<?php
/**
 * Patient Transaction History - Comprehensive Lifetime View
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Team
 * @copyright Copyright (c) 2024 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once("$srcdir/patient.inc");

use OpenEMR\Core\Header;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

// Ensure user is logged in
if (!isset($_SESSION['authUserID'])) {
    die(xlt('Not authorized'));
}

$pid = $_GET['pid'] ?? 0;
if (!$pid) {
    die(xlt('Patient ID required'));
}

$patient_data = getPatientData($pid, 'fname,mname,lname,pubpid,DOB,phone_home,phone_cell,email,sex');
if (!$patient_data) {
    die(xlt('Patient not found'));
}

function patientTransactionHistoryHasColumn($table, $column)
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', (string) $table);
    $row = sqlFetchArray(sqlStatement("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column]));
    return !empty($row);
}

function patientTransactionHistoryHasActiveSiblingReceiptTransaction($row): bool
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

function patientTransactionHistoryCanVoid($row, $hasPermission)
{
    if (!$hasPermission) {
        return [false, xlt('Permission required')];
    }

    if (!empty($row['voided'])) {
        if (patientTransactionHistoryHasActiveSiblingReceiptTransaction($row)) {
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

function patientTransactionHistoryCanUndoBackdated($row, $hasPermission)
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

function patientTransactionHistoryIsBackdatedReceipt($receiptNumber)
{
    return strpos((string) $receiptNumber, 'BD-') === 0;
}

function patientTransactionHistoryDecodeItems($rawItems)
{
    if (empty($rawItems)) {
        return [];
    }

    $decoded = json_decode((string) $rawItems, true);
    return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
}

function patientTransactionHistoryReceiptFallbackRows($pid, $date_from, $date_to, $payment_method)
{
    $query = "SELECT pt.id, pt.receipt_number, pt.created_date, pt.amount, pt.payment_method, pt.items,
                     COALESCE(CONCAT(TRIM(u.fname), ' ', TRIM(u.lname)), u.username, pt.user_id) AS created_by
              FROM pos_transactions AS pt
              LEFT JOIN users u ON (pt.user_id = u.username OR pt.user_id = CAST(u.id AS CHAR))
              WHERE pt.pid = ?
                AND pt.transaction_type IN ('external_payment', 'payment', 'credit_payment')
                AND pt.amount > 0";

    $params = [$pid];
    if ($date_from && $date_to) {
        $query .= " AND DATE(pt.created_date) BETWEEN ? AND ?";
        $params[] = $date_from;
        $params[] = $date_to;
    }
    if ($payment_method !== 'all') {
        $query .= " AND pt.payment_method = ?";
        $params[] = $payment_method;
    }

    $query .= " ORDER BY pt.created_date DESC, pt.id DESC";

    $result = sqlStatement($query, $params);
    $rows = [];
    while ($row = sqlFetchArray($result)) {
        if (!is_array($row)) {
            continue;
        }

        $receiptNumber = trim((string) ($row['receipt_number'] ?? ''));
        $items = [];

        if ($receiptNumber !== '') {
            $itemResult = sqlStatement(
                "SELECT items
                 FROM pos_transactions
                 WHERE pid = ? AND receipt_number = ?
                 ORDER BY created_date DESC, id DESC",
                [$pid, $receiptNumber]
            );
            while ($itemRow = sqlFetchArray($itemResult)) {
                foreach (patientTransactionHistoryDecodeItems($itemRow['items'] ?? '') as $item) {
                    $items[] = $item;
                }
            }
        }

        if (empty($items)) {
            $items = patientTransactionHistoryDecodeItems($row['items'] ?? '');
        }

        $row['receipt_data'] = json_encode(['items' => $items]);
        $rows[] = $row;
    }

    return $rows;
}

function patientTransactionHistoryRenderPosAction(array $txn, $pid): string
{
    ob_start();

    if (!empty($txn['can_undo_backdate'])) {
        echo '<div class="void-action-wrap">';
        echo '<button type="button" class="btn btn-sm btn-void" style="background:#0f766e; border-color:#0f766e;" title="' . attr(xl('Backdated receipt. Use Undo Backdate to reverse the saved backdated tracking entry.')) . '" onclick="undoBackdatedTransaction(' . attr(json_encode((string) ($txn['receipt_number'] ?? ''))) . ', ' . attr((string) ($txn['pid'] ?? $pid)) . ', this)">' . xlt('Undo Backdate') . '</button>';
        echo '<div class="void-note">' . xlt('Backdated receipt. Same-day rule does not apply.') . '</div>';
        echo '</div>';
    } elseif (!empty($txn['can_void'])) {
        echo '<div class="void-action-wrap">';
        echo '<button type="button" class="btn btn-sm btn-void" title="' . attr(xl('Same-day only. Reverses POS sale and restocks inventory.')) . '" onclick="voidPatientTransaction(' . attr((string) ($txn['id'] ?? '')) . ', ' . attr((string) ($txn['pid'] ?? $pid)) . ', this)">' . xlt('Void') . '</button>';
        if (!empty($txn['voided'])) {
            echo '<div class="void-note">' . xlt('Completes the remaining split-payment void.') . '</div>';
        } else {
            echo '<div class="void-note">' . xlt('Same-day only. Reverses sale and restocks inventory.') . '</div>';
        }
        echo '</div>';
    } elseif (!empty($txn['voided'])) {
        echo '<div class="void-action-wrap">';
        echo '<span class="badge badge-danger">' . xlt('VOIDED') . '</span>';
        if (!empty($txn['voided_at'])) {
            echo '<div class="void-note">' . text(date('m/d/Y H:i', strtotime($txn['voided_at']))) . '</div>';
        }
        if (!empty($txn['void_reason'])) {
            echo '<div class="void-note">' . text($txn['void_reason']) . '</div>';
        }
        echo '</div>';
    } elseif (!empty($txn['undo_backdate_block_reason']) || !empty($txn['void_block_reason'])) {
        echo '<div class="void-action-wrap">';
        echo '<span class="badge badge-info">' . xlt('Unavailable') . '</span>';
        echo '<div class="void-note">' . text($txn['undo_backdate_block_reason'] ?: $txn['void_block_reason']) . '</div>';
        echo '</div>';
    } else {
        echo text('POS Transaction');
    }

    return (string) ob_get_clean();
}

function patientTransactionHistoryFormatPaymentMethodLabel($method): string
{
    $method = trim((string) $method);
    if ($method === '') {
        return 'Unknown';
    }

    if (strtolower($method) === 'split') {
        return 'Split';
    }

    return ucwords(str_replace('_', ' ', strtolower($method)));
}

function patientTransactionHistoryResolveReceiptPaymentMethod($receiptData, $fallbackMethod = ''): string
{
    $individualPayments = isset($receiptData['individual_payments']) && is_array($receiptData['individual_payments'])
        ? $receiptData['individual_payments']
        : [];

    $methods = [];
    foreach ($individualPayments as $payment) {
        $method = strtolower(trim((string) ($payment['method'] ?? '')));
        if ($method === '' || $method === 'unknown' || $method === 'n/a') {
            continue;
        }
        $methods[] = $method;
    }

    $methods = array_values(array_unique($methods));
    if (count($methods) > 1) {
        return 'Split';
    }

    if (count($methods) === 1) {
        return patientTransactionHistoryFormatPaymentMethodLabel($methods[0]);
    }

    return patientTransactionHistoryFormatPaymentMethodLabel($fallbackMethod);
}

function patientTransactionHistoryResolveReceiptPaymentMethodLive($pid, $receiptNumber, $receiptData, $fallbackMethod = ''): string
{
    $pid = (int) $pid;
    $receiptNumber = trim((string) $receiptNumber);
    $methods = [];

    if ($pid > 0 && $receiptNumber !== '') {
        $paymentRows = sqlStatement(
            "SELECT payment_method, amount
               FROM pos_transactions
              WHERE pid = ? AND receipt_number = ?
              ORDER BY created_date ASC, id ASC",
            [$pid, $receiptNumber]
        );

        while ($row = sqlFetchArray($paymentRows)) {
            $method = strtolower(trim((string) ($row['payment_method'] ?? '')));
            $amount = round((float) ($row['amount'] ?? 0), 2);

            if ($amount <= 0 || $method === '' || $method === 'unknown' || $method === 'n/a') {
                continue;
            }

            $methods[] = $method;
        }
    }

    $methods = array_values(array_unique($methods));
    if (count($methods) > 1) {
        return 'Split';
    }

    if (count($methods) === 1) {
        return patientTransactionHistoryFormatPaymentMethodLabel($methods[0]);
    }

    return patientTransactionHistoryResolveReceiptPaymentMethod($receiptData, $fallbackMethod);
}

function patientTransactionHistoryResolveReceiptPaymentTotalLive($pid, $receiptNumber, $fallbackAmount = 0.0): float
{
    $pid = (int) $pid;
    $receiptNumber = trim((string) $receiptNumber);
    $total = 0.0;

    if ($pid > 0 && $receiptNumber !== '') {
        $paymentRows = sqlStatement(
            "SELECT amount
               FROM pos_transactions
              WHERE pid = ?
                AND receipt_number = ?
                AND transaction_type IN ('external_payment', 'payment', 'credit_payment')
                AND amount > 0
              ORDER BY created_date ASC, id ASC",
            [$pid, $receiptNumber]
        );

        while ($row = sqlFetchArray($paymentRows)) {
            $total += round((float) ($row['amount'] ?? 0), 2);
        }
    }

    if ($total > 0) {
        return round($total, 2);
    }

    return round((float) $fallbackAmount, 2);
}

function patientTransactionHistoryResolveReceiptPaymentRowsLive($pid, $receiptNumber, $receiptData = [], $fallbackCreatedDate = '', $fallbackMethod = '', $fallbackAmount = 0.0): array
{
    $pid = (int) $pid;
    $receiptNumber = trim((string) $receiptNumber);
    $paymentRows = [];

    if ($pid > 0 && $receiptNumber !== '') {
        $result = sqlStatement(
            "SELECT created_date, payment_method, amount
               FROM pos_transactions
              WHERE pid = ?
                AND receipt_number = ?
                AND transaction_type IN ('external_payment', 'payment', 'credit_payment')
                AND amount > 0
              ORDER BY created_date ASC, id ASC",
            [$pid, $receiptNumber]
        );

        while ($row = sqlFetchArray($result)) {
            $method = trim((string) ($row['payment_method'] ?? ''));
            $amount = round((float) ($row['amount'] ?? 0), 2);

            if ($amount <= 0) {
                continue;
            }

            $paymentRows[] = [
                'created_date' => (string) ($row['created_date'] ?? $fallbackCreatedDate),
                'method' => $method !== '' ? $method : $fallbackMethod,
                'amount' => $amount,
            ];
        }
    }

    if (!empty($paymentRows)) {
        return $paymentRows;
    }

    $storedPayments = isset($receiptData['individual_payments']) && is_array($receiptData['individual_payments'])
        ? $receiptData['individual_payments']
        : [];

    foreach ($storedPayments as $payment) {
        $amount = round((float) ($payment['amount'] ?? 0), 2);
        if ($amount <= 0) {
            continue;
        }

        $paymentRows[] = [
            'created_date' => (string) ($payment['created_date'] ?? $fallbackCreatedDate),
            'method' => (string) ($payment['method'] ?? $fallbackMethod),
            'amount' => $amount,
        ];
    }

    if (!empty($paymentRows)) {
        return $paymentRows;
    }

    $fallbackAmount = round((float) $fallbackAmount, 2);
    if ($fallbackAmount > 0) {
        return [[
            'created_date' => (string) $fallbackCreatedDate,
            'method' => (string) $fallbackMethod,
            'amount' => $fallbackAmount,
        ]];
    }

    return [];
}

function patientTransactionHistoryResolveReceiptChargeTotal($receiptData, $fallbackAmount = 0.0): float
{
    $receiptData = is_array($receiptData) ? $receiptData : [];
    $items = isset($receiptData['items']) && is_array($receiptData['items']) ? $receiptData['items'] : [];
    $total = 0.0;

    foreach ($items as $item) {
        $qty = (float) ($item['quantity'] ?? 1);
        if (isset($item['total']) && is_numeric($item['total'])) {
            $lineTotal = (float) $item['total'];
        } elseif (isset($item['line_total']) && is_numeric($item['line_total'])) {
            $lineTotal = (float) $item['line_total'];
        } else {
            $lineTotal = (float) ($item['price'] ?? 0) * $qty;
        }

        $total += $lineTotal;
    }

    $total += round((float) ($receiptData['tax_total'] ?? 0), 2);

    if ($total > 0) {
        return round($total, 2);
    }

    return round((float) $fallbackAmount, 2);
}

function patientTransactionHistoryGetReceiptFinancialSummary($pid, $date_from, $date_to, $payment_method): array
{
    $query = "SELECT receipt_number, amount, payment_method, receipt_data
                FROM pos_receipts
               WHERE pid = ?";
    $params = [$pid];

    if ($date_from && $date_to) {
        $query .= " AND DATE(created_date) BETWEEN ? AND ?";
        $params[] = $date_from;
        $params[] = $date_to;
    }

    $query .= " ORDER BY created_date DESC";

    $result = sqlStatement($query, $params);
    $count = 0;
    $chargeTotal = 0.0;
    $paymentTotal = 0.0;

    while ($row = sqlFetchArray($result)) {
        if (!is_array($row)) {
            continue;
        }

        $receiptData = json_decode((string) ($row['receipt_data'] ?? ''), true);
        if (!is_array($receiptData)) {
            $receiptData = [];
        }

        $resolvedMethod = strtolower(trim(patientTransactionHistoryResolveReceiptPaymentMethodLive(
            $pid,
            (string) ($row['receipt_number'] ?? ''),
            $receiptData,
            $row['payment_method'] ?? 'Unknown'
        )));

        if ($payment_method !== 'all' && $resolvedMethod !== strtolower(trim((string) $payment_method))) {
            continue;
        }

        $count++;
        $chargeTotal += patientTransactionHistoryResolveReceiptChargeTotal($receiptData, (float) ($row['amount'] ?? 0));
        $paymentTotal += patientTransactionHistoryResolveReceiptPaymentTotalLive(
            $pid,
            (string) ($row['receipt_number'] ?? ''),
            (float) ($row['amount'] ?? 0)
        );
    }

    return [
        'count' => $count,
        'charges' => round($chargeTotal, 2),
        'payments' => round($paymentTotal, 2),
    ];
}

function patientTransactionHistoryResolveReceiptItemsLive($pid, $receiptNumber, $receiptData = []): array
{
    $pid = (int) $pid;
    $receiptNumber = trim((string) $receiptNumber);
    $receiptData = is_array($receiptData) ? $receiptData : [];
    $items = isset($receiptData['items']) && is_array($receiptData['items']) ? $receiptData['items'] : [];

    if (!empty($items)) {
        return $items;
    }

    if ($pid <= 0 || $receiptNumber === '') {
        return [];
    }

    $result = sqlStatement(
        "SELECT items
           FROM pos_transactions
          WHERE pid = ?
            AND receipt_number = ?
          ORDER BY created_date DESC, id DESC",
        [$pid, $receiptNumber]
    );

    while ($row = sqlFetchArray($result)) {
        $decodedItems = patientTransactionHistoryDecodeItems($row['items'] ?? '');
        if (!empty($decodedItems)) {
            return $decodedItems;
        }
    }

    return [];
}

function patientTransactionHistoryProductTotals($pid, $date_from, $date_to)
{
    $productTotals = [];

    $receiptRows = [];
    $receiptResult = sqlStatement(
        "SELECT receipt_number, created_date, receipt_data
           FROM pos_receipts
          WHERE pid = ?
          ORDER BY created_date DESC",
        [$pid]
    );

    while ($row = sqlFetchArray($receiptResult)) {
        if (is_array($row)) {
            $receiptRows[] = $row;
        }
    }

    if (empty($receiptRows)) {
        $receiptRows = patientTransactionHistoryReceiptFallbackRows($pid, $date_from, $date_to, 'all');
    }

    foreach ($receiptRows as $row) {
        $rowDate = date('Y-m-d', strtotime((string) ($row['created_date'] ?? '')));
        if ($date_from && $date_to && ($rowDate < $date_from || $rowDate > $date_to)) {
            continue;
        }

        $receiptData = json_decode((string) ($row['receipt_data'] ?? ''), true);
        if (!is_array($receiptData)) {
            $receiptData = [];
        }

        $items = patientTransactionHistoryResolveReceiptItemsLive(
            $pid,
            (string) ($row['receipt_number'] ?? ''),
            $receiptData
        );

        foreach ($items as $item) {
            $name = trim((string) ($item['name'] ?? $item['display_name'] ?? 'Unknown Item'));
            if ($name === '') {
                $name = 'Unknown Item';
            }

            if (!isset($productTotals[$name])) {
                $productTotals[$name] = [
                    'total_quantity' => 0,
                    'total_amount' => 0,
                    'lot_number' => (string) ($item['lot_number'] ?? 'N/A'),
                    'last_purchase_date' => $row['created_date'],
                ];
            }

            $qty = (float) ($item['quantity'] ?? 1);
            $price = (float) ($item['price'] ?? 0);
            if ($price <= 0 && isset($item['total']) && $qty > 0) {
                $price = (float) $item['total'] / $qty;
            }
            $productTotals[$name]['total_quantity'] += $qty;
            $productTotals[$name]['total_amount'] += ($price * $qty);
            $productTotals[$name]['last_purchase_date'] = $row['created_date'];
        }
    }

    if (!empty($productTotals)) {
        return $productTotals;
    }

    $txnQuery = "SELECT created_date, items
                 FROM pos_transactions
                 WHERE pid = ?
                   AND transaction_type IN ('dispense', 'purchase', 'purchase_and_dispens', 'purchase_and_alterna', 'administer')
                 ORDER BY created_date DESC";
    $txnParams = [$pid];
    if ($date_from && $date_to) {
        $txnQuery = "SELECT created_date, items
                     FROM pos_transactions
                     WHERE pid = ?
                       AND transaction_type IN ('dispense', 'purchase', 'purchase_and_dispens', 'purchase_and_alterna', 'administer')
                       AND DATE(created_date) BETWEEN ? AND ?
                     ORDER BY created_date DESC";
        $txnParams[] = $date_from;
        $txnParams[] = $date_to;
    }

    $txnResult = sqlStatement($txnQuery, $txnParams);
    while ($row = sqlFetchArray($txnResult)) {
        foreach (patientTransactionHistoryDecodeItems($row['items'] ?? '') as $item) {
            $name = trim((string) ($item['name'] ?? $item['display_name'] ?? 'Unknown Item'));
            if ($name === '') {
                $name = 'Unknown Item';
            }

            if (!isset($productTotals[$name])) {
                $productTotals[$name] = [
                    'total_quantity' => 0,
                    'total_amount' => 0,
                    'lot_number' => (string) ($item['lot_number'] ?? 'N/A'),
                    'last_purchase_date' => $row['created_date'],
                ];
            }

            $qty = (float) ($item['quantity'] ?? 1);
            $price = (float) ($item['price'] ?? 0);
            if ($price <= 0 && isset($item['total'])) {
                $price = (float) $item['total'] / max($qty, 1);
            }

            $productTotals[$name]['total_quantity'] += $qty;
            $productTotals[$name]['total_amount'] += ($price * $qty);
            $productTotals[$name]['last_purchase_date'] = $row['created_date'];
        }
    }

    return $productTotals;
}

// Get filter parameters
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-5 years'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$payment_method = $_GET['payment_method'] ?? 'all';
$has_void_columns = patientTransactionHistoryHasColumn('pos_transactions', 'voided');
$can_void_transactions = AclMain::aclCheckCore('acct', 'rep_a') || AclMain::aclCheckCore('admin', 'super');

$title = xlt("Patient Transaction History");
Header::setupHeader(['datatables', 'datatables-bs', 'datetime-picker', 'common']);
?>
<title><?php echo $title; ?></title>

    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
    padding: 0;
            background: #f5f5f5;
        }
        
.transaction-history-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    padding: 1.2rem 1rem 1rem 1rem;
            max-width: 1400px;
    margin: 1.2rem auto;
}

.transaction-history-title {
    font-size: 1.2rem;
    font-weight: 700;
    margin-bottom: 1.5rem;
    color: #2a3b4d;
            text-align: center;
        }
        
.patient-info-section {
            background: #f8f9fa;
    border: 1px solid #E1E5E9;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1.5rem;
}

.patient-info-title {
    margin-bottom: 1rem;
    font-size: 1.1rem;
    font-weight: 600;
    color: #2a3b4d;
}

.patient-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
        }
        
.patient-info-item {
            display: flex;
    justify-content: space-between;
            align-items: center;
    padding: 0.5rem;
    background: white;
    border-radius: 6px;
    border: 1px solid #E1E5E9;
        }
        
.patient-info-label {
            font-weight: 600;
    color: #333;
    margin-right: 0.75rem;
    min-width: 80px;
    font-size: 0.95rem;
}

        .patient-info-value {
    color: #2a3b4d;
    font-size: 0.95rem;
    flex: 1;
}

        /* Credit transaction styles */
        .badge {
            display: inline-block;
            padding: 0.25em 0.6em;
            font-size: 0.75em;
            font-weight: 700;
            line-height: 1;
            text-align: center;
            white-space: nowrap;
            vertical-align: baseline;
            border-radius: 0.25rem;
        }
        
        .badge-success {
            color: #fff;
            background-color: #28a745;
        }
        
        .badge-danger {
            color: #fff;
            background-color: #dc3545;
        }
        
        .badge-info {
            color: #fff;
            background-color: #17a2b8;
        }
        
        .credit-summary-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 1px solid #dee2e6;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .credit-balance-card, .credit-stats-card {
            background: white;
            padding: 1.2rem;
            border-radius: 8px;
            border: 1px solid #dee2e6;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .credit-balance-card:hover, .credit-stats-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

.filters-section {
            background: #f8f9fa;
    border: 1px solid #E1E5E9;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1.5rem;
}

.filters-title {
    font-weight: 600;
    color: #2a3b4d;
    margin-bottom: 0.8rem;
    font-size: 1rem;
}

.filters-grid {
            display: grid;
    grid-template-columns: 1fr 1fr 1fr auto auto;
    gap: 0.7rem 1.2rem;
            align-items: end;
        }

.filters-grid .filter-group:has(.btn-filter) {
    align-self: end;
}

@media (max-width: 900px) {
    .filters-grid {
        grid-template-columns: 1fr;
    }
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
.filter-group:has(.btn-filter) {
    display: flex;
    flex-direction: row;
    gap: 0.5rem;
    align-items: center;
    justify-content: flex-start;
}

.filter-label {
            font-weight: 600;
    color: #333;
    margin-bottom: 0.1rem;
    display: block;
}

.filter-input {
    width: 100%;
    padding: 0.45rem 0.7rem;
    border: 1.5px solid #E1E5E9;
    border-radius: 7px;
    font-size: 0.98rem;
    background: white;
    margin-bottom: 0.1rem;
}

.filter-input:focus {
    border-color: #4A90E2;
    outline: none;
    box-shadow: 0 0 0 0.2rem rgba(74, 144, 226, 0.25);
}

.filter-actions {
    grid-column: 1 / -1;
            display: flex;
    justify-content: center;
    gap: 0.7rem;
    margin-top: 1rem;
        }
        
.btn-filter {
    background: #4A90E2;
    color: #fff;
            border: none;
    border-radius: 8px;
    padding: 0.5rem 1.5rem;
    font-size: 1rem;
    font-weight: 600;
            cursor: pointer;
    transition: background 0.2s;
}

.btn-filter:hover {
    background: #357ABD;
}

.btn-reset {
    background: #f8f9fa;
    color: #495057;
    border: 2px solid #E1E5E9;
    border-radius: 8px;
    padding: 0.5rem 1.5rem;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
    text-decoration: none;
    display: inline-block;
}

.btn-reset:hover {
    background: #e9ecef;
}

.summary-section {
    background: #f8f9fa;
    border: 1px solid #E1E5E9;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1.5rem;
}

.summary-title {
    font-weight: 600;
    color: #2a3b4d;
    margin-bottom: 0.8rem;
    font-size: 1rem;
}

.summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.summary-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.8rem;
            background: white;
    border-radius: 6px;
    border: 1px solid #E1E5E9;
}

.summary-label {
    font-weight: 600;
    color: #333;
}

.summary-value {
    font-weight: 700;
    color: #2a3b4d;
    font-size: 1.1rem;
}

.tabs-section {
    background: #f8f9fa;
    border: 1px solid #E1E5E9;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1.5rem;
}

.tabs-title {
    font-weight: 600;
    color: #2a3b4d;
    margin-bottom: 0.8rem;
    font-size: 1rem;
}

.tabs-container {
            display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.tab-btn {
    background: white;
    color: #495057;
    border: 1px solid #E1E5E9;
    border-radius: 6px;
    padding: 0.5rem 1rem;
    font-size: 0.9rem;
    font-weight: 600;
            cursor: pointer;
    transition: all 0.2s;
}

.tab-btn:hover {
    background: #e9ecef;
}

.tab-btn.active {
    background: #4A90E2;
    color: white;
    border-color: #4A90E2;
        }
        
        .tab-content {
            display: none;
    background: white;
    border: 1px solid #E1E5E9;
    border-radius: 8px;
    overflow: hidden;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        .dataTable {
            width: 100%;
            border-collapse: collapse;
        }
        
        .dataTable th,
        .dataTable td {
    padding: 0.8rem;
            text-align: left;
    border-bottom: 1px solid #E1E5E9;
        }
        
        .dataTable th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
    font-size: 0.9rem;
        }
        
        .dataTable tr:hover {
            background: #f8f9fa;
        }
        
        .transaction-type {
    padding: 0.3rem 0.6rem;
            border-radius: 4px;
    font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .type-pos {
            background: #d4edda;
            color: #155724;
        }
        
        .type-billing {
            background: #fff3cd;
            color: #856404;
        }
        
        .type-payment {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .type-drug {
            background: #f8d7da;
            color: #721c24;
        }
        
        .amount {
            font-weight: 600;
        }
        
        .amount.positive {
            color: #28a745;
        }
        
        .amount.negative {
            color: #dc3545;
        }
        
        .status {
    padding: 0.3rem 0.6rem;
            border-radius: 4px;
    font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status.paid {
            background: #d4edda;
            color: #155724;
        }
        
        .status.pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status.voided {
            background: #f8d7da;
            color: #721c24;
        }
        
        .action-buttons {
            display: flex;
    gap: 0.3rem;
        }
        
        .btn-sm {
    padding: 0.3rem 0.6rem;
    font-size: 0.8rem;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}

.btn-view {
    background: #4A90E2;
    color: white;
}

.btn-view:hover {
    background: #357ABD;
}

.btn-print {
    background: #28a745;
    color: white;
}

.btn-print:hover {
    background: #218838;
        }

        .void-help-banner {
            margin-bottom: 1rem;
            padding: 0.85rem 1rem;
            border-radius: 10px;
            background: linear-gradient(135deg, #fff4e5 0%, #ffe8cc 100%);
            border: 1px solid #f5c27a;
            color: #7a4b00;
            font-size: 0.95rem;
            line-height: 1.4;
        }

        .void-action-wrap {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
            align-items: flex-start;
        }

        .btn-void {
            background: #c62828;
            color: #fff;
            font-weight: 700;
            border-radius: 999px;
            padding: 0.35rem 0.85rem;
            box-shadow: 0 2px 6px rgba(198, 40, 40, 0.2);
        }

        .btn-void:hover {
            background: #a91f1f;
            color: #fff;
        }

        .void-note {
            font-size: 11px;
            color: #6c757d;
            line-height: 1.35;
            max-width: 200px;
        }
        
        .email-receipt-btn {
            background-color: #17a2b8 !important;
            color: white !important;
            border: none !important;
        }
        
        .email-receipt-btn:hover {
            background-color: #138496 !important;
            color: white !important;
        }
        
        .alert-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 10px;
        }
        
        .receipt-link {
            color: #4A90E2;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: color 0.2s;
        }
        
        .receipt-link:hover {
            color: #357ABD;
            text-decoration: underline;
        }
        
        .receipt-details {
            background: #f8f9fa;
        }
        
        .receipt-details-content {
            padding: 15px;
            background: white;
            border-radius: 6px;
            margin: 10px;
            border: 1px solid #E1E5E9;
        }
        
        .details-table {
            border-collapse: collapse;
            width: 100%;
        }
        
        .details-table th,
        .details-table td {
            border: 1px solid #E1E5E9;
            padding: 8px;
            font-size: 12px;
        }
        
        .details-table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        
        .export-section {
            background: #f8f9fa;
    border: 1px solid #E1E5E9;
    border-radius: 8px;
    padding: 1rem;
    margin-top: 1.5rem;
            text-align: center;
        }

.export-title {
    font-weight: 600;
    color: #2a3b4d;
    margin-bottom: 0.8rem;
    font-size: 1rem;
        }
        
        .export-buttons {
            display: flex;
    gap: 0.7rem;
            justify-content: center;
            flex-wrap: wrap;
        }

.btn-export {
    background: #6c757d;
    color: white;
    border: none;
    border-radius: 8px;
    padding: 0.5rem 1.5rem;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
    text-decoration: none;
    display: inline-block;
}

.btn-export:hover {
    background: #5a6268;
}

.btn-back-to-pos:hover {
    background: #5a6268;
    text-decoration: none;
    color: white;
        }
        
        @media (max-width: 768px) {
    .filters-grid {
                grid-template-columns: 1fr;
            }
            
    .patient-info-grid {
                grid-template-columns: 1fr;
            }
            
    .summary-grid {
                grid-template-columns: 1fr;
            }
    
    .tabs-container {
        flex-direction: column;
    }
    
    .tab-btn {
        text-align: center;
            }
        }
    </style>
    <meta name="csrf-token" content="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
</head>

<body class="body_top">

<div class="transaction-history-card">
    <div class="transaction-history-title" style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <i class="fas fa-history me-2" style="color: #4A90E2;"></i>
            <?php echo xlt('Patient Transaction History'); ?>
        </div>
        <a href="pos_modal.php?pid=<?php echo attr($pid); ?>" class="btn-back-to-pos" style="display: inline-flex; align-items: center; gap: 0.5rem; background: #6c757d; color: white; border: none; border-radius: 8px; padding: 0.5rem 1rem; font-size: 0.9rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: background 0.2s;">
            <i class="fas fa-arrow-left"></i>
            <?php echo xlt('Back to Patient POS'); ?>
        </a>
        </div>
        
    <!-- Patient Information -->
    <div class="patient-info-section">
        <div style="position: relative;">
            <div class="patient-summary" style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #007bff;">
                <div style="display: flex; gap: 60px; font-size: 16px; color: #555; align-items: center; flex-wrap: wrap;">
                    <div>
                        <strong style="font-size: 18px; color: #333;"><?php echo text($patient_data['fname'] . ' ' . $patient_data['lname']); ?></strong>
                </div>
                    <div>
                        <strong style="font-size: 16px;">Mobile:</strong> <?php echo text($patient_data['phone_cell'] ?: $patient_data['phone_home'] ?: 'N/A'); ?>
                </div>
                    <div>
                        <strong style="font-size: 16px;">DOB:</strong> <?php echo text(oeFormatShortDate($patient_data['DOB'])); ?>
                </div>
                    <div>
                        <strong style="font-size: 16px;">Gender:</strong> <?php echo text(ucfirst($patient_data['sex'] ?? 'N/A')); ?>
                </div>
                    <div>
                        <strong style="font-size: 16px;">Patient ID:</strong> <?php echo text($patient_data['pubpid']); ?>
                </div>
                    <?php if (!empty($patient_data['email'])): ?>
                    <div>
                        <strong style="font-size: 16px;">Email:</strong> <?php echo text($patient_data['email']); ?>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
            </div>
        </div>
        
    <div class="filters-section">
        <div class="filters-title">
            <i class="fas fa-filter me-2" style="color: #4A90E2;"></i>
            <?php echo xlt('Filter Options'); ?>
        </div>
            <form method="GET" action="">
                <input type="hidden" name="pid" value="<?php echo attr($pid); ?>">
            <div class="filters-grid">
                    <div class="filter-group">
                    <label class="filter-label"><?php echo xlt('Date From'); ?></label>
                    <input type="date" name="date_from" value="<?php echo attr($date_from); ?>" class="filter-input">
                    </div>
                    <div class="filter-group">
                    <label class="filter-label"><?php echo xlt('Date To'); ?></label>
                    <input type="date" name="date_to" value="<?php echo attr($date_to); ?>" class="filter-input">
                    </div>
                    <div class="filter-group">
                    <label class="filter-label"><?php echo xlt('Payment Method'); ?></label>
                    <select name="payment_method" class="filter-input">
                            <option value="all" <?php echo $payment_method === 'all' ? 'selected' : ''; ?>><?php echo xlt('All Methods'); ?></option>
                        <option value="stripe" <?php echo $payment_method === 'stripe' ? 'selected' : ''; ?>><?php echo xlt('Stripe'); ?></option>
                            <option value="cash" <?php echo $payment_method === 'cash' ? 'selected' : ''; ?>><?php echo xlt('Cash'); ?></option>
                        </select>
                    </div>
                <div class="filter-group">
                    <button type="submit" class="btn-filter">
                        <?php echo xlt('Filter'); ?>
                    </button>
                    <a href="?pid=<?php echo attr($pid); ?>" class="btn-reset">
                        <i class="fas fa-undo"></i> <?php echo xlt('Reset'); ?>
                    </a>
                    </div>
                </div>
            </form>
        </div>
        
        <?php
        $receipt_financial_summary = patientTransactionHistoryGetReceiptFinancialSummary($pid, $date_from, $date_to, $payment_method);

        // Calculate comprehensive summary statistics
        $summary_sql = "SELECT 
        (SELECT COUNT(*) FROM pos_receipts WHERE pid = ?) as pos_receipt_count,
        (SELECT COUNT(*) FROM pos_transactions WHERE pid = ? AND transaction_type IN ('external_payment', 'payment', 'credit_payment') AND amount > 0) as pos_payment_count,
            (SELECT COUNT(*) FROM billing WHERE pid = ? AND activity = 1) as billing_count,
            (SELECT COUNT(*) FROM drug_sales WHERE pid = ? AND fee > 0) as drug_count,
        (SELECT SUM(amount) FROM pos_receipts WHERE pid = ?) as pos_receipt_total,
        (SELECT SUM(amount) FROM pos_transactions WHERE pid = ? AND transaction_type IN ('external_payment', 'payment', 'credit_payment') AND amount > 0) as pos_payment_total,
            (SELECT SUM(fee) FROM billing WHERE pid = ? AND activity = 1) as billing_total,
            (SELECT SUM(fee) FROM drug_sales WHERE pid = ? AND fee > 0) as drug_total";
        
    $summary_result = sqlFetchArray(sqlStatement($summary_sql, array($pid, $pid, $pid, $pid, $pid, $pid, $pid, $pid)));
        
    $pos_receipt_count = $summary_result['pos_receipt_count'] ?? 0;
        $pos_payment_count = $summary_result['pos_payment_count'] ?? 0;
        $billing_count = $summary_result['billing_count'] ?? 0;
        $drug_count = $summary_result['drug_count'] ?? 0;
        
    $pos_receipt_total = $summary_result['pos_receipt_total'] ?? 0;
        $pos_payment_total = $summary_result['pos_payment_total'] ?? 0;
        $billing_total = $summary_result['billing_total'] ?? 0;
        $drug_total = $summary_result['drug_total'] ?? 0;
    
            // Prefer receipt-derived financial totals because pos_receipts.amount can be stale on split tenders.
        $pos_count = (int) ($receipt_financial_summary['count'] ?? 0) > 0 ? (int) ($receipt_financial_summary['count'] ?? 0) : $pos_payment_count;
        $pos_charge_total = (float) ($receipt_financial_summary['charges'] ?? 0) > 0
            ? (float) ($receipt_financial_summary['charges'] ?? 0)
            : (float) $pos_receipt_total;
        $pos_payment_live_total = (float) ($receipt_financial_summary['payments'] ?? 0) > 0
            ? (float) ($receipt_financial_summary['payments'] ?? 0)
            : (float) $pos_payment_total;
        
        // Get credit balance only for financial summary
        $credit_balance = 0;
        
        $credit_balance_query = "SELECT balance FROM patient_credit_balance WHERE pid = ?";
        $credit_balance_result = sqlQuery($credit_balance_query, array($pid));
        $credit_balance_data = null;
        if (is_object($credit_balance_result) && method_exists($credit_balance_result, 'FetchRow')) {
            $credit_balance_data = $credit_balance_result->FetchRow();
        } elseif (is_array($credit_balance_result)) {
            $credit_balance_data = $credit_balance_result;
        }
        
        if ($credit_balance_data) {
            $credit_balance = floatval($credit_balance_data['balance']);
        }
        
        $total_charges = $pos_charge_total + $billing_total + $drug_total;
        $total_transactions = $pos_count + $billing_count + $drug_count;
        
        $payment_total = $pos_payment_live_total;
        $balance = $total_charges - $payment_total;
        ?>
        
    <div class="summary-section">
        <div class="summary-title">
            <i class="fas fa-chart-bar me-2" style="color: #4A90E2;"></i>
            <?php echo xlt('Financial Summary'); ?>
            </div>
        <div class="summary-grid">
            <div class="summary-item">
                <span class="summary-label"><?php echo xlt('Total Transactions'); ?></span>
                <span class="summary-value"><?php echo number_format($total_transactions); ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label"><?php echo xlt('Total Charges'); ?></span>
                <span class="summary-value">$<?php echo number_format($total_charges, 2); ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label"><?php echo xlt('Total Payments'); ?></span>
                <span class="summary-value">$<?php echo number_format($payment_total, 2); ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label"><?php echo xlt('Current Balance'); ?></span>
                <span class="summary-value">$<?php echo number_format($balance, 2); ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label"><?php echo xlt('Credit Balance'); ?></span>
                <span class="summary-value" style="color: #28a745;">$<?php echo number_format($credit_balance, 2); ?></span>
            </div>
            </div>
        </div>
        
    <div class="tabs-section">
        <div class="tabs-title" style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <i class="fas fa-list me-2" style="color: #4A90E2;"></i>
                <?php echo xlt('Transaction Details'); ?>
            </div>
            <div class="export-buttons" style="display: flex; gap: 0.5rem;">
                <a href="pos_export_transactions.php?pid=<?php echo attr($pid); ?>&format=csv&date_from=<?php echo attr($date_from); ?>&date_to=<?php echo attr($date_to); ?>&type=<?php echo attr($payment_method); ?>" 
                   class="btn-export">
                    <i class="fas fa-file-csv"></i> <?php echo xlt('CSV'); ?>
                </a>
                <a href="pos_export_transactions.php?pid=<?php echo attr($pid); ?>&format=pdf&date_from=<?php echo attr($date_from); ?>&date_to=<?php echo attr($date_to); ?>&type=<?php echo attr($payment_method); ?>" 
                   class="btn-export">
                    <i class="fas fa-file-pdf"></i> <?php echo xlt('PDF'); ?>
                </a>
                <a href="pos_export_transactions.php?pid=<?php echo attr($pid); ?>&format=excel&date_from=<?php echo attr($date_from); ?>&date_to=<?php echo attr($date_to); ?>&type=<?php echo attr($payment_method); ?>" 
                   class="btn-export">
                    <i class="fas fa-file-excel"></i> <?php echo xlt('Excel'); ?>
                </a>
            </div>
        </div>
        <div class="tabs-container">
            <button class="tab-btn active" onclick="showTab('pos')"><?php echo xlt('POS Receipts'); ?></button>
            <button class="tab-btn" onclick="showTab('all')"><?php echo xlt('All Transactions'); ?></button>
            <button class="tab-btn" onclick="showTab('credit')"><?php echo xlt('Credit Transactions'); ?></button>
            <button class="tab-btn" onclick="showTab('drug')"><?php echo xlt('Drug Sales'); ?></button>

        </div>
            </div>
            
            <!-- All Transactions Tab -->
            <div id="all" class="tab-content">
                <div class="void-help-banner">
                    <strong><?php echo xlt('Void Rules'); ?>:</strong>
                    <?php echo xlt('Void applies only to same-day eligible POS transactions before reconciliation. Backdated receipts are different: they use Undo Backdate and are not subject to the same-day rule.'); ?>
                </div>
                <div class="table-container">
                    <table id="all-transactions-table" class="dataTable">
                        <thead>
                            <tr>
                                <th><?php echo xlt('Date'); ?></th>
                                <th><?php echo xlt('Type'); ?></th>
                                <th><?php echo xlt('Product(s)'); ?></th>
                                <th><?php echo xlt('QTY'); ?></th>
                                <th><?php echo xlt('Charges'); ?></th>
                                <th><?php echo xlt('Payment'); ?></th>
                                <th><?php echo xlt('Payment Method'); ?></th>
                                <th><?php echo xlt('Status'); ?></th>
                                <th><?php echo xlt('User'); ?></th>
                                <th><?php echo xlt('Receipt'); ?></th>
                                <th><?php echo xlt('Actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Build comprehensive query for all transaction types
                            $all_transactions = array();
                            $receipt_transaction_actions = array();
                            $all_transaction_receipt_rows = array();
                            $all_transaction_receipt_numbers = array();

                            $all_receipt_query = "SELECT * FROM pos_receipts WHERE pid = ?";
                            $all_receipt_params = array($pid);
                            if ($date_from && $date_to) {
                                $all_receipt_query .= " AND DATE(created_date) BETWEEN ? AND ?";
                                $all_receipt_params[] = $date_from;
                                $all_receipt_params[] = $date_to;
                            }
                            if ($payment_method !== 'all') {
                                $all_receipt_query .= " AND payment_method = ?";
                                $all_receipt_params[] = $payment_method;
                            }
                            $all_receipt_query .= " ORDER BY created_date DESC";

                            $all_receipt_result = sqlStatement($all_receipt_query, $all_receipt_params);
                            while ($receipt_row = sqlFetchArray($all_receipt_result)) {
                                if (!is_array($receipt_row)) {
                                    continue;
                                }

                                $all_transaction_receipt_rows[] = $receipt_row;
                                $receipt_number = trim((string) ($receipt_row['receipt_number'] ?? ''));
                                if ($receipt_number !== '') {
                                    $all_transaction_receipt_numbers[$receipt_number] = true;
                                }
                            }
                            
                            // First, get POS Transactions (from pos_transactions table)
                            $pos_txn_table_check = sqlQuery("SHOW TABLES LIKE 'pos_transactions'");
                            if ($pos_txn_table_check) {
                                $pos_txn_query = "SELECT pt.*," .
                                    ($has_void_columns ? " pt.voided, pt.voided_at, pt.voided_by, pt.void_reason," : " 0 AS voided, NULL AS voided_at, NULL AS voided_by, NULL AS void_reason,") . "
                                                  COALESCE(CONCAT(TRIM(u.fname), ' ', TRIM(u.lname)), u.username, pt.user_id) AS user_display 
                                                  FROM pos_transactions pt
                                                  LEFT JOIN users u ON (pt.user_id = u.username OR pt.user_id = CAST(u.id AS CHAR))
                                                  WHERE pt.pid = ?";
                                
                                if ($date_from && $date_to) {
                                    $pos_txn_query .= " AND DATE(pt.created_date) BETWEEN ? AND ?";
                                }
                                if ($payment_method !== 'all') {
                                    $pos_txn_query .= " AND pt.payment_method = ?";
                                }
                                $pos_txn_query .= " ORDER BY pt.created_date DESC";
                                
                                $pos_txn_params = array($pid);
                                if ($date_from && $date_to) {
                                    $pos_txn_params[] = $date_from;
                                    $pos_txn_params[] = $date_to;
                                }
                                if ($payment_method !== 'all') {
                                    $pos_txn_params[] = $payment_method;
                                }
                                
                                $pos_txn_result = sqlStatement($pos_txn_query, $pos_txn_params);
                                while ($txn_row = sqlFetchArray($pos_txn_result)) {
                                    $receiptNumber = trim((string) ($txn_row['receipt_number'] ?? ''));
                                    [$can_void, $void_block_reason] = patientTransactionHistoryCanVoid($txn_row, $can_void_transactions);
                                    [$can_undo_backdate, $undo_backdate_block_reason] = patientTransactionHistoryCanUndoBackdated($txn_row, $can_void_transactions);
                                    $displayAmount = patientTransactionHistoryIsBackdatedReceipt($receiptNumber) ? 0.0 : floatval($txn_row['amount']);
                                    $txn = array(
                                        'id' => $txn_row['id'],
                                        'pid' => $pid,
                                        'date' => $txn_row['created_date'],
                                        'type' => 'POS Transaction',
                                        'description' => ucfirst(str_replace('_', ' ', $txn_row['transaction_type'])),
                                        'amount' => $displayAmount,
                                        'payment_method' => $txn_row['payment_method'] ?? 'N/A',
                                        'status' => !empty($txn_row['voided']) ? 'Voided' : 'Completed',
                                        'user' => $txn_row['user_display'] ?? $txn_row['user_id'],
                                        'receipt_number' => $receiptNumber,
                                        'reference' => $txn_row['id'],
                                        'voided' => !empty($txn_row['voided']),
                                        'voided_at' => $txn_row['voided_at'] ?? null,
                                        'void_reason' => $txn_row['void_reason'] ?? null,
                                        'can_void' => $can_void,
                                        'void_block_reason' => $void_block_reason,
                                        'can_undo_backdate' => $can_undo_backdate,
                                        'undo_backdate_block_reason' => $undo_backdate_block_reason
                                    );

                                    if ($receiptNumber !== '' && isset($all_transaction_receipt_numbers[$receiptNumber])) {
                                        $receipt_transaction_actions[$receiptNumber] = $txn;
                                        continue;
                                    }

                                    $all_transactions[] = $txn;
                                }
                            }
                            
                            // Output POS Transactions
                            foreach ($all_transactions as $txn) {
                                echo '<tr>';
                                echo '<td>' . text(date('m/d/Y H:i', strtotime($txn['date']))) . '</td>';
                                echo '<td>' . text($txn['type']) . '</td>';
                                echo '<td>' . text($txn['description']) . '</td>';
                                echo '<td>-</td>'; // Charge
                                echo '<td class="amount">$' . number_format($txn['amount'], 2) . '</td>';
                                echo '<td>-</td>'; // Payment
                                echo '<td>' . text($txn['payment_method']) . '</td>';
                                $status_class = strtolower($txn['status']) === 'voided' ? 'status voided' : 'status paid';
                                echo '<td><span class="' . attr($status_class) . '">' . text($txn['status']) . '</span></td>';
                                echo '<td>' . text($txn['user']) . '</td>';
                                
                                // Receipt link
                                if (!empty($txn['receipt_number'])) {
                                    echo '<td><a href="pos_receipt.php?receipt_number=' . attr($txn['receipt_number']) . '&pid=' . attr($pid) . '" target="_blank" class="btn btn-sm btn-outline-primary">View</a></td>';
                                } else {
                                    echo '<td>-</td>';
                                }
                                
                                echo '<td>' . patientTransactionHistoryRenderPosAction($txn, $pid) . '</td>';
                                echo '</tr>';
                            }
                            
                            // POS Receipts (from pos_receipts table)
                            foreach ($all_transaction_receipt_rows as $row) {
                                $receipt_data = json_decode($row['receipt_data'], true);
                                $products = isset($receipt_data['items']) && is_array($receipt_data['items']) ? $receipt_data['items'] : [];
                                $payment_method = patientTransactionHistoryResolveReceiptPaymentMethodLive(
                                    $pid,
                                    $row['receipt_number'] ?? '',
                                    $receipt_data,
                                    $row['payment_method'] ?? 'Unknown'
                                );
                                $amount = patientTransactionHistoryResolveReceiptPaymentTotalLive(
                                    $pid,
                                    $row['receipt_number'] ?? '',
                                    (float) ($row['amount'] ?? 0)
                                );
                                $user = $row['created_by'] ?? '';
                                $status = 'Paid';
                                $reference = $row['receipt_number'];
                                $date = $row['created_date'];
                                $receipt_action_txn = $receipt_transaction_actions[$reference] ?? null;
                                
                                // Add product rows first
                                foreach ($products as $item) {
                                    $qty = $item['quantity'] ?? 1;
                                    $dispense_qty = $item['dispense_quantity'] ?? $qty;
                                    $name = $item['name'] ?? $item['display_name'] ?? 'Unknown Item';
                                    $charge = ($item['price'] ?? 0) * $qty;
                                    echo '<tr>';
                                    echo '<td>' . text(oeFormatShortDate($date)) . '</td>';
                                    echo '<td>POS</td>';
                                    echo '<td>' . text($name) . '</td>';
                                    echo '<td>' . text($qty) . ($dispense_qty != $qty ? ' (Dispense: ' . $dispense_qty . ')' : '') . '</td>';
                                    echo '<td class="amount negative">+$' . number_format($charge, 2) . '</td>';
                                    echo '<td></td>';
                                    echo '<td></td>';
                                    echo '<td>' . xlt($status) . '</td>';
                                    echo '<td>' . text($user) . '</td>';
                                    echo '<td><a href="pos_receipt.php?receipt_number=' . attr($reference) . '&pid=' . attr($pid) . '" target="_blank" class="btn btn-sm btn-success open-receipt-modal">Receipt</a></td>';
                                    echo '<td></td>';
                                    echo '</tr>';
                                }
                                
                                // Add tax entry after products
                                $tax_total = $receipt_data['tax_total'] ?? 0;
                                if ($tax_total > 0) {
                                echo '<tr>';
                                echo '<td>' . text(oeFormatShortDate($date)) . '</td>';
                                echo '<td>POS</td>';
                                    echo '<td>Sales Tax</td>';
                                    echo '<td>1</td>';
                                    echo '<td class="amount negative">+$' . number_format($tax_total, 2) . '</td>';
                                    echo '<td></td>';
                                    echo '<td></td>';
                                    echo '<td>' . xlt($status) . '</td>';
                                    echo '<td>' . text($user) . '</td>';
                                    echo '<td><a href="pos_receipt.php?receipt_number=' . attr($reference) . '&pid=' . attr($pid) . '" target="_blank" class="btn btn-sm btn-success open-receipt-modal">Receipt</a></td>';
                                    echo '<td></td>';
                                    echo '</tr>';
                                }
                                
                                // Add credit entry if credit was applied
                                $credit_amount = $receipt_data['credit_amount'] ?? 0;
                                if ($credit_amount > 0) {
                                    echo '<tr>';
                                    echo '<td>' . text(oeFormatShortDate($date)) . '</td>';
                                    echo '<td>POS</td>';
                                    echo '<td>Credit Applied</td>';
                                    echo '<td></td>';
                                    echo '<td></td>';
                                    echo '<td class="amount positive" style="color: #28a745;">-$' . number_format($credit_amount, 2) . '</td>';
                                    echo '<td>Credit</td>';
                                    echo '<td>' . xlt($status) . '</td>';
                                    echo '<td>' . text($user) . '</td>';
                                    echo '<td><a href="pos_receipt.php?receipt_number=' . attr($reference) . '&pid=' . attr($pid) . '" target="_blank" class="btn btn-sm btn-success open-receipt-modal">Receipt</a></td>';
                                    echo '<td></td>';
                                    echo '</tr>';
                                }
                                
                                // Add payment entry last
                                echo '<tr>';
                                echo '<td>' . text(oeFormatShortDate($date)) . '</td>';
                                echo '<td>POS</td>';
                                echo '<td></td>';
                                echo '<td></td>';
                                echo '<td></td>';
                                echo '<td class="amount positive">-$' . number_format($amount, 2) . '</td>';
                                echo '<td>' . text($payment_method) . '</td>';
                                echo '<td>' . xlt($status) . '</td>';
                                echo '<td>' . text($user) . '</td>';
                                echo '<td><a href="pos_receipt.php?receipt_number=' . attr($reference) . '&pid=' . attr($pid) . '" target="_blank" class="btn btn-sm btn-success open-receipt-modal">Receipt</a></td>';
                                echo '<td>' . ($receipt_action_txn ? patientTransactionHistoryRenderPosAction($receipt_action_txn, $pid) : '') . '</td>';
                                echo '</tr>';
                            }
                            
                            // Billing Charges
                            $billing_query = "SELECT date, 'Billing' as type, CONCAT(code, ' - ', code_text) as description, fee as amount, 'N/A' as payment_method, CASE WHEN billed = 1 THEN 'Billed' ELSE 'Pending' END as status, user, id as reference FROM billing WHERE pid = ? AND activity = 1";
                            if ($date_from && $date_to) {
                                $billing_query .= " AND DATE(date) BETWEEN ? AND ?";
                            }
                            if ($payment_method !== 'all') {
                                $billing_query .= " AND 1=0"; // No payment method filter for billing
                            }
                            $billing_query .= " ORDER BY date DESC";
                            $billing_params = array($pid);
                            if ($date_from && $date_to) {
                                $billing_params[] = $date_from;
                                $billing_params[] = $date_to;
                            }
                            $billing_result = sqlStatement($billing_query, $billing_params);
                            while ($row = sqlFetchArray($billing_result)) {
                                echo '<tr>';
                                echo '<td>' . text(oeFormatShortDate($row['date'])) . '</td>';
                                echo '<td>Billing</td>';
                                echo '<td>' . text($row['description']) . '</td>';
                                echo '<td></td>';
                                echo '<td></td>';
                                echo '<td class="amount negative">+$' . number_format($row['amount'], 2) . '</td>';
                                echo '<td>' . $row['payment_method'] . '</td>';
                                echo '<td>' . xlt($row['status']) . '</td>';
                                echo '<td>' . text($row['user']) . '</td>';
                                echo '<td></td>';
                                echo '<td></td>';
                                echo '</tr>';
                            }
                            // Charges (including tax entries)
                            echo "<!-- START CHARGES SECTION -->";
                            
                            // Skip charges section when viewing POS transactions to avoid duplicates
                            if ($payment_method !== 'all') { // Only show charges if not filtering for payments only
                                $charges_query = "SELECT post_time as date, 'Charge' as type, code as description, pay_amount as amount, code_type as payment_method, 'Charged' as status, post_user as user, sequence_no as reference FROM ar_activity WHERE pid = ? AND pay_amount > 0 AND code_type IN ('PROD', 'POS', 'TAX') AND deleted IS NULL";
                                
                            if ($date_from && $date_to) {
                                $charges_query .= " AND DATE(post_time) BETWEEN ? AND ?";
                            }
                                // Show charges and tax entries for all transaction types except when specifically filtering for payments only
                                if ($payment_method === 'all') { // Only show tax entries for payment view if no payment method filter
                                    $charges_query .= " AND code_type = 'TAX'";
                                } elseif ($payment_method !== 'all') {
                                    $charges_query .= " AND 1=0"; // No charges if payment method filter is active
                            }
                            $charges_query .= " ORDER BY post_time DESC";
                            $charges_params = array($pid);
                            if ($date_from && $date_to) {
                                $charges_params[] = $date_from;
                                $charges_params[] = $date_to;
                            }
                            $charges_result = sqlStatement($charges_query, $charges_params);
                                
                            while ($row = sqlFetchArray($charges_result)) {
                                if ($row['payment_method'] === 'TAX') {
                                    echo "<!-- TAX ENTRY: " . $row['description'] . " $" . $row['amount'] . " -->";
                                }
                                echo '<tr>';
                                echo '<td>' . text(oeFormatShortDate($row['date'])) . '</td>';
                                echo '<td>' . text($row['type']) . '</td>';
                                echo '<td>' . text($row['description']) . '</td>';
                                echo '<td></td>';
                                echo '<td class="amount negative">+$' . number_format($row['amount'], 2) . '</td>';
                                echo '<td></td>';
                                echo '<td>' . $row['payment_method'] . '</td>';
                                echo '<td>' . xlt($row['status']) . '</td>';
                                echo '<td>' . text($row['user']) . '</td>';
                                echo '<td></td>';
                                echo '<td></td>';
                                echo '</tr>';
                                }
                            }
                            echo "<!-- END CHARGES SECTION -->";
                            
                            // Payments
                            $payment_query = "SELECT post_time as date, 'Payment' as type, CONCAT('Payment - ', COALESCE(code, 'Unknown')) as description, pay_amount as amount, COALESCE(code, 'Unknown') as payment_method, 'Paid' as status, post_user as user, sequence_no as reference FROM ar_activity WHERE pid = ? AND pay_amount > 0 AND code_type NOT IN ('PROD', 'POS', 'TAX') AND deleted IS NULL";
                            if ($date_from && $date_to) {
                                $payment_query .= " AND DATE(post_time) BETWEEN ? AND ?";
                            }
                            if ($payment_method !== 'all') {
                                $payment_query .= " AND code = ?";
                            }
                            $payment_query .= " ORDER BY post_time DESC";
                            $payment_params = array($pid);
                            if ($date_from && $date_to) {
                                $payment_params[] = $date_from;
                                $payment_params[] = $date_to;
                            }
                            if ($payment_method !== 'all') {
                                $payment_params[] = $payment_method;
                            }
                            $payment_result = sqlStatement($payment_query, $payment_params);
                            while ($row = sqlFetchArray($payment_result)) {
                                echo '<tr>';
                                echo '<td>' . text(oeFormatShortDate($row['date'])) . '</td>';
                                echo '<td>Payment</td>';
                                echo '<td></td>';
                                echo '<td></td>';
                                echo '<td></td>';
                                echo '<td class="amount positive">-$' . number_format($row['amount'], 2) . '</td>';
                                echo '<td>' . $row['payment_method'] . '</td>';
                                echo '<td>' . xlt($row['status']) . '</td>';
                                echo '<td>' . text($row['user']) . '</td>';
                                echo '<td></td>';
                                echo '<td></td>';
                                echo '</tr>';
                            }
                            // Drug Sales
                            $drug_query = "SELECT sale_date as date, 'Drug' as type, CONCAT('Drug Sale - ', COALESCE(notes, 'Unknown Drug')) as description, quantity, fee as amount, 'N/A' as payment_method, CASE WHEN billed = 1 THEN 'Billed' ELSE 'Pending' END as status, user, sale_id as reference FROM drug_sales WHERE pid = ?";
                            if ($date_from && $date_to) {
                                $drug_query .= " AND DATE(sale_date) BETWEEN ? AND ?";
                            }
                            if ($payment_method !== 'all') {
                                $drug_query .= " AND 1=0"; // No payment method filter for drug sales
                            }
                            $drug_query .= " ORDER BY sale_date DESC";
                            $drug_params = array($pid);
                            if ($date_from && $date_to) {
                                $drug_params[] = $date_from;
                                $drug_params[] = $date_to;
                            }
                            $drug_result = sqlStatement($drug_query, $drug_params);
                            while ($row = sqlFetchArray($drug_result)) {
                                echo '<tr>';
                                echo '<td>' . text(oeFormatShortDate($row['date'])) . '</td>';
                                echo '<td>Drug</td>';
                                echo '<td>' . text($row['description']) . '</td>';
                                echo '<td>' . text($row['quantity']) . '</td>';
                                echo '<td></td>';
                                echo '<td class="amount negative">+$' . number_format($row['amount'], 2) . '</td>';
                                echo '<td>' . $row['payment_method'] . '</td>';
                                echo '<td>' . xlt($row['status']) . '</td>';
                                echo '<td>' . text($row['user']) . '</td>';
                                echo '<td></td>';
                                echo '<td></td>';
                                echo '</tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- POS Receipts Tab -->
            <div id="pos" class="tab-content active">
                <div class="table-container">
                    <table id="pos-table" class="dataTable">
                        <thead>
                            <tr>
                                <th><?php echo xlt('Receipt #'); ?></th>
                                <th><?php echo xlt('Date'); ?></th>
                                <th><?php echo xlt('Amount'); ?></th>
                                <th><?php echo xlt('Payment Method'); ?></th>
                                <th><?php echo xlt('Cashier'); ?></th>
                                <th><?php echo xlt('Actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $pos_receipt_rows = [];
                            $pos_receipts = sqlStatement("
                                SELECT 
                                    receipt_number,
                                    created_date,
                                    amount,
                                    payment_method,
                                    created_by,
                                    receipt_data
                                FROM pos_receipts 
                                WHERE pid = ?
                                ORDER BY created_date DESC
                            ", array($pid));
                            while ($receipt = sqlFetchArray($pos_receipts)) {
                                if (is_array($receipt)) {
                                    $pos_receipt_rows[] = $receipt;
                                }
                            }

                            if (empty($pos_receipt_rows)) {
                                $pos_receipt_rows = patientTransactionHistoryReceiptFallbackRows($pid, $date_from, $date_to, $payment_method);
                            }
                            
                            foreach ($pos_receipt_rows as $receipt):
                                // Safely decode receipt data with error handling
                                $receipt_data = null;
                                $products = [];
                                $tax_total = 0;
                                $live_payment_total = patientTransactionHistoryResolveReceiptPaymentTotalLive(
                                    $pid,
                                    $receipt['receipt_number'] ?? '',
                                    (float) ($receipt['amount'] ?? 0)
                                );
                                $live_payment_rows = patientTransactionHistoryResolveReceiptPaymentRowsLive(
                                    $pid,
                                    $receipt['receipt_number'] ?? '',
                                    is_array($receipt_data) ? $receipt_data : [],
                                    (string) ($receipt['created_date'] ?? ''),
                                    (string) ($receipt['payment_method'] ?? 'Unknown'),
                                    (float) ($receipt['amount'] ?? 0)
                                );
                                
                                if (!empty($receipt['receipt_data'])) {
                                    $receipt_data = json_decode($receipt['receipt_data'], true);
                                    if (json_last_error() === JSON_ERROR_NONE && is_array($receipt_data)) {
                                        $products = isset($receipt_data['items']) && is_array($receipt_data['items']) ? $receipt_data['items'] : [];
                                        $tax_total = $receipt_data['tax_total'] ?? 0;
                                    }
                                }
                                $live_payment_rows = patientTransactionHistoryResolveReceiptPaymentRowsLive(
                                    $pid,
                                    $receipt['receipt_number'] ?? '',
                                    is_array($receipt_data) ? $receipt_data : [],
                                    (string) ($receipt['created_date'] ?? ''),
                                    (string) ($receipt['payment_method'] ?? 'Unknown'),
                                    (float) ($receipt['amount'] ?? 0)
                                );
                            ?>
                            <tr class="receipt-row" data-receipt="<?php echo attr($receipt['receipt_number']); ?>">
                                <td>
                                    <a href="#" class="receipt-link" onclick="toggleReceiptDetails('<?php echo attr($receipt['receipt_number']); ?>')">
                                        <?php echo text($receipt['receipt_number']); ?>
                                        <i class="fas fa-chevron-down" id="icon-<?php echo attr($receipt['receipt_number']); ?>"></i>
                                    </a>
                                </td>
                                <td><?php echo text(oeFormatShortDate($receipt['created_date'])); ?></td>
                                <td class="amount negative">$<?php echo number_format($live_payment_total, 2); ?></td>
                                <td>
                                    <?php 
                                    if (!empty($live_payment_rows)):
                                        echo text(patientTransactionHistoryResolveReceiptPaymentMethodLive(
                                            $pid,
                                            $receipt['receipt_number'] ?? '',
                                            $receipt_data,
                                            $receipt['payment_method'] ?? 'Unknown'
                                        ));
                                    else:
                                        // Fallback for single payment receipts
                                        echo text(patientTransactionHistoryFormatPaymentMethodLabel($receipt['payment_method'] ?? 'Unknown'));
                                    endif; 
                                    ?>
                                </td>
                                <td><?php echo text($receipt['created_by']); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="pos_receipt.php?receipt_number=<?php echo attr($receipt['receipt_number']); ?>&pid=<?php echo attr($pid); ?>" 
                                           target="_blank" class="btn btn-sm btn-success open-receipt-modal">
                                            <?php echo xlt('Print'); ?>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-info email-receipt-btn" 
                                                onclick="emailReceipt('<?php echo attr($receipt['receipt_number']); ?>', <?php echo attr($pid); ?>)">
                                            <i class="fas fa-envelope"></i> <?php echo xlt('Email'); ?>
                                        </button>

                                    </div>
                                </td>
                            </tr>
                            <!-- Expandable transaction details row -->
                            <tr class="receipt-details" id="details-<?php echo attr($receipt['receipt_number']); ?>" style="display: none;">
                                <td colspan="6">
                                    <div class="receipt-details-content">
                                        <h6 style="margin: 0 0 10px 0; color: #495057;"><?php echo xlt('Transaction Details for Receipt'); ?> <?php echo text($receipt['receipt_number']); ?></h6>
                                        <table class="details-table" style="width: 100%; margin-bottom: 10px;">
                                            <thead>
                                                <tr style="background: #f8f9fa;">
                                                    <th style="padding: 10px; text-align: left; font-size: 16px; font-weight: 600;"><?php echo xlt('Date'); ?></th>
                                                    <th style="padding: 10px; text-align: left; font-size: 16px; font-weight: 600;"><?php echo xlt('Type'); ?></th>
                                                    <th style="padding: 10px; text-align: left; font-size: 16px; font-weight: 600;"><?php echo xlt('Product(s)'); ?></th>
                                                    <th style="padding: 10px; text-align: center; font-size: 16px; font-weight: 600;"><?php echo xlt('QTY'); ?></th>
                                                    <th style="padding: 10px; text-align: right; font-size: 16px; font-weight: 600;"><?php echo xlt('Charges'); ?></th>
                                                    <th style="padding: 10px; text-align: right; font-size: 16px; font-weight: 600;"><?php echo xlt('Payment'); ?></th>
                                                    <th style="padding: 10px; text-align: left; font-size: 16px; font-weight: 600;"><?php echo xlt('Payment Method'); ?></th>
                                                    <th style="padding: 10px; text-align: left; font-size: 16px; font-weight: 600;"><?php echo xlt('Status'); ?></th>
                                                    <th style="padding: 10px; text-align: left; font-size: 16px; font-weight: 600;"><?php echo xlt('User'); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($products as $item): ?>
                                                <tr>
                                                    <td style="padding: 10px; font-size: 16px;"><?php echo text(oeFormatShortDate($receipt['created_date'])); ?></td>
                                                    <td style="padding: 10px; font-size: 16px;">POS</td>
                                                    <td style="padding: 10px; font-size: 16px;"><?php echo text($item['name'] ?? $item['display_name'] ?? 'Unknown Item'); ?></td>
                                                    <td style="padding: 10px; text-align: center; font-size: 16px;"><?php 
                                                        $qty = $item['quantity'] ?? 1;
                                                        $dispense_qty = $item['dispense_quantity'] ?? $qty;
                                                        echo text($qty) . ($dispense_qty != $qty ? ' (Dispense: ' . $dispense_qty . ')' : '');
                                                    ?></td>
                                                    <td style="padding: 10px; text-align: right; font-size: 16px; color: #dc3545;">+$<?php echo number_format(($item['price'] ?? 0) * ($item['quantity'] ?? 1), 2); ?></td>
                                                    <td style="padding: 10px; text-align: right; font-size: 16px;"></td>
                                                    <td style="padding: 10px; font-size: 16px;"></td>
                                                    <td style="padding: 10px; font-size: 16px;"><?php echo xlt('Paid'); ?></td>
                                                    <td style="padding: 10px; font-size: 16px;"><?php echo text($receipt['created_by']); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                                
                                                <?php if ($tax_total > 0): ?>
                                                <tr>
                                                    <td style="padding: 10px; font-size: 16px;"><?php echo text(oeFormatShortDate($receipt['created_date'])); ?></td>
                                                    <td style="padding: 10px; font-size: 16px;">POS</td>
                                                    <td style="padding: 10px; font-size: 16px;"><?php echo xlt('Sales Tax'); ?></td>
                                                    <td style="padding: 10px; text-align: center; font-size: 16px;">1</td>
                                                    <td style="padding: 10px; text-align: right; font-size: 16px; color: #dc3545;">+$<?php echo number_format($tax_total, 2); ?></td>
                                                    <td style="padding: 10px; text-align: right; font-size: 16px;"></td>
                                                    <td style="padding: 10px; font-size: 16px;"></td>
                                                    <td style="padding: 10px; font-size: 16px;"><?php echo xlt('Paid'); ?></td>
                                                    <td style="padding: 10px; font-size: 16px;"><?php echo text($receipt['created_by']); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                                
                                                <?php 
                                                if (!empty($live_payment_rows)): 
                                                    foreach ($live_payment_rows as $payment): ?>
                                                <tr style="background: #e9ecef;">
                                                    <td style="padding: 10px; font-size: 16px;"><?php echo text(oeFormatShortDate($payment['created_date'] ?? $receipt['created_date'])); ?></td>
                                                    <td style="padding: 10px; font-size: 16px;">POS</td>
                                                    <td style="padding: 10px; font-size: 16px;"></td>
                                                    <td style="padding: 10px; text-align: center; font-size: 16px;"></td>
                                                    <td style="padding: 10px; text-align: right; font-size: 16px;"></td>
                                                    <td style="padding: 10px; text-align: right; font-size: 16px; color: #28a745; font-weight: bold;">-$<?php echo number_format((float) ($payment['amount'] ?? 0), 2); ?></td>
                                                    <td style="padding: 10px; font-size: 16px;"><?php echo text(patientTransactionHistoryFormatPaymentMethodLabel($payment['method'] ?? 'Unknown')); ?></td>
                                                    <td style="padding: 10px; font-size: 16px;"><?php echo xlt('Paid'); ?></td>
                                                    <td style="padding: 10px; font-size: 16px;"><?php echo text($receipt['created_by']); ?></td>
                                                </tr>
                                                    <?php endforeach; ?>
                                                <?php else: 
                                                    // Fallback for single payment receipts
                                                ?>
                                                <tr style="background: #e9ecef;">
                                                    <td style="padding: 10px; font-size: 16px;"><?php echo text(oeFormatShortDate($receipt['created_date'])); ?></td>
                                                    <td style="padding: 10px; font-size: 16px;">POS</td>
                                                    <td style="padding: 10px; font-size: 16px;"></td>
                                                    <td style="padding: 10px; text-align: center; font-size: 16px;"></td>
                                                    <td style="padding: 10px; text-align: right; font-size: 16px;"></td>
                                                    <td style="padding: 10px; text-align: right; font-size: 16px; color: #28a745; font-weight: bold;">-$<?php echo number_format($live_payment_total, 2); ?></td>
                                                    <td style="padding: 10px; font-size: 16px;"><?php echo text(patientTransactionHistoryFormatPaymentMethodLabel($receipt['payment_method'] ?? 'Unknown')); ?></td>
                                                    <td style="padding: 10px; font-size: 16px;"><?php echo xlt('Paid'); ?></td>
                                                    <td style="padding: 10px; font-size: 16px;"><?php echo text($receipt['created_by']); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($pos_receipt_rows)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 2rem; color: #6c757d; font-style: italic;">
                                    <?php echo xlt('No POS receipts found for this patient.'); ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Charges Tab -->
            <div id="charges" class="tab-content">
                <div class="table-container">
                    <table id="charges-table" class="dataTable">
                        <thead>
                            <tr>
                                <th><?php echo xlt('Date'); ?></th>
                                <th><?php echo xlt('Type'); ?></th>
                                <th><?php echo xlt('Product(s)'); ?></th>
                                <th><?php echo xlt('QTY'); ?></th>
                                <th><?php echo xlt('Charges'); ?></th>
                                <th><?php echo xlt('Payment'); ?></th>
                                <th><?php echo xlt('Payment Method'); ?></th>
                                <th><?php echo xlt('Status'); ?></th>
                                <th><?php echo xlt('User'); ?></th>
                                <th><?php echo xlt('Receipt'); ?></th>
                                <th><?php echo xlt('Actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Show POS charges in the same format as POS receipts
                            $pos_receipts_for_charges = sqlStatement("SELECT * FROM pos_receipts WHERE pid = ? ORDER BY created_date DESC", array($pid));
                            
                            while ($row = sqlFetchArray($pos_receipts_for_charges)) {
                                $receipt_data = json_decode($row['receipt_data'], true);
                                $products = isset($receipt_data['items']) && is_array($receipt_data['items']) ? $receipt_data['items'] : [];
                                $user = $row['created_by'] ?? '';
                                $status = 'Paid';
                                $date = $row['created_date'];
                                
                                // Add product rows
                                foreach ($products as $item) {
                                    $qty = $item['quantity'] ?? 1;
                                    $dispense_qty = $item['dispense_quantity'] ?? $qty;
                                    $name = $item['name'] ?? $item['display_name'] ?? 'Unknown Item';
                                    $charge = ($item['price'] ?? 0) * $qty;
                                    echo '<tr>';
                                    echo '<td>' . text(oeFormatShortDate($date)) . '</td>';
                                    echo '<td>POS</td>';
                                    echo '<td>' . text($name) . '</td>';
                                    echo '<td>' . text($qty) . ($dispense_qty != $qty ? ' (Dispense: ' . $dispense_qty . ')' : '') . '</td>';
                                    echo '<td class="amount negative">+$' . number_format($charge, 2) . '</td>';
                                    echo '<td></td>';
                                    echo '<td></td>';
                                    echo '<td>' . xlt($status) . '</td>';
                                    echo '<td>' . text($user) . '</td>';
                                    echo '<td></td>';
                                    echo '<td></td>';
                                    echo '</tr>';
                                }
                                
                                // Add tax entry
                                $tax_total = $receipt_data['tax_total'] ?? 0;
                                if ($tax_total > 0) {
                                    echo '<tr>';
                                    echo '<td>' . text(oeFormatShortDate($date)) . '</td>';
                                    echo '<td>POS</td>';
                                    echo '<td>Sales Tax</td>';
                                    echo '<td>1</td>';
                                    echo '<td class="amount negative">+$' . number_format($tax_total, 2) . '</td>';
                                    echo '<td></td>';
                                    echo '<td></td>';
                                    echo '<td>' . xlt($status) . '</td>';
                                    echo '<td>' . text($user) . '</td>';
                                    echo '<td></td>';
                                    echo '<td></td>';
                                    echo '</tr>';
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Credit Transactions Tab -->
            <div id="credit" class="tab-content">
                <?php
                // Get current credit balance
                $current_credit_balance = 0;
                $credit_balance_query = "SELECT balance FROM patient_credit_balance WHERE pid = ?";
                $credit_balance_result = sqlQuery($credit_balance_query, array($pid));
                $credit_balance_data = null;
                if (is_object($credit_balance_result) && method_exists($credit_balance_result, 'FetchRow')) {
                    $credit_balance_data = $credit_balance_result->FetchRow();
                } elseif (is_array($credit_balance_result)) {
                    $credit_balance_data = $credit_balance_result;
                }
                
                if ($credit_balance_data) {
                    $current_credit_balance = floatval($credit_balance_data['balance']);
                }
                
                // Get detailed credit summary statistics
                $credit_summary_query = "SELECT 
                    COUNT(*) as total_transactions,
                    SUM(CASE WHEN transaction_type = 'payment' THEN ABS(amount) ELSE 0 END) as total_used,
                    SUM(CASE WHEN transaction_type = 'refund' THEN ABS(amount) ELSE 0 END) as total_received,
                    SUM(CASE WHEN transaction_type = 'transfer' AND amount > 0 THEN ABS(amount) ELSE 0 END) as total_received_transfers,
                    SUM(CASE WHEN transaction_type = 'transfer' AND amount < 0 THEN ABS(amount) ELSE 0 END) as total_transferred_out,
                    MIN(created_at) as first_transaction,
                    MAX(created_at) as last_transaction
                    FROM patient_credit_transactions 
                    WHERE pid = ?";
                $credit_summary_result = sqlQuery($credit_summary_query, array($pid));
                $credit_summary_data = null;
                if (is_object($credit_summary_result) && method_exists($credit_summary_result, 'FetchRow')) {
                    $credit_summary_data = $credit_summary_result->FetchRow();
                } elseif (is_array($credit_summary_result)) {
                    $credit_summary_data = $credit_summary_result;
                }
                ?>
                
                <!-- Credit Balance Summary -->
                <div class="credit-summary-section" style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <div class="credit-balance-card" style="background: white; padding: 1rem; border-radius: 6px; border: 1px solid #dee2e6;">
                            <div style="font-size: 0.9rem; color: #6c757d; margin-bottom: 0.5rem;"><?php echo xlt('Current Balance'); ?></div>
                            <div style="font-size: 1.5rem; font-weight: bold; color: #28a745;">$<?php echo number_format($current_credit_balance, 2); ?></div>
                        </div>
                        
                        <?php if ($credit_summary_data): ?>
                        <div class="credit-stats-card" style="background: white; padding: 1rem; border-radius: 6px; border: 1px solid #dee2e6;">
                            <div style="font-size: 0.9rem; color: #6c757d; margin-bottom: 0.5rem;"><?php echo xlt('Total Received'); ?></div>
                            <div style="font-size: 1.2rem; font-weight: bold; color: #28a745;">$<?php echo number_format(($credit_summary_data['total_received'] ?? 0) + ($credit_summary_data['total_received_transfers'] ?? 0), 2); ?></div>
                        </div>
                        
                        <div class="credit-stats-card" style="background: white; padding: 1rem; border-radius: 6px; border: 1px solid #dee2e6;">
                            <div style="font-size: 0.9rem; color: #6c757d; margin-bottom: 0.5rem;"><?php echo xlt('Total Used'); ?></div>
                            <div style="font-size: 1.2rem; font-weight: bold; color: #dc3545;">$<?php echo number_format($credit_summary_data['total_used'] ?? 0, 2); ?></div>
                        </div>
                        
                        <div class="credit-stats-card" style="background: white; padding: 1rem; border-radius: 6px; border: 1px solid #dee2e6;">
                            <div style="font-size: 0.9rem; color: #6c757d; margin-bottom: 0.5rem;"><?php echo xlt('Total Transferred'); ?></div>
                            <div style="font-size: 1.2rem; font-weight: bold; color: #ffc107;">$<?php echo number_format($credit_summary_data['total_transferred_out'] ?? 0, 2); ?></div>
                        </div>
                        
                        <div class="credit-stats-card" style="background: white; padding: 1rem; border-radius: 6px; border: 1px solid #dee2e6;">
                            <div style="font-size: 0.9rem; color: #6c757d; margin-bottom: 0.5rem;"><?php echo xlt('Total Transactions'); ?></div>
                            <div style="font-size: 1.2rem; font-weight: bold; color: #6c757d;"><?php echo number_format($credit_summary_data['total_transactions'] ?? 0); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="table-container">
                    <table id="credit-transactions-table" class="dataTable">
                        <thead>
                            <tr>
                                <th><?php echo xlt('Date & Time'); ?></th>
                                <th><?php echo xlt('Type'); ?></th>
                                <th><?php echo xlt('Amount'); ?></th>
                                <th><?php echo xlt('Balance Before'); ?></th>
                                <th><?php echo xlt('Balance After'); ?></th>
                                <th><?php echo xlt('Receipt'); ?></th>
                                <th><?php echo xlt('Description'); ?></th>
                                <th><?php echo xlt('Actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // First, show POS transactions (dispense/administer)
                            $pos_table_exists = sqlQuery("SHOW TABLES LIKE 'pos_transactions'");
                            $pos_table_data = null;
                            if (is_object($pos_table_exists) && method_exists($pos_table_exists, 'FetchRow')) {
                                $pos_table_data = $pos_table_exists->FetchRow();
                            } elseif (is_array($pos_table_exists)) {
                                $pos_table_data = $pos_table_exists;
                            }
                            
                            if ($pos_table_data) {
                                $pos_query = "SELECT pt.*, COALESCE(CONCAT(TRIM(u.fname), ' ', TRIM(u.lname)), u.username, pt.user_id) AS user_display 
                                              FROM pos_transactions pt
                                              LEFT JOIN users u ON (pt.user_id = u.username OR pt.user_id = CAST(u.id AS CHAR))
                                              WHERE pt.pid = ?";
                                
                                // Add date filtering
                                if ($date_from && $date_to) {
                                    $pos_query .= " AND DATE(pt.created_date) BETWEEN ? AND ?";
                                }
                                
                                // Add payment method filtering if specified
                                if ($payment_method !== 'all') {
                                    $pos_query .= " AND pt.payment_method = ?";
                                }
                                
                                $pos_query .= " ORDER BY pt.created_date DESC";
                                
                                // Build parameters array
                                $pos_params = array($pid);
                                if ($date_from && $date_to) {
                                    $pos_params[] = $date_from;
                                    $pos_params[] = $date_to;
                                }
                                if ($payment_method !== 'all') {
                                    $pos_params[] = $payment_method;
                                }
                                
                                $pos_result = sqlStatement($pos_query, $pos_params);
                                
                                while ($pos_row = sqlFetchArray($pos_result)) {
                                    $transaction_type = $pos_row['transaction_type'];
                                    $amount = floatval($pos_row['amount']);
                                    $items = json_decode($pos_row['items'], true);
                                    
                                    // Determine badge class based on transaction type
                                    $type_badge_class = 'badge-info';
                                    if ($transaction_type == 'dispense') {
                                        $type_badge_class = 'badge-primary';
                                    } elseif ($transaction_type == 'administer') {
                                        $type_badge_class = 'badge-warning';
                                    } elseif ($transaction_type == 'dispense_and_administer') {
                                        $type_badge_class = 'badge-success';
                                    } elseif ($transaction_type == 'external_payment') {
                                        $type_badge_class = 'badge-secondary';
                                    } elseif ($transaction_type == 'purchase') {
                                        $type_badge_class = 'badge-dark';
                                    }
                                    
                                    // Build description from items
                                    $description = '';
                                    if (is_array($items) && count($items) > 0) {
                                        $item_names = array();
                                        foreach ($items as $item) {
                                            $name = $item['name'] ?? $item['display_name'] ?? 'Unknown';
                                            $qty = intval($item['quantity'] ?? 0);
                                            $dispense = intval($item['dispense_quantity'] ?? 0);
                                            $administer = intval($item['administer_quantity'] ?? 0);
                                            $lot = $item['lot_number'] ?? 'N/A';
                                            
                                            $item_desc = $name . ' (Lot: ' . $lot;
                                            if ($qty > 0) $item_desc .= ', Qty: ' . $qty;
                                            if ($dispense > 0) $item_desc .= ', Dispensed: ' . $dispense;
                                            if ($administer > 0) $item_desc .= ', Administered: ' . $administer;
                                            $item_desc .= ')';
                                            
                                            $item_names[] = $item_desc;
                                        }
                                        $description = implode('<br>', $item_names);
                                    } else {
                                        $description = 'No item details available';
                                    }
                                    
                                    echo '<tr>';
                                    echo '<td>' . text(date('m/d/Y H:i', strtotime($pos_row['created_date']))) . '</td>';
                                    echo '<td><span class="badge ' . $type_badge_class . '">' . text(ucfirst(str_replace('_', ' ', $transaction_type))) . '</span></td>';
                                    echo '<td class="amount" style="color: #17a2b8;">$' . number_format($amount, 2) . '</td>';
                                    echo '<td>-</td>'; // Balance before (not applicable)
                                    echo '<td>-</td>'; // Balance after (not applicable)
                                    
                                    // Receipt link
                                    if (!empty($pos_row['receipt_number'])) {
                                        echo '<td><a href="pos_receipt.php?receipt_number=' . attr($pos_row['receipt_number']) . '&pid=' . attr($pid) . '" target="_blank" class="btn btn-sm btn-outline-primary">View Receipt</a></td>';
                                    } else {
                                        echo '<td><span class="text-muted">N/A</span></td>';
                                    }
                                    
                                    echo '<td>' . $description . '</td>';
                                    
                                    // Actions column
                                    echo '<td>';
                                    echo '<button class="btn btn-sm btn-outline-info" onclick="alert(\'Transaction ID: ' . $pos_row['id'] . '\\nReceipt: ' . $pos_row['receipt_number'] . '\\nUser: ' . ($pos_row['user_display'] ?? 'Unknown') . '\')">Details</button>';
                                    echo '</td>';
                                    echo '</tr>';
                                }
                            }
                            
                            // Now show credit transactions
                            $credit_table_exists = sqlQuery("SHOW TABLES LIKE 'patient_credit_transactions'");
                            $credit_table_data = null;
                            if (is_object($credit_table_exists) && method_exists($credit_table_exists, 'FetchRow')) {
                                $credit_table_data = $credit_table_exists->FetchRow();
                            } elseif (is_array($credit_table_exists)) {
                                $credit_table_data = $credit_table_exists;
                            }
                            
                            if ($credit_table_data) {
                                $credit_query = "SELECT * FROM patient_credit_transactions WHERE pid = ? ORDER BY created_at DESC";
                                $credit_result = sqlStatement($credit_query, array($pid));
                                
                                while ($row = sqlFetchArray($credit_result)) {
                                    $transaction_type = $row['transaction_type'];
                                    $amount = floatval($row['amount']);
                                    
                                    // Determine display properties based on transaction type and amount
                                    if ($transaction_type == 'payment') {
                                        // Credit used for payment - always negative (red)
                                        $amount_class = 'negative';
                                        $amount_sign = '-';
                                        $type_badge_class = 'badge-danger';
                                        $amount_color = 'color: #dc3545;';
                                    } elseif ($transaction_type == 'transfer') {
                                        // Transfer - check if amount is negative (outgoing) or positive (incoming)
                                        if ($amount < 0) {
                                            // Outgoing transfer - negative amount (red)
                                            $amount_class = 'negative';
                                            $amount_sign = '';
                                            $type_badge_class = 'badge-danger';
                                            $amount_color = 'color: #dc3545;';
                                        } else {
                                            // Incoming transfer - positive amount (green)
                                            $amount_class = 'positive';
                                            $amount_sign = '+';
                                            $type_badge_class = 'badge-success';
                                            $amount_color = 'color: #28a745;';
                                        }
                                    } else {
                                        // Refund or other credit additions - always positive (green)
                                        $amount_class = 'positive';
                                        $amount_sign = '+';
                                        $type_badge_class = 'badge-success';
                                        $amount_color = 'color: #28a745;';
                                    }
                                    
                                    echo '<tr>';
                                    echo '<td>' . text(date('m/d/Y H:i', strtotime($row['created_at']))) . '</td>';
                                    echo '<td><span class="badge ' . $type_badge_class . '">' . text(ucfirst($transaction_type)) . '</span></td>';
                                    echo '<td class="amount ' . $amount_class . '" style="' . $amount_color . '">' . $amount_sign . '$' . number_format(abs($amount), 2) . '</td>';
                                    echo '<td>$' . number_format($row['balance_before'], 2) . '</td>';
                                    echo '<td>$' . number_format($row['balance_after'], 2) . '</td>';
                                    
                                    // Receipt link if available
                                    if (!empty($row['receipt_number']) && $row['receipt_number'] != 'N/A') {
                                        echo '<td><a href="pos_receipt.php?receipt_number=' . attr($row['receipt_number']) . '&pid=' . attr($pid) . '" target="_blank" class="btn btn-sm btn-outline-primary">View Receipt</a></td>';
                                    } else {
                                        echo '<td><span class="text-muted">N/A</span></td>';
                                    }
                                    
                                    echo '<td>' . text($row['description']) . '</td>';
                                    
                                    // Actions column
                                    echo '<td>';
                                    echo '<button class="btn btn-sm btn-outline-info" onclick="showCreditTransactionDetails(' . $row['id'] . ')">Details</button>';
                                    echo '</td>';
                                    echo '</tr>';
                                }
                            }
                            
                            // Check if we have any transactions at all
                            $has_pos = $pos_table_data ? true : false;
                            $has_credit = $credit_table_data ? true : false;
                            
                            if (!$has_pos && !$has_credit) {
                                echo '<tr><td colspan="8" style="text-align: center; padding: 2rem; color: #6c757d; font-style: italic;">' . 
                                     xlt('No transactions found for this patient.') . '</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Drug Sales Tab -->
            <div id="drug" class="tab-content">
                <div class="table-container">
                    <table id="drugs-table" class="dataTable">
                        <thead>
                            <tr>
                                <th><?php echo xlt('Product Name'); ?></th>
                                <th><?php echo xlt('Lot Number'); ?></th>
                                <th><?php echo xlt('Total Quantity Purchased'); ?></th>
                                <th><?php echo xlt('Last Purchase Date'); ?></th>
                                <th><?php echo xlt('Total Amount Spent'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $product_totals = patientTransactionHistoryProductTotals($pid, $date_from, $date_to);
                            
                            if (!empty($product_totals)):
                                foreach ($product_totals as $product_name => $data):
                            ?>
                            <tr>
                                <td><?php echo text($product_name); ?></td>
                                <td><?php echo text($data['lot_number']); ?></td>
                                <td><?php echo text($data['total_quantity']); ?></td>
                                <td><?php echo text(oeFormatShortDate($data['last_purchase_date'])); ?></td>
                                <td class="amount negative">$<?php echo number_format($data['total_amount'], 2); ?></td>
                            </tr>
                            <?php 
                                 endforeach;
                             else:
                             ?>
                             <tr>
                                 <td colspan="5" style="text-align: center; padding: 2rem; color: #6c757d; font-style: italic;">
                                     <?php echo xlt('No products purchased for this patient.'); ?>
                                </td>
                            </tr>
                             <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            

        </div>
        
    </div>
    
    <script>
        function showTab(tabName) {
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => content.classList.remove('active'));
            
            // Remove active class from all tabs
            const tabs = document.querySelectorAll('.tab-btn');
            tabs.forEach(tab => tab.classList.remove('active'));
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
            

        }
        
        function viewDetails(type, reference) {
            // This function can be expanded to show detailed transaction information
            alert('Transaction Details:\nType: ' + type + '\nReference: ' + reference);
        }
        
        function showCreditTransactionDetails(transactionId) {
            // This function can be expanded to show detailed transaction information
            alert('Credit Transaction Details:\nTransaction ID: ' + transactionId + '\n\nThis feature can be expanded to show detailed transaction information in a modal or popup.');
        }
        
        function toggleReceiptDetails(receiptNumber) {
            const detailsRow = document.getElementById(`details-${receiptNumber}`);
            const icon = document.getElementById(`icon-${receiptNumber}`);

            if (detailsRow.style.display === 'none') {
                detailsRow.style.display = 'table-row'; // Show the details row
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            } else {
                detailsRow.style.display = 'none'; // Hide the details row
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
            }
        }

        function emailReceipt(receiptNumber, pid) {
            // Show email modal
            const emailModal = document.getElementById('email-modal');
            const emailInput = document.getElementById('email-input');
            const receiptNumberSpan = document.getElementById('receipt-number-span');
            
            receiptNumberSpan.textContent = receiptNumber;
            emailModal.style.display = 'flex';
            
            // Pre-fill with patient's email if available
            fetch(`pos_email_receipt.php?action=get_patient_email&pid=${pid}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.email) {
                        emailInput.value = data.email;
                    }
                })
                .catch(error => {
                    console.error('Error fetching patient email:', error);
                });
        }

        function sendEmailReceipt() {
            const emailInput = document.getElementById('email-input');
            const receiptNumber = document.getElementById('receipt-number-span').textContent;
            const sendBtn = document.getElementById('send-email-btn');
            const statusDiv = document.getElementById('email-status');
            
            const email = emailInput.value.trim();
            if (!email) {
                alert('Please enter an email address');
                return;
            }
            
            if (!email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                alert('Please enter a valid email address');
                return;
            }
            
            // Disable button and show loading
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            statusDiv.innerHTML = '';
            
            // Send email
            fetch('pos_email_receipt.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=send_receipt&receipt_number=${encodeURIComponent(receiptNumber)}&email=${encodeURIComponent(email)}&csrf_token=${encodeURIComponent(document.querySelector('meta[name="csrf-token"]').getAttribute('content'))}`
            })
            .then(response => response.json())
            .then(data => {
                console.log('Email response:', data); // Debug log
                if (data.success) {
                    if (data.development_mode) {
                        statusDiv.innerHTML = '<div class="alert alert-warning">Email simulated successfully! (Development mode - check server logs for details)</div>';
                    } else {
                        statusDiv.innerHTML = '<div class="alert alert-success">Email sent successfully!</div>';
                    }
                    setTimeout(() => {
                        closeEmailModal();
                    }, 3000);
                } else {
                    let errorMsg = data.error || 'Failed to send email';
                    if (data.gmail_auth_issue) {
                        errorMsg += '<br><br><strong>Gmail Authentication Issue:</strong><br>' +
                                   '• Gmail requires an "App Password" for SMTP<br>' +
                                   '• If 2FA is enabled, use App Password instead of regular password<br>' +
                                   '• Go to: <a href="https://myaccount.google.com/apppasswords" target="_blank">https://myaccount.google.com/apppasswords</a><br>' +
                                   '• Generate App Password for "Mail"';
                    }
                    statusDiv.innerHTML = `<div class="alert alert-danger">Error: ${errorMsg}</div>`;
                }
            })
            .catch(error => {
                console.error('Error sending email:', error);
                statusDiv.innerHTML = '<div class="alert alert-danger">Network error occurred</div>';
            })
            .finally(() => {
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Email';
            });
        }

        function closeEmailModal() {
            document.getElementById('email-modal').style.display = 'none';
            document.getElementById('email-input').value = '';
            document.getElementById('email-status').innerHTML = '';
        }

        function showVoidFeedback(message, isError = false) {
            if (window.top && typeof window.top.dlgAlert === 'function') {
                window.top.dlgAlert(message);
                return;
            }

            if (isError) {
                alert(message);
                return;
            }

            alert(message);
        }

        async function voidPatientTransaction(transactionId, pid, buttonElement) {
            if (!transactionId || !pid) {
                showVoidFeedback('Missing transaction information for void.', true);
                return;
            }

            const reason = window.prompt('Enter the reason for voiding this same-day transaction:');
            if (reason === null) {
                return;
            }

            const trimmedReason = reason.trim();
            if (trimmedReason.length < 3) {
                showVoidFeedback('Please enter a more detailed void reason.', true);
                return;
            }

            if (!window.confirm('Void this same-day transaction? This will reverse POS inventory and dispense/administer tracking.')) {
                return;
            }

            const originalLabel = buttonElement ? buttonElement.textContent : '';
            if (buttonElement) {
                buttonElement.disabled = true;
                buttonElement.textContent = 'Voiding...';
            }

            try {
                const formData = new FormData();
                formData.append('transaction_id', String(transactionId));
                formData.append('pid', String(pid));
                formData.append('reason', trimmedReason);
                formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

                const response = await fetch('pos_void_transaction.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.error || 'Void failed');
                }

                showVoidFeedback(result.message || 'Transaction voided successfully.');
                window.location.reload();
            } catch (error) {
                if (buttonElement) {
                    buttonElement.disabled = false;
                    buttonElement.textContent = originalLabel || 'Void';
                }

                showVoidFeedback(error.message || 'Error voiding transaction.', true);
            }
        }

        async function undoBackdatedTransaction(receiptNumber, pid, buttonElement) {
            if (!receiptNumber || !pid) {
                showVoidFeedback('Missing backdated receipt information for undo.', true);
                return;
            }

            const reason = window.prompt('Enter the reason for undoing this backdated entry:', 'Backdated entry entered by mistake');
            if (reason === null) {
                return;
            }

            const trimmedReason = reason.trim();
            if (trimmedReason.length < 3) {
                showVoidFeedback('Please enter a more detailed undo reason.', true);
                return;
            }

            if (!window.confirm('Undo this backdated entry? This will reverse the saved backdated tracking entry.')) {
                return;
            }

            const originalLabel = buttonElement ? buttonElement.textContent : '';
            if (buttonElement) {
                buttonElement.disabled = true;
                buttonElement.textContent = 'Undoing...';
            }

            try {
                const response = await fetch('backdate_void.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        receipt_number: receiptNumber,
                        pid: pid,
                        reason: trimmedReason,
                        csrf_token_form: document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    })
                });
                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.error || 'Undo failed');
                }

                showVoidFeedback(result.message || 'Backdated transaction reversed successfully.');
                window.location.reload();
            } catch (error) {
                if (buttonElement) {
                    buttonElement.disabled = false;
                    buttonElement.textContent = originalLabel || 'Undo Backdate';
                }

                showVoidFeedback(error.message || 'Error undoing backdated transaction.', true);
            }
        }





        // Initialize DataTables
        document.addEventListener('DOMContentLoaded', function() {
            
            // Wait for jQuery and DataTables to be available
            function initializeDataTables() {
                if (typeof $ !== 'undefined' && typeof $.fn.DataTable !== 'undefined') {
                    // Initialize all tables with DataTables
                    const tables = ['all-transactions-table', 'pos-table', 'charges-table', 'credit-transactions-table', 'drugs-table'];
                    tables.forEach(tableId => {
                        const tableElement = document.getElementById(tableId);
                        if (tableElement && !$(tableElement).hasClass('dataTable')) {
                            try {
                                $('#' + tableId).DataTable({
                                    "pageLength": 25,
                                    "order": [[0, "desc"]],
                                    "language": {
                                        "search": "<?php echo xlt('Search'); ?>:",
                                        "lengthMenu": "<?php echo xlt('Show'); ?> _MENU_ <?php echo xlt('entries'); ?>",
                                        "info": "<?php echo xlt('Showing'); ?> _START_ <?php echo xlt('to'); ?> _END_ <?php echo xlt('of'); ?> _TOTAL_ <?php echo xlt('entries'); ?>",
                                        "infoEmpty": "<?php echo xlt('Showing 0 to 0 of 0 entries'); ?>",
                                        "infoFiltered": "(<?php echo xlt('filtered from'); ?> _MAX_ <?php echo xlt('total entries'); ?>)",
                                        "paginate": {
                                            "first": "<?php echo xlt('First'); ?>",
                                            "last": "<?php echo xlt('Last'); ?>",
                                            "next": "<?php echo xlt('Next'); ?>",
                                            "previous": "<?php echo xlt('Previous'); ?>"
                                        }
                                    }
                                });
                            } catch (error) {
                                console.error('Error initializing DataTable for ' + tableId + ':', error);
                            }
                        }
                    });
                } else {
                    // Retry after a short delay if libraries aren't loaded yet
                    setTimeout(initializeDataTables, 100);
                }
            }
            
            // Start initialization
            initializeDataTables();
        });
    </script>
    <div id="receipt-modal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
  <div id="receipt-modal-content" style="background:white; max-width:90vw; max-height:90vh; overflow:auto; border-radius:8px; box-shadow:0 2px 16px rgba(0,0,0,0.3); position:relative;">
    <button onclick="closeReceiptModal()" style="position:absolute; top:10px; right:10px; z-index:2; background:#dc3545; color:white; border:none; border-radius:4px; padding:4px 10px; cursor:pointer;">&times;</button>
    <div id="receipt-modal-body"></div>
  </div>
</div>

<!-- Email Receipt Modal -->
<div id="email-modal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center;">
  <div style="background:white; max-width:500px; width:90%; border-radius:8px; box-shadow:0 2px 16px rgba(0,0,0,0.3); position:relative; padding:30px;">
    <button onclick="closeEmailModal()" style="position:absolute; top:10px; right:10px; z-index:2; background:#dc3545; color:white; border:none; border-radius:4px; padding:4px 10px; cursor:pointer; font-size:18px;">&times;</button>
    
    <h4 style="margin:0 0 20px 0; color:#495057; text-align:center;">
      <i class="fas fa-envelope" style="color:#17a2b8; margin-right:8px;"></i>
      Email Receipt
    </h4>
    
    <div style="margin-bottom:20px;">
      <label style="display:block; margin-bottom:8px; font-weight:600; color:#495057;">
        Receipt Number: <span id="receipt-number-span" style="color:#17a2b8; font-weight:700;"></span>
      </label>
    </div>
    
    <div style="margin-bottom:20px;">
      <label for="email-input" style="display:block; margin-bottom:8px; font-weight:600; color:#495057;">
        Email Address:
      </label>
      <input type="email" id="email-input" placeholder="Enter email address" 
             style="width:100%; padding:12px; border:1px solid #ddd; border-radius:4px; font-size:14px; box-sizing:border-box;">
    </div>
    
    <div id="email-status" style="margin-bottom:20px;"></div>
    
    <div style="text-align:center;">
      <button id="send-email-btn" onclick="sendEmailReceipt()" 
              style="background:#17a2b8; color:white; border:none; border-radius:4px; padding:12px 24px; font-size:14px; cursor:pointer; margin-right:10px;">
        <i class="fas fa-paper-plane"></i> Send Email
      </button>
      <button onclick="closeEmailModal()" 
              style="background:#6c757d; color:white; border:none; border-radius:4px; padding:12px 24px; font-size:14px; cursor:pointer;">
        Cancel
      </button>
    </div>
  </div>
</div>


  </div>
</div>
<script>
function openReceiptModal(url) {
  const modal = document.getElementById('receipt-modal');
  const body = document.getElementById('receipt-modal-body');
  body.innerHTML = '<div style="padding:40px;text-align:center;">Loading...</div>';
  modal.style.display = 'flex';
  fetch(url)
    .then(r => r.text())
    .then(html => {
      // Remove <body> tags if present
      html = html.replace(/<\/?body[^>]*>/gi, '');
      body.innerHTML = html;
    })
    .catch(() => { body.innerHTML = '<div style="color:red;padding:40px;">Failed to load receipt.</div>'; });
}
function closeReceiptModal() {
  document.getElementById('receipt-modal').style.display = 'none';
}
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.open-receipt-modal').forEach(function(link) {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      openReceiptModal(this.getAttribute('href'));
    });
  });
});
</script>
</body>
</html> 
