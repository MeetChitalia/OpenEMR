<?php

/**
 * User Activity Report - Track individual user actions in inventory system
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
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("User Activity Report")]);
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
$user_id = (!empty($_POST["user_id"])) ? $_POST["user_id"] : '';
$movement_type = (!empty($_POST["movement_type"])) ? $_POST["movement_type"] : '';
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

// Get user activity summary
function getUserActivitySummary($start_date, $end_date, $user_id = '', $movement_type = '') {
    $sql = "SELECT 
                u.id as user_id,
                u.fname,
                u.lname,
                u.username,
                COUNT(*) as total_movements,
                SUM(ABS(iml.quantity_change)) as total_quantity_moved,
                SUM(iml.total_value) as total_value_impact,
                COUNT(DISTINCT iml.drug_id) as unique_products,
                COUNT(DISTINCT DATE(iml.movement_date)) as active_days,
                MIN(iml.movement_date) as first_activity,
                MAX(iml.movement_date) as last_activity,
                AVG(ABS(iml.quantity_change)) as avg_quantity_per_movement
            FROM inventory_movement_log iml
            JOIN users u ON u.id = iml.user_id
            WHERE iml.movement_date BETWEEN ? AND ?";
    
    $params = array($start_date . ' 00:00:00', $end_date . ' 23:59:59');
    
    if (!empty($user_id)) {
        $sql .= " AND iml.user_id = ?";
        $params[] = $user_id;
    }
    
    if (!empty($movement_type)) {
        $sql .= " AND iml.movement_type = ?";
        $params[] = $movement_type;
    }
    
    $sql .= " GROUP BY u.id, u.fname, u.lname, u.username
              ORDER BY total_movements DESC";
    
    $result = sqlStatement($sql, $params);
    
    $summary = array();
    while ($row = sqlFetchArray($result)) {
        $summary[] = $row;
    }
    
    return $summary;
}

// Get user detailed activity
function getUserDetailedActivity($start_date, $end_date, $user_id = '', $movement_type = '') {
    $sql = "SELECT 
                iml.*,
                d.name as drug_name,
                d.ndc_number,
                lo.title as warehouse_name,
                u.fname,
                u.lname,
                u.username
            FROM inventory_movement_log iml
            JOIN drugs d ON d.drug_id = iml.drug_id
            LEFT JOIN list_options lo ON lo.list_id = 'warehouse' AND lo.option_id = iml.warehouse_id
            JOIN users u ON u.id = iml.user_id
            WHERE iml.movement_date BETWEEN ? AND ?";
    
    $params = array($start_date . ' 00:00:00', $end_date . ' 23:59:59');
    
    if (!empty($user_id)) {
        $sql .= " AND iml.user_id = ?";
        $params[] = $user_id;
    }
    
    if (!empty($movement_type)) {
        $sql .= " AND iml.movement_type = ?";
        $params[] = $movement_type;
    }
    
    $sql .= " ORDER BY iml.movement_date DESC, iml.log_id DESC";
    
    $result = sqlStatement($sql, $params);
    
    $activity = array();
    while ($row = sqlFetchArray($result)) {
        $activity[] = $row;
    }
    
    return $activity;
}

// Get user activity by hour
function getUserActivityByHour($start_date, $end_date, $user_id = '') {
    $sql = "SELECT 
                HOUR(movement_date) as hour,
                COUNT(*) as movement_count,
                SUM(ABS(quantity_change)) as total_quantity,
                SUM(total_value) as total_value
            FROM inventory_movement_log
            WHERE movement_date BETWEEN ? AND ?";
    
    $params = array($start_date . ' 00:00:00', $end_date . ' 23:59:59');
    
    if (!empty($user_id)) {
        $sql .= " AND user_id = ?";
        $params[] = $user_id;
    }
    
    $sql .= " GROUP BY HOUR(movement_date)
              ORDER BY hour";
    
    $result = sqlStatement($sql, $params);
    
    $hourly = array();
    while ($row = sqlFetchArray($result)) {
        $hourly[] = $row;
    }
    
    return $hourly;
}

// Get users for dropdown
function getUsersList() {
    $sql = "SELECT DISTINCT u.id, u.fname, u.lname, u.username 
            FROM users u 
            JOIN inventory_movement_log iml ON u.id = iml.user_id 
            WHERE u.active = 1 
            ORDER BY u.lname, u.fname";
    $result = sqlStatement($sql);
    $users = array();
    while ($row = sqlFetchArray($result)) {
        $users[$row['id']] = $row['lname'] . ', ' . $row['fname'] . ' (' . $row['username'] . ')';
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
    <title><?php echo xlt('User Activity Report'); ?></title>
    <?php Header::setupHeader(['datetime-picker', 'report-helper']); ?>
    <style>
        .activity-card {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .activity-number {
            font-size: 24px;
            font-weight: bold;
            color: #007bff;
        }
        .activity-label {
            color: #6c757d;
            font-size: 14px;
            margin-top: 5px;
        }
        .activity-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .activity-table th {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 8px;
            text-align: left;
            font-weight: bold;
        }
        .activity-table td {
            border: 1px solid #dee2e6;
            padding: 8px;
            vertical-align: top;
        }
        .activity-table tr:nth-child(even) {
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
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #007bff;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 10px;
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
        @media print {
            .filter-section, .btn {
                display: none !important;
            }
        }
    </style>
</head>
<body class="body_top">
    <div class="container-fluid">
        <h2><?php echo xlt('User Activity Report'); ?></h2>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="post" name="activity_form" id="activity_form">
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
                    <div class="col-md-3">
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
                    <div class="col-md-3">
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
                        <label>&nbsp;</label><br>
                        <button type="submit" name="form_action" value="submit" class="btn btn-primary">
                            <i class="fa fa-search"></i> <?php echo xlt('Search'); ?>
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
            $user_summary = getUserActivitySummary($start_date, $end_date, $user_id, $movement_type);
            $detailed_activity = getUserDetailedActivity($start_date, $end_date, $user_id, $movement_type);
            $hourly_activity = getUserActivityByHour($start_date, $end_date, $user_id);
            
            // Calculate overall statistics
            $total_users = count($user_summary);
            $total_movements = 0;
            $total_quantity = 0;
            $total_value = 0;
            
            foreach ($user_summary as $user) {
                $total_movements += $user['total_movements'];
                $total_quantity += $user['total_quantity_moved'];
                $total_value += $user['total_value_impact'];
            }
            ?>
            
            <!-- Overall Summary -->
            <div class="row">
                <div class="col-md-3">
                    <div class="activity-card text-center">
                        <div class="activity-number"><?php echo $total_users; ?></div>
                        <div class="activity-label"><?php echo xlt('Active Users'); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="activity-card text-center">
                        <div class="activity-number"><?php echo number_format($total_movements); ?></div>
                        <div class="activity-label"><?php echo xlt('Total Movements'); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="activity-card text-center">
                        <div class="activity-number"><?php echo number_format($total_quantity); ?></div>
                        <div class="activity-label"><?php echo xlt('Total Quantity Moved'); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="activity-card text-center">
                        <div class="activity-number"><?php echo formatCurrency($total_value); ?></div>
                        <div class="activity-label"><?php echo xlt('Total Value Impact'); ?></div>
                    </div>
                </div>
            </div>

            <?php if ($form_action == 'export'): ?>
                <?php
                // Export CSV data
                echo csvEscape(xl('User')) . ',';
                echo csvEscape(xl('Username')) . ',';
                echo csvEscape(xl('Total Movements')) . ',';
                echo csvEscape(xl('Total Quantity Moved')) . ',';
                echo csvEscape(xl('Total Value Impact')) . ',';
                echo csvEscape(xl('Unique Products')) . ',';
                echo csvEscape(xl('Active Days')) . ',';
                echo csvEscape(xl('First Activity')) . ',';
                echo csvEscape(xl('Last Activity')) . ',';
                echo csvEscape(xl('Avg Quantity per Movement')) . "\n";
                
                foreach ($user_summary as $user) {
                    echo csvEscape($user['lname'] . ', ' . $user['fname']) . ',';
                    echo csvEscape($user['username']) . ',';
                    echo csvEscape($user['total_movements']) . ',';
                    echo csvEscape($user['total_quantity_moved']) . ',';
                    echo csvEscape(formatCurrency($user['total_value_impact'])) . ',';
                    echo csvEscape($user['unique_products']) . ',';
                    echo csvEscape($user['active_days']) . ',';
                    echo csvEscape(oeFormatDateTime($user['first_activity'])) . ',';
                    echo csvEscape(oeFormatDateTime($user['last_activity'])) . ',';
                    echo csvEscape(number_format($user['avg_quantity_per_movement'], 2)) . "\n";
                }
                ?>
            <?php else: ?>
                <!-- User Summary Table -->
                <div class="activity-card">
                    <h4><?php echo xlt('User Activity Summary'); ?></h4>
                    <div class="table-responsive">
                        <table class="activity-table">
                            <thead>
                                <tr>
                                    <th><?php echo xlt('User'); ?></th>
                                    <th><?php echo xlt('Movements'); ?></th>
                                    <th><?php echo xlt('Quantity Moved'); ?></th>
                                    <th><?php echo xlt('Value Impact'); ?></th>
                                    <th><?php echo xlt('Products'); ?></th>
                                    <th><?php echo xlt('Active Days'); ?></th>
                                    <th><?php echo xlt('First Activity'); ?></th>
                                    <th><?php echo xlt('Last Activity'); ?></th>
                                    <th><?php echo xlt('Avg Qty/Movement'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($user_summary)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center">
                                            <?php echo xlt('No user activity found for the selected criteria.'); ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($user_summary as $user): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="user-avatar">
                                                        <?php echo strtoupper(substr($user['fname'], 0, 1) . substr($user['lname'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <strong><?php echo text($user['lname'] . ', ' . $user['fname']); ?></strong><br>
                                                        <small class="text-muted"><?php echo text($user['username']); ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-right"><?php echo text($user['total_movements']); ?></td>
                                            <td class="text-right"><?php echo text(number_format($user['total_quantity_moved'])); ?></td>
                                            <td class="text-right"><?php echo text(formatCurrency($user['total_value_impact'])); ?></td>
                                            <td class="text-right"><?php echo text($user['unique_products']); ?></td>
                                            <td class="text-right"><?php echo text($user['active_days']); ?></td>
                                            <td><?php echo text(oeFormatDateTime($user['first_activity'])); ?></td>
                                            <td><?php echo text(oeFormatDateTime($user['last_activity'])); ?></td>
                                            <td class="text-right"><?php echo text(number_format($user['avg_quantity_per_movement'], 2)); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Detailed Activity Table -->
                <div class="activity-card">
                    <h4><?php echo xlt('Detailed User Activity'); ?></h4>
                    <div class="table-responsive">
                        <table class="activity-table">
                            <thead>
                                <tr>
                                    <th><?php echo xlt('Date/Time'); ?></th>
                                    <th><?php echo xlt('User'); ?></th>
                                    <th><?php echo xlt('Product'); ?></th>
                                    <th><?php echo xlt('Movement Type'); ?></th>
                                    <th><?php echo xlt('Quantity Change'); ?></th>
                                    <th><?php echo xlt('Value Impact'); ?></th>
                                    <th><?php echo xlt('Warehouse'); ?></th>
                                    <th><?php echo xlt('Reason'); ?></th>
                                    <th><?php echo xlt('Notes'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($detailed_activity)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center">
                                            <?php echo xlt('No detailed activity found for the selected criteria.'); ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($detailed_activity as $activity): ?>
                                        <tr>
                                            <td><?php echo text(oeFormatDateTime($activity['movement_date'])); ?></td>
                                            <td>
                                                <strong><?php echo text($activity['lname'] . ', ' . $activity['fname']); ?></strong><br>
                                                <small class="text-muted"><?php echo text($activity['username']); ?></small>
                                            </td>
                                            <td>
                                                <strong><?php echo text($activity['drug_name']); ?></strong><br>
                                                <small class="text-muted"><?php echo text($activity['ndc_number']); ?></small>
                                            </td>
                                            <td>
                                                <span class="movement-type <?php echo getMovementTypeClass($activity['movement_type']); ?>">
                                                    <?php echo text(getMovementTypeDisplay($activity['movement_type'])); ?>
                                                </span>
                                            </td>
                                            <td class="text-right">
                                                <span class="quantity-change <?php 
                                                    echo $activity['quantity_change'] > 0 ? 'quantity-positive' : 
                                                        ($activity['quantity_change'] < 0 ? 'quantity-negative' : ''); 
                                                ?>">
                                                    <?php echo $activity['quantity_change'] > 0 ? '+' : ''; ?><?php echo text($activity['quantity_change']); ?>
                                                </span>
                                            </td>
                                            <td class="text-right"><?php echo text(formatCurrency($activity['total_value'])); ?></td>
                                            <td><?php echo text($activity['warehouse_name']); ?></td>
                                            <td><?php echo text($activity['reason']); ?></td>
                                            <td><?php echo text($activity['notes']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Hourly Activity Chart -->
                <div class="activity-card">
                    <h4><?php echo xlt('Activity by Hour of Day'); ?></h4>
                    <div class="table-responsive">
                        <table class="activity-table">
                            <thead>
                                <tr>
                                    <th><?php echo xlt('Hour'); ?></th>
                                    <th><?php echo xlt('Movement Count'); ?></th>
                                    <th><?php echo xlt('Total Quantity'); ?></th>
                                    <th><?php echo xlt('Total Value'); ?></th>
                                    <th><?php echo xlt('Percentage'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total_hourly_movements = 0;
                                foreach ($hourly_activity as $hour) {
                                    $total_hourly_movements += $hour['movement_count'];
                                }
                                ?>
                                <?php foreach ($hourly_activity as $hour): ?>
                                    <tr>
                                        <td><?php echo text($hour['hour'] . ':00'); ?></td>
                                        <td class="text-right"><?php echo text($hour['movement_count']); ?></td>
                                        <td class="text-right"><?php echo text(number_format($hour['total_quantity'])); ?></td>
                                        <td class="text-right"><?php echo text(formatCurrency($hour['total_value'])); ?></td>
                                        <td class="text-right">
                                            <?php 
                                            $percentage = $total_hourly_movements > 0 ? ($hour['movement_count'] / $total_hourly_movements) * 100 : 0;
                                            echo text(number_format($percentage, 1) . '%'); 
                                            ?>
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
            
            // Auto-submit form when filters change
            $('#user_id, #movement_type').change(function() {
                $('#activity_form').submit();
            });
        });
    </script>
</body>
</html> 