<?php
/**
 * POS Transfer History AJAX Handler
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Team
 * @copyright Copyright (c) 2024 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

use OpenEMR\Common\Acl\AclMain;

// Ensure user is logged in
if (!isset($_SESSION['authUserID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => xlt('Not authorized')]);
    exit;
}

// Get patient ID from GET request
$pid = intval($_GET['pid'] ?? 0);

if (!$pid) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => xlt('Invalid patient ID')]);
    exit;
}

try {
    // Check if pos_transfer_history table exists
    $table_check = sqlStatement("SHOW TABLES LIKE 'pos_transfer_history'");
    $table_exists = sqlFetchArray($table_check);
    if (!$table_exists) {
        echo json_encode(['success' => true, 'transfers' => []]);
        exit;
    }
    
    // Get patient data for comparison
    $patient_result = sqlStatement("SELECT fname, lname FROM patient_data WHERE pid = ?", array($pid));
    $patient_data = sqlFetchArray($patient_result);
    if (!$patient_data) {
        echo json_encode(['success' => true, 'transfers' => []]);
        exit;
    }
    
    $current_patient_name = $patient_data['fname'] . ' ' . $patient_data['lname'];
    
    // Query to get transfer history for the patient
    $query = "SELECT 
                th.transfer_id,
                th.source_patient_name,
                th.target_patient_name,
                th.drug_name,
                th.quantity_transferred,
                th.transfer_date,
                th.user_name,
                th.lot_number
              FROM pos_transfer_history th
              WHERE th.source_pid = ? OR th.target_pid = ?
              ORDER BY th.transfer_date DESC";
    
    $result = sqlStatement($query, array($pid, $pid));
    $transfers = array();
    
    while ($row = sqlFetchArray($result)) {
        // Determine transfer type and patient name for display
        $isSource = ($row['source_patient_name'] === $current_patient_name);
        $patientName = $isSource ? $row['target_patient_name'] : $row['source_patient_name'];
        $transferType = $isSource ? 'out' : 'in';
        
        $transfers[] = array(
            'transfer_id' => $row['transfer_id'],
            'patient_name' => $patientName,
            'drug_name' => $row['drug_name'],
            'lot_number' => $row['lot_number'],
            'quantity_transferred' => intval($row['quantity_transferred']),
            'transfer_date' => $row['transfer_date'],
            'user_name' => $row['user_name'],
            'transfer_type' => $transferType
        );
    }
    
    echo json_encode([
        'success' => true,
        'transfers' => $transfers,
        'count' => count($transfers)
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching transfer history: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => xlt('Database error occurred')]);
}
?>
