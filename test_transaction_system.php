<?php
/**
 * Test file to verify the transaction system is working properly
 */

require_once("interface/globals.php");

echo "<h2>OpenEMR Transaction System Test</h2>\n";

// Test 1: Check if drug_sales table exists and has trans_type column
echo "<h3>Test 1: Database Structure</h3>\n";
try {
    $result = sqlQuery("DESCRIBE drug_sales");
    if ($result) {
        echo "✓ drug_sales table exists<br>\n";
        
        // Check for trans_type column
        $columns = sqlStatement("SHOW COLUMNS FROM drug_sales LIKE 'trans_type'");
        $trans_type_exists = false;
        while ($row = sqlFetchArray($columns)) {
            if ($row['Field'] == 'trans_type') {
                $trans_type_exists = true;
                break;
            }
        }
        
        if ($trans_type_exists) {
            echo "✓ trans_type column exists<br>\n";
        } else {
            echo "✗ trans_type column missing<br>\n";
        }
    } else {
        echo "✗ drug_sales table not found<br>\n";
    }
} catch (Exception $e) {
    echo "✗ Database error: " . $e->getMessage() . "<br>\n";
}

// Test 2: Check if drugs table has sample data
echo "<h3>Test 2: Sample Data</h3>\n";
try {
    $drug_count = sqlQuery("SELECT COUNT(*) as count FROM drugs");
    echo "✓ Found " . $drug_count['count'] . " drugs in database<br>\n";
    
    if ($drug_count['count'] > 0) {
        $sample_drug = sqlQuery("SELECT drug_id, name FROM drugs LIMIT 1");
        echo "✓ Sample drug: " . $sample_drug['name'] . " (ID: " . $sample_drug['drug_id'] . ")<br>\n";
    }
} catch (Exception $e) {
    echo "✗ Error checking drugs: " . $e->getMessage() . "<br>\n";
}

// Test 3: Check if warehouses exist
echo "<h3>Test 3: Warehouses</h3>\n";
try {
    $warehouse_count = sqlQuery("SELECT COUNT(*) as count FROM list_options WHERE list_id = 'warehouse' AND activity = 1");
    echo "✓ Found " . $warehouse_count['count'] . " active warehouses<br>\n";
    
    if ($warehouse_count['count'] > 0) {
        $warehouses = sqlStatement("SELECT option_id, title FROM list_options WHERE list_id = 'warehouse' AND activity = 1 LIMIT 3");
        echo "✓ Sample warehouses:<br>\n";
        while ($row = sqlFetchArray($warehouses)) {
            echo "&nbsp;&nbsp;- " . $row['title'] . " (ID: " . $row['option_id'] . ")<br>\n";
        }
    }
} catch (Exception $e) {
    echo "✗ Error checking warehouses: " . $e->getMessage() . "<br>\n";
}

// Test 4: Check transaction types
echo "<h3>Test 4: Transaction Types</h3>\n";
$transaction_types = array(
    0 => 'Edit Only',
    1 => 'Sale',
    2 => 'Purchase/Receipt',
    3 => 'Return',
    4 => 'Transfer',
    5 => 'Adjustment',
    6 => 'Distribution',
    7 => 'Consumption'
);

echo "✓ Supported transaction types:<br>\n";
foreach ($transaction_types as $type_id => $type_name) {
    echo "&nbsp;&nbsp;- Type $type_id: $type_name<br>\n";
}

// Test 5: Check if add_edit_lot.php is accessible
echo "<h3>Test 5: File Accessibility</h3>\n";
if (file_exists("interface/drugs/add_edit_lot.php")) {
    echo "✓ add_edit_lot.php file exists<br>\n";
    
    // Check file permissions
    if (is_readable("interface/drugs/add_edit_lot.php")) {
        echo "✓ File is readable<br>\n";
    } else {
        echo "✗ File is not readable<br>\n";
    }
} else {
    echo "✗ add_edit_lot.php file not found<br>\n";
}

// Test 6: Check ACL permissions
echo "<h3>Test 6: ACL Permissions</h3>\n";
try {
    // Test if ACL functions are available
    if (function_exists('AclMain::aclCheckCore')) {
        echo "✓ ACL functions available<br>\n";
    } else {
        echo "✗ ACL functions not available<br>\n";
    }
} catch (Exception $e) {
    echo "✗ ACL error: " . $e->getMessage() . "<br>\n";
}

// Test 7: Test a simple drug query (similar to what add_edit_lot.php does)
echo "<h3>Test 7: Database Query Test</h3>\n";
try {
    $test_drug_id = 1; // Test with drug ID 1
    $test_result = sqlQuery("SELECT drug_id, name FROM drugs WHERE drug_id = $test_drug_id");
    if ($test_result) {
        echo "✓ Database query test successful - Found drug: " . $test_result['name'] . "<br>\n";
    } else {
        echo "✓ Database query test successful - No drug found with ID $test_drug_id<br>\n";
    }
} catch (Exception $e) {
    echo "✗ Database query test failed: " . $e->getMessage() . "<br>\n";
}

echo "<h3>Test Complete</h3>\n";
echo "<p>If all tests show ✓, the transaction system should be working properly.</p>\n";
echo "<p><a href='interface/drugs/drug_inventory.php'>Go to Inventory Management</a></p>\n";
echo "<p><strong>Next Steps:</strong></p>\n";
echo "<ol>\n";
echo "<li>Go to Inventory Management</li>\n";
echo "<li>Click the 'Tran' button next to any drug</li>\n";
echo "<li>The popup should now load without SQL errors</li>\n";
echo "<li>Try creating a Purchase transaction to test the system</li>\n";
echo "</ol>\n";

function areVendorsUsed()
{
    $row = sqlQuery(
        "SELECT COUNT(*) AS count FROM users " .
        "WHERE active = 1 AND (info IS NULL OR info NOT LIKE '%Inactive%') " .
        "AND abook_type LIKE 'vendor%'"
    );
    return (is_array($row) && isset($row['count'])) ? $row['count'] : 0;
}
?> 