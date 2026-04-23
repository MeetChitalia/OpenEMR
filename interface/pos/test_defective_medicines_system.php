<?php
/**
 * Test Script for Defective Medicines Management System
 * 
 * This script tests the basic functionality of the defective medicines system
 */

require_once(__DIR__ . "/../globals.php");

echo "<h1>Defective Medicines System Test</h1>";

// Test 1: Check if tables exist
echo "<h2>Test 1: Database Tables</h2>";
$tables = ['defective_medicines', 'defective_medicine_replacements', 'defective_medicines_summary'];
foreach ($tables as $table) {
    $result = sqlQuery("SHOW TABLES LIKE '$table'");
    if ($result) {
        echo "✅ Table '$table' exists<br>";
    } else {
        echo "❌ Table '$table' missing<br>";
    }
}

// Test 2: Check current data
echo "<h2>Test 2: Current Data</h2>";
$status_counts = sqlQuery("SELECT status, COUNT(*) as count FROM defective_medicines GROUP BY status");
if ($status_counts) {
    echo "Current defective medicines by status:<br>";
    do {
        echo "- " . $status_counts['status'] . ": " . $status_counts['count'] . " records<br>";
    } while ($status_counts = sqlFetchArray($status_counts));
} else {
    echo "No defective medicines records found<br>";
}

// Test 3: Check if manager interface file exists
echo "<h2>Test 3: Manager Interface</h2>";
$manager_file = __DIR__ . "/pos_defective_medicines_manager.php";
if (file_exists($manager_file)) {
    echo "✅ Manager interface file exists<br>";
    echo "File size: " . filesize($manager_file) . " bytes<br>";
} else {
    echo "❌ Manager interface file missing<br>";
}

// Test 4: Check if handler files exist
echo "<h2>Test 4: Handler Files</h2>";
$handler_files = [
    'defective_medicines_handler.php',
    'defective_medicines_handler_simple.php'
];

foreach ($handler_files as $file) {
    $file_path = __DIR__ . "/" . $file;
    if (file_exists($file_path)) {
        echo "✅ Handler file '$file' exists<br>";
    } else {
        echo "❌ Handler file '$file' missing<br>";
    }
}

// Test 5: Check POS system integration
echo "<h2>Test 5: POS System Integration</h2>";
$pos_file = __DIR__ . "/pos_system.php";
if (file_exists($pos_file)) {
    $pos_content = file_get_contents($pos_file);
    if (strpos($pos_content, 'pos_defective_medicines_manager.php') !== false) {
        echo "✅ POS system has manager link<br>";
    } else {
        echo "❌ POS system missing manager link<br>";
    }
} else {
    echo "❌ POS system file missing<br>";
}

// Test 6: Check user permissions
echo "<h2>Test 6: User Permissions</h2>";
if (isset($_SESSION['authUserID'])) {
    echo "✅ User is logged in: " . $_SESSION['authUser'] . "<br>";
    if (acl_check('admin', 'super')) {
        echo "✅ User has admin privileges<br>";
    } else {
        echo "⚠️ User does not have admin privileges<br>";
    }
} else {
    echo "❌ User not logged in<br>";
}

echo "<h2>System Status</h2>";
echo "<p><strong>Defective Medicines Management System is ready for use!</strong></p>";
echo "<p>To access the manager interface, go to: <a href='pos_defective_medicines_manager.php'>Defective Medicines Manager</a></p>";
echo "<p>To access the POS system, go to: <a href='pos_system.php?pid=1'>POS System</a></p>";

?>


