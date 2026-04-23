<?php
/**
 * Quick Weight Tables Creation Script
 * Creates the missing patient_goals table
 */

// Basic error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Creating Weight Tables</h1>";

// Include OpenEMR configuration
try {
    require_once("interface/globals.php");
    echo "<p style='color: green;'>✓ OpenEMR configuration loaded</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error loading OpenEMR: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

// Test database connection
try {
    $test_query = sqlQuery("SELECT 1 as test");
    if ($test_query['test'] == 1) {
        echo "<p style='color: green;'>✓ Database connection successful</p>";
    } else {
        throw new Exception("Database test failed");
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

// Create patient_goals table
echo "<h2>Creating patient_goals table...</h2>";

$sql = "
CREATE TABLE IF NOT EXISTS `patient_goals` (
    `id` bigint(20) NOT NULL AUTO_INCREMENT,
    `pid` bigint(20) NOT NULL,
    `goal_type` varchar(50) NOT NULL DEFAULT 'weight_loss',
    `goal_weight` decimal(8,2) NOT NULL,
    `target_date` date NOT NULL,
    `notes` text,
    `status` enum('active','achieved','inactive') NOT NULL DEFAULT 'active',
    `created_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pid` (`pid`),
    KEY `idx_goal_type` (`goal_type`),
    KEY `idx_status` (`status`),
    KEY `idx_target_date` (`target_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
";

try {
    sqlStatement($sql);
    echo "<p style='color: green;'>✓ patient_goals table created successfully</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error creating patient_goals table: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Verify table exists
try {
    $result = sqlQuery("SHOW TABLES LIKE 'patient_goals'");
    if ($result) {
        echo "<p style='color: green;'>✓ patient_goals table verified</p>";
    } else {
        echo "<p style='color: red;'>✗ patient_goals table not found</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error verifying table: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Test the query that was failing
echo "<h2>Testing the failing query...</h2>";

try {
    $test_query = "SELECT pg.*, p.fname, p.lname FROM patient_goals pg JOIN patient_data p ON pg.pid = p.pid WHERE pg.pid = '7' AND pg.goal_type = 'weight_loss' AND pg.status = 'active' ORDER BY pg.target_date ASC";
    $result = sqlStatement($test_query);
    echo "<p style='color: green;'>✓ Query executed successfully (no results found, which is expected for a new table)</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Query still failing: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>Setup Complete!</h2>";
echo "<p>You can now try accessing the weight goals page again.</p>";
echo "<p><a href='interface/reports/weight_goals.php'>Go to Weight Goals</a></p>";
?>
