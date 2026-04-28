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

            // SSD swap info
            $ssdInfo = '';
            $ssdPath = $cfg['ssd_swap_path'] ?? '';
            $ssdActive = false;
            if ($ssdPath && file_exists($ssdPath)) {
                $swaps = @file_get_contents('/proc/swaps') ?: '';
                $ssdActive = strpos($swaps, $ssdPath) !== false;
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
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(80px,1fr));gap:6px;margin:5px 0 8px;">
                <div style="background:rgba(0,0,0,0.1);padding:6px;border-radius:4px;text-align:center;">
                    <span id="zram-uncompressed" style="font-size:1.1em;font-weight:bold;display:block;color:#d49373;"><?php echo $fmt($totalOriginal); ?></span>
                    <span style="font-size:0.75em;opacity:0.7;">Uncompressed</span>
                </div>
                <div style="background:rgba(0,0,0,0.1);padding:6px;border-radius:4px;text-align:center;">
                    <span id="zram-compressed" style="font-size:1.1em;font-weight:bold;display:block;color:#7fba59;"><?php echo $fmt($totalUsed); ?></span>
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
                <div style="background:rgba(0,0,0,0.1);padding:6px;border-radius:4px;text-align:center;">
                    <span id="zram-swappiness" style="font-size:1.1em;font-weight:bold;display:block;color:#ba7fba;"><?php echo $swappiness; ?></span>
                    <span style="font-size:0.75em;opacity:0.7;">Swappiness</span>
                </div>
            </div>
            <div style="height:70px;width:100%;margin-bottom:8px;"><canvas id="zramChart"></canvas></div>
            <div id="zram-device-list" style="margin-top:3px;border-top:1px solid rgba(255,255,255,0.05);padding-top:2px;">
<?php if ($ourDev): ?>
                <div style="display:grid;grid-template-columns:1.2fr 1fr 0.8fr 0.8fr 1fr;gap:4px;opacity:0.5;font-size:0.75em;margin-bottom:1px;border-bottom:1px solid rgba(255,255,255,0.05);">
                    <div>Tier</div><div style="text-align:right;">Dev</div><div style="text-align:right;">Size</div><div style="text-align:right;">Prio</div><div style="text-align:right;">Algo</div>
                </div>
                <div style="display:grid;grid-template-columns:1.2fr 1fr 0.8fr 0.8fr 1fr;gap:4px;font-size:0.8em;padding:1px 0;">
                    <div style="color:#7fba59;">ZRAM</div>
                    <div style="text-align:right;font-weight:bold;"><?php echo htmlspecialchars($ourDev); ?></div>
                    <div style="text-align:right;opacity:0.7;"><?php echo $fmt($diskSize, 0); ?></div>
                    <div style="text-align:right;opacity:0.7;"><?php echo $prio; ?></div>
                    <div style="text-align:right;opacity:0.7;"><?php echo htmlspecialchars($algo); ?></div>
                </div>
<?php if ($ssdPath): ?>
                <div id="zram-ssd-row" style="display:grid;grid-template-columns:1.2fr 1fr 0.8fr 0.8fr 1fr;gap:4px;font-size:0.8em;padding:1px 0;">
                    <div style="color:<?php echo $ssdActive ? '#00a4d8' : '#666'; ?>;">Disk<?php if (!$ssdActive) echo ' (idle)'; ?></div>
                    <div style="text-align:right;opacity:0.7;" title="<?php echo htmlspecialchars($ssdPath); ?>">swap file</div>
                    <div style="text-align:right;opacity:0.7;"><?php echo $fmt(filesize($ssdPath), 0); ?></div>
                    <div style="text-align:right;opacity:0.7;">10</div>
                    <div style="text-align:right;opacity:0.7;">—</div>
                </div>
<?php endif; ?>
<?php else: ?>
                <div style="text-align:center;opacity:0.5;padding:3px;font-size:0.8em;">No ZRAM devices active.</div>
<?php endif; ?>
            </div>
        </div>
        <script>
            window.ZRAM_CONFIG = {
                url: '/plugins/unraid-zram-card/zram_status.php',
                pollInterval: <?php echo $pollInterval; ?>
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
