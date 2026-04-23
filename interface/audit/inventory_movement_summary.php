<?php

/**
 * Inventory Movement Summary Report
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
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Inventory Movement Summary")]);
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

// Get summary data
function getMovementSummary($start_date, $end_date, $group_by) {
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
                SUM(ABS(quantity_change)) as total_quantity_moved,
                SUM(CASE WHEN quantity_change > 0 THEN quantity_change ELSE 0 END) as quantity_in,
                SUM(CASE WHEN quantity_change < 0 THEN ABS(quantity_change) ELSE 0 END) as quantity_out,
                SUM(total_value) as total_value_impact,
                COUNT(DISTINCT drug_id) as unique_products,
                COUNT(DISTINCT user_id) as unique_users
            FROM inventory_movement_log
            WHERE movement_date BETWEEN ? AND ?
            GROUP BY $group_clause, movement_type
            ORDER BY period DESC, movement_type";
    
    $result = sqlStatement($sql, array($start_date . ' 00:00:00', $end_date . ' 23:59:59'));
    
    $summary = array();
    while ($row = sqlFetchArray($result)) {
        $summary[] = $row;
    }
    
    return $summary;
}

// Get top products by movement
function getTopProducts($start_date, $end_date, $limit = 10) {
    $sql = "SELECT 
                d.name as drug_name,
                d.ndc_number,
                COUNT(*) as movement_count,
                SUM(ABS(iml.quantity_change)) as total_quantity_moved,
                SUM(iml.total_value) as total_value_impact
            FROM inventory_movement_log iml
            JOIN drugs d ON d.drug_id = iml.drug_id
            WHERE iml.movement_date BETWEEN ? AND ?
            GROUP BY iml.drug_id, d.name, d.ndc_number
            ORDER BY movement_count DESC
            LIMIT ?";
    
    $result = sqlStatement($sql, array($start_date . ' 00:00:00', $end_date . ' 23:59:59', $limit));
    
    $products = array();
    while ($row = sqlFetchArray($result)) {
        $products[] = $row;
    }
    
    return $products;
}

// Get top users by activity
function getTopUsers($start_date, $end_date, $limit = 10) {
    $sql = "SELECT 
                u.fname,
                u.lname,
                COUNT(*) as movement_count,
                SUM(ABS(iml.quantity_change)) as total_quantity_moved,
                SUM(iml.total_value) as total_value_impact
            FROM inventory_movement_log iml
            JOIN users u ON u.id = iml.user_id
            WHERE iml.movement_date BETWEEN ? AND ?
            GROUP BY iml.user_id, u.fname, u.lname
            ORDER BY movement_count DESC
            LIMIT ?";
    
    $result = sqlStatement($sql, array($start_date . ' 00:00:00', $end_date . ' 23:59:59', $limit));
    
    $users = array();
    while ($row = sqlFetchArray($result)) {
        $users[] = $row;
    }
    
    return $users;
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

?>
<html>
<head>
    <title><?php echo xlt('Inventory Movement Summary'); ?></title>
    <?php Header::setupHeader(['datetime-picker', 'report-helper']); ?>
    <style>
        .summary-card {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .summary-number {
            font-size: 32px;
            font-weight: bold;
            color: #007bff;
        }
        .summary-label {
            color: #6c757d;
            font-size: 14px;
            margin-top: 5px;
        }
        .movement-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .movement-table th {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 8px;
            text-align: left;
            font-weight: bold;
        }
        .movement-table td {
            border: 1px solid #dee2e6;
            padding: 8px;
            vertical-align: top;
        }
        .movement-table tr:nth-child(even) {
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
        .chart-container {
            height: 300px;
            margin: 20px 0;
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
        <h2><?php echo xlt('Inventory Movement Summary'); ?></h2>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="post" name="summary_form" id="summary_form">
                <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                
                <div class="row">
                    <div class="col-md-3">
                        <label for="start_date"><?php echo xlt('Start Date'); ?>:</label>
                        <input type="text" class="form-control datepicker" name="start_date" id="start_date" 
                               value="<?php echo attr(oeFormatShortDate($start_date)); ?>" />
                    </div>
                    <div class="col-md-3">
                        <label for="end_date"><?php echo xlt('End Date'); ?>:</label>
                        <input type="text" class="form-control datepicker" name="end_date" id="end_date" 
                               value="<?php echo attr(oeFormatShortDate($end_date)); ?>" />
                    </div>
                    <div class="col-md-3">
                        <label for="group_by"><?php echo xlt('Group By'); ?>:</label>
                        <select class="form-control" name="group_by" id="group_by">
                            <option value="hour" <?php echo $group_by == 'hour' ? 'selected' : ''; ?>><?php echo xlt('Hour'); ?></option>
                            <option value="day" <?php echo $group_by == 'day' ? 'selected' : ''; ?>><?php echo xlt('Day'); ?></option>
                            <option value="week" <?php echo $group_by == 'week' ? 'selected' : ''; ?>><?php echo xlt('Week'); ?></option>
                            <option value="month" <?php echo $group_by == 'month' ? 'selected' : ''; ?>><?php echo xlt('Month'); ?></option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>&nbsp;</label><br>
                        <button type="submit" name="form_action" value="submit" class="btn btn-primary">
                            <i class="fa fa-search"></i> <?php echo xlt('Generate Report'); ?>
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
            $summary_data = getMovementSummary($start_date, $end_date, $group_by);
            $top_products = getTopProducts($start_date, $end_date);
            $top_users = getTopUsers($start_date, $end_date);
            
            // Calculate overall statistics
            $total_movements = 0;
            $total_quantity = 0;
            $total_value = 0;
            $unique_products = 0;
            $unique_users = 0;
            
            foreach ($summary_data as $row) {
                $total_movements += $row['movement_count'];
                $total_quantity += $row['total_quantity_moved'];
                $total_value += $row['total_value_impact'];
                $unique_products = max($unique_products, $row['unique_products']);
                $unique_users = max($unique_users, $row['unique_users']);
            }
            ?>
            
            <!-- Overall Summary -->
            <div class="row">
                <div class="col-md-2">
                    <div class="summary-card text-center">
                        <div class="summary-number"><?php echo number_format($total_movements); ?></div>
                        <div class="summary-label"><?php echo xlt('Total Movements'); ?></div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="summary-card text-center">
                        <div class="summary-number"><?php echo number_format($total_quantity); ?></div>
                        <div class="summary-label"><?php echo xlt('Total Quantity Moved'); ?></div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="summary-card text-center">
                        <div class="summary-number"><?php echo formatCurrency($total_value); ?></div>
                        <div class="summary-label"><?php echo xlt('Total Value Impact'); ?></div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="summary-card text-center">
                        <div class="summary-number"><?php echo $unique_products; ?></div>
                        <div class="summary-label"><?php echo xlt('Products Affected'); ?></div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="summary-card text-center">
                        <div class="summary-number"><?php echo $unique_users; ?></div>
                        <div class="summary-label"><?php echo xlt('Users Active'); ?></div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="summary-card text-center">
                        <div class="summary-number"><?php echo count($summary_data); ?></div>
                        <div class="summary-label"><?php echo xlt('Time Periods'); ?></div>
                    </div>
                </div>
            </div>

            <?php if ($form_action == 'export'): ?>
                <?php
                // Export CSV data
                echo csvEscape(xl('Period')) . ',';
                echo csvEscape(xl('Movement Type')) . ',';
                echo csvEscape(xl('Movement Count')) . ',';
                echo csvEscape(xl('Total Quantity Moved')) . ',';
                echo csvEscape(xl('Quantity In')) . ',';
                echo csvEscape(xl('Quantity Out')) . ',';
                echo csvEscape(xl('Total Value Impact')) . ',';
                echo csvEscape(xl('Unique Products')) . ',';
                echo csvEscape(xl('Unique Users')) . "\n";
                
                foreach ($summary_data as $row) {
                    echo csvEscape($row['period']) . ',';
                    echo csvEscape(getMovementTypeDisplay($row['movement_type'])) . ',';
                    echo csvEscape($row['movement_count']) . ',';
                    echo csvEscape($row['total_quantity_moved']) . ',';
                    echo csvEscape($row['quantity_in']) . ',';
                    echo csvEscape($row['quantity_out']) . ',';
                    echo csvEscape(formatCurrency($row['total_value_impact'])) . ',';
                    echo csvEscape($row['unique_products']) . ',';
                    echo csvEscape($row['unique_users']) . "\n";
                }
                ?>
            <?php else: ?>
                <!-- Movement Summary Table -->
                <div class="summary-card">
                    <h4><?php echo xlt('Movement Summary by'); ?> <?php echo xlt(ucfirst($group_by)); ?></h4>
                    <div class="table-responsive">
                        <table class="movement-table">
                            <thead>
                                <tr>
                                    <th><?php echo xlt('Period'); ?></th>
                                    <th><?php echo xlt('Movement Type'); ?></th>
                                    <th><?php echo xlt('Count'); ?></th>
                                    <th><?php echo xlt('Total Quantity'); ?></th>
                                    <th><?php echo xlt('Quantity In'); ?></th>
                                    <th><?php echo xlt('Quantity Out'); ?></th>
                                    <th><?php echo xlt('Value Impact'); ?></th>
                                    <th><?php echo xlt('Products'); ?></th>
                                    <th><?php echo xlt('Users'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($summary_data)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center">
                                            <?php echo xlt('No movement data found for the selected criteria.'); ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($summary_data as $row): ?>
                                        <tr>
                                            <td><?php echo text($row['period']); ?></td>
                                            <td>
                                                <span class="movement-type <?php echo getMovementTypeClass($row['movement_type']); ?>">
                                                    <?php echo text(getMovementTypeDisplay($row['movement_type'])); ?>
                                                </span>
                                            </td>
                                            <td class="text-right"><?php echo text($row['movement_count']); ?></td>
                                            <td class="text-right"><?php echo text(number_format($row['total_quantity_moved'])); ?></td>
                                            <td class="text-right text-success"><?php echo text(number_format($row['quantity_in'])); ?></td>
                                            <td class="text-right text-danger"><?php echo text(number_format($row['quantity_out'])); ?></td>
                                            <td class="text-right"><?php echo text(formatCurrency($row['total_value_impact'])); ?></td>
                                            <td class="text-right"><?php echo text($row['unique_products']); ?></td>
                                            <td class="text-right"><?php echo text($row['unique_users']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Top Products -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="summary-card">
                            <h4><?php echo xlt('Top Products by Movement Count'); ?></h4>
                            <div class="table-responsive">
                                <table class="movement-table">
                                    <thead>
                                        <tr>
                                            <th><?php echo xlt('Product'); ?></th>
                                            <th><?php echo xlt('Movements'); ?></th>
                                            <th><?php echo xlt('Quantity Moved'); ?></th>
                                            <th><?php echo xlt('Value Impact'); ?></th>
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
                                                <td class="text-right"><?php echo text(number_format($product['total_quantity_moved'])); ?></td>
                                                <td class="text-right"><?php echo text(formatCurrency($product['total_value_impact'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="summary-card">
                            <h4><?php echo xlt('Top Users by Activity'); ?></h4>
                            <div class="table-responsive">
                                <table class="movement-table">
                                    <thead>
                                        <tr>
                                            <th><?php echo xlt('User'); ?></th>
                                            <th><?php echo xlt('Movements'); ?></th>
                                            <th><?php echo xlt('Quantity Moved'); ?></th>
                                            <th><?php echo xlt('Value Impact'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($top_users as $user): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo text($user['lname'] . ', ' . $user['fname']); ?></strong>
                                                </td>
                                                <td class="text-right"><?php echo text($user['movement_count']); ?></td>
                                                <td class="text-right"><?php echo text(number_format($user['total_quantity_moved'])); ?></td>
                                                <td class="text-right"><?php echo text(formatCurrency($user['total_value_impact'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        $(document).ready(function() {
            // Initialize date pickers
            $('.datepicker').datetimepicker({
                format: '<?php echo $DateFormat; ?>',
                locale: '<?php echo $Locale; ?>',
                useCurrent: false
            });
            
            // Auto-submit form when group by changes
            $('#group_by').change(function() {
                $('#summary_form').submit();
            });
        });
    </script>
</body>
</html> 