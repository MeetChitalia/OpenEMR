<?php
/**
 * Create Warehouses for Clinics
 * Simple script to create warehouses for existing clinics
 */

// Database connection
$host = 'localhost';
$dbname = 'openemr';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h1>Multi-Clinic Inventory - Warehouse Setup</h1>\n";
    echo "<div style='font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto;'>\n";
    
    // Step 1: Check existing facilities
    echo "<h2>Step 1: Checking Existing Clinics</h2>\n";
    $stmt = $pdo->query("SELECT id, name, billing_location FROM facility ORDER BY id");
    $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($facilities)) {
        echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
        echo "<strong>No facilities found!</strong> Please create clinics first in OpenEMR Admin.\n";
        echo "</div>\n";
        exit;
    }
    
    echo "<table border='1' style='width: 100%; border-collapse: collapse; margin: 10px 0;'>\n";
    echo "<tr style='background: #f8f9fa;'><th>ID</th><th>Name</th><th>Billing Location</th></tr>\n";
    
    foreach ($facilities as $facility) {
        $billing_status = $facility['billing_location'] ? 'Yes' : 'No';
        echo "<tr><td>{$facility['id']}</td><td>{$facility['name']}</td><td>$billing_status</td></tr>\n";
    }
    echo "</table>\n";
    
    // Step 2: Check existing warehouses
    echo "<h2>Step 2: Checking Existing Warehouses</h2>\n";
    $stmt = $pdo->query("SELECT option_id, title, option_value FROM list_options WHERE list_id = 'warehouse' ORDER BY seq");
    $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='width: 100%; border-collapse: collapse; margin: 10px 0;'>\n";
    echo "<tr style='background: #f8f9fa;'><th>Warehouse ID</th><th>Title</th><th>Facility ID</th></tr>\n";
    
    foreach ($warehouses as $warehouse) {
        echo "<tr><td>{$warehouse['option_id']}</td><td>{$warehouse['title']}</td><td>{$warehouse['option_value']}</td></tr>\n";
    }
    echo "</table>\n";
    
    // Step 3: Create warehouses for clinics that don't have them
    echo "<h2>Step 3: Creating Missing Warehouses</h2>\n";
    
    $created_count = 0;
    foreach ($facilities as $facility) {
        // Check if warehouse already exists for this facility
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM list_options WHERE list_id = 'warehouse' AND option_value = ?");
        $stmt->execute([$facility['id']]);
        $exists = $stmt->fetchColumn();
        
        if (!$exists) {
            // Create warehouse for this facility
            $warehouse_id = 'WH_CLINIC_' . $facility['id'];
            $stmt = $pdo->prepare("INSERT INTO list_options (list_id, option_id, title, option_value, seq, activity) VALUES (?, ?, ?, ?, ?, 1)");
            
            try {
                $stmt->execute(['warehouse', $warehouse_id, $facility['name'] . ' Warehouse', $facility['id'], $facility['id']]);
                echo "<div style='color: green; margin: 5px 0;'>✅ Created warehouse '$warehouse_id' for clinic '{$facility['name']}'</div>\n";
                $created_count++;
            } catch (PDOException $e) {
                echo "<div style='color: red; margin: 5px 0;'>❌ Failed to create warehouse for clinic '{$facility['name']}': " . $e->getMessage() . "</div>\n";
            }
        } else {
            echo "<div style='color: blue; margin: 5px 0;'>ℹ️ Warehouse already exists for clinic '{$facility['name']}'</div>\n";
        }
    }
    
    echo "<div style='background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
    echo "<strong>Warehouse creation completed!</strong> Created $created_count new warehouses.\n";
    echo "</div>\n";
    
    // Step 4: Show final warehouse list
    echo "<h2>Step 4: Final Warehouse List</h2>\n";
    $stmt = $pdo->query("SELECT option_id, title, option_value FROM list_options WHERE list_id = 'warehouse' ORDER BY seq");
    $final_warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='width: 100%; border-collapse: collapse; margin: 10px 0;'>\n";
    echo "<tr style='background: #f8f9fa;'><th>Warehouse ID</th><th>Title</th><th>Facility ID</th></tr>\n";
    
    foreach ($final_warehouses as $warehouse) {
        echo "<tr><td>{$warehouse['option_id']}</td><td>{$warehouse['title']}</td><td>{$warehouse['option_value']}</td></tr>\n";
    }
    echo "</table>\n";
    
    // Step 5: Next steps
    echo "<h2>Next Steps</h2>\n";
    echo "<div style='background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
    echo "<strong>Warehouses created successfully!</strong><br>\n";
    echo "Now you can proceed to:<br>\n";
    echo "1. Configure clinic inventory settings<br>\n";
    echo "2. Test inventory management<br>\n";
    echo "3. Set up the central dashboard<br>\n";
    echo "</div>\n";
    
    echo "</div>\n";
    
} catch (PDOException $e) {
    echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
    echo "<strong>Database Error:</strong> " . $e->getMessage() . "\n";
    echo "</div>\n";
}
?> 