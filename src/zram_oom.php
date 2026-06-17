<?php
/**
 * <module_context>
 *   <name>zram_oom</name>
 *   <description>OOM protection: item discovery, apply, and reset endpoint</description>
 *   <dependencies>zram_config</dependencies>
 *   <consumers>UnraidZramCard.page (AJAX)</consumers>
 * </module_context>
 */

require_once dirname(__FILE__) . '/zram_config.php';

/** Canonical level → oom_score_adj mapping. */
const OOM_LEVEL_SCORES = [
    'protected' => -1000,
    'high'      => -500,
    'normal'    => 0,
    'low'       => 500,
    'killfirst' => 1000,
];

/**
 * Parse the oom_levels config string into an associative array.
 * Format: "vm:DevBox=protected,docker:plex=low,proc:mover=high"
 * Returns: ['vm:DevBox' => 'protected', 'docker:plex' => 'low', ...]
 */
function oom_parse_levels(string $raw): array {
    if ($raw === '') return [];
    $out = [];
    foreach (explode(',', $raw) as $entry) {
        $entry = trim($entry);
        if ($entry === '') continue;
        $pos = strrpos($entry, '=');
        if ($pos === false) continue;
        $id    = trim(substr($entry, 0, $pos));
        $level = strtolower(trim(substr($entry, $pos + 1)));
        if ($id === '' || !isset(OOM_LEVEL_SCORES[$level])) continue;
        if (!preg_match('/^(vm|docker|proc):[A-Za-z0-9_.\- ]+$/', $id)) continue;
        $out[$id] = $level;
    }
    return $out;
}

/**
 * Serialise a levels array back to the config string.
 */
function oom_serialize_levels(array $levels): string {
    $parts = [];
    foreach ($levels as $id => $level) {
        $parts[] = "$id=$level";
    }
    return implode(',', $parts);
}

/**
 * Return true if $name is a safe VM/container name for use in filesystem paths.
 * Allows letters, digits, underscore, dot, hyphen, and space.
 */
function oom_is_safe_name(string $name): bool {
    return $name !== '' && preg_match('/^[A-Za-z0-9_.\- ]+$/', $name) === 1;
}

/**
 * Return the path to the libvirt qemu hook script.
 */
function oom_hook_path(): string {
    return '/etc/libvirt/hooks/qemu';
}

/** Unique marker pair for this plugin's block in the hook script. */
const OOM_HOOK_MARKER_START = '# BEGIN zram-oom-protection';
const OOM_HOOK_MARKER_END   = '# END zram-oom-protection';

/**
 * Remove only the plugin's marked block from the libvirt qemu hook.
 * Preserves any surrounding user content. No-op if the block is absent.
 */
function oom_remove_hook_block(array &$logs): void {
    $path = oom_hook_path();
    if (!file_exists($path)) return;
    $content = file_get_contents($path);
    if ($content === false) return;
    $pattern = '/' . preg_quote(OOM_HOOK_MARKER_START, '/') . '.*?' . preg_quote(OOM_HOOK_MARKER_END, '/') . '\n?/s';
    $new = preg_replace($pattern, '', $content);
    if ($new === $content) return; // block not present
    if (file_put_contents($path, $new) !== false) {
        $logs[] = 'Libvirt hook block removed from ' . $path;
        // If the hook is now empty (just shebang and/or blank lines), remove it
        if (trim(preg_replace('/^#!.*$/m', '', $new)) === '') {
            @unlink($path);
            $logs[] = 'Libvirt hook script removed (now empty)';
        }
    }
}

/**
 * Map a friendly level name to its oom_score_adj integer.
 * Returns 0 for unknown levels.
 */
function oom_score_for_level(string $level): int {
    return OOM_LEVEL_SCORES[$level] ?? 0;
}

/**
 * Return true iff the resulting configuration leaves NO killable victim.
 * A configuration is over-protected when:
 *   - There is at least one item (itemIds non-empty), AND
 *   - Every item's resolved level (levelMap[$id] ?? defaultLevel) has score < 0.
 * Returns false when itemIds is empty (no items → no protection to enforce).
 */
function oom_is_overprotected(array $itemIds, array $levelMap, string $defaultLevel): bool {
    if (empty($itemIds)) return false;
    foreach ($itemIds as $id) {
        $level = $levelMap[$id] ?? $defaultLevel;
        if (oom_score_for_level($level) >= 0) return false;
    }
    return true;
}

/**
 * Discover all VMs, containers, and services currently visible on this host.
 * Returns an array of item maps with keys: id, type, name, state, mem_bytes,
 * oom_score, oom_score_adj, level, present.
 * The 'level' field reflects the stored per-item level (or default) for display.
 * Does NOT include remembered-absent items (items in config but not discovered).
 */
function oom_discover_items(): array {
    $cfg     = zram_config_read();
    $levels  = oom_parse_levels($cfg['oom_levels'] ?? '');
    $default = $cfg['oom_default_level'] ?? 'normal';
    $items   = [];

    // --- VMs via virsh ---
    exec('virsh list --all --name 2>/dev/null', $vmNames, $rc);
    if ($rc === 0) {
        foreach ($vmNames as $name) {
            $name = trim($name);
            if ($name === '') continue;
            if (!oom_is_safe_name($name)) continue;
            // State. Reset $stateOut first — exec() APPENDS to an existing
            // array, so without this every VM would inherit the FIRST VM's
            // state (e.g. an off "Jump Box" showing "running"). WP bug.
            $stateOut = [];
            exec('virsh domstate ' . escapeshellarg($name) . ' 2>/dev/null', $stateOut);
            $state = trim($stateOut[0] ?? 'unknown');
            // Memory: configured from dumpxml (always accurate regardless of power state)
            $configuredBytes = 0;
            $dumpxmlOut = [];
            exec('virsh dumpxml ' . escapeshellarg($name) . ' 2>/dev/null', $dumpxmlOut);
            $dumpxmlStr = implode("\n", $dumpxmlOut);
            if (preg_match("/<memory unit='KiB'>(\d+)<\/memory>/", $dumpxmlStr, $memM)) {
                $configuredBytes = intval($memM[1]) * 1024;
            }
            // Live RSS from qemu process (running only)
            $adj     = 0;
            $score   = 0;
            $pidFile = '/run/libvirt/qemu/' . $name . '.pid';
            $liveBytes = 0;
            if (file_exists($pidFile)) {
                $pid = trim(@file_get_contents($pidFile));
                if ($pid && is_numeric($pid)) {
                    $adjRaw = @file_get_contents("/proc/$pid/oom_score_adj");
                    if ($adjRaw !== false) $adj = intval(trim($adjRaw));
                    $scoreRaw = @file_get_contents("/proc/$pid/oom_score");
                    if ($scoreRaw !== false) $score = intval(trim($scoreRaw));
                    $statusRaw = @file_get_contents("/proc/$pid/status");
                    if ($statusRaw !== false && preg_match('/^VmRSS:\s+(\d+)/m', $statusRaw, $rssM)) {
                        $liveBytes = intval($rssM[1]) * 1024;
                    }
                }
            }
            $running = ($state === 'running');
            $memBytes = $running && $liveBytes > 0 ? $liveBytes : $configuredBytes;
            $memKind  = $running && $liveBytes > 0 ? 'used' : 'configured';
            $id = 'vm:' . $name;
            $items[] = [
                'id'            => $id,
                'type'          => 'vm',
                'name'          => $name,
                'state'         => $state,
                'mem_bytes'     => $memBytes,
                'mem_kind'      => $memKind,
                'configured'    => (($levels[$id] ?? $default) !== $default),
                'oom_score'     => $score,
                'oom_score_adj' => $adj,
                'level'         => $levels[$id] ?? $default,
                'present'       => true,
            ];
        }
    }

    // --- Containers via docker ps -a ---
    // --no-trunc: emit the FULL 64-char container ID. The short 12-char ID does
    // NOT match the cgroup directory name (/sys/fs/cgroup/docker/<full-id>/), so
    // without this both memory.current and cgroup.procs reads silently fail →
    // running containers reported no memory and oom_score 0. (cgroup v2.)
    exec('docker ps -a --no-trunc --format "{{.Names}}\t{{.Status}}\t{{.ID}}" 2>/dev/null', $dockerLines, $drc);
    if ($drc === 0) {
        foreach ($dockerLines as $line) {
            $parts = explode("\t", $line);
            if (count($parts) < 3) continue;
            [$cname, $status, $cid] = $parts;
            if (!oom_is_safe_name($cname)) continue;
            $running = strpos($status, 'Up') === 0;
            $state   = $running ? 'running' : 'stopped';
            $adj     = 0;
            $score   = 0;
            if ($running) {
                // Get pids from cgroup.procs
                $cgroupFile = "/sys/fs/cgroup/docker/$cid/cgroup.procs";
                if (file_exists($cgroupFile)) {
                    $pids = array_filter(explode("\n", trim(@file_get_contents($cgroupFile) ?: '')));
                    foreach ($pids as $p) {
                        $p = trim($p);
                        if ($p && is_numeric($p)) {
                            $ar = @file_get_contents("/proc/$p/oom_score_adj");
                            if ($ar !== false) { $adj = intval(trim($ar)); break; }
                        }
                    }
                    // oom_score from first pid
                    $firstPid = $pids[0] ?? '';
                    if ($firstPid) {
                        $sr = @file_get_contents("/proc/$firstPid/oom_score");
                        if ($sr !== false) $score = intval(trim($sr));
                    }
                }
            }
            // Memory: live (running) or configured/nolimit (stopped)
            $memBytes = 0;
            $memKind  = 'none';
            if ($running) {
                $memCgFile = "/sys/fs/cgroup/docker/$cid/memory.current";
                if (file_exists($memCgFile)) {
                    $mcRaw = @file_get_contents($memCgFile);
                    if ($mcRaw !== false) {
                        $memBytes = intval(trim($mcRaw));
                        $memKind  = 'used';
                    }
                }
            } else {
                $inspOut = [];
                exec('docker inspect -f \'{{.HostConfig.Memory}}\' ' . escapeshellarg($cid) . ' 2>/dev/null', $inspOut);
                $limit = intval($inspOut[0] ?? 0);
                if ($limit > 0) {
                    $memBytes = $limit;
                    $memKind  = 'configured';
                } else {
                    $memBytes = 0;
                    $memKind  = 'nolimit';
                }
            }
            $id = 'docker:' . $cname;
            $items[] = [
                'id'            => $id,
                'type'          => 'docker',
                'name'          => $cname,
                'state'         => $state,
                'mem_bytes'     => $memBytes,
                'mem_kind'      => $memKind,
                'configured'    => (($levels[$id] ?? $default) !== $default),
                'oom_score'     => $score,
                'oom_score_adj' => $adj,
                'level'         => $levels[$id] ?? $default,
                'present'       => true,
            ];
        }
    }

    // --- Plugin/host services via curated list + user patterns ---
    // Curated default services: USERSPACE processes that matter for Unraid
    // stability and are actually OOM-killable. Kernel threads (btrfs/zfs workers)
    // are deliberately excluded — the OOM killer can't kill them, they have no
    // userspace RSS (so memory shows "—"), and setting oom_score_adj on them is a
    // no-op. Users can still add any process pattern below.
    $defaultPatterns = [
        'mover'   => 'unraid_mover',
        'shfs'    => 'shfs',
    ];
    $userPatterns = [];
    $rawPatterns = $cfg['oom_proc_patterns'] ?? '';
    if ($rawPatterns !== '') {
        foreach (explode(',', $rawPatterns) as $pat) {
            $pat = trim($pat);
            if ($pat !== '') $userPatterns[$pat] = $pat;
        }
    }
    $allPatterns = array_merge($defaultPatterns, $userPatterns);
    foreach ($allPatterns as $label => $pattern) {
        if (!oom_is_safe_name((string)$label)) continue;
        exec('pgrep -f ' . escapeshellarg($pattern) . ' 2>/dev/null', $pids, $prc);
        $adj   = 0;
        $score = 0;
        $state = 'idle';
        if ($prc === 0 && !empty($pids)) {
            $state = 'running';
            $p = trim($pids[0]);
            if ($p && is_numeric($p)) {
                $ar = @file_get_contents("/proc/$p/oom_score_adj");
                if ($ar !== false) $adj = intval(trim($ar));
                $sr = @file_get_contents("/proc/$p/oom_score");
                if ($sr !== false) $score = intval(trim($sr));
            }
        }
        // Memory: live RSS from first pid when running
        $procMemBytes = 0;
        $procMemKind  = 'none';
        if ($state === 'running' && !empty($pids)) {
            $pp = trim($pids[0]);
            if ($pp && is_numeric($pp)) {
                $procStatusRaw = @file_get_contents("/proc/$pp/status");
                if ($procStatusRaw !== false && preg_match('/^VmRSS:\s+(\d+)/m', $procStatusRaw, $pRss)) {
                    $procMemBytes = intval($pRss[1]) * 1024;
                    $procMemKind  = 'used';
                }
            }
        }
        $id = 'proc:' . $label;
        $items[] = [
            'id'            => $id,
            'type'          => 'proc',
            'name'          => $label,
            'state'         => $state,
            'mem_bytes'     => $procMemBytes,
            'mem_kind'      => $procMemKind,
            'configured'    => isset($levels[$id]),
            'oom_score'     => $score,
            'oom_score_adj' => $adj,
            'level'         => $levels[$id] ?? $default,
            'present'       => ($state === 'running'),
        ];
        unset($pids);
    }

    return $items;
}

if (PHP_SAPI !== 'cli') {
header('Content-Type: application/json');

$action = filter_input(INPUT_GET, 'action', FILTER_UNSAFE_RAW) ?: '';
$csrf   = filter_input(INPUT_GET, 'csrf_token', FILTER_UNSAFE_RAW) ?: '';

$_var        = @parse_ini_file('/var/local/emhttp/var.ini', false, INI_SCANNER_RAW) ?: [];
$_serverCsrf = (string)($_var['csrf_token'] ?? '');
if ($_serverCsrf === '' || !hash_equals($_serverCsrf, $csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'Missing CSRF token']);
    exit;
}

// --- ACTION: list_items ---
if ($action === 'list_items') {
    $cfg    = zram_config_read();
    $levels = oom_parse_levels($cfg['oom_levels'] ?? '');
    $default = $cfg['oom_default_level'] ?? 'normal';

    $items = oom_discover_items();

    // Remembered absent items (in config but not discovered above)
    $foundIds = array_column($items, 'id');
    foreach ($levels as $id => $level) {
        if (!in_array($id, $foundIds, true)) {
            if ($level === $default) continue; // stale default-level entry for a gone item — ignore
            // Orphaned service pattern (e.g. a removed default like btrfs): real
            // patterns are always discovered (running or idle), so an undiscovered
            // proc: entry is dead config — never show it as an "absent" item.
            if (strpos($id, 'proc:') === 0) continue;
            [$type, $name] = explode(':', $id, 2) + ['', ''];
            $items[] = [
                'id'            => $id,
                'type'          => $type,
                'name'          => $name,
                'state'         => 'absent',
                'mem_bytes'     => 0,
                'mem_kind'      => 'none',
                'configured'    => true,
                'oom_score'     => 0,
                'oom_score_adj' => 0,
                'level'         => $level,
                'present'       => false,
            ];
        }
    }

    echo json_encode(['items' => $items]);
    exit;
}

// --- ACTION: apply_oom ---
if ($action === 'apply_oom') {
    $cfg = zram_config_read();
    $levelsRaw = filter_input(INPUT_GET, 'levels', FILTER_UNSAFE_RAW) ?: '';
    $defaultLevel = filter_input(INPUT_GET, 'default_level', FILTER_UNSAFE_RAW) ?: 'normal';
    $defaultLevel = isset(OOM_LEVEL_SCORES[$defaultLevel]) ? $defaultLevel : 'normal';
    $oomGroupRaw  = filter_input(INPUT_GET, 'oom_group', FILTER_UNSAFE_RAW) ?: '';
    $oomGroup     = ($oomGroupRaw === 'yes') ? 'yes' : 'no';
    $vmMemoryMin  = filter_input(INPUT_GET, 'vm_memory_min', FILTER_UNSAFE_RAW) ?: '';
    $vmMemoryMin  = ($vmMemoryMin === 'yes') ? 'yes' : 'no';
    $procPatterns = filter_input(INPUT_GET, 'oom_proc_patterns', FILTER_UNSAFE_RAW) ?: '';
    $procPatterns = substr(trim($procPatterns), 0, 500);
    $autoDeps     = filter_input(INPUT_GET, 'oom_auto_deps', FILTER_UNSAFE_RAW) ?: '';
    $autoDeps     = substr(trim($autoDeps), 0, 500);

    // Parse incoming levels string (submitted from the UI form)
    $levels = oom_parse_levels($levelsRaw);

    // --- Over-protection guard ---
    // Discover ALL items (matching boot-script semantics) so the guard covers
    // unconfigured items that will receive the default level at reboot.
    $discovered   = oom_discover_items();
    $allIds       = array_column($discovered, 'id');
    if (oom_is_overprotected($allIds, $levels, $defaultLevel)) {
        echo json_encode([
            'success' => false,
            'message' => 'Over-protection refused: every item is Protected or High. At least one item must be Normal, Low, or Kill first — otherwise the kernel has no victim and may panic.',
            'logs'    => [],
        ]);
        exit;
    }

    $logs  = [];
    $applied = 0;
    $errors  = 0;

    // Apply to ALL discovered items, resolving the default for unconfigured items.
    // This matches the semantics of zram_oom_apply.sh which applies map[id] ?? default
    // to every discovered VM/container/service.
    foreach ($discovered as $item) {
        $id    = $item['id'];
        $level = $levels[$id] ?? $defaultLevel;
        $score = OOM_LEVEL_SCORES[$level] ?? 0;
        [$type, $name] = explode(':', $id, 2) + ['', ''];

        if (($type === 'vm' || $type === 'docker') && $name !== '' && !oom_is_safe_name($name)) {
            $logs[] = ucfirst($type) . ": skipping unsafe name '$name'";
            continue;
        }

        if ($type === 'vm') {
            $pidFile = '/run/libvirt/qemu/' . $name . '.pid';
            if (!file_exists($pidFile)) {
                $logs[] = "VM $name: pid file absent — will re-apply via libvirt hook on next start";
                continue;
            }
            $pid = trim(@file_get_contents($pidFile));
            if (!$pid || !is_numeric($pid)) {
                $logs[] = "VM $name: invalid pid in $pidFile";
                $errors++;
                continue;
            }
            $adjFile = "/proc/$pid/oom_score_adj";
            if (@file_put_contents($adjFile, (string)$score) === false) {
                $logs[] = "VM $name (pid $pid): failed to write $score to oom_score_adj";
                $errors++;
            } else {
                $logs[] = "VM $name (pid $pid): oom_score_adj=$score";
                $applied++;
            }
            // Optional: memory.min for Protected VMs when vm_memory_min=yes
            if ($level === 'protected' && $vmMemoryMin === 'yes') {
                // Resolve cgroup from /proc/<pid>/cgroup (cgroup v2: single line "0::/...")
                $cgRaw = @file_get_contents("/proc/$pid/cgroup");
                if ($cgRaw !== false) {
                    preg_match('/^0::(.+)$/m', trim($cgRaw), $cgm);
                    $cgPath = rtrim($cgm[1] ?? '', '/');
                    // Strip /emulator suffix if present (libvirt puts qemu main thread there)
                    $cgPath = preg_replace('#/emulator$#', '', $cgPath);
                    $memMinFile = "/sys/fs/cgroup$cgPath/memory.min";
                    // Get VM max memory from dominfo — cap memory.min to that
                    exec('virsh dominfo ' . escapeshellarg($name) . ' 2>/dev/null', $info);
                    $maxMemKb = 0;
                    foreach ($info as $line) {
                        if (strpos($line, 'Max memory:') === 0) {
                            $maxMemKb = intval(preg_replace('/[^0-9]/', '', $line));
                            break;
                        }
                    }
                    if ($maxMemKb > 0 && file_exists($memMinFile)) {
                        $memMinBytes = $maxMemKb * 1024;
                        if (@file_put_contents($memMinFile, (string)$memMinBytes) !== false) {
                            $logs[] = "VM $name: memory.min=" . round($memMinBytes/1073741824, 1) . "G (capped to VM RAM)";
                        } else {
                            $logs[] = "VM $name: memory.min write failed (cgroup v1 or permission)";
                        }
                    }
                }
            }

        } elseif ($type === 'docker') {
            // Resolve container ID from name
            exec('docker inspect -f \'{{.Id}}\' ' . escapeshellarg($name) . ' 2>/dev/null', $idOut, $drc);
            if ($drc !== 0 || empty($idOut)) {
                $logs[] = "Container $name: not found — skipping";
                continue;
            }
            $cid = trim($idOut[0]);
            $cgroupFile = "/sys/fs/cgroup/docker/$cid/cgroup.procs";
            if (!file_exists($cgroupFile)) {
                $logs[] = "Container $name: cgroup.procs not found (container stopped?)";
                continue;
            }
            $pids = array_filter(explode("\n", trim(@file_get_contents($cgroupFile) ?: '')));
            $written = 0;
            foreach ($pids as $p) {
                $p = trim($p);
                if (!$p || !is_numeric($p)) continue;
                $adjFile = "/proc/$p/oom_score_adj";
                if (!file_exists($adjFile)) continue; // pid exited between listing and write — benign race, not an error
                if (@file_put_contents($adjFile, (string)$score) !== false) {
                    $written++;
                } else {
                    $logs[] = "Container $name: could not write oom_score_adj on pid $p (process state or permission)";
                    $errors++;
                }
            }
            $logs[] = "Container $name: oom_score_adj=$score on $written pids";
            if ($written > 0) $applied++;
            // memory.oom.group
            if ($oomGroup === 'yes') {
                $oomGroupFile = "/sys/fs/cgroup/docker/$cid/memory.oom.group";
                if (file_exists($oomGroupFile)) {
                    @file_put_contents($oomGroupFile, '1');
                    $logs[] = "Container $name: memory.oom.group=1";
                }
            }

        } elseif ($type === 'proc') {
            exec('pgrep -f ' . escapeshellarg($name) . ' 2>/dev/null', $pids, $prc);
            if ($prc !== 0 || empty($pids)) {
                $logs[] = "Service $name: no matching process — skipping";
                continue;
            }
            $written = 0;
            foreach ($pids as $p) {
                $p = trim($p);
                if (!$p || !is_numeric($p)) continue;
                $adjFile = "/proc/$p/oom_score_adj";
                if (!file_exists($adjFile)) continue; // pid exited — benign race, not an error
                if (@file_put_contents($adjFile, (string)$score) !== false) {
                    $written++;
                } else {
                    $logs[] = "Service $name: could not write oom_score_adj on pid $p (process state or permission)";
                    $errors++;
                }
            }
            $logs[] = "Service $name: oom_score_adj=$score on $written pids";
            if ($written > 0) $applied++;
            unset($pids);
        }
    }

    // Persist the levels + defaults to config
    zram_config_write([
        'oom_protect_enabled' => 'yes',
        'oom_levels'          => $levelsRaw,
        'oom_default_level'   => $defaultLevel,
        'oom_oom_group'       => $oomGroup,
        'vm_memory_min'       => $vmMemoryMin,
        'oom_proc_patterns'   => $procPatterns,
        'oom_auto_deps'       => $autoDeps,
    ]);

    // Install/refresh the libvirt hook immediately (so it's present without waiting for boot)
    $applyScript = '/usr/local/emhttp/plugins/unraid-zram-card/zram_oom_apply.sh';
    if (is_executable($applyScript)) {
        exec(escapeshellarg($applyScript) . ' --install-hook > /dev/null 2>&1 &');
    }

    $msg = "OOM levels applied: $applied items" . ($errors ? ", $errors errors — see activity log for details" : '');
    echo json_encode(['success' => $errors === 0, 'message' => $msg, 'logs' => $logs]);
    exit;
}

// --- ACTION: reset_oom ---
if ($action === 'reset_oom') {
    $logs  = [];
    $reset = 0;

    // Walk all VMs
    exec('virsh list --all --name 2>/dev/null', $vmNames, $rc);
    if ($rc === 0) {
        foreach ($vmNames as $name) {
            $name = trim($name);
            if ($name === '') continue;
            if (!oom_is_safe_name($name)) continue;
            $pidFile = '/run/libvirt/qemu/' . $name . '.pid';
            if (!file_exists($pidFile)) continue;
            $pid = trim(@file_get_contents($pidFile));
            if (!$pid || !is_numeric($pid)) continue;
            if (@file_put_contents("/proc/$pid/oom_score_adj", '0') !== false) {
                $logs[] = "VM $name (pid $pid): oom_score_adj reset to 0";
                $reset++;
            }
        }
    }

    // Walk all containers
    exec('docker ps --format "{{.ID}}" 2>/dev/null', $cids, $drc);
    if ($drc === 0) {
        foreach ($cids as $cid) {
            $cid = trim($cid);
            $cgroupFile = "/sys/fs/cgroup/docker/$cid/cgroup.procs";
            if (!file_exists($cgroupFile)) continue;
            $pids = array_filter(explode("\n", trim(@file_get_contents($cgroupFile) ?: '')));
            foreach ($pids as $p) {
                $p = trim($p);
                if ($p && is_numeric($p)) @file_put_contents("/proc/$p/oom_score_adj", '0');
            }
            $logs[] = "Container $cid: oom_score_adj reset to 0";
            $reset++;
        }
    }

    // Remove libvirt hook block
    oom_remove_hook_block($logs);

    // Clear config
    zram_config_write([
        'oom_protect_enabled' => 'no',
        'oom_levels'          => '',
        'oom_default_level'   => 'normal',
    ]);

    echo json_encode(['success' => true, 'message' => "Reset $reset items to oom_score_adj=0", 'logs' => $logs]);
    exit;
}

// --- ACTION: list_service_candidates ---
if ($action === 'list_service_candidates') {
    // Curated host-service list: name => [desc, critical]
    $curated = [
        'emhttpd'      => ['Web GUI &amp; array control',    true],
        'shfs'         => ['User shares (/mnt/user)',         true],
        'nginx'        => ['Web server / GUI proxy',          true],
        'php-fpm'      => ['GUI page rendering',              true],
        'dockerd'      => ['Docker engine',                   true],
        'containerd'   => ['Container runtime',               false],
        'virtqemud'    => ['VM management (libvirt)',         true],
        'libvirtd'     => ['VM management (legacy)',          true],
        'smbd'         => ['Samba (SMB shares)',              false],
        'nmbd'         => ['Samba NetBIOS',                  false],
        'sshd'         => ['SSH access',                     false],
        'node'         => ['Unraid Connect API',             false],
        'avahi-daemon' => ['mDNS discovery',                 false],
        'wsdd2'        => ['Windows discovery',              false],
        'rpcbind'      => ['NFS portmapper',                 false],
        'crond'        => ['Task scheduler',                 false],
        'rsyslogd'     => ['System logging',                 false],
        'apcupsd'      => ['UPS monitor',                    true],
        'ttyd'         => ['Web terminal',                   false],
    ];

    $suggestedNames = array_keys($curated);
    $suggested = [];

    foreach ($curated as $sname => [$desc, $critical]) {
        if (!oom_is_safe_name($sname)) continue;
        // Try exact match first (-x), fall back to -f for multi-word daemons
        $pids = [];
        exec('pgrep -x ' . escapeshellarg($sname) . ' 2>/dev/null', $pids, $prc);
        if ($prc !== 0 || empty($pids)) {
            $pids = [];
            exec('pgrep -f ' . escapeshellarg($sname) . ' 2>/dev/null', $pids);
        }
        $instances  = count(array_filter($pids, fn($p) => is_numeric(trim($p))));
        $running    = $instances > 0;
        $memBytes   = 0;
        if ($running) {
            foreach ($pids as $pp) {
                $pp = trim($pp);
                if (!$pp || !is_numeric($pp)) continue;
                $statusRaw = @file_get_contents("/proc/$pp/status");
                if ($statusRaw !== false && preg_match('/^VmRSS:\s+(\d+)/m', $statusRaw, $rm)) {
                    $memBytes += intval($rm[1]) * 1024;
                }
            }
        }
        $suggested[] = [
            'name'      => $sname,
            'desc'      => $desc,
            'mem_bytes' => $memBytes,
            'instances' => $instances,
            'running'   => $running,
            'critical'  => $critical,
        ];
    }

    // Other running userspace HOST processes — aggregate RSS per comm.
    // Excludes: kernel threads (no VmRSS), processes inside Docker containers
    // (covered by the Docker rows), the running VM (qemu — covered by VM rows),
    // Docker/VM plumbing, and anything already in the curated list.
    $otherMap = [];
    $exclPrefixes = ['qemu-system', 'containerd-shim', 'docker-proxy'];
    foreach (glob('/proc/[0-9]*') as $procDir) {
        $cg = @file_get_contents("$procDir/cgroup");
        if ($cg !== false && strpos($cg, '/docker/') !== false) continue; // inside a container
        $statusRaw = @file_get_contents("$procDir/status");
        if ($statusRaw === false) continue;
        if (!preg_match('/^VmRSS:\s+(\d+)/m', $statusRaw, $rm)) continue; // kernel thread / no userspace mem
        $rss = intval($rm[1]);
        if ($rss === 0) continue;
        $comm = trim(@file_get_contents("$procDir/comm") ?: '');
        if ($comm === '' || $comm[0] === '[') continue;
        if (in_array($comm, $suggestedNames, true)) continue;
        $skip = false;
        foreach ($exclPrefixes as $pre) { if (strpos($comm, $pre) === 0) { $skip = true; break; } }
        if ($skip) continue;
        if (!isset($otherMap[$comm])) {
            $otherMap[$comm] = ['mem_bytes' => 0, 'instances' => 0];
        }
        $otherMap[$comm]['mem_bytes'] += $rss * 1024;
        $otherMap[$comm]['instances']++;
    }
    // Sort by mem desc, cap at top 40
    uasort($otherMap, fn($a, $b) => $b['mem_bytes'] - $a['mem_bytes']);
    $other = [];
    $count = 0;
    foreach ($otherMap as $comm => $data) {
        if ($count >= 40) break;
        if (!oom_is_safe_name($comm)) continue;
        $other[] = [
            'name'      => $comm,
            'mem_bytes' => $data['mem_bytes'],
            'instances' => $data['instances'],
        ];
        $count++;
    }

    echo json_encode(['success' => true, 'suggested' => $suggested, 'other' => $other]);
    exit;
}

// Unknown action fallback
echo json_encode(['success' => false, 'message' => "Unknown action: $action"]);
} // end PHP_SAPI !== 'cli'
