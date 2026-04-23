<?php
/**
 * Fix Weight Tracking Issues
 * Creates missing database table and fixes common issues
 */

require_once("interface/globals.php");

echo "<h1>Fixing Weight Tracking Issues</h1>";

// Test database connection
try {
    $test_query = sqlQuery("SELECT 1 as test");
    echo "<p style='color: green;'>✓ Database connection successful</p>";
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

// Test the problematic query
echo "<h2>Testing weight goals query...</h2>";

try {
    $test_query = "SELECT COUNT(*) as count FROM patient_goals WHERE pid = '7' AND goal_type = 'weight_loss' AND status = 'active'";
    $result = sqlQuery($test_query);
    echo "<p style='color: green;'>✓ Weight goals query works (found " . $result['count'] . " goals for patient 7)</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Query still failing: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>Fix Complete!</h2>";
echo "<p>Now try accessing the weight tracking page again:</p>";
echo "<p><a href='interface/reports/weight_tracking.php'>Go to Weight Tracking</a></p>";

// Clean up this file
echo "<p><strong>Security Note:</strong> This fix file will be deleted after successful completion.</p>";
echo "<p><a href='?delete_fix=1' onclick='return confirm(\"Are you sure you want to delete this fix file?\")'>Delete Fix File</a></p>";

if (isset($_GET['delete_fix']) && $_GET['delete_fix'] == 1) {
    if (unlink(__FILE__)) {
        echo "<p style='color: green;'>Fix file deleted successfully.</p>";
        echo "<script>setTimeout(function(){ window.location.href='interface/reports/weight_tracking.php'; }, 2000);</script>";
    } else {
        echo "<p style='color: red;'>Could not delete fix file. Please delete it manually.</p>";
    }
}
?>
