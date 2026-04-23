<?php
/**
 * Defective Medicines Handler for POS System
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Team
 * @copyright Copyright (c) 2024 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Disable error reporting to prevent HTML output
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering to ensure clean JSON responses
ob_start();

// Set JSON content type
header('Content-Type: application/json');

try {
    require_once(__DIR__ . "/../globals.php");
    require_once(__DIR__ . "/../../library/patient.inc");
    require_once(__DIR__ . "/../../library/payment.inc.php");
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'System initialization failed: ' . $e->getMessage()]);
    exit;
}

use OpenEMR\Common\Csrf\CsrfUtils;

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

try {
    switch ($action) {
        case 'report_defective':
            reportDefectiveMedicine($input);
            break;
        case 'approve_defective':
            approveDefectiveMedicine($input);
            break;
        case 'reject_defective':
            rejectDefectiveMedicine($input);
            break;
        case 'process_replacement':
            processReplacement($input);
            break;
        case 'get_defective_list':
            getDefectiveList($input);
            break;
        case 'verify_manager':
            verifyManagerCredentials($input);
            break;
        default:
            sendJsonResponse(['success' => false, 'error' => 'Invalid action'], 400);
    }
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Request processing failed: ' . $e->getMessage()]);
    exit;
}

/**
 * Report a medicine as defective
 */
function reportDefectiveMedicine($input) {
    $drug_id = intval($input['drug_id'] ?? 0);
    $lot_number = $input['lot_number'] ?? '';
    $inventory_id = intval($input['inventory_id'] ?? 0);
    $pid = intval($input['pid'] ?? 0);
    $quantity = floatval($input['quantity'] ?? 0);
    $reason = trim($input['reason'] ?? '');
    $defect_type = $input['defect_type'] ?? 'defective';
    $notes = trim($input['notes'] ?? '');
    $reported_by = $_SESSION['authUser'] ?? 'unknown';
    
    // Validation
    if (!$drug_id || $quantity <= 0 || empty($reason)) {
        sendJsonResponse(['success' => false, 'error' => 'Invalid input parameters'], 400);
    }
    
    if (!in_array($defect_type, ['faulty', 'defective', 'expired', 'contaminated', 'other'])) {
        sendJsonResponse(['success' => false, 'error' => 'Invalid defect type'], 400);
    }
    
    try {
        // Insert defective medicine record
        $sql = "INSERT INTO defective_medicines (drug_id, lot_number, inventory_id, pid, quantity, reason, defect_type, reported_by, notes, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
        
        $result = sqlStatement($sql, array(
            $drug_id, $lot_number, $inventory_id, $pid, $quantity, $reason, $defect_type, $reported_by, $notes
        ));
        
        if ($result) {
            $defective_id = sqlInsertID();
            
            // Log the action
            error_log("Defective medicine reported - ID: $defective_id, Drug: $drug_id, Quantity: $quantity, Reason: $reason");
            
            sendJsonResponse([
                'success' => true, 
                'message' => 'Defective medicine reported successfully. Awaiting manager approval.',
                'defective_id' => $defective_id
            ]);
        } else {
            sendJsonResponse(['success' => false, 'error' => 'Failed to report defective medicine'], 500);
        }
        
    } catch (Exception $e) {
        error_log("Error reporting defective medicine: " . $e->getMessage());
        sendJsonResponse(['success' => false, 'error' => 'Database error occurred'], 500);
    }
}

/**
 * Approve defective medicine report (Manager only)
 */
function approveDefectiveMedicine($input) {
    $defective_id = intval($input['defective_id'] ?? 0);
    $approval_notes = trim($input['approval_notes'] ?? '');
    
    if (!$defective_id) {
        sendJsonResponse(['success' => false, 'error' => 'Invalid defective medicine ID'], 400);
    }
    
    try {
        // Get defective medicine details
        $sql = "SELECT * FROM defective_medicines WHERE id = ? AND status = 'pending'";
        $result = sqlQuery($sql, array($defective_id));
        
        if (!$result) {
            sendJsonResponse(['success' => false, 'error' => 'Defective medicine not found or already processed'], 404);
        }
        
        // Update status to approved
        $update_sql = "UPDATE defective_medicines SET 
                       status = 'approved', 
                       approved_by = ?, 
                       approval_date = NOW(), 
                       notes = CONCAT(IFNULL(notes, ''), '\nManager Approval: ', ?)
                       WHERE id = ?";
        
        $update_result = sqlStatement($update_sql, array($_SESSION['authUser'], $approval_notes, $defective_id));
        
        if ($update_result) {
            // Automatically deduct from inventory
            deductFromInventory($defective_id);
            
            sendJsonResponse([
                'success' => true, 
                'message' => 'Defective medicine approved. Inventory has been deducted.',
                'defective_id' => $defective_id
            ]);
        } else {
            sendJsonResponse(['success' => false, 'error' => 'Failed to approve defective medicine'], 500);
        }
        
    } catch (Exception $e) {
        error_log("Error approving defective medicine: " . $e->getMessage());
        sendJsonResponse(['success' => false, 'error' => 'Database error occurred'], 500);
    }
}

/**
 * Reject defective medicine report (Manager only)
 */
function rejectDefectiveMedicine($input) {
    $defective_id = intval($input['defective_id'] ?? 0);
    $rejection_reason = trim($input['rejection_reason'] ?? '');
    
    if (!$defective_id) {
        sendJsonResponse(['success' => false, 'error' => 'Invalid defective medicine ID'], 400);
    }
    
    if (empty($rejection_reason)) {
        sendJsonResponse(['success' => false, 'error' => 'Rejection reason is required'], 400);
    }
    
    try {
        // Update status to rejected
        $sql = "UPDATE defective_medicines SET 
                status = 'rejected', 
                approved_by = ?, 
                approval_date = NOW(), 
                notes = CONCAT(IFNULL(notes, ''), '\nManager Rejection: ', ?)
                WHERE id = ? AND status = 'pending'";
        
        $result = sqlStatement($sql, array($_SESSION['authUser'], $rejection_reason, $defective_id));
        
        if ($result) {
            sendJsonResponse([
                'success' => true, 
                'message' => 'Defective medicine report rejected.',
                'defective_id' => $defective_id
            ]);
        } else {
            sendJsonResponse(['success' => false, 'error' => 'Failed to reject defective medicine'], 500);
        }
        
    } catch (Exception $e) {
        error_log("Error rejecting defective medicine: " . $e->getMessage());
        sendJsonResponse(['success' => false, 'error' => 'Database error occurred'], 500);
    }
}

/**
 * Process replacement medicine
 */
function processReplacement($input) {
    $defective_id = intval($input['defective_id'] ?? 0);
    $replacement_drug_id = intval($input['replacement_drug_id'] ?? 0);
    $replacement_lot_number = $input['replacement_lot_number'] ?? '';
    $replacement_inventory_id = intval($input['replacement_inventory_id'] ?? 0);
    $quantity = floatval($input['quantity'] ?? 0);
    $fee = floatval($input['fee'] ?? 0);
    $notes = trim($input['notes'] ?? '');
    $processed_by = $_SESSION['authUser'] ?? 'unknown';
    
    if (!$defective_id || !$replacement_drug_id || $quantity <= 0) {
        sendJsonResponse(['success' => false, 'error' => 'Invalid input parameters'], 400);
    }
    
    try {
        // Check if defective medicine is approved
        $check_sql = "SELECT status FROM defective_medicines WHERE id = ?";
        $check_result = sqlQuery($check_sql, array($defective_id));
        
        if (!$check_result || $check_result['status'] !== 'approved') {
            sendJsonResponse(['success' => false, 'error' => 'Defective medicine must be approved before processing replacement'], 400);
        }
        
        // Insert replacement record
        $sql = "INSERT INTO defective_medicine_replacements 
                (defective_id, replacement_drug_id, replacement_lot_number, replacement_inventory_id, quantity, fee, processed_by, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $result = sqlStatement($sql, array(
            $defective_id, $replacement_drug_id, $replacement_lot_number, 
            $replacement_inventory_id, $quantity, $fee, $processed_by, $notes
        ));
        
        if ($result) {
            // Update defective medicine status
            $update_sql = "UPDATE defective_medicines SET 
                           status = 'processed', 
                           replacement_processed = 1 
                           WHERE id = ?";
            
            sqlStatement($update_sql, array($defective_id));
            
            sendJsonResponse([
                'success' => true, 
                'message' => 'Replacement medicine processed successfully.',
                'defective_id' => $defective_id
            ]);
        } else {
            sendJsonResponse(['success' => false, 'error' => 'Failed to process replacement'], 500);
        }
        
    } catch (Exception $e) {
        error_log("Error processing replacement: " . $e->getMessage());
        sendJsonResponse(['success' => false, 'error' => 'Database error occurred'], 500);
    }
}

/**
 * Get list of defective medicines
 */
function getDefectiveList($input) {
    $status = $input['status'] ?? '';
    $drug_id = intval($input['drug_id'] ?? 0);
    $limit = intval($input['limit'] ?? 100);
    
    try {
        $where_conditions = array();
        $bind_params = array();
        
        if ($status) {
            $where_conditions[] = "dm.status = ?";
            $bind_params[] = $status;
        }
        
        if ($drug_id) {
            $where_conditions[] = "dm.drug_id = ?";
            $bind_params[] = $drug_id;
        }
        
        $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
        
        $sql = "SELECT * FROM defective_medicines_summary $where_clause ORDER BY dm.created_date DESC LIMIT ?";
        $bind_params[] = $limit;
        
        $result = sqlStatement($sql, $bind_params);
        $defective_list = array();
        
        while ($row = sqlFetchArray($result)) {
            $defective_list[] = $row;
        }
        
        sendJsonResponse([
            'success' => true,
            'defective_list' => $defective_list,
            'total_count' => count($defective_list)
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting defective list: " . $e->getMessage());
        sendJsonResponse(['success' => false, 'error' => 'Database error occurred'], 500);
    }
}

/**
 * Verify manager credentials
 */
function verifyManagerCredentials($username, $password) {
    if (empty($username) || empty($password)) {
        return false;
    }
    
    try {
        // Check if user exists and has manager/admin privileges
        $sql = "SELECT u.id, u.username, u.active, u.authorized 
                FROM users u 
                WHERE u.username = ? AND u.active = 1 AND u.authorized = 1";
        
        $result = sqlQuery($sql, array($username));
        
        if (!$result) {
            return false;
        }
        
        // Verify password
        $sql = "SELECT u.id FROM users u WHERE u.username = ? AND u.password = ?";
        $result = sqlQuery($sql, array($username, $password));
        
        return $result !== false;
        
    } catch (Exception $e) {
        error_log("Error verifying manager credentials: " . $e->getMessage());
        return false;
    }
}

/**
 * Deduct defective medicine from inventory
 */
function deductFromInventory($defective_id) {
    try {
        // Get defective medicine details
        $sql = "SELECT * FROM defective_medicines WHERE id = ? AND status = 'approved' AND inventory_deducted = 0";
        $result = sqlQuery($sql, array($defective_id));
        
        if (!$result) {
            return false;
        }
        
        $drug_id = $result['drug_id'];
        $lot_number = $result['lot_number'];
        $inventory_id = $result['inventory_id'];
        $quantity = $result['quantity'];
        
        // Deduct from inventory if we have specific inventory record
        if ($inventory_id) {
            $deduct_sql = "UPDATE drug_inventory SET 
                           on_hand = GREATEST(0, on_hand - ?) 
                           WHERE inventory_id = ? AND on_hand >= ?";
            
            $deduct_result = sqlStatement($deduct_sql, array($quantity, $inventory_id, $quantity));
            
            if ($deduct_result) {
                // Mark as deducted
                $update_sql = "UPDATE defective_medicines SET inventory_deducted = 1 WHERE id = ?";
                sqlStatement($update_sql, array($defective_id));
                
                error_log("Inventory deducted for defective medicine ID: $defective_id, Quantity: $quantity");
                return true;
            }
        } else if ($lot_number) {
            // Deduct from specific lot
            $deduct_sql = "UPDATE drug_inventory SET 
                           on_hand = GREATEST(0, on_hand - ?) 
                           WHERE drug_id = ? AND lot_number = ? AND on_hand >= ?";
            
            $deduct_result = sqlStatement($deduct_sql, array($quantity, $drug_id, $lot_number, $quantity));
            
            if ($deduct_result) {
                // Mark as deducted
                $update_sql = "UPDATE defective_medicines SET inventory_deducted = 1 WHERE id = ?";
                sqlStatement($update_sql, array($defective_id));
                
                error_log("Inventory deducted for defective medicine ID: $defective_id, Lot: $lot_number, Quantity: $quantity");
                return true;
            }
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Error deducting inventory for defective medicine: " . $e->getMessage());
        return false;
    }
}
