<?php

/**
 * Shared helpers for Medical inventory counting workflow.
 */

function isMedicationInventoryCategoryName(string $categoryName): bool
{
    $normalized = strtolower(trim($categoryName));
    return in_array($normalized, ['medical', 'medication'], true);
}

require_once(dirname(__FILE__) . "/../globals.php");

function medicalInventoryFetchRow($result)
{
    if (is_object($result) && method_exists($result, 'FetchRow')) {
        return $result->FetchRow();
    }

    return is_array($result) ? $result : null;
}

function ensureMedicalInventoryCountTable(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    sqlStatement(
        "CREATE TABLE IF NOT EXISTS medical_inventory_counts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            facility_id INT NOT NULL DEFAULT 0,
            drug_id INT NOT NULL,
            expected_qoh DECIMAL(12,4) NOT NULL DEFAULT 0,
            counted_qoh DECIMAL(12,4) NOT NULL DEFAULT 0,
            variance_qoh DECIMAL(12,4) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'matched',
            counted_by INT DEFAULT NULL,
            counted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            report_sent_at DATETIME DEFAULT NULL,
            report_sent_by INT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_facility_sent (facility_id, report_sent_at),
            INDEX idx_drug_sent (drug_id, report_sent_at),
            INDEX idx_facility_drug_sent (facility_id, drug_id, report_sent_at)
        )"
    );

    $ensured = true;
}

function getMedicalInventoryScopeFacilityId(): int
{
    if (!empty($_SESSION['facilityId'])) {
        return (int) $_SESSION['facilityId'];
    }

    if (!empty($_SESSION['facility_id'])) {
        return (int) $_SESSION['facility_id'];
    }

    return 0;
}

function getMedicalInventoryAdminEmail(): string
{
    $candidates = [
        trim((string) ($GLOBALS['practice_return_email_path'] ?? '')),
        trim((string) ($GLOBALS['patient_reminder_sender_email'] ?? '')),
        trim((string) ($GLOBALS['SMTP_USER'] ?? '')),
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            return $candidate;
        }
    }

    return '';
}

function getMedicalInventoryAdminRecipients(): array
{
    $recipients = [];

    $res = sqlStatement(
        "SELECT DISTINCT
                u.id,
                u.username,
                u.fname,
                u.lname,
                COALESCE(NULLIF(u.google_signin_email, ''), NULLIF(u.email, '')) AS admin_email
           FROM users AS u
     INNER JOIN gacl_aro AS aro
             ON aro.section_value = 'users'
            AND aro.value = u.username
     INNER JOIN gacl_groups_aro_map AS gm
             ON gm.aro_id = aro.id
     INNER JOIN gacl_aro_groups AS ag
             ON ag.id = gm.group_id
          WHERE u.active = 1
            AND COALESCE(NULLIF(u.google_signin_email, ''), NULLIF(u.email, '')) IS NOT NULL
            AND ag.value = 'admin'
       ORDER BY u.fname ASC, u.lname ASC, u.username ASC"
    );

    while ($row = sqlFetchArray($res)) {
        $email = trim((string) ($row['admin_email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $nameParts = array_filter([
            trim((string) ($row['fname'] ?? '')),
            trim((string) ($row['lname'] ?? '')),
        ]);
        $displayName = trim(implode(' ', $nameParts));
        if ($displayName === '') {
            $displayName = trim((string) ($row['username'] ?? ''));
        }
        if ($displayName === '') {
            $displayName = $email;
        }

        $recipients[$email] = [
            'email' => $email,
            'label' => $displayName,
            'username' => trim((string) ($row['username'] ?? '')),
        ];
    }

    return array_values($recipients);
}

function getMedicalInventoryMailerFromEmail(): string
{
    $candidates = [
        trim((string) ($GLOBALS['practice_return_email_path'] ?? '')),
        trim((string) ($GLOBALS['patient_reminder_sender_email'] ?? '')),
        trim((string) ($GLOBALS['SMTP_USER'] ?? '')),
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            return $candidate;
        }
    }

    return '';
}

function getMedicalInventoryRoundedVariance(float $expectedQoh, float $countedQoh): float
{
    return round($countedQoh - $expectedQoh, 2);
}

function getMedicalInventoryCountStatus(float $expectedQoh, float $countedQoh): string
{
    $variance = getMedicalInventoryRoundedVariance($expectedQoh, $countedQoh);
    return abs($variance) < 0.0001 ? 'matched' : 'mismatch';
}

function fetchMedicalInventoryExpectedRows(int $facilityId = 0): array
{
    $binds = [];
    $facilityWhere = '';

    if ($facilityId > 0) {
        $facilityWhere = " AND COALESCE(lo.option_value, di.facility_id) = ? ";
        $binds[] = $facilityId;
    }

    $sql = "SELECT
                d.drug_id,
                d.name,
                c.category_name,
                COALESCE(SUM(di.on_hand), 0) AS expected_qoh
            FROM drugs AS d
      INNER JOIN drug_inventory AS di
              ON di.drug_id = d.drug_id
             AND di.destroy_date IS NULL
       LEFT JOIN list_options AS lo
              ON lo.list_id = 'warehouse'
             AND lo.option_id = di.warehouse_id
             AND lo.activity = 1
       LEFT JOIN categories AS c ON c.category_id = d.category_id
           WHERE LOWER(TRIM(COALESCE(c.category_name, ''))) IN ('medical', 'medication')
             AND d.active = 1" . $facilityWhere . "
        GROUP BY d.drug_id, d.name, c.category_name
        ORDER BY d.name ASC";

    $rows = [];
    $res = sqlStatement($sql, $binds);
    while ($row = sqlFetchArray($res)) {
        $rows[(int) $row['drug_id']] = [
            'drug_id' => (int) $row['drug_id'],
            'name' => (string) ($row['name'] ?? ''),
            'category_name' => (string) ($row['category_name'] ?? ''),
            'expected_qoh' => (float) ($row['expected_qoh'] ?? 0),
        ];
    }

    return $rows;
}

function fetchPendingMedicalInventoryCountMap(int $facilityId = 0): array
{
    ensureMedicalInventoryCountTable();

    $res = sqlStatement(
        "SELECT *
           FROM medical_inventory_counts
          WHERE facility_id = ?
            AND report_sent_at IS NULL",
        [$facilityId]
    );

    $map = [];
    while ($row = sqlFetchArray($res)) {
        $map[(int) $row['drug_id']] = [
            'id' => (int) $row['id'],
            'drug_id' => (int) $row['drug_id'],
            'expected_qoh' => (float) ($row['expected_qoh'] ?? 0),
            'counted_qoh' => (float) ($row['counted_qoh'] ?? 0),
            'variance_qoh' => (float) ($row['variance_qoh'] ?? 0),
            'status' => (string) ($row['status'] ?? 'matched'),
            'counted_at' => (string) ($row['counted_at'] ?? ''),
        ];
    }

    return $map;
}

function upsertPendingMedicalInventoryCount(int $facilityId, int $drugId, float $expectedQoh, float $countedQoh, int $userId = 0): array
{
    ensureMedicalInventoryCountTable();

    $variance = getMedicalInventoryRoundedVariance($expectedQoh, $countedQoh);
    $status = getMedicalInventoryCountStatus($expectedQoh, $countedQoh);

    $existing = sqlQuery(
        "SELECT id
           FROM medical_inventory_counts
          WHERE facility_id = ?
            AND drug_id = ?
            AND report_sent_at IS NULL
       ORDER BY id DESC
          LIMIT 1",
        [$facilityId, $drugId]
    );
    $existing = medicalInventoryFetchRow($existing);

    if (!empty($existing['id'])) {
        sqlStatement(
            "UPDATE medical_inventory_counts
                SET expected_qoh = ?,
                    counted_qoh = ?,
                    variance_qoh = ?,
                    status = ?,
                    counted_by = ?,
                    counted_at = NOW()
              WHERE id = ?",
            [$expectedQoh, $countedQoh, $variance, $status, $userId ?: null, (int) $existing['id']]
        );
        $recordId = (int) $existing['id'];
    } else {
        $recordId = (int) sqlInsert(
            "INSERT INTO medical_inventory_counts
                (facility_id, drug_id, expected_qoh, counted_qoh, variance_qoh, status, counted_by, counted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
            [$facilityId, $drugId, $expectedQoh, $countedQoh, $variance, $status, $userId ?: null]
        );
    }

    return [
        'id' => $recordId,
        'facility_id' => $facilityId,
        'drug_id' => $drugId,
        'expected_qoh' => $expectedQoh,
        'counted_qoh' => $countedQoh,
        'variance_qoh' => $variance,
        'status' => $status,
    ];
}

function buildMedicalInventoryCountSummary(int $facilityId = 0): array
{
    $expectedRows = fetchMedicalInventoryExpectedRows($facilityId);
    $countMap = fetchPendingMedicalInventoryCountMap($facilityId);

    $missing = [];
    foreach ($expectedRows as $drugId => $row) {
        if (!isset($countMap[$drugId])) {
            $missing[] = $row['name'];
        }
    }

    return [
        'facility_id' => $facilityId,
        'total_items' => count($expectedRows),
        'counted_items' => count($countMap),
        'missing_items' => $missing,
        'expected_rows' => $expectedRows,
        'count_map' => $countMap,
    ];
}

function markMedicalInventoryCountsReported(int $facilityId, int $userId = 0): void
{
    ensureMedicalInventoryCountTable();

    sqlStatement(
        "UPDATE medical_inventory_counts
            SET report_sent_at = NOW(),
                report_sent_by = ?
          WHERE facility_id = ?
            AND report_sent_at IS NULL",
        [$userId ?: null, $facilityId]
    );
}
