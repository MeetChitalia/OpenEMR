<?php

/**
 * Patient spreadsheet import utility.
 *
 * v1 assumptions:
 * - Uses a fixed template/header order that we also generate as a sample XLSX
 * - Supports XLSX/XLS/CSV uploads
 * - Creates patients through the existing OpenEMR patient save helpers
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    OpenEMR
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$sessionAllowWrite = true;
require_once("../globals.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/options.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!AclMain::aclCheckCore('patients', 'demo', '', ['write', 'addonly'])) {
    die(xlt('Adding demographics is not authorized.'));
}

const PATIENT_IMPORT_SESSION_KEY = 'patient_import_preview_rows';
const PATIENT_IMPORT_FILE_SESSION_KEY = 'patient_import_uploaded_file_name';

function patientImportFetchOne(string $sql, array $params = []): array
{
    $result = sqlQuery($sql, $params);

    if (is_array($result)) {
        return $result;
    }

    if (is_object($result)) {
        $row = sqlFetchArray($result);
        return is_array($row) ? $row : [];
    }

    return [];
}

function patientImportTemplateColumns(): array
{
    return [
        'Patient ID',
        'First Name',
        'Middle Name',
        'Last Name',
        'DOB',
        'Sex',
        'Phone Home',
        'Phone Cell',
        'Email',
        'Street',
        'City',
        'State',
        'Zip',
        'Country',
        'Care Team (Facility)',
    ];
}

function patientImportHeaderMap(): array
{
    return [
        'patient id' => 'pubpid',
        'first name' => 'fname',
        'middle name' => 'mname',
        'last name' => 'lname',
        'dob' => 'DOB',
        'sex' => 'sex',
        'phone home' => 'phone_home',
        'phone cell' => 'phone_cell',
        'email' => 'email',
        'street' => 'street',
        'city' => 'city',
        'state' => 'state',
        'zip' => 'postal_code',
        'country' => 'country_code',
        'care team (facility)' => 'care_team_facility',
        'care team facility' => 'care_team_facility',
    ];
}

function patientImportRequiredFields(): array
{
    return ['fname', 'lname', 'DOB', 'sex'];
}

function patientImportRequiredColumnLabels(): array
{
    return [
        xlt('First Name'),
        xlt('Last Name'),
        xlt('DOB'),
        xlt('Sex'),
    ];
}

function patientImportNormalizeHeader(string $value): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', $value)));
}

function patientImportNormalizeSex($value): string
{
    $normalized = strtoupper(trim((string) $value));
    $map = [
        'M' => 'Male',
        'MALE' => 'Male',
        'F' => 'Female',
        'FEMALE' => 'Female',
        'O' => 'Other',
        'OTHER' => 'Other',
        'U' => 'Unknown',
        'UNKNOWN' => 'Unknown',
    ];

    return $map[$normalized] ?? trim((string) $value);
}

function patientImportNormalizeDateValue($value): string
{
    if ($value === null) {
        return '';
    }

    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $formats = [
        'Y-m-d',
        'm/d/Y',
        'n/j/Y',
        'm-d-Y',
        'n-j-Y',
        'd/m/Y',
        'd-m-Y',
        'Y/m/d',
    ];

    foreach ($formats as $format) {
        $parsed = DateTime::createFromFormat($format, $value);
        if ($parsed instanceof DateTime) {
            return $parsed->format('Y-m-d');
        }
    }

    $timestamp = strtotime($value);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }

    return '';
}

function patientImportNormalizePhone($value): string
{
    return preg_replace('/\D+/', '', trim((string) $value));
}

function patientImportFacilityNameIndex(): array
{
    static $index = null;
    if ($index !== null) {
        return $index;
    }

    $index = [];
    $result = sqlStatement("SELECT id, name FROM facility ORDER BY name ASC");
    while ($row = sqlFetchArray($result)) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string) ($row['name'] ?? ''));
        $id = (int) ($row['id'] ?? 0);
        if ($name === '' || $id <= 0) {
            continue;
        }
        $index[strtolower($name)] = $id;
    }

    return $index;
}

function patientImportNormalizeCareTeamFacility($value): array
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return ['', []];
    }

    $facilityIndex = patientImportFacilityNameIndex();
    $segments = preg_split('/\s*\|\s*|\s*;\s*|\r\n|\n|\r/', $raw) ?: [];
    $segments = array_values(array_filter(array_map('trim', $segments), static function ($segment) {
        return $segment !== '';
    }));

    if (empty($segments)) {
        return ['', []];
    }

    $ids = [];
    $invalid = [];
    foreach ($segments as $segment) {
        $lookupKey = strtolower($segment);
        if (!isset($facilityIndex[$lookupKey])) {
            $invalid[] = $segment;
            continue;
        }
        $ids[] = (string) $facilityIndex[$lookupKey];
    }

    $ids = array_values(array_unique($ids));
    return [implode(',', $ids), $invalid];
}

function patientImportValueFromCell($cell): string
{
    if ($cell === null) {
        return '';
    }

    $value = $cell->getValue();
    if ($value === null) {
        return '';
    }

    if (SpreadsheetDate::isDateTime($cell) && is_numeric($value)) {
        return SpreadsheetDate::excelToDateTimeObject($value)->format('Y-m-d');
    }

    if (is_string($value)) {
        return trim($value);
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    return trim((string) $value);
}

function patientImportBuildRow(array $mappedRow, int $rowNumber, array &$seenKeys = []): array
{
    $errors = [];

    foreach (patientImportRequiredFields() as $requiredField) {
        if (empty(trim((string) ($mappedRow[$requiredField] ?? '')))) {
            $errors[] = sprintf(xl('%s is required'), $requiredField);
        }
    }

    $mappedRow['DOB'] = patientImportNormalizeDateValue($mappedRow['DOB'] ?? '');
    if (empty($mappedRow['DOB'])) {
        $errors[] = xl('DOB is invalid or missing');
    }

    $mappedRow['sex'] = patientImportNormalizeSex($mappedRow['sex'] ?? '');
    if (empty($mappedRow['sex'])) {
        $errors[] = xl('Sex is required');
    }

    [$mappedRow['care_team_facility'], $careTeamFacilityInvalid] = patientImportNormalizeCareTeamFacility($mappedRow['care_team_facility'] ?? '');
    if (!empty($careTeamFacilityInvalid)) {
        $errors[] = xl('Care Team (Facility) contains unknown value(s)') . ': ' . implode(', ', $careTeamFacilityInvalid);
    }

    $errors = array_merge($errors, patientImportFindDuplicateIssues($mappedRow, $seenKeys));

    return [
        'row_number' => $rowNumber,
        'data' => $mappedRow,
        'errors' => $errors,
        'importable' => empty($errors),
    ];
}

function patientImportFindDuplicateIssues(array $mappedRow, array &$seenKeys = []): array
{
    $issues = [];

    $normalizedPubpid = trim((string) ($mappedRow['pubpid'] ?? ''));
    $normalizedPhoneCell = patientImportNormalizePhone($mappedRow['phone_cell'] ?? '');
    $normalizedEmail = strtolower(trim((string) ($mappedRow['email'] ?? '')));
    $normalizedNameDob = '';
    if (!empty($mappedRow['fname']) && !empty($mappedRow['lname']) && !empty($mappedRow['DOB'])) {
        $normalizedNameDob = strtoupper(trim((string) $mappedRow['fname'])) . '|' .
            strtoupper(trim((string) $mappedRow['lname'])) . '|' .
            trim((string) $mappedRow['DOB']);
    }

    if (!empty($mappedRow['pubpid'])) {
        $existing = patientImportFetchOne("SELECT pid FROM patient_data WHERE pubpid = ? LIMIT 1", [$mappedRow['pubpid']]);
        if (!empty($existing['pid'])) {
            $issues[] = xl('Duplicate patient id already exists');
        }
    }

    if ($normalizedPhoneCell !== '') {
        $existing = patientImportFetchOne(
            "SELECT pid FROM patient_data WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone_cell, '-', ''), ' ', ''), '(', ''), ')', ''), '.', '') = ? LIMIT 1",
            [$normalizedPhoneCell]
        );
        if (!empty($existing['pid'])) {
            $issues[] = xl('A patient already exists with the same mobile number');
        }
    }

    if ($normalizedEmail !== '') {
        $existing = patientImportFetchOne(
            "SELECT pid FROM patient_data WHERE TRIM(LOWER(email)) = ? LIMIT 1",
            [$normalizedEmail]
        );
        if (!empty($existing['pid'])) {
            $issues[] = xl('A patient already exists with the same email');
        }
    }

    if ($normalizedPhoneCell === '' && !empty($mappedRow['fname']) && !empty($mappedRow['lname']) && !empty($mappedRow['DOB'])) {
        $existing = patientImportFetchOne(
            "SELECT pid FROM patient_data WHERE fname = ? AND lname = ? AND DOB = ? LIMIT 1",
            [$mappedRow['fname'], $mappedRow['lname'], $mappedRow['DOB']]
        );
        if (!empty($existing['pid'])) {
            $issues[] = xl('Possible duplicate patient already exists with same first name, last name, and DOB');
        }
    }

    if ($normalizedPubpid !== '') {
        if (!empty($seenKeys['pubpid'][$normalizedPubpid])) {
            $issues[] = xl('Duplicate patient id found in uploaded file');
        } else {
            $seenKeys['pubpid'][$normalizedPubpid] = true;
        }
    }

    if ($normalizedPhoneCell !== '') {
        if (!empty($seenKeys['phone_cell'][$normalizedPhoneCell])) {
            $issues[] = xl('Duplicate mobile number found in uploaded file');
        } else {
            $seenKeys['phone_cell'][$normalizedPhoneCell] = true;
        }
    }

    if ($normalizedEmail !== '') {
        if (!empty($seenKeys['email'][$normalizedEmail])) {
            $issues[] = xl('Duplicate email found in uploaded file');
        } else {
            $seenKeys['email'][$normalizedEmail] = true;
        }
    } elseif ($normalizedNameDob !== '') {
        if (!empty($seenKeys['name_dob'][$normalizedNameDob])) {
            $issues[] = xl('Duplicate patient found in uploaded file with same first name, last name, and DOB');
        } else {
            $seenKeys['name_dob'][$normalizedNameDob] = true;
        }
    }

    return $issues;
}

function patientImportParseSpreadsheet(string $filePath): array
{
    $spreadsheet = IOFactory::load($filePath);
    $worksheet = $spreadsheet->getActiveSheet();
    $highestRow = $worksheet->getHighestDataRow();
    $highestColumn = $worksheet->getHighestDataColumn();
    $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

    $headerMap = patientImportHeaderMap();
    $templateColumns = patientImportTemplateColumns();
    $foundHeaders = [];
    $headerLookup = [];
    $headerRowNumber = null;

    $rowsToScan = min($highestRow, 5);
    for ($row = 1; $row <= $rowsToScan; $row++) {
        $candidateHeaders = [];
        $candidateLookup = [];

        for ($column = 1; $column <= $highestColumnIndex; $column++) {
            $rawValue = $worksheet->getCellByColumnAndRow($column, $row)->getValue();
            $headerValue = patientImportNormalizeHeader((string) $rawValue);
            if ($headerValue !== '') {
                $candidateHeaders[] = $headerValue;
                $candidateLookup[$column] = $headerMap[$headerValue] ?? null;
            }
        }

        $matchedHeaders = array_values(array_filter($candidateLookup, function ($value) {
            return !empty($value);
        }));

        if (count($matchedHeaders) > count(array_filter($headerLookup))) {
            $foundHeaders = $candidateHeaders;
            $headerLookup = $candidateLookup;
            $headerRowNumber = $row;
        }
    }

    $missingHeaders = [];
    foreach ($templateColumns as $templateColumn) {
        $normalized = patientImportNormalizeHeader($templateColumn);
        if (!in_array($normalized, $foundHeaders, true)) {
            $missingHeaders[] = $templateColumn;
        }
    }

    if (!empty($missingHeaders)) {
        throw new RuntimeException(xl('Missing required columns') . ': ' . implode(', ', $missingHeaders));
    }

    $parsedRows = [];
    if ($headerRowNumber === null) {
        throw new RuntimeException(xl('Could not find a valid header row in the uploaded file'));
    }

    $seenKeys = [
        'pubpid' => [],
        'phone_cell' => [],
        'name_dob' => [],
    ];

    for ($row = $headerRowNumber + 1; $row <= $highestRow; $row++) {
        $mappedRow = [];
        $hasAnyValue = false;

        for ($column = 1; $column <= $highestColumnIndex; $column++) {
            $targetField = $headerLookup[$column] ?? null;
            if (empty($targetField)) {
                continue;
            }

            $value = patientImportValueFromCell($worksheet->getCellByColumnAndRow($column, $row));
            if ($value !== '') {
                $hasAnyValue = true;
            }

            $mappedRow[$targetField] = $value;
        }

        if (!$hasAnyValue) {
            continue;
        }

        $parsedRows[] = patientImportBuildRow($mappedRow, $row, $seenKeys);
    }

    return $parsedRows;
}

function patientImportAssignFacility(int $pid): void
{
    $assignedFacilityId = null;

    if (isset($_SESSION['facilityId']) && (int) $_SESSION['facilityId'] > 0) {
        $assignedFacilityId = (int) $_SESSION['facilityId'];
    } elseif (!empty($_SESSION['facility_id'])) {
        $assignedFacilityId = (int) $_SESSION['facility_id'];
    } else {
        $userFacility = patientImportFetchOne("SELECT facility_id FROM users WHERE id = ?", [$_SESSION['authUserID'] ?? 0]);
        if (!empty($userFacility['facility_id'])) {
            $assignedFacilityId = $userFacility['facility_id'];
        }
    }

    if (!empty($assignedFacilityId)) {
        sqlStatement("UPDATE patient_data SET facility_id = ? WHERE pid = ?", [$assignedFacilityId, $pid]);
    }
}

function patientImportPersistCareTeamFacility(int $pid, $careTeamFacility): void
{
    $normalizedValue = trim((string) $careTeamFacility);
    sqlStatement("UPDATE patient_data SET care_team_facility = ? WHERE pid = ?", [$normalizedValue !== '' ? $normalizedValue : null, $pid]);
}

function patientImportCreatePatient(array $mappedRow): int
{
    $mappedRow['regdate'] = $mappedRow['regdate'] ?? date('Y-m-d');
    $pid = updatePatientData(null, $mappedRow, true);

    if (empty($pid)) {
        throw new RuntimeException(xl('Patient creation failed'));
    }

    patientImportAssignFacility((int) $pid);
    patientImportPersistCareTeamFacility((int) $pid, $mappedRow['care_team_facility'] ?? '');
    newHistoryData($pid);
    newInsuranceData($pid, "primary");
    if (!$GLOBALS['insurance_only_one']) {
        newInsuranceData($pid, "secondary");
        newInsuranceData($pid, "tertiary");
    }
    if (!$GLOBALS['omit_employers']) {
        updateEmployerData($pid, [], true);
    }

    return (int) $pid;
}

function patientImportSendTemplate(): void
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $headers = patientImportTemplateColumns();
    $sampleRows = [
        ['PT-1001', 'John', 'A', 'Doe', '1985-06-14', 'Male', '555-210-1001', '555-310-1001', 'john.doe@example.com', '123 Main St', 'Chicago', 'IL', '60601', 'US', 'Chattanooga, TN'],
        ['PT-1002', 'Maria', '', 'Lopez', '1992-11-03', 'Female', '555-210-1002', '555-310-1002', 'maria.lopez@example.com', '456 Oak Ave', 'Dallas', 'TX', '75201', 'US', 'Clarksville, TN | Columbus, GA'],
        ['PT-1003', 'Sam', '', 'Taylor', '1978-02-28', 'Other', '555-210-1003', '555-310-1003', 'sam.taylor@example.com', '789 Pine Rd', 'Austin', 'TX', '73301', 'US', 'Dothan, AL'],
    ];

    $sheet->fromArray($headers, null, 'A1');
    $sheet->fromArray($sampleRows, null, 'A2');

    $lastColumn = $sheet->getHighestColumn();
    $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
    $sheet->getStyle("A1:{$lastColumn}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDDEBF7');

    foreach (range('A', $lastColumn) as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    $facilitySheet = $spreadsheet->createSheet();
    $facilitySheet->setTitle('Care Team Facilities');
    $facilitySheet->setCellValue('A1', 'Available Care Team (Facility) Values');
    $facilitySheet->getStyle('A1')->getFont()->setBold(true);
    $facilitySheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDDEBF7');

    $facilityResult = sqlStatement("SELECT name FROM facility ORDER BY name ASC");
    $facilityRowNumber = 2;
    while ($facilityRow = sqlFetchArray($facilityResult)) {
        if (!is_array($facilityRow) || empty($facilityRow['name'])) {
            continue;
        }
        $facilitySheet->setCellValue('A' . $facilityRowNumber, (string) $facilityRow['name']);
        $facilityRowNumber++;
    }
    $facilitySheet->getColumnDimension('A')->setAutoSize(true);

    $fileName = 'patient_import_sample.xlsx';
    $tempFile = tempnam($GLOBALS['temporary_files_dir'], 'PTI');
    $writer = new Xlsx($spreadsheet);
    $writer->save($tempFile);

    header('Content-Description: File Transfer');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($tempFile));
    header('Cache-Control: max-age=0');
    readfile($tempFile);
    @unlink($tempFile);
    exit;
}

$previewRows = $_SESSION[PATIENT_IMPORT_SESSION_KEY] ?? [];
$lastUploadedFileName = $_SESSION[PATIENT_IMPORT_FILE_SESSION_KEY] ?? '';
$messages = [];
$importResults = null;
if (isset($_GET['download_sample']) && $_GET['download_sample'] === '1') {
    patientImportSendTemplate();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }

    if (!empty($_POST['preview_import'])) {
        try {
            if (empty($_FILES['patient_file']['tmp_name']) || !is_uploaded_file($_FILES['patient_file']['tmp_name'])) {
                throw new RuntimeException(xl('Please choose an Excel or CSV file to import'));
            }

            $previewRows = patientImportParseSpreadsheet($_FILES['patient_file']['tmp_name']);
            $_SESSION[PATIENT_IMPORT_SESSION_KEY] = $previewRows;
            $_SESSION[PATIENT_IMPORT_FILE_SESSION_KEY] = $_FILES['patient_file']['name'] ?? '';
            $lastUploadedFileName = $_SESSION[PATIENT_IMPORT_FILE_SESSION_KEY];

            $messages[] = ['type' => 'success', 'text' => xl('File parsed successfully. Review the preview and import the ready rows.')];
        } catch (Throwable $exception) {
            $messages[] = ['type' => 'danger', 'text' => $exception->getMessage()];
            $previewRows = [];
            unset($_SESSION[PATIENT_IMPORT_SESSION_KEY], $_SESSION[PATIENT_IMPORT_FILE_SESSION_KEY]);
        }
    } elseif (!empty($_POST['run_import'])) {
        $previewRows = $_SESSION[PATIENT_IMPORT_SESSION_KEY] ?? [];
        $importResults = [
            'created' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($previewRows as $previewRow) {
            if (empty($previewRow['importable'])) {
                $importResults['skipped']++;
                continue;
            }

            try {
                patientImportCreatePatient($previewRow['data']);
                $importResults['created']++;
            } catch (Throwable $exception) {
                $importResults['errors'][] = xl('Row') . ' ' . ($previewRow['row_number'] ?? '?') . ': ' . $exception->getMessage();
            }
        }

        unset($_SESSION[PATIENT_IMPORT_SESSION_KEY], $_SESSION[PATIENT_IMPORT_FILE_SESSION_KEY]);
        $previewRows = [];
        $lastUploadedFileName = '';
        $messages[] = ['type' => 'success', 'text' => xl('Import completed.')];
    } elseif (!empty($_POST['clear_preview'])) {
        unset($_SESSION[PATIENT_IMPORT_SESSION_KEY], $_SESSION[PATIENT_IMPORT_FILE_SESSION_KEY]);
        $previewRows = [];
        $lastUploadedFileName = '';
    }
}

$previewCounts = [
    'total' => count($previewRows),
    'ready' => count(array_filter($previewRows, function ($row) {
        return !empty($row['importable']);
    })),
    'blocked' => count(array_filter($previewRows, function ($row) {
        return empty($row['importable']);
    })),
];
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Patient Import'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <style>
        .patient-import-wrap {
            max-width: 1200px;
            margin: 24px auto;
            padding: 0 16px 32px;
        }
        .patient-import-card {
            background: #fff;
            border: 1px solid #d7dce2;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 18px;
        }
        .patient-import-title {
            font-size: 28px;
            font-weight: 700;
            color: #17324d;
            margin: 0 0 8px;
        }
        .patient-import-subtitle {
            color: #506174;
            margin-bottom: 18px;
        }
        .patient-import-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }
        .patient-import-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }
        .patient-import-stat {
            background: #f7fafc;
            border-radius: 10px;
            padding: 14px;
            border: 1px solid #e3e8ef;
        }
        .patient-import-stat strong {
            display: block;
            font-size: 24px;
            color: #17324d;
        }
        .patient-import-upload {
            border: 2px dashed #b9c6d4;
            border-radius: 12px;
            padding: 18px;
            background: #f9fbfd;
        }
        .patient-import-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
            font-size: 14px;
        }
        .patient-import-table th,
        .patient-import-table td {
            border: 1px solid #dfe5eb;
            padding: 10px;
            vertical-align: top;
        }
        .patient-import-table th {
            background: #eef4f8;
            color: #17324d;
        }
        .patient-import-ready {
            color: #0f6b3c;
            font-weight: 600;
        }
        .patient-import-blocked {
            color: #9b2226;
            font-weight: 600;
        }
        .patient-import-note {
            margin-top: 12px;
            color: #617385;
            font-size: 13px;
        }
    </style>
</head>
<body>
<div class="patient-import-wrap">
    <?php foreach ($messages as $message) { ?>
        <div class="alert alert-<?php echo attr($message['type']); ?>"><?php echo text($message['text']); ?></div>
    <?php } ?>

    <?php if (is_array($importResults)) { ?>
        <div class="patient-import-card">
            <h2><?php echo xlt('Import Results'); ?></h2>
            <div class="patient-import-grid">
                <div class="patient-import-stat">
                    <strong><?php echo text((string) $importResults['created']); ?></strong>
                    <span><?php echo xlt('Patients Created'); ?></span>
                </div>
                <div class="patient-import-stat">
                    <strong><?php echo text((string) $importResults['skipped']); ?></strong>
                    <span><?php echo xlt('Rows Skipped'); ?></span>
                </div>
                <div class="patient-import-stat">
                    <strong><?php echo text((string) count($importResults['errors'])); ?></strong>
                    <span><?php echo xlt('Errors'); ?></span>
                </div>
            </div>
            <?php if (!empty($importResults['errors'])) { ?>
                <div class="alert alert-danger mt-3">
                    <?php echo text(implode(' | ', $importResults['errors'])); ?>
                </div>
            <?php } ?>
        </div>
    <?php } ?>

    <div class="patient-import-card">
        <h1 class="patient-import-title"><?php echo xlt('Patient Import'); ?></h1>
        <form method="post" enctype="multipart/form-data" class="patient-import-upload">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
            <div class="alert alert-info" role="note">
                <?php echo xlt('Mandatory columns:'); ?>
                <?php echo text(implode(', ', patientImportRequiredColumnLabels())); ?>.
            </div>
            <div class="form-group">
                <label for="patient_file"><?php echo xlt('Excel or CSV File'); ?></label>
                <input type="file" class="form-control" id="patient_file" name="patient_file" accept=".xlsx,.xls,.csv" required>
            </div>
            <button type="submit" name="preview_import" value="1" class="btn btn-primary"><?php echo xlt('Preview Import'); ?></button>
            <button type="submit" name="clear_preview" value="1" class="btn btn-secondary" id="clear-preview-button" formnovalidate><?php echo xlt('Clear Preview'); ?></button>
        </form>
    </div>

    <?php if (!empty($previewRows)) { ?>
        <div class="patient-import-card" id="patient-import-preview-panel">
            <h2><?php echo xlt('Preview'); ?></h2>
            <div class="patient-import-grid">
                <div class="patient-import-stat">
                    <strong><?php echo text((string) $previewCounts['total']); ?></strong>
                    <span><?php echo xlt('Rows Found'); ?></span>
                </div>
                <div class="patient-import-stat">
                    <strong><?php echo text((string) $previewCounts['ready']); ?></strong>
                    <span><?php echo xlt('Ready to Import'); ?></span>
                </div>
                <div class="patient-import-stat">
                    <strong><?php echo text((string) $previewCounts['blocked']); ?></strong>
                    <span><?php echo xlt('Blocked Rows'); ?></span>
                </div>
            </div>

            <form method="post" class="mt-3">
                <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
                <button type="submit" name="run_import" value="1" class="btn btn-success" <?php echo $previewCounts['ready'] > 0 ? '' : 'disabled'; ?>>
                    <?php echo xlt('Import Ready Rows'); ?>
                </button>
            </form>

            <table class="patient-import-table">
                <thead>
                <tr>
                    <th><?php echo xlt('Sheet Row'); ?></th>
                    <th><?php echo xlt('Status'); ?></th>
                    <th><?php echo xlt('Patient ID'); ?></th>
                    <th><?php echo xlt('First Name'); ?></th>
                    <th><?php echo xlt('Last Name'); ?></th>
                    <th><?php echo xlt('DOB'); ?></th>
                    <th><?php echo xlt('Sex'); ?></th>
                    <th><?php echo xlt('Issues'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($previewRows as $previewRow) { ?>
                    <tr>
                        <td><?php echo text((string) ($previewRow['row_number'] ?? '')); ?></td>
                        <td class="<?php echo !empty($previewRow['importable']) ? 'patient-import-ready' : 'patient-import-blocked'; ?>">
                            <?php echo !empty($previewRow['importable']) ? xlt('Ready') : xlt('Blocked'); ?>
                        </td>
                        <td><?php echo text($previewRow['data']['pubpid'] ?? ''); ?></td>
                        <td><?php echo text($previewRow['data']['fname'] ?? ''); ?></td>
                        <td><?php echo text($previewRow['data']['lname'] ?? ''); ?></td>
                        <td><?php echo text($previewRow['data']['DOB'] ?? ''); ?></td>
                        <td><?php echo text($previewRow['data']['sex'] ?? ''); ?></td>
                        <td><?php echo text(implode('; ', $previewRow['errors'] ?? [])); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var clearButton = document.getElementById('clear-preview-button');
    if (!clearButton) {
        return;
    }

    clearButton.addEventListener('click', function () {
        var fileInput = document.getElementById('patient_file');
        if (fileInput) {
            fileInput.value = '';
        }

        var previewPanel = document.getElementById('patient-import-preview-panel');
        if (previewPanel) {
            previewPanel.remove();
        }
    });
});
</script>
</body>
</html>
