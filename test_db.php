<?php
// Test database connection
$host = 'localhost';
$port = '3306';
$login = 'root';
$pass = '';
$dbase = 'openemr';

echo "Testing database connection...\n";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbase", $login, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Database connection successful!\n";
    
    // Test a simple query
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Users table has " . $result['count'] . " records\n";
    
} catch (PDOException $e) {
    echo "Database connection failed: " . $e->getMessage() . "\n";
}
?>

