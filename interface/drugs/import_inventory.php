<?php

/**
 * Inventory CSV import.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$sessionAllowWrite = true;
require_once("../globals.php");
require_once("drugs.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$authAdmin = AclMain::aclCheckCore('admin', 'drugs');
$authImport = $authAdmin ||
    AclMain::aclCheckCore('inventory', 'lots') ||
    AclMain::aclCheckCore('inventory', 'purchases') ||
    AclMain::aclCheckCore('inventory', 'adjustments');

if (!$authImport) {
    die(xlt('Inventory import is not authorized.'));
}

function inventoryImportCurrentFacilityId(): int
{
    if (!empty($_SESSION['facilityId'])) {
        return (int) $_SESSION['facilityId'];
    }

    if (!empty($_SESSION['facility_id'])) {
        return (int) $_SESSION['facility_id'];
    }

    return 0;
}

function inventoryImportColumns(): array
{
    return [
        'Drug ID',
        'Inventory ID',
        'Name',
        'Active',
        'Consumable',
        'Dispensable',
        'NDC',
        'Form',
        'Size',
        'Strength',
        'Unit',
        'Route',
        'Category',
        'Subcategory',
        'Product Cost',
        'Product Price',
        'Lot Number',
        'Lot QOH',
        'Expiration',
        'Manufacturer',
        'Lot Cost',
        'Lot Price',
        'Facility ID',
        'Facility',
        'Warehouse ID',
        'Warehouse',
    ];
}

function inventoryImportNormalizeHeader(string $header): string
{
    return strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', ' ', $header)));
}

function inventoryImportValue(array $row, string $key): string
{
    return trim((string) ($row[$key] ?? ''));
}

function inventoryImportFetchOne(string $sql, array $params = []): array
{
    $result = sqlQuery($sql, $params);

    if (is_array($result)) {
        return $result;
    }

    if (is_object($result)) {
        $row = sqlFetchArray($result);
        return is_array($row) ? $row : [];
    }

    return [];
}

function inventoryImportIgnoredColumns(): array
{
    return [
        'inventory id',
        'lot number',
        'lot qoh',
        'expiration',
        'manufacturer',
        'lot cost',
        'lot price',
        'facility id',
        'facility',
        'warehouse id',
        'warehouse',
    ];
}

function inventoryImportSanitizeRow(array $row): array
{
    foreach (inventoryImportIgnoredColumns() as $column) {
        if (array_key_exists($column, $row)) {
            $row[$column] = '';
        }
    }

    return $row;
}

function inventoryImportDecimal(?string $value): ?float
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $value = str_replace(['$', ','], '', $value);
    return is_numeric($value) ? (float) $value : null;
}

function inventoryImportBool(?string $value, int $default = 0): int
{
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return $default;
    }

    return in_array($value, ['1', 'yes', 'y', 'true', 'active'], true) ? 1 : 0;
}

function inventoryImportDate(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d', $timestamp) : null;
}

function inventoryImportCategoryId(string $categoryName): ?int
{
    $categoryName = trim($categoryName);
    if ($categoryName === '') {
        return null;
    }

    $row = inventoryImportFetchOne("SELECT category_id FROM categories WHERE category_name = ? LIMIT 1", [$categoryName]);
    return !empty($row['category_id']) ? (int) $row['category_id'] : null;
}

function inventoryImportExistingDrugId(array $row): int
{
    $drugId = (int) inventoryImportValue($row, 'drug id');
    if ($drugId > 0) {
        $found = inventoryImportFetchOne("SELECT drug_id FROM drugs WHERE drug_id = ? LIMIT 1", [$drugId]);
        if (!empty($found['drug_id'])) {
            return (int) $found['drug_id'];
        }
    }

    $name = inventoryImportValue($row, 'name');
    if ($name !== '') {
        $found = inventoryImportFetchOne("SELECT drug_id FROM drugs WHERE name = ? ORDER BY drug_id LIMIT 1", [$name]);
        if (!empty($found['drug_id'])) {
            return (int) $found['drug_id'];
        }
    }

    return 0;
}

function inventoryImportSaveDrug(array $row): int
{
    $name = inventoryImportValue($row, 'name');
    if ($name === '') {
        throw new RuntimeException(xl('Name is required.'));
    }

    $categoryName = inventoryImportValue($row, 'category');
    $categoryId = inventoryImportCategoryId($categoryName);
    $cost = inventoryImportDecimal(inventoryImportValue($row, 'product cost'));
    $price = inventoryImportDecimal(inventoryImportValue($row, 'product price'));
    $drugId = inventoryImportExistingDrugId($row);

    $fields = [
        'name' => $name,
        'active' => inventoryImportBool(inventoryImportValue($row, 'active'), 1),
        'consumable' => inventoryImportBool(inventoryImportValue($row, 'consumable'), 0),
        'dispensable' => inventoryImportBool(inventoryImportValue($row, 'dispensable'), 1),
        'ndc_number' => inventoryImportValue($row, 'ndc'),
        'form' => inventoryImportValue($row, 'form') ?: '0',
        'size' => inventoryImportValue($row, 'size'),
        'strength' => inventoryImportValue($row, 'strength') ?: '0',
        'unit' => inventoryImportValue($row, 'unit') ?: '0',
        'route' => inventoryImportValue($row, 'route') ?: '0',
        'category_name' => $categoryName,
        'category_id' => $categoryId,
        'cost_per_unit' => $cost,
        'sell_price' => $price,
    ];

    if ($drugId > 0) {
        sqlStatement(
            "UPDATE drugs
                SET name = ?, active = ?, consumable = ?, dispensable = ?, ndc_number = ?,
                    form = ?, size = ?, strength = ?, unit = ?, route = ?, category_name = ?,
                    category_id = ?, cost_per_unit = ?, sell_price = ?
              WHERE drug_id = ?",
            [
                $fields['name'], $fields['active'], $fields['consumable'], $fields['dispensable'],
                $fields['ndc_number'], $fields['form'], $fields['size'], $fields['strength'],
                $fields['unit'], $fields['route'], $fields['category_name'], $fields['category_id'],
                $fields['cost_per_unit'], $fields['sell_price'], $drugId,
            ]
        );
        return $drugId;
    }

    return (int) sqlInsert(
        "INSERT INTO drugs
            (name, active, consumable, dispensable, ndc_number, form, size, strength, unit, route,
             category_name, category_id, cost_per_unit, sell_price)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $fields['name'], $fields['active'], $fields['consumable'], $fields['dispensable'],
            $fields['ndc_number'], $fields['form'], $fields['size'], $fields['strength'],
            $fields['unit'], $fields['route'], $fields['category_name'], $fields['category_id'],
            $fields['cost_per_unit'], $fields['sell_price'],
        ]
    );
}

function inventoryImportExistingInventoryId(array $row, int $drugId): int
{
    $sessionFacilityId = inventoryImportCurrentFacilityId();
    $inventoryId = (int) inventoryImportValue($row, 'inventory id');
    if ($inventoryId > 0) {
        $binds = [$inventoryId];
        $sql = "SELECT inventory_id
                  FROM drug_inventory
                 WHERE inventory_id = ?";

        if ($sessionFacilityId > 0) {
            $sql .= " AND COALESCE(facility_id, 0) = ?";
            $binds[] = $sessionFacilityId;
        }

        $sql .= " LIMIT 1";
        $found = inventoryImportFetchOne($sql, $binds);
        if (!empty($found['inventory_id'])) {
            return (int) $found['inventory_id'];
        }
    }

    $lotNumber = inventoryImportValue($row, 'lot number');
    if ($lotNumber === '') {
        return 0;
    }

    $facilityId = $sessionFacilityId;
    $warehouseId = '';
    $found = inventoryImportFetchOne(
        "SELECT inventory_id
           FROM drug_inventory
          WHERE drug_id = ?
            AND lot_number = ?
            AND COALESCE(facility_id, 0) = ?
            AND COALESCE(warehouse_id, '') = ?
          ORDER BY inventory_id
          LIMIT 1",
        [$drugId, $lotNumber, $facilityId, $warehouseId]
    );

    return !empty($found['inventory_id']) ? (int) $found['inventory_id'] : 0;
}

function inventoryImportEnsurePlaceholderInventory(int $drugId): bool
{
    $facilityId = inventoryImportCurrentFacilityId();
    $existing = inventoryImportFetchOne(
        "SELECT inventory_id
           FROM drug_inventory
          WHERE drug_id = ?
            AND COALESCE(facility_id, 0) = ?
            AND COALESCE(lot_number, '') = ''
            AND COALESCE(warehouse_id, '') = ''
            AND COALESCE(on_hand, 0) = 0
            AND destroy_date IS NULL
          LIMIT 1",
        [$drugId, $facilityId]
    );

    if (!empty($existing['inventory_id'])) {
        return true;
    }

    sqlInsert(
        "INSERT INTO drug_inventory
            (drug_id, lot_number, expiration, manufacturer, on_hand, warehouse_id, facility_id, vendor_id, supplier_id)
         VALUES (?, '', NULL, '', 0, '', ?, 0, 0)",
        [$drugId, $facilityId]
    );

    return true;
}

function inventoryImportSaveLot(array $row, int $drugId): bool
{
    $dispensable = inventoryImportBool(inventoryImportValue($row, 'dispensable'), 1);
    if (!$dispensable || $drugId <= 0) {
        return true;
    }

    inventoryImportEnsurePlaceholderInventory($drugId);
    return true;
}

function inventoryImportReadCsv(string $path): array
{
    $handle = fopen($path, 'r');
    if (!$handle) {
        throw new RuntimeException(xl('Unable to read uploaded file.'));
    }

    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        throw new RuntimeException(xl('The CSV file is empty.'));
    }

    $normalizedHeaders = array_map(static function ($header) {
        return inventoryImportNormalizeHeader((string) $header);
    }, $headers);

    $rows = [];
    while (($data = fgetcsv($handle)) !== false) {
        if (count(array_filter($data, static fn($value) => trim((string) $value) !== '')) === 0) {
            continue;
        }

        $row = [];
        foreach ($normalizedHeaders as $index => $header) {
            $row[$header] = $data[$index] ?? '';
        }
        $rows[] = $row;
    }

    fclose($handle);
    return $rows;
}

$messages = [];
$errors = [];

if (isset($_GET['download_template'])) {
    header("Content-Type: text/csv");
    header("Content-Disposition: attachment; filename=inventory_import_template.csv");
    $output = fopen('php://output', 'w');
    fputcsv($output, inventoryImportColumns());
    fclose($output);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        die(xlt('Invalid CSRF token'));
    }

    try {
        if (empty($_FILES['inventory_file']['tmp_name']) || !is_uploaded_file($_FILES['inventory_file']['tmp_name'])) {
            throw new RuntimeException(xl('Please choose a CSV file to import.'));
        }

        $rows = array_map('inventoryImportSanitizeRow', inventoryImportReadCsv($_FILES['inventory_file']['tmp_name']));
        $productCount = 0;
        $lotCount = 0;

        foreach ($rows as $index => $row) {
            try {
                $drugId = inventoryImportSaveDrug($row);
                $productCount++;
                inventoryImportSaveLot($row, $drugId);
            } catch (Throwable $rowError) {
                $errors[] = xl('Row') . ' ' . ($index + 2) . ': ' . $rowError->getMessage();
            }
        }

        $messages[] = sprintf(xl('Imported %s products. Stocked items were added to the current facility inventory list.'), $productCount);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Import Inventory'); ?></title>
    <?php Header::setupHeader(['common', 'fontawesome']); ?>
    <style>
        .inventory-import-wrap {
            max-width: 860px;
            margin: 28px auto;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
        }
        .inventory-import-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 18px;
        }
        .inventory-import-secondary {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 8px;
        }
        .inventory-import-help {
            color: #5f6b7a;
            line-height: 1.5;
            margin-bottom: 20px;
        }
    </style>
</head>
<body class="body_top">
<div class="inventory-import-wrap">
    <h1><?php echo xlt('Import Inventory'); ?></h1>
    <p class="inventory-import-help">
        <?php echo xlt('Upload a CSV exported from Inventory, or download the blank template and fill it in. Existing rows are updated by Drug ID or product name. Imported stocked items are attached to the current facility so they appear in the inventory list right away.'); ?>
    </p>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-success"><?php echo text($message); ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?php echo text($error); ?></div>
    <?php endforeach; ?>

    <form method="post" enctype="multipart/form-data" onsubmit="return top.restoreSession()">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
        <div class="form-group">
            <label for="inventory_file"><?php echo xlt('Inventory CSV File'); ?></label>
            <input type="file" class="form-control" id="inventory_file" name="inventory_file" accept=".csv,text/csv" required>
        </div>
        <div class="inventory-import-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-file-import"></i> <?php echo xlt('Import Inventory'); ?>
            </button>
            <a class="btn btn-outline-primary" href="export_inventory.php">
                <i class="fas fa-file-export"></i> <?php echo xlt('Export Inventory'); ?>
            </a>
        </div>
        <div class="inventory-import-secondary">
            <a class="btn btn-secondary" href="import_inventory.php?download_template=1">
                <i class="fas fa-download"></i> <?php echo xlt('Download Template'); ?>
            </a>
            <a class="btn btn-link" href="drug_inventory.php"><?php echo xlt('Back to Inventory'); ?></a>
        </div>
    </form>
</div>
</body>
</html>
