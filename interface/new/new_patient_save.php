<?php

/**
 * new_patient_save.php
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("$srcdir/options.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Validators\PatientValidator;
use OpenEMR\Validators\ProcessingResult;

$pid = null;
$isAjaxCreateRequest = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$isFinderModalCreate = !empty($_POST['from_finder_modal']) && $_POST['from_finder_modal'] === '1';

function newPatientSaveIsAjaxRequest(): bool
{
    global $isAjaxCreateRequest;
    return $isAjaxCreateRequest;
}

function newPatientSaveIsFinderModalCreate(): bool
{
    global $isFinderModalCreate;
    return $isFinderModalCreate;
}

function newPatientSaveShouldRespondJson(): bool
{
    return newPatientSaveIsAjaxRequest() || newPatientSaveIsFinderModalCreate();
}

function newPatientSaveRespondJson(array $payload): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(200);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload);
    exit();
}

function newPatientSaveRespondFailure(string $message): void
{
    global $alertmsg;

    $alertmsg = trim($message);
    if ($alertmsg === '') {
        $alertmsg = xl('Patient creation failed. Please review the form and try again.');
    }

    if (newPatientSaveShouldRespondJson()) {
        newPatientSaveRespondJson([
            'success' => false,
            'message' => $alertmsg,
        ]);
    }
}

function newPatientSaveFormatProcessingErrors(ProcessingResult $processingResult): string
{
    $messages = [];

    foreach ($processingResult->getValidationMessages() as $fieldName => $fieldMessages) {
        if (!is_array($fieldMessages)) {
            continue;
        }
        foreach ($fieldMessages as $messageKey => $value) {
            $fieldLabel = trim((string) $fieldName);

            if (is_string($value) && trim($value) !== '') {
                $formattedValue = trim($value);
                if (
                    $formattedValue === 'LengthBetween::TOO_SHORT' ||
                    $formattedValue === 'Length::TOO_SHORT' ||
                    $formattedValue === 'LengthBetween::TOO_LONG' ||
                    $formattedValue === 'Length::TOO_LONG' ||
                    $formattedValue === 'Datetime::INVALID_VALUE'
                ) {
                    $formattedValue = '';
                } else {
                    $messages[] = $formattedValue;
                    continue;
                }
            }

            $translatedMessage = '';
            $ruleIdentifier = is_string($messageKey) ? trim($messageKey) : '';
            if ($ruleIdentifier === '') {
                $ruleIdentifier = is_string($value) ? trim($value) : '';
            }

            if ($ruleIdentifier === 'LengthBetween::TOO_SHORT' || $ruleIdentifier === 'Length::TOO_SHORT') {
                if (strcasecmp($fieldLabel, 'Last Name') === 0 || strcasecmp($fieldLabel, 'First Name') === 0) {
                    $translatedMessage = sprintf(xl('%s must be at least 2 characters.'), $fieldLabel);
                } else {
                    $translatedMessage = sprintf(xl('%s is too short.'), $fieldLabel !== '' ? $fieldLabel : xl('This field'));
                }
            } elseif ($ruleIdentifier === 'LengthBetween::TOO_LONG' || $ruleIdentifier === 'Length::TOO_LONG') {
                $translatedMessage = sprintf(xl('%s is too long.'), $fieldLabel !== '' ? $fieldLabel : xl('This field'));
            } elseif ($ruleIdentifier === 'Datetime::INVALID_VALUE') {
                $translatedMessage = sprintf(xl('%s must be a valid date.'), $fieldLabel !== '' ? $fieldLabel : xl('Date of Birth'));
            }

            if ($translatedMessage !== '') {
                $messages[] = $translatedMessage;
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $nestedValue) {
                    if (is_string($nestedValue) && trim($nestedValue) !== '') {
                        $messages[] = trim($nestedValue);
                    }
                }
                continue;
            }

            if (is_string($message) && trim($message) !== '') {
                $messages[] = trim($message);
            }
        }
    }

    foreach ($processingResult->getInternalErrors() as $internalError) {
        $internalError = trim((string) $internalError);
        if ($internalError !== '') {
            $messages[] = $internalError;
        }
    }

    $messages = array_values(array_unique(array_filter(array_map('trim', $messages))));
    if (empty($messages)) {
        return xl('Patient creation failed. Please review the form and try again.');
    }

    return implode("\n", $messages);
}

function newPatientSaveBuildDuplicateMessage(array $duplicatePatients, array $patientData = []): string
{
    if (empty($duplicatePatients)) {
        return xl('Possible duplicate patient found. Review the matching records below before creating a new patient.');
    }

    $facilityNames = [];
    foreach ($duplicatePatients as $duplicatePatient) {
        $facilityName = getPatientFacilityDisplayName(
            $duplicatePatient['facility_id'] ?? '',
            $duplicatePatient['care_team_facility'] ?? '',
            $duplicatePatient['facility_name'] ?? ''
        );
        if ($facilityName !== '') {
            $facilityNames[] = $facilityName;
        }
    }

    $facilityNames = array_values(array_unique($facilityNames));
    $selectedFacilityName = getPatientFacilityDisplayName(
        '',
        $patientData['care_team_facility'] ?? '',
        ''
    );

    if ($selectedFacilityName === xl('Unassigned')) {
        $selectedFacilityName = '';
    }

    if (empty($facilityNames)) {
        return xl('Possible duplicate patient found. Review the matching records below before creating a new patient.');
    }

    if (count($facilityNames) === 1) {
        $message = sprintf(
            xl('A matching patient already exists in %s.'),
            $facilityNames[0]
        );
        if ($selectedFacilityName !== '') {
            $message .= ' ' . sprintf(
                xl('Selected care team facility: %s.'),
                $selectedFacilityName
            );
        }
        $message .= ' ' . xl('Review the matching record below before creating a new patient.');
        return $message;
    }

    $message = sprintf(
        xl('Matching patients already exist in these facilities: %s.'),
        implode(', ', $facilityNames)
    );
    if ($selectedFacilityName !== '') {
        $message .= ' ' . sprintf(
            xl('Selected care team facility: %s.'),
            $selectedFacilityName
        );
    }
    $message .= ' ' . xl('Review the records below before creating a new patient.');
    return $message;
}

function newPatientSaveGetMissingRequiredFieldMessage(array $patientData): string
{
    $requiredFields = [];
    $requiredResult = sqlStatement("SELECT field_id, title FROM layout_options WHERE form_id = 'DEM' AND uor = 2");
    while ($row = sqlFetchArray($requiredResult)) {
        if (($row['field_id'] ?? '') === 'care_team_facility') {
            continue;
        }
        $requiredFields[$row['field_id']] = $row['title'];
    }

    foreach ($requiredFields as $fieldId => $fieldTitle) {
        $value = $patientData[$fieldId] ?? '';
        if (is_array($value)) {
            $value = implode('', array_map('trim', $value));
        } else {
            $value = trim((string) $value);
        }

        if ($value === '') {
            return sprintf(xl('Please complete the required field: %s.'), $fieldTitle);
        }
    }

    $dob = trim((string) ($patientData['DOB'] ?? ''));
    if ($dob !== '' && $dob !== '0000-00-00') {
        try {
            $dobDate = new DateTime($dob);
            $today = new DateTime();
            $age = (int) $today->diff($dobDate)->y;
            if ($age < 18) {
                $guardianFields = [
                    'guardiansname' => xl('Guardian Name'),
                    'guardianaddress' => xl('Guardian Address'),
                    'guardianphone' => xl('Guardian Phone'),
                    'guardianrelationship' => xl('Guardian Relationship'),
                ];

                foreach ($guardianFields as $fieldId => $fieldTitle) {
                    $value = trim((string) ($patientData[$fieldId] ?? ''));
                    if ($value === '') {
                        return sprintf(xl('For patients under 18, please complete: %s.'), $fieldTitle);
                    }
                }
            }
        } catch (Throwable $e) {
        }
    }

    return '';
}

function newPatientSaveNormalizeDateValue($value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    // Strip stray separator characters the datepicker/input mask can leave behind
    // so values like "09/20/2000-" normalize cleanly instead of tripping
    // layout max-length/date parsing guards.
    $value = preg_replace('/[^\d\/-]+/', '', $value);
    $value = preg_replace('/[\/-]+$/', '', $value);

    $formats = ['Y-m-d', 'm/d/Y', 'm-d-Y', 'n/j/Y', 'n-j-Y'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $value);
        $errors = DateTime::getLastErrors();
        $hasErrors = is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);
        if ($date instanceof DateTime && !$hasErrors) {
            return $date->format('Y-m-d');
        }
    }

    $timestamp = strtotime($value);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }

    return $value;
}

// Validation for non-unique external patient identifier.
$alertmsg = '';

try {
    $csrfToken = $_POST['csrf_token_form'] ?? '';
    if ($csrfToken === '' || !CsrfUtils::verifyCsrfToken($csrfToken)) {
        if (newPatientSaveIsAjaxRequest()) {
            newPatientSaveRespondJson([
                'success' => false,
                'message' => xl('Your session expired. Please refresh the page and try again.'),
            ]);
        }
        CsrfUtils::csrfNotVerified();
    }

    if (!empty($_POST["form_pubpid"])) {
        $form_pubpid = trim($_POST["form_pubpid"]);
        $result = sqlQuery("SELECT count(*) AS count FROM patient_data WHERE " .
        "pubpid = ?", array($form_pubpid));
        if (is_object($result) && method_exists($result, 'FetchRow')) {
            $result = $result->FetchRow();
        } elseif (!is_array($result)) {
            $result = [];
        }
        if (!empty($result['count'])) {
            $alertmsg = xl('Warning: Patient ID is not unique!');
            if (newPatientSaveIsAjaxRequest()) {
                newPatientSaveRespondJson([
                    'success' => false,
                    'message' => $alertmsg,
                    'reload_form' => true,
                ]);
            }
            require_once("new.php");
            exit();
        }
    }

    require_once("$srcdir/pid.inc");
    require_once("$srcdir/patient.inc");

    if (!empty($_POST['form_create'])) {

    // Debug: Log received data
    error_log("New patient save - POST data: " . print_r($_POST, true));

    // Prepare data arrays for patient creation using layout system
    $newdata = array();
    $newdata['patient_data'] = array();
    $newdata['employer_data'] = array();
    $duplicatePatients = [];
    $duplicateOverride = !empty($_POST['duplicate_override']);

    // The finder modal DOB field is rendered in MM/DD/YYYY regardless of the
    // global display format, so normalize it before get_layout_form_value()
    // applies the generic date formatter.
    if (isset($_POST['form_DOB'])) {
        $_POST['form_DOB'] = newPatientSaveNormalizeDateValue($_POST['form_DOB']);
    }

    // Get all layout fields for DEM form
    $fres = sqlStatement("SELECT * FROM layout_options " .
      "WHERE form_id = 'DEM' AND (uor > 0 OR field_id = 'pubpid') AND field_id != '' " .
      "ORDER BY group_id, seq");
    
    while ($frow = sqlFetchArray($fres)) {
        $data_type = $frow['data_type'];
        $field_id  = $frow['field_id'];
        $colname   = $field_id;
        $tblname   = 'patient_data';
        
        if (strpos($field_id, 'em_') === 0) {
            $colname = substr($field_id, 3);
            $tblname = 'employer_data';
        }

        // Get value only if field exists in $_POST (prevents deleting of field with disabled attribute)
        if (isset($_POST["form_$field_id"]) || $field_id == "pubpid") {
            if ($field_id === 'DOB') {
                // Bypass the generic layout date parser here because this modal
                // always posts DOB in a UI-friendly format, while some installs
                // still have the legacy field-length/date conversion path that
                // can reject valid DOB values before we reach validation.
                $value = newPatientSaveNormalizeDateValue($_POST["form_$field_id"] ?? '');
            } else {
                $value = get_layout_form_value($frow);
            }
            $newdata[$tblname][$colname] = $value;
        }
    }

    // Debug: Log the data being sent to updatePatientData
    error_log("New patient save - Data being sent to updatePatientData: " . print_r($newdata['patient_data'], true));

    if (array_key_exists('facility_id', $newdata['patient_data'])) {
        $newdata['patient_data']['facility_id'] = normalizePatientFacilityIdForStorage($newdata['patient_data']['facility_id']);
    } else {
        $newdata['patient_data']['facility_id'] = '';
    }

    if (array_key_exists('DOB', $newdata['patient_data'])) {
        $newdata['patient_data']['DOB'] = newPatientSaveNormalizeDateValue($newdata['patient_data']['DOB']);
    }

    if (array_key_exists('care_team_facility', $newdata['patient_data'])) {
        $newdata['patient_data']['care_team_facility'] = normalizePatientCareTeamFacilitiesForStorage($newdata['patient_data']['care_team_facility']);
    }

    if (empty($newdata['patient_data']['facility_id'])) {
        $newdata['patient_data']['facility_id'] = resolvePatientAssignedFacilityId($newdata['patient_data']['care_team_facility'] ?? '');
    }

    if (array_key_exists('care_team_provider', $newdata['patient_data'])) {
        $newdata['patient_data']['care_team_provider'] = normalizePatientCareTeamProvidersForStorage($newdata['patient_data']['care_team_provider']);
    }

    $missingRequiredFieldMessage = newPatientSaveGetMissingRequiredFieldMessage($newdata['patient_data']);
    if ($missingRequiredFieldMessage !== '') {
        $alertmsg = $missingRequiredFieldMessage;
        if (newPatientSaveShouldRespondJson()) {
            newPatientSaveRespondJson([
                'success' => false,
                'message' => $alertmsg,
            ]);
        }
        require("new.php");
        exit();
    }

    if (!$duplicateOverride) {
        $duplicatePatients = getPotentialDuplicatePatients($newdata['patient_data']);
        if (!empty($duplicatePatients)) {
            $alertmsg = newPatientSaveBuildDuplicateMessage($duplicatePatients, $newdata['patient_data']);
            if (newPatientSaveIsAjaxRequest()) {
                newPatientSaveRespondJson([
                    'success' => false,
                    'message' => $alertmsg,
                    'reload_form' => true,
                ]);
            }
            require("new.php");
            exit();
        }
    }

    // Use the global helper to use the PatientService to create a new patient
    // The result contains the pid, so use that to set the global session pid
    try {
        $patientValidator = new PatientValidator();
        $processingResult = $patientValidator->validate($newdata['patient_data'], PatientValidator::DATABASE_INSERT_CONTEXT);

        if (!$processingResult->isValid()) {
            throw new RuntimeException(newPatientSaveFormatProcessingErrors($processingResult));
        }

        $pid = (int) updatePatientData(null, $newdata['patient_data'], true);

        error_log("New patient save - updatePatientData result: " . $pid);

        if ($pid === false || empty($pid)) {
            throw new RuntimeException(xl('Unable to save the patient record. Please try again.'));
        }

        if (!newPatientSaveIsAjaxRequest() && !newPatientSaveIsFinderModalCreate()) {
            setpid($pid);
        }

        // Keep post-create steps best-effort so a successful patient insert does not surface
        // as a failed create in the modal.
        $assigned_facility_id = null;
        try {
            if (!empty($_SESSION['facilityId'])) {
                $assigned_facility_id = (int) $_SESSION['facilityId'];
                error_log("New patient save - Assigning facility_id: " . $assigned_facility_id . " from selected facility session to patient: " . $pid);
            } elseif (isset($_SESSION['facility_id']) && !empty($_SESSION['facility_id'])) {
                $assigned_facility_id = (int) $_SESSION['facility_id'];
                error_log("New patient save - Assigning facility_id: " . $assigned_facility_id . " from legacy user session to patient: " . $pid);
            } else {
                $user_facility_result = sqlQuery("SELECT facility_id FROM users WHERE id = ?", array($_SESSION['authUserID']));
                if ($user_facility_result) {
                    $user_facility_data = null;
                    if (is_object($user_facility_result) && method_exists($user_facility_result, 'FetchRow')) {
                        $user_facility_data = $user_facility_result->FetchRow();
                    } elseif (is_array($user_facility_result)) {
                        $user_facility_data = $user_facility_result;
                    }

                    if ($user_facility_data && !empty($user_facility_data['facility_id'])) {
                        $assigned_facility_id = $user_facility_data['facility_id'];
                        error_log("New patient save - Assigning facility_id: " . $assigned_facility_id . " from user default to patient: " . $pid);
                    }
                }
            }

            if ($assigned_facility_id) {
                sqlStatement("UPDATE patient_data SET facility_id = ? WHERE pid = ?", array($assigned_facility_id, $pid));
                error_log("New patient save - Successfully assigned facility_id to patient " . $pid);
            }
        } catch (Throwable $e) {
            error_log("New patient save - Non-fatal facility assignment error: " . $e->getMessage());
        }

        if (!$GLOBALS['omit_employers']) {
            try {
                updateEmployerData($pid, $newdata['employer_data'], true);
            } catch (Throwable $e) {
                error_log("New patient save - Non-fatal employer update error: " . $e->getMessage());
            }
        }

        try {
            newHistoryData($pid);
        } catch (Throwable $e) {
            error_log("New patient save - Non-fatal history initialization error: " . $e->getMessage());
        }
        foreach (["primary", "secondary", "tertiary"] as $insuranceType) {
            try {
                newInsuranceData($pid, $insuranceType);
            } catch (Throwable $e) {
                error_log("New patient save - Non-fatal insurance initialization error ({$insuranceType}): " . $e->getMessage());
            }
        }

        if (newPatientSaveShouldRespondJson()) {
            newPatientSaveRespondJson([
                'success' => true,
                'pid' => $pid,
                'redirect' => "$rootdir/patient_file/summary/demographics.php?set_pid=" . attr_url($pid) . "&is_new=1",
                'message' => xl('Patient created successfully!'),
            ]);
        }
    } catch (Throwable $e) {
        error_log("New patient save - Fatal create error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        error_log($e->getTraceAsString());
        $alertmsg = trim((string) $e->getMessage());
        if ($alertmsg === '' || $alertmsg === 'Patient creation failed in updatePatientData()') {
            $alertmsg = xl('Patient creation failed. Please review the form and try again.');
        }
        if (newPatientSaveIsAjaxRequest()) {
            newPatientSaveRespondJson([
                'success' => false,
                'message' => $alertmsg,
            ]);
        }
        require("new.php");
        exit();
    }
    } else {
        error_log("New patient save - form_create field missing or empty. POST data: " . print_r($_POST, true));
        throw new RuntimeException(xl('Patient creation request was incomplete. Please refresh the page and try again.'));
    }
} catch (Throwable $e) {
    error_log("New patient save - Unhandled bootstrap error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    error_log($e->getTraceAsString());
    newPatientSaveRespondFailure($e->getMessage());
}
?>
<html>
<body>
<script>
<?php
if ($alertmsg) {
    echo "alert(" . js_escape($alertmsg) . ");\n";
}

  $redirectUrl = "$rootdir/patient_file/summary/demographics.php?set_pid=" . attr_url($pid) . "&is_new=1";
  echo "window.location='$redirectUrl';\n";
?>
</script>

</body>
</html>
