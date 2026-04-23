<?php
// Fix the path to globals.php - it's in the interface directory
$globals_path = dirname(__DIR__) . '/globals.php';
if (!file_exists($globals_path)) {
    die("Error: globals.php not found at: $globals_path");
}
require_once($globals_path);

echo "<h1>Database Tables Test</h1>";

// Check if tables exist
$tables_to_check = array('product_categories', 'product_category_mapping');

foreach ($tables_to_check as $table) {
    $res = sqlStatement("SHOW TABLES LIKE '$table'");
    if (sqlNumRows($res) > 0) {
        echo "<p style='color: green;'>✅ Table '$table' exists</p>";
        
        // Count rows
        $count_res = sqlStatement("SELECT COUNT(*) as count FROM $table");
        $count_row = sqlFetchArray($count_res);
        echo "<p>Rows in $table: " . $count_row['count'] . "</p>";
    } else {
        echo "<p style='color: red;'>❌ Table '$table' does NOT exist</p>";
    }
}

// Test basic queries
echo "<h2>Testing Basic Queries</h2>";

try {
    $res = sqlStatement("SELECT * FROM product_categories LIMIT 5");
    echo "<p style='color: green;'>✅ product_categories query works</p>";
    while ($row = sqlFetchArray($res)) {
        echo "<p>- " . $row['category_name'] . "</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ product_categories query failed: " . $e->getMessage() . "</p>";
}

try {
    $res = sqlStatement("SELECT * FROM product_category_mapping LIMIT 5");
    echo "<p style='color: green;'>✅ product_category_mapping query works</p>";
    while ($row = sqlFetchArray($res)) {
        echo "<p>- " . $row['product_name'] . " (Category: " . $row['category_name'] . ")</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ product_category_mapping query failed: " . $e->getMessage() . "</p>";
}

echo "<h2>Next Steps</h2>";
echo "<p>If tables don't exist, run: <code>source create_product_categories.sql</code> in your MySQL database</p>";
echo "<p><a href='manage_categories.php'>Try the main interface again</a></p>";
?> 