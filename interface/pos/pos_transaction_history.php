<?php
/**
 * POS Transaction History AJAX Handler
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

function posHistoryHasColumn($table, $column)
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', (string) $table);
    $row = sqlFetchArray(sqlStatement("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column]));
    return !empty($row);
}

function posHistoryHasActiveSiblingReceiptTransaction($row): bool
{
    $pid = (int) ($row['pid'] ?? 0);
    $receiptNumber = trim((string) ($row['receipt_number'] ?? ''));
    $transactionId = (int) ($row['id'] ?? 0);

    if ($pid <= 0 || $receiptNumber === '') {
        return false;
    }

    $sibling = sqlFetchArray(sqlStatement(
        "SELECT id
           FROM pos_transactions
          WHERE pid = ?
            AND receipt_number = ?
            AND transaction_type != 'void'
            AND COALESCE(voided, 0) = 0
            AND id != ?
          LIMIT 1",
        [$pid, $receiptNumber, $transactionId]
    ));

    return !empty($sibling);
}

function posHistoryCanVoidTransaction($row, $hasPermission)
{
    if (!$hasPermission) {
        return [false, xlt('Permission required')];
    }

    if (!empty($row['voided'])) {
        if (posHistoryHasActiveSiblingReceiptTransaction($row)) {
            return [true, ''];
        }

        return [false, xlt('Already voided')];
    }

    $ineligible = ['void', 'credit_for_remaining', 'transfer_in', 'transfer_out', 'medicine_switch'];
    $type = (string) ($row['transaction_type'] ?? '');
    if (in_array($type, $ineligible, true)) {
        return [false, xlt('Not eligible')];
    }

    $createdDate = (string) ($row['created_date'] ?? '');
    if ($createdDate === '' || date('Y-m-d', strtotime($createdDate)) !== date('Y-m-d')) {
        return [false, xlt('Same-day only')];
    }

    return [true, ''];
}

function posHistoryCanUndoBackdatedTransaction($row, $hasPermission)
{
    if (!$hasPermission) {
        return [false, xlt('Permission required')];
    }

    if (!empty($row['voided'])) {
        return [false, xlt('Already voided')];
    }

    $receiptNumber = (string) ($row['receipt_number'] ?? '');
    if (strpos($receiptNumber, 'BD-') !== 0) {
        return [false, ''];
    }

    $type = (string) ($row['transaction_type'] ?? '');
    if ($type === 'void') {
        return [false, xlt('Already reversed')];
    }

    return [true, ''];
}

// Get parameters from GET request
$pid = intval($_GET['pid'] ?? 0);
$drug_id = intval($_GET['drug_id'] ?? 0);
$lot_number = $_GET['lot_number'] ?? '';

// Allow testing without specific drug_id and lot_number
$test_mode = isset($_GET['test']) && $_GET['test'] == '1';

if (!$pid) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => xlt('Invalid patient ID')]);
    exit;
}

if (!$test_mode && (!$drug_id || !$lot_number)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => xlt('Invalid parameters')]);
    exit;
}

try {
    $has_void_columns = posHistoryHasColumn('pos_transactions', 'voided');
    $can_void_transactions = AclMain::aclCheckCore('acct', 'rep_a') || AclMain::aclCheckCore('admin', 'super');

    // Test database connection first
    $test_query = "SELECT 1 as test";
    try {
        $test_result = sqlStatement($test_query);
        $test_row = sqlFetchArray($test_result);
        error_log("POS Transaction History: Database connection test successful");
    } catch (Exception $e) {
        error_log("POS Transaction History: Database connection test failed: " . $e->getMessage());
        // Return empty results instead of failing
        echo json_encode(['success' => true, 'transactions' => [], 'count' => 0, 'debug' => ['connection_failed' => $e->getMessage()]]);
        exit;
    }
    
    // Check if pos_transactions table exists
    $table_check = sqlStatement("SHOW TABLES LIKE 'pos_transactions'");
    $table_exists = sqlFetchArray($table_check);
    if (!$table_exists) {
        // Create the pos_transactions table if it doesn't exist
        $create_sql = "CREATE TABLE IF NOT EXISTS `pos_transactions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `pid` int(11) NOT NULL,
            `receipt_number` varchar(50) NOT NULL,
            `transaction_type` varchar(50) NOT NULL DEFAULT 'dispense',
            `payment_method` varchar(50) DEFAULT NULL,
            `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
            `items` longtext NOT NULL,
            `created_date` datetime NOT NULL,
            `user_id` varchar(50) NOT NULL,
            `visit_type` varchar(10) NOT NULL DEFAULT '-',
            `price_override_notes` TEXT NULL,
            `patient_number` INT(11) NULL,
            `facility_id` INT(11) NULL,
            PRIMARY KEY (`id`),
            KEY `pid` (`pid`),
            KEY `receipt_number` (`receipt_number`),
            KEY `created_date` (`created_date`),
            KEY `transaction_type` (`transaction_type`),
            KEY `facility_id` (`facility_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        try {
            sqlStatement($create_sql);
            error_log("POS Transaction History: Created pos_transactions table successfully");
        } catch (Exception $e) {
            error_log("POS Transaction History: Failed to create pos_transactions table: " . $e->getMessage());
            // Instead of failing, just return empty results
            echo json_encode(['success' => true, 'transactions' => [], 'count' => 0, 'debug' => ['table_creation_failed' => $e->getMessage()]]);
            exit;
        }
    } else {
        error_log("POS Transaction History: pos_transactions table already exists");
    }
    
    // Double-check if table exists after creation attempt
    $table_check_after = sqlStatement("SHOW TABLES LIKE 'pos_transactions'");
    $table_exists_after = sqlFetchArray($table_check_after);
    if (!$table_exists_after) {
        error_log("POS Transaction History: Table still doesn't exist after creation attempt");
        echo json_encode(['success' => true, 'transactions' => [], 'count' => 0, 'debug' => ['table_exists' => false]]);
        exit;
    }
    
    if ($test_mode) {
        // Test mode: just get all transactions for the patient
        $query = "SELECT 
                    pt.id, pt.transaction_type, pt.amount, pt.receipt_number, 
                    pt.created_date, pt.user_id, pt.items," .
                    ($has_void_columns ? " pt.voided, pt.voided_at, pt.voided_by, pt.void_reason, " : " 0 AS voided, NULL AS voided_at, NULL AS voided_by, NULL AS void_reason, ") . "
                    COALESCE(CONCAT(TRIM(u.fname), ' ', TRIM(u.lname)), u.username, pt.user_id) AS user_display
                  FROM pos_transactions pt
                  LEFT JOIN users u ON (pt.user_id = u.username OR pt.user_id = CAST(u.id AS CHAR))
                  WHERE pt.pid = ?
                  ORDER BY pt.created_date DESC";
        
        try {
            $result = sqlStatement($query, array($pid));
            error_log("POS Transaction History: Test mode query executed successfully");
        } catch (Exception $e) {
            error_log("POS Transaction History: Test mode query failed: " . $e->getMessage());
            // Return empty results instead of failing
            echo json_encode(['success' => true, 'transactions' => [], 'count' => 0, 'debug' => ['error' => $e->getMessage()]]);
            exit;
        }
    } else {
        // Normal mode: get transactions for specific product/lot
        $query = "SELECT 
                    pt.id, pt.transaction_type, pt.amount, pt.receipt_number, 
                    pt.created_date, pt.user_id, pt.items," .
                    ($has_void_columns ? " pt.voided, pt.voided_at, pt.voided_by, pt.void_reason, " : " 0 AS voided, NULL AS voided_at, NULL AS voided_by, NULL AS void_reason, ") . "
                    COALESCE(CONCAT(TRIM(u.fname), ' ', TRIM(u.lname)), u.username, pt.user_id) AS user_display
                  FROM pos_transactions pt
                  LEFT JOIN users u ON (pt.user_id = u.username OR pt.user_id = CAST(u.id AS CHAR))
                  WHERE pt.pid = ?
                  ORDER BY pt.created_date DESC";
        
        try {
            $result = sqlStatement($query, array($pid));
            error_log("POS Transaction History: Normal mode query executed successfully");
            error_log("POS Transaction History - Query executed for pid=$pid, drug_id=$drug_id, lot_number=$lot_number");
        } catch (Exception $e) {
            error_log("POS Transaction History: Normal mode query failed: " . $e->getMessage());
            // Return empty results instead of failing
            echo json_encode(['success' => true, 'transactions' => [], 'count' => 0, 'debug' => ['error' => $e->getMessage()]]);
            exit;
        }
    }
    $transactions = array();
    
    // Debug: Check if we got any results
    $rowCount = 0;
    
    while ($row = sqlFetchArray($result)) {
        $items = json_decode($row['items'], true);
        
        // Skip if JSON decoding failed or items is not an array
        if (!is_array($items)) {
            continue;
        }
        
        $rowCount++;
        
        if ($test_mode) {
            // In test mode, show all items from all transactions
            foreach ($items as $item) {
                [$can_void, $void_block_reason] = posHistoryCanVoidTransaction($row, $can_void_transactions);
                [$can_undo_backdate, $undo_backdate_block_reason] = posHistoryCanUndoBackdatedTransaction($row, $can_void_transactions);
                                        $transactions[] = array(
                            'id' => $row['id'],
                            'transaction_type' => $row['transaction_type'],
                            'name' => $item['name'] ?? $item['display_name'] ?? 'Unknown Product',
                            'lot_number' => $item['lot_number'] ?? 'N/A',
                            'quantity' => intval($item['quantity'] ?? 0),
                            'dispense_quantity' => intval($item['dispense_quantity'] ?? 0),
                            'administer_quantity' => intval($item['administer_quantity'] ?? 0),
                            'total_amount' => floatval($row['amount']),
                            'receipt_number' => $row['receipt_number'],
                            'transaction_date' => $row['created_date'],
                            'notes' => '', // Not stored in current table structure
                            'created_by' => $row['user_display'] ?? $row['user_id'],
                            'drug_id' => $item['drug_id'] ?? 'unknown',
                            'is_remaining_dispense' => $item['is_remaining_dispense'] ?? false,
                            'is_different_lot_dispense' => $item['is_different_lot_dispense'] ?? false,
                            'has_remaining_dispense' => $item['has_remaining_dispense'] ?? false,
                            'pid' => $pid,
                            'voided' => !empty($row['voided']),
                            'voided_at' => $row['voided_at'] ?? null,
                            'voided_by' => $row['voided_by'] ?? null,
                            'void_reason' => $row['void_reason'] ?? null,
                            'can_void' => $can_void,
                            'void_block_reason' => $void_block_reason,
                            'can_undo_backdate' => $can_undo_backdate,
                            'undo_backdate_block_reason' => $undo_backdate_block_reason
                        );
            }
        } else {
            // Smart filtering: Show transactions for the specific lot, OR show transactions from other lots only when they were used as alternate lots
            foreach ($items as $item) {
                if (isset($item['drug_id']) && $item['drug_id'] == $drug_id) {
                    $itemLotNumber = $item['lot_number'] ?? 'N/A';
                    $isDifferentLotDispense = $item['is_different_lot_dispense'] ?? false;
                    
                    // Show transaction if it's from the same lot as requested
                    $shouldShow = ($itemLotNumber == $lot_number);
                    
                    if ($shouldShow) {
                        error_log("POS Transaction History - Found matching item: drug_id=" . $item['drug_id'] . ", lot=" . $itemLotNumber . ", name=" . ($item['name'] ?? 'Unknown') . ", is_alternate_lot=" . ($isDifferentLotDispense ? 'Yes' : 'No'));
                        [$can_void, $void_block_reason] = posHistoryCanVoidTransaction($row, $can_void_transactions);
                        [$can_undo_backdate, $undo_backdate_block_reason] = posHistoryCanUndoBackdatedTransaction($row, $can_void_transactions);
                        
                        $transactions[] = array(
                            'id' => $row['id'],
                            'transaction_type' => $row['transaction_type'],
                            'name' => $item['name'] ?? $item['display_name'] ?? 'Unknown Product',
                            'lot_number' => $itemLotNumber,
                            'quantity' => intval($item['quantity'] ?? 0),
                            'dispense_quantity' => intval($item['dispense_quantity'] ?? 0),
                            'administer_quantity' => intval($item['administer_quantity'] ?? 0),
                            'total_amount' => floatval($row['amount']),
                            'receipt_number' => $row['receipt_number'],
                            'transaction_date' => $row['created_date'],
                            'notes' => '', // Not stored in current table structure
                            'created_by' => $row['user_display'] ?? $row['user_id'],
                            'is_remaining_dispense' => $item['is_remaining_dispense'] ?? false,
                            'is_different_lot_dispense' => $isDifferentLotDispense,
                            'has_remaining_dispense' => $item['has_remaining_dispense'] ?? false,
                            'pid' => $pid,
                            'voided' => !empty($row['voided']),
                            'voided_at' => $row['voided_at'] ?? null,
                            'voided_by' => $row['voided_by'] ?? null,
                            'void_reason' => $row['void_reason'] ?? null,
                            'can_void' => $can_void,
                            'void_block_reason' => $void_block_reason,
                            'can_undo_backdate' => $can_undo_backdate,
                            'undo_backdate_block_reason' => $undo_backdate_block_reason
                        );
                    } else {
                        error_log("POS Transaction History - Skipping item: drug_id=" . $item['drug_id'] . ", lot=" . $itemLotNumber . " (not requested lot and not alternate lot dispense)");
                    }
                }
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'transactions' => $transactions,
        'count' => count($transactions),
        'debug' => [
            'total_rows_found' => $rowCount,
            'drug_id_requested' => $drug_id,
            'lot_number_requested' => $lot_number
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching transaction history: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => xlt('Database error occurred')]);
}
?> 
