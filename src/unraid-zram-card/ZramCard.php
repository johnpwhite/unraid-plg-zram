<?php
// ZramCard.php - Live Stats with Chart

if (!function_exists('getZramDashboardCard')) {
    function getZramDashboardCard() {
        // Debug Logger
        $log = function($msg) {
            $dir = '/tmp/unraid-zram-card';
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            @file_put_contents($dir . '/debug.log', date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
        };
        
        try {
            // --- HELPER: Format Bytes ---
            $formatBytes = function($bytes, $precision = 2) {
                $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                $bytes = max($bytes, 0);
                if ($bytes < 1) return '0 B';
                $pow = floor(log($bytes) / log(1024));
                $pow = min($pow, count($units) - 1);
                $pow = max($pow, 0);
                $bytes /= pow(1024, $pow);
                return round($bytes, $precision) . ' ' . $units[$pow];
            };

            // --- 1. Load Settings ---
            $zram_settings = ['enabled' => 'yes', 'refresh_interval' => '3000'];
            $zram_iniFile = '/boot/config/plugins/unraid-zram-card/settings.ini';
            if (file_exists($zram_iniFile)) {
                $zram_loaded = @parse_ini_file($zram_iniFile);
                if (is_array($zram_loaded)) $zram_settings = array_merge($zram_settings, $zram_loaded);
            }

            if (($zram_settings['enabled'] ?? 'yes') !== 'yes') return '';

            // --- 2. Fetch Initial ZRAM Data ---
            $output = [];
            $return_var = 0;
            exec('zramctl --output-all --bytes --json 2>/dev/null', $output, $return_var);
            
            $devices = [];
            if ($return_var === 0 && !empty($output)) {
                $parsed = json_decode(implode("\n", $output), true);
                $devices = $parsed['zramctl'] ?? [];
            } else {
                unset($output);
                exec('zramctl --output-all --bytes --noheadings --raw 2>/dev/null', $output, $return_var);
                foreach ($output as $line) {
                    $parts = preg_split('/\s+/', trim($line));
                    if (count($parts) >= 8) {
                        $devices[] = [
                            'name' => $parts[0], 'disksize' => $parts[1], 'data' => $parts[2],
                            'compr' => $parts[3], 'algorithm' => $parts[4], 'total' => $parts[7],
                        ];
                    }
                }
            }

            $totalOriginal = 0; $totalCompressed = 0; $totalUsed = 0;
            foreach ($devices as $dev) {
                $totalOriginal += intval($dev['data'] ?? 0);
                $totalCompressed += intval($dev['compr'] ?? 0);
                $totalUsed += intval($dev['total'] ?? 0);
            }
            $memorySaved = max(0, $totalOriginal - $totalUsed);
            $ratio = ($totalCompressed > 0) ? round($totalOriginal / $totalCompressed, 2) : 0.00;

            // --- 3. Unraid Version Check ---
            $zram_isResponsive = false;
            if (file_exists('/etc/unraid-version')) {
                $zram_ver_arr = @parse_ini_file('/etc/unraid-version');
                if (isset($zram_ver_arr['version'])) {
                    $zram_isResponsive = version_compare($zram_ver_arr['version'], '7.2.0-beta', '>=');
                }
            }

            // --- 4. Render Output ---
            ob_start();
?>
            <tbody title='ZRAM Usage'>
                <tr>
                    <td>
                        <span class='tile-header'>
                            <span class='tile-header-left'>
                                <img src='/plugins/unraid-zram-card/unraid-zram-card.png' style='width:32px; height:32px; margin-right:10px;'>
                                <div class='section'>
                                    <?php if ($zram_isResponsive): ?>
                                        <h3 class='tile-header-main'>ZRAM Status</h3>
                                    <?php else: ?>
                                        ZRAM Status<br>
                                    <?php endif; ?>
                                    <span class="zram-subtitle" style="font-size: 0.9em; opacity: 0.8;">
                                        <?php echo count($devices) > 0 ? 'Active (' . count($devices) . ' devs)' : 'Inactive'; ?>
                                    </span>
                                </div>
                            </span>
                            <span class='tile-header-right'>
                                <span class='tile-ctrl'>
                                    <a href="/Settings/UnraidZramCard" title="Settings"><i class="fa fa-cog"></i></a>
                                </span>
                            </span>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td>
                        <div class="zram-content" style="padding: 0 8px;">
                            <!-- Stats Grid (Tightened) -->
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(80px, 1fr)); gap: 6px; margin-bottom: 8px; margin-top: 5px;">
                                <div style="background-color: rgba(0,0,0,0.1); padding: 6px; border-radius: 4px; text-align: center;">
                                    <span id="zram-saved" style="font-size: 1.1em; font-weight: bold; display: block; color: #7fba59;">
                                        <?php echo $formatBytes($memorySaved); ?>
                                    </span>
                                    <span style="font-size: 0.75em; opacity: 0.7;">RAM Saved</span>
                                </div>
                                <div style="background-color: rgba(0,0,0,0.1); padding: 6px; border-radius: 4px; text-align: center;">
                                    <span id="zram-ratio" style="font-size: 1.1em; font-weight: bold; display: block; color: #ffae00;">
                                        <?php echo $ratio; ?>x
                                    </span>
                                    <span style="font-size: 0.75em; opacity: 0.7;">Ratio</span>
                                </div>
                                <div style="background-color: rgba(0,0,0,0.1); padding: 6px; border-radius: 4px; text-align: center;">
                                    <span id="zram-used" style="font-size: 1.1em; font-weight: bold; display: block; color: #00a4d8;">
                                        <?php echo $formatBytes($totalUsed); ?>
                                    </span>
                                    <span style="font-size: 0.75em; opacity: 0.7;">Actual Used</span>
                                </div>
                                <div style="background-color: rgba(0,0,0,0.1); padding: 6px; border-radius: 4px; text-align: center;">
                                    <span id="zram-load" style="font-size: 1.1em; font-weight: bold; display: block; color: #e57373;">
                                        0%
                                    </span>
                                    <span style="font-size: 0.75em; opacity: 0.7;">Load</span>
                                </div>
                            </div>

                            <!-- Chart Canvas (Smaller Height) -->
                            <div style="height: 70px; width: 100%; margin-bottom: 8px;">
                                <canvas id="zramChart"></canvas>
                            </div>

                            <!-- Device List (Compact) -->
                            <div id="zram-device-list" style="margin-top: 3px; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 2px;">
                                <?php if (count($devices) > 0): ?>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 4px; opacity: 0.5; font-size: 0.75em; margin-bottom: 1px; border-bottom: 1px solid rgba(255,255,255,0.05);">
                                        <div style="text-align: left;">Dev</div><div style="text-align: right;">Size</div><div style="text-align: right;">Used</div><div style="text-align: right;">Comp</div>
                                    </div>
                                    <?php foreach ($devices as $dev): ?>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 4px; font-size: 0.8em; padding: 1px 0;">
                                        <div style="text-align: left; font-weight: bold;"><?php echo htmlspecialchars($dev['name'] ?? '?'); ?></div>
                                        <div style="text-align: right; opacity: 0.7;"><?php echo $formatBytes(intval($dev['disksize'] ?? 0)); ?></div>
                                        <div style="text-align: right; opacity: 0.7;"><?php echo $formatBytes(intval($dev['total'] ?? 0)); ?></div>
                                        <div style="text-align: right; opacity: 0.7;"><?php echo htmlspecialchars($dev['algorithm'] ?? '?'); ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="text-align: center; opacity: 0.5; padding: 3px; font-size: 0.8em;">No ZRAM devices active.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Configuration and Scripts -->
                        <script>
                            window.ZRAM_CONFIG = {
                                url: '/plugins/unraid-zram-card/zram_status.php',
                                pollInterval: <?php echo intval($zram_settings['refresh_interval'] ?? 3000); ?>
                            };
                        </script>
                        <script src="/plugins/unraid-zram-card/js/chart.min.js"></script>
                        <script src="/plugins/unraid-zram-card/js/zram-card.js"></script>
                    </td>
                </tr>
            </tbody>
<?php
            return ob_get_clean();

        } catch (Throwable $e) {
            if (ob_get_level() > 0) ob_end_clean();
            $log("CRITICAL ERROR: " . $e->getMessage());
            return "<tbody title='ZRAM Error'><tr><td><div style='padding: 10px; color: #E57373; text-align: center;'><strong>ZRAM Plugin Error</strong><br><small>Run: cat /tmp/unraid-zram-card/debug.log</small></div></td></tr></tbody>";
        }
    }
}
?>