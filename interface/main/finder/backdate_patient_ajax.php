<?php

require_once("../../globals.php");
require_once($GLOBALS['srcdir'] . "/sql.inc");
require_once($GLOBALS['srcdir'] . "/formatting.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

function backdateFinderFetchRow($result)
{
    if (is_object($result) && method_exists($result, 'FetchRow')) {
        return $result->FetchRow();
    }

    return is_array($result) ? $result : null;
}

function backdateFinderSelectedFacilityId(): int
{
    return isset($_SESSION['facilityId']) ? (int) $_SESSION['facilityId'] : 0;
}

function backdateFinderAllowedFacilities(int $userId): array
{
    $facilities = [];
    $facilityResult = sqlStatement(
        "SELECT DISTINCT facility_id FROM users_facility WHERE tablename = 'users' AND table_id = ?",
        [$userId]
    );

    while ($facilityRow = sqlFetchArray($facilityResult)) {
        if (!empty($facilityRow['facility_id'])) {
            $facilities[] = (int) $facilityRow['facility_id'];
        }
    }

    if (empty($facilities)) {
        $userRow = sqlQuery("SELECT facility_id FROM users WHERE id = ?", [$userId]);
        $userRow = backdateFinderFetchRow($userRow);
        if ($userRow && !empty($userRow['facility_id'])) {
            $facilities[] = (int) $userRow['facility_id'];
        }
    }

    return $facilities;
}

header('Content-Type: application/json; charset=utf-8');

if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    CsrfUtils::csrfNotVerified();
}

if (!AclMain::aclCheckCore('patients', 'demo', '', ['read'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$search = trim((string) ($_POST['q'] ?? ''));
$page = max(1, (int) ($_POST['page'] ?? 1));
$pageSize = (int) ($_POST['page_size'] ?? 10);
if ($pageSize < 1 || $pageSize > 100) {
    $pageSize = 10;
}

$offset = ($page - 1) * $pageSize;
$where = " WHERE 1=1 ";
$params = [];
$selectedFacilityId = backdateFinderSelectedFacilityId();

if ($selectedFacilityId > 0) {
    $where .= " AND (pd.facility_id = ? OR FIND_IN_SET(?, REPLACE(COALESCE(pd.care_team_facility, ''), '|', ',')) > 0)";
    $params[] = $selectedFacilityId;
    $params[] = $selectedFacilityId;
} elseif (!empty($GLOBALS['restrict_user_facility'])) {
    $userFacilities = backdateFinderAllowedFacilities((int) ($_SESSION['authUserID'] ?? 0));
    if (!empty($userFacilities)) {
        $placeholders = implode(',', array_fill(0, count($userFacilities), '?'));
        $where .= " AND pd.facility_id IN ($placeholders)";
        $params = array_merge($params, $userFacilities);
    }
}

if ($search !== '') {
    $where .= " AND (
        pd.fname LIKE ? OR
        pd.mname LIKE ? OR
        pd.lname LIKE ? OR
        CONCAT(COALESCE(pd.lname, ''), ', ', COALESCE(pd.fname, ''), ' ', COALESCE(pd.mname, '')) LIKE ? OR
        pd.phone_cell LIKE ? OR
        pd.phone_home LIKE ? OR
        pd.pubpid LIKE ? OR
        CAST(pd.pid AS CHAR) LIKE ? OR
        DATE_FORMAT(pd.DOB, '%m/%d/%Y') LIKE ? OR
        pd.DOB LIKE ?
    )";
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like, $like, $like, $like]);
}

$countRow = sqlQuery(
    "SELECT COUNT(*) AS total
     FROM patient_data AS pd
     $where",
    $params
);
$countRow = backdateFinderFetchRow($countRow);
$total = (int) ($countRow['total'] ?? 0);

$rows = sqlStatement(
    "SELECT pd.pid, pd.pubpid, pd.fname, pd.mname, pd.lname, pd.phone_cell, pd.phone_home, pd.DOB
     FROM patient_data AS pd
     $where
     ORDER BY pd.lname ASC, pd.fname ASC, pd.pid ASC
     LIMIT ? OFFSET ?",
    array_merge($params, [$pageSize, $offset])
);

$data = [];
while ($row = sqlFetchArray($rows)) {
    $name = trim(($row['lname'] ?? '') . ((empty($row['fname'])) ? '' : ', ' . $row['fname']) . ((empty($row['mname'])) ? '' : ' ' . $row['mname']));
    $phone = !empty($row['phone_cell']) ? $row['phone_cell'] : ($row['phone_home'] ?? '');
    $data[] = [
        'pid' => (int) ($row['pid'] ?? 0),
        'name' => $name,
        'phone' => $phone,
        'dob' => !empty($row['DOB']) ? oeFormatShortDate($row['DOB']) : '',
        'external_id' => (string) ($row['pubpid'] ?? ''),
    ];
}

echo json_encode([
    'success' => true,
    'rows' => $data,
    'page' => $page,
    'page_size' => $pageSize,
    'total' => $total,
], JSON_INVALID_UTF8_SUBSTITUTE);
exit;
