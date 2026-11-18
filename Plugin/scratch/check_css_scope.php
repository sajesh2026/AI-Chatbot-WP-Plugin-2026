<?php
$css = file_get_contents('c:/xampp/htdocs/ids-energy.de/wp-content/plugins/Plugin/public/css/chatbot-widget.css');

// Remove comments
$css_clean = preg_replace('/\/\*.*?\*\//s', '', $css);

// Match all selectors before {
preg_match_all('/([^{}]+)\{/s', $css_clean, $matches);
echo "=== SELECTORS NOT SCOPED TO .ai- ===\n";
foreach ($matches[1] as $sel_group) {
    $sel_group = trim($sel_group);
    if (strpos($sel_group, '@') === 0) continue; // media queries or keyframes
    $selectors = explode(',', $sel_group);
    foreach ($selectors as $sel) {
        $sel = trim($sel);
        if ($sel === '') continue;
        if (strpos($sel, '@') === 0) continue;
        // Check if selector starts with or contains .ai- or #ai- or :root
        if (!preg_match('/(\.ai-|#ai-|:root|@)/', $sel)) {
            echo "UNSCOPED SELECTOR: $sel\n";
        }
    }
}

