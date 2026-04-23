<?php

/**
 * Product Category Manager Class
 * Dynamic structure: Categories can have many products, products belong to one category
 * All methods are static for real-time dynamic functionality
 * 
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Your Name
 * @copyright Copyright (c) 2024
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

class ProductCategoryManager
{
    /**
     * Get all categories
     */
    public static function getAllCategories($active_only = true) {
        $where = $active_only ? "WHERE is_active = 1" : "";
        $sql = "SELECT * FROM categories $where ORDER BY category_name";
        $result = sqlStatement($sql);
        $categories = array();
        while ($row = sqlFetchArray($result)) {
            $categories[] = $row;
        }
        return $categories;
    }
    
    /**
     * Get category by ID
     */
    public static function getCategoryById($category_id) {
        $sql = "SELECT * FROM categories WHERE category_id = ?";
        $result = sqlStatement($sql, array($category_id));
        return sqlFetchArray($result);
    }
    
    /**
     * Add new category
     */
    public static function addCategory($category_name, $description = '') {
        // Check if category already exists
        if (self::categoryExists($category_name)) {
            return array('success' => false, 'error' => 'Category already exists: ' . $category_name);
        }
        
        $sql = "INSERT INTO categories (category_name, description) VALUES (?, ?)";
        $result = sqlInsert($sql, array($category_name, $description));
        
        if ($result !== false) {
            return array('success' => true, 'id' => $result);
        } else {
            return array('success' => false, 'error' => 'Database error occurred while adding category');
        }
    }
    
    /**
     * Update category
     */
    public static function updateCategory($category_id, $category_name, $description = '', $is_active = true) {
        $sql = "UPDATE categories SET category_name = ?, description = ?, is_active = ? WHERE category_id = ?";
        return sqlStatement($sql, array($category_name, $description, $is_active ? 1 : 0, $category_id));
    }
    
    /**
     * Delete category (only if no products exist)
     */
    public static function deleteCategory($category_id) {
        // Check if category has products
        $res = sqlStatement("SELECT COUNT(*) as count FROM products WHERE category_id = ?", array($category_id));
        $row = sqlFetchArray($res);
        if ($row['count'] > 0) {
            return false; // Cannot delete category with products
        }
        
        $sql = "DELETE FROM categories WHERE category_id = ?";
        return sqlStatement($sql, array($category_id));
    }
    
    /**
     * Get all products with their category information
     */
    public static function getAllProducts($active_only = true) {
        $where = $active_only ? "WHERE p.is_active = 1" : "";
        $sql = "SELECT p.*, c.category_name, c.description as category_description 
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.category_id 
                $where 
                ORDER BY p.subcategory_name";
        $result = sqlStatement($sql);
        $products = array();
        while ($row = sqlFetchArray($result)) {
            $products[] = $row;
        }
        return $products;
    }
    
    /**
     * Get product by ID
     */
    public static function getProductById($product_id) {
        $sql = "SELECT p.*, c.category_name, c.description as category_description 
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.category_id 
                WHERE p.product_id = ?";
        $result = sqlStatement($sql, array($product_id));
        return sqlFetchArray($result);
    }
    
    /**
     * Add new product
     */
    public static function addProduct($product_name, $category_id, $description = '') {
        // Check if product already exists
        if (self::productExists($product_name)) {
            return array('success' => false, 'error' => 'SubCategory already exists: ' . $product_name);
        }
        
        // Check if category exists
        $category = self::getCategoryById($category_id);
        if (!$category) {
            return array('success' => false, 'error' => 'Selected category does not exist');
        }
        
        $sql = "INSERT INTO products (subcategory_name, category_id, description) VALUES (?, ?, ?)";
        $result = sqlInsert($sql, array($product_name, $category_id, $description));
        
        if ($result !== false) {
            return array('success' => true, 'id' => $result);
        } else {
            return array('success' => false, 'error' => 'Database error occurred while adding product');
        }
    }
    
    /**
     * Update product
     */
    public static function updateProduct($product_id, $product_name, $category_id, $description = '', $is_active = true) {
        $sql = "UPDATE products SET subcategory_name = ?, category_id = ?, description = ?, is_active = ? WHERE product_id = ?";
        return sqlStatement($sql, array($product_name, $category_id, $description, $is_active ? 1 : 0, $product_id));
    }
    
    /**
     * Delete product
     */
    public static function deleteProduct($product_id) {
        $sql = "DELETE FROM products WHERE product_id = ?";
        return sqlStatement($sql, array($product_id));
    }
    
    /**
     * Get products by category
     */
    public static function getProductsByCategory($category_id, $active_only = true) {
        $where = $active_only ? "AND p.is_active = 1" : "";
        $sql = "SELECT p.*, c.category_name 
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.category_id 
                WHERE p.category_id = ? $where 
                ORDER BY p.subcategory_name";
        $result = sqlStatement($sql, array($category_id));
        $products = array();
        while ($row = sqlFetchArray($result)) {
            $products[] = $row;
        }
        return $products;
    }
    
    /**
     * Search products
     */
    public static function searchProducts($search_term, $active_only = true) {
        $search_term = '%' . $search_term . '%';
        $where = $active_only ? "AND p.is_active = 1" : "";
        $sql = "SELECT p.*, c.category_name 
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.category_id 
                WHERE (p.subcategory_name LIKE ? OR c.category_name LIKE ? OR p.description LIKE ?) $where 
                ORDER BY p.subcategory_name";
        $result = sqlStatement($sql, array($search_term, $search_term, $search_term));
        $products = array();
        while ($row = sqlFetchArray($result)) {
            $products[] = $row;
        }
        return $products;
    }
    
    /**
     * Search categories
     */
    public static function searchCategories($search_term, $active_only = true) {
        $search_term = '%' . $search_term . '%';
        $where = $active_only ? "AND is_active = 1" : "";
        $sql = "SELECT * FROM categories WHERE (category_name LIKE ? OR description LIKE ?) $where ORDER BY category_name";
        $result = sqlStatement($sql, array($search_term, $search_term));
        $categories = array();
        while ($row = sqlFetchArray($result)) {
            $categories[] = $row;
        }
        return $categories;
    }
    
    /**
     * Get statistics
     */
    public static function getStatistics() {
        $stats = array();
        
        // Total products
        $result = sqlStatement("SELECT COUNT(*) as count FROM products WHERE is_active = 1");
        $row = sqlFetchArray($result);
        $stats['total_products'] = $row['count'];
        
        // Total categories
        $result = sqlStatement("SELECT COUNT(*) as count FROM categories WHERE is_active = 1");
        $row = sqlFetchArray($result);
        $stats['total_categories'] = $row['count'];
        
        // Products per category
        $sql = "SELECT c.category_name, c.category_id, COUNT(p.product_id) as count 
                FROM categories c 
                LEFT JOIN products p ON c.category_id = p.category_id AND p.is_active = 1 
                WHERE c.is_active = 1 
                GROUP BY c.category_id, c.category_name 
                ORDER BY count DESC";
        $result = sqlStatement($sql);
        $stats['products_per_category'] = array();
        while ($row = sqlFetchArray($result)) {
            $stats['products_per_category'][] = $row;
        }
        
        // Categories with no products
        $sql = "SELECT c.category_name, c.category_id 
                FROM categories c 
                LEFT JOIN products p ON c.category_id = p.category_id AND p.is_active = 1 
                WHERE c.is_active = 1 AND p.product_id IS NULL 
                ORDER BY c.category_name";
        $result = sqlStatement($sql);
        $stats['empty_categories'] = array();
        while ($row = sqlFetchArray($result)) {
            $stats['empty_categories'][] = $row;
        }
        
        return $stats;
    }
    
    /**
     * Check if product exists
     */
    public static function productExists($product_name) {
        $sql = "SELECT COUNT(*) as count FROM products WHERE subcategory_name = ?";
        $result = sqlStatement($sql, array($product_name));
        $row = sqlFetchArray($result);
        return $row['count'] > 0;
    }
    
    /**
     * Check if category exists
     */
    public static function categoryExists($category_name) {
        $sql = "SELECT COUNT(*) as count FROM categories WHERE category_name = ?";
        $result = sqlStatement($sql, array($category_name));
        $row = sqlFetchArray($result);
        return $row['count'] > 0;
    }
    
    /**
     * Get next available product ID
     */
    public static function getNextProductId() {
        $sql = "SELECT MAX(product_id) as max_id FROM products";
        $result = sqlStatement($sql);
        $row = sqlFetchArray($result);
        return ($row['max_id'] ?? 0) + 1;
    }
    
    /**
     * Get next available category ID
     */
    public static function getNextCategoryId() {
        $sql = "SELECT MAX(category_id) as max_id FROM categories";
        $result = sqlStatement($sql);
        $row = sqlFetchArray($result);
        return ($row['max_id'] ?? 0) + 1;
    }
    
    /**
     * Get real-time data for AJAX updates
     */
    public static function getRealTimeData() {
        return array(
            'categories' => self::getAllCategories(),
            'products' => self::getAllProducts(),
            'stats' => self::getStatistics(),
            'recent_products' => self::getRecentProducts(5),
            'timestamp' => time()
        );
    }
    
    /**
     * Get recent products (last 10 added)
     */
    public static function getRecentProducts($limit = 10) {
        $sql = "SELECT p.*, c.category_name 
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.category_id 
                WHERE p.is_active = 1 
                ORDER BY p.product_id DESC 
                LIMIT ?";
        $result = sqlStatement($sql, array($limit));
        $products = array();
        while ($row = sqlFetchArray($result)) {
            $products[] = $row;
        }
        return $products;
    }
    
    /**
     * Get category counts for real-time updates
     */
    public static function getCategoryCounts() {
        $sql = "SELECT c.category_name, c.category_id, COUNT(p.product_id) as count 
                FROM categories c 
                LEFT JOIN products p ON c.category_id = p.category_id AND p.is_active = 1 
                WHERE c.is_active = 1 
                GROUP BY c.category_id, c.category_name 
                ORDER BY count DESC";
        $result = sqlStatement($sql);
        $counts = array();
        while ($row = sqlFetchArray($result)) {
            $counts[] = $row;
        }
        return $counts;
    }
    
    /**
     * Move products from one category to another
     */
    public static function moveProductsToCategory($from_category_id, $to_category_id) {
        $sql = "UPDATE products SET category_id = ? WHERE category_id = ?";
        return sqlStatement($sql, array($to_category_id, $from_category_id));
    }
    
    /**
     * Get products without category (orphaned)
     */
    public static function getOrphanedProducts() {
        $sql = "SELECT p.* FROM products p 
                LEFT JOIN categories c ON p.category_id = c.category_id 
                WHERE c.category_id IS NULL AND p.is_active = 1 
                ORDER BY p.product_name";
        $result = sqlStatement($sql);
        $products = array();
        while ($row = sqlFetchArray($result)) {
            $products[] = $row;
        }
        return $products;
    }
} 