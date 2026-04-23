<?php
/**
 * Update Drug Discount Percentage
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
$discount_active = intval($_POST['discount_active'] ?? 0);
$discount_type = $_POST['discount_type'] ?? 'percentage';
$discount_percent = floatval($_POST['discount_percent'] ?? 0);
$discount_amount = floatval($_POST['discount_amount'] ?? 0);
$discount_quantity = isset($_POST['discount_quantity']) && $_POST['discount_quantity'] !== '' ? intval($_POST['discount_quantity']) : null;
$discount_start_date = $_POST['discount_start_date'] ?? null;
$discount_end_date = $_POST['discount_end_date'] ?? null;
$discount_month = $_POST['discount_month'] ?? null;
$discount_description = $_POST['discount_description'] ?? '';

// Validate input
if ($drug_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid drug ID']);
    exit;
}

if ($discount_type === 'percentage' && ($discount_percent < 0 || $discount_percent > 100)) {
    echo json_encode(['success' => false, 'message' => 'Percentage discount must be between 0 and 100']);
    exit;
}

if ($discount_type === 'fixed' && $discount_amount < 0) {
    echo json_encode(['success' => false, 'message' => 'Fixed discount amount cannot be negative']);
    exit;
}

if ($discount_type === 'quantity' && ($discount_quantity === null || $discount_quantity <= 1)) {
    echo json_encode(['success' => false, 'message' => 'Quantity discount must be greater than 1. Example: 4 means buy 4 get 1 free.']);
    exit;
}

// Validate discount type
if (!in_array($discount_type, ['percentage', 'fixed', 'quantity'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid discount type']);
    exit;
}

// Check if drug exists
$drug_check = sqlQuery("SELECT drug_id FROM drugs WHERE drug_id = ?", array($drug_id));
if (!$drug_check) {
    echo json_encode(['success' => false, 'message' => 'Drug not found']);
    exit;
}

// Update all discount fields
$result = sqlStatement(
    "UPDATE drugs SET 
        discount_active = ?, 
        discount_type = ?, 
        discount_percent = ?, 
        discount_amount = ?, 
        discount_quantity = ?, 
        discount_start_date = ?, 
        discount_end_date = ?, 
        discount_month = ?, 
        discount_description = ? 
     WHERE drug_id = ?",
    array(
        $discount_active,
        $discount_type,
        $discount_percent,
        $discount_amount,
        $discount_quantity,
        $discount_start_date,
        $discount_end_date,
        $discount_month,
        $discount_description,
        $drug_id
    )
);

if ($result) {
    // Log the change
    $user = $_SESSION['authUser'] ?? 'unknown';
    $log_message = "Drug discount updated: Drug ID $drug_id, Discount: $discount_percent%, User: $user";
    error_log($log_message);
    
    echo json_encode(['success' => true, 'message' => 'Discount updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update discount']);
}
?> 