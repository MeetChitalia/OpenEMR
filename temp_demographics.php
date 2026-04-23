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
    $sql_query = "select documents.id from documents join categories_to_documents " .
        "on documents.id = categories_to_documents.document_id " .
        "join categories on categories.id = categories_to_documents.category_id " .
        "where categories.name like ? and documents.foreign_id = ? and documents.deleted = 0";
    if ($query = sqlStatement($sql_query, array($picture_directory, $pid))) {
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
        $result = sqlQuery("SELECT d.id, d.date, d.url
            FROM documents AS d, categories_to_documents AS cd, categories AS c
            WHERE d.foreign_id = ?
            AND cd.document_id = d.id
            AND c.id = cd.category_id
            AND c.name LIKE ?
            ORDER BY d.date DESC LIMIT 1", array($pid, $doc_catg));
    }

    return ($result['id'] ?? false);
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
    if ($portalStatus['allow_patient_portal'] == 'YES') {
        $return = true;
    }
    return $return;
}

function isApiAllowed($pid): bool
{
    $return = false;

    $apiStatus = sqlQuery("SELECT prevent_portal_apps FROM patient_data WHERE pid = ?", [$pid]);
    if (strtoupper($apiStatus['prevent_portal_apps'] ?? '') != 'YES') {
        $return = true;
    }
    return $return;
}

function areCredentialsCreated($pid): bool
{
    $return = false;
    $credentialsCreated = sqlQuery("SELECT date_created FROM `patient_access_onsite` WHERE `pid`=?", [$pid]);
    if ($credentialsCreated['date_created'] ?? null) {
        $return = true;
    }

    return $return;
}

function isContactEmail($pid): bool
{
    $return = false;

    $email = sqlQuery("SELECT email, email_direct FROM patient_data WHERE pid = ?", [$pid]);
    if (!empty($email['email']) || !empty($email['email_direct'])) {
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
$vitals_is_registered = $tmp['count'];

// Get patient/employer/insurance information.
//
$result = getPatientData($pid, "*, DATE_FORMAT(DOB,'%Y-%m-%d') as DOB_YMD");
$result2 = getEmployerData($pid);
$result3 = getInsuranceData($pid, "primary", "copay, provider, DATE_FORMAT(`date`,'%Y-%m-%d') as effdate");
$insco_name = "";
if (!empty($result3['provider'])) {   // Use provider in case there is an ins record w/ unassigned insco
    $insco_name = getInsuranceProvider($result3['provider']);
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
                parent.left_nav.setPatient(<?php echo js_escape($result['fname'] . " " . $result['lname']) .
                                                "," . js_escape($pid) . "," . js_escape($result['pubpid']) . ",'',";
                if (empty($date_of_death)) {
                    echo js_escape(" " . xl('DOB') . ": " . oeFormatShortDate($result['DOB_YMD']) . " " . xl('Age') . ": " . getPatientAgeDisplay($result['DOB_YMD']));
                } else {
                    echo js_escape(" " . xl('DOB') . ": " . oeFormatShortDate($result['DOB_YMD']) . " " . xl('Age at death') . ": " . oeFormatAge($result['DOB_YMD'], $date_of_death));
                } ?>);
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
                $query_result = sqlQuery("SELECT `date` FROM `form_encounter` WHERE `encounter` = ?", array($encounter)); ?>
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

        // Array of skip conditions for the checkSkipConditions() function.
        var skipArray = [
            <?php echo ($condition_str ?? ''); ?>
        ];
        checkSkipConditions();

        var isPost = <?php echo js_escape($showEligibility ?? false); ?>;
        var listId = '#' + <?php echo js_escape($list_id); ?>;
        $(function() {
            $(listId).addClass("active");
            if (isPost === true) {
                $("#eligibility").click();
                $("#eligibility").get(0).scrollIntoView();
            }
        });

        // Modern Patient Forms Navigation JavaScript
        $(document).ready(function() {
            const forms = ['demographics', 'insurance', 'messages', 'vitals'];
            let currentFormIndex = 0;

            // Initialize the form navigation
            function initFormNavigation() {
                // Sidebar navigation
                $('.nav-item').on('click', function(e) {
                    e.preventDefault();
                    const formType = $(this).data('form');
                    showForm(formType);
                });

                // Next/Back button navigation
                $('#nextBtn').on('click', function() {
                    if (currentFormIndex < forms.length - 1) {
                        currentFormIndex++;
                        showForm(forms[currentFormIndex]);
                    }
                });

                $('#prevBtn').on('click', function() {
                    if (currentFormIndex > 0) {
                        currentFormIndex--;
                        showForm(forms[currentFormIndex]);
                    }
                });

                // Keyboard navigation
                $(document).on('keydown', function(e) {
                    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                        e.preventDefault();
                        $('#nextBtn').click();
                    } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                        e.preventDefault();
                        $('#prevBtn').click();
                    }
                });
            }

            // Show the specified form
            function showForm(formType) {
                // Hide all form sections
                $('.form-section').removeClass('active').hide();
                
                // Show the selected form section
                $(`#${formType}-section`).addClass('active').show();
                
                // Update sidebar navigation
                $('.nav-item').removeClass('active');
                $(`.nav-item[data-form="${formType}"]`).addClass('active');
                
                // Update current form index
                currentFormIndex = forms.indexOf(formType);
                
                // Update navigation buttons
                updateNavigationButtons();
                
                // Trigger any necessary form-specific initialization
                initializeFormContent(formType);
            }

            // Update navigation button states
            function updateNavigationButtons() {
                const prevBtn = $('#prevBtn');
                const nextBtn = $('#nextBtn');
                
                // Show/hide back button
                if (currentFormIndex === 0) {
                    prevBtn.hide();
                } else {
                    prevBtn.show();
                }
                
                // Update next button text
                if (currentFormIndex === forms.length - 1) {
                    nextBtn.html('<?php echo xlt("Finish"); ?> <i class="fa fa-check"></i>');
                } else {
                    nextBtn.html('<?php echo xlt("Next"); ?> <i class="fa fa-chevron-right"></i>');
                }
            }

            // Initialize form-specific content
            function initializeFormContent(formType) {
                switch(formType) {
                    case 'demographics':
                        // Ensure demographics form is properly initialized
                        if (typeof tabbify === 'function') {
                            tabbify();
                        }
                        break;
                    case 'insurance':
                        // Initialize insurance form if needed
                        break;
                    case 'messages':
                        // Initialize messages form if needed
                        break;
                    case 'vitals':
                        // Initialize vitals form if needed
                        break;
                }
            }

            // Initialize the navigation
            initFormNavigation();
            
            // Show demographics by default (already active)
            updateNavigationButtons();
        });
    </script>

    <style>
        /* Bad practice to override here, will get moved to base style theme */
        .card {
            box-shadow: 1px 1px 1px hsl(0 0% 0% / .2);
            border-radius: 0;
        }

        <?php
        if (!empty($GLOBALS['right_justify_labels_demographics']) && ($_SESSION['language_direction'] == 'ltr')) { ?>
        div.tab td.label_custom, div.label_custom {
            text-align: right !important;
        }

        div.tab td.data, div.data {
            padding-left: 0.5em;
            padding-right: 2em;
        }
            <?php
        } ?>

        <?php
        // This is for layout font size override.
        $grparr = array();
        getLayoutProperties('DEM', $grparr, 'grp_size');
        if (!empty($grparr['']['grp_size'])) {
            $FONTSIZE = round($grparr['']['grp_size'] * 1.333333);
            $FONTSIZE = round($FONTSIZE * 0.0625, 2);
            ?>

        /* Override font sizes in the theme. */
        #DEM .groupname {
            font-size: <?php echo attr($FONTSIZE); ?>rem;
        }

        #DEM .label {
            font-size: <?php echo attr($FONTSIZE); ?>rem;
        }

        #DEM .data {
            font-size: <?php echo attr($FONTSIZE); ?>rem;
        }

        #DEM .data td {
            font-size: <?php echo attr($FONTSIZE); ?>rem;
        }

        <?php } ?> :root {
            --white: #fff;
            --bg: hsl(0 0% 90%);
        }

        body {
            background: var(--bg) !important;
        }

        section {
            background: var(--white);
            margin-top: .25em;
            padding: .25em;
        }

        .section-header-dynamic {
            border-bottom: none;
        }
        
        /* Modern Demographics Styling - Direct CSS */
        .patient-demographic {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }

        /* Modern card container */
        #DEM {
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin: 20px;
            padding: 30px;
            border: none;
            animation: fadeInUp 0.6s ease-out;
        }

        /* Modern group headers */
        #DEM .groupname {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 25px;
            border-radius: 12px 12px 0 0;
            font-size: 1.1rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 0;
            box-shadow: 0 4px 8px rgba(102, 126, 234, 0.3);
        }

        /* Modern form layout */
        #DEM table {
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
            background: white;
            border-radius: 0 0 12px 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        /* Modern label styling */
        #DEM .label_custom {
            background: #f8f9fa;
            color: #495057;
            font-weight: 600;
            padding: 15px 20px;
            border-left: 4px solid #007bff;
            border-radius: 0;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        #DEM .label_custom:hover {
            background: #e9ecef;
            border-left-color: #0056b3;
        }

        /* Modern data field styling */
        #DEM .data {
            background: white;
            padding: 15px 20px;
            border: 1px solid #e9ecef;
            border-radius: 0;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        #DEM .data:hover {
            border-color: #007bff;
            box-shadow: 0 2px 8px rgba(0, 123, 255, 0.15);
        }

        /* Modern form controls */
        #DEM .form-control {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 10px 15px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            min-width: 200px;
        }

        #DEM .form-control:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
            outline: none;
        }

        /* Modern buttons */
        #DEM .btn {
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
        }

        #DEM .btn-primary {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
        }

        #DEM .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 123, 255, 0.4);
        }

        /* Modern table rows */
        #DEM tr {
            transition: background-color 0.3s ease;
        }

        #DEM tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        #DEM tr:hover {
            background-color: #e3f2fd;
        }

        /* Animation for page load */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive design */
        @media (max-width: 768px) {
            #DEM {
                margin: 10px;
                padding: 20px;
            }
            
            #DEM .form-control {
                width: 100%;
                min-width: auto;
            }
            
            #DEM .label_custom,
            #DEM .data {
                padding: 12px 15px;
            }
            
            #DEM .groupname {
                padding: 15px 20px;
                font-size: 1rem;
            }
        }

        /* Modern Patient Forms Layout Styles */
        .patient-forms-container {
            display: flex;
            min-height: calc(100vh - 200px);
            background: #f8f9fa;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .patient-forms-sidebar {
            width: 280px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0;
            position: relative;
            flex-shrink: 0;
        }

        .sidebar-header {
            padding: 25px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(0, 0, 0, 0.1);
        }

        .sidebar-header h4 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .sidebar-nav {
            padding: 20px 0;
        }

        .nav-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .nav-item {
            margin: 0;
            padding: 0;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 15px 25px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            text-decoration: none;
            border-left-color: rgba(255, 255, 255, 0.3);
        }

        .nav-item.active .nav-link {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-left-color: #fff;
            box-shadow: inset 0 0 20px rgba(255, 255, 255, 0.1);
        }

        .nav-link i {
            width: 20px;
            margin-right: 12px;
            font-size: 1.1rem;
        }

        .nav-link span {
            font-weight: 500;
            font-size: 0.95rem;
        }

        .patient-forms-content {
            flex: 1;
            background: white;
            display: flex;
            flex-direction: column;
        }

        .form-navigation {
            padding: 20px 30px;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .form-navigation .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .form-navigation .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .form-sections {
            flex: 1;
            overflow-y: auto;
        }

        .form-section {
            display: none;
            padding: 30px;
            animation: fadeIn 0.3s ease-in-out;
        }

        .form-section.active {
            display: block;
        }

        .section-header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }

        .section-header h3 {
            color: #2c3e50;
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .section-header p {
            color: #6c757d;
            font-size: 1rem;
            margin: 0;
        }

        .section-content {
            background: white;
            border-radius: 8px;
        }

        /* Animation for form transitions */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive design for the new layout */
        @media (max-width: 992px) {
            .patient-forms-container {
                flex-direction: column;
            }

            .patient-forms-sidebar {
                width: 100%;
                max-height: 200px;
                overflow-y: auto;
            }

            .nav-list {
                display: flex;
                overflow-x: auto;
                padding: 10px 20px;
            }

            .nav-item {
                flex-shrink: 0;
                margin-right: 10px;
            }

            .nav-link {
                padding: 12px 20px;
                border-radius: 8px;
                border-left: none;
                border-bottom: 3px solid transparent;
            }

            .nav-item.active .nav-link {
                border-bottom-color: #fff;
            }

            .form-navigation {
                padding: 15px 20px;
            }

            .form-section {
                padding: 20px;
            }
        }

        @media (max-width: 576px) {
            .patient-forms-sidebar {
                max-height: 150px;
            }

            .nav-link {
                padding: 10px 15px;
                font-size: 0.9rem;
            }

            .nav-link i {
                margin-right: 8px;
                font-size: 1rem;
            }

            .form-section {
                padding: 15px;
            }

            .section-header h3 {
                font-size: 1.5rem;
            }
        }
    </style>
    
    <!-- Modern Demographics Styling -->
    <link rel="stylesheet" href="<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/demographics_modern.css?v=<?php echo time(); ?>">
    
    <!-- Modern Patient Demographics Styling -->
    <link rel="stylesheet" href="<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/patient_modern.css?v=<?php echo time(); ?>">

    <title><?php echo xlt("Dashboard{{patient file}}"); ?></title>
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

    <div id="container_div" class="<?php echo $oemr_ui->oeContainer(); ?> mb-2">
        <a href='../reminder/active_reminder_popup.php' id='reminder_popup_link' style='display: none' onclick='top.restoreSession()'></a>
        <a href='../birthday_alert/birthday_pop.php?pid=<?php echo attr_url($pid); ?>&user_id=<?php echo attr_url($_SESSION['authUserID']); ?>' id='birthday_popup' style='display: none;' onclick='top.restoreSession()'></a>
        <?php

        if ($thisauth) {
            if ($result['squad'] && !AclMain::aclCheckCore('squads', $result['squad'])) {
                $thisauth = 0;
            }
        }

        if ($thisauth) :
            require_once("$include_root/patient_file/summary/dashboard_header.php");
        endif;

        $list_id = "dashboard"; // to indicate nav item is active, count and give correct id
        // Collect the patient menu then build it
        $menuPatient = new PatientMenuRole($twig);
        $menuPatient->displayHorizNavBarMenu();
        // Get the document ID of the patient ID card if access to it is wanted here.
        $idcard_doc_id = false;
        if ($GLOBALS['patient_id_category_name']) {
            $idcard_doc_id = get_document_by_catg($pid, $GLOBALS['patient_id_category_name']);
        }
        ?>
        <div class="main mb-5">
            <!-- Modern Patient Forms Layout -->
            <div class="patient-forms-container">
                <!-- Left Sidebar -->
                <div class="patient-forms-sidebar">
                    <div class="sidebar-header">
                        <h4><?php echo xlt('Patient Forms'); ?></h4>
                    </div>
                    <nav class="sidebar-nav">
                        <ul class="nav-list">
                            <li class="nav-item active" data-form="demographics">
                                <a href="#" class="nav-link">
                                    <i class="fa fa-user"></i>
                                    <span><?php echo xlt('Demographics'); ?></span>
                                </a>
                            </li>
                            <li class="nav-item" data-form="insurance">
                                <a href="#" class="nav-link">
                                    <i class="fa fa-shield"></i>
                                    <span><?php echo xlt('Insurance'); ?></span>
                                </a>
                            </li>
                            <li class="nav-item" data-form="messages">
                                <a href="#" class="nav-link">
                                    <i class="fa fa-comments"></i>
                                    <span><?php echo xlt('Messages'); ?></span>
                                </a>
                            </li>
                            <li class="nav-item" data-form="vitals">
                                <a href="#" class="nav-link">
                                    <i class="fa fa-heartbeat"></i>
                                    <span><?php echo xlt('Vitals'); ?></span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>

                <!-- Right Content Area -->
                <div class="patient-forms-content">
                    <!-- Form Navigation -->
                    <div class="form-navigation">
                        <button type="button" class="btn btn-secondary" id="prevBtn" style="display: none;">
                            <i class="fa fa-chevron-left"></i> <?php echo xlt('Back'); ?>
                        </button>
                        <button type="button" class="btn btn-primary" id="nextBtn">
                            <?php echo xlt('Next'); ?> <i class="fa fa-chevron-right"></i>
                        </button>
                    </div>

                    <!-- Form Sections -->
                    <div class="form-sections">
                        <!-- Demographics Section -->
                        <div class="form-section active" id="demographics-section">
                            <div class="section-header">
                                <h3><?php echo xlt('Patient Demographics'); ?></h3>
                                <p><?php echo xlt('Basic patient information and contact details'); ?></p>
                            </div>
                            <div class="section-content">
            <!-- start main content div -->
            <div class="row">
                <div class="col-md-8">
                    <?php

                    if ($deceased > 0) :
                        echo $twig->getTwig()->render('patient/partials/deceased.html.twig', [
                            'deceasedDays' => deceasedDays($deceased),
                        ]);
                    endif;

                    $sectionRenderEvents = $ed->dispatch(SectionEvent::EVENT_HANDLE, new SectionEvent('primary'));
                    $sectionCards = $sectionRenderEvents->getCards();

                    $t = $twig->getTwig();

                    foreach ($sectionCards as $card) {
                        $_auth = $card->getAcl();
                        if (!AclMain::aclCheckCore($_auth[0], $_auth[1])) {
                            continue;
                        }

                        $btnLabel = false;
                        if ($card->canAdd()) {
                            $btnLabel = 'Add';
                        } elseif ($card->canEdit()) {
                            $btnLabel = 'Edit';
                        }

                        $viewArgs = [
                            'title' => $card->getTitle(),
                            'id' => $card->getIdentifier(),
                            'initiallyCollapsed' => !$card->isInitiallyCollapsed(),
                            'card_bg_color' => $card->getBackgroundColorClass(),
                            'card_text_color' => $card->getTextColorClass(),
                            'forceAlwaysOpen' => !$card->canCollapse(),
                            'btnLabel' => $btnLabel,
                            'btnLink' => 'test',
                        ];

                        echo $t->render($card->getTemplateFile(), array_merge($card->getTemplateVariables(), $viewArgs));
                    }

                    if (!$GLOBALS['hide_billing_widget']) :
                        $forceBillingExpandAlways = ($GLOBALS['force_billing_widget_open']) ? true : false;
                        $patientbalance = get_patient_balance($pid, false);
                        $insurancebalance = get_patient_balance($pid, true) - $patientbalance;
                        $totalbalance = $patientbalance + $insurancebalance;
                        $id = "billing_ps_expand";
                        $dispatchResult = $ed->dispatch(CardRenderEvent::EVENT_HANDLE, new CardRenderEvent('billing'));
                        $viewArgs = [
                            'title' => xl('Billing'),
                            'id' => $id,
                            'initiallyCollapsed' => (getUserSetting($id) == 0) ? false : true,
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
                    endif; // End the hide_billing_widget

                    // if anyone wants to render anything before the patient demographic list
                    $GLOBALS["kernel"]->getEventDispatcher()->dispatch(RenderEvent::EVENT_SECTION_LIST_RENDER_BEFORE, new RenderEvent($pid), 10);

                    if (AclMain::aclCheckCore('patients', 'demo')) :
                        $dispatchResult = $ed->dispatch(CardRenderEvent::EVENT_HANDLE, new CardRenderEvent('demographic'));
                        // Render the Demographics box
                        $viewArgs = [
                            'title' => xl("Demographics"),
                            'id' => "demographics_ps_expand",
                            'btnLabel' => "Edit",
                            'btnLink' => "demographics_full.php",
                            'linkMethod' => "html",
                            'auth' => ACLMain::aclCheckCore('patients', 'demo', '', 'write'),
                            'requireRestore' => (!isset($_SESSION['patient_portal_onsite_two'])) ? true : false,
                            'initiallyCollapsed' => getUserSetting("demographics_ps_expand") == true ? true : false,
                            'tabID' => "DEM",
                            'result' => $result,
                            'result2' => $result2,
                            'prependedInjection' => $dispatchResult->getPrependedInjection(),
                            'appendedInjection' => $dispatchResult->getAppendedInjection(),
                        ];
                        echo $twig->getTwig()->render('patient/card/tab_base.html.twig', $viewArgs);
                                        endif;  // end if demographics authorized
                                        ?>
                                    </div> <!-- end left column div -->
                                    <div class="col-md-4">
                                        <!-- start right column div -->
                                        <?php
                                        // it's important enough to always show it
                                        $portalCard = new PortalCard($GLOBALS);

                                        $sectionRenderEvents = $ed->dispatch(SectionEvent::EVENT_HANDLE, new SectionEvent('secondary'));
                                        $sectionCards = $sectionRenderEvents->getCards();

                                        $t = $twig->getTwig();

                                        foreach ($sectionCards as $card) {
                                            $_auth = $card->getAcl();
                                            $auth = AclMain::aclCheckCore($_auth[0], $_auth[1]);
                                            if (!$auth) {
                                                continue;
                                            }

                                            $btnLabel = false;
                                            if ($card->canAdd()) {
                                                $btnLabel = 'Add';
                                            } elseif ($card->canEdit()) {
                                                $btnLabel = 'Edit';
                                            }

                                            $viewArgs = [
                                                'card' => $card,
                                                'title' => $card->getTitle(),
                                                'id' => $card->getIdentifier() . "_expand",
                                                'auth' => $auth,
                                                'linkMethod' => 'html',
                                                'initiallyCollapsed' => !$card->isInitiallyCollapsed(),
                                                'card_bg_color' => $card->getBackgroundColorClass(),
                                                'card_text_color' => $card->getTextColorClass(),
                                                'forceAlwaysOpen' => !$card->canCollapse(),
                                                'btnLabel' => $btnLabel,
                                                'btnLink' => "javascript:$('#patient_portal').collapse('toggle')",
                                            ];

                                            echo $t->render($card->getTemplateFile(), array_merge($card->getTemplateVariables(), $viewArgs));
                                        }

                                        if ($GLOBALS['erx_enable']) :
                                            $dispatchResult = $ed->dispatch(CardRenderEvent::EVENT_HANDLE, new CardRenderEvent('demographics'));
                                            echo $twig->getTwig()->render('patient/partials/erx.html.twig', [
                                                'prependedInjection' => $dispatchResult->getPrependedInjection(),
                                                'appendedInjection' => $dispatchResult->getAppendedInjection(),
                                            ]);
                                        endif;

                                        // If there is an ID Card or any Photos show the widget
                                        $photos = pic_array($pid, $GLOBALS['patient_photo_category_name']);
                                        if ($photos or $idcard_doc_id) {
                                            $id = "photos_ps_expand";
                                            $dispatchResult = $ed->dispatch(CardRenderEvent::EVENT_HANDLE, new CardRenderEvent('patient_photo'));
                                            $viewArgs = [
                                                'title' => xl("ID Card / Photos"),
                                                'id' => $id,
                                                'initiallyCollapsed' => (getUserSetting($id) == 0) ? false : true,
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
                                        }
                                        ?>
                                    </div> <!-- end right column div -->
                                </div> <!-- end div.main > row:first  -->
                            </div>
                        </div>

                        <!-- Insurance Section -->
                        <div class="form-section" id="insurance-section">
                            <div class="section-header">
                                <h3><?php echo xlt('Insurance Information'); ?></h3>
                                <p><?php echo xlt('Patient insurance details and coverage information'); ?></p>
                            </div>
                            <div class="section-content">
                                <?php
                                if (AclMain::aclCheckCore('patients', 'demo')) :
                        // Insurance
                        $insArr = [];
                        $insInBinder = '?';
                        for ($y = 1; count($insurance_array) > $y; $y++) {
                            $insInBinder .= ',?';
                        }
                        $sql = "SELECT * FROM insurance_data WHERE pid = ? AND type IN(" . $insInBinder . ") ORDER BY type, date DESC";
                        $params[] = $pid;
                        $params = array_merge($params, $insurance_array);
                        $res = sqlStatement($sql, $params);
                        $prior_ins_type = '';

                        while ($row = sqlFetchArray($res)) {
                            if ($row['provider']) {
                                // since the query is sorted by DATE DESC can use prior ins type to identify
                                $row['isOld'] = (strcmp($row['type'], $prior_ins_type) == 0) ? true : false;
                                $icobj = new InsuranceCompany($row['provider']);
                                $adobj = $icobj->get_address();
                                $insco_name = trim($icobj->get_name());
                                $row['insco'] = [
                                    'name' => trim($icobj->get_name()),
                                    'address' => [
                                        'line1' => $adobj->get_line1(),
                                        'line2' => $adobj->get_line2(),
                                        'city' => $adobj->get_city(),
                                        'state' => $adobj->get_state(),
                                        'postal' => $adobj->get_zip(),
                                        'country' => $adobj->get_country()
                                    ],
                                ];
                                $row['policy_type'] = (!empty($row['policy_type'])) ? $policy_types[$row['policy_type']] : false;
                                $row['dispFromDate'] = $row['date'] ? true : false;
                                $mname = ($row['subscriber_mname'] != "") ? $row['subscriber_mname'] : "";
                                $row['subscriber_full_name'] = str_replace("%mname%", $mname, "{$row['subscriber_fname']} %mname% {$row['subscriber_lname']}");
                                $insArr[] = $row;
                                $prior_ins_type = $row['type'];
                            }
                        }

                        if ($GLOBALS["enable_oa"]) {
                            if (($_POST['status_update'] ?? '') === 'true') {
                                unset($_POST['status_update']);
                                $showEligibility = true;
                                $ok = EDI270::requestEligibleTransaction($pid);
                                if ($ok === true) {
                                    ob_start();
                                    EDI270::showEligibilityInformation($pid, false);
                                    $output = ob_get_contents();
                                    ob_end_clean();
                                } else {
                                    $output = $ok;
                                }
                            } else {
                                ob_start();
                                EDI270::showEligibilityInformation($pid, true);
                                $output = ob_get_contents();
                                ob_end_clean();
                            }
                        } else {
                            ob_start();
                            EDI270::showEligibilityInformation($pid, true);
                            $output = ob_get_contents();
                            ob_end_clean();
                        }

                        $id = "insurance_ps_expand";
                        $dispatchResult = $ed->dispatch(CardRenderEvent::EVENT_HANDLE, new CardRenderEvent('insurance'));
                        $viewArgs = [
                            'title' => xl("Insurance"),
                            'id' => $id,
                            'btnLabel' => "Edit",
                            'btnLink' => "demographics_full.php",
                            'linkMethod' => 'html',
                            'initiallyCollapsed' => (getUserSetting($id) == 0) ? false : true,
                            'ins' => $insArr,
                            'eligibility' => $output,
                            'enable_oa' => $GLOBALS['enable_oa'],
                            'auth' => AclMain::aclCheckCore('patients', 'demo', '', 'write'),
                            'prependedInjection' => $dispatchResult->getPrependedInjection(),
                            'appendedInjection' => $dispatchResult->getAppendedInjection(),
                        ];

                        if (count($insArr) > 0) {
                            echo $twig->getTwig()->render('patient/card/insurance.html.twig', $viewArgs);
                        }
                    endif;  // end if demographics authorized
                                ?>
                            </div>
                        </div>

                        <!-- Messages Section -->
                        <div class="form-section" id="messages-section">
                            <div class="section-header">
                                <h3><?php echo xlt('Patient Messages'); ?></h3>
                                <p><?php echo xlt('Patient notes and communication history'); ?></p>
                            </div>
                            <div class="section-content">
                                <?php
                    if (AclMain::aclCheckCore('patients', 'notes')) :
                        $dispatchResult = $ed->dispatch(CardRenderEvent::EVENT_HANDLE, new CardRenderEvent('note'));
                        // Notes expand collapse widget
                        $id = "pnotes_ps_expand";
                        $viewArgs = [
                            'title' => xl("Messages"),
                            'id' => $id,
                            'btnLabel' => "Edit",
                            'btnLink' => "pnotes_full.php?form_active=1",
                            'initiallyCollapsed' => (getUserSetting($id) == 0) ? false : true,
                            'linkMethod' => "html",
                            'bodyClass' => "notab",
                            'auth' => AclMain::aclCheckCore('patients', 'notes', '', 'write'),
                            'prependedInjection' => $dispatchResult->getPrependedInjection(),
                            'appendedInjection' => $dispatchResult->getAppendedInjection(),
                        ];
                        echo $twig->getTwig()->render('patient/card/loader.html.twig', $viewArgs);
                    endif; // end if notes authorized
                                ?>
                            </div>
                        </div>

                        <!-- Vitals Section -->
                        <div class="form-section" id="vitals-section">
                            <div class="section-header">
                                <h3><?php echo xlt('Vital Signs'); ?></h3>
                                <p><?php echo xlt('Patient vital signs and measurements'); ?></p>
                            </div>
                            <div class="section-content">
                                <?php
                    if ($vitals_is_registered && AclMain::aclCheckCore('patients', 'med')) :
                        $dispatchResult = $ed->dispatch(CardRenderEvent::EVENT_HANDLE, new CardRenderEvent('vital_sign'));
                        // vitals expand collapse widget
                        // check to see if any vitals exist
                        $existVitals = sqlQuery("SELECT * FROM form_vitals WHERE pid=?", array($pid));
                        $widgetAuth = ($existVitals) ? true : false;

                        $id = "vitals_ps_expand";
                        $viewArgs = [
                            'title' => xl('Vitals'),
                            'id' => $id,
                            'initiallyCollapsed' => (getUserSetting($id) == 0) ? false : true,
                            'btnLabel' => 'Trend',
                            'btnLink' => "../encounter/trend_form.php?formname=vitals",
                            'linkMethod' => 'html',
                            'bodyClass' => 'collapse show',
                            'auth' => $widgetAuth,
                            'prependedInjection' => $dispatchResult->getPrependedInjection(),
                            'appendedInjection' => $dispatchResult->getAppendedInjection(),
                        ];
                        echo $twig->getTwig()->render('patient/card/loader.html.twig', $viewArgs);
                    endif; // end vitals
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div><!-- end main content div -->
        <?php $oemr_ui->oeBelowContainerDiv(); ?>
        <script>
            // Array of skip conditions for the checkSkipConditions() function.
            var skipArray = [
                <?php echo ($condition_str ?? ''); ?>
            ];
            checkSkipConditions();

            var isPost = <?php echo js_escape($showEligibility ?? false); ?>;
            var listId = '#' + <?php echo js_escape($list_id); ?>;
            $(function() {
                $(listId).addClass("active");
                if (isPost === true) {
                    $("#eligibility").click();
                    $("#eligibility").get(0).scrollIntoView();
                }
            });

            // Modern Patient Forms Navigation JavaScript
            $(document).ready(function() {
                const forms = ['demographics', 'insurance', 'messages', 'vitals'];
                let currentFormIndex = 0;

                // Initialize the form navigation
                function initFormNavigation() {
                    // Sidebar navigation
                    $('.nav-item').on('click', function(e) {
                        e.preventDefault();
                        const formType = $(this).data('form');
                        showForm(formType);
                    });

                    // Next/Back button navigation
                    $('#nextBtn').on('click', function() {
                        if (currentFormIndex < forms.length - 1) {
                            currentFormIndex++;
                            showForm(forms[currentFormIndex]);
                        }
                    });

                    $('#prevBtn').on('click', function() {
                        if (currentFormIndex > 0) {
                            currentFormIndex--;
                            showForm(forms[currentFormIndex]);
                        }
                    });

                    // Keyboard navigation
                    $(document).on('keydown', function(e) {
                        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                            e.preventDefault();
                            $('#nextBtn').click();
                        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                            e.preventDefault();
                            $('#prevBtn').click();
                        }
                    });
                }

                // Show the specified form
                function showForm(formType) {
                    // Hide all form sections
                    $('.form-section').removeClass('active').hide();
                    
                    // Show the selected form section
                    $(`#${formType}-section`).addClass('active').show();
                    
                    // Update sidebar navigation
                    $('.nav-item').removeClass('active');
                    $(`.nav-item[data-form="${formType}"]`).addClass('active');
                    
                    // Update current form index
                    currentFormIndex = forms.indexOf(formType);
                    
                    // Update navigation buttons
                    updateNavigationButtons();
                    
                    // Trigger any necessary form-specific initialization
                    initializeFormContent(formType);
                }

                // Update navigation button states
                function updateNavigationButtons() {
                    const prevBtn = $('#prevBtn');
                    const nextBtn = $('#nextBtn');
                    
                    // Show/hide back button
                    if (currentFormIndex === 0) {
                        prevBtn.hide();
                                } else {
                        prevBtn.show();
                    }
                    
                    // Update next button text
                    if (currentFormIndex === forms.length - 1) {
                        nextBtn.html('<?php echo xlt("Finish"); ?> <i class="fa fa-check"></i>');
                            } else {
                        nextBtn.html('<?php echo xlt("Next"); ?> <i class="fa fa-chevron-right"></i>');
                    }
                }

                // Initialize form-specific content
                function initializeFormContent(formType) {
                    switch(formType) {
                        case 'demographics':
                            // Ensure demographics form is properly initialized
                            if (typeof tabbify === 'function') {
                                tabbify();
                            }
                            break;
                        case 'insurance':
                            // Initialize insurance form if needed
                            break;
                        case 'messages':
                            // Initialize messages form if needed
                            break;
                        case 'vitals':
                            // Initialize vitals form if needed
                            break;
                    }
                }

                // Initialize the navigation
                initFormNavigation();
                
                // Show demographics by default (already active)
                updateNavigationButtons();
            });
        </script>
</body>

</html>
