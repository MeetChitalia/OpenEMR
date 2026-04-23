<?php

/**
 * Inventory Audit Log - Comprehensive tracking of all inventory movements
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    OpenEMR Team
 * @copyright Copyright (c) 2024 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;

// Check authorization
if (!AclMain::aclCheckCore('admin', 'super')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Inventory Audit Log")]);
    exit;
}

// Verify CSRF token
if (!empty($_POST)) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }
}

// Get filter parameters - Default to last 7 days for more recent activity
$start_date = (!empty($_POST["start_date"])) ? DateToYYYYMMDD($_POST["start_date"]) : date('Y-m-d', strtotime('-7 days'));
$end_date = (!empty($_POST["end_date"])) ? DateToYYYYMMDD($_POST["end_date"]) : date('Y-m-d');
$drug_id = (!empty($_POST["drug_id"])) ? intval($_POST["drug_id"]) : '';
$warehouse_id = (!empty($_POST["warehouse_id"])) ? $_POST["warehouse_id"] : '';
$movement_type = (!empty($_POST["movement_type"])) ? $_POST["movement_type"] : '';
$user_id = (!empty($_POST["user_id"])) ? $_POST["user_id"] : '';
$form_action = $_POST['form_action'] ?? '';
$limit = (!empty($_POST["limit"])) ? intval($_POST["limit"]) : 100; // Default to show 100 most recent records

// Export functionality
if ($form_action == 'export') {
    header("Pragma: public");
    header("Expires: 0");
    header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
    header("Content-Type: application/force-download");
    header("Content-Disposition: attachment; filename=inventory_audit_log.csv");
    header("Content-Description: File Transfer");
    
    // CSV headers
    echo csvEscape(xl('Date/Time')) . ',';
    echo csvEscape(xl('Product')) . ',';
    echo csvEscape(xl('Lot Number')) . ',';
    echo csvEscape(xl('Movement Type')) . ',';
    echo csvEscape(xl('Quantity Before')) . ',';
    echo csvEscape(xl('Quantity Change')) . ',';
    echo csvEscape(xl('Quantity After')) . ',';
    echo csvEscape(xl('User')) . ',';
    echo csvEscape(xl('IP Address')) . ',';
    echo csvEscape(xl('Reference')) . ',';
    echo csvEscape(xl('Reason')) . ',';
    echo csvEscape(xl('Notes')) . "\n";
}

// Build query for audit log
function buildAuditQuery($start_date, $end_date, $drug_id, $warehouse_id, $movement_type, $user_id, $limit = 100) {
    $sql = "SELECT 
                iml.*,
                COALESCE(d.name, CONCAT('Deleted Drug #', iml.drug_id)) as drug_name,
                d.ndc_number,
                CASE 
                    WHEN iml.reference_type = 'sale' THEN CONCAT('Sale #', iml.reference_id)
                    WHEN iml.reference_type = 'purchase' THEN CONCAT('Purchase #', iml.reference_id)
                    WHEN iml.reference_type = 'transfer' THEN CONCAT('Transfer #', iml.reference_id)
                    WHEN iml.reference_type = 'adjustment' THEN CONCAT('Adjustment #', iml.reference_id)
                    WHEN iml.reference_type = 'return' THEN CONCAT('Return #', iml.reference_id)
                    WHEN iml.reference_type = 'destruction' THEN CONCAT('Destruction #', iml.reference_id)
                    WHEN iml.reference_type = 'lot_management' THEN CONCAT('Lot #', iml.inventory_id)
                    WHEN iml.reference_type = 'inventory_management' THEN CONCAT('Drug #', iml.drug_id)
                    WHEN iml.reference_type = 'transaction' THEN CONCAT('Transaction #', iml.reference_id)
                    ELSE iml.reference_type
                END as reference_display
            FROM inventory_movement_log iml
            LEFT JOIN drugs d ON d.drug_id = iml.drug_id
            WHERE iml.movement_date BETWEEN ? AND ?
            AND NOT (iml.movement_type = 'lot_delete' AND iml.reference_type = 'inventory_update')";
    
    $params = array($start_date . ' 00:00:00', $end_date . ' 23:59:59');
    
    if (!empty($drug_id)) {
        $sql .= " AND iml.drug_id = ?";
        $params[] = $drug_id;
    }
    
    if (!empty($warehouse_id)) {
        $sql .= " AND iml.warehouse_id = ?";
        $params[] = $warehouse_id;
    }
    
    if (!empty($movement_type)) {
        $sql .= " AND iml.movement_type = ?";
        $params[] = $movement_type;
    }
    
    if (!empty($user_id)) {
        $sql .= " AND iml.user_id = ?";
        $params[] = $user_id;
    }
    
    $sql .= " ORDER BY iml.movement_date DESC, iml.log_id DESC LIMIT ?";
    $params[] = $limit;
    
    return array($sql, $params);
}

// Get movement types for dropdown
function getMovementTypes() {
    return array(
        'inventory_add' => xl('Add Drug'),
        'inventory_update' => xl('Update Drug'),
        'inventory_delete' => xl('Delete Drug'),
        'lot_add' => xl('Add Lot'),
        'lot_update' => xl('Update Lot'),
        'lot_delete' => xl('Delete Lot'),
        'sale' => xl('Sale'),
        'purchase' => xl('Purchase'),
        'transfer' => xl('Transfer'),
        'adjustment' => xl('Adjustment'),
        'return' => xl('Return'),
        'destruction' => xl('Destruction'),
        'expiration' => xl('Expiration')
    );
}

// Get drugs for dropdown
function getDrugsList() {
    $sql = "SELECT drug_id, name, ndc_number FROM drugs WHERE active = 1 ORDER BY name";
    $result = sqlStatement($sql);
    $drugs = array();
    while ($row = sqlFetchArray($result)) {
        $drugs[$row['drug_id']] = $row['name'] . ' (' . $row['ndc_number'] . ')';
    }
    return $drugs;
}

// Get warehouses for dropdown
function getWarehousesList() {
    $sql = "SELECT option_id, title FROM list_options WHERE list_id = 'warehouse' AND activity = 1 ORDER BY seq, title";
    $result = sqlStatement($sql);
    $warehouses = array();
    while ($row = sqlFetchArray($result)) {
        $warehouses[$row['option_id']] = $row['title'];
    }
    return $warehouses;
}

// Get users for dropdown
function getUsersList() {
    $sql = "SELECT id, fname, lname FROM users WHERE active = 1 ORDER BY lname, fname";
    $result = sqlStatement($sql);
    $users = array();
    while ($row = sqlFetchArray($result)) {
        $users[$row['id']] = $row['lname'] . ', ' . $row['fname'];
    }
    return $users;
}

// Format currency
function formatCurrency($amount) {
    return $amount ? '$' . number_format($amount, 2) : '';
}

// Get movement type display name
function getMovementTypeDisplay($type) {
    $types = getMovementTypes();
    return isset($types[$type]) ? $types[$type] : $type;
}

// Get movement type CSS class
function getMovementTypeClass($type) {
    $classes = array(
        'inventory_add' => 'text-success',
        'inventory_update' => 'text-info',
        'inventory_delete' => 'text-danger',
        'lot_add' => 'text-success',
        'lot_update' => 'text-info',
        'lot_delete' => 'text-danger',
        'purchase' => 'text-success',
        'sale' => 'text-danger',
        'transfer' => 'text-info',
        'adjustment' => 'text-warning',
        'return' => 'text-primary',
        'destruction' => 'text-danger',
        'expiration' => 'text-muted'
    );
    return isset($classes[$type]) ? $classes[$type] : '';
}

?>
<html>
<head>
    <title><?php echo xlt('Inventory Audit Log'); ?></title>
    <?php Header::setupHeader(['datetime-picker', 'report-helper']); ?>
    <style>
        .audit-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .audit-table th {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 8px;
            text-align: left;
            font-weight: bold;
        }
        .audit-table td {
            border: 1px solid #dee2e6;
            padding: 8px;
            vertical-align: top;
        }
        .audit-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .audit-table tr:hover {
            background-color: #e9ecef;
        }
        .movement-type {
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.9em;
        }
        .quantity-change {
            font-weight: bold;
        }
        .quantity-positive {
            color: #28a745;
        }
        .quantity-negative {
            color: #dc3545;
        }
        .quantity-zero {
            color: #6c757d;
        }
        .filter-section {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .summary-stats {
            display: flex;
            justify-content: space-around;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        .stat-card {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            text-align: center;
            min-width: 150px;
            margin: 5px;
        }
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #007bff;
        }
        .stat-label {
            color: #6c757d;
            font-size: 14px;
        }
        @media print {
            .filter-section, .summary-stats, .btn {
                display: none !important;
            }
        }
    </style>
</head>
<body class="body_top">
    <div class="container-fluid">
        <h2><?php echo xlt('Inventory Audit Log'); ?></h2>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="post" name="audit_form" id="audit_form">
                <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                
                <div class="row">
                    <div class="col-md-2">
                        <label for="start_date"><?php echo xlt('Start Date'); ?>:</label>
                        <input type="text" class="form-control datepicker" name="start_date" id="start_date" 
                               value="<?php echo attr(oeFormatShortDate($start_date)); ?>" />
                    </div>
                    <div class="col-md-2">
                        <label for="end_date"><?php echo xlt('End Date'); ?>:</label>
                        <input type="text" class="form-control datepicker" name="end_date" id="end_date" 
                               value="<?php echo attr(oeFormatShortDate($end_date)); ?>" />
                    </div>
                    <div class="col-md-2">
                        <label for="drug_id"><?php echo xlt('Product'); ?>:</label>
                        <select class="form-control" name="drug_id" id="drug_id">
                            <option value=""><?php echo xlt('All Products'); ?></option>
                            <?php
                            $drugs = getDrugsList();
                            foreach ($drugs as $id => $name) {
                                $selected = ($drug_id == $id) ? 'selected' : '';
                                echo "<option value='$id' $selected>" . text($name) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="warehouse_id"><?php echo xlt('Warehouse'); ?>:</label>
                        <select class="form-control" name="warehouse_id" id="warehouse_id">
                            <option value=""><?php echo xlt('All Warehouses'); ?></option>
                            <?php
                            $warehouses = getWarehousesList();
                            foreach ($warehouses as $id => $name) {
                                $selected = ($warehouse_id == $id) ? 'selected' : '';
                                echo "<option value='$id' $selected>" . text($name) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="movement_type"><?php echo xlt('Movement Type'); ?>:</label>
                        <select class="form-control" name="movement_type" id="movement_type">
                            <option value=""><?php echo xlt('All Types'); ?></option>
                            <?php
                            $types = getMovementTypes();
                            foreach ($types as $type => $label) {
                                $selected = ($movement_type == $type) ? 'selected' : '';
                                echo "<option value='$type' $selected>" . text($label) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="user_id"><?php echo xlt('User'); ?>:</label>
                        <select class="form-control" name="user_id" id="user_id">
                            <option value=""><?php echo xlt('All Users'); ?></option>
                            <?php
                            $users = getUsersList();
                            foreach ($users as $id => $name) {
                                $selected = ($user_id == $id) ? 'selected' : '';
                                echo "<option value='$id' $selected>" . text($name) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="limit"><?php echo xlt('Records Limit'); ?>:</label>
                        <select class="form-control" name="limit" id="limit">
                            <option value="50" <?php echo ($limit == 50) ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo ($limit == 100) ? 'selected' : ''; ?>>100</option>
                            <option value="250" <?php echo ($limit == 250) ? 'selected' : ''; ?>>250</option>
                            <option value="500" <?php echo ($limit == 500) ? 'selected' : ''; ?>>500</option>
                            <option value="1000" <?php echo ($limit == 1000) ? 'selected' : ''; ?>>1000</option>
                        </select>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-12">
                        <button type="submit" name="form_action" value="submit" class="btn btn-primary">
                            <i class="fa fa-search"></i> <?php echo xlt('Search'); ?>
                        </button>
                        <button type="submit" name="form_action" value="recent" class="btn btn-warning">
                            <i class="fa fa-clock-o"></i> <?php echo xlt('Show Recent Activity'); ?>
                        </button>
                        <button type="submit" name="form_action" value="export" class="btn btn-success">
                            <i class="fa fa-download"></i> <?php echo xlt('Export CSV'); ?>
                        </button>
                        <button type="button" onclick="window.print()" class="btn btn-info">
                            <i class="fa fa-print"></i> <?php echo xlt('Print'); ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($form_action == 'submit' || $form_action == 'export' || $form_action == 'recent' || empty($_POST)): ?>
            <?php
            list($sql, $params) = buildAuditQuery($start_date, $end_date, $drug_id, $warehouse_id, $movement_type, $user_id, $limit);
            $result = sqlStatement($sql, $params);
            
            // Calculate summary statistics
            $total_movements = 0;
            $total_quantity_changed = 0;
            $total_value_impact = 0;
            $movement_counts = array();
            $movement_values = array();
            
            $audit_data = array();
            while ($row = sqlFetchArray($result)) {
                $audit_data[] = $row;
                $total_movements++;
                $total_quantity_changed += abs($row['quantity_change']);
                $total_value_impact += $row['total_value'] ?: 0;
                
                $movement_counts[$row['movement_type']] = ($movement_counts[$row['movement_type']] ?? 0) + 1;
                $movement_values[$row['movement_type']] = ($movement_values[$row['movement_type']] ?? 0) + ($row['total_value'] ?: 0);
            }
            ?>
            
            <!-- Summary Statistics -->
            <div class="summary-stats">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_movements; ?></div>
                    <div class="stat-label"><?php echo xlt('Total Movements'); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($total_quantity_changed); ?></div>
                    <div class="stat-label"><?php echo xlt('Total Quantity Changed'); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo formatCurrency($total_value_impact); ?></div>
                    <div class="stat-label"><?php echo xlt('Total Value Impact'); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count(array_unique(array_column($audit_data, 'drug_id'))); ?></div>
                    <div class="stat-label"><?php echo xlt('Products Affected'); ?></div>
                </div>
            </div>

            <?php if ($form_action == 'export'): ?>
                <?php
                // Export CSV data
                foreach ($audit_data as $row) {
                    echo csvEscape(oeFormatDateTime($row['movement_date'])) . ',';
                    echo csvEscape($row['drug_name']) . ',';
                    echo csvEscape($row['lot_number']) . ',';
                    echo csvEscape(getMovementTypeDisplay($row['movement_type'])) . ',';
                    echo csvEscape($row['quantity_before']) . ',';
                    echo csvEscape($row['quantity_change']) . ',';
                    echo csvEscape($row['quantity_after']) . ',';
                    echo csvEscape($row['user_name'] ?: 'System') . ',';
                    echo csvEscape($row['ip_address'] ?: 'N/A') . ',';
                    echo csvEscape($row['reference_display']) . ',';
                    echo csvEscape($row['reason']) . ',';
                    echo csvEscape($row['notes']) . "\n";
                }
                ?>
            <?php else: ?>
                <!-- Audit Log Table -->
                <div class="table-responsive">
                    <table class="audit-table">
                        <thead>
                            <tr>
                                <th><?php echo xlt('Date/Time'); ?></th>
                                <th><?php echo xlt('Product'); ?></th>
                                <th><?php echo xlt('Lot Number'); ?></th>
                                <th><?php echo xlt('Movement Type'); ?></th>
                                <th><?php echo xlt('Quantity Before'); ?></th>
                                <th><?php echo xlt('Change'); ?></th>
                                <th><?php echo xlt('Quantity After'); ?></th>
                                <th><?php echo xlt('User'); ?></th>
                                <th><?php echo xlt('IP Address'); ?></th>
                                <th><?php echo xlt('Notes'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                                                            <?php if (empty($audit_data)): ?>
                                    <tr>
                                        <td colspan="12" class="text-center">
                                            <?php echo xlt('No audit records found for the selected criteria.'); ?>
                                        </td>
                                    </tr>
                            <?php else: ?>
                                <?php foreach ($audit_data as $row): ?>
                                    <tr>
                                        <td><?php echo text(oeFormatDateTime($row['movement_date'])); ?></td>
                                        <td>
                                            <strong><?php echo text($row['drug_name']); ?></strong><br>
                                            <small class="text-muted"><?php echo text($row['ndc_number']); ?></small>
                                        </td>
                                        <td><?php echo text($row['lot_number']); ?></td>
                                        <td>
                                            <span class="movement-type <?php echo getMovementTypeClass($row['movement_type']); ?>">
                                                <?php echo text(getMovementTypeDisplay($row['movement_type'])); ?>
                                            </span>
                                        </td>
                                        <td class="text-right"><?php echo text($row['quantity_before']); ?></td>
                                        <td class="text-right">
                                            <span class="quantity-change <?php 
                                                echo $row['quantity_change'] > 0 ? 'quantity-positive' : 
                                                    ($row['quantity_change'] < 0 ? 'quantity-negative' : 'quantity-zero'); 
                                            ?>">
                                                <?php echo $row['quantity_change'] > 0 ? '+' : ''; ?><?php echo text($row['quantity_change']); ?>
                                            </span>
                                        </td>
                                        <td class="text-right"><?php echo text($row['quantity_after']); ?></td>
                                        <td><?php echo text($row['user_name'] ?: 'System'); ?></td>
                                        <td><?php echo text($row['ip_address'] ?: 'N/A'); ?></td>
                                        <td><?php 
                                            // Build a summary for the Notes column
                                            $summary = getMovementTypeDisplay($row['movement_type']) . ' (';
                                            $summary .= ($row['quantity_change'] > 0 ? '+' : '') . $row['quantity_change'] . ')';
                                            
                                            // Add product name for lot modifications and deletions
                                            if (strpos($row['movement_type'], 'lot_') === 0) {
                                                $summary .= ' Product: ' . $row['drug_name'] . ';';
                                            }
                                            
                                            if (!empty($row['lot_number'])) {
                                                $summary .= ' Lot: ' . $row['lot_number'] . ';';
                                            }
                                            
                                            // Special handling for lot deletions
                                            if ($row['movement_type'] === 'lot_delete') {
                                                $summary = 'Lot Deleted - Product: ' . $row['drug_name'] . '; Lot: ' . $row['lot_number'];
                                                if (!empty($row['notes'])) {
                                                    $summary .= '; ' . $row['notes'];
                                                }
                                            } else {
                                                if (!empty($row['notes'])) {
                                                    $summary .= ' ' . $row['notes'];
                                                }
                                            }
                                            echo text(trim($summary));
                                        ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        $(document).ready(function() {
            // Initialize date pickers
            $('.datepicker').datetimepicker({
                <?php $datetimepicker_timepicker = false; ?>
                <?php $datetimepicker_showseconds = false; ?>
                <?php $datetimepicker_formatInput = true; ?>
                <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
                useCurrent: false
            });
            
            // Auto-submit form when filters change
            $('#drug_id, #warehouse_id, #movement_type, #user_id, #limit').change(function() {
                $('#audit_form').submit();
            });
            
            // Auto-submit form on page load to show recent activity
            <?php if (empty($_POST)): ?>
            $(document).ready(function() {
                // Set form action to 'recent' and submit
                $('input[name="form_action"]').val('recent');
                $('#audit_form').submit();
            });
            <?php endif; ?>
        });
    </script>
</body>
</html> 