<?php
/**
 * Test page for Enhanced DCR Report
 * Direct access to test if the report is working
 */

require_once("../globals.php");

// Direct database query to test
echo "<h1>Enhanced DCR Report Test</h1>";
echo "<hr>";

echo "<h2>Today's Transactions</h2>";

try {
    $today = date('Y-m-d');
    
    $query = "SELECT pt.*, p.fname, p.lname 
              FROM pos_transactions pt
              LEFT JOIN patient_data p ON pt.pid = p.pid
              WHERE DATE(pt.created_date) = ?
              ORDER BY pt.created_date DESC";
    
    $result = sqlStatement($query, [$today]);
    
    echo "<p><strong>Query:</strong> $query</p>";
    echo "<p><strong>Date:</strong> $today</p>";
    
    $count = 0;
    $total = 0;
    
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    echo "<tr>";
    echo "<th>ID</th>";
    echo "<th>Patient</th>";
    echo "<th>Receipt</th>";
    echo "<th>Type</th>";
    echo "<th>Amount</th>";
    echo "<th>Items</th>";
    echo "<th>Date/Time</th>";
    echo "</tr>";
    
    if ($result) {
        while ($row = sqlFetchArray($result)) {
            $count++;
            $total += floatval($row['amount']);
            
            $patient_name = trim(($row['fname'] ?? '') . ' ' . ($row['lname'] ?? ''));
            $items = json_decode($row['items'], true);
            $items_count = is_array($items) ? count($items) : 0;
            
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['id']) . "</td>";
            echo "<td>" . htmlspecialchars($patient_name) . "</td>";
            echo "<td>" . htmlspecialchars($row['receipt_number']) . "</td>";
            echo "<td>" . htmlspecialchars($row['transaction_type']) . "</td>";
            echo "<td>$" . number_format($row['amount'], 2) . "</td>";
            echo "<td>$items_count items</td>";
            echo "<td>" . htmlspecialchars($row['created_date']) . "</td>";
            echo "</tr>";
            
            // Show items detail
            if (is_array($items) && !empty($items)) {
                echo "<tr>";
                echo "<td colspan='7' style='background: #f9f9f9;'>";
                echo "<strong>Items:</strong><br>";
                echo "<pre>" . htmlspecialchars(json_encode($items, JSON_PRETTY_PRINT)) . "</pre>";
                echo "</td>";
                echo "</tr>";
            }
        }
    }
    
    echo "</table>";
    
    echo "<hr>";
    echo "<p><strong>Total Transactions:</strong> $count</p>";
    echo "<p><strong>Total Amount:</strong> $" . number_format($total, 2) . "</p>";
    
    if ($count == 0) {
        echo "<p style='color: red;'>⚠️ No transactions found for today!</p>";
    } else {
        echo "<p style='color: green;'>✅ Transactions found!</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<h2>Test Enhanced DCR Functions</h2>";

// Test the functions
require_once("dcr_daily_collection_report_enhanced.php");

// This won't work because we already included globals.php, but we can still test

echo "<hr>";
echo "<p><a href='dcr_daily_collection_report_enhanced.php'>Open Enhanced DCR Report</a></p>";
echo "<p><a href='dcr_daily_collection_report.php'>Open Original DCR Report</a></p>";
?>


