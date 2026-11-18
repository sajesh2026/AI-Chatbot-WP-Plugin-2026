<?php
$js = file_get_contents('c:/xampp/htdocs/ids-energy.de/wp-content/plugins/Plugin/public/js/chatbot-widget.js');

preg_match_all('/document\.(?:querySelector|querySelectorAll|getElementById|getElementsByClassName|getElementsByTagName)\([\'"]([^\'"]+)[\'"]\)/', $js, $matches);
echo "=== DOM SELECTORS QUERIED IN CHATBOT-WIDGET.JS ===\n";
print_r(array_unique($matches[1]));

preg_match_all('/\$\([\'"]([^\'"]+)[\'"]\)/', $js, $jq_matches);
echo "=== JQUERY SELECTORS ===\n";
print_r(array_unique($jq_matches[1]));

