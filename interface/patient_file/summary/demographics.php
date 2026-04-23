<?php

/**
 *
 * Patient summary screen.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Sharon Cohen <sharonco@matrix.co.il>
 * @author    Stephen Waite <stephen.waite@cmsvt.com>
 * @author    Ranganath Pathak <pathak@scrs1.org>
 * @author    Tyler Wrenn <tyler@tylerwrenn.com>
 * @author    Robert Down <robertdown@live.com>
 * @copyright Copyright (c) 2017-2020 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2017 Sharon Cohen <sharonco@matrix.co.il>
 * @copyright Copyright (c) 2018-2020 Stephen Waite <stephen.waite@cmsvt.com>
 * @copyright Copyright (c) 2018 Ranganath Pathak <pathak@scrs1.org>
 * @copyright Copyright (c) 2020 Tyler Wrenn <tyler@tylerwrenn.com>
 * @copyright Copyright (c) 2021-2022 Robert Down <robertdown@live.com
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../../globals.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/options.inc.php");
require_once("../history/history.inc.php");
require_once("$srcdir/clinical_rules.php");
require_once("$srcdir/group.inc");
require_once(__DIR__ . "/../../../library/appointments.inc.php");

use OpenEMR\Billing\EDI270;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionUtil;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\Events\Patient\Summary\Card\RenderEvent as CardRenderEvent;
use OpenEMR\Events\Patient\Summary\Card\SectionEvent;
use OpenEMR\Events\Patient\Summary\Card\RenderModel;
use OpenEMR\Events\Patient\Summary\Card\CardInterface;
use OpenEMR\Events\PatientDemographics\ViewEvent;
use OpenEMR\Events\PatientDemographics\RenderEvent;
use OpenEMR\FHIR\SMART\SmartLaunchController;
use OpenEMR\Menu\PatientMenuRole;
use OpenEMR\OeUI\OemrUI;
use OpenEMR\Patient\Cards\PortalCard;
use OpenEMR\Reminder\BirthdayReminder;
use Symfony\Component\EventDispatcher\EventDispatcher;

$twig = new TwigContainer(null, $GLOBALS['kernel']);

/**
 * Helper function to convert ADORecordSet to array
 * @param mixed $result - SQL result that might be ADORecordSet or array
 * @return array - Always returns an array
 */
if (!function_exists('ensureArray')) {
    function ensureArray($result) {
        if (is_array($result)) {
            return $result;
        }
        
        // If it's an ADORecordSet object, convert to array
        if (is_object($result) && method_exists($result, 'FetchRow')) {
            $array = array();
            while ($row = $result->FetchRow()) {
                $array[] = $row;
            }
            return $array;
        }
        
        // If it's a single row ADORecordSet, get the first row
        if (is_object($result) && method_exists($result, 'FetchRow')) {
            $row = $result->FetchRow();
            return $row ? $row : array();
        }
        
        return array();
    }
}

// Set session for pid (via setpid). Also set session for encounter (if applicable)
if (isset($_GET['set_pid'])) {
    require_once("$srcdir/pid.inc");
    setpid($_GET['set_pid']);
    if (isset($_GET['set_encounterid']) && ((int)$_GET['set_encounterid'] > 0)) {
        $encounter = (int)$_GET['set_encounterid'];
        SessionUtil::setSession('encounter', $encounter);
    }
}

// Note: it would eventually be a good idea to move this into
// it's own module that people can remove / add if they don't
// want smart support in their system.
$smartLaunchController = new SMARTLaunchController($GLOBALS["kernel"]->getEventDispatcher());
$smartLaunchController->registerContextEvents();

/**
 * @var EventDispatcher
 */
$ed = $GLOBALS['kernel']->getEventDispatcher();

$active_reminders = false;
$all_allergy_alerts = false;
if ($GLOBALS['enable_cdr']) {
    //CDR Engine stuff
    if ($GLOBALS['enable_allergy_check'] && $GLOBALS['enable_alert_log']) {
        //Check for new allergies conflicts and throw popup if any exist(note need alert logging to support this)
        $new_allergy_alerts = allergy_conflict($pid, 'new', $_SESSION['authUser']);
        if (!empty($new_allergy_alerts)) {
            $pod_warnings = '';
            foreach ($new_allergy_alerts as $new_allergy_alert) {
                $pod_warnings .= js_escape($new_allergy_alert) . ' + "\n"';
            }
            $allergyWarningMessage = '<script>alert(' . xlj('WARNING - FOLLOWING ACTIVE MEDICATIONS ARE ALLERGIES') . ' + "\n" + ' . $pod_warnings . ')</script>';
        }
    }

    if ((empty($_SESSION['alert_notify_pid']) || ($_SESSION['alert_notify_pid'] != $pid)) && isset($_GET['set_pid']) && $GLOBALS['enable_cdr_crp']) {
        // showing a new patient, so check for active reminders and allergy conflicts, which use in active reminder popup
        $active_reminders = active_alert_summary($pid, "reminders-due", '', 'default', $_SESSION['authUser'], true);
        if ($GLOBALS['enable_allergy_check']) {
            $all_allergy_alerts = allergy_conflict($pid, 'all', $_SESSION['authUser'], true);
        }
    }
    SessionUtil::setSession('alert_notify_pid', $pid);
    // can not output html until after above setSession call
    if (!empty($allergyWarningMessage)) {
        echo $allergyWarningMessage;
    }
}
//Check to see is only one insurance is allowed
if ($GLOBALS['insurance_only_one']) {
    $insurance_array = array('primary');
} else {
    $insurance_array = array('primary', 'secondary', 'tertiary');
}

function print_as_money($money)
{
    preg_match("/(\d*)\.?(\d*)/", $money, $moneymatches);
    $tmp = wordwrap(strrev($moneymatches[1]), 3, ",", 1);
    $ccheck = strrev($tmp);
    if ($ccheck[0] == ",") {
        $tmp = substr($ccheck, 1, strlen($ccheck) - 1);
    }

    if ($moneymatches[2] != "") {
        return "$ " . strrev($tmp) . "." . $moneymatches[2];
    } else {
        return "$ " . strrev($tmp);
    }
}

// get an array from Photos category
function pic_array($pid, $picture_directory)
{
    $pics = array();
    $sql_query = "SELECT id FROM documents WHERE foreign_id = ? AND name LIKE ? AND deleted = 0";
    if ($query = sqlStatement($sql_query, array($pid, $picture_directory))) {
        while ($results = sqlFetchArray($query)) {
            array_push($pics, $results['id']);
        }
    }
    return ($pics);
}

// Get the document ID of the first document in a specific catg.
function get_document_by_catg($pid, $doc_catg)
{
    $result = array();

    if ($pid and $doc_catg) {
        $result = sqlQuery("SELECT id, date, url FROM documents 
            WHERE foreign_id = ? AND name LIKE ? AND deleted = 0 
            ORDER BY date DESC LIMIT 1", array($pid, $doc_catg));
    }

    return ($result && is_array($result) && isset($result['id'])) ? $result['id'] : false;
}

function isPortalEnabled(): bool
{
    if (
        !$GLOBALS['portal_onsite_two_enable']
    ) {
        return false;
    }

    return true;
}

function isPortalSiteAddressValid(): bool
{
    if (
        // maybe can use filter_var() someday but the default value in GLOBALS
        // fails with FILTER_VALIDATE_URL
        !isset($GLOBALS['portal_onsite_two_address'])
    ) {
        return false;
    }

    return true;
}

function isPortalAllowed($pid): bool
{
    $return = false;

    $portalStatus = sqlQuery("SELECT allow_patient_portal FROM patient_data WHERE pid = ?", [$pid]);
    $portalStatus = ensureArray($portalStatus);
    if ($portalStatus && is_array($portalStatus) && isset($portalStatus['allow_patient_portal']) && $portalStatus['allow_patient_portal'] == 'YES') {
        $return = true;
    }
    return $return;
}

function isApiAllowed($pid): bool
{
    $return = false;

    $apiStatus = sqlQuery("SELECT prevent_portal_apps FROM patient_data WHERE pid = ?", [$pid]);
    $apiStatus = ensureArray($apiStatus);
    if ($apiStatus && is_array($apiStatus) && isset($apiStatus['prevent_portal_apps']) && strtoupper($apiStatus['prevent_portal_apps']) != 'YES') {
        $return = true;
    }
    return $return;
}

function areCredentialsCreated($pid): bool
{
    $return = false;

    $credentialsCreated = sqlQuery("SELECT date_created FROM `patient_access_onsite` WHERE `pid`=?", [$pid]);
    $credentialsCreated = ensureArray($credentialsCreated);
    if ($credentialsCreated && is_array($credentialsCreated) && isset($credentialsCreated['date_created']) && !empty($credentialsCreated['date_created'])) {
        $return = true;
    }
    return $return;
}

function isContactEmail($pid): bool
{
    $return = false;

    $email = sqlQuery("SELECT email, email_direct FROM patient_data WHERE pid = ?", [$pid]);
    $email = ensureArray($email);
    if ($email && is_array($email) && isset($email['email']) && !empty($email['email'])) {
        $return = true;
    }
    return $return;
}

function isEnforceSigninEmailPortal(): bool
{
    if (
        $GLOBALS['enforce_signin_email']
    ) {
        return true;
    }

    return false;
}

function deceasedDays($days_deceased)
{
    $deceased_days = intval($days_deceased['days_deceased'] ?? '');
    if ($deceased_days == 0) {
        $num_of_days = xl("Today");
    } elseif ($deceased_days == 1) {
        $num_of_days =  $deceased_days . " " . xl("day ago");
    } elseif ($deceased_days > 1 && $deceased_days < 90) {
        $num_of_days =  $deceased_days . " " . xl("days ago");
    } elseif ($deceased_days >= 90 && $deceased_days < 731) {
        $num_of_days =  "~" . round($deceased_days / 30) . " " . xl("months ago");  // function intdiv available only in php7
    } elseif ($deceased_days >= 731) {
        $num_of_days =  xl("More than") . " " . round($deceased_days / 365) . " " . xl("years ago");
    }

    if (strlen($days_deceased['date_deceased'] ?? '') > 10 && $GLOBALS['date_display_format'] < 1) {
        $deceased_date = substr($days_deceased['date_deceased'], 0, 10);
    } else {
        $deceased_date = oeFormatShortDate($days_deceased['date_deceased'] ?? '');
    }

    return xlt("Deceased") . " - " . text($deceased_date) . " (" . text($num_of_days) . ")";
}

$deceased = is_patient_deceased($pid);


// Display image in 'widget style'
function image_widget($doc_id, $doc_catg)
{
    global $pid, $web_root;
    $docobj = new Document($doc_id);
    $image_file = $docobj->get_url_file();
    $image_file_name = $docobj->get_name();
    $image_width = $GLOBALS['generate_doc_thumb'] == 1 ? '' : 'width=100';
    $extension = substr($image_file_name, strrpos($image_file_name, "."));
    $viewable_types = array('.png', '.jpg', '.jpeg', '.png', '.bmp', '.PNG', '.JPG', '.JPEG', '.PNG', '.BMP');
    if (in_array($extension, $viewable_types)) { // extension matches list
        $to_url = "<td> <a href = '$web_root" .
            "/controller.php?document&retrieve&patient_id=" . attr_url($pid) . "&document_id=" . attr_url($doc_id) . "&as_file=false&original_file=true&disable_exit=false&show_original=true'" .
            " onclick='top.restoreSession();' class='image_modal'>" .
            " <img src = '$web_root" .
            "/controller.php?document&retrieve&patient_id=" . attr_url($pid) . "&document_id=" . attr_url($doc_id) . "&as_file=false'" .
            " $image_width alt='" . attr($doc_catg) . ":" . attr($image_file) . "'>  </a> </td> <td class='align-middle'>" .
            text($doc_catg) . '<br />&nbsp;' . text($image_file) .
            "</td>";
    } else {
        $to_url = "<td> <a href='" . $web_root . "/controller.php?document&retrieve" .
            "&patient_id=" . attr_url($pid) . "&document_id=" . attr_url($doc_id) . "'" .
            " onclick='top.restoreSession()' class='btn btn-primary btn-sm'>" .
            "<span>" .
            xlt("View") . "</a> &nbsp;" .
            text("$doc_catg - $image_file") .
            "</span> </td>";
    }

    echo "<table><tr>";
    echo $to_url;
    echo "</tr></table>";
}



// Determine if the Vitals form is in use for this site.
$tmp = sqlQuery("SELECT count(*) AS count FROM registry WHERE directory = 'vitals' AND state = 1");
$tmp = ensureArray($tmp);
$vitals_is_registered = ($tmp && is_array($tmp) && isset($tmp['count'])) ? $tmp['count'] : 0;
if ($vitals_is_registered > 0) {
    $vitals = true;
}

// Get patient/employer/insurance information.
//
$result = getPatientData($pid, "*, DATE_FORMAT(DOB,'%Y-%m-%d') as DOB_YMD");
$result2 = getEmployerData($pid);

// Get insurance data directly to avoid ADORecordSet issues
$insco_name = "";
$show_insurance = false; // Default to false, only show if we have valid data
$result3 = false;

// Check if insurance_data table exists using a safer approach
$table_exists = false;
$table_check = @sqlQuery("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'insurance_data'");
$table_check = ensureArray($table_check);
if ($table_check) {
    $table_exists = true;
}

if ($table_exists) {
    // Table exists, try to get insurance data with error suppression
    $result3 = @sqlQuery(
        "SELECT provider FROM insurance_data WHERE pid = ? AND type = 'primary' AND date <= ? ORDER BY date DESC LIMIT 1",
        array($pid, $result['DOB_YMD'])
    );
    $result3 = ensureArray($result3);
    
    if ($result3 && is_array($result3) && !empty($result3['provider'])) {
    $insco_name = getInsuranceProvider($result3['provider']);
        $show_insurance = true;
    }
}

$arrOeUiSettings = array(
    'heading_title' => xl('Medical Record Dashboard'),
    'include_patient_name' => true,
    'expandable' => false,
    'expandable_files' => array(), //all file names need suffix _xpd
    'action' => "", //conceal, reveal, search, reset, link or back
    'action_title' => "",
    'action_href' => "", //only for actions - reset, link or back
    'show_help_icon' => true,
    'help_file_name' => "medical_dashboard_help.php"
);
$oemr_ui = new OemrUI($arrOeUiSettings);
?>
<!DOCTYPE html>
<html>

<head>
    <?php
    Header::setupHeader(['common']);
    require_once("$srcdir/options.js.php");
    ?>
    <script>
        // Process click on diagnosis for referential cds popup.
        function referentialCdsClick(codetype, codevalue) {
            top.restoreSession();
            // Force a new window instead of iframe to address cross site scripting potential
            dlgopen('../education.php?type=' + encodeURIComponent(codetype) + '&code=' + encodeURIComponent(codevalue), '_blank', 1024, 750,true);
        }

        function oldEvt(apptdate, eventid) {
            let title = <?php echo xlj('Appointments'); ?>;
            dlgopen('../../main/calendar/add_edit_event.php?date=' + encodeURIComponent(apptdate) + '&eid=' + encodeURIComponent(eventid), '_blank', 800, 500, '', title);
        }

        function advdirconfigure() {
            dlgopen('advancedirectives.php', '_blank', 400, 500);
        }

        function refreshme() {
            top.restoreSession();
            location.reload();
        }

        // Process click on Delete link.
        function deleteme() { // @todo don't think this is used any longer!!
            dlgopen('../deleter.php?patient=' + <?php echo js_url($pid); ?> + '&csrf_token_form=' + <?php echo js_url(CsrfUtils::collectCsrfToken()); ?>, '_blank', 500, 450, '', '', {
                allowResize: false,
                allowDrag: false,
                dialogId: 'patdel',
                type: 'iframe'
            });
            return false;
        }

        // Called by the deleteme.php window on a successful delete.
        function imdeleted() {
            top.clearPatient();
        }

        function newEvt() {
            let title = <?php echo xlj('Appointments'); ?>;
            let url = '../../main/calendar/add_edit_event.php?patientid=' + <?php echo js_url($pid); ?>;
            dlgopen(url, '_blank', 800, 500, '', title);
            return false;
        }

        function getWeno() {
            top.restoreSession();
            location.href = '../../weno/indexrx.php'
        }

        function toggleIndicator(target, div) {
            // <i id="show_hide" class="fa fa-lg small fa-eye-slash" title="Click to Hide"></i>
            $mode = $(target).find(".indicator").text();
            if ($mode == <?php echo xlj('collapse'); ?>) {
                $(target).find(".indicator").text(<?php echo xlj('expand'); ?>);
                $("#" + div).hide();
                $.post("../../../library/ajax/user_settings.php", {
                    target: div,
                    mode: 0,
                    csrf_token_form: <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>
                });
            } else {
                $(target).find(".indicator").text(<?php echo xlj('collapse'); ?>);
                $("#" + div).show();
                $.post("../../../library/ajax/user_settings.php", {
                    target: div,
                    mode: 1,
                    csrf_token_form: <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>
                });
            }
        }

        // edit prescriptions dialog.
        // called from stats.php.
        //
        function editScripts(url) {
            var AddScript = function() {
                var __this = $(this);
                __this.find("#clearButton").css("display", "");
                __this.find("#backButton").css("display", "");
                __this.find("#addButton").css("display", "none");

                var iam = top.frames.editScripts;
                iam.location.href = '<?php echo $GLOBALS['webroot'] ?>/controller.php?prescription&edit&id=0&pid=' + <?php echo js_url($pid); ?>;
            };
            var ListScripts = function() {
                var __this = $(this);
                __this.find("#clearButton").css("display", "none");
                __this.find("#backButton").css("display", "none");
                __this.find("#addButton").css("display", "");
                var iam = top.frames.editScripts
                iam.location.href = '<?php echo $GLOBALS['webroot'] ?>/controller.php?prescription&list&id=' + <?php echo js_url($pid); ?>;
            };

            let title = <?php echo xlj('Prescriptions'); ?>;
            let w = 960; // for weno width

            dlgopen(url, 'editScripts', w, 400, '', '', {
                buttons: [{
                        text: <?php echo xlj('Add'); ?>,
                        close: false,
                        id: 'addButton',
                        class: 'btn-primary btn-sm',
                        click: AddScript
                    },
                    {
                        text: <?php echo xlj('Clear'); ?>,
                        close: false,
                        id: 'clearButton',
                        style: 'display:none;',
                        class: 'btn-primary btn-sm',
                        click: AddScript
                    },
                    {
                        text: <?php echo xlj('Back'); ?>,
                        close: false,
                        id: 'backButton',
                        style: 'display:none;',
                        class: 'btn-primary btn-sm',
                        click: ListScripts
                    },
                    {
                        text: <?php echo xlj('Quit'); ?>,
                        close: true,
                        id: 'doneButton',
                        class: 'btn-secondary btn-sm'
                    }
                ],
                onClosed: 'refreshme',
                allowResize: true,
                allowDrag: true,
                dialogId: 'editscripts',
                type: 'iframe'
            });
        }

        /**
         * async function fetchHtml(...)
         *
         * @param {*} url
         * @param {boolean} embedded
         * @param {boolean} sessionRestore
         * @returns {text}
         */
        async function fetchHtml(url, embedded = false, sessionRestore = false) {
            if (sessionRestore === true) {
                // restore cookie before fetch.
                top.restoreSession();
            }
            let csrf = new FormData;
            // a security given.
            csrf.append("csrf_token_form", <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>);
            if (embedded === true) {
                // special formatting in certain widgets.
                csrf.append("embeddedScreen", true);
            }

            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                body: csrf
            });
            return await response.text();
        }

        /**
         * async function placeHtml(...) will await fetch of html then place in divId.
         * This function will return a promise for use to init various items regarding
         * inserted HTML if needed.
         * If divId does not exist, then will skip.
         * Example
         *
         * @param {*} url
         * @param {string} divId id
         * @param {boolean} embedded
         * @param {boolean} sessionRestore
         * @returns {object} promise
         */
        async function placeHtml(url, divId, embedded = false, sessionRestore = false) {
            const contentDiv = document.getElementById(divId);
            if (contentDiv) {
                await fetchHtml(url, embedded, sessionRestore).then(fragment => {
                    contentDiv.innerHTML = fragment;
                });
            }
        }

        if (typeof load_location === 'undefined') {
            function load_location(location) {
                top.restoreSession();
                document.location = location;
            }
        }

        $(function() {
            var msg_updation = '';
            <?php
            if ($GLOBALS['erx_enable']) {
                $soap_status = sqlStatement("select soap_import_status,pid from patient_data where pid=? and soap_import_status in ('1','3')", array($pid));
                while ($row_soapstatus = sqlFetchArray($soap_status)) { ?>
                    top.restoreSession();
                    $.ajax({
                        type: "POST",
                        url: "../../soap_functions/soap_patientfullmedication.php",
                        dataType: "html",
                        data: {
                            patient: <?php echo js_escape($row_soapstatus['pid']); ?>,
                        },
                        async: false,
                        success: function(thedata) {
                            //alert(thedata);
                            msg_updation += thedata;
                        },
                        error: function() {
                            alert('ajax error');
                        }
                    });

                    top.restoreSession();
                    $.ajax({
                        type: "POST",
                        url: "../../soap_functions/soap_allergy.php",
                        dataType: "html",
                        data: {
                            patient: <?php echo js_escape($row_soapstatus['pid']); ?>,
                        },
                        async: false,
                        success: function(thedata) {
                            //alert(thedata);
                            msg_updation += thedata;
                        },
                        error: function() {
                            alert('ajax error');
                        }
                    });
                    <?php
                    if ($GLOBALS['erx_import_status_message']) { ?>
                        if (msg_updation)
                            alert(msg_updation);
                        <?php
                    }
                }
            }
            ?>

            // load divs
            placeHtml("stats.php", "stats_div", true);
            placeHtml("pnotes_fragment.php", 'pnotes_ps_expand').then(() => {
                // must be delegated event!
                $(this).on("click", ".complete_btn", function() {
                    let btn = $(this);
                    let csrf = new FormData;
                    csrf.append("csrf_token_form", <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>);
                    fetch("pnotes_fragment.php?docUpdateId=" + encodeURIComponent(btn.attr('data-id')), {
                            method: "POST",
                            credentials: 'same-origin',
                            body: csrf
                        })
                        .then(function() {
                            placeHtml("pnotes_fragment.php", 'pnotes_ps_expand');
                        });
                });
            });
            placeHtml("disc_fragment.php", "disclosures_ps_expand");
            placeHtml("labdata_fragment.php", "labdata_ps_expand");
            placeHtml("track_anything_fragment.php", "track_anything_ps_expand");
            <?php if ($vitals_is_registered && AclMain::aclCheckCore('patients', 'med')) { ?>
                // Initialize the Vitals form if it is registered and user is authorized.
                placeHtml("vitals_fragment.php", "vitals_ps_expand");
            <?php } ?>

            <?php if ($GLOBALS['enable_cdr'] && $GLOBALS['enable_cdr_crw']) { ?>
                placeHtml("clinical_reminders_fragment.php", "clinical_reminders_ps_expand", true, true).then(() => {
                    // (note need to place javascript code here also to get the dynamic link to work)
                    $(".medium_modal").on('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        dlgopen('', '', 800, 200, '', '', {
                            buttons: [{
                                text: <?php echo xlj('Close'); ?>,
                                close: true,
                                style: 'secondary btn-sm'
                            }],
                            onClosed: 'refreshme',
                            allowResize: false,
                            allowDrag: true,
                            dialogId: 'demreminder',
                            type: 'iframe',
                            url: $(this).attr('href')
                        });
                    });
                });
            <?php } // end crw
            ?>

            <?php if ($GLOBALS['enable_cdr'] && $GLOBALS['enable_cdr_prw']) { ?>
                placeHtml("patient_reminders_fragment.php", "patient_reminders_ps_expand", false, true);
            <?php } // end prw
            ?>

            <?php
            // Initialize for each applicable LBF form.
            $gfres = sqlStatement("SELECT grp_form_id
                FROM layout_group_properties
                WHERE grp_form_id LIKE 'LBF%'
                    AND grp_group_id = ''
                    AND grp_repeats > 0
                    AND grp_activity = 1
                ORDER BY grp_seq, grp_title");
            while ($gfrow = sqlFetchArray($gfres)) { ?>
                $(<?php echo js_escape("#" . $gfrow['grp_form_id'] . "_ps_expand"); ?>).load("lbf_fragment.php?formname=" + <?php echo js_url($gfrow['grp_form_id']); ?>, {
                    csrf_token_form: <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>
                });
            <?php } ?>
            tabbify();

            // modal for dialog boxes
            $(".large_modal").on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                dlgopen('', '', 1000, 600, '', '', {
                    buttons: [{
                        text: <?php echo xlj('Close'); ?>,
                        close: true,
                        style: 'secondary btn-sm'
                    }],
                    allowResize: true,
                    allowDrag: true,
                    dialogId: '',
                    type: 'iframe',
                    url: $(this).attr('href')
                });
            });

            $(".rx_modal").on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var title = <?php echo xlj('Amendments'); ?>;
                dlgopen('', 'editAmendments', 800, 300, '', title, {
                    onClosed: 'refreshme',
                    allowResize: true,
                    allowDrag: true,
                    dialogId: '',
                    type: 'iframe',
                    url: $(this).attr('href')
                });
            });

            // modal for image viewer
            $(".image_modal").on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                dlgopen('', '', 400, 300, '', <?php echo xlj('Patient Images'); ?>, {
                    allowResize: true,
                    allowDrag: true,
                    dialogId: '',
                    type: 'iframe',
                    url: $(this).attr('href')
                });
            });

            $(".deleter").on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                dlgopen('', '', 600, 360, '', '', {
                    buttons: [{
                        text: <?php echo xlj('Close'); ?>,
                        close: true,
                        style: 'secondary btn-sm'
                    }],
                    //onClosed: 'imdeleted',
                    allowResize: false,
                    allowDrag: false,
                    dialogId: 'patdel',
                    type: 'iframe',
                    url: $(this).attr('href')
                });
            });

            $(".iframe1").on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                dlgopen('', '', 350, 300, '', '', {
                    buttons: [{
                        text: <?php echo xlj('Close'); ?>,
                        close: true,
                        style: 'secondary btn-sm'
                    }],
                    allowResize: true,
                    allowDrag: true,
                    dialogId: '',
                    type: 'iframe',
                    url: $(this).attr('href')
                });
            });
            // for patient portal
            $(".small_modal").on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                dlgopen('', '', 550, 550, '', '', {
                    buttons: [{
                        text: <?php echo xlj('Close'); ?>,
                        close: true,
                        style: 'secondary btn-sm'
                    }],
                    allowResize: true,
                    allowDrag: true,
                    dialogId: '',
                    type: 'iframe',
                    url: $(this).attr('href')
                });
            });

            function openReminderPopup() {
                top.restoreSession()
                dlgopen('', 'reminders', 500, 250, '', '', {
                    buttons: [{
                        text: <?php echo xlj('Close'); ?>,
                        close: true,
                        style: 'secondary btn-sm'
                    }],
                    allowResize: true,
                    allowDrag: true,
                    dialogId: '',
                    type: 'iframe',
                    url: $("#reminder_popup_link").attr('href')
                });
            }

            <?php if ($GLOBALS['patient_birthday_alert']) {
                // To display the birthday alert:
                //  1. The patient is not deceased
                //  2. The birthday is today (or in the past depending on global selection)
                //  3. The notification has not been turned off (or shown depending on global selection) for this year
                $birthdayAlert = new BirthdayReminder($pid, $_SESSION['authUserID']);
                if ($birthdayAlert->isDisplayBirthdayAlert()) {
                    ?>
                    // show the active reminder modal
                    dlgopen('', 'bdayreminder', 300, 170, '', false, {
                        allowResize: false,
                        allowDrag: true,
                        dialogId: '',
                        type: 'iframe',
                        url: $("#birthday_popup").attr('href')
                    });

                <?php } elseif ($active_reminders || $all_allergy_alerts) { ?>
                    openReminderPopup();
                <?php } ?>
            <?php } elseif ($active_reminders || $all_allergy_alerts) { ?>
                openReminderPopup();
            <?php } ?>

            // $(".card-title").on('click', "button", (e) => {
            //     console.debug("click");
            //     updateUserVisibilitySetting(e);
            // });
        });

        /**
         * Change the preference to expand/collapse a given card.
         *
         * For the given e element, find the corresponding card body, determine if it is collapsed
         * or shown, and then save the state to the user preferences via an async fetch call POST'ing
         * the updated setting.
         *
         * @var e element The Button that was clicked to collapse/expand the card
         */
        async function updateUserVisibilitySetting(e) {
            const targetID = e.target.getAttribute("data-target");
            const target = document.querySelector(targetID);
            const targetStr = targetID.substring(1);

            let formData = new FormData();
            formData.append("csrf_token_form", <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>);
            formData.append("target", targetStr);
            formData.append("mode", (target.classList.contains("show")) ? 0 : 1);

            const response = await fetch("../../../library/ajax/user_settings.php", {
                method: "POST",
                credentials: 'same-origin',
                body: formData,
            });

            const update = await response.text();
            return update;
        }

        // Update the User's visibility setting when the card header is clicked
        function cardTitleButtonClickListener() {
            const buttons = document.querySelectorAll(".card-title button[data-toggle='collapse']");
            buttons.forEach((b) => {
                b.addEventListener("click", (e) => {
                    updateUserVisibilitySetting(e);
                });
            });
        }

        // JavaScript stuff to do when a new patient is set.
        //
        function setMyPatient() {
            <?php
            if (isset($_GET['set_pid'])) {
                $date_of_death = is_patient_deceased($pid);
                if (!empty($date_of_death)) {
                    $date_of_death = $date_of_death['date_deceased'];
                }
                ?>
                parent.left_nav.setPatient(
                    <?php echo js_escape((is_array($result) ? ($result['fname'] ?? '') : '') . " " . (is_array($result) ? ($result['lname'] ?? '') : '')) .
                        "," . js_escape($pid) . "," . js_escape(is_array($result) ? ($result['pubpid'] ?? '') : '') . ",'' ,";
                if (empty($date_of_death)) {
                        echo js_escape(" " . xl('DOB') . ": " . oeFormatShortDate(is_array($result) ? ($result['DOB_YMD'] ?? '') : '') . " " . xl('Age') . ": " . getPatientAgeDisplay(is_array($result) ? ($result['DOB_YMD'] ?? '') : ''));
                } else {
                        echo js_escape(" " . xl('DOB') . ": " . oeFormatShortDate(is_array($result) ? ($result['DOB_YMD'] ?? '') : '') . " " . xl('Age at death') . ": " . oeFormatAge(is_array($result) ? ($result['DOB_YMD'] ?? '') : '', $date_of_death));
                    }
                ?>);
                var EncounterDateArray = new Array;
                var CalendarCategoryArray = new Array;
                var EncounterIdArray = new Array;
                var Count = 0;
                <?php
                //Encounter details are stored to javacript as array.
                $result4 = sqlStatement("SELECT fe.encounter,fe.date,openemr_postcalendar_categories.pc_catname FROM form_encounter AS fe " .
                    " left join openemr_postcalendar_categories on fe.pc_catid=openemr_postcalendar_categories.pc_catid  WHERE fe.pid = ? order by fe.date desc", array($pid));
                if (sqlNumRows($result4) > 0) {
                    while ($rowresult4 = sqlFetchArray($result4)) { ?>
                        EncounterIdArray[Count] = <?php echo js_escape($rowresult4['encounter']); ?>;
                        EncounterDateArray[Count] = <?php echo js_escape(oeFormatShortDate(date("Y-m-d", strtotime($rowresult4['date'])))); ?>;
                        CalendarCategoryArray[Count] = <?php echo js_escape(xl_appt_category($rowresult4['pc_catname'])); ?>;
                        Count++;
                        <?php
                    }
                }
                ?>
                parent.left_nav.setPatientEncounter(EncounterIdArray, EncounterDateArray, CalendarCategoryArray);
                <?php
            } // end setting new pid
            ?>
            parent.left_nav.syncRadios();
            <?php if ((isset($_GET['set_pid'])) && (isset($_GET['set_encounterid'])) && (intval($_GET['set_encounterid']) > 0)) {
                $query_result = sqlQuery("SELECT `date` FROM `form_encounter` WHERE `encounter` = ?", array($encounter));
                $query_result = ensureArray($query_result); ?>
                encurl = 'encounter/encounter_top.php?set_encounter=' + <?php echo js_url($encounter); ?> + '&pid=' + <?php echo js_url($pid); ?>;
                parent.left_nav.setEncounter(<?php echo js_escape(oeFormatShortDate(date("Y-m-d", strtotime($query_result['date'])))); ?>, <?php echo js_escape($encounter); ?>, 'enc');
                top.restoreSession();
                parent.left_nav.loadFrame('enc2', 'enc', 'patient_file/' + encurl);
            <?php } // end setting new encounter id (only if new pid is also set)
            ?>
        }

        $(window).on('load', function() {
            setMyPatient();
        });

        document.addEventListener("DOMContentLoaded", () => {
            cardTitleButtonClickListener();
        });
    </script>

    <style>
        /* Modern Healthcare Patient Dashboard Styles - Industry Standard */
        .healthcare-dashboard {
            width: 100vw;
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            font-family: 'Inter', 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            overflow-x: hidden;
            position: relative;
        }

        /* Modern Healthcare Header */
        .dashboard-navbar {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 50%, #3498db 100%);
            color: white;
            padding: 0;
            margin-bottom: 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            position: relative;
            overflow: hidden;
        }

        .dashboard-navbar::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="white" opacity="0.1"/><circle cx="75" cy="75" r="1" fill="white" opacity="0.1"/><circle cx="50" cy="10" r="0.5" fill="white" opacity="0.1"/><circle cx="10" cy="60" r="0.5" fill="white" opacity="0.1"/><circle cx="90" cy="40" r="0.5" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            pointer-events: none;
        }

        .header-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 40px;
            position: relative;
            z-index: 2;
            max-width: none;
            width: 100%;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .healthcare-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.8rem;
            font-weight: 700;
            color: #ecf0f1;
        }

        .healthcare-logo i {
            font-size: 2.2rem;
            color: #3498db;
            background: rgba(255,255,255,0.2);
            padding: 8px;
            border-radius: 12px;
        }

        .patient-basic-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .patient-id {
            font-size: 0.85rem;
            opacity: 0.8;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        .header-center {
            flex: 1;
            text-align: center;
            margin: 0 40px;
        }

        .patient-name {
            font-size: 2.4rem;
            font-weight: 800;
            margin: 0 0 8px 0;
            color: #ecf0f1;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
            letter-spacing: -0.5px;
        }

        .patient-details {
            display: flex;
            gap: 16px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 12px;
        }

        .patient-detail-item {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid rgba(255,255,255,0.2);
            transition: all 0.3s ease;
        }

        .patient-detail-item:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-1px);
        }

        .patient-detail-item i {
            font-size: 0.8rem;
            opacity: 0.8;
        }

        .status-badge {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .status-badge.active {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            box-shadow: 0 2px 8px rgba(39, 174, 96, 0.3);
        }

        .status-badge.deceased {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            box-shadow: 0 2px 8px rgba(231, 76, 60, 0.3);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .quick-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 10px 20px;
            font-size: 0.9rem;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }

        .action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .action-btn:hover::before {
            left: 100%;
        }

        .action-btn.primary {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);
        }

        .action-btn.primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(52, 152, 219, 0.4);
        }

        .action-btn.secondary {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            color: white;
            border: 1px solid rgba(255,255,255,0.2);
        }

        .action-btn.secondary:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-2px);
        }

        /* Responsive Design */
        @media (max-width: 1400px) {
            .clinical-grid, .admin-grid {
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            }
            
            .clinical-rows {
                gap: 15px;
            }
        }

        @media (max-width: 1200px) {
            .header-container {
                flex-direction: column;
                gap: 20px;
                padding: 20px;
            }
            
            .header-center {
                margin: 0;
                order: -1;
            }
            
            .patient-name {
                font-size: 2rem;
            }
            
            .patient-details {
                gap: 12px;
            }
            
            .patient-detail-item {
                padding: 6px 12px;
                font-size: 0.85rem;
            }

            .dashboard-content {
                grid-template-columns: 1fr;
                padding: 20px;
                min-height: auto;
            }
            
            .clinical-grid, .admin-grid {
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            }
            
            .clinical-rows {
                gap: 12px;
            }
            
            .row-header {
                padding: 12px 15px;
            }
            
            .row-content {
                padding: 15px;
            }

            .dashboard-card {
                min-height: 400px;
            }
        }

        @media (max-width: 768px) {
            .healthcare-dashboard {
                padding: 0;
            }

            .dashboard-content {
                padding: 15px;
                gap: 20px;
                min-height: auto;
            }

            .dashboard-navbar {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .nav-center {
                margin: 0;
            }

            .patient-info h1 {
                font-size: 1.5rem;
            }

            .patient-details {
                flex-direction: column;
                gap: 10px;
            }

            .nav-right .quick-actions {
                justify-content: center;
            }

            .clinical-grid, .admin-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .clinical-rows {
                gap: 10px;
            }
            
            .row-header {
                padding: 10px 12px;
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
            
            .row-content {
                padding: 12px;
            }

            .dashboard-card {
                min-height: 350px;
            }
        }

        @media (max-width: 480px) {
            .dashboard-navbar {
                padding: 15px 20px;
            }

            .dashboard-content {
                padding: 10px;
                gap: 15px;
            }

            .patient-info h1 {
                font-size: 1.3rem;
            }

            .nav-right .btn {
                width: 100%;
                justify-content: center;
            }

            .clinical-grid, .admin-grid {
                gap: 10px;
            }

            .dashboard-card {
                min-height: 300px;
            }
        }

        /* Main Dashboard Content */
        .dashboard-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin: 0;
            padding: 30px;
            width: 100%;
            box-sizing: border-box;
            min-height: calc(100vh - 200px);
            max-width: none;
        }

        /* Clinical and Admin Grids */
        .clinical-grid, .admin-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
                width: 100%;
            flex: 1;
            align-content: start;
        }

        /* New Clinical Rows Layout */
        .clinical-rows {
            display: flex;
            flex-direction: column;
            gap: 20px;
            width: 100%;
        }

        .clinical-row {
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            overflow: hidden;
        }

        .row-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            background: #e9ecef;
            border-bottom: 1px solid #dee2e6;
        }

        .row-header h4 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
            color: #495057;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .row-header h4 i {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .row-content {
            padding: 20px;
        }

        .clinical-section, .admin-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            border: 1px solid #e9ecef;
            min-height: 200px;
            display: flex;
            flex-direction: column;
            position: relative;
        }
        


        .clinical-section h4, .admin-section h4 {
            margin: 0 0 15px 0;
                font-size: 1rem;
            font-weight: 600;
            color: #495057;
            display: flex;
            align-items: center;
            gap: 8px;
            padding-bottom: 8px;
            border-bottom: 2px solid #dee2e6;
        }

        .clinical-section h4 i, .admin-section h4 i {
            color: #6c757d;
            font-size: 0.9rem;
        }

        /* Billing & Payment Section (Full Width) */
        .billing-payment-section {
            width: 100%;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 25px;
            border: 1px solid #e9ecef;
            min-height: 200px;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .billing-payment-section h4 {
            margin: 0 0 20px 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: #495057;
            display: flex;
            align-items: center;
            gap: 8px;
            padding-bottom: 10px;
            border-bottom: 2px solid #dee2e6;
        }

        .billing-payment-section h4 i {
            color: #6c757d;
            font-size: 1rem;
        }

        /* Recent Charges Section */
        .recent-charges-section {
            margin-top: 1.5rem;
            border-top: 1px solid #dee2e6;
            padding-top: 1rem;
        }

        .recent-charges-section h5 {
            margin: 0 0 15px 0;
            font-size: 1rem;
            font-weight: 600;
            color: #495057;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .recent-charges-section h5 i {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .charges-table {
            background: white;
            border-radius: 6px;
            border: 1px solid #e9ecef;
            overflow: hidden;
        }

        .charge-header {
            display: grid;
            grid-template-columns: 80px 1fr 50px 70px 100px;
            gap: 10px;
            padding: 10px 15px;
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            font-weight: 600;
            font-size: 0.85rem;
            color: #495057;
        }

        .charge-item {
            display: grid;
            grid-template-columns: 80px 1fr 50px 70px 100px;
            gap: 10px;
            padding: 8px 15px;
            border-bottom: 1px solid #f1f3f4;
            font-size: 0.85rem;
            align-items: center;
        }

        .charge-item:last-child {
            border-bottom: none;
        }

        .charge-item:hover {
            background: #f8f9fa;
        }

        .charge-date {
            color: #6c757d;
            font-size: 0.8rem;
        }

        .charge-description {
            color: #212529;
            font-weight: 500;
        }

        .charge-qty {
            text-align: center;
            color: #6c757d;
            font-weight: 500;
        }

        .charge-fee {
            text-align: right;
            color: #495057;
            font-weight: 500;
        }

        .charge-amount {
            font-weight: 600;
            text-align: right;
        }

        .payment-amount {
            color: #28a745;
        }



        .no-charges {
            padding: 30px 15px;
            text-align: center;
            color: #6c757d;
        }

        .no-charges i {
            font-size: 2rem;
            margin-bottom: 10px;
            display: block;
        }

        .no-charges p {
            margin: 0;
            font-size: 0.9rem;
        }

        .charges-summary {
            padding: 12px 15px;
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            font-weight: 600;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }

        .summary-row:last-child {
            margin-bottom: 0;
        }

        .payment-total {
            color: #28a745;
        }

        .total-label {
            color: #495057;
        }

        .total-amount {
            font-size: 1.1rem;
        }

        /* Outstanding Balance Section */
        .outstanding-balance-section {
            margin-top: 1rem;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }

        .balance-display {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .balance-label {
            color: #495057;
        }

        .balance-amount {
            font-size: 1.2rem;
        }

        /* Payment Buttons Section */
        .payment-buttons-section {
            margin-top: 1.5rem;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .payment-buttons-section .btn {
            min-width: 150px;
            padding: 10px 20px;
            font-size: 1rem;
            font-weight: 600;
        }

        .pay-now-btn {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            border: none;
            color: white;
            box-shadow: 0 4px 15px rgba(23, 162, 184, 0.3);
            transition: all 0.3s ease;
        }

        .pay-now-btn:hover {
            background: linear-gradient(135deg, #138496 0%, #117a8b 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(23, 162, 184, 0.4);
            color: white;
        }

        .pay-now-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 10px rgba(23, 162, 184, 0.3);
        }

        /* Responsive adjustments for charges table */
        @media (max-width: 768px) {
            .charge-header,
            .charge-item {
                grid-template-columns: 60px 1fr 40px 60px 70px;
                gap: 6px;
                padding: 6px 8px;
                font-size: 0.75rem;
            }

            .charge-date {
                font-size: 0.7rem;
            }
            
            .charge-qty,
            .charge-fee {
                font-size: 0.7rem;
            }
        }

        /* Modern Dashboard Cards */
        .dashboard-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.08);
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            position: relative;
            height: 100%;
            display: flex;
            flex-direction: column;
            width: 100%;
        }

        .dashboard-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
        }

        .dashboard-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 16px 40px rgba(0,0,0,0.15);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }

        .card-header h3 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 700;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 12px;
            letter-spacing: -0.5px;
        }

        .card-header h3 i {
            color: #3498db;
            font-size: 1.1rem;
            background: rgba(52, 152, 219, 0.1);
            padding: 8px;
            border-radius: 8px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        .header-actions .btn {
            padding: 8px 16px;
            font-size: 0.85rem;
            font-weight: 600;
            border-radius: 20px;
            border: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .header-actions .btn-success {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(39, 174, 96, 0.3);
        }

        .header-actions .btn-success:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.4);
        }

        .card-content {
            padding: 25px;
            height: 100%;
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        /* Data Items */
        .data-item {
            padding: 12px 0;
            border-bottom: 1px solid #f1f3f4;
            transition: all 0.3s ease;
        }

        .data-item:last-child {
            border-bottom: none;
        }

        .data-item:hover {
            background: #f8f9fa;
            margin: 0 -20px;
            padding: 12px 20px;
        }

        .item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .item-title {
            font-weight: 600;
            color: #495057;
            font-size: 0.95rem;
        }

        .item-status {
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .item-status.active {
            background: #d4edda;
            color: #155724;
        }

        .item-status.resolved {
            background: #d1ecf1;
            color: #0c5460;
        }

        .item-status.allergy {
            background: #f8d7da;
            color: #721c24;
        }

        .item-status.completed {
            background: #d4edda;
            color: #155724;
        }

        .item-status.pending {
            background: #fff3cd;
            color: #856404;
        }

        .item-date {
            font-size: 0.8rem;
            color: #6c757d;
        }

        .item-amount {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .item-amount.positive {
            color: #28a745;
        }

        .item-amount.negative {
            color: #dc3545;
        }

        .item-details {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .detail {
            font-size: 0.85rem;
            color: #6c757d;
        }

        .item-actions {
            margin-top: 8px;
        }

        .item-actions .btn {
            padding: 4px 8px;
            font-size: 0.75rem;
            border-radius: 12px;
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 30px 20px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 10px;
            opacity: 0.5;
        }

        .empty-state p {
            margin: 0;
            font-size: 0.9rem;
        }

        /* Billing Summary */
        .billing-summary {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .billing-item {
                display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f1f3f4;
        }

        .billing-item:last-child {
            border-bottom: none;
        }

        .billing-item .label {
            font-weight: 500;
            color: #495057;
            font-size: 0.9rem;
        }

        .billing-item .value {
            font-weight: 600;
            color: #212529;
            font-size: 0.9rem;
        }

        /* Buttons */
        .btn {
            border: none;
                border-radius: 8px;
            padding: 8px 16px;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-primary {
            background: #007bff;
            color: white;
        }

        .btn-primary:hover {
            background: #0056b3;
            color: white;
        }

        .btn-outline-primary {
            background: transparent;
            color: #007bff;
            border: 1px solid #007bff;
        }

        .btn-outline-primary:hover {
            background: #007bff;
            color: white;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #1e7e34;
            color: white;
        }

        .btn-outline {
            background: transparent;
            color: #6c757d;
            border: 1px solid #6c757d;
        }

        .btn-outline:hover {
            background: #6c757d;
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.8rem;
        }

        /* Animation Classes */
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Accessibility Improvements */
        .dashboard-card:focus-within {
            outline: 2px solid #007bff;
            outline-offset: 2px;
        }

        .btn:focus {
            outline: 2px solid #007bff;
            outline-offset: 2px;
        }

        /* Print Styles */
        @media print {
            .nav-right,
            .card-header .btn,
            .item-actions {
                display: none !important;
            }

            .dashboard-card {
                break-inside: avoid;
                box-shadow: none;
                border: 1px solid #000;
            }
        }

        /* Override OpenEMR container constraints */
        .full-width-container {
            width: 100vw !important;
            max-width: none !important;
            margin: 0 !important;
            padding: 0 !important;
            position: relative;
            left: 50%;
            right: 50%;
            margin-left: -50vw !important;
            margin-right: -50vw !important;
        }

        /* Force full width on body and html */
        body.patient-demographic {
            width: 100vw !important;
            max-width: none !important;
            margin: 0 !important;
            padding: 0 !important;
            overflow-x: hidden;
        }

        .patient-detail-item:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-1px);
        }

        .patient-detail-item.edit-demographics-btn {
            background: rgba(52, 152, 219, 0.2);
            border: 1px solid rgba(52, 152, 219, 0.3);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .patient-detail-item.edit-demographics-btn:hover {
            background: rgba(52, 152, 219, 0.35);
            border-color: rgba(52, 152, 219, 0.5);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
        }

        .patient-detail-item.edit-demographics-btn i {
            color: #3498db;
        }

        /* Payment & Checkout Styles */
        .payment-actions {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .action-buttons .btn {
            flex: 1;
            min-width: 120px;
            justify-content: center;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .action-buttons .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .payment-summary {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 16px;
            border: 1px solid #e9ecef;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .summary-item:last-child {
            border-bottom: none;
        }

        .summary-item .label {
            font-weight: 500;
            color: #495057;
            font-size: 0.9rem;
        }

        .summary-item .value {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .summary-item .value.text-danger {
            color: #dc3545;
        }

        .summary-item .value.text-muted {
            color: #6c757d;
        }

        /* Transaction Items */
        .transaction-item {
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 8px;
            transition: all 0.3s ease;
        }

        .transaction-item:hover {
            border-color: #007bff;
            box-shadow: 0 2px 8px rgba(0,123,255,0.1);
        }

        /* Section header actions styling */
        .section-header-actions {
            margin-bottom: 1rem;
            text-align: right;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .section-header-actions .btn {
            margin-left: 0;
            font-size: 0.8rem;
            padding: 6px 12px;
            white-space: nowrap;
        }

        /* Clinical section styling updates */
        .clinical-section h4 {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .clinical-section h4 i {
            margin-right: 0.5rem;
        }

        /* Status badge styling for administered items */
        .item-status.administered {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Prescription item styling */
        .prescription-item {
            border-left: 4px solid #3498db;
        }

        /* Immunization item styling */
        .immunization-item {
            border-left: 4px solid #e74c3c;
        }
    </style>
</head>

<body class="mt-1 patient-demographic bg-light">

    <?php
    // Create and fire the patient demographics view event
    $viewEvent = new ViewEvent($pid);
    $viewEvent = $GLOBALS["kernel"]->getEventDispatcher()->dispatch(ViewEvent::EVENT_HANDLE, $viewEvent, 10);
    $thisauth = AclMain::aclCheckCore('patients', 'demo');

    if (!$thisauth || !$viewEvent->authorized()) {
        echo $twig->getTwig()->render('core/unauthorized-partial.html.twig', ['pageTitle' => xl("Medical Dashboard")]);
        exit();
    }
    ?>

    <div id="container_div" class="full-width-container" style="width: 100%; max-width: none; margin: 0; padding: 0;">
        <a href='../reminder/active_reminder_popup.php' id='reminder_popup_link' style='display: none' onclick='top.restoreSession()'></a>
        <a href='../birthday_alert/birthday_pop.php?pid=<?php echo attr_url($pid); ?>&user_id=<?php echo attr_url($_SESSION['authUserID']); ?>' id='birthday_popup' style='display: none;' onclick='top.restoreSession()'></a>
        <?php

        if ($thisauth) {
            if (is_array($result) && isset($result['squad']) && !AclMain::aclCheckCore('squads', $result['squad'])) {
                $thisauth = 0;
            }
        }

        if ($thisauth) :
            // require_once("$include_root/patient_file/summary/dashboard_header.php");
        endif;

        $list_id = "dashboard"; // to indicate nav item is active, count and give correct id
        // Collect the patient menu then build it
        $menuPatient = new PatientMenuRole($twig);
        // $menuPatient->displayHorizNavBarMenu();
        // Get the document ID of the patient ID card if access to it is wanted here.
        $idcard_doc_id = false;
        if ($GLOBALS['patient_id_category_name']) {
            $idcard_doc_id = get_document_by_catg($pid, $GLOBALS['patient_id_category_name']);
        }
        ?>
        <!-- Modern Healthcare Patient Dashboard -->
        <div class="healthcare-dashboard">
            <!-- Modern Healthcare Header -->
            <div class="dashboard-navbar">
                <div class="header-container">
                    <div class="header-left">
                        <div class="healthcare-logo">
                            <i class="fa fa-heartbeat"></i>
                            <span><?php echo text((is_array($result) ? ($result['fname'] ?? '') : '') . ' ' . (is_array($result) ? ($result['lname'] ?? '') : '')); ?></span>
                    </div>
                        <div class="patient-basic-info">
                            <span class="patient-id">Patient ID: <?php echo text($pid); ?></span>
                        </div>
                    </div>
                    
                    <div class="header-center">
                        <div class="patient-details">
                            <div class="patient-detail-item">
                                <i class="fa fa-calendar"></i>
                                <span><?php echo text(oeFormatShortDate(is_array($result) ? ($result['DOB_YMD'] ?? '') : '')); ?> (<?php echo text(getPatientAge(is_array($result) ? ($result['DOB_YMD'] ?? '') : '')); ?> years)</span>
                            </div>
                            <div class="patient-detail-item">
                                <i class="fa fa-venus-mars"></i>
                                <span><?php echo text(is_array($result) ? ($result['sex'] ?? '') : ''); ?></span>
                            </div>
                            <a href="demographics_full.php?pid=<?php echo attr_url($pid); ?>" class="patient-detail-item edit-demographics-btn" style="text-decoration: none; color: inherit;">
                                <i class="fa fa-edit"></i>
                                <span><?php echo xlt('Edit Demographics'); ?></span>
                            </a>
                        </div>
                    </div>
                    
                    <div class="header-right">
                        <div class="quick-actions">
                            <a href="../history/encounters.php" class="action-btn secondary">
                                <i class="fa fa-history"></i>
                                <span>History</span>
                            </a>
                            <?php if (AclMain::aclCheckCore('admin', 'super')) : ?>
                            <a href="javascript:void(0)" onclick="addEncounter()" class="action-btn primary">
                                <i class="fa fa-plus-circle"></i>
                                <span>Add Encounter</span>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                </div>

            <!-- Deceased Notice -->
            <?php if ($deceased > 0) : ?>
                <div class="alert alert-warning deceased-notice" style="margin: 20px 30px; border-radius: 12px; border: none; background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%); color: #856404; box-shadow: 0 4px 15px rgba(255, 193, 7, 0.2);">
                    <i class="fa fa-exclamation-triangle"></i>
                    <?php echo $twig->getTwig()->render('patient/partials/deceased.html.twig', [
                        'deceasedDays' => deceasedDays($deceased),
                    ]); ?>
                </div>
            <?php endif; ?>

            <!-- Main Dashboard Content -->
            <div class="dashboard-content">
                <!-- Clinical Information Section -->
                <div class="dashboard-card clinical-info-card">
                    <div class="card-header">
                        <h3><i class="fa fa-stethoscope"></i> Clinical Information</h3>
                    </div>
                    <div class="card-content">
                        <div class="clinical-rows">
                            <!-- Prescriptions Row -->
                            <div class="clinical-row">
                                <div class="row-header">
                                    <h4><i class="fa fa-prescription"></i> Prescriptions</h4>
                                    <button class="btn btn-sm btn-success" onclick="addPrescriptionWithBilling()">
                                        <i class="fa fa-plus"></i> Add Charge
                                    </button>
                                </div>
                                <?php
                                $prescriptions = sqlStatement("SELECT * FROM prescriptions WHERE patient_id = ? AND active = 1 ORDER BY date_added DESC LIMIT 3", array($pid));
                                $hasPrescriptions = sqlNumRows($prescriptions) > 0;
                                
                                if ($hasPrescriptions) {
                                    echo '<div class="row-content">';
                                    while ($prescription = sqlFetchArray($prescriptions)) {
                                        echo '<div class="data-item prescription-item">';
                                        echo '<div class="item-header">';
                                        echo '<span class="item-title">' . text($prescription['drug'] ?? 'Prescription') . '</span>';
                                        echo '<span class="item-status active">' . xl('Active') . '</span>';
                                        echo '</div>';
                                        echo '<div class="item-details">';
                                        echo '<span class="detail">' . xl('Date') . ': ' . text(oeFormatShortDate($prescription['date_added'])) . '</span>';
                                        if (!empty($prescription['dosage'])) {
                                            echo '<span class="detail">' . xl('Dosage') . ': ' . text($prescription['dosage']) . '</span>';
                                        }
                                        echo '</div>';
                                        echo '</div>';
                                    }
                                    echo '</div>';
                                }
                                ?>
                            </div>

                            <!-- Medications Row -->
                            <div class="clinical-row">
                                <div class="row-header">
                                    <h4><i class="fa fa-pills"></i> Medications</h4>
                                    <button class="btn btn-sm btn-success" onclick="addMedicationWithBilling()">
                                        <i class="fa fa-plus"></i> Add Charge
                                    </button>
                                </div>
                                <?php
                                $medications = sqlStatement("SELECT * FROM lists WHERE pid = ? AND type = 'medication' AND activity = 1 ORDER BY date DESC LIMIT 3", array($pid));
                                $hasMedications = sqlNumRows($medications) > 0;
                                
                                if ($hasMedications) {
                                    echo '<div class="row-content">';
                                    while ($medication = sqlFetchArray($medications)) {
                                        echo '<div class="data-item medication-item">';
                                        echo '<div class="item-header">';
                                        echo '<span class="item-title">' . text($medication['title']) . '</span>';
                                        echo '<span class="item-status active">' . xl('Active') . '</span>';
                                        echo '</div>';
                                        echo '<div class="item-details">';
                                        echo '<span class="detail">' . xl('Started') . ': ' . text(oeFormatShortDate($medication['begdate'])) . '</span>';
                                        if (!empty($medication['comments'])) {
                                            echo '<span class="detail">' . text(substr($medication['comments'], 0, 50)) . '...</span>';
                                        }
                                        echo '</div>';
                                        echo '</div>';
                                    }
                                    echo '</div>';
                                }
                                ?>
                            </div>

                            <!-- Administered Row -->
                            <div class="clinical-row">
                                <div class="row-header">
                                    <h4><i class="fa fa-syringe"></i> Administered</h4>
                                    <button class="btn btn-sm btn-success" onclick="addAdministeredWithBilling()">
                                        <i class="fa fa-plus"></i> Add Charge
                                    </button>
                                </div>
                                <?php
                                $immunizations = sqlStatement("SELECT * FROM immunizations WHERE patient_id = ? ORDER BY administered_date DESC LIMIT 3", array($pid));
                                $hasImmunizations = sqlNumRows($immunizations) > 0;
                                
                                if ($hasImmunizations) {
                                    echo '<div class="row-content">';
                                    while ($immunization = sqlFetchArray($immunizations)) {
                                        // Get vaccine name from list_options if immunization_id exists
                                        $vaccine_name = 'Immunization';
                                        if (!empty($immunization['immunization_id'])) {
                                            $vaccine_result = sqlQuery("SELECT title FROM list_options WHERE list_id = 'immunizations' AND option_id = ?", array($immunization['immunization_id']));
                                            if ($vaccine_result && !empty($vaccine_result['title'])) {
                                                $vaccine_name = $vaccine_result['title'];
                                            }
                                        }
                                        
                                        echo '<div class="data-item immunization-item">';
                                        echo '<div class="item-header">';
                                        echo '<span class="item-title">' . text($vaccine_name) . '</span>';
                                        echo '<span class="item-status administered">' . xl('Administered') . '</span>';
                                        echo '</div>';
                                        echo '<div class="item-details">';
                                        echo '<span class="detail">' . xl('Date') . ': ' . text(oeFormatShortDate($immunization['administered_date'])) . '</span>';
                                        if (!empty($immunization['manufacturer'])) {
                                            echo '<span class="detail">' . xl('Manufacturer') . ': ' . text($immunization['manufacturer']) . '</span>';
                                        }
                                        echo '</div>';
                                        echo '</div>';
                                    }
                                    echo '</div>';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Administrative Information Section -->
                <div class="dashboard-card admin-info-card">
                    <div class="card-header">
                        <h3><i class="fa fa-cogs"></i> Administrative Information</h3>
                        <div class="header-actions">
                            <button class="btn btn-sm btn-success" onclick="location.href='../transaction/add_transaction.php'">
                                <i class="fa fa-plus"></i> Add Transaction
                            </button>
                        </div>
                    </div>
                    <div class="card-content">
                        <!-- Billing & Payment Section (Full Width) -->
                        <div class="billing-payment-section">
                            <h4><i class="fa fa-money-bill"></i> Billing & Payment</h4>

                            <!-- Charges Section -->
                            <div class="recent-charges-section" style="margin-top: 0.5rem;">
                                <h5><i class="fa fa-list"></i> <?php echo xlt('Charges & Payments'); ?></h5>
                                <?php
                                // Get recent charges and payments separately and combine them
                                $all_entries = array();
                                
                                // Get charges from billing table with quantity and fee information
                                $charges_query = "SELECT id, date, code_text as description, (fee / units) as unit_fee, units, fee as amount FROM billing WHERE pid = ? AND activity = 1 AND fee > 0 ORDER BY date DESC, id DESC LIMIT 10";
                                $charges_result = sqlStatement($charges_query, array($pid));
                                
                                while ($charge = sqlFetchArray($charges_result)) {
                                    $all_entries[] = array(
                                        'id' => $charge['id'],
                                        'date' => $charge['date'],
                                        'description' => $charge['description'],
                                        'amount' => $charge['amount'],
                                        'quantity' => $charge['units'],
                                        'unit_fee' => $charge['unit_fee'],
                                        'type' => 'charge'
                                    );
                                }
                                
                                // Get payments from ar_activity table
                                $payments_query = "SELECT sequence_no, post_date as date, code, pay_amount FROM ar_activity WHERE pid = ? AND pay_amount > 0 AND deleted IS NULL ORDER BY post_date DESC, sequence_no DESC LIMIT 10";
                                $payments_result = sqlStatement($payments_query, array($pid));
                                
                                while ($payment = sqlFetchArray($payments_result)) {
                                    $payment_method = !empty($payment['code']) ? $payment['code'] : 'Stripe';
                                    $all_entries[] = array(
                                        'id' => $payment['sequence_no'],
                                        'date' => $payment['date'],
                                        'description' => 'Payment - ' . $payment_method,
                                        'amount' => -$payment['pay_amount'],
                                        'quantity' => null,
                                        'unit_fee' => null,
                                        'type' => 'payment'
                                    );
                                }
                                
                                // Sort all entries by date (newest first)
                                usort($all_entries, function($a, $b) {
                                    return strtotime($b['date']) - strtotime($a['date']);
                                });
                                
                                // Limit to 10 entries
                                $all_entries = array_slice($all_entries, 0, 10);
                                
                                $hasEntries = false;
                                $totalCharges = 0;
                                $totalPayments = 0;
                                
                                echo '<div class="charges-table">';
                                echo '<div class="charge-header">';
                                echo '<span class="charge-date">' . xlt('Date') . '</span>';
                                echo '<span class="charge-description">' . xlt('Description') . '</span>';
                                echo '<span class="charge-qty">' . xlt('Qty') . '</span>';
                                echo '<span class="charge-fee">' . xlt('Fee') . '</span>';
                                echo '<span class="charge-amount">' . xlt('Amount') . '</span>';
                                echo '</div>';
                                
                                foreach ($all_entries as $entry) {
                                    $hasEntries = true;
                                    
                                    if ($entry['type'] == 'charge') {
                                        $totalCharges += $entry['amount'];
                                    } else {
                                        $totalPayments += abs($entry['amount']);
                                    }
                                    
                                    echo '<div class="charge-item">';
                                    echo '<span class="charge-date">' . text(oeFormatShortDate($entry['date'])) . '</span>';
                                    echo '<span class="charge-description">' . text($entry['description']) . '</span>';
                                    if ($entry['type'] == 'charge') {
                                        echo '<span class="charge-qty">' . text($entry['quantity']) . '</span>';
                                        echo '<span class="charge-fee">$' . text(number_format($entry['unit_fee'], 2)) . '</span>';
                                    } else {
                                        echo '<span class="charge-qty">-</span>';
                                        echo '<span class="charge-fee">-</span>';
                                    }
                                    echo '<span class="charge-amount ' . ($entry['type'] == 'payment' ? 'payment-amount' : 'charge-amount') . '">';
                                    echo ($entry['type'] == 'payment' ? '-' : '') . '$' . text(number_format(abs($entry['amount']), 2));
                                    echo '</span>';
                                    echo '</div>';
                                }
                                
                                if (!$hasEntries) {
                                    echo '<div class="no-charges">';
                                    echo '<i class="fa fa-info-circle"></i>';
                                    echo '<p>' . xlt('No charges or payments recorded') . '</p>';
                                    echo '</div>';
                                }
                                echo '</div>';
                                
                                if ($hasEntries) {
                                    echo '<div class="charges-summary">';
                                    echo '<div class="summary-row">';
                                    echo '<span class="total-label">' . xlt('Total Charges') . ':</span>';
                                    echo '<span class="total-amount">$' . text(number_format($totalCharges, 2)) . '</span>';
                                    echo '</div>';
                                    if ($totalPayments > 0) {
                                        echo '<div class="summary-row">';
                                        echo '<span class="total-label">' . xlt('Total Payments') . ':</span>';
                                        echo '<span class="total-amount payment-total">-$' . text(number_format($totalPayments, 2)) . '</span>';
                                        echo '</div>';
                                    }
                                    echo '</div>';
                                }
                                ?>
                            </div>

                            <!-- Outstanding Balance Section -->
                            <div class="outstanding-balance-section" style="margin-top: 1rem;">
                                <div class="balance-display">
                                    <span class="balance-label"><?php echo xlt('Outstanding Balance'); ?>:</span>
                                            <?php
                                    // Use the same comprehensive balance calculation as in Pay Now form
                                    // Check for any charges in billing table (including clinical billing) - include unbilled charges
                                    $billing_charges = sqlQuery(
                                        "SELECT SUM(fee) AS total_charges FROM billing WHERE pid = ? AND activity = 1 AND fee > 0",
                                        array($pid)
                                    );

                                    // Check for any drug sales
                                    $drug_charges = sqlQuery(
                                        "SELECT SUM(fee) AS total_charges FROM drug_sales WHERE pid = ? AND fee > 0",
                                        array($pid)
                                    );

                                    // Check for any payments made
                                    $payments = sqlQuery(
                                        "SELECT SUM(pay_amount) AS total_payments FROM ar_activity WHERE pid = ? AND deleted IS NULL AND pay_amount > 0",
                                        array($pid)
                                    );

                                    // Calculate total outstanding balance with explicit type casting and null handling
                                    $billing_total = 0.0;
                                    if (is_object($billing_charges) && method_exists($billing_charges, 'FetchRow')) {
                                        $billing_row = $billing_charges->FetchRow();
                                        if ($billing_row && isset($billing_row['total_charges']) && $billing_row['total_charges'] !== null && $billing_row['total_charges'] !== '') {
                                            $billing_total = floatval($billing_row['total_charges']);
                                        }
                                    }

                                    $drug_total = 0.0;
                                    if (is_object($drug_charges) && method_exists($drug_charges, 'FetchRow')) {
                                        $drug_row = $drug_charges->FetchRow();
                                        if ($drug_row && isset($drug_row['total_charges']) && $drug_row['total_charges'] !== null && $drug_row['total_charges'] !== '') {
                                            $drug_total = floatval($drug_row['total_charges']);
                                        }
                                    }

                                    $payment_total = 0.0;
                                    if (is_object($payments) && method_exists($payments, 'FetchRow')) {
                                        $payment_row = $payments->FetchRow();
                                        if ($payment_row && isset($payment_row['total_payments']) && $payment_row['total_payments'] !== null && $payment_row['total_payments'] !== '') {
                                            $payment_total = floatval($payment_row['total_payments']);
                                        }
                                    }

                                    $total_charges = $billing_total + $drug_total;
                                    $calculated_balance = $total_charges - $payment_total;

                                    // Use the calculated balance as the primary source since it includes all charges
                                    $outstanding_balance = $calculated_balance;

                                    // Ensure balance is not negative
                                    if ($outstanding_balance < 0) {
                                        $outstanding_balance = 0;
                                    }
                                    ?>
                                    <span class="balance-amount">$<?php echo text(number_format($outstanding_balance, 2)); ?></span>
                                        </div>
                                    </div>

                            <!-- Payment Buttons Section -->
                            <div class="payment-buttons-section" style="margin-top: 1.5rem;">
                                <?php if (!empty($GLOBALS['payment_gateway']) && $GLOBALS['payment_gateway'] == 'Stripe') { ?>
                                <button class="btn btn-sm btn-info pay-now-btn" onclick="openStripePayment()">
                                    <i class="fa fa-credit-card"></i> <?php echo xlt('Pay Now'); ?>
                                </button>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Hidden Sections (for future use) -->
            <div class="hidden-sections" style="display: none;">
                <!-- All other sections can be added here in the future -->
            </div>
        </div>

                    <!-- Hidden Old Dashboard Sections -->
                    <div class="hidden-sections" style="display: none;">
                        <!-- Patient Portal Card -->
                        <div class="dashboard-card portal-card">
                            <div class="card-header">
                                <h3><i class="fa fa-user-circle"></i> Patient Portal</h3>
                            </div>
                            <div class="card-body">
                                <?php
                                $portalCard = new PortalCard($GLOBALS);
                                echo $twig->getTwig()->render('patient/partials/portal.html.twig', [
                                    'isPortalEnabled' => isPortalEnabled(),
                                    'isPortalSiteAddressValid' => isPortalSiteAddressValid(),
                                    'isPortalAllowed' => isPortalAllowed($pid),
                                    'isContactEmail' => isContactEmail($pid),
                                    'isEnforceSigninEmailPortal' => isEnforceSigninEmailPortal(),
                                    'isApiAllowed' => isApiAllowed($pid),
                                    'areCredentialsCreated' => areCredentialsCreated($pid),
                                    'portalLoginHref' => $GLOBALS['portal_onsite_two_address'] ?? '',
                                    'pid' => $pid,
                                    'allowpp' => $result['allow_patient_portal'] ?? 'NO'
                                ]);
                                ?>
                            </div>
                        </div>

                        <!-- Appointments Card -->
                        <div class="dashboard-card appointments-card">
                            <div class="card-header">
                                <h3><i class="fa fa-calendar"></i> Appointments</h3>
                                <div class="card-actions">
                                    <button class="btn btn-sm btn-outline-success" onclick="addAppointment()">
                                        <i class="fa fa-plus"></i> Add
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php
                                $dispatchResult = $ed->dispatch(CardRenderEvent::EVENT_HANDLE, new CardRenderEvent('appointments'));
                        $viewArgs = [
                                    'title' => xl('Appointments'),
                                    'id' => 'appointments_expand',
                                    'initiallyCollapsed' => false,
                            'prependedInjection' => $dispatchResult->getPrependedInjection(),
                            'appendedInjection' => $dispatchResult->getAppendedInjection(),
                        ];
                                echo $twig->getTwig()->render('patient/card/appointments.html.twig', $viewArgs);
                                ?>
                            </div>
                        </div>

                        <!-- Prescriptions Card -->
                        <div class="dashboard-card prescriptions-card">
                            <div class="card-header">
                                <h3><i class="fa fa-prescription"></i> Prescriptions</h3>
                                <div class="card-actions">
                                    <button class="btn btn-sm btn-outline-success" onclick="addPrescription()">
                                        <i class="fa fa-plus"></i> Add
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php
                                $dispatchResult = $ed->dispatch(CardRenderEvent::EVENT_HANDLE, new CardRenderEvent('prescriptions'));
                                $viewArgs = [
                                    'title' => xl('Prescriptions'),
                                    'id' => 'prescriptions_expand',
                                    'initiallyCollapsed' => false,
                                    'prependedInjection' => $dispatchResult->getPrependedInjection(),
                                    'appendedInjection' => $dispatchResult->getAppendedInjection(),
                                ];
                                echo $twig->getTwig()->render('patient/card/rx.html.twig', $viewArgs);
                                ?>
                            </div>
                        </div>

                        <!-- Advanced Directives Card -->
                        <div class="dashboard-card advanced-directives-card">
                            <div class="card-header">
                                <h3><i class="fa fa-file-contract"></i> Advanced Directives</h3>
                                <div class="card-actions">
                                    <button class="btn btn-sm btn-outline-success" onclick="addAdvancedDirective()">
                                        <i class="fa fa-plus"></i> Add
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php
                                // Get advanced directives data
                                $advDirData = [];
                                $counterFlag = false;
                                
                                // Get patient's advance directive status
                                $adStatus = sqlQuery("SELECT completed_ad, ad_reviewed FROM patient_data WHERE pid = ?", array($pid));
                                $adStatus = ensureArray($adStatus);
                                if ($adStatus && is_array($adStatus)) {
                                    if (!empty($adStatus['completed_ad'])) {
                                        $advDirData[] = [
                                            'type' => 'status',
                                            'label' => xl('Status'),
                                            'value' => $adStatus['completed_ad'] == 'YES' ? xl('Completed') : xl('Not Completed'),
                                            'class' => $adStatus['completed_ad'] == 'YES' ? 'text-success' : 'text-warning'
                                        ];
                                        $counterFlag = true;
                                    }
                                    
                                    if (!empty($adStatus['ad_reviewed'])) {
                                        $advDirData[] = [
                                            'type' => 'reviewed',
                                            'label' => xl('Last Reviewed'),
                                            'value' => oeFormatShortDate($adStatus['ad_reviewed']),
                                            'class' => 'text-info'
                                        ];
                                        $counterFlag = true;
                                    }
                                }
                                
                                // Note: Advanced directive documents can be uploaded through the Documents section
                                // For now, we're just showing the status and review date from patient_data
                                
                                if ($counterFlag) {
                                    echo '<div class="list-group list-group-flush">';
                                    echo '<div class="list-group-item p-3 border-left-4 border-warning">';
                                    echo '<div class="d-flex justify-content-between align-items-start mb-2">';
                                    echo '<h6 class="mb-0 text-warning"><i class="fa fa-file-contract"></i> ' . xl('Advanced Directives Status') . '</h6>';
                                    echo '</div>';
                                    
                                    foreach ($advDirData as $item) {
                                        if ($item['type'] == 'status') {
                                            echo '<div class="row mb-2">';
                                            echo '<div class="col-md-6">';
                                            echo '<small class="text-muted">' . xl('Completion Status') . ':</small><br>';
                                            echo '<span class="badge badge-' . ($item['value'] == xl('Completed') ? 'success' : 'warning') . '">' . text($item['value']) . '</span>';
                                            echo '</div>';
                                        } elseif ($item['type'] == 'reviewed') {
                                            echo '<div class="col-md-6">';
                                            echo '<small class="text-muted">' . xl('Last Reviewed') . ':</small><br>';
                                            echo '<strong>' . text($item['value']) . '</strong>';
                                            echo '</div>';
                                            echo '</div>';
                                        }
                                    }
                                    
                                    echo '<div class="mt-3">';
                                    echo '<small class="text-muted">' . xl('Note') . ':</small><br>';
                                    echo '<span class="text-dark">' . xl('Advanced directive documents can be uploaded through the Documents section') . '</span>';
                                    echo '</div>';
                                    
                                    echo '</div>';
                                    echo '</div>';
                                } else {
                                    echo '<div class="text-center text-muted py-3">';
                                    echo '<i class="fa fa-file-contract fa-2x mb-2"></i>';
                                    echo '<p>' . xlt('No Advanced Directives recorded') . '</p>';
                                    echo '</div>';
                                }
                                ?>
                            </div>
                        </div>

                        <!-- Medical Problems Card -->
                        <div class="dashboard-card medical-problems-card">
                        <div class="card-header">
                            <h3><i class="fa fa-stethoscope"></i> Medical Problems</h3>
                            <div class="card-actions">
                                <button class="btn btn-sm btn-outline-success" onclick="addMedicalProblem()">
                                    <i class="fa fa-plus"></i> Add
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                                        <?php
                            // Get detailed medical problems data
                            $medicalProblems = sqlStatement("SELECT * FROM lists WHERE pid = ? AND type = 'medical_problem' AND activity = 1 ORDER BY date DESC", array($pid));
                            $hasProblems = false;
                            
                            echo '<div class="list-group list-group-flush">';
                            while ($problem = sqlFetchArray($medicalProblems)) {
                                $hasProblems = true;
                                echo '<div class="list-group-item p-3 border-left-4 border-primary">';
                                echo '<div class="d-flex justify-content-between align-items-start mb-2">';
                                echo '<h6 class="mb-0 text-primary"><i class="fa fa-stethoscope"></i> ' . text($problem['title']) . '</h6>';
                                echo '<span class="badge badge-' . ($problem['enddate'] ? 'success' : 'warning') . '">' . 
                                     ($problem['enddate'] ? xl('Resolved') : xl('Active')) . '</span>';
                                echo '</div>';
                                
                                echo '<div class="row">';
                                echo '<div class="col-md-6">';
                                echo '<small class="text-muted">' . xl('Diagnosis Date') . ':</small><br>';
                                echo '<strong>' . text(oeFormatShortDate($problem['date'])) . '</strong>';
                                echo '</div>';
                                
                                if ($problem['enddate']) {
                                    echo '<div class="col-md-6">';
                                    echo '<small class="text-muted">' . xl('Resolved Date') . ':</small><br>';
                                    echo '<strong>' . text(oeFormatShortDate($problem['enddate'])) . '</strong>';
                                    echo '</div>';
                                }
                                echo '</div>';
                                
                                if (!empty($problem['comments'])) {
                                    echo '<div class="mt-2">';
                                    echo '<small class="text-muted">' . xl('Notes') . ':</small><br>';
                                    echo '<span class="text-dark">' . text($problem['comments']) . '</span>';
                                    echo '</div>';
                                }
                                
                                if (!empty($problem['diagnosis'])) {
                                    echo '<div class="mt-2">';
                                    echo '<small class="text-muted">' . xl('Diagnosis Code') . ':</small><br>';
                                    echo '<span class="badge badge-info">' . text($problem['diagnosis']) . '</span>';
                                    echo '</div>';
                                }
                                
                                echo '</div>';
                            }
                            
                            if (!$hasProblems) {
                                echo '<div class="text-center text-muted py-3">';
                                echo '<i class="fa fa-stethoscope fa-2x mb-2"></i>';
                                echo '<p>' . xlt('No medical problems recorded') . '</p>';
                                echo '</div>';
                            }
                            echo '</div>';
                            ?>
                        </div>
                    </div>

                    <!-- Allergies Card -->
                    <div class="dashboard-card allergies-card">
                        <div class="card-header">
                            <h3><i class="fa fa-exclamation-triangle"></i> Allergies</h3>
                            <div class="card-actions">
                                <button class="btn btn-sm btn-outline-success" onclick="addAllergy()">
                                    <i class="fa fa-plus"></i> Add
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php
                            // Get detailed allergies data
                            $allergies = sqlStatement("SELECT * FROM lists WHERE pid = ? AND type = 'allergy' AND activity = 1 ORDER BY date DESC", array($pid));
                            $hasAllergies = false;
                            
                            echo '<div class="list-group list-group-flush">';
                            while ($allergy = sqlFetchArray($allergies)) {
                                $hasAllergies = true;
                                echo '<div class="list-group-item p-3 border-left-4 border-danger">';
                                echo '<div class="d-flex justify-content-between align-items-start mb-2">';
                                echo '<h6 class="mb-0 text-danger"><i class="fa fa-exclamation-triangle"></i> ' . text($allergy['title']) . '</h6>';
                                echo '<span class="badge badge-danger">' . xl('Allergy') . '</span>';
                                echo '</div>';
                                
                                echo '<div class="row">';
                                echo '<div class="col-md-6">';
                                echo '<small class="text-muted">' . xl('Reaction') . ':</small><br>';
                                echo '<strong>' . text($allergy['reaction'] ?? xl('Not specified')) . '</strong>';
                                echo '</div>';
                                
                                echo '<div class="col-md-6">';
                                echo '<small class="text-muted">' . xl('Severity') . ':</small><br>';
                                echo '<strong>' . text($allergy['severity'] ?? xl('Not specified')) . '</strong>';
                                echo '</div>';
                                echo '</div>';
                                
                                if (!empty($allergy['comments'])) {
                                    echo '<div class="mt-2">';
                                    echo '<small class="text-muted">' . xl('Notes') . ':</small><br>';
                                    echo '<span class="text-dark">' . text($allergy['comments']) . '</span>';
                                    echo '</div>';
                                }
                                
                                if (!empty($allergy['diagnosis'])) {
                                    echo '<div class="mt-2">';
                                    echo '<small class="text-muted">' . xl('Allergy Code') . ':</small><br>';
                                    echo '<span class="badge badge-info">' . text($allergy['diagnosis']) . '</span>';
                                    echo '</div>';
                                }
                                
                                echo '</div>';
                            }
                            
                            if (!$hasAllergies) {
                                echo '<div class="text-center text-muted py-3">';
                                echo '<i class="fa fa-exclamation-triangle fa-2x mb-2"></i>';
                                echo '<p>' . xlt('No allergies recorded') . '</p>';
                                echo '</div>';
                            }
                            echo '</div>';
                            ?>
                            </div>
                        </div>

                    <!-- Medications Card -->
                    <div class="dashboard-card medications-card">
                        <div class="card-header">
                            <h3><i class="fa fa-pills"></i> Medications</h3>
                            <div class="card-actions">
                                <button class="btn btn-sm btn-outline-success" onclick="addMedication()">
                                    <i class="fa fa-plus"></i> Add
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                                <?php
                            // Get detailed medications data
                            $medications = sqlStatement("SELECT * FROM lists WHERE pid = ? AND type = 'medication' AND activity = 1 ORDER BY date DESC", array($pid));
                            $hasMedications = false;
                            
                            echo '<div class="list-group list-group-flush">';
                            while ($medication = sqlFetchArray($medications)) {
                                $hasMedications = true;
                                echo '<div class="list-group-item p-3 border-left-4 border-success">';
                                echo '<div class="d-flex justify-content-between align-items-start mb-2">';
                                echo '<h6 class="mb-0 text-success"><i class="fa fa-pills"></i> ' . text($medication['title']) . '</h6>';
                                echo '<span class="badge badge-success">' . xl('Active') . '</span>';
                                echo '</div>';
                                
                                echo '<div class="row">';
                                echo '<div class="col-md-6">';
                                echo '<small class="text-muted">' . xl('Dosage') . ':</small><br>';
                                echo '<strong>' . text($medication['begdate'] ? oeFormatShortDate($medication['begdate']) : xl('Not specified')) . '</strong>';
                                echo '</div>';
                                
                                echo '<div class="col-md-6">';
                                echo '<small class="text-muted">' . xl('Instructions') . ':</small><br>';
                                echo '<strong>' . text($medication['diagnosis'] ?? xl('Not specified')) . '</strong>';
                                echo '</div>';
                                echo '</div>';
                                
                                if (!empty($medication['comments'])) {
                                    echo '<div class="mt-2">';
                                    echo '<small class="text-muted">' . xl('Notes') . ':</small><br>';
                                    echo '<span class="text-dark">' . text($medication['comments']) . '</span>';
                                    echo '</div>';
                                }
                                
                                if (!empty($medication['enddate'])) {
                                    echo '<div class="mt-2">';
                                    echo '<small class="text-muted">' . xl('End Date') . ':</small><br>';
                                    echo '<span class="text-warning">' . text(oeFormatShortDate($medication['enddate'])) . '</span>';
                                    echo '</div>';
                                }
                                
                                echo '</div>';
                            }
                            
                            if (!$hasMedications) {
                                echo '<div class="text-center text-muted py-3">';
                                echo '<i class="fa fa-pills fa-2x mb-2"></i>';
                                echo '<p>' . xlt('No medications recorded') . '</p>';
                                echo '</div>';
                            }
                            echo '</div>';
                            ?>
                        </div>
                    </div>

                    <!-- Immunizations Card -->
                    <div class="dashboard-card immunizations-card">
                        <div class="card-header">
                            <h3><i class="fa fa-syringe"></i> Immunizations</h3>
                            <div class="card-actions">
                                <button class="btn btn-sm btn-outline-success" onclick="addImmunization()">
                                    <i class="fa fa-plus"></i> Add
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php
                            // Get detailed immunizations data
                            $immunizations = sqlStatement("SELECT * FROM immunizations WHERE patient_id = ? ORDER BY administered_date DESC", array($pid));
                            $hasImmunizations = false;
                            
                            echo '<div class="list-group list-group-flush">';
                            while ($immunization = sqlFetchArray($immunizations)) {
                                $hasImmunizations = true;
                                echo '<div class="list-group-item p-3 border-left-4 border-info">';
                                echo '<div class="d-flex justify-content-between align-items-start mb-2">';
                                echo '<h6 class="mb-0 text-info"><i class="fa fa-syringe"></i> ' . text($immunization['immunization_name']) . '</h6>';
                                echo '<span class="badge badge-info">' . xl('Immunization') . '</span>';
                                echo '</div>';
                                
                                echo '<div class="row">';
                                echo '<div class="col-md-6">';
                                echo '<small class="text-muted">' . xl('Administered Date') . ':</small><br>';
                                echo '<strong>' . text(oeFormatShortDate($immunization['administered_date'])) . '</strong>';
                                echo '</div>';
                                
                                echo '<div class="col-md-6">';
                                echo '<small class="text-muted">' . xl('Manufacturer') . ':</small><br>';
                                echo '<strong>' . text($immunization['manufacturer'] ?? xl('Not specified')) . '</strong>';
                                echo '</div>';
                                echo '</div>';
                                
                                if (!empty($immunization['lot_number'])) {
                                    echo '<div class="row mt-2">';
                                    echo '<div class="col-md-6">';
                                    echo '<small class="text-muted">' . xl('Lot Number') . ':</small><br>';
                                    echo '<strong>' . text($immunization['lot_number']) . '</strong>';
                                    echo '</div>';
                                    
                                    if (!empty($immunization['expiration_date'])) {
                                        echo '<div class="col-md-6">';
                                        echo '<small class="text-muted">' . xl('Expiration Date') . ':</small><br>';
                                        echo '<strong>' . text(oeFormatShortDate($immunization['expiration_date'])) . '</strong>';
                                        echo '</div>';
                                    }
                                    echo '</div>';
                                }
                                
                                if (!empty($immunization['note'])) {
                                    echo '<div class="mt-2">';
                                    echo '<small class="text-muted">' . xl('Notes') . ':</small><br>';
                                    echo '<span class="text-dark">' . text($immunization['note']) . '</span>';
                                    echo '</div>';
                                }
                                
                                echo '</div>';
                            }
                            
                            if (!$hasImmunizations) {
                                echo '<div class="text-center text-muted py-3">';
                                echo '<i class="fa fa-syringe fa-2x mb-2"></i>';
                                echo '<p>' . xlt('No immunizations recorded') . '</p>';
                                echo '</div>';
                            }
                            echo '</div>';
                                ?>
                            </div>
                        </div>

                    <!-- Encounters Card -->
                    <div class="dashboard-card encounters-card">
                        <div class="card-header">
                            <h3><i class="fa fa-calendar-check"></i> Recent Encounters</h3>
                            <div class="card-actions">
                                <button class="btn btn-sm btn-outline-success" onclick="addEncounter()">
                                    <i class="fa fa-plus"></i> Add
                                </button>
                                <a href="../history/encounters.php" class="btn btn-sm btn-outline-primary">
                                    <i class="fa fa-list"></i> View All
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                                <?php
                            // Get recent encounters
                            $encounters = sqlStatement("SELECT encounter, date, pc_catname FROM form_encounter fe 
                                LEFT JOIN openemr_postcalendar_categories pc ON fe.pc_catid = pc.pc_catid 
                                WHERE fe.pid = ? ORDER BY date DESC LIMIT 5", array($pid));
                            ?>
                            <div class="encounters-list">
                                <?php while ($encounter = sqlFetchArray($encounters)) : ?>
                                    <div class="encounter-item">
                                        <div class="encounter-date"><?php echo text(oeFormatShortDate($encounter['date'])); ?></div>
                                        <div class="encounter-type"><?php echo text($encounter['pc_catname'] ?? 'Visit'); ?></div>
                                        <div class="encounter-actions">
                                            <a href="../encounter/encounter_top.php?set_encounter=<?php echo attr_url($encounter['encounter']); ?>" 
                                               class="btn btn-sm btn-outline-primary">
                                                <i class="fa fa-eye"></i> View
                                            </a>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        </div>
                    </div>

                </div> <!-- End Left Column -->

                <!-- Right Column - Secondary Data -->
                <div class="dashboard-sidebar">
                    
                    <!-- Insurance & Billing Card -->
                    <?php if ($show_insurance) : ?>
                        <div class="dashboard-card insurance-card">
                            <div class="card-header">
                                <h3><i class="fa fa-shield-alt"></i> Insurance & Billing</h3>
                            </div>
                            <div class="card-body">
                                <?php
                        $forceBillingExpandAlways = ($GLOBALS['force_billing_widget_open']) ? true : false;
                        $patientbalance = get_patient_balance($pid, false);
                        $insurancebalance = get_patient_balance($pid, true) - $patientbalance;
                        $totalbalance = $patientbalance + $insurancebalance;
                        $id = "billing_ps_expand";
                        $dispatchResult = $ed->dispatch(CardRenderEvent::EVENT_HANDLE, new CardRenderEvent('billing'));
                        $viewArgs = [
                            'title' => xl('Billing'),
                            'id' => $id,
                                    'initiallyCollapsed' => false,
                            'hideBtn' => true,
                            'patientBalance' => $patientbalance,
                            'insuranceBalance' => $insurancebalance,
                            'totalBalance' => $totalbalance,
                            'forceAlwaysOpen' => $forceBillingExpandAlways,
                            'prependedInjection' => $dispatchResult->getPrependedInjection(),
                            'appendedInjection' => $dispatchResult->getAppendedInjection(),
                        ];

                        if (!empty($result['billing_note'])) {
                            $viewArgs['billingNote'] = $result['billing_note'];
                        }

                        if (!empty($result3['provider'])) {
                            $viewArgs['provider'] = true;
                            $viewArgs['insName'] = $insco_name;
                            $viewArgs['copay'] = $result3['copay'];
                            $viewArgs['effDate'] = $result3['effdate'];
                        }

                        echo $twig->getTwig()->render('patient/card/billing.html.twig', $viewArgs);
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Documents & Photos Card -->
                    <?php 
                    // Get patient photos
                                        $photos = pic_array($pid, $GLOBALS['patient_photo_category_name']);
                    if ($photos || $idcard_doc_id) : 
                    ?>
                        <div class="dashboard-card documents-card">
                            <div class="card-header">
                                <h3><i class="fa fa-file-image"></i> Documents & Photos</h3>
                            </div>
                            <div class="card-body">
                                <?php
                                            $id = "photos_ps_expand";
                                            $dispatchResult = $ed->dispatch(CardRenderEvent::EVENT_HANDLE, new CardRenderEvent('patient_photo'));
                        $viewArgs = [
                                                'title' => xl("ID Card / Photos"),
                            'id' => $id,
                                    'initiallyCollapsed' => false,
                                                'btnLabel' => 'Edit',
                                                'linkMethod' => "javascript",
                            'bodyClass' => 'collapse show',
                                                'auth' => false,
                                                'patientIDCategoryID' => $GLOBALS['patient_id_category_name'],
                                                'patientPhotoCategoryName' => $GLOBALS['patient_photo_category_name'],
                                                'photos' => $photos,
                                                'idCardDocID' => $idcard_doc_id,
                            'prependedInjection' => $dispatchResult->getPrependedInjection(),
                            'appendedInjection' => $dispatchResult->getAppendedInjection(),
                        ];
                                            echo $twig->getTwig()->render('patient/card/photo.html.twig', $viewArgs);
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>

                        </div> <!-- End Right Column -->
                    </div> <!-- End Hidden Old Dashboard Sections -->
                </div> <!-- End Dashboard Grid -->
        </div> <!-- End Patient Dashboard Container -->
        <?php $oemr_ui->oeBelowContainerDiv(); ?>
        <script>
            // Array of skip conditions for the checkSkipConditions() function.
            var skipArray = [
                <?php echo ($condition_str ?? ''); ?>
            ];

            // Dashboard JavaScript Functions
            function addMedicalProblem() {
                dlgopen('add_edit_issue.php?pid=<?php echo attr_url($pid); ?>', '_blank', 800, 600, '', '<?php echo xlj("Add Medical Problem"); ?>');
            }

            function addAllergy() {
                // Use the existing issue form for allergies
                dlgopen('add_edit_issue.php?pid=<?php echo attr_url($pid); ?>&thistype=allergy', '_blank', 800, 600, '', '<?php echo xlj("Add Allergy"); ?>');
            }

            function addMedicationOnly() {
                // Use the existing issue form for medications (no billing)
                dlgopen('add_edit_issue.php?pid=<?php echo attr_url($pid); ?>&thistype=medication', '_blank', 800, 600, '', '<?php echo xlj("Add Medication"); ?>');
            }

            function addMedicationWithBilling() {
                // Use the new integrated billing form for medications
                dlgopen('add_medication_with_billing.php?pid=<?php echo attr_url($pid); ?>', '_blank', 800, 700, '', '<?php echo xlj("Add Medication with Billing"); ?>');
            }

            function addAdministeredOnly() {
                // Navigate to the immunizations page (no billing)
                top.restoreSession();
                location.href = 'immunizations.php?pid=<?php echo attr_url($pid); ?>';
            }

            function addAdministeredWithBilling() {
                // Use the new integrated billing form for administered items
                dlgopen('add_administered_with_billing.php?pid=<?php echo attr_url($pid); ?>', '_blank', 800, 700, '', '<?php echo xlj("Add Administered Item with Billing"); ?>');
            }

            function addAppointment() {
                dlgopen('../../main/calendar/add_edit_event.php?patientid=<?php echo attr_url($pid); ?>', '_blank', 800, 600, '', '<?php echo xlj("Add Appointment"); ?>');
            }

            function addPrescriptionOnly() {
                // Use the existing issue form for prescriptions (no billing)
                dlgopen('add_edit_issue.php?pid=<?php echo attr_url($pid); ?>&thistype=medication', '_blank', 800, 600, '', '<?php echo xlj("Add Prescription"); ?>');
            }

            function addPrescriptionWithBilling() {
                // Use the new integrated billing form for prescriptions
                dlgopen('add_prescription_with_billing.php?pid=<?php echo attr_url($pid); ?>', '_blank', 800, 700, '', '<?php echo xlj("Add Prescription with Billing"); ?>');
            }

            function addAdvancedDirective() {
                // Open advanced directives form in modal
                dlgopen('advancedirectives.php?pid=<?php echo attr_url($pid); ?>', '_blank', 500, 600, '', '<?php echo xlj("Advanced Directives"); ?>');
            }

            function addEncounter() {
                top.restoreSession();
                dlgopen('../../forms/newpatient/new.php?pid=<?php echo attr_url($pid); ?>', '_blank', 1200, 800, '', '<?php echo xlj("Add Encounter"); ?>');
            }

            // Payment and Checkout Functions
            function openPaymentWindow() {
                top.restoreSession();
                dlgopen('../front_payment.php?pid=<?php echo attr_url($pid); ?>', '_blank', 1200, 800, '', '<?php echo xlj("Record Payment"); ?>');
            }

            function openCheckoutWindow() {
                top.restoreSession();
                dlgopen('../pos_checkout_normal.php?pid=<?php echo attr_url($pid); ?>', '_blank', 1200, 800, '', '<?php echo xlj("POS Checkout"); ?>');
            }

            function openStripePayment() {
                top.restoreSession();
                dlgopen('../front_payment_stripe.php?pid=<?php echo attr_url($pid); ?>', '_blank', 1200, 800, '', '<?php echo xlj("Pay Now"); ?>');
            }

            function refreshBillingSection() {
                // Refresh the billing section to show updated balance
                location.reload();
            }

            // Listen for window close events from billing interface
            window.addEventListener('message', function(event) {
                if (event.data === 'billing_updated') {
                    refreshBillingSection();
                }
            });

            // Initialize healthcare dashboard
            $(document).ready(function() {
                // Add fade-in animation to cards
                $('.dashboard-card').addClass('fade-in');
                
                // Card hover effects
                $('.dashboard-card').hover(
                    function() { $(this).addClass('card-hover'); },
                    function() { $(this).removeClass('card-hover'); }
                );

                // Responsive adjustments
                function adjustLayout() {
                    if ($(window).width() < 768) {
                        $('.dashboard-content').addClass('mobile-layout');
                            } else {
                        $('.dashboard-content').removeClass('mobile-layout');
                    }
                }

                adjustLayout();
                $(window).resize(adjustLayout);

                // Add smooth scrolling for better UX
                $('html').css('scroll-behavior', 'smooth');
            });
        </script>
</body>
</html>

