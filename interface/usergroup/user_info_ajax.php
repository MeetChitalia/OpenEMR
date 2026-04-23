<?php

/**
 * Controller to handle user password change requests.
 *
 * <pre>
 * Expected REQUEST parameters
 * $_REQUEST['pk'] - The primary key being used for encryption. The browser would have requested this previously
 * $_REQUEST['curPass'] - ciphertext of the user's current password
 * $_REQUEST['newPass'] - ciphertext of the new password to use
 * $_REQUEST['newPass2']) - second copy of ciphertext of the new password to confirm proper user entry.
 * </pre>
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Kevin Yeh <kevin.y@integralemr.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2013 Kevin Yeh <kevin.y@integralemr.com>
 * @copyright Copyright (c) 2013 OEMR <www.oemr.org>
 * @copyright Copyright (c) 2017-2019 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE CNU General Public License 3
 */

// Set $sessionAllowWrite to true to prevent session concurrency issues during authorization related code
$sessionAllowWrite = true;
require_once("../globals.php");

use OpenEMR\Common\Auth\AuthUtils;
use OpenEMR\Common\Csrf\CsrfUtils;

if (!empty($_POST)) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }
}

$curPass = $_REQUEST['curPass'];
$newPass = $_REQUEST['newPass'];
$newPass2 = $_REQUEST['newPass2'];

if ($newPass != $newPass2) {
    echo "<div class='alert alert-danger'>" . xlt("Passwords Don't match!") . "</div>";
    exit;
}

// Check if this is a forced password change due to temporary password
$isForcedChange = isset($_SESSION['force_password_change']) && $_SESSION['force_password_change'] === true;

if ($isForcedChange) {
    // For temporary password users, update the password directly in the database
    $authHash = new \OpenEMR\Common\Auth\AuthHash();
    $newHash = $authHash->passwordHash($newPass);
    
    if (empty($newHash)) {
        $success = false;
        $errorMessage = xl("Unable to create password hash");
    } else {
        // Update password directly in database
        $updateSQL = "UPDATE `users_secure` SET `password` = ?, `last_update_password` = NOW() WHERE `id` = ?";
        $result = privStatement($updateSQL, array($newHash, $_SESSION['authUserID']));
        
        if ($result !== false) {
            // Reset login failed counter when password is changed
            privStatement("UPDATE `users_secure` SET `login_fail_counter` = 0 WHERE `id` = ?", array($_SESSION['authUserID']));
            
            $success = true;
            // Update session password hash
            $_SESSION['authPass'] = $newHash;
        } else {
            $success = false;
            $errorMessage = xl("Database update failed");
        }
    }
} else {
    // Normal password change flow
$authUtilsUpdatePassword = new AuthUtils();
$success = $authUtilsUpdatePassword->updatePassword($_SESSION['authUserID'], $_SESSION['authUserID'], $curPass, $newPass);
}

if ($success) {
    echo "<div class='alert alert-success'>" . xlt("Password change successful") . "</div>";
    // Clear the force password change session flag
    if ($isForcedChange) {
        unset($_SESSION['force_password_change']);
    }
} else {
    // If updatePassword fails the error message is returned
    if ($isForcedChange) {
        echo "<div class='alert alert-danger'>" . text($errorMessage) . "</div>";
    } else {
    echo "<div class='alert alert-danger'>" . text($authUtilsUpdatePassword->getErrorMessage()) . "</div>";
    }
}
