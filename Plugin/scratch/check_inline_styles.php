<?php
$html = file_get_contents('http://localhost/ids-energy.de/');
preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $html, $styles);
foreach ([8, 9, 10, 11, 13, 14] as $idx) {
    if (isset($styles[0][$idx])) {
        echo "=== STYLE #$idx ===\n";
        echo substr($styles[0][$idx], 0, 1000) . "\n\n";
    }
}

