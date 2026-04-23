<?php
/**
 * Dynamic Finder AJAX Handler
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../../globals.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/formatting.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

function dynamicFinderGetSelectedFacilityId(): int
{
    return isset($_SESSION['facilityId']) ? (int) $_SESSION['facilityId'] : 0;
}

// Helper function for filtering dates
function dateSearch($sSearch) {
    // Handle MMDDYYYY format (e.g., "05151990" for May 15, 1990)
    if (preg_match('/^\d{8}$/', $sSearch)) {
        $month = substr($sSearch, 0, 2);
        $day = substr($sSearch, 2, 2);
        $year = substr($sSearch, 4, 4);
        return "$year-$month-$day";
    }
    
    // Handle MMDDYY format (e.g., "051590" for May 15, 1990)
    if (preg_match('/^\d{6}$/', $sSearch)) {
        $month = substr($sSearch, 0, 2);
        $day = substr($sSearch, 2, 2);
        $year = '19' . substr($sSearch, 4, 2);
        return "$year-$month-$day";
    }
    
    // Handle full DOB search (e.g., "1990-05-15" or "05/15/1990")
    if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $sSearch)) {
        return $sSearch;
    }
    
    if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $sSearch)) {
        $parts = explode('/', $sSearch);
        $month = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
        $day = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
        $year = $parts[2];
        return "$year-$month-$day";
    }
    
    // If no delimiters and not 8 or 6 digits, then just search the whole date
    return "%$sSearch%";
}

function buildPatientSearchClause(string $searchTerm): array
{
    $searchTerm = trim($searchTerm);
    $normalizedDobSearch = existingFinderNormalizeSearchDate($searchTerm);

    if ($searchTerm === '') {
        return ['sql' => '', 'params' => []];
    }

    if (ctype_digit($searchTerm)) {
        $phonePrefix = $searchTerm . '%';
        $clauses = [
            "pid = ?",
            "pubpid LIKE ?",
            "phone_cell LIKE ?",
            "phone_home LIKE ?",
        ];
        $params = [
            (int) $searchTerm,
            $searchTerm . '%',
            $phonePrefix,
            $phonePrefix,
        ];

        if ($normalizedDobSearch !== '') {
            $clauses[] = "DOB = ?";
            $params[] = $normalizedDobSearch;
        }

        return [
            'sql' => ' AND (' . implode(' OR ', $clauses) . ')',
            'params' => $params,
        ];
    }

    $prefix = $searchTerm . '%';
    return [
        'sql' => " AND (lname LIKE ? OR fname LIKE ? OR mname LIKE ? OR pubpid LIKE ?)",
        'params' => [$prefix, $prefix, $prefix, $prefix],
    ];
}

function existingFinderNormalizeSearchDate(string $search): string
{
    $normalized = trim(dateSearch($search), '%');
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized) ? $normalized : '';
}

function initializePatientMetricMap(array $pids): array
{
    $map = [];
    foreach ($pids as $pid) {
        $map[(int) $pid] = 0.0;
    }

    return $map;
}

function fetchGroupedPatientMetricMap(string $sql, array $pids): array
{
    $map = initializePatientMetricMap($pids);
    if (empty($pids)) {
        return $map;
    }

    $placeholders = implode(',', array_fill(0, count($pids), '?'));
    $res = sqlStatement(sprintf($sql, $placeholders), $pids);
    while ($row = sqlFetchArray($res)) {
        $map[(int) ($row['pid'] ?? 0)] = (float) ($row['metric_value'] ?? 0);
    }

    return $map;
}

function fetchPatientReceiptSummaryMap(array $pids): array
{
    $map = [];
    foreach ($pids as $pid) {
        $map[(int) $pid] = [
            'receipt_count' => 0,
            'total_payments' => 0.0,
        ];
    }

    if (empty($pids)) {
        return $map;
    }

    $placeholders = implode(',', array_fill(0, count($pids), '?'));
    $res = sqlStatement(
        "SELECT pid, COUNT(*) AS receipt_count, COALESCE(SUM(amount), 0) AS total_payments
           FROM pos_receipts
          WHERE pid IN ($placeholders)
            AND amount > 0
       GROUP BY pid",
        $pids
    );

    while ($row = sqlFetchArray($res)) {
        $pid = (int) ($row['pid'] ?? 0);
        $map[$pid] = [
            'receipt_count' => (int) ($row['receipt_count'] ?? 0),
            'total_payments' => (float) ($row['total_payments'] ?? 0),
        ];
    }

    return $map;
}

function fetchPatientFinancialSnapshotMap(array $pids): array
{
    $billingTotals = fetchGroupedPatientMetricMap(
        "SELECT pid, COALESCE(SUM(fee), 0) AS metric_value
           FROM billing
          WHERE pid IN (%s)
            AND activity = 1
            AND fee > 0
       GROUP BY pid",
        $pids
    );
    $drugTotals = fetchGroupedPatientMetricMap(
        "SELECT pid, COALESCE(SUM(fee), 0) AS metric_value
           FROM drug_sales
          WHERE pid IN (%s)
            AND fee > 0
       GROUP BY pid",
        $pids
    );
    $paymentTotals = fetchGroupedPatientMetricMap(
        "SELECT pid, COALESCE(SUM(pay_amount), 0) AS metric_value
           FROM ar_activity
          WHERE pid IN (%s)
            AND deleted IS NULL
            AND pay_amount > 0
       GROUP BY pid",
        $pids
    );
    $receiptSummaries = fetchPatientReceiptSummaryMap($pids);
    $posPaymentTotals = fetchGroupedPatientMetricMap(
        "SELECT pid, COALESCE(SUM(amount), 0) AS metric_value
           FROM pos_transactions
          WHERE pid IN (%s)
            AND amount > 0
            AND transaction_type IN ('external_payment', 'payment', 'credit_payment')
       GROUP BY pid",
        $pids
    );
    $voidTotals = fetchGroupedPatientMetricMap(
        "SELECT pid, COALESCE(SUM(amount), 0) AS metric_value
           FROM pos_transactions
          WHERE pid IN (%s)
            AND transaction_type = 'void'
            AND amount < 0
       GROUP BY pid",
        $pids
    );
    $refundTotals = fetchGroupedPatientMetricMap(
        "SELECT pid, COALESCE(SUM(refund_amount), 0) AS metric_value
           FROM pos_refunds
          WHERE pid IN (%s)
            AND refund_type = 'payment'
            AND refund_amount > 0
       GROUP BY pid",
        $pids
    );

    $snapshot = [];
    foreach ($pids as $pid) {
        $pid = (int) $pid;
        $totalCharges = ($billingTotals[$pid] ?? 0) + ($drugTotals[$pid] ?? 0);
        $balance = max(0, $totalCharges - ($paymentTotals[$pid] ?? 0));

        $receiptCount = (int) ($receiptSummaries[$pid]['receipt_count'] ?? 0);
        $receiptTotal = (float) ($receiptSummaries[$pid]['total_payments'] ?? 0);
        $baseTotal = $receiptCount > 0 ? $receiptTotal : (float) ($posPaymentTotals[$pid] ?? 0);
        $netTotal = $baseTotal + (float) ($voidTotals[$pid] ?? 0) - (float) ($refundTotals[$pid] ?? 0);

        $snapshot[$pid] = [
            'balance' => $balance,
            'total_paid' => max(0, $netTotal),
        ];
    }

    return $snapshot;
}

// Helper function to calculate patient balance for POS workflows
function calculatePatientBalance($pid) {
    // Calculate balance for this patient using comprehensive calculation
    // Check for any charges in billing table (including clinical billing) - include unbilled charges
    $billing_charges = sqlQuery(
        "SELECT SUM(fee) AS total_charges FROM billing WHERE pid = ? AND activity = 1 AND fee > 0",
        array($pid)
    );

    // Check for any drug sales
    $drug_charges = sqlQuery(
        "SELECT SUM(fee) AS total_charges FROM drug_sales WHERE pid = ? AND fee > 0",
        array($pid)
    );

    // Check for any payments made
    $payments = sqlQuery(
        "SELECT SUM(pay_amount) AS total_payments FROM ar_activity WHERE pid = ? AND deleted IS NULL AND pay_amount > 0",
        array($pid)
    );

    // Calculate total outstanding balance with explicit type casting and null handling
    $billing_total = 0.0;
    if (is_array($billing_charges) && isset($billing_charges['total_charges']) && $billing_charges['total_charges'] !== null && $billing_charges['total_charges'] !== '') {
        $billing_total = floatval($billing_charges['total_charges']);
    }

    $drug_total = 0.0;
    if (is_array($drug_charges) && isset($drug_charges['total_charges']) && $drug_charges['total_charges'] !== null && $drug_charges['total_charges'] !== '') {
        $drug_total = floatval($drug_charges['total_charges']);
    }

    $payment_total = 0.0;
    if (is_array($payments) && isset($payments['total_payments']) && $payments['total_payments'] !== null && $payments['total_payments'] !== '') {
        $payment_total = floatval($payments['total_payments']);
    }

    $total_charges = $billing_total + $drug_total;
    $calculated_balance = $total_charges - $payment_total;

    // Ensure balance is not negative
    if ($calculated_balance < 0) {
        $calculated_balance = 0;
    }
    
    return $calculated_balance;
}

// Helper function to calculate lifetime patient payments for finder display only.
function calculatePatientLifetimePayments($pid) {
    $receipts = sqlQuery(
        "SELECT COUNT(*) AS receipt_count, SUM(amount) AS total_payments
         FROM pos_receipts
         WHERE pid = ? AND amount > 0",
        array($pid)
    );

    $receiptCount = (is_array($receipts) && isset($receipts['receipt_count'])) ? (int) $receipts['receipt_count'] : 0;
    $receiptTotal = (is_array($receipts) && isset($receipts['total_payments']) && $receipts['total_payments'] !== null && $receipts['total_payments'] !== '')
        ? floatval($receipts['total_payments'])
        : 0.0;

    $posPayments = sqlQuery(
        "SELECT SUM(amount) AS total_payments
         FROM pos_transactions
         WHERE pid = ?
           AND amount > 0
           AND transaction_type IN ('external_payment', 'payment', 'credit_payment')",
        array($pid)
    );

    $posPaymentTotal = (is_array($posPayments) && isset($posPayments['total_payments']) && $posPayments['total_payments'] !== null && $posPayments['total_payments'] !== '')
        ? floatval($posPayments['total_payments'])
        : 0.0;

    $paymentRefunds = sqlQuery(
        "SELECT SUM(refund_amount) AS total_refunds
         FROM pos_refunds
         WHERE pid = ?
           AND refund_type = 'payment'
           AND refund_amount > 0",
        array($pid)
    );

    $refundTotal = (is_array($paymentRefunds) && isset($paymentRefunds['total_refunds']) && $paymentRefunds['total_refunds'] !== null && $paymentRefunds['total_refunds'] !== '')
        ? floatval($paymentRefunds['total_refunds'])
        : 0.0;

    $voidedPayments = sqlQuery(
        "SELECT SUM(amount) AS total_voids
         FROM pos_transactions
         WHERE pid = ?
           AND transaction_type = 'void'
           AND amount < 0",
        array($pid)
    );

    $voidTotal = (is_array($voidedPayments) && isset($voidedPayments['total_voids']) && $voidedPayments['total_voids'] !== null && $voidedPayments['total_voids'] !== '')
        ? floatval($voidedPayments['total_voids'])
        : 0.0;

    $baseTotal = $receiptCount > 0 ? $receiptTotal : $posPaymentTotal;
    $netTotal = $baseTotal + $voidTotal - $refundTotal;

    return $netTotal > 0 ? $netTotal : 0.0;
}

// Ensure this isn't called directly
if (!CsrfUtils::verifyCsrfToken($_GET['csrf_token_form'])) {
    CsrfUtils::csrfNotVerified();
}

// Check if the user has permission to read patient demographics
if (!AclMain::aclCheckCore('patients', 'demo', '', array('read'))) {
    header("HTTP/1.0 403 Forbidden");
    echo "Access Denied";
    exit;
}

// Get search term
$searchTerm = isset($_GET['sSearch']) ? trim($_GET['sSearch']) : '';
$start = isset($_GET['iDisplayStart']) ? intval($_GET['iDisplayStart']) : 0;
$length = isset($_GET['iDisplayLength']) ? intval($_GET['iDisplayLength']) : 10;

// Build facility filter
$facilityFilter = "";
$facilityParams = array();
 $selectedFacilityId = dynamicFinderGetSelectedFacilityId();

if ($selectedFacilityId > 0) {
    $facilityFilter = " AND (facility_id = ? OR FIND_IN_SET(?, REPLACE(COALESCE(care_team_facility, ''), '|', ',')) > 0)";
    $facilityParams = [$selectedFacilityId, $selectedFacilityId];
} elseif (!empty($GLOBALS['restrict_user_facility'])) {
    // Get user's accessible facilities
    $userFacilities = array();
    $facilityQuery = "SELECT DISTINCT facility_id FROM users_facility WHERE tablename = 'users' AND table_id = ?";
    $facilityResult = sqlStatement($facilityQuery, array($_SESSION['authUserID']));
    
    while ($facilityRow = sqlFetchArray($facilityResult)) {
        $userFacilities[] = $facilityRow['facility_id'];
    }
    
    // If no facilities found, use user's default facility
    if (empty($userFacilities)) {
        $userQuery = "SELECT facility_id FROM users WHERE id = ?";
        $userResult = sqlQuery($userQuery, array($_SESSION['authUserID']));
        
        if ($userResult) {
            $userData = null;
            if (is_object($userResult) && method_exists($userResult, 'FetchRow')) {
                $userData = $userResult->FetchRow();
            } elseif (is_array($userResult)) {
                $userData = $userResult;
            }
            
            if ($userData && !empty($userData['facility_id'])) {
                $userFacilities[] = $userData['facility_id'];
            }
        }
    }
    
    // Build facility filter
    if (!empty($userFacilities)) {
        $facilityPlaceholders = implode(',', array_fill(0, count($userFacilities), '?'));
        $facilityFilter = " AND facility_id IN ($facilityPlaceholders)";
        $facilityParams = $userFacilities;
    }
}

if (!empty($searchTerm)) {
    $searchClause = buildPatientSearchClause($searchTerm);
    $query = "SELECT pid, lname, fname, mname, phone_cell, phone_home, pubpid, DOB
              FROM patient_data 
              WHERE 1 = 1" .
              $searchClause['sql'] .
              $facilityFilter . "
              ORDER BY lname, fname 
              LIMIT ?, ?";

    $params = array_merge(
        $searchClause['params'],
        $facilityParams,
        [$start, $length]
    );

    $res = sqlStatement($query, $params);
    
    // Count total matching records
    $countQuery = "SELECT COUNT(*) as total 
                   FROM patient_data 
                   WHERE 1 = 1" .
                   $searchClause['sql'] . "
                   $facilityFilter";
    
    $countParams = array_merge(
        $searchClause['params'],
        $facilityParams
    );
    
    $countRes = sqlStatement($countQuery, $countParams);
    $totalRecords = sqlFetchArray($countRes)['total'];
    
} else {
    // No search - get all records
    $whereClause = !empty($facilityFilter) ? "WHERE 1=1 $facilityFilter" : "";
    
    $query = "SELECT pid, lname, fname, mname, phone_cell, phone_home, pubpid, DOB
              FROM patient_data 
              $whereClause
              ORDER BY lname, fname 
              LIMIT ?, ?";
    
    $params = array_merge($facilityParams, array($start, $length));
    

    
    $res = sqlStatement($query, $params);
    
    // Count total records
    $countQuery = "SELECT COUNT(*) as total FROM patient_data $whereClause";
    $countRes = sqlStatement($countQuery, $facilityParams);
    $totalRecords = sqlFetchArray($countRes)['total'];
}

// Build response data
$data = array();
$rows = [];

while ($row = sqlFetchArray($res)) {
    $rows[] = $row;
}

$pids = array_values(array_unique(array_map(static function ($row) {
    return (int) ($row['pid'] ?? 0);
}, $rows ?? [])));
$patientFinancials = fetchPatientFinancialSnapshotMap($pids);

foreach (($rows ?? []) as $row) {
    
    // Build the row data in the format expected by DataTables
    $arow = array();
    
    // Add each column in the order expected by the frontend
    // The frontend expects: name, phone_cell, DOB, pid, total_paid, pay_now
    
    // Name column (will be rendered by wrapInLink)
    $name = $row['lname'];
    if ($name && $row['fname']) {
        $name .= ', ';
    }
    if ($row['fname']) {
        $name .= $row['fname'];
    }
    if ($row['mname']) {
        $name .= ' ' . $row['mname'];
    }
    $arow[] = $name;
    
    // Phone column
    $phone = !empty($row['phone_cell']) ? $row['phone_cell'] : $row['phone_home'];
    $arow[] = $phone;
    
    // DOB column
    $dob = $row['DOB'] ? date('m/d/Y', strtotime($row['DOB'])) : '';
    $arow[] = $dob;
    
    // PID column
    $arow[] = $row['pid'];
    
    // Total paid column (sum of lifetime patient receipts)
    $patientMetrics = $patientFinancials[(int) ($row['pid'] ?? 0)] ?? ['total_paid' => 0.0, 'balance' => 0.0];
    $totalPaid = (float) ($patientMetrics['total_paid'] ?? 0);
    $arow[] = $totalPaid;

    // Pay Now column (keep passing actual balance for POS routing)
    $balance = (float) ($patientMetrics['balance'] ?? 0);
    $arow[] = $row['pid'] . '|' . $balance;
    
    // Add DataTables row properties
    $arow['DT_RowId'] = 'pid_' . $row['pid'];
    
    $data[] = $arow;
}



// Return JSON response
$response = array(
    'sEcho' => isset($_GET['sEcho']) ? intval($_GET['sEcho']) : 1,
    'iTotalRecords' => $totalRecords,
    'iTotalDisplayRecords' => $totalRecords,
    'aaData' => $data
);

header('Content-Type: application/json');
echo json_encode($response);
exit;
?>
