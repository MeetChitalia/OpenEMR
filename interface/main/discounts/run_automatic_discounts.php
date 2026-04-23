<?php
/**
 * Automatic Discount Management Runner
 * 
 * This script can be run manually or via cron job to automatically
 * activate and deactivate discounts based on their start/end dates.
 * 
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(dirname(__FILE__) . "/../../globals.php");
require_once($GLOBALS['srcdir'] . "/Discount/DiscountManager.php");

use OpenEMR\Discount\DiscountManager;

// Check if running from command line or web
$isCommandLine = (php_sapi_name() === 'cli');

if ($isCommandLine) {
    echo "Starting Automatic Discount Management...\n";
    echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
    echo "----------------------------------------\n";
} else {
    // Web interface - check permissions
    if (!acl_check('admin', 'discounts')) {
        echo "<div class='alert alert-danger'>Access Denied</div>";
        exit;
    }
}

try {
    $discountManager = new DiscountManager();
    $result = $discountManager->runAutomaticDiscountManagement();
    
    if ($isCommandLine) {
        echo "Results:\n";
        echo "- Activated: " . $result['activated'] . " discounts\n";
        echo "- Deactivated: " . $result['deactivated'] . " discounts\n";
        echo "- Timestamp: " . $result['timestamp'] . "\n\n";
        
        if (!empty($result['activated_details'])) {
            echo "Activated Discounts:\n";
            foreach ($result['activated_details'] as $discount) {
                echo "- " . $discount['name'];
                if ($discount['description']) {
                    echo " (" . $discount['description'] . ")";
                }
                echo "\n";
            }
            echo "\n";
        }
        
        if (!empty($result['deactivated_details'])) {
            echo "Deactivated Discounts:\n";
            foreach ($result['deactivated_details'] as $discount) {
                echo "- " . $discount['name'];
                if ($discount['description']) {
                    echo " (" . $discount['description'] . ")";
                }
                echo "\n";
            }
            echo "\n";
        }
        
        echo "Automatic discount management completed successfully.\n";
    } else {
        // Web interface response
        echo "<div class='alert alert-success'>";
        echo "<h5>Automatic Discount Management Completed</h5>";
        echo "<p><strong>Activated:</strong> " . $result['activated'] . " discounts</p>";
        echo "<p><strong>Deactivated:</strong> " . $result['deactivated'] . " discounts</p>";
        echo "<p><strong>Timestamp:</strong> " . $result['timestamp'] . "</p>";
        
        if (!empty($result['activated_details'])) {
            echo "<div class='mt-3'>";
            echo "<strong>Activated Discounts:</strong><ul>";
            foreach ($result['activated_details'] as $discount) {
                echo "<li>" . htmlspecialchars($discount['name']);
                if ($discount['description']) {
                    echo " - " . htmlspecialchars($discount['description']);
                }
                echo "</li>";
            }
            echo "</ul></div>";
        }
        
        if (!empty($result['deactivated_details'])) {
            echo "<div class='mt-3'>";
            echo "<strong>Deactivated Discounts:</strong><ul>";
            foreach ($result['deactivated_details'] as $discount) {
                echo "<li>" . htmlspecialchars($discount['name']);
                if ($discount['description']) {
                    echo " - " . htmlspecialchars($discount['description']);
                }
                echo "</li>";
            }
            echo "</ul></div>";
        }
        
        echo "</div>";
    }
    
} catch (Exception $e) {
    $errorMessage = "Error running automatic discount management: " . $e->getMessage();
    
    if ($isCommandLine) {
        echo "ERROR: " . $errorMessage . "\n";
        exit(1);
    } else {
        echo "<div class='alert alert-danger'>" . htmlspecialchars($errorMessage) . "</div>";
    }
}
?> 