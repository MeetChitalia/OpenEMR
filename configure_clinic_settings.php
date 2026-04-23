<?php
/**
 * Configure Clinic Inventory Settings
 * Set up inventory thresholds, alert preferences, and other settings for each clinic
 */

// Database connection
$host = 'localhost';
$dbname = 'openemr';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h1>Multi-Clinic Inventory - Settings Configuration</h1>\n";
    echo "<div style='font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto;'>\n";
    
    // Step 1: Check existing facilities
    echo "<h2>Step 1: Checking Clinics for Configuration</h2>\n";
    $stmt = $pdo->query("SELECT id, name FROM facility WHERE billing_location = 1 ORDER BY id");
    $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($facilities)) {
        echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
        echo "<strong>No billing facilities found!</strong> Please ensure clinics are set as billing locations.\n";
        echo "</div>\n";
        exit;
    }
    
    echo "<table border='1' style='width: 100%; border-collapse: collapse; margin: 10px 0;'>\n";
    echo "<tr style='background: #f8f9fa;'><th>ID</th><th>Clinic Name</th></tr>\n";
    
    foreach ($facilities as $facility) {
        echo "<tr><td>{$facility['id']}</td><td>{$facility['name']}</td></tr>\n";
    }
    echo "</table>\n";
    
    // Step 2: Define default settings
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
    
    echo "<h2>Step 2: Default Settings to Apply</h2>\n";
    echo "<table border='1' style='width: 100%; border-collapse: collapse; margin: 10px 0;'>\n";
    echo "<tr style='background: #f8f9fa;'><th>Setting</th><th>Value</th><th>Description</th></tr>\n";
    
    $descriptions = array(
        'low_stock_threshold_days' => 'Days to consider for low stock calculation',
        'expiration_warning_days' => 'Days before expiration to show warning',
        'emergency_transfer_limit' => 'Maximum amount for emergency transfers',
        'auto_transfer_enabled' => 'Enable automatic transfers between clinics',
        'transfer_approval_required' => 'Require approval for inter-clinic transfers',
        'central_reporting_enabled' => 'Enable reporting to central inventory',
        'snapshot_frequency' => 'Frequency of inventory snapshots',
        'alert_escalation_enabled' => 'Enable alert escalation to central management',
        'reorder_point_percentage' => 'Percentage of max stock to trigger reorder',
        'max_stock_level' => 'Maximum stock level before overstock alert',
        'min_stock_level' => 'Minimum stock level before low stock alert'
    );
    
    foreach ($default_settings as $setting => $value) {
        $desc = $descriptions[$setting] ?? 'No description available';
        echo "<tr><td>$setting</td><td>$value</td><td>$desc</td></tr>\n";
    }
    echo "</table>\n";
    
    // Step 3: Configure settings for each clinic
    echo "<h2>Step 3: Configuring Settings for Each Clinic</h2>\n";
    
    $configured_count = 0;
    foreach ($facilities as $facility) {
        echo "<h3>Configuring: {$facility['name']} (ID: {$facility['id']})</h3>\n";
        
        foreach ($default_settings as $setting_name => $setting_value) {
            // Check if setting already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM clinic_inventory_settings WHERE facility_id = ? AND setting_name = ?");
            $stmt->execute([$facility['id'], $setting_name]);
            $exists = $stmt->fetchColumn();
            
            if (!$exists) {
                // Insert new setting
                $stmt = $pdo->prepare("INSERT INTO clinic_inventory_settings (facility_id, setting_name, setting_value, setting_description) VALUES (?, ?, ?, ?)");
                $description = $descriptions[$setting_name] ?? 'No description available';
                
                try {
                    $stmt->execute([$facility['id'], $setting_name, $setting_value, $description]);
                    echo "<div style='color: green; margin: 5px 0;'>✅ Set '$setting_name' = '$setting_value'</div>\n";
                    $configured_count++;
                } catch (PDOException $e) {
                    echo "<div style='color: red; margin: 5px 0;'>❌ Failed to set '$setting_name': " . $e->getMessage() . "</div>\n";
                }
            } else {
                // Update existing setting
                $stmt = $pdo->prepare("UPDATE clinic_inventory_settings SET setting_value = ?, updated_date = NOW() WHERE facility_id = ? AND setting_name = ?");
                
                try {
                    $stmt->execute([$setting_value, $facility['id'], $setting_name]);
                    echo "<div style='color: blue; margin: 5px 0;'>🔄 Updated '$setting_name' = '$setting_value'</div>\n";
                    $configured_count++;
                } catch (PDOException $e) {
                    echo "<div style='color: red; margin: 5px 0;'>❌ Failed to update '$setting_name': " . $e->getMessage() . "</div>\n";
                }
            }
        }
        echo "<hr style='margin: 20px 0;'>\n";
    }
    
    echo "<div style='background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
    echo "<strong>Settings configuration completed!</strong> Configured $configured_count settings.\n";
    echo "</div>\n";
    
    // Step 4: Show final settings
    echo "<h2>Step 4: Final Settings Summary</h2>\n";
    $stmt = $pdo->query("SELECT facility_id, setting_name, setting_value FROM clinic_inventory_settings ORDER BY facility_id, setting_name");
    $final_settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($final_settings)) {
        echo "<table border='1' style='width: 100%; border-collapse: collapse; margin: 10px 0;'>\n";
        echo "<tr style='background: #f8f9fa;'><th>Clinic ID</th><th>Setting</th><th>Value</th></tr>\n";
        
        foreach ($final_settings as $setting) {
            echo "<tr><td>{$setting['facility_id']}</td><td>{$setting['setting_name']}</td><td>{$setting['setting_value']}</td></tr>\n";
        }
        echo "</table>\n";
    } else {
        echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
        echo "<strong>No settings found!</strong> Configuration may have failed.\n";
        echo "</div>\n";
    }
    
    // Step 5: Next steps
    echo "<h2>Next Steps</h2>\n";
    echo "<div style='background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
    echo "<strong>Settings configured successfully!</strong><br>\n";
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