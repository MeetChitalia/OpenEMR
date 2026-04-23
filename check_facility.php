<?php
/**
 * Check Current User Facility
 * Simple test to check which facility the current user is assigned to
 */

require_once(__DIR__ . "/interface/globals.php");

// Check if user is logged in
if (!isset($_SESSION['authUserID'])) {
    die("Not logged in. Please log in first.");
}

$username = $_SESSION['authUser'] ?? 'Unknown';
$user_id = $_SESSION['authUserID'] ?? 0;

// Get user's facility information
$facility_query = "SELECT u.facility_id, f.name as facility_name 
                   FROM users u 
                   LEFT JOIN facility f ON u.facility_id = f.id 
                   WHERE u.username = ?";
$facility_result = sqlFetchArray(sqlStatement($facility_query, array($username)));

$facility_id = $facility_result ? $facility_result['facility_id'] : 'NULL';
$facility_name = $facility_result ? $facility_result['facility_name'] : 'Not assigned';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Facility Check</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .info { background: #f0f0f0; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .hoover { background: #d4edda; border: 1px solid #c3e6cb; }
        .other { background: #f8d7da; border: 1px solid #f5c6cb; }
        .button { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
    </style>
</head>
<body>
    <h1>Current User Facility Check</h1>
    
    <div class="info">
        <h3>User Information:</h3>
        <p><strong>Username:</strong> <?php echo htmlspecialchars($username); ?></p>
        <p><strong>User ID:</strong> <?php echo $user_id; ?></p>
    </div>
    
    <div class="info <?php echo ($facility_id == 36) ? 'hoover' : 'other'; ?>">
        <h3>Facility Information:</h3>
        <p><strong>Facility ID:</strong> <?php echo $facility_id; ?></p>
        <p><strong>Facility Name:</strong> <?php echo htmlspecialchars($facility_name); ?></p>
        <p><strong>Is Hoover Facility:</strong> <?php echo ($facility_id == 36) ? 'YES' : 'NO'; ?></p>
    </div>
    
    <?php if ($facility_id == 36): ?>
        <div class="info hoover">
            <h3>✅ Hoover Facility Detected!</h3>
            <p>You should see "Dispense from Marketplace" buttons in the POS system instead of regular "Dispense Complete" buttons.</p>
        </div>
    <?php else: ?>
        <div class="info other">
            <h3>⚠️ Not Hoover Facility</h3>
            <p>You will see regular "Dispense Complete" buttons in the POS system.</p>
            <p>To test the Hoover marketplace dispense feature, you need to:</p>
            <ol>
                <li>Log in as the "Virat" user (who is assigned to Hoover facility)</li>
                <li>Or assign your current user to facility 36 (Hoover, AL)</li>
            </ol>
        </div>
    <?php endif; ?>
    
    <div class="info">
        <h3>Available Users by Facility:</h3>
        <?php
        $all_users_query = "SELECT u.username, u.facility_id, f.name as facility_name 
                           FROM users u 
                           LEFT JOIN facility f ON u.facility_id = f.id 
                           WHERE u.username != 'admin' 
                           ORDER BY u.facility_id, u.username";
        $all_users_result = sqlStatement($all_users_query);
        
        $current_facility = null;
        while ($user = sqlFetchArray($all_users_result)) {
            if ($current_facility != $user['facility_id']) {
                if ($current_facility !== null) echo "</ul>";
                $current_facility = $user['facility_id'];
                echo "<h4>Facility " . $user['facility_id'] . " - " . ($user['facility_name'] ?: 'Not assigned') . "</h4><ul>";
            }
            echo "<li>" . htmlspecialchars($user['username']) . "</li>";
        }
        if ($current_facility !== null) echo "</ul>";
        ?>
    </div>
    
    <button class="button" onclick="window.close()">Close</button>
</body>
</html>


