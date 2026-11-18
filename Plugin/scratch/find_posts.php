<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

global $wpdb;
$posts = $wpdb->get_results("SELECT ID, post_title, post_name, post_status, post_type FROM {$wpdb->posts} WHERE post_title LIKE '%charg%' OR post_title LIKE '%solar%' OR post_title LIKE '%bill%' OR post_content LIKE '%billing%' LIMIT 50");
foreach ($posts as $p) {
    echo "ID: {$p->ID} | Title: {$p->post_title} | Slug: {$p->post_name} | Type: {$p->post_type} | Status: {$p->post_status}\n";
}

