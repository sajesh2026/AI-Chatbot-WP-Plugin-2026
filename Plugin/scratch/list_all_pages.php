<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

$pages = get_posts([
    'post_type' => 'page',
    'numberposts' => -1,
    'post_status' => 'publish'
]);

foreach ($pages as $p) {
    echo "ID: {$p->ID} | Title: {$p->post_title} | URL: " . get_permalink($p->ID) . "\n";
}

