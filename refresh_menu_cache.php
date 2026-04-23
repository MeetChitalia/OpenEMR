<?php
/**
 * Refresh Menu Cache Script
 * Clears the OpenEMR menu cache so new menu items appear immediately
 */

require_once("interface/globals.php");

echo "<h1>Refreshing Menu Cache</h1>";

// Clear any cached menu data
try {
    // Clear session cache
    if (isset($_SESSION)) {
        unset($_SESSION['menu_cache']);
        unset($_SESSION['menu_role_cache']);
    }
    
    // Clear any file-based cache if it exists
    $cache_files = [
        'interface/main/tabs/menu/menus/cache/',
        'sites/default/menu_cache/',
        'interface/main/tabs/menu/cache/'
    ];
    
    foreach ($cache_files as $cache_dir) {
        if (is_dir($cache_dir)) {
            $files = glob($cache_dir . '*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
    
    echo "<p style='color: green;'>✓ Menu cache cleared successfully</p>";
    
} catch (Exception $e) {
    echo "<p style='color: orange;'>⚠ Warning: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>Menu Update Complete!</h2>";
echo "<p>The menu has been updated with the new Weight Tracking section.</p>";
echo "<p><strong>To see the changes:</strong></p>";
echo "<ol>";
echo "<li>Refresh your browser page</li>";
echo "<li>Look for <strong>Weight Tracking</strong> under the Reports menu</li>";
echo "<li>Click on any of the weight report options</li>";
echo "</ol>";

echo "<p><strong>New menu items added:</strong></p>";
echo "<ul>";
echo "<li>Weight Reports Navigation - Central hub for all weight reports</li>";
echo "<li>Basic Weight Reports - Simple weight tracking and summaries</li>";
echo "<li>Weight Analytics - Advanced analytics and trends</li>";
echo "<li>Weight Goals - Goal setting and progress tracking</li>";
echo "</ul>";

echo "<p><a href='interface/main/tabs/main.php'>Go back to main page</a></p>";
echo "<p><em>Cache refreshed on " . date('Y-m-d H:i:s') . "</em></p>";
?>
