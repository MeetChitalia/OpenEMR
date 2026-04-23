<?php

/**
 * Facility patient deletion manager.
 *
 * @package OpenEMR
 */

require_once("../globals.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/options.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Core\Header;

if (!AclMain::aclCheckCore('admin', 'super')) {
    die(xlt('Not authorized'));
}

$selectedFacility = (string) ($_REQUEST['form_facility'] ?? '');
$hasFacilitySelection = array_key_exists('form_facility', $_REQUEST);
$flashMessage = '';
$flashError = '';

function facilityQueryRow(string $sql, array $binds = []): array
{
    $result = sqlStatement($sql, $binds);
    $row = sqlFetchArray($result);
    return is_array($row) ? $row : [];
}

function facilityDeleteRow(string $table, string $where): void
{
    $tres = sqlStatement("SELECT * FROM " . escape_table_name($table) . " WHERE $where");
    $count = 0;
    while ($trow = sqlFetchArray($tres)) {
        $logstring = "";
        foreach ($trow as $key => $value) {
            if (!$value || $value == '0000-00-00 00:00:00') {
                continue;
            }
            if ($logstring) {
                $logstring .= " ";
            }
            $logstring .= $key . "= '" . $value . "' ";
        }
        EventAuditLogger::instance()->newEvent("delete", $_SESSION['authUser'], $_SESSION['authProvider'], 1, "$table: $logstring");
        ++$count;
    }

    if ($count) {
        sqlStatement("DELETE FROM " . escape_table_name($table) . " WHERE $where");
    }
}

function facilityModifyRow(string $table, string $set, string $where): void
{
    if (sqlQuery("SELECT * FROM " . escape_table_name($table) . " WHERE $where")) {
        EventAuditLogger::instance()->newEvent("deactivate", $_SESSION['authUser'], $_SESSION['authProvider'], 1, "$table: $where");
        sqlStatement("UPDATE " . escape_table_name($table) . " SET $set WHERE $where");
    }
}

function facilityTableExists(string $table): bool
{
    return (bool) sqlFetchArray(sqlStatement("SHOW TABLES LIKE ?", [$table]));
}

function facilityGetNameById(int $facilityId): string
{
    if ($facilityId <= 0) {
        return '';
    }

    $row = facilityQueryRow("SELECT name FROM facility WHERE id = ?", [$facilityId]);
    return is_array($row) ? (string) ($row['name'] ?? '') : '';
}

function facilityGetCareTeamNames($value): string
{
    $raw = str_replace('|', ',', trim((string) $value));
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

function facilityHasCareTeamFacility($value, int $facilityId): bool
{
    if ($facilityId <= 0) {
        return false;
    }

    $normalized = str_replace('|', ',', trim((string) $value));
    $ids = array_values(array_filter(array_map('trim', explode(',', $normalized)), static function ($id) {
        return ctype_digit((string) $id) && (int) $id > 0;
    }));

    return in_array((string) $facilityId, $ids, true);
}

function facilityPatientMatchesSelection(array $patientRow, string $selectedFacility): bool
{
    if ($selectedFacility === '' || !ctype_digit($selectedFacility) || (int) $selectedFacility <= 0) {
        return true;
    }

    $facilityId = (int) $selectedFacility;
    if ((int) ($patientRow['facility_id'] ?? 0) === $facilityId) {
        return true;
    }

    return facilityHasCareTeamFacility($patientRow['care_team_facility'] ?? '', $facilityId);
}

function facilityDisplayName(array $patientRow): string
{
    $assignedName = trim((string) ($patientRow['assigned_facility_name'] ?? $patientRow['facility_name'] ?? ''));
    $careTeamName = trim((string) ($patientRow['care_team_facility_names'] ?? ''));
    $facilityId = (int) ($patientRow['facility_id'] ?? 0);

    if ($assignedName !== '' && $careTeamName !== '' && $careTeamName !== $assignedName) {
        return $assignedName . ' | ' . $careTeamName;
    }

    if ($assignedName !== '') {
        return $assignedName;
    }

    if ($careTeamName !== '') {
        return $careTeamName;
    }

    if ($facilityId > 0) {
        return xlt('Facility') . ' #' . $facilityId;
    }

    return '';
}

function facilityOptionalDeleteRow(string $table, string $where): void
{
    if (!facilityTableExists($table)) {
        return;
    }
    facilityDeleteRow($table, $where);
}

function facilityDeleteDrugSales(int $patientId): void
{
    sqlStatement(
        "UPDATE drug_sales AS ds, drug_inventory AS di " .
        "SET di.on_hand = di.on_hand + ds.quantity " .
        "WHERE ds.pid = ? AND ds.encounter != 0 AND di.inventory_id = ds.inventory_id",
        [$patientId]
    );
    facilityDeleteRow("drug_sales", "pid = '" . add_escape_custom($patientId) . "'");
}

function facilityDeleteForm(string $formdir, string $formid, string $patientId, string $encounterId): void
{
    $formdir = ($formdir == 'newpatient') ? 'encounter' : $formdir;
    $formdir = ($formdir == 'newGroupEncounter') ? 'groups_encounter' : $formdir;
    $isValidFormTableSuffix = static function ($value) {
        return is_string($value) && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $value);
    };

    if (substr($formdir, 0, 3) == 'LBF') {
        facilityDeleteRow("lbf_data", "form_id = '" . add_escape_custom($formid) . "'");
        $where = "pid = '" . add_escape_custom($patientId) . "' AND encounter = '" .
            add_escape_custom($encounterId) . "' AND field_id NOT IN (" .
            "SELECT lo.field_id FROM forms AS f, layout_options AS lo WHERE " .
            "f.pid = '" . add_escape_custom($patientId) . "' AND f.encounter = '" .
            add_escape_custom($encounterId) . "' AND f.formdir LIKE 'LBF%' AND " .
            "f.deleted = 0 AND f.form_id != '" . add_escape_custom($formid) . "' AND " .
            "lo.form_id = f.formdir AND lo.source = 'E' AND lo.uor > 0)";
        facilityDeleteRow("shared_attributes", $where);
    } elseif ($formdir == 'procedure_order') {
        $tres = sqlStatement("SELECT procedure_report_id FROM procedure_report WHERE procedure_order_id = ?", [$formid]);
        while ($trow = sqlFetchArray($tres)) {
            $reportid = (int) $trow['procedure_report_id'];
            facilityDeleteRow("procedure_result", "procedure_report_id = '" . add_escape_custom($reportid) . "'");
        }
        facilityDeleteRow("procedure_report", "procedure_order_id = '" . add_escape_custom($formid) . "'");
        facilityDeleteRow("procedure_order_code", "procedure_order_id = '" . add_escape_custom($formid) . "'");
        facilityDeleteRow("procedure_order", "procedure_order_id = '" . add_escape_custom($formid) . "'");
    } elseif ($formdir == 'physical_exam') {
        facilityDeleteRow("form_$formdir", "forms_id = '" . add_escape_custom($formid) . "'");
    } elseif ($formdir == 'eye_mag') {
        $tables = ['form_eye_base', 'form_eye_hpi', 'form_eye_ros', 'form_eye_vitals', 'form_eye_acuity', 'form_eye_refraction', 'form_eye_biometrics', 'form_eye_external', 'form_eye_antseg', 'form_eye_postseg', 'form_eye_neuro', 'form_eye_locking', 'form_eye_mag_orders'];
        foreach ($tables as $tableName) {
            facilityDeleteRow($tableName, "id = '" . add_escape_custom($formid) . "'");
        }
        facilityDeleteRow("form_eye_mag_impplan", "form_id = '" . add_escape_custom($formid) . "'");
        facilityDeleteRow("form_eye_mag_wearing", "FORM_ID = '" . add_escape_custom($formid) . "'");
    } elseif (!$isValidFormTableSuffix($formdir)) {
        EventAuditLogger::instance()->newEvent(
            "warning",
            $_SESSION['authUser'],
            $_SESSION['authProvider'],
            1,
            "Skipped patient cleanup for invalid formdir: " . (string) $formdir
        );
    } else {
        facilityDeleteRow("form_$formdir", "id = '" . add_escape_custom($formid) . "'");
    }
}

function facilityDeleteDocument(int $documentId): void
{
    sqlStatement("UPDATE `documents` SET `deleted` = 1 WHERE id = ?", [$documentId]);
    facilityDeleteRow("categories_to_documents", "document_id = '" . add_escape_custom($documentId) . "'");
    facilityDeleteRow("gprelations", "type1 = 1 AND id1 = '" . add_escape_custom($documentId) . "'");
}

function facilityDeletePatientChart(int $patientId): void
{
    facilityModifyRow("billing", "activity = 0", "pid = '" . add_escape_custom($patientId) . "'");
    facilityModifyRow("pnotes", "deleted = 1", "pid = '" . add_escape_custom($patientId) . "'");
    facilityDeleteRow("prescriptions", "patient_id = '" . add_escape_custom($patientId) . "'");
    facilityDeleteRow("claims", "patient_id = '" . add_escape_custom($patientId) . "'");
    facilityDeleteDrugSales($patientId);
    facilityDeleteRow("payments", "pid = '" . add_escape_custom($patientId) . "'");
    facilityModifyRow("ar_activity", "deleted = NOW()", "pid = '" . add_escape_custom($patientId) . "' AND deleted IS NULL");
    facilityDeleteRow("openemr_postcalendar_events", "pc_pid = '" . add_escape_custom($patientId) . "'");
    facilityDeleteRow("immunizations", "patient_id = '" . add_escape_custom($patientId) . "'");
    facilityDeleteRow("issue_encounter", "pid = '" . add_escape_custom($patientId) . "'");
    facilityDeleteRow("lists", "pid = '" . add_escape_custom($patientId) . "'");
    facilityDeleteRow("transactions", "pid = '" . add_escape_custom($patientId) . "'");
    facilityDeleteRow("employer_data", "pid = '" . add_escape_custom($patientId) . "'");
    facilityDeleteRow("history_data", "pid = '" . add_escape_custom($patientId) . "'");
    facilityDeleteRow("insurance_data", "pid = '" . add_escape_custom($patientId) . "'");
    facilityDeleteRow("patient_history", "pid = '" . add_escape_custom($patientId) . "'");

    facilityOptionalDeleteRow("marketplace_dispense_tracking", "pid = '" . add_escape_custom($patientId) . "'");
    facilityOptionalDeleteRow("pos_receipts", "pid = '" . add_escape_custom($patientId) . "'");
    facilityOptionalDeleteRow("pos_refunds", "pid = '" . add_escape_custom($patientId) . "'");
    facilityOptionalDeleteRow("patient_credit_transactions", "pid = '" . add_escape_custom($patientId) . "'");
    facilityOptionalDeleteRow("patient_credit_balance", "pid = '" . add_escape_custom($patientId) . "'");
    facilityOptionalDeleteRow("daily_administer_tracking", "pid = '" . add_escape_custom($patientId) . "'");
    facilityOptionalDeleteRow("pos_remaining_dispense", "pid = '" . add_escape_custom($patientId) . "'");
    facilityOptionalDeleteRow("pos_transactions", "pid = '" . add_escape_custom($patientId) . "'");
    facilityOptionalDeleteRow("patient_credit_transfers", "from_pid = '" . add_escape_custom($patientId) . "' OR to_pid = '" . add_escape_custom($patientId) . "'");
    facilityOptionalDeleteRow("pos_transfer_history", "source_pid = '" . add_escape_custom($patientId) . "' OR target_pid = '" . add_escape_custom($patientId) . "'");
    facilityOptionalDeleteRow("pos_transaction_void_audit", "pid = '" . add_escape_custom($patientId) . "'");

    $res = sqlStatement("SELECT * FROM forms WHERE pid = ?", [$patientId]);
    while ($row = sqlFetchArray($res)) {
        facilityDeleteForm((string) $row['formdir'], (string) $row['form_id'], (string) $row['pid'], (string) $row['encounter']);
    }
    facilityDeleteRow("forms", "pid = '" . add_escape_custom($patientId) . "'");

    $res = sqlStatement("SELECT id FROM documents WHERE foreign_id = ? AND deleted = 0", [$patientId]);
    while ($row = sqlFetchArray($res)) {
        facilityDeleteDocument((int) $row['id']);
    }

    facilityDeleteRow("patient_data", "pid = '" . add_escape_custom($patientId) . "'");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }

    $formAction = (string) ($_POST['form_action'] ?? '');
    $selectedFacility = (string) ($_POST['form_facility'] ?? $selectedFacility);

    if ($formAction === 'delete_patient') {
        $deletePidRaw = trim((string) ($_POST['delete_pid'] ?? ''));
        $deletePid = (int) $deletePidRaw;
        if ($deletePidRaw === '' || $deletePid <= 0) {
            $flashError = xlt('Please choose a valid patient.');
        } else {
            $patientRow = facilityQueryRow(
                "SELECT pid, pubpid, facility_id, care_team_facility, fname, lname
                 FROM patient_data
                 WHERE pid = ? OR pubpid = ?
                 LIMIT 1",
                [$deletePid, $deletePidRaw]
            );

            if (!is_array($patientRow)) {
                $flashError = xlt('Patient not found.');
            } elseif (!facilityPatientMatchesSelection($patientRow, $selectedFacility)) {
                $flashError = xlt('The selected patient does not belong to the chosen facility.');
            } else {
                $actualPid = (int) ($patientRow['pid'] ?? 0);
                $patientLabel = trim(((string) ($patientRow['lname'] ?? '')) . ', ' . ((string) ($patientRow['fname'] ?? '')));
                facilityDeletePatientChart($actualPid);
                $flashMessage = xlt('Patient deleted successfully.') . ' ' . text($patientLabel !== '' ? $patientLabel : (string) $deletePid);
            }
        }
    } elseif ($formAction === 'delete_selected') {
        $deletePids = array_values(array_filter(array_map(static function ($value) {
            return (int) $value;
        }, (array) ($_POST['delete_pids'] ?? [])), static function ($value) {
            return $value > 0;
        }));

        if (empty($deletePids)) {
            $deletePids = array_values(array_filter(array_map(static function ($value) {
                return (int) trim((string) $value);
            }, explode(',', (string) ($_POST['selected_delete_pids'] ?? ''))), static function ($value) {
                return $value > 0;
            }));
        }

        if (empty($deletePids)) {
            $flashError = xlt('Please select at least one patient to delete.');
        } else {
            $deletedCount = 0;
            foreach ($deletePids as $deletePid) {
                $patientRow = facilityQueryRow(
                    "SELECT pid, pubpid
                     FROM patient_data
                     WHERE pid = ? OR pubpid = ?
                     LIMIT 1",
                    [$deletePid, (string) $deletePid]
                );

                if (!is_array($patientRow)) {
                    continue;
                }

                $actualPid = (int) ($patientRow['pid'] ?? 0);
                if ($actualPid <= 0) {
                    continue;
                }

                facilityDeletePatientChart($actualPid);
                ++$deletedCount;
            }

            if ($deletedCount > 0) {
                $flashMessage = xlt('Deleted selected patients successfully.') . ' (' . text((string) $deletedCount) . ')';
            } else {
                $flashError = xlt('No patients were deleted.');
            }
        }
    }
}

$patients = [];
if ($hasFacilitySelection && $selectedFacility === '') {
    $patientStmt = sqlStatement(
        "SELECT pd.pid, pd.pubpid, pd.facility_id, pd.care_team_facility, pd.fname, pd.mname, pd.lname, pd.DOB, pd.email,
                pd.phone_home, pd.phone_cell, '' AS regdate, pd.street, pd.city, pd.state, pd.postal_code, f.name AS assigned_facility_name
         FROM patient_data AS pd
         LEFT JOIN facility AS f ON f.id = pd.facility_id
         ORDER BY pd.lname ASC, pd.fname ASC, pd.pid ASC"
    );
} elseif ($hasFacilitySelection && ctype_digit($selectedFacility) && (int) $selectedFacility > 0) {
    $patientStmt = sqlStatement(
        "SELECT pd.pid, pd.pubpid, pd.facility_id, pd.care_team_facility, pd.fname, pd.mname, pd.lname, pd.DOB, pd.email,
                pd.phone_home, pd.phone_cell, '' AS regdate, pd.street, pd.city, pd.state, pd.postal_code, f.name AS assigned_facility_name
         FROM patient_data AS pd
         LEFT JOIN facility AS f ON f.id = pd.facility_id
         WHERE pd.facility_id = ? OR FIND_IN_SET(?, REPLACE(REPLACE(COALESCE(pd.care_team_facility, ''), '|', ','), ' ', '')) > 0
         ORDER BY pd.lname ASC, pd.fname ASC, pd.pid ASC",
        [(int) $selectedFacility, (string) ((int) $selectedFacility)]
    );
}

if (!empty($patientStmt)) {
    while ($row = sqlFetchArray($patientStmt)) {
        if (is_array($row)) {
            $row['care_team_facility_names'] = facilityGetCareTeamNames($row['care_team_facility'] ?? '');
            $patients[] = $row;
        }
    }
}
?>
<!doctype html>
<html>
<head>
    <title><?php echo xlt('Facility Patient Delete'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <style>
        body { margin: 0; background: #f4f7fb; font-family: Arial, sans-serif; color: #223046; }
        .wrap { max-width: 1380px; margin: 24px auto; padding: 0 20px 32px; }
        .card { background: #fff; border: 1px solid #dfe7f2; border-radius: 18px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06); overflow: hidden; }
        .head { padding: 26px 28px 18px; border-bottom: 1px solid #edf2f8; }
        .title { margin: 0; font-size: 30px; }
        .subtitle { margin: 10px 0 0; color: #6c7c92; font-size: 14px; }
        .body { padding: 22px 28px 28px; }
        .toolbar { display: grid; grid-template-columns: minmax(260px, 360px) auto; gap: 14px; align-items: end; margin-bottom: 20px; }
        .field label { display: block; margin-bottom: 8px; font-weight: 700; }
        .field select { width: 100%; border: 1px solid #ced8e5; border-radius: 12px; padding: 10px 12px; font-size: 14px; background: #fff; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn { border: none; border-radius: 12px; padding: 10px 16px; font-weight: 700; cursor: pointer; }
        .btn-primary { background: #0b63ce; color: #fff; }
        .btn-secondary { background: #edf2f7; color: #243447; }
        .btn-danger { background: #fff1f0; color: #b42318; border: 1px solid #f4b5af; }
        .notice, .error { border-radius: 12px; padding: 12px 14px; margin-bottom: 16px; }
        .notice { background: #e8f7ee; border: 1px solid #b8e2c5; color: #1d5d35; }
        .error { background: #fff1f1; border: 1px solid #f3c2c2; color: #9b1c1c; }
        .meta { margin-bottom: 16px; color: #5c6d84; font-size: 14px; }
        .table-wrap { overflow-x: auto; border: 1px solid #e3eaf3; border-radius: 14px; }
        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #e9eef5; text-align: left; vertical-align: middle; white-space: nowrap; }
        th { background: #eef4fb; color: #243447; font-size: 13px; }
        tr:hover td { background: #f9fbfe; }
        .empty { padding: 28px 12px; text-align: center; color: #6c7c92; font-style: italic; }
        .danger-text { color: #b42318; font-weight: 700; }
        .delete-form { margin: 0; }
        @media (max-width: 900px) {
            .toolbar { grid-template-columns: 1fr; }
        }
    </style>
    <script>
        function confirmPatientDelete(name) {
            return window.confirm('Delete patient ' + name + ' and all related POS transactions? This cannot be undone.');
        }

        function submitSinglePatientDelete(pid, name) {
            if (!confirmPatientDelete(name)) {
                return false;
            }

            var form = document.getElementById('single-delete-form');
            if (!form) {
                return false;
            }

            var pidInput = document.getElementById('single-delete-pid');
            if (!pidInput) {
                return false;
            }

            pidInput.value = String(pid || '');
            form.submit();
            return false;
        }

        function toggleAllPatients(source) {
            var checkboxes = document.querySelectorAll('.patient-select-checkbox');
            checkboxes.forEach(function (checkbox) {
                checkbox.checked = !!source.checked;
            });
        }

        function confirmBulkDelete() {
            var selected = document.querySelectorAll('.patient-select-checkbox:checked');
            if (!selected.length) {
                window.alert('Select at least one patient to delete.');
                return false;
            }

            var csvInput = document.getElementById('selected-delete-pids');
            if (csvInput) {
                var selectedIds = [];
                selected.forEach(function (checkbox) {
                    if (checkbox.value) {
                        selectedIds.push(String(checkbox.value));
                    }
                });
                csvInput.value = selectedIds.join(',');
            }

            return window.confirm('Delete ' + selected.length + ' selected patients and all related POS transactions? This cannot be undone.');
        }

        function submitBulkDelete() {
            if (!confirmBulkDelete()) {
                return false;
            }

            var form = document.getElementById('bulk-delete-form');
            if (!form) {
                return false;
            }

            form.submit();
            return false;
        }
    </script>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="head">
            <h1 class="title"><?php echo xlt('Facility Patient Delete'); ?></h1>
            <p class="subtitle"><?php echo xlt('Select a facility, review its patients, and permanently delete a patient with related POS transaction cleanup.'); ?></p>
        </div>
        <div class="body">
            <?php if ($flashMessage !== '') { ?>
                <div class="notice"><?php echo text($flashMessage); ?></div>
            <?php } ?>
            <?php if ($flashError !== '') { ?>
                <div class="error"><?php echo text($flashError); ?></div>
            <?php } ?>

            <form method="get" action="facility_patient_delete.php">
                <div class="toolbar">
                    <div class="field">
                        <label for="form_facility"><?php echo xlt('Facility'); ?></label>
                        <?php dropdown_facility($selectedFacility, 'form_facility', false, true); ?>
                    </div>
                    <div class="actions">
                        <button class="btn btn-primary" type="submit"><?php echo xlt('Load Patients'); ?></button>
                        <a class="btn btn-secondary" href="facility_patient_delete.php"><?php echo xlt('Reset'); ?></a>
                    </div>
                </div>
            </form>

            <?php if (!$hasFacilitySelection) { ?>
                <div class="empty"><?php echo xlt('Choose a facility to view patients.'); ?></div>
            <?php } else { ?>
                <div class="meta">
                    <strong><?php echo text((string) count($patients)); ?></strong>
                    <?php echo $selectedFacility === '' ? xlt('patients found across all facilities.') : xlt('patients found in the selected facility.'); ?>
                    <span class="danger-text"><?php echo xlt('Deleting a patient here also removes related POS records.'); ?></span>
                </div>
                <form id="single-delete-form" method="post" action="facility_patient_delete.php" style="display:none;">
                    <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                    <input type="hidden" name="form_action" value="delete_patient" />
                    <input type="hidden" name="form_facility" value="<?php echo attr($selectedFacility); ?>" />
                    <input type="hidden" id="single-delete-pid" name="delete_pid" value="" />
                </form>
                <form id="bulk-delete-form" method="post" action="facility_patient_delete.php" style="display:none;">
                    <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                    <input type="hidden" name="form_action" value="delete_selected" />
                    <input type="hidden" name="form_facility" value="<?php echo attr($selectedFacility); ?>" />
                    <input type="hidden" id="selected-delete-pids" name="selected_delete_pids" value="" />
                </form>
                <div class="actions" style="margin-bottom: 12px;">
                    <button type="button" class="btn btn-danger" onclick="return submitBulkDelete()"><?php echo xlt('Delete Selected'); ?></button>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th><input type="checkbox" onclick="toggleAllPatients(this)" title="<?php echo attr(xla('Select all visible patients')); ?>" /></th>
                            <th><?php echo xlt('PID'); ?></th>
                            <th><?php echo xlt('ID'); ?></th>
                            <th><?php echo xlt('Name'); ?></th>
                            <th><?php echo xlt('Facility'); ?></th>
                            <th><?php echo xlt('DOB'); ?></th>
                            <th><?php echo xlt('Email'); ?></th>
                            <th><?php echo xlt('Phone'); ?></th>
                            <th><?php echo xlt('Registered'); ?></th>
                            <th><?php echo xlt('Address'); ?></th>
                            <th><?php echo xlt('Action'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($patients)) { ?>
                            <tr><td colspan="11" class="empty"><?php echo xlt('No patients found for this facility selection.'); ?></td></tr>
                        <?php } ?>
                        <?php foreach ($patients as $patient) { ?>
                            <?php
                            $patientName = trim(((string) ($patient['lname'] ?? '')) . ', ' . ((string) ($patient['fname'] ?? '')) . ' ' . ((string) ($patient['mname'] ?? '')));
                            $phone = trim((string) ($patient['phone_cell'] ?: $patient['phone_home']));
                            $facilityName = facilityDisplayName($patient);
                            $address = trim(
                                ((string) ($patient['street'] ?? '')) .
                                (((string) ($patient['city'] ?? '')) !== '' ? ', ' . (string) $patient['city'] : '') .
                                (((string) ($patient['state'] ?? '')) !== '' ? ', ' . (string) $patient['state'] : '') .
                                (((string) ($patient['postal_code'] ?? '')) !== '' ? ' ' . (string) $patient['postal_code'] : '')
                            );
                            ?>
                            <tr>
                                <td><input type="checkbox" class="patient-select-checkbox" name="delete_pids[]" value="<?php echo attr((string) ($patient['pid'] ?? '')); ?>" /></td>
                                <td><?php echo text((string) ($patient['pid'] ?? '')); ?></td>
                                <td><?php echo text((string) ($patient['pubpid'] ?? '')); ?></td>
                                <td><?php echo text($patientName); ?></td>
                                <td><?php echo text($facilityName !== '' ? $facilityName : xlt('Unspecified')); ?></td>
                                <td><?php echo text(oeFormatShortDate((string) ($patient['DOB'] ?? ''))); ?></td>
                                <td><?php echo text((string) ($patient['email'] ?? '')); ?></td>
                                <td><?php echo text($phone); ?></td>
                                <td><?php echo text(oeFormatShortDate((string) ($patient['regdate'] ?? ''))); ?></td>
                                <td><?php echo text($address); ?></td>
                                <td>
                                    <button
                                        type="button"
                                        class="btn btn-danger"
                                        onclick="return submitSinglePatientDelete(<?php echo attr_js((string) ($patient['pid'] ?? '')); ?>, <?php echo attr_js($patientName !== '' ? $patientName : (string) ($patient['pid'] ?? '')); ?>);"
                                    ><?php echo xlt('Delete'); ?></button>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } ?>
        </div>
    </div>
</div>
</body>
</html>
