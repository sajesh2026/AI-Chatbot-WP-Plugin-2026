<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

$p = get_post(24869);
echo "=== POST 24869 ===\n";
echo "Title: " . $p->post_title . "\n";
echo "Slug: " . $p->post_name . "\n";
$meta = get_post_meta(24869);
foreach ($meta as $k => $v) {
    if ($k === '_elementor_data') {
        echo "Meta _elementor_data has billing? " . (stripos($v[0], 'billing') !== false ? 'YES' : 'NO') . "\n";
        if (stripos($v[0], 'billing') !== false) {
            echo "Matches:\n";
            preg_match_all('/.{0,100}billing.{0,100}/i', $v[0], $matches);
            print_r($matches[0]);
        }
    } else {
        echo "Meta $k: " . (is_string($v[0]) ? substr($v[0], 0, 100) : print_r($v[0], true)) . "\n";
    }
}

