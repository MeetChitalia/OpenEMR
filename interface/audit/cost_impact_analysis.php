<?php

/**
 * Cost Impact Analysis Report - Track financial implications of inventory movements
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
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Cost Impact Analysis")]);
    exit;
}

// Verify CSRF token
if (!empty($_POST)) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }
}

// Get filter parameters
$start_date = (!empty($_POST["start_date"])) ? DateToYYYYMMDD($_POST["start_date"]) : date('Y-m-d', strtotime('-30 days'));
$end_date = (!empty($_POST["end_date"])) ? DateToYYYYMMDD($_POST["end_date"]) : date('Y-m-d');
$drug_id = (!empty($_POST["drug_id"])) ? intval($_POST["drug_id"]) : '';
$warehouse_id = (!empty($_POST["warehouse_id"])) ? $_POST["warehouse_id"] : '';
$movement_type = (!empty($_POST["movement_type"])) ? $_POST["movement_type"] : '';
$group_by = (!empty($_POST["group_by"])) ? $_POST["group_by"] : 'day';
$form_action = $_POST['form_action'] ?? '';

// Get movement types
function getMovementTypes() {
    return array(
        'purchase' => xl('Purchase'),
        'sale' => xl('Sale'),
        'transfer' => xl('Transfer'),
        'adjustment' => xl('Adjustment'),
        'return' => xl('Return'),
        'destruction' => xl('Destruction'),
        'expiration' => xl('Expiration')
    );
}

// Get cost impact summary
function getCostImpactSummary($start_date, $end_date, $drug_id, $warehouse_id, $movement_type, $group_by) {
    $date_format = '';
    $group_clause = '';
    
    switch ($group_by) {
        case 'hour':
            $date_format = '%Y-%m-%d %H:00:00';
            $group_clause = 'DATE_FORMAT(movement_date, "%Y-%m-%d %H:00:00")';
            break;
        case 'day':
            $date_format = '%Y-%m-%d';
            $group_clause = 'DATE(movement_date)';
            break;
        case 'week':
            $date_format = '%Y-%u';
            $group_clause = 'YEARWEEK(movement_date)';
            break;
        case 'month':
            $date_format = '%Y-%m';
            $group_clause = 'DATE_FORMAT(movement_date, "%Y-%m")';
            break;
        default:
            $date_format = '%Y-%m-%d';
            $group_clause = 'DATE(movement_date)';
    }
    
    $sql = "SELECT 
                $group_clause as period,
                movement_type,
                COUNT(*) as movement_count,
                SUM(total_value) as total_cost_impact,
                AVG(total_value) as avg_cost_per_movement,
                SUM(CASE WHEN total_value > 0 THEN total_value ELSE 0 END) as positive_impact,
                SUM(CASE WHEN total_value < 0 THEN total_value ELSE 0 END) as negative_impact,
                SUM(ABS(quantity_change)) as total_quantity_moved,
                AVG(unit_cost) as avg_unit_cost
            FROM inventory_movement_log
            WHERE movement_date BETWEEN ? AND ?";
    
    $params = array($start_date . ' 00:00:00', $end_date . ' 23:59:59');
    
    if (!empty($drug_id)) {
        $sql .= " AND drug_id = ?";
        $params[] = $drug_id;
    }
    
    if (!empty($warehouse_id)) {
        $sql .= " AND warehouse_id = ?";
        $params[] = $warehouse_id;
    }
    
    if (!empty($movement_type)) {
        $sql .= " AND movement_type = ?";
        $params[] = $movement_type;
    }
    
    $sql .= " GROUP BY $group_clause, movement_type
              ORDER BY period DESC, movement_type";
    
    $result = sqlStatement($sql, $params);
    
    $summary = array();
    while ($row = sqlFetchArray($result)) {
        $summary[] = $row;
    }
    
    return $summary;
}

// Get top cost impact products
function getTopCostProducts($start_date, $end_date, $limit = 10) {
    $sql = "SELECT 
                d.name as drug_name,
                d.ndc_number,
                COUNT(*) as movement_count,
                SUM(iml.total_value) as total_cost_impact,
                AVG(iml.total_value) as avg_cost_per_movement,
                SUM(ABS(iml.quantity_change)) as total_quantity_moved,
                AVG(iml.unit_cost) as avg_unit_cost
            FROM inventory_movement_log iml
            JOIN drugs d ON d.drug_id = iml.drug_id
            WHERE iml.movement_date BETWEEN ? AND ?
            GROUP BY iml.drug_id, d.name, d.ndc_number
            ORDER BY ABS(SUM(iml.total_value)) DESC
            LIMIT ?";
    
    $result = sqlStatement($sql, array($start_date . ' 00:00:00', $end_date . ' 23:59:59', $limit));
    
    $products = array();
    while ($row = sqlFetchArray($result)) {
        $products[] = $row;
    }
    
    return $products;
}

// Get cost trends over time
function getCostTrends($start_date, $end_date, $group_by = 'day') {
    $date_format = '';
    $group_clause = '';
    
    switch ($group_by) {
        case 'hour':
            $group_clause = 'DATE_FORMAT(movement_date, "%Y-%m-%d %H:00:00")';
            break;
        case 'day':
            $group_clause = 'DATE(movement_date)';
            break;
        case 'week':
            $group_clause = 'YEARWEEK(movement_date)';
            break;
        case 'month':
            $group_clause = 'DATE_FORMAT(movement_date, "%Y-%m")';
            break;
        default:
            $group_clause = 'DATE(movement_date)';
    }
    
    $sql = "SELECT 
                $group_clause as period,
                SUM(total_value) as daily_cost_impact,
                COUNT(*) as movement_count,
                AVG(total_value) as avg_cost_per_movement
            FROM inventory_movement_log
            WHERE movement_date BETWEEN ? AND ?
            GROUP BY $group_clause
            ORDER BY period";
    
    $result = sqlStatement($sql, array($start_date . ' 00:00:00', $end_date . ' 23:59:59'));
    
    $trends = array();
    while ($row = sqlFetchArray($result)) {
        $trends[] = $row;
    }
    
    return $trends;
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

// Format currency
function formatCurrency($amount) {
    return $amount ? '$' . number_format($amount, 2) : '$0.00';
}

// Get movement type display name
function getMovementTypeDisplay($type) {
    $types = getMovementTypes();
    return isset($types[$type]) ? $types[$type] : $type;
}

// Get movement type CSS class
function getMovementTypeClass($type) {
    $classes = array(
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

// Get cost impact CSS class
function getCostImpactClass($amount) {
    if ($amount > 0) {
        return 'text-success';
    } elseif ($amount < 0) {
        return 'text-danger';
    } else {
        return 'text-muted';
    }
}

?>
<html>
<head>
    <title><?php echo xlt('Cost Impact Analysis'); ?></title>
    <?php Header::setupHeader(['datetime-picker', 'report-helper']); ?>
    <style>
        .cost-card {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .cost-number {
            font-size: 32px;
            font-weight: bold;
        }
        .cost-label {
            color: #6c757d;
            font-size: 14px;
            margin-top: 5px;
        }
        .cost-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .cost-table th {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 8px;
            text-align: left;
            font-weight: bold;
        }
        .cost-table td {
            border: 1px solid #dee2e6;
            padding: 8px;
            vertical-align: top;
        }
        .cost-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .movement-type {
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.9em;
        }
        .filter-section {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .trend-chart {
            height: 300px;
            margin: 20px 0;
        }
        .cost-positive {
            color: #28a745;
        }
        .cost-negative {
            color: #dc3545;
        }
        .cost-neutral {
            color: #6c757d;
        }
        @media print {
            .filter-section, .btn {
                display: none !important;
            }
        }
    </style>
</head>
<body class="body_top">
    <div class="container-fluid">
        <h2><?php echo xlt('Cost Impact Analysis'); ?></h2>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="post" name="cost_form" id="cost_form">
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
                        <label for="group_by"><?php echo xlt('Group By'); ?>:</label>
                        <select class="form-control" name="group_by" id="group_by">
                            <option value="hour" <?php echo $group_by == 'hour' ? 'selected' : ''; ?>><?php echo xlt('Hour'); ?></option>
                            <option value="day" <?php echo $group_by == 'day' ? 'selected' : ''; ?>><?php echo xlt('Day'); ?></option>
                            <option value="week" <?php echo $group_by == 'week' ? 'selected' : ''; ?>><?php echo xlt('Week'); ?></option>
                            <option value="month" <?php echo $group_by == 'month' ? 'selected' : ''; ?>><?php echo xlt('Month'); ?></option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label>&nbsp;</label><br>
                        <button type="submit" name="form_action" value="submit" class="btn btn-primary">
                            <i class="fa fa-search"></i> <?php echo xlt('Analyze'); ?>
                        </button>
                        <button type="submit" name="form_action" value="export" class="btn btn-success">
                            <i class="fa fa-download"></i> <?php echo xlt('Export'); ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($form_action == 'submit' || $form_action == 'export'): ?>
            <?php
            $cost_summary = getCostImpactSummary($start_date, $end_date, $drug_id, $warehouse_id, $movement_type, $group_by);
            $top_products = getTopCostProducts($start_date, $end_date);
            $cost_trends = getCostTrends($start_date, $end_date, $group_by);
            
            // Calculate overall statistics
            $total_cost_impact = 0;
            $total_positive_impact = 0;
            $total_negative_impact = 0;
            $total_movements = 0;
            $avg_cost_per_movement = 0;
            
            foreach ($cost_summary as $row) {
                $total_cost_impact += $row['total_cost_impact'];
                $total_positive_impact += $row['positive_impact'];
                $total_negative_impact += $row['negative_impact'];
                $total_movements += $row['movement_count'];
            }
            
            $avg_cost_per_movement = $total_movements > 0 ? $total_cost_impact / $total_movements : 0;
            ?>
            
            <!-- Overall Cost Summary -->
            <div class="row">
                <div class="col-md-2">
                    <div class="cost-card text-center">
                        <div class="cost-number <?php echo getCostImpactClass($total_cost_impact); ?>">
                            <?php echo formatCurrency($total_cost_impact); ?>
                        </div>
                        <div class="cost-label"><?php echo xlt('Total Cost Impact'); ?></div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="cost-card text-center">
                        <div class="cost-number cost-positive">
                            <?php echo formatCurrency($total_positive_impact); ?>
                        </div>
                        <div class="cost-label"><?php echo xlt('Positive Impact'); ?></div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="cost-card text-center">
                        <div class="cost-number cost-negative">
                            <?php echo formatCurrency($total_negative_impact); ?>
                        </div>
                        <div class="cost-label"><?php echo xlt('Negative Impact'); ?></div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="cost-card text-center">
                        <div class="cost-number">
                            <?php echo number_format($total_movements); ?>
                        </div>
                        <div class="cost-label"><?php echo xlt('Total Movements'); ?></div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="cost-card text-center">
                        <div class="cost-number <?php echo getCostImpactClass($avg_cost_per_movement); ?>">
                            <?php echo formatCurrency($avg_cost_per_movement); ?>
                        </div>
                        <div class="cost-label"><?php echo xlt('Avg Cost/Movement'); ?></div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="cost-card text-center">
                        <div class="cost-number">
                            <?php echo count($top_products); ?>
                        </div>
                        <div class="cost-label"><?php echo xlt('Products Analyzed'); ?></div>
                    </div>
                </div>
            </div>

            <?php if ($form_action == 'export'): ?>
                <?php
                // Export CSV data
                echo csvEscape(xl('Period')) . ',';
                echo csvEscape(xl('Movement Type')) . ',';
                echo csvEscape(xl('Movement Count')) . ',';
                echo csvEscape(xl('Total Cost Impact')) . ',';
                echo csvEscape(xl('Avg Cost per Movement')) . ',';
                echo csvEscape(xl('Positive Impact')) . ',';
                echo csvEscape(xl('Negative Impact')) . ',';
                echo csvEscape(xl('Total Quantity Moved')) . ',';
                echo csvEscape(xl('Avg Unit Cost')) . "\n";
                
                foreach ($cost_summary as $row) {
                    echo csvEscape($row['period']) . ',';
                    echo csvEscape(getMovementTypeDisplay($row['movement_type'])) . ',';
                    echo csvEscape($row['movement_count']) . ',';
                    echo csvEscape(formatCurrency($row['total_cost_impact'])) . ',';
                    echo csvEscape(formatCurrency($row['avg_cost_per_movement'])) . ',';
                    echo csvEscape(formatCurrency($row['positive_impact'])) . ',';
                    echo csvEscape(formatCurrency($row['negative_impact'])) . ',';
                    echo csvEscape($row['total_quantity_moved']) . ',';
                    echo csvEscape(formatCurrency($row['avg_unit_cost'])) . "\n";
                }
                ?>
            <?php else: ?>
                <!-- Cost Impact Summary Table -->
                <div class="cost-card">
                    <h4><?php echo xlt('Cost Impact Summary by'); ?> <?php echo xlt(ucfirst($group_by)); ?></h4>
                    <div class="table-responsive">
                        <table class="cost-table">
                            <thead>
                                <tr>
                                    <th><?php echo xlt('Period'); ?></th>
                                    <th><?php echo xlt('Movement Type'); ?></th>
                                    <th><?php echo xlt('Count'); ?></th>
                                    <th><?php echo xlt('Total Cost Impact'); ?></th>
                                    <th><?php echo xlt('Avg Cost/Movement'); ?></th>
                                    <th><?php echo xlt('Positive Impact'); ?></th>
                                    <th><?php echo xlt('Negative Impact'); ?></th>
                                    <th><?php echo xlt('Quantity Moved'); ?></th>
                                    <th><?php echo xlt('Avg Unit Cost'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($cost_summary)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center">
                                            <?php echo xlt('No cost impact data found for the selected criteria.'); ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($cost_summary as $row): ?>
                                        <tr>
                                            <td><?php echo text($row['period']); ?></td>
                                            <td>
                                                <span class="movement-type <?php echo getMovementTypeClass($row['movement_type']); ?>">
                                                    <?php echo text(getMovementTypeDisplay($row['movement_type'])); ?>
                                                </span>
                                            </td>
                                            <td class="text-right"><?php echo text($row['movement_count']); ?></td>
                                            <td class="text-right">
                                                <span class="<?php echo getCostImpactClass($row['total_cost_impact']); ?>">
                                                    <?php echo text(formatCurrency($row['total_cost_impact'])); ?>
                                                </span>
                                            </td>
                                            <td class="text-right">
                                                <span class="<?php echo getCostImpactClass($row['avg_cost_per_movement']); ?>">
                                                    <?php echo text(formatCurrency($row['avg_cost_per_movement'])); ?>
                                                </span>
                                            </td>
                                            <td class="text-right cost-positive"><?php echo text(formatCurrency($row['positive_impact'])); ?></td>
                                            <td class="text-right cost-negative"><?php echo text(formatCurrency($row['negative_impact'])); ?></td>
                                            <td class="text-right"><?php echo text(number_format($row['total_quantity_moved'])); ?></td>
                                            <td class="text-right"><?php echo text(formatCurrency($row['avg_unit_cost'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Top Cost Impact Products -->
                <div class="cost-card">
                    <h4><?php echo xlt('Top Products by Cost Impact'); ?></h4>
                    <div class="table-responsive">
                        <table class="cost-table">
                            <thead>
                                <tr>
                                    <th><?php echo xlt('Product'); ?></th>
                                    <th><?php echo xlt('Movements'); ?></th>
                                    <th><?php echo xlt('Total Cost Impact'); ?></th>
                                    <th><?php echo xlt('Avg Cost/Movement'); ?></th>
                                    <th><?php echo xlt('Quantity Moved'); ?></th>
                                    <th><?php echo xlt('Avg Unit Cost'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top_products as $product): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo text($product['drug_name']); ?></strong><br>
                                            <small class="text-muted"><?php echo text($product['ndc_number']); ?></small>
                                        </td>
                                        <td class="text-right"><?php echo text($product['movement_count']); ?></td>
                                        <td class="text-right">
                                            <span class="<?php echo getCostImpactClass($product['total_cost_impact']); ?>">
                                                <?php echo text(formatCurrency($product['total_cost_impact'])); ?>
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <span class="<?php echo getCostImpactClass($product['avg_cost_per_movement']); ?>">
                                                <?php echo text(formatCurrency($product['avg_cost_per_movement'])); ?>
                                            </span>
                                        </td>
                                        <td class="text-right"><?php echo text(number_format($product['total_quantity_moved'])); ?></td>
                                        <td class="text-right"><?php echo text(formatCurrency($product['avg_unit_cost'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Cost Trends -->
                <div class="cost-card">
                    <h4><?php echo xlt('Cost Trends Over Time'); ?></h4>
                    <div class="table-responsive">
                        <table class="cost-table">
                            <thead>
                                <tr>
                                    <th><?php echo xlt('Period'); ?></th>
                                    <th><?php echo xlt('Daily Cost Impact'); ?></th>
                                    <th><?php echo xlt('Movement Count'); ?></th>
                                    <th><?php echo xlt('Avg Cost/Movement'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cost_trends as $trend): ?>
                                    <tr>
                                        <td><?php echo text($trend['period']); ?></td>
                                        <td class="text-right">
                                            <span class="<?php echo getCostImpactClass($trend['daily_cost_impact']); ?>">
                                                <?php echo text(formatCurrency($trend['daily_cost_impact'])); ?>
                                            </span>
                                        </td>
                                        <td class="text-right"><?php echo text($trend['movement_count']); ?></td>
                                        <td class="text-right">
                                            <span class="<?php echo getCostImpactClass($trend['avg_cost_per_movement']); ?>">
                                                <?php echo text(formatCurrency($trend['avg_cost_per_movement'])); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
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
            
            // Auto-submit form when group by changes
            $('#group_by').change(function() {
                $('#cost_form').submit();
            });
        });
    </script>
</body>
</html> 