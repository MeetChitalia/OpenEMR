<?php
require_once("../globals.php");

echo "<html><head><title>Test Lot Page</title></head><body>";
echo "<h1>Test Lot Page</h1>";
echo "<p>This is a test page to verify basic functionality.</p>";
echo "<p>Current time: " . date('Y-m-d H:i:s') . "</p>";
echo "<p>PHP version: " . phpversion() . "</p>";
echo "<p>OpenEMR version: " . ($GLOBALS['openemr_version'] ?? 'Not set') . "</p>";
echo "<p>Site ID: " . ($GLOBALS['site_id'] ?? 'Not set') . "</p>";
echo "<p>Database connected: " . (isset($GLOBALS['adodb']['db']) ? 'Yes' : 'No') . "</p>";
echo "</body></html>";
?> 