<?php
require_once("../../globals.php");
require_once($GLOBALS['srcdir'] . "/sql.inc");

use OpenEMR\Common\Acl\AclMain;

function existingFinderAjaxFetchRow($result)
{
    if (is_object($result) && method_exists($result, 'FetchRow')) {
        return $result->FetchRow();
    }

    return is_array($result) ? $result : null;
}

function existingFinderAjaxOrderedColumns(): array
{
    $columns = [];
    $res = sqlStatement("SELECT option_id, title FROM list_options WHERE list_id = 'ptlistcols' AND activity = 1 ORDER BY seq, title");
    while ($row = sqlFetchArray($res)) {
        $colname = $row['option_id'] ?? '';
        $title = xl_list_label($row['title'] ?? '');
        $title1 = ($title == xl('Full Name')) ? xl('Name') : $title;

        if ($colname === 'ss') {
            continue;
        }

        if ($colname === 'phone_home') {
            $colname = 'phone_cell';
        }

        $columns[] = [
            'name' => $colname,
            'title' => $title1,
        ];
    }

    return $columns;
}

function existingFinderAjaxGetSelectedFacilityId(): int
{
    return isset($_SESSION['facilityId']) ? (int) $_SESSION['facilityId'] : 0;
}

function existingFinderAjaxGetAllowedFacilities(int $userId): array
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
        $userRow = existingFinderAjaxFetchRow($userRow);
        if ($userRow && !empty($userRow['facility_id'])) {
            $facilities[] = (int) $userRow['facility_id'];
        }
    }

    return $facilities;
}

function existingFinderAjaxNormalizeDobSearch(string $search): string
{
    $search = trim($search);

    if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $search)) {
        [$month, $day, $year] = explode('/', $search);
        return sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
    }

    if (preg_match('/^\d{8}$/', $search)) {
        return substr($search, 4, 4) . '-' . substr($search, 0, 2) . '-' . substr($search, 2, 2);
    }

    return $search;
}

function existingFinderAjaxBuildSearchClause(string $search): array
{
    $search = trim($search);
    if ($search === '') {
        return ['sql' => '', 'params' => []];
    }

    if (ctype_digit($search)) {
        $prefix = $search . '%';
        $clauses = [
            "pd.pid = ?",
            "pd.phone_cell LIKE ?",
            "pd.pubpid LIKE ?",
        ];
        $params = [(int) $search, $prefix, $prefix];

        $normalizedDobSearch = existingFinderAjaxNormalizeDobSearch($search);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalizedDobSearch)) {
            $clauses[] = "pd.DOB = ?";
            $params[] = $normalizedDobSearch;
        }

        return [
            'sql' => ' AND (' . implode(' OR ', $clauses) . ')',
            'params' => $params,
        ];
    }

    $prefix = $search . '%';
    return [
        'sql' => " AND (pd.lname LIKE ? OR pd.fname LIKE ? OR CONCAT(pd.lname, ', ', pd.fname) LIKE ?)",
        'params' => [$prefix, $prefix, $prefix],
    ];
}

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(0);

// ACL check (read access is sufficient to load the patient list)
if (!AclMain::aclCheckCore('patients', 'demo', '', array('read'))) {
    http_response_code(403);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$draw   = (int)($_POST['draw'] ?? 0);
$start  = max(0, (int)($_POST['start'] ?? 0));
$length = (int)($_POST['length'] ?? 25);
if ($length < 1 || $length > 200) $length = 25;

$search = trim($_POST['search']['value'] ?? '');

$orderCol = (int)($_POST['order'][0]['column'] ?? 0);
$orderDir = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$colMap = [
    0 => 'pd.pid',
    1 => 'pd.lname',
    2 => 'pd.DOB',
    3 => 'pd.phone_cell'
];
$orderBy = $colMap[$orderCol] ?? 'pd.pid';

$baseWhere = " WHERE 1=1 ";
$baseParams = [];
$selectedFacilityId = existingFinderAjaxGetSelectedFacilityId();

if ($selectedFacilityId > 0) {
    $baseWhere .= " AND (pd.facility_id = ? OR FIND_IN_SET(?, REPLACE(COALESCE(pd.care_team_facility, ''), '|', ',')) > 0)";
    $baseParams[] = $selectedFacilityId;
    $baseParams[] = $selectedFacilityId;
} elseif (!empty($GLOBALS['restrict_user_facility'])) {
    $userFacilities = existingFinderAjaxGetAllowedFacilities((int) ($_SESSION['authUserID'] ?? 0));
    if (!empty($userFacilities)) {
        $placeholders = implode(',', array_fill(0, count($userFacilities), '?'));
        $baseWhere .= " AND pd.facility_id IN ($placeholders)";
        $baseParams = array_merge($baseParams, $userFacilities);
    }
}

$where = $baseWhere;
$params = $baseParams;

if ($search !== '') {
    $searchClause = existingFinderAjaxBuildSearchClause($search);
    $where .= $searchClause['sql'];
    $params = array_merge($params, $searchClause['params']);
}

// totals
$totalRow = sqlQuery("SELECT COUNT(*) AS cnt FROM patient_data pd $baseWhere", $baseParams);
$totalRow = existingFinderAjaxFetchRow($totalRow);
$recordsTotal = (int)($totalRow['cnt'] ?? 0);

$filteredRow = sqlQuery("SELECT COUNT(*) AS cnt FROM patient_data pd $where", $params);
$filteredRow = existingFinderAjaxFetchRow($filteredRow);
$recordsFiltered = (int)($filteredRow['cnt'] ?? 0);

// rows
$sql = "
    SELECT pd.*
    FROM patient_data pd
    $where
    ORDER BY $orderBy $orderDir
    LIMIT ? OFFSET ?
";
$params2 = array_merge($params, [$length, $start]);

$res = sqlStatement($sql, $params2);

$data = [];
$orderedColumns = existingFinderAjaxOrderedColumns();
while ($row = sqlFetchArray($res)) {
    $formattedRow = [];

    foreach ($orderedColumns as $column) {
        if (($column['title'] ?? '') === xl('Name')) {
            $formattedRow[] = trim(($row['lname'] ?? '') . ((empty($row['fname'])) ? '' : ', ' . $row['fname']) . ((empty($row['mname'])) ? '' : ' ' . $row['mname']));
            continue;
        }

        $columnName = $column['name'] ?? '';
        $value = $row[$columnName] ?? '';

        if ($columnName === 'DOB' && !empty($value)) {
            $value = oeFormatShortDate($value);
        }

        $formattedRow[] = $value;
    }

    $formattedRow[] = ((int) ($row['pid'] ?? 0)) . '|0';
    $formattedRow['DT_RowId'] = 'pid_' . ((int) ($row['pid'] ?? 0));
    $data[] = $formattedRow;
}

echo json_encode([
    'draw' => $draw,
    'recordsTotal' => $recordsTotal,
    'recordsFiltered' => $recordsFiltered,
    'data' => $data
], JSON_INVALID_UTF8_SUBSTITUTE);
exit;
