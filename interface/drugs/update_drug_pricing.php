<?php
/**
 * Update Drug Pricing
 * 
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Meet Patel <meetpatel1798@gmail.com>
 * @copyright Copyright (c) 2024 Meet Patel
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("$srcdir/options.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

// Check authorizations
if (!AclMain::aclCheckCore('admin', 'drugs')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Verify CSRF token
if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

// Set content type to JSON
header('Content-Type: application/json');

// Get POST data
$drug_id = intval($_POST['drug_id'] ?? 0);
$cost_per_unit = isset($_POST['cost_per_unit']) ? floatval($_POST['cost_per_unit']) : null;
$sell_price = isset($_POST['sell_price']) ? floatval($_POST['sell_price']) : null;

// Validate input
if ($drug_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid drug ID']);
    exit;
}

// Check if drug exists
$drug_check = sqlQuery("SELECT drug_id FROM drugs WHERE drug_id = ?", array($drug_id));
if (!$drug_check) {
    echo json_encode(['success' => false, 'message' => 'Drug not found']);
    exit;
}

// Build update query based on what's being updated
$update_fields = array();
$update_values = array();

if ($cost_per_unit !== null) {
    if ($cost_per_unit < 0) {
        echo json_encode(['success' => false, 'message' => 'Cost cannot be negative']);
        exit;
    }
    $update_fields[] = "cost_per_unit = ?";
    $update_values[] = $cost_per_unit;
}

if ($sell_price !== null) {
    if ($sell_price < 0) {
        echo json_encode(['success' => false, 'message' => 'Sell price cannot be negative']);
        exit;
    }
    $update_fields[] = "sell_price = ?";
    $update_values[] = $sell_price;
}

if (empty($update_fields)) {
    echo json_encode(['success' => false, 'message' => 'No fields to update']);
    exit;
}

// Add drug_id to the end for WHERE clause
$update_values[] = $drug_id;

// Update the drug pricing
$sql = "UPDATE drugs SET " . implode(", ", $update_fields) . " WHERE drug_id = ?";
$result = sqlStatement($sql, $update_values);

if ($result) {
    // Log the change
    $user = $_SESSION['authUser'] ?? 'unknown';
    $changes = array();
    if ($cost_per_unit !== null) $changes[] = "Cost: $cost_per_unit";
    if ($sell_price !== null) $changes[] = "Sell Price: $sell_price";
    $log_message = "Drug pricing updated: Drug ID $drug_id, Changes: " . implode(", ", $changes) . ", User: $user";
    error_log($log_message);
    
    echo json_encode(['success' => true, 'message' => 'Pricing updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update pricing']);
}
?> 