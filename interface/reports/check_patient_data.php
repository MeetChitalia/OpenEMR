<?php
/**
 * Diagnostic tool to check why a "new" patient has existing credit and dispense data
 * 
 * Access via: interface/reports/check_patient_data.php?pid=XXX
 */

require_once(__DIR__ . '/../../globals.php');
require_once(__DIR__ . '/../../library/patient.inc');

use OpenEMR\Common\Acl\AclMain;

// Check authorization
if (!AclMain::aclCheckCore('patients', 'demo', '', array('read'))) {
    die(xlt('Access Denied'));
}

$pid = isset($_GET['pid']) ? intval($_GET['pid']) : 0;

if ($pid <= 0) {
    die("Error: Invalid or missing PID. Usage: check_patient_data.php?pid=XXX");
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Patient Data Diagnostic - PID <?php echo htmlspecialchars($pid); ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        .section {
            margin: 20px 0;
            padding: 15px;
            background: #f9f9f9;
            border-left: 4px solid #007bff;
            border-radius: 4px;
        }
        .section h2 {
            margin-top: 0;
            color: #007bff;
        }
        .warning {
            background: #fff3cd;
            border-left-color: #ffc107;
            color: #856404;
        }
        .error {
            background: #f8d7da;
            border-left-color: #dc3545;
            color: #721c24;
        }
        .success {
            background: #d4edda;
            border-left-color: #28a745;
            color: #155724;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #007bff;
            color: white;
            font-weight: 600;
        }
        tr:hover {
            background: #f5f5f5;
        }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-warning {
            background: #ffc107;
            color: #000;
        }
        .badge-danger {
            background: #dc3545;
            color: white;
        }
        .badge-success {
            background: #28a745;
            color: white;
        }
        .summary {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
        }
        .summary h3 {
            margin-top: 0;
            color: #0056b3;
        }
        .recommendation {
            background: #fff3cd;
            padding: 15px;
            border-radius: 4px;
            margin: 10px 0;
            border-left: 4px solid #ffc107;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Patient Data Diagnostic - PID <?php echo htmlspecialchars($pid); ?></h1>
        
        <?php
        // 1. Check patient basic info
        $patient = sqlQuery("SELECT pid, fname, lname, DOB, regdate, date FROM patient_data WHERE pid = ?", [$pid]);
        
        if (!$patient) {
            echo "<div class='section error'><h2>Error</h2><p>Patient not found!</p></div>";
            exit;
        }
        
        echo "<div class='section'><h2>1. Patient Basic Info</h2>";
        echo "<p><strong>Name:</strong> " . htmlspecialchars($patient['fname'] . ' ' . $patient['lname']) . "</p>";
        echo "<p><strong>DOB:</strong> " . htmlspecialchars($patient['DOB']) . "</p>";
        echo "<p><strong>Registration Date:</strong> " . htmlspecialchars($patient['regdate']) . "</p>";
        echo "<p><strong>Last Updated:</strong> " . htmlspecialchars($patient['date']) . "</p>";
        echo "</div>";
        
        // 2. Check for prior POS transactions
        echo "<div class='section'><h2>2. POS Transactions</h2>";
        $transactions = sqlStatement("SELECT id, receipt_number, transaction_type, amount, payment_method, created_date 
                                      FROM pos_transactions 
                                      WHERE pid = ? 
                                      ORDER BY created_date ASC", [$pid]);
        $txn_count = 0;
        $first_txn = null;
        $txn_rows = [];
        while ($txn = sqlFetchArray($transactions)) {
            if ($txn_count == 0) {
                $first_txn = $txn;
            }
            $txn_rows[] = $txn;
            $txn_count++;
        }
        
        echo "<p><strong>Total Transactions:</strong> $txn_count</p>";
        
        if ($txn_count > 0) {
            if ($first_txn) {
                $txn_date = strtotime($first_txn['created_date']);
                $reg_date = strtotime($patient['regdate']);
                
                if ($txn_date < $reg_date) {
                    echo "<p class='badge badge-danger'>⚠️ CRITICAL: First transaction is BEFORE patient registration!</p>";
                }
                
                echo "<p><strong>First Transaction:</strong> " . htmlspecialchars($first_txn['created_date']) . 
                     " ({$first_txn['transaction_type']}, \${$first_txn['amount']})</p>";
            }
            
            if ($txn_count <= 20) {
                echo "<table><thead><tr><th>Date</th><th>Receipt #</th><th>Type</th><th>Amount</th><th>Payment Method</th></tr></thead><tbody>";
                foreach ($txn_rows as $txn) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($txn['created_date']) . "</td>";
                    echo "<td>" . htmlspecialchars($txn['receipt_number']) . "</td>";
                    echo "<td>" . htmlspecialchars($txn['transaction_type']) . "</td>";
                    echo "<td>$" . number_format($txn['amount'], 2) . "</td>";
                    echo "<td>" . htmlspecialchars($txn['payment_method'] ?? 'N/A') . "</td>";
                    echo "</tr>";
                }
                echo "</tbody></table>";
            }
        } else {
            echo "<p class='badge badge-success'>✓ No transactions found (patient is truly new)</p>";
        }
        echo "</div>";
        
        // 3. Check credit balance
        $credit = sqlQuery("SELECT id, balance, created_date, updated_date 
                           FROM patient_credit_balance 
                           WHERE pid = ?", [$pid]);
        echo "<div class='section " . ($credit ? 'warning' : 'success') . "'><h2>3. Credit Balance</h2>";
        
        if ($credit) {
            echo "<p class='badge badge-warning'>⚠️ WARNING: Credit balance exists!</p>";
            echo "<p><strong>Balance:</strong> $" . number_format($credit['balance'], 2) . "</p>";
            echo "<p><strong>Created:</strong> " . htmlspecialchars($credit['created_date']) . "</p>";
            echo "<p><strong>Last Updated:</strong> " . htmlspecialchars($credit['updated_date']) . "</p>";
            
            // Check credit transactions
            $credit_txns = sqlStatement("SELECT id, transaction_type, amount, old_balance, new_balance, created_date, description 
                                         FROM patient_credit_transactions 
                                         WHERE pid = ? 
                                         ORDER BY created_date ASC", [$pid]);
            $credit_txn_count = 0;
            $credit_txn_rows = [];
            while ($ctxn = sqlFetchArray($credit_txns)) {
                $credit_txn_rows[] = $ctxn;
                $credit_txn_count++;
            }
            
            echo "<p><strong>Credit Transactions:</strong> $credit_txn_count</p>";
            
            if ($credit_txn_count > 0) {
                echo "<table><thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Old Balance</th><th>New Balance</th><th>Description</th></tr></thead><tbody>";
                foreach ($credit_txn_rows as $ctxn) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($ctxn['created_date']) . "</td>";
                    echo "<td>" . htmlspecialchars($ctxn['transaction_type']) . "</td>";
                    echo "<td>$" . number_format($ctxn['amount'], 2) . "</td>";
                    echo "<td>$" . number_format($ctxn['old_balance'], 2) . "</td>";
                    echo "<td>$" . number_format($ctxn['new_balance'], 2) . "</td>";
                    echo "<td>" . htmlspecialchars($ctxn['description'] ?? '') . "</td>";
                    echo "</tr>";
                }
                echo "</tbody></table>";
            } else {
                echo "<p class='badge badge-warning'>⚠️ No credit transactions found (orphaned credit record?)</p>";
            }
        } else {
            echo "<p class='badge badge-success'>✓ No credit balance (correct for new patient)</p>";
        }
        echo "</div>";
        
        // 4. Check remaining dispense records
        $dispense = sqlStatement("SELECT id, drug_id, lot_number, total_quantity, dispensed_quantity, 
                                        administered_quantity, remaining_quantity, receipt_number, created_date 
                                 FROM pos_remaining_dispense 
                                 WHERE pid = ? AND remaining_quantity > 0
                                 ORDER BY created_date DESC", [$pid]);
        $dispense_count = 0;
        $total_remaining = 0;
        $dispense_rows = [];
        while ($disp = sqlFetchArray($dispense)) {
            $dispense_rows[] = $disp;
            $dispense_count++;
            $total_remaining += intval($disp['remaining_quantity']);
        }
        echo "<div class='section " . ($dispense_count > 0 ? 'warning' : 'success') . "'><h2>4. Remaining Dispense Tracking</h2>";
        
        if ($dispense_count > 0) {
            echo "<p class='badge badge-warning'>⚠️ WARNING: Remaining dispense records exist!</p>";
            echo "<p><strong>Total Records:</strong> $dispense_count</p>";
            echo "<p><strong>Total Remaining:</strong> $total_remaining</p>";
            
            echo "<table><thead><tr><th>Date</th><th>Drug ID</th><th>Lot</th><th>Total</th><th>Dispensed</th><th>Administered</th><th>Remaining</th><th>Receipt</th></tr></thead><tbody>";
            foreach ($dispense_rows as $disp) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($disp['created_date']) . "</td>";
                echo "<td>" . htmlspecialchars($disp['drug_id']) . "</td>";
                echo "<td>" . htmlspecialchars($disp['lot_number']) . "</td>";
                echo "<td>" . htmlspecialchars($disp['total_quantity']) . "</td>";
                echo "<td>" . htmlspecialchars($disp['dispensed_quantity']) . "</td>";
                echo "<td>" . htmlspecialchars($disp['administered_quantity']) . "</td>";
                echo "<td><strong>" . htmlspecialchars($disp['remaining_quantity']) . "</strong></td>";
                echo "<td>" . htmlspecialchars($disp['receipt_number']) . "</td>";
                echo "</tr>";
            }
            echo "</tbody></table>";
        } else {
            echo "<p class='badge badge-success'>✓ No remaining dispense records (correct for new patient)</p>";
        }
        echo "</div>";
        
        // 5. Check encounters
        echo "<div class='section'><h2>5. Encounters</h2>";
        $encounters = sqlStatement("SELECT encounter, date FROM form_encounter WHERE pid = ? ORDER BY date ASC", [$pid]);
        $enc_count = 0;
        $first_enc = null;
        while ($enc = sqlFetchArray($encounters)) {
            if ($enc_count == 0) {
                $first_enc = $enc;
            }
            $enc_count++;
        }
        echo "<p><strong>Total Encounters:</strong> $enc_count</p>";
        if ($first_enc) {
            echo "<p><strong>First Encounter Date:</strong> " . htmlspecialchars($first_enc['date']) . "</p>";
        } else {
            echo "<p class='badge badge-success'>✓ No encounters (correct for new patient)</p>";
        }
        echo "</div>";
        
        // 6. Summary and recommendations
        echo "<div class='summary'><h3>Summary & Recommendations</h3>";
        
        $issues = [];
        $recommendations = [];
        
        if ($txn_count > 0 && $first_txn) {
            $txn_date = strtotime($first_txn['created_date']);
            $reg_date = strtotime($patient['regdate']);
            if ($txn_date < $reg_date) {
                $issues[] = "CRITICAL: First transaction (" . $first_txn['created_date'] . ") is BEFORE patient registration (" . $patient['regdate'] . ") - Data integrity issue!";
                $recommendations[] = "Check if PID was reused or if patient was merged/duplicated. Verify transaction dates.";
            } elseif (abs($txn_date - $reg_date) < 86400) {
                $issues[] = "First transaction is on the same day as registration - Patient may have been created with existing data";
            }
        }
        
        if ($credit && floatval($credit['balance']) > 0) {
            $issues[] = "Patient has $" . number_format($credit['balance'], 2) . " credit balance - Should be 0 for a new patient";
            $recommendations[] = "If credit was added by mistake, you can delete it: <code>DELETE FROM patient_credit_balance WHERE pid = $pid;</code>";
        }
        
        if ($dispense_count > 0) {
            $issues[] = "Patient has $dispense_count remaining dispense record(s) with $total_remaining total remaining - Should be 0 for a new patient";
            $recommendations[] = "If dispense records don't belong to this patient, you can delete them: <code>DELETE FROM pos_remaining_dispense WHERE pid = $pid;</code>";
        }
        
        if (empty($issues)) {
            echo "<p class='badge badge-success'>✓ Patient appears to be truly new with no existing data</p>";
        } else {
            echo "<p><strong>Issues Found:</strong></p><ul>";
            foreach ($issues as $issue) {
                echo "<li>$issue</li>";
            }
            echo "</ul>";
            
            if (!empty($recommendations)) {
                echo "<p><strong>Recommendations:</strong></p><ul>";
                foreach ($recommendations as $rec) {
                    echo "<li>$rec</li>";
                }
                echo "</ul>";
            }
        }
        
        echo "</div>";
        
        // 7. Check if patient should be considered "new" in DCR
        echo "<div class='section'><h2>6. DCR "New Patient" Status</h2>";
        $today = date('Y-m-d');
        $is_new = true;
        
        if ($txn_count > 0 && $first_txn) {
            $first_txn_date = date('Y-m-d', strtotime($first_txn['created_date']));
            $query = "SELECT COUNT(*) as previous_visits 
                      FROM pos_transactions 
                      WHERE pid = ? AND DATE(created_date) < ?";
            $stmt = sqlStatement($query, [$pid, $today]);
            $result = sqlFetchArray($stmt);
            $previous_visits = intval($result['previous_visits'] ?? 0);
            $is_new = ($previous_visits == 0);
            
            echo "<p><strong>Transactions before today:</strong> $previous_visits</p>";
            echo "<p><strong>Would be marked as "New" in DCR:</strong> " . ($is_new ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-warning">No (Returning)</span>') . "</p>";
        } else {
            echo "<p class='badge badge-success'>✓ No transactions - Would be marked as New in DCR</p>";
        }
        echo "</div>";
        ?>
        
        <div style="margin-top: 30px; padding: 15px; background: #f9f9f9; border-radius: 4px;">
            <p><strong>Note:</strong> This diagnostic tool helps identify why a "new" patient might have existing data. 
            If you find orphaned records, please verify they don't belong to the patient before deleting them.</p>
        </div>
    </div>
</body>
</html>

