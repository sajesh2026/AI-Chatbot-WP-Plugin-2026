<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

global $wpdb;

$posts = $wpdb->get_results("SELECT ID, post_title, post_name, post_content FROM {$wpdb->posts} WHERE post_content LIKE '%[fluentform%' OR post_content LIKE '%[wpforms%' OR post_content LIKE '%[funnelforms%' OR post_content LIKE '%[fiform%' OR post_content LIKE '%[contact-form%' OR post_content LIKE '%[ai_chatbot%'");
echo "=== POSTS WITH SHORTCODES ===\n";
foreach ($posts as $p) {
    preg_match_all('/\[[a-zA-Z0-9_\-]+[^\]]*\]/', $p->post_content, $matches);
    echo "ID {$p->ID} ({$p->post_name}): " . implode(', ', $matches[0]) . "\n";
}

$pm = $wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND (meta_value LIKE '%shortcode%' OR meta_value LIKE '%fluentform%' OR meta_value LIKE '%wpforms%' OR meta_value LIKE '%funnelforms%')");
echo "\n=== ELEMENTOR POSTS WITH SHORTCODES ===\n";
foreach ($pm as $m) {
    preg_match_all('/\[[a-zA-Z0-9_\-]+[^\]]*\]/', $m->meta_value, $matches);
    if (!empty($matches[0])) {
        echo "Post ID {$m->post_id}: " . implode(', ', array_unique($matches[0])) . "\n";
    }
}

