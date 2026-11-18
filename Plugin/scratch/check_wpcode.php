<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

global $wpdb;

$snippets = $wpdb->get_results("SELECT ID, post_title, post_name, post_content, post_status FROM {$wpdb->posts} WHERE post_type = 'wpcode'");
echo "=== WPCODE SNIPPETS ===\n";
foreach ($snippets as $s) {
    echo "ID: {$s->ID} | Title: {$s->post_title} | Status: {$s->post_status}\n";
    echo "Content:\n" . $s->post_content . "\n";
    echo "Meta:\n";
    print_r(get_post_meta($s->ID));
    echo "----------------------------------------\n";
}

