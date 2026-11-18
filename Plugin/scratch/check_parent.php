<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';
$p = get_post(24767);
echo 'Parent: ' . $p->post_parent . ' | Title: ' . $p->post_title . "\n";
$parent = get_post($p->post_parent);
echo 'Parent Title: ' . $parent->post_title . ' | Slug: ' . $parent->post_name . ' | Status: ' . $parent->post_status . "\n";

