<?php
/**
 * Patient Search API for POS Refund System
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Team
 * @copyright Copyright (c) 2024 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once("$srcdir/patient.inc");

use OpenEMR\Common\Csrf\CsrfUtils;

// Start output buffering to ensure clean JSON responses
ob_start();

// Set JSON content type
header('Content-Type: application/json');

// Helper function to send JSON response
function sendJsonResponse($data, $statusCode = 200) {
    ob_clean();
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    sendJsonResponse(['success' => false, 'error' => 'Invalid JSON input'], 400);
}

// Verify CSRF token
if (!CsrfUtils::verifyCsrfToken($input['csrf_token'] ?? '')) {
    sendJsonResponse(['success' => false, 'error' => 'Invalid CSRF token'], 403);
}

$search = $input['search'] ?? '';

if (strlen($search) < 2) {
    sendJsonResponse(['success' => true, 'patients' => []]);
}

$search = trim($search);
$whereSql = '';
$binds = [];

if (ctype_digit($search)) {
    $prefix = $search . '%';
    $whereSql = "WHERE (p.pid = ? OR p.pubpid LIKE ? OR p.phone_cell LIKE ? OR p.phone_home LIKE ?)";
    $binds = [(int) $search, $prefix, $prefix, $prefix];
} else {
    $prefix = $search . '%';
    $whereSql = "WHERE (p.lname LIKE ? OR p.fname LIKE ? OR p.pubpid LIKE ?)";
    $binds = [$prefix, $prefix, $prefix];
}

$sql = "SELECT 
            p.pid,
            p.fname,
            p.lname,
            p.pubpid,
            p.phone_cell,
            p.phone_home,
            p.DOB,
            p.sex,
            COALESCE(pcb.balance, 0) as balance
        FROM patient_data p
        LEFT JOIN patient_credit_balance pcb ON p.pid = pcb.pid
        $whereSql
        ORDER BY p.lname, p.fname, p.pid
        LIMIT 20";

$result = sqlStatement($sql, $binds);

$patients = array();
while ($row = sqlFetchArray($result)) {
    $patients[] = array(
        'pid' => intval($row['pid']),
        'fname' => $row['fname'],
        'lname' => $row['lname'],
        'pubpid' => $row['pubpid'],
        'phone' => $row['phone_cell'] ?: $row['phone_home'],
        'dob' => $row['DOB'],
        'sex' => $row['sex'],
        'balance' => floatval($row['balance'])
    );
}

sendJsonResponse([
    'success' => true,
    'patients' => $patients,
    'count' => count($patients)
]);
?>

