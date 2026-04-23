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

// Check the current setting
$sql = "SELECT gl_value FROM globals WHERE gl_name = 'full_new_patient_form'";
$result = mysqli_query($conn, $sql);

if ($result && mysqli_num_rows($result) > 0) {
    $row = mysqli_fetch_assoc($result);
    echo "Current full_new_patient_form setting: " . $row['gl_value'] . "\n";
    
    // Show what each setting means
    echo "\nSetting meanings:\n";
    echo "0 = Old-style static form without search or duplication check\n";
    echo "1 = All demographics fields, with search and duplication check\n";
    echo "2 = Mandatory or specified fields only, search and dup check\n";
    echo "3 = Mandatory or specified fields only, dup check, no search\n";
    echo "4 = Mandatory or specified fields only, use patient validation Zend module\n";
} else {
    echo "Setting not found in database\n";
}

mysqli_close($conn);
?> 