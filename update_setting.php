<?php
// Simple database connection
$host = 'localhost';
$port = '3306';
$login = 'root';
$pass = '';
$dbase = 'openemr';

$conn = mysqli_connect($host, $login, $pass, $dbase, $port);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Update the setting to 0 (simple form)
$sql = "UPDATE globals SET gl_value = '0' WHERE gl_name = 'full_new_patient_form'";
$result = mysqli_query($conn, $sql);

if ($result) {
    echo "Successfully updated full_new_patient_form setting to 0 (simple form)\n";
    
    // Verify the change
    $check_sql = "SELECT gl_value FROM globals WHERE gl_name = 'full_new_patient_form'";
    $check_result = mysqli_query($conn, $check_sql);
    if ($check_result && mysqli_num_rows($check_result) > 0) {
        $row = mysqli_fetch_assoc($check_result);
        echo "Current setting is now: " . $row['gl_value'] . "\n";
    }
} else {
    echo "Failed to update setting: " . mysqli_error($conn) . "\n";
}

mysqli_close($conn);
?> 