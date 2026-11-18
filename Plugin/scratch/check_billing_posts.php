<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

global $wpdb;

$posts = $wpdb->get_results("SELECT ID, post_title, post_name, post_type, post_status FROM {$wpdb->posts} WHERE (post_content LIKE '%billing%' OR post_title LIKE '%billing%') AND post_type != 'revision'");
echo "=== POSTS WITH BILLING (NON-REVISION) ===\n";
foreach ($posts as $p) {
    echo "ID: {$p->ID} | Title: {$p->post_title} | Slug: {$p->post_name} | Type: {$p->post_type} | Status: {$p->post_status}\n";
}
if (empty($posts)) {
    echo "NO non-revision posts found with 'billing'!\n";
}

