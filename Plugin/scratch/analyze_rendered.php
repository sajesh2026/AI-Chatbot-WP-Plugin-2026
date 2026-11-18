<?php
function analyze_page($url, $label) {
    echo "=======================================================\n";
    echo "ANALYZING: $label ($url)\n";
    echo "=======================================================\n";
    $html = @file_get_contents($url);
    if (!$html) {
        echo "FAILED TO FETCH\n";
        return;
    }
    echo "HTML length: " . strlen($html) . " bytes\n";

    // 1. Check for tel: or phone number
    preg_match_all('/href=[\'"](tel:[^\'"]+)[\'"]/i', $html, $tel_matches);
    echo "Phone links (tel:): " . json_encode($tel_matches[1]) . "\n";
    preg_match_all('/489\s*78\s*323|0451/i', $html, $phone_texts);
    echo "Phone text occurrences: " . count($phone_texts[0]) . "\n";

    // 2. Check for Contact links
    preg_match_all('/<a[^>]+href=[\'"][^\'"]*kontakt[^\'"]*[\'"][^>]*>(.*?)<\/a>/is', $html, $contact_matches);
    echo "Contact links (count " . count($contact_matches[0]) . "):\n";
    foreach ($contact_matches[0] as $m) {
        echo "  - " . trim(strip_tags($m)) . " -> " . htmlspecialchars(substr($m, 0, 120)) . "\n";
    }

    // 3. Check for Quote links
    preg_match_all('/<a[^>]+href=[\'"][^\'"]*(?:angebot|quote|rechner)[^\'"]*[\'"][^>]*>(.*?)<\/a>/is', $html, $quote_matches);
    echo "Quote / Angebot links (count " . count($quote_matches[0]) . "):\n";
    foreach ($quote_matches[0] as $m) {
        echo "  - " . trim(strip_tags($m)) . " -> " . htmlspecialchars(substr($m, 0, 120)) . "\n";
    }

    // 4. Check for Nav Menu items
    preg_match_all('/<li[^>]*class=[\'"][^\'"]*menu-item[^\'"]*[\'"][^>]*>.*?<a[^>]+href=[\'"]([^\'"]+)[\'"][^>]*>(.*?)<\/a>/is', $html, $nav_matches);
    echo "Nav menu items (count " . count($nav_matches[0]) . "):\n";
    for ($i = 0; $i < min(20, count($nav_matches[0])); $i++) {
        echo "  * " . trim(strip_tags($nav_matches[2][$i])) . " => " . $nav_matches[1][$i] . "\n";
    }

    // 5. Check for 'billing' in HTML
    if (stripos($html, 'billing') !== false) {
        echo "⚠️ BILLING FOUND IN HTML!\n";
        preg_match_all('/.{0,80}billing.{0,80}/is', $html, $bill_matches);
        foreach (array_slice($bill_matches[0], 0, 5) as $bm) {
            echo "    [snippet]: " . htmlspecialchars(trim(preg_replace('/\s+/', ' ', $bm))) . "\n";
        }
    } else {
        echo "No 'billing' text found in HTML\n";
    }
}

analyze_page('http://localhost/ids-energy.de/', 'Home');
analyze_page('http://localhost/ids-energy.de/elektromobilitaet/', 'E-Mobilitaet (DE)');
analyze_page('http://localhost/ids-energy.de/en/elektromobilitaet/', 'Solar Car Charging (EN)');

