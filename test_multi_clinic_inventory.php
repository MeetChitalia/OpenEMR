<?php
/**
 * Multi-Clinic Inventory System - Testing Script
 * 
 * This script tests all major functionality of the multi-clinic inventory system
 * Run this script after deployment to verify everything is working correctly
 */

// Include OpenEMR configuration
require_once("../../globals.php");
require_once("$srcdir/acl.inc");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/formatting.inc.php");

// Include our multi-clinic inventory class
require_once("$srcdir/classes/MultiClinicInventory.class.php");

use OpenEMR\Common\Csrf\CsrfUtils;

class MultiClinicInventoryTester
{
    private $multi_inventory;
    private $test_results = array();
    private $errors = array();
    private $warnings = array();

    public function __construct()
    {
        $this->multi_inventory = new MultiClinicInventory();
    }

    /**
     * Run all tests
     */
    public function runAllTests()
    {
        echo "<h1>Multi-Clinic Inventory System - Test Results</h1>\n";
        echo "<div style='font-family: Arial, sans-serif; max-width: 1200px; margin: 0 auto;'>\n";
        
        $this->testDatabaseTables();
        $this->testClassLoading();
        $this->testFacilityAccess();
        $this->testInventoryOperations();
        $this->testTransferOperations();
        $this->testAlertSystem();
        $this->testReportingSystem();
        $this->testSecurityFeatures();
        
        $this->displayResults();
        
        echo "</div>\n";
    }

    /**
     * Test database tables
     */
    private function testDatabaseTables()
    {
        echo "<h2>1. Database Tables Test</h2>\n";
        
        $required_tables = array(
            'central_inventory',
            'clinic_inventory_settings',
            'clinic_transfers',
            'clinic_transfer_items',
            'clinic_inventory_requests',
            'clinic_request_items',
            'clinic_inventory_alerts',
            'central_inventory_reports',
            'clinic_inventory_snapshots'
        );

        foreach ($required_tables as $table) {
            $result = sqlQuery("SHOW TABLES LIKE ?", array($table));
            if ($result) {
                $this->test_results[] = "✅ Table '$table' exists";
                echo "<div style='color: green; margin: 5px 0;'>✅ Table '$table' exists</div>\n";
            } else {
                $this->errors[] = "❌ Table '$table' missing";
                echo "<div style='color: red; margin: 5px 0;'>❌ Table '$table' missing</div>\n";
            }
        }

        // Test indexes
        $indexes = sqlStatement("SHOW INDEX FROM drug_inventory WHERE Key_name = 'idx_facility_warehouse'");
        if (sqlNumRows($indexes) > 0) {
            $this->test_results[] = "✅ Database indexes created";
            echo "<div style='color: green; margin: 5px 0;'>✅ Database indexes created</div>\n";
        } else {
            $this->warnings[] = "⚠️ Database indexes may not be optimized";
            echo "<div style='color: orange; margin: 5px 0;'>⚠️ Database indexes may not be optimized</div>\n";
        }
    }

    /**
     * Test class loading
     */
    private function testClassLoading()
    {
        echo "<h2>2. Class Loading Test</h2>\n";
        
        try {
            $test_instance = new MultiClinicInventory();
            $this->test_results[] = "✅ MultiClinicInventory class loads successfully";
            echo "<div style='color: green; margin: 5px 0;'>✅ MultiClinicInventory class loads successfully</div>\n";
            
            // Test basic methods
            $facilities = $test_instance->getUserFacilities();
            if (is_array($facilities)) {
                $this->test_results[] = "✅ getUserFacilities() method works";
                echo "<div style='color: green; margin: 5px 0;'>✅ getUserFacilities() method works</div>\n";
            } else {
                $this->errors[] = "❌ getUserFacilities() method failed";
                echo "<div style='color: red; margin: 5px 0;'>❌ getUserFacilities() method failed</div>\n";
            }
            
        } catch (Exception $e) {
            $this->errors[] = "❌ Class loading failed: " . $e->getMessage();
            echo "<div style='color: red; margin: 5px 0;'>❌ Class loading failed: " . htmlspecialchars($e->getMessage()) . "</div>\n";
        }
    }

    /**
     * Test facility access
     */
    private function testFacilityAccess()
    {
        echo "<h2>3. Facility Access Test</h2>\n";
        
        // Get facilities
        $facilities = $this->multi_inventory->getUserFacilities();
        
        if (empty($facilities)) {
            $this->warnings[] = "⚠️ No facilities found - check facility configuration";
            echo "<div style='color: orange; margin: 5px 0;'>⚠️ No facilities found - check facility configuration</div>\n";
        } else {
            $this->test_results[] = "✅ Found " . count($facilities) . " facilities";
            echo "<div style='color: green; margin: 5px 0;'>✅ Found " . count($facilities) . " facilities</div>\n";
            
            // Test first facility
            $first_facility_id = array_key_first($facilities);
            $inventory = $this->multi_inventory->getClinicInventorySummary($first_facility_id);
            
            if ($inventory !== false) {
                $this->test_results[] = "✅ Can access clinic inventory";
                echo "<div style='color: green; margin: 5px 0;'>✅ Can access clinic inventory</div>\n";
            } else {
                $this->errors[] = "❌ Cannot access clinic inventory";
                echo "<div style='color: red; margin: 5px 0;'>❌ Cannot access clinic inventory</div>\n";
            }
        }
    }

    /**
     * Test inventory operations
     */
    private function testInventoryOperations()
    {
        echo "<h2>4. Inventory Operations Test</h2>\n";
        
        $facilities = $this->multi_inventory->getUserFacilities();
        if (empty($facilities)) {
            echo "<div style='color: orange; margin: 5px 0;'>⚠️ Skipping inventory tests - no facilities available</div>\n";
            return;
        }

        $facility_id = array_key_first($facilities);
        
        // Test inventory summary
        $inventory = $this->multi_inventory->getClinicInventorySummary($facility_id);
        if (is_array($inventory)) {
            $this->test_results[] = "✅ Inventory summary retrieval works";
            echo "<div style='color: green; margin: 5px 0;'>✅ Inventory summary retrieval works</div>\n";
        } else {
            $this->errors[] = "❌ Inventory summary retrieval failed";
            echo "<div style='color: red; margin: 5px 0;'>❌ Inventory summary retrieval failed</div>\n";
        }

        // Test clinic settings
        $setting = $this->multi_inventory->getClinicSetting($facility_id, 'low_stock_threshold_days', 30);
        if ($setting !== null) {
            $this->test_results[] = "✅ Clinic settings retrieval works";
            echo "<div style='color: green; margin: 5px 0;'>✅ Clinic settings retrieval works</div>\n";
        } else {
            $this->warnings[] = "⚠️ Clinic settings may not be configured";
            echo "<div style='color: orange; margin: 5px 0;'>⚠️ Clinic settings may not be configured</div>\n";
        }
    }

    /**
     * Test transfer operations
     */
    private function testTransferOperations()
    {
        echo "<h2>5. Transfer Operations Test</h2>\n";
        
        $facilities = $this->multi_inventory->getUserFacilities();
        if (count($facilities) < 2) {
            echo "<div style='color: orange; margin: 5px 0;'>⚠️ Skipping transfer tests - need at least 2 facilities</div>\n";
            return;
        }

        $facility_ids = array_keys($facilities);
        $from_facility = $facility_ids[0];
        $to_facility = $facility_ids[1];

        // Test transfer history
        $transfers = $this->multi_inventory->getTransferHistory();
        if (is_array($transfers)) {
            $this->test_results[] = "✅ Transfer history retrieval works";
            echo "<div style='color: green; margin: 5px 0;'>✅ Transfer history retrieval works</div>\n";
        } else {
            $this->errors[] = "❌ Transfer history retrieval failed";
            echo "<div style='color: red; margin: 5px 0;'>❌ Transfer history retrieval failed</div>\n";
        }

        // Test creating a transfer request (if we have drugs)
        $drugs = sqlStatement("SELECT drug_id FROM drugs WHERE active = 1 LIMIT 1");
        if ($drug = sqlFetchArray($drugs)) {
            $items = array(
                array(
                    'drug_id' => $drug['drug_id'],
                    'quantity' => 1,
                    'reason' => 'Test transfer'
                )
            );

            $transfer_id = $this->multi_inventory->createTransferRequest(
                $from_facility, $to_facility, $items, 'scheduled', 'normal', 'Test transfer'
            );

            if ($transfer_id) {
                $this->test_results[] = "✅ Transfer request creation works";
                echo "<div style='color: green; margin: 5px 0;'>✅ Transfer request creation works (ID: $transfer_id)</div>\n";
                
                // Clean up test transfer
                sqlStatement("DELETE FROM clinic_transfer_items WHERE transfer_id = ?", array($transfer_id));
                sqlStatement("DELETE FROM clinic_transfers WHERE transfer_id = ?", array($transfer_id));
            } else {
                $this->errors[] = "❌ Transfer request creation failed";
                echo "<div style='color: red; margin: 5px 0;'>❌ Transfer request creation failed</div>\n";
            }
        } else {
            $this->warnings[] = "⚠️ No active drugs found for transfer testing";
            echo "<div style='color: orange; margin: 5px 0;'>⚠️ No active drugs found for transfer testing</div>\n";
        }
    }

    /**
     * Test alert system
     */
    private function testAlertSystem()
    {
        echo "<h2>6. Alert System Test</h2>\n";
        
        $facilities = $this->multi_inventory->getUserFacilities();
        if (empty($facilities)) {
            echo "<div style='color: orange; margin: 5px 0;'>⚠️ Skipping alert tests - no facilities available</div>\n";
            return;
        }

        $facility_id = array_key_first($facilities);
        
        // Test alert retrieval
        $alerts = $this->multi_inventory->getClinicAlerts($facility_id);
        if (is_array($alerts)) {
            $this->test_results[] = "✅ Alert system retrieval works";
            echo "<div style='color: green; margin: 5px 0;'>✅ Alert system retrieval works</div>\n";
        } else {
            $this->errors[] = "❌ Alert system retrieval failed";
            echo "<div style='color: red; margin: 5px 0;'>❌ Alert system retrieval failed</div>\n";
        }

        // Test creating an alert (if we have drugs)
        $drugs = sqlStatement("SELECT drug_id FROM drugs WHERE active = 1 LIMIT 1");
        if ($drug = sqlFetchArray($drugs)) {
            $alert_id = $this->multi_inventory->createClinicAlert(
                $facility_id, $drug['drug_id'], 'low_stock', 'Test alert', 10, 5
            );

            if ($alert_id) {
                $this->test_results[] = "✅ Alert creation works";
                echo "<div style='color: green; margin: 5px 0;'>✅ Alert creation works (ID: $alert_id)</div>\n";
                
                // Clean up test alert
                sqlStatement("DELETE FROM clinic_inventory_alerts WHERE alert_id = ?", array($alert_id));
            } else {
                $this->errors[] = "❌ Alert creation failed";
                echo "<div style='color: red; margin: 5px 0;'>❌ Alert creation failed</div>\n";
            }
        } else {
            $this->warnings[] = "⚠️ No active drugs found for alert testing";
            echo "<div style='color: orange; margin: 5px 0;'>⚠️ No active drugs found for alert testing</div>\n";
        }
    }

    /**
     * Test reporting system
     */
    private function testReportingSystem()
    {
        echo "<h2>7. Reporting System Test</h2>\n";
        
        // Test central inventory overview (owner only)
        $overview = $this->multi_inventory->getCentralInventoryOverview();
        if (is_array($overview)) {
            $this->test_results[] = "✅ Central inventory overview works";
            echo "<div style='color: green; margin: 5px 0;'>✅ Central inventory overview works</div>\n";
        } else {
            $this->warnings[] = "⚠️ Central inventory overview not available (may be permission issue)";
            echo "<div style='color: orange; margin: 5px 0;'>⚠️ Central inventory overview not available (may be permission issue)</div>\n";
        }

        // Test report generation
        $facilities = $this->multi_inventory->getUserFacilities();
        if (!empty($facilities)) {
            $facility_id = array_key_first($facilities);
            $report_id = $this->multi_inventory->generateCentralReport('daily', $facility_id);
            
            if ($report_id) {
                $this->test_results[] = "✅ Report generation works";
                echo "<div style='color: green; margin: 5px 0;'>✅ Report generation works (ID: $report_id)</div>\n";
                
                // Clean up test report
                sqlStatement("DELETE FROM central_inventory_reports WHERE report_id = ?", array($report_id));
            } else {
                $this->warnings[] = "⚠️ Report generation may not be available";
                echo "<div style='color: orange; margin: 5px 0;'>⚠️ Report generation may not be available</div>\n";
            }
        }
    }

    /**
     * Test security features
     */
    private function testSecurityFeatures()
    {
        echo "<h2>8. Security Features Test</h2>\n";
        
        // Test CSRF token generation
        $csrf_token = CsrfUtils::collectCsrfToken();
        if (!empty($csrf_token)) {
            $this->test_results[] = "✅ CSRF protection available";
            echo "<div style='color: green; margin: 5px 0;'>✅ CSRF protection available</div>\n";
        } else {
            $this->warnings[] = "⚠️ CSRF protection may not be configured";
            echo "<div style='color: orange; margin: 5px 0;'>⚠️ CSRF protection may not be configured</div>\n";
        }

        // Test SQL injection prevention (basic test)
        $test_input = "'; DROP TABLE users; --";
        $result = sqlQuery("SELECT COUNT(*) as count FROM facility WHERE name = ?", array($test_input));
        if ($result !== false) {
            $this->test_results[] = "✅ SQL injection prevention appears to be working";
            echo "<div style='color: green; margin: 5px 0;'>✅ SQL injection prevention appears to be working</div>\n";
        } else {
            $this->warnings[] = "⚠️ SQL injection prevention test inconclusive";
            echo "<div style='color: orange; margin: 5px 0;'>⚠️ SQL injection prevention test inconclusive</div>\n";
        }
    }

    /**
     * Display test results summary
     */
    private function displayResults()
    {
        echo "<h2>Test Results Summary</h2>\n";
        
        $total_tests = count($this->test_results) + count($this->errors) + count($this->warnings);
        $passed_tests = count($this->test_results);
        $failed_tests = count($this->errors);
        $warning_tests = count($this->warnings);
        
        echo "<div style='background: #f5f5f5; padding: 20px; border-radius: 8px; margin: 20px 0;'>\n";
        echo "<h3>Overall Results:</h3>\n";
        echo "<p><strong>Total Tests:</strong> $total_tests</p>\n";
        echo "<p><strong>Passed:</strong> <span style='color: green;'>$passed_tests</span></p>\n";
        echo "<p><strong>Failed:</strong> <span style='color: red;'>$failed_tests</span></p>\n";
        echo "<p><strong>Warnings:</strong> <span style='color: orange;'>$warning_tests</span></p>\n";
        
        if ($failed_tests == 0) {
            echo "<div style='background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
            echo "<strong>🎉 All critical tests passed! The system appears to be working correctly.</strong>\n";
            echo "</div>\n";
        } else {
            echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
            echo "<strong>⚠️ Some tests failed. Please review the errors above before proceeding.</strong>\n";
            echo "</div>\n";
        }
        
        if ($warning_tests > 0) {
            echo "<div style='background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
            echo "<strong>📝 There are $warning_tests warnings. These should be addressed but are not critical.</strong>\n";
            echo "</div>\n";
        }
        
        echo "</div>\n";
        
        // Display recommendations
        echo "<h3>Recommendations:</h3>\n";
        echo "<ul>\n";
        
        if ($failed_tests > 0) {
            echo "<li style='color: red;'>Fix the failed tests before proceeding with deployment</li>\n";
        }
        
        if ($warning_tests > 0) {
            echo "<li style='color: orange;'>Address the warnings to ensure optimal system performance</li>\n";
        }
        
        if ($passed_tests > 0) {
            echo "<li style='color: green;'>Proceed with user training and go-live preparation</li>\n";
        }
        
        echo "<li>Run this test script again after any configuration changes</li>\n";
        echo "<li>Monitor system performance after go-live</li>\n";
        echo "</ul>\n";
    }
}

// Run the tests
$tester = new MultiClinicInventoryTester();
$tester->runAllTests();
?> 