<?php
// ZramCard.php - Safe Mode with Debugging

if (!function_exists('getZramDashboardCard')) {
    function getZramDashboardCard() {
        // Debug Logger
        $log = function($msg) {
            file_put_contents('/tmp/zram_debug.log', date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
        };
        
        $log("Card render started.");

        try {
            // --- HELPER: Format Bytes ---
            $formatBytes = function($bytes, $precision = 2) {
                $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                $bytes = max($bytes, 0);
                $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
                $pow = min($pow, count($units) - 1);
                $bytes /= pow(1024, $pow);
                return round($bytes, $precision) . ' ' . $units[$pow];
            };

            // --- 1. Load Settings ---
            $zram_settings = ['enabled' => 'yes', 'refresh_interval' => 3000];
            $zram_iniFile = '/boot/config/plugins/unraid-zram-card/settings.ini';
            if (file_exists($zram_iniFile)) {
                $zram_loaded = @parse_ini_file($zram_iniFile);
                if (is_array($zram_loaded)) $zram_settings = array_merge($zram_settings, $zram_loaded);
            }

            if (($zram_settings['enabled'] ?? 'yes') !== 'yes') {
                $log("Card disabled in settings.");
                return '';
            }

            // --- 2. Fetch ZRAM Data ---
            $output = [];
            $return_var = 0;
            
            // Log command execution
            $cmd = 'zramctl --output-all --bytes --json 2>/dev/null';
            exec($cmd, $output, $return_var);
            
            $log("zramctl return: $return_var");
            $log("zramctl output: " . implode(" ", $output));

            $devices = [];
            if ($return_var === 0 && !empty($output)) {
                $jsonString = implode("\n", $output);
                $parsed = json_decode($jsonString, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $log("JSON Decode Error: " . json_last_error_msg());
                }
                $devices = $parsed['zramctl'] ?? [];
            } else {
                // Fallback: Raw parsing
                $log("Fallback to raw parsing.");
                unset($output);
                exec('zramctl --output-all --bytes --noheadings --raw 2>/dev/null', $output, $return_var);
                foreach ($output as $line) {
                    $parts = preg_split('/\s+/', trim($line));
                    // Log raw line parts to ensure we aren't indexing out of bounds
                    // $log("Raw line parts: " . count($parts)); 
                    if (count($parts) >= 5) { // Relaxed check
                         // Map raw columns safely. Adjust indices based on your 'zramctl --output-all' output
                         // Typically: NAME DISKSIZE DATA COMPR ALGORITHM STREAMS ZERO-PAGES TOTAL ...
                        $devices[] = [
                            'name' => $parts[0] ?? '?',
                            'disksize' => $parts[1] ?? 0,
                            'data' => $parts[2] ?? 0,
                            'compr' => $parts[3] ?? 0,
                            'algorithm' => $parts[4] ?? '?',
                            'total' => $parts[7] ?? 0, 
                        ];
                    }
                }
            }

            $log("Devices parsed: " . count($devices));

            // --- 3. Calculate Aggregates ---
            $totalOriginal = 0;
            $totalCompressed = 0;
            $totalUsed = 0;
            
            foreach ($devices as $dev) {
                // Safely cast to int to prevent math errors on strings
                $totalOriginal += intval($dev['data'] ?? 0);
                $totalCompressed += intval($dev['compr'] ?? 0);
                $totalUsed += intval($dev['total'] ?? 0);
            }

            $memorySaved = max(0, $totalOriginal - $totalUsed);
            $ratio = ($totalCompressed > 0) ? round($totalOriginal / $totalCompressed, 2) : 0.00;
            
            $log("Aggregates: Saved=$memorySaved, Ratio=$ratio, Used=$totalUsed");

            // --- 4. Unraid Version Check ---
            $zram_isResponsive = false;
            if (file_exists('/etc/unraid-version')) {
                $zram_ver_arr = @parse_ini_file('/etc/unraid-version');
                if (isset($zram_ver_arr['version'])) {
                    $zram_isResponsive = version_compare($zram_ver_arr['version'], '7.2.0-beta', '>=');
                }
            }

            // --- 5. Render Output ---
            ob_start();
?>
            <tbody title='ZRAM Usage'>
                <tr>
                    <td>
                        <span class='tile-header'>
                            <span class='tile-header-left'>
                                <i class='fa fa-compress f32'></i>
                                <div class='section'>
                                    <?php if ($zram_isResponsive): ?>
                                        <h3 class='tile-header-main'>ZRAM Status</h3>
                                    <?php else: ?>
                                        ZRAM Status<br>
                                    <?php endif; ?>
                                    <span class="zram-subtitle">
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
                        <div class="zram-content" style="padding: 0 10px;">
                            <!-- Stats Grid -->
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 10px; margin-bottom: 15px; margin-top: 10px;">
                                <div style="background-color: rgba(0,0,0,0.1); padding: 10px; border-radius: 4px; text-align: center;">
                                    <span style="font-size: 1.2em; font-weight: bold; display: block; color: #7fba59;">
                                        <?php echo $formatBytes($memorySaved); ?>
                                    </span>
                                    <span style="font-size: 0.8em; opacity: 0.7;">RAM Saved</span>
                                </div>
                                <div style="background-color: rgba(0,0,0,0.1); padding: 10px; border-radius: 4px; text-align: center;">
                                    <span style="font-size: 1.2em; font-weight: bold; display: block; color: #ffae00;">
                                        <?php echo $ratio; ?>x
                                    </span>
                                    <span style="font-size: 0.8em; opacity: 0.7;">Ratio</span>
                                </div>
                                <div style="background-color: rgba(0,0,0,0.1); padding: 10px; border-radius: 4px; text-align: center;">
                                    <span style="font-size: 1.2em; font-weight: bold; display: block; color: #00a4d8;">
                                        <?php echo $formatBytes($totalUsed); ?>
                                    </span>
                                    <span style="font-size: 0.8em; opacity: 0.7;">Actual Used</span>
                                </div>
                            </div>

                            <!-- Device Table -->
                            <?php if (count($devices) > 0): ?>
                            <div class="TableContainer" style="overflow-x: auto;">
                                <table style="width: 100%; font-size: 0.85em; margin-bottom: 10px; border-collapse: separate; border-spacing: 0 4px;">
                                    <thead>
                                        <tr>
                                            <th style="text-align: left; opacity: 0.6; padding-bottom: 5px;">Dev</th>
                                            <th style="text-align: right; opacity: 0.6; padding-bottom: 5px;">Size</th>
                                            <th style="text-align: right; opacity: 0.6; padding-bottom: 5px;">Used</th>
                                            <th style="text-align: right; opacity: 0.6; padding-bottom: 5px;">Comp</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($devices as $dev): ?>
                                        <tr style="background-color: rgba(255,255,255,0.03);">
                                            <td style="padding: 4px; border-radius: 3px 0 0 3px;"><?php echo htmlspecialchars($dev['name'] ?? '?'); ?></td>
                                            <td style="text-align: right; padding: 4px;"><?php echo $formatBytes(intval($dev['disksize'] ?? 0)); ?></td>
                                            <td style="text-align: right; padding: 4px;"><?php echo $formatBytes(intval($dev['total'] ?? 0)); ?></td>
                                            <td style="text-align: right; padding: 4px; border-radius: 0 3px 3px 0;"><?php echo htmlspecialchars($dev['algorithm'] ?? '?'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            </tbody>
<?php
            $log("Render complete.");
            return ob_get_clean();

        } catch (Throwable $e) {
            // CATCH ALL: Prevent Dashboard Crash
            if (ob_get_level() > 0) ob_end_clean();
            $log("CRITICAL ERROR: " . $e->getMessage());
            $log("Stack Trace: " . $e->getTraceAsString());
            
            // Return a safe error card
            return "<tbody><tr><td><div style='color: #d9534f; padding: 10px;'><strong>ZRAM Card Error</strong><br>Check /tmp/zram_debug.log</div></td></tr></tbody>";
        }
    }
}
?>