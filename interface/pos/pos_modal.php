<?php
/**
 * POS Modal System - Overlay for Patient Finder
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Team
 * @copyright Copyright (c) 2024 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/payment.inc.php");
require_once("$srcdir/options.inc.php");

use OpenEMR\Billing\PaymentGateway;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

function posGetCurrentSessionFacilityId(): int
{
    if (!empty($_SESSION['facilityId'])) {
        return (int) $_SESSION['facilityId'];
    }

    if (!empty($_SESSION['facility_id'])) {
        return (int) $_SESSION['facility_id'];
    }

    $userResult = sqlFetchArray(sqlStatement("SELECT facility_id FROM users WHERE id = ?", [$_SESSION['authUserID'] ?? 0]));
    return !empty($userResult['facility_id']) ? (int) $userResult['facility_id'] : 0;
}

function posGetSelectedFacilityId(): int
{
    return posGetCurrentSessionFacilityId();
}

function posGetAllowedFacilities(): array
{
    $userFacilities = array();
    $facilityQuery = "SELECT DISTINCT facility_id FROM users_facility WHERE tablename = 'users' AND table_id = ?";
    $facilityResult = sqlStatement($facilityQuery, array($_SESSION['authUserID'] ?? 0));

    while ($facilityRow = sqlFetchArray($facilityResult)) {
        if (!empty($facilityRow['facility_id'])) {
            $userFacilities[] = (int) $facilityRow['facility_id'];
        }
    }

    if (empty($userFacilities)) {
        $userQuery = "SELECT facility_id FROM users WHERE id = ?";
        $userResult = sqlQuery($userQuery, array($_SESSION['authUserID'] ?? 0));
        if (is_array($userResult) && !empty($userResult['facility_id'])) {
            $userFacilities[] = (int) $userResult['facility_id'];
        }
    }

    return $userFacilities;
}

function posPatientBelongsToFacility(array $patientData, int $selectedFacilityId): bool
{
    if ($selectedFacilityId <= 0) {
        return true;
    }

    $patientFacilityId = (int) ($patientData['facility_id'] ?? 0);
    if ($patientFacilityId > 0 && $patientFacilityId === $selectedFacilityId) {
        return true;
    }

    $careTeamFacilities = trim((string) ($patientData['care_team_facility'] ?? ''));
    if ($careTeamFacilities === '') {
        return false;
    }

    $ids = array_filter(array_map('trim', explode('|', $careTeamFacilities)), static function ($value) {
        return $value !== '';
    });

    return in_array((string) $selectedFacilityId, $ids, true);
}

function posGetTodayPatientNumberForPid($pid): ?int
{
    $pid = (int) $pid;
    if ($pid <= 0) {
        return null;
    }

    $hasPatientNumber = sqlFetchArray(sqlStatement("SHOW COLUMNS FROM `pos_transactions` LIKE 'patient_number'"));
    if (empty($hasPatientNumber)) {
        return null;
    }

    $hasVoided = sqlFetchArray(sqlStatement("SHOW COLUMNS FROM `pos_transactions` LIKE 'voided'"));
    $voidFilter = !empty($hasVoided) ? " AND COALESCE(voided, 0) = 0" : "";
    $facilityFilter = "";
    $binds = [$pid];

    if (sqlFetchArray(sqlStatement("SHOW COLUMNS FROM `pos_transactions` LIKE 'facility_id'"))) {
        $facilityId = posGetSelectedFacilityId();
        if ($facilityId <= 0) {
            $row = sqlFetchArray(sqlStatement("SELECT facility_id FROM patient_data WHERE pid = ? LIMIT 1", [$pid]));
            $facilityId = (int) ($row['facility_id'] ?? 0);
        }

        if ($facilityId > 0) {
            $facilityFilter = " AND facility_id = ?";
            $binds[] = $facilityId;
        }
    }

    $row = sqlFetchArray(sqlStatement(
        "SELECT patient_number
           FROM pos_transactions
          WHERE pid = ?
            AND DATE(created_date) = CURDATE()
            AND patient_number IS NOT NULL
            AND patient_number > 0
            AND transaction_type != 'void'" . $voidFilter . $facilityFilter . "
          ORDER BY id DESC
          LIMIT 1",
        $binds
    ));

    return isset($row['patient_number']) ? (int) $row['patient_number'] : null;
}

function posPatientNumberUsedByAnotherPatientToday($patientNumber, $pid): bool
{
    $patientNumber = (int) $patientNumber;
    $pid = (int) $pid;
    if ($patientNumber <= 0) {
        return false;
    }

    $hasPatientNumber = sqlFetchArray(sqlStatement("SHOW COLUMNS FROM `pos_transactions` LIKE 'patient_number'"));
    if (empty($hasPatientNumber)) {
        return false;
    }

    $hasVoided = sqlFetchArray(sqlStatement("SHOW COLUMNS FROM `pos_transactions` LIKE 'voided'"));
    $voidFilter = !empty($hasVoided) ? " AND COALESCE(voided, 0) = 0" : "";
    $facilityFilter = "";
    $binds = [$patientNumber, $pid];

    if (sqlFetchArray(sqlStatement("SHOW COLUMNS FROM `pos_transactions` LIKE 'facility_id'"))) {
        $facilityId = posGetSelectedFacilityId();
        if ($facilityId <= 0) {
            $row = sqlFetchArray(sqlStatement("SELECT facility_id FROM patient_data WHERE pid = ? LIMIT 1", [$pid]));
            $facilityId = (int) ($row['facility_id'] ?? 0);
        }

        if ($facilityId > 0) {
            $facilityFilter = " AND facility_id = ?";
            $binds[] = $facilityId;
        }
    }

    $row = sqlFetchArray(sqlStatement(
        "SELECT pid
           FROM pos_transactions
          WHERE patient_number = ?
            AND pid != ?
            AND DATE(created_date) = CURDATE()
            AND transaction_type != 'void'" . $voidFilter . $facilityFilter . "
          ORDER BY id DESC
          LIMIT 1",
        $binds
    ));

    return !empty($row);
}

function posGetPriceOverrideMailerGlobals(): array
{
    $globalNames = [
        'EMAIL_METHOD',
        'SMTP_HOST',
        'SMTP_PORT',
        'SMTP_USER',
        'SMTP_PASS',
        'SMTP_SECURE',
        'SMTP_Auth',
        'patient_reminder_sender_email',
        'practice_return_email_path',
    ];

    $placeholders = implode(',', array_fill(0, count($globalNames), '?'));
    $result = sqlStatement("SELECT gl_name, gl_value FROM globals WHERE gl_name IN ($placeholders)", $globalNames);

    $settings = [];
    while ($row = sqlFetchArray($result)) {
        $settings[$row['gl_name']] = $row['gl_value'];
    }

    return $settings;
}

function posSendPriceOverrideCodeEmail(string $toEmail, string $adminName, string $code, string $requester, string $notes): array
{
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'The selected administrator does not have a valid email address.'];
    }

    require_once(__DIR__ . '/../../library/classes/postmaster.php');

    $mailerGlobals = posGetPriceOverrideMailerGlobals();
    $GLOBALS['EMAIL_METHOD'] = $mailerGlobals['EMAIL_METHOD'] ?? ($GLOBALS['EMAIL_METHOD'] ?? 'PHPMAIL');
    $GLOBALS['SMTP_HOST'] = $mailerGlobals['SMTP_HOST'] ?? ($GLOBALS['SMTP_HOST'] ?? '');
    $GLOBALS['SMTP_PORT'] = $mailerGlobals['SMTP_PORT'] ?? ($GLOBALS['SMTP_PORT'] ?? '');
    $GLOBALS['SMTP_USER'] = $mailerGlobals['SMTP_USER'] ?? ($GLOBALS['SMTP_USER'] ?? '');
    $GLOBALS['SMTP_PASS'] = $mailerGlobals['SMTP_PASS'] ?? ($GLOBALS['SMTP_PASS'] ?? '');
    $GLOBALS['SMTP_SECURE'] = $mailerGlobals['SMTP_SECURE'] ?? ($GLOBALS['SMTP_SECURE'] ?? '');
    $GLOBALS['patient_reminder_sender_email'] = $mailerGlobals['patient_reminder_sender_email'] ?? ($GLOBALS['patient_reminder_sender_email'] ?? '');
    $GLOBALS['practice_return_email_path'] = $mailerGlobals['practice_return_email_path'] ?? ($GLOBALS['practice_return_email_path'] ?? '');
    $GLOBALS['HTML_CHARSET'] = $GLOBALS['HTML_CHARSET'] ?? 'UTF-8';
    $smtpAuth = $mailerGlobals['SMTP_Auth'] ?? ($GLOBALS['SMTP_Auth'] ?? true);
    $smtpAuth = filter_var($smtpAuth, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($smtpAuth === null) {
        $smtpAuth = true;
    }
    $GLOBALS['SMTP_Auth'] = $smtpAuth;
    $GLOBALS['SMTP_AUTH'] = $smtpAuth;

    $senderCandidates = [
        trim((string) ($GLOBALS['patient_reminder_sender_email'] ?? '')),
        trim((string) ($GLOBALS['practice_return_email_path'] ?? '')),
        trim((string) ($GLOBALS['SMTP_USER'] ?? '')),
    ];

    $senderEmail = '';
    foreach ($senderCandidates as $candidate) {
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            $senderEmail = $candidate;
            break;
        }
    }

    if ($senderEmail === '') {
        return ['success' => false, 'error' => 'OpenEMR email sender is not configured.'];
    }

    $safeAdmin = htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8');
    $safeRequester = htmlspecialchars($requester, ENT_QUOTES, 'UTF-8');
    $safeNotes = nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8'));
    $html = '
        <div style="font-family: Arial, sans-serif; color: #243142; line-height: 1.5;">
            <h2 style="margin: 0 0 12px; color: #243142;">Price Override Approval Code</h2>
            <p>' . $safeRequester . ' requested remote approval for a POS price override.</p>
            <div style="font-size: 28px; font-weight: 700; letter-spacing: 4px; padding: 14px 18px; background: #f6f8fb; border: 1px solid #dfe5ee; display: inline-block; margin: 10px 0;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</div>
            <p>This code expires in 10 minutes. Share it only if you approve this override request.</p>
            <p><strong>Requested admin:</strong> ' . $safeAdmin . '</p>
            <p><strong>Reason:</strong><br>' . $safeNotes . '</p>
            <p style="margin-top: 24px; color: #6b7280; font-size: 13px;">Thank you for using JacTrac.</p>
        </div>';

    try {
        $mail = new MyMailer();
        $mail->AddReplyTo($senderEmail, 'JACtrac POS');
        $mail->SetFrom($senderEmail, 'JACtrac POS');
        $mail->AddAddress($toEmail, $adminName ?: $toEmail);
        $mail->Subject = 'JACtrac Price Override Code';
        $mail->MsgHTML($html);
        $mail->IsHTML(true);
        $mail->AltBody = "Price override approval code: {$code}\n\nRequested by: {$requester}\nReason: {$notes}\n\nThis code expires in 10 minutes.\n\nThank you for using JacTrac.";

        if ($mail->Send()) {
            return ['success' => true];
        }

        return ['success' => false, 'error' => 'Email send failed: ' . $mail->ErrorInfo];
    } catch (Exception $e) {
        error_log("POS price override approval email failed: " . $e->getMessage());
        return ['success' => false, 'error' => 'Email send failed: ' . $e->getMessage()];
    }
}

function posGetPriceOverrideAdminEmailUsers(): array
{
    $admins = [];
    $result = sqlStatement(
        "SELECT DISTINCT
                u.id,
                u.username,
                u.fname,
                u.lname,
                COALESCE(NULLIF(u.google_signin_email, ''), NULLIF(u.email, '')) AS admin_email
           FROM users u
           JOIN gacl_aro aro
             ON aro.section_value = 'users'
            AND aro.value = u.username
           JOIN gacl_groups_aro_map gm
             ON gm.aro_id = aro.id
           JOIN gacl_aro_groups ag
             ON ag.id = gm.group_id
            AND ag.value = 'admin'
          WHERE u.username != ''
            AND u.active = 1
            AND COALESCE(NULLIF(u.google_signin_email, ''), NULLIF(u.email, '')) IS NOT NULL
          ORDER BY u.fname, u.lname, u.username"
    );

    while ($row = sqlFetchArray($result)) {
        $email = trim((string) ($row['admin_email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $name = trim((string) (($row['fname'] ?? '') . ' ' . ($row['lname'] ?? '')));
        if ($name === '') {
            $name = (string) $row['username'];
        }

        $admins[] = [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'name' => $name,
            'email' => $email,
        ];
    }

    return $admins;
}

function posEnsurePriceOverrideCodeTable(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    sqlStatement(
        "CREATE TABLE IF NOT EXISTS pos_price_override_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            requester_user_id INT NOT NULL,
            admin_user_id INT NOT NULL,
            admin_username VARCHAR(255) NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            used TINYINT(1) NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_requester_active (requester_user_id, used, expires_at),
            KEY idx_admin_user (admin_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ready = true;
}

// Handle AJAX request for patient data
if (isset($_GET['action']) && $_GET['action'] === 'get_patient_data') {
    header('Content-Type: application/json');
    
    try {
        $pid = intval($_GET['pid'] ?? 0);
        if (!$pid) {
            echo json_encode(['success' => false, 'error' => 'No patient ID provided']);
            exit;
        }
        
        // Get patient data
        $patient_data = getPatientData($pid, 'fname,mname,lname,pubpid,DOB,phone_home,phone_cell,email,sex,facility_id,care_team_facility');
        
        if (!$patient_data) {
            echo json_encode(['success' => false, 'error' => 'Patient not found']);
            exit;
        }

        $selectedFacilityId = posGetSelectedFacilityId();
        if ($selectedFacilityId > 0 && !posPatientBelongsToFacility($patient_data, $selectedFacilityId)) {
            echo json_encode(['success' => false, 'error' => 'Patient does not belong to the selected facility']);
            exit;
        }
        
        // Get patient balance with error handling
        $patient_balance = 0;
        if (function_exists('get_patient_balance')) {
            try {
        $patient_balance = get_patient_balance($pid, true);
                // Ensure balance is numeric
                $patient_balance = is_numeric($patient_balance) ? floatval($patient_balance) : 0;
            } catch (Exception $e) {
                error_log("Error getting patient balance for PID $pid: " . $e->getMessage());
                $patient_balance = 0;
            }
        }
        
            echo json_encode([
                'success' => true,
                'patient' => array_merge($patient_data, ['balance' => $patient_balance])
            ]);
    } catch (Exception $e) {
        error_log("Error in get_patient_data action: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        echo json_encode([
            'success' => false,
            'error' => 'Server error: ' . $e->getMessage()
        ]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'validate_patient_number') {
    header('Content-Type: application/json');

    try {
        $pid = intval($_GET['pid'] ?? 0);
        $patientNumber = trim((string) ($_GET['patient_number'] ?? ''));

        if ($pid <= 0) {
            echo json_encode(['success' => false, 'error' => 'No patient ID provided']);
            exit;
        }

        if ($patientNumber === '') {
            echo json_encode(['success' => true, 'valid' => false, 'message' => 'Please enter a Patient Number.']);
            exit;
        }

        if (!preg_match('/^\d+$/', $patientNumber) || (int) $patientNumber <= 0) {
            echo json_encode(['success' => true, 'valid' => false, 'message' => 'Please enter a valid numeric Patient Number.']);
            exit;
        }

        $todaysPatientNumber = posGetTodayPatientNumberForPid($pid);
        if ($todaysPatientNumber !== null) {
            if ((int) $patientNumber !== $todaysPatientNumber) {
                echo json_encode([
                    'success' => true,
                    'valid' => false,
                    'message' => 'This patient already has a Patient Number for today. Please use the same Patient Number for this patient today.',
                    'locked_number' => $todaysPatientNumber
                ]);
                exit;
            }

            echo json_encode(['success' => true, 'valid' => true, 'message' => '', 'locked_number' => $todaysPatientNumber]);
            exit;
        }

        if (posPatientNumberUsedByAnotherPatientToday((int) $patientNumber, $pid)) {
            echo json_encode(['success' => true, 'valid' => false, 'message' => 'Patient Number already exists for today. Please enter a unique Patient Number.']);
            exit;
        }

        echo json_encode(['success' => true, 'valid' => true, 'message' => '']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request for patient search
if (isset($_GET['action']) && $_GET['action'] === 'search_patients') {
    try {
        $search = $_GET['search'] ?? '';
        
        if (strlen($search) >= 2) {
            $patients = array();
            
            // Build facility filter for patient search
            $facilityFilter = "";
            $facilityParams = array();
            $selectedFacilityId = posGetSelectedFacilityId();

            if ($selectedFacilityId > 0) {
                $facilityFilter = " AND (p.facility_id = ? OR FIND_IN_SET(?, REPLACE(COALESCE(p.care_team_facility, ''), '|', ',')) > 0)";
                $facilityParams = [$selectedFacilityId, $selectedFacilityId];
            } elseif (!empty($GLOBALS['restrict_user_facility'])) {
                $userFacilities = posGetAllowedFacilities();
                if (!empty($userFacilities)) {
                    $facilityPlaceholders = implode(',', array_fill(0, count($userFacilities), '?'));
                    $facilityFilter = " AND p.facility_id IN ($facilityPlaceholders)";
                    $facilityParams = $userFacilities;
                }
            }
            
            // Search patients by name, ID, or phone
            $sql = "SELECT p.pid, p.pubpid, p.fname, p.lname, p.DOB, p.phone_home, p.phone_cell, p.sex,
                           COALESCE(p.phone_cell, p.phone_home) as phone
                    FROM patient_data p 
                    WHERE (p.fname LIKE ? OR p.lname LIKE ? OR p.pubpid LIKE ? OR p.phone_home LIKE ? OR p.phone_cell LIKE ?)
                    AND p.pid > 0
                    $facilityFilter
                    ORDER BY p.lname, p.fname
                    LIMIT 20";
            
            $searchTerm = '%' . $search . '%';
            $searchParams = array_merge(array($searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm), $facilityParams);
            $res = sqlStatement($sql, $searchParams);
            
            if ($res) {
                while ($row = sqlFetchArray($res)) {
                    // Try to get balance, but don't fail if function doesn't exist
                    $balance = 0;
                    if (function_exists('get_patient_balance')) {
                        $balance = get_patient_balance($row['pid'], true);
                    }
                    
                    $patients[] = array(
                        'pid' => $row['pid'],
                        'pubpid' => $row['pubpid'],
                        'fname' => $row['fname'],
                        'lname' => $row['lname'],
                        'dob' => function_exists('oeFormatShortDate') ? oeFormatShortDate($row['DOB']) : $row['DOB'],
                        'phone' => $row['phone'],
                        'sex' => $row['sex'],
                        'balance' => $balance
                    );
                }
            }
            
            echo json_encode([
                'success' => true,
                'patients' => $patients
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Search term too short'
            ]);
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage()
        ]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_recent_transactions') {
    header('Content-Type: application/json');

    try {
        $limit = max(1, min(20, intval($_GET['limit'] ?? 8)));
        $selectedDate = trim((string) ($_GET['transaction_date'] ?? ''));
        $transactions = [];

        $hasVoided = sqlFetchArray(sqlStatement("SHOW COLUMNS FROM `pos_transactions` LIKE 'voided'"));
        $voidFilter = !empty($hasVoided) ? " AND COALESCE(pt.voided, 0) = 0 AND pt.transaction_type != 'void'" : " AND pt.transaction_type != 'void'";

        $sql = "SELECT pt.receipt_number, pt.transaction_type, pt.amount, pt.created_date,
                       pt.payment_method, pt.pid, pd.fname, pd.lname
                  FROM pos_transactions AS pt
            INNER JOIN patient_data AS pd ON pd.pid = pt.pid";
        $binds = [];
        $selectedFacilityId = posGetSelectedFacilityId();
        $hasWhere = false;

        if ($selectedFacilityId > 0) {
            $sql .= " WHERE pt.facility_id = ?";
            $binds[] = $selectedFacilityId;
            $hasWhere = true;
        }

        if ($selectedDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
            $sql .= $hasWhere ? " AND DATE(pt.created_date) = ?" : " WHERE DATE(pt.created_date) = ?";
            $binds[] = $selectedDate;
            $hasWhere = true;
        }

        $sql .= $hasWhere ? $voidFilter : " WHERE 1 = 1" . $voidFilter;

        $sql .= " ORDER BY pt.created_date DESC";

        if ($selectedDate === '') {
            $sql .= " LIMIT ?";
            $binds[] = $limit;
        }

        $res = sqlStatement($sql, $binds);

        while ($row = sqlFetchArray($res)) {
            $transactions[] = [
                'receipt_number' => $row['receipt_number'] ?? '',
                'transaction_type' => $row['transaction_type'] ?? '',
                'amount' => (float) ($row['amount'] ?? 0),
                'created_date' => $row['created_date'] ?? '',
                'payment_method' => $row['payment_method'] ?? '',
                'pid' => (int) ($row['pid'] ?? 0),
                'patient_name' => trim(($row['fname'] ?? '') . ' ' . ($row['lname'] ?? '')),
            ];
        }

        echo json_encode([
            'success' => true,
            'transactions' => $transactions,
        ]);
    } catch (Exception $e) {
        error_log("Error in get_recent_transactions action: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage(),
        ]);
    }
    exit;
}

        // Handle AJAX request for admin credentials verification
        if (isset($_GET['action']) && $_GET['action'] === 'verify_admin') {
            $username = $_GET['username'] ?? '';
            $password = $_GET['password'] ?? '';
            
            if (empty($username) || empty($password)) {
                echo json_encode(['success' => false, 'error' => 'Username and password required']);
                exit;
            }
            
            // Check if current user is logged in
            if (!isset($_SESSION['authUserID'])) {
                echo json_encode(['success' => false, 'error' => 'Not authenticated']);
                exit;
            }
            
            // Get administrator user by username from users table
            $user_res = sqlQuery("SELECT id, username, authorized FROM users WHERE username = ? AND authorized = 1", array($username));
            
            if (!$user_res) {
                echo json_encode(['success' => false, 'error' => 'Administrator not found or insufficient privileges']);
                exit;
            }
            
            // Get the user row data
            $user_row = sqlFetchArray($user_res);
            if (!$user_row) {
                echo json_encode(['success' => false, 'error' => 'Administrator not found or insufficient privileges']);
                exit;
            }
            
            // Get the password hash from users_secure table
            $secure_res = sqlQuery("SELECT password FROM users_secure WHERE id = ?", array($user_row['id']));
            
            if (!$secure_res) {
                echo json_encode(['success' => false, 'error' => 'Administrator password not found']);
                exit;
            }
            
            // Get the secure row data
            $secure_row = sqlFetchArray($secure_res);
            if (!$secure_row) {
                echo json_encode(['success' => false, 'error' => 'Administrator password not found']);
                exit;
            }
            
            // Verify the provided password matches the administrator's password
            $stored_password = $secure_row['password'];
            $is_valid = password_verify($password, $stored_password);
            
            if ($is_valid) {
                $_SESSION['price_override_verified'] = true;
                $_SESSION['price_override_admin'] = $username;
                $_SESSION['price_override_verified_at'] = time(); // timestamp
                echo json_encode(['success' => true, 'message' => 'Administrator verification successful']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid administrator credentials']);
            }
            exit;
        }

        // Handle AJAX request to email a short-lived override approval code to an administrator
        if (isset($_GET['action']) && $_GET['action'] === 'request_price_override_code') {
            if (!isset($_SESSION['authUserID'])) {
                echo json_encode(['success' => false, 'error' => 'Not authenticated']);
                exit;
            }

            $username = trim((string) ($_POST['admin_username'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($username === '') {
                echo json_encode(['success' => false, 'error' => 'Administrator username is required']);
                exit;
            }

            if ($notes === '') {
                echo json_encode(['success' => false, 'error' => 'Override notes are required before requesting a code']);
                exit;
            }

            $adminResult = sqlStatement(
                "SELECT DISTINCT u.id, u.username, u.fname, u.lname, u.email
                   FROM users u
                   JOIN gacl_aro aro
                     ON aro.section_value = 'users'
                    AND aro.value = u.username
                   JOIN gacl_groups_aro_map gm
                     ON gm.aro_id = aro.id
                   JOIN gacl_aro_groups ag
                     ON ag.id = gm.group_id
                    AND ag.value = 'admin'
                  WHERE u.username = ?
                    AND u.authorized = 1
                    AND u.active = 1
                  LIMIT 1",
                [$username]
            );
            $admin = sqlFetchArray($adminResult);

            if (empty($admin) || empty($admin['id'])) {
                echo json_encode(['success' => false, 'error' => 'Administrator not found, inactive, or missing administrator role']);
                exit;
            }

            $adminEmail = trim((string) ($admin['email'] ?? ''));
            if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'error' => 'That administrator does not have a valid email address on file']);
                exit;
            }

            $code = (string) random_int(100000, 999999);
            $adminName = trim((string) (($admin['fname'] ?? '') . ' ' . ($admin['lname'] ?? '')));
            if ($adminName === '') {
                $adminName = (string) $admin['username'];
            }
            $requester = (string) ($_SESSION['authUser'] ?? ('User ID ' . ($_SESSION['authUserID'] ?? 'unknown')));

            posEnsurePriceOverrideCodeTable();
            $requesterUserId = (int) ($_SESSION['authUserID'] ?? 0);
            sqlStatement(
                "UPDATE pos_price_override_codes
                    SET used = 1
                  WHERE requester_user_id = ?
                    AND used = 0",
                [$requesterUserId]
            );
            sqlStatement(
                "INSERT INTO pos_price_override_codes
                    (requester_user_id, admin_user_id, admin_username, code_hash, expires_at)
                 VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))",
                [
                    $requesterUserId,
                    (int) $admin['id'],
                    (string) $admin['username'],
                    password_hash($code, PASSWORD_DEFAULT)
                ]
            );

            $emailResult = posSendPriceOverrideCodeEmail($adminEmail, $adminName, $code, $requester, $notes);
            if (!$emailResult['success']) {
                sqlStatement(
                    "UPDATE pos_price_override_codes
                        SET used = 1
                      WHERE requester_user_id = ?
                        AND used = 0",
                    [$requesterUserId]
                );
                echo json_encode(['success' => false, 'error' => $emailResult['error'] ?? 'Unable to email approval code']);
                exit;
            }

            echo json_encode(['success' => true, 'message' => 'Approval code emailed to the administrator. It expires in 10 minutes.']);
            exit;
        }

        if (isset($_GET['action']) && $_GET['action'] === 'get_price_override_admins') {
            if (!isset($_SESSION['authUserID'])) {
                echo json_encode(['success' => false, 'error' => 'Not authenticated']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'admins' => posGetPriceOverrideAdminEmailUsers(),
            ]);
            exit;
        }

        // Handle AJAX request to verify a remote approval code
        if (isset($_GET['action']) && $_GET['action'] === 'verify_price_override_code') {
            if (!isset($_SESSION['authUserID'])) {
                echo json_encode(['success' => false, 'error' => 'Not authenticated']);
                exit;
            }

            $code = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? ''));
            if ($code === '') {
                echo json_encode(['success' => false, 'error' => 'Approval code is required']);
                exit;
            }

            posEnsurePriceOverrideCodeTable();
            $requesterUserId = (int) ($_SESSION['authUserID'] ?? 0);
            $codeRowResult = sqlStatement(
                "SELECT id, admin_username, code_hash, attempts, expires_at
                   FROM pos_price_override_codes
                  WHERE requester_user_id = ?
                    AND used = 0
                  ORDER BY id DESC
                  LIMIT 1",
                [$requesterUserId]
            );
            $codeRow = sqlFetchArray($codeRowResult);

            if (empty($codeRow) || empty($codeRow['id'])) {
                echo json_encode(['success' => false, 'error' => 'No active approval code. Request a new code first.']);
                exit;
            }

            $codeId = (int) $codeRow['id'];
            $hash = (string) $codeRow['code_hash'];
            $attempts = (int) $codeRow['attempts'];
            $adminUsername = (string) ($codeRow['admin_username'] ?? 'remote admin');

            if (strtotime((string) $codeRow['expires_at']) < time()) {
                sqlStatement("UPDATE pos_price_override_codes SET used = 1 WHERE id = ?", [$codeId]);
                echo json_encode(['success' => false, 'error' => 'Approval code expired. Request a new code.']);
                exit;
            }

            if ($attempts >= 5) {
                sqlStatement("UPDATE pos_price_override_codes SET used = 1 WHERE id = ?", [$codeId]);
                echo json_encode(['success' => false, 'error' => 'Too many invalid attempts. Request a new code.']);
                exit;
            }

            sqlStatement("UPDATE pos_price_override_codes SET attempts = attempts + 1 WHERE id = ?", [$codeId]);
            if (!password_verify($code, $hash)) {
                echo json_encode(['success' => false, 'error' => 'Invalid approval code']);
                exit;
            }

            $_SESSION['price_override_verified'] = true;
            $_SESSION['price_override_admin'] = $adminUsername . ' (remote email code)';
            $_SESSION['price_override_verified_at'] = time();
            sqlStatement("UPDATE pos_price_override_codes SET used = 1 WHERE id = ?", [$codeId]);

            echo json_encode(['success' => true, 'message' => 'Remote approval code accepted']);
            exit;
        }

        // Handle AJAX request to log price override notes
        if (isset($_GET['action']) && $_GET['action'] === 'log_price_override') {

            if (!isset($_SESSION['authUserID'])) {
                echo json_encode(['success' => false, 'error' => 'Not authenticated']);
                exit;
            }

            // Ensure admin was verified in Step 6
            if (empty($_SESSION['price_override_verified']) || empty($_SESSION['price_override_admin'])) {
                echo json_encode(['success' => false, 'error' => 'Override not authorized']);
                exit;
            }

            // Optional: expire authorization after 5 minutes
            $verifiedAt = intval($_SESSION['price_override_verified_at'] ?? 0);
            if (!$verifiedAt || (time() - $verifiedAt) > 300) {
                unset($_SESSION['price_override_verified'], $_SESSION['price_override_admin'], $_SESSION['price_override_verified_at']);
                echo json_encode(['success' => false, 'error' => 'Override authorization expired. Please verify again.']);
                exit;
            }

            $pid = intval($_POST['pid'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');

            if ($notes === '') {
                echo json_encode(['success' => false, 'error' => 'Override notes are required']);
                exit;
            }

            sqlStatement(
                "INSERT INTO pos_price_override_log (pid, overridden_by, override_notes)
                VALUES (?, ?, ?)",
                [
                    $pid,
                    $_SESSION['price_override_admin'],
                    $notes
                ]
            );

            echo json_encode(['success' => true]);
            exit;
        }

// Ensure user is logged in
if (!isset($_SESSION['authUserID'])) {
    die(xlt('Not authorized'));
}

// Get patient ID from URL
$pid = $_GET['pid'] ?? 0;
$patient_data = null;

// Check if dispense tracking parameter is set
$dispense_tracking = isset($_GET['dispense_tracking']) && $_GET['dispense_tracking'] == '1';

if ($pid) {
    try {
    $patient_data = getPatientData($pid, 'fname,mname,lname,pubpid,DOB,phone_home,phone_cell,email,sex');
    if (!$patient_data) {
        // Don't die, just show a warning
        $patient_error = xlt('Patient not found');
        }
    } catch (Exception $e) {
        error_log("Error getting patient data for PID $pid: " . $e->getMessage());
        $patient_error = xlt('Error loading patient data');
        $patient_data = null;
    }
}

// Get patient balance from URL parameter (from finder table) or calculate if not provided
$patient_balance = 0;
if ($pid) {
    if (isset($_GET['balance']) && is_numeric($_GET['balance'])) {
        // Use balance from finder table
        $patient_balance = floatval($_GET['balance']);
    } else {
        // Fallback: calculate balance with error handling
        if (function_exists('get_patient_balance')) {
            try {
        $patient_balance = get_patient_balance($pid, true);
                // Ensure balance is numeric
                $patient_balance = is_numeric($patient_balance) ? floatval($patient_balance) : 0;
            } catch (Exception $e) {
                error_log("Error calculating patient balance for PID $pid: " . $e->getMessage());
                $patient_balance = 0;
            }
        }
    }
    if ($patient_balance < 0) {
        $patient_balance = 0;
    }
}

$todays_patient_number = $pid ? posGetTodayPatientNumberForPid((int) $pid) : null;
$price_override_admin_email_users = posGetPriceOverrideAdminEmailUsers();

// Load tax rates from database
function getTaxRates() {
    $taxes = array();
    $res = sqlStatement(
        "SELECT option_id, title, option_value " .
        "FROM list_options WHERE list_id = 'taxrate' AND activity = 1 ORDER BY seq, title, option_id"
    );
    while ($row = sqlFetchArray($res)) {
        $taxes[$row['option_id']] = array(
            'id' => $row['option_id'],
            'name' => $row['title'],
            'rate' => floatval($row['option_value'])
        );
    }
    return $taxes;
}
$tax_rates = getTaxRates();

// If no tax rates are configured, add a default tax rate for testing
if (empty($tax_rates)) {
    $tax_rates = array(
        'default' => array(
            'id' => 'default',
            'name' => 'Sales Tax',
            'rate' => 8.5
        )
    );
}

Header::setupHeader(['opener']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo xlt('Point of Sale'); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://js.stripe.com/v3/"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            color: #333;
            overflow: hidden;
        }

        /* Keep visit-type checkbox fill/check color blue instead of browser-default purple */
        .consultation-section input[type="checkbox"] {
            accent-color: #0d6efd;
        }

        /* Notification animations */
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        .pos-container {
            width: 100%;
            height: 100vh;
            background: white;
            display: flex;
            flex-direction: column;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        /* Header */
        .pos-header {
            background: white;
            color: #333;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e9ecef;
        }

        .pos-title {
            font-size: 28px;
            font-weight: 700;
            color: #333;
        }

        .header-actions {
            display: flex;
            align-items: center;
        }

        .close-btn {
            background: transparent;
            border: none;
            color: #333;
            font-size: 24px;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
        }

        .close-btn:hover {
            background: #f8f9fa;
            transform: scale(1.1);
        }

        /* Defective button styling for dispense tracking */
        .defective-btn-dispense {
            transition: all 0.3s ease !important;
        }

        .defective-btn-dispense:hover {
            background: #e9ecef !important;
            transform: scale(1.05) !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15) !important;
            border-color: #adb5bd !important;
        }

        .defective-btn-dispense:active {
            background: #dee2e6 !important;
            transform: scale(0.95) !important;
        }

        /* Multi-step Form */
        .form-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }



        /* Form Steps */
        .form-step {
            display: none;
            flex: 1;
            flex-direction: column;
            overflow: hidden;
        }

        .form-step.active {
            display: flex;
        }

        .step-content {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
        }

        .step-content h2 {
            margin: 0 0 30px 0;
            font-size: 24px;
            font-weight: 700;
            color: #333;
            text-align: center;
        }

        /* Patient Details */
        .patient-details {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 15px;
            padding: 30px;
            max-width: 600px;
            margin: 0 auto;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #f8f9fa;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-row label {
            font-weight: 600;
            color: #333;
            font-size: 16px;
        }

        .detail-row span {
            color: #6c757d;
            font-size: 16px;
        }

        .balance-row {
            background: #fff3cd;
            border-radius: 8px;
            padding: 15px 20px;
            margin-top: 20px;
        }

        .balance-amount {
            color: #e74c3c !important;
            font-weight: 700 !important;
            font-size: 18px !important;
        }

        .patient-error {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            max-width: 600px;
            margin: 0 auto;
        }

        .patient-error i {
            font-size: 48px;
            color: #f39c12;
            margin-bottom: 20px;
        }

        .patient-error p {
            color: #856404;
            font-size: 18px;
            margin: 0 0 10px 0;
        }

        .patient-error small {
            color: #856404;
            font-size: 14px;
        }

        /* Weight Validation Status */
        .weight-validation-status {
            padding: 15px 30px;
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-left: 4px solid #f39c12;
            margin: 0;
        }
        
        .validation-message {
            display: flex;
            align-items: center;
            color: #856404;
            font-size: 14px;
            font-weight: 500;
        }
        
        .validation-message i {
            margin-right: 10px;
            font-size: 16px;
        }
        
        .validation-message.success {
            color: #155724;
        }
        
        .validation-message.success i {
            color: #28a745;
        }
        
        .validation-message.error {
            color: #721c24;
        }
        
        .validation-message.error i {
            color: #dc3545;
        }

        /* Step Actions */
        .step-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 30px;
            background: white;
            border-top: 1px solid #e9ecef;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }

        .btn-primary {
            background: #007bff;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-primary:hover {
            background: #0056b3;
            transform: translateY(-1px);
        }

        .btn-primary:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
        }
        /* Checkout Content */
        .checkout-summary {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .checkout-summary h3 {
            margin: 0 0 20px 0;
            font-size: 20px;
            font-weight: 700;
            color: #333;
        }

        .checkout-items {
            margin-bottom: 20px;
        }

        .checkout-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #f8f9fa;
        }

        .checkout-item:last-child {
            border-bottom: none;
        }

        .item-info strong {
            display: block;
            color: #333;
            font-size: 16px;
            margin-bottom: 5px;
        }

        .item-info small {
            color: #6c757d;
            font-size: 14px;
        }

        .item-price {
            font-weight: 600;
            color: #333;
            font-size: 16px;
        }

        .checkout-total {
            padding-top: 20px;
            border-top: 2px solid #e9ecef;
            text-align: right;
            font-size: 18px;
            color: #333;
        }

        .payment-methods {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .payment-methods h3 {
            margin: 0 0 20px 0;
            font-size: 20px;
            font-weight: 700;
            color: #333;
        }

        /* Payment Summary Styles (EMR-aligned) */
        .payment-summary {
            background: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 20px;
        }

        .payment-metrics {
            display: flex;
            gap: 12px;
        }

        .payment-metrics .metric {
            flex: 1;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 12px 14px;
        }

        .payment-metrics .metric .metric-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 4px;
        }

        .payment-metrics .metric .metric-value {
            font-size: 20px;
            font-weight: 700;
            color: #2c3e50;
            line-height: 1.2;
        }

        .payment-metrics .metric.total .metric-value { color: #007bff; }
        .payment-metrics .metric.paid .metric-value { color: #28a745; }
        .payment-metrics .metric.due .metric-value { color: #dc3545; }

        @media (max-width: 576px) {
            .payment-metrics { flex-direction: column; }
        }

        .payments-list {
            margin-top: 12px;
            font-size: 14px;
            color: #2c3e50;
        }
        .payments-list h4 {
            margin: 10px 0 6px 0;
            font-size: 15px;
            color: #34495e;
        }
        .payments-list ul {
            list-style: none;
            padding-left: 0;
            margin: 0;
        }
        .payments-list li {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px dashed #e9ecef;
        }

        .close-btn:hover {
            background: rgba(255,255,255,0.2);
            border-color: rgba(255,255,255,0.5);
            transform: scale(1.05);
        }

        .close-btn:active {
            transform: scale(0.95);
        }

        /* Main Content */
        .pos-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 20px 30px;
            overflow: hidden;
        }

        /* Search Section */
        .search-section {
            margin-bottom: 30px;
            position: relative;
        }

        .search-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .search-input {
            flex: 1;
            display: flex;
            align-items: center;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 15px 20px;
            transition: all 0.3s;
        }

        .search-input:focus-within {
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }

        .search-icon {
            color: #6c757d;
            margin-right: 15px;
            font-size: 18px;
        }

        .search-input input {
            flex: 1;
            border: none;
            outline: none;
            font-size: 16px;
            background: transparent;
            color: #333;
        }

        .search-input input::placeholder {
            color: #6c757d;
        }

        .add-btn {
            background: #f8f9fa;
            color: #333;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            cursor: pointer;
            font-size: 20px;
            transition: all 0.3s;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .add-btn:hover {
            background: #e9ecef;
            border-color: #6c757d;
        }

        /* Search Results */
        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            width: 100%;
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.25);
            max-height: 400px;
            overflow-y: auto;
            z-index: 999999;
            display: none;
            margin-top: 5px;
        }

        .search-result-item {
            padding: 12px 15px;
            border-bottom: 1px solid #e9ecef;
            cursor: pointer;
            transition: background 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #ffffff;
            min-height: 60px;
        }

        .search-result-item:hover {
            background: #f8f9fa;
        }

        .search-result-item-disabled {
            opacity: 0.7;
            cursor: not-allowed !important;
        }

        .search-result-item-disabled:hover {
            background: #f8f9fa !important;
        }

        .search-result-item:last-child {
            border-bottom: none;
        }

        .search-result-info {
            flex: 1;
            margin-right: 15px;
        }

        .search-result-name {
            font-weight: 500;
            color: #333;
            margin-bottom: 5px;
            font-size: 15px;
        }

        .search-result-details {
            font-size: 14px;
            color: #6c757d;
            margin-bottom: 5px;
        }

        .search-result-lot {
            font-size: 12px;
            color: #e67e22;
            font-weight: 500;
            background: #fff3e0;
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-block;
        }

        .search-result-price {
            font-weight: 600;
            color: #333;
            font-size: 20px;
            background: transparent;
            padding: 0;
            border-radius: 0;
            min-width: 60px;
            text-align: right;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            height: 100%;
        }

        /* Order Summary */
        .order-summary {
            flex: 1;
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 15px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .order-header {
            background: white;
            padding: 20px 25px;
            border-bottom: 1px solid #e9ecef;
        }

        .order-header h3 {
            margin: 0;
            font-weight: 700;
            font-size: 20px;
            color: #333;
        }

        .order-table {
            background: white;
            overflow: hidden;
            flex: 1;
        }
        
        .order-table table {
            width: 100%;
            border-collapse: collapse;
            font-size: 15px;
        }
        
        .order-table th {
            background: white;
            color: #333;
            font-weight: 600;
            padding: 15px 20px;
            text-align: left;
            border-bottom: 2px solid #e9ecef;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .order-table td {
            padding: 15px 20px;
            border-bottom: 1px dashed #e9ecef;
            vertical-align: middle;
            font-size: 15px;
        }

        .order-table tr:hover {
            background: #f8f9fa;
        }

        .product-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .product-icon {
            width: 35px;
            height: 35px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }

        .product-icon.drug {
            background: #6c757d;
        }

        .product-icon.consultation {
            background: #6c757d;
        }
        .product-icon.injection {
    background: #4A90E2;
        }

        .product-icon.shake {
            background: #6c757d;
        }

        .product-icon.consultation {
            background: #4A90E2;
        }

        .product-icon.bloodwork {
            background: #4A90E2;
        }

        .product-name {
            font-weight: 500;
            color: #333;
        }

        .quantity-control {
            display: flex;
            align-items: center;
            background: #6c757d;
            border-radius: 6px;
            overflow: hidden;
            width: fit-content;
        }

        .qty-btn {
            background: #6c757d;
            border: none;
            color: white;
            width: 30px;
            height: 30px;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
            transition: background 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .qty-btn:hover {
            background: #5a6268;
        }

        .qty-input {
            width: 30px;
            text-align: center;
            border: none;
            background: #6c757d;
            color: white;
            font-weight: 600;
            font-size: 16px;
            padding: 8px 0;
            -webkit-appearance: none;
            -moz-appearance: textfield;
        }
        
        .qty-input::-webkit-outer-spin-button,
        .qty-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .qty-input:focus {
            outline: none;
        }

        /* Dispense input now uses the same styling as quantity input */

        .price {
            font-weight: 600;
            color: #333;
        }

        .remove-btn {
            background: transparent;
            color: #BF1542;
            border: none;
            padding: 8px;
            cursor: pointer;
            transition: all 0.3s;
            border-radius: 4px;
            font-size: 16px;
        }

        .remove-btn:hover {
            background: rgba(191, 21, 66, 0.1);
            color: #A01238;
            transform: scale(1.1);
        }

        .btn-clear-cart:hover {
            background: #A01238;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(191, 21, 66, 0.2);
        }
        .btn-override {
            background: #007bff;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-override:hover {
            background: #0056b3;
            transform: translateY(-1px);
        }

        .btn-override.active {
            background: #28a745;
            color: white;
        }

        .price-display {
            cursor: default;
        }

        .price-display.editable {
            cursor: pointer;
            background: #fff3cd;
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid #ffeaa7;
        }

        .price-display.editable:hover {
            background: #ffeaa7;
        }

        /* Modal Styles */
        .modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            animation: modalSlideIn 0.3s ease-out;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .modal-header h3 {
            margin: 0;
            color: #333;
            font-size: 18px;
            font-weight: 600;
        }
        
        .modal-header .close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s ease;
        }
        
        .modal-header .close:hover {
            background-color: #f8f9fa;
        }
        
        .modal-body {
            margin-bottom: 20px;
        }
        
        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            padding-top: 15px;
            border-top: 1px solid #e9ecef;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Order Total */
        .order-total {
            background: white;
            padding: 20px 25px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 15px;
        }

        .total-label {
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }

        .total-amount {
            font-size: 24px;
            font-weight: 700;
            color: #333;
        }

        /* Footer */
        .pos-footer {
            background: white;
            padding: 20px 30px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: center;
        }

        .checkout-btn {
            background: #007bff;
            color: white;
            border: none;
            border-radius: 10px;
            padding: 15px 40px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            min-width: 200px;
        }

        .checkout-btn:hover {
            background: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
        }

        /* Patient Info */
        .patient-info {
            background: #e8f5e8;
            border: 1px solid #c3e6cb;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .patient-info h4 {
            color: #155724;
            margin-bottom: 10px;
            font-size: 16px;
        }

        .patient-info p {
            color: #155724;
            margin: 5px 0;
            font-size: 14px;
        }



        /* Responsive */
        @media (max-width: 768px) {
            .pos-content {
                padding: 15px;
            }
            
            .pos-header {
                padding: 15px 20px;
            }
            
            .pos-footer {
                padding: 15px 20px;
                flex-direction: column;
                gap: 15px;
            }
        }

        /* Payment Method Styles */
        .method-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .method-tab {
            flex: 1;
            padding: 12px 20px;
            border: 2px solid #e9ecef;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 500;
        }

        .method-tab.active {
            border-color: #007bff;
            background: #007bff;
            color: white;
        }

        .method-tab:hover {
            border-color: #007bff;
        }

        .payment-form {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            font-size: 16px;
        }

        .card-element {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 12px;
            background: white;
        }

        .error-message {
            color: #e74c3c;
            font-size: 14px;
            margin-top: 8px;
            min-height: 20px;
        }

        .change-display {
            background: #e8f5e8;
            color: #27ae60;
            padding: 10px;
            border-radius: 6px;
            margin-top: 10px;
            font-weight: 600;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="pos-container">
        <!-- Header -->
        <div class="pos-header">
            <h1 class="pos-title">Point of Sale <span id="cart-counter" style="margin-left: 10px; font-size: 14px; color: #6c757d; font-weight: normal;"></span></h1>
            <div class="header-actions">
                <?php if ($pid): ?>
                        <button onclick="toggleRefundSection()" class="btn btn-secondary" style="margin-right: 10px; background: #6c757d; border: 1px solid #6c757d; color: white; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onmouseover="this.style.backgroundColor='#5a6268'; this.style.borderColor='#5a6268'" onmouseout="this.style.backgroundColor='#6c757d'; this.style.borderColor='#6c757d'">
                    <i class="fas fa-undo"></i> Refunds
                </button>
                <?php $current_facility_id = posGetCurrentSessionFacilityId(); ?>
                <button onclick="toggleDispenseTracking()" class="btn btn-secondary" style="margin-right: 10px; background: #6c757d; border: 1px solid #6c757d; color: white; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onmouseover="this.style.backgroundColor='#5a6268'; this.style.borderColor='#5a6268'" onmouseout="this.style.backgroundColor='#6c757d'; this.style.borderColor='#6c757d'">
                    <i class="fas fa-pills"></i> <?php echo xlt('Dispense Tracking'); ?>
                </button>
        <button onclick="toggleWeightTracker()" class="btn btn-secondary" style="margin-right: 10px; background: #6c757d; border: 1px solid #6c757d; color: white; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onmouseover="this.style.backgroundColor='#5a6268'; this.style.borderColor='#5a6268'" onmouseout="this.style.backgroundColor='#6c757d'; this.style.borderColor='#6c757d'">
            <i class="fas fa-weight"></i> Weight Tracking
        </button>
                <a href="pos_patient_transaction_history.php?pid=<?php echo attr($pid); ?>" class="btn btn-secondary" style="background: #6c757d; border: 1px solid #6c757d; color: white; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onmouseover="this.style.backgroundColor='#5a6268'; this.style.borderColor='#5a6268'" onmouseout="this.style.backgroundColor='#6c757d'; this.style.borderColor='#6c757d'">
                    <i class="fas fa-history"></i> <?php echo xlt('Transaction History'); ?>
                </a>
                

                <?php endif; ?>
            </div>
        </div>
        <!-- Multi-step Form Container -->
        <div class="form-container">




            <!-- Step 1: Bill for Drug -->
            <div class="form-step active" id="step-1">
                <div class="step-content">
                    <!-- Patient Information Summary -->
                    <?php if ($patient_data): ?>
                    <!-- Patient Search Section (Hidden by default) -->
                    <div id="patient-search-section" style="display: none; background: white; border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin-bottom: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <div style="display: flex; align-items: center; margin-bottom: 10px;">
                            <i class="fas fa-search" style="color: #666; margin-right: 10px;"></i>
                            <input type="text" id="patient-search-input" placeholder="Search patients by name, ID, or phone number..." style="flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                            <button onclick="closePatientSearch()" style="background: #dc3545; color: white; border: none; border-radius: 4px; padding: 8px 12px; margin-left: 10px; cursor: pointer;">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div id="patient-search-results" class="search-results" style="position: relative; top: auto; left: auto; right: auto; margin-top: 5px; border: 1px solid #e9ecef;"></div>
                    </div>
                    
                    <div style="position: relative; margin-bottom: 20px;">
                        <div class="patient-summary" style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #007bff;">
                            <div style="display: flex; gap: 60px; font-size: 16px; color: #555; align-items: center; flex-wrap: wrap;">
                                <div>
                                    <strong class="patient-name" style="font-size: 18px; color: #333;"><?php echo text($patient_data['fname'] . ' ' . $patient_data['lname']); ?></strong>
                                </div>
                                <div>
                                    <strong style="font-size: 16px;">Mobile:</strong> <?php echo text($patient_data['phone_cell'] ?: $patient_data['phone_home'] ?: 'N/A'); ?>
                                </div>
                                <div>
                                    <strong style="font-size: 16px;">DOB:</strong> <?php echo text(oeFormatShortDate($patient_data['DOB'])); ?>
                                </div>
                                <div>
                                    <strong style="font-size: 16px;">Gender:</strong> <?php echo text(ucfirst($patient_data['sex'] ?? 'N/A')); ?>
                                </div>
                                <?php if ($patient_balance > 0): ?>
                                <div style="color: #dc3545;">
                                    <strong style="font-size: 16px;">Balance:</strong> $<?php echo number_format($patient_balance, 2); ?>
                                </div>
                                <?php endif; ?>
                                <div style="color: #495057;">
                                    <strong style="font-size: 16px;">Credit Balance:</strong> <span id="patientCreditBalance" style="color: #28a745;">$0.00</span>
                                </div>
                                <div id="creditTransferButtonContainer" style="display: none;">
                                    <button onclick="toggleCreditTransferSection()" class="btn btn-sm" style="background: #007bff; color: white; border: none; padding: 4px 8px; border-radius: 4px; font-size: 11px; cursor: pointer; transition: background-color 0.3s ease;" onmouseover="this.style.backgroundColor='#0056b3'" onmouseout="this.style.backgroundColor='#007bff'">
                                        <i class="fas fa-exchange-alt"></i> Transfer
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button onclick="showPatientSearch()" style="position: absolute; top: -8px; right: -8px; background: #007bff; color: white; border: none; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.2);" title="Change Patient">
                            <i class="fas fa-pencil-alt" style="font-size: 12px;"></i>
                        </button>
                    </div>
                    
                    <!-- Weight Tracker Section -->
                    <div id="weight-tracker-section" style="display: none; margin-bottom: 20px;">
                        <div class="order-summary" style="background: #ffffff; border: 1px solid #dee2e6; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                            <div class="order-header" style="background: #f8f9fa; color: #495057; padding: 20px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #dee2e6;">
                                <h3 style="margin: 0; display: flex; align-items: center; gap: 12px; font-size: 20px; font-weight: 600; color: #333;">
                                    <i class="fas fa-weight" style="color: #17a2b8; font-size: 22px;"></i> Weight Tracker
                                </h3>
                                <button onclick="hideWeightTracker()" style="background: #dc3545; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; transition: all 0.3s ease;">
                                    <i class="fas fa-times"></i> Close
                                </button>
                            </div>
                            
                            <div style="padding: 20px;">
        <!-- Weight Summary Row -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <!-- Current Weight Display -->
            <div id="current-weight-display" style="background: #e3f2fd; border: 1px solid #2196f3; border-radius: 8px; padding: 15px; text-align: center;">
                <h4 style="margin: 0 0 10px 0; color: #1976d2; font-size: 16px; display: flex; align-items: center; justify-content: center; gap: 8px;">
                    <i class="fas fa-weight" style="color: #1976d2;"></i> Current Weight
                </h4>
                <div id="current-weight-value" style="font-size: 24px; font-weight: bold; color: #1976d2;">Loading...</div>
                <div id="current-weight-date" style="font-size: 12px; color: #666; margin-top: 5px;"></div>
            </div>
            
            <!-- Total Weight Loss Display -->
            <div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: 15px; text-align: center;">
                <h4 style="margin: 0 0 10px 0; color: #856404; font-size: 16px; display: flex; align-items: center; justify-content: center; gap: 8px;">
                    <i class="fas fa-chart-line" style="color: #f39c12;"></i> Total Weight Loss
                </h4>
                <div id="total-weight-loss" style="font-size: 24px; font-weight: bold; color: #28a745;">-0.0 lbs</div>
                <div style="font-size: 12px; color: #666; margin-top: 5px;">Since first recording</div>
            </div>
        </div>

        <!-- Weight Chart Section -->
        <div style="background: #ffffff; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #e9ecef; padding-bottom: 10px;">
                <h4 style="margin: 0; color: #495057; font-size: 18px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-chart-area" style="color: #6c757d; font-size: 20px;"></i> Weight Progress Chart
                </h4>
                <div style="display:flex; gap:10px; align-items:center;">
                    <button onclick="emailWeightChart()" id="email-chart-btn" title="Email the weight progress chart and report summary" style="background: #0d6efd; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.3s ease; display: flex; align-items: center; gap: 8px;" onmouseover="this.style.backgroundColor='#0b5ed7'" onmouseout="this.style.backgroundColor='#0d6efd'">
                        <i class="fas fa-envelope"></i> Email Chart
                    </button>
                    <button onclick="downloadWeightChart()" id="download-chart-btn" title="Download the weight progress chart as a high-quality PNG image to share with patients" style="background: #28a745; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.3s ease; display: flex; align-items: center; gap: 8px;" onmouseover="this.style.backgroundColor='#218838'" onmouseout="this.style.backgroundColor='#28a745'">
                        <i class="fas fa-download"></i> Download Chart
                    </button>
                </div>
            </div>
            <div style="position: relative; height: 300px; width: 100%;">
                <canvas id="weightChart" style="width: 100%; height: 100%;"></canvas>
            </div>
            <div id="chart-loading" style="text-align: center; padding: 50px; color: #6c757d; display: none;">
                <i class="fas fa-spinner fa-spin" style="font-size: 20px; margin-right: 10px;"></i> Loading chart data...
            </div>
            <div id="chart-no-data" style="text-align: center; padding: 50px; color: #6c757d; display: none;">
                <i class="fas fa-chart-line" style="font-size: 40px; margin-bottom: 10px; opacity: 0.3;"></i>
                <p style="margin: 0; font-size: 16px;">No weight data available for chart</p>
                <p style="margin: 5px 0 0 0; font-size: 14px; color: #999;">Add some weight entries to see the progress chart</p>
            </div>
        </div>
                                
                                <!-- Weight Entry Form -->
                                <div style="background: #ffffff; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                    <h4 style="margin: 0 0 20px 0; color: #495057; font-size: 18px; display: flex; align-items: center; gap: 10px; border-bottom: 2px solid #e9ecef; padding-bottom: 10px;">
                                        <i class="fas fa-plus-circle" style="color: #28a745; font-size: 20px;"></i> Record New Weight
                                    </h4>
                                    
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: end;">
                                        <div>
                                            <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #495057; font-size: 14px;">Weight (lbs) <span style="color: #dc3545;">*</span></label>
                                            <input type="number" id="new-weight-input" step="0.1" min="50" max="1000" 
                                                   style="width: 100%; padding: 12px; border: 2px solid #ced4da; border-radius: 6px; font-size: 16px; font-weight: 600; transition: border-color 0.3s ease;"
                                                   placeholder="Enter weight" 
                                                   onfocus="this.style.borderColor='#007bff'"
                                                   onblur="this.style.borderColor='#ced4da'">
                                        </div>
                                        <div>
                                            <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #495057; font-size: 14px;">Notes (Optional)</label>
                                            <input type="text" id="weight-notes-input" 
                                                   style="width: 100%; padding: 12px; border: 2px solid #ced4da; border-radius: 6px; font-size: 14px; transition: border-color 0.3s ease;"
                                                   placeholder="Add notes..."
                                                   onfocus="this.style.borderColor='#007bff'"
                                                   onblur="this.style.borderColor='#ced4da'">
                                        </div>
                                    </div>
                                    
                                    <div style="margin-top: 20px; text-align: right;">
                                        <button onclick="saveWeight()" id="save-weight-btn" 
                                                style="background: #28a745; color: white; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"
                                                onmouseover="this.style.backgroundColor='#218838'; this.style.transform='translateY(-1px)'"
                                                onmouseout="this.style.backgroundColor='#28a745'; this.style.transform='translateY(0)'">
                                            <i class="fas fa-save"></i> Save Weight
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Weight History -->
                                <div style="background: #ffffff; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #e9ecef; padding-bottom: 10px;">
                                        <h4 style="margin: 0; color: #495057; font-size: 18px; display: flex; align-items: center; gap: 10px;">
                                            <i class="fas fa-history" style="color: #6c757d; font-size: 20px;"></i> Weight History
                                        </h4>
                                    </div>
                                    
                                    <div id="weight-history-container" style="max-height: 400px; overflow-y: auto; border: 1px solid #e9ecef; border-radius: 6px;">
                                        <div id="weight-history-loading" style="text-align: center; padding: 30px; color: #6c757d;">
                                            <i class="fas fa-spinner fa-spin" style="font-size: 20px; margin-right: 10px;"></i> Loading weight history...
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Credit Transfer Section -->
                    <div id="transfer-section" style="display: none; margin-bottom: 20px;">
                        <div class="order-summary" style="background: #ffffff; border: 1px solid #dee2e6; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); max-width: 700px; margin: 0 auto;">
                            <div class="order-header" style="background: #f8f9fa; color: #495057; padding: 12px 15px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #dee2e6;">
                                <h3 style="margin: 0; display: flex; align-items: center; gap: 10px; font-size: 16px; font-weight: 600; color: #333;">
                                    <i class="fas fa-exchange-alt" style="color: #007bff; font-size: 18px;"></i> Credit Transfer
                                </h3>
                                <div style="display: flex; align-items: center; gap: 15px;">
                                    <div style="text-align: right;">
                                        <div style="font-size: 11px; color: #6c757d; margin-bottom: 2px;">Balance</div>
                                        <div style="font-size: 15px; font-weight: 700; color: #28a745;" id="transferSectionCreditBalance">$0.00</div>
                                    </div>
                                    <button onclick="toggleCreditTransferSection()" class="btn btn-secondary" style="background: #6c757d; border: 1px solid #6c757d; color: white; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500; transition: all 0.3s ease;">
                                        <i class="fas fa-times"></i> Close
                                    </button>
                                </div>
                            </div>
                            
                            <div id="transfer-content" style="padding: 20px;">
                                <!-- Patient Search Section (Matching Main POS Style) -->
                                <div style="margin-bottom: 20px;">
                                            <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #495057; font-size: 14px;">
                                        <i class="fas fa-user" style="color: #007bff; margin-right: 6px;"></i> Transfer To Patient
                                            </label>
                                    <div id="inlineCreditTransferPatientSearchSection" style="position: relative;">
                                        <div class="search-input" style="position: relative;">
                                            <i class="fas fa-search search-icon"></i>
                                            <input type="text" id="inlineCreditTransferPatientSearch" placeholder="Search patients by name, ID, or phone number..." 
                                                   style="width: 100%; padding: 10px 10px 10px 45px; border: 1px solid #ced4da; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.3s ease;"
                                                   onkeyup="searchInlineCreditTransferPatient(this.value)"
                                                   onfocus="if(this.value.length >= 2) searchInlineCreditTransferPatient(this.value)"
                                                   onblur="setTimeout(() => { document.getElementById('inlineCreditTransferPatientResults').style.display = 'none'; }, 200);">
                                            </div>
                                        <div id="inlineCreditTransferPatientResults" class="search-results" style="position: absolute; top: 100%; left: 0; width: 100%; margin-top: 5px; z-index: 999999;"></div>
                                                    </div>
                                    <div id="inlineCreditTransferPatientDisplay" style="display: none; margin-top: 12px;">
                                        <div class="patient-summary" style="background: #f8f9fa; padding: 12px 15px; border-radius: 8px; border-left: 4px solid #007bff; position: relative;">
                                            <div style="display: flex; gap: 30px; font-size: 14px; color: #555; align-items: center; flex-wrap: wrap;">
                                                <div>
                                                    <strong style="font-size: 15px; color: #333;" id="inlineSelectedCreditTransferPatientName"></strong>
                                                </div>
                                                <div>
                                                    <strong style="font-size: 13px;">Mobile:</strong> <span id="inlineSelectedCreditTransferPatientMobile"></span>
                                                </div>
                                                <div>
                                                    <strong style="font-size: 13px;">DOB:</strong> <span id="inlineSelectedCreditTransferPatientDob"></span>
                                                </div>
                                                <div>
                                                    <strong style="font-size: 13px;">Gender:</strong> <span id="inlineSelectedCreditTransferPatientGender"></span>
                                                </div>
                                            </div>
                                            <button onclick="editInlineCreditTransferPatient()" style="position: absolute; top: -8px; right: -8px; background: #007bff; color: white; border: none; border-radius: 50%; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.2);" title="Change Patient">
                                                <i class="fas fa-pencil-alt" style="font-size: 11px;"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        
                                <!-- Transfer Amount (Read-only, Full Amount) -->
                                <div style="margin-bottom: 20px;">
                                            <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #495057; font-size: 14px;">
                                                <i class="fas fa-dollar-sign" style="color: #28a745; margin-right: 6px;"></i> Transfer Amount
                                            </label>
                                    <div style="background: #f8f9fa; padding: 12px; border-radius: 6px; border: 2px solid #28a745;">
                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                            <div>
                                                <div style="font-size: 11px; color: #6c757d; margin-bottom: 3px;">Full Credit Balance</div>
                                                <div style="font-size: 20px; font-weight: 700; color: #28a745;" id="inlineCreditTransferAmountDisplay">$0.00</div>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="hidden" id="inlineCreditTransferAmount">
                                        </div>
                                        
                                        <!-- Transfer Reason -->
                                <div style="margin-bottom: 20px;">
                                            <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #495057; font-size: 14px;">
                                        <i class="fas fa-comment" style="color: #6c757d; margin-right: 6px;"></i> Reason (Optional)
                                            </label>
                                    <input type="text" id="inlineCreditTransferReason" placeholder="Enter reason for transfer..." 
                                           style="width: 100%; padding: 10px; border: 1px solid #ced4da; border-radius: 6px; font-size: 14px; outline: none; transition: border-color 0.3s ease;">
                                        </div>
                                        
                                        <!-- Transfer Button -->
                                <div style="text-align: center; margin-top: 20px;">
                                            <button onclick="processInlineCreditTransfer()" class="btn btn-primary" id="processInlineCreditTransferBtn" disabled
                                            style="background: #007bff; border: 1px solid #007bff; color: white; padding: 12px 30px; border-radius: 6px; cursor: pointer; font-size: 15px; font-weight: 600; transition: all 0.3s ease; box-shadow: 0 3px 5px rgba(0,0,0,0.1); min-width: 180px;">
                                        <i class="fas fa-exchange-alt"></i> Transfer Credit Balance
                                            </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Refund Section -->
                    <div id="refund-section" style="display: none; margin-bottom: 20px;">
                        <div class="order-summary" style="background: #ffffff; border: 1px solid #dee2e6; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                            <div class="order-header" style="background: #f8f9fa; color: #495057; padding: 20px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #dee2e6;">
                                <h3 style="margin: 0; display: flex; align-items: center; gap: 12px; font-size: 20px; font-weight: 600; color: #333;">
                                    <i class="fas fa-undo" style="color: #dc3545; font-size: 22px;"></i> Refund Management
                                </h3>
                                <div style="display: flex; align-items: center; gap: 15px;">
                                    <div style="text-align: right;">
                                        <div style="font-size: 14px; color: #6c757d; margin-bottom: 2px;">Patient Credit Balance</div>
                                        <div style="font-size: 18px; font-weight: 700; color: #28a745;" id="refundSectionCreditBalance">$0.00</div>
                                    </div>
                                    <button onclick="hideRefundSection()" class="btn btn-secondary" style="background: #6c757d; border: 1px solid #6c757d; color: white; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                        <i class="fas fa-times"></i> Close
                                    </button>
                                </div>
                            </div>
                            
                            <div id="refund-content" style="padding: 25px;">
                                <!-- Professional Refund Management Interface -->
                                <div id="refundModalContent">
                                    <!-- Step 1: Refund Type Selection -->
                                    <div id="refundTypeSelection" style="margin-bottom: 30px;">
                                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 20px;">
                                            <div style="width: 30px; height: 30px; background: #007bff; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px;">1</div>
                                            <label style="font-weight: 600; color: #333; font-size: 18px;">
                                                <i class="fas fa-route"></i> Select Refund Type
                                            </label>
                                        </div>
                                        
                                        <!-- Refund Type Options -->
                                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                                            <div onclick="selectRefundType('dispense')" id="refundTypeDispense" style="padding: 25px; border: 2px solid #e9ecef; border-radius: 12px; cursor: pointer; transition: all 0.3s ease; background: #f8f9fa;" onmouseover="this.style.borderColor='#007bff'; this.style.backgroundColor='#e3f2fd'" onmouseout="this.style.borderColor='#e9ecef'; this.style.backgroundColor='#f8f9fa'">
                                                <div style="text-align: center;">
                                                    <i class="fas fa-pills" style="font-size: 32px; color: #ffc107; margin-bottom: 15px;"></i>
                                                    <h4 style="margin: 0; color: #333; font-size: 18px;">Remaining Dispense Refund</h4>
                                                </div>
                                            </div>
                                            
                                            <div onclick="selectRefundType('other')" id="refundTypeOther" style="padding: 25px; border: 2px solid #e9ecef; border-radius: 12px; cursor: pointer; transition: all 0.3s ease; background: #f8f9fa;" onmouseover="this.style.borderColor='#007bff'; this.style.backgroundColor='#e3f2fd'" onmouseout="this.style.borderColor='#e9ecef'; this.style.backgroundColor='#f8f9fa'">
                                                <div style="text-align: center;">
                                                    <i class="fas fa-undo" style="font-size: 32px; color: #dc3545; margin-bottom: 15px;"></i>
                                                    <h4 style="margin: 0; color: #333; font-size: 18px;">Other Refund</h4>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Step 2: Multi-Product Selection (for dispense refunds) -->
                                    <div id="dispenseRefundSection" style="display: none; margin-bottom: 30px;">
                                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 20px;">
                                            <div style="width: 30px; height: 30px; background: #28a745; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px;">2</div>
                                            <label style="font-weight: 600; color: #333; font-size: 18px;">
                                                <i class="fas fa-list"></i> Select Products for Return
                                            </label>
                                        </div>
                                        
                                        <!-- Search and Filter Controls -->
                                        <div style="background: white; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                            <div style="display: grid; grid-template-columns: 1fr auto auto; gap: 15px; align-items: center;">
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <i class="fas fa-search" style="color: #6c757d; font-size: 16px;"></i>
                                                    <input type="text" id="refund-search-input" placeholder="Search products with remaining dispense..." 
                                                           style="flex: 1; padding: 12px; border: 1px solid #ced4da; border-radius: 6px; font-size: 14px; outline: none; transition: border-color 0.3s ease;"
                                                           onkeyup="filterRefundItems(this.value)">
                                                </div>
                                                <button onclick="clearRefundSearch()" style="background: #6c757d; color: white; border: none; padding: 10px 15px; border-radius: 6px; cursor: pointer; font-size: 13px; transition: all 0.3s ease;">
                                                    <i class="fas fa-times"></i> Clear
                                                </button>
                                                <button onclick="selectAllRefundItems()" style="background: #007bff; color: white; border: none; padding: 10px 15px; border-radius: 6px; cursor: pointer; font-size: 13px; transition: all 0.3s ease;">
                                                    <i class="fas fa-check-square"></i> Select All
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <!-- Multi-Product Selection Table -->
                                        <div id="refundableItemsList" style="background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden;">
                                            <div style="text-align: center; padding: 40px; color: #6c757d;">
                                                <i class="fas fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 10px;"></i>
                                                <div>Loading products with remaining dispense...</div>
                                            </div>
                                        </div>
                                        
                                        <!-- Next Button -->
                                        <div id="nextButtonContainer" style="display: none; margin-top: 20px; text-align: right;">
                                            <button id="nextButton" onclick="proceedToRefundConfiguration()" style="background: #28a745; color: white; border: none; padding: 15px 30px; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: 600; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(40, 167, 69, 0.2);" onmouseover="this.style.backgroundColor='#218838'; this.style.transform='translateY(-2px)'" onmouseout="this.style.backgroundColor='#28a745'; this.style.transform='translateY(0)'">
                                                <i class="fas fa-arrow-right"></i> Proceed to Refund Configuration
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- Step 2: Manual Refund Entry (for other refunds) -->
                                    <div id="manualRefundSection" style="display: none; margin-bottom: 30px;">
                                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 20px;">
                                            <div style="width: 30px; height: 30px; background: #28a745; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px;">2</div>
                                            <label style="font-weight: 600; color: #333; font-size: 18px;">
                                                <i class="fas fa-edit"></i> Enter Refund Details
                                            </label>
                                        </div>
                                        
                                        <!-- Manual Refund Form -->
                                        <div style="background: white; border: 1px solid #dee2e6; border-radius: 8px; padding: 25px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: center;">
                                                <div>
                                                    <label style="display: block; margin-bottom: 12px; font-weight: 600; color: #495057; font-size: 16px;">
                                                        <i class="fas fa-dollar-sign" style="color: #28a745; margin-right: 8px;"></i> Refund Amount
                                                    </label>
                                                    <input type="number" id="manualRefundAmount" min="0.01" step="0.01" 
                                                           style="width: 100%; padding: 15px; border: 2px solid #ced4da; border-radius: 8px; font-size: 18px; outline: none; transition: border-color 0.3s ease; text-align: center; font-weight: 600;"
                                                           placeholder="Enter refund amount" onchange="updateManualRefundDisplay()" oninput="updateManualRefundDisplay()">
                                                </div>
                                                
                                                <div style="text-align: center;">
                                                    <label style="display: block; margin-bottom: 12px; font-weight: 600; color: #495057; font-size: 16px;">
                                                        <i class="fas fa-tag" style="color: #007bff; margin-right: 8px;"></i> Refund Amount Preview
                                                    </label>
                                                    <div style="font-size: 32px; font-weight: 700; color: #28a745; padding: 20px; background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); border-radius: 12px; border: 2px solid #28a745; box-shadow: 0 4px 12px rgba(40, 167, 69, 0.2);" id="manualRefundAmountDisplay">$0.00</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Refund Summary -->
                                    <div id="refundDetails" style="display: none;">
                                        
                                        <!-- Refund Summary Container -->
                                        <div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 25px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                                            <!-- Refund Order Summary -->
                                            <div id="refundItemDetails" style="margin-bottom: 25px; display: none;">
                                                <div style="background: white; border: 1px solid #dee2e6; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow: hidden;">
                                                    <div style="background: #f8f9fa; padding: 15px; border-bottom: 1px solid #dee2e6;">
                                                        <h4 style="margin: 0; color: #495057; font-size: 16px; font-weight: 600;">
                                                            <i class="fas fa-receipt"></i> Refund Order Summary
                                                        </h4>
                                                    </div>
                                                    <div id="refundItemInfo"></div>
                                                </div>
                                            </div>
                                            
                                            <!-- Refund Method Selection -->
                                            <div style="margin-bottom: 25px;">
                                                <h4 style="margin-bottom: 15px; color: #495057; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                                                    <i class="fas fa-credit-card" style="color: #007bff;"></i> Refund Method
                                                </h4>
                                                <div style="background: white; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                                        <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; padding: 15px; border: 1px solid #ced4da; border-radius: 6px; transition: all 0.3s ease; background: #f8f9fa;" onmouseover="this.style.borderColor='#007bff'; this.style.backgroundColor='#e3f2fd'" onmouseout="this.style.borderColor='#ced4da'; this.style.backgroundColor='#f8f9fa'">
                                                            <input type="radio" name="refundType" value="payment" checked onchange="toggleRefundType()" style="transform: scale(1.2);">
                                                            <div>
                                                                <div style="font-weight: 600; color: #495057; font-size: 16px; margin-bottom: 4px;">
                                                                    <i class="fas fa-credit-card" style="color: #007bff;"></i> Credit Card
                                                                </div>
                                                                <div style="font-size: 13px; color: #6c757d;">Refund to original payment method</div>
                                                            </div>
                                                        </label>
                                                        <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; padding: 15px; border: 1px solid #ced4da; border-radius: 6px; transition: all 0.3s ease; background: #f8f9fa;" onmouseover="this.style.borderColor='#007bff'; this.style.backgroundColor='#e3f2fd'" onmouseout="this.style.borderColor='#ced4da'; this.style.backgroundColor='#f8f9fa'">
                                                            <input type="radio" name="refundType" value="credit" onchange="toggleRefundType()" style="transform: scale(1.2);">
                                                            <div>
                                                                <div style="font-weight: 600; color: #495057; font-size: 16px; margin-bottom: 4px;">
                                                                    <i class="fas fa-wallet" style="color: #28a745;"></i> Credit Balance
                                                                </div>
                                                                <div style="font-size: 13px; color: #6c757d;">Add to patient account balance</div>
                                                            </div>
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                            

                                            
                                            <!-- Reason for Refund -->
                                            <div style="margin-bottom: 25px;">
                                                <h4 style="margin-bottom: 15px; color: #495057; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                                                    <i class="fas fa-comment" style="color: #007bff;"></i> Reason for Refund
                                                </h4>
                                                <div style="background: white; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                                    <textarea id="refundReason" placeholder="Enter reason for refund (e.g., Patient request, Product defect, etc.)..." 
                                                              style="width: 100%; padding: 15px; border: 1px solid #ced4da; border-radius: 6px; font-size: 14px; outline: none; resize: vertical; min-height: 80px; transition: border-color 0.3s ease;"
                                                              onfocus="this.style.borderColor='#007bff'" onblur="this.style.borderColor='#ced4da'"></textarea>
                                                </div>
                                            </div>
                                            
                                            <!-- Action Buttons -->
                                            <div style="text-align: center; padding-top: 20px; border-top: 1px solid #e9ecef;">
                                                <button onclick="goBackToProductSelection()" class="btn btn-secondary" style="padding: 15px 30px; font-size: 16px; font-weight: 600; border-radius: 8px; margin-right: 15px; background: #6c757d; border: 1px solid #6c757d; color: white; transition: all 0.3s ease;" onmouseover="this.style.backgroundColor='#5a6268'; this.style.borderColor='#5a6268'" onmouseout="this.style.backgroundColor='#6c757d'; this.style.borderColor='#6c757d'">
                                                    <i class="fas fa-arrow-left"></i> Back to Selection
                                                </button>
                                                <button onclick="processRefund()" class="btn btn-danger" id="processRefundBtn" style="display: none; padding: 15px 30px; font-size: 16px; font-weight: 600; border-radius: 8px; margin-right: 15px; background: #dc3545; border: 1px solid #dc3545; color: white; transition: all 0.3s ease;" onmouseover="this.style.backgroundColor='#c82333'; this.style.borderColor='#c82333'" onmouseout="this.style.backgroundColor='#dc3545'; this.style.borderColor='#dc3545'">
                                                    <i class="fas fa-undo"></i> Process Refund
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Dispense Tracking Section -->
                    <div id="dispense-tracking-section" style="display: none; margin-bottom: 20px;">
                        <div class="order-summary" style="background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <div class="order-header" style="background: #e9ecef; color: #495057; padding: 15px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #dee2e6;">
                                <h3 style="margin: 0; display: flex; align-items: center; gap: 8px; font-size: 18px; font-weight: 600;">
                                    <i class="fas fa-pills" style="color: #6c757d;"></i> Dispense Tracking
                                </h3>
                                <button onclick="hideDispenseTracking()" class="btn btn-secondary" style="background: #6c757d; border: 1px solid #6c757d; color: white; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                    <i class="fas fa-times"></i> Close
                                </button>
                            </div>
                            
                            <div id="dispense-tracking-content" style="padding: 15px;">
                                <!-- Dispense Tracking Content -->
                                <div id="dispense-content" style="display: block;">
                                <div style="text-align: center; padding: 40px 20px;">
                                    <div style="background: #ffffff; border: 1px solid #dee2e6; border-radius: 8px; padding: 30px; display: inline-block; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                                        <i class="fas fa-spinner fa-spin" style="font-size: 48px; color: #6c757d; margin-bottom: 15px;"></i>
                                        <h4 style="margin: 0 0 10px 0; color: #495057; font-weight: 600;">Loading Data</h4>
                                        <p style="margin: 0; color: #6c757d; font-size: 14px;">Fetching remaining dispense information...</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Transfer History Content -->
                                <div id="transfer-history-content" style="display: none;">
                                    <div style="text-align: center; padding: 40px 20px;">
                                        <div style="background: #ffffff; border: 1px solid #dee2e6; border-radius: 8px; padding: 30px; display: inline-block; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                                            <i class="fas fa-spinner fa-spin" style="font-size: 48px; color: #6c757d; margin-bottom: 15px;"></i>
                                            <h4 style="margin: 0 0 10px 0; color: #495057; font-weight: 600;">Loading Transfer History</h4>
                                            <p style="margin: 0; color: #6c757d; font-size: 14px;">Fetching transfer history...</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Patient Search Section (Visible when no patient selected) -->
                    <div id="patient-search-section" style="display: block; background: white; border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin-bottom: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <div style="display: flex; align-items: center; margin-bottom: 10px;">
                            <i class="fas fa-search" style="color: #666; margin-right: 10px;"></i>
                            <input type="text" id="patient-search-input" placeholder="Search patients by name, ID, or phone number..." style="flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        </div>
                        <div id="patient-search-results" class="search-results" style="position: relative; top: auto; left: auto; right: auto; margin-top: 5px; border: 1px solid #e9ecef;"></div>
                    </div>
                    <div id="recent-transactions-section" style="display: block; background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin-bottom: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 12px; flex-wrap: wrap;">
                            <h3 style="margin: 0; font-size: 18px; font-weight: 600; color: #333;">
                                <i class="fas fa-history" style="color: #6c757d; margin-right: 8px;"></i> Recent Transactions
                            </h3>
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <input type="date" id="recent-transactions-date" style="padding: 6px 10px; border: 1px solid #ced4da; border-radius: 6px; font-size: 13px; color: #495057;">
                                <button type="button" onclick="loadRecentTransactions()" style="background: #f8f9fa; color: #495057; border: 1px solid #ced4da; border-radius: 6px; padding: 6px 12px; cursor: pointer; font-size: 13px;">
                                    <i class="fas fa-filter"></i> Load
                                </button>
                                <button type="button" onclick="clearRecentTransactionsDateFilter()" style="background: #fff; color: #495057; border: 1px solid #ced4da; border-radius: 6px; padding: 6px 12px; cursor: pointer; font-size: 13px;">
                                    <i class="fas fa-xmark"></i> Clear
                                </button>
                            </div>
                        </div>
                        <div id="recent-transactions-content" style="min-height: 120px;">
                            <div style="text-align: center; color: #6c757d; padding: 30px 0;">
                                <i class="fas fa-spinner fa-spin" style="margin-right: 8px;"></i> Loading recent transactions...
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($patient_data): ?>
                                         <!-- Main POS Section -->
                     <div id="main-pos-section" style="display: block;">
                    <!-- Search Section -->
                    <div class="search-section">
                        <div class="search-container" style="display: flex; align-items: center; gap: 10px;">
                            <div class="search-input" style="flex: 1; position: relative;">
                                <i class="fas fa-search search-icon"></i>
                                <input
                                    type="text"
                                    id="search-input"
                                    name="pos_item_search"
                                    placeholder="<?php echo xlt('Search Medicine, Products, Office, etc'); ?>"
                                    autocomplete="off"
                                    autocorrect="off"
                                    autocapitalize="off"
                                    spellcheck="false"
                                >
                                <div id="search-results" class="search-results"></div>
                            </div>
                        </div>
                        
                        <!-- Consultation Checkbox Section -->
                       <div class="consultation-section" 
     style="margin-top: 15px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">

    <!-- Consultation -->
    <div class="consultation-checkbox"
         style="display: flex; align-items: center; gap: 8px; padding: 12px 16px; border: 1px solid #ddd; border-radius: 8px; background: #f8f9fa;">

        <input type="checkbox"
               id="consultation-checkbox"
               style="width: 18px; height: 18px; cursor: pointer;">

        <label for="consultation-checkbox"
               style="margin: 0; cursor: pointer; font-size: 16px; font-weight: 500; color: #333;">
            <i class="fas fa-stethoscope"></i> Consultation
        </label>
    </div>
<input type="hidden" name="visit_type" id="visit-type-hidden" value="-">
    <!-- Follow-Up -->
    <div class="consultation-checkbox" id="followup-box"
         style="display: flex; align-items: center; gap: 8px; padding: 12px 16px; border: 1px solid #ddd; border-radius: 8px; background: #f8f9fa;">

        <input type="checkbox"
               id="followup-checkbox"
               style="width: 18px; height: 18px; cursor: pointer;">

        <label for="followup-checkbox"
               style="margin: 0; cursor: pointer; font-size: 16px; font-weight: 500; color: #333;">
            <i class="fas fa-user-clock"></i> Follow-Up
        </label>
    </div>

    <!-- Returning -->
    <div class="consultation-checkbox" id="returning-box"
         style="display: flex; align-items: center; gap: 8px; padding: 12px 16px; border: 1px solid #ddd; border-radius: 8px; background: #f8f9fa;">

        <input type="checkbox"
               id="returning-checkbox"
               style="width: 18px; height: 18px; cursor: pointer;">

        <label for="returning-checkbox"
               style="margin: 0; cursor: pointer; font-size: 16px; font-weight: 500; color: #333;">
            <i class="fas fa-user-clock"></i> Returning
        </label>
    </div>

    <!-- New -->
    <div class="consultation-checkbox" id="new-box"
         style="display: flex; align-items: center; gap: 8px; padding: 12px 16px; border: 1px solid #ddd; border-radius: 8px; background: #f8f9fa;">

        <input type="checkbox"
               id="new-checkbox"
               style="width: 18px; height: 18px; cursor: pointer;">

        <label for="new-checkbox"
               style="margin: 0; cursor: pointer; font-size: 16px; font-weight: 500; color: #333;">
            <i class="fas fa-user-plus"></i> New
        </label>
    </div>
    <!-- Injection -->
<div class="consultation-checkbox"
     style="display: flex; align-items: center; gap: 8px; padding: 12px 16px; border: 1px solid #ddd; border-radius: 8px; background: #f8f9fa;">

    <input type="checkbox"
           id="injection-checkbox"
           style="width: 18px; height: 18px; cursor: pointer;">

    <label for="injection-checkbox"
           style="margin: 0; cursor: pointer; font-size: 16px; font-weight: 500; color: #333;">
        <i class="fas fa-syringe"></i> Injection
    </label>
</div>

        <!-- Blood Work -->
    <div class="consultation-checkbox"
         style="display: flex; align-items: center; gap: 8px; padding: 12px 16px; border: 1px solid #ddd; border-radius: 8px; background: #f8f9fa;">

        <input type="checkbox"
               id="bloodwork-checkbox"
               style="width: 18px; height: 18px; cursor: pointer;">

        <label for="bloodwork-checkbox"
               style="margin: 0; cursor: pointer; font-size: 16px; font-weight: 500; color: #333;">
            <i class="fas fa-vial"></i> Blood Work
        </label>
    </div>
    <!-- Manual Patient Number -->
<div class="consultation-checkbox"
     style="display:flex; align-items:center; gap:10px; padding:8px 16px; border:1px solid #ddd; border-radius:8px; background:#f8f9fa;">

    <div style="min-width:150px; font-size:16px; font-weight:500; color:#333;">
        <i class="fas fa-id-badge"></i> Patient Number
    </div>

    <input type="number"
       id="manual-patient-number"
       class="form-control"
       style="max-width:220px; height:34px;"
       placeholder="<?php echo attr($todays_patient_number ? 'Today\'s Patient Number' : 'Enter Patient Number'); ?>"
       value="<?php echo attr((string) ($todays_patient_number ?? '')); ?>"
       min="0"
       step="1"
       oninput="this.value = this.value.replace(/[^0-9]/g, ''); if (typeof schedulePatientNumberValidation === 'function') { schedulePatientNumberValidation(); }"
       onblur="if (typeof validatePatientNumberInline === 'function') { validatePatientNumberInline(true); }"
       <?php echo $todays_patient_number ? 'readonly' : ''; ?>>
    <?php if ($todays_patient_number): ?>
        <div style="font-size:12px; color:#0f766e; font-weight:600;">
            <?php echo xlt('Locked for today'); ?>
        </div>
    <?php endif; ?>
</div>
<div id="patient-number-error" style="display:none; margin-top:8px; padding:10px 14px; border-radius:8px; background:#fff1f2; border:1px solid #fecdd3; color:#b42318; font-size:13px; font-weight:600;"></div>

<div class="consultation-checkbox" id="prepay-box"
     style="display:flex; align-items:center; gap:8px; padding:12px 16px; border:1px solid #ddd; border-radius:8px; background:#f8f9fa;">
    <input type="checkbox"
           id="prepay-toggle-checkbox"
           style="width: 18px; height: 18px; cursor: pointer;"
           onchange="togglePrepayMode(this.checked)">
    <label for="prepay-toggle-checkbox"
           style="margin: 0; cursor: pointer; font-size: 16px; font-weight: 500; color: #333;">
        <i class="fas fa-hand-holding-usd"></i> Prepay
    </label>
</div>

<div class="consultation-checkbox" id="ten-off-box"
     style="display:flex; align-items:center; gap:8px; padding:12px 16px; border:1px solid #ddd; border-radius:8px; background:#f8f9fa;">
    <input type="checkbox"
           id="ten-off-checkbox"
           style="width: 18px; height: 18px; cursor: pointer;"
           onchange="toggleTenDollarOff(this.checked)">
    <label for="ten-off-checkbox"
           style="margin: 0; cursor: pointer; font-size: 16px; font-weight: 500; color: #333;">
        <i class="fas fa-tags"></i> $10 Off
    </label>
</div>

</div>

<div id="prepay-details-section" style="display:none; margin-top:12px; background:#f8fbff; border:1px solid #d8e6ff; border-radius:10px; padding:14px 16px;">
    <div style="font-size:14px; font-weight:700; color:#1f4f8a; margin-bottom:12px;">
        <i class="fas fa-receipt"></i> Prepay Details
    </div>
    <div style="display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end;">
        <div style="min-width:190px; flex:0 0 220px;">
            <label for="prepay-date" style="display:block; margin-bottom:6px; font-size:13px; font-weight:600; color:#495057;">
                Prepay Date <span style="color:#dc3545;">*</span>
            </label>
            <input type="date" id="prepay-date" class="form-control" max="<?php echo attr(date('Y-m-d')); ?>" onchange="updatePrepayDetailsFromInputs()">
        </div>
        <div style="min-width:260px; flex:1 1 320px;">
            <label for="prepay-sale-reference" style="display:block; margin-bottom:6px; font-size:13px; font-weight:600; color:#495057;">
                Sale / Reference <span style="color:#dc3545;">*</span>
            </label>
            <input type="text" id="prepay-sale-reference" class="form-control" placeholder="Enter prepaid sale or reference" oninput="updatePrepayDetailsFromInputs()">
        </div>
    </div>
</div>

	                        <!-- Weight Recording Section -->
                        <div class="weight-tracking-section" style="margin-top: 15px;">
                            <div id="weight-recording-container" style="background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%); border: 1px solid #e9ecef; border-left: 4px solid #dc3545; border-radius: 8px; padding: 16px 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); transition: all 0.3s ease;">
                                <!-- Header with Icon and Status -->
                                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                    <div style="width: 40px; height: 40px; background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); border-radius: 8px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(220,53,69,0.3);" id="weight-icon-container">
                                        <i class="fas fa-weight" id="weight-icon" style="color: white; font-size: 18px;"></i>
                                    </div>
                                    <div style="flex: 1;">
                                        <div id="weight-recording-label" style="font-size: 16px; font-weight: 600; color: #495057; margin-bottom: 2px;">
                                            Weight Recording Required
                                        </div>
                                        <div id="weight-recording-subtitle" style="font-size: 13px; color: #6c757d;">
                                            Record patient weight to proceed with checkout
                                        </div>
                                    </div>
                                    <div id="weight-recording-badge" style="background: #dc3545; color: white; padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
                                        Required
                                    </div>
                                </div>
                                
                                <!-- Weight Input -->
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="flex: 1; position: relative;">
                                        <input type="number" id="patient-weight" placeholder="Enter weight" step="0.1" min="0" max="1000" 
                                               style="width: 100%; padding: 10px 14px; padding-right: 45px; border: 2px solid #dee2e6; border-radius: 6px; font-size: 15px; font-weight: 500; transition: all 0.3s ease; outline: none;"
                                               onfocus="this.style.borderColor='#007bff'; this.style.boxShadow='0 0 0 3px rgba(0,123,255,0.1)';"
                                               onblur="this.style.borderColor='#dee2e6'; this.style.boxShadow='none';">
                                        <span style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: #6c757d; font-size: 14px; font-weight: 500; pointer-events: none;">lbs</span>
                                    </div>
                                    <button type="button" id="save-weight-btn" onclick="savePatientWeight()" 
                                            style="background: linear-gradient(135deg, #28a745 0%, #218838 100%); color: white; border: none; padding: 10px 24px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.3s ease; box-shadow: 0 2px 6px rgba(40,167,69,0.3); white-space: nowrap;"
                                            onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(40,167,69,0.4)';"
                                            onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 6px rgba(40,167,69,0.3)';">
                                        <i class="fas fa-check-circle"></i> Record Weight
                                </button>
                            </div>
                            </div>
                            <div id="weight-status" style="margin-top: 10px; padding: 10px 14px; border-radius: 6px; font-size: 14px; font-weight: 500; display: none;"></div>
                        </div>
                    </div>

                    <!-- Order Summary -->
                    <div class="order-summary">
                        <div class="order-header">
                            <h3>Order Summary</h3>
                        </div>
                        <div class="order-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Product name</th>
                                        <th>Lot no</th>
                                        <th>Quantity</th>
                                             <th id="dispense-column-header"><?php 
                                                $dispense_facility_id = posGetCurrentSessionFacilityId();
                                                echo ($dispense_facility_id == 36) ? 'Marketplace Dispense' : 'Dispense'; 
                                             ?></th>
                                        <th>Administer</th>
                                        <th id="unit-price-header" style="display: none;">Unit/Price</th>
                                        <th id="discount-header" style="display: none;">Discount</th>
                                        <th>Price</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="order-items">
                                    <!-- Items will be added here dynamically -->
                                </tbody>
                            </table>
                        </div>
                                                  <div class="order-total">
                              <span class="total-label">Total:</span>
                              <span class="total-amount" id="order-total">$0.00</span>
                              <button class="btn-clear-cart" onclick="clearCart()" style="margin-left: 15px; background: #BF1542; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; transition: all 0.3s ease;">
                                  <i class="fas fa-trash"></i> Clear Cart
                              </button>
                          </div>
                          
                          <!-- Credit Balance Application -->
                          <div id="credit-balance-section" style="margin-top: 15px; display: none;">
                              <div class="credit-checkbox" style="display: flex; align-items: center; gap: 8px; padding: 12px 16px; border: 1px solid #ddd; border-radius: 8px; background: #f8f9fa; width: fit-content;">
                                  <input type="checkbox" id="apply-credit-checkbox" onchange="toggleCreditApplication()" style="width: 18px; height: 18px; cursor: pointer;">
                                  <label for="apply-credit-checkbox" style="margin: 0; cursor: pointer; font-size: 16px; font-weight: 500; color: #333; display: flex; align-items: center; gap: 8px;">
                                      <i class="fas fa-wallet" style="color: #28a745;"></i> Apply Credit Balance
                                  </label>
                              </div>
                          </div>
                             
                             <!-- POS Action Buttons -->
                             <div class="pos-actions" style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px; padding-top: 15px; border-top: 1px solid #e9ecef;">
                                 <div>
                                     <!-- Left side - can be used for additional buttons -->
                    </div>
                                 <div style="display: flex; gap: 10px;">
                                             <button class="btn-override" onclick="openGlobalOverride()" id="override-btn" title="Override All Prices (Admin Only)" style="display: none; background: #ffc107; color: #212529; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.3s ease;">
            <i class="fas fa-edit"></i> Override Prices
        </button>
        <!-- Defective medicine reporting is now handled at inventory level only -->
        <!-- Managers should use the inventory system to report defective products by lot number -->
                                     <button class="btn-primary" onclick="nextStep()" id="checkout-btn" <?php echo !$patient_data ? 'disabled' : ''; ?> style="<?php echo !$patient_data ? 'opacity: 0.6; cursor: not-allowed;' : ''; ?> background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.3s ease;">
                                         Proceed to Payment
                                     </button>
                                 </div>
                             </div>
                         </div>
                     </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Step 2: Checkout -->
            <div class="form-step" id="step-2">
                <div class="step-content">
                    <!-- Patient Information Summary (Read-only) -->
                    <?php if ($patient_data): ?>
                    <div class="patient-summary" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #007bff;">
                        <div style="display: flex; gap: 60px; font-size: 16px; color: #555; align-items: center; flex-wrap: wrap;">
                            <div>
                                <strong style="font-size: 18px; color: #333;"><?php echo text($patient_data['fname'] . ' ' . $patient_data['lname']); ?></strong>
                            </div>
                            <div>
                                <strong style="font-size: 16px;">Mobile:</strong> <?php echo text($patient_data['phone_cell'] ?: $patient_data['phone_home'] ?: 'N/A'); ?>
                            </div>
                            <div>
                                <strong style="font-size: 16px;">DOB:</strong> <?php echo text(oeFormatShortDate($patient_data['DOB'])); ?>
                            </div>
                            <div>
                                <strong style="font-size: 16px;">Gender:</strong> <?php echo text(ucfirst($patient_data['sex'] ?? 'N/A')); ?>
                            </div>
                            <?php if ($patient_balance > 0): ?>
                            <div style="color: #dc3545;">
                                <strong style="font-size: 16px;">Balance:</strong> $<?php echo number_format($patient_balance, 2); ?>
                            </div>
                            <?php endif; ?>
                            <div id="patientCreditBalanceDisplay" style="color: #495057;">
                                <strong style="font-size: 16px;">Credit Balance:</strong> <span id="patientCreditBalance">$0.00</span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="payment-summary">
                        <h2>Payment Processing</h2>
                        <div id="payment-total" class="payment-total"></div>
                        <div id="payments-list" class="payments-list" style="display:none;">
                            <h4>Payments for this invoice</h4>
                            <ul id="payments-ul"></ul>
                        </div>
                    </div>
                    
                    <div class="payment-methods">
                        <h4>Payment Method</h4>
                        
                        <!-- Single Payment Form with Method Selection -->
                        <div class="payment-form">
                            <div class="form-group">
                                <label for="payment-method">Payment Method</label>
                                <select id="payment-method" class="form-control">
                                    <option value="" selected>None</option>
                                    <option value="credit_card">Credit Card</option>
                                    <option value="cash">Cash</option>
                                    <option value="affirm">Affirm</option>
                                    <option value="zip">Zip</option>
                                    <option value="check">Check</option>
                                    <option value="afterpay">Afterpay</option>
                                    <option value="sezzle">Sezzle</option>
                                    <option value="fsa_hsa">FSA/HSA</option>
                                </select>
                        </div>
                        
                            <div class="form-group">
                                <label for="payment-amount">Amount</label>
                                <input type="number" id="payment-amount" step="0.01" min="0" placeholder="Enter amount">
                                <div id="cash-change" class="change-display" style="display: none;"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="step-actions">
                    <button class="btn-secondary" onclick="prevStep()">Back</button>
                    <button class="btn-primary" onclick="processPayment()" id="process-payment-btn">
                        <i class="fas fa-lock"></i> Process Payment
                    </button>
                </div>
            </div>
        </div>



    </div>

    <!-- Credit Transfer Modal -->
    <div id="creditTransferModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3><i class="fas fa-exchange-alt"></i> Transfer Credit Balance</h3>
                <span class="close" onclick="closeCreditTransferModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #495057;">Transfer Amount:</label>
                    <input type="number" id="creditTransferAmount" min="0.01" step="0.01" 
                           style="width: 100%; padding: 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; outline: none;"
                           placeholder="Enter amount to transfer">
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #495057;">To Patient:</label>
                    <input type="text" id="creditTransferPatientSearch" placeholder="Search patient by name, ID, or phone..." 
                           style="width: 100%; padding: 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; outline: none;"
                           onkeyup="searchCreditTransferPatient(this.value)">
                    <div id="creditTransferPatientResults" style="margin-top: 10px; max-height: 200px; overflow-y: auto; border: 1px solid #ced4da; border-radius: 4px; display: none;"></div>
                </div>
                
                <div id="selectedCreditTransferPatient" style="display: none;">
                    <h4 style="margin-bottom: 10px; color: #495057;">Selected Patient:</h4>
                    <div id="selectedCreditTransferPatientInfo" style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #007bff;"></div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #495057;">Reason for Transfer:</label>
                    <textarea id="creditTransferReason" placeholder="Enter reason for transfer..." 
                              style="width: 100%; padding: 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; outline: none; resize: vertical; min-height: 60px;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="processCreditTransfer()" class="btn btn-primary" id="processCreditTransferBtn" disabled>
                    <i class="fas fa-exchange-alt"></i> Transfer Credit
                </button>
                <button onclick="closeCreditTransferModal()" class="btn btn-secondary">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Price Override Modal -->
    <div id="override-modal" class="modal" style="display: none;">
        <div class="modal-content" style="background: white; padding: 30px; border-radius: 12px; max-width: 500px; width: 90%; margin: 50px auto; box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="margin: 0; color: #333;">
                    <i class="fas fa-edit" style="color: #ffc107;"></i> Price Override
                </h3>
                <button onclick="closeOverrideModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">&times;</button>
            </div>
            
            <div id="override-content">
                <!-- Content will be populated dynamically -->
            </div>
        </div>
    </div>

    <!-- Defective Medicine Modal - REMOVED -->
    <!-- Defective medicine reporting is now handled at inventory level only -->
    <!-- Managers should use the inventory system to report defective products by lot number -->

    <script>
        // Global variables
        var cart = [];
        var currentSearchResults = [];
        var searchTimeout;
        var activeInventorySearchPromise = null;
        var currentPaymentMethod = 'terminal_card';
        var prepayModeEnabled = false;
        var tenDollarOffEnabled = false;
        var prepayDetails = { date: '', saleReference: '' };
        var posSimpleSearchUrl = <?php echo json_encode($GLOBALS['webroot'] . '/interface/pos/simple_search.php'); ?>;
        var priceOverrideAdminEmails = <?php echo json_encode($price_override_admin_email_users); ?>;
        var stripe, card;
        var currentPatientId = <?php echo json_encode($pid ?: null); ?>;
        console.log('currentPatientId initialized to:', currentPatientId);
        var taxRates = <?php echo json_encode($tax_rates); ?>;
        
        // Get current user's facility information
        var currentFacilityId = <?php echo json_encode(posGetCurrentSessionFacilityId()); ?>;
        var isHooverFacility = (currentFacilityId == 36);
        console.log('Current facility ID:', currentFacilityId, 'Is Hoover facility:', isHooverFacility);
        console.log('Facility comparison:', currentFacilityId, '==', 36, '=', isHooverFacility);
        console.log('Current facility ID type:', typeof currentFacilityId);
        console.log('Comparison with string "36":', currentFacilityId == "36");
        console.log('Comparison with number 36:', currentFacilityId == 36);

        function generateFacilityInvoiceNumber(prefix = 'INV') {
            const normalizedPrefix = String(prefix || 'INV')
                .toUpperCase()
                .replace(/[^A-Z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '') || 'INV';
            const numericFacilityId = Number(currentFacilityId || 0);
            const facilityToken = `F${String(numericFacilityId > 0 ? numericFacilityId : 0).padStart(3, '0')}`;
            const now = new Date();
            const datePart = [
                now.getFullYear(),
                String(now.getMonth() + 1).padStart(2, '0'),
                String(now.getDate()).padStart(2, '0'),
                String(now.getHours()).padStart(2, '0'),
                String(now.getMinutes()).padStart(2, '0'),
                String(now.getSeconds()).padStart(2, '0')
            ].join('');
            const milliPart = String(now.getMilliseconds()).padStart(3, '0');
            const randomPart = Math.random().toString(36).slice(2, 6).toUpperCase().padEnd(4, '0');

            return `${normalizedPrefix}-${facilityToken}-${datePart}-${milliPart}${randomPart}`;
        }
        
        // Show alert for debugging
        if (isHooverFacility) {
            console.log('🎯 HOOVER FACILITY DETECTED - Marketplace dispense should be available!');
            
            // Hoover facility detected - marketplace dispense will be available for all items
        } else {
            console.log('❌ NOT Hoover facility - Regular dispense will be shown');
        }

        // Cart persistence functions
        function saveCartToStorage() {
            if (currentPatientId) {
                try {
                    const cartData = {
                        patientId: currentPatientId,
                        items: cart,
                        prepayModeEnabled: prepayModeEnabled,
                        tenDollarOffEnabled: tenDollarOffEnabled,
                        prepayDetails: prepayDetails,
                        timestamp: Date.now()
                    };
                    localStorage.setItem('pos_cart_' + currentPatientId, JSON.stringify(cartData));
                    console.log('Cart saved for patient:', currentPatientId, 'Items:', cart.length);
                } catch (error) {
                    console.error('Error saving cart to storage:', error);
                }
            }
        }

        function loadPersistentCart() {
            if (currentPatientId) {
                try {
                    const cartData = localStorage.getItem('pos_cart_' + currentPatientId);
                    if (cartData) {
                        const parsed = JSON.parse(cartData);
                        // Check if cart is from the same patient and not too old (24 hours)
                        if (parsed.patientId === currentPatientId && 
                            (Date.now() - parsed.timestamp) < (24 * 60 * 60 * 1000)) {
                            cart = parsed.items || [];
                            cart.forEach(function(item) {
                                applyDoseBasedPricing(item);
                            });
                            prepayModeEnabled = !!parsed.prepayModeEnabled;
                            tenDollarOffEnabled = !!parsed.tenDollarOffEnabled;
                            prepayDetails = parsed.prepayDetails || { date: '', saleReference: '' };
                            updateOrderSummary();
                            refreshCartRemainingDispenseState().then(() => {
                                cart.forEach(function(item) {
                                    applyRemainingDispenseDoseLock(item);
                                });
                                updateOrderSummary();
                                saveCartToStorage();
                            }).catch(function(error) {
                                console.error('Error refreshing remaining dispense dose data:', error);
                            });
                            updateConsultationCheckboxState();
                            syncPrepayToggleState();
                            syncTenDollarOffToggleState();
                            console.log('Cart loaded for patient:', currentPatientId, 'Items:', cart.length);
                            
                            // Add indicator if cart has items (no popup notification)
                            if (cart.length > 0) {
                                addRestoredCartIndicator();
                            }
                        } else {
                            // Clear old cart data
                            localStorage.removeItem('pos_cart_' + currentPatientId);
                            cart = [];
                            prepayModeEnabled = false;
                            tenDollarOffEnabled = false;
                            prepayDetails = { date: '', saleReference: '' };
                            updateConsultationCheckboxState();
                            syncPrepayToggleState();
                            syncTenDollarOffToggleState();
                        }
                    }
                } catch (error) {
                    console.error('Error loading cart from storage:', error);
                    cart = [];
                    prepayModeEnabled = false;
                    tenDollarOffEnabled = false;
                    prepayDetails = { date: '', saleReference: '' };
                    updateConsultationCheckboxState();
                    syncPrepayToggleState();
                    syncTenDollarOffToggleState();
                }
            }
        }

        function clearPersistentCart() {
            if (currentPatientId) {
                try {
                    localStorage.removeItem('pos_cart_' + currentPatientId);
                    console.log('Cart cleared for patient:', currentPatientId);
                } catch (error) {
                    console.error('Error clearing cart from storage:', error);
                }
            }
        }

        function getCartFromStorage() {
            if (currentPatientId) {
                try {
                    const cartData = localStorage.getItem('pos_cart_' + currentPatientId);
                    if (cartData) {
                        const parsed = JSON.parse(cartData);
                        // Check if cart is from the same patient and not too old (24 hours)
                        if (parsed.patientId === currentPatientId && 
                            (Date.now() - parsed.timestamp) < (24 * 60 * 60 * 1000)) {
                            return parsed.items || [];
                        }
                    }
                } catch (error) {
                    console.error('Error getting cart from storage:', error);
                }
            }
            return [];
        }

        function syncPrepayToggleState() {
            const prepayToggle = document.getElementById('prepay-toggle-checkbox');
            const prepayBox = document.getElementById('prepay-box');
            const prepayDetailsSection = document.getElementById('prepay-details-section');
            const prepayDateInput = document.getElementById('prepay-date');
            const prepaySaleReferenceInput = document.getElementById('prepay-sale-reference');
            if (prepayToggle) {
                prepayToggle.checked = !!prepayModeEnabled;
            }

            if (prepayBox) {
                prepayBox.style.background = prepayModeEnabled ? '#e9f2ff' : '#f8f9fa';
                prepayBox.style.borderColor = prepayModeEnabled ? '#0d6efd' : '#ddd';
                prepayBox.style.boxShadow = prepayModeEnabled ? '0 0 0 1px rgba(13,110,253,0.2)' : 'none';
            }

            if (prepayDetailsSection) {
                prepayDetailsSection.style.display = prepayModeEnabled ? 'block' : 'none';
            }

            if (prepayDateInput) {
                prepayDateInput.max = getTodayIsoDate();
                prepayDateInput.value = prepayDetails.date || '';
            }

            if (prepaySaleReferenceInput) {
                prepaySaleReferenceInput.value = prepayDetails.saleReference || '';
            }
        }

        function syncTenDollarOffToggleState() {
            const tenOffToggle = document.getElementById('ten-off-checkbox');
            const tenOffBox = document.getElementById('ten-off-box');

            if (tenOffToggle) {
                tenOffToggle.checked = !!tenDollarOffEnabled;
            }

            if (tenOffBox) {
                tenOffBox.style.background = tenDollarOffEnabled ? '#fff4cc' : '#f8f9fa';
                tenOffBox.style.borderColor = tenDollarOffEnabled ? '#f4b400' : '#ddd';
                tenOffBox.style.boxShadow = tenDollarOffEnabled ? '0 0 0 1px rgba(244,180,0,0.2)' : 'none';
            }
        }

        function updatePrepayDetailsFromInputs() {
            const prepayDateInput = document.getElementById('prepay-date');
            const prepaySaleReferenceInput = document.getElementById('prepay-sale-reference');
            prepayDetails = {
                date: prepayDateInput ? (prepayDateInput.value || '') : '',
                saleReference: prepaySaleReferenceInput ? (prepaySaleReferenceInput.value || '').trim() : ''
            };
            saveCartToStorage();
        }

        function getTodayIsoDate() {
            const today = new Date();
            const year = today.getFullYear();
            const month = String(today.getMonth() + 1).padStart(2, '0');
            const day = String(today.getDate()).padStart(2, '0');
            return year + '-' + month + '-' + day;
        }

        function getTenDollarOffAmount() {
            return tenDollarOffEnabled ? 10 : 0;
        }

        function validatePrepayDetails() {
            if (!prepayModeEnabled) {
                return true;
            }

            const hasSelectedPrepayItem = Array.isArray(cart) && cart.some(item => !!item.prepay_selected);
            if (!hasSelectedPrepayItem) {
                return true;
            }

            updatePrepayDetailsFromInputs();
            if (!prepayDetails.date || !prepayDetails.saleReference) {
                alert('When Prepay is selected, please enter the prepay date and sale/reference.');
                return false;
            }

            if (prepayDetails.date > getTodayIsoDate()) {
                alert('Prepay date cannot be in the future.');
                const prepayDateInput = document.getElementById('prepay-date');
                if (prepayDateInput) {
                    prepayDateInput.focus();
                }
                return false;
            }

            return true;
        }

        function isItemPrepaid(item) {
            return !!(prepayModeEnabled && item && item.prepay_selected);
        }

        function getSimpleDiscountedItemTotal(item) {
            if (isItemPrepaid(item)) {
                return 0;
            }
            const remainingDoseUpgradeCharge = getRemainingDoseUpgradeCharge(item);
            if (remainingDoseUpgradeCharge > 0) {
                return remainingDoseUpgradeCharge;
            }
            const quantity = Number(item.quantity) || 0;
            const originalPrice = Number(item.original_price ?? item.price) || 0;
            const currentPrice = Number(item.price) || 0;
            const discountInfo = item.inventory_discount_info || item.discount_info;

            if (discountInfo && discountInfo.type === 'quantity' && Number(discountInfo.quantity) > 1) {
                const discountQty = Number(discountInfo.quantity);
                const groupSize = discountQty + 1;
                const fullGroups = Math.floor(quantity / groupSize);
                const remainingItems = quantity % groupSize;
                return Math.round(((fullGroups * discountQty * originalPrice) + (remainingItems * originalPrice)) * 100) / 100;
            }

            return Math.round((currentPrice * quantity) * 100) / 100;
        }

        function getSimpleOriginalItemTotal(item) {
            if (isItemPrepaid(item)) {
                return 0;
            }
            const remainingDoseUpgradeCharge = getRemainingDoseUpgradeCharge(item);
            if (remainingDoseUpgradeCharge > 0) {
                return remainingDoseUpgradeCharge;
            }
            const quantity = Number(item.quantity) || 0;
            const originalPrice = Number(item.original_price ?? item.price) || 0;
            return Math.round((originalPrice * quantity) * 100) / 100;
        }

        function buildCheckoutItemPayload(item) {
            const quantity = Number(item.quantity) || 0;
            const originalUnitPrice = isItemPrepaid(item) ? 0 : (Number(item.original_price ?? item.price) || 0);
            const unitPrice = isItemPrepaid(item) ? 0 : (Number(item.price) || 0);
            const originalLineTotal = getSimpleOriginalItemTotal(item);
            const lineTotal = getSimpleDiscountedItemTotal(item);
            const lineDiscount = Math.max(0, Math.round((originalLineTotal - lineTotal) * 100) / 100);

            return {
                id: item.id,
                name: item.name,
                display_name: item.display_name,
                drug_id: item.drug_id || (String(item.id || '').startsWith('drug_') ? String(item.id).replace('drug_', '').split('_')[0] : 0),
                lot_number: item.lot || '',
                quantity: quantity,
                dispense_quantity: item.dispense_quantity !== undefined ? item.dispense_quantity : quantity,
                administer_quantity: item.administer_quantity !== undefined ? item.administer_quantity : 0,
                dose_option_mg: item.dose_option_mg || '',
                price: unitPrice,
                original_price: originalUnitPrice,
                line_total: lineTotal,
                original_line_total: originalLineTotal,
                line_discount: lineDiscount,
                has_discount: !!item.has_discount,
                discount_info: item.discount_info || null,
                prepay_selected: !!item.prepay_selected,
                prepay_date: isItemPrepaid(item) ? (prepayDetails.date || '') : '',
                prepay_sale_reference: isItemPrepaid(item) ? (prepayDetails.saleReference || '') : '',
                lot: item.lot,
                qoh: item.qoh,
                expiration: item.expiration,
                has_remaining_dispense: item.has_remaining_dispense || false,
                remaining_dispense_items: item.remaining_dispense_items || [],
                total_remaining_quantity: item.total_remaining_quantity || 0,
                is_different_lot_dispense: item.is_different_lot_dispense || false,
                marketplace_dispense: item.marketplace_dispense || false
            };
        }

        function togglePrepayMode(enabled) {
            prepayModeEnabled = !!enabled;
            if (!prepayModeEnabled) {
                cart.forEach(function(item) {
                    item.prepay_selected = false;
                });
                prepayDetails = { date: '', saleReference: '' };
            }

            syncPrepayToggleState();
            updateOrderSummary();
            updatePaymentSummary();
            saveCartToStorage();
        }

        function toggleTenDollarOff(enabled) {
            tenDollarOffEnabled = !!enabled;
            syncTenDollarOffToggleState();
            updateOrderSummary();
            updatePaymentSummary();
            saveCartToStorage();
        }

        function toggleItemPrepay(index, enabled) {
            if (!cart[index]) {
                return;
            }

            cart[index].prepay_selected = !!enabled;
            updateOrderSummary();
            updatePaymentSummary();
            saveCartToStorage();
        }



        // Show notification message
        function showNotification(message, type = 'success') {
            // Remove any existing notifications
            const existingNotification = document.querySelector('.pos-notification');
            if (existingNotification) {
                existingNotification.remove();
            }
            
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `pos-notification pos-notification-${type}`;
            notification.innerHTML = `
                <div class="notification-content">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                    <span>${message}</span>
                </div>
            `;
            
            // Add styles
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : '#17a2b8'};
                color: white;
                padding: 15px 20px;
                border-radius: 5px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 9999;
                font-size: 14px;
                font-weight: 500;
                max-width: 400px;
                animation: slideInRight 0.3s ease-out;
            `;
            
            // Add animation styles
            const style = document.createElement('style');
            style.textContent = `
                @keyframes slideInRight {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                @keyframes slideOutRight {
                    from { transform: translateX(0); opacity: 1; }
                    to { transform: translateX(100%); opacity: 0; }
                }
                .pos-notification .notification-content {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                .pos-notification .notification-content i {
                    font-size: 16px;
                }
            `;
            document.head.appendChild(style);
            
            // Add to page
            document.body.appendChild(notification);
            
            // Auto-remove after 3 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.style.animation = 'slideOutRight 0.3s ease-out';
                    setTimeout(() => {
                        if (notification.parentNode) {
                            notification.remove();
                        }
                    }, 300);
                }
            }, 3000);
        }

        function isMlInventoryItem(item) {
            if (!item) {
                return false;
            }

            if (typeof item.is_ml_form !== 'undefined') {
                return !!item.is_ml_form;
            }

            return String(item.form || '').toLowerCase() === 'ml';
        }

        const MEDICATION_DOSE_OPTIONS = {
            semaglutide: ['0.25', '0.50', '1.00', '1.70', '2.40'],
            tirzepatide: ['2.50', '5.00', '7.50', '10.00', '12.50', '15.00'],
            testosterone: ['80', '100', '120', '140', '160', '180', '200', '240', '300']
        };

        const MEDICATION_DOSE_PRICING = {
            tirzepatide: {
                '2.50': 74.75,
                '5.00': 87.25,
                '7.50': 99.75,
                '10.00': 112.25,
                '12.50': 124.75,
                '15.00': 137.25
            }
        };

        function getMedicationDoseKey(item) {
            const itemName = String(item?.name || item?.display_name || '').toLowerCase();
            if (itemName.includes('semaglutide')) {
                return 'semaglutide';
            }
            if (itemName.includes('tirzepatide')) {
                return 'tirzepatide';
            }
            if (itemName.includes('testosterone')) {
                return 'testosterone';
            }
            return '';
        }

        function hasMedicationDoseSelector(item) {
            return !!getMedicationDoseKey(item);
        }

        function isPhentermineProduct(item) {
            const itemName = String(item?.name || item?.display_name || '').toLowerCase();
            return itemName === 'phentermine #28' || itemName === 'phentermine #14';
        }

        function usesRegularDispenseInHoover(item) {
            return isPhentermineProduct(item);
        }

        function isFivePackMarketplaceDispenseItem(item) {
            const itemName = String(item?.name || item?.display_name || '')
                .replace(/\s*\([^)]*\)\s*$/, '')
                .replace(/\s+/g, ' ')
                .trim()
                .toUpperCase();

            return itemName === 'LIPOB12' || itemName === 'LIPOB12 SULFA FREE';
        }

        function getMarketplaceDispenseStep(item) {
            return isFivePackMarketplaceDispenseItem(item) ? 5 : 4;
        }

        function roundMarketplaceDispenseQuantity(value, item) {
            const step = getMarketplaceDispenseStep(item);
            return Math.round((parseFloat(value) || 0) / step) * step;
        }

        function floorMarketplaceDispenseQuantity(value, item) {
            const step = getMarketplaceDispenseStep(item);
            return Math.floor((parseFloat(value) || 0) / step) * step;
        }

        function getMedicationDoseOptions(item) {
            const doseKey = getMedicationDoseKey(item);
            return MEDICATION_DOSE_OPTIONS[doseKey] || [];
        }

        function normalizeMedicationDoseOption(value) {
            const doseValue = Number(value);
            if (!Number.isFinite(doseValue) || doseValue <= 0) {
                return '';
            }

            return doseValue.toFixed(2);
        }

        function getRemainingDispenseDoseOption(itemOrRemainingItems) {
            const remainingItems = Array.isArray(itemOrRemainingItems)
                ? itemOrRemainingItems
                : (Array.isArray(itemOrRemainingItems?.remaining_dispense_items) ? itemOrRemainingItems.remaining_dispense_items : []);

            for (const remainingItem of remainingItems) {
                const doseOption = normalizeMedicationDoseOption(remainingItem?.dose_option_mg || remainingItem?.semaglutide_dose_mg || '');
                if (doseOption !== '') {
                    return doseOption;
                }
            }

            return '';
        }

        function allowsRemainingDoseUpgrade(item) {
            return getMedicationDoseKey(item) === 'tirzepatide';
        }

        function getRemainingDoseUpgradeCharge(item) {
            if (!item || parseFloat(item.quantity || 0) > 0 || !allowsRemainingDoseUpgrade(item)) {
                return 0;
            }

            const administerQty = parseFloat(item.administer_quantity || 0) || 0;
            if (administerQty <= 0) {
                return 0;
            }

            const remainingDose = getRemainingDispenseDoseOption(item);
            const selectedDose = normalizeMedicationDoseOption(item.dose_option_mg || getDefaultMedicationDose(item));
            if (remainingDose === '' || selectedDose === '') {
                return 0;
            }

            const remainingPrice = getMedicationDosePrice(item, remainingDose);
            const selectedPrice = getMedicationDosePrice(item, selectedDose);
            if (remainingPrice === null || selectedPrice === null) {
                return 0;
            }

            const upgradeAmount = Math.max(0, selectedPrice - remainingPrice);
            return Math.round(upgradeAmount * administerQty * 100) / 100;
        }

        function applyRemainingDispenseDoseLock(item) {
            if (!item || !hasMedicationDoseSelector(item)) {
                return item;
            }

            const remainingDose = getRemainingDispenseDoseOption(item);
            if (remainingDose === '' || (parseFloat(item.quantity || 0) > 0)) {
                item.locked_remaining_dose_mg = '';
                return item;
            }

            item.remaining_dose_mg = remainingDose;
            item.locked_remaining_dose_mg = '';
            if (!item.dose_option_mg) {
                item.dose_option_mg = remainingDose;
            }
            applyDoseBasedPricing(item);
            return item;
        }

        function getSelectedMedicationDose(item) {
            return item?.dose_option_mg || getDefaultMedicationDose(item);
        }

        function getMedicationDoseSelectorHtml(item, index) {
            if (!hasMedicationDoseSelector(item)) {
                return '';
            }

            applyRemainingDispenseDoseLock(item);
            const doseOptions = getMedicationDoseOptions(item);
            const selectedDose = getSelectedMedicationDose(item);
            const remainingDose = parseFloat(item.quantity || 0) <= 0 ? getRemainingDispenseDoseOption(item) : '';
            const upgradeCharge = getRemainingDoseUpgradeCharge(item);
            const helpText = remainingDose
                ? `<div style="font-size:11px; color:#6c757d; margin-top:4px;">Remaining dose: ${remainingDose} mg${upgradeCharge > 0 ? `; upgrade difference: $${upgradeCharge.toFixed(2)}` : ''}</div>`
                : '';

            return `<div style="margin-top:8px;"><select class="form-control form-control-sm" style="min-width:110px;" onchange="updateMedicationDose(${index}, this.value)"> ${doseOptions.map(option => `<option value="${option}" ${selectedDose === option ? 'selected' : ''}>${option} mg</option>`).join('')}</select>${helpText}</div>`;
        }

        function hasDoseBasedPricing(item) {
            const doseKey = getMedicationDoseKey(item);
            return !!MEDICATION_DOSE_PRICING[doseKey];
        }

        function getDefaultMedicationDose(item) {
            const doseOptions = getMedicationDoseOptions(item);
            if (!doseOptions.length) {
                return '';
            }

            const sourceText = [
                item?.size || '',
                item?.display_name || '',
                item?.name || ''
            ].join(' ');

            const match = sourceText.match(/(\d+(?:\.\d+)?)/);
            if (match) {
                const numericMatch = Number(match[1]).toFixed(2);
                if (doseOptions.includes(numericMatch)) {
                    return numericMatch;
                }
                const integerMatch = String(Number(match[1]));
                if (doseOptions.includes(integerMatch)) {
                    return integerMatch;
                }
            }

            return doseOptions[0];
        }

        function getQuantityUnit(item) {
            return 'units';
        }

        function getQohUnit(item) {
            return isMlInventoryItem(item) ? 'mg' : 'units';
        }

        function getMedicationDoseValue(item) {
            if (!hasMedicationDoseSelector(item)) {
                return 0;
            }

            const doseValue = Number(item?.dose_option_mg || item?.semaglutide_dose_mg || getDefaultMedicationDose(item));
            return Number.isFinite(doseValue) ? doseValue : 0;
        }

        function getMedicationDosePrice(item, doseValue) {
            const doseKey = getMedicationDoseKey(item);
            const pricingMap = MEDICATION_DOSE_PRICING[doseKey];
            if (!pricingMap) {
                return null;
            }

            const normalizedDose = Number(doseValue || item?.dose_option_mg || getDefaultMedicationDose(item));
            if (!Number.isFinite(normalizedDose)) {
                return null;
            }

            const lookupKey = normalizedDose.toFixed(2);
            if (Object.prototype.hasOwnProperty.call(pricingMap, lookupKey)) {
                return pricingMap[lookupKey];
            }

            return null;
        }

        function applyDoseBasedPricing(item) {
            if (!item || !hasDoseBasedPricing(item) || item.is_manually_overridden) {
                return item;
            }

            const dosePrice = getMedicationDosePrice(item);
            if (dosePrice === null) {
                return item;
            }

            item.original_price = dosePrice;
            item.price = dosePrice;
            item.has_discount = false;
            item.discount_info = null;
            item.inventory_has_discount = false;
            item.inventory_discount_info = null;

            return item;
        }

        function getAdministerCapacityFromQoh(item) {
            if (!item || !(item.qoh > 0)) {
                return 999;
            }

            if (hasMedicationDoseSelector(item) && isMlInventoryItem(item)) {
                const doseValue = getMedicationDoseValue(item);
                if (doseValue > 0) {
                    return Math.floor(item.qoh / doseValue);
                }
            }

            return item.qoh;
        }

        function getQuantityStep(item) {
            return 1;
        }

        function getQuantityUnitFromForm(form) {
            return 'units';
        }

        function formatQuantityWithUnit(value, itemOrForm) {
            const unit = typeof itemOrForm === 'string'
                ? getQuantityUnitFromForm(itemOrForm)
                : getQuantityUnit(itemOrForm);
            return `${formatQuantityValue(value)} ${unit}`;
        }

        function updateMedicationDose(index, doseValue) {
            if (!cart[index]) {
                return;
            }

            const item = cart[index];
            const previousDose = normalizeMedicationDoseOption(item.dose_option_mg || getDefaultMedicationDose(item));
            const nextDose = normalizeMedicationDoseOption(doseValue || getDefaultMedicationDose(item));
            cart[index].dose_option_mg = String(nextDose || getDefaultMedicationDose(cart[index]));
            applyDoseBasedPricing(cart[index]);
            const maxAdminister = getMaxAdministerQuantity(cart[index], index);
            if ((cart[index].administer_quantity || 0) > maxAdminister) {
                cart[index].administer_quantity = maxAdminister;
            }
            const remainingDose = normalizeMedicationDoseOption(item.remaining_dose_mg || getRemainingDispenseDoseOption(item));
            const doseKey = getMedicationDoseKey(item);
            if (
                parseFloat(item.quantity || 0) <= 0 &&
                remainingDose !== '' &&
                nextDose !== '' &&
                previousDose !== nextDose &&
                (doseKey === 'semaglutide' || doseKey === 'testosterone')
            ) {
                alert(`${doseKey === 'semaglutide' ? 'Semaglutide' : 'Testosterone'} dose changed from ${remainingDose} mg to ${nextDose} mg. Administer-only should not be completed until any required price difference is paid.`);
            }
            updateOrderSummary();
            saveCartToStorage();
        }

        function formatQuantityValue(value) {
            const numericValue = Number(value || 0);
            if (!Number.isFinite(numericValue)) {
                return '0';
            }

            if (Math.abs(numericValue - Math.round(numericValue)) < 0.0001) {
                return String(Math.round(numericValue));
            }

            return numericValue.toFixed(3);
        }

        // Clear cart function
        function clearCart() {
            if (confirm('Are you sure you want to clear the cart? This action cannot be undone.')) {
                cart = [];
                prepayModeEnabled = false;
                tenDollarOffEnabled = false;
                prepayDetails = { date: '', saleReference: '' };
                clearPersistentCart(); // Clear persistent storage
                updateOrderSummary();
                updateConsultationCheckboxState();
                syncPrepayToggleState();
                syncTenDollarOffToggleState();
                removeRestoredCartIndicator();
                
                // Reset credit application when cart is cleared
                const creditCheckbox = document.getElementById('apply-credit-checkbox');
                if (creditCheckbox) {
                    creditCheckbox.checked = false;
                }
                window.creditAmount = 0;
                resetCheckoutPaymentSession();
                updatePaymentSummary();
                
                console.log('Cart cleared manually');
            }
        }

        // Add indicator to Order Summary header when cart is restored
        function addRestoredCartIndicator() {
            const orderHeader = document.querySelector('.order-header h3');
            if (orderHeader && !orderHeader.querySelector('.restored-indicator')) {
                const indicator = document.createElement('span');
                indicator.className = 'restored-indicator';
                indicator.innerHTML = ' <i class="fas fa-history" style="color: #28a745; font-size: 14px;" title="Items restored from previous session"></i>';
                orderHeader.appendChild(indicator);
            }
        }

        // Remove indicator from Order Summary header
        function removeRestoredCartIndicator() {
            const indicator = document.querySelector('.restored-indicator');
            if (indicator) {
                indicator.remove();
            }
        }



        // Initialize when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            setWeightRecordedForToday(false);
            syncPrepayToggleState();
            
            // Load persistent cart for this patient
            loadPersistentCart();
            
            // Update consultation checkbox state after cart is loaded
            setTimeout(updateConsultationCheckboxState, 100);
            
            // Set up automatic patient data refresh
            if (<?php echo $pid ?: 'null'; ?>) {
                // Refresh patient data every 30 seconds
                setInterval(refreshPatientData, 30000);
                
                // Also refresh when user focuses on the window
                window.addEventListener('focus', refreshPatientData);
                
                // Load remaining dispense quantities for the patient
                setTimeout(loadRemainingDispenseData, 500);
                
                // Load current patient weight
                setTimeout(loadCurrentWeight, 500);
                
                // Load patient credit balance
                setTimeout(loadPatientCreditBalance, 500);
                
                // Don't automatically open dispense tracking - show POS view by default
                // Auto-check for remaining dispenses when modal loads (but don't auto-open dispense tracking)
                setTimeout(function() {
                    checkForRemainingDispenses();
                }, 1000);
            }
            
            // Set up search input
            const searchInput = document.getElementById('search-input');
            
            if (searchInput) {
                searchInput.setAttribute('autocomplete', 'off');
                searchInput.setAttribute('autocorrect', 'off');
                searchInput.setAttribute('autocapitalize', 'off');
                searchInput.setAttribute('spellcheck', 'false');

                // Input event for search
                searchInput.addEventListener('input', function(e) {
                    const searchTerm = e.target.value.trim();
                    
                    if (searchTerm.length > 0) {
                        searchInventory(searchTerm);
                    } else {
                        hideSearchResults();
                    }
                });

                // Keypress event for Enter key
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        addItem(this.value.trim());
                    }
                });

                // Focus event
                searchInput.addEventListener('focus', function() {
                    if (this.value.trim().length > 0) {
                        searchInventory(this.value.trim());
                    }
                });
            }

            // Hide search results when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.search-section')) {
                    hideSearchResults();
                }
                
                // Hide transfer patient search results when clicking outside
                const transferSearchInputs = document.querySelectorAll('[id^="transfer-patient-search-"]');
                transferSearchInputs.forEach(input => {
                    const productKey = input.id.replace('transfer-patient-search-', '');
                    const resultsDiv = document.getElementById(`transfer-patient-results-${productKey}`);
                    if (resultsDiv && !resultsDiv.contains(e.target) && !input.contains(e.target)) {
                        resultsDiv.style.display = 'none';
                    }
                });
            });

            // Add keyboard shortcut for navigating back (Escape key)
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    navigateBackToFinder();
                }
            });

            // Stripe disabled for terminal-only workflow
            // initializeStripe();

            // Set up payment amount input
            const paymentAmountInput = document.getElementById('payment-amount');
            if (paymentAmountInput) {
                paymentAmountInput.addEventListener('input', function() {
                    calculateChange();
                });
                
                // Auto-fill remaining balance when field is focused
                paymentAmountInput.addEventListener('focus', function() {
                    if (!this.value || this.value === '0') {
                        const paidSoFar = (window._posPayments || []).reduce((sum, p) => sum + (parseFloat(p.amount) || 0), 0);
                        const grandTotal = getCheckoutTotals().grandTotal;
                        const remainingBalance = Math.max(0, Math.round((grandTotal - paidSoFar) * 100) / 100);
                        
                        if (remainingBalance > 0) {
                            this.value = remainingBalance.toFixed(2);
                            this.placeholder = `Remaining: $${remainingBalance.toFixed(2)}`;
                            calculateChange();
                        }
                    }
                });
            }
            
            // Set up payment method change handler
            const paymentMethodSelect = document.getElementById('payment-method');
            if (paymentMethodSelect) {
                paymentMethodSelect.addEventListener('change', handlePaymentMethodChange);
            }
            
            // Set up consultation checkbox handler
            const consultationCheckbox = document.getElementById('consultation-checkbox');
            if (consultationCheckbox) {
                consultationCheckbox.addEventListener('change', toggleConsultation);
            }

            // Start real-time discount updates
            startDiscountAutoRefresh();
            
            // Initialize table headers
            updateTableHeaders();

            // Set up blood work checkbox handler
            const bloodworkCheckbox = document.getElementById('bloodwork-checkbox');
            if (bloodworkCheckbox) {
                bloodworkCheckbox.addEventListener('change', toggleBloodWork);
            }
            const injectionCheckbox = document.getElementById('injection-checkbox');
            if (injectionCheckbox) {
                 injectionCheckbox.addEventListener('change', toggleInjection);
                 injectionCheckbox.addEventListener('change', function () {
                    updateWeightRecordingStatus();
                    if (currentStep === 1) {
                        updateWeightValidationStatus();
                    }
                 });
            }
        });

        // Search inventory with debouncing
        function searchInventory(searchTerm) {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                performSearch(searchTerm);
            }, 300);
        }
        // Perform AJAX search
        function performSearch(searchTerm) {
            searchTerm = (searchTerm || '').trim();
            if (searchTerm.length < 2) {
                currentSearchResults = [];
                hideSearchResults();
                return Promise.resolve([]);
            }

            // Use simple search API without authentication
            var searchUrl = posSimpleSearchUrl + '?search=' + encodeURIComponent(searchTerm) + '&t=' + Date.now();
            if (currentFacilityId) {
                searchUrl += '&facility_id=' + encodeURIComponent(currentFacilityId);
            }

            activeInventorySearchPromise = fetch(searchUrl)
                .then(response => response.json())
                .then(data => {
                    if (data.results && data.results.length > 0) {
                        displaySearchResults(data.results);
                        return data.results;
                    } else {
                        currentSearchResults = [];
                        hideSearchResults();
                        return [];
                    }
                })
                .catch(error => {
                    console.error('Search failed:', error);
                    currentSearchResults = [];
                    hideSearchResults();
                    return [];
                });

            return activeInventorySearchPromise;
        }

        // Display search results
        function displaySearchResults(results) {
            currentSearchResults = results;
            var resultsContainer = document.getElementById('search-results');

            if (results.length === 0) {
                resultsContainer.innerHTML = '<div class="search-result-item" style="padding: 20px; text-align: center; color: #6c757d;">No items found</div>';
                resultsContainer.style.display = 'block';
                resultsContainer.style.visibility = 'visible';
                resultsContainer.style.opacity = '1';
                return;
            }

            var html = '<div style="background: #f8f9fa; color: #333; padding: 12px; border-bottom: 1px solid #dee2e6; font-weight: 600; font-size: 14px; text-align: center;">Found ' + results.length + ' results - Click to select</div>';
            results.forEach(function(item, index) {
                // Check if this item is already in the cart
                const isInCart = cart.some(cartItem => cartItem.id === item.id);
                const itemStyle = isInCart ? 'background: #f8f9fa; opacity: 0.7; cursor: not-allowed;' : 'background: white; cursor: pointer;';
                const itemClass = isInCart ? 'search-result-item-disabled' : 'search-result-item';
                
                html += '<div class="' + itemClass + '" onclick="selectItem(\'' + item.id + '\')" style="' + itemStyle + '">';
                html += '<div class="search-result-info">';
                html += '<div class="search-result-name" style="color: #333; font-weight: 500; font-size: 16px; display: flex; gap: 30px; align-items: center; flex-wrap: wrap;">';
                html += '<span>' + item.display_name + '</span>';
                if (isInCart) {
                    html += '<span style="color: #28a745; font-weight: 600; font-size: 12px; background: #d4edda; padding: 2px 8px; border-radius: 4px;">✓ In Cart</span>';
                }
                if (item.form && item.form !== '0') {
                    html += '<span style="color: #6c757d; font-weight: 500;">Form: ' + item.form + '</span>';
                }
                if (item.unit && item.unit !== '0') {
                    html += '<span style="color: #6c757d; font-weight: 500;">Unit: ' + item.unit + '</span>';
                }
                html += '</div>';
                
                // Enhanced lot information display - only for medical products
                const isMedical = item.category_name === 'Medical';
                
                if (isMedical) {
                html += '<div class="search-result-lot" style="margin-top: 8px; font-size: 16px; display: flex; gap: 30px; flex-wrap: wrap; background: #f8f9fa; padding: 10px; border-radius: 6px; width: fit-content;">';
                if (item.lot_number && item.lot_number !== 'No Lot') {
                    html += '<span style="color: #6c757d; font-weight: 500;">Lot #' + item.lot_number + '</span>';
                                    if (item.qoh !== undefined && item.qoh !== null) {
                        html += '<span style="color: #6c757d; font-weight: 500;">QOH: ' + formatQuantityValue(item.qoh) + ' ' + getQohUnit(item) + '</span>';
                    }
                    if (item.expiration) {
                        html += '<span style="color: #6c757d; font-weight: 500;">Exp: ' + item.expiration + '</span>';
                    }
                } else {
                    html += '<span style="color: #6c757d; font-weight: 500;">No Lot Available</span>';
                }
                html += '</div>';
                }
                html += '</div>';
                
                // Display actual sell price (original price, not discounted)
                html += '<div class="search-result-price" style="color: #333; font-weight: 600; font-size: 15px;">$' + item.original_price.toFixed(2) + '</div>';
                html += '</div>';
            });

            resultsContainer.innerHTML = html;
            resultsContainer.style.display = 'block';
            resultsContainer.style.visibility = 'visible';
            resultsContainer.style.opacity = '1';
            resultsContainer.style.zIndex = '999999';
            resultsContainer.style.position = 'absolute';
        }

        // Hide search results
        function hideSearchResults() {
            var resultsContainer = document.getElementById('search-results');
            if (resultsContainer) {
                resultsContainer.style.display = 'none';
            }
        }

        // Select item from search results
        function selectItem(itemId) {
            
            const item = currentSearchResults.find(result => result.id === itemId);
            if (item) {
                // Check if this exact item is already in the cart
                const existingItem = cart.find(cartItem => cartItem.id === item.id);
                if (existingItem) {
                    // Item already in cart, just hide search results
                    hideSearchResults();
                    document.getElementById('search-input').value = '';
                    return;
                }
                
                addToCart(item);
                hideSearchResults();
                document.getElementById('search-input').value = '';
            }
        }

        // Add item to cart
        function addToCart(item) {
            
            // Check if item already exists in cart
            const existingItem = cart.find(cartItem => cartItem.id === item.id);
            
            if (existingItem) {
                // Check if adding one more would exceed QOH
                if (item.qoh !== undefined && item.qoh > 0) {
                    if (existingItem.quantity >= item.qoh) {
                        alert('Cannot add more items. Quantity on Hand (QOH): ' + formatQuantityValue(item.qoh) + ' ' + getQohUnit(item));
                        return;
                    }
                }
                existingItem.quantity += getQuantityStep(item);
                existingItem.dispense_quantity = existingItem.dispense_quantity || 1;
                
                // Always check for remaining dispense integration for existing items
                const currentPid = <?php echo $pid ?: 'null'; ?>;
                if (currentPid) {
                    // Create a temporary item object with all necessary properties
                    const tempItem = {
                        id: item.id,
                        name: item.name || item.display_name,
                        drug_id: item.drug_id || item.id.split('_')[1],
                        lot_number: item.lot_number || item.lot
                    };
                    
                    checkAndIntegrateRemainingDispense(tempItem).then(integratedItem => {
                        if (integratedItem.has_remaining_dispense) {
                            // Update existing item with remaining dispense integration
                            existingItem.has_remaining_dispense = true;
                            existingItem.remaining_dispense_items = integratedItem.remaining_dispense_items;
                            existingItem.total_remaining_quantity = integratedItem.total_remaining_quantity;
                            existingItem.display_name = integratedItem.display_name;
                            existingItem.dose_option_mg = integratedItem.dose_option_mg || existingItem.dose_option_mg || '';
                            existingItem.locked_remaining_dose_mg = integratedItem.locked_remaining_dose_mg || existingItem.locked_remaining_dose_mg || '';
                            applyRemainingDispenseDoseLock(existingItem);
                            
                            // Update dispense quantity and max_dispense_quantity to include remaining quantities
                            const maxDispenseFromRemaining = existingItem.quantity + integratedItem.total_remaining_quantity;
                            const maxDispenseFromInventory = existingItem.qoh || 999;
                            const maxDispense = Math.min(maxDispenseFromRemaining, maxDispenseFromInventory);
                            
                            existingItem.max_dispense_quantity = maxDispense;
                            existingItem.dispense_quantity = Math.min(existingItem.dispense_quantity, maxDispense);
                            
                            // Show notification about remaining dispense integration
                            const itemText = integratedItem.remaining_dispense_items.length === 1 ? 'item' : 'items';
                            const remainingLabel = `${formatQuantityValue(integratedItem.total_remaining_quantity)} remaining quantities`;
                            showNotification(`Found ${integratedItem.remaining_dispense_items.length} ${itemText} with ${remainingLabel} for this product. These will be integrated into your dispense.`, 'info');
                        }
                        
                        // Apply dynamic adjustment after adding item
                        const itemIndex = cart.findIndex(cartItem => cartItem.id === item.id);
                        if (itemIndex !== -1) {
                            applyDynamicQuantityAdjustment(itemIndex);
                        }
                        
                        updateOrderSummary();
                        saveCartToStorage();
                        
                        // Recalculate credit amount if credit is being applied
                        recalculateCreditIfApplied();
                    });
                    return; // Exit early as we're handling the item asynchronously
                }
            } else {
                // Check for remaining dispense quantities for this product
                checkAndIntegrateRemainingDispense(item).then(integratedItem => {
                    applyDoseBasedPricing(integratedItem);
                    cart.push(integratedItem);
                    
                    // Apply dynamic adjustment after adding new item
                    const itemIndex = cart.length - 1;
                    applyDynamicQuantityAdjustment(itemIndex);
                    
                    updateOrderSummary();
                    saveCartToStorage();
                    
                    // Recalculate credit amount if credit is being applied
                    const creditCheckbox = document.getElementById('apply-credit-checkbox');
                    if (creditCheckbox && creditCheckbox.checked) {
                        applyFullCreditBalance();
                    }
                });
                return; // Exit early as we're handling the item asynchronously
            }
            
            updateOrderSummary();
            saveCartToStorage(); // Save cart to persistent storage
            
            // Recalculate credit amount if credit is being applied
            const creditCheckbox = document.getElementById('apply-credit-checkbox');
            if (creditCheckbox && creditCheckbox.checked) {
                applyFullCreditBalance();
            }
        }

        function normalizeRemainingDispenseMatchValue(value) {
            return String(value === undefined || value === null ? '' : value).trim();
        }

        function resolveRemainingDispenseMatches(remainingItems, itemDrugId, itemLotNumber) {
            const normalizedDrugId = normalizeRemainingDispenseMatchValue(itemDrugId);
            const normalizedLotNumber = normalizeRemainingDispenseMatchValue(itemLotNumber);
            const activeRemainingItems = Array.isArray(remainingItems) ? remainingItems.filter(remainingItem =>
                normalizeRemainingDispenseMatchValue(remainingItem.drug_id) === normalizedDrugId &&
                parseFloat(remainingItem.remaining_quantity || 0) > 0
            ) : [];

            const exactMatchingRemainingItems = activeRemainingItems.filter(remainingItem =>
                normalizeRemainingDispenseMatchValue(remainingItem.lot_number) === normalizedLotNumber
            );

            const otherLotRemainingItems = activeRemainingItems.filter(remainingItem =>
                normalizeRemainingDispenseMatchValue(remainingItem.lot_number) !== normalizedLotNumber
            );

            const dedupeMostRecentPerLot = function(items) {
                const lotMap = new Map();
                items.forEach((remainingItem) => {
                    const key = `${normalizeRemainingDispenseMatchValue(remainingItem.drug_id)}_${normalizeRemainingDispenseMatchValue(remainingItem.lot_number)}`;
                    if (!lotMap.has(key)) {
                        lotMap.set(key, remainingItem);
                        return;
                    }

                    const existing = lotMap.get(key);
                    const existingDate = new Date(existing.last_updated || existing.created_date || 0);
                    const currentDate = new Date(remainingItem.last_updated || remainingItem.created_date || 0);
                    if (currentDate > existingDate) {
                        lotMap.set(key, remainingItem);
                    }
                });

                return Array.from(lotMap.values());
            };

            let matchingRemainingItems = [];
            let isDifferentLot = false;
            const currentLotCompleted = false;
            const hasOtherLotRemaining = otherLotRemainingItems.length > 0;

            if (exactMatchingRemainingItems.length > 0) {
                matchingRemainingItems = exactMatchingRemainingItems.length > 1
                    ? dedupeMostRecentPerLot(exactMatchingRemainingItems).slice(0, 1)
                    : exactMatchingRemainingItems;
            } else if (otherLotRemainingItems.length > 0) {
                matchingRemainingItems = dedupeMostRecentPerLot(otherLotRemainingItems);
                isDifferentLot = true;
            }

            return {
                matchingRemainingItems,
                isDifferentLot,
                currentLotCompleted,
                hasOtherLotRemaining
            };
        }

        function calculateAvailableRemainingQuantity(matchingRemainingItems, currentCart, sourceItemId) {
            let totalRemaining = matchingRemainingItems.reduce((sum, remainingItem) =>
                sum + (parseFloat(remainingItem.remaining_quantity || 0) || 0), 0
            );

            if (!Array.isArray(currentCart) || currentCart.length === 0) {
                return totalRemaining;
            }

            for (const cartItem of currentCart) {
                if (sourceItemId && cartItem.id === sourceItemId) {
                    continue;
                }

                if (!cartItem.has_remaining_dispense || !Array.isArray(cartItem.remaining_dispense_items)) {
                    continue;
                }

                for (const cartRemainingItem of cartItem.remaining_dispense_items) {
                    for (const matchingItem of matchingRemainingItems) {
                        if (
                            normalizeRemainingDispenseMatchValue(cartRemainingItem.drug_id) === normalizeRemainingDispenseMatchValue(matchingItem.drug_id) &&
                            normalizeRemainingDispenseMatchValue(cartRemainingItem.lot_number) === normalizeRemainingDispenseMatchValue(matchingItem.lot_number)
                        ) {
                            const usedQuantity = Math.min(
                                parseFloat(cartItem.dispense_quantity || 0) || 0,
                                parseFloat(cartRemainingItem.remaining_quantity || 0) || 0
                            );
                            totalRemaining = Math.max(0, totalRemaining - usedQuantity);
                        }
                    }
                }
            }

            return totalRemaining;
        }

        async function refreshCartRemainingDispenseState() {
            const currentPid = <?php echo $pid ?: 'null'; ?>;
            if (!currentPid || !Array.isArray(cart) || cart.length === 0) {
                return;
            }

            const response = await fetch(`pos_remaining_dispense.php?pid=${currentPid}`, {
                credentials: 'same-origin'
            });
            const data = await response.json();
            if (!data.success || !Array.isArray(data.remaining_items)) {
                return;
            }

            cart.forEach((item) => {
                if (!item || (item.id && item.id.startsWith('consultation_'))) {
                    return;
                }

                const itemDrugId = item.drug_id || (item.id && item.id.includes('drug_') ? item.id.split('_')[1] : '');
                const itemLotNumber = item.lot_number || item.lot || '';
                if (!itemDrugId) {
                    return;
                }

                const matches = resolveRemainingDispenseMatches(data.remaining_items, itemDrugId, itemLotNumber);
                if (!matches.matchingRemainingItems.length) {
                    item.has_remaining_dispense = false;
                    item.remaining_dispense_items = [];
                    item.total_remaining_quantity = 0;
                    item.is_different_lot_dispense = false;
                    item.max_dispense_quantity = Math.min(parseFloat(item.quantity || 0) || 0, parseFloat(item.qoh || 999) || 999);
                    return;
                }

                const totalRemaining = calculateAvailableRemainingQuantity(matches.matchingRemainingItems, cart, item.id);
                item.has_remaining_dispense = totalRemaining > 0;
                item.remaining_dispense_items = matches.matchingRemainingItems;
                item.total_remaining_quantity = totalRemaining;
                item.is_different_lot_dispense = matches.isDifferentLot;
                item.max_dispense_quantity = Math.min(
                    (parseFloat(item.quantity || 0) || 0) + totalRemaining,
                    parseFloat(item.qoh || 999) || 999
                );
                applyRemainingDispenseDoseLock(item);
            });
        }

        // Check for remaining dispense quantities and integrate them
        async function checkAndIntegrateRemainingDispense(item) {
            const currentPid = <?php echo $pid ?: 'null'; ?>;
            if (!currentPid) {
                // If no patient, just add the item normally
                    return {
                        id: item.id,
                        name: item.name,
                        display_name: item.name,
                        form: item.form || '',
                        is_ml_form: isMlInventoryItem(item),
                        quantity_unit: item.quantity_unit || getQuantityUnit(item),
                        quantity_step: item.quantity_step || getQuantityStep(item),
                        price: item.price,
                        original_price: item.original_price || item.price,
                        lot: item.lot_number || 'NA',
                        lot_number: item.lot_number || 'NA',
                    drug_id: item.drug_id || item.id.split('_')[1],
                    qoh: item.qoh || 0,
                        quantity: 1,
                        dispense_quantity: 1,
                        marketplace_dispense_quantity: 0,
                        marketplace_dispense: false,
                        dose_option_mg: hasMedicationDoseSelector(item) ? getDefaultMedicationDose(item) : '',
                        icon: getItemIcon(item.type),
                        expiration: item.expiration,
                    has_discount: item.has_discount || false,
                    discount_info: item.discount_info || null,
                    category_name: item.category_name || 'Medical'
                };
            }

            try {
                // Fetch remaining dispense data for this patient
                const response = await fetch(`pos_remaining_dispense.php?pid=${currentPid}`);
                const data = await response.json();
                
                if (data.success && data.remaining_items && data.remaining_items.length > 0) {
                    // Extract drug_id and lot_number from item (handle different item structures)
                    let itemDrugId = item.drug_id;
                    let itemLotNumber = item.lot_number;
                    
                    // If drug_id is not directly available, try to extract from item.id (format: drug_123_lot_456)
                    if (!itemDrugId && item.id && item.id.includes('drug_')) {
                        const idParts = item.id.split('_');
                        if (idParts.length >= 4) {
                            itemDrugId = idParts[1]; // drug_id
                            itemLotNumber = idParts[3]; // lot_number
                        }
                    }
                    
                    // Fallback to lot if lot_number is not available
                    if (!itemLotNumber && item.lot) {
                        itemLotNumber = item.lot;
                    }
                    

                    
                    // Get all remaining items for this product (filter out items with remaining_quantity <= 0)
                    const {
                        matchingRemainingItems,
                        isDifferentLot,
                        currentLotCompleted,
                        hasOtherLotRemaining
                    } = resolveRemainingDispenseMatches(data.remaining_items, itemDrugId, itemLotNumber);
                    

                    
                    if (matchingRemainingItems.length > 0) {
                        // Calculate total remaining quantity, but subtract what's already being dispensed in the cart
                        const currentCart = getCartFromStorage();
                        let totalRemaining = calculateAvailableRemainingQuantity(matchingRemainingItems, currentCart, item.id);
                        
                        // Show notification about remaining dispense
                        const itemText = matchingRemainingItems.length === 1 ? 'item' : 'items';
                        let lotInfo = '';
                        
                        if (isDifferentLot) {
                            if (currentLotCompleted && hasOtherLotRemaining) {
                                lotInfo = ' (current lot completed, using remaining quantities from other lots)';
                            } else {
                                lotInfo = ' (using different lot number)';
                            }
                        }
                        
                        const remainingLabel = `${formatQuantityValue(totalRemaining)} remaining quantities`;
                        showNotification(`Found ${matchingRemainingItems.length} ${itemText} with ${remainingLabel} for this product${lotInfo}. These will be integrated into your dispense.`, 'info');
                        
                        // Calculate maximum dispense quantity considering both remaining dispense and inventory QOH
                        const newPurchaseQuantity = 1; // Default new purchase quantity
                        const maxDispenseFromRemaining = totalRemaining + newPurchaseQuantity; // Remaining quantities + new purchase
                        const maxDispenseFromInventory = item.qoh || 999;
                        const maxDispense = Math.min(maxDispenseFromRemaining, maxDispenseFromInventory);
                        
                        const remainingDoseOption = getRemainingDispenseDoseOption(matchingRemainingItems);

                        // Create integrated item with remaining dispense info
                        let displayName = '';
                        if (isDifferentLot) {
                            if (currentLotCompleted && hasOtherLotRemaining) {
                                displayName = `${item.name} (${formatQuantityValue(totalRemaining)} remaining available - replacement lot)`;
                            } else {
                                displayName = `${item.name} (${formatQuantityValue(totalRemaining)} remaining available - different lot)`;
                            }
                        } else {
                            // Only using current lot (we removed the logic that adds alternate lots)
                                displayName = `${item.name} (${formatQuantityValue(totalRemaining)} remaining available)`;
                        }
                        
                        return applyRemainingDispenseDoseLock({
                            id: item.id,
                            name: item.name,
                            display_name: displayName,
                            form: item.form || '',
                            is_ml_form: isMlInventoryItem(item),
                            quantity_unit: item.quantity_unit || getQuantityUnit(item),
                            quantity_step: item.quantity_step || getQuantityStep(item),
                            price: item.price,
                            original_price: item.original_price || item.price,
                            lot: item.lot_number || 'NA',
                            lot_number: item.lot_number || 'NA',
                            drug_id: item.drug_id || item.id.split('_')[1],
                            qoh: item.qoh || 0,
                            quantity: 1, // New purchase quantity
                            dispense_quantity: 1, // Start with new purchase quantity, user can increase up to max
                            marketplace_dispense_quantity: 0,
                            marketplace_dispense: false,
                            dose_option_mg: remainingDoseOption || (hasMedicationDoseSelector(item) ? getDefaultMedicationDose(item) : ''),
                            locked_remaining_dose_mg: remainingDoseOption,
                            icon: getItemIcon(item.type),
                            expiration: item.expiration,
                            has_discount: item.has_discount || false,
                            discount_info: item.discount_info || null,
                            category_name: item.category_name || 'Medical',
                            has_remaining_dispense: true,
                            remaining_dispense_items: matchingRemainingItems,
                            total_remaining_quantity: totalRemaining,
                            max_dispense_quantity: maxDispense, // Store the maximum allowed dispense quantity
                            is_different_lot_dispense: isDifferentLot // Flag to indicate we're using different lot
                        });
                    }
                }
                
                // No remaining dispense found, add normally
                return {
                    id: item.id,
                    name: item.name,
                    display_name: item.name,
                    form: item.form || '',
                    is_ml_form: isMlInventoryItem(item),
                    quantity_unit: item.quantity_unit || getQuantityUnit(item),
                    quantity_step: item.quantity_step || getQuantityStep(item),
                    price: item.price,
                    original_price: item.original_price || item.price,
                    lot: item.lot_number || 'NA',
                    lot_number: item.lot_number || 'NA',
                    drug_id: item.drug_id || item.id.split('_')[1],
                    qoh: item.qoh || 0,
                    quantity: 1,
                    dispense_quantity: 1,
                    marketplace_dispense_quantity: 0,
                    marketplace_dispense: false,
                    dose_option_mg: hasMedicationDoseSelector(item) ? getDefaultMedicationDose(item) : '',
                    icon: getItemIcon(item.type),
                    expiration: item.expiration,
                    has_discount: item.has_discount || false,
                    discount_info: item.discount_info || null,
                    category_name: item.category_name || 'Medical'
                };
                
            } catch (error) {
                console.error('Error checking remaining dispense:', error);
                // Fallback to normal item addition
                return {
                    id: item.id,
                    name: item.name,
                    display_name: item.name,
                    form: item.form || '',
                    is_ml_form: isMlInventoryItem(item),
                    quantity_unit: item.quantity_unit || getQuantityUnit(item),
                    quantity_step: item.quantity_step || getQuantityStep(item),
                    price: item.price,
                    original_price: item.original_price || item.price,
                    lot: item.lot_number || 'NA',
                    lot_number: item.lot_number || 'NA',
                    drug_id: item.drug_id || item.id.split('_')[1],
                    qoh: item.qoh || 0,
                    quantity: 1,
                    dispense_quantity: 1,
                    marketplace_dispense_quantity: 0,
                    marketplace_dispense: false,
                    dose_option_mg: hasMedicationDoseSelector(item) ? getDefaultMedicationDose(item) : '',
                    icon: getItemIcon(item.type),
                    expiration: item.expiration,
                    has_discount: item.has_discount || false,
                    discount_info: item.discount_info || null,
                    category_name: item.category_name || 'Medical'
                };
            }
        }
        // Show notification message
        function showNotification(message, type = 'info') {
            // Create notification element
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                border-radius: 8px;
                color: white;
                font-weight: 600;
                z-index: 10000;
                max-width: 400px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
                animation: slideIn 0.3s ease-out;
            `;
            
            // Set background color based on type
            switch(type) {
                case 'success':
                    notification.style.background = 'linear-gradient(135deg, #28a745 0%, #20c997 100%)';
                    break;
                case 'warning':
                    notification.style.background = 'linear-gradient(135deg, #ffc107 0%, #fd7e14 100%)';
                    break;
                case 'error':
                    notification.style.background = 'linear-gradient(135deg, #dc3545 0%, #c82333 100%)';
                    break;
                default:
                    notification.style.background = 'linear-gradient(135deg, #007bff 0%, #0056b3 100%)';
            }
            
            notification.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'warning' ? 'exclamation-triangle' : type === 'error' ? 'times-circle' : 'info-circle'}"></i>
                    <span>${message}</span>
                </div>
            `;
            
            // Add to page
            document.body.appendChild(notification);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease-in';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 5000);
        }

        // Get icon for item type
        function getItemIcon(type) {
            switch(type) {
                case 'drug': return 'drug';
                case 'consultation': return 'consultation';
                case 'shake': return 'shake';
                default: return 'drug';
            }
        }

        // Check if a product is medical category
        function isMedicalProduct(item) {
            if (!item.category_name) return true; // Default to medical if no category
            return item.category_name === 'Medical';
        }

        function isTaxableItem(item) {
            const n = ((item && item.name) ? item.name : '').toLowerCase();
            return (
                n.includes('metatrim') ||
                n.includes('meta trim') ||
                n.includes('baricare') ||
                n.includes('bari care')
            );
        }

        // Update table headers based on cart contents
        function updateTableHeaders() {
            // Always show headers and cells - just leave them empty for non-medical products
            const lotHeader = document.querySelector('th:nth-child(2)'); // Lot no column
            const dispenseHeader = document.querySelector('th:nth-child(4)'); // Dispense column
            
            if (lotHeader) {
                lotHeader.style.display = 'table-cell';
            }
            if (dispenseHeader) {
                dispenseHeader.style.display = 'table-cell';
            }
        }

        // Update quantity
        function updateQuantity(index, change) {
            if (cart[index]) {
                // Prevent quantity changes for consultation items
                if (cart[index].id.startsWith('consultation_')) {
                    return;
                }
                
                const newQuantity = cart[index].quantity + change;
                
                // Check QOH limits
                if (cart[index].qoh > 0 && newQuantity > cart[index].qoh) {
                    alert('Cannot exceed Quantity on Hand (QOH): ' + formatQuantityValue(cart[index].qoh) + ' ' + getQohUnit(cart[index]));
                    return;
                }
                
                // Allow quantity to be 0 for dispensing-only items
                if (newQuantity < 0) {
                    newQuantity = 0;
                }
                
                    cart[index].quantity = newQuantity;
                
                // Update max_dispense_quantity based on new quantity and remaining dispense
                if (cart[index].has_remaining_dispense) {
                    const totalRemaining = cart[index].total_remaining_quantity || 0;
                    const maxDispense = newQuantity + totalRemaining;
                    cart[index].max_dispense_quantity = Math.min(maxDispense, cart[index].qoh || 999);
                } else {
                    cart[index].max_dispense_quantity = Math.min(newQuantity, cart[index].qoh || 999);
                }
                
                // Auto-adjust dispense quantity if it exceeds the new maximum
                const currentDispense = cart[index].dispense_quantity || 0;
                const newMaxDispense = cart[index].max_dispense_quantity;
                
                if (currentDispense > newMaxDispense) {
                    cart[index].dispense_quantity = newMaxDispense;
                    
                    if (cart[index].has_remaining_dispense) {
                        const totalRemaining = cart[index].total_remaining_quantity || 0;
                        showNotification(`Quantity changed. Dispense quantity adjusted to maximum available: ${newQuantity} (new purchase) + ${totalRemaining} (remaining) = ${newMaxDispense} total.`, 'info');
                    } else {
                        showNotification(`Quantity reduced. Dispense quantity adjusted to match your new purchase quantity (${newQuantity}).`, 'info');
                    }
                }
                
                // Validate marketplace dispense for Hoover facility (no auto-adjustment)
                if (isHooverFacility) {
                    // For Hoover facility, validate: Marketplace Dispense + Administer <= QTY + Remaining Dispense
                    const currentAdminister = cart[index].administer_quantity || 0;
                    const currentMarketplaceDispense = cart[index].marketplace_dispense_quantity || 0;
                    const totalRemainingQuantity = cart[index].total_remaining_quantity || 0;
                    const totalAvailable = newQuantity + totalRemainingQuantity;
                    const totalRequested = currentMarketplaceDispense + currentAdminister;
                    
                    // Only show warning when increasing QTY (change > 0), not when decreasing
                    // When decreasing QTY, allow it silently - user can adjust marketplace dispense/administer next
                    if (totalRequested > totalAvailable && change > 0) {
                        alert(`Warning: Marketplace Dispense (${currentMarketplaceDispense}) + Administer (${currentAdminister}) = ${totalRequested} exceeds available quantity (QTY: ${newQuantity} + Remaining Dispense: ${totalRemainingQuantity} = ${totalAvailable}).\n\nPlease increase QTY or reduce marketplace dispense/administer quantities.`);
                        // Don't prevent QTY change, just warn
                    }
                    
                    console.log(`Hoover facility: QTY changed to ${newQuantity}, Marketplace: ${currentMarketplaceDispense}, Administer: ${currentAdminister}, Remaining: ${totalRemainingQuantity}, Total Available: ${totalAvailable} for item ${index}`);
                }
                
                // Always apply dynamic adjustment logic - this ensures quantities are always balanced
                applyDynamicQuantityAdjustment(index);
                
                // Keep item in cart even when both quantity and dispense are 0 (for tracking purposes)
                // Removed automatic removal logic
                
                updateOrderSummary();
                saveCartToStorage(); // Save cart to persistent storage
                
                // Recalculate credit amount if credit is being applied
                const creditCheckbox = document.getElementById('apply-credit-checkbox');
                if (creditCheckbox && creditCheckbox.checked) {
                    applyFullCreditBalance();
                }
            }
        }

        // Dynamic quantity adjustment function - ensures quantities are always balanced
        function applyDynamicQuantityAdjustment(index) {
            if (!cart[index]) return;
            
            const item = cart[index];
            const currentDispense = item.dispense_quantity || 0;
            const currentAdminister = item.administer_quantity || 0;
            const totalRemaining = item.total_remaining_quantity || 0;
            const totalAvailable = item.quantity + totalRemaining;
            const totalUsed = currentDispense + currentAdminister;
            
            // For Hoover facilities, handle marketplace dispense quantity
            if (isHooverFacility) {
                const currentMarketplaceDispense = item.marketplace_dispense_quantity || 0;
                const currentAdminister = item.administer_quantity || 0;
                const currentQty = item.quantity || 0;
                
                // Ensure marketplace dispense quantity uses the product-specific pack increment.
                let adjustedMarketplaceDispense = roundMarketplaceDispenseQuantity(currentMarketplaceDispense, item);
                if (adjustedMarketplaceDispense < 0) adjustedMarketplaceDispense = 0;
                
                // Check QOH limits - use the current QTY (user may have manually set it)
                // Allow QTY to be greater than Marketplace Dispense + Administer, but still respect QOH
                const effectiveQty = currentQty > 0 ? currentQty : (adjustedMarketplaceDispense + currentAdminister);
                if (item.qoh > 0 && effectiveQty > item.qoh) {
                    // If total exceeds QOH, reduce marketplace dispense to fit within QOH
                    const maxMarketplaceDispense = Math.max(0, item.qoh - currentAdminister);
                    adjustedMarketplaceDispense = floorMarketplaceDispenseQuantity(maxMarketplaceDispense, item);
                    if (adjustedMarketplaceDispense < 0) adjustedMarketplaceDispense = 0;
                    
                    // Only adjust QTY if it exceeds QOH (allow QTY to be greater than Marketplace Dispense + Administer)
                    if (currentQty > item.qoh) {
                        const newQty = Math.min(currentQty, item.qoh); // Cap at QOH but don't force to Marketplace + Administer
                        cart[index].quantity = newQty;
                        console.log(`Hoover facility: QTY (${currentQty}) exceeds QOH (${item.qoh}), adjusted to ${newQty}`);
                    }
                }
                
                // Update marketplace dispense quantity and sync with dispense_quantity
                cart[index].marketplace_dispense_quantity = adjustedMarketplaceDispense;
                cart[index].dispense_quantity = adjustedMarketplaceDispense;
                cart[index].marketplace_dispense = adjustedMarketplaceDispense > 0;
                
                // Don't auto-update QTY - let user control it, but validate on payment
                console.log(`Hoover facility: Marketplace dispense: ${adjustedMarketplaceDispense}, Administer: ${currentAdminister}, QTY: ${cart[index].quantity} for item ${index}`);
                return; // Skip regular adjustment logic for Hoover facilities
            }
            
            // If total used exceeds total available, reduce quantities in priority order
            if (totalUsed > totalAvailable) {
                let remainingToReduce = totalUsed - totalAvailable;
                let newDispense = currentDispense;
                let newAdminister = currentAdminister;
                
                // Priority: Reduce dispense first, then administer
                if (remainingToReduce > 0 && newDispense > 0) {
                    const reduceFromDispense = Math.min(remainingToReduce, newDispense);
                    newDispense -= reduceFromDispense;
                    remainingToReduce -= reduceFromDispense;
                }
                
                if (remainingToReduce > 0 && newAdminister > 0) {
                    const reduceFromAdminister = Math.min(remainingToReduce, newAdminister);
                    newAdminister -= reduceFromAdminister;
                    remainingToReduce -= reduceFromAdminister;
                }
                
                // Apply the adjustments
                if (newDispense !== currentDispense) {
                    cart[index].dispense_quantity = newDispense;
                }
                if (newAdminister !== currentAdminister) {
                    cart[index].administer_quantity = newAdminister;
                }
                
                showNotification(`Quantities automatically adjusted: Dispense: ${newDispense}, Administer: ${newAdminister}`, 'info');
            }
            
            // Special case: If QTY is 0 and no dispense remaining, reset administer to 0
            if (item.quantity === 0 && !item.has_remaining_dispense && currentAdminister > 0) {
                cart[index].administer_quantity = 0;
                showNotification(`Administer quantity reset to 0 since there's no dispense remaining available.`, 'info');
            }
        }

        // Update dispense quantity
        function updateDispenseQuantity(index, newValue) {
            if (cart[index]) {
                // Prevent quantity changes for consultation items
                if (cart[index].id.startsWith('consultation_')) {
                    return;
                }
                
                let newQty = parseFloat(newValue) || 0; // Allow 0 as default
                const item = cart[index];
                
                // Allow dispense quantity to be 0
                if (newQty < 0) {
                    newQty = 0;
                }
                
                // For remaining dispense items, dispense quantity can be up to remaining quantity
                if (item.is_remaining_dispense || item.is_replacement) {
                    const maxDispense = item.remaining_quantity || item.quantity;
                    if (newQty > maxDispense) {
                        alert('Dispense quantity cannot exceed the remaining quantity (' + formatQuantityValue(maxDispense) + ' ' + getQuantityUnit(item) + ') available for dispensing.');
                    return;
                }
                } else if (item.has_remaining_dispense) {
                    // For items with integrated remaining dispense, use the updated max_dispense_quantity
                    const maxDispense = item.max_dispense_quantity || Math.min(item.quantity + item.total_remaining_quantity, item.qoh || 999);
                    
                    // Subtract current administer quantity from max dispense
                    const maxDispenseAfterAdminister = maxDispense - (item.administer_quantity || 0);
                    
                    if (newQty > maxDispenseAfterAdminister) {
                        const maxDispenseFromRemaining = item.quantity + item.total_remaining_quantity;
                        const maxDispenseFromInventory = item.qoh || 999;
                        const currentAdminister = item.administer_quantity || 0;
                        
                        if (maxDispenseFromInventory < maxDispenseFromRemaining) {
                            alert(`Dispense quantity cannot exceed the available inventory (${formatQuantityValue(maxDispenseFromInventory)} ${getQuantityUnit(item)}). You have ${formatQuantityValue(item.total_remaining_quantity)} remaining quantity plus ${formatQuantityValue(item.quantity)} new quantity, but only ${formatQuantityValue(maxDispenseFromInventory)} ${getQuantityUnit(item)} is available in inventory. Current administer: ${formatQuantityValue(currentAdminister)}, Max dispense: ${formatQuantityValue(maxDispenseFromInventory - currentAdminister)}`);
                } else {
                            alert(`Dispense quantity cannot exceed the total available quantity (${formatQuantityValue(maxDispenseAfterAdminister)} ${getQuantityUnit(item)}) which includes new quantity (${formatQuantityValue(item.quantity)}) + remaining quantity (${formatQuantityValue(item.total_remaining_quantity)}) - current administer (${formatQuantityValue(currentAdminister)}).`);
                        }
                        return;
                    }
                } else {
                    // For regular items without remaining dispense, dispense quantity cannot exceed new purchase quantity
                    const maxDispenseAfterAdminister = item.quantity - (item.administer_quantity || 0);
                    
                    if (newQty > maxDispenseAfterAdminister) {
                        const currentAdminister = item.administer_quantity || 0;
                        alert(`Dispense quantity cannot exceed the new purchase quantity (${formatQuantityValue(item.quantity)} ${getQuantityUnit(item)}) minus current administer (${formatQuantityValue(currentAdminister)}) = ${formatQuantityValue(maxDispenseAfterAdminister)} when there are no remaining quantities available for this product.`);
                        return;
                    }
                    
                    // Also check QOH limit
                    const totalUsed = newQty + (item.administer_quantity || 0);
                    if (item.qoh > 0 && totalUsed > item.qoh) {
                        alert(`Total quantity (dispense + administer) cannot exceed Quantity on Hand (QOH): ${formatQuantityValue(item.qoh)} ${getQuantityUnit(item)}. Current administer: ${formatQuantityValue(item.administer_quantity || 0)}, Max dispense: ${formatQuantityValue(item.qoh - (item.administer_quantity || 0))}`);
                        return;
                    }
                }
                
                cart[index].dispense_quantity = newQty;
                
                // Always apply dynamic adjustment logic after changing dispense quantity
                applyDynamicQuantityAdjustment(index);
                
                // Check if we need to integrate remaining dispense when dispense quantity exceeds new purchase quantity
                const currentPid = <?php echo $pid ?: 'null'; ?>;
                if (currentPid && newQty > item.quantity && !item.has_remaining_dispense) {
                    // Create a temporary item object for checking remaining dispense
                    const tempItem = {
                        id: item.id,
                        name: item.name || item.display_name,
                        drug_id: item.drug_id || item.id.split('_')[1],
                        lot_number: item.lot_number || item.lot
                    };
                    
                    checkAndIntegrateRemainingDispense(tempItem).then(integratedItem => {
                        if (integratedItem.has_remaining_dispense) {
                            // Update the cart item with remaining dispense integration
                            cart[index].has_remaining_dispense = true;
                            cart[index].remaining_dispense_items = integratedItem.remaining_dispense_items;
                            cart[index].total_remaining_quantity = integratedItem.total_remaining_quantity;
                            cart[index].display_name = integratedItem.display_name;
                            cart[index].dose_option_mg = integratedItem.dose_option_mg || cart[index].dose_option_mg || '';
                            cart[index].locked_remaining_dose_mg = integratedItem.locked_remaining_dose_mg || cart[index].locked_remaining_dose_mg || '';
                            applyRemainingDispenseDoseLock(cart[index]);
                            
                            // Show notification about remaining dispense integration
                            const itemText = integratedItem.remaining_dispense_items.length === 1 ? 'item' : 'items';
                            const remainingLabel = `${formatQuantityValue(integratedItem.total_remaining_quantity)} remaining quantities`;
                            showNotification(`Found ${integratedItem.remaining_dispense_items.length} ${itemText} with ${remainingLabel} for this product. These will be integrated into your dispense.`, 'info');
                } else {
                            // No remaining dispense found, revert dispense quantity to new purchase quantity
                            cart[index].dispense_quantity = item.quantity;
                            showNotification(`No remaining quantities found for this product. Dispense quantity has been set to match your new purchase quantity (${item.quantity}).`, 'warning');
                        }
                        updateOrderSummary();
                        saveCartToStorage();
                    });
                } else {
                    // Keep item in cart even when both quantity and dispense are 0 (for tracking purposes)
                    // Removed automatic removal logic
                }
                updateOrderSummary();
                saveCartToStorage(); // Save cart to persistent storage
                
                // Recalculate credit amount if credit is being applied
                const creditCheckbox = document.getElementById('apply-credit-checkbox');
                if (creditCheckbox && creditCheckbox.checked) {
                    applyFullCreditBalance();
                }
            }
        }

        // Function to update marketplace dispense quantity for Hoover facility.
        function updateMarketplaceDispenseQuantity(index, newValue) {
            if (cart[index]) {
                // Prevent quantity changes for consultation items
                if (cart[index].id.startsWith('consultation_')) {
                    return;
                }
                
                let newMarketplaceQty = parseFloat(newValue) || 0;
                const item = cart[index];
                
                // Ensure quantity uses the product-specific pack increment.
                newMarketplaceQty = roundMarketplaceDispenseQuantity(newMarketplaceQty, item);
                if (newMarketplaceQty < 0) newMarketplaceQty = 0;
                
                // Get current administer quantity
                const currentAdminister = item.administer_quantity || 0;
                
                // Get current QTY and remaining dispense
                const currentQty = item.quantity || 0;
                const totalRemainingQuantity = item.total_remaining_quantity || 0;
                
                // Calculate total available: QTY + Remaining Dispense
                const totalAvailable = currentQty + totalRemainingQuantity;
                
                // Validate: Marketplace Dispense + Administer <= QTY + Remaining Dispense
                const totalRequested = newMarketplaceQty + currentAdminister;
                
                if (totalRequested > totalAvailable) {
                    // Validation failed - show error and prevent update
                    alert(`Cannot set marketplace dispense to ${newMarketplaceQty}. Marketplace Dispense (${newMarketplaceQty}) + Administer (${currentAdminister}) = ${totalRequested} exceeds available quantity (QTY: ${currentQty} + Remaining Dispense: ${totalRemainingQuantity} = ${totalAvailable}).\n\nPlease increase QTY or reduce marketplace dispense/administer quantities.`);
                    
                    // Reset input to previous value
                    const inputElement = document.querySelector(`input[onchange*="updateMarketplaceDispenseQuantity(${index}"]`);
                    if (inputElement) {
                        inputElement.value = item.marketplace_dispense_quantity || 0;
                    }
                    return;
                }
                
                // Check QOH limits for the new total quantity
                if (item.qoh > 0 && totalRequested > item.qoh) {
                    // If new total exceeds QOH, reduce marketplace dispense to fit within QOH
                    const maxMarketplaceQty = Math.max(0, item.qoh - currentAdminister);
                    newMarketplaceQty = floorMarketplaceDispenseQuantity(maxMarketplaceQty, item);
                    if (newMarketplaceQty < 0) newMarketplaceQty = 0;
                    
                    alert(`Marketplace dispense quantity adjusted to ${newMarketplaceQty} to stay within QOH limit (${item.qoh}).`);
                }
                
                // Update marketplace dispense quantity
                cart[index].marketplace_dispense_quantity = newMarketplaceQty;
                
                // Update the regular dispense_quantity to match marketplace dispense for Hoover facility
                cart[index].dispense_quantity = newMarketplaceQty;
                
                // Update marketplace_dispense flag
                cart[index].marketplace_dispense = newMarketplaceQty > 0;
                
                // DO NOT auto-adjust QTY - let user control it manually
                // QTY should be set by user, and validation ensures Marketplace Dispense + Administer <= QTY + Remaining Dispense
                
                console.log(`Hoover facility: Updated marketplace dispense to ${newMarketplaceQty}, administer: ${currentAdminister}, QTY: ${currentQty}, Remaining: ${totalRemainingQuantity}, Total Available: ${totalAvailable} for item ${index}`);
                
                updateOrderSummary();
                saveCartToStorage();
            }
        }

        function isHooverAdministerOnlyFlow(item) {
            if (!isHooverFacility || !item) {
                return false;
            }

            const currentMarketplaceDispense = parseFloat(item.marketplace_dispense_quantity || item.dispense_quantity || 0);
            const currentDispenseQuantity = parseFloat(item.dispense_quantity || 0);
            const currentQty = parseFloat(item.quantity || 0);
            const totalRemainingQuantity = parseFloat(item.total_remaining_quantity || 0);
            const hasRemainingDispense = !!item.has_remaining_dispense;

            return hasRemainingDispense
                && totalRemainingQuantity > 0
                && currentMarketplaceDispense === 0
                && currentDispenseQuantity === 0
                && currentQty === 0
                && (item.is_remaining_dispense || Array.isArray(item.remaining_dispense_items));
        }

        // Get maximum administer quantity for an item
        function getMaxAdministerQuantity(item, index) {
            // For Hoover facility, calculate max based on: Marketplace Dispense + Administer <= QTY + Remaining Dispense
            if (isHooverFacility) {
                const currentMarketplaceDispense = item.marketplace_dispense_quantity || 0;
                const currentQty = item.quantity || 0;
                const totalRemainingQuantity = item.total_remaining_quantity || 0;
                const totalAvailable = currentQty + totalRemainingQuantity;

                // Also check QOH limit
                const maxFromQOH = getAdministerCapacityFromQoh(item);

                // Daily limit is 2
                const maxFromDailyLimit = 2;

                if (isHooverAdministerOnlyFlow(item)) {
                    return Math.max(0, Math.min(totalRemainingQuantity, maxFromQOH, maxFromDailyLimit));
                }

                // Max administer = Total Available - Marketplace Dispense
                const maxFromAvailable = Math.max(0, totalAvailable - currentMarketplaceDispense);

                // Return the minimum of all limits
                return Math.max(0, Math.min(maxFromAvailable, maxFromQOH, maxFromDailyLimit));
            }
            
            // Original logic for non-Hoover facilities
            const currentItem = cart[index];
            if (!currentItem) return 0;
            
            // Calculate total available quantity (new purchase + remaining dispense)
            const totalAvailable = currentItem.quantity + (currentItem.total_remaining_quantity || 0);
            
            // Subtract current dispense quantity to get remaining for administer
            const remainingForAdminister = totalAvailable - (currentItem.dispense_quantity || 0);
            
            // Also check QOH limit
            const maxFromQOH = getAdministerCapacityFromQoh(currentItem);
            
            // For medications, check daily administration limit (max 2 doses per day)
            let maxFromDailyLimit = 999;
            if (currentItem.drug_id && currentItem.lot) {
                // For now, use a conservative approach - the real-time check will handle the actual limit
                // This prevents the UI from showing a max that might be invalid
                maxFromDailyLimit = 2;
            }
            
            return Math.max(0, Math.min(remainingForAdminister, maxFromQOH, maxFromDailyLimit));
        }

        // Update administer quantity
        function updateAdministerQuantity(index, newValue) {
            if (cart[index]) {
                // Prevent quantity changes for consultation items
                if (cart[index].id.startsWith('consultation_')) {
                    return;
                }
                
                let newQty = parseFloat(newValue) || 0; // Allow 0 as default
                const item = cart[index];
                
                // Allow administer quantity to be 0
                if (newQty < 0) {
                    newQty = 0;
                }
                
                console.log('updateAdministerQuantity called:', { index, newQty, drug_id: item.drug_id, lot: item.lot });
                
                // Enforce hard limit of 2 doses per day for ALL administer quantities
                if (newQty > 2) {
                    alert('Daily administration limit exceeded! Maximum 2 doses allowed per day.');
                    showNotification('Daily administration limit exceeded! Maximum 2 doses allowed per day.', 'error');
                    // Reset the input to previous value
                    const inputElement = document.querySelector(`input[onchange*="updateAdministerQuantity(${index}"]`);
                    if (inputElement) {
                        inputElement.value = cart[index].administer_quantity || 0;
                    }
                    return;
                }
                
                // Check daily administration limit for medications (max 2 doses per day)
                if (newQty > 0 && item.drug_id && item.lot) {
                    console.log('Calling checkDailyAdministerLimit with:', { drug_id: item.drug_id, lot: item.lot, newQty });
                    checkDailyAdministerLimit(item.drug_id, item.lot, newQty, index, newQty);
                    return; // The limit check will handle the update if allowed
                } else {
                    console.log('Skipping daily limit check - proceeding with normal validation');
                    updateAdministerQuantityInternal(index, newQty);
                }
                
                // If no drug_id or lot (non-medication items), proceed with normal validation
                updateAdministerQuantityInternal(index, newQty);
            }
        }
        
        // Validate administer input in real-time before update
        function validateAdministerInput(index, inputElement) {
            if (!cart[index] || !isHooverFacility) {
                return true; // Skip validation for non-Hoover or invalid items
            }

            const item = cart[index];
            let newValue = parseFloat(inputElement.value) || 0;
            if (isHooverAdministerOnlyFlow(item)) {
                const maxAllowed = getMaxAdministerQuantity(item, index);
                if (newValue > maxAllowed) {
                    inputElement.value = Math.min(maxAllowed, item.administer_quantity || 0);
                    showNotification(`Maximum administer allowed: ${maxAllowed}`, 'warning');
                    return false;
                }
                return true;
            }
            const currentMarketplaceDispense = item.marketplace_dispense_quantity || 0;
            const currentQty = item.quantity || 0;
            const totalRemainingQuantity = item.total_remaining_quantity || 0;
            const totalAvailable = currentQty + totalRemainingQuantity;
            const totalRequested = currentMarketplaceDispense + newValue;
            const currentAdminister = item.administer_quantity || 0;
            
            // Validate in real-time - block if exceeding available
            if (totalRequested > totalAvailable) {
                const maxAllowed = Math.max(0, totalAvailable - currentMarketplaceDispense);
                // Prevent invalid value by capping it to max allowed or current value
                newValue = Math.min(newValue, maxAllowed, currentAdminister);
                inputElement.value = newValue;
                // Show warning
                showNotification(`Maximum administer allowed: ${maxAllowed} (Marketplace: ${currentMarketplaceDispense} + Administer ≤ QTY: ${currentQty} + Remaining: ${totalRemainingQuantity})`, 'warning');
                return false;
            }
            
            // Additional validation: Check if excess (beyond remaining) exceeds new purchase
            const maxFromRemaining = totalRemainingQuantity;
            if (totalRequested > maxFromRemaining) {
                const excessNeeded = totalRequested - maxFromRemaining;
                if (excessNeeded > currentQty) {
                    const maxAllowed = Math.max(0, totalAvailable - currentMarketplaceDispense);
                    newValue = Math.min(newValue, maxAllowed, currentAdminister);
                    inputElement.value = newValue;
                    showNotification(`Maximum administer allowed: ${maxAllowed}. Cannot use ${excessNeeded} from new purchase when only ${currentQty} is available in QTY.`, 'warning');
                    return false;
                }
            }
            
            // Also check if value exceeds max attribute
            const maxAttr = parseFloat(inputElement.getAttribute('max')) || 999;
            if (newValue > maxAttr) {
                newValue = Math.min(newValue, maxAttr, currentAdminister);
                inputElement.value = newValue;
                return false;
            }
            
            return true;
        }
        
        // Internal function to update administer quantity after limit checks
        function updateAdministerQuantityInternal(index, newQty) {
            const item = cart[index];
            
            if (!item) {
                console.error('updateAdministerQuantityInternal: Item not found at index', index);
                return;
            }
            
             // For Hoover facility with marketplace dispense, use different validation logic
             if (isHooverFacility) {
                  // For Hoover facility, validate: Marketplace Dispense + Administer <= QTY + Remaining Dispense
                  // Ensure we're using the latest values from the cart item
                  const currentMarketplaceDispense = parseFloat(cart[index].marketplace_dispense_quantity || cart[index].dispense_quantity || 0);
                  const totalRemainingQuantity = parseFloat(cart[index].total_remaining_quantity || 0);
                  const currentQty = parseFloat(cart[index].quantity || 0);
                  const isAdministerOnlyFlow = isHooverAdministerOnlyFlow(cart[index]);
                  const totalAvailable = currentQty + totalRemainingQuantity;
                  const totalRequested = currentMarketplaceDispense + newQty;
                 
                  console.log('Hoover Administer Validation:', {
                      index: index,
                      currentMarketplaceDispense: currentMarketplaceDispense,
                      newQty: newQty,
                      currentQty: currentQty,
                      totalRemainingQuantity: totalRemainingQuantity,
                      totalAvailable: totalAvailable,
                      totalRequested: totalRequested,
                      validation: totalRequested <= totalAvailable,
                      shouldBlock: totalRequested > totalAvailable,
                      isAdministerOnlyFlow: isAdministerOnlyFlow
                  });

                  if (isAdministerOnlyFlow) {
                      const maxAllowed = getMaxAdministerQuantity(item, index);
                      if (newQty > maxAllowed) {
                          alert(`Administer quantity cannot exceed remaining available quantity. Max administer: ${formatQuantityValue(maxAllowed)}`);

                          const inputElement = document.querySelector(`input[onchange*="updateAdministerQuantity(${index}"]`) || document.getElementById(`administer-input-${index}`);
                          if (inputElement) {
                              inputElement.value = item.administer_quantity || 0;
                          }
                          return;
                      }
                  } else {
                  
                  // Validate total requested doesn't exceed available
                  // Block if totalRequested > totalAvailable (strictly greater than)
                  if (totalRequested > totalAvailable) {
                      console.log('BLOCKING: Total Requested exceeds Total Available', {
                          totalRequested,
                          totalAvailable,
                          maxAllowed: totalAvailable - currentMarketplaceDispense
                      });
                     const maxAllowedAdminister = Math.max(0, totalAvailable - currentMarketplaceDispense);
                     alert(`Administer quantity cannot be set to ${newQty}. Marketplace Dispense (${currentMarketplaceDispense}) + Administer (${newQty}) = ${totalRequested} exceeds available quantity (QTY: ${currentQty} + Remaining Dispense: ${totalRemainingQuantity} = ${totalAvailable}).\n\nMaximum allowed administer: ${maxAllowedAdminister}\n\nPlease increase QTY or reduce marketplace dispense/administer quantities.`);
                     
                     // Reset input to previous value
                     const inputElement = document.querySelector(`input[onchange*="updateAdministerQuantity(${index}"]`) || document.getElementById(`administer-input-${index}`);
                     if (inputElement) {
                         inputElement.value = item.administer_quantity || 0;
                         // Force update the display
                         inputElement.dispatchEvent(new Event('input', { bubbles: true }));
                     }
                     return;
                 }
                 
                 // Additional validation: Ensure we're not trying to use more than what's actually available
                 // Calculate how much can come from remaining dispense vs new purchase
                 const maxFromRemaining = totalRemainingQuantity;
                 const maxFromNewPurchase = currentQty;
                 
                 console.log('Hoover Administer Additional Validation:', {
                     totalRequested,
                     maxFromRemaining,
                     maxFromNewPurchase,
                     totalAvailable,
                     condition1: totalRequested > maxFromRemaining,
                     condition2: totalRequested >= totalAvailable
                 });
                 
                 // If marketplace dispense + administer exceeds what can come from remaining, 
                 // ensure the excess doesn't exceed new purchase quantity
                 // Also prevent using exactly all available when QTY is small
                 if (totalRequested > maxFromRemaining) {
                     const excessNeeded = totalRequested - maxFromRemaining;
                     
                     console.log('Excess calculation:', {
                         excessNeeded,
                         maxFromNewPurchase,
                         excessCheck: excessNeeded >= maxFromNewPurchase,
                         totalRequestedCheck: totalRequested >= totalAvailable,
                         shouldBlock: excessNeeded >= maxFromNewPurchase && totalRequested >= totalAvailable
                     });
                     
                     // If excess needed exceeds new purchase QTY, block it
                     if (excessNeeded > maxFromNewPurchase) {
                         const maxAllowedAdminister = Math.max(0, totalAvailable - currentMarketplaceDispense);
                         console.log('BLOCKING: Excess exceeds new purchase QTY');
                         alert(`Cannot set administer to ${newQty}. The combination of Marketplace Dispense (${currentMarketplaceDispense}) + Administer (${newQty}) = ${totalRequested} would require ${excessNeeded} from new purchase, but only ${maxFromNewPurchase} is available in QTY.\n\nMaximum allowed administer: ${maxAllowedAdminister}\n\nPlease increase QTY or reduce marketplace dispense/administer quantities.`);
                         
                         // Reset input to previous value
                         const inputElement = document.querySelector(`input[onchange*="updateAdministerQuantity(${index}"]`) || document.getElementById(`administer-input-${index}`);
                         if (inputElement) {
                             inputElement.value = item.administer_quantity || 0;
                             inputElement.dispatchEvent(new Event('input', { bubbles: true }));
                         }
                         return;
                     }
                     
                     // Also check if excess exceeds new purchase quantity
                     if (excessNeeded > maxFromNewPurchase) {
                         const maxAllowedAdminister = Math.max(0, totalAvailable - currentMarketplaceDispense);
                         console.log('BLOCKING: Excess exceeds new purchase QTY');
                         alert(`Cannot set administer to ${newQty}. The combination of Marketplace Dispense (${currentMarketplaceDispense}) + Administer (${newQty}) = ${totalRequested} would require ${excessNeeded} from new purchase, but only ${maxFromNewPurchase} is available in QTY.\n\nMaximum allowed administer: ${maxAllowedAdminister}\n\nPlease increase QTY or reduce marketplace dispense/administer quantities.`);
                         
                         // Reset input to previous value
                         const inputElement = document.querySelector(`input[onchange*="updateAdministerQuantity(${index}"]`) || document.getElementById(`administer-input-${index}`);
                         if (inputElement) {
                             inputElement.value = item.administer_quantity || 0;
                             inputElement.dispatchEvent(new Event('input', { bubbles: true }));
                         }
                         return;
                     }
                 }
                 }
                 
                 // Also check QOH limits
                 const maxAdministerFromQoh = getAdministerCapacityFromQoh(item);
                 if (item.qoh > 0 && newQty > maxAdministerFromQoh) {
                     alert(`Administer quantity cannot exceed available QOH: ${formatQuantityValue(item.qoh)} ${getQohUnit(item)}${hasMedicationDoseSelector(item) ? ` at ${item.dose_option_mg || getDefaultMedicationDose(item)} mg per administer` : ''}. Max administer: ${formatQuantityValue(maxAdministerFromQoh)}`);
                     
                     // Reset input to previous value
                     const inputElement = document.querySelector(`input[onchange*="updateAdministerQuantity(${index}"]`);
                     if (inputElement) {
                         inputElement.value = item.administer_quantity || 0;
                     }
                     return;
                 }
             } else {
                 // Original logic for non-Hoover facilities
                 // Calculate total available quantity (new purchase + remaining dispense)
                 const totalAvailable = item.quantity + (item.total_remaining_quantity || 0);
                 
                 // Calculate total used (dispense + administer)
                 const totalUsed = (item.dispense_quantity || 0) + newQty;
                 
                 // Check if total used exceeds total available
                 if (totalUsed > totalAvailable) {
                      alert(`Total quantity (dispense + administer) cannot exceed the total available quantity (${formatQuantityValue(totalAvailable)} ${getQuantityUnit(item)}). Current dispense: ${formatQuantityValue(item.dispense_quantity || 0)}, Max administer: ${formatQuantityValue(totalAvailable - (item.dispense_quantity || 0))}`);
                      return;
                  }
                 
                 // Also check QOH limit
                 const maxAdministerFromQoh = getAdministerCapacityFromQoh(item);
                 if (item.qoh > 0 && newQty > maxAdministerFromQoh) {
                      alert(`Administer quantity cannot exceed available QOH: ${formatQuantityValue(item.qoh)} ${getQohUnit(item)}${hasMedicationDoseSelector(item) ? ` at ${item.dose_option_mg || getDefaultMedicationDose(item)} mg per administer` : ''}. Max administer: ${formatQuantityValue(maxAdministerFromQoh)}`);
                      return;
                  }
             }
            
            cart[index].administer_quantity = newQty;
            
            // For Hoover facility, DO NOT auto-adjust QTY - let user control it manually
            // Validation ensures Marketplace Dispense + Administer <= QTY + Remaining Dispense
            if (isHooverFacility) {
                const currentMarketplaceQty = cart[index].marketplace_dispense_quantity || 0;
                const currentQty = cart[index].quantity || 0;
                const totalRemainingQuantity = cart[index].total_remaining_quantity || 0;
                const totalAvailable = currentQty + totalRemainingQuantity;
                const totalRequested = currentMarketplaceQty + newQty;
                
                console.log(`Hoover facility: Administer changed to ${newQty}, marketplace dispense: ${currentMarketplaceQty}, QTY: ${currentQty}, Remaining: ${totalRemainingQuantity}, Total Available: ${totalAvailable}, Total Requested: ${totalRequested}`);
                
                // Validation already done above, no auto-adjustment needed
            }
            
            // Always apply dynamic adjustment logic after changing administer quantity
            applyDynamicQuantityAdjustment(index);
            
            updateOrderSummary();
            saveCartToStorage(); // Save cart to persistent storage
            
            // Recalculate credit amount if credit is being applied
            const creditCheckbox = document.getElementById('apply-credit-checkbox');
            if (creditCheckbox && creditCheckbox.checked) {
                applyFullCreditBalance();
            }
        }
        
        // Check daily administration limit
        function checkDailyAdministerLimit(drug_id, lot_number, requested_quantity, index, newQty) {
            const pid = <?php echo $pid ?: 'null'; ?>;
            
            if (!pid) {
                alert('Patient ID is required to check administration limits.');
                return;
            }
            
            // Hard limit check - never allow more than 2 doses per day
            if (newQty > 2) {
                alert('Daily administration limit exceeded! Maximum 2 doses allowed per day.');
                showNotification('Daily administration limit exceeded! Maximum 2 doses allowed per day.', 'error');
                // Reset the input to previous value
                const inputElement = document.querySelector(`input[onchange*="updateAdministerQuantity(${index}"]`);
                if (inputElement) {
                    inputElement.value = cart[index].administer_quantity || 0;
                }
                return;
            }
            
            fetch('pos_payment_processor.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'check_administer_limit',
                    pid: pid,
                    drug_id: drug_id,
                    lot_number: lot_number,
                    requested_quantity: requested_quantity,
                    csrf_token_form: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                console.log('Daily limit check response:', data);
                if (data.success) {
                    if (data.can_administer) {
                        // Limit check passed, proceed with update
                        updateAdministerQuantityInternal(index, newQty);
                        // Show success notification with current day's total
                        if (newQty > 0) {
                            const totalForDay = data.current_total + newQty;
                            showNotification(`Administer quantity updated successfully! (${totalForDay}/2 doses today)`, 'success');
                        }
                    } else {
                        // Limit exceeded, show error with current day's total
                        const errorMsg = `Daily limit exceeded! Already administered ${data.current_total} doses today. Maximum ${data.remaining_allowed} more allowed.`;
                        alert(errorMsg);
                        showNotification(errorMsg, 'error');
                        // Reset the input to previous value
                        const inputElement = document.querySelector(`input[onchange*="updateAdministerQuantity(${index}"]`);
                        if (inputElement) {
                            inputElement.value = cart[index].administer_quantity || 0;
                        }
                    }
                } else {
                    alert('Error checking administration limit: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error checking administration limit:', error);
                alert('Error checking administration limit. Please try again.');
            });
        }
        // Get daily administration status for display
        function getDailyAdministerStatus(drug_id, lot_number, callback) {
            const pid = <?php echo $pid ?: 'null'; ?>;
            
            if (!pid || !drug_id || !lot_number) {
                callback({ current_total: 0, remaining_allowed: 2 });
                return;
            }
            
            fetch('pos_payment_processor.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'check_administer_limit',
                    pid: pid,
                    drug_id: drug_id,
                    lot_number: lot_number,
                    requested_quantity: 0, // Just checking current status
                    csrf_token_form: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    callback({
                        current_total: data.current_total || 0,
                        remaining_allowed: data.remaining_allowed || 2
                    });
                } else {
                    callback({ current_total: 0, remaining_allowed: 2 });
                }
            })
            .catch(error => {
                console.error('Error getting daily administration status:', error);
                callback({ current_total: 0, remaining_allowed: 2 });
            });
        }
        
        // Check all administer limits before processing (callback version)
        function checkAllAdministerLimits(callback) {
            const pid = <?php echo $pid ?: 'null'; ?>;
            
            if (!pid) {
                alert('Patient ID is required to check administration limits.');
                return;
            }
            
            // Get all items with administer quantities
            const administerItems = cart.filter(item => 
                (item.administer_quantity || 0) > 0 && item.drug_id && item.lot
            );
            
            if (administerItems.length === 0) {
                // No administer items, proceed
                callback();
                return;
            }
            
            // Check limits for each item
            let checkedItems = 0;
            let hasErrors = false;
            
            administerItems.forEach(item => {
                fetch('pos_payment_processor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'check_administer_limit',
                        pid: pid,
                        drug_id: item.drug_id,
                        lot_number: item.lot,
                        requested_quantity: item.administer_quantity,
                        csrf_token_form: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    checkedItems++;
                    
                    if (data.success && !data.can_administer) {
                        hasErrors = true;
                        alert(`Daily administration limit exceeded for ${item.display_name}!\n\n${data.error}`);
                    }
                    
                    // If all items checked and no errors, proceed
                    if (checkedItems === administerItems.length && !hasErrors) {
                        callback();
                    } else if (checkedItems === administerItems.length && hasErrors) {
                        // Don't proceed if there were errors
                        return;
                    }
                })
                .catch(error => {
                    console.error('Error checking administration limit:', error);
                    alert('Error checking administration limit: ' + error.message);
                    return;
                });
            });
        }
        
        // Check all administer limits before processing (async version)
        async function checkAllAdministerLimitsAsync() {
            const pid = <?php echo $pid ?: 'null'; ?>;
            
            if (!pid) {
                alert('Patient ID is required to check administration limits.');
                return;
            }
            
            // Get all items with administer quantities
            const administerItems = cart.filter(item => 
                (item.administer_quantity || 0) > 0 && item.drug_id && item.lot
            );
            
            if (administerItems.length === 0) {
                // No administer items, proceed
                return;
            }
            
            // Check limits for each item
            for (const item of administerItems) {
                try {
                    const response = await fetch('pos_payment_processor.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            action: 'check_administer_limit',
                            pid: pid,
                            drug_id: item.drug_id,
                            lot_number: item.lot,
                            requested_quantity: item.administer_quantity,
                            csrf_token_form: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                        })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success && !data.can_administer) {
                        alert(`Daily administration limit exceeded for ${item.display_name}!\n\n${data.error}`);
                        throw new Error('Daily administration limit exceeded');
                    }
                } catch (error) {
                    if (error.message === 'Daily administration limit exceeded') {
                        throw error; // Re-throw limit exceeded errors
                    }
                    console.error('Error checking administration limit:', error);
                    alert('Error checking administration limit: ' + error.message);
                    throw error;
                }
            }
        }

        // Remove item from cart
        function removeItem(index) {
            const removedItem = cart[index];
            cart.splice(index, 1);
            
            updateOrderSummary();
            saveCartToStorage(); // Save cart to persistent storage
            
            // Update consultation checkbox state
            updateConsultationCheckboxState();
            
            // Recalculate credit amount if credit is being applied
            const creditCheckbox = document.getElementById('apply-credit-checkbox');
            if (creditCheckbox && creditCheckbox.checked) {
                applyFullCreditBalance();
            }
        }

        // Add item manually (for Enter key)
        async function addItem(searchTerm = '') {
            searchTerm = (searchTerm || '').trim();

            if (currentSearchResults.length === 0 && searchTerm.length >= 2) {
                const results = await performSearch(searchTerm);
                if (results.length > 0) {
                    selectItem(results[0].id);
                }
                return;
            }

            // If there are search results, select the first one
            if (currentSearchResults.length > 0) {
                selectItem(currentSearchResults[0].id);
            } else {
                hideSearchResults();
            }
        }

        // Toggle consultation service in cart
        function toggleConsultation() {
            const consultationCheckbox = document.getElementById('consultation-checkbox');
            const isChecked = consultationCheckbox.checked;
            
            // Remove any existing consultation items from cart
            cart = cart.filter(item => !item.id.startsWith('consultation_'));
            
            if (isChecked) {
                // Get consultation price from configuration (default to 39.95 if not set)
                const consultationPrice = <?php 
                    $res = sqlStatement("SELECT gl_value FROM globals WHERE gl_name = 'pos_consultation_price'");
                $row = sqlFetchArray($res);
                echo $row ? floatval($row['gl_value']) : 39.95;
            ?>;
            
                const consultationItem = {
                    id: 'consultation_' + Date.now(),
                    display_name: 'Consultation Service',
                    original_price: consultationPrice,
                    price: consultationPrice,
                quantity: 1,
                    dispense_quantity: 1, // For consultation, dispense quantity equals quantity
                lot: null,
                qoh: 0,
                    icon: 'consultation',
                has_discount: false,
                discount_info: null
            };
            
                cart.push(consultationItem);
            }
            
            updateOrderSummary();
            saveCartToStorage();
            
            // Recalculate credit amount if credit is being applied
            const creditCheckbox = document.getElementById('apply-credit-checkbox');
            if (creditCheckbox && creditCheckbox.checked) {
                applyFullCreditBalance();
            }

            if (typeof window.syncVisitOptionHighlights === 'function') {
                window.syncVisitOptionHighlights();
            }
        }

        // Toggle blood work in cart
        function toggleBloodWork() {
            const bloodworkCheckbox = document.getElementById('bloodwork-checkbox');
            if (!bloodworkCheckbox) return;

            const isChecked = bloodworkCheckbox.checked;

            // Remove any existing blood work items from cart
            cart = cart.filter(item => !item.id.startsWith('bloodwork_'));

            if (isChecked) {
                // Get blood work price from configuration (default to 69.00 if not set)
                const bloodworkPrice = <?php 
                    $res = sqlStatement("SELECT gl_value FROM globals WHERE gl_name = 'pos_bloodwork_price'");
                    $row = sqlFetchArray($res);
                    echo $row ? floatval($row['gl_value']) : 69.00;
                ?>;

                const bloodworkItem = {
                    id: 'bloodwork_' + Date.now(),
                    display_name: 'Blood Work',
                    original_price: bloodworkPrice,
                    price: bloodworkPrice,
                    quantity: 1,
                    dispense_quantity: 1,
                    lot: null,
                    qoh: 0,
                    icon: 'bloodwork',
                    has_discount: false,
                    discount_info: null
                };

                cart.push(bloodworkItem);
            }

            updateOrderSummary();
            saveCartToStorage();

            // If credit is applied, recalc
            const creditCheckbox = document.getElementById('apply-credit-checkbox');
            if (creditCheckbox && creditCheckbox.checked) {
                applyFullCreditBalance();
            }

            if (typeof window.syncVisitOptionHighlights === 'function') {
                window.syncVisitOptionHighlights();
            }
        }

        // Update consultation checkbox state based on cart contents
        function updateConsultationCheckboxState() {
            const consultationCheckbox = document.getElementById('consultation-checkbox');
            if (consultationCheckbox) {
                const hasConsultation = cart.some(item => item.id.startsWith('consultation_'));
                consultationCheckbox.checked = hasConsultation;
                if (typeof window.syncVisitOptionHighlights === 'function') {
                    window.syncVisitOptionHighlights();
                }
            }
        }

        // Toggle refund section
        function toggleRefundSection() {
            const refundSection = document.getElementById('refund-section');
            const mainPosSection = document.getElementById('main-pos-section');
            const dispenseSection = document.getElementById('dispense-tracking-section');
            const transferSection = document.getElementById('transfer-section');
            const weightSection = document.getElementById('weight-tracker-section');
            
            if (refundSection && mainPosSection) {
                // Hide other sections if they're open
                if (dispenseSection) {
                    dispenseSection.style.display = 'none';
                }
                if (transferSection) {
                    transferSection.style.display = 'none';
                }
                if (weightSection) {
                    weightSection.style.display = 'none';
                }
                
                if (refundSection.style.display === 'block') {
                    // Currently showing refund section, hide it and show POS
                    refundSection.style.display = 'none';
                    mainPosSection.style.display = 'block';
                } else {
                    // Currently showing POS, hide it and show refund section
                    mainPosSection.style.display = 'none';
                    refundSection.style.display = 'block';
                    
                    // Load refundable items
                    loadRefundableItems();
                }
            }
        }

        // Hide refund section
        function hideRefundSection() {
            const refundSection = document.getElementById('refund-section');
            const mainPosSection = document.getElementById('main-pos-section');
            
            if (refundSection && mainPosSection) {
                // Hide refund section
                refundSection.style.display = 'none';
                // Show main POS section
                mainPosSection.style.display = 'block';
            }
        }

        // Load refundable items for the new toggle system
        function loadRefundableItems() {
            // Get all refundable items for this patient
            fetch('pos_refund_system.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'get_refundable_items',
                    pid: currentPatientId,
                    csrf_token: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.items.length > 0) {
                    // Populate items list
                    populateRefundableItemsList(data.items);
                } else {
                    // Show no items message
                    const itemsList = document.getElementById('refundableItemsList');
                    itemsList.innerHTML = '<div style="padding: 40px; text-align: center; color: #6c757d;"><i class="fas fa-info-circle" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>No items with remaining dispense found for this patient</div>';
                }
                
                // Also update the credit balance in the refund section
                updateRefundSectionCreditBalance();
            })
            .catch(error => {
                console.error('Error loading refundable items:', error);
                const itemsList = document.getElementById('refundableItemsList');
                itemsList.innerHTML = '<div style="padding: 40px; text-align: center; color: #dc3545;"><i class="fas fa-exclamation-triangle" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>Error loading refundable items</div>';
            });
        }

        // Select refund type (dispense or other)
        function selectRefundType(type) {
            currentRefundType = type;
            
            // Reset all sections
            document.getElementById('dispenseRefundSection').style.display = 'none';
            document.getElementById('manualRefundSection').style.display = 'none';
            document.getElementById('refundDetails').style.display = 'none';
            
            // Reset selection styling
            document.getElementById('refundTypeDispense').style.borderColor = '#e9ecef';
            document.getElementById('refundTypeDispense').style.backgroundColor = '#f8f9fa';
            document.getElementById('refundTypeOther').style.borderColor = '#e9ecef';
            document.getElementById('refundTypeOther').style.backgroundColor = '#f8f9fa';
            
            // Highlight selected option
            if (type === 'dispense') {
                document.getElementById('refundTypeDispense').style.borderColor = '#007bff';
                document.getElementById('refundTypeDispense').style.backgroundColor = '#e3f2fd';
                document.getElementById('dispenseRefundSection').style.display = 'block';
                loadRefundableItems();
            } else if (type === 'other') {
                document.getElementById('refundTypeOther').style.borderColor = '#007bff';
                document.getElementById('refundTypeOther').style.backgroundColor = '#e3f2fd';
                document.getElementById('manualRefundSection').style.display = 'block';
            }
        }

        // Update manual refund amount display
        function updateManualRefundDisplay() {
            const amount = parseFloat(document.getElementById('manualRefundAmount').value) || 0;
            document.getElementById('manualRefundAmountDisplay').textContent = '$' + amount.toFixed(2);
            
            // Show refund configuration if amount is valid
            if (amount > 0) {
                document.getElementById('refundDetails').style.display = 'block';

                document.getElementById('processRefundBtn').style.display = 'inline-block';
                
                // Hide quantity section for manual refunds
                document.getElementById('quantitySection').style.display = 'none';
                document.getElementById('refundConfigurationGrid').style.gridTemplateColumns = '1fr';
            } else {
                document.getElementById('refundDetails').style.display = 'none';
            }
        }

        // Update credit balance in refund section
        function updateRefundSectionCreditBalance() {
            const creditBalanceElement = document.getElementById('refundSectionCreditBalance');
            const mainCreditBalanceElement = document.getElementById('patientCreditBalance');
            
            if (creditBalanceElement && mainCreditBalanceElement) {
                creditBalanceElement.textContent = mainCreditBalanceElement.textContent;
            }
        }



        // Select refund product (new function for table interface)
        function selectRefundProduct(drugId, productName, totalRemaining, refundableAmount) {
            console.log('selectRefundProduct - drugId:', drugId, 'productName:', productName, 'totalRemaining:', totalRemaining, 'refundableAmount:', refundableAmount);
            
            // Set current refund data
            currentRefundData = {
                itemId: `refund-product-${drugId}`,
                drugId: drugId,
                drugName: productName,
                maxQuantity: totalRemaining,
                originalAmount: refundableAmount,
                totalAmount: refundableAmount,
                itemType: 'remaining_dispense'
            };
            
            // Populate refund details
            populateRefundModal({
                drug_name: productName,
                remaining_quantity: totalRemaining,
                original_amount: refundableAmount,
                total_amount: refundableAmount,
                item_type: 'remaining_dispense'
            });
            
            // Show refund details section
            document.getElementById('refundDetails').style.display = 'block';
            
            // Show quantity section for dispense refunds
            document.getElementById('quantitySection').style.display = 'block';
            document.getElementById('refundConfigurationGrid').style.gridTemplateColumns = '1fr 1fr';
            
            // Show process refund button
            document.getElementById('processRefundBtn').style.display = 'inline-block';
            
            // Update max quantity display
            document.getElementById('maxRefundQuantity').textContent = totalRemaining;
            document.getElementById('refundQuantity').max = totalRemaining;
            document.getElementById('refundQuantity').value = 1;
            
            // Calculate initial refund amount
            calculateRefundAmount();
        }

        // Filter refund items
        function filterRefundItems(searchTerm) {
            const rows = document.querySelectorAll('.refund-product-row');
            const searchLower = searchTerm.toLowerCase();
            
            rows.forEach(row => {
                const productName = row.getAttribute('data-product');
                if (productName.includes(searchLower)) {
                    row.style.display = 'table-row';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Clear refund search
        function clearRefundSearch() {
            document.getElementById('refund-search-input').value = '';
            const rows = document.querySelectorAll('.refund-product-row');
            rows.forEach(row => {
                row.style.display = 'table-row';
            });
        }

        // Multi-product selection functions
        function toggleProductSelection(drugId, productName, maxQuantity, refundableAmount) {
            const existingIndex = selectedRefundItems.findIndex(item => item.drug_id === drugId);
            const row = document.querySelector(`tr[data-drug-id="${drugId}"]`);
            const quantityInput = row.querySelector('input[type="number"]');
            
            if (existingIndex >= 0) {
                // Remove from selection
                selectedRefundItems.splice(existingIndex, 1);
                row.style.backgroundColor = '';
                quantityInput.disabled = true;
                quantityInput.value = 0;
            } else {
                // Add to selection
                selectedRefundItems.push({
                    drug_id: drugId,
                    product_name: productName,
                    max_quantity: maxQuantity,
                    refundable_amount: 0, // Start with 0 refund amount
                    selected_quantity: 0 // Start with 0 quantity
                });
                row.style.backgroundColor = '#e3f2fd';
                quantityInput.disabled = false;
                quantityInput.value = 0;
            }
            
            updateSelectedItemsSummary();
            updateNextButtonVisibility();
        }
        function updateItemQuantityInTable(drugId, newQuantity, maxQuantity, originalRefundableAmount, originalPerUnitPrice) {
            const item = selectedRefundItems.find(item => item.drug_id === drugId);
            if (!item) return;
            
            if (newQuantity > maxQuantity) {
                newQuantity = maxQuantity;
            }
            
            if (newQuantity < 0) {
                newQuantity = 0;
            }
            
            item.selected_quantity = newQuantity;
            // Calculate the refund amount based on the selected quantity
            // Use the original per-unit price for accurate refund calculation
            item.refundable_amount = originalPerUnitPrice * newQuantity;
            
            console.log(`updateItemQuantityInTable - drugId: ${drugId}, selectedQty: ${newQuantity}, maxQty: ${maxQuantity}, originalPerUnitPrice: ${originalPerUnitPrice}, refundAmount: ${item.refundable_amount}`);
            
            // Update the refund amount display in the table
            const refundAmountElement = document.getElementById(`refund-amount-${drugId}`);
            if (refundAmountElement) {
                refundAmountElement.textContent = '$' + item.refundable_amount.toFixed(2);
            }
            
            updateSelectedItemsSummary();
        }

        function toggleSelectAll() {
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            const productCheckboxes = document.querySelectorAll('.product-checkbox');
            const isSelectAll = selectAllCheckbox.checked;
            
            productCheckboxes.forEach(checkbox => {
                checkbox.checked = isSelectAll;
                const drugId = parseInt(checkbox.getAttribute('data-drug-id'));
                const row = checkbox.closest('tr');
                const productName = row.querySelector('td:nth-child(2) div div').textContent;
                const maxQuantity = parseInt(row.querySelector('td:nth-child(6)').textContent);
                const refundableAmount = parseFloat(row.querySelector('td:nth-child(7)').textContent.replace('$', ''));
                
                if (isSelectAll) {
                    // Add to selection if not already selected
                    if (!selectedRefundItems.some(item => item.drug_id === drugId)) {
                        selectedRefundItems.push({
                            drug_id: drugId,
                            product_name: productName,
                            max_quantity: maxQuantity,
                            refundable_amount: 0,
                            selected_quantity: 0
                        });
                        row.style.backgroundColor = '#e3f2fd';
                    }
                } else {
                    // Remove from selection
                    selectedRefundItems = selectedRefundItems.filter(item => item.drug_id !== drugId);
                    row.style.backgroundColor = '';
                }
            });
            
            updateSelectedItemsSummary();
        }

        function selectAllRefundItems() {
            document.getElementById('selectAllCheckbox').checked = true;
            toggleSelectAll();
        }

        function updateSelectedItemsSummary() {
            // This function is now simplified since we removed the summary display
            // We only need to update the next button visibility
            updateNextButtonVisibility();
        }

        function updateNextButtonVisibility() {
            const nextButtonContainer = document.getElementById('nextButtonContainer');
            if (selectedRefundItems.length > 0) {
                nextButtonContainer.style.display = 'block';
            } else {
                nextButtonContainer.style.display = 'none';
            }
        }

        function removeSelectedItem(index) {
            const removedItem = selectedRefundItems.splice(index, 1)[0];
            const checkbox = document.querySelector(`input[data-drug-id="${removedItem.drug_id}"]`);
            if (checkbox) {
                checkbox.checked = false;
                checkbox.closest('tr').style.backgroundColor = '';
                const quantityInput = checkbox.closest('tr').querySelector('input[type="number"]');
                if (quantityInput) {
                    quantityInput.disabled = true;
                    quantityInput.value = 0;
                }
            }
            updateSelectedItemsSummary();
        }

        function goBackToProductSelection() {
            // Hide the refund configuration section
            document.getElementById('refundDetails').style.display = 'none';
            document.getElementById('processRefundBtn').style.display = 'none';
            
            // Show the refund type selection and dispense refund section again
            document.getElementById('refundTypeSelection').style.display = 'block';
            document.getElementById('dispenseRefundSection').style.display = 'block';
            
            // Ensure the next button is visible if items are selected
            updateNextButtonVisibility();
        }

        function clearAllSelections() {
            selectedRefundItems = [];
            document.querySelectorAll('.product-checkbox').forEach(checkbox => {
                checkbox.checked = false;
            });
            document.getElementById('selectAllCheckbox').checked = false;
            document.querySelectorAll('.refund-product-row').forEach(row => {
                row.style.backgroundColor = '';
                const quantityInput = row.querySelector('input[type="number"]');
                if (quantityInput) {
                    quantityInput.disabled = true;
                    quantityInput.value = 0;
                }
            });
            updateSelectedItemsSummary();
        }

        function proceedToRefundConfiguration() {
            if (selectedRefundItems.length === 0) {
                showNotification('Please select at least one product for refund', 'error');
                return;
            }
            
            // Hide the refund type selection and dispense refund section, show simplified configuration
            document.getElementById('refundTypeSelection').style.display = 'none';
            document.getElementById('dispenseRefundSection').style.display = 'none';
            document.getElementById('refundDetails').style.display = 'block';
            document.getElementById('processRefundBtn').style.display = 'inline-block';
            
            // Populate simplified refund configuration
            populateSimplifiedRefundConfiguration();
        }

        function populateSimplifiedRefundConfiguration() {
            const itemInfo = document.getElementById('refundItemInfo');
            const totalAmount = selectedRefundItems.reduce((sum, item) => sum + item.refundable_amount, 0);
            
            let itemsHtml = `
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                    <thead>
                        <tr style="background: #f8f9fa; border-bottom: 2px solid #dee2e6;">
                            <th style="padding: 12px; text-align: left; font-weight: 600; color: #495057; font-size: 13px; width: 50%;">
                                <i class="fas fa-pills"></i> Product
                            </th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: #495057; font-size: 13px; width: 25%;">
                                <i class="fas fa-sort-numeric-up"></i> QTY
                            </th>
                            <th style="padding: 12px; text-align: right; font-weight: 600; color: #495057; font-size: 13px; width: 25%;">
                                <i class="fas fa-dollar-sign"></i> Price
                            </th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            selectedRefundItems.forEach((item, index) => {
                itemsHtml += `
                    <tr style="border-bottom: 1px solid #f0f0f0;">
                        <td style="padding: 12px; text-align: left;">
                            <div style="font-weight: 600; color: #333; font-size: 14px;">${item.product_name}</div>
                        </td>
                        <td style="padding: 12px; text-align: center; font-weight: 600; color: #495057;">
                            ${parseInt(item.selected_quantity)}
                        </td>
                        <td style="padding: 12px; text-align: right; font-weight: 700; color: #28a745;">
                            $${item.refundable_amount.toFixed(2)}
                        </td>
                    </tr>
                `;
            });
            
            itemsHtml += `
                    </tbody>
                    <tfoot>
                        <tr style="background: #f8f9fa; border-top: 2px solid #dee2e6;">
                            <td style="padding: 15px; text-align: left; font-weight: 600; color: #495057; font-size: 16px;">
                                Total
                            </td>
                            <td style="padding: 15px; text-align: center; font-weight: 600; color: #495057; font-size: 16px;">
                                ${selectedRefundItems.reduce((sum, item) => sum + parseInt(item.selected_quantity), 0)}
                            </td>
                            <td style="padding: 15px; text-align: right; font-weight: 700; color: #28a745; font-size: 18px;">
                                $${totalAmount.toFixed(2)}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            `;
            
            itemInfo.innerHTML = itemsHtml;
            
            // Show item details card
            document.getElementById('refundItemDetails').style.display = 'block';
            
            // Load patient credit balance
            loadPatientCreditBalance();
        }

        function updateItemQuantity(index, newQuantity) {
            const item = selectedRefundItems[index];
            const maxQuantity = item.max_quantity;
            const originalAmount = item.refundable_amount;
            
            if (newQuantity > maxQuantity) {
                newQuantity = maxQuantity;
            }
            
            item.selected_quantity = newQuantity;
            item.refundable_amount = (newQuantity / maxQuantity) * originalAmount;
            
            // Update display
            populateMultiItemRefundConfiguration();
            updateSelectedItemsSummary();
        }

        function viewProductDetails(drugId, productName, maxQuantity, refundableAmount) {
            // Show a modal or tooltip with detailed product information
            showNotification(`Product: ${productName}\nMax Quantity: ${maxQuantity}\nRefund Amount: $${refundableAmount.toFixed(2)}`, 'info');
        }

        // Reset refund form to initial state
        function resetRefundForm() {
            // Reset form fields
            document.getElementById('refundQuantity').value = 1;
            document.getElementById('manualRefundAmount').value = '';
            document.getElementById('refundReason').value = '';
            document.querySelector('input[name="refundType"][value="payment"]').checked = true;
            
            // Hide all sections
            document.getElementById('creditBalanceInfo').style.display = 'none';
            document.getElementById('refundDetails').style.display = 'none';
            document.getElementById('dispenseRefundSection').style.display = 'none';
            document.getElementById('manualRefundSection').style.display = 'none';
            document.getElementById('processRefundBtn').style.display = 'none';
            
            // Reset selection styling
            document.getElementById('refundTypeDispense').style.borderColor = '#e9ecef';
            document.getElementById('refundTypeDispense').style.backgroundColor = '#f8f9fa';
            document.getElementById('refundTypeOther').style.borderColor = '#e9ecef';
            document.getElementById('refundTypeOther').style.backgroundColor = '#f8f9fa';
            
            // Reset current refund data
            currentRefundType = null;
            currentRefundData = {
                itemId: null,
                drugId: null,
                drugName: null,
                maxQuantity: 0,
                originalAmount: 0,
                totalAmount: 0,
                itemType: null
            };
            
            // Reset displays
            document.getElementById('manualRefundAmountDisplay').textContent = '$0.00';
        }

        // Toggle dispense tracking section
        function toggleDispenseTracking() {
            console.log('toggleDispenseTracking called');
            const dispenseSection = document.getElementById('dispense-tracking-section');
            const mainPosSection = document.getElementById('main-pos-section');
            const refundSection = document.getElementById('refund-section');
            const weightSection = document.getElementById('weight-tracker-section');
            
            console.log('dispenseSection found:', !!dispenseSection);
            console.log('mainPosSection found:', !!mainPosSection);
            
            if (dispenseSection && mainPosSection) {
                // Hide other sections if they're open
                if (refundSection) {
                    refundSection.style.display = 'none';
                }
                if (weightSection) {
                    weightSection.style.display = 'none';
                }
                
                if (dispenseSection.style.display === 'block') {
                    // Currently showing dispense tracking, hide it and show POS
                    console.log('Hiding dispense tracking, showing POS');
                    dispenseSection.style.display = 'none';
                    mainPosSection.style.display = 'block';
                } else {
                    // Currently showing POS, hide it and show dispense tracking
                    console.log('Hiding POS, showing dispense tracking');
                    mainPosSection.style.display = 'none';
                    dispenseSection.style.display = 'block';
                    
                    // Reset to dispense tracking view and load data
                    resetToDispenseTracking();
                    loadRemainingDispenseData();
                }
            } else {
                console.error('Required elements not found:', {
                    dispenseSection: !!dispenseSection,
                    mainPosSection: !!mainPosSection
                });
            }
        }

        // Show dispense tracking section (for backward compatibility)
        function showDispenseTracking() {
            const dispenseSection = document.getElementById('dispense-tracking-section');
            const mainPosSection = document.getElementById('main-pos-section');
            const transferSection = document.getElementById('transfer-section');
            const refundSection = document.getElementById('refund-section');
            
            if (dispenseSection && mainPosSection) {
                // Hide main POS section
                mainPosSection.style.display = 'none';
                
                // Hide transfer section if it's open
                if (transferSection) {
                    transferSection.style.display = 'none';
                }
                
                // Hide refund section if it's open
                if (refundSection) {
                    refundSection.style.display = 'none';
                }
                
                // Show dispense tracking section
                dispenseSection.style.display = 'block';
                
                // Reset to dispense tracking view and load data
                resetToDispenseTracking();
                loadRemainingDispenseData();
            }
        }

        // Hide dispense tracking section
        function hideDispenseTracking() {
            const dispenseSection = document.getElementById('dispense-tracking-section');
            const mainPosSection = document.getElementById('main-pos-section');
            
            if (dispenseSection && mainPosSection) {
                // Hide dispense tracking section
                dispenseSection.style.display = 'none';
                // Show main POS section
                mainPosSection.style.display = 'block';
            }
        }

        // Toggle weight tracker section
        function toggleWeightTracker() {
            console.log('toggleWeightTracker called');
            const weightSection = document.getElementById('weight-tracker-section');
            const mainPosSection = document.getElementById('main-pos-section');
            const refundSection = document.getElementById('refund-section');
            const dispenseSection = document.getElementById('dispense-tracking-section');
            const transferSection = document.getElementById('transfer-section');
            
            console.log('weightSection found:', !!weightSection);
            console.log('mainPosSection found:', !!mainPosSection);
            
            if (weightSection && mainPosSection) {
                // Hide other sections if they're open
                if (refundSection) {
                    refundSection.style.display = 'none';
                }
                if (dispenseSection) {
                    dispenseSection.style.display = 'none';
                }
                if (transferSection) {
                    transferSection.style.display = 'none';
                }
                
                if (weightSection.style.display === 'block') {
                    // Hide weight tracker section
                    weightSection.style.display = 'none';
                    // Show main POS section
                    mainPosSection.style.display = 'block';
                } else {
                    // Show weight tracker section
                    console.log('Showing weight tracker section');
                    weightSection.style.display = 'block';
                    // Hide main POS section
                    mainPosSection.style.display = 'none';
                    
                    // Load current weight and history
                    console.log('About to call loadCurrentWeight and loadWeightHistory');
                    try {
                        loadCurrentWeight();
                    } catch (error) {
                        console.error('Error calling loadCurrentWeight:', error);
                    }
                    try {
                        loadWeightHistory();
                    } catch (error) {
                        console.error('Error calling loadWeightHistory:', error);
                    }
                }
            }
        }

        // Hide weight tracker section
        function hideWeightTracker() {
            const weightSection = document.getElementById('weight-tracker-section');
            const mainPosSection = document.getElementById('main-pos-section');
            
            if (weightSection && mainPosSection) {
                // Hide weight tracker section
                weightSection.style.display = 'none';
                // Show main POS section
                mainPosSection.style.display = 'block';
            }
        }

        // Load current weight
        function loadCurrentWeight() {
            try {
                console.log('loadCurrentWeight called, currentPatientId:', currentPatientId);
                
                if (!currentPatientId) {
                    console.log('No patient selected, setting error message');
                    document.getElementById('current-weight-value').textContent = 'No patient selected';
                    return;
                }

            console.log('Loading current weight for patient:', currentPatientId);
            
            // Get current user ID from PHP session
            const currentUserId = <?php echo json_encode($_SESSION['authUserID'] ?? null); ?>;
            console.log('Current user ID:', currentUserId);
            console.log('Current user ID type:', typeof currentUserId);
            
            const fetchUrl = `pos_weight_handler.php?action=get_current_weight&pid=${currentPatientId}&user_id=${currentUserId}`;
            console.log('Making fetch request to:', fetchUrl);
            
            fetch(fetchUrl, {
                credentials: 'include'
            })
                .then(response => {
                    console.log('Weight handler response status:', response.status);
                    console.log('Weight handler response headers:', response.headers);
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Weight handler response data:', data);
                    console.log('Data success:', data.success);
                    console.log('Data weight:', data.weight);
                    console.log('Data recorded_by:', data.recorded_by);
                    
                    if (data.success) {
                        if (data.weight !== null && data.weight !== undefined && data.weight !== '') {
                            setWeightRecordedForToday(isWeightRecordedToday(data.date));
                            console.log('Setting weight display to:', data.weight + ' lbs');
                            document.getElementById('current-weight-value').textContent = data.weight + ' lbs';
                            const patientWeightInput = document.getElementById('patient-weight');
                            if (patientWeightInput) {
                                patientWeightInput.value = data.weight;
                            }
                            let dateText = 'Last recorded: ' + new Date(data.date).toLocaleDateString();
                            if (data.recorded_by) {
                                dateText += ' by ' + data.recorded_by;
                            }
                            document.getElementById('current-weight-date').textContent = dateText;
                            console.log('Weight display updated successfully');
                        } else {
                            setWeightRecordedForToday(false);
                            console.log('No weight data, setting no weight message');
                            document.getElementById('current-weight-value').textContent = 'No weight recorded';
                            document.getElementById('current-weight-date').textContent = '';
                        }
                    } else {
                        setWeightRecordedForToday(false);
                        console.log('Response not successful, setting error message');
                        document.getElementById('current-weight-value').textContent = 'Error loading weight';
                        document.getElementById('current-weight-date').textContent = '';
                    }
                })
                .catch(error => {
                    setWeightRecordedForToday(false);
                    console.error('Error loading current weight:', error);
                    console.error('Error details:', error.message);
                    document.getElementById('current-weight-value').textContent = 'Error loading weight: ' + error.message;
                    document.getElementById('current-weight-date').textContent = '';
                });
            } catch (error) {
                console.error('Error in loadCurrentWeight function:', error);
                document.getElementById('current-weight-value').textContent = 'Error in loadCurrentWeight: ' + error.message;
                document.getElementById('current-weight-date').textContent = '';
            }
        }

        // Daily weight tracking - a recorded weight remains valid for the calendar day
        let weightRecordedForToday = false;

        function isWeightRecordedToday(weightDate) {
            if (!weightDate) {
                return false;
            }

            const recordedDate = new Date(weightDate);
            if (Number.isNaN(recordedDate.getTime())) {
                return false;
            }

            const today = new Date();
            return recordedDate.getFullYear() === today.getFullYear()
                && recordedDate.getMonth() === today.getMonth()
                && recordedDate.getDate() === today.getDate();
        }

        function isInjectionWeightExempt() {
            const injectionCheckbox = document.getElementById('injection-checkbox');
            const hasInjectionChecked = !!(injectionCheckbox && injectionCheckbox.checked);
            const hasInjectionInCart = Array.isArray(cart) && cart.some(item =>
                (item && typeof item.id === 'string' && item.id.startsWith('injection_')) ||
                (item && (item.display_name === 'Injection' || item.name === 'Injection'))
            );

            return hasInjectionChecked || hasInjectionInCart;
        }
        
        // Validate if patient has weight recorded for TODAY
        async function validateSessionWeight() {
            try {
                console.log('validateSessionWeight called - Daily flag:', weightRecordedForToday);
                
                if (!currentPatientId) {
                    return {
                        hasWeight: false,
                        message: 'No patient selected',
                        requiresRecording: true
                    };
                }

                if (isInjectionWeightExempt()) {
                    return {
                        hasWeight: true,
                        message: 'Injection selected - weight not required',
                        requiresRecording: false
                    };
                }

                if (weightRecordedForToday) {
                    console.log('Weight already recorded today - validation passed');
                    return {
                        hasWeight: true,
                        message: 'Weight recorded for today',
                        requiresRecording: false
                    };
                }
                
                console.log('Weight NOT recorded today - validation failed');
                return {
                    hasWeight: false,
                    message: 'Weight must be recorded for today',
                    requiresRecording: true
                };
            } catch (error) {
                console.error('Error validating session weight:', error);
                return {
                    hasWeight: false,
                    message: 'Error checking weight: ' + error.message,
                    requiresRecording: true
                };
            }
        }
        
        // Mark weight as recorded for today
        function markWeightRecorded() {
            weightRecordedForToday = true;
            console.log('Weight marked as recorded for today');
            
            // Update the UI to show weight has been recorded
            updateWeightRecordingStatus();
            
            // Update validation status if on Order Summary
            if (currentStep === 1) {
                updateWeightValidationStatus();
            }
        }
        
        function setWeightRecordedForToday(isRecorded) {
            weightRecordedForToday = !!isRecorded;
            console.log('Weight recorded today flag set to:', weightRecordedForToday);
            updateWeightRecordingStatus();

            if (currentStep === 1) {
                updateWeightValidationStatus();
            }
        }
        
        // Update weight recording status UI
        function updateWeightRecordingStatus() {
            const container = document.getElementById('weight-recording-container');
            const iconContainer = document.getElementById('weight-icon-container');
            const label = document.getElementById('weight-recording-label');
            const subtitle = document.getElementById('weight-recording-subtitle');
            const badge = document.getElementById('weight-recording-badge');
            
            if (!container || !iconContainer || !label || !subtitle || !badge) return;
            
            if (isInjectionWeightExempt()) {
                container.style.borderLeft = '4px solid #17a2b8';
                container.style.background = 'linear-gradient(135deg, #e8f7fb 0%, #d7f0f7 100%)';
                
                iconContainer.style.background = 'linear-gradient(135deg, #17a2b8 0%, #138496 100%)';
                iconContainer.style.boxShadow = '0 2px 8px rgba(23,162,184,0.3)';
                
                label.style.color = '#0c5460';
                label.innerHTML = 'Weight Recording Optional';
                
                subtitle.style.color = '#0c5460';
                subtitle.innerHTML = 'Injection selected, so weight is not required for checkout';
                
                badge.style.background = '#17a2b8';
                badge.innerHTML = 'Optional';
                
                console.log('UI updated - weight optional for injection');
            } else if (weightRecordedForToday) {
                // Weight has been recorded - show success state
                container.style.borderLeft = '4px solid #28a745';
                container.style.background = 'linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%)';
                
                iconContainer.style.background = 'linear-gradient(135deg, #28a745 0%, #218838 100%)';
                iconContainer.style.boxShadow = '0 2px 8px rgba(40,167,69,0.3)';
                
                label.style.color = '#155724';
                label.innerHTML = 'Weight Recorded Successfully';
                
                subtitle.style.color = '#155724';
                subtitle.innerHTML = 'Patient weight has been recorded for today';
                
                badge.style.background = '#28a745';
                badge.innerHTML = 'Completed';
                
                console.log('UI updated - weight recorded (green)');
            } else {
                // Weight NOT recorded - show required state
                container.style.borderLeft = '4px solid #dc3545';
                container.style.background = 'linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%)';
                
                iconContainer.style.background = 'linear-gradient(135deg, #dc3545 0%, #c82333 100%)';
                iconContainer.style.boxShadow = '0 2px 8px rgba(220,53,69,0.3)';
                
                label.style.color = '#495057';
                label.innerHTML = 'Weight Recording Required';
                
                subtitle.style.color = '#6c757d';
                subtitle.innerHTML = 'Record patient weight to proceed with checkout';
                
                badge.style.background = '#dc3545';
                badge.innerHTML = 'Required';
                
                console.log('UI updated - weight required (red)');
            }
        }

        // Update weight validation status (checkout button state only)
        async function updateWeightValidationStatus() {
            const checkoutBtn = document.getElementById('checkout-btn');
            
            if (!checkoutBtn) return;
            
            try {
                const validation = await validateSessionWeight();
                
                if (validation.hasWeight) {
                    // Weight is valid - enable checkout button
                    checkoutBtn.disabled = false;
                    checkoutBtn.style.opacity = '1';
                    checkoutBtn.style.cursor = 'pointer';
                } else {
                    // Weight is missing - disable checkout button
                    checkoutBtn.disabled = true;
                    checkoutBtn.style.opacity = '0.6';
                    checkoutBtn.style.cursor = 'not-allowed';
                }
            } catch (error) {
                console.error('Error updating weight validation status:', error);
                // Don't disable button on error - allow checkout to proceed
                checkoutBtn.disabled = false;
                checkoutBtn.style.opacity = '1';
                checkoutBtn.style.cursor = 'pointer';
            }
        }

        let currentWeightHistoryData = [];

        function getCurrentPosPatientName() {
            const nameEl = document.querySelector('.patient-name');
            return (nameEl?.textContent || 'Patient').trim();
        }

        // Load weight history
        function loadWeightHistory() {
            if (!currentPatientId) {
                document.getElementById('weight-history-container').innerHTML = '<div style="text-align: center; padding: 20px; color: #6c757d;">No patient selected</div>';
                return;
            }

            // Get current user ID from PHP session
            const currentUserId = <?php echo json_encode($_SESSION['authUserID'] ?? null); ?>;
            
            fetch(`pos_weight_handler.php?action=get_weight_history&pid=${currentPatientId}&user_id=${currentUserId}`, {
                credentials: 'include'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.history && data.history.length > 0) {
                        currentWeightHistoryData = data.history;
                        let historyHtml = '<table style="width: 100%; border-collapse: collapse; background: white;">';
                        historyHtml += '<thead><tr style="background: #f8f9fa; border-bottom: 2px solid #dee2e6;">';
                        historyHtml += '<th style="padding: 15px 12px; text-align: left; font-weight: 600; color: #495057; font-size: 14px; border-right: 1px solid #dee2e6;">Date</th>';
                        historyHtml += '<th style="padding: 15px 12px; text-align: left; font-weight: 600; color: #495057; font-size: 14px; border-right: 1px solid #dee2e6;">Weight</th>';
                        historyHtml += '<th style="padding: 15px 12px; text-align: left; font-weight: 600; color: #495057; font-size: 14px; border-right: 1px solid #dee2e6;">Change</th>';
                        historyHtml += '<th style="padding: 15px 12px; text-align: left; font-weight: 600; color: #495057; font-size: 14px; border-right: 1px solid #dee2e6;">Recorded By</th>';
                        historyHtml += '<th style="padding: 15px 12px; text-align: left; font-weight: 600; color: #495057; font-size: 14px;">Notes</th>';
                        historyHtml += '</tr></thead><tbody>';

                        data.history.forEach((entry, index) => {
                            // Calculate change from previous entry (next in array since it's ordered DESC)
                            const change = index < data.history.length - 1 ? 
                                (entry.weight - data.history[index + 1].weight).toFixed(1) : '0.0';
                            const changeColor = change > 0 ? '#dc3545' : change < 0 ? '#28a745' : '#6c757d';
                            const changeIcon = change > 0 ? '↗' : change < 0 ? '↘' : '→';


                            historyHtml += '<tr style="border-bottom: 1px solid #e9ecef; transition: background-color 0.2s ease;" onmouseover="this.style.backgroundColor=\'#f8f9fa\'" onmouseout="this.style.backgroundColor=\'white\'">';
                            historyHtml += `<td style="padding: 15px 12px; color: #495057; font-size: 14px; border-right: 1px solid #e9ecef;">${new Date(entry.date_recorded).toLocaleDateString()}</td>`;
                            historyHtml += `<td style="padding: 15px 12px; font-weight: 600; color: #333; font-size: 14px; border-right: 1px solid #e9ecef;">${entry.weight} lbs</td>`;
                            historyHtml += `<td style="padding: 15px 12px; color: ${changeColor}; font-weight: 600; font-size: 14px; border-right: 1px solid #e9ecef;">${changeIcon} ${Math.abs(change)} lbs</td>`;
                            historyHtml += `<td style="padding: 15px 12px; color: #6c757d; font-size: 13px; border-right: 1px solid #e9ecef;">${entry.user_name || entry.user_id || 'Unknown'}</td>`;
                            historyHtml += `<td style="padding: 15px 12px; color: #6c757d; font-size: 13px;">${entry.notes || '-'}</td>`;
                            historyHtml += '</tr>';
                        });

                        historyHtml += '</tbody></table>';
                        document.getElementById('weight-history-container').innerHTML = historyHtml;
                        
                        // Calculate and display total weight loss
                        calculateTotalWeightLoss(data.history);
                        
                        // Render the weight progress chart
                        renderWeightChart(data.history);
                    } else {
                        currentWeightHistoryData = [];
                        document.getElementById('weight-history-container').innerHTML = '<div style="text-align: center; padding: 20px; color: #6c757d;">No weight history found</div>';
                        // Reset treatment summary
                        document.getElementById('total-weight-loss').textContent = '-0.0 lbs';
                        
                        // Show no data message for chart
                        renderWeightChart([]);
                    }
                })
                .catch(error => {
                    currentWeightHistoryData = [];
                    console.error('Error loading weight history:', error);
                    document.getElementById('weight-history-container').innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;">Error loading weight history</div>';
                });
        }

        // Global variable to store the chart instance
        let weightChart = null;

        // Render weight progress chart
        function renderWeightChart(weightHistory) {
            // Show loading state
            document.getElementById('chart-loading').style.display = 'block';
            document.getElementById('chart-no-data').style.display = 'none';
            document.getElementById('weightChart').style.display = 'none';
            
            if (!weightHistory || weightHistory.length === 0) {
                // Show no data message
                document.getElementById('chart-loading').style.display = 'none';
                document.getElementById('chart-no-data').style.display = 'block';
                return;
            }
            
            // Sort data by date (oldest first for proper chart display)
            const sortedData = weightHistory.sort((a, b) => new Date(a.date_recorded) - new Date(b.date_recorded));
            
            // Prepare chart data
            const labels = sortedData.map(entry => {
                const date = new Date(entry.date_recorded);
                return date.toLocaleDateString('en-US', { 
                    month: 'short', 
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            });
            
            const weights = sortedData.map(entry => parseFloat(entry.weight));
            
            // Calculate weight change from first entry
            const firstWeight = weights[0];
            const weightChanges = weights.map(weight => {
                const change = weight - firstWeight;
                return change > 0 ? `+${change.toFixed(1)}` : change.toFixed(1);
            });
            
            // Hide loading and show chart
            document.getElementById('chart-loading').style.display = 'none';
            document.getElementById('chart-no-data').style.display = 'none';
            document.getElementById('weightChart').style.display = 'block';
            
            // Destroy existing chart if it exists
            if (weightChart) {
                weightChart.destroy();
            }
            
            // Get chart context
            const ctx = document.getElementById('weightChart').getContext('2d');
            
            // Store sorted data globally for plugin access
            window.chartSortedData = sortedData;
            
            // Prepare point colors based on medicine administration
            const pointColors = sortedData.map(entry => {
                // If medicines were administered on this day, use medical blue/teal color
                if (entry.medicines_for_day && entry.medicines_for_day.length > 0) {
                    return '#17a2b8'; // Medical blue/teal for medicine administered
                }
                return '#2196f3'; // Blue for regular weight recording
            });
            
            const pointBorders = sortedData.map(entry => {
                if (entry.medicines_for_day && entry.medicines_for_day.length > 0) {
                    return '#ffffff'; // White border for medicine points
                }
                return '#ffffff'; // White border for regular points
            });
            
            // Track hover state to hide labels
            window.chartHoverState = { isHovering: false, hoverIndex: -1 };
            
            // Chart.js plugin to draw medicine labels on chart
            const medicineLabelPlugin = {
                id: 'medicineLabels',
                afterDraw: (chart) => {
                    const ctx = chart.ctx;
                    const meta = chart.getDatasetMeta(0);
                    
                    if (!window.chartSortedData || !meta || !meta.data) {
                        return;
                    }
                    
                    // Don't draw labels if hovering
                    if (window.chartHoverState.isHovering) {
                        return;
                    }
                    
                    // Draw labels for each point with medicine
                    window.chartSortedData.forEach((entry, index) => {
                        if (!entry.medicines_for_day || entry.medicines_for_day.length === 0) return;
                        
                        // Group medicines by name
                        const medicineMap = {};
                        entry.medicines_for_day.forEach(med => {
                            const medName = med.drug_name || med.name || 'Unknown';
                            const qty = med.administered_quantity || med.dispensed_quantity || 0;
                            if (qty > 0) {
                                if (!medicineMap[medName]) {
                                    medicineMap[medName] = 0;
                                }
                                medicineMap[medName] += qty;
                            }
                        });
                        
                        const medicineNames = Object.keys(medicineMap);
                        if (medicineNames.length === 0) return;
                        
                        // Get point position
                        const point = meta.data[index];
                        if (!point || point.x === undefined || point.y === undefined) return;
                        
                        const xPos = point.x;
                        const yPos = point.y;
                        
                        // Create compact medicine label
                        const firstMedicine = medicineNames[0];
                        let medicineText = firstMedicine.length > 14 ? firstMedicine.substring(0, 12) + '..' : firstMedicine;
                        if (medicineNames.length > 1) {
                            medicineText += ` +${medicineNames.length - 1}`;
                        }
                        
                        ctx.save();
                        ctx.font = '500 12px "Segoe UI", Arial, sans-serif';
                        const textPadding = 12;
                        const iconWidth = 18;
                        const text = medicineText;
                        const textMetrics = ctx.measureText(text);
                        const labelWidth = Math.min(textMetrics.width + textPadding * 2 + iconWidth, chart.chartArea.right - chart.chartArea.left - 20);
                        const labelHeight = 26;
                        
                        // Positioning - above the point by default
                        let labelX = xPos - labelWidth / 2;
                        let labelY = yPos - 32;
                        
                        // Check boundaries and adjust
                        if (labelY - labelHeight / 2 < chart.chartArea.top + 5) {
                            labelY = yPos + 32;
                        }
                        if (labelX + labelWidth > chart.chartArea.right - 10) {
                            labelX = chart.chartArea.right - labelWidth - 10;
                        }
                        if (labelX < chart.chartArea.left + 10) {
                            labelX = chart.chartArea.left + 10;
                        }
                        
                        // Draw label background with simple, clean styling
                        ctx.shadowColor = 'rgba(0, 0, 0, 0.2)';
                        ctx.shadowBlur = 5;
                        ctx.shadowOffsetX = 0;
                        ctx.shadowOffsetY = 2;
                        
                        // Use a more medical/clinical color scheme
                        ctx.fillStyle = '#17a2b8'; // Medical blue/teal
                        ctx.strokeStyle = '#138496';
                        ctx.lineWidth = 1.5;
                        
                        // Simple rounded rectangle
                        const cornerRadius = 13;
                        ctx.beginPath();
                        ctx.moveTo(labelX + cornerRadius, labelY - labelHeight / 2);
                        ctx.lineTo(labelX + labelWidth - cornerRadius, labelY - labelHeight / 2);
                        ctx.quadraticCurveTo(labelX + labelWidth, labelY - labelHeight / 2, labelX + labelWidth, labelY - labelHeight / 2 + cornerRadius);
                        ctx.lineTo(labelX + labelWidth, labelY + labelHeight / 2 - cornerRadius);
                        ctx.quadraticCurveTo(labelX + labelWidth, labelY + labelHeight / 2, labelX + labelWidth - cornerRadius, labelY + labelHeight / 2);
                        ctx.lineTo(labelX + cornerRadius, labelY + labelHeight / 2);
                        ctx.quadraticCurveTo(labelX, labelY + labelHeight / 2, labelX, labelY + labelHeight / 2 - cornerRadius);
                        ctx.lineTo(labelX, labelY - labelHeight / 2 + cornerRadius);
                        ctx.quadraticCurveTo(labelX, labelY - labelHeight / 2, labelX + cornerRadius, labelY - labelHeight / 2);
                        ctx.closePath();
                        ctx.fill();
                        ctx.stroke();
                        
                        // Reset shadow
                        ctx.shadowColor = 'transparent';
                        ctx.shadowBlur = 0;
                        ctx.shadowOffsetX = 0;
                        ctx.shadowOffsetY = 0;
                        
                        // Draw syringe/injection icon (💉 emoji)
                        ctx.fillStyle = '#ffffff';
                        ctx.font = '16px Arial';
                        ctx.textAlign = 'left';
                        ctx.textBaseline = 'middle';
                        ctx.fillText('💉', labelX + textPadding, labelY);
                        
                        // Draw text with better spacing
                        ctx.fillStyle = '#ffffff';
                        ctx.font = '500 12px "Segoe UI", Arial, sans-serif';
                        ctx.fillText(text, labelX + textPadding + iconWidth, labelY);
                        
                        ctx.restore();
                    });
                }
            };
            
            // Register the plugin
            try {
                // Use Chart.js plugin registration method
                if (typeof Chart.register === 'function') {
                    Chart.register(medicineLabelPlugin);
                }
            } catch (e) {
                console.warn('Chart.js plugin registration:', e.message);
            }
            
            // Create new chart with plugin
            weightChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Weight (lbs)',
                        data: weights,
                        borderColor: '#2196f3',
                        backgroundColor: 'rgba(33, 150, 243, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: pointColors,
                        pointBorderColor: pointBorders,
                        pointBorderWidth: 2,
                        pointRadius: sortedData.map(entry => {
                            // Larger points for days with medicine
                            return (entry.medicines_for_day && entry.medicines_for_day.length > 0) ? 8 : 6;
                        }),
                        pointHoverRadius: sortedData.map(entry => {
                            return (entry.medicines_for_day && entry.medicines_for_day.length > 0) ? 10 : 8;
                        })
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                padding: 15,
                                font: {
                                    size: 13,
                                    weight: '500'
                                },
                                generateLabels: function(chart) {
                                    return [
                                        {
                                            text: '⚬ Weight Recorded',
                                            fillStyle: '#2196f3',
                                            strokeStyle: '#2196f3',
                                            lineWidth: 0,
                                            hidden: false,
                                            index: 0
                                        },
                                        {
                                            text: '💉 Medicine Administered',
                                            fillStyle: '#17a2b8',
                                            strokeStyle: '#17a2b8',
                                            lineWidth: 0,
                                            hidden: false,
                                            index: 1
                                        }
                                    ];
                                }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.9)',
                            titleColor: '#ffffff',
                            bodyColor: '#ffffff',
                            borderColor: '#2196f3',
                            borderWidth: 2,
                            cornerRadius: 8,
                            displayColors: false,
                            padding: 15,
                            titleFont: {
                                size: 14,
                                weight: 'bold'
                            },
                            bodyFont: {
                                size: 13
                            },
                            callbacks: {
                                title: function(context) {
                                    const index = context[0].dataIndex;
                                    const entry = sortedData[index];
                                    const date = new Date(entry.date_recorded);
                                    return date.toLocaleDateString('en-US', { 
                                        weekday: 'long',
                                        year: 'numeric',
                                        month: 'long',
                                        day: 'numeric',
                                        hour: '2-digit',
                                        minute: '2-digit'
                                    });
                                },
                                label: function(context) {
                                    const weight = context.parsed.y;
                                    const change = weight - firstWeight;
                                    const changeText = change > 0 ? `+${change.toFixed(1)} lbs` : `${change.toFixed(1)} lbs`;
                                    const index = context.dataIndex;
                                    const entry = sortedData[index];
                                    
                                    let labels = [
                                        `Weight: ${weight} lbs`,
                                        `Change: ${changeText}`,
                                        `Recorded by: ${entry.user_name || 'Unknown'}`
                                    ];
                                    
                                    // Add medicine information if available
                                    if (entry.medicines_for_day && entry.medicines_for_day.length > 0) {
                                        labels.push(''); // Separator
                                        labels.push('💊 Medicines Administered:');
                                        
                                        // Group medicines by name to avoid duplicates
                                        const medicineMap = {};
                                        entry.medicines_for_day.forEach(med => {
                                            const medName = med.drug_name || med.name || 'Unknown';
                                            const qty = med.administered_quantity || med.dispensed_quantity || 0;
                                            if (qty > 0) {
                                                if (!medicineMap[medName]) {
                                                    medicineMap[medName] = 0;
                                                }
                                                medicineMap[medName] += qty;
                                            }
                                        });
                                        
                                        // Add medicines to labels
                                        Object.keys(medicineMap).forEach(medName => {
                                            labels.push(`  • ${medName} (Qty: ${medicineMap[medName]})`);
                                        });
                                    } else {
                                        labels.push('');
                                        labels.push('💊 No medicines administered on this day');
                                    }
                                    
                                    return labels;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            display: true,
                            title: {
                                display: true,
                                text: 'Date',
                                font: {
                                    size: 12,
                                    weight: '500'
                                },
                                color: '#666'
                            },
                            grid: {
                                display: true,
                                color: 'rgba(0, 0, 0, 0.1)'
                            },
                            ticks: {
                                maxTicksLimit: 8,
                                font: {
                                    size: 11
                                },
                                color: '#666'
                            }
                        },
                        y: {
                            display: true,
                            title: {
                                display: true,
                                text: 'Weight (lbs)',
                                font: {
                                    size: 12,
                                    weight: '500'
                                },
                                color: '#666'
                            },
                            grid: {
                                display: true,
                                color: 'rgba(0, 0, 0, 0.1)'
                            },
                            ticks: {
                                font: {
                                    size: 11
                                },
                                color: '#666',
                                callback: function(value) {
                                    return value + ' lbs';
                                }
                            }
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    animation: {
                        duration: 1000,
                        easing: 'easeInOutQuart'
                    }
                }
            });
            
            // Add hover event listeners to hide/show medicine labels
            const chartCanvas = document.getElementById('weightChart');
            if (chartCanvas) {
                chartCanvas.addEventListener('mousemove', function(event) {
                    const chart = weightChart;
                    if (!chart) return;
                    
                    const points = chart.getElementsAtEventForMode(event, 'index', { intersect: false }, true);
                    if (points && points.length > 0) {
                        if (!window.chartHoverState.isHovering) {
                            window.chartHoverState.isHovering = true;
                            chart.update('none');
                        }
                    }
                });
                
                chartCanvas.addEventListener('mouseleave', function() {
                    if (window.chartHoverState.isHovering) {
                        window.chartHoverState.isHovering = false;
                        window.chartHoverState.hoverIndex = -1;
                        if (weightChart) {
                            weightChart.update('none');
                        }
                    }
                });
            }
            
            console.log('Weight chart rendered successfully');
        }

        // Calculate total weight loss
        function calculateTotalWeightLoss(history) {
            if (!history || history.length < 2) {
                document.getElementById('total-weight-loss').textContent = '-0.0 lbs';
                return;
            }

            // Calculate total weight loss (oldest weight - most recent weight)
            const firstWeight = history[history.length - 1].weight; // Oldest weight
            const lastWeight = history[0].weight; // Most recent weight
            const totalLoss = (firstWeight - lastWeight).toFixed(1);
            
            document.getElementById('total-weight-loss').textContent = totalLoss >= 0 ? 
                `-${totalLoss} lbs` : `+${Math.abs(totalLoss)} lbs`;
            document.getElementById('total-weight-loss').style.color = totalLoss >= 0 ? '#28a745' : '#dc3545';
        }

        // Save weight
        function saveWeight() {
            const weightInput = document.getElementById('new-weight-input');
            const notesInput = document.getElementById('weight-notes-input');
            const saveBtn = document.getElementById('save-weight-btn');

            const weight = parseFloat(weightInput.value);
            const notes = notesInput.value.trim();

            if (!weight || weight <= 0 || weight > 1000) {
                alert('Please enter a valid weight between 1 and 1000 lbs');
                return;
            }

            if (!currentPatientId) {
                alert('No patient selected');
                return;
            }

            // Disable save button
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            const formData = new FormData();
            formData.append('action', 'save_weight');
            formData.append('pid', currentPatientId);
            formData.append('weight', weight);
            formData.append('notes', notes);

            fetch('pos_weight_handler.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Clear form
                    weightInput.value = '';
                    notesInput.value = '';
                    
                    // Reload current weight and history
                    loadCurrentWeight();
                    loadWeightHistory();
                    
                    // Show success message
                    showNotification('Weight saved successfully!', 'success');
                } else {
                    alert('Error saving weight: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error saving weight:', error);
                alert('Error saving weight. Please try again.');
            })
            .finally(() => {
                // Re-enable save button
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Weight';
            });
        }

        // Reset to dispense tracking view (default state)
        function resetToDispenseTracking() {
            const dispenseContent = document.getElementById('dispense-content');
            const transferContent = document.getElementById('transfer-history-content');
            const transferBtn = document.getElementById('transferHistoryBtn');
            
            if (dispenseContent && transferContent && transferBtn) {
                // Always show dispense tracking and hide transfer history
                dispenseContent.style.display = 'block';
                transferContent.style.display = 'none';
                
                // Reset button to default state
                transferBtn.innerHTML = '<i class="fas fa-exchange-alt"></i> View Transfer History';
                transferBtn.style.background = '#17a2b8';
            }
        }

        // Toggle transfer history
        function toggleTransferHistory() {
            const dispenseContent = document.getElementById('dispense-content');
            const transferContent = document.getElementById('transfer-history-content');
            const transferBtn = document.getElementById('transferHistoryBtn');
            
            if (dispenseContent && transferContent && transferBtn) {
                if (transferContent.style.display === 'block') {
                    // Currently showing transfer history, close it and show dispense tracking
                    transferContent.style.display = 'none';
                    dispenseContent.style.display = 'block';
                    transferBtn.innerHTML = '<i class="fas fa-exchange-alt"></i> View Transfer History';
                    transferBtn.style.background = '#17a2b8';
                } else {
                    // Currently showing dispense tracking, expand to show transfer history
                    dispenseContent.style.display = 'none';
                    transferContent.style.display = 'block';
                    transferBtn.innerHTML = '<i class="fas fa-times"></i> Close Transfer History';
                    transferBtn.style.background = '#dc3545';
                    loadTransferHistory();
                }
            }
        }

        // Load transfer history
        function loadTransferHistory() {
            const currentPid = <?php echo $pid ?: 'null'; ?>;
            if (!currentPid) {
                return;
            }

            const contentDiv = document.getElementById('transfer-history-content');
            if (!contentDiv) {
                return;
            }

            // Show loading state
            contentDiv.innerHTML = `
                <div style="text-align: center; padding: 40px 20px;">
                    <div style="background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%); border: 1px solid #ffeaa7; border-radius: 8px; padding: 30px; display: inline-block;">
                        <i class="fas fa-spinner fa-spin" style="font-size: 48px; color: #856404; margin-bottom: 15px;"></i>
                        <h4 style="margin: 0 0 10px 0; color: #856404; font-weight: 600;">Loading Transfer History</h4>
                        <p style="margin: 0; color: #856404; font-size: 14px;">Fetching transfer history...</p>
                    </div>
                </div>
            `;

            fetch('pos_transfer_history_ajax.php?pid=' + currentPid)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.transfers && data.transfers.length > 0) {
                        showTransferHistoryContent(data.transfers);
                    } else {
                        contentDiv.innerHTML = `
                            <div style="text-align: center; padding: 40px 20px;">
                                <div style="background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); border: 1px solid #c3e6cb; border-radius: 8px; padding: 30px; display: inline-block;">
                                    <i class="fas fa-check-circle" style="font-size: 48px; color: #155724; margin-bottom: 15px;"></i>
                                    <h4 style="margin: 0 0 10px 0; color: #155724; font-weight: 600;">No Transfer History</h4>
                                    <p style="margin: 0; color: #155724; font-size: 14px;">This patient has no transfer history.</p>
                                </div>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading transfer history:', error);
                    contentDiv.innerHTML = `
                        <div style="text-align: center; padding: 40px 20px;">
                            <div style="background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%); border: 1px solid #f5c6cb; border-radius: 8px; padding: 30px; display: inline-block;">
                                <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #721c24; margin-bottom: 15px;"></i>
                                <h4 style="margin: 0 0 10px 0; color: #721c24; font-weight: 600;">Error Loading Transfer History</h4>
                                <p style="margin: 0; color: #721c24; font-size: 14px;">Failed to load transfer history. Please try again.</p>
                            </div>
                        </div>
                    `;
                });
        }
        // Show transfer history content
        function showTransferHistoryContent(transfers) {
            const contentDiv = document.getElementById('transfer-history-content');
            if (!contentDiv) return;

            let html = `
                <!-- Transfer History Header -->
                <div style="background: #f8f9fa; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #dee2e6;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="margin: 0; color: #495057; font-size: 18px; font-weight: 600;">
                            <i class="fas fa-exchange-alt" style="color: #17a2b8; margin-right: 8px;"></i> Transfer History
                        </h3>
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <span style="color: #6c757d; font-size: 14px;">${transfers.length} transfer${transfers.length !== 1 ? 's' : ''} found</span>
                            <button onclick="toggleTransferHistory()" style="background: #dc3545; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; transition: all 0.3s ease;">
                                <i class="fas fa-times"></i> Close
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="transfer-table-container" style="background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);">
                                <th style="padding: 15px 12px; text-align: left; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 14px;">Transfer ID</th>
                                <th style="padding: 15px 12px; text-align: left; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 14px;">Patient Name</th>
                                <th style="padding: 15px 12px; text-align: left; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 14px;">Product</th>
                                <th style="padding: 15px 12px; text-align: left; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 14px;">Lot Number</th>
                                <th style="padding: 15px 12px; text-align: left; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 14px;">Quantity</th>
                                <th style="padding: 15px 12px; text-align: left; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 14px;">Date & Time</th>
                                <th style="padding: 15px 12px; text-align: left; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 14px;">User</th>
                            </tr>
                        </thead>
                        <tbody>`;

            transfers.forEach(transfer => {
                const transferDate = new Date(transfer.transfer_date);
                const formattedDate = transferDate.toLocaleDateString() + ' ' + transferDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                
                html += `
                    <tr style="border-bottom: 1px solid #f1f1f1;">
                        <td style="padding: 12px; font-family: monospace; font-size: 12px; color: #6c757d;">
                            ${transfer.transfer_id}
                        </td>
                        <td style="padding: 12px;">
                            <span style="display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; ${transfer.transfer_type === 'out' ? 'background-color: #fff3cd; color: #856404;' : 'background-color: #d1ecf1; color: #0c5460;'}">
                                ${transfer.transfer_type === 'out' ? 'To' : 'From'}
                            </span>
                            <span style="margin-left: 8px; font-weight: 500;">
                                ${transfer.patient_name}
                            </span>
                        </td>
                        <td style="padding: 12px; font-weight: 500; color: #495057;">
                            ${transfer.drug_name}
                        </td>
                        <td style="padding: 12px; font-family: monospace; font-size: 12px; color: #6c757d;">
                            ${transfer.lot_number}
                        </td>
                        <td style="padding: 12px; font-weight: 600; color: #007bff;">
                            ${transfer.quantity_transferred}
                        </td>
                        <td style="padding: 12px; color: #6c757d; font-size: 13px;">
                            ${formattedDate}
                        </td>
                        <td style="padding: 12px; font-weight: 500; color: #495057;">
                            ${transfer.user_name}
                        </td>
                    </tr>`;
            });

            html += `
                        </tbody>
                    </table>
                </div>`;

            contentDiv.innerHTML = html;
        }

        // Toggle and load product updates (POS history) for a product group
        function toggleProductUpdates(drugId, updatesRowId, headerEl) {
            const row = document.getElementById(updatesRowId);
            if (!row) return;
            const isHidden = row.style.display === 'none';
            row.style.display = isHidden ? 'table-row' : 'none';
            try { headerEl.querySelector('i.fa-chevron-right')?.classList.toggle('rotated'); } catch (e) {}

            if (isHidden) {
                // Load updates for this product
                loadProductUpdates(drugId, updatesRowId + '-body');
            }
        }

        // Load POS updates for a product (all lots)
        function loadProductUpdates(drugId, targetBodyId) {
            const body = document.getElementById(targetBodyId);
            if (!body) return;
            body.innerHTML = `<tr><td colspan="8" style="padding: 20px; color:#6c757d; text-align:center; font-size: 14px;"><i class="fas fa-spinner fa-spin"></i> Loading updates...</td></tr>`;

            // Use transaction history endpoint in test mode to fetch all transactions for patient, then filter client-side by drugId
            fetch(`pos_transaction_history.php?pid=${currentPatientId}&test=1`)
                .then(res => res.json())
                .then(data => {
                    if (!data.success || !Array.isArray(data.transactions)) {
                        body.innerHTML = `<tr><td colspan="8" style="padding: 20px; color:#dc3545; text-align:center; font-size: 16px;">Failed to load updates</td></tr>`;
                        return;
                    }

                    const transactions = data.transactions.filter(t => String(t.drug_id) === String(drugId));

                    if (transactions.length === 0) {
                        body.innerHTML = `<tr><td colspan="8" style="padding: 20px; color:#6c757d; text-align:center; font-size: 16px;">No POS updates found for this product</td></tr>`;
                        return;
                    }

                    // Sort newest first
                    transactions.sort((a, b) => new Date(b.transaction_date) - new Date(a.transaction_date));

                    const rows = transactions.map(t => {
                        const date = new Date(t.transaction_date).toLocaleString();
                        const qty = Number(t.quantity || 0);
                        const disp = Number(t.dispense_quantity || 0);
                        const admin = Number(t.administer_quantity || 0);
                        const lot = t.lot_number || 'N/A';
                        const amount = Number(t.total_amount || 0).toFixed(2);
                        const by = t.created_by || '';
                        
                        // Add defective button ONLY if this transaction has administered quantities
                        const defectiveButton = admin > 0 ? 
                            `<button onclick="reportTransactionAsDefective(${t.drug_id}, '${t.drug_name || 'Unknown'}', '${lot}', ${admin}, '${t.transaction_date}', ${t.id || 0})" 
                                       class="defective-btn-dispense"
                                       style="background: #f8f9fa; color: #495057; border: 1px solid #dee2e6; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: 600; margin-left: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);" 
                                       title="Report as Defective (Max: ${admin})">
                                <i class="fas fa-exclamation-triangle"></i> Report
                            </button>` : '';
                        
                        return `<tr style="border-bottom: 1px solid #f1f1f1;">
                            <td style="text-align:center; padding: 12px 8px; color:#495057; font-size: 15px; min-width: 120px;">${date}</td>
                            <td style="text-align:center; padding: 12px 8px; min-width: 100px;"><span style="display:inline-block; min-width:60px; text-align:center; background:#eef5ff; border:1px solid #cfe2ff; border-radius:4px; padding:4px 8px; font-size: 14px; font-weight: 500;">${lot}</span></td>
                            <td style="text-align:center; padding: 12px 8px; color:#495057; font-size: 15px; min-width: 60px; font-weight: 600;">${qty}</td>
                            <td style="text-align:center; padding: 12px 8px; color:#28a745; font-size: 15px; min-width: 80px; font-weight: 600;">${disp}</td>
                            <td style="text-align:center; padding: 12px 8px; color:#17a2b8; font-size: 15px; min-width: 80px; font-weight: 600;">${admin}</td>
                            <td style="text-align:center; padding: 12px 8px; color:#495057; font-size: 15px; min-width: 90px; font-weight: 600;">$${amount}</td>
                            <td style="text-align:center; padding: 12px 8px; color:#6c757d; font-size: 15px; min-width: 120px;">${by}</td>
                            <td style="text-align:center; padding: 12px 8px; color:#495057; font-size: 15px; min-width: 100px; font-family: monospace;">
                                ${t.receipt_number || ''}
                                ${defectiveButton}
                            </td>
                        </tr>`;
                    }).join('');

                    body.innerHTML = rows;
                })
                .catch(() => {
                    body.innerHTML = `<tr><td colspan="8" style="padding: 20px; color:#dc3545; text-align:center; font-size: 16px;">Error loading updates</td></tr>`;
                });
        }

        // Load current patient weight for patient weight input field
        function loadCurrentPatientWeight() {
            const currentPid = <?php echo $pid ?: 'null'; ?>;
            if (!currentPid) {
                return;
            }

            fetch('pos_weight_handler.php?action=get_current_weight&pid=' + currentPid, {
                credentials: 'include'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.weight !== null && data.weight !== undefined && data.weight !== '') {
                        setWeightRecordedForToday(isWeightRecordedToday(data.date));
                        document.getElementById('patient-weight').value = data.weight;
                        showWeightStatus('Current weight: ' + data.weight + ' lbs', 'info');
                    } else {
                        setWeightRecordedForToday(false);
                    }
                })
                .catch(error => {
                    setWeightRecordedForToday(false);
                    console.error('Error loading current weight:', error);
                });
        }

        // Save patient weight
        function savePatientWeight() {
            const currentPid = <?php echo $pid ?: 'null'; ?>;
            const weightInput = document.getElementById('patient-weight');
            const weight = parseFloat(weightInput.value);

            if (!currentPid) {
                showWeightStatus('No patient selected', 'error');
                return;
            }

            if (!weight || weight <= 0) {
                showWeightStatus('Please enter a valid weight', 'error');
                return;
            }

            if (weight > 1000) {
                showWeightStatus('Weight seems too high. Please verify.', 'error');
                return;
            }

            const saveBtn = document.getElementById('save-weight-btn');
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            const formData = new FormData();
            formData.append('action', 'save_weight');
            formData.append('pid', currentPid);
            formData.append('weight', weight);
            formData.append('csrf_token', '<?php echo CsrfUtils::collectCsrfToken(); ?>');

            fetch('pos_weight_handler.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showWeightStatus('✓ Weight recorded successfully for today!', 'success');
                    weightInput.value = weight;
                    
                    // Mark weight as recorded for today
                    markWeightRecorded();
                } else {
                    showWeightStatus('Error: ' + (data.error || 'Failed to save weight'), 'error');
                }
            })
            .catch(error => {
                console.error('Error saving weight:', error);
                showWeightStatus('Error saving weight. Please try again.', 'error');
            })
            .finally(() => {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fas fa-save"></i> Record Weight';
            });
        }

        // Show weight status message
        function showWeightStatus(message, type) {
            const statusDiv = document.getElementById('weight-status');
            statusDiv.textContent = message;
            statusDiv.style.display = 'block';
            
            // Set color based on type
            switch(type) {
                case 'success':
                    statusDiv.style.color = '#28a745';
                    break;
                case 'error':
                    statusDiv.style.color = '#dc3545';
                    break;
                case 'info':
                    statusDiv.style.color = '#007bff';
                    break;
                default:
                    statusDiv.style.color = '#6c757d';
            }
            
            // Auto-hide after 3 seconds for success/info messages
            if (type === 'success' || type === 'info') {
                setTimeout(() => {
                    statusDiv.style.display = 'none';
                }, 3000);
            }
        }

        // Load remaining dispense quantities for the patient
        function loadRemainingDispenseData() {
            console.log('loadRemainingDispenseData called');
            const currentPid = <?php echo $pid ?: 'null'; ?>;
            console.log('loadRemainingDispenseData - PID:', currentPid);
            
            if (!currentPid) {
                console.log('loadRemainingDispenseData - No PID, exiting');
                return;
            }

            const contentDiv = document.getElementById('dispense-content');
            if (!contentDiv) {
                console.log('loadRemainingDispenseData - No content div found, exiting');
                return;
            }

            console.log('loadRemainingDispenseData - Content div found, showing loading state');

            // Show loading state
            contentDiv.innerHTML = `
                <div style="text-align: center; padding: 40px 20px;">
                    <div style="background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%); border: 1px solid #ffeaa7; border-radius: 8px; padding: 30px; display: inline-block;">
                        <i class="fas fa-spinner fa-spin" style="font-size: 48px; color: #856404; margin-bottom: 15px;"></i>
                        <h4 style="margin: 0 0 10px 0; color: #856404; font-weight: 600;">Loading Data</h4>
                        <p style="margin: 0; color: #856404; font-size: 14px;">Fetching remaining dispense information...</p>
                    </div>
                </div>
            `;

            const fetchUrl = 'pos_remaining_dispense.php?pid=' + currentPid;
            console.log('loadRemainingDispenseData - Fetching from:', fetchUrl);

            fetch(fetchUrl)
                .then(response => {
                    console.log('loadRemainingDispenseData - Response status:', response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('loadRemainingDispenseData - Data received:', data);
                    console.log('loadRemainingDispenseData - Items count:', data.remaining_items ? data.remaining_items.length : 0);
                    
                    if (data.success && data.remaining_items && data.remaining_items.length > 0) {
                        console.log('loadRemainingDispenseData - Showing items:', data.remaining_items);
                        showRemainingDispenseContent(data.remaining_items);
                    } else {
                        console.log('loadRemainingDispenseData - No items found, showing empty state');
                        contentDiv.innerHTML = `
                            <div style="text-align: center; padding: 40px 20px;">
                                <div style="background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); border: 1px solid #c3e6cb; border-radius: 8px; padding: 30px; display: inline-block;">
                                    <i class="fas fa-check-circle" style="font-size: 48px; color: #155724; margin-bottom: 15px;"></i>
                                    <h4 style="margin: 0 0 10px 0; color: #155724; font-weight: 600;">No Remaining Dispense</h4>
                                    <p style="margin: 0; color: #155724; font-size: 14px;">This patient has no remaining quantities to be dispensed.</p>
                                </div>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('loadRemainingDispenseData - Error:', error);
                    contentDiv.innerHTML = `
                        <div style="text-align: center; padding: 40px 20px;">
                            <div style="background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%); border: 1px solid #f5c6cb; border-radius: 8px; padding: 30px; display: inline-block;">
                                <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #721c24; margin-bottom: 15px;"></i>
                                <h4 style="margin: 0 0 10px 0; color: #721c24; font-weight: 600;">Error Loading Data</h4>
                                <p style="margin: 0; color: #721c24; font-size: 14px;">Unable to load remaining dispense data. Please try again.</p>
                                <p style="margin: 10px 0 0 0; color: #721c24; font-size: 12px;">Error: ${error.message}</p>
                            </div>
                        </div>
                    `;
                });
        }

        // Load patient credit balance
        function loadPatientCreditBalance() {
            if (!currentPatientId) return;
            
            fetch('pos_refund_system.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'get_patient_credit',
                    pid: currentPatientId,
                    csrf_token: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const balance = parseFloat(data.balance) || 0;
                    const creditElements = document.querySelectorAll('#patientCreditBalance');
                    creditElements.forEach(element => {
                        element.textContent = '$' + balance.toFixed(2);
                    });
                    
                    // Show/hide Transfer button based on credit balance
                    const transferButtonContainer = document.getElementById('creditTransferButtonContainer');
                    if (transferButtonContainer) {
                        transferButtonContainer.style.display = balance > 0 ? 'block' : 'none';
                    }
                }
            })
            .catch(error => {
                console.error('Error loading patient credit balance:', error);
                });
        }

        // Load dispense tracking data (refresh function)
        function loadDispenseTrackingData() {
            if (!currentPatientId) return;
            
            fetch(`pos_remaining_dispense.php?pid=${currentPatientId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.remaining_items) {
                        showRemainingDispenseContent(data.remaining_items);
                    }
                })
                .catch(error => {
                    console.error('Error refreshing dispense tracking data:', error);
                });
        }

        // Check for remaining dispenses and auto-show if any exist
        function checkForRemainingDispenses() {
            if (!currentPatientId) return;
            
            fetch(`pos_remaining_dispense.php?pid=${currentPatientId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.remaining_items && data.remaining_items.length > 0) {
                        // Don't auto-show dispense tracking - let user open it manually if needed
                        // Just show a notification that remaining dispense items are available
                        const itemText = data.remaining_items.length === 1 ? 'item' : 'items';
                        const mlItems = data.remaining_items.filter(item => item.is_ml_form);
                        const remainingSummary = mlItems.length > 0
                            ? `${formatQuantityValue(mlItems.reduce((sum, item) => sum + Number(item.remaining_quantity || 0), 0))} remaining quantities`
                            : 'remaining dispense quantities';
                        showNotification(`Found ${data.remaining_items.length} ${itemText} with ${remainingSummary}. Click "Dispense Tracking" to view them.`, 'info');
                    }
                })
                .catch(error => {
                    console.error('Error checking for remaining dispenses:', error);
                });
        }

        // Show remaining dispense content in section (grouped by product with lot subtables)
        function showRemainingDispenseContent(remainingItems) {
            console.log('showRemainingDispenseContent called with items:', remainingItems.length);
            
            const contentDiv = document.getElementById('dispense-content');
            if (!contentDiv) {
                console.log('showRemainingDispenseContent - No content div found');
                return;
            }

            // Group remaining items by product (drug_id)
            const productMap = {};
            for (const item of remainingItems) {
                console.log('Processing item:', {
                    receipt: item.receipt_number,
                    drug_id: item.drug_id,
                    total: item.total_quantity,
                    dispensed: item.dispensed_quantity,
                    administered: item.administered_quantity,
                    remaining: item.remaining_quantity
                });
                
                if (!productMap[item.drug_id]) {
                    productMap[item.drug_id] = {
                        drug_id: item.drug_id,
                        product_name: item.drug_name,
                        form: item.form || '',
                        is_ml_form: !!item.is_ml_form,
                        lots: [],
                        total_bought: 0,
                        total_dispensed: 0,
                        total_administered: 0,
                        total_remaining: 0
                    };
                }
                const group = productMap[item.drug_id];
                group.lots.push(item);
                group.total_bought += Number(item.total_quantity || 0);
                group.total_dispensed += Number(item.dispensed_quantity || 0);
                group.total_administered += Number(item.administered_quantity || 0);
                group.total_remaining += Number(item.remaining_quantity || 0);
            }

            console.log('Product groups created:', Object.keys(productMap).length);
            Object.values(productMap).forEach(group => {
                console.log('Group:', {
                    name: group.product_name,
                    total_bought: group.total_bought,
                    total_remaining: group.total_remaining,
                    lots: group.lots.length
                });
            });

            // Sort lots by created_date desc within each product for consistent display (newest first)
            Object.values(productMap).forEach(group => {
                group.lots.sort((a, b) => {
                    const dateA = new Date(a.created_date || a.last_updated);
                    const dateB = new Date(b.created_date || b.last_updated);
                    return dateB - dateA; // Newest first (descending order)
                });
            });

            let html = `
                <!-- Search Bar for Dispense Tracking -->
                <div style="margin-bottom: 20px;">
                    <div style="background: white; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-search" style="color: #6c757d; font-size: 16px;"></i>
                            <input type="text" id="dispense-search-input" placeholder="Search products or lots in dispense tracking..." 
                                   style="flex: 1; padding: 10px; border: 1px solid #ced4da; border-radius: 6px; font-size: 14px; outline: none; transition: border-color 0.3s ease;"
                                   onkeyup="filterDispenseItems(this.value)">
                            <button onclick="clearDispenseSearch()" style="background: #6c757d; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; transition: all 0.3s ease;">
                                <i class="fas fa-times"></i> Clear
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Dispense Tracking Table (grouped by product) -->
                <div class="dispense-table-container" style="background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 16px;">
                        <thead>
                            <tr style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);">
                                <th style="padding: 18px; text-align: left; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 15px; width: 40%">
                                    <i class="fas fa-pills"></i> Product
                                </th>
                                <th style="padding: 18px; text-align: center; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 15px;">
                                    <i class="fas fa-shopping-cart"></i> Total Bought
                                </th>
                                <th style="padding: 18px; text-align: center; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 15px;">
                            <i class="fas fa-check-circle"></i> Total Dispensed
                                </th>
                                <th style="padding: 18px; text-align: center; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 15px;">
                            <i class="fas fa-syringe"></i> Total Administered
                                </th>
                                <th style="padding: 18px; text-align: center; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 15px;">
                            <i class="fas fa-exclamation-triangle"></i> Dispense Remaining
                                </th>
                                <th style="padding: 18px; text-align: center; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 15px;">
                            <i class="fas fa-exchange-alt"></i> Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>`;

            Object.values(productMap).forEach(group => {
                const isCompleted = group.total_remaining === 0;
                const groupStyle = isCompleted ? 'background-color: #f8f9fa; opacity: 0.9;' : '';
                const statusIcon = isCompleted ? '<i class="fas fa-check-circle" style="color: #28a745; margin-right: 5px;"></i>' : '';
                const safeProductKey = `product-${group.drug_id}`;
                
                html += `
                    <tr class="product-group" data-product="${(group.product_name || '').toLowerCase()}" data-drug-id="${group.drug_id}" style="border-bottom: 1px solid #e9ecef; ${groupStyle}">
                        <td style="padding: 18px; border-right: 1px solid #e9ecef;">
                            <div style="font-weight: 600; color: #495057; font-size: 16px; cursor: pointer; display:flex; align-items:center;" onclick="toggleProductUpdates(${group.drug_id}, '${safeProductKey}-updates', this)">
                                <i class="fas fa-chevron-right" style="margin-right: 8px; transition: transform 0.3s ease;"></i>
                                ${statusIcon}${group.product_name}
                                ${isCompleted ? '<span style="color: #28a745; font-size: 14px; margin-left: 5px;">(Completed)</span>' : ''}
                            </div>
                        </td>
                        <td style="padding: 18px; text-align: center; font-weight: 600; color: #495057;">${formatQuantityWithUnit(group.total_bought, group.form)}</td>
                        <td style="padding: 18px; text-align: center; font-weight: 600; color: #28a745;">${formatQuantityWithUnit(group.total_dispensed, group.form)}</td>
                        <td style="padding: 18px; text-align: center; font-weight: 600; color: #17a2b8;">${formatQuantityWithUnit(group.total_administered, group.form)}</td>
                        <td style="padding: 18px; text-align: center; font-weight: 600; color: ${isCompleted ? '#6c757d' : '#dc3545'};">${formatQuantityWithUnit(group.total_remaining, group.form)}</td>
                        <td style="padding: 18px; text-align: center;">
                            ${group.total_remaining > 0 ? `
                                <div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">
                                <button onclick="toggleTransferSection(${group.drug_id}, '${group.product_name}', ${group.total_remaining}, '${safeProductKey}')" 
                                        id="transfer-btn-${safeProductKey}"
                                        style="background: #17a2b8; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; transition: all 0.3s ease;">
                                    <i class="fas fa-exchange-alt"></i> Transfer
                                </button>
                                    <button onclick="toggleSwitchMedicineSection(${group.drug_id}, '${group.product_name}', ${group.total_remaining}, '${safeProductKey}')" 
                                            id="switch-medicine-btn-${safeProductKey}"
                                            style="background: #28a745; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; transition: all 0.3s ease;">
                                        <i class="fas fa-pills"></i> Switch
                                    </button>
                                </div>
                            ` : '<span style="color: #6c757d; font-size: 12px;">No remaining</span>'}
                        </td>
                    </tr>
                    <tr id="${safeProductKey}-transfer" class="transfer-section" style="display: none; background: #fbfbfb;">
                        <td colspan="6" style="padding: 0;">
                            <div style="padding: 15px 20px 20px 20px; background: #f8f9fa; border-radius: 6px; margin: 10px 18px;">
                                <div style="overflow-x: auto; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                    <table style="width: 100%; border-collapse: collapse; background: white; min-width: 800px;">
                                        <thead>
                                            <tr style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);">
                                                <th style="text-align:center; padding: 10px 6px; color:#495057; font-weight: 600; font-size: 14px; border-bottom: 2px solid #dee2e6; width: 25%;">
                                                    <i class="fas fa-pills"></i> Product
                                                </th>
                                                <th style="text-align:center; padding: 10px 6px; color:#495057; font-weight: 600; font-size: 14px; border-bottom: 2px solid #dee2e6; width: 20%;">
                                                    <i class="fas fa-calculator"></i> Amount
                                                </th>
                                                <th style="text-align:center; padding: 10px 6px; color:#495057; font-weight: 600; font-size: 14px; border-bottom: 2px solid #dee2e6; width: 35%;">
                                                    <i class="fas fa-user-plus"></i> Target Patient
                                                </th>
                                                <th style="text-align:center; padding: 10px 6px; color:#495057; font-weight: 600; font-size: 14px; border-bottom: 2px solid #dee2e6; width: 20%;">
                                                    <i class="fas fa-cogs"></i> Action
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr style="border-bottom: 1px solid #f1f1f1;">
                                                <td style="text-align:center; padding: 10px 6px; color:#495057; font-size: 14px;">
                                                    <div style="font-weight: 600; margin-bottom: 3px;">${group.product_name}</div>
                                                    <div style="color: #6c757d; font-size: 12px;">
                                                        Available: <span style="color: #dc3545; font-weight: 600;">${formatQuantityWithUnit(group.total_remaining, group.form)}</span>
                            </div>
                        </td>
                                                <td style="text-align:center; padding: 10px 6px;">
                                                    <div style="display: flex; align-items: center; justify-content: center; gap: 5px;">
                                                        <input type="number" id="transfer-amount-${safeProductKey}" min="1" max="${group.total_remaining}" value="1" 
                                                               style="width: 50px; padding: 6px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; text-align: center; outline: none;"
                                                               onchange="validateTransferAmount('${safeProductKey}', ${group.total_remaining})"
                                                               oninput="this.value = this.value.replace(/[^0-9]/g, ''); if(parseInt(this.value) > ${group.total_remaining}) this.value = ${group.total_remaining}; if(parseInt(this.value) < 1) this.value = 1;">
                                                        <span style="color: #6c757d; font-size: 12px;">of ${group.total_remaining}</span>
                                                    </div>
                                                    <div id="transfer-amount-error-${safeProductKey}" style="color: #dc3545; font-size: 11px; margin-top: 3px; display: none;"></div>
                        </td>
                                                <td style="text-align:center; padding: 10px 6px;">
                                                    <div id="transfer-patient-search-section-${safeProductKey}">
                                                        <div style="position: relative;">
                                                            <input type="text" id="transfer-patient-search-${safeProductKey}" placeholder="Search by name, ID, or phone..." 
                                                                   style="width: 100%; padding: 6px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; outline: none;"
                                                                   onkeyup="searchTransferPatients(this.value, '${safeProductKey}')">
                                                            <div id="transfer-patient-results-${safeProductKey}" style="position: fixed; background: white; border: 1px solid #ced4da; border-radius: 4px; max-height: 200px; overflow-y: auto; z-index: 999999; display: none; box-shadow: 0 4px 12px rgba(0,0,0,0.15); width: 100%; min-width: 300px; max-width: 500px; scrollbar-width: thin; scrollbar-color: #c1c1c1 #f1f1f1;"></div>
                                                        </div>
                                                    </div>
                                                    <div id="transfer-patient-display-${safeProductKey}" style="display: none;">
                                                        <div style="background: #f8f9fa; padding: 10px 15px; border-radius: 8px; border-left: 4px solid #007bff; position: relative;">
                                                            <div style="display: flex; gap: 25px; font-size: 13px; color: #555; align-items: center; flex-wrap: wrap;">
                                                                <div>
                                                                    <strong style="font-size: 14px; color: #333;" id="transfer-patient-name-${safeProductKey}"></strong>
                                                                </div>
                                                                <div>
                                                                    <strong style="font-size: 13px;">Mobile:</strong> <span id="transfer-patient-mobile-${safeProductKey}"></span>
                                                                </div>
                                                                <div>
                                                                    <strong style="font-size: 13px;">DOB:</strong> <span id="transfer-patient-dob-${safeProductKey}"></span>
                                                                </div>
                                                                <div>
                                                                    <strong style="font-size: 13px;">Gender:</strong> <span id="transfer-patient-gender-${safeProductKey}"></span>
                                                                </div>
                                                            </div>
                                                            <button onclick="editTransferPatient('${safeProductKey}')" style="position: absolute; top: -6px; right: -6px; background: #007bff; color: white; border: none; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.2);" title="Change Patient">
                                                                <i class="fas fa-pencil-alt" style="font-size: 10px;"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                        </td>
                                                <td style="text-align:center; padding: 10px 6px;">
                                                    <button onclick="processTransferInline(${group.drug_id}, '${safeProductKey}', ${group.total_remaining})" 
                                                            id="process-transfer-btn-${safeProductKey}" disabled
                                                            style="background: #17a2b8; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600; transition: all 0.3s ease;">
                                                        <i class="fas fa-exchange-alt"></i> Process
                                                    </button>
                        </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr id="${safeProductKey}-switch-medicine" class="switch-medicine-section" style="display: none; background: #fbfbfb;">
                        <td colspan="6" style="padding: 0;">
                            <div style="padding: 15px 20px 20px 20px; background: #f8f9fa; border-radius: 6px; margin: 10px 18px;">
                                <div style="overflow-x: auto; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                    <table style="width: 100%; border-collapse: collapse; background: white; min-width: 800px;">
                                        <thead>
                                            <tr style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);">
                                                <th style="text-align:center; padding: 10px 6px; color:#495057; font-weight: 600; font-size: 14px; border-bottom: 2px solid #dee2e6; width: 25%;">
                                                    <i class="fas fa-pills"></i> Current Medicine
                                                </th>
                                                <th style="text-align:center; padding: 10px 6px; color:#495057; font-weight: 600; font-size: 14px; border-bottom: 2px solid #dee2e6; width: 20%;">
                                                    <i class="fas fa-calculator"></i> Quantity
                                                </th>
                                                <th style="text-align:center; padding: 10px 6px; color:#495057; font-weight: 600; font-size: 14px; border-bottom: 2px solid #dee2e6; width: 35%;">
                                                    <i class="fas fa-search"></i> New Medicine
                                                </th>
                                                <th style="text-align:center; padding: 10px 6px; color:#495057; font-weight: 600; font-size: 14px; border-bottom: 2px solid #dee2e6; width: 20%;">
                                                    <i class="fas fa-cogs"></i> Action
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr style="border-bottom: 1px solid #f1f1f1;">
                                                <td style="text-align:center; padding: 10px 6px; color:#495057; font-size: 14px;">
                                                    <div style="font-weight: 600; margin-bottom: 3px;">${group.product_name}</div>
                                                    <div style="color: #6c757d; font-size: 12px;">
                                                        Available: <span style="color: #dc3545; font-weight: 600;">${formatQuantityWithUnit(group.total_remaining, group.form)}</span>
                                                    </div>
                                                </td>
                                                <td style="text-align:center; padding: 10px 6px;">
                                                    <div style="display: flex; align-items: center; justify-content: center; gap: 5px;">
                                                        <input type="number" id="switch-quantity-${safeProductKey}" min="1" max="${group.total_remaining}" value="${group.total_remaining}" 
                                                               style="width: 50px; padding: 6px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; text-align: center; outline: none;"
                                                               onchange="validateSwitchQuantity('${safeProductKey}', ${group.total_remaining})"
                                                               oninput="this.value = this.value.replace(/[^0-9]/g, ''); if(parseInt(this.value) > ${group.total_remaining}) this.value = ${group.total_remaining}; if(parseInt(this.value) < 1) this.value = 1;">
                                                        <span style="color: #6c757d; font-size: 12px;">of ${group.total_remaining}</span>
                                                    </div>
                                                    <div id="switch-quantity-error-${safeProductKey}" style="color: #dc3545; font-size: 11px; margin-top: 3px; display: none;"></div>
                                                </td>
                                                <td style="text-align:center; padding: 10px 6px;">
                                                    <div id="switch-medicine-search-section-${safeProductKey}">
                                                        <div style="position: relative;">
                                                            <input type="text" id="switch-medicine-search-${safeProductKey}" placeholder="Search for new medicine..." 
                                                                   style="width: 100%; padding: 8px 12px; border: 1px solid #ced4da; border-radius: 6px; font-size: 14px; outline: none;"
                                                                   onkeyup="searchSwitchMedicines(this.value, '${safeProductKey}')"
                                                                   onfocus="if(this.value.trim().length > 0) searchSwitchMedicines(this.value, '${safeProductKey}')">
                                                            <div id="switch-medicine-results-${safeProductKey}" class="search-results" style="position: fixed; background: white; border: 2px solid #007bff; border-radius: 8px; max-height: 400px; overflow-y: auto; z-index: 999999; display: none; box-shadow: 0 8px 24px rgba(0,0,0,0.2); min-width: 600px; max-width: 800px;"></div>
                                                        </div>
                                                    </div>
                                                    <div id="switch-medicine-display-${safeProductKey}" style="display: none;">
                                                        <div style="background: #f8f9fa; padding: 8px 12px; border-radius: 6px; border-left: 4px solid #28a745; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                                                            <div style="display: flex; gap: 10px; font-size: 13px; color: #555; align-items: center; flex: 1; flex-wrap: wrap;">
                                                                <span style="font-size: 14px; font-weight: 600; color: #333;" id="switch-medicine-name-${safeProductKey}"></span>
                                                                <span id="switch-medicine-form-${safeProductKey}" style="color: #6c757d; font-weight: 500;"></span>
                                                                <span id="switch-medicine-unit-${safeProductKey}" style="color: #6c757d; font-weight: 500;"></span>
                                                            </div>
                                                            <button onclick="editSwitchMedicine('${safeProductKey}')" style="background: #28a745; color: white; border: none; border-radius: 4px; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.2); flex-shrink: 0;" title="Change Medicine">
                                                                <i class="fas fa-pencil-alt" style="font-size: 11px;"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td style="text-align:center; padding: 10px 6px;">
                                                    <button onclick="processSwitchMedicine(${group.drug_id}, '${safeProductKey}', ${group.total_remaining})" 
                                                            id="process-switch-btn-${safeProductKey}" disabled
                                                            style="background: #28a745; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600; transition: all 0.3s ease;">
                                                        <i class="fas fa-pills"></i> Switch
                                                    </button>
                        </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr id="${safeProductKey}-updates" class="product-updates" style="display: none; background: #fbfbfb;">
                        <td colspan="6" style="padding: 0;">
                            <div style="padding: 15px 20px 20px 20px; background: #f8f9fa; border-radius: 6px; margin: 10px 18px;">
                                <div style="overflow-x: auto; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                    <table style="width: 100%; border-collapse: collapse; background: white; min-width: 800px;">
                                        <thead>
                                            <tr style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);">
                                                <th style="text-align:center; padding: 12px 8px; color:#495057; font-weight: 600; font-size: 15px; border-bottom: 2px solid #dee2e6; min-width: 120px;">Date</th>
                                                <th style="text-align:center; padding: 12px 8px; color:#495057; font-weight: 600; font-size: 15px; border-bottom: 2px solid #dee2e6; min-width: 100px;">Lot</th>
                                                <th style="text-align:center; padding: 12px 8px; color:#495057; font-weight: 600; font-size: 15px; border-bottom: 2px solid #dee2e6; min-width: 80px;">Total Bought</th>
                                                <th style="text-align:center; padding: 12px 8px; color:#495057; font-weight: 600; font-size: 15px; border-bottom: 2px solid #dee2e6; min-width: 80px;">Dispensed</th>
                                                <th style="text-align:center; padding: 12px 8px; color:#495057; font-weight: 600; font-size: 15px; border-bottom: 2px solid #dee2e6; min-width: 80px;">Administered</th>
                                                <th style="text-align:center; padding: 12px 8px; color:#495057; font-weight: 600; font-size: 15px; border-bottom: 2px solid #dee2e6; min-width: 90px;">Remaining</th>
                                                <th style="text-align:center; padding: 12px 8px; color:#495057; font-weight: 600; font-size: 15px; border-bottom: 2px solid #dee2e6; min-width: 100px;">Receipt #</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${group.lots.map(lot => {
                                                const date = new Date(lot.created_date).toLocaleString();
                                                const isCompleted = lot.remaining_quantity === 0;
                                                const rowStyle = isCompleted ? 'background-color: #f8f9fa; opacity: 0.8;' : '';
                                                return `<tr style="border-bottom: 1px solid #f1f1f1; ${rowStyle}">
                                                    <td style="text-align:center; padding: 12px 8px; color:#495057; font-size: 14px;">${date}</td>
                                                    <td style="text-align:center; padding: 12px 8px;"><span style="display:inline-block; min-width:60px; text-align:center; background:#eef5ff; border:1px solid #cfe2ff; border-radius:4px; padding:4px 8px; font-size: 13px; font-weight: 500;">${lot.lot_number}</span></td>
                                                    <td style="text-align:center; padding: 12px 8px; color:#495057; font-size: 14px; font-weight: 600;">${formatQuantityWithUnit(lot.total_quantity, lot.form || '')}</td>
                                                    <td style="text-align:center; padding: 12px 8px; color:#28a745; font-size: 14px; font-weight: 600;">${formatQuantityWithUnit(lot.dispensed_quantity, lot.form || '')}</td>
                                                    <td style="text-align:center; padding: 12px 8px; color:#17a2b8; font-size: 14px; font-weight: 600;">${formatQuantityWithUnit(lot.administered_quantity || 0, lot.form || '')}</td>
                                                    <td style="text-align:center; padding: 12px 8px; color:${isCompleted ? '#6c757d' : '#dc3545'}; font-size: 14px; font-weight: 600;">${formatQuantityWithUnit(lot.remaining_quantity, lot.form || '')}</td>
                                                    <td style="text-align:center; padding: 12px 8px; color:#495057; font-size: 13px; font-family: monospace;">${lot.receipt_number || 'N/A'}</td>
                                                </tr>`;
                                            }).join('')}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </td>
                    </tr>`;
            });
            html += `
                        </tbody>
                    </table>
                </div>
                
                <!-- Transfer History Button at Bottom -->
                <div style="margin-top: 20px; text-align: center;">
                    <button onclick="toggleTransferHistory()" id="transferHistoryBtn" style="background: #17a2b8; color: white; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <i class="fas fa-exchange-alt"></i> View Transfer History
                    </button>
                </div>`;

            contentDiv.innerHTML = html;
        }



        // Add remaining item to cart (simplified - no replacement options)
        function addRemainingToCart(index, originalDrugId, originalLotNumber, remainingQty, drugName) {
            const dispenseQty = parseInt(document.getElementById(`dispense_qty_${index}`).value) || 0;
            
            if (dispenseQty > remainingQty) {
                alert('Dispense quantity cannot exceed remaining quantity');
                return;
            }
            
            // Don't add to cart if dispense quantity is 0
            if (dispenseQty === 0) {
                alert('Please enter a dispense quantity greater than 0');
                return;
            }
            
            // Add original remaining item to cart
            const itemId = `drug_${originalDrugId}_lot_${originalLotNumber}`;
            const existingIndex = cart.findIndex(item => item.id === itemId);
            
            if (existingIndex !== -1) {
                cart[existingIndex].quantity = dispenseQty;
                cart[existingIndex].dispense_quantity = dispenseQty;
            } else {
                const newItem = {
                    id: itemId,
                    name: drugName,
                    display_name: `${drugName} (Remaining Dispense)`,
                    price: 0,
                    original_price: 0,
                    lot: originalLotNumber,
                    qoh: dispenseQty,
                    quantity: dispenseQty,
                    dispense_quantity: dispenseQty,
                    remaining_quantity: dispenseQty,
                    icon: 'drug',
                    expiration: null,
                    has_discount: false,
                    discount_info: null,
                    is_remaining_dispense: true,
                    drug_id: originalDrugId
                };
                
                cart.push(newItem);
            }
            
            updateOrderSummary();
            saveCartToStorage();
            
            // Show success message
            alert(`Added ${dispenseQty} units to cart for dispensing.`);
        }

        // Filter dispense items based on search term (works with product grouping)
        function filterDispenseItems(searchTerm) {
            const searchLower = (searchTerm || '').toLowerCase();
            const productGroups = document.querySelectorAll('.product-group');

            productGroups.forEach(groupRow => {
                const productName = groupRow.getAttribute('data-product') || '';
                const productId = groupRow.getAttribute('data-drug-id');
                const lotsContainer = document.getElementById(`product-${productId}-lots`);
                let matches = false;

                // Match on product name
                if (productName.includes(searchLower)) {
                    matches = true;
                }

                // Match on any lot row within this product
                if (!matches && lotsContainer) {
                    const lotRows = lotsContainer.querySelectorAll('.lot-row');
                    for (const lotRow of lotRows) {
                        const lotText = lotRow.getAttribute('data-lot') || '';
                        if (lotText.includes(searchLower)) {
                            matches = true;
                            break;
                        }
                    }
                }

                groupRow.style.display = matches ? '' : 'none';
                if (lotsContainer) {
                    lotsContainer.style.display = matches ? lotsContainer.style.display : 'none';
                }
            });
        }

        // Clear dispense search
        function clearDispenseSearch() {
            const searchInput = document.getElementById('dispense-search-input');
            if (searchInput) {
                searchInput.value = '';
                filterDispenseItems('');
            }
        }

        // Proceed to POS
        function proceedToPOS() {
            hideDispenseTracking();
            // The POS section is already visible, just scroll to it
            document.querySelector('.search-section').scrollIntoView({ behavior: 'smooth' });
        }



        // Check Hoover facility quantity validation and update button state
        function validateHooverQuantities() {
            if (!isHooverFacility) return true;
            
            const invalidItems = [];
            cart.forEach((item) => {
                // Skip consultation items
                if (item.id && item.id.startsWith('consultation_')) {
                    return;
                }
                
                // Only validate medical products
                if (item.drug_id || item.lot) {
                    // Ensure all values are numbers (handle string conversion)
                    const qty = parseFloat(item.quantity) || 0;
                    const marketplaceDispense = parseFloat(item.marketplace_dispense_quantity) || 0;
                    const administer = parseFloat(item.administer_quantity) || 0;
                    const totalRemainingQuantity = parseFloat(item.total_remaining_quantity) || 0;
                    const totalAvailable = qty + totalRemainingQuantity;
                    const totalRequested = marketplaceDispense + administer;
                    
                    // Validate: Marketplace Dispense + Administer <= QTY + Remaining Dispense
                    // Use a small epsilon to handle floating point precision issues
                    const epsilon = 0.0001;
                    
                    // Debug logging
                    console.log(`Hoover Validation - Item: ${item.name || item.id}`, {
                        qty,
                        marketplaceDispense,
                        administer,
                        totalRemainingQuantity,
                        totalAvailable,
                        totalRequested,
                        comparison: totalRequested > totalAvailable + epsilon,
                        isValid: totalRequested <= totalAvailable + epsilon
                    });
                    
                    if (totalRequested > totalAvailable + epsilon) {
                        invalidItems.push({
                            ...item,
                            error: `Marketplace Dispense (${marketplaceDispense}) + Administer (${administer}) = ${totalRequested} exceeds available quantity (QTY: ${qty} + Remaining Dispense: ${totalRemainingQuantity} = ${totalAvailable})`
                        });
                    }
                }
            });
            
            // Update checkout button state (check both possible button IDs)
            const checkoutBtn = document.getElementById('checkout-btn') || document.getElementById('process-payment-btn');
            if (checkoutBtn) {
                if (invalidItems.length > 0) {
                    checkoutBtn.disabled = true;
                    checkoutBtn.style.opacity = '0.6';
                    checkoutBtn.style.cursor = 'not-allowed';
                    checkoutBtn.title = 'Please ensure Marketplace Dispense + Administer ≤ QTY + Remaining Dispense for all items';
                    console.log('Payment blocked - Invalid items:', invalidItems);
                } else {
                    checkoutBtn.disabled = false;
                    checkoutBtn.style.opacity = '1';
                    checkoutBtn.style.cursor = 'pointer';
                    checkoutBtn.title = 'Proceed to payment';
                    console.log('Payment allowed - All items valid');
                }
            } else {
                console.warn('Checkout button not found');
            }
            
            return invalidItems.length === 0;
        }

        // Update order summary display
        function updateOrderSummary() {
            // First, update all cart items with current discount information (skip if in override mode)
            if (overrideMode) {
                // In override mode, skip discount updates to preserve manual price changes
                renderOrderSummary();
            } else {
                updateCartDiscounts().then(() => {
                    renderOrderSummary();
                });
            }
            
            // Validate Hoover quantities and update button state
            if (isHooverFacility) {
                validateHooverQuantities();
            }
            
            // Defective medicine reporting is now handled at inventory level only
            // No need to show/hide defective button in cart
        }

        // Separate function to render the order summary
        function renderOrderSummary() {
            const orderItems = document.getElementById('order-items');
            const orderTotal = document.getElementById('order-total');
            
            let html = '';
            let subtotal = 0;
            let totalDiscount = 0;
            let discountedSubtotal = 0;
            let taxableSubtotal = 0;
            let taxTotal = 0;
            let taxBreakdown = {};
            let taxableProductNames = [];
                
                cart.forEach((item, index) => {
                    // Check if this is a remaining dispense item
                    const isRemainingDispense = item.is_remaining_dispense || item.is_replacement;
                    
                    // Check if quantity is 0 but dispense quantity > 0 (dispensing only)
                    const isDispensingOnly = item.quantity === 0 && (item.dispense_quantity || 0) > 0;
                    
                    // For Hoover facility: Check if item has remaining dispense that should be free
                    const hasRemainingDispense = item.has_remaining_dispense || false;
                    const totalRemainingQuantity = item.total_remaining_quantity || 0;
                    const marketplaceDispenseQty = item.marketplace_dispense_quantity || item.dispense_quantity || 0;
                    const administerQty = item.administer_quantity || 0;
                    const remainingDoseUpgradeCharge = getRemainingDoseUpgradeCharge(item);
                    
                    // Calculate pricing based on conditions
                    let originalItemTotal, discountedItemTotal, itemDiscount;
                    
                    if (remainingDoseUpgradeCharge > 0) {
                        // Tirzepatide remaining dispense may be administered at a higher dose; charge only the dose difference.
                        originalItemTotal = remainingDoseUpgradeCharge;
                        discountedItemTotal = remainingDoseUpgradeCharge;
                        itemDiscount = 0;
                    } else if (isRemainingDispense || isDispensingOnly) {
                        // For remaining dispense items or dispensing-only items, set prices to 0
                        originalItemTotal = 0;
                        discountedItemTotal = 0;
                        itemDiscount = 0;
                    } else if (isHooverFacility && hasRemainingDispense && totalRemainingQuantity > 0 && item.remaining_dispense_items) {
                        // For Hoover facility with remaining dispense: 
                        // Remaining dispense quantities are FREE (already paid for in previous transaction)
                        // Calculate how much marketplace dispense/administer is coming from remaining dispense (free)
                        let usedFromRemaining = 0;
                        const totalRequested = marketplaceDispenseQty + administerQty;
                        
                        // Sum up remaining quantities that will be used (these are free)
                        if (item.remaining_dispense_items && Array.isArray(item.remaining_dispense_items)) {
                            item.remaining_dispense_items.forEach(remainingItem => {
                                const remainingQty = remainingItem.remaining_quantity || 0;
                                const qtyToUse = Math.min(remainingQty, totalRequested - usedFromRemaining);
                                usedFromRemaining += qtyToUse;
                            });
                        }
                        
                        // Calculate how much of the NEW purchase (QTY) is being used for marketplace dispense/administer
                        // vs how much is remaining (which will become new remaining dispense)
                        const usedFromNewPurchase = Math.min(item.quantity, totalRequested - usedFromRemaining);
                        const newRemainingQty = Math.max(0, item.quantity - usedFromNewPurchase);
                        
                        // Charge for the FULL new purchase quantity (QTY)
                        // The remaining dispense portions used are free, but the new purchase is still charged
                        originalItemTotal = item.original_price * item.quantity;
                        
                        // Check for quantity discount (Buy X Get 1 Free)
                        // Check both inventory_discount_info and discount_info (from search results)
                        const discountInfo = item.inventory_discount_info || item.discount_info;
                        if (discountInfo && discountInfo.type === 'quantity' && discountInfo.quantity && discountInfo.quantity > 1) {
                            // Calculate quantity discount total
                            const discountQty = discountInfo.quantity;
                            const groupSize = discountQty + 1; // e.g., if discount_quantity = 4, group size is 5
                            const fullGroups = Math.floor(item.quantity / groupSize);
                            const remainingItems = item.quantity % groupSize;
                            discountedItemTotal = (fullGroups * discountQty * item.original_price) + (remainingItems * item.original_price);
                            console.log(`Quantity discount applied (Hoover): Item=${item.name}, Qty=${item.quantity}, DiscountQty=${discountQty}, FullGroups=${fullGroups}, Remaining=${remainingItems}, OriginalTotal=$${originalItemTotal.toFixed(2)}, DiscountedTotal=$${discountedItemTotal.toFixed(2)}`);
                        } else {
                            discountedItemTotal = item.price * item.quantity;
                            if (discountInfo && discountInfo.type === 'quantity') {
                                console.log(`Quantity discount NOT applied: discountInfo=`, discountInfo, `item=`, item);
                            }
                        }
                        itemDiscount = originalItemTotal - discountedItemTotal;
                    } else {
                        // Normal pricing for items with quantity > 0
                        originalItemTotal = item.original_price * item.quantity;
                        
                        // Check for quantity discount (Buy X Get 1 Free)
                        // Check both inventory_discount_info and discount_info (from search results)
                        const discountInfo = item.inventory_discount_info || item.discount_info;
                        if (discountInfo && discountInfo.type === 'quantity' && discountInfo.quantity && discountInfo.quantity > 1) {
                            // Calculate quantity discount total
                            const discountQty = discountInfo.quantity;
                            const groupSize = discountQty + 1; // e.g., if discount_quantity = 4, group size is 5
                            const fullGroups = Math.floor(item.quantity / groupSize);
                            const remainingItems = item.quantity % groupSize;
                            discountedItemTotal = (fullGroups * discountQty * item.original_price) + (remainingItems * item.original_price);
                            console.log(`Quantity discount applied: Item=${item.name}, Qty=${item.quantity}, DiscountQty=${discountQty}, FullGroups=${fullGroups}, Remaining=${remainingItems}, OriginalTotal=$${originalItemTotal.toFixed(2)}, DiscountedTotal=$${discountedItemTotal.toFixed(2)}`);
                        } else {
                            discountedItemTotal = item.price * item.quantity;
                            if (discountInfo && discountInfo.type === 'quantity') {
                                console.log(`Quantity discount NOT applied: discountInfo=`, discountInfo, `item=`, item);
                            }
	                        }
	                        itemDiscount = originalItemTotal - discountedItemTotal;
	                    }

	                    if (isItemPrepaid(item)) {
	                        discountedItemTotal = 0;
	                        itemDiscount = originalItemTotal;
	                    }
	                    
	                    subtotal += originalItemTotal;
                    totalDiscount += itemDiscount;
                    discountedSubtotal += discountedItemTotal;
                    if (isTaxableItem(item)) {
                        taxableSubtotal += discountedItemTotal;
                        if (discountedItemTotal > 0) {
                            taxableProductNames.push(item.display_name || item.name || 'Unknown Product');
                        }
                    }
                
                    // Show QOH information if available
                    const qohInfo = item.qoh > 0 ? ` (QOH: ${formatQuantityValue(item.qoh)} ${getQohUnit(item)})` : '';
                    const maxQty = item.qoh > 0 ? item.qoh : 999;
                    const actionUnitBadge = '';
                
                    // Service items (no lot/qty/dispense/administer like Consultation)
                    const isServiceItem =
                        item.id.startsWith('consultation_') ||
                        item.id.startsWith('bloodwork_');
                    
                    // Prepare discount display (don't show for remaining dispense items)
                    let discountDisplay = '';
                    if (!isRemainingDispense && item.has_discount && item.discount_info) {
                        let discountTypeText = '';
                        if (item.discount_info.type === 'percentage') {
                            discountTypeText = `${item.discount_info.percent}% OFF`;
                        } else if (item.discount_info.type === 'fixed') {
                            discountTypeText = `$${item.discount_info.amount.toFixed(2)} OFF`;
                        } else if (item.discount_info.type === 'quantity' && item.discount_info.quantity > 1) {
                            discountTypeText = `Buy ${item.discount_info.quantity} Get 1 Free`;
                        }
                        const discountDescription = item.discount_info.description ? ` - ${item.discount_info.description}` : '';
                        discountDisplay = `<br><small style="color: #28a745; font-weight: 500;"><i class="fas fa-tag"></i> ${discountTypeText}${discountDescription}</small>`;
                    }
                    
                    // Also check inventory discount for quantity type
                    if (!isRemainingDispense && item.inventory_has_discount && item.inventory_discount_info && 
                        item.inventory_discount_info.type === 'quantity' && item.inventory_discount_info.quantity > 1) {
                        const discountTypeText = `Buy ${item.inventory_discount_info.quantity} Get 1 Free`;
                        const discountDescription = item.inventory_discount_info.description ? ` - ${item.inventory_discount_info.description}` : '';
                        discountDisplay = `<br><small style="color: #28a745; font-weight: 500;"><i class="fas fa-tag"></i> ${discountTypeText}${discountDescription}</small>`;
                    }
                    
                    // Add Unit/Price column when in override mode (not for remaining dispense items)
                    const unitPriceColumn = (overrideMode && !isRemainingDispense) ? 
                        `<td class="unit-price">
                            <div style="position: relative; display: inline-block;">
                                <span style="position: absolute; left: 8px; top: 50%; transform: translateY(-50%); color: #666; font-size: 12px; font-weight: bold;">$</span>
                                <input type="number" 
                                       class="unit-price-input" 
                                       data-index="${index}" 
                                       value="${item.original_price.toFixed(2)}" 
                                       step="0.01" 
                                       min="0" 
                                       readonly
                                       aria-readonly="true"
                                       title="Unit price is locked while override mode is enabled"
                                       style="width: 120px; padding: 4px 4px 4px 20px; border: 1px solid #ddd; border-radius: 4px; text-align: center; background: #f3f4f6; color: #6c757d; cursor: not-allowed;"
                                       onfocus="this.blur()">
                            </div>
                        </td>` : '';
                    
                    // Defective medicine reporting is now handled at inventory level only
                    // Managers should use the inventory system to report defective products by lot number
                    const defectiveButton = '';
                    
                    // Add Discount column when in override mode (not for remaining dispense items)
                    const discountColumn = (overrideMode && !isRemainingDispense) ? 
                        `<td class="discount">
                            <div style="position: relative; display: inline-block;">
                                <span style="position: absolute; left: 8px; top: 50%; transform: translateY(-50%); color: #666; font-size: 12px; font-weight: bold;">${item.inventory_has_discount && item.inventory_discount_info && item.inventory_discount_info.type === 'fixed' ? '$' : '%'}</span>
                                <input type="number" 
                                       class="discount-input" 
                                       data-index="${index}" 
                                       value="${item.has_discount && item.discount_info ? (item.discount_info.type === 'percentage' ? item.discount_info.percent : item.discount_info.amount) : (item.inventory_has_discount && item.inventory_discount_info ? (item.inventory_discount_info.type === 'percentage' ? item.inventory_discount_info.percent : item.inventory_discount_info.amount) : '')}" 
                                       placeholder="${item.inventory_has_discount && item.inventory_discount_info && item.inventory_discount_info.type === 'fixed' ? 'Amount' : '%'}" 
                                       step="0.01" 
                                       min="0" 
                                       max="${item.inventory_has_discount && item.inventory_discount_info && item.inventory_discount_info.type === 'fixed' ? '1000' : '100'}"
                                       style="width: 80px; padding: 4px 4px 4px 20px; border: 1px solid #ddd; border-radius: 4px; text-align: center;"
                                       onchange="updateDiscount(${index}, this.value, '${item.inventory_has_discount && item.inventory_discount_info ? item.inventory_discount_info.type : 'percentage'}')"
                                       onfocus="this.select()">
                            </div>
                        </td>` : '';
                
                html += `
                    <tr>
                        <td>
                            <div class="product-info">
                                <div class="product-icon ${item.icon}">
                                    <i class="fas fa-${
  item.icon === 'drug' ? 'pills' :
  item.icon === 'consultation' ? 'stethoscope' :
  item.icon === 'bloodwork' ? 'vial' :
  item.icon === 'injection' ? 'syringe' :
  'glass-whiskey'
}"></i>
                                </div>
                                <div>
                                    <span class="product-name">${item.display_name}</span>
                                    ${prepayModeEnabled ? `<label style="display:flex; align-items:center; gap:6px; margin-top:6px; color:#495057; font-size:13px; font-weight:600;"><input type="checkbox" ${item.prepay_selected ? 'checked' : ''} onchange="toggleItemPrepay(${index}, this.checked)"> Prepay</label>` : ''}
                                    ${item.qoh > 0 ? `<br><small style="color: #e67e22;">QOH: ${formatQuantityValue(item.qoh)} ${getQohUnit(item)}</small>` : ''}
                                        ${discountDisplay}
                                </div>
                            </div>
                        </td>
                            <td>${isServiceItem || !isMedicalProduct(item) ? '' : `${item.lot || ''}${getMedicationDoseSelectorHtml(item, index)}`}</td>
                        <td>
                            ${isServiceItem ? 
                                '' :
                                    `                                <div class="quantity-control">
                                <button class="qty-btn" onclick="updateQuantity(${index}, -getQuantityStep(cart[${index}]))">-</button>
                                <input type="number" class="qty-input" value="${item.quantity}" min="0" max="${maxQty}" step="${getQuantityStep(item)}" onchange="validateAndUpdateQuantity(${index}, this.value)">
                                <button class="qty-btn" onclick="updateQuantity(${index}, getQuantityStep(cart[${index}]))">+</button>
                                    </div>`
                            }
                        </td>
                        <td>
                                ${isServiceItem ? 
                                    '' :
                                    !isMedicalProduct(item) ? 
                                    '' :
                                    isHooverFacility ? 
                                    `<div class="quantity-control">
                                        <button class="qty-btn" onclick="updateMarketplaceDispenseQuantity(${index}, ${Math.max(0, (item.marketplace_dispense_quantity || 0) - getMarketplaceDispenseStep(item))})">-</button>
                                        <input type="number" class="qty-input" value="${item.marketplace_dispense_quantity || 0}" min="0" max="${Math.max(0, (item.quantity || 0) - (item.administer_quantity || 0))}" 
                                            onchange="updateMarketplaceDispenseQuantity(${index}, this.value)"
                                            onfocus="this.select()"
                                            step="${getMarketplaceDispenseStep(item)}">
                                        <button class="qty-btn" onclick="updateMarketplaceDispenseQuantity(${index}, ${Math.max(0, (item.marketplace_dispense_quantity || 0) + getMarketplaceDispenseStep(item))})">+</button>
                                    </div>${actionUnitBadge}` :
                                    `<div class="quantity-control">
                                        <button class="qty-btn" onclick="updateDispenseQuantity(${index}, ${(item.dispense_quantity || 0) - getQuantityStep(item)})">-</button>
                                        <input type="number" class="qty-input" value="${item.dispense_quantity || 0}" min="0" max="${isRemainingDispense ? (item.remaining_quantity || item.quantity) : (item.qoh > 0 ? item.qoh : 999)}" step="${getQuantityStep(item)}" 
                                            onchange="updateDispenseQuantity(${index}, this.value)"
                                            onfocus="this.select()">
                                        <button class="qty-btn" onclick="updateDispenseQuantity(${index}, ${(item.dispense_quantity || 0) + getQuantityStep(item)})">+</button>
                                    </div>${actionUnitBadge}`
                                }
                        </td>
                        <td>
                                ${isServiceItem ? 
                                    '' :
                                    !isMedicalProduct(item) ? 
                                    '' :
                                `                                <div class="quantity-control">
                                    <button class="qty-btn" onclick="updateAdministerQuantity(${index}, ${(item.administer_quantity || 0) - getQuantityStep(item)})">-</button>
                                    <input type="number" class="qty-input" id="administer-input-${index}" value="${item.administer_quantity || 0}" min="0" max="${getMaxAdministerQuantity(item, index)}" step="${getQuantityStep(item)}" 
                                        oninput="validateAdministerInput(${index}, this)"
                                        onchange="updateAdministerQuantity(${index}, this.value)"
                                        onfocus="this.select(); getDailyAdministerStatus(${item.drug_id || 0}, '${item.lot || ''}', function(status) { 
                                            this.title = 'Daily limit: 2 doses per day. Already administered: ' + status.current_total + ' today. Remaining: ' + status.remaining_allowed;
                                        }.bind(this));"
                                        title="Daily limit: 2 doses per day">
                                    <button class="qty-btn" onclick="updateAdministerQuantity(${index}, ${(item.administer_quantity || 0) + getQuantityStep(item)})">+</button>
                                    </div>
                                    ${actionUnitBadge}
`
                                }
                        </td>
                            ${unitPriceColumn}
                            ${discountColumn}
                        <td class="price">
                                ${(isRemainingDispense || isDispensingOnly) ? 
                                    `<span class="price-display" data-index="${index}" style="color: #28a745; font-weight: 600;">$0.00</span>` :
                                    isItemPrepaid(item) ?
                                    `<div>
                                        <span style="text-decoration: line-through; color: #6c757d; font-size: 0.9em;">$${originalItemTotal.toFixed(2)}</span><br>
                                        <span class="price-display" data-index="${index}" style="color: #28a745; font-weight: 600;">$0.00</span><br>
                                        <small style="color:#0d6efd; font-weight:600;">Prepay</small>
                                    </div>` :
                                    item.has_discount ? 
                                    `<div>
                                        <span style="text-decoration: line-through; color: #6c757d; font-size: 0.9em;">$${originalItemTotal.toFixed(2)}</span><br>
                                        <span class="price-display" data-index="${index}" style="color: #28a745; font-weight: 600;">$${discountedItemTotal.toFixed(2)}</span>
                                    </div>` :
                                    `<span class="price-display" data-index="${index}">$${originalItemTotal.toFixed(2)}</span>`
                                }
                        </td>
                        <td>
                            <button class="remove-btn" onclick="removeItem(${index})">
                                <i class="fas fa-trash"></i>
                            </button>
                            ${defectiveButton}
                        </td>
                    </tr>
                `;
            });
            

             
             // Add subtotal after discounts (if any)
             const subtotalColspan = overrideMode ? 7 : 5; // Account for Unit/Price, Discount, and Administer columns in override mode
             html += `
                 <tr style="background-color: #f8f9fa; border-top: 2px solid #dee2e6;">
                     <td colspan="${subtotalColspan}">
                         <div style="display: flex; align-items: center;">
                             <i class="fas fa-calculator" style="color: #495057; margin-right: 8px;"></i>
                             <span style="color: #495057; font-weight: 600;">Subtotal</span>
                         </div>
                     </td>
                     <td class="price" style="color: #495057; font-weight: 600;">$${discountedSubtotal.toFixed(2)}</td>
                     <td></td>
                 </tr>
             `;
             
             // Calculate tax on discounted subtotal
            if (Object.keys(taxRates).length > 0) {
                Object.keys(taxRates).forEach(taxId => {
                    const taxRate = taxRates[taxId];
                    const itemTax = taxableSubtotal * (taxRate.rate / 100);
                    taxTotal += itemTax;
                    if (!taxBreakdown[taxId]) {
                        taxBreakdown[taxId] = {
                            name: taxRate.name,
                            amount: 0
                        };
                    }
                    taxBreakdown[taxId].amount += itemTax;
                });
            }
            
            // Add tax rows only if the tax amount is > 0
            const taxableTaxIds = Object.keys(taxBreakdown).filter(taxId => {
                return (taxBreakdown[taxId].amount || 0) > 0.00001;
            });
            const uniqueTaxableProductNames = [...new Set(taxableProductNames)];
            const taxSourceTooltip = uniqueTaxableProductNames.length > 0
                ? `Tax from: ${uniqueTaxableProductNames.join(', ')}`
                : 'Tax from: No taxable products';
            const escapedTaxSourceTooltip = taxSourceTooltip.replace(/"/g, '&quot;');

            if (taxableTaxIds.length > 0) {
                taxableTaxIds.forEach(taxId => {
                    const tax = taxBreakdown[taxId];
                    const taxRate = taxRates[taxId];
                    html += `
                        <tr style="background-color: #f8f9fa;">
                            <td colspan="${subtotalColspan}">
                                <div style="display: flex; align-items: center;">
                                    <i class="fas fa-percentage" style="color: #6c757d; margin-right: 8px;"></i>
                                    <span style="color: #6c757d; font-weight: 500;">${tax.name} (${taxRate.rate}%)</span>
                                </div>
                            </td>
                            <td class="price" style="color: #6c757d; cursor: help;" title="${escapedTaxSourceTooltip}">$${tax.amount.toFixed(2)}</td>
                            <td></td>
                        </tr>
                    `;
                });
            }
            
            // Add credit applied row if credit is being applied
            const creditCheckbox = document.getElementById('apply-credit-checkbox');
            const creditAmount = creditCheckbox && creditCheckbox.checked ? (window.creditAmount || 0) : 0;
            const tenDollarOffAmount = getTenDollarOffAmount();
            
            if (creditCheckbox && creditCheckbox.checked) {
                html += `
                    <tr style="background-color: #f8f9fa;">
                        <td colspan="${subtotalColspan}">
                            <div style="display: flex; align-items: center;">
                                <i class="fas fa-wallet" style="color: #28a745; margin-right: 8px;"></i>
                                <span style="color: #28a745; font-weight: 500;">Credit Applied</span>
                            </div>
                        </td>
                        <td class="price" style="color: #28a745;">-$${creditAmount.toFixed(2)}</td>
                        <td></td>
                    </tr>
                `;
            }

            if (tenDollarOffAmount > 0) {
                html += `
                    <tr style="background-color: #fffdf2;">
                        <td colspan="${subtotalColspan}">
                            <div style="display: flex; align-items: center;">
                                <i class="fas fa-tags" style="color: #f4b400; margin-right: 8px;"></i>
                                <span style="color: #946200; font-weight: 500;">$10 Off</span>
                            </div>
                        </td>
                        <td class="price" style="color: #946200;">-$${tenDollarOffAmount.toFixed(2)}</td>
                        <td></td>
                    </tr>
                `;
            }
            
            orderItems.innerHTML = html;
            
            const grandTotal = discountedSubtotal + taxTotal - creditAmount - tenDollarOffAmount;
            // Fix negative total display - show 0.00 instead of -0.00
            const displayTotal = grandTotal <= 0 ? 0 : grandTotal;
            orderTotal.textContent = '$' + displayTotal.toFixed(2);
            
            // Update cart counter in header
            const cartCounter = document.getElementById('cart-counter');
            if (cartCounter) {
                const itemCount = cart.reduce((sum, item) => sum + Math.max(item.quantity, item.dispense_quantity || 0), 0);
                cartCounter.textContent = itemCount > 0 ? `(${itemCount} ${itemCount === 1 ? 'item' : 'items'})` : '';
            }
            
            // Show/hide override button based on cart contents and override mode
            const overrideBtn = document.getElementById('override-btn');
            if (overrideBtn) {
                // Check if there are items with quantity > 0 (items that can have prices overridden)
                const hasItemsWithQuantity = cart.some(item => item.quantity > 0);
                
                if (hasItemsWithQuantity && !overrideMode) {
                    // Show override button only when there are items with quantity > 0 AND override mode is not active
                    overrideBtn.style.display = 'flex';
                } else {
                    // Hide override button when no items with quantity > 0 OR override mode is active
                    overrideBtn.style.display = 'none';
                    // If no items with quantity and override mode is active, disable override mode
                    if (overrideMode && !hasItemsWithQuantity) {
                        disableOverride();
                    }
                }
            }
            
            // If override mode is active, make prices editable
            if (overrideMode) {
                setTimeout(() => {
                    enablePriceEditing();
                }, 50);
            }
            
            // Update payment button based on cart contents
            updatePaymentButton();
            
            // Update table headers based on cart contents
            updateTableHeaders();
            
            // Update weight validation status (checkout button) if on Order Summary step
            if (currentStep === 1 && cart.length > 0) {
                setTimeout(() => {
                    updateWeightValidationStatus();
                }, 100);
            }
        }

        // Helper function to recalculate credit amount if credit is being applied
        function recalculateCreditIfApplied() {
            const creditCheckbox = document.getElementById('apply-credit-checkbox');
            if (creditCheckbox && creditCheckbox.checked) {
                applyFullCreditBalance();
            }
        }

        // Function to check if cart contains only remaining dispense items or dispensing-only items
        function isOnlyRemainingDispense() {
            if (cart.length === 0) return false;
            return cart.every(item => item.is_remaining_dispense || (item.quantity === 0 && item.dispense_quantity > 0));
        }

        function getCartCheckoutTotal() {
            const creditCheckbox = document.getElementById('apply-credit-checkbox');
            const creditAmount = creditCheckbox && creditCheckbox.checked ? (window.creditAmount || 0) : 0;
            const tenDollarOffAmount = getTenDollarOffAmount();

            let total = 0;
            cart.forEach(item => {
                total += getSimpleDiscountedItemTotal(item);
            });

            if (Object.keys(taxRates).length > 0) {
                Object.keys(taxRates).forEach(taxId => {
                    const taxRate = taxRates[taxId];
                    const itemTax = total * (taxRate.rate / 100);
                    total += itemTax;
                });
            }

            total -= creditAmount;
            total -= tenDollarOffAmount;
            return Math.max(0, total);
        }

        // Function to update payment button based on cart contents
        function updatePaymentButton() {
            const checkoutBtn = document.getElementById('checkout-btn');
            if (!checkoutBtn) return;
            
            // Check if cart is empty
            if (cart.length === 0) {
                checkoutBtn.style.display = 'none';
                return;
            }
            
            // Check if all items have quantity = 0, dispense quantity = 0, and administer quantity = 0 (empty cart)
            const allItemsEmpty = cart.every(item => 
                item.quantity === 0 && 
                (!item.dispense_quantity || item.dispense_quantity === 0) && 
                (!item.administer_quantity || item.administer_quantity === 0)
            );
            
            if (allItemsEmpty) {
                // Hide payment button when all items are empty
                checkoutBtn.style.display = 'none';
                return;
            }
            
            // Show payment button
            checkoutBtn.style.display = 'block';
            
            // Check if all items have quantity = 0 but some have dispense quantity > 0 (dispensing only)
            const allItemsZeroQuantity = cart.every(item => item.quantity === 0);
            const someItemsHaveDispenseQuantity = cart.some(item => item.dispense_quantity && item.dispense_quantity > 0);
            const someItemsHaveAdministerQuantity = cart.some(item => (item.administer_quantity || 0) > 0);
            const allItemsZeroDispense = cart.every(item => !item.dispense_quantity || item.dispense_quantity === 0);
            const allItemsZeroAdminister = cart.every(item => !item.administer_quantity || item.administer_quantity === 0);
            const checkoutTotal = getCartCheckoutTotal();
            
            // Check for combined dispense and administer scenario (QTY=0, both dispense and administer > 0)
            const hasCombinedDispenseAdminister = allItemsZeroQuantity && someItemsHaveDispenseQuantity && someItemsHaveAdministerQuantity;
            
            if (hasCombinedDispenseAdminister) {
                // Show "Complete Dispense and Administer Process" button for combined scenarios
                checkoutBtn.innerHTML = '<i class="fas fa-syringe"></i> Complete Dispense and Administer Process';
                checkoutBtn.onclick = completeDispenseAndAdminister;
                checkoutBtn.style.background = 'linear-gradient(135deg, #17a2b8 0%, #6f42c1 100%)';
                checkoutBtn.title = 'Complete both dispense and administer without payment';
            } else if (allItemsZeroQuantity && allItemsZeroDispense && someItemsHaveAdministerQuantity) {
                if (checkoutTotal > 0) {
                    // Administer-only remaining dispense can still require payment for dose/price differences.
                    checkoutBtn.innerHTML = '<i class="fas fa-credit-card"></i> Proceed to Payment';
                    checkoutBtn.onclick = nextStep;
                    checkoutBtn.style.background = 'linear-gradient(135deg, #007bff 0%, #0056b3 100%)';
                    checkoutBtn.title = 'Collect payment before completing administer';
                } else {
                    // Show "Administer Complete" button for administer-only scenarios with no balance due
                    checkoutBtn.innerHTML = '<i class="fas fa-syringe"></i> Administer Complete';
                    checkoutBtn.onclick = completeAdminister;
                    checkoutBtn.style.background = 'linear-gradient(135deg, #6f42c1 0%, #e83e8c 100%)';
                    checkoutBtn.title = 'Complete administer without payment';
                }
            } else if (allItemsZeroQuantity && someItemsHaveDispenseQuantity && allItemsZeroAdminister) {
                // Show "Dispense Complete" button for dispensing-only scenarios
                console.log('🔄 Generating dispense button - isHooverFacility:', isHooverFacility);
                if (isHooverFacility && !cart.every(item => usesRegularDispenseInHoover(item))) {
                    // For Hoover facility, show marketplace checkbox instead of dispense button
                    console.log('🏪 Setting up HOOVER marketplace dispense button');
                    checkoutBtn.innerHTML = '<i class="fas fa-store"></i> Marketplace Dispense';
                    checkoutBtn.onclick = completeMarketplaceDispense;
                    checkoutBtn.style.background = 'linear-gradient(135deg, #6f42c1 0%, #e83e8c 100%)';
                    checkoutBtn.title = 'Mark items as dispensed from marketplace';
                } else {
                    // For other facilities, show normal dispense button
                    console.log('✅ Setting up regular dispense button');
                    checkoutBtn.innerHTML = '<i class="fas fa-check"></i> Dispense Complete';
                    checkoutBtn.onclick = completeDispense;
                    checkoutBtn.style.background = 'linear-gradient(135deg, #28a745 0%, #20c997 100%)';
                    checkoutBtn.title = 'Complete dispense without payment';
                }
            } else if (overrideMode) {
                // Show "Override Complete" button when in override mode
                checkoutBtn.innerHTML = '<i class="fas fa-check"></i> Override Complete';
                checkoutBtn.onclick = completeOverride;
                checkoutBtn.style.background = 'linear-gradient(135deg, #ffc107 0%, #fd7e14 100%)';
                checkoutBtn.title = 'Complete override and proceed to payment';
            } else if (isOnlyRemainingDispense()) {
                // Show "Dispense Complete" button for remaining dispense items
                console.log('🔄 Generating remaining dispense button - isHooverFacility:', isHooverFacility);
                if (isHooverFacility && !cart.every(item => usesRegularDispenseInHoover(item))) {
                    // For Hoover facility, show marketplace dispense for remaining items
                    console.log('🏪 Setting up HOOVER marketplace dispense button for remaining items');
                    checkoutBtn.innerHTML = '<i class="fas fa-store"></i> Marketplace Dispense';
                    checkoutBtn.onclick = completeMarketplaceDispense;
                    checkoutBtn.style.background = 'linear-gradient(135deg, #6f42c1 0%, #e83e8c 100%)';
                    checkoutBtn.title = 'Mark remaining items as dispensed from marketplace';
                } else {
                    // For other facilities, show normal dispense button
                    console.log('✅ Setting up regular dispense button for remaining items');
                    checkoutBtn.innerHTML = '<i class="fas fa-check"></i> Dispense Complete';
                    checkoutBtn.onclick = completeDispense;
                    checkoutBtn.style.background = 'linear-gradient(135deg, #28a745 0%, #20c997 100%)';
                    checkoutBtn.title = 'Complete dispense without payment';
                }
            } else {
                const creditCheckbox = document.getElementById('apply-credit-checkbox');
                const creditAmount = creditCheckbox && creditCheckbox.checked ? (window.creditAmount || 0) : 0;

                if (checkoutTotal <= 0 && creditAmount > 0) {
                    // Show "Complete Purchase" button when total is 0 or negative with credit applied
                    checkoutBtn.innerHTML = '<i class="fas fa-check"></i> Complete Purchase';
                    checkoutBtn.onclick = completePurchaseWithCredit;
                    checkoutBtn.style.background = 'linear-gradient(135deg, #28a745 0%, #1e7e34 100%)';
                    checkoutBtn.title = 'Complete purchase with credit balance';
                } else {
                    // Show regular "Proceed to Payment" button
                    checkoutBtn.innerHTML = '<i class="fas fa-credit-card"></i> Proceed to Payment';
                    checkoutBtn.onclick = nextStep;
                    checkoutBtn.style.background = 'linear-gradient(135deg, #007bff 0%, #0056b3 100%)';
                    checkoutBtn.title = 'Proceed to payment';
                }
            }
        }

        // Function to complete override and proceed to payment
        function completeOverride() {
            // Disable override mode
            disableOverride();
            // Proceed to payment
            nextStep();
        }

        // Function to complete dispense without payment
        function completeDispense() {
            if (cart.length === 0) {
                alert('Cart is empty. Please add items first.');
                return;
            }

            // Check if all items are either remaining dispense items or dispensing-only items
            const allItemsValid = cart.every(item => 
                item.is_remaining_dispense || 
                (item.quantity === 0 && item.dispense_quantity > 0) ||
                (item.has_remaining_dispense && item.dispense_quantity > 0)
            );

            if (!allItemsValid) {
                alert('Cart contains items that require payment. Please use the regular payment process.');
                return;
            }

            // For dispensing-only items (quantity = 0, dispense_quantity > 0) that don't have is_remaining_dispense flag,
            // we need to mark them as remaining dispense items so the backend can process them correctly
            // BUT don't set this flag for items that have has_remaining_dispense (integrated remaining dispense items)
            cart.forEach(item => {
                if (item.quantity === 0 && item.dispense_quantity > 0 && !item.is_remaining_dispense && !item.has_remaining_dispense) {
                    item.is_remaining_dispense = true;
                }
            });

            // Confirm dispense completion
            if (!confirm('Are you sure you want to complete the dispense? This will deduct quantities from inventory and update remaining dispense tracking.')) {
                return;
            }

            // Prepare data for dispense completion
            const dispenseData = {
                pid: <?php echo $pid ?: 'null'; ?>,
                patient_number: getCurrentPatientNumberValue(),
                items: cart.map(item => ({
                    id: item.id,
                    name: item.name,
                    display_name: item.display_name,
                    drug_id: item.drug_id || item.id.replace('drug_', '').split('_')[0],
                    lot_number: item.lot,
                    quantity: item.quantity,
                    dispense_quantity: item.dispense_quantity,
                    administer_quantity: item.administer_quantity !== undefined ? item.administer_quantity : 0,
                    dose_option_mg: item.dose_option_mg || '',
                    is_remaining_dispense: item.is_remaining_dispense,
                    has_remaining_dispense: item.has_remaining_dispense || false,
                    remaining_dispense_items: item.remaining_dispense_items || [],
                    total_remaining_quantity: item.total_remaining_quantity || 0,
                    is_different_lot_dispense: item.is_different_lot_dispense || false
                })),
                action: 'complete_dispense',
                csrf_token_form: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
            };



            // Send dispense completion request
            fetch('pos_payment_processor.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(dispenseData)
            })
            .then(response => {
                return response.text().then(text => {
                    let parsed;
                    try {
                        parsed = JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON response:', text);
                        throw new Error('Invalid server response format');
                    }

                    if (!response.ok) {
                        throw new Error(parsed.error || `HTTP ${response.status}: ${response.statusText}`);
                    }

                    return parsed;
                });
            })
            .then(data => {
                if (data.success) {
                    alert('Dispense completed successfully!');
                    openOperationalReceiptWindow(data.receipt_number);
                    // Clear cart
                    cart = [];
                    updateOrderSummary();
                    saveCartToStorage();
                    // Hide dispense tracking section
                    hideDispenseTracking();
                    // Reload remaining dispense data
                    setTimeout(() => {
                        if (document.getElementById('dispense-tracking-section').style.display !== 'none') {
                            loadRemainingDispenseData();
                        }
                    }, 1000);
                } else {
                    alert('Error completing dispense: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error completing dispense:', error);
                alert('Error completing dispense: ' + error.message);
            });
        }

        // Function to toggle marketplace dispense for individual items (Hoover facility)

        // Function to complete marketplace dispense for Hoover facility

        // Function to complete administer without payment
        function completeAdminister() {
            if (cart.length === 0) {
                alert('Cart is empty. Please add items first.');
                return;
            }

            // Check if all items are administer-only items (quantity = 0, dispense_quantity = 0, administer_quantity > 0)
            const allItemsValid = cart.every(item => 
                (item.quantity === 0 && item.dispense_quantity === 0 && (item.administer_quantity || 0) > 0) ||
                (item.has_remaining_dispense && item.quantity === 0 && item.dispense_quantity === 0 && (item.administer_quantity || 0) > 0)
            );

            if (!allItemsValid) {
                alert('Cart contains items that require payment or dispense. Please use the regular payment process or complete dispense.');
                return;
            }

            // Check daily administration limits before proceeding
            checkAllAdministerLimitsAsync().then(() => {
                // For administer-only items, we need to mark them appropriately for backend processing
                cart.forEach(item => {
                    if (item.quantity === 0 && item.dispense_quantity === 0 && (item.administer_quantity || 0) > 0 && !item.is_remaining_dispense && !item.has_remaining_dispense) {
                        item.is_remaining_dispense = true;
                    }
                });

                // Confirm administer completion
                if (!confirm('Are you sure you want to complete the administer? This will deduct quantities from inventory and update remaining dispense tracking.')) {
                    return;
                }

                // Prepare data for administer completion
                const administerData = {
                    pid: <?php echo $pid ?: 'null'; ?>,
                    patient_number: getCurrentPatientNumberValue(),
                    items: cart.map(item => ({
                        id: item.id,
                        name: item.name,
                        display_name: item.display_name,
                        drug_id: item.drug_id || item.id.replace('drug_', '').split('_')[0],
                        lot_number: item.lot,
                        quantity: item.quantity,
                        dispense_quantity: item.dispense_quantity,
                        administer_quantity: item.administer_quantity !== undefined ? item.administer_quantity : 0,
                        dose_option_mg: item.dose_option_mg || '',
                        is_remaining_dispense: item.is_remaining_dispense,
                        has_remaining_dispense: item.has_remaining_dispense || false,
                        remaining_dispense_items: item.remaining_dispense_items || [],
                        total_remaining_quantity: item.total_remaining_quantity || 0,
                        is_different_lot_dispense: item.is_different_lot_dispense || false
                    })),
                    action: 'complete_administer',
                    csrf_token_form: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                };

                // Debug: Log the administer data being sent
                console.log('Administer Data being sent:', administerData);
                
                // Send administer completion request
                fetch('pos_payment_processor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(administerData)
                })
                .then(async response => {
                    const text = await response.text();
                    let data = null;
                    try {
                        data = text ? JSON.parse(text) : null;
                    } catch (e) {
                        console.error('Invalid JSON response:', text);
                        throw new Error('Invalid server response format');
                    }

                    if (!response.ok) {
                        throw new Error(data?.error || `HTTP ${response.status}: ${response.statusText}`);
                    }

                    return data;
                })
                .then(data => {
                    if (data.success) {
                        alert('Administer completed successfully!');
                        openOperationalReceiptWindow(data.receipt_number);
                        // Clear cart
                        cart = [];
                        updateOrderSummary();
                        saveCartToStorage();
                        // Hide dispense tracking section
                        hideDispenseTracking();
                        // Reload remaining dispense data
                        setTimeout(() => {
                            if (document.getElementById('dispense-tracking-section').style.display !== 'none') {
                                loadRemainingDispenseData();
                            }
                        }, 1000);
                    } else {
                        alert('Error completing administer: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error completing administer:', error);
                    alert('Error completing administer: ' + error.message);
                });
            }).catch(error => {
                console.error('Error checking administration limits:', error);
                // Don't proceed if limits are exceeded
            });
        }

        // Function to complete both dispense and administer without payment
        function completeDispenseAndAdminister() {
            if (cart.length === 0) {
                alert('Cart is empty. Please add items first.');
                return;
            }

            // Check if all items are valid for combined dispense and administer (quantity = 0, both dispense and administer > 0)
            const allItemsValid = cart.every(item => 
                (item.quantity === 0 && (item.dispense_quantity || 0) > 0 && (item.administer_quantity || 0) > 0) ||
                (item.has_remaining_dispense && item.quantity === 0 && (item.dispense_quantity || 0) > 0 && (item.administer_quantity || 0) > 0)
            );

            if (!allItemsValid) {
                alert('Cart contains items that require payment or are not valid for combined dispense and administer. Please use the regular payment process.');
                return;
            }

            // Check daily administration limits before proceeding
            checkAllAdministerLimitsAsync().then(() => {
                // For combined dispense and administer items that don't have is_remaining_dispense flag,
                // we need to mark them as remaining dispense items so the backend can process them correctly
                cart.forEach(item => {
                    if (item.quantity === 0 && (item.dispense_quantity || 0) > 0 && (item.administer_quantity || 0) > 0 && !item.is_remaining_dispense && !item.has_remaining_dispense) {
                        item.is_remaining_dispense = true;
                    }
                });

                // Confirm combined dispense and administer completion
                if (!confirm('Are you sure you want to complete both dispense and administer? This will deduct quantities from inventory and update remaining dispense tracking.')) {
                    return;
                }

            // Prepare data for combined dispense and administer completion
            const combinedData = {
                pid: <?php echo $pid ?: 'null'; ?>,
                patient_number: getCurrentPatientNumberValue(),
                items: cart.map(item => ({
                    id: item.id,
                    name: item.name,
                    display_name: item.display_name,
                    drug_id: item.drug_id || item.id.replace('drug_', '').split('_')[0],
                    lot_number: item.lot,
                    quantity: item.quantity,
                    dispense_quantity: item.dispense_quantity,
                    administer_quantity: item.administer_quantity !== undefined ? item.administer_quantity : 0,
                    dose_option_mg: item.dose_option_mg || '',
                    is_remaining_dispense: item.is_remaining_dispense,
                    has_remaining_dispense: item.has_remaining_dispense || false,
                    remaining_dispense_items: item.remaining_dispense_items || [],
                    total_remaining_quantity: item.total_remaining_quantity || 0,
                    is_different_lot_dispense: item.is_different_lot_dispense || false
                })),
                action: 'complete_dispense_and_administer',
                csrf_token_form: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
            };

                // Debug: Log the combined data being sent
                console.log('Combined Dispense and Administer Data being sent:', combinedData);
                console.log('Hoover facility check in payment data - isHooverFacility:', isHooverFacility);
                console.log('Current facility ID in payment:', currentFacilityId);
            
            // Send combined dispense and administer completion request
            fetch('pos_payment_processor.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(combinedData)
            })
            .then(response => {
                return response.text().then(text => {
                    let parsed;
                    try {
                        parsed = JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON response:', text);
                        throw new Error('Invalid server response format');
                    }

                    if (!response.ok) {
                        throw new Error(parsed.error || `HTTP ${response.status}: ${response.statusText}`);
                    }

                    return parsed;
                });
            })
            .then(data => {
                if (data.success) {
                    alert('Combined dispense and administer completed successfully!');
                    openOperationalReceiptWindow(data.receipt_number);
                    // Clear cart
                    cart = [];
                    updateOrderSummary();
                    saveCartToStorage();
                    // Hide dispense tracking section
                    hideDispenseTracking();
                    // Reload remaining dispense data
                    setTimeout(() => {
                        if (document.getElementById('dispense-tracking-section').style.display !== 'none') {
                            loadRemainingDispenseData();
                        }
                    }, 1000);
                } else {
                    alert('Error completing combined dispense and administer: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error completing combined dispense and administer:', error);
                alert('Error completing combined dispense and administer: ' + error.message);
            });
            }).catch(error => {
                console.error('Error checking administration limits:', error);
                // Don't proceed if limits are exceeded
            });
        }

        // Real-time discount update function
        async function updateCartDiscounts() {
            if (cart.length === 0) return;
            
            try {
                // Get unique drug IDs from cart
                const drugIds = [...new Set(cart.map(item => {
                    // Extract drug_id from item.id (format: drug_123_lot_456)
                    const match = item.id.match(/drug_(\d+)_/);
                    return match ? match[1] : null;
                }).filter(id => id))];
                
                if (drugIds.length === 0) return;
                
                // Fetch current discount information for all drugs in cart
                const response = await fetch(posSimpleSearchUrl + '?action=get_discounts&drug_ids=' + drugIds.join(','));
                const data = await response.json();
                
                if (data.success && data.discounts) {
                    // Update cart items with current discount information
                    cart.forEach(item => {
                        const drugId = item.id.match(/drug_(\d+)_/)?.[1];
                        if (drugId && data.discounts[drugId]) {
                            const discountInfo = data.discounts[drugId];
                            
                            // Store inventory discount information for display
                            item.inventory_discount_info = discountInfo.discount_info;
                            item.inventory_has_discount = discountInfo.has_discount;
                            
                            // Only update if the item hasn't been manually overridden
                            if (!item.is_manually_overridden) {
                                // Update item with current discount information
                                item.has_discount = discountInfo.has_discount;
                                item.discount_info = discountInfo.discount_info;
                                item.price = discountInfo.discounted_price;
                                item.original_price = discountInfo.original_price;
                            }
                        }
                    });
                    
                    cart.forEach(item => {
                        applyDoseBasedPricing(item);
                    });

                    // Save updated cart to storage
                    saveCartToStorage();
                }
            } catch (error) {
                console.error('Error updating cart discounts:', error);
            }
        }

        // Auto-refresh discounts every 30 seconds
        function startDiscountAutoRefresh() {
            setInterval(() => {
                if (cart.length > 0 && !overrideMode) {
                    updateCartDiscounts().then(() => {
                        updateOrderSummary();
                        
                        // Recalculate credit amount if credit is being applied
                        const creditCheckbox = document.getElementById('apply-credit-checkbox');
                        if (creditCheckbox && creditCheckbox.checked) {
                            applyFullCreditBalance();
                        }
                    });
                }
            }, 30000); // 30 seconds
        }
        // Validate and update quantity from input field
        function validateAndUpdateQuantity(index, newValue) {
            const item = cart[index];
            if (!item) return;
            
            // Prevent quantity changes for consultation items
            if (item.id.startsWith('consultation_')) {
                updateOrderSummary(); // Refresh to show correct value
                return;
            }
            
            let newQty = parseFloat(newValue) || 0;
            
            // Check QOH limits
            if (item.qoh > 0 && newQty > item.qoh) {
                alert('Cannot exceed Quantity on Hand (QOH): ' + formatQuantityValue(item.qoh) + ' ' + getQohUnit(item));
                updateOrderSummary(); // Refresh to show correct value
                return;
            }
            
            // Allow quantity to be 0 for dispensing-only items
            if (newQty < 0) {
                newQty = 0;
            }
            
            item.quantity = newQty;
            
            // For Hoover facility, update marketplace dispense only if QTY is less than Marketplace Dispense + Administer
            if (isHooverFacility) {
                const currentAdminister = item.administer_quantity || 0;
                const currentMarketplaceDispense = item.marketplace_dispense_quantity || 0;
                const minRequiredQty = currentMarketplaceDispense + currentAdminister;
                
                // Only adjust marketplace dispense if new QTY is less than the minimum required
                if (newQty < minRequiredQty) {
                    const marketplaceQty = Math.max(0, newQty - currentAdminister);
                    
                    const adjustedMarketplaceQty = roundMarketplaceDispenseQuantity(marketplaceQty, item);
                    
                    item.marketplace_dispense_quantity = adjustedMarketplaceQty;
                    item.dispense_quantity = adjustedMarketplaceQty;
                    item.marketplace_dispense = adjustedMarketplaceQty > 0;
                    
                    console.log(`Hoover facility: QTY (${newQty}) < Marketplace (${currentMarketplaceDispense}) + Administer (${currentAdminister}). Adjusted marketplace dispense to ${adjustedMarketplaceQty}`);
                } else {
                    // QTY is >= Marketplace Dispense + Administer, no adjustment needed
                    console.log(`Hoover facility: QTY (${newQty}) >= Marketplace (${currentMarketplaceDispense}) + Administer (${currentAdminister}). No adjustment needed`);
                }
            }
            
            // Keep item in cart even when both quantity and dispense are 0 (for tracking purposes)
            // Removed automatic removal logic
            
            updateOrderSummary();
            saveCartToStorage();
            
            // Recalculate credit amount if credit is being applied
            const creditCheckbox = document.getElementById('apply-credit-checkbox');
            if (creditCheckbox && creditCheckbox.checked) {
                applyFullCreditBalance();
            }
        }

        // Process checkout - now handled by nextStep() function
        function processCheckout() {
            
            nextStep(); // This will validate and move to step 3
        }

        // Navigate back to Finder function
        function navigateBackToFinder() {
            try {
                // Navigate back to the Finder page
                window.location.href = '../../main/finder/dynamic_finder.php';
            } catch (error) {
                console.error('Error navigating back:', error);
                // Fallback: try to go back in browser history
                window.history.back();
            }
        }

        // Initialize Stripe (disabled for terminal-only workflow)
        function initializeStripe() { return; }

        // Handle payment method change
        function handlePaymentMethodChange() {
            const paymentMethod = document.getElementById('payment-method').value;
            const cashChangeDiv = document.getElementById('cash-change');
            
            // Show/hide cash change calculation for cash payments
            if (paymentMethod === 'cash') {
                cashChangeDiv.style.display = 'block';
                calculateChange();
                    } else {
                cashChangeDiv.style.display = 'none';
            }
        }

        // Refresh CSRF token
        async function refreshCSRFToken() {
            try {
                const response = await fetch('pos_payment_processor.php?get_csrf_token=1', {
                    credentials: 'include'
                });
                const data = await response.json();
                if (data.csrf_token) {
                    return data.csrf_token;
                }
            } catch (error) {
                console.error('Failed to refresh CSRF token:', error);
            }
            return null;
        }

        // Safely toggle the Process Payment button state
        function setButtonState(isDisabled) {
            const processBtn = document.getElementById('process-payment-btn');
            if (!processBtn) return;
            processBtn.disabled = !!isDisabled;
            processBtn.innerHTML = isDisabled
                ? '<i class="fas fa-spinner fa-spin"></i> Processing...'
                : '<i class="fas fa-lock"></i> Process Payment';
        }

        function resetCheckoutPaymentSession() {
            const paymentAmountEl = document.getElementById('payment-amount');
            if (paymentAmountEl) {
                paymentAmountEl.value = '';
            }

            const paymentMethodSelect = document.getElementById('payment-method');
            if (paymentMethodSelect) {
                paymentMethodSelect.value = '';
            }

            const listWrap = document.getElementById('payments-list');
            const listUl = document.getElementById('payments-ul');
            if (listWrap && listUl) {
                listWrap.style.display = 'none';
                listUl.innerHTML = '';
            }

            window._posPayments = [];
            window.currentInvoiceNumber = null;
        }

        async function finalizePendingCheckoutInvoice(paymentData) {
            const finalizeData = {
                action: 'finalize_invoice',
                pid: <?php echo $pid ?: 'null'; ?>,
                invoice_number: window.currentInvoiceNumber || '',
                patient_number: getCurrentPatientNumberValue(),
                items: paymentData.items,
                subtotal: paymentData.subtotal,
                tax_total: paymentData.tax_total,
                tax_breakdown: paymentData.tax_breakdown,
                credit_amount: paymentData.credit_amount,
                ten_off_discount: paymentData.ten_off_discount,
                csrf_token_form: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
            };

            const resp = await fetch('pos_payment_processor.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(finalizeData)
            });
            const res = await resp.json();
            if (!res.success) {
                throw new Error(res.error || 'Finalize failed');
            }

            if (typeof completePurchase === 'function') {
                await completePurchase(res);
            } else if (res.receipt_number) {
                const currentPid = <?php echo $pid ?: 'null'; ?>;
                let receiptUrl = '';
                if (currentPid) {
                    receiptUrl = `pos_receipt.php?receipt_number=${res.receipt_number}&pid=${currentPid}`;
                } else {
                    receiptUrl = `pos_receipt.php?receipt_number=${res.receipt_number}`;
                }
                const left = Math.max(0, (window.screen.availWidth - 400) / 2);
                const top = Math.max(0, (window.screen.availHeight - 600) / 2);
                const receiptWindow = window.open(receiptUrl, '_blank', `width=400,height=600,left=${left},top=${top},scrollbars=yes,resizable=yes,status=no,location=no,toolbar=no,menubar=no`);
                if (receiptWindow) {
                    receiptWindow.focus();
                }
            }

            resetCheckoutPaymentSession();

            cart = [];
            tenDollarOffEnabled = false;
            clearPersistentCart();
            syncTenDollarOffToggleState();
            updateOrderSummary();

            setTimeout(() => {
                if (typeof loadPatientCreditBalance === 'function') { loadPatientCreditBalance(); }
                if (typeof checkCreditBalanceAvailability === 'function') { checkCreditBalanceAvailability(); }
                if (typeof refreshPatientData === 'function') { refreshPatientData(); }
                if (typeof loadRemainingDispenseData === 'function') {
                    console.log('Finalize complete - refreshing dispense tracking...');
                    loadRemainingDispenseData();
                }
            }, 500);
        }

        // Handle payment success
        function handlePaymentSuccess(result) {
            // Show success message with receipt option
            let message = result.message || 'Payment processed successfully!';
            if (result.warning) {
                message += '\n\nWarning: ' + result.warning;
            }
            if (result.change !== undefined) {
                message += '\n\nChange: $' + result.change.toFixed(2);
            }
            
            // Ask user if they want to print receipt
            if (result.receipt_number) {
                const printReceipt = confirm(message + '\n\nWould you like to print a receipt?');
                if (printReceipt) {
                    // Open receipt in new window with improved positioning
                    const receiptUrl = `receipt.php?receipt=${result.receipt_number}`;
                    const receiptWindow = window.open(
                        receiptUrl, 
                        'receipt', 
                        'width=400,height=600,scrollbars=yes,resizable=yes,left=' + 
                        (screen.width / 2 - 200) + ',top=' + (screen.height / 2 - 300)
                    );
                    
                    // Focus the receipt window
                    if (receiptWindow) {
                        receiptWindow.focus();
                    }
                } else {
                    showNotification(message, 'success');
                }
            } else {
                showNotification(message, 'success');
            }
            
            // Clear cart and reset UI
            clearCart();
            
            // Clear invoice number for new transaction
            window.currentInvoiceNumber = null;
            
            // Reset payment method to None so staff must pick it explicitly
            const paymentMethodSelect = document.getElementById('payment-method');
            if (paymentMethodSelect) {
                paymentMethodSelect.value = '';
            }
            
            // Clear any card errors
            const cardErrors = document.getElementById('card-errors');
            if (cardErrors) {
                cardErrors.textContent = '';
            }
        }

        // Process payment
        async function processPayment() {
            const processBtn = document.getElementById('process-payment-btn');
            
            if (cart.length === 0) {
                alert('No items in cart');
                return;
            }
            
            // Generate invoice number if not already set
            if (!window.currentInvoiceNumber) {
                window.currentInvoiceNumber = generateFacilityInvoiceNumber('INV');
            }
            
            const totals = getCheckoutTotals();
            const subtotal = totals.adjustedSubtotal;
            const taxTotal = totals.taxTotal;
            const taxBreakdown = totals.taxBreakdown;
            const creditAmount = totals.creditAmount;
            const grandTotal = totals.grandTotal;
            
            if (grandTotal < 0) {
                alert('Credit amount cannot exceed total amount');
                return;
            }
            const totalDue = totals.totalBeforeCredit;
            // Check if total is $0 (complete purchase with credit)
           if (grandTotal === 0 && totalDue > 0 && creditAmount > 0) {
    completePurchaseWithCredit();
    return;
}
            
            // Check daily administration limits before processing payment
            await checkAllAdministerLimitsAsync();
            
                // Disable button and show processing
                processBtn.disabled = true;
                processBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                clearPatientNumberInlineError();
            
            try {
                const currentPid = <?php echo $pid ?: 'null'; ?>;
                // Ensure proper precision for payment amount
                const paymentAmount = Math.round(grandTotal * 100) / 100;

                if (!validatePrepayDetails()) {
                    processBtn.disabled = false;
                    processBtn.innerHTML = '<i class="fas fa-lock"></i> Process Payment';
                    return;
                }
                
                let paymentData = {
                    action: '',
                    pid: currentPid,
                    amount: paymentAmount,
                    items: cart.map(item => buildCheckoutItemPayload(item)),
                    subtotal: Math.round(subtotal * 100) / 100,
                    tax_total: Math.round(taxTotal * 100) / 100,
                    tax_breakdown: taxBreakdown,
                    ten_off_discount: Math.round(totals.tenDollarOffAmount * 100) / 100,
                    prepay_enabled: prepayModeEnabled,
                    prepay_details: prepayDetails,
                    credit_amount: Math.round(creditAmount * 100) / 100,
                    csrf_token_form: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                };
                paymentData.visit_type = (window.getVisitType ? window.getVisitType() : "-");
                paymentData.price_override_notes = (window._priceOverrideNotes || '').trim();
                paymentData.patient_number = getCurrentPatientNumberValue();
console.log("Sending visit_type:", paymentData.visit_type);
                
                // Debug: Log payment data and facility info
                console.log('Payment Data being sent:', paymentData);
                console.log('Hoover facility check in payment - isHooverFacility:', isHooverFacility);
                console.log('Current facility ID in payment:', currentFacilityId);
                
                // Check for overpayment / remaining first
                const paidSoFar = (window._posPayments || []).reduce((sum, p) => sum + (parseFloat(p.amount) || 0), 0);
                const remainingBalance = Math.max(0, Math.round((grandTotal - paidSoFar) * 100) / 100);

                if (remainingBalance === 0) {
                    const hasRecordedPayment = paidSoFar > 0 || creditAmount > 0;
                    if (!hasRecordedPayment) {
                        throw new Error('No recorded payment found to finalize');
                    }

                    renderCheckoutPaymentSummary();
                    await finalizePendingCheckoutInvoice(paymentData);
                    setButtonState(false);
                    return;
                }

                // Get payment method and amount from the new form
                const paymentMethod = document.getElementById('payment-method').value;
                let enteredAmount = parseFloat(document.getElementById('payment-amount').value || '0');

                if (!paymentMethod) {
                    throw new Error('Select a payment method');
                }

                if (isNaN(enteredAmount) || enteredAmount <= 0) {
                    throw new Error('Enter payment amount');
                }
                if (enteredAmount > remainingBalance) {
                    throw new Error(`Payment amount ($${enteredAmount.toFixed(2)}) exceeds remaining balance ($${remainingBalance.toFixed(2)}). Please enter $${remainingBalance.toFixed(2)} or less.`);
                }
                
                console.log('Overpayment check - Grand Total:', grandTotal, 'Paid so far:', paidSoFar, 'Remaining:', remainingBalance);
                
                if (enteredAmount > remainingBalance) {
                    throw new Error(`Payment amount ($${enteredAmount.toFixed(2)}) exceeds remaining balance ($${remainingBalance.toFixed(2)}). Please enter $${remainingBalance.toFixed(2)} or less.`);
                }
                
                paymentData.action = 'record_external_payment';
                paymentData.amount = Math.round(enteredAmount * 100) / 100;
                paymentData.method = paymentMethod;
                paymentData.reference = '';
                paymentData.invoice_number = window.currentInvoiceNumber || '';
                
                // Debug: Log the payment data structure (after action is set)
                console.log('Payment Data Structure:', paymentData);
                
                // Debug: Log the payment data
                console.log('CSRF Token:', paymentData.csrf_token_form);
                console.log('Payment Data:', paymentData);
                console.log('Current PID:', currentPid);
                
                // Debug: Log remaining dispense integration data
                paymentData.items.forEach((item, index) => {
                    if (item.has_remaining_dispense) {
                        console.log(`Item ${index} (${item.name}) has remaining dispense integration:`, {
                            has_remaining_dispense: item.has_remaining_dispense,
                            total_remaining_quantity: item.total_remaining_quantity,
                            remaining_dispense_items: item.remaining_dispense_items
                        });
                    }
                });
                
                // Send to backend for processing
                const response = await fetch('pos_payment_processor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(paymentData),
                    credentials: 'include'
                });
                
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('Server response:', response.status, errorText);
                    let parsedServerError = '';
                    try {
                        const parsedError = JSON.parse(errorText);
                        parsedServerError = parsedError && parsedError.error ? String(parsedError.error) : '';
                    } catch (parseError) {
                        parsedServerError = '';
                    }
                    
                    // Check if it's a CSRF token error and try to refresh
                    if (response.status === 403 && errorText.includes('CSRF token verification failed')) {
                        console.log('CSRF token expired, attempting to refresh and retry...');
                        
                        // Try to refresh CSRF token and retry the payment
                        const newToken = await refreshCSRFToken();
                        if (newToken) {
                            console.log('Got new CSRF token, retrying payment...');
                            paymentData.csrf_token_form = newToken;
                            
                            // Retry the payment with new token
                            const retryResponse = await fetch('pos_payment_processor.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify(paymentData),
                                credentials: 'include'
                            });
                            
                            if (retryResponse.ok) {
                                const retryResult = await retryResponse.json();
                                if (retryResult.success) {
                                    // Handle successful payment
                                    handlePaymentSuccess(retryResult);
                                    return;
                                }
                            }
                        }
                        
                        // If refresh failed, ask user to reload page
                        if (confirm('Your session has expired. Would you like to refresh the page to continue?')) {
                            window.location.reload();
                            return;
                        } else {
                            throw new Error('Session expired. Please refresh the page to continue.');
                        }
                    }

                    const patientNumberServerMessage = parsedServerError || errorText;
                    if (patientNumberServerMessage.toLowerCase().includes('patient number')) {
                        goToPatientNumberEntryStep(parsedServerError || 'Please check the Patient Number and try again.');
                        return;
                    }
                    
                    throw new Error(`Server error: ${response.status} - ${parsedServerError || errorText}`);
                }
                const result = await response.json();
                
                if (result.success) {
                    // Show success message with receipt option
                    let message = result.message || 'Payment processed successfully!';
                    if (result.warning) {
                        message += '\n\nWarning: ' + result.warning;
                    }
                    if (result.change !== undefined) {
                        message += '\n\nChange: $' + result.change.toFixed(2);
                    }
                    
                    // Refresh credit balance if credit was applied successfully
                    if (result.credit_amount && result.credit_amount > 0 && result.credit_success !== false) {
                        setTimeout(() => {
                            loadPatientCreditBalance();
                            checkCreditBalanceAvailability();
                        }, 500);
                    }
                    
                    // For external/manual payments, hold final receipt until invoice is fully paid
                    const splitMethods = ['terminal_card', 'cash', 'affirm', 'zip', 'afterpay', 'sezzle', 'check', 'fsa_hsa', 'credit_card'];
                    if (splitMethods.includes((paymentMethod || '').toLowerCase())) {
                        // Handle terminal partials client-side; finalize when fully paid
                        await (async function(result, paidNow, grandTotal, paymentData){
                            window._posPayments = window._posPayments || [];
                            window._posPayments.push({method: (paymentMethod || paymentData.method || '').toLowerCase(), amount: paidNow, reference: result.transaction_id || result.reference || ''});

                            const paidTotal = window._posPayments.reduce((s,p)=>s + (parseFloat(p.amount)||0), 0);
                            const due = Math.max(0, Math.round((grandTotal - paidTotal) * 100)/100);

                            if (due > 0) {
                                renderCheckoutPaymentSummary();
                                // Clear amount field and focus for next partial entry
                                const paymentAmountEl = document.getElementById('payment-amount');
                                if (paymentAmountEl) { paymentAmountEl.value = ''; paymentAmountEl.focus(); }
                                return;
                            }

                            renderCheckoutPaymentSummary();
                            await finalizePendingCheckoutInvoice(paymentData);
                        })(result, (paymentData && typeof paymentData.amount === 'number') ? paymentData.amount : paymentAmount, grandTotal, paymentData);
                        setButtonState(false);
                        return;
                    }

                    // Ask user if they want to print receipt (legacy flow)
                    if (result.receipt_number) {
                        const printReceipt = confirm(message + '\n\nWould you like to print a receipt?');
                        if (printReceipt) {
                            // Open receipt in new window with improved positioning
                            const currentPid = <?php echo $pid ?: 'null'; ?>;
                            if (currentPid) {
                                const receiptUrl = `pos_receipt.php?receipt_number=${result.receipt_number}&pid=${currentPid}`;
                                console.log('Receipt URL:', receiptUrl);
                                console.log('Receipt Number:', result.receipt_number);
                                console.log('PID for receipt:', currentPid);
                                // Center the receipt window with more reliable positioning
                                const left = Math.max(0, (window.screen.availWidth - 400) / 2);
                                const top = Math.max(0, (window.screen.availHeight - 600) / 2);
                                const receiptWindow = window.open(receiptUrl, '_blank', `width=400,height=600,left=${left},top=${top},scrollbars=yes,resizable=yes,status=no,location=no,toolbar=no,menubar=no`);
                                // Focus the receipt window
                                if (receiptWindow) {
                                    receiptWindow.focus();
                                }
                            } else {
                                // Fallback if PID is not available
                                const receiptUrl = `pos_receipt.php?receipt_number=${result.receipt_number}`;
                                console.log('Receipt URL (no PID):', receiptUrl);
                                // Center the receipt window with more reliable positioning
                                const left = Math.max(0, (window.screen.availWidth - 400) / 2);
                                const top = Math.max(0, (window.screen.availHeight - 600) / 2);
                                const receiptWindow = window.open(receiptUrl, '_blank', `width=400,height=600,left=${left},top=${top},scrollbars=yes,resizable=yes,status=no,location=no,toolbar=no,menubar=no`);
                                // Focus the receipt window
                                if (receiptWindow) {
                                    receiptWindow.focus();
                                }
                            }
                        }
                    } else {
                        alert(message);
                    }
                    
                    // Clear cart and close modal
                    cart = [];
                    tenDollarOffEnabled = false;
                    clearPersistentCart(); // Clear persistent storage
                    syncTenDollarOffToggleState();
                    updateOrderSummary();
                    removeRestoredCartIndicator();
                    
                    // Show success and navigate to patient transaction history
                    setTimeout(() => {
                        const currentPid = <?php echo $pid ?: 'null'; ?>;
                        if (currentPid) {
                            // Navigate to patient transaction history
                            window.location.href = `pos_patient_transaction_history.php?pid=${currentPid}`;
                        } else {
                            // Fallback to Finder if no patient
                            navigateBackToFinder();
                        }
                    }, 1000);
                    
                } else {
                    if ((result.error || '').toLowerCase().includes('patient number')) {
                        goToPatientNumberEntryStep(result.error);
                        return;
                    }
                    throw new Error(result.error || 'Payment failed');
                }
                
            } catch (error) {
                console.error('Payment processing failed:', error);
                if ((error.message || '').toLowerCase().includes('patient number')) {
                    goToPatientNumberEntryStep(error.message);
                } else {
                    alert('Payment failed: ' + error.message);
                }
            } finally {
                // Re-enable button
                processBtn.disabled = false;
                processBtn.innerHTML = '<i class="fas fa-lock"></i> Process Payment';
            }
        }

        // Calculate and display change
        function calculateChange() {
            // Extract total from the payment-total element (which now contains HTML)
            const paymentTotalElement = document.getElementById('payment-total');
            let paymentTotal = 0;

            // Prefer DUE (not TOTAL) for cash short/change calculation
            const dueNode = paymentTotalElement?.querySelector('.metric.due .metric-value');
            const totalNode = paymentTotalElement?.querySelector('.metric.total .metric-value');

            // Try DUE first
            if (dueNode && dueNode.textContent) {
                paymentTotal = parseFloat(dueNode.textContent.replace(/[^\d.]/g, '')) || 0;
            }
            // Fallback to TOTAL if DUE not found (safe fallback)
            else if (totalNode && totalNode.textContent) {
                paymentTotal = parseFloat(totalNode.textContent.replace(/[^\d.]/g, '')) || 0;
            }
            // Last fallback
            else {
                paymentTotal = parseFloat((paymentTotalElement?.textContent || '').replace(/[^\d.]/g, '')) || 0;
}
            
            const amountTendered = parseFloat(document.getElementById('payment-amount').value || '0') || 0;
            const change = amountTendered - paymentTotal;

            const changeDisplay = document.getElementById('cash-change');
            if (change >= 0) {
                changeDisplay.textContent = 'Change: $' + change.toFixed(2);
                changeDisplay.style.color = '#27ae60'; // Green for positive change
            } else {
                changeDisplay.textContent = 'Short: $' + Math.abs(change).toFixed(2);
                changeDisplay.style.color = '#e74c3c'; // Red for negative change
            }
        }

        // Open transaction history
        function openTransactionHistory() {
            const pid = <?php echo $pid ?: 0; ?>;
            if (pid) {
                const historyUrl = `pos_patient_transaction_history.php?pid=${pid}`;
                window.location.href = historyUrl;
            } else {
                alert('<?php echo xla('No patient selected'); ?>');
            }
        }

        // Multi-step navigation
        let currentStep = 1;
        const totalSteps = 2;
        let patientNumberValidationRequest = 0;
        let patientNumberValidationTimer = null;
        let patientNumberIsValid = <?php echo $todays_patient_number ? 'true' : 'false'; ?>;
       // --- Mandatory selection rule: New OR Follow-Up OR Returning OR Injection must be selected ---
function hasRequiredSelection() {
    // New/Follow-Up/Returning is stored reliably here
    const hidden = document.getElementById('visit-type-hidden');
    const vt = hidden ? String(hidden.value || '').trim() : '';
    const hasVisitType = (vt === 'N' || vt === 'F' || vt === 'R');

    // Injection: allow either a checkbox OR an injection item in cart
    const injChk = document.getElementById('injection-checkbox');
    const hasInjectionChecked = !!(injChk && injChk.checked);

    const hasInjectionInCart = Array.isArray(cart) && cart.some(it =>
        (it && typeof it.id === 'string' && it.id.startsWith('injection_')) ||
        (it && (it.display_name === 'Injection' || it.name === 'Injection'))
    );

    return hasVisitType || hasInjectionChecked || hasInjectionInCart;
}

function requireSelectionOrStop() {
    if (hasRequiredSelection()) return true;
    alert('Please select at least one: New, Follow-Up, Returning, or Injection.');
    return false;
}

        function showPatientNumberFieldError(message, showPopup = false) {
            const errorBox = document.getElementById('patient-number-error');
            const input = document.getElementById('manual-patient-number');
            if (errorBox) {
                errorBox.textContent = message;
                errorBox.style.display = 'none';
            }
            if (input) {
                input.style.borderColor = '#dc3545';
                input.style.boxShadow = '0 0 0 0.2rem rgba(220,53,69,0.15)';
                if (!input.readOnly && !showPopup) {
                    input.focus();
                    input.select();
                }
            }
            if (showPopup && message) {
                alert(message);
            }
        }

        function clearPatientNumberInlineError() {
            const errorBox = document.getElementById('patient-number-error');
            const input = document.getElementById('manual-patient-number');
            if (errorBox) {
                errorBox.textContent = '';
                errorBox.style.display = 'none';
            }
            if (input) {
                input.style.borderColor = '';
                input.style.boxShadow = '';
            }
        }

        function getCurrentPatientNumberValue() {
            const input = document.getElementById('manual-patient-number');
            if (!input) {
                return '';
            }

            return String(input.value || '').trim();
        }

        async function validatePatientNumberInline(showPopup = false) {
            const input = document.getElementById('manual-patient-number');
            const pid = <?php echo (int) ($pid ?: 0); ?>;
            if (!input || input.readOnly) {
                patientNumberIsValid = true;
                return true;
            }

            const patientNumber = (input.value || '').trim();
            if (!patientNumber) {
                patientNumberIsValid = false;
                showPatientNumberFieldError('Please enter a Patient Number.', showPopup);
                return false;
            }

            if (!/^\d+$/.test(patientNumber) || Number(patientNumber) <= 0) {
                patientNumberIsValid = false;
                showPatientNumberFieldError('Please enter a valid numeric Patient Number.', showPopup);
                return false;
            }

            const requestId = ++patientNumberValidationRequest;

            try {
                const response = await fetch(`pos_modal.php?action=validate_patient_number&pid=${encodeURIComponent(pid)}&patient_number=${encodeURIComponent(patientNumber)}`, {
                    credentials: 'same-origin'
                });
                const result = await response.json();

                if (requestId !== patientNumberValidationRequest) {
                    return patientNumberIsValid;
                }

                if (!response.ok || !result.success) {
                    patientNumberIsValid = false;
                    showPatientNumberFieldError((result && result.error) ? result.error : 'Unable to validate Patient Number right now.', showPopup);
                    return false;
                }

                if (!result.valid) {
                    patientNumberIsValid = false;
                    showPatientNumberFieldError(result.message || 'Please check the Patient Number.', showPopup);
                    return false;
                }

                patientNumberIsValid = true;
                clearPatientNumberInlineError();
                return true;
            } catch (error) {
                patientNumberIsValid = false;
                showPatientNumberFieldError('Unable to validate Patient Number right now.', showPopup);
                return false;
            }
        }

        function schedulePatientNumberValidation() {
            const input = document.getElementById('manual-patient-number');
            if (!input || input.readOnly) {
                return;
            }

            clearPatientNumberInlineError();
            patientNumberIsValid = false;

            if (patientNumberValidationTimer) {
                clearTimeout(patientNumberValidationTimer);
            }

            patientNumberValidationTimer = setTimeout(() => {
                validatePatientNumberInline(false);
            }, 300);
        }

        function goToPatientNumberEntryStep(message = '') {
            if (typeof currentStep !== 'undefined' && currentStep !== 1) {
                const currentStepEl = document.getElementById('step-' + currentStep);
                const firstStepEl = document.getElementById('step-1');
                if (currentStepEl) {
                    currentStepEl.classList.remove('active');
                }
                currentStep = 1;
                if (firstStepEl) {
                    firstStepEl.classList.add('active');
                }
                updateStepNavigation();
            }

            if (message) {
                showPatientNumberFieldError(message, false);
            }
        }

        async function nextStep() {
            const patientNumber = getCurrentPatientNumberValue();
            clearPatientNumberInlineError();

            if (!patientNumber || !/^\d+$/.test(patientNumber)) 
                {
                     showPatientNumberFieldError("Please enter a valid numeric Patient Number.", false);
                     return;
                }
            if (!(await validatePatientNumberInline(false))) {
                return;
            }
            if (!requireSelectionOrStop()) return;
            if (currentStep < totalSteps) {
                // Validate current step
                if (currentStep === 1) {
                    // Step 1 validation - check if patient is selected
                    const currentPid = <?php echo $pid ?: 'null'; ?>;
                    if (!currentPid) {
                        alert('Please select a patient before proceeding to checkout');
                        return;
                    }
                    
                    // Step 1 validation - check if items in cart
                    if (cart.length === 0) {
                        alert('Please add items to cart before proceeding to checkout');
                        return;
                    }
                    
                    // WEIGHT VALIDATION: Check if patient has weight recorded for today
                    const weightValidationResult = await validateSessionWeight();
                    if (!weightValidationResult.hasWeight) {
                        alert(`Weight Recording Required\n\nPatient weight must be recorded before proceeding to checkout.\n\nPlease use the Weight Tracker to record the patient's weight for today.`);
                        return;
                    }
                    
                    // For Hoover facility, validate that QTY + Remaining Dispense >= Marketplace Dispense + Administer
                    if (isHooverFacility) {
                        await refreshCartRemainingDispenseState();
                        const invalidItems = [];
                        cart.forEach((item, idx) => {
                            // Skip consultation items
                            if (item.id && item.id.startsWith('consultation_')) {
                                return;
                            }
                            
                            // Only validate medical products
                            if (item.drug_id || item.lot) {
                                // Ensure all values are numbers
                                const qty = parseFloat(item.quantity) || 0;
                                const marketplaceDispense = parseFloat(item.marketplace_dispense_quantity) || 0;
                                const administer = parseFloat(item.administer_quantity) || 0;
                                const totalRemainingQuantity = parseFloat(item.total_remaining_quantity) || 0;
                                const totalAvailable = qty + totalRemainingQuantity;
                                const totalRequested = marketplaceDispense + administer;
                                const isAdministerOnlyFlow = isHooverAdministerOnlyFlow(item);
                                
                                // Validate: Marketplace Dispense + Administer <= QTY + Remaining Dispense
                                // Use a small epsilon to handle floating point precision issues
                                const epsilon = 0.0001;
                                
                                if (!isAdministerOnlyFlow && totalRequested > totalAvailable + epsilon) {
                                    invalidItems.push({
                                        name: item.name || item.display_name || 'Item',
                                        qty: qty,
                                        marketplaceDispense: marketplaceDispense,
                                        administer: administer,
                                        totalRemainingQuantity: totalRemainingQuantity,
                                        totalAvailable: totalAvailable,
                                        totalRequested: totalRequested
                                    });
                                }
                            }
                        });
                        
                        if (invalidItems.length > 0) {
                            let errorMsg = 'Cannot proceed to payment. Please ensure Marketplace Dispense + Administer ≤ QTY + Remaining Dispense for the following items:\n\n';
                            invalidItems.forEach(item => {
                                errorMsg += `• ${item.name}: Marketplace (${item.marketplaceDispense}) + Administer (${item.administer}) = ${item.totalRequested} exceeds available quantity (QTY: ${item.qty} + Remaining: ${item.totalRemainingQuantity} = ${item.totalAvailable})\n`;
                            });
                            alert(errorMsg);
                            return;
                        }
                    }
                    
                    // Load checkout content
                    loadCheckoutContent();
                    
                    // Load credit balance for payment with delay to ensure elements are ready
                    setTimeout(() => {
                        loadCreditBalanceForPayment();
                        checkCreditBalanceAvailability();
                    }, 200);
                }
                
                // Hide current step
                document.getElementById('step-' + currentStep).classList.remove('active');
                
                // Show next step
                currentStep++;
                document.getElementById('step-' + currentStep).classList.add('active');
                
                // Update navigation
                updateStepNavigation();
            }
        }

        function prevStep() {
            if (currentStep > 1) {
                // Hide current step
                document.getElementById('step-' + currentStep).classList.remove('active');
                
                // Show previous step
                currentStep--;
                document.getElementById('step-' + currentStep).classList.add('active');
                
                // Update navigation
                updateStepNavigation();
            }
        }

        function updateStepNavigation() {
            // Step navigation removed - no longer needed
        }

        // Function to refresh patient data dynamically
        function refreshPatientData() {
            const pid = <?php echo $pid ?: 'null'; ?>;
            if (!pid) return;
            
            // Fetch updated patient data
            fetch(`pos_modal.php?action=get_patient_data&pid=${pid}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.text(); // Get as text first to debug
                })
                .then(text => {
                    try {
                        const data = JSON.parse(text);
                        return data;
                    } catch (e) {
                        console.error('Failed to parse JSON response:', text);
                        throw new Error('Invalid JSON response: ' + text.substring(0, 100));
                    }
                })
                .then(data => {
                    if (data.success) {
                        // Update patient details with null checks
                        const patientNameElement = document.getElementById('patient-name');
                        const patientIdElement = document.getElementById('patient-id');
                        const patientDobElement = document.getElementById('patient-dob');
                        const patientPhoneElement = document.getElementById('patient-phone');
                        const patientEmailElement = document.getElementById('patient-email');
                        
                        if (patientNameElement) {
                            patientNameElement.textContent = data.patient.fname + ' ' + data.patient.lname;
                        }
                        if (patientIdElement) {
                            patientIdElement.textContent = data.patient.pubpid;
                        }
                        if (patientDobElement) {
                            patientDobElement.textContent = data.patient.DOB;
                        }
                        if (patientPhoneElement) {
                            patientPhoneElement.textContent = data.patient.phone_cell || data.patient.phone_home || 'N/A';
                        }
                        if (patientEmailElement) {
                            patientEmailElement.textContent = data.patient.email || 'N/A';
                        }
                        
                        // Update balance if available
                        if (data.patient.balance > 0) {
                            const balanceElement = document.getElementById('patient-balance');
                            if (balanceElement) {
                                balanceElement.textContent = '$' + parseFloat(data.patient.balance).toFixed(2);
                            }
                        }
                    }
                })
                .catch(error => {
                    console.error('Error refreshing patient data:', error);
                });
        }

        function loadCheckoutContent() {
            renderCheckoutPaymentSummary();
            
            // Set up payment amount input
            const paymentAmountInput = document.getElementById('payment-amount');
            if (paymentAmountInput) {
                paymentAmountInput.addEventListener('input', function() {
                    calculateChange();
                });
            }
            
            // Reset payment method to None so staff must choose it for each sale
            const paymentMethodSelect = document.getElementById('payment-method');
            if (paymentMethodSelect) {
                paymentMethodSelect.value = '';
            }
            
            // Clear any previous errors
            const ceNode = document.getElementById('card-errors');
            if (ceNode) ceNode.textContent = '';
            const ccNode = document.getElementById('cash-change');
            if (ccNode) ccNode.textContent = '';
        }

        // Patient Search Functions
        function showPatientSearch() {
            document.getElementById('patient-search-section').style.display = 'block';
            document.getElementById('patient-search-input').focus();
            
            // Disable checkout button when search is shown (no patient selected)
            const checkoutBtn = document.getElementById('checkout-btn');
            if (checkoutBtn) {
                checkoutBtn.disabled = true;
                checkoutBtn.style.opacity = '0.6';
                checkoutBtn.style.cursor = 'not-allowed';
                checkoutBtn.title = 'Please select a patient first';
            }
        }

        function closePatientSearch() {
            document.getElementById('patient-search-section').style.display = 'none';
            document.getElementById('patient-search-results').innerHTML = '';
            document.getElementById('patient-search-results').style.display = 'none';
            document.getElementById('patient-search-input').value = '';
        }

        function loadRecentTransactions() {
            const container = document.getElementById('recent-transactions-content');
            const dateInput = document.getElementById('recent-transactions-date');
            if (!container) {
                return;
            }

            container.innerHTML = `<div style="text-align: center; color: #6c757d; padding: 30px 0;">
                <i class="fas fa-spinner fa-spin" style="margin-right: 8px;"></i> Loading recent transactions...
            </div>`;

            const selectedDate = dateInput ? dateInput.value.trim() : '';
            const params = new URLSearchParams({
                action: 'get_recent_transactions',
                limit: '8'
            });
            if (selectedDate) {
                params.set('transaction_date', selectedDate);
            }

            fetch(`pos_modal.php?${params.toString()}`)
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.error || 'Unable to load recent transactions');
                    }

                    renderRecentTransactions(data.transactions || [], selectedDate);
                })
                .catch(error => {
                    console.error('Error loading recent transactions:', error);
                    container.innerHTML = `<div style="text-align: center; color: #dc3545; padding: 30px 0;">
                        <i class="fas fa-exclamation-triangle" style="margin-right: 8px;"></i> ${error.message}
                    </div>`;
                });
        }

        function clearRecentTransactionsDateFilter() {
            const dateInput = document.getElementById('recent-transactions-date');
            if (dateInput) {
                dateInput.value = '';
            }
            loadRecentTransactions();
        }

        function renderRecentTransactions(transactions, selectedDate = '') {
            const container = document.getElementById('recent-transactions-content');
            if (!container) {
                return;
            }

            if (!transactions.length) {
                container.innerHTML = `<div style="text-align: center; color: #6c757d; padding: 30px 0;">
                    <i class="fas fa-info-circle" style="margin-right: 8px;"></i> ${selectedDate ? `No transactions found for ${selectedDate}` : 'No recent transactions found'}
                </div>`;
                return;
            }

            container.innerHTML = `
                <div style="margin-bottom: 12px; font-size: 13px; color: #6c757d;">
                    ${selectedDate ? `Showing ${transactions.length} transaction(s) for ${selectedDate}` : `Showing latest ${transactions.length} transaction(s)`}
                </div>
                <div style="display: grid; gap: 10px;">
                    ${transactions.map(transaction => {
                        const createdAt = transaction.created_date ? new Date(transaction.created_date.replace(' ', 'T')) : null;
                        const createdLabel = createdAt && !Number.isNaN(createdAt.getTime())
                            ? `${createdAt.toLocaleDateString()} ${createdAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`
                            : 'Unknown date';
                        const patientName = transaction.patient_name || 'Walk-in / Unknown';
                        const typeLabel = String(transaction.transaction_type || '')
                            .replace(/_/g, ' ')
                            .replace(/\b\w/g, letter => letter.toUpperCase());

                        return `
                            <div style="display: grid; grid-template-columns: minmax(220px, 1.35fr) minmax(280px, 1fr) auto auto; gap: 12px; align-items: center; padding: 14px 16px; border: 1px solid #e9ecef; border-radius: 8px; background: #fafbfc;">
                                <div>
                                    <div style="font-weight: 600; color: #333;">${patientName}</div>
                                    <div style="font-size: 12px; color: #6c757d;">${createdLabel}</div>
                                </div>
                                <div style="justify-self: start; text-align: left;">
                                    <div style="font-size: 13px; color: #495057;">${typeLabel || 'Transaction'}</div>
                                    <div style="font-size: 12px; color: #6c757d;">Receipt: ${transaction.receipt_number || 'N/A'}</div>
                                </div>
                                <div style="font-weight: 700; color: #198754; white-space: nowrap;">$${Number(transaction.amount || 0).toFixed(2)}</div>
                                <div>
                                    ${transaction.receipt_number
                                        ? `<a href="pos_receipt.php?receipt_number=${encodeURIComponent(transaction.receipt_number)}${transaction.pid ? `&pid=${encodeURIComponent(transaction.pid)}` : ''}" target="_blank" style="display: inline-flex; align-items: center; gap: 6px; background: #0d6efd; color: white; text-decoration: none; border-radius: 6px; padding: 8px 12px; font-size: 12px; font-weight: 600; white-space: nowrap;">
                                            <i class="fas fa-receipt"></i> View Receipt
                                           </a>`
                                        : `<span style="font-size: 12px; color: #adb5bd;">No receipt</span>`}
                                </div>
                            </div>
                        `;
                    }).join('')}
                </div>
            `;
        }

        // Initialize patient search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('patient-search-input');
            if (searchInput) {
                let searchTimeout;
                
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    const searchTerm = this.value.trim();
                    
                    if (searchTerm.length >= 2) {
                        searchTimeout = setTimeout(() => {
                            searchPatients(searchTerm);
                        }, 300);
                    } else {
                        document.getElementById('patient-search-results').innerHTML = '';
                        document.getElementById('patient-search-results').style.display = 'none';
                    }
                });

                // Handle Enter key
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        const searchTerm = this.value.trim();
                        if (searchTerm.length >= 2) {
                            searchPatients(searchTerm);
                        }
                    }
                });
            }
            
            // Update checkout button state on page load
            updateCheckoutButtonState();
            
            // Hide POS interface if no patient is selected
            const currentPid = <?php echo $pid ?: 'null'; ?>;
            if (!currentPid) {
                hidePOSInterface();
                loadRecentTransactions();
            }
        });
        
        function updateCheckoutButtonState() {
            const checkoutBtn = document.getElementById('checkout-btn');
            const currentPid = <?php echo $pid ?: 'null'; ?>;
            
            if (checkoutBtn) {
                if (!currentPid) {
                    checkoutBtn.disabled = true;
                    checkoutBtn.style.opacity = '0.6';
                    checkoutBtn.style.cursor = 'not-allowed';
                    checkoutBtn.title = 'Please select a patient first';
                } else {
                    checkoutBtn.disabled = false;
                    checkoutBtn.style.opacity = '1';
                    checkoutBtn.style.cursor = 'pointer';
                    checkoutBtn.title = 'Proceed to payment';
                }
            }
        }

        function searchPatients(searchTerm) {
            const resultsContainer = document.getElementById('patient-search-results');
            resultsContainer.style.display = 'block';
            resultsContainer.innerHTML = '<div class="search-result-item" style="padding: 20px; text-align: center; color: #6c757d;"><i class="fas fa-spinner fa-spin"></i> Searching...</div>';
            
            // Make AJAX request to search patients
            fetch('pos_modal.php?action=search_patients&search=' + encodeURIComponent(searchTerm))
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.patients && data.patients.length > 0) {
                        let html = '';
                        data.patients.forEach(patient => {
                            const balance = patient.balance > 0 ? ` (Balance: $${parseFloat(patient.balance).toFixed(2)})` : '';
                            const gender = patient.sex ? patient.sex.charAt(0).toUpperCase() + patient.sex.slice(1) : 'N/A';
                            html += `
                                <div class="search-result-item" onclick="selectPatient(${patient.pid})" style="background: white;">
                                    <div class="search-result-info">
                                        <div class="search-result-name">${patient.fname} ${patient.lname}</div>
                                        <div class="search-result-details" style="display: flex; gap: 30px; flex-wrap: wrap; margin-top: 8px; font-size: 16px;">
                                            <span style="font-size: 16px; color: #6c757d; font-weight: 500;">Gender: ${gender}</span>
                                            <span style="font-size: 16px; color: #6c757d; font-weight: 500;">DOB: ${patient.dob}</span>
                                            <span style="font-size: 16px; color: #6c757d; font-weight: 500;">Phone: ${patient.phone || 'N/A'}</span>
                                            ${patient.balance > 0 ? `<span style="font-size: 16px; color: #dc3545; font-weight: 500;">Balance: $${parseFloat(patient.balance).toFixed(2)}</span>` : ''}
                                        </div>
                                    </div>
                                </div>
                            `;
                        });
                        resultsContainer.innerHTML = html;
                    } else if (data.success && data.patients && data.patients.length === 0) {
                        resultsContainer.innerHTML = '<div class="search-result-item" style="padding: 20px; text-align: center; color: #6c757d;">No patients found</div>';
                    } else {
                        // Show server error message
                        const errorMsg = data.error || 'Unknown error occurred';
                        resultsContainer.innerHTML = `<div class="search-result-item" style="padding: 20px; text-align: center; color: #dc3545;">Error: ${errorMsg}</div>`;
                    }
                })
                .catch(error => {
                    console.error('Error searching patients:', error);
                    resultsContainer.innerHTML = '<div class="search-result-item" style="padding: 20px; text-align: center; color: #dc3545;">Error searching patients. Please try again.</div>';
                });
        }

        function selectPatient(pid) {
            // Close the search section
            closePatientSearch();
            
            // Show search section and order summary for the selected patient
            showPOSInterface();
            
            // Enable the checkout button
            const checkoutBtn = document.getElementById('checkout-btn');
            if (checkoutBtn) {
                checkoutBtn.disabled = false;
                checkoutBtn.style.opacity = '1';
                checkoutBtn.style.cursor = 'pointer';
            }
            
            // Reload the page with the new patient ID
            const currentUrl = new URL(window.location);
            currentUrl.searchParams.set('pid', pid);
            window.location.href = currentUrl.toString();
        }
        
        function showPOSInterface() {
            // Show search section and order summary
            const searchSection = document.querySelector('.search-section');
            const orderSummary = document.querySelector('.order-summary');
            
            if (searchSection) searchSection.style.display = 'block';
            if (orderSummary) orderSummary.style.display = 'block';
        }
        
        function hidePOSInterface() {
            // Hide search section and order summary
            const searchSection = document.querySelector('.search-section');
            const orderSummary = document.querySelector('.order-summary');
            
            if (searchSection) searchSection.style.display = 'none';
            if (orderSummary) orderSummary.style.display = 'none';
        }

        // Price Override Functions
        let overrideMode = false;
        let remoteOverrideCodeRequested = false;

        function escapeOverrideHtml(value) {
            return String(value || '').replace(/[&<>"']/g, function(char) {
                return ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                })[char];
            });
        }

        function buildRemoteAdminEmailOptions() {
            if (!Array.isArray(priceOverrideAdminEmails) || priceOverrideAdminEmails.length === 0) {
                return '<option value="">No administrator emails configured</option>';
            }

            return '<option value="">Select administrator email</option>' + priceOverrideAdminEmails.map(admin => {
                const label = `${admin.name || admin.username} - ${admin.email}`;
                return `<option value="${escapeOverrideHtml(admin.username)}">${escapeOverrideHtml(label)}</option>`;
            }).join('');
        }

        function refreshRemoteAdminEmailOptions() {
            return fetch('pos_modal.php?action=get_price_override_admins', {
                method: 'GET',
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => {
                if (!data || !data.success || !Array.isArray(data.admins)) {
                    throw new Error((data && data.error) ? data.error : 'Failed to load administrator emails');
                }

                priceOverrideAdminEmails = data.admins;
                const remoteAdminSelect = document.getElementById('remote-admin-username');
                if (remoteAdminSelect) {
                    const currentValue = remoteAdminSelect.value || '';
                    remoteAdminSelect.innerHTML = buildRemoteAdminEmailOptions();
                    if (currentValue && priceOverrideAdminEmails.some(admin => admin.username === currentValue)) {
                        remoteAdminSelect.value = currentValue;
                    }
                }

                return data.admins;
            })
            .catch(error => {
                console.error('Error refreshing price override admin emails:', error);
                return [];
            });
        }

        function openGlobalOverride() {
            const remoteAdminOptions = buildRemoteAdminEmailOptions();
            remoteOverrideCodeRequested = false;
            const content = `
                <div style="margin-bottom: 20px;">
                    <p style="color: #666; margin-bottom: 15px;">
                        <strong>Global Price Override</strong><br>
                        This will allow you to edit all prices in the current order. You will need administrator approval to proceed.
                    </p>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #333;">
                            <i class="fas fa-user-shield" style="color: #ffc107;"></i> Administrator Credentials
                        </label>
                        <input type="text" id="admin-username" placeholder="Enter administrator username"
                               style="width: 100%; padding: 12px; border: 2px solid #e1e5e9; border-radius: 6px; font-size: 16px; margin-bottom: 10px;">
                        <input type="password" id="admin-password" placeholder="Enter administrator password"
                               style="width: 100%; padding: 12px; border: 2px solid #e1e5e9; border-radius: 6px; font-size: 16px;">

                        <div style="margin: 14px 0; padding: 12px; border: 1px solid #e1e5e9; border-radius: 6px; background: #f8f9fa;">
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 10px;">
                                <div style="font-weight: 600; color: #333;">
                                    <i class="fas fa-envelope" style="color: #ffc107;"></i> Remote Approval Code
                                </div>
                                <button type="button" id="request-remote-code-btn" onclick="requestRemoteOverrideCode()"
                                        style="background: #ffc107; color: #333; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600;">
                                    <i class="fas fa-paper-plane"></i> Email Code to Admin
                                </button>
                            </div>
                            <select id="remote-admin-username"
                                    style="width: 100%; padding: 10px 12px; border: 2px solid #e1e5e9; border-radius: 6px; font-size: 15px; margin-bottom: 10px;">
                                ${remoteAdminOptions}
                            </select>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <input type="text" id="remote-override-code" inputmode="numeric" maxlength="6" placeholder="Enter emailed code"
                                       style="flex: 1; min-width: 180px; padding: 10px 12px; border: 2px solid #e1e5e9; border-radius: 6px; font-size: 15px;">
                                <button type="button" id="use-remote-code-btn" onclick="verifyRemoteOverrideCode()"
                                        style="background: #198754; color: white; border: none; padding: 10px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600;">
                                    <i class="fas fa-key"></i> Use Code
                                </button>
                            </div>
                            <div id="remote-override-status" style="display:none; margin-top:8px; font-size:13px; line-height:1.4;"></div>
                            <small style="display:block; margin-top:8px; color:#6c757d;">
                                Add notes, then email a one-time code to an administrator on this list.
                            </small>
                        </div>
                        <label style="display: block; margin: 15px 0 8px; font-weight: 600; color: #333;">
                        <i class="fas fa-sticky-note" style="color: #ffc107;"></i> Override Notes
                        </label>

                        <textarea id="override-notes"
                        placeholder="Enter reason for price override..."
                        style="width: 100%; padding: 12px; border: 2px solid #e1e5e9; border-radius: 6px; font-size: 16px; min-height: 90px; resize: vertical;"></textarea>

                        <small style="display:block; margin-top:6px; color:#6c757d;">
                        Reason is required for audit tracking.
                        </small>
                    </div>
                    
                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button onclick="closeOverrideModal()" 
                                style="background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 14px;">
                            Cancel
                        </button>
                        <button onclick="verifyAndEnableOverride()" 
                                style="background: #ffc107; color: #333; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600;">
                            <i class="fas fa-check"></i> Enable Override
                        </button>
                    </div>
                </div>
            `;
            
            document.getElementById('override-content').innerHTML = content;
            document.getElementById('override-modal').style.display = 'block';
            refreshRemoteAdminEmailOptions();
            
            // Focus on password field
            setTimeout(() => {
                document.getElementById('admin-password').focus();
            }, 100);
        }

        function closeOverrideModal() {
            document.getElementById('override-modal').style.display = 'none';
            remoteOverrideCodeRequested = false;
            const n = document.getElementById('override-notes');
            if (n) n.value = '';
            const adminUsername = document.getElementById('admin-username');
            const adminPassword = document.getElementById('admin-password');
            const remoteAdminUsername = document.getElementById('remote-admin-username');
            const remoteCode = document.getElementById('remote-override-code');
            if (adminUsername) adminUsername.value = '';
            if (adminPassword) adminPassword.value = '';
            if (remoteAdminUsername) remoteAdminUsername.value = '';
            if (remoteCode) remoteCode.value = '';

        }

        // Defective Medicine Functions - REMOVED
        // Defective medicine reporting is now handled at inventory level only
        // Managers should use the inventory system to report defective products by lot number

        // Report individual transaction as defective (from dispense tracking details)
        function reportTransactionAsDefective(drugId, productName, lotNumber, administeredQty, transactionDate, transactionId) {
            // For transaction-level reporting, we use the administered quantity as the maximum
            // This ensures we can only report defects on what was actually given to the patient
            
            if (administeredQty <= 0) {
                showNotification('This transaction has no administered quantities to report as defective', 'warning');
                return;
            }

            // Toggle inline defective form for this transaction
            const existingForm = document.getElementById(`defective-form-transaction-${transactionId}`);
            if (existingForm) {
                existingForm.remove();
                return;
            }
            
            // Create inline form for transaction
            const formHtml = `
                <tr id="defective-form-transaction-${transactionId}" style="background: #f8f9fa; border: 1px solid #dee2e6;">
                    <td colspan="8" style="padding: 15px;">
                        <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                            <div style="flex: 1; min-width: 150px;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #495057;">Quantity (Max: ${administeredQty})</label>
                                <input type="number" id="defect-qty-transaction-${transactionId}" min="1" max="${administeredQty}" value="1" 
                                       style="width: 100%; padding: 6px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; box-sizing: border-box;">
                            </div>
                            <div style="flex: 1; min-width: 200px;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #495057;">Reason</label>
                                <input type="text" id="defect-reason-transaction-${transactionId}" placeholder="Enter defect reason" 
                                       style="width: 100%; padding: 6px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; box-sizing: border-box;">
                            </div>
                            <div style="flex: 1; min-width: 120px;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #495057;">Type</label>
                                <select id="defect-type-transaction-${transactionId}" style="width: 100%; padding: 6px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; box-sizing: border-box;">
                                    <option value="defective">Defective</option>
                                    <option value="faulty">Faulty</option>
                                    <option value="expired">Expired</option>
                                    <option value="contaminated">Contaminated</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div style="display: flex; gap: 8px; align-items: end;">
                                <button onclick="submitTransactionDefectiveReport(${drugId}, '${productName}', '${lotNumber}', ${administeredQty}, '${transactionDate}', ${transactionId})" 
                                        style="background: #007bff; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                                    <i class="fas fa-exclamation-triangle"></i> Report
                                </button>
                                <button onclick="document.getElementById('defective-form-transaction-${transactionId}').remove()" 
                                        style="background: #6c757d; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </td>
                </tr>
            `;
            
            // Insert form after the transaction row
            const transactionRow = document.querySelector(`[onclick*="reportTransactionAsDefective(${drugId}, '${productName}', '${lotNumber}', ${administeredQty}, '${transactionDate}', ${transactionId})"]`).closest('tr');
            if (transactionRow) {
                transactionRow.insertAdjacentHTML('afterend', formHtml);
            }
        }

        // Submit defective medicine report from inline form
        function submitDefectiveReport(drugId, productName, safeProductKey) {
            const quantity = parseInt(document.getElementById(`defect-qty-${safeProductKey}`).value);
            const reason = document.getElementById(`defect-reason-${safeProductKey}`).value.trim();
            const defectType = document.getElementById(`defect-type-${safeProductKey}`).value;
            
            if (!reason) {
                showNotification('Please enter a reason for the defective report', 'error');
                return;
            }
            
            if (quantity <= 0) {
                showNotification('Please enter a valid quantity', 'error');
                return;
            }
            
            // Prepare data for reporting
            const reportData = {
                action: 'report_defective',
                drug_id: drugId,
                lot_number: '', // Will be determined by handler
                inventory_id: 0, // Will be determined by handler
                pid: currentPatientId,
                quantity: quantity,
                reason: reason,
                defect_type: defectType,
                notes: `Post-processing defective report from dispense tracking - ${productName}`,
                csrf_token: getCsrfToken()
            };
            
            // Send report to backend
            fetch('defective_medicines_handler_simple.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(reportData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    showNotification(`Inventory updated: ${data.quantity_deducted} units deducted. Remaining QOH: ${data.remaining_qoh}`, 'info');
                    
                    if (data.dispense_tracking_updated) {
                        showNotification(`Dispense tracking updated - ${data.compensation_added} units added back as compensation for defective medicine`, 'success');
                    }
                    
                    if (data.defective_transaction_id) {
                        showNotification(`New defective medicine entry created in dispense tracking (ID: ${data.defective_transaction_id})`, 'info');
                    }
                    
                    // Remove the form and refresh dispense tracking
                    document.getElementById(`defective-form-${safeProductKey}`).remove();
                    loadDispenseTrackingData();
                } else {
                    showNotification(data.error || 'Failed to report defective medicine', 'error');
                }
            })
            .catch(error => {
                console.error('Error reporting defective medicine:', error);
                showNotification('Error reporting defective medicine. Please try again.', 'error');
            });
        }

        // Submit transaction defective medicine report from inline form
        function submitTransactionDefectiveReport(drugId, productName, lotNumber, administeredQty, transactionDate, transactionId) {
            const quantity = parseInt(document.getElementById(`defect-qty-transaction-${transactionId}`).value);
            const reason = document.getElementById(`defect-reason-transaction-${transactionId}`).value.trim();
            const defectType = document.getElementById(`defect-type-transaction-${transactionId}`).value;
            
            if (!reason) {
                showNotification('Please enter a reason for the defective report', 'error');
                return;
            }
            
            if (quantity <= 0) {
                showNotification('Please enter a valid quantity', 'error');
                return;
            }
            
            // Prepare data for reporting
            const reportData = {
                action: 'report_defective',
                drug_id: drugId,
                lot_number: lotNumber,
                inventory_id: 0, // Will be determined by handler
                pid: currentPatientId,
                quantity: quantity,
                reason: reason,
                defect_type: defectType,
                notes: `Post-processing defective report from transaction ID ${transactionId} on ${transactionDate} - ${productName} (Lot: ${lotNumber}, Administered: ${administeredQty})`,
                transaction_id: transactionId,
                csrf_token: getCsrfToken()
            };
            
            // Send report to backend
            fetch('defective_medicines_handler_simple.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(reportData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    showNotification(`Inventory updated: ${data.quantity_deducted} units deducted. Remaining QOH: ${data.remaining_qoh}`, 'info');
                    
                    if (data.dispense_tracking_updated) {
                        showNotification(`Dispense tracking updated - ${data.compensation_added} units added back as compensation for defective medicine`, 'success');
                    }
                    
                    if (data.defective_transaction_id) {
                        showNotification(`New defective medicine entry created in dispense tracking (ID: ${data.defective_transaction_id})`, 'info');
                    }
                    
                    // Remove the form and refresh dispense tracking
                    document.getElementById(`defective-form-transaction-${transactionId}`).remove();
                    loadDispenseTrackingData();
                } else {
                    showNotification(data.error || 'Failed to report defective medicine', 'error');
                }
            })
            .catch(error => {
                console.error('Error reporting defective medicine:', error);
                showNotification('Error reporting defective medicine. Please try again.', 'error');
            });
        }

        // Report dispense item as defective (from dispense tracking)
        function reportDispenseAsDefective(drugId, productName, safeProductKey) {
            // Check if this product has any administered quantities (only those can be reported as defective)
            const administeredQty = parseInt(document.querySelector(`[data-drug-id="${drugId}"] .product-group td:nth-child(4)`).textContent);
            
            if (administeredQty <= 0) {
                showNotification('This product has no administered quantities. Defective reports only apply to medicines that were actually given to patients.', 'warning');
                return;
            }
            
            // Toggle inline defective form
            const existingForm = document.getElementById(`defective-form-${safeProductKey}`);
            if (existingForm) {
                existingForm.remove();
                return;
            }
            
            // Create inline form
            const formHtml = `
                <div id="defective-form-${safeProductKey}" style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 6px; padding: 15px; margin: 10px 18px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 200px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #856404;">Quantity (Max: ${administeredQty})</label>
                            <input type="number" id="defect-qty-${safeProductKey}" min="1" max="${administeredQty}" value="1" 
                                   style="width: 100%; padding: 8px; border: 1px solid #ffeaa7; border-radius: 4px; font-size: 14px;">
                        </div>
                        <div style="flex: 1; min-width: 200px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #856404;">Reason</label>
                            <input type="text" id="defect-reason-${safeProductKey}" placeholder="Enter defect reason" 
                                   style="width: 100%; padding: 8px; border: 1px solid #ffeaa7; border-radius: 4px; font-size: 14px;">
                        </div>
                        <div style="flex: 1; min-width: 150px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #856404;">Type</label>
                            <select id="defect-type-${safeProductKey}" style="width: 100%; padding: 8px; border: 1px solid #ffeaa7; border-radius: 4px; font-size: 14px;">
                                <option value="defective">Defective</option>
                                <option value="faulty">Faulty</option>
                                <option value="expired">Expired</option>
                                <option value="contaminated">Contaminated</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div style="display: flex; gap: 10px; align-items: end;">
                            <button onclick="submitDefectiveReport(${drugId}, '${productName}', '${safeProductKey}')" 
                                    style="background: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 600;">
                                <i class="fas fa-exclamation-triangle"></i> Report
                            </button>
                            <button onclick="document.getElementById('defective-form-${safeProductKey}').remove()" 
                                    style="background: #6c757d; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 14px;">
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            // Insert form after the product row
            const productRow = document.querySelector(`[data-drug-id="${drugId}"] .product-group`);
            if (productRow) {
                productRow.insertAdjacentHTML('afterend', formHtml);
            }
        }

        // Report individual item as defective - REMOVED
        // Defective medicine reporting is now handled at inventory level only
        // Managers should use the inventory system to report defective products by lot number

        // CSRF Token function
        function getCsrfToken() {
            // Get CSRF token from meta tag or form
            const metaTag = document.querySelector('meta[name="csrf_token"]');
            if (metaTag) {
                return metaTag.getAttribute('content');
            }

            // Fallback to form input
            const formInput = document.querySelector('input[name="csrf_token_form"]');
            if (formInput) {
                return formInput.value;
            }

            return '';
        }

        function getOverrideNotes() {
            const notesEl = document.getElementById('override-notes');
            const notes = notesEl ? notesEl.value.trim() : '';

            if (!notes) {
                showNotification('Please enter a reason for price override (notes)', 'error');
                if (notesEl) notesEl.focus();
                return '';
            }

            window._priceOverrideNotes = notes;
            return notes;
        }

        function activatePriceOverride(notes, successMessage) {
            logPriceOverride(notes).catch(err => console.warn('Price override log failed:', err));
            overrideMode = true;

            const overrideBtn = document.getElementById('override-btn');
            if (overrideBtn) {
                overrideBtn.style.display = 'none';
            }

            const checkoutBtn = document.getElementById('checkout-btn');
            if (checkoutBtn) {
                checkoutBtn.innerHTML = '<i class="fas fa-check"></i> Override Complete';
                checkoutBtn.onclick = completeOverride;
                checkoutBtn.style.background = '#28a745';
            }

            closeOverrideModal();
            renderOrderSummary();

            setTimeout(() => {
                enablePriceEditing();
            }, 100);

            showNotification(successMessage || 'Price override mode enabled! Click on any price to edit it.', 'success');
        }

        function setRemoteOverrideStatus(message, type) {
            const statusEl = document.getElementById('remote-override-status');
            if (!statusEl) {
                return;
            }

            const colors = {
                success: '#198754',
                error: '#dc3545',
                info: '#495057'
            };
            statusEl.style.display = message ? 'block' : 'none';
            statusEl.style.color = colors[type] || colors.info;
            statusEl.textContent = message || '';
        }

        function setRemoteButtonLoading(buttonId, isLoading, loadingText) {
            const button = document.getElementById(buttonId);
            if (!button) {
                return;
            }

            if (isLoading) {
                button.dataset.originalHtml = button.innerHTML;
                button.disabled = true;
                button.style.opacity = '0.7';
                button.style.cursor = 'not-allowed';
                button.innerHTML = `<i class="fas fa-spinner fa-spin"></i> ${loadingText}`;
                return;
            }

            button.disabled = false;
            button.style.opacity = '1';
            button.style.cursor = 'pointer';
            if (button.dataset.originalHtml) {
                button.innerHTML = button.dataset.originalHtml;
            }
        }

        function parseOverrideJsonResponse(response) {
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (error) {
                    throw new Error(text ? text.substring(0, 180) : `Server returned HTTP ${response.status}`);
                }
            });
        }

        function requestRemoteOverrideCode() {
            const notes = getOverrideNotes();
            if (!notes) {
                return;
            }

            const usernameEl = document.getElementById('remote-admin-username');
            const adminUsernameEl = document.getElementById('admin-username');
            const dropdownUsername = usernameEl ? usernameEl.value.trim() : '';
            const typedUsername = adminUsernameEl ? adminUsernameEl.value.trim() : '';
            const username = dropdownUsername || typedUsername;
            if (!username) {
                showNotification('Select an administrator email or enter an administrator username before requesting a code', 'error');
                if (usernameEl && usernameEl.options && usernameEl.options.length > 1) {
                    usernameEl.focus();
                } else if (adminUsernameEl) {
                    adminUsernameEl.focus();
                }
                return;
            }

            remoteOverrideCodeRequested = false;
            setRemoteOverrideStatus('Sending approval code...', 'info');
            setRemoteButtonLoading('request-remote-code-btn', true, 'Sending...');
            fetch('pos_modal.php?action=request_price_override_code', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: new URLSearchParams({
                    admin_username: username,
                    notes: notes
                }).toString()
            })
            .then(parseOverrideJsonResponse)
            .then(data => {
                if (data && data.success) {
                    remoteOverrideCodeRequested = true;
                    setRemoteOverrideStatus(data.message || 'Approval code emailed. Enter it below.', 'success');
                    showNotification(data.message || 'Approval code emailed to administrator.', 'success');
                    const codeEl = document.getElementById('remote-override-code');
                    if (codeEl) codeEl.focus();
                    return;
                }

                remoteOverrideCodeRequested = false;
                const errorMessage = (data && data.error) ? data.error : 'Unable to email approval code';
                setRemoteOverrideStatus(errorMessage, 'error');
                showNotification(errorMessage, 'error');
            })
            .catch(error => {
                console.error('Error requesting remote approval code:', error);
                remoteOverrideCodeRequested = false;
                const errorMessage = error && error.message ? error.message : 'Error requesting approval code. Please try again.';
                setRemoteOverrideStatus(errorMessage, 'error');
                showNotification(errorMessage, 'error');
            })
            .finally(() => {
                setRemoteButtonLoading('request-remote-code-btn', false);
            });
        }

        function verifyRemoteOverrideCode() {
            const notes = getOverrideNotes();
            if (!notes) {
                return;
            }

            if (!remoteOverrideCodeRequested) {
                const message = 'Request and receive an approval code before using this field.';
                setRemoteOverrideStatus(message, 'error');
                showNotification(message, 'error');
                return;
            }

            const codeEl = document.getElementById('remote-override-code');
            const code = codeEl ? codeEl.value.trim() : '';
            if (!code) {
                showNotification('Enter the approval code from the administrator email', 'error');
                if (codeEl) codeEl.focus();
                return;
            }

            setRemoteOverrideStatus('Checking approval code...', 'info');
            setRemoteButtonLoading('use-remote-code-btn', true, 'Checking...');
            fetch('pos_modal.php?action=verify_price_override_code', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: new URLSearchParams({
                    code: code
                }).toString()
            })
            .then(parseOverrideJsonResponse)
            .then(data => {
                if (data && data.success) {
                    setRemoteOverrideStatus(data.message || 'Remote approval accepted.', 'success');
                    activatePriceOverride(notes, 'Remote approval accepted. Price override mode enabled!');
                    return;
                }

                const errorMessage = (data && data.error) ? data.error : 'Invalid approval code';
                setRemoteOverrideStatus(errorMessage, 'error');
                showNotification(errorMessage, 'error');
            })
            .catch(error => {
                console.error('Error verifying remote approval code:', error);
                const errorMessage = error && error.message ? error.message : 'Error verifying approval code. Please try again.';
                setRemoteOverrideStatus(errorMessage, 'error');
                showNotification(errorMessage, 'error');
            })
            .finally(() => {
                setRemoteButtonLoading('use-remote-code-btn', false);
            });
        }

        function verifyAndEnableOverride() {
            const notes = getOverrideNotes();
            if (!notes) {
                return;
            }
            const usernameEl = document.getElementById('admin-username');
            const passwordEl = document.getElementById('admin-password');
            const username = usernameEl ? usernameEl.value.trim() : '';
            const password = passwordEl ? passwordEl.value : '';

            if (!username || !password) {
                showNotification('Please enter both administrator username and password', 'error');
                return;
            }
            
            // Verify administrator credentials
            verifyAdminCredentials(username, password).then(isValid => {
                if (isValid) {
                    activatePriceOverride(notes, 'Price override mode enabled! Click on any price to edit it.');
                } else {
                    showNotification('Invalid administrator password. Access denied.', 'error');
                }
            }).catch(error => {
                console.error('Error verifying password:', error);
                showNotification('Error verifying administrator credentials. Please try again.', 'error');
            });
        }

        function updateUnitPrice(index, newPrice) {
            if (cart[index]) {
                const newPriceFloat = parseFloat(newPrice) || 0;
                if (newPriceFloat < 0) {
                    showNotification('Price cannot be negative', 'error');
                    return;
                }
                
            const item = cart[index];
                
                // Update original price
                item.original_price = newPriceFloat;
                
                // If there's an existing discount, recalculate the discounted price
                if (item.has_discount && item.discount_info) {
                    const discountAmount = (newPriceFloat * item.discount_info.percent) / 100;
                    item.price = newPriceFloat - discountAmount;
                    item.discount_info.amount = discountAmount;
                } else {
                    // No discount, set price to original price
                    item.price = newPriceFloat;
                }
                
                // Mark item as manually overridden
                item.is_manually_overridden = true;
                
                // Update the order summary to reflect changes immediately
                updateOrderSummary();
            }
        }

        function updateDiscount(index, discountValue, discountType = 'percentage') {
            if (cart[index]) {
                const item = cart[index];
                
                if (!discountValue || discountValue === '' || parseFloat(discountValue) === 0) {
                    // Remove discount
                    item.has_discount = false;
                    item.discount_info = null;
                    item.price = item.original_price;
                } else {
                    const value = parseFloat(discountValue);
                    
                    if (discountType === 'fixed') {
                        // Apply fixed amount discount
                        if (value > item.original_price) {
                            showNotification('Fixed discount cannot exceed the original price', 'error');
                            return;
                        }
                        const discountedPrice = item.original_price - value;
                        
                        item.has_discount = true;
                        item.discount_info = {
                            type: 'fixed',
                            amount: value,
                            description: `$${value.toFixed(2)} discount applied`
                        };
                        item.price = discountedPrice;
                    } else {
                        // Apply percentage discount
                        if (value > 100) {
                            showNotification('Discount cannot exceed 100%', 'error');
                            return;
                        }
                        const discountAmount = (item.original_price * value) / 100;
                        const discountedPrice = item.original_price - discountAmount;
                        
                        item.has_discount = true;
                        item.discount_info = {
                            type: 'percentage',
                            percent: value,
                            amount: discountAmount,
                            description: `${value}% discount applied`
                        };
                        item.price = discountedPrice;
                    }
                }
                
                // Mark item as manually overridden
                item.is_manually_overridden = true;
                
                // Update the order summary to reflect changes immediately
                updateOrderSummary();
            }
        }

        function completeOverride() {
            // Cart items are already updated by the individual update functions
            // Mark all items as manually overridden to prevent auto-reversion
            cart.forEach(item => {
                if (item.original_price !== undefined || item.has_discount) {
                    item.is_manually_overridden = true;
                }
            });
            
            // Save changes to storage
            saveCartToStorage();
            
            // Disable override mode and return to normal view
            overrideMode = false;
            
            // Hide the Unit/Price and Discount column headers
            const unitPriceHeader = document.getElementById('unit-price-header');
            if (unitPriceHeader) {
                unitPriceHeader.style.display = 'none';
            }
            
            const discountHeader = document.getElementById('discount-header');
            if (discountHeader) {
                discountHeader.style.display = 'none';
            }
            
            // Show the override button again (but only if there are items with quantity > 0)
            const overrideBtn = document.getElementById('override-btn');
            if (overrideBtn) {
                // Check if there are items with quantity > 0 (items that can have prices overridden)
                const hasItemsWithQuantity = cart.some(item => item.quantity > 0);
                
                if (hasItemsWithQuantity) {
                overrideBtn.style.display = 'block';
                } else {
                    overrideBtn.style.display = 'none';
                }
            }
            
            // Defective medicine reporting is now handled at inventory level only
            // No need to show/hide defective button in cart
            
            // Restore checkout button to original state
            const checkoutBtn = document.getElementById('checkout-btn');
            if (checkoutBtn) {
                checkoutBtn.innerHTML = 'Proceed to Payment';
                checkoutBtn.onclick = nextStep;
                checkoutBtn.style.background = '';
            }
            
            // Re-render order summary to remove override columns and show updated prices
            renderOrderSummary();
            
            // Show success message
            showNotification('Override changes applied successfully! You can now proceed to payment.', 'success');
        }

        function enablePriceEditing() {
            // Show the Unit/Price column header
            const unitPriceHeader = document.getElementById('unit-price-header');
            if (unitPriceHeader) {
                unitPriceHeader.style.display = 'table-cell';
            }
            
            // Show the Discount column header
            const discountHeader = document.getElementById('discount-header');
            if (discountHeader) {
                discountHeader.style.display = 'table-cell';
            }
            
            console.log('Override mode enabled - Unit/Price and Discount columns added');
        }







        function disableOverride() {
            overrideMode = false;
            
            // Show the override button again (but only if there are items with quantity > 0)
            const overrideBtn = document.getElementById('override-btn');
            if (overrideBtn) {
                // Check if there are items with quantity > 0 (items that can have prices overridden)
                const hasItemsWithQuantity = cart.some(item => item.quantity > 0);
                
                if (hasItemsWithQuantity) {
                    overrideBtn.style.display = 'block';
                } else {
                    overrideBtn.style.display = 'none';
                }
            }
            
            // Defective medicine reporting is now handled at inventory level only
            // No need to show/hide defective button in cart
            
            // Hide the Unit/Price column header
            const unitPriceHeader = document.getElementById('unit-price-header');
            if (unitPriceHeader) {
                unitPriceHeader.style.display = 'none';
            }
            
            // Hide the Discount column header
            const discountHeader = document.getElementById('discount-header');
            if (discountHeader) {
                discountHeader.style.display = 'none';
            }
            
            // Restore checkout button to original state
            const checkoutBtn = document.getElementById('checkout-btn');
            if (checkoutBtn) {
                checkoutBtn.innerHTML = 'Proceed to Payment';
                checkoutBtn.onclick = nextStep;
                checkoutBtn.style.background = '';
            }
            
            // Re-render order summary to remove override columns
            renderOrderSummary();
            
            alert('Price override mode disabled.');
        }

        function verifyAdminCredentials(username, password) {
            return new Promise((resolve, reject) => {
                // Make AJAX request to verify admin credentials
                fetch('pos_modal.php?action=verify_admin&username=' + encodeURIComponent(username) + '&password=' + encodeURIComponent(password))
                    .then(response => response.json())
                    .then(data => {
                        resolve(data.success);
                    })
                    .catch(error => {
                        console.error('Error verifying credentials:', error);
                        reject(error);
                    });
            });
        }

        function logPriceOverride(notes) {
            const pid = (typeof currentPatientId !== 'undefined' && currentPatientId) ? currentPatientId : 0;

            return fetch('pos_modal.php?action=log_price_override', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: new URLSearchParams({
                pid: String(pid),
                notes: notes
                }).toString()
            }).then(r => r.json()).then(data => {
                if (!data || !data.success) {
                throw new Error((data && data.error) ? data.error : 'Unknown error');
                }
                return data;
            });
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('override-modal');
            if (event.target === modal) {
                closeOverrideModal();
            }
        });

        // Handle Enter key in modal
        document.addEventListener('keypress', function(event) {
            if (event.key === 'Enter' && document.getElementById('override-modal').style.display !== 'none') {
                if (event.target && event.target.id === 'remote-override-code') {
                    verifyRemoteOverrideCode();
                } else {
                    verifyAndEnableOverride();
                }
            }
        });

        // Transaction History Toggle Function
        function toggleTransactionHistory(drugId, lotNumber, element) {
            const safeLotNumber = lotNumber.replace(/[^a-zA-Z0-9]/g, '');
            const historyRow = document.getElementById(`transaction-history-${drugId}-${safeLotNumber}`);
            const contentDiv = document.getElementById(`transaction-content-${drugId}-${safeLotNumber}`);
            const chevron = document.getElementById(`chevron-${drugId}-${safeLotNumber}`);
            
            if (historyRow.style.display === 'none') {
                // Show transaction history
                historyRow.style.display = 'table-row';
                chevron.style.transform = 'rotate(90deg)';
                
                // Load transaction history if not already loaded
                if (contentDiv.innerHTML.includes('Loading transaction history')) {
                    loadTransactionHistory(drugId, lotNumber, contentDiv);
                }
            } else {
                // Hide transaction history
                historyRow.style.display = 'none';
                chevron.style.transform = 'rotate(0deg)';
            }
        }

        // Load Transaction History Function
        function loadTransactionHistory(drugId, lotNumber, contentDiv) {
            const pid = <?php echo $pid; ?>;
            contentDiv.dataset.drugId = drugId;
            contentDiv.dataset.lotNumber = lotNumber;
            
            fetch(`pos_transaction_history.php?pid=${pid}&drug_id=${drugId}&lot_number=${encodeURIComponent(lotNumber)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderTransactionHistory(data.transactions, contentDiv);
                    } else {
                        contentDiv.innerHTML = `<div style="text-align: center; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle"></i> Error loading transaction history
                        </div>`;
                    }
                })
                .catch(error => {
                    console.error('Error loading transaction history:', error);
                    contentDiv.innerHTML = `<div style="text-align: center; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle"></i> Error loading transaction history
                    </div>`;
                });
        }

        // Render Transaction History Function
        function renderTransactionHistory(transactions, contentDiv) {
            if (transactions.length === 0) {
                contentDiv.innerHTML = `<div style="text-align: center; color: #6c757d;">
                    <i class="fas fa-info-circle"></i> No transaction history found
                </div>`;
                return;
            }

            let html = `
                <div style="background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden;">
                    <div style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding: 15px; border-bottom: 2px solid #dee2e6;">
                        <h4 style="margin: 0; color: #495057; font-size: 16px;">
                            <i class="fas fa-history"></i> Transaction History (${transactions.length} records)
                        </h4>
                    </div>
                    <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                        <thead>
                            <tr style="background: #f8f9fa;">
                                <th style="padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">Date</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057; display: none;">Type</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">Product</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">Quantity</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">Dispense</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">Amount</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">Receipt #</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">Action</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057; display: none;">Notes</th>
                            </tr>
                        </thead>
                        <tbody>`;

            transactions.forEach((transaction, index) => {
                const transactionDate = new Date(transaction.transaction_date).toLocaleDateString();
                const transactionTime = new Date(transaction.transaction_date).toLocaleTimeString();
                const rowStyle = index % 2 === 0 ? 'background-color: white;' : 'background-color: #f8f9fa;';
                const isVoided = !!transaction.voided;
                const voidReason = transaction.void_reason ? String(transaction.void_reason).replace(/"/g, '&quot;') : '';
                const voidActionHtml = isVoided
                    ? `<div style="display:flex; flex-direction:column; gap:4px; align-items:center;">
                            <span style="background:#6c757d; color:white; border-radius:999px; padding:4px 8px; font-size:11px; font-weight:700;">VOIDED</span>
                            ${transaction.voided_at ? `<span style="font-size:11px; color:#6c757d;">${new Date(transaction.voided_at).toLocaleString()}</span>` : ''}
                            ${voidReason ? `<span style="font-size:11px; color:#6c757d; max-width:180px;">${voidReason}</span>` : ''}
                       </div>`
                    : (transaction.can_undo_backdate
                        ? `<div style="display:flex; flex-direction:column; gap:6px; align-items:center;">
                                <button type="button"
                                        onclick="undoBackdatedPosTransaction('${String(transaction.receipt_number || '').replace(/'/g, "\\'")}', ${Number(transaction.pid || 0)}, this)"
                                        style="background:#0f766e; color:white; border:none; border-radius:6px; padding:8px 12px; font-size:12px; font-weight:700; cursor:pointer;">
                                    Undo Backdate
                                </button>
                                <span style="font-size:11px; color:#6c757d; text-align:center;">Available anytime for backdated receipts.</span>
                           </div>`
                    : (transaction.can_void
                        ? `<button type="button"
                                   onclick="voidPosTransaction(${Number(transaction.id || 0)}, ${Number(transaction.pid || 0)}, this)"
                                   style="background:#dc3545; color:white; border:none; border-radius:6px; padding:8px 12px; font-size:12px; font-weight:700; cursor:pointer;">
                                Void
                           </button>`
                        : `<span style="font-size:11px; color:#6c757d;">${transaction.undo_backdate_block_reason || transaction.void_block_reason || ''}</span>`));
                
                html += `
                    <tr style="${rowStyle} border-bottom: 1px solid #e9ecef;">
                        <td style="padding: 12px; border-right: 1px solid #e9ecef; vertical-align: middle;">
                            <div style="font-weight: 500; color: #495057;">${transactionDate}</div>
                            <div style="font-size: 12px; color: #6c757d;">${transactionTime}</div>
                        </td>
                        <td style="padding: 12px; text-align: center; border-right: 1px solid #e9ecef; vertical-align: middle; display: none;">
                            <span style="background: ${getTransactionTypeColor(transaction.transaction_type)}; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                ${getTransactionTypeDisplayName(transaction.transaction_type)}
                            </span>
                        </td>
                        <td style="padding: 12px; text-align: left; border-right: 1px solid #e9ecef; vertical-align: middle;">
                            <div style="font-weight: 600; color: #495057; font-size: 14px;">${transaction.name || 'Unknown Product'}</div>
                            <div style="font-size: 12px; color: #6c757d;">Lot: ${transaction.lot_number || 'N/A'}</div>
                        </td>
                        <td style="padding: 12px; text-align: center; border-right: 1px solid #e9ecef; vertical-align: middle;">
                            <div style="font-weight: 600; color: #495057;">${formatQuantityWithUnit(transaction.quantity, transaction.form || '')}</div>
                        </td>
                        <td style="padding: 12px; text-align: center; border-right: 1px solid #e9ecef; vertical-align: middle;">
                            <div style="font-weight: 600; color: #28a745;">${formatQuantityWithUnit(transaction.dispense_quantity, transaction.form || '')}</div>
                        </td>
                        <td style="padding: 12px; text-align: center; border-right: 1px solid #e9ecef; vertical-align: middle;">
                            <div style="font-weight: 600; color: #495057;">$${transaction.total_amount.toFixed(2)}</div>
                        </td>
                        <td style="padding: 12px; text-align: center; vertical-align: middle;">
                            <div style="background: #e3f2fd; border: 1px solid #2196f3; border-radius: 4px; padding: 4px 8px; display: inline-block; font-weight: 600; color: #1976d2; font-size: 12px;">
                                ${transaction.receipt_number}
                            </div>
                        </td>
                        <td style="padding: 12px; text-align: center; vertical-align: middle;">
                            ${voidActionHtml}
                        </td>
                        <td style="padding: 12px; text-align: center; vertical-align: middle; display: none;">
                            <div style="font-size: 12px; color: #6c757d;">
                                ${getTransactionNotes(transaction)}
                            </div>
                        </td>
                    </tr>`;
            });

            html += `
                        </tbody>
                    </table>
                </div>`;

            contentDiv.innerHTML = html;
        }

        async function voidPosTransaction(transactionId, pid, buttonElement) {
            if (!transactionId || !pid) {
                showNotification('Missing transaction information for void.', 'error');
                return;
            }

            const reason = window.prompt('Enter the reason for voiding this same-day transaction:');
            if (reason === null) {
                return;
            }

            const trimmedReason = reason.trim();
            if (trimmedReason.length < 3) {
                showNotification('Please enter a more detailed void reason.', 'error');
                return;
            }

            if (!window.confirm('Void this same-day transaction? This will reverse POS inventory and dispense/administer tracking.')) {
                return;
            }

            const originalLabel = buttonElement ? buttonElement.textContent : '';
            if (buttonElement) {
                buttonElement.disabled = true;
                buttonElement.textContent = 'Voiding...';
            }

            try {
                const formData = new FormData();
                formData.append('transaction_id', String(transactionId));
                formData.append('pid', String(pid));
                formData.append('reason', trimmedReason);
                formData.append('csrf_token', '<?php echo CsrfUtils::collectCsrfToken(); ?>');

                const response = await fetch('pos_void_transaction.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.error || 'Void failed');
                }

                showNotification(result.message || 'Transaction voided successfully.', 'success');

                const contentDiv = buttonElement ? buttonElement.closest('td')?.closest('table')?.parentElement?.parentElement : null;
                if (contentDiv && contentDiv.dataset.drugId && contentDiv.dataset.lotNumber) {
                    loadTransactionHistory(contentDiv.dataset.drugId, contentDiv.dataset.lotNumber, contentDiv);
                }

                if (typeof loadMedicineDispenseHistory === 'function') {
                    loadMedicineDispenseHistory();
                }
            } catch (error) {
                console.error('Error voiding transaction:', error);
                showNotification(error.message || 'Error voiding transaction.', 'error');
                if (buttonElement) {
                    buttonElement.disabled = false;
                    buttonElement.textContent = originalLabel || 'Void';
                }
            }
        }

        async function undoBackdatedPosTransaction(receiptNumber, pid, buttonElement) {
            if (!receiptNumber || !pid) {
                showNotification('Missing backdated receipt information.', 'error');
                return;
            }

            const reason = window.prompt('Enter the reason for undoing this backdated entry:', 'Backdated entry entered by mistake');
            if (reason === null) {
                return;
            }

            const trimmedReason = reason.trim();
            if (trimmedReason.length < 3) {
                showNotification('Please enter a more detailed undo reason.', 'error');
                return;
            }

            if (!window.confirm('Undo this backdated entry? This will reverse the saved backdated transaction and restore inventory/tracking.')) {
                return;
            }

            const originalLabel = buttonElement ? buttonElement.textContent : '';
            if (buttonElement) {
                buttonElement.disabled = true;
                buttonElement.textContent = 'Undoing...';
            }

            try {
                const response = await fetch('backdate_void.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        receipt_number: receiptNumber,
                        pid: pid,
                        reason: trimmedReason,
                        csrf_token_form: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                    })
                });
                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.error || 'Undo failed');
                }

                showNotification(result.message || 'Backdated transaction reversed successfully.', 'success');

                const contentDiv = buttonElement ? buttonElement.closest('td')?.closest('table')?.parentElement?.parentElement : null;
                if (contentDiv && contentDiv.dataset.drugId && contentDiv.dataset.lotNumber) {
                    loadTransactionHistory(contentDiv.dataset.drugId, contentDiv.dataset.lotNumber, contentDiv);
                }

                if (typeof loadMedicineDispenseHistory === 'function') {
                    loadMedicineDispenseHistory();
                }
            } catch (error) {
                console.error('Error undoing backdated transaction:', error);
                showNotification(error.message || 'Error undoing backdated entry.', 'error');
                if (buttonElement) {
                    buttonElement.disabled = false;
                    buttonElement.textContent = originalLabel || 'Undo Backdate';
                }
            }
        }

        // Get Transaction Type Color Function
        function getTransactionTypeColor(type) {
            switch (type.toLowerCase()) {
                case 'purchase':
                    return '#28a745';
                case 'dispense':
                    return '#007bff';
                case 'purchase_and_dispense':
                    return '#17a2b8';
                case 'alternate_lot_dispense':
                    return '#6f42c1';
                case 'purchase_and_alternate_dispense':
                    return '#fd7e14';
                case 'refund':
                    return '#dc3545';
                case 'adjustment':
                    return '#ffc107';
                case 'transfer_in':
                    return '#20c997';
                case 'transfer_out':
                    return '#fd7e14';
                default:
                    return '#6c757d';
            }
        }

        // Get Transaction Type Display Name Function
        function getTransactionTypeDisplayName(type) {
            switch (type.toLowerCase()) {
                case 'purchase':
                    return 'Purchase';
                case 'dispense':
                    return 'Dispense';
                case 'purchase_and_dispense':
                    return 'Purchase & Dispense';
                case 'alternate_lot_dispense':
                    return 'Alternate Lot Dispense';
                case 'purchase_and_alternate_dispense':
                    return 'Purchase & Alternate Dispense';
                case 'refund':
                    return 'Refund';
                case 'adjustment':
                    return 'Adjustment';
                case 'transfer_in':
                    return 'Transfer In';
                case 'transfer_out':
                    return 'Transfer Out';
                default:
                    return type;
            }
        }
        // Get Transaction Notes Function
        function getTransactionNotes(transaction) {
            let notes = [];
            
            // Check if this is a remaining dispense transaction
            if (transaction.is_remaining_dispense) {
                notes.push('Remaining Dispense');
            }
            
            // Check if this is a different lot dispense
            if (transaction.is_different_lot_dispense) {
                notes.push('Alternate Lot');
            }
            
            // Check if this has remaining dispense integration
            if (transaction.has_remaining_dispense) {
                notes.push('Integrated Remaining');
            }
            
            // Check if quantity is 0 but dispense quantity > 0 (dispense-only)
            if (transaction.quantity === 0 && transaction.dispense_quantity > 0) {
                notes.push('Dispense Only');
            }
            
            // Check transaction type for additional context
            if (transaction.transaction_type === 'alternate_lot_dispense') {
                notes.push('Alternate Lot Dispense');
            } else if (transaction.transaction_type === 'purchase_and_alternate_dispense') {
                notes.push('Purchase & Alternate Dispense');
            } else if (transaction.transaction_type === 'transfer_in') {
                notes.push('Transfer In');
                if (transaction.transfer_amount) {
                    notes.push(`${transaction.transfer_amount} units`);
                }
            } else if (transaction.transaction_type === 'transfer_out') {
                notes.push('Transfer Out');
                if (transaction.transfer_amount) {
                    notes.push(`${transaction.transfer_amount} units`);
                }
            }
            
            return notes.length > 0 ? notes.join(', ') : '-';
        }

        // Inline Transfer Functions
        let currentTransferData = {};
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdowns = document.querySelectorAll('[id^="transfer-patient-results-"]');
            dropdowns.forEach(dropdown => {
                if (!dropdown.contains(event.target) && !event.target.closest('[id^="transfer-patient-search-"]')) {
                    dropdown.style.display = 'none';
                }
            });
        });

        function toggleTransferSection(drugId, productName, maxAmount, productKey) {
            const transferSection = document.getElementById(`${productKey}-transfer`);
            const transferBtn = document.getElementById(`transfer-btn-${productKey}`);
            
            if (transferSection.style.display === 'none') {
                // Close any open subtable sections first
                const allUpdateSections = document.querySelectorAll('.product-updates');
                allUpdateSections.forEach(section => {
                    if (section.style.display !== 'none') {
                        section.style.display = 'none';
                        // Reset arrow to right
                        const productKeyFromSection = section.id.replace('-updates', '');
                        const headerRow = document.querySelector(`[data-drug-id="${productKeyFromSection.replace('product-', '')}"]`);
                        if (headerRow) {
                            const arrow = headerRow.querySelector('i.fa-chevron-down');
                            if (arrow) {
                                arrow.className = 'fas fa-chevron-right';
                            }
                        }
                    }
                });
                
                // Close any other open transfer sections
                const allTransferSections = document.querySelectorAll('.transfer-section');
                allTransferSections.forEach(section => {
                    if (section.id !== `${productKey}-transfer` && section.style.display !== 'none') {
                        section.style.display = 'none';
                        const otherProductKey = section.id.replace('-transfer', '');
                        const otherTransferBtn = document.getElementById(`transfer-btn-${otherProductKey}`);
                        if (otherTransferBtn) {
                            otherTransferBtn.innerHTML = '<i class="fas fa-exchange-alt"></i> Transfer';
                            otherTransferBtn.style.background = '#17a2b8';
                        }
                        // Clear transfer data
                        if (currentTransferData[otherProductKey]) {
                            delete currentTransferData[otherProductKey];
                            resetTransferForm(otherProductKey);
                        }
                    }
                });
                
                // Show transfer section
                transferSection.style.display = 'table-row';
                transferBtn.innerHTML = '<i class="fas fa-times"></i> Close';
                transferBtn.style.background = '#6c757d';
                
                // Initialize transfer data
                currentTransferData[productKey] = {
                    drugId: drugId,
                    productName: productName,
                    maxAmount: maxAmount,
                    sourcePid: <?php echo $pid ?: 'null'; ?>
                };
                
                // Focus on search input
                setTimeout(() => {
                    const searchInput = document.getElementById(`transfer-patient-search-${productKey}`);
                    if (searchInput) searchInput.focus();
                }, 100);
            } else {
                // Hide transfer section
                transferSection.style.display = 'none';
                transferBtn.innerHTML = '<i class="fas fa-exchange-alt"></i> Transfer';
                transferBtn.style.background = '#17a2b8';
                
                // Clear transfer data
                delete currentTransferData[productKey];
                
                // Reset form
                resetTransferForm(productKey);
            }
        }

        function searchTransferPatients(searchTerm, productKey) {
            const resultsDiv = document.getElementById(`transfer-patient-results-${productKey}`);
            const searchInput = document.getElementById(`transfer-patient-search-${productKey}`);
            if (!resultsDiv || !searchInput) return;
            
            if (!searchTerm || searchTerm.length < 2) {
                resultsDiv.style.display = 'none';
                return;
            }
            
            // Position the dropdown directly below the search box
            const rect = searchInput.getBoundingClientRect();
            
            // Always position below the search box
            const topPosition = rect.bottom + 5;
            
            // Use the same left position as the search box
            const leftPosition = rect.left;
            
            // Use the same width as the search box
            const dropdownWidth = rect.width;
            
            resultsDiv.style.top = topPosition + 'px';
            resultsDiv.style.left = leftPosition + 'px';
            resultsDiv.style.width = dropdownWidth + 'px';
            
            fetch(`pos_modal.php?action=search_patients&search=${encodeURIComponent(searchTerm)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.patients && data.patients.length > 0) {
                        let html = '';
                        data.patients.forEach(patient => {
                            const balance = patient.balance > 0 ? ` (Balance: $${parseFloat(patient.balance).toFixed(2)})` : '';
                            const gender = patient.sex ? patient.sex.charAt(0).toUpperCase() + patient.sex.slice(1) : 'N/A';
                            html += `
                                <div class="search-result-item" onclick="selectTransferPatient(${patient.pid}, '${patient.fname}', '${patient.lname}', '${patient.pubpid}', '${patient.phone || ''}', '${patient.dob || ''}', '${patient.sex || ''}', '${productKey}')" style="background: white; padding: 8px 10px; border-bottom: 1px solid #f1f1f1; cursor: pointer; transition: background-color 0.2s ease;" onmouseover="this.style.backgroundColor='#e3f2fd'" onmouseout="this.style.backgroundColor='white'">
                                    <div style="display: flex; gap: 8px; align-items: center; font-size: 12px; white-space: nowrap; overflow: hidden;">
                                        <div style="font-weight: 600; color: #495057; min-width: 80px; flex: 1; overflow: hidden; text-overflow: ellipsis;">${patient.fname} ${patient.lname}</div>
                                        <div style="color: #6c757d; min-width: 40px; text-align: center;">${gender}</div>
                                        <div style="color: #6c757d; min-width: 50px; text-align: center;">${patient.dob}</div>
                                        <div style="color: #6c757d; min-width: 60px; text-align: center;">${patient.phone || 'N/A'}</div>
                                        ${patient.balance > 0 ? `<div style="color: #dc3545; font-weight: 500; min-width: 50px; text-align: center;">$${parseFloat(patient.balance).toFixed(2)}</div>` : ''}
                                    </div>
                                </div>
                            `;
                        });
                        resultsDiv.innerHTML = html;
                        resultsDiv.style.display = 'block';
                    } else {
                        resultsDiv.innerHTML = '<div style="padding: 10px 12px; color: #6c757d; text-align: center; font-size: 13px;">No patients found</div>';
                        resultsDiv.style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error searching patients:', error);
                    resultsDiv.innerHTML = '<div style="padding: 10px 12px; color: #dc3545; text-align: center; font-size: 13px;">Error searching patients</div>';
                    resultsDiv.style.display = 'block';
                });
        }

        function selectTransferPatient(pid, fname, lname, pubpid, phone, dob, sex, productKey) {
            const currentPid = <?php echo $pid ?: 'null'; ?>;
            if (pid == currentPid) {
                alert('Cannot transfer to the same patient');
                return;
            }
            
            currentTransferData[productKey].targetPid = pid;
            currentTransferData[productKey].targetName = `${fname} ${lname}`;
            currentTransferData[productKey].targetPubpid = pubpid;
            currentTransferData[productKey].targetPhone = phone;
            
            // Hide search results
            document.getElementById(`transfer-patient-results-${productKey}`).style.display = 'none';
            
            // Hide search section and show patient display
            document.getElementById(`transfer-patient-search-section-${productKey}`).style.display = 'none';
            document.getElementById(`transfer-patient-display-${productKey}`).style.display = 'block';
            
            // Update patient display with all information in horizontal row format
            const patientName = `${fname} ${lname}`;
            const patientPhone = phone || 'N/A';
            const patientDob = dob || 'N/A';
            const patientGender = sex ? sex.charAt(0).toUpperCase() + sex.slice(1) : 'N/A';
            
            document.getElementById(`transfer-patient-name-${productKey}`).textContent = patientName;
            document.getElementById(`transfer-patient-mobile-${productKey}`).textContent = patientPhone;
            document.getElementById(`transfer-patient-dob-${productKey}`).textContent = patientDob;
            document.getElementById(`transfer-patient-gender-${productKey}`).textContent = patientGender;
            
            // Enable transfer button
            document.getElementById(`process-transfer-btn-${productKey}`).disabled = false;
        }

        function validateTransferAmount(productKey, maxAmount) {
            console.log('validateTransferAmount - productKey:', productKey, 'maxAmount:', maxAmount);
            
            const amountInput = document.getElementById(`transfer-amount-${productKey}`);
            const errorDiv = document.getElementById(`transfer-amount-error-${productKey}`);
            
            console.log('validateTransferAmount - amountInput:', amountInput);
            console.log('validateTransferAmount - errorDiv:', errorDiv);
            
            if (!amountInput) {
                console.error('Amount input not found!');
                return false;
            }
            
            if (!errorDiv) {
                console.error('Error div not found!');
                return false;
            }
            
            let amount = parseInt(amountInput.value) || 0;
            console.log('validateTransferAmount - Initial amount:', amount);
            
            // Remove any non-numeric characters
            amountInput.value = amountInput.value.replace(/[^0-9]/g, '');
            amount = parseInt(amountInput.value) || 0;
            console.log('validateTransferAmount - Cleaned amount:', amount);
            
            if (amount < 1) {
                console.log('validateTransferAmount - Amount < 1, FAILED');
                errorDiv.textContent = 'Transfer amount must be at least 1';
                errorDiv.style.display = 'block';
                amountInput.value = 1;
                return false;
            } else if (amount > maxAmount) {
                console.log('validateTransferAmount - Amount > maxAmount, FAILED');
                errorDiv.textContent = `Transfer amount cannot exceed ${maxAmount}`;
                errorDiv.style.display = 'block';
                amountInput.value = maxAmount;
                return false;
            } else {
                console.log('validateTransferAmount - PASSED');
                errorDiv.style.display = 'none';
                return true;
            }
        }

        function processTransferInline(drugId, productKey, maxAmount) {
            console.log('processTransferInline called:', {drugId, productKey, maxAmount});
            console.log('currentTransferData:', currentTransferData[productKey]);
            
            if (!currentTransferData[productKey] || !currentTransferData[productKey].targetPid) {
                alert('Please select a target patient');
                return;
            }
            
            const amount = parseInt(document.getElementById(`transfer-amount-${productKey}`).value) || 0;
            console.log('Transfer amount:', amount);
            
            if (!validateTransferAmount(productKey, maxAmount)) {
                console.log('Amount validation failed');
                return;
            }
            
            // Show loading state
            const btn = document.getElementById(`process-transfer-btn-${productKey}`);
            if (!btn) {
                console.error('Transfer button not found:', `process-transfer-btn-${productKey}`);
                return;
            }
            
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;
            
            const transferData = {
                    action: 'transfer_dispense',
                    source_pid: currentTransferData[productKey].sourcePid,
                    target_pid: currentTransferData[productKey].targetPid,
                    drug_id: currentTransferData[productKey].drugId,
                    transfer_amount: amount,
                    csrf_token: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
            };
            
            console.log('Sending transfer request:', transferData);
            
            // Process transfer
            fetch('pos_transfer_processor.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(transferData)
            })
            .then(response => {
                console.log('Transfer response status:', response.status);
                if (!response.ok) {
                    return response.text().then(text => {
                        console.error('Transfer error response:', text);
                        throw new Error(`HTTP ${response.status}: ${text.substring(0, 100)}`);
                    });
                }
                return response.json();
            })
            .then(data => {
                console.log('Transfer response data:', data);
                if (data.success) {
                    showNotification('Transfer completed successfully!', 'success');
                    toggleTransferSection(drugId, currentTransferData[productKey].productName, maxAmount, productKey);
                    // Refresh dispense tracking
                    loadRemainingDispenseData();
                } else {
                    showNotification(data.error || 'Transfer failed', 'error');
                }
            })
            .catch(error => {
                console.error('Transfer error:', error);
                showNotification('Transfer failed: ' + error.message, 'error');
            })
            .finally(() => {
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }

        function editTransferPatient(productKey) {
            // Clear current selection
            currentTransferData[productKey] = {
                targetPid: null,
                targetName: null,
                targetPubpid: null
            };
            
            // Show search section and hide patient display
            document.getElementById(`transfer-patient-search-section-${productKey}`).style.display = 'block';
            document.getElementById(`transfer-patient-display-${productKey}`).style.display = 'none';
            
            // Clear search input
            document.getElementById(`transfer-patient-search-${productKey}`).value = '';
            
            // Disable transfer button
            document.getElementById(`process-transfer-btn-${productKey}`).disabled = true;
        }

        function resetTransferForm(productKey) {
            // Clear search input
            const searchInput = document.getElementById(`transfer-patient-search-${productKey}`);
            if (searchInput) searchInput.value = '';
            
            // Hide search results
            const resultsDiv = document.getElementById(`transfer-patient-results-${productKey}`);
            if (resultsDiv) resultsDiv.style.display = 'none';
            
            // Show search section and hide patient display
            const searchSection = document.getElementById(`transfer-patient-search-section-${productKey}`);
            const displaySection = document.getElementById(`transfer-patient-display-${productKey}`);
            if (searchSection) searchSection.style.display = 'block';
            if (displaySection) displaySection.style.display = 'none';
            
            // Reset amount input
            const amountInput = document.getElementById(`transfer-amount-${productKey}`);
            if (amountInput) amountInput.value = 1;
            
            // Hide error message
            const errorDiv = document.getElementById(`transfer-amount-error-${productKey}`);
            if (errorDiv) errorDiv.style.display = 'none';
            
            // Disable transfer button
            const transferBtn = document.getElementById(`process-transfer-btn-${productKey}`);
            if (transferBtn) transferBtn.disabled = true;
            
            // Reset transfer data
            currentTransferData[productKey] = {
                targetPid: null,
                targetName: null,
                targetPubpid: null
            };
        }

        // ==================== MEDICINE SWITCHING FUNCTIONS ====================
        
        // Global variables for medicine switching
        let currentSwitchData = {};
        
        // Close switch medicine search results when clicking outside
        document.addEventListener('click', function(e) {
            const searchResults = document.querySelectorAll('[id^="switch-medicine-results-"]');
            searchResults.forEach(resultsDiv => {
                if (resultsDiv.style.display === 'block' && resultsDiv.style.visibility === 'visible') {
                    const productKey = resultsDiv.id.replace('switch-medicine-results-', '');
                    const searchInput = document.getElementById(`switch-medicine-search-${productKey}`);
                    
                    // Check if click was outside both the results and the search input
                    // Allow clicks on search result items
                    const isClickOnResult = resultsDiv.contains(e.target) && e.target.closest('.search-result-item');
                    const isClickOnInput = searchInput && (searchInput.contains(e.target) || searchInput === e.target);
                    
                    if (!isClickOnResult && !isClickOnInput) {
                        resultsDiv.style.display = 'none';
                        resultsDiv.style.visibility = 'hidden';
                        resultsDiv.style.opacity = '0';
                    }
                }
            });
        });
        
        function toggleSwitchMedicineSection(drugId, productName, maxAmount, productKey) {
            const switchSection = document.getElementById(`${productKey}-switch-medicine`);
            const switchBtn = document.getElementById(`switch-medicine-btn-${productKey}`);
            
            if (switchSection.style.display === 'none') {
                // Close any open subtable sections first
                const allUpdateSections = document.querySelectorAll('.product-updates');
                allUpdateSections.forEach(section => {
                    if (section.style.display !== 'none') {
                        section.style.display = 'none';
                    }
                });
                
                // Close any other open transfer sections
                const allTransferSections = document.querySelectorAll('.transfer-section');
                allTransferSections.forEach(section => {
                    if (section.id !== `${productKey}-transfer` && section.style.display !== 'none') {
                        section.style.display = 'none';
                        const otherProductKey = section.id.replace('-transfer', '');
                        const otherTransferBtn = document.getElementById(`transfer-btn-${otherProductKey}`);
                        if (otherTransferBtn) {
                            otherTransferBtn.innerHTML = '<i class="fas fa-exchange-alt"></i> Transfer';
                            otherTransferBtn.style.background = '#17a2b8';
                        }
                    }
                });
                
                // Close any other open switch medicine sections
                const allSwitchSections = document.querySelectorAll('.switch-medicine-section');
                allSwitchSections.forEach(section => {
                    if (section.id !== `${productKey}-switch-medicine` && section.style.display !== 'none') {
                        section.style.display = 'none';
                        const otherProductKey = section.id.replace('-switch-medicine', '');
                        const otherSwitchBtn = document.getElementById(`switch-medicine-btn-${otherProductKey}`);
                        if (otherSwitchBtn) {
                            otherSwitchBtn.innerHTML = '<i class="fas fa-pills"></i> Switch';
                            otherSwitchBtn.style.background = '#28a745';
                        }
                    }
                });
                
                // Show switch medicine section
                switchSection.style.display = 'table-row';
                switchBtn.innerHTML = '<i class="fas fa-times"></i> Cancel';
                switchBtn.style.background = '#dc3545';
                
                // Initialize switch data
                currentSwitchData[productKey] = {
                    currentDrugId: drugId,
                    currentProductName: productName,
                    maxAmount: maxAmount,
                    newDrugId: null,
                    newDrugName: null,
                    quantity: maxAmount
                };
                
                // Reset form
                resetSwitchMedicineForm(productKey);
            } else {
                // Hide switch medicine section
                switchSection.style.display = 'none';
                switchBtn.innerHTML = '<i class="fas fa-pills"></i> Switch';
                switchBtn.style.background = '#28a745';
                
                // Clear switch data
                delete currentSwitchData[productKey];
            }
        }
        
        function resetSwitchMedicineForm(productKey) {
            // Reset quantity input
            const quantityInput = document.getElementById(`switch-quantity-${productKey}`);
            if (quantityInput) {
                quantityInput.value = currentSwitchData[productKey]?.maxAmount || 1;
            }
            
            // Reset medicine search
            const searchInput = document.getElementById(`switch-medicine-search-${productKey}`);
            if (searchInput) searchInput.value = '';
            
            // Hide search results
            const resultsDiv = document.getElementById(`switch-medicine-results-${productKey}`);
            if (resultsDiv) resultsDiv.style.display = 'none';
            
            // Show search section and hide medicine display
            const searchSection = document.getElementById(`switch-medicine-search-section-${productKey}`);
            const displaySection = document.getElementById(`switch-medicine-display-${productKey}`);
            if (searchSection) searchSection.style.display = 'block';
            if (displaySection) displaySection.style.display = 'none';
            
            // Hide error message
            const errorDiv = document.getElementById(`switch-quantity-error-${productKey}`);
            if (errorDiv) errorDiv.style.display = 'none';
            
            // Disable switch button
            const switchBtn = document.getElementById(`process-switch-btn-${productKey}`);
            if (switchBtn) switchBtn.disabled = true;
            
            // Reset switch data
            if (currentSwitchData[productKey]) {
                currentSwitchData[productKey].newDrugId = null;
                currentSwitchData[productKey].newDrugName = null;
                currentSwitchData[productKey].quantity = currentSwitchData[productKey].maxAmount;
            }
        }
        
        function validateSwitchQuantity(productKey, maxAmount) {
            const quantityInput = document.getElementById(`switch-quantity-${productKey}`);
            const errorDiv = document.getElementById(`switch-quantity-error-${productKey}`);
            const switchBtn = document.getElementById(`process-switch-btn-${productKey}`);
            
            if (!quantityInput || !errorDiv || !switchBtn) return false;
            
            const quantity = parseInt(quantityInput.value) || 0;
            
            if (quantity < 1) {
                errorDiv.textContent = 'Quantity must be at least 1';
                errorDiv.style.display = 'block';
                switchBtn.disabled = true;
                return false;
            } else if (quantity > maxAmount) {
                errorDiv.textContent = `Quantity cannot exceed ${maxAmount}`;
                errorDiv.style.display = 'block';
                switchBtn.disabled = true;
                return false;
            } else {
                errorDiv.style.display = 'none';
                
                // Update switch data
                if (currentSwitchData[productKey]) {
                    currentSwitchData[productKey].quantity = quantity;
                }
                
                // Enable switch button if medicine is selected
                if (currentSwitchData[productKey]?.newDrugId) {
                    switchBtn.disabled = false;
                }
                
                return true;
            }
        }
        
        function searchSwitchMedicines(query, productKey) {
            if (query.length < 2) {
                const resultsDiv = document.getElementById(`switch-medicine-results-${productKey}`);
                if (resultsDiv) {
                    resultsDiv.style.display = 'none';
                    resultsDiv.style.visibility = 'hidden';
                    resultsDiv.style.opacity = '0';
                }
                return;
            }
            
            // Search for medicines using the existing inventory search
            let searchUrl = posSimpleSearchUrl + '?search=' + encodeURIComponent(query) + '&t=' + Date.now();
            if (currentFacilityId) {
                searchUrl += '&facility_id=' + encodeURIComponent(currentFacilityId);
            }
            fetch(searchUrl)
                .then(response => response.json())
                .then(data => {
                    const resultsDiv = document.getElementById(`switch-medicine-results-${productKey}`);
                    const searchInput = document.getElementById(`switch-medicine-search-${productKey}`);
                    if (!resultsDiv || !searchInput) return;
                    
                    // Position the dropdown below the input field
                    const inputRect = searchInput.getBoundingClientRect();
                    resultsDiv.style.position = 'fixed';
                    resultsDiv.style.top = (inputRect.bottom + window.scrollY + 5) + 'px';
                    resultsDiv.style.left = inputRect.left + 'px';
                    resultsDiv.style.width = Math.max(inputRect.width, 600) + 'px';
                    resultsDiv.style.maxWidth = '800px';
                    console.log('Positioning search results:', {
                        inputTop: inputRect.top,
                        inputBottom: inputRect.bottom,
                        scrollY: window.scrollY,
                        calculatedTop: inputRect.bottom + window.scrollY + 5,
                        inputLeft: inputRect.left,
                        width: Math.max(inputRect.width, 600)
                    });
                    
                    if (data.results && data.results.length > 0) {
                        console.log('Search results received:', data.results.length, 'items');
                        console.log('First result sample:', data.results[0]);
                        
                        // Filter out the current medicine being switched
                        const filteredResults = data.results.filter(item => 
                            item.drug_id != currentSwitchData[productKey]?.currentDrugId
                        );
                        
                        console.log('Filtered results:', filteredResults.length, 'items');
                        
                        if (filteredResults.length === 0) {
                            resultsDiv.innerHTML = '<div class="search-result-item" style="padding: 20px; text-align: center; color: #6c757d;">No other medicines found</div>';
                            resultsDiv.style.display = 'block';
                            resultsDiv.style.visibility = 'visible';
                            resultsDiv.style.opacity = '1';
                            resultsDiv.style.zIndex = '999999';
                            return;
                        }
                        
                        let html = '<div style="background: #f8f9fa; color: #333; padding: 12px; border-bottom: 1px solid #dee2e6; font-weight: 600; font-size: 14px; text-align: center;">Found ' + filteredResults.length + ' results - Click to select</div>';
                        
                        filteredResults.forEach(item => {
                            const isMedical = item.category_name === 'Medical';
                            const formValue = (item.form && item.form !== '0') ? item.form : '';
                            const unitValue = (item.unit && item.unit !== '0') ? item.unit : '';
                            
                            // Extract drug_id from composite ID (format: drug_149_lot_200)
                            let drugId = item.drug_id || item.id || item.drugId || '';
                            if (typeof drugId === 'string' && drugId.startsWith('drug_')) {
                                // Extract numeric drug_id from format "drug_149_lot_200"
                                const parts = drugId.split('_');
                                if (parts.length >= 2) {
                                    drugId = parts[1]; // Get the number after "drug_"
                                }
                            }
                            
                            console.log('Building onclick for item:', {original_id: item.id, extracted_drugId: drugId, name: item.name});
                            
                            html += '<div class="search-result-item" onclick="selectSwitchMedicine(\'' + drugId + '\', \'' + (item.name || '').replace(/'/g, "\\'") + '\', \'' + (item.qoh || 0) + '\', \'' + (item.lot_number || 'N/A').replace(/'/g, "\\'") + '\', \'' + formValue.replace(/'/g, "\\'") + '\', \'' + unitValue.replace(/'/g, "\\'") + '\', \'' + productKey + '\')" style="background: white; cursor: pointer;">';
                            html += '<div class="search-result-info">';
                            html += '<div class="search-result-name" style="color: #333; font-weight: 500; font-size: 16px; display: flex; gap: 30px; align-items: center; flex-wrap: wrap;">';
                            html += '<span>' + (item.display_name || item.name) + '</span>';
                            if (item.form && item.form !== '0') {
                                html += '<span style="color: #6c757d; font-weight: 500;">Form: ' + item.form + '</span>';
                            }
                            if (item.unit && item.unit !== '0') {
                                html += '<span style="color: #6c757d; font-weight: 500;">Unit: ' + item.unit + '</span>';
                            }
                            html += '</div>';
                            
                            // Enhanced lot information display - only for medical products
                            if (isMedical) {
                                html += '<div class="search-result-lot" style="margin-top: 8px; font-size: 16px; display: flex; gap: 30px; flex-wrap: wrap; background: #f8f9fa; padding: 10px; border-radius: 6px; width: fit-content;">';
                                if (item.lot_number && item.lot_number !== 'No Lot') {
                                    html += '<span style="color: #6c757d; font-weight: 500;">Lot #' + item.lot_number + '</span>';
                                    if (item.qoh !== undefined && item.qoh !== null) {
                                        html += '<span style="color: #6c757d; font-weight: 500;">QOH: ' + item.qoh + '</span>';
                                    }
                                    if (item.expiration) {
                                        html += '<span style="color: #6c757d; font-weight: 500;">Exp: ' + item.expiration + '</span>';
                                    }
                                } else {
                                    html += '<span style="color: #6c757d; font-weight: 500;">No Lot Available</span>';
                                }
                                html += '</div>';
                            }
                            html += '</div>';
                            
                            // Display price
                            if (item.original_price !== undefined && item.original_price !== null) {
                                html += '<div class="search-result-price" style="color: #333; font-weight: 600; font-size: 15px;">$' + parseFloat(item.original_price).toFixed(2) + '</div>';
                            }
                            html += '</div>';
                        });
                        
                        resultsDiv.innerHTML = html;
                        resultsDiv.style.display = 'block';
                        resultsDiv.style.visibility = 'visible';
                        resultsDiv.style.opacity = '1';
                        resultsDiv.style.zIndex = '999999';
                        
                        // Reposition on scroll
                        const repositionResults = () => {
                            if (resultsDiv.style.display === 'block' && resultsDiv.style.visibility === 'visible') {
                                const inputRect = searchInput.getBoundingClientRect();
                                resultsDiv.style.top = (inputRect.bottom + window.scrollY + 5) + 'px';
                                resultsDiv.style.left = inputRect.left + 'px';
                            }
                        };
                        
                        // Remove old listeners and add new ones
                        window.removeEventListener('scroll', repositionResults);
                        window.removeEventListener('resize', repositionResults);
                        window.addEventListener('scroll', repositionResults, true);
                        window.addEventListener('resize', repositionResults);
                    } else {
                        resultsDiv.innerHTML = '<div class="search-result-item" style="padding: 20px; text-align: center; color: #6c757d;">No medicines found</div>';
                        resultsDiv.style.display = 'block';
                        resultsDiv.style.visibility = 'visible';
                        resultsDiv.style.opacity = '1';
                        resultsDiv.style.zIndex = '999999';
                    }
                })
                .catch(error => {
                    console.error('Error searching medicines:', error);
                    const resultsDiv = document.getElementById(`switch-medicine-results-${productKey}`);
                    if (resultsDiv) {
                        resultsDiv.innerHTML = '<div class="search-result-item" style="padding: 20px; text-align: center; color: #dc3545;">Error searching medicines</div>';
                        resultsDiv.style.display = 'block';
                        resultsDiv.style.visibility = 'visible';
                        resultsDiv.style.opacity = '1';
                        resultsDiv.style.zIndex = '999999';
                    }
                });
        }
        
        function selectSwitchMedicine(drugId, drugName, stock, lotNumber, form, unit, productKey) {
            console.log('selectSwitchMedicine - drugId:', drugId, 'type:', typeof drugId);
            console.log('selectSwitchMedicine - parseInt(drugId):', parseInt(drugId));
            
            // Update switch data - convert drugId to integer
            if (currentSwitchData[productKey]) {
                currentSwitchData[productKey].newDrugId = parseInt(drugId);
                currentSwitchData[productKey].newDrugName = drugName;
                console.log('Updated currentSwitchData:', JSON.stringify(currentSwitchData[productKey]));
            } else {
                console.error('currentSwitchData not initialized for productKey:', productKey);
            }
            
            // Update display
            const searchSection = document.getElementById(`switch-medicine-search-section-${productKey}`);
            const displaySection = document.getElementById(`switch-medicine-display-${productKey}`);
            const medicineName = document.getElementById(`switch-medicine-name-${productKey}`);
            const medicineForm = document.getElementById(`switch-medicine-form-${productKey}`);
            const medicineUnit = document.getElementById(`switch-medicine-unit-${productKey}`);
            
            if (searchSection) searchSection.style.display = 'none';
            if (displaySection) displaySection.style.display = 'block';
            if (medicineName) medicineName.textContent = drugName;
            if (medicineForm) {
                medicineForm.textContent = form ? `Form: ${form}` : '';
                medicineForm.style.display = form ? 'inline' : 'none';
            }
            if (medicineUnit) {
                medicineUnit.textContent = unit ? `Unit: ${unit}` : '';
                medicineUnit.style.display = unit ? 'inline' : 'none';
            }
            
            // Hide search results
            const resultsDiv = document.getElementById(`switch-medicine-results-${productKey}`);
            if (resultsDiv) {
                resultsDiv.style.display = 'none';
                resultsDiv.style.visibility = 'hidden';
                resultsDiv.style.opacity = '0';
            }
            
            // Enable switch button if quantity is valid
            const switchBtn = document.getElementById(`process-switch-btn-${productKey}`);
            if (switchBtn) {
                const quantity = parseInt(document.getElementById(`switch-quantity-${productKey}`).value) || 0;
                const maxAmount = currentSwitchData[productKey]?.maxAmount || 0;
                switchBtn.disabled = !(quantity >= 1 && quantity <= maxAmount);
            }
        }
        
        function editSwitchMedicine(productKey) {
            // Show search section and hide medicine display
            const searchSection = document.getElementById(`switch-medicine-search-section-${productKey}`);
            const displaySection = document.getElementById(`switch-medicine-display-${productKey}`);
            if (searchSection) searchSection.style.display = 'block';
            if (displaySection) displaySection.style.display = 'none';
            
            // Clear search input
            const searchInput = document.getElementById(`switch-medicine-search-${productKey}`);
            if (searchInput) searchInput.value = '';
            
            // Reset switch data
            if (currentSwitchData[productKey]) {
                currentSwitchData[productKey].newDrugId = null;
                currentSwitchData[productKey].newDrugName = null;
            }
            
            // Disable switch button
            const switchBtn = document.getElementById(`process-switch-btn-${productKey}`);
            if (switchBtn) switchBtn.disabled = true;
        }
        
        function processSwitchMedicine(currentDrugId, productKey, maxAmount) {
            console.log('processSwitchMedicine - productKey:', productKey);
            console.log('processSwitchMedicine - currentSwitchData:', JSON.stringify(currentSwitchData));
            
            const switchData = currentSwitchData[productKey];
            console.log('processSwitchMedicine - switchData:', JSON.stringify(switchData));
            console.log('processSwitchMedicine - switchData.newDrugId:', switchData?.newDrugId, 'type:', typeof switchData?.newDrugId);
            
            if (!switchData || !switchData.newDrugId || switchData.newDrugId === 0) {
                console.error('Validation failed!');
                console.error('  switchData exists:', !!switchData);
                console.error('  newDrugId:', switchData?.newDrugId);
                console.error('  newDrugId === 0:', switchData?.newDrugId === 0);
                alert('Please select a new medicine');
                return;
            }
            
            console.log('Validation passed!');
            
            const quantity = parseInt(document.getElementById(`switch-quantity-${productKey}`).value) || 0;
            if (quantity < 1 || quantity > maxAmount) {
                alert('Invalid quantity');
                return;
            }
            
            if (confirm(`Switch ${quantity} units of "${switchData.currentProductName}" to "${switchData.newDrugName}"?`)) {
                // Disable button during processing
                const switchBtn = document.getElementById(`process-switch-btn-${productKey}`);
                if (switchBtn) {
                    switchBtn.disabled = true;
                    switchBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Switching...';
                }
                
                const requestData = {
                    action: 'switch_medicine',
                    pid: currentPatientId,
                    current_drug_id: currentDrugId,
                    new_drug_id: switchData.newDrugId,
                    quantity: quantity,
                    csrf_token_form: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                };
                
                console.log('Sending switch medicine request:', {
                    action: requestData.action,
                    pid: requestData.pid,
                    current_drug_id: requestData.current_drug_id,
                    new_drug_id: requestData.new_drug_id,
                    quantity: requestData.quantity,
                    has_csrf: !!requestData.csrf_token_form
                });
                
                // Process the switch via AJAX
                fetch('pos_payment_processor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(requestData)
                })
                .then(response => {
                    console.log('Switch medicine response status:', response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('Switch medicine response data:', data);
                    console.log('Switch medicine error (if any):', data.error);
                    console.log('Switch medicine success:', data.success);
                    if (data.success) {
                        showNotification('Medicine switched successfully!', 'success');
                        
                        // Close switch section
                        const switchSection = document.getElementById(`${productKey}-switch-medicine`);
                        const switchBtn = document.getElementById(`switch-medicine-btn-${productKey}`);
                        if (switchSection) switchSection.style.display = 'none';
                        if (switchBtn) {
                            switchBtn.innerHTML = '<i class="fas fa-pills"></i> Switch';
                            switchBtn.style.background = '#28a745';
                        }
                        
                        // Refresh dispense tracking data
                        loadRemainingDispenseData();
                        
                        // Clear switch data
                        delete currentSwitchData[productKey];
                    } else {
                        showNotification('Error switching medicine: ' + (data.error || 'Unknown error'), 'error');
                        
                        // Re-enable button
                        const switchBtn = document.getElementById(`process-switch-btn-${productKey}`);
                        if (switchBtn) {
                            switchBtn.disabled = false;
                            switchBtn.innerHTML = '<i class="fas fa-pills"></i> Switch';
                        }
                    }
                })
                .catch(error => {
                    console.error('Error switching medicine:', error);
                    showNotification('Error switching medicine: ' + error.message, 'error');
                    
                    // Re-enable button
                    const switchBtn = document.getElementById(`process-switch-btn-${productKey}`);
                    if (switchBtn) {
                        switchBtn.disabled = false;
                        switchBtn.innerHTML = '<i class="fas fa-pills"></i> Switch';
                    }
                });
            }
        }

        // ==================== REFUND SYSTEM FUNCTIONS ====================
        
        // Global variables for refund management
        let currentRefundType = null;
        let currentRefundData = {
            itemId: null,
            drugId: null,
            drugName: null,
            maxQuantity: 0,
            originalAmount: 0,
            totalAmount: 0,
            itemType: null
        };
        let selectedRefundItems = [];
        let refundableItemsData = [];

        function openRefundModal() {
            // Use the new toggle system instead of modal
            toggleRefundSection();
        }
        function populateRefundableItemsList(items) {
            const itemsList = document.getElementById('refundableItemsList');
            if (!itemsList) return;
            
            // Store the data globally for filtering and selection
            refundableItemsData = items || [];
            
            if (!items || items.length === 0) {
                itemsList.innerHTML = '<div style="padding: 40px; text-align: center; color: #6c757d;"><i class="fas fa-info-circle" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>No items with remaining dispense found for this patient</div>';
                return;
            }
            
            // Group items by product (drug_id) - only show products with remaining dispense
            const productMap = {};
            for (const item of items) {
                const remainingQty = Number(item.remaining_quantity || 0);
                if (remainingQty > 0) { // Only include products with remaining dispense
                    const resolvedProductName = (item.drug_name || item.name || '').toString().trim() || `Drug #${item.drug_id}`;
                    if (!productMap[item.drug_id]) {
                        productMap[item.drug_id] = {
                            drug_id: item.drug_id,
                            product_name: resolvedProductName,
                            form: item.form || '',
                            total_bought: 0,
                            total_dispensed: 0,
                            total_administered: 0,
                            total_remaining: 0,
                            original_amount: 0,
                            refundable_amount: 0,
                            price_per_unit: Number(item.price || 0) // Get price from backend
                        };
                    }
                    const group = productMap[item.drug_id];
                    group.total_bought += Number(item.total_quantity || item.quantity || 0);
                    group.total_dispensed += Number(item.dispensed_quantity || 0);
                    group.total_administered += Number(item.administered_quantity || 0);
                    group.total_remaining += remainingQty;
                    group.original_amount += Number(item.original_amount || item.total_amount || 0);
                    
                    // Use backend-calculated refundable amount (it properly fetches price from inventory)
                    group.refundable_amount += Number(item.total_amount || 0);
                }
            }
            
            // Recalculate refundable amount based on price from inventory if original amount is 0
            for (const product of Object.values(productMap)) {
                // If no refundable amount calculated from backend, calculate from price_per_unit
                if (product.refundable_amount === 0 && product.price_per_unit > 0) {
                    product.refundable_amount = product.total_remaining * product.price_per_unit;
                }
                // Fallback: proportional calculation if we have original_amount
                else if (product.refundable_amount === 0 && product.total_bought > 0 && product.original_amount > 0) {
                    product.refundable_amount = (product.total_remaining / product.total_bought) * product.original_amount;
                }
            }
            
            // Sort products by total remaining (descending)
            const sortedProducts = Object.values(productMap).sort((a, b) => b.total_remaining - a.total_remaining);
            
            if (sortedProducts.length === 0) {
                itemsList.innerHTML = '<div style="padding: 40px; text-align: center; color: #6c757d;"><i class="fas fa-info-circle" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>No products with remaining dispense found for this patient</div>';
                return;
            }
            
            let html = `
                <!-- Multi-Product Selection Table -->
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                    <thead>
                        <tr style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);">
                            <th style="padding: 15px; text-align: center; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 13px; width: 50px;">
                                <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll()" style="transform: scale(1.2);">
                            </th>
                            <th style="padding: 15px; text-align: left; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 13px; width: 30%">
                                <i class="fas fa-pills"></i> Product
                            </th>
                            <th style="padding: 15px; text-align: center; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 13px;">
                                <i class="fas fa-shopping-cart"></i> Bought
                            </th>
                            <th style="padding: 15px; text-align: center; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 13px;">
                                <i class="fas fa-check-circle"></i> Dispensed
                            </th>
                            <th style="padding: 15px; text-align: center; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 13px;">
                                <i class="fas fa-syringe"></i> Administered
                            </th>
                            <th style="padding: 15px; text-align: center; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 13px;">
                                <i class="fas fa-exclamation-triangle"></i> Remaining
                            </th>
                            <th style="padding: 15px; text-align: center; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 13px;">
                                <i class="fas fa-undo"></i> Return QTY
                            </th>
                            <th style="padding: 15px; text-align: center; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 13px;">
                                <i class="fas fa-dollar-sign"></i> Refund Amount
                            </th>
                            <th style="padding: 15px; text-align: center; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; font-size: 13px;">
                                <i class="fas fa-cog"></i> Action
                            </th>
                        </tr>
                    </thead>
                    <tbody>`;
            
            sortedProducts.forEach(group => {
                const refundableAmount = group.refundable_amount;
                const isSelected = selectedRefundItems.some(item => item.drug_id === group.drug_id);
                const safeProductName = (group.product_name || `Drug #${group.drug_id}`).replace(/'/g, "\\'");
                
                html += `
                    <tr class="refund-product-row" data-product="${(group.product_name || '').toLowerCase()}" data-drug-id="${group.drug_id}" style="border-bottom: 1px solid #e9ecef; ${isSelected ? 'background-color: #e3f2fd;' : ''}">
                        <td style="padding: 12px; text-align: center; border-right: 1px solid #e9ecef;">
                            <input type="checkbox" class="product-checkbox" data-drug-id="${group.drug_id}" 
                                   onchange="toggleProductSelection(${group.drug_id}, '${safeProductName}', ${group.total_remaining}, ${refundableAmount})"
                                   ${isSelected ? 'checked' : ''} style="transform: scale(1.2);">
                        </td>
                        <td style="padding: 12px; border-right: 1px solid #e9ecef;">
                            <div style="font-weight: 600; color: #495057; font-size: 14px; display: flex; align-items: center;">
                                <i class="fas fa-pills" style="color: #ffc107; margin-right: 10px; font-size: 16px;"></i>
                                <div>
                                    <div style="font-weight: 700; color: #333; font-size: 13px;">${group.product_name}</div>
                                    <div style="font-size: 11px; color: #dc3545; font-weight: 600; margin-top: 2px;">
                                        <i class="fas fa-exclamation-triangle"></i> Refundable
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td style="padding: 12px; text-align: center; font-weight: 600; color: #495057; font-size: 13px;">${formatQuantityWithUnit(group.total_bought, group.form)}</td>
                        <td style="padding: 12px; text-align: center; font-weight: 600; color: #28a745; font-size: 13px;">${formatQuantityWithUnit(group.total_dispensed, group.form)}</td>
                        <td style="padding: 12px; text-align: center; font-weight: 600; color: #17a2b8; font-size: 13px;">${formatQuantityWithUnit(group.total_administered, group.form)}</td>
                        <td style="padding: 12px; text-align: center; font-weight: 700; color: #dc3545; font-size: 14px;">${formatQuantityWithUnit(group.total_remaining, group.form)}</td>
                        <td style="padding: 12px; text-align: center;">
                            <input type="number" min="0" max="${group.total_remaining}" value="${isSelected ? (selectedRefundItems.find(item => item.drug_id === group.drug_id)?.selected_quantity || 0) : 0}" 
                                   style="width: 60px; padding: 6px; border: 1px solid #ced4da; border-radius: 4px; text-align: center; font-size: 12px; font-weight: 600;"
                                   onchange="updateItemQuantityInTable(${group.drug_id}, this.value, ${group.total_remaining}, ${refundableAmount}, ${group.original_per_unit_price || refundableAmount / group.total_remaining})"
                                   ${isSelected ? '' : 'disabled'}>
                        </td>
                        <td style="padding: 12px; text-align: center; font-weight: 700; color: #28a745; font-size: 14px;" id="refund-amount-${group.drug_id}">
                            $${isSelected ? (selectedRefundItems.find(item => item.drug_id === group.drug_id)?.refundable_amount || refundableAmount).toFixed(2) : refundableAmount.toFixed(2)}
                        </td>
                        <td style="padding: 12px; text-align: center;">
                            <button onclick="viewProductDetails(${group.drug_id}, '${safeProductName}', ${group.total_remaining}, ${refundableAmount})" 
                                    style="background: #007bff; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600; transition: all 0.3s ease;"
                                    onmouseover="this.style.backgroundColor='#0056b3'" onmouseout="this.style.backgroundColor='#007bff'">
                                <i class="fas fa-eye"></i> Details
                            </button>
                        </td>
                    </tr>`;
            });
            
            html += `
                        </tbody>
                    </table>
                </div>`;
            
            itemsList.innerHTML = html;
        }

        function selectRefundItem(itemId, item) {
            console.log('selectRefundItem - itemId:', itemId, 'item:', item);
            
            currentRefundData = {
                itemId: itemId,
                drugId: item.drug_id,
                drugName: item.drug_name,
                maxQuantity: item.item_type === 'remaining_dispense' ? item.remaining_quantity : item.quantity,
                originalAmount: item.original_amount,
                totalAmount: item.total_amount,
                itemType: item.item_type
            };
            
            console.log('selectRefundItem - currentRefundData set to:', currentRefundData);
            
            // Populate refund details
            populateRefundModal(item);
            
            // Show refund details section
            document.getElementById('refundDetails').style.display = 'block';
            document.getElementById('processRefundBtn').style.display = 'inline-block';
            
            // Highlight selected item
            document.querySelectorAll('.refundable-item').forEach(el => {
                el.style.borderLeft = '3px solid transparent';
            });
            event.currentTarget.style.borderLeft = '3px solid #007bff';
        }

        function populateRefundModal(item) {
            // Populate item details
            const itemInfo = document.getElementById('refundItemInfo');
            const quantity = item.item_type === 'remaining_dispense' ? item.remaining_quantity : item.quantity;
            const itemType = item.item_type === 'remaining_dispense' ? 'Remaining Dispense' : 'Transaction';
            
            itemInfo.innerHTML = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; font-size: 14px;">
                    <div>
                        <div style="margin-bottom: 10px;">
                            <strong style="color: #495057;">Product:</strong> 
                            <span style="color: #333; font-weight: 600;">${item.drug_name}</span>
                        </div>
                        <div style="margin-bottom: 10px;">
                            <strong style="color: #495057;">Lot Number:</strong> 
                            <span style="color: #333;">${item.lot_number}</span>
                        </div>
                        <div style="margin-bottom: 10px;">
                            <strong style="color: #495057;">Receipt:</strong> 
                            <span style="color: #333; font-family: monospace;">${item.receipt_number}</span>
                        </div>
                        <div>
                            <strong style="color: #495057;">Type:</strong> 
                            <span style="background: ${item.item_type === 'remaining_dispense' ? '#ffc107' : '#17a2b8'}; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600;">${itemType}</span>
                        </div>
                    </div>
                    <div>
                        <div style="margin-bottom: 10px;">
                            <strong style="color: #495057;">Original Amount:</strong> 
                            <span style="color: #333; font-weight: 600;">$${parseFloat(item.original_amount).toFixed(2)}</span>
                        </div>
                        <div style="margin-bottom: 10px;">
                            <strong style="color: #495057;">Available Quantity:</strong> 
                            <span style="color: #333; font-weight: 600;">${quantity}</span>
                        </div>
                        <div style="margin-bottom: 10px;">
                            <strong style="color: #495057;">Refundable Amount:</strong> 
                            <span style="color: #28a745; font-weight: 700; font-size: 16px;">$${parseFloat(item.total_amount).toFixed(2)}</span>
                        </div>
                        <div>
                            <strong style="color: #495057;">Date:</strong> 
                            <span style="color: #333;">${new Date(item.created_date).toLocaleDateString()} ${new Date(item.created_date).toLocaleTimeString()}</span>
                        </div>
                    </div>
                </div>
            `;
            
            // Set max quantity
            document.getElementById('maxRefundQuantity').textContent = quantity;
            document.getElementById('refundQuantity').max = quantity;
            document.getElementById('refundQuantity').value = 1;
            
            // Calculate initial refund amount
            calculateRefundAmount();
            
            // Load patient credit balance
            loadPatientCreditBalance();
        }

        function calculateRefundAmount() {
            const quantity = parseInt(document.getElementById('refundQuantity').value) || 0;
            const maxQuantity = currentRefundData.maxQuantity;
            const totalAmount = currentRefundData.totalAmount;
            
            if (quantity > maxQuantity) {
                document.getElementById('refundQuantity').value = maxQuantity;
                return;
            }
            
            if (maxQuantity > 0) {
                const refundAmount = (quantity / maxQuantity) * totalAmount;
            }
        }

        function toggleRefundType() {
            const refundType = document.querySelector('input[name="refundType"]:checked').value;
            
            if (refundType === 'credit') {
                loadPatientCreditBalance();
            }
        }

        function loadPatientCreditBalance() {
            fetch('pos_refund_system.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'get_patient_credit',
                    pid: currentPatientId,
                    csrf_token: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update all patient credit balance displays
                    const newBalance = parseFloat(data.balance).toFixed(2);
                    
                    const balanceElements = document.querySelectorAll('#patientCreditBalance');
                    balanceElements.forEach(element => {
                        element.textContent = '$' + newBalance;
                    });
                    
                    // Update refund section credit balance
                    updateRefundSectionCreditBalance();
                    // Update transfer section credit balance
                    const transferBalanceElement = document.getElementById('transferSectionCreditBalance');
                    if (transferBalanceElement) {
                        transferBalanceElement.textContent = '$' + parseFloat(data.balance).toFixed(2);
                    }
                    
                    // Show/hide Transfer button based on credit balance
                    const balance = parseFloat(data.balance) || 0;
                    const transferButtonContainer = document.getElementById('creditTransferButtonContainer');
                    if (transferButtonContainer) {
                        transferButtonContainer.style.display = balance > 0 ? 'block' : 'none';
                    }
                }
            })
            .catch(error => {
                console.error('Error loading credit balance:', error);
            });
        }

        function processRefund() {
            console.log('processRefund - Starting with currentPatientId:', currentPatientId);
            console.log('processRefund - currentRefundType:', currentRefundType);
            console.log('processRefund - selectedRefundItems:', selectedRefundItems);
            
            const refundType = document.querySelector('input[name="refundType"]:checked').value;
            const reason = document.getElementById('refundReason').value.trim();
            
            if (currentRefundType === 'dispense') {
                // Multi-product dispense refund
                if (selectedRefundItems.length === 0) {
                    showNotification('Please select at least one product for refund', 'error');
                    return;
                }
                
                // Validate all selected items
                for (const item of selectedRefundItems) {
                    if (item.selected_quantity <= 0 || item.selected_quantity > item.max_quantity) {
                        showNotification(`Invalid quantity for ${item.product_name}`, 'error');
                        return;
                    }
                }
                
            } else if (currentRefundType === 'other') {
                // Manual refund - use entered amount
                const refundAmount = parseFloat(document.getElementById('manualRefundAmount').value) || 0;
                
                if (refundAmount <= 0) {
                    showNotification('Please enter a valid refund amount', 'error');
                    return;
                }
            }
            
            // Show loading state
            const btn = document.getElementById('processRefundBtn');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;
            
            // Prepare request data based on refund type
            let requestData;
            
            if (currentRefundType === 'dispense') {
                // Multi-product refund
                requestData = {
                    action: 'process_multi_refund',
                    pid: currentPatientId,
                    selected_items: selectedRefundItems,
                    refund_type: refundType,
                    refund_category: currentRefundType,
                    reason: reason,
                    csrf_token: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                };
            } else {
                // Manual refund
                const refundAmount = parseFloat(document.getElementById('manualRefundAmount').value) || 0;
                requestData = {
                    action: 'process_refund',
                    pid: currentPatientId,
                    item_id: null,
                    refund_quantity: 0,
                    refund_type: refundType,
                    refund_amount: refundAmount,
                    refund_category: currentRefundType,
                    reason: reason,
                    csrf_token: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                };
            }
            
            // Debug logging
            console.log('processRefund - Sending data:', requestData);
            console.log('processRefund - currentRefundData:', currentRefundData);
            console.log('processRefund - selectedRefundItems details:', selectedRefundItems.map(item => ({
                drug_id: item.drug_id,
                product_name: item.product_name,
                selected_quantity: item.selected_quantity,
                refundable_amount: item.refundable_amount
            })));
            
            // Process refund
            fetch('pos_refund_system.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(requestData)
            })
            .then(response => {
                console.log('processRefund - Response status:', response.status);
                console.log('processRefund - Response headers:', response.headers);
                return response.text().then(text => {
                    console.log('processRefund - Raw response text:', text);
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('processRefund - JSON parse error:', e);
                        console.error('processRefund - Raw response that failed to parse:', text);
                        throw new Error('Invalid JSON response from server');
                    }
                });
            })
            .then(data => {
                console.log('processRefund - Response data:', data);
                if (data.success) {
                    showNotification('Refund processed successfully!', 'success');
                    hideRefundSection();
                    // Refresh dispense tracking
                    console.log('processRefund - Refreshing dispense tracking data...');
                    loadRemainingDispenseData();
                    // Refresh credit balance with a small delay to ensure database commit
                    console.log('processRefund - Refreshing credit balance...');
                    setTimeout(() => {
                        loadPatientCreditBalance();
                    }, 500);
                } else {
                    showNotification(data.error || 'Refund failed', 'error');
                }
            })
            .catch(error => {
                console.error('Refund error:', error);
                showNotification('Refund failed: ' + error.message, 'error');
            })
            .finally(() => {
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }

        function closeRefundModal() {
            // Use the new toggle system instead of modal
            hideRefundSection();
        }

        // ==================== CREDIT TRANSFER FUNCTIONS ====================
        
        let currentCreditTransferData = {
            targetPid: null,
            targetName: null
        };

        function openCreditTransferModal() {
            document.getElementById('creditTransferModal').style.display = 'block';
            // Load current patient credit balance
            loadPatientCreditBalance();
        }

        function searchCreditTransferPatient(searchTerm) {
            if (searchTerm.length < 2) {
                document.getElementById('creditTransferPatientResults').style.display = 'none';
                return;
            }
            
            fetch('pos_patient_search.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    search: searchTerm,
                    csrf_token: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.patients.length > 0) {
                    displayCreditTransferPatientResults(data.patients);
                } else {
                    document.getElementById('creditTransferPatientResults').style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Error searching patients:', error);
            });
        }

        function displayCreditTransferPatientResults(patients) {
            const resultsDiv = document.getElementById('creditTransferPatientResults');
            let html = '';
            
            patients.forEach(patient => {
                const gender = patient.sex === 'M' ? 'Male' : patient.sex === 'F' ? 'Female' : 'Other';
                html += `
                    <div class="search-result-item" onclick="selectCreditTransferPatient(${patient.pid}, '${patient.fname}', '${patient.lname}', '${patient.pubpid}', '${patient.phone || ''}', '${patient.dob || ''}', '${patient.sex || ''}')" style="background: white; padding: 8px 10px; border-bottom: 1px solid #f1f1f1; cursor: pointer; transition: background-color 0.2s ease;" onmouseover="this.style.backgroundColor='#e3f2fd'" onmouseout="this.style.backgroundColor='white'">
                        <div style="display: flex; gap: 8px; align-items: center; font-size: 12px; white-space: nowrap; overflow: hidden;">
                            <div style="font-weight: 600; color: #495057; min-width: 80px; flex: 1; overflow: hidden; text-overflow: ellipsis;">${patient.fname} ${patient.lname}</div>
                            <div style="color: #6c757d; min-width: 40px; text-align: center;">${gender}</div>
                            <div style="color: #6c757d; min-width: 50px; text-align: center;">${patient.dob}</div>
                            <div style="color: #6c757d; min-width: 60px; text-align: center;">${patient.phone || 'N/A'}</div>
                            ${patient.balance > 0 ? `<div style="color: #dc3545; font-weight: 500; min-width: 50px; text-align: center;">$${parseFloat(patient.balance).toFixed(2)}</div>` : ''}
                        </div>
                    </div>
                `;
            });
            
            resultsDiv.innerHTML = html;
            resultsDiv.style.display = 'block';
        }
        function selectCreditTransferPatient(pid, fname, lname, pubpid, phone, dob, sex) {
            currentCreditTransferData = {
                targetPid: pid,
                targetName: fname + ' ' + lname
            };
            
            const gender = sex === 'M' ? 'Male' : sex === 'F' ? 'Female' : 'Other';
            
            document.getElementById('selectedCreditTransferPatientInfo').innerHTML = `
                <div style="display: flex; gap: 25px; font-size: 13px; color: #555; align-items: center; flex-wrap: wrap;">
                    <div>
                        <strong style="font-size: 14px; color: #333;">${fname} ${lname}</strong>
                    </div>
                    <div>
                        <strong style="font-size: 13px;">Mobile:</strong> <span>${phone || 'N/A'}</span>
                    </div>
                    <div>
                        <strong style="font-size: 13px;">DOB:</strong> <span>${dob || 'N/A'}</span>
                    </div>
                    <div>
                        <strong style="font-size: 13px;">Gender:</strong> <span>${gender}</span>
                    </div>
                </div>
            `;
            
            document.getElementById('selectedCreditTransferPatient').style.display = 'block';
            document.getElementById('creditTransferPatientResults').style.display = 'none';
            document.getElementById('processCreditTransferBtn').disabled = false;
        }

        function processCreditTransfer() {
            const amount = parseFloat(document.getElementById('creditTransferAmount').value) || 0;
            const reason = document.getElementById('creditTransferReason').value.trim();
            
            if (amount <= 0) {
                showNotification('Please enter a valid transfer amount', 'error');
                return;
            }
            
            if (!currentCreditTransferData.targetPid) {
                showNotification('Please select a target patient', 'error');
                return;
            }
            
            // Show loading state
            const btn = document.getElementById('processCreditTransferBtn');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;
            
            // Process credit transfer
            fetch('pos_refund_system.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'transfer_credit',
                    from_pid: currentPatientId,
                    to_pid: currentCreditTransferData.targetPid,
                    amount: amount,
                    reason: reason,
                    csrf_token: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Credit transfer completed successfully!', 'success');
                    closeCreditTransferModal();
                    // Refresh credit balance display
                    loadPatientCreditBalance();
                } else {
                    showNotification(data.error || 'Credit transfer failed', 'error');
                }
            })
            .catch(error => {
                console.error('Credit transfer error:', error);
                showNotification('Credit transfer failed: ' + error.message, 'error');
            })
            .finally(() => {
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }

        function closeCreditTransferModal() {
            document.getElementById('creditTransferModal').style.display = 'none';
            // Reset form
            document.getElementById('creditTransferAmount').value = '';
            document.getElementById('creditTransferReason').value = '';
            document.getElementById('creditTransferPatientSearch').value = '';
            document.getElementById('selectedCreditTransferPatient').style.display = 'none';
            document.getElementById('creditTransferPatientResults').style.display = 'none';
            document.getElementById('processCreditTransferBtn').disabled = true;
            currentCreditTransferData = {
                targetPid: null,
                targetName: null
            };
        }

        // ==================== INLINE TRANSFER FUNCTIONS ====================
        
        function toggleCreditTransferSection() {
            const transferSection = document.getElementById('transfer-section');
            const orderSummary = document.getElementById('order-summary');
            const mainPosSection = document.getElementById('main-pos-section');
            const searchSection = document.querySelector('.search-section');
            const refundSection = document.getElementById('refund-section');
            const dispenseSection = document.getElementById('dispense-tracking-section');
            const weightSection = document.getElementById('weight-tracker-section');
            
            if (!transferSection) {
                return;
            }
            
            if (transferSection.style.display === 'block' || transferSection.style.display === '') {
                // Hide transfer section, show other sections
                transferSection.style.display = 'none';
                if (orderSummary) {
                    orderSummary.style.display = 'block';
                }
                if (mainPosSection) {
                    mainPosSection.style.display = 'block';
                }
                if (searchSection) {
                    searchSection.style.display = 'block';
                }
            } else {
                // Show transfer section, hide all other sections
                transferSection.style.display = 'block';
                if (orderSummary) {
                    orderSummary.style.display = 'none';
                }
                if (mainPosSection) {
                    mainPosSection.style.display = 'none';
                }
                if (searchSection) {
                    searchSection.style.display = 'none';
                }
                if (refundSection) {
                    refundSection.style.display = 'none';
                }
                if (dispenseSection) {
                    dispenseSection.style.display = 'none';
                }
                if (weightSection) {
                    weightSection.style.display = 'none';
                }
                
                // Load current credit balance and update displays
                loadPatientCreditBalance();
                updateTransferAmountDisplay();
            }
        }
        
        function updateTransferAmountDisplay() {
            const balanceElement = document.getElementById('patientCreditBalance');
            const transferAmountDisplay = document.getElementById('inlineCreditTransferAmountDisplay');
            const transferAmountInput = document.getElementById('inlineCreditTransferAmount');
            
            if (balanceElement) {
                const balance = parseFloat(balanceElement.textContent.replace('$', '')) || 0;
                
                // Update display
                if (transferAmountDisplay) {
                    transferAmountDisplay.textContent = '$' + balance.toFixed(2);
                }
                
                // Update hidden input with full balance
                if (transferAmountInput) {
                    transferAmountInput.value = balance.toFixed(2);
                }
                
                // Update transfer section balance
                const transferBalanceElement = document.getElementById('transferSectionCreditBalance');
                if (transferBalanceElement) {
                    transferBalanceElement.textContent = '$' + balance.toFixed(2);
                }
            }
        }
        
        function hideTransferSection() {
            const transferSection = document.getElementById('transfer-section');
            const orderSummary = document.getElementById('order-summary');
            const mainPosSection = document.getElementById('main-pos-section');
            const searchSection = document.querySelector('.search-section');
            
            if (transferSection) {
                transferSection.style.display = 'none';
            }
            
            // Show all sections when transfer section is closed
            if (orderSummary) {
                orderSummary.style.display = 'block';
            }
            if (mainPosSection) {
                mainPosSection.style.display = 'block';
            }
            if (searchSection) {
                searchSection.style.display = 'block';
            }
        }
        
        function validateTransferAmount() {
            // Since we're transferring full amount only, just check if patient is selected
            const transferBtn = document.getElementById('processInlineCreditTransferBtn');
            const patientSelected = currentCreditTransferData && currentCreditTransferData.targetPid;
            
            if (patientSelected && transferBtn) {
                transferBtn.disabled = false;
                return true;
            } else {
                if (transferBtn) {
                transferBtn.disabled = true;
            }
                return false;
            }
        }
        
        function searchInlineCreditTransferPatient(searchTerm) {
            const resultsDiv = document.getElementById('inlineCreditTransferPatientResults');
            
            if (!resultsDiv) {
                return;
            }
            
            if (searchTerm.length < 2) {
                resultsDiv.style.display = 'none';
                return;
            }
            
            resultsDiv.style.display = 'block';
            resultsDiv.innerHTML = '<div class="search-result-item" style="padding: 20px; text-align: center; color: #6c757d;"><i class="fas fa-spinner fa-spin"></i> Searching...</div>';
            
            // Get current patient ID
            const currentPid = <?php echo $pid ?: 'null'; ?> || currentPatientId;
            
            // Use the same endpoint as main POS patient search
            fetch('pos_modal.php?action=search_patients&search=' + encodeURIComponent(searchTerm))
            .then(response => response.json())
            .then(data => {
                if (data.success && data.patients && data.patients.length > 0) {
                let html = '';
                    data.patients.forEach(patient => {
                        const balance = patient.balance > 0 ? ` (Balance: $${parseFloat(patient.balance).toFixed(2)})` : '';
                    const gender = patient.sex ? patient.sex.charAt(0).toUpperCase() + patient.sex.slice(1) : 'N/A';
                        
                        // Check if this is the current patient
                        const isCurrentPatient = currentPid && parseInt(patient.pid) === parseInt(currentPid);
                        const disabledStyle = isCurrentPatient ? 'opacity: 0.5; cursor: not-allowed; background: #f8f9fa;' : '';
                        const onClickHandler = isCurrentPatient ? '' : `onclick="selectInlineCreditTransferPatient(${patient.pid}, '${patient.fname || ''}', '${patient.lname || ''}', '${patient.pubpid || ''}', '${patient.phone || ''}', '${patient.dob || ''}', '${patient.sex || ''}')"`;
                        const disabledMessage = isCurrentPatient ? '<span style="color: #dc3545; font-weight: 600; margin-left: 10px;">(Current Patient - Cannot Transfer to Self)</span>' : '';
                        
                    html += `
                            <div class="search-result-item" ${onClickHandler} style="background: white; ${disabledStyle}">
                                <div class="search-result-info">
                                    <div class="search-result-name">${patient.fname} ${patient.lname}${disabledMessage}</div>
                                    <div class="search-result-details" style="display: flex; gap: 30px; flex-wrap: wrap; margin-top: 8px; font-size: 16px;">
                                        <span style="font-size: 16px; color: #6c757d; font-weight: 500;">Gender: ${gender}</span>
                                        <span style="font-size: 16px; color: #6c757d; font-weight: 500;">DOB: ${patient.dob || 'N/A'}</span>
                                        <span style="font-size: 16px; color: #6c757d; font-weight: 500;">Phone: ${patient.phone || 'N/A'}</span>
                                        ${patient.balance > 0 ? `<span style="font-size: 16px; color: #dc3545; font-weight: 500;">Balance: $${parseFloat(patient.balance).toFixed(2)}</span>` : ''}
                                    </div>
                            </div>
                        </div>
                    `;
                });
                resultsDiv.innerHTML = html;
                } else if (data.success && data.patients && data.patients.length === 0) {
                    resultsDiv.innerHTML = '<div class="search-result-item" style="padding: 20px; text-align: center; color: #6c757d;">No patients found</div>';
            } else {
                    const errorMsg = data.error || 'Unknown error occurred';
                    resultsDiv.innerHTML = `<div class="search-result-item" style="padding: 20px; text-align: center; color: #dc3545;">Error: ${errorMsg}</div>`;
                }
            })
            .catch(error => {
                console.error('Error searching patients:', error);
                resultsDiv.innerHTML = '<div class="search-result-item" style="padding: 20px; text-align: center; color: #dc3545;">Error searching patients. Please try again.</div>';
            });
        }
        
        function selectInlineCreditTransferPatient(pid, fname, lname, pubpid, phone, dob, sex) {
            // Get current patient ID from PHP variable or currentPatientId
            const currentPid = <?php echo $pid ?: 'null'; ?> || currentPatientId;
            
            // Check if selected patient is the same as current patient
            if (currentPid && parseInt(pid) === parseInt(currentPid)) {
                alert('Cannot transfer credit to the same patient. Please select a different patient.');
                return;
            }
            
            currentCreditTransferData = {
                targetPid: pid,
                targetName: fname + ' ' + lname
            };
            
            const gender = sex ? sex.charAt(0).toUpperCase() + sex.slice(1) : 'N/A';
            
            // Update patient display with all information in horizontal row format
            const patientName = `${fname} ${lname}`;
            const patientPhone = phone || 'N/A';
            const patientDob = dob || 'N/A';
            const patientGender = gender;
            
            document.getElementById('inlineSelectedCreditTransferPatientName').textContent = patientName;
            document.getElementById('inlineSelectedCreditTransferPatientMobile').textContent = patientPhone;
            document.getElementById('inlineSelectedCreditTransferPatientDob').textContent = patientDob;
            document.getElementById('inlineSelectedCreditTransferPatientGender').textContent = patientGender;
            
            // Hide search section and show patient display
            document.getElementById('inlineCreditTransferPatientSearchSection').style.display = 'none';
            document.getElementById('inlineCreditTransferPatientDisplay').style.display = 'block';
            document.getElementById('inlineCreditTransferPatientResults').style.display = 'none';
            
            // Enable transfer button if amount is valid
            const transferBtn = document.getElementById('processInlineCreditTransferBtn');
            if (validateTransferAmount()) {
                transferBtn.disabled = false;
            }
        }
        
        function editInlineCreditTransferPatient() {
            // Show search section and hide patient display
            document.getElementById('inlineCreditTransferPatientSearchSection').style.display = 'block';
            document.getElementById('inlineCreditTransferPatientDisplay').style.display = 'none';
            
            // Clear search input
            document.getElementById('inlineCreditTransferPatientSearch').value = '';
            
            // Reset current data
            currentCreditTransferData = {
                targetPid: null,
                targetName: null
            };
            
            // Disable transfer button
            document.getElementById('processInlineCreditTransferBtn').disabled = true;
        }
        
        function processInlineCreditTransfer() {
            const amount = parseFloat(document.getElementById('inlineCreditTransferAmount').value) || 0;
            const reason = document.getElementById('inlineCreditTransferReason').value.trim();
            
            if (amount <= 0) {
                showNotification('Please enter a valid transfer amount', 'error');
                return;
            }
            
            if (!currentCreditTransferData.targetPid) {
                showNotification('Please select a target patient', 'error');
                return;
            }
            
            // Show loading state
            const btn = document.getElementById('processInlineCreditTransferBtn');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;
            
            // Process credit transfer
            fetch('pos_refund_system.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'transfer_credit',
                    from_pid: currentPatientId,
                    to_pid: currentCreditTransferData.targetPid,
                    amount: amount,
                    reason: reason,
                    csrf_token: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Credit transfer completed successfully!', 'success');
                    hideTransferSection();
                    // Refresh credit balance display
                    loadPatientCreditBalance();
                    // Reset form
                    resetInlineTransferForm();
                } else {
                    showNotification(data.error || 'Credit transfer failed', 'error');
                }
            })
            .catch(error => {
                console.error('Credit transfer error:', error);
                showNotification('Credit transfer failed: ' + error.message, 'error');
            })
            .finally(() => {
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }
        
        function resetInlineTransferForm() {
            const amountInput = document.getElementById('inlineCreditTransferAmount');
            const reasonInput = document.getElementById('inlineCreditTransferReason');
            const searchInput = document.getElementById('inlineCreditTransferPatientSearch');
            const searchSection = document.getElementById('inlineCreditTransferPatientSearchSection');
            const patientDisplay = document.getElementById('inlineCreditTransferPatientDisplay');
            const resultsDiv = document.getElementById('inlineCreditTransferPatientResults');
            const transferBtn = document.getElementById('processInlineCreditTransferBtn');
            const errorDiv = document.getElementById('transferAmountError');
            
            if (amountInput) amountInput.value = '';
            if (reasonInput) reasonInput.value = '';
            if (searchInput) searchInput.value = '';
            if (searchSection) searchSection.style.display = 'block';
            if (patientDisplay) patientDisplay.style.display = 'none';
            if (resultsDiv) resultsDiv.style.display = 'none';
            if (transferBtn) transferBtn.disabled = true;
            if (errorDiv) errorDiv.style.display = 'none';
            
            currentCreditTransferData = {
                targetPid: null,
                targetName: null
            };
        }

        // Credit Balance Payment Functions
        function loadCreditBalanceForPayment() {
            const currentPid = <?php echo $pid ?: 'null'; ?>;
            if (!currentPid) {
                console.log('loadCreditBalanceForPayment - No patient ID available');
                return;
            }

            console.log('loadCreditBalanceForPayment - Loading credit balance for patient:', currentPid);

            fetch('pos_refund_system.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'get_patient_credit',
                    pid: currentPid,
                    csrf_token: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                console.log('loadCreditBalanceForPayment - Response:', data);
                if (data.success) {
                    const balance = parseFloat(data.balance) || 0;
                    console.log('loadCreditBalanceForPayment - Balance:', balance);
                    
                    // Update credit balance display - update all instances
                    const patientCreditBalanceElements = document.querySelectorAll('#patientCreditBalance');
                    console.log('loadCreditBalanceForPayment - Found credit balance elements:', patientCreditBalanceElements.length);
                    
                    patientCreditBalanceElements.forEach(element => {
                        element.textContent = '$' + balance.toFixed(2);
                        console.log('loadCreditBalanceForPayment - Updated element:', element.textContent);
                    });
                    
                    // Show/hide credit balance section based on available balance
                    const creditSection = document.getElementById('credit-balance-section');
                    console.log('loadCreditBalanceForPayment - Credit section found:', !!creditSection);
                    
                    if (balance > 0) {
                        creditSection.style.display = 'block';
                        console.log('loadCreditBalanceForPayment - Showing credit section (balance > 0)');
                    } else {
                        creditSection.style.display = 'none';
                        console.log('loadCreditBalanceForPayment - Hiding credit section (balance = 0)');
                    }
                    
                    // Show/hide Transfer button based on credit balance
                    const transferButtonContainer = document.getElementById('creditTransferButtonContainer');
                    if (transferButtonContainer) {
                        if (balance > 0) {
                            transferButtonContainer.style.display = 'block';
                            console.log('loadCreditBalanceForPayment - Showing Transfer button (balance > 0)');
                        } else {
                            transferButtonContainer.style.display = 'none';
                            console.log('loadCreditBalanceForPayment - Hiding Transfer button (balance = 0)');
                        }
                    }
                } else {
                    console.log('loadCreditBalanceForPayment - Failed to get credit balance:', data.error);
                }
            })
            .catch(error => {
                console.error('loadCreditBalanceForPayment - Error:', error);
            });
        }

        function toggleCreditApplication() {
            const checkbox = document.getElementById('apply-credit-checkbox');
            
            if (checkbox.checked) {
                // Activate credit application
                // Auto-apply full credit balance when activated
                applyFullCreditBalance();
            } else {
                // Deactivate credit application
                // Reset credit amount to 0
                window.creditAmount = 0;
                updatePaymentSummary();
            }
        }
        function applyFullCreditBalance() {
            const availableBalance = parseFloat(document.getElementById('patientCreditBalance').textContent.replace('$', '')) || 0;
            const originalTotal = getOriginalOrderTotal();

            let taxableTotal = 0;
            cart.forEach(item => {
                if (isTaxableItem(item)) {
                    taxableTotal += getSimpleDiscountedItemTotal(item);
                }
            });

            // Calculate total after tax (before credit)
            let totalAfterTax = originalTotal;
            if (Object.keys(taxRates).length > 0) {
                Object.keys(taxRates).forEach(taxId => {
                    const taxRate = taxRates[taxId];
                    const itemTax = taxableTotal * (taxRate.rate / 100);
                    totalAfterTax += itemTax;
                });
            }
            
            // Use the smaller of available balance or total after tax
            const creditToApply = Math.min(availableBalance, totalAfterTax);
            window.creditAmount = creditToApply;
            
            updatePaymentSummary();
        }
        
        function getOriginalOrderTotal() {
            // Calculate the original total from cart items
            let total = 0;
            cart.forEach(item => {
                total += getSimpleDiscountedItemTotal(item);
            });
            return total;
        }

        function getCheckoutTotals() {
            let subtotal = 0;
            let discountedSubtotal = 0;
            let taxableSubtotal = 0;
            let taxTotal = 0;
            let taxBreakdown = {};

            cart.forEach(item => {
                const originalItemTotal = isItemPrepaid(item)
                    ? 0
                    : Math.round((item.original_price * item.quantity) * 100) / 100;
                const discountedItemTotal = getSimpleDiscountedItemTotal(item);

                subtotal += originalItemTotal;
                discountedSubtotal += discountedItemTotal;
                if (isTaxableItem(item)) {
                    taxableSubtotal += discountedItemTotal;
                }
            });

            subtotal = Math.round(subtotal * 100) / 100;
            discountedSubtotal = Math.round(discountedSubtotal * 100) / 100;

            if (Object.keys(taxRates).length > 0) {
                Object.keys(taxRates).forEach(taxId => {
                    const taxRate = taxRates[taxId];
                    const itemTax = Math.round((taxableSubtotal * (taxRate.rate / 100)) * 100) / 100;
                    taxTotal += itemTax;
                    if (!taxBreakdown[taxId]) {
                        taxBreakdown[taxId] = {
                            name: taxRate.name,
                            amount: 0,
                            rate: taxRate.rate
                        };
                    }
                    taxBreakdown[taxId].amount = Math.round((taxBreakdown[taxId].amount + itemTax) * 100) / 100;
                });
            }

            taxTotal = Math.round(taxTotal * 100) / 100;

            const creditCheckbox = document.getElementById('apply-credit-checkbox');
            const creditAmount = creditCheckbox && creditCheckbox.checked ? (window.creditAmount || 0) : 0;
            const tenDollarOffAmount = Math.round(getTenDollarOffAmount() * 100) / 100;
            const adjustedSubtotal = Math.max(0, Math.round((discountedSubtotal - tenDollarOffAmount) * 100) / 100);
            const totalBeforeCredit = Math.round((adjustedSubtotal + taxTotal) * 100) / 100;
            const paidSoFar = (window._posPayments || []).reduce((sum, p) => sum + (parseFloat(p.amount) || 0), 0);
            const totalPaid = Math.round((paidSoFar + creditAmount) * 100) / 100;
            const due = Math.max(0, Math.round((totalBeforeCredit - totalPaid) * 100) / 100);
            const grandTotal = Math.max(0, Math.round((totalBeforeCredit - creditAmount) * 100) / 100);

            return {
                subtotal,
                discountedSubtotal,
                adjustedSubtotal,
                taxableSubtotal,
                taxTotal,
                taxBreakdown,
                tenDollarOffAmount,
                creditAmount: Math.round(creditAmount * 100) / 100,
                totalBeforeCredit,
                paidSoFar: Math.round(paidSoFar * 100) / 100,
                totalPaid,
                due,
                grandTotal
            };
        }

        function renderCheckoutPaymentSummary() {
            const summaryEl = document.getElementById('payment-total');
            if (!summaryEl) {
                return;
            }

            const totals = getCheckoutTotals();
            summaryEl.innerHTML = `
                <div class="payment-summary">
                    <div class="payment-metrics">
                        <div class="metric total">
                            <div class="metric-label">Total</div>
                            <div class="metric-value">$${totals.totalBeforeCredit.toFixed(2)}</div>
                        </div>
                        <div class="metric paid">
                            <div class="metric-label">Paid</div>
                            <div class="metric-value">$${totals.totalPaid.toFixed(2)}</div>
                        </div>
                        <div class="metric due">
                            <div class="metric-label">Due</div>
                            <div class="metric-value">$${totals.due.toFixed(2)}</div>
                        </div>
                    </div>
                </div>
            `;

            const listWrap = document.getElementById('payments-list');
            const listUl = document.getElementById('payments-ul');
            if (listWrap && listUl) {
                const payments = [];
                if (totals.creditAmount > 0) {
                    payments.push({ method: 'credit balance', amount: totals.creditAmount });
                }
                (window._posPayments || []).forEach(payment => payments.push(payment));

                if (payments.length > 0) {
                    listWrap.style.display = 'block';
                    listUl.innerHTML = payments.map((p, idx) => {
                        const methodLabel = String(p.method || 'payment').replace(/_/g, ' ').toUpperCase();
                        const amountValue = parseFloat(p.amount) || 0;
                        return `<li><span>${idx + 1}. ${methodLabel}</span><span>$${amountValue.toFixed(2)}</span></li>`;
                    }).join('');
                } else {
                    listWrap.style.display = 'none';
                    listUl.innerHTML = '';
                }
            }
        }

        function validateCreditAmount() {
            updatePaymentSummary();
        }
        
        function updatePaymentSummary() {
            const creditAmount = window.creditAmount || 0;
            const creditBalanceNode = document.getElementById('patientCreditBalance');
            const availableBalance = creditBalanceNode
                ? (parseFloat(creditBalanceNode.textContent.replace('$', '')) || 0)
                : 0;
            const totals = getCheckoutTotals();
            
            // Validate credit amount
            let validCreditAmount = creditAmount;
            if (validCreditAmount > availableBalance) {
                validCreditAmount = availableBalance;
                window.creditAmount = validCreditAmount;
            }
            if (validCreditAmount > totals.totalBeforeCredit) {
                validCreditAmount = totals.totalBeforeCredit;
                window.creditAmount = validCreditAmount;
            }
            
            // Re-render the order summary to show credit row in table
            renderOrderSummary();
            renderCheckoutPaymentSummary();
            
            // Update payment button text based on final total
            updatePaymentButton();
        }

        // Check credit balance availability on page load
        function checkCreditBalanceAvailability() {
            const currentPid = <?php echo $pid ?: 'null'; ?>;
            
            if (!currentPid) {
                return;
            }

            fetch('pos_refund_system.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'get_patient_credit',
                    pid: currentPid,
                    csrf_token: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const balance = parseFloat(data.balance) || 0;
                    const creditSection = document.getElementById('credit-balance-section');
                    const patientCreditBalance = document.getElementById('patientCreditBalance');
                    
                    // Update all credit balance displays
                    const balanceElements = document.querySelectorAll('#patientCreditBalance');
                    balanceElements.forEach(element => {
                        element.textContent = '$' + balance.toFixed(2);
                    });
                    
                    if (balance > 0) {
                        creditSection.style.display = 'block';
                    } else {
                        creditSection.style.display = 'none';
                    }
                }
            })
            .catch(error => {
                console.error('Error checking credit balance availability:', error);
            });
        }

        // Initialize credit balance check on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Check credit balance availability for payment
            setTimeout(checkCreditBalanceAvailability, 500);
        });
        
        // Complete purchase function for when payment is fully processed
        async function completePurchase(result) {
            if (!requireSelectionOrStop()) return;
            try {
                console.log('completePurchase called with result:', result);
                
                // Show success message
                let message = result.message || 'Payment recorded successfully!';
                alert(message);
                
                // Open receipt window if receipt data is available
                if (result.receipt_number) {
                    const currentPid = <?php echo $pid ?: 'null'; ?>;
                    let receiptUrl = '';
                    if (currentPid) {
                        receiptUrl = `pos_receipt.php?receipt_number=${result.receipt_number}&pid=${currentPid}&_ts=${Date.now()}`;
                    } else {
                        receiptUrl = `pos_receipt.php?receipt_number=${result.receipt_number}&_ts=${Date.now()}`;
                    }
                    const left = Math.max(0, (window.screen.availWidth - 400) / 2);
                    const top = Math.max(0, (window.screen.availHeight - 600) / 2);
                    const receiptWindow = window.open(receiptUrl, '_blank', `width=400,height=600,left=${left},top=${top},scrollbars=yes,resizable=yes,status=no,location=no,toolbar=no,menubar=no`);
                    if (receiptWindow) { 
                        receiptWindow.focus(); 
                    }
                }
                
                // Clear cart and refresh UI
                cart = [];
                tenDollarOffEnabled = false;
                clearPersistentCart();
                syncTenDollarOffToggleState();
                updateOrderSummary();
                
                // Refresh patient-related data
                setTimeout(() => {
                    if (typeof loadPatientCreditBalance === 'function') { loadPatientCreditBalance(); }
                    if (typeof checkCreditBalanceAvailability === 'function') { checkCreditBalanceAvailability(); }
                    if (typeof refreshPatientData === 'function') { refreshPatientData(); }
                    if (typeof loadRemainingDispenseData === 'function') {
                        console.log('Complete purchase - refreshing dispense tracking...');
                        loadRemainingDispenseData();
                    }
                }, 500);
                
            } catch (error) {
                console.error('Error in completePurchase:', error);
                alert('Payment completed but there was an error: ' + error.message);
            }
        }

        function openOperationalReceiptWindow(receiptNumber) {
            if (!receiptNumber) {
                return;
            }

            const currentPid = <?php echo $pid ?: 'null'; ?>;
            let receiptUrl = '';
            if (currentPid) {
                receiptUrl = `pos_receipt.php?receipt_number=${receiptNumber}&pid=${currentPid}&_ts=${Date.now()}`;
            } else {
                receiptUrl = `pos_receipt.php?receipt_number=${receiptNumber}&_ts=${Date.now()}`;
            }

            const left = Math.max(0, (window.screen.availWidth - 400) / 2);
            const top = Math.max(0, (window.screen.availHeight - 600) / 2);
            const receiptWindow = window.open(receiptUrl, '_blank', `width=400,height=600,left=${left},top=${top},scrollbars=yes,resizable=yes,status=no,location=no,toolbar=no,menubar=no`);
            if (receiptWindow) {
                receiptWindow.focus();
            }
        }

        async function completePurchaseWithCredit() {
            const processBtn = document.getElementById('process-payment-btn');
            
            // Disable button and show processing
            processBtn.disabled = true;
            processBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            
            try {
                const currentPid = <?php echo $pid ?: 'null'; ?>;
                const creditAmount = window.creditAmount || 0;
                
                if (creditAmount <= 0) {
                    throw new Error('No credit amount applied');
                }

                if (!window.currentInvoiceNumber) {
                    window.currentInvoiceNumber = generateFacilityInvoiceNumber('INV');
                }

                if (!validatePrepayDetails()) {
                    processBtn.disabled = false;
                    processBtn.innerHTML = '<i class="fas fa-lock"></i> Process Payment';
                    return;
                }
                
                const totals = getCheckoutTotals();
                
                let paymentData = {
                    action: 'record_external_payment',
                    pid: currentPid,
                    patient_number: getCurrentPatientNumberValue(),
                    amount: 0,
                    method: 'credit',
                    reference: '',
                    invoice_number: window.currentInvoiceNumber || '',
                    credit_amount: Math.round(creditAmount * 100) / 100,
                    items: cart.map(item => buildCheckoutItemPayload(item)),
                    subtotal: totals.adjustedSubtotal,
                    tax_total: totals.taxTotal,
                    tax_breakdown: totals.taxBreakdown,
                    ten_off_discount: totals.tenDollarOffAmount,
                    prepay_enabled: prepayModeEnabled,
                    prepay_details: prepayDetails,
                    visit_type: (window.getVisitType ? window.getVisitType() : "-"),
                    price_override_notes: (window._priceOverrideNotes || '').trim(),
                    csrf_token_form: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                };
                
                console.log('Complete Purchase with Credit - Payment Data:', paymentData);
                
                const response = await fetch('pos_payment_processor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(paymentData),
                    credentials: 'include'
                });
                
                console.log('Complete Purchase with Credit - Response status:', response.status);
                console.log('Complete Purchase with Credit - Response headers:', response.headers);
                
                const responseText = await response.text();
                console.log('Complete Purchase with Credit - Raw response text:', responseText);
                
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('Complete Purchase with Credit - JSON parse error:', parseError);
                    console.error('Complete Purchase with Credit - Raw response that failed to parse:', responseText);
                    throw new Error('Invalid JSON response from server');
                }
                
                console.log('Complete Purchase with Credit - Response:', data);
                
                if (data.success) {
                    const finalizeResponse = await fetch('pos_payment_processor.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            action: 'finalize_invoice',
                            pid: currentPid,
                            invoice_number: window.currentInvoiceNumber || '',
                            patient_number: getCurrentPatientNumberValue(),
                            items: paymentData.items,
                            subtotal: paymentData.subtotal,
                            tax_total: paymentData.tax_total,
                            tax_breakdown: paymentData.tax_breakdown,
                            credit_amount: paymentData.credit_amount,
                            ten_off_discount: paymentData.ten_off_discount,
                            csrf_token_form: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                        }),
                        credentials: 'include'
                    });

                    const finalizeData = await finalizeResponse.json();
                    if (!finalizeData.success) {
                        throw new Error(finalizeData.error || 'Finalize failed');
                    }

                    await completePurchase(finalizeData);
                    window._posPayments = [];
                    window.currentInvoiceNumber = null;
                    window.creditAmount = 0;
                    tenDollarOffEnabled = false;

                    const creditCheckbox = document.getElementById('apply-credit-checkbox');
                    if (creditCheckbox) {
                        creditCheckbox.checked = false;
                    }

                    syncTenDollarOffToggleState();
                    updatePaymentSummary();
                    setTimeout(checkCreditBalanceAvailability, 100);
                    
                } else {
                    throw new Error(data.error || 'Failed to complete purchase');
                }
                
            } catch (error) {
                console.error('Complete Purchase with Credit - Error:', error);
                alert('Error completing purchase: ' + error.message);
            } finally {
                // Re-enable button
                processBtn.disabled = false;
                processBtn.innerHTML = '<i class="fas fa-credit-card"></i> Process Payment';
            }
        }

        function buildWeightChartExportBlob() {
            return new Promise((resolve, reject) => {
                if (!currentPatientId) {
                    reject(new Error('No patient selected'));
                    return;
                }
                if (!weightChart) {
                    reject(new Error('No weight chart available to export. Please ensure weight history is loaded.'));
                    return;
                }

                const patientName = getCurrentPosPatientName();
                const sanitizedPatientName = patientName.replace(/[^a-zA-Z0-9]/g, '_');
                const currentDate = new Date().toISOString().split('T')[0];
                const canvas = document.getElementById('weightChart');
                if (!canvas) {
                    reject(new Error('Chart canvas not found'));
                    return;
                }

                const tempCanvas = document.createElement('canvas');
                const tempCtx = tempCanvas.getContext('2d');
                if (!tempCtx) {
                    reject(new Error('Unable to create chart export context'));
                    return;
                }

                const scale = 2;
                const headerHeight = 60;
                tempCanvas.width = canvas.width * scale;
                tempCanvas.height = (canvas.height * scale) + headerHeight;
                tempCtx.scale(scale, scale);
                tempCtx.fillStyle = '#ffffff';
                tempCtx.fillRect(0, 0, canvas.width, canvas.height + (headerHeight / scale));
                tempCtx.fillStyle = '#333333';
                tempCtx.font = 'bold 16px Arial';
                tempCtx.textAlign = 'center';
                tempCtx.fillText(`${patientName} - Weight Progress Chart`, canvas.width / 2, 25);
                tempCtx.font = '12px Arial';
                tempCtx.fillText(`Generated on ${new Date().toLocaleDateString()}`, canvas.width / 2, 45);
                tempCtx.drawImage(canvas, 0, headerHeight / scale);

                tempCanvas.toBlob(function(blob) {
                    if (!blob) {
                        reject(new Error('Failed to generate chart image'));
                        return;
                    }
                    resolve({
                        blob,
                        patientName,
                        fileName: `Weight_Chart_${sanitizedPatientName}_${currentDate}.png`
                    });
                }, 'image/png', 1.0);
            });
        }

        // Download Weight Chart Function
        async function downloadWeightChart() {
            const downloadBtn = document.getElementById('download-chart-btn');
            const originalText = downloadBtn.innerHTML;
            downloadBtn.disabled = true;
            downloadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Downloading...';

            try {
                const exportData = await buildWeightChartExportBlob();
                const url = URL.createObjectURL(exportData.blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = exportData.fileName;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                alert('Weight chart downloaded successfully!');
            } catch (error) {
                console.error('Error downloading weight chart:', error);
                alert('Error downloading chart: ' + error.message);
            } finally {
                downloadBtn.disabled = false;
                downloadBtn.innerHTML = originalText;
            }
        }

        async function emailWeightChart() {
            if (!currentPatientId) {
                alert('No patient selected');
                return;
            }

            if (!currentWeightHistoryData || currentWeightHistoryData.length === 0) {
                alert('No weight history available to email.');
                return;
            }

            const defaultEmail = localStorage.getItem('posWeightChartEmail') || '';
            const recipientEmail = prompt('Enter the email address for this weight chart:', defaultEmail);
            if (!recipientEmail) {
                return;
            }

            const trimmedEmail = recipientEmail.trim();
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmedEmail)) {
                alert('Please enter a valid email address.');
                return;
            }

            const emailBtn = document.getElementById('email-chart-btn');
            const originalText = emailBtn.innerHTML;
            emailBtn.disabled = true;
            emailBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Emailing...';

            try {
                const exportData = await buildWeightChartExportBlob();
                const chartDataUrl = await new Promise((resolve, reject) => {
                    const reader = new FileReader();
                    reader.onloadend = () => resolve(reader.result);
                    reader.onerror = () => reject(new Error('Failed to prepare chart image for email.'));
                    reader.readAsDataURL(exportData.blob);
                });

                const response = await fetch('pos_weight_handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    credentials: 'include',
                    body: JSON.stringify({
                        action: 'email_weight_chart',
                        pid: currentPatientId,
                        to_email: trimmedEmail,
                        patient_name: exportData.patientName,
                        chart_filename: exportData.fileName,
                        chart_image: chartDataUrl,
                        weight_history: currentWeightHistoryData,
                        csrf_token: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                    })
                });

                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.error || 'Failed to email weight chart.');
                }

                localStorage.setItem('posWeightChartEmail', trimmedEmail);
                alert(data.message || 'Weight chart emailed successfully!');
            } catch (error) {
                console.error('Error emailing weight chart:', error);
                alert('Error emailing chart: ' + error.message);
            } finally {
                emailBtn.disabled = false;
                emailBtn.innerHTML = originalText;
            }
        }

        // Generate Weight Report (DEPRECATED - Use downloadWeightChart instead)
        function generateWeightReport(weightHistory, patientId) {
            // Get patient data
            const patientName = document.querySelector('.patient-name')?.textContent || 'Patient';
            const currentDate = new Date().toLocaleDateString();
            
            // Create HTML content for the report
            let reportHtml = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Weight Tracking Report</title>
                    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; color: #333; }
                        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #007bff; padding-bottom: 20px; }
                        .header h1 { color: #007bff; margin: 0; font-size: 28px; }
                        .header h2 { color: #666; margin: 10px 0 0 0; font-size: 18px; font-weight: normal; }
                        .patient-info { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 25px; }
                        .patient-info h3 { margin: 0 0 10px 0; color: #495057; }
                        .patient-info p { margin: 5px 0; color: #6c757d; }
                        .summary { display: flex; justify-content: space-around; margin-bottom: 30px; }
                        .summary-item { text-align: center; background: #e9ecef; padding: 15px; border-radius: 8px; flex: 1; margin: 0 10px; }
                        .summary-item h4 { margin: 0 0 5px 0; color: #495057; font-size: 14px; }
                        .summary-item .value { font-size: 24px; font-weight: bold; color: #007bff; }
                        .weight-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
                        .weight-table th, .weight-table td { border: 1px solid #dee2e6; padding: 12px; text-align: left; }
                        .weight-table th { background: #f8f9fa; font-weight: bold; color: #495057; }
                        .weight-table tr:nth-child(even) { background: #f8f9fa; }
                        .change-positive { color: #dc3545; font-weight: bold; }
                        .change-negative { color: #28a745; font-weight: bold; }
                        .change-neutral { color: #6c757d; }
                        .chart-section { margin-top: 30px; text-align: center; }
                        .chart-placeholder { background: #f8f9fa; border: 2px dashed #dee2e6; padding: 40px; border-radius: 8px; margin: 20px 0; }
                        .footer { margin-top: 40px; text-align: center; color: #6c757d; font-size: 12px; border-top: 1px solid #dee2e6; padding-top: 20px; }
                        @media print {
                            body { margin: 0; }
                            .no-print { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>Weight Tracking Report</h1>
                        <h2>Generated on ${currentDate}</h2>
                        <div style="margin-top: 15px;">
                            <button onclick="window.print()" style="background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500; margin-right: 10px;">
                                <i class="fas fa-print"></i> Print Report
                            </button>
                            <button onclick="window.close()" style="background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;">
                                <i class="fas fa-times"></i> Close
                            </button>
                        </div>
                    </div>
                    
                    <div class="patient-info">
                        <h3>Patient Information</h3>
                        <p><strong>Patient:</strong> ${patientName}</p>
                        <p><strong>Patient ID:</strong> ${patientId}</p>
                        <p><strong>Report Period:</strong> ${weightHistory[weightHistory.length - 1].date_recorded.split(' ')[0]} to ${weightHistory[0].date_recorded.split(' ')[0]}</p>
                    </div>
                    
                    <div class="summary">
                        <div class="summary-item">
                            <h4>Total Entries</h4>
                            <div class="value">${weightHistory.length}</div>
                        </div>
                        <div class="summary-item">
                            <h4>Starting Weight</h4>
                            <div class="value">${weightHistory[weightHistory.length - 1].weight} lbs</div>
                        </div>
                        <div class="summary-item">
                            <h4>Current Weight</h4>
                            <div class="value">${weightHistory[0].weight} lbs</div>
                        </div>
                        <div class="summary-item">
                            <h4>Total Change</h4>
                            <div class="value ${weightHistory[0].weight - weightHistory[weightHistory.length - 1].weight > 0 ? 'change-positive' : weightHistory[0].weight - weightHistory[weightHistory.length - 1].weight < 0 ? 'change-negative' : 'change-neutral'}">
                                ${(weightHistory[0].weight - weightHistory[weightHistory.length - 1].weight).toFixed(1)} lbs
                            </div>
                        </div>
                    </div>
                    
                    <h3>Weight History</h3>
                    <table class="weight-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Weight (lbs)</th>
                                <th>Change</th>
                                <th>Medications</th>
                                <th>Recorded By</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>`;

            // Add weight history rows
            weightHistory.forEach((entry, index) => {
                const change = index < weightHistory.length - 1 ? 
                    (entry.weight - weightHistory[index + 1].weight).toFixed(1) : '0.0';
                const changeClass = change > 0 ? 'change-positive' : change < 0 ? 'change-negative' : 'change-neutral';
                const changeIcon = change > 0 ? '↗' : change < 0 ? '↘' : '→';

                // Format medications for the day
                let medicationsText = '-';
                if (entry.medicines_for_day && entry.medicines_for_day.length > 0) {
                    const medList = entry.medicines_for_day.map(med => {
                        let medText = med.drug_name || med.name || 'Unknown';
                        if (med.dispensed_quantity) medText += ` (Dispensed: ${formatQuantityWithUnit(med.dispensed_quantity, med.form || '')})`;
                        if (med.administered_quantity) medText += ` (Administered: ${formatQuantityWithUnit(med.administered_quantity, med.form || '')})`;
                        return medText;
                    });
                    medicationsText = medList.join(', ');
                }

                reportHtml += `
                    <tr>
                        <td>${new Date(entry.date_recorded).toLocaleDateString()}</td>
                        <td>${entry.weight}</td>
                        <td class="${changeClass}">${changeIcon} ${Math.abs(change)}</td>
                        <td style="font-size: 12px; max-width: 200px; word-wrap: break-word;">${medicationsText}</td>
                        <td>${entry.user_name || entry.user_id || 'Unknown'}</td>
                        <td>${entry.notes || '-'}</td>
                    </tr>`;
            });

            reportHtml += `
                        </tbody>
                    </table>
                    
                    <div class="chart-section">
                        <h3>Weight Progress Chart</h3>
                        <div class="chart-placeholder">
                            <p><strong>Weight Progress Visualization</strong></p>
                            <p>Chart shows weight changes over time</p>
                            <p>📈 Use this data to track medication effectiveness</p>
                        </div>
                    </div>
                    
                    <div class="medication-summary">
                        <h3>Medication Summary</h3>
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                            <p><strong>Purpose:</strong> This report helps track the relationship between weight changes and medication administration.</p>
                            <p><strong>Key Points:</strong></p>
                            <ul style="margin: 10px 0; padding-left: 20px;">
                                <li>Weight changes are tracked alongside medication dispensed and administered</li>
                                <li>Positive changes (↗) indicate weight gain</li>
                                <li>Negative changes (↘) indicate weight loss</li>
                                <li>Use this data to assess medication effectiveness and side effects</li>
                                <li>Share this report with your healthcare provider for treatment adjustments</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="footer">
                        <p>This report was generated automatically by the OpenEMR Weight Tracking System</p>
                        <p>For questions about this report, please contact your healthcare provider</p>
                    </div>
                </body>
                </html>`;

            // Create and download the file
            const blob = new Blob([reportHtml], { type: 'text/html' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `Weight_Report_${patientName.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.html`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
        
// Followup / Returning / New: only one selectable + set hidden visit_type
document.addEventListener("DOMContentLoaded", function () {
  const followChk = document.getElementById("followup-checkbox");
  const returningChk = document.getElementById("returning-checkbox");
  const newChk = document.getElementById("new-checkbox");
  const bwChk = document.getElementById("bloodwork-checkbox");
  const injectionChk = document.getElementById("injection-checkbox");
  const hidden = document.getElementById("visit-type-hidden");

  if (!followChk || !returningChk || !newChk || !injectionChk || !hidden) {
    console.warn("Visit type elements missing:", { followChk, returningChk, newChk, injectionChk, hidden });
    return;
  }

  function paintSelected(containerEl, selected) {
    if (!containerEl) return;
    containerEl.style.background = selected ? "#e9f2ff" : "#f8f9fa";
    containerEl.style.borderColor = selected ? "#0d6efd" : "#ddd";
    containerEl.style.boxShadow = selected ? "0 0 0 1px rgba(13,110,253,0.2)" : "none";
  }

  function syncVisitOptionHighlights() {
    const ids = [
      "consultation-checkbox",
      "bloodwork-checkbox",
      "injection-checkbox",
      "followup-checkbox",
      "returning-checkbox",
      "new-checkbox"
    ];

    ids.forEach(function (id) {
      const chk = document.getElementById(id);
      if (!chk) return;
      const container = chk.closest(".consultation-checkbox");
      if (!container) return;
      paintSelected(container, !!chk.checked);
    });
  }

	  function updateVisitType(source) {
	    // Only one of Follow-Up, Returning, New, or Injection can be selected at a time.
	    if (source === followChk && followChk.checked) {
          returningChk.checked = false;
	      newChk.checked = false;
	      injectionChk.checked = false;
    }

    if (source === returningChk && returningChk.checked) {
      followChk.checked = false;
      newChk.checked = false;
      injectionChk.checked = false;
    }

    if (source === newChk && newChk.checked) {
      followChk.checked = false;
      returningChk.checked = false;
      injectionChk.checked = false;
    }

    if (source === injectionChk && injectionChk.checked) {
      followChk.checked = false;
      returningChk.checked = false;
      newChk.checked = false;
    }

    // Set hidden value + visuals
    if (followChk.checked) {
      hidden.value = "F";
    } else if (returningChk.checked) {
      hidden.value = "R";
    } else if (newChk.checked) {
      hidden.value = "N";
    } else {
      hidden.value = "-";
    }

    if (bwChk) {
      if (newChk.checked) {
        bwChk.checked = true;
        bwChk.disabled = true;
        bwChk.style.opacity = "0.7";
        toggleBloodWork();
      } else {
        bwChk.disabled = false;
        bwChk.style.opacity = "1";
        if (bwChk.checked) {
          bwChk.checked = false;
          toggleBloodWork();
        }
      }
	    }
	
	    syncVisitOptionHighlights();
	    updateWeightRecordingStatus();
	    if (currentStep === 1) {
	      updateWeightValidationStatus();
	    }
	
	    console.log("visit_type =", hidden.value);
	  }

  followChk.addEventListener("change", () => updateVisitType(followChk));
  returningChk.addEventListener("change", () => updateVisitType(returningChk));
  newChk.addEventListener("change", () => updateVisitType(newChk));
  injectionChk.addEventListener("change", () => updateVisitType(injectionChk));

  const consultationChk = document.getElementById("consultation-checkbox");
  if (consultationChk) consultationChk.addEventListener("change", syncVisitOptionHighlights);
  if (bwChk) bwChk.addEventListener("change", syncVisitOptionHighlights);
  if (injectionChk) injectionChk.addEventListener("change", syncVisitOptionHighlights);

  // Initialize UI
  updateVisitType(null);
  syncVisitOptionHighlights();

  window.syncVisitOptionHighlights = syncVisitOptionHighlights;

  window.getVisitType = function () {
    return hidden.value || "-";
  };
});




    </script>

</body>
</html> 
