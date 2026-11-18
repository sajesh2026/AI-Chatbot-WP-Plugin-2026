<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

$items = wp_get_nav_menu_items(4);
foreach ($items as $item) {
    echo "ID: {$item->ID} | Parent: {$item->menu_item_parent} | Order: {$item->menu_order} | Title: {$item->title} | URL: {$item->url}\n";
}

