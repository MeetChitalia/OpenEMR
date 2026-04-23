<?php
// Test script for POS Prescription Fee Configuration
require_once(__DIR__ . "/library/globals.inc.php");

echo "<h2>POS Prescription Fee Configuration Test</h2>\n";

$pos_prescription_fee_enabled = $GLOBALS['pos_prescription_fee_enabled'] ?? null;
$pos_prescription_fee_amount = $GLOBALS['pos_prescription_fee_amount'] ?? null;

echo "<p><strong>pos_prescription_fee_enabled:</strong> " . ($pos_prescription_fee_enabled ? "Enabled" : "Disabled") . "</p>\n";
echo "<p><strong>pos_prescription_fee_amount:</strong> $" . ($pos_prescription_fee_amount ?? "Not Set") . "</p>\n";

if ($pos_prescription_fee_enabled) {
    echo "<p>✅ Feature is ENABLED</p>\n";
} else {
    echo "<p>⚠️ Feature is DISABLED - Go to Admin > Globals to enable</p>\n";
}
?> 