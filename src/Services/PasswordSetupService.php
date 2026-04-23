<?php

/**
 * Password Setup Service
 * Handles self-service password setup for new users
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Your Name <your.email@example.com>
 * @copyright Copyright (c) 2024 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services;

use OpenEMR\Common\Crypto\CryptoGen;

class PasswordSetupService
{
    private $cryptoGen;
    
    public function __construct()
    {
        $this->cryptoGen = new CryptoGen();
    }
    
    /**
     * Generate a secure random token
     */
    private function generateToken()
    {
        return bin2hex(random_bytes(16)); // Generate 32-character random string
    }
    
    /**
     * Generate a temporary password
     */
    private function generateTempPassword()
    {
        return bin2hex(random_bytes(6)); // Generate 12-character random string
    }
    
    /**
     * Get the proper server URL for password setup links
     */
    private function getServerUrl()
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $port = '';
        
        // Add port if it's not the default port
        if (($protocol === 'http' && $_SERVER['SERVER_PORT'] != 80) || 
            ($protocol === 'https' && $_SERVER['SERVER_PORT'] != 443)) {
            $port = ':' . $_SERVER['SERVER_PORT'];
        }
        
        return $protocol . '://' . $host . $port . $GLOBALS['webroot'];
    }

    /**
     * Get password setup URL with site ID
     */
    private function getPasswordSetupUrl($token)
    {
        $site_id = $_SESSION['site_id'] ?? 'default';
        return $this->getServerUrl() . '/interface/login/password_setup.php?site=' . urlencode($site_id) . '&token=' . urlencode($token);
    }
    
    /**
     * Create a password setup token for a new user
     */
    public function createPasswordSetupToken($userId, $username, $email)
    {
        $tempPassword = $this->generateTempPassword();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+72 hours'));
        
        $sql = "INSERT INTO temp_passwords 
                (user_id, username, temp_password, email, expires_at) 
                VALUES (?, ?, ?, ?, ?)";
        
        $result = sqlStatement($sql, array(
            $userId,
            $username,
            $tempPassword,
            $email,
            $expiresAt
        ));
        
        if ($result) {
            error_log("Password setup token created successfully for user: $username");
            return array(
                'token' => $tempPassword,
                'temp_password' => $tempPassword,
                'expires_at' => $expiresAt
            );
        } else {
            error_log("Failed to create password setup token for user: $username");
            return false;
        }
    }
    
    /**
     * Validate a password setup token
     */
    public function validateToken($token)
    {
        $sql = "SELECT * FROM password_setup_tokens 
                WHERE token = ? AND used = 0 AND expires_at > NOW()";
        
        $result = sqlQuery($sql, array($token));
        
        if ($result && is_array($result)) {
            return $result;
        }
        
        return false;
    }
    
    /**
     * Mark token as used
     */
    public function markTokenAsUsed($token)
    {
        $sql = "UPDATE password_setup_tokens SET used = 1 WHERE token = ?";
        return sqlStatement($sql, array($token));
    }
    
    /**
     * Set user must_set_password flag
     */
    public function setUserMustSetPassword($userId, $value = true)
    {
        $sql = "UPDATE users SET must_set_password = ? WHERE id = ?";
        return sqlStatement($sql, array($value ? 1 : 0, $userId));
    }
    
    /**
     * Send password setup email
     */
    public function sendPasswordSetupEmail($email, $username, $tempPassword, $token)
    {
        $setupUrl = $this->getPasswordSetupUrl($token);
        
        $subject = xl('Welcome to') . ' ' . $GLOBALS['practice_name'] . ' - ' . xl('Set Your Password');
        
        $message = $this->getEmailTemplate($username, $tempPassword, $setupUrl);
        
        // Use OpenEMR's email function
        return mail($email, $subject, $message, $this->getEmailHeaders());
    }
    
    /**
     * Get email template with custom branding
     */
    private function getEmailTemplate($username, $tempPassword, $setupUrl)
    {
        $practiceName = $GLOBALS['practice_name'] ?? 'OpenEMR';
        $practiceAddress = $GLOBALS['practice_address'] ?? '';
        $practicePhone = $GLOBALS['practice_phone'] ?? '';
        
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #4A90E2; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
                .button { display: inline-block; background: #4A90E2; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; margin: 20px 0; }
                .warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 6px; margin: 20px 0; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>" . text($practiceName) . "</h1>
                    <p>" . xl('Your Account Setup') . "</p>
                </div>
                
                <div class='content'>
                    <h2>" . xl('Welcome') . " " . text($username) . "!</h2>
                    
                    <p>" . xl('Your account has been created successfully. To complete your setup, you need to set your password.') . "</p>
                    
                    <h3>" . xl('Your Temporary Login Credentials:') . "</h3>
                    <p><strong>" . xl('Username') . ":</strong> " . text($username) . "</p>
                    <p><strong>" . xl('Temporary Password') . ":</strong> <code>" . text($tempPassword) . "</code></p>
                    
                    <div class='warning'>
                        <strong>" . xl('Important:') . "</strong> " . xl('This temporary password will expire in 72 hours. Please set your new password as soon as possible.') . "
                    </div>
                    
                    <p>" . xl('Click the button below to set your new password:') . "</p>
                    
                    <a href='" . text($setupUrl) . "' class='button'>" . xl('Set My Password') . "</a>
                    
                    <p>" . xl('Or copy and paste this link into your browser:') . "</p>
                    <p style='word-break: break-all;'>" . text($setupUrl) . "</p>
                    
                    <p>" . xl('If you did not request this account, please contact your administrator immediately.') . "</p>
                </div>
                
                <div class='footer'>
                    <p>" . text($practiceName) . "</p>
                    " . (!empty($practiceAddress) ? "<p>" . text($practiceAddress) . "</p>" : "") . "
                    " . (!empty($practicePhone) ? "<p>" . text($practicePhone) . "</p>" : "") . "
                    <p>" . xl('This is an automated message. Please do not reply to this email.') . "</p>
                </div>
            </div>
        </body>
        </html>";
        
        return $html;
    }
    
    /**
     * Get email headers
     */
    private function getEmailHeaders()
    {
        $practiceName = $GLOBALS['practice_name'] ?? 'OpenEMR';
        $practiceEmail = $GLOBALS['practice_email'] ?? 'noreply@openemr.org';
        
        $headers = array();
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-type: text/html; charset=utf-8';
        $headers[] = 'From: ' . $practiceName . ' <' . $practiceEmail . '>';
        $headers[] = 'Reply-To: ' . $practiceEmail;
        $headers[] = 'X-Mailer: OpenEMR Password Setup';
        
        return implode("\r\n", $headers);
    }
    
    /**
     * Clean up expired tokens
     */
    public function cleanupExpiredTokens()
    {
        $sql = "DELETE FROM password_setup_tokens WHERE expires_at < NOW()";
        return sqlStatement($sql);
    }
} 