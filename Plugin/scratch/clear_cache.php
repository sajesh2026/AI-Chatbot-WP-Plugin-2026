<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

// Clear WP Rocket cache
if (function_exists('rocket_clean_domain')) {
    echo "Clearing WP Rocket domain cache...\n";
    rocket_clean_domain();
}
if (function_exists('rocket_clean_minify')) {
    echo "Clearing WP Rocket minify cache...\n";
    rocket_clean_minify();
}

// Clear Elementor CSS cache
if (class_exists('\Elementor\Plugin')) {
    echo "Clearing Elementor files cache...\n";
    \Elementor\Plugin::$instance->files_manager->clear_cache();
}

// Clear WordPress object cache
wp_cache_flush();

echo "All caches cleared successfully!\n";

