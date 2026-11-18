<?php
$dir = 'c:/xampp/htdocs/ids-energy.de/wp-content/plugins/Plugin';

$it = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        function ($current) {
            $p = str_replace('\\', '/', $current->getPathname());
            if ($current->isDir()) {
                if (preg_match('#/(node_modules|scratch|\.git)/#i', $p . '/')) return false;
            }
            return true;
        }
    )
);

$found = false;
foreach ($it as $file) {
    if ($file->isDir()) continue;
    $content = file_get_contents($file->getPathname());
    if (stripos($content, 'billing') !== false) {
        $found = true;
        echo "Found 'billing' in: " . $file->getPathname() . "\n";
        $lines = explode("\n", $content);
        foreach ($lines as $idx => $line) {
            if (stripos($line, 'billing') !== false) {
                echo "  Line " . ($idx + 1) . ": " . trim(substr($line, 0, 150)) . "\n";
            }
        }
    }
}

if (!$found) {
    echo "NO occurrences of 'billing' found in Plugin (excluding node_modules/scratch)!\n";
}

