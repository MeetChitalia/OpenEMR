<?php

/**
 * Password Setup Page for New Users
 * 
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Bypass authentication for password setup
$ignoreAuth = true;
$ignoreAuth_onsite_portal = true;
$skipSessionExpirationCheck = true;
$sessionAllowWrite = true;

// Debug: Log that we're entering password setup
error_log("Password setup: Starting password setup process");

require_once("../globals.php");

use OpenEMR\Common\Auth\AuthUtils;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$error_message = '';
$success_message = '';
$token = '';
$temp_data = null;

// Check if token is provided
if (!isset($_GET['token']) || empty($_GET['token'])) {
    $error_message = xl('Invalid or missing setup token.');
} else {
    $token = urldecode($_GET['token']); // Decode the URL-encoded token
    
    // Debug: Log the token we're looking for
    error_log("Password setup: Looking for token: " . $token);
    
    // Verify the token and get user info
    $result = sqlStatement(
        "SELECT tp.*, u.username, u.fname, u.lname FROM temp_passwords tp 
         JOIN users u ON tp.user_id = u.id 
         WHERE tp.temp_password = '" . add_escape_custom($token) . "' AND tp.used = 0 AND tp.expires_at > NOW()"
    );
    $temp_data = sqlFetchArray($result);
    
    if (!$temp_data) {
        $error_message = xl('Invalid or expired setup token.');
    } else {
        error_log("Password setup: Found valid token for user: " . $temp_data['username']);
    }
}

// Handle form submission
if ($_POST && isset($_POST['setup_password']) && !$error_message) {
    // For password setup, we validate using the temporary password token instead of CSRF
    // This is secure because the token is unique and time-limited
    $form_token = $_POST['temp_token'] ?? '';
    $temp_password_input = $_POST['temp_password'] ?? '';
    
    error_log("Password setup: Form submission - Token: $token, Form token: $form_token, Temp password: $temp_password_input");
    
    if (empty($temp_password_input) || $temp_password_input !== $token || $form_token !== $token) {
        $error_message = xl('Invalid temporary password or security validation failed.');
        error_log("Password setup: Validation failed - Token mismatch or empty password");
    }
    
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate new password
    if (empty($new_password)) {
        $error_message = xl('New password is required.');
    } elseif (strlen($new_password) < 8) {
        $error_message = xl('Password must be at least 8 characters long.');
    } elseif ($new_password !== $confirm_password) {
        $error_message = xl('Passwords do not match.');
    } else {
        // Update the user's password using OpenEMR's proper hashing method
        $authHash = new \OpenEMR\Common\Auth\AuthHash();
        $hashed_password = $authHash->passwordHash($new_password);
        
        // First, check if user exists in users_secure table
        $user_secure_check = sqlStatement(
            "SELECT id FROM users_secure WHERE id = ?",
            array($temp_data['user_id'])
        );
        $user_secure_exists = sqlFetchArray($user_secure_check);
        
        if ($user_secure_exists) {
            // Update existing record in users_secure
            $update_result = sqlStatement(
                "UPDATE users_secure SET password = ?, last_update_password = NOW() WHERE id = ?",
                array($hashed_password, $temp_data['user_id'])
            );
        } else {
            // Insert new record in users_secure
            $update_result = sqlStatement(
                "INSERT INTO users_secure (id, username, password, last_update_password) VALUES (?, ?, ?, NOW())",
                array($temp_data['user_id'], $temp_data['username'], $hashed_password)
            );
        }
        
        $success = ($update_result !== false);
        
        if ($success) {
            // Mark temp password as used
            sqlStatement(
                "UPDATE temp_passwords SET used = 1 WHERE id = ?",
                array($temp_data['id'])
            );
            
            // Reset login failed counter so user can log in again
            sqlStatement(
                "UPDATE users_secure SET login_fail_counter = 0 WHERE id = ?",
                array($temp_data['user_id'])
            );
            
            $success_message = xl('Password set successfully! You can now log in with your new password.');
        } else {
            $error_message = xl('Failed to update password. Please try again.');
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Set Your Password'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .setup-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            padding: 2rem;
            width: 100%;
            max-width: 450px;
            margin: 1rem;
        }
        .setup-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .setup-header h1 {
            color: #2c3e50;
            margin-bottom: 0.5rem;
            font-size: 1.8rem;
        }
        .setup-header p {
            color: #7f8c8d;
            margin: 0;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #2c3e50;
            font-weight: 500;
        }
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e8ed;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }
        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            width: 100%;
            transition: transform 0.2s ease;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
        }
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        .alert-danger {
            background: #fee;
            border: 1px solid #fcc;
            color: #c33;
        }
        .alert-success {
            background: #efe;
            border: 1px solid #cfc;
            color: #3c3;
        }
        .login-link {
            text-align: center;
            margin-top: 1.5rem;
        }
        .login-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        .login-link a:hover {
            text-decoration: underline;
        }
        .password-requirements {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            color: #6c757d;
        }
        .password-requirements h4 {
            margin: 0 0 0.5rem 0;
            color: #495057;
        }
        .password-requirements ul {
            margin: 0;
            padding-left: 1.2rem;
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-header">
            <h1><?php echo xlt('Set Your Password'); ?></h1>
            <?php if (!$error_message && $temp_data): ?>
                <p><?php echo xlt('Welcome') . ', ' . text($temp_data['fname'] . ' ' . $temp_data['lname']); ?></p>
            <?php endif; ?>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-danger">
                <?php echo text($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <?php echo text($success_message); ?>
            </div>
            <div class="login-link">
                <a href="../login/login.php"><?php echo xlt('Go to Login Page'); ?></a>
            </div>
        <?php elseif (!$error_message && $temp_data): ?>
            <div class="password-requirements">
                <h4><?php echo xlt('Password Requirements'); ?></h4>
                <ul>
                    <li><?php echo xlt('At least 8 characters long'); ?></li>
                    <li><?php echo xlt('Should contain uppercase and lowercase letters'); ?></li>
                    <li><?php echo xlt('Should contain numbers and special characters'); ?></li>
                </ul>
            </div>

            <form method="post" action="">
                <input type="hidden" name="temp_token" value="<?php echo attr($token); ?>" />
                
                <div class="form-group">
                    <label for="temp_password"><?php echo xlt('Temporary Password'); ?></label>
                    <input type="text" id="temp_password" name="temp_password" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="new_password"><?php echo xlt('New Password'); ?></label>
                    <input type="password" id="new_password" name="new_password" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password"><?php echo xlt('Confirm New Password'); ?></label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                </div>
                
                <button type="submit" name="setup_password" class="btn-primary">
                    <?php echo xlt('Set Password'); ?>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <script>
        // Password confirmation validation
        const confirmPasswordField = document.getElementById('confirm_password');
        if (confirmPasswordField) {
            confirmPasswordField.addEventListener('input', function() {
                const newPassword = document.getElementById('new_password').value;
                const confirmPassword = this.value;
                
                if (newPassword !== confirmPassword) {
                    this.setCustomValidity('<?php echo xlt("Passwords do not match"); ?>');
                } else {
                    this.setCustomValidity('');
                }
            });
        }
    </script>
</body>
</html> 