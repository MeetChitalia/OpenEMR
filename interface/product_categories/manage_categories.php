<?php
/**
 * Dynamic Product Categories Management Interface
 * Categories can have many products, products belong to one category
 * Real-time dynamic functionality with AJAX updates
 */

// Include OpenEMR globals
require_once '../globals.php';

// Include the ProductCategoryManager class
require_once '../../library/classes/ProductCategoryManager.class.php';

// Handle AJAX requests for real-time updates
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['ajax']) {
        case 'realtime':
            echo json_encode(ProductCategoryManager::getRealTimeData());
            break;
            
        case 'check_product':
            $name = $_GET['name'] ?? '';
            $exists = ProductCategoryManager::productExists($name);
            echo json_encode(array('exists' => $exists));
            break;
            
        case 'check_category':
            $name = $_GET['name'] ?? '';
            $exists = ProductCategoryManager::categoryExists($name);
            echo json_encode(array('exists' => $exists));
            break;
            
        case 'get_products_by_category':
            $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
            if ($category_id > 0) {
                $products = ProductCategoryManager::getProductsByCategory($category_id);
                echo json_encode($products);
            } else {
                echo json_encode(array());
            }
            break;
            
        default:
            echo json_encode(array('error' => 'Invalid AJAX request'));
    }
    exit;
}

// Handle form submissions
if ($_POST) {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_category':
                if (!empty($_POST['category_name'])) {
                    $result = ProductCategoryManager::addCategory($_POST['category_name'], $_POST['description'] ?? '');
                    if (is_array($result) && $result['success']) {
                        $success_message = "Category added successfully!";
                    } else {
                        $error_message = is_array($result) ? $result['error'] : "Error adding category.";
                    }
                } else {
                    $error_message = "Category name is required.";
                }
                break;
                
            case 'add_product':
                if (!empty($_POST['product_name']) && !empty($_POST['category_id'])) {
                    $result = ProductCategoryManager::addProduct($_POST['product_name'], $_POST['category_id'], $_POST['description'] ?? '');
                    if (is_array($result) && $result['success']) {
                        $success_message = "SubCategory added successfully!";
                    } else {
                        $error_message = is_array($result) ? $result['error'] : "Error adding subcategory.";
                    }
                } else {
                    $error_message = "SubCategory name and category are required.";
                }
                break;
                
            case 'update_category':
                if (!empty($_POST['category_id']) && !empty($_POST['category_name'])) {
                    $result = ProductCategoryManager::updateCategory($_POST['category_id'], $_POST['category_name'], $_POST['description'] ?? '');
                    if ($result !== false) {
                        $success_message = "Category updated successfully!";
                    } else {
                        $error_message = "Error updating category.";
                    }
                } else {
                    $error_message = "Category ID and name are required.";
                }
                break;
                
            case 'update_product':
                if (!empty($_POST['product_id']) && !empty($_POST['product_name']) && !empty($_POST['category_id'])) {
                    $result = ProductCategoryManager::updateProduct($_POST['product_id'], $_POST['product_name'], $_POST['category_id'], $_POST['description'] ?? '');
                    if ($result !== false) {
                        $success_message = "SubCategory updated successfully!";
                    } else {
                        $error_message = "Error updating subcategory.";
                    }
                } else {
                    $error_message = "All fields are required for update.";
                }
                break;
                
            case 'delete_category':
                if (!empty($_POST['category_id'])) {
                    $result = ProductCategoryManager::deleteCategory($_POST['category_id']);
                    if ($result !== false) {
                        $success_message = "Category deleted successfully!";
                    } else {
                        $error_message = "Cannot delete category with existing products.";
                    }
                } else {
                    $error_message = "Category ID is required for deletion.";
                }
                break;
                
            case 'delete_product':
                if (!empty($_POST['product_id'])) {
                    $result = ProductCategoryManager::deleteProduct($_POST['product_id']);
                    if ($result !== false) {
                        $success_message = "SubCategory deleted successfully!";
                    } else {
                        $error_message = "Error deleting subcategory.";
                    }
                } else {
                    $error_message = "SubCategory ID is required for deletion.";
                }
                break;
        }
    }
}

// Get data for display
$categories = ProductCategoryManager::getAllCategories();
$products = ProductCategoryManager::getAllProducts();
$stats = ProductCategoryManager::getStatistics();
$recent_products = ProductCategoryManager::getRecentProducts(5);

// Handle search
$search_results = array();
if (!empty($_GET['search'])) {
    $search_results = ProductCategoryManager::searchProducts($_GET['search']);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dynamic Product Categories Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: #f7f8fa;
            font-family: 'Segoe UI', 'Roboto', 'Arial', sans-serif;
            margin: 0;
            padding: 0;
        }
        .main-container {
            max-width: 100vw;
            margin: 0;
            padding: 0;
        }
        .card {
            background: #fff;
            border-radius: 0 0 18px 18px;
            box-shadow: 0 2px 16px 0 rgba(60,72,88,0.07);
            padding: 0 0 24px 0;
            margin: 0;
            min-height: 100vh;
        }
        .header-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 24px 32px 12px 32px;
            border-bottom: 1px solid #f1f3f7;
            background: #fff;
        }
        .header-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #222;
            letter-spacing: -1px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .search-box {
            display: flex;
            align-items: center;
            gap: 0;
            margin: 0;
        }
        .search-box input {
            border-radius: 8px 0 0 8px;
            border: 1px solid #e5e7eb;
            background: #f7f8fa;
            padding: 8px 12px;
            font-size: 1rem;
            width: 220px;
            transition: border 0.2s;
            margin: 0;
        }
        .search-box input:focus {
            border: 1.5px solid #2563eb;
            outline: none;
            background: #fff;
        }
        .search-box button {
            border: none;
            background: #2563eb;
            color: #fff;
            border-radius: 0 8px 8px 0;
            padding: 8px 16px;
            font-size: 1rem;
            cursor: pointer;
            transition: background 0.2s;
            margin-left: -1px;
        }
        .search-box button:hover {
            background: #1746a2;
        }
        .btn {
            border: none;
            border-radius: 8px;
            padding: 8px 18px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            background: #f1f3f7;
            color: #222;
            transition: background 0.2s, color 0.2s;
        }
        .btn-primary {
            background: #2563eb;
            color: #fff;
        }
        .btn-primary:hover {
            background: #1746a2;
        }
        .btn-success {
            background: #22c55e;
            color: #fff;
        }
        .btn-success:hover {
            background: #15803d;
        }
        .btn-danger {
            background: #ef4444;
            color: #fff;
        }
        .btn-danger:hover {
            background: #b91c1c;
        }
        .btn-warning {
            background: #fbbf24;
            color: #222;
        }
        .btn-warning:hover {
            background: #f59e42;
        }
        .tabs {
            display: flex;
            gap: 2px;
            margin: 0 0 18px 0;
            padding: 0 32px;
            border-bottom: 2px solid #f1f3f7;
            background: #fff;
        }
        .tab {
            padding: 14px 36px 10px 36px;
            background: #f1f3f7;
            color: #222;
            border-radius: 12px 12px 0 0;
            font-weight: 500;
            cursor: pointer;
            border: none;
            outline: none;
            transition: background 0.2s, color 0.2s;
            font-size: 1.08rem;
            margin-bottom: -2px;
        }
        .tab.active {
            background: #fff;
            color: #2563eb;
            box-shadow: 0 -2px 8px 0 rgba(60,72,88,0.04);
            border-bottom: 2px solid #2563eb;
        }
        .tab-content {
            display: none;
            padding: 0 32px;
        }
        .tab-content.active {
            display: block;
        }
        .form-section {
            margin-bottom: 18px;
        }
        .form-section h2 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 12px;
            color: #222;
        }
        .form-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
        }
        .form-table td {
            padding: 0 0 0 0;
        }
        .form-table label {
            font-weight: 500;
            color: #444;
        }
        input[type="text"], input[type="number"], textarea, select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #f7f8fa;
            font-size: 1rem;
            margin-top: 4px;
            margin-bottom: 0;
            transition: border 0.2s;
        }
        input[type="text"]:focus, input[type="number"]:focus, textarea:focus, select:focus {
            border: 1.5px solid #2563eb;
            outline: none;
            background: #fff;
        }
        .table-container {
            margin-top: 8px;
        }
        .scroll-table {
            max-height: 60vh;
            overflow-y: auto;
            border-radius: 12px;
            box-shadow: 0 1px 4px 0 rgba(60,72,88,0.04);
        }
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 4px;
            background: transparent;
        }
        th, td {
            padding: 8px 8px;
            text-align: left;
        }
        th {
            background: #f1f3f7;
            color: #222;
            font-weight: 600;
            border-radius: 8px 8px 0 0;
        }
        tr {
            background: #fff;
            border-radius: 8px;
            transition: box-shadow 0.15s, background 0.15s;
        }
        tr:hover {
            background: #f6faff;
            box-shadow: 0 2px 8px 0 rgba(60,72,88,0.06);
        }
        tr:not(:last-child) {
            border-bottom: 1px solid #f1f3f7;
        }
        .category-count {
            background: #e0e7ef;
            color: #2563eb;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.95em;
            font-weight: 500;
        }
        .empty-category {
            color: #b0b0b0;
            font-style: italic;
            font-size: 0.95em;
        }
        .success, .error, .duplicate-warning {
            margin: 12px 0 0 0;
            padding: 12px 18px;
            border-radius: 8px;
            font-size: 1rem;
        }
        .success {
            background: #e7fbe7;
            color: #15803d;
        }
        .error, .duplicate-warning {
            background: #fbe7e7;
            color: #b91c1c;
        }
        .stats {
            display: flex;
            gap: 24px;
            margin-bottom: 18px;
            padding: 0 32px;
        }
        .stat-box {
            background: #f1f3f7;
            border-radius: 12px;
            padding: 14px 18px;
            text-align: center;
            flex: 1;
            box-shadow: 0 1px 4px 0 rgba(60,72,88,0.04);
        }
        .stat-box h3 {
            margin: 0;
            font-size: 1.2rem;
            color: #2563eb;
            font-weight: 700;
        }
        .stat-box p {
            margin: 4px 0 0 0;
            color: #666;
            font-size: 1rem;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(60,72,88,0.10);
            z-index: 1000;
        }
        .modal-content {
            position: absolute;
            top: 50%; left: 50%; transform: translate(-50%, -50%);
            background: #fff;
            padding: 32px 32px 24px 32px;
            border-radius: 18px;
            min-width: 400px;
            box-shadow: 0 2px 16px 0 rgba(60,72,88,0.12);
        }
        @media (max-width: 900px) {
            .main-container { padding: 0 2px; }
            .card { padding: 0 0 8px 0; }
            .modal-content { min-width: 90vw; }
            .header-bar, .tabs, .tab-content, .stats { padding-left: 8px; padding-right: 8px; }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="card">
            <div class="header-bar">
                <div class="header-title">
                    <i class="fa-solid fa-layer-group" style="color:#2563eb;"></i>
                    Product Categories Management
                </div>
                <div class="header-actions">
                    <a href="../drugs/drug_inventory.php" class="btn btn-primary"><i class="fa-solid fa-arrow-left"></i> Back</a>
                    <button onclick="refreshData()" class="btn"><i class="fa-solid fa-rotate"></i> Refresh</button>
                    <form class="search-box" method="GET" style="margin:0;">
                        <input type="text" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                        <button type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
                        <?php if (!empty($_GET['search'])): ?>
                            <a href="?" class="btn btn-warning" style="border-radius:8px 8px 8px 8px; margin-left:4px;">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Statistics -->
            <div class="stats" id="statsContainer">
                <div class="stat-box">
                    <h3 id="totalProducts"><?php echo $stats['total_products']; ?></h3>
                    <p>Total SubCategories</p>
                </div>
                <div class="stat-box">
                    <h3 id="totalCategories"><?php echo $stats['total_categories']; ?></h3>
                    <p>Total Categories</p>
                </div>
                <div class="stat-box">
                    <h3 id="activeCategories"><?php echo count($stats['products_per_category']); ?></h3>
                    <p>Active Categories</p>
                </div>
                <div class="stat-box">
                    <h3 id="emptyCategories"><?php echo count($stats['empty_categories']); ?></h3>
                    <p>Empty Categories</p>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('categories')">Categories</button>
                <button class="tab" onclick="switchTab('products')">SubCategories</button>
                <button class="tab" onclick="switchTab('overview')">Overview</button>
            </div>

            <!-- Categories Tab -->
            <div id="categoriesTab" class="tab-content active">
                <div class="form-section">
                    <h2><i class="fa-solid fa-folder-plus" style="color:#2563eb;"></i> Add New Category</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_category">
                        <table class="form-table">
                            <tr>
                                <td><label>Category Name:</label></td>
                                <td><input type="text" name="category_name" required></td>
                            </tr>
                            <tr>
                                <td><label>Description:</label></td>
                                <td><textarea name="description" rows="2"></textarea></td>
                            </tr>
                            <tr>
                                <td></td>
                                <td><button type="submit" class="btn btn-success"><i class="fa-solid fa-plus"></i> Add Category</button></td>
                            </tr>
                        </table>
                    </form>
                </div>
                <div class="table-container">
                    <h2 style="font-size:1.1rem;font-weight:600;margin-bottom:10px;">Categories <span class="category-count"><?php echo count($categories); ?></span></h2>
                    <div id="categoriesTable" class="scroll-table">
                        <?php 
                        $display_categories = $categories;
                        if (!empty($_GET['search'])) {
                            $search_term = strtolower(trim($_GET['search']));
                            $display_categories = array_filter($stats['products_per_category'], function($cat) use ($search_term) {
                                return strpos(strtolower($cat['category_name']), $search_term) !== false || strpos(strtolower($cat['description'] ?? ''), $search_term) !== false;
                            });
                        } else {
                            $display_categories = $stats['products_per_category'];
                        }
                        ?>
                        <?php if (empty($display_categories)): ?>
                            <p style="padding:16px;">No results found.</p>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Category Name</th>
                                        <th>Description</th>
                                        <th>SubCategories</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($display_categories as $category): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($category['category_id']); ?></td>
                                            <td><?php echo htmlspecialchars($category['category_name']); ?></td>
                                            <td><?php echo htmlspecialchars($category['description'] ?? ''); ?></td>
                                            <td>
                                                <span class="category-count"><?php echo $category['count']; ?></span>
                                                <?php if ($category['count'] == 0): ?>
                                                    <span class="empty-category">(empty)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button onclick="editCategory(<?php echo $category['category_id']; ?>, '<?php echo htmlspecialchars($category['category_name']); ?>', '<?php echo htmlspecialchars($category['description'] ?? ''); ?>')" class="btn btn-warning"><i class="fa-solid fa-pen"></i> Edit</button>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this category?');">
                                                    <input type="hidden" name="action" value="delete_category">
                                                    <input type="hidden" name="category_id" value="<?php echo $category['category_id']; ?>">
                                                    <button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash"></i> Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Products Tab -->
            <div id="productsTab" class="tab-content">
                <div class="form-section">
                    <h2><i class="fa-solid fa-box-open" style="color:#2563eb;"></i> Add New SubCategory</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_product">
                        <table class="form-table">
                            <tr>
                                <td><label>SubCategory Name:</label></td>
                                <td><input type="text" name="product_name" required></td>
                            </tr>
                            <tr>
                                <td><label>Category:</label></td>
                                <td>
                                    <select name="category_id" required>
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo $category['category_id']; ?>">
                                                <?php echo htmlspecialchars($category['category_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <td><label>Description:</label></td>
                                <td><textarea name="description" rows="2"></textarea></td>
                            </tr>
                            <tr>
                                <td></td>
                                <td><button type="submit" class="btn btn-success"><i class="fa-solid fa-plus"></i> Add SubCategory</button></td>
                            </tr>
                        </table>
                    </form>
                </div>
                <div class="table-container">
                    <h2 style="font-size:1.1rem;font-weight:600;margin-bottom:10px;">SubCategories <span class="category-count"><?php echo count($products); ?></span></h2>
                    <div id="productsTable" class="scroll-table">
                        <?php 
                        $display_products = $products;
                        if (!empty($_GET['search'])) {
                            $search_term = strtolower(trim($_GET['search']));
                            $display_products = array_filter($products, function($prod) use ($search_term) {
                                return strpos(strtolower($prod['product_name']), $search_term) !== false || strpos(strtolower($prod['category_name']), $search_term) !== false || strpos(strtolower($prod['description'] ?? ''), $search_term) !== false;
                            });
                        }
                        ?>
                        <?php if (empty($display_products)): ?>
                            <p style="padding:16px;">No results found.</p>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>SubCategory Name</th>
                                        <th>Category</th>
                                        <th>Description</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($display_products as $product): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($product['product_id']); ?></td>
                                            <td><?php echo htmlspecialchars($product['subcategory_name']); ?></td>
                                            <td><?php echo htmlspecialchars($product['category_name']); ?></td>
                                            <td><?php echo htmlspecialchars($product['description'] ?? ''); ?></td>
                                            <td>
                                                <button onclick="editProduct(<?php echo $product['product_id']; ?>, '<?php echo htmlspecialchars($product['product_name']); ?>', <?php echo $product['category_id']; ?>, '<?php echo htmlspecialchars($product['description'] ?? ''); ?>')" class="btn btn-warning"><i class="fa-solid fa-pen"></i> Edit</button>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this product?');">
                                                    <input type="hidden" name="action" value="delete_product">
                                                    <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                                    <button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash"></i> Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Overview Tab -->
            <div id="overviewTab" class="tab-content">
                <div class="stats" style="margin-bottom:0;">
                    <div class="stat-box">
                        <h3><?php echo $stats['total_products']; ?></h3>
                        <p>Total SubCategories</p>
                    </div>
                    <div class="stat-box">
                        <h3><?php echo $stats['total_categories']; ?></h3>
                        <p>Total Categories</p>
                    </div>
                </div>
                <div class="table-container" style="margin-top:24px;">
                    <h2 style="font-size:1.1rem;font-weight:600;margin-bottom:10px;">Recent SubCategories</h2>
                    <div id="recentProducts">
                        <?php if (empty($recent_products)): ?>
                            <p>No recent subcategories.</p>
                        <?php else: ?>
                            <ul style="list-style: none; padding: 0;">
                                <?php foreach ($recent_products as $product): ?>
                                    <li style="padding: 8px 0; border-bottom: 1px solid #eee;">
                                        <strong><?php echo htmlspecialchars($product['subcategory_name']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($product['category_name']); ?></small>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="table-container">
                    <h2 style="font-size:1.1rem;font-weight:600;margin-bottom:10px;">Categories Summary</h2>
                    <div id="categoriesSummary">
                        <?php if (empty($stats['products_per_category'])): ?>
                            <p>No categories found.</p>
                        <?php else: ?>
                            <table style="width: 100%; font-size: 14px;">
                                <thead>
                                    <tr>
                                        <th>Category</th>
                                        <th>Count</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats['products_per_category'] as $category): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($category['category_name']); ?></td>
                                            <td><?php echo $category['count']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Edit Category Modal -->
            <div id="editCategoryModal" class="modal">
                <div class="modal-content">
                    <h3 style="margin-bottom:18px;"><i class="fa-solid fa-pen"></i> Edit Category</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_category">
                        <input type="hidden" name="category_id" id="edit_category_id">
                        <table class="form-table">
                            <tr>
                                <td><label>Category Name:</label></td>
                                <td><input type="text" name="category_name" id="edit_category_name" required></td>
                            </tr>
                            <tr>
                                <td><label>Description:</label></td>
                                <td><textarea name="description" id="edit_category_description" rows="2"></textarea></td>
                            </tr>
                            <tr>
                                <td></td>
                                <td>
                                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Update Category</button>
                                    <button type="button" onclick="closeEditCategoryModal()" class="btn btn-warning">Cancel</button>
                                </td>
                            </tr>
                        </table>
                    </form>
                </div>
            </div>

            <!-- Edit Product Modal -->
            <div id="editProductModal" class="modal">
                <div class="modal-content">
                    <h3 style="margin-bottom:18px;"><i class="fa-solid fa-pen"></i> Edit SubCategory</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_product">
                        <input type="hidden" name="product_id" id="edit_product_id">
                        <table class="form-table">
                            <tr>
                                <td><label>SubCategory Name:</label></td>
                                <td><input type="text" name="product_name" id="edit_product_name" required></td>
                            </tr>
                            <tr>
                                <td><label>Category:</label></td>
                                <td>
                                    <select name="category_id" id="edit_product_category_id" required>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo $category['category_id']; ?>">
                                                <?php echo htmlspecialchars($category['category_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <td><label>Description:</label></td>
                                <td><textarea name="description" id="edit_product_description" rows="2"></textarea></td>
                            </tr>
                            <tr>
                                <td></td>
                                <td>
                                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Update SubCategory</button>
                                    <button type="button" onclick="closeEditProductModal()" class="btn btn-warning">Cancel</button>
                                </td>
                            </tr>
                        </table>
                    </form>
                </div>
            </div>

            <?php if (!empty($success_message)): ?>
                <div class="success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        let autoRefreshInterval;
        let searchActive = false;

        function isSearchActive() {
            const searchInput = document.querySelector('.search-box input[name="search"]');
            return searchInput && searchInput.value.trim().length > 0;
        }

        function refreshData(force = false) {
            // Only refresh if not searching, or if forced
            if (!isSearchActive() || force) {
                fetch('?ajax=realtime')
                    .then(response => response.json())
                    .then(data => {
                        // Update statistics
                        document.getElementById('totalProducts').textContent = data.stats.total_products;
                        document.getElementById('totalCategories').textContent = data.stats.total_categories;
                        document.getElementById('activeCategories').textContent = data.stats.total_categories;
                        document.getElementById('emptyCategories').textContent = data.stats.empty_categories.length;

                        // Update categories table
                        updateCategoriesTable(data.stats.products_per_category);

                        // Update products table
                        updateProductsTable(data.products);

                        // Update recent products
                        updateRecentProducts(data.recent_products);

                        // Update categories summary
                        updateCategoriesSummary(data.stats.products_per_category);

                        console.log('Data refreshed at:', new Date().toLocaleTimeString());
                    })
                    .catch(error => {
                        console.error('Error refreshing data:', error);
                    });
            } else {
                // If search is active, do not refresh
                console.log('Auto-refresh paused during search');
            }
        }

        // Pause auto-refresh when search is active, resume when cleared
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('.search-box input[name="search"]');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    if (isSearchActive()) {
                        // Pause auto-refresh
                        clearInterval(autoRefreshInterval);
                        searchActive = true;
                    } else {
                        // Resume auto-refresh
                        if (document.getElementById('autoRefresh').checked) {
                            autoRefreshInterval = setInterval(refreshData, 30000);
                        }
                        searchActive = false;
                        refreshData(true); // force refresh when search is cleared
                    }
                });
            }
        });

        // Auto-refresh functionality
        document.getElementById('autoRefresh').addEventListener('change', function() {
            if (this.checked && !isSearchActive()) {
                autoRefreshInterval = setInterval(refreshData, 30000); // 30 seconds
                console.log('Auto-refresh enabled');
            } else {
                clearInterval(autoRefreshInterval);
                console.log('Auto-refresh disabled');
            }
        });

        // Initialize auto-refresh
        if (document.getElementById('autoRefresh').checked && !isSearchActive()) {
            autoRefreshInterval = setInterval(refreshData, 30000);
        }

        // Refresh data on page load
        setTimeout(function() { if (!isSearchActive()) refreshData(); }, 1000);

        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName + 'Tab').classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }
        
        function updateCategoriesTable(categories) {
            const container = document.getElementById('categoriesTable');
            if (categories.length === 0) {
                container.innerHTML = '<p>No categories found.</p>';
                return;
            }
            
            let html = '<table><thead><tr><th>ID</th><th>Category Name</th><th>Description</th><th>SubCategories</th><th>Actions</th></tr></thead><tbody>';
            
            categories.forEach(category => {
                const emptyClass = category.count == 0 ? 'empty-category' : '';
                const emptyText = category.count == 0 ? ' (empty)' : '';
                
                html += `<tr>
                    <td>${category.category_id}</td>
                    <td>${category.category_name}</td>
                    <td>${category.description || ''}</td>
                    <td>
                        <span class="category-count">${category.count}</span>
                        <span class="${emptyClass}">${emptyText}</span>
                    </td>
                    <td>
                        <button onclick="editCategory(${category.category_id}, '${category.category_name}', '${category.description || ''}')" class="btn btn-warning">Edit</button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this category?');">
                            <input type="hidden" name="action" value="delete_category">
                            <input type="hidden" name="category_id" value="${category.category_id}">
                            <button type="submit" class="btn btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>`;
            });
            
            html += '</tbody></table>';
            container.innerHTML = html;
        }
        
        function updateProductsTable(products) {
            const container = document.getElementById('productsTable');
            if (products.length === 0) {
                container.innerHTML = '<p>No subcategories found.</p>';
                return;
            }
            
            let html = '<table><thead><tr><th>ID</th><th>SubCategory Name</th><th>Category</th><th>Description</th><th>Actions</th></tr></thead><tbody>';
            
            products.forEach(product => {
                html += `<tr>
                    <td>${product.product_id}</td>
                    <td>${product.subcategory_name}</td>
                    <td>${product.category_name}</td>
                    <td>${product.description || ''}</td>
                    <td>
                        <button onclick="editProduct(${product.product_id}, '${product.subcategory_name}', ${product.category_id}, '${product.description || ''}')" class="btn btn-warning">Edit</button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this subcategory?');">
                            <input type="hidden" name="action" value="delete_product">
                            <input type="hidden" name="product_id" value="${product.product_id}">
                            <button type="submit" class="btn btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>`;
            });
            
            html += '</tbody></table>';
            container.innerHTML = html;
        }
        
        function updateRecentProducts(products) {
            const container = document.getElementById('recentProducts');
            if (products.length === 0) {
                container.innerHTML = '<p>No recent subcategories.</p>';
                return;
            }
            
            let html = '<ul style="list-style: none; padding: 0;">';
            products.forEach(product => {
                html += `<li style="padding: 8px 0; border-bottom: 1px solid #eee;">
                    <strong>${product.subcategory_name}</strong><br>
                    <small>${product.category_name}</small>
                </li>`;
            });
            html += '</ul>';
            container.innerHTML = html;
        }
        
        function updateCategoriesSummary(categories) {
            const container = document.getElementById('categoriesSummary');
            if (categories.length === 0) {
                container.innerHTML = '<p>No categories found.</p>';
                return;
            }
            
            let html = '<table style="width: 100%; font-size: 14px;"><thead><tr><th>Category</th><th>Count</th></tr></thead><tbody>';
            categories.forEach(category => {
                html += `<tr><td>${category.category_name}</td><td>${category.count}</td></tr>`;
            });
            html += '</tbody></table>';
            container.innerHTML = html;
        }
        
        function editCategory(categoryId, categoryName, description) {
            document.getElementById('edit_category_id').value = categoryId;
            document.getElementById('edit_category_name').value = categoryName;
            document.getElementById('edit_category_description').value = description;
            document.getElementById('editCategoryModal').style.display = 'block';
        }

        function closeEditCategoryModal() {
            document.getElementById('editCategoryModal').style.display = 'none';
        }
        
        function editProduct(productId, productName, categoryId, description) {
            document.getElementById('edit_product_id').value = productId;
            document.getElementById('edit_product_name').value = productName;
            document.getElementById('edit_product_category_id').value = categoryId;
            document.getElementById('edit_product_description').value = description;
            document.getElementById('editProductModal').style.display = 'block';
        }

        function closeEditProductModal() {
            document.getElementById('editProductModal').style.display = 'none';
        }

        // Close modals when clicking outside
        document.getElementById('editCategoryModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditCategoryModal();
            }
        });
        
        document.getElementById('editProductModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditProductModal();
            }
        });
    </script>
</body>
</html> 