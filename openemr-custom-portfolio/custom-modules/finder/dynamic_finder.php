<?php

/**
 * dynamic_finder.php
 *
 * Sponsored by David Eschelbacher, MD
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2012-2016 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(dirname(__FILE__) . "/../../globals.php");
require_once "$srcdir/user.inc";
require_once "$srcdir/options.inc.php";

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\OeUI\OemrUI;

function finderGetSelectedFacilityId(): int
{
    return isset($_SESSION['facilityId']) ? (int) $_SESSION['facilityId'] : 0;
}

function finderBuildSelectedFacilityWhereClause(int $facilityId): string
{
    return " WHERE (facility_id = ? OR FIND_IN_SET(?, REPLACE(COALESCE(care_team_facility, ''), '|', ',')) > 0)";
}

function finderCalculatePatientBalance($pid)
{
    $billingCharges = sqlQuery(
        "SELECT SUM(fee) AS total_charges FROM billing WHERE pid = ? AND activity = 1 AND fee > 0",
        array($pid)
    );
    $drugCharges = sqlQuery(
        "SELECT SUM(fee) AS total_charges FROM drug_sales WHERE pid = ? AND fee > 0",
        array($pid)
    );
    $payments = sqlQuery(
        "SELECT SUM(pay_amount) AS total_payments FROM ar_activity WHERE pid = ? AND deleted IS NULL AND pay_amount > 0",
        array($pid)
    );

    $billingTotal = (is_array($billingCharges) && !empty($billingCharges['total_charges'])) ? (float) $billingCharges['total_charges'] : 0.0;
    $drugTotal = (is_array($drugCharges) && !empty($drugCharges['total_charges'])) ? (float) $drugCharges['total_charges'] : 0.0;
    $paymentTotal = (is_array($payments) && !empty($payments['total_payments'])) ? (float) $payments['total_payments'] : 0.0;
    $balance = ($billingTotal + $drugTotal) - $paymentTotal;

    return $balance > 0 ? $balance : 0.0;
}

function finderCalculatePatientLifetimePayments($pid)
{
    $receipts = sqlQuery(
        "SELECT COUNT(*) AS receipt_count, SUM(amount) AS total_payments
         FROM pos_receipts
         WHERE pid = ? AND amount > 0",
        array($pid)
    );

    $receiptCount = (is_array($receipts) && isset($receipts['receipt_count'])) ? (int) $receipts['receipt_count'] : 0;
    $receiptTotal = (is_array($receipts) && isset($receipts['total_payments']) && $receipts['total_payments'] !== null && $receipts['total_payments'] !== '')
        ? (float) $receipts['total_payments']
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
        ? (float) $posPayments['total_payments']
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
        ? (float) $paymentRefunds['total_refunds']
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
        ? (float) $voidedPayments['total_voids']
        : 0.0;

    $baseTotal = $receiptCount > 0 ? $receiptTotal : $posPaymentTotal;
    $netTotal = $baseTotal + $voidTotal - $refundTotal;

    return $netTotal > 0 ? $netTotal : 0.0;
}

function buildFinderFallbackRowsHtml($includePos = true)
{
    $facilityFilter = "";
    $facilityParams = array();
    $selectedFacilityId = finderGetSelectedFacilityId();
    $pageSize = empty($GLOBALS['gbl_pt_list_page_size']) ? 10 : (int) $GLOBALS['gbl_pt_list_page_size'];
    if ($pageSize < 1) {
        $pageSize = 10;
    }

    if ($selectedFacilityId > 0) {
        $facilityFilter = finderBuildSelectedFacilityWhereClause($selectedFacilityId);
        $facilityParams = [$selectedFacilityId, $selectedFacilityId];
    } elseif (!empty($GLOBALS['restrict_user_facility'])) {
        $userFacilities = array();
        $facilityResult = sqlStatement(
            "SELECT DISTINCT facility_id FROM users_facility WHERE tablename = 'users' AND table_id = ?",
            array($_SESSION['authUserID'])
        );

        while ($facilityRow = sqlFetchArray($facilityResult)) {
            $userFacilities[] = $facilityRow['facility_id'];
        }

        if (empty($userFacilities)) {
            $userRow = sqlQuery("SELECT facility_id FROM users WHERE id = ?", array($_SESSION['authUserID']));
            if (is_array($userRow) && !empty($userRow['facility_id'])) {
                $userFacilities[] = $userRow['facility_id'];
            }
        }

        if (!empty($userFacilities)) {
            $facilityFilter = " WHERE facility_id IN (" . implode(',', array_fill(0, count($userFacilities), '?')) . ")";
            $facilityParams = $userFacilities;
        }
    }

    $rows = sqlStatement(
        "SELECT pid, lname, fname, mname, phone_cell, phone_home, pubpid, DOB
         FROM patient_data" . $facilityFilter . "
         ORDER BY lname, fname
         LIMIT ?",
        array_merge($facilityParams, [$pageSize])
    );

    $html = '';
    while ($row = sqlFetchArray($rows)) {
        $name = trim(($row['lname'] ?? '') . ((empty($row['fname'])) ? '' : ', ' . $row['fname']) . ((empty($row['mname'])) ? '' : ' ' . $row['mname']));
        $phone = !empty($row['phone_cell']) ? $row['phone_cell'] : ($row['phone_home'] ?? '');
        $dob = !empty($row['DOB']) ? date('m/d/Y', strtotime($row['DOB'])) : '';
        $totalPaid = finderCalculatePatientLifetimePayments($row['pid']);
        $totalPaidHtml = $totalPaid > 0
            ? '<span class="text-success font-weight-bold">$' . text(number_format($totalPaid, 2)) . '</span>'
            : '<span class="text-success">$0.00</span>';
        $nameHtml = '<span class="d-inline-flex align-items-center">'
            . text($name)
            . '<a href="#" class="btn btn-link btn-sm p-0 ml-1 edit-patient-btn" title="' . attr(xl('Edit Patient Demographics')) . '">'
            . '<i class="fa fa-edit text-primary"></i></a></span>';
        $balance = finderCalculatePatientBalance($row['pid']);
        $posUrl = $GLOBALS['webroot'] . '/interface/pos/pos_modal.php?pid=' . urlencode((string) $row['pid']);
        if ($balance > 0) {
            $posUrl .= '&balance=' . urlencode((string)$balance);
        }
        $posHtml = $includePos
            ? '<a class="btn btn-info btn-sm pay-now-btn" data-pid="' . attr($row['pid']) . '" data-balance="' . attr($balance) . '" href="' . attr($posUrl) . '" onclick="event.stopPropagation();"><i class="fa fa-credit-card"></i> POS</a>'
            : '';

        $html .= '<tr id="pid_' . attr($row['pid']) . '">'
            . '<td>' . $nameHtml . '</td>'
            . '<td>' . text($phone) . '</td>'
            . '<td>' . text($dob) . '</td>'
            . '<td>' . text($row['pid']) . '</td>'
            . '<td>' . $totalPaidHtml . '</td>'
            . '<td>' . $posHtml . '</td>'
            . '</tr>';
    }

    return $html;
}

$uspfx = 'patient_finder.'; //substr(__FILE__, strlen($webserver_root)) . '.';
$patient_finder_exact_search = prevSetting($uspfx, 'patient_finder_exact_search', 'patient_finder_exact_search', '');
 $finderFallbackRowsHtml = buildFinderFallbackRowsHtml(true);

$popup = empty($_REQUEST['popup']) ? 0 : 1;
$searchAny = empty($_GET['search_any']) ? "" : $_GET['search_any'];
unset($_GET['search_any']);
// Generate some code based on the list of columns.
//
$colcount = 0;
$header0 = "";
$header = "";
$coljson = "";
$orderjson = "";
$res = sqlStatement("SELECT option_id, title, toggle_setting_1 FROM list_options WHERE " .
    "list_id = 'ptlistcols' AND activity = 1 ORDER BY seq, title");
$sort_dir_map = generate_list_map('Sort_Direction');
while ($row = sqlFetchArray($res)) {
    $colname = $row['option_id'];
    $colorder = $sort_dir_map[$row['toggle_setting_1']]; // Get the title 'asc' or 'desc' using the value
    $title = xl_list_label($row['title']);
    $title1 = ($title == xl('Full Name')) ? xl('Name') : $title;
    
    // Skip SSN column
    if ($colname == 'ss') {
        continue;
    }
    
    // Change phone_home title to Mobile Phone and use phone_cell field
    if ($colname == 'phone_home') {
        $title = xl('Mobile Phone');
        $title1 = xl('Mobile Phone');
        $colname = 'phone_cell'; // Use phone_cell field instead of phone_home
    }
    
    $header .= "   <th>";
    $header .= text($title);
    $header .= "</th>\n";
    // Hide individual search boxes - we'll use unified search instead
    $header0 .= "   <td style='display: none;'><input type='text' size='20' ";
    $header0 .= "value='' class='form-control search_init' placeholder='" . xla("Search by") . " " . $title1 . "'/></td>\n";
    if ($coljson) {
        $coljson .= ", ";
    }

    $coljson .= "{\"sName\": \"" . addcslashes($colname, "\t\r\n\"\\") . "\"";
    if ($title1 == xl('Name')) {
        $coljson .= ", \"mRender\": wrapInLink";
    }
    $coljson .= "}";
    if ($orderjson) {
        $orderjson .= ", ";
    }
    $orderjson .= "[\"$colcount\", \"" . addcslashes($colorder, "\t\r\n\"\\") . "\"]";
    ++$colcount;
}

// Add Total Paid column
$header .= "   <th>" . xlt('Total Paid') . "</th>\n";
$header0 .= "   <td style='display: none;'><input type='text' size='20' value='' class='form-control search_init' placeholder='" . xla("Search by Total Paid") . "'/></td>\n";
if ($coljson) {
    $coljson .= ", ";
}
$coljson .= "{\"sName\": \"total_paid\", \"mRender\": formatTotalPaid}";
if ($orderjson) {
    $orderjson .= ", ";
}
$orderjson .= "[\"$colcount\", \"asc\"]";
++$colcount;

// Add Pay Now column
$header .= "   <th>" . xlt('POS') . "</th>\n";
$header0 .= "   <td style='display: none;'></td>\n";
if ($coljson) {
    $coljson .= ", ";
}
$coljson .= "{\"sName\": \"pay_now\", \"mRender\": formatPayNow}";
if ($orderjson) {
    $orderjson .= ", ";
}
$orderjson .= "[\"$colcount\", \"asc\"]";
++$colcount;


$loading = "<div class='spinner-border' role='status'><span class='sr-only'>" . xlt("Loading") . "...</span></div>";
?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(['opener', 'datetime-picker']); ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap4.min.css">
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap4.min.js"></script>
    <title><?php echo xlt("Patient Finder"); ?></title>
<style>
    /* Finder Processing style */
    div.dataTables_wrapper div.dataTables_processing {
        width: auto;
        margin: 0;
        color: var(--danger);
        transform: translateX(-50%);
    }
    .card {
        border: 0;
        border-radius: 0;
    }

    @media screen and (max-width: 640px) {
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter {
            float: inherit;
            text-align: justify;
        }
    }

            /* Modern unified search styling */
        .unified-search-container {
            background: #ffffff;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #e5e7eb;
        }

        /* Pay Now button styling (matching Patient Dashboard) */
        .pay-now-btn {
            background: linear-gradient(135deg, #BF1542 0%, #A01238 100%);
            border: none;
            color: white;
            box-shadow: 0 2px 8px rgba(191, 21, 66, 0.2);
            transition: all 0.3s ease;
        }

        .pay-now-btn:hover {
            background: linear-gradient(135deg, #A01238 0%, #8A0F30 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(191, 21, 66, 0.25);
            color: white;
        }

        .pay-now-btn:active {
            transform: translateY(0);
            box-shadow: 0 1px 4px rgba(191, 21, 66, 0.2);
        }


    
    /* Increase font size for Finder table */
    #pt_table {
        font-size: 15px; /* Increased from default */
    }
    
    #pt_table th,
    #pt_table td {
        font-size: 15px; /* Increased from default */
        padding: 12px 8px; /* Slightly more padding for better readability */
    }
    
    /* Increase font size for main menu */
    .navbar,
    .navbar-nav,
    .nav-link {
        font-size: 15px; /* Increased from default */
    }
    
    /* Increase font size for inventory table */
    .inventory-table,
    .inventory-table th,
    .inventory-table td {
        font-size: 15px; /* Increased from default */
    }
    
    .page-title {
        font-size: 28px;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 8px;
    }
    
    .page-subtitle {
        font-size: 16px;
        color: #6b7280;
        margin-bottom: 24px;
    }
    
    .search-controls {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-bottom: 16px;
    }
    
    .search-input-wrapper {
        position: relative;
        flex: 1;
        max-width: 500px;
    }
    
    .search-input-wrapper .form-control {
        border: 1px solid #d1d5db;
        border-radius: 8px;
        padding: 12px 16px 12px 44px;
        font-size: 14px;
        background-color: #f9fafb;
        transition: all 0.2s ease;
    }
    
    .search-input-wrapper .form-control:focus {
        border-color: #3b82f6;
        background-color: #ffffff;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .search-icon {
        position: absolute;
        left: 16px;
        top: 50%;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 16px;
    }
    
    .action-buttons {
        display: flex;
        gap: 12px;
        align-items: center;
    }

    .finder-search-results {
        display: none;
        margin-top: 18px;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        overflow: hidden;
        background: #ffffff;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
        max-height: 60vh;
        overflow-y: auto;
        position: relative;
        z-index: 25;
        pointer-events: auto;
    }

    .finder-search-result-item {
        padding: 16px 20px;
        border-top: 1px solid #eef2f7;
        cursor: pointer;
        transition: background 0.16s ease;
        position: relative;
        z-index: 26;
        pointer-events: auto;
        user-select: none;
    }

    .finder-search-result-item:first-child {
        border-top: none;
    }

    .finder-search-result-item:hover {
        background: #f8fbff;
    }

    .finder-search-result-name {
        font-size: 16px;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 8px;
    }

    .finder-search-result-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 18px;
        color: #6b7280;
        font-size: 14px;
    }

    .finder-search-result-actions {
        display: flex;
        gap: 10px;
        margin-top: 14px;
    }

    .finder-search-action-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 14px;
        border-radius: 8px;
        border: 1px solid #dbe4f0;
        background: #ffffff;
        color: #1f2937;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
    }

    .finder-search-action-btn:hover,
    .finder-search-action-btn:focus {
        text-decoration: none;
        color: #1f2937;
        background: #f8fbff;
    }

    .finder-search-action-btn-primary {
        background: #2563eb;
        border-color: #2563eb;
        color: #ffffff;
    }

    .finder-search-action-btn-primary:hover,
    .finder-search-action-btn-primary:focus {
        background: #1d4ed8;
        border-color: #1d4ed8;
        color: #ffffff;
    }

    .finder-search-empty {
        padding: 18px 20px;
        color: #6b7280;
        font-size: 14px;
        background: #ffffff;
    }

    .finder-search-results-header {
        padding: 14px 20px;
        background: #f8fbff;
        border-bottom: 1px solid #eef2f7;
        font-size: 13px;
        font-weight: 600;
        color: #4b5563;
        letter-spacing: 0.02em;
        text-transform: uppercase;
    }

    .finder-icon-btn {
        width: 38px;
        height: 38px;
        border-radius: 8px;
        border: 1px solid #dbe3ec;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        color: #7b8794;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.18s ease;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
        padding: 0;
        font-size: 16px;
    }

    .finder-icon-btn:hover {
        color: #2563eb;
        border-color: #bfd7ff;
        background: linear-gradient(180deg, #f9fbff 0%, #eef5ff 100%);
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(37, 99, 235, 0.12);
    }

    .finder-icon-btn:focus {
        outline: none;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.14);
    }

    .finder-icon-divider {
        width: 1px;
        height: 34px;
        background: linear-gradient(180deg, rgba(203, 213, 225, 0) 0%, rgba(203, 213, 225, 1) 50%, rgba(203, 213, 225, 0) 100%);
    }
    
    .btn-modern {
        border-radius: 8px;
        font-weight: 500;
        padding: 10px 16px;
        border: none;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .btn-primary-modern {
        background-color: #3b82f6;
        color: white;
    }
    
    .btn-primary-modern:hover {
        background-color: #2563eb;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    }
    
    .btn-secondary-modern {
        background-color: #f3f4f6;
        color: #374151;
        border: 1px solid #d1d5db;
    }
    
    .btn-secondary-modern:hover {
        background-color: #e5e7eb;
        transform: translateY(-1px);
    }
    
    .btn-sm-modern {
        padding: 8px 12px;
        font-size: 13px;
    }
    
    .table-container {
        background: #ffffff;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        border: 1px solid #e5e7eb;
        overflow: hidden;
    }

    .table-container.search-hidden {
        display: none;
    }
    
    .table-modern {
        margin-bottom: 0;
    }
    
    .table-modern thead th {
        background-color: #f9fafb;
        border-bottom: 1px solid #e5e7eb;
        color: #374151;
        font-weight: 600;
        font-size: 14px;
        padding: 16px 12px;
        text-align: left;
    }
    
    .table-modern tbody tr {
        border-bottom: 1px solid #f3f4f6;
        transition: background-color 0.2s ease;
    }
    
    .table-modern tbody tr:hover {
        background-color: #f9fafb;
    }
    
    .table-modern tbody td {
        padding: 16px 12px;
        color: #1f2937;
        font-size: 14px;
        vertical-align: middle;
    }
    
    .table-modern tbody tr:last-child {
        border-bottom: none;
    }
    
    .pagination-info {
        padding: 16px 24px;
        background-color: #f9fafb;
        border-top: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 14px;
        color: #6b7280;
    }
    
    .pagination-controls {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .pagination-btn {
        padding: 6px 12px;
        border: 1px solid #d1d5db;
        background-color: #ffffff;
        color: #374151;
        border-radius: 6px;
        font-size: 13px;
        transition: all 0.2s ease;
    }
    
    .pagination-btn:hover {
        background-color: #f3f4f6;
        border-color: #9ca3af;
    }
    
    .pagination-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .finder-status {
        display: none;
        margin-bottom: 16px;
    }

    /* Hide individual search boxes completely */
    #advanced_search {
        display: none !important;
    }

    /* Color Overrides for jQuery-DT */
    table.dataTable thead th,
    table.dataTable thead td {
        border-bottom: 1px solid var(--gray900) !important;
    }

    table.dataTable tfoot th,
    table.dataTable tfoot td {
        border-top: 1px solid var(--gray900) !important;
    }

    table.dataTable tbody tr {
        background-color: var(--white) !important;
        cursor: pointer;
    }

    table.dataTable.row-border tbody th,
    table.dataTable.row-border tbody td,
    table.dataTable.display tbody th,
    table.dataTable.display tbody td {
        border-top: 1px solid var(--gray300) !important;
    }

    table.dataTable.cell-border tbody th,
    table.dataTable.cell-border tbody td {
        border-top: 1px solid var(--gray300) !important;
        border-right: 1px solid var(--gray300) !important;
    }

    table.dataTable.cell-border tbody tr th:first-child,
    table.dataTable.cell-border tbody tr td:first-child {
        border-left: 1px solid var(--gray300) !important;
    }

    table.dataTable.stripe tbody tr.odd,
    table.dataTable.display tbody tr.odd {
        background-color: var(--light) !important;
    }

    table.dataTable.hover tbody tr:hover,
    table.dataTable.display tbody tr:hover {
        background-color: var(--light) !important;
    }

    table.dataTable.order-column tbody tr>.sorting_1,
    table.dataTable.order-column tbody tr>.sorting_2,
    table.dataTable.order-column tbody tr>.sorting_3,
    table.dataTable.display tbody tr>.sorting_1,
    table.dataTable.display tbody tr>.sorting_2,
    table.dataTable.display tbody tr>.sorting_3 {
        background-color: var(--light) !important;
    }

    table.dataTable.display tbody tr.odd>.sorting_1,
    table.dataTable.order-column.stripe tbody tr.odd>.sorting_1 {
        background-color: var(--light) !important;
    }

    table.dataTable.display tbody tr.odd>.sorting_2,
    table.dataTable.order-column.stripe tbody tr.odd>.sorting_2 {
        background-color: var(--light) !important;
    }

    table.dataTable.display tbody tr.odd>.sorting_3,
    table.dataTable.order-column.stripe tbody tr.odd>.sorting_3 {
        background-color: var(--light) !important;
    }

    table.dataTable.display tbody tr.even>.sorting_1,
    table.dataTable.order-column.stripe tbody tr.even>.sorting_1 {
        background-color: var(--light) !important;
    }

    table.dataTable.display tbody tr.even>.sorting_2,
    table.dataTable.order-column.stripe tbody tr.even>.sorting_2 {
        background-color: var(--light) !important;
    }

    table.dataTable.display tbody tr.even>.sorting_3,
    table.dataTable.order-column.stripe tbody tr.even>.sorting_3 {
        background-color: var(--light) !important;
    }

    table.dataTable.display tbody tr:hover>.sorting_1,
    table.dataTable.order-column.hover tbody tr:hover>.sorting_1 {
        background-color: var(--gray200) !important;
    }

    table.dataTable.display tbody tr:hover>.sorting_2,
    table.dataTable.order-column.hover tbody tr:hover>.sorting_2 {
        background-color: var(--gray200) !important;
    }

    table.dataTable.display tbody tr:hover>.sorting_3,
    table.dataTable.order-column.hover tbody tr:hover>.sorting_3 {
        background-color: var(--gray200) !important;
    }

    table.dataTable.display tbody .odd:hover,
    table.dataTable.display tbody .even:hover {
        background-color: var(--gray200) !important;
    }

    table.dataTable.no-footer {
        border-bottom: 1px solid var(--gray900) !important;
    }

    .dataTables_wrapper .dataTables_processing {
        background-color: var(--white) !important;
        background: -webkit-gradient(linear, left top, right top, color-stop(0%, transparent), color-stop(25%, rgba(var(--black), 0.9)), color-stop(75%, rgba(var(--black), 0.9)), color-stop(100%, transparent)) !important;
        background: -webkit-linear-gradient(left, transparent 0%, rgba(var(--black), 0.9) 25%, rgba(var(--black), 0.9) 75%, transparent 100%) !important;
        background: -moz-linear-gradient(left, transparent 0%, rgba(var(--black), 0.9) 25%, rgba(var(--black), 0.9) 75%, transparent 100%) !important;
        background: -ms-linear-gradient(left, transparent 0%, rgba(var(--black), 0.9) 25%, rgba(var(--black), 0.9) 75%, transparent 100%) !important;
        background: -o-linear-gradient(left, transparent 0%, rgba(var(--black), 0.9) 25%, rgba(var(--black), 0.9) 75%, transparent 100%) !important;
        background: linear-gradient(to right, transparent 0%, rgba(var(--black), 0.9) 25%, rgba(var(--black), 0.9) 75%, transparent 100%) !important;
    }

    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter,
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_processing,
    .dataTables_wrapper .dataTables_paginate {
        color: var(--dark) !important;
    }

    .dataTables_wrapper .mytopdiv {
        margin-top: 14px;
        padding: 14px 16px;
        border-top: 1px solid #e9eef5;
        background: #fbfdff;
    }

    .finder-footer-controls {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 18px;
        margin: 0;
    }

    .finder-footer-check {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        margin: 0;
        font-size: 15px;
        font-weight: 500;
        color: #314255;
        cursor: pointer;
        user-select: none;
    }

    .finder-footer-check input[type="checkbox"] {
        width: 18px;
        height: 18px;
        margin: 0;
        accent-color: #2f80ed;
    }

    .finder-footer-check span {
        line-height: 1.35;
    }

    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate {
        margin-top: 14px;
        padding-top: 14px;
        border-top: 1px solid #eef2f7;
    }

    .dataTables_wrapper .dataTables_info {
        font-size: 15px;
        font-weight: 500;
        color: #5b6b7c !important;
        float: left;
        max-width: 60%;
    }

    .dataTables_wrapper .dataTables_paginate {
        display: flex;
        justify-content: flex-end;
        float: right;
    }

    .dataTables_wrapper .dataTables_paginate .paginate_button .page-link {
        border-radius: 10px !important;
        border-color: #d6dee8;
        color: #35506b;
        min-width: 42px;
        text-align: center;
        box-shadow: none !important;
    }

    .dataTables_wrapper .dataTables_paginate .paginate_button.active .page-link {
        background: #2f80ed;
        border-color: #2f80ed;
        color: #ffffff !important;
    }

    @media screen and (max-width: 768px) {
        .finder-footer-controls {
            flex-direction: column;
            align-items: flex-start;
            gap: 12px;
        }

        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            float: none;
            display: block;
            max-width: 100%;
            width: 100%;
            text-align: left;
        }

        .dataTables_wrapper .dataTables_paginate {
            justify-content: flex-start;
        }
    }

    .dataTables_wrapper .dataTables_length label {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        font-weight: 500;
        white-space: nowrap;
    }

    .dataTables_wrapper .dataTables_length select {
        width: 84px !important;
        min-width: 84px !important;
        height: 44px;
        padding: 8px 30px 8px 12px;
        border: 1px solid #d6dee8 !important;
        border-radius: 10px;
        background-color: #ffffff;
        font-size: 18px;
        line-height: 1.2;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .dataTables_wrapper.no-footer .dataTables_scrollBody {
        border-bottom: 1px solid var(--gray900) !important;
    }

    /* Pagination button Overrides for jQuery-DT */
    .dataTables_wrapper .dataTables_paginate .paginate_button {
        padding: 0 !important;
        margin: 0 !important;
        border: 0 !important;
    }

    /* Sort indicator Overrides for jQuery-DT */
    table thead .sorting::before,
    table thead .sorting_asc::before,
    table thead .sorting_asc::after,
    table thead .sorting_desc::before,
    table thead .sorting_desc::after,
    table thead .sorting::after {
        display: none !important;
    }

    /* Increase font size for Finder table */
    #pt_table {
        font-size: 15px; /* Increased from default */
    }
    
    #pt_table th,
    #pt_table td {
        font-size: 15px; /* Increased from default */
        padding: 12px 8px; /* Slightly more padding for better readability */
    }
    
    /* Increase font size for main menu */
    .navbar,
    .navbar-nav,
    .nav-link {
        font-size: 15px; /* Increased from default */
    }
    
    /* Increase font size for inventory table */
    .inventory-table,
    .inventory-table th,
    .inventory-table td {
        font-size: 15px; /* Increased from default */
    }

    /* Modern unified search styling */
</style>

<?php
    $arrOeUiSettings = array(
    'heading_title' => xl('Patient Finder'),
    'include_patient_name' => false,
    'expandable' => true,
    'expandable_files' => array('dynamic_finder_xpd'),//all file names need suffix _xpd
    'action' => "search",//conceal, reveal, search, reset, link or back
    'action_title' => "",//only for action link, leave empty for conceal, reveal, search
    'action_href' => "",//only for actions - reset, link or back
    'show_help_icon' => false,
    'help_file_name' => ""
    );
    $oemr_ui = new OemrUI($arrOeUiSettings);
    ?>
</head>
<body>
    <div id="container_div" class="<?php echo attr($oemr_ui->oeContainer()); ?> mt-3">
         <div class="w-100">
            <!-- Modern Page Header -->
            <div class="mb-4">
                <h1 class="page-title"><?php echo xlt('Patients'); ?></h1>
                <p class="page-subtitle"><?php echo xlt('List of all patients'); ?></p>
            </div>
            
            <!-- Modern Search and Actions Container -->
            <div class="unified-search-container">
                <div class="search-controls">
                    <div class="search-input-wrapper">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="unified_search" class="form-control" placeholder="<?php echo xla('Search by Name, DOB, or Mobile Phone...'); ?>" value="<?php echo attr($_GET['global_search'] ?? ''); ?>" autocomplete="off" />
                    </div>
                    <div class="action-buttons">
                        <button type="button" class="btn btn-secondary-modern btn-sm-modern" onclick="clearSearch()">
                            <i class="fas fa-times"></i> <?php echo xlt('Clear'); ?>
                        </button>
                        <span class="finder-icon-divider" aria-hidden="true"></span>
                        <a class="finder-icon-btn" title="<?php echo attr(xla('Import Patients')); ?>" aria-label="<?php echo attr(xla('Import Patients')); ?>" href="<?php echo attr($GLOBALS['webroot'] . '/interface/patient_file/import_patients.php'); ?>">
                            <i class="fas fa-file-import"></i>
                        </a>
                        <a class="finder-icon-btn" title="<?php echo attr(xla('Export Patients')); ?>" aria-label="<?php echo attr(xla('Export Patients')); ?>" href="<?php echo attr($GLOBALS['webroot'] . '/interface/main/finder/export_patients.php'); ?>">
                            <i class="fas fa-file-export"></i>
                        </a>
            <?php if (AclMain::aclCheckCore('patients', 'demo', '', array('write','addonly'))) { ?>
                            <button id="create_patient_btn1" class="btn btn-primary-modern btn-sm-modern" onclick="openAddPatientModal()">
                                <i class="fas fa-plus"></i> <?php echo xlt('Add Patient'); ?>
                            </button>
            <?php } ?>
                    </div>
                </div>
                <div id="finder_search_results" class="finder-search-results"></div>
            </div>
            

            
            <!-- Modern Table Container -->
            <div class="table-container">
                <div id="patient_finder_status" class="alert finder-status" role="alert"></div>
                <div id="dynamic">
                    <div class="table-responsive">
                        <table class="table table-modern display" id="pt_table">
                            <thead>
                                <tr id="advanced_search" class="hideaway" style="display: none;">
                                    <?php echo $header0; ?>
                                </tr>
                                <tr>
                                    <?php echo $header; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="dataTables_empty" colspan="<?php echo attr($colcount); ?>">...</td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- form used to open a new top level window when a patient row is clicked -->
        <form name='fnew' method='post' target='_blank' action='../main_screen.php?auth=login&site=<?php echo attr_url($_SESSION['site_id']); ?>'>
            <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
            <input type='hidden' name='patientID' value='0'/>
        </form>
    </div> <!--End of Container div-->
    <?php $oemr_ui->oeBelowContainerDiv();?>

    <script>
        $(function () {
            $("#exp_cont_icon").click(function () {
                $("#pt_table").removeAttr("style");
            });
        });

        $(window).on("resize", function() { //portrait vs landscape
           $("#pt_table").removeAttr("style");
        });
    </script>
    <script>
      $(function() {
        // Hide default DataTables elements and apply modern styling
        $("#pt_table_filter").hide();
        $("#pt_table_length").hide();
        $("#show_hide").hide();
        $("#search_hide").hide();
        
        // Apply modern styling to DataTables elements
        $('.dataTables_info').addClass('pagination-info');
        $('.dataTables_paginate').addClass('pagination-controls');
        $('.paginate_button').addClass('pagination-btn');
        
      });
    </script>

    <script>
        document.addEventListener('touchstart', {});
    </script>

    <script>
        var currentUnifiedSearch = $.trim($('#unified_search').val() || '');

        function getFinderWebroot() {
            if (typeof top !== 'undefined' && top && typeof top.webroot_url === 'string' && top.webroot_url !== '') {
                return top.webroot_url;
            }

            if (typeof window !== 'undefined' && typeof window.webroot_url === 'string' && window.webroot_url !== '') {
                return window.webroot_url;
            }

            return <?php echo js_url($web_root); ?>;
        }

        function safeFinderRestoreSession() {
            if (typeof top !== 'undefined' && top && typeof top.restoreSession === 'function') {
                top.restoreSession();
            }
        }

        function setFinderSearchMode(isSearching) {
            $('.table-container').toggleClass('search-hidden', false);
            $('#finder_search_results').hide().empty();
        }

        window.openPOSForPatient = function(pid, balance) {
            try {
                var posUrl = getFinderWebroot() + '/interface/pos/pos_modal.php?pid=' + encodeURIComponent(pid);
                if (balance && balance > 0) {
                    posUrl += '&balance=' + encodeURIComponent(balance);
                }

                safeFinderRestoreSession();
                window.location.assign(posUrl);
            } catch (error) {
                console.error('Error navigating to POS:', error);
                alert('Error navigating to POS system. Please try again.');
            }
        };

        window.openFinderSearchResult = function(element) {
            var $element = $(element);
            var rowId = $element.data('row-id');
            var pid = $element.data('pid');
            var balance = parseFloat($element.data('balance')) || 0;

            if (rowId && $('#' + rowId).length) {
                $('#' + rowId).trigger('click');
                return false;
            }

            if (pid) {
                safeFinderRestoreSession();
                showPatientInfo(pid, balance);
            }

            return false;
        };

        window.handleFinderSearchResultKeydown = function(event, element) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                return window.openFinderSearchResult(element);
            }

            return true;
        };

        function applyUnifiedSearch() {
            currentUnifiedSearch = $.trim($('#unified_search').val() || '');
            setFinderSearchMode(false);

            if (window.patientFinderTable) {
                window.patientFinderTable.ajax.reload(null, true);
                return true;
            }

            return false;
        }

        $(function() {
            var unifiedSearchTimer = null;
            $('#unified_search').on('input keyup change', function() {
                window.clearTimeout(unifiedSearchTimer);
                unifiedSearchTimer = window.setTimeout(function() {
                    applyUnifiedSearch();
                }, 250);
            });

            // Retry once the table is available in slower environments.
            var searchRetryCount = 0;
            var searchRetryTimer = window.setInterval(function() {
                searchRetryCount++;
                if (applyUnifiedSearch() || searchRetryCount >= 20) {
                    window.clearInterval(searchRetryTimer);
                }
            }, 250);
            
            // Focus on the unified search box
            $('#unified_search').focus();
            
            // Add modern hover effects
            $('#pt_table tbody').on('mouseenter', 'tr', function() {
                $(this).addClass('table-hover');
            }).on('mouseleave', 'tr', function() {
                $(this).removeClass('table-hover');
            });

            if ($.trim($('#unified_search').val() || '').length >= 2) {
                applyUnifiedSearch();
            }
        });
        
        function clearSearch() {
            $('#unified_search').val('');
            setFinderSearchMode(false);
            applyUnifiedSearch();
            $('#unified_search').focus();
        }
    </script>

    <!-- Add Patient Modal -->
    <div class="modal fade" id="addPatientModal" tabindex="-1" role="dialog" aria-labelledby="addPatientModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl" role="document" style="max-width: 75%; margin-left: auto; margin-right: 0; height: 100%; margin-top: 0; margin-bottom: 0;">
            <div class="modal-content" style="height: 100vh; border-radius: 0; border-left: 1px solid #dee2e6;">
                <div class="modal-header" style="border-bottom: 1px solid #dee2e6;">
                    <h5 class="modal-title" id="addPatientModalLabel"><?php echo xlt("Add New Patient"); ?></h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" id="addPatientModalBody" style="overflow-y: auto; height: calc(100vh - 120px);">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="sr-only">Loading...</span>
                        </div>
                        <p class="mt-2">Loading patient form...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Custom modal styling to slide from right */
        #addPatientModal .modal-dialog {
            transform: translateX(100%);
            transition: transform 0.3s ease-out;
        }
        
        #addPatientModal.show .modal-dialog {
            transform: translateX(0);
        }
        
        #addPatientModal .modal-content {
            box-shadow: -5px 0 15px rgba(0,0,0,0.3);
        }
        
        /* Ensure modal backdrop covers full screen */
        #addPatientModal .modal-backdrop {
            background-color: rgba(0,0,0,0.5);
        }
    </style>

    <script>
        function capitalizeMe(fld) {
            if (!fld) {
                return;
            }
            fld.value = String(fld.value || '').toUpperCase();
        }

        function showAddPatientModalMessage(message, type) {
            var feedback = $('#addPatientModalBody').find('#patient-form-feedback');
            if (!feedback.length || !message) {
                return;
            }

            feedback
                .removeClass('is-error is-success')
                .addClass(type === 'success' ? 'is-success' : 'is-error')
                .text(message)
                .show();
        }

        function getAddPatientFormUrl() {
            return '<?php echo $web_root ?>/interface/new/new.php?_=' + Date.now();
        }

        function initializeAddPatientForm() {
            // Fix form action URL to use absolute path
            $('#addPatientModalBody form[name="new_patient"]').attr('action', '<?php echo $web_root ?>/interface/new/new_patient_save.php');

            // Add form submission handler
            $('#addPatientModalBody form[name="new_patient"]').off('submit').on('submit', function(e) {
                e.preventDefault();
                if (typeof validate === 'function' && validate() === false) {
                    return false;
                }
                var formData = new FormData(this);

                // Ensure CSRF token is included
                if (!formData.get('csrf_token_form')) {
                    formData.append('csrf_token_form', '<?php echo attr(CsrfUtils::collectCsrfToken()); ?>');
                }

                // Ensure form_create field is included (required for patient creation)
                if (!formData.get('form_create')) {
                    formData.append('form_create', '1');
                }

                formData.set('from_finder_modal', '1');

                var saveUrl = '<?php echo $web_root ?>/interface/new/new_patient_save.php';

                $.ajax({
                    url: saveUrl,
                    method: 'POST',
                    data: formData,
                    dataType: 'json',
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response && typeof response === 'object') {
                            if (response.success) {
                                $('#addPatientModal').modal('hide');
                                $.get('<?php echo $web_root; ?>/interface/patient_file/summary/demographics.php?set_pid=0&csrf_token_form=<?php echo attr_url(CsrfUtils::collectCsrfToken()); ?>');
                                try {
                                    if (typeof $('#pt_table').DataTable === 'function') {
                                        $('#pt_table').DataTable().ajax.reload();
                                    } else {
                                        setTimeout(function() {
                                            window.location.reload();
                                        }, 300);
                                    }
                                } catch (e) {
                                    setTimeout(function() {
                                        window.location.reload();
                                    }, 300);
                                }
                                return;
                            }

                            if (response.reload_form) {
                                $('#addPatientModalBody').load(getAddPatientFormUrl(), function() {
                                    initializeAddPatientForm();
                                    showAddPatientModalMessage(response.message || 'Please review the highlighted fields and try again.', 'error');
                                });
                                return;
                            }

                            showAddPatientModalMessage(response.message || 'Please review the patient details and try again.', 'error');
                            return;
                        }

                        showAddPatientModalMessage('Please review the patient details and try again.', 'error');
                    },
                    error: function(xhr, status, error) {
                        console.error('Error submitting form:', error);
                        console.error('Response:', xhr.responseText);
                        try {
                            var json = xhr.responseJSON || JSON.parse(xhr.responseText);
                            if (json.reload_form) {
                                $('#addPatientModalBody').load(getAddPatientFormUrl(), function() {
                                    initializeAddPatientForm();
                                    showAddPatientModalMessage(json.message || 'Please review the patient details and try again.', 'error');
                                });
                                return;
                            }
                            if (json.success) {
                                $('#addPatientModal').modal('hide');
                                $.get('<?php echo $web_root; ?>/interface/patient_file/summary/demographics.php?set_pid=0&csrf_token_form=<?php echo attr_url(CsrfUtils::collectCsrfToken()); ?>');
                                setTimeout(function() {
                                    window.location.reload();
                                }, 300);
                                return;
                            }
                            showAddPatientModalMessage(json.message || 'Please review the patient details and try again.', 'error');
                            return;
                        } catch (parseError) {
                        }
                        showAddPatientModalMessage('Unable to save the patient right now. Please review the form and try again.', 'error');
                    }
                });
            });

            // Initialize datepicker for DOB field - use setTimeout to ensure DOM is ready
            setTimeout(function() {
                // Destroy any existing datepickers to prevent conflicts
                $('#addPatientModalBody .datepicker-us').datetimepicker('destroy');

                // Reinitialize datepicker
                $('#addPatientModalBody .datepicker-us').datetimepicker({
                    format: 'm/d/Y',
                    timepicker: false,
                    closeOnDateSelect: true,
                    scrollInput: false,
                    scrollMonth: false
                });
            }, 200);

            // Add cancel button handler with timeout to ensure DOM is ready
            setTimeout(function() {
                // Handle all possible cancel button types
                $('#addPatientModalBody .btn-cancel, #addPatientModalBody button[type="reset"], #addPatientModalBody a[href*="demographics.php"]').off('click').on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    // Try multiple methods to close the modal
                    try {
                        $('#addPatientModal').modal('hide');

                        // Fallback: Force close after a short delay
                        setTimeout(function() {
                            if ($('#addPatientModal').hasClass('show') || $('#addPatientModal').is(':visible')) {
                                $('#addPatientModal').removeClass('show').addClass('fade');
                                $('body').removeClass('modal-open');
                                $('.modal-backdrop').remove();
                                $('#addPatientModal').hide();

                                // Additional cleanup
                                setTimeout(function() {
                                    if ($('#addPatientModal').is(':visible')) {
                                        $('#addPatientModal').css('display', 'none');
                                        $('body').css('overflow', 'auto');
                                    }
                                }, 100);
                            }
                        }, 200);
                    } catch (error) {
                        // Force close
                        $('#addPatientModal').removeClass('show').addClass('fade');
                        $('body').removeClass('modal-open');
                        $('.modal-backdrop').remove();
                        $('#addPatientModal').hide();
                    }

                    return false;
                });

                // Also add a data attribute to make them easier to target
                $('#addPatientModalBody .btn-cancel, #addPatientModalBody button[type="reset"]').attr('data-modal-cancel', 'true');
            }, 500);
        }

        function openAddPatientModal() {
            top.restoreSession();
            $.get('<?php echo $web_root; ?>/interface/patient_file/summary/demographics.php?set_pid=0&csrf_token_form=<?php echo attr_url(CsrfUtils::collectCsrfToken()); ?>');
            
            // Show the modal
            $('#addPatientModal').modal('show');
            
            // Load the add patient form via AJAX
            var url = getAddPatientFormUrl();
            
            $.ajax({
                url: url,
                method: 'GET',
                cache: false,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                success: function(data) {
                    $('#addPatientModalBody').html(data);
                    initializeAddPatientForm();
                },
                error: function(xhr, status, error) {
                    console.error('Error loading add patient form:', error);
                    $('#addPatientModalBody').html('<div class="alert alert-danger">Error loading patient form. Please try again.</div>');
                }
            });
        }

        // Handle cancel buttons using event delegation (works for dynamically added elements)
        $(document).on('click', '#addPatientModal .btn-cancel, #addPatientModal button[type="reset"], #addPatientModal a[href*="demographics.php"], #addPatientModal [data-modal-cancel="true"]', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Try multiple methods to close the modal
            try {
                $('#addPatientModal').modal('hide');
                
                // Also try clicking the close button
                $('#addPatientModal .close, #addPatientModal [data-dismiss="modal"]').click();
                
                // Fallback methods
                setTimeout(function() {
                    if ($('#addPatientModal').hasClass('show') || $('#addPatientModal').is(':visible')) {
                        $('#addPatientModal').removeClass('show').addClass('fade');
                        $('body').removeClass('modal-open');
                        $('.modal-backdrop').remove();
                        $('#addPatientModal').hide();
                    }
                }, 200);
            } catch (error) {
                // Force close
                $('#addPatientModal').removeClass('show').addClass('fade');
                $('body').removeClass('modal-open');
                $('.modal-backdrop').remove();
                $('#addPatientModal').hide();
            }
            
            return false;
        });
        
        // Handle modal close to refresh the patient list
        $('#addPatientModal').on('hidden.bs.modal', function () {
            // Refresh the DataTable to show any newly added patients
            try {
                if (typeof $('#pt_table').DataTable === 'function') {
                    $('#pt_table').DataTable().ajax.reload();
                } else {
                    // Fallback: refresh the page to show updated data
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                }
            } catch (e) {
                // Fallback: refresh the page to show updated data
                setTimeout(function() {
                    window.location.reload();
                }, 1000);
            }
        });
    </script>

<script>
    var uspfx = '<?php echo attr($uspfx); ?>';

    function showFinderStatus(message, level) {
        var $status = $('#patient_finder_status');
        $status.removeClass('alert-danger alert-warning alert-info alert-success')
            .addClass('alert-' + (level || 'warning'))
            .text(message)
            .show();
    }

    function clearFinderStatus() {
        $('#patient_finder_status').hide().text('').removeClass('alert-danger alert-warning alert-info alert-success');
    }

    function buildEmptyFinderResponse(requestData) {
        var echoValue = 1;
        $.each(requestData || [], function (index, item) {
            if (item && item.name === 'sEcho') {
                echoValue = parseInt(item.value, 10) || 1;
                return false;
            }
        });

        return {
            sEcho: echoValue,
            iTotalRecords: 0,
            iTotalDisplayRecords: 0,
            aaData: []
        };
    }

    $(function () {
        // Initializing the DataTable.
        //
        $.fn.dataTable.ext.errMode = 'none';
        var finderCsrfToken = <?php echo js_url(CsrfUtils::collectCsrfToken()); ?>;
        var oTable = $('#pt_table').DataTable({
            "processing": true,
            "serverSide": true,
            "deferRender": true,
            "searching": false,
            // dom invokes ColReorderWithResize and allows inclusion of a custom div
            "dom": 'lrt<"mytopdiv">ip',
            "order": [ <?php echo $orderjson; ?> ],
            "lengthMenu": [10, 25, 50, 100],
            "pageLength": <?php echo empty($GLOBALS['gbl_pt_list_page_size']) ? '10' : $GLOBALS['gbl_pt_list_page_size']; ?>,
            "ajax": function(data, callback) {
                $.ajax({
                    url: 'dynamic_finder_ajax_live.php',
                    method: 'GET',
                    dataType: 'json',
                    data: {
                        csrf_token_form: finderCsrfToken,
                        sSearch: currentUnifiedSearch,
                        iDisplayStart: data.start || 0,
                        iDisplayLength: data.length || <?php echo empty($GLOBALS['gbl_pt_list_page_size']) ? '10' : (int) $GLOBALS['gbl_pt_list_page_size']; ?>,
                        sEcho: data.draw || 1
                    },
                    success: function(response) {
                        callback({
                            draw: parseInt(response.sEcho || data.draw || 1, 10),
                            recordsTotal: parseInt(response.iTotalRecords || 0, 10),
                            recordsFiltered: parseInt(response.iTotalDisplayRecords || 0, 10),
                            data: Array.isArray(response.aaData) ? response.aaData : []
                        });
                    },
                    error: function() {
                        callback({
                            draw: data.draw || 1,
                            recordsTotal: 0,
                            recordsFiltered: 0,
                            data: []
                        });
                    }
                });
            },
            "createdRow": function(row, data) {
                if (data && data.DT_RowId) {
                    $(row).attr('id', data.DT_RowId);
                }
            },
            "columns": [<?php echo $coljson; ?>],
            <?php // Bring in the translations ?>
            <?php $translationsDatatablesOverride = array('search' => (xla('Search all columns') . ':')); ?>
            <?php $translationsDatatablesOverride = array('processing' => $loading); ?>
            <?php require($GLOBALS['srcdir'] . '/js/xl/datatables-net.js.php'); ?>
        });
        window.patientFinderTable = oTable;

        <?php
        $checked = (!empty($GLOBALS['gbl_pt_list_new_window'])) ? 'checked' : '';
        ?>
        $("div.mytopdiv").html("<form name='myform' class='finder-footer-controls'><label for='form_new_window' class='finder-footer-check' id='form_new_window_label'><input type='checkbox' id='form_new_window' name='form_new_window' value='1' <?php echo $checked; ?> /><span><?php echo xlt('Open in New Window'); ?></span></label><label for='setting_search_type' id='setting_search_type_label' class='finder-footer-check'><input type='checkbox' name='setting_search_type' id='setting_search_type' onchange='persistCriteria(this, event)' value='1'/><span><?php echo xlt('Exact Search'); ?></span></label></form>");

        // Force Exact Search checkbox to be unchecked by default
        $("#setting_search_type").prop('checked', false);
        
        // Small delay to ensure checkbox state is set before any search requests
        setTimeout(function() {
            // Checkbox state is set
        }, 100);

        // This is to support column-specific search fields.
        // Borrowed from the multi_filter.html example.
        $("thead input").keyup(function () {
            // Filter on the column (the index) of this element
            oTable.fnFilter(this.value, $("thead input").index(this));
        });

        $('#pt_table').on('mouseenter', 'tbody tr', function() {
            $(this).find('a').css('text-decoration', 'underline');
        });
        $('#pt_table').on('mouseleave', 'tbody tr', function() {
            $(this).find('a').css('text-decoration', '');
        });
        // OnClick handler for the rows
        $('#pt_table').on('click', 'tbody tr', function (event) {
            // Don't trigger row click if clicking on a button or edit link
            if ($(event.target).closest('button').length > 0 || 
                $(event.target).closest('.edit-patient-btn').length > 0 ||
                $(event.target).closest('a').length > 0) {
                return;
            }

            // Prevent default behavior and stop propagation only for row clicks
            event.preventDefault();
            event.stopPropagation();
            
            // ID of a row element is pid_{value}
            var newpid = this.id.substring(4);
            // If the pid is invalid, then don't attempt to set
            // The row display for "No matching records found" has no valid ID, but is
            // otherwise clickable. (Matches this CSS selector).  This prevents an invalid
            // state for the PID to be set.
            if (newpid.length === 0) {
                return false;
            }
            
            // Get the displayed total paid amount from the same row
            var balance = 0;
            try {
                // Find the Total Paid column index
                var balanceColumnIndex = -1;
                $('#pt_table thead th').each(function(index) {
                    if ($(this).text().trim() === 'Total Paid') {
                        balanceColumnIndex = index;
                        return false; // break the loop
                    }
                });
                
                if (balanceColumnIndex >= 0) {
                    var balanceCell = $(this).find('td').eq(balanceColumnIndex);
                    if (balanceCell.length > 0) {
                        var balanceText = balanceCell.text().replace(/[^\d.-]/g, '');
                        balance = parseFloat(balanceText) || 0;
                    }
                }
            } catch (error) {
                balance = 0;
            }
            
            // Show patient information instead of redirecting to POS
            top.restoreSession();
            showPatientInfo(newpid, balance);
            
            return false; // Prevent any other handlers
        });

        // Function to show patient information
        window.showPatientInfo = function(pid, balance) {
            console.log('Showing patient info for PID:', pid, 'Balance:', balance);
            
            try {
                // Create modal overlay for patient information
                var modalHtml = ''
                    + '<div id="patientInfoModal" style="display: flex; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center;">'
                    + '<div class="modal-content" style="background-color: white; margin: 0; padding: 0; border-radius: 12px; width: 90%; max-width: 1000px; max-height: 90vh; box-shadow: 0 4px 20px rgba(0,0,0,0.3); overflow: hidden; display: flex; flex-direction: column;">'
                    + '<div class="modal-header" style="background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: white; padding: 20px; border-radius: 12px 12px 0 0;">'
                    + '<h3 style="margin: 0; font-size: 18px;">'
                    + '<i class="fas fa-user"></i> Patient Information'
                    + '</h3>'
                    + '<span class="close" onclick="closePatientInfoModal()" style="color: white; float: right; font-size: 28px; font-weight: bold; cursor: pointer; line-height: 1;">&times;</span>'
                    + '</div>'
                    + '<div class="modal-body" style="padding: 0; flex: 1; overflow-y: auto;">'
                    + '<div id="patientInfoContent" style="text-align: center; padding: 40px;">'
                    + '<i class="fas fa-spinner fa-spin" style="font-size: 24px; color: #007bff;"></i>'
                    + '<p style="margin-top: 10px; color: #6c757d;">Loading patient information...</p>'
                    + '</div>'
                    + '</div>'
                    + '</div>'
                    + '</div>';
                
                // Add modal to page
                document.body.insertAdjacentHTML('beforeend', modalHtml);
                
                // Load patient information via AJAX
                loadPatientInfo(pid);
                
            } catch (error) {
                console.error('Error showing patient info:', error);
                alert('Error loading patient information. Please try again.');
            }
        };

        // Function to load patient information via AJAX
        window.loadPatientInfo = function(pid) {
            // Get CSRF token from the page
            var csrfToken = <?php echo js_url(CsrfUtils::collectCsrfToken()); ?>;

            $.ajax({
                url: 'patient_info_ajax.php',
                method: 'GET',
                dataType: 'json',
                data: {
                    pid: pid,
                    csrf_token: csrfToken
                },
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                success: function(data) {
                    if (data.success) {
                        document.getElementById('patientInfoContent').innerHTML = data.html;
                    } else {
                        document.getElementById('patientInfoContent').innerHTML =
                            '<div style="color: #dc3545; text-align: center;">' +
                            '<i class="fas fa-exclamation-triangle" style="font-size: 24px; margin-bottom: 10px;"></i>' +
                            '<p>Error loading patient information: ' + (data.error || 'Unknown error') + '</p>' +
                            '</div>';
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error loading patient info:', error);
                    document.getElementById('patientInfoContent').innerHTML =
                        '<div style="color: #dc3545; text-align: center;">' +
                        '<i class="fas fa-exclamation-triangle" style="font-size: 24px; margin-bottom: 10px;"></i>' +
                        '<p>Error loading patient information. Please try again.</p>' +
                        '<p style="font-size: 12px; color: #6c757d;">Error details: ' + error + '</p>' +
                        '</div>';
                }
            });
        };

        // Function to close patient info modal
        window.closePatientInfoModal = function() {
            var modal = document.getElementById('patientInfoModal');
            if (modal) {
                modal.remove();
            }
        };

        // Function to navigate to POS page with dispense tracking focus
        function openDispenseTrackingModal(pid) {
            // Validate PID
            if (!pid || pid === 'undefined' || pid === 'null') {
                return;
            }
            
            try {
                // Use absolute webroot so staging/subdirectory paths do not break
                var posUrl = getFinderWebroot() + '/interface/pos/pos_modal.php?pid=' + encodeURIComponent(pid) + '&dispense_tracking=1';
                
                // Navigate to the POS page
                window.location.href = posUrl;
                
            } catch (error) {
                console.error('Error navigating to POS with dispense tracking:', error);
                alert('Error navigating to POS system. Please try again.');
            }
        }
        // Specific handler for Dispense Tracking buttons to prevent event bubbling
        $('#pt_table').on('click', '.dispense-tracking-btn', function (event) {
            event.preventDefault();
            event.stopPropagation();
            
            var pid = $(this).data('pid');
            
            // Navigate to POS page with dispense tracking focus
            openDispenseTrackingModal(pid);
            
            return false;
        });

        $('#pt_table').on('click', '.pay-now-btn', function (event) {
            event.preventDefault();
            event.stopPropagation();

            var href = $(this).attr('href');
            if (href) {
                if (typeof top !== 'undefined' && top && typeof top.restoreSession === 'function') {
                    top.restoreSession();
                }
                window.location.assign(href);
            }

            return false;
        });

        // Specific handler for Edit Patient buttons to prevent event bubbling
        $('#pt_table').on('click', '.edit-patient-btn', function (event) {
            event.preventDefault();
            event.stopPropagation();
            
            // Extract PID from the row ID (format: 'pid_123')
            var row = $(this).closest('tr');
            var rowId = row.attr('id');
            var pid = rowId ? rowId.replace('pid_', '') : null;
            var patientName = $(this).data('patient-name');
            
            if (!pid || pid === 'undefined' || pid === 'null' || isNaN(pid)) {
                console.error('Invalid PID for edit patient button. Row ID:', rowId);
                alert('Error: Invalid patient ID. Please try again.');
                return false;
            }
            
            openEditPatientModal(pid);
            
            return false;
        });
    });

    function wrapInLink(data, type, full, meta) {
        if (type == 'display') {
            // Get the PID from the row ID (format: 'pid_123')
            // We'll extract it when the button is clicked instead
            var patientName = data;
            
            return '<span class="d-inline-flex align-items-center">' +
                   data +
                   '<a href="#" ' +
                   'class="btn btn-link btn-sm p-0 ml-1 edit-patient-btn" ' +
                   'data-patient-name="' + patientName + '" ' +
                   'title="Edit Patient Demographics for ' + data + '">' +
                   '<i class="fa fa-edit text-primary"></i></a>' +
                   '</span>';
        } else {
            return data;
        }
    }

    function formatTotalPaid(data, type, full) {
        if (type == 'display') {
            var totalPaid = parseFloat(data);
            if (totalPaid > 0) {
                return '<span class="text-success font-weight-bold">$' + totalPaid.toFixed(2) + '</span>';
            } else {
                return '<span class="text-success">$0.00</span>';
            }
        } else {
            return data;
        }
    }

    function formatPayNow(data, type, full, meta) {
        if (type == 'display') {
            // Parse the combined PID and balance data (format: "pid|balance")
            var parts = data.toString().split('|');
            var actualPid = parts[0];
            var balance = parseFloat(parts[1] || 0);
            var url = getFinderWebroot() + '/interface/pos/pos_modal.php?pid=' + encodeURIComponent(actualPid);
            if (balance > 0) {
                url += '&balance=' + encodeURIComponent(balance);
            }
            
            return '<a class="btn btn-info btn-sm pay-now-btn" data-pid="' + actualPid + '" data-balance="' + balance + '" href="' + url + '" onclick="event.stopPropagation();">' +
                   '<i class="fa fa-credit-card"></i> POS</a>';
        } else {
            return data;
        }
    }



    function openPayment(pid, balance) {
        top.restoreSession();
        // Open Stripe payment form in modal
        var url = '../../patient_file/front_payment_stripe_modal.php?pid=' + pid;
        
        // Create modal if it doesn't exist
        if (!$('#paymentModal').length) {
            $('body').append(
                '<div class="modal fade" id="paymentModal" tabindex="-1" role="dialog" aria-labelledby="paymentModalLabel" aria-hidden="true">'
                + '<div class="modal-dialog modal-xl" role="document">'
                + '<div class="modal-content">'
                + '<div class="modal-header">'
                + '<h5 class="modal-title" id="paymentModalLabel"><?php echo xlj("Pay Now"); ?></h5>'
                + '<button type="button" class="close" data-dismiss="modal" aria-label="Close">'
                + '<span aria-hidden="true">&times;</span>'
                + '</button>'
                + '</div>'
                + '<div class="modal-body" id="paymentModalBody">'
                + '<div class="text-center">'
                + '<div class="spinner-border" role="status">'
                + '<span class="sr-only">Loading...</span>'
                + '</div>'
                + '<p class="mt-2">Loading payment form...</p>'
                + '</div>'
                + '</div>'
                + '</div>'
                + '</div>'
                + '</div>'
            );
        }
        
        // Load payment form content
        $('#paymentModalBody').html(
            '<div class="text-center">'
            + '<div class="spinner-border" role="status">'
            + '<span class="sr-only">Loading...</span>'
            + '</div>'
            + '<p class="mt-2">Loading payment form...</p>'
            + '</div>'
        );
        
        $('#paymentModal').modal('show');
        
        // Load the payment form via AJAX
        $.ajax({
            url: url,
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            success: function(data) {
                $('#paymentModalBody').html(data);
            },
            error: function(xhr, status, error) {
                console.error('Failed to load payment form:', status, error);
                console.error('Response:', xhr.responseText);
                $('#paymentModalBody').html(
                    '<div class="alert alert-danger">'
                    + '<h5>Error Loading Payment Form</h5>'
                    + '<p>Unable to load the payment form. Please try again or contact support.</p>'
                    + '<p><strong>Error Details:</strong> ' + status + ' - ' + error + '</p>'
                    + '<p><strong>Response:</strong> ' + xhr.responseText + '</p>'
                    + '<button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>'
                    + '</div>'
                );
            }
        });
    }

    function openEditPatientModal(pid) {
        top.restoreSession();
        
        // Validate PID
        if (!pid || pid === 'undefined' || pid === 'null') {
            console.error('Invalid PID passed to openEditPatientModal:', pid);
            alert('Error: Invalid patient ID. Please try again.');
            return;
        }
        
        // Show the modal
        $('#editPatientModal').modal('show');
        
        // Update modal title with patient info
        $('#editPatientModalLabel').text('Edit Patient (PID: ' + pid + ')');
        
        // Load the edit patient form via AJAX
        var url = '<?php echo $web_root ?>/interface/patient_file/summary/demographics_full.php?set_pid=' + pid;
        
                    $.ajax({
                url: url,
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                success: function(data) {
                    $('#editPatientModalBody').html(data);
                    
                    // Fix form action URL to use absolute path
                    $('#editPatientModalBody form[name="demographics_form"]').attr('action', '<?php echo $web_root ?>/interface/patient_file/summary/demographics_save.php');
                    
                    // Add form submission handler
                    $('#editPatientModalBody form[name="demographics_form"]').off('submit').on('submit', function(e) {
                        e.preventDefault();
                        var formData = new FormData(this);
                        
                        // Ensure CSRF token is included
                        if (!formData.get('csrf_token_form')) {
                            formData.append('csrf_token_form', '<?php echo attr(CsrfUtils::collectCsrfToken()); ?>');
                        }
                        
                        // Debug form data
                        for (var pair of formData.entries()) {
                        }
                        
                        var saveUrl = '<?php echo $web_root ?>/interface/patient_file/summary/demographics_save.php';
                        
                        $.ajax({
                            url: saveUrl,
                            method: 'POST',
                            data: formData,
                            processData: false,
                            contentType: false,
                            success: function(response) {
                                // Handle JSON response
                                if (typeof response === 'object' && response.success) {
                                    $('#editPatientModal').modal('hide');
                                    // Refresh the patient list if DataTable is available
                                    try {
                                        if (typeof $('#pt_table').DataTable === 'function') {
                                            $('#pt_table').DataTable().ajax.reload();
                                        } else {
                                            // Fallback: refresh the page to show updated data
                                            setTimeout(function() {
                                                window.location.reload();
                                            }, 1000);
                                        }
                                    } catch (e) {
                                        // Fallback: refresh the page to show updated data
                                        setTimeout(function() {
                                            window.location.reload();
                                        }, 1000);
                                    }
                                    alert('Patient updated successfully!');
                                } 
                                // Handle HTML response with window.location
                                else if (typeof response === 'string' && (response.includes('success') || response.includes('Patient updated') || response.includes('window.location'))) {
                                    $('#editPatientModal').modal('hide');
                                    // Refresh the patient list if DataTable is available
                                    try {
                                        if (typeof $('#pt_table').DataTable === 'function') {
                                            $('#pt_table').DataTable().ajax.reload();
                                        } else {
                                            // Fallback: refresh the page to show updated data
                                            setTimeout(function() {
                                                window.location.reload();
                                            }, 1000);
                                        }
                                    } catch (e) {
                                        // Fallback: refresh the page to show updated data
                                        setTimeout(function() {
                                            window.location.reload();
                                        }, 1000);
                                    }
                                    alert('Patient updated successfully!');
                                } else {
                                    alert('Error updating patient. Please check the form and try again.');
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('Error submitting form:', error);
                                console.error('Response:', xhr.responseText);
                                alert('Error updating patient. Please try again.');
                            }
                        });
                    });
                    
                    // Initialize datepicker for DOB field - use setTimeout to ensure DOM is ready
                    setTimeout(function() {
                        // Destroy any existing datepickers to prevent conflicts
                        $('#editPatientModalBody .datepicker-us').datetimepicker('destroy');
                        
                        // Reinitialize datepicker
                        $('#editPatientModalBody .datepicker-us').datetimepicker({
                            format: 'm/d/Y',
                            timepicker: false,
                            closeOnDateSelect: true,
                            scrollInput: false,
                            scrollMonth: false
                        });
                    }, 200);
                    
                    // Add cancel button handler with timeout to ensure DOM is ready
                    setTimeout(function() {
                        // Handle all possible cancel button types
                        $('#editPatientModalBody .btn-cancel, #editPatientModalBody button[type="reset"], #editPatientModalBody a[href*="demographics.php"]').off('click').on('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            
                            // Try multiple methods to close the modal
                            try {
                                $('#editPatientModal').modal('hide');
                                
                                // Fallback: Force close after a short delay
                                setTimeout(function() {
                                    if ($('#editPatientModal').hasClass('show') || $('#editPatientModal').is(':visible')) {
                                        $('#editPatientModal').removeClass('show').addClass('fade');
                                        $('body').removeClass('modal-open');
                                        $('.modal-backdrop').remove();
                                        $('#editPatientModal').hide();
                                        
                                        // Additional cleanup
                                        setTimeout(function() {
                                            if ($('#editPatientModal').is(':visible')) {
                                                $('#editPatientModal').css('display', 'none');
                                                $('body').css('overflow', 'auto');
                                            }
                                        }, 100);
                                    }
                                }, 200);
                            } catch (error) {
                                // Force close
                                $('#editPatientModal').removeClass('show').addClass('fade');
                                $('body').removeClass('modal-open');
                                $('.modal-backdrop').remove();
                                $('#editPatientModal').hide();
                            }
                            
                            return false;
                        });
                        
                        // Also add a data attribute to make them easier to target
                        $('#editPatientModalBody .btn-cancel, #editPatientModalBody button[type="reset"]').attr('data-modal-cancel', 'true');
                    }, 500);
                },
                error: function(xhr, status, error) {
                    console.error('Error loading add patient form:', error);
                    $('#addPatientModalBody').html('<div class="alert alert-danger">Error loading patient form. Please try again.</div>');
                }
            });
        }

        // Handle cancel buttons using event delegation (works for dynamically added elements)
        $(document).on('click', '#addPatientModal .btn-cancel, #addPatientModal button[type="reset"], #addPatientModal a[href*="demographics.php"], #addPatientModal [data-modal-cancel="true"]', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Try multiple methods to close the modal
            try {
                $('#addPatientModal').modal('hide');
                
                // Also try clicking the close button
                $('#addPatientModal .close, #addPatientModal [data-dismiss="modal"]').click();
                
                // Fallback methods
                setTimeout(function() {
                    if ($('#addPatientModal').hasClass('show') || $('#addPatientModal').is(':visible')) {
                        $('#addPatientModal').removeClass('show').addClass('fade');
                        $('body').removeClass('modal-open');
                        $('.modal-backdrop').remove();
                        $('#addPatientModal').hide();
                    }
                }, 200);
            } catch (error) {
                // Force close
                $('#addPatientModal').removeClass('show').addClass('fade');
                $('body').removeClass('modal-open');
                $('.modal-backdrop').remove();
                $('#addPatientModal').hide();
            }
            
            return false;
        });
        
        // Handle modal close to refresh the patient list
        $('#addPatientModal').on('hidden.bs.modal', function () {
            // Refresh the DataTable to show any newly added patients
            try {
                if (typeof $('#pt_table').DataTable === 'function') {
                    $('#pt_table').DataTable().ajax.reload();
                } else {
                    // Fallback: refresh the page to show updated data
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                }
            } catch (e) {
                // Fallback: refresh the page to show updated data
                setTimeout(function() {
                    window.location.reload();
                }, 1000);
            }
        });

        // Close patient info modal when clicking outside
        document.addEventListener('click', function(event) {
            var modal = document.getElementById('patientInfoModal');
            if (event.target === modal) {
                closePatientInfoModal();
            }
        });
    </script>

    <!-- Edit Patient Modal -->
    <div class="modal fade" id="editPatientModal" tabindex="-1" role="dialog" aria-labelledby="editPatientModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl" role="document" style="max-width: 75%; margin-left: auto; margin-right: 0; height: 100%; margin-top: 0; margin-bottom: 0;">
            <div class="modal-content" style="height: 100vh; border-radius: 0; border-left: 1px solid #dee2e6;">
                <div class="modal-header" style="border-bottom: 1px solid #dee2e6;">
                    <h5 class="modal-title" id="editPatientModalLabel"><?php echo xlt("Edit Patient"); ?></h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" id="editPatientModalBody" style="overflow-y: auto; height: calc(100vh - 120px);">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="sr-only">Loading...</span>
                        </div>
                        <p class="mt-2">Loading patient form...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Custom modal styling to slide from right */
        #editPatientModal .modal-dialog {
            transform: translateX(100%);
            transition: transform 0.3s ease-out;
            max-width: 75%;
            margin-left: auto;
            margin-right: 0;
            height: 100%;
            margin-top: 0;
            margin-bottom: 0;
        }
        
        #editPatientModal.show .modal-dialog {
            transform: translateX(0);
        }
        
        #editPatientModal .modal-content {
            box-shadow: -5px 0 15px rgba(0,0,0,0.3);
            height: 100vh;
            border-radius: 0;
            border-left: 1px solid #dee2e6;
        }
        
        #editPatientModal .modal-body {
            overflow-y: auto;
            height: calc(100vh - 120px);
            padding: 1rem;
        }
        
        /* Ensure modal backdrop covers full screen */
        #editPatientModal .modal-backdrop {
            background-color: rgba(0,0,0,0.5);
        }
        
        /* Ensure form content fits properly */
        #editPatientModal .add-patient-card {
            margin: 0;
            max-width: none;
            height: 100%;
        }
        
        #editPatientModal .add-patient-content {
            max-height: none;
            overflow: visible;
        }
    </style>

    <script>
        // Handle modal close to refresh the patient list
        $('#editPatientModal').on('hidden.bs.modal', function () {
            // Refresh the DataTable to show any updated patient information
            $('#pt_table').DataTable().ajax.reload();
        });
    </script>
</body>
</html>
