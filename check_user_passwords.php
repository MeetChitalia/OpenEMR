<?php
require_once("interface/globals.php");

echo "<h2>User Password Status Check</h2>\n";

try {
    // Check if needs_password_set column exists
    $columnExists = sqlQuery("SHOW COLUMNS FROM users LIKE 'needs_password_set'");
    if (!$columnExists) {
        echo "<p style='color: red;'>✗ needs_password_set column does not exist!</p>\n";
        echo "<p>Attempting to add it...</p>\n";
        $result = sqlStatement("ALTER TABLE users ADD COLUMN needs_password_set TINYINT(1) DEFAULT 0");
        if ($result) {
            echo "<p style='color: green;'>✓ Successfully added needs_password_set column</p>\n";
        } else {
            echo "<p style='color: red;'>✗ Failed to add needs_password_set column</p>\n";
            exit;
        }
    } else {
        echo "<p style='color: green;'>✓ needs_password_set column exists</p>\n";
    }
    
    // Get all users
    $result = sqlStatement("SELECT id, username, fname, lname, google_signin_email, needs_password_set, password FROM users WHERE username != '' ORDER BY username");
    
    echo "<h3>Current User Status:</h3>\n";
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>\n";
    echo "<tr><th>Username</th><th>Name</th><th>Email</th><th>Password</th><th>needs_password_set</th><th>Status</th><th>Action</th></tr>\n";
    
    while ($row = sqlFetchArray($result)) {
        $passwordStatus = ($row['password'] == 'NoLongerUsed') ? 'No Password' : 'Has Password';
        $needsSetup = $row['needs_password_set'] ? 'Yes' : 'No';
        $status = ($row['needs_password_set']) ? 'Needs Setup' : 'Set';
        
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['username']) . "</td>";
        echo "<td>" . htmlspecialchars($row['fname'] . ' ' . $row['lname']) . "</td>";
        echo "<td>" . htmlspecialchars($row['google_signin_email']) . "</td>";
        echo "<td>" . htmlspecialchars($passwordStatus) . "</td>";
        echo "<td>" . htmlspecialchars($needsSetup) . "</td>";
        echo "<td>" . htmlspecialchars($status) . "</td>";
        echo "<td>";
        
        if ($row['password'] == 'NoLongerUsed' && !$row['needs_password_set']) {
            echo "<button onclick='fixUser(" . $row['id'] . ")'>Fix Status</button>";
        } elseif ($row['needs_password_set']) {
            echo "<span style='color: orange;'>Needs Password Setup</span>";
        } else {
            echo "<span style='color: green;'>OK</span>";
        }
        
        echo "</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";
    
    echo "<h3>Actions:</h3>\n";
    echo "<p><button onclick='fixAllUsers()'>Fix All Users (Set needs_password_set = 1 for users with 'NoLongerUsed' password)</button></p>\n";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>\n";
}
?>

<script>
function fixUser(userId) {
    if (confirm('Fix the password status for this user?')) {
        fetch('fix_user_password_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'user_id=' + userId + '&action=fix_single'
        })
        .then(response => response.text())
        .then(data => {
            alert(data);
            location.reload();
        })
        .catch(error => {
            alert('Error: ' + error);
        });
    }
}

function fixAllUsers() {
    if (confirm('Fix the password status for all users with "NoLongerUsed" password?')) {
        fetch('fix_user_password_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=fix_all'
        })
        .then(response => response.text())
        .then(data => {
            alert(data);
            location.reload();
        })
        .catch(error => {
            alert('Error: ' + error);
        });
    }
}
</script> 