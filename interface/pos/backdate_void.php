<?php
/**
 * Reverse a saved backdated POS transaction.
 *
 * This is intentionally separate from the normal same-day POS void flow.
 * It only allows reversing receipts created by backdate_save.php (BD-...),
 * and it restores inventory / dispense tracking / administer tracking together.
 */

require_once(__DIR__ . "/../globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

header('Content-Type: application/json');

if (!isset($_SESSION['authUserID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => xlt('Not authorized')]);
    exit;
}

if (!AclMain::aclCheckCore('acct', 'rep_a') && !AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => xlt('Access denied')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => xlt('Invalid request method')]);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) {
    $input = $_POST;
}

if (!CsrfUtils::verifyCsrfToken($input['csrf_token_form'] ?? $input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => xlt('CSRF token verification failed')]);
    exit;
}

function backdateVoidJsonResponse($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function sqlInsertId()
{
    global $pdo;

    if (isset($pdo) && $pdo) {
        return $pdo->lastInsertId();
    }

    return 0;
}

function backdateVoidNormalizeQuantity($value)
{
    return round((float) ($value ?? 0), 4);
}

function backdateVoidGetQuantityColumn()
{
    static $column = null;

    if ($column !== null) {
        return $column;
    }

    $columns = [];
    $result = sqlStatement("SHOW COLUMNS FROM drug_inventory");
    while ($row = sqlFetchArray($result)) {
        $columns[] = $row['Field'];
    }

    foreach (['on_hand', 'quantity', 'qty', 'stock'] as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $column = $candidate;
            return $column;
        }
    }

    $column = 'on_hand';
    return $column;
}

function backdateVoidHasColumn($table, $column)
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', (string) $table);
    $row = sqlFetchArray(sqlStatement("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column]));
    return !empty($row);
}

function backdateVoidHasIndex($table, $indexName)
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', (string) $table);
    $row = sqlFetchArray(sqlStatement("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]));
    return !empty($row);
}

function backdateVoidEnsureTransactionSchema()
{
    if (!backdateVoidHasColumn('pos_transactions', 'facility_id')) {
        sqlStatement("ALTER TABLE `pos_transactions` ADD COLUMN `facility_id` INT NULL");
    }
    if (!backdateVoidHasIndex('pos_transactions', 'facility_id_idx')) {
        sqlStatement("ALTER TABLE `pos_transactions` ADD INDEX `facility_id_idx` (`facility_id`)");
    }
    if (!backdateVoidHasColumn('pos_transactions', 'voided')) {
        sqlStatement("ALTER TABLE `pos_transactions` ADD COLUMN `voided` TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!backdateVoidHasColumn('pos_transactions', 'voided_at')) {
        sqlStatement("ALTER TABLE `pos_transactions` ADD COLUMN `voided_at` DATETIME NULL");
    }
    if (!backdateVoidHasColumn('pos_transactions', 'voided_by')) {
        sqlStatement("ALTER TABLE `pos_transactions` ADD COLUMN `voided_by` VARCHAR(255) NULL");
    }
    if (!backdateVoidHasColumn('pos_transactions', 'void_reason')) {
        sqlStatement("ALTER TABLE `pos_transactions` ADD COLUMN `void_reason` TEXT NULL");
    }
    if (!backdateVoidHasColumn('pos_transactions', 'void_reversal_transaction_id')) {
        sqlStatement("ALTER TABLE `pos_transactions` ADD COLUMN `void_reversal_transaction_id` INT NULL");
    }
    if (!backdateVoidHasColumn('pos_transactions', 'original_transaction_id')) {
        sqlStatement("ALTER TABLE `pos_transactions` ADD COLUMN `original_transaction_id` INT NULL");
    }
}

function backdateVoidEnsureAuditSchema()
{
    sqlStatement(
        "CREATE TABLE IF NOT EXISTS `pos_transaction_void_audit` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `original_transaction_id` INT NOT NULL,
            `reversal_transaction_id` INT NULL,
            `pid` INT NOT NULL,
            `receipt_number` VARCHAR(100) NOT NULL,
            `void_reason` TEXT NOT NULL,
            `voided_by` VARCHAR(255) NOT NULL,
            `voided_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `orig_tx` (`original_transaction_id`),
            KEY `pid` (`pid`),
            KEY `receipt_number` (`receipt_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function backdateVoidEnsureRemainingDispenseSchema()
{
    sqlStatement(
        "CREATE TABLE IF NOT EXISTS `pos_remaining_dispense` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `pid` int(11) NOT NULL,
            `drug_id` int(11) NOT NULL,
            `lot_number` varchar(50) NOT NULL,
            `total_quantity` decimal(12,4) NOT NULL DEFAULT 0,
            `dispensed_quantity` decimal(12,4) NOT NULL DEFAULT 0,
            `administered_quantity` decimal(12,4) NOT NULL DEFAULT 0,
            `remaining_quantity` decimal(12,4) NOT NULL DEFAULT 0,
            `receipt_number` varchar(50) NOT NULL,
            `created_date` datetime NOT NULL,
            `last_updated` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `pid` (`pid`),
            KEY `drug_id` (`drug_id`),
            KEY `receipt_number` (`receipt_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function backdateVoidEnsureDailyAdministerSchema()
{
    sqlStatement(
        "CREATE TABLE IF NOT EXISTS `daily_administer_tracking` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `pid` int(11) NOT NULL,
            `drug_id` int(11) NOT NULL,
            `lot_number` varchar(50) NOT NULL,
            `administer_date` date NOT NULL,
            `total_administered` decimal(12,4) NOT NULL DEFAULT 0,
            `created_date` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_date` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_daily_administer` (`pid`, `drug_id`, `administer_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function backdateVoidGetDrugRow($drugId)
{
    static $cache = [];

    $drugId = (int) $drugId;
    if ($drugId <= 0) {
        return [];
    }
    if (!isset($cache[$drugId])) {
        $cache[$drugId] = sqlFetchArray(sqlStatement("SELECT * FROM drugs WHERE drug_id = ?", [$drugId])) ?: [];
    }

    return $cache[$drugId];
}

function backdateVoidIsLiquidInventoryDrug($drug)
{
    $form = strtolower(trim((string) ($drug['form'] ?? '')));
    $size = trim((string) ($drug['size'] ?? ''));
    $unit = trim((string) ($drug['unit'] ?? ''));
    $route = trim((string) ($drug['route'] ?? ''));

    if ($form === 'ml') {
        return true;
    }

    $mlPerVial = null;
    if (preg_match('/-?\d+(?:\.\d+)?/', $size, $sizeMatches)) {
        $mlPerVial = (float) $sizeMatches[0];
    }

    $mgPerMl = null;
    if (
        preg_match(
            '/(-?\d+(?:\.\d+)?)\s*mg\s*\/\s*(\d+(?:\.\d+)?)?\s*(ml|mL|cc)/i',
            $unit,
            $unitMatches
        )
    ) {
        $mgAmount = (float) $unitMatches[1];
        $volumeAmount = empty($unitMatches[2]) ? 1.0 : (float) $unitMatches[2];
        if ($volumeAmount > 0) {
            $mgPerMl = $mgAmount / $volumeAmount;
        }
    }

    return (
        (
            strpos($form, 'vial') !== false ||
            strpos($form, 'inject') !== false ||
            stripos($size, 'ml') !== false ||
            stripos($unit, '/ml') !== false ||
            stripos($unit, 'cc') !== false ||
            stripos($route, 'intramuscular') !== false
        ) &&
        $mlPerVial !== null && $mlPerVial > 0 &&
        $mgPerMl !== null && $mgPerMl > 0
    );
}

function backdateVoidAnalyzeItem($item)
{
    $drugId = (int) ($item['drug_id'] ?? 0);
    $quantity = backdateVoidNormalizeQuantity($item['quantity'] ?? 0);
    $dispenseQuantity = backdateVoidNormalizeQuantity($item['dispense_quantity'] ?? ($item['quantity'] ?? 0));
    $administerQuantity = backdateVoidNormalizeQuantity($item['administer_quantity'] ?? 0);
    $remainingQuantity = max(0, $quantity - $dispenseQuantity - $administerQuantity);
    $inventoryRestore = $dispenseQuantity + $administerQuantity;

    return [
        'drug_id' => $drugId,
        'lot_number' => (string) ($item['lot_number'] ?? ''),
        'quantity' => $quantity,
        'dispense_quantity' => $dispenseQuantity,
        'administer_quantity' => $administerQuantity,
        'remaining_quantity' => backdateVoidNormalizeQuantity($remainingQuantity),
        'inventory_restore' => backdateVoidNormalizeQuantity($inventoryRestore),
    ];
}

function backdateVoidRestoreDrugInventory($drugId, $lotNumber, $quantity)
{
    $quantity = backdateVoidNormalizeQuantity($quantity);
    if ($drugId <= 0 || $lotNumber === '' || $quantity <= 0) {
        return;
    }

    $quantityColumn = backdateVoidGetQuantityColumn();
    $hasDestroyDate = backdateVoidHasColumn('drug_inventory', 'destroy_date');
    $activeLotFilter = $hasDestroyDate
        ? " AND (destroy_date IS NULL OR destroy_date = '0000-00-00')"
        : "";
    $existing = sqlFetchArray(sqlStatement(
        "SELECT * FROM drug_inventory WHERE drug_id = ? AND lot_number = ?" . $activeLotFilter . " ORDER BY inventory_id DESC LIMIT 1",
        [$drugId, $lotNumber]
    ));

    if ($existing) {
        $newQuantity = backdateVoidNormalizeQuantity(($existing[$quantityColumn] ?? 0) + $quantity);
        if ($hasDestroyDate) {
            sqlStatement(
                "UPDATE drug_inventory SET `$quantityColumn` = ?, destroy_date = NULL WHERE inventory_id = ?",
                [$newQuantity, $existing['inventory_id']]
            );
        } else {
            sqlStatement(
                "UPDATE drug_inventory SET `$quantityColumn` = ? WHERE inventory_id = ?",
                [$newQuantity, $existing['inventory_id']]
            );
        }
        return;
    }

    $template = sqlFetchArray(sqlStatement(
        "SELECT * FROM drug_inventory WHERE drug_id = ? ORDER BY inventory_id DESC LIMIT 1",
        [$drugId]
    ));
    $columns = [];
    $result = sqlStatement("SHOW COLUMNS FROM drug_inventory");
    while ($row = sqlFetchArray($result)) {
        $columns[] = $row;
    }

    $insertColumns = [];
    $insertValues = [];
    $placeholders = [];

    foreach ($columns as $column) {
        $field = $column['Field'];
        if (stripos((string) $column['Extra'], 'auto_increment') !== false) {
            continue;
        }

        if ($field === 'drug_id') {
            $value = $drugId;
        } elseif ($field === 'lot_number') {
            $value = $lotNumber;
        } elseif ($field === $quantityColumn) {
            $value = $quantity;
        } elseif ($field === 'destroy_date') {
            $value = null;
        } elseif ($template && array_key_exists($field, $template)) {
            $value = $template[$field];
        } elseif ($column['Default'] !== null) {
            $value = $column['Default'];
        } elseif ($column['Null'] === 'YES') {
            $value = null;
        } elseif (preg_match('/int|decimal|float|double/i', (string) $column['Type'])) {
            $value = 0;
        } else {
            $value = '';
        }

        $insertColumns[] = "`$field`";
        $insertValues[] = $value;
        $placeholders[] = '?';
    }

    sqlStatement(
        "INSERT INTO drug_inventory (" . implode(', ', $insertColumns) . ") VALUES (" . implode(', ', $placeholders) . ")",
        $insertValues
    );
}

function backdateVoidReverseRemainingDispense($pid, $drugId, $lotNumber, $receiptNumber, $quantity, $dispenseQuantity, $administerQuantity, $remainingQuantity)
{
    $row = sqlFetchArray(sqlStatement(
        "SELECT id, total_quantity, dispensed_quantity, administered_quantity, remaining_quantity, receipt_number
           FROM pos_remaining_dispense
          WHERE pid = ?
            AND drug_id = ?
            AND lot_number = ?
          ORDER BY last_updated DESC, id DESC
          LIMIT 1",
        [$pid, $drugId, $lotNumber]
    ));

    if (!$row) {
        return;
    }

    $newTotal = backdateVoidNormalizeQuantity(($row['total_quantity'] ?? 0) - $quantity);
    $newDispensed = backdateVoidNormalizeQuantity(($row['dispensed_quantity'] ?? 0) - $dispenseQuantity);
    $newAdministered = backdateVoidNormalizeQuantity(($row['administered_quantity'] ?? 0) - $administerQuantity);
    $newRemaining = backdateVoidNormalizeQuantity(($row['remaining_quantity'] ?? 0) - $remainingQuantity);

    $newTotal = max(0, $newTotal);
    $newDispensed = max(0, $newDispensed);
    $newAdministered = max(0, $newAdministered);
    $newRemaining = max(0, $newRemaining);

    if ($newTotal <= 0 && $newDispensed <= 0 && $newAdministered <= 0 && $newRemaining <= 0) {
        sqlStatement("DELETE FROM pos_remaining_dispense WHERE id = ?", [$row['id']]);
        return;
    }

    $updatedReceiptNumber = ((string) ($row['receipt_number'] ?? '')) === $receiptNumber ? '' : (string) ($row['receipt_number'] ?? '');
    sqlStatement(
        "UPDATE pos_remaining_dispense
            SET total_quantity = ?,
                dispensed_quantity = ?,
                administered_quantity = ?,
                remaining_quantity = ?,
                receipt_number = ?,
                last_updated = NOW()
          WHERE id = ?",
        [$newTotal, $newDispensed, $newAdministered, $newRemaining, $updatedReceiptNumber, $row['id']]
    );
}

function backdateVoidHasLaterOverlappingTransactions($pid, $transactionId, $createdDate, $items)
{
    $drugIds = [];
    foreach ((array) $items as $item) {
        $drugId = (int) ($item['drug_id'] ?? 0);
        if ($drugId > 0) {
            $drugIds[$drugId] = true;
        }
    }

    if (empty($drugIds)) {
        return false;
    }

    $rows = sqlStatement(
        "SELECT id, items
           FROM pos_transactions
          WHERE pid = ?
            AND id != ?
            AND created_date > ?
            AND transaction_type != 'void'
            AND COALESCE(voided, 0) = 0
          ORDER BY created_date ASC",
        [$pid, $transactionId, $createdDate]
    );

    while ($row = sqlFetchArray($rows)) {
        $otherItems = json_decode($row['items'] ?? '[]', true);
        if (!is_array($otherItems)) {
            continue;
        }
        foreach ($otherItems as $otherItem) {
            $drugId = (int) ($otherItem['drug_id'] ?? 0);
            if ($drugId > 0 && isset($drugIds[$drugId])) {
                return true;
            }
        }
    }

    return false;
}

function backdateVoidInsertReversalTransaction($original, $reason, $voidedBy, $timestamp)
{
    $receiptNumber = 'UNDO-' . preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($original['receipt_number'] ?? 'BD')) . '-' . date('His');
    $columns = ['pid', 'receipt_number', 'transaction_type', 'amount', 'items', 'created_date', 'user_id'];
    $params = [(int) $original['pid'], $receiptNumber, 'void', -1 * abs((float) ($original['amount'] ?? 0)), $original['items'] ?? '[]', $timestamp, $voidedBy];

    foreach (['payment_method', 'visit_type', 'price_override_notes', 'patient_number', 'facility_id'] as $optionalColumn) {
        if (backdateVoidHasColumn('pos_transactions', $optionalColumn)) {
            $columns[] = $optionalColumn;
            $params[] = $optionalColumn === 'price_override_notes'
                ? 'UNDO of backdated transaction #' . (int) $original['id'] . ': ' . $reason
                : ($original[$optionalColumn] ?? null);
        }
    }

    $columns[] = 'original_transaction_id';
    $params[] = (int) $original['id'];

    $sql = "INSERT INTO pos_transactions (" . implode(', ', $columns) . ") VALUES (" .
        implode(', ', array_fill(0, count($columns), '?')) . ")";
    sqlStatement($sql, $params);

    return [
        'id' => sqlInsertId(),
        'receipt_number' => $receiptNumber,
    ];
}

$receiptNumber = trim((string) ($input['receipt_number'] ?? ''));
$pid = (int) ($input['pid'] ?? 0);
$reason = trim((string) ($input['reason'] ?? ''));

if ($receiptNumber === '' || $pid <= 0 || $reason === '') {
    backdateVoidJsonResponse(['success' => false, 'error' => xlt('Receipt number, patient ID, and reason are required')], 400);
}

if (strpos($receiptNumber, 'BD-') !== 0) {
    backdateVoidJsonResponse(['success' => false, 'error' => xlt('Only backdated receipts can be reversed from this screen')], 400);
}

if (strlen($reason) < 3) {
    backdateVoidJsonResponse(['success' => false, 'error' => xlt('Please enter a more detailed undo reason')], 400);
}

try {
    backdateVoidEnsureTransactionSchema();
    backdateVoidEnsureAuditSchema();
    backdateVoidEnsureRemainingDispenseSchema();
    $original = sqlFetchArray(sqlStatement(
        "SELECT * FROM pos_transactions
          WHERE pid = ?
            AND receipt_number = ?
            AND transaction_type != 'void'
          ORDER BY id DESC
          LIMIT 1",
        [$pid, $receiptNumber]
    ));

    if (!$original) {
        backdateVoidJsonResponse(['success' => false, 'error' => xlt('Backdated transaction not found')], 404);
    }

    if (!empty($original['voided'])) {
        backdateVoidJsonResponse(['success' => false, 'error' => xlt('This backdated transaction has already been reversed')], 409);
    }

    $createdDate = (string) ($original['created_date'] ?? '');
    $items = json_decode($original['items'] ?? '[]', true);
    if (!is_array($items) || empty($items)) {
        backdateVoidJsonResponse(['success' => false, 'error' => xlt('This backdated transaction has no item data to reverse')], 400);
    }

    if (backdateVoidHasLaterOverlappingTransactions($pid, (int) $original['id'], $createdDate, $items)) {
        backdateVoidJsonResponse([
            'success' => false,
            'error' => xlt('A later transaction already uses one of these products. Reverse it manually after reviewing inventory.'),
        ], 409);
    }

    $timestamp = date('Y-m-d H:i:s');
    $voidedBy = (string) ($_SESSION['authUser'] ?? $_SESSION['authUserID'] ?? 'system');

    sqlStatement("START TRANSACTION");

    foreach ($items as $item) {
        $analysis = backdateVoidAnalyzeItem($item);
        if ($analysis['drug_id'] <= 0) {
            continue;
        }

        backdateVoidReverseRemainingDispense(
            $pid,
            $analysis['drug_id'],
            $analysis['lot_number'],
            (string) ($original['receipt_number'] ?? ''),
            $analysis['quantity'],
            $analysis['dispense_quantity'],
            $analysis['administer_quantity'],
            $analysis['remaining_quantity']
        );
        // Older backdated entries deducted inventory. Newer ones are historical-only and store amount 0.
        if ((float) ($original['amount'] ?? 0) > 0) {
            backdateVoidRestoreDrugInventory($analysis['drug_id'], $analysis['lot_number'], $analysis['inventory_restore']);
        }
    }

    $reversal = backdateVoidInsertReversalTransaction($original, $reason, $voidedBy, $timestamp);

    sqlStatement(
        "UPDATE pos_transactions
            SET voided = 1,
                voided_at = ?,
                voided_by = ?,
                void_reason = ?,
                void_reversal_transaction_id = ?
          WHERE id = ?",
        [$timestamp, $voidedBy, $reason, $reversal['id'], $original['id']]
    );

    sqlStatement(
        "INSERT INTO pos_transaction_void_audit
            (original_transaction_id, reversal_transaction_id, pid, receipt_number, void_reason, voided_by, voided_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        [$original['id'], $reversal['id'], $pid, $original['receipt_number'], $reason, $voidedBy, $timestamp]
    );

    sqlStatement("COMMIT");

    backdateVoidJsonResponse([
        'success' => true,
        'message' => xlt('Backdated transaction reversed successfully'),
        'reversal_receipt_number' => $reversal['receipt_number'],
    ]);
} catch (Throwable $e) {
    sqlStatement("ROLLBACK");
    error_log("Backdate void failed: " . $e->getMessage());
    backdateVoidJsonResponse(['success' => false, 'error' => xlt('Failed to reverse backdated transaction') . ': ' . $e->getMessage()], 500);
}
