<?php
/**
 * add and edit lot
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2006-2021 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2017 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("drugs.inc.php");
require_once("$srcdir/options.inc.php");
require_once(__DIR__ . "/../../library/translation.inc.php");
require_once(__DIR__ . "/../../library/InventoryAuditLogger.class.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;

// Check authorizations.
$auth_admin = AclMain::aclCheckCore('admin', 'drugs');
$auth_lots  = $auth_admin               ||
    AclMain::aclCheckCore('inventory', 'lots') ||
    AclMain::aclCheckCore('inventory', 'purchases') ||
    AclMain::aclCheckCore('inventory', 'transfers') ||
    AclMain::aclCheckCore('inventory', 'adjustments') ||
    AclMain::aclCheckCore('inventory', 'consumption') ||
    AclMain::aclCheckCore('inventory', 'destruction');
if (!$auth_lots) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Edit/Add Lot")]);
    exit;
}

function checkWarehouseUsed($warehouse_id)
{
    global $drug_id;
    $drug_id_escaped = intval($drug_id);
    $warehouse_id_escaped = add_escape_custom($warehouse_id);
    $row = sqlQuery("SELECT count(*) AS count FROM drug_inventory WHERE " .
    "drug_id = $drug_id_escaped AND on_hand != 0 AND " .
    "destroy_date IS NULL AND warehouse_id = '$warehouse_id_escaped'");
    return $row['count'];
}

function areVendorsUsed()
{
    $row = sqlQueryNoLog(
        "SELECT COUNT(*) AS count FROM users " .
        "WHERE active = 1 AND (info IS NULL OR info NOT LIKE '%Inactive%') " .
        "AND abook_type LIKE 'vendor%'"
    );
    if ($row && isset($row['count'])) {
    return $row['count'];
    }
    return 0;
}

function resolveInventoryFacilityId($warehouseId)
{
    $warehouseId = trim((string) $warehouseId);
    if ($warehouseId === '') {
        return getCurrentInventorySessionFacilityId();
    }

    $row = sqlQueryNoLog(
        "SELECT option_value FROM list_options WHERE list_id = 'warehouse' AND option_id = ? AND activity = 1",
        array($warehouseId)
    );

    if (is_object($row) && method_exists($row, 'FetchRow')) {
        $row = $row->FetchRow();
    }

    $facilityId = is_array($row) ? (int) ($row['option_value'] ?? 0) : 0;
    if ($facilityId > 0) {
        return $facilityId;
    }

    return getCurrentInventorySessionFacilityId();
}

function getCurrentInventorySessionFacilityId()
{
    if (!empty($_SESSION['facilityId'])) {
        return (int) $_SESSION['facilityId'];
    }

    if (!empty($_SESSION['facility_id'])) {
        return (int) $_SESSION['facility_id'];
    }

    return 0;
}

// Generate a <select> list of warehouses.
// If multiple lots are not allowed for this product, then restrict the
// list to warehouses that are unused for the product.
// Returns the number of warehouses allowed.
// For these purposes the "unassigned" option is considered a warehouse.
//
function genWarehouseList($tag_name, $currvalue, $title, $class = '')
{
    global $is_user_restricted;
    $res = sqlStatement("SELECT option_id, title FROM list_options " .
    "WHERE list_id = 'warehouse' AND activity = 1 ORDER BY seq, title");
    echo "   <select name='$tag_name' class='$class'>\n";
    echo "    <option value=''>" . xlt('Select') . " $title</option>\n";
    while ($row = sqlFetchArray($res)) {
        $tmp = explode('/', $row['option_id']);
        $dis = $is_user_restricted && (count($tmp) < 2 || $tmp[1] != $_SESSION['facility_id']);
        echo "    <option value='" . attr($row['option_id']) . "'";
        if ($row['option_id'] == $currvalue) {
            echo " selected";
        }
        if ($dis) {
            echo " disabled";
        }
        echo ">" . text($row['title']) . "</option>\n";
    }
    echo "   </select>\n";
    }

/**
 * Convert transaction type to movement type for audit logging
 */
function getMovementTypeFromTransType($trans_type)
{
    $movement_types = array(
        0 => 'edit_only',
        1 => 'sale',
        2 => 'purchase',
        3 => 'return',
        4 => 'transfer',
        5 => 'adjustment',
        6 => 'distribution',
        7 => 'consumption'
    );
    
    return $movement_types[$trans_type] ?? 'unknown';
}

$drug_id = $_REQUEST['drug'] + 0;
$lot_id  = $_REQUEST['lot'] + 0;
$info_msg = "";
$selectedFacilityId = getCurrentInventorySessionFacilityId();

$form_trans_type = intval(isset($_POST['form_trans_type']) ? $_POST['form_trans_type'] : '0');
$form_sale_date_value = $_POST['form_sale_date'] ?? date('Y-m-d');

// Note if user is restricted to any facilities and/or warehouses.
$is_user_restricted = isUserRestricted();

if (!$drug_id) {
    die(xlt('Drug ID missing!'));
}

// Check if the drug exists and get category information
$drug_id_escaped = intval($drug_id);
$drug_check = sqlQuery("SELECT d.drug_id, d.name, d.category_id, d.category, d.form, d.size, d.unit, d.route, c.category_name 
                        FROM drugs d 
                        LEFT JOIN categories c ON c.category_id = d.category_id 
                        WHERE d.drug_id = $drug_id_escaped");

// Handle ADORecordSet result
$drug_data = null;
if (is_object($drug_check) && method_exists($drug_check, 'FetchRow')) {
    $drug_data = $drug_check->FetchRow();
} elseif (is_array($drug_check)) {
    $drug_data = $drug_check;
}

if (!$drug_data) {
    die(xlt('Drug not found!'));
}

function isMedicationCategoryLabel($categoryName)
{
    $normalized = strtolower(trim((string) $categoryName));
    return in_array($normalized, ['medical', 'medication'], true)
        || stripos((string) $categoryName, 'medical') !== false
        || stripos((string) $categoryName, 'medication') !== false;
}

// Check if this is a medication category
$is_medical_category = false;
$category_to_check = $drug_data['category_name'] ?? $drug_data['category'] ?? '';

if ($category_to_check) {
    $is_medical_category = isMedicationCategoryLabel($category_to_check);
}

$liquid_inventory = getLiquidInventoryDefinition($drug_data);
$is_liquid_inventory = !empty($liquid_inventory['is_liquid']);

// Initialize $row with empty values for new lots
$row = array(
    'lot_number' => '',
    'manufacturer' => '',
    'expiration' => '',
    'vendor_id' => '',
    'supplier_id' => '',
    'warehouse_id' => '',
    'on_hand' => 0
);

if ($lot_id) {
    $lot_id_escaped = intval($lot_id);
    $row = sqlQueryNoLog("SELECT * FROM drug_inventory WHERE drug_id = $drug_id_escaped AND inventory_id = $lot_id_escaped");
}

// Handle form submission
if (!empty($_POST['form_save'])) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }

    if ($is_liquid_inventory) {
        $posted_ml_per_vial = ($_POST['form_lot_ml_per_vial'] ?? '') + 0;
        $posted_mg_per_ml = ($_POST['form_lot_mg_per_ml'] ?? '') + 0;
        if ($posted_ml_per_vial > 0 && $posted_mg_per_ml > 0) {
            $drug_data['size'] = formatDrugInventoryNumber($posted_ml_per_vial) . ' mL per vial';
            $drug_data['unit'] = formatDrugInventoryNumber($posted_mg_per_ml) . ' mg / mL';
            $liquid_inventory = getLiquidInventoryDefinition($drug_data);
        }
    }

    $entered_quantity = ($_POST['form_quantity'] ?? 0) + 0;
    $form_quantity = $entered_quantity;
    $quantity_in_vials = $is_liquid_inventory && in_array($form_trans_type, array(2, 3, 4), true);
    if ($quantity_in_vials) {
        $form_quantity = convertLiquidVialsToMg($drug_data, $entered_quantity);
    }
    // Note: Cost and Sell Price are now managed at the product level

    list($form_source_lot, $form_source_facility) = explode('|', $_POST['form_source_lot'] ?? '0|0');
    $form_source_lot = intval($form_source_lot);

    list($form_warehouse_id) = explode('|', $_POST['form_warehouse_id'] ?? '');
    $form_facility_id = resolveInventoryFacilityId($form_warehouse_id);

    $form_expiration   = $_POST['form_expiration'] ?? '';
    // Convert MM/DD/YYYY to YYYY-MM-DD for storage
    if (!empty($form_expiration) && preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $form_expiration, $matches)) {
        $form_expiration = $matches[3] . '-' . $matches[1] . '-' . $matches[2];
    }
    $form_lot_number   = $_POST['form_lot_number'] ?? '';
    $form_manufacturer = $_POST['form_manufacturer'] ?? '';
    $form_vendor_id    = $_POST['form_vendor_id'] ?? '';
    $form_supplier_id  = $_POST['form_supplier_id'] ?? '';
    $form_sale_date    = trim($_POST['form_sale_date'] ?? '');

    // Validate Lot Number (required field only for medication categories)
    if ($is_medical_category && empty(trim($form_lot_number))) {
        $info_msg = xl('Lot Number is required for medication products');
    }

    if (!empty($form_sale_date)) {
        $sale_date = DateTime::createFromFormat('Y-m-d', $form_sale_date) ?:
            DateTime::createFromFormat('m-d-Y', $form_sale_date) ?:
            DateTime::createFromFormat('m/d/Y', $form_sale_date) ?:
            DateTime::createFromFormat('Y/m/d', $form_sale_date);
        if ($sale_date) {
            $form_sale_date = $sale_date->format('Y-m-d');
        } else {
            $info_msg = xl('Received Date is invalid');
        }
    } else {
        $form_sale_date = date('Y-m-d');
    }
    $form_sale_date_value = $form_sale_date;

    // Repopulate supplier dropdown with user's selection after POST
    $row['supplier_id'] = $form_supplier_id;

    if ($form_trans_type < 0 || $form_trans_type > 7) {
        die(xlt('Internal error!'));
    }

    if (
        !$auth_admin && (
            $form_trans_type == 2 && !AclMain::aclCheckCore('inventory', 'purchases') ||
            $form_trans_type == 3 && !AclMain::aclCheckCore('inventory', 'purchases') ||
            $form_trans_type == 4 && !AclMain::aclCheckCore('inventory', 'transfers') ||
            $form_trans_type == 5 && !AclMain::aclCheckCore('inventory', 'adjustments') ||
            $form_trans_type == 7 && !AclMain::aclCheckCore('inventory', 'consumption')
            )
    ) {
        die(xlt('Not authorized'));
    }

      // Some fixups depending on transaction type.
    if ($form_trans_type == 3) { // return
        $form_quantity = 0 - $form_quantity;
        $form_cost = 0 - $form_cost;
    } elseif ($form_trans_type == 5) { // adjustment
        $form_cost = 0;
    } elseif ($form_trans_type == 7) { // consumption
        $form_quantity = 0 - $form_quantity;
        $form_cost = 0;
    } elseif ($form_trans_type == 0) { // no transaction
        $form_quantity = 0;
        $form_cost = 0;
    }
    if ($form_trans_type != 4) { // not transfer
        $form_source_lot = 0;
    }

    // If a transfer, make sure there is sufficient quantity in the source lot
    // and apply some default values from it.
    if ($form_source_lot) {
        $form_source_lot_escaped = intval($form_source_lot);
        $srow = sqlQueryNoLog(
            "SELECT lot_number, expiration, manufacturer, vendor_id, on_hand, supplier_id " .
            "FROM drug_inventory WHERE drug_id = $drug_id_escaped AND inventory_id = $form_source_lot_escaped"
        );
        if (empty($form_lot_number)) {
            $form_lot_number = $srow['lot_number'];
        }
        if (empty($form_expiration)) {
             $form_expiration = $srow['expiration'];
        }
        if (empty($form_manufacturer)) {
             $form_manufacturer = $srow['manufacturer'];
        }
        if (empty($form_vendor_id)) {
             $form_vendor_id = $srow['vendor_id'];
        }
        if (empty($form_supplier_id)) {
             $form_supplier_id = $srow['supplier_id'];
        }
        if ($form_quantity && $srow['on_hand'] < $form_quantity) {
            $info_msg = xl('Transfer failed, insufficient quantity in source lot');
        }
    }

    // Restore expiration date logic to store as YYYY-MM-DD
    if (!empty($form_expiration)) {
        // Try to parse with slashes or dashes and convert to YYYY-MM-DD
        $date = DateTime::createFromFormat('Y-m-d', $form_expiration) ?:
                DateTime::createFromFormat('m-d-Y', $form_expiration) ?:
                DateTime::createFromFormat('m/d/Y', $form_expiration) ?:
                DateTime::createFromFormat('Y/m/d', $form_expiration);
        if ($date) {
            $form_expiration = $date->format('Y-m-d');
        } else {
            // If parsing fails, set to NULL
            $form_expiration = '';
        }
    }

    // For WHERE clause in duplicate lot check
    if (!empty($form_expiration)) {
        $expiration_where_sql = "expiration = '$form_expiration'";
        $expiration_set_sql = "expiration = '$form_expiration'";
    } else {
        $expiration_where_sql = "expiration IS NULL";
        $expiration_set_sql = "expiration = NULL";
    }

    if (!$info_msg) {
        if ($is_liquid_inventory && !empty($liquid_inventory['ml_per_vial']) && !empty($liquid_inventory['mg_per_ml'])) {
            sqlStatement(
                "UPDATE drugs SET size = ?, unit = ? WHERE drug_id = ?",
                array(
                    formatDrugInventoryNumber($liquid_inventory['ml_per_vial']) . ' mL per vial',
                    formatDrugInventoryNumber($liquid_inventory['mg_per_ml']) . ' mg / mL',
                    $drug_id
                )
            );
        }

        // If purchase or transfer with no destination lot specified, see if one already exists.
        if (!$lot_id && $form_lot_number && ($form_trans_type == 2 || $form_trans_type == 4)) {
            $form_warehouse_id_escaped = add_escape_custom($form_warehouse_id);
            $form_lot_number_escaped = add_escape_custom($form_lot_number);
            $erow = sqlQueryNoLog(
                "SELECT * FROM drug_inventory WHERE " .
                "drug_id = $drug_id_escaped AND warehouse_id = '$form_warehouse_id_escaped' " .
                "AND facility_id = " . (int) $form_facility_id . " " .
                "AND lot_number = '$form_lot_number_escaped' AND destroy_date IS NULL AND on_hand != 0 " .
                "ORDER BY inventory_id DESC LIMIT 1"
            );
            if (!empty($erow['inventory_id'])) {
                // Yes a matching lot exists, use it and its values.
                $lot_id = $erow['inventory_id'];
                if (empty($form_expiration)) {
                    $form_expiration   = $erow['expiration'];
                }
                if (empty($form_manufacturer)) {
                    $form_manufacturer = $erow['manufacturer'];
                }
                if (empty($form_vendor_id)) {
                    $form_vendor_id    = $erow['vendor_id'];
                }
                if (empty($form_supplier_id)) {
                    $form_supplier_id  = $erow['supplier_id'];
                }
            }
        }

        // Destination lot already exists.
        if ($lot_id) {
            if ($_POST['form_save']) {
                // Make sure the destination quantity will not end up negative.
                if (($row['on_hand'] + $form_quantity) < 0) {
                    $info_msg = xl('Transaction failed, insufficient quantity in destination lot');
                } else {
                    $form_lot_number_escaped = add_escape_custom($form_lot_number);
                    $form_manufacturer_escaped = add_escape_custom($form_manufacturer);
                    $form_vendor_id_escaped = add_escape_custom($form_vendor_id);
                    $form_supplier_id_escaped = add_escape_custom($form_supplier_id);
                    $form_warehouse_id_escaped = add_escape_custom($form_warehouse_id);
                    sqlStatement(
                        "UPDATE drug_inventory SET " .
                        "lot_number = '$form_lot_number_escaped', " .
                        "manufacturer = '$form_manufacturer_escaped', " .
                        "$expiration_set_sql, "  .
                        "vendor_id = '$form_vendor_id_escaped', " .
                        "supplier_id = '$form_supplier_id_escaped', " .
                        "warehouse_id = '$form_warehouse_id_escaped', " .
                        "facility_id = " . (int) $form_facility_id . ", " .
                        "on_hand = on_hand + $form_quantity " .
                        "WHERE drug_id = $drug_id_escaped AND inventory_id = $lot_id"
                    );


                }
            }
        } else {
            // Create new lot
            if ($form_quantity < 0) {
                $info_msg = xl('Transaction failed, quantity is less than zero');
            } else {
                $form_lot_number_escaped = add_escape_custom($form_lot_number);
                $form_manufacturer_escaped = add_escape_custom($form_manufacturer);
                $form_vendor_id_escaped = add_escape_custom($form_vendor_id);
                $form_supplier_id_escaped = add_escape_custom($form_supplier_id);
                $form_warehouse_id_escaped = add_escape_custom($form_warehouse_id);
                // Check for duplicate lot
                $crow = sqlQueryNoLog(
                    "SELECT count(*) AS count from drug_inventory " .
                    "WHERE lot_number = '$form_lot_number_escaped' " .
                    "AND drug_id = $drug_id_escaped " .
                    "AND warehouse_id = '$form_warehouse_id_escaped' " .
                    "AND facility_id = " . (int) $form_facility_id . " " .
                    "AND $expiration_where_sql " .
                    "AND on_hand != 0 " .
                    "AND destroy_date IS NULL"
                );
                if ($crow['count']) {
                    $info_msg = xl('Transaction failed, duplicate lot');
                } else {
                    $lot_id = sqlInsert(
                        "INSERT INTO drug_inventory ( " .
                        "drug_id, lot_number, manufacturer, expiration, " .
                        "vendor_id, supplier_id, warehouse_id, facility_id, on_hand " .
                        ") VALUES ( " .
                        "$drug_id_escaped, " .
                        "'$form_lot_number_escaped', " .
                        "'$form_manufacturer_escaped', " .
                        (empty($form_expiration) ? "NULL" : "'$form_expiration'") . ", " .
                        "'$form_vendor_id_escaped', " .
                        "'$form_supplier_id_escaped', " .
                        "'$form_warehouse_id_escaped', " .
                        (int) $form_facility_id . ", " .
                        "$form_quantity " .
                        ")"
                    );


                }
            }
        }

        // Create the corresponding drug_sales transaction.
        if ($_POST['form_save'] && $form_quantity && !$info_msg) {
            $form_notes = $_POST['form_notes'];

            $form_notes_escaped = add_escape_custom($form_notes);
            $form_sale_date_escaped = add_escape_custom($form_sale_date);
            $user_escaped = add_escape_custom($_SESSION['authUser']);

            // Temporarily disable the audit trigger to prevent duplicate entries
            sqlStatement("SET @DISABLE_AUDIT_TRIGGER = 1");

            $sale_id = sqlInsert(
                "INSERT INTO drug_sales ( " .
                "drug_id, inventory_id, prescription_id, pid, encounter, user, sale_date, " .
                "quantity, fee, xfer_inventory_id, distributor_id, notes, trans_type " .
                ") VALUES ( " .
                "$drug_id_escaped, " .
                "$lot_id, " .
                "'0', '0', '0', " .
                "'$user_escaped', " .
                "'$form_sale_date_escaped', " .
                (0 - $form_quantity) . ", " .
                "0, " . // Fee is now managed at product level
                "$form_source_lot, " .
                "0, " .
                "'$form_notes_escaped', " .
                "$form_trans_type " .
                ")"
            );

            // Re-enable the audit trigger
            sqlStatement("SET @DISABLE_AUDIT_TRIGGER = NULL");

            // Log the transaction in the audit system with comprehensive data
            if ($sale_id) {
                try {
                    $audit_logger = new InventoryAuditLogger();
                    
                    // Create a single comprehensive audit entry with exact form data
                    $audit_data = array(
                        'drug_id' => $drug_id,
                        'inventory_id' => $lot_id,
                        'movement_type' => getMovementTypeFromTransType($form_trans_type),
                        'quantity_change' => $form_quantity,
                        'reference_id' => $sale_id,
                        'reference_type' => 'drug_sales',
                        'lot_number' => $form_lot_number,
                        'expiration_date' => $form_expiration,
                        'manufacturer' => $form_manufacturer,
                        'vendor_id' => $form_vendor_id,
                        'supplier_id' => $form_supplier_id,
                        'warehouse_id' => $form_warehouse_id,
                        'cost' => 0, // Cost is now managed at product level
                        'sell_price' => 0, // Sell price is now managed at product level
                        'notes' => $_POST['form_notes'] ?? '',
                        'source_lot' => $form_source_lot,
                        'transaction_type' => $form_trans_type,
                        'action' => $lot_id ? 'lot_updated' : 'lot_created'
                    );
                    
                    $audit_logger->logComprehensiveActivity($audit_data);
                } catch (Exception $e) {
                    // Log error but don't break the transaction
                    error_log("Audit logging failed: " . $e->getMessage());
                }
            }

            // If this is a transfer then reduce source QOH.
            if ($form_source_lot) {
                sqlStatement(
                    "UPDATE drug_inventory SET " .
                    "on_hand = on_hand - $form_quantity " .
                    "WHERE inventory_id = $form_source_lot"
                );


            }
        }

        // After successful add/update, refresh parent and close popup or show a professional modal success dialog
        echo "<link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'/>
";
        echo "<script src='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'></script>
";
        echo "<script>\n";
        echo "function refreshInventoryParent() {\n";
        echo "  try {\n";
        echo "    if (typeof top.restoreSession === 'function') {\n";
        echo "      top.restoreSession();\n";
        echo "    }\n";
        echo "    if (window.localStorage) {\n";
        echo "      window.localStorage.setItem('openemr_drug_inventory_refresh', Date.now().toString());\n";
        echo "    }\n";
        echo "    if (typeof parent.refreshme === 'function') {\n";
        echo "      parent.refreshme();\n";
        echo "    } else if (window.opener && typeof window.opener.refreshme === 'function') {\n";
        echo "      window.opener.refreshme();\n";
        echo "    }\n";
        echo "  } catch (err) {\n";
        echo "    console.error('Unable to refresh inventory parent', err);\n";
        echo "  }\n";
        echo "}\n";
        echo "function closeLotDialog() {\n";
        echo "  if (typeof parent.dlgclose === 'function') {\n";
        echo "    parent.dlgclose();\n";
        echo "  } else if (typeof dlgclose === 'function') {\n";
        echo "    dlgclose();\n";
        echo "  } else {\n";
        echo "    window.close();\n";
        echo "  }\n";
        echo "}\n";
        echo "if (typeof parent.dlgclose === 'function' || typeof dlgclose === 'function' || window.opener) {\n";
        echo "  refreshInventoryParent();\n";
        echo "  setTimeout(closeLotDialog, 50);\n";
        echo "} else {\n";
        echo "  window.onload = function() {\n";
        echo "    document.body.innerHTML = `<div id=\'modal-backdrop\' style=\'position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.5);z-index:1040;\'></div>\n";
        echo "    <div class=\'modal show d-block\' tabindex=\'-1\' style=\'position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:1050;display:flex;align-items:center;justify-content:center;\'>\n";
        echo "      <div class=\'modal-dialog modal-dialog-centered\'>\n";
        echo "        <div class=\'modal-content\'>\n";
        echo "          <div class=\'modal-header bg-success text-white\'>\n";
        echo "            <h5 class=\'modal-title\'><i class=\'fa fa-check-circle me-2\'></i>Success!</h5>\n";
        echo "          </div>\n";
        echo "          <div class=\'modal-body text-center\'>\n";
        echo "            <p class=\'mb-3\'>Lot added/updated successfully.</p>\n";
        echo "          </div>\n";
        echo "          <div class=\'modal-footer justify-content-center\'>\n";
        echo "            <button id=\'goInventory\' class=\'btn btn-primary px-4\'>Close Form</button>\n";
        echo "          </div>\n";
        echo "        </div>\n";
        echo "      </div>\n";
        echo "    </div>\n";
        echo "`;
        document.getElementById('goInventory').onclick = function() { 
            refreshInventoryParent();
            if (typeof parent.dlgclose === 'function' || typeof dlgclose === 'function' || window.opener) {
                setTimeout(closeLotDialog, 50);
            } else {
                window.location.href = '../drugs/drug_inventory.php';
            }
        };\n";
        echo "  };\n";
        echo "}\n";
        echo "</script>\n";
        exit();
    }
}

$title = $lot_id ? xl("Update Lot") : xl("Add Lot");
?>
<html>
<head>
<title><?php echo xlt('Edit/Add Lot'); ?></title>
<?php Header::setupHeader(['datetime-picker', 'dialog']); ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
.add-lot-card {
    background: linear-gradient(180deg, #ffffff 0%, #fbfcfe 100%);
    border: 1px solid #e4ebf3;
    border-radius: 18px;
    box-shadow: 0 18px 48px rgba(22, 34, 51, 0.08);
    padding: 1.5rem 1.35rem 1.25rem 1.35rem;
    max-width: 900px;
    margin: 1.25rem auto;
}
.add-lot-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.4rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #e8eef5;
}
.add-lot-title-main {
    display: flex;
    align-items: center;
    gap: 0.85rem;
}
.add-lot-title-icon {
    width: 48px;
    height: 48px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 14px;
    background: linear-gradient(135deg, #0f6cbd 0%, #5a9ff3 100%);
    color: #fff;
    box-shadow: 0 10px 24px rgba(15, 108, 189, 0.25);
}
.add-lot-title-text {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}
.add-lot-heading {
    font-size: 1.3rem;
    font-weight: 700;
    color: #1d2a3a;
    line-height: 1.2;
}
.add-lot-subtitle {
    font-size: 0.93rem;
    color: #66758a;
}
.add-lot-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.45rem 0.8rem;
    border-radius: 999px;
    background: #eef5ff;
    color: #0f5da6;
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 0.02em;
}
.add-lot-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.95rem 1.25rem;
}
@media (max-width: 900px) {
    .add-lot-grid {
        grid-template-columns: 1fr;
    }
    .add-lot-title {
        flex-direction: column;
        align-items: flex-start;
    }
}
.add-lot-label {
    font-weight: 600;
    color: #243447;
    margin-bottom: 0.35rem;
    display: block;
}
.add-lot-input, .add-lot-select {
    width: 100%;
    min-height: 46px;
    padding: 0.7rem 0.85rem;
    border: 1.5px solid #d8e1eb;
    border-radius: 12px;
    font-size: 1rem;
    background: #fff;
    margin-bottom: 0.1rem;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
}
.add-lot-input:focus, .add-lot-select:focus {
    border-color: #4A90E2;
    outline: none;
    box-shadow: 0 0 0 0.2rem rgba(74, 144, 226, 0.18);
    transform: translateY(-1px);
}
.add-lot-actions {
    grid-column: 1 / -1;
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 0.85rem;
    margin-top: 1.2rem;
}
.btn-save {
    background: linear-gradient(135deg, #0f6cbd 0%, #4e9af1 100%);
    color: #fff;
    border: none;
    border-radius: 12px;
    padding: 0.78rem 1.65rem;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
    box-shadow: 0 12px 24px rgba(15, 108, 189, 0.2);
}
.btn-save:hover {
    filter: brightness(0.98);
    transform: translateY(-1px);
}
.btn-cancel {
    background: #f7f9fc;
    color: #495057;
    border: 1px solid #d8e1eb;
    border-radius: 12px;
    padding: 0.78rem 1.65rem;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s ease, transform 0.2s ease;
}
.btn-cancel:hover {
    background: #edf2f7;
    transform: translateY(-1px);
}
.btn-danger {
    background: linear-gradient(135deg, #c73b46 0%, #db5560 100%);
    color: #fff;
    border: none;
    border-radius: 12px;
    padding: 0.78rem 1.65rem;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.btn-danger:hover {
    transform: translateY(-1px);
    box-shadow: 0 12px 22px rgba(199, 59, 70, 0.2);
}
.btn-warning {
    background: #ffc107;
    color: #212529;
    border: none;
    border-radius: 8px;
    padding: 0.5rem 1.5rem;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}
.btn-warning:hover {
    background: #e0a800;
}
.btn-primary {
    background: linear-gradient(135deg, #1668c7 0%, #0b84ff 100%);
    color: #fff;
    border: none;
    border-radius: 12px;
    padding: 0.78rem 1.65rem;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 12px 22px rgba(11, 132, 255, 0.18);
}
.required {
    color: #dc3545;
}
.add-lot-helper {
    display: block;
    margin-top: 0.45rem;
    color: #6c7b8e;
    font-size: 0.88rem;
    line-height: 1.45;
}
.inventory-note {
    grid-column: 1 / -1;
    padding: 0.85rem 1rem;
    border-radius: 12px;
    border: 1px solid #f0d99e;
    background: linear-gradient(180deg, #fff9ec 0%, #fff4d8 100%);
    color: #855b10;
    font-size: 0.86rem;
}
.liquid-inventory-panel {
    grid-column: 1 / -1;
    padding: 1.1rem;
    border-radius: 16px;
    border: 1px solid #dce8f5;
    background: linear-gradient(180deg, #f9fcff 0%, #f2f7fd 100%);
}
.liquid-panel-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}
.liquid-panel-title {
    font-size: 1.08rem;
    font-weight: 700;
    color: #1d2a3a;
}
.liquid-panel-subtitle {
    margin-top: 0.2rem;
    color: #66758a;
    font-size: 0.88rem;
    line-height: 1.45;
}
.liquid-panel-badge {
    flex-shrink: 0;
    padding: 0.45rem 0.7rem;
    border-radius: 999px;
    background: #e6f1ff;
    color: #0b5fb3;
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.liquid-panel-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(220px, 1fr));
    gap: 14px;
}
.liquid-field {
    display: flex;
    flex-direction: column;
}
.liquid-field-wide {
    grid-column: 1 / -1;
}
.liquid-metric {
    min-height: 112px;
    padding: 0.95rem 1rem;
    border: 1px solid #d9e4f0;
    border-radius: 14px;
    background: #fff;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.75);
}
.liquid-metric-label {
    display: block;
    margin-bottom: 0.55rem;
    color: #617286;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.liquid-metric-value {
    color: #1d2a3a;
    font-size: 1.02rem;
    font-weight: 600;
    line-height: 1.5;
}
@media (max-width: 900px) {
    .liquid-panel-grid {
        grid-template-columns: 1fr;
    }
}
.form-control {
    width: 100%;
    padding: 0.45rem 0.7rem;
    border: 1.5px solid #E1E5E9;
    border-radius: 7px;
    font-size: 0.98rem;
    background: white;
    margin-bottom: 0.1rem;
}
.form-control:focus {
    border-color: #4A90E2;
    outline: none;
    box-shadow: 0 0 0 0.2rem rgba(74, 144, 226, 0.25);
}
.hidden {
    display: none !important;
}
</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
.add-lot-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    padding: 1.2rem 1rem 1rem 1rem;
    max-width: 900px;
    margin: 1.2rem auto;
}
.add-lot-title {
    font-size: 1.2rem;
    font-weight: 700;
    margin-bottom: 1.5rem;
    color: #2a3b4d;
    text-align: center;
}
.add-lot-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.7rem 1.2rem;
}
@media (max-width: 900px) {
    .add-lot-grid {
        grid-template-columns: 1fr;
    }
}
.add-lot-label {
    font-weight: 600;
    color: #333;
    margin-bottom: 0.1rem;
    display: block;
}
.add-lot-input, .add-lot-select {
    width: 100%;
    padding: 0.45rem 0.7rem;
    border: 1.5px solid #E1E5E9;
    border-radius: 7px;
    font-size: 0.98rem;
    background: white;
    margin-bottom: 0.1rem;
}
.add-lot-input:focus, .add-lot-select:focus {
    border-color: #4A90E2;
    outline: none;
    box-shadow: 0 0 0 0.2rem rgba(74, 144, 226, 0.25);
}
.add-lot-actions {
    grid-column: 1 / -1;
    display: flex;
    justify-content: center;
    gap: 0.7rem;
    margin-top: 1rem;
}
.btn-save {
    background: #4A90E2;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 0.5rem 1.5rem;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}
.btn-save:hover {
    background: #357ABD;
}
.btn-cancel {
    background: #f8f9fa;
    color: #495057;
    border: 2px solid #E1E5E9;
    border-radius: 8px;
    padding: 0.5rem 1.5rem;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}
.btn-cancel:hover {
    background: #e9ecef;
}
.btn-danger {
    background: #dc3545;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 0.5rem 1.5rem;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}
.btn-danger:hover {
    background: #c82333;
}
.btn-warning {
    background: #ffc107;
    color: #212529;
    border: none;
    border-radius: 8px;
    padding: 0.5rem 1.5rem;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}
.btn-warning:hover {
    background: #e0a800;
}
.btn-primary {
    background: #007bff;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 0.5rem 1.5rem;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}
.btn-primary:hover {
    background: #0056b3;
}
.required {
    color: #dc3545;
}
.form-control {
    width: 100%;
    padding: 0.45rem 0.7rem;
    border: 1.5px solid #E1E5E9;
    border-radius: 7px;
    font-size: 0.98rem;
    background: white;
    margin-bottom: 0.1rem;
}
.form-control:focus {
    border-color: #4A90E2;
    outline: none;
    box-shadow: 0 0 0 0.2rem rgba(74, 144, 226, 0.25);
}
.hidden {
    display: none !important;
}
</style>
</head>
<body class="body_top">

<div class="add-lot-card">
    <div class="add-lot-title">
        <div class="add-lot-title-main">
            <span class="add-lot-title-icon"><i class="fas fa-vials"></i></span>
            <div class="add-lot-title-text">
                <span class="add-lot-heading"><?php echo text($lot_id ? xlt('Update Inventory Lot') : xlt('Add Inventory Lot')); ?></span>
                <span class="add-lot-subtitle"><?php echo text($drug_data['name'] ?? xlt('Medication Inventory')); ?></span>
            </div>
        </div>
        <span class="add-lot-pill"><?php echo text($is_liquid_inventory ? xlt('Liquid Inventory') : xlt('Inventory Entry')); ?></span>
    </div>

<form method='post' name='theform' action='add_edit_lot.php?drug=<?php echo attr_url($drug_id); ?>&lot=<?php echo attr_url($lot_id); ?>' onsubmit='return validate()'>
<input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />



        <div class="add-lot-grid">
            <!-- Received Date -->
            <div id='row_sale_date'>
                <label class="add-lot-label"><?php echo xlt('Received Date'); ?></label>
                <input type='text' class="datepicker form-control" size='10' name='form_sale_date' id='form_sale_date'
    value='<?php echo attr($form_sale_date_value); ?>'
    title='<?php echo xla('yyyy-mm-dd date of purchase or transfer'); ?>' />
            </div>

            <!-- Transaction Type -->
            <div>
                <label class="add-lot-label"><?php echo xlt('Transaction Type'); ?></label>
                <select name='form_trans_type' class='add-lot-select form-control' onchange='trans_type_changed()'>
<?php
foreach (
    array(
    '2' => xl('Purchase/Receipt'),
    '3' => xl('Return'),
    '4' => xl('Transfer'),
    '5' => xl('Adjustment'),
    // '7' => xl('Consumption'), // Hidden for now, keep for future use
    '0' => xl('Edit Only'),
    ) as $key => $value
) {
    echo "<option value='" . attr($key) . "'";
    if (
        !$auth_admin && (
        $key == 2 && !AclMain::aclCheckCore('inventory', 'purchases') ||
        $key == 3 && !AclMain::aclCheckCore('inventory', 'purchases') ||
        $key == 4 && !AclMain::aclCheckCore('inventory', 'transfers') ||
        $key == 5 && !AclMain::aclCheckCore('inventory', 'adjustments') ||
        $key == 7 && !AclMain::aclCheckCore('inventory', 'consumption')
        )
    ) {
        echo " disabled";
    } else if (
        !$lot_id && in_array($key, array('0', '3', '5', '7'))
    ) {
        echo " disabled";
    } else {
        if (isset($_POST['form_trans_type']) && $key == $form_trans_type) {
            echo " selected";
        }
    }
    echo ">" . text($value) . "</option>\n";
}
?>
   </select>
            </div>

            <!-- Lot Number - Only show for medication categories -->
            <?php if ($is_medical_category): ?>
            <div id='row_lot_number'>
                <label class="add-lot-label"><?php echo xlt('Lot Number'); ?> <span class="required">*</span></label>
                <input class="add-lot-input form-control" type='text' size='20' name='form_lot_number' maxlength='20' 
    value='<?php echo attr($row['lot_number']); ?>' required />
            </div>
            <?php else: ?>
            <!-- Hidden lot number field for non-medication products -->
            <input type='hidden' name='form_lot_number' value='N/A' />
            <div class="inventory-note">
                <strong>Note:</strong> Lot Number field is hidden for non-medication products (Category: <?php echo attr($drug_data['category_name'] ?? 'NULL'); ?>)
            </div>
            <?php endif; ?>

            <!-- Manufacturer (Hidden for now, but kept for future use) -->
            <div id='row_manufacturer' style="display: none;">
                <label class="add-lot-label"><?php echo xlt('Manufacturer'); ?></label>
                <input class="add-lot-input form-control" type='text' size='40' name='form_manufacturer' maxlength='255' 
    value='<?php echo attr($row['manufacturer']); ?>' />
            </div>

            <!-- Expiration -->
            <div id='row_expiration'>
                <label class="add-lot-label"><?php echo xlt('Expiration'); ?></label>
                <input class="add-lot-input form-control datepicker-us" type='text' name='form_expiration' maxlength='10' 
    value='<?php echo attr($row['expiration']); ?>'
                 placeholder='MM/DD/YYYY' autocomplete='off' />
            </div>

            <!-- Supplier -->
            <div id='row_supplier'>
                <label class="add-lot-label"><?php echo xlt('Supplier'); ?></label>
                <select name='form_supplier_id' class='add-lot-select form-control'>
                 <option value=''><?php echo xlt('Select Supplier'); ?></option>
<?php
                     $sres = sqlStatement("SELECT option_id, title FROM list_options " .
                        "WHERE list_id = 'Suppliers' AND activity = 1 ORDER BY seq, title");
                    while ($srow = sqlFetchArray($sres)) {
                         echo "   <option value='" . attr($srow['option_id']) . "'";
                        if (isset($row['supplier_id']) && $srow['option_id'] == $row['supplier_id']) {
            echo " selected";
        }
                         echo ">" . text($srow['title']) . "</option>\n";
    }
                ?>
                </select>
            </div>

            <!-- Source Lot -->
            <div id='row_source_lot'>
                <label class="add-lot-label"><?php echo xlt('Source Lot'); ?></label>
                <select name='form_source_lot' class='add-lot-select form-control'>
    <option value='0|0'><?php echo xlt('None'); ?></option>
<?php
$sres = sqlStatement(
    "SELECT di.inventory_id, di.lot_number, di.on_hand, " .
    "lo.title AS warehouse_name, f.name AS facility_name " .
    "FROM drug_inventory AS di " .
    "LEFT JOIN list_options AS lo ON lo.list_id = 'warehouse' AND " .
    "lo.option_id = di.warehouse_id AND lo.activity = 1 " .
    "LEFT JOIN facility AS f ON f.id = COALESCE(lo.option_value, di.facility_id) " .
    "WHERE di.drug_id = $drug_id_escaped AND di.inventory_id != $lot_id AND di.on_hand > 0 " .
    "AND di.destroy_date IS NULL " .
    ($selectedFacilityId > 0 ? "AND COALESCE(lo.option_value, di.facility_id) = " . (int) $selectedFacilityId . " " : "") .
    "ORDER BY di.lot_number"
);
while ($srow = sqlFetchArray($sres)) {
    $facility_name = $srow['facility_name'] ? $srow['facility_name'] : 'N/A';
    $warehouse_name = $srow['warehouse_name'] ? $srow['warehouse_name'] : 'N/A';
    $quantity_display = formatDrugInventoryQuantity($drug_data, $srow['on_hand']);
    if ($is_liquid_inventory && !empty($liquid_inventory['mg_per_vial'])) {
        $vials_on_hand = $srow['on_hand'] / $liquid_inventory['mg_per_vial'];
        $quantity_display = formatDrugInventoryNumber($vials_on_hand) . ' vials';
    }
    $display_name = $srow['lot_number'] . ' (' . $facility_name . ' - ' . $warehouse_name . ' - ' . $quantity_display . ')';
    echo "    <option value='" . attr($srow['inventory_id'] . '|' . $facility_name) . "'>" .
    text($display_name) . "</option>\n";
}
?>
   </select>
            </div>

            <!-- On Hand -->
            <div id='row_on_hand'>
                <label class="add-lot-label" id="on_hand_label"><?php echo text($is_liquid_inventory ? xl('On Hand (Vials)') : xl('On Hand')); ?></label>
                <span class="add-lot-input form-control" id="current_on_hand_display" style="background: #f8f9fa;"><?php
                    if ($is_liquid_inventory && !empty($liquid_inventory['mg_per_vial'])) {
                        echo text(formatDrugInventoryNumber(($row['on_hand'] + 0) / $liquid_inventory['mg_per_vial']) . ' vials');
                    } else {
                        echo text($row['on_hand'] + 0);
                    }
                ?></span>
                <?php if ($is_liquid_inventory) { ?>
                <small id="current_on_hand_mg" class="add-lot-helper"><?php echo text(formatDrugInventoryQuantity($drug_data, $row['on_hand'] + 0)); ?></small>
                <?php } ?>
            </div>

            <!-- Received -->
            <div id='row_quantity'>
                <label class="add-lot-label" id="quantity_label"><?php echo text($is_liquid_inventory ? xl('Vial Quantity') : xl('Received')); ?></label>
                <input class="add-lot-input form-control" type='text' size='5' name='form_quantity' id='form_quantity' maxlength='7' />
                <?php if ($is_liquid_inventory) { ?>
                <small id="quantity_help" class="add-lot-helper"><?php echo xlt('For receipt, return, and transfer, quantity is entered as vial count.'); ?></small>
                <?php } ?>
            </div>

            <?php if ($is_liquid_inventory) { ?>
            <div id='row_liquid_inventory_info' class="liquid-inventory-panel">
                <div class="liquid-panel-header">
                    <div>
                        <div class="liquid-panel-title"><?php echo xlt('Liquid Inventory Overview'); ?></div>
                        <div class="liquid-panel-subtitle"><?php echo xlt('Review vial volume, concentration, and the total calculated medication before saving this lot movement.'); ?></div>
                    </div>
                    <span class="liquid-panel-badge"><?php echo xlt('Auto Calculation'); ?></span>
                </div>
                <div class="liquid-panel-grid">
                    <div class="liquid-field">
                        <label class="add-lot-label" style="margin-bottom:6px;"><?php echo xlt('mL per Vial'); ?></label>
                        <input class="add-lot-input form-control" type="number" min="0" step="0.01" id="lot_ml_per_vial" name="form_lot_ml_per_vial" value="<?php echo attr(formatDrugInventoryNumber($liquid_inventory['ml_per_vial'])); ?>" />
                    </div>
                    <div class="liquid-field">
                        <label class="add-lot-label" style="margin-bottom:6px;"><?php echo xlt('mg per mL'); ?></label>
                        <input class="add-lot-input form-control" type="number" min="0" step="0.01" id="lot_mg_per_ml" name="form_lot_mg_per_ml" value="<?php echo attr(formatDrugInventoryNumber($liquid_inventory['mg_per_ml'])); ?>" />
                    </div>
                    <div class="liquid-field">
                        <label class="add-lot-label" style="margin-bottom:6px;"><?php echo xlt('Total mg per Vial'); ?></label>
                        <div class="liquid-metric">
                            <span class="liquid-metric-label"><?php echo xlt('Per Vial Calculation'); ?></span>
                            <div class="liquid-metric-value" id="lot_mg_per_vial_calc"><?php echo xlt('Enter vial quantity.'); ?></div>
                        </div>
                    </div>
                    <div class="liquid-field liquid-field-wide">
                        <label class="add-lot-label" style="margin-bottom:6px;"><?php echo xlt('Total Inventory'); ?></label>
                        <div class="liquid-metric">
                            <span class="liquid-metric-label"><?php echo xlt('Calculated Total'); ?></span>
                            <div class="liquid-metric-value" id="lot_total_inventory_calc"><?php echo xlt('Enter vial quantity to calculate total mg.'); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php } ?>

            <!-- Note: Cost and Sell Price are now managed at the product level, not lot level -->

            <!-- Comments -->
            <div id='row_notes' style="grid-column: 1 / -1;" title='<?php echo xla('Include your initials and details of reason for transaction.'); ?>'>
                <label class="add-lot-label"><?php echo xlt('Comments'); ?></label>
                <input class="add-lot-input form-control" type='text' size='40' name='form_notes' maxlength='255' />
            </div>
        </div>

        <div class="add-lot-actions">
            <input type='submit' class="btn-save" name='form_save' value='<?php echo $lot_id ? xla('Update') : xla('Add') ?>' />

<?php if ($lot_id && ($auth_admin || AclMain::aclCheckCore('inventory', 'destruction'))) { ?>
            <input type='button' class="btn-danger" value='<?php echo xla('Destroy'); ?>'
 onclick="window.location.href='destroy_lot.php?drug=<?php echo attr_url($drug_id); ?>&lot=<?php echo attr_url($lot_id); ?>'" />
<?php } ?>

            <input type='button' class="btn-primary" value='<?php echo xla('Print'); ?>' onclick='window.print()' />

            <input type='button' class="btn-cancel" value='<?php echo xla('Cancel'); ?>' onclick='if (typeof parent.dlgclose === "function") { parent.dlgclose(); } else if (typeof dlgclose === "function") { dlgclose(); } else { window.close(); }' />
</div>
</form>
</div>

<script>
<?php
if ($info_msg) {
    echo " alert('" . addslashes($info_msg) . "');\n";
    echo " if (typeof parent.dlgclose === 'function') {\n";
    echo "   parent.dlgclose();\n";
    echo " } else if (typeof dlgclose === 'function') {\n";
    echo "   dlgclose();\n";
    echo " } else if (window.opener) {\n";
    echo "   window.close();\n";
    echo " } else {\n";
    echo "   window.location.href = '../drugs/drug_inventory.php';\n";
    echo " }\n";
}
?>

var liquidLotConfig = <?php echo json_encode(array(
    'enabled' => $is_liquid_inventory,
    'drugName' => $drug_data['name'] ?? '',
    'mgPerVial' => $liquid_inventory['mg_per_vial'] ?? 0,
)); ?>;

// Add US datepicker and auto-format for expiration
setTimeout(function() {
    var expInput = document.querySelector('input[name="form_expiration"]');
    if (expInput) {
        $(expInput).datetimepicker({
            timepicker: false,
            format: 'm/d/Y',
            formatDate: 'm/d/Y',
            scrollMonth: false,
            scrollInput: false,
            closeOnDateSelect: true
        });
        expInput.addEventListener('blur', function() {
            var v = this.value.replace(/[^0-9]/g, '');
            if (v.length === 8) {
                this.value = v.substring(0,2) + '/' + v.substring(2,4) + '/' + v.substring(4);
            }
        });
    }
}, 500);

function formatLotNumber(value) {
    return Number(value || 0).toFixed(2);
}

function updateLiquidLotUi() {
    if (!liquidLotConfig.enabled) {
        return;
    }

    var f = document.forms[0];
    var ttype = f.form_trans_type.value;
    var quantityField = f.form_quantity;
    var enteredQuantity = parseFloat(quantityField.value || '0');
    var usesVials = ttype === '2' || ttype === '3' || ttype === '4';
    var quantityLabel = document.getElementById('quantity_label');
    var quantityHelp = document.getElementById('quantity_help');
    var lotMlPerVial = document.getElementById('lot_ml_per_vial');
    var lotMgPerMl = document.getElementById('lot_mg_per_ml');
    var mgPerVialCalc = document.getElementById('lot_mg_per_vial_calc');
    var totalInventoryCalc = document.getElementById('lot_total_inventory_calc');
    var vialQuantity = parseFloat(quantityField.value || '0');
    var mlPerVial = parseFloat((lotMlPerVial ? lotMlPerVial.value : '0') || '0');
    var mgPerMl = parseFloat((lotMgPerMl ? lotMgPerMl.value : '0') || '0');
    var mgPerVial = mlPerVial * mgPerMl;
    var totalMg = Math.max(vialQuantity, 0) * mgPerVial;
    liquidLotConfig.mgPerVial = mgPerVial;

    quantityLabel.textContent = usesVials ? 'Vial Quantity' : 'Quantity (mg)';
    if (quantityHelp) {
        quantityHelp.textContent = usesVials ?
            'Quantity is entered as vial count for receipt, return, and transfer.' :
            'Quantity is entered directly in mg for adjustments and consumption.';
    }

    if (mgPerVialCalc) {
        if (mlPerVial > 0 && mgPerMl > 0) {
            mgPerVialCalc.textContent = formatLotNumber(mgPerMl) + ' mg x ' + formatLotNumber(mlPerVial) + ' mL = ' + formatLotNumber(mgPerVial) + ' mg per vial';
        } else {
            mgPerVialCalc.textContent = 'Enter vial quantity.';
        }
    }

    if (totalInventoryCalc) {
        if (usesVials && mlPerVial > 0 && mgPerMl > 0) {
            totalInventoryCalc.textContent = formatLotNumber(Math.max(vialQuantity, 0)) + ' vials x ' + formatLotNumber(mgPerVial) + ' mg = ' + formatLotNumber(totalMg) + ' mg total' + (liquidLotConfig.drugName ? ' ' + liquidLotConfig.drugName : '');
        } else if (usesVials) {
            totalInventoryCalc.textContent = 'Enter vial quantity to calculate total mg.';
        } else {
            totalInventoryCalc.textContent = formatLotNumber(Math.max(enteredQuantity, 0)) + ' mg';
        }
    }
}

function trans_type_changed() {
    console.log('trans_type_changed() called');
    var f = document.forms[0];
    var ttype = f.form_trans_type.value;
    console.log('Transaction type selected:', ttype);
    
    // Show/hide rows based on transaction type
    var showRows = ['row_sale_date', 'row_lot_number', 'row_expiration', 'row_vendor_id', 'row_warehouse_id', 'row_on_hand', 'row_quantity', 'row_cost', 'row_sell_price', 'row_notes'];
    var hideRows = ['row_source_lot', 'row_manufacturer'];
    
    if (ttype == '4') { // Transfer
        showRows.push('row_source_lot');
        hideRows = hideRows.filter(function(item) { return item !== 'row_source_lot'; });
        console.log('Transfer selected - showing source lot');
    }
    
    console.log('Rows to show:', showRows);
    console.log('Rows to hide:', hideRows);
    
    // Show all rows first using CSS classes
    showRows.forEach(function(rowId) {
        var row = document.getElementById(rowId);
        if (row) {
            row.style.display = '';
            row.className = row.className.replace(' d-none', '').replace(' hidden', '');
            console.log('Showing row:', rowId);
        } else {
            console.log('Row not found:', rowId);
        }
    });
    
    // Hide specific rows using CSS classes
    hideRows.forEach(function(rowId) {
        var row = document.getElementById(rowId);
        if (row) {
            row.style.display = 'none';
            console.log('Hiding row:', rowId);
        } else {
            console.log('Row not found for hiding:', rowId);
        }
    });
    
    // Enable/disable fields based on transaction type
    var quantityField = f.form_quantity;
    var costField = f.form_cost;
    var sellPriceField = f.form_sell_price;
    var vendorField = f.form_vendor_id;
    var warehouseField = f.form_warehouse_id;
    var onHandField = f.form_on_hand;
    
    if (ttype == '0') { // Edit Only
        // Disable quantity-related fields
        if (quantityField) quantityField.disabled = true;
        if (costField) { costField.disabled = true; costField.value = ''; }
        if (sellPriceField) sellPriceField.disabled = true;
        if (vendorField) vendorField.disabled = true;
        if (warehouseField) warehouseField.disabled = true;
        if (onHandField) onHandField.disabled = true;
        console.log('Edit Only - disabled quantity, cost, sell price, vendor, warehouse, and on hand fields');
    } else {
        // Enable fields based on transaction type
        if (quantityField) quantityField.disabled = false;
        if (costField) {
            if (ttype == '5' || ttype == '7') { // Adjustment or Consumption
                costField.disabled = true;
                costField.value = '0.00';
                console.log('Adjustment/Consumption - disabled cost field');
            } else {
                costField.disabled = false;
                console.log('Enabled cost field');
            }
        }
        if (sellPriceField) sellPriceField.disabled = false;
        if (vendorField) vendorField.disabled = false;
        if (warehouseField) warehouseField.disabled = false;
        if (onHandField) onHandField.disabled = false;
    }

    updateLiquidLotUi();
}

function validate() {
    var f = document.forms[0];
    var ttype = f.form_trans_type.value;
    var quantityField = f.form_quantity;
    var costField = f.form_cost;
    var lotNumberField = f.form_lot_number;
    var isMedicalCategory = <?php echo ($is_medical_category ? 'true' : 'false'); ?>;
    
    // Validate Lot Number (only required for medication categories)
    if (isMedicalCategory && lotNumberField && !lotNumberField.value.trim()) {
        alert('Lot Number is required for medication products');
        lotNumberField.focus();
        return false;
    }
    
    // For non-medication categories, the hidden field already has 'N/A' value
    // No additional validation needed
    
    if (ttype != '0') { // Not Edit Only
        if (quantityField && !quantityField.value) {
            alert('Quantity is required');
            quantityField.focus();
            return false;
        }
        if (quantityField && (isNaN(quantityField.value) || quantityField.value <= 0)) {
            alert('Quantity must be a positive number');
            quantityField.focus();
            return false;
        }
        if (ttype != '5' && ttype != '7') { // Not Adjustment or Consumption
            if (costField && !costField.value) {
                alert('Cost is required');
                costField.focus();
                return false;
            }
            if (costField && (isNaN(costField.value) || costField.value < 0)) {
                alert('Cost must be a non-negative number');
                costField.focus();
                return false;
            }
        }
    }
    return true;
}

// Initialize form state with multiple event listeners for reliability
console.log('Page loaded - initializing form state');

// Call immediately
trans_type_changed();

// Call when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded - calling trans_type_changed()');
    trans_type_changed();
    if (document.forms[0] && document.forms[0].form_quantity) {
        document.forms[0].form_quantity.addEventListener('input', updateLiquidLotUi);
    }
    var mlField = document.getElementById('lot_ml_per_vial');
    if (mlField) {
        mlField.addEventListener('input', updateLiquidLotUi);
    }
    var mgField = document.getElementById('lot_mg_per_ml');
    if (mgField) {
        mgField.addEventListener('input', updateLiquidLotUi);
    }
});

// Call when window is fully loaded
window.addEventListener('load', function() {
    console.log('Window loaded - calling trans_type_changed()');
    trans_type_changed();
});

// Call after a short delay to ensure everything is rendered
setTimeout(function() {
    console.log('Delayed initialization - calling trans_type_changed()');
    trans_type_changed();
}, 100);
</script>

</body>
</html>
