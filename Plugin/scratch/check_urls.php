<?php
$urls = [
    'Home' => 'http://localhost/ids-energy.de/',
    'Home EN' => 'http://localhost/ids-energy.de/en/',
    'Photovoltaik' => 'http://localhost/ids-energy.de/photovoltaik/',
    'E-Mobilität' => 'http://localhost/ids-energy.de/elektromobilitaet/',
    'E-Mobilität EN' => 'http://localhost/ids-energy.de/en/elektromobilitaet/',
    'Referenzen' => 'http://localhost/ids-energy.de/referenzen/',
    'Services' => 'http://localhost/ids-energy.de/services/',
    'Über uns' => 'http://localhost/ids-energy.de/ueber-uns/',
    'Kontakt' => 'http://localhost/ids-energy.de/kontakt/',
];

foreach ($urls as $name => $url) {
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $headers = @get_headers($url);
    $status = $headers ? $headers[0] : 'FAILED';
    echo "$name ($url) -> Status: $status\n";
}

