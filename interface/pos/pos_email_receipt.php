<?php
/**
 * POS Email Receipt Handler
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Team
 * @copyright Copyright (c) 2024 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/options.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;

function formatPrepayReceiptDate($date) {
    if (empty($date)) {
        return '';
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return (string)$date;
    }

    return date('m/d/Y', $timestamp);
}

// Ensure user is logged in
if (!isset($_SESSION['authUserID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => xlt('Not authorized')]);
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action === 'get_patient_email') {
        $pid = intval($_GET['pid'] ?? 0);
        
        if (!$pid) {
            echo json_encode(['success' => false, 'error' => xlt('Invalid patient ID')]);
            exit;
        }
        
        // Get patient email
        $patient_data = getPatientData($pid, 'email');
        $email = $patient_data['email'] ?? '';
        
        echo json_encode([
            'success' => true,
            'email' => $email
        ]);
        exit;
    }
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'send_receipt') {
// Verify CSRF token
if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'error' => xlt('Invalid security token')]);
            exit;
}

$receipt_number = $_POST['receipt_number'] ?? '';
$email = $_POST['email'] ?? '';
        
        if (!$receipt_number || !$email) {
            echo json_encode(['success' => false, 'error' => xlt('Missing required parameters')]);
            exit;
        }
        
        // Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => xlt('Invalid email address')]);
            exit;
}

function posEmailReceiptNormalizeLocationLine(string $value): string
{
    return strtolower(preg_replace('/[^a-z0-9]+/', '', $value));
}

function posEmailReceiptBuildFacilityDisplay(array $facility_result): array
{
    $brandName = 'Achieve Medical';
    $locationName = trim((string) ($facility_result['name'] ?? ''));
    $street = trim((string) ($facility_result['street'] ?? ''));
    $city = trim((string) ($facility_result['city'] ?? ''));
    $state = trim((string) ($facility_result['state'] ?? ''));
    $postal = trim((string) ($facility_result['postal_code'] ?? ''));

    $addressParts = [];
    if ($locationName !== '' && posEmailReceiptNormalizeLocationLine($locationName) !== posEmailReceiptNormalizeLocationLine($brandName)) {
        $addressParts[] = $locationName;
    }
    if ($street !== '') {
        $addressParts[] = $street;
    }

    $cityStatePostalParts = [];
    if ($city !== '') {
        $cityStatePostalParts[] = $city;
    }
    if ($state !== '') {
        $cityStatePostalParts[] = $state;
    }
    if ($postal !== '') {
        $cityStatePostalParts[] = $postal;
    }
    $cityStatePostal = trim(implode(', ', $cityStatePostalParts), ', ');

    if ($cityStatePostal !== '') {
        $locationNormalized = posEmailReceiptNormalizeLocationLine($locationName);
        $cityStateNormalized = posEmailReceiptNormalizeLocationLine($cityStatePostal);
        $stateNormalized = posEmailReceiptNormalizeLocationLine($state);
        $locationAlreadyContainsState = $stateNormalized !== '' && strpos($locationNormalized, $stateNormalized) !== false;
        if (
            ($locationNormalized === '' || $locationNormalized !== $cityStateNormalized) &&
            !$locationAlreadyContainsState
        ) {
            $addressParts[] = $cityStatePostal;
        }
    }

    return [
        'brand_name' => $brandName,
        'address_text' => implode("\n", $addressParts),
    ];
}

// Get receipt data
        $receipt_result = sqlFetchArray(sqlStatement(
            "SELECT * FROM pos_receipts WHERE receipt_number = ?", 
            array($receipt_number)
        ));

if (!$receipt_result) {
            echo json_encode(['success' => false, 'error' => xlt('Receipt not found')]);
            exit;
        }
        
        $receipt_data = json_decode($receipt_result['receipt_data'], true);
        if (!$receipt_data) {
            echo json_encode(['success' => false, 'error' => xlt('Invalid receipt data')]);
            exit;
        }
        
        $pid = $receipt_result['pid'];
        $patient_data = getPatientData($pid, 'fname,mname,lname,pubpid,DOB,phone_home,phone_cell,email');
        
        if (!$patient_data) {
            echo json_encode(['success' => false, 'error' => xlt('Patient data not found')]);
            exit;
        }
        
        // Get facility information
        $facility_query = "SELECT f.name, f.street, f.city, f.state, f.postal_code, f.country_code 
                           FROM facility f 
                           JOIN users u ON f.id = u.facility_id 
                           WHERE u.username = ?";
        $facility_result = sqlFetchArray(sqlStatement($facility_query, array($_SESSION['authUser'])));
        
        $facility_display = posEmailReceiptBuildFacilityDisplay($facility_result ?: []);
        $facility_name = $facility_display['brand_name'];
        $facility_address = $facility_display['address_text'];

// Generate receipt HTML
        $receipt_html = generateReceiptHTML($receipt_data, $patient_data, $facility_name, $facility_address, $receipt_number);
        
        // Log current SMTP configuration for debugging
        error_log("SMTP Configuration Check:");
        error_log("  Host: " . ($GLOBALS['SMTP_HOST'] ?? 'NOT SET'));
        error_log("  Port: " . ($GLOBALS['SMTP_PORT'] ?? 'NOT SET'));
        error_log("  Username: " . ($GLOBALS['SMTP_USER'] ?? 'NOT SET'));
        error_log("  Password: " . (!empty($GLOBALS['SMTP_PASS']) ? 'SET' : 'NOT SET'));
        error_log("  Secure: " . ($GLOBALS['SMTP_SECURE'] ?? 'NOT SET'));
        error_log("  From Email: " . ($GLOBALS['patient_reminder_sender_email'] ?? 'NOT SET'));
        error_log("  From Name: " . ($GLOBALS['practice_return_email_path'] ?? 'NOT SET'));

// Send email
        $email_result = sendReceiptEmail($email, $receipt_number, $receipt_html, $patient_data, $facility_name);
        $email_sent = $email_result['success'] ?? false;
        $gmail_auth_issue = $email_result['gmail_auth_issue'] ?? false;
    
    if ($email_sent) {
            // Check if we're in development environment
            $is_localhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '::1']) || 
                           strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false;
            
            // Check if SMTP is configured
            $smtp_configured = !empty($GLOBALS['SMTP_HOST']) && !empty($GLOBALS['SMTP_USER']);
            
            if ($is_localhost && !$smtp_configured) {
                echo json_encode([
                    'success' => true, 
                    'message' => xlt('Receipt email simulated successfully (development environment)'),
                    'development_mode' => true
                ]);
            } else {
                echo json_encode(['success' => true, 'message' => xlt('Receipt sent successfully')]);
            }
        } else {
            $response = ['success' => false, 'error' => xlt('Failed to send email')];
            if ($gmail_auth_issue) {
                $response['gmail_auth_issue'] = true;
            }
            error_log("Gmail auth issue flag: " . ($gmail_auth_issue ? 'true' : 'false')); // Debug log
            error_log("Sending error response: " . json_encode($response)); // Debug log
            echo json_encode($response);
        }
        exit;
    }
}

// Default response
echo json_encode(['success' => false, 'error' => xlt('Invalid action')]);

/**
 * Generate receipt HTML content
 */
function generateReceiptHTML($receipt_data, $patient_data, $facility_name, $facility_address, $receipt_number) {
    $patient_name = trim($patient_data['fname'] . ' ' . $patient_data['mname'] . ' ' . $patient_data['lname']);
    $patient_id = $patient_data['pubpid'];
    $date = date('m/d/Y H:i:s');
    
    $items_html = '';
    $subtotal = 0;
    
    if (isset($receipt_data['items']) && is_array($receipt_data['items'])) {
        foreach ($receipt_data['items'] as $item) {
            $name = $item['name'] ?? $item['display_name'] ?? 'Unknown Item';
            $quantity = $item['quantity'] ?? 1;
            $dispenseQuantity = $item['dispense_quantity'] ?? null;
            $administerQuantity = $item['administer_quantity'] ?? null;
            $price = $item['price'] ?? 0;
            $total = $price * $quantity;
            $subtotal += $total;
            $prepay_note_html = '';
            $itemMetaParts = [
                "Qty: " . htmlspecialchars((string)$quantity, ENT_QUOTES),
            ];
            if ($dispenseQuantity !== null && $dispenseQuantity != $quantity) {
                $itemMetaParts[] = "<span style='color:#28a745;'>Dispense: " . htmlspecialchars((string)$dispenseQuantity, ENT_QUOTES) . "</span>";
            }
            if (!empty($administerQuantity)) {
                $itemMetaParts[] = "<span style='color:#0d6efd;'>Administer: " . htmlspecialchars((string)$administerQuantity, ENT_QUOTES) . "</span>";
            }
            if (!empty($item['prepay_selected'])) {
                $prepay_date = formatPrepayReceiptDate($item['prepay_date'] ?? '');
                $prepay_reference = trim((string)($item['prepay_sale_reference'] ?? ''));
                $prepay_note_parts = ["<div class='prepay-note'><div class='prepay-note-label'>Prepaid Item</div><div>Receipt price: $0.00</div>"];
                if ($prepay_date !== '') {
                    $prepay_note_parts[] = "<div>Paid on: " . htmlspecialchars($prepay_date, ENT_QUOTES) . "</div>";
                }
                if ($prepay_reference !== '') {
                    $prepay_note_parts[] = "<div>Notes: " . nl2br(htmlspecialchars($prepay_reference, ENT_QUOTES)) . "</div>";
                }
                $prepay_note_parts[] = "</div>";
                $prepay_note_html = implode('', $prepay_note_parts);
            }
            
            $items_html .= "
            <tr>
                <td style='padding: 8px; border-bottom: 1px solid #eee;'>
                    <div>" . htmlspecialchars($name, ENT_QUOTES) . "</div>
                    <div class='item-meta'>" . implode(" &nbsp;&nbsp; ", $itemMetaParts) . "</div>
                    {$prepay_note_html}
                </td>
                <td style='padding: 8px; border-bottom: 1px solid #eee; text-align: center;'>{$quantity}</td>
                <td style='padding: 8px; border-bottom: 1px solid #eee; text-align: right;'>$" . number_format($price, 2) . "</td>
                <td style='padding: 8px; border-bottom: 1px solid #eee; text-align: right;'>$" . number_format($total, 2) . "</td>
            </tr>";
        }
    }
    
    $tax_total = $receipt_data['tax_total'] ?? 0;
    $grand_total = $subtotal + $tax_total;
    
    $html = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Receipt - {$receipt_number}</title>
        <style>
            body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.4; color: #333; }
            .receipt { max-width: 400px; margin: 0 auto; padding: 20px; }
            .header { text-align: center; margin-bottom: 20px; }
            .facility-name { font-size: 18px; font-weight: bold; margin-bottom: 5px; }
            .facility-address { font-size: 11px; color: #666; margin-bottom: 10px; }
            .receipt-title { font-size: 16px; font-weight: bold; margin-bottom: 15px; }
            .patient-info { margin-bottom: 20px; }
            .patient-info div { margin-bottom: 5px; }
            .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            .items-table th { background: #f5f5f5; padding: 8px; text-align: left; font-weight: bold; border-bottom: 2px solid #ddd; }
            .items-table td { padding: 8px; border-bottom: 1px solid #eee; }
            .item-meta { margin-top: 4px; font-size: 11px; color: #666; }
            .prepay-note { margin-top: 6px; padding: 6px 8px; border-left: 3px solid #28a745; background: #f4fbf6; font-size: 11px; color: #555; line-height: 1.45; }
            .prepay-note-label { font-weight: bold; color: #1f7a36; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 2px; }
            .total-row { font-weight: bold; background: #f9f9f9; }
            .footer { text-align: center; margin-top: 30px; font-size: 10px; color: #666; }
        </style>
    </head>
    <body>
        <div class='receipt'>
            <div class='header'>
                <div class='facility-name'>{$facility_name}</div>
                <div class='facility-address'>" . nl2br(htmlspecialchars($facility_address)) . "</div>
                <div class='receipt-title'>RECEIPT</div>
            </div>
            
            <div class='patient-info'>
                <div><strong>Patient:</strong> {$patient_name}</div>
                <div><strong>Patient ID:</strong> {$patient_id}</div>
                <div><strong>Receipt #:</strong> {$receipt_number}</div>
                <div><strong>Date:</strong> {$date}</div>
            </div>
            
            <table class='items-table'>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th style='text-align: center;'>Qty</th>
                        <th style='text-align: right;'>Price</th>
                        <th style='text-align: right;'>Total</th>
                    </tr>
                </thead>
                <tbody>
                    {$items_html}
                    " . ($tax_total > 0 ? "
                    <tr>
                        <td colspan='3' style='text-align: right;'><strong>Subtotal:</strong></td>
                        <td style='text-align: right;'>$" . number_format($subtotal, 2) . "</td>
        </tr>
        <tr>
                        <td colspan='3' style='text-align: right;'><strong>Tax:</strong></td>
                        <td style='text-align: right;'>$" . number_format($tax_total, 2) . "</td>
                    </tr>" : "") . "
                    <tr class='total-row'>
                        <td colspan='3' style='text-align: right;'><strong>Total:</strong></td>
                        <td style='text-align: right;'>$" . number_format($grand_total, 2) . "</td>
        </tr>
    </tbody>
    </table>
    
            <div class='footer'>
                <p>Thank you for your business!</p>
                <p>This is an electronic receipt. Please keep for your records.</p>
    </div>
    </div>
    </body>
    </html>";
    
    return $html;
}

/**
 * Send receipt email using OpenEMR's email configuration
 */
function sendReceiptEmail($to_email, $receipt_number, $receipt_html, $patient_data, $facility_name) {
    $gmail_auth_issue = false;
    // Get OpenEMR email configuration
    $smtp_host = $GLOBALS['SMTP_HOST'] ?? '';
    $smtp_port = $GLOBALS['SMTP_PORT'] ?? 587;
    $smtp_username = $GLOBALS['SMTP_USER'] ?? '';
    $smtp_password = $GLOBALS['SMTP_PASS'] ?? '';
    $smtp_secure = $GLOBALS['SMTP_SECURE'] ?? 'tls';
    $smtp_from_email = $GLOBALS['patient_reminder_sender_email'] ?? $GLOBALS['SMTP_USER'] ?? 'noreply@openemr.org';
    $smtp_from_name = $GLOBALS['practice_return_email_path'] ?? $facility_name;
    
    // Check if we're in a development environment (localhost)
    $is_localhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '::1']) || 
                   strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false;
    
    // Validate from email address
    if (empty($smtp_from_email) || !filter_var($smtp_from_email, FILTER_VALIDATE_EMAIL)) {
        error_log("Invalid from email address: {$smtp_from_email}, using SMTP username as fallback");
        $smtp_from_email = $smtp_username;
    }
    
    // If SMTP is configured, try PHPMailer first (regardless of environment)
    if (!empty($smtp_host) && !empty($smtp_username) && class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        error_log("Attempting to send email via PHPMailer with SMTP: {$smtp_host}:{$smtp_port}");
        $result = sendReceiptEmailViaPHPMailer($to_email, $receipt_number, $receipt_html, $patient_data, $facility_name, $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_secure, $smtp_from_email, $smtp_from_name);
        if ($result === true) {
            error_log("PHPMailer email sent successfully");
            return ['success' => true, 'gmail_auth_issue' => false];
        } else {
            error_log("PHPMailer email failed, trying fallback methods");
            if (is_array($result) && isset($result['gmail_auth_issue'])) {
                $gmail_auth_issue = $result['gmail_auth_issue'];
            } elseif (strpos($smtp_host, 'gmail.com') !== false) {
                $gmail_auth_issue = true;
            }
        }
    }
    
    // If SMTP is configured but PHPMailer failed, try PHP mail() function
    if (!empty($smtp_host) && !empty($smtp_username)) {
        error_log("Attempting to send email via PHP mail() function");
        $result = sendReceiptEmailViaMail($to_email, $receipt_number, $receipt_html, $patient_data, $facility_name);
        if ($result) {
            error_log("PHP mail() email sent successfully");
            return ['success' => true, 'gmail_auth_issue' => $gmail_auth_issue];
        } else {
            error_log("PHP mail() email failed");
        }
    }
    
    // Only simulate if no SMTP is configured and we're in development
    if ($is_localhost && (empty($smtp_host) || empty($smtp_username))) {
        error_log("No SMTP configured, simulating email in development environment");
        $result = simulateEmailSending($to_email, $receipt_number, $receipt_html, $patient_data, $facility_name);
        return ['success' => $result, 'gmail_auth_issue' => false];
    }
    
    // Last resort: try PHP mail() function
    error_log("Attempting final fallback with PHP mail() function");
    $result = sendReceiptEmailViaMail($to_email, $receipt_number, $receipt_html, $patient_data, $facility_name);
    error_log("Final result - success: " . ($result ? 'true' : 'false') . ", gmail_auth_issue: " . ($gmail_auth_issue ? 'true' : 'false'));
    return ['success' => $result, 'gmail_auth_issue' => $gmail_auth_issue];
}

/**
 * Simulate email sending for development environment
 */
function simulateEmailSending($to_email, $receipt_number, $receipt_html, $patient_data, $facility_name) {
    $patient_name = trim($patient_data['fname'] . ' ' . $patient_data['mname'] . ' ' . $patient_data['lname']);
    
    // Create a log entry for the simulated email
    $log_message = "=== SIMULATED EMAIL SENT ===\n";
    $log_message .= "Date: " . date('Y-m-d H:i:s') . "\n";
    $log_message .= "To: {$to_email}\n";
    $log_message .= "Subject: Receipt #{$receipt_number} - {$facility_name}\n";
    $log_message .= "Patient: {$patient_name}\n";
    $log_message .= "Receipt Number: {$receipt_number}\n";
    $log_message .= "Facility: {$facility_name}\n";
    $log_message .= "================================\n\n";
    
    // Log to error log for debugging
    error_log($log_message);
    
    // Save email content to a file for easy viewing
    $email_dir = __DIR__ . '/email_logs';
    if (!is_dir($email_dir)) {
        mkdir($email_dir, 0755, true);
    }
    
    $email_content = "
    <!DOCTYPE html>
    <html>
    <head>
        <title>Simulated Email - Receipt #{$receipt_number}</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .email-header { background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
            .email-content { border: 1px solid #ddd; padding: 20px; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class='email-header'>
            <h3>Simulated Email Details</h3>
            <p><strong>To:</strong> {$to_email}</p>
            <p><strong>Subject:</strong> Receipt #{$receipt_number} - {$facility_name}</p>
            <p><strong>Patient:</strong> {$patient_name}</p>
            <p><strong>Date:</strong> " . date('Y-m-d H:i:s') . "</p>
        </div>
        <div class='email-content'>
            {$receipt_html}
            <p style='margin-top: 24px; color: #6b7280; font-size: 13px;'>Thank you for using JacTrac.</p>
        </div>
    </body>
    </html>";
    
    $filename = $email_dir . '/receipt_' . $receipt_number . '_' . date('Y-m-d_H-i-s') . '.html';
    file_put_contents($filename, $email_content);
    
    error_log("Email content saved to: " . $filename);
    
    return true; // Simulate success
}

/**
 * Send email using PHP's mail() function
 */
function sendReceiptEmailViaMail($to_email, $receipt_number, $receipt_html, $patient_data, $facility_name) {
    $patient_name = trim($patient_data['fname'] . ' ' . $patient_data['mname'] . ' ' . $patient_data['lname']);
    
    $subject = "Receipt #{$receipt_number} - {$facility_name}";
    
    $headers = array(
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: ' . ($GLOBALS['practice_return_email_path'] ?? $facility_name) . ' <' . ($GLOBALS['patient_reminder_sender_email'] ?? $GLOBALS['SMTP_USER'] ?? 'noreply@openemr.org') . '>',
        'Reply-To: ' . ($GLOBALS['patient_reminder_sender_email'] ?? $GLOBALS['SMTP_USER'] ?? 'noreply@openemr.org'),
        'X-Mailer: OpenEMR POS System'
    );
    
    $message = "
    <html>
    <head>
        <title>{$subject}</title>
    </head>
    <body>
        <p>Dear {$patient_name},</p>
        <p>Please find attached your receipt for transaction #{$receipt_number}.</p>
        <p>Thank you for choosing {$facility_name}.</p>
        <br>
        {$receipt_html}
        <p style='margin-top: 24px; color: #6b7280; font-size: 13px;'>Thank you for using JacTrac.</p>
    </body>
    </html>";
    
    return mail($to_email, $subject, $message, implode("\r\n", $headers));
}

/**
 * Send email using PHPMailer (if available)
 */
function sendReceiptEmailViaPHPMailer($to_email, $receipt_number, $receipt_html, $patient_data, $facility_name, $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_secure, $smtp_from_email, $smtp_from_name) {
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Enable debug output
        $mail->SMTPDebug = 0; // Set to 2 for detailed debug output
        $mail->Debugoutput = function($str, $level) {
            error_log("PHPMailer Debug: $str");
        };
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = $smtp_host;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_username;
        $mail->Password = $smtp_password;
        $mail->SMTPSecure = $smtp_secure;
        $mail->Port = $smtp_port;
        
        // Set timeout
        $mail->Timeout = 30;
        $mail->SMTPKeepAlive = true;
        
        // Recipients
        $mail->setFrom($smtp_from_email, $smtp_from_name);
        $mail->addAddress($to_email);
        
        // Content
        $patient_name = trim($patient_data['fname'] . ' ' . $patient_data['mname'] . ' ' . $patient_data['lname']);
        $subject = "Receipt #{$receipt_number} - {$facility_name}";
        
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = "
        <p>Dear {$patient_name},</p>
        <p>Please find attached your receipt for transaction #{$receipt_number}.</p>
        <p>Thank you for choosing {$facility_name}.</p>
        <br>
        {$receipt_html}
        <p style='margin-top: 24px; color: #6b7280; font-size: 13px;'>Thank you for using JacTrac.</p>";
        
        error_log("PHPMailer attempting to send email to: {$to_email} via {$smtp_host}:{$smtp_port}");
        $mail->send();
        error_log("PHPMailer email sent successfully to: {$to_email}");
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer error: " . $e->getMessage());
        error_log("PHPMailer error details: Host={$smtp_host}, Port={$smtp_port}, Username={$smtp_username}, Secure={$smtp_secure}");
        
        // Check for specific Gmail authentication issues
        $gmail_auth_issue = false;
        if (strpos($e->getMessage(), 'Could not authenticate') !== false && strpos($smtp_host, 'gmail.com') !== false) {
            error_log("GMAIL AUTHENTICATION ISSUE DETECTED:");
            error_log("  - Gmail requires an 'App Password' for SMTP authentication");
            error_log("  - If 2FA is enabled, you cannot use your regular password");
            error_log("  - Go to: https://myaccount.google.com/apppasswords");
            error_log("  - Generate an App Password for 'Mail' and use it instead of your regular password");
            $gmail_auth_issue = true;
        }
        
        return ['success' => false, 'gmail_auth_issue' => $gmail_auth_issue];
    }
}
?>












