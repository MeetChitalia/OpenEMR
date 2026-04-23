<?php
/**
 * Assign User to Hoover Facility
 * Simple script to assign a user to Hoover facility for testing
 */

require_once(__DIR__ . "/interface/globals.php");

// Check if user is logged in
if (!isset($_SESSION['authUserID'])) {
    die("Not logged in. Please log in first.");
}

$username = $_SESSION['authUser'] ?? 'Unknown';

// Get current facility
$current_facility_query = "SELECT facility_id FROM users WHERE username = ?";
$current_facility_result = sqlFetchArray(sqlStatement($current_facility_query, array($username)));
$current_facility_id = $current_facility_result ? $current_facility_result['facility_id'] : 'NULL';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Assign Hoover Facility</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .info { background: #f0f0f0; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .button { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin: 5px; }
        .danger { background: #dc3545; }
        .success { background: #28a745; }
    </style>
</head>
<body>
    <h1>Assign User to Hoover Facility</h1>
    
    <div class="info">
        <h3>Current User:</h3>
        <p><strong>Username:</strong> <?php echo htmlspecialchars($username); ?></p>
        <p><strong>Current Facility ID:</strong> <?php echo $current_facility_id; ?></p>
    </div>
    
    <?php if ($current_facility_id == 36): ?>
        <div class="info" style="background: #d4edda;">
            <h3>✅ Already Assigned to Hoover!</h3>
            <p>You are already assigned to facility 36 (Hoover, AL). You should see marketplace dispense options in the POS system.</p>
        </div>
    <?php else: ?>
        <div class="info">
            <h3>Assign to Hoover Facility</h3>
            <p>Click the button below to assign your user to facility 36 (Hoover, AL) for testing the marketplace dispense feature.</p>
            
            <form method="POST">
                <button type="submit" name="assign_hoover" class="button success">Assign to Hoover Facility (36)</button>
            </form>
        </div>
        
        <?php
        if (isset($_POST['assign_hoover'])) {
            $update_query = "UPDATE users SET facility_id = 36 WHERE username = ?";
            $result = sqlStatement($update_query, array($username));
            
            if ($result !== false) {
                echo '<div class="info" style="background: #d4edda;"><h3>✅ Success!</h3><p>You have been assigned to Hoover facility. Please refresh the POS system to see the marketplace dispense options.</p></div>';
            } else {
                echo '<div class="info" style="background: #f8d7da;"><h3>❌ Error</h3><p>Failed to assign to Hoover facility. Please try again.</p></div>';
            }
        }
        ?>
    <?php endif; ?>
    
    <div class="info">
        <h3>Reset to Original Facility</h3>
        <p>If you want to reset back to your original facility:</p>
        
        <form method="POST">
            <button type="submit" name="reset_facility" class="button danger">Reset to Facility <?php echo $current_facility_id; ?></button>
        </form>
        
        <?php
        if (isset($_POST['reset_facility']) && $current_facility_id != 36) {
            $update_query = "UPDATE users SET facility_id = ? WHERE username = ?";
            $result = sqlStatement($update_query, array($current_facility_id, $username));
            
            if ($result !== false) {
                echo '<div class="info" style="background: #d4edda;"><h3>✅ Reset!</h3><p>You have been reset to facility ' . $current_facility_id . '.</p></div>';
            } else {
                echo '<div class="info" style="background: #f8d7da;"><h3>❌ Error</h3><p>Failed to reset facility. Please try again.</p></div>';
            }
        }
        ?>
    </div>
    
    <button class="button" onclick="window.close()">Close</button>
</body>
</html>


