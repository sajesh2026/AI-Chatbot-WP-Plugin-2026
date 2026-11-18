<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

$raw = get_post_meta(24869, '_elementor_data', true);
$data = json_decode($raw, true);

function dump_text($elements) {
    foreach ($elements as $el) {
        $settings = $el['settings'] ?? [];
        if (!empty($settings['title'])) echo "TITLE: " . strip_tags($settings['title']) . "\n";
        if (!empty($settings['editor'])) echo "EDITOR: " . strip_tags($settings['editor']) . "\n";
        if (!empty($settings['html'])) echo "HTML: " . strip_tags($settings['html']) . "\n";
        if (!empty($settings['shortcode'])) echo "SHORTCODE: " . $settings['shortcode'] . "\n";
        if (!empty($el['elements'])) {
            dump_text($el['elements']);
        }
    }
}

echo "=== TEXT ON ELEKTROMOBILITAET ===\n";
dump_text($data);

