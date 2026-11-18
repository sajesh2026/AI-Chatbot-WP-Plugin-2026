<?php
$dirs = [
    'c:/xampp/htdocs/ids-energy.de/wp-content/themes',
    'c:/xampp/htdocs/ids-energy.de/wp-content/plugins'
];

foreach ($dirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isDir()) continue;
        $path = $file->getPathname();
        if (preg_match('#/(node_modules|cache|updraft|languages|woocommerce|fluentform|elementor|wordpress-seo)/#i', str_replace('\\', '/', $path))) continue;
        if (!preg_match('/\.(php|js|css|json)$/i', $path)) continue;
        
        $content = file_get_contents($path);
        if (stripos($content, 'billing') !== false) {
            echo "Found in $path\n";
            $lines = explode("\n", $content);
            foreach ($lines as $num => $line) {
                if (stripos($line, 'billing') !== false) {
                    echo "  Line " . ($num + 1) . ": " . trim(substr($line, 0, 150)) . "\n";
                }
            }
        }
    }
}

