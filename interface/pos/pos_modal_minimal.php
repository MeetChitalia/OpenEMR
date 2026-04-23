<?php
// Minimal POS Modal Test
echo "<h1>POS Modal Minimal Test</h1>";
echo "<p>Current time: " . date('Y-m-d H:i:s') . "</p>";

// Test basic includes
try {
    require_once(__DIR__ . "/../globals.php");
    echo "<p>✅ globals.php loaded</p>";
} catch (Exception $e) {
    echo "<p>❌ Error loading globals.php: " . $e->getMessage() . "</p>";
}

// Test session
if (isset($_SESSION['authUserID'])) {
    echo "<p>✅ User logged in: " . ($_SESSION['authUser'] ?? 'Unknown') . "</p>";
} else {
    echo "<p>❌ User not logged in</p>";
}

// Test database
try {
    $result = sqlQuery("SELECT 1 as test");
    echo "<p>✅ Database working</p>";
} catch (Exception $e) {
    echo "<p>❌ Database error: " . $e->getMessage() . "</p>";
}

echo "<p>Minimal test completed.</p>";
?>
