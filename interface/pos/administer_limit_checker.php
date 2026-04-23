<?php
/**
 * Daily Administration Limit Checker
 * 
 * This file handles checking and enforcing daily administration limits
 * to ensure patient safety by limiting medication administration to 2 doses per day
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Team
 * @copyright Copyright (c) 2024 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

/**
 * Check if a patient can administer a specific medication today
 * 
 * @param int $pid Patient ID
 * @param int $drug_id Drug ID
 * @param string $lot_number Lot number
 * @param int $requested_quantity Quantity requested to administer
 * @return array ['can_administer' => bool, 'current_total' => int, 'remaining_allowed' => int, 'error' => string]
 */
function checkDailyAdministerLimit($pid, $drug_id, $lot_number, $requested_quantity = 0) {
    // Ensure table exists
    createDailyAdministerTrackingTable();
    
    $today = date('Y-m-d');
    $max_daily_limit = 2; // Maximum 2 doses per day per medication
    
    // Get current total administered today for this patient and drug
    $sql = "SELECT total_administered FROM daily_administer_tracking 
            WHERE pid = ? AND drug_id = ? AND lot_number = ? AND administer_date = ?";
    
    $result = sqlFetchArray(sqlStatement($sql, array($pid, $drug_id, $lot_number, $today)));
    $current_total = $result ? intval($result['total_administered']) : 0;
    
    // Calculate remaining allowed
    $remaining_allowed = $max_daily_limit - $current_total;
    
    // Check if requested quantity would exceed limit
    $can_administer = ($current_total + $requested_quantity) <= $max_daily_limit;
    
    $error_message = '';
    if (!$can_administer) {
        $error_message = "Daily administration limit exceeded. Patient has already received {$current_total} dose(s) today. Maximum allowed: {$max_daily_limit} dose(s) per day.";
    }
    
    return [
        'can_administer' => $can_administer,
        'current_total' => $current_total,
        'remaining_allowed' => $remaining_allowed,
        'error' => $error_message
    ];
}

/**
 * Record administration in the daily tracking table
 * 
 * @param int $pid Patient ID
 * @param int $drug_id Drug ID
 * @param string $lot_number Lot number
 * @param int $administered_quantity Quantity administered
 * @return bool Success status
 */
function recordDailyAdminister($pid, $drug_id, $lot_number, $administered_quantity) {
    // Ensure table exists
    createDailyAdministerTrackingTable();
    
    $today = date('Y-m-d');
    
    // Check if record exists for today
    $check_sql = "SELECT id, total_administered FROM daily_administer_tracking 
                  WHERE pid = ? AND drug_id = ? AND lot_number = ? AND administer_date = ?";
    
    $existing = sqlFetchArray(sqlStatement($check_sql, array($pid, $drug_id, $lot_number, $today)));
    
    if ($existing) {
        // Update existing record
        $new_total = $existing['total_administered'] + $administered_quantity;
        $update_sql = "UPDATE daily_administer_tracking 
                       SET total_administered = ?, updated_date = NOW() 
                       WHERE id = ?";
        
        $result = sqlStatement($update_sql, array($new_total, $existing['id']));
    } else {
        // Insert new record
        $insert_sql = "INSERT INTO daily_administer_tracking 
                       (pid, drug_id, lot_number, administer_date, total_administered) 
                       VALUES (?, ?, ?, ?, ?)";
        
        $result = sqlStatement($insert_sql, array($pid, $drug_id, $lot_number, $today, $administered_quantity));
    }
    
    return $result !== false;
}

/**
 * Get daily administration summary for a patient
 * 
 * @param int $pid Patient ID
 * @param string $date Date in Y-m-d format (defaults to today)
 * @return array Array of administration records
 */
function getDailyAdministerSummary($pid, $date = null) {
    if ($date === null) {
        $date = date('Y-m-d');
    }
    
    // Ensure table exists
    createDailyAdministerTrackingTable();
    
    $sql = "SELECT dt.*, d.name as drug_name 
            FROM daily_administer_tracking dt
            LEFT JOIN drugs d ON dt.drug_id = d.drug_id
            WHERE dt.pid = ? AND dt.administer_date = ? AND dt.total_administered > 0
            ORDER BY dt.drug_id, dt.lot_number";
    
    $result = sqlStatement($sql, array($pid, $date));
    $records = array();
    
    while ($row = sqlFetchArray($result)) {
        $records[] = $row;
    }
    
    return $records;
}

/**
 * Create the daily administer tracking table if it doesn't exist
 */
function createDailyAdministerTrackingTable() {
    $table_exists = sqlQuery("SHOW TABLES LIKE 'daily_administer_tracking'");
    
    if (!$table_exists) {
        $create_sql = "CREATE TABLE IF NOT EXISTS `daily_administer_tracking` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `pid` int(11) NOT NULL,
            `drug_id` int(11) NOT NULL,
            `lot_number` varchar(50) NOT NULL,
            `administer_date` date NOT NULL,
            `total_administered` int(11) NOT NULL DEFAULT 0,
            `created_date` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_date` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_daily_administer` (`pid`, `drug_id`, `lot_number`, `administer_date`),
            KEY `idx_patient_date` (`pid`, `administer_date`),
            KEY `idx_drug_date` (`drug_id`, `administer_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        sqlStatement($create_sql);
        
        // Add index for better performance
        $index_sql = "CREATE INDEX IF NOT EXISTS `idx_daily_administer_lookup` ON `daily_administer_tracking` (`pid`, `drug_id`, `administer_date`)";
        sqlStatement($index_sql);
    }
}

/**
 * AJAX handler for checking administration limits
 */
if ($_POST['action'] === 'check_administer_limit') {
    $pid = intval($_POST['pid'] ?? 0);
    $drug_id = intval($_POST['drug_id'] ?? 0);
    $lot_number = $_POST['lot_number'] ?? '';
    $requested_quantity = intval($_POST['requested_quantity'] ?? 0);
    
    if (!$pid || !$drug_id || !$lot_number) {
        echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
        exit;
    }
    
    $result = checkDailyAdministerLimit($pid, $drug_id, $lot_number, $requested_quantity);
    
    echo json_encode([
        'success' => true,
        'can_administer' => $result['can_administer'],
        'current_total' => $result['current_total'],
        'remaining_allowed' => $result['remaining_allowed'],
        'error' => $result['error']
    ]);
    exit;
}

/**
 * AJAX handler for getting daily administration summary
 */
if ($_POST['action'] === 'get_administer_summary') {
    $pid = intval($_POST['pid'] ?? 0);
    $date = $_POST['date'] ?? date('Y-m-d');
    
    if (!$pid) {
        echo json_encode(['success' => false, 'error' => 'Missing patient ID']);
        exit;
    }
    
    $summary = getDailyAdministerSummary($pid, $date);
    
    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'date' => $date
    ]);
    exit;
}
?>
