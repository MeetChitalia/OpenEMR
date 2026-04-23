<?php
/**
 * Test file for Guardian Age Functionality
 * This file tests the shouldShowGuardianFields function
 */

require_once("library/options.inc.php");

// Test cases
$test_cases = [
    ['dob' => '2010-01-01', 'expected' => true, 'description' => '13 year old - should show guardian'],
    ['dob' => '2005-01-01', 'expected' => true, 'description' => '18 year old - should show guardian'],
    ['dob' => '2000-01-01', 'expected' => false, 'description' => '23 year old - should not show guardian'],
    ['dob' => '1990-01-01', 'expected' => false, 'description' => '33 year old - should not show guardian'],
];

echo "<h2>Testing Guardian Age Functionality</h2>\n";
echo "<table border='1' style='border-collapse: collapse;'>\n";
echo "<tr><th>Test Case</th><th>Date of Birth</th><th>Expected</th><th>Actual</th><th>Result</th></tr>\n";

foreach ($test_cases as $test) {
    // Simulate POST data for testing
    $_POST['form_DOB'] = $test['dob'];
    
    $result = shouldShowGuardianFields(1); // pid doesn't matter for this test
    $passed = ($result === $test['expected']);
    
    echo "<tr>";
    echo "<td>" . htmlspecialchars($test['description']) . "</td>";
    echo "<td>" . htmlspecialchars($test['dob']) . "</td>";
    echo "<td>" . ($test['expected'] ? 'Show' : 'Hide') . "</td>";
    echo "<td>" . ($result ? 'Show' : 'Hide') . "</td>";
    echo "<td style='color: " . ($passed ? 'green' : 'red') . "'>" . ($passed ? 'PASS' : 'FAIL') . "</td>";
    echo "</tr>\n";
}

echo "</table>\n";

// Test new patient scenario (no pid)
echo "<h3>Testing New Patient Scenario (no PID)</h3>\n";
echo "<p>When no DOB is provided, guardian fields should show by default: " . (shouldShowGuardianFields(0) ? 'PASS' : 'FAIL') . "</p>\n";

// Clear POST data
unset($_POST['form_DOB']);
?> 