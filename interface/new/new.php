<?php

/**
 * new.php
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("$srcdir/options.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Core\Header;

if ($GLOBALS['full_new_patient_form']) {
    require("new_comprehensive.php");
    exit;
}

// For a layout field return 0=unused, 1=optional, 2=mandatory.
function getLayoutUOR($form_id, $field_id)
{
    $crow = sqlQuery("SELECT uor FROM layout_options WHERE " .
    "form_id = ? AND field_id = ? LIMIT 1", array($form_id, $field_id));
    if (is_object($crow) && method_exists($crow, 'FetchRow')) {
        $crow = $crow->FetchRow();
    } elseif (!is_array($crow)) {
        $crow = [];
    }

    return 0 + ($crow['uor'] ?? 0);
}

// Get layout fields for DEM form
function getLayoutFields($form_id = 'DEM', $group_id = '1') {
    return sqlStatement("SELECT * FROM layout_options WHERE form_id = ? AND uor > 0 AND group_id = ? ORDER BY seq", array($form_id, $group_id));
}

// Get form values from POST
$form_values = array();
foreach ($_POST as $key => $value) {
    // Remove 'form_' prefix if present
    $field_name = preg_replace('/^form_/', '', $key);
    if (is_array($value)) {
        $form_values[$field_name] = $value;
    } else {
        $form_values[$field_name] = trim((string) $value);
    }
}

// Set default values
$form_values['regdate'] = $form_values['regdate'] ?? date('Y-m-d');
?>
<html>

<head>

<?php
    Header::setupHeader(['datetime-picker', 'bootstrap', 'fontawesome', 'dialog']);
    include_once($GLOBALS['srcdir'] . "/options.js.php");
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
    .add-patient-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        padding: 1.2rem 1rem 1rem 1rem;
        max-width: 1200px;
        margin: 1.2rem auto;
        display: flex;
        gap: 2rem;
    }
    .add-patient-sidebar {
        width: 280px;
        background: #f8f9fa;
        border-radius: 8px;
        padding: 1.5rem;
        height: fit-content;
        position: sticky;
        top: 1rem;
    }
    .add-patient-content {
        flex: 1;
        min-width: 0;
    }
    .add-patient-title {
        font-size: 1.2rem;
        font-weight: 700;
        margin-bottom: 1.5rem;
        color: #2a3b4d;
        text-align: center;
    }
    .sidebar-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: #2a3b4d;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #4A90E2;
    }
    .sidebar-menu {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .sidebar-menu li {
        margin-bottom: 0.5rem;
    }
    .sidebar-menu a {
        display: block;
        padding: 0.75rem 1rem;
        color: #495057;
        text-decoration: none;
        border-radius: 6px;
        transition: all 0.2s;
        font-weight: 500;
    }
    .sidebar-menu a:hover {
        background: #e9ecef;
        color: #2a3b4d;
    }
    .sidebar-menu a.active {
        background: #4A90E2;
        color: white;
    }
    .sidebar-menu a {
        background: none;
        color: #495057;
    }
    .sidebar-menu a:hover {
        background: #e9ecef;
        color: #2a3b4d;
    }
    .sidebar-menu a.completed {
        background: none;
        color: #495057;
    }
    .sidebar-menu a.completed:hover {
        background: #e9ecef;
        color: #2a3b4d;
    }
    .sidebar-nav-buttons {
        margin-top: 1.5rem;
        padding-top: 1rem;
        border-top: 1px solid #dee2e6;
    }
    .nav-btn {
        width: 100%;
        padding: 0.5rem;
        margin-bottom: 0.5rem;
        border: none;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s;
    }
    .nav-btn-prev {
        background: #6c757d;
        color: white;
    }
    .nav-btn-prev:hover {
        background: #5a6268;
    }
    .nav-btn-next {
        background: #4A90E2;
        color: white;
    }
    .nav-btn-next:hover {
        background: #357ABD;
    }
    .nav-btn:disabled {
        background: #e9ecef;
        color: #6c757d;
        cursor: not-allowed;
    }
    .form-section {
        display: none;
    }
    .form-section.active {
        display: block;
        animation: fadeIn 0.3s ease-in;
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .add-patient-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.7rem 1.2rem;
    }
    @media (max-width: 900px) {
        .add-patient-card {
            flex-direction: column;
            gap: 1rem;
        }
        .add-patient-sidebar {
            width: 100%;
            position: static;
        }
        .add-patient-grid {
            grid-template-columns: 1fr;
        }
    }
    .add-patient-section {
        grid-column: 1 / -1;
        font-size: 1rem;
        font-weight: 600;
        color: #4A90E2;
        margin-top: 0.7rem;
        margin-bottom: 0.2rem;
        border-bottom: 1px solid #e6e8ec;
        padding-bottom: 0.1rem;
    }
    .add-patient-label {
        font-weight: 600;
        color: #333;
        margin-bottom: 0.1rem;
        display: block;
    }
    .add-patient-input, .add-patient-select {
        width: 100%;
        padding: 0.45rem 0.7rem;
        border: 1.5px solid #E1E5E9;
        border-radius: 7px;
        font-size: 0.98rem;
        background: white;
        margin-bottom: 0.1rem;
    }
    .add-patient-checkbox-group {
        display: flex;
        gap: 0.7rem;
        align-items: center;
        margin-bottom: 0.2rem;
    }
    .add-patient-actions {
        grid-column: 1 / -1;
        display: flex;
        justify-content: center;
        gap: 0.7rem;
        margin-top: 1rem;
    }
    .btn-save {
        background: #4A90E2;
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 0.5rem 1.5rem;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s;
    }
    .btn-save:hover {
        background: #357ABD;
    }
    .btn-cancel {
        background: #f8f9fa;
        color: #495057;
        border: 2px solid #E1E5E9;
        border-radius: 8px;
        padding: 0.5rem 1.5rem;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s;
    }
    .btn-cancel:hover {
        background: #e9ecef;
    }
    .required {
        color: #dc3545;
    }
    .form-control {
        width: 100%;
        padding: 0.45rem 0.7rem;
        border: 1.5px solid #E1E5E9;
        border-radius: 7px;
        font-size: 0.98rem;
        background: white;
        margin-bottom: 0.1rem;
    }
    .form-control:focus {
        border-color: #4A90E2;
        outline: none;
        box-shadow: 0 0 0 0.2rem rgba(74, 144, 226, 0.25);
    }
    .duplicate-warning {
        background: #fff4e5;
        border: 1px solid #ffd18a;
        border-radius: 10px;
        color: #7a4b00;
        margin-bottom: 1rem;
        padding: 1rem;
    }
    .duplicate-warning h3 {
        font-size: 1rem;
        font-weight: 700;
        margin: 0 0 0.4rem 0;
    }
    .duplicate-table-wrap {
        border: 1px solid #e6e8ec;
        border-radius: 10px;
        margin-bottom: 1rem;
        overflow-x: auto;
    }
    .duplicate-table {
        background: #fff;
        margin: 0;
        width: 100%;
    }
    .duplicate-table th,
    .duplicate-table td {
        border-bottom: 1px solid #eef1f4;
        padding: 0.65rem 0.75rem;
        white-space: nowrap;
    }
    .duplicate-table th {
        background: #f8f9fa;
        font-size: 0.9rem;
        font-weight: 700;
    }
    .duplicate-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        margin-top: 0.85rem;
    }
    .duplicate-link {
        background: #fff;
        border: 1px solid #d7dce2;
        border-radius: 8px;
        color: #2a3b4d;
        display: inline-block;
        font-size: 0.92rem;
        font-weight: 600;
        padding: 0.45rem 0.7rem;
        text-decoration: none;
    }
    .duplicate-link:hover {
        background: #f8f9fa;
        text-decoration: none;
    }
    .patient-form-feedback {
        display: none;
        margin-bottom: 1rem;
        border-radius: 10px;
        padding: 0.9rem 1rem;
        font-size: 0.95rem;
        font-weight: 600;
        line-height: 1.4;
        white-space: pre-line;
    }
    .patient-form-feedback.is-error {
        background: #fff3f2;
        border: 1px solid #f2c3bf;
        color: #a2352a;
        display: block;
    }
    .patient-form-feedback.is-success {
        background: #edf8f0;
        border: 1px solid #b9ddc0;
        color: #1f6b34;
        display: block;
    }
</style>

<script>
 function renderPatientFormFeedback(message, type) {
  var feedback = document.getElementById('patient-form-feedback');
  if (!feedback) {
   return;
  }

  if (!message) {
   feedback.textContent = '';
   feedback.className = 'patient-form-feedback';
   feedback.style.display = 'none';
   return;
  }

  feedback.textContent = message;
  feedback.className = 'patient-form-feedback ' + (type === 'success' ? 'is-success' : 'is-error');
  feedback.style.display = 'block';
 }

 function showValidationMessage(message, field) {
  var targetSection = field ? field.closest('.form-section') : null;
  if (targetSection) {
   $('.form-section').removeClass('active');
   $('.sidebar-menu-item').removeClass('active');
   targetSection.classList.add('active');
   var sectionId = targetSection.id ? targetSection.id.replace('section-', '') : '';
   if (sectionId) {
    $('.sidebar-menu-item[data-group-id="' + sectionId + '"]').addClass('active');
   }
  }

  renderPatientFormFeedback(message, 'error');

  var feedback = document.getElementById('patient-form-feedback');
  if (feedback) {
   feedback.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  if (field && typeof field.focus === 'function') {
   setTimeout(function() {
    field.focus();
   }, 150);
  }
 }

 function fieldHasValue(field) {
  if (!field) {
   return false;
  }

  if (field.tagName === 'SELECT' && field.multiple) {
   return Array.prototype.some.call(field.options, function(option) {
    return option.selected && String(option.value || '').trim() !== '';
   });
  }

  if (typeof field.value === 'undefined' || field.value === null) {
   return false;
  }

  return String(field.value).trim() !== '';
 }

 function getFormField(form, fieldName) {
  return document.getElementById(fieldName) || form[fieldName] || null;
 }

 function validate() {
  var f = document.forms[0];
  renderPatientFormFeedback('');
  
  // Check required fields based on layout configuration
  var requiredFields = [];
  
  // Get all required fields from layout_options
  <?php
  $required_fields = sqlStatement("SELECT field_id FROM layout_options WHERE form_id = 'DEM' AND uor = 2");
  while ($row = sqlFetchArray($required_fields)) {
      if ($row['field_id'] === 'care_team_facility') {
          continue;
      }
      echo "requiredFields.push('" . $row['field_id'] . "');\n";
  }
  ?>

  // Custom: If patient is under 18, require guardian fields
  var dobField = f['form_DOB'];
  if (dobField && dobField.value) {
    var dob = new Date(dobField.value);
    var today = new Date();
    var age = today.getFullYear() - dob.getFullYear();
    var m = today.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) {
      age--;
    }
    if (age < 18) {
      var guardianFields = [
        {id: 'guardiansname', label: 'Guardian Name'},
        {id: 'guardianaddress', label: 'Guardian Address'},
        {id: 'guardianphone', label: 'Guardian Phone'},
        {id: 'guardianrelationship', label: 'Guardian Relationship'}
      ];
      for (var i = 0; i < guardianFields.length; i++) {
        var fieldName = 'form_' + guardianFields[i].id;
        var field = getFormField(f, fieldName);
        if (field && !fieldHasValue(field)) {
          showValidationMessage('For patients under 18, please complete: ' + guardianFields[i].label + '.', field);
   return false;
  }
      }
    }
  }

  for (var i = 0; i < requiredFields.length; i++) {
      var fieldName = 'form_' + requiredFields[i];
      var field = getFormField(f, fieldName);
      if (field && !fieldHasValue(field)) {
          var fieldLabel = field.previousElementSibling ? field.previousElementSibling.textContent.replace(':', '') : 'Required field';
          showValidationMessage('Please complete the required field: ' + fieldLabel + '.', field);
   return false;
  }
  }
  
  top.restoreSession();
  return true;
 }

$(function () {
    $('.datepicker').datetimepicker({
        <?php $datetimepicker_timepicker = false; ?>
        <?php $datetimepicker_showseconds = false; ?>
        <?php $datetimepicker_formatInput = true; ?>
        <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
    });
    $('.datetimepicker').datetimepicker({
        <?php $datetimepicker_timepicker = true; ?>
        <?php $datetimepicker_showseconds = false; ?>
        <?php $datetimepicker_formatInput = true; ?>
        <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
    });
    // Custom US format for DOB
    $('.datepicker-us').datetimepicker({
        timepicker: false,
        format: 'm/d/Y',
        formatDate: 'm/d/Y',
        scrollMonth: false,
        scrollInput: false,
        closeOnDateSelect: true
    });
    // Attach DOB auto-format event after DOM and field are ready
    setTimeout(function() {
        var dobInput = document.getElementById('form_DOB');
        var userFormatted = false;
        if (dobInput) {
            dobInput.addEventListener('input', function() {
                var v = this.value.replace(/[^0-9]/g, '');
                if (v.length === 8) {
                    this.value = v.substring(0,2) + '/' + v.substring(2,4) + '/' + v.substring(4);
                    userFormatted = true;
                    console.log('DOB auto-formatted to: ' + this.value);
                } else {
                    userFormatted = false;
                }
            });
            dobInput.addEventListener('change', function() {
                if (userFormatted) {
                    // Prevent datepicker from re-parsing
                    setTimeout(() => { userFormatted = false; }, 200);
                }
            });
            console.log('DOB auto-format event attached');
        } else {
            console.log('DOB input not found');
        }
    }, 500);
});

</script>

</head>

<body class="body_top" onload="javascript:document.new_patient.fname.focus();">

<form name='new_patient' method='post' action="new_patient_save.php" onsubmit='return validate()' autocomplete='off'>
<input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
<input type="hidden" id="duplicate_override" name="duplicate_override" value="<?php echo attr($form_values['duplicate_override'] ?? '0'); ?>" />

<div class="container-fluid">
    <?php
// Get all layout groups for DEM form
$groups_result = sqlStatement("SELECT * FROM layout_group_properties WHERE grp_form_id = 'DEM' AND grp_group_id != '' ORDER BY grp_seq, grp_group_id");
$group_tabs = [];
$demographics_group_id = null;
$choices_group_id = null;
while ($group = sqlFetchArray($groups_result)) {
    if (($group['grp_title'] ?? '') === 'Demographics') {
        $demographics_group_id = $group['grp_group_id'];
    }
    if (($group['grp_title'] ?? '') === 'Choices') {
        $choices_group_id = $group['grp_group_id'];
    }
    $group_tabs[] = $group;
}

$moved_demographics_field_ids = ['care_team_provider', 'care_team_facility'];

$render_patient_field = function ($field, $form_values) {
    $field_id = $field['field_id'];
    $field_title = $field['title'];
    $field_uor = ($field_id === 'care_team_facility') ? 2 : $field['uor'];
    if ($field_uor == 0) {
        return;
    }

    $currvalue = $form_values[$field_id] ?? '';
    if ($field_id === 'DOB' && !empty($currvalue) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $currvalue)) {
        $dt = DateTime::createFromFormat('Y-m-d', $currvalue);
        if ($dt) {
            $currvalue = $dt->format('m/d/Y');
        }
    }

    $required_class = ($field_uor == 2) ? 'required' : '';
    echo "<div>";
    echo "<label class='add-patient-label $required_class'>" . xlt($field_title) . "</label>";
    if ($field_id === 'DOB') {
        $field['data_type'] = 4;
        echo "<input type='text' class='form-control datepicker-us' name='form_DOB' id='form_DOB' value='" . attr($currvalue) . "' placeholder='MM/DD/YYYY' autocomplete='off' />";
    } else {
        generate_form_field($field, $currvalue);
    }
    echo "</div>";
};
?>
<div class="add-patient-card">
    <div class="add-patient-sidebar">
        <div class="sidebar-title"><?php echo xlt('Add Patient'); ?></div>
        <ul class="sidebar-menu">
<?php
            foreach ($group_tabs as $i => $group) {
                $group_id = $group['grp_group_id'];
                $group_title = $group['grp_title'];
                if ($GLOBALS['omit_employers'] && $group_id == '4') {
                    continue;
                }
                echo "<li><a href='#' class='sidebar-menu-item" . ($i === 0 ? ' active' : '') . "' data-group-id='" . attr($group_id) . "'>" . xlt($group_title) . "</a></li>";
}
?>
        </ul>
        <div class="sidebar-nav-buttons">
            <button type="button" class="nav-btn nav-btn-prev"><?php echo xla('Previous'); ?></button>
            <button type="button" class="nav-btn nav-btn-next"><?php echo xla('Next'); ?></button>
        </div>
    </div>
    <div class="add-patient-content">
        <div class="add-patient-title"><?php echo xlt('Patient Information'); ?></div>
        <div id="patient-form-feedback" class="patient-form-feedback"></div>
        <?php if (!empty($duplicatePatients)) { ?>
            <div class="duplicate-warning" id="duplicate-warning-panel">
                <h3><?php echo xlt('Possible Duplicate Patients'); ?></h3>
                <div><?php echo xlt('These records already look similar. Please review them before creating another chart.'); ?></div>
                <div class="duplicate-actions">
                    <?php if (AclMain::aclCheckCore('admin', 'super')) { ?>
                        <a class="duplicate-link" href="../patient_file/manage_dup_patients.php" target="_blank" rel="noopener noreferrer">
                            <?php echo xlt('Open Duplicate Manager'); ?>
                        </a>
                    <?php } ?>
                </div>
            </div>
            <div class="duplicate-table-wrap">
                <table class="duplicate-table">
                    <thead>
                    <tr>
                        <th><?php echo xlt('Score'); ?></th>
                        <th><?php echo xlt('PID'); ?></th>
                        <th><?php echo xlt('Name'); ?></th>
                        <th><?php echo xlt('Facility'); ?></th>
                        <th><?php echo xlt('DOB'); ?></th>
                        <th><?php echo xlt('Phone'); ?></th>
                        <th><?php echo xlt('Email'); ?></th>
                        <th><?php echo xlt('Action'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($duplicatePatients as $duplicatePatient) { ?>
                        <?php
                        $phones = array_filter([
                            trim($duplicatePatient['phone_home'] ?? ''),
                            trim($duplicatePatient['phone_biz'] ?? ''),
                            trim($duplicatePatient['phone_cell'] ?? '')
                        ]);
                        ?>
                        <tr>
                            <td><?php echo text($duplicatePatient['match_score'] ?? ''); ?></td>
                            <td><?php echo text($duplicatePatient['pid'] ?? ''); ?></td>
                            <td><?php echo text(trim(($duplicatePatient['lname'] ?? '') . ', ' . ($duplicatePatient['fname'] ?? '') . ' ' . ($duplicatePatient['mname'] ?? ''))); ?></td>
                            <td><?php echo text(getPatientFacilityDisplayName($duplicatePatient['facility_id'] ?? '', $duplicatePatient['care_team_facility'] ?? '', $duplicatePatient['facility_name'] ?? '')); ?></td>
                            <td><?php echo text(oeFormatShortDate($duplicatePatient['DOB'] ?? '')); ?></td>
                            <td><?php echo text(implode(', ', $phones)); ?></td>
                            <td><?php echo text($duplicatePatient['email'] ?? ''); ?></td>
                            <td>
                                <a class="duplicate-link" href="../patient_file/summary/demographics.php?set_pid=<?php echo attr_url($duplicatePatient['pid'] ?? ''); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php echo xlt('Open Existing'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    <?php
        foreach ($group_tabs as $i => $group) {
            $group_id = $group['grp_group_id'];
            $group_title = $group['grp_title'];
            if ($GLOBALS['omit_employers'] && $group_id == '4') {
                continue;
            }
            echo '<div class="form-section' . ($i === 0 ? ' active' : '') . '" id="section-' . attr($group_id) . '">';
            echo '<div class="add-patient-section">';
            echo '<span class="add-patient-label">' . xlt($group_title) . '</span>';
            echo '</div>';
            echo '<div class="add-patient-grid">';
            
            $fields_result = getLayoutFields('DEM', $group_id);
            while ($field = sqlFetchArray($fields_result)) {
                $field_id = $field['field_id'];
                if ($group_id === $choices_group_id && in_array($field_id, $moved_demographics_field_ids, true)) {
                    continue;
                }
                $render_patient_field($field, $form_values);
            }

            if ($group_id === $demographics_group_id && !empty($choices_group_id)) {
                $moved_fields_result = getLayoutFields('DEM', $choices_group_id);
                while ($field = sqlFetchArray($moved_fields_result)) {
                    if (!in_array($field['field_id'], $moved_demographics_field_ids, true)) {
                        continue;
                    }
                    $render_patient_field($field, $form_values);
                }
            }
            echo '</div>';
            echo '</div>';
        }
        ?>
        <div class="add-patient-actions">
            <button type="submit" name="form_create" value="1" class="btn btn-save" onclick="document.getElementById('duplicate_override').value = '<?php echo !empty($duplicatePatients) ? '1' : '0'; ?>';"><?php echo xla(!empty($duplicatePatients) ? 'Create Anyway' : 'Create New Patient'); ?></button>
            <button type="reset" class="btn btn-cancel"><?php echo xla('Cancel'); ?></button>
        </div>
    </div>
</div>
</form>
<script>
<?php
if (isset($form_values['pubpid']) && $form_values['pubpid']) {
    echo "renderPatientFormFeedback(" . xlj('This patient ID is already in use. Please use a different ID.') . ", 'error');\n";
}
if (!empty($alertmsg)) {
    echo "renderPatientFormFeedback(" . xlj($alertmsg) . ", 'error');\n";
}
?>

// Sidebar Navigation
$(document).ready(function() {
    let currentSection = 0;
    const sections = $('.form-section');
    const menuItems = $('.sidebar-menu-item');
    const prevBtn = $('.nav-btn-prev');
    const nextBtn = $('.nav-btn-next');
    
    // Initialize navigation
    updateNavigation();
    
    // Menu item click
    $('.sidebar-menu-item').click(function(e) {
        e.preventDefault();
        const groupId = $(this).data('group-id');
        const sectionIndex = sections.index($('#section-' + groupId));
        if (sectionIndex !== -1) {
            showSection(sectionIndex);
        }
    });
    
    // Previous button
    prevBtn.click(function() {
        if (currentSection > 0) {
            showSection(currentSection - 1);
        }
    });
    
    // Next button
    nextBtn.click(function() {
        if (currentSection < sections.length - 1) {
            showSection(currentSection + 1);
        }
    });
    
    function showSection(index) {
        // Hide all sections
        sections.removeClass('active');
        menuItems.removeClass('active');
        
        // Show current section
        sections.eq(index).addClass('active');
        menuItems.eq(index).addClass('active');
        
        currentSection = index;
        updateNavigation();
    }
    
    function updateNavigation() {
        prevBtn.prop('disabled', currentSection === 0);
        nextBtn.prop('disabled', currentSection === sections.length - 1);
        
        if (currentSection === sections.length - 1) {
            nextBtn.text('<?php echo xla('Finish'); ?>');
        } else {
            nextBtn.text('<?php echo xla('Next'); ?>');
        }
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include $GLOBALS['fileroot'] . "/library/options_listadd.inc"; ?>
</body>
</html>
