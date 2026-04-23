<?php
header('Content-Type: application/json');

function ensureDefectiveMedicineTables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS defective_medicines (
            id INT(11) NOT NULL AUTO_INCREMENT,
            drug_id INT(11) NOT NULL,
            lot_number VARCHAR(100) DEFAULT NULL,
            inventory_id INT(11) DEFAULT NULL,
            pid INT(11) DEFAULT NULL,
            quantity DECIMAL(10,2) NOT NULL,
            reason TEXT NOT NULL,
            defect_type ENUM('faulty', 'defective', 'expired', 'contaminated', 'other') NOT NULL DEFAULT 'defective',
            reported_by VARCHAR(100) NOT NULL,
            approved_by VARCHAR(100) DEFAULT NULL,
            approval_date DATETIME DEFAULT NULL,
            status ENUM('pending', 'approved', 'rejected', 'processed') NOT NULL DEFAULT 'pending',
            inventory_deducted TINYINT(1) NOT NULL DEFAULT 0,
            replacement_processed TINYINT(1) NOT NULL DEFAULT 0,
            notes TEXT DEFAULT NULL,
            created_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY drug_id (drug_id),
            KEY lot_number (lot_number),
            KEY inventory_id (inventory_id),
            KEY pid (pid),
            KEY status (status),
            KEY reported_by (reported_by),
            KEY approved_by (approved_by),
            KEY idx_drug_lot (drug_id, lot_number),
            KEY idx_status_date (status, created_date),
            KEY idx_reported_date (reported_by, created_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS defective_medicine_replacements (
            id INT(11) NOT NULL AUTO_INCREMENT,
            defective_id INT(11) NOT NULL,
            replacement_drug_id INT(11) DEFAULT NULL,
            replacement_lot_number VARCHAR(100) DEFAULT NULL,
            replacement_inventory_id INT(11) DEFAULT NULL,
            quantity DECIMAL(10,2) NOT NULL,
            fee DECIMAL(10,2) DEFAULT 0.00,
            processed_by VARCHAR(100) NOT NULL,
            processed_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            notes TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY defective_id (defective_id),
            KEY replacement_drug_id (replacement_drug_id),
            KEY replacement_inventory_id (replacement_inventory_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function defectivePosFacilityId(PDO $pdo, int $pid): ?int
{
    $selectedFacilityId = (int) ($_SESSION['facilityId'] ?? 0);
    if ($selectedFacilityId > 0) {
        return $selectedFacilityId;
    }

    if ($pid > 0) {
        $stmt = $pdo->prepare("SELECT facility_id FROM patient_data WHERE pid = ? LIMIT 1");
        $stmt->execute([$pid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $patientFacilityId = (int) ($row['facility_id'] ?? 0);
        if ($patientFacilityId > 0) {
            return $patientFacilityId;
        }
    }

    $legacyFacilityId = (int) ($_SESSION['facility_id'] ?? 0);
    return $legacyFacilityId > 0 ? $legacyFacilityId : null;
}

// Simple defective medicines handler that works with inventory
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if ($input && isset($input['action'])) {
        switch ($input['action']) {
            case 'report_defective':
                // Get the input data
                $drug_id = intval($input['drug_id'] ?? 0);
                $lot_number = $input['lot_number'] ?? '';
                $quantity = floatval($input['quantity'] ?? 0);
                $reason = trim($input['reason'] ?? '');
                $defect_type = $input['defect_type'] ?? 'defective';
                $pid = intval($input['pid'] ?? 0);
                
                if (!$drug_id || $quantity <= 0 || empty($reason)) {
                    echo json_encode(['success' => false, 'error' => 'Invalid input parameters']);
                    exit;
                }
                
                try {
                    // Use OpenEMR's database configuration
                    require_once(__DIR__ . '/../../sites/default/sqlconf.php');
                    
                    $pdo = new PDO(
                        "mysql:host=" . $host . ";port=" . $port . ";dbname=" . $dbase . ";charset=utf8mb4",
                        $login,
                        $pass,
                        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
                    );

                    ensureDefectiveMedicineTables($pdo);
                    
                    // First, check if we have enough inventory
                    $inventory = null;
                    
                    if (!empty($lot_number)) {
                        // Try to find inventory with specific lot number first
                        $check_sql = "SELECT inventory_id, on_hand FROM drug_inventory WHERE drug_id = ? AND lot_number = ? AND on_hand >= ? LIMIT 1";
                        $check_stmt = $pdo->prepare($check_sql);
                        $check_stmt->execute([$drug_id, $lot_number, $quantity]);
                        $inventory = $check_stmt->fetch(PDO::FETCH_ASSOC);
                    }
                    
                    if (!$inventory) {
                        // If no specific lot or not enough quantity, find any available inventory
                        $check_sql = "SELECT inventory_id, on_hand, lot_number FROM drug_inventory WHERE drug_id = ? AND on_hand >= ? ORDER BY on_hand DESC LIMIT 1";
                        $check_stmt = $pdo->prepare($check_sql);
                        $check_stmt->execute([$drug_id, $quantity]);
                        $inventory = $check_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($inventory && empty($lot_number)) {
                            $lot_number = $inventory['lot_number']; // Use the found lot number
                        }
                    }
                    
                    if (!$inventory) {
                        echo json_encode(['success' => false, 'error' => 'Insufficient inventory found for this medicine']);
                        exit;
                    }
                    
                    // Insert defective medicine record
                    $insert_sql = "INSERT INTO defective_medicines (drug_id, lot_number, inventory_id, pid, quantity, reason, defect_type, reported_by, status) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, 'test_user', 'approved')";
                    $insert_stmt = $pdo->prepare($insert_sql);
                    $insert_stmt->execute([$drug_id, $lot_number, $inventory['inventory_id'], $pid, $quantity, $reason, $defect_type]);
                    
                    $defective_id = $pdo->lastInsertId();
                    
                    // Deduct from inventory
                    $deduct_sql = "UPDATE drug_inventory SET on_hand = GREATEST(0, on_hand - ?) WHERE inventory_id = ?";
                    $deduct_stmt = $pdo->prepare($deduct_sql);
                    $deduct_stmt->execute([$quantity, $inventory['inventory_id']]);
                    
                    // Mark as deducted
                    $update_sql = "UPDATE defective_medicines SET inventory_deducted = 1 WHERE id = ?";
                    $update_stmt = $pdo->prepare($update_sql);
                    $update_stmt->execute([$defective_id]);
                    
                     // Update dispense tracking - ADD the defective quantity back to dispense remaining of that product
                     $dispense_tracking_updated = false;
                     
                     if (isset($input['transaction_id']) && $input['transaction_id'] > 0) {
                         // Update the pos_remaining_dispense table for this specific product/patient
                         // This is where the actual dispense remaining quantities are stored
                         $remaining_dispense_sql = "SELECT id, remaining_quantity FROM pos_remaining_dispense WHERE pid = ? AND drug_id = ? AND remaining_quantity > 0 ORDER BY last_updated DESC LIMIT 1";
                         $remaining_dispense_stmt = $pdo->prepare($remaining_dispense_sql);
                         $remaining_dispense_stmt->execute([$pid, $drug_id]);
                         $remaining_dispense_result = $remaining_dispense_stmt->fetch(PDO::FETCH_ASSOC);
                         
                         if ($remaining_dispense_result) {
                             // Update existing remaining dispense record
                             $current_remaining = intval($remaining_dispense_result['remaining_quantity']);
                             $new_remaining = $current_remaining + $quantity;
                             
                             $update_remaining_sql = "UPDATE pos_remaining_dispense SET remaining_quantity = ?, last_updated = NOW() WHERE id = ?";
                             $update_remaining_stmt = $pdo->prepare($update_remaining_sql);
                             $update_remaining_stmt->execute([$new_remaining, $remaining_dispense_result['id']]);
                             
                             $dispense_tracking_updated = true;
                         } else {
                             // Create new remaining dispense record if none exists
                             $insert_remaining_sql = "INSERT INTO pos_remaining_dispense (pid, drug_id, lot_number, total_quantity, dispensed_quantity, administered_quantity, remaining_quantity, receipt_number, created_date, last_updated) VALUES (?, ?, ?, ?, 0, 0, ?, 'DEFECTIVE-COMPENSATION', NOW(), NOW())";
                             $insert_remaining_stmt = $pdo->prepare($insert_remaining_sql);
                             $insert_remaining_stmt->execute([$pid, $drug_id, $lot_number, $quantity, $quantity]);
                             
                             $dispense_tracking_updated = true;
                         }
                     } else {
                         // If no specific transaction_id, update the main dispense tracking (for inventory-level reports)
                         $tracking_sql = "SELECT id, items FROM pos_transactions WHERE pid = ? ORDER BY created_date DESC";
                         $tracking_stmt = $pdo->prepare($tracking_sql);
                         $tracking_stmt->execute([$pid]);
                         $tracking_results = $tracking_stmt->fetchAll(PDO::FETCH_ASSOC);
                         
                         foreach ($tracking_results as $tracking) {
                             $items = json_decode($tracking['items'], true);
                             $updated = false;
                             
                             if (is_array($items)) {
                                 foreach ($items as &$item) {
                                     if (isset($item['drug_id']) && $item['drug_id'] == $drug_id) {
                                         // ADD the defective quantity back to remaining quantity (compensation)
                                         $current_remaining = intval($item['remaining_quantity'] ?? 0);
                                         $new_remaining = $current_remaining + $quantity;
                                         $item['remaining_quantity'] = $new_remaining;
                                         $updated = true;
                                     }
                                 }
                                 
                                 if ($updated) {
                                     // Update the transaction with modified items
                                     $update_tracking_sql = "UPDATE pos_transactions SET items = ? WHERE id = ?";
                                     $update_tracking_stmt = $pdo->prepare($update_tracking_sql);
                                     $update_tracking_stmt->execute([json_encode($items), $tracking['id']]);
                                     $dispense_tracking_updated = true;
                                 }
                             }
                         }
                     }
                     
                     // Create a new transaction entry for the defective medicine report
                     // This will show up in the dispense tracking subtable as a compensation entry
                     $defective_transaction_items = json_encode([
                         [
                             'drug_id' => $drug_id,
                             'name' => 'Defective Medicine Compensation',
                             'display_name' => 'Defective Medicine Compensation',
                             'lot_number' => $lot_number,
                             'quantity' => $quantity,
                             'dispense_quantity' => 0,
                             'administer_quantity' => 0,
                             'remaining_quantity' => $quantity,
                             'is_defective_report' => true,
                             'is_compensation' => true,
                             'defect_reason' => $reason,
                             'defect_type' => $defect_type,
                             'defective_id' => $defective_id,
                             'original_transaction_id' => $input['transaction_id'] ?? 0,
                             'is_remaining_dispense' => false,
                             'is_different_lot_dispense' => false,
                             'has_remaining_dispense' => false
                         ]
                     ]);
                     
                     // Insert new transaction for defective medicine report
                     $facilityColumnStmt = $pdo->query("SHOW COLUMNS FROM pos_transactions LIKE 'facility_id'");
                     $hasFacilityId = (bool) ($facilityColumnStmt && $facilityColumnStmt->fetch(PDO::FETCH_ASSOC));
                     $insertColumns = ['pid', 'receipt_number', 'transaction_type', 'amount', 'items', 'created_date', 'user_id'];
                     $insertValues = [$pid, 'DEFECTIVE-' . $defective_id, 'defective_report', 0.00, $defective_transaction_items, date('Y-m-d H:i:s'), 'system'];
                     if ($hasFacilityId) {
                         $insertColumns[] = 'facility_id';
                         $insertValues[] = defectivePosFacilityId($pdo, $pid);
                     }
                     $insert_defective_transaction_sql = "INSERT INTO pos_transactions (" . implode(', ', $insertColumns) . ")
                                                        VALUES (" . implode(', ', array_fill(0, count($insertColumns), '?')) . ")";
                     $insert_defective_transaction_stmt = $pdo->prepare($insert_defective_transaction_sql);
                     $insert_defective_transaction_stmt->execute($insertValues);
                     
                     $defective_transaction_id = $pdo->lastInsertId();
                     
                     // Add transaction ID to notes if available (for better tracking)
                     if (isset($input['transaction_id']) && $input['transaction_id'] > 0) {
                         $update_notes_sql = "UPDATE defective_medicines SET notes = CONCAT(notes, ' [Transaction ID: ', ?, ']') WHERE id = ?";
                         $update_notes_stmt = $pdo->prepare($update_notes_sql);
                         $update_notes_stmt->execute([$input['transaction_id'], $defective_id]);
                     }
                    
                                         $update_message = isset($input['transaction_id']) && $input['transaction_id'] > 0 
                         ? 'Dispense remaining updated with compensation' 
                         : 'Main dispense tracking updated with compensation';
                     
                     echo json_encode([
                         'success' => true, 
                         'message' => 'Defective medicine reported, inventory deducted, and ' . $update_message,
                         'defective_id' => $defective_id,
                         'quantity_deducted' => $quantity,
                         'remaining_qoh' => $inventory['on_hand'] - $quantity,
                         'dispense_tracking_updated' => $dispense_tracking_updated,
                         'defective_transaction_id' => $defective_transaction_id,
                         'compensation_added' => $quantity,
                         'dispense_remaining_updated' => isset($input['transaction_id']) ? true : false
                     ]);
                    
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
                }
                break;
                
            default:
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
    }
} else {
    echo json_encode(['success' => true, 'message' => 'Handler accessible']);
}
?>
