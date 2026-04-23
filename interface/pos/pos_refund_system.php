<?php
/**
 * POS Refund System for Dispense Remaining Quantities
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
    require_once("$srcdir/patient.inc");
    require_once("$srcdir/payment.inc.php");
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

function normalizeRefundQuantity($value)
{
    return round((float) ($value ?? 0), 4);
}

function refundPosHasColumn(string $column): bool
{
    static $cache = [];

    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $cache[$column] = (bool) sqlFetchArray(sqlStatement("SHOW COLUMNS FROM pos_transactions LIKE ?", [$column]));
    return $cache[$column];
}

function refundPosFacilityId(int $pid): ?int
{
    $selectedFacilityId = (int) ($_SESSION['facilityId'] ?? 0);
    if ($selectedFacilityId > 0) {
        return $selectedFacilityId;
    }

    $row = sqlFetchArray(sqlStatement("SELECT facility_id FROM patient_data WHERE pid = ? LIMIT 1", [$pid]));
    $patientFacilityId = (int) ($row['facility_id'] ?? 0);
    if ($patientFacilityId > 0) {
        return $patientFacilityId;
    }

    $legacyFacilityId = (int) ($_SESSION['facility_id'] ?? 0);
    return $legacyFacilityId > 0 ? $legacyFacilityId : null;
}

function refundPosSelectedFacilityId(): int
{
    return (int) ($_SESSION['facilityId'] ?? 0);
}

function refundPosFacilityFilter(string $alias = ''): string
{
    $selectedFacilityId = refundPosSelectedFacilityId();
    if ($selectedFacilityId <= 0) {
        return '';
    }

    $prefix = $alias !== '' ? $alias . '.' : '';
    return " AND {$prefix}facility_id = " . (int) $selectedFacilityId;
}

function refundPosPatientFacilityFilter(string $patientAlias = 'p'): string
{
    $selectedFacilityId = refundPosSelectedFacilityId();
    if ($selectedFacilityId <= 0) {
        return '';
    }

    $prefix = $patientAlias !== '' ? $patientAlias . '.' : '';
    return " AND {$prefix}facility_id = " . (int) $selectedFacilityId;
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
        case 'get_refundable_items':
            getRefundableItems($input);
            break;
        case 'process_refund':
            processRefund($input);
            break;
        case 'process_multi_refund':
            processMultiRefund($input);
            break;
        case 'get_patient_credit':
            getPatientCredit($input);
            break;
        case 'transfer_credit':
            transferCredit($input);
            break;
        case 'process_credit_payment':
            processCreditPayment($input);
            break;
        case 'get_refund_history':
            getRefundHistory($input);
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
 * Get refundable items for a patient
 */
function getRefundableItems($input) {
    $pid = intval($input['pid'] ?? 0);
    
    if (!$pid) {
        sendJsonResponse(['success' => false, 'error' => 'Invalid patient ID'], 400);
    }
    
    // Get all recent transactions that can be refunded (last 30 days)
    $sql = "SELECT 
                pr.id,
                pr.pid,
                pr.receipt_number,
                pr.amount as original_amount,
                pr.payment_method,
                pr.transaction_id as stripe_payment_intent_id,
                pr.created_date,
                pr.receipt_data as items,
                'transaction' as item_type
            FROM pos_receipts pr
            WHERE pr.pid = ? AND pr.created_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)" . refundPosFacilityFilter('pr') . "
            ORDER BY pr.created_date DESC";
    
    $result = sqlStatement($sql, array($pid));
    $items = array();
    
    while ($row = sqlFetchArray($result)) {
        $receipt_data = json_decode($row['items'], true);
        if ($receipt_data && isset($receipt_data['items'])) {
            $receipt_items = $receipt_data['items'];
            foreach ($receipt_items as $item) {
                // Skip items with 0 quantity or amount
                if (empty($item['quantity']) || empty($item['price'])) {
                    continue;
                }
                
                $items[] = array(
                    'id' => $row['id'] . '_' . ($item['drug_id'] ?? $item['id']),
                    'receipt_id' => $row['id'],
                    'drug_id' => $item['drug_id'] ?? null,
                    'drug_name' => $item['name'] ?? $item['drug_name'] ?? 'Unknown Product',
                    'lot_number' => $item['lot_number'] ?? 'N/A',
                    'form' => $item['form'] ?? '',
                    'is_ml_form' => strtolower(trim((string) ($item['form'] ?? ''))) === 'ml',
                    'quantity' => normalizeRefundQuantity($item['quantity']),
                    'price' => floatval($item['price']),
                    'total_amount' => floatval($item['quantity']) * floatval($item['price']),
                    'receipt_number' => $row['receipt_number'],
                    'original_amount' => floatval($row['original_amount']),
                    'payment_method' => $row['payment_method'],
                    'stripe_payment_intent_id' => $row['stripe_payment_intent_id'],
                    'created_date' => $row['created_date'],
                    'item_type' => 'transaction'
                );
            }
        }
    }
    
    // Also get dispense remaining items
    $sql_remaining = "SELECT 
                        prd.id,
                        prd.pid,
                        prd.drug_id,
                        d.name as drug_name,
                        d.form,
                        prd.lot_number,
                        prd.remaining_quantity,
                        prd.total_quantity,
                        prd.dispensed_quantity,
                        prd.administered_quantity,
                        prd.receipt_number,
                        prd.created_date,
                        prd.last_updated,
                        COALESCE(pr.amount, 0) as original_amount,
                        COALESCE(pr.payment_method, '') as payment_method,
                        COALESCE(pr.transaction_id, '') as stripe_payment_intent_id
                    FROM pos_remaining_dispense prd
                    INNER JOIN patient_data p ON p.pid = prd.pid
                    LEFT JOIN drugs d ON prd.drug_id = d.drug_id
                    LEFT JOIN pos_receipts pr ON pr.pid = prd.pid AND pr.receipt_number = prd.receipt_number
                    WHERE prd.pid = ? AND prd.remaining_quantity > 0" . refundPosPatientFacilityFilter('p') . "
                    ORDER BY prd.last_updated DESC";
    
    $result_remaining = sqlStatement($sql_remaining, array($pid));
    
    while ($row = sqlFetchArray($result_remaining)) {
        $refundable_amount = calculateRefundableAmount($row);
        
        // Calculate the original per-unit price
        $original_per_unit_price = $row['total_quantity'] > 0 ? floatval($row['original_amount']) / normalizeRefundQuantity($row['total_quantity']) : 0;
        
        $items[] = array(
            'id' => 'remaining_' . $row['id'],
            'receipt_id' => $row['id'],
            'drug_id' => $row['drug_id'],
            'drug_name' => $row['drug_name'],
            'form' => $row['form'],
            'is_ml_form' => strtolower(trim((string) ($row['form'] ?? ''))) === 'ml',
            'lot_number' => $row['lot_number'],
            'quantity' => normalizeRefundQuantity($row['remaining_quantity']),
            'price' => $row['remaining_quantity'] > 0 ? $refundable_amount / normalizeRefundQuantity($row['remaining_quantity']) : 0,
            'total_amount' => $refundable_amount,
            'receipt_number' => $row['receipt_number'],
            'original_amount' => floatval($row['original_amount']),
            'original_per_unit_price' => $original_per_unit_price,
            'payment_method' => $row['payment_method'],
            'stripe_payment_intent_id' => $row['stripe_payment_intent_id'],
            'created_date' => $row['last_updated'],
            'item_type' => 'remaining_dispense',
            'remaining_quantity' => normalizeRefundQuantity($row['remaining_quantity']),
            'total_quantity' => normalizeRefundQuantity($row['total_quantity'])
        );
    }
    
    sendJsonResponse([
        'success' => true,
        'items' => $items,
        'count' => count($items)
    ]);
}

/**
 * Calculate refundable amount based on remaining quantity
 */
function calculateRefundableAmount($item) {
    $drug_id = intval($item['drug_id']);
    $remaining_quantity = normalizeRefundQuantity($item['remaining_quantity']);
    
    if ($remaining_quantity <= 0) {
        return 0;
    }
    
    // Get the sell price from the drug inventory
    $sell_price = getDrugSellPrice($drug_id);
    
    if ($sell_price <= 0) {
        // Fallback to original calculation if sell price not found
        $original_amount = floatval($item['original_amount']);
        $total_quantity = normalizeRefundQuantity($item['total_quantity']);
        
        if ($total_quantity <= 0) {
            return 0;
        }
        
        // Calculate proportional refund amount based on original payment
        $refundable_amount = ($remaining_quantity / $total_quantity) * $original_amount;
        return round($refundable_amount, 2);
    }
    
    // Calculate refund amount based on sell price
    $refundable_amount = $remaining_quantity * $sell_price;
    
    return round($refundable_amount, 2);
}

/**
 * Get drug sell price from inventory
 */
function getDrugSellPrice($drug_id) {
    if (empty($drug_id) || $drug_id <= 0) {
        error_log("getDrugSellPrice - Invalid drug_id: $drug_id");
        return 0;
    }
    
    try {
        // First try to get from drug_inventory table
        $sql = "SELECT sell_price FROM drug_inventory WHERE drug_id = ? AND on_hand > 0 ORDER BY expiration ASC LIMIT 1";
        $result = sqlStatement($sql, array($drug_id));
        $row = sqlFetchArray($result);
        
        if ($row && isset($row['sell_price']) && $row['sell_price'] > 0) {
            return floatval($row['sell_price']);
        }
        
        // Fallback to recent drug_sales to get the last known price
        $sql = "SELECT fee FROM drug_sales WHERE drug_id = ? AND fee > 0 ORDER BY sale_date DESC LIMIT 1";
        $result = sqlStatement($sql, array($drug_id));
        $row = sqlFetchArray($result);
        
        if ($row && isset($row['fee']) && $row['fee'] > 0) {
            return floatval($row['fee']);
        }
        
        // Fallback to drugs table sell_price
        $sql = "SELECT sell_price, name FROM drugs WHERE drug_id = ?";
        $result = sqlStatement($sql, array($drug_id));
        $row = sqlFetchArray($result);
        
        if ($row && isset($row['sell_price']) && $row['sell_price'] > 0) {
            error_log("getDrugSellPrice - Using drugs table sell_price for drug_id: $drug_id ({$row['name']}), price: {$row['sell_price']}");
            return floatval($row['sell_price']);
        }
        
        if ($row) {
            error_log("getDrugSellPrice - No valid price found for drug_id: $drug_id, drug: " . $row['name']);
        } else {
            error_log("getDrugSellPrice - Drug not found in drugs table: drug_id: $drug_id");
        }
        
    } catch (Exception $e) {
        error_log("getDrugSellPrice - Error getting price for drug_id: $drug_id - " . $e->getMessage());
    }
    
    return 0;
}

/**
 * Process refund for items
 */
function processRefund($input) {
    // Debug logging
    error_log("processRefund - Input received: " . json_encode($input));
    
    $pid = intval($input['pid'] ?? 0);
    $item_id = $input['item_id'] ?? '';
    $refund_quantity = normalizeRefundQuantity($input['refund_quantity'] ?? 0);
    $refund_type = $input['refund_type'] ?? ''; // 'payment' or 'credit'
    $refund_amount = floatval($input['refund_amount'] ?? 0);
    $reason = $input['reason'] ?? '';
    
    // Debug logging
    error_log("processRefund - Parsed values: pid=$pid, item_id='$item_id', refund_quantity=$refund_quantity, refund_type='$refund_type', refund_amount=$refund_amount");
    
    if (!$pid || !$item_id || $refund_quantity <= 0 || $refund_amount <= 0) {
        $error_msg = "Invalid input parameters: pid=$pid, item_id='$item_id', refund_quantity=$refund_quantity, refund_amount=$refund_amount";
        error_log("processRefund - " . $error_msg);
        sendJsonResponse(['success' => false, 'error' => $error_msg], 400);
    }
    
    if (!in_array($refund_type, ['payment', 'credit'])) {
        sendJsonResponse(['success' => false, 'error' => 'Invalid refund type'], 400);
    }
    
    // Parse item ID to determine type
    $item_type = 'transaction';
    $actual_item_id = $item_id;
    $drug_id = null;
    
    if (strpos($item_id, 'remaining_') === 0) {
        $item_type = 'remaining_dispense';
        $actual_item_id = substr($item_id, 10); // Remove 'remaining_' prefix
    } else if (strpos($item_id, 'refund-product-') === 0) {
        // New format for dispense remaining refunds
        $item_type = 'remaining_dispense';
        $drug_id = substr($item_id, 15); // Remove 'refund-product-' prefix
        // For this format, we need to find the remaining dispense record by drug_id and patient
        $actual_item_id = findRemainingDispenseByDrugId($drug_id, $pid);
    } else {
        // For transaction items, extract receipt_id and drug_id from the item_id (format: receipt_id_drug_drug_id_lot_lot_number)
        $parts = explode('_', $item_id);
        if (count($parts) >= 4) {
            $receipt_id = $parts[0]; // First part is receipt_id
            $drug_id = $parts[2]; // Third part is drug_id (after 'drug')
        }
    }
    
    // Get the item details based on type
    if ($item_type === 'remaining_dispense') {
        if (!$actual_item_id) {
            sendJsonResponse(['success' => false, 'error' => 'No remaining dispense found for this product'], 404);
        }
        
        $item = getRemainingDispenseItem($actual_item_id);
        if (!$item || $item['pid'] != $pid) {
            sendJsonResponse(['success' => false, 'error' => 'Item not found or access denied'], 404);
        }
        
        if ($refund_quantity > $item['remaining_quantity']) {
            sendJsonResponse(['success' => false, 'error' => 'Refund quantity exceeds remaining quantity'], 400);
        }
    } else {
        // For transaction items, we need to get the receipt details
        $item = getTransactionItem($actual_item_id, $pid);
        if (!$item) {
            sendJsonResponse(['success' => false, 'error' => 'Item not found or access denied'], 404);
        }
        
        // For transaction items, we need to parse the receipt data to get the actual item quantity
        $receipt_data = json_decode($item['receipt_data'], true);
        $item_quantity = 0;
        
        error_log("processRefund - Receipt data: " . json_encode($receipt_data));
        error_log("processRefund - Looking for drug_id: $drug_id");
        
        if ($receipt_data && isset($receipt_data['items'])) {
            foreach ($receipt_data['items'] as $receipt_item) {
                error_log("processRefund - Checking receipt item: " . json_encode($receipt_item));
                // Check if this is the item we're looking for (try multiple ways to match)
                $receipt_drug_id = $receipt_item['drug_id'] ?? null;
                $receipt_id = $receipt_item['id'] ?? null;
                
                error_log("processRefund - Comparing: receipt_drug_id='$receipt_drug_id' vs drug_id='$drug_id'");
                error_log("processRefund - Receipt ID: '$receipt_id'");
                
                // Extract drug_id from receipt item ID if it exists
                $extracted_drug_id = null;
                if ($receipt_id && preg_match('/drug_(\d+)_lot_/', $receipt_id, $matches)) {
                    $extracted_drug_id = $matches[1];
                    error_log("processRefund - Extracted drug_id from receipt_id: $extracted_drug_id");
                }
                
                if (($receipt_drug_id && $receipt_drug_id == $drug_id) || 
                    ($extracted_drug_id && $extracted_drug_id == $drug_id)) {
                    $item_quantity = normalizeRefundQuantity(isset($receipt_item['quantity']) ? $receipt_item['quantity'] : 0);
                    error_log("processRefund - Found matching item, quantity: $item_quantity");
                    
                    // Store the matched item for later use
                    $matched_receipt_item = $receipt_item;
                    break;
                }
            }
        }
        
        error_log("processRefund - Final item_quantity: $item_quantity, refund_quantity: $refund_quantity");
        
        if ($refund_quantity > $item_quantity) {
            sendJsonResponse(['success' => false, 'error' => 'Refund quantity exceeds original quantity'], 400);
        }
    }
    
    // Start transaction
    sqlBeginTrans();
    
    try {
        // Update remaining dispense record if applicable
        if ($item_type === 'remaining_dispense') {
            $new_remaining = $item['remaining_quantity'] - $refund_quantity;
            error_log("processRefund - Updating remaining dispense: item_id=$actual_item_id, old_remaining={$item['remaining_quantity']}, refund_quantity=$refund_quantity, new_remaining=$new_remaining");
            
            $update_sql = "UPDATE pos_remaining_dispense SET 
                           remaining_quantity = ?, 
                           last_updated = NOW() 
                           WHERE id = ?";
            sqlStatement($update_sql, array($new_remaining, $actual_item_id));
            
            // Verify the update
            $verify_sql = "SELECT remaining_quantity FROM pos_remaining_dispense WHERE id = ?";
            $verify_result = sqlStatement($verify_sql, array($actual_item_id));
            $verify_row = sqlFetchArray($verify_result);
            error_log("processRefund - Verification: updated remaining_quantity = " . ($verify_row['remaining_quantity'] ?? 'NULL'));
        }
        
        // Record refund transaction
        if ($item_type === 'remaining_dispense') {
            $refund_id = recordRefundTransaction($pid, $item, $refund_quantity, $refund_amount, $refund_type, $reason);
        } else {
            $refund_id = recordRefundTransaction($pid, $item, $refund_quantity, $refund_amount, $refund_type, $reason, $matched_receipt_item ?? null);
        }
        
        // Handle refund based on type
        if ($refund_type === 'payment') {
            $payment_result = processPaymentRefund($item, $refund_amount);
            if (!$payment_result['success']) {
                throw new Exception($payment_result['error']);
            }
        } else { // credit
            error_log("processRefund - Adding credit balance: pid=$pid, amount=$refund_amount");
            $description = "Refund credit: " . ($reason ?: 'Product refund');
            $credit_result = addPatientCredit($pid, $refund_amount, $description);
            if (!$credit_result['success']) {
                throw new Exception($credit_result['error']);
            }
            error_log("processRefund - Credit balance updated successfully: new_balance={$credit_result['new_balance']}");
        }
        
        // Commit transaction
        sqlCommitTrans();
        
        // Clear any output buffers to ensure clean JSON response
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        sendJsonResponse([
            'success' => true,
            'refund_id' => $refund_id,
            'message' => 'Refund processed successfully',
            'refund_amount' => $refund_amount
        ]);
        
    } catch (Exception $e) {
        sqlRollbackTrans();
        sendJsonResponse(['success' => false, 'error' => 'Refund failed: ' . $e->getMessage()], 500);
    }
}

/**
 * Get remaining dispense item by ID
 */
function getRemainingDispenseItem($item_id) {
    $sql = "SELECT * FROM pos_remaining_dispense WHERE id = ?";
    $result = sqlStatement($sql, array($item_id));
    return sqlFetchArray($result);
}

/**
 * Find remaining dispense record by drug ID and patient ID
 */
function findRemainingDispenseByDrugId($drug_id, $pid) {
    $sql = "SELECT id FROM pos_remaining_dispense WHERE drug_id = ? AND pid = ? AND remaining_quantity > 0 ORDER BY last_updated DESC LIMIT 1";
    $result = sqlStatement($sql, array($drug_id, $pid));
    $row = sqlFetchArray($result);
    return $row ? $row['id'] : null;
}

/**
 * Get transaction item by receipt ID and patient ID
 */
function getTransactionItem($receipt_id, $pid) {
    $sql = "SELECT * FROM pos_receipts WHERE id = ? AND pid = ?";
    $result = sqlStatement($sql, array($receipt_id, $pid));
    return sqlFetchArray($result);
}

/**
 * Record refund transaction
 */
function recordRefundTransaction($pid, $item, $refund_quantity, $refund_amount, $refund_type, $reason, $receipt_item = null) {
    // Create refunds table if it doesn't exist
    createRefundsTable();
    
    $refund_number = 'REFUND-' . date('Ymd') . '-' . rand(1000, 9999);
    
    // Extract data from receipt item if available, otherwise use item data
    $drug_id = null;
    $drug_name = null;
    $lot_number = null;
    
    if ($receipt_item && is_array($receipt_item)) {
        // Extract drug_id from receipt item ID
        if (isset($receipt_item['id']) && preg_match('/drug_(\d+)_lot_(\d+)/', $receipt_item['id'], $matches)) {
            $drug_id = $matches[1];
            $lot_number = $matches[2];
        }
        $drug_name = $receipt_item['name'] ?? 'Unknown Product';
    } else {
        // Fallback to item data
        $drug_id = isset($item['drug_id']) ? $item['drug_id'] : null;
        $drug_name = isset($item['drug_name']) ? $item['drug_name'] : 'Unknown Product';
        $lot_number = isset($item['lot_number']) ? $item['lot_number'] : 'N/A';
    }
    
    $insertColumns = [
        'refund_number', 'pid', 'item_id', 'drug_id', 'drug_name', 'lot_number',
        'refund_quantity', 'refund_amount', 'refund_type', 'original_receipt',
        'reason', 'created_date', 'user_id'
    ];
    $params = [
        $refund_number, $pid, $item['id'], $drug_id, $drug_name,
        $lot_number, $refund_quantity, $refund_amount, $refund_type,
        $item['receipt_number'], $reason, date('Y-m-d H:i:s'), $_SESSION['authUserID'] ?? 1
    ];

    if ((bool) sqlFetchArray(sqlStatement("SHOW COLUMNS FROM pos_refunds LIKE 'facility_id'"))) {
        $insertColumns[] = 'facility_id';
        $params[] = refundPosFacilityId((int) $pid);
    }

    $sql = "INSERT INTO pos_refunds 
            (" . implode(', ', $insertColumns) . ") 
            VALUES (" . implode(', ', array_fill(0, count($insertColumns), '?')) . ")";

    sqlStatement($sql, $params);
    
    return $refund_number;
}

/**
 * Create refunds table
 */
function createRefundsTable() {
    $sql = "CREATE TABLE IF NOT EXISTS `pos_refunds` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `refund_number` varchar(50) NOT NULL,
        `pid` int(11) NOT NULL,
        `item_id` int(11) NOT NULL,
        `drug_id` int(11) NOT NULL,
        `drug_name` varchar(255) NOT NULL,
        `lot_number` varchar(50) NOT NULL,
        `refund_quantity` decimal(12,4) NOT NULL,
        `refund_amount` decimal(10,2) NOT NULL,
        `refund_type` enum('payment','credit') NOT NULL,
        `original_receipt` varchar(50) NOT NULL,
        `reason` text,
        `created_date` datetime NOT NULL,
        `user_id` int(11) NOT NULL,
        `facility_id` int(11) DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `refund_number` (`refund_number`),
        KEY `pid` (`pid`),
        KEY `item_id` (`item_id`),
        KEY `drug_id` (`drug_id`),
        KEY `refund_type` (`refund_type`),
        KEY `created_date` (`created_date`),
        KEY `facility_id` (`facility_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    sqlStatement($sql);
    sqlStatement("ALTER TABLE `pos_refunds` MODIFY `refund_quantity` decimal(12,4) NOT NULL");
    if (!(bool) sqlFetchArray(sqlStatement("SHOW COLUMNS FROM pos_refunds LIKE 'facility_id'"))) {
        sqlStatement("ALTER TABLE `pos_refunds` ADD COLUMN `facility_id` INT NULL");
    }
}

/**
 * Process payment refund (Stripe integration)
 */
function processPaymentRefund($item, $refund_amount) {
    // Check for Stripe payment intent in different possible fields
    $stripe_payment_intent = $item['stripe_payment_intent_id'] ?? $item['transaction_id'] ?? null;
    
    if (empty($stripe_payment_intent)) {
        return ['success' => false, 'error' => 'No Stripe payment intent found for refund'];
    }
    
    // Check if Stripe is configured
    if (empty($GLOBALS['stripe_secret_key'])) {
        return ['success' => false, 'error' => 'Stripe is not configured. Please contact administrator.'];
    }
    
    // Initialize Stripe
    if (!file_exists(__DIR__ . "/../../library/stripe/init.php")) {
        return ['success' => false, 'error' => 'Stripe library not found. Please contact administrator.'];
    }
    
    require_once(__DIR__ . "/../../library/stripe/init.php");
    
    try {
        $stripe = new \Stripe\StripeClient($GLOBALS['stripe_secret_key']);
        
        // Create refund
        $refund = $stripe->refunds->create([
            'payment_intent' => $stripe_payment_intent,
            'amount' => intval($refund_amount * 100), // Convert to cents
            'reason' => 'requested_by_customer'
        ]);
        
        return ['success' => true, 'refund_id' => $refund->id];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Stripe refund failed: ' . $e->getMessage()];
    }
}

/**
 * Add credit to patient account
 */
function addPatientCredit($pid, $amount, $description = 'Credit added') {
    // Create patient credit balance table if it doesn't exist
    createPatientCreditTable();
    
    // Check if patient already has credit balance
    $sql = "SELECT id, balance FROM patient_credit_balance WHERE pid = ?";
    $result = sqlStatement($sql, array($pid));
    $existing = sqlFetchArray($result);
    
    $old_balance = $existing ? floatval($existing['balance']) : 0;
    $new_balance = $old_balance + $amount;
    
    if ($existing) {
        // Update existing balance
        $update_sql = "UPDATE patient_credit_balance SET balance = ?, updated_date = NOW() WHERE id = ?";
        sqlStatement($update_sql, array($new_balance, $existing['id']));
    } else {
        // Create new credit balance record
        $insert_sql = "INSERT INTO patient_credit_balance (pid, balance, created_date, updated_date) VALUES (?, ?, NOW(), NOW())";
        sqlStatement($insert_sql, array($pid, $amount));
    }
    
    // Record the credit transaction
    recordCreditTransaction($pid, 'refund', $amount, $old_balance, $new_balance, null, $description);
    
    return ['success' => true, 'new_balance' => $new_balance];
}

/**
 * Create patient credit balance table
 */
function createPatientCreditTable() {
    $sql = "CREATE TABLE IF NOT EXISTS `patient_credit_balance` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `pid` int(11) NOT NULL,
        `balance` decimal(10,2) NOT NULL DEFAULT 0.00,
        `created_date` datetime NOT NULL,
        `updated_date` datetime NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `pid` (`pid`),
        KEY `balance` (`balance`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    sqlStatement($sql);
}

/**
 * Get patient credit balance
 */
function getPatientCredit($input) {
    $pid = intval($input['pid'] ?? 0);
    
    if (!$pid) {
        sendJsonResponse(['success' => false, 'error' => 'Invalid patient ID'], 400);
    }
    
    createPatientCreditTable();
    
    $sql = "SELECT balance FROM patient_credit_balance WHERE pid = ?";
    $result = sqlStatement($sql, array($pid));
    $row = sqlFetchArray($result);
    
    $balance = $row ? floatval($row['balance']) : 0.00;
    
    sendJsonResponse([
        'success' => true,
        'balance' => $balance
    ]);
}

/**
 * Transfer credit between patients
 */
function transferCredit($input) {
    $from_pid = intval($input['from_pid'] ?? 0);
    $to_pid = intval($input['to_pid'] ?? 0);
    $amount = floatval($input['amount'] ?? 0);
    $reason = $input['reason'] ?? '';
    
    if (!$from_pid || !$to_pid || $amount <= 0) {
        sendJsonResponse(['success' => false, 'error' => 'Invalid input parameters'], 400);
    }
    
    if ($from_pid === $to_pid) {
        sendJsonResponse(['success' => false, 'error' => 'Cannot transfer to same patient'], 400);
    }
    
    createPatientCreditTable();
    
    // Start transaction
    sqlBeginTrans();
    
    try {
        // Check source patient balance
        $from_sql = "SELECT id, balance FROM patient_credit_balance WHERE pid = ?";
        $from_result = sqlStatement($from_sql, array($from_pid));
        $from_balance = sqlFetchArray($from_result);
        
        if (!$from_balance || $from_balance['balance'] < $amount) {
            throw new Exception('Insufficient credit balance for transfer');
        }
        
        // Deduct from source patient
        $new_from_balance = $from_balance['balance'] - $amount;
        $update_from_sql = "UPDATE patient_credit_balance SET balance = ?, updated_date = NOW() WHERE id = ?";
        sqlStatement($update_from_sql, array($new_from_balance, $from_balance['id']));
        
        // Add to destination patient
        $to_sql = "SELECT id, balance FROM patient_credit_balance WHERE pid = ?";
        $to_result = sqlStatement($to_sql, array($to_pid));
        $to_balance = sqlFetchArray($to_result);
        
        if ($to_balance) {
            $new_to_balance = $to_balance['balance'] + $amount;
            $update_to_sql = "UPDATE patient_credit_balance SET balance = ?, updated_date = NOW() WHERE id = ?";
            sqlStatement($update_to_sql, array($new_to_balance, $to_balance['id']));
        } else {
            $insert_to_sql = "INSERT INTO patient_credit_balance (pid, balance, created_date, updated_date) VALUES (?, ?, NOW(), NOW())";
            sqlStatement($insert_to_sql, array($to_pid, $amount));
        }
        
        // Record credit transfer
        recordCreditTransfer($from_pid, $to_pid, $amount, $reason);
        
        // Record credit transactions for both patients
        // For source patient: record as negative amount (transferred out)
        recordCreditTransaction($from_pid, 'transfer', -$amount, $from_balance['balance'], $new_from_balance, null, "Credit transferred to patient $to_pid: $reason");
        
        // For destination patient: record as positive amount (transferred in)
        $to_old_balance = $to_balance ? floatval($to_balance['balance']) : 0;
        $to_new_balance = $to_balance ? $new_to_balance : $amount;
        recordCreditTransaction($to_pid, 'transfer', $amount, $to_old_balance, $to_new_balance, null, "Credit received from patient $from_pid: $reason");
        
        // Commit transaction
        sqlCommitTrans();
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Credit transfer completed successfully',
            'from_balance' => $new_from_balance,
            'to_balance' => ($to_balance ? $new_to_balance : $amount)
        ]);
        
    } catch (Exception $e) {
        sqlRollbackTrans();
        sendJsonResponse(['success' => false, 'error' => 'Credit transfer failed: ' . $e->getMessage()], 500);
    }
}

/**
 * Record credit transaction
 */
function recordCreditTransaction($pid, $transaction_type, $amount, $balance_before, $balance_after, $receipt_number = null, $description = '') {
    // Create patient_credit_transactions table if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS `patient_credit_transactions` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `pid` int(11) NOT NULL,
        `transaction_type` enum('payment','refund','transfer') NOT NULL,
        `amount` decimal(10,2) NOT NULL,
        `balance_before` decimal(10,2) NOT NULL,
        `balance_after` decimal(10,2) NOT NULL,
        `receipt_number` varchar(50) DEFAULT NULL,
        `description` text,
        `created_at` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `pid` (`pid`),
        KEY `transaction_type` (`transaction_type`),
        KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    sqlStatement($sql);
    
    $transaction_sql = "INSERT INTO patient_credit_transactions (pid, transaction_type, amount, balance_before, balance_after, receipt_number, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
    sqlStatement($transaction_sql, array($pid, $transaction_type, $amount, $balance_before, $balance_after, $receipt_number, $description));
}

/**
 * Record credit transfer
 */
function recordCreditTransfer($from_pid, $to_pid, $amount, $reason) {
    // Create credit transfers table if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS `patient_credit_transfers` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `from_pid` int(11) NOT NULL,
        `to_pid` int(11) NOT NULL,
        `amount` decimal(10,2) NOT NULL,
        `reason` text,
        `created_date` datetime NOT NULL,
        `user_id` int(11) NOT NULL,
        PRIMARY KEY (`id`),
        KEY `from_pid` (`from_pid`),
        KEY `to_pid` (`to_pid`),
        KEY `created_date` (`created_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    sqlStatement($sql);
    
    $transfer_sql = "INSERT INTO patient_credit_transfers (from_pid, to_pid, amount, reason, created_date, user_id) VALUES (?, ?, ?, ?, NOW(), ?)";
    $user_id = $_SESSION['authUserID'] ?? 1;
    
    sqlStatement($transfer_sql, array($from_pid, $to_pid, $amount, $reason, $user_id));
}

/**
 * Get refund history for a patient
 */
function getRefundHistory($input) {
    $pid = intval($input['pid'] ?? 0);
    
    if (!$pid) {
        sendJsonResponse(['success' => false, 'error' => 'Invalid patient ID'], 400);
    }
    
    createRefundsTable();
    
    $sql = "SELECT 
                r.*,
                u.fname as user_fname,
                u.lname as user_lname
            FROM pos_refunds r
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.pid = ?" . refundPosFacilityFilter('r') . "
            ORDER BY r.created_date DESC";
    
    $result = sqlStatement($sql, array($pid));
    $refunds = array();
    
    while ($row = sqlFetchArray($result)) {
        $refunds[] = array(
            'refund_number' => $row['refund_number'],
            'drug_name' => $row['drug_name'],
            'lot_number' => $row['lot_number'],
            'refund_quantity' => normalizeRefundQuantity($row['refund_quantity']),
            'refund_amount' => floatval($row['refund_amount']),
            'refund_type' => $row['refund_type'],
            'original_receipt' => $row['original_receipt'],
            'reason' => $row['reason'],
            'created_date' => $row['created_date'],
            'user_name' => $row['user_fname'] . ' ' . $row['user_lname']
        );
    }
    
    sendJsonResponse([
        'success' => true,
        'refunds' => $refunds,
        'count' => count($refunds)
    ]);
}

/**
 * Process multi-product refund
 */
function processMultiRefund($input) {
    $pid = intval($input['pid'] ?? 0);
    $selected_items = $input['selected_items'] ?? array();
    $refund_type = $input['refund_type'] ?? 'payment';
    $reason = $input['reason'] ?? '';
    
    if (!$pid) {
        sendJsonResponse(['success' => false, 'error' => 'Invalid patient ID'], 400);
    }
    
    if (empty($selected_items)) {
        sendJsonResponse(['success' => false, 'error' => 'No items selected for refund'], 400);
    }
    
    try {
        // Start transaction
        sqlBeginTrans();
        
        $total_refund_amount = 0;
        $processed_items = array();
        
        foreach ($selected_items as $item) {
            $drug_id = intval($item['drug_id']);
            $selected_quantity = normalizeRefundQuantity($item['selected_quantity']);
            $max_quantity = normalizeRefundQuantity($item['max_quantity']);
            $refundable_amount = floatval($item['refundable_amount']);
            
            if ($selected_quantity <= 0 || $selected_quantity > $max_quantity) {
                throw new Exception("Invalid quantity for product ID: $drug_id");
            }
            
            // Use the refund amount calculated by the frontend
            // The frontend has already calculated the correct refund amount based on selected quantity
            $item_refund_amount = floatval($item['refundable_amount']);
            $total_refund_amount += $item_refund_amount;
            
            error_log("processMultiRefund - Item calculation: drug_id=$drug_id, selected_qty=$selected_quantity, max_qty=$max_quantity, frontend_refund_amount=$item_refund_amount, total_so_far=$total_refund_amount");
            
            // Find the remaining dispense record for this drug
            $remaining_sql = "SELECT * FROM pos_remaining_dispense WHERE pid = ? AND drug_id = ? AND remaining_quantity > 0 ORDER BY created_date ASC";
            error_log("processMultiRefund - SQL Query: $remaining_sql with params: pid=$pid, drug_id=$drug_id");
            $remaining_result = sqlStatement($remaining_sql, array($pid, $drug_id));
            
            $quantity_to_deduct = $selected_quantity;
            
            while ($remaining_row = sqlFetchArray($remaining_result)) {
                if ($quantity_to_deduct <= 0) {
                    break;
                }
                
                error_log("processMultiRefund - Processing row: " . json_encode($remaining_row));
                
                // Ensure we have a valid ID
                if (empty($remaining_row['id'])) {
                    error_log("processMultiRefund - Skipping row with empty ID");
                    continue; // Skip this row if no valid ID
                }
                
                $available_quantity = normalizeRefundQuantity($remaining_row['remaining_quantity']);
                $deduct_quantity = min($available_quantity, $quantity_to_deduct);
                
                // Update remaining dispense
                $new_remaining = $available_quantity - $deduct_quantity;
                $update_sql = "UPDATE pos_remaining_dispense SET remaining_quantity = ?, last_updated = NOW() WHERE id = ?";
                error_log("processMultiRefund - Executing UPDATE: $update_sql with params: new_remaining=$new_remaining, id={$remaining_row['id']}");
                $update_result = sqlStatement($update_sql, array($new_remaining, $remaining_row['id']));
                if (!$update_result) {
                    throw new Exception("Failed to update remaining dispense record ID: {$remaining_row['id']}");
                }
                
                $quantity_to_deduct -= $deduct_quantity;
            }
            
            if ($quantity_to_deduct > 0) {
                throw new Exception("Insufficient remaining quantity for product ID: $drug_id");
            }
            
            $processed_items[] = array(
                'drug_id' => $drug_id,
                'product_name' => $item['product_name'],
                'quantity' => $selected_quantity,
                'refund_amount' => $item_refund_amount
            );
        }
        
        // Process refund based on type
        if ($refund_type === 'credit') {
            error_log("processMultiRefund - Processing credit refund: total_refund_amount=$total_refund_amount");
            
            // Add to patient credit balance
            $credit_sql = "SELECT * FROM patient_credit_balance WHERE pid = ?";
            $credit_result = sqlStatement($credit_sql, array($pid));
            $credit_balance = sqlFetchArray($credit_result);
            
            $old_balance = $credit_balance ? floatval($credit_balance['balance']) : 0;
            $new_balance = $old_balance + $total_refund_amount;
            
            if ($credit_balance) {
                error_log("processMultiRefund - Updating credit balance: old_balance=$old_balance, new_balance=$new_balance");
                $update_credit_sql = "UPDATE patient_credit_balance SET balance = ?, updated_date = NOW() WHERE id = ?";
                sqlStatement($update_credit_sql, array($new_balance, $credit_balance['id']));
            } else {
                error_log("processMultiRefund - Creating new credit balance: amount=$total_refund_amount");
                $insert_credit_sql = "INSERT INTO patient_credit_balance (pid, balance, created_date, updated_date) VALUES (?, ?, NOW(), NOW())";
                sqlStatement($insert_credit_sql, array($pid, $total_refund_amount));
            }
            
            // Record credit transaction
            $description = "Multi-product refund credit: " . ($reason ?: 'Dispense remaining refund');
            recordCreditTransaction($pid, 'refund', $total_refund_amount, $old_balance, $new_balance, null, $description);
        }
        
        // Record refund in database
        createRefundsTable();
        
        foreach ($processed_items as $item) {
            $refund_number = generateRefundNumber();
            $refund_sql = "INSERT INTO pos_refunds (
                refund_number, pid, drug_id, drug_name, lot_number, refund_quantity, 
                refund_amount, refund_type, original_receipt, reason, created_date, user_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)";
            
            $user_id = $_SESSION['authUserID'] ?? 1;
            sqlStatement($refund_sql, array(
                $refund_number,
                $pid,
                $item['drug_id'],
                $item['product_name'],
                'MULTI-REFUND',
                $item['quantity'],
                $item['refund_amount'],
                $refund_type,
                'MULTI-REFUND',
                $reason,
                $user_id
            ));
        }
        
        // Commit transaction
        sqlCommitTrans();
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Multi-product refund processed successfully',
            'total_refund_amount' => $total_refund_amount,
            'processed_items' => $processed_items,
            'refund_type' => $refund_type
        ]);
        
    } catch (Exception $e) {
        sqlRollbackTrans();
        sendJsonResponse(['success' => false, 'error' => 'Multi-product refund failed: ' . $e->getMessage()], 500);
    }
}

/**
 * Generate unique refund number
 */
function generateRefundNumber() {
    $prefix = 'REF';
    $timestamp = date('YmdHis');
    $random = mt_rand(1000, 9999);
    return $prefix . $timestamp . $random;
}

/**
 * Create POS tables if they don't exist
 */
function createPosTables() {
    try {
        error_log("createPosTables - Starting table creation...");
        
        // Check if pos_transactions table exists
        $check_transactions_sql = "SHOW TABLES LIKE 'pos_transactions'";
        $check_result = sqlStatement($check_transactions_sql);
        $table_exists = sqlFetchArray($check_result);
        
        if (!$table_exists) {
            error_log("pos_transactions table does not exist, creating it...");
            
            // Create a simpler pos_transactions table first
            $transactions_sql = "CREATE TABLE pos_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                receipt_number VARCHAR(50),
                pid INT,
                total_amount DECIMAL(10,2),
                payment_method VARCHAR(20),
                payment_status VARCHAR(20),
                credit_amount DECIMAL(10,2),
                created_date DATETIME,
                user_id INT
            )";
            
            $result = sqlStatement($transactions_sql);
            if (!$result) {
                error_log("Failed to create pos_transactions table: " . sqlError());
                throw new Exception("Failed to create pos_transactions table: " . sqlError());
            } else {
                error_log("pos_transactions table created successfully");
            }
        } else {
            error_log("pos_transactions table already exists");
        }
        
        // Check if pos_transaction_items table exists
        $check_items_sql = "SHOW TABLES LIKE 'pos_transaction_items'";
        $check_items_result = sqlStatement($check_items_sql);
        $items_table_exists = sqlFetchArray($check_items_result);
        
        if (!$items_table_exists) {
            error_log("pos_transaction_items table does not exist, creating it...");
            
            // Create pos_transaction_items table
            $items_sql = "CREATE TABLE pos_transaction_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_id INT NOT NULL,
                drug_id VARCHAR(50) NOT NULL,
                drug_name VARCHAR(255) NOT NULL,
                quantity INT NOT NULL,
                dispense_quantity INT DEFAULT 0,
                administer_quantity INT DEFAULT 0,
                unit_price DECIMAL(10,2) NOT NULL,
                total_price DECIMAL(10,2) NOT NULL,
                lot_number VARCHAR(50),
                created_date DATETIME NOT NULL,
                INDEX idx_transaction (transaction_id)
            )";
            
            $result = sqlStatement($items_sql);
            if (!$result) {
                error_log("Failed to create pos_transaction_items table: " . sqlError());
                throw new Exception("Failed to create pos_transaction_items table: " . sqlError());
            } else {
                error_log("pos_transaction_items table created successfully");
            }
        } else {
            error_log("pos_transaction_items table already exists");
        }
        
    } catch (Exception $e) {
        error_log("Error creating POS tables: " . $e->getMessage());
        throw $e;
    }
    
    error_log("createPosTables - Table creation completed");
}

/**
 * Process credit payment for POS purchases
 */
function processCreditPayment($input) {
    $pid = intval($input['pid'] ?? 0);
    $amount = floatval($input['amount'] ?? 0);
    $credit_amount = floatval($input['credit_amount'] ?? 0);
    $items = $input['items'] ?? array();
    
    if (!$pid || $amount <= 0 || $credit_amount <= 0) {
        sendJsonResponse(['success' => false, 'error' => 'Invalid payment parameters'], 400);
    }
    
    if (empty($items)) {
        sendJsonResponse(['success' => false, 'error' => 'No items in cart'], 400);
    }
    
    try {
        // Create tables if they don't exist
        error_log("processCreditPayment - Creating POS tables...");
        try {
            createPosTables();
            error_log("processCreditPayment - POS tables created successfully");
        } catch (Exception $e) {
            error_log("processCreditPayment - Error in createPosTables: " . $e->getMessage());
            throw $e;
        }
        
        // Begin transaction
        sqlBeginTrans();
        
        // Check if patient has sufficient credit balance
        $credit_sql = "SELECT * FROM patient_credit_balance WHERE pid = ?";
        $credit_result = sqlStatement($credit_sql, array($pid));
        $credit_balance = sqlFetchArray($credit_result);
        
        if (!$credit_balance) {
            throw new Exception('Patient has no credit balance');
        }
        
        $current_balance = floatval($credit_balance['balance']);
        if ($current_balance < $credit_amount) {
            throw new Exception('Insufficient credit balance');
        }
        
        // Deduct credit amount from patient's balance
        $new_balance = $current_balance - $credit_amount;
        $update_credit_sql = "UPDATE patient_credit_balance SET balance = ?, updated_date = NOW() WHERE id = ?";
        $update_result = sqlStatement($update_credit_sql, array($new_balance, $credit_balance['id']));
        
        if (!$update_result) {
            throw new Exception('Failed to update credit balance');
        }
        
        // Generate receipt number
        $receipt_number = 'RCPT-' . date('Ymd') . '-' . mt_rand(1000, 9999);
        

        
        // Check if table exists and log its structure
        $table_check_sql = "SHOW TABLES LIKE 'pos_transactions'";
        $table_check_result = sqlStatement($table_check_sql);
        $table_exists = sqlFetchArray($table_check_result);
        
        if (!$table_exists) {
            error_log("ERROR: pos_transactions table does not exist after createPosTables() call");
            throw new Exception('pos_transactions table does not exist');
        } else {
            error_log("pos_transactions table exists, checking structure...");
            
            // Check table structure
            $structure_sql = "DESCRIBE pos_transactions";
            $structure_result = sqlStatement($structure_sql);
            $columns = array();
            while ($row = sqlFetchArray($structure_result)) {
                $columns[] = $row['Field'];
            }
            error_log("pos_transactions table columns: " . implode(', ', $columns));
        }
        
        // Record the transaction using existing table structure
        $user_id = $_SESSION['authUserID'] ?? 1;
        $items_json = json_encode($items);
        $insertColumns = ['receipt_number', 'pid', 'transaction_type', 'payment_method', 'amount', 'items', 'created_date', 'user_id'];
        $params = [$receipt_number, $pid, 'credit_payment', 'credit', $amount, $items_json, date('Y-m-d H:i:s'), $user_id];
        if (refundPosHasColumn('facility_id')) {
            $insertColumns[] = 'facility_id';
            $params[] = refundPosFacilityId($pid);
        }
        
        error_log("Attempting to insert transaction with params: " . json_encode($params));
        
        $transaction_result = sqlStatement(
            "INSERT INTO pos_transactions (" . implode(', ', $insertColumns) . ")
             VALUES (" . implode(', ', array_fill(0, count($insertColumns), '?')) . ")",
            $params
        );
        
        if (!$transaction_result) {
            $error = sqlError();
            error_log("Failed to record transaction: " . $error);
            throw new Exception('Failed to record transaction: ' . $error);
        }
        

        
        // Commit transaction
        sqlCommitTrans();
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Credit payment processed successfully',
            'receipt_number' => $receipt_number,
            'credit_amount' => $credit_amount,
            'remaining_balance' => $new_balance
        ]);
        
    } catch (Exception $e) {
        sqlRollbackTrans();
        sendJsonResponse(['success' => false, 'error' => 'Credit payment failed: ' . $e->getMessage()], 500);
    }
}
?>
