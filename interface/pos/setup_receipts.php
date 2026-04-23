<?php
/**
 * Setup script for POS Receipts table
 */

require_once(__DIR__ . "/../globals.php");

echo "<h2>Setting up POS Receipts Table</h2>";

// Create the table
$create_sql = "CREATE TABLE IF NOT EXISTS `pos_receipts` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `receipt_number` varchar(50) NOT NULL,
    `pid` int(11) NOT NULL,
    `amount` decimal(10,2) NOT NULL,
    `payment_method` varchar(20) NOT NULL,
    `transaction_id` varchar(100) NOT NULL,
    `receipt_data` longtext NOT NULL,
    `created_by` varchar(50) NOT NULL,
    `created_date` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `receipt_number` (`receipt_number`),
    KEY `pid` (`pid`),
    KEY `created_date` (`created_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

try {
    $result = sqlStatement($create_sql);
    if ($result !== false) {
        echo "<p style='color: green;'>✅ POS Receipts table created successfully!</p>";
        
        // Verify table exists
        $table_exists = sqlQuery("SHOW TABLES LIKE 'pos_receipts'");
        if ($table_exists) {
            echo "<p style='color: green;'>✅ Table verification successful</p>";
        } else {
            echo "<p style='color: red;'>❌ Table verification failed</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ Failed to create table</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}

echo "<p><a href='pos_modal.php'>Return to POS</a></p>";
?> 