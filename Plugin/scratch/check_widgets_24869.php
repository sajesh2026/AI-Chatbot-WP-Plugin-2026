<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

$p = get_post(24869);
echo "Post content: " . $p->post_content . "\n";
$meta = get_post_meta(24869, '_elementor_data', true);
$data = json_decode($meta, true);

function find_widgets($elements) {
    foreach ($elements as $el) {
        if (!empty($el['widgetType'])) {
            echo "Widget: {$el['widgetType']} (id: {$el['id']})\n";
            if (!empty($el['settings']['shortcode'])) echo "  shortcode: {$el['settings']['shortcode']}\n";
            if (!empty($el['settings']['html'])) echo "  html: " . substr(strip_tags($el['settings']['html']), 0, 100) . "\n";
        }
        if (!empty($el['elements'])) {
            find_widgets($el['elements']);
        }
    }
}
find_widgets($data);

