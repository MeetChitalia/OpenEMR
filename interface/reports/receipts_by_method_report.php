<?php

/**
 * Corporate Payment Receipt Summary report.
 *
 * Built from POS receipt transactions to provide a clinic/date payment summary
 * with patient, procedure, adjustment, notes, payment and payment type details.
 *
 * @package   OpenEMR
 */

require_once("../globals.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/options.inc.php");

enforceSelectedFacilityScopeForRequest('form_facility');

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;

if (!AclMain::aclCheckCore('acct', 'rep_a')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', [
        'pageTitle' => xl("Payment Receipt Summary")
    ]);
    exit;
}

if (!empty($_POST) && !CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"] ?? '')) {
    CsrfUtils::csrfNotVerified();
}

$isReportSubmitted = !empty($_POST);
$form_from_date = isset($_POST['form_from_date']) ? DateToYYYYMMDD($_POST['form_from_date']) : date('Y-m-d');
$form_to_date = isset($_POST['form_to_date']) ? DateToYYYYMMDD($_POST['form_to_date']) : date('Y-m-d');
$form_facility = $_POST['form_facility'] ?? null;

function paymentReportMoney($amount): string
{
    return '$' . number_format((float) $amount, 2);
}

function paymentReportHasPosColumn(string $column): bool
{
    static $cache = [];

    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $cache[$column] = !empty(sqlFetchArray(sqlStatement("SHOW COLUMNS FROM pos_transactions LIKE ?", [$column])));
    return $cache[$column];
}

function paymentReportHasCreditTable(): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    $exists = !empty(sqlFetchArray(sqlStatement("SHOW TABLES LIKE 'patient_credit_transactions'")));
    return $exists;
}

function paymentReportGetPatientName(int $pid): string
{
    static $cache = [];

    if (isset($cache[$pid])) {
        return $cache[$pid];
    }

    $row = sqlFetchArray(sqlStatement(
        "SELECT fname, mname, lname FROM patient_data WHERE pid = ? LIMIT 1",
        [$pid]
    ));

    if (empty($row)) {
        $cache[$pid] = '';
        return $cache[$pid];
    }

    $cache[$pid] = trim(($row['lname'] ?? '') . ', ' . ($row['fname'] ?? '') . ' ' . ($row['mname'] ?? ''));
    return $cache[$pid];
}

function paymentReportNormalizePaymentType(?string $paymentMethod): string
{
    $paymentMethod = strtolower(trim((string) $paymentMethod));
    if ($paymentMethod === '') {
        return xl('Unknown');
    }

    $map = [
        'cash' => xl('Cash'),
        'check' => xl('Check'),
        'credit_card' => xl('Card'),
        'credit card' => xl('Card'),
        'card' => xl('Card'),
        'debit_card' => xl('Card'),
        'debit card' => xl('Card'),
        'afterpay' => xl('Afterpay'),
        'credit' => xl('Credit'),
        'credit_balance' => xl('Credit Balance'),
        'credit balance' => xl('Credit Balance'),
    ];

    return $map[$paymentMethod] ?? ucwords(str_replace('_', ' ', $paymentMethod));
}

function paymentReportParseItems(string $itemsJson): array
{
    $items = json_decode($itemsJson, true);
    return is_array($items) ? $items : [];
}

function paymentReportResolveItemQuantity(array $item): float
{
    $candidates = [
        (float) ($item['quantity'] ?? 0),
        (float) ($item['dispense_quantity'] ?? 0),
        (float) ($item['administer_quantity'] ?? 0),
    ];

    $quantity = max($candidates);
    return $quantity > 0 ? $quantity : 1.0;
}

function paymentReportFormatItemProcedure(array $item): string
{
    $name = trim((string) ($item['name'] ?? $item['display_name'] ?? ''));
    if ($name === '') {
        $name = xl('Unnamed Item');
    }

    $dose = trim((string) ($item['dose_option_mg'] ?? ''));
    if ($dose !== '') {
        $name .= ' ' . $dose . ' mg';
    }

    $quantity = paymentReportResolveItemQuantity($item);
    if ($quantity > 1) {
        $formattedQuantity = floor($quantity) == $quantity ? (string) (int) $quantity : (string) $quantity;
        $name .= ' x' . $formattedQuantity;
    }

    return $name;
}

function paymentReportBuildProcedureSummary(array $items): string
{
    if (empty($items)) {
        return '';
    }

    $parts = [];
    foreach ($items as $item) {
        $parts[] = paymentReportFormatItemProcedure($item);
    }

    return implode('; ', array_values(array_unique(array_filter($parts))));
}

function paymentReportCalculateLineDiscount(array $item): float
{
    if (isset($item['line_discount']) && is_numeric($item['line_discount'])) {
        return max(0, round((float) $item['line_discount'], 2));
    }

    $quantity = paymentReportResolveItemQuantity($item);
    $lineTotal = isset($item['line_total']) && is_numeric($item['line_total'])
        ? (float) $item['line_total']
        : ((float) ($item['price'] ?? 0) * $quantity);
    $originalLineTotal = isset($item['original_line_total']) && is_numeric($item['original_line_total'])
        ? (float) $item['original_line_total']
        : ((float) ($item['original_price'] ?? ($item['price'] ?? 0)) * $quantity);

    return max(0, round($originalLineTotal - $lineTotal, 2));
}

function paymentReportGetCreditData(int $pid, string $receiptNumber): array
{
    static $cache = [];
    $cacheKey = $pid . '|' . $receiptNumber;

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $result = ['amount' => 0.0, 'notes' => []];
    if ($pid <= 0 || $receiptNumber === '' || !paymentReportHasCreditTable()) {
        $cache[$cacheKey] = $result;
        return $cache[$cacheKey];
    }

    $res = sqlStatement(
        "SELECT amount, description
         FROM patient_credit_transactions
         WHERE pid = ? AND receipt_number = ? AND transaction_type = 'payment'
         ORDER BY created_at ASC, id ASC",
        [$pid, $receiptNumber]
    );

    while ($row = sqlFetchArray($res)) {
        $result['amount'] += (float) ($row['amount'] ?? 0);
        $description = trim((string) ($row['description'] ?? ''));
        if ($description !== '') {
            $result['notes'][] = $description;
        }
    }

    $result['amount'] = round($result['amount'], 2);
    $result['notes'] = array_values(array_unique($result['notes']));
    $cache[$cacheKey] = $result;
    return $cache[$cacheKey];
}

function paymentReportBuildAdjustmentAndNotes(array $items, string $priceOverrideNotes, int $pid, string $receiptNumber): array
{
    $lineDiscounts = 0.0;
    $notes = [];

    foreach ($items as $item) {
        $lineDiscounts += paymentReportCalculateLineDiscount($item);

        $discountInfo = $item['discount_info'] ?? null;
        if (is_array($discountInfo)) {
            $description = trim((string) ($discountInfo['description'] ?? ''));
            if ($description !== '') {
                $notes[] = $description;
            }
        }

        $saleReference = trim((string) ($item['prepay_sale_reference'] ?? ''));
        if ($saleReference !== '') {
            $notes[] = xl('Sale') . ': ' . $saleReference;
        }

        if (!empty($item['prepay_selected'])) {
            $itemLabel = trim((string) ($item['name'] ?? $item['display_name'] ?? ''));
            $notes[] = $itemLabel !== '' ? (xl('Prepaid') . ': ' . $itemLabel) : xl('Prepaid');
        }
    }

    $priceOverrideNotes = trim($priceOverrideNotes);
    if ($priceOverrideNotes !== '') {
        $notes[] = $priceOverrideNotes;
    }

    $creditData = paymentReportGetCreditData($pid, $receiptNumber);
    if ($creditData['amount'] > 0) {
        $notes[] = xl('Credit Used') . ': ' . oeFormatMoney($creditData['amount']);
    }
    foreach ($creditData['notes'] as $creditNote) {
        $notes[] = $creditNote;
    }

    return [
        'adjustments' => round($lineDiscounts + $creditData['amount'], 2),
        'notes' => implode(' | ', array_values(array_unique(array_filter($notes)))),
    ];
}

function paymentReportGetRows(string $fromDate, string $toDate, $facilityId): array
{
    $binds = [$fromDate . ' 00:00:00', $toDate . ' 23:59:59'];
    $query = "SELECT pt.id, pt.pid, pt.receipt_number, pt.payment_method, pt.amount, pt.items, pt.created_date, pt.price_override_notes
              FROM pos_transactions AS pt
              WHERE pt.transaction_type = 'external_payment'
                AND pt.created_date >= ?
                AND pt.created_date <= ?";

    if (paymentReportHasPosColumn('voided')) {
        $query .= " AND COALESCE(pt.voided, 0) = 0";
    }

    if ($facilityId && paymentReportHasPosColumn('facility_id')) {
        $query .= " AND pt.facility_id = ?";
        $binds[] = (int) $facilityId;
    }

    $query .= " ORDER BY pt.created_date DESC, pt.id DESC";
    $res = sqlStatement($query, $binds);
    $rows = [];

    while ($row = sqlFetchArray($res)) {
        $pid = (int) ($row['pid'] ?? 0);
        $receiptNumber = trim((string) ($row['receipt_number'] ?? ''));
        $items = paymentReportParseItems((string) ($row['items'] ?? ''));
        $adjustmentData = paymentReportBuildAdjustmentAndNotes(
            $items,
            (string) ($row['price_override_notes'] ?? ''),
            $pid,
            $receiptNumber
        );
        $payments = round((float) ($row['amount'] ?? 0), 2);
        $adjustments = (float) ($adjustmentData['adjustments'] ?? 0);

        if ($payments <= 0 && $adjustments <= 0) {
            continue;
        }

        $rows[] = [
            'date' => substr((string) ($row['created_date'] ?? ''), 0, 10),
            'patient_name' => paymentReportGetPatientName($pid),
            'procedure' => paymentReportBuildProcedureSummary($items),
            'adjustments' => $adjustments,
            'notes' => (string) ($adjustmentData['notes'] ?? ''),
            'payments' => $payments,
            'payment_type' => paymentReportNormalizePaymentType($row['payment_method'] ?? ''),
        ];
    }

    return $rows;
}

$reportRows = [];
$reportError = '';
if ($isReportSubmitted) {
    try {
        $reportRows = paymentReportGetRows($form_from_date, $form_to_date, $form_facility);
    } catch (Throwable $e) {
        error_log('receipts_by_method_report.php failed: ' . errorLogEscape($e->getMessage()));
        $reportError = xl('The payment receipt summary could not be loaded. Please try again or contact support if it continues.');
    }
}

$grandAdjustments = 0.0;
$grandPayments = 0.0;
foreach ($reportRows as $reportRow) {
    $grandAdjustments += (float) ($reportRow['adjustments'] ?? 0);
    $grandPayments += (float) ($reportRow['payments'] ?? 0);
}

?>
<html>
<head>
    <title><?php echo xlt('Payment Receipt Summary'); ?></title>
    <?php Header::setupHeader(['datetime-picker']); ?>
    <style>
        @media print {
            #report_parameters {
                display: none;
            }

            .payment-report-page {
                padding: 0;
            }

            #report_results {
                margin-top: 16px;
                box-shadow: none;
                border: 1px solid #cbd5e1;
            }
        }

        .payment-report-page {
            padding: 24px;
            max-width: 1800px;
        }

        .payment-report-title {
            margin: 0 0 22px;
            font-size: 2.1rem;
            font-weight: 800;
            color: #1e293b;
        }

        .payment-report-card,
        .payment-report-results {
            background: #fff;
            border: 1px solid #dbe4ee;
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
        }

        .payment-report-card {
            padding: 26px;
        }

        .payment-filter-layout {
            display: flex;
            gap: 24px;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .payment-filter-grid {
            flex: 1 1 900px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
            align-items: end;
        }

        .payment-filter-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .payment-filter-field label {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 700;
            color: #334155;
        }

        .payment-filter-field .form-control,
        .payment-filter-field select {
            min-height: 48px;
            border-radius: 12px;
        }

        .payment-report-actions {
            display: flex;
            align-items: center;
            min-width: 220px;
        }

        .payment-report-actions .btn-group {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
        }

        .payment-report-results {
            margin-top: 24px;
            overflow: hidden;
        }

        .payment-report-meta {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            padding: 16px 20px;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
            color: #475569;
            font-size: 0.95rem;
            font-weight: 700;
        }

        .payment-report-table {
            width: 100%;
            margin: 0;
            border-collapse: collapse;
        }

        .payment-report-table thead th {
            background: #e9eff5;
            color: #334155;
            font-size: 0.95rem;
            font-weight: 800;
            border: 1px solid #dbe4ee;
            padding: 14px 12px;
            white-space: nowrap;
        }

        .payment-report-table tbody td {
            padding: 13px 12px;
            vertical-align: top;
            border: 1px solid #e2e8f0;
            color: #1f2937;
        }

        .payment-report-table tbody tr:hover td {
            background: #fafcff;
        }

        .payment-report-empty {
            padding: 24px;
            text-align: center;
            color: #64748b;
            font-style: italic;
        }

        .payment-procedure,
        .payment-notes {
            min-width: 240px;
            white-space: normal;
            line-height: 1.45;
        }

        .payment-money {
            text-align: right;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        .payment-report-table tbody td.payment-payments-cell {
            background: #f0fdf4;
            color: #166534;
            font-weight: 800;
        }

        .payment-type {
            white-space: nowrap;
            font-weight: 700;
            color: #0f766e;
        }

        .payment-total-row td {
            background: #fff7ed;
            font-weight: 800;
        }

        @media (max-width: 920px) {
            .payment-report-page {
                padding: 16px;
            }

            .payment-report-card {
                padding: 18px;
            }

            .payment-report-actions {
                width: 100%;
            }

            .payment-report-results {
                overflow-x: auto;
            }
        }
    </style>
    <script>
        $(function () {
            $('.datepicker').datetimepicker({
                <?php $datetimepicker_timepicker = false; ?>
                <?php $datetimepicker_showseconds = false; ?>
                <?php $datetimepicker_formatInput = true; ?>
                <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
            });

            const printButton = document.getElementById('printbutton');
            if (printButton) {
                printButton.addEventListener('click', function () {
                    window.print();
                });
            }
        });
    </script>
</head>

<body class="body_top">
<div class="payment-report-page">
    <h1 class="payment-report-title"><?php echo xlt('Payment Receipt Summary'); ?></h1>

    <form method="post" action="receipts_by_method_report.php" id="theform">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />

        <div id="report_parameters" class="payment-report-card">
            <div class="payment-filter-layout">
                <div class="payment-filter-grid">
                    <div class="payment-filter-field">
                        <label for="form_facility"><?php echo xlt('Clinic'); ?></label>
                        <?php dropdown_facility($form_facility, 'form_facility', false); ?>
                    </div>

                    <div class="payment-filter-field">
                        <label for="form_from_date"><?php echo xlt('From'); ?></label>
                        <input type="text" class="datepicker form-control" name="form_from_date" id="form_from_date" value="<?php echo attr(oeFormatShortDate($form_from_date)); ?>">
                    </div>

                    <div class="payment-filter-field">
                        <label for="form_to_date"><?php echo xlt('To{{Range}}'); ?></label>
                        <input type="text" class="datepicker form-control" name="form_to_date" id="form_to_date" value="<?php echo attr(oeFormatShortDate($form_to_date)); ?>">
                    </div>
                </div>

                <div class="payment-report-actions">
                    <div class="btn-group" role="group">
                        <button type="submit" class="btn btn-secondary btn-save"><?php echo xlt('Submit'); ?></button>
                        <?php if ($isReportSubmitted) { ?>
                            <button type="button" class="btn btn-secondary btn-print" id="printbutton"><?php echo xlt('Print'); ?></button>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>

        <div id="report_results" class="payment-report-results">
            <?php if ($isReportSubmitted) { ?>
                <div class="payment-report-meta">
                    <span><?php echo text(xl('Showing') . ' ' . count($reportRows) . ' ' . xl('receipt row(s)')); ?></span>
                    <span><?php echo text(xl('Adjustment Total') . ': ' . paymentReportMoney($grandAdjustments)); ?></span>
                    <span><?php echo text(xl('Payment Total') . ': ' . paymentReportMoney($grandPayments)); ?></span>
                </div>
            <?php } ?>

            <table class="table payment-report-table">
                <thead>
                    <tr>
                        <th><?php echo xlt('Date'); ?></th>
                        <th><?php echo xlt('Patient Name'); ?></th>
                        <th><?php echo xlt('Procedure'); ?></th>
                        <th class="payment-money"><?php echo xlt('Adjustments'); ?></th>
                        <th><?php echo xlt('Notes'); ?></th>
                        <th class="payment-money"><?php echo xlt('Payments'); ?></th>
                        <th><?php echo xlt('Payment Type'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$isReportSubmitted) { ?>
                        <tr>
                            <td colspan="7" class="payment-report-empty">
                                <?php echo xlt('Choose a clinic and date range, then click Submit to view the payment receipt summary.'); ?>
                            </td>
                        </tr>
                    <?php } elseif ($reportError !== '') { ?>
                        <tr>
                            <td colspan="7" class="payment-report-empty">
                                <?php echo text($reportError); ?>
                            </td>
                        </tr>
                    <?php } elseif (empty($reportRows)) { ?>
                        <tr>
                            <td colspan="7" class="payment-report-empty">
                                <?php echo xlt('No payment receipts were found for the selected clinic and date range.'); ?>
                            </td>
                        </tr>
                    <?php } else { ?>
                        <?php foreach ($reportRows as $row) { ?>
                            <tr>
                                <td><?php echo text(oeFormatShortDate($row['date'])); ?></td>
                                <td><?php echo text($row['patient_name']); ?></td>
                                <td class="payment-procedure"><?php echo text($row['procedure']); ?></td>
                                <td class="payment-money"><?php echo text(paymentReportMoney($row['adjustments'])); ?></td>
                                <td class="payment-notes"><?php echo text($row['notes']); ?></td>
                                <td class="payment-money payment-payments-cell"><?php echo text(paymentReportMoney($row['payments'])); ?></td>
                                <td class="payment-type"><?php echo text($row['payment_type']); ?></td>
                            </tr>
                        <?php } ?>
                        <tr class="payment-total-row">
                            <td colspan="3"><?php echo xlt('Grand Total'); ?></td>
                            <td class="payment-money"><?php echo text(paymentReportMoney($grandAdjustments)); ?></td>
                            <td>&nbsp;</td>
                            <td class="payment-money"><?php echo text(paymentReportMoney($grandPayments)); ?></td>
                            <td>&nbsp;</td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </form>
</div>
</body>
</html>
