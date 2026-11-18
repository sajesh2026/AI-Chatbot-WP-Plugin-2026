<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

$raw = get_post_meta(23393, '_elementor_data', true);
$data = json_decode($raw, true);

function find_widget($elements, $id) {
    foreach ($elements as $el) {
        if (($el['id'] ?? '') === $id) return $el;
        if (!empty($el['elements'])) {
            $found = find_widget($el['elements'], $id);
            if ($found) return $found;
        }
    }
    return null;
}

$w = find_widget($data, '316fad8');
echo "=== WIDGET 316fad8 ===\n";
print_r($w);

