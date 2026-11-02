<?php
$file = 'C:/xampp/apache/conf/extra/httpd-xampp.conf';
$content = file_get_contents($file);

$needle = '<Directory "C:/xampp/htdocs/xampp/dhaba-support-assistant/public">';
$addition = <<<EOF

<Directory "C:/xampp/htdocs/xampp/dhaba-support-ai">
    Require all granted
    AllowOverride All
</Directory>

<Directory "C:/xampp/htdocs/xampp/dhaba-support-ai/public">
    Require all granted
    AllowOverride All
</Directory>

EOF;

if (strpos($content, 'dhaba-support-ai') === false) {
    $content = str_replace($needle, $addition . $needle, $content);
    file_put_contents($file, $content);
    echo "Config updated successfully.\n";
} else {
    echo "Already configured.\n";
}

