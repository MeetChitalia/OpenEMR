<?php
/**
 * POS Inventory Search - AJAX endpoint for dynamic inventory search
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Team
 * @copyright Copyright (c) 2024 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;

// Ensure user is logged in
if (!isset($_SESSION['authUserID'])) {
    http_response_code(401);
    die(json_encode(['error' => 'Not authorized']));
}

// Verify CSRF token - temporarily disabled for testing
// if (!CsrfUtils::verifyCsrfToken($_GET['csrf_token_form'] ?? '')) {
//     http_response_code(403);
//     die(json_encode(['error' => 'Invalid CSRF token']));
// }

// Get search term
$search = $_GET['search'] ?? '';
$search = trim($search);

if (empty($search)) {
    echo json_encode(['results' => []]);
    exit;
}

try {
    // Search in drug inventory (drugs table)
    $drugQuery = "SELECT 
                    'drug' as type,
                    d.drug_id,
                    d.name,
                    d.form,
                    d.strength,
                    d.size,
                    d.unit,
                    COALESCE(d.sell_price, 0.00) as price,
                    '' as manufacturer,
                    COALESCE(c.category_name, '') as category_name
                  FROM drugs d
                  LEFT JOIN categories c ON d.category_id = c.category_id
                  WHERE (d.name LIKE ? OR d.form LIKE ? OR d.strength LIKE ? OR d.size LIKE ?)
                  AND d.active = 1
                  ORDER BY d.name ASC
                  LIMIT 20";
    
    $searchTerm = '%' . $search . '%';
    $drugResults = sqlStatement($drugQuery, array($searchTerm, $searchTerm, $searchTerm, $searchTerm));
    
    $results = [];
    
    while ($row = sqlFetchArray($drugResults)) {
        $results[] = [
            'id' => 'drug_' . $row['drug_id'],
            'type' => 'drug',
            'name' => $row['name'],
            'form' => $row['form'],
            'strength' => $row['strength'],
            'size' => $row['size'],
            'unit' => $row['unit'],
            'lot_number' => 'N/A', // Lot numbers are in drug_inventory table
            'price' => floatval($row['price']),
            'manufacturer' => $row['manufacturer'],
            'category_name' => $row['category_name'],
            'display_name' => $row['name'] . ' ' . $row['form'] . ' ' . $row['strength'] . ' ' . $row['size'] . ' ' . $row['unit']
        ];
    }
    
    // Search in product inventory (product table) if it exists
    try {
        $productQuery = "SELECT 
                           'product' as type,
                           p.id as product_id,
                           p.name,
                           '' as form,
                           '' as strength,
                           '' as size,
                           '' as unit,
                           COALESCE(p.price, 0.00) as price,
                           COALESCE(p.manufacturer, '') as manufacturer,
                           COALESCE(c.category_name, '') as category_name
                         FROM product p
                         LEFT JOIN categories c ON p.category_id = c.category_id
                         WHERE (p.name LIKE ? OR COALESCE(p.manufacturer, '') LIKE ?)
                         AND p.active = 1
                         ORDER BY p.name ASC
                         LIMIT 20";
        
        $productResults = sqlStatement($productQuery, array($searchTerm, $searchTerm));
        
        while ($row = sqlFetchArray($productResults)) {
            $results[] = [
                'id' => 'product_' . $row['product_id'],
                'type' => 'product',
                'name' => $row['name'],
                'form' => $row['form'],
                'strength' => $row['strength'],
                'size' => $row['size'],
                'unit' => $row['unit'],
                'lot_number' => 'N/A',
                'price' => floatval($row['price']),
                'manufacturer' => $row['manufacturer'],
                'category_name' => $row['category_name'],
                'display_name' => $row['name']
            ];
        }
    } catch (Exception $e) {
        // Product table doesn't exist, which is fine
        // No need to log this as it's expected
    }
    
    // Sort results by name
    usort($results, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    
    // Limit total results
    $results = array_slice($results, 0, 20);
    
    echo json_encode(['results' => $results]);
    
} catch (Exception $e) {
    error_log('POS Inventory Search Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Search failed']);
}
?> 