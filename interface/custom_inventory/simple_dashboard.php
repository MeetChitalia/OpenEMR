<!DOCTYPE html>
<html>
<head>
    <title>Inventory Management - OpenEMR</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <style>
        .card { background: #fff; border-radius: 6px; box-shadow: 0 2px 6px #eee; margin-bottom: 20px; padding: 20px; }
        .nav-tabs { margin-bottom: 20px; }
        .btn-action { margin: 5px; }
        .status-success { color: green; }
        .status-error { color: red; }
        .status-info { color: blue; }
        .status-warning { color: orange; }
        .alert-box { padding: 15px; margin-bottom: 15px; border-radius: 4px; }
        .alert-info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; }
        .alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .alert-warning { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; }
        .alert-primary { background: #cce5ff; border: 1px solid #b3d7ff; color: #004085; }
        .alert-danger { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .warehouse-card { border-left: 4px solid #007bff; margin-bottom: 15px; }
        .warehouse-card.central { border-left-color: #28a745; }
        .warehouse-card.clinic { border-left-color: #17a2b8; }
        .warehouse-card.low-stock { border-left-color: #ffc107; }
        .warehouse-card.critical { border-left-color: #dc3545; }
        .warehouse-header { background: #f8f9fa; padding: 10px; border-radius: 4px; margin-bottom: 10px; }
        .warehouse-stats { display: flex; justify-content: space-between; margin-top: 10px; }
        .stat-item { text-align: center; padding: 5px; }
        .stat-number { font-size: 18px; font-weight: bold; }
        .stat-label { font-size: 12px; color: #666; }
        .table-condensed th, .table-condensed td { padding: 6px; }
        .progress { height: 8px; margin-bottom: 5px; }
        .badge { font-size: 11px; }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="page-header">
        <h1><i class="fa fa-cubes"></i> Inventory Management</h1>
        <p class="text-muted">Multi-clinic warehouse tracking and management system</p>
    </div>
    
    <div class="alert alert-info">
        <strong><i class="fa fa-info-circle"></i> System Status:</strong> 
        Tracking <strong>21 warehouses</strong> (1 Central + 20 Clinics) with real-time inventory monitoring.
    </div>
    
    <ul class="nav nav-tabs">
        <li class="active">
            <a href="#warehouses" data-toggle="tab">
                <i class="fa fa-warehouse"></i> All Warehouses
            </a>
        </li>
        <li>
            <a href="#central" data-toggle="tab">
                <i class="fa fa-building"></i> Central Inventory
            </a>
        </li>
        <li>
            <a href="#clinics" data-toggle="tab">
                <i class="fa fa-hospital-o"></i> Clinic Warehouses
            </a>
        </li>
        <li>
            <a href="#alerts" data-toggle="tab">
                <i class="fa fa-exclamation-triangle"></i> Alerts
            </a>
        </li>
        <li>
            <a href="#settings" data-toggle="tab">
                <i class="fa fa-cogs"></i> Settings
            </a>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane active" id="warehouses">
            <div class="card">
                <h3><i class="fa fa-warehouse"></i> All Warehouses Overview</h3>
                <p>Complete inventory tracking across all locations</p>
                
                <!-- Central Inventory -->
                <div class="warehouse-card central">
                    <div class="warehouse-header">
                        <h4><i class="fa fa-building"></i> Central Inventory Warehouse</h4>
                        <small class="text-muted">Main distribution center for all clinics</small>
                    </div>
                    <div class="row">
                        <div class="col-md-8">
                            <table class="table table-condensed">
                                <tr>
                                    <td><strong>Location:</strong></td>
                                    <td>Main Distribution Center, Downtown</td>
                                    <td><strong>Manager:</strong></td>
                                    <td>John Smith</td>
                                </tr>
                                <tr>
                                    <td><strong>Phone:</strong></td>
                                    <td>(555) 123-4567</td>
                                    <td><strong>Email:</strong></td>
                                    <td>central@healthcare.com</td>
                                </tr>
                                <tr>
                                    <td><strong>Status:</strong></td>
                                    <td><span class="label label-success">Active</span></td>
                                    <td><strong>Last Updated:</strong></td>
                                    <td>Today 2:30 PM</td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-4">
                            <div class="warehouse-stats">
                                <div class="stat-item">
                                    <div class="stat-number text-success">1,250</div>
                                    <div class="stat-label">Total Items</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number text-warning">45</div>
                                    <div class="stat-label">Low Stock</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number text-danger">12</div>
                                    <div class="stat-label">Critical</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Clinic Warehouses -->
                <h4><i class="fa fa-hospital-o"></i> Clinic Warehouses (20 Locations)</h4>
                <div class="row">
                <?php
                // Sample data for 20 clinics
                $clinic_names = [
                    'Downtown Medical Center', 'Northside Clinic', 'Southside Medical', 'Eastside Healthcare', 'Westside Clinic',
                    'Lakeside Health', 'Uptown Family Practice', 'Green Valley Clinic', 'Sunrise Health', 'Riverbend Medical',
                    'Hilltop Clinic', 'West End Health', 'Pinecrest Medical', 'Oakwood Clinic', 'Cedar Grove Health',
                    'Maple Leaf Clinic', 'Harborview Medical', 'Parkside Health', 'Summit Family Clinic', 'Valleyview Medical'
                ];
                $managers = [
                    'Dr. Sarah Johnson', 'Dr. Mike Chen', 'Dr. Lisa Rodriguez', 'Dr. Robert Wilson', 'Dr. Emily Davis',
                    'Dr. Alan Brown', 'Dr. Priya Patel', 'Dr. Kevin Lee', 'Dr. Maria Garcia', 'Dr. James White',
                    'Dr. Linda Kim', 'Dr. Steven Clark', 'Dr. Angela Young', 'Dr. Brian Hall', 'Dr. Rachel Adams',
                    'Dr. Jason Scott', 'Dr. Olivia Turner', 'Dr. Eric Martinez', 'Dr. Grace Evans', 'Dr. Henry Moore'
                ];
                for ($i = 0; $i < 20; $i++):
                    $items = rand(250, 600);
                    $low = rand(0, 20);
                    $critical = rand(0, 8);
                    $status = ($critical > 5) ? 'Critical' : (($low > 10) ? 'Low Stock Alert' : 'Active');
                    $status_class = ($critical > 5) ? 'critical' : (($low > 10) ? 'low-stock' : '');
                ?>
                    <div class="col-md-6">
                        <div class="warehouse-card clinic <?php echo $status_class; ?>" style="cursor:pointer;" onclick="showClinicDetails(<?php echo $i+1; ?>)">
                            <div class="warehouse-header">
                                <h5><i class="fa fa-hospital-o"></i> <?php echo $clinic_names[$i]; ?></h5>
                                <small class="text-muted">Clinic #<?php echo $i+1; ?></small>
                            </div>
                            <div class="row">
                                <div class="col-md-8">
                                    <p><strong>Manager:</strong> <?php echo $managers[$i]; ?></p>
                                    <p><strong>Status:</strong> 
                                        <?php if ($status == 'Critical'): ?>
                                            <span class="label label-danger">Critical Stock</span>
                                        <?php elseif ($status == 'Low Stock Alert'): ?>
                                            <span class="label label-warning">Low Stock Alert</span>
                                        <?php else: ?>
                                            <span class="label label-success">Active</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="col-md-4">
                                    <div class="warehouse-stats">
                                        <div class="stat-item">
                                            <div class="stat-number"><?php echo $items; ?></div>
                                            <div class="stat-label">Items</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-number text-warning"><?php echo $low; ?></div>
                                            <div class="stat-label">Low</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-number text-danger"><?php echo $critical; ?></div>
                                            <div class="stat-label">Critical</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endfor; ?>
                </div>
            </div>
        </div>
        
        <div class="tab-pane" id="central">
            <div class="card">
                <h3><i class="fa fa-building"></i> Central Inventory Management</h3>
                <p>Main distribution center inventory and operations</p>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="alert alert-success">
                            <strong>1,250</strong><br>
                            <i class="fa fa-cubes"></i> Total Items
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="alert alert-warning">
                            <strong>45</strong><br>
                            <i class="fa fa-exclamation-triangle"></i> Low Stock
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="alert alert-danger">
                            <strong>12</strong><br>
                            <i class="fa fa-times-circle"></i> Critical
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="alert alert-info">
                            <strong>8</strong><br>
                            <i class="fa fa-truck"></i> Pending Orders
                        </div>
                    </div>
                </div>

                <h4><i class="fa fa-list"></i> Top Items by Category</h4>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Items</th>
                                <th>Low Stock</th>
                                <th>Critical</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Medications</strong></td>
                                <td>450</td>
                                <td>15</td>
                                <td>3</td>
                                <td><span class="label label-success">Good</span></td>
                            </tr>
                            <tr>
                                <td><strong>Medical Supplies</strong></td>
                                <td>320</td>
                                <td>12</td>
                                <td>5</td>
                                <td><span class="label label-warning">Low</span></td>
                            </tr>
                            <tr>
                                <td><strong>Equipment</strong></td>
                                <td>180</td>
                                <td>8</td>
                                <td>2</td>
                                <td><span class="label label-success">Good</span></td>
                            </tr>
                            <tr>
                                <td><strong>Lab Supplies</strong></td>
                                <td>200</td>
                                <td>10</td>
                                <td>2</td>
                                <td><span class="label label-warning">Low</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="tab-pane" id="clinics">
            <div class="card">
                <h3><i class="fa fa-hospital-o"></i> Clinic Warehouse Management</h3>
                <p>Individual clinic inventory tracking and management</p>
                
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Clinic ID</th>
                                <th>Clinic Name</th>
                                <th>Manager</th>
                                <th>Total Items</th>
                                <th>Low Stock</th>
                                <th>Critical</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1</td>
                                <td><strong>Downtown Medical Center</strong></td>
                                <td>Dr. Sarah Johnson</td>
                                <td>450</td>
                                <td><span class="badge badge-warning">8</span></td>
                                <td><span class="badge badge-success">0</span></td>
                                <td><span class="label label-success">Active</span></td>
                                <td>
                                    <button class="btn btn-xs btn-primary">View</button>
                                    <button class="btn btn-xs btn-info">Edit</button>
                                </td>
                            </tr>
                            <tr>
                                <td>2</td>
                                <td><strong>Northside Clinic</strong></td>
                                <td>Dr. Mike Chen</td>
                                <td>380</td>
                                <td><span class="badge badge-danger">15</span></td>
                                <td><span class="badge badge-success">0</span></td>
                                <td><span class="label label-warning">Low Stock</span></td>
                                <td>
                                    <button class="btn btn-xs btn-primary">View</button>
                                    <button class="btn btn-xs btn-warning">Alert</button>
                                </td>
                            </tr>
                            <tr>
                                <td>3</td>
                                <td><strong>Southside Medical</strong></td>
                                <td>Dr. Lisa Rodriguez</td>
                                <td>520</td>
                                <td><span class="badge badge-warning">6</span></td>
                                <td><span class="badge badge-success">0</span></td>
                                <td><span class="label label-success">Active</span></td>
                                <td>
                                    <button class="btn btn-xs btn-primary">View</button>
                                    <button class="btn btn-xs btn-info">Edit</button>
                                </td>
                            </tr>
                            <tr>
                                <td>4</td>
                                <td><strong>Eastside Healthcare</strong></td>
                                <td>Dr. Robert Wilson</td>
                                <td>290</td>
                                <td><span class="badge badge-danger">22</span></td>
                                <td><span class="badge badge-danger">5</span></td>
                                <td><span class="label label-danger">Critical</span></td>
                                <td>
                                    <button class="btn btn-xs btn-primary">View</button>
                                    <button class="btn btn-xs btn-danger">Emergency</button>
                                </td>
                            </tr>
                            <tr>
                                <td>5</td>
                                <td><strong>Westside Clinic</strong></td>
                                <td>Dr. Emily Davis</td>
                                <td>410</td>
                                <td><span class="badge badge-warning">9</span></td>
                                <td><span class="badge badge-success">0</span></td>
                                <td><span class="label label-success">Active</span></td>
                                <td>
                                    <button class="btn btn-xs btn-primary">View</button>
                                    <button class="btn btn-xs btn-info">Edit</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="alert alert-info">
                    <i class="fa fa-info-circle"></i> <strong>Showing 5 of 20 clinics.</strong> 
                    Use the pagination controls below to view all clinics.
                </div>
            </div>
        </div>
        
        <div class="tab-pane" id="alerts">
            <div class="card">
                <h3><i class="fa fa-exclamation-triangle"></i> Inventory Alerts</h3>
                <p>Active alerts and notifications across all warehouses</p>
                
                <div class="row">
                    <div class="col-md-6">
                        <h4><i class="fa fa-times-circle text-danger"></i> Critical Alerts (3)</h4>
                        <div class="alert alert-danger">
                            <strong>Eastside Healthcare - Critical Stock Level</strong><br>
                            <small>22 items below minimum threshold. Immediate action required.</small>
                        </div>
                        <div class="alert alert-danger">
                            <strong>Northside Clinic - Low Stock Alert</strong><br>
                            <small>15 items need replenishment within 48 hours.</small>
                        </div>
                        <div class="alert alert-danger">
                            <strong>Central Warehouse - Expired Items</strong><br>
                            <small>12 items expired and need disposal.</small>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <h4><i class="fa fa-exclamation-triangle text-warning"></i> Warning Alerts (8)</h4>
                        <div class="alert alert-warning">
                            <strong>Downtown Medical Center</strong><br>
                            <small>8 items approaching low stock levels.</small>
                        </div>
                        <div class="alert alert-warning">
                            <strong>Southside Medical</strong><br>
                            <small>6 items need reordering soon.</small>
                        </div>
                        <div class="alert alert-warning">
                            <strong>Westside Clinic</strong><br>
                            <small>9 items below optimal levels.</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="tab-pane" id="settings">
            <div class="card">
                <h3><i class="fa fa-cogs"></i> Inventory Settings</h3>
                <p>Configure inventory thresholds and alert settings for all warehouses</p>
                <form>
                    <div class="row">
                        <div class="col-md-6">
                            <h4>Stock Level Thresholds</h4>
                            <div class="form-group">
                                <label><i class="fa fa-exclamation-triangle"></i> Low Stock Threshold (days):</label>
                                <input type="number" value="30" class="form-control">
                            </div>
                            <div class="form-group">
                                <label><i class="fa fa-calendar"></i> Expiration Warning (days):</label>
                                <input type="number" value="90" class="form-control">
                            </div>
                            <div class="form-group">
                                <label><i class="fa fa-percentage"></i> Reorder Point (%):</label>
                                <input type="number" value="20" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h4>Alert Settings</h4>
                            <div class="form-group">
                                <label><i class="fa fa-bell"></i> Email Alerts:</label>
                                <select class="form-control">
                                    <option>Enabled</option>
                                    <option>Disabled</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fa fa-mobile"></i> SMS Alerts:</label>
                                <select class="form-control">
                                    <option>Critical Only</option>
                                    <option>All Alerts</option>
                                    <option>Disabled</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fa fa-clock-o"></i> Alert Frequency:</label>
                                <select class="form-control">
                                    <option>Immediate</option>
                                    <option>Daily Summary</option>
                                    <option>Weekly Summary</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success btn-action">
                        <i class="fa fa-save"></i> Update Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Clinic Details Modal -->
<div class="modal fade" id="clinicDetailsModal" tabindex="-1" role="dialog" aria-labelledby="clinicDetailsLabel">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title" id="clinicDetailsLabel">Clinic Inventory Details</h4>
      </div>
      <div class="modal-body" id="clinicDetailsBody">
        <!-- Populated by JS -->
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
<script>
function showClinicDetails(clinicId) {
    // Sample data for 20 clinics
    var clinicNames = [
        'Downtown Medical Center', 'Northside Clinic', 'Southside Medical', 'Eastside Healthcare', 'Westside Clinic',
        'Lakeside Health', 'Uptown Family Practice', 'Green Valley Clinic', 'Sunrise Health', 'Riverbend Medical',
        'Hilltop Clinic', 'West End Health', 'Pinecrest Medical', 'Oakwood Clinic', 'Cedar Grove Health',
        'Maple Leaf Clinic', 'Harborview Medical', 'Parkside Health', 'Summit Family Clinic', 'Valleyview Medical'
    ];
    var managers = [
        'Dr. Sarah Johnson', 'Dr. Mike Chen', 'Dr. Lisa Rodriguez', 'Dr. Robert Wilson', 'Dr. Emily Davis',
        'Dr. Alan Brown', 'Dr. Priya Patel', 'Dr. Kevin Lee', 'Dr. Maria Garcia', 'Dr. James White',
        'Dr. Linda Kim', 'Dr. Steven Clark', 'Dr. Angela Young', 'Dr. Brian Hall', 'Dr. Rachel Adams',
        'Dr. Jason Scott', 'Dr. Olivia Turner', 'Dr. Eric Martinez', 'Dr. Grace Evans', 'Dr. Henry Moore'
    ];
    // Generate random inventory for demo
    var items = Math.floor(Math.random()*350)+250;
    var low = Math.floor(Math.random()*20);
    var critical = Math.floor(Math.random()*8);
    var status = (critical > 5) ? 'Critical' : ((low > 10) ? 'Low Stock Alert' : 'Active');
    var statusLabel = (critical > 5) ? '<span class="label label-danger">Critical Stock</span>' : ((low > 10) ? '<span class="label label-warning">Low Stock Alert</span>' : '<span class="label label-success">Active</span>');
    var inventoryRows = '';
    for (var i=1; i<=8; i++) {
        var drug = 'Drug ' + String.fromCharCode(64+i);
        var qty = Math.floor(Math.random()*100)+10;
        var exp = (Math.random() > 0.8) ? '<span class="text-danger">Expired</span>' : '2025-0'+((i%9)+1)+'-15';
        var alert = (qty < 20) ? '<span class="label label-warning">Low</span>' : '';
        inventoryRows += '<tr><td>'+drug+'</td><td>'+qty+'</td><td>'+exp+'</td><td>'+alert+'</td></tr>';
    }
    var html = '<h4>'+clinicNames[clinicId-1]+'</h4>';
    html += '<p><strong>Manager:</strong> '+managers[clinicId-1]+'<br>';
    html += '<strong>Status:</strong> '+statusLabel+'<br>';
    html += '<strong>Total Items:</strong> '+items+'<br>';
    html += '<strong>Low Stock:</strong> '+low+'<br>';
    html += '<strong>Critical:</strong> '+critical+'</p>';
    html += '<h5>Inventory List</h5>';
    html += '<div class="table-responsive"><table class="table table-bordered table-striped"><thead><tr><th>Drug</th><th>Quantity</th><th>Expiration</th><th>Alert</th></tr></thead><tbody>'+inventoryRows+'</tbody></table></div>';
    $("#clinicDetailsBody").html(html);
    $("#clinicDetailsModal").modal('show');
}
console.log('Inventory Management Dashboard loaded successfully');
</script>

</body>
</html> 