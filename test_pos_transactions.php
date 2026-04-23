<?php
require_once(__DIR__ . "/interface/globals.php");

$pid = 4; // Patient ID from the logs

echo "<h2>Testing POS Transactions for Patient $pid</h2>";

// Check if table exists
$table_check = sqlStatement("SHOW TABLES LIKE 'pos_transactions'");
$table_exists = sqlFetchArray($table_check);

if (!$table_exists) {
    echo "<p style='color: red;'>❌ pos_transactions table does NOT exist!</p>";
    exit;
}

echo "<p style='color: green;'>✅ pos_transactions table exists</p>";

// Get all transactions for this patient
$query = "SELECT * FROM pos_transactions WHERE pid = ? ORDER BY created_date DESC";
$result = sqlStatement($query, array($pid));

$count = 0;
echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
echo "<tr style='background: #f0f0f0;'><th>ID</th><th>Receipt #</th><th>Type</th><th>Amount</th><th>Date</th><th>User ID</th><th>Items (JSON)</th></tr>";

while ($row = sqlFetchArray($result)) {
    $count++;
    echo "<tr>";
    echo "<td>" . $row['id'] . "</td>";
    echo "<td>" . $row['receipt_number'] . "</td>";
    echo "<td>" . $row['transaction_type'] . "</td>";
    echo "<td>$" . number_format($row['amount'], 2) . "</td>";
    echo "<td>" . $row['created_date'] . "</td>";
    echo "<td>" . $row['user_id'] . "</td>";
    echo "<td style='max-width: 300px; overflow: auto; font-size: 11px;'>" . htmlspecialchars(substr($row['items'], 0, 200)) . "...</td>";
    echo "</tr>";
}

echo "</table>";

if ($count == 0) {
    echo "<p style='color: red;'>❌ No transactions found for patient $pid</p>";
} else {
    echo "<p style='color: green;'>✅ Found $count transactions for patient $pid</p>";
}

// Also check the total count
$count_query = "SELECT COUNT(*) as total FROM pos_transactions";
$count_result = sqlQuery($count_query);
echo "<p>Total transactions in database: " . $count_result['total'] . "</p>";
?>


