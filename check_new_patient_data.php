<?php
/**
 * Diagnostic script to check why a "new" patient has existing credit and dispense data
 * 
 * Usage: php check_new_patient_data.php <pid>
 */

require_once(__DIR__ . '/interface/globals.php');

if ($argc < 2) {
    echo "Usage: php check_new_patient_data.php <pid>\n";
    exit(1);
}

$pid = intval($argv[1]);

if ($pid <= 0) {
    echo "Error: Invalid PID\n";
    exit(1);
}

echo "=== Patient Data Diagnostic for PID: $pid ===\n\n";

// 1. Check patient basic info
echo "1. PATIENT BASIC INFO:\n";
$patient = sqlQuery("SELECT pid, fname, lname, DOB, regdate, date FROM patient_data WHERE pid = ?", [$pid]);
if ($patient) {
    echo "   Name: {$patient['fname']} {$patient['lname']}\n";
    echo "   DOB: {$patient['DOB']}\n";
    echo "   Registration Date: {$patient['regdate']}\n";
    echo "   Last Updated: {$patient['date']}\n";
} else {
    echo "   ERROR: Patient not found!\n";
    exit(1);
}
echo "\n";

// 2. Check for prior POS transactions
echo "2. POS TRANSACTIONS:\n";
$transactions = sqlStatement("SELECT id, receipt_number, transaction_type, amount, payment_method, created_date 
                               FROM pos_transactions 
                               WHERE pid = ? 
                               ORDER BY created_date ASC", [$pid]);
$txn_count = 0;
$first_txn = null;
while ($txn = sqlFetchArray($transactions)) {
    if ($txn_count == 0) {
        $first_txn = $txn;
    }
    $txn_count++;
}
echo "   Total Transactions: $txn_count\n";
if ($first_txn) {
    echo "   First Transaction Date: {$first_txn['created_date']}\n";
    echo "   First Transaction Type: {$first_txn['transaction_type']}\n";
    echo "   First Transaction Amount: \${$first_txn['amount']}\n";
} else {
    echo "   ✓ No transactions found (patient is truly new)\n";
}
echo "\n";

// 3. Check credit balance
echo "3. CREDIT BALANCE:\n";
$credit = sqlQuery("SELECT id, balance, created_date, updated_date 
                    FROM patient_credit_balance 
                    WHERE pid = ?", [$pid]);
if ($credit) {
    echo "   ⚠️  WARNING: Credit balance exists!\n";
    echo "   Balance: \${$credit['balance']}\n";
    echo "   Created: {$credit['created_date']}\n";
    echo "   Last Updated: {$credit['updated_date']}\n";
    
    // Check credit transactions
    $credit_txns = sqlStatement("SELECT id, transaction_type, amount, old_balance, new_balance, created_date, description 
                                 FROM patient_credit_transactions 
                                 WHERE pid = ? 
                                 ORDER BY created_date ASC", [$pid]);
    $credit_txn_count = 0;
    echo "   Credit Transactions:\n";
    while ($ctxn = sqlFetchArray($credit_txns)) {
        $credit_txn_count++;
        echo "      #$credit_txn_count: {$ctxn['transaction_type']} - \${$ctxn['amount']} on {$ctxn['created_date']}\n";
        echo "         Description: {$ctxn['description']}\n";
        echo "         Balance: \${$ctxn['old_balance']} -> \${$ctxn['new_balance']}\n";
    }
    if ($credit_txn_count == 0) {
        echo "      ⚠️  No credit transactions found (orphaned credit record?)\n";
    }
} else {
    echo "   ✓ No credit balance (correct for new patient)\n";
}
echo "\n";

// 4. Check remaining dispense records
echo "4. REMAINING DISPENSE TRACKING:\n";
$dispense = sqlStatement("SELECT id, drug_id, lot_number, total_quantity, dispensed_quantity, 
                                 administered_quantity, remaining_quantity, receipt_number, created_date 
                          FROM pos_remaining_dispense 
                          WHERE pid = ? AND remaining_quantity > 0
                          ORDER BY created_date DESC", [$pid]);
$dispense_count = 0;
$total_remaining = 0;
while ($disp = sqlFetchArray($dispense)) {
    $dispense_count++;
    $total_remaining += intval($disp['remaining_quantity']);
    echo "   ⚠️  Record #$dispense_count:\n";
    echo "      Drug ID: {$disp['drug_id']}\n";
    echo "      Lot: {$disp['lot_number']}\n";
    echo "      Total: {$disp['total_quantity']}, Dispensed: {$disp['dispensed_quantity']}, Administered: {$disp['administered_quantity']}, Remaining: {$disp['remaining_quantity']}\n";
    echo "      Receipt: {$disp['receipt_number']}\n";
    echo "      Created: {$disp['created_date']}\n";
}
if ($dispense_count == 0) {
    echo "   ✓ No remaining dispense records (correct for new patient)\n";
} else {
    echo "   ⚠️  Total Remaining: $total_remaining\n";
}
echo "\n";

// 5. Check if patient has any encounters
echo "5. ENCOUNTERS:\n";
$encounters = sqlStatement("SELECT encounter, date FROM form_encounter WHERE pid = ? ORDER BY date ASC", [$pid]);
$enc_count = 0;
$first_enc = null;
while ($enc = sqlFetchArray($encounters)) {
    if ($enc_count == 0) {
        $first_enc = $enc;
    }
    $enc_count++;
}
echo "   Total Encounters: $enc_count\n";
if ($first_enc) {
    echo "   First Encounter Date: {$first_enc['date']}\n";
} else {
    echo "   ✓ No encounters (correct for new patient)\n";
}
echo "\n";

// 6. Summary and recommendations
echo "=== SUMMARY ===\n";
$issues = [];

if ($txn_count > 0 && $first_txn) {
    $txn_date = strtotime($first_txn['created_date']);
    $reg_date = strtotime($patient['regdate']);
    if ($txn_date < $reg_date) {
        $issues[] = "⚠️  CRITICAL: First transaction ({$first_txn['created_date']}) is BEFORE patient registration ({$patient['regdate']}) - Data integrity issue!";
    } elseif ($txn_date == $reg_date) {
        $issues[] = "⚠️  First transaction is on the same day as registration - Patient may have been created with existing data";
    }
}

if ($credit && floatval($credit['balance']) > 0) {
    $issues[] = "⚠️  Patient has \${$credit['balance']} credit balance - Should be 0 for a new patient";
}

if ($dispense_count > 0) {
    $issues[] = "⚠️  Patient has $dispense_count remaining dispense record(s) with $total_remaining total remaining - Should be 0 for a new patient";
}

if (empty($issues)) {
    echo "✓ Patient appears to be truly new with no existing data\n";
} else {
    echo "ISSUES FOUND:\n";
    foreach ($issues as $issue) {
        echo "  $issue\n";
    }
    echo "\nRECOMMENDATIONS:\n";
    if ($credit && floatval($credit['balance']) > 0) {
        echo "  1. Check if credit was added by mistake. If so, delete the credit record:\n";
        echo "     DELETE FROM patient_credit_balance WHERE pid = $pid;\n";
        echo "     DELETE FROM patient_credit_transactions WHERE pid = $pid;\n";
    }
    if ($dispense_count > 0) {
        echo "  2. Check if dispense records belong to this patient. If not, delete them:\n";
        echo "     DELETE FROM pos_remaining_dispense WHERE pid = $pid;\n";
    }
    if ($txn_count > 0) {
        echo "  3. Verify that transactions belong to this patient. If patient was created incorrectly:\n";
        echo "     - Check if PID was reused\n";
        echo "     - Check if patient was merged or duplicated\n";
    }
}

echo "\n";


