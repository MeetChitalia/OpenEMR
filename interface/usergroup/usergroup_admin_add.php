<?php

/**
 * Add new user.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2017-2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("$srcdir/calendar.inc");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/erx_javascript.inc.php");

use OpenEMR\Common\Acl\AclExtended;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\Events\User\UserEditRenderEvent;
use OpenEMR\Menu\MainMenuRole;
use OpenEMR\Menu\PatientMenuRole;
use OpenEMR\Services\FacilityService;
use OpenEMR\Services\UserService;

$facilityService = new FacilityService();

if (!AclMain::aclCheckCore('admin', 'users')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Add User")]);
    exit;
}

$alertmsg = '';

?>
<html>
<head>

<?php Header::setupHeader(['common','opener']); ?>

<script src="checkpwd_validation.js"></script>

<!-- validation library -->
<!--//Not lbf forms use the new validation, please make sure you have the corresponding values in the list Page validation-->
<?php    $use_validate_js = 1;?>
<?php  require_once($GLOBALS['srcdir'] . "/validation/validation_script.js.php"); ?>
<?php
//Gets validation rules from Page Validation list.
//Note that for technical reasons, we are bypassing the standard validateUsingPageRules() call.
$collectthis = collectValidationPageRules("/interface/usergroup/usergroup_admin_add.php");
if (empty($collectthis)) {
    $collectthis = "undefined";
} else {
    $collectthis = json_sanitize($collectthis["new_user"]["rules"]);
}
?>
<script>

/*
* validation on the form with new client side validation (using validate.js).
* this enable to add new rules for this form in the pageValidation list.
* */
var collectvalidation = <?php echo $collectthis; ?>;

function trimAll(sString)
{
    while (sString.substring(0,1) == ' ')
    {
        sString = sString.substring(1, sString.length);
    }
    while (sString.substring(sString.length-1, sString.length) == ' ')
    {
        sString = sString.substring(0,sString.length-1);
    }
    return sString;
}

function submitform() {

    var valid = submitme(1, undefined, 'new_user', collectvalidation);
    if (!valid) return;

   top.restoreSession();

   //Checking if secure password is enabled or disabled.
   //If it is enabled and entered password is a weak password, alert the user to enter strong password.
    if(document.new_user.secure_pwd.value == 1){
        var password = trim(document.new_user.stiltskin.value);
        if(password != "") {
            var pwdresult = passwordvalidate(password);
            if(pwdresult === 0){
                alert(
                    <?php echo xlj('The password must be at least eight characters, and should'); ?> +
                    '\n' +
                    <?php echo xlj('contain at least three of the four following items:'); ?> +
                    '\n' +
                    <?php echo xlj('A number'); ?> +
                    '\n' +
                    <?php echo xlj('A lowercase letter'); ?> +
                    '\n' +
                    <?php echo xlj('An uppercase letter'); ?> +
                    '\n' +
                    <?php echo xlj('A special character'); ?> +
                    '\n' +
                    '(' +
                    <?php echo xlj('not a letter or number'); ?> +
                    ').' +
                    '\n' +
                    <?php echo xlj('For example:'); ?> +
                    ' healthCare@09'
                );
                return false;
            }
        }
    } //secure_pwd if ends here

    <?php if ($GLOBALS['erx_enable']) { ?>
   alertMsg='';
   f=document.forms[0];
   for(i=0;i<f.length;i++){
      if(f[i].type=='text' && f[i].value)
      {
         if(f[i].name == 'rumple')
         {
            alertMsg += checkLength(f[i].name,f[i].value,35);
            alertMsg += checkUsername(f[i].name,f[i].value);
         }
         else if(f[i].name == 'fname' || f[i].name == 'mname' || f[i].name == 'lname')
         {
            alertMsg += checkLength(f[i].name,f[i].value,35);
            alertMsg += checkUsername(f[i].name,f[i].value);
         }
         else if(f[i].name == 'federaltaxid')
         {
            alertMsg += checkLength(f[i].name,f[i].value,10);
            alertMsg += checkFederalEin(f[i].name,f[i].value);
         }
         else if(f[i].name == 'state_license_number')
         {
            alertMsg += checkLength(f[i].name,f[i].value,10);
            alertMsg += checkStateLicenseNumber(f[i].name,f[i].value);
         }
         else if(f[i].name == 'federaldrugid')
         {
            alertMsg += checkLength(f[i].name,f[i].value,30);
            alertMsg += checkAlphaNumeric(f[i].name,f[i].value);
         }
      }
   }
   if(alertMsg)
   {
      alert(alertMsg);
      return false;
   }
    <?php } // End erx_enable only include block?>

    let post_url = $("#new_user").attr("action");
    let request_method = $("#new_user").attr("method");
    let form_data = $("#new_user").serialize();

    $.ajax({
        url: post_url,
        type: request_method,
        data: form_data
    }).done(function (r) {
        if (r) {
            alert(r);
        } else {
            dlgclose('reload', false);
        }
    });

    return false;
}
function authorized_clicked() {
     var f = document.forms[0];
     f.calendar.disabled = !f.authorized.checked;
     f.calendar.checked  =  f.authorized.checked;
}

</script>
<style>
    .add-user-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        padding: 1.2rem 1rem 1rem 1rem;
        max-width: 700px;
        margin: 1.2rem auto;
    }
    .add-user-title {
        font-size: 1.2rem;
        font-weight: 700;
        margin-bottom: 0.7rem;
        color: #2a3b4d;
        text-align: center;
    }
    .add-user-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.7rem 1.2rem;
    }
    @media (max-width: 700px) {
        .add-user-grid {
            grid-template-columns: 1fr;
        }
    }
    .add-user-section {
        grid-column: 1 / -1;
        font-size: 1rem;
        font-weight: 600;
        color: #4A90E2;
        margin-top: 0.7rem;
        margin-bottom: 0.2rem;
        border-bottom: 1px solid #e6e8ec;
        padding-bottom: 0.1rem;
    }
    .add-user-label {
        font-weight: 600;
        color: #333;
        margin-bottom: 0.1rem;
        display: block;
    }
    .add-user-input, .add-user-select {
        width: 100%;
        padding: 0.45rem 0.7rem;
        border: 1.5px solid #E1E5E9;
        border-radius: 7px;
        font-size: 0.98rem;
        background: white;
        margin-bottom: 0.1rem;
    }
    .add-user-checkbox-group {
        display: flex;
        gap: 0.7rem;
        align-items: center;
        margin-bottom: 0.2rem;
    }
    .add-user-actions {
        grid-column: 1 / -1;
        display: flex;
        justify-content: flex-end;
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
</style>
</head>
<body class="body_top">

<div class="container">

<div class="add-user-card">
    <div class="add-user-title">
        <i class="fas fa-user-plus me-2" style="color: #4A90E2;"></i>
    </div>
    <form name='new_user' id="new_user" method='post' action="usergroup_admin.php" onsubmit="return submitform();">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
        <input type='hidden' name='mode' value='new_user'>
        <input type='hidden' name='secure_pwd' value="<?php echo attr($GLOBALS['secure_password']); ?>">
        <div class="add-user-grid">
            <div class="add-user-section"><?php echo xlt('Login Details'); ?></div>
            <div>
                <label class="add-user-label" for="rumple"><?php echo xlt('Username'); ?></label>
                <input type="text" name="rumple" id="rumple" class="add-user-input" required>
            </div>
            <div>
                <label class="add-user-label" for="google_signin_email"><?php echo xlt('Email'); ?> <span style="color: #dc3545;">*</span></label>
                <input type="email" name="google_signin_email" id="google_signin_email" class="add-user-input" required>
                <small style="color: #6c757d; font-size: 0.85rem; display: block; margin-top: 0.25rem;">
                    <i class="fa fa-info-circle"></i> <?php echo xlt('User will receive an email with temporary password'); ?>
                </small>
            </div>
            <div class="add-user-section"><?php echo xlt('Personal Info'); ?></div>
            <div>
                <label class="add-user-label" for="fname"><?php echo xlt('First Name'); ?></label>
                <input type="text" name='fname' id='fname' class="add-user-input" required>
            </div>
            <div>
                <label class="add-user-label" for="lname"><?php echo xlt('Last Name'); ?></label>
                <input type="text" name='lname' id='lname' class="add-user-input" required>
            </div>
            <div class="add-user-section"><?php echo xlt('Permissions & Settings'); ?></div>
            <div>
                <label class="add-user-label" for="facility_id"><?php echo xlt('Default Facility'); ?></label>
                <select name="facility_id" id="facility_id" class="add-user-select">
                    <?php
                    $fres = $facilityService->getAllServiceLocations();
                    if ($fres) {
                        foreach ($fres as $iter) {
                            ?>
                            <option value="<?php echo attr($iter['id']); ?>"><?php echo text($iter['name']); ?></option>
                            <?php
                        }
                    }
                    ?>
                </select>
            </div>
            <div>
                <label class="add-user-label" for="additional_facilities"><?php echo xlt('Additional Facilities Access'); ?></label>
                <select name="additional_facilities[]" id="additional_facilities" class="add-user-select" multiple size="5" style="height: 120px;">
                    <?php
                    $fres = $facilityService->getAllServiceLocations();
                    if ($fres) {
                        foreach ($fres as $iter) {
                            ?>
                            <option value="<?php echo attr($iter['id']); ?>"><?php echo text($iter['name']); ?></option>
                            <?php
                        }
                    }
                    ?>
                </select>
                <small class="text-muted"><?php echo xlt('Hold Ctrl/Cmd to select multiple facilities. User will have access to all selected facilities.'); ?></small>
            </div>
            <div>
                <label class="add-user-label" for="access_group"><?php echo xlt('Access Control'); ?></label>
                <select name="access_group" id="access_group" class="add-user-select">
                    <?php
                    $is_super_user = AclMain::aclCheckCore('admin', 'super');
                    $list_acl_groups = AclExtended::aclGetGroupTitleList($is_super_user ? true : false);
                    $default_acl_group = 'Administrators';
                    foreach ($list_acl_groups as $value) {
                        if ($is_super_user && $default_acl_group == $value) {
                            echo " <option value='" . attr($value) . "' selected>" . text(xl_gacl_group($value)) . "</option>\n";
                        } else {
                            echo " <option value='" . attr($value) . "'>" . text(xl_gacl_group($value)) . "</option>\n";
                        }
                    }
                    ?>
                </select>
            </div>
            <div>
                <label class="add-user-label" for="supervisor_id"><?php echo xlt('Supervisor'); ?></label>
                <select name="supervisor_id" id="supervisor_id" class="add-user-select">
                    <option value="0"><?php echo xlt('None'); ?></option>
                    <?php
                    // Get all authorized users (providers) who can be supervisors
                    $supervisor_res = sqlStatement("SELECT id, fname, lname, username FROM users WHERE authorized = 1 AND active = 1 ORDER BY lname, fname");
                    while ($supervisor_row = sqlFetchArray($supervisor_res)) {
                        echo "<option value='" . attr($supervisor_row['id']) . "'>" . text($supervisor_row['lname'] . ", " . $supervisor_row['fname']) . "</option>\n";
                    }
                    ?>
                </select>
            </div>
            <div class="add-user-checkbox-group">
                <label><input type='checkbox' name='authorized' value='1' onclick='authorized_clicked()'> <?php echo xlt('Provider'); ?></label>
                <label><input type='checkbox' name='calendar' disabled> <?php echo xlt('Calendar'); ?></label>
                <label><input type="checkbox" name="portal_user"> <?php echo xlt('Portal'); ?></label>
            </div>
            <div class="add-user-actions">
                <button type="submit" class="btn-save"><?php echo xlt('Save'); ?></button>
                <button type="button" class="btn-cancel" onclick="dlgclose('cancel', false);"><?php echo xlt('Cancel'); ?></button>
            </div>
        </div>
    </form>
</div>
<!-- End Modern Add User Form -->

</div>

<script>
// Prevent duplicate facility selection between default and additional facilities
document.addEventListener('DOMContentLoaded', function() {
    const defaultFacilitySelect = document.getElementById('facility_id');
    const additionalFacilitiesSelect = document.getElementById('additional_facilities');
    
    function updateAdditionalFacilities() {
        const selectedDefault = defaultFacilitySelect.value;
        
        // Reset additional facilities options
        Array.from(additionalFacilitiesSelect.options).forEach(option => {
            option.disabled = false;
            option.style.color = '';
        });
        
        // Disable the default facility option in additional facilities
        if (selectedDefault) {
            const defaultOption = additionalFacilitiesSelect.querySelector(`option[value="${selectedDefault}"]`);
            if (defaultOption) {
                defaultOption.disabled = true;
                defaultOption.style.color = '#ccc';
                defaultOption.selected = false;
            }
        }
    }
    
    function updateDefaultFacility() {
        const selectedAdditional = Array.from(additionalFacilitiesSelect.selectedOptions).map(option => option.value);
        
        // Reset default facility options
        Array.from(defaultFacilitySelect.options).forEach(option => {
            option.disabled = false;
            option.style.color = '';
        });
        
        // Disable selected additional facilities in default facility
        selectedAdditional.forEach(facilityId => {
            const option = defaultFacilitySelect.querySelector(`option[value="${facilityId}"]`);
            if (option) {
                option.disabled = true;
                option.style.color = '#ccc';
                option.selected = false;
            }
        });
    }
    
    // Add event listeners
    defaultFacilitySelect.addEventListener('change', updateAdditionalFacilities);
    additionalFacilitiesSelect.addEventListener('change', updateDefaultFacility);
    
    // Initial update
    updateAdditionalFacilities();
});
</script>

</body>
</html>
