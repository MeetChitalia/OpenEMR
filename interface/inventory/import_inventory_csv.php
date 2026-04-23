<?php
/**
 * CSV Inventory Import Script
 * Imports inventory data from CSV file to OpenEMR database
 * 
 * CSV Mapping:
 * - Product Name → drugs.name
 * - Category → categories.category_name (creates if doesn't exist)
 * - Subcategory → products.subcategory_name (creates if doesn't exist)
 * - Form → drugs.form
 * - Millagram → drugs.size
 * 
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    OpenEMR
 * @copyright Copyright (c) 2025 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("$srcdir/options.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

// Check if user has access to inventory
// Allow any authenticated user to access (you can adjust this later)
if (!isset($_SESSION['authUser']) || empty($_SESSION['authUser'])) {
    die(xlt("Access Denied - Please log in"));
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// CSV file path
$csv_file_path = $GLOBALS['OE_SITE_DIR'] . '/../../Files/inventory.csv';

// Process the import if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import'])) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
        exit;
    }
    
    $import_results = importInventoryFromCSV($csv_file_path);
}

/**
 * Get or create category
 */
function getOrCreateCategory($category_name) {
    // Check if category exists
    $check_sql = "SELECT category_id FROM categories WHERE category_name = ?";
    $result = sqlStatement($check_sql, array($category_name));
    $row = sqlFetchArray($result);
    
    if ($row && isset($row['category_id'])) {
        return $row['category_id'];
    }
    
    // Create new category
    $insert_sql = "INSERT INTO categories (category_name, is_active) VALUES (?, 1)";
    $category_id = sqlInsert($insert_sql, array($category_name));
    
    return $category_id;
}

/**
 * Get or create product (subcategory)
 */
function getOrCreateProduct($subcategory_name, $category_id) {
    // Check if product exists
    $check_sql = "SELECT product_id FROM products WHERE subcategory_name = ? AND category_id = ?";
    $result = sqlStatement($check_sql, array($subcategory_name, $category_id));
    $row = sqlFetchArray($result);
    
    if ($row && isset($row['product_id'])) {
        return $row['product_id'];
    }
    
    // Create new product
    $insert_sql = "INSERT INTO products (subcategory_name, category_id, is_active) VALUES (?, ?, 1)";
    $product_id = sqlInsert($insert_sql, array($subcategory_name, $category_id));
    
    return $product_id;
}

/**
 * Import inventory from CSV
 */
function importInventoryFromCSV($csv_file_path) {
    $results = array(
        'success' => 0,
        'skipped' => 0,
        'errors' => array(),
        'total' => 0
    );
    
    if (!file_exists($csv_file_path)) {
        $results['errors'][] = "CSV file not found: $csv_file_path";
        return $results;
    }
    
    $handle = fopen($csv_file_path, 'r');
    if (!$handle) {
        $results['errors'][] = "Could not open CSV file";
        return $results;
    }
    
    // Read header row
    $header = fgetcsv($handle);
    if (!$header) {
        $results['errors'][] = "CSV file is empty or invalid";
        fclose($handle);
        return $results;
    }
    
    // Expected columns: Product Name, Category, Subcategory, Form, Millagram
    $expected_columns = array('Product Name', 'Category', 'Subcategory', 'Form', 'Millagram');
    
    $line_number = 1;
    
    while (($row = fgetcsv($handle)) !== false) {
        $line_number++;
        $results['total']++;
        
        // Skip empty rows
        if (empty($row[0]) || trim($row[0]) === '') {
            $results['skipped']++;
            continue;
        }
        
        try {
            $product_name = trim($row[0]);
            $category_name = trim($row[1]);
            $subcategory_name = trim($row[2]);
            $form = trim($row[3]);
            $size = trim($row[4]);
            
            // Skip if product name is empty
            if (empty($product_name)) {
                $results['skipped']++;
                continue;
            }
            
            // Check if product already exists
            $check_drug_sql = "SELECT drug_id FROM drugs WHERE name = ?";
            $existing_drug_result = sqlStatement($check_drug_sql, array($product_name));
            $existing_drug = sqlFetchArray($existing_drug_result);
            
            if ($existing_drug && isset($existing_drug['drug_id'])) {
                $results['skipped']++;
                continue; // Skip if product already exists
            }
            
            // Get or create category
            $category_id = getOrCreateCategory($category_name);
            
            // Get or create product (subcategory)
            $product_id = getOrCreateProduct($subcategory_name, $category_id);
            
            // Handle N/A values
            $form_value = ($form === 'N/A' || empty($form)) ? '' : $form;
            $size_value = ($size === 'N/A' || empty($size)) ? '' : $size;
            
            // Insert into drugs table
            $insert_drug_sql = "INSERT INTO drugs (
                name, 
                category, 
                form, 
                size, 
                category_id, 
                category_name,
                product_id,
                active,
                ndc_number,
                route,
                strength,
                substitute,
                related_code,
                cyp_factor,
                allow_combining,
                allow_multiple,
                consumable,
                dispensable,
                unit
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, '', '0', '0', 0, '', 0, 0, 1, 0, 1, '0')";
            
            $insert_result = sqlInsert($insert_drug_sql, array(
                $product_name,
                $category_name,
                $form_value,
                $size_value,
                $category_id,
                $category_name,
                $product_id
            ));
            
            if ($insert_result) {
                $results['success']++;
            } else {
                $results['errors'][] = "Line $line_number: Failed to insert product '$product_name'";
            }
            
        } catch (Exception $e) {
            $results['errors'][] = "Line $line_number: " . $e->getMessage();
        }
    }
    
    fclose($handle);
    
    return $results;
}

?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <title><?php echo xlt('Import Inventory from CSV'); ?></title>
    <style>
        .import-container {
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .import-header {
            background: #007bff;
            color: white;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .info-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #007bff;
        }
        .info-section h3 {
            margin-top: 0;
            color: #007bff;
        }
        .info-section ul {
            margin-bottom: 0;
        }
        .import-button {
            background: #28a745;
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
        }
        .import-button:hover {
            background: #218838;
        }
        .results-section {
            margin-top: 20px;
            padding: 20px;
            border-radius: 5px;
        }
        .results-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .results-warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }
        .results-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .results-summary {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .error-list {
            max-height: 300px;
            overflow-y: auto;
            background: white;
            padding: 10px;
            border-radius: 3px;
            margin-top: 10px;
        }
        .error-item {
            padding: 5px;
            border-bottom: 1px solid #eee;
        }
        .back-button {
            background: #6c757d;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-top: 10px;
        }
        .back-button:hover {
            background: #545b62;
            color: white;
            text-decoration: none;
        }
        .warning-box {
            background: #fff3cd;
            border: 2px solid #ffc107;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .warning-box h4 {
            color: #856404;
            margin-top: 0;
        }
    </style>
</head>
<body class="body_top">
    <div class="import-container">
        <div class="import-header">
            <h2><?php echo xlt('Import Inventory from CSV'); ?></h2>
        </div>
        
        <?php if (!isset($import_results)): ?>
            <div class="warning-box">
                <h4>⚠️ <?php echo xlt('Important'); ?></h4>
                <p><?php echo xlt('This will import inventory data from the CSV file. Existing products with the same name will be skipped.'); ?></p>
                <p><?php echo xlt('Please make sure you have a backup of your database before proceeding.'); ?></p>
            </div>
            
            <div class="info-section">
                <h3><?php echo xlt('CSV File Mapping'); ?></h3>
                <ul>
                    <li><strong><?php echo xlt('Product Name'); ?>:</strong> <?php echo xlt('Will be imported as product name'); ?></li>
                    <li><strong><?php echo xlt('Category'); ?>:</strong> <?php echo xlt('Will create category if it doesn\'t exist'); ?></li>
                    <li><strong><?php echo xlt('Subcategory'); ?>:</strong> <?php echo xlt('Will create product subcategory if it doesn\'t exist'); ?></li>
                    <li><strong><?php echo xlt('Form'); ?>:</strong> <?php echo xlt('Product form (e.g., Tablet, Injection)'); ?></li>
                    <li><strong><?php echo xlt('Millagram'); ?>:</strong> <?php echo xlt('Product size/strength'); ?></li>
                </ul>
            </div>
            
            <div class="info-section">
                <h3><?php echo xlt('CSV File Location'); ?></h3>
                <p><code><?php echo text($csv_file_path); ?></code></p>
                <?php if (file_exists($csv_file_path)): ?>
                    <p style="color: green;">✓ <?php echo xlt('CSV file found'); ?> (<?php echo number_format(filesize($csv_file_path)); ?> <?php echo xlt('bytes'); ?>)</p>
                <?php else: ?>
                    <p style="color: red;">✗ <?php echo xlt('CSV file not found'); ?></p>
                <?php endif; ?>
            </div>
            
            <form method="POST">
                <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
                <input type="hidden" name="import" value="1">
                <button type="submit" class="import-button" <?php echo !file_exists($csv_file_path) ? 'disabled' : ''; ?>>
                    <?php echo xlt('Start Import'); ?>
                </button>
                <a href="../reports/inventory_list.php" class="back-button"><?php echo xlt('Cancel'); ?></a>
            </form>
        <?php else: ?>
            <!-- Display import results -->
            <?php 
            $has_errors = count($import_results['errors']) > 0;
            $all_successful = $import_results['success'] > 0 && !$has_errors;
            $result_class = $all_successful ? 'results-success' : ($has_errors ? 'results-error' : 'results-warning');
            ?>
            
            <div class="results-section <?php echo $result_class; ?>">
                <div class="results-summary">
                    <?php echo xlt('Import Complete'); ?>
                </div>
                
                <p><strong><?php echo xlt('Total rows processed'); ?>:</strong> <?php echo text($import_results['total']); ?></p>
                <p><strong><?php echo xlt('Successfully imported'); ?>:</strong> <?php echo text($import_results['success']); ?></p>
                <p><strong><?php echo xlt('Skipped (duplicates or empty)'); ?>:</strong> <?php echo text($import_results['skipped']); ?></p>
                <p><strong><?php echo xlt('Errors'); ?>:</strong> <?php echo text(count($import_results['errors'])); ?></p>
                
                <?php if (count($import_results['errors']) > 0): ?>
                    <h4><?php echo xlt('Error Details'); ?>:</h4>
                    <div class="error-list">
                        <?php foreach ($import_results['errors'] as $error): ?>
                            <div class="error-item"><?php echo text($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <a href="../reports/inventory_list.php" class="back-button"><?php echo xlt('View Inventory'); ?></a>
                <a href="import_inventory_csv.php" class="back-button"><?php echo xlt('Import Again'); ?></a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

