<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

global $shortcode_tags;
echo "=== REGISTERED SHORTCODES (" . count($shortcode_tags) . ") ===\n";
$keys = array_keys($shortcode_tags);
sort($keys);
foreach ($keys as $k) {
    if (preg_match('/bill|chat|ai_|form|contact|quote/i', $k)) {
        echo "  - $k\n";
    }
}

