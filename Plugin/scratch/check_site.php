<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

echo "=== PAGES ===\n";
$pages = get_posts([
    'post_type' => 'page',
    'numberposts' => -1,
    'post_status' => 'any',
]);
foreach ($pages as $p) {
    echo "ID: {$p->ID} | Slug: {$p->post_name} | Title: {$p->post_title} | Status: {$p->post_status} | Template: " . get_post_meta($p->ID, '_wp_page_template', true) . "\n";
}

echo "\n=== NAV MENUS ===\n";
$menus = wp_get_nav_menus();
foreach ($menus as $m) {
    echo "Menu ID: {$m->term_id} | Name: {$m->name} | Slug: {$m->slug} | Count: {$m->count}\n";
    $items = wp_get_nav_menu_items($m->term_id);
    if ($items) {
        foreach ($items as $item) {
            echo "  - Item ID: {$item->ID} | Title: {$item->title} | URL: {$item->url} | Type: {$item->type} | Object: {$item->object} | ObjectID: {$item->object_id} | Status: {$item->post_status}\n";
        }
    }
}

echo "\n=== MENU LOCATIONS ===\n";
$locations = get_nav_menu_locations();
print_r($locations);

