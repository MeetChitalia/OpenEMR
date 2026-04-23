<?php
/**
 * backdate_pos_screen.php
 * Minimal Backdate POS screen:
 * - patient header
 * - inventory search (same simple_search.php)
 * - order summary (no price, no proceed)
 * - backdate date picker
 * - Save Backdated Entry (persists to DB via backdate_save.php)
 */

require_once(dirname(__FILE__) . "/../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$pid = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;

$csrf = CsrfUtils::collectCsrfToken();
$selectedFacilityId = !empty($_SESSION['facilityId']) ? (int) $_SESSION['facilityId'] : (!empty($_SESSION['facility_id']) ? (int) $_SESSION['facility_id'] : 0);

$patient = null;
$backdateDispenseTracking = [];

if (!empty($pid)) {
    $res = sqlStatement(
        "SELECT pid, fname, mname, lname, DOB, sex, phone_cell
         FROM patient_data
         WHERE pid = ?",
        [$pid]
    );
    $patient = sqlFetchArray($res) ?: null;

    $trackingResult = sqlStatement(
        "SELECT
            prd.drug_id,
            d.name,
            d.form,
            d.strength,
            d.size,
            d.unit,
            COUNT(*) AS lot_count,
            SUM(COALESCE(prd.total_quantity, 0)) AS total_bought,
            SUM(COALESCE(prd.dispensed_quantity, 0)) AS total_dispensed,
            SUM(COALESCE(prd.administered_quantity, 0)) AS total_administered,
            SUM(COALESCE(prd.remaining_quantity, 0)) AS total_remaining,
            MAX(prd.last_updated) AS last_updated
         FROM pos_remaining_dispense prd
         INNER JOIN drugs d ON d.drug_id = prd.drug_id
         WHERE prd.pid = ?
         GROUP BY prd.drug_id, d.name, d.form, d.strength, d.size, d.unit
         ORDER BY total_remaining DESC, d.name ASC",
        [$pid]
    );

    while ($trackingRow = sqlFetchArray($trackingResult)) {
        $productParts = array_filter([
            trim((string) ($trackingRow['name'] ?? '')),
            trim((string) ($trackingRow['form'] ?? '')),
            trim((string) ($trackingRow['strength'] ?? '')),
            trim((string) ($trackingRow['size'] ?? '')),
            trim((string) ($trackingRow['unit'] ?? '')),
        ]);

        $backdateDispenseTracking[] = [
            'product_name' => implode(' ', $productParts),
            'lot_count' => (int) ($trackingRow['lot_count'] ?? 0),
            'total_bought' => round((float) ($trackingRow['total_bought'] ?? 0), 2),
            'total_dispensed' => round((float) ($trackingRow['total_dispensed'] ?? 0), 2),
            'total_administered' => round((float) ($trackingRow['total_administered'] ?? 0), 2),
            'total_remaining' => round((float) ($trackingRow['total_remaining'] ?? 0), 2),
            'last_updated' => $trackingRow['last_updated'] ?? '',
        ];
    }
}


?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(['jquery', 'bootstrap', 'font-awesome']); ?>
    <title><?php echo xlt('Backdate'); ?></title>

 <style>
    body {
        margin: 0;
        background: #f5f7fb;
        color: #1f2937;
        font-family: Arial, sans-serif;
    }

    .page-wrap {
        max-width: 1480px;
        margin: 0 auto;
        padding: 28px 28px 40px;
    }

    .title-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 20px;
        margin-bottom: 20px;
    }

    .page-heading {
        display: flex;
        align-items: baseline;
        gap: 14px;
        flex-wrap: wrap;
    }

    .page-title {
        margin: 0;
        font-size: 26px;
        line-height: 1.1;
        font-weight: 800;
        color: #222;
    }

    .page-chip {
        font-size: 15px;
        font-weight: 600;
        color: #6b7280;
    }

    .subtitle {
        margin-top: 10px;
        color: #6b7280;
        font-size: 16px;
        font-weight: 500;
    }

    .btn-back {
        border: 1px solid #d8dee8;
        background: #fff;
        color: #374151;
        padding: 12px 16px;
        border-radius: 12px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s ease;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06);
    }

    .btn-back:hover {
        background: #f8fafc;
        border-color: #c6d0dd;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.10);
        transform: translateY(-1px);
    }

    .card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 18px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
    }

    .backdate-patient-bar {
        margin-top: 18px;
        padding: 18px 20px;
        background: #f8fafc;
        border-left: 4px solid #1e88ff;
        border-radius: 12px;
        display: flex !important;
        flex-direction: row !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 56px !important;
        flex-wrap: nowrap !important;
        overflow-x: auto !important;
        overflow-y: hidden !important;
        white-space: nowrap !important;
        text-align: left !important;
    }

    .backdate-patient-bar > div {
        flex: 0 0 auto !important;
        width: auto !important;
        max-width: none !important;
        margin: 0 !important;
    }

    .backdate-patient-name {
        flex: 0 0 auto !important;
        display: inline-block !important;
        font-size: 18px;
        font-weight: 800;
        color: #253043;
        white-space: nowrap !important;
    }

    .backdate-patient-meta {
        display: inline-flex !important;
        flex-direction: row !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 8px;
        flex: 0 0 auto !important;
        width: auto !important;
        white-space: nowrap !important;
        color: #4b5563;
        font-size: 15px;
        text-align: left !important;
    }

    .backdate-patient-meta .label {
        font-weight: 700;
        color: #374151;
    }

    .backdate-patient-meta .value {
        font-weight: 600;
        color: #111827;
    }

    .search-card {
        margin-top: 22px;
        overflow: hidden;
    }

    .search-wrap {
        position: relative;
        padding: 18px 20px;
    }

    .search-wrap .fa-search {
        position: absolute;
        left: 38px;
        top: 50%;
        transform: translateY(-50%);
        color: #6b7280;
        font-size: 20px;
    }

    .search-input {
        width: 100%;
        min-height: 72px;
        padding: 18px 22px 18px 56px;
        border: 1px solid #dde3ed;
        border-radius: 12px;
        background: #fff;
        font-size: 18px;
        color: #1f2937;
        outline: none;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .search-input:focus {
        border-color: #4f8ef7;
        box-shadow: 0 0 0 4px rgba(79, 142, 247, 0.12);
    }

    .dropdown-results {
        margin: 0 20px 20px;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 16px 40px rgba(15, 23, 42, 0.12);
        max-height: 360px;
        overflow-y: auto;
        display: none;
    }

    .dropdown-itemx {
        padding: 16px 18px;
        cursor: pointer;
        border-bottom: 1px solid #eff3f8;
        transition: background-color 0.18s ease;
    }

    .dropdown-itemx:last-child {
        border-bottom: none;
    }

    .dropdown-itemx:hover {
        background: #f8fbff;
    }

    .dropdown-itemx.disabled {
        opacity: 0.55;
        cursor: not-allowed;
        pointer-events: none;
    }

    .item-title {
        font-weight: 700;
        color: #1f2937;
        font-size: 16px;
        margin-bottom: 6px;
    }

    .item-sub {
        font-size: 13px;
        color: #6b7280;
    }

    .nolot-badge {
        margin-left: 8px;
        font-size: 11px;
        padding: 3px 8px;
        border-radius: 999px;
        background: #fee2e2;
        color: #991b1b;
        font-weight: 700;
    }

    .summary-card {
        margin-top: 22px;
        padding: 0;
        overflow: hidden;
    }

    .tracking-card {
        margin-top: 22px;
        overflow: hidden;
    }

    .tracking-header {
        padding: 22px 24px;
        border-bottom: 1px solid #e8edf3;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
    }

    .tracking-title {
        margin: 0;
        font-size: 22px;
        font-weight: 800;
        color: #252c35;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .tracking-subtitle {
        margin-top: 6px;
        color: #6b7280;
        font-size: 14px;
        font-weight: 500;
    }

    .btn-track {
        min-height: 48px;
        background: #1e88ff;
        color: #fff;
        border: none;
        padding: 12px 18px;
        border-radius: 12px;
        font-weight: 800;
        font-size: 14px;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        box-shadow: 0 10px 24px rgba(30, 136, 255, 0.20);
        transition: all 0.2s ease;
    }

    .btn-track:hover {
        background: #1877e6;
        color: #fff;
        text-decoration: none;
        transform: translateY(-1px);
    }

    .tracking-content {
        padding: 24px;
    }

    .tracking-summary-row {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 20px;
    }

    .tracking-stat {
        background: #f8fafc;
        border: 1px solid #e8edf3;
        border-radius: 14px;
        padding: 16px;
    }

    .tracking-stat-label {
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #6b7280;
    }

    .tracking-stat-value {
        margin-top: 8px;
        font-size: 28px;
        font-weight: 800;
        color: #1f2937;
    }

    .tracking-stat-value.good {
        color: #16a34a;
    }

    .tracking-stat-value.info {
        color: #0ea5e9;
    }

    .tracking-stat-value.warn {
        color: #dc2626;
    }

    .tracking-empty {
        padding: 34px 18px;
        text-align: center;
        color: #6b7280;
        font-size: 15px;
        font-weight: 600;
        background: #f8fafc;
        border: 1px dashed #d8e0ea;
        border-radius: 14px;
    }

    .summary-header {
        padding: 22px 24px;
        border-bottom: 1px solid #e8edf3;
    }

    .summary-title {
        margin: 0;
        font-size: 22px;
        font-weight: 800;
        color: #252c35;
    }

    .summary-content {
        padding: 24px;
    }

    .top-controls {
        display: flex;
        align-items: end;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 18px;
    }

    .date-block label {
        display: block;
        margin-bottom: 8px;
        font-size: 14px;
        font-weight: 800;
        letter-spacing: 0.02em;
        color: #374151;
        text-transform: uppercase;
    }

    .date-block input {
        min-width: 240px;
        padding: 14px 16px;
        border: 1px solid #d8e0ea;
        border-radius: 12px;
        background: #fff;
        font-size: 16px;
        color: #111827;
        outline: none;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .date-block input:focus {
        border-color: #4f8ef7;
        box-shadow: 0 0 0 4px rgba(79, 142, 247, 0.12);
    }

    .btn-save {
        min-height: 52px;
        background: #0f9d58;
        color: #fff;
        border: none;
        padding: 14px 20px;
        border-radius: 12px;
        font-weight: 800;
        font-size: 15px;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        transition: all 0.2s ease;
        box-shadow: 0 10px 24px rgba(15, 157, 88, 0.20);
    }

    .btn-save:hover {
        background: #0d8a4d;
        transform: translateY(-1px);
        box-shadow: 0 14px 28px rgba(15, 157, 88, 0.24);
    }

    .btn-save:disabled {
        background: #bbd9c7;
        color: #f7faf8;
        box-shadow: none;
        cursor: not-allowed;
        transform: none;
    }

    .summary-table-wrap {
        border: 1px solid #edf1f6;
        border-radius: 14px;
        overflow: hidden;
        background: #fff;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    thead th {
        padding: 18px 16px;
        text-align: left;
        font-size: 13px;
        letter-spacing: 0.04em;
        font-weight: 800;
        color: #374151;
        background: #f8fafc;
        border-bottom: 1px solid #e8edf3;
    }

    tbody td {
        padding: 18px 16px;
        border-bottom: 1px solid #eef2f7;
        vertical-align: middle;
        font-size: 15px;
        color: #1f2937;
        font-weight: 600;
    }

    tbody tr:last-child td {
3        border-bottom: none;
    }

    .tracking-table thead th:nth-child(n+2),
    .tracking-table tbody td:nth-child(n+2) {
        text-align: center;
    }

    .tracking-table thead th:first-child,
    .tracking-table tbody td:first-child {
        text-align: left;
    }

    .qty-input {
        width: 112px;
        min-height: 48px;
        border-radius: 10px;
        border: 1px solid #d8e0ea;
        padding: 10px 12px;
        font-size: 16px;
        font-weight: 700;
        color: #1f2937;
        background: #fff;
        outline: none;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .qty-input:focus {
        border-color: #4f8ef7;
        box-shadow: 0 0 0 4px rgba(79, 142, 247, 0.12);
    }

    .empty-row {
        padding: 24px 16px;
        text-align: center;
        color: #6b7280;
        font-weight: 600;
    }

    .msg {
        margin-bottom: 16px;
        display: none;
        padding: 14px 16px;
        border-radius: 12px;
        font-weight: 700;
    }

    .msg.ok {
        background: #ecfdf5;
        color: #065f46;
        border: 1px solid #a7f3d0;
    }

    .msg.err {
        background: #fef2f2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }

    .success-modal-overlay {
        position: fixed;
        inset: 0;
        z-index: 1000000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 24px;
        background: rgba(15, 23, 42, 0.44);
    }

    .success-modal-overlay.show {
        display: flex;
    }

    .success-modal {
        width: min(100%, 460px);
        background: #fff;
        border-radius: 22px;
        border: 1px solid #e5e7eb;
        box-shadow: 0 28px 60px rgba(15, 23, 42, 0.25);
        overflow: hidden;
    }

    .success-modal-header {
        padding: 26px 28px 18px;
        text-align: center;
        background: linear-gradient(135deg, #ebfaf0 0%, #f8fffb 100%);
        border-bottom: 1px solid #e5efe8;
    }

    .success-modal-icon {
        width: 60px;
        height: 60px;
        margin: 0 auto 14px;
        border-radius: 999px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #16a34a 0%, #22c55e 100%);
        color: #fff;
        font-size: 24px;
        box-shadow: 0 16px 30px rgba(34, 197, 94, 0.30);
    }

    .success-modal-title {
        margin: 0;
        color: #1f2937;
        font-size: 24px;
        font-weight: 800;
    }

    .success-modal-body {
        padding: 24px 28px 28px;
        text-align: center;
    }

    .success-modal-message {
        margin: 0;
        color: #4b5563;
        font-size: 16px;
        line-height: 1.6;
    }

    .success-modal-receipt {
        margin-top: 18px;
        padding: 14px 16px;
        background: #f8fafc;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        color: #111827;
        font-size: 16px;
        font-weight: 700;
    }

    .success-modal-actions {
        margin-top: 22px;
        display: flex;
        justify-content: center;
    }

    .success-modal-btn {
        min-width: 140px;
        border: none;
        border-radius: 12px;
        padding: 12px 22px;
        background: linear-gradient(135deg, #0d6efd 0%, #1e88ff 100%);
        color: #fff;
        font-size: 15px;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 14px 28px rgba(30, 136, 255, 0.22);
        transition: transform 0.18s ease, box-shadow 0.18s ease;
    }

    .success-modal-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 18px 34px rgba(30, 136, 255, 0.28);
    }

    @media (max-width: 900px) {
        .page-wrap {
            padding: 20px 16px 32px;
        }

        .title-row {
            flex-direction: column;
            align-items: stretch;
        }

        .btn-back {
            align-self: flex-start;
        }

        .backdate-patient-bar {
            gap: 24px !important;
        }

        .summary-content {
            padding: 18px;
        }

        .tracking-summary-row {
            grid-template-columns: 1fr 1fr;
        }
    }
 </style>


</head>

<body>
<div class="page-wrap">

    <div class="title-row">
        <div>
            <div class="page-heading">
                <h1 class="page-title"><?php echo xlt('Backdate POS'); ?></h1>
                <span class="page-chip"><?php echo xlt('Inventory Entry'); ?></span>
            </div>
            <div class="subtitle"><?php echo xlt('Search inventory and add to Order Summary.'); ?></div>
        </div>
        <button class="btn-back" type="button" onclick="goBack()">
            <i class="fa fa-arrow-left"></i> <?php echo xlt('Back'); ?>
        </button>
    </div>

    <?php if (!$pid || empty($patient)) : ?>
        <div class="card" style="margin-top:22px; padding:18px;">
            <div class="msg err" style="display:block;">
                <?php echo xlt('No patient selected. Open this screen from Finder Backdate button, or pass ?pid=123 in the URL.'); ?>
            </div>
        </div>
    <?php else: ?>
       <?php
            $fullName = trim(($patient['fname'] ?? '') . ' ' . ($patient['mname'] ?? '') . ' ' . ($patient['lname'] ?? ''));
            $dob = $patient['DOB'] ?? '';
            $sex = $patient['sex'] ?? '';
            $cell = $patient['phone_cell'] ?? '';
        ?>
      <div class="card backdate-patient-bar">
    <div class="backdate-patient-name">
        <?php echo text($fullName); ?>
    </div>

    <div class="backdate-patient-meta">
        <span class="label"><?php echo xlt('Mobile'); ?>:</span>
        <span class="value"><?php echo text($cell); ?></span>
    </div>

    <div class="backdate-patient-meta">
        <span class="label"><?php echo xlt('DOB'); ?>:</span>
        <span class="value"><?php echo text($dob); ?></span>
    </div>

    <div class="backdate-patient-meta">
        <span class="label"><?php echo xlt('Gender'); ?>:</span>
        <span class="value"><?php echo text($sex); ?></span>
    </div>
</div>


        <div class="card search-card">
            <div class="search-wrap">
                <i class="fa fa-search"></i>
                <input id="inventory_search" class="search-input" type="text"
                       placeholder="<?php echo xla('Search Medicine, Products, Office, etc'); ?>" autocomplete="off" />
            </div>

            <div id="search_results" class="dropdown-results"></div>
        </div>

        <div class="card summary-card">
            <div class="summary-header">
                <div class="summary-title"><?php echo xlt('Order Summary'); ?></div>
            </div>

            <div class="summary-content">
                <div class="top-controls">
                    <div class="date-block">
                        <label for="backdate"><?php echo xlt('Date Purchased'); ?></label>
                        <input type="date" id="backdate" />
                    </div>

                    <button id="save_btn" class="btn-save" type="button" onclick="saveBackdatedEntry()" disabled>
                        <i class="fa fa-save"></i> <?php echo xlt('Save Backdated Entry'); ?>
                    </button>
                </div>

                <div id="msg_ok" class="msg ok"></div>
                <div id="msg_err" class="msg err"></div>

                <div class="summary-table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th><?php echo xlt('PRODUCT NAME'); ?></th>
                            <th><?php echo xlt('LOT NO'); ?></th>
                            <th><?php echo xlt('QUANTITY'); ?></th>
                            <th><?php echo xlt('DISPENSED'); ?></th>
                            <th><?php echo xlt('ADMINISTERED'); ?></th>
                        </tr>
                        </thead>
                        <tbody id="order_body">
                            <tr id="empty_row">
                                <td class="empty-row" colspan="5"><?php echo xlt('No items added yet.'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php
            $trackingTotals = [
                'bought' => 0.0,
                'dispensed' => 0.0,
                'administered' => 0.0,
                'remaining' => 0.0,
            ];
            foreach ($backdateDispenseTracking as $trackingItem) {
                $trackingTotals['bought'] += (float) $trackingItem['total_bought'];
                $trackingTotals['dispensed'] += (float) $trackingItem['total_dispensed'];
                $trackingTotals['administered'] += (float) $trackingItem['total_administered'];
                $trackingTotals['remaining'] += (float) $trackingItem['total_remaining'];
            }
        ?>
        <div class="card tracking-card">
            <div class="tracking-header">
                <div>
                    <div class="tracking-title">
                        <i class="fa fa-pills" style="color:#1e88ff;"></i>
                        <?php echo xlt('Backdated Dispense Tracking'); ?>
                    </div>
                    <div class="tracking-subtitle"><?php echo xlt('Current patient tracking summary from dispensed and administered history.'); ?></div>
                </div>
                <a class="btn-track" href="<?php echo attr($GLOBALS['webroot'] . '/interface/pos/pos_modal.php?pid=' . urlencode((string) $pid) . '&dispense_tracking=1'); ?>">
                    <i class="fa fa-external-link"></i> <?php echo xlt('Open Full Dispense Tracking'); ?>
                </a>
            </div>
            <div class="tracking-content">
                <div class="tracking-summary-row">
                    <div class="tracking-stat">
                        <div class="tracking-stat-label"><?php echo xlt('Products Tracked'); ?></div>
                        <div class="tracking-stat-value"><?php echo text((string) count($backdateDispenseTracking)); ?></div>
                    </div>
                    <div class="tracking-stat">
                        <div class="tracking-stat-label"><?php echo xlt('Total Bought'); ?></div>
                        <div class="tracking-stat-value"><?php echo text(number_format($trackingTotals['bought'], 2)); ?></div>
                    </div>
                    <div class="tracking-stat">
                        <div class="tracking-stat-label"><?php echo xlt('Total Dispensed/Administered'); ?></div>
                        <div class="tracking-stat-value info"><?php echo text(number_format($trackingTotals['dispensed'] + $trackingTotals['administered'], 2)); ?></div>
                    </div>
                    <div class="tracking-stat">
                        <div class="tracking-stat-label"><?php echo xlt('Total Remaining'); ?></div>
                        <div class="tracking-stat-value <?php echo ($trackingTotals['remaining'] > 0 ? 'warn' : 'good'); ?>"><?php echo text(number_format($trackingTotals['remaining'], 2)); ?></div>
                    </div>
                </div>

                <?php if (empty($backdateDispenseTracking)) : ?>
                    <div class="tracking-empty">
                        <?php echo xlt('No dispensed tracking records found yet for this patient.'); ?>
                    </div>
                <?php else : ?>
                    <div class="summary-table-wrap">
                        <table class="tracking-table">
                            <thead>
                                <tr>
                                    <th><?php echo xlt('Product'); ?></th>
                                    <th><?php echo xlt('Lots'); ?></th>
                                    <th><?php echo xlt('Bought'); ?></th>
                                    <th><?php echo xlt('Dispensed'); ?></th>
                                    <th><?php echo xlt('Administered'); ?></th>
                                    <th><?php echo xlt('Remaining'); ?></th>
                                    <th><?php echo xlt('Last Updated'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($backdateDispenseTracking as $trackingItem) : ?>
                                    <tr>
                                        <td><?php echo text($trackingItem['product_name']); ?></td>
                                        <td><?php echo text((string) $trackingItem['lot_count']); ?></td>
                                        <td><?php echo text(number_format($trackingItem['total_bought'], 2)); ?></td>
                                        <td style="color:#16a34a;"><?php echo text(number_format($trackingItem['total_dispensed'], 2)); ?></td>
                                        <td style="color:#0ea5e9;"><?php echo text(number_format($trackingItem['total_administered'], 2)); ?></td>
                                        <td style="color:<?php echo ($trackingItem['total_remaining'] > 0 ? '#dc2626' : '#16a34a'); ?>;"><?php echo text(number_format($trackingItem['total_remaining'], 2)); ?></td>
                                        <td><?php echo text($trackingItem['last_updated'] ? oeFormatShortDate(substr($trackingItem['last_updated'], 0, 10)) . ' ' . substr($trackingItem['last_updated'], 11, 5) : ''); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>
</div>

<div id="success_modal_overlay" class="success-modal-overlay" aria-hidden="true">
    <div class="success-modal" role="dialog" aria-modal="true" aria-labelledby="success_modal_title">
        <div class="success-modal-header">
            <div class="success-modal-icon">
                <i class="fa fa-check"></i>
            </div>
            <h2 id="success_modal_title" class="success-modal-title"><?php echo xlt('Transaction Saved'); ?></h2>
        </div>
        <div class="success-modal-body">
            <p class="success-modal-message"><?php echo xlt('The backdated transaction was completed successfully.'); ?></p>
            <div id="success_modal_receipt" class="success-modal-receipt"></div>
            <div class="success-modal-actions">
                <button id="success_modal_undo" type="button" class="success-modal-btn" style="background:#fff; color:#0f766e; border:1px solid #99f6e4;"><?php echo xlt('Undo Backdated Entry'); ?></button>
                <button id="success_modal_ok" type="button" class="success-modal-btn"><?php echo xlt('OK'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
const PID = <?php echo (int)$pid; ?>;
const CSRF = <?php echo js_escape($csrf); ?>;
const WEBROOT = <?php echo js_escape($GLOBALS['webroot']); ?>;
const SELECTED_FACILITY_ID = <?php echo (int) $selectedFacilityId; ?>;

let orderItems = []; // {key, drug_id, lot_number, name, quantity, dispense, administer}
let backdateSaveInProgress = false;
let lastBackdateReceiptNumber = '';
const BACKDATE_MEDICATION_DOSE_OPTIONS = {
    semaglutide: ['0.25', '0.50', '1.00', '1.70', '2.40'],
    tirzepatide: ['2.50', '5.00', '7.50', '10.00', '12.50', '15.00'],
    testosterone: ['80', '100', '120', '140', '160', '180', '200', '240', '300']
};

function goBack() {
    // same tab navigation (not a new window)
    window.history.back();
}

function navigateAfterBackdateSave() {
    const sameOriginReferrer = document.referrer && document.referrer.startsWith(window.location.origin)
        ? document.referrer
        : '';

    if (sameOriginReferrer) {
        window.location.assign(sameOriginReferrer);
        return;
    }

    window.location.assign(`${WEBROOT}/interface/main/finder/existing_finder.php?backdate_mode=1`);
}

function showBackdateSuccessModal(receipt) {
    return new Promise((resolve) => {
        const overlay = document.getElementById('success_modal_overlay');
        const okButton = document.getElementById('success_modal_ok');
        const undoButton = document.getElementById('success_modal_undo');
        const receiptBox = document.getElementById('success_modal_receipt');

        if (!overlay || !okButton || !receiptBox || !undoButton) {
            resolve();
            return;
        }

        lastBackdateReceiptNumber = receipt || '';
        receiptBox.textContent = `Receipt: ${receipt}`;
        overlay.classList.add('show');
        overlay.setAttribute('aria-hidden', 'false');
        undoButton.disabled = !lastBackdateReceiptNumber;

        const closeModal = () => {
            overlay.classList.remove('show');
            overlay.setAttribute('aria-hidden', 'true');
            okButton.removeEventListener('click', handleOk);
            undoButton.removeEventListener('click', handleUndo);
            document.removeEventListener('keydown', handleEsc);
            resolve();
        };

        const handleOk = () => closeModal();
        const handleUndo = async () => {
            const reason = window.prompt('Enter a short reason for undoing this backdated entry:', 'Backdated entry entered by mistake');
            if (!reason || !reason.trim()) {
                return;
            }

            undoButton.disabled = true;
            undoButton.textContent = '<?php echo xla('Undoing'); ?>...';

            try {
                const response = await fetch(`${WEBROOT}/interface/pos/backdate_void.php`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        pid: PID,
                        receipt_number: lastBackdateReceiptNumber,
                        reason: reason.trim(),
                        csrf_token_form: CSRF
                    })
                });

                const result = await response.json().catch(() => ({}));
                if (!response.ok || !result.success) {
                    throw new Error(result.error || 'Unable to undo backdated entry.');
                }

                showOk(`Backdated entry reversed. Receipt: ${result.reversal_receipt_number || 'N/A'}`);
                closeModal();
                window.location.reload();
            } catch (error) {
                undoButton.disabled = false;
                undoButton.textContent = '<?php echo xla('Undo Backdated Entry'); ?>';
                showErr(error.message || 'Unable to undo backdated entry.');
            }
        };
        const handleEsc = (event) => {
            if (event.key === 'Escape') {
                closeModal();
            }
        };

        okButton.addEventListener('click', handleOk);
        undoButton.addEventListener('click', handleUndo);
        document.addEventListener('keydown', handleEsc);
        okButton.focus();
    });
}

// default backdate = today
(function initDate() {
    const el = document.getElementById('backdate');
    if (!el) return;
    const today = new Date();
    const yyyy = today.getFullYear();
    const mm = String(today.getMonth() + 1).padStart(2,'0');
    const dd = String(today.getDate()).padStart(2,'0');
    el.value = `${yyyy}-${mm}-${dd}`;
})();

function showOk(msg) {
    const ok = document.getElementById('msg_ok');
    const er = document.getElementById('msg_err');
    if (er) er.style.display = 'none';
    if (ok) { ok.textContent = msg; ok.style.display = 'block'; }
}
function showErr(msg) {
    const ok = document.getElementById('msg_ok');
    const er = document.getElementById('msg_err');
    if (ok) ok.style.display = 'none';
    if (er) { er.textContent = msg; er.style.display = 'block'; }
}

function setSaveEnabled() {
    const btn = document.getElementById('save_btn');
    if (!btn) return;
    btn.disabled = !(orderItems.length > 0 && PID > 0);
}

function getBackdateMedicationDoseKey(item) {
    const itemName = String(item?.name || '').toLowerCase();
    if (itemName.includes('semaglutide')) {
        return 'semaglutide';
    }
    if (itemName.includes('tirzepatide')) {
        return 'tirzepatide';
    }
    if (itemName.includes('testosterone')) {
        return 'testosterone';
    }
    return '';
}

function hasBackdateDoseSelector(item) {
    return !!getBackdateMedicationDoseKey(item);
}

function getBackdateDoseOptions(item) {
    const doseKey = getBackdateMedicationDoseKey(item);
    return BACKDATE_MEDICATION_DOSE_OPTIONS[doseKey] || [];
}

function getBackdateDefaultDose(item) {
    const options = getBackdateDoseOptions(item);
    return options.length ? options[0] : '';
}

function updateBackdateDose(index, value) {
    if (!orderItems[index]) {
        return;
    }
    const item = orderItems[index];
    const nextDose = String(value || '');
    const currentDose = String(item.dose_option_mg || '');
    const doseKey = getBackdateMedicationDoseKey(item);

    item.dose_option_mg = nextDose;

    if (
        nextDose &&
        currentDose &&
        currentDose !== nextDose &&
        (doseKey === 'semaglutide' || doseKey === 'testosterone')
    ) {
        window.alert(`${doseKey === 'semaglutide' ? 'Semaglutide' : 'Testosterone'} dose changed from ${currentDose} mg to ${nextDose} mg. Please make sure the dose change is intentional before saving this backdated entry.`);
    }
}

function renderOrderTable() {
    const body = document.getElementById('order_body');
    const empty = document.getElementById('empty_row');
    if (!body) return;

    // clear rows except empty
    body.querySelectorAll('tr.data-row').forEach(r => r.remove());

    if (orderItems.length === 0) {
        if (empty) empty.style.display = '';
        setSaveEnabled();
        return;
    }
    if (empty) empty.style.display = 'none';

    orderItems.forEach((it, idx) => {
        const tr = document.createElement('tr');
        tr.className = 'data-row';

        const tdName = document.createElement('td');
        tdName.textContent = it.name;

        const tdLot = document.createElement('td');
        const doseSelectorHtml = hasBackdateDoseSelector(it)
            ? `<div style="margin-top:8px;"><select class="form-control form-control-sm" style="min-width:110px;" onchange="updateBackdateDose(${idx}, this.value)">${getBackdateDoseOptions(it).map(option => `<option value="${option}" ${(it.dose_option_mg || getBackdateDefaultDose(it)) === option ? 'selected' : ''}>${option} mg</option>`).join('')}</select></div>`
            : '';
        tdLot.innerHTML = `${escapeHtml(it.lot_number || '')}${doseSelectorHtml}`;

        const tdQty = document.createElement('td');
        tdQty.innerHTML = `<input class="qty-input" type="number" min="0" value="${it.quantity}" data-idx="${idx}" data-field="quantity">`;

        const tdDisp = document.createElement('td');
        tdDisp.innerHTML = `<input class="qty-input" type="number" min="0" value="${it.dispense}" data-idx="${idx}" data-field="dispense">`;

        const tdAdm = document.createElement('td');
        tdAdm.innerHTML = `<input class="qty-input" type="number" min="0" value="${it.administer}" data-idx="${idx}" data-field="administer">`;

        tr.appendChild(tdName);
        tr.appendChild(tdLot);
        tr.appendChild(tdQty);
        tr.appendChild(tdDisp);
        tr.appendChild(tdAdm);

        body.appendChild(tr);
    });

    setSaveEnabled();
}

document.addEventListener('input', function(e) {
    const t = e.target;
    if (!t || !t.matches('input.qty-input')) return;

    const idx = parseInt(t.getAttribute('data-idx'), 10);
    const field = t.getAttribute('data-field');
    if (isNaN(idx) || !field || !orderItems[idx]) return;

    let val = parseInt(t.value, 10);
    if (isNaN(val) || val < 0) val = 0;

    orderItems[idx][field] = val;

    // optional sanity: keep dispense+administer <= quantity by auto-bumping quantity
    const q = orderItems[idx].quantity || 0;
    const d = orderItems[idx].dispense || 0;
    const a = orderItems[idx].administer || 0;
    if ((d + a) > q) {
        orderItems[idx].quantity = d + a;
        renderOrderTable();
    }

    setSaveEnabled();
});

// ------------------- Inventory Search (simple_search.php) -------------------
let searchTimer = null;

document.getElementById('inventory_search')?.addEventListener('input', function() {
    clearTimeout(searchTimer);
    const q = this.value.trim();
    searchTimer = setTimeout(() => runInventorySearch(q), 220);
});

document.addEventListener('click', function(e) {
    const res = document.getElementById('search_results');
    const box = document.getElementById('inventory_search');
    if (!res || !box) return;
    if (res.contains(e.target) || box.contains(e.target)) return;
    res.style.display = 'none';
});

async function runInventorySearch(query) {
    const resBox = document.getElementById('search_results');
    if (!resBox) return;

    if (!query || query.length < 2) {
        resBox.style.display = 'none';
        resBox.innerHTML = '';
        return;
    }

    try {
        // IMPORTANT: this is what your original POS uses
        const url = `${WEBROOT}/interface/pos/simple_search.php?search=${encodeURIComponent(query)}&limit=20&facility_id=${encodeURIComponent(SELECTED_FACILITY_ID)}`;
        const r = await fetch(url, { credentials:'same-origin' });
        const data = await r.json();

        const results = (data && data.results) ? data.results : [];
        if (!results.length) {
            resBox.innerHTML = `<div class="dropdown-itemx"><div class="item-sub">No matches</div></div>`;
            resBox.style.display = 'block';
            return;
        }

        resBox.innerHTML = results.map(item => {
    const title = (item.display_name || item.name || '').toString();
    const sub   = (item.lot_display || item.category_name || item.manufacturer || '').toString();

    // Detect "No Lot" based on your returned id OR lot fields
    const idStr = (item.id || '').toString();
    const noLot =
        idStr.includes('_no_lot') ||
        (item.lot_number && item.lot_number === 'No Lot') ||
        (item.lot_display && item.lot_display.toString().toLowerCase().includes('no lot'));

    // Optional: also disable if qoh is 0 (keep/remove depending on your rule)
    const noQty = (item.qoh !== undefined && item.qoh !== null && Number(item.qoh) <= 0);

    const disabled = noLot || noQty;

    const safeTitle = escapeHtml(title);
    const safeSub   = escapeHtml(sub);

    const payload = encodeURIComponent(JSON.stringify(item));

    return `
      <div class="dropdown-itemx ${disabled ? 'disabled' : ''}" data-payload="${payload}">
        <div class="item-title">
          ${safeTitle}
          ${disabled ? `<span class="nolot-badge">No Lot</span>` : ``}
        </div>
        <div class="item-sub">${safeSub}</div>
      </div>
    `;
}).join('');


        resBox.style.display = 'block';

    } catch (err) {
        console.error(err);
        resBox.innerHTML = `<div class="dropdown-itemx"><div class="item-sub">Search error</div></div>`;
        resBox.style.display = 'block';
    }
}

document.getElementById('search_results')?.addEventListener('click', function(e) {
    const row = e.target.closest('.dropdown-itemx');
    if (!row) return;

    const payload = row.getAttribute('data-payload');
    if (!payload) return;

    const item = JSON.parse(decodeURIComponent(payload));

    // We expect drug-type IDs like: drug_148_lot_100 or drug_150_no_lot
    // This is exactly like your returned JSON.
    const parsed = parseDrugId(item.id || '');
    if (!parsed) {
        showErr('Could not parse selected item id.');
        return;
    }

    const key = `${parsed.drug_id}::${parsed.lot_number}`;
    const exists = orderItems.find(x => x.key === key);
    if (exists) {
        // bump quantity
        exists.quantity = (exists.quantity || 0) + 1;
        // default dispense=quantity if you want; for now keep as-is
        renderOrderTable();
    } else {
        orderItems.push({
            key,
            drug_id: parsed.drug_id,
            lot_number: parsed.lot_number,
            name: (item.display_name || item.name || 'Item'),
            dose_option_mg: hasBackdateDoseSelector(item) ? getBackdateDefaultDose(item) : '',
            quantity: 1,
            dispense: 1,
            administer: 0
        });
        renderOrderTable();
    }

    // clear search + hide results
    const box = document.getElementById('inventory_search');
    const resBox = document.getElementById('search_results');
    if (box) box.value = '';
    if (resBox) { resBox.style.display = 'none'; resBox.innerHTML = ''; }
});

function parseDrugId(id) {
    // Examples:
    // drug_148_lot_100
    // drug_150_no_lot
    const s = (id || '').toString();
    if (!s.startsWith('drug_')) return null;

    // drug_(drugid)_lot_(lot) OR drug_(drugid)_no_lot
    const lotMatch = s.match(/^drug_(\d+)_lot_(.+)$/);
    if (lotMatch) {
        return { drug_id: parseInt(lotMatch[1], 10), lot_number: lotMatch[2] };
    }
    const noLotMatch = s.match(/^drug_(\d+)_no_lot$/);
    if (noLotMatch) {
        return { drug_id: parseInt(noLotMatch[1], 10), lot_number: 'No Lot' };
    }
    return null;
}

function escapeHtml(str) {
    return (str || '').toString()
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");
}
function showReceiptToast(message, ms = 3500) {
  let el = document.getElementById('receipt-toast');
  if (!el) {
    el = document.createElement('div');
    el.id = 'receipt-toast';
    el.style.cssText = `
      position: fixed; top: 20px; right: 20px; z-index: 999999;
      background: #28a745; color: #fff; padding: 12px 16px;
      border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,.15);
      font-weight: 600; max-width: 520px; white-space: pre-line;
      opacity: 0; transform: translateY(-6px); transition: all .18s ease;
    `;
    document.body.appendChild(el);
  }

  el.textContent = message;
  el.style.opacity = '1';
  el.style.transform = 'translateY(0)';

  clearTimeout(el._t);
  el._t = setTimeout(() => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(-6px)';
  }, ms);
}
// ------------------- Save to DB -------------------
async function saveBackdatedEntry() 
{
    if (backdateSaveInProgress) {
        return;
    }

    if (!PID) { showErr('Missing patient id.'); return; }
    if (!orderItems.length) { showErr('No items to save.'); return; }

    const dateEl = document.getElementById('backdate');
    const backdate = dateEl ? dateEl.value : '';
    if (!backdate) { showErr('Please select a backdate.'); return; }
    for (let item of orderItems) {
        if (!item.lot_number || item.lot_number === 'No Lot') {
            alert('No Lot for this inventory. It cannot be backdated.');
            return;
        }
    }

    // Build payload for server
    const payload = {
        pid: PID,
        backdate: backdate,  // YYYY-MM-DD
        items: orderItems.map(it => ({
            drug_id: it.drug_id,
            lot_number: it.lot_number,
            quantity: parseInt(it.quantity || 0, 10),
            dispense: parseInt(it.dispense || 0, 10),
            administer: parseInt(it.administer || 0, 10),
            name: it.name,
            dose_option_mg: it.dose_option_mg || ''
        })),
        csrf_token_form: CSRF
    };

    try {
        backdateSaveInProgress = true;
        const saveBtn = document.getElementById('save_btn');
        const originalBtnHtml = saveBtn ? saveBtn.innerHTML : '';
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> <?php echo xla('Saving'); ?>';
        }

        const url = `${WEBROOT}/interface/pos/backdate_save.php`;
        const r = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const text = await r.text();
let data = null;
try {
  data = JSON.parse(text);
} catch (e) {
  console.error('Server returned non-JSON:', text);
  backdateSaveInProgress = false;
  if (saveBtn) {
    saveBtn.disabled = false;
    saveBtn.innerHTML = originalBtnHtml;
  }
  showErr('Save failed. Server returned HTML/PHP error. Check backdate_save.php.');
  return;
}


        if (!r.ok || !data || !data.success) {
            backdateSaveInProgress = false;
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalBtnHtml;
            }
            showErr((data && data.error) ? data.error : 'Save failed.');
            return;
        }

      const receipt = data.receipt_number || 'N/A';
showOk('Saved. Receipt: ' + receipt);
showReceiptToast(`Saved!\nReceipt: ${receipt}`);
        orderItems = [];
        renderOrderTable();
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fa fa-check"></i> <?php echo xla('Saved'); ?>';
        }
        await showBackdateSuccessModal(receipt);
        navigateAfterBackdateSave();

    } catch (err) {
        backdateSaveInProgress = false;
        const saveBtn = document.getElementById('save_btn');
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fa fa-save"></i> <?php echo xla('Save Backdated Entry'); ?>';
        }
        console.error(err);
        showErr('Save error: ' + err.message);
    }
}

renderOrderTable();

</script>
</body>
</html>
