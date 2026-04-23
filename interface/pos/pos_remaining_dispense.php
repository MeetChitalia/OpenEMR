<?php
/**
 * POS Remaining Dispense AJAX Handler
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Team
 * @copyright Copyright (c) 2024 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

use OpenEMR\Common\Acl\AclMain;

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

function getRemainingDispenseDoseOption($itemsJson, $drugId, $lotNumber)
{
    $items = json_decode((string) $itemsJson, true);
    if (!is_array($items)) {
        return '';
    }

    $drugId = (string) $drugId;
    $lotNumber = trim((string) $lotNumber);
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $itemDrugId = (string) ($item['drug_id'] ?? '');
        $itemLotNumber = trim((string) ($item['lot_number'] ?? ($item['lot'] ?? '')));
        if ($itemDrugId === $drugId && $itemLotNumber === $lotNumber) {
            return trim((string) ($item['dose_option_mg'] ?? ($item['semaglutide_dose_mg'] ?? '')));
        }
    }

    return '';
}

// Ensure user is logged in
if (!isset($_SESSION['authUserID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => xlt('Not authorized')]);
    exit;
}

// Get patient ID from GET request
$pid = intval($_GET['pid'] ?? 0);

if (!$pid) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => xlt('Invalid patient ID')]);
    exit;
}

try {
    // Check if pos_remaining_dispense table exists
    $table_exists = sqlQuery("SHOW TABLES LIKE 'pos_remaining_dispense'");
    if (!$table_exists) {
        echo json_encode(['success' => true, 'remaining_items' => []]);
        exit;
    }
    
    // Query to get remaining dispense quantities for the patient (only items with remaining_quantity > 0)
    // Filter out completed items (remaining_quantity <= 0) and negative values
    $query = "SELECT 
                prd.id, prd.drug_id, prd.lot_number, prd.total_quantity, prd.dispensed_quantity, 
                prd.administered_quantity, prd.remaining_quantity, prd.receipt_number, prd.created_date, prd.last_updated,
                d.name as drug_name, d.form, d.strength, d.size, d.unit, d.route,
                pt_items.items AS transaction_items
              FROM pos_remaining_dispense prd
              INNER JOIN drugs d ON prd.drug_id = d.drug_id
              LEFT JOIN (
                  SELECT pt1.pid, pt1.receipt_number, pt1.items
                  FROM pos_transactions pt1
                  INNER JOIN (
                      SELECT pid, receipt_number, MAX(id) AS max_id
                      FROM pos_transactions
                      WHERE items IS NOT NULL AND items != ''
                      GROUP BY pid, receipt_number
                  ) latest_pt ON latest_pt.max_id = pt1.id
              ) pt_items ON pt_items.pid = prd.pid AND pt_items.receipt_number = prd.receipt_number
              WHERE prd.pid = ? AND prd.remaining_quantity > 0
              ORDER BY prd.last_updated DESC";
    
    $result = sqlStatement($query, array($pid));
    $remaining_items = array();
    
    while ($row = sqlFetchArray($result)) {
        $is_ml_form = isLiquidInventoryDrugData($row);
        $remaining_items[] = array(
            'id' => $row['id'],
            'drug_id' => $row['drug_id'],
            'drug_name' => $row['drug_name'] . ' ' . $row['form'] . ' ' . $row['strength'] . ' ' . $row['size'] . ' ' . $row['unit'],
            'form' => $row['form'],
            'is_ml_form' => $is_ml_form,
            'quantity_unit' => $is_ml_form ? 'mg' : 'units',
            'lot_number' => $row['lot_number'],
            'total_quantity' => round((float) ($row['total_quantity'] ?? 0), 4),
            'dispensed_quantity' => round((float) ($row['dispensed_quantity'] ?? 0), 4),
            'administered_quantity' => round((float) ($row['administered_quantity'] ?? 0), 4),
            'remaining_quantity' => round((float) ($row['remaining_quantity'] ?? 0), 4),
            'dose_option_mg' => getRemainingDispenseDoseOption($row['transaction_items'] ?? '', $row['drug_id'], $row['lot_number']),
            'receipt_number' => $row['receipt_number'],
            'created_date' => $row['created_date'],
            'last_updated' => $row['last_updated']
        );
    }
    
    echo json_encode([
        'success' => true,
        'remaining_items' => $remaining_items,
        'count' => count($remaining_items)
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching remaining dispense: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => xlt('Database error occurred')]);
}
?> 
