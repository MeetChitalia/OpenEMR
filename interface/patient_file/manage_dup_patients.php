<?php

/*
 * This tool helps with identifying and merging duplicate patients.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2017-2021 Rod Roark <rod@sunsetsystems.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/options.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\Services\FacilityService;

$firsttime = true;
$deleteStatusMessage = '';

function dupRowDelete($table, $where)
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

function dupRowModify($table, $set, $where)
{
    if (sqlQuery("SELECT * FROM " . escape_table_name($table) . " WHERE $where")) {
        EventAuditLogger::instance()->newEvent("deactivate", $_SESSION['authUser'], $_SESSION['authProvider'], 1, "$table: $where");
        sqlStatement("UPDATE " . escape_table_name($table) . " SET $set WHERE $where");
    }
}

function dupTableExists($table)
{
    return (bool) sqlFetchArray(sqlStatement("SHOW TABLES LIKE ?", [$table]));
}

function dupOptionalRowDelete($table, $where)
{
    if (!dupTableExists($table)) {
        return;
    }

    dupRowDelete($table, $where);
}

function dupOptionalRowModify($table, $set, $where)
{
    if (!dupTableExists($table)) {
        return;
    }

    dupRowModify($table, $set, $where);
}

function dupDeleteDrugSales($patientId)
{
    sqlStatement(
        "UPDATE drug_sales AS ds, drug_inventory AS di " .
        "SET di.on_hand = di.on_hand + ds.quantity " .
        "WHERE ds.pid = ? AND ds.encounter != 0 AND di.inventory_id = ds.inventory_id",
        [$patientId]
    );
    dupRowDelete("drug_sales", "pid = '" . add_escape_custom($patientId) . "'");
}

function dupFormDelete($formdir, $formid, $patientId, $encounterId)
{
    $formdir = ($formdir == 'newpatient') ? 'encounter' : $formdir;
    $formdir = ($formdir == 'newGroupEncounter') ? 'groups_encounter' : $formdir;
    $isValidFormTableSuffix = static function ($value) {
        return is_string($value) && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $value);
    };

    if (substr($formdir, 0, 3) == 'LBF') {
        dupRowDelete("lbf_data", "form_id = '" . add_escape_custom($formid) . "'");
        $where = "pid = '" . add_escape_custom($patientId) . "' AND encounter = '" .
            add_escape_custom($encounterId) . "' AND field_id NOT IN (" .
            "SELECT lo.field_id FROM forms AS f, layout_options AS lo WHERE " .
            "f.pid = '" . add_escape_custom($patientId) . "' AND f.encounter = '" .
            add_escape_custom($encounterId) . "' AND f.formdir LIKE 'LBF%' AND " .
            "f.deleted = 0 AND f.form_id != '" . add_escape_custom($formid) . "' AND " .
            "lo.form_id = f.formdir AND lo.source = 'E' AND lo.uor > 0)";
        dupRowDelete("shared_attributes", $where);
    } elseif ($formdir == 'procedure_order') {
        $tres = sqlStatement("SELECT procedure_report_id FROM procedure_report WHERE procedure_order_id = ?", [$formid]);
        while ($trow = sqlFetchArray($tres)) {
            $reportid = (int) $trow['procedure_report_id'];
            dupRowDelete("procedure_result", "procedure_report_id = '" . add_escape_custom($reportid) . "'");
        }
        dupRowDelete("procedure_report", "procedure_order_id = '" . add_escape_custom($formid) . "'");
        dupRowDelete("procedure_order_code", "procedure_order_id = '" . add_escape_custom($formid) . "'");
        dupRowDelete("procedure_order", "procedure_order_id = '" . add_escape_custom($formid) . "'");
    } elseif ($formdir == 'physical_exam') {
        dupRowDelete("form_$formdir", "forms_id = '" . add_escape_custom($formid) . "'");
    } elseif ($formdir == 'eye_mag') {
        $tables = ['form_eye_base', 'form_eye_hpi', 'form_eye_ros', 'form_eye_vitals', 'form_eye_acuity', 'form_eye_refraction', 'form_eye_biometrics', 'form_eye_external', 'form_eye_antseg', 'form_eye_postseg', 'form_eye_neuro', 'form_eye_locking', 'form_eye_mag_orders'];
        foreach ($tables as $tableName) {
            dupRowDelete($tableName, "id = '" . add_escape_custom($formid) . "'");
        }
        dupRowDelete("form_eye_mag_impplan", "form_id = '" . add_escape_custom($formid) . "'");
        dupRowDelete("form_eye_mag_wearing", "FORM_ID = '" . add_escape_custom($formid) . "'");
    } elseif (!$isValidFormTableSuffix($formdir)) {
        EventAuditLogger::instance()->newEvent(
            "warning",
            $_SESSION['authUser'],
            $_SESSION['authProvider'],
            1,
            "Skipped duplicate form cleanup for invalid formdir: " . (string) $formdir
        );
    } else {
        dupRowDelete("form_$formdir", "id = '" . add_escape_custom($formid) . "'");
    }
}

function dupDeleteDocument($documentId)
{
    sqlStatement("UPDATE `documents` SET `deleted` = 1 WHERE id = ?", [$documentId]);
    dupRowDelete("categories_to_documents", "document_id = '" . add_escape_custom($documentId) . "'");
    dupRowDelete("gprelations", "type1 = 1 AND id1 = '" . add_escape_custom($documentId) . "'");
}

function dupDeletePatientChart($patientId)
{
    dupRowModify("billing", "activity = 0", "pid = '" . add_escape_custom($patientId) . "'");
    dupRowModify("pnotes", "deleted = 1", "pid = '" . add_escape_custom($patientId) . "'");
    dupRowDelete("prescriptions", "patient_id = '" . add_escape_custom($patientId) . "'");
    dupRowDelete("claims", "patient_id = '" . add_escape_custom($patientId) . "'");
    dupDeleteDrugSales($patientId);
    dupRowDelete("payments", "pid = '" . add_escape_custom($patientId) . "'");
    dupRowModify("ar_activity", "deleted = NOW()", "pid = '" . add_escape_custom($patientId) . "' AND deleted IS NULL");
    dupRowDelete("openemr_postcalendar_events", "pc_pid = '" . add_escape_custom($patientId) . "'");
    dupRowDelete("immunizations", "patient_id = '" . add_escape_custom($patientId) . "'");
    dupRowDelete("issue_encounter", "pid = '" . add_escape_custom($patientId) . "'");
    dupRowDelete("lists", "pid = '" . add_escape_custom($patientId) . "'");
    dupRowDelete("transactions", "pid = '" . add_escape_custom($patientId) . "'");
    dupRowDelete("employer_data", "pid = '" . add_escape_custom($patientId) . "'");
    dupRowDelete("history_data", "pid = '" . add_escape_custom($patientId) . "'");
    dupRowDelete("insurance_data", "pid = '" . add_escape_custom($patientId) . "'");
    dupRowDelete("patient_history", "pid = '" . add_escape_custom($patientId) . "'");

    // Remove POS and dispensing data so deleted patients do not leave orphaned
    // receipts, tracking, or balances behind.
    dupOptionalRowDelete("marketplace_dispense_tracking", "pid = '" . add_escape_custom($patientId) . "'");
    dupOptionalRowDelete("pos_refunds", "pid = '" . add_escape_custom($patientId) . "'");
    dupOptionalRowDelete("patient_credit_transactions", "pid = '" . add_escape_custom($patientId) . "'");
    dupOptionalRowDelete("patient_credit_balance", "pid = '" . add_escape_custom($patientId) . "'");
    dupOptionalRowDelete("daily_administer_tracking", "pid = '" . add_escape_custom($patientId) . "'");
    dupOptionalRowDelete("pos_remaining_dispense", "pid = '" . add_escape_custom($patientId) . "'");
    dupOptionalRowDelete("pos_transactions", "pid = '" . add_escape_custom($patientId) . "'");
    dupOptionalRowDelete("patient_credit_transfers", "from_pid = '" . add_escape_custom($patientId) . "' OR to_pid = '" . add_escape_custom($patientId) . "'");
    dupOptionalRowDelete("pos_transfer_history", "source_pid = '" . add_escape_custom($patientId) . "' OR target_pid = '" . add_escape_custom($patientId) . "'");
    dupOptionalRowDelete("pos_transaction_void_audit", "pid = '" . add_escape_custom($patientId) . "'");

    $res = sqlStatement("SELECT * FROM forms WHERE pid = ?", [$patientId]);
    while ($row = sqlFetchArray($res)) {
        dupFormDelete($row['formdir'], $row['form_id'], $row['pid'], $row['encounter']);
    }
    dupRowDelete("forms", "pid = '" . add_escape_custom($patientId) . "'");

    $res = sqlStatement("SELECT id FROM documents WHERE foreign_id = ? AND deleted = 0", [$patientId]);
    while ($row = sqlFetchArray($res)) {
        dupDeleteDocument($row['id']);
    }

    dupRowDelete("patient_data", "pid = '" . add_escape_custom($patientId) . "'");
}

function displayRow($row, $pid = '')
{
    global $firsttime;

    $myscore = '';

    if (empty($pid)) {
        $pid = $row['pid'];
    }

    if (isset($row['myscore'])) {
        $myscore = $row['myscore'];
    } else {
        $myscore = $row['dupscore'];
        if (!$firsttime) {
            echo " <tr class='dup-group-separator'><td class='detail' colspan='13'><div class='dup-group-line'></div></td></tr>\n";
        }
    }

    $firsttime = false;
    $ptname = $row['lname'] . ', ' . $row['fname'] . ' ' . $row['mname'];
    $phones = array();
    if (trim($row['phone_home'])) {
        $phones[] = trim($row['phone_home']);
    }
    if (trim($row['phone_biz' ])) {
        $phones[] = trim($row['phone_biz' ]);
    }
    if (trim($row['phone_cell'])) {
        $phones[] = trim($row['phone_cell']);
    }
    $phones = implode(', ', $phones);

    $facname = '';
    $homeFacility = $row['home_facility'] ?? '';
    if (!empty($homeFacility)) {
        $facrow = getFacility($homeFacility);
        if (!empty($facrow['name'])) {
            $facname = $facrow['name'];
        }
    }
    ?>
 <tr class='dup-row'>
  <td class="detail dup-check-cell" align="center">
   <input type="checkbox" class="dup-delete-checkbox" value="<?php echo attr($row['pid']); ?>" />
  </td>
  <td class="detail" align="right">
    <?php echo text($myscore); ?>
  </td>
  <td class="detail" align="right" onclick="openNewTopWindow(<?php echo attr_js($row['pid']); ?>)"
    title="<?php echo xla('Click to open in a new window or tab'); ?>" style="color:blue;cursor:pointer">
    <?php echo text($row['pid']); ?>
  </td>
  <td class="detail">
    <?php echo text($row['pubpid']); ?>
  </td>
  <td class="detail">
    <?php echo text($ptname); ?>
  </td>
  <td class="detail">
    <?php echo text(oeFormatShortDate($row['DOB'])); ?>
  </td>
  <td class="detail">
    <?php echo text($row['ss']); ?>
  </td>
  <td class="detail">
    <?php echo text($row['email']); ?>
  </td>
  <td class="detail">
    <?php echo text($phones); ?>
  </td>
  <td class="detail">
    <?php echo text(oeFormatShortDate($row['regdate'] ?? '')); ?>
  </td>
  <td class="detail">
    <?php echo text($facname); ?>
  </td>
  <td class="detail">
    <?php echo text($row['street']); ?>
  </td>
  <td class="detail">
    <?php if ((int) $pid !== (int) $row['pid']) { ?>
      <a class="delink"
         href="merge_patients.php?pid1=<?php echo attr_url((string) $pid); ?>&pid2=<?php echo attr_url((string) $row['pid']); ?>"
         onclick="top.restoreSession()"
         title="<?php echo xla('Merge this source chart into the duplicate group patient'); ?>">
        <?php echo xlt('Merge into'); ?> <?php echo text((string) $pid); ?>
      </a>
    <?php } ?>
  </td>
 </tr>
    <?php
}

if (!empty($_POST)) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }
}

if (!AclMain::aclCheckCore('admin', 'super')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Duplicate Patient Management")]);
    exit;
}

$scorecalc = getDupScoreSQL();
?>
<html>
<head>
<title><?php echo xlt('Duplicate Patient Management') ?></title>

    <?php Header::setupHeader(['report-helper']); ?>

<style type="text/css">

.dup-body {
 margin: 0;
 background: #f5f7fb;
 font-family: sans-serif;
 color: #243447;
}

.dup-shell {
 max-width: 1440px;
 margin: 24px auto;
 padding: 0 18px 32px 18px;
}

.dup-card {
 background: #ffffff;
 border: 1px solid #e3e8ef;
 border-radius: 18px;
 box-shadow: 0 10px 26px rgba(15, 23, 42, 0.08);
 overflow: hidden;
}

.dup-card-head {
 padding: 28px 28px 18px 28px;
 border-bottom: 1px solid #edf1f6;
 background: linear-gradient(180deg, #ffffff 0%, #fafcff 100%);
}

.dup-title {
 margin: 0;
 font-size: 28px;
 font-weight: 700;
 color: #1f2d3d;
}

.dup-subtitle {
 margin: 8px 0 0 0;
 color: #6b7a90;
 font-size: 14px;
}

.dup-toolbar {
 display: flex;
 align-items: center;
 justify-content: space-between;
 gap: 12px;
 flex-wrap: wrap;
 padding: 18px 28px;
 border-bottom: 1px solid #edf1f6;
 background: #fbfcfe;
}

.dup-toolbar-actions {
 display: flex;
 gap: 10px;
 flex-wrap: wrap;
}

.dup-btn {
 border: 1px solid #d7e0ea;
 border-radius: 10px;
 padding: 9px 16px;
 background: #ffffff;
 color: #243447;
 font-size: 14px;
 font-weight: 600;
 cursor: pointer;
 box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
}

.dup-btn:hover {
 background: #f8fbff;
}

.dup-btn-primary {
 background: #2f80ed;
 border-color: #2f80ed;
 color: #ffffff;
}

.dup-btn-primary:hover {
 background: #1f6fd8;
}

.dup-btn-danger {
 background: #fff4f3;
 border-color: #f2b8b5;
 color: #b42318;
}

.dup-btn-danger:hover {
 background: #ffe9e7;
}

.dup-table-wrap {
 padding: 0 20px 20px 20px;
 overflow-x: auto;
}

.dehead { color:#243447; font-size:10pt; font-weight:700 }
.detail { color:#243447; font-size:10pt; font-weight:400 }
.delink { color:#2f80ed; font-size:10pt; font-weight:600; cursor:pointer }

table.mymaintable {
 width: 100%;
 border-collapse: separate;
 border-spacing: 0;
}

table.mymaintable td {
 padding: 9px 10px;
 border-right: 1px solid #edf1f6;
 border-bottom: 1px solid #edf1f6;
 vertical-align: middle;
 background: #ffffff;
}

table.mymaintable thead td {
 position: sticky;
 top: 0;
 z-index: 2;
 background: #eef4fb;
 border-top: 1px solid #edf1f6;
}

table.mymaintable tr td:first-child {
 border-left: 1px solid #edf1f6;
}

.dup-row:hover td {
 background: #f9fbff;
}

.dup-check-cell {
 background: #f7faff !important;
}

.dup-group-separator td {
 padding: 16px 10px !important;
 border: none;
 background: transparent !important;
}

.dup-group-line {
 position: relative;
 height: 18px;
 background: linear-gradient(90deg, #d8e8fb 0%, #8db8f2 50%, #d8e8fb 100%);
 border-radius: 999px;
 box-shadow: inset 0 0 0 1px rgba(47, 128, 237, 0.12);
}

.dup-group-line::before {
 content: '';
 position: absolute;
 inset: 7px 18px;
 border-top: 2px dashed rgba(255, 255, 255, 0.9);
}

.dup-group-line::after {
 content: 'Duplicate Group';
 position: absolute;
 top: 50%;
 left: 50%;
 transform: translate(-50%, -50%);
 padding: 0 12px;
 background: #edf5ff;
 color: #2f5f9e;
 font-size: 11px;
 font-weight: 700;
 letter-spacing: 0.08em;
 text-transform: uppercase;
 border-radius: 999px;
}

table.mymaintable select {
 width: 100%;
 min-width: 150px;
 border: 1px solid #d7e0ea;
 border-radius: 8px;
 padding: 6px 8px;
 background: #ffffff;
}

.dup-message {
 background: #e8f6ee;
 border: 1px solid #7cc79b;
 color: #1f5c36;
 padding: 10px 14px;
 margin: 0 0 14px 0;
 border-radius: 6px;
 font-family: sans-serif;
 font-size: 11pt;
}

.dup-modal-backdrop {
 display: none;
 position: fixed;
 inset: 0;
 background: rgba(0, 0, 0, 0.45);
 z-index: 1050;
}

.dup-modal {
 display: none;
 position: fixed;
 top: 50%;
 left: 50%;
 transform: translate(-50%, -50%);
 width: min(520px, 92vw);
 background: #ffffff;
 border-radius: 10px;
 box-shadow: 0 16px 40px rgba(0, 0, 0, 0.2);
 z-index: 1060;
 padding: 22px;
 text-align: left;
 font-family: sans-serif;
}

.dup-modal-title {
 font-size: 18px;
 font-weight: bold;
 margin-bottom: 10px;
}

.dup-modal-text {
 font-size: 14px;
 margin-bottom: 18px;
}

.dup-modal-actions {
 display: flex;
 justify-content: flex-end;
 gap: 10px;
}

</style>

<script>

$(function () {
    // Enable fixed headers when scrolling the report.
    if (window.oeFixedHeaderSetup) {
        oeFixedHeaderSetup(document.getElementById('mymaintable'));
    }
});

function openNewTopWindow(pid) {
 document.fnew.patientID.value = pid;
 top.restoreSession();
 document.fnew.submit();
}

function openDeleteModal() {
  var selectedPids = [];
  $('.dup-delete-checkbox:checked').each(function () {
    selectedPids.push($(this).val());
  });

  if (!selectedPids.length) {
    alert(<?php echo xlj('Select at least one patient to delete.'); ?>);
    return false;
  }
  document.forms[0].form_delete_pids.value = selectedPids.join(',');
  $('#dup-delete-count').text(selectedPids.length);
  $('#dup-delete-pids').text(selectedPids.join(', '));
  $('#dup-delete-backdrop, #dup-delete-modal').show();
  return false;
}

function toggleAllDeleteSelections(source) {
  $('.dup-delete-checkbox').prop('checked', !!source.checked);
}

function closeDeleteModal() {
  $('#dup-delete-backdrop, #dup-delete-modal').hide();
}

function confirmBulkDelete() {
  document.forms[0].form_action.value = 'DEL_CONFIRM';
  top.restoreSession();
  document.forms[0].submit();
}

</script>

</head>

<body class='dup-body'>
<div class='dup-shell'>
<div class='dup-card'>
<div class='dup-card-head'>
 <h2 class='dup-title'><?php echo xlt('Duplicate Patient Management')?></h2>
 <p class='dup-subtitle'><?php echo xlt('Review potential duplicate charts, merge related records, or remove unwanted test duplicates.'); ?></p>
</div>

<form method='post' action='manage_dup_patients.php'>
<input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
<div class='dup-toolbar'>
 <div class='dup-subtitle'><?php echo xlt('Showing the highest duplicate scores first.'); ?></div>
 <div class='dup-toolbar-actions'>
  <input class='dup-btn' type='submit' name='form_refresh' value="<?php echo xla('Refresh') ?>">
  <input class='dup-btn' type='button' value='<?php echo xla('Print'); ?>' onclick='window.print()' />
  <input class='dup-btn dup-btn-danger' type='button' value='<?php echo xla('Delete Selected'); ?>' onclick='return openDeleteModal()' />
 </div>
</div>

<div class='dup-table-wrap'>
<table id='mymaintable' class='mymaintable'>
 <thead>
  <tr>
   <td class="dehead" align="center">
    <input type="checkbox" onclick="toggleAllDeleteSelections(this)" title="<?php echo xla('Select all visible patients'); ?>" />
   </td>
   <td class="dehead" align="right">
    <?php echo xlt('Score'); ?>
   </td>
   <td class="dehead" align="right">
    <?php echo xlt('Pid'); ?>
   </td>
   <td class="dehead">
    <?php echo xlt('ID'); ?>
   </td>
   <td class="dehead">
    <?php echo xlt('Name'); ?>
   </td>
   <td class="dehead">
    <?php echo xlt('DOB'); ?>
   </td>
   <td class="dehead">
    <?php echo xlt('SSN'); ?>
   </td>
   <td class="dehead">
    <?php echo xlt('Email'); ?>
   </td>
   <td class="dehead">
    <?php echo xlt('Telephone'); ?>
   </td>
   <td class="dehead">
    <?php echo xlt('Registered'); ?>
   </td>
   <td class="dehead">
    <?php echo xlt('Home Facility'); ?>
   </td>
   <td class="dehead">
    <?php echo xlt('Address'); ?>
   </td>
   <td class="dehead">
    <?php echo xlt('Action'); ?>
   </td>
  </tr>
 </thead>
<tbody>
<?php

$form_action = $_POST['form_action'] ?? '';

if ($form_action == 'DEL_CONFIRM') {
    $deletePids = array_filter(array_map('trim', explode(',', (string)($_POST['form_delete_pids'] ?? ''))), function ($value) {
        return ctype_digit((string)$value) && (int)$value > 0;
    });

    if (!empty($deletePids)) {
        foreach ($deletePids as $deletePid) {
            dupDeletePatientChart((int) $deletePid);
        }
        $deleteStatusMessage = xlt('Deleted selected patients successfully.') . ' (' . text((string) count($deletePids)) . ')';
    }
}

if ($deleteStatusMessage) {
    echo "<tr><td colspan='13' style='padding:0;border:none;background:transparent'><div class='dup-message'>" . text($deleteStatusMessage) . "</div></td></tr>\n";
}

$query = "SELECT * FROM patient_data WHERE dupscore > 7 " .
    "ORDER BY dupscore DESC, pid DESC LIMIT 100";
$res1 = sqlStatement($query);
while ($row1 = sqlFetchArray($res1)) {
    displayRow($row1);
    $query = "SELECT p2.*, ($scorecalc) AS myscore " .
    "FROM patient_data AS p1, patient_data AS p2 WHERE " .
    "p1.pid = ? AND p2.pid < p1.pid AND ($scorecalc) > 7 " .
    "ORDER BY myscore DESC, p2.pid DESC";
    $res2 = sqlStatement($query, array($row1['pid']));
    while ($row2 = sqlFetchArray($res2)) {
        displayRow($row2, $row1['pid']);
    }
}
?>
</tbody>
</table>
</div>
<input type='hidden' name='form_action' value='' />
<input type='hidden' name='form_delete_pids' value='' />
</form>
</div>
</div>

<div id="dup-delete-backdrop" class="dup-modal-backdrop" onclick="closeDeleteModal()"></div>
<div id="dup-delete-modal" class="dup-modal" role="dialog" aria-modal="true" aria-labelledby="dup-delete-title">
 <div id="dup-delete-title" class="dup-modal-title"><?php echo xlt('Delete Selected Patients'); ?></div>
 <div class="dup-modal-text">
  <?php echo xlt('You are about to permanently delete these patient charts from the system through the duplicate patient manager.'); ?><br><br>
  <?php echo xlt('Selected count'); ?>: <strong id="dup-delete-count">0</strong><br>
  <?php echo xlt('Patient IDs'); ?>: <span id="dup-delete-pids"></span>
 </div>
 <div class="dup-modal-actions">
  <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()"><?php echo xlt('Cancel'); ?></button>
  <button type="button" class="btn btn-danger" onclick="confirmBulkDelete()"><?php echo xlt('Delete'); ?></button>
 </div>
</div>

<!-- form used to open a new top level window when a patient row is clicked -->
<form name='fnew' method='post' target='_blank'
 action='../main/main_screen.php?auth=login&site=<?php echo attr_url($_SESSION['site_id']); ?>'>
<input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
<input type='hidden' name='patientID' value='0' />
</form>

</body>
</html>
