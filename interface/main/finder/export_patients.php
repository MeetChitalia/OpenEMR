<?php

/**
 * Simple patient finder CSV export.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$ignoreAuth = false;
require_once(dirname(__FILE__) . "/../../globals.php");
require_once("$srcdir/patient.inc");

use OpenEMR\Common\Acl\AclMain;

function exportPatientsGetSelectedFacilityId(): int
{
    return isset($_SESSION['facilityId']) ? (int) $_SESSION['facilityId'] : 0;
}

function exportPatientsCareTeamFacilityNames($value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    $ids = array_values(array_filter(array_map('trim', explode(',', $raw)), static function ($id) {
        return ctype_digit((string) $id) && (int) $id > 0;
    }));
    if (empty($ids)) {
        return '';
    }

    $result = sqlStatement(
        "SELECT name
         FROM facility
         WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
         ORDER BY name ASC",
        array_map('intval', $ids)
    );

    $names = [];
    while ($row = sqlFetchArray($result)) {
        if (is_array($row) && !empty($row['name'])) {
            $names[] = (string) $row['name'];
        }
    }

    return implode(' | ', $names);
}

if (!AclMain::aclCheckCore('patients', 'demo', '', ['write', 'addonly'])) {
    die(xlt('Not authorized'));
}

header("Pragma: public");
header("Expires: 0");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
header("Content-Type: text/csv");
header("Content-Disposition: attachment; filename=patients_export.csv");
header("Content-Description: File Transfer");

$output = fopen('php://output', 'w');
fputcsv($output, [
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
]);

$where = [];
$binds = [];
$selectedFacilityId = exportPatientsGetSelectedFacilityId();

if ($selectedFacilityId > 0) {
    $where[] = 'facility_id = ?';
    $binds[] = $selectedFacilityId;
} elseif (!empty($GLOBALS['restrict_user_facility'])) {
    $userFacilities = [];
    $facilityResult = sqlStatement(
        "SELECT DISTINCT facility_id FROM users_facility WHERE tablename = 'users' AND table_id = ?",
        [$_SESSION['authUserID']]
    );

    while ($facilityRow = sqlFetchArray($facilityResult)) {
        if (!empty($facilityRow['facility_id'])) {
            $userFacilities[] = $facilityRow['facility_id'];
        }
    }

    if (empty($userFacilities)) {
        $userRow = sqlQuery("SELECT facility_id FROM users WHERE id = ?", [$_SESSION['authUserID']]);
        if (is_array($userRow) && !empty($userRow['facility_id'])) {
            $userFacilities[] = $userRow['facility_id'];
        }
    }

    if (!empty($userFacilities)) {
        $where[] = "facility_id IN (" . implode(',', array_fill(0, count($userFacilities), '?')) . ")";
        $binds = array_merge($binds, $userFacilities);
    }
}

$result = sqlStatement(
    "SELECT pubpid, fname, mname, lname, DATE_FORMAT(DOB, '%Y-%m-%d') AS dob_export, sex,
            phone_home, phone_cell, email, street, city, state, postal_code, country_code, care_team_facility
     FROM patient_data" . (!empty($where) ? " WHERE " . implode(' AND ', $where) : '') . "
     ORDER BY lname, fname, pid",
    $binds
);

while ($row = sqlFetchArray($result)) {
    fputcsv($output, [
        $row['pubpid'] ?? '',
        $row['fname'] ?? '',
        $row['mname'] ?? '',
        $row['lname'] ?? '',
        $row['dob_export'] ?? '',
        $row['sex'] ?? '',
        $row['phone_home'] ?? '',
        $row['phone_cell'] ?? '',
        $row['email'] ?? '',
        $row['street'] ?? '',
        $row['city'] ?? '',
        $row['state'] ?? '',
        $row['postal_code'] ?? '',
        $row['country_code'] ?? '',
        exportPatientsCareTeamFacilityNames($row['care_team_facility'] ?? ''),
    ]);
}

fclose($output);
exit;
