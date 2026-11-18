<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

global $wpdb;

echo "=== 1. UPDATING HEADER TEMPLATE 23393 ===\n";
$raw = get_post_meta(23393, '_elementor_data', true);
$data = json_decode($raw, true);

function clean_responsive_hide(&$elements) {
    foreach ($elements as &$el) {
        if (($el['id'] ?? '') === 'e4c8722') {
            echo "Found widget e4c8722! Removing hide_tablet, hide_mobile...\n";
            unset($el['settings']['hide_tablet_extra']);
            unset($el['settings']['hide_tablet']);
            unset($el['settings']['hide_mobile']);
        }
        if (!empty($el['elements'])) {
            clean_responsive_hide($el['elements']);
        }
    }
}

clean_responsive_hide($data);
update_post_meta(23393, '_elementor_data', wp_slash(json_encode($data)));
echo "Header template 23393 updated!\n";

echo "\n=== 2. UPDATING FOOTER TEMPLATE 23486 ===\n";
$fraw = get_post_meta(23486, '_elementor_data', true);
$fdata = json_decode($fraw, true);

function update_footer_links(&$elements) {
    foreach ($elements as &$el) {
        // Fix phone number link to tel:
        if (($el['id'] ?? '') === '8862ec9') {
            echo "Found footer phone widget 8862ec9! Updating link to tel:+4945148978323...\n";
            $el['settings']['link']['url'] = 'tel:+4945148978323';
        }
        // Add Quote link under "Weitere Links" container (container id: ab1a65a or parent of b47a71a)
        if (($el['id'] ?? '') === 'ab1a65a' || ($el['id'] ?? '') === 'c4df677') {
            // Check if quote link already exists
        }
        if (!empty($el['elements'])) {
            update_footer_links($el['elements']);
        }
    }
}

// Find container holding "Weitere Links"
function add_quote_to_footer(&$elements) {
    foreach ($elements as &$el) {
        if (!empty($el['elements'])) {
            $has_weitere = false;
            $has_quote = false;
            foreach ($el['elements'] as $sub) {
                if (($sub['settings']['title'] ?? '') === 'Weitere Links') $has_weitere = true;
                if (($sub['settings']['title'] ?? '') === 'Angebot anfordern') $has_quote = true;
            }
            if ($has_weitere && !$has_quote) {
                echo "Found 'Weitere Links' container! Adding 'Angebot anfordern' link...\n";
                $el['elements'][] = [
                    'id' => 'quote_' . substr(md5(time()), 0, 7),
                    'elType' => 'widget',
                    'settings' => [
                        'title' => 'Angebot anfordern',
                        'link' => [
                            'url' => '/ids-energy.de/photovoltaik-angebot/',
                            'is_external' => '',
                            'nofollow' => '',
                            'custom_attributes' => '',
                        ],
                        'header_size' => 'p',
                        '_element_width' => 'initial',
                        '_element_width_mobile' => 'inherit',
                    ],
                    'elements' => [],
                    'widgetType' => 'heading',
                ];
            }
            add_quote_to_footer($el['elements']);
        }
    }
}

update_footer_links($fdata);
add_quote_to_footer($fdata);
update_post_meta(23486, '_elementor_data', wp_slash(json_encode($fdata)));
echo "Footer template 23486 updated!\n";

echo "\n=== 3. UPDATING MENU ID 4 (MAIN MENU) ===\n";
// Check if Angebot, Kontakt, Phone already exist in Menu 4
$existing = wp_get_nav_menu_items(4);
$has_angebot = false;
$has_kontakt = false;
$has_tel = false;
$max_order = 0;

foreach ($existing as $item) {
    if (stripos($item->url, 'photovoltaik-angebot') !== false || stripos($item->title, 'Angebot') !== false) {
        $has_angebot = true;
    }
    if (stripos($item->url, 'kontakt') !== false && $item->menu_item_parent == 0) {
        $has_kontakt = true;
    }
    if (stripos($item->url, 'tel:') !== false) {
        $has_tel = true;
    }
    if ($item->menu_order > $max_order) {
        $max_order = $item->menu_order;
    }
}

if (!$has_angebot) {
    echo "Adding 'Angebot anfordern' to Menu 4...\n";
    $max_order++;
    wp_update_nav_menu_item(4, 0, [
        'menu-item-title'   => 'Angebot',
        'menu-item-url'     => 'http://localhost/ids-energy.de/photovoltaik-angebot/',
        'menu-item-type'    => 'custom',
        'menu-item-status'  => 'publish',
        'menu-item-position'=> 11,
    ]);
}

if (!$has_kontakt) {
    echo "Adding 'Kontakt' to Menu 4...\n";
    $max_order++;
    wp_update_nav_menu_item(4, 0, [
        'menu-item-title'   => 'Kontakt',
        'menu-item-url'     => 'http://localhost/ids-energy.de/kontakt/',
        'menu-item-type'    => 'custom',
        'menu-item-status'  => 'publish',
        'menu-item-position'=> 12,
    ]);
}

if (!$has_tel) {
    echo "Adding 'Tel: +49 (0) 451 489 78 323' to Menu 4...\n";
    $max_order++;
    wp_update_nav_menu_item(4, 0, [
        'menu-item-title'   => '📞 +49 (0) 451 489 78 323',
        'menu-item-url'     => 'tel:+4945148978323',
        'menu-item-type'    => 'custom',
        'menu-item-status'  => 'publish',
        'menu-item-position'=> 13,
    ]);
}

echo "Menu ID 4 updated successfully!\n";

