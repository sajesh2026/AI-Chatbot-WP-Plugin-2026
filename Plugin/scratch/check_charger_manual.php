<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

foreach ([27843, 27844] as $id) {
    $p = get_post($id);
    echo "=== POST $id ({$p->post_title}, {$p->post_name}) ===\n";
    echo "Content:\n" . substr($p->post_content, 0, 500) . "\n";
    $data = get_post_meta($id, '_elementor_data', true);
    if ($data) {
        echo "Elementor data length: " . strlen($data) . "\n";
        if (stripos($data, 'billing') !== false) {
            echo "BILLING FOUND IN POST $id!\n";
        }
    }
}

