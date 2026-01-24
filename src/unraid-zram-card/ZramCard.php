<?php
// ZramCard.php - Live Stats Version

if (!function_exists('getZramDashboardCard')) {
    function getZramDashboardCard() {
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

        if (($zram_settings['enabled'] ?? 'yes') !== 'yes') return '';

        // --- 2. Fetch ZRAM Data ---
        $output = [];
        $return_var = 0;
        // Try JSON first (Unraid 7 usually has modern util-linux)
        exec('zramctl --output-all --bytes --json 2>/dev/null', $output, $return_var);
        
        $devices = [];
        if ($return_var === 0 && !empty($output)) {
            $jsonString = implode("\n", $output);
            $parsed = json_decode($jsonString, true);
            $devices = $parsed['zramctl'] ?? [];
        } else {
            // Fallback: Raw parsing
            unset($output);
            exec('zramctl --output-all --bytes --noheadings --raw 2>/dev/null', $output, $return_var);
            foreach ($output as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 8) {
                    $devices[] = [
                        'name' => $parts[0],
                        'disksize' => $parts[1],
                        'data' => $parts[2], // Original data
                        'compr' => $parts[3], // Compressed data
                        'algorithm' => $parts[4],
                        'total' => $parts[7], // Total mem used
                    ];
                }
            }
        }

        // --- 3. Calculate Aggregates ---
        $totalOriginal = 0;
        $totalCompressed = 0;
        $totalUsed = 0;
        
        foreach ($devices as $dev) {
            $totalOriginal += intval($dev['data'] ?? 0);
            $totalCompressed += intval($dev['compr'] ?? 0);
            $totalUsed += intval($dev['total'] ?? 0);
        }

        // Stats Logic
        $memorySaved = max(0, $totalOriginal - $totalUsed);
        $ratio = ($totalCompressed > 0) ? round($totalOriginal / $totalCompressed, 2) : 0.00;
        
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

                        <!-- Device Table (Simplified) -->
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
                                        <td style="padding: 4px; border-radius: 3px 0 0 3px;"><?php echo htmlspecialchars($dev['name']); ?></td>
                                        <td style="text-align: right; padding: 4px;"><?php echo $formatBytes($dev['disksize']); ?></td>
                                        <td style="text-align: right; padding: 4px;"><?php echo $formatBytes($dev['total']); ?></td>
                                        <td style="text-align: right; padding: 4px; border-radius: 0 3px 3px 0;"><?php echo htmlspecialchars($dev['algorithm']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div style="text-align: center; opacity: 0.6; padding: 20px;">No ZRAM devices active.</div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        </tbody>
<?php
        return ob_get_clean();
    }
}
?>