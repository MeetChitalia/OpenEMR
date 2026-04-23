<?php
/**
 * Resend Password Setup Emails
 * This script will resend password setup emails for users who didn't receive them
 */

// Include OpenEMR globals
require_once(dirname(__FILE__) . "/globals.php");

// Get users with temp passwords that haven't been used
$query = "SELECT tp.*, u.fname, u.lname, u.email 
          FROM temp_passwords tp 
          JOIN users u ON tp.user_id = u.id 
          WHERE tp.used = 0 AND tp.expires_at > NOW()";

$result = sqlStatement($query);

echo "<h2>Resending Password Setup Emails</h2>";

while ($row = sqlFetchArray($result)) {
    $user_id = $row['user_id'];
    $username = $row['username'];
    $temp_password = $row['temp_password'];
    $email = $row['email'];
    $fname = $row['fname'];
    $lname = $row['lname'];
    
    if (empty($email)) {
        echo "<p>❌ User $fname $lname ($username) - No email address</p>";
        continue;
    }
    
    // Create password setup link
    $setup_link = $GLOBALS['webroot'] . "/interface/login/password_reset.php?token=" . $temp_password;
    
    // Email subject and body
    $subject = "OpenEMR - Password Setup Required";
    $message = "
    <html>
    <head><title>Password Setup</title></head>
    <body>
        <h2>Welcome to OpenEMR!</h2>
        <p>Hello $fname $lname,</p>
        <p>Your account has been created successfully. Please set up your password by clicking the link below:</p>
        <p><a href='$setup_link' style='background-color: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Set Up Password</a></p>
        <p>Or copy and paste this link into your browser:</p>
        <p>$setup_link</p>
        <p>This link will expire in 3 days.</p>
        <p>If you didn't request this account, please contact your administrator.</p>
    </body>
    </html>
    ";
    
    // Send email
    $mail_result = mail($email, $subject, $message, [
        'From: ' . $GLOBALS['practice_return_email_path'],
        'Reply-To: ' . $GLOBALS['practice_return_email_path'],
        'Content-Type: text/html; charset=UTF-8'
    ]);
    
    if ($mail_result) {
        echo "<p>✅ Email sent successfully to $fname $lname ($email)</p>";
    } else {
        echo "<p>❌ Failed to send email to $fname $lname ($email)</p>";
    }
}

echo "<h3>Manual Links (if emails fail):</h3>";
$manual_result = sqlStatement($query);
while ($row = sqlFetchArray($manual_result)) {
    $setup_link = $GLOBALS['webroot'] . "/interface/login/password_reset.php?token=" . $row['temp_password'];
    echo "<p><strong>" . $row['fname'] . " " . $row['lname'] . ":</strong> <a href='$setup_link' target='_blank'>$setup_link</a></p>";
}
?>
