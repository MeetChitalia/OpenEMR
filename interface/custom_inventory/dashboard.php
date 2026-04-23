<?php
require_once("../../globals.php");
require_once("$srcdir/acl.inc");
require_once("$srcdir/formatting.inc.php");
require_once("$srcdir/headers.inc.php");
require_once("$srcdir/sql.inc");
require_once("$srcdir/../library/CustomInventory.php");

use OpenEMR\Core\Header;

// Simulate user_id from OpenEMR session (replace with real user logic in production)
$user_id = $_SESSION['authUserID'] ?? 'admin';
$customInventory = new CustomInventory($user_id);
$is_admin = $customInventory->is_admin;
$clinic_id = $customInventory->clinic_id;

// Tab selection
$tab = $_GET['tab'] ?? ($is_admin ? 'central' : 'clinic');

Header::setupHeader(['bootstrap', 'fontawesome']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Custom Inventory Dashboard</title>
    <link rel="stylesheet" href="<?php echo $GLOBALS['webroot']; ?>/public/assets/bootstrap-3-3-4/dist/css/bootstrap.min.css">
    <style>
        .card { background: #fff; border-radius: 6px; box-shadow: 0 2px 6px #eee; margin-bottom: 20px; padding: 20px; }
        .nav-tabs { margin-bottom: 20px; }
        .table th, .table td { vertical-align: middle !important; }
        .alert-warning { border-left: 4px solid #ffc107; }
        .alert-danger { border-left: 4px solid #dc3545; }
        .alert-success { border-left: 4px solid #28a745; }
        .expiring-soon { background-color: #fff3cd; }
        .expired { background-color: #f8d7da; }
        .low-stock { background-color: #f8d7da; }
        .btn-action { margin: 5px; }
        .status-success { color: green; }
        .status-error { color: red; }
        .status-info { color: blue; }
    </style>
</head>
<body>
<div class="container-fluid">
    <h1 class="mt-3 mb-4">Custom Inventory Dashboard</h1>
    <ul class="nav nav-tabs">
        <?php if ($is_admin): ?>
            <li class="<?php echo $tab == 'central' ? 'active' : ''; ?>">
                <a href="?tab=central">Central Inventory</a>
            </li>
            <li class="<?php echo $tab == 'clinics' ? 'active' : ''; ?>">
                <a href="?tab=clinics">All Clinics</a>
            </li>
            <li class="<?php echo $tab == 'settings' ? 'active' : ''; ?>">
                <a href="?tab=settings">Settings</a>
            </li>
        <?php endif; ?>
        <li class="<?php echo $tab == 'clinic' ? 'active' : ''; ?>">
            <a href="?tab=clinic">My Clinic Inventory</a>
        </li>
        <li class="<?php echo $tab == 'transfers' ? 'active' : ''; ?>">
            <a href="?tab=transfers">Transfers</a>
        </li>
        <li class="<?php echo $tab == 'alerts' ? 'active' : ''; ?>">
            <a href="?tab=alerts">Alerts</a>
        </li>
    </ul>

    <?php if (isset($message)): ?>
        <div class="alert alert-info">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <?php if ($tab == 'settings' && $is_admin): ?>
        <div class="card">
            <h3><i class="fa fa-cogs"></i> Inventory System Settings</h3>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <h4>System Overview</h4>
                        <?php
                        $facility_count = sqlQuery("SELECT COUNT(*) as count FROM facility WHERE billing_location = 1")['count'];
                        $warehouse_count = sqlQuery("SELECT COUNT(*) as count FROM list_options WHERE list_id = 'warehouse'")['count'];
                        $total_drugs = sqlQuery("SELECT COUNT(*) as count FROM drugs")['count'];
                        $active_transfers = sqlQuery("SELECT COUNT(*) as count FROM custom_transfers WHERE transfer_status IN ('pending', 'approved')")['count'];
                        ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="alert alert-info">
                                    <strong><?php echo $facility_count; ?></strong><br>
                                    Clinics
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="alert alert-success">
                                    <strong><?php echo $warehouse_count; ?></strong><br>
                                    Warehouses
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="alert alert-warning">
                                    <strong><?php echo $total_drugs; ?></strong><br>
                                    Total Drugs
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="alert alert-primary">
                                    <strong><?php echo $active_transfers; ?></strong><br>
                                    Active Transfers
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <h4>Inventory Settings</h4>
                        <p>Configure default inventory settings for all clinics.</p>
                        
                        <form method="post" action="?tab=settings">
                            <div class="form-group">
                                <label>Low Stock Threshold (days):</label>
                                <input type="number" name="low_stock_threshold" value="30" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Expiration Warning (days):</label>
                                <input type="number" name="expiration_warning" value="90" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Reorder Point (%):</label>
                                <input type="number" name="reorder_point" value="20" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Max Stock Level:</label>
                                <input type="number" name="max_stock" value="200" class="form-control">
                            </div>
                            <button type="submit" name="update_settings" class="btn btn-success btn-action">
                                <i class="fa fa-save"></i> Update Settings
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <h4>All Clinics Overview</h4>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Clinic Name</th>
                                <th>Phone</th>
                                <th>Address</th>
                                <th>Warehouse</th>
                                <th>Inventory Items</th>
                                <th>Low Stock Alerts</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $clinics = sqlStatement("SELECT f.*, lo.option_id as warehouse_id,
                                                   (SELECT COUNT(*) FROM custom_inventory ci WHERE ci.clinic_id = f.id) as inventory_count,
                                                   (SELECT COUNT(*) FROM custom_inventory_alerts cia WHERE cia.clinic_id = f.id AND cia.alert_type = 'low_stock' AND cia.is_active = 1) as low_stock_count
                                                   FROM facility f 
                                                   LEFT JOIN list_options lo ON lo.list_id = 'warehouse' AND lo.option_value = f.id 
                                                   WHERE f.billing_location = 1 ORDER BY f.name");
                            while ($clinic = sqlFetchArray($clinics)):
                            ?>
                            <tr>
                                <td><?php echo $clinic['id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($clinic['name']); ?></strong>
                                    <?php if ($clinic['billing_location']): ?>
                                        <span class="label label-primary">Billing</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($clinic['phone']); ?></td>
                                <td><?php echo htmlspecialchars($clinic['street'] . ', ' . $clinic['city'] . ', ' . $clinic['state'] . ' ' . $clinic['postal_code']); ?></td>
                                <td>
                                    <?php if ($clinic['warehouse_id']): ?>
                                        <span class="status-success"><?php echo htmlspecialchars($clinic['warehouse_id']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">Not configured</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-info"><?php echo $clinic['inventory_count']; ?> items</span>
                                </td>
                                <td>
                                    <?php if ($clinic['low_stock_count'] > 0): ?>
                                        <span class="badge badge-warning"><?php echo $clinic['low_stock_count']; ?> alerts</span>
                                    <?php else: ?>
                                        <span class="badge badge-success">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($clinic['warehouse_id']): ?>
                                        <span class="status-success">✅ Active</span>
                                    <?php else: ?>
                                        <span class="status-error">❌ No Warehouse</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="card">
                <h4>Recent System Activity</h4>
                <div class="row">
                    <div class="col-md-6">
                        <h5>Recent Transfers</h5>
                        <div class="table-responsive">
                            <table class="table table-condensed">
                                <thead>
                                    <tr>
                                        <th>From</th>
                                        <th>To</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $recent_transfers = sqlStatement("SELECT * FROM custom_transfers ORDER BY transfer_date DESC LIMIT 5");
                                    while ($transfer = sqlFetchArray($recent_transfers)):
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($transfer['from_clinic_id']); ?></td>
                                        <td><?php echo htmlspecialchars($transfer['to_clinic_id']); ?></td>
                                        <td>
                                            <span class="label label-<?php echo $transfer['transfer_status'] == 'completed' ? 'success' : ($transfer['transfer_status'] == 'pending' ? 'warning' : 'info'); ?>">
                                                <?php echo htmlspecialchars($transfer['transfer_status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M j', strtotime($transfer['transfer_date'])); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <h5>Recent Alerts</h5>
                        <div class="table-responsive">
                            <table class="table table-condensed">
                                <thead>
                                    <tr>
                                        <th>Clinic</th>
                                        <th>Type</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $recent_alerts = sqlStatement("SELECT a.*, c.name as clinic_name FROM custom_inventory_alerts a 
                                                                 JOIN facility c ON a.clinic_id = c.id 
                                                                 WHERE a.is_active = 1 ORDER BY a.created_date DESC LIMIT 5");
                                    while ($alert = sqlFetchArray($recent_alerts)):
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($alert['clinic_name']); ?></td>
                                        <td>
                                            <span class="label label-<?php echo $alert['alert_type'] == 'low_stock' ? 'warning' : ($alert['alert_type'] == 'expired' ? 'danger' : 'info'); ?>">
                                                <?php echo htmlspecialchars($alert['alert_type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M j', strtotime($alert['created_date'])); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($tab == 'central' && $is_admin): ?>
        <div class="card">
            <h3>Central Inventory</h3>
            <?php $central = $customInventory->getCentralInventory(); ?>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>Drug</th><th>NDC</th><th>Quantity</th><th>Allocated</th><th>Available</th><th>Total Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($central as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['drug_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['ndc_number']); ?></td>
                                <td><?php echo (int)$row['total_quantity']; ?></td>
                                <td><?php echo (int)$row['total_allocated']; ?></td>
                                <td><?php echo (int)$row['total_available']; ?></td>
                                <td>$<?php echo number_format($row['total_value'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($tab == 'clinics' && $is_admin): ?>
        <div class="card">
            <h3>All Clinics Inventory</h3>
            <?php $clinics = $customInventory->getAllClinics(); ?>
            <?php foreach ($clinics as $clinic): ?>
                <h4><?php echo htmlspecialchars($clinic['clinic_name']); ?></h4>
                <?php $inv = $customInventory->getClinicInventory($clinic['clinic_id']); ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Drug</th><th>NDC</th><th>Lot</th><th>Expires</th><th>On Hand</th><th>Allocated</th><th>Available</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inv as $row): ?>
                                <?php
                                $row_class = '';
                                if ($row['expiration_date']) {
                                    $days = (strtotime($row['expiration_date']) - time()) / (60*60*24);
                                    if ($days < 0) $row_class = 'expired';
                                    elseif ($days <= 90) $row_class = 'expiring-soon';
                                }
                                if ($row['quantity_on_hand'] <= 10) $row_class = 'low-stock';
                                ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td><?php echo htmlspecialchars($row['drug_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['ndc_number']); ?></td>
                                    <td><?php echo htmlspecialchars($row['lot_number']); ?></td>
                                    <td><?php echo $row['expiration_date'] ? htmlspecialchars($row['expiration_date']) : '-'; ?></td>
                                    <td><?php echo (int)$row['quantity_on_hand']; ?></td>
                                    <td><?php echo (int)$row['quantity_allocated']; ?></td>
                                    <td><?php echo (int)$row['quantity_available']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($tab == 'clinic' && $clinic_id): ?>
        <div class="card">
            <h3>My Clinic Inventory</h3>
            <?php $inv = $customInventory->getClinicInventory($clinic_id); ?>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>Drug</th><th>NDC</th><th>Lot</th><th>Expires</th><th>On Hand</th><th>Allocated</th><th>Available</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inv as $row): ?>
                            <?php
                            $row_class = '';
                            if ($row['expiration_date']) {
                                $days = (strtotime($row['expiration_date']) - time()) / (60*60*24);
                                if ($days < 0) $row_class = 'expired';
                                elseif ($days <= 90) $row_class = 'expiring-soon';
                            }
                            if ($row['quantity_on_hand'] <= 10) $row_class = 'low-stock';
                            ?>
                            <tr class="<?php echo $row_class; ?>">
                                <td><?php echo htmlspecialchars($row['drug_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['ndc_number']); ?></td>
                                <td><?php echo htmlspecialchars($row['lot_number']); ?></td>
                                <td><?php echo $row['expiration_date'] ? htmlspecialchars($row['expiration_date']) : '-'; ?></td>
                                <td><?php echo (int)$row['quantity_on_hand']; ?></td>
                                <td><?php echo (int)$row['quantity_allocated']; ?></td>
                                <td><?php echo (int)$row['quantity_available']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($tab == 'transfers'): ?>
        <div class="card">
            <h3>Transfers</h3>
            <?php $transfers = $customInventory->getTransfers($is_admin ? null : $clinic_id); ?>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>ID</th><th>From</th><th>To</th><th>Date</th><th>Status</th><th>Type</th><th>Priority</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transfers as $row): ?>
                            <tr>
                                <td><?php echo (int)$row['transfer_id']; ?></td>
                                <td><?php echo htmlspecialchars($row['from_clinic_id']); ?></td>
                                <td><?php echo htmlspecialchars($row['to_clinic_id']); ?></td>
                                <td><?php echo htmlspecialchars($row['transfer_date']); ?></td>
                                <td><?php echo htmlspecialchars($row['transfer_status']); ?></td>
                                <td><?php echo htmlspecialchars($row['transfer_type']); ?></td>
                                <td><?php echo htmlspecialchars($row['priority']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($tab == 'alerts'): ?>
        <div class="card">
            <h3>Alerts</h3>
            <?php $alerts = $customInventory->getAlerts($is_admin ? null : $clinic_id); ?>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <?php if ($is_admin): ?><th>Clinic</th><?php endif; ?>
                            <th>Drug</th><th>Type</th><th>Message</th><th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($alerts as $row): ?>
                            <tr>
                                <?php if ($is_admin): ?><td><?php echo htmlspecialchars($row['clinic_name']); ?></td><?php endif; ?>
                                <td><?php echo htmlspecialchars($row['drug_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['alert_type']); ?></td>
                                <td><?php echo htmlspecialchars($row['alert_message']); ?></td>
                                <td><?php echo htmlspecialchars($row['created_date']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// Add any JavaScript functionality here
console.log('Custom Inventory Dashboard loaded successfully');
</script>

</body>
</html> 