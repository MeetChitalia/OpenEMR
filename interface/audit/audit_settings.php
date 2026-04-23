<?php

/**
 * Audit Settings - Configure inventory audit log parameters
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    OpenEMR Team
 * @copyright Copyright (c) 2024 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;

// Check authorization
if (!AclMain::aclCheckCore('admin', 'super')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Audit Settings")]);
    exit;
}

// Handle form submission
if (!empty($_POST['form_save'])) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }
    
    // Save audit settings
    $settings = array(
        'audit_trail_enabled' => $_POST['audit_trail_enabled'] ?? '0',
        'audit_retention_days' => intval($_POST['audit_retention_days'] ?? 365),
        'audit_log_level' => $_POST['audit_log_level'] ?? 'standard',
        'audit_email_notifications' => $_POST['audit_email_notifications'] ?? '0',
        'audit_alert_threshold' => intval($_POST['audit_alert_threshold'] ?? 100),
        'audit_auto_cleanup' => $_POST['audit_auto_cleanup'] ?? '0',
        'audit_backup_enabled' => $_POST['audit_backup_enabled'] ?? '0',
        'audit_export_format' => $_POST['audit_export_format'] ?? 'csv'
    );
    
    foreach ($settings as $name => $value) {
        sqlStatement(
            "INSERT INTO inventory_settings (setting_name, setting_value, setting_description, is_global) 
             VALUES (?, ?, ?, 1) 
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            array($name, $value, 'Audit setting: ' . $name)
        );
    }
    
    $success_message = xl('Audit settings saved successfully.');
}

// Get current settings
function getAuditSetting($name, $default = '') {
    $res = sqlStatement(
        "SELECT setting_value FROM inventory_settings WHERE setting_name = ? AND is_global = 1",
        array($name)
    );
    $row = sqlFetchArray($res);
    return $row ? $row['setting_value'] : $default;
}

$current_settings = array(
    'audit_trail_enabled' => getAuditSetting('audit_trail_enabled', '1'),
    'audit_retention_days' => getAuditSetting('audit_retention_days', '365'),
    'audit_log_level' => getAuditSetting('audit_log_level', 'standard'),
    'audit_email_notifications' => getAuditSetting('audit_email_notifications', '0'),
    'audit_alert_threshold' => getAuditSetting('audit_alert_threshold', '100'),
    'audit_auto_cleanup' => getAuditSetting('audit_auto_cleanup', '0'),
    'audit_backup_enabled' => getAuditSetting('audit_backup_enabled', '0'),
    'audit_export_format' => getAuditSetting('audit_export_format', 'csv')
);

// Get audit statistics
$stats = array();
$res = sqlStatement("SELECT COUNT(*) as count FROM inventory_movement_log");
$row = sqlFetchArray($res);
$stats['total_records'] = $row ? $row['count'] : 0;

$res = sqlStatement("SELECT MIN(movement_date) as date FROM inventory_movement_log");
$row = sqlFetchArray($res);
$stats['oldest_record'] = $row ? $row['date'] : null;

$res = sqlStatement("SELECT MAX(movement_date) as date FROM inventory_movement_log");
$row = sqlFetchArray($res);
$stats['newest_record'] = $row ? $row['date'] : null;

$res = sqlStatement("SELECT COUNT(DISTINCT user_id) as count FROM inventory_movement_log");
$row = sqlFetchArray($res);
$stats['total_users'] = $row ? $row['count'] : 0;

?>
<html>
<head>
    <title><?php echo xlt('Audit Settings'); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
        .settings-section {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .stats-card {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .stats-number {
            font-size: 24px;
            font-weight: bold;
            color: #007bff;
        }
        .stats-label {
            color: #6c757d;
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .help-text {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
        }
    </style>
</head>
<body class="body_top">
    <div class="container-fluid">
        <h2><?php echo xlt('Audit Settings'); ?></h2>
        
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <?php echo text($success_message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Audit Statistics -->
        <div class="row">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number"><?php echo number_format($stats['total_records']); ?></div>
                    <div class="stats-label"><?php echo xlt('Total Audit Records'); ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number"><?php echo $stats['total_users']; ?></div>
                    <div class="stats-label"><?php echo xlt('Users Tracked'); ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number"><?php echo $stats['oldest_record'] ? oeFormatShortDate($stats['oldest_record']) : xl('N/A'); ?></div>
                    <div class="stats-label"><?php echo xlt('Oldest Record'); ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number"><?php echo $stats['newest_record'] ? oeFormatShortDate($stats['newest_record']) : xl('N/A'); ?></div>
                    <div class="stats-label"><?php echo xlt('Newest Record'); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Settings Form -->
        <form method="post" name="audit_settings_form" id="audit_settings_form">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
            
            <div class="settings-section">
                <h4><?php echo xlt('General Audit Settings'); ?></h4>
                
                <div class="form-group">
                    <label for="audit_trail_enabled">
                        <input type="checkbox" name="audit_trail_enabled" id="audit_trail_enabled" value="1" 
                               <?php echo $current_settings['audit_trail_enabled'] ? 'checked' : ''; ?> />
                        <?php echo xlt('Enable Audit Trail'); ?>
                    </label>
                    <div class="help-text">
                        <?php echo xlt('When enabled, all inventory movements will be logged with detailed information.'); ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="audit_log_level"><?php echo xlt('Log Level'); ?>:</label>
                    <select class="form-control" name="audit_log_level" id="audit_log_level">
                        <option value="minimal" <?php echo $current_settings['audit_log_level'] == 'minimal' ? 'selected' : ''; ?>>
                            <?php echo xlt('Minimal - Basic movement tracking'); ?>
                        </option>
                        <option value="standard" <?php echo $current_settings['audit_log_level'] == 'standard' ? 'selected' : ''; ?>>
                            <?php echo xlt('Standard - Full audit trail with user tracking'); ?>
                        </option>
                        <option value="detailed" <?php echo $current_settings['audit_log_level'] == 'detailed' ? 'selected' : ''; ?>>
                            <?php echo xlt('Detailed - Complete audit with cost tracking and notes'); ?>
                        </option>
                    </select>
                    <div class="help-text">
                        <?php echo xlt('Determines the level of detail captured in audit logs.'); ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="audit_retention_days"><?php echo xlt('Retention Period (Days)'); ?>:</label>
                    <input type="number" class="form-control" name="audit_retention_days" id="audit_retention_days" 
                           value="<?php echo attr($current_settings['audit_retention_days']); ?>" min="30" max="3650" />
                    <div class="help-text">
                        <?php echo xlt('How long to keep audit records before automatic cleanup (30-3650 days).'); ?>
                    </div>
                </div>
            </div>
            
            <div class="settings-section">
                <h4><?php echo xlt('Notification Settings'); ?></h4>
                
                <div class="form-group">
                    <label for="audit_email_notifications">
                        <input type="checkbox" name="audit_email_notifications" id="audit_email_notifications" value="1" 
                               <?php echo $current_settings['audit_email_notifications'] ? 'checked' : ''; ?> />
                        <?php echo xlt('Enable Email Notifications'); ?>
                    </label>
                    <div class="help-text">
                        <?php echo xlt('Send email notifications for significant audit events.'); ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="audit_alert_threshold"><?php echo xlt('Alert Threshold'); ?>:</label>
                    <input type="number" class="form-control" name="audit_alert_threshold" id="audit_alert_threshold" 
                           value="<?php echo attr($current_settings['audit_alert_threshold']); ?>" min="1" max="10000" />
                    <div class="help-text">
                        <?php echo xlt('Number of movements per day that triggers an alert notification.'); ?>
                    </div>
                </div>
            </div>
            
            <div class="settings-section">
                <h4><?php echo xlt('Maintenance Settings'); ?></h4>
                
                <div class="form-group">
                    <label for="audit_auto_cleanup">
                        <input type="checkbox" name="audit_auto_cleanup" id="audit_auto_cleanup" value="1" 
                               <?php echo $current_settings['audit_auto_cleanup'] ? 'checked' : ''; ?> />
                        <?php echo xlt('Enable Automatic Cleanup'); ?>
                    </label>
                    <div class="help-text">
                        <?php echo xlt('Automatically remove audit records older than the retention period.'); ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="audit_backup_enabled">
                        <input type="checkbox" name="audit_backup_enabled" id="audit_backup_enabled" value="1" 
                               <?php echo $current_settings['audit_backup_enabled'] ? 'checked' : ''; ?> />
                        <?php echo xlt('Enable Audit Backup'); ?>
                    </label>
                    <div class="help-text">
                        <?php echo xlt('Create backup copies of audit logs before cleanup.'); ?>
                    </div>
                </div>
            </div>
            
            <div class="settings-section">
                <h4><?php echo xlt('Export Settings'); ?></h4>
                
                <div class="form-group">
                    <label for="audit_export_format"><?php echo xlt('Default Export Format'); ?>:</label>
                    <select class="form-control" name="audit_export_format" id="audit_export_format">
                        <option value="csv" <?php echo $current_settings['audit_export_format'] == 'csv' ? 'selected' : ''; ?>>
                            <?php echo xlt('CSV'); ?>
                        </option>
                        <option value="xlsx" <?php echo $current_settings['audit_export_format'] == 'xlsx' ? 'selected' : ''; ?>>
                            <?php echo xlt('Excel (XLSX)'); ?>
                        </option>
                        <option value="pdf" <?php echo $current_settings['audit_export_format'] == 'pdf' ? 'selected' : ''; ?>>
                            <?php echo xlt('PDF'); ?>
                        </option>
                    </select>
                    <div class="help-text">
                        <?php echo xlt('Default format for audit log exports.'); ?>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <button type="submit" name="form_save" value="1" class="btn btn-primary">
                    <i class="fa fa-save"></i> <?php echo xlt('Save Settings'); ?>
                </button>
                <a href="inventory_audit_log.php" class="btn btn-secondary">
                    <i class="fa fa-arrow-left"></i> <?php echo xlt('Back to Audit Log'); ?>
                </a>
            </div>
        </form>
        
        <!-- Maintenance Actions -->
        <div class="settings-section">
            <h4><?php echo xlt('Maintenance Actions'); ?></h4>
            
            <div class="row">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo xlt('Cleanup Old Records'); ?></h5>
                            <p class="card-text"><?php echo xlt('Remove audit records older than the retention period.'); ?></p>
                            <button type="button" class="btn btn-warning btn-sm" onclick="confirmCleanup()">
                                <i class="fa fa-trash"></i> <?php echo xlt('Run Cleanup'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo xlt('Backup Audit Log'); ?></h5>
                            <p class="card-text"><?php echo xlt('Create a backup of all audit records.'); ?></p>
                            <button type="button" class="btn btn-info btn-sm" onclick="backupAuditLog()">
                                <i class="fa fa-download"></i> <?php echo xlt('Create Backup'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo xlt('Reset Audit Settings'); ?></h5>
                            <p class="card-text"><?php echo xlt('Reset all audit settings to default values.'); ?></p>
                            <button type="button" class="btn btn-danger btn-sm" onclick="confirmReset()">
                                <i class="fa fa-refresh"></i> <?php echo xlt('Reset Settings'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function confirmCleanup() {
            if (confirm('<?php echo xla('Are you sure you want to cleanup old audit records? This action cannot be undone.'); ?>')) {
                // Add cleanup functionality here
                alert('<?php echo xla('Cleanup functionality will be implemented in the next phase.'); ?>');
            }
        }
        
        function backupAuditLog() {
            if (confirm('<?php echo xla('Create a backup of all audit records?'); ?>')) {
                // Add backup functionality here
                alert('<?php echo xla('Backup functionality will be implemented in the next phase.'); ?>');
            }
        }
        
        function confirmReset() {
            if (confirm('<?php echo xla('Are you sure you want to reset all audit settings to default values?'); ?>')) {
                // Add reset functionality here
                alert('<?php echo xla('Reset functionality will be implemented in the next phase.'); ?>');
            }
        }
        
        $(document).ready(function() {
            // Show/hide help text based on log level
            $('#audit_log_level').change(function() {
                var level = $(this).val();
                if (level === 'minimal') {
                    alert('<?php echo xla('Minimal logging will only track basic inventory movements without user attribution or cost tracking.'); ?>');
                }
            });
        });
    </script>
</body>
</html> 