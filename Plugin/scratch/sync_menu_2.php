<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

$menu_id = 2; // Primary
$existing = wp_get_nav_menu_items($menu_id);
$urls = [];
foreach ($existing as $item) {
    $urls[] = $item->url;
}

$to_add = [
    [
        'title' => 'Photovoltaik',
        'url'   => 'http://localhost/ids-energy.de/photovoltaik/',
    ],
    [
        'title' => 'Über uns',
        'url'   => 'http://localhost/ids-energy.de/ueber-uns/',
    ],
    [
        'title' => 'Angebot',
        'url'   => 'http://localhost/ids-energy.de/photovoltaik-angebot/',
    ],
    [
        'title' => '📞 +49 (0) 451 489 78 323',
        'url'   => 'tel:+4945148978323',
    ],
];

foreach ($to_add as $item) {
    if (!in_array($item['url'], $urls)) {
        echo "Adding '{$item['title']}' to Menu 2...\n";
        wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title'   => $item['title'],
            'menu-item-url'     => $item['url'],
            'menu-item-type'    => 'custom',
            'menu-item-status'  => 'publish',
        ]);
    }
}

echo "Menu ID 2 sync complete!\n";

