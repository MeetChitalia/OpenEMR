<?php
require_once('interface/globals.php');

echo "Checking drugs table...\n";

$result = sqlQuery("SELECT COUNT(*) as count FROM drugs");
echo "Total drugs in database: " . $result['count'] . "\n";

if ($result['count'] > 0) {
    $drug = sqlQuery("SELECT drug_id, name FROM drugs LIMIT 1");
    echo "First drug - ID: " . $drug['drug_id'] . ", Name: " . $drug['name'] . "\n";
} else {
    echo "No drugs found in database!\n";
}
?> 