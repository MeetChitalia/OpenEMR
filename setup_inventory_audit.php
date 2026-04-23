<?php

/**
 * Inventory Audit System Setup Script
 *
 * This script helps install and configure the comprehensive inventory audit system
 * for OpenEMR. It sets up database tables, configurations, and verifies the installation.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    OpenEMR Team
 * @copyright Copyright (c) 2024 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("library/sql.inc.php");
require_once("library/forms.inc.php");

use OpenEMR\Common\Acl\AclMain;

// Check authorization
if (!AclMain::aclCheckCore('admin', 'super')) {
    die("Access denied. Admin privileges required.");
}

$step = $_GET['step'] ?? 1;
$error_message = '';
$success_message = '';

// Function to check if table exists
function tableExists($table_name) {
    $result = sqlQuery("SHOW TABLES LIKE ?", array($table_name));
    return $result !== false;
}

// Function to check if trigger exists
function triggerExists($trigger_name) {
    $result = sqlQuery("SHOW TRIGGERS LIKE ?", array($trigger_name));
    return $result !== false;
}

// Function to execute SQL file
function executeSQLFile($filename) {
    if (!file_exists($filename)) {
        return false;
    }
    
    $sql = file_get_contents($filename);
    $statements = explode(';', $sql);
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            try {
                sqlStatement($statement);
            } catch (Exception $e) {
                error_log("SQL Error: " . $e->getMessage());
                return false;
            }
        }
    }
    
    return true;
}

// Handle form submissions
if ($_POST['action'] == 'install') {
    $step = 2;
    
    // Step 1: Create database tables
    if (executeSQLFile('sql/inventory_audit_system.sql')) {
        $success_message = "Database tables created successfully!";
    } else {
        $error_message = "Error creating database tables. Check error logs.";
        $step = 1;
    }
} elseif ($_POST['action'] == 'verify') {
    $step = 3;
    
    // Step 2: Verify installation
    $tables_exist = tableExists('inventory_movement_log') && 
                   tableExists('inventory_settings') && 
                   tableExists('inventory_alerts');
    
    $triggers_exist = triggerExists('trg_drug_sales_audit') && 
                     triggerExists('trg_drug_inventory_update_audit');
    
    if ($tables_exist && $triggers_exist) {
        $success_message = "Installation verified successfully! All components are working.";
    } else {
        $error_message = "Installation verification failed. Some components are missing.";
        $step = 2;
    }
} elseif ($_POST['action'] == 'configure') {
    $step = 4;
    
    // Step 3: Configure settings
    $settings = array(
        'audit_trail_enabled' => $_POST['audit_trail_enabled'] ?? '1',
        'audit_retention_days' => $_POST['audit_retention_days'] ?? '365',
        'audit_log_level' => $_POST['audit_log_level'] ?? 'standard',
        'audit_email_notifications' => $_POST['audit_email_notifications'] ?? '0',
        'audit_alert_threshold' => $_POST['audit_alert_threshold'] ?? '100'
    );
    
    $success = true;
    foreach ($settings as $name => $value) {
        $result = sqlStatement(
            "INSERT INTO inventory_settings (setting_name, setting_value, setting_description, is_global) 
             VALUES (?, ?, ?, 1) 
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            array($name, $value, 'Audit setting: ' . $name)
        );
        
        if (!$result) {
            $success = false;
        }
    }
    
    if ($success) {
        $success_message = "Configuration completed successfully!";
    } else {
        $error_message = "Error saving configuration settings.";
        $step = 3;
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Inventory Audit System Setup</title>
    <link rel="stylesheet" href="interface/themes/style_blue.css" type="text/css">
    <style>
        .setup-container {
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            padding: 20px 0;
            border-bottom: 2px solid #eee;
        }
        .step {
            flex: 1;
            text-align: center;
            padding: 10px;
            background: #f8f9fa;
            margin: 0 5px;
            border-radius: 5px;
            position: relative;
        }
        .step.active {
            background: #007bff;
            color: white;
        }
        .step.completed {
            background: #28a745;
            color: white;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        .btn-primary {
            background: #007bff;
            color: white;
        }
        .btn-success {
            background: #28a745;
            color: white;
        }
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .check-list {
            list-style: none;
            padding: 0;
        }
        .check-list li {
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        .check-list li:before {
            content: "✓";
            color: #28a745;
            font-weight: bold;
            margin-right: 10px;
        }
        .check-list li.error:before {
            content: "✗";
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <h1>Inventory Audit System Setup</h1>
        
        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step <?php echo $step >= 1 ? 'active' : ''; ?>">
                <strong>Step 1</strong><br>
                Database Setup
            </div>
            <div class="step <?php echo $step >= 2 ? 'active' : ''; ?>">
                <strong>Step 2</strong><br>
                Verification
            </div>
            <div class="step <?php echo $step >= 3 ? 'active' : ''; ?>">
                <strong>Step 3</strong><br>
                Configuration
            </div>
            <div class="step <?php echo $step >= 4 ? 'active' : ''; ?>">
                <strong>Step 4</strong><br>
                Complete
            </div>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <?php if ($step == 1): ?>
            <!-- Step 1: Database Setup -->
            <h2>Step 1: Database Setup</h2>
            <p>This step will create the necessary database tables and triggers for the inventory audit system.</p>
            
            <div class="form-group">
                <h3>What will be installed:</h3>
                <ul class="check-list">
                    <li>inventory_movement_log table - Main audit log</li>
                    <li>inventory_settings table - System configuration</li>
                    <li>inventory_alerts table - Alert system</li>
                    <li>Database triggers for automatic logging</li>
                    <li>Database indexes for performance</li>
                    <li>Database views for reporting</li>
                </ul>
            </div>
            
            <form method="post">
                <input type="hidden" name="action" value="install">
                <button type="submit" class="btn btn-primary">Install Database Components</button>
            </form>

        <?php elseif ($step == 2): ?>
            <!-- Step 2: Verification -->
            <h2>Step 2: Installation Verification</h2>
            <p>Verifying that all database components were installed correctly.</p>
            
            <div class="form-group">
                <h3>Verification Results:</h3>
                <ul class="check-list">
                    <?php
                    $tables = array(
                        'inventory_movement_log' => 'Main audit log table',
                        'inventory_settings' => 'Settings table',
                        'inventory_alerts' => 'Alerts table'
                    );
                    
                    $all_tables_exist = true;
                    foreach ($tables as $table => $description) {
                        $exists = tableExists($table);
                        $all_tables_exist = $all_tables_exist && $exists;
                        echo "<li class='" . ($exists ? '' : 'error') . "'>$description ($table): " . ($exists ? '✓ Installed' : '✗ Missing') . "</li>";
                    }
                    ?>
                </ul>
                
                <h3>Trigger Verification:</h3>
                <ul class="check-list">
                    <?php
                    $triggers = array(
                        'trg_drug_sales_audit' => 'Drug sales audit trigger',
                        'trg_drug_inventory_update_audit' => 'Inventory update audit trigger'
                    );
                    
                    $all_triggers_exist = true;
                    foreach ($triggers as $trigger => $description) {
                        $exists = triggerExists($trigger);
                        $all_triggers_exist = $all_triggers_exist && $exists;
                        echo "<li class='" . ($exists ? '' : 'error') . "'>$description ($trigger): " . ($exists ? '✓ Installed' : '✗ Missing') . "</li>";
                    }
                    ?>
                </ul>
            </div>
            
            <?php if ($all_tables_exist && $all_triggers_exist): ?>
                <form method="post">
                    <input type="hidden" name="action" value="verify">
                    <button type="submit" class="btn btn-success">Continue to Configuration</button>
                </form>
            <?php else: ?>
                <p><strong>Some components are missing. Please check the error logs and try again.</strong></p>
                <a href="?step=1" class="btn btn-primary">Retry Installation</a>
            <?php endif; ?>

        <?php elseif ($step == 3): ?>
            <!-- Step 3: Configuration -->
            <h2>Step 3: System Configuration</h2>
            <p>Configure the basic settings for the inventory audit system.</p>
            
            <form method="post">
                <input type="hidden" name="action" value="configure">
                
                <div class="form-group">
                    <label for="audit_trail_enabled">Enable Audit Trail:</label>
                    <select name="audit_trail_enabled" id="audit_trail_enabled">
                        <option value="1">Yes - Enable comprehensive audit logging</option>
                        <option value="0">No - Disable audit logging</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="audit_log_level">Log Level:</label>
                    <select name="audit_log_level" id="audit_log_level">
                        <option value="minimal">Minimal - Basic movement tracking</option>
                        <option value="standard" selected>Standard - Full audit trail with user tracking</option>
                        <option value="detailed">Detailed - Complete audit with cost tracking and notes</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="audit_retention_days">Retention Period (Days):</label>
                    <input type="number" name="audit_retention_days" id="audit_retention_days" value="365" min="30" max="3650">
                    <small>How long to keep audit records before automatic cleanup (30-3650 days)</small>
                </div>
                
                <div class="form-group">
                    <label for="audit_email_notifications">Email Notifications:</label>
                    <select name="audit_email_notifications" id="audit_email_notifications">
                        <option value="0" selected>No - Disable email notifications</option>
                        <option value="1">Yes - Enable email notifications for significant events</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="audit_alert_threshold">Alert Threshold:</label>
                    <input type="number" name="audit_alert_threshold" id="audit_alert_threshold" value="100" min="1" max="10000">
                    <small>Number of movements per day that triggers an alert notification</small>
                </div>
                
                <button type="submit" class="btn btn-success">Save Configuration</button>
            </form>

        <?php elseif ($step == 4): ?>
            <!-- Step 4: Complete -->
            <h2>Step 4: Installation Complete!</h2>
            <p>The inventory audit system has been successfully installed and configured.</p>
            
            <div class="form-group">
                <h3>What's Next:</h3>
                <ul class="check-list">
                    <li>Access the Audit menu from the main navigation</li>
                    <li>Review the Inventory Audit Log to see current activity</li>
                    <li>Configure additional settings in Audit Settings</li>
                    <li>Set up user training for the new audit features</li>
                    <li>Monitor system performance and adjust as needed</li>
                </ul>
            </div>
            
            <div class="form-group">
                <h3>Quick Links:</h3>
                <p>
                    <a href="interface/audit/inventory_audit_log.php" class="btn btn-primary">View Audit Log</a>
                    <a href="interface/audit/audit_settings.php" class="btn btn-primary">Audit Settings</a>
                    <a href="interface/main/main_screen.php" class="btn btn-success">Return to Main Menu</a>
                </p>
            </div>
            
            <div class="form-group">
                <h3>Documentation:</h3>
                <p>For detailed information about using the inventory audit system, please refer to the documentation in <code>INVENTORY_AUDIT_SYSTEM_DOCUMENTATION.md</code></p>
            </div>

        <?php endif; ?>
    </div>

    <script>
        // Auto-submit forms for steps 2 and 3
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($step == 2): ?>
                // Auto-submit verification form
                setTimeout(function() {
                    document.querySelector('form').submit();
                }, 2000);
            <?php endif; ?>
        });
    </script>
</body>
</html> 