<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

global $wpdb;

$backup = [
    'date' => date('c'),
    'header_post' => get_post(23393),
    'header_meta' => get_post_meta(23393),
    'footer_post' => get_post(23486),
    'footer_meta' => get_post_meta(23486),
    'menu_4_items' => wp_get_nav_menu_items(4),
    'menu_2_items' => wp_get_nav_menu_items(2),
    'menu_locations' => get_nav_menu_locations(),
    'ai_chatbot_settings_1' => get_option('ai_chatbot_settings_1'),
    'theme_style_css' => file_get_contents(get_stylesheet_directory() . '/style.css'),
    'theme_functions_php' => file_get_contents(get_stylesheet_directory() . '/functions.php'),
];

file_put_contents(__DIR__ . '/pre_change_backup.json', json_encode($backup, JSON_PRETTY_PRINT));
echo "Backup successfully written to pre_change_backup.json (" . strlen(json_encode($backup)) . " bytes)\n";

