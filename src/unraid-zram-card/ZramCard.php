<?php
// ZramCard.php
// Dashboard Card for ZRAM Statistics

if (!function_exists('getZramDashboardCard')) {
    function getZramDashboardCard() {
        ob_start();
        
        // 1. Load Settings safely
        $zram_configFile = "/boot/config/plugins/unraid-zram-card/settings.ini";
        $zram_settings = [
            'enabled' => 'yes',
            'refresh_interval' => 3000
        ];

        if (file_exists($zram_configFile)) {
            $zram_loaded = @parse_ini_file($zram_configFile);
            if ($zram_loaded && is_array($zram_loaded)) {
                $zram_settings = array_merge($zram_settings, $zram_loaded);
            }
        }

        // 2. Check if enabled
        if (($zram_settings['enabled'] ?? 'yes') !== 'yes') {
            ob_end_clean();
            return '';
        }

        // 3. Check for Unraid 7.2+ Responsive GUI
        $zram_isResponsiveWebgui = false;
        if (file_exists('/etc/unraid-version')) {
            $zram_ver = @parse_ini_file('/etc/unraid-version');
            if ($zram_ver && isset($zram_ver['version'])) {
                $zram_isResponsiveWebgui = version_compare($zram_ver['version'], '7.2.0-beta', '>=');
            }
        }

        $zram_cardId = 'zram-dashboard-card';
?>
<style>
#<?php echo $zram_cardId; ?> .zram-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; margin-bottom: 10px; }
#<?php echo $zram_cardId; ?> .zram-stat-item { background-color: rgba(255, 255, 255, 0.05); padding: 10px; border-radius: 4px; text-align: center; }
#<?php echo $zram_cardId; ?> .zram-stat-value { font-size: 1.2em; font-weight: bold; display: block; }
#<?php echo $zram_cardId; ?> .zram-stat-label { font-size: 0.8em; opacity: 0.7; }
#<?php echo $zram_cardId; ?> table { width: 100%; font-size: 0.9em; margin-top: 10px; border-collapse: collapse; }
#<?php echo $zram_cardId; ?> th { text-align: left; opacity: 0.6; border-bottom: 1px solid rgba(255,255,255,0.1); }
#<?php echo $zram_cardId; ?> td { padding: 4px 0; }
</style>

<tbody title='ZRAM Usage' id='<?php echo $zram_cardId; ?>'>
  <tr>
    <td>
      <span class='tile-header'>
        <span class='tile-header-left'>
          <i class='fa fa-compress f32'></i>
          <div class='section'>
            <?php if ($zram_isResponsiveWebgui): ?>
              <h3 class='tile-header-main'>ZRAM Status</h3>
              <span id="zram-subtitle">Initializing...</span>
            <?php else: ?>
              ZRAM Status<br>
              <span id="zram-subtitle-legacy">Initializing...</span><br>
            <?php endif; ?>
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
        <div class="zram-content">
            <div class="zram-stats-grid">
                <div class="zram-stat-item"><span class="zram-stat-value" id="zram-saved">--</span><span class="zram-stat-label">RAM Saved</span></div>
                <div class="zram-stat-item"><span class="zram-stat-value" id="zram-ratio">--</span><span class="zram-stat-label">Ratio</span></div>
                <div class="zram-stat-item"><span class="zram-stat-value" id="zram-used">--</span><span class="zram-stat-label">Actual Used</span></div>
            </div>
            <div style="position: relative; height: 120px; width: 100%; max-width: 100%; overflow: hidden;">
                <canvas id="zramChart" style="display: block; width: 100%; height: 100%;"></canvas>
            </div>
            <div class="TableContainer">
                <table id="zram-device-table">
                    <thead><tr><th>Device</th><th>Disk Size</th><th>Orig Data</th><th>Compr Data</th><th>Algorithm</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <script>
            const ZRAM_CONFIG = {
                pollInterval: <?php echo intval($zram_settings['refresh_interval']); ?>, 
                url: '/plugins/unraid-zram-card/zram_status.php'
            };
        </script>
        <script src="/plugins/unraid-zram-card/js/chart.min.js"></script>
        <script src="/plugins/unraid-zram-card/js/zram-card.js"></script>
    </td>
  </tr>
</tbody>
<?php
        return ob_get_clean();
    }
}
?>

    // 3. Check for Unraid 7.2+ Responsive GUI safely
    $zram_isResponsiveWebgui = false;
    if (file_exists('/etc/unraid-version')) {
        $zram_ver = @parse_ini_file('/etc/unraid-version');
        if ($zram_ver && isset($zram_ver['version'])) {
            $zram_isResponsiveWebgui = version_compare($zram_ver['version'], '7.2.0-beta', '>=');
        }
    }

    // Unique ID for this card's elements
    $zram_cardId = 'zram-dashboard-card';
?>

<style>
#<?php echo $zram_cardId; ?> .zram-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 10px;
    margin-bottom: 10px;
}
#<?php echo $zram_cardId; ?> .zram-stat-item {
    background-color: rgba(255, 255, 255, 0.05);
    padding: 10px;
    border-radius: 4px;
    text-align: center;
}
#<?php echo $zram_cardId; ?> .zram-stat-value {
    font-size: 1.2em;
    font-weight: bold;
    display: block;
}
#<?php echo $zram_cardId; ?> .zram-stat-label {
    font-size: 0.8em;
    opacity: 0.7;
}
#<?php echo $zram_cardId; ?> table {
    width: 100%;
    font-size: 0.9em;
    margin-top: 10px;
    border-collapse: collapse;
}
#<?php echo $zram_cardId; ?> th {
    text-align: left;
    opacity: 0.6;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}
#<?php echo $zram_cardId; ?> td {
    padding: 4px 0;
}
</style>

<tbody title='ZRAM Usage' id='<?php echo $zram_cardId; ?>'>
  <tr>
    <td>
      <span class='tile-header'>
        <span class='tile-header-left'>
          <i class='fa fa-compress f32'></i> <!-- Icon -->
          <div class='section'>
            <?php if ($zram_isResponsiveWebgui): ?>
              <h3 class='tile-header-main'>ZRAM Status</h3>
              <span id="zram-subtitle">Initializing...</span>
            <?php else: ?>
              ZRAM Status<br>
              <span id="zram-subtitle-legacy">Initializing...</span><br>
            <?php endif; ?>
          </div>
        </span>
        <span class='tile-header-right'>
           <!-- Settings Cog -->
          <span class='tile-ctrl'>
             <a href="/Settings/UnraidZramCard" title="Settings"><i class="fa fa-cog"></i></a>
          </span>
        </span>
      </span>
    </td>
  </tr>
  <tr>
    <td>
        <div class="zram-content">
            <!-- Top Level Stats -->
            <div class="zram-stats-grid">
                <div class="zram-stat-item">
                    <span class="zram-stat-value" id="zram-saved">--</span>
                    <span class="zram-stat-label">RAM Saved</span>
                </div>
                <div class="zram-stat-item">
                    <span class="zram-stat-value" id="zram-ratio">--</span>
                    <span class="zram-stat-label">Ratio</span>
                </div>
                <div class="zram-stat-item">
                    <span class="zram-stat-value" id="zram-used">--</span>
                    <span class="zram-stat-label">Actual Used</span>
                </div>
            </div>

            <!-- Chart -->
            <div style="position: relative; height: 120px; width: 100%; max-width: 100%; overflow: hidden;">
                <canvas id="zramChart" style="display: block; width: 100%; height: 100%;"></canvas>
            </div>

            <!-- Device Table -->
            <div class="TableContainer">
                <table id="zram-device-table">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Disk Size</th>
                            <th>Orig Data</th>
                            <th>Compr Data</th>
                            <th>Algorithm</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Populated by JS -->
                    </tbody>
                </table>
            </div>
        </div>

        <script>
            // Configuration passed from PHP
            const ZRAM_CONFIG = {
                pollInterval: <?php echo intval($zram_settings['refresh_interval']); ?>, 
                url: '/plugins/unraid-zram-card/zram_status.php'
            };
        </script>
        <script src="/plugins/unraid-zram-card/js/chart.min.js"></script>
        <script src="/plugins/unraid-zram-card/js/zram-card.js"></script>
    </td>
  </tr>
</tbody>

<?php endif; // End check for enabled ?>
