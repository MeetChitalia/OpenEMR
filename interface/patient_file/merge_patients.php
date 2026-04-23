<?php

/**
 * This script merges two patient charts into a single patient chart.
 * It is to correct the error of creating a duplicate patient.
 *
 * @category  Patient_Data
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2013-2021 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

set_time_limit(0);

require_once("../globals.php");
require_once("$srcdir/patient.inc");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\Common\Logging\EventAuditLogger;

$form_pid1 = empty($_GET['pid1']) ? 0 : intval($_GET['pid1']);
$form_pid2 = empty($_GET['pid2']) ? 0 : intval($_GET['pid2']);

// Set this to true for production use.
// If false you will get a "dry run" with no updates.
$PRODUCTION = true;

if (!AclMain::aclCheckCore('admin', 'super')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Merge Patients")]);
    exit;
}
?>
<!DOCTYPE html>
<html>

<head>
    <title><?php echo xlt('Merge Patients'); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
        .merge-body {
            margin: 0;
            background: #f5f7fb;
            color: #243447;
        }

        .merge-shell {
            max-width: 1180px;
            margin: 0 auto;
            padding: 24px 16px 40px;
        }

        .merge-hero,
        .merge-card,
        .merge-log {
            background: #ffffff;
            border: 1px solid #d9e2ec;
            border-radius: 14px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
        }

        .merge-hero {
            padding: 24px;
            margin-bottom: 20px;
        }

        .merge-eyebrow {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 999px;
            background: #e8f1fb;
            color: #24507a;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin-bottom: 12px;
        }

        .merge-title {
            margin: 0 0 8px;
            font-size: 30px;
            font-weight: 700;
            color: #17324d;
        }

        .merge-subtitle {
            margin: 0;
            max-width: 760px;
            color: #5b7083;
            line-height: 1.6;
        }

        .merge-grid {
            display: grid;
            grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr);
            gap: 20px;
            align-items: start;
        }

        .merge-card {
            padding: 0;
            overflow: hidden;
        }

        .merge-card-header {
            padding: 18px 22px;
            border-bottom: 1px solid #e6edf5;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        }

        .merge-card-title {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            color: #17324d;
        }

        .merge-card-body {
            padding: 20px 22px 22px;
        }

        .merge-summary {
            display: grid;
            gap: 14px;
        }

        .merge-patient-box {
            border: 1px solid #d9e2ec;
            border-radius: 12px;
            padding: 16px;
            background: #fbfdff;
        }

        .merge-patient-label {
            display: block;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #5a6f82;
        }

        .merge-patient-help {
            margin: 8px 0 0;
            color: #607487;
            font-size: 13px;
            line-height: 1.5;
        }

        .merge-arrow {
            display: flex;
            justify-content: center;
            align-items: center;
            color: #6a8094;
            font-size: 24px;
            font-weight: 700;
        }

        .merge-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 18px;
        }

        .merge-actions .btn {
            min-width: 220px;
        }

        .merge-side-list {
            margin: 0;
            padding-left: 18px;
            color: #54687a;
        }

        .merge-side-list li + li {
            margin-top: 10px;
        }

        .merge-alert {
            margin-top: 16px;
            border-radius: 12px;
        }

        .merge-log {
            margin-bottom: 18px;
            padding: 18px 22px;
            line-height: 1.65;
        }

        @media (max-width: 991px) {
            .merge-grid {
                grid-template-columns: 1fr;
            }

            .merge-actions .btn {
                width: 100%;
                min-width: 0;
            }
        }
    </style>

    <script>

        var mypcc = <?php echo js_escape($GLOBALS['phone_country_code']); ?>;

        var el_pt_name;
        var el_pt_id;

        // This is for callback by the find-patient popup.
        function setpatient(pid, lname, fname, dob) {
            el_pt_name.value = lname + ', ' + fname + ' (' + pid + ')';
            el_pt_id.value = pid;
        }

        // This invokes the find-patient popup.
        function sel_patient(ename, epid) {
            el_pt_name = ename;
            el_pt_id = epid;
            dlgopen('../main/calendar/find_patient_popup.php', '_blank', 600, 400);
        }

    </script>

</head>

<body class="body_top merge-body">
    <div class="merge-shell">
        <div class="merge-hero">
            <div class="merge-eyebrow"><?php echo xlt('Patient Cleanup'); ?></div>
            <h1 class="merge-title"><?php echo xlt('Merge Patients'); ?></h1>
            <p class="merge-subtitle"><?php echo xlt('Combine a duplicate chart into the main chart while keeping the interface consistent with the rest of the application. The source chart is merged into the target chart and then removed.'); ?></p>
        </div>

        <?php

        /**
         * Deletes rows from the given table that are no longer needed due to the merge.
         *
         * @param [type] $tblname    the name of the table to operate on.
         * @param [type] $colname    the column used for the query.
         * @param [type] $source_pid the source patient id.
         * @param [type] $target_pid the target patient id.
         *
         * @return void
         */
        function deleteRows($tblname, $colname, $source_pid, $target_pid)
        {
            global $PRODUCTION;
            $crow = sqlQuery(
                "SELECT COUNT(*) AS count FROM " . escape_table_name($tblname)
                . " WHERE " . escape_sql_column_name($colname, array($tblname)) . " = ?",
                array($source_pid)
            );
            $count = $crow['count'];
            if ($count) {
                $sql = "DELETE FROM " . escape_table_name($tblname) . " WHERE "
                    . escape_sql_column_name($colname, array($tblname)) . " = ?";
                echo "<br />$sql ($count)";
                if ($PRODUCTION) {
                    sqlStatement($sql, array($source_pid));
                    logMergeEvent(
                        $target_pid,
                        "delete",
                        "Deleted rows with " . $colname . " = " . $source_pid . " in table "
                        . $tblname
                    );
                }
            }
        }

        /**
         * Updates rows in the given table, where the given column's value
         * is the source_pid to the given target_pid value.
         *
         * @param [type] $tblname    the name of the table to operate on.
         * @param [type] $colname    the column used for the query.
         * @param [type] $source_pid the source patient id.
         * @param [type] $target_pid the target patient id.
         *
         * @return voidd
         */
        function updateRows($tblname, $colname, $source_pid, $target_pid)
        {
            global $PRODUCTION;
            $crow = sqlQuery(
                "SELECT COUNT(*) AS count FROM " . escape_table_name($tblname)
                . " WHERE " . escape_sql_column_name($colname, array($tblname)) . " = ?",
                array($source_pid)
            );
            $count = $crow['count'];
            if ($count) {
                $sql = "UPDATE " . escape_table_name($tblname) . " SET " .
                    escape_sql_column_name($colname, array($tblname)) . " = ? WHERE " .
                    escape_sql_column_name($colname, array($tblname)) . " = ?";
                echo "<br />$sql ($count)";
                if ($PRODUCTION) {
                    sqlStatement($sql, array($target_pid, $source_pid));
                    logMergeEvent(
                        $target_pid,
                        "update",
                        "Updated rows with " . $colname . " = " . $source_pid . " to " .
                        $target_pid . " in table " . $tblname
                    );
                }
            }
        }

        /**
         * Merge rows by changing the given column of the given table
         * from source_pid to target_pid.
         *
         * @param [type] $tblname    the table to operate on.
         * @param [type] $colname    the column name of the data to change.
         * @param [type] $source_pid the data to be changed from.
         * @param [type] $target_pid the data to be changed to.
         *
         * @return void
         */
        function mergeRows($tblname, $colname, $source_pid, $target_pid)
        {
            global $PRODUCTION;
            $crow = sqlQuery(
                "SELECT COUNT(*) AS count FROM " . escape_table_name($tblname) .
                " WHERE " . escape_sql_column_name($colname, array($tblname)) . " = ?",
                array($source_pid)
            );
            $count = $crow['count'];
            if ($count) {
                echo "<br />lists_touch count is ($count)";
                $source_sel = "SELECT * FROM " . escape_table_name($tblname) .
                    " WHERE `pid` = ?";
                $source_res = sqlStatement($source_sel, array($source_pid));

                while ($source_row = sqlFetchArray($source_res)) {
                    $target_row = sqlQuery(
                        "SELECT * FROM " . escape_table_name($tblname) . " WHERE `pid` = ? AND `type` = ?",
                        array($target_pid, $source_row['type'])
                    );

                    if (empty($target_row)) {
                        continue;
                    }

                    if (strcmp($source_row['date'], $target_row['date']) < 0) {
                        // We delete the entry from the target since the source has
                        // an older date, then update source to target.
                        $sql1 = "DELETE FROM " . escape_table_name($tblname) .
                            " WHERE " . escape_sql_column_name($colname, array($tblname))
                            . " = ? AND `type` = ?";
                        $sql2 = "UPDATE " . escape_table_name($tblname) . " SET " .
                            escape_sql_column_name($colname, array($tblname)) .
                            " = ? WHERE " . escape_sql_column_name(
                                $colname,
                                array($tblname)
                            ) . " = ?  AND `type` = ?";
                        echo "<br />$sql1";
                        echo "<br />$sql2";
                        if ($PRODUCTION) {
                            sqlStatement(
                                $sql1,
                                array($target_pid, $source_row['type'])
                            );
                            sqlStatement(
                                $sql2,
                                array($target_pid, $source_pid,
                                    $source_row['type'])
                            );
                            logMergeEvent(
                                $target_pid,
                                "delete",
                                "Deleted rows with " . $colname . " = " . $target_pid
                                . " and type = " . $source_row['type'] .
                                " in table " . $tblname
                            );
                            logMergeEvent(
                                $target_pid,
                                "update",
                                "Updated rows with " . $colname . " = " . $source_pid
                                . " to " . $target_pid .
                                " in table " . $tblname
                            );
                        }
                    } else {
                        // We just delete the entry from the source.
                        $sql = "DELETE FROM " . escape_table_name($tblname) .
                            " WHERE " . escape_sql_column_name($colname, array($tblname))
                            . " = ? AND `type` = ?";
                        echo "<br />$sql";
                        if ($PRODUCTION) {
                            sqlStatement(
                                $sql,
                                array($source_pid, $source_row['type'])
                            );
                            logMergeEvent(
                                $target_pid,
                                "delete",
                                "Deleted rows with " . $colname . " = " . $source_pid
                                . " and type = " . $source_row['type'] .
                                " in table " . $tblname
                            );
                        }
                    }
                }
                // if there was no target but a source then check count again
                // ^^ should read : Check count again for the case of no target_rows.
                $crow = sqlQuery(
                    "SELECT COUNT(*) AS count FROM " .
                    escape_table_name($tblname) . " WHERE " . escape_sql_column_name(
                        $colname,
                        array($tblname)
                    ) . " = ?",
                    array($source_pid)
                );
                $count = $crow['count'];
                if ($count) {
                    $sql = "UPDATE " . escape_table_name($tblname) . " SET " .
                        escape_sql_column_name(
                            $colname,
                            array($tblname)
                        ) . " = ? WHERE " . escape_sql_column_name(
                            $colname,
                            array($tblname)
                        ) . " = ?";
                    echo "<br />$sql ($count)";
                    if ($PRODUCTION) {
                        sqlStatement($sql, array($target_pid, $source_pid));
                        logMergeEvent(
                            $target_pid,
                            "update",
                            "Updated rows with " . $colname . " = " . $source_pid
                            . " and type = " . $source_row['type'] .
                            " to " . $target_pid . " in table " . $tblname
                        );
                    }
                }
            }
        }

        /**
         * Add a line into the audit log for a merge event.
         *
         * @param [type] $target_pid  the target patient id.
         * @param [type] $event_type  the type of db change (update,delete,etc.)
         * @param [type] $log_message the message to log
         *
         * @return void
         */
        function logMergeEvent($target_pid, $event_type, $log_message)
        {
            EventAuditLogger::instance()->newEvent(
                "patient-merge-" . $event_type,
                $_SESSION['authUser'],
                $_SESSION['authProvider'],
                1,
                $log_message,
                $target_pid
            );
        }

        function mergePatientCreditBalance($source_pid, $target_pid)
        {
            global $PRODUCTION;

            $source = sqlQuery("SELECT * FROM patient_credit_balance WHERE pid = ?", [$source_pid]);
            if (empty($source)) {
                return;
            }

            $target = sqlQuery("SELECT * FROM patient_credit_balance WHERE pid = ?", [$target_pid]);
            if (!empty($target)) {
                $mergedBalance = (float) ($target['balance'] ?? 0) + (float) ($source['balance'] ?? 0);
                echo "<br />Merging patient_credit_balance from " . text($source_pid) . " into " . text($target_pid) .
                    " (new balance: " . text($mergedBalance) . ")";
                if ($PRODUCTION) {
                    sqlStatement(
                        "UPDATE patient_credit_balance SET balance = ?, updated_date = NOW() WHERE pid = ?",
                        [$mergedBalance, $target_pid]
                    );
                    sqlStatement("DELETE FROM patient_credit_balance WHERE pid = ?", [$source_pid]);
                    logMergeEvent($target_pid, "update", "Merged patient_credit_balance from $source_pid into $target_pid");
                }
            } else {
                echo "<br />Updating patient_credit_balance pid from " . text($source_pid) . " to " . text($target_pid);
                if ($PRODUCTION) {
                    sqlStatement("UPDATE patient_credit_balance SET pid = ?, updated_date = NOW() WHERE pid = ?", [$target_pid, $source_pid]);
                    logMergeEvent($target_pid, "update", "Updated patient_credit_balance pid from $source_pid to $target_pid");
                }
            }
        }

        function mergeDailyAdministerTracking($source_pid, $target_pid)
        {
            global $PRODUCTION;

            $res = sqlStatement("SELECT * FROM daily_administer_tracking WHERE pid = ?", [$source_pid]);
            while ($row = sqlFetchArray($res)) {
                $existing = sqlQuery(
                    "SELECT * FROM daily_administer_tracking WHERE pid = ? AND drug_id = ? AND administer_date = ?",
                    [$target_pid, $row['drug_id'], $row['administer_date']]
                );

                if (!empty($existing)) {
                    $mergedTotal = (float) ($existing['total_administered'] ?? 0) + (float) ($row['total_administered'] ?? 0);
                    echo "<br />Merging daily_administer_tracking id " . text($row['id']) .
                        " into existing target row for pid " . text($target_pid);
                    if ($PRODUCTION) {
                        sqlStatement(
                            "UPDATE daily_administer_tracking SET total_administered = ?, updated_date = NOW() WHERE id = ?",
                            [$mergedTotal, $existing['id']]
                        );
                        sqlStatement("DELETE FROM daily_administer_tracking WHERE id = ?", [$row['id']]);
                        logMergeEvent(
                            $target_pid,
                            "update",
                            "Merged daily_administer_tracking source row {$row['id']} into target row {$existing['id']}"
                        );
                    }
                } else {
                    echo "<br />Updating daily_administer_tracking pid from " . text($source_pid) . " to " . text($target_pid) .
                        " for row " . text($row['id']);
                    if ($PRODUCTION) {
                        sqlStatement("UPDATE daily_administer_tracking SET pid = ? WHERE id = ?", [$target_pid, $row['id']]);
                        logMergeEvent($target_pid, "update", "Updated daily_administer_tracking row {$row['id']} from $source_pid to $target_pid");
                    }
                }
            }
        }

        function getPatientReferenceColumns(string $tableName): array
        {
            $columns = [];
            $excludedColumns = ['from_pid', 'to_pid', 'source_pid', 'target_pid', 'ppid'];
            $result = sqlStatement("SHOW COLUMNS FROM `" . escape_table_name($tableName) . "`");
            while ($row = sqlFetchArray($result)) {
                $field = (string) ($row['Field'] ?? '');
                if ($field === '') {
                    continue;
                }

                if (in_array(strtolower($field), $excludedColumns, true)) {
                    continue;
                }

                if (preg_match('/^(pid|patient_id|.+_pid)$/i', $field)) {
                    $columns[] = $field;
                }
            }

            return array_values(array_unique($columns));
        }

        function resolveTargets($target_pid, $source_pid): array
        {
            // look for an anonymous encounter in a pid target/source set
            // this is where we source components to merge with target encounter.
            $sql = "SELECT e1.date, e1.encounter, e1.reason, e1.encounter_type_code, e1.pid
                    FROM `form_encounter` e1 WHERE e1.pid IN(?,?) AND e1.reason IS NULL AND e1.encounter_type_code IS NULL LIMIT 1";
            $source = sqlQuery($sql, array($target_pid, $source_pid));
            // will we need to deduplicate?
            if (empty($source)) {
                return [];
            }

            $test = ((int)$source['pid'] === (int)$source_pid) ? $source_pid : null;
            $targetPid = $test ? $target_pid : $source_pid;

            $sql = "SELECT e1.date, e1.encounter, e1.reason, e1.encounter_type_code, e1.pid
                    FROM `form_encounter` e1 WHERE e1.pid = ? AND e1.date = ? LIMIT 1";
            $target = sqlQuery($sql, array($targetPid, $source['date']));
            if (empty($target)) {
                // there wasn't a target encounter date match to merge components
                // so grab an encounter that is within the date period of source encounter.
                $src_date = date("Ymd", strtotime($source['date']));
                $sql = "SELECT e1.date, e1.date_end, e1.encounter, e1.reason, e1.encounter_type_code, e1.pid
                    FROM `form_encounter` e1 WHERE e1.pid = ? AND ? BETWEEN e1.date and e1.date_end LIMIT 1";
                $target = sqlQuery($sql, array($targetPid, $src_date));
            }
            return [$target ?? null, $source ?? null];
        }

        function cleanupMergedSourcePatient($source_pid, $target_pid, $sourceDocDir): void
        {
            global $PRODUCTION;

            // Make the source chart disappear even if the earlier table sweep missed
            // a branch or table ordering changed.
            foreach (['history_data', 'insurance_data', 'patient_data'] as $tableName) {
                $countRow = sqlQuery("SELECT COUNT(*) AS count FROM " . escape_table_name($tableName) . " WHERE pid = ?", [$source_pid]);
                $count = (int) ($countRow['count'] ?? 0);
                if ($count <= 0) {
                    continue;
                }

                echo "<br />Final cleanup delete from " . text($tableName) . " for merged source pid " . text($source_pid) . " ($count)";
                if ($PRODUCTION) {
                    sqlStatement("DELETE FROM " . escape_table_name($tableName) . " WHERE pid = ?", [$source_pid]);
                    logMergeEvent($target_pid, "delete", "Final cleanup removed source pid $source_pid from table $tableName");
                }
            }

            if (!$PRODUCTION || !is_dir($sourceDocDir)) {
                return;
            }

            $remainingEntries = array_values(array_diff(scandir($sourceDocDir) ?: [], ['.', '..']));
            if (empty($remainingEntries)) {
                if (@rmdir($sourceDocDir)) {
                    echo "<br />Removed empty merged source document directory " . text($sourceDocDir);
                    logMergeEvent($target_pid, "delete", "Removed empty merged source document directory for pid $source_pid");
                }
            }
        }

        /**
         * @param $source_pid
         * @param $target_pid
         * @return void
         */
        function resolveDuplicateEncounters($targets): void
        {
            global $PRODUCTION;

            $target_pid = $targets[0]['pid'];
            $target = $targets[0]['encounter'];
            $source = $targets[1];

            if (!empty($target)) {
                $sql = "SELECT DISTINCT TABLE_NAME as encounter_table, COLUMN_NAME as encounter_column " .
                    "FROM INFORMATION_SCHEMA.COLUMNS " .
                    "WHERE COLUMN_NAME IN('encounter', 'encounter_id') AND TABLE_SCHEMA = ?";
                $res = sqlStatement($sql, array($GLOBALS['adodb']['db']->database));
                while ($tbl = sqlFetchArray($res)) {
                    $tables[] = $tbl;
                }
                foreach ($tables as $table) {
                    if ($table['encounter_table'] === 'form_encounter' || $table['encounter_table'] === 'forms') {
                        continue;
                    }
                    $sql = "UPDATE " . escape_table_name($table['encounter_table']) . " SET " .
                        escape_sql_column_name($table['encounter_column'], array($table['encounter_table'])) . " = ? WHERE " .
                        escape_sql_column_name($table['encounter_column'], array($table['encounter_table'])) . " = ?";

                    if ($PRODUCTION) {
                        sqlStatement($sql, array($target, $source['encounter']));
                        if ($GLOBALS['adodb']['db']->_connectionID->affected_rows) {
                            echo "<br />$sql (" . text($target) . ")" . " : (" . text($source['encounter']) . ")";
                            logMergeEvent(
                                $target_pid,
                                "update",
                                "Updated for duplicate encounters with " .
                                $table['encounter_table'] . '.' . $table['encounter_column'] . " = " . $target
                            );
                        }
                    }
                }
                $sql = "UPDATE " . escape_table_name('forms') . " SET " .
                    escape_sql_column_name('encounter', array('forms')) . " = ? WHERE " .
                    escape_sql_column_name('encounter', array('forms')) . " = ? AND " .
                    escape_sql_column_name('formdir', array('forms')) . " != 'newpatient'";
                sqlStatement($sql, array($target, $source['encounter']));
                if ($PRODUCTION) {
                    $sql = "DELETE FROM `forms` WHERE `encounter` = ? AND `formdir` = 'newpatient'";
                    sqlStatement($sql, array($source['encounter']));
                    $sql = "DELETE FROM `form_encounter` WHERE `encounter` = ?";
                    sqlStatement($sql, array($source['encounter']));
                    if ($GLOBALS['adodb']['db']->_connectionID->affected_rows) {
                        echo "<br />$sql" . text($source['encounter']);
                        logMergeEvent(
                            $target_pid,
                            "delete",
                            "deleted duplicate form encounter " . " = " . $source['encounter'] . " after move."
                        );
                    }
                }
            }
        }

        if (!empty($_POST['form_submit'])) {
            if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
                CsrfUtils::csrfNotVerified();
            }

            $targets = null;
            $target_pid = intval($_POST['form_target_pid']);
            $source_pid = intval($_POST['form_source_pid']);
            echo "<div class='merge-log'>";
            if ($target_pid == $source_pid) {
                die(xlt('Target and source pid may not be the same!'));
            }

            // we want to adjust target and source pid so we can remove the anonymous encounter
            // created by import to house independent components.
            // test if merge or dedupe action is needed.
            if (!empty($_POST['form_submit'])) {
                $targets = resolveTargets($target_pid, $source_pid);
                $_POST['form_submit'] = !empty($targets) ? 'dedupe' : 'merge';
                if ($_POST['form_submit'] == "dedupe" && (empty($targets[0]['pid']) || empty($targets[1]['pid']))) {
                    throw new \RuntimeException("Failed to resolve deduplication target");
                }
                if ($_POST['form_submit'] == "dedupe") {
                    $target_pid = $targets[0]['pid'] ?? null;
                    $source_pid = $targets[1]['pid'] ?? null;
                }
            }

            $tprow = sqlQuery(
                "SELECT * FROM patient_data WHERE pid = ?",
                array($target_pid)
            );
            $sprow = sqlQuery(
                "SELECT * FROM patient_data WHERE pid = ?",
                array($source_pid)
            );

            // Do some checking to make sure source and target exist and are the same person.
            if (empty($tprow['pid'])) {
                die(xlt('Target patient not found'));
            }

            if (empty($sprow['pid'])) {
                die(xlt('Source patient not found'));
            }

            // SSN and DOB checking are skipped if we are coming from the dup manager.
            if (!$form_pid1 || !$form_pid2) {
                if ($tprow['ss'] != $sprow['ss']) {
                    die(xlt('Target and source SSN do not match'));
                }
                if (empty($tprow['DOB']) || $tprow['DOB'] == '0000-00-00') {
                    die(xlt('Target patient has no DOB'));
                }
                if (empty($sprow['DOB']) || $sprow['DOB'] == '0000-00-00') {
                    die(xlt('Source patient has no DOB'));
                }
                if ($tprow['DOB'] != $sprow['DOB']) {
                    die(xlt('Target and source DOB do not match'));
                }
            }

            $tdocdir = "$OE_SITE_DIR/documents/" . check_file_dir_name($target_pid);
            $sdocdir = "$OE_SITE_DIR/documents/" . check_file_dir_name($source_pid);
            $sencdir = "$sdocdir/encounters";
            $tencdir = "$tdocdir/encounters";

            // Change normal documents first as that could fail if CouchDB connection fails.
            $dres = sqlStatement(
                "SELECT * FROM `documents` WHERE `foreign_id` = ?",
                array($source_pid)
            );
            while ($drow = sqlFetchArray($dres)) {
                $d = new Document($drow['id']);
                echo "<br />" . xlt('Changing patient ID for document') . ' '
                    . text($d->get_url_file());
                if ($PRODUCTION) {
                    if (!$d->change_patient($target_pid)) {
                        die("<br />" . xlt('Change failed! CouchDB connect error?'));
                    }
                }
            }

            // Move scanned encounter documents and delete their container.
            if (is_dir($sencdir)) {
                if ($PRODUCTION && !file_exists($tdocdir)) {
                    mkdir($tdocdir);
                }

                if ($PRODUCTION && !file_exists($tencdir)) {
                    mkdir($tencdir);
                }

                $dh = opendir($sencdir);
                if (!$dh) {
                    die(xlt('Cannot read directory') . " '" . text($sencdir) . "'");
                }

                while (false !== ($sfname = readdir($dh))) {
                    if ($sfname == '.' || $sfname == '..') {
                        continue;
                    }

                    if ($sfname == 'index.html') {
                        echo "<br />" . xlt('Deleting') . " " . text($sencdir) . "/"
                            . text($sfname);
                        if ($PRODUCTION) {
                            if (!unlink("$sencdir/$sfname")) {
                                die("<br />" . xlt('Delete failed!'));
                            }
                        }

                        continue;
                    }

                    echo "<br />" . xlt('Moving') . " " . text($sencdir) . "/"
                        . text($sfname) . " " . xlt('to{{Destination}}') . " "
                        . text($tencdir) . "/" . text($sfname);
                    if ($PRODUCTION) {
                        if (!rename("$sencdir/$sfname", "$tencdir/$sfname")) {
                            die("<br />" . xlt('Move failed!'));
                        }
                    }
                }

                closedir($dh);
                echo "<br />" . xlt('Deleting') . " $sencdir";
                if ($PRODUCTION) {
                    if (!rmdir($sencdir)) {
                        echo "<br />" . xlt('Directory delete failed; continuing.');
                    }
                }
            }

            $tres = sqlStatement("SHOW TABLES");
            while ($trow = sqlFetchArray($tres)) {
                $tblname = array_shift($trow);
                if (
                    $tblname == 'patient_data'
                    || $tblname == 'history_data'
                    || $tblname == 'insurance_data'
                ) {
                    deleteRows($tblname, 'pid', $source_pid, $target_pid);
                } elseif ($tblname == 'chart_tracker') {
                    updateRows($tblname, 'ct_pid', $source_pid, $target_pid);
                } elseif ($tblname == 'patient_credit_balance') {
                    mergePatientCreditBalance($source_pid, $target_pid);
                } elseif ($tblname == 'daily_administer_tracking') {
                    mergeDailyAdministerTracking($source_pid, $target_pid);
                } elseif ($tblname == 'patient_credit_transfers') {
                    updateRows($tblname, 'from_pid', $source_pid, $target_pid);
                    updateRows($tblname, 'to_pid', $source_pid, $target_pid);
                } elseif ($tblname == 'credit_transfers') {
                    updateRows($tblname, 'from_pid', $source_pid, $target_pid);
                    updateRows($tblname, 'to_pid', $source_pid, $target_pid);
                } elseif ($tblname == 'pos_transfer_history') {
                    updateRows($tblname, 'source_pid', $source_pid, $target_pid);
                    updateRows($tblname, 'target_pid', $source_pid, $target_pid);
                } elseif ($tblname == 'documents') {
                    // Documents already handled.
                } elseif ($tblname == 'openemr_postcalendar_events') {
                    updateRows($tblname, 'pc_pid', $source_pid, $target_pid);
                } elseif ($tblname == 'lists_touch') {
                    mergeRows($tblname, 'pid', $source_pid, $target_pid);
                } elseif ($tblname == 'log') {
                    // Don't mess with log data.
                } else {
                    $patientReferenceColumns = getPatientReferenceColumns($tblname);
                    foreach ($patientReferenceColumns as $colname) {
                        updateRows($tblname, $colname, $source_pid, $target_pid);
                        // Note employer_data is included here; its rows are never deleted and the
                        // most recent row for each patient is the one that is normally relevant.
                    }
                }
            }

            // Deduplicate encounters
            // at this point the pids of merge/dedupe action has been merged whereas now
            // we'll resolve the encounters components to the appropriate target encounter.
            if ($_POST['form_submit'] == 'dedupe') {
                if (empty($targets)) {
                    throw new \RuntimeException("Failed to resolve targets for deduplication. Go back.");
                }
                resolveDuplicateEncounters($targets);
            }

            cleanupMergedSourcePatient($source_pid, $target_pid, $sdocdir);

            // Recompute dupscore for target patient.
            updateDupScore($target_pid);

            echo "<div class='alert alert-success merge-alert mb-0 mt-3'>" . xlt('Merge complete.') . "</div>";
            echo "</div>";

            echo "<div class='mt-3'>";
            echo "<input type='button' class='btn btn-primary' value='" . xla('Go to Duplicate Manager') .
                "' onclick='window.location = \"manage_dup_patients.php\"' />\n";
            echo "</div>";
            echo "</body></html>";

            exit(0);
        }

        $target_string = xl('Click to select');
        $source_string = xl('Click to select');
        $target_pid = '0';
        $source_pid = '0';

        if ($form_pid1) {
            $target_pid = $form_pid1;
            $row = sqlQuery(
                "SELECT lname, fname FROM patient_data WHERE pid = ?",
                array($target_pid)
            );
            $target_string = $row['lname'] . ', ' . $row['fname'] . " ($target_pid)";
        }
        if ($form_pid2) {
            $source_pid = $form_pid2;
            $row = sqlQuery(
                "SELECT lname, fname FROM patient_data WHERE pid = ?",
                array($source_pid)
            );
            $source_string = $row['lname'] . ', ' . $row['fname'] . " ($source_pid)";
        }
        ?>

        <div class="merge-grid">
            <div class="merge-card">
                <div class="merge-card-header">
                    <h2 class="merge-card-title"><?php echo xlt('Merge Setup'); ?></h2>
                </div>
                <div class="merge-card-body">
                    <form method='post' action='merge_patients.php?<?php echo "pid1=" . attr_url($form_pid1) . "&pid2=" . attr_url($form_pid2); ?>'>
                        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                        <div class="merge-summary">
                            <div class="merge-patient-box">
                                <label class="merge-patient-label" for="form_target_patient"><?php echo xlt('Target Patient'); ?></label>
                                <input id="form_target_patient" type='text' class="form-control" size='30' name='form_target_patient'
                                    value='<?php echo attr($target_string); ?>'
                                    onclick='sel_patient(this, this.form.form_target_pid)'
                                    title='<?php echo xla('Click to select patient'); ?>' readonly />
                                <input type='hidden' name='form_target_pid' value='<?php echo attr($target_pid); ?>' />
                                <p class="merge-patient-help"><?php echo xlt('This is the main chart that keeps demographics, insurance, and history.'); ?></p>
                            </div>
                            <div class="merge-arrow" aria-hidden="true">↓</div>
                            <div class="merge-patient-box">
                                <label class="merge-patient-label" for="form_source_patient"><?php echo xlt('Source Patient'); ?></label>
                                <input id="form_source_patient" type='text' class='form-control' size='30' name='form_source_patient'
                                    value='<?php echo attr($source_string); ?>'
                                    onclick='sel_patient(this, this.form.form_source_pid)'
                                    title='<?php echo xla('Click to select patient'); ?>' readonly />
                                <input type='hidden' name='form_source_pid' value='<?php echo attr($source_pid); ?>' />
                                <p class="merge-patient-help"><?php echo xlt('This chart is merged into the target and then removed.'); ?></p>
                            </div>
                        </div>
                        <div class="merge-actions">
                            <button type='submit' class="btn btn-primary" name='form_submit' value='merge'><?php echo xla('Merge'); ?></button>
                            <button type='submit' class="btn btn-outline-primary" name='form_submit' value='dedupe'><?php echo xla('Merge with Encounter Deduplication'); ?></button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="merge-card">
                <div class="merge-card-header">
                    <h2 class="merge-card-title"><?php echo xlt('What Happens'); ?></h2>
                </div>
                <div class="merge-card-body">
                    <ul class="merge-side-list">
                        <li><?php echo xlt('Moves compatible patient-linked records into the target chart, including POS and shot-history related data.'); ?></li>
                        <li><?php echo xlt('Keeps the target chart as the authoritative demographic, history, and insurance profile.'); ?></li>
                        <li><?php echo xlt('Deletes the source chart after a successful merge cleanup.'); ?></li>
                    </ul>

                    <div class="alert alert-warning merge-alert mb-0" role="alert">
                        <strong><?php echo xlt('Use carefully.'); ?></strong>
                        <?php echo xlt('Back up your database and documents before merging charts.'); ?>
                    </div>

                    <?php if (!$PRODUCTION) { ?>
                        <div class="alert alert-info merge-alert mb-0" role="alert">
                            <?php echo xlt('This environment is set to dry run mode, so no physical data updates will occur.'); ?>
                        </div>
                    <?php } ?>

                    <?php if (!$form_pid1 || !$form_pid2) { ?>
                        <div class="alert alert-secondary merge-alert mb-0" role="alert">
                            <?php echo xlt('When patients are selected manually, the merge runs only if SSN and DOB match and DOB is not empty.'); ?>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
