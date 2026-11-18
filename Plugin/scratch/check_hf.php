<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

global $wpdb;

echo "=== POST TYPES IN USE ===\n";
$types = $wpdb->get_results("SELECT post_type, COUNT(*) as cnt FROM {$wpdb->posts} GROUP BY post_type");
foreach ($types as $t) {
    echo "{$t->post_type}: {$t->cnt}\n";
}

echo "\n=== HEADER / FOOTER TEMPLATES ===\n";
$hf = $wpdb->get_results("SELECT ID, post_title, post_name, post_type, post_status FROM {$wpdb->posts} WHERE post_type IN ('elementor_library', 'btf_builder', 'boostify_hf') OR post_title LIKE '%header%' OR post_title LIKE '%footer%' OR post_title LIKE '%menu%'");
foreach ($hf as $h) {
    echo "[{$h->post_type}] ID {$h->ID}: {$h->post_title} (slug: {$h->post_name}, status: {$h->post_status})\n";
}

