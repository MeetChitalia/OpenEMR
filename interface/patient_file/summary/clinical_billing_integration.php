<?php

/**
 * Clinical Billing Integration
 * 
 * This file handles the integration of billing charges for clinical items
 * (Prescriptions, Medications, and Administered items) directly from the patient dashboard.
 * 
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Meet Patel <meetpatel1798@gmail.com>
 * @copyright Copyright (c) 2024 Meet Patel
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../../globals.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/encounter.inc");
require_once("$srcdir/options.inc.php");

use OpenEMR\Billing\BillingUtilities;
use OpenEMR\Common\Csrf\CsrfUtils;

/**
 * Add billing charge for a prescription
 * 
 * @param int $pid Patient ID
 * @param int $encounter_id Encounter ID
 * @param string $drug_name Drug name
 * @param string $dosage Dosage information
 * @param float $fee Fee amount
 * @param string $description Optional description
 * @return bool Success status
 */
function addPrescriptionCharge($pid, $encounter_id, $drug_name, $dosage, $fee, $description = '') {
    if (empty($pid)) {
        error_log("Error: Patient ID is required for prescription charge");
        return false;
    }
    
    // Validate and get encounter ID
    $valid_encounter_id = validateAndGetEncounter($pid, $encounter_id);
    
    if (empty($valid_encounter_id)) {
        // Create a new encounter if none exists
        $valid_encounter_id = createEncounter($pid);
        if (!$valid_encounter_id) {
            error_log("Error: Failed to create encounter for patient $pid");
            return false;
        }
    }
    
    $code_type = 'PRESCRIPTION';
    $code = 'RX_' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $drug_name), 0, 10));
    $code_text = $description ?: "Prescription: $drug_name - $dosage";
    
    try {
        $result = BillingUtilities::addBilling(
            $valid_encounter_id,
            $code_type,
            $code,
            $code_text,
            $pid,
            '1', // authorized
            $_SESSION['authUserID'],
            '', // modifier
            1, // units
            $fee,
            '', // ndc_info
            '', // justify
            0, // billed
            '', // notecodes
            '', // pricelevel
            '', // revenue_code
            '' // payer_id
        );
        
        if ($result) {
            return true;
        } else {
            error_log("Error: BillingUtilities::addBilling returned false for prescription charge");
            return false;
        }
    } catch (Exception $e) {
        error_log("Error adding prescription charge: " . $e->getMessage());
        return false;
    }
}

/**
 * Add billing charge for a medication
 * 
 * @param int $pid Patient ID
 * @param int $encounter_id Encounter ID
 * @param string $medication_name Medication name
 * @param string $comments Comments/notes
 * @param float $fee Fee amount
 * @param string $description Optional description
 * @return bool Success status
 */
function addMedicationCharge($pid, $encounter_id, $medication_name, $comments, $fee, $description = '', $drug_id = 0, $quantity = 1) {
    if (empty($pid)) {
        error_log("Error: Patient ID is required for medication charge");
        return false;
    }
    
    // Validate and get encounter ID
    $valid_encounter_id = validateAndGetEncounter($pid, $encounter_id);
    
    if (empty($valid_encounter_id)) {
        // Create a new encounter if none exists
        $valid_encounter_id = createEncounter($pid);
        if (!$valid_encounter_id) {
            error_log("Error: Failed to create encounter for patient $pid");
            return false;
        }
    }
    
    $code_type = 'MEDICATION';
    $code = 'MED_' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $medication_name), 0, 10));
    $code_text = $description ?: "Medication: $medication_name" . ($comments ? " - $comments" : '');
    
    try {
        // Start transaction for inventory reduction
        sqlStatement("START TRANSACTION");
        
        // Add billing charge
        $result = BillingUtilities::addBilling(
            $valid_encounter_id,
            $code_type,
            $code,
            $code_text,
            $pid,
            '1', // authorized
            $_SESSION['authUserID'],
            '', // modifier
            $quantity, // units
            $fee,
            '', // ndc_info
            '', // justify
            0, // billed
            '', // notecodes
            '', // pricelevel
            '', // revenue_code
            '' // payer_id
        );
        
        if (!$result) {
            sqlStatement("ROLLBACK");
            error_log("Error: BillingUtilities::addBilling returned false for medication charge");
            return false;
        }
        
        // Reduce inventory if drug_id is provided
        if ($drug_id > 0 && $quantity > 0) {
            // Find available inventory to reduce
            $inventory_sql = "SELECT inventory_id, on_hand FROM drug_inventory 
                             WHERE drug_id = ? AND destroy_date IS NULL AND on_hand > 0 
                             ORDER BY expiration ASC, inventory_id ASC";
            $inventory_result = sqlStatement($inventory_sql, array($drug_id));
            
            $remaining_quantity = $quantity;
            
            while ($remaining_quantity > 0) {
                $row = sqlFetchArray($inventory_result);
                if (!$row) {
                    break; // No more inventory available
                }
                
                $inventory_id = $row['inventory_id'];
                $available_qty = $row['on_hand'];
                $reduce_qty = min($remaining_quantity, $available_qty);
                
                // Update inventory
                sqlStatement(
                    "UPDATE drug_inventory SET on_hand = on_hand - ? WHERE inventory_id = ?",
                    array($reduce_qty, $inventory_id)
                );
                
                $remaining_quantity -= $reduce_qty;
            }
            
            if ($remaining_quantity > 0) {
                // Not enough inventory available
                sqlStatement("ROLLBACK");
                error_log("Error: Insufficient inventory for drug_id $drug_id, requested $quantity");
                return false;
            }
        }
        
        // Commit transaction
        sqlStatement("COMMIT");
        return true;
        
    } catch (Exception $e) {
        sqlStatement("ROLLBACK");
        error_log("Error adding medication charge: " . $e->getMessage());
        return false;
    }
}

/**
 * Add billing charge for an administered item (immunization/procedure)
 * 
 * @param int $pid Patient ID
 * @param int $encounter_id Encounter ID
 * @param string $item_name Item name
 * @param string $manufacturer Manufacturer info
 * @param float $fee Fee amount
 * @param string $description Optional description
 * @return bool Success status
 */
function addAdministeredCharge($pid, $encounter_id, $item_name, $manufacturer, $fee, $description = '') {
    if (empty($pid)) {
        error_log("Error: Patient ID is required for administered charge");
        return false;
    }
    
    // Validate and get encounter ID
    $valid_encounter_id = validateAndGetEncounter($pid, $encounter_id);
    
    if (empty($valid_encounter_id)) {
        // Create a new encounter if none exists
        $valid_encounter_id = createEncounter($pid);
        if (!$valid_encounter_id) {
            error_log("Error: Failed to create encounter for patient $pid");
            return false;
        }
    }
    
    $code_type = 'ADMINISTERED';
    $code = 'ADMIN_' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $item_name), 0, 10));
    $code_text = $description ?: "Administered: $item_name" . ($manufacturer ? " ($manufacturer)" : '');
    
    try {
        $result = BillingUtilities::addBilling(
            $valid_encounter_id,
            $code_type,
            $code,
            $code_text,
            $pid,
            '1', // authorized
            $_SESSION['authUserID'],
            '', // modifier
            1, // units
            $fee,
            '', // ndc_info
            '', // justify
            0, // billed
            '', // notecodes
            '', // pricelevel
            '', // revenue_code
            '' // payer_id
        );
        
        if ($result) {
            return true;
        } else {
            error_log("Error: BillingUtilities::addBilling returned false for administered charge");
            return false;
        }
    } catch (Exception $e) {
        error_log("Error adding administered charge: " . $e->getMessage());
        return false;
    }
}

/**
 * Create a new encounter if none exists
 * 
 * @param int $pid Patient ID
 * @return int Encounter ID
 */
function createEncounter($pid) {
    try {
        $encounter = date('Y-m-d H:i:s');
        $reason = 'Clinical billing integration';
        $facility = 1; // Default facility
        $facility_id = 1; // Default facility ID
        $provider_id = $_SESSION['authUserID'];
        
        // Insert encounter directly into form_encounter table
        $sql = "INSERT INTO form_encounter (date, reason, pid, facility, facility_id, provider_id) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $result = sqlInsert($sql, array($encounter, $reason, $pid, $facility, $facility_id, $provider_id));
        
        if ($result) {
            return $result; // sqlInsert returns the ID of the inserted record
        } else {
            error_log("Error creating encounter: sqlInsert failed");
            return 0;
        }
    } catch (Exception $e) {
        error_log("Error creating encounter: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get default fees for different clinical items
 * 
 * @param string $item_type Type of item (prescription, medication, administered)
 * @return float Default fee amount
 */
function getDefaultFee($item_type) {
    $default_fees = array(
        'prescription' => 25.00,
        'medication' => 15.00,
        'administered' => 50.00
    );
    
    return isset($default_fees[$item_type]) ? $default_fees[$item_type] : 25.00;
}

/**
 * Helper function to safely handle database results
 * 
 * @param mixed $result Database result
 * @return array Safe array result
 */
function ensureArrayResult($result) {
    if (is_object($result) && method_exists($result, 'FetchRow')) {
        $row = $result->FetchRow();
        return $row ? $row : array();
    }
    return $result ? $result : array();
}

/**
 * Handle AJAX requests for adding clinical items with billing
 */
if ($_POST['action'] ?? '' === 'add_clinical_item') {
    try {
        if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
            CsrfUtils::csrfNotVerified();
        }
        
        $response = array('success' => false, 'message' => '');
        
        $pid = intval($_POST['pid'] ?? 0);
        $encounter_id = intval($_POST['encounter_id'] ?? 0);
        $item_type = $_POST['item_type'] ?? '';
        $item_name = $_POST['item_name'] ?? '';
        $fee = floatval($_POST['fee'] ?? 0);
        $description = $_POST['description'] ?? '';
        
        // Enhanced validation
        if (!$pid) {
            $response['message'] = 'Invalid patient ID';
            echo json_encode($response);
            exit;
        }
        
        if (!$item_type) {
            $response['message'] = 'Item type is required';
            echo json_encode($response);
            exit;
        }
        
        if (!$item_name) {
            $response['message'] = 'Item name is required';
            echo json_encode($response);
            exit;
        }
        
        if ($fee <= 0) {
            $response['message'] = 'Fee must be greater than 0';
            echo json_encode($response);
            exit;
        }
        
        $success = false;
        $error_message = '';
        
        switch ($item_type) {
            case 'prescription':
                $dosage = $_POST['dosage'] ?? '';
                $notes = $_POST['notes'] ?? '';
                
                // If item_name is empty or just "Prescription", use notes to create a meaningful name
                if (empty($item_name) || $item_name === 'Prescription') {
                    $item_name = $notes ? 'Prescription: ' . substr($notes, 0, 30) : 'Prescription';
                }
                
                $success = addPrescriptionCharge($pid, $encounter_id, $item_name, $dosage, $fee, $description);
                if (!$success) {
                    $error_message = 'Failed to add prescription charge';
                }
                break;
                
            case 'medication':
                $comments = $_POST['comments'] ?? '';
                $drug_id = intval($_POST['drug_id'] ?? 0);
                $quantity = intval($_POST['quantity'] ?? 1);
                $success = addMedicationCharge($pid, $encounter_id, $item_name, $comments, $fee, $description, $drug_id, $quantity);
                if (!$success) {
                    $error_message = 'Failed to add medication charge';
                }
                break;
                
            case 'administered':
                $manufacturer = $_POST['manufacturer'] ?? '';
                $success = addAdministeredCharge($pid, $encounter_id, $item_name, $manufacturer, $fee, $description);
                if (!$success) {
                    $error_message = 'Failed to add administered charge';
                }
                break;
                
            default:
                $response['message'] = 'Invalid item type: ' . $item_type;
                echo json_encode($response);
                exit;
        }
        
        if ($success) {
            $response['success'] = true;
            $response['message'] = 'Item added successfully with billing charge';
            $response['encounter_id'] = $encounter_id;
        } else {
            $response['message'] = $error_message ?: 'Failed to add item or billing charge';
        }
        
        echo json_encode($response);
        exit;
        
    } catch (Exception $e) {
        error_log("Clinical billing integration error: " . $e->getMessage());
        $response = array(
            'success' => false, 
            'message' => 'System error: ' . $e->getMessage()
        );
        echo json_encode($response);
        exit;
    }
}

/**
 * Get current encounter ID for patient
 * 
 * @param int $pid Patient ID
 * @return int Encounter ID or 0 if none
 */
function getCurrentEncounter($pid) {
    try {
        $result = sqlQuery("SELECT encounter FROM form_encounter WHERE pid = ? ORDER BY date DESC LIMIT 1", array($pid));
        if (is_object($result) && method_exists($result, 'FetchRow')) {
            $row = $result->FetchRow();
            return $row ? intval($row['encounter']) : 0;
        }
        return $result ? intval($result['encounter']) : 0;
    } catch (Exception $e) {
        error_log("Error getting current encounter for patient $pid: " . $e->getMessage());
        return 0;
    }
}

/**
 * Validate and get encounter ID for patient
 * 
 * @param int $pid Patient ID
 * @param int $encounter_id Encounter ID (optional)
 * @return int Valid encounter ID or 0 if none
 */
function validateAndGetEncounter($pid, $encounter_id = 0) {
    // If encounter_id is provided, validate it belongs to this patient
    if ($encounter_id > 0) {
        $check_result = sqlQuery(
            "SELECT encounter FROM form_encounter WHERE pid = ? AND encounter = ?",
            array($pid, $encounter_id)
        );
        
        if (is_object($check_result) && method_exists($check_result, 'FetchRow')) {
            $row = $check_result->FetchRow();
            if ($row && $row['encounter'] == $encounter_id) {
                return intval($encounter_id);
            }
        } else if ($check_result && $check_result['encounter'] == $encounter_id) {
            return intval($encounter_id);
        }
        
        // If the provided encounter_id is invalid, log it and get current encounter
        error_log("Warning: Encounter $encounter_id does not exist for patient $pid, getting current encounter instead");
    }
    
    // Get current encounter for the patient
    return getCurrentEncounter($pid);
}
?> 