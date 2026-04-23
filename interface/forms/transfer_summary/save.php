<?php

/**
 * transfer summary form.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Naina Mohamed <naina@capminds.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2012-2013 Naina Mohamed <naina@capminds.com> CapMinds Technologies
 * @copyright Copyright (c) 2019 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/api.inc");
require_once("$srcdir/forms.inc");

use OpenEMR\Common\Csrf\CsrfUtils;

if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
    CsrfUtils::csrfNotVerified();
}

if (!$encounter) { // comes from globals.php
    die(xlt("Internal error: we do not seem to be in an encounter!"));
}

$id = (int) (isset($_GET['id']) ? $_GET['id'] : '');

if (empty($id)) {
    // New form - use formSubmit
    $newid = formSubmit("form_transfer_summary", $_POST, '', $userauthorized);
    addForm($encounter, "Transfer Summary", $newid, "transfer_summary", $pid, $userauthorized);
} else {
    // Update existing form - use formUpdate
    $success = formUpdate("form_transfer_summary", $_POST, $id, $userauthorized);
}

formHeader("Redirecting....");
formJump();
formFooter(); 