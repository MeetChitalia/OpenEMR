<?php
// Debug file to test basic functionality
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>";
echo "<html><head><title>Debug Test</title></head><body>";
echo "<h1>Debug Test</h1>";
echo "<p>PHP is working!</p>";
echo "<p>Time: " . date('Y-m-d H:i:s') . "</p>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<p>Current Directory: " . getcwd() . "</p>";
echo "<p>File: " . __FILE__ . "</p>";
echo "</body></html>";
?> 