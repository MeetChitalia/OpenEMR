<?php
/**
 * Simple POS Inventory Search - No authentication required for testing
 */

// Set content type to JSON
header('Content-Type: application/json');

// Use OpenEMR's database configuration
require_once(__DIR__ . '/../../sites/default/sqlconf.php');

try {
    $pdo = new PDO(
        "mysql:host=" . $host . ";port=" . $port . ";dbname=" . $dbase . ";charset=utf8mb4",
        $login,
        $pass,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
} catch (PDOException $e) {
    error_log("Database connection failed in simple_search.php: " . $e->getMessage());
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

function ensureDrugInventoryQuantitySchema($pdo)
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `drug_inventory` LIKE 'on_hand'");
        $stmt->execute();
        $column = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$column) {
            return;
        }

        $columnType = strtolower((string) ($column['Type'] ?? ''));
        if (strpos($columnType, 'decimal') !== false) {
            return;
        }

        $alter = $pdo->prepare("ALTER TABLE `drug_inventory` MODIFY `on_hand` DECIMAL(12,4) NOT NULL DEFAULT 0");
        $alter->execute();
        error_log("simple_search.php - Upgraded drug_inventory.on_hand from {$columnType} to DECIMAL(12,4)");
    } catch (Exception $e) {
        error_log("simple_search.php - Failed to upgrade drug_inventory.on_hand: " . $e->getMessage());
    }
}

ensureDrugInventoryQuantitySchema($pdo);

function isLiquidInventoryDrugData($drug)
{
    $form = strtolower(trim((string) ($drug['form'] ?? '')));
    $size = trim((string) ($drug['size'] ?? ''));
    $unit = trim((string) ($drug['unit'] ?? ''));
    $route = trim((string) ($drug['route'] ?? ''));

    if ($form === 'ml') {
        return true;
    }

    $mlPerVial = null;
    if (preg_match('/-?\d+(?:\.\d+)?/', $size, $sizeMatches)) {
        $mlPerVial = (float) $sizeMatches[0];
    }

    $mgPerMl = null;
    if (
        preg_match(
            '/(-?\d+(?:\.\d+)?)\s*mg\s*\/\s*(\d+(?:\.\d+)?)?\s*(ml|mL|cc)/i',
            $unit,
            $unitMatches
        )
    ) {
        $mgAmount = (float) $unitMatches[1];
        $volumeAmount = empty($unitMatches[2]) ? 1.0 : (float) $unitMatches[2];
        if ($volumeAmount > 0) {
            $mgPerMl = $mgAmount / $volumeAmount;
        }
    }

    return (
        (
            strpos($form, 'vial') !== false ||
            strpos($form, 'inject') !== false ||
            stripos($size, 'ml') !== false ||
            stripos($unit, '/ml') !== false ||
            stripos($unit, '/ mL') !== false ||
            stripos($unit, 'cc') !== false ||
            stripos($route, 'intramuscular') !== false
        ) &&
        $mlPerVial !== null && $mlPerVial > 0 &&
        $mgPerMl !== null && $mgPerMl > 0
    );
}

// Check if search parameter is provided
if (!isset($_GET['search']) || empty($_GET['search'])) {
    echo json_encode(['error' => 'Search parameter is required']);
    exit;
}

// Handle discount lookup request
if (isset($_GET['action']) && $_GET['action'] === 'get_discounts') {
    $drugIds = $_GET['drug_ids'] ?? '';
    $drugIds = explode(',', $drugIds);
    $drugIds = array_filter($drugIds, 'is_numeric');
    
    if (empty($drugIds)) {
        echo json_encode(['success' => false, 'error' => 'No valid drug IDs provided']);
        exit;
    }
    
    try {
        $discounts = array();
        $placeholders = str_repeat('?,', count($drugIds) - 1) . '?';
        
        $query = "SELECT 
                    drug_id,
                    COALESCE(sell_price, 0.00) as original_price,
                    discount_active,
                    discount_type,
                    discount_percent,
                    discount_amount,
                    discount_quantity,
                    discount_start_date,
                    discount_end_date,
                    discount_month,
                    discount_description
                  FROM drugs 
                  WHERE drug_id IN ($placeholders)";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($drugIds);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $drugId = $row['drug_id'];
            
            // Check if discount is active (REAL-TIME)
            $isDiscountActive = isDiscountActive(
                $row['discount_active'],
                $row['discount_start_date'],
                $row['discount_end_date'],
                $row['discount_month']
            );
            
            // Calculate prices
            $originalPrice = floatval($row['original_price']);
            $discountedPrice = $originalPrice;
            $discountInfo = null;
            
            if ($isDiscountActive) {
                $discountedPrice = calculateDiscountedPrice(
                    $originalPrice,
                    $row['discount_type'],
                    $row['discount_percent'],
                    $row['discount_amount']
                );
                
                $discountInfo = [
                    'type' => $row['discount_type'],
                    'percent' => floatval($row['discount_percent'] ?? 0),
                    'amount' => floatval($row['discount_amount'] ?? 0),
                    'quantity' => isset($row['discount_quantity']) ? intval($row['discount_quantity']) : null,
                    'description' => $row['discount_description'] ?? '',
                    'original_price' => $originalPrice,
                    'discounted_price' => $discountedPrice
                ];
            }
            
            $discounts[$drugId] = [
                'has_discount' => $isDiscountActive,
                'discount_info' => $discountInfo,
                'original_price' => $originalPrice,
                'discounted_price' => $discountedPrice
            ];
        }
        
        echo json_encode(['success' => true, 'discounts' => $discounts]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Discount lookup failed: ' . $e->getMessage()]);
    }
    exit;
}

// Get search term
$search = $_GET['search'] ?? '';
$search = trim($search);
$selectedFacilityId = isset($_GET['facility_id']) ? (int) $_GET['facility_id'] : 0;

if (empty($search)) {
    echo json_encode(['results' => []]);
    exit;
}

// Function to check if discount is active based on date conditions (REAL-TIME)
// This function uses current date/time to determine if discounts are active
function isDiscountActive($discountActive, $discountStartDate, $discountEndDate, $discountMonth) {
    if (!$discountActive) {
        return false;
    }
    
    $today = date('Y-m-d');
    $currentMonth = date('Y-m');
    
    // Check specific date range
    if ($discountStartDate && $discountStartDate !== '0000-00-00' && $discountEndDate && $discountEndDate !== '0000-00-00') {
        return ($today >= $discountStartDate && $today <= $discountEndDate);
    }
    
    // Check specific date (start date only, no end date)
    if ($discountStartDate && $discountStartDate !== '0000-00-00' && (!$discountEndDate || $discountEndDate === '0000-00-00')) {
        return ($today === $discountStartDate);
    }
    
    // Check month-specific discount
    if ($discountMonth && $discountMonth !== '') {
        return ($currentMonth === $discountMonth);
    }
    
    // If no date restrictions, discount is active
    return true;
}

// Function to calculate discounted price (for percentage and fixed discounts)
function calculateDiscountedPrice($originalPrice, $discountType, $discountPercent, $discountAmount) {
    if ($discountType === 'percentage') {
        $discount = ($originalPrice * $discountPercent) / 100;
        return $originalPrice - $discount;
    } else if ($discountType === 'fixed') {
        return max(0, $originalPrice - $discountAmount);
    } else {
        // Quantity discounts are calculated based on quantity, not per-item price
        return $originalPrice;
    }
}

// Function to calculate total price with quantity discount (Buy X Get 1 Free)
// Returns the total price for the given quantity
function calculateQuantityDiscountTotal($unitPrice, $quantity, $discountQuantity) {
    if ($discountQuantity <= 1 || $quantity <= 0) {
        return $unitPrice * $quantity;
    }
    
    // Buy X Get 1 Free means: for every (X+1) items, pay for X items
    // Formula: floor(quantity / (discountQuantity + 1)) * discountQuantity * unitPrice + (quantity % (discountQuantity + 1)) * unitPrice
    $groupSize = $discountQuantity + 1; // e.g., if discount_quantity = 4, group size is 5
    $fullGroups = floor($quantity / $groupSize);
    $remainingItems = $quantity % $groupSize;
    
    $totalPrice = ($fullGroups * $discountQuantity * $unitPrice) + ($remainingItems * $unitPrice);
    
    return $totalPrice;
}

try {
    // First, let's see what drugs exist in the database
    $debugQuery = "SELECT COUNT(*) as total_drugs FROM drugs";
    $debugStmt = $pdo->prepare($debugQuery);
    $debugStmt->execute();
    $totalDrugs = $debugStmt->fetch(PDO::FETCH_ASSOC)['total_drugs'];
    
    // Check if active column exists in drugs table
    $columnCheckQuery = "SHOW COLUMNS FROM drugs LIKE 'active'";
    $columnStmt = $pdo->prepare($columnCheckQuery);
    $columnStmt->execute();
    $hasActiveColumn = $columnStmt->rowCount() > 0;
    
    // Check what columns exist in drug_inventory table
    $inventoryColumnsQuery = "SHOW COLUMNS FROM drug_inventory";
    $inventoryStmt = $pdo->prepare($inventoryColumnsQuery);
    $inventoryStmt->execute();
    $inventoryColumns = [];
    while ($col = $inventoryStmt->fetch(PDO::FETCH_ASSOC)) {
        $inventoryColumns[] = $col['Field'];
    }
    
    // Determine the correct column names for quantity
    $quantityColumn = 'quantity';
    if (!in_array('quantity', $inventoryColumns)) {
        if (in_array('qty', $inventoryColumns)) {
            $quantityColumn = 'qty';
        } elseif (in_array('stock', $inventoryColumns)) {
            $quantityColumn = 'stock';
        } elseif (in_array('on_hand', $inventoryColumns)) {
            $quantityColumn = 'on_hand';
        }
    }

    $hasDestroyDateColumn = in_array('destroy_date', $inventoryColumns);
    $activeInventoryWhere = "WHERE $quantityColumn > 0";
    if ($hasDestroyDateColumn) {
        $activeInventoryWhere .= " AND (destroy_date IS NULL OR destroy_date = '0000-00-00' OR destroy_date = '0000-00-00 00:00:00')";
    }
    
    // Search in drug inventory with lot information - show current QOH per unique lot.
    // When a facility is selected, only show inventory explicitly resolved to that
    // facility so POS search stays facility-specific.
    $facilityFilterJoin = '';
    $facilityFilterWhere = '';
    if ($selectedFacilityId > 0) {
        $facilityFilterJoin = " LEFT JOIN list_options lo2
                                  ON lo2.list_id = 'warehouse'
                                 AND lo2.option_id = di2.warehouse_id
                                 AND lo2.activity = 1 ";
        // POS must be strict by facility. Unassigned/legacy inventory rows should not
        // bleed into another facility's checkout search results.
        $facilityFilterWhere = " AND COALESCE(CAST(lo2.option_value AS UNSIGNED), di2.facility_id) = :facility_id ";
    }

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
                    di.lot_number,
                    di.$quantityColumn as qoh,
                    di.expiration,
                    di.manufacturer as lot_manufacturer,
                    d.discount_active,
                    d.discount_type,
                    d.discount_percent,
                    d.discount_amount,
                    d.discount_quantity,
                    d.discount_start_date,
                    d.discount_end_date,
                    d.discount_month,
                    d.discount_description,
                    CASE 
                        WHEN di.lot_number IS NULL OR di.lot_number = '' OR di.lot_number = 'No Lot' OR di.lot_number = 'NA' OR di.lot_number = 'N/A' OR TRIM(di.lot_number) = '' THEN 'Non-Medical'
                        ELSE 'Medical'
                    END as category_name
                  FROM drugs d
                                INNER JOIN (
                SELECT 
                  di2.drug_id,
                  di2.lot_number,
                  di2.$quantityColumn,
                  di2.expiration,
                  di2.manufacturer,
                  di2.inventory_id
                FROM drug_inventory di2
                $facilityFilterJoin
                INNER JOIN (
                  SELECT drug_id, COALESCE(lot_number, '') AS lot_number_key, MAX(inventory_id) as max_inventory_id
                  FROM drug_inventory di2
                  $facilityFilterJoin
                  WHERE 1 = 1
                  $facilityFilterWhere
                  GROUP BY drug_id, COALESCE(lot_number, '')
                ) latest ON di2.drug_id = latest.drug_id 
                          AND COALESCE(di2.lot_number, '') = latest.lot_number_key 
                          AND di2.inventory_id = latest.max_inventory_id
                WHERE di2.$quantityColumn > 0
                  AND (di2.destroy_date IS NULL OR di2.destroy_date = '0000-00-00' OR di2.destroy_date = '0000-00-00 00:00:00')
              ) di ON d.drug_id = di.drug_id
                                WHERE (d.name LIKE :search OR d.form LIKE :search OR d.strength LIKE :search OR d.size LIKE :search OR COALESCE(di.lot_number, '') LIKE :search)";
    
    // Only add active condition if the column exists
    if ($hasActiveColumn) {
        $drugQuery .= " AND (d.active = 1 OR d.active IS NULL)";
    }
    
    $drugQuery .= " ORDER BY d.name ASC, di.expiration ASC LIMIT 50";
    
    $searchTerm = '%' . $search . '%';
    $stmt = $pdo->prepare($drugQuery);
    $stmt->bindParam(':search', $searchTerm, PDO::PARAM_STR);
    if ($selectedFacilityId > 0) {
        $stmt->bindValue(':facility_id', $selectedFacilityId, PDO::PARAM_INT);
    }
    $stmt->execute();
    

    
    $results = [];
    $rowCount = 0;
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rowCount++;
        
        // Debug logging for raw database results
        error_log("POS Inventory Search - Raw DB result: " . $row['name'] . " (Lot: " . $row['lot_number'] . ", QOH: " . $row['qoh'] . ", inventory_id: " . ($row['inventory_id'] ?? 'N/A') . ")");
        
        // Check if discount is active (REAL-TIME - based on current date)
        $isDiscountActive = isDiscountActive(
            $row['discount_active'],
            $row['discount_start_date'],
            $row['discount_end_date'],
            $row['discount_month']
        );
        
        // Calculate original and discounted prices
        $originalPrice = floatval($row['price']);
        $discountedPrice = $originalPrice;
        $discountInfo = null;
        
        if ($isDiscountActive) {
            $discountedPrice = calculateDiscountedPrice(
                $originalPrice,
                $row['discount_type'],
                $row['discount_percent'],
                $row['discount_amount']
            );
            
            $discountInfo = [
                'type' => $row['discount_type'],
                'percent' => floatval($row['discount_percent'] ?? 0),
                'amount' => floatval($row['discount_amount'] ?? 0),
                'quantity' => isset($row['discount_quantity']) ? intval($row['discount_quantity']) : null,
                'description' => $row['discount_description'] ?? '',
                'original_price' => $originalPrice,
                'discounted_price' => $discountedPrice
            ];
        }
        
        // If no lot information, create a default entry
        if (empty($row['lot_number']) || $row['lot_number'] === '' || $row['lot_number'] === null || trim($row['lot_number']) === '' || strlen(trim($row['lot_number'])) === 0) {
            $isMlForm = isLiquidInventoryDrugData($row);
            $results[] = [
                'id' => 'drug_' . $row['drug_id'] . '_no_lot',
                'type' => 'drug',
                'name' => $row['name'],
                'form' => $row['form'],
                'is_ml_form' => $isMlForm,
                'quantity_unit' => $isMlForm ? 'mg' : 'units',
                'quantity_step' => $isMlForm ? 0.01 : 1,
                'strength' => $row['strength'],
                'size' => $row['size'],
                'unit' => $row['unit'],
                'lot_number' => 'No Lot',
                'qoh' => 0,
                'expiration' => null,
                'price' => $discountedPrice, // Use discounted price
                'original_price' => $originalPrice,
                'category_name' => 'Non-Medical', // Products without lot numbers are non-medical
                'manufacturer' => $row['manufacturer'],
                'display_name' => $row['name'],
                'lot_display' => 'No Lot Available',
                'has_discount' => $isDiscountActive,
                'discount_info' => $discountInfo
            ];
        } else {
            // Create separate entries for each lot
            $qoh = round((float) ($row['qoh'] ?? 0), 4);
            $isMlForm = isLiquidInventoryDrugData($row);
            $results[] = [
                'id' => 'drug_' . $row['drug_id'] . '_lot_' . $row['lot_number'],
                'type' => 'drug',
                'name' => $row['name'],
                'form' => $row['form'],
                'is_ml_form' => $isMlForm,
                'quantity_unit' => $isMlForm ? 'mg' : 'units',
                'quantity_step' => $isMlForm ? 0.01 : 1,
                'strength' => $row['strength'],
                'size' => $row['size'],
                'unit' => $row['unit'],
                'lot_number' => $row['lot_number'],
                'qoh' => $qoh,
                'expiration' => $row['expiration'],
                'price' => $discountedPrice, // Use discounted price
                'original_price' => $originalPrice,
                'category_name' => $row['category_name'] ?? 'Medical',
                'manufacturer' => $row['lot_manufacturer'] ?: $row['manufacturer'],
                'display_name' => $row['name'],
                'lot_display' => 'Lot: ' . $row['lot_number'] . ' (QOH: ' . $qoh . ($isMlForm ? ' mg' : '') . ')',
                'has_discount' => $isDiscountActive,
                'discount_info' => $discountInfo
            ];
            
                // Debug logging for inventory results
    error_log("POS Inventory Search - Found item: " . $row['name'] . " (Lot: " . $row['lot_number'] . ", QOH: $qoh)");
    
    // Additional check: if QOH is 0, log a warning
    if ($qoh == 0) {
        error_log("POS Inventory Search - WARNING: Found item with QOH 0: " . $row['name'] . " (Lot: " . $row['lot_number'] . ") - This should not appear in search results!");
    }
        }
    }
    
    // Sort results by name, then by expiration date (earliest first)
    usort($results, function($a, $b) {
        $nameCompare = strcasecmp($a['name'], $b['name']);
        if ($nameCompare !== 0) {
            return $nameCompare;
        }
        
        // If both have expiration dates, compare them
        if (!empty($a['expiration']) && !empty($b['expiration'])) {
            return strcmp($a['expiration'], $b['expiration']);
        }
        
        // If only one has expiration, prioritize the one with expiration
        if (!empty($a['expiration']) && empty($b['expiration'])) {
            return -1; // a comes first
        }
        if (empty($a['expiration']) && !empty($b['expiration'])) {
            return 1; // b comes first
        }
        
        // If neither has expiration, sort by lot number
        return strcasecmp($a['lot_number'], $b['lot_number']);
    });
    
    // Limit total results
    $results = array_slice($results, 0, 30);
    
    echo json_encode(['results' => $results]);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Search failed: ' . $e->getMessage()]);
}
?> 
