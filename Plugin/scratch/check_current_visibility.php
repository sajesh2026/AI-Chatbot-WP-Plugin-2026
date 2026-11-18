<?php
define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 4) . '/wp-load.php';

$settings = \AIChatbot\Settings::get_all(1);
echo "=== VISIBILITY SETTINGS ===\n";
print_r($settings['visibility'] ?? []);

echo "\n=== GUARDRAILS SETTINGS ===\n";
print_r($settings['guardrails'] ?? []);

