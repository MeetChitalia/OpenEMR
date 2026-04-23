<?php
/**
 * backdate_save.php
 * Save backdated dispense/administer quantities so they reflect in:
 *  - pos_transactions (transaction history)
 *  - pos_remaining_dispense (dispense tracking)
 *  - drug_inventory QOH (deducts inventory)
 */

require_once(__DIR__ . "/../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;

header('Content-Type: application/json');

// ---- Always return JSON even on fatal errors ----
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json', true, 500);
        echo json_encode([
            'success' => false,
            'error'   => 'Fatal error in backdate_save.php',
            'detail'  => $err['message'],
            'file'    => $err['file'],
            'line'    => $err['line']
        ]);
    }
});

function jexit($code, $msg, $detail = null)
{
    http_response_code($code);
    $out = ['success' => false, 'error' => $msg];
    if ($detail !== null) $out['detail'] = $detail;
    echo json_encode($out);
    exit;
}

if (empty($_SESSION['authUserID'])) {
    jexit(401, 'Not authorized');
}

$raw = file_get_contents('php://input');
if (!$raw) {
    jexit(400, 'Empty request body');
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    jexit(400, 'Invalid JSON');
}

$csrf = $data['csrf_token_form'] ?? $data['csrf_token'] ?? '';
if (!CsrfUtils::verifyCsrfToken($csrf)) {
    jexit(400, 'CSRF token validation failed');
}

/**
 * Detect quantity column in drug_inventory (matches your simple_search.php logic)
 */
function detectInventoryQtyColumn(): string
{
    $cols = [];
    $rs = sqlStatement("SHOW COLUMNS FROM drug_inventory");
    while ($row = sqlFetchArray($rs)) {
        $cols[] = $row['Field'];
    }

    if (in_array('quantity', $cols, true)) return 'quantity';
    if (in_array('qty', $cols, true)) return 'qty';
    if (in_array('stock', $cols, true)) return 'stock';
    if (in_array('on_hand', $cols, true)) return 'on_hand';

    return 'quantity';
}

function randHex8()
{
    if (function_exists('random_bytes')) return substr(bin2hex(random_bytes(4)), 0, 8);
    if (function_exists('openssl_random_pseudo_bytes')) return substr(bin2hex(openssl_random_pseudo_bytes(4)), 0, 8);
    return substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
}

function backdatePosHasColumn(string $column): bool
{
    static $cache = [];

    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $cache[$column] = (bool) sqlFetchArray(sqlStatement("SHOW COLUMNS FROM pos_transactions LIKE ?", [$column]));
    return $cache[$column];
}

function backdatePosFacilityId(int $pid): ?int
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

$pid = (int)($data['pid'] ?? 0);
if ($pid <= 0) jexit(400, 'Invalid patient id');

$backdate = trim((string)($data['backdate'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $backdate)) {
    jexit(400, 'Invalid backdate. Expected YYYY-MM-DD');
}

// Use noon time to avoid timezone day-shift issues
$createdDate = $backdate . ' 12:00:00';

$items = $data['items'] ?? [];
if (!is_array($items) || count($items) === 0) jexit(400, 'No items');

// Normalize items
$norm = [];
foreach ($items as $it) {
    $drug_id = (int)($it['drug_id'] ?? 0);
    if ($drug_id <= 0) continue;

    $lot = trim((string)($it['lot_number'] ?? ''));
    if ($lot === '') $lot = 'No Lot';

    $qty = (int)($it['quantity'] ?? 0);
    $disp = (int)($it['dispense'] ?? 0);
    $adm = (int)($it['administer'] ?? 0);

    if ($qty < 0) $qty = 0;
    if ($disp < 0) $disp = 0;
    if ($adm < 0) $adm = 0;

    // Ensure qty covers disp+adm
    $minTotal = $disp + $adm;
    if ($qty < $minTotal) $qty = $minTotal;

    $name = trim((string)($it['name'] ?? ''));
    $dose_option_mg = trim((string)($it['dose_option_mg'] ?? ''));

    $norm[] = [
        'drug_id' => $drug_id,
        'lot_number' => $lot,
        'quantity' => $qty,
        'dispense_quantity' => $disp,
        'administer_quantity' => $adm,
        'name' => $name,
        'dose_option_mg' => $dose_option_mg
    ];
}
if (count($norm) === 0) jexit(400, 'No valid items');

// Backdated entries are historical tracking only. They should not create new revenue
// or trigger inventory deduction because the real inventory movement already happened.
$transactionAmount = 0.00;

// Receipt number
$receipt = 'BD-' . date('YmdHis') . '-' . $pid . '-' . randHex8();

//
// Ensure required tables exist
//
try {
    $rs = sqlStatement("SHOW TABLES LIKE 'pos_transactions'");
    $txExists = sqlFetchArray($rs);
    if (!$txExists) {
        sqlStatement("CREATE TABLE IF NOT EXISTS `pos_transactions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `pid` int(11) NOT NULL,
            `receipt_number` varchar(50) NOT NULL,
            `transaction_type` varchar(20) NOT NULL DEFAULT 'dispense',
            `payment_method` varchar(50) DEFAULT NULL,
            `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
            `items` longtext NOT NULL,
            `created_date` datetime NOT NULL,
            `user_id` varchar(50) NOT NULL,
            `visit_type` varchar(10) NOT NULL DEFAULT '-',
            `price_override_notes` TEXT NULL,
            `facility_id` INT(11) NULL,
            PRIMARY KEY (`id`),
            KEY `pid` (`pid`),
            KEY `receipt_number` (`receipt_number`),
            KEY `created_date` (`created_date`),
            KEY `transaction_type` (`transaction_type`),
            KEY `facility_id` (`facility_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    $rs2 = sqlStatement("SHOW TABLES LIKE 'pos_remaining_dispense'");
    $rdExists = sqlFetchArray($rs2);
    if (!$rdExists) {
        sqlStatement("CREATE TABLE IF NOT EXISTS `pos_remaining_dispense` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `pid` int(11) NOT NULL,
            `drug_id` int(11) NOT NULL,
            `lot_number` varchar(50) NOT NULL DEFAULT '',
            `total_quantity` int(11) NOT NULL DEFAULT 0,
            `dispensed_quantity` int(11) NOT NULL DEFAULT 0,
            `administered_quantity` int(11) NOT NULL DEFAULT 0,
            `remaining_quantity` int(11) NOT NULL DEFAULT 0,
            `receipt_number` varchar(50) DEFAULT '',
            `created_date` datetime NOT NULL,
            `last_updated` datetime NOT NULL,
           
            PRIMARY KEY (`id`),
            KEY `pid` (`pid`),
            KEY `pid_drug_lot` (`pid`,`drug_id`,`lot_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
} catch (Exception $e) {
    jexit(500, 'DB init failed', $e->getMessage());
}

//
// Insert transaction history
//
try {
    $itemsForHistory = [];
    foreach ($norm as $it) {
        $itemsForHistory[] = [
            'drug_id' => $it['drug_id'],
            'name' => $it['name'],
            'lot_number' => $it['lot_number'],
            'dose_option_mg' => $it['dose_option_mg'] ?? ($it['semaglutide_dose_mg'] ?? ''),
            'quantity' => $it['quantity'],
            'dispense_quantity' => $it['dispense_quantity'],
            'administer_quantity' => $it['administer_quantity'],
            'is_remaining_dispense' => true
        ];
    }

    $itemsJson = json_encode($itemsForHistory, JSON_UNESCAPED_SLASHES);
    $userId = (string)($_SESSION['authUser'] ?? $_SESSION['authUserID'] ?? 'system');
    $visit_type = $data['visit_type'] ?? '-';
$visit_type = trim((string)$visit_type);
$price_override_notes = $data['price_override_notes'] ?? '';
$price_override_notes = trim((string)$price_override_notes);
if ($visit_type === '') {
    $visit_type = '-';
}
    $columns = ['pid', 'receipt_number', 'transaction_type', 'amount', 'items', 'created_date', 'user_id', 'visit_type', 'price_override_notes'];
    $params = [$pid, $receipt, 'dispense', $transactionAmount, $itemsJson, $createdDate, $userId, $visit_type, $price_override_notes];
    if (backdatePosHasColumn('facility_id')) {
        $columns[] = 'facility_id';
        $params[] = backdatePosFacilityId($pid);
    }
    sqlStatement(
        "INSERT INTO pos_transactions (" . implode(', ', $columns) . ")
         VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")",
        $params
    );
} catch (Exception $e) {
    jexit(500, 'Failed saving transaction history', $e->getMessage());
}

//
// Upsert remaining dispense
//
try {
    foreach ($norm as $it) {
        $drug_id = (int)$it['drug_id'];
        $lot = (string)$it['lot_number'];

        $qty  = (int)$it['quantity'];
        $disp = (int)$it['dispense_quantity'];
        $adm  = (int)$it['administer_quantity'];

        $remaining = max(0, $qty - $disp - $adm);

        $rs = sqlStatement(
            "SELECT id, total_quantity, dispensed_quantity, administered_quantity, remaining_quantity
             FROM pos_remaining_dispense
             WHERE pid = ? AND drug_id = ? AND lot_number = ?
             ORDER BY last_updated DESC
             LIMIT 1",
            [$pid, $drug_id, $lot]
        );
        $existing = sqlFetchArray($rs);

        if (!empty($existing['id'])) {
            $newTotal = (int)$existing['total_quantity'] + $qty;
            $newDisp  = (int)$existing['dispensed_quantity'] + $disp;
            $newAdm   = (int)$existing['administered_quantity'] + $adm;
            $newRem   = (int)$existing['remaining_quantity'] + $remaining;

            sqlStatement(
                "UPDATE pos_remaining_dispense
                 SET total_quantity = ?, dispensed_quantity = ?, administered_quantity = ?, remaining_quantity = ?,
                     receipt_number = ?, last_updated = NOW()
                 WHERE id = ?",
                [$newTotal, $newDisp, $newAdm, $newRem, $receipt, (int)$existing['id']]
            );
        } else {
            sqlStatement(
                "INSERT INTO pos_remaining_dispense
                    (pid, drug_id, lot_number, total_quantity, dispensed_quantity, administered_quantity, remaining_quantity,
                     receipt_number, created_date, last_updated)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [$pid, $drug_id, $lot, $qty, $disp, $adm, $remaining, $receipt, $createdDate]
            );
        }
    }
} catch (Exception $e) {
    jexit(500, 'Failed updating remaining dispense', $e->getMessage());
}

while (ob_get_level()) ob_end_clean();

echo json_encode([
    'success' => true,
    'receipt_number' => $receipt
]);
exit;
