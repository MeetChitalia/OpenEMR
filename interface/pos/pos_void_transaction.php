<?php
/**
 * POS Void Transaction Handler
 *
 * Same-day voids only. Reverses POS-side inventory and tracking, marks the
 * original transaction as voided, and records an audit trail.
 *
 * @package OpenEMR
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

if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => xlt('CSRF token verification failed')]);
    exit;
}

function posVoidJsonResponse($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function posVoidSelectedFacilityId(): int
{
    return (int) ($_SESSION['facilityId'] ?? 0);
}

function sqlInsertId()
{
    global $pdo;

    if (isset($pdo) && $pdo) {
        return $pdo->lastInsertId();
    }

    return 0;
}

function posVoidNormalizeQuantity($value)
{
    return round((float) ($value ?? 0), 4);
}

function posVoidGetQuantityColumn()
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

function posVoidHasColumn($table, $column)
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', (string) $table);
    $row = sqlFetchArray(sqlStatement("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column]));
    return !empty($row);
}

function posVoidHasIndex($table, $indexName)
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', (string) $table);
    $row = sqlFetchArray(sqlStatement("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]));
    return !empty($row);
}

function posVoidEnsureTransactionSchema()
{
    if (!posVoidHasColumn('pos_transactions', 'facility_id')) {
        sqlStatement("ALTER TABLE `pos_transactions` ADD COLUMN `facility_id` INT NULL");
    }
    if (!posVoidHasIndex('pos_transactions', 'facility_id_idx')) {
        sqlStatement("ALTER TABLE `pos_transactions` ADD INDEX `facility_id_idx` (`facility_id`)");
    }
    if (!posVoidHasColumn('pos_transactions', 'voided')) {
        sqlStatement("ALTER TABLE `pos_transactions` ADD COLUMN `voided` TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!posVoidHasColumn('pos_transactions', 'voided_at')) {
        sqlStatement("ALTER TABLE `pos_transactions` ADD COLUMN `voided_at` DATETIME NULL");
    }
    if (!posVoidHasColumn('pos_transactions', 'voided_by')) {
        sqlStatement("ALTER TABLE `pos_transactions` ADD COLUMN `voided_by` VARCHAR(255) NULL");
    }
    if (!posVoidHasColumn('pos_transactions', 'void_reason')) {
        sqlStatement("ALTER TABLE `pos_transactions` ADD COLUMN `void_reason` TEXT NULL");
    }
    if (!posVoidHasColumn('pos_transactions', 'void_reversal_transaction_id')) {
        sqlStatement("ALTER TABLE `pos_transactions` ADD COLUMN `void_reversal_transaction_id` INT NULL");
    }
    if (!posVoidHasColumn('pos_transactions', 'original_transaction_id')) {
        sqlStatement("ALTER TABLE `pos_transactions` ADD COLUMN `original_transaction_id` INT NULL");
    }
}

function posVoidEnsureAuditSchema()
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

function posVoidEnsureRemainingDispenseSchema()
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

function posVoidEnsureDailyAdministerSchema()
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

function posVoidGetDrugRow($drugId)
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

function posVoidIsLiquidInventoryDrug($drug)
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

function posVoidShouldDeductDispenseFromInventory($drugId)
{
    return !posVoidIsLiquidInventoryDrug(posVoidGetDrugRow($drugId));
}

function posVoidGetAdministerInventoryDeductionQuantity($item, $administeredQuantity)
{
    $administeredQuantity = posVoidNormalizeQuantity($administeredQuantity);
    if ($administeredQuantity <= 0) {
        return 0.0;
    }

    $doseMg = posVoidNormalizeQuantity($item['dose_option_mg'] ?? ($item['semaglutide_dose_mg'] ?? 0));
    if ($doseMg > 0) {
        return posVoidNormalizeQuantity($doseMg * $administeredQuantity);
    }

    return $administeredQuantity;
}

function posVoidIsPhentermineItem($item)
{
    $itemName = strtolower(trim((string) ($item['name'] ?? ($item['display_name'] ?? ''))));
    return in_array($itemName, ['phentermine #28', 'phentermine #14'], true);
}

function posVoidAnalyzeItem($item)
{
    $drugId = (int) ($item['drug_id'] ?? 0);
    $quantity = posVoidNormalizeQuantity($item['quantity'] ?? 0);
    $dispenseQuantity = posVoidNormalizeQuantity($item['dispense_quantity'] ?? ($item['quantity'] ?? 0));
    $administerQuantity = posVoidNormalizeQuantity($item['administer_quantity'] ?? 0);
    $marketplaceDispenseQuantity = posVoidNormalizeQuantity($item['marketplace_dispense_quantity'] ?? 0);
    $isMarketplaceDispense = $marketplaceDispenseQuantity > 0 && !posVoidIsPhentermineItem($item);
    $totalRemainingQuantity = posVoidNormalizeQuantity($item['total_remaining_quantity'] ?? 0);
    $remainingItems = is_array($item['remaining_dispense_items'] ?? null) ? $item['remaining_dispense_items'] : [];
    $hasRemainingDispense = !empty($item['has_remaining_dispense']) && !empty($remainingItems) && $totalRemainingQuantity > 0;

    $newInventoryToUse = $isMarketplaceDispense ? min($quantity, $marketplaceDispenseQuantity) : min($quantity, $dispenseQuantity);
    $remainingDispenseToUse = 0.0;
    $administerFromRemaining = 0.0;
    $administerFromInventory = $administerQuantity;

    if ($hasRemainingDispense) {
        $dispenseQtyForTracking = $isMarketplaceDispense ? $marketplaceDispenseQuantity : $dispenseQuantity;
        $newInventoryToUse = min($quantity, $dispenseQtyForTracking);
        $remainingDispenseToUse = max(0, $dispenseQtyForTracking - $newInventoryToUse);
        $remainingAfterDispense = max(0, $totalRemainingQuantity - $remainingDispenseToUse);
        $administerFromRemaining = min($administerQuantity, $remainingAfterDispense);
        $administerFromInventory = max(0, $administerQuantity - $administerFromRemaining);
    }

    if (!posVoidShouldDeductDispenseFromInventory($drugId)) {
        $inventoryRestore = posVoidGetAdministerInventoryDeductionQuantity($item, $administerFromInventory);
    } elseif ($isMarketplaceDispense) {
        $inventoryRestore = posVoidGetAdministerInventoryDeductionQuantity($item, $administerFromInventory);
    } else {
        $inventoryRestore = $newInventoryToUse + posVoidGetAdministerInventoryDeductionQuantity($item, $administerFromInventory);
    }

    return [
        'drug_id' => $drugId,
        'lot_number' => (string) ($item['lot_number'] ?? ''),
        'quantity' => $quantity,
        'administer_quantity' => $administerQuantity,
        'inventory_restore' => posVoidNormalizeQuantity($inventoryRestore),
        'remaining_dispense_restore' => posVoidNormalizeQuantity($remainingDispenseToUse),
        'remaining_administer_restore' => posVoidNormalizeQuantity($administerFromRemaining),
        'remaining_sources' => $remainingItems,
    ];
}

function posVoidRestoreDrugInventory($drugId, $lotNumber, $quantity)
{
    $quantity = posVoidNormalizeQuantity($quantity);
    if ($drugId <= 0 || $lotNumber === '' || $quantity <= 0) {
        return;
    }

    $quantityColumn = posVoidGetQuantityColumn();
    $hasDestroyDate = posVoidHasColumn('drug_inventory', 'destroy_date');
    $activeLotFilter = $hasDestroyDate
        ? " AND (destroy_date IS NULL OR destroy_date = '0000-00-00')"
        : "";
    $existing = sqlFetchArray(sqlStatement(
        "SELECT * FROM drug_inventory WHERE drug_id = ? AND lot_number = ?" . $activeLotFilter . " ORDER BY inventory_id DESC LIMIT 1",
        [$drugId, $lotNumber]
    ));

    if ($existing) {
        $newQuantity = posVoidNormalizeQuantity(($existing[$quantityColumn] ?? 0) + $quantity);
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

function posVoidRestoreRemainingDispenseUsage($pid, $drugId, $receiptNumber, $restoreQuantity, $usageColumn, $sources, $createdDate)
{
    $restoreQuantity = posVoidNormalizeQuantity($restoreQuantity);
    if ($pid <= 0 || $drugId <= 0 || $restoreQuantity <= 0) {
        return;
    }

    if (!in_array($usageColumn, ['dispensed_quantity', 'administered_quantity'], true)) {
        return;
    }

    $restored = 0.0;
    $sourceLots = [];
    foreach ((array) $sources as $source) {
        $lot = trim((string) ($source['lot_number'] ?? ''));
        if ($lot !== '') {
            $sourceLots[] = $lot;
        }
    }
    $sourceLots = array_values(array_unique($sourceLots));

    foreach ($sourceLots as $lotNumber) {
        if ($restored >= $restoreQuantity) {
            break;
        }

        $rows = sqlStatement(
            "SELECT id, total_quantity, remaining_quantity, {$usageColumn} AS usage_quantity
               FROM pos_remaining_dispense
              WHERE pid = ?
                AND drug_id = ?
                AND lot_number = ?
                AND receipt_number != ?
                AND created_date <= ?
              ORDER BY created_date ASC, id ASC",
            [$pid, $drugId, $lotNumber, $receiptNumber, $createdDate]
        );

        while (($row = sqlFetchArray($rows)) && $restored < $restoreQuantity) {
            $usageQuantity = posVoidNormalizeQuantity($row['usage_quantity'] ?? 0);
            $remainingCapacity = max(0, posVoidNormalizeQuantity($row['total_quantity']) - posVoidNormalizeQuantity($row['remaining_quantity']));
            $capacity = min($usageQuantity, $remainingCapacity);
            if ($capacity <= 0 || $usageQuantity <= 0) {
                continue;
            }

            $increment = min($capacity, $restoreQuantity - $restored);
            sqlStatement(
                "UPDATE pos_remaining_dispense
                    SET remaining_quantity = ?,
                        {$usageColumn} = ?,
                        last_updated = NOW()
                  WHERE id = ?",
                [
                    posVoidNormalizeQuantity($row['remaining_quantity'] + $increment),
                    posVoidNormalizeQuantity($usageQuantity - $increment),
                    $row['id']
                ]
            );
            $restored += $increment;
        }
    }

    if ($restored < $restoreQuantity) {
        $rows = sqlStatement(
            "SELECT id, total_quantity, remaining_quantity, {$usageColumn} AS usage_quantity
               FROM pos_remaining_dispense
              WHERE pid = ?
                AND drug_id = ?
                AND receipt_number != ?
                AND created_date <= ?
              ORDER BY created_date ASC, id ASC",
            [$pid, $drugId, $receiptNumber, $createdDate]
        );

        while (($row = sqlFetchArray($rows)) && $restored < $restoreQuantity) {
            $usageQuantity = posVoidNormalizeQuantity($row['usage_quantity'] ?? 0);
            $remainingCapacity = max(0, posVoidNormalizeQuantity($row['total_quantity']) - posVoidNormalizeQuantity($row['remaining_quantity']));
            $capacity = min($usageQuantity, $remainingCapacity);
            if ($capacity <= 0 || $usageQuantity <= 0) {
                continue;
            }

            $increment = min($capacity, $restoreQuantity - $restored);
            sqlStatement(
                "UPDATE pos_remaining_dispense
                    SET remaining_quantity = ?,
                        {$usageColumn} = ?,
                        last_updated = NOW()
                  WHERE id = ?",
                [
                    posVoidNormalizeQuantity($row['remaining_quantity'] + $increment),
                    posVoidNormalizeQuantity($usageQuantity - $increment),
                    $row['id']
                ]
            );
            $restored += $increment;
        }
    }
}

function posVoidReverseDailyAdminister($pid, $drugId, $lotNumber, $createdDate, $item)
{
    $administerQty = posVoidNormalizeQuantity($item['administer_quantity'] ?? 0);
    if ($pid <= 0 || $drugId <= 0 || $administerQty <= 0) {
        return;
    }

    $increment = posVoidShouldDeductDispenseFromInventory($drugId)
        ? $administerQty
        : 1.0;

    $administerDate = date('Y-m-d', strtotime((string) $createdDate));
    $row = sqlFetchArray(sqlStatement(
        "SELECT id, total_administered FROM daily_administer_tracking WHERE pid = ? AND drug_id = ? AND administer_date = ?",
        [$pid, $drugId, $administerDate]
    ));

    if (!$row) {
        return;
    }

    $newTotal = posVoidNormalizeQuantity(($row['total_administered'] ?? 0) - $increment);
    if ($newTotal <= 0) {
        sqlStatement("DELETE FROM daily_administer_tracking WHERE id = ?", [$row['id']]);
    } else {
        sqlStatement(
            "UPDATE daily_administer_tracking SET total_administered = ?, updated_date = NOW() WHERE id = ?",
            [$newTotal, $row['id']]
        );
    }
}

function posVoidHasLaterOverlappingTransactions($pid, $transactionId, $createdDate, $items)
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
            AND DATE(created_date) = CURDATE()
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

function posVoidInsertReversalTransaction($original, $reason, $voidedBy, $timestamp)
{
    $receiptNumber = 'VOID-' . preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($original['receipt_number'] ?? 'TX')) . '-' . date('His');
    $hasPaymentMethod = posVoidHasColumn('pos_transactions', 'payment_method');
    $hasVisitType = posVoidHasColumn('pos_transactions', 'visit_type');
    $hasPriceOverrideNotes = posVoidHasColumn('pos_transactions', 'price_override_notes');
    $hasPatientNumber = posVoidHasColumn('pos_transactions', 'patient_number');

    $columns = ['pid', 'receipt_number', 'transaction_type'];
    $params = [(int) $original['pid'], $receiptNumber, 'void'];

    if ($hasPaymentMethod) {
        $columns[] = 'payment_method';
        $params[] = $original['payment_method'] ?? '';
    }

    $columns[] = 'amount';
    $params[] = -1 * abs((float) ($original['amount'] ?? 0));

    $columns[] = 'items';
    $params[] = $original['items'] ?? '[]';

    $columns[] = 'created_date';
    $params[] = $timestamp;

    $columns[] = 'user_id';
    $params[] = $voidedBy;

    if ($hasVisitType) {
        $columns[] = 'visit_type';
        $params[] = $original['visit_type'] ?? '-';
    }

    if ($hasPriceOverrideNotes) {
        $columns[] = 'price_override_notes';
        $params[] = 'VOID of transaction #' . (int) $original['id'] . ': ' . $reason;
    }

    if ($hasPatientNumber) {
        $columns[] = 'patient_number';
        $params[] = $original['patient_number'] ?? null;
    }

    if (posVoidHasColumn('pos_transactions', 'facility_id')) {
        $columns[] = 'facility_id';
        $params[] = $original['facility_id'] ?? ((int) ($_SESSION['facilityId'] ?? 0) ?: null);
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

function posVoidLoadReceiptTransactions($pid, $receiptNumber, $includeVoided = false)
{
    $pid = (int) $pid;
    $receiptNumber = trim((string) $receiptNumber);

    if ($pid <= 0 || $receiptNumber === '') {
        return [];
    }

    $rows = [];
    $sql = "SELECT *
              FROM pos_transactions
             WHERE pid = ?
               AND receipt_number = ?
               AND transaction_type != 'void'";

    if (!$includeVoided) {
        $sql .= " AND COALESCE(voided, 0) = 0";
    }

    $sql .= " ORDER BY created_date ASC, id ASC";

    $result = sqlStatement($sql, [$pid, $receiptNumber]);

    while ($row = sqlFetchArray($result)) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function posVoidFilterActiveReceiptTransactions(array $transactions): array
{
    $activeRows = [];

    foreach ($transactions as $row) {
        if (!is_array($row)) {
            continue;
        }

        if ((string) ($row['transaction_type'] ?? '') === 'void') {
            continue;
        }

        if (!empty($row['voided'])) {
            continue;
        }

        $activeRows[] = $row;
    }

    return $activeRows;
}

function posVoidReceiptHasPriorVoidActivity(array $transactions): bool
{
    foreach ($transactions as $row) {
        if (!is_array($row)) {
            continue;
        }

        if (!empty($row['voided'])) {
            return true;
        }
    }

    return false;
}

function posVoidCanonicalReceiptItems(array $transactions): array
{
    foreach ($transactions as $row) {
        $items = json_decode($row['items'] ?? '[]', true);
        if (is_array($items) && !empty($items)) {
            return $items;
        }
    }

    return [];
}

function posVoidBuildReceiptAggregateOriginal(array $transactions, array $fallbackOriginal, array $canonicalItems)
{
    $aggregate = $fallbackOriginal;
    $totalAmount = 0.0;
    $paymentMethods = [];

    foreach ($transactions as $row) {
        $totalAmount += (float) ($row['amount'] ?? 0);
        $method = trim((string) ($row['payment_method'] ?? ''));
        if ($method !== '') {
            $paymentMethods[strtolower($method)] = $method;
        }
    }

    $aggregate['amount'] = round($totalAmount, 2);
    $aggregate['items'] = json_encode($canonicalItems, JSON_UNESCAPED_SLASHES);
    if ($aggregate['items'] === false) {
        $aggregate['items'] = $fallbackOriginal['items'] ?? '[]';
    }

    if (count($paymentMethods) > 1) {
        $aggregate['payment_method'] = 'split';
    } elseif (count($paymentMethods) === 1) {
        $aggregate['payment_method'] = reset($paymentMethods);
    }

    return $aggregate;
}

$transactionId = (int) ($_POST['transaction_id'] ?? 0);
$pid = (int) ($_POST['pid'] ?? 0);
$reason = trim((string) ($_POST['reason'] ?? ''));

if ($transactionId <= 0 || $pid <= 0 || $reason === '') {
    posVoidJsonResponse(['success' => false, 'error' => xlt('Transaction ID, patient ID, and reason are required')], 400);
}

if (strlen($reason) < 3) {
    posVoidJsonResponse(['success' => false, 'error' => xlt('Please enter a more detailed void reason')], 400);
}

try {
    posVoidEnsureTransactionSchema();
    posVoidEnsureAuditSchema();
    posVoidEnsureRemainingDispenseSchema();
    posVoidEnsureDailyAdministerSchema();

    $original = sqlFetchArray(sqlStatement(
        "SELECT * FROM pos_transactions WHERE id = ? AND pid = ? LIMIT 1",
        [$transactionId, $pid]
    ));

    if (!$original) {
        posVoidJsonResponse(['success' => false, 'error' => xlt('Transaction not found')], 404);
    }

    $selectedFacilityId = posVoidSelectedFacilityId();
    $originalFacilityId = (int) ($original['facility_id'] ?? 0);
    if ($selectedFacilityId > 0 && $originalFacilityId > 0 && $originalFacilityId !== $selectedFacilityId) {
        posVoidJsonResponse(['success' => false, 'error' => xlt('Transaction does not belong to the selected facility')], 403);
    }

    $ineligibleTypes = ['void', 'credit_for_remaining', 'transfer_in', 'transfer_out', 'medicine_switch'];
    if (in_array((string) ($original['transaction_type'] ?? ''), $ineligibleTypes, true)) {
        posVoidJsonResponse(['success' => false, 'error' => xlt('This transaction type cannot be voided')], 400);
    }

    $receiptNumber = trim((string) ($original['receipt_number'] ?? ''));
    $allReceiptTransactions = $receiptNumber !== ''
        ? posVoidLoadReceiptTransactions($pid, $receiptNumber, true)
        : [$original];
    $receiptTransactions = $receiptNumber !== ''
        ? posVoidFilterActiveReceiptTransactions($allReceiptTransactions)
        : (empty($original['voided']) ? [$original] : []);

    if (!empty($original['voided']) && empty($receiptTransactions)) {
        posVoidJsonResponse(['success' => false, 'error' => xlt('This transaction has already been voided')], 409);
    }

    $eligibilitySource = !empty($receiptTransactions) ? reset($receiptTransactions) : $original;
    $createdDate = (string) ($eligibilitySource['created_date'] ?? '');
    if ($createdDate === '' || date('Y-m-d', strtotime($createdDate)) !== date('Y-m-d')) {
        posVoidJsonResponse(['success' => false, 'error' => xlt('Only same-day transactions can be voided')], 400);
    }

    if (empty($receiptTransactions)) {
        posVoidJsonResponse(['success' => false, 'error' => xlt('This receipt has already been voided')], 409);
    }

    $hasPriorVoidActivity = $receiptNumber !== '' && posVoidReceiptHasPriorVoidActivity($allReceiptTransactions);
    $items = posVoidCanonicalReceiptItems($allReceiptTransactions);
    if (empty($items)) {
        $items = json_decode($original['items'] ?? '[]', true);
    }
    if (!$hasPriorVoidActivity && (!is_array($items) || empty($items))) {
        posVoidJsonResponse(['success' => false, 'error' => xlt('This transaction has no item data to reverse')], 400);
    }
    if (!is_array($items)) {
        $items = [];
    }

    $timestamp = date('Y-m-d H:i:s');
    $voidedBy = (string) ($_SESSION['authUser'] ?? $_SESSION['authUserID'] ?? 'system');

    sqlStatement("START TRANSACTION");

    if (!$hasPriorVoidActivity) {
        foreach ($items as $item) {
            $analysis = posVoidAnalyzeItem($item);
            if ($analysis['drug_id'] <= 0) {
                continue;
            }

            posVoidRestoreRemainingDispenseUsage(
                $pid,
                $analysis['drug_id'],
                $receiptNumber,
                $analysis['remaining_dispense_restore'],
                'dispensed_quantity',
                $analysis['remaining_sources'],
                $createdDate
            );

            posVoidRestoreRemainingDispenseUsage(
                $pid,
                $analysis['drug_id'],
                $receiptNumber,
                $analysis['remaining_administer_restore'],
                'administered_quantity',
                $analysis['remaining_sources'],
                $createdDate
            );

            sqlStatement(
                "DELETE FROM pos_remaining_dispense WHERE pid = ? AND receipt_number = ? AND drug_id = ? AND lot_number = ?",
                [$pid, $receiptNumber, $analysis['drug_id'], $analysis['lot_number']]
            );

            posVoidReverseDailyAdminister($pid, $analysis['drug_id'], $analysis['lot_number'], $createdDate, $item);
            posVoidRestoreDrugInventory($analysis['drug_id'], $analysis['lot_number'], $analysis['inventory_restore']);
        }
    }

    $aggregateItems = $hasPriorVoidActivity ? [] : $items;
    $aggregateOriginal = posVoidBuildReceiptAggregateOriginal($receiptTransactions, $original, $aggregateItems);
    $reversal = posVoidInsertReversalTransaction($aggregateOriginal, $reason, $voidedBy, $timestamp);

    foreach ($receiptTransactions as $transactionRow) {
        $currentTransactionId = (int) ($transactionRow['id'] ?? 0);
        if ($currentTransactionId <= 0) {
            continue;
        }

        sqlStatement(
            "UPDATE pos_transactions
                SET voided = 1,
                    voided_at = ?,
                    voided_by = ?,
                    void_reason = ?,
                    void_reversal_transaction_id = ?
              WHERE id = ?",
            [$timestamp, $voidedBy, $reason, $reversal['id'], $currentTransactionId]
        );

        sqlStatement(
            "INSERT INTO pos_transaction_void_audit
                (original_transaction_id, reversal_transaction_id, pid, receipt_number, void_reason, voided_by, voided_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$currentTransactionId, $reversal['id'], $pid, $receiptNumber, $reason, $voidedBy, $timestamp]
        );
    }

    if ($receiptNumber !== '') {
        sqlStatement(
            "DELETE FROM pos_receipts WHERE pid = ? AND receipt_number = ?",
            [$pid, $receiptNumber]
        );
    }

    sqlStatement("COMMIT");

    posVoidJsonResponse([
        'success' => true,
        'message' => xlt('Transaction voided successfully'),
        'reversal_receipt_number' => $reversal['receipt_number'],
    ]);
} catch (Throwable $e) {
    sqlStatement("ROLLBACK");
    error_log("POS void transaction failed: " . $e->getMessage());
    posVoidJsonResponse(['success' => false, 'error' => xlt('Failed to void transaction') . ': ' . $e->getMessage()], 500);
}
