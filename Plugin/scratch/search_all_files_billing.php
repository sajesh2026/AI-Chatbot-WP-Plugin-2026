<?php
$dir = 'c:/xampp/htdocs/ids-energy.de/wp-content';

$it = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        function ($current) {
            $p = str_replace('\\', '/', $current->getPathname());
            if ($current->isDir()) {
                if (preg_match('#/(node_modules|cache|updraft|\.git|languages|uploads)/#i', $p . '/')) return false;
            }
            return true;
        }
    )
);

$matches = [];
foreach ($it as $file) {
    if ($file->isDir()) continue;
    $ext = strtolower($file->getExtension());
    if (!in_array($ext, ['php', 'js', 'css', 'json'])) continue;
    $content = file_get_contents($file->getPathname());
    if (stripos($content, 'billing') !== false) {
        $p = $file->getPathname();
        echo "Found in: $p\n";
        $lines = explode("\n", $content);
        foreach ($lines as $idx => $line) {
            if (stripos($line, 'billing') !== false) {
                echo "  L" . ($idx + 1) . ": " . trim(substr($line, 0, 120)) . "\n";
            }
        }
    }
}

