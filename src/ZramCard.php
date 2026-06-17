<?php
/**
 * <module_context>
 *   <name>ZramCard</name>
 *   <description>Dashboard card renderer showing tiered ZRAM + SSD swap stats</description>
 *   <dependencies>zram_config</dependencies>
 *   <consumers>UnraidZramDash.page</consumers>
 * </module_context>
 */

if (!function_exists('getZramDashboardCard')) {
    function getZramDashboardCard() {
        $docroot = $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
        require_once "$docroot/plugins/unraid-zram-card/zram_config.php";

        try {
            $cfg = zram_config_read();
            if (($cfg['enabled'] ?? 'yes') !== 'yes') return '';

            $fmt = function($bytes, $p = 2) {
                $u = ['B','KB','MB','GB','TB'];
                if ($bytes < 1) return '0 B';
                $pow = min(floor(log(max($bytes,1)) / log(1024)), count($u) - 1);
                return round($bytes / pow(1024, $pow), $p) . ' ' . $u[$pow];
            };

            // Fetch our device stats
            $ourDev = zram_get_our_device();
            $totalOriginal = 0; $totalCompressed = 0; $totalUsed = 0; $diskSize = 0;
            $algo = '-'; $devCount = 0;

            if ($ourDev) {
                $devCount = 1;
                $out = [];
                exec("zramctl --bytes --noheadings --raw --output NAME,DISKSIZE,DATA,COMPR,ALGORITHM,TOTAL /dev/$ourDev 2>/dev/null", $out);
                foreach ($out as $line) {
                    $p = preg_split('/\s+/', trim($line));
                    if (count($p) >= 6 && basename($p[0]) === $ourDev) {
                        $diskSize = intval($p[1]);
                        $totalOriginal = intval($p[2]);
                        $totalCompressed = intval($p[3]);
                        $algo = $p[4];
                        $totalUsed = intval($p[5]);
                    }
                }
            }

            $ratio = ($totalCompressed > 0) ? round($totalOriginal / $totalCompressed, 2) : 0;
            $swappiness = trim(@file_get_contents('/proc/sys/vm/swappiness') ?: '60');

            // SSD swap info — capture used + size when active so the chip and
            // chart can render real values in Tier-2-only mode.
            // For loop-backed swap the kernel lists /dev/loopN in /proc/swaps and
            // swapon --show, not the image path. Resolve the loop device first.
            $ssdPath = $cfg['ssd_swap_path'] ?? '';
            $ssdBacking = $cfg['ssd_swap_backing'] ?? 'file';
            // Pool/mount label for the device table. Prefer the stored mount;
            // fall back to deriving it from the image path (<mount>/.swap/<file>).
            $ssdMount = $cfg['ssd_swap_mount'] ?? '';
            if ($ssdMount === '' && $ssdPath !== '') $ssdMount = dirname(dirname($ssdPath));
            $ssdPool  = $ssdMount !== '' ? basename($ssdMount) : '—';
            $ssdActive = false;
            $ssdSize = 0;
            $ssdUsed = 0;
            $ssdPrio = '-';
            $ssdDevLabel = 'swap file';
            if ($ssdPath && file_exists($ssdPath)) {
                // Resolve swap target: loop device for loop-backed, path for file-backed
                $swapTarget = $ssdPath;
                if ($ssdBacking === 'loop') {
                    $ljOut = [];
                    exec('losetup -j ' . escapeshellarg($ssdPath) . ' 2>/dev/null', $ljOut);
                    foreach ($ljOut as $ljLine) {
                        if (preg_match('#^(/dev/loop\d+):#', $ljLine, $ljm)) {
                            $swapTarget = $ljm[1];
                            break;
                        }
                    }
                }
                $swaps = @file_get_contents('/proc/swaps') ?: '';
                $ssdActive = preg_match('/^' . preg_quote($swapTarget, '/') . '\s/m', $swaps) === 1;
                if ($ssdActive) {
                    // Show loop device name when loop-backed so it's not mistaken for a plain file
                    if ($ssdBacking === 'loop' && $swapTarget !== $ssdPath) {
                        $ssdDevLabel = basename($swapTarget);
                    }
                    exec('swapon --bytes --noheadings --show=NAME,SIZE,USED,PRIO 2>/dev/null', $ssdRows);
                    foreach ($ssdRows as $row) {
                        $rp = preg_split('/\s+/', trim($row));
                        if (count($rp) >= 4 && $rp[0] === $swapTarget) {
                            $ssdSize = intval($rp[1]);
                            $ssdUsed = intval($rp[2]);
                            $ssdPrio = $rp[3];
                            break;
                        }
                    }
                } else {
                    $ssdSize = filesize($ssdPath) ?: 0;
                }
            }

            // Priority
            $prio = '-';
            if ($ourDev) {
                exec('swapon --noheadings --show=NAME,PRIO 2>/dev/null', $sw);
                foreach ($sw as $sl) {
                    $sp = preg_split('/\s+/', trim($sl));
                    if (count($sp) >= 2 && basename($sp[0]) === $ourDev) { $prio = $sp[1]; break; }
                }
            }

            // Unraid 7.2+ responsive check
            $isResp = false;
            if (file_exists('/etc/unraid-version')) {
                $v = @parse_ini_file('/etc/unraid-version');
                if (isset($v['version'])) $isResp = version_compare($v['version'], '7.2.0-beta', '>=');
            }

            $pollInterval = intval($cfg['refresh_interval'] ?? 3000);
            // Cache-buster: filemtime auto-invalidates whenever assets are reinstalled
            $jsMtime  = @filemtime(__DIR__ . '/js/zram-card.js')  ?: time();
            $chartMtime = @filemtime(__DIR__ . '/js/chart.min.js') ?: $jsMtime;

            // Tier label for subtitle
            $tierLabel = $devCount > 0 ? 'Active' : 'Inactive';
            if ($ssdActive) $tierLabel .= ' + Disk';

            ob_start();
?>
<style>
@keyframes zram-fade-blink { 0%{opacity:0.3} 50%{opacity:1;color:#7fba59;text-shadow:0 0 2px #7fba59} 100%{opacity:0.3} }
.zram-pulse { animation: zram-fade-blink 0.6s ease-in-out; }
/* Device table — theme-neutral so dividers/hierarchy read on both light and dark Dynamix themes
   (the old rgba(255,255,255,0.05) borders were invisible on the light theme). */
.zram-devtable{margin-top:6px;}
.zram-devgrid{display:grid;grid-template-columns:1.1fr 1.3fr 1fr 0.9fr 1fr 0.65fr 0.8fr;gap:8px;align-items:center;}
.zram-devhead{font-size:0.68em;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;opacity:0.5;padding:2px 2px 4px;border-bottom:1px solid rgba(128,128,128,0.25);}
.zram-devrow{font-size:0.8em;padding:5px 2px;}
.zram-devrow + .zram-devrow{border-top:1px solid rgba(128,128,128,0.12);}
.zram-num{text-align:right;font-variant-numeric:tabular-nums;font-feature-settings:"tnum";}
.zram-muted{opacity:0.7;}
.zram-tier{display:flex;align-items:center;gap:6px;font-weight:600;min-width:0;}
.zram-dot{width:7px;height:7px;border-radius:50%;flex:0 0 auto;}
.zram-ell{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
</style>
<tbody title='ZRAM Usage'>
    <tr><td>
        <span class='tile-header'>
            <span class='tile-header-left'>
                <img src='/plugins/unraid-zram-card/unraid-zram-card.png' class='f32' style='width:32px;height:32px;margin-right:10px;'>
                <div class='section'>
                    <?php if ($isResp): ?><h3 class='tile-header-main'>ZRAM STATUS</h3>
                    <?php else: ?>ZRAM Status<br><?php endif; ?>
                    <span class="subtitle"><i class="fa fa-fw fa-info-circle"></i> <?php echo $tierLabel; ?></span>
                </div>
            </span>
            <span class='tile-header-right'><span class='tile-header-right-controls'>
                <span style="opacity:0.6;display:inline-flex;align-items:center;gap:4px;margin-right:8px;">
                    <i class="fa fa-fw fa-refresh" id="zram-refresh-icon"></i>
                    <span id="zram-refresh-text" style="font-family:monospace;font-size:0.9em;"><?php echo round($pollInterval/1000, 1); ?>s</span>
                </span>
                <a href="/Dashboard/Settings/UnraidZramCard" title="ZRAM Settings"><i class="fa fa-fw fa-cog control"></i></a>
            </span></span>
        </span>
    </td></tr>
    <tr><td>
        <div class="zram-content" style="padding:0 8px;">
<?php
            // Three render modes for the chip strip + chart, driven by which
            // tiers are actually live. tier1 = ZRAM device exists; tier2 =
            // disk swap configured AND active in /proc/swaps. See
            // docs/specs/DASHBOARD_TIER2_VISIBILITY.md.
            $tier1 = ($devCount > 0);
            $tier2 = ($ssdActive === true);
?>
<?php if ($tier1 || $tier2): ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(80px,1fr));gap:6px;margin:5px 0 8px;">
<?php if ($tier1): ?>
                <div style="background:rgba(0,0,0,0.1);padding:6px;border-radius:4px;text-align:center;">
                    <span id="zram-uncompressed" style="font-size:1.1em;font-weight:bold;display:block;color:#d49373;"><?php echo $fmt($totalOriginal); ?></span>
                    <span style="font-size:0.75em;opacity:0.7;">Uncompressed</span>
                </div>
                <div style="background:rgba(0,0,0,0.1);padding:6px;border-radius:4px;text-align:center;">
                    <span id="zram-compressed" style="font-size:1.1em;font-weight:bold;display:block;color:#7fba59;"><?php echo $fmt($totalCompressed); ?></span>
                    <span style="font-size:0.75em;opacity:0.7;">Compressed</span>
                </div>
                <div style="background:rgba(0,0,0,0.1);padding:6px;border-radius:4px;text-align:center;">
                    <span id="zram-ratio" style="font-size:1.1em;font-weight:bold;display:block;color:#ffae00;"><?php echo $ratio; ?>x</span>
                    <span style="font-size:0.75em;opacity:0.7;">Ratio</span>
                </div>
                <div style="background:rgba(0,0,0,0.1);padding:6px;border-radius:4px;text-align:center;">
                    <span id="zram-load" style="font-size:1.1em;font-weight:bold;display:block;color:#e57373;">0%</span>
                    <span style="font-size:0.75em;opacity:0.7;">Load</span>
                </div>
<?php endif; ?>
<?php if ($tier2): ?>
                <div style="background:rgba(0,0,0,0.1);padding:6px;border-radius:4px;text-align:center;">
                    <span id="zram-disk" style="font-size:1.1em;font-weight:bold;display:block;color:#00a4d8;"><?php echo $fmt($ssdUsed); ?></span>
                    <span style="font-size:0.75em;opacity:0.7;">Disk Used</span>
                </div>
<?php endif; ?>
                <div style="background:rgba(0,0,0,0.1);padding:6px;border-radius:4px;text-align:center;">
                    <span id="zram-swappiness" style="font-size:1.1em;font-weight:bold;display:block;color:#ba7fba;"><?php echo $swappiness; ?></span>
                    <span style="font-size:0.75em;opacity:0.7;">Swappiness</span>
                </div>
            </div>
            <div style="height:70px;width:100%;margin-bottom:8px;"><canvas id="zramChart"></canvas></div>
            <div id="zram-device-list" class="zram-devtable">
                <div class="zram-devgrid zram-devhead">
                    <div>Tier</div>
                    <div>Pool</div>
                    <div>Dev</div>
                    <div class="zram-num">Size</div>
                    <div class="zram-num">Used</div>
                    <div class="zram-num">Prio</div>
                    <div class="zram-num">Algo</div>
                </div>
<?php if ($ourDev): ?>
                <div class="zram-devgrid zram-devrow">
                    <div class="zram-tier" style="color:#7fba59;"><span class="zram-dot" style="background:#7fba59;"></span>ZRAM</div>
                    <div class="zram-muted">RAM</div>
                    <div class="zram-ell" style="font-weight:bold;"><?php echo htmlspecialchars($ourDev); ?></div>
                    <div class="zram-num zram-muted"><?php echo $fmt($diskSize, 0); ?></div>
                    <div class="zram-num zram-muted"><?php echo number_format($totalOriginal / 1073741824, 2); ?> GB</div>
                    <div class="zram-num zram-muted"><?php echo $prio; ?></div>
                    <div class="zram-num zram-muted"><?php echo htmlspecialchars($algo); ?></div>
                </div>
<?php endif; ?>
<?php if ($ssdPath): ?>
                <div id="zram-ssd-row" class="zram-devgrid zram-devrow">
                    <div class="zram-tier" style="<?php echo $ssdActive ? 'color:#00a4d8;' : 'opacity:0.6;'; ?>"><span class="zram-dot" style="background:<?php echo $ssdActive ? '#00a4d8' : '#9aa0a6'; ?>;"></span>Disk<?php if (!$ssdActive) echo ' (idle)'; ?></div>
                    <div class="zram-muted zram-ell" title="<?php echo htmlspecialchars($ssdMount); ?>"><?php echo htmlspecialchars($ssdPool); ?></div>
                    <div class="zram-muted zram-ell" title="<?php echo htmlspecialchars($ssdPath); ?>"><?php echo htmlspecialchars($ssdDevLabel); ?></div>
                    <div class="zram-num zram-muted"><?php echo $fmt($ssdSize ?: filesize($ssdPath), 0); ?></div>
                    <div class="zram-num zram-muted"><?php echo number_format($ssdUsed / 1073741824, 2); ?> GB</div>
                    <div class="zram-num zram-muted"><?php echo htmlspecialchars((string)$ssdPrio); ?></div>
                    <div class="zram-num zram-muted">&mdash;</div>
                </div>
<?php endif; ?>
            </div>
<?php else: ?>
            <div style="text-align:center;opacity:0.6;padding:14px 8px;font-size:0.85em;">
                <i class="fa fa-info-circle" style="opacity:0.5;margin-right:6px;"></i>
                No ZRAM devices active. No swap configured &mdash; see <a href="/Dashboard/Settings/UnraidZramCard" style="color:#ff8c00;">ZRAM Settings</a> to set one up.
            </div>
<?php endif; ?>
        </div>
        <script>
            window.ZRAM_CONFIG = {
                url: '/plugins/unraid-zram-card/zram_status.php',
                pollInterval: <?php echo $pollInterval; ?>,
                tier1Active: <?php echo $tier1 ? 'true' : 'false'; ?>,
                tier2Active: <?php echo $tier2 ? 'true' : 'false'; ?>
            };
        </script>
        <script src="/plugins/unraid-zram-card/js/chart.min.js?v=<?php echo $chartMtime; ?>"></script>
        <script src="/plugins/unraid-zram-card/js/zram-card.js?v=<?php echo $jsMtime; ?>"></script>
    </td></tr>
</tbody>
<?php
            return ob_get_clean();
        } catch (Throwable $e) {
            if (ob_get_level() > 0) ob_end_clean();
            zram_log("CRITICAL: " . $e->getMessage(), 'ERROR');
            return "<tbody title='ZRAM Error'><tr><td><div style='padding:10px;color:#E57373;text-align:center;'><strong>ZRAM Plugin Error</strong><br><small>cat /tmp/unraid-zram-card/debug.log</small></div></td></tr></tbody>";
        }
    }
}
