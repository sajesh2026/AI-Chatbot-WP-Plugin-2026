<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

$raw = get_post_meta(23486, '_elementor_data', true);
$data = json_decode($raw, true);

function dump_elements($elements, $depth = 0) {
    foreach ($elements as $el) {
        $type = $el['elType'] ?? '';
        $widgetType = $el['widgetType'] ?? '';
        $settings = $el['settings'] ?? [];
        $id = $el['id'] ?? '';
        $indent = str_repeat('  ', $depth);
        if ($widgetType) {
            echo "{$indent}[$widgetType] (id: $id)\n";
            if (!empty($settings['title'])) echo "{$indent}  title: {$settings['title']}\n";
            if (!empty($settings['menu'])) echo "{$indent}  menu: {$settings['menu']}\n";
            if (!empty($settings['link'])) echo "{$indent}  link: " . json_encode($settings['link']) . "\n";
            if (!empty($settings['text'])) echo "{$indent}  text: " . substr(strip_tags($settings['text']), 0, 80) . "\n";
            if (!empty($settings['editor'])) echo "{$indent}  editor: " . substr(strip_tags($settings['editor']), 0, 80) . "\n";
        }
        if (!empty($el['elements'])) {
            dump_elements($el['elements'], $depth + 1);
        }
    }
}

echo "=== FOOTER ELEMENTS ===\n";
dump_elements($data);

