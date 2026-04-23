<?php

/**
 * This script assigns ACL 'Emergency login'.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Roberto Vasquez <robertogagliotta@gmail.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Daniel Pflieger <daniel@mi-squared.com> <daniel@growlingflea.com>
 * @author    Ken Chapple <ken@mi-squared.com>
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Robert DOwn <robertdown@live.com>
 * @copyright Copyright (c) 2015 Roberto Vasquez <robertogagliotta@gmail.com>
 * @copyright Copyright (c) 2017-2019 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2021 Daniel Pflieger <daniel@mi-squared.com> <daniel@growlingflea.com>
 * @copyright Copyright (c) 2021 Ken Chapple <ken@mi-squared.com>
 * @copyright Copyright (c) 2021 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2022 Robert Down <robertdown@live.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$sessionAllowWrite = true;
require_once("../globals.php");
require_once("$srcdir/auth.inc");

use OpenEMR\Common\Acl\AclExtended;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Auth\AuthUtils;
use OpenEMR\Common\Auth\AuthHash;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\Services\UserService;
use OpenEMR\Events\User\UserUpdatedEvent;
use OpenEMR\Events\User\UserCreatedEvent;

// Function to generate the proper server URL for password setup links
function getServerUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $port = '';

    // Add port if it's not the default port
    if (($protocol === 'http' && $_SERVER['SERVER_PORT'] != 80) ||
        ($protocol === 'https' && $_SERVER['SERVER_PORT'] != 443)) {
        $port = ':' . $_SERVER['SERVER_PORT'];
    }

    // Get the current site ID
    $site_id = $_SESSION['site_id'] ?? 'default';

    return $protocol . '://' . $host . $port . $GLOBALS['web_root'];
}

// Function to generate password setup URL with site ID
function getPasswordSetupUrl($temp_password) {
    $site_id = $_SESSION['site_id'] ?? 'default';
    return getServerUrl() . "/interface/login/password_setup.php?site=" . urlencode($site_id) . "&token=" . urlencode($temp_password);
}

if (!empty($_POST)) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }
}

if (!empty($_GET)) {
    if (!CsrfUtils::verifyCsrfToken($_GET["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }
}

if (!AclMain::aclCheckCore('admin', 'users')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("User / Groups")]);
    exit;
}

if (!AclMain::aclCheckCore('admin', 'super')) {
    //block non-administrator user from create administrator
    if (!empty($_POST['access_group'])) {
        foreach ($_POST['access_group'] as $aro_group) {
            if (AclExtended::isGroupIncludeSuperuser($aro_group)) {
                die(xlt('Saving denied'));
            };
        }
    }
    if (($_POST['mode'] ?? '') === 'update') {
        //block non-administrator user from update administrator
        $user_service = new UserService();
        $user = $user_service->getUser($_POST['id']);
        $aro_groups = AclExtended::aclGetGroupTitles($user['username']);
        foreach ($aro_groups as $aro_group) {
            if (AclExtended::isGroupIncludeSuperuser($aro_group)) {
                die(xlt('Saving denied'));
            };
        }
    }
}

// Handle user deletion
if (!empty($_POST['delete_user_id']) && AclMain::aclCheckCore('admin', 'users')) {
    $delete_id = intval($_POST['delete_user_id']);
    // Prevent deleting self
    if ($delete_id != $_SESSION['authUserID']) {
        // Get username before deletion for cleanup
        $result = sqlStatement("SELECT username FROM users WHERE id = ?", [$delete_id]);
        $user_to_delete = sqlFetchArray($result);
        if ($user_to_delete) {
            $username = $user_to_delete['username'];

            // Delete from users table
            sqlStatement("DELETE FROM users WHERE id = ?", [$delete_id]);
            // Delete from groups table
            sqlStatement("DELETE FROM `groups` WHERE user = ?", [$username]);
            // Delete from login_mfa_registrations
            sqlStatement("DELETE FROM login_mfa_registrations WHERE user_id = ?", [$delete_id]);
            // Delete from users_secure
            sqlStatement("DELETE FROM users_secure WHERE id = ?", [$delete_id]);
            // Delete from facility_user_ids
            sqlStatement("DELETE FROM facility_user_ids WHERE uid = ?", [$delete_id]);
            // Delete from users_facility
            sqlStatement("DELETE FROM users_facility WHERE table_id = ? AND tablename = 'users'", [$delete_id]);

            $alertmsg = xlt('User deleted successfully.');
        } else {
            $alertmsg = xlt('User not found.');
        }
    } else {
        $alertmsg = xlt('You cannot delete your own account.');
    }
}

$alertmsg = '';
$bg_msg = '';
$set_active_msg = 0;
$show_message = 0;

/* Sending a mail to the admin when the breakglass user is activated only if $GLOBALS['Emergency_Login_email'] is set to 1 */
if (!empty($_POST['access_group']) && is_array($_POST['access_group'])) {
    $bg_count = count($_POST['access_group']);
    $mail_id = explode(".", $SMTP_HOST);
    for ($i = 0; $i < $bg_count; $i++) {
        if (($_POST['access_group'][$i] == "Emergency Login") && ($_POST['active'] == 'on') && ($_POST['pre_active'] == 0)) {
            if (($_POST['get_admin_id'] == 1) && ($_POST['admin_id'] != "")) {
                $res = sqlStatement("select username from users where id= ? ", array($_POST["id"]));
                $row = sqlFetchArray($res);
                $uname = $row['username'];
                $mail = new MyMailer();
                $mail->From = $GLOBALS["practice_return_email_path"];
                $mail->FromName = "Administrator OpenEMR";
                $text_body = "Hello Security Admin,\n\n The Emergency Login user " . $uname .
                    " was activated at " . date('l jS \of F Y h:i:s A') . " \n\nThanks,\nAdmin OpenEMR.";
                $mail->Body = $text_body;
                $mail->Subject = "Emergency Login User Activated";
                $mail->AddAddress($_POST['admin_id']);
                $mail->Send();
            }
        }
    }
}

/* To refresh and save variables in mail frame */
if (isset($_POST["privatemode"]) && $_POST["privatemode"] == "user_admin") {
    if ($_POST["mode"] == "update") {
        $accessGroups = [];
        if (isset($_POST['access_group'])) {
            if (is_array($_POST['access_group'])) {
                foreach ($_POST['access_group'] as $groupTitle) {
                    if (is_scalar($groupTitle)) {
                        $groupTitle = trim((string)$groupTitle);
                        if ($groupTitle !== '') {
                            $accessGroups[] = $groupTitle;
                        }
                    }
                }
            } elseif (is_scalar($_POST['access_group'])) {
                $groupTitle = trim((string)$_POST['access_group']);
                if ($groupTitle !== '') {
                    $accessGroups[] = $groupTitle;
                }
            }
        }

        $user_data = sqlFetchArray(sqlStatement("select * from users where id= ? ", array($_POST["id"])));
        $existingAccessGroups = AclExtended::aclGetGroupTitles($user_data["username"]);
        if (!is_array($existingAccessGroups)) {
            $existingAccessGroups = [];
        }

        $preservedAccessGroups = [];
        foreach ($existingAccessGroups as $existingGroupTitle) {
            if (!AclExtended::iHaveGroupPermissions($existingGroupTitle)) {
                $preservedAccessGroups[] = $existingGroupTitle;
            }
        }
        $finalAccessGroups = array_values(array_unique(array_merge($preservedAccessGroups, $accessGroups)));

        if (isset($_POST["username"])) {
            sqlStatement("update users set username=? where id= ? ", array(trim($_POST["username"]), $_POST["id"]));
            sqlStatement("update `groups` set user=? where user= ?", array(trim($_POST["username"]), $user_data["username"]));
        }

        if (isset($_POST["taxid"]) && $_POST["taxid"]) {
            sqlStatement("update users set federaltaxid=? where id= ? ", array($_POST["taxid"], $_POST["id"]));
        }

        if (isset($_POST["state_license_number"]) && $_POST["state_license_number"]) {
            sqlStatement("update users set state_license_number=? where id= ? ", array($_POST["state_license_number"], $_POST["id"]));
        }

        if (isset($_POST["drugid"]) && $_POST["drugid"]) {
            sqlStatement("update users set federaldrugid=? where id= ? ", array($_POST["drugid"], $_POST["id"]));
        }

        if (isset($_POST["upin"]) && $_POST["upin"]) {
            sqlStatement("update users set upin=? where id= ? ", array($_POST["upin"], $_POST["id"]));
        }

        if (isset($_POST["npi"]) && $_POST["npi"]) {
            sqlStatement("update users set npi=? where id= ? ", array($_POST["npi"], $_POST["id"]));
        }

        if (isset($_POST["taxonomy"]) && $_POST["taxonomy"]) {
            sqlStatement("update users set taxonomy = ? where id= ? ", array($_POST["taxonomy"], $_POST["id"]));
        }

        if (isset($_POST["lname"])) {
            sqlStatement("update users set lname=? where id= ? ", array(trim((string)$_POST["lname"]), $_POST["id"]));
        }

        if (isset($_POST["job"]) && $_POST["job"]) {
            sqlStatement("update users set specialty=? where id= ? ", array($_POST["job"], $_POST["id"]));
        }

        if (isset($_POST["mname"]) && $_POST["mname"]) {
            sqlStatement("update users set mname=? where id= ? ", array($_POST["mname"], $_POST["id"]));
        }

        if ($_POST["facility_id"]) {
            sqlStatement("update users set facility_id = ? where id = ? ", array($_POST["facility_id"], $_POST["id"]));
            //(CHEMED) Update facility name when changing the id
            sqlStatement("UPDATE users, facility SET users.facility = facility.name WHERE facility.id = ? AND users.id = ?", array($_POST["facility_id"], $_POST["id"]));
            //END (CHEMED)
        }

        // Handle additional facilities access for user updates
        if (!empty($GLOBALS['gbl_fac_warehouse_restrictions']) || !empty($GLOBALS['restrict_user_facility'])) {
            // First, remove all existing additional facility assignments (keep default facility)
            sqlStatement("DELETE FROM users_facility WHERE tablename = 'users' AND table_id = ? AND facility_id != ?", array($_POST["id"], $_POST["facility_id"]));

            // Then add new additional facilities
            if (!empty($_POST["additional_facilities"]) && is_array($_POST["additional_facilities"])) {
                foreach ($_POST["additional_facilities"] as $facility_id) {
                    // Skip if it's the same as default facility to avoid duplicates
                    if ($facility_id != $_POST["facility_id"]) {
                        sqlStatement(
                            "INSERT INTO users_facility SET tablename = ?, table_id = ?, facility_id = ?, warehouse_id = ?",
                            array('users', $_POST["id"], $facility_id, '')
                        );
                        error_log("Updated user additional facility access: " . $facility_id);
                    }
                }
            }
        }

        if ($_POST["billing_facility_id"]) {
            sqlStatement("update users set billing_facility_id = ? where id = ? ", array($_POST["billing_facility_id"], $_POST["id"]));
            //(CHEMED) Update facility name when changing the id
            sqlStatement("UPDATE users, facility SET users.billing_facility = facility.name WHERE facility.id = ? AND users.id = ?", array($_POST["billing_facility_id"], $_POST["id"]));
            //END (CHEMED)
        }

        if (!empty($GLOBALS['gbl_fac_warehouse_restrictions']) || !empty($GLOBALS['restrict_user_facility'])) {
            if (empty($_POST["schedule_facility"])) {
                $_POST["schedule_facility"] = array();
            }
            $tmpres = sqlStatement(
                "SELECT * FROM users_facility WHERE " .
                "tablename = ? AND table_id = ?",
                array('users', $_POST["id"])
            );
            // $olduf will become an array of entries to delete.
            $olduf = array();
            while ($tmprow = sqlFetchArray($tmpres)) {
                $olduf[$tmprow['facility_id'] . '/' . $tmprow['warehouse_id']] = true;
            }
            // Now process the selection of facilities and warehouses.
            foreach ($_POST["schedule_facility"] as $tqvar) {
                if (($i = strpos($tqvar, '/')) !== false) {
                    $facid = substr($tqvar, 0, $i);
                    $whid = substr($tqvar, $i + 1);
                    // If there was also a facility-only selection for this warehouse then remove it.
                    if (isset($olduf["$facid/"])) {
                        $olduf["$facid/"] = true;
                    }
                } else {
                    $facid = $tqvar;
                    $whid = '';
                }
                if (!isset($olduf["$facid/$whid"])) {
                    sqlStatement(
                        "INSERT INTO users_facility SET tablename = ?, table_id = ?, " .
                        "facility_id = ?, warehouse_id = ?",
                        array('users', $_POST["id"], $facid, $whid)
                    );
                }
                $olduf["$facid/$whid"] = false;
            }
            // Now delete whatever is left over for this user.
            foreach ($olduf as $key => $value) {
                if ($value && ($i = strpos($key, '/')) !== false) {
                    $facid = substr($key, 0, $i);
                    $whid = substr($key, $i + 1);
                    sqlStatement(
                        "DELETE FROM users_facility WHERE " .
                        "tablename = ? AND table_id = ? AND facility_id = ? AND warehouse_id = ?",
                        array('users', $_POST["id"], $facid, $whid)
                    );
                }
            }
        }

        if (isset($_POST["fname"])) {
            sqlStatement("update users set fname=? where id= ? ", array(trim((string)$_POST["fname"]), $_POST["id"]));
        }

        if (isset($_POST['default_warehouse'])) {
            sqlStatement("UPDATE users SET default_warehouse = ? WHERE id = ?", array($_POST['default_warehouse'], $_POST["id"]));
        }

        if (isset($_POST['irnpool'])) {
            sqlStatement("UPDATE users SET irnpool = ? WHERE id = ?", array($_POST['irnpool'], $_POST["id"]));
        }

        if (!empty($_POST['clear_2fa'])) {
            sqlStatement("DELETE FROM login_mfa_registrations WHERE user_id = ?", array($_POST['id']));
        }

        // Update user password if clearPass is provided (no admin password required)
        if (isset($_POST["clearPass"]) && $_POST["clearPass"]) {
            $authUtilsUpdatePassword = new AuthUtils();
            // Use empty string for adminPass since it's no longer required
            $success = $authUtilsUpdatePassword->updatePassword($_SESSION['authUserID'], $_POST['id'], '', $_POST['clearPass']);
            if (!$success) {
                error_log(errorLogEscape($authUtilsUpdatePassword->getErrorMessage()));
                $alertmsg .= $authUtilsUpdatePassword->getErrorMessage();
            }
        }

        $tqvar  = (!empty($_POST["authorized"])) ? 1 : 0;
        $actvar = (!empty($_POST["active"]))     ? 1 : 0;
        $calvar = (!empty($_POST["calendar"]))   ? 1 : 0;
        $portalvar = (!empty($_POST["portal_user"])) ? 1 : 0;

        sqlStatement("UPDATE users SET authorized = ?, active = ?, " .
        "calendar = ?, portal_user = ?, see_auth = ? WHERE " .
        "id = ? ", array($tqvar, $actvar, $calvar, $portalvar, isset($_POST['see_auth']) ? $_POST['see_auth'] : '', $_POST["id"]));
      //Display message when Emergency Login user was activated
        if (is_countable($finalAccessGroups)) {
            $bg_count = count($finalAccessGroups);
            for ($i = 0; $i < $bg_count; $i++) {
                if (($finalAccessGroups[$i] == "Emergency Login") && ($_POST['pre_active'] == 0) && ($actvar == 1)) {
                    $show_message = 1;
                }
            }

            if (!empty($finalAccessGroups)) {
                $access_groups = $finalAccessGroups;
                $bg_count = count($access_groups);
                for ($i = 0; $i < $bg_count; $i++) {
                    if (($access_groups[$i] == "Emergency Login") && ($_POST['user_type']) == "" && ($_POST['check_acl'] == 1) && ($_POST['active']) != "") {
                        $set_active_msg = 1;
                    }
                }
            }
        }

        if (isset($_POST["comments"]) && $_POST["comments"]) {
            sqlStatement("update users set info = ? where id = ? ", array($_POST["comments"], $_POST["id"]));
        }

        $erxrole = isset($_POST['erxrole']) ? $_POST['erxrole'] : '';
        sqlStatement("update users set newcrop_user_role = ? where id = ? ", array($erxrole, $_POST["id"]));

        if (isset($_POST["physician_type"]) && $_POST["physician_type"]) {
            sqlStatement("update users set physician_type = ? where id = ? ", array($_POST["physician_type"], $_POST["id"]));
        }

        if (isset($_POST["main_menu_role"]) && $_POST["main_menu_role"]) {
              $mainMenuRole = filter_input(INPUT_POST, 'main_menu_role');
              sqlStatement("update `users` set `main_menu_role` = ? where `id` = ? ", array($mainMenuRole, $_POST["id"]));
        }

        if (isset($_POST["patient_menu_role"]) && $_POST["patient_menu_role"]) {
            $patientMenuRole = filter_input(INPUT_POST, 'patient_menu_role');
            sqlStatement("update `users` set `patient_menu_role` = ? where `id` = ? ", array($patientMenuRole, $_POST["id"]));
        }

        if (isset($_POST["erxprid"]) && $_POST["erxprid"]) {
            sqlStatement("update users set weno_prov_id = ? where id = ? ", array($_POST["erxprid"], $_POST["id"]));
        }

        if (isset($_POST["supervisor_id"])) {
            sqlStatement("update users set supervisor_id = ? where id = ? ", array((int)$_POST["supervisor_id"], $_POST["id"]));
        }

        // ============================================================
        // ✅ GENERIC DUPLICATE EMAIL CHECK (UPDATE MODE)
        // Prevent SQL "Query Error" when email is already used by another user
        // ============================================================
        if (isset($_POST["google_signin_email"]) && !empty($_POST["google_signin_email"])) {
            $email = trim($_POST["google_signin_email"]);
            $dup = sqlQueryNoLog(
                "SELECT id FROM users WHERE google_signin_email = ? AND id != ? LIMIT 1",
                [$email, (int)$_POST["id"]]
            );
            if (!empty($dup['id'])) {
                die(xlt("Email already exists. Please use a different email."));
            }
        }

        if (isset($_POST["google_signin_email"])) {
            if (empty($_POST["google_signin_email"])) {
                $googleSigninEmail = null;
            } else {
                $googleSigninEmail = $_POST["google_signin_email"];
            }
            sqlStatement("update users set google_signin_email = ? where id = ? ", array($googleSigninEmail, $_POST["id"]));
        }

        sqlStatement("DELETE FROM `groups` WHERE `user` = ?", array($user_data["username"]));
        foreach ($finalAccessGroups as $groupTitle) {
            sqlStatement(
                "INSERT INTO `groups` (`name`, `user`) VALUES (?, ?)",
                array($groupTitle, $user_data["username"])
            );
        }

        // Set the access control group of user
        $user_data = sqlFetchArray(sqlStatement("select username from users where id= ?", array($_POST["id"])));
        AclExtended::setUserAro(
            $finalAccessGroups,
            $user_data["username"],
            (isset($_POST['fname']) ? $_POST['fname'] : ''),
            (isset($_POST['mname']) ? $_POST['mname'] : ''),
            (isset($_POST['lname']) ? $_POST['lname'] : '')
        );

        // TODO: why are we sending $user_data here when its overwritten with just the 'username' of the user updated
        // instead of the entire user data?  This makes the pre event data not very useful w/o doing a database hit...
        $userUpdatedEvent = new UserUpdatedEvent($user_data, $_POST);
        $GLOBALS["kernel"]->getEventDispatcher()->dispatch(UserUpdatedEvent::EVENT_HANDLE, $userUpdatedEvent, 10);

        if (trim((string) $alertmsg) === '') {
            $alertmsg = xlt('User saved successfully.');
        }
    }
}

/* To refresh and save variables in mail frame  - Arb*/
if (isset($_POST["mode"])) {
    if ($_POST["mode"] == "new_user") {
        if (empty($_POST["authorized"]) || $_POST["authorized"] != "1") {
            $_POST["authorized"] = 0;
        }

        $calvar = (!empty($_POST["calendar"])) ? 1 : 0;
        $portalvar = (!empty($_POST["portal_user"])) ? 1 : 0;

        // Check if username already exists globally
        $res = sqlQueryNoLog("select username from users where username = '" . add_escape_custom(trim($_POST['rumple'])) . "'");
        $doit = true;
        if ($res !== false && !empty($res['username'])) {
            $doit = false;
            $alertmsg .= xl('Username already exists. Please choose a different username.');
        }

        // ============================================================
        // ✅ GENERIC DUPLICATE EMAIL CHECK (CREATE MODE)
        // Blocks duplicates BEFORE INSERT so no SQL "Query Error"
        // ============================================================
        $user_email = trim($_POST['google_signin_email'] ?? '');
        if ($doit && $user_email !== '') {
            $dupEmail = sqlQueryNoLog(
                "SELECT id, username FROM users WHERE google_signin_email = ? LIMIT 1",
                [$user_email]
            );

            if (!empty($dupEmail['id'])) {
                $doit = false;
                $alertmsg .= xl('Email already exists. Please use a different email.');
            }
        }

        if ($doit == true) {
            $expires_at = date('Y-m-d H:i:s', strtotime('+72 hours'));
            // $user_email already computed above

            // Build the SQL query properly
            $insertUserSQL = "INSERT INTO users SET " .
                "username = '" . add_escape_custom(trim((isset($_POST['rumple']) ? $_POST['rumple'] : ''))) . "', " .
                "password = '', " .
                "fname = '" . add_escape_custom(trim((isset($_POST['fname']) ? $_POST['fname'] : ''))) . "', " .
                "mname = '" . add_escape_custom(trim((isset($_POST['mname']) ? $_POST['mname'] : ''))) . "', " .
                "lname = '" . add_escape_custom(trim((isset($_POST['lname']) ? $_POST['lname'] : ''))) . "', " .
                "google_signin_email = '" . add_escape_custom($user_email) . "', " .
                "federaltaxid = '" . add_escape_custom(trim((isset($_POST['federaltaxid']) ? $_POST['federaltaxid'] : ''))) . "', " .
                "state_license_number = '" . add_escape_custom(trim((isset($_POST['state_license_number']) ? $_POST['state_license_number'] : ''))) . "', " .
                "newcrop_user_role = '" . add_escape_custom(trim((isset($_POST['erxrole']) ? $_POST['erxrole'] : ''))) . "', " .
                "physician_type = '" . add_escape_custom(trim((isset($_POST['physician_type']) ? $_POST['physician_type'] : ''))) . "', " .
                "main_menu_role = '" . add_escape_custom(trim((isset($_POST['main_menu_role']) ? $_POST['main_menu_role'] : ''))) . "', " .
                "patient_menu_role = '" . add_escape_custom(trim((isset($_POST['patient_menu_role']) ? $_POST['patient_menu_role'] : ''))) . "', " .
                "weno_prov_id = '" . add_escape_custom(trim((isset($_POST['erxprid']) ? $_POST['erxprid'] : ''))) . "', " .
                "authorized = '" . add_escape_custom(trim((isset($_POST['authorized']) ? $_POST['authorized'] : ''))) . "', " .
                "info = '" . add_escape_custom(trim((isset($_POST['info']) ? $_POST['info'] : ''))) . "', " .
                "federaldrugid = '" . add_escape_custom(trim((isset($_POST['federaldrugid']) ? $_POST['federaldrugid'] : ''))) . "', " .
                "upin = '" . add_escape_custom(trim((isset($_POST['upin']) ? $_POST['upin'] : ''))) . "', " .
                "npi = '" . add_escape_custom(trim((isset($_POST['npi']) ? $_POST['npi'] : ''))) . "', " .
                "taxonomy = '" . add_escape_custom(trim((isset($_POST['taxonomy']) ? $_POST['taxonomy'] : ''))) . "', " .
                "facility_id = '" . add_escape_custom(trim((isset($_POST['facility_id']) ? $_POST['facility_id'] : ''))) . "', " .
                "billing_facility_id = '" . add_escape_custom(trim((isset($_POST['billing_facility_id']) ? $_POST['billing_facility_id'] : ''))) . "', " .
                "specialty = '" . add_escape_custom(trim((isset($_POST['specialty']) ? $_POST['specialty'] : ''))) . "', " .
                "see_auth = '" . add_escape_custom(trim((isset($_POST['see_auth']) ? $_POST['see_auth'] : ''))) . "', " .
                "default_warehouse = '" . add_escape_custom(trim((isset($_POST['default_warehouse']) ? $_POST['default_warehouse'] : ''))) . "', " .
                "irnpool = '" . add_escape_custom(trim((isset($_POST['irnpool']) ? $_POST['irnpool'] : ''))) . "', " .
                "abook_type = '', " .
                "calendar = '" . add_escape_custom($calvar) . "', " .
                "portal_user = '" . add_escape_custom($portalvar) . "', " .
                "supervisor_id = " . (isset($_POST['supervisor_id']) ? (int)$_POST['supervisor_id'] : 0);

            // Create user with empty password - they will set it via temp password
            $success = sqlStatement($insertUserSQL);
            if ($success === false) {
                $alertmsg .= xl('Failed to create user.');
                error_log("User creation failed: SQL error");
            } else {
                $alertmsg .= xl('User created successfully.');
                error_log("User creation successful");

                // Get the user ID for the newly created user
                $username = trim((isset($_POST['rumple']) ? $_POST['rumple'] : ''));
                $user_lookup = sqlStatement("SELECT id FROM users WHERE username = '" . add_escape_custom($username) . "'");
                $user_data = sqlFetchArray($user_lookup);
                $new_user_id = $user_data ? $user_data['id'] : 0;

                // Create entry in users_secure table (required for authentication)
                if ($new_user_id > 0) {
                    // Create a temporary password hash that will be replaced when user sets their password
                    $authHash = new \OpenEMR\Common\Auth\AuthHash();
                    $temp_password = 'temp_' . bin2hex(random_bytes(8));
                    $temp_hash = $authHash->passwordHash($temp_password);

                    $secure_insert = sqlStatement(
                        "INSERT INTO users_secure (id, username, password, last_update_password) VALUES (?, ?, ?, NOW())",
                        array($new_user_id, $username, $temp_hash)
                    );

                    if ($secure_insert === false) {
                        error_log("Failed to create users_secure entry for user: " . $username);
                        $alertmsg .= xl('User created but secure credentials setup failed.');
                    } else {
                        error_log("Successfully created users_secure entry for user: " . $username);
                    }
                }

                if ($new_user_id > 0) {
                    // Set default user settings
                    $default_settings = array(
                        'allergy_ps_expand' => '1',
                        'appointments_ps_expand' => '1',
                        'demographics_ps_expand' => '0',
                        'dental_ps_expand' => '1',
                        'directives_ps_expand' => '1',
                        'disclosures_ps_expand' => '0',
                        'immunizations_ps_expand' => '1',
                        'insurance_ps_expand' => '0',
                        'medical_problem_ps_expand' => '1',
                        'medication_ps_expand' => '1',
                        'pnotes_ps_expand' => '0',
                        'prescriptions_ps_expand' => '1',
                        'surgery_ps_expand' => '1',
                        'vitals_ps_expand' => '1',
                        'gacl_protect' => '0'
                    );

                    foreach ($default_settings as $setting_label => $setting_value) {
                        sqlStatement(
                            "INSERT INTO user_settings (setting_user, setting_label, setting_value) VALUES (?, ?, ?)",
                            array($new_user_id, $setting_label, $setting_value)
                        );
                    }

                    error_log("Set default user settings for user ID: " . $new_user_id);
                }
            }

            if ($success) {
                // Generate a secure random temporary password after user creation
                $temp_password = bin2hex(random_bytes(6)); // 12 characters, hex only for reliability

                // Store the temp password in temp_passwords table
                error_log("New user ID: " . $new_user_id . ", Username: " . $username . ", Temp password: " . $temp_password);

                if ($new_user_id > 0) {
                    $insert_result = sqlStatement(
                        "INSERT INTO temp_passwords (user_id, username, temp_password, email, expires_at) VALUES (?, ?, ?, ?, ?)",
                        array(
                            $new_user_id,
                            trim((isset($_POST['rumple']) ? $_POST['rumple'] : '')),
                            $temp_password,
                            $user_email,
                            $expires_at
                        )
                    );

                    if ($insert_result === false) {
                        error_log("Failed to insert temp password into database");
                    } else {
                        error_log("Successfully inserted temp password into database");
                    }

                    // Email the temp password to the user using OpenEMR's email system
                    $mail = new MyMailer();
                    $mail->From = $GLOBALS['practice_return_email_path'] ?: 'noreply@openemr.local';
                    $mail->FromName = (!empty($GLOBALS['practice_name'])) ? $GLOBALS['practice_name'] : 'OpenEMR Administrator';
                    $mail->AddAddress($user_email);
                    $mail->Subject = 'Your OpenEMR Temporary Password';

                    $setup_url = getPasswordSetupUrl($temp_password);
                    $login_url = getServerUrl() . "/interface/login/login.php?site=" . urlencode($_SESSION['site_id'] ?? 'default');

                    // Create HTML email
                    $html_message = "
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset='utf-8'>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                        .container { max-width: 600px; margin: 0 auto; background: #ffffff; }
                        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
                        .header h1 { margin: 0; font-size: 24px; }
                        .content { padding: 30px; }
                        .temp-password { background: #f8f9fa; border: 2px solid #e9ecef; border-radius: 8px; padding: 20px; margin: 20px 0; text-align: center; }
                        .temp-password h3 { margin: 0 0 10px 0; color: #495057; }
                        .temp-password .password { font-size: 24px; font-weight: bold; color: #667eea; font-family: monospace; letter-spacing: 2px; }
                        .setup-button { display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 30px; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 20px 0; }
                        .setup-button:hover { background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%); }
                        .warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 6px; margin: 20px 0; }
                        .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #6c757d; font-size: 12px; }
                        .steps { background: #e3f2fd; border-left: 4px solid #2196f3; padding: 20px; margin: 20px 0; }
                        .steps h3 { margin: 0 0 15px 0; color: #1976d2; }
                        .steps ol { margin: 0; padding-left: 20px; }
                        .steps li { margin-bottom: 8px; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h1>" . ((!empty($GLOBALS['practice_name'])) ? $GLOBALS['practice_name'] : 'OpenEMR') . "</h1>
                            <p>Account Setup Complete</p>
                        </div>

                        <div class='content'>
                            <h2>Welcome to " . ((!empty($GLOBALS['practice_name'])) ? $GLOBALS['practice_name'] : 'OpenEMR') . "!</h2>

                            <p>Your account has been created successfully. To complete your setup, you need to set a secure password using the temporary password provided below.</p>

                            <div class='temp-password'>
                                <h3>Your Temporary Password</h3>
                                <div class='password'>" . $temp_password . "</div>
                                <p><strong>This password will expire in 72 hours</strong></p>
                            </div>

                            <div class='steps'>
                                <h3>Next Steps:</h3>
                                <ol>
                                    <li>Click the 'Set Up Password' button below</li>
                                    <li>Enter the temporary password above</li>
                                    <li>Create your new secure password</li>
                                    <li>Log in with your username and new password</li>
                                </ol>
                            </div>

                            <div style='text-align: center;'>
                                <a href='" . $setup_url . "' class='setup-button'>Set Up Password</a>
                            </div>

                            <div class='warning'>
                                <strong>Important:</strong> For security reasons, this temporary password will expire in 72 hours. Please set up your password as soon as possible.
                            </div>

                            <p>If you did not request this account, please contact your administrator immediately.</p>

                            <p>If you have any issues, you can also access the password setup page directly:</p>
                            <p><a href='" . $setup_url . "'>" . $setup_url . "</a></p>
                        </div>

                        <div class='footer'>
                            <p><strong>" . ((!empty($GLOBALS['practice_name'])) ? $GLOBALS['practice_name'] : 'OpenEMR') . "</strong></p>
                            <p>This is an automated message. Please do not reply to this email.</p>
                        </div>
                    </div>
                </body>

                </html>";

                    // Create plain text version
                    $text_message = "Hello,\n\nYour " . ((!empty($GLOBALS['practice_name'])) ? $GLOBALS['practice_name'] : 'OpenEMR') . " account has been created successfully.\n\nYour temporary password is: " . $temp_password . "\n\nTo set up your password:\n1. Go to: " . $setup_url . "\n2. Enter the temporary password above\n3. Create your new secure password\n4. Log in with your username and new password\n\nThis temporary password will expire in 72 hours.\n\nIf you did not request this account, please contact your administrator immediately.\n\nBest regards,\n" . ((!empty($GLOBALS['practice_name'])) ? $GLOBALS['practice_name'] : 'OpenEMR Administrator');

                    // Add debugging to see what's being sent
                    error_log("Sending temp password email - Password: " . $temp_password . " to: " . $user_email);
                    error_log("Setup URL: " . $setup_url);

                    $mail->Body = $html_message;
                    $mail->AltBody = $text_message;
                    $mail->IsHTML(true);

                    if (!$mail->Send()) {
                        error_log("Failed to send temporary password email to: " . errorLogEscape($user_email) . " - " . errorLogEscape($mail->ErrorInfo));
                    } else {
                        $alertmsg .= xl('User created successfully. Temporary password has been sent to') . ' ' . $user_email;
                    }
                } else {
                    error_log("Failed to get valid user ID for temp password creation. User ID: " . $new_user_id);
                }
            }
        } else {
            // Username/email already exists - don't create user
            error_log("User creation blocked (duplicate username/email): " . trim((isset($_POST['rumple']) ? $_POST['rumple'] : '')));
        }

        // Assign user to groups
        if ($_POST['access_group']) {
            $access_groups = $_POST['access_group'];
            if (!is_array($access_groups)) {
                $access_groups = [$access_groups];
            }
            $bg_count = count($access_groups);
            for ($i = 0; $i < $bg_count; $i++) {
                if ($access_groups[$i] == "Emergency Login") {
                    $set_active_msg = 1;
                }

                // Add user to the group
                sqlStatement(
                    "INSERT INTO `groups` (name, user) VALUES (?, ?)",
                    array($access_groups[$i], trim((isset($_POST['rumple']) ? $_POST['rumple'] : '')))
                );
                error_log("Added user " . trim((isset($_POST['rumple']) ? $_POST['rumple'] : '')) . " to group: " . $access_groups[$i]);
            }

            // Set up ACL permissions for the user (same as OpenEMR's built-in system)
            $username = trim((isset($_POST['rumple']) ? $_POST['rumple'] : ''));
            $fname = trim((isset($_POST['fname']) ? $_POST['fname'] : ''));
            $mname = trim((isset($_POST['mname']) ? $_POST['mname'] : ''));
            $lname = trim((isset($_POST['lname']) ? $_POST['lname'] : ''));

            AclExtended::setUserAro(
                $access_groups,
                $username,
                $fname,
                $mname,
                $lname
            );
            error_log("Set up ACL permissions for user: " . $username);

            // this event should only fire if we actually succeeded in creating the user...
            if ($success) {
                // Handle facility-specific settings if enabled
                if (!empty($GLOBALS['gbl_fac_warehouse_restrictions']) || !empty($GLOBALS['restrict_user_facility'])) {
                    if (!empty($_POST["facility_id"])) {
                        // Add user to their default facility
                        sqlStatement(
                            "INSERT INTO users_facility SET tablename = ?, table_id = ?, facility_id = ?, warehouse_id = ?",
                            array('users', $new_user_id, $_POST["facility_id"], '')
                        );
                        error_log("Added user to default facility: " . $_POST["facility_id"]);
                    }

                    // Handle additional facilities access
                    if (!empty($_POST["additional_facilities"]) && is_array($_POST["additional_facilities"])) {
                        foreach ($_POST["additional_facilities"] as $facility_id) {
                            // Skip if it's the same as default facility to avoid duplicates
                            if ($facility_id != $_POST["facility_id"]) {
                                sqlStatement(
                                    "INSERT INTO users_facility SET tablename = ?, table_id = ?, facility_id = ?, warehouse_id = ?",
                                    array('users', $new_user_id, $facility_id, '')
                                );
                                error_log("Added user to additional facility: " . $facility_id);
                            }
                        }
                    }
                }

                // let's make sure we send on our uuid alongside the id of the user
                $submittedData = $_POST;
                $submittedData['uuid'] = $uuid ?? null;
                $submittedData['username'] = $submittedData['rumple'] ?? null;
                $userCreatedEvent = new UserCreatedEvent($submittedData);
                unset($submittedData); // clear things out in case we have any sensitive data here
                $GLOBALS["kernel"]->getEventDispatcher()->dispatch(UserCreatedEvent::EVENT_HANDLE, $userCreatedEvent, 10);
            }
        }
    } elseif ($_POST["mode"] == "new_group") {
        $res = sqlStatement("select distinct name, user from `groups`");
        for ($iter = 0; $row = sqlFetchArray($res); $iter++) {
            $result[$iter] = $row;
        }

        $doit = 1;
        foreach ($result as $iter) {
            if ($doit == 1 && $iter["name"] == (trim((isset($_POST['groupname']) ? $_POST['groupname'] : ''))) && $iter["user"] == (trim((isset($_POST['rumple']) ? $_POST['rumple'] : '')))) {
                $doit--;
            }
        }

        if ($doit == 1) {
            sqlStatement(
                "insert into `groups` set name = ?, user = ?",
                array(
                    trim((isset($_POST['groupname']) ? $_POST['groupname'] : '')),
                    trim((isset($_POST['rumple']) ? $_POST['rumple'] : ''))
                )
            );
        } else {
            $alertmsg .= "User " . trim((isset($_POST['rumple']) ? $_POST['rumple'] : '')) .
            " is already a member of group " . trim((isset($_POST['groupname']) ? $_POST['groupname'] : '')) . ". ";
        }
    }
}

if (isset($_GET["mode"])) {
    if ($_GET["mode"] == "delete_group") {
        $res = sqlStatement("select distinct user from `groups` where id = ?", array($_GET["id"]));
        for ($iter = 0; $row = sqlFetchArray($res); $iter++) {
            $result[$iter] = $row;
        }

        foreach ($result as $iter) {
            $un = $iter["user"];
        }

        $res = sqlStatement("select name, user from `groups` where user = ? " .
        "and id != ?", array($un, $_GET["id"]));

        // Remove the user only if they are also in some other group.  I.e. every
        // user must be a member of at least one group.
        if (sqlFetchArray($res) != false) {
              sqlStatement("delete from `groups` where id = ?", array($_GET["id"]));
        } else {
              $alertmsg .= "You must add this user to some other group before " .
                "removing them from this group. ";
        }
    }
}

if (isset($_POST["mode"]) && $_POST["mode"] === "send_reset_link") {
    // Validate user is logged in
    $adminUserId = $_SESSION['authUserID'];
    $targetUserId = (int)($_POST['id'] ?? 0);
    if (!$adminUserId || !$targetUserId) {
        echo xlt('Missing required parameters.');
        exit;
    }
    // Get user info
    $userRes = sqlStatement("SELECT username, google_signin_email, fname, lname FROM users WHERE id = ?", [$targetUserId]);
    $user = sqlFetchArray($userRes);
    if (!$user || empty($user['google_signin_email'])) {
        echo xlt('User or user email not found.');
        exit;
    }
    $expires_at = date('Y-m-d H:i:s', strtotime('+72 hours'));
    $temp_password = bin2hex(random_bytes(6)); // 12 chars
    // Store temp password
    $insert_result = sqlStatement(
        "INSERT INTO temp_passwords (user_id, username, temp_password, email, expires_at) VALUES (?, ?, ?, ?, ?)",
        [
            $targetUserId,
            $user['username'],
            $temp_password,
            $user['google_signin_email'],
            $expires_at
        ]
    );
    if ($insert_result === false) {
        echo xlt('Failed to store temporary password.');
        exit;
    }
    // Send email
    $mail = new MyMailer();
    $mail->From = $GLOBALS['practice_return_email_path'] ?: 'noreply@openemr.local';
    $mail->FromName = (!empty($GLOBALS['practice_name'])) ? $GLOBALS['practice_name'] : 'OpenEMR Administrator';
    $mail->AddAddress($user['google_signin_email']);
    $mail->Subject = 'Your OpenEMR Temporary Password';
    $setup_url = getPasswordSetupUrl($temp_password);
    $html_message = "\n                <!DOCTYPE html>\n                <html>\n                <head>\n                    <meta charset='utf-8'>\n                    <style>\n                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }\n                        .container { max-width: 600px; margin: 0 auto; background: #ffffff; }\n                        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }\n                        .header h1 { margin: 0; font-size: 24px; }\n                        .content { padding: 30px; }\n                        .temp-password { background: #f8f9fa; border: 2px solid #e9ecef; border-radius: 8px; padding: 20px; margin: 20px 0; text-align: center; }\n                        .temp-password h3 { margin: 0 0 10px 0; color: #495057; }\n                        .temp-password .password { font-size: 24px; font-weight: bold; color: #667eea; font-family: monospace; letter-spacing: 2px; }\n                        .setup-button { display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 30px; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 20px 0; }\n                        .setup-button:hover { background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%); }\n                        .warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 6px; margin: 20px 0; }\n                        .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #6c757d; font-size: 12px; }\n                        .steps { background: #e3f2fd; border-left: 4px solid #2196f3; padding: 20px; margin: 20px 0; }\n                        .steps h3 { margin: 0 0 15px 0; color: #1976d2; }\n                        .steps ol { margin: 0; padding-left: 20px; }\n                        .steps li { margin-bottom: 8px; }\n                    </style>\n                </head>\n                <body>\n                    <div class='container'>\n                        <div class='header'>\n                            <h1>" . ((!empty($GLOBALS['practice_name'])) ? $GLOBALS['practice_name'] : 'OpenEMR') . "</h1>\n                            <p>Password Reset Request</p>\n                        </div>\n                        <div class='content'>\n                            <h2>Hello " . htmlspecialchars($user['fname'] . ' ' . $user['lname']) . ",</h2>\n                            <p>Your administrator has requested a password reset for your OpenEMR account. Use the temporary password below to set a new password.</p>\n                            <div class='temp-password'>\n                                <h3>Your Temporary Password</h3>\n                                <div class='password'>" . $temp_password . "</div>\n                                <p><strong>This password will expire in 72 hours</strong></p>\n                            </div>\n                            <div class='steps'>\n                                <h3>Next Steps:</h3>\n                                <ol>\n                                    <li>Click the 'Set Up Password' button below</li>\n                                    <li>Enter the temporary password above</li>\n                                    <li>Create your new secure password</li>\n                                    <li>Log in with your username and new password</li>\n                                </ol>\n                            </div>\n                            <div style='text-align: center;'>\n                                <a href='" . $setup_url . "' class='setup-button'>Set Up Password</a>\n                            </div>\n                            <div class='warning'>\n                                <strong>Important:</strong> For security reasons, this temporary password will expire in 72 hours. Please set up your password as soon as possible.\n                            </div>\n                            <p>If you did not request this reset, please contact your administrator immediately.</p>\n                            <p>If you have any issues, you can also access the password setup page directly:</p>\n                            <p><a href='" . $setup_url . "'>" . $setup_url . "</a></p>\n                        </div>\n                        <div class='footer'>\n                            &copy; " . date('Y') . " OpenEMR\n                        </div>\n                    </div>\n                </body>\n                </html>\n    ";
    $mail->isHTML(true);
    $mail->Body = $html_message;
    if (!$mail->Send()) {
        echo xlt('Failed to send email.');
        exit;
    }
    echo xlt('Password reset link sent successfully.');
    exit;
}

// added for form submit's from usergroup_admin_add and user_admin.php
// sjp 12/29/17
if (isset($_REQUEST["mode"])) {
    exit(text(trim($alertmsg)));
}

$form_inactive = empty($_POST['form_inactive']) ? false : true;

?>
<html>
<head>
<title><?php echo xlt('User / Groups');?></title>

<?php Header::setupHeader(['common']); ?>

<script>

$(function () {

    tabbify();

    $(".medium_modal").on('click', function(e) {
        e.preventDefault();e.stopPropagation();
        dlgopen('', '', 'modal-mlg', 450, '', '', {
            type: 'iframe',
            url: $(this).attr('href')
        });
    });

});

function authorized_clicked() {
 var f = document.forms[0];
 f.calendar.disabled = !f.authorized.checked;
 f.calendar.checked  =  f.authorized.checked;
}

</script>

</head>
<body class="body_top">

<div class="container">
    <div class="row">
        <div class="col-12">
            <div class="page-title">
                <h2><?php echo xlt('User / Groups');?></h2>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-12">
            <div class="btn-group">
                <a href="usergroup_admin_add.php" class="medium_modal btn btn-secondary btn-add"><?php echo xlt('Add User'); ?></a>
                <a href="facility_user.php" class="btn btn-secondary btn-show"><?php echo xlt('View Facility Specific User Information'); ?></a>
            </div>
            <form name='userlist' method='post' style="display: inline;" class="form-inline" class="float-right" action='usergroup_admin.php' onsubmit='return top.restoreSession()'>
                <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                <div class="checkbox">
                    <label for="form_inactive">
                        <input type='checkbox' class="form-control" id="form_inactive" name='form_inactive' value='1' onclick='submit()' <?php echo ($form_inactive) ? 'checked ' : ''; ?>>
                        <?php echo xlt('Include inactive users'); ?>
                    </label>
                </div>
            </form>
        </div>
    </div>
    <div class="row">
        <div class="col-12">
            <?php
            if ($set_active_msg == 1) {
                echo "<div class='alert alert-danger'>" . xlt('Emergency Login ACL is chosen. The user is still in active state, please de-activate the user and activate the same when required during emergency situations. Visit Administration->Users for activation or de-activation.') . "</div><br />";
            }

            if ($show_message == 1) {
                echo "<div class='alert alert-danger'>" . xlt('The following Emergency Login User is activated:') . " " . "<b>" . text($_GET['fname']) . "</b>" . "</div><br />";
                echo "<div class='alert alert-danger'>" . xlt('Emergency Login activation email will be circulated only if following settings in the interface/globals.php file are configured:') . " \$GLOBALS['Emergency_Login_email'], \$GLOBALS['Emergency_Login_email_id']</div>";
            }

            ?>
            <div class="table-responsive">
                <table class="table table-striped table-sm">
                    <thead>
                        <tr>
                            <th><?php echo xlt('Username'); ?></th>
                            <th><?php echo xlt('Real Name'); ?></th>
                            <th><?php echo xlt('Additional Info'); ?></th>
                            <th><?php echo xlt('Authorized'); ?></th>
                            <th><?php echo xlt('MFA'); ?></th>
                            <?php
                            $checkPassExp = false;
                            if (($GLOBALS['password_expiration_days'] != 0) && (check_integer($GLOBALS['password_expiration_days'])) && (check_integer($GLOBALS['password_grace_time']))) {
                                $checkPassExp = true;
                                echo '<th>' . xlt('Password Expiration') . '</th>';
                            }
                            ?>
                            <th><?php echo xlt('Actions'); ?></th>
                        </tr>
                    <tbody>
                        <?php
                        $query = "SELECT * FROM users WHERE username != '' ";
                        if (!$form_inactive) {
                            $query .= "AND active = '1' ";
                        }

                        $query .= "ORDER BY username";
                        $res = sqlStatement($query);
                        for ($iter = 0; $row = sqlFetchArray($res); $iter++) {
                            $result4[$iter] = $row;
                        }

                        foreach ($result4 as $iter) {
                            // Skip this user if logged-in user does not have all of its permissions.
                            // Note that a superuser now has all permissions.
                            if (!AclExtended::iHavePermissionsOf($iter['username'])) {
                                continue;
                            }

                            if ($iter["authorized"]) {
                                $iter["authorized"] = xl('yes');
                            } else {
                                $iter["authorized"] = xl('no');
                            }

                            $mfa = sqlQueryNoLog(
                                "SELECT `method` FROM `login_mfa_registrations` " .
                                "WHERE `user_id` = ? AND (`method` = 'TOTP' OR `method` = 'U2F')",
                                [$iter['id']]
                            );
                            if (!empty($mfa['method'])) {
                                $isMfa = xl('yes');
                            } else {
                                $isMfa = xl('no');
                            }

                            if ($checkPassExp && !empty($iter["active"])) {
                                $current_date = date("Y-m-d");
                                $userSecure = privQuery("SELECT `last_update_password` FROM `users_secure` WHERE `id` = ?", [$iter['id']]);
                                $pwd_expires = date("Y-m-d", strtotime($userSecure['last_update_password'] . "+" . $GLOBALS['password_expiration_days'] . " days"));
                                $grace_time = date("Y-m-d", strtotime($pwd_expires . "+" . $GLOBALS['password_grace_time'] . " days"));
                            }

                            print "<tr>
                                <td><a href='user_admin.php?id=" . attr_url($iter["id"]) . "&csrf_token_form=" . attr_url(CsrfUtils::collectCsrfToken()) .
                                "' class='medium_modal' onclick='top.restoreSession()'>" . text($iter["username"]) . "</a>" . "</td>
                                <td>" . text($iter["fname"]) . ' ' . text($iter["lname"]) . "&nbsp;</td>
                                <td>" . text($iter["info"]) . "&nbsp;</td>
                                <td align='left'><span>" . text($iter["authorized"]) . "</td>
                                <td align='left'><span>" . text($isMfa) . "</td>";
                            if ($checkPassExp) {
                                if (AuthUtils::useActiveDirectory($iter["username"]) || empty($iter["active"])) {
                                    // LDAP bypasses expired password mechanism
                                    echo '<td>';
                                    echo xlt('Not Applicable');
                                } elseif (strtotime($current_date) > strtotime($grace_time)) {
                                    echo '<td class="bg-danger text-light">';
                                    echo xlt('Expired');
                                } elseif (strtotime($current_date) > strtotime($pwd_expires)) {
                                    echo '<td class="bg-warning text-dark">';
                                    echo xlt('Grace Period');
                                } else {
                                    echo '<td>';
                                    echo text(oeFormatShortDate($pwd_expires));
                                }
                                echo '</td>';
                            }
                            // Add Delete button for admin only, and prevent deleting self
                            if (AclMain::aclCheckCore('admin', 'users') && $_SESSION['authUserID'] != $iter['id']) {
                                echo "<td><form method='POST' style='display:inline;' onsubmit='return confirm(\"Are you sure you want to delete this user? This action cannot be undone.\");'>
                                    <input type='hidden' name='csrf_token_form' value='" . attr(CsrfUtils::collectCsrfToken()) . "' />
                                    <input type='hidden' name='delete_user_id' value='" . attr($iter['id']) . "' />
                                    <button type='submit' class='btn btn-danger btn-sm' onclick='event.stopPropagation();'>" . xlt('Delete') . "</button>
                                </form></td>";
                            } else {
                                echo "<td></td>";
                            }
                            print "</tr>\n";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            <?php
            if (empty(
                $GLOBALS['disable_non_default_groups']
            )) {
                // (Optional) You can add group listing code here if needed.
            }
            ?>
        </div>
    </div>
</div>
<script>
<?php
if ($alertmsg = trim($alertmsg)) {
    echo "alert(" . js_escape($alertmsg) . ");\n";
}
?>

<?php if (!empty($_POST["privatemode"]) && $_POST["privatemode"] === "user_admin" && ($_POST["mode"] ?? '') === 'update' && !empty($alertmsg)) { ?>
if (window.opener && !window.opener.closed) {
    window.opener.location.reload();
}
if (window.parent && window.parent !== window && typeof window.parent.location !== 'undefined') {
    try {
        if (window.parent.location.href !== window.location.href) {
            window.parent.location.reload();
        }
    } catch (e) {
    }
}
if (typeof dlgclose === 'function') {
    dlgclose();
}
<?php } ?>

// Add missing cancel function for dialog callbacks
function cancel() {
    dlgclose();
}

// Ensure cancel function is available globally
window.cancel = cancel;
</script>
</body>
</html>
