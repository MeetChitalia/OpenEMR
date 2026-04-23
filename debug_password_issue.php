<?php
require_once("interface/globals.php");

echo "<h2>Password Setup Debug</h2>";

// Check if AuthHash class exists and works
echo "<h3>1. AuthHash Class Test:</h3>";
try {
    $authHash = new \OpenEMR\Common\Auth\AuthHash();
    echo "<p>✅ AuthHash class loaded successfully</p>";
    
    // Test password hashing
    $test_password = "TestPassword123!";
    $hashed = $authHash->passwordHash($test_password);
    echo "<p>✅ Password hashing works. Hash: " . substr($hashed, 0, 20) . "...</p>";
    
    // Test password verification
    $verify_result = \OpenEMR\Common\Auth\AuthHash::passwordVerify($test_password, $hashed);
    echo "<p>✅ Password verification works: " . ($verify_result ? "TRUE" : "FALSE") . "</p>";
    
} catch (Exception $e) {
    echo "<p>❌ AuthHash error: " . $e->getMessage() . "</p>";
}

// Check recent users
echo "<h3>2. Recent Users:</h3>";
$users_result = sqlStatement("SELECT id, username, fname, lname, password, LENGTH(password) as pwd_length FROM users ORDER BY id DESC LIMIT 5");

echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>ID</th><th>Username</th><th>Name</th><th>Password Status</th><th>Length</th><th>Hash Preview</th></tr>";

while ($row = sqlFetchArray($users_result)) {
    echo "<tr>";
    echo "<td>" . $row['id'] . "</td>";
    echo "<td>" . htmlspecialchars($row['username']) . "</td>";
    echo "<td>" . htmlspecialchars($row['fname'] . ' ' . $row['lname']) . "</td>";
    
    if (empty($row['password'])) {
        echo "<td style='color: red;'>❌ Empty</td>";
    } else {
        echo "<td style='color: green;'>✅ Set</td>";
    }
    
    echo "<td>" . $row['pwd_length'] . "</td>";
    echo "<td>" . (empty($row['password']) ? 'N/A' : substr($row['password'], 0, 20) . '...') . "</td>";
    echo "</tr>";
}
echo "</table>";

// Check temp passwords
echo "<h3>3. Temp Passwords:</h3>";
$temp_result = sqlStatement("SELECT tp.*, u.username, u.fname, u.lname FROM temp_passwords tp JOIN users u ON tp.user_id = u.id ORDER BY tp.created_at DESC");

echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>ID</th><th>User</th><th>Temp Password</th><th>Used</th><th>Expires</th><th>Status</th></tr>";

while ($row = sqlFetchArray($temp_result)) {
    $expired = strtotime($row['expires_at']) < time();
    $status = "";
    if ($row['used'] == 1) {
        $status = "✅ Used";
    } elseif ($expired) {
        $status = "❌ Expired";
    } else {
        $status = "⏳ Active";
    }
    
    echo "<tr>";
    echo "<td>" . $row['id'] . "</td>";
    echo "<td>" . htmlspecialchars($row['username']) . "</td>";
    echo "<td>" . htmlspecialchars($row['temp_password']) . "</td>";
    echo "<td>" . ($row['used'] == 1 ? "Yes" : "No") . "</td>";
    echo "<td>" . $row['expires_at'] . "</td>";
    echo "<td>" . $status . "</td>";
    echo "</tr>";
}
echo "</table>";

// Test login simulation
echo "<h3>4. Login Test Simulation:</h3>";
$test_username = "Meet"; // Change this to the username you're testing
$test_user = sqlStatement("SELECT id, username, password FROM users WHERE username = '" . add_escape_custom($test_username) . "'");
$user_data = sqlFetchArray($test_user);

if ($user_data) {
    echo "<p>Found user: " . htmlspecialchars($user_data['username']) . " (ID: " . $user_data['id'] . ")</p>";
    
    if (empty($user_data['password'])) {
        echo "<p>❌ User has no password set</p>";
    } else {
        echo "<p>✅ User has password set (length: " . strlen($user_data['password']) . ")</p>";
        echo "<p>Hash starts with: " . substr($user_data['password'], 0, 20) . "...</p>";
        
        // Test if it's a valid OpenEMR hash
        if (strpos($user_data['password'], '$2y$') === 0) {
            echo "<p>✅ Hash format looks like bcrypt (OpenEMR standard)</p>";
        } elseif (strpos($user_data['password'], '$6$') === 0) {
            echo "<p>✅ Hash format looks like SHA512 (OpenEMR alternative)</p>";
        } elseif (strpos($user_data['password'], '$argon2') === 0) {
            echo "<p>✅ Hash format looks like Argon2 (OpenEMR alternative)</p>";
        } else {
            echo "<p>❌ Hash format doesn't match OpenEMR standards</p>";
        }
    }
} else {
    echo "<p>❌ User not found: " . htmlspecialchars($test_username) . "</p>";
}

// Check OpenEMR settings
echo "<h3>5. OpenEMR Hash Settings:</h3>";
echo "<p>Hash Algorithm: " . ($GLOBALS['gbl_auth_hash_algo'] ?? 'Not set') . "</p>";
echo "<p>Bcrypt Cost: " . ($GLOBALS['gbl_auth_bcrypt_hash_cost'] ?? 'Default') . "</p>";

// Test a simple password update
echo "<h3>6. Test Password Update:</h3>";
if (isset($_GET['test_update']) && $user_data) {
    $test_new_password = "TestPassword123!";
    $authHash = new \OpenEMR\Common\Auth\AuthHash();
    $new_hash = $authHash->passwordHash($test_new_password);
    
    $update_result = sqlStatement(
        "UPDATE users SET password = ? WHERE id = ?",
        array($new_hash, $user_data['id'])
    );
    
    if ($update_result !== false) {
        echo "<p>✅ Test password update successful</p>";
        echo "<p>New hash: " . substr($new_hash, 0, 20) . "...</p>";
        
        // Verify it works
        $verify = \OpenEMR\Common\Auth\AuthHash::passwordVerify($test_new_password, $new_hash);
        echo "<p>Verification test: " . ($verify ? "✅ PASS" : "❌ FAIL") . "</p>";
    } else {
        echo "<p>❌ Test password update failed</p>";
    }
} else {
    echo "<p><a href='?test_update=1'>Click here to test password update</a></p>";
}

echo "<h3>7. Next Steps:</h3>";
echo "<p>1. Check if the user has a password set in the table above</p>";
echo "<p>2. If no password, try creating a new user and setting password</p>";
echo "<p>3. If password exists but login fails, check the hash format</p>";
echo "<p>4. Try the test password update above</p>";
?> 