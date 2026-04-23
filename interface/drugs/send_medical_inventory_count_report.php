<?php

require_once("../globals.php");
require_once("$srcdir/options.inc.php");
require_once(__DIR__ . "/medical_inventory_count_common.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

header('Content-Type: application/json');

function buildMedicalInventoryEmailHtml(array $summaryMetrics, array $reportRows, string $facilityName): string
{
    $generatedAt = date('m/d/Y h:i A');
    $countedItems = (int) ($summaryMetrics['counted_items'] ?? 0);
    $totalItems = (int) ($summaryMetrics['total_items'] ?? 0);
    $mismatchCount = (int) ($summaryMetrics['mismatch_count'] ?? 0);
    $attachmentName = (string) ($summaryMetrics['attachment_name'] ?? '');

    $rowsHtml = '';
    foreach ($reportRows as $row) {
        $statusColor = $row['status'] === 'matched' ? '#15803d' : '#c62828';
        $rowsHtml .= '<tr>';
        $rowsHtml .= '<td style="border:1px solid #d8e4f0; padding:10px 12px;">' . htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') . '</td>';
        $rowsHtml .= '<td style="border:1px solid #d8e4f0; padding:10px 12px; text-align:right;">' . number_format((float) $row['expected_qoh'], 2) . '</td>';
        $rowsHtml .= '<td style="border:1px solid #d8e4f0; padding:10px 12px; text-align:right;">' . number_format((float) $row['counted_qoh'], 2) . '</td>';
        $rowsHtml .= '<td style="border:1px solid #d8e4f0; padding:10px 12px; text-align:right;">' . number_format((float) $row['variance_qoh'], 2) . '</td>';
        $rowsHtml .= '<td style="border:1px solid #d8e4f0; padding:10px 12px; color:' . $statusColor . '; font-weight:700;">' . htmlspecialchars(ucfirst((string) $row['status']), ENT_QUOTES, 'UTF-8') . '</td>';
        $rowsHtml .= '<td style="border:1px solid #d8e4f0; padding:10px 12px;">' . htmlspecialchars((string) $row['counted_at'], ENT_QUOTES, 'UTF-8') . '</td>';
        $rowsHtml .= '</tr>';
    }

    return "
    <!doctype html>
    <html>
    <body style='margin:0; padding:24px; background:#eef3f8; font-family:Arial, Helvetica, sans-serif; color:#1f2937;'>
        <div style='max-width:920px; margin:0 auto; background:#ffffff; border:1px solid #d7e2ee; border-radius:16px; overflow:hidden; box-shadow:0 10px 24px rgba(15, 23, 42, 0.06);'>
            <div style='background:linear-gradient(135deg, #163a63 0%, #1f5a96 100%); color:#ffffff; padding:28px 32px 24px 32px;'>
                <div style='font-size:12px; letter-spacing:0.08em; text-transform:uppercase; opacity:0.82; margin-bottom:10px;'>JACtrac Reporting</div>
                <div style='font-size:28px; line-height:1.25; font-weight:700; margin-bottom:8px;'>Medical Inventory Count Report</div>
                <div style='font-size:15px; line-height:1.5; opacity:0.94;'>
                    Your Medical inventory count summary for <strong>" . htmlspecialchars($facilityName, ENT_QUOTES, 'UTF-8') . "</strong> is ready. The spreadsheet attachment is included for review and distribution.
                </div>
            </div>
            <div style='padding:28px 32px;'>
                <div style='font-size:14px; line-height:1.7; color:#334155; margin-bottom:20px;'>
                    This email includes a concise inventory summary for the completed count. The attached spreadsheet remains the source file for operational review and recordkeeping.
                </div>
                <table cellpadding='0' cellspacing='0' border='0' style='width:100%; margin-bottom:22px;'>
                    <tr>
                        <td style='width:33.33%; padding-right:10px; vertical-align:top;'>
                            <div style='border:1px solid #d8e4f0; background:#f8fbff; border-radius:12px; padding:14px 16px;'>
                                <div style='font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:#64748b; margin-bottom:6px;'>Location</div>
                                <div style='font-size:15px; font-weight:700; color:#1e293b;'>" . htmlspecialchars($facilityName, ENT_QUOTES, 'UTF-8') . "</div>
                            </div>
                        </td>
                        <td style='width:33.33%; padding:0 5px; vertical-align:top;'>
                            <div style='border:1px solid #d8e4f0; background:#f8fbff; border-radius:12px; padding:14px 16px;'>
                                <div style='font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:#64748b; margin-bottom:6px;'>Generated</div>
                                <div style='font-size:15px; font-weight:700; color:#1e293b;'>" . htmlspecialchars($generatedAt, ENT_QUOTES, 'UTF-8') . "</div>
                            </div>
                        </td>
                        <td style='width:33.33%; padding-left:10px; vertical-align:top;'>
                            <div style='border:1px solid #d8e4f0; background:#f8fbff; border-radius:12px; padding:14px 16px;'>
                                <div style='font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:#64748b; margin-bottom:6px;'>Progress</div>
                                <div style='font-size:15px; font-weight:700; color:#1e293b;'>" . $countedItems . " / " . $totalItems . " counted</div>
                            </div>
                        </td>
                    </tr>
                </table>
                <table cellpadding='0' cellspacing='0' border='0' style='width:100%; margin-bottom:20px;'>
                    <tr>
                        <td style='width:50%; padding:0 8px 12px 0; vertical-align:top;'>
                            <div style='border:1px solid #d9e3ef; border-radius:12px; padding:16px 18px; background:#ffffff;'>
                                <div style='font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:#64748b; margin-bottom:8px;'>Mismatches</div>
                                <div style='font-size:28px; font-weight:700; color:#163a63;'>" . $mismatchCount . "</div>
                            </div>
                        </td>
                        <td style='width:50%; padding:0 0 12px 8px; vertical-align:top;'>
                            <div style='border:1px solid #d9e3ef; border-radius:12px; padding:16px 18px; background:#ffffff;'>
                                <div style='font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:#64748b; margin-bottom:8px;'>Attachment</div>
                                <div style='font-size:18px; font-weight:700; color:#163a63;'>" . htmlspecialchars($attachmentName, ENT_QUOTES, 'UTF-8') . "</div>
                            </div>
                        </td>
                    </tr>
                </table>
                <div style='font-size:17px; font-weight:700; color:#183b63; margin:0 0 12px 0;'>Count Details</div>
                <table cellpadding='0' cellspacing='0' border='0' style='width:100%; border-collapse:collapse; margin-bottom:22px;'>
                    <tr style='background:#eaf2ff;'>
                        <th style='border:1px solid #d8e4f0; padding:10px 12px; text-align:left;'>Item</th>
                        <th style='border:1px solid #d8e4f0; padding:10px 12px; text-align:right;'>QOH</th>
                        <th style='border:1px solid #d8e4f0; padding:10px 12px; text-align:right;'>Counted</th>
                        <th style='border:1px solid #d8e4f0; padding:10px 12px; text-align:right;'>Difference</th>
                        <th style='border:1px solid #d8e4f0; padding:10px 12px; text-align:left;'>Status</th>
                        <th style='border:1px solid #d8e4f0; padding:10px 12px; text-align:left;'>Counted At</th>
                    </tr>
                    " . $rowsHtml . "
                </table>
                <div style='background:#f8fbff; border:1px solid #d8e4f0; border-radius:12px; padding:16px 18px; margin-bottom:22px;'>
                    <div style='font-size:14px; font-weight:700; color:#1e3a5f; margin-bottom:6px;'>Attachment Included</div>
                    <div style='font-size:13px; line-height:1.6; color:#475569;'>
                        The attached spreadsheet contains the complete Medical inventory count detail for this reporting period.
                    </div>
                </div>
                <div style='padding-top:18px; border-top:1px solid #e2e8f0;'>
                    <div style='font-size:14px; font-weight:700; color:#1e3a5f; margin-bottom:6px;'>Prepared by JACtrac</div>
                    <div style='font-size:13px; color:#475569; line-height:1.6; margin-bottom:4px;'>
                        Operational reporting for Medical inventory counts, mismatch visibility, and audit-ready review.
                    </div>
                    <div style='font-size:12px; color:#64748b; line-height:1.6;'>
                        This report was generated automatically from the OpenEMR Medical inventory counting workflow and delivered through the JACtrac reporting process.
                    </div>
                    <div style='font-size:12px; color:#64748b; line-height:1.6; margin-top:12px;'>
                        Thank you for using JacTrac.
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>";
}

$can_edit_cqoh = AclMain::aclCheckCore('admin', 'drugs') ||
    AclMain::aclCheckCore('inventory', 'lots') ||
    AclMain::aclCheckCore('inventory', 'purchases') ||
    AclMain::aclCheckCore('inventory', 'transfers') ||
    AclMain::aclCheckCore('inventory', 'adjustments') ||
    AclMain::aclCheckCore('inventory', 'consumption') ||
    AclMain::aclCheckCore('inventory', 'destruction');

if (!$can_edit_cqoh) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

$facilityId = getMedicalInventoryScopeFacilityId();
$summary = buildMedicalInventoryCountSummary($facilityId);
$missingItems = $summary['missing_items'] ?? [];
$allowedRecipients = getMedicalInventoryAdminRecipients();
$allowedRecipientEmails = array_column($allowedRecipients, 'email');

if (($summary['total_items'] ?? 0) <= 0) {
    echo json_encode(['success' => false, 'message' => 'No Medical inventory items are available for counting in this facility.']);
    exit;
}

if (!empty($missingItems)) {
    echo json_encode([
        'success' => false,
        'incomplete' => true,
        'message' => 'Inventory count is incomplete. Some Medical items have not been counted yet.',
        'missing_items' => $missingItems,
        'counted_items' => (int) ($summary['counted_items'] ?? 0),
        'total_items' => (int) ($summary['total_items'] ?? 0),
    ]);
    exit;
}

$requestedRecipient = trim((string) ($_POST['recipient_email'] ?? ''));
$adminEmail = $requestedRecipient;
if ($adminEmail === '' || !in_array($adminEmail, $allowedRecipientEmails, true)) {
    $adminEmail = getMedicalInventoryAdminEmail();
}
if ($adminEmail === '') {
    echo json_encode(['success' => false, 'message' => 'Inventory admin email is not configured.']);
    exit;
}

if (!class_exists('MyMailer', false)) {
    require_once(__DIR__ . "/../../library/classes/postmaster.php");
}

$facilityName = 'All Facilities';
if ($facilityId > 0) {
    $facilityRow = sqlQuery("SELECT name FROM facility WHERE id = ?", [$facilityId]);
    $facilityRow = medicalInventoryFetchRow($facilityRow);
    if (!empty($facilityRow['name'])) {
        $facilityName = (string) $facilityRow['name'];
    }
}

$countMap = $summary['count_map'] ?? [];
$expectedRows = $summary['expected_rows'] ?? [];
$reportRows = [];
$mismatchCount = 0;

foreach ($expectedRows as $drugId => $drugRow) {
    $countRow = $countMap[$drugId] ?? null;
    if (!$countRow) {
        continue;
    }

    $expectedQoh = (float) ($countRow['expected_qoh'] ?? 0);
    $countedQoh = (float) ($countRow['counted_qoh'] ?? 0);
    $variance = getMedicalInventoryRoundedVariance($expectedQoh, $countedQoh);
    $status = getMedicalInventoryCountStatus($expectedQoh, $countedQoh);
    if (abs($variance) >= 0.0001) {
        $mismatchCount++;
    }

    $reportRows[] = [
        'name' => (string) ($drugRow['name'] ?? ''),
        'expected_qoh' => $expectedQoh,
        'counted_qoh' => $countedQoh,
        'variance_qoh' => $variance,
        'status' => $status,
        'counted_at' => (string) ($countRow['counted_at'] ?? ''),
    ];
}

$csvHandle = fopen('php://temp', 'w+');
if ($csvHandle === false) {
    echo json_encode(['success' => false, 'message' => 'Unable to generate spreadsheet attachment.']);
    exit;
}

fputcsv($csvHandle, ['Facility', 'Generated', 'Item', 'QOH', 'Counted', 'Difference', 'Status', 'Counted At']);
foreach ($reportRows as $row) {
    fputcsv($csvHandle, [
        $facilityName,
        date('m/d/Y H:i:s'),
        $row['name'],
        number_format((float) $row['expected_qoh'], 2, '.', ''),
        number_format((float) $row['counted_qoh'], 2, '.', ''),
        number_format((float) $row['variance_qoh'], 2, '.', ''),
        $row['status'],
        $row['counted_at'],
    ]);
}
rewind($csvHandle);
$csvContent = stream_get_contents($csvHandle);
fclose($csvHandle);

$safeFacilitySlug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $facilityName) ?: 'facility';
$csvFileName = 'medical_inventory_count_' . $safeFacilitySlug . '_' . date('Ymd_His') . '.csv';

$html = buildMedicalInventoryEmailHtml([
    'counted_items' => (int) ($summary['counted_items'] ?? 0),
    'total_items' => (int) ($summary['total_items'] ?? 0),
    'mismatch_count' => $mismatchCount,
    'attachment_name' => $csvFileName,
], $reportRows, $facilityName);

try {
    $mail = new MyMailer();
    $senderEmail = getMedicalInventoryMailerFromEmail();
    if ($senderEmail === '') {
        $senderEmail = $adminEmail;
    }
    $transportEmail = trim((string) ($GLOBALS['SMTP_USER'] ?? ''));
    $senderName = 'JACtrac Inventory Count';
    $mail->AddReplyTo($senderEmail, $senderName);
    $mail->SetFrom($senderEmail, $senderName);
    if ($transportEmail !== '' && filter_var($transportEmail, FILTER_VALIDATE_EMAIL) && strcasecmp($transportEmail, $senderEmail) !== 0) {
        $mail->Sender = $transportEmail;
    }
    $mail->AddAddress($adminEmail, $adminEmail);
    $mail->Subject = 'JACtrac Medical Inventory Count Report | ' . $facilityName . ' | ' . date('m/d/Y');
    $mail->MsgHTML($html);
    $mail->IsHTML(true);
    $mail->AltBody = 'Medical Inventory Count Report for ' . $facilityName . '. The spreadsheet attachment is included for review and distribution.' . "\n\nThank you for using JacTrac.";
    $mail->addStringAttachment($csvContent, $csvFileName, 'base64', 'text/csv');

    if (!$mail->Send()) {
        error_log('Medical inventory count report email send failed: ' . $mail->ErrorInfo);
        echo json_encode(['success' => false, 'message' => 'Email send failed: ' . $mail->ErrorInfo]);
        exit;
    }

    error_log('Medical inventory count report email sent to ' . $adminEmail . ' using from ' . $senderEmail . ' with attachment ' . $csvFileName);

    markMedicalInventoryCountsReported($facilityId, (int) ($_SESSION['authUserID'] ?? 0));

    echo json_encode([
        'success' => true,
        'message' => 'Medical inventory count report sent with spreadsheet attachment.',
        'recipient' => $adminEmail,
        'attachment' => $csvFileName,
        'mismatch_count' => $mismatchCount,
    ]);
} catch (Throwable $e) {
    error_log('Medical inventory count report send failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to send report: ' . $e->getMessage()]);
}
