<?php

/**
 * Inventory CSV export.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("drugs.inc.php");

use OpenEMR\Common\Acl\AclMain;

$authAdmin = AclMain::aclCheckCore('admin', 'drugs');
$authInventory = $authAdmin ||
    AclMain::aclCheckCore('inventory', 'lots') ||
    AclMain::aclCheckCore('inventory', 'purchases') ||
    AclMain::aclCheckCore('inventory', 'transfers') ||
    AclMain::aclCheckCore('inventory', 'adjustments') ||
    AclMain::aclCheckCore('inventory', 'consumption') ||
    AclMain::aclCheckCore('inventory', 'destruction') ||
    AclMain::aclCheckCore('inventory', 'sales') ||
    AclMain::aclCheckCore('inventory', 'reporting');

if (!$authInventory) {
    die(xlt('Not authorized'));
}

function exportInventoryCurrentFacilityId(): int
{
    if (!empty($_SESSION['facilityId'])) {
        return (int) $_SESSION['facilityId'];
    }

    if (!empty($_SESSION['facility_id'])) {
        return (int) $_SESSION['facility_id'];
    }

    return 0;
}

$selectedFacilityId = exportInventoryCurrentFacilityId();
$formFacility = empty($_GET['form_facility']) ? 0 : (int) $_GET['form_facility'];
if ($selectedFacilityId > 0) {
    $formFacility = $selectedFacilityId;
}
$formCategory = trim((string) ($_GET['form_category'] ?? ''));
$formWarehouse = trim((string) ($_GET['form_warehouse'] ?? ''));
$formConsumable = (int) ($_GET['form_consumable'] ?? 0);
$formShowEmpty = !empty($_GET['form_show_empty']);
$formShowInactive = !empty($_GET['form_show_inactive']);

$where = ["di.destroy_date IS NULL"];
$binds = [];

if ($formFacility > 0) {
    $where[] = "COALESCE(lo.option_value, di.facility_id) = ?";
    $binds[] = $formFacility;
} elseif (!empty($GLOBALS['restrict_user_facility'])) {
    $userFacilities = [];
    $facilityResult = sqlStatement(
        "SELECT DISTINCT facility_id FROM users_facility WHERE tablename = 'users' AND table_id = ?",
        [$_SESSION['authUserID'] ?? 0]
    );
    while ($facilityRow = sqlFetchArray($facilityResult)) {
        if (!empty($facilityRow['facility_id'])) {
            $userFacilities[] = (int) $facilityRow['facility_id'];
        }
    }

    if (!empty($userFacilities)) {
        $where[] = "COALESCE(lo.option_value, di.facility_id) IN (" . implode(',', array_fill(0, count($userFacilities), '?')) . ")";
        $binds = array_merge($binds, $userFacilities);
    }
}

if ($formWarehouse !== '') {
    $where[] = "di.warehouse_id = ?";
    $binds[] = $formWarehouse;
}

if ($formCategory !== '') {
    $where[] = "c.category_name = ?";
    $binds[] = $formCategory;
}

if (!$formShowInactive) {
    $where[] = "d.active = 1";
}

if ($formConsumable === 1) {
    $where[] = "d.consumable = 1";
} elseif ($formConsumable === 2) {
    $where[] = "d.consumable != 1";
}

if (!$formShowEmpty) {
    $where[] = "(di.inventory_id IS NULL OR di.on_hand > 0)";
}

$sql = "SELECT d.drug_id, d.name, d.active, d.consumable, d.dispensable, d.ndc_number,
               d.form, d.size, d.strength, d.unit, d.route, d.cost_per_unit AS product_cost,
               d.sell_price AS product_price, c.category_name, p.subcategory_name,
               di.inventory_id, di.lot_number, di.expiration, di.manufacturer, di.on_hand,
               di.cost_per_unit AS lot_cost, di.sell_price AS lot_price, di.facility_id,
               f.name AS facility_name, di.warehouse_id, lo.title AS warehouse_name
          FROM drugs AS d
     LEFT JOIN categories AS c ON c.category_id = d.category_id
     LEFT JOIN products AS p ON p.product_id = d.product_id
     LEFT JOIN drug_inventory AS di ON di.drug_id = d.drug_id
     LEFT JOIN list_options AS lo ON lo.list_id = 'warehouse'
                                  AND lo.option_id = di.warehouse_id
                                  AND lo.activity = 1
     LEFT JOIN facility AS f ON f.id = COALESCE(lo.option_value, di.facility_id)
         WHERE " . implode(' AND ', $where) . "
      ORDER BY d.name, di.expiration, di.lot_number, di.inventory_id";

header("Pragma: public");
header("Expires: 0");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
header("Content-Type: text/csv");
header("Content-Disposition: attachment; filename=inventory_export.csv");
header("Content-Description: File Transfer");

$output = fopen('php://output', 'w');
fputcsv($output, [
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
]);

$result = sqlStatement($sql, $binds);
while ($row = sqlFetchArray($result)) {
    fputcsv($output, [
        $row['drug_id'] ?? '',
        $row['inventory_id'] ?? '',
        $row['name'] ?? '',
        !empty($row['active']) ? '1' : '0',
        !empty($row['consumable']) ? '1' : '0',
        !empty($row['dispensable']) ? '1' : '0',
        $row['ndc_number'] ?? '',
        $row['form'] ?? '',
        $row['size'] ?? '',
        $row['strength'] ?? '',
        $row['unit'] ?? '',
        $row['route'] ?? '',
        $row['category_name'] ?? '',
        $row['subcategory_name'] ?? '',
        $row['product_cost'] ?? '',
        $row['product_price'] ?? '',
        $row['lot_number'] ?? '',
        $row['on_hand'] ?? '',
        $row['expiration'] ?? '',
        $row['manufacturer'] ?? '',
        $row['lot_cost'] ?? '',
        $row['lot_price'] ?? '',
        $row['facility_id'] ?? '',
        $row['facility_name'] ?? '',
        $row['warehouse_id'] ?? '',
        $row['warehouse_name'] ?? '',
    ]);
}

fclose($output);
exit;
