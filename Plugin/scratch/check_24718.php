<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

$meta = get_post_meta(24718, '_elementor_data', true);
if (stripos($meta, 'billing') !== false) {
    echo "Found billing in post 24718!\n";
    preg_match_all('/.{0,100}billing.{0,100}/i', $meta, $matches);
    print_r($matches[0]);
} else {
    echo "Billing NOT in post 24718\n";
}

