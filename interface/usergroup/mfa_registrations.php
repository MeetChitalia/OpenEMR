<?php

/**
 * Multi-Factor Authentication Management
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2018 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2018-2019 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE CNU General Public License 3
 */

require_once("../globals.php");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/classes/Totp.class.php");

use OpenEMR\Common\Auth\AuthUtils;
use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\OeUI\OemrUI;

$userid = $_SESSION['authUserID'];
$user_name = getUserIDInfo($userid);
$user_full_name = $user_name['fname'] . " " . $user_name['lname'];
$message = '';
$message_type = '';

function mfaRegistrationsGetTotpSecret(int $userid)
{
    return privQuery(
        "SELECT var1 FROM login_mfa_registrations WHERE `user_id` = ? AND `method` = 'TOTP'",
        array($userid)
    );
}

// Handle form submissions
if (!empty($_POST)) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }
    
    // Handle delete
    if (!empty($_POST['form_delete_method'])) {
        sqlStatement(
            "DELETE FROM login_mfa_registrations WHERE user_id = ? AND method = ? AND name = ?",
            array($userid, $_POST['form_delete_method'], $_POST['form_delete_name'])
        );
        $message = xl('Authentication method deleted successfully.');
        $message_type = 'success';
    }
    
    // Handle TOTP setup
    if (!empty($_POST['setup_totp'])) {
        // Verify password first
        if (!(new AuthUtils())->confirmPassword($_SESSION['authUser'], $_POST['clearPass'])) {
            $message = xl('Invalid password. Please try again.');
            $message_type = 'danger';
        } else {
            // Check if TOTP already exists
            $existingSecret = mfaRegistrationsGetTotpSecret((int) $userid);
            
            if (!empty($existingSecret['var1'])) {
                $message = xl('TOTP authentication is already set up for this user.');
                $message_type = 'warning';
            } else {
                // Generate new TOTP secret
                $cryptoGen = new CryptoGen();
                $secret = $cryptoGen->generateRandomString(32);
                $encryptedSecret = $cryptoGen->encryptStandard($secret);
                
                // Save to database
                sqlStatement(
                    "INSERT INTO login_mfa_registrations (user_id, method, name, var1) VALUES (?, 'TOTP', ?, ?)",
                    array($userid, xl('TOTP Key'), $encryptedSecret)
                );
                
                $message = xl('TOTP authentication set up successfully. Please scan the QR code below with your authenticator app.');
                $message_type = 'success';
            }
        }
    }
}

// Get current MFA registrations
$res = sqlStatement("SELECT name, method FROM login_mfa_registrations WHERE user_id = ? ORDER BY method, name", array($userid));
$has_totp = false;
$totp_name = '';
if (sqlNumRows($res)) {
    while ($row = sqlFetchArray($res)) {
        if ($row['method'] == "TOTP") {
            $has_totp = true;
            $totp_name = $row['name'];
        }
    }
}

// Generate QR code if TOTP exists
$qr_code = '';
if ($has_totp) {
    try {
        $existingSecret = mfaRegistrationsGetTotpSecret((int) $userid);
        if (!empty($existingSecret['var1'])) {
            $cryptoGen = new CryptoGen();
            $secret = $cryptoGen->decryptStandard($existingSecret['var1']);
            if (empty($secret)) {
                throw new RuntimeException(xl('Stored TOTP secret could not be decrypted.'));
            }

            $googleAuth = new Totp($secret, $_SESSION['authUser']);
            $qr_code = $googleAuth->generateQrCode();
            if (empty($qr_code)) {
                throw new RuntimeException(xl('QR code could not be generated.'));
            }
        }
    } catch (Throwable $e) {
        error_log('mfa_registrations.php QR generation failed: ' . errorLogEscape($e->getMessage()));
        $qr_code = '';
        $message = xl('MFA is active, but the QR code could not be loaded. You can remove and re-set up TOTP if needed.');
        $message_type = 'warning';
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <title><?php echo xlt('Multi-Factor Authentication'); ?></title>
    <style>
        .mfa-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 25px;
            border-radius: 8px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .page-header h1 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .page-header .header-icon {
            font-size: 1.8rem;
            opacity: 0.9;
        }
        
        .mfa-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            overflow: hidden;
        }
        
        .mfa-card-header {
            background: #f8f9fa;
            padding: 20px 30px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .mfa-card-header h3 {
            margin: 0;
            color: #495057;
            font-weight: 600;
        }
        
        .mfa-card-body {
            padding: 30px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 25px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .status-inactive {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .qr-section {
            text-align: center;
            padding: 30px;
            background: #f8f9fa;
            border-radius: 10px;
            margin: 20px 0;
        }
        
        .qr-code {
            max-width: 250px;
            border: 3px solid #fff;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .setup-form {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 10px;
            margin-top: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid transparent;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }
        
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-color: #ffeaa7;
        }
        
        .info-box {
            background: #e3f2fd;
            border: 1px solid #bbdefb;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .info-box h4 {
            color: #1976d2;
            margin-top: 0;
        }
        
        .app-list {
            list-style: none;
            padding: 0;
            margin: 20px 0;
        }
        
        .app-list li {
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .app-list li:last-child {
            border-bottom: none;
        }
        
        .app-list a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        
        .app-list a:hover {
            text-decoration: underline;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        @media (max-width: 768px) {
            .mfa-container {
                padding: 10px;
            }
            
            .mfa-header h1 {
                font-size: 2rem;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: stretch;
            }
            
            .btn {
                width: 100%;
                margin-bottom: 10px;
            }
        }
    </style>
</head>
<body class="body_top">
    <div class="mfa-container">
        <!-- Header -->
        <div class="page-header">
            <i class="fa fa-shield-alt header-icon"></i>
            <h1><?php echo xlt('Multi-Factor Authentication'); ?></h1>
        </div>

        <!-- Success/Error Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fa fa-<?php echo $message_type == 'success' ? 'check-circle' : ($message_type == 'danger' ? 'exclamation-triangle' : 'info-circle'); ?>"></i>
                <?php echo text($message); ?>
            </div>
        <?php endif; ?>

        <!-- Current Status Card -->
        <div class="mfa-card">
            <div class="mfa-card-header">
                <h3><i class="fa fa-user-shield"></i> <?php echo xlt('Current Authentication Status'); ?></h3>
            </div>
            <div class="mfa-card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h4><?php echo xlt('User'); ?></h4>
                        <p class="lead"><?php echo text($user_full_name); ?></p>
                    </div>
                    <div class="col-md-6">
                        <h4><?php echo xlt('Status'); ?></h4>
                        <?php if ($has_totp): ?>
                            <span class="status-badge status-active">
                                <i class="fa fa-check-circle"></i> <?php echo xlt('TOTP Active'); ?>
                            </span>
                        <?php else: ?>
                            <span class="status-badge status-inactive">
                                <i class="fa fa-exclamation-triangle"></i> <?php echo xlt('No MFA Configured'); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($has_totp): ?>
            <!-- TOTP QR Code Card -->
            <div class="mfa-card">
                <div class="mfa-card-header">
                    <h3><i class="fa fa-qrcode"></i> <?php echo xlt('TOTP QR Code'); ?></h3>
                </div>
                <div class="mfa-card-body">
                    <div class="qr-section">
                        <h4><?php echo xlt('Scan this QR code with your authenticator app'); ?></h4>
                        <img src="<?php echo attr($qr_code); ?>" alt="TOTP QR Code" class="qr-code">
                        <p class="mt-3"><small><?php echo xlt('If you need to set up a new device, scan this QR code'); ?></small></p>
                    </div>
                    
                    <div class="info-box">
                        <h4><i class="fa fa-info-circle"></i> <?php echo xlt('Recommended Authenticator Apps'); ?></h4>
                        <ul class="app-list">
                            <li>
                                <strong><?php echo xlt('Google Authenticator'); ?></strong>
                                (<a href="https://itunes.apple.com/us/app/google-authenticator/id388497605?mt=8" target="_blank"><?php echo xlt('iOS'); ?></a>,
                                <a href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2&hl=en" target="_blank"><?php echo xlt('Android'); ?></a>)
                            </li>
                            <li>
                                <strong><?php echo xlt('Authy'); ?></strong>
                                (<a href="https://itunes.apple.com/us/app/authy/id494168017?mt=8" target="_blank"><?php echo xlt('iOS'); ?></a>,
                                <a href="https://play.google.com/store/apps/details?id=com.authy.authy&hl=en" target="_blank"><?php echo xlt('Android'); ?></a>)
                            </li>
                            <li>
                                <strong><?php echo xlt('Microsoft Authenticator'); ?></strong>
                                (<a href="https://apps.apple.com/us/app/microsoft-authenticator/id983156458" target="_blank"><?php echo xlt('iOS'); ?></a>,
                                <a href="https://play.google.com/store/apps/details?id=com.azure.authenticator" target="_blank"><?php echo xlt('Android'); ?></a>)
                            </li>
                        </ul>
                    </div>
                    
                    <div class="action-buttons">
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                            <input type="hidden" name="form_delete_method" value="TOTP" />
                            <input type="hidden" name="form_delete_name" value="<?php echo attr($totp_name); ?>" />
                            <button type="submit" class="btn btn-danger" onclick="return confirm('<?php echo xla('Are you sure you want to remove TOTP authentication?'); ?>')">
                                <i class="fa fa-trash"></i> <?php echo xlt('Remove TOTP'); ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Setup TOTP Card -->
            <div class="mfa-card">
                <div class="mfa-card-header">
                    <h3><i class="fa fa-plus-circle"></i> <?php echo xlt('Set Up TOTP Authentication'); ?></h3>
                </div>
                <div class="mfa-card-body">
                    <div class="info-box">
                        <h4><i class="fa fa-shield-alt"></i> <?php echo xlt('Why Use Two-Factor Authentication?'); ?></h4>
                        <p><?php echo xlt('Two-factor authentication adds an extra layer of security to your account. Even if someone gets your password, they won\'t be able to access your account without your authenticator app.'); ?></p>
                    </div>
                    
                    <form method="post" class="setup-form">
                        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                        <input type="hidden" name="setup_totp" value="1" />
                        
                        <div class="form-group">
                            <label for="clearPass" class="form-label">
                                <i class="fa fa-lock"></i> <?php echo xlt('Enter your current password to continue'); ?>
                            </label>
                            <input type="password" class="form-control" id="clearPass" name="clearPass" required>
                            <small class="form-text text-muted"><?php echo xlt('This ensures only you can set up MFA for your account'); ?></small>
                        </div>
                        
                        <div class="action-buttons">
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-shield-alt"></i> <?php echo xlt('Set Up TOTP'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Focus on password field when page loads
        $(document).ready(function() {
            $('#clearPass').focus();
        });
    </script>
</body>
</html>
