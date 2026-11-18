<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

global $wpdb;

$terms = ['Abrechnung', 'Abrechnungssystem', 'Zahlung', 'Rechnung'];

foreach ($terms as $t) {
    $res = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_title, post_name, post_type, post_status FROM {$wpdb->posts} WHERE (post_content LIKE %s OR post_title LIKE %s) AND post_type != 'revision'",
        '%' . $wpdb->esc_like($t) . '%',
        '%' . $wpdb->esc_like($t) . '%'
    ));
    echo "Term '$t' found: " . count($res) . "\n";
    foreach ($res as $r) {
        echo "  [{$r->post_type}] ID {$r->ID}: {$r->post_title} (slug: {$r->post_name})\n";
    }
}

