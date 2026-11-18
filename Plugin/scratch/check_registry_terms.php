<?php
$data = json_decode(file_get_contents('c:/xampp/htdocs/ids-energy.de/wp-content/plugins/Plugin/data/url_registry.json'), true);

$terms = ['charg', 'solar', 'bill', 'photovoltaik', 'about', 'ueber', 'service', 'kontakt', 'contact', 'quote', 'angebot'];

foreach ($data['urls'] as $u) {
    foreach ($terms as $t) {
        if (stripos($u['title'], $t) !== false || stripos($u['slug'], $t) !== false || stripos($u['summary'], $t) !== false || stripos($u['keywords'], $t) !== false) {
            echo "[Matched: $t] ID {$u['id']} | Title: {$u['title']} | Slug: {$u['slug']} | URL: {$u['url']}\n";
            break;
        }
    }
}

