<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

global $wpdb;

$rows = $wpdb->get_results("SELECT original, translated FROM wp_trp_dictionary_de_de_en_us WHERE original LIKE '%Lade%' OR original LIKE '%Wallbox%' OR original LIKE '%Solar%' OR original LIKE '%Mobilit%' OR translated LIKE '%charg%' OR translated LIKE '%solar%' LIMIT 50");
foreach ($rows as $r) {
    echo "DE: {$r->original} ===> EN: {$r->translated}\n";
}

