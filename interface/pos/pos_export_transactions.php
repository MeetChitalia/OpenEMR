<?php
/**
 * Patient Transaction History Export
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

// Ensure user is logged in
if (!isset($_SESSION['authUserID'])) {
    die(xlt('Not authorized'));
}

$pid = $_GET['pid'] ?? 0;
$format = $_GET['format'] ?? 'csv';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$payment_method = $_GET['type'] ?? 'all';

if (!$pid) {
    die(xlt('Patient ID required'));
}

$patient_data = getPatientData($pid, 'fname,mname,lname,pubpid,DOB,phone_home,phone_cell,email,facility_id,care_team_facility');
if (!$patient_data) {
    die(xlt('Patient not found'));
}

function posExportSelectedFacilityId(): int
{
    return (int) ($_SESSION['facilityId'] ?? 0);
}

function posExportPatientBelongsToSelectedFacility(array $patientData): bool
{
    $selectedFacilityId = posExportSelectedFacilityId();
    if ($selectedFacilityId <= 0) {
        return true;
    }

    $patientFacilityId = (int) ($patientData['facility_id'] ?? 0);
    if ($patientFacilityId > 0 && $patientFacilityId === $selectedFacilityId) {
        return true;
    }

    $careTeamFacilities = trim((string) ($patientData['care_team_facility'] ?? ''));
    if ($careTeamFacilities === '') {
        return false;
    }

    $ids = array_filter(array_map('trim', explode('|', $careTeamFacilities)), static function ($value) {
        return $value !== '';
    });

    return in_array((string) $selectedFacilityId, $ids, true);
}

function posExportHasColumn(string $table, string $column): bool
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $row = sqlFetchArray(sqlStatement("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column]));
    return !empty($row);
}

function posExportDecodeItems($rawItems): array
{
    if (empty($rawItems)) {
        return [];
    }

    $decoded = json_decode((string) $rawItems, true);
    return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
}

function posExportReceiptFallbackRows($pid, $date_from, $date_to, $payment_method): array
{
    $query = "SELECT pt.id, pt.receipt_number, pt.created_date, pt.amount, pt.payment_method, pt.items,
                     COALESCE(CONCAT(TRIM(u.fname), ' ', TRIM(u.lname)), u.username, pt.user_id) AS created_by
              FROM pos_transactions AS pt
              LEFT JOIN users u ON (pt.user_id = u.username OR pt.user_id = CAST(u.id AS CHAR))
              WHERE pt.pid = ?
                AND pt.transaction_type IN ('external_payment', 'payment', 'credit_payment')
                AND pt.amount > 0";

    $params = [$pid];
    if (posExportHasColumn('pos_transactions', 'voided')) {
        $query .= " AND COALESCE(pt.voided, 0) = 0 AND pt.transaction_type != 'void'";
    }
    if ($date_from && $date_to) {
        $query .= " AND DATE(pt.created_date) BETWEEN ? AND ?";
        $params[] = $date_from;
        $params[] = $date_to;
    }
    if ($payment_method !== 'all') {
        $query .= " AND pt.payment_method = ?";
        $params[] = $payment_method;
    }
    if (posExportHasColumn('pos_transactions', 'facility_id') && posExportSelectedFacilityId() > 0) {
        $query .= " AND pt.facility_id = ?";
        $params[] = posExportSelectedFacilityId();
    }

    $query .= " ORDER BY pt.created_date DESC, pt.id DESC";

    $result = sqlStatement($query, $params);
    $rows = [];
    while ($row = sqlFetchArray($result)) {
        $rows[] = [
            'date' => $row['created_date'],
            'type' => 'POS',
            'description' => 'POS Receipt #' . ($row['receipt_number'] ?? ''),
            'amount' => (float) ($row['amount'] ?? 0),
            'payment_method' => $row['payment_method'] ?? 'N/A',
            'status' => 'Paid',
            'user' => trim((string) ($row['created_by'] ?? '')),
            'reference' => $row['receipt_number'] ?? '',
        ];
    }

    return $rows;
}

function posExportDrugFallbackRows($pid, $date_from, $date_to): array
{
    $query = "SELECT created_date, receipt_number, items
              FROM pos_transactions
              WHERE pid = ?
                AND transaction_type IN ('dispense', 'purchase', 'purchase_and_dispens', 'purchase_and_alterna', 'administer')";
    $params = [$pid];

    if (posExportHasColumn('pos_transactions', 'voided')) {
        $query .= " AND COALESCE(voided, 0) = 0 AND transaction_type != 'void'";
    }
    if ($date_from && $date_to) {
        $query .= " AND DATE(created_date) BETWEEN ? AND ?";
        $params[] = $date_from;
        $params[] = $date_to;
    }
    if (posExportHasColumn('pos_transactions', 'facility_id') && posExportSelectedFacilityId() > 0) {
        $query .= " AND facility_id = ?";
        $params[] = posExportSelectedFacilityId();
    }

    $query .= " ORDER BY created_date DESC";

    $rows = [];
    $result = sqlStatement($query, $params);
    while ($row = sqlFetchArray($result)) {
        foreach (posExportDecodeItems($row['items'] ?? '') as $item) {
            $qty = (float) ($item['quantity'] ?? 0);
            $price = (float) ($item['price'] ?? 0);
            $name = trim((string) ($item['name'] ?? $item['display_name'] ?? 'Unknown Item'));
            if ($name === '') {
                $name = 'Unknown Item';
            }
            $rows[] = [
                'date' => $row['created_date'],
                'type' => 'Drug',
                'description' => 'Drug Sale - ' . $name,
                'amount' => round($qty * $price, 2),
                'payment_method' => 'N/A',
                'status' => 'Completed',
                'user' => '',
                'reference' => $row['receipt_number'] ?? '',
            ];
        }
    }

    return $rows;
}

if (!posExportPatientBelongsToSelectedFacility($patient_data)) {
    die(xlt('Patient not available in the selected facility'));
}

// Collect all transaction data
$all_transactions = array();

// POS Receipts
$pos_query = "SELECT 
    created_date as date,
    'POS' as type,
    CONCAT('POS Receipt #', receipt_number) as description,
    amount,
    payment_method,
    'Paid' as status,
    created_by as user,
    receipt_number as reference
FROM pos_receipts 
WHERE pid = ?";

$pos_params = array($pid);
if ($date_from && $date_to) {
    $pos_query .= " AND DATE(created_date) BETWEEN ? AND ?";
    $pos_params[] = $date_from;
    $pos_params[] = $date_to;
}
if ($payment_method !== 'all') {
    $pos_query .= " AND payment_method = ?";
    $pos_params[] = $payment_method;
}
if (posExportHasColumn('pos_receipts', 'facility_id') && posExportSelectedFacilityId() > 0) {
    $pos_query .= " AND facility_id = ?";
    $pos_params[] = posExportSelectedFacilityId();
}

$pos_query .= " ORDER BY created_date DESC";

$pos_result = sqlStatement($pos_query, $pos_params);
$has_pos_receipts = false;
while ($row = sqlFetchArray($pos_result)) {
    $has_pos_receipts = true;
    $all_transactions[] = $row;
}
if (!$has_pos_receipts) {
    foreach (posExportReceiptFallbackRows($pid, $date_from, $date_to, $payment_method) as $fallbackRow) {
        $all_transactions[] = $fallbackRow;
    }
}

// Billing Charges
$billing_query = "SELECT 
    date,
    'Billing' as type,
    CONCAT(code, ' - ', code_text) as description,
    fee as amount,
    'N/A' as payment_method,
    CASE WHEN billed = 1 THEN 'Billed' ELSE 'Pending' END as status,
    user,
    id as reference
FROM billing 
WHERE pid = ? AND activity = 1";

$billing_params = array($pid);
if ($date_from && $date_to) {
    $billing_query .= " AND DATE(date) BETWEEN ? AND ?";
    $billing_params[] = $date_from;
    $billing_params[] = $date_to;
}

$billing_query .= " ORDER BY date DESC";

$billing_result = sqlStatement($billing_query, $billing_params);
while ($row = sqlFetchArray($billing_result)) {
    $all_transactions[] = $row;
}

// Payments
$payment_query = "SELECT 
    post_time as date,
    'Payment' as type,
    CONCAT('Payment - ', COALESCE(code, 'Unknown')) as description,
    pay_amount as amount,
    COALESCE(code, 'Unknown') as payment_method,
    'Paid' as status,
    post_user as user,
    sequence_no as reference
FROM ar_activity 
WHERE pid = ? AND pay_amount > 0 AND deleted IS NULL";

$payment_params = array($pid);
if ($date_from && $date_to) {
    $payment_query .= " AND DATE(post_time) BETWEEN ? AND ?";
    $payment_params[] = $date_from;
    $payment_params[] = $date_to;
}

$payment_query .= " ORDER BY post_time DESC";

$payment_result = sqlStatement($payment_query, $payment_params);
while ($row = sqlFetchArray($payment_result)) {
    $all_transactions[] = $row;
}

// Drug Sales
$drug_query = "SELECT 
    sale_date as date,
    'Drug' as type,
    CONCAT('Drug Sale - ', COALESCE(notes, 'Unknown Drug')) as description,
    fee as amount,
    'N/A' as payment_method,
    CASE WHEN billed = 1 THEN 'Billed' ELSE 'Pending' END as status,
    user,
    sale_id as reference
FROM drug_sales 
WHERE pid = ? AND fee > 0";

$drug_params = array($pid);
if ($date_from && $date_to) {
    $drug_query .= " AND DATE(sale_date) BETWEEN ? AND ?";
    $drug_params[] = $date_from;
    $drug_params[] = $date_to;
}

$drug_query .= " ORDER BY sale_date DESC";

$drug_result = sqlStatement($drug_query, $drug_params);
$has_drug_sales = false;
while ($row = sqlFetchArray($drug_result)) {
    $has_drug_sales = true;
    $all_transactions[] = $row;
}
if (!$has_drug_sales) {
    foreach (posExportDrugFallbackRows($pid, $date_from, $date_to) as $fallbackDrugRow) {
        $all_transactions[] = $fallbackDrugRow;
    }
}

// Sort all transactions by date (newest first)
usort($all_transactions, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

// Calculate summary statistics
$total_charges = 0;
$total_payments = 0;
$total_transactions = count($all_transactions);

foreach ($all_transactions as $transaction) {
    if ($transaction['type'] === 'Payment') {
        $total_payments += $transaction['amount'];
    } else {
        $total_charges += $transaction['amount'];
    }
}

$balance = $total_charges - $total_payments;

// Export based on format
switch ($format) {
    case 'csv':
        exportCSV($patient_data, $all_transactions, $total_charges, $total_payments, $balance, $total_transactions);
        break;
    case 'pdf':
        exportPDF($patient_data, $all_transactions, $total_charges, $total_payments, $balance, $total_transactions);
        break;
    case 'excel':
        exportExcel($patient_data, $all_transactions, $total_charges, $total_payments, $balance, $total_transactions);
        break;
    default:
        die(xlt('Invalid export format'));
}

function exportCSV($patient_data, $transactions, $total_charges, $total_payments, $balance, $total_transactions) {
    $filename = 'patient_transactions_' . $patient_data['pubpid'] . '_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Write patient information
    fputcsv($output, array('Patient Transaction History Report'));
    fputcsv($output, array(''));
    fputcsv($output, array('Patient Information'));
    fputcsv($output, array('Name', $patient_data['fname'] . ' ' . $patient_data['lname']));
    fputcsv($output, array('Patient ID', $patient_data['pubpid']));
    fputcsv($output, array('Date of Birth', $patient_data['DOB']));
    fputcsv($output, array('Phone', $patient_data['phone_cell'] ?: $patient_data['phone_home'] ?: 'N/A'));
    fputcsv($output, array('Email', $patient_data['email'] ?: 'N/A'));
    fputcsv($output, array(''));
    
    // Write summary
    fputcsv($output, array('Summary'));
    fputcsv($output, array('Total Transactions', $total_transactions));
    fputcsv($output, array('Total Charges', '$' . number_format($total_charges, 2)));
    fputcsv($output, array('Total Payments', '$' . number_format($total_payments, 2)));
    fputcsv($output, array('Current Balance', '$' . number_format($balance, 2)));
    fputcsv($output, array(''));
    
    // Write transaction headers
    fputcsv($output, array('Date', 'Type', 'Description', 'Amount', 'Payment Method', 'Status', 'User', 'Reference'));
    
    // Write transaction data
    foreach ($transactions as $transaction) {
        $amount_sign = $transaction['type'] === 'Payment' ? '-' : '+';
        fputcsv($output, array(
            date('Y-m-d H:i:s', strtotime($transaction['date'])),
            $transaction['type'],
            $transaction['description'],
            $amount_sign . '$' . number_format($transaction['amount'], 2),
            $transaction['payment_method'],
            $transaction['status'],
            $transaction['user'],
            $transaction['reference']
        ));
    }
    
    fclose($output);
    exit;
}

function exportPDF($patient_data, $transactions, $total_charges, $total_payments, $balance, $total_transactions) {
    // For PDF export, we'll create a simple HTML-based PDF
    $filename = 'patient_transactions_' . $patient_data['pubpid'] . '_' . date('Y-m-d_H-i-s') . '.html';
    
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Patient Transaction History</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { text-align: center; margin-bottom: 30px; }
            .patient-info { margin-bottom: 30px; }
            .summary { margin-bottom: 30px; }
            .table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
            .table th, .table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            .table th { background-color: #f2f2f2; }
            .positive { color: green; }
            .negative { color: red; }
            .summary-table { width: 50%; margin: 0 auto; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Patient Transaction History Report</h1>
            <p>Generated on: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
        
        <div class="patient-info">
            <h2>Patient Information</h2>
            <table class="summary-table">
                <tr><td><strong>Name:</strong></td><td><?php echo text($patient_data['fname'] . ' ' . $patient_data['lname']); ?></td></tr>
                <tr><td><strong>Patient ID:</strong></td><td><?php echo text($patient_data['pubpid']); ?></td></tr>
                <tr><td><strong>Date of Birth:</strong></td><td><?php echo text($patient_data['DOB']); ?></td></tr>
                <tr><td><strong>Phone:</strong></td><td><?php echo text($patient_data['phone_cell'] ?: $patient_data['phone_home'] ?: 'N/A'); ?></td></tr>
                <tr><td><strong>Email:</strong></td><td><?php echo text($patient_data['email'] ?: 'N/A'); ?></td></tr>
            </table>
        </div>
        
        <div class="summary">
            <h2>Summary</h2>
            <table class="summary-table">
                <tr><td><strong>Total Transactions:</strong></td><td><?php echo number_format($total_transactions); ?></td></tr>
                <tr><td><strong>Total Charges:</strong></td><td>$<?php echo number_format($total_charges, 2); ?></td></tr>
                <tr><td><strong>Total Payments:</strong></td><td>$<?php echo number_format($total_payments, 2); ?></td></tr>
                <tr><td><strong>Current Balance:</strong></td><td>$<?php echo number_format($balance, 2); ?></td></tr>
            </table>
        </div>
        
        <div class="transactions">
            <h2>Transaction Details</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th>Payment Method</th>
                        <th>Status</th>
                        <th>User</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $transaction): 
                        $amount_sign = $transaction['type'] === 'Payment' ? '-' : '+';
                        $amount_class = $transaction['type'] === 'Payment' ? 'positive' : 'negative';
                    ?>
                    <tr>
                        <td><?php echo text(date('Y-m-d H:i:s', strtotime($transaction['date']))); ?></td>
                        <td><?php echo text($transaction['type']); ?></td>
                        <td><?php echo text($transaction['description']); ?></td>
                        <td class="<?php echo $amount_class; ?>"><?php echo $amount_sign; ?>$<?php echo number_format($transaction['amount'], 2); ?></td>
                        <td><?php echo text($transaction['payment_method']); ?></td>
                        <td><?php echo text($transaction['status']); ?></td>
                        <td><?php echo text($transaction['user']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function exportExcel($patient_data, $transactions, $total_charges, $total_payments, $balance, $total_transactions) {
    // For Excel export, we'll create a CSV with Excel-compatible formatting
    $filename = 'patient_transactions_' . $patient_data['pubpid'] . '_' . date('Y-m-d_H-i-s') . '.xls';
    
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Write patient information
    fwrite($output, "Patient Transaction History Report\n");
    fwrite($output, "\n");
    fwrite($output, "Patient Information\n");
    fwrite($output, "Name\t" . $patient_data['fname'] . ' ' . $patient_data['lname'] . "\n");
    fwrite($output, "Patient ID\t" . $patient_data['pubpid'] . "\n");
    fwrite($output, "Date of Birth\t" . $patient_data['DOB'] . "\n");
    fwrite($output, "Phone\t" . ($patient_data['phone_cell'] ?: $patient_data['phone_home'] ?: 'N/A') . "\n");
    fwrite($output, "Email\t" . ($patient_data['email'] ?: 'N/A') . "\n");
    fwrite($output, "\n");
    
    // Write summary
    fwrite($output, "Summary\n");
    fwrite($output, "Total Transactions\t" . $total_transactions . "\n");
    fwrite($output, "Total Charges\t$" . number_format($total_charges, 2) . "\n");
    fwrite($output, "Total Payments\t$" . number_format($total_payments, 2) . "\n");
    fwrite($output, "Current Balance\t$" . number_format($balance, 2) . "\n");
    fwrite($output, "\n");
    
    // Write transaction headers
    fwrite($output, "Date\tType\tDescription\tAmount\tPayment Method\tStatus\tUser\tReference\n");
    
    // Write transaction data
    foreach ($transactions as $transaction) {
        $amount_sign = $transaction['type'] === 'Payment' ? '-' : '+';
        fwrite($output, 
            date('Y-m-d H:i:s', strtotime($transaction['date'])) . "\t" .
            $transaction['type'] . "\t" .
            $transaction['description'] . "\t" .
            $amount_sign . '$' . number_format($transaction['amount'], 2) . "\t" .
            $transaction['payment_method'] . "\t" .
            $transaction['status'] . "\t" .
            $transaction['user'] . "\t" .
            $transaction['reference'] . "\n"
        );
    }
    
    fclose($output);
    exit;
}
?> 
