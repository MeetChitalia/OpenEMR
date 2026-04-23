<?php
/**
 * Verify Clinic-Warehouse Mapping
 * Ensure each clinic has a proper warehouse mapping
 */

// Database connection
$host = 'localhost';
$dbname = 'openemr';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h1>Multi-Clinic Inventory - Mapping Verification</h1>\n";
    echo "<div style='font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto;'>\n";
    
    // Step 1: Check all facilities
    echo "<h2>Step 1: All Facilities</h2>\n";
    $stmt = $pdo->query("SELECT id, name, billing_location, service_location FROM facility ORDER BY id");
    $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='width: 100%; border-collapse: collapse; margin: 10px 0;'>\n";
    echo "<tr style='background: #f8f9fa;'><th>ID</th><th>Name</th><th>Billing Location</th><th>Service Location</th></tr>\n";
    
    foreach ($facilities as $facility) {
        $billing = $facility['billing_location'] ? 'Yes' : 'No';
        $service = $facility['service_location'] ? 'Yes' : 'No';
        echo "<tr><td>{$facility['id']}</td><td>{$facility['name']}</td><td>$billing</td><td>$service</td></tr>\n";
    }
    echo "</table>\n";
    
    // Step 2: Check all warehouses
    echo "<h2>Step 2: All Warehouses</h2>\n";
    $stmt = $pdo->query("SELECT option_id, title, option_value, seq, activity FROM list_options WHERE list_id = 'warehouse' ORDER BY seq");
    $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='width: 100%; border-collapse: collapse; margin: 10px 0;'>\n";
    echo "<tr style='background: #f8f9fa;'><th>Warehouse ID</th><th>Title</th><th>Facility ID</th><th>Sequence</th><th>Active</th></tr>\n";
    
    foreach ($warehouses as $warehouse) {
        $active = $warehouse['activity'] ? 'Yes' : 'No';
        echo "<tr><td>{$warehouse['option_id']}</td><td>{$warehouse['title']}</td><td>{$warehouse['option_value']}</td><td>{$warehouse['seq']}</td><td>$active</td></tr>\n";
    }
    echo "</table>\n";
    
    // Step 3: Verify mapping
    echo "<h2>Step 3: Clinic-Warehouse Mapping Verification</h2>\n";
    
    $mapping_issues = array();
    $mapping_success = array();
    
    foreach ($facilities as $facility) {
        // Find warehouse for this facility
        $stmt = $pdo->prepare("SELECT option_id, title FROM list_options WHERE list_id = 'warehouse' AND option_value = ?");
        $stmt->execute([$facility['id']]);
        $warehouse = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($warehouse) {
            $mapping_success[] = array(
                'facility_id' => $facility['id'],
                'facility_name' => $facility['name'],
                'warehouse_id' => $warehouse['option_id'],
                'warehouse_title' => $warehouse['title']
            );
        } else {
            $mapping_issues[] = array(
                'facility_id' => $facility['id'],
                'facility_name' => $facility['name'],
                'issue' => 'No warehouse found'
            );
        }
    }
    
    // Display successful mappings
    if (!empty($mapping_success)) {
        echo "<h3>✅ Successful Mappings</h3>\n";
        echo "<table border='1' style='width: 100%; border-collapse: collapse; margin: 10px 0;'>\n";
        echo "<tr style='background: #d4edda;'><th>Clinic ID</th><th>Clinic Name</th><th>Warehouse ID</th><th>Warehouse Name</th></tr>\n";
        
        foreach ($mapping_success as $mapping) {
            echo "<tr><td>{$mapping['facility_id']}</td><td>{$mapping['facility_name']}</td><td>{$mapping['warehouse_id']}</td><td>{$mapping['warehouse_title']}</td></tr>\n";
        }
        echo "</table>\n";
    }
    
    // Display mapping issues
    if (!empty($mapping_issues)) {
        echo "<h3>❌ Mapping Issues</h3>\n";
        echo "<table border='1' style='width: 100%; border-collapse: collapse; margin: 10px 0;'>\n";
        echo "<tr style='background: #f8d7da;'><th>Clinic ID</th><th>Clinic Name</th><th>Issue</th></tr>\n";
        
        foreach ($mapping_issues as $issue) {
            echo "<tr><td>{$issue['facility_id']}</td><td>{$issue['facility_name']}</td><td>{$issue['issue']}</td></tr>\n";
        }
        echo "</table>\n";
    }
    
    // Step 4: Check inventory settings
    echo "<h2>Step 4: Inventory Settings Verification</h2>\n";
    $stmt = $pdo->query("SELECT facility_id, COUNT(*) as setting_count FROM clinic_inventory_settings GROUP BY facility_id ORDER BY facility_id");
    $settings_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='width: 100%; border-collapse: collapse; margin: 10px 0;'>\n";
    echo "<tr style='background: #f8f9fa;'><th>Clinic ID</th><th>Settings Count</th><th>Status</th></tr>\n";
    
    foreach ($settings_summary as $setting) {
        $status = $setting['setting_count'] >= 10 ? '✅ Complete' : '⚠️ Incomplete';
        echo "<tr><td>{$setting['facility_id']}</td><td>{$setting['setting_count']}</td><td>$status</td></tr>\n";
    }
    echo "</table>\n";
    
    // Step 5: Summary and recommendations
    echo "<h2>Step 5: Verification Summary</h2>\n";
    
    $total_facilities = count($facilities);
    $successful_mappings = count($mapping_success);
    $mapping_issues_count = count($mapping_issues);
    
    echo "<div style='background: #e2e3e5; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
    echo "<strong>Summary:</strong><br>\n";
    echo "• Total Facilities: $total_facilities<br>\n";
    echo "• Successful Mappings: $successful_mappings<br>\n";
    echo "• Mapping Issues: $mapping_issues_count<br>\n";
    echo "</div>\n";
    
    if ($mapping_issues_count == 0) {
        echo "<div style='background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
        echo "<strong>✅ All clinic-warehouse mappings are correct!</strong><br>\n";
        echo "Your system is ready for inventory management testing.\n";
        echo "</div>\n";
    } else {
        echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
        echo "<strong>⚠️ Some mapping issues found!</strong><br>\n";
        echo "Please resolve the mapping issues before proceeding.\n";
        echo "</div>\n";
    }
    
    // Step 6: Next steps
    echo "<h2>Next Steps</h2>\n";
    echo "<div style='background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
    echo "<strong>Mapping verification completed!</strong><br>\n";
    echo "Now you can proceed to:<br>\n";
    echo "1. ✅ Verify clinic-warehouse mapping<br>\n";
    echo "2. 🔄 Test inventory management<br>\n";
    echo "3. 🔄 Set up the central dashboard<br>\n";
    echo "</div>\n";
    
    echo "</div>\n";
    
} catch (PDOException $e) {
    echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
    echo "<strong>Database Error:</strong> " . $e->getMessage() . "\n";
    echo "</div>\n";
}
?> 