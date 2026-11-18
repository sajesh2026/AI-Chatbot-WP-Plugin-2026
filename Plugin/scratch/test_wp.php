<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

echo "Theme: " . get_stylesheet() . "\n";
$plugins = get_option('active_plugins');
echo "Active Plugins:\n";
print_r($plugins);
