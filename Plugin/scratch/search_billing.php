<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

global $wpdb;

$tables = $wpdb->get_col("SHOW TABLES");
foreach ($tables as $table) {
    if (in_array($table, ['wp_posts'])) continue; // we already checked posts
    $cols = $wpdb->get_results("SHOW COLUMNS FROM `$table`");
    $text_cols = [];
    foreach ($cols as $col) {
        if (preg_match('/char|text|blob/i', $col->Type)) {
            $text_cols[] = $col->Field;
        }
    }
    if (empty($text_cols)) continue;

    $where = [];
    foreach ($text_cols as $c) {
        $where[] = "`$c` LIKE '%billing%'";
    }
    $sql = "SELECT * FROM `$table` WHERE " . implode(' OR ', $where) . " LIMIT 5";
    $res = $wpdb->get_results($sql, ARRAY_A);
    if (!empty($res)) {
        echo "Found 'billing' in table: $table (count: " . count($res) . ")\n";
        foreach ($res as $r) {
            foreach ($text_cols as $c) {
                if (stripos($r[$c] ?? '', 'billing') !== false) {
                    echo "  Col $c: " . substr($r[$c], 0, 150) . "\n";
                }
            }
        }
    }
}

