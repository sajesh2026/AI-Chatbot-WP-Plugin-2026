<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

global $wpdb;

$options = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%ai_chatbot%' OR option_name LIKE '%mxchat%'");
echo "=== CHATBOT OPTIONS ===\n";
foreach ($options as $o) {
    echo "Option: {$o->option_name}\n";
    $val = maybe_unserialize($o->option_value);
    if (is_array($val) || is_object($val)) {
        echo print_r($val, true) . "\n";
    } else {
        echo substr((string)$val, 0, 300) . "\n";
    }
    echo "----------------------------------------\n";
}

