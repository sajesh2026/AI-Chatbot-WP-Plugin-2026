<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

global $wpdb;

$rows = $wpdb->get_results("SELECT * FROM wp_trp_original_strings WHERE original LIKE '%billing%'", ARRAY_A);
print_r($rows);

// Search postmeta for that string or part of it
$pm = $wpdb->get_results("SELECT * FROM wp_postmeta WHERE meta_value LIKE '%20-station workplace%' OR meta_value LIKE '%billing system%'", ARRAY_A);
echo "=== POSTMETA ===\n";
print_r($pm);

$posts = $wpdb->get_results("SELECT ID, post_title, post_name, post_type FROM wp_posts WHERE post_content LIKE '%20-station workplace%' OR post_content LIKE '%billing system%'", ARRAY_A);
echo "=== POSTS ===\n";
print_r($posts);

