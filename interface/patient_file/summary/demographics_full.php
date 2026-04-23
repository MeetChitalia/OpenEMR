<?php

/**
 * Edit demographics.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2017 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2021 Rod Roark <rod@sunsetsystems.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../../globals.php");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/validation/LBF_Validation.php");
require_once("$srcdir/patientvalidation.inc.php");
require_once("$srcdir/pid.inc");
require_once("$srcdir/patient.inc");

// Ensure session is maintained for AJAX requests
if (isset($_GET['set_pid']) || isset($_GET['pid'])) {
    // This is an AJAX request, ensure session is active
    if (!isset($_SESSION['authUser'])) {
        die(xlt('Session expired. Please refresh the page.'));
    }
}

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\Events\PatientDemographics\UpdateEvent;

// Session pid must be right or bad things can happen when demographics are saved!
//
$set_pid = $_GET["set_pid"] ?? ($_GET["pid"] ?? null);

if ($set_pid && $set_pid != $_SESSION["pid"]) {
    setpid($set_pid);
}

$result = getPatientData($pid, "*, DATE_FORMAT(DOB,'%Y-%m-%d') as DOB_YMD");
$result2 = getEmployerData($pid);

 // Check authorization.
if ($pid) {
    // Create and fire the patient demographics update event
    $updateEvent = new UpdateEvent($pid);
    $updateEvent = $GLOBALS["kernel"]->getEventDispatcher()->dispatch(UpdateEvent::EVENT_HANDLE, $updateEvent, 10);

    if (
        !$updateEvent->authorized() ||
        !AclMain::aclCheckCore('patients', 'demo', '', 'write')
    ) {
        die(xlt('Updating demographics is not authorized.'));
    }

    if ($result['squad'] && ! AclMain::aclCheckCore('squads', $result['squad'])) {
        die(xlt('You are not authorized to access this squad.'));
    }
} else {
    if (!AclMain::aclCheckCore('patients', 'demo', '', array('write','addonly'))) {
        die(xlt('Adding demographics is not authorized.'));
    }
}

// Get layout fields for DEM form
function getLayoutFields($form_id = 'DEM', $group_id = '1') {
    return sqlStatement("SELECT * FROM layout_options WHERE form_id = ? AND uor > 0 AND group_id = ? ORDER BY seq", array($form_id, $group_id));
}

$CPR = 4; // cells per row

/*Get the constraint from the DB-> LBF forms accordinf the form_id*/
$constraints = LBF_Validation::generate_validate_constraints("DEM");
?>
<script> var constraints = <?php echo $constraints;?>; </script>

<html>
<head>
    <title><?php echo xlt('Edit Demographics'); ?></title>
    <?php Header::setupHeader(['datetime-picker', 'bootstrap', 'fontawesome', 'dialog']); ?>
<?php include_once($GLOBALS['srcdir'] . "/options.js.php"); ?>

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
        overflow: hidden;
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
        overflow-x: hidden;
        overflow-y: auto;
        max-height: calc(100vh - 200px);
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
        opacity: 0;
        transform: translateY(10px);
    }
    .form-section.active {
        display: block;
        opacity: 1;
        transform: translateY(0);
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
        margin-bottom: 1rem;
        width: 100%;
        max-width: 100%;
    }
    
    .add-patient-grid > div {
        display: flex;
        flex-direction: column;
        min-height: 60px;
        max-width: 100%;
        overflow: hidden;
    }
    @media (max-width: 900px) {
        .add-patient-card {
            flex-direction: column;
            gap: 1rem;
            margin: 0.5rem;
            padding: 1rem 0.5rem;
        }
        .add-patient-sidebar {
            width: 100%;
            position: static;
        }
        .add-patient-content {
            max-height: none;
            overflow: visible;
        }
        .add-patient-grid {
            grid-template-columns: 1fr;
            gap: 0.5rem 1rem;
        }
    }
    
    @media (max-width: 600px) {
        .add-patient-card {
            margin: 0;
            border-radius: 0;
            padding: 0.5rem;
        }
        .add-patient-grid {
            gap: 0.3rem 0.5rem;
        }
        .add-patient-grid input,
        .add-patient-grid select,
        .add-patient-grid textarea {
            font-size: 16px; /* Prevent zoom on iOS */
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
    .add-patient-label.required::after {
        content: " *";
        color: #dc3545;
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
    
    /* Ensure all form controls have proper styling */
    .add-patient-grid input[type="text"],
    .add-patient-grid input[type="email"],
    .add-patient-grid input[type="tel"],
    .add-patient-grid input[type="number"],
    .add-patient-grid select,
    .add-patient-grid textarea {
        width: 100%;
        max-width: 100%;
        padding: 0.45rem 0.7rem;
        border: 1.5px solid #E1E5E9;
        border-radius: 7px;
        font-size: 0.98rem;
        background: white;
        margin-bottom: 0.1rem;
        box-sizing: border-box;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .add-patient-grid input:focus,
    .add-patient-grid select:focus,
    .add-patient-grid textarea:focus {
        outline: none;
        border-color: #4A90E2;
        box-shadow: 0 0 0 2px rgba(74, 144, 226, 0.2);
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
        background: #6c757d;
        color: white;
        border: none;
        border-radius: 8px;
        padding: 0.5rem 1.5rem;
        font-size: 1rem;
        font-weight: 600;
        text-decoration: none;
        display: inline-block;
        transition: background 0.2s;
    }
    .btn-cancel:hover {
        background: #5a6268;
        color: white;
        text-decoration: none;
    }
    .datepicker-us {
        background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="%236c757d" viewBox="0 0 16 16"><path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4H1z"/></svg>');
        background-repeat: no-repeat;
        background-position: right 12px center;
        background-size: 16px;
        padding-right: 40px;
    }
    
    /* Ensure form controls have proper styling */
    .add-patient-grid input[type="text"],
    .add-patient-grid input[type="email"],
    .add-patient-grid input[type="tel"],
    .add-patient-grid input[type="number"],
    .add-patient-grid input[type="date"],
    .add-patient-grid select,
    .add-patient-grid textarea {
        width: 100%;
        padding: 0.45rem 0.7rem;
        border: 1.5px solid #E1E5E9;
        border-radius: 7px;
        font-size: 0.98rem;
        background: white;
        margin-bottom: 0.1rem;
    }
    
    .add-patient-grid input:focus,
    .add-patient-grid select:focus,
    .add-patient-grid textarea:focus {
        outline: none;
        border-color: #4A90E2;
        box-shadow: 0 0 0 2px rgba(74, 144, 226, 0.2);
    }
    
    /* Override any Bootstrap form-control styling */
    .add-patient-grid .form-control {
        width: 100%;
        padding: 0.45rem 0.7rem;
        border: 1.5px solid #E1E5E9;
        border-radius: 7px;
        font-size: 0.98rem;
        background: white;
        margin-bottom: 0.1rem;
    }
    
    .add-patient-grid .form-control:focus {
        outline: none;
        border-color: #4A90E2;
        box-shadow: 0 0 0 2px rgba(74, 144, 226, 0.2);
    }
</style>

</head>

<body class="body_top">

<form action='demographics_save.php' name='demographics_form' id="DEM" method='post' class='form-inline'
 onsubmit="submitme(<?php echo $GLOBALS['new_validate'] ? 1 : 0;?>,event,'DEM',constraints)">
<input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
<input type='hidden' name='mode' value='save' />
<input type='hidden' name='db_id' value="<?php echo attr($result['id']); ?>" />

<div class="container-fluid">
<?php
// Get all layout groups for DEM form
$groups_result = sqlStatement("SELECT * FROM layout_group_properties WHERE grp_form_id = 'DEM' AND grp_group_id != '' ORDER BY grp_seq, grp_group_id");
$group_tabs = [];
while ($group = sqlFetchArray($groups_result)) {
    $group_tabs[] = $group;
    }
    ?>
<div class="add-patient-card">
    <div class="add-patient-sidebar">
        <div class="sidebar-title"><?php echo xlt('Edit Patient'); ?></div>
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
                $field_title = $field['title'];
                $field_uor = $field['uor'];
                if ($field_uor == 0) continue;
                
                // Get current value from patient data
                $currvalue = '';
                if (strpos($field_id, 'em_') === 0) {
                    // Employer field
                    $employer_field = substr($field_id, 3);
                    $currvalue = $result2[$employer_field] ?? '';
                } else {
                    // Patient field
                    $currvalue = $result[$field_id] ?? '';
                }
                
                // Format DOB for display
                if ($field_id === 'DOB' && !empty($currvalue) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $currvalue)) {
                    $dt = DateTime::createFromFormat('Y-m-d', $currvalue);
                    if ($dt) $currvalue = $dt->format('m/d/Y');
                }
                
                $required_class = ($field_uor == 2) ? 'required' : '';
                echo "<div>";
                echo "<label class='add-patient-label $required_class'>" . xlt($field_title) . "</label>";
                
                // For DOB, add a class for custom datepicker format
                if ($field_id === 'DOB') {
                    $field['data_type'] = 4; // ensure it's a date
                    echo "<input type='text' class='add-patient-input datepicker-us' name='form_DOB' id='form_DOB' value='" . attr($currvalue) . "' placeholder='MM/DD/YYYY' autocomplete='off' />";
                } else {
                    // Apply proper CSS classes to generated form fields
                    $field['class'] = 'add-patient-input';
                    if ($field['data_type'] == 2) { // Dropdown/select
                        $field['class'] = 'add-patient-select';
                    }
                    generate_form_field($field, $currvalue);
                }
                echo "</div>";
            }
            echo '</div>';
            echo '</div>';
        }
        ?>
        <div class="add-patient-actions">
            <button type="submit" class="btn btn-save" id="submit_btn"><?php echo xla('Save Changes'); ?></button>
            <a class="btn btn-cancel" href="demographics.php" onclick="top.restoreSession()"><?php echo xla('Cancel'); ?></a>
            </div>
      </div>
    </div>
</form>

        <?php
// Note: parent.left_nav.setPatient() removed since this form is loaded in a modal context
// and doesn't need to update the parent navigation
?>

<?php echo $date_init; ?>
</script>

<!-- include support for the list-add selectbox feature -->
<?php include $GLOBALS['fileroot'] . "/library/options_listadd.inc"; ?>

<?php /*Include the validation script and rules for this form*/
$form_id = "DEM";
//LBF forms use the new validation depending on the global value
$use_validate_js = $GLOBALS['new_validate'];

?>
<?php  include_once("$srcdir/validation/validation_script.js.php");?>


<script>
    var duplicateFieldsArray=[];

    // Sidebar Navigation
    $(document).ready(function() {
        let currentSection = 0;
        const sections = $('.form-section');
        const menuItems = $('.sidebar-menu-item');
        const prevBtn = $('.nav-btn-prev');
        const nextBtn = $('.nav-btn-next');
        
        console.log('Edit Patient Form - Sections found:', sections.length);
        console.log('Edit Patient Form - Menu items found:', menuItems.length);
        console.log('Edit Patient Form - Form fields found:', $('.add-patient-grid input, .add-patient-grid select, .add-patient-grid textarea').length);
        
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
        
        // Initialize datepicker for DOB field
        $('.datepicker-us').datetimepicker({
            format: 'm/d/Y',
            timepicker: false,
            closeOnDateSelect: true,
            scrollInput: false,
            scrollMonth: false
        });
    });

//This code deals with demographics before save action -
    <?php if (($GLOBALS['gbl_edit_patient_form'] == '1') && (checkIfPatientValidationHookIsActive())) :?>
                //Use the Zend patient validation hook.
                //TODO - get the edit part of patient validation hook to work smoothly and then
                //       remove the closeBeforeOpening=1 in the url below.

        var f = $("form");

        // Use hook to open the controller and get the new patient validation .
        // when no params are sent this window will be closed from the zend controller.
        var url ='<?php echo  $GLOBALS['web_root'] . "/interface/modules/zend_modules/public/patientvalidation";?>';
        $("#submit_btn").attr("name","btnSubmit");
        $("#submit_btn").attr("id","btnSubmit");
        $("#btnSubmit").click(function( event ) {

      top.restoreSession();
                event.preventDefault();
      var formData = new FormData(f[0]);
      $.ajax({
        url: url,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(data) {
          if (data.success) {
            f[0].submit();
          } else {
            alert(data.message);
          }
        },
        error: function() {
          alert('Error occurred during validation');
        }
      });
    });
    <?php endif; ?>

    // Phone number formatting
    function phonekeyup(me) {
        var num = me.value.replace(/\D/g, '');
        if (num.length == 0) {
            me.value = '';
        } else if (num.length <= 3) {
            me.value = num;
        } else if (num.length <= 6) {
            me.value = num.substr(0, 3) + '-' + num.substr(3);
        } else {
            me.value = num.substr(0, 3) + '-' + num.substr(3, 3) + '-' + num.substr(6, 4);
        }
    }

    // Capitalize function
    function capitalizeMe(fld) {
        fld.value = fld.value.toUpperCase();
    }

    // Policy number formatting
    function policykeyup(me) {
        var num = me.value.replace(/\D/g, '');
        me.value = num;
    }

    // SSN formatting
    function ssnkeyup(me) {
        var num = me.value.replace(/\D/g, '');
        if (num.length == 0) {
            me.value = '';
        } else if (num.length <= 3) {
            me.value = num;
        } else if (num.length <= 5) {
            me.value = num.substr(0, 3) + '-' + num.substr(3);
        } else {
            me.value = num.substr(0, 3) + '-' + num.substr(3, 2) + '-' + num.substr(5, 4);
        }
    }

    // Insurance search function
    var insurance_index = 0;
    function ins_search(ins) {
        insurance_index = ins;
        var title = <?php echo xlj('Insurance Search/Select/Add'); ?>;
        var url = '<?php echo $GLOBALS['web_root']; ?>/interface/practice/ins_search.php';
        dlgopen(url, '_blank', 950, 600, '', title);
    }

    // Set insurance function
    function set_insurance(ins_id, ins_name) {
        var thesel = document.forms[0]['i' + insurance_index + 'provider'];
        thesel.value = ins_id;
        thesel.options[thesel.selectedIndex].text = ins_name;
    }
</script>

</body>
</html>
