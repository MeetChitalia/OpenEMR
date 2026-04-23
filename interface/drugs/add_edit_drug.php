<?php

 // Copyright (C) 2006-2021 Rod Roark <rod@sunsetsystems.com>
 //
 // This program is free software; you can redistribute it and/or
 // modify it under the terms of the GNU General Public License
 // as published by the Free Software Foundation; either version 2
 // of the License, or (at your option) any later version.

require_once("../globals.php");
require_once("drugs.inc.php");
require_once("$srcdir/options.inc.php");
require_once(__DIR__ . "/../../library/InventoryAuditLogger.class.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;

$alertmsg = '';
$drug_id = $_REQUEST['drug'];
$info_msg = "";
$tmpl_line_no = 0;

if (!AclMain::aclCheckCore('admin', 'drugs')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Edit/Add Drug")]);
    exit;
}

ensureDrugInventoryMetadataSchema();

function isMedicationCategoryNameForDrugForm($categoryName)
{
    $normalized = strtolower(trim((string) $categoryName));
    return in_array($normalized, ['medical', 'medication'], true)
        || stripos((string) $categoryName, 'medical') !== false
        || stripos((string) $categoryName, 'medication') !== false;
}

function getCurrentDrugSessionFacilityId()
{
    if (!empty($_SESSION['facilityId'])) {
        return (int) $_SESSION['facilityId'];
    }

    if (!empty($_SESSION['facility_id'])) {
        return (int) $_SESSION['facility_id'];
    }

    return 0;
}

function ensureDrugFacilityPlaceholderInventory($drugId, $facilityId)
{
    $drugId = (int) $drugId;
    $facilityId = (int) $facilityId;
    if ($drugId <= 0 || $facilityId <= 0) {
        return;
    }

    $existing = sqlQueryNoLog(
        "SELECT inventory_id
           FROM drug_inventory
          WHERE drug_id = ?
            AND facility_id = ?
            AND COALESCE(lot_number, '') = ''
            AND COALESCE(warehouse_id, '') = ''
            AND COALESCE(on_hand, 0) = 0
            AND destroy_date IS NULL
          LIMIT 1",
        array($drugId, $facilityId)
    );

    if (!empty($existing['inventory_id'])) {
        return;
    }

    sqlStatement(
        "INSERT INTO drug_inventory
            (drug_id, lot_number, expiration, manufacturer, on_hand, warehouse_id, facility_id, vendor_id, supplier_id)
         VALUES (?, '', NULL, '', 0, '', ?, 0, 0)",
        array($drugId, $facilityId)
    );
}

// Write a line of data for one template to the form.
//
function writeTemplateLine($selector, $dosage, $period, $quantity, $refills, $prices, $taxrates, $pkgqty)
{
    global $tmpl_line_no;
    ++$tmpl_line_no;

    echo " <tr>\n";
    echo "  <td class='tmplcell drugsonly'>";
    echo "<input class='form-control' name='form_tmpl[" . attr($tmpl_line_no) . "][selector]' value='" . attr($selector) . "' size='8' maxlength='100'>";
    echo "</td>\n";
    echo "  <td class='tmplcell drugsonly'>";
    echo "<input class='form-control' name='form_tmpl[" . attr($tmpl_line_no) . "][dosage]' value='" . attr($dosage) . "' size='6' maxlength='10'>";
    echo "</td>\n";
    echo "  <td class='tmplcell drugsonly'>";
    generate_form_field(array(
    'data_type'   => 1,
    'field_id'    => 'tmpl[' . attr($tmpl_line_no) . '][period]',
    'list_id'     => 'drug_interval',
    'empty_title' => 'SKIP'
    ), $period);
    echo "</td>\n";
    echo "  <td class='tmplcell drugsonly'>";
    echo "<input class='form-control' name='form_tmpl[" . attr($tmpl_line_no) . "][quantity]' value='" . attr($quantity) . "' size='3' maxlength='7'>";
    echo "</td>\n";
    echo "  <td class='tmplcell drugsonly'>";
    echo "<input class='form-control' name='form_tmpl[" . attr($tmpl_line_no) . "][refills]' value='" . attr($refills) . "' size='3' maxlength='5'>";
    echo "</td>\n";

    /******************************************************************
    echo "  <td class='tmplcell drugsonly'>";
    echo "<input type='text' class='form-control' name='form_tmpl[" . attr($tmpl_line_no) .
        "][pkgqty]' value='" . attr($pkgqty) . "' size='3' maxlength='5'>";
    echo "</td>\n";
    ******************************************************************/

    foreach ($prices as $pricelevel => $price) {
        echo "  <td class='tmplcell'>";
        echo "<input class='form-control' name='form_tmpl[" . attr($tmpl_line_no) . "][price][" . attr($pricelevel) . "]' value='" . attr($price) . "' size='6' maxlength='12'>";
        echo "</td>\n";
    }

    $pres = sqlStatement("SELECT option_id FROM list_options " .
    "WHERE list_id = 'taxrate' AND activity = 1 ORDER BY seq");
    while ($prow = sqlFetchArray($pres)) {
        echo "  <td class='tmplcell'>";
        echo "<input type='checkbox' name='form_tmpl[" . attr($tmpl_line_no) . "][taxrate][" . attr($prow['option_id']) . "]' value='1'";
        if (strpos(":$taxrates", $prow['option_id']) !== false) {
            echo " checked";
        }

        echo " /></td>\n";
    }

    echo " </tr>\n";
}
?>
<html>
<head>
<title><?php echo $drug_id ? xlt("Edit") : xlt("Add New");
echo ' ' . xlt('Drug'); ?></title>

<?php Header::setupHeader(["opener"]); ?>

<style>
/* Healthcare Inventory Form Styling */
.healthcare-form {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.07);
    padding: 1.2rem 1rem 1rem 1rem;
    max-width: 900px;
    margin: 1.2rem auto;
}

.healthcare-form h3 {
    color: #2c3e50;
    font-weight: 700;
    margin-bottom: 1rem;
    text-align: center;
    border-bottom: 2px solid #3498db;
    padding-bottom: 0.5rem;
}

.form-section {
    background: #f8f9fa;
    border-radius: 6px;
    padding: 1rem 1.2rem 0.5rem 1.2rem;
    margin-bottom: 1rem;
    border-left: 3px solid #3498db;
}

.form-section h4 {
    color: #2c3e50;
    font-weight: 600;
    margin-bottom: 0.7rem;
    font-size: 1.05rem;
}

.form-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 1.2rem 2.5%;
}

.form-grid .form-group {
    flex: 1 1 45%;
    min-width: 220px;
    margin-bottom: 0.7rem;
}

.form-group label {
    font-weight: 600;
    color: #34495e;
    margin-bottom: 0.3rem;
    display: block;
}

.form-control {
    border: 1.5px solid #e9ecef;
    border-radius: 5px;
    padding: 0.5rem 0.7rem;
    font-size: 1rem;
    transition: border-color 0.2s;
}

.form-control:focus {
    border-color: #3498db;
    box-shadow: 0 0 0 0.1rem rgba(52, 152, 219, 0.13);
}

.checkbox-group {
    display: flex;
    flex-wrap: wrap;
    gap: 1.2rem;
    align-items: center;
}

.checkbox-group label {
    margin-bottom: 0;
    font-weight: 500;
}

.btn-group {
    display: flex;
    gap: 1rem;
    justify-content: center;
    margin-top: 1.2rem;
}

.btn {
    padding: 0.6rem 1.2rem;
    border-radius: 5px;
    font-weight: 600;
    font-size: 1rem;
    border: none;
    cursor: pointer;
    transition: background 0.2s, box-shadow 0.2s;
}

.btn-primary {
    background: #3498db;
    color: white;
}

.btn-primary:hover {
    background: #2980b9;
}

.btn-secondary {
    background: #95a5a6;
}

/* Discount styles */
.discount-display {
    margin-top: 0.5rem;
}

.discount-summary {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.discount-value {
    font-weight: 600;
    padding: 0.3rem 0.6rem;
    border-radius: 4px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
}

.discount-active {
    color: #2e7d32 !important;
    background: #e8f5e8 !important;
    border-color: #4caf50 !important;
}

.discount-inactive {
    color: #666 !important;
    background: #f5f5f5 !important;
    border-color: #ccc !important;
    font-style: italic;
}

.discount-expired {
    color: #d32f2f !important;
    background: #ffebee !important;
    border-color: #f44336 !important;
    font-style: italic;
}

/* Modal styles */
.discount-modal {
    font-family: inherit;
}

.discount-modal input[type="checkbox"] {
    margin-right: 8px;
}

.discount-modal label {
    font-weight: 500;
    color: #333;
}

.discount-modal select,
.discount-modal input[type="number"],
.discount-modal input[type="date"],
.discount-modal input[type="month"],
.discount-modal textarea {
    font-family: inherit;
    font-size: 14px;
}
    color: white;
}

.btn-secondary:hover {
    background: #7f8c8d;
}

.btn-danger {
    background: #e74c3c;
    color: white;
}

.btn-danger:hover {
    background: #c0392b;
}

.medical-fields, #status-permissions-section {
    display: none;
}
.medical-fields.show, #status-permissions-section.show {
    display: block;
    animation: fadeIn 0.2s;
}

@media (max-width: 900px) {
    .form-grid .form-group { min-width: 180px; }
}
@media (max-width: 600px) {
    .form-grid { flex-direction: column; gap: 0.5rem; }
    .form-grid .form-group { min-width: 100%; }
    .btn-group { flex-direction: column; gap: 0.5rem; }
}

<?php if ($GLOBALS['sell_non_drug_products'] == 2) { // "Products but no prescription drugs and no templates" ?>
.drugsonly { display:none; }
<?php } else { ?>
.drugsonly { }
<?php } ?>

<?php if (empty($GLOBALS['ippf_specific'])) { ?>
.ippfonly { display:none; }
<?php } else { ?>
.ippfonly { }
<?php } ?>

/* Hide Templates section */
.templates-section {
    display: none !important;
}
</style>

<script>

<?php require($GLOBALS['srcdir'] . "/restoreSession.php"); ?>

// This is for callback by the find-code popup.
// Appends to or erases the current list of related codes.
// The target element is set by the find-code popup
// (this allows use of this in multiple form elements on the same page)
function set_related_target(codetype, code, selector, codedesc, target_element, limit=0) {
    var f = document.forms[0];
    var s = f[target_element].value;
    if (code) {
        if (limit > 0) {
            s = codetype + ':' + code;
        }
        else {
            if (codetype != 'PROD') {
                // Return an error message if a service code is already selected.
                if (s.indexOf(codetype + ':') == 0 || s.indexOf(';' + codetype + ':') > 0) {
                    return <?php echo xlj('A code of this type is already selected. Erase the field first if you need to replace it.') ?>;
                }
            }
            if (s.length > 0) {
                s += ';';
            }
            s += codetype + ':' + code;
        }
    } else {
        s = '';
    }
    f[target_element].value = s;
    return '';
}

// This is for callback by the find-code popup.
// Returns the array of currently selected codes with each element in codetype:code format.
function get_related() {
 return document.forms[0].form_related_code.value.split(';');
}

// This is for callback by the find-code popup.
// Deletes the specified codetype:code from the currently selected list.
function del_related(s) {
 my_del_related(s, document.forms[0].form_related_code, false);
}

// This invokes the find-code popup.
function sel_related(getter = '') {
 dlgopen('../patient_file/encounter/find_code_dynamic.php' + getter, '_blank', 900, 800);
}

// onclick handler for "allow inventory" checkbox.
function dispensable_changed() {
 var f = document.forms[0];
 var dis = !f.form_dispensable.checked;
 f.form_allow_multiple.disabled = dis;
 f.form_allow_combining.disabled = dis;
 return true;
}

function validate(f) {
 var saving = f.form_save.clicked ? true : false;
 f.form_save.clicked = false;
  if (saving) {
  if (f.form_name.value.search(/[^\s]/) < 0) {
   alert(<?php echo xlj('Product name is required'); ?>);
   return false;
  }
  if (!f.form_category_id.value) {
   alert(<?php echo xlj('Category is required'); ?>);
   return false;
  }
  // Check if subcategory is required (if subcategory dropdown is visible)
  if (document.getElementById('subcategory_group').style.display !== 'none' && !f.form_product_id.value) {
   alert(<?php echo xlj('Product/Subcategory is required'); ?>);
   return false;
  }
 }
 var deleting = f.form_delete.clicked ? true : false;
 f.form_delete.clicked = false;
 if (deleting) {
  if (!confirm(<?php echo xlj('This will permanently delete all lots of this product. Related reports will be incomplete or incorrect. Are you sure?'); ?>)) {
   return false;
  }
 }
 top.restoreSession();
 return true;
}

function parseInventoryNumber(value) {
 var match = String(value || '').match(/-?\d+(?:\.\d+)?/);
 return match ? parseFloat(match[0]) : 0;
}

function isLiquidInventoryForm() {
 var formField = document.getElementById('form');
 return formField && formField.value === 'ml';
}

</script>

</head>

<body class="body_top">
<?php
// If we are saving, then save and close the window.
// First check for duplicates.
//
if (!empty($_POST['form_save'])) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }

    $drugName = trim($_POST['form_name']);
    if ($drugName === '') {
        $alertmsg = xl('Drug name is required');
    } else {
        $currentFacilityId = getCurrentDrugSessionFacilityId();
        if ($currentFacilityId > 0) {
            $crow = sqlQueryNoLog(
                "SELECT COUNT(DISTINCT d.drug_id) AS count
                   FROM drugs AS d
                   INNER JOIN drug_inventory AS di
                           ON di.drug_id = d.drug_id
                          AND di.facility_id = ?
                  WHERE d.name = ?
                    AND d.form = ?
                    AND d.size = ?
                    AND d.unit = ?
                    AND d.route = ?
                    AND d.drug_id != ?",
                array(
                    $currentFacilityId,
                    trim($_POST['form_name']),
                    trim($_POST['form_form']),
                    trim($_POST['form_size']),
                    trim($_POST['form_unit']),
                    trim($_POST['form_route']),
                    $drug_id
                )
            );
        } else {
            $crow = sqlQueryNoLog(
                "SELECT COUNT(*) AS count FROM drugs WHERE " .
                "name = ? AND " .
                "form = ? AND " .
                "size = ? AND " .
                "unit = ? AND " .
                "route = ? AND " .
                "drug_id != ?",
                array(
                    trim($_POST['form_name']),
                    trim($_POST['form_form']),
                    trim($_POST['form_size']),
                    trim($_POST['form_unit']),
                    trim($_POST['form_route']),
                    $drug_id
                )
            );
        }
        if ($crow['count']) {
            $alertmsg = xl('Cannot add this entry because it already exists!');
        }
    }
}

if ((!empty($_POST['form_save']) || !empty($_POST['form_delete'])) && !$alertmsg) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }

    $new_drug = false;
    // Save form_category_id and form_product_id instead
    $category_id = isset($_POST['form_category_id']) ? intval($_POST['form_category_id']) : null;
    $product_id = isset($_POST['form_product_id']) ? intval($_POST['form_product_id']) : null;
    if ($product_id !== null && $product_id <= 0) {
        $product_id = null;
    }
    // Look up the category_name from the selected category_id using the helper
    require_once $GLOBALS['srcdir'] . "/../library/classes/ProductCategoryManager.class.php";
    $category_name = null;
    if ($category_id) {
        $cat_row = ProductCategoryManager::getCategoryById($category_id);
        if ($cat_row && isset($cat_row['category_name'])) {
            $category_name = $cat_row['category_name'];
        }
    }
    // Ensure both category_id and product_id are set for the insert
    if ($category_id === null) $category_id = null;
    if ($product_id === null) $product_id = null;
    if ($drug_id) {
        if (isset($_POST['form_save']) && $_POST['form_save'] && $drug_id) { // updating an existing drug
            // Process discount fields
            $discount_type = $_POST['form_discount_type'] ?? 'percentage';
            $discount_value = floatval($_POST['form_discount_value'] ?? 0);
            $discount_percent = ($discount_type == 'percentage') ? $discount_value : 0;
            $discount_amount = ($discount_type == 'fixed') ? $discount_value : 0;
            $discount_start_date = !empty($_POST['form_discount_start_date']) ? $_POST['form_discount_start_date'] : null;
            $discount_end_date = !empty($_POST['form_discount_end_date']) ? $_POST['form_discount_end_date'] : null;
            $discount_month = !empty($_POST['form_discount_month']) ? $_POST['form_discount_month'] : null;
            
            sqlStatement(
                "UPDATE drugs SET " .
                "name = ?, " .
                "ndc_number = ?, " .
                "drug_code = ?, " .
                "on_order = ?, " .
                "reorder_point = ?, " .
                "max_level = ?, " .
                "form = ?, " .
                "size = ?, " .
                "unit = ?, " .
                "route = ?, " .
                "vial_quantity = ?, " .
                "cyp_factor = ?, " .
                "related_code = ?, " .
                "dispensable = ?, " .
                "allow_multiple = ?, " .
                "allow_combining = ?, " .
                "active = ?, " .
                "consumable = ?, " .
                "category_id = ?, " .
                "product_id = ?, " .
                "category_name = ?, " .
                "cost_per_unit = ?, " .
                "sell_price = ?, " .
                "discount_type = ?, " .
                "discount_percent = ?, " .
                "discount_amount = ?, " .
                "discount_active = ?, " .
                "discount_start_date = ?, " .
                "discount_end_date = ?, " .
                "discount_month = ?, " .
                "discount_description = ? " .
                "WHERE drug_id = ?",
                array(
                    trim($_POST['form_name']),
                    trim($_POST['form_ndc_number'] ?? ''),
                    trim($_POST['form_drug_code'] ?? ''),
                    trim($_POST['form_on_order'] ?? ''),
                    trim($_POST['form_reorder_point'] ?? ''),
                    trim($_POST['form_max_level'] ?? ''),
                    trim($_POST['form_form'] ?? ''),
                    trim($_POST['form_size'] ?? ''),
                    trim($_POST['form_unit'] ?? ''),
                    trim($_POST['form_route'] ?? ''),
                    floatval($_POST['form_vial_quantity'] ?? 0),
                    trim($_POST['form_cyp_factor'] ?? ''),
                    trim($_POST['form_related_code'] ?? ''),
                    (empty($_POST['form_dispensable'    ]) ? 0 : 1),
                    (empty($_POST['form_allow_multiple' ]) ? 0 : 1),
                    (empty($_POST['form_allow_combining']) ? 0 : 1),
                    (empty($_POST['form_active']) ? 0 : 1),
                    (empty($_POST['form_consumable'     ]) ? 0 : 1),
                    $category_id,
                    $product_id,
                    $category_name,
                    floatval($_POST['form_cost_per_unit'] ?? 0),
                    floatval($_POST['form_sell_price'] ?? 0),
                    $discount_type,
                    $discount_percent,
                    $discount_amount,
                    (empty($_POST['form_discount_active']) ? 0 : 1),
                    $discount_start_date,
                    $discount_end_date,
                    $discount_month,
                    trim($_POST['form_discount_description'] ?? ''),
                    $drug_id
                )
            );

            // Log drug update in audit system
            try {
                $audit_logger = new InventoryAuditLogger();
                
                // Create a single comprehensive audit entry with exact form data
                $audit_data = array(
                    'drug_id' => $drug_id,
                    'inventory_id' => 0, // Drug update, no specific inventory
                    'movement_type' => 'drug_modified',
                    'quantity_change' => 0,
                    'reference_id' => null,
                    'reference_type' => 'drug_modification',
                    'lot_number' => '',
                    'expiration_date' => null,
                    'manufacturer' => '',
                    'vendor_id' => '',
                    'supplier_id' => '',
                    'warehouse_id' => '',
                    'cost' => 0,
                    'notes' => "Drug updated: " . trim($_POST['form_name']),
                    'source_lot' => null,
                    'transaction_type' => null,
                    'action' => 'drug_modified',
                    'drug_name' => trim($_POST['form_name']),
                    'ndc_number' => trim($_POST['form_ndc_number']),
                    'category_id' => $category_id,
                    'product_id' => $product_id
                );
                
                $audit_logger->logComprehensiveActivity($audit_data);
            } catch (Exception $e) {
                error_log("Audit logging failed: " . $e->getMessage());
            }
            sqlStatement("DELETE FROM drug_templates WHERE drug_id = ?", array($drug_id));
        } else { // deleting
            if (AclMain::aclCheckCore('admin', 'super')) {
                // Log drug deletion in audit system before deleting
                try {
                    $audit_logger = new InventoryAuditLogger();
                    
                    // Get drug info before deletion
                    $drug_info = sqlQuery("SELECT name, ndc_number FROM drugs WHERE drug_id = ?", array($drug_id));
                    
                    // Convert ADORecordSet to array if needed
                    if (is_object($drug_info) && method_exists($drug_info, 'fields')) {
                        $drug_info = $drug_info->fields;
                    }
                    
                    // Create a single comprehensive audit entry with exact form data
                    $audit_data = array(
                        'drug_id' => $drug_id,
                        'inventory_id' => 0, // Drug deletion, no specific inventory
                        'movement_type' => 'drug_deleted',
                        'quantity_change' => 0,
                        'reference_id' => null,
                        'reference_type' => 'drug_deletion',
                        'lot_number' => '',
                        'expiration_date' => null,
                        'manufacturer' => '',
                        'vendor_id' => '',
                        'supplier_id' => '',
                        'warehouse_id' => '',
                        'cost' => 0,
                        'notes' => "Drug deleted: " . ($drug_info['name'] ?? 'Unknown'),
                        'source_lot' => null,
                        'transaction_type' => null,
                        'action' => 'drug_deleted',
                        'drug_name' => $drug_info['name'] ?? '',
                        'ndc_number' => $drug_info['ndc_number'] ?? ''
                    );
                    
                    $audit_logger->logComprehensiveActivity($audit_data);
                } catch (Exception $e) {
                    error_log("Audit logging failed: " . $e->getMessage());
                }

                sqlStatement("DELETE FROM drug_inventory WHERE drug_id = ?", array($drug_id));
                sqlStatement("DELETE FROM drug_templates WHERE drug_id = ?", array($drug_id));
                sqlStatement("DELETE FROM drugs WHERE drug_id = ?", array($drug_id));
                sqlStatement("DELETE FROM prices WHERE pr_id = ? AND pr_selector != ''", array($drug_id));
            }
        }
    } elseif (isset($_POST['form_save']) && $_POST['form_save']) { // saving a new drug
        $new_drug = true;
        
        // Process discount fields for new drug
        $discount_type = $_POST['form_discount_type'] ?? 'percentage';
        $discount_value = floatval($_POST['form_discount_value'] ?? 0);
        $discount_percent = ($discount_type == 'percentage') ? $discount_value : 0;
        $discount_amount = ($discount_type == 'fixed') ? $discount_value : 0;
        $discount_start_date = !empty($_POST['form_discount_start_date']) ? $_POST['form_discount_start_date'] : null;
        $discount_end_date = !empty($_POST['form_discount_end_date']) ? $_POST['form_discount_end_date'] : null;
        $discount_month = !empty($_POST['form_discount_month']) ? $_POST['form_discount_month'] : null;
        
        $drug_id = sqlInsert(
            "INSERT INTO drugs ( " .
            "name, ndc_number, drug_code, on_order, reorder_point, max_level, form, " .
            "size, unit, route, vial_quantity, cyp_factor, related_code, " .
            "dispensable, allow_multiple, allow_combining, active, consumable, category_id, product_id, category_name, " .
            "cost_per_unit, sell_price, discount_type, discount_percent, discount_amount, discount_active, discount_start_date, discount_end_date, discount_month, discount_description " .
            ") VALUES ( " .
            "?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? )",
            array(
                trim($_POST['form_name']),
                trim($_POST['form_ndc_number'] ?? ''),
                trim($_POST['form_drug_code'] ?? ''),
                trim($_POST['form_on_order'] ?? ''),
                trim($_POST['form_reorder_point'] ?? ''),
                trim($_POST['form_max_level'] ?? ''),
                trim($_POST['form_form'] ?? ''),
                trim($_POST['form_size'] ?? ''),
                trim($_POST['form_unit'] ?? ''),
                trim($_POST['form_route'] ?? ''),
                floatval($_POST['form_vial_quantity'] ?? 0),
                trim($_POST['form_cyp_factor'] ?? ''),
                trim($_POST['form_related_code'] ?? ''),
                (empty($_POST['form_dispensable'    ]) ? 0 : 1),
                (empty($_POST['form_allow_multiple' ]) ? 0 : 1),
                (empty($_POST['form_allow_combining']) ? 0 : 1),
                (empty($_POST['form_active'         ]) ? 0 : 1),
                (empty($_POST['form_consumable'     ]) ? 0 : 1),
                $category_id,
                $product_id,
                $category_name,
                floatval($_POST['form_cost_per_unit'] ?? 0),
                floatval($_POST['form_sell_price'] ?? 0),
                $discount_type,
                $discount_percent,
                $discount_amount,
                (empty($_POST['form_discount_active']) ? 0 : 1),
                $discount_start_date,
                $discount_end_date,
                $discount_month,
                trim($_POST['form_discount_description'] ?? '')
            )
        );

        // Log new drug creation in audit system
        if ($drug_id) {
            $currentFacilityId = getCurrentDrugSessionFacilityId();
            if (!empty($_POST['form_dispensable'])) {
                ensureDrugFacilityPlaceholderInventory($drug_id, $currentFacilityId);
            }
            try {
                $audit_logger = new InventoryAuditLogger();
                
                // Create a single comprehensive audit entry with exact form data
                $audit_data = array(
                    'drug_id' => $drug_id,
                    'inventory_id' => 0, // New drug, no inventory yet
                    'movement_type' => 'drug_created',
                    'quantity_change' => 0,
                    'reference_id' => null,
                    'reference_type' => 'drug_creation',
                    'lot_number' => '',
                    'expiration_date' => null,
                    'manufacturer' => '',
                    'vendor_id' => '',
                    'supplier_id' => '',
                    'warehouse_id' => '',
                    'cost' => 0,
                    'notes' => "New drug created: " . trim($_POST['form_name']),
                    'source_lot' => null,
                    'transaction_type' => null,
                    'action' => 'drug_created',
                    'drug_name' => trim($_POST['form_name']),
                    'ndc_number' => trim($_POST['form_ndc_number']),
                    'category_id' => $category_id,
                    'product_id' => $product_id
                );
                
                $audit_logger->logComprehensiveActivity($audit_data);
            } catch (Exception $e) {
                error_log("Audit logging failed: " . $e->getMessage());
            }
        }
    }

    if (isset($_POST['form_save']) && $_POST['form_save'] && $drug_id) {
        $tmpl = $_POST['form_tmpl'];
       // If using the simplified drug form, then force the one and only
       // selector name to be the same as the product name.
        if ($GLOBALS['sell_non_drug_products'] == 2) {
            $tmpl["1"]['selector'] = $_POST['form_name'];
        }

        sqlStatement("DELETE FROM prices WHERE pr_id = ? AND pr_selector != ''", array($drug_id));
        for ($lino = 1; isset($tmpl["$lino"]['selector']); ++$lino) {
            $iter = $tmpl["$lino"];
            $selector = trim($iter['selector']);
            if ($selector) {
                $taxrates = "";
                if (!empty($iter['taxrate'])) {
                    foreach ($iter['taxrate'] as $key => $value) {
                        $taxrates .= "$key:";
                    }
                }

                sqlStatement(
                    "INSERT INTO drug_templates ( " .
                    "drug_id, selector, dosage, period, quantity, refills, taxrates, pkgqty " .
                    ") VALUES ( ?, ?, ?, ?, ?, ?, ?, ? )",
                    array(
                        $drug_id,
                        $selector,
                        trim($iter['dosage']),
                        trim($iter['period']),
                        trim($iter['quantity']),
                        trim($iter['refills']),
                        $taxrates,
                        // floatval(trim($iter['pkgqty']))
                        1.0
                    )
                );

                // Add prices for this drug ID and selector.
                foreach ($iter['price'] as $key => $value) {
                    if ($value) {
                         $value = $value + 0;
                         sqlStatement(
                             "INSERT INTO prices ( " .
                             "pr_id, pr_selector, pr_level, pr_price ) VALUES ( " .
                             "?, ?, ?, ? )",
                             array($drug_id, $selector, $key, $value)
                         );
                    }
                } // end foreach price
            } // end if selector is present
        } // end for each selector
       // Save warehouse-specific mins and maxes for this drug.
        sqlStatement("DELETE FROM product_warehouse WHERE pw_drug_id = ?", array($drug_id));
        foreach ($_POST['form_wh_min'] as $whid => $whmin) {
            $whmin = 0 + $whmin;
            $whmax = 0 + $_POST['form_wh_max'][$whid];
            if ($whmin != 0 || $whmax != 0) {
                sqlStatement("INSERT INTO product_warehouse ( " .
                "pw_drug_id, pw_warehouse, pw_min_level, pw_max_level ) VALUES ( " .
                "?, ?, ?, ? )", array($drug_id, $whid, $whmin, $whmax));
            }
        }
    } // end if saving a drug

    $shouldCollectOpeningStock = $new_drug && !empty($_POST['form_dispensable']);

    // Close this window and redisplay the updated list of drugs, or move straight
    // into lot entry for new dispensable products so stock does not appear to
    // "disappear" after only the catalog item was created. When opened from the
    // inventory dialog, route the next step back through the parent dialog helper
    // so the authenticated session context is preserved.
    echo "<script>\n";
    if ($shouldCollectOpeningStock) {
        echo "if (parent && typeof parent.refreshme === 'function') parent.refreshme();\n";
        echo "if (opener && opener.refreshme) opener.refreshme();\n";
        echo "if (parent && typeof parent.doiclick === 'function') {\n";
        echo "  parent.doiclick(" . attr_js($drug_id) . ", 0);\n";
        echo "  if (typeof parent.dlgclose === 'function') {\n";
        echo "    parent.dlgclose();\n";
        echo "  } else if (typeof dlgclose === 'function') {\n";
        echo "    dlgclose();\n";
        echo "  } else {\n";
        echo "    window.close();\n";
        echo "  }\n";
        echo "} else {\n";
        echo "  window.location.href = 'add_edit_lot.php?drug=" . attr_js($drug_id) . "&lot=0';\n";
        echo "}\n";
    } else {
        echo "if (parent && typeof parent.refreshme === 'function') parent.refreshme();\n";
        echo "if (opener && opener.refreshme) opener.refreshme();\n";
        echo "window.close();\n";
    }
    echo "</script>";
    exit();
}

if ($drug_id) {
    $row = sqlQueryNoLog("SELECT * FROM drugs WHERE drug_id = ?", array($drug_id));
    $tres = sqlStatement("SELECT * FROM drug_templates WHERE " .
    "drug_id = ? ORDER BY selector", array($drug_id));
} else {
    $row = array(
    'name' => '',
    'active' => '1',
    'dispensable' => '1',
    'allow_multiple' => '1',
    'allow_combining' => '',
    'consumable' => '0',
    'ndc_number' => '',
    'on_order' => '0',
    'reorder_point' => '0',
    'max_level' => '0',
    'form' => '',
    'size' => '',
    'unit' => '',
    'route' => '',
    'vial_quantity' => '0',
    'cyp_factor' => '',
     'related_code' => '',
     );
}
$liquid_inventory = getLiquidInventoryDefinition($row);
$title = $drug_id ? xl("Update Inventory") : xl("Add Inventory");
?>
<div class="healthcare-form">
    <h3><i class="fas fa-warehouse" style="color: #3498db; font-size: 1.5rem;"></i></h3>
    <form method='post' name='theform' action='add_edit_drug.php?drug=<?php echo attr_url($drug_id); ?>' onsubmit='return validate(this);'>
    <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />

        <div class="form-section">
            <h4><i class="fa fa-info-circle"></i> Basic Information</h4>
            <div style="margin-bottom: 1rem; padding: 0.85rem 1rem; border-radius: 10px; background: #eef6ff; border: 1px solid #cfe2ff; color: #1f4f7a; font-weight: 500;">
                <?php echo xlt('Creating an inventory item adds the product record first. For stocked items, the next step is entering the opening lot and quantity.'); ?>
            </div>
            <div class="form-grid">
    <div class="form-group">
        <label class="font-weight-bold"><?php echo xlt('Name'); ?>:</label>
        <input class="form-control" size="40" name="form_name" maxlength="80" value='<?php echo attr($row['name']) ?>' />
    </div>
                <div class="form-group">
                <label class="font-weight-bold">Category:</label>
                    <select class="form-control" name="form_category_id" id="form_category_id" required onchange="toggleMedicalFields()">
                    <option value="">Select Category</option>
                    <?php
                    require_once $GLOBALS['srcdir'] . "/../library/classes/ProductCategoryManager.class.php";
                    $categories = ProductCategoryManager::getAllCategories();
                    $selected_category_id = $row['category_id'] ?? '';
                        $medical_category_id = '';
                        foreach ($categories as $cat) {
                            $is_medical = isMedicationCategoryNameForDrugForm($cat['category_name'] ?? '');
                            if ($is_medical && !$medical_category_id) $medical_category_id = $cat['category_id'];
                        }
                    foreach ($categories as $cat) {
                            $is_medical = isMedicationCategoryNameForDrugForm($cat['category_name'] ?? '');
                            $selected = '';
                            if ($selected_category_id) {
                                if ($cat['category_id'] == $selected_category_id) $selected = 'selected';
                            } else if ($is_medical) {
                                $selected = 'selected';
                            }
                            echo "<option value='" . attr($cat['category_id']) . "' $selected>" . text($cat['category_name']) . "</option>\n";
                    }
                    ?>
                </select>
            </div>
                <div class="form-group" id="subcategory_group" style="display:none;">
                <label class="font-weight-bold">Product/Subcategory:</label>
                <select class="form-control" name="form_product_id" id="form_product_id">
                    <option value="">Select Product</option>
                </select>
                </div>
                <div class="form-group" id="injection_group" style="display:none;">
                <label class="font-weight-bold">Injection:</label>
                <select class="form-control" id="form_basic_injection">
                    <option value="">Select Injection</option>
                    <option value="Injection" selected>Injection</option>
                </select>
                </div>
        </div>
    </div>

        <div class="form-section medical-fields" id="status-permissions-section">
            <h4><i class="fa fa-cogs"></i> Status & Permissions</h4>
            <div class="form-grid">
                <div class="form-group">
        <label class="font-weight-bold">Status:</label>
                    <div class="checkbox-group">
                        <label>
                            <input type='checkbox' name='form_active' value='1'<?php if ($row['active']) { echo ' checked'; } ?> />
            <?php echo xlt('Active{{Drug}}'); ?>
                        </label>
                        <label>
                            <input type='checkbox' name='form_consumable' value='1'<?php if ($row['consumable']) { echo ' checked'; } ?> />
            <?php echo xlt('Consumable'); ?>
                        </label>
        </div>
    </div>
                <div class="form-group">
        <label class="font-weight-bold"><?php echo xlt('Allow'); ?>:</label>
                    <div class="checkbox-group">
                        <label>
                            <input type='checkbox' name='form_dispensable' value='1' onclick='dispensable_changed();'<?php if ($row['dispensable']) { echo ' checked'; } ?> />
        <?php echo xlt('Inventory'); ?>
                        </label>
                        <label>
                            <input type='checkbox' name='form_allow_multiple' value='1'<?php if ($row['allow_multiple']) { echo ' checked'; } ?> />
        <?php echo xlt('Multiple Lots'); ?>
                        </label>
                        <label>
                            <input type='checkbox' name='form_allow_combining' value='1'<?php if ($row['allow_combining']) { echo ' checked'; } ?> />
        <?php echo xlt('Combining Lots'); ?>
                        </label>
    </div>
    </div>
    </div>
    </div>

        <div class="form-section medical-fields" id="medical-fields">
            <h4><i class="fa fa-pills"></i> Medical Specifications</h4>
            <div class="form-grid">
                <div class="form-group drugsonly">
        <label class="font-weight-bold"><?php echo xlt('Form'); ?>:</label>
                    <?php
                    $selected_form = strtolower(trim((string) ($row['form'] ?? '')));
                    if ($selected_form === 'injection' || $selected_form === 'injection / vial') {
                        $selected_form = 'ml';
                    }
                    ?>
                    <select class="form-control" name="form_form" id="form">
                        <option value=""><?php echo xlt('Select Form'); ?></option>
                        <option value="ml"<?php echo ($selected_form === 'ml') ? ' selected' : ''; ?>><?php echo xlt('ml'); ?></option>
                        <option value="tablet"<?php echo ($selected_form === 'tablet') ? ' selected' : ''; ?>><?php echo xlt('tablet'); ?></option>
                        <option value="units"<?php echo ($selected_form === 'units') ? ' selected' : ''; ?>><?php echo xlt('units'); ?></option>
                    </select>
    </div>
                <div class="form-group drugsonly">
        <label class="font-weight-bold"><?php echo xlt('Size'); ?>:</label>
        <input class="form-control" size="20" name="form_size" id="form_size" maxlength="32" value='<?php echo attr($row['size']) ?>' placeholder="<?php echo attr(xl('30 mL per vial')); ?>" />
    </div>
                <div class="form-group drugsonly">
        <label class="font-weight-bold"><?php echo xlt('Route'); ?>:</label>
                    <select class="form-control" name="form_route" id="route">
                        <option value=""><?php echo xlt('Select Route'); ?></option>
                        <option value="Intramuscular (IM)"<?php echo ($row['route'] == 'Intramuscular (IM)') ? ' selected' : ''; ?>><?php echo xlt('Intramuscular (IM)'); ?></option>
                        <option value="Intramuscular"<?php echo ($row['route'] == 'Intramuscular') ? ' selected' : ''; ?>><?php echo xlt('Intramuscular'); ?></option>
                        <option value="by mouth"<?php echo ($row['route'] == 'by mouth') ? ' selected' : ''; ?>><?php echo xlt('by mouth'); ?></option>
                        <option value="SQ"<?php echo ($row['route'] == 'SQ') ? ' selected' : ''; ?>><?php echo xlt('SQ (Subcutaneously)'); ?></option>
                    </select>
    </div>
                <!--
                <div class="form-group drugsonly">
                    <label class="font-weight-bold"><?php echo xlt('Relate To'); ?>:</label>
                    <input class="form-control w-100" type="text" size="50" name="form_related_code" value='<?php echo attr($row['related_code']) ?>' onclick='sel_related("?target_element=form_related_code")' title='<?php echo xla('Click to select related code'); ?>' data-toggle="tooltip" data-placement="top" readonly />
    </div>
                -->
    </div>
    </div>

        <div class="form-section">
            <h4><i class="fa fa-dollar-sign"></i> Pricing & Discount</h4>
            <div class="form-grid">
                <div class="form-group">
                    <label class="font-weight-bold"><?php echo xlt('Cost per Unit'); ?>:</label>
                    <input class="form-control" type="number" step="0.01" min="0" name="form_cost_per_unit" maxlength="10" 
                           value='<?php echo attr($row['cost_per_unit'] ?? ''); ?>'
                           placeholder="0.00" title='<?php echo xla('Cost per unit'); ?>' />
                </div>
                <div class="form-group">
                    <label class="font-weight-bold"><?php echo xlt('Sell Price'); ?>:</label>
                    <input class="form-control" type="number" step="0.01" min="0" name="form_sell_price" maxlength="10" 
                           value='<?php echo attr($row['sell_price'] ?? ''); ?>'
                           placeholder="0.00" title='<?php echo xla('Selling price per unit'); ?>' />
                </div>
                <div class="form-group">
                    <label class="font-weight-bold"><?php echo xlt('Discount Settings'); ?>:</label>
                    <div class="discount-display">
                        <?php
                        $discount_type = $row['discount_type'] ?? 'percentage';
                        $discount_active = $row['discount_active'] ?? 0;
                        $discount_percent = $row['discount_percent'] ?? 0.00;
                        $discount_amount = $row['discount_amount'] ?? 0.00;
                        $discount_start_date = $row['discount_start_date'] ?? null;
                        $discount_end_date = $row['discount_end_date'] ?? null;
                        $discount_month = $row['discount_month'] ?? null;
                        $discount_description = $row['discount_description'] ?? '';
                        
                        // Determine if discount is currently active
                        $is_currently_active = false;
                        if ($discount_active) {
                            $today = date('Y-m-d');
                            if ($discount_start_date && $discount_end_date) {
                                $is_currently_active = ($today >= $discount_start_date && $today <= $discount_end_date);
                            } elseif ($discount_start_date && !$discount_end_date) {
                                $is_currently_active = ($today >= $discount_start_date);
                            } elseif (!$discount_start_date && $discount_end_date) {
                                $is_currently_active = ($today <= $discount_end_date);
                            } elseif ($discount_month) {
                                $is_currently_active = (date('Y-m') === $discount_month);
                            } else {
                                $is_currently_active = true; // No date restrictions
                            }
                        }
                        
                        // Create discount display
                        if ($discount_type == 'percentage' && $discount_percent > 0) {
                            $discount_display = number_format($discount_percent, 2) . '%';
                        } elseif ($discount_type == 'fixed' && $discount_amount > 0) {
                            $discount_display = '$' . number_format($discount_amount, 2);
                        } else {
                            $discount_display = '0.00%';
                        }
                        
                        // Add status indicator
                        $status_class = '';
                        $status_text = '';
                        if (!$discount_active) {
                            $status_class = 'discount-inactive';
                            $status_text = ' (Inactive)';
                        } elseif (!$is_currently_active) {
                            $status_class = 'discount-expired';
                            $status_text = ' (Expired)';
                        } elseif ($is_currently_active) {
                            $status_class = 'discount-active';
                            $status_text = ' (Active)';
                        }
                        ?>
                        <div class="discount-summary">
                            <span class="discount-value <?php echo $status_class; ?>"><?php echo text($discount_display . $status_text); ?></span>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="openDiscountDialog()" style="margin-left: 10px;">
                                <i class="fa fa-edit"></i> <?php echo xlt('Edit Discount'); ?>
                            </button>
                        </div>
                        
                        <!-- Hidden fields to store discount data -->
                        <input type="hidden" name="form_discount_type" id="form_discount_type" value="<?php echo attr($discount_type); ?>">
                        <input type="hidden" name="form_discount_value" id="form_discount_value" value="<?php echo attr($discount_type == 'percentage' ? $discount_percent : $discount_amount); ?>">
                        <input type="hidden" name="form_discount_active" id="form_discount_active" value="<?php echo $discount_active; ?>">
                        <input type="hidden" name="form_discount_description" id="form_discount_description" value="<?php echo attr($discount_description); ?>">
                        <input type="hidden" name="form_discount_start_date" id="form_discount_start_date" value="<?php echo attr($discount_start_date); ?>">
                        <input type="hidden" name="form_discount_end_date" id="form_discount_end_date" value="<?php echo attr($discount_end_date); ?>">
                        <input type="hidden" name="form_discount_month" id="form_discount_month" value="<?php echo attr($discount_month); ?>">
                    </div>
                </div>
            </div>
        </div>

    <div class="btn-group">
            <button type='submit' class="btn btn-primary btn-save" name='form_save' value='<?php echo  $drug_id ? xla('Update') : xla('Add') ; ?>' onclick='return this.clicked = true;'><?php echo $drug_id ? xlt('Update') : xlt('Add') ; ?></button>
        <?php if (AclMain::aclCheckCore('admin', 'super') && $drug_id) { ?>
            <button class="btn btn-danger" type='submit' name='form_delete' onclick='return this.clicked = true;' value='<?php echo xla('Delete'); ?>'><?php echo xlt('Delete'); ?></button>
        <?php } ?>
        <button type='button' class="btn btn-secondary btn-cancel" onclick='window.close()'><?php echo xlt('Cancel'); ?></button>
    </div>
</form>
</div>

<script>

// Function to toggle medical fields and status/permissions based on category selection
function toggleMedicalFields() {
    var categorySelect = document.getElementById('form_category_id');
    var medicalFields = document.querySelectorAll('.medical-fields');
    var statusPermissions = document.getElementById('status-permissions-section');
    var injectionGroup = document.getElementById('injection_group');
    var selectedOption = categorySelect.options[categorySelect.selectedIndex];
    var selectedCategoryText = selectedOption ? selectedOption.text.toLowerCase() : '';
    var isMedical = selectedCategoryText.includes('medical') || selectedCategoryText.includes('medication');
    if (isMedical) {
        medicalFields.forEach(function(section) {
            section.classList.add('show');
        });
        statusPermissions.classList.add('show');
        if (injectionGroup) {
            injectionGroup.style.display = '';
        }
    } else {
        medicalFields.forEach(function(section) {
            section.classList.remove('show');
            section.style.display = 'none';
        });
        statusPermissions.classList.remove('show');
        if (injectionGroup) {
            injectionGroup.style.display = 'none';
        }
    }

}

// Initialize medical fields and status/permissions visibility on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleMedicalFields();
});

$(function () {
  $('[data-toggle="tooltip"]').tooltip();
});

dispensable_changed();

<?php
if ($alertmsg) {
    echo "alert('" . addslashes($alertmsg) . "');\n";
}
?>

$(document).ready(function() {
    function updateSubcategories() {
        var catId = $('#form_category_id').val();
        var catText = ($('#form_category_id option:selected').text() || '').toLowerCase();
        var isMedical = catText.indexOf('medical') !== -1 || catText.indexOf('medication') !== -1;
        if (!catId) {
            $('#subcategory_group').hide();
            $('#form_product_id').prop('required', false);
            return;
        }
        $.ajax({
            url: '../product_categories/manage_categories.php?ajax=get_products_by_category',
            type: 'GET',
            data: { category_id: catId },
            dataType: 'json',
            success: function(data) {
                var $sub = $('#form_product_id');
                $sub.empty();
                $sub.append('<option value="">Select Product</option>');
                if (isMedical) {
                    $sub.append('<option value="-1">Injection</option>');
                }
                if (data.length > 0) {
                    data.forEach(function(prod) {
                        $sub.append('<option value="' + prod.product_id + '">' + prod.subcategory_name + '</option>');
                    });
                    $('#subcategory_group').show();
                    $sub.prop('required', true);
                } else {
                    if (isMedical) {
                        $('#subcategory_group').show();
                        $sub.prop('required', true);
                    } else {
                        $('#subcategory_group').hide();
                        $sub.prop('required', false);
                    }
                }
            }
        });
    }
    $('#form_category_id').change(updateSubcategories);
    // On page load, if editing, trigger subcategory load
    if ($('#form_category_id').val()) {
        updateSubcategories();
        // After loading subcategories, set the selected product if editing
        setTimeout(function() {
            var selectedProductId = '<?php echo $row['product_id'] ?? ''; ?>';
            if (selectedProductId) {
                $('#form_product_id').val(selectedProductId);
            } else if ((function(selectedText) {
                selectedText = (selectedText || '').toLowerCase();
                return selectedText.indexOf('medical') !== -1 || selectedText.indexOf('medication') !== -1;
            })(($('#form_category_id option:selected').text() || ''))) {
                $('#form_product_id').val('-1');
            }
        }, 500);
    }
    
    // Handle discount type change
    $('#discount_type').change(function() {
        var discountType = $(this).val();
        var discountValue = $('#form_discount_value').val();
        
        // Update placeholder based on discount type
        if (discountType === 'percentage') {
            $('#form_discount_value').attr('placeholder', '0.00%');
            $('#form_discount_value').attr('max', '100');
        } else {
            $('#form_discount_value').attr('placeholder', '0.00');
            $('#form_discount_value').removeAttr('max');
        }
    });
    
    // Trigger change event on page load
    $('#discount_type').trigger('change');

});

// Discount Dialog Functions
function openDiscountDialog() {
    var discountType = document.getElementById('form_discount_type').value || 'percentage';
    var currentDiscount = parseFloat(document.getElementById('form_discount_value').value) || 0;
    var discountActive = document.getElementById('form_discount_active').value || '0';
    var discountStart = document.getElementById('form_discount_start_date').value || '';
    var discountEnd = document.getElementById('form_discount_end_date').value || '';
    var discountMonth = document.getElementById('form_discount_month').value || '';
    var discountDescription = document.getElementById('form_discount_description').value || '';
    
    // Create modal dialog for discount editing
    var modal = document.createElement('div');
    modal.className = 'discount-modal';
    modal.style = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;';
    
    var modalContent = document.createElement('div');
    modalContent.style = 'background: white; padding: 20px; border-radius: 8px; width: 500px; max-width: 90%; max-height: 90%; overflow-y: auto;';
    
    modalContent.innerHTML = `
        <h3 style="margin-top: 0; color: #333;">Edit Discount Settings</h3>
        
        <div style="margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Discount Active:</label>
            <input type="checkbox" id="discount_active" ${discountActive == '1' ? 'checked' : ''} style="margin-right: 8px;">
            <label for="discount_active">Enable discount</label>
        </div>
        
        <div style="margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Discount Type:</label>
            <select id="discount_type" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                <option value="percentage" ${discountType == 'percentage' ? 'selected' : ''}>Percentage (%)</option>
                <option value="fixed" ${discountType == 'fixed' ? 'selected' : ''}>Fixed Amount ($)</option>
            </select>
        </div>
        
        <div style="margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Discount Value:</label>
            <input type="number" id="discount_value" value="${currentDiscount}" min="0" step="0.01" 
                   style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
        </div>
        
        <div style="margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Activation Period:</label>
            <select id="activation_type" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 10px;">
                <option value="none">No date restrictions</option>
                <option value="specific_date">Specific date</option>
                <option value="date_range">Date range</option>
                <option value="month">Specific month</option>
            </select>
            
            <div id="specific_date_div" style="display: none; margin-bottom: 10px;">
                <label>Start Date:</label>
                <input type="date" id="discount_start_date" value="${discountStart}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            </div>
            
            <div id="date_range_div" style="display: none;">
                <div style="margin-bottom: 10px;">
                    <label>Start Date:</label>
                    <input type="date" id="discount_start_date_range" value="${discountStart}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div>
                    <label>End Date:</label>
                    <input type="date" id="discount_end_date" value="${discountEnd}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
            </div>
            
            <div id="month_div" style="display: none;">
                <label>Month (YYYY-MM):</label>
                <input type="month" id="discount_month" value="${discountMonth}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            </div>
        </div>
        
        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Description (Optional):</label>
            <textarea id="discount_description" rows="3" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; resize: vertical;">${discountDescription}</textarea>
        </div>
        
        <div style="text-align: right;">
            <button type="button" onclick="closeDiscountDialog()" style="padding: 8px 16px; margin-right: 10px; border: 1px solid #ddd; background: #f5f5f5; border-radius: 4px; cursor: pointer;">Cancel</button>
            <button type="button" onclick="saveDiscountDialog()" style="padding: 8px 16px; background: #ff9800; color: white; border: none; border-radius: 4px; cursor: pointer;">Save</button>
        </div>
    `;
    
    modal.appendChild(modalContent);
    document.body.appendChild(modal);
    
    // Set initial activation type based on existing data
    var activationType = 'none';
    if (discountStart && discountEnd) activationType = 'date_range';
    else if (discountStart && !discountEnd) activationType = 'specific_date';
    else if (discountMonth) activationType = 'month';
    
    document.getElementById('activation_type').value = activationType;
    updateActivationFields();
    
    // Add event listeners
    document.getElementById('activation_type').addEventListener('change', updateActivationFields);
    document.getElementById('discount_type').addEventListener('change', updateDiscountValueLabel);
}

function closeDiscountDialog() {
    var modal = document.querySelector('.discount-modal');
    if (modal) {
        modal.remove();
    }
}

function updateActivationFields() {
    var activationType = document.getElementById('activation_type').value;
    document.getElementById('specific_date_div').style.display = 'none';
    document.getElementById('date_range_div').style.display = 'none';
    document.getElementById('month_div').style.display = 'none';
    
    if (activationType === 'specific_date') {
        document.getElementById('specific_date_div').style.display = 'block';
    } else if (activationType === 'date_range') {
        document.getElementById('date_range_div').style.display = 'block';
    } else if (activationType === 'month') {
        document.getElementById('month_div').style.display = 'block';
    }
}

function updateDiscountValueLabel() {
    var discountType = document.getElementById('discount_type').value;
    var label = document.querySelector('label[for="discount_value"]');
    if (discountType === 'percentage') {
        label.textContent = 'Discount Value (%):';
    } else {
        label.textContent = 'Discount Value ($):';
    }
}

function saveDiscountDialog() {
    var discountActive = document.getElementById('discount_active').checked ? 1 : 0;
    var discountType = document.getElementById('discount_type').value;
    var discountValue = parseFloat(document.getElementById('discount_value').value) || 0;
    var activationType = document.getElementById('activation_type').value;
    var discountDescription = document.getElementById('discount_description').value;
    
    // Validate discount value
    if (discountType === 'percentage' && (discountValue < 0 || discountValue > 100)) {
        alert('Percentage discount must be between 0 and 100.');
        return;
    }
    if (discountType === 'fixed' && discountValue < 0) {
        alert('Fixed discount amount cannot be negative.');
        return;
    }
    
    // Prepare date fields
    var discountStartDate = null;
    var discountEndDate = null;
    var discountMonth = null;
    
    if (activationType === 'specific_date') {
        discountStartDate = document.getElementById('discount_start_date').value;
    } else if (activationType === 'date_range') {
        discountStartDate = document.getElementById('discount_start_date_range').value;
        discountEndDate = document.getElementById('discount_end_date').value;
    } else if (activationType === 'month') {
        discountMonth = document.getElementById('discount_month').value;
    }
    
    // Update hidden form fields
    document.getElementById('form_discount_type').value = discountType;
    document.getElementById('form_discount_value').value = discountValue;
    document.getElementById('form_discount_active').value = discountActive;
    document.getElementById('form_discount_description').value = discountDescription;
    document.getElementById('form_discount_start_date').value = discountStartDate || '';
    document.getElementById('form_discount_end_date').value = discountEndDate || '';
    document.getElementById('form_discount_month').value = discountMonth || '';
    
    // Update the display
    updateDiscountDisplay();
    
    closeDiscountDialog();
}

function updateDiscountDisplay() {
    var discountType = document.getElementById('form_discount_type').value;
    var discountActive = document.getElementById('form_discount_active').value;
    var discountValue = parseFloat(document.getElementById('form_discount_value').value) || 0;
    var discountStartDate = document.getElementById('form_discount_start_date').value;
    var discountEndDate = document.getElementById('form_discount_end_date').value;
    var discountMonth = document.getElementById('form_discount_month').value;
    
    // Create discount display
    var discountDisplay = '';
    if (discountType === 'percentage' && discountValue > 0) {
        discountDisplay = discountValue.toFixed(2) + '%';
    } else if (discountType === 'fixed' && discountValue > 0) {
        discountDisplay = '$' + discountValue.toFixed(2);
    } else {
        discountDisplay = '0.00%';
    }
    
    // Determine status
    var statusClass = '';
    var statusText = '';
    if (discountActive == '0') {
        statusClass = 'discount-inactive';
        statusText = ' (Inactive)';
    } else {
        // Check if currently active based on dates
        var today = new Date().toISOString().split('T')[0];
        var isCurrentlyActive = false;
        
        if (discountStartDate && discountEndDate) {
            isCurrentlyActive = (today >= discountStartDate && today <= discountEndDate);
        } else if (discountStartDate && !discountEndDate) {
            isCurrentlyActive = (today >= discountStartDate);
        } else if (!discountStartDate && discountEndDate) {
            isCurrentlyActive = (today <= discountEndDate);
        } else if (discountMonth) {
            isCurrentlyActive = (today.substring(0, 7) === discountMonth);
        } else {
            isCurrentlyActive = true; // No date restrictions
        }
        
        if (!isCurrentlyActive) {
            statusClass = 'discount-expired';
            statusText = ' (Expired)';
        } else {
            statusClass = 'discount-active';
            statusText = ' (Active)';
        }
    }
    
    // Update the display
    var discountValueElement = document.querySelector('.discount-value');
    if (discountValueElement) {
        discountValueElement.textContent = discountDisplay + statusText;
        discountValueElement.className = 'discount-value ' + statusClass;
    }
}

</script>

</body>
</html>
