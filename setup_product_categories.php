<?php
/**
 * Setup script for Product Categories
 * Run this script to create the necessary database tables
 * Dynamic structure: Categories can have many products, products belong to one category
 */

// Include the OpenEMR globals
require_once 'interface/globals.php';

echo "<h1>Product Categories Setup - Dynamic Structure</h1>";

// SQL to create the tables
$sql_commands = array(
    // Drop tables if they exist (in correct order due to foreign keys)
    "DROP TABLE IF EXISTS `product_category_mapping`",
    "DROP TABLE IF EXISTS `product_categories`",
    "DROP TABLE IF EXISTS `categories`",
    "DROP TABLE IF EXISTS `products`",
    
    // Create the categories table
    "CREATE TABLE `categories` (
        `category_id` int(11) NOT NULL AUTO_INCREMENT,
        `category_name` varchar(255) NOT NULL,
        `description` text,
        `is_active` tinyint(1) DEFAULT 1,
        `created_date` timestamp DEFAULT CURRENT_TIMESTAMP,
        `updated_date` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`category_id`),
        UNIQUE KEY `category_name` (`category_name`),
        KEY `is_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    
    // Create the products table
    "CREATE TABLE `products` (
        `product_id` int(11) NOT NULL AUTO_INCREMENT,
        `product_name` varchar(255) NOT NULL,
        `description` text,
        `category_id` int(11) NOT NULL,
        `is_active` tinyint(1) DEFAULT 1,
        `created_date` timestamp DEFAULT CURRENT_TIMESTAMP,
        `updated_date` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`product_id`),
        UNIQUE KEY `product_name` (`product_name`),
        KEY `category_id` (`category_id`),
        KEY `is_active` (`is_active`),
        FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    
    // Insert default categories
    "INSERT INTO `categories` (`category_name`, `description`) VALUES
        ('Medications', 'Prescription and over-the-counter medications'),
        ('Medical Supplies', 'Medical equipment and supplies'),
        ('Surgical Instruments', 'Surgical tools and instruments'),
        ('Laboratory Supplies', 'Lab testing materials and equipment'),
        ('Personal Protective Equipment', 'PPE and safety equipment'),
        ('Diagnostic Equipment', 'Medical diagnostic devices'),
        ('Emergency Supplies', 'Emergency medical supplies'),
        ('Pharmaceuticals', 'Drug products and medications'),
        ('Consumables', 'Disposable medical items'),
        ('Equipment', 'Medical equipment and devices')",
    
    // Insert sample products
    "INSERT INTO `products` (`product_name`, `description`, `category_id`) VALUES
        ('Aspirin 500mg', 'Pain relief medication', 1),
        ('Ibuprofen 400mg', 'Anti-inflammatory medication', 1),
        ('Bandages', 'Medical bandages for wound care', 2),
        ('Surgical Scissors', 'Sterile surgical scissors', 3),
        ('Test Tubes', 'Laboratory test tubes', 4),
        ('Face Masks', 'Disposable face masks', 5),
        ('Stethoscope', 'Medical diagnostic tool', 6),
        ('Emergency Kit', 'First aid emergency kit', 7),
        ('Syringes', 'Disposable medical syringes', 9),
        ('Blood Pressure Monitor', 'Digital BP monitoring device', 10),
        ('Paracetamol 500mg', 'Fever and pain relief', 1),
        ('Gauze Pads', 'Sterile gauze pads', 2),
        ('Surgical Forceps', 'Medical forceps', 3),
        ('Microscope Slides', 'Lab microscope slides', 4),
        ('N95 Masks', 'High-grade protective masks', 5),
        ('Thermometer', 'Digital thermometer', 6),
        ('Defibrillator', 'Emergency defibrillator', 7),
        ('Gloves', 'Disposable medical gloves', 9),
        ('X-Ray Machine', 'Medical imaging equipment', 10),
        ('Antibiotics', 'General antibiotics', 8)",
    
    // Create indexes for better performance
    "CREATE INDEX `idx_products_category_active` ON `products` (`category_id`, `is_active`)",
    "CREATE INDEX `idx_categories_active` ON `categories` (`is_active`)",
    "CREATE INDEX `idx_products_name` ON `products` (`product_name`)",
    "CREATE INDEX `idx_categories_name` ON `categories` (`category_name`)"
);

// Execute each SQL command
$success_count = 0;
$error_count = 0;

foreach ($sql_commands as $sql) {
    try {
        $result = sqlStatement($sql);
        if ($result !== false) {
            echo "<p style='color: green;'>✅ Success: " . substr($sql, 0, 50) . "...</p>";
            $success_count++;
        } else {
            echo "<p style='color: red;'>❌ Error: " . substr($sql, 0, 50) . "...</p>";
            $error_count++;
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Exception: " . $e->getMessage() . "</p>";
        $error_count++;
    }
}

echo "<h2>Setup Complete</h2>";
echo "<p>Successful operations: $success_count</p>";
echo "<p>Errors: $error_count</p>";

if ($error_count == 0) {
    echo "<p style='color: green; font-weight: bold;'>✅ Dynamic Product Categories tables created successfully!</p>";
    echo "<p><a href='interface/product_categories/manage_categories.php'>Go to Product Categories Management</a></p>";
} else {
    echo "<p style='color: red; font-weight: bold;'>❌ Some errors occurred during setup.</p>";
}

// Test the tables
echo "<h2>Testing Tables</h2>";

try {
    $res = sqlStatement("SELECT COUNT(*) as count FROM categories");
    $row = sqlFetchArray($res);
    echo "<p>Categories table: " . $row['count'] . " records</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Error testing categories table: " . $e->getMessage() . "</p>";
}

try {
    $res = sqlStatement("SELECT COUNT(*) as count FROM products");
    $row = sqlFetchArray($res);
    echo "<p>Products table: " . $row['count'] . " records</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Error testing products table: " . $e->getMessage() . "</p>";
}

// Show sample data
echo "<h3>Sample Data Structure:</h3>";
try {
    $res = sqlStatement("SELECT c.category_name, COUNT(p.product_id) as product_count 
                        FROM categories c 
                        LEFT JOIN products p ON c.category_id = p.category_id 
                        GROUP BY c.category_id, c.category_name 
                        ORDER BY product_count DESC");
    
    echo "<table border='1' style='border-collapse: collapse; margin-top: 10px;'>";
    echo "<tr><th>Category</th><th>Product Count</th></tr>";
    while ($row = sqlFetchArray($res)) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['category_name']) . "</td>";
        echo "<td>" . $row['product_count'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Error showing sample data: " . $e->getMessage() . "</p>";
}
?> 