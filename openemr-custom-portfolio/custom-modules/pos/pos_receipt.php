<?php
/**
 * POS Receipt Printer
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Team
 * @copyright Copyright (c) 2024 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once("$srcdir/patient.inc");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

// Ensure user is logged in
if (!isset($_SESSION['authUserID'])) {
    die(xlt('Not authorized'));
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

$receipt_number = $_GET['receipt_number'] ?? '';
$pid = $_GET['pid'] ?? 0;

if (!$receipt_number || !$pid) {
    die(xlt('Invalid receipt information'));
}

function posReceiptDisplayPaymentMethod($transactionType)
{
    $transactionType = trim((string) $transactionType);
    if ($transactionType === 'dispense_and_administer') {
        return 'DA';
    }

    return $transactionType;
}

// Get receipt data from pos_transactions table. Operational receipts may already
// exist in pos_receipts even when the matching transaction lookup is delayed or
// saved under a different ordering path, so keep a fallback from pos_receipts.
$receipt_result = sqlFetchArray(sqlStatement(
    "SELECT * FROM pos_transactions
     WHERE receipt_number = ? AND pid = ?
     ORDER BY CASE WHEN transaction_type = 'external_payment' THEN 0 ELSE 1 END, id DESC
     LIMIT 1",
    array($receipt_number, $pid)
));

$stored_receipt_row = null;
$stored_receipt_data = null;
if (!$receipt_result) {
    $stored_receipt_row = sqlFetchArray(sqlStatement(
        "SELECT *
           FROM pos_receipts
          WHERE receipt_number = ? AND pid = ?
          ORDER BY id DESC
          LIMIT 1",
        [$receipt_number, $pid]
    ));

    if (!empty($stored_receipt_row['receipt_data'])) {
        $decoded_receipt = json_decode((string) $stored_receipt_row['receipt_data'], true);
        if (is_array($decoded_receipt)) {
            $stored_receipt_data = $decoded_receipt;
            $receipt_result = [
                'id' => $stored_receipt_row['transaction_id'] ?? 0,
                'receipt_number' => $stored_receipt_row['receipt_number'] ?? $receipt_number,
                'pid' => $stored_receipt_row['pid'] ?? $pid,
                'amount' => $stored_receipt_row['amount'] ?? 0,
                'transaction_type' => $stored_receipt_row['payment_method'] ?? 'dispense',
                'items' => json_encode($stored_receipt_data['items'] ?? []),
                'created_date' => $stored_receipt_row['created_date'] ?? date('Y-m-d H:i:s'),
                'price_override_notes' => $stored_receipt_data['price_override_notes'] ?? '',
            ];
        }
    }
}

if (!$receipt_result) {
    die(xlt('Receipt not found'));
}

$payments = [];
$payment_rows = sqlStatement(
    "SELECT transaction_type, payment_method, amount
     FROM pos_transactions
     WHERE pid = ? AND receipt_number = ?
     ORDER BY created_date ASC",
    [$pid, $receipt_number]
);

while ($row = sqlFetchArray($payment_rows)) {
    $method = $row['payment_method'] ?? $row['transaction_type'] ?? 'unknown';

    $payments[] = [
        'method' => strtolower($method),
        'amount' => (float)($row['amount'] ?? 0),
    ];
}
$total_paid_db = 0;
foreach ($payments as $p) {
    $total_paid_db += (float)($p['amount'] ?? 0);
}

$credit_amount_db = 0.0;
try {
    $credit_sum = sqlFetchArray(sqlStatement(
        "SELECT COALESCE(SUM(amount), 0) AS applied_credit
         FROM patient_credit_transactions
         WHERE pid = ? AND receipt_number = ? AND transaction_type = 'payment'",
        [$pid, $receipt_number]
    ));
    $credit_amount_db = round((float)($credit_sum['applied_credit'] ?? 0), 2);
} catch (Throwable $e) {
    $credit_amount_db = 0.0;
}

// Prefer the finalized receipt payload when available because it preserves
// order-level adjustments that do not exist on individual line items.
if ($stored_receipt_data === null) {
    try {
        $stored_receipt_row = sqlFetchArray(sqlStatement(
            "SELECT receipt_data
               FROM pos_receipts
              WHERE pid = ? AND receipt_number = ?
              ORDER BY id DESC
              LIMIT 1",
            [$pid, $receipt_number]
        ));
        if (!empty($stored_receipt_row['receipt_data'])) {
            $decoded_receipt = json_decode((string) $stored_receipt_row['receipt_data'], true);
            if (is_array($decoded_receipt)) {
                $stored_receipt_data = $decoded_receipt;
            }
        }
    } catch (Throwable $e) {
        $stored_receipt_data = null;
    }
}

$fallback_receipt_data = [
    'receipt_number' => $receipt_result['receipt_number'],
    'transaction_id' => $receipt_result['id'],
    'patient_id' => $receipt_result['pid'],
    'amount' => $receipt_result['amount'],
    'payment_method' => posReceiptDisplayPaymentMethod($receipt_result['transaction_type']),
    'items' => json_decode($receipt_result['items'], true),
    'date' => $receipt_result['created_date'],
    'status' => 'completed',
    'price_override_notes' => trim((string) ($receipt_result['price_override_notes'] ?? '')),
    'individual_payments' => $payments,
    'total_paid_db' => $total_paid_db,
    'credit_amount' => $credit_amount_db
];

$receipt_data = array_merge($fallback_receipt_data, $stored_receipt_data ?? []);
$receipt_data['individual_payments'] = $payments;
$receipt_data['total_paid_db'] = $total_paid_db;
$receipt_data['credit_amount'] = $credit_amount_db;

$patient_data = getPatientData($pid, 'fname,mname,lname,pubpid,DOB,phone_home,phone_cell,email');
if (!$patient_data) {
    die(xlt('Patient data not found'));
}

function formatPrepayReceiptDate($date)
{
    if (empty($date)) {
        return '';
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return (string)$date;
    }

    return date('m/d/Y', $timestamp);
}

function getReceiptDrugDiscountRule(int $drugId): array
{
    static $cache = [];

    if ($drugId <= 0) {
        return [];
    }

    if (array_key_exists($drugId, $cache)) {
        return $cache[$drugId];
    }

    $row = sqlQuery(
        "SELECT discount_active, discount_type, discount_percent, discount_amount, discount_quantity, discount_start_date, discount_end_date, discount_month
         FROM drugs WHERE drug_id = ?",
        [$drugId]
    );

    $cache[$drugId] = is_array($row) ? $row : [];
    return $cache[$drugId];
}

function isReceiptDiscountRuleActive(array $rule, $referenceDate = ''): bool
{
    if (empty($rule) || empty($rule['discount_active'])) {
        return false;
    }

    $referenceTimestamp = $referenceDate ? strtotime((string)$referenceDate) : time();
    if ($referenceTimestamp === false) {
        $referenceTimestamp = time();
    }

    $referenceDay = date('Y-m-d', $referenceTimestamp);
    $referenceMonth = date('Y-m', $referenceTimestamp);
    $startDate = trim((string)($rule['discount_start_date'] ?? ''));
    $endDate = trim((string)($rule['discount_end_date'] ?? ''));
    $discountMonth = trim((string)($rule['discount_month'] ?? ''));

    if ($startDate !== '' && $startDate !== '0000-00-00' && $endDate !== '' && $endDate !== '0000-00-00') {
        return ($referenceDay >= $startDate && $referenceDay <= $endDate);
    }

    if ($startDate !== '' && $startDate !== '0000-00-00' && ($endDate === '' || $endDate === '0000-00-00')) {
        return ($referenceDay === $startDate);
    }

    if ($discountMonth !== '') {
        return ($referenceMonth === $discountMonth);
    }

    return true;
}

function calculateReceiptQuantityDiscountTotal(float $unitPrice, int $quantity, int $discountQuantity): float
{
    if ($discountQuantity <= 1 || $quantity <= 0) {
        return $unitPrice * $quantity;
    }

    $groupSize = $discountQuantity + 1;
    $fullGroups = (int) floor($quantity / $groupSize);
    $remainingItems = $quantity % $groupSize;

    return ($fullGroups * $discountQuantity * $unitPrice) + ($remainingItems * $unitPrice);
}

function getReceiptItemLineTotal(array $item, $referenceDate = ''): float
{
    if (isset($item['line_total']) && is_numeric($item['line_total'])) {
        return (float) $item['line_total'];
    }

    $quantity = (int) ($item['quantity'] ?? 0);
    $unitPrice = (float) ($item['price'] ?? 0);
    if ($quantity <= 0 || $unitPrice <= 0) {
        return $unitPrice * $quantity;
    }

    $drugId = (int) ($item['drug_id'] ?? 0);
    if ($drugId > 0) {
        $rule = getReceiptDrugDiscountRule($drugId);
        if (
            !empty($rule) &&
            isReceiptDiscountRuleActive($rule, $referenceDate) &&
            (($rule['discount_type'] ?? '') === 'quantity') &&
            (int) ($rule['discount_quantity'] ?? 0) > 1
        ) {
            return calculateReceiptQuantityDiscountTotal($unitPrice, $quantity, (int) $rule['discount_quantity']);
        }
    }

    return $unitPrice * $quantity;
}

function posReceiptNormalizeLocationLine(string $value): string
{
    return strtolower(preg_replace('/[^a-z0-9]+/', '', $value));
}

function posReceiptBuildFacilityDisplay(array $facility_result): array
{
    $brandName = 'Achieve Medical';
    $locationName = trim((string) ($facility_result['name'] ?? ''));
    $street = trim((string) ($facility_result['street'] ?? ''));
    $city = trim((string) ($facility_result['city'] ?? ''));
    $state = trim((string) ($facility_result['state'] ?? ''));
    $postal = trim((string) ($facility_result['postal_code'] ?? ''));

    $addressParts = [];
    $locationNormalized = posReceiptNormalizeLocationLine($locationName);
    $brandNormalized = posReceiptNormalizeLocationLine($brandName);
    $stateNormalized = posReceiptNormalizeLocationLine($state);

    if (
        $locationName !== '' &&
        $locationNormalized !== $brandNormalized &&
        $locationNormalized !== $stateNormalized
    ) {
        $addressParts[] = $locationName;
    }

    $streetNormalized = posReceiptNormalizeLocationLine($street);
    if (
        $street !== '' &&
        $streetNormalized !== '' &&
        $streetNormalized !== $locationNormalized &&
        $streetNormalized !== $stateNormalized
    ) {
        $addressParts[] = $street;
    }

    $cityStatePostalParts = [];
    if ($city !== '') {
        $cityStatePostalParts[] = $city;
    }
    if ($state !== '') {
        $cityStatePostalParts[] = $state;
    }
    if ($postal !== '') {
        $cityStatePostalParts[] = $postal;
    }
    $cityStatePostal = trim(implode(', ', $cityStatePostalParts), ', ');

    if ($cityStatePostal !== '') {
        $cityStateNormalized = posReceiptNormalizeLocationLine($cityStatePostal);
        $locationAlreadyContainsState = $stateNormalized !== '' && strpos($locationNormalized, $stateNormalized) !== false;
        $locationAlreadyMatchesCityState = $cityStateNormalized !== '' && strpos($locationNormalized, $cityStateNormalized) !== false;
        if (
            ($locationNormalized === '' || $locationNormalized !== $cityStateNormalized) &&
            !$locationAlreadyMatchesCityState &&
            !$locationAlreadyContainsState
        ) {
            $addressParts[] = $cityStatePostal;
        }
    }

    return [
        'brand_name' => $brandName,
        'address_html' => implode('<br>', $addressParts),
    ];
}

// Use the facility saved on the transaction so the receipt header matches the actual sale location.
$receiptFacilityId = (int) ($receipt_result['facility_id'] ?? 0);
$facility_result = [];

if ($receiptFacilityId > 0) {
    $facility_result = sqlFetchArray(sqlStatement(
        "SELECT name, street, city, state, postal_code, country_code
         FROM facility
         WHERE id = ?
         LIMIT 1",
        [$receiptFacilityId]
    )) ?: [];
}

if (empty($facility_result)) {
    $facility_query = "SELECT f.name, f.street, f.city, f.state, f.postal_code, f.country_code
                       FROM facility f
                       JOIN users u ON f.id = u.facility_id
                       WHERE u.username = ?";
    $facility_result = sqlFetchArray(sqlStatement($facility_query, array($_SESSION['authUser']))) ?: [];
}

$facility_display = posReceiptBuildFacilityDisplay($facility_result);
$facility_name = $facility_display['brand_name'];
$facility_address = $facility_display['address_html'];

Header::setupHeader(['opener']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo xlt('Receipt'); ?> - <?php echo $receipt_number; ?></title>
    <style>
        @media print {
            body { margin: 0; }
            .no-print { display: none !important; }
        }
        
        body {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.2;
            margin: 20px;
            background: white !important;
        }
        
        .receipt {
            max-width: 300px;
            margin: 0 auto;
            border: 1px solid #ccc;
            padding: 10px;
            background: white !important;
        }
        
        .header {
            text-align: center;
            border-bottom: 1px solid #000;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }
        
        .facility-name {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .facility-address {
            font-size: 10px;
            margin-bottom: 5px;
            color: #666;
        }
        
        .receipt-number {
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .date-time {
            font-size: 12px;
        }
        
        .patient-info {
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px dashed #ccc;
        }
        
        .items {
            margin-bottom: 10px;
        }
        
        .item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }
        
        .item-name {
            flex: 1;
        }

        .item-meta {
            margin-top: 4px;
            font-size: 10px;
            color: #666;
            line-height: 1.35;
        }

        .prepay-note {
            margin-top: 5px;
            padding: 4px 6px;
            border-left: 2px solid #28a745;
            background: #f4fbf6;
        }

        .prepay-note-label {
            font-weight: bold;
            color: #1f7a36;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .override-note {
            margin-top: 8px;
            padding: 6px 8px;
            border-left: 2px solid #d97706;
            background: #fff7ed;
            font-size: 10px;
            line-height: 1.35;
        }

        .override-note-label {
            font-weight: bold;
            color: #b45309;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        
        .item-price {
            text-align: right;
            min-width: 60px;
        }
        
        .total-section {
            border-top: 1px solid #000;
            padding-top: 10px;
            margin-top: 10px;
        }
        
        .total-line {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }
        
        .total-line.final {
            font-weight: bold;
            font-size: 14px;
        }
        
        .payment-section {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed #ccc;
        }
        
        .payment-entry {
            margin-bottom: 10px;
        }
        
        .payment-line {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }
        
        .payment-line.final {
            font-weight: bold;
            border-top: 1px solid #000;
            padding-top: 3px;
            margin-top: 3px;
        }
        
        .payment-status {
            border-top: 1px dashed #ccc;
            padding-top: 10px;
            font-size: 11px;
        }
        
        .footer {
            text-align: center;
            margin-top: 20px;
            font-size: 10px;
        }
        
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .print-button:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">
        <i class="fas fa-print"></i> Print Receipt
    </button>
    
    <div class="receipt">
        <div class="header">
            <div class="facility-name"><?php echo text($facility_name); ?></div>
            <?php if (!empty($facility_address)): ?>
            <div class="facility-address"><?php echo $facility_address; ?></div>
            <?php endif; ?>
            <div class="receipt-number"><?php echo xlt('Receipt'); ?>: <?php echo $receipt_number; ?></div>
            <div class="date-time"><?php echo date('m/d/Y H:i:s', strtotime($receipt_data['date'] ?? 'now')); ?></div>
        </div>
        
        <div class="patient-info">
            <div><strong><?php echo xlt('Patient'); ?>:</strong> <?php echo $receipt_data['patient_name'] ?? ($patient_data['fname'] . ' ' . $patient_data['lname']); ?></div>
            <div><strong><?php echo xlt('ID'); ?>:</strong> <?php echo $patient_data['pubpid']; ?></div>
            <?php if ($patient_data['phone_cell'] || $patient_data['phone_home']): ?>
            <div><strong><?php echo xlt('Phone'); ?>:</strong> <?php echo $patient_data['phone_cell'] ?: $patient_data['phone_home']; ?></div>
            <?php endif; ?>
        </div>
        
        <div class="items">
            <div style="text-align: center; margin-bottom: 5px; font-weight: bold;"><?php echo xlt('Items Purchased'); ?></div>
            <?php
            $subtotal = 0;
            $tax_total = 0;
            $stored_tax_total = round((float)($receipt_data['tax_total'] ?? 0), 2);
            $taxable_products = [];
            $credit_amount = (float)($receipt_data['credit_amount'] ?? 0);
            $ten_off_discount = round((float)($receipt_data['ten_off_discount'] ?? 0), 2);
            $invoice_total = 0;
            ?>
            <?php if (isset($receipt_data['items']) && is_array($receipt_data['items'])): ?>
                <?php 
                $receiptReferenceDate = $receipt_data['date'] ?? '';
                foreach ($receipt_data['items'] as $item): 
                    $item_total = getReceiptItemLineTotal($item, $receiptReferenceDate);
                    $original_item_total = isset($item['original_line_total'])
                        ? (float)($item['original_line_total'] ?? 0)
                        : ((float)($item['original_price'] ?? ($item['price'] ?? 0)) * (float)($item['quantity'] ?? 1));
                    $item_discount = isset($item['line_discount'])
                        ? (float)($item['line_discount'] ?? 0)
                        : max(0, $original_item_total - $item_total);
                    $subtotal += $item_total;

                    $item_name = (string)($item['name'] ?? $item['display_name'] ?? '');
                    $item_name_lc = strtolower($item_name);
                    $is_taxable_item = (
                        strpos($item_name_lc, 'metatrim') !== false ||
                        strpos($item_name_lc, 'meta trim') !== false ||
                        strpos($item_name_lc, 'baricare') !== false ||
                        strpos($item_name_lc, 'bari care') !== false
                    );
                    if ($is_taxable_item && $item_total > 0) {
                        $taxable_products[] = $item_name;
                    }
                    
                    // Calculate tax for this item if tax breakdown is available
                    $item_tax = 0;
                    if (
                        $stored_tax_total > 0 &&
                        isset($receipt_data['tax_breakdown']) &&
                        is_array($receipt_data['tax_breakdown'])
                    ) {
                        foreach ($receipt_data['tax_breakdown'] as $tax) {
                            $tax_rate = $tax['rate'] ?? 0;
                            $item_tax += $item_total * ($tax_rate / 100);
                        }
                    }
                    $tax_total += $item_tax;
                ?>

                <div class="item">
                    <div class="item-name">
                        <?php echo text($item['name'] ?? $item['display_name'] ?? 'Unknown Item'); ?>
                        <div class="item-meta">
                            <span><?php echo xlt('Qty'); ?>: <?php echo text($item['quantity'] ?? 1); ?></span>
                            <?php if (isset($item['dispense_quantity']) && (float) $item['dispense_quantity'] > 0): ?>
                                <span style="color: #28a745; margin-left: 6px;"><?php echo xlt('Dispense'); ?>: <?php echo text($item['dispense_quantity']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($item['administer_quantity'])): ?>
                                <span style="color: #0d6efd; margin-left: 6px;"><?php echo xlt('Administer'); ?>: <?php echo text($item['administer_quantity']); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($item['prepay_selected'])): ?>
                            <?php
                            $prepayDate = formatPrepayReceiptDate($item['prepay_date'] ?? '');
                            $prepayReference = trim((string)($item['prepay_sale_reference'] ?? ''));
                            ?>
                            <div class="item-meta prepay-note">
                                <div class="prepay-note-label"><?php echo xlt('Prepaid Item'); ?></div>
                                <div><?php echo xlt('Receipt price'); ?>: $0.00</div>
                                <?php if ($prepayDate !== ''): ?>
                                    <div><?php echo xlt('Paid on'); ?>: <?php echo text($prepayDate); ?></div>
                                <?php endif; ?>
                                <?php if ($prepayReference !== ''): ?>
                                    <div><?php echo xlt('Notes'); ?>: <?php echo text($prepayReference); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="item-price">
                        $<?php echo number_format($item_total, 2); ?>
                        <br><span style="font-size: 10px; color: #666;">
                            <?php echo xlt('Unit'); ?>: $<?php echo number_format((float)($item['price'] ?? 0), 2); ?>
                        </span>
                        <?php if ($item_discount > 0): ?>
                            <br><span style="font-size: 10px; color: #28a745;">
                                <?php echo xlt('Discount'); ?>: -$<?php echo number_format($item_discount, 2); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($item_tax > 0): ?>
                            <br><span style="font-size: 10px; color: #666;">+ Tax: $<?php echo number_format($item_tax, 2); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php
                // Fallback: if tax_breakdown is missing but payments imply extra amount, treat as tax/fee
                if (
                    (!isset($receipt_data['tax_breakdown']) || !is_array($receipt_data['tax_breakdown']) || count($receipt_data['tax_breakdown']) === 0)
                    && isset($receipt_data['total_paid_db'])
                ) {
                    $paid_sum = (float)($receipt_data['total_paid_db'] ?? 0);

                    // Only if paid is greater than subtotal (and credit is not the reason)
                    $implied_tax = max(0, $paid_sum - $subtotal - $credit_amount);

                    if ($implied_tax > 0 && $tax_total == 0) {
                        $tax_total = $implied_tax;
                    }
                }

                if (array_key_exists('tax_total', $receipt_data)) {
                    $tax_total = $stored_tax_total;
                }

                if ($ten_off_discount <= 0) {
                    $paid_reference_total = 0;
                    if (isset($receipt_data['total_paid_db'])) {
                        $paid_reference_total = round((float)($receipt_data['total_paid_db'] ?? 0), 2);
                    } elseif (isset($receipt_data['amount'])) {
                        $paid_reference_total = round((float)($receipt_data['amount'] ?? 0), 2);
                    }

                    $implied_order_discount = round((($subtotal + $tax_total) - $credit_amount) - $paid_reference_total, 2);
                    if ($implied_order_discount > 0) {
                        $ten_off_discount = $implied_order_discount;
                    }
                }

                $taxable_products = array_values(array_unique(array_filter($taxable_products)));
                $invoice_total = max(0, ($subtotal + $tax_total) - $credit_amount - $ten_off_discount);
                ?>
            <?php else: ?>
                <div class="item">
                    <div class="item-name"><?php echo xlt('No items found'); ?></div>
                    <div class="item-price">$0.00</div>
                </div>
            <?php endif; ?>

            <?php if (!empty($receipt_data['price_override_notes'])): ?>
                <div class="override-note">
                    <div class="override-note-label"><?php echo xlt('Price Override Notes'); ?></div>
                    <div><?php echo text($receipt_data['price_override_notes']); ?></div>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="total-section">
            <div class="total-line">
                <span><?php echo xlt('Subtotal'); ?>:</span>
                <span>$<?php echo number_format($subtotal, 2); ?></span>
            </div>
            <?php if (isset($receipt_data['tax_breakdown']) && is_array($receipt_data['tax_breakdown'])): ?>
                <?php foreach ($receipt_data['tax_breakdown'] as $tax): ?>
                <div class="total-line">
                    <span><?php echo $tax['name'] ?? xlt('Tax'); ?>:</span>
                    <span>$<?php echo number_format($tax['amount'] ?? 0, 2); ?></span>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
            <div class="total-line">
                <span><?php echo xlt('Tax'); ?>:</span>
                <span>$<?php echo number_format($tax_total, 2); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($taxable_products) && $tax_total > 0): ?>
            <div class="total-line">
                <span><?php echo xlt('Tax Products'); ?>:</span>
                <span style="text-align: right;"><?php echo text(implode(', ', $taxable_products)); ?></span>
            </div>
            <?php endif; ?>
            <?php if (isset($receipt_data['credit_amount']) && $receipt_data['credit_amount'] > 0): ?>
            <div class="total-line" style="color: #28a745;">
                <span><?php echo xlt('Credit Applied'); ?>:</span>
                <span>-$<?php echo number_format($receipt_data['credit_amount'], 2); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($ten_off_discount)): ?>
            <div class="total-line" style="color: #946200;">
                <span><?php echo xlt('$10 Off'); ?>:</span>
                <span>-$<?php echo number_format($ten_off_discount, 2); ?></span>
            </div>
            <?php endif; ?>
            <div class="total-line final">
                <span><?php echo xlt('Total'); ?>:</span>
                <span>$<?php echo number_format($invoice_total, 2); ?></span>
            </div>
        </div>
        
        <div class="payment-section">
            <div style="text-align: center; margin-bottom: 5px; font-weight: bold;"><?php echo xlt('Payment Details'); ?></div>
            
            <?php
                // Payment status logic
                $status = $receipt_data['payment_status'] ?? null;
                $error = $receipt_data['payment_error'] ?? null;
                if (!$status) {
                    if (!empty($receipt_data['transaction_id'])) {
                        $status = 'Success';
                    } else if (!empty($error)) {
                        $status = 'Error';
                    } else {
                        $status = 'Unknown';
                    }
                }
                
                $total_sales = $invoice_total;
                $total_paid = 0;
            ?>
            
            <div class="payment-entry">
                <?php if (isset($receipt_data['credit_amount']) && $receipt_data['credit_amount'] > 0): ?>
                <div class="payment-line" style="color: #28a745;">
                    <span><?php echo xlt('Credit Applied'); ?>:</span>
                    <span>-$<?php echo number_format($receipt_data['credit_amount'], 2); ?></span>
                </div>
                <?php $total_paid += $receipt_data['credit_amount']; ?>
                <?php endif; ?>
                
                <?php if (isset($receipt_data['individual_payments']) && is_array($receipt_data['individual_payments']) && count($receipt_data['individual_payments']) > 0): ?>
                    <?php foreach ($receipt_data['individual_payments'] as $index => $payment): ?>
                    <div class="payment-line">
                        <span><?php echo ucwords(str_replace('_', ' ', $payment['method'])); ?> <?php echo xlt('Payment'); ?>:</span>
                        <span>$<?php echo number_format($payment['amount'], 2); ?></span>
                    </div>
                    <?php $total_paid += $payment['amount']; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php 
                    // Fallback for single payment receipts
                    $payment_method = ucwords(str_replace('_', ' ', strtolower($receipt_data['payment_method'] ?? 'Unknown')));
                    $amount_paid = $receipt_data['amount'] ?? 0;
                    $change = $receipt_data['change'] ?? 0;
                    ?>
                    <div class="payment-line">
                        <span><?php echo $payment_method; ?> <?php echo xlt('Payment'); ?>:</span>
                        <span>$<?php echo number_format($amount_paid, 2); ?></span>
                    </div>
                    <?php $total_paid = $amount_paid - $change; ?>
                    
                    <?php if ($change > 0): ?>
                    <div class="payment-line">
                        <span><?php echo xlt('Change Given'); ?>:</span>
                        <span>-$<?php echo number_format($change, 2); ?></span>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <div class="payment-line final">
                    <span><?php echo xlt('Total Paid'); ?>:</span>
                    <span>$<?php echo number_format($total_paid, 2); ?></span>
                </div>
                
                <div class="payment-line final">
                    <span><?php echo xlt('Balance'); ?>:</span>
                    <span>$<?php echo number_format($total_sales - $total_paid, 2); ?></span>
                </div>
            </div>
            
            <div class="payment-status">
                <div><strong><?php echo xlt('Payment Status'); ?>:</strong> <?php echo xlt($status); ?></div>
                <?php if (isset($receipt_data['transaction_id']) && $receipt_data['transaction_id']): ?>
                <div><strong><?php echo xlt('Transaction ID'); ?>:</strong> <?php echo $receipt_data['transaction_id']; ?></div>
                <?php endif; ?>
                <?php if (!empty($error)): ?>
                <div style="color: red;"><strong><?php echo xlt('Error'); ?>:</strong> <?php echo text($error); ?></div>
                <?php endif; ?>
                <div><strong><?php echo xlt('Cashier'); ?>:</strong> <?php echo $receipt_data['user'] ?? $_SESSION['authUser'] ?? 'Unknown'; ?></div>
            </div>
        </div>
        
        <div class="footer">
            <div><?php echo xlt('Thank you for your purchase!'); ?></div>
            <div><?php echo xlt('Please keep this receipt for your records.'); ?></div>
            <div style="margin-top: 10px;">
                <?php echo xlt('For questions, please contact us.'); ?>
            </div>
        </div>
    </div>
    
    <script>
        // Auto-print when page loads
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html> 
