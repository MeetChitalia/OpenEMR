<?php
/**
 * POS Payment Processor - Handles Stripe payments and inventory updates
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Team
 * @copyright Copyright (c) 2024 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Ensure clean output for JSON responses - be very aggressive
while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

// Debug: Log that payment processor is starting
error_log("Payment processor: Starting execution at " . date('Y-m-d H:i:s'));
error_log("Payment processor: Request method: " . $_SERVER['REQUEST_METHOD']);
error_log("Payment processor: Request URI: " . $_SERVER['REQUEST_URI']);

// Disable any output that might interfere with JSON
ini_set('html_errors', 0);
ini_set('implicit_flush', 0);
ini_set('zlib.output_compression', 0);

// Suppress any warnings or notices that might interfere with JSON output
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Function to clean any output and send JSON
function cleanOutputAndSendJson($data, $statusCode = 200) {
    // Clear ALL output buffers aggressively
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Disable all output buffering
    ini_set('output_buffering', 'off');
    ini_set('zlib.output_compression', 'off');
    
    // Set proper headers before any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        http_response_code($statusCode);
    }
    
    // Send JSON response directly
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    
    // Force output and exit
    if (ob_get_level()) {
        ob_end_flush();
    }
    flush();
    exit;
}

// Ensure Site ID is set for proper session handling BEFORE including globals.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['site_id']) || empty($_SESSION['site_id'])) {
    // Set default site ID if not set
    $_SESSION['site_id'] = 'default';
    error_log("Payment processor: Site ID was missing, set to default");
}

// Debug: Log session status
error_log("Payment processor: Session started, site_id: " . ($_SESSION['site_id'] ?? 'not set'));

// Skip globals.php inclusion to avoid session timeout issues
// We'll handle session and user lookup manually

// Include only the necessary files for payment processing
require_once(__DIR__ . "/../../library/payment.inc.php");

// Use OpenEMR's database configuration
require_once(__DIR__ . '/../../sites/default/sqlconf.php');

try {
    $pdo = new PDO(
        "mysql:host=" . $host . ";port=" . $port . ";dbname=" . $dbase . ";charset=utf8mb4",
        $login,
        $pass,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    error_log("Payment processor: Database connection successful");
} catch (PDOException $e) {
    error_log("Payment processor: Database connection failed: " . $e->getMessage());
    cleanOutputAndSendJson(['success' => false, 'error' => 'Database connection failed'], 500);
}

// This endpoint bypasses OpenEMR's normal bootstrap, so apply the configured
// global timezone manually before any transaction timestamps are generated.
try {
    $timezoneStmt = $pdo->prepare("SELECT gl_value FROM globals WHERE gl_name = ? ORDER BY gl_index LIMIT 1");
    $timezoneStmt->execute(['gbl_time_zone']);
    $timezoneValue = trim((string) $timezoneStmt->fetchColumn());
    if ($timezoneValue !== '') {
        date_default_timezone_set($timezoneValue);
    }

    $pdo->prepare("SET time_zone = ?")->execute([(new DateTime())->format("P")]);
    error_log("Payment processor: Active timezone is " . date_default_timezone_get());
} catch (Throwable $e) {
    error_log("Payment processor: Failed to synchronize timezone: " . $e->getMessage());
}

// Manual user lookup function
function getUserInfo($userId) {
    global $pdo;
    try {
        $query = "SELECT fname, lname, username FROM users WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting user info: " . $e->getMessage());
        return false;
    }
}

// Manual translation function
function xlt($text) {
    return $text; // Simple fallback for translation
}

// Database helper functions
function add_escape_custom($string) {
    global $pdo;
    return $pdo->quote($string);
}

function sqlStatement($query, $binds = false) {
    global $pdo;
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($binds ?: []);
        return $stmt;
    } catch (PDOException $e) {
        error_log("SQL Error: " . $e->getMessage());
        return false;
    }
}

function sqlFetchArray($result)
{
    if ($result instanceof PDOStatement) {
        $row = $result->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    if (is_array($result)) {
        return $result;
    }

    return null;
}

function sqlQuery($query, $binds = false)
{
    $result = sqlStatement($query, $binds);
    return $result ? sqlFetchArray($result) : false;
}

function posGetReceiptPaymentMethodLabel($transactionType): string
{
    $transactionType = trim((string) $transactionType);
    if ($transactionType === 'dispense_and_administer') {
        return 'DA';
    }

    return $transactionType;
}

function posGetReceiptPaymentSummary(int $pid, string $receiptNumber, string $fallbackTransactionType = '', string $fallbackPaymentMethod = ''): array
{
    $receiptNumber = trim($receiptNumber);
    $payments = [];

    if ($pid > 0 && $receiptNumber !== '') {
        $paymentRows = sqlStatement(
            "SELECT transaction_type, payment_method, amount, created_date
               FROM pos_transactions
              WHERE pid = ? AND receipt_number = ?
              ORDER BY created_date ASC, id ASC",
            [$pid, $receiptNumber]
        );

        while ($row = sqlFetchArray($paymentRows)) {
            $method = strtolower(trim((string) ($row['payment_method'] ?? '')));
            $amount = round((float) ($row['amount'] ?? 0), 2);

            if ($amount <= 0 || $method === '' || $method === 'n/a' || $method === 'unknown') {
                continue;
            }

            $payments[] = [
                'method' => $method,
                'amount' => $amount,
                'created_date' => (string) ($row['created_date'] ?? ''),
            ];
        }
    }

    $uniqueMethods = array_values(array_unique(array_column($payments, 'method')));
    if (count($uniqueMethods) > 1) {
        $displayMethod = 'split';
    } elseif (count($uniqueMethods) === 1) {
        $displayMethod = $uniqueMethods[0];
    } else {
        $fallbackPaymentMethod = strtolower(trim($fallbackPaymentMethod));
        if ($fallbackPaymentMethod !== '' && $fallbackPaymentMethod !== 'n/a' && $fallbackPaymentMethod !== 'unknown') {
            $displayMethod = $fallbackPaymentMethod;
        } else {
            $displayMethod = strtolower(trim(posGetReceiptPaymentMethodLabel($fallbackTransactionType)));
        }
    }

    return [
        'payment_method' => $displayMethod !== '' ? $displayMethod : 'unknown',
        'individual_payments' => $payments,
    ];
}

function sqlInsertId() {
    global $pdo;
    return $pdo->lastInsertId();
}

function posTableExists($tableName)
{
    static $cache = [];

    $tableName = preg_replace('/[^A-Za-z0-9_]/', '', (string) $tableName);
    if ($tableName === '') {
        return false;
    }

    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    try {
        $stmt = sqlStatement("SHOW TABLES LIKE ?", [$tableName]);
        $cache[$tableName] = (bool) ($stmt && $stmt->fetch(PDO::FETCH_NUM));
    } catch (Exception $e) {
        error_log("posTableExists - Could not check table {$tableName}: " . $e->getMessage());
        $cache[$tableName] = false;
    }

    return $cache[$tableName];
}

function posIndexExists($tableName, $indexName): bool
{
    static $cache = [];

    $tableName = preg_replace('/[^A-Za-z0-9_]/', '', (string) $tableName);
    $indexName = preg_replace('/[^A-Za-z0-9_]/', '', (string) $indexName);
    $cacheKey = $tableName . '.' . $indexName;

    if ($tableName === '' || $indexName === '') {
        return false;
    }

    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = sqlStatement(
            "SELECT 1
               FROM information_schema.statistics
              WHERE table_schema = DATABASE()
                AND table_name = ?
                AND index_name = ?
              LIMIT 1",
            [$tableName, $indexName]
        );
        $cache[$cacheKey] = (bool) ($stmt && $stmt->fetch(PDO::FETCH_NUM));
    } catch (Exception $e) {
        error_log("posIndexExists - Could not check index {$indexName} on {$tableName}: " . $e->getMessage());
        $cache[$cacheKey] = false;
    }

    return $cache[$cacheKey];
}

function posColumnExists($tableName, $columnName): bool
{
    static $cache = [];

    $tableName = preg_replace('/[^A-Za-z0-9_]/', '', (string) $tableName);
    $columnName = preg_replace('/[^A-Za-z0-9_]/', '', (string) $columnName);
    $cacheKey = $tableName . '.' . $columnName;

    if ($tableName === '' || $columnName === '') {
        return false;
    }

    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = sqlStatement("SHOW COLUMNS FROM `{$tableName}` LIKE ?", [$columnName]);
        $cache[$cacheKey] = (bool) ($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        error_log("posColumnExists - Could not check column {$columnName} on {$tableName}: " . $e->getMessage());
        $cache[$cacheKey] = false;
    }

    return $cache[$cacheKey];
}

function posGetDayDateRange($date): array
{
    $day = substr((string) $date, 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        $day = date('Y-m-d');
    }

    return [
        $day . ' 00:00:00',
        date('Y-m-d H:i:s', strtotime($day . ' +1 day')),
    ];
}

function posGetTransactionCreatedDateRangeClause(string $column = 'created_date'): string
{
    return " AND {$column} >= ? AND {$column} < ? ";
}

function posResolveDrugInventoryQuantityColumn(): string
{
    static $quantityColumn = null;
    if ($quantityColumn !== null) {
        return $quantityColumn;
    }

    $quantityColumn = 'quantity';
    try {
        $columnsResult = sqlStatement("SHOW COLUMNS FROM drug_inventory");
        $columns = [];
        while ($col = sqlFetchArray($columnsResult)) {
            $columns[] = $col['Field'];
        }

        if (!in_array('quantity', $columns, true)) {
            if (in_array('qty', $columns, true)) {
                $quantityColumn = 'qty';
            } elseif (in_array('stock', $columns, true)) {
                $quantityColumn = 'stock';
            } elseif (in_array('on_hand', $columns, true)) {
                $quantityColumn = 'on_hand';
            }
        }
    } catch (Exception $e) {
        error_log("posResolveDrugInventoryQuantityColumn - Failed to inspect drug_inventory: " . $e->getMessage());
        $quantityColumn = 'on_hand';
    }

    return $quantityColumn;
}

function posFindActiveInventoryRow($drugId, $preferredLotNumber = null, $requiredQty = 0)
{
    $drugId = (int) $drugId;
    if ($drugId <= 0) {
        return null;
    }

    $quantityColumn = posResolveDrugInventoryQuantityColumn();
    $requiredQty = (float) $requiredQty;
    $preferredLotNumber = trim((string) ($preferredLotNumber ?? ''));
    $facilityId = (int) (posGetTransactionFacilityId() ?? 0);

    $sql = "SELECT di.inventory_id,
                   di.drug_id,
                   di.lot_number,
                   di.{$quantityColumn} AS on_hand,
                   di.expiration
              FROM drug_inventory di
         LEFT JOIN list_options lo
                ON lo.list_id = 'warehouse'
               AND lo.option_id = di.warehouse_id
               AND lo.activity = 1
             WHERE di.drug_id = ?
               AND (di.destroy_date IS NULL OR di.destroy_date = '0000-00-00' OR di.destroy_date = '0000-00-00 00:00:00')
               AND di.{$quantityColumn} > 0";

    $binds = [$drugId];

    if ($facilityId > 0) {
        $sql .= " AND COALESCE(CAST(lo.option_value AS UNSIGNED), di.facility_id) = ?";
        $binds[] = $facilityId;
    }

    if ($requiredQty > 0) {
        $sql .= " AND di.{$quantityColumn} >= ?";
        $binds[] = $requiredQty;
    }

    if ($preferredLotNumber !== '') {
        $sql .= " ORDER BY (COALESCE(di.lot_number, '') = ?) DESC, di.expiration ASC, di.inventory_id ASC LIMIT 1";
        $binds[] = $preferredLotNumber;
    } else {
        $sql .= " ORDER BY di.expiration ASC, di.inventory_id ASC LIMIT 1";
    }

    $row = sqlQuery($sql, $binds);
    return is_array($row) ? $row : null;
}

function posTransactionsHasColumn($column)
{
    static $cache = [];

    $column = (string) $column;
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    try {
        $stmt = sqlStatement("SHOW COLUMNS FROM pos_transactions LIKE ?", [$column]);
        $cache[$column] = (bool) ($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        error_log("posTransactionsHasColumn - Could not check column {$column}: " . $e->getMessage());
        $cache[$column] = false;
    }

    return $cache[$column];
}

function posGetTransactionFacilityId($pid = 0)
{
    $selectedFacilityId = (int) ($_SESSION['facilityId'] ?? 0);
    if ($selectedFacilityId > 0) {
        return $selectedFacilityId;
    }

    $pid = (int) $pid;
    if ($pid > 0) {
        try {
            $row = sqlFetchArray(sqlStatement("SELECT facility_id FROM patient_data WHERE pid = ? LIMIT 1", [$pid]));
            $patientFacilityId = (int) ($row['facility_id'] ?? 0);
            if ($patientFacilityId > 0) {
                return $patientFacilityId;
            }
        } catch (Exception $e) {
            error_log("posGetTransactionFacilityId - Could not load patient facility: " . $e->getMessage());
        }
    }

    $legacyFacilityId = (int) ($_SESSION['facility_id'] ?? 0);
    return $legacyFacilityId > 0 ? $legacyFacilityId : null;
}

function posNormalizeReceiptPrefix(string $prefix): string
{
    $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', trim($prefix)));
    $normalized = trim($normalized, '-');

    return $normalized !== '' ? $normalized : 'INV';
}

function posFormatReceiptFacilityToken($facilityId = null, $pid = 0): string
{
    $resolvedFacilityId = (int) ($facilityId ?? 0);
    if ($resolvedFacilityId <= 0) {
        $resolvedFacilityId = (int) (posGetTransactionFacilityId((int) $pid) ?? 0);
    }

    return $resolvedFacilityId > 0 ? ('F' . str_pad((string) $resolvedFacilityId, 3, '0', STR_PAD_LEFT)) : 'F000';
}

function posGenerateReceiptNumber(string $prefix = 'INV', $pid = 0, $facilityId = null): string
{
    $normalizedPrefix = posNormalizeReceiptPrefix($prefix);
    $facilityToken = posFormatReceiptFacilityToken($facilityId, $pid);
    $microToken = str_pad((string) ((int) round(fmod(microtime(true), 1) * 10000)), 4, '0', STR_PAD_LEFT);

    try {
        $randomToken = strtoupper(bin2hex(random_bytes(2)));
    } catch (Exception $e) {
        $randomToken = strtoupper(str_pad(dechex(mt_rand(0, 65535)), 4, '0', STR_PAD_LEFT));
    }

    return sprintf('%s-%s-%s-%s%s', $normalizedPrefix, $facilityToken, date('YmdHis'), $microToken, $randomToken);
}

function posResolveReceiptNumber($preferredReceiptNumber = null, string $prefix = 'INV', $pid = 0, $facilityId = null): string
{
    $preferredReceiptNumber = trim((string) $preferredReceiptNumber);
    if ($preferredReceiptNumber !== '') {
        return $preferredReceiptNumber;
    }

    return posGenerateReceiptNumber($prefix, $pid, $facilityId);
}

function ensurePosReceiptUniquenessSchema(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    if (posTableExists('pos_transactions')) {
        sqlStatement("ALTER TABLE `pos_transactions` MODIFY `transaction_type` VARCHAR(50) NOT NULL DEFAULT 'dispense'");

        if (!posColumnExists('pos_transactions', 'facility_id')) {
            sqlStatement("ALTER TABLE `pos_transactions` ADD COLUMN `facility_id` INT(11) NOT NULL DEFAULT 0");
        } else {
            sqlStatement("UPDATE `pos_transactions` SET `facility_id` = 0 WHERE `facility_id` IS NULL");
            sqlStatement("ALTER TABLE `pos_transactions` MODIFY `facility_id` INT(11) NOT NULL DEFAULT 0");
        }

        // Split payments reuse the same receipt number across multiple payment rows.
        if (posIndexExists('pos_transactions', 'uniq_facility_receipt')) {
            sqlStatement("ALTER TABLE `pos_transactions` DROP INDEX `uniq_facility_receipt`");
        }
        if (!posIndexExists('pos_transactions', 'idx_facility_receipt')) {
            sqlStatement("ALTER TABLE `pos_transactions` ADD INDEX `idx_facility_receipt` (`facility_id`, `receipt_number`)");
        }
    }

    if (!posTableExists('pos_receipts')) {
        sqlStatement(
            "CREATE TABLE IF NOT EXISTS `pos_receipts` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `receipt_number` varchar(50) NOT NULL,
                `pid` int(11) NOT NULL,
                `facility_id` int(11) NOT NULL DEFAULT 0,
                `amount` decimal(10,2) NOT NULL,
                `payment_method` varchar(20) NOT NULL,
                `transaction_id` varchar(100) NOT NULL,
                `receipt_data` longtext NOT NULL,
                `created_by` varchar(50) NOT NULL,
                `created_date` datetime NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_receipt_number` (`receipt_number`),
                KEY `pid` (`pid`),
                KEY `created_date` (`created_date`),
                KEY `facility_id` (`facility_id`),
                UNIQUE KEY `uniq_facility_receipt` (`facility_id`, `receipt_number`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } else {
        if (!posColumnExists('pos_receipts', 'facility_id')) {
            sqlStatement("ALTER TABLE `pos_receipts` ADD COLUMN `facility_id` INT(11) NOT NULL DEFAULT 0 AFTER `pid`");
        } else {
            sqlStatement("UPDATE `pos_receipts` SET `facility_id` = 0 WHERE `facility_id` IS NULL");
            sqlStatement("ALTER TABLE `pos_receipts` MODIFY `facility_id` INT(11) NOT NULL DEFAULT 0");
        }

        if (posIndexExists('pos_receipts', 'receipt_number')) {
            sqlStatement("ALTER TABLE `pos_receipts` DROP INDEX `receipt_number`");
        }
        if (!posIndexExists('pos_receipts', 'idx_receipt_number')) {
            sqlStatement("ALTER TABLE `pos_receipts` ADD INDEX `idx_receipt_number` (`receipt_number`)");
        }

        if (!posColumnExists('pos_receipts', 'transaction_id')) {
            sqlStatement("ALTER TABLE `pos_receipts` ADD COLUMN `transaction_id` varchar(100) NOT NULL DEFAULT '' AFTER `payment_method`");
        }
        if (!posColumnExists('pos_receipts', 'receipt_data')) {
            sqlStatement("ALTER TABLE `pos_receipts` ADD COLUMN `receipt_data` longtext NOT NULL AFTER `transaction_id`");
        }
        if (!posColumnExists('pos_receipts', 'created_by')) {
            sqlStatement("ALTER TABLE `pos_receipts` ADD COLUMN `created_by` varchar(50) NOT NULL DEFAULT '' AFTER `receipt_data`");
        }
        if (!posColumnExists('pos_receipts', 'created_date')) {
            sqlStatement("ALTER TABLE `pos_receipts` ADD COLUMN `created_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `created_by`");
        }

        if (!posIndexExists('pos_receipts', 'uniq_facility_receipt')) {
            $duplicateReceipt = sqlFetchArray(sqlStatement(
                "SELECT facility_id, receipt_number, COUNT(*) AS duplicate_count
                   FROM pos_receipts
               GROUP BY facility_id, receipt_number
                 HAVING COUNT(*) > 1
                  LIMIT 1"
            ));

            if (!empty($duplicateReceipt['duplicate_count'])) {
                error_log(
                    "ensurePosReceiptUniquenessSchema - Skipping uniq_facility_receipt because duplicates exist for facility_id=" .
                    (string) ($duplicateReceipt['facility_id'] ?? '0') .
                    ", receipt_number=" . (string) ($duplicateReceipt['receipt_number'] ?? '') .
                    ", count=" . (string) ($duplicateReceipt['duplicate_count'] ?? '0')
                );
            } else {
                sqlStatement("ALTER TABLE `pos_receipts` ADD UNIQUE INDEX `uniq_facility_receipt` (`facility_id`, `receipt_number`)");
            }
        }
    }

    $ensured = true;
}

function ensurePosFinalizeSchema(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    if (!posTableExists('pos_transactions')) {
        $ensured = true;
        return;
    }

    if (!posTransactionsHasColumn('finalized')) {
        sqlStatement("ALTER TABLE `pos_transactions` ADD COLUMN `finalized` TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!posTransactionsHasColumn('finalized_at')) {
        sqlStatement("ALTER TABLE `pos_transactions` ADD COLUMN `finalized_at` DATETIME NULL");
    }
    if (!posTransactionsHasColumn('finalized_by')) {
        sqlStatement("ALTER TABLE `pos_transactions` ADD COLUMN `finalized_by` VARCHAR(255) NULL");
    }
    if (!posIndexExists('pos_transactions', 'idx_receipt_finalized')) {
        sqlStatement("ALTER TABLE `pos_transactions` ADD INDEX `idx_receipt_finalized` (`receipt_number`, `finalized`)");
    }

    $ensured = true;
}

function posIsStagingRequest(): bool
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    return $host !== '' && strpos($host, 'staging.') !== false;
}

function posBuildFinalizedReceiptData(int $pid, array $transaction, array $input, float $creditAmountApplied): array
{
    global $pdo;

    $patient_name = 'Unknown Patient';
    try {
        $patient_query = "SELECT fname, lname FROM patient_data WHERE pid = ?";
        $stmt = $pdo->prepare($patient_query);
        $stmt->execute([$pid]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($patient) {
            $patient_name = trim((string) (($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? '')));
        }
    } catch (Exception $e) {
        error_log("posBuildFinalizedReceiptData - Failed loading patient name: " . $e->getMessage());
    }

    $paymentSummary = posGetReceiptPaymentSummary(
        $pid,
        (string) ($transaction['receipt_number'] ?? ($input['invoice_number'] ?? '')),
        (string) ($transaction['transaction_type'] ?? ''),
        (string) ($transaction['payment_method'] ?? '')
    );

    return [
        'receipt_number' => (string) ($transaction['receipt_number'] ?? ($input['invoice_number'] ?? '')),
        'transaction_id' => (int) ($transaction['id'] ?? 0),
        'patient_id' => $pid,
        'patient_name' => $patient_name !== '' ? $patient_name : 'Unknown Patient',
        'amount' => round((float) ($transaction['amount'] ?? 0), 2),
        'payment_method' => $paymentSummary['payment_method'],
        'items' => json_decode((string) ($transaction['items'] ?? '[]'), true) ?: [],
        'date' => (string) ($transaction['created_date'] ?? date('Y-m-d H:i:s')),
        'status' => 'completed',
        'subtotal' => round((float) ($input['subtotal'] ?? 0), 2),
        'tax_total' => round((float) ($input['tax_total'] ?? 0), 2),
        'tax_breakdown' => $input['tax_breakdown'] ?? [],
        'ten_off_discount' => round((float) ($input['ten_off_discount'] ?? 0), 2),
        'credit_amount' => round($creditAmountApplied, 2),
        'individual_payments' => $paymentSummary['individual_payments'],
        'price_override_notes' => trim((string) ($transaction['price_override_notes'] ?? ''))
    ];
}

function posUpsertFinalizedReceiptRecord(int $pid, array $transaction, array $receiptData): void
{
    $receiptNumber = (string) ($transaction['receipt_number'] ?? '');
    if ($receiptNumber === '') {
        return;
    }

    $facilityId = (int) ($transaction['facility_id'] ?? 0);
    $receiptJson = json_encode($receiptData, JSON_UNESCAPED_SLASHES);
    if ($receiptJson === false) {
        error_log("posUpsertFinalizedReceiptRecord - Failed to encode receipt JSON for {$receiptNumber}");
        return;
    }

    $existing = sqlFetchArray(sqlStatement(
        "SELECT id FROM pos_receipts WHERE pid = ? AND receipt_number = ? LIMIT 1",
        [$pid, $receiptNumber]
    ));

    if (!empty($existing['id'])) {
        sqlStatement(
            "UPDATE pos_receipts
                SET facility_id = ?, amount = ?, payment_method = ?, transaction_id = ?, receipt_data = ?, created_by = ?
              WHERE id = ?",
            [
                $facilityId,
                round((float) ($transaction['amount'] ?? 0), 2),
                (string) ($receiptData['payment_method'] ?? posGetReceiptPaymentMethodLabel($transaction['transaction_type'] ?? '')),
                (string) ($transaction['id'] ?? ''),
                $receiptJson,
                (string) ($_SESSION['authUser'] ?? $_SESSION['authUserID'] ?? 'system'),
                (int) $existing['id']
            ]
        );
        return;
    }

    sqlStatement(
        "INSERT INTO pos_receipts
            (receipt_number, pid, facility_id, amount, payment_method, transaction_id, receipt_data, created_by, created_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $receiptNumber,
            $pid,
            $facilityId,
            round((float) ($transaction['amount'] ?? 0), 2),
            (string) ($receiptData['payment_method'] ?? posGetReceiptPaymentMethodLabel($transaction['transaction_type'] ?? '')),
            (string) ($transaction['id'] ?? ''),
            $receiptJson,
            (string) ($_SESSION['authUser'] ?? $_SESSION['authUserID'] ?? 'system'),
            (string) ($transaction['created_date'] ?? date('Y-m-d H:i:s'))
        ]
    );
}

function posUpsertOperationalReceiptRecord(int $pid, array $transaction, array $input = []): bool
{
    $receiptData = posBuildFinalizedReceiptData($pid, $transaction, $input, 0.0);
    $receiptData['status'] = 'completed';
    $receiptData['subtotal'] = round((float) ($transaction['amount'] ?? 0), 2);
    $receiptData['tax_total'] = round((float) ($receiptData['tax_total'] ?? 0), 2);
    $receiptData['tax_breakdown'] = $receiptData['tax_breakdown'] ?? [];
    $receiptData['total_paid_db'] = round((float) ($transaction['amount'] ?? 0), 2);

    posUpsertFinalizedReceiptRecord($pid, $transaction, $receiptData);
    $receiptNumber = (string) ($transaction['receipt_number'] ?? '');
    if ($receiptNumber === '') {
        return false;
    }

    $existing = sqlFetchArray(sqlStatement(
        "SELECT id FROM pos_receipts WHERE pid = ? AND receipt_number = ? LIMIT 1",
        [$pid, $receiptNumber]
    ));

    return !empty($existing['id']);
}

function posInsertTransaction(array $transactionData)
{
    global $pdo;
    ensurePosReceiptUniquenessSchema();

    $pid = (int) ($transactionData['pid'] ?? 0);
    $columns = ['pid', 'receipt_number', 'transaction_type', 'amount', 'items', 'created_date', 'user_id'];
    $params = [
        $pid,
        posResolveReceiptNumber($transactionData['receipt_number'] ?? null, 'INV', $pid, $transactionData['facility_id'] ?? null),
        (string) ($transactionData['transaction_type'] ?? 'dispense'),
        round((float) ($transactionData['amount'] ?? 0), 2),
        is_string($transactionData['items'] ?? null) ? $transactionData['items'] : json_encode($transactionData['items'] ?? [], JSON_UNESCAPED_SLASHES),
        (string) ($transactionData['created_date'] ?? date('Y-m-d H:i:s')),
        (string) ($transactionData['user_id'] ?? ($_SESSION['authUserID'] ?? $_SESSION['authUser'] ?? 'system')),
    ];

    if (posTransactionsHasColumn('payment_method')) {
        $columns[] = 'payment_method';
        $params[] = (string) ($transactionData['payment_method'] ?? 'cash');
    }

    if (posTransactionsHasColumn('visit_type')) {
        $visitType = (string) ($transactionData['visit_type'] ?? '-');
        if (!in_array($visitType, ['F', 'R', 'N', '-'], true)) {
            $visitType = '-';
        }
        $columns[] = 'visit_type';
        $params[] = $visitType;
    }

    if (posTransactionsHasColumn('price_override_notes')) {
        $columns[] = 'price_override_notes';
        $params[] = $transactionData['price_override_notes'] ?? null;
    }

    if (posTransactionsHasColumn('patient_number')) {
        $patientNumber = posResolveDailyPatientNumber(
            $pid,
            $transactionData['patient_number'] ?? null,
            (string) ($transactionData['created_date'] ?? date('Y-m-d H:i:s'))
        );
        $columns[] = 'patient_number';
        $params[] = ($patientNumber === '' || $patientNumber === 0 || $patientNumber === '0') ? null : (int) $patientNumber;
    }

    if (posTransactionsHasColumn('facility_id')) {
        $columns[] = 'facility_id';
        $params[] = (int) (posGetTransactionFacilityId($pid) ?? 0);
    }

    $sql = "INSERT INTO pos_transactions (" . implode(', ', $columns) . ") VALUES (" .
        implode(', ', array_fill(0, count($columns), '?')) . ")";

    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute($params);

    if (!$result) {
        $errorInfo = $stmt->errorInfo();
        error_log("posInsertTransaction - Insert failed: " . json_encode($errorInfo));
    }

    return $result;
}

function posNormalizePatientNumber($value)
{
    $normalized = trim((string) ($value ?? ''));
    if ($normalized === '') {
        return null;
    }

    if (!preg_match('/^\d+$/', $normalized)) {
        throw new RuntimeException(xlt('Patient Number must be numeric'));
    }

    $numeric = (int) $normalized;
    return $numeric > 0 ? $numeric : null;
}

function posGetTodayPatientNumberForPid($pid, $date)
{
    $pid = (int) $pid;
    if ($pid <= 0 || !posTransactionsHasColumn('patient_number')) {
        return null;
    }

    [$dayStart, $dayEnd] = posGetDayDateRange($date);
    $voidFilter = posTransactionsHasColumn('voided') ? " AND COALESCE(voided, 0) = 0" : "";
    $facilityFilter = "";
    $binds = [$pid, $dayStart, $dayEnd];

    if (posTransactionsHasColumn('facility_id')) {
        $facilityId = posGetTransactionFacilityId($pid);
        if ((int) $facilityId > 0) {
            $facilityFilter = " AND facility_id = ?";
            $binds[] = (int) $facilityId;
        }
    }

    $row = sqlFetchArray(sqlStatement(
        "SELECT patient_number
           FROM pos_transactions
          WHERE pid = ?
            " . posGetTransactionCreatedDateRangeClause() . "
            AND patient_number IS NOT NULL
            AND patient_number > 0
            AND transaction_type != 'void'" . $voidFilter . $facilityFilter . "
          ORDER BY id DESC
          LIMIT 1",
        $binds
    ));

    return isset($row['patient_number']) ? (int) $row['patient_number'] : null;
}

function posPatientNumberUsedByAnotherPatient($patientNumber, $pid, $date)
{
    $patientNumber = (int) $patientNumber;
    $pid = (int) $pid;
    if ($patientNumber <= 0 || !posTransactionsHasColumn('patient_number')) {
        return false;
    }

    [$dayStart, $dayEnd] = posGetDayDateRange($date);
    $voidFilter = posTransactionsHasColumn('voided') ? " AND COALESCE(voided, 0) = 0" : "";
    $facilityFilter = "";
    $binds = [$patientNumber, $pid, $dayStart, $dayEnd];

    if (posTransactionsHasColumn('facility_id')) {
        $facilityId = posGetTransactionFacilityId($pid);
        if ((int) $facilityId > 0) {
            $facilityFilter = " AND facility_id = ?";
            $binds[] = (int) $facilityId;
        }
    }

    $row = sqlFetchArray(sqlStatement(
        "SELECT pid
           FROM pos_transactions
          WHERE patient_number = ?
            AND pid != ?
            " . posGetTransactionCreatedDateRangeClause() . "
            AND transaction_type != 'void'" . $voidFilter . $facilityFilter . "
          ORDER BY id DESC
          LIMIT 1",
        $binds
    ));

    return !empty($row);
}

function posResolveDailyPatientNumber($pid, $requestedPatientNumber, $createdDate = null)
{
    $pid = (int) $pid;
    if ($pid <= 0 || !posTransactionsHasColumn('patient_number')) {
        return null;
    }

    $date = substr((string) ($createdDate ?: date('Y-m-d H:i:s')), 0, 10);
    $requested = posNormalizePatientNumber($requestedPatientNumber);
    $existingForPatient = posGetTodayPatientNumberForPid($pid, $date);

    if ($existingForPatient !== null) {
        if ($requested !== null && $requested !== $existingForPatient) {
            throw new RuntimeException(xlt('This patient already has a Patient Number for today. Please use the same Patient Number for this patient today.'));
        }

        return $existingForPatient;
    }

    if ($requested === null) {
        return null;
    }

    if (posPatientNumberUsedByAnotherPatient($requested, $pid, $date)) {
        throw new RuntimeException(xlt('Patient Number already exists for today. Please enter a unique Patient Number.'));
    }

    return $requested;
}

function normalizePosQuantity($value)
{
    return round((float) ($value ?? 0), 4);
}

function isLiquidInventoryDrugData($drug)
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
            stripos($unit, '/ mL') !== false ||
            stripos($unit, 'cc') !== false ||
            stripos($route, 'intramuscular') !== false
        ) &&
        $mlPerVial !== null && $mlPerVial > 0 &&
        $mgPerMl !== null && $mgPerMl > 0
    );
}

function getAdministerInventoryDeductionQuantity($item, $administeredQuantity)
{
    $administeredQuantity = normalizePosQuantity($administeredQuantity);
    if ($administeredQuantity <= 0) {
        return 0.0;
    }

    $drugId = getPosItemDrugId($item);
    $lipoDeductionPerUnit = getLipoInventoryDeductionPerUnit($drugId);
    if ($lipoDeductionPerUnit !== null) {
        return normalizePosQuantity($lipoDeductionPerUnit * $administeredQuantity);
    }

    $doseMg = normalizePosQuantity($item['dose_option_mg'] ?? ($item['semaglutide_dose_mg'] ?? 0));
    if ($doseMg > 0) {
        return normalizePosQuantity($doseMg * $administeredQuantity);
    }

    return $administeredQuantity;
}

function isMlDrugForm($drugId)
{
    global $pdo;

    static $cache = [];

    $drugId = (int) $drugId;
    if ($drugId <= 0) {
        return false;
    }

    if (array_key_exists($drugId, $cache)) {
        return $cache[$drugId];
    }

    try {
        $stmt = $pdo->prepare("SELECT form, size, unit, route FROM drugs WHERE drug_id = ?");
        $stmt->execute([$drugId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $cache[$drugId] = isLiquidInventoryDrugData($row ?: []);
    } catch (Exception $e) {
        error_log("isMlDrugForm - Query failed for drug_id=$drugId: " . $e->getMessage());
        $cache[$drugId] = false;
    }

    return $cache[$drugId];
}

function getDailyAdministerIncrement($drugId, $administeredQuantity)
{
    $administeredQuantity = normalizePosQuantity($administeredQuantity);
    if ($administeredQuantity <= 0) {
        return 0.0;
    }

    return isMlDrugForm($drugId) ? 1.0 : $administeredQuantity;
}

function hasSuccessfulAdministerTransactionToday($pid, $drugId, $date)
{
    global $pdo;

    try {
        [$dayStart, $dayEnd] = posGetDayDateRange($date);
        $sql = "SELECT COUNT(*) AS transaction_count
                FROM pos_transactions
                WHERE pid = ?
                  AND created_date >= ?
                  AND created_date < ?
                  AND transaction_type IN ('administer', 'dispense_and_administer')
                  AND items LIKE ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$pid, $dayStart, $dayEnd, '%"drug_id":' . (int) $drugId . '%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($row['transaction_count'] ?? 0) > 0;
    } catch (PDOException $e) {
        error_log("hasSuccessfulAdministerTransactionToday - Query failed: " . $e->getMessage());
        return true;
    }
}

function isPhentermineDrug($drugId)
{
    static $cache = [];

    $drugId = (int) $drugId;
    if ($drugId <= 0) {
        return false;
    }

    if (array_key_exists($drugId, $cache)) {
        return $cache[$drugId];
    }

    try {
        $row = sqlQuery("SELECT name FROM drugs WHERE drug_id = ?", [$drugId]);
        $drugName = strtolower(trim((string) ($row['name'] ?? '')));
        $cache[$drugId] = in_array($drugName, ['phentermine #14', 'phentermine #28'], true);
    } catch (Throwable $e) {
        error_log("isPhentermineDrug - Query failed for drug_id=$drugId: " . $e->getMessage());
        $cache[$drugId] = false;
    }

    return $cache[$drugId];
}

function isLipoDrug($drugId)
{
    static $cache = [];

    $drugId = (int) $drugId;
    if ($drugId <= 0) {
        return false;
    }

    if (array_key_exists($drugId, $cache)) {
        return $cache[$drugId];
    }

    try {
        $row = sqlQuery("SELECT name FROM drugs WHERE drug_id = ?", [$drugId]);
        $drugName = strtoupper(trim((string) ($row['name'] ?? '')));
        $cache[$drugId] = in_array($drugName, ['LIPOB12', 'LIPOB12 SULFA FREE'], true);
    } catch (Throwable $e) {
        error_log("isLipoDrug - Query failed for drug_id=$drugId: " . $e->getMessage());
        $cache[$drugId] = false;
    }

    return $cache[$drugId];
}

function getLipoInventoryDeductionPerUnit($drugId)
{
    static $cache = [];

    $drugId = (int) $drugId;
    if ($drugId <= 0) {
        return null;
    }

    if (array_key_exists($drugId, $cache)) {
        return $cache[$drugId];
    }

    try {
        $row = sqlQuery("SELECT name FROM drugs WHERE drug_id = ?", [$drugId]);
        $drugName = strtoupper(trim((string) ($row['name'] ?? '')));

        if ($drugName === 'LIPOB12') {
            $cache[$drugId] = 126.175;
        } elseif ($drugName === 'LIPOB12 SULFA FREE') {
            $cache[$drugId] = 101.175;
        } else {
            $cache[$drugId] = null;
        }
    } catch (Throwable $e) {
        error_log("getLipoInventoryDeductionPerUnit - Query failed for drug_id=$drugId: " . $e->getMessage());
        $cache[$drugId] = null;
    }

    return $cache[$drugId];
}

function shouldDeductDispenseFromInventory($drugId)
{
    if (isPhentermineDrug($drugId) || isLipoDrug($drugId)) {
        return true;
    }

    return !isMlDrugForm($drugId);
}

function isPhentermineItem($item)
{
    $itemName = strtolower(trim((string) ($item['name'] ?? $item['display_name'] ?? '')));
    return in_array($itemName, ['phentermine #28', 'phentermine #14'], true);
}

function isHooverMarketplaceDispenseException($item)
{
    return isPhentermineItem($item);
}

function ensurePosRemainingDispenseSchema()
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

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
            KEY `receipt_number` (`receipt_number`),
            KEY `remaining_quantity` (`remaining_quantity`),
            KEY `idx_pid_drug_lot_remaining_created` (`pid`, `drug_id`, `lot_number`, `remaining_quantity`, `created_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    sqlStatement("ALTER TABLE `pos_remaining_dispense` MODIFY `total_quantity` decimal(12,4) NOT NULL DEFAULT 0");
    sqlStatement("ALTER TABLE `pos_remaining_dispense` MODIFY `dispensed_quantity` decimal(12,4) NOT NULL DEFAULT 0");
    sqlStatement("ALTER TABLE `pos_remaining_dispense` MODIFY `administered_quantity` decimal(12,4) NOT NULL DEFAULT 0");
    sqlStatement("ALTER TABLE `pos_remaining_dispense` MODIFY `remaining_quantity` decimal(12,4) NOT NULL DEFAULT 0");
    if (!posIndexExists('pos_remaining_dispense', 'idx_pid_drug_lot_remaining_created')) {
        sqlStatement("ALTER TABLE `pos_remaining_dispense` ADD INDEX `idx_pid_drug_lot_remaining_created` (`pid`, `drug_id`, `lot_number`, `remaining_quantity`, `created_date`)");
    }
    $ensured = true;
}

function ensurePatientCreditTables(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    sqlStatement(
        "CREATE TABLE IF NOT EXISTS `patient_credit_balance` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `pid` int(11) NOT NULL,
            `balance` decimal(10,2) NOT NULL DEFAULT 0.00,
            `created_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `pid` (`pid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    sqlStatement(
        "CREATE TABLE IF NOT EXISTS `patient_credit_transactions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `pid` int(11) NOT NULL,
            `transaction_type` enum('payment','refund','transfer') NOT NULL,
            `amount` decimal(10,2) NOT NULL,
            `balance_before` decimal(10,2) NOT NULL,
            `balance_after` decimal(10,2) NOT NULL,
            `receipt_number` varchar(50) DEFAULT NULL,
            `description` text,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `pid` (`pid`),
            KEY `transaction_type` (`transaction_type`),
            KEY `created_at` (`created_at`),
            KEY `idx_pid_receipt_type` (`pid`, `receipt_number`, `transaction_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if (!posIndexExists('patient_credit_transactions', 'idx_pid_receipt_type')) {
        sqlStatement("ALTER TABLE `patient_credit_transactions` ADD INDEX `idx_pid_receipt_type` (`pid`, `receipt_number`, `transaction_type`)");
    }
    $ensured = true;
}

function ensureDailyAdministerTrackingSchema()
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
            UNIQUE KEY `unique_daily_administer` (`pid`, `drug_id`, `administer_date`),
            KEY `idx_patient_date` (`pid`, `administer_date`),
            KEY `idx_drug_date` (`drug_id`, `administer_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    sqlStatement("ALTER TABLE `daily_administer_tracking` MODIFY `total_administered` decimal(12,4) NOT NULL DEFAULT 0");
}

function ensureDrugInventoryQuantitySchema()
{
    global $pdo;

    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `drug_inventory` LIKE 'on_hand'");
        $stmt->execute();
        $column = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$column) {
            return;
        }

        $columnType = strtolower((string) ($column['Type'] ?? ''));
        if (strpos($columnType, 'decimal') !== false) {
            return;
        }

        sqlStatement("ALTER TABLE `drug_inventory` MODIFY `on_hand` DECIMAL(12,4) NOT NULL DEFAULT 0");
        error_log("ensureDrugInventoryQuantitySchema - Upgraded drug_inventory.on_hand from {$columnType} to DECIMAL(12,4)");
    } catch (Exception $e) {
        error_log("ensureDrugInventoryQuantitySchema - Failed: " . $e->getMessage());
    }
}

ensureDrugInventoryQuantitySchema();

// Check for any output after includes
$output_after_includes = ob_get_contents();
if (!empty($output_after_includes)) {
    error_log("Output detected after includes: " . substr($output_after_includes, 0, 500));
    ob_clean();
}

// Additional check for any output before processing
$output_before_processing = ob_get_contents();
if (!empty($output_before_processing)) {
    error_log("Output detected before processing: " . substr($output_before_processing, 0, 200));
    ob_clean();
}

// Temporarily skip CSRF verification for testing
// use OpenEMR\Billing\PaymentGateway;
// use OpenEMR\Common\Csrf\CsrfUtils;
// use OpenEMR\Common\Crypto\CryptoGen;

// Use the new clean output function
function sendJsonResponse($data, $statusCode = 200) {
    cleanOutputAndSendJson($data, $statusCode);
}

// For now, skip authentication check to test functionality
// TODO: Implement proper authentication later
/*
// Ensure user is logged in
if (!isset($_SESSION['authUserID'])) {
    error_log("Payment processor: User not authenticated - authUserID not set");
    sendJsonResponse(['success' => false, 'error' => xlt('Not authorized')], 401);
}
*/

// Get input data
$raw_input = file_get_contents('php://input');
$input = json_decode($raw_input, true);
if (!$input) {
    $input = $_POST;
}

// Debug: Log the request data
error_log("Payment processor: Raw input data: " . $raw_input);
error_log("Payment processor: Decoded input data: " . json_encode($input));

// Debug: Log input data
error_log("Payment processor - Input data: " . json_encode($input));

// Debug logging
error_log("Payment processor - About to parse action and verify CSRF");

// Verify CSRF token from the input data (skip for dispense and administer completion)
$action = $input['action'] ?? '';
error_log("Payment processor - Action parsed: '$action'");

if ($action !== 'complete_dispense' && $action !== 'complete_administer' && $action !== 'complete_dispense_and_administer' && $action !== 'complete_marketplace_dispense') {
    $csrf_token = $input['csrf_token_form'] ?? '';
    error_log("Payment processor - CSRF token: '$csrf_token'");
    error_log("Payment processor - About to verify CSRF token");
    
    // Temporarily skip CSRF verification for testing
    // if (!CsrfUtils::verifyCsrfToken($csrf_token)) {
    //     error_log("Payment processor - CSRF token verification failed");
    //     sendJsonResponse(['success' => false, 'error' => xlt('CSRF token verification failed')], 403);
    // }
    error_log("Payment processor - CSRF token verification skipped for testing");
}

$pid = $input['pid'] ?? 0;
$amount = floatval($input['amount'] ?? 0);
$credit_amount = floatval($input['credit_amount'] ?? 0);
$items = $input['items'] ?? [];

error_log("Payment processor - Variables parsed: action='$action', pid=$pid, amount=$amount, credit_amount=$credit_amount, items_count=" . count($items));
error_log("Payment processor - About to start debugging section");

if (!$action) {
    error_log("Payment processor - No action provided, sending error response");
    sendJsonResponse(['success' => false, 'error' => xlt('Invalid input data')], 400);
}

// Only require items for actions that need them
if ($action !== 'check_administer_limit' && $action !== 'switch_medicine' && $action !== 'get_patient_credit' && $action !== 'get_refundable_items' && empty($items)) {
    error_log("Payment processor - Items validation failed for action: $action");
    sendJsonResponse(['success' => false, 'error' => xlt('Invalid input data - items required')], 400);
}

// Validate amount for payment actions only
// Validate amount for payment actions only
$subtotal   = round((float)($input['subtotal'] ?? 0), 2);
$tax_total  = round((float)($input['tax_total'] ?? 0), 2);
$credit_amt = round((float)($input['credit_amount'] ?? 0), 2);

// "True due" based on what backend receives
$total_due = round($subtotal + $tax_total - $credit_amt, 2);

if (
    $action !== 'complete_dispense' &&
    $action !== 'complete_administer' &&
    $action !== 'complete_dispense_and_administer' &&
    $action !== 'complete_marketplace_dispense' &&
    $action !== 'check_administer_limit' &&
    $action !== 'finalize_invoice' &&
    $action !== 'switch_medicine' &&
    $action !== 'get_patient_credit' &&
    $action !== 'get_refundable_items' &&
    $amount <= 0 &&
    $total_due !== 0.00     // ✅ allow amount=0 only when true total due is 0
) {
    error_log("Payment processor - Amount validation failed for action: $action, amount: $amount, total_due: $total_due");
    sendJsonResponse(['success' => false, 'error' => xlt('Invalid amount')], 400);
}

// Check for any output before processing
$output_before_processing = ob_get_contents();
if (!empty($output_before_processing)) {
    error_log("Output detected before processing: " . substr($output_before_processing, 0, 200));
    ob_clean();
}

$actionsRequiringDailyPatientNumber = [
    'record_external_payment',
    'finalize_invoice',
];

if (in_array($action, $actionsRequiringDailyPatientNumber, true) && posTransactionsHasColumn('patient_number')) {
    try {
        $resolvedPatientNumber = posResolveDailyPatientNumber(
            $pid,
            $input['patient_number'] ?? null,
            (string) ($input['created_date'] ?? date('Y-m-d H:i:s'))
        );

        if ($resolvedPatientNumber === null) {
            sendJsonResponse(['success' => false, 'error' => xlt('Patient Number is required for POS checkout')], 400);
        }

        $input['patient_number'] = $resolvedPatientNumber;
        $GLOBALS['input']['patient_number'] = $resolvedPatientNumber;
    } catch (RuntimeException $e) {
        sendJsonResponse(['success' => false, 'error' => $e->getMessage()], 409);
    }
}

// Debug: Log the action being processed
error_log("Payment processor - Action: '$action', PID: $pid, Amount: $amount");
error_log("Payment processor - Items count: " . count($items));
error_log("Payment processor - About to enter switch statement");
error_log("Payment processor - Action value: '$action' (length: " . strlen($action) . ")");
error_log("Payment processor - Action bytes: " . bin2hex($action));

// Process based on action
error_log("Payment processor - About to start switch statement with action: '$action'");
switch ($action) {
    case 'process_stripe_payment':
        processStripePayment($pid, $amount, $input['stripeToken'], $items);
        break;
        
    case 'process_cash_payment':
        processCashPayment($pid, $amount, $input['amount_tendered'], $items);
        break;
        
    case 'update_inventory':
        updateInventory($pid, $amount, $items);
        break;
        
    case 'complete_dispense':
        error_log("Switch case 'complete_dispense' matched - About to call completeDispense function");
        completeDispense($pid, $items);
        error_log("Switch case 'complete_dispense' - completeDispense function completed");
        break;
        
    case 'complete_administer':
        completeAdminister($pid, $items);
        break;
        
    case 'complete_dispense_and_administer':
        completeDispenseAndAdminister($pid, $items);
        break;
        
    case 'complete_marketplace_dispense':
        error_log("Payment processor - About to call completeMarketplaceDispense");
        completeMarketplaceDispense($pid, $items);
        error_log("Payment processor - completeMarketplaceDispense completed");
        break;
        
    case 'check_administer_limit':
        checkAdministerLimit($pid, $input);
        break;
        
    case 'record_external_payment':
        error_log("Payment processor - About to call recordExternalPayment");
        recordExternalPayment($pid, $amount, $input, $items);
        error_log("Payment processor - recordExternalPayment completed");
        break;
        
    case 'get_patient_credit':
        getPatientCredit($pid);
        break;
        
    case 'get_refundable_items':
        getRefundableItems($pid);
        break;
        
    case 'finalize_invoice':
        error_log("Payment processor - About to call finalizeInvoice");
        finalizeInvoice($pid, $input);
        error_log("Payment processor - finalizeInvoice completed");
        break;
        
    case 'switch_medicine':
        switchMedicine($pid, $input);
        break;
        
    default:
        error_log("Payment processor - Falling through to default case for action: '$action'");
        sendJsonResponse(['success' => false, 'error' => xlt('Invalid action')], 400);
}
error_log("Payment processor - Switch statement completed");

/**
 * Generate receipt data and store transaction record
 */
function generateReceipt($pid, $amount, $items, $payment_method, $transaction_id, $change = 0, $subtotal = null, $tax_total = null, $tax_breakdown = null, $credit_amount = 0) {
    global $GLOBALS;
    global $input;
    ensurePosReceiptUniquenessSchema();
    
    $timestamp = date('Y-m-d H:i:s');
    // Use the invoice number from frontend as receipt number, or generate one if not provided
    $receipt_number = posResolveReceiptNumber($input['invoice_number'] ?? null, 'RCPT', $pid);
    
    // Get patient data
    $patient_data = getPatientData($pid, 'fname,mname,lname,pubpid,DOB,phone_home,email');
    
    // Prepare receipt data
    $receipt_data = [
        'receipt_number' => $receipt_number,
        'timestamp' => $timestamp,
        'patient_id' => $pid,
        'patient_name' => $patient_data ? $patient_data['fname'] . ' ' . $patient_data['lname'] : 'Unknown',
        'amount' => $amount,
        'payment_method' => $payment_method,
        'transaction_id' => $transaction_id,
        'change' => $change,
        'credit_amount' => $credit_amount,
        'items' => $items,
        'subtotal' => $subtotal,
        'tax_total' => $tax_total,
        'tax_breakdown' => $tax_breakdown,
        'user' => $_SESSION['authUser'],
        'facility' => $GLOBALS['facility_name'] ?? 'OpenEMR'
    ];
    
    // Store receipt in database
    $receipt_json = json_encode($receipt_data);
    
    // Check if JSON encoding failed
    if ($receipt_json === false) {
        // Fallback: create a simpler receipt structure
        $receipt_data_simple = [
            'receipt_number' => $receipt_number,
            'timestamp' => $timestamp,
            'patient_id' => $pid,
            'patient_name' => $patient_data ? $patient_data['fname'] . ' ' . $patient_data['lname'] : 'Unknown',
            'amount' => $amount,
            'payment_method' => $payment_method,
            'transaction_id' => $transaction_id,
            'change' => $change,
            'user' => $_SESSION['authUser'],
            'facility' => $GLOBALS['facility_name'] ?? 'OpenEMR'
        ];
        $receipt_json = json_encode($receipt_data_simple);
    }
    
    // Debug: Log the receipt data structure
    
    try {
        // Check if table exists first
        $table_exists = sqlFetchArray(sqlStatement("SHOW TABLES LIKE 'pos_receipts'"));
        if (!$table_exists) {
            // Create table if it doesn't exist
            $create_sql = "CREATE TABLE IF NOT EXISTS `pos_receipts` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `receipt_number` varchar(50) NOT NULL,
                `pid` int(11) NOT NULL,
                `facility_id` int(11) NOT NULL DEFAULT 0,
                `amount` decimal(10,2) NOT NULL,
                `payment_method` varchar(20) NOT NULL,
                `transaction_id` varchar(100) NOT NULL,
                `receipt_data` longtext NOT NULL,
                `created_by` varchar(50) NOT NULL,
                `created_date` datetime NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_receipt_number` (`receipt_number`),
                KEY `pid` (`pid`),
                KEY `created_date` (`created_date`),
                KEY `facility_id` (`facility_id`),
                UNIQUE KEY `uniq_facility_receipt` (`facility_id`, `receipt_number`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            sqlStatement($create_sql);
        }
        
        // Ensure all parameters are properly converted to strings/numbers
        $receipt_number = strval($receipt_number);
        $pid = intval($pid);
        $amount = floatval($amount);
        $payment_method = strval($payment_method);
        $transaction_id = strval($transaction_id);
        $receipt_json = strval($receipt_json);
        $created_by = strval($_SESSION['authUser']);
        $timestamp = strval($timestamp);
        $facility_id = (int) (posGetTransactionFacilityId($pid) ?? 0);
        
        sqlStatement("INSERT INTO pos_receipts SET " .
            "receipt_number = ?, " .
            "pid = ?, " .
            "facility_id = ?, " .
            "amount = ?, " .
            "payment_method = ?, " .
            "transaction_id = ?, " .
            "receipt_data = ?, " .
            "created_by = ?, " .
            "created_date = ?",
            array($receipt_number, $pid, $facility_id, $amount, $payment_method, $transaction_id, $receipt_json, $created_by, $timestamp)
        );
    } catch (Exception $e) {
        // Log error but don't fail the payment
        error_log("POS Receipt generation failed: " . $e->getMessage());
        // Continue without receipt generation
    }
    
    return $receipt_data;
}

/**
 * Process Stripe payment
 */
function processStripePayment($pid, $amount, $token, $items) {
    global $input;
    global $GLOBALS;
    
    // Check for any output at the start of function
    $output_at_start = ob_get_contents();
    if (!empty($output_at_start)) {
        error_log("Output detected at start of processStripePayment: " . substr($output_at_start, 0, 200));
        ob_clean();
    }
    
    // Get credit amount from input
    $credit_amount = floatval($input['credit_amount'] ?? 0);
    
    if ($GLOBALS['payment_gateway'] !== 'Stripe') {
        sendJsonResponse(['success' => false, 'error' => xlt('Stripe not configured')]);
        return;
    }
    
    try {
        // Get patient data
        $patient_data = getPatientData($pid, 'fname,mname,lname,pubpid');
        $description = $patient_data ? 
            'POS Payment for ' . $patient_data['fname'] . ' ' . $patient_data['lname'] :
            'POS Payment';
        
        // Process payment using OpenEMR's PaymentGateway
        $pay = new PaymentGateway("Stripe");
        
        // Ensure proper precision for Stripe (amount should be in dollars, not cents)
        $amount_for_stripe = round($amount, 2);
        
        // Create metadata with proper length checking
        $metadata = [
            'Patient' => $patient_data ? $patient_data['fname'] . ' ' . $patient_data['lname'] : 'Walk-in',
            'MRN' => $patient_data ? $patient_data['pubpid'] : 'N/A',
            'POS Transaction' => 'true'
        ];
        
        // Create simplified items for metadata
        $simplified_items = array_map(function($item) {
            return [
                'id' => $item['id'] ?? '',
                'name' => substr(($item['name'] ?? $item['display_name'] ?? 'Unknown'), 0, 25), // Truncate long names
                'qty' => $item['quantity'] ?? 0,
                'price' => $item['price'] ?? 0
            ];
        }, $items);
        
        // Convert to JSON and ensure it fits within Stripe's 500 character limit
        $items_json = json_encode($simplified_items);
        $max_items_length = 400; // Leave room for other metadata fields
        
        if (strlen($items_json) > $max_items_length) {
            // If too long, truncate the items array
            $truncated_items = array_slice($simplified_items, 0, 2); // Keep only first 2 items
            $items_json = json_encode($truncated_items);
            
            if (strlen($items_json) > $max_items_length) {
                // If still too long, create a simple summary
                $total_items = count($simplified_items);
                $items_json = json_encode(['summary' => "{$total_items} items"]);
            }
        }
        
        $metadata['Items'] = $items_json;
        
        // Debug logging for metadata length
        $total_metadata_length = strlen(json_encode($metadata));
        error_log("POS Payment Processor - Metadata length: {$total_metadata_length} characters");
        if ($total_metadata_length > 500) {
            error_log("POS Payment Processor - WARNING: Metadata exceeds 500 character limit!");
        }
        
        $transaction = [
            'amount' => $amount_for_stripe,
            'currency' => "USD",
            'token' => $token,
            'description' => $description,
            'metadata' => $metadata
        ];
        
        $response = $pay->submitPaymentToken($transaction);
        
        if (is_string($response)) {
            sendJsonResponse(['success' => false, 'error' => $response]);
            return;
        }
        
        if ($response->isSuccessful()) {
            // Record the payment
            $timestamp = date('Y-m-d H:i:s');
            $payment_id = 'pos_stripe_' . time();
            
            // Record in payments table
            if ($pid) {
                frontPayment($pid, 0, 'stripe', $payment_id, $amount, 0, $timestamp);
                
                // Record in ar_activity table
                sqlBeginTrans();
                $sequence_no = sqlFetchArray(sqlStatement("SELECT IFNULL(MAX(sequence_no),0) + 1 AS increment FROM ar_activity WHERE pid = ? AND encounter = 0", array($pid)));
                $sequence_increment = 1;
                
                // Handle ADORecordSet result
                if (is_object($sequence_no) && method_exists($sequence_no, 'FetchRow')) {
                    $sequence_row = $sequence_no->FetchRow();
                    if ($sequence_row && isset($sequence_row['increment'])) {
                        $sequence_increment = intval($sequence_row['increment']);
                    }
                } elseif (is_array($sequence_no) && isset($sequence_no['increment'])) {
                    $sequence_increment = intval($sequence_no['increment']);
                }
                
                // Record product transactions first
                $subtotal = $input['subtotal'] ?? 0;
                
                // Create a single consolidated transaction entry for products
                if ($subtotal > 0) {
                    $product_sequence = $sequence_increment++;
                    
                    // Create a consolidated description for all items
                    $item_descriptions = [];
                    foreach ($input['items'] as $item) {
                        $item_name = $item['name'] ?? $item['display_name'] ?? 'Unknown Item';
                        $item_qty = $item['quantity'] ?? 1;
                        $item_descriptions[] = $item_name . ' (Qty: ' . $item_qty . ')';
                    }
                    
                    $consolidated_description = 'POS Transaction - ' . implode(', ', $item_descriptions);
                    
                    sqlStatement("INSERT INTO ar_activity SET " .
                        "pid = ?, " .
                        "encounter = 0, " .
                        "sequence_no = ?, " .
                        "code_type = 'POS', " .
                        "code = ?, " .
                        "modifier = '', " .
                        "payer_type = 0, " .
                        "post_time = ?, " .
                        "post_user = ?, " .
                        "session_id = ?, " .
                        "modified_time = ?, " .
                        "pay_amount = ?, " .
                        "adj_amount = 0, " .
                        "account_code = 'POS'",
                        array($pid, $product_sequence, $consolidated_description, $timestamp, $_SESSION['authUser'], $payment_id, $timestamp, $subtotal)
                    );
                }
                
                // Record tax transactions separately
                $tax_total = $input['tax_total'] ?? 0;
                
                // Debug logging for tax data
                error_log("POS Payment Processor - Tax Total: " . $tax_total);
                error_log("POS Payment Processor - Tax Breakdown: " . print_r($input['tax_breakdown'] ?? 'null', true));
                
                if ($tax_total > 0 && isset($input['tax_breakdown']) && is_array($input['tax_breakdown'])) {
                    foreach ($input['tax_breakdown'] as $tax) {
                        $tax_amount = $tax['amount'] ?? 0;
                        if ($tax_amount > 0) {
                            $tax_sequence = $sequence_increment++;
                            $tax_name = $tax['name'] ?? 'Sales Tax';

                            error_log("POS Payment Processor - Creating Tax Entry: " . $tax_name . " Amount: " . $tax_amount);
                            
                            sqlStatement("INSERT INTO ar_activity SET " .
                                "pid = ?, " .
                                "encounter = 0, " .
                                "sequence_no = ?, " .
                                "code_type = 'TAX', " .
                                "code = ?, " .
                                "modifier = '', " .
                                "payer_type = 0, " .
                                "post_time = ?, " .
                                "post_user = ?, " .
                                "session_id = ?, " .
                                "modified_time = ?, " .
                                "pay_amount = ?, " .
                                "adj_amount = 0, " .
                                "account_code = 'TAX'",
                                array($pid, $tax_sequence, $tax_name, $timestamp, $_SESSION['authUser'], $payment_id, $timestamp, $tax_amount)
                            );
                            
                            error_log("POS Payment Processor - Tax Entry Created Successfully");
                        }
                    }
                } else {
                    // Fallback: If we have a tax total but no breakdown, create a single tax entry
                    if ($tax_total > 0) {
                        $tax_sequence = $sequence_increment++;
                        
                        error_log("POS Payment Processor - Creating Fallback Tax Entry: Sales Tax Amount: " . $tax_total);
                        
                        sqlStatement("INSERT INTO ar_activity SET " .
                            "pid = ?, " .
                            "encounter = 0, " .
                            "sequence_no = ?, " .
                            "code_type = 'TAX', " .
                            "code = ?, " .
                            "modifier = '', " .
                            "payer_type = 0, " .
                            "post_time = ?, " .
                            "post_user = ?, " .
                            "session_id = ?, " .
                            "modified_time = ?, " .
                            "pay_amount = ?, " .
                            "adj_amount = 0, " .
                            "account_code = 'TAX'",
                            array($pid, $tax_sequence, 'Sales Tax', $timestamp, $_SESSION['authUser'], $payment_id, $timestamp, $tax_total)
                        );
                        
                        error_log("POS Payment Processor - Fallback Tax Entry Created Successfully");
                    }
                }
                
                // Record payment transaction
                sqlStatement("INSERT INTO ar_activity SET " .
                    "pid = ?, " .
                    "encounter = 0, " .
                    "sequence_no = ?, " .
                    "code_type = 'stripe', " .
                    "code = ?, " .
                    "modifier = '', " .
                    "payer_type = 0, " .
                    "post_time = ?, " .
                    "post_user = ?, " .
                    "session_id = ?, " .
                    "modified_time = ?, " .
                    "pay_amount = ?, " .
                    "adj_amount = 0, " .
                    "account_code = 'PP'",
                    array($pid, $sequence_increment, $payment_id, $timestamp, $_SESSION['authUser'], $payment_id, $timestamp, $amount)
                );
                sqlCommitTrans();
            }
            
            // Extract tax information from input
            $subtotal = $input['subtotal'] ?? null;
            $tax_total = $input['tax_total'] ?? null;
            $tax_breakdown = $input['tax_breakdown'] ?? null;
            
            // Generate receipt
            $receipt_data = generateReceipt($pid, $amount, $items, 'stripe', $response->getTransactionReference(), 0, $subtotal, $tax_total, $tax_breakdown, $credit_amount);
            
            // Update inventory with receipt number
            $inventoryResult = updateInventory($pid, $amount, $items, $receipt_data['receipt_number']);
            
            // Check for any output before credit processing
            $output_before_credit = ob_get_contents();
            if (!empty($output_before_credit)) {
                error_log("Output detected before credit processing: " . substr($output_before_credit, 0, 200));
                ob_clean();
            }
            
            // Process credit if any was applied
            $creditResult = ['success' => true];
            if ($credit_amount > 0) {
                try {
                    $creditResult = processCreditPayment($pid, $credit_amount, $receipt_data['receipt_number']);
                    if (!$creditResult['success']) {
                        error_log("Credit processing failed: " . $creditResult['error']);
                    }
                } catch (Exception $e) {
                    error_log("Credit processing exception: " . $e->getMessage());
                    $creditResult = ['success' => false, 'error' => $e->getMessage()];
                }
            }
            
            if ($inventoryResult['success']) {
                $message = xlt('Payment processed and inventory updated successfully');
                if ($credit_amount > 0) {
                    if ($creditResult['success']) {
                        $message .= ' - Credit applied: $' . number_format($credit_amount, 2);
                    } else {
                        $message .= ' - Warning: Credit processing failed: ' . $creditResult['error'];
                    }
                }
                
                sendJsonResponse([
                    'success' => true, 
                    'transaction_id' => $response->getTransactionReference(),
                    'receipt_number' => $receipt_data['receipt_number'],
                    'credit_amount' => $credit_amount,
                    'credit_success' => $creditResult['success'],
                    'message' => $message
                ]);
            } else {
                sendJsonResponse([
                    'success' => true, 
                    'transaction_id' => $response->getTransactionReference(),
                    'receipt_number' => $receipt_data['receipt_number'],
                    'credit_amount' => $credit_amount,
                    'credit_success' => $creditResult['success'],
                    'warning' => xlt('Payment processed but inventory update failed: ') . $inventoryResult['error']
                ]);
            }
        } else {
            sendJsonResponse(['success' => false, 'error' => $response->getMessage()]);
        }
        
    } catch (Exception $e) {
        sendJsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Process cash payment
 */
function processCashPayment($pid, $amount, $amountTendered, $items) {
    global $input;
    
    // Get credit amount from input
    $credit_amount = floatval($input['credit_amount'] ?? 0);
    try {
        $timestamp = date('Y-m-d H:i:s');
        $payment_id = 'pos_cash_' . time();
        $change = $amountTendered - $amount;
        
        // Record in payments table
        if ($pid) {
            frontPayment($pid, 0, 'cash', $payment_id, $amount, 0, $timestamp);
            
            // Record in ar_activity table
            sqlBeginTrans();
            $sequence_no = sqlFetchArray(sqlStatement("SELECT IFNULL(MAX(sequence_no),0) + 1 AS increment FROM ar_activity WHERE pid = ? AND encounter = 0", array($pid)));
            $sequence_increment = 1;
            
            // Handle ADORecordSet result
            if (is_object($sequence_no) && method_exists($sequence_no, 'FetchRow')) {
                $sequence_row = $sequence_no->FetchRow();
                if ($sequence_row && isset($sequence_row['increment'])) {
                    $sequence_increment = intval($sequence_row['increment']);
                }
            } elseif (is_array($sequence_no) && isset($sequence_no['increment'])) {
                $sequence_increment = intval($sequence_no['increment']);
            }
            
            // Record product transactions first
            $subtotal = $input['subtotal'] ?? 0;
            
            // Create a single consolidated transaction entry for products
            if ($subtotal > 0) {
                $product_sequence = $sequence_increment++;
                
                // Create a consolidated description for all items
                $item_descriptions = [];
                foreach ($input['items'] as $item) {
                    $item_name = $item['name'] ?? $item['display_name'] ?? 'Unknown Item';
                    $item_qty = $item['quantity'] ?? 1;
                    $item_descriptions[] = $item_name . ' (Qty: ' . $item_qty . ')';
                }
                
                $consolidated_description = 'POS Transaction - ' . implode(', ', $item_descriptions);
                
                sqlStatement("INSERT INTO ar_activity SET " .
                    "pid = ?, " .
                    "encounter = 0, " .
                    "sequence_no = ?, " .
                    "code_type = 'POS', " .
                    "code = ?, " .
                    "modifier = '', " .
                    "payer_type = 0, " .
                    "post_time = ?, " .
                    "post_user = ?, " .
                    "session_id = ?, " .
                    "modified_time = ?, " .
                    "pay_amount = ?, " .
                    "adj_amount = 0, " .
                    "account_code = 'POS'",
                    array($pid, $product_sequence, $consolidated_description, $timestamp, $_SESSION['authUser'], $payment_id, $timestamp, $subtotal)
                );
            }
            
            // Record tax transactions separately
            $tax_total = $input['tax_total'] ?? 0;
            
            // Debug logging for tax data
            error_log("POS Cash Payment - Tax Total: " . $tax_total);
            error_log("POS Cash Payment - Tax Breakdown: " . print_r($input['tax_breakdown'] ?? 'null', true));
            
            if ($tax_total > 0 && isset($input['tax_breakdown']) && is_array($input['tax_breakdown'])) {
                foreach ($input['tax_breakdown'] as $tax) {
                    $tax_amount = $tax['amount'] ?? 0;
                    if ($tax_amount > 0) {
                        $tax_sequence = $sequence_increment++;
                        $tax_name = $tax['name'] ?? 'Sales Tax';
                        
                        error_log("POS Cash Payment - Creating Tax Entry: " . $tax_name . " Amount: " . $tax_amount);
                        
                        sqlStatement("INSERT INTO ar_activity SET " .
                            "pid = ?, " .
                            "encounter = 0, " .
                            "sequence_no = ?, " .
                            "code_type = 'TAX', " .
                            "code = ?, " .
                            "modifier = '', " .
                            "payer_type = 0, " .
                            "post_time = ?, " .
                            "post_user = ?, " .
                            "session_id = ?, " .
                            "modified_time = ?, " .
                            "pay_amount = ?, " .
                            "adj_amount = 0, " .
                            "account_code = 'TAX'",
                            array($pid, $tax_sequence, $tax_name, $timestamp, $_SESSION['authUser'], $payment_id, $timestamp, $tax_amount)
                        );
                        
                        error_log("POS Cash Payment - Tax Entry Created Successfully");
                    }
                }
            } else {
                // Fallback: If we have a tax total but no breakdown, create a single tax entry
                if ($tax_total > 0) {
                    $tax_sequence = $sequence_increment++;
                    
                    error_log("POS Cash Payment - Creating Fallback Tax Entry: Sales Tax Amount: " . $tax_total);
                    
                    sqlStatement("INSERT INTO ar_activity SET " .
                        "pid = ?, " .
                        "encounter = 0, " .
                        "sequence_no = ?, " .
                        "code_type = 'TAX', " .
                        "code = ?, " .
                        "modifier = '', " .
                        "payer_type = 0, " .
                        "post_time = ?, " .
                        "post_user = ?, " .
                        "session_id = ?, " .
                        "modified_time = ?, " .
                        "pay_amount = ?, " .
                        "adj_amount = 0, " .
                        "account_code = 'TAX'",
                        array($pid, $tax_sequence, 'Sales Tax', $timestamp, $_SESSION['authUser'], $payment_id, $timestamp, $tax_total)
                    );
                    
                    error_log("POS Cash Payment - Fallback Tax Entry Created Successfully");
                }
            }
            
            // Record payment transaction
            sqlStatement("INSERT INTO ar_activity SET " .
                "pid = ?, " .
                "encounter = 0, " .
                "sequence_no = ?, " .
                "code_type = 'cash', " .
                "code = ?, " .
                "modifier = '', " .
                "payer_type = 0, " .
                "post_time = ?, " .
                "post_user = ?, " .
                "session_id = ?, " .
                "modified_time = ?, " .
                "pay_amount = ?, " .
                "adj_amount = 0, " .
                "account_code = 'PP'",
                array($pid, $sequence_increment, $payment_id, $timestamp, $_SESSION['authUser'], $payment_id, $timestamp, $amount)
            );
            sqlCommitTrans();
        }
        
        // Extract tax information from input
        $subtotal = $input['subtotal'] ?? null;
        $tax_total = $input['tax_total'] ?? null;
        $tax_breakdown = $input['tax_breakdown'] ?? null;
        
        // Generate receipt
        $receipt_data = generateReceipt($pid, $amount, $items, 'cash', $payment_id, $change, $subtotal, $tax_total, $tax_breakdown, $credit_amount);
        
        // Update inventory with receipt number
        $inventoryResult = updateInventory($pid, $amount, $items, $receipt_data['receipt_number']);
        
        // Process credit if any was applied
        $creditResult = ['success' => true];
        if ($credit_amount > 0) {
            try {
                $creditResult = processCreditPayment($pid, $credit_amount, $receipt_data['receipt_number']);
                if (!$creditResult['success']) {
                    error_log("Credit processing failed: " . $creditResult['error']);
                }
            } catch (Exception $e) {
                error_log("Credit processing exception: " . $e->getMessage());
                $creditResult = ['success' => false, 'error' => $e->getMessage()];
            }
        }
        
        if ($inventoryResult['success']) {
            $message = xlt('Cash payment processed and inventory updated successfully');
            if ($credit_amount > 0) {
                if ($creditResult['success']) {
                    $message .= ' - Credit applied: $' . number_format($credit_amount, 2);
                } else {
                    $message .= ' - Warning: Credit processing failed: ' . $creditResult['error'];
                }
            }
            
            sendJsonResponse([
                'success' => true, 
                'transaction_id' => $payment_id,
                'receipt_number' => $receipt_data['receipt_number'],
                'change' => $change,
                'credit_amount' => $credit_amount,
                'credit_success' => $creditResult['success'],
                'message' => $message
            ]);
        } else {
            sendJsonResponse([
                'success' => true, 
                'transaction_id' => $payment_id,
                'receipt_number' => $receipt_data['receipt_number'],
                'change' => $change,
                'credit_amount' => $credit_amount,
                'credit_success' => $creditResult['success'],
                'warning' => xlt('Payment processed but inventory update failed: ') . $inventoryResult['error']
            ]);
        }
        
    } catch (Exception $e) {
        sendJsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Update inventory for dispensed items
 */
function updateInventory($pid, $amount, $items, $receipt_number = null) {
    try {
        $quantityColumn = posResolveDrugInventoryQuantityColumn();
        error_log("Using quantity column: " . $quantityColumn);
        
        sqlBeginTrans();
        
        // Get current facility information for Hoover-specific logic
        // Use session facility ID if available (from login selection), otherwise fall back to user's default facility
        $current_facility_id = null;
        if (isset($_SESSION['facilityId']) && !empty($_SESSION['facilityId'])) {
            $current_facility_id = $_SESSION['facilityId'];
        } else {
        $facility_query = "SELECT facility_id FROM users WHERE username = ?";
        $facility_result = sqlFetchArray(sqlStatement($facility_query, array($_SESSION['authUser'])));
            if ($facility_result) {
                $current_facility_id = $facility_result['facility_id'];
            }
        }
        $isHooverFacility = ($current_facility_id == 36);
        
        // Debug: Log facility detection
        error_log("Payment processor: Facility detection - User: " . ($_SESSION['authUser'] ?? 'NOT_SET') . ", Session Facility ID: " . ($_SESSION['facilityId'] ?? 'NOT_SET') . ", Current Facility ID: $current_facility_id, Is Hoover: " . ($isHooverFacility ? 'YES' : 'NO'));
        
        foreach ($items as $item) {
            $itemId = $item['id'];
            $totalQuantity = normalizePosQuantity($item['quantity']);
            $dispenseQuantity = normalizePosQuantity($item['dispense_quantity'] ?? $item['quantity']); // Use dispense quantity, fallback to total quantity
            $administerQuantity = normalizePosQuantity($item['administer_quantity'] ?? 0); // New administer quantity
            
            // Hoover facility logic: For Hoover facility, default dispense is marketplace dispense
            // except for product-specific QOH deduction rules such as phentermine.
            $isMarketplaceDispense = $isHooverFacility && !isHooverMarketplaceDispenseException($item);
            
            error_log("POS Payment Processor - Item processing: " . $item['name']);
            error_log("POS Payment Processor - Hoover facility check: isHooverFacility=$isHooverFacility, isMarketplaceDispense=$isMarketplaceDispense");
            error_log("POS Payment Processor - Hoover logic: Marketplace dispense=" . ($isMarketplaceDispense ? 'YES' : 'NO') . " for item " . ($item['name'] ?? 'Unknown'));
            
            // Debug logging
            error_log("POS Payment Processor - Item: " . $item['name'] . ", Total Qty: " . $totalQuantity . ", Dispense Qty: " . $dispenseQuantity . ", Administer Qty: " . $administerQuantity . ", Item Data: " . json_encode($item));
            error_log("POS Payment Processor - DEBUG: Raw dispense_quantity from frontend: " . ($item['dispense_quantity'] ?? 'NOT_SET') . ", Raw quantity from frontend: " . ($item['quantity'] ?? 'NOT_SET') . ", Raw administer_quantity from frontend: " . ($item['administer_quantity'] ?? 'NOT_SET'));
            
            // Parse item ID to get drug_id and lot_number
            if (preg_match('/drug_(\d+)_lot_(.+)/', $itemId, $matches)) {
                $drug_id = intval($matches[1]); // Ensure it's an integer
                $lot_number = $matches[2];
                
                // Check if this item has integrated remaining dispense
                $hasRemainingDispense = isset($item['has_remaining_dispense']) && $item['has_remaining_dispense'];
                $remainingDispenseItems = $item['remaining_dispense_items'] ?? array();
                $totalRemainingQuantity = $item['total_remaining_quantity'] ?? 0;
                
                error_log("POS Payment Processor - Checking remaining dispense: hasRemainingDispense=$hasRemainingDispense, totalRemainingQuantity=$totalRemainingQuantity, remainingDispenseItems count=" . count($remainingDispenseItems));
                
                // Only process integrated remaining dispense if there are actually remaining quantities available
                if ($hasRemainingDispense && !empty($remainingDispenseItems) && $totalRemainingQuantity > 0) {
                    error_log("POS Payment Processor - Item has remaining dispense integration. Total remaining: $totalRemainingQuantity, Dispense requested: $dispenseQuantity");
                    error_log("POS Payment Processor - Remaining dispense items: " . json_encode($remainingDispenseItems));
                    
                    // Check if this is a different lot dispense
                    $isDifferentLotDispense = isset($item['is_different_lot_dispense']) && $item['is_different_lot_dispense'];
                    
                    error_log("POS Payment Processor - Different lot dispense check: isDifferentLotDispense=" . ($isDifferentLotDispense ? 'true' : 'false') . ", item data: " . json_encode($item));
                    
                    if ($isDifferentLotDispense) {
                        error_log("POS Payment Processor - This is a different lot dispense. Current lot: $lot_number");
                    }
                    
                    // Calculate priority: First use new purchase quantity, then remaining dispense
                    // For Hoover facility with marketplace dispense, use marketplace_dispense_quantity
                    $dispenseQtyForTracking = $dispenseQuantity;
                    if ($isHooverFacility && $isMarketplaceDispense) {
                        $dispenseQtyForTracking = normalizePosQuantity($item['marketplace_dispense_quantity'] ?? $dispenseQuantity);
                        error_log("POS Payment Processor - Hoover Marketplace (integrated): Using marketplace_dispense_quantity: $dispenseQtyForTracking (instead of regular dispense: $dispenseQuantity)");
                    }
                    $newInventoryToUse = min($totalQuantity, $dispenseQtyForTracking); // Use new purchase first
                    $remainingDispenseToUse = $dispenseQtyForTracking - $newInventoryToUse; // Use remaining dispense for the rest
                    
                    // Also calculate administer quantities that need to be deducted from remaining dispense
                    // Account for remaining dispense already used for marketplace dispense
                    $remainingAfterMarketplaceDispense = max(0, $totalRemainingQuantity - $remainingDispenseToUse);
                    $administerFromRemaining = min($administerQuantity, $remainingAfterMarketplaceDispense);
                    
                    error_log("POS Payment Processor - Calculation breakdown:");
                    error_log("  - Total dispense requested: $dispenseQuantity");
                    error_log("  - Total administer requested: $administerQuantity");
                    error_log("  - Total remaining available: $totalRemainingQuantity");
                    error_log("  - Will use from remaining dispense: $remainingDispenseToUse");
                    error_log("  - Will administer from remaining dispense: $administerFromRemaining");
                    error_log("  - Will use from new inventory: $newInventoryToUse");
                    error_log("  - New purchase quantity: $totalQuantity");
                    error_log("  - Is different lot dispense: " . ($isDifferentLotDispense ? 'Yes' : 'No'));
                    
                    // First, update remaining dispense records for dispense quantities (only if we need to use remaining dispense)
                    $totalDeductedForDispense = 0;
                    $maxIterations = 10; // Prevent infinite loops
                    $iteration = 0;
                    
                    if ($remainingDispenseToUse > 0) {
                        error_log("POS Payment Processor - Starting to update remaining dispense records. Total to use: $remainingDispenseToUse");
                        
                        // Keep looping until we've deducted all needed or no more remaining dispense available
                        while ($remainingDispenseToUse > 0 && $iteration < $maxIterations) {
                            $iteration++;
                            error_log("POS Payment Processor - Iteration $iteration: Still need to deduct $remainingDispenseToUse");
                            
                            // Query ALL fresh remaining dispense records for this drug_id to ensure we get current values
                            // Use ADODB for consistency with transaction
                            $allRemainingQuery = "SELECT id, drug_id, lot_number, remaining_quantity FROM pos_remaining_dispense 
                                                 WHERE pid = ? AND drug_id = ? AND remaining_quantity > 0 
                                                 ORDER BY created_date ASC";
                            $allRemainingResult = sqlStatement($allRemainingQuery, array($pid, $drug_id));
                            $allFreshRemainingItems = array();
                            while ($row = sqlFetchArray($allRemainingResult)) {
                                $allFreshRemainingItems[] = $row;
                            }
                            
                            if (empty($allFreshRemainingItems)) {
                                error_log("POS Payment Processor - No more remaining dispense records found. Stopping deduction.");
                                break;
                            }
                            
                            $deductedThisIteration = 0;
                            foreach ($allFreshRemainingItems as $freshItem) {
                            if ($remainingDispenseToUse <= 0) {
                                break;
                            }
                                
                                $remainingItemId = $freshItem['id'];
                                $currentQty = normalizePosQuantity($freshItem['remaining_quantity']);
                                
                                // Only process if there's actually remaining quantity
                                if ($currentQty <= 0) {
                                    continue;
                                }
                                
                                $qtyToDeduct = min($currentQty, $remainingDispenseToUse);
                                
                                error_log("POS Payment Processor - Processing remaining item: ID=$remainingItemId, current_qty=$currentQty (from DB), will_deduct=$qtyToDeduct, lot=" . $freshItem['lot_number']);
                                
                                if ($qtyToDeduct > 0) {
                                    $actualDeducted = updateRemainingDispenseRecord($pid, $freshItem['drug_id'], $freshItem['lot_number'], $qtyToDeduct, false);
                                    if ($actualDeducted !== false && $actualDeducted > 0) {
                                        $remainingDispenseToUse -= $actualDeducted;
                                        $totalDeductedForDispense += $actualDeducted;
                                        $deductedThisIteration += $actualDeducted;
                                        error_log("POS Payment Processor - Updated remaining dispense record ID: $remainingItemId, deducted: $actualDeducted, remaining to use: $remainingDispenseToUse, total deducted so far: $totalDeductedForDispense");
                                    } else {
                                        error_log("POS Payment Processor - Failed to update remaining dispense record ID: $remainingItemId (returned: " . ($actualDeducted === false ? 'false' : $actualDeducted) . ")");
                                    }
                                }
                            }
                            
                            // If we didn't deduct anything this iteration, break to avoid infinite loop
                            if ($deductedThisIteration == 0) {
                                error_log("POS Payment Processor - No deduction made in iteration $iteration. Stopping.");
                                break;
                            }
                        }
                        
                        if ($remainingDispenseToUse > 0) {
                            error_log("POS Payment Processor - WARNING: Could not fully deduct remaining dispense for marketplace dispense. Remaining to use: $remainingDispenseToUse, Total deducted: $totalDeductedForDispense");
                        } else {
                            error_log("POS Payment Processor - Successfully deducted all remaining dispense for marketplace dispense. Total deducted: $totalDeductedForDispense");
                        }
                    } else {
                        error_log("POS Payment Processor - No remaining dispense quantities to use (new purchase covers all dispense)");
                    }
                    
                    // Now update remaining dispense records for administer quantities
                    // Note: We need to query fresh data from database since marketplace dispense may have already deducted some
                    $totalDeductedForAdminister = 0;
                    $administerIteration = 0;
                    $administerMaxIterations = 10;
                    
                    if ($administerFromRemaining > 0) {
                        error_log("POS Payment Processor - Starting to update remaining dispense records for administer. Total to use: $administerFromRemaining");
                        
                        // Keep looping until we've deducted all needed or no more remaining dispense available
                        while ($administerFromRemaining > 0 && $administerIteration < $administerMaxIterations) {
                            $administerIteration++;
                            error_log("POS Payment Processor - Administer iteration $administerIteration: Still need to deduct $administerFromRemaining");
                            
                            // Query fresh remaining dispense data from database to get current quantities after marketplace dispense deductions
                            // Use ADODB for consistency with transaction
                            $freshRemainingQuery = "SELECT id, drug_id, lot_number, remaining_quantity FROM pos_remaining_dispense 
                                                   WHERE pid = ? AND drug_id = ? AND remaining_quantity > 0 
                                                   ORDER BY created_date ASC";
                            $freshRemainingResult = sqlStatement($freshRemainingQuery, array($pid, $drug_id));
                            $freshRemainingItems = array();
                            while ($row = sqlFetchArray($freshRemainingResult)) {
                                $freshRemainingItems[] = $row;
                            }
                            
                            if (empty($freshRemainingItems)) {
                                error_log("POS Payment Processor - No more remaining dispense records found for administer. Stopping deduction.");
                                break;
                            }
                            
                            $deductedThisIteration = 0;
                            foreach ($freshRemainingItems as $freshItem) {
                                if ($administerFromRemaining <= 0) {
                                    break;
                                }
                                
                                $remainingItemId = $freshItem['id'];
                                $remainingItemQty = normalizePosQuantity($freshItem['remaining_quantity']); // Get current quantity from database
                                
                                // Only process if there's actually remaining quantity
                                if ($remainingItemQty <= 0) {
                                    continue;
                                }
                                
                            $qtyToDeduct = min($remainingItemQty, $administerFromRemaining);
                            
                                error_log("POS Payment Processor - Processing remaining item for administer: ID=$remainingItemId, current_qty=$remainingItemQty (from DB), will_deduct=$qtyToDeduct, lot=" . $freshItem['lot_number']);
                            
                            if ($qtyToDeduct > 0) {
                                    $actualDeducted = updateRemainingDispenseRecord($pid, $freshItem['drug_id'], $freshItem['lot_number'], $qtyToDeduct, true);
                                    if ($actualDeducted !== false && $actualDeducted > 0) {
                                        $administerFromRemaining -= $actualDeducted;
                                        $totalDeductedForAdminister += $actualDeducted;
                                        $deductedThisIteration += $actualDeducted;
                                        error_log("POS Payment Processor - Updated remaining dispense record ID: $remainingItemId, deducted for administer: $actualDeducted, remaining to use: $administerFromRemaining, total deducted so far: $totalDeductedForAdminister");
                                    } else {
                                        error_log("POS Payment Processor - Failed to update remaining dispense record ID: $remainingItemId for administer");
                                    }
                                }
                            }
                            
                            // If we didn't deduct anything this iteration, break to avoid infinite loop
                            if ($deductedThisIteration == 0) {
                                error_log("POS Payment Processor - No deduction made in administer iteration $administerIteration. Stopping.");
                                break;
                            }
                        }
                        
                        if ($administerFromRemaining > 0) {
                            error_log("POS Payment Processor - WARNING: Could not fully deduct remaining dispense for administer. Remaining to use: $administerFromRemaining, Total deducted: $totalDeductedForAdminister");
                        } else {
                            error_log("POS Payment Processor - Successfully deducted all remaining dispense for administer. Total deducted: $totalDeductedForAdminister");
                        }
                    } else {
                        error_log("POS Payment Processor - No administer quantities to use from remaining dispense");
                    }
                    
                    // Hoover facility logic: Only deduct administer quantity from inventory, not marketplace dispense
                    if (!shouldDeductDispenseFromInventory($drug_id)) {
                        $inventoryToDeduct = getAdministerInventoryDeductionQuantity($item, $administerFromInventory);
                        error_log("POS Payment Processor - ml form detected: skipping dispense inventory deduction, deducting administer only ($inventoryToDeduct inventory units from $administerFromInventory administered)");
                    } elseif ($isHooverFacility) {
                        if ($isMarketplaceDispense) {
                            // Marketplace dispense: Only deduct administer quantity, skip dispense quantity
                            $administerFromInventory = $administerQuantity - $administerFromRemaining;
                            $inventoryToDeduct = getAdministerInventoryDeductionQuantity($item, $administerFromInventory); // Only deduct administer, not dispense
                            error_log("POS Payment Processor - Hoover Marketplace: Only deducting administer quantity ($inventoryToDeduct inventory units from $administerFromInventory administered) from inventory, skipping dispense quantity");
                        } else {
                            // Regular dispense: Deduct both dispense and administer quantities
                            $administerFromInventory = $administerQuantity - $administerFromRemaining;
                            $inventoryToDeduct = $newInventoryToUse + getAdministerInventoryDeductionQuantity($item, $administerFromInventory);
                            error_log("POS Payment Processor - Hoover Regular: Deducting dispense ($newInventoryToUse) plus administered inventory amount from current lot");
                        }
                    } else {
                        // Non-Hoover facility: Use original logic
                        $administerFromInventory = $administerQuantity - $administerFromRemaining;
                        $inventoryToDeduct = $newInventoryToUse + getAdministerInventoryDeductionQuantity($item, $administerFromInventory);
                        error_log("POS Payment Processor - Non-Hoover: Using standard logic - deducting dispense plus administered inventory amount");
                    }
                    
                    error_log("POS Payment Processor - Deducting from inventory (current lot): $inventoryToDeduct from lot: $lot_number; used from current lot: dispense=$newInventoryToUse, administer=$administerFromInventory; used from remaining: dispense=$remainingDispenseToUse, administer=$administerFromRemaining");
                    
                    // Check if we have sufficient inventory
                    $checkQuery = "SELECT $quantityColumn FROM drug_inventory WHERE drug_id = ? AND lot_number = ?";
                    $checkResult = sqlQuery($checkQuery, array($drug_id, $lot_number));
                    
                    $currentQuantity = 0;
                    if (is_object($checkResult) && method_exists($checkResult, 'FetchRow')) {
                        $checkRow = $checkResult->FetchRow();
                        if ($checkRow && isset($checkRow[$quantityColumn])) {
                            $currentQuantity = normalizePosQuantity($checkRow[$quantityColumn]);
                        }
                    } elseif (is_array($checkResult) && isset($checkResult[$quantityColumn])) {
                        $currentQuantity = normalizePosQuantity($checkResult[$quantityColumn]);
                    }
                    
                    error_log("POS Payment Processor - Current inventory before update: $currentQuantity for drug_id: $drug_id, lot: $lot_number");
                    
                    if ($currentQuantity < $inventoryToDeduct) {
                        throw new Exception("Insufficient inventory for item: " . $item['name'] . " (Lot: " . $lot_number . "). Available: " . $currentQuantity . ", Requested: " . $inventoryToDeduct);
                    }
                    
                    // Update inventory - deduct the calculated amount
                    $updateQuery = "UPDATE drug_inventory SET $quantityColumn = $quantityColumn - ? WHERE drug_id = ? AND lot_number = ?";
                    $result = sqlStatement($updateQuery, array($inventoryToDeduct, $drug_id, $lot_number));
                    
                    // Verify the update was successful
                    $verifyQuery = "SELECT $quantityColumn FROM drug_inventory WHERE drug_id = ? AND lot_number = ?";
                    $verifyResult = sqlQuery($verifyQuery, array($drug_id, $lot_number));
                    
                    $newQuantity = 0;
                    if (is_object($verifyResult) && method_exists($verifyResult, 'FetchRow')) {
                        $verifyRow = $verifyResult->FetchRow();
                        if ($verifyRow && isset($verifyRow[$quantityColumn])) {
                            $newQuantity = normalizePosQuantity($verifyRow[$quantityColumn]);
                        }
                    } elseif (is_array($verifyResult) && isset($verifyResult[$quantityColumn])) {
                        $newQuantity = normalizePosQuantity($verifyResult[$quantityColumn]);
                    }
                    
                    error_log("POS Payment Processor - Updated inventory for drug_id: $drug_id, lot: $lot_number, deducted: $inventoryToDeduct, new QOH: $newQuantity");
                    
                    if ($newQuantity != ($currentQuantity - $inventoryToDeduct)) {
                        error_log("POS Payment Processor - WARNING: Inventory update verification failed. Expected: " . ($currentQuantity - $inventoryToDeduct) . ", Actual: $newQuantity");
                    }
                    
                    // Check if lot is now empty and remove it
                    if ($newQuantity <= 0) {
                        error_log("POS Payment Processor - Lot is now empty, removing lot record for drug_id: $drug_id, lot: $lot_number");
                        sqlStatement("DELETE FROM drug_inventory WHERE drug_id = ? AND lot_number = ?", array($drug_id, $lot_number));
                    }
                    
                    // Verify that all remaining dispense was properly deducted before tracking new purchase
                    // Use ADODB for consistency with transaction
                    $verifyRemainingQuery = "SELECT SUM(remaining_quantity) as total_remaining FROM pos_remaining_dispense 
                                            WHERE pid = ? AND drug_id = ? AND remaining_quantity > 0";
                    $verifyResult = sqlQuery($verifyRemainingQuery, array($pid, $drug_id));
                    $verifyData = null;
                    if (is_object($verifyResult) && method_exists($verifyResult, 'FetchRow')) {
                        $verifyData = $verifyResult->FetchRow();
                    } elseif (is_array($verifyResult)) {
                        $verifyData = $verifyResult;
                    }
                    $actualRemainingAfterDeduction = $verifyData ? normalizePosQuantity($verifyData['total_remaining']) : 0;
                    
                    error_log("POS Payment Processor - Verification: Expected remaining after deduction: 0, Actual remaining in DB: $actualRemainingAfterDeduction");
                    
                    if ($actualRemainingAfterDeduction > 0) {
                        error_log("POS Payment Processor - WARNING: There is still remaining dispense ($actualRemainingAfterDeduction) after deductions. This should have been fully used.");
                    }
                    
                    // Track remaining dispense for new purchase if we didn't use all of it
                    // Note: we now separate dispensed vs administered used from the new purchase
                    // For Hoover facility, use marketplace_dispense_quantity for tracking
                    $dispenseQtyForTracking = $newInventoryToUse;
                    if ($isHooverFacility && $isMarketplaceDispense) {
                        // Use marketplace dispense quantity for tracking (already calculated above)
                        $dispenseQtyForTracking = $newInventoryToUse; // $newInventoryToUse already uses marketplace_dispense_quantity
                        error_log("POS Payment Processor - Hoover Marketplace (integrated): Tracking with marketplace dispense quantity: $dispenseQtyForTracking");
                    }
                    
                    $totalUsedFromNewInventory = $dispenseQtyForTracking + $administerFromInventory;
                    error_log("POS Payment Processor - Integrated logic: checking remaining dispense tracking - newInventoryToUse=$dispenseQtyForTracking, administerFromInventory=$administerFromInventory, totalUsedFromNewInventory=$totalUsedFromNewInventory, totalQuantity=$totalQuantity, condition=" . ($totalUsedFromNewInventory < $totalQuantity ? 'true' : 'false'));
                    
                    // For Hoover facility, allow QTY to be greater than Marketplace Dispense + Administer
                    if ($totalUsedFromNewInventory < $totalQuantity) {
                        $remainingQuantity = $totalQuantity - $totalUsedFromNewInventory;
                        error_log("POS Payment Processor - Integrated logic: tracking remaining dispense - total=$totalQuantity, dispensed=$dispenseQtyForTracking, administered=$administerFromInventory, totalUsed=$totalUsedFromNewInventory, remaining=$remainingQuantity for drug_id: $drug_id, lot: $lot_number");
                        trackRemainingDispense($pid, $drug_id, $lot_number, $totalQuantity, $dispenseQtyForTracking, $administerFromInventory, $remainingQuantity, $item, $receipt_number);
                    } else if ($totalUsedFromNewInventory == $totalQuantity) {
                        // Fully used new purchase in this POS; record it with remaining 0
                        error_log("POS Payment Processor - Integrated logic: fully used purchase; recording with remaining=0 - total=$totalQuantity, dispensed=$dispenseQtyForTracking, administered=$administerFromInventory");
                        trackRemainingDispense($pid, $drug_id, $lot_number, $totalQuantity, $dispenseQtyForTracking, $administerFromInventory, 0, $item, $receipt_number);
                    } else {
                        // For Hoover facility, allow QTY > Marketplace Dispense + Administer
                        // Track the remaining quantity (QTY - Marketplace Dispense - Administer) as remaining dispense
                        if ($isHooverFacility && $isMarketplaceDispense) {
                            $remainingQuantity = $totalQuantity - $totalUsedFromNewInventory;
                            error_log("POS Payment Processor - Hoover Marketplace (integrated): QTY ($totalQuantity) > Marketplace Dispense ($dispenseQtyForTracking) + Administer ($administerFromInventory). Remaining quantity ($remainingQuantity) will be tracked as remaining dispense.");
                            trackRemainingDispense($pid, $drug_id, $lot_number, $totalQuantity, $dispenseQtyForTracking, $administerFromInventory, $remainingQuantity, $item, $receipt_number);
                    } else {
                        // No usage from the new purchase; still create a remaining-dispense record for the new purchase with full remaining
                        $remainingAll = $totalQuantity - $totalUsedFromNewInventory; // equals totalQuantity here
                        error_log("POS Payment Processor - Integrated logic: full remaining from new purchase - totalUsedFromNewInventory=$totalUsedFromNewInventory, remainingAll=$remainingAll");
                            trackRemainingDispense($pid, $drug_id, $lot_number, $totalQuantity, $dispenseQtyForTracking, $administerFromInventory, $remainingAll, $item, $receipt_number);
                        }
                    }
                    
                    continue; // Skip the regular inventory update logic
                } else if ($hasRemainingDispense && !empty($remainingDispenseItems) && $totalRemainingQuantity == 0) {
                    // There are remaining dispense items but they're all completed (remaining_quantity = 0)
                    // Treat this as a regular new purchase
                    error_log("POS Payment Processor - Remaining dispense items exist but all are completed (remaining_quantity = 0). Treating as regular new purchase.");
                }
                
                // First check if we have sufficient inventory for dispense quantity
                $checkQuery = "SELECT $quantityColumn FROM drug_inventory WHERE drug_id = ? AND lot_number = ?";
                $checkResult = sqlQuery($checkQuery, array($drug_id, $lot_number));
                
                // Handle ADORecordSet result
                $currentQuantity = 0;
                if (is_object($checkResult) && method_exists($checkResult, 'FetchRow')) {
                    $checkRow = $checkResult->FetchRow();
                    if ($checkRow && isset($checkRow[$quantityColumn])) {
                        $currentQuantity = intval($checkRow[$quantityColumn]);
                    }
                } elseif (is_array($checkResult) && isset($checkResult[$quantityColumn])) {
                    $currentQuantity = intval($checkResult[$quantityColumn]);
                }
                
                // Hoover facility logic: Calculate what to deduct based on dispense type
                $administerInventoryDeduction = getAdministerInventoryDeductionQuantity($item, $administerQuantity);
                if (!shouldDeductDispenseFromInventory($drug_id)) {
                    $totalToDeduct = $administerInventoryDeduction;
                    error_log("POS Payment Processor - ml form detected: deducting only administered inventory amount ($administerInventoryDeduction) from inventory");
                } elseif ($isHooverFacility) {
                    if ($isMarketplaceDispense) {
                        // Marketplace dispense: Only deduct administer quantity, skip dispense quantity
                        $totalToDeduct = $administerInventoryDeduction;
                        error_log("POS Payment Processor - Hoover Marketplace: Only deducting administer inventory amount ($administerInventoryDeduction), skipping dispense quantity ($dispenseQuantity)");
                    } else {
                        // Regular dispense: Deduct both dispense and administer quantities
                        $totalToDeduct = $dispenseQuantity + $administerInventoryDeduction;
                        error_log("POS Payment Processor - Hoover Regular: Deducting dispense ($dispenseQuantity) and administer inventory amount ($administerInventoryDeduction)");
                    }
                } else {
                    // Non-Hoover facility: Use original logic
                    $totalToDeduct = $dispenseQuantity + $administerInventoryDeduction;
                    error_log("POS Payment Processor - Non-Hoover: Using standard logic - deducting dispense and administer inventory amount");
                }
                
                // Debug: Log the calculated values
                error_log("POS Payment Processor - DEBUG: Item: " . $item['name'] . ", Dispense: $dispenseQuantity, Administer: $administerQuantity, Total to deduct: $totalToDeduct, Is Hoover: " . ($isHooverFacility ? 'Yes' : 'No') . ", Is Marketplace: " . ($isMarketplaceDispense ? 'Yes' : 'No'));
                error_log("POS Payment Processor - Calculation details: For Hoover=" . ($isHooverFacility ? 'Yes' : 'No') . ", Marketplace=" . ($isMarketplaceDispense ? 'Yes' : 'No') . ", Administer=$administerQuantity, Dispense=$dispenseQuantity");
                
                if ($totalToDeduct > 0) {
                    if ($currentQuantity < $totalToDeduct) {
                        $errorMsg = "Insufficient inventory for item: " . $item['name'] . " (Lot: " . $lot_number . "). Available: " . $currentQuantity;
                        if ($isHooverFacility) {
                            $errorMsg .= ", Requested to administer: " . $administerQuantity . " (Hoover facility - dispense handled via marketplace)";
                        } else {
                            $errorMsg .= ", Requested to dispense: " . $dispenseQuantity . ", Requested to administer: " . $administerQuantity;
                        }
                        $errorMsg .= ", Total to deduct: " . $totalToDeduct;
                        throw new Exception($errorMsg);
                    }
                    
                    // Update inventory quantity - deduct calculated amount
                    if ($isHooverFacility) {
                        error_log("POS Payment Processor - Hoover facility: Updating inventory for drug_id: " . $drug_id . ", lot: " . $lot_number . ", deducting only administer quantity: " . $totalToDeduct . " (dispense handled via marketplace)");
                    } else {
                        error_log("POS Payment Processor - Regular facility: Updating inventory for drug_id: " . $drug_id . ", lot: " . $lot_number . ", deducting: " . $totalToDeduct . " (dispense: " . $dispenseQuantity . " + administer: " . $administerQuantity . ") out of " . $totalQuantity . " (total qty)");
                    }
                    $updateQuery = "UPDATE drug_inventory SET $quantityColumn = $quantityColumn - ? WHERE drug_id = ? AND lot_number = ?";
                    $result = sqlStatement($updateQuery, array($totalToDeduct, $drug_id, $lot_number));
                    
                    // Verify the update was successful
                    $verifyQuery = "SELECT $quantityColumn FROM drug_inventory WHERE drug_id = ? AND lot_number = ?";
                    $verifyResult = sqlQuery($verifyQuery, array($drug_id, $lot_number));
                    
                    $newQuantity = 0;
                    if (is_object($verifyResult) && method_exists($verifyResult, 'FetchRow')) {
                        $verifyRow = $verifyResult->FetchRow();
                        if ($verifyRow && isset($verifyRow[$quantityColumn])) {
                            $newQuantity = intval($verifyRow[$quantityColumn]);
                        }
                    } elseif (is_array($verifyResult) && isset($verifyResult[$quantityColumn])) {
                        $newQuantity = intval($verifyResult[$quantityColumn]);
                    }
                    
                    error_log("POS Payment Processor - Inventory update result: drug_id=$drug_id, lot=$lot_number, deducted=$totalToDeduct, old_qty=$currentQuantity, new_qty=$newQuantity");
                } else {
                    error_log("POS Payment Processor - No dispense or administer quantity (both 0), skipping inventory deduction for drug_id: " . $drug_id . ", lot: " . $lot_number);
                }
                
                // Verify the update was successful by checking the new quantity (only if we updated inventory)
                if ($totalToDeduct > 0) {
                $verifyQuery = "SELECT $quantityColumn FROM drug_inventory WHERE drug_id = ? AND lot_number = ?";
                $verifyResult = sqlQuery($verifyQuery, array($drug_id, $lot_number));
                
                $newQuantity = 0;
                if (is_object($verifyResult) && method_exists($verifyResult, 'FetchRow')) {
                    $verifyRow = $verifyResult->FetchRow();
                    if ($verifyRow && isset($verifyRow[$quantityColumn])) {
                        $newQuantity = intval($verifyRow[$quantityColumn]);
                    }
                } elseif (is_array($verifyResult) && isset($verifyResult[$quantityColumn])) {
                    $newQuantity = intval($verifyResult[$quantityColumn]);
                }
                
                    if ($newQuantity != ($currentQuantity - $totalToDeduct)) {
                    throw new Exception("Failed to update inventory for item: " . $item['name'] . " (Lot: " . $lot_number . ")");
                    }
                    
                    // Check if lot is now empty and remove it
                    if ($newQuantity <= 0) {
                        error_log("POS Payment Processor - Lot is now empty, removing lot record for drug_id: $drug_id, lot: $lot_number");
                        sqlStatement("DELETE FROM drug_inventory WHERE drug_id = ? AND lot_number = ?", array($drug_id, $lot_number));
                    }
                } else {
                    // If no dispense or administer quantity, use current quantity for remaining dispense calculation
                    $newQuantity = $currentQuantity;
                }
                
                // Track remaining dispense based on separate dispensed/administered usage
                // For Hoover facility with marketplace dispense, track marketplace dispense quantity
                $dispenseQtyToTrack = $dispenseQuantity;
                if ($isHooverFacility && $isMarketplaceDispense) {
                    // For Hoover marketplace dispense, use marketplace_dispense_quantity if available
                    $dispenseQtyToTrack = intval($item['marketplace_dispense_quantity'] ?? $dispenseQuantity);
                    error_log("POS Payment Processor - Hoover Marketplace: Tracking marketplace dispense quantity: $dispenseQtyToTrack (instead of regular dispense: $dispenseQuantity)");
                }
                
                $totalUsed = $dispenseQtyToTrack + $administerQuantity;
                error_log("POS Payment Processor - Checking remaining dispense tracking: totalQuantity=$totalQuantity, dispenseQuantity=$dispenseQtyToTrack, administerQuantity=$administerQuantity, totalUsed=$totalUsed, condition=" . ($totalUsed < $totalQuantity ? 'true' : 'false') . ", isHoover=$isHooverFacility, isMarketplace=$isMarketplaceDispense");
                
                // For Hoover facility, allow QTY to be greater than Marketplace Dispense + Administer
                // Track dispense even if totalUsed < totalQuantity or totalUsed >= totalQuantity
                if ($totalUsed < $totalQuantity) {
                    $remainingQuantity = $totalQuantity - $totalUsed;
                    error_log("POS Payment Processor - Tracking remaining dispense: total=$totalQuantity, dispensed=$dispenseQtyToTrack, administered=$administerQuantity, totalUsed=$totalUsed, remaining=$remainingQuantity for drug_id: $drug_id, lot: $lot_number");
                    trackRemainingDispense($pid, $drug_id, $lot_number, $totalQuantity, $dispenseQtyToTrack, $administerQuantity, $remainingQuantity, $item, $receipt_number);
                } else if ($totalUsed == $totalQuantity) {
                    // Fully used new purchase in this POS; record it with remaining 0 so aggregates (Total Bought / Dispensed / Administered) update
                    error_log("POS Payment Processor - Fully used purchase; recording with remaining=0 for aggregation: total=$totalQuantity, dispensed=$dispenseQtyToTrack, administered=$administerQuantity");
                    trackRemainingDispense($pid, $drug_id, $lot_number, $totalQuantity, $dispenseQtyToTrack, $administerQuantity, 0, $item, $receipt_number);
                } else {
                    // For Hoover facility, allow QTY > Marketplace Dispense + Administer
                    // Track the remaining quantity (QTY - Marketplace Dispense - Administer) as remaining dispense
                    if ($isHooverFacility && $isMarketplaceDispense) {
                        $remainingQuantity = $totalQuantity - $totalUsed;
                        error_log("POS Payment Processor - Hoover Marketplace: QTY ($totalQuantity) > Marketplace Dispense ($dispenseQtyToTrack) + Administer ($administerQuantity). Remaining quantity ($remainingQuantity) will be tracked as remaining dispense.");
                        trackRemainingDispense($pid, $drug_id, $lot_number, $totalQuantity, $dispenseQtyToTrack, $administerQuantity, $remainingQuantity, $item, $receipt_number);
                } else {
                    error_log("POS Payment Processor - ERROR: total_used ($totalUsed) cannot be greater than total_quantity ($totalQuantity) for drug_id: $drug_id, lot: $lot_number");
                    }
                }
                
                // Update remaining dispense record if this is a remaining dispense item
                if (isset($item['is_remaining_dispense']) && $item['is_remaining_dispense']) {
                    // Update dispense quantities
                    if ($dispenseQuantity > 0) {
                        updateRemainingDispenseRecord($pid, $drug_id, $lot_number, $dispenseQuantity, false);
                    }
                    // Update administer quantities
                    if ($administerQuantity > 0) {
                        updateRemainingDispenseRecord($pid, $drug_id, $lot_number, $administerQuantity, true);
                        
                        // Record daily administration for tracking
                        recordDailyAdminister($pid, $drug_id, $lot_number, $administerQuantity);
                    }
                }
                
                // Record daily administration for ALL administer quantities (including new purchases)
                if ($administerQuantity > 0) {
                    error_log("POS Payment Processor - Recording daily administration for new purchase: drug_id=$drug_id, lot=$lot_number, qty=$administerQuantity");
                    recordDailyAdminister($pid, $drug_id, $lot_number, $administerQuantity);
                }
                
                // Log the inventory transaction (commented out due to table structure issues)
                // $logQuery = "INSERT INTO drug_inventory_log SET 
                //     drug_id = ?, 
                //     lot_number = ?, 
                //     quantity_change = ?, 
                //     action = 'dispense', 
                //     user_id = ?, 
                //     patient_id = ?, 
                //     timestamp = NOW(), 
                //     notes = ?";
                // 
                // $notes = "POS dispense - " . $dispenseQuantity . " units for patient " . $pid;
                // sqlStatement($logQuery, array($drug_id, $lot_number, -$dispenseQuantity, $_SESSION['authUser'], $pid, $notes));
                
                // Simple logging to error log instead
            
            }
        }
        
        // Record transaction in pos_transactions table for all items (optional; skip if table missing)
        try {
            $tableExists = posTableExists('pos_transactions');
            if (!$tableExists) {
                // Table not present; skip recording and continue
                error_log("POS Transaction Recording skipped: pos_transactions table does not exist");
                goto skip_record_purchase_txn;
            }
            
            // Determine transaction type based on items
            $transactionType = 'purchase'; // Default
            $hasDispenseOnly = false;
            $hasNewPurchase = false;
            $hasAlternateLotDispense = false;
            
            foreach ($items as $item) {
                $itemQuantity = normalizePosQuantity($item['quantity'] ?? 0);
                $itemDispenseQuantity = normalizePosQuantity($item['dispense_quantity'] ?? 0);
                $isDifferentLotDispense = $item['is_different_lot_dispense'] ?? false;
                
                if ($itemQuantity == 0 && $itemDispenseQuantity > 0) {
                    $hasDispenseOnly = true;
                } elseif ($itemQuantity > 0) {
                    $hasNewPurchase = true;
                    // Also check if there's dispense quantity in the same transaction
                    if ($itemDispenseQuantity > 0) {
                        $hasDispenseOnly = true;
                    }
                }
                
                if ($isDifferentLotDispense) {
                    $hasAlternateLotDispense = true;
                }
            }
            
            if ($hasDispenseOnly && !$hasNewPurchase) {
                $transactionType = $hasAlternateLotDispense ? 'alternate_lot_dispense' : 'dispense';
            } elseif ($hasNewPurchase && $hasDispenseOnly) {
                $transactionType = $hasAlternateLotDispense ? 'purchase_and_alternate_dispense' : 'purchase_and_dispense';
            } elseif ($hasNewPurchase) {
                $transactionType = 'purchase';
            }
            
            // Debug logging for transaction type detection
            error_log("POS Transaction Type Detection - hasDispenseOnly: " . ($hasDispenseOnly ? 'true' : 'false') . ", hasNewPurchase: " . ($hasNewPurchase ? 'true' : 'false') . ", hasAlternateLotDispense: " . ($hasAlternateLotDispense ? 'true' : 'false') . ", Final Type: $transactionType");
            
            // Debug logging for alternate lot dispense detection
            foreach ($items as $item) {
                $isDifferentLotDispense = $item['is_different_lot_dispense'] ?? false;
                if ($isDifferentLotDispense) {
                    error_log("POS Transaction - Alternate Lot Dispense detected for item: " . ($item['name'] ?? 'Unknown') . ", lot: " . ($item['lot_number'] ?? 'N/A'));
                }
            }
            
            // Clean the items data for database insertion
            $cleanItems = array();
            foreach ($items as $item) {
                // Extract drug_id and lot_number from item ID if not directly available
                $drug_id = $item['drug_id'] ?? null;
                $lot_number = $item['lot_number'] ?? null;
                
                // If not directly available, try to extract from item ID
                if (!$drug_id || !$lot_number) {
                    if (preg_match('/drug_(\d+)_lot_(.+)/', $item['id'], $matches)) {
                        $drug_id = intval($matches[1]);
                        $lot_number = $matches[2];
                    }
                }
                
                // Fallback to lot field if available
                if (!$lot_number && isset($item['lot'])) {
                    $lot_number = $item['lot'];
                }
                
                $cleanItems[] = array(
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'display_name' => $item['display_name'],
                    'drug_id' => $drug_id,
                    'lot_number' => $lot_number,
                    'form' => $item['form'] ?? '',
                    'dose_option_mg' => $item['dose_option_mg'] ?? ($item['semaglutide_dose_mg'] ?? ''),
                    'quantity' => normalizePosQuantity($item['quantity']),
                    'dispense_quantity' => normalizePosQuantity($item['dispense_quantity']),
                    'administer_quantity' => normalizePosQuantity($item['administer_quantity'] ?? 0),
                    'price' => (float) ($item['price'] ?? 0),
                    'original_price' => (float) ($item['original_price'] ?? ($item['price'] ?? 0)),
                    'line_total' => (float) ($item['line_total'] ?? 0),
                    'original_line_total' => (float) ($item['original_line_total'] ?? 0),
                    'line_discount' => (float) ($item['line_discount'] ?? 0),
                    'has_discount' => !empty($item['has_discount']),
                    'discount_info' => $item['discount_info'] ?? null,
                    'prepay_selected' => !empty($item['prepay_selected']),
                    'prepay_date' => (string) ($item['prepay_date'] ?? ''),
                    'prepay_sale_reference' => (string) ($item['prepay_sale_reference'] ?? ''),
                    'is_remaining_dispense' => $item['is_remaining_dispense'] ?? false,
                    'is_different_lot_dispense' => $item['is_different_lot_dispense'] ?? false,
                    'has_remaining_dispense' => $item['has_remaining_dispense'] ?? false
                );
            }
            
            $jsonData = json_encode($cleanItems, JSON_UNESCAPED_SLASHES);
            $timestamp = date('Y-m-d H:i:s');
            
            // Get payment method from input
            $payment_method = $input['payment_method'] ?? $input['method'] ?? 'cash';
            
            // Debug logging for transaction recording
            error_log("POS Transaction Recording - Clean items: " . json_encode($cleanItems));
            error_log("POS Transaction Recording - Transaction type: $transactionType, Amount: $amount, Receipt: $receipt_number, Payment method: $payment_method");
            
            posInsertTransaction([
                'pid' => $pid,
                'receipt_number' => $receipt_number,
                'transaction_type' => $transactionType,
                'payment_method' => $payment_method,
                'amount' => $amount,
                'items' => $jsonData,
                'created_date' => $timestamp,
                'user_id' => $_SESSION['authUserID'] ?? $_SESSION['authUser'] ?? 'system',
                'price_override_notes' => trim((string) ($input['price_override_notes'] ?? '')),
                'patient_number' => $input['patient_number'] ?? null,
            ]);
            
            error_log("POS Transaction recorded successfully: $receipt_number, type: $transactionType, amount: $amount");
        } catch (Exception $e) {
            error_log("Warning: Could not record POS transaction: " . $e->getMessage());
            // Continue with the transaction even if recording fails
        }
        skip_record_purchase_txn:
        
        sqlCommitTrans();
        return ['success' => true];
        
    } catch (Exception $e) {
        sqlRollbackTrans();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Track remaining dispense quantities for partial dispenses
 */
function trackRemainingDispense($pid, $drug_id, $lot_number, $totalQuantity, $dispensedUsed, $administeredUsed, $remainingQuantity, $item, $receipt_number = null) {
    global $input;
    ensurePosRemainingDispenseSchema();

    $totalQuantity = normalizePosQuantity($totalQuantity);
    $dispensedUsed = normalizePosQuantity($dispensedUsed);
    $administeredUsed = normalizePosQuantity($administeredUsed);
    $remainingQuantity = normalizePosQuantity($remainingQuantity);
    
    // Use provided receipt number or fallback to input or generate one
    if (!$receipt_number) {
        $receipt_number = posResolveReceiptNumber($input['receipt_number'] ?? ($input['invoice_number'] ?? null), 'RCPT', $pid);
    }
    $timestamp = date('Y-m-d H:i:s');
    
    // First, check if there's already a record with remaining quantity > 0
    $existingQuery = "SELECT id, total_quantity, dispensed_quantity, administered_quantity, remaining_quantity FROM pos_remaining_dispense 
                      WHERE pid = ? AND drug_id = ? AND lot_number = ? AND remaining_quantity > 0";
    $existingResult = sqlQuery($existingQuery, array($pid, $drug_id, $lot_number));
    
    // Handle ADORecordSet result
    $existingData = null;
    if (is_object($existingResult) && method_exists($existingResult, 'FetchRow')) {
        $existingData = $existingResult->FetchRow();
    } elseif (is_array($existingResult)) {
        $existingData = $existingResult;
    }
    
    // If no active record found, look for the most recent completed record for the same product/lot
    if (!$existingData) {
        $completedQuery = "SELECT id, dispensed_quantity, remaining_quantity, total_quantity FROM pos_remaining_dispense 
                          WHERE pid = ? AND drug_id = ? AND lot_number = ? AND remaining_quantity = 0 
                          ORDER BY last_updated DESC LIMIT 1";
        $completedResult = sqlQuery($completedQuery, array($pid, $drug_id, $lot_number));
        
        if (is_object($completedResult) && method_exists($completedResult, 'FetchRow')) {
            $existingData = $completedResult->FetchRow();
        } elseif (is_array($completedResult)) {
            $existingData = $completedResult;
        }
    }
    
    if ($existingData) {
        // Always create a NEW record for each transaction to maintain proper historical tracking
        // This ensures each transaction is displayed separately in the dispense tracking
        error_log("trackRemainingDispense - DEBUG VALUES BEFORE INSERT: pid=$pid, drug_id=$drug_id, lot=$lot_number, totalQuantity=$totalQuantity, dispensedUsed=$dispensedUsed, administeredUsed=$administeredUsed, remainingQuantity=$remainingQuantity, receipt=$receipt_number");
        
        sqlStatement("INSERT INTO pos_remaining_dispense 
                     (pid, drug_id, lot_number, total_quantity, dispensed_quantity, administered_quantity, remaining_quantity, receipt_number, created_date, last_updated) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", 
                     array($pid, $drug_id, $lot_number, $totalQuantity, $dispensedUsed, $administeredUsed, $remainingQuantity, $receipt_number, $timestamp, $timestamp));
        
        error_log("trackRemainingDispense - Created new record for transaction: drug_id=$drug_id, lot=$lot_number, receipt=$receipt_number, totalQty=$totalQuantity, dispensed=$dispensedUsed, remaining=$remainingQuantity");
    } else {
        // Insert new record
        error_log("trackRemainingDispense - DEBUG VALUES BEFORE INSERT (NEW): pid=$pid, drug_id=$drug_id, lot=$lot_number, totalQuantity=$totalQuantity, dispensedUsed=$dispensedUsed, administeredUsed=$administeredUsed, remainingQuantity=$remainingQuantity, receipt=$receipt_number");
        
        sqlStatement("INSERT INTO pos_remaining_dispense 
                     (pid, drug_id, lot_number, total_quantity, dispensed_quantity, administered_quantity, remaining_quantity, receipt_number, created_date, last_updated) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", 
                     array($pid, $drug_id, $lot_number, $totalQuantity, $dispensedUsed, $administeredUsed, $remainingQuantity, $receipt_number, $timestamp, $timestamp));
        
        error_log("trackRemainingDispense - Created first record for product: drug_id=$drug_id, lot=$lot_number, receipt=$receipt_number, totalQty=$totalQuantity, dispensed=$dispensedUsed, remaining=$remainingQuantity");
    }
}

/**
 * Update remaining dispense record when dispensing remaining quantities
 */
function updateRemainingDispenseRecord($pid, $drug_id, $lot_number, $quantityToDeduct, $isAdminister = false) {
    global $pdo;
    ensurePosRemainingDispenseSchema();
    $timestamp = date('Y-m-d H:i:s');

    $normalizedLot = trim((string) $lot_number);
    $resultData = null;
    try {
        // Prefer the exact lot that POS/backdate tracking is tied to. Falling back to any lot was
        // causing administration to reduce the wrong record and leave the visible lot unchanged.
        $query = "SELECT id, remaining_quantity, lot_number, dispensed_quantity, administered_quantity
                  FROM pos_remaining_dispense
                  WHERE pid = ? AND drug_id = ? AND lot_number = ? AND remaining_quantity > 0
                  ORDER BY created_date ASC, id ASC
                  LIMIT 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$pid, $drug_id, $normalizedLot]);
        $resultData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$resultData && $normalizedLot === '') {
            $fallbackQuery = "SELECT id, remaining_quantity, lot_number, dispensed_quantity, administered_quantity
                              FROM pos_remaining_dispense
                              WHERE pid = ? AND drug_id = ? AND remaining_quantity > 0
                              ORDER BY created_date ASC, id ASC
                              LIMIT 1";
            $fallbackStmt = $pdo->prepare($fallbackQuery);
            $fallbackStmt->execute([$pid, $drug_id]);
            $resultData = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log("updateRemainingDispenseRecord - Database error: " . $e->getMessage());
        return false;
    }
    
    if ($resultData && isset($resultData['id'])) {
        $recordId = intval($resultData['id']);
        $currentRemaining = normalizePosQuantity($resultData['remaining_quantity']);
        $recordLotNumber = $resultData['lot_number'] ?? $lot_number;
        $currentDispensed = normalizePosQuantity($resultData['dispensed_quantity'] ?? 0);
        $currentAdministered = normalizePosQuantity($resultData['administered_quantity'] ?? 0);
        $quantityToDeduct = normalizePosQuantity($quantityToDeduct);
        
        // Ensure we don't deduct more than available (prevent negative values)
        $actualDeduction = min($quantityToDeduct, $currentRemaining);
        $newRemainingQuantity = max(0, $currentRemaining - $actualDeduction);
        $newDispensed = $currentDispensed;
        $newAdministered = $currentAdministered;
        if ($isAdminister) {
            $newAdministered += $actualDeduction;
        } else {
            $newDispensed += $actualDeduction;
        }
        
        error_log("updateRemainingDispenseRecord - Record ID: $recordId, Current: $currentRemaining, Requested deduction: $quantityToDeduct, Actual deduction: $actualDeduction, New remaining: $newRemainingQuantity, Lot: $recordLotNumber, New dispensed: $newDispensed, New administered: $newAdministered");
        
        try {
            $updateQuery = "UPDATE pos_remaining_dispense
                            SET remaining_quantity = ?, dispensed_quantity = ?, administered_quantity = ?, last_updated = ?
                            WHERE id = ?";
            $updateStmt = $pdo->prepare($updateQuery);
            $updateStmt->execute([$newRemainingQuantity, $newDispensed, $newAdministered, $timestamp, $recordId]);
            
            // Verify the update
            $verifyQuery = "SELECT remaining_quantity, dispensed_quantity, administered_quantity FROM pos_remaining_dispense WHERE id = ?";
            $verifyStmt = $pdo->prepare($verifyQuery);
            $verifyStmt->execute([$recordId]);
            $verifyData = $verifyStmt->fetch(PDO::FETCH_ASSOC);
            $verifiedRemaining = $verifyData ? normalizePosQuantity($verifyData['remaining_quantity']) : -1;
            $verifiedDispensed = $verifyData ? normalizePosQuantity($verifyData['dispensed_quantity']) : -1;
            $verifiedAdministered = $verifyData ? normalizePosQuantity($verifyData['administered_quantity']) : -1;
            
            error_log("updateRemainingDispenseRecord - Verification: Expected remaining: $newRemainingQuantity, Actual remaining: $verifiedRemaining, Expected dispensed: $newDispensed, Actual dispensed: $verifiedDispensed, Expected administered: $newAdministered, Actual administered: $verifiedAdministered");
            
            if (
                abs($verifiedRemaining - $newRemainingQuantity) < 0.0001 &&
                abs($verifiedDispensed - $newDispensed) < 0.0001 &&
                abs($verifiedAdministered - $newAdministered) < 0.0001
            ) {
                // Return the actual amount deducted (in case we couldn't deduct the full amount)
                return $actualDeduction;
            } else {
                error_log("updateRemainingDispenseRecord - WARNING: Update verification failed for record ID $recordId");
                return false;
            }
        } catch (Exception $e) {
            error_log("updateRemainingDispenseRecord - Update error: " . $e->getMessage());
            return false;
        }
    } else {
        error_log("updateRemainingDispenseRecord - No record found for pid=$pid, drug_id=$drug_id with remaining_quantity > 0");
    }
    
    return false;
}

/**
 * Complete dispense without payment - for remaining dispense items
 */
function completeDispense($pid, $items) {
    global $input;
    
    error_log("completeDispense - FUNCTION CALLED - PID: $pid, Items count: " . count($items));
    error_log("completeDispense - Items data: " . json_encode($items));
    
    // Validate input
    if (!$pid || empty($items)) {
        error_log("completeDispense - ERROR: Invalid input data - pid=$pid, items empty=" . (empty($items) ? 'yes' : 'no'));
        sendJsonResponse(['success' => false, 'error' => xlt('Invalid input data')], 400);
    }
    
    // TEMPORARY FIX: Skip transaction handling to avoid timeout issue
    // Start transaction
    error_log("completeDispense - Skipping SQL transaction (using direct queries)");
    // sqlBeginTrans(); // DISABLED - causing timeout
    
    error_log("completeDispense - About to call processDispenseItems");
    // Process the dispense
    try {
        $result = processDispenseItems($pid, $items);
        error_log("completeDispense - processDispenseItems returned: " . json_encode($result));
    } catch (Exception $e) {
        error_log("completeDispense - ERROR in processDispenseItems: " . $e->getMessage());
        // sqlRollbackTrans(); // DISABLED
        sendJsonResponse(['success' => false, 'error' => 'Processing error: ' . $e->getMessage()], 500);
    }
    
    if ($result['success']) {
        // Commit transaction
        // sqlCommitTrans(); // DISABLED
        
        sendJsonResponse([
            'success' => true, 
            'message' => xlt('Dispense completed successfully'),
            'receipt_number' => $result['receipt_number']
        ]);
    } else {
        // Rollback transaction on error
        // sqlRollbackTrans(); // DISABLED
        
        error_log("Error completing dispense: " . ($result['error'] ?? 'Unknown error'));
        sendJsonResponse(['success' => false, 'error' => xlt('Error completing dispense: ') . ($result['error'] ?? 'Unknown error')]);
    }
}

/**
 * Check daily administration limit for a specific medication
 */
function checkAdministerLimit($pid, $input) {
    global $pdo;
    error_log("checkAdministerLimit - Starting function with PID: $pid");
    $drug_id = intval($input['drug_id'] ?? 0);
    $lot_number = $input['lot_number'] ?? '';
    $requested_quantity = normalizePosQuantity($input['requested_quantity'] ?? 0);
    $requested_increment = getDailyAdministerIncrement($drug_id, $requested_quantity);
    
    error_log("checkAdministerLimit - Parameters: drug_id=$drug_id, lot_number=$lot_number, requested_quantity=$requested_quantity");
    
    if (!$pid || !$drug_id || !$lot_number) {
        error_log("checkAdministerLimit - Missing required parameters");
        sendJsonResponse(['success' => false, 'error' => xlt('Missing required parameters')], 400);
    }
    
    // Ensure daily administration tracking table exists
    createDailyAdministerTrackingTable();
    
    $today = date('Y-m-d');
    $max_daily_limit = 2; // Maximum 2 doses per day per medication
    
    // Get current total administered today for this patient and drug (product level, not lot level)
    $sql = "SELECT SUM(total_administered) as total_administered FROM daily_administer_tracking 
            WHERE pid = ? AND drug_id = ? AND administer_date = ?";

    error_log("Daily limit check - SQL: $sql, params: pid=$pid, drug_id=$drug_id, today=$today");
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$pid, $drug_id, $today]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_total = $result ? normalizePosQuantity($result['total_administered']) : 0;
        error_log("Daily limit check - PDO query result: " . ($result ? json_encode($result) : 'none'));
    } catch (PDOException $e) {
        error_log("Daily limit check - PDO query failed: " . $e->getMessage());
        $current_total = 0;
    }

    if ($current_total > 0 && !hasSuccessfulAdministerTransactionToday($pid, $drug_id, $today)) {
        error_log("Daily limit check - Ignoring stale daily administer count for pid=$pid, drug_id=$drug_id on $today");
        $current_total = 0;
    }
    
    error_log("Daily limit check - Current total: $current_total, Requested: $requested_quantity");
    
    // Calculate remaining allowed
    $remaining_allowed = max(0, $max_daily_limit - $current_total);
    
    // Check if requested quantity would exceed limit
    $can_administer = ($current_total + $requested_increment) <= $max_daily_limit;
    
    error_log("Daily limit check - Current total: $current_total, Requested: $requested_quantity, Remaining allowed: $remaining_allowed, Can administer: " . ($can_administer ? 'true' : 'false'));
    
    $error_message = '';
    if (!$can_administer) {
        $error_message = xlt("Daily administration limit exceeded. Patient has already received") . " {$current_total} " . xlt("dose(s) today. Maximum allowed:") . " {$max_daily_limit} " . xlt("dose(s) per day.");
    }
    
    sendJsonResponse([
        'success' => true,
        'can_administer' => $can_administer,
        'current_total' => $current_total,
        'remaining_allowed' => $remaining_allowed,
        'error' => $error_message
    ]);
}

/**
 * Create the daily administer tracking table if it doesn't exist
 */
function createDailyAdministerTrackingTable() {
    error_log("createDailyAdministerTrackingTable - Starting function");
    error_log("createDailyAdministerTrackingTable - Creating/upgrading table");
    ensureDailyAdministerTrackingSchema();
    $result = true;
    if ($result === false) {
        error_log("createDailyAdministerTrackingTable - Table creation failed");
    } else {
        error_log("createDailyAdministerTrackingTable - Table creation completed");
    }
    error_log("createDailyAdministerTrackingTable - Function completed");
}

/**
 * Record daily administration in the tracking table
 */
function recordDailyAdminister($pid, $drug_id, $lot_number, $administered_quantity) {
    global $pdo;
    createDailyAdministerTrackingTable();
    $administered_quantity = getDailyAdministerIncrement($drug_id, $administered_quantity);
    if ($administered_quantity <= 0) {
        return true;
    }
    
    $today = date('Y-m-d');
    
    // Check if record exists for today using PDO
    $check_sql = "SELECT id, total_administered FROM daily_administer_tracking 
                  WHERE pid = ? AND drug_id = ? AND administer_date = ?";
    
    error_log("recordDailyAdminister - Check SQL: $check_sql, params: pid=$pid, drug_id=$drug_id, today=$today");
    
    try {
        $stmt = $pdo->prepare($check_sql);
        $stmt->execute([$pid, $drug_id, $today]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        error_log("recordDailyAdminister - PDO query successful, existing record: " . ($existing ? json_encode($existing) : 'none'));
    } catch (PDOException $e) {
        error_log("recordDailyAdminister - PDO check query error: " . $e->getMessage());
        $existing = null;
    }

    if ($existing && !hasSuccessfulAdministerTransactionToday($pid, $drug_id, $today)) {
        error_log("recordDailyAdminister - Resetting stale daily administer record for pid=$pid, drug_id=$drug_id on $today");
        $existing['total_administered'] = 0;
    }
    
    if ($existing) {
        // Update existing record
        $new_total = normalizePosQuantity($existing['total_administered']) + $administered_quantity;
        $update_sql = "UPDATE daily_administer_tracking 
                       SET total_administered = ?, updated_date = NOW() 
                       WHERE id = ?";
        
        try {
            $stmt = $pdo->prepare($update_sql);
            $stmt->execute([$new_total, $existing['id']]);
            error_log("recordDailyAdminister - Updated existing record, new total: $new_total");
            return true;
        } catch (PDOException $e) {
            error_log("recordDailyAdminister - PDO update error: " . $e->getMessage());
            return false;
        }
    } else {
        // Insert new record
        $insert_sql = "INSERT INTO daily_administer_tracking 
                       (pid, drug_id, lot_number, administer_date, total_administered) 
                       VALUES (?, ?, ?, ?, ?)";
        
        try {
            $stmt = $pdo->prepare($insert_sql);
            $stmt->execute([$pid, $drug_id, $lot_number, $today, $administered_quantity]);
            error_log("recordDailyAdminister - Inserted new record");
            return true;
        } catch (PDOException $e) {
            error_log("recordDailyAdminister - PDO insert error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Complete administer without payment - for administer-only items
 */
function completeAdminister($pid, $items) {
    global $input, $pdo;
    
    // Validate input
    if (!$pid || empty($items)) {
        sendJsonResponse(['success' => false, 'error' => xlt('Invalid input data')], 400);
    }
    
    // Check daily administration limits before processing
    error_log("completeAdminister - Checking daily limits for pid=$pid");
    foreach ($items as $item) {
        $drug_id = intval($item['drug_id'] ?? 0);
        $lot_number = $item['lot_number'] ?? '';
        $administer_quantity = normalizePosQuantity($item['administer_quantity'] ?? 0);
        $daily_administer_increment = getDailyAdministerIncrement($drug_id, $administer_quantity);
        
        if ($drug_id && $lot_number && $daily_administer_increment > 0) {
            error_log("completeAdminister - Checking limit for drug_id=$drug_id, lot=$lot_number, qty=$administer_quantity, increment=$daily_administer_increment");
            
            // Check daily limit using PDO
            $today = date('Y-m-d');
            $sql = "SELECT SUM(total_administered) as total_administered FROM daily_administer_tracking 
                    WHERE pid = ? AND drug_id = ? AND administer_date = ?";
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$pid, $drug_id, $today]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $current_total = $result ? normalizePosQuantity($result['total_administered']) : 0;
                error_log("completeAdminister - PDO daily limit query successful, current total: $current_total");
            } catch (PDOException $e) {
                error_log("completeAdminister - PDO daily limit query error: " . $e->getMessage());
                $current_total = 0;
            }

            if ($current_total > 0 && !hasSuccessfulAdministerTransactionToday($pid, $drug_id, $today)) {
                error_log("completeAdminister - Ignoring stale daily administer count for pid=$pid, drug_id=$drug_id on $today");
                $current_total = 0;
            }
            
            $max_daily_limit = 2;
            $can_administer = ($current_total + $daily_administer_increment) <= $max_daily_limit;
            
            error_log("completeAdminister - Current total: $current_total, Requested: $administer_quantity, Can administer: " . ($can_administer ? 'true' : 'false'));
            
            if (!$can_administer) {
                error_log("completeAdminister - BLOCKING: Daily limit exceeded! Current: $current_total, Requested: $administer_quantity, Max: $max_daily_limit");
                sendJsonResponse(['success' => false, 'error' => xlt("Daily administration limit exceeded. Patient has already received") . " {$current_total} " . xlt("dose(s) today. Maximum allowed:") . " {$max_daily_limit} " . xlt("dose(s) per day.")], 400);
            } else {
                error_log("completeAdminister - ALLOWING: Daily limit check passed. Current: $current_total, Requested: $administer_quantity, Max: $max_daily_limit");
            }
        }
    }
    
    // Skip ADODB transaction (use direct queries)
    error_log("completeAdminister - Skipping SQL transaction (using direct queries)");
    
    // Process the administer
    error_log("completeAdminister - About to call processAdministerItems");
    $result = processAdministerItems($pid, $items);
    error_log("completeAdminister - processAdministerItems returned: " . json_encode($result));
    
    if ($result['success']) {
        error_log("completeAdminister - Success, sending response");
        sendJsonResponse([
            'success' => true, 
            'message' => xlt('Administer completed successfully'),
            'receipt_number' => $result['receipt_number']
        ]);
    } else {
        error_log("Error completing administer: " . $result['error']);
        sendJsonResponse(['success' => false, 'error' => xlt('Error completing administer: ') . $result['error']]);
    }
}

/**
 * Complete both dispense and administer without payment - for combined dispense and administer items
 */
function completeDispenseAndAdminister($pid, $items) {
    global $input, $pdo;
    
    // Validate input
    if (!$pid || empty($items)) {
        sendJsonResponse(['success' => false, 'error' => xlt('Invalid input data')], 400);
    }
    
    // Skip ADODB transaction (use direct queries)
    error_log("completeDispenseAndAdminister - Skipping SQL transaction (using direct queries)");
    
    // Process the combined dispense and administer
    error_log("completeDispenseAndAdminister - About to call processCombinedDispenseAndAdministerItems");
    $result = processCombinedDispenseAndAdministerItems($pid, $items);
    error_log("completeDispenseAndAdminister - processCombinedDispenseAndAdministerItems returned: " . json_encode($result));
    
    if ($result['success']) {
        error_log("completeDispenseAndAdminister - Success, sending response");
        sendJsonResponse([
            'success' => true, 
            'message' => xlt('Combined dispense and administer completed successfully'),
            'receipt_number' => $result['receipt_number']
        ]);
    } else {
        error_log("Error completing combined dispense and administer: " . $result['error']);
        sendJsonResponse(['success' => false, 'error' => xlt('Error completing combined dispense and administer: ') . $result['error']]);
    }
}

/**
 * Complete marketplace dispense without payment - for Hoover facility
 */
function completeMarketplaceDispense($pid, $items) {
    global $input;
    
    error_log("completeMarketplaceDispense - Starting function");
    error_log("completeMarketplaceDispense - PID: $pid, Items: " . json_encode($items));
    
    // Validate input
    if (!$pid || empty($items)) {
        error_log("completeMarketplaceDispense - Invalid input data");
        sendJsonResponse(['success' => false, 'error' => xlt('Invalid input data')], 400);
    }
    
    // Start transaction
    error_log("completeMarketplaceDispense - Starting transaction");
    sqlBeginTrans();
    
    // Process the marketplace dispense (similar to regular dispense but without inventory deduction)
    error_log("completeMarketplaceDispense - About to call processMarketplaceDispenseItems");
    $result = processMarketplaceDispenseItems($pid, $items);
    error_log("completeMarketplaceDispense - processMarketplaceDispenseItems result: " . json_encode($result));
    
    if ($result['success']) {
        // Commit transaction
        sqlCommitTrans();
        
        sendJsonResponse([
            'success' => true, 
            'message' => xlt('Marketplace dispense completed successfully'),
            'receipt_number' => $result['receipt_number']
        ]);
    } else {
        // Rollback transaction on error
        sqlRollbackTrans();
        
        error_log("Error completing marketplace dispense: " . $result['error']);
        sendJsonResponse(['success' => false, 'error' => xlt('Error completing marketplace dispense: ') . $result['error']]);
    }
}

function processDispenseItems($pid, $items) {
    global $pdo;
    error_log("processDispenseItems - START - PID: $pid, Items count: " . count($items));
    error_log("processDispenseItems - Items data: " . json_encode($items));
    
    // Process each item
    foreach ($items as $item) {
        $drug_id = intval($item['drug_id']);
        $lot_number = $item['lot_number'];
        $quantity = normalizePosQuantity($item['quantity']);
        $dispense_quantity = normalizePosQuantity($item['dispense_quantity']);
        $is_remaining_dispense = $item['is_remaining_dispense'] ?? false;
        $has_remaining_dispense = $item['has_remaining_dispense'] ?? false;
        $remaining_dispense_items = $item['remaining_dispense_items'] ?? array();
        $total_remaining_quantity = $item['total_remaining_quantity'] ?? 0;
        
        error_log("processDispenseItems - Processing item: drug_id=$drug_id, lot=$lot_number, qty=$quantity, dispense=$dispense_quantity, has_remaining=$has_remaining_dispense");
        
        // Complete-dispense is a patient-balance flow. If the item is already marked as
        // remaining dispense, or the cart item is tied to existing remaining balance,
        // always consume from pos_remaining_dispense instead of treating it as new stock.
        if ($dispense_quantity > 0 && ($is_remaining_dispense || $has_remaining_dispense)) {
            error_log("processDispenseItems - Forcing remaining-dispense path: drug_id=$drug_id, lot=$lot_number, qty=$quantity, dispense=$dispense_quantity, is_remaining=" . ($is_remaining_dispense ? 'yes' : 'no') . ", has_remaining=" . ($has_remaining_dispense ? 'yes' : 'no'));
            processRemainingDispenseItem($pid, $drug_id, $lot_number, $dispense_quantity, $has_remaining_dispense, $remaining_dispense_items);
            error_log("processDispenseItems - processRemainingDispenseItem returned");
            continue;
        }
        
        // Process the item based on its type
        if ($is_remaining_dispense) {
            processRemainingDispenseItem($pid, $drug_id, $lot_number, $dispense_quantity, $has_remaining_dispense, $remaining_dispense_items);
        } elseif ($has_remaining_dispense && !empty($remaining_dispense_items)) {
            processIntegratedDispenseItem($pid, $drug_id, $lot_number, $quantity, $dispense_quantity, $remaining_dispense_items, $item);
        } else {
            processRegularDispenseItem($pid, $drug_id, $lot_number, $quantity, $dispense_quantity, $item);
        }
    }
    
    error_log("processDispenseItems - Foreach loop completed, about to generate receipt number");
    
    // Generate receipt number
    $receipt_number = posGenerateReceiptNumber('DISP', $pid);
    $timestamp = date('Y-m-d H:i:s');
    
    $tableExists = posTableExists('pos_transactions');
    error_log("processDispenseItems - pos_transactions table exists: " . ($tableExists ? 'yes' : 'no'));
    
    // Record transaction
    $transactionType = 'dispense';
    $hasAlternateLotDispense = false;
    
    foreach ($items as $item) {
        $isDifferentLotDispense = $item['is_different_lot_dispense'] ?? false;
        if ($isDifferentLotDispense) {
            $hasAlternateLotDispense = true;
        }
    }
    
    if ($hasAlternateLotDispense) {
        $transactionType = 'alternate_lot_dispense';
    }
    
    // Clean the items data for database insertion
    $cleanItems = array();
    foreach ($items as $item) {
        $drug_id = $item['drug_id'] ?? null;
        $lot_number = $item['lot_number'] ?? null;
        $useRegularDispenseFlow = isHooverMarketplaceDispenseException($item);
        
        if (!$drug_id || !$lot_number) {
            if (preg_match('/drug_(\d+)_lot_(.+)/', $item['id'], $matches)) {
                $drug_id = intval($matches[1]);
                $lot_number = $matches[2];
            }
        }
        
        if (!$lot_number && isset($item['lot'])) {
            $lot_number = $item['lot'];
        }
        
        // For alternate lot dispense, use the actual lot being dispensed from
        if (isset($item['is_different_lot_dispense']) && $item['is_different_lot_dispense']) {
            // Extract the actual lot being used from the item ID or lot field
            if (preg_match('/drug_(\d+)_lot_(.+)/', $item['id'], $matches)) {
                $drug_id = intval($matches[1]);
                $lot_number = $matches[2];
            } elseif (isset($item['lot'])) {
                $lot_number = $item['lot'];
            }
        }
        
        $cleanItems[] = array(
            'id' => $item['id'],
            'name' => $item['name'],
            'display_name' => $item['display_name'],
            'drug_id' => $drug_id,
            'lot_number' => $lot_number,
            'form' => $item['form'] ?? '',
            'dose_option_mg' => $item['dose_option_mg'] ?? ($item['semaglutide_dose_mg'] ?? ''),
            'quantity' => normalizePosQuantity($item['quantity']),
            'dispense_quantity' => normalizePosQuantity($item['dispense_quantity']),
            'price' => (float) ($item['price'] ?? 0),
            'prepay_selected' => !empty($item['prepay_selected']),
            'prepay_date' => (string) ($item['prepay_date'] ?? ''),
            'prepay_sale_reference' => (string) ($item['prepay_sale_reference'] ?? ''),
            'is_remaining_dispense' => $item['is_remaining_dispense'] ?? false,
            'is_different_lot_dispense' => $item['is_different_lot_dispense'] ?? false,
            'has_remaining_dispense' => $item['has_remaining_dispense'] ?? false,
            'remaining_dispense_items' => is_array($item['remaining_dispense_items'] ?? null) ? $item['remaining_dispense_items'] : [],
            'total_remaining_quantity' => normalizePosQuantity($item['total_remaining_quantity'] ?? 0)
        );
    }
    
    if ($tableExists) {
        $jsonData = json_encode($cleanItems, JSON_UNESCAPED_SLASHES);
        
        // Get user ID safely
        $user_id = $_SESSION['authUserID'] ?? $_SESSION['authUser'] ?? 'system';
        $visitType = (string) ($item['visit_type'] ?? ($GLOBALS['input']['visit_type'] ?? '-'));
        $priceOverrideNotes = trim((string) ($GLOBALS['input']['price_override_notes'] ?? ''));
        $patientNumber = $GLOBALS['input']['patient_number'] ?? null;
        
        error_log("processDispenseItems - About to insert transaction: receipt=$receipt_number, type=$transactionType, user_id=$user_id");
        
        try {
            $inserted = posInsertTransaction([
                'pid' => $pid,
                'receipt_number' => $receipt_number,
                'transaction_type' => $transactionType,
                'amount' => 0,
                'items' => $jsonData,
                'created_date' => $timestamp,
                'user_id' => $user_id,
                'visit_type' => $visitType,
                'price_override_notes' => $priceOverrideNotes,
                'patient_number' => $patientNumber,
            ]);
            $transactionId = (int) sqlInsertId();
            $receiptRecorded = $inserted && $transactionId > 0 && posUpsertOperationalReceiptRecord($pid, [
                'id' => $transactionId,
                'pid' => $pid,
                'receipt_number' => $receipt_number,
                'transaction_type' => $transactionType,
                'amount' => 0,
                'items' => $jsonData,
                'created_date' => $timestamp,
                'price_override_notes' => $priceOverrideNotes,
                'facility_id' => (int) (posGetTransactionFacilityId($pid) ?? 0),
            ]);
            if (!$receiptRecorded) {
                return [
                    'success' => false,
                    'error' => 'Failed to create dispense receipt record'
                ];
            }
            error_log("processDispenseItems - Transaction inserted successfully with receipt: $receipt_number, type: $transactionType");
        } catch (PDOException $e) {
            error_log("processDispenseItems - PDO Error recording transaction: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Error recording transaction: ' . $e->getMessage()
            ];
        }
    } else {
        error_log("processDispenseItems - Dispense transaction recording skipped: pos_transactions table does not exist");
    }
    
    error_log("processDispenseItems - COMPLETE - Returning success with receipt: $receipt_number");
    
    return [
        'success' => true,
        'receipt_number' => $receipt_number
    ];
}

function processMarketplaceDispenseItems($pid, $items) {
    // Process each item for marketplace dispense. Certain products such as phentermine
    // must still follow regular dispense inventory deduction even in Hoover.
    foreach ($items as $item) {
        $drug_id = intval($item['drug_id']);
        $lot_number = $item['lot_number'];
        $dispense_quantity = normalizePosQuantity($item['dispense_quantity']);
        $is_remaining_dispense = $item['is_remaining_dispense'] ?? false;
        $has_remaining_dispense = $item['has_remaining_dispense'] ?? false;
        $remaining_dispense_items = $item['remaining_dispense_items'] ?? [];
        $useRegularDispenseFlow = isHooverMarketplaceDispenseException($item);
        
        if ($dispense_quantity <= 0) {
            continue; // Skip items with no dispense quantity
        }

        if ($useRegularDispenseFlow) {
            error_log("processMarketplaceDispenseItems - Using regular dispense inventory flow for Hoover exception item: " . ($item['name'] ?? 'Unknown'));
            if ($is_remaining_dispense || $has_remaining_dispense) {
                processRemainingDispenseItem($pid, $drug_id, $lot_number, $dispense_quantity, $has_remaining_dispense, $remaining_dispense_items);
            } else {
                processIntegratedDispenseItem($pid, $drug_id, $lot_number, normalizePosQuantity($item['quantity']), $dispense_quantity, $remaining_dispense_items, $item);
            }
            continue;
        }

        // Standard Hoover marketplace dispense tracks usage without reducing inventory.
        if ($is_remaining_dispense || $has_remaining_dispense) {
            processRemainingDispenseItem($pid, $drug_id, $lot_number, $dispense_quantity, $has_remaining_dispense, $remaining_dispense_items);
        } else {
            trackMarketplaceDispense($pid, $drug_id, $lot_number, $dispense_quantity);
        }
    }
    
    // Generate receipt number for marketplace dispense
    $receipt_number = posGenerateReceiptNumber('MP', $pid);
    
    // Record marketplace dispense transaction (without inventory deduction)
    $timestamp = date('Y-m-d H:i:s');
    $hasRegularDispenseException = false;
    foreach ($items as $item) {
        if (isHooverMarketplaceDispenseException($item)) {
            $hasRegularDispenseException = true;
            break;
        }
    }

    $transactionType = $hasRegularDispenseException ? 'dispense' : 'marketplace_dispense';
    
    // Clean the items data for database insertion
    $cleanItems = array();
    foreach ($items as $item) {
        $drug_id = $item['drug_id'] ?? null;
        $lot_number = $item['lot_number'] ?? null;
        $useRegularDispenseFlow = isHooverMarketplaceDispenseException($item);
        
        if (!$drug_id || !$lot_number) {
            if (preg_match('/drug_(\d+)_lot_(.+)/', $item['id'], $matches)) {
                $drug_id = intval($matches[1]);
                $lot_number = $matches[2];
            }
        }
        
        $cleanItems[] = [
            'id' => $item['id'],
            'name' => $item['name'],
            'display_name' => $item['display_name'],
            'drug_id' => $drug_id,
            'lot_number' => $lot_number,
            'form' => $item['form'] ?? '',
            'dose_option_mg' => $item['dose_option_mg'] ?? ($item['semaglutide_dose_mg'] ?? ''),
            'quantity' => normalizePosQuantity($item['quantity']),
            'dispense_quantity' => normalizePosQuantity($item['dispense_quantity']),
            'administer_quantity' => normalizePosQuantity($item['administer_quantity'] ?? 0),
            'price' => (float) ($item['price'] ?? 0),
            'prepay_selected' => !empty($item['prepay_selected']),
            'prepay_date' => (string) ($item['prepay_date'] ?? ''),
            'prepay_sale_reference' => (string) ($item['prepay_sale_reference'] ?? ''),
            'is_remaining_dispense' => $item['is_remaining_dispense'] ?? false,
            'has_remaining_dispense' => $item['has_remaining_dispense'] ?? false,
            'remaining_dispense_items' => is_array($item['remaining_dispense_items'] ?? null) ? $item['remaining_dispense_items'] : [],
            'total_remaining_quantity' => normalizePosQuantity($item['total_remaining_quantity'] ?? 0),
            'marketplace_dispense' => !$useRegularDispenseFlow
        ];
    }
    
    $jsonData = json_encode($cleanItems);
    
    // Record the marketplace dispense transaction
    $transactionResult = posInsertTransaction([
        'pid' => $pid,
        'receipt_number' => $receipt_number,
        'transaction_type' => $transactionType,
        'amount' => 0,
        'items' => $jsonData,
        'created_date' => $timestamp,
        'user_id' => $_SESSION['authUserID'] ?? $_SESSION['authUser'] ?? 'system',
        'patient_number' => $GLOBALS['input']['patient_number'] ?? null,
    ]);
    
    if ($transactionResult !== false) {
        $transactionId = (int) sqlInsertId();
        $receiptRecorded = $transactionId > 0 && posUpsertOperationalReceiptRecord($pid, [
            'id' => $transactionId,
            'pid' => $pid,
            'receipt_number' => $receipt_number,
            'transaction_type' => $transactionType,
            'amount' => 0,
            'items' => $jsonData,
            'created_date' => $timestamp,
            'facility_id' => (int) (posGetTransactionFacilityId($pid) ?? 0),
        ]);
        if (!$receiptRecorded) {
            return [
                'success' => false,
                'error' => 'Failed to create marketplace dispense receipt record'
            ];
        }
        error_log("Marketplace dispense transaction recorded successfully: $receipt_number, type: $transactionType");
    } else {
        error_log("Warning: Could not record marketplace dispense transaction");
    }
    
    return [
        'success' => true,
        'receipt_number' => $receipt_number
    ];
}

function processAdministerItems($pid, $items) {
    global $pdo;
    error_log("processAdministerItems - START - PID: $pid, Items count: " . count($items));
    
    // Process each item for administer
    foreach ($items as $item) {
        $drug_id = intval($item['drug_id']);
        $lot_number = $item['lot_number'];
        $quantity = normalizePosQuantity($item['quantity']);
        $administer_quantity = normalizePosQuantity($item['administer_quantity'] ?? 0);
        $is_remaining_dispense = $item['is_remaining_dispense'] ?? false;
        $has_remaining_dispense = $item['has_remaining_dispense'] ?? false;
        $remaining_dispense_items = $item['remaining_dispense_items'] ?? array();
        $total_remaining_quantity = $item['total_remaining_quantity'] ?? 0;
        
        error_log("processAdministerItems - Processing item: drug_id=$drug_id, lot=$lot_number, administer=$administer_quantity");
        
        // Process the item based on its type
        if ($is_remaining_dispense) {
            processRemainingAdministerItem($pid, $drug_id, $lot_number, $administer_quantity, $has_remaining_dispense, $remaining_dispense_items, $item);
        } elseif ($has_remaining_dispense && !empty($remaining_dispense_items)) {
            processIntegratedAdministerItem($pid, $drug_id, $lot_number, $quantity, $administer_quantity, $remaining_dispense_items, $item);
        } else {
            // Regular administer-only (no remaining-dispense integration)
            processRegularAdministerItem($pid, $drug_id, $lot_number, $administer_quantity, $item);
        }
        error_log("processAdministerItems - Item processed");
    }
    
    // Generate receipt number
    $receipt_number = posGenerateReceiptNumber('ADMIN', $pid);
    $timestamp = date('Y-m-d H:i:s');
    error_log("processAdministerItems - Generated receipt: $receipt_number");
    
    $tableExists = posTableExists('pos_transactions');
    error_log("processAdministerItems - pos_transactions table exists: " . ($tableExists ? 'yes' : 'no'));
    
    // Record transaction
    $transactionType = 'administer';
    
    // Clean the items data for database insertion
    $cleanItems = array();
    foreach ($items as $item) {
        $drug_id = $item['drug_id'] ?? null;
        $lot_number = $item['lot_number'] ?? null;
        
        if (!$drug_id || !$lot_number) {
            if (preg_match('/drug_(\d+)_lot_(.+)/', $item['id'], $matches)) {
                $drug_id = intval($matches[1]);
                $lot_number = $matches[2];
            }
        }
        
        if (!$lot_number && isset($item['lot'])) {
            $lot_number = $item['lot'];
        }
        
        $cleanItems[] = array(
            'id' => $item['id'],
            'name' => $item['name'],
            'display_name' => $item['display_name'],
            'drug_id' => $drug_id,
            'lot_number' => $lot_number,
            'form' => $item['form'] ?? '',
            'dose_option_mg' => $item['dose_option_mg'] ?? ($item['semaglutide_dose_mg'] ?? ''),
            'quantity' => normalizePosQuantity($item['quantity']),
            'dispense_quantity' => normalizePosQuantity($item['dispense_quantity'] ?? 0),
            'administer_quantity' => normalizePosQuantity($item['administer_quantity'] ?? 0),
            'price' => (float) ($item['price'] ?? 0),
            'original_price' => (float) ($item['original_price'] ?? ($item['price'] ?? 0)),
            'line_total' => (float) ($item['line_total'] ?? 0),
            'original_line_total' => (float) ($item['original_line_total'] ?? 0),
            'line_discount' => (float) ($item['line_discount'] ?? 0),
            'has_discount' => !empty($item['has_discount']),
            'discount_info' => $item['discount_info'] ?? null,
            'prepay_selected' => !empty($item['prepay_selected']),
            'prepay_date' => (string) ($item['prepay_date'] ?? ''),
            'prepay_sale_reference' => (string) ($item['prepay_sale_reference'] ?? ''),
            'is_remaining_dispense' => $item['is_remaining_dispense'] ?? false,
            'is_different_lot_dispense' => $item['is_different_lot_dispense'] ?? false,
            'has_remaining_dispense' => $item['has_remaining_dispense'] ?? false,
            'remaining_dispense_items' => is_array($item['remaining_dispense_items'] ?? null) ? $item['remaining_dispense_items'] : [],
            'total_remaining_quantity' => normalizePosQuantity($item['total_remaining_quantity'] ?? 0)
        );
    }
    
    if ($tableExists) {
        $jsonData = json_encode($cleanItems, JSON_UNESCAPED_SLASHES);
        $user_id = $_SESSION['authUserID'] ?? $_SESSION['authUser'] ?? 'system';
        $visitType = $item['visit_type'] ?? ($GLOBALS['input']['visit_type'] ?? '-');
        $priceOverrideNotes = trim((string) ($GLOBALS['input']['price_override_notes'] ?? ''));
        $patientNumber = trim((string) ($GLOBALS['input']['patient_number'] ?? ''));
        
        try {
            $inserted = posInsertTransaction([
                'pid' => $pid,
                'receipt_number' => $receipt_number,
                'transaction_type' => $transactionType,
                'amount' => 0,
                'items' => $jsonData,
                'created_date' => $timestamp,
                'user_id' => $user_id,
                'visit_type' => $visitType,
                'price_override_notes' => $priceOverrideNotes,
                'patient_number' => $patientNumber,
            ]);
            $transactionId = (int) sqlInsertId();
            $receiptRecorded = $inserted && $transactionId > 0 && posUpsertOperationalReceiptRecord($pid, [
                'id' => $transactionId,
                'pid' => $pid,
                'receipt_number' => $receipt_number,
                'transaction_type' => $transactionType,
                'amount' => 0,
                'items' => $jsonData,
                'created_date' => $timestamp,
                'price_override_notes' => $priceOverrideNotes,
                'facility_id' => (int) (posGetTransactionFacilityId($pid) ?? 0),
            ]);
            if (!$receiptRecorded) {
                return [
                    'success' => false,
                    'error' => 'Failed to create administer receipt record'
                ];
            }
            error_log("processAdministerItems - Transaction recorded successfully: $receipt_number");
        } catch (PDOException $e) {
            error_log("processAdministerItems - Error recording transaction: " . $e->getMessage());
        }
    } else {
        error_log("processAdministerItems - Transaction recording skipped: pos_transactions table does not exist");
    }
    
    error_log("processAdministerItems - COMPLETE - Returning success with receipt: $receipt_number");
    return [
        'success' => true,
        'receipt_number' => $receipt_number
    ];
}

function processCombinedDispenseAndAdministerItems($pid, $items) {
    global $pdo;
    
    // Generate receipt number first
    $receipt_number = posGenerateReceiptNumber('DISP-ADMIN', $pid);
    
    // Process each item for combined dispense and administer
    foreach ($items as $item) {
        $drug_id = intval($item['drug_id']);
        $lot_number = $item['lot_number'];
        $quantity = normalizePosQuantity($item['quantity']);
        $dispense_quantity = normalizePosQuantity($item['dispense_quantity']);
        $administer_quantity = normalizePosQuantity($item['administer_quantity'] ?? 0);
        
        // For ml products, inventory is reduced only when medication is administered.
        $administerInventoryDeduction = getAdministerInventoryDeductionQuantity($item, $administer_quantity);
        $totalInventoryToDeduct = shouldDeductDispenseFromInventory($drug_id)
            ? ($dispense_quantity + $administerInventoryDeduction)
            : $administerInventoryDeduction;
        
        // Track dispense/administer in pos_remaining_dispense
        if ($quantity > 0 || $dispense_quantity > 0 || $administer_quantity > 0) {
            // CASE 1: New Purchase (qty > 0)
            if ($quantity > 0) {
                $totalUsed = $dispense_quantity + $administer_quantity;
                $remainingQuantity = $quantity - $totalUsed;
                
                try {
                    $insert_sql = "INSERT INTO pos_remaining_dispense 
                                  (pid, drug_id, lot_number, total_quantity, dispensed_quantity, administered_quantity, remaining_quantity, receipt_number, created_date, last_updated) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                    $insert_stmt = $pdo->prepare($insert_sql);
                    $insert_stmt->execute([$pid, $drug_id, $lot_number, $quantity, $dispense_quantity, $administer_quantity, $remainingQuantity, $receipt_number]);
                } catch (PDOException $e) {
                    error_log("processCombinedDispenseAndAdministerItems - Error creating purchase entry: " . $e->getMessage());
                }
            }
            // CASE 2: Using remaining dispense (qty=0, dispense/admin > 0)
            elseif ($quantity == 0 && ($dispense_quantity > 0 || $administer_quantity > 0)) {
                // Deduct from existing records (FIFO - oldest first)
                $totalToDeduct = $dispense_quantity + $administer_quantity;
                $deductSuccess = updateRemainingDispenseRecord($pid, $drug_id, $lot_number, $totalToDeduct);
                
                // ALWAYS create transaction entry regardless of deduction success
                // This ensures we track what was dispensed/administered even if no remaining quantities exist
                try {
                    $insert_sql = "INSERT INTO pos_remaining_dispense 
                                  (pid, drug_id, lot_number, total_quantity, dispensed_quantity, administered_quantity, remaining_quantity, receipt_number, created_date, last_updated) 
                                  VALUES (?, ?, ?, 0, ?, ?, 0, ?, NOW(), NOW())";
                    $insert_stmt = $pdo->prepare($insert_sql);
                    $insert_stmt->execute([$pid, $drug_id, $lot_number, $dispense_quantity, $administer_quantity, $receipt_number]);
                } catch (PDOException $e) {
                    error_log("processCombinedDispenseAndAdministerItems - Error creating transaction entry: " . $e->getMessage());
                }
            }
        }
        
        // Now handle the combined inventory deduction from the POS-selected lot
        if ($totalInventoryToDeduct > 0) {
            $inventoryData = posFindActiveInventoryRow($drug_id, $lot_number, $totalInventoryToDeduct);
            
            if ($inventoryData && $inventoryData['on_hand'] >= $totalInventoryToDeduct) {
                $newQoh = $inventoryData['on_hand'] - $totalInventoryToDeduct;
                try {
                    $updateStmt = $pdo->prepare("UPDATE drug_inventory SET on_hand = ? WHERE inventory_id = ?");
                    $updateStmt->execute([$newQoh, $inventoryData['inventory_id']]);
                    
                    if ($newQoh <= 0) {
                        $deleteStmt = $pdo->prepare("DELETE FROM drug_inventory WHERE inventory_id = ?");
                        $deleteStmt->execute([$inventoryData['inventory_id']]);
                    }
                } catch (PDOException $e) {
                    error_log("processCombinedDispenseAndAdministerItems - Inventory update error: " . $e->getMessage());
                }
            } else {
                error_log("processCombinedDispenseAndAdministerItems - Insufficient inventory: drug_id=$drug_id, requested=$totalInventoryToDeduct, available=" . ($inventoryData ? $inventoryData['on_hand'] : 0));
            }
        }
    }
    
    // Receipt number was already generated at the start of the function
    $timestamp = date('Y-m-d H:i:s');
    
    $tableExists = posTableExists('pos_transactions');
    
    // Record the transaction
    $transactionType = 'dispense_and_administer';
    $cleanItems = array();
    
    foreach ($items as $item) {
        $drug_id = intval($item['drug_id']);
        $lot_number = $item['lot_number'] ?? null;
        
        if (!$drug_id || !$lot_number) {
            if (preg_match('/drug_(\d+)_lot_(.+)/', $item['id'], $matches)) {
                $drug_id = intval($matches[1]);
                $lot_number = $matches[2];
            }
        }
        
        if (!$lot_number && isset($item['lot'])) {
            $lot_number = $item['lot'];
        }
        
        $cleanItems[] = array(
            'id' => $item['id'],
            'name' => $item['name'],
            'display_name' => $item['display_name'],
            'drug_id' => $drug_id,
            'lot_number' => $lot_number,
            'form' => $item['form'] ?? '',
            'dose_option_mg' => $item['dose_option_mg'] ?? ($item['semaglutide_dose_mg'] ?? ''),
            'quantity' => normalizePosQuantity($item['quantity']),
            'dispense_quantity' => normalizePosQuantity($item['dispense_quantity']),
            'administer_quantity' => normalizePosQuantity($item['administer_quantity'] ?? 0),
            'price' => (float) ($item['price'] ?? 0),
            'prepay_selected' => !empty($item['prepay_selected']),
            'prepay_date' => (string) ($item['prepay_date'] ?? ''),
            'prepay_sale_reference' => (string) ($item['prepay_sale_reference'] ?? ''),
            'is_remaining_dispense' => $item['is_remaining_dispense'] ?? false,
            'is_different_lot_dispense' => $item['is_different_lot_dispense'] ?? false,
            'has_remaining_dispense' => $item['has_remaining_dispense'] ?? false,
            'remaining_dispense_items' => is_array($item['remaining_dispense_items'] ?? null) ? $item['remaining_dispense_items'] : [],
            'total_remaining_quantity' => normalizePosQuantity($item['total_remaining_quantity'] ?? 0)
        );
    }
    
    if ($tableExists) {
        $jsonData = json_encode($cleanItems, JSON_UNESCAPED_SLASHES);
        $user_id = $_SESSION['authUserID'] ?? $_SESSION['authUser'] ?? 'system';
        $visitType = (string) ($item['visit_type'] ?? ($GLOBALS['input']['visit_type'] ?? '-'));
        $priceOverrideNotes = trim((string) ($GLOBALS['input']['price_override_notes'] ?? ''));
        $patientNumber = $GLOBALS['input']['patient_number'] ?? null;
        
        try {
            $inserted = posInsertTransaction([
                'pid' => $pid,
                'receipt_number' => $receipt_number,
                'transaction_type' => $transactionType,
                'amount' => 0,
                'items' => $jsonData,
                'created_date' => $timestamp,
                'user_id' => $user_id,
                'visit_type' => $visitType,
                'price_override_notes' => $priceOverrideNotes,
                'patient_number' => $patientNumber,
            ]);
            $transactionId = (int) sqlInsertId();
            $receiptRecorded = $inserted && $transactionId > 0 && posUpsertOperationalReceiptRecord($pid, [
                'id' => $transactionId,
                'pid' => $pid,
                'receipt_number' => $receipt_number,
                'transaction_type' => $transactionType,
                'amount' => 0,
                'items' => $jsonData,
                'created_date' => $timestamp,
                'price_override_notes' => $priceOverrideNotes,
                'facility_id' => (int) (posGetTransactionFacilityId($pid) ?? 0),
            ]);
            if (!$receiptRecorded) {
                return [
                    'success' => false,
                    'error' => 'Failed to create combined dispense/administer receipt record'
                ];
            }
        } catch (PDOException $e) {
            error_log("processCombinedDispenseAndAdministerItems - Transaction recording error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Error recording combined dispense/administer transaction: ' . $e->getMessage()
            ];
        }
    }
    
    return [
        'success' => true,
        'receipt_number' => $receipt_number
    ];
}

function processRemainingDispenseItem($pid, $drug_id, $lot_number, $dispense_quantity, $has_remaining_dispense, $remaining_dispense_items) {
    error_log("processRemainingDispenseItem - START: pid=$pid, drug_id=$drug_id, lot=$lot_number, dispense_qty=$dispense_quantity");
    
    if ($has_remaining_dispense && !empty($remaining_dispense_items)) {
        error_log("processRemainingDispenseItem - Has remaining dispense items: " . count($remaining_dispense_items));
        foreach ($remaining_dispense_items as $remainingItem) {
            $originalLotNumber = $remainingItem['lot_number'];
            $remainingItemQty = $remainingItem['remaining_quantity'];
            $qtyToDeduct = min($remainingItemQty, $dispense_quantity);
            
            if ($qtyToDeduct > 0) {
                error_log("processRemainingDispenseItem - Updating remaining dispense: qty=$qtyToDeduct, lot=$originalLotNumber");
                // Update remaining-dispense for the original lot
                updateRemainingDispenseRecord($pid, $drug_id, $originalLotNumber, $qtyToDeduct, false);
                error_log("processRemainingDispenseItem - updateRemainingDispenseRecord returned");
                
                $dispense_quantity -= $qtyToDeduct;
                if ($dispense_quantity <= 0) break;
            }
        }
    } else {
        error_log("processRemainingDispenseItem - No remaining items, updating lot $lot_number");
        // Update remaining-dispense for the provided lot
        updateRemainingDispenseRecord($pid, $drug_id, $lot_number, $dispense_quantity, false);
        error_log("processRemainingDispenseItem - updateRemainingDispenseRecord returned");
    }

    // Remaining-dispense use is patient-balance only. Inventory was already deducted
    // when the original quantity was sold/purchased, so do not deduct it again here.
    error_log("processRemainingDispenseItem - Skipping inventory deduction because this is a remaining-dispense-only flow");
    error_log("processRemainingDispenseItem - END: function completed successfully");
}

function processIntegratedDispenseItem($pid, $drug_id, $lot_number, $quantity, $dispense_quantity, $remaining_dispense_items, $item) {
    $isDifferentLotDispense = isset($item['is_different_lot_dispense']) && $item['is_different_lot_dispense'];
    
    $newInventoryToUse = min($quantity, $dispense_quantity);
    $remainingDispenseToUse = $dispense_quantity - $newInventoryToUse;
    
    if ($remainingDispenseToUse > 0) {
        foreach ($remaining_dispense_items as $remainingItem) {
            $originalLotNumber = $remainingItem['lot_number'];
            $remainingItemQty = $remainingItem['remaining_quantity'];
            $qtyToDeduct = min($remainingItemQty, $remainingDispenseToUse);
            
            if ($qtyToDeduct > 0) {
                // Update remaining-dispense record for the original lot (tracking only)
                updateRemainingDispenseRecord($pid, $remainingItem['drug_id'], $originalLotNumber, $qtyToDeduct, false);

                $remainingDispenseToUse -= $qtyToDeduct;
            }
            
            if ($remainingDispenseToUse <= 0) {
                break;
            }
        }
    }
    
    if ($newInventoryToUse > 0 && shouldDeductDispenseFromInventory($drug_id)) {
        // Update inventory for new purchase quantity
        $inventoryData = posFindActiveInventoryRow($drug_id, $lot_number, $newInventoryToUse);
        
        if ($inventoryData && $inventoryData['on_hand'] >= $newInventoryToUse) {
            $newQoh = $inventoryData['on_hand'] - $newInventoryToUse;
            sqlStatement("UPDATE drug_inventory SET on_hand = ? WHERE inventory_id = ?", 
                        array($newQoh, $inventoryData['inventory_id']));
            
            if ($newQoh <= 0) {
                sqlStatement("DELETE FROM drug_inventory WHERE inventory_id = ?", array($inventoryData['inventory_id']));
            }
        }
    }
}

function processRegularDispenseItem($pid, $drug_id, $lot_number, $quantity, $dispense_quantity, $item)
{
    if ($dispense_quantity <= 0) {
        return;
    }

    if ($quantity > 0) {
        $remainingQuantity = $quantity - $dispense_quantity;
        if ($remainingQuantity < 0) {
            $remainingQuantity = 0;
        }

        sqlStatement(
            "INSERT INTO pos_remaining_dispense
                (pid, drug_id, lot_number, total_quantity, dispensed_quantity, administered_quantity, remaining_quantity, receipt_number, created_date, last_updated)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, NOW(), NOW())",
            [
                $pid,
                $drug_id,
                $lot_number,
                normalizePosQuantity($quantity),
                normalizePosQuantity($dispense_quantity),
                normalizePosQuantity($remainingQuantity),
                posGenerateReceiptNumber('DISP-TRACK', $pid),
            ]
        );
    } elseif ($dispense_quantity > 0) {
        updateRemainingDispenseRecord($pid, $drug_id, $lot_number, $dispense_quantity, false);

        sqlStatement(
            "INSERT INTO pos_remaining_dispense
                (pid, drug_id, lot_number, total_quantity, dispensed_quantity, administered_quantity, remaining_quantity, receipt_number, created_date, last_updated)
             VALUES (?, ?, ?, 0, ?, 0, 0, ?, NOW(), NOW())",
            [
                $pid,
                $drug_id,
                $lot_number,
                normalizePosQuantity($dispense_quantity),
                posGenerateReceiptNumber('DISP-TRACK', $pid),
            ]
        );
    }

    if (!shouldDeductDispenseFromInventory($drug_id)) {
        error_log("processRegularDispenseItem - ml form detected: skipping inventory deduction for drug_id=$drug_id, lot=$lot_number");
        return;
    }

    $inventoryData = posFindActiveInventoryRow($drug_id, $lot_number, $dispense_quantity);
    if (!$inventoryData) {
        throw new Exception("No inventory found for dispensed item (drug_id=$drug_id, lot=$lot_number)");
    }

    $currentQuantity = normalizePosQuantity($inventoryData['on_hand'] ?? 0);
    if ($currentQuantity < $dispense_quantity) {
        throw new Exception("Insufficient inventory for dispensed item (drug_id=$drug_id, lot=$lot_number). Available: $currentQuantity, Requested: $dispense_quantity");
    }

    $newQoh = $currentQuantity - $dispense_quantity;
    sqlStatement(
        "UPDATE drug_inventory SET on_hand = ? WHERE inventory_id = ?",
        [$newQoh, $inventoryData['inventory_id']]
    );

    if ($newQoh <= 0) {
        sqlStatement("DELETE FROM drug_inventory WHERE inventory_id = ?", [$inventoryData['inventory_id']]);
    }
}
                    
            if ($is_remaining_dispense) {
                // For remaining dispense items, we need to find the original lot where the remaining dispense is tracked
                if ($has_remaining_dispense && !empty($remaining_dispense_items)) {
                                    // This is a different lot dispense - update the original remaining dispense records
                    foreach ($remaining_dispense_items as $remainingItem) {
                        $originalLotNumber = $remainingItem['lot_number'];
                        $remainingItemQty = $remainingItem['remaining_quantity'];
                        $qtyToDeduct = min($remainingItemQty, $dispense_quantity);
                        

                        
                        if ($qtyToDeduct > 0) {
                            updateRemainingDispenseRecord($pid, $drug_id, $originalLotNumber, $qtyToDeduct, false);
                            $dispense_quantity -= $qtyToDeduct;
                            
                            if ($dispense_quantity <= 0) break;
                        }
                    }
                } else {
                    // Regular remaining dispense item - update the current lot
                    updateRemainingDispenseRecord($pid, $drug_id, $lot_number, $dispense_quantity, false);
                }
                
                if (shouldDeductDispenseFromInventory($drug_id)) {
                    // Also update inventory if the original lot still exists
                    $inventoryData = posFindActiveInventoryRow($drug_id, $lot_number, $dispense_quantity);
                    
                    if ($inventoryData && $inventoryData['on_hand'] >= $dispense_quantity) {
                        // Update inventory
                        $newQoh = $inventoryData['on_hand'] - $dispense_quantity;
                        sqlStatement("UPDATE drug_inventory SET on_hand = ? WHERE inventory_id = ?", 
                                    array($newQoh, $inventoryData['inventory_id']));
                        
                        error_log("Dispense Complete - Updated inventory for drug_id: $drug_id, lot: $lot_number, deducted: $dispense_quantity, new QOH: $newQoh, inventory_id: " . $inventoryData['inventory_id']);
                        
                        // Check if lot is now empty and remove it
                        if ($newQoh <= 0) {
                            error_log("Dispense Complete - Lot is now empty, removing lot record for drug_id: $drug_id, lot: $lot_number");
                            sqlStatement("DELETE FROM drug_inventory WHERE inventory_id = ?", array($inventoryData['inventory_id']));
                        }
                    } else {
                        error_log("Dispense Complete - Warning: No inventory found or insufficient inventory for drug_id: $drug_id, lot: $lot_number");
                    }
                } else {
                    error_log("Dispense Complete - ml form detected: skipping inventory deduction for dispense on drug_id=$drug_id, lot=$lot_number");
                }
                
            } elseif ($has_remaining_dispense && !empty($remaining_dispense_items)) {
                // For items with integrated remaining dispense
                $isDifferentLotDispense = isset($item['is_different_lot_dispense']) && $item['is_different_lot_dispense'];

                
                // Calculate priority: First use new purchase quantity, then remaining dispense
                $newInventoryToUse = min($quantity, $dispense_quantity); // Use new purchase first
                $remainingDispenseToUse = $dispense_quantity - $newInventoryToUse; // Use remaining dispense for the rest
                

                
                // First, update remaining dispense records
                if ($remainingDispenseToUse > 0) {
    
                    foreach ($remaining_dispense_items as $remainingItem) {
                        $remainingItemId = $remainingItem['id'];
                        $remainingItemQty = $remainingItem['remaining_quantity'];
                        $qtyToDeduct = min($remainingItemQty, $remainingDispenseToUse);
                        

                        
                        if ($qtyToDeduct > 0) {
                            updateRemainingDispenseRecord($pid, $remainingItem['drug_id'], $remainingItem['lot_number'], $qtyToDeduct, false);
                            $remainingDispenseToUse -= $qtyToDeduct;

                        }
                        
                        if ($remainingDispenseToUse <= 0) {
                            break;
                        }
                    }
                }
                
                // Then, update new inventory
                // Apply Hoover facility logic for inventory deduction
                if (!shouldDeductDispenseFromInventory($drug_id)) {
                    $inventoryToDeduct = getAdministerInventoryDeductionQuantity($item, $administer_quantity);
                    error_log("POS Payment Processor - ml form detected: skipping dispense inventory deduction in integrated path");
                } elseif ($isHooverFacility && $isMarketplaceDispense) {
                    // Hoover facility marketplace dispense: Only deduct administer quantity, skip dispense quantity
                    $inventoryToDeduct = getAdministerInventoryDeductionQuantity($item, $administer_quantity);
                    error_log("POS Payment Processor - Hoover Marketplace (remaining dispense): Only deducting administer inventory amount ($inventoryToDeduct) from inventory, skipping dispense quantity ($dispense_quantity)");
                } else {
                    // Regular logic: deduct the full dispense quantity from current lot inventory
                    $inventoryToDeduct = $dispense_quantity;
                    error_log("POS Payment Processor - Regular logic: Deducting dispense quantity ($dispense_quantity) from inventory");
                }
                

                
                if ($inventoryToDeduct > 0) {
    
                    
                                    // Check if we have sufficient inventory - use the most recent inventory record
                $checkQuery = "SELECT di.on_hand, di.inventory_id 
                              FROM drug_inventory di
                              INNER JOIN (
                                SELECT drug_id, lot_number, MAX(inventory_id) as max_inventory_id
                                FROM drug_inventory
                                WHERE drug_id = ? AND lot_number = ? AND destroy_date IS NULL
                                GROUP BY drug_id, lot_number
                              ) latest ON di.drug_id = latest.drug_id 
                                        AND di.lot_number = latest.lot_number 
                                        AND di.inventory_id = latest.max_inventory_id
                              WHERE di.drug_id = ? AND di.lot_number = ? AND di.destroy_date IS NULL";
                $checkResult = sqlQuery($checkQuery, array($drug_id, $lot_number, $drug_id, $lot_number));
                
                $currentQuantity = 0;
                $inventoryId = null;
                if (is_object($checkResult) && method_exists($checkResult, 'FetchRow')) {
                    $checkRow = $checkResult->FetchRow();
                    if ($checkRow && isset($checkRow['on_hand'])) {
                        $currentQuantity = normalizePosQuantity($checkRow['on_hand']);
                        $inventoryId = intval($checkRow['inventory_id']);
                    }
                } elseif (is_array($checkResult) && isset($checkResult['on_hand'])) {
                    $currentQuantity = normalizePosQuantity($checkResult['on_hand']);
                    $inventoryId = intval($checkResult['inventory_id']);
                }
                
                error_log("POS Payment Processor - Current inventory check: drug_id=$drug_id, lot=$lot_number, current_qty=$currentQuantity, inventory_id=$inventoryId");
                
                if ($currentQuantity < $inventoryToDeduct) {
                    throw new Exception("Insufficient inventory for item: " . $item['name'] . " (Lot: " . $lot_number . "). Available: " . $currentQuantity . ", Requested: " . $inventoryToDeduct);
                }
                
                // Update inventory using the specific inventory_id to avoid affecting multiple records
                if ($inventoryId) {
                    $newQoh = $currentQuantity - $inventoryToDeduct;
                    sqlStatement("UPDATE drug_inventory SET on_hand = ? WHERE inventory_id = ?", array($newQoh, $inventoryId));
                    error_log("POS Payment Processor - Updated inventory: drug_id=$drug_id, lot=$lot_number, inventory_id=$inventoryId, deducted=$inventoryToDeduct, new_qoh=$newQoh");
                    
                    // Check if lot is now empty and remove it
                    if ($newQoh <= 0) {
                        sqlStatement("DELETE FROM drug_inventory WHERE inventory_id = ?", array($inventoryId));
                        error_log("POS Payment Processor - Removed empty lot: drug_id=$drug_id, lot=$lot_number, inventory_id=$inventoryId");
                    }
                }
                
            } else {
                // For regular items (including dispensing-only items with quantity = 0)
                
                // First, check if this is a dispensing-only item (quantity = 0, dispense_quantity > 0)
                // If so, we need to find and update the existing remaining dispense record
                if ($quantity === 0 && $dispense_quantity > 0) {
    
                    
                    // Find existing remaining dispense record for this product/lot
                    $remainingQuery = "SELECT id, remaining_quantity FROM pos_remaining_dispense 
                                      WHERE pid = ? AND drug_id = ? AND lot_number = ? AND remaining_quantity > 0";
                    $remainingResult = sqlQuery($remainingQuery, array($pid, $drug_id, $lot_number));
                    
                    $remainingData = null;
                    if (is_object($remainingResult) && method_exists($remainingResult, 'FetchRow')) {
                        $remainingData = $remainingResult->FetchRow();
                    } elseif (is_array($remainingResult)) {
                        $remainingData = $remainingResult;
                    }
                    
                                    if ($remainingData) {
                    // Update the existing remaining dispense record
                    updateRemainingDispenseRecord($pid, $drug_id, $lot_number, $dispense_quantity, false);
                }
                }
                
                // Check if we have sufficient inventory
                $checkRow = posFindActiveInventoryRow($drug_id, $lot_number, $dispense_quantity);
                
                $currentQuantity = 0;
                $inventoryId = null;
                if (is_array($checkRow)) {
                    $currentQuantity = normalizePosQuantity($checkRow['on_hand']);
                    $inventoryId = $checkRow['inventory_id'];
                }
                
                if (shouldDeductDispenseFromInventory($drug_id) && $currentQuantity < $dispense_quantity) {
                    throw new Exception("Insufficient inventory for item: " . $item['name'] . " (Lot: " . $lot_number . "). Available: " . $currentQuantity . ", Requested: " . $dispense_quantity);
                }

                if (shouldDeductDispenseFromInventory($drug_id)) {
                    // Update inventory
                    $newQoh = $currentQuantity - $dispense_quantity;
                    sqlStatement("UPDATE drug_inventory SET on_hand = ? WHERE inventory_id = ?", 
                                array($newQoh, $inventoryId));

                    // Check if lot is now empty and remove it
                    if ($newQoh <= 0) {
                        sqlStatement("DELETE FROM drug_inventory WHERE inventory_id = ?", array($inventoryId));
                    }
                } else {
                    error_log("POS Payment Processor - ml form detected: regular dispense path skipped inventory deduction for drug_id=$drug_id, lot=$lot_number");
                }
            }
        }
        
        // Log the dispense completion
        $timestamp = date('Y-m-d H:i:s');
        $receipt_number = posGenerateReceiptNumber('DISP', $pid);
        
        // Create a dispense record in the database (only if pos_transactions table exists)
        $tableExists = posTableExists('pos_transactions');
        if (!$tableExists) {
            // Create the pos_transactions table if it doesn't exist
            $create_sql = "CREATE TABLE IF NOT EXISTS `pos_transactions` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `pid` int(11) NOT NULL,
                `receipt_number` varchar(50) NOT NULL,
                `transaction_type` varchar(50) NOT NULL DEFAULT 'dispense',
                `payment_method` varchar(50) DEFAULT NULL,
                `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
                `items` longtext NOT NULL,
                `created_date` datetime NOT NULL,
                `user_id` varchar(50) NOT NULL,
                `visit_type` varchar(10) NOT NULL DEFAULT '-',
                `price_override_notes` TEXT NULL,
                `patient_number` INT(11) NULL,
                `facility_id` INT(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `pid` (`pid`),
                KEY `receipt_number` (`receipt_number`),
                KEY `created_date` (`created_date`),
                KEY `transaction_type` (`transaction_type`),
                KEY `facility_id` (`facility_id`),
                KEY `idx_facility_receipt` (`facility_id`, `receipt_number`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            sqlStatement($create_sql);
        }
        
        // Helper functions for administer processing
        function processRemainingAdministerItem($pid, $drug_id, $lot_number, $administer_quantity, $has_remaining_dispense, $remaining_dispense_items, $item = array()) {
            global $pdo;
            error_log("processRemainingAdministerItem - START: pid=$pid, drug_id=$drug_id, lot=$lot_number, administer=$administer_quantity");
            
            $requestedAdminister = $administer_quantity;
            $actualAdministerUsed = 0;
            if ($has_remaining_dispense && !empty($remaining_dispense_items)) {
                error_log("processRemainingAdministerItem - Has remaining dispense items: " . count($remaining_dispense_items));
                foreach ($remaining_dispense_items as $remainingItem) {
                    $originalLotNumber = $remainingItem['lot_number'];
                    $remainingItemQty = $remainingItem['remaining_quantity'];
                    $qtyToUse = min($remainingItemQty, $administer_quantity);
                    if ($qtyToUse > 0) {
                        // Consume patient's remaining from original lots (tracking only)
                        $actualDeducted = updateRemainingDispenseRecord($pid, $drug_id, $originalLotNumber, $qtyToUse, true);
                        $actualDeducted = ($actualDeducted === false) ? 0 : normalizePosQuantity($actualDeducted);
                        $actualAdministerUsed += $actualDeducted;
                        $administer_quantity -= $actualDeducted;
                        if ($administer_quantity <= 0) break;
                    }
                }
            } else {
                if ($administer_quantity > 0) {
                    $actualDeducted = updateRemainingDispenseRecord($pid, $drug_id, $lot_number, $administer_quantity, true);
                    $actualDeducted = ($actualDeducted === false) ? 0 : normalizePosQuantity($actualDeducted);
                    $actualAdministerUsed += $actualDeducted;
                }
            }

            if ($actualAdministerUsed < $requestedAdminister) {
                error_log("processRemainingAdministerItem - Capped administer from $requestedAdminister to $actualAdministerUsed based on remaining dispense availability");
            }

            $inventoryDeductionQuantity = getAdministerInventoryDeductionQuantity($item, $actualAdministerUsed);

            // Always deduct inventory from the current POS-selected lot using PDO
            if ($inventoryDeductionQuantity > 0 && $actualAdministerUsed > 0) {
                $inventoryUpdated = false;
                try {
                    $invData = posFindActiveInventoryRow($drug_id, $lot_number, $inventoryDeductionQuantity);
                    error_log("processRemainingAdministerItem - Inventory resolution found: " . ($invData ? 'yes' : 'no'));
                } catch (PDOException $e) {
                    error_log("processRemainingAdministerItem - PDO inventory query error: " . $e->getMessage());
                    $invData = null;
                }
                
                if ($invData && $invData['on_hand'] >= $inventoryDeductionQuantity) {
                    $newQoh = $invData['on_hand'] - $inventoryDeductionQuantity;
                    try {
                        $updateStmt = $pdo->prepare("UPDATE drug_inventory SET on_hand = ? WHERE inventory_id = ?");
                        $updateStmt->execute([$newQoh, $invData['inventory_id']]);
                        $inventoryUpdated = true;
                        
                        if ($newQoh <= 0) {
                            $deleteStmt = $pdo->prepare("DELETE FROM drug_inventory WHERE inventory_id = ?");
                            $deleteStmt->execute([$invData['inventory_id']]);
                            error_log("processRemainingAdministerItem - Inventory deleted (QOH <= 0)");
                        }
                        error_log("processRemainingAdministerItem - Inventory updated, new QOH: $newQoh");
                    } catch (PDOException $e) {
                        error_log("processRemainingAdministerItem - PDO inventory update error: " . $e->getMessage());
                    }
                }

                if ($inventoryUpdated) {
                    recordDailyAdminister($pid, $drug_id, $lot_number, $actualAdministerUsed);
                    error_log("processRemainingAdministerItem - Daily admin recorded");
                }
            }
            error_log("processRemainingAdministerItem - END");
        }

        function processIntegratedAdministerItem($pid, $drug_id, $lot_number, $quantity, $administer_quantity, $remaining_dispense_items, $item) {
            global $pdo;
            error_log("processIntegratedAdministerItem - START: pid=$pid, drug_id=$drug_id, lot=$lot_number, administer=$administer_quantity");
            
            $isDifferentLotAdminister = isset($item['is_different_lot_dispense']) && $item['is_different_lot_dispense'];
            
            $requestedAdminister = normalizePosQuantity($administer_quantity);
            $newInventoryToUse = min($quantity, $requestedAdminister);
            $remainingDispenseToUse = $administer_quantity - $newInventoryToUse;
            $actualRemainingDispenseUsed = 0;
            
            if ($remainingDispenseToUse > 0) {
                error_log("processIntegratedAdministerItem - Using remaining dispense: $remainingDispenseToUse");
                foreach ($remaining_dispense_items as $remainingItem) {
                    $remainingItemQty = $remainingItem['remaining_quantity'];
                    $qtyToDeduct = min($remainingItemQty, $remainingDispenseToUse);
                    
                    if ($qtyToDeduct > 0) {
                        $actualDeducted = updateRemainingDispenseRecord($pid, $remainingItem['drug_id'], $remainingItem['lot_number'], $qtyToDeduct, true);
                        $actualDeducted = ($actualDeducted === false) ? 0 : normalizePosQuantity($actualDeducted);
                        $actualRemainingDispenseUsed += $actualDeducted;
                        $remainingDispenseToUse -= $actualDeducted;
                    }
                    
                    if ($remainingDispenseToUse <= 0) {
                        break;
                    }
                }
            }
            
            $actualAdministerUsed = normalizePosQuantity($newInventoryToUse + $actualRemainingDispenseUsed);
            if ($actualAdministerUsed < $requestedAdminister) {
                error_log("processIntegratedAdministerItem - Capped administer from $requestedAdminister to $actualAdministerUsed based on available quantity");
            }

            $inventoryDeductionQuantity = getAdministerInventoryDeductionQuantity($item, $actualAdministerUsed);

            // Deduct the full administered quantity from the current lot using PDO
            if ($inventoryDeductionQuantity > 0 && $actualAdministerUsed > 0) {
                $inventoryUpdated = false;
                try {
                    $inventoryData = posFindActiveInventoryRow($drug_id, $lot_number, $inventoryDeductionQuantity);
                    error_log("processIntegratedAdministerItem - Inventory resolution found: " . ($inventoryData ? 'yes' : 'no'));
                } catch (PDOException $e) {
                    error_log("processIntegratedAdministerItem - PDO inventory query error: " . $e->getMessage());
                    $inventoryData = null;
                }
                
                if ($inventoryData && $inventoryData['on_hand'] >= $inventoryDeductionQuantity) {
                    $newQoh = $inventoryData['on_hand'] - $inventoryDeductionQuantity;
                    try {
                        $updateStmt = $pdo->prepare("UPDATE drug_inventory SET on_hand = ? WHERE inventory_id = ?");
                        $updateStmt->execute([$newQoh, $inventoryData['inventory_id']]);
                        $inventoryUpdated = true;
                        
                        if ($newQoh <= 0) {
                            $deleteStmt = $pdo->prepare("DELETE FROM drug_inventory WHERE inventory_id = ?");
                            $deleteStmt->execute([$inventoryData['inventory_id']]);
                            error_log("processIntegratedAdministerItem - Inventory deleted (QOH <= 0)");
                        }
                        error_log("processIntegratedAdministerItem - Inventory updated, new QOH: $newQoh");
                    } catch (PDOException $e) {
                        error_log("processIntegratedAdministerItem - PDO inventory update error: " . $e->getMessage());
                    }
                }

                if ($inventoryUpdated) {
                    recordDailyAdminister($pid, $drug_id, $lot_number, $actualAdministerUsed);
                    error_log("processIntegratedAdministerItem - Daily admin recorded");
                }
            }
            error_log("processIntegratedAdministerItem - END");
        }
        
        // Regular administer: update remaining-dispense for the same lot and deduct inventory
        function processRegularAdministerItem($pid, $drug_id, $lot_number, $administer_quantity, $item = array()) {
            global $pdo;
            if ($administer_quantity <= 0) return;
            
            error_log("processRegularAdministerItem - START: pid=$pid, drug_id=$drug_id, lot=$lot_number, administer=$administer_quantity");
            
            // Update administered_quantity on the same lot's remaining-dispense record if present
            updateRemainingDispenseRecord($pid, $drug_id, $lot_number, $administer_quantity, true);
            error_log("processRegularAdministerItem - Remaining dispense updated");
            
            $inventoryDeductionQuantity = getAdministerInventoryDeductionQuantity($item, $administer_quantity);

            // Deduct inventory from the selected lot when available, otherwise from
            // another active lot of the same medicine in the current facility.
            try {
                $invData = posFindActiveInventoryRow($drug_id, $lot_number, $inventoryDeductionQuantity);
                error_log("processRegularAdministerItem - Inventory resolution found: " . ($invData ? 'yes' : 'no'));
            } catch (PDOException $e) {
                error_log("processRegularAdministerItem - PDO inventory query error: " . $e->getMessage());
                $invData = null;
            }

            if ($invData && $invData['on_hand'] >= $inventoryDeductionQuantity) {
                $newQoh = $invData['on_hand'] - $inventoryDeductionQuantity;
                try {
                    $updateStmt = $pdo->prepare("UPDATE drug_inventory SET on_hand = ? WHERE inventory_id = ?");
                    $updateStmt->execute([$newQoh, $invData['inventory_id']]);
                    
                    if ($newQoh <= 0) {
                        $deleteStmt = $pdo->prepare("DELETE FROM drug_inventory WHERE inventory_id = ?");
                        $deleteStmt->execute([$invData['inventory_id']]);
                        error_log("processRegularAdministerItem - Inventory deleted (QOH <= 0)");
                    }
                    error_log("processRegularAdministerItem - Inventory updated, new QOH: $newQoh");
                    recordDailyAdminister($pid, $drug_id, $lot_number, $administer_quantity);
                    error_log("processRegularAdministerItem - Daily admin recorded");
                } catch (PDOException $e) {
                    error_log("processRegularAdministerItem - PDO inventory update error: " . $e->getMessage());
                }
            }
            error_log("processRegularAdministerItem - END");
        }
        
        /**
         * Process credit payment and update patient credit balance
         */
        function processCreditPayment($pid, $credit_amount, $receipt_number) {
            global $pdo;
            try {
                error_log("Credit payment processing called: pid=$pid, amount=$credit_amount, receipt=$receipt_number");
                ensurePatientCreditTables();

                $pid = (int) $pid;
                $credit_amount = round((float) $credit_amount, 2);
                if ($pid <= 0 || $credit_amount <= 0) {
                    return ['success' => false, 'error' => 'Invalid credit payment request'];
                }

                $startedTransaction = !$pdo->inTransaction();
                if ($startedTransaction) {
                    $pdo->beginTransaction();
                }
                $timestamp = date('Y-m-d H:i:s');

                $balanceStmt = $pdo->prepare("SELECT id, balance FROM patient_credit_balance WHERE pid = ? FOR UPDATE");
                $balanceStmt->execute([$pid]);
                $balanceData = $balanceStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                $current_balance = $balanceData ? (float) $balanceData['balance'] : 0.0;
                error_log("Current credit balance: $current_balance, Credit to apply: $credit_amount");

                if ($current_balance < $credit_amount) {
                    if ($startedTransaction && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log("Insufficient credit balance: current=$current_balance, needed=$credit_amount");
                    return ['success' => false, 'error' => 'Insufficient credit balance'];
                }

                $new_balance = round($current_balance - $credit_amount, 2);

                if ($balanceData) {
                    $updateStmt = $pdo->prepare(
                        "UPDATE patient_credit_balance
                            SET balance = ?, updated_date = NOW()
                          WHERE pid = ?"
                    );
                    $updateStmt->execute([$new_balance, $pid]);
                } else {
                    $insertStmt = $pdo->prepare(
                        "INSERT INTO patient_credit_balance (pid, balance, created_date, updated_date)
                         VALUES (?, ?, NOW(), NOW())"
                    );
                    $insertStmt->execute([$pid, $new_balance]);
                }

                $creditTxnStmt = $pdo->prepare(
                    "INSERT INTO patient_credit_transactions
                        (pid, transaction_type, amount, balance_before, balance_after, receipt_number, description, created_at)
                     VALUES (?, 'payment', ?, ?, ?, ?, 'Credit applied to POS purchase', ?)"
                );
                $creditTxnStmt->execute([$pid, $credit_amount, $current_balance, $new_balance, $receipt_number, $timestamp]);

                if ($startedTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
                error_log("Recorded credit transaction successfully");
                return ['success' => true, 'new_balance' => $new_balance];
                
            } catch (Exception $e) {
                if (isset($startedTransaction) && $startedTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Credit payment processing error: " . $e->getMessage());
                return ['success' => false, 'error' => $e->getMessage()];
            } catch (Error $e) {
                if (isset($startedTransaction) && $startedTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Credit payment processing fatal error: " . $e->getMessage());
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

/**
 * Record external payment (cash, check, etc.)
 */
function recordExternalPayment($pid, $amount, $input, $items) {
    try {
        error_log("recordExternalPayment called - PID: $pid, Amount: $amount");
        error_log("Input data: " . json_encode($input));
        error_log("Items: " . json_encode($items));
        
        $method = $input['method'] ?? 'cash';
        $reference = $input['reference'] ?? '';
        $invoice_number = $input['invoice_number'] ?? '';
        
        error_log("Method: $method, Reference: $reference, Invoice: $invoice_number");
        error_log("recordExternalPayment - About to validate required fields");
        
        // Validate required fields
        if ($amount < 0) {
            error_log("Invalid payment amount: $amount");
            sendJsonResponse(['success' => false, 'error' => xlt('Invalid payment amount')], 400);
        }
        
        if (empty($items)) {
            error_log("No items to process");
            sendJsonResponse(['success' => false, 'error' => xlt('No items to process')], 400);
        }
        
        error_log("recordExternalPayment - Validation passed, about to store transaction");
        
        // Generate transaction record
        $transaction_data = [
            'pid' => $pid,
            'amount' => $amount,
            'payment_method' => $method,
            'reference' => $reference,
            'invoice_number' => $invoice_number,
            'items' => $items,
            'transaction_type' => 'external_payment',
            'status' => 'completed',
            'visit_type' => $input['visit_type'] ?? '-',
            'price_override_notes' => $input['price_override_notes'] ?? null,
             'patient_number' => isset($input['patient_number']) && $input['patient_number'] !== '' ? (int)$input['patient_number']: null,
        ];
        
        // Store transaction in database
        error_log("recordExternalPayment - About to call storeTransaction");
        $transaction_id = storeTransaction($transaction_data);
        error_log("recordExternalPayment - storeTransaction returned: " . ($transaction_id ?: 'false'));
        
        if (!$transaction_id) {
            error_log("recordExternalPayment - storeTransaction failed");
            sendJsonResponse(['success' => false, 'error' => xlt('Failed to record transaction')], 500);
        }
        
        // Note: Inventory updates are now handled by finalizeInvoice to prevent double deduction
        // This function only records the payment transaction
        error_log("recordExternalPayment - Skipping inventory update (handled by finalizeInvoice)");
        
        // Generate receipt data
        error_log("recordExternalPayment - About to generate receipt data");
        $receipt_data = generateReceiptData($transaction_id, $pid, $amount, $items, $method);
        error_log("recordExternalPayment - Receipt data generated");
        
        // Check for any output before sending response
        $output_before_response = ob_get_contents();
        if (!empty($output_before_response)) {
            error_log("Output detected before response: " . substr($output_before_response, 0, 200));
            ob_clean();
        }
        
        error_log("About to send success response for transaction ID: $transaction_id");
        
        // Check for any output before sending response
        $output_before_response = ob_get_contents();
        if (!empty($output_before_response)) {
            error_log("Output detected before response: " . substr($output_before_response, 0, 500));
            ob_clean();
        }
        
        error_log("recordExternalPayment - About to send final success response");
        sendJsonResponse([
            'success' => true,
            'transaction_id' => $transaction_id,
            'receipt_data' => $receipt_data,
            'message' => xlt('Payment recorded successfully')
        ]);
        error_log("recordExternalPayment - Success response sent");
        
    } catch (Exception $e) {
        error_log("External payment processing error: " . $e->getMessage());
        sendJsonResponse(['success' => false, 'error' => xlt('Payment processing failed')], 500);
    }
}

/**
 * Get patient credit balance
 */
function getPatientCredit($pid) {
    try {
        // Get patient credit balance from database
        $credit_query = "SELECT credit_balance FROM patient_credit WHERE pid = ?";
        $credit_result = sqlQuery($credit_query, [$pid]);
        
        $credit_balance = $credit_result ? floatval($credit_result['credit_balance']) : 0.00;
        
        sendJsonResponse([
            'success' => true,
            'credit_balance' => $credit_balance
        ]);
        
    } catch (Exception $e) {
        error_log("Get patient credit error: " . $e->getMessage());
        sendJsonResponse(['success' => false, 'error' => xlt('Failed to get credit balance')], 500);
    }
}

/**
 * Get refundable items for patient
 */
function getRefundableItems($pid) {
    try {
        // Get refundable transactions for the patient
        $refund_query = "SELECT transaction_id, amount, payment_method, created_date, items 
                        FROM pos_transactions 
                        WHERE pid = ? AND status = 'completed' 
                        AND created_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                        ORDER BY created_date DESC";
        
        $refund_result = sqlStatement($refund_query, [$pid]);
        $refundable_items = [];
        
        while ($row = sqlFetchArray($refund_result)) {
            $items = json_decode($row['items'], true);
            if ($items && is_array($items)) {
                foreach ($items as $item) {
                    if (isset($item['dispense_quantity']) && $item['dispense_quantity'] > 0) {
                        $refundable_items[] = [
                            'transaction_id' => $row['transaction_id'],
                            'drug_id' => $item['id'],
                            'drug_name' => $item['name'],
                            'quantity' => $item['dispense_quantity'],
                            'amount' => $item['price'] * $item['dispense_quantity'],
                            'date' => $row['created_date'],
                            'payment_method' => $row['payment_method']
                        ];
                    }
                }
            }
        }
        
        sendJsonResponse([
            'success' => true,
            'refundable_items' => $refundable_items
        ]);
        
    } catch (Exception $e) {
        error_log("Get refundable items error: " . $e->getMessage());
        sendJsonResponse(['success' => false, 'error' => xlt('Failed to get refundable items')], 500);
    }
}

/**
 * Update inventory for administered items
 */
function updateInventoryForAdminister($pid, $item, $transaction_id) {
    global $pdo;
    try {
        error_log("updateInventoryForAdminister called - PID: $pid, Item: " . $item['name'] . ", Transaction ID: $transaction_id");
        
        $item_id = $item['id'] ?? '';
        $drug_id = getPosItemDrugId($item);
        
        $administer_quantity = normalizePosQuantity($item['administer_quantity']);
        $lot_number = getPosItemLotNumber($item);
        $inventoryDeductionQuantity = getAdministerInventoryDeductionQuantity($item, $administer_quantity);
        $quantityColumn = posResolveDrugInventoryQuantityColumn();
        
        error_log("updateInventoryForAdminister - Item ID: $item_id, Drug ID: $drug_id, Administer Quantity: $administer_quantity, Inventory Deduction Quantity: $inventoryDeductionQuantity, Lot: $lot_number");
        
        if ($inventoryDeductionQuantity <= 0) {
            error_log("updateInventoryForAdminister - No quantity to administer, returning true");
            return true; // Nothing to administer
        }

        $inventoryRow = posFindActiveInventoryRow($drug_id, $lot_number, $inventoryDeductionQuantity);
        if (!$inventoryRow) {
            error_log("updateInventoryForAdminister - No eligible inventory found for drug_id: $drug_id, preferred lot: $lot_number, required qty: $inventoryDeductionQuantity");
            return false;
        }

        $resolvedInventoryId = (int) ($inventoryRow['inventory_id'] ?? 0);
        $resolvedLotNumber = trim((string) ($inventoryRow['lot_number'] ?? $lot_number));
        $currentQuantity = normalizePosQuantity($inventoryRow['on_hand'] ?? 0);
        $newQuantity = $currentQuantity - $inventoryDeductionQuantity;
        
        if ($newQuantity < 0) {
            error_log("updateInventoryForAdminister - Insufficient inventory: current=$currentQuantity, requested=$inventoryDeductionQuantity, lot=$resolvedLotNumber");
            return false;
        }

        if ($resolvedInventoryId <= 0) {
            error_log("updateInventoryForAdminister - Resolved inventory row is missing inventory_id for drug_id=$drug_id, lot=$resolvedLotNumber");
            return false;
        }

        // Update the exact resolved inventory row so staging/local behave consistently.
        $update_query = "UPDATE drug_inventory SET {$quantityColumn} = ? WHERE inventory_id = ?";
        $stmt = $pdo->prepare($update_query);
        $result = $stmt->execute([$newQuantity, $resolvedInventoryId]);
        
        if ($result && $stmt->rowCount() > 0) {
            error_log("updateInventoryForAdminister - Successfully updated inventory: drug_id=$drug_id, lot=$resolvedLotNumber, deducted=$inventoryDeductionQuantity, old_qty=$currentQuantity, new_qty=$newQuantity, inventory_id=$resolvedInventoryId");
            return true;
        } else {
            error_log("updateInventoryForAdminister - Failed to update inventory row: inventory_id=$resolvedInventoryId, lot=$resolvedLotNumber");
            return false;
        }
        
    } catch (Exception $e) {
        error_log("updateInventoryForAdminister error: " . $e->getMessage());
        return false;
    }
}

/**
 * Store transaction in database
 */
function storeTransaction($transaction_data) {
    global $pdo;
    try {
        error_log("storeTransaction called with data: " . json_encode($transaction_data));
        
        $pid = intval($transaction_data['pid']);
        $amount = floatval($transaction_data['amount']);
        $items = json_encode($transaction_data['items']);
        $transaction_type = $transaction_data['transaction_type'] ?? 'external_payment';
        $receipt_number = posResolveReceiptNumber(
            $transaction_data['invoice_number'] ?? ($transaction_data['receipt_number'] ?? null),
            'INV',
            $pid,
            $transaction_data['facility_id'] ?? null
        );
        $user_id = $_SESSION['authUserID'] ?? 'pos_user';
        
        error_log("Processed data - PID: $pid, Amount: $amount, Type: $transaction_type, Receipt: $receipt_number, User: $user_id");
        
        // Get payment method from transaction data
        $payment_method = $transaction_data['payment_method'] ?? 'cash';
       $visit_type = $transaction_data['visit_type'] ?? ($_POST['visit_type'] ?? '-');
       $price_override_notes = $transaction_data['price_override_notes'] ?? null;
       $patient_number = isset($transaction_data['patient_number'])
    ? (int)$transaction_data['patient_number']
    : (int)($_POST['patient_number'] ?? null);

        if ($patient_number === 0) {
            $patient_number = null;
        }
        if (!in_array($visit_type, ['F', 'R', 'N', '-'], true)) {
            $visit_type = '-';
        }

        $result = posInsertTransaction([
            'pid' => $pid,
            'receipt_number' => $receipt_number,
            'transaction_type' => $transaction_type,
            'payment_method' => $payment_method,
            'amount' => $amount,
            'items' => $items,
            'created_date' => date('Y-m-d H:i:s'),
            'user_id' => $user_id,
            'visit_type' => $visit_type,
            'price_override_notes' => $price_override_notes,
            'patient_number' => $patient_number,
        ]);
        
        error_log("SQL result: " . ($result ? 'success' : 'failed'));
        
        if (!$result) {
            error_log("SQL statement failed - check database connection and query syntax");
            return false;
        }
        
        if ($result) {
            $insert_id = $pdo->lastInsertId();
            error_log("Insert ID: $insert_id");
            return $insert_id;
        }
        
        error_log("SQL statement failed");
        return false;
        
    } catch (Exception $e) {
        error_log("Store transaction error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * Update inventory for dispensed items
 */
function updateInventoryForDispense($pid, $item, $transaction_id) {
    global $pdo;
    try {
        error_log("updateInventoryForDispense called - PID: $pid, Item: " . $item['name'] . ", Transaction ID: $transaction_id");
        
        $item_id = $item['id'] ?? '';
        $drug_id = getPosItemDrugId($item);
        
        $dispense_quantity = normalizePosQuantity($item['dispense_quantity']);
        $lipoDeductionPerUnit = getLipoInventoryDeductionPerUnit($drug_id);
        $inventoryDeductionQuantity = $lipoDeductionPerUnit !== null
            ? normalizePosQuantity($lipoDeductionPerUnit * $dispense_quantity)
            : $dispense_quantity;
        $lot_number = getPosItemLotNumber($item);
        
        error_log("updateInventoryForDispense - Item ID: $item_id, Drug ID: $drug_id, Dispense Quantity: $dispense_quantity, Inventory Deduction Quantity: $inventoryDeductionQuantity, Lot: $lot_number");
        
        if ($inventoryDeductionQuantity <= 0) {
            error_log("updateInventoryForDispense - No quantity to dispense, returning true");
            return true; // Nothing to dispense
        }

        if (!shouldDeductDispenseFromInventory($drug_id)) {
            error_log("updateInventoryForDispense - ml form detected, skipping inventory deduction for dispense");
            return true;
        }
        
        // Update inventory quantity
        error_log("updateInventoryForDispense - About to update drug_inventory");
        $update_query = "UPDATE drug_inventory 
                        SET on_hand = on_hand - ? 
                        WHERE drug_id = ? AND lot_number = ? AND on_hand >= ?";
        
        $stmt = $pdo->prepare($update_query);
        $result = $stmt->execute([$inventoryDeductionQuantity, $drug_id, $lot_number, $inventoryDeductionQuantity]);
        
        if ($result && $stmt->rowCount() > 0) {
            error_log("updateInventoryForDispense - Inventory updated successfully");
        } else {
            error_log("updateInventoryForDispense - Inventory update failed or no rows affected");
            return false;
        }
        
        // Tracking is now handled in finalizeInvoice to ensure all quantities are recorded correctly
        error_log("updateInventoryForDispense - Tracking handled in finalizeInvoice, skipping duplicate tracking here");
            return true;
        
    } catch (Exception $e) {
        error_log("Update inventory for dispense error: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate receipt data
 */
function generateReceiptData($transaction_id, $pid, $amount, $items, $method) {
    global $pdo;
    try {
        error_log("generateReceiptData called - Transaction ID: $transaction_id, PID: $pid, Amount: $amount, Method: $method");
        
        // Get patient information
        $patient_query = "SELECT fname, lname, DOB FROM patient_data WHERE pid = ?";
        $stmt = $pdo->prepare($patient_query);
        $stmt->execute([$pid]);
        $patient_result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $patient_name = '';
        if ($patient_result) {
            $patient_name = $patient_result['fname'] . ' ' . $patient_result['lname'];
            error_log("generateReceiptData - Patient name: $patient_name");
        } else {
            error_log("generateReceiptData - No patient found for PID: $pid");
        }
        
        // Generate receipt data
        $receipt_data = [
            'transaction_id' => $transaction_id,
            'patient_id' => $pid,
            'patient_name' => $patient_name,
            'amount' => $amount,
            'payment_method' => $method,
            'items' => $items,
            'date' => date('Y-m-d H:i:s'),
            'invoice_number' => posGenerateReceiptNumber('INV', $pid)
        ];
        
        error_log("generateReceiptData - Receipt data generated successfully");
        return $receipt_data;
        
    } catch (Exception $e) {
        error_log("Generate receipt data error: " . $e->getMessage());
        return [
            'transaction_id' => $transaction_id,
            'patient_id' => $pid,
            'amount' => $amount,
            'payment_method' => $method,
            'date' => date('Y-m-d H:i:s')
        ];
    }
}

function getPosItemDrugId(array $item): int
{
    $drugId = intval($item['drug_id'] ?? 0);
    if ($drugId > 0) {
        return $drugId;
    }

    $itemId = (string)($item['id'] ?? '');
    if ($itemId !== '' && preg_match('/drug_(\d+)/', $itemId, $matches)) {
        return intval($matches[1]);
    }

    return 0;
}

function getPosItemLotNumber(array $item): string
{
    $lotNumber = trim((string)($item['lot_number'] ?? ($item['lot'] ?? '')));
    if ($lotNumber !== '') {
        return $lotNumber;
    }

    $itemId = (string)($item['id'] ?? '');
    if ($itemId !== '' && preg_match('/drug_\d+_lot_(.+)/', $itemId, $matches)) {
        return trim((string)$matches[1]);
    }

    return '';
}

/**
 * Finalize invoice - complete the transaction
 */
function finalizeInvoice($pid, $input) {
    global $pdo;
    try {
        error_log("finalizeInvoice function called - PID: $pid");
        error_log("finalizeInvoice - Input data: " . json_encode($input));
        
        $invoice_number = $input['invoice_number'] ?? '';
        
        if (empty($invoice_number)) {
            error_log("finalizeInvoice - No invoice number provided");
            sendJsonResponse(['success' => false, 'error' => 'Invoice number is required'], 400);
            return;
        }
        ensurePosFinalizeSchema();
        ensurePosRemainingDispenseSchema();
        ensurePosReceiptUniquenessSchema();

        $pdo->beginTransaction();

        $transaction_query = "SELECT * FROM pos_transactions WHERE pid = ? AND receipt_number = ? ORDER BY created_date DESC LIMIT 1 FOR UPDATE";
        $stmt = $pdo->prepare($transaction_query);
        $stmt->execute([$pid, $invoice_number]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transaction) {
            $pdo->commit();
            error_log("finalizeInvoice - No transaction found for invoice: $invoice_number");
            sendJsonResponse([
                'success' => true,
                'message' => 'Payment recorded successfully!',
                'invoice_number' => $invoice_number
            ]);
            return;
        }

        $creditAmountRequested = round((float) ($input['credit_amount'] ?? 0), 2);
        $creditAmountApplied = 0.0;
        $alreadyFinalized = !empty($transaction['finalized']);

        try {
            $existingCredit = sqlFetchArray(sqlStatement(
                "SELECT COALESCE(SUM(amount), 0) AS applied_credit
                 FROM patient_credit_transactions
                 WHERE pid = ? AND receipt_number = ? AND transaction_type = 'payment'",
                [$pid, $invoice_number]
            ));
            $creditAmountApplied = round((float) ($existingCredit['applied_credit'] ?? 0), 2);
        } catch (Exception $e) {
            error_log("finalizeInvoice - Could not read existing credit transactions: " . $e->getMessage());
            $creditAmountApplied = 0.0;
        }

        if ($alreadyFinalized) {
            $receipt_data = posBuildFinalizedReceiptData($pid, $transaction, $input, $creditAmountApplied);
            posUpsertFinalizedReceiptRecord($pid, $transaction, $receipt_data);
            $pdo->commit();
            error_log("finalizeInvoice - Invoice already finalized, short-circuiting: $invoice_number");
            sendJsonResponse([
                'success' => true,
                'message' => 'Payment already finalized.',
                'invoice_number' => $invoice_number,
                'receipt_number' => $invoice_number,
                'receipt_data' => $receipt_data,
                'credit_amount' => $creditAmountApplied,
                'credit_success' => true,
                'already_finalized' => true
            ]);
            return;
        }

        if ($creditAmountRequested > 0 && $creditAmountApplied <= 0) {
            $creditResult = processCreditPayment($pid, $creditAmountRequested, $invoice_number);
            if (empty($creditResult['success'])) {
                $pdo->rollBack();
                sendJsonResponse([
                    'success' => false,
                    'error' => $creditResult['error'] ?? 'Failed to apply credit balance'
                ], 400);
                return;
            }
            $creditAmountApplied = $creditAmountRequested;
        }

        $items = json_decode($transaction['items'], true);
        $transaction_id = $transaction['id'];
        error_log("finalizeInvoice - Processing inventory updates for transaction ID: $transaction_id");

        if (!empty($items)) {
            foreach ($items as $item) {
                $hasQuantity = isset($item['quantity']) && $item['quantity'] > 0;
                $hasDispenseQuantity = isset($item['dispense_quantity']) && $item['dispense_quantity'] > 0;
                $hasAdministerQuantity = isset($item['administer_quantity']) && $item['administer_quantity'] > 0;

                $drug_id = getPosItemDrugId($item);
                $lot_number = getPosItemLotNumber($item);

                if (!$drug_id || !$lot_number) {
                    error_log("finalizeInvoice - WARNING: Could not parse drug_id and lot_number from item ID: " . ($item['id'] ?? ''));
                    continue;
                }

                $totalQuantity = normalizePosQuantity($item['quantity']);
                $dispenseQuantity = normalizePosQuantity($item['dispense_quantity'] ?? 0);
                $administerQuantity = normalizePosQuantity($item['administer_quantity'] ?? 0);
                $isRemainingDispense = isset($item['is_remaining_dispense']) && $item['is_remaining_dispense'];

                error_log("finalizeInvoice - Item: {$item['name']}, Qty=$totalQuantity, Dispense=$dispenseQuantity, Admin=$administerQuantity, IsRemainingDispense=" . ($isRemainingDispense ? 'YES' : 'NO'));
                error_log("finalizeInvoice - hasQuantity=" . ($hasQuantity ? 'true' : 'false') . ", hasDispenseQuantity=" . ($hasDispenseQuantity ? 'true' : 'false') . ", hasAdministerQuantity=" . ($hasAdministerQuantity ? 'true' : 'false'));

                if (!posTableExists('pos_remaining_dispense')) {
                    error_log("finalizeInvoice - WARNING: pos_remaining_dispense table does not exist");
                    continue;
                }

                $receipt_number = $transaction['receipt_number'] ?? $invoice_number;

                if ($hasQuantity) {
                    error_log("finalizeInvoice - CASE 1: New Purchase (Qty > 0)");
                    $totalUsed = $dispenseQuantity + $administerQuantity;
                    $remainingQuantity = $totalQuantity - $totalUsed;

                    $insert_sql = "INSERT INTO pos_remaining_dispense 
                                  (pid, drug_id, lot_number, total_quantity, dispensed_quantity, administered_quantity, remaining_quantity, receipt_number, created_date, last_updated) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                    $insert_stmt = $pdo->prepare($insert_sql);
                    $insert_stmt->execute([
                        $pid,
                        $drug_id,
                        $lot_number,
                        $totalQuantity,
                        $dispenseQuantity,
                        $administerQuantity,
                        $remainingQuantity,
                        $receipt_number
                    ]);
                } elseif (!$hasQuantity && ($hasDispenseQuantity || $hasAdministerQuantity)) {
                    error_log("finalizeInvoice - CASE 2: Using Remaining Dispense (Qty=0, Dispense=$dispenseQuantity, Admin=$administerQuantity)");
                    $find_sql = "SELECT id, total_quantity, dispensed_quantity, administered_quantity, remaining_quantity, receipt_number 
                                FROM pos_remaining_dispense 
                                WHERE pid = ? AND drug_id = ? AND lot_number = ? AND remaining_quantity > 0 
                                ORDER BY created_date ASC
                                FOR UPDATE";
                    $find_stmt = $pdo->prepare($find_sql);
                    $find_stmt->execute([$pid, $drug_id, $lot_number]);
                    $existing_records = $find_stmt->fetchAll(PDO::FETCH_ASSOC);

                    $dispenseToUse = $dispenseQuantity;
                    $administerToUse = $administerQuantity;

                    foreach ($existing_records as $record) {
                        if ($dispenseToUse <= 0 && $administerToUse <= 0) {
                            break;
                        }

                        $recordId = $record['id'];
                        $currentRemaining = normalizePosQuantity($record['remaining_quantity']);
                        $dispenseFromThis = min($dispenseToUse, $currentRemaining);
                        $administerFromThis = min($administerToUse, $currentRemaining - $dispenseFromThis);
                        $totalFromThis = $dispenseFromThis + $administerFromThis;

                        if ($totalFromThis > 0) {
                            $newRemaining = $currentRemaining - $totalFromThis;
                            $update_sql = "UPDATE pos_remaining_dispense 
                                          SET remaining_quantity = ?, 
                                              last_updated = NOW() 
                                          WHERE id = ?";
                            $update_stmt = $pdo->prepare($update_sql);
                            $update_stmt->execute([$newRemaining, $recordId]);

                            $dispenseToUse -= $dispenseFromThis;
                            $administerToUse -= $administerFromThis;
                        }
                    }

                    if ($dispenseToUse > 0 || $administerToUse > 0) {
                        error_log("finalizeInvoice - Insufficient remaining quantity: dispense=$dispenseToUse, administer=$administerToUse");
                    }

                    error_log("finalizeInvoice - CASE 2: Creating transaction entry (Total=0, Disp=$dispenseQuantity, Admin=$administerQuantity)");
                    $insert_sql = "INSERT INTO pos_remaining_dispense 
                                  (pid, drug_id, lot_number, total_quantity, dispensed_quantity, administered_quantity, remaining_quantity, receipt_number, created_date, last_updated) 
                                  VALUES (?, ?, ?, 0, ?, ?, 0, ?, NOW(), NOW())";
                    $insert_stmt = $pdo->prepare($insert_sql);
                    $insert_stmt->execute([
                        $pid,
                        $drug_id,
                        $lot_number,
                        $dispenseQuantity,
                        $administerQuantity,
                        $receipt_number
                    ]);
                    error_log("finalizeInvoice - CASE 2: Transaction entry created successfully! ID: " . $pdo->lastInsertId());
                } else {
                    error_log("finalizeInvoice - CASE 3: Neither CASE 1 nor CASE 2 matched. hasQuantity=" . ($hasQuantity ? 'true' : 'false') . ", hasDispense=" . ($hasDispenseQuantity ? 'true' : 'false') . ", hasAdmin=" . ($hasAdministerQuantity ? 'true' : 'false'));
                }

                if ($hasDispenseQuantity || $hasAdministerQuantity) {
                    $isMarketplaceDispense = isset($item['marketplace_dispense']) && $item['marketplace_dispense'];

                    if ($hasDispenseQuantity && (($isMarketplaceDispense && !isHooverMarketplaceDispenseException($item)) || !shouldDeductDispenseFromInventory($drug_id))) {
                        error_log("finalizeInvoice - Skipping dispense inventory update for marketplace dispensed item: " . $item['name']);
                    } elseif ($hasDispenseQuantity) {
                        error_log("finalizeInvoice - Updating inventory for dispensed item: " . $item['name']);
                        if (!updateInventoryForDispense($pid, $item, $transaction_id)) {
                            throw new Exception("Failed to update inventory for dispensed item: " . ($item['name'] ?? 'Unknown'));
                        }
                    }

                    if ($hasAdministerQuantity) {
                        error_log("finalizeInvoice - Updating inventory for administered item: " . $item['name']);
                        if (!updateInventoryForAdminister($pid, $item, $transaction_id)) {
                            throw new Exception("Failed to update inventory for administered item: " . ($item['name'] ?? 'Unknown'));
                        }
                    }
                }
            }
        }

        $finalizedBy = (string) ($_SESSION['authUser'] ?? $_SESSION['authUserID'] ?? 'system');
        $finalizeUpdateSql = "UPDATE pos_transactions
                                 SET finalized = 1,
                                     finalized_at = NOW(),
                                     finalized_by = ?
                               WHERE id = ?";
        $finalizeStmt = $pdo->prepare($finalizeUpdateSql);
        $finalizeStmt->execute([$finalizedBy, $transaction_id]);

        $transaction['finalized'] = 1;
        $transaction['finalized_at'] = date('Y-m-d H:i:s');
        $transaction['finalized_by'] = $finalizedBy;

        $receipt_data = posBuildFinalizedReceiptData($pid, $transaction, $input, $creditAmountApplied);
        posUpsertFinalizedReceiptRecord($pid, $transaction, $receipt_data);
        $pdo->commit();

        error_log("finalizeInvoice - Invoice finalized successfully: $invoice_number with receipt data");
        sendJsonResponse([
            'success' => true,
            'message' => 'Payment recorded successfully!',
            'invoice_number' => $invoice_number,
            'receipt_number' => $invoice_number,
            'receipt_data' => $receipt_data,
            'credit_amount' => $creditAmountApplied,
            'credit_success' => true
        ]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Finalize invoice error: " . $e->getMessage());
        error_log("Finalize invoice trace: " . $e->getTraceAsString());
        $response = ['success' => false, 'error' => 'Failed to finalize invoice'];
        if (posIsStagingRequest()) {
            $response['debug_error'] = $e->getMessage();
        }
        sendJsonResponse($response, 500);
    }
}

/**
 * Track marketplace dispense for Hoover facility
 */
function trackMarketplaceDispense($pid, $drug_id, $lot_number, $dispense_quantity) {
    // For marketplace dispense, we just track the dispense without inventory deduction
    // This is similar to remaining dispense tracking but for marketplace items
    
    // Create marketplace dispense tracking table if it doesn't exist
    $create_sql = "CREATE TABLE IF NOT EXISTS marketplace_dispense_tracking (
        id int(11) NOT NULL AUTO_INCREMENT,
        pid int(11) NOT NULL,
        drug_id int(11) NOT NULL,
        lot_number varchar(50) NOT NULL,
        dispense_quantity int(11) NOT NULL DEFAULT 0,
        dispense_date datetime DEFAULT CURRENT_TIMESTAMP,
        created_by varchar(50) DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_patient_drug (pid, drug_id),
        KEY idx_drug_lot (drug_id, lot_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    sqlStatement($create_sql);
    
    // Record the marketplace dispense
    $insert_sql = "INSERT INTO marketplace_dispense_tracking SET 
        pid = ?, 
        drug_id = ?, 
        lot_number = ?, 
        dispense_quantity = ?, 
        dispense_date = NOW(),
        created_by = ?";
    
    $result = sqlStatement($insert_sql, array(
        $pid, 
        $drug_id, 
        $lot_number, 
        $dispense_quantity,
        $_SESSION['authUserID']
    ));
    
    if ($result !== false) {
        error_log("Marketplace dispense tracked successfully: PID=$pid, Drug=$drug_id, Lot=$lot_number, Qty=$dispense_quantity");
    } else {
        error_log("Warning: Could not track marketplace dispense");
    }
}

/**
 * Switch Medicine Function
 * Allows switching remaining dispense quantities from one medicine to another
 */
function switchMedicine($pid, $input) {
    global $pdo;
    try {
        error_log("switchMedicine called - PID: $pid, Input: " . json_encode($input));
        
        $current_drug_id = intval($input['current_drug_id'] ?? 0);
        $new_drug_id = intval($input['new_drug_id'] ?? 0);
        $quantity = normalizePosQuantity($input['quantity'] ?? 0);
        
        error_log("switchMedicine - Parsed: current_drug_id=$current_drug_id, new_drug_id=$new_drug_id, quantity=$quantity");
        
        if (!$pid || !$current_drug_id || !$new_drug_id || !$quantity) {
            $error = "Missing required parameters: pid=$pid, current_drug_id=$current_drug_id, new_drug_id=$new_drug_id, quantity=$quantity";
            error_log("switchMedicine - " . $error);
            cleanOutputAndSendJson(['success' => false, 'error' => $error], 400);
            return;
        }
        
        if ($current_drug_id === $new_drug_id) {
            cleanOutputAndSendJson(['success' => false, 'error' => 'Cannot switch to the same medicine'], 400);
            return;
        }
        
        // Check if patient has enough remaining quantity of current medicine
        $check_sql = "SELECT SUM(remaining_quantity) as total_remaining 
                      FROM pos_remaining_dispense 
                      WHERE pid = ? AND drug_id = ? AND remaining_quantity > 0";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$pid, $current_drug_id]);
        $check_result = $check_stmt->fetch(PDO::FETCH_ASSOC);
        $total_remaining = normalizePosQuantity($check_result['total_remaining'] ?? 0);
        
        error_log("switchMedicine - Total remaining for drug $current_drug_id: $total_remaining");
        
        if ($total_remaining < $quantity) {
            cleanOutputAndSendJson(['success' => false, 'error' => "Insufficient remaining quantity. Available: $total_remaining, Requested: $quantity"], 400);
            return;
        }
        
        // Get current medicine name
        $current_med_sql = "SELECT name FROM drugs WHERE drug_id = ?";
        $current_med_stmt = $pdo->prepare($current_med_sql);
        $current_med_stmt->execute([$current_drug_id]);
        $current_med_result = $current_med_stmt->fetch(PDO::FETCH_ASSOC);
        $current_med_name = $current_med_result['name'] ?? 'Unknown';
        
        // Get new medicine name
        $new_med_sql = "SELECT name FROM drugs WHERE drug_id = ?";
        $new_med_stmt = $pdo->prepare($new_med_sql);
        $new_med_stmt->execute([$new_drug_id]);
        $new_med_result = $new_med_stmt->fetch(PDO::FETCH_ASSOC);
        $new_med_name = $new_med_result['name'] ?? 'Unknown';
        
        error_log("switchMedicine - Medicine names: From '$current_med_name' to '$new_med_name'");
        
        // Start transaction
        $pdo->beginTransaction();
        
        try {
            // Get all remaining dispense records for the current medicine (FIFO order)
            $get_records_sql = "SELECT id, remaining_quantity 
                               FROM pos_remaining_dispense 
                               WHERE pid = ? AND drug_id = ? AND remaining_quantity > 0
                               ORDER BY created_date ASC";
            $records_stmt = $pdo->prepare($get_records_sql);
            $records_stmt->execute([$pid, $current_drug_id]);
            $records = $records_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("switchMedicine - Found " . count($records) . " records to deduct from");
            
            $remaining_to_deduct = $quantity;
            $deducted = 0;
            
            // Deduct from each record in FIFO order
            foreach ($records as $record) {
                if ($remaining_to_deduct <= 0) break;
                
                $deduct_from_this = min($remaining_to_deduct, $record['remaining_quantity']);
                $new_remaining = $record['remaining_quantity'] - $deduct_from_this;
                
                error_log("switchMedicine - Deducting $deduct_from_this from record {$record['id']}, new remaining: $new_remaining");
                
                $update_sql = "UPDATE pos_remaining_dispense 
                              SET remaining_quantity = ?,
                                  last_updated = NOW()
                              WHERE id = ?";
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([$new_remaining, $record['id']]);
                
                $deducted += $deduct_from_this;
                $remaining_to_deduct -= $deduct_from_this;
            }
            
            if ($deducted < $quantity) {
                throw new Exception("Could not deduct full quantity. Deducted: $deducted, Requested: $quantity");
            }
            
            error_log("switchMedicine - Successfully deducted $deducted units");
            
            // Add to new medicine remaining quantities
            $add_sql = "INSERT INTO pos_remaining_dispense 
                       (pid, drug_id, lot_number, total_quantity, dispensed_quantity, 
                        administered_quantity, remaining_quantity, created_date, last_updated, 
                        receipt_number) 
                       VALUES (?, ?, 'SWITCH', ?, 0, 0, ?, NOW(), NOW(), ?)";
            
            $receipt_number = posGenerateReceiptNumber('SWITCH', $pid);
            
            error_log("switchMedicine - Adding $quantity units of new medicine, receipt: $receipt_number");
            
            $add_stmt = $pdo->prepare($add_sql);
            $add_stmt->execute([
                $pid,
                $new_drug_id,
                $quantity,
                $quantity,
                $receipt_number
            ]);
            
            // Log the switch in pos_transactions
            $user_id = $_SESSION['authUserID'] ?? '0';
            $items_json = json_encode([
                'switch' => [
                    'from_drug_id' => $current_drug_id,
                    'from_drug_name' => $current_med_name,
                    'to_drug_id' => $new_drug_id,
                    'to_drug_name' => $new_med_name,
                    'quantity' => $quantity
                ]
            ]);
            
            error_log("switchMedicine - Logging transaction");
            
            posInsertTransaction([
                'pid' => $pid,
                'receipt_number' => $receipt_number,
                'transaction_type' => 'medicine_switch',
                'amount' => 0,
                'items' => $items_json,
                'created_date' => date('Y-m-d H:i:s'),
                'user_id' => $user_id,
                'price_override_notes' => null,
            ]);
            
            // Commit transaction
            $pdo->commit();
            
            error_log("switchMedicine - Success! Transaction committed");
            
            cleanOutputAndSendJson([
                'success' => true,
                'message' => "Successfully switched $quantity units from '$current_med_name' to '$new_med_name'",
                'receipt_number' => $receipt_number
            ]);
            
        } catch (Exception $e) {
            // Rollback transaction
            $pdo->rollBack();
            error_log("switchMedicine - Rolling back transaction: " . $e->getMessage());
            throw $e;
        }
        
    } catch (Exception $e) {
        error_log("Medicine switch error: " . $e->getMessage());
        error_log("Medicine switch error trace: " . $e->getTraceAsString());
        cleanOutputAndSendJson(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

?> 
