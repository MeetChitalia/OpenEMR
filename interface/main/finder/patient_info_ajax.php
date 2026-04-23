<?php

/**
 * Patient Information AJAX endpoint
 *
 * @package OpenEMR
 * @author Brady Miller <brady.g.miller@gmail.com>
 * @copyright (C) 2017 Brady Miller <brady.g.miller@gmail.com>
 * @link http://www.open-emr.org
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../../globals.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/formatting.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;

// Ensure this is an AJAX request
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    http_response_code(403);
    exit('Direct access not allowed');
}

// Verify CSRF token
if (!CsrfUtils::verifyCsrfToken($_GET['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Get patient ID
$pid = isset($_GET['pid']) ? intval($_GET['pid']) : 0;

if (!$pid) {
    echo json_encode(['success' => false, 'error' => 'Invalid patient ID']);
    exit;
}

try {
    // Debug: Log the PID being processed
    error_log("Patient info AJAX - Processing PID: " . $pid);
    
    // Get patient data
    $patient = getPatientData($pid);
    
    if (!$patient) {
        error_log("Patient info AJAX - Patient not found for PID: " . $pid);
        echo json_encode(['success' => false, 'error' => 'Patient not found']);
        exit;
    }

    // Debug: Check if patient data is valid
    if (!is_array($patient)) {
        error_log("Patient info AJAX - Invalid patient data format for PID: " . $pid);
        echo json_encode(['success' => false, 'error' => 'Invalid patient data format']);
        exit;
    }

    error_log("Patient info AJAX - Successfully retrieved patient data for PID: " . $pid);

    // Get additional patient information
    $encounter_count = 0;
    $last_encounter_date = '';
    $next_appointment = '';
    
    // Get encounter count
    $query = "SELECT COUNT(*) as count FROM form_encounter WHERE pid = ?";
    $result = sqlQuery($query, array($pid));
    $resultData = sqlFetchArray($result);
    $encounter_count = $resultData ? $resultData['count'] : 0;
    
    // Get last encounter date
    $query = "SELECT MAX(date) as last_date FROM form_encounter WHERE pid = ?";
    $result = sqlQuery($query, array($pid));
    $resultData = sqlFetchArray($result);
    $last_encounter_date = $resultData ? $resultData['last_date'] : '';
    
    // Get next appointment
    $query = "SELECT pc_eventDate, pc_startTime, pc_apptstatus 
              FROM openemr_postcalendar_events 
              WHERE pc_pid = ? AND pc_eventDate >= CURDATE() 
              ORDER BY pc_eventDate ASC, pc_startTime ASC 
              LIMIT 1";
    $result = sqlQuery($query, array($pid));
    $resultData = sqlFetchArray($result);
    $next_appointment = $resultData && $resultData['pc_eventDate'] ? $resultData['pc_eventDate'] . ' ' . $resultData['pc_startTime'] : '';

    // Get current weight from vitals (primary source)
    $current_weight = '';
    $weight_date = '';
    $weight_source = '';
    
    // First try to get weight from form_vitals (standard vitals)
    $query = "SELECT weight, date FROM form_vitals WHERE pid = ? AND weight > 0 ORDER BY date DESC, id DESC LIMIT 1";
    $result = sqlQuery($query, array($pid));
    $resultData = sqlFetchArray($result);
    if ($resultData && $resultData['weight']) {
        $current_weight = floatval($resultData['weight']);
        $weight_date = $resultData['date'];
        $weight_source = 'vitals';
    }
    
    // If no weight in vitals, check POS weight tracking as fallback
    if (!$current_weight) {
        $pos_query = "SELECT weight, date_recorded as date FROM pos_weight_tracking WHERE pid = ? AND weight > 0 ORDER BY date_recorded DESC LIMIT 1";
        $pos_result = sqlQuery($pos_query, array($pid));
        $pos_resultData = sqlFetchArray($pos_result);
        if ($pos_resultData && $pos_resultData['weight']) {
            $current_weight = floatval($pos_resultData['weight']);
            $weight_date = $pos_resultData['date'];
            $weight_source = 'pos_tracking';
        }
    }

    // Helper function to check if field has value
    function hasValue($value) {
        return !empty(trim($value)) && $value !== '0000-00-00 00:00:00';
    }
    
    // Build the HTML with conditional field display
    $html = '
    <style>
    .patient-info-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        padding: 1.2rem 1rem 1rem 1rem;
        max-width: 900px;
        margin: 0 auto;
        position: relative;
        z-index: 1050;
        width: 100%;
        box-sizing: border-box;
    }
    .patient-info-title {
        font-size: 1.2rem;
        font-weight: 700;
        margin-bottom: 1.5rem;
        color: #2a3b4d;
        text-align: center;
    }
    .patient-info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.7rem 1.2rem;
        width: 100%;
    }
    @media (max-width: 900px) {
        .patient-info-grid {
            grid-template-columns: 1fr;
        }
    }
    @media (max-width: 600px) {
        .patient-info-card {
            padding: 1rem 0.5rem 0.5rem 0.5rem;
        }
        .patient-info-grid {
            gap: 0.5rem 0.8rem;
        }
    }
    .patient-info-section {
        grid-column: 1 / -1;
        font-size: 1rem;
        font-weight: 600;
        color: #4A90E2;
        margin-top: 0.7rem;
        margin-bottom: 0.2rem;
        border-bottom: 1px solid #e6e8ec;
        padding-bottom: 0.1rem;
    }
    .patient-info-field {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.7rem;
    }
    .patient-info-label {
        font-weight: 600;
        color: #333;
        min-width: 120px;
        text-align: right;
        padding-right: 0.5rem;
    }
    .patient-info-value {
        flex: 1;
        padding: 0.45rem 0.7rem;
        border: 1.5px solid #E1E5E9;
        border-radius: 7px;
        font-size: 0.98rem;
        background: white;
        color: #495057;
        min-height: 38px;
        display: flex;
        align-items: center;
        box-sizing: border-box;
    }
    .patient-info-value:focus {
        border-color: #4A90E2;
        outline: none;
        box-shadow: 0 0 0 0.2rem rgba(74, 144, 226, 0.25);
    }
    .patient-info-actions {
        grid-column: 1 / -1;
        display: flex;
        justify-content: center;
        gap: 0.7rem;
        margin-top: 1rem;
    }
    .btn-save {
        background: #4A90E2;
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 0.5rem 1.5rem;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s;
    }
    .btn-save:hover {
        background: #357ABD;
    }
    .btn-cancel {
        background: #f8f9fa;
        color: #495057;
        border: 2px solid #E1E5E9;
        border-radius: 8px;
        padding: 0.5rem 1.5rem;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s;
    }
    .btn-cancel:hover {
        background: #e9ecef;
    }
    .badge {
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .badge-secondary {
        background: #6c757d;
        color: white;
    }
    .badge-info {
        background: #17a2b8;
        color: white;
    }
    .badge-success {
        background: #28a745;
        color: white;
    }
    .badge-danger {
        background: #dc3545;
        color: white;
    }
    .badge-primary {
        background: #007bff;
        color: white;
    }
    .required {
        color: #dc3545;
    }
    .form-control {
        width: 100%;
        padding: 0.45rem 0.7rem;
        border: 1.5px solid #E1E5E9;
        border-radius: 7px;
        font-size: 0.98rem;
        background: white;
        margin-bottom: 0.1rem;
    }
    .form-control:focus {
        border-color: #4A90E2;
        outline: none;
        box-shadow: 0 0 0 0.2rem rgba(74, 144, 226, 0.25);
    }
    
    /* Modern button styles matching finder page */
    .btn-primary-modern {
        background-color: #3b82f6;
        color: white;
        border: none;
        border-radius: 8px;
        padding: 0.5rem 1.5rem;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .btn-primary-modern:hover {
        background-color: #2563eb;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    }
    
    .btn-secondary-modern {
        background-color: #f3f4f6;
        color: #374151;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        padding: 0.5rem 1.5rem;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    
    .btn-secondary-modern:hover {
        background-color: #e5e7eb;
        transform: translateY(-1px);
    }
    
    /* Pay Now button styling (matching Finder page) */
    .pay-now-btn {
        background: linear-gradient(135deg, #BF1542 0%, #A01238 100%);
        border: none;
        color: white;
        box-shadow: 0 2px 8px rgba(191, 21, 66, 0.2);
        transition: all 0.3s ease;
        border-radius: 8px;
        padding: 0.5rem 1.5rem;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .pay-now-btn:hover {
        background: linear-gradient(135deg, #A01238 0%, #8A0F30 100%);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(191, 21, 66, 0.25);
        color: white;
    }
    
    .pay-now-btn:active {
        transform: translateY(0);
        box-shadow: 0 1px 4px rgba(191, 21, 66, 0.2);
    }
    </style>
    
    <div class="patient-info-card">
        <div class="patient-info-title">
            <i class="fas fa-user-circle me-2" style="color: #4A90E2;"></i>
            Patient Information - ' . htmlspecialchars($patient['fname'] . ' ' . $patient['lname']) . '
        </div>
        
        <div class="patient-info-grid">';
    
    // Basic Information Section
    $html .= '
            <div class="patient-info-section">
                <i class="fas fa-user me-2"></i>Basic Information
            </div>';
    
    // Name (always shown)
    $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">Name:</label>
                <div class="patient-info-value">' . htmlspecialchars($patient['fname'] . ' ' . $patient['lname']) . '</div>
            </div>';
    
    // Patient ID (always shown)
    $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">Patient ID:</label>
                <div class="patient-info-value">' . htmlspecialchars($patient['pubpid']) . '</div>
            </div>';
    
    // Date of Birth (only if has value)
    if (hasValue($patient['DOB'])) {
        $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">Date of Birth:</label>
                <div class="patient-info-value">' . oeFormatShortDate($patient['DOB']) . '</div>
            </div>';
    }
    
    // Gender (only if has value)
    if (hasValue($patient['sex'])) {
        $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">Gender:</label>
                <div class="patient-info-value">' . htmlspecialchars($patient['sex']) . '</div>
            </div>';
    }
    
    // SSN (only if has value)
    if (hasValue($patient['ss'])) {
        $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">SSN:</label>
                <div class="patient-info-value">' . htmlspecialchars($patient['ss']) . '</div>
            </div>';
    }
    
    // Status (always shown)
    $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">Status:</label>
                <div class="patient-info-value">' . ($patient['status'] ? 'Active' : 'Inactive') . '</div>
            </div>';

    // Weight Information (if available)
    if ($current_weight) {
        $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">Weight:</label>
                <div class="patient-info-value">' . number_format($current_weight, 1) . ' lbs</div>
            </div>';
    }
    
    // Contact Information Section (only if any contact info exists)
    $hasContactInfo = hasValue($patient['phone_home']) || hasValue($patient['phone_cell']) || hasValue($patient['phone_biz']) || hasValue($patient['phone_contact']) || hasValue($patient['email']);
    if ($hasContactInfo) {
        $html .= '
            <div class="patient-info-section">
                <i class="fas fa-phone me-2"></i>Contact Information
            </div>';
        
        if (hasValue($patient['phone_home'])) {
            $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">Home Phone:</label>
                <div class="patient-info-value">' . htmlspecialchars($patient['phone_home']) . '</div>
            </div>';
        }
        
        if (hasValue($patient['phone_cell'])) {
            $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">Cell Phone:</label>
                <div class="patient-info-value">' . htmlspecialchars($patient['phone_cell']) . '</div>
            </div>';
        }
        
        if (hasValue($patient['phone_biz'])) {
            $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">Business Phone:</label>
                <div class="patient-info-value">' . htmlspecialchars($patient['phone_biz']) . '</div>
            </div>';
        }
        
        if (hasValue($patient['phone_contact'])) {
            $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">Contact Phone:</label>
                <div class="patient-info-value">' . htmlspecialchars($patient['phone_contact']) . '</div>
            </div>';
        }
        
        if (hasValue($patient['email'])) {
            $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">Email:</label>
                <div class="patient-info-value">' . htmlspecialchars($patient['email']) . '</div>
            </div>';
        }
    }
    
    // Address Information Section (only if any address info exists)
    $hasAddressInfo = hasValue($patient['street']) || hasValue($patient['city']) || hasValue($patient['state']) || hasValue($patient['postal_code']) || hasValue($patient['country_code']);
    if ($hasAddressInfo) {
        $html .= '
            <div class="patient-info-section">
                <i class="fas fa-map-marker-alt me-2"></i>Address Information
            </div>';
        
        if (hasValue($patient['street'])) {
            $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">Street:</label>
                <div class="patient-info-value">' . htmlspecialchars($patient['street']) . '</div>
            </div>';
        }
        
        if (hasValue($patient['city'])) {
            $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">City:</label>
                <div class="patient-info-value">' . htmlspecialchars($patient['city']) . '</div>
            </div>';
        }
        
        if (hasValue($patient['state'])) {
            $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">State:</label>
                <div class="patient-info-value">' . htmlspecialchars($patient['state']) . '</div>
            </div>';
        }
        
        if (hasValue($patient['postal_code'])) {
            $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">ZIP:</label>
                <div class="patient-info-value">' . htmlspecialchars($patient['postal_code']) . '</div>
            </div>';
        }
        
        if (hasValue($patient['country_code'])) {
            $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">Country:</label>
                <div class="patient-info-value">' . htmlspecialchars($patient['country_code']) . '</div>
            </div>';
        }
    }
    
    // Medical Information Section (only if any medical info exists)
    $hasMedicalInfo = $encounter_count > 0 || hasValue($last_encounter_date) || hasValue($next_appointment);
    if ($hasMedicalInfo) {
        $html .= '
            <div class="patient-info-section">
                <i class="fas fa-heartbeat me-2"></i>Medical Information
            </div>';
        
        if ($encounter_count > 0) {
            $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">Total Encounters:</label>
                <div class="patient-info-value">' . $encounter_count . '</div>
            </div>';
        }
        
        if (hasValue($last_encounter_date)) {
            $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">Last Encounter:</label>
                <div class="patient-info-value">' . oeFormatShortDate($last_encounter_date) . '</div>
            </div>';
        }
        
        if (hasValue($next_appointment)) {
            $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">Next Appointment:</label>
                <div class="patient-info-value">' . oeFormatShortDate($next_appointment) . '</div>
            </div>';
        }
    }
    
    // Emergency Contact Section (only if any emergency contact info exists)
    $hasEmergencyInfo = hasValue($patient['emergency_contact'] ?? '') || hasValue($patient['emergency_relationship'] ?? '') || hasValue($patient['emergency_phone'] ?? '');
    if ($hasEmergencyInfo) {
        $html .= '
            <div class="patient-info-section">
                <i class="fas fa-exclamation-triangle me-2"></i>Emergency Contact
            </div>';
        
        if (hasValue($patient['emergency_contact'] ?? '')) {
            $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">Emergency Contact Name:</label>
                <div class="patient-info-value">' . htmlspecialchars($patient['emergency_contact'] ?? '') . '</div>
            </div>';
        }
        
        if (hasValue($patient['emergency_relationship'] ?? '')) {
            $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">Relationship:</label>
                <div class="patient-info-value">' . htmlspecialchars($patient['emergency_relationship'] ?? '') . '</div>
            </div>';
        }
        
        if (hasValue($patient['emergency_phone'] ?? '')) {
            $html .= '
            <div class="patient-info-field">
                <label class="patient-info-label">Emergency Phone:</label>
                <div class="patient-info-value">' . htmlspecialchars($patient['emergency_phone'] ?? '') . '</div>
            </div>';
        }
    }
    
    // Action Buttons
    $html .= '
            <div class="patient-info-actions">
                <button type="button" class="pay-now-btn" onclick="openPOSForPatient(' . $pid . ')">
                    <i class="fa fa-credit-card"></i> POS
                </button>
                <button type="button" class="btn-secondary-modern" onclick="closePatientInfoModal()">
                    Close
                </button>
            </div>
        </div>
    </div>';

    echo json_encode([
        'success' => true,
        'html' => $html,
        'patient' => [
            'pid' => $pid,
            'name' => $patient['fname'] . ' ' . $patient['lname'],
            'pubpid' => $patient['pubpid']
        ]
    ]);

} catch (Exception $e) {
    error_log("Patient info AJAX error: " . $e->getMessage());
    error_log("Patient info AJAX error trace: " . $e->getTraceAsString());
    echo json_encode(['success' => false, 'error' => 'Error loading patient information: ' . $e->getMessage()]);
}


















