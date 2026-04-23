<?php
/**
 * Simple Weight Reports System Setup Script
 * Run this script to set up the weight tracking and analytics system
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Basic error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Weight Reports System Setup</h1>";
echo "<p>Setting up weight tracking and analytics system...</p>";

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
echo "<h2>Creating Database Tables</h2>";

$sql1 = "
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
    sqlStatement($sql1);
    echo "<p style='color: green;'>✓ patient_goals table created successfully</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error creating patient_goals table: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Create weight_analytics_cache table
$sql2 = "
CREATE TABLE IF NOT EXISTS `weight_analytics_cache` (
    `id` bigint(20) NOT NULL AUTO_INCREMENT,
    `cache_key` varchar(255) NOT NULL,
    `cache_data` longtext NOT NULL,
    `created_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_date` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_cache_key` (`cache_key`),
    KEY `idx_expires_date` (`expires_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
";

try {
    sqlStatement($sql2);
    echo "<p style='color: green;'>✓ weight_analytics_cache table created successfully</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error creating weight_analytics_cache table: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Insert default report configurations
echo "<h2>Adding Configuration Data</h2>";

$sql3 = "
INSERT IGNORE INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `activity`) VALUES
('weight_report_types', 'weight_loss_summary', 'Weight Loss Summary', 10, 1, 1),
('weight_report_types', 'patient_weight_history', 'Patient Weight History', 20, 0, 1),
('weight_report_types', 'weight_trends', 'Weight Trends Analysis', 30, 0, 1),
('weight_report_types', 'comprehensive_analysis', 'Comprehensive Analysis', 40, 0, 1),
('weight_report_types', 'treatment_correlation', 'Treatment Correlation', 50, 0, 1);
";

try {
    sqlStatement($sql3);
    echo "<p style='color: green;'>✓ Weight report types added</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error adding weight report types: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Add weight units list
$sql4 = "
INSERT IGNORE INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `activity`) VALUES
('weight_units', 'lbs', 'Pounds (lbs)', 10, 1, 1),
('weight_units', 'kg', 'Kilograms (kg)', 20, 0, 1);
";

try {
    sqlStatement($sql4);
    echo "<p style='color: green;'>✓ Weight units added</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error adding weight units: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Insert default settings
$sql5 = "
INSERT IGNORE INTO `globals` (`gl_name`, `gl_value`, `gl_description`) VALUES
('weight_tracking_enabled', '1', 'Enable weight tracking and analytics'),
('weight_tracking_cache_duration', '3600', 'Weight analytics cache duration in seconds'),
('weight_tracking_auto_goals', '0', 'Automatically create weight loss goals for new patients'),
('weight_tracking_goal_reminders', '1', 'Enable goal achievement reminders');
";

try {
    sqlStatement($sql5);
    echo "<p style='color: green;'>✓ Default settings added</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error adding default settings: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Create weight tracking view
echo "<h2>Creating Database Views</h2>";

$sql6 = "
CREATE OR REPLACE VIEW `weight_tracking_summary` AS
SELECT 
    p.pid,
    p.fname,
    p.lname,
    p.DOB,
    p.sex,
    MIN(fv.date) as first_weigh_in,
    MAX(fv.date) as last_weigh_in,
    COUNT(DISTINCT fv.date) as total_weigh_ins,
    MIN(fv.weight) as min_weight,
    MAX(fv.weight) as max_weight,
    AVG(fv.weight) as avg_weight,
    (SELECT fv2.weight FROM form_vitals fv2 
     WHERE fv2.pid = p.pid AND fv2.weight > 0 
     ORDER BY fv2.date ASC LIMIT 1) as starting_weight,
    (SELECT fv3.weight FROM form_vitals fv3 
     WHERE fv3.pid = p.pid AND fv3.weight > 0 
     ORDER BY fv3.date DESC LIMIT 1) as current_weight,
    DATEDIFF(MAX(fv.date), MIN(fv.date)) as days_tracked
FROM patient_data p
JOIN form_vitals fv ON p.pid = fv.pid
WHERE fv.weight > 0
GROUP BY p.pid, p.fname, p.lname, p.DOB, p.sex
HAVING total_weigh_ins > 1;
";

try {
    sqlStatement($sql6);
    echo "<p style='color: green;'>✓ Weight tracking view created</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error creating weight tracking view: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Create indexes for better performance
echo "<h2>Creating Performance Indexes</h2>";

$index_queries = [
    'Adding weight tracking indexes to form_vitals...' => "ALTER TABLE `form_vitals` ADD INDEX IF NOT EXISTS `idx_weight_tracking` (`pid`, `date`, `weight`)",
    'Adding weight analysis indexes...' => "ALTER TABLE `form_vitals` ADD INDEX IF NOT EXISTS `idx_weight_analysis` (`weight`, `date`)",
    'Adding composite indexes to patient_goals...' => "CREATE INDEX IF NOT EXISTS `idx_patient_goals_composite` ON `patient_goals` (`pid`, `goal_type`, `status`)"
];

foreach ($index_queries as $description => $sql) {
    echo "<p>$description</p>";
    try {
        sqlStatement($sql);
        echo "<p style='color: green;'>✓ Success</p>";
    } catch (Exception $e) {
        echo "<p style='color: orange;'>⚠ Warning: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// Verify setup
echo "<h2>Setup Verification</h2>";

$verification_queries = [
    'patient_goals table' => "SELECT COUNT(*) as count FROM information_schema.tables WHERE table_name = 'patient_goals' AND table_schema = DATABASE()",
    'weight_analytics_cache table' => "SELECT COUNT(*) as count FROM information_schema.tables WHERE table_name = 'weight_analytics_cache' AND table_schema = DATABASE()",
    'weight_tracking_summary view' => "SELECT COUNT(*) as count FROM information_schema.views WHERE table_name = 'weight_tracking_summary' AND table_schema = DATABASE()",
    'weight report types' => "SELECT COUNT(*) as count FROM list_options WHERE list_id = 'weight_report_types'",
    'weight units' => "SELECT COUNT(*) as count FROM list_options WHERE list_id = 'weight_units'",
    'global settings' => "SELECT COUNT(*) as count FROM globals WHERE gl_name LIKE 'weight_tracking_%'"
];

foreach ($verification_queries as $item => $sql) {
    try {
        $result = sqlQuery($sql);
        $count = $result['count'];
        if ($count > 0) {
            echo "<p style='color: green;'>✓ $item: $count items found</p>";
        } else {
            echo "<p style='color: red;'>✗ $item: Not found</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>✗ $item: Error checking - " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

echo "<h2>Setup Complete!</h2>";
echo "<p><strong>Weight Reports System has been successfully installed.</strong></p>";
echo "<p>You can now access the weight tracking reports at:</p>";
echo "<ul>";
echo "<li><a href='interface/reports/weight_reports_navigation.php'>Weight Reports Navigation</a></li>";
echo "<li><a href='interface/reports/weight_reports.php'>Basic Weight Reports</a></li>";
echo "<li><a href='interface/reports/weight_analytics.php'>Advanced Weight Analytics</a></li>";
echo "<li><a href='interface/reports/weight_goals.php'>Weight Goals Tracking</a></li>";
echo "</ul>";

echo "<h3>Features Available:</h3>";
echo "<ul>";
echo "<li>✓ Weight loss tracking and summary reports</li>";
echo "<li>✓ Patient-specific weight history</li>";
echo "<li>✓ BMI tracking and analysis</li>";
echo "<li>✓ Advanced analytics with trends</li>";
echo "<li>✓ Weight loss goal setting and tracking</li>";
echo "<li>✓ Progress visualization</li>";
echo "<li>✓ CSV export functionality</li>";
echo "<li>✓ Date range filtering</li>";
echo "<li>✓ Patient filtering options</li>";
echo "<li>✓ Treatment correlation analysis</li>";
echo "</ul>";

echo "<p><strong>Next Steps:</strong></p>";
echo "<ol>";
echo "<li>Start by setting up weight loss goals for your patients</li>";
echo "<li>Review existing weight data in the Basic Weight Reports</li>";
echo "<li>Use Advanced Analytics for deeper insights</li>";
echo "<li>Integrate with your existing treatment protocols</li>";
echo "</ol>";

echo "<hr>";
echo "<p><em>Setup completed on " . date('Y-m-d H:i:s') . "</em></p>";

// Delete this setup file for security
echo "<p><strong>Security Note:</strong> Please delete this setup file after successful installation for security purposes.</p>";
echo "<p><a href='?delete_setup=1' onclick='return confirm(\"Are you sure you want to delete the setup file?\")'>Delete Setup File</a></p>";

// Handle file deletion
if (isset($_GET['delete_setup']) && $_GET['delete_setup'] == 1) {
    if (unlink(__FILE__)) {
        echo "<p style='color: green;'>Setup file deleted successfully. Redirecting...</p>";
        echo "<script>setTimeout(function(){ window.location.href='interface/reports/weight_reports_navigation.php'; }, 2000);</script>";
    } else {
        echo "<p style='color: red;'>Could not delete setup file. Please delete it manually.</p>";
    }
}
?>
