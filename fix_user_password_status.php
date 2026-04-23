<?php
require_once("interface/globals.php");

if ($_POST['action'] == 'fix_single') {
    $userId = $_POST['user_id'] ?? 0;
    
    if ($userId) {
        // Check if user has 'NoLongerUsed' password
        $user = sqlQuery("SELECT username, password FROM users WHERE id = '" . add_escape_custom($userId) . "'");
        
        if ($user && $user['password'] == 'NoLongerUsed') {
            $result = sqlStatement("UPDATE users SET needs_password_set = 1 WHERE id = ?", array($userId));
            if ($result) {
                echo "Successfully fixed password status for user: " . $user['username'];
            } else {
                echo "Failed to fix password status for user: " . $user['username'];
            }
        } else {
            echo "User not found or doesn't have 'NoLongerUsed' password";
        }
    } else {
        echo "Invalid user ID";
    }
    
} elseif ($_POST['action'] == 'fix_all') {
    // Fix all users with 'NoLongerUsed' password
    $result = sqlStatement("UPDATE users SET needs_password_set = 1 WHERE password = 'NoLongerUsed' AND needs_password_set = 0");
    
    if ($result) {
        // Get count of affected rows
        $countResult = sqlQuery("SELECT COUNT(*) as count FROM users WHERE password = 'NoLongerUsed' AND needs_password_set = 1");
        $count = $countResult['count'] ?? 0;
        echo "Successfully fixed password status for " . $count . " users";
    } else {
        echo "Failed to fix password status for users";
    }
    
} else {
    echo "Invalid action";
}
?> 