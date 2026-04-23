<?php
/**
 * Multi-Clinic Inventory System - Setup Script
 * 
 * This script helps set up the multi-clinic inventory system
 * Run this script after deploying the system to configure clinics and settings
 */

// Include OpenEMR configuration
require_once("globals.php");
require_once("$srcdir/acl.inc");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/formatting.inc.php");

// Include our multi-clinic inventory class
require_once("$srcdir/classes/MultiClinicInventory.class.php");

use OpenEMR\Common\Csrf\CsrfUtils;

class MultiClinicInventorySetup
{
    private $multi_inventory;
    private $setup_results = array();

    public function __construct()
    {
        $this->multi_inventory = new MultiClinicInventory();
    }

    /**
     * Run the setup process
     */
    public function runSetup()
    {
        echo "<h1>Multi-Clinic Inventory System - Setup Wizard</h1>\n";
        echo "<div style='font-family: Arial, sans-serif; max-width: 1000px; margin: 0 auto;'>\n";
        
        // Check if user has proper permissions
        if (!AclMain::aclCheckCore('admin', 'super')) {
            echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 20px 0;'>\n";
            echo "<strong>Access Denied:</strong> You need super admin privileges to run this setup.\n";
            echo "</div>\n";
            return;
        }

        // Handle form submissions
        if ($_POST['setup_action']) {
            $this->handleSetupAction($_POST['setup_action']);
        }

        // Display setup options
        $this->displaySetupOptions();
        
        echo "</div>\n";
    }

    /**
     * Handle setup actions
     */
    private function handleSetupAction($action)
    {
        switch ($action) {
            case 'create_clinics':
                $this->createDefaultClinics();
                break;
            case 'create_warehouses':
                $this->createDefaultWarehouses();
                break;
            case 'configure_settings':
                $this->configureDefaultSettings();
                break;
            case 'test_system':
                $this->testSystem();
                break;
            case 'generate_sample_data':
                $this->generateSampleData();
                break;
        }
    }

    /**
     * Display setup options
     */
    private function displaySetupOptions()
    {
        echo "<div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>\n";
        echo "<h2>Setup Options</h2>\n";
        echo "<p>Choose an option to configure your multi-clinic inventory system:</p>\n";
        echo "</div>\n";

        echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin: 20px 0;'>\n";

        // Create Clinics
        echo "<div style='border: 1px solid #ddd; border-radius: 8px; padding: 20px; background: white;'>\n";
        echo "<h3>🏥 Create Default Clinics</h3>\n";
        echo "<p>Create 10 sample clinics for your multi-clinic setup.</p>\n";
        echo "<form method='post' style='margin-top: 15px;'>\n";
        echo "<input type='hidden' name='csrf_token_form' value='" . attr(CsrfUtils::collectCsrfToken()) . "'>\n";
        echo "<input type='hidden' name='setup_action' value='create_clinics'>\n";
        echo "<button type='submit' style='background: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;'>Create Clinics</button>\n";
        echo "</form>\n";
        echo "</div>\n";

        // Create Warehouses
        echo "<div style='border: 1px solid #ddd; border-radius: 8px; padding: 20px; background: white;'>\n";
        echo "<h3>📦 Create Warehouses</h3>\n";
        echo "<p>Create warehouses for each clinic in the system.</p>\n";
        echo "<form method='post' style='margin-top: 15px;'>\n";
        echo "<input type='hidden' name='csrf_token_form' value='" . attr(CsrfUtils::collectCsrfToken()) . "'>\n";
        echo "<input type='hidden' name='setup_action' value='create_warehouses'>\n";
        echo "<button type='submit' style='background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;'>Create Warehouses</button>\n";
        echo "</form>\n";
        echo "</div>\n";

        // Configure Settings
        echo "<div style='border: 1px solid #ddd; border-radius: 8px; padding: 20px; background: white;'>\n";
        echo "<h3>⚙️ Configure Settings</h3>\n";
        echo "<p>Set up default inventory settings for all clinics.</p>\n";
        echo "<form method='post' style='margin-top: 15px;'>\n";
        echo "<input type='hidden' name='csrf_token_form' value='" . attr(CsrfUtils::collectCsrfToken()) . "'>\n";
        echo "<input type='hidden' name='setup_action' value='configure_settings'>\n";
        echo "<button type='submit' style='background: #ffc107; color: black; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;'>Configure Settings</button>\n";
        echo "</form>\n";
        echo "</div>\n";

        // Test System
        echo "<div style='border: 1px solid #ddd; border-radius: 8px; padding: 20px; background: white;'>\n";
        echo "<h3>🧪 Test System</h3>\n";
        echo "<p>Run comprehensive tests to verify system functionality.</p>\n";
        echo "<form method='post' style='margin-top: 15px;'>\n";
        echo "<input type='hidden' name='csrf_token_form' value='" . attr(CsrfUtils::collectCsrfToken()) . "'>\n";
        echo "<input type='hidden' name='setup_action' value='test_system'>\n";
        echo "<button type='submit' style='background: #17a2b8; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;'>Test System</button>\n";
        echo "</form>\n";
        echo "</div>\n";

        // Generate Sample Data
        echo "<div style='border: 1px solid #ddd; border-radius: 8px; padding: 20px; background: white;'>\n";
        echo "<h3>📊 Generate Sample Data</h3>\n";
        echo "<p>Create sample inventory data for testing and training.</p>\n";
        echo "<form method='post' style='margin-top: 15px;'>\n";
        echo "<input type='hidden' name='csrf_token_form' value='" . attr(CsrfUtils::collectCsrfToken()) . "'>\n";
        echo "<input type='hidden' name='setup_action' value='generate_sample_data'>\n";
        echo "<button type='submit' style='background: #6f42c1; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;'>Generate Sample Data</button>\n";
        echo "</form>\n";
        echo "</div>\n";

        echo "</div>\n";

        // Display results
        if (!empty($this->setup_results)) {
            echo "<div style='background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 20px 0;'>\n";
            echo "<h3>Setup Results:</h3>\n";
            echo "<ul>\n";
            foreach ($this->setup_results as $result) {
                echo "<li>$result</li>\n";
            }
            echo "</ul>\n";
            echo "</div>\n";
        }
    }

    /**
     * Create default clinics
     */
    private function createDefaultClinics()
    {
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
            array('Coastal Medical Center', '555-1001', '555-1002', '741 Coast Dr', 'Coastal', 'CA', '90219')
        );

        $created_count = 0;
        foreach ($clinics as $clinic) {
            $result = sqlInsert(
                "INSERT INTO facility (name, phone, fax, street, city, state, postal_code, billing_location, service_location) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)",
                $clinic
            );
            if ($result) {
                $created_count++;
            }
        }

        $this->setup_results[] = "✅ Created $created_count clinics successfully";
    }

    /**
     * Create default warehouses
     */
    private function createDefaultWarehouses()
    {
        $facilities = sqlStatement("SELECT id, name FROM facility WHERE billing_location = 1 ORDER BY id");
        $created_count = 0;

        while ($facility = sqlFetchArray($facilities)) {
            $warehouse_id = 'WH_CLINIC_' . $facility['id'];
            $result = sqlInsert(
                "INSERT INTO list_options (list_id, option_id, title, option_value, seq, activity) 
                 VALUES ('warehouse', ?, 'Main Warehouse', ?, ?, 1)",
                array($warehouse_id, $facility['id'], $facility['id'])
            );
            if ($result) {
                $created_count++;
            }
        }

        $this->setup_results[] = "✅ Created $created_count warehouses successfully";
    }

    /**
     * Configure default settings
     */
    private function configureDefaultSettings()
    {
        $facilities = sqlStatement("SELECT id FROM facility WHERE billing_location = 1");
        $configured_count = 0;

        while ($facility = sqlFetchArray($facilities)) {
            $facility_id = $facility['id'];
            
            $settings = array(
                'low_stock_threshold_days' => 30,
                'expiration_warning_days' => 90,
                'emergency_transfer_limit' => 1000,
                'auto_transfer_enabled' => 0,
                'transfer_approval_required' => 1,
                'central_reporting_enabled' => 1,
                'snapshot_frequency' => 'daily',
                'alert_escalation_enabled' => 1
            );

            foreach ($settings as $setting_name => $setting_value) {
                $this->multi_inventory->setClinicSetting($facility_id, $setting_name, $setting_value);
            }
            $configured_count++;
        }

        $this->setup_results[] = "✅ Configured settings for $configured_count clinics";
    }

    /**
     * Test the system
     */
    private function testSystem()
    {
        $tests_passed = 0;
        $total_tests = 0;

        // Test class loading
        $total_tests++;
        try {
            $test_instance = new MultiClinicInventory();
            $tests_passed++;
        } catch (Exception $e) {
            $this->setup_results[] = "❌ Class loading test failed";
        }

        // Test facility access
        $total_tests++;
        $facilities = $this->multi_inventory->getUserFacilities();
        if (is_array($facilities) && !empty($facilities)) {
            $tests_passed++;
        } else {
            $this->setup_results[] = "❌ Facility access test failed";
        }

        // Test inventory summary
        $total_tests++;
        if (!empty($facilities)) {
            $facility_id = array_key_first($facilities);
            $inventory = $this->multi_inventory->getClinicInventorySummary($facility_id);
            if (is_array($inventory)) {
                $tests_passed++;
            } else {
                $this->setup_results[] = "❌ Inventory summary test failed";
            }
        }

        // Test transfer history
        $total_tests++;
        $transfers = $this->multi_inventory->getTransferHistory();
        if (is_array($transfers)) {
            $tests_passed++;
        } else {
            $this->setup_results[] = "❌ Transfer history test failed";
        }

        $this->setup_results[] = "✅ System tests: $tests_passed/$total_tests passed";
    }

    /**
     * Generate sample data
     */
    private function generateSampleData()
    {
        // Create sample drugs if none exist
        $drug_count = sqlQuery("SELECT COUNT(*) as count FROM drugs WHERE active = 1")['count'];
        
        if ($drug_count == 0) {
            $sample_drugs = array(
                array('Aspirin 325mg', '00071015423', 'tablet', 1),
                array('Ibuprofen 400mg', '00071015424', 'tablet', 1),
                array('Acetaminophen 500mg', '00071015425', 'tablet', 1),
                array('Bandages 3x3', '00071015426', 'box', 1),
                array('Gauze Pads 4x4', '00071015427', 'box', 1),
                array('Medical Tape', '00071015428', 'roll', 1),
                array('Alcohol Wipes', '00071015429', 'box', 1),
                array('Gloves Medium', '00071015430', 'box', 1),
                array('Syringes 10ml', '00071015431', 'box', 1),
                array('Needles 22G', '00071015432', 'box', 1)
            );

            $created_drugs = 0;
            foreach ($sample_drugs as $drug) {
                $result = sqlInsert(
                    "INSERT INTO drugs (name, ndc_number, form, active) VALUES (?, ?, ?, ?)",
                    $drug
                );
                if ($result) {
                    $created_drugs++;
                }
            }

            $this->setup_results[] = "✅ Created $created_drugs sample drugs";
        } else {
            $this->setup_results[] = "ℹ️ Drugs already exist, skipping drug creation";
        }

        // Create sample inventory if none exists
        $inventory_count = sqlQuery("SELECT COUNT(*) as count FROM drug_inventory")['count'];
        
        if ($inventory_count == 0) {
            $facilities = sqlStatement("SELECT id FROM facility WHERE billing_location = 1 LIMIT 3");
            $drugs = sqlStatement("SELECT drug_id FROM drugs WHERE active = 1 LIMIT 5");
            
            $created_inventory = 0;
            while ($facility = sqlFetchArray($facilities)) {
                $warehouse = sqlQuery(
                    "SELECT option_id FROM list_options WHERE list_id = 'warehouse' AND option_value = ?",
                    array($facility['id'])
                );
                
                if ($warehouse) {
                    while ($drug = sqlFetchArray($drugs)) {
                        $quantity = rand(10, 100);
                        $cost = rand(5, 50);
                        
                        $result = sqlInsert(
                            "INSERT INTO drug_inventory (drug_id, lot_number, expiration, on_hand, warehouse_id, cost_per_unit) 
                             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 YEAR), ?, ?, ?)",
                            array($drug['drug_id'], 'LOT' . rand(1000, 9999), $quantity, $warehouse['option_id'], $cost)
                        );
                        if ($result) {
                            $created_inventory++;
                        }
                    }
                    // Reset drugs cursor
                    $drugs = sqlStatement("SELECT drug_id FROM drugs WHERE active = 1 LIMIT 5");
                }
            }

            $this->setup_results[] = "✅ Created $created_inventory sample inventory items";
        } else {
            $this->setup_results[] = "ℹ️ Inventory already exists, skipping inventory creation";
        }
    }
}

// Run the setup
$setup = new MultiClinicInventorySetup();
$setup->runSetup();
?> 