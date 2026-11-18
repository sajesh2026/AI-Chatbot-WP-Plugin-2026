<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

$header = get_post(23393);
echo "=== HEADER (23393) ===\n";
echo "Type: " . $header->post_type . "\n";
echo "Content length: " . strlen($header->post_content) . "\n";
$header_meta = get_post_meta(23393);
foreach ($header_meta as $k => $v) {
    if ($k === '_elementor_data') {
        echo "Meta: $k (length: " . strlen($v[0]) . ")\n";
    } else {
        echo "Meta: $k = " . print_r($v, true) . "\n";
    }
}

$footer = get_post(23486);
echo "\n=== FOOTER (23486) ===\n";
echo "Type: " . $footer->post_type . "\n";
$footer_meta = get_post_meta(23486);
foreach ($footer_meta as $k => $v) {
    if ($k === '_elementor_data') {
        echo "Meta: $k (length: " . strlen($v[0]) . ")\n";
    } else {
        echo "Meta: $k = " . print_r($v, true) . "\n";
    }
}

