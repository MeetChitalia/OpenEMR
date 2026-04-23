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
require_once(__DIR__ . "/medical_inventory_count_common.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;

// Check authorizations.
$auth_admin = AclMain::aclCheckCore('admin', 'drugs');
$auth_lots  = $auth_admin                             ||
    AclMain::aclCheckCore('inventory', 'lots') ||
    AclMain::aclCheckCore('inventory', 'purchases') ||
    AclMain::aclCheckCore('inventory', 'transfers') ||
    AclMain::aclCheckCore('inventory', 'adjustments') ||
    AclMain::aclCheckCore('inventory', 'consumption') ||
    AclMain::aclCheckCore('inventory', 'destruction');
$auth_anything = $auth_lots                           ||
    AclMain::aclCheckCore('inventory', 'sales') ||
    AclMain::aclCheckCore('inventory', 'reporting');
if (!$auth_anything) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Drug Inventory")]);
    exit;
}
// Note if user is restricted to any facilities and/or warehouses.
$is_user_restricted = isUserRestricted();

function getCurrentDrugInventoryFacilityId(): int
{
    if (!empty($_SESSION['facilityId'])) {
        return (int) $_SESSION['facilityId'];
    }

    if (!empty($_SESSION['facility_id'])) {
        return (int) $_SESSION['facility_id'];
    }

    return 0;
}

// For each sorting option, specify the ORDER BY argument.
//
$ORDERHASH = array(
  'prod' => 'd.name, d.drug_id, di.expiration, di.lot_number',
  'act'  => 'd.active, d.name, d.drug_id, di.expiration, di.lot_number',
  'ndc'  => 'd.ndc_number, d.name, d.drug_id, di.expiration, di.lot_number',
  'con'  => 'd.consumable, d.name, d.drug_id, di.expiration, di.lot_number',
  'form' => 'lof.title, d.name, d.drug_id, di.expiration, di.lot_number',
  'lot'  => 'di.lot_number, d.name, d.drug_id, di.expiration',
  'wh'   => 'lo.title, d.name, d.drug_id, di.expiration, di.lot_number',
  'fac'  => 'f.name, d.name, d.drug_id, di.expiration, di.lot_number',
  'qoh'  => 'di.on_hand, d.name, d.drug_id, di.expiration, di.lot_number',
  'exp'  => 'di.expiration, d.name, d.drug_id, di.lot_number',
);

$selectedFacilityId = getCurrentDrugInventoryFacilityId();
$form_facility = (empty($_REQUEST['form_facility']) ? 0 : (int) $_REQUEST['form_facility']);
if ($selectedFacilityId > 0) {
    $form_facility = $selectedFacilityId;
}
$form_show_empty = empty($_REQUEST['form_show_empty']) ? 0 : 1;
$form_show_inactive = empty($_REQUEST['form_show_inactive']) ? 0 : 1;
$form_consumable = isset($_REQUEST['form_consumable']) ? intval($_REQUEST['form_consumable']) : 0;
$form_category = isset($_REQUEST['form_category']) ? $_REQUEST['form_category'] : '';

// Incoming form_warehouse, if not empty is in the form "warehouse/facility".
// The facility part is an attribute used by JavaScript logic.
$form_warehouse = empty($_REQUEST['form_warehouse']) ? '' : $_REQUEST['form_warehouse'];
$tmp = explode('/', $form_warehouse);
$form_warehouse = $tmp[0];

// Get the order hash array value and key for this request.
$form_orderby = isset($ORDERHASH[$_REQUEST['form_orderby'] ?? '']) ? $_REQUEST['form_orderby'] : 'prod';
$orderby = $ORDERHASH[$form_orderby];

ensureMedicalInventoryCountTable();
$medicalInventorySummary = buildMedicalInventoryCountSummary($form_facility > 0 ? (int) $form_facility : 0);
$medicalInventoryCountMap = $medicalInventorySummary['count_map'] ?? [];
$medicalInventoryTotalItems = (int) ($medicalInventorySummary['total_items'] ?? 0);
$medicalInventoryCountedItems = (int) ($medicalInventorySummary['counted_items'] ?? 0);
$medicalInventoryMissingItems = $medicalInventorySummary['missing_items'] ?? [];
$medicalInventoryMissingCount = count($medicalInventoryMissingItems);
$medicalInventoryAdminEmail = getMedicalInventoryAdminEmail();
$medicalInventoryAdminRecipients = getMedicalInventoryAdminRecipients();
$medicalInventorySelectedRecipient = $medicalInventoryAdminEmail;
if (!empty($_SESSION['authUser'])) {
    foreach ($medicalInventoryAdminRecipients as $recipient) {
        if (
            strcasecmp((string) $_SESSION['authUser'], (string) ($recipient['username'] ?? '')) === 0
        ) {
            $medicalInventorySelectedRecipient = (string) ($recipient['email'] ?? '');
            break;
        }
    }
}
if ($medicalInventorySelectedRecipient === '' && !empty($medicalInventoryAdminRecipients)) {
    $medicalInventorySelectedRecipient = (string) ($medicalInventoryAdminRecipients[0]['email'] ?? '');
}

$binds = array();
$where = "WHERE 1 = 1";
// Allow a zero-QOH placeholder row to keep newly created products visible within the
// selected facility before a real lot is added.
$placeholderInventoryJoinCondition = ($form_facility > 0)
    ? " OR (COALESCE(di.on_hand, 0) = 0
            AND COALESCE(di.lot_number, '') = ''
            AND COALESCE(di.warehouse_id, '') = ''
            AND di.facility_id = " . (int) $form_facility . ")"
    : "";
if ($form_facility) {
    $where .= " AND COALESCE(lo.option_value, di.facility_id) = ?";
    $binds[] = $form_facility;
}
if ($form_warehouse) {
    $where .= " AND di.warehouse_id IS NOT NULL AND di.warehouse_id = ?";
    $binds[] = $form_warehouse;
}
if (!$form_show_inactive) {
    $where .= " AND d.active = 1";
}
if ($form_consumable) {
    if ($form_consumable == 1) {
        $where .= " AND d.consumable = '1'";
    } else {
        $where .= " AND d.consumable != '1'";
    }
}
if ($form_category) {
    $where .= " AND c.category_name = ?";
    $binds[] = $form_category;
}

$totalQohJoin = "LEFT JOIN (
        SELECT di2.drug_id, COALESCE(SUM(di2.on_hand), 0) AS total_qoh
          FROM drug_inventory AS di2
         WHERE di2.destroy_date IS NULL
      GROUP BY di2.drug_id
    ) AS tq ON tq.drug_id = d.drug_id ";

if ($form_facility) {
    $totalQohJoin = "LEFT JOIN (
            SELECT di2.drug_id, COALESCE(SUM(di2.on_hand), 0) AS total_qoh
              FROM drug_inventory AS di2
         LEFT JOIN list_options AS lo2
                ON lo2.list_id = 'warehouse'
               AND lo2.option_id = di2.warehouse_id
               AND lo2.activity = 1
             WHERE di2.destroy_date IS NULL
               AND COALESCE(lo2.option_value, di2.facility_id) = " . (int) $form_facility . "
          GROUP BY di2.drug_id
        ) AS tq ON tq.drug_id = d.drug_id ";
}

// get drugs - Modified to show all products, with lot info only when quantity > 0
$res = sqlStatement(
    "SELECT d.*, " .
    "di.inventory_id, di.lot_number, di.expiration, di.manufacturer, di.on_hand, " .
    "di.warehouse_id, lo.title, lo.option_value AS facid, f.name AS facname, " .
    "d.cost_per_unit, d.sell_price, d.discount_percent, d.discount_amount, d.discount_quantity, d.discount_type, d.discount_active, " .
    "d.discount_start_date, d.discount_end_date, d.discount_month, d.discount_description, " .
    "c.category_name, p.subcategory_name, COALESCE(tq.total_qoh, 0) AS total_qoh " .
    "FROM drugs AS d " .
    "LEFT JOIN categories AS c ON c.category_id = d.category_id " .
    "LEFT JOIN products AS p ON p.product_id = d.product_id " .
    $totalQohJoin .
    "LEFT JOIN drug_inventory AS di ON di.drug_id = d.drug_id " .
    "AND di.destroy_date IS NULL " .
    "AND (" . ($form_show_empty ? "1=1" : "di.on_hand > 0") . $placeholderInventoryJoinCondition . ") " .
    "LEFT JOIN list_options AS lo ON lo.list_id = 'warehouse' AND " .
    "lo.option_id = di.warehouse_id AND lo.activity = 1 " .
    "LEFT JOIN facility AS f ON f.id = COALESCE(lo.option_value, di.facility_id) " .
    "LEFT JOIN list_options AS lof ON lof.list_id = 'drug_form' AND " .
    "lof.option_id = d.form AND lof.activity = 1 " .
    "$where ORDER BY d.active DESC, $orderby",
    $binds
);

function generateEmptyTd($n)
{
    $temp = '';
    while ($n > 0) {
        $temp .= "<td></td>";
        $n--;
    }
    echo $temp;
}
function processData($data)
{
    $isPlaceholderRow = (
        !empty($data['inventory_id']) &&
        (string) ($data['lot_number'] ?? '') === '' &&
        (string) ($data['warehouse_id'] ?? '') === '' &&
        (float) ($data['on_hand'] ?? 0) == 0.0
    );

    $data['inventory_id'] = $isPlaceholderRow ? [] : [$data['inventory_id']];
    $data['lot_number'] = $isPlaceholderRow ? [] : [$data['lot_number']];
    $data['facname'] =  $isPlaceholderRow ? [] : [$data['facname']];
    $data['title'] =  $isPlaceholderRow ? [] : [$data['title']];
    $data['on_hand'] = $isPlaceholderRow ? [] : [$data['on_hand']];
    $data['expiration'] = $isPlaceholderRow ? [] : [$data['expiration']];
    // Note: cost_per_unit, sell_price, and total_qoh are now product-level, not lot-level
    return $data;
}
function mergeData($d1, $d2)
{
    $d1['inventory_id'] = array_merge($d1['inventory_id'], $d2['inventory_id']);
    $d1['lot_number'] = array_merge($d1['lot_number'], $d2['lot_number']);
    $d1['facname'] = array_merge($d1['facname'], $d2['facname']);
    $d1['title'] = array_merge($d1['title'], $d2['title']);
    $d1['on_hand'] = array_merge($d1['on_hand'], $d2['on_hand']);
    $d1['expiration'] = array_merge($d1['expiration'], $d2['expiration']);
    // Note: cost_per_unit and sell_price are now product-level, not lot-level
    return $d1;
}
function mapToTable($row)
{
    global $auth_admin, $auth_lots, $medicalInventoryCountMap;
    $today = date('Y-m-d');
    if ($row) {
        $can_edit_cqoh = $auth_admin || $auth_lots;
        $category_name = trim((string) ($row['category_name'] ?? ''));
        $is_cqoh_editable_category = in_array(strtolower($category_name), ['office', 'products'], true);
        $is_medical_inventory_category = isMedicationInventoryCategoryName($category_name);
        echo " <tr class='detail'>\n";
        $lastid = $row['drug_id'];
        if ($auth_admin) {
            echo "<td title='" . xla('Click to edit') . "' onclick='dodclick(" . attr(addslashes($lastid)) . ")'>" .
            "<a href='' onclick='return false'>" .
            text($row['name']) . "</a></td>\n";
        } else {
            echo "  <td>" . text($row['name']) . "</td>\n";
        }
        echo "  <td class='hide-inventory-col'>" . ($row['active'] ? xlt('Yes') : xlt('No')) . "</td>\n";
        echo "  <td class='hide-inventory-col'>" . ($row['consumable'] ? xlt('Yes') : xlt('No')) . "</td>\n";
        echo "  <td class='hide-inventory-col'>" . text($row['ndc_number']) . "</td>\n";
        echo "  <td class='hide-inventory-col'>" .
        generate_display_field(array('data_type' => '1','list_id' => 'drug_form'), $row['form']) .
        "</td>\n";
        echo "  <td>" . text($row['size']) . "</td>\n";
        echo "  <td title='" . xla('Measurement Units') . "'>" .
        text($row['unit']) .
        "</td>\n";

        // Cost: per product (not per lot) - show same value for all lots
        echo "<td style='vertical-align: top; text-align: center;'>";
        $cost_per_unit = isset($row['cost_per_unit']) ? $row['cost_per_unit'] : 0.00;
        $cost_display = $cost_per_unit > 0 ? '$' . number_format($cost_per_unit, 2) : 'N/A';
        
        if ($auth_admin) {
            // Make cost editable for admin users
            echo "<span class='editable-cost' data-drug-id='" . attr($lastid) . "' 
                      data-current-cost='" . attr($cost_per_unit) . "' 
                      onclick='editCost(this)' 
                      title='" . xla('Click to edit cost per unit') . "'>" . text($cost_display) . "</span>";
        } else {
            echo text($cost_display);
        }
        echo "</td>\n<td style='vertical-align: top; text-align: center;'>";
        // Sell Price: per product (not per lot) - show same value for all lots
        $sell_price = isset($row['sell_price']) ? $row['sell_price'] : 0.00;
        $sell_display = $sell_price > 0 ? '$' . number_format($sell_price, 2) : 'N/A';
        
        if ($auth_admin) {
            // Make sell price editable for admin users
            echo "<span class='editable-sell-price' data-drug-id='" . attr($lastid) . "' 
                      data-current-price='" . attr($sell_price) . "' 
                      onclick='editSellPrice(this)' 
                      title='" . xla('Click to edit sell price') . "'>" . text($sell_display) . "</span>";
        } else {
            echo text($sell_display);
        }
        echo "</td>\n<td style='vertical-align: top; text-align: center;'>";
        // Enhanced Discount: per product (not per lot) - show same value for all lots
        $discount_type = isset($row['discount_type']) ? $row['discount_type'] : 'percentage';
        $discount_active = isset($row['discount_active']) ? $row['discount_active'] : 0;
        $discount_percent = isset($row['discount_percent']) ? $row['discount_percent'] : 0.00;
        $discount_amount = isset($row['discount_amount']) ? $row['discount_amount'] : 0.00;
        $discount_start_date = isset($row['discount_start_date']) ? $row['discount_start_date'] : null;
        $discount_end_date = isset($row['discount_end_date']) ? $row['discount_end_date'] : null;
        $discount_month = isset($row['discount_month']) ? $row['discount_month'] : null;
        $discount_description = isset($row['discount_description']) ? $row['discount_description'] : '';
        
        // Determine discount status (active, scheduled, expired, or inactive)
        $discount_status = 'inactive';
        $status_details = '';
        
        if ($discount_active) {
            $today = date('Y-m-d');
            $current_month = date('Y-m');
            
            // Debug logging removed - system working correctly
            
            if ($discount_start_date && $discount_end_date && $discount_start_date !== '0000-00-00' && $discount_end_date !== '0000-00-00') {
                // Date range - check if current date falls within the range
                $start_date = new DateTime($discount_start_date);
                $end_date = new DateTime($discount_end_date);
                $current_date = new DateTime($today);
                
                if ($current_date >= $start_date && $current_date <= $end_date) {
                    $discount_status = 'active';
                    $status_details = ' (Active)';
                } elseif ($current_date < $start_date) {
                    $discount_status = 'scheduled';
                    $start_date_formatted = $start_date->format('m/d/Y');
                    $end_date_formatted = $end_date->format('m/d/Y');
                    $status_details = " (Scheduled: $start_date_formatted to $end_date_formatted)";
                } else {
                    $discount_status = 'expired';
                    $status_details = ' (Expired)';
                }
            } elseif ($discount_start_date && (!$discount_end_date || $discount_end_date === '0000-00-00') && $discount_start_date !== '0000-00-00') {
                // Start date only - active from start date onwards
                $start_date = new DateTime($discount_start_date);
                $current_date = new DateTime($today);
                
                error_log("Specific Date Check - Start: " . $start_date->format('Y-m-d') . ", Current: " . $current_date->format('Y-m-d'));
                
                if ($current_date >= $start_date) {
                    $discount_status = 'active';
                    $status_details = ' (Active)';
                    error_log("Status: ACTIVE - Current date is on or after start date");
                } else {
                    $discount_status = 'scheduled';
                    $start_date_formatted = $start_date->format('m/d/Y');
                    $status_details = " (Scheduled: from $start_date_formatted)";
                    error_log("Status: SCHEDULED - Current date is before start date");
                }
            } elseif (!$discount_start_date && $discount_end_date && $discount_end_date !== '0000-00-00') {
                // End date only - active until end date
                $end_date = new DateTime($discount_end_date);
                $current_date = new DateTime($today);
                
                error_log("End Date Only Check - End: " . $end_date->format('Y-m-d') . ", Current: " . $current_date->format('Y-m-d'));
                
                if ($current_date <= $end_date) {
                    $discount_status = 'active';
                    $status_details = ' (Active)';
                    error_log("Status: ACTIVE - Current date is on or before end date");
                } else {
                    $discount_status = 'expired';
                    $status_details = ' (Expired)';
                    error_log("Status: EXPIRED - Current date is after end date");
                }
            } elseif ($discount_month) {
                // Specific month - compare YYYY-MM format
                $current_month_obj = new DateTime($current_month . '-01');
                $discount_month_obj = new DateTime($discount_month . '-01');
                
                if ($current_month === $discount_month) {
                    $discount_status = 'active';
                    $status_details = ' (Active)';
                } elseif ($discount_month_obj > $current_month_obj) {
                    $discount_status = 'scheduled';
                    $month_formatted = $discount_month_obj->format('F Y');
                    $status_details = " (Scheduled: $month_formatted)";
                } else {
                    $discount_status = 'expired';
                    $status_details = ' (Expired)';
                }
            } else {
                // No date restrictions - always active
                $discount_status = 'active';
                $status_details = ' (Active)';
                error_log("Status: ACTIVE - No date restrictions");
            }
        }
        
        // Create discount display
        $discount_quantity = isset($row['discount_quantity']) ? intval($row['discount_quantity']) : null;
        if ($discount_type == 'percentage' && $discount_percent > 0) {
            $discount_display = number_format($discount_percent, 2) . '%';
        } elseif ($discount_type == 'fixed' && $discount_amount > 0) {
            $discount_display = '$' . number_format($discount_amount, 2);
        } elseif ($discount_type == 'quantity' && $discount_quantity > 1) {
            $discount_display = 'Buy ' . $discount_quantity . ' Get 1 Free';
        } else {
            $discount_display = '0.00%';
        }
        
        // Add status indicator with appropriate styling
        $status_class = '';
        $status_text = '';
        
        if ($discount_status === 'inactive') {
            $status_class = 'discount-inactive';
            $status_text = ' (Inactive)';
        } elseif ($discount_status === 'scheduled') {
            $status_class = 'discount-scheduled';
            $status_text = $status_details;
            // Add description if available
            if (!empty($discount_description)) {
                $status_text .= ' - ' . $discount_description;
            }
        } elseif ($discount_status === 'expired') {
            $status_class = 'discount-expired';
            $status_text = ' (Expired)';
        } elseif ($discount_status === 'active') {
            $status_class = 'discount-active';
            $status_text = ' (Active)';
        }
        
        if ($auth_admin) {
            // Debug logging removed - system working correctly
            
            // Make discount editable for admin users
            $discount_quantity = isset($row['discount_quantity']) ? $row['discount_quantity'] : null;
            echo "<span class='editable-discount $status_class' data-drug-id='" . attr($lastid) . "' 
                      data-current-discount='" . attr($discount_type == 'percentage' ? $discount_percent : ($discount_type == 'quantity' ? $discount_quantity : $discount_amount)) . "' 
                      data-discount-type='" . attr($discount_type) . "' 
                      data-discount-quantity='" . attr($discount_quantity) . "' 
                      data-discount-active='" . attr($discount_active) . "' 
                      data-discount-start='" . attr($discount_start_date) . "' 
                      data-discount-end='" . attr($discount_end_date) . "' 
                      data-discount-month='" . attr($discount_month) . "' 
                      data-discount-description='" . attr($discount_description) . "' 
                      onclick='editDiscount(this)' 
                      title='" . xla('Click to edit discount settings') . $status_text . "'>" . text($discount_display) . $status_text . "</span>";
        } else {
            echo "<span class='$status_class'>" . text($discount_display) . $status_text . "</span>";
        }
        echo "</td>\n";



        // Category: per product (not per lot) - show same value for all lots
        echo "<td style='vertical-align: top; text-align: center;'>";
        $category_display = !empty($category_name) ? $category_name : 'N/A';
        echo text($category_display);
        echo "</td>\n";

        // Always show the + button at the top of the lot cell
        echo "<td style='position: relative; vertical-align: top;'>";
        echo "<button type='button' onclick='doiclick(" . intval($lastid) . ",0)' class='inventory-add-btn-center' title='" .
            xla('Add New Lot') . "'>+</button>";
        // Now show lots (if any) below the button
        $lot_count = !empty($row['inventory_id'][0]) ? count($row['inventory_id']) : 0;
        for ($i = 0; $i < $lot_count; $i++) {
                if ($auth_lots) {
                echo "<div class='lot-row' style='margin-top: 28px;'>";
                    echo "<span class='lot-value' title='" .
                        xla('Adjustment, Consumption, Return, or Edit') .
                    "' onclick='doiclick(" . intval($lastid) . "," . intval($row['inventory_id'][$i]) . ")'>" .
                        "<a href='' onclick='return false'>" .
                    text($row['lot_number'][$i]) .
                        "</a></span>";
                    echo "</div>";
                } else {
                echo "  <div style='margin-top: 28px;'>" . text($row['lot_number'][$i]) . "</div>\n";
            }
            }
            echo "</td>\n<td class='hide-inventory-col'>";

            foreach ($row['facname'] as $value) {
                $value = $value != null ? $value : "N/A";
                echo "<div >" .  text($value) . "</div>";
            }
            echo "</td>\n<td class='hide-inventory-col'>";

            foreach ($row['title'] as $value) {
                $value = $value != null ? $value : "N/A";
                echo "<div >" .  text($value) . "</div>";
            }
        echo "</td>\n<td class='qoh-cell' style='vertical-align: top;'>";
        $liquidDefinition = getLiquidInventoryDefinition($row);
        $isLiquidInventory = !empty($liquidDefinition['is_liquid']);

        // QOH: align with lots - use exact same structure as lot column
        // The + button is absolutely positioned, so lot numbers start from the top
        // No need for spacer div since button doesn't take up document flow space
        for ($i = 0; $i < $lot_count; $i++) {
            $qoh = isset($row['on_hand'][$i]) ? $row['on_hand'][$i] : '';
            $qoh = $qoh !== null && $qoh !== '' ? formatDrugInventoryDisplayNumber($qoh) : 'N/A';
            if ($isLiquidInventory && $qoh !== 'N/A') {
                $qoh .= ' mg';
            }
            echo "<div class='qoh-row'>" . text($qoh) . "</div>";
        }
        
        // Show total QOH directly under the lot quantities to avoid extra cell gap
        $total_qoh = isset($row['total_qoh']) ? $row['total_qoh'] : 0;
        if ($total_qoh > 0) {
            $total_qoh_display = xlt('Total') . ': ' . formatDrugInventoryDisplayNumber($total_qoh);
            if ($isLiquidInventory) {
                $total_qoh_display = xlt('Total') . ': ' . formatDrugInventoryDisplayNumber($total_qoh) . ' mg';
            }
            echo "<div class='qoh-total'>";
            echo text($total_qoh_display);
            echo "</div>";
        }
        echo "</td>\n<td class='cqoh-cell' style='vertical-align: top; text-align: center;'>";
        $countRecord = $medicalInventoryCountMap[(int) $lastid] ?? null;
        $cqoh_display = formatDrugInventoryDisplayNumber(0);
        if ($is_medical_inventory_category) {
            $countedQoh = (float) ($countRecord['counted_qoh'] ?? 0);
            $differenceQoh = (float) ($countRecord['variance_qoh'] ?? 0);
            $countStatus = (string) ($countRecord['status'] ?? 'not_counted');
            $cqoh_display = $countRecord ? formatDrugInventoryDisplayNumber($countedQoh) : xlt('Count');
            $bubbleClasses = 'editable-cqoh medical-cqoh-bubble';
            if ($countStatus === 'matched') {
                $bubbleClasses .= ' medical-cqoh-match';
            } elseif ($countStatus === 'mismatch') {
                $bubbleClasses .= ' medical-cqoh-mismatch';
            } else {
                $bubbleClasses .= ' medical-cqoh-pending';
            }

            if ($can_edit_cqoh) {
                echo "<span class='" . attr($bubbleClasses) . "' data-drug-id='" . attr($lastid) . "' " .
                    "data-current-cqoh='" . attr($countedQoh) . "' " .
                    "onclick='editCqoh(this)' title='" . xla('Click to record counted quantity for this Medication item') . "'>" .
                    text($cqoh_display) . "</span>";
            } else {
                echo "<span class='" . attr($bubbleClasses) . "'>" . text($cqoh_display) . "</span>";
            }

            echo "<div class='medical-cqoh-difference'>";
            if ($countRecord) {
                if ($countStatus === 'matched') {
                    echo "<span class='difference-match'>" . xlt('Matched') . "</span>";
                } else {
                    $differenceLabel = $differenceQoh > 0 ? '+' . formatDrugInventoryDisplayNumber($differenceQoh) : formatDrugInventoryDisplayNumber($differenceQoh);
                    echo "<span class='difference-mismatch'>" . xlt('Diff') . ': ' . text($differenceLabel) . "</span>";
                }
            } else {
                echo "<span class='difference-pending'>" . xlt('Not counted') . "</span>";
            }
            echo "</div>";
        } elseif ($is_cqoh_editable_category) {
            if ($can_edit_cqoh) {
                echo "<span class='editable-cqoh' data-drug-id='" . attr($lastid) . "' " .
                    "data-current-cqoh='" . attr(0) . "' " .
                    "onclick='editCqoh(this)' title='" . xla('Click to update current quantity on hand') . "'>" .
                    text($cqoh_display) . "</span>";
            } else {
                echo text($cqoh_display);
            }
        } else {
            echo "<span class='cqoh-not-applicable'>" . xlt('N/A') . "</span>";
        }
        echo "</td>\n<td style='vertical-align: top;'>";
        // Expiration: align with lots - use exact same structure as lot column
        for ($i = 0; $i < $lot_count; $i++) {
            $exp_value = isset($row['expiration'][$i]) ? $row['expiration'][$i] : '';
                // Make the expiration date red if expired.
            $expired = !empty($exp_value) && strcmp($exp_value, $today) <= 0;
            // Format as MM/DD/YYYY
            if (!empty($exp_value) && $exp_value !== '0000-00-00') {
                $dt = DateTime::createFromFormat('Y-m-d', $exp_value);
                $display_exp = $dt ? $dt->format('m/d/Y') : $exp_value;
            } else {
                $display_exp = xl('N/A');
            }
            echo "<div style='margin-top: 28px; text-align: center;" . ($expired ? " color:red" : "") . "'>" . text($display_exp) . "</div>";
        }
        echo "</td>\n";
        
        // Defective column: align with lots - use exact same structure as lot column
        echo "<td style='vertical-align: top;'>";
        for ($i = 0; $i < $lot_count; $i++) {
            $inventory_id = isset($row['inventory_id'][$i]) ? $row['inventory_id'][$i] : 0;
            $lot_number = isset($row['lot_number'][$i]) ? $row['lot_number'][$i] : '';
            $qoh = isset($row['on_hand'][$i]) ? $row['on_hand'][$i] : 0;
            
                            if ($qoh > 0 && $auth_lots) {
                    echo "<div style='margin-top: 28px; text-align: center;'>";
                    echo "<button type='button' id='defective-btn-" . $inventory_id . "' onclick='reportDefectiveMedicine(" . $lastid . ", " . $inventory_id . ", \"" . addslashes($lot_number) . "\", " . $qoh . ")' 
                              class='defective-btn' title='" . xla('Report Defective Medicine') . "' 
                              style='background: #f8f9fa; color: #495057; border: 1px solid #dee2e6; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: 600; transition: all 0.3s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.1);'>";
                    echo "<i class='fas fa-exclamation-triangle'></i> Report";
                    echo "</button>";
                    echo "</div>";
                } else {
                    echo "<div style='margin-top: 28px; text-align: center; color: #6c757d; font-size: 12px;'>";
                    echo $qoh > 0 ? "—" : "N/A";
                    echo "</div>";
                }
        }
        echo "</td>\n";
    }
}
?>
<html>

<head>

<title><?php echo xlt('Drug Inventory'); ?></title>

<style>
a, a:visited, a:hover {
  color: var(--primary);
}
#mymaintable thead .sorting::before,
#mymaintable thead .sorting_asc::before,
#mymaintable thead .sorting_asc::after,
#mymaintable thead .sorting_desc::before,
#mymaintable thead .sorting_desc::after,
#mymaintable thead .sorting::after {
  display: none;
}

.dataTables_wrapper .dataTables_paginate .paginate_button {
  padding: 0 !important;
  margin: 0 !important;
  border: 0 !important;
}

.paginate_button:hover {
  background: transparent !important;
}

.hide-inventory-col { display: none !important; }

/* Editable cost styles */
.editable-cost {
  cursor: pointer;
  padding: 2px 4px;
  border-radius: 3px;
  transition: background-color 0.2s;
}

.editable-cost:hover {
  background-color: #e8f5e8;
  border: 1px solid #4caf50;
}

.cost-input {
  font-size: inherit;
  font-family: inherit;
}

.cost-input:focus {
  outline: none;
  border-color: #4caf50 !important;
  box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.25);
}

/* Editable sell price styles */
.editable-sell-price {
  cursor: pointer;
  padding: 2px 4px;
  border-radius: 3px;
  transition: background-color 0.2s;
}

.editable-sell-price:hover {
  background-color: #e3f2fd;
  border: 1px solid #2196f3;
}

.editable-cqoh {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 72px;
  padding: 6px 12px;
  border: 1px solid #b8d7ff;
  border-radius: 999px;
  background: #eef6ff;
  color: #0f5eb8;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.2s ease;
}

.editable-cqoh:hover {
  background: #dfeeff;
  border-color: #7eb4ff;
  transform: translateY(-1px);
  box-shadow: 0 2px 6px rgba(15, 94, 184, 0.18);
}

.medical-cqoh-bubble {
  min-width: 96px;
}

body:not(.medical-counting-mode) .medical-cqoh-bubble {
  cursor: not-allowed;
  opacity: 0.78;
}

body:not(.medical-counting-mode) .medical-cqoh-bubble:hover {
  transform: none;
  box-shadow: none;
}

.medical-cqoh-match {
  background: #ecfdf3;
  border-color: #86efac;
  color: #166534;
}

.medical-cqoh-match:hover {
  background: #dcfce7;
  border-color: #4ade80;
  box-shadow: 0 2px 8px rgba(22, 101, 52, 0.16);
}

.medical-cqoh-mismatch {
  background: #fff1f2;
  border-color: #fda4af;
  color: #be123c;
}

.medical-cqoh-mismatch:hover {
  background: #ffe4e6;
  border-color: #fb7185;
  box-shadow: 0 2px 8px rgba(190, 18, 60, 0.16);
}

.medical-cqoh-pending {
  background: #eff6ff;
  border-color: #93c5fd;
  color: #1d4ed8;
}

.medical-cqoh-pending:hover {
  background: #dbeafe;
  border-color: #60a5fa;
}

.medical-cqoh-difference {
  margin-top: 6px;
  font-size: 12px;
  font-weight: 600;
  text-align: center;
  white-space: normal;
}

.difference-match {
  color: #15803d;
}

.difference-mismatch {
  color: #be123c;
}

.difference-pending {
  color: #2563eb;
}

.cqoh-input {
  width: 90px;
  text-align: center;
  border: 1px solid #007bff;
  border-radius: 999px;
  padding: 5px 10px;
  font-weight: 700;
}

.cqoh-input:focus {
  outline: none;
  border-color: #2196f3 !important;
  box-shadow: 0 0 0 2px rgba(33, 150, 243, 0.25);
}

.cqoh-not-applicable {
  color: #8a94a6;
  font-style: italic;
}

.sell-price-input {
  font-size: inherit;
  font-family: inherit;
}

.sell-price-input:focus {
  outline: none;
  border-color: #2196f3 !important;
  box-shadow: 0 0 0 2px rgba(33, 150, 243, 0.25);
}

/* Editable discount styles */
.editable-discount {
  cursor: pointer;
  padding: 2px 4px;
  border-radius: 3px;
  transition: background-color 0.2s;
}

.editable-discount:hover {
  background-color: #fff3e0;
  border: 1px solid #ff9800;
}

.discount-input {
  font-size: inherit;
  font-family: inherit;
}

.discount-input:focus {
  outline: none;
  border-color: #ff9800 !important;
  box-shadow: 0 0 0 2px rgba(255, 152, 0, 0.25);
}

/* Discount status styles */
.discount-active {
  color: #2e7d32 !important;
  font-weight: 600;
}

.discount-inactive {
  color: #666 !important;
  font-style: italic;
}

.discount-expired {
  color: #d32f2f !important;
  font-style: italic;
}

.discount-scheduled {
  color: #1976d2 !important;
  font-weight: 600;
  font-style: italic;
}
#mymaintable {
  width: 100% !important;
  margin-left: 0 !important;
  margin-right: 0 !important;
  border-collapse: collapse !important;
  table-layout: fixed !important;
}
#mymaintable th, #mymaintable td {
  padding: 8px 4px !important;
  border: 1px solid #dee2e6 !important;
  overflow: hidden !important;
  text-overflow: ellipsis !important;
  white-space: nowrap !important;
  vertical-align: middle !important;
}

/* Column width optimizations */
#mymaintable th:nth-child(1), #mymaintable td:nth-child(1) { /* Name */
  width: 18% !important;
  min-width: 120px !important;
  white-space: normal !important;
}
#mymaintable th:nth-child(2), #mymaintable td:nth-child(2) { /* Active */
  width: 4% !important;
  min-width: 40px !important;
}
#mymaintable th:nth-child(3), #mymaintable td:nth-child(3) { /* Consumable */
  width: 4% !important;
  min-width: 40px !important;
}
#mymaintable th:nth-child(4), #mymaintable td:nth-child(4) { /* NDC */
  width: 7% !important;
  min-width: 60px !important;
}
#mymaintable th:nth-child(5), #mymaintable td:nth-child(5) { /* Form */
  width: 5% !important;
  min-width: 50px !important;
}
#mymaintable th:nth-child(6), #mymaintable td:nth-child(6) { /* Size */
  width: 5% !important;
  min-width: 50px !important;
}
#mymaintable th:nth-child(7), #mymaintable td:nth-child(7) { /* Unit */
  width: 5% !important;
  min-width: 50px !important;
}
#mymaintable th:nth-child(8), #mymaintable td:nth-child(8) { /* Cost */
  width: 7% !important;
  min-width: 70px !important;
  text-align: center !important;
}
#mymaintable th:nth-child(9), #mymaintable td:nth-child(9) { /* Sell Price */
  width: 7% !important;
  min-width: 70px !important;
  text-align: center !important;
}
#mymaintable th:nth-child(10), #mymaintable td:nth-child(10) { /* Discount */
  width: 8% !important;
  min-width: 80px !important;
  text-align: center !important;
}

#mymaintable th:nth-child(12), #mymaintable td:nth-child(12) { /* Category */
  width: 8% !important;
  min-width: 80px !important;
  text-align: center !important;
}
#mymaintable th:nth-child(13), #mymaintable td:nth-child(13) { /* Lot */
  width: 12% !important;
  min-width: 100px !important;
  white-space: normal !important;
}
#mymaintable th:nth-child(14), #mymaintable td:nth-child(14) { /* Facility */
  width: 8% !important;
  min-width: 70px !important;
}
#mymaintable th:nth-child(15), #mymaintable td:nth-child(15) { /* Warehouse */
  width: 8% !important;
  min-width: 70px !important;
}
#mymaintable th:nth-child(16), #mymaintable td:nth-child(16) { /* QOH */
  width: 8% !important;
  min-width: 60px !important;
  text-align: center !important;
}
#mymaintable td.qoh-cell {
  text-align: left !important;
}
.qoh-row {
  margin-top: 28px;
  padding-left: 14px;
  text-align: left;
  font-variant-numeric: tabular-nums;
}
.qoh-total {
  margin-top: 6px;
  padding-left: 14px;
  text-align: left;
  font-weight: bold;
  color: #495057;
  font-size: 12px;
  line-height: 1.2;
  font-variant-numeric: tabular-nums;
}
#mymaintable th:nth-child(17), #mymaintable td:nth-child(17) { /* CQOH */
  width: 7% !important;
  min-width: 70px !important;
  text-align: center !important;
}
#mymaintable th:nth-child(18), #mymaintable td:nth-child(18) { /* Expires */
  width: 8% !important;
  min-width: 70px !important;
  text-align: center !important;
}

/* Alternating row colors for better readability */
#mymaintable tbody tr:nth-child(even) {
  background-color: #f8f9fa !important;
}

#mymaintable tbody tr:nth-child(odd) {
  background-color: #ffffff !important;
}

/* Responsive adjustments for smaller screens */
@media (max-width: 1200px) {
  #mymaintable th:nth-child(1), #mymaintable td:nth-child(1) { /* Name */
    width: 20% !important;
  }
  #mymaintable th:nth-child(12), #mymaintable td:nth-child(12) { /* Lot */
    width: 15% !important;
  }
  #mymaintable th:nth-child(16), #mymaintable td:nth-child(16) { /* QOH */
    width: 10% !important;
  }
  #mymaintable th:nth-child(17), #mymaintable td:nth-child(17) { /* CQOH */
    width: 10% !important;
  }
}

/* Table container improvements for better screen fit */
#mymaintable {
  max-width: 100% !important;
  overflow-x: auto !important;
  table-layout: auto !important;
}

#mymaintable td, #mymaintable th {
  white-space: nowrap !important;
  min-width: auto !important;
}

/* Defective form improvements for better screen fit */
[id^="defective-form-"] {
  max-width: 100% !important;
  overflow-x: auto !important;
  word-wrap: break-word !important;
}

[id^="defective-form-"] input,
[id^="defective-form-"] select {
  max-width: 100% !important;
  box-sizing: border-box !important;
}

/* Ensure table fits screen width */
.drug-inventory-container {
  max-width: 100% !important;
  overflow-x: auto !important;
  padding: 0 10px !important;
}

@media (max-width: 768px) {
  #mymaintable {
    font-size: 0.9rem !important;
  }
  #mymaintable th, #mymaintable td {
    padding-left: 4px !important;
    padding-right: 4px !important;
}
}

.filter-bar {
  background: #f5fafd;
  border-radius: 8px;
  padding: 18px;
  margin-bottom: 18px;
  box-shadow: 0 2px 8px rgba(33, 150, 243, 0.06);
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 18px;
}
.filter-bar .header-title {
  font-size: 1.25rem;
  font-weight: 700;
  color: #205081;
  letter-spacing: 0.5px;
  flex-shrink: 0;
}
.filter-bar .filter-fields {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 18px;
  margin-left: auto;
  justify-content: flex-end;
}
.filter-bar .search-group {
  flex: 0 1 420px;
  min-width: 300px;
  margin-left: auto;
}
.filter-bar .search-shell {
  position: relative;
  width: 100%;
}
.filter-bar .search-icon {
  position: absolute;
  left: 14px;
  top: 50%;
  transform: translateY(-50%);
  color: #6c7a89;
  font-size: 15px;
  pointer-events: none;
}
.filter-bar .search-group input {
  width: 100%;
  min-height: 40px;
  padding-left: 38px;
  padding-right: 14px;
  border-radius: 10px;
  border: 1px solid #cfe0f5;
  background: #fff;
  box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04);
}
.filter-bar .search-group input:focus {
  border-color: #5b9cff;
  box-shadow: 0 0 0 3px rgba(91, 156, 255, 0.16);
}
.filter-bar .form-group {
  margin-bottom: 0;
  display: flex;
  align-items: center;
}
.filter-bar .btn {
  margin-left: 8px;
}
.filter-bar label {
  font-weight: 600;
  color: #1976d2;
  margin-right: 8px;
  margin-bottom: 0;
}
.filter-bar input[type="checkbox"] {
  margin-right: 6px;
}
.inventory-toolbar-actions {
  display: flex;
  align-items: center;
  gap: 12px;
}
.inventory-icon-divider {
  width: 1px;
  height: 28px;
  background: #d7dee8;
  display: inline-block;
}
.inventory-icon-btn {
  width: 42px;
  height: 42px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #5f6b7a;
  background: #f8fafc;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  text-decoration: none;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
  transition: all 0.15s ease;
}
.inventory-icon-btn:hover,
.inventory-icon-btn:focus-visible {
  color: #205081;
  background: #eef5ff;
  border-color: #c7dcff;
  text-decoration: none;
  box-shadow: 0 3px 8px rgba(15, 23, 42, 0.10);
}

.inventory-icon-btn:focus {
  outline: none;
}

@media (max-width: 1200px) {
  .filter-bar .header-title {
    width: 100%;
  }

  .filter-bar .search-group {
    flex: 1 1 100%;
    min-width: 0;
    margin-left: 0;
  }

  .filter-bar .filter-fields {
    width: 100%;
    margin-left: 0;
    justify-content: flex-start;
  }
}

/* Inventory + button styling */
.inventory-add-btn {
  padding: 2px 6px !important;
  margin-left: 8px !important;
  border-radius: 3px !important;
  background: #2196F3 !important;
  color: white !important;
  border: none !important;
  font-size: 12px !important;
  font-weight: bold !important;
  cursor: pointer !important;
  min-width: 20px !important;
  transition: background-color 0.2s ease !important;
}

.inventory-add-btn:hover {
  background: #1976D2 !important;
  transform: scale(1.05) !important;
}

.inventory-add-btn:active {
  background: #0D47A1 !important;
  transform: scale(0.95) !important;
}

/* Lot column layout */
.lot-row {
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
  margin-bottom: 2px !important;
}

.lot-value {
  flex: 1 !important;
  margin-right: 8px !important;
  cursor: pointer !important;
}

.lot-value a {
  color: #2196F3 !important;
  text-decoration: none !important;
}

.lot-value a:hover {
  text-decoration: underline !important;
  color: #1976D2 !important;
}

/* Centered + button styling */
.inventory-add-btn-center {
  position: absolute !important;
  right: 8px !important;
  top: 50% !important;
  transform: translateY(-50%) !important;
  padding: 4px 8px !important;
  border-radius: 3px !important;
  background: #2196F3 !important;
  color: white !important;
  border: none !important;
  font-size: 12px !important;
  font-weight: bold !important;
  cursor: pointer !important;
  min-width: 24px !important;
  transition: background-color 0.2s ease !important;
  z-index: 10 !important;
}

.inventory-add-btn-center:hover {
  background: #1976D2 !important;
  transform: translateY(-50%) scale(1.05) !important;
}

.inventory-add-btn-center:active {
  background: #0D47A1 !important;
  transform: translateY(-50%) scale(0.95) !important;
}

/* Defective button styling */
.defective-btn {
  transition: all 0.3s ease !important;
}

.defective-btn:hover {
  background: #e9ecef !important;
  transform: scale(1.05) !important;
  box-shadow: 0 2px 8px rgba(0,0,0,0.15) !important;
  border-color: #adb5bd !important;
}

.defective-btn:active {
  background: #dee2e6 !important;
  transform: scale(0.95) !important;
}

/* Action buttons bar styling */
.action-buttons-bar {
  background: #f8f9fa;
  border-radius: 8px;
  padding: 15px;
  margin-bottom: 15px;
  text-align: center;
  box-shadow: 0 2px 4px rgba(0,0,0,0.05);
  border: 1px solid #e9ecef;
}

.action-buttons-bar .btn {
  margin: 0 8px;
  padding: 8px 16px;
  font-weight: 600;
  border-radius: 6px;
  transition: all 0.2s ease;
}

.action-buttons-bar .btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.action-buttons-bar .btn-primary {
  background: #007bff;
  border-color: #007bff;
}

.action-buttons-bar .btn-primary:hover {
  background: #0056b3;
  border-color: #0056b3;
}

.action-buttons-bar .btn-secondary {
  background: #6c757d;
  border-color: #6c757d;
}

.action-buttons-bar .btn-secondary:hover {
  background: #545b62;
  border-color: #545b62;
}

.medical-count-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 12px;
  margin-top: 14px;
}

.medical-count-summary {
  display: inline-flex;
  align-items: center;
  gap: 12px;
  padding: 10px 16px;
  background: linear-gradient(135deg, #eff6ff 0%, #f8fbff 100%);
  border: 1px solid #cfe0ff;
  border-radius: 999px;
  color: #1e3a8a;
  font-weight: 600;
}

.medical-count-summary .summary-label {
  color: #334155;
  font-weight: 700;
}

.medical-count-summary .summary-value {
  color: #0f5eb8;
}

.medical-count-summary .summary-alert {
  color: #be123c;
}

.medical-count-summary .summary-ok {
  color: #15803d;
}

.medical-count-toggle {
  min-width: 170px;
  background: #007bff;
  border-color: #007bff;
  color: #ffffff;
}

.medical-count-toggle:hover {
  background: #0056b3;
  border-color: #0056b3;
}

.medical-count-toggle.is-active {
  background: #007bff;
  border-color: #007bff;
}

.medical-count-btn {
  min-width: 240px;
}

.medical-recipient-picker {
  min-width: 260px;
  padding: 10px 14px;
  border: 1px solid #cfe0ff;
  border-radius: 12px;
  background: #fff;
  color: #1f2937;
  font-weight: 500;
}

.medical-recipient-picker:focus {
  outline: none;
  border-color: #5b9cff;
  box-shadow: 0 0 0 3px rgba(91, 156, 255, 0.16);
}

.medical-count-btn[disabled] {
  cursor: not-allowed;
  opacity: 0.65;
  box-shadow: none;
}

.inventory-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  display: none;
  align-items: center;
  justify-content: center;
  padding: 20px;
  z-index: 10020;
}

.inventory-modal-overlay.is-visible {
  display: flex;
}

.inventory-modal-card {
  width: min(640px, 100%);
  max-height: 80vh;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  background: #ffffff;
  border-radius: 16px;
  box-shadow: 0 18px 48px rgba(15, 23, 42, 0.25);
  border: 1px solid #dbe7f5;
}

.inventory-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 18px 22px 14px;
  border-bottom: 1px solid #e5edf7;
}

.inventory-modal-header h3 {
  margin: 0;
  font-size: 22px;
  color: #1f2937;
}

.inventory-modal-close {
  width: 36px;
  height: 36px;
  border: 0;
  border-radius: 50%;
  background: #f3f7fb;
  color: #475569;
  font-size: 20px;
  cursor: pointer;
}

.inventory-modal-body {
  padding: 18px 22px;
  overflow-y: auto;
  color: #334155;
}

.inventory-modal-body p {
  margin-bottom: 12px;
}

.inventory-missing-list {
  margin: 0;
  padding-left: 20px;
}

.inventory-missing-list li {
  margin-bottom: 8px;
}

.inventory-modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding: 14px 22px 20px;
  border-top: 1px solid #e5edf7;
}

body.medical-counting-mode .medical-count-summary {
  border-color: #86b7ff;
  box-shadow: 0 0 0 4px rgba(91, 156, 255, 0.12);
}

body.medical-counting-mode .medical-cqoh-bubble {
  animation: medicalCountPulse 1.5s ease-in-out infinite;
  box-shadow: 0 0 0 5px rgba(91, 156, 255, 0.12);
}

body.medical-counting-mode .medical-cqoh-pending {
  border-color: #2563eb;
  background: #dbeafe;
}

body.medical-counting-mode .medical-cqoh-bubble.is-count-target {
  box-shadow: 0 0 0 6px rgba(34, 197, 94, 0.22), 0 10px 22px rgba(15, 23, 42, 0.14);
  transform: translateY(-1px) scale(1.03);
}

@keyframes medicalCountPulse {
  0% {
    box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.18);
  }
  70% {
    box-shadow: 0 0 0 10px rgba(59, 130, 246, 0);
  }
  100% {
    box-shadow: 0 0 0 0 rgba(59, 130, 246, 0);
  }
}

.dataTables_wrapper {
  padding-left: 0 !important;
  padding-right: 0 !important;
}

#mymaintable thead th {
  background: #e3f2fd !important;
  color: #1565c0 !important;
  font-size: 1.1rem;
  font-weight: 700;
  border-bottom: 2px solid #90caf9 !important;
  vertical-align: middle;
  text-align: center;
}

#mymaintable thead th .header-icon {
  margin-right: 6px;
  font-size: 1.1em;
  vertical-align: middle;
}

#mymaintable tr.detail td {
  border-top: 1px solid #e3f2fd !important;
  vertical-align: middle;
}

/* Responsive adjustments for smaller screens */
@media (max-width: 1200px) {
  #mymaintable th:nth-child(1), #mymaintable td:nth-child(1) { /* Name */
    width: 20% !important;
  }
  #mymaintable th:nth-child(9), #mymaintable td:nth-child(9) { /* Lot */
    width: 15% !important;
  }
  #mymaintable th:nth-child(12), #mymaintable td:nth-child(12) { /* QOH */
    width: 10% !important;
  }
}

@media (max-width: 768px) {
  #mymaintable {
    font-size: 0.9rem !important;
  }
  #mymaintable th, #mymaintable td {
    padding-left: 4px !important;
    padding-right: 4px !important;
  }
}
</style>

<?php Header::setupHeader(['datatables', 'datatables-dt', 'datatables-bs', 'report-helper', 'dialog']); ?>
<script type="text/javascript" src="<?php echo $GLOBALS['web_root']; ?>/library/dialog.js"></script>

<script>

// callback from add_edit_drug.php or add_edit_drug_inventory.php:
function refreshme() {
 // Avoid reload() to prevent POST resubmission warnings, but force a fresh GET.
 var currentUrl = new URL(window.location.href);
 currentUrl.searchParams.set('_inventory_refresh', Date.now().toString());
 window.location.replace(currentUrl.toString());
}

window.addEventListener('storage', function (event) {
 if (event.key === 'openemr_drug_inventory_refresh' && event.newValue) {
  refreshme();
 }
});

// Process click on drug title.
function dodclick(id) {
 dlgopen('add_edit_drug.php?drug=' + id, '_blank', 900, 600);
}

// Process click on drug QOO or lot.
function doiclick(id, lot) {
 console.log('Opening popup with drug_id:', id, 'lot_id:', lot);
 console.log('Current location:', window.location.href);
 console.log('Current pathname:', window.location.pathname);
 
 var url = window.location.pathname.replace('drug_inventory.php', 'add_edit_lot.php') + '?drug=' + id + '&lot=' + lot;
 console.log('Constructed URL:', url);
 
 // Try a simpler approach - use relative path
 var simpleUrl = 'add_edit_lot.php?drug=' + id + '&lot=' + lot;
 console.log('Simple URL:', simpleUrl);
 
 // Try both approaches
 try {
   dlgopen(simpleUrl, '_blank', 600, 475);
   console.log('dlgopen called successfully');
 } catch (error) {
   console.error('Error calling dlgopen:', error);
   // Fallback to window.open if dlgopen fails
   console.log('Trying fallback with window.open...');
   window.open(simpleUrl, '_blank', 'width=600,height=475');
 }
}

// Enable/disable warehouse options depending on current facility.
function facchanged() {
    var f = document.forms[0];
    var facid = f.form_facility.value;
    var theopts = f.form_warehouse.options;
    for (var i = 1; i < theopts.length; ++i) {
        var tmp = theopts[i].value.split('/');
        var dis = facid && (tmp.length < 2 || tmp[1] != facid);
        theopts[i].disabled = dis;
        if (dis) {
            theopts[i].selected = false;
        }
    }
}

var inventoryTableInstance = null;

function filterInventoryRows(searchValue) {
  var normalized = (searchValue || '').toLowerCase().trim();
  var rows = document.querySelectorAll('#mymaintable tbody tr');

  rows.forEach(function(row) {
    var rowText = (row.textContent || '').toLowerCase();
    var matches = normalized === '' || normalized.length < 3 || rowText.indexOf(normalized) !== -1;
    row.style.display = matches ? '' : 'none';
  });
}

function bindInventorySearch() {
  var searchInput = document.getElementById('inventory_search');
  if (!searchInput || searchInput.dataset.bound === '1') {
    return;
  }

  searchInput.dataset.bound = '1';
  searchInput.addEventListener('input', function() {
    var searchValue = this.value || '';

    if (inventoryTableInstance && typeof inventoryTableInstance.search === 'function') {
      if (searchValue.trim() === '' || searchValue.trim().length >= 3) {
        inventoryTableInstance.search(searchValue).draw();
      } else {
        inventoryTableInstance.search('').draw();
      }
    }

    filterInventoryRows(searchValue);
  });
}

$(function () {
  try {
    inventoryTableInstance = $('#mymaintable').DataTable({
              stripeClasses:['stripe1','stripe2'],
              orderClasses: false,
              <?php // Bring in the translations ?>
              <?php require($GLOBALS['srcdir'] . '/js/xl/datatables-net.js.php'); ?>
          });
  } catch (error) {
    inventoryTableInstance = null;
  }

  bindInventorySearch();
});

document.addEventListener('DOMContentLoaded', bindInventorySearch);
window.addEventListener('load', bindInventorySearch);

// Editable cost functionality (product-level)
function editCost(element) {
    var currentCost = parseFloat(element.getAttribute('data-current-cost'));
    var drugId = element.getAttribute('data-drug-id');
    
    // Create input field
    var input = document.createElement('input');
    input.type = 'number';
    input.step = '0.01';
    input.min = '0';
    input.value = currentCost;
    input.className = 'cost-input';
    input.style = 'width: 80px; text-align: center; border: 1px solid #007bff; border-radius: 3px; padding: 2px;';
    
    // Replace the span with input
    var parent = element.parentNode;
    parent.innerHTML = '';
    parent.appendChild(input);
    input.focus();
    input.select();
    
    // Handle save on enter or blur
    function saveCost() {
        var newCost = parseFloat(input.value);
        if (isNaN(newCost) || newCost < 0) {
            alert('Please enter a valid cost (must be 0 or greater)');
            input.focus();
            return;
        }
        
        // Show loading indicator
        parent.innerHTML = '<span style="color: #666;">Updating...</span>';
        
        // Send AJAX request
        $.ajax({
            url: 'update_drug_pricing.php',
            type: 'POST',
            data: {
                drug_id: drugId,
                cost_per_unit: newCost,
                csrf_token_form: '<?php echo attr(CsrfUtils::collectCsrfToken()); ?>'
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Update the display
                    var displayValue = newCost > 0 ? '$' + newCost.toFixed(2) : 'N/A';
                    parent.innerHTML = '<span class="editable-cost" data-drug-id="' + drugId + 
                                     '" data-current-cost="' + newCost + '" onclick="editCost(this)" ' +
                                     'title="<?php echo xla('Click to edit cost per unit'); ?>">' + displayValue + '</span>';
                    
                    // Show success message briefly
                    showNotification('Cost updated successfully', 'success');
                } else {
                    // Show error and revert
                    alert('Error: ' + response.message);
                    var displayValue = currentCost > 0 ? '$' + currentCost.toFixed(2) : 'N/A';
                    parent.innerHTML = '<span class="editable-cost" data-drug-id="' + drugId + 
                                     '" data-current-cost="' + currentCost + '" onclick="editCost(this)" ' +
                                     'title="<?php echo xla('Click to edit cost per unit'); ?>">' + displayValue + '</span>';
                }
            },
            error: function() {
                // Show error and revert
                alert('Network error occurred. Please try again.');
                var displayValue = currentCost > 0 ? '$' + currentCost.toFixed(2) : 'N/A';
                parent.innerHTML = '<span class="editable-cost" data-drug-id="' + drugId + 
                                 '" data-current-cost="' + currentCost + '" onclick="editCost(this)" ' +
                                 'title="<?php echo xla('Click to edit cost per unit'); ?>">' + displayValue + '</span>';
            }
        });
    }
    
    input.addEventListener('blur', saveCost);
    input.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            saveCost();
        } else if (e.key === 'Escape') {
            // Cancel editing
            var displayValue = currentCost > 0 ? '$' + currentCost.toFixed(2) : 'N/A';
            parent.innerHTML = '<span class="editable-cost" data-drug-id="' + drugId + 
                             '" data-current-cost="' + currentCost + '" onclick="editCost(this)" ' +
                             'title="<?php echo xla('Click to edit cost per unit'); ?>">' + displayValue + '</span>';
        }
    });
}

// Editable sell price functionality (product-level)
function editSellPrice(element) {
    var currentPrice = parseFloat(element.getAttribute('data-current-price'));
    var drugId = element.getAttribute('data-drug-id');
    
    // Create input field
    var input = document.createElement('input');
    input.type = 'number';
    input.step = '0.01';
    input.min = '0';
    input.value = currentPrice;
    input.className = 'sell-price-input';
    input.style = 'width: 80px; text-align: center; border: 1px solid #007bff; border-radius: 3px; padding: 2px;';
    
    // Replace the span with input
    var parent = element.parentNode;
    parent.innerHTML = '';
    parent.appendChild(input);
    input.focus();
    input.select();
    
    // Handle save on enter or blur
    function savePrice() {
        var newPrice = parseFloat(input.value);
        if (isNaN(newPrice) || newPrice < 0) {
            alert('Please enter a valid price (must be 0 or greater)');
            input.focus();
            return;
        }
        
        // Show loading indicator
        parent.innerHTML = '<span style="color: #666;">Updating...</span>';
        
        // Send AJAX request
        $.ajax({
            url: 'update_drug_pricing.php',
            type: 'POST',
            data: {
                drug_id: drugId,
                sell_price: newPrice,
                csrf_token_form: '<?php echo attr(CsrfUtils::collectCsrfToken()); ?>'
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Update the display
                    var displayValue = newPrice > 0 ? '$' + newPrice.toFixed(2) : 'N/A';
                    parent.innerHTML = '<span class="editable-sell-price" data-drug-id="' + drugId + 
                                     '" data-current-price="' + newPrice + '" onclick="editSellPrice(this)" ' +
                                     'title="<?php echo xla('Click to edit sell price'); ?>">' + displayValue + '</span>';
                    
                    // Show success message briefly
                    showNotification('Sell price updated successfully', 'success');
                } else {
                    // Show error and revert
                    alert('Error: ' + response.message);
                    var displayValue = currentPrice > 0 ? '$' + currentPrice.toFixed(2) : 'N/A';
                    parent.innerHTML = '<span class="editable-sell-price" data-drug-id="' + drugId + 
                                     '" data-current-price="' + currentPrice + '" onclick="editSellPrice(this)" ' +
                                     'title="<?php echo xla('Click to edit sell price'); ?>">' + displayValue + '</span>';
                }
            },
            error: function() {
                // Show error and revert
                alert('Network error occurred. Please try again.');
                var displayValue = currentPrice > 0 ? '$' + currentPrice.toFixed(2) : 'N/A';
                parent.innerHTML = '<span class="editable-sell-price" data-drug-id="' + drugId + 
                                 '" data-current-price="' + currentPrice + '" onclick="editSellPrice(this)" ' +
                                 'title="<?php echo xla('Click to edit sell price'); ?>">' + displayValue + '</span>';
            }
        });
    }
    
    input.addEventListener('blur', savePrice);
    input.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            savePrice();
        } else if (e.key === 'Escape') {
            // Cancel editing
            var displayValue = currentPrice > 0 ? '$' + currentPrice.toFixed(2) : 'N/A';
            parent.innerHTML = '<span class="editable-sell-price" data-drug-id="' + drugId + 
                             '" data-current-price="' + currentPrice + '" onclick="editSellPrice(this)" ' +
                             'title="<?php echo xla('Click to edit sell price'); ?>">' + displayValue + '</span>';
        }
    });
}

function editCqoh(element) {
    var currentCqoh = parseFloat(element.getAttribute('data-current-cqoh'));
    var drugId = element.getAttribute('data-drug-id');
    var parent = element.parentNode;
    var isMedical = element.classList.contains('medical-cqoh-bubble');

    if (isMedical && !document.body.classList.contains('medical-counting-mode')) {
        showNotification('<?php echo attr(xla('Click Start Counting before recording Medication CQOH.')); ?>', 'warning');
        return;
    }

    if (isNaN(currentCqoh)) {
        currentCqoh = 0;
    }

    var input = document.createElement('input');
    input.type = 'number';
    input.step = '0.0001';
    input.min = '0';
    input.value = currentCqoh.toFixed(4).replace(/\.?0+$/, '');
    input.className = 'cqoh-input';

    parent.innerHTML = '';
    parent.appendChild(input);
    input.focus();
    input.select();

    function formatCqohValue(value) {
        var numericValue = parseFloat(value);
        if (isNaN(numericValue)) {
            numericValue = 0;
        }
        return numericValue.toFixed(4).replace(/\.?0+$/, '');
    }

    function restoreCqoh(value) {
        var bubbleClass = isMedical ? 'editable-cqoh medical-cqoh-bubble medical-cqoh-pending' : 'editable-cqoh';
        var titleText = isMedical
            ? '<?php echo xla('Click to record counted quantity for this Medication item'); ?>'
            : '<?php echo xla('Click to update current quantity on hand'); ?>';
        var html = '<span class="' + bubbleClass + '" data-drug-id="' + drugId +
            '" data-current-cqoh="' + value + '" onclick="editCqoh(this)" ' +
            'title="' + titleText + '">' + formatCqohValue(value) + '</span>';

        if (isMedical) {
            html += '<div class="medical-cqoh-difference"><span class="difference-pending"><?php echo attr(xla('Refreshing count status...')); ?></span></div>';
        }

        parent.innerHTML = html;
    }

    function saveCqoh() {
        var newCqoh = parseFloat(input.value);
        if (isNaN(newCqoh) || newCqoh < 0) {
            alert('<?php echo attr(xla('Please enter a valid current quantity (0 or greater)')); ?>');
            input.focus();
            return;
        }

        parent.innerHTML = '<span style="color: #666;"><?php echo attr(xla('Updating...')); ?></span>';

        $.ajax({
            url: 'update_cqoh.php',
            type: 'POST',
            dataType: 'json',
            data: {
                drug_id: drugId,
                cqoh: newCqoh,
                csrf_token_form: '<?php echo attr(CsrfUtils::collectCsrfToken()); ?>'
            },
            success: function(response) {
                if (response.success) {
                    var updatedValue = response.cqoh !== undefined ? response.cqoh : newCqoh;
                    restoreCqoh(updatedValue);
                    showNotification(response.message || '<?php echo attr(xla('Current quantity updated successfully')); ?>', 'success');
                    window.setTimeout(function() {
                        window.location.reload();
                    }, 350);
                } else {
                    alert('Error: ' + (response.message || '<?php echo attr(xla('Unable to update current quantity')); ?>'));
                    restoreCqoh(currentCqoh);
                }
            },
            error: function() {
                alert('<?php echo attr(xla('Network error occurred. Please try again.')); ?>');
                restoreCqoh(currentCqoh);
            }
        });
    }

    input.addEventListener('blur', saveCqoh);
    input.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            saveCqoh();
        } else if (e.key === 'Escape') {
            restoreCqoh(currentCqoh);
        }
    });
}

// Function to edit discount settings
function editDiscount(element) {
    var drugId = element.getAttribute('data-drug-id');
    var currentDiscount = parseFloat(element.getAttribute('data-current-discount')) || 0;
    var discountType = element.getAttribute('data-discount-type') || 'percentage';
    var discountQuantity = parseInt(element.getAttribute('data-discount-quantity')) || 0;
    var discountActive = element.getAttribute('data-discount-active') || '0';
    var discountStart = element.getAttribute('data-discount-start') || '';
    var discountEnd = element.getAttribute('data-discount-end') || '';
    var discountMonth = element.getAttribute('data-discount-month') || '';
    var discountDescription = element.getAttribute('data-discount-description') || '';
    
    // Debug logging
    console.log('Discount Data:', {
        drugId: drugId,
        currentDiscount: currentDiscount,
        discountType: discountType,
        discountActive: discountActive,
        discountStart: discountStart,
        discountEnd: discountEnd,
        discountMonth: discountMonth,
        discountDescription: discountDescription
    });
    console.log('Raw discountStart:', discountStart, 'Type:', typeof discountStart);
    console.log('Raw discountEnd:', discountEnd, 'Type:', typeof discountEnd);
    console.log('Raw discountMonth:', discountMonth, 'Type:', typeof discountMonth);
    
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
                <option value="quantity" ${discountType == 'quantity' ? 'selected' : ''}>Quantity (Buy X Get 1 Free)</option>
            </select>
        </div>
        
        <div id="discount_value_div" style="margin-bottom: 15px;">
            <label id="discount_value_label" style="display: block; margin-bottom: 5px; font-weight: bold;">Discount Value:</label>
            <input type="number" id="discount_value" value="${currentDiscount}" min="0" step="0.01" 
                   style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
        </div>
        
        <div id="discount_quantity_div" style="margin-bottom: 15px; display: none;">
            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Buy Quantity (must be > 1):</label>
            <input type="number" id="discount_quantity" value="${discountQuantity || 2}" min="2" step="1" 
                   style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                   placeholder="e.g., 4 means Buy 4 Get 1 Free">
            <small style="color: #666; display: block; margin-top: 5px;">Example: 4 means buy 4 items, get the 5th one free</small>
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
                <input type="date" id="discount_start_date" value="${discountStart}" min="${new Date().toISOString().split('T')[0]}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            </div>
            
            <div id="date_range_div" style="display: none;">
                <div style="margin-bottom: 10px;">
                    <label>Start Date:</label>
                    <input type="date" id="discount_start_date_range" value="${discountStart}" min="${new Date().toISOString().split('T')[0]}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div>
                    <label>End Date:</label>
                    <input type="date" id="discount_end_date" value="${discountEnd}" min="${new Date().toISOString().split('T')[0]}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
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
            <button type="button" onclick="closeDiscountModal()" style="padding: 8px 16px; margin-right: 10px; border: 1px solid #ddd; background: #f5f5f5; border-radius: 4px; cursor: pointer;">Cancel</button>
            <button type="button" onclick="saveDiscountSettings()" style="padding: 8px 16px; background: #ff9800; color: white; border: none; border-radius: 4px; cursor: pointer;">Save</button>
        </div>
    `;
    
    modal.appendChild(modalContent);
    document.body.appendChild(modal);
    
    // Set initial activation type based on existing data
    var activationType = 'none';
    
    // Check for valid date range (both dates must be valid and not 0000-00-00)
    if (discountStart && discountEnd && discountStart !== '' && discountEnd !== '' && 
        discountStart !== '0000-00-00' && discountEnd !== '0000-00-00') {
        activationType = 'date_range';
    } 
    // Check for valid specific date (not 0000-00-00)
    else if (discountStart && discountStart !== '' && discountStart !== '0000-00-00') {
        activationType = 'specific_date';
    } 
    // Check for valid month
    else if (discountMonth && discountMonth !== '') {
        activationType = 'month';
    }
    
    console.log('Detected activation type:', activationType);
    
    // Set the activation type and update fields
    setTimeout(function() {
        var activationSelect = document.getElementById('activation_type');
        if (activationSelect) {
            activationSelect.value = activationType;
            console.log('Set activation type to:', activationType);
            // Call updateActivationFields only once
            setTimeout(function() {
                updateActivationFields();
            }, 50);
        } else {
            console.error('Activation select element not found');
        }
    }, 100);
    
    // Remove any existing event listeners first
    var activationSelect = document.getElementById('activation_type');
    var discountTypeSelect = document.getElementById('discount_type');
    
    if (activationSelect) {
        activationSelect.removeEventListener('change', updateActivationFields);
        activationSelect.addEventListener('change', updateActivationFields);
    }
    
    if (discountTypeSelect) {
        discountTypeSelect.removeEventListener('change', updateDiscountValueLabel);
        discountTypeSelect.addEventListener('change', updateDiscountValueLabel);
        discountTypeSelect.addEventListener('change', updateDiscountTypeFields);
    }
    
    // Initial call to set fields correctly
    updateDiscountTypeFields();
    
    // Store data for save function
    window.currentDiscountData = {
        drugId: drugId,
        element: element,
        parent: element.parentNode
    };
    
    function updateActivationFields() {
        var activationType = document.getElementById('activation_type').value;
        console.log('updateActivationFields called with type:', activationType);
        
        document.getElementById('specific_date_div').style.display = 'none';
        document.getElementById('date_range_div').style.display = 'none';
        document.getElementById('month_div').style.display = 'none';
        
        if (activationType === 'specific_date') {
            document.getElementById('specific_date_div').style.display = 'block';
            console.log('Showing specific_date_div');
        } else if (activationType === 'date_range') {
            document.getElementById('date_range_div').style.display = 'block';
            console.log('Showing date_range_div');
        } else if (activationType === 'month') {
            document.getElementById('month_div').style.display = 'block';
            console.log('Showing month_div');
        }
    }
    
    function updateDiscountValueLabel() {
        var discountType = document.getElementById('discount_type').value;
        var label = document.getElementById('discount_value_label');
        if (discountType === 'percentage') {
            label.textContent = 'Discount Value (%):';
        } else if (discountType === 'fixed') {
            label.textContent = 'Discount Value ($):';
        }
    }
    
    function updateDiscountTypeFields() {
        var discountType = document.getElementById('discount_type').value;
        var valueDiv = document.getElementById('discount_value_div');
        var quantityDiv = document.getElementById('discount_quantity_div');
        
        if (discountType === 'quantity') {
            valueDiv.style.display = 'none';
            quantityDiv.style.display = 'block';
        } else {
            valueDiv.style.display = 'block';
            quantityDiv.style.display = 'none';
        }
        
        updateDiscountValueLabel();
    }
    
    // Make functions globally available
    window.updateActivationFields = updateActivationFields;
    window.updateDiscountValueLabel = updateDiscountValueLabel;
    window.updateDiscountTypeFields = updateDiscountTypeFields;
}

function closeDiscountModal() {
    var modal = document.querySelector('.discount-modal');
    if (modal) {
        modal.remove();
    }
}

function saveDiscountSettings() {
    var data = window.currentDiscountData;
    if (!data) return;
    
    var discountActive = document.getElementById('discount_active').checked ? 1 : 0;
    var discountType = document.getElementById('discount_type').value;
    var discountValue = parseFloat(document.getElementById('discount_value').value) || 0;
    var discountQuantity = parseInt(document.getElementById('discount_quantity').value) || 0;
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
    if (discountType === 'quantity' && discountQuantity <= 1) {
        alert('Quantity discount must be greater than 1. Example: 4 means buy 4 get 1 free.');
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
    
    // Validate date ranges
    if (activationType === 'date_range' && discountStartDate && discountEndDate) {
        if (new Date(discountEndDate) < new Date(discountStartDate)) {
            alert('End date cannot be before start date.');
            return;
        }
    }
    
    // Send AJAX request
    $.ajax({
        url: 'update_discount.php',
        method: 'POST',
        data: {
            drug_id: data.drugId,
            discount_active: discountActive,
            discount_type: discountType,
            discount_percent: discountType === 'percentage' ? discountValue : 0,
            discount_amount: discountType === 'fixed' ? discountValue : 0,
            discount_quantity: discountType === 'quantity' ? discountQuantity : null,
            discount_start_date: discountStartDate,
            discount_end_date: discountEndDate,
            discount_month: discountMonth,
            discount_description: discountDescription,
            csrf_token_form: '<?php echo attr(CsrfUtils::collectCsrfToken()); ?>'
        },
        success: function(response) {
            if (response.success) {
                // Refresh the page to show updated discount
                location.reload();
                showNotification('Discount settings updated successfully', 'success');
            } else {
                alert('Error: ' + response.message);
            }
        },
        error: function() {
            alert('Network error occurred. Please try again.');
        }
    });
    
    closeDiscountModal();
}

// Simple notification function
function showNotification(message, type) {
    var notification = document.createElement('div');
    notification.className = 'notification ' + (type || 'info');
    notification.textContent = message;
    notification.style = 'position: fixed; top: 20px; right: 20px; padding: 10px 15px; ' +
                        'background: ' + (type === 'success' ? '#28a745' : (type === 'warning' ? '#d97706' : '#007bff')) + '; ' +
                        'color: white; border-radius: 5px; z-index: 9999; font-size: 14px;';
    
    document.body.appendChild(notification);
    
    setTimeout(function() {
        notification.remove();
    }, 3000);
}

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function showInventoryCountModal(title, bodyHtml) {
    var modal = document.getElementById('inventory-count-modal');
    if (!modal) {
        return;
    }

    document.getElementById('inventory-count-modal-title').textContent = title;
    document.getElementById('inventory-count-modal-body').innerHTML = bodyHtml;
    modal.classList.add('is-visible');
}

function closeInventoryCountModal() {
    var modal = document.getElementById('inventory-count-modal');
    if (modal) {
        modal.classList.remove('is-visible');
    }
}

function getMedicalCountBubbles() {
    return Array.prototype.slice.call(document.querySelectorAll('.medical-cqoh-bubble'));
}

function focusMedicalCountTarget() {
    var bubbles = getMedicalCountBubbles();
    if (!bubbles.length) {
        return false;
    }

    bubbles.forEach(function(bubble) {
        bubble.classList.remove('is-count-target');
    });

    var target = bubbles.find(function(bubble) {
        return bubble.classList.contains('medical-cqoh-pending');
    }) || bubbles[0];

    if (!target) {
        return false;
    }

    target.classList.add('is-count-target');
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return true;
}

function updateMedicalCountToggleUi(enabled) {
    var toggle = document.getElementById('medical-count-toggle-btn');
    if (!toggle) {
        return;
    }

    toggle.classList.toggle('is-active', enabled);
    toggle.innerHTML = enabled
        ? '<i class="fas fa-check-circle"></i> <?php echo attr(xla('Counting On')); ?>'
        : '<i class="fas fa-clipboard-list"></i> <?php echo attr(xla('Start Counting')); ?>';
}

function setMedicalCountingMode(enabled) {
    document.body.classList.toggle('medical-counting-mode', enabled);
    window.localStorage.setItem('medicalCountingMode', enabled ? '1' : '0');
    updateMedicalCountToggleUi(enabled);

    if (enabled) {
        if (!focusMedicalCountTarget()) {
            showNotification('<?php echo attr(xla('No Medication count bubbles are available on this page.')); ?>', 'warning');
        }
    } else {
        getMedicalCountBubbles().forEach(function(bubble) {
            bubble.classList.remove('is-count-target');
        });
    }
}

function toggleMedicalCountingMode() {
    setMedicalCountingMode(!document.body.classList.contains('medical-counting-mode'));
}

document.addEventListener('DOMContentLoaded', function() {
    updateMedicalCountToggleUi(false);
    if (window.localStorage.getItem('medicalCountingMode') === '1') {
        setMedicalCountingMode(true);
    }
});

function sendMedicalInventoryReport() {
    var sendButton = document.getElementById('send-medical-count-report-btn');
    var recipientSelect = document.getElementById('medical-report-recipient');
    if (!sendButton || sendButton.disabled) {
        return;
    }

    var originalText = sendButton.innerHTML;
    sendButton.disabled = true;
    sendButton.innerHTML = '<i class="fas fa-paper-plane"></i> <?php echo xlj('Sending...'); ?>';

    $.ajax({
        url: 'send_medical_inventory_count_report.php',
        type: 'POST',
        dataType: 'json',
        data: {
            csrf_token_form: '<?php echo attr(CsrfUtils::collectCsrfToken()); ?>',
            recipient_email: recipientSelect ? recipientSelect.value : ''
        },
        success: function(response) {
            if (response.success) {
                showNotification(response.message || '<?php echo xlj('Medication inventory report sent successfully'); ?>', 'success');
                window.setTimeout(function() {
                    window.location.reload();
                }, 600);
                return;
            }

            if (response.incomplete) {
                var missingItems = Array.isArray(response.missing_items) ? response.missing_items : [];
                var missingMarkup = '';

                if (missingItems.length > 0) {
                    missingMarkup = '<ul class="inventory-missing-list">' + missingItems.map(function(item) {
                        return '<li>' + escapeHtml(item) + '</li>';
                    }).join('') + '</ul>';
                }

                showInventoryCountModal(
                    '<?php echo xlj('Medication Count Still Incomplete'); ?>',
                    '<p><?php echo xlj('Please complete the remaining Medication counts before sending the report to admin.'); ?></p>' +
                    '<p><strong><?php echo xlj('Progress'); ?>:</strong> ' +
                    escapeHtml(response.counted_items) + ' / ' + escapeHtml(response.total_items) + '</p>' +
                    missingMarkup
                );
                showNotification(response.message || '<?php echo xlj('Some Medication items are still missing a count'); ?>', 'warning');
            } else {
                showNotification(response.message || '<?php echo xlj('Unable to send Medication inventory report'); ?>', 'warning');
            }
        },
        error: function() {
            showNotification('<?php echo xlj('Network error while sending Medication inventory report'); ?>', 'warning');
        },
        complete: function() {
            sendButton.disabled = false;
            sendButton.innerHTML = originalText;
        }
    });
}
</script>

</head>

<body class="body_top">
<form method='post' action='drug_inventory.php' onsubmit='return top.restoreSession()'>

<div class="filter-bar">
  <span class="header-title">Inventory Management</span>
  <div class="form-group search-group">
    <div class="search-shell">
      <span class="search-icon"><i class="fa fa-search" aria-hidden="true"></i></span>
      <input type="text" id="inventory_search" class="form-control form-control-sm" placeholder="<?php echo xla('Search inventory by name, lot, category, or expiry...'); ?>" />
    </div>
  </div>
  <div class="inventory-toolbar-actions">
<?php
$inventoryExportUrl = 'export_inventory.php?' . http_build_query([
    'form_facility' => $form_facility,
    'form_warehouse' => $form_warehouse,
    'form_category' => $form_category,
    'form_consumable' => $form_consumable,
    'form_show_empty' => $form_show_empty ? 1 : 0,
    'form_show_inactive' => $form_show_inactive ? 1 : 0,
]);
?>
    <span class="inventory-icon-divider" aria-hidden="true"></span>
    <a class="inventory-icon-btn" aria-label="<?php echo attr(xla('Import Inventory')); ?>" href="import_inventory.php">
      <i class="fas fa-file-import"></i>
    </a>
    <a class="inventory-icon-btn" aria-label="<?php echo attr(xla('Export Inventory')); ?>" href="<?php echo attr($inventoryExportUrl); ?>">
      <i class="fas fa-file-export"></i>
    </a>
  </div>
  <div class="filter-fields">
    <div class="form-group">
      <label for="form_facility">Facility</label>
      <select name='form_facility' id="form_facility" class="form-control form-control-sm" onchange='facchanged()'<?php echo $selectedFacilityId > 0 ? ' disabled' : ''; ?>>
<?php if ($selectedFacilityId <= 0) { ?>
        <option value=''>-- <?php echo xlt('All Facilities'); ?> --</option>
<?php } ?>
<?php
$fres = sqlStatement("SELECT id, name FROM facility ORDER BY name");
while ($frow = sqlFetchArray($fres)) {
    $facid = $frow['id'];
    if ($is_user_restricted && !isFacilityAllowed($facid)) {
        continue;
    }
    echo "        <option value='" . attr($facid) . "'";
    if ($facid == $form_facility) {
        echo " selected";
    }
    echo ">" . text($frow['name']) . "</option>\n";
}
?>
      </select>
<?php if ($selectedFacilityId > 0) { ?>
      <input type="hidden" name="form_facility" value="<?php echo attr($selectedFacilityId); ?>">
<?php } ?>
    </div>
    <div class="form-group">
      <label for="form_category">Category</label>
      <select name='form_category' id="form_category" class="form-control form-control-sm">
        <option value=''><?php echo xlt('All Categories'); ?></option>
<?php
// Get categories from categories table
$catres = sqlStatement("SELECT category_name FROM categories WHERE is_active = 1 ORDER BY category_name");
while ($catrow = sqlFetchArray($catres)) {
    $catname = $catrow['category_name'];
    echo "        <option value='" . attr($catname) . "'";
    if ($catname == $form_category) {
        echo " selected";
    }
    echo ">" . text($catname) . "</option>\n";
}
?>
      </select>
    </div>
    <div class="form-group" style="display: none;">
      <label for="form_warehouse">Warehouse</label>
      <select name='form_warehouse' id="form_warehouse" class="form-control form-control-sm">
        <option value=''><?php echo xlt('All Warehouses'); ?></option>
<?php
$lres = sqlStatement(
    "SELECT * FROM list_options " .
    "WHERE list_id = 'warehouse' ORDER BY seq, title"
);
while ($lrow = sqlFetchArray($lres)) {
    $whid  = $lrow['option_id'];
    $facid = $lrow['option_value'];
    if ($is_user_restricted && !isWarehouseAllowed($facid, $whid)) {
        continue;
    }
    echo "        <option value='" . attr("$whid/$facid") . "'";
    echo " id='fac" . attr($facid) . "'";
    if (strlen($form_warehouse)  > 0 && $whid == $form_warehouse) {
        echo " selected";
    }
    echo ">" . text(xl_list_label($lrow['title'])) . "</option>\n";
}
?>
      </select>
    </div>
    <div class="form-group" style="display: none;">
      <label for="form_consumable">Type</label>
      <select name='form_consumable' id="form_consumable" class="form-control form-control-sm">
<?php
foreach (
    array(
    '0' => xl('All Product Types'),
    '1' => xl('Consumable Only'),
    '2' => xl('Non-Consumable Only'),
    ) as $key => $value
) {
    echo "        <option value='" . attr($key) . "'";
    if ($key == $form_consumable) {
        echo " selected";
    }
    echo ">" . text($value) . "</option>\n";
}
?>
      </select>
    </div>
    <div class="form-group">
      <input type='checkbox' name='form_show_empty' id='form_show_empty' value='1'<?php if ($form_show_empty) { echo " checked";} ?> />
      <label for="form_show_empty">Show empty lots</label>
    </div>
    <div class="form-group" style="display: none;">
      <input type='checkbox' name='form_show_inactive' id='form_show_inactive' value='1'<?php if ($form_show_inactive) { echo " checked";} ?> />
      <label for="form_show_inactive">Show inactive</label>
    </div>
    <button type='submit' name='form_refresh' class='btn btn-primary btn-sm'><?php echo xla('Refresh'); ?></button>
  </div>
</div>

<!-- Action buttons centered above table -->
<div class="action-buttons-bar">
  <button type='button' class='btn btn-primary' onclick='dodclick(0)'><?php echo xla('Add Inventory'); ?></button>
  <a href="../product_categories/manage_categories.php" class="btn btn-secondary">
    <i class="fa fa-tags"></i> <?php echo xla('Manage Product Categories'); ?>
  </a>
  <div class="medical-count-actions">
    <div class="medical-count-summary">
      <span class="summary-label"><?php echo xlt('Medication Count Progress'); ?></span>
      <span class="summary-value"><?php echo text($medicalInventoryCountedItems); ?> / <?php echo text($medicalInventoryTotalItems); ?></span>
<?php if ($medicalInventoryTotalItems > 0) { ?>
      <span class="<?php echo $medicalInventoryMissingCount > 0 ? 'summary-alert' : 'summary-ok'; ?>">
        <?php echo text($medicalInventoryMissingCount > 0 ? $medicalInventoryMissingCount . ' pending' : 'All counted'); ?>
      </span>
<?php } else { ?>
      <span class="summary-alert"><?php echo xlt('No Medication items found'); ?></span>
<?php } ?>
    </div>
    <button
      type="button"
      id="medical-count-toggle-btn"
      class="btn medical-count-toggle"
      onclick="toggleMedicalCountingMode()"
    >
      <i class="fas fa-clipboard-list"></i>
      <?php echo xlt('Start Counting'); ?>
    </button>
<?php if (!empty($medicalInventoryAdminRecipients)) { ?>
    <select id="medical-report-recipient" class="medical-recipient-picker">
<?php foreach ($medicalInventoryAdminRecipients as $recipient) { ?>
      <option value="<?php echo attr($recipient['email']); ?>"<?php echo $recipient['email'] === $medicalInventorySelectedRecipient ? ' selected' : ''; ?>>
        <?php echo text($recipient['label'] . ' - ' . $recipient['email']); ?>
      </option>
<?php } ?>
    </select>
<?php } ?>
    <button
      type="button"
      id="send-medical-count-report-btn"
      class="btn btn-success medical-count-btn"
      onclick="sendMedicalInventoryReport()"
<?php echo ($medicalInventoryTotalItems <= 0 || empty($medicalInventoryAdminRecipients)) ? ' disabled' : ''; ?>
      title="<?php echo attr(!empty($medicalInventoryAdminRecipients) ? xla('Send Medication inventory count report to inventory admin') : xla('Configure an administrator email before sending reports')); ?>"
    >
      <i class="fas fa-paper-plane"></i>
      <?php echo xlt('Send Medication Count Report to Admin'); ?>
    </button>
<?php if (empty($medicalInventoryAdminRecipients)) { ?>
    <span class="text-danger"><?php echo xlt('Admin email not configured'); ?></span>
<?php } ?>
  </div>
</div>

<!-- TODO: Why are we not using the BS4 table class here? !-->
<div class="drug-inventory-container">
<table id='mymaintable' class="table table-bordered table-striped w-100" style="width:100%; margin:0;">
 <thead>
 <tr class='head'>
  <th><span class="header-icon">&#128137;</span><?php echo xlt('Name'); ?></th>
  <th class="hide-inventory-col"><?php echo xlt('Act'); ?></th>
  <th class="hide-inventory-col"><?php echo xlt('Cons'); ?></th>
  <th class="hide-inventory-col"><?php echo xlt('NDC'); ?></th>
  <th class="hide-inventory-col"><?php echo xlt('Form'); ?></th>
  <th><?php echo xlt('Size'); ?></th>
  <th title='<?php echo xlt('Measurement Units'); ?>'><?php echo xlt('Strength'); ?></th>
  <th><span class="header-icon">&#128176;</span><?php echo xlt('Cost'); ?></th>
  <th><span class="header-icon">&#128181;</span><?php echo xlt('Sell Price'); ?></th>
  <th><span class="header-icon">&#128183;</span><?php echo xlt('Discount'); ?></th>

  <th><span class="header-icon">&#127991;</span><?php echo xlt('Category'); ?></th>
  <th><span class="header-icon">&#128230;</span><?php echo xlt('Lot'); ?></th>
  <th class="hide-inventory-col"><?php echo xlt('Facility'); ?></th>
  <th class="hide-inventory-col"><?php echo xlt('Warehouse'); ?></th>
  <th><span class="header-icon">&#128202;</span><?php echo xlt('QOH'); ?></th>
  <th><span class="header-icon">&#9998;</span><?php echo xlt('CQOH'); ?></th>
  <th><span class="header-icon">&#9200;</span><?php echo xlt('Expires'); ?></th>
          <th><span class="header-icon">&#9888;</span><?php echo xlt('Report'); ?></th>
 </tr>
 </thead>
 <tbody>
<?php
 $prevRow = '';
while ($row = sqlFetchArray($res)) {
    if (!empty($row['inventory_id']) && $is_user_restricted && !isWarehouseAllowed($row['facid'], $row['warehouse_id'])) {
        continue;
    }
    $row = processData($row);
    if ($prevRow == '') {
        $prevRow = $row;
        continue;
    }
    if ($prevRow['drug_id'] == $row['drug_id']) {
        $row = mergeData($prevRow, $row);
    } else {
        mapToTable($prevRow);
    }
    $prevRow = $row;
} // end while
mapToTable($prevRow);
?>
 </tbody>
</table>
</div>

<input type="hidden" name="form_orderby" value="<?php echo attr($form_orderby) ?>" />

</form>

<script>
facchanged();

// Defective Medicine Reporting Functions
function reportDefectiveMedicine(drugId, inventoryId, lotNumber, qoh) {
    // Create inline form for defective medicine reporting
    const existingForm = document.getElementById(`defective-form-${inventoryId}`);
    if (existingForm) {
        existingForm.remove();
        return;
    }
    
    // Create inline form
    const formHtml = `
        <div id="defective-form-${inventoryId}" style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 15px; margin: 10px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1); max-width: 100%; overflow-x: auto;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #495057; white-space: nowrap;">Quantity (Max: ${qoh})</label>
                    <input type="number" id="defect-qty-${inventoryId}" min="1" max="${qoh}" value="1" 
                           style="width: 100%; padding: 8px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; box-sizing: border-box;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #495057; white-space: nowrap;">Reason</label>
                    <input type="text" id="defect-reason-${inventoryId}" placeholder="Enter defect reason" 
                           style="width: 100%; padding: 8px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; box-sizing: border-box;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #495057; white-space: nowrap;">Type</label>
                    <select id="defect-type-${inventoryId}" style="width: 100%; padding: 8px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; box-sizing: border-box;">
                        <option value="defective">Defective</option>
                        <option value="faulty">Faulty</option>
                        <option value="expired">Expired</option>
                        <option value="contaminated">Contaminated</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div style="display: flex; gap: 10px; align-items: end; grid-column: 1 / -1; justify-content: center;">
                    <button onclick="submitDefectiveReport(${drugId}, ${inventoryId}, '${lotNumber}', ${qoh})" 
                            style="background: #007bff; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 600; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <i class="fas fa-exclamation-triangle"></i> Report
                    </button>
                    <button onclick="document.getElementById('defective-form-${inventoryId}').remove()" 
                            style="background: #6c757d; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 14px;">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    `;
    
    // Insert form after the defective button row
    const defectiveButton = document.getElementById(`defective-btn-${inventoryId}`);
    if (defectiveButton) {
        const buttonRow = defectiveButton.closest('div');
        buttonRow.insertAdjacentHTML('afterend', formHtml);
    }
}

function submitDefectiveReport(drugId, inventoryId, lotNumber, qoh) {
    const quantity = parseInt(document.getElementById(`defect-qty-${inventoryId}`).value);
    const reason = document.getElementById(`defect-reason-${inventoryId}`).value.trim();
    const defectType = document.getElementById(`defect-type-${inventoryId}`).value;
    
    if (!reason) {
        alert('Please enter a reason for the defective report');
        return;
    }
    
    if (quantity <= 0 || quantity > qoh) {
        alert('Please enter a valid quantity');
        return;
    }
    
    // Prepare data for reporting
    const reportData = {
        action: 'report_defective',
        drug_id: drugId,
        lot_number: lotNumber,
        inventory_id: inventoryId,
        pid: 0, // No specific patient for inventory-level reporting
        quantity: quantity,
        reason: reason,
        defect_type: defectType,
        notes: `Inventory-level defective report - ${lotNumber}`,
        csrf_token: document.querySelector('input[name="csrf_token_form"]')?.value || ''
    };
    
    // Send report to backend
    fetch('../pos/defective_medicines_handler_simple.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(reportData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Defective medicine reported successfully!');
            alert(`Inventory updated: ${data.quantity_deducted} units deducted. Remaining QOH: ${data.remaining_qoh}`);
            
            // Remove the form and refresh the page to show updated QOH
            document.getElementById(`defective-form-${inventoryId}`).remove();
            location.reload(); // Refresh to show updated inventory
        } else {
            alert('Error: ' + (data.error || 'Failed to report defective medicine'));
        }
    })
    .catch(error => {
        console.error('Error reporting defective medicine:', error);
        alert('Error reporting defective medicine. Please try again.');
    });
}
</script>

<div id="inventory-count-modal" class="inventory-modal-overlay" onclick="if (event.target === this) { closeInventoryCountModal(); }">
  <div class="inventory-modal-card" role="dialog" aria-modal="true" aria-labelledby="inventory-count-modal-title">
    <div class="inventory-modal-header">
      <h3 id="inventory-count-modal-title"><?php echo xlt('Medication Inventory Count'); ?></h3>
      <button type="button" class="inventory-modal-close" onclick="closeInventoryCountModal()" aria-label="<?php echo attr(xla('Close')); ?>">&times;</button>
    </div>
    <div id="inventory-count-modal-body" class="inventory-modal-body"></div>
    <div class="inventory-modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeInventoryCountModal()"><?php echo xlt('Close'); ?></button>
    </div>
  </div>
</div>

</body>
</html>

