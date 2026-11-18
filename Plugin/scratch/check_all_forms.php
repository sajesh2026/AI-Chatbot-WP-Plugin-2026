<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

global $wpdb;

echo "=== FLUENTFORMS ===\n";
if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}fluentform_forms'")) {
    $ff = $wpdb->get_results("SELECT id, title, status FROM {$wpdb->prefix}fluentform_forms");
    foreach ($ff as $f) {
        echo "ID: {$f->id} | Title: {$f->title} | Status: {$f->status}\n";
    }
}

echo "\n=== FUNNELFORMS ===\n";
$af2 = $wpdb->get_results("SELECT ID, post_title, post_name, post_type FROM {$wpdb->posts} WHERE post_type LIKE 'af2_%' LIMIT 20");
foreach ($af2 as $f) {
    echo "ID: {$f->ID} | Type: {$f->post_type} | Title: {$f->post_title}\n";
}

echo "\n=== WPFORMS ===\n";
$wpf = $wpdb->get_results("SELECT ID, post_title, post_name FROM {$wpdb->posts} WHERE post_type = 'wpforms'");
foreach ($wpf as $f) {
    echo "ID: {$f->ID} | Title: {$f->post_title}\n";
}

