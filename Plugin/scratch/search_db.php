<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

global $wpdb;

$terms = ['Solar Car Charging', 'billing', 'Quote', 'Request a Quote', 'Photovoltaik', 'About Us'];

echo "=== SEARCH IN POSTS ===\n";
foreach ($terms as $term) {
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_title, post_name, post_type, post_status FROM {$wpdb->posts} WHERE post_title LIKE %s OR post_content LIKE %s LIMIT 10",
        '%' . $wpdb->esc_like($term) . '%',
        '%' . $wpdb->esc_like($term) . '%'
    ));
    echo "--- Term: '$term' (Found: " . count($results) . ") ---\n";
    foreach ($results as $r) {
        echo "  [{$r->post_type}] ID {$r->ID}: {$r->post_title} (slug: {$r->post_name})\n";
    }
}

