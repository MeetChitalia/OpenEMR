<?php
/**
 * POS Transfer Processor - Handle dispense remaining transfers between patients
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Team
 * @copyright Copyright (c) 2024 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/payment.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;

// Start output buffering to ensure clean JSON responses
ob_start();

// Set JSON content type
header('Content-Type: application/json');

// Helper function to send JSON response
function sendJsonResponse($data, $statusCode = 200) {
    ob_clean();
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    sendJsonResponse(['success' => false, 'error' => 'Invalid JSON input'], 400);
}

// Verify CSRF token
if (!CsrfUtils::verifyCsrfToken($input['csrf_token'] ?? '')) {
    sendJsonResponse(['success' => false, 'error' => 'Invalid CSRF token'], 403);
}

$action = $input['action'] ?? '';

if ($action === 'transfer_dispense') {
    processTransferDispense($input);
} else {
    sendJsonResponse(['success' => false, 'error' => 'Invalid action'], 400);
}

function processTransferDispense($input) {
    $sourcePid = intval($input['source_pid'] ?? 0);
    $targetPid = intval($input['target_pid'] ?? 0);
    $drugId = intval($input['drug_id'] ?? 0);
    $transferAmount = intval($input['transfer_amount'] ?? 0);
    
    // Validate inputs
    if ($sourcePid <= 0 || $targetPid <= 0 || $drugId <= 0 || $transferAmount <= 0) {
        sendJsonResponse(['success' => false, 'error' => 'Invalid input parameters'], 400);
    }
    
    if ($sourcePid === $targetPid) {
        sendJsonResponse(['success' => false, 'error' => 'Cannot transfer to the same patient'], 400);
    }
    
    // Verify patients exist
    $sourcePatient = getPatientData($sourcePid, 'fname,lname');
    $targetPatient = getPatientData($targetPid, 'fname,lname');
    
    if (!$sourcePatient || !$targetPatient) {
        sendJsonResponse(['success' => false, 'error' => 'One or both patients not found'], 404);
    }
    
    // Get drug information
    $drugQuery = "SELECT drug_id, name, form, strength, size, unit FROM drugs WHERE drug_id = ?";
    $drugResult = sqlQuery($drugQuery, array($drugId));
    if (!$drugResult) {
        sendJsonResponse(['success' => false, 'error' => 'Drug not found'], 404);
    }
    $drugData = sqlFetchArray($drugResult);
    if (!$drugData) {
        sendJsonResponse(['success' => false, 'error' => 'Drug not found'], 404);
    }
    
    // Check available remaining dispense for source patient
    $remainingQuery = "SELECT id, lot_number, remaining_quantity, total_quantity, dispensed_quantity, administered_quantity 
                       FROM pos_remaining_dispense 
                       WHERE pid = ? AND drug_id = ? AND remaining_quantity > 0 
                       ORDER BY last_updated ASC";
    $remainingResult = sqlStatement($remainingQuery, array($sourcePid, $drugId));
    
    $availableRemaining = 0;
    $remainingRecords = array();
    
    while ($row = sqlFetchArray($remainingResult)) {
        $availableRemaining += $row['remaining_quantity'];
        $remainingRecords[] = $row;
    }
    
    if ($availableRemaining < $transferAmount) {
        sendJsonResponse(['success' => false, 'error' => "Insufficient remaining dispense. Available: $availableRemaining, Requested: $transferAmount"], 400);
    }
    
    // Start transaction
    sqlBeginTrans();
    
    try {
        $remainingToTransfer = $transferAmount;
        $transferredRecords = array();
        
        // Process transfer from source patient's remaining records
        foreach ($remainingRecords as $record) {
            if ($remainingToTransfer <= 0) break;
            
            $transferFromThisRecord = min($remainingToTransfer, $record['remaining_quantity']);
            
            // Update source patient's remaining record
            $newRemaining = $record['remaining_quantity'] - $transferFromThisRecord;
            $updateQuery = "UPDATE pos_remaining_dispense SET 
                           remaining_quantity = ?, 
                           last_updated = NOW() 
                           WHERE id = ?";
            sqlStatement($updateQuery, array($newRemaining, $record['id']));
            
            // Create or update target patient's remaining record
            $targetRecordQuery = "SELECT id, total_quantity, dispensed_quantity, administered_quantity, remaining_quantity 
                                 FROM pos_remaining_dispense 
                                 WHERE pid = ? AND drug_id = ? AND lot_number = ?";
            $targetRecordResult = sqlQuery($targetRecordQuery, array($targetPid, $drugId, $record['lot_number']));
            $targetRecord = sqlFetchArray($targetRecordResult);
            
            if ($targetRecord) {
                // Update existing record
                $newTotalQuantity = $targetRecord['total_quantity'] + $transferFromThisRecord;
                $newRemainingQuantity = $targetRecord['remaining_quantity'] + $transferFromThisRecord;
                
                $updateTargetQuery = "UPDATE pos_remaining_dispense SET 
                                     total_quantity = ?, 
                                     remaining_quantity = ?, 
                                     last_updated = NOW() 
                                     WHERE id = ?";
                sqlStatement($updateTargetQuery, array($newTotalQuantity, $newRemainingQuantity, $targetRecord['id']));
            } else {
                // Create new record for target patient
                $insertTargetQuery = "INSERT INTO pos_remaining_dispense 
                                     (pid, drug_id, lot_number, total_quantity, dispensed_quantity, administered_quantity, remaining_quantity, receipt_number, created_date, last_updated) 
                                     VALUES (?, ?, ?, ?, 0, 0, ?, ?, NOW(), NOW())";
                $transferReceiptNumber = 'TRANSFER-' . date('Ymd') . '-' . rand(1000, 9999);
                sqlStatement($insertTargetQuery, array($targetPid, $drugId, $record['lot_number'], $transferFromThisRecord, $transferFromThisRecord, $transferReceiptNumber));
            }
            
            $transferredRecords[] = array(
                'lot_number' => $record['lot_number'],
                'amount' => $transferFromThisRecord
            );
            
            $remainingToTransfer -= $transferFromThisRecord;
        }
        
        // Create transfer history table if it doesn't exist
        $createTransferHistoryTable = "
            CREATE TABLE IF NOT EXISTS `pos_transfer_history` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `transfer_id` varchar(50) NOT NULL,
                `source_pid` int(11) NOT NULL,
                `source_patient_name` varchar(255) NOT NULL,
                `target_pid` int(11) NOT NULL,
                `target_patient_name` varchar(255) NOT NULL,
                `drug_id` int(11) NOT NULL,
                `drug_name` varchar(255) NOT NULL,
                `lot_number` varchar(50) NOT NULL,
                `quantity_transferred` int(11) NOT NULL,
                `transfer_date` datetime NOT NULL,
                `user_id` varchar(50) NOT NULL,
                `user_name` varchar(255) NOT NULL,
                `notes` text,
                PRIMARY KEY (`id`),
                KEY `transfer_id` (`transfer_id`),
                KEY `source_pid` (`source_pid`),
                KEY `target_pid` (`target_pid`),
                KEY `drug_id` (`drug_id`),
                KEY `transfer_date` (`transfer_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        sqlStatement($createTransferHistoryTable);
        
        // Record transfer in transfer history table
        $transferId = 'TRANSFER-' . date('Ymd') . '-' . rand(1000, 9999);
        $sourcePatientName = $sourcePatient['fname'] . ' ' . $sourcePatient['lname'];
        $targetPatientName = $targetPatient['fname'] . ' ' . $targetPatient['lname'];
        
        // Get user name
        $userName = $_SESSION['authUser'] ?? 'Unknown User';
        
        $transferHistoryQuery = "INSERT INTO pos_transfer_history 
                                (transfer_id, source_pid, source_patient_name, target_pid, target_patient_name, 
                                 drug_id, drug_name, lot_number, quantity_transferred, transfer_date, user_id, user_name, notes) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)";
        
        $transferNotes = "Transfer of $transferAmount units from $sourcePatientName to $targetPatientName";
        
        sqlStatement($transferHistoryQuery, array(
            $transferId,
            $sourcePid,
            $sourcePatientName,
            $targetPid,
            $targetPatientName,
            $drugId,
            $drugData['name'],
            $transferredRecords[0]['lot_number'],
            $transferAmount,
            $_SESSION['authUserID'],
            $userName,
            $transferNotes
        ));
        
        // Commit transaction
        sqlCommitTrans();
        
        // Log the transfer
        error_log("Dispense transfer completed: $transferAmount units of drug_id $drugId from patient $sourcePid to patient $targetPid by user " . $_SESSION['authUserID']);
        
        sendJsonResponse([
            'success' => true, 
            'message' => "Successfully transferred $transferAmount units to " . $targetPatient['fname'] . " " . $targetPatient['lname'],
            'transfer_details' => [
                'source_patient' => $sourcePatient['fname'] . " " . $sourcePatient['lname'],
                'target_patient' => $targetPatient['fname'] . " " . $targetPatient['lname'],
                'drug_name' => $drugData['name'],
                'amount_transferred' => $transferAmount,
                'transfer_id' => $transferId
            ]
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        sqlRollbackTrans();
        error_log("Transfer error: " . $e->getMessage());
        sendJsonResponse(['success' => false, 'error' => 'Transfer failed: ' . $e->getMessage()], 500);
    }
}




















