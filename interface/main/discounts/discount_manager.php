<?php
/**
 * Discount Manager Interface
 * 
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Your Name <your.email@example.com>
 * @copyright Copyright (c) 2024 Your Name
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(dirname(__FILE__) . "/../../globals.php");
require_once($GLOBALS['srcdir'] . "/Discount/DiscountManager.php");

use OpenEMR\Discount\DiscountManager;

// Check if user has permission
if (!acl_check('admin', 'discounts')) {
    echo "<div class='alert alert-danger'>Access Denied</div>";
    exit;
}

$discountManager = new DiscountManager();

// Handle form submissions
if ($_POST['action'] ?? '' === 'create') {
    $discountData = array(
        'name' => $_POST['name'],
        'description' => $_POST['description'],
        'discount_type' => $_POST['discount_type'],
        'discount_value' => $_POST['discount_value'],
        'start_date' => $_POST['start_date'],
        'end_date' => $_POST['end_date'] ?: null,
        'is_active' => $_POST['is_active'] ?? 1,
        'is_automatic' => $_POST['is_automatic'] ?? 1
    );
    
    $result = $discountManager->createDiscount($discountData);
    if ($result) {
        echo "<div class='alert alert-success'>Discount created successfully!</div>";
    } else {
        echo "<div class='alert alert-danger'>Error creating discount</div>";
    }
}

// Get all discounts for display
$allDiscounts = sqlStatement("SELECT * FROM discounts ORDER BY start_date DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Discount Manager'); ?></title>
    <link rel="stylesheet" href="<?php echo $GLOBALS['webroot']; ?>/interface/themes/style_light.css">
    <script src="<?php echo $GLOBALS['webroot']; ?>/interface/main/calendar/modules/PostCalendar/pntemplates/default/headIncludes.php"></script>
    <script src="<?php echo $GLOBALS['webroot']; ?>/interface/main/calendar/modules/PostCalendar/pntemplates/default/headIncludes.php"></script>
</head>
<body class="body_top">
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <h2><?php echo xlt('Discount Manager'); ?></h2>
                
                <!-- Create Discount Form -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h4><?php echo xlt('Create New Discount'); ?></h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="create">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label><?php echo xlt('Discount Name'); ?> *</label>
                                        <input type="text" name="name" class="form-control" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label><?php echo xlt('Discount Type'); ?> *</label>
                                        <select name="discount_type" class="form-control" required>
                                            <option value="percentage"><?php echo xlt('Percentage'); ?></option>
                                            <option value="fixed_amount"><?php echo xlt('Fixed Amount'); ?></option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label><?php echo xlt('Discount Value'); ?> *</label>
                                        <input type="number" name="discount_value" class="form-control" step="0.01" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label><?php echo xlt('Start Date'); ?> *</label>
                                        <input type="date" name="start_date" class="form-control" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label><?php echo xlt('End Date'); ?></label>
                                        <input type="date" name="end_date" class="form-control">
                                        <small class="form-text text-muted"><?php echo xlt('Leave empty for no end date'); ?></small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label><?php echo xlt('Description'); ?></label>
                                        <textarea name="description" class="form-control" rows="3"></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input type="checkbox" name="is_active" value="1" class="form-check-input" checked>
                                        <label class="form-check-label"><?php echo xlt('Active'); ?></label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input type="checkbox" name="is_automatic" value="1" class="form-check-input" checked>
                                        <label class="form-check-label"><?php echo xlt('Automatic Activation'); ?></label>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary"><?php echo xlt('Create Discount'); ?></button>
                        </form>
                    </div>
                </div>
                
                <!-- Discounts List -->
                <div class="card">
                    <div class="card-header">
                        <h4><?php echo xlt('All Discounts'); ?></h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th><?php echo xlt('Name'); ?></th>
                                        <th><?php echo xlt('Type'); ?></th>
                                        <th><?php echo xlt('Value'); ?></th>
                                        <th><?php echo xlt('Start Date'); ?></th>
                                        <th><?php echo xlt('End Date'); ?></th>
                                        <th><?php echo xlt('Status'); ?></th>
                                        <th><?php echo xlt('Actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($discount = sqlFetchArray($allDiscounts)): ?>
                                    <tr>
                                        <td><?php echo text($discount['name']); ?></td>
                                        <td><?php echo text($discount['discount_type']); ?></td>
                                        <td>
                                            <?php 
                                            if ($discount['discount_type'] === 'percentage') {
                                                echo text($discount['discount_value']) . '%';
                                            } else {
                                                echo '$' . text($discount['discount_value']);
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo text($discount['start_date']); ?></td>
                                        <td><?php echo text($discount['end_date'] ?: 'No End Date'); ?></td>
                                        <td>
                                            <?php 
                                            $currentDate = date('Y-m-d');
                                            $isActive = $discount['is_active'] && 
                                                       $discount['start_date'] <= $currentDate && 
                                                       ($discount['end_date'] === null || $discount['end_date'] >= $currentDate);
                                            
                                            if ($isActive) {
                                                echo "<span class='badge badge-success'>" . xlt('Active') . "</span>";
                                            } elseif ($discount['start_date'] > $currentDate) {
                                                echo "<span class='badge badge-warning'>" . xlt('Future') . "</span>";
                                            } else {
                                                echo "<span class='badge badge-secondary'>" . xlt('Inactive') . "</span>";
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" onclick="editDiscount(<?php echo $discount['id']; ?>)">
                                                <?php echo xlt('Edit'); ?>
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="deleteDiscount(<?php echo $discount['id']; ?>)">
                                                <?php echo xlt('Delete'); ?>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function editDiscount(id) {
            // Implement edit functionality
            alert('Edit discount ' + id);
        }
        
        function deleteDiscount(id) {
            if (confirm('<?php echo xlt('Are you sure you want to delete this discount?'); ?>')) {
                // Implement delete functionality
                alert('Delete discount ' + id);
            }
        }
    </script>
</body>
</html> 