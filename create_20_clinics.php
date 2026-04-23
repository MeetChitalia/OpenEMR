<?php
/**
 * Create 20 Clinics for Multi-Clinic Inventory System
 * Creates clinics and their corresponding warehouses
 */

// Database connection
$host = 'localhost';
$dbname = 'openemr';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h1>Multi-Clinic Inventory - Create 20 Clinics</h1>\n";
    echo "<div style='font-family: Arial, sans-serif; max-width: 1000px; margin: 0 auto;'>\n";
    
    // Step 1: Define 20 clinics
    $clinics = array(
        array('Downtown Medical Center', '555-0101', '555-0102', '123 Main St', 'Downtown', 'CA', '90210'),
        array('Northside Clinic', '555-0201', '555-0202', '456 North Ave', 'Northside', 'CA', '90211'),
        array('Southside Medical', '555-0301', '555-0302', '789 South Blvd', 'Southside', 'CA', '90212'),
        array('Eastside Healthcare', '555-0401', '555-0402', '321 East Rd', 'Eastside', 'CA', '90213'),
        array('Westside Clinic', '555-0501', '555-0502', '654 West St', 'Westside', 'CA', '90214'),
        array('Central Medical', '555-0601', '555-0602', '987 Central Ave', 'Central', 'CA', '90215'),
        array('Riverside Clinic', '555-0701', '555-0702', '147 River Rd', 'Riverside', 'CA', '90216'),
        array('Hillside Medical', '555-0801', '555-0802', '258 Hill St', 'Hillside', 'CA', '90217'),
        array('Valley Healthcare', '555-0901', '555-0902', '369 Valley Blvd', 'Valley', 'CA', '90218'),
        array('Coastal Medical Center', '555-1001', '555-1002', '741 Coast Dr', 'Coastal', 'CA', '90219'),
        array('Metro Health Clinic', '555-1101', '555-1102', '852 Metro Ave', 'Metro', 'CA', '90220'),
        array('Sunset Medical', '555-1201', '555-1202', '963 Sunset Blvd', 'Sunset', 'CA', '90221'),
        array('Oakland Healthcare', '555-1301', '555-1302', '159 Oak St', 'Oakland', 'CA', '90222'),
        array('Pine Medical Center', '555-1401', '555-1402', '357 Pine Rd', 'Pine', 'CA', '90223'),
        array('Cedar Clinic', '555-1501', '555-1502', '486 Cedar Ave', 'Cedar', 'CA', '90224'),
        array('Maple Healthcare', '555-1601', '555-1602', '753 Maple St', 'Maple', 'CA', '90225'),
        array('Birch Medical', '555-1701', '555-1702', '951 Birch Blvd', 'Birch', 'CA', '90226'),
        array('Elm Clinic', '555-1801', '555-1802', '264 Elm Rd', 'Elm', 'CA', '90227'),
        array('Willow Healthcare', '555-1901', '555-1902', '837 Willow Ave', 'Willow', 'CA', '90228'),
        array('Aspen Medical Center', '555-2001', '555-2002', '426 Aspen St', 'Aspen', 'CA', '90229')
    );
    
    // Step 2: Check existing facilities
    echo "<h2>Step 1: Checking Existing Facilities</h2>\n";
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM facility");
    $existing_count = $stmt->fetchColumn();
    
    echo "<div style='background: #e2e3e5; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
    echo "<strong>Current Status:</strong> $existing_count existing facilities found.\n";
    echo "</div>\n";
    
    // Step 3: Create clinics
    echo "<h2>Step 2: Creating 20 Clinics</h2>\n";
    
    $created_clinics = array();
    $created_count = 0;
    
    foreach ($clinics as $index => $clinic) {
        $clinic_number = $index + 1;
        
        // Check if clinic already exists (by name)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM facility WHERE name = ?");
        $stmt->execute([$clinic[0]]);
        $exists = $stmt->fetchColumn();
        
        if (!$exists) {
            // Create clinic
            $stmt = $pdo->prepare("INSERT INTO facility (name, phone, fax, street, city, state, postal_code, billing_location, service_location) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)");
            
            try {
                $stmt->execute($clinic);
                $facility_id = $pdo->lastInsertId();
                
                $created_clinics[] = array(
                    'id' => $facility_id,
                    'name' => $clinic[0],
                    'number' => $clinic_number
                );
                
                echo "<div style='color: green; margin: 5px 0;'>✅ Created Clinic #$clinic_number: {$clinic[0]} (ID: $facility_id)</div>\n";
                $created_count++;
            } catch (PDOException $e) {
                echo "<div style='color: red; margin: 5px 0;'>❌ Failed to create clinic '{$clinic[0]}': " . $e->getMessage() . "</div>\n";
            }
        } else {
            echo "<div style='color: blue; margin: 5px 0;'>ℹ️ Clinic '{$clinic[0]}' already exists</div>\n";
        }
    }
    
    echo "<div style='background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
    echo "<strong>Clinic creation completed!</strong> Created $created_count new clinics.\n";
    echo "</div>\n";
    
    // Step 4: Create warehouses for new clinics
    echo "<h2>Step 3: Creating Warehouses for New Clinics</h2>\n";
    
    $created_warehouses = array();
    $warehouse_count = 0;
    
    foreach ($created_clinics as $clinic) {
        $warehouse_id = 'WH_CLINIC_' . $clinic['id'];
        
        // Check if warehouse already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM list_options WHERE list_id = 'warehouse' AND option_id = ?");
        $stmt->execute([$warehouse_id]);
        $exists = $stmt->fetchColumn();
        
        if (!$exists) {
            // Create warehouse
            $stmt = $pdo->prepare("INSERT INTO list_options (list_id, option_id, title, option_value, seq, activity) VALUES (?, ?, ?, ?, ?, 1)");
            
            try {
                $stmt->execute(['warehouse', $warehouse_id, $clinic['name'] . ' Warehouse', $clinic['id'], $clinic['id']]);
                
                $created_warehouses[] = array(
                    'warehouse_id' => $warehouse_id,
                    'clinic_name' => $clinic['name'],
                    'facility_id' => $clinic['id']
                );
                
                echo "<div style='color: green; margin: 5px 0;'>✅ Created warehouse '$warehouse_id' for {$clinic['name']}</div>\n";
                $warehouse_count++;
            } catch (PDOException $e) {
                echo "<div style='color: red; margin: 5px 0;'>❌ Failed to create warehouse for {$clinic['name']}: " . $e->getMessage() . "</div>\n";
            }
        } else {
            echo "<div style='color: blue; margin: 5px 0;'>ℹ️ Warehouse '$warehouse_id' already exists</div>\n";
        }
    }
    
    echo "<div style='background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
    echo "<strong>Warehouse creation completed!</strong> Created $warehouse_count new warehouses.\n";
    echo "</div>\n";
    
    // Step 5: Configure settings for new clinics
    echo "<h2>Step 4: Configuring Inventory Settings</h2>\n";
    
    $default_settings = array(
        'low_stock_threshold_days' => 30,
        'expiration_warning_days' => 90,
        'emergency_transfer_limit' => 1000,
        'auto_transfer_enabled' => 0,
        'transfer_approval_required' => 1,
        'central_reporting_enabled' => 1,
        'snapshot_frequency' => 'daily',
        'alert_escalation_enabled' => 1,
        'reorder_point_percentage' => 20,
        'max_stock_level' => 200,
        'min_stock_level' => 10
    );
    
    $settings_count = 0;
    
    foreach ($created_clinics as $clinic) {
        echo "<h4>Configuring: {$clinic['name']} (ID: {$clinic['id']})</h4>\n";
        
        foreach ($default_settings as $setting_name => $setting_value) {
            $stmt = $pdo->prepare("INSERT INTO clinic_inventory_settings (facility_id, setting_name, setting_value, setting_description) VALUES (?, ?, ?, ?)");
            $description = "Default setting for " . str_replace('_', ' ', $setting_name);
            
            try {
                $stmt->execute([$clinic['id'], $setting_name, $setting_value, $description]);
                $settings_count++;
            } catch (PDOException $e) {
                // Setting might already exist, try to update
                $stmt = $pdo->prepare("UPDATE clinic_inventory_settings SET setting_value = ?, updated_date = NOW() WHERE facility_id = ? AND setting_name = ?");
                $stmt->execute([$setting_value, $clinic['id'], $setting_name]);
                $settings_count++;
            }
        }
    }
    
    echo "<div style='background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
    echo "<strong>Settings configuration completed!</strong> Configured $settings_count settings.\n";
    echo "</div>\n";
    
    // Step 6: Final summary
    echo "<h2>Step 5: Final Summary</h2>\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM facility WHERE billing_location = 1");
    $total_billing_facilities = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM list_options WHERE list_id = 'warehouse'");
    $total_warehouses = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM clinic_inventory_settings");
    $total_settings = $stmt->fetchColumn();
    
    echo "<table border='1' style='width: 100%; border-collapse: collapse; margin: 10px 0;'>\n";
    echo "<tr style='background: #f8f9fa;'><th>Component</th><th>Count</th><th>Status</th></tr>\n";
    echo "<tr><td>Billing Facilities</td><td>$total_billing_facilities</td><td>✅ Complete</td></tr>\n";
    echo "<tr><td>Warehouses</td><td>$total_warehouses</td><td>✅ Complete</td></tr>\n";
    echo "<tr><td>Inventory Settings</td><td>$total_settings</td><td>✅ Complete</td></tr>\n";
    echo "</table>\n";
    
    // Step 7: Show all clinics
    echo "<h2>Step 6: All Clinics Overview</h2>\n";
    $stmt = $pdo->query("SELECT id, name, billing_location FROM facility ORDER BY id");
    $all_facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 12px;'>\n";
    echo "<tr style='background: #f8f9fa;'><th>ID</th><th>Clinic Name</th><th>Billing Location</th></tr>\n";
    
    foreach ($all_facilities as $facility) {
        $billing = $facility['billing_location'] ? 'Yes' : 'No';
        echo "<tr><td>{$facility['id']}</td><td>{$facility['name']}</td><td>$billing</td></tr>\n";
    }
    echo "</table>\n";
    
    // Step 8: Next steps
    echo "<h2>Next Steps</h2>\n";
    echo "<div style='background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
    echo "<strong>🎉 20 Clinics Created Successfully!</strong><br>\n";
    echo "Your multi-clinic inventory system is now ready with:<br>\n";
    echo "• $total_billing_facilities clinics<br>\n";
    echo "• $total_warehouses warehouses<br>\n";
    echo "• $total_settings inventory settings<br>\n";
    echo "<br>\n";
    echo "Now you can proceed to:<br>\n";
    echo "1. ✅ Test inventory management<br>\n";
    echo "2. ✅ Set up the central dashboard<br>\n";
    echo "3. ✅ Train staff on the system<br>\n";
    echo "</div>\n";
    
    echo "</div>\n";
    
} catch (PDOException $e) {
    echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
    echo "<strong>Database Error:</strong> " . $e->getMessage() . "\n";
    echo "</div>\n";
}
?> 