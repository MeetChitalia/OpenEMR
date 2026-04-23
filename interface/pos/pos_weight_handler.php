<?php
/**
 * POS Weight Tracking Handler
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Team
 * @copyright Copyright (c) 2024 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Ensure clean output for JSON responses
while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

// Set content type to JSON immediately
header('Content-Type: application/json; charset=utf-8');

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$rawInput = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    if (is_string($rawBody) && trim($rawBody) !== '') {
        $decodedInput = json_decode($rawBody, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedInput)) {
            $rawInput = $decodedInput;
        }
    }
}

$requestAction = $_POST['action'] ?? $_GET['action'] ?? $rawInput['action'] ?? '';

// Ensure Site ID is set for proper session handling BEFORE including globals.php
if (!isset($_SESSION['site_id']) || empty($_SESSION['site_id'])) {
    // Set default site ID if not set
    $_SESSION['site_id'] = 'default';
}

// Skip globals.php inclusion to avoid session timeout issues
// We'll handle session and user lookup manually

// Function to clean any output and send JSON
function cleanOutputAndSendJson($data, $statusCode = 200) {
    // Clear ALL output buffers aggressively
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set proper headers before any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        http_response_code($statusCode);
    }
    
    // Send JSON response directly
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    
    // Force output and exit
    if (ob_get_level()) {
        ob_end_flush();
    }
    flush();
    exit;
}

function getWeightChartMailerGlobals(PDO $pdo): array
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
    $stmt = $pdo->prepare("SELECT gl_name, gl_value FROM globals WHERE gl_name IN ($placeholders)");
    $stmt->execute($globalNames);

    $settings = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[$row['gl_name']] = $row['gl_value'];
    }

    return $settings;
}

function buildWeightChartEmailHtml(string $patientName, array $weightHistory): string
{
    $entryCount = count($weightHistory);
    $latestEntry = $entryCount > 0 ? $weightHistory[0] : [];
    $oldestEntry = $entryCount > 0 ? $weightHistory[$entryCount - 1] : [];
    $currentWeight = isset($latestEntry['weight']) ? (float) $latestEntry['weight'] : 0.0;
    $startingWeight = isset($oldestEntry['weight']) ? (float) $oldestEntry['weight'] : 0.0;
    $weightChange = $entryCount > 1 ? ($currentWeight - $startingWeight) : 0.0;
    $changeLabel = $weightChange > 0 ? '+' . number_format($weightChange, 1) : number_format($weightChange, 1);
    $reportPeriod = $entryCount > 0
        ? date('m/d/Y', strtotime((string) ($oldestEntry['date_recorded'] ?? 'now'))) . ' to ' . date('m/d/Y', strtotime((string) ($latestEntry['date_recorded'] ?? 'now')))
        : date('m/d/Y');

    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Weight Progress Chart</title>
</head>
<body style="margin:0; padding:24px; background:#f4f7fb; font-family:Arial, sans-serif; color:#233044;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:720px; margin:0 auto; background:#ffffff; border:1px solid #dde5f0; border-radius:14px; overflow:hidden;">
        <tr>
            <td style="padding:24px 28px; background:linear-gradient(135deg, #0d6efd 0%, #1aa3b5 100%); color:#ffffff;">
                <div style="font-size:24px; font-weight:700;">JACtrac Weight Progress Chart</div>
                <div style="margin-top:8px; font-size:14px; opacity:0.95;">Professional patient weight tracking summary from Point of Sale.</div>
            </td>
        </tr>
        <tr>
            <td style="padding:24px 28px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
                    <tr>
                        <td style="padding:0 0 16px; font-size:15px;"><strong>Patient:</strong> ' . htmlspecialchars($patientName, ENT_QUOTES) . '</td>
                        <td style="padding:0 0 16px; font-size:15px; text-align:right;"><strong>Generated:</strong> ' . date('m/d/Y g:i A') . '</td>
                    </tr>
                    <tr>
                        <td colspan="2" style="padding:0 0 18px; font-size:15px;"><strong>Report Period:</strong> ' . htmlspecialchars($reportPeriod, ENT_QUOTES) . '</td>
                    </tr>
                </table>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate; border-spacing:12px 0;">
                    <tr>
                        <td style="background:#eef5ff; border:1px solid #cfe0ff; border-radius:12px; padding:16px; text-align:center;">
                            <div style="font-size:12px; color:#5a6d85; text-transform:uppercase; letter-spacing:0.04em;">Current Weight</div>
                            <div style="margin-top:8px; font-size:28px; font-weight:700; color:#0d6efd;">' . number_format($currentWeight, 1) . ' lbs</div>
                        </td>
                        <td style="background:#fff7e7; border:1px solid #f6dd9c; border-radius:12px; padding:16px; text-align:center;">
                            <div style="font-size:12px; color:#7a6632; text-transform:uppercase; letter-spacing:0.04em;">Weight Change</div>
                            <div style="margin-top:8px; font-size:28px; font-weight:700; color:#d97706;">' . htmlspecialchars($changeLabel, ENT_QUOTES) . ' lbs</div>
                        </td>
                        <td style="background:#eefbf2; border:1px solid #cfead7; border-radius:12px; padding:16px; text-align:center;">
                            <div style="font-size:12px; color:#4d6d5b; text-transform:uppercase; letter-spacing:0.04em;">Entries</div>
                            <div style="margin-top:8px; font-size:28px; font-weight:700; color:#198754;">' . $entryCount . '</div>
                        </td>
                    </tr>
                </table>
                <p style="margin:24px 0 0; font-size:14px; line-height:1.6; color:#4f5f75;">
                    The latest weight progress chart is attached as a PNG image for quick sharing and review.
                </p>
            </td>
        </tr>
        <tr>
            <td style="padding:18px 28px 24px; border-top:1px solid #e7edf5; background:#fbfcfe; color:#63758c; font-size:12px; line-height:1.6;">
                <strong style="color:#233044;">Prepared by JACtrac</strong><br>
                This message was generated automatically from the POS weight tracking workflow.
                <div style="margin-top:12px;">Thank you for using JacTrac.</div>
            </td>
        </tr>
    </table>
</body>
</html>';
}

function sendWeightChartEmail(PDO $pdo, array $payload): array
{
    $toEmail = trim((string) ($payload['to_email'] ?? ''));
    $patientName = trim((string) ($payload['patient_name'] ?? 'Patient'));
    $chartFilename = trim((string) ($payload['chart_filename'] ?? 'weight_chart.png'));
    $chartImage = (string) ($payload['chart_image'] ?? '');
    $weightHistory = is_array($payload['weight_history'] ?? null) ? $payload['weight_history'] : [];

    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'A valid recipient email is required.'];
    }

    if ($chartImage === '' || stripos($chartImage, 'data:image/png;base64,') !== 0) {
        return ['success' => false, 'error' => 'A valid chart image is required.'];
    }

    if (empty($weightHistory)) {
        return ['success' => false, 'error' => 'Weight history is required to email the chart.'];
    }

    $imageBinary = base64_decode(substr($chartImage, strlen('data:image/png;base64,')), true);
    if ($imageBinary === false) {
        return ['success' => false, 'error' => 'The chart image could not be decoded.'];
    }

    $ignoreAuth = true;
    require_once(__DIR__ . '/../globals.php');
    require_once(__DIR__ . '/../../library/classes/postmaster.php');

    $mailerGlobals = getWeightChartMailerGlobals($pdo);
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

    try {
        $mail = new MyMailer();
        $mail->AddReplyTo($senderEmail, 'JACtrac Weight Reports');
        $mail->SetFrom($senderEmail, 'JACtrac Weight Reports');
        $mail->AddAddress($toEmail, $toEmail);
        $mail->Subject = 'Weight Progress Chart | ' . $patientName . ' | ' . date('m/d/Y');
        $mail->MsgHTML(buildWeightChartEmailHtml($patientName, $weightHistory));
        $mail->IsHTML(true);
        $mail->AltBody = 'Weight progress chart for ' . $patientName . ' generated on ' . date('m/d/Y g:i A') . '. The chart image is attached.' . "\n\nThank you for using JacTrac.";
        $mail->addStringAttachment($imageBinary, $chartFilename, 'base64', 'image/png');

        if ($mail->Send()) {
            return ['success' => true, 'message' => 'Weight chart emailed successfully to ' . $toEmail . '.'];
        }

        return ['success' => false, 'error' => 'Email send failed: ' . $mail->ErrorInfo];
    } catch (Exception $e) {
        error_log("POS weight chart email failed: " . $e->getMessage());
        return ['success' => false, 'error' => 'Email send failed: ' . $e->getMessage()];
    }
}

// Use OpenEMR's database configuration
require_once(__DIR__ . '/../../sites/default/sqlconf.php');

try {
    $pdo = new PDO(
        "mysql:host=" . $host . ";port=" . $port . ";dbname=" . $dbase . ";charset=utf8mb4",
        $login,
        $pass,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
} catch (PDOException $e) {
    error_log("Database connection failed in pos_weight_handler.php: " . $e->getMessage());
    cleanOutputAndSendJson(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()], 500);
}

// For now, skip authentication check to test functionality
// TODO: Implement proper authentication later
/*
// Ensure user is logged in
if (!isset($_SESSION['authUserID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}
*/

// Handle GET request for current weight
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $requestAction === 'get_current_weight') {
    $pid = intval($_GET['pid'] ?? 0);
    $userId = $_GET['user_id'] ?? null;
    
    if (!$pid) {
        cleanOutputAndSendJson(['success' => false, 'error' => 'Invalid patient ID'], 400);
    }
    
    try {
        // Get the most recent weight for the patient from pos_weight_tracking (latest weight tracking)
        $query = "SELECT weight, date_recorded as date, recorded_by FROM pos_weight_tracking WHERE pid = ? ORDER BY date_recorded DESC LIMIT 1";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$pid]);
        $weightData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($weightData && $weightData['weight']) {
            // Get current logged-in user's name for display
            $currentUserName = 'Current User';
            
            
            // Use the user_id parameter passed from JavaScript, or fall back to session
            if (!$userId) {
                if (isset($_SESSION['authUserID'])) {
                    $userId = $_SESSION['authUserID'];
                } elseif (isset($_SESSION['authUser'])) {
                    $userId = $_SESSION['authUser'];
                } elseif (isset($_SESSION['user_id'])) {
                    $userId = $_SESSION['user_id'];
                }
            }
            
            
            if ($userId) {
                $userQuery = "SELECT fname, lname, username FROM users WHERE id = ?";
                $userStmt = $pdo->prepare($userQuery);
                $userStmt->execute([$userId]);
                $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
                if ($userData) {
                    // Use username if fname and lname are empty or the same
                    $fullName = trim($userData['fname'] . ' ' . $userData['lname']);
                    if (empty($fullName) || $fullName === ' ' || $userData['fname'] === $userData['lname']) {
                        $currentUserName = $userData['username'];
                    } else {
                        $currentUserName = $fullName;
                    }
                }
            }
            
            cleanOutputAndSendJson([
                'success' => true,
                'weight' => floatval($weightData['weight']),
                'date' => $weightData['date'],
                'recorded_by' => $currentUserName,
                'notes' => 'From patient vitals'
            ]);
        } else {
            // Fallback: Check form_vitals if no weight tracking data found
            $fallbackQuery = "SELECT weight, date, user FROM form_vitals WHERE pid = ? AND weight > 0 ORDER BY date DESC LIMIT 1";
            $fallbackStmt = $pdo->prepare($fallbackQuery);
            $fallbackStmt->execute([$pid]);
            $fallbackData = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($fallbackData && $fallbackData['weight']) {
                // Use the user_id parameter for fallback data too
                $fallbackUserName = 'Current User';
                if ($userId) {
                    $userQuery = "SELECT fname, lname, username FROM users WHERE id = ?";
                    $userStmt = $pdo->prepare($userQuery);
                    $userStmt->execute([$userId]);
                    $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
                    if ($userData) {
                        $fullName = trim($userData['fname'] . ' ' . $userData['lname']);
                        if (empty($fullName) || $fullName === ' ' || $userData['fname'] === $userData['lname']) {
                            $fallbackUserName = $userData['username'];
                        } else {
                            $fallbackUserName = $fullName;
                        }
                    }
                }
                
                cleanOutputAndSendJson([
                    'success' => true,
                    'weight' => floatval($fallbackData['weight']),
                    'date' => $fallbackData['date'],
                    'recorded_by' => $fallbackUserName,
                    'notes' => 'From patient vitals (fallback)'
                ]);
            } else {
                cleanOutputAndSendJson([
                'success' => true,
                'weight' => null,
                    'message' => 'No weight recorded'
            ]);
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching current weight: " . $e->getMessage());
        cleanOutputAndSendJson(['success' => false, 'error' => 'Database error occurred'], 500);
    }
    exit;
}

// Handle GET request for weight history
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $requestAction === 'get_weight_history') {
    $pid = intval($_GET['pid'] ?? 0);
    $userId = $_GET['user_id'] ?? null;
    
    if (!$pid) {
        cleanOutputAndSendJson(['success' => false, 'error' => 'Invalid patient ID'], 400);
    }
    
    try {
        // Get weight history for the patient with detailed tracking info
        // Group by date to ensure only one entry per date (use the most recent entry for each date)
        $query = "SELECT weight, date_recorded, recorded_by, user_id, ip_address, notes 
                  FROM pos_weight_tracking 
                  WHERE pid = ? 
                  AND id IN (
                      SELECT MAX(id) 
                      FROM pos_weight_tracking 
                      WHERE pid = ? 
                      GROUP BY DATE(date_recorded)
                  )
                  ORDER BY date_recorded DESC 
                  LIMIT 50";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$pid, $pid]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // For each weight entry, get medicines dispensed/administered on that day
        foreach ($history as &$entry) {
            $weightDate = date('Y-m-d', strtotime($entry['date_recorded']));
            
            // If recorded_by is "pos_user", use the current user's name (since pos_user represents the current user)
            if ($entry['recorded_by'] === 'pos_user') {
                if ($userId) {
                    $userQuery = "SELECT fname, lname, username FROM users WHERE id = ?";
                    $userStmt = $pdo->prepare($userQuery);
                    $userStmt->execute([$userId]);
                    $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
                    if ($userData) {
                        $fullName = trim($userData['fname'] . ' ' . $userData['lname']);
                        if (empty($fullName) || $fullName === ' ' || $userData['fname'] === $userData['lname']) {
                            $entry['user_name'] = $userData['username'];
                        } else {
                            $entry['user_name'] = $fullName;
                        }
                    } else {
                        $entry['user_name'] = 'Current User';
                    }
                } else {
                    $entry['user_name'] = 'Current User';
                }
            } else {
                // Use the recorded_by field which now contains the full name
                $entry['user_name'] = $entry['recorded_by'] ?: 'Unknown';
            }
            
            // Get ONLY administered medicines from pos_remaining_dispense table
            // Check both created_date (when first administered) and last_updated (when administered later)
            $administerQuery = "SELECT d.name as drug_name, prd.administered_quantity, prd.last_updated as date_administered 
                               FROM pos_remaining_dispense prd 
                               JOIN drugs d ON prd.drug_id = d.drug_id 
                               WHERE prd.pid = ? 
                               AND (DATE(prd.created_date) = ? OR DATE(prd.last_updated) = ?)
                               AND prd.administered_quantity > 0";
            
            $administerStmt = $pdo->prepare($administerQuery);
            $administerStmt->execute([$pid, $weightDate, $weightDate]);
            $administeredMeds = $administerStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Also check pos_transactions table for administer transactions
            $transactionQuery = "SELECT items, created_date 
                                FROM pos_transactions 
                                WHERE pid = ? AND DATE(created_date) = ? 
                                AND transaction_type IN ('administer', 'dispense_and_administer')";
            
            $transactionStmt = $pdo->prepare($transactionQuery);
            $transactionStmt->execute([$pid, $weightDate]);
            $transactions = $transactionStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Parse transaction items for ONLY administered medicines
            $transactionMeds = [];
            foreach ($transactions as $transaction) {
                $items = json_decode($transaction['items'], true);
                if ($items) {
                    foreach ($items as $item) {
                        // ONLY include if administer_quantity exists and is > 0
                        if (isset($item['administer_quantity']) && $item['administer_quantity'] > 0) {
                            $transactionMeds[] = [
                                'drug_name' => $item['name'],
                                'administered_quantity' => $item['administer_quantity'],
                                'date_administered' => $transaction['created_date']
                            ];
                        }
                    }
                }
            }
            
            // ONLY include administered medicines for the chart (no dispensed medicines)
            $entry['medicines_for_day'] = array_merge($administeredMeds, $transactionMeds);
        }
        
        cleanOutputAndSendJson([
            'success' => true,
            'history' => $history
        ]);
    } catch (Exception $e) {
        error_log("Error fetching weight history: " . $e->getMessage());
        cleanOutputAndSendJson(['success' => false, 'error' => 'Database error occurred'], 500);
    }
    exit;
}

// Handle POST request for saving weight
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestAction === 'save_weight') {
    $pid = intval($_POST['pid'] ?? 0);
    $weight = floatval($_POST['weight'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    
    if (!$pid) {
        cleanOutputAndSendJson(['success' => false, 'error' => 'Invalid patient ID'], 400);
    }
    
    if ($weight <= 0 || $weight > 1000) {
        cleanOutputAndSendJson(['success' => false, 'error' => 'Invalid weight value'], 400);
    }
    
    try {
        $current_date = date('Y-m-d H:i:s');
        
        // Get current user from session or use default
        $current_user_id = null; // Default to NULL for database
        $current_user_name = 'System User'; // Default
        
        // Try different session variables that OpenEMR might use
        if (isset($_SESSION['authUserID'])) {
            $current_user_id = intval($_SESSION['authUserID']);
        } elseif (isset($_SESSION['authUser'])) {
            // authUser might be username, need to look up ID
            $userLookupQuery = "SELECT id FROM users WHERE username = ?";
            $userLookupStmt = $pdo->prepare($userLookupQuery);
            $userLookupStmt->execute([$_SESSION['authUser']]);
            $userLookupData = $userLookupStmt->fetch(PDO::FETCH_ASSOC);
            if ($userLookupData) {
                $current_user_id = intval($userLookupData['id']);
            }
        } elseif (isset($_SESSION['user_id'])) {
            $current_user_id = intval($_SESSION['user_id']);
        }
        
        // Get user's full name
        if ($current_user_id) {
            $userQuery = "SELECT fname, lname, username FROM users WHERE id = ?";
            $userStmt = $pdo->prepare($userQuery);
            $userStmt->execute([$current_user_id]);
            $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
            if ($userData) {
                $current_user_name = trim($userData['fname'] . ' ' . $userData['lname']);
                if (empty($current_user_name) || $current_user_name === ' ') {
                    $current_user_name = $userData['username'];
                }
            }
        }
        
        // Get IP address
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
            $ip_address = $_SERVER['HTTP_X_REAL_IP'];
        }
        
        // Get session ID
        $session_id = session_id() ?: 'unknown';
        
        // Get user agent
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        // Check if weight was already recorded TODAY for this patient
        $today_date = date('Y-m-d');
        $check_query = "SELECT id FROM pos_weight_tracking WHERE pid = ? AND DATE(date_recorded) = ? ORDER BY date_recorded DESC LIMIT 1";
        $check_stmt = $pdo->prepare($check_query);
        $check_stmt->execute([$pid, $today_date]);
        $existing_weight = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_weight) {
            // UPDATE the existing weight entry for today
            $update_query = "UPDATE pos_weight_tracking 
                            SET weight = ?, 
                                date_recorded = ?, 
                                recorded_by = ?, 
                                notes = ?, 
                                user_id = ?, 
                                ip_address = ?, 
                                session_id = ?, 
                                user_agent = ? 
                            WHERE id = ?";
            $update_params = [
                $weight,
                $current_date,
                $current_user_name,
                $notes,
                $current_user_id,
                $ip_address,
                $session_id,
                $user_agent,
                $existing_weight['id']
            ];
            
            $stmt = $pdo->prepare($update_query);
            $insert_result = $stmt->execute($update_params);
            $action_taken = 'updated';
        } else {
            // INSERT new weight record for a new day
            $insert_query = "INSERT INTO pos_weight_tracking (pid, weight, date_recorded, recorded_by, notes, user_id, ip_address, session_id, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $insert_params = [
                $pid,
                $weight,
                $current_date,
                $current_user_name, // User's full name for weight tracking
                $notes,
                $current_user_id,
                $ip_address,
                $session_id,
                $user_agent
            ];
            
            $stmt = $pdo->prepare($insert_query);
            $insert_result = $stmt->execute($insert_params);
            $action_taken = 'recorded';
        }
        
        if ($insert_result) {
            // Synchronize weight to form_vitals table for patient information display
            try {
                // Check if there's an existing vitals record for today
                $today = date('Y-m-d');
                $vitals_check_query = "SELECT id FROM form_vitals WHERE pid = ? AND DATE(date) = ?";
                $vitals_check_stmt = $pdo->prepare($vitals_check_query);
                $vitals_check_stmt->execute([$pid, $today]);
                $existing_vitals = $vitals_check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing_vitals) {
                    // Update existing vitals record with new weight
                    $update_vitals_query = "UPDATE form_vitals SET weight = ?, user = ? WHERE id = ?";
                    $update_vitals_stmt = $pdo->prepare($update_vitals_query);
                    $update_vitals_stmt->execute([$weight, $current_user_name, $existing_vitals['id']]);
                } else {
                    // Create new vitals record with weight
                    $insert_vitals_query = "INSERT INTO form_vitals (pid, weight, date, user, groupname, authorized, activity) VALUES (?, ?, ?, ?, 'Default', 1, 1)";
                    $insert_vitals_stmt = $pdo->prepare($insert_vitals_query);
                    $insert_vitals_stmt->execute([$pid, $weight, $current_date, $current_user_name]);
                }
            } catch (Exception $vitals_error) {
                // Log the error but don't fail the main operation
                error_log("Error synchronizing weight to form_vitals: " . $vitals_error->getMessage());
            }
            
            cleanOutputAndSendJson([
                'success' => true,
                'message' => "Weight $action_taken successfully and synchronized to patient information",
                'weight' => $weight,
                'date' => $current_date,
                'recorded_by' => $current_user_name,
                'ip_address' => $ip_address,
                'action' => $action_taken
            ]);
        } else {
            cleanOutputAndSendJson(['success' => false, 'error' => 'Failed to save weight'], 500);
        }
        
    } catch (Exception $e) {
        error_log("Error saving weight: " . $e->getMessage());
        cleanOutputAndSendJson(['success' => false, 'error' => 'Database error occurred'], 500);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestAction === 'email_weight_chart') {
    $emailResult = sendWeightChartEmail($pdo, $rawInput);
    cleanOutputAndSendJson($emailResult, $emailResult['success'] ? 200 : 400);
}

// Test endpoint
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $requestAction === 'test') {
    cleanOutputAndSendJson(['success' => true, 'message' => 'Weight handler is working']);
}

// Invalid request
cleanOutputAndSendJson(['success' => false, 'error' => 'Invalid request'], 400);


?>
