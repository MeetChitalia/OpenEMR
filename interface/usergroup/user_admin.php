<?php

/**
 * Edit user.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Daniel Pflieger <daniel@mi-squared.com> <daniel@growlingflea.com>
 * @author    Ken Chapple <ken@mi-squared.com>
 * @copyright Copyright (c) 2018-2019 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2021 Daniel Pflieger <daniel@mi-squared.com> <daniel@growlingflea.com>
 * @copyright Copyright (c) 2021 Ken Chapple <ken@mi-squared.com>
 * @copyright Copyright (c) 2021 Rod Roark <rod@sunsetsystems.com>
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
use OpenEMR\Menu\MainMenuRole;
use OpenEMR\Menu\PatientMenuRole;
use OpenEMR\Services\FacilityService;
use OpenEMR\Services\UserService;
use OpenEMR\Events\User\UserEditRenderEvent;

if (!empty($_GET)) {
    if (!CsrfUtils::verifyCsrfToken($_GET["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }
}

$facilityService = new FacilityService();

if (!AclMain::aclCheckCore('admin', 'users')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Edit User")]);
    exit;
}

if (!$_GET["id"]) {
    exit();
}

$res = sqlStatement("select * from users where id=?", array($_GET["id"]));
for ($iter = 0; $row = sqlFetchArray($res); $iter++) {
                $result[$iter] = $row;
}

$iter = $result[0];

// Add missing variables
$is_super_user = AclMain::aclCheckCore('admin', 'super');
$selected_user_is_superuser = false; // Default value, can be enhanced if needed
$disabled_save = ''; // Default value, can be enhanced if needed

// Check if selected user is superuser
$selected_user_acl_groups = AclExtended::aclGetGroupTitles($iter["username"]);
foreach ($selected_user_acl_groups as $group) {
    if (AclExtended::isGroupIncludeSuperuser($group)) {
        $selected_user_is_superuser = true;
        break;
    }
}

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
$collectthis = collectValidationPageRules("/interface/usergroup/user_admin.php");
if (empty($collectthis)) {
    $collectthis = "undefined";
} else {
    $collectthis = json_sanitize($collectthis["user_form"]["rules"]);
}
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
.add-user-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    padding: 1.2rem 1rem 1rem 1rem;
    max-width: 900px;
    margin: 1.2rem auto;
}
.add-user-title {
    font-size: 1.2rem;
    font-weight: 700;
    margin-bottom: 1.5rem;
    color: #2a3b4d;
    text-align: center;
}
.add-user-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.7rem 1.2rem;
}
@media (max-width: 900px) {
    .add-user-grid {
        grid-template-columns: 1fr;
    }
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
.add-user-actions {
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
</style>
</head>
<body class="body_top">

<div class="add-user-card">
    <div class="add-user-title">
        <i class="fas fa-user-shield me-2" style="color: #4A90E2;"></i>
    </div>
    <form name="user_form" id="user_form" method="POST" action="usergroup_admin.php" onsubmit="return submitform();">
<input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
<input type="hidden" name="mode" value="update" />
<input type="hidden" name="id" value="<?php echo attr($iter['id']); ?>" />
<input type="hidden" name="privatemode" value="user_admin" />
        <div class="add-user-grid">
            <!-- Username -->
            <div>
                <label class="add-user-label required"><?php echo xlt('Username'); ?></label>
                <input type="text" name="username" class="add-user-input form-control" value="<?php echo attr($iter["username"]); ?>" disabled>
            </div>
            <!-- Password reset button, only if not LDAP -->
<?php if (empty($GLOBALS['gbl_ldap_enabled']) || empty($GLOBALS['gbl_ldap_exclusions'])) { ?>
            <div>
                <button type="button" id="sendResetLink" class="btn btn-warning" style="margin-top: 0.5rem; width: 100%;">
                    <i class="fa fa-envelope"></i> <?php echo xlt('Send Reset Password Link'); ?>
                </button>
            </div>
<?php } ?>
            <!-- Provider, Calendar, Portal, Active, Clear 2FA -->
            <div>
                <label class="add-user-label"><?php echo xlt('Provider / Calendar / Portal / Active'); ?></label>
                <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: center;">
                    <span><?php echo xlt('Clear 2FA'); ?>: <input type="checkbox" name="clear_2fa" value="1" /></span>
                    <span><?php echo xlt('Provider'); ?>: <input type="checkbox" name="authorized" onclick="authorized_clicked()"<?php if ($iter["authorized"]) { echo " checked"; } ?> /></span>
                    <span><?php echo xlt('Calendar'); ?>: <input type="checkbox" name="calendar"<?php if ($iter["calendar"]) { echo " checked"; } if (!$iter["authorized"]) { echo " disabled"; } ?> /></span>
                    <span><?php echo xlt('Portal'); ?>: <input type="checkbox" name="portal_user" <?php if ($iter["portal_user"]) { echo " checked"; } ?> /></span>
                    <span><?php echo xlt('Active'); ?>: <input type="checkbox" name="active"<?php echo ($iter["active"]) ? " checked" : ""; ?>/></span>
                </div>
            </div>
            <!-- First Name -->
            <div>
                <label class="add-user-label required"><?php echo xlt('First Name'); ?></label>
                <input type="text" name="fname" id="fname" class="add-user-input form-control" value="<?php echo attr($iter["fname"]); ?>">
            </div>
            <!-- Last Name -->
            <div>
                <label class="add-user-label required"><?php echo xlt('Last Name'); ?></label>
                <input type="text" name="lname" id="lname" class="add-user-input form-control" value="<?php echo attr($iter["lname"]); ?>">
            </div>
            <!-- Default Facility -->
            <div>
                <label class="add-user-label"><?php echo xlt('Default Facility'); ?></label>
                <select name="facility_id" class="add-user-select form-control">
<?php
$fres = $facilityService->getAllServiceLocations();
if ($fres) {
    for ($iter2 = 0; $iter2 < sizeof($fres); $iter2++) {
                $result[$iter2] = $fres[$iter2];
    }
    foreach ($result as $iter2) {
        ?>
                        <option value="<?php echo attr($iter2['id']); ?>" <?php if ($iter['facility_id'] == $iter2['id']) { echo "selected"; } ?>><?php echo text($iter2['name']); ?></option>
        <?php
    }
    }
    ?>
  </select>
            </div>
            <!-- Additional Facilities Access -->
            <div>
                <label class="add-user-label"><?php echo xlt('Additional Facilities Access'); ?></label>
                <select name="additional_facilities[]" class="add-user-select form-control" multiple size="5" style="height: 120px;">
<?php
// Get current user's additional facilities
$current_facilities = [];
$facility_result = sqlStatement("SELECT facility_id FROM users_facility WHERE tablename = 'users' AND table_id = ? AND facility_id != ?", array($iter['id'], $iter['facility_id']));
while ($facility_row = sqlFetchArray($facility_result)) {
    $current_facilities[] = $facility_row['facility_id'];
}

$fres = $facilityService->getAllServiceLocations();
if ($fres) {
    for ($iter2 = 0; $iter2 < sizeof($fres); $iter2++) {
        $result[$iter2] = $fres[$iter2];
    }
    foreach ($result as $iter2) {
        $selected = in_array($iter2['id'], $current_facilities) ? 'selected' : '';
        ?>
                        <option value="<?php echo attr($iter2['id']); ?>" <?php echo $selected; ?>><?php echo text($iter2['name']); ?></option>
        <?php
    }
}
?>
                </select>
                <small class="text-muted"><?php echo xlt('Hold Ctrl/Cmd to select multiple facilities. User will have access to all selected facilities.'); ?></small>
            </div>
            <!-- Email -->
            <div>
                <label class="add-user-label"><?php echo xlt('Email'); ?></label>
                <input type="text" name="google_signin_email" class="add-user-input form-control" value="<?php echo attr($iter["google_signin_email"]); ?>">
            </div>
            <!-- Supervisor -->
            <div>
                <label class="add-user-label"><?php echo xlt('Supervisor'); ?></label>
                <select name="supervisor_id" class="add-user-select form-control">
                    <option value="0"><?php echo xlt('None'); ?></option>
    <?php
                    $supervisor_res = sqlStatement("SELECT id, fname, lname, username FROM users WHERE authorized = 1 AND active = 1 AND id != ? ORDER BY lname, fname", array($_GET['id']));
                    while ($supervisor_row = sqlFetchArray($supervisor_res)) {
                        echo "<option value='" . attr($supervisor_row['id']) . "'";
                        if ($iter['supervisor_id'] == $supervisor_row['id']) {
        echo " selected";
    }
                        echo ">" . text($supervisor_row['lname'] . ", " . $supervisor_row['fname']) . "</option>\n";
    }
    ?>
   </select>
            </div>
            <!-- Access Control -->
            <div>
                <label class="add-user-label"><?php echo xlt('Access Control'); ?></label>
                <select id="access_group_id" name="access_group[]" multiple class="add-user-select form-control">
<?php
$list_acl_groups = AclExtended::aclGetGroupTitleList($is_super_user || $selected_user_is_superuser);
$username_acl_groups = AclExtended::aclGetGroupTitles($iter["username"]);
foreach ($list_acl_groups as $value) {
    $tmp = AclExtended::iHaveGroupPermissions($value) ? '' : 'disabled ';
    if ($username_acl_groups && in_array($value, $username_acl_groups)) {
        $tmp .= 'selected ';
    }
    echo " <option value='" . attr($value) . "' $tmp>" . text(xl_gacl_group($value)) . "</option>\n";
}
?>
                </select>
            </div>
            <!-- Default Billing Facility -->
            <div>
                <label class="add-user-label"><?php echo xlt('Default Billing Facility'); ?></label>
                <select name="billing_facility_id" class="add-user-select form-control">
            <?php
            $fres = $facilityService->getAllBillingLocations();
            if ($fres) {
                $billResults = [];
                for ($iter2 = 0; $iter2 < sizeof($fres); $iter2++) {
                    $billResults[$iter2] = $fres[$iter2];
                }
                foreach ($billResults as $iter2) {
                    ?>
                        <option value="<?php echo attr($iter2['id']); ?>" <?php if ($iter['billing_facility_id'] == $iter2['id']) { echo "selected"; } ?>><?php echo text($iter2['name']); ?></option>
                    <?php
                }
            }
            ?>
                </select>
            </div>
            <!-- Add more fields as needed, following the same pattern -->
        </div>
        <div class="add-user-actions">
            <button type="submit" class="btn btn-save" <?php echo $disabled_save; ?>><?php echo xlt('Save'); ?></button>
            <a class="btn btn-cancel" id="cancel" href="#"><?php echo xlt('Cancel'); ?></a>
        </div>
    </form>
</div>

<script>
function submitform() {
    top.restoreSession();
    return true;
}

function authorized_clicked() {
    var f = document.forms[0];
    f.calendar.disabled = !f.authorized.checked;
    f.calendar.checked = f.authorized.checked;
}

$(function () {
    $("#cancel").click(function() {
          dlgclose();
     });

    $('#sendResetLink').on('click', function() {
        var userId = <?php echo json_encode($iter['id']); ?>;
        var csrfToken = $('input[name="csrf_token_form"]').val();
        $.ajax({
            url: 'usergroup_admin.php',
            type: 'POST',
            data: {
                mode: 'send_reset_link',
                id: userId,
                csrf_token_form: csrfToken
            },
            success: function(response) {
                alert(response);
            },
            error: function(xhr) {
                alert('Error: ' + xhr.responseText);
            }
        });
    });
    });
    
    // Prevent duplicate facility selection between default and additional facilities
    function updateAdditionalFacilities() {
        const defaultFacilitySelect = document.querySelector('select[name="facility_id"]');
        const additionalFacilitiesSelect = document.querySelector('select[name="additional_facilities[]"]');
        
        if (!defaultFacilitySelect || !additionalFacilitiesSelect) return;
        
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
        const defaultFacilitySelect = document.querySelector('select[name="facility_id"]');
        const additionalFacilitiesSelect = document.querySelector('select[name="additional_facilities[]"]');
        
        if (!defaultFacilitySelect || !additionalFacilitiesSelect) return;
        
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
    
    // Add event listeners when document is ready
    $(document).ready(function() {
        const defaultFacilitySelect = document.querySelector('select[name="facility_id"]');
        const additionalFacilitiesSelect = document.querySelector('select[name="additional_facilities[]"]');
        
        if (defaultFacilitySelect) {
            defaultFacilitySelect.addEventListener('change', updateAdditionalFacilities);
        }
        
        if (additionalFacilitiesSelect) {
            additionalFacilitiesSelect.addEventListener('change', updateDefaultFacility);
        }
        
        // Initial update
        updateAdditionalFacilities();
    });
</script>

</BODY>

</HTML>
