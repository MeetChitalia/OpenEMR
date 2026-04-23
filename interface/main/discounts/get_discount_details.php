<?php
/**
 * Get Discount Details
 * 
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(dirname(__FILE__) . "/../../globals.php");

// Check if user has permission
if (!acl_check('admin', 'discounts')) {
    echo "<div class='alert alert-danger'>Access Denied</div>";
    exit;
}

$discountId = $_GET['id'] ?? 0;

if (!$discountId) {
    echo "<div class='alert alert-danger'>Invalid discount ID</div>";
    exit;
}

// Get discount details
$discount = sqlQuery("SELECT * FROM discounts WHERE id = ?", array($discountId));

if (!$discount) {
    echo "<div class='alert alert-danger'>Discount not found</div>";
    exit;
}

// Get associated products
$products = sqlStatement("
    SELECT d.name as drug_name, d.drug_id 
    FROM drugs d 
    INNER JOIN product_discounts pd ON d.drug_id = pd.product_id 
    WHERE pd.discount_id = ? AND pd.is_active = 1
", array($discountId));

// Get associated categories
$categories = sqlStatement("
    SELECT c.name as category_name, c.id 
    FROM product_categories c 
    INNER JOIN category_discounts cd ON c.id = cd.category_id 
    WHERE cd.discount_id = ? AND cd.is_active = 1
", array($discountId));

$currentDate = new DateTime();
$startDate = new DateTime($discount['start_date']);
$endDate = $discount['end_date'] ? new DateTime($discount['end_date']) : null;

$isActive = $discount['is_active'] && 
           $startDate <= $currentDate && 
           ($endDate === null || $endDate >= $currentDate);

$statusClass = $isActive ? 'success' : 'secondary';
$statusText = $isActive ? 'Active' : 'Inactive';

if ($startDate > $currentDate) {
    $statusClass = 'warning';
    $statusText = 'Future';
    $daysUntil = $startDate->diff($currentDate)->days;
    $statusText .= " (in $daysUntil days)";
} elseif ($endDate && $endDate < $currentDate) {
    $statusClass = 'danger';
    $statusText = 'Expired';
}
?>

<div class="row">
    <div class="col-md-6">
        <h5><?php echo xlt('Discount Information'); ?></h5>
        <table class="table table-borderless">
            <tr>
                <td><strong><?php echo xlt('Name'); ?>:</strong></td>
                <td><?php echo text($discount['name']); ?></td>
            </tr>
            <tr>
                <td><strong><?php echo xlt('Description'); ?>:</strong></td>
                <td><?php echo text($discount['description'] ?: xlt('No description')); ?></td>
            </tr>
            <tr>
                <td><strong><?php echo xlt('Type'); ?>:</strong></td>
                <td>
                    <span class="badge badge-<?php echo $discount['discount_type'] === 'percentage' ? 'info' : 'warning'; ?>">
                        <?php echo text(ucfirst($discount['discount_type'])); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <td><strong><?php echo xlt('Value'); ?>:</strong></td>
                <td>
                    <strong>
                    <?php 
                    if ($discount['discount_type'] === 'percentage') {
                        echo text($discount['discount_value']) . '%';
                    } else {
                        echo '$' . text(number_format($discount['discount_value'], 2));
                    }
                    ?>
                    </strong>
                </td>
            </tr>
            <tr>
                <td><strong><?php echo xlt('Status'); ?>:</strong></td>
                <td>
                    <span class="badge badge-<?php echo $statusClass; ?>">
                        <?php echo xlt($statusText); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <td><strong><?php echo xlt('Automatic'); ?>:</strong></td>
                <td>
                    <?php if ($discount['is_automatic']): ?>
                        <i class="fa fa-check text-success"></i> <?php echo xlt('Yes'); ?>
                    <?php else: ?>
                        <i class="fa fa-times text-danger"></i> <?php echo xlt('No'); ?>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>
    
    <div class="col-md-6">
        <h5><?php echo xlt('Date Information'); ?></h5>
        <table class="table table-borderless">
            <tr>
                <td><strong><?php echo xlt('Start Date'); ?>:</strong></td>
                <td>
                    <?php 
                    if ($startDate > $currentDate) {
                        echo "<span class='text-warning'><i class='fa fa-calendar-plus-o'></i> ";
                        echo text($startDate->format('M d, Y'));
                        echo "</span>";
                    } else {
                        echo "<span class='text-success'><i class='fa fa-calendar-check-o'></i> ";
                        echo text($startDate->format('M d, Y'));
                        echo "</span>";
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <td><strong><?php echo xlt('End Date'); ?>:</strong></td>
                <td>
                    <?php 
                    if ($endDate) {
                        if ($endDate < $currentDate) {
                            echo "<span class='text-danger'><i class='fa fa-calendar-times-o'></i> ";
                            echo text($endDate->format('M d, Y'));
                            echo "</span>";
                        } else {
                            echo "<span class='text-info'><i class='fa fa-calendar-o'></i> ";
                            echo text($endDate->format('M d, Y'));
                            echo "</span>";
                        }
                    } else {
                        echo "<span class='text-muted'><i class='fa fa-infinity'></i> " . xlt('No End Date') . "</span>";
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <td><strong><?php echo xlt('Created'); ?>:</strong></td>
                <td><?php echo text(date('M d, Y H:i', strtotime($discount['created_date']))); ?></td>
            </tr>
            <tr>
                <td><strong><?php echo xlt('Last Updated'); ?>:</strong></td>
                <td><?php echo text(date('M d, Y H:i', strtotime($discount['updated_date']))); ?></td>
            </tr>
        </table>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-6">
        <h5><?php echo xlt('Associated Products'); ?></h5>
        <?php 
        $productCount = 0;
        while ($product = sqlFetchArray($products)) {
            $productCount++;
            if ($productCount === 1) {
                echo "<ul class='list-group'>";
            }
            echo "<li class='list-group-item'>" . text($product['drug_name']) . "</li>";
        }
        
        if ($productCount === 0) {
            echo "<p class='text-muted'>" . xlt('No specific products associated') . "</p>";
        } else {
            echo "</ul>";
        }
        ?>
    </div>
    
    <div class="col-md-6">
        <h5><?php echo xlt('Associated Categories'); ?></h5>
        <?php 
        $categoryCount = 0;
        while ($category = sqlFetchArray($categories)) {
            $categoryCount++;
            if ($categoryCount === 1) {
                echo "<ul class='list-group'>";
            }
            echo "<li class='list-group-item'>" . text($category['category_name']) . "</li>";
        }
        
        if ($categoryCount === 0) {
            echo "<p class='text-muted'>" . xlt('No specific categories associated') . "</p>";
        } else {
            echo "</ul>";
        }
        ?>
    </div>
</div>

<div class="row mt-4">
    <div class="col-12">
        <div class="alert alert-info">
            <i class="fa fa-info-circle"></i>
            <strong><?php echo xlt('Note'); ?>:</strong> 
            <?php echo xlt('This discount will be automatically applied to eligible products during checkout.'); ?>
        </div>
    </div>
</div> 