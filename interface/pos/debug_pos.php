<?php
/**
 * Debug POS Modal Issues
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>POS Modal Debug</h1>";
echo "<p>Current time: " . date('Y-m-d H:i:s') . "</p>";
echo "<p>PHP version: " . phpversion() . "</p>";

// Test 1: Basic PHP
echo "<h2>Test 1: Basic PHP</h2>";
echo "✅ PHP is working<br>";

// Test 2: File paths
echo "<h2>Test 2: File Paths</h2>";
echo "Current directory: " . __DIR__ . "<br>";
echo "Parent directory: " . dirname(__DIR__) . "<br>";

// Test 3: Check if globals.php exists
echo "<h2>Test 3: Check Dependencies</h2>";
$globals_path = __DIR__ . "/../globals.php";
if (file_exists($globals_path)) {
    echo "✅ globals.php exists<br>";
} else {
    echo "❌ globals.php not found at: $globals_path<br>";
}

$patient_inc_path = dirname(__DIR__) . "/library/patient.inc";
if (file_exists($patient_inc_path)) {
    echo "✅ patient.inc exists<br>";
} else {
    echo "❌ patient.inc not found at: $patient_inc_path<br>";
}

// Test 4: Try to include globals.php
echo "<h2>Test 4: Include globals.php</h2>";
try {
    require_once($globals_path);
    echo "✅ globals.php included successfully<br>";
} catch (Exception $e) {
    echo "❌ Error including globals.php: " . $e->getMessage() . "<br>";
} catch (Error $e) {
    echo "❌ Fatal error including globals.php: " . $e->getMessage() . "<br>";
}

// Test 5: Check session
echo "<h2>Test 5: Session Check</h2>";
if (session_status() === PHP_SESSION_NONE) {
    echo "Session not started<br>";
} else {
    echo "Session is active<br>";
}

// Test 6: Check if user is logged in
echo "<h2>Test 6: User Authentication</h2>";
if (isset($_SESSION['authUserID'])) {
    echo "✅ User is logged in: " . ($_SESSION['authUser'] ?? 'Unknown') . "<br>";
    echo "User ID: " . ($_SESSION['authUserID'] ?? 'Unknown') . "<br>";
} else {
    echo "❌ User is not logged in<br>";
}

// Test 7: Database connection
echo "<h2>Test 7: Database Connection</h2>";
try {
    $test_query = "SELECT 1 as test";
    $result = sqlQuery($test_query);
    echo "✅ Database connection working<br>";
} catch (Exception $e) {
    echo "❌ Database connection error: " . $e->getMessage() . "<br>";
}

echo "<h2>Test Complete</h2>";
echo "<p>If you see this page, the basic PHP and web server are working.</p>";
?>
