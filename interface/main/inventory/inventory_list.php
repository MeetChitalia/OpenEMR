<?php
/**
 * Inventory List with Discount Display
 */

require_once(dirname(__FILE__) . "/../../globals.php");
require_once($GLOBALS['srcdir'] . "/Discount/DiscountManager.php");

use OpenEMR\Discount\DiscountManager;

$discountManager = new DiscountManager();
?>

<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Inventory Management'); ?></title>
    <link rel="stylesheet" href="<?php echo $GLOBALS['webroot']; ?>/interface/themes/style_light.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap4.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap4.min.js"></script>
</head>
<body class="body_top">
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <h2><?php echo xlt('Inventory Management'); ?></h2>
                
                <div class="card">
                    <div class="card-header">
                        <h4><?php echo xlt('Product Inventory'); ?></h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="inventoryTable" class="table table-striped">
                                <thead>
                                    <tr>
                                        <th><?php echo xlt('Product Name'); ?></th>
                                        <th><?php echo xlt('Category'); ?></th>
                                        <th><?php echo xlt('Price'); ?></th>
                                        <th><?php echo xlt('Discounted Price'); ?></th>
                                        <th><?php echo xlt('Discounts'); ?></th>
                                        <th><?php echo xlt('Stock'); ?></th>
                                        <th><?php echo xlt('Actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $products = sqlStatement("SELECT * FROM drugs ORDER BY name");
                                    while ($product = sqlFetchArray($products)):
                                        $discountInfo = $discountManager->getDiscountDisplayInfo($product['drug_id']);
                                        $pricing = $discountManager->calculateDiscountedPrice($product['drug_id'], $product['price']);
                                    ?>
                                    <tr>
                                        <td><?php echo text($product['name']); ?></td>
                                        <td><?php echo text($product['category']); ?></td>
                                        <td>$<?php echo number_format($product['price'], 2); ?></td>
                                        <td>
                                            <?php if ($pricing['final_price'] < $product['price']): ?>
                                                <span class="text-success">$<?php echo number_format($pricing['final_price'], 2); ?></span>
                                                <small class="text-muted d-block">
                                                    <?php echo xlt('Save'); ?>: $<?php echo number_format($pricing['total_discount'], 2); ?>
                                                </small>
                                            <?php else: ?>
                                                <span class="text-muted">$<?php echo number_format($product['price'], 2); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($discountInfo): ?>
                                                <?php foreach ($discountInfo as $discount): ?>
                                                    <div class="discount-badge mb-1">
                                                        <span class="badge badge-<?php echo $discount['is_future'] ? 'warning' : 'success'; ?>">
                                                            <?php echo text($discount['name']); ?>
                                                        </span>
                                                        <?php if ($discount['description']): ?>
                                                            <small class="text-muted d-block">
                                                                <?php echo text($discount['description']); ?>
                                                            </small>
                                                        <?php endif; ?>
                                                        <small class="text-muted d-block">
                                                            <?php 
                                                            if ($discount['type'] === 'percentage') {
                                                                echo text($discount['value']) . '%';
                                                            } else {
                                                                echo '$' . text($discount['value']);
                                                            }
                                                            ?>
                                                            <?php if ($discount['is_future']): ?>
                                                                (<?php echo xlt('Starts'); ?>: <?php echo text($discount['start_date']); ?>)
                                                            <?php endif; ?>
                                                        </small>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="text-muted"><?php echo xlt('No discounts'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo text($product['quantity']); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" onclick="editProduct(<?php echo $product['drug_id']; ?>)">
                                                <?php echo xlt('Edit'); ?>
                                            </button>
                                            <button class="btn btn-sm btn-info" onclick="viewDiscounts(<?php echo $product['drug_id']; ?>)">
                                                <?php echo xlt('Discounts'); ?>
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
    
    <style>
        .discount-badge {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 5px;
            margin-bottom: 5px;
        }
        
        .discount-badge:last-child {
            margin-bottom: 0;
        }
    </style>
    
    <script>
        $(document).ready(function() {
            $('#inventoryTable').DataTable({
                "pageLength": 25,
                "order": [[0, "asc"]],
                "language": {
                    "search": "<?php echo xlt('Search'); ?>:",
                    "lengthMenu": "<?php echo xlt('Show'); ?> _MENU_ <?php echo xlt('entries'); ?>",
                    "info": "<?php echo xlt('Showing'); ?> _START_ <?php echo xlt('to'); ?> _END_ <?php echo xlt('of'); ?> _TOTAL_ <?php echo xlt('entries'); ?>",
                    "paginate": {
                        "first": "<?php echo xlt('First'); ?>",
                        "last": "<?php echo xlt('Last'); ?>",
                        "next": "<?php echo xlt('Next'); ?>",
                        "previous": "<?php echo xlt('Previous'); ?>"
                    }
                }
            });
        });
        
        function editProduct(id) {
            // Implement edit functionality
            window.open('edit_product.php?id=' + id, '_blank');
        }
        
        function viewDiscounts(id) {
            // Implement discount view functionality
            window.open('product_discounts.php?id=' + id, '_blank');
        }
    </script>
</body>
</html> 