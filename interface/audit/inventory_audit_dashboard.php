<?php
/**
 * Inventory Audit Dashboard
 * 
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Meet Patel <meetpatel1798@gmail.com>
 * @copyright Copyright (c) 2024 Meet Patel
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("../../library/InventoryAuditLogger.class.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;

// Check authorizations
if (!AclMain::aclCheckCore('admin', 'drugs') && !AclMain::aclCheckCore('inventory', 'reporting')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Inventory Audit Dashboard")]);
    exit;
}

$audit_logger = new InventoryAuditLogger();

// Handle filters
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$drug_id = $_GET['drug_id'] ?? '';
$warehouse_id = $_GET['warehouse_id'] ?? '';
$movement_type = $_GET['movement_type'] ?? '';
$user_id = $_GET['user_id'] ?? '';

// Get audit data
$filters = array(
    'start_date' => $start_date,
    'end_date' => $end_date,
    'drug_id' => $drug_id,
    'warehouse_id' => $warehouse_id,
    'movement_type' => $movement_type,
    'user_id' => $user_id,
    'limit' => 100
);

$audit_logs = $audit_logger->getAuditLog($filters);
$statistics = $audit_logger->getAuditStatistics($start_date, $end_date);
$movement_stats = $audit_logger->getMovementTypeStats($start_date, $end_date);
$user_stats = $audit_logger->getUserActivityStats($start_date, $end_date);

// Get dropdown data
$drugs = sqlStatement("SELECT drug_id, name FROM drugs WHERE active = 1 ORDER BY name");
$warehouses = sqlStatement("SELECT option_id, title FROM list_options WHERE list_id = 'warehouse' AND activity = 1 ORDER BY title");
$users = sqlStatement("SELECT id, fname, lname FROM users WHERE active = 1 ORDER BY lname, fname");

$movement_types = array(
    'purchase' => 'Purchase/Receipt',
    'sale' => 'Sale',
    'return' => 'Return',
    'transfer' => 'Transfer',
    'adjustment' => 'Adjustment',
    'consumption' => 'Consumption',
    'lot_created' => 'Lot Created',
    'lot_modified' => 'Lot Modified',
    'drug_created' => 'Drug Created',
    'drug_modified' => 'Drug Modified',
    'drug_deleted' => 'Drug Deleted'
);

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Inventory Audit Dashboard'); ?></title>
    <?php Header::setupHeader(['datetime-picker', 'datatables']); ?>
    <style>
        .audit-dashboard {
            padding: 20px;
            background: #f8f9fa;
            min-height: 100vh;
        }
        .audit-header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .audit-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #007bff;
            margin-bottom: 10px;
        }
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .filters-section {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }
        .audit-table {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .movement-type {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
            text-transform: uppercase;
        }
        .movement-purchase { background: #d4edda; color: #155724; }
        .movement-sale { background: #f8d7da; color: #721c24; }
        .movement-transfer { background: #d1ecf1; color: #0c5460; }
        .movement-adjustment { background: #fff3cd; color: #856404; }
        .movement-consumption { background: #e2e3e5; color: #383d41; }
        .movement-lot_created { background: #d1ecf1; color: #0c5460; }
        .movement-lot_modified { background: #fff3cd; color: #856404; }
        .movement-drug_created { background: #d4edda; color: #155724; }
        .movement-drug_modified { background: #fff3cd; color: #856404; }
        .movement-drug_deleted { background: #f8d7da; color: #721c24; }
        .btn-export {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            margin-left: 10px;
        }
        .btn-export:hover {
            background: #218838;
        }
    </style>
</head>
<body class="body_top">
    <div class="audit-dashboard">
        <div class="audit-header">
            <h1><i class="fa fa-shield-alt"></i> <?php echo xlt('Inventory Audit Dashboard'); ?></h1>
            <p class="text-muted"><?php echo xlt('Real-time tracking of all inventory activities and movements'); ?></p>
        </div>

        <!-- Statistics Cards -->
        <div class="audit-stats">
            <div class="stat-card">
                <div class="stat-number"><?php echo $statistics['total_movements'] ?? 0; ?></div>
                <div class="stat-label"><?php echo xlt('Total Movements'); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $statistics['unique_drugs'] ?? 0; ?></div>
                <div class="stat-label"><?php echo xlt('Drugs Affected'); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $statistics['unique_users'] ?? 0; ?></div>
                <div class="stat-label"><?php echo xlt('Users Active'); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-number">$<?php echo number_format($statistics['total_value'] ?? 0, 2); ?></div>
                <div class="stat-label"><?php echo xlt('Total Value'); ?></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-section">
            <h4><i class="fa fa-filter"></i> <?php echo xlt('Filters'); ?></h4>
            <form method="GET" class="filters-grid">
                <div>
                    <label><?php echo xlt('Start Date'); ?></label>
                    <input type="date" name="start_date" value="<?php echo attr($start_date); ?>" class="form-control">
                </div>
                <div>
                    <label><?php echo xlt('End Date'); ?></label>
                    <input type="date" name="end_date" value="<?php echo attr($end_date); ?>" class="form-control">
                </div>
                <div>
                    <label><?php echo xlt('Drug'); ?></label>
                    <select name="drug_id" class="form-control">
                        <option value=""><?php echo xlt('All Drugs'); ?></option>
                        <?php while ($drug = sqlFetchArray($drugs)) { ?>
                            <option value="<?php echo attr($drug['drug_id']); ?>" <?php echo $drug_id == $drug['drug_id'] ? 'selected' : ''; ?>>
                                <?php echo text($drug['name']); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
                <div>
                    <label><?php echo xlt('Warehouse'); ?></label>
                    <select name="warehouse_id" class="form-control">
                        <option value=""><?php echo xlt('All Warehouses'); ?></option>
                        <?php while ($warehouse = sqlFetchArray($warehouses)) { ?>
                            <option value="<?php echo attr($warehouse['option_id']); ?>" <?php echo $warehouse_id == $warehouse['option_id'] ? 'selected' : ''; ?>>
                                <?php echo text($warehouse['title']); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
                <div>
                    <label><?php echo xlt('Movement Type'); ?></label>
                    <select name="movement_type" class="form-control">
                        <option value=""><?php echo xlt('All Types'); ?></option>
                        <?php foreach ($movement_types as $type => $label) { ?>
                            <option value="<?php echo attr($type); ?>" <?php echo $movement_type == $type ? 'selected' : ''; ?>>
                                <?php echo xlt($label); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
                <div>
                    <label><?php echo xlt('User'); ?></label>
                    <select name="user_id" class="form-control">
                        <option value=""><?php echo xlt('All Users'); ?></option>
                        <?php while ($user = sqlFetchArray($users)) { ?>
                            <option value="<?php echo attr($user['id']); ?>" <?php echo $user_id == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo text($user['lname'] . ', ' . $user['fname']); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="btn btn-primary"><?php echo xlt('Apply Filters'); ?></button>
                    <a href="inventory_audit_dashboard.php" class="btn btn-secondary"><?php echo xlt('Clear'); ?></a>
                    <button type="button" class="btn-export" onclick="exportData()"><?php echo xlt('Export CSV'); ?></button>
                </div>
            </form>
        </div>

        <!-- Audit Table -->
        <div class="audit-table">
            <h4><i class="fa fa-table"></i> <?php echo xlt('Audit Log'); ?></h4>
            <table id="audit-table" class="table table-striped table-bordered">
                <thead>
                    <tr>
                        <th><?php echo xlt('Date/Time'); ?></th>
                        <th><?php echo xlt('Drug'); ?></th>
                        <th><?php echo xlt('Movement Type'); ?></th>
                        <th><?php echo xlt('Quantity Change'); ?></th>
                        <th><?php echo xlt('Warehouse'); ?></th>
                        <th><?php echo xlt('User'); ?></th>
                        <th><?php echo xlt('Reason'); ?></th>
                        <th><?php echo xlt('Value'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($log = sqlFetchArray($audit_logs)) { ?>
                        <tr>
                            <td><?php echo text(date('m/d/Y H:i', strtotime($log['movement_date']))); ?></td>
                            <td><?php echo text($log['drug_name']); ?></td>
                            <td>
                                <span class="movement-type movement-<?php echo attr($log['movement_type']); ?>">
                                    <?php echo xlt($movement_types[$log['movement_type']] ?? $log['movement_type']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($log['quantity_change'] != 0) { ?>
                                    <span class="<?php echo $log['quantity_change'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo $log['quantity_change'] > 0 ? '-' : '+'; ?><?php echo abs($log['quantity_change']); ?>
                                    </span>
                                <?php } else { ?>
                                    <span class="text-muted"><?php echo xlt('No Change'); ?></span>
                                <?php } ?>
                            </td>
                            <td><?php echo text($log['warehouse_name'] ?? $log['warehouse_id']); ?></td>
                            <td><?php echo text($log['user_fname'] . ' ' . $log['user_lname']); ?></td>
                            <td><?php echo text($log['reason']); ?></td>
                            <td>
                                <?php if ($log['total_value']) { ?>
                                    $<?php echo number_format($log['total_value'], 2); ?>
                                <?php } else { ?>
                                    <span class="text-muted">-</span>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            $('#audit-table').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                responsive: true,
                language: {
                    search: "<?php echo xla('Search'); ?>:",
                    lengthMenu: "<?php echo xla('Show'); ?> _MENU_ <?php echo xla('entries'); ?>",
                    info: "<?php echo xla('Showing'); ?> _START_ <?php echo xla('to'); ?> _END_ <?php echo xla('of'); ?> _TOTAL_ <?php echo xla('entries'); ?>",
                    infoEmpty: "<?php echo xla('Showing 0 to 0 of 0 entries'); ?>",
                    infoFiltered: "(<?php echo xla('filtered from'); ?> _MAX_ <?php echo xla('total entries'); ?>)",
                    paginate: {
                        first: "<?php echo xla('First'); ?>",
                        last: "<?php echo xla('Last'); ?>",
                        next: "<?php echo xla('Next'); ?>",
                        previous: "<?php echo xla('Previous'); ?>"
                    }
                }
            });
        });

        function exportData() {
            var params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            window.location.href = 'inventory_audit_dashboard.php?' + params.toString();
        }
    </script>
</body>
</html> 