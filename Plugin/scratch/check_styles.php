<?php
$html = file_get_contents('http://localhost/ids-energy.de/');

// Extract all <link rel="stylesheet">
preg_match_all('/<link[^>]+rel=[\'"]stylesheet[\'"][^>]*>/i', $html, $links);
echo "=== STYLESHEETS (" . count($links[0]) . ") ===\n";
foreach ($links[0] as $link) {
    if (preg_match('/href=[\'"]([^\'"]+)[\'"]/i', $link, $m)) {
        echo "  " . $m[1] . "\n";
    }
}

// Extract all <style> blocks
preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $html, $styles);
echo "\n=== INLINE STYLES (" . count($styles[0]) . ") ===\n";
foreach ($styles[1] as $idx => $s) {
    if (preg_match('/none|visibility|hidden|menu|nav|phone|contact|quote/i', $s)) {
        echo "--- Style #$idx contains keywords (length " . strlen($s) . ") ---\n";
        // Check for suspicious selectors
        preg_match_all('/([^{}]+)\{[^{}]*(?:display\s*:\s*none|visibility\s*:\s*hidden)[^{}]*\}/is', $s, $rules);
        if (!empty($rules[0])) {
            foreach ($rules[0] as $rule) {
                echo "    " . trim(preg_replace('/\s+/', ' ', substr($rule, 0, 200))) . "\n";
            }
        }
    }
}

