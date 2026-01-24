<?php
// ZramCard.php
// Dashboard Card for ZRAM Statistics

// Load Settings
$configFile = "/boot/config/plugins/unraid-zram-card/settings.ini";
$settings = [
    'enabled' => 'yes',
    'refresh_interval' => 3000
];
if (file_exists($configFile)) {
    $loaded = parse_ini_file($configFile);
    if ($loaded) $settings = array_merge($settings, $loaded);
}

// Check if enabled
if ($settings['enabled'] != 'yes') {
    return;
}

// Check for Unraid 7.2+ Responsive GUI
$isResponsiveWebgui = version_compare(parse_ini_file('/etc/unraid-version')['version'] ?? '6.0.0', '7.2.0-beta', '>=');

// Unique ID for this card's elements to avoid collisions
$cardId = 'zram-dashboard-card';
?>

<style>
#<?php echo $cardId; ?> .zram-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 10px;
    margin-bottom: 10px;
}
#<?php echo $cardId; ?> .zram-stat-item {
    background-color: rgba(255, 255, 255, 0.05);
    padding: 10px;
    border-radius: 4px;
    text-align: center;
}
#<?php echo $cardId; ?> .zram-stat-value {
    font-size: 1.2em;
    font-weight: bold;
    display: block;
}
#<?php echo $cardId; ?> .zram-stat-label {
    font-size: 0.8em;
    opacity: 0.7;
}
#<?php echo $cardId; ?> table {
    width: 100%;
    font-size: 0.9em;
    margin-top: 10px;
    border-collapse: collapse;
}
#<?php echo $cardId; ?> th {
    text-align: left;
    opacity: 0.6;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}
#<?php echo $cardId; ?> td {
    padding: 4px 0;
}
</style>

<tbody title='ZRAM Usage' id='<?php echo $cardId; ?>'>
  <tr>
    <td>
      <span class='tile-header'>
        <span class='tile-header-left'>
          <i class='fa fa-compress f32'></i> <!-- Icon -->
          <div class='section'>
            <?php if ($isResponsiveWebgui): ?>
              <h3 class='tile-header-main'>ZRAM Status</h3>
              <span id="zram-subtitle">Initializing...</span>
            <?php else: ?>
              ZRAM Status<br>
              <span id="zram-subtitle-legacy">Initializing...</span><br>
            <?php endif; ?>
          </div>
        </span>
        <span class='tile-header-right'>
           <!-- Settings Cog (could link to settings page) -->
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

            <!-- Device Table (Collapsible or small) -->
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
                pollInterval: <?php echo intval($settings['refresh_interval']); ?>, 
                url: '/plugins/unraid-zram-card/zram_status.php'
            };
        </script>
        <!-- Load Chart.js and then our logic -->
        <script src="/plugins/unraid-zram-card/js/chart.min.js"></script>
        <script src="/plugins/unraid-zram-card/js/zram-card.js"></script>
    </td>
  </tr>
</tbody>
