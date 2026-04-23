<?php
/**
 * Update Current Quantity On Hand
 *
 * Allows direct manual stock corrections for Office and Products items.
 * Medication items use a separate cycle-count workflow and do not overwrite QOH.
 */

require_once("../globals.php");
require_once("$srcdir/options.inc.php");
require_once(__DIR__ . "/medical_inventory_count_common.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

header('Content-Type: application/json');

function cqohFetchRow($result)
{
    if (is_object($result) && method_exists($result, 'FetchRow')) {
        return $result->FetchRow();
    }

    return is_array($result) ? $result : null;
}

$can_edit_cqoh = AclMain::aclCheckCore('admin', 'drugs') ||
    AclMain::aclCheckCore('inventory', 'lots') ||
    AclMain::aclCheckCore('inventory', 'purchases') ||
    AclMain::aclCheckCore('inventory', 'transfers') ||
    AclMain::aclCheckCore('inventory', 'adjustments') ||
    AclMain::aclCheckCore('inventory', 'consumption') ||
    AclMain::aclCheckCore('inventory', 'destruction');

if (!$can_edit_cqoh) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

$drug_id = (int) ($_POST['drug_id'] ?? 0);
$cqoh = isset($_POST['cqoh']) ? (float) $_POST['cqoh'] : null;
$selectedFacilityId = (int) ($_SESSION['facilityId'] ?? 0);

if ($drug_id <= 0 || $cqoh === null || $cqoh < 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid quantity request']);
    exit;
}

$drug = sqlQuery(
    "SELECT d.drug_id, d.name, c.category_name
       FROM drugs AS d
  LEFT JOIN categories AS c ON c.category_id = d.category_id
      WHERE d.drug_id = ?",
    [$drug_id]
);
$drug = cqohFetchRow($drug);

if (!$drug) {
    echo json_encode(['success' => false, 'message' => 'Inventory item not found']);
    exit;
}

$category_name = strtolower(trim((string) ($drug['category_name'] ?? '')));
$is_medication_inventory_category = isMedicationInventoryCategoryName((string) ($drug['category_name'] ?? ''));
if (!in_array($category_name, ['office', 'products'], true) && !$is_medication_inventory_category) {
    echo json_encode(['success' => false, 'message' => 'CQOH can only be edited for Office, Products, and Medication categories']);
    exit;
}

$scope_where = "di.drug_id = ? AND di.destroy_date IS NULL";
$scope_binds = [$drug_id];

if ($selectedFacilityId > 0) {
    $scope_where .= " AND (di.facility_id = ? OR EXISTS (
        SELECT 1
          FROM list_options AS lo
         WHERE lo.list_id = 'warehouse'
           AND lo.activity = 1
           AND lo.option_id = di.warehouse_id
           AND lo.option_value = ?
    ))";
    $scope_binds[] = $selectedFacilityId;
    $scope_binds[] = $selectedFacilityId;
}

$updated_total = sqlQuery(
    "SELECT COALESCE(SUM(di.on_hand), 0) AS total_qoh
       FROM drug_inventory AS di
      WHERE $scope_where",
    $scope_binds
);
$updated_total = cqohFetchRow($updated_total);
$expectedQoh = isset($updated_total['total_qoh']) ? (float) $updated_total['total_qoh'] : 0;

if ($is_medication_inventory_category) {
    $countRecord = upsertPendingMedicalInventoryCount(
        $selectedFacilityId,
        $drug_id,
        $expectedQoh,
        $cqoh,
        (int) ($_SESSION['authUserID'] ?? 0)
    );

    $variance = (float) ($countRecord['variance_qoh'] ?? 0);
    $status = (string) ($countRecord['status'] ?? 'matched');
    $message = $status === 'matched'
        ? 'Medication inventory count recorded. Count matches QOH.'
        : 'Medication inventory count recorded. Difference: ' . $variance;

    echo json_encode([
        'success' => true,
        'message' => $message,
        'cqoh' => (float) ($countRecord['counted_qoh'] ?? $cqoh),
        'expected_qoh' => $expectedQoh,
        'difference' => $variance,
        'status' => $status,
        'is_medical' => true
    ]);
    exit;
}

$inventory_rows = [];
$res = sqlStatement(
    "SELECT di.inventory_id, di.on_hand
       FROM drug_inventory AS di
      WHERE $scope_where
   ORDER BY di.inventory_id",
    $scope_binds
);

while ($row = sqlFetchArray($res)) {
    $inventory_rows[] = $row;
}

if (!empty($inventory_rows)) {
    $primary_inventory_id = (int) $inventory_rows[0]['inventory_id'];
    sqlStatement(
        "UPDATE drug_inventory
            SET on_hand = ?
          WHERE inventory_id = ?",
        [$cqoh, $primary_inventory_id]
    );

    if (count($inventory_rows) > 1) {
        for ($i = 1; $i < count($inventory_rows); $i++) {
            sqlStatement(
                "UPDATE drug_inventory
                    SET on_hand = 0
                  WHERE inventory_id = ?",
                [(int) $inventory_rows[$i]['inventory_id']]
            );
        }
    }
} elseif ($cqoh > 0) {
    sqlStatement(
        "INSERT INTO drug_inventory
            (drug_id, lot_number, expiration, manufacturer, on_hand, warehouse_id, facility_id, vendor_id, supplier_id)
         VALUES (?, 'N/A', NULL, '', ?, '', ?, 0, NULL)",
        [$drug_id, $cqoh, $selectedFacilityId ?: null]
    );
}

echo json_encode([
    'success' => true,
    'message' => 'Current quantity updated successfully',
    'cqoh' => $expectedQoh,
    'expected_qoh' => $expectedQoh,
    'difference' => 0,
    'status' => 'matched',
    'is_medical' => false
]);
?>
