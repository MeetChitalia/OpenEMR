<?php
require_once("interface/globals.php");

echo "<h2>User Password Status Check</h2>";

// Check recent users and their password status
$result = sqlStatement("SELECT id, username, fname, lname, password, LENGTH(password) as pwd_length FROM users ORDER BY id DESC LIMIT 10");

echo "<h3>Recent Users (Last 10):</h3>";
echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>ID</th><th>Username</th><th>First Name</th><th>Last Name</th><th>Password Status</th><th>Password Length</th></tr>";

while ($row = sqlFetchArray($result)) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['id']) . "</td>";
    echo "<td>" . htmlspecialchars($row['username']) . "</td>";
    echo "<td>" . htmlspecialchars($row['fname']) . "</td>";
    echo "<td>" . htmlspecialchars($row['lname']) . "</td>";
    
    if (empty($row['password'])) {
        echo "<td style='color: red;'>❌ No Password Set</td>";
    } else {
        echo "<td style='color: green;'>✅ Password Set</td>";
    }
    
    echo "<td>" . $row['pwd_length'] . "</td>";
    echo "</tr>";
}

echo "</table>";

// Check temp_passwords table
echo "<h3>Temp Passwords Status:</h3>";
$temp_result = sqlStatement("SELECT tp.*, u.username, u.fname, u.lname FROM temp_passwords tp JOIN users u ON tp.user_id = u.id ORDER BY tp.created_at DESC");

echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>ID</th><th>User</th><th>Temp Password</th><th>Used</th><th>Expires At</th><th>Created At</th></tr>";

while ($row = sqlFetchArray($temp_result)) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['id']) . "</td>";
    echo "<td>" . htmlspecialchars($row['username']) . " (" . htmlspecialchars($row['fname'] . ' ' . $row['lname']) . ")</td>";
    echo "<td>" . htmlspecialchars($row['temp_password']) . "</td>";
    echo "<td>" . ($row['used'] == 1 ? "✅ Used" : "❌ Unused") . "</td>";
    echo "<td>" . htmlspecialchars($row['expires_at']) . "</td>";
    echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
    echo "</tr>";
}

echo "</table>";

// Check specific user if provided
if (isset($_GET['username'])) {
    $username = $_GET['username'];
    echo "<h3>Specific User Check for: " . htmlspecialchars($username) . "</h3>";
    
    $user_check = sqlStatement("SELECT id, username, fname, lname, password, LENGTH(password) as pwd_length FROM users WHERE username = '" . add_escape_custom($username) . "'");
    $user_data = sqlFetchArray($user_check);
    
    if ($user_data) {
        echo "<p><strong>User ID:</strong> " . $user_data['id'] . "</p>";
        echo "<p><strong>Username:</strong> " . htmlspecialchars($user_data['username']) . "</p>";
        echo "<p><strong>Name:</strong> " . htmlspecialchars($user_data['fname'] . ' ' . $user_data['lname']) . "</p>";
        echo "<p><strong>Password Status:</strong> " . (empty($user_data['password']) ? "❌ No Password Set" : "✅ Password Set") . "</p>";
        echo "<p><strong>Password Length:</strong> " . $user_data['pwd_length'] . "</p>";
        
        if (!empty($user_data['password'])) {
            echo "<p><strong>Password Hash:</strong> " . substr($user_data['password'], 0, 20) . "...</p>";
        }
    } else {
        echo "<p>❌ User not found</p>";
    }
}

echo "<h3>Test Login:</h3>";
echo "<p>To test if a user can login, try logging in with their username and the password they set.</p>";
echo "<p>If login fails, the password might not be stored correctly or there might be an issue with the password hashing.</p>";
?> 