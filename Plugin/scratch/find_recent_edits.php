<?php
$scan_dirs = [
    'c:/xampp/htdocs/ids-energy.de/wp-content/plugins',
    'c:/xampp/htdocs/ids-energy.de/wp-content/themes',
    'c:/xampp/htdocs/ids-energy.de/wp-content/mu-plugins',
    'c:/xampp/htdocs/ids-energy.de',
];

$recent_files = [];
$cutoff = time() - (30 * 86400); // last 30 days

foreach ($scan_dirs as $sdir) {
    if (!is_dir($sdir)) continue;
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($sdir, FilesystemIterator::SKIP_DOTS),
            function ($current, $key, $iterator) {
                $p = str_replace('\\', '/', $current->getPathname());
                if ($current->isDir()) {
                    if (preg_match('#/(node_modules|cache|updraft|languages|uploads|\.git)/#i', $p . '/')) return false;
                }
                return true;
            }
        )
    );

    foreach ($it as $file) {
        if ($file->isDir()) continue;
        $mtime = $file->getMTime();
        if ($mtime >= $cutoff) {
            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['php', 'js', 'css', 'json', 'html', 'sql', 'htaccess'])) {
                $recent_files[] = [
                    'mtime' => $mtime,
                    'date' => date('Y-m-d H:i:s', $mtime),
                    'path' => $file->getPathname(),
                    'size' => $file->getSize()
                ];
            }
        }
    }
}

usort($recent_files, fn($a, $b) => $b['mtime'] <=> $a['mtime']);

echo "=== RECENTLY MODIFIED CODE FILES (Total: " . count($recent_files) . ") ===\n";
foreach (array_slice($recent_files, 0, 50) as $f) {
    echo "{$f['date']} | {$f['size']} bytes | {$f['path']}\n";
}

