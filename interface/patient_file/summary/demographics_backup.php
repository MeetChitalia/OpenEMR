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
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2017-2020 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2017 Sharon Cohen <sharonco@matrix.co.il>
 * @copyright Copyright (c) 2018-2020 Stephen Waite <stephen.waite@cmsvt.com>
 * @copyright Copyright (c) 2018 Ranganath Pathak <pathak@scrs1.org>
 * @copyright Copyright (c) 2018-2024 Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2020 Tyler Wrenn <tyler@tylerwrenn.com>
 * @copyright Copyright (c) 2021-2022 Robert Down <robertdown@live.com
 * @copyright Copyright (c) 2024 Care Management Solutions, Inc. <stephen.waite@cmsvt.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../../globals.php");

require_once("$srcdir/lists.inc");
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
use OpenEMR\Patient\Cards\BillingViewCard;
use OpenEMR\Patient\Cards\DemographicsViewCard;
use OpenEMR\Patient\Cards\InsuranceViewCard;
use OpenEMR\Patient\Cards\PortalCard;
use OpenEMR\Reminder\BirthdayReminder;
use OpenEMR\Services\AllergyIntoleranceService;
use OpenEMR\Services\ConditionService;
use OpenEMR\Services\ImmunizationService;
use OpenEMR\Services\PatientIssuesService;
use OpenEMR\Services\PatientService;
use Symfony\Component\EventDispatcher\EventDispatcher;

// Reset the previous name flag to allow normal operation.
// This is set in new.php so we can prevent new previous name from being added i.e no pid available.
OpenEMR\Common\Session\SessionUtil::setSession('disablePreviousNameAdds', 0);

$twig = new TwigContainer(null, $GLOBALS['kernel']);

// Set session for pid (via setpid). Also set session for encounter (if applicable)
if (isset($_GET['set_pid'])) {
    require_once("$srcdir/pid.inc");
    setpid($_GET['set_pid']);
    $ptService = new PatientService();
    $newPatient = $ptService->findByPid($pid);
    $ptService->touchRecentPatientList($newPatient);
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
$hiddenCards = getHiddenDashboardCards();

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

function getHiddenDashboardCards(): array
{
    $hiddenList = [];
    $ret = sqlStatement("SELECT gl_value FROM `globals` WHERE `gl_name` = 'hide_dashboard_cards'");
    while ($row = sqlFetchArray($ret)) {
        $hiddenList[] = $row['gl_value'];
    }

    return $hiddenList;
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

// Get the document ID's in a specific catg.
// this is only used in one place, here for id photos
function get_document_by_catg($pid, $doc_catg, $limit = 1)
{
    $results = null;

    if ($pid and $doc_catg) {
        $query = sqlStatement("SELECT d.id, d.date, d.url
            FROM documents AS d, categories_to_documents AS cd, categories AS c
            WHERE d.foreign_id = ?
            AND cd.document_id = d.id
            AND c.id = cd.category_id
            AND c.name LIKE ?
            ORDER BY d.date DESC LIMIT " . escape_limit($limit), array($pid, $doc_catg));
        
        if ($query) {
            while ($result = sqlFetchArray($query)) {
                $results[] = $result['id'];
            }
        }
    }
    return ($results ?? false);
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
        $num_of_days = $deceased_days . " " . xl("day ago");
    } elseif ($deceased_days > 1 && $deceased_days < 90) {
        $num_of_days = $deceased_days . " " . xl("days ago");
    } elseif ($deceased_days >= 90 && $deceased_days < 731) {
        $num_of_days = "~" . round($deceased_days / 30) . " " . xl("months ago");  // function intdiv available only in php7
    } elseif ($deceased_days >= 731) {
        $num_of_days = xl("More than") . " " . round($deceased_days / 365) . " " . xl("years ago");
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
            " $image_width alt='" . attr($doc_catg) . ":" . attr($image_file_name) . "'>  </a> </td> <td class='align-middle'>" .
            text($doc_catg) . '<br />&nbsp;' . text($image_file_name) . "</td>";
    } else {
        $to_url = "<td> <a href='" . $web_root . "/controller.php?document&retrieve" .
            "&patient_id=" . attr_url($pid) . "&document_id=" . attr_url($doc_id) . "'" .
            " onclick='top.restoreSession()' class='btn btn-primary btn-sm'>" .
            "<span>" .
            xlt("View") . "</a> &nbsp;" .
            text("$doc_catg - $image_file_name") .
            "</span> </td>";
    }

    echo "<table><tr>";
    echo $to_url;
    echo "</tr></table>";
}

// Determine if the Vitals form is in use for this site.
$tmp = sqlQuery("SELECT count(*) AS count FROM registry WHERE directory = 'vitals' AND state = 1");
$vitals_is_registered = ($tmp && is_array($tmp)) ? $tmp['count'] : 0;

// Get patient/employer/insurance information.
//
$result = getPatientData($pid, "*, DATE_FORMAT(DOB,'%Y-%m-%d') as DOB_YMD");
$result2 = getEmployerData($pid);
// Get insurance data directly to avoid ADORecordSet issues
// If insurance is hidden or table/data is missing, skip gracefully
$insco_name = "";
$show_insurance = false; // Default to false, only show if we have valid data
$result3 = false;

// Check if insurance_data table exists using a safer approach
$table_exists = false;
$table_check = @sqlQuery("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'insurance_data'");
if ($table_check && is_array($table_check)) {
    $table_exists = true;
}

if ($table_exists) {
    // Table exists, try to get insurance data with error suppression
    $result3 = @sqlQuery(
        "SELECT copay, provider, DATE_FORMAT(`date`,'%Y-%m-%d') as effdate, DATE_FORMAT(`date_end`,'%Y-%m-%d') as effdate_end 
         FROM insurance_data 
         WHERE pid = ? AND type = 'primary' 
         ORDER BY date DESC LIMIT 1", 
        array($pid)
    );
    
    if ($result3 && is_array($result3) && !empty($result3['provider'])) {
    $insco_name = getInsuranceProvider($result3['provider']);
        $show_insurance = true;
    }
}

$arrOeUiSettings = array(
    'page_id' => 'core.mrd',
    'heading_title' => xl('Medical Record Dashboard'),
    'include_patient_name' => true,
    'expandable' => true,
    'expandable_files' => array('demographics_xpd'), //all file names need suffix _xpd
    'action' => "", //conceal, reveal, search, reset, link or back
    'action_title' => "",
    'action_href' => "", //only for actions - reset, link or back
    'show_help_icon' => true,
    'help_file_name' => "medical_dashboard_help.php"
);
$oemr_ui = new OemrUI($arrOeUiSettings);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo xlt("Patient Dashboard"); ?> - <?php echo text(getPatientName($pid)); ?></title>
    
    <?php
    Header::setupHeader(['common', 'utility', 'datetime-picker']);
    require_once("$srcdir/options.js.php");
    ?>
    
    <style>
        /* Enhanced Patient Dashboard Styles */
        .patient-dashboard {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            padding: 20px 0;
        }
        
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px 30px;
            position: relative;
        }
        
        .dashboard-header h1 {
            margin: 0;
            font-size: 2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .patient-info {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-top: 10px;
        }
        
        .patient-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: bold;
        }
        
        .patient-details h3 {
            margin: 0 0 5px 0;
            font-size: 1.3rem;
        }
        
        .patient-details p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.95rem;
        }
        
        .dashboard-nav {
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
            padding: 0 30px;
        }
        
        .nav-tabs {
            border: none;
            margin: 0;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 500;
            padding: 15px 20px;
            border-radius: 0;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .nav-tabs .nav-link:hover {
            color: #495057;
            background: rgba(102, 126, 234, 0.1);
        }
        
        .nav-tabs .nav-link.active {
            color: #667eea;
            background: white;
            border-bottom: 3px solid #667eea;
        }
        
        .dashboard-content {
            padding: 30px;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .main-content {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .sidebar-content {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .dashboard-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border: 1px solid #e9ecef;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .dashboard-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        
        .card-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 15px 20px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .card-header:hover {
            background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
        }
        
        .card-title {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: #495057;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-actions {
            display: flex;
            gap: 8px;
        }
        
        .btn-card {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }
        
        .btn-card.btn-edit {
            background: #28a745;
            color: white;
        }
        
        .btn-card.btn-edit:hover {
            background: #218838;
            transform: translateY(-1px);
        }
        
        .btn-card.btn-add {
            background: #007bff;
            color: white;
        }
        
        .btn-card.btn-add:hover {
            background: #0056b3;
            transform: translateY(-1px);
        }
        
        .card-body {
            padding: 20px;
        }
        
        .card-content {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .list-group-item {
            border: none;
            border-bottom: 1px solid #f1f3f4;
            padding: 12px 0;
            background: transparent;
            transition: all 0.3s ease;
        }
        
        .list-group-item:hover {
            background: #f8f9fa;
            padding-left: 10px;
        }
        
        .list-group-item:last-child {
            border-bottom: none;
        }
        
        .alert-warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .empty-state h4 {
            margin-bottom: 10px;
            color: #495057;
        }
        
        .empty-state p {
            margin: 0;
            font-size: 0.9rem;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-container {
                margin: 10px;
                border-radius: 10px;
            }
            
            .dashboard-header {
                padding: 20px;
            }
            
            .dashboard-header h1 {
                font-size: 1.5rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .patient-info {
                flex-direction: column;
                text-align: center;
            }
            
            .dashboard-content {
                padding: 20px;
            }
            
            .card-header {
                padding: 12px 15px;
            }
            
            .card-body {
                padding: 15px;
            }
        }
        
        /* Loading States */
        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
            color: #6c757d;
        }
        
        .loading i {
            animation: spin 1s linear infinite;
            margin-right: 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Enhanced Form Controls */
        .form-control {
            border-radius: 8px;
            border: 2px solid #e9ecef;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        /* Enhanced Buttons */
        .btn {
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
        }
        
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #e83e8c 100%);
        }
    </style>
    
    <script>
        // Enhanced JavaScript for Patient Dashboard
        $(document).ready(function() {
            // Initialize tooltips
            $('[data-toggle="tooltip"]').tooltip();
            
            // Initialize popovers
            $('[data-toggle="popover"]').popover();
            
            // Smooth scrolling for anchor links
            $('a[href^="#"]').on('click', function(event) {
                var target = $(this.getAttribute('href'));
                if (target.length) {
                    event.preventDefault();
                    $('html, body').stop().animate({
                        scrollTop: target.offset().top - 100
                    }, 1000);
                }
            });
            
            // Enhanced card collapse functionality
            $('.card-header').on('click', function() {
                var cardBody = $(this).next('.card-body');
                var icon = $(this).find('.fa');
                
                if (cardBody.hasClass('show')) {
                    cardBody.removeClass('show');
                    icon.removeClass('fa-compress').addClass('fa-expand');
                } else {
                    cardBody.addClass('show');
                    icon.removeClass('fa-expand').addClass('fa-compress');
                }
            });
            
            // Auto-refresh functionality for critical data
            setInterval(function() {
                refreshCriticalData();
            }, 300000); // Refresh every 5 minutes
        });
        
        // Enhanced functions
        function referentialCdsClick(codetype, codevalue) {
            top.restoreSession();
            dlgopen('../education.php?type=' + encodeURIComponent(codetype) + '&code=' + encodeURIComponent(codevalue), '_blank', 1024, 750, true);
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

        function deleteme() {
            if (confirm('<?php echo xlj("Are you sure you want to delete this patient?"); ?>')) {
                dlgopen('../deleter.php?patient=' + <?php echo js_url($pid); ?> +'&csrf_token_form=' + <?php echo js_url(CsrfUtils::collectCsrfToken()); ?>, '_blank', 500, 450, '', '', {
                allowResize: false,
                allowDrag: false,
                dialogId: 'patdel',
                type: 'iframe'
            });
            }
            return false;
        }

        function imdeleted() {
            top.clearPatient();
        }

        function newEvt() {
            let title = <?php echo xlj('Appointments'); ?>;
            let url = '../../main/calendar/add_edit_event.php?patientid=' + <?php echo js_url($pid); ?>;
            dlgopen(url, '_blank', 800, 500, '', title);
            return false;
        }

        function toggleIndicator(target, div) {
            var $target = $(target);
            var $indicator = $target.find(".indicator");
            var $div = $("#" + div);
            
            if ($indicator.text() == <?php echo xlj('collapse'); ?>) {
                $indicator.text(<?php echo xlj('expand'); ?>);
                $div.slideUp(300);
                $.post("../../../library/ajax/user_settings.php", {
                    target: div,
                    mode: 0,
                    csrf_token_form: <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>
                });
            } else {
                $indicator.text(<?php echo xlj('collapse'); ?>);
                $div.slideDown(300);
                $.post("../../../library/ajax/user_settings.php", {
                    target: div,
                    mode: 1,
                    csrf_token_form: <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>
                });
            }
        }

        function editScripts(url) {
            let title = <?php echo xlj('Prescriptions'); ?>;
            let w = 960;

            dlgopen(url, 'editScripts', w, 400, '', '', {
                resolvePromiseOn: 'close',
                allowResize: true,
                allowDrag: true,
                dialogId: 'editscripts',
                type: 'iframe'
            }).then(() => refreshme());
            return false;
        }

        async function fetchHtml(url, embedded = false, sessionRestore = false) {
            if (sessionRestore === true) {
                top.restoreSession();
            }
            let csrf = new FormData();
            csrf.append("csrf_token_form", <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>);
            if (embedded === true) {
                csrf.append("embeddedScreen", true);
            }

            try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                body: csrf
            });
            return await response.text();
            } catch (error) {
                console.error('Error fetching HTML:', error);
                return '<div class="alert alert-danger">Error loading content</div>';
            }
        }

        async function placeHtml(url, divId, embedded = false, sessionRestore = false) {
            const contentDiv = document.getElementById(divId);
            if (contentDiv) {
                contentDiv.innerHTML = '<div class="loading"><i class="fa fa-spinner"></i> Loading...</div>';
                try {
                    const fragment = await fetchHtml(url, embedded, sessionRestore);
                    contentDiv.innerHTML = fragment;
                } catch (error) {
                    contentDiv.innerHTML = '<div class="alert alert-danger">Error loading content</div>';
                }
            }
        }

            function load_location(location) {
                top.restoreSession();
                document.location = location;
        }
        
        function refreshCriticalData() {
            // Refresh critical patient data without full page reload
                    $.ajax({
                url: 'ajax_refresh_patient_data.php',
                method: 'POST',
                        data: {
                    pid: <?php echo js_escape($pid); ?>,
                    csrf_token_form: <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>
                },
                success: function(data) {
                    // Update critical data sections
                    if (data.allergies) {
                        $('#allergies_content').html(data.allergies);
                    }
                    if (data.medications) {
                        $('#medications_content').html(data.medications);
                    }
                    if (data.vitals) {
                        $('#vitals_content').html(data.vitals);
                    }
                        },
                        error: function() {
                    console.log('Failed to refresh critical data');
                }
            });
        }
        
        // Enhanced error handling
        window.addEventListener('error', function(e) {
            console.error('JavaScript error:', e.error);
            // Show user-friendly error message
            if (e.error && e.error.message) {
                showNotification('Error: ' + e.error.message, 'error');
            }
        });
        
        function showNotification(message, type = 'info') {
            const notification = $('<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
                message +
                '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                '<span aria-hidden="true">&times;</span>' +
                '</button>' +
                '</div>');
            
            $('.dashboard-content').prepend(notification);
            
            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                notification.alert('close');
            }, 5000);
        }
        
        // Check if reload is required
        if (typeof reloadRequired !== 'undefined' && reloadRequired) {
            document.location.reload();
        }

                    <?php
                    if ($GLOBALS['erx_import_status_message']) { ?>
            if (typeof msg_updation !== 'undefined' && msg_updation) {
                            alert(msg_updation);
            }
                        <?php
            }
            ?>
    </script>

    <script>
            // load divs
            placeHtml("stats.php", "stats_div", true).then(() => {
                $('[data-toggle="collapse"]').on('click', function (e) {
                    updateUserVisibilitySetting(e);
                });
            });
            placeHtml("pnotes_fragment.php", 'pnotes_ps_expand').then(() => {
                // must be delegated event!
                $(this).on("click", ".complete_btn", function () {
                    let btn = $(this);
                    let csrf = new FormData;
                    csrf.append("csrf_token_form", <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>);
                    fetch("pnotes_fragment.php?docUpdateId=" + encodeURIComponent(btn.attr('data-id')), {
                            method: "POST",
                            credentials: 'same-origin',
                            body: csrf
                    }).then(function () {
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
                $(".medium_modal").on('click', function (e) {
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
                $(".cdr-rule-btn-info-launch").on("click", function (e) {
                    let pid = <?php echo js_escape($pid); ?>;
                    let csrfToken = <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>;
                    let ruleId = $(this).data("ruleId");
                    let launchUrl = "<?php echo $GLOBALS['webroot']; ?>/interface/super/rules/index.php?action=review!view&pid="
                        + encodeURIComponent(pid) + "&rule_id=" + encodeURIComponent(ruleId) + "&csrf_token_form=" + encodeURIComponent(csrfToken);
                    e.preventDefault();
                    e.stopPropagation();
                    // as we're loading another iframe, make sure to sync session
                    window.top.restoreSession();

                    let windowMessageHandler = function () {
                        console.log("received message ", event);
                        if (event.origin !== window.location.origin) {
                            return;
                        }
                        let data = event.data;
                        if (data && data.type === 'cdr-edit-source') {
                            window.name = event.source.name;
                            dlgclose();
                            window.top.removeEventListener('message', windowMessageHandler);
                            // loadFrame already handles webroot and /interface/ prefix.
                            let editUrl = '/super/rules/index.php?action=edit!summary&id=' +encodeURIComponent(data.ruleId)
                                + "&csrf_token=" + encodeURIComponent(csrfToken);
                            window.parent.left_nav.loadFrame('adm', 'adm0', editUrl);
                        }
                    };
                    window.top.addEventListener('message', windowMessageHandler);

                    dlgopen('', 'cdrEditSource', 800, 200, '', '', {
                        buttons: [{
                            text: <?php echo xlj('Close'); ?>,
                            close: true,
                            style: 'secondary btn-sm'
                        }],
                        // don't think we need to refresh
                        // onClosed: 'refreshme',
                        allowResize: true,
                        allowDrag: true,
                        dialogId: 'rulereview',
                        type: 'iframe',
                        url: launchUrl,
                        onClose: function() {
                            window.top.removeEventListener('message', windowMessageHandler);
                        }
                    });
                })
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
            $(".large_modal").on('click', function (e) {
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

            $(".rx_modal").on('click', function (e) {
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
            $(".image_modal").on('click', function (e) {
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

            $(".deleter").on('click', function (e) {
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

            $(".iframe1").on('click', function (e) {
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
            $(".small_modal").on('click', function (e) {
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
            // test ensure at least an element we want.
            if (target.classList.contains("collapse")) {
                // who is icon. Easier to catch BS event than create one specific for this decision..
                // Should always be icon target
                let iconTarget = e.target.children[0] || e.target;
                // toggle
                if (iconTarget.classList.contains("fa-expand")) {
                    iconTarget.classList.remove('fa-expand');
                    iconTarget.classList.add('fa-compress');
                } else {
                    iconTarget.classList.remove('fa-compress');
                    iconTarget.classList.add('fa-expand');
                }
            }
            let formData = new FormData();
            formData.append("csrf_token_form", <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>);
            formData.append("target", targetStr);
            formData.append("mode", (target.classList.contains("show")) ? 0 : 1);
            top.restoreSession();
            const response = await fetch("../../../library/ajax/user_settings.php", {
                method: "POST",
                credentials: 'same-origin',
                body: formData,
            });

            return await response.text();
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
            var EncounterDateArray = [];
            var CalendarCategoryArray = [];
            var EncounterIdArray = [];
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
            encurl = 'encounter/encounter_top.php?set_encounter=' + <?php echo js_url($encounter); ?> +'&pid=' + <?php echo js_url($pid); ?>;
                parent.left_nav.setEncounter(<?php echo js_escape(oeFormatShortDate(date("Y-m-d", strtotime($query_result['date'])))); ?>, <?php echo js_escape($encounter); ?>, 'enc');
                top.restoreSession();
                parent.left_nav.loadFrame('enc2', 'enc', 'patient_file/' + encurl);
            <?php } // end setting new encounter id (only if new pid is also set)
            ?>
        }

        $(window).on('load', function () {
            setMyPatient();
        });
    </script>

    <style>
        /* Bad practice to override here, will get moved to base style theme */
        .card {
            box-shadow: 1px 1px 1px hsl(0 0% 0% / .2);
            border-radius: 0;
        }

      /* Short term fix. This ensures the problem list, allergies, medications, and immunization cards handle long lists without interuppting
         the UI. This should be configurable and should go in a more appropriate place
      .pami-list {
          max-height: 200px;
          overflow-y: scroll;
      } */

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

      <?php } ?>
      :root {
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
    </style>
</head>

<body class="patient-dashboard">

    <?php
    // Create and fire the patient demographics view event
    $viewEvent = new ViewEvent($pid);
    $viewEvent = $GLOBALS["kernel"]->getEventDispatcher()->dispatch($viewEvent, ViewEvent::EVENT_HANDLE, 10);
    $thisauth = AclMain::aclCheckCore('patients', 'demo');

    if (!$thisauth || !$viewEvent->authorized()) {
        echo $twig->getTwig()->render('core/unauthorized-partial.html.twig', ['pageTitle' => xl("Medical Dashboard")]);
        exit();
    }
    ?>

    <div class="dashboard-container">
        <!-- Enhanced Dashboard Header -->
        <div class="dashboard-header">
            <h1>
                <span><i class="fa fa-user-md"></i> <?php echo xlt("Patient Dashboard"); ?></span>
                <div class="patient-info">
                    <div class="patient-avatar">
                        <?php echo strtoupper(substr(getPatientName($pid), 0, 1)); ?>
                    </div>
                    <div class="patient-details">
                        <h3><?php echo text(getPatientName($pid)); ?></h3>
                        <p>ID: <?php echo text($pid); ?> | DOB: <?php echo text($result['DOB']); ?></p>
                    </div>
                </div>
            </h1>
        </div>

        <!-- Enhanced Navigation -->
        <div class="dashboard-nav">
            <?php
        if ($thisauth) :
            require_once("$include_root/patient_file/summary/dashboard_header.php");
        endif;

            $list_id = "dashboard";
        $menuPatient = new PatientMenuRole($twig);
        $menuPatient->displayHorizNavBarMenu();
            
        $idcard_doc_id = false;
        if ($GLOBALS['patient_id_category_name']) {
                $idcard_doc_id = get_document_by_catg($pid, $GLOBALS['patient_id_category_name'], 3);
            }
            ?>
                    </div>

        <!-- Main Dashboard Content -->
        <div class="dashboard-content">
            <!-- Hidden popup links -->
            <a href='../reminder/active_reminder_popup.php' id='reminder_popup_link' style='display: none' onclick='top.restoreSession()'></a>
            <a href='../birthday_alert/birthday_pop.php?pid=<?php echo attr_url($pid); ?>&user_id=<?php echo attr_url($_SESSION['authUserID']); ?>' id='birthday_popup' style='display: none;' onclick='top.restoreSession()'></a>
            
            <!-- Alert Messages -->
            <?php if ($deceased > 0) : ?>
                <div class="alert alert-warning">
                    <i class="fa fa-exclamation-triangle"></i>
                    <strong><?php echo xlt("Patient Deceased"); ?>:</strong> 
                    <?php echo text(deceasedDays($deceased)); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($allergyWarningMessage)) : ?>
                <div class="alert alert-danger">
                    <i class="fa fa-exclamation-circle"></i>
                    <strong><?php echo xlt("Allergy Warning"); ?>:</strong> 
                    <?php echo $allergyWarningMessage; ?>
                </div>
            <?php endif; ?>

            <!-- Dashboard Grid Layout -->
            <div class="dashboard-grid">
                <!-- Main Content Column -->
                <div class="main-content">
                    <?php
                    $t = $twig->getTwig();

                    $allergy = (AclMain::aclCheckIssue('allergy') ? 1 : 0) && !in_array('card_allergies', $hiddenCards) ? 1 : 0;
                    $pl = (AclMain::aclCheckIssue('medical_problem') ? 1 : 0) && !in_array('card_medicalproblems', $hiddenCards) ? 1 : 0;
                    $meds = (AclMain::aclCheckIssue('medication') ? 1 : 0) && !in_array('card_medication', $hiddenCards) ? 1 : 0;
                    $rx = !$GLOBALS['disable_prescriptions'] && AclMain::aclCheckCore('patients', 'rx') && !in_array('card_prescriptions', $hiddenCards) ? 1 : 0;

                    /**
                     * Helper function to return only issues with an outcome not equal to resolved
                     *
                     * @param array $i An array of issues
                     * @return array
                     */
                    function filterActiveIssues(array $i): array
                    {
                        return array_filter($i, function ($_i) {
                            return ($_i['outcome'] != 1) && (empty($_i['enddate']) || (strtotime($_i['enddate']) > strtotime('now')));
                        });
                    }

                    // Enhanced Medical Issues Row
                    if ($allergy === 1 || $pl === 1 || $meds === 1) : ?>
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fa fa-stethoscope"></i>
                                    <?php echo xlt("Medical Issues"); ?>
                                </h5>
                                <div class="card-actions">
                                    <a href="<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/stats_full.php?active=all" 
                                       class="btn-card btn-edit" onclick="top.restoreSession()">
                                        <i class="fa fa-edit"></i> <?php echo xlt("Manage All"); ?>
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php
                                    // ALLERGY CARD
                                    if ($allergy === 1) {
                                        $allergyService = new AllergyIntoleranceService();
                                        $_rawAllergies = filterActiveIssues($allergyService->getAll(['lists.pid' => $pid])->getData());
                                        ?>
                                        <div class="col-md-4">
                                            <div class="dashboard-card">
                                                <div class="card-header">
                                                    <h6 class="card-title">
                                                        <i class="fa fa-exclamation-triangle text-warning"></i>
                                                        <?php echo xlt("Allergies"); ?>
                                                        <?php if (!empty($_rawAllergies)) : ?>
                                                            <span class="badge badge-warning"><?php echo count($_rawAllergies); ?></span>
                                                        <?php endif; ?>
                                                    </h6>
                                                    <div class="card-actions">
                                                        <a href="<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/stats_full.php?active=all&category=allergy" 
                                                           class="btn-card btn-edit" onclick="top.restoreSession()">
                                                            <i class="fa fa-edit"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                                <div class="card-body">
                                                    <div class="card-content" id="allergies_content">
                                                        <?php if (empty($_rawAllergies)) : ?>
                                                            <div class="empty-state">
                                                                <i class="fa fa-check-circle text-success"></i>
                                                                <h6><?php echo xlt("No Known Allergies"); ?></h6>
                                                                <p><?php echo xlt("Patient has no recorded allergies"); ?></p>
                                                            </div>
                                                        <?php else : ?>
                                                            <div class="list-group list-group-flush">
                                                                <?php foreach ($_rawAllergies as $allergy) : ?>
                                                                    <div class="list-group-item">
                                                                        <div class="d-flex justify-content-between align-items-start">
                                                                            <div>
                                                                                <strong><?php echo text($allergy['title']); ?></strong>
                                                                                <?php if (!empty($allergy['reaction_title'])) : ?>
                                                                                    <br><small class="text-muted"><?php echo xlt("Reaction"); ?>: <?php echo text($allergy['reaction_title']); ?></small>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                            <?php if (!empty($allergy['severity_al'])) : ?>
                                                                                <span class="status-badge <?php echo ($allergy['severity_al'] == 'severe' || $allergy['severity_al'] == 'life_threatening_severity' || $allergy['severity_al'] == 'fatal') ? 'status-danger' : 'status-warning'; ?>">
                                                                                    <?php echo text(getListItemTitle('severity_ccda', $allergy['severity_al'])); ?>
                                                                                </span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php } ?>

                                    <?php
                                    $patIssueService = new PatientIssuesService();

                                    // MEDICAL PROBLEMS CARD
                                    if ($pl === 1) {
                                        $_rawPL = $patIssueService->search(['lists.pid' => $pid, 'lists.type' => 'medical_problem'])->getData();
                                        $activeProblems = filterActiveIssues($_rawPL);
                                        ?>
                                        <div class="col-md-4">
                                            <div class="dashboard-card">
                                                <div class="card-header">
                                                    <h6 class="card-title">
                                                        <i class="fa fa-heartbeat text-danger"></i>
                                                        <?php echo xlt("Medical Problems"); ?>
                                                        <?php if (!empty($activeProblems)) : ?>
                                                            <span class="badge badge-danger"><?php echo count($activeProblems); ?></span>
                                                        <?php endif; ?>
                                                    </h6>
                                                    <div class="card-actions">
                                                        <a href="<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/stats_full.php?active=all&category=medical_problem" 
                                                           class="btn-card btn-edit" onclick="top.restoreSession()">
                                                            <i class="fa fa-edit"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                                <div class="card-body">
                                                    <div class="card-content" id="medical_problems_content">
                                                        <?php if (empty($activeProblems)) : ?>
                                                            <div class="empty-state">
                                                                <i class="fa fa-check-circle text-success"></i>
                                                                <h6><?php echo xlt("No Active Problems"); ?></h6>
                                                                <p><?php echo xlt("No active medical problems recorded"); ?></p>
                                                            </div>
                                                        <?php else : ?>
                                                            <div class="list-group list-group-flush">
                                                                <?php foreach ($activeProblems as $problem) : ?>
                                                                    <div class="list-group-item">
                                                                        <div class="d-flex justify-content-between align-items-start">
                                                                            <div>
                                                                                <strong><?php echo text($problem['title']); ?></strong>
                                                                                <?php if (!empty($problem['diagnosis'])) : ?>
                                                                                    <br><small class="text-muted"><?php echo text($problem['diagnosis']); ?></small>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                            <?php if (!empty($problem['begdate'])) : ?>
                                                                                <span class="status-badge status-active">
                                                                                    <?php echo text(date('M Y', strtotime($problem['begdate']))); ?>
                                                                                </span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php } ?>

                                    <!-- MEDICATION CARD -->
                                    <?php if ($meds === 1) {
                                        $_rawMedList = $patIssueService->search(['lists.pid' => $pid, 'lists.type' => 'medication'])->getData();
                                        $activeMeds = filterActiveIssues($_rawMedList);
                                        ?>
                                        <div class="col-md-4">
                                            <div class="dashboard-card">
                                                <div class="card-header">
                                                    <h6 class="card-title">
                                                        <i class="fa fa-pills text-primary"></i>
                                                        <?php echo xlt("Medications"); ?>
                                                        <?php if (!empty($activeMeds)) : ?>
                                                            <span class="badge badge-primary"><?php echo count($activeMeds); ?></span>
                                                        <?php endif; ?>
                                                    </h6>
                                                    <div class="card-actions">
                                                        <a href="<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/stats_full.php?active=all&category=medication" 
                                                           class="btn-card btn-edit" onclick="top.restoreSession()">
                                                            <i class="fa fa-edit"></i>
                                                        </a>
                </div>
                                                </div>
                                                <div class="card-body">
                                                    <div class="card-content" id="medications_content">
                                                        <?php if (empty($activeMeds)) : ?>
                                                            <div class="empty-state">
                                                                <i class="fa fa-check-circle text-success"></i>
                                                                <h6><?php echo xlt("No Active Medications"); ?></h6>
                                                                <p><?php echo xlt("No active medications recorded"); ?></p>
                                                            </div>
                                                        <?php else : ?>
                                                            <div class="list-group list-group-flush">
                                                                <?php foreach ($activeMeds as $med) : ?>
                                                                    <div class="list-group-item">
                                                                        <div class="d-flex justify-content-between align-items-start">
                                                                            <div>
                                                                                <strong><?php echo text($med['title']); ?></strong>
                                                                                <?php if (!empty($med['diagnosis'])) : ?>
                                                                                    <br><small class="text-muted"><?php echo text($med['diagnosis']); ?></small>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                            <?php if (!empty($med['begdate'])) : ?>
                                                                                <span class="status-badge status-active">
                                                                                    <?php echo text(date('M Y', strtotime($med['begdate']))); ?>
                                                                                </span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Prescriptions Section -->
                    <?php if ($rx === 1) : ?>
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fa fa-prescription-bottle-alt"></i>
                                    <?php echo xlt("Prescriptions"); ?>
                                </h5>
                                <div class="card-actions">
                                    <?php if ($GLOBALS['erx_enable']) : ?>
                                        <a href="<?php echo $GLOBALS['webroot']; ?>/interface/eRx.php?page=compose" 
                                           class="btn-card btn-add" onclick="top.restoreSession()">
                                            <i class="fa fa-plus"></i> <?php echo xlt("New Rx"); ?>
                                        </a>
                                    <?php else : ?>
                                        <a href="#" onclick="editScripts('<?php echo $GLOBALS['webroot']; ?>/controller.php?prescription&list&id=<?php echo attr_url($pid); ?>')" 
                                           class="btn-card btn-edit">
                                            <i class="fa fa-edit"></i> <?php echo xlt("Edit"); ?>
                                        </a>
                                    <?php endif; ?>
                    </div>
                            </div>
                            <div class="card-body">
                                <div class="card-content" id="prescriptions_content">
                                    <?php
                                    // Get current prescriptions
                                    $sql = "SELECT * FROM prescriptions WHERE patient_id = ? AND active = '1' ORDER BY date_added DESC LIMIT 10";
                                    $res = sqlStatement($sql, [$pid]);
                                    $rxArr = [];
                                    while ($row = sqlFetchArray($res)) {
                                        $row['unit'] = generate_display_field(array('data_type' => '1', 'list_id' => 'drug_units'), $row['unit']);
                                        $row['form'] = generate_display_field(array('data_type' => '1', 'list_id' => 'drug_form'), $row['form']);
                                        $row['route'] = generate_display_field(array('data_type' => '1', 'list_id' => 'drug_route'), $row['route']);
                                        $row['interval'] = generate_display_field(array('data_type' => '1', 'list_id' => 'drug_interval'), $row['interval']);
                                        $rxArr[] = $row;
                                    }
                                    
                                    if (empty($rxArr)) : ?>
                                        <div class="empty-state">
                                            <i class="fa fa-prescription-bottle-alt text-muted"></i>
                                            <h6><?php echo xlt("No Active Prescriptions"); ?></h6>
                                            <p><?php echo xlt("No active prescriptions found"); ?></p>
                            </div>
                                    <?php else : ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($rxArr as $rx) : ?>
                                                <div class="list-group-item">
                                                    <div class="d-flex justify-content-between align-items-start">
                                                        <div>
                                                            <strong><?php echo text($rx['drug']); ?></strong>
                                                            <?php if (!empty($rx['dosage'])) : ?>
                                                                <br><small class="text-muted">
                                                                    <?php echo text($rx['dosage']); ?>
                                                                    <?php if (!empty($rx['unit'])) : ?>
                                                                        <?php echo text($rx['unit']); ?>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($rx['route'])) : ?>
                                                                        <?php echo xlt("via"); ?> <?php echo text($rx['route']); ?>
                                                                    <?php endif; ?>
                                                                </small>
                                                            <?php endif; ?>
                                                            <?php if (!empty($rx['interval'])) : ?>
                                                                <br><small class="text-info"><?php echo text($rx['interval']); ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if (!empty($rx['date_added'])) : ?>
                                                            <span class="status-badge status-active">
                                                                <?php echo text(date('M Y', strtotime($rx['date_added']))); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (count($rxArr) >= 10) : ?>
                                            <div class="text-center mt-3">
                                                <a href="<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/stats_full.php?active=all&category=prescriptions" 
                                                   class="btn btn-outline-primary btn-sm" onclick="top.restoreSession()">
                                                    <?php echo xlt("View All Prescriptions"); ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php
                    // Enhanced Demographics Section
                    $sectionRenderEvents = $ed->dispatch(new SectionEvent('primary'), SectionEvent::EVENT_HANDLE);
                    $sectionRenderEvents->addCard(new DemographicsViewCard($result, $result2, ['dispatcher' => $ed]));

                    if ($show_insurance) {
                        $sectionRenderEvents->addCard(new BillingViewCard($pid, $insco_name, $result['billing_note'], $result3, ['dispatcher' => $ed]));
                    }

                    if (!in_array('card_insurance', $hiddenCards)) {
                        $sectionRenderEvents->addCard(new InsuranceViewCard($pid, ['dispatcher' => $ed]));
                    }
                    
                    $sectionCards = $sectionRenderEvents->getCards();
                    $GLOBALS["kernel"]->getEventDispatcher()->dispatch(new RenderEvent($pid), RenderEvent::EVENT_SECTION_LIST_RENDER_BEFORE, 10);

                    // Enhanced Demographics Cards
                    foreach ($sectionCards as $card) {
                        $_auth = $card->getAcl();
                        if (!empty($_auth) && !AclMain::aclCheckCore($_auth[0], $_auth[1])) {
                            continue;
                        }

                        $btnLabel = false;
                        if ($card->canAdd()) {
                            $btnLabel = 'Add';
                        } elseif ($card->canEdit()) {
                            $btnLabel = 'Edit';
                        }
                        ?>
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fa fa-user"></i>
                                    <?php echo text($card->getTitle()); ?>
                                </h5>
                                <?php if ($btnLabel) : ?>
                                    <div class="card-actions">
                                        <a href="#" class="btn-card btn-edit" onclick="top.restoreSession()">
                                            <i class="fa fa-edit"></i> <?php echo xlt($btnLabel); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <div class="card-content">
                                    <?php
                        $viewArgs = [
                            'title' => $card->getTitle(),
                            'id' => $card->getIdentifier(),
                                        'initiallyCollapsed' => $card->isInitiallyCollapsed(),
                            'card_bg_color' => $card->getBackgroundColorClass(),
                            'card_text_color' => $card->getTextColorClass(),
                            'forceAlwaysOpen' => !$card->canCollapse(),
                            'btnLabel' => $btnLabel,
                            'btnLink' => 'test',
                        ];
                                    echo $t->render($card->getTemplateFile(), array_merge($viewArgs, $card->getTemplateVariables()));
                                    ?>
                                </div>
                            </div>
                        </div>
                    <?php } ?>

                    <!-- Enhanced Notes Section -->
                    <?php if (AclMain::aclCheckCore('patients', 'notes')) :
                        $dispatchResult = $ed->dispatch(new CardRenderEvent('note'), CardRenderEvent::EVENT_HANDLE);
                        ?>
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fa fa-comments"></i>
                                    <?php echo xlt("Messages & Notes"); ?>
                                </h5>
                                <div class="card-actions">
                                    <a href="pnotes_full.php?form_active=1" 
                                       class="btn-card btn-edit" onclick="top.restoreSession()">
                                        <i class="fa fa-edit"></i> <?php echo xlt("Edit"); ?>
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="card-content" id="notes_content">
                                    <?php echo $dispatchResult->getPrependedInjection(); ?>
                                    <div class="loading">
                                        <i class="fa fa-spinner"></i> <?php echo xlt("Loading messages..."); ?>
                                    </div>
                                    <?php echo $dispatchResult->getAppendedInjection(); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Enhanced Reminders Section -->
                    <?php if (AclMain::aclCheckCore('patients', 'reminder') && $GLOBALS['enable_cdr'] && $GLOBALS['enable_cdr_prw'] && !in_array('card_patientreminders', $hiddenCards)) :
                        $dispatchResult = $ed->dispatch(new CardRenderEvent('reminder'), CardRenderEvent::EVENT_HANDLE);
                        ?>
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fa fa-bell"></i>
                                    <?php echo xlt("Patient Reminders"); ?>
                                </h5>
                                <div class="card-actions">
                                    <a href="../reminder/patient_reminders.php?mode=simple&patient_id=<?php echo attr_url($pid); ?>" 
                                       class="btn-card btn-edit" onclick="top.restoreSession()">
                                        <i class="fa fa-edit"></i> <?php echo xlt("Edit"); ?>
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="card-content" id="reminders_content">
                                    <?php echo $dispatchResult->getPrependedInjection(); ?>
                                    <div class="loading">
                                        <i class="fa fa-spinner"></i> <?php echo xlt("Loading reminders..."); ?>
                                    </div>
                                    <?php echo $dispatchResult->getAppendedInjection(); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Enhanced Disclosures Section -->
                    <?php if (AclMain::aclCheckCore('patients', 'disclosure') && !in_array('card_disclosure', $hiddenCards)) :
                        $authWriteDisclosure = AclMain::aclCheckCore('patients', 'disclosure', '', 'write');
                        $authAddonlyDisclosure = AclMain::aclCheckCore('patients', 'disclosure', '', 'addonly');
                        $dispatchResult = $ed->dispatch(new CardRenderEvent('disclosure'), CardRenderEvent::EVENT_HANDLE);
                        ?>
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fa fa-file-contract"></i>
                                    <?php echo xlt("Disclosures"); ?>
                                </h5>
                                <?php if ($authWriteDisclosure || $authAddonlyDisclosure) : ?>
                                    <div class="card-actions">
                                        <a href="disclosure_full.php" 
                                           class="btn-card btn-edit" onclick="top.restoreSession()">
                                            <i class="fa fa-edit"></i> <?php echo xlt("Edit"); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <div class="card-content" id="disclosures_content">
                                    <?php echo $dispatchResult->getPrependedInjection(); ?>
                                    <div class="loading">
                                        <i class="fa fa-spinner"></i> <?php echo xlt("Loading disclosures..."); ?>
                                    </div>
                                    <?php echo $dispatchResult->getAppendedInjection(); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Enhanced Amendments Section -->
                    <?php if ($GLOBALS['amendments'] && AclMain::aclCheckCore('patients', 'amendment') && !in_array('card_amendments', $hiddenCards)) :
                        $dispatchResult = $ed->dispatch(new CardRenderEvent('amendment'), CardRenderEvent::EVENT_HANDLE);
                        
                        // Get amendments data
                        $sql = "SELECT * FROM amendments WHERE pid = ? ORDER BY amendment_date DESC LIMIT 5";
                        $result = sqlStatement($sql, [$pid]);
                        $amendments = [];
                        while ($row = sqlFetchArray($result)) {
                            $amendments[] = $row;
                        }
                        ?>
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fa fa-edit"></i>
                                    <?php echo xlt("Amendments"); ?>
                                    <?php if (!empty($amendments)) : ?>
                                        <span class="badge badge-info"><?php echo count($amendments); ?></span>
                                    <?php endif; ?>
                                </h5>
                                <div class="card-actions">
                                    <a href="<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/list_amendments.php?id=<?php echo attr_url($pid); ?>" 
                                       class="btn-card btn-edit" onclick="top.restoreSession()">
                                        <i class="fa fa-edit"></i> <?php echo xlt("Edit"); ?>
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="card-content" id="amendments_content">
                                    <?php echo $dispatchResult->getPrependedInjection(); ?>
                                    <?php if (empty($amendments)) : ?>
                                        <div class="empty-state">
                                            <i class="fa fa-edit text-muted"></i>
                                            <h6><?php echo xlt("No Amendments"); ?></h6>
                                            <p><?php echo xlt("No amendments recorded"); ?></p>
                                        </div>
                                    <?php else : ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($amendments as $amendment) : ?>
                                                <div class="list-group-item">
                                                    <div class="d-flex justify-content-between align-items-start">
                                                        <div>
                                                            <strong><?php echo text($amendment['amendment_desc']); ?></strong>
                                                            <?php if (!empty($amendment['amendment_reason'])) : ?>
                                                                <br><small class="text-muted"><?php echo text($amendment['amendment_reason']); ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if (!empty($amendment['amendment_date'])) : ?>
                                                            <span class="status-badge status-active">
                                                                <?php echo text(date('M Y', strtotime($amendment['amendment_date']))); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (count($amendments) >= 5) : ?>
                                            <div class="text-center mt-3">
                                                <a href="<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/list_amendments.php?id=<?php echo attr_url($pid); ?>" 
                                                   class="btn btn-outline-primary btn-sm" onclick="top.restoreSession()">
                                                    <?php echo xlt("View All Amendments"); ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php echo $dispatchResult->getAppendedInjection(); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Enhanced Labs Section -->
                    <?php if (AclMain::aclCheckCore('patients', 'lab') && !in_array('card_lab', $hiddenCards)) :
                        $dispatchResult = $ed->dispatch(new CardRenderEvent('lab'), CardRenderEvent::EVENT_HANDLE);
                        
                        // Check if lab data exists
                        $spruch = "SELECT procedure_report.date_collected AS date, procedure_report.procedure_report_id,
                                         procedure_order.procedure_name
                                  FROM procedure_report
                                  JOIN procedure_order ON procedure_report.procedure_order_id = procedure_order.procedure_order_id
                                  WHERE procedure_order.patient_id = ?
                                  ORDER BY procedure_report.date_collected DESC LIMIT 5";
                        $labResults = sqlStatement($spruch, array($pid));
                        $labData = [];
                        while ($row = sqlFetchArray($labResults)) {
                            $labData[] = $row;
                        }
                        $widgetAuth = !empty($labData);
                        ?>
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fa fa-flask"></i>
                                    <?php echo xlt("Laboratory Results"); ?>
                                    <?php if (!empty($labData)) : ?>
                                        <span class="badge badge-info"><?php echo count($labData); ?></span>
                                    <?php endif; ?>
                                </h5>
                                <?php if ($widgetAuth) : ?>
                                    <div class="card-actions">
                                        <a href="../summary/labdata.php" 
                                           class="btn-card btn-edit" onclick="top.restoreSession()">
                                            <i class="fa fa-chart-line"></i> <?php echo xlt("Trend"); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <div class="card-content" id="labs_content">
                                    <?php echo $dispatchResult->getPrependedInjection(); ?>
                                    <?php if (empty($labData)) : ?>
                                        <div class="empty-state">
                                            <i class="fa fa-flask text-muted"></i>
                                            <h6><?php echo xlt("No Lab Results"); ?></h6>
                                            <p><?php echo xlt("No laboratory results available"); ?></p>
                                        </div>
                                    <?php else : ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($labData as $lab) : ?>
                                                <div class="list-group-item">
                                                    <div class="d-flex justify-content-between align-items-start">
                                                        <div>
                                                            <strong><?php echo text($lab['procedure_name']); ?></strong>
                                                            <?php if (!empty($lab['date'])) : ?>
                                                                <br><small class="text-muted"><?php echo xlt("Collected"); ?>: <?php echo text(date('M d, Y', strtotime($lab['date']))); ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if (!empty($lab['date'])) : ?>
                                                            <span class="status-badge status-active">
                                                                <?php echo text(date('M Y', strtotime($lab['date']))); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (count($labData) >= 5) : ?>
                                            <div class="text-center mt-3">
                                                <a href="../summary/labdata.php" 
                                                   class="btn btn-outline-primary btn-sm" onclick="top.restoreSession()">
                                                    <?php echo xlt("View All Lab Results"); ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php echo $dispatchResult->getAppendedInjection(); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Enhanced Vitals Section -->
                    <?php if ($vitals_is_registered && AclMain::aclCheckCore('patients', 'med') && !in_array('card_vitals', $hiddenCards)) :
                        $dispatchResult = $ed->dispatch(new CardRenderEvent('vital_sign'), CardRenderEvent::EVENT_HANDLE);
                        
                        // Get recent vitals data
                        $vitalsQuery = "SELECT * FROM form_vitals WHERE pid = ? ORDER BY date DESC LIMIT 5";
                        $vitalsResult = sqlStatement($vitalsQuery, array($pid));
                        $vitalsData = [];
                        while ($row = sqlFetchArray($vitalsResult)) {
                            $vitalsData[] = $row;
                        }
                        $widgetAuth = !empty($vitalsData);
                        ?>
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fa fa-heartbeat"></i>
                                    <?php echo xlt("Vital Signs"); ?>
                                    <?php if (!empty($vitalsData)) : ?>
                                        <span class="badge badge-success"><?php echo count($vitalsData); ?></span>
                                    <?php endif; ?>
                                </h5>
                                <?php if ($widgetAuth) : ?>
                                    <div class="card-actions">
                                        <a href="../encounter/trend_form.php?formname=vitals&context=dashboard" 
                                           class="btn-card btn-edit" onclick="top.restoreSession()">
                                            <i class="fa fa-chart-line"></i> <?php echo xlt("Trend"); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <div class="card-content" id="vitals_content">
                                    <?php echo $dispatchResult->getPrependedInjection(); ?>
                                    <?php if (empty($vitalsData)) : ?>
                                        <div class="empty-state">
                                            <i class="fa fa-heartbeat text-muted"></i>
                                            <h6><?php echo xlt("No Vital Signs"); ?></h6>
                                            <p><?php echo xlt("No vital signs recorded"); ?></p>
                                        </div>
                                    <?php else : ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($vitalsData as $vital) : ?>
                                                <div class="list-group-item">
                                                    <div class="d-flex justify-content-between align-items-start">
                                                        <div>
                                                            <strong><?php echo xlt("Vital Signs"); ?></strong>
                                                            <?php if (!empty($vital['temperature'])) : ?>
                                                                <br><small class="text-muted"><?php echo xlt("Temp"); ?>: <?php echo text($vital['temperature']); ?>°F</small>
                                                            <?php endif; ?>
                                                            <?php if (!empty($vital['bp_systolic']) && !empty($vital['bp_diastolic'])) : ?>
                                                                <br><small class="text-muted"><?php echo xlt("BP"); ?>: <?php echo text($vital['bp_systolic']); ?>/<?php echo text($vital['bp_diastolic']); ?> mmHg</small>
                                                            <?php endif; ?>
                                                            <?php if (!empty($vital['pulse'])) : ?>
                                                                <br><small class="text-muted"><?php echo xlt("Pulse"); ?>: <?php echo text($vital['pulse']); ?> bpm</small>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if (!empty($vital['date'])) : ?>
                                                            <span class="status-badge status-active">
                                                                <?php echo text(date('M Y', strtotime($vital['date']))); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (count($vitalsData) >= 5) : ?>
                                            <div class="text-center mt-3">
                                                <a href="../encounter/trend_form.php?formname=vitals&context=dashboard" 
                                                   class="btn btn-outline-primary btn-sm" onclick="top.restoreSession()">
                                                    <?php echo xlt("View All Vital Signs"); ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php echo $dispatchResult->getAppendedInjection(); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Enhanced LBF Forms Section -->
                    <?php
                    // This generates a section similar to Vitals for each LBF form that
                    // supports charting.  The form ID is used as the "widget label".
                    $gfres = sqlStatement("SELECT grp_form_id AS option_id, grp_title AS title, grp_aco_spec
                        FROM layout_group_properties
                        WHERE grp_form_id LIKE 'LBF%'
                        AND grp_group_id = ''
                        AND grp_repeats > 0
                        AND grp_activity = 1
                        ORDER BY grp_seq, grp_title");

                    while ($gfrow = sqlFetchArray($gfres)) :
                        $LBF_ACO = empty($gfrow['grp_aco_spec']) ? false : explode('|', $gfrow['grp_aco_spec']);
                        if ($LBF_ACO && !AclMain::aclCheckCore($LBF_ACO[0], $LBF_ACO[1])) {
                            continue;
                        }

                        $widgetAuth = false;
                        if (!$LBF_ACO || AclMain::aclCheckCore($LBF_ACO[0], $LBF_ACO[1], '', 'write')) {
                            $existVitals = sqlQuery("SELECT * FROM forms WHERE pid = ? AND formdir = ? AND deleted = 0", [$pid, $vitals_form_id]);
                            $widgetAuth = $existVitals;
                        }

                        $dispatchResult = $ed->dispatch(new CardRenderEvent($gfrow['title']), CardRenderEvent::EVENT_HANDLE);
                        ?>
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fa fa-clipboard-list"></i>
                                    <?php echo xlt($gfrow['title']); ?>
                                </h5>
                                <?php if ($widgetAuth) : ?>
                                    <div class="card-actions">
                                        <a href="../encounter/trend_form.php?formname=vitals&context=dashboard" 
                                           class="btn-card btn-edit" onclick="top.restoreSession()">
                                            <i class="fa fa-chart-line"></i> <?php echo xlt("Trend"); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <div class="card-content">
                                    <?php echo $dispatchResult->getPrependedInjection(); ?>
                                    <div class="loading">
                                        <i class="fa fa-spinner"></i> <?php echo xlt("Loading form data..."); ?>
                                    </div>
                                    <?php echo $dispatchResult->getAppendedInjection(); ?>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>

                    <!-- if anyone wants to render anything after the patient demographic list -->
                    <?php $GLOBALS["kernel"]->getEventDispatcher()->dispatch(new RenderEvent($pid), RenderEvent::EVENT_SECTION_LIST_RENDER_AFTER, 10); ?>
                </div> <!-- end main content column -->

                <!-- Sidebar Column -->
                <div class="sidebar-content">
                                        <?php
                    $_extAccess = [
                        $GLOBALS['portal_onsite_two_enable'],
                        $GLOBALS['rest_fhir_api'],
                        $GLOBALS['rest_api'],
                        $GLOBALS['rest_portal_api'],
                    ];
                    foreach ($_extAccess as $_) {
                        if ($_) {
                                        $portalCard = new PortalCard($GLOBALS);
                            break;
                        }
                    }

                    $sectionRenderEvents = $ed->dispatch(new SectionEvent('secondary'), SectionEvent::EVENT_HANDLE);
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
                        ?>
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h6 class="card-title">
                                    <i class="fa fa-external-link-alt"></i>
                                    <?php echo text($card->getTitle()); ?>
                                </h6>
                                <?php if ($btnLabel) : ?>
                                    <div class="card-actions">
                                        <a href="#" class="btn-card btn-edit" onclick="top.restoreSession()">
                                            <i class="fa fa-edit"></i>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <div class="card-content">
                                    <?php
                                            $viewArgs = [
                                                'card' => $card,
                                                'title' => $card->getTitle(),
                                                'id' => $card->getIdentifier() . "_expand",
                                                'auth' => $auth,
                                                'linkMethod' => 'html',
                                        'initiallyCollapsed' => $card->isInitiallyCollapsed(),
                                                'card_bg_color' => $card->getBackgroundColorClass(),
                                                'card_text_color' => $card->getTextColorClass(),
                                                'forceAlwaysOpen' => !$card->canCollapse(),
                                                'btnLabel' => $btnLabel,
                                                'btnLink' => "javascript:$('#patient_portal').collapse('toggle')",
                                            ];
                                    echo $t->render($card->getTemplateFile(), array_merge($viewArgs, $card->getTemplateVariables()));
                                    ?>
                                </div>
                            </div>
                        </div>
                    <?php } ?>

                    <!-- Enhanced eRx Section -->
                    <?php if ($GLOBALS['erx_enable']) :
                        $dispatchResult = $ed->dispatch(new CardRenderEvent('demographics'), CardRenderEvent::EVENT_HANDLE);
                        ?>
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h6 class="card-title">
                                    <i class="fa fa-prescription-bottle"></i>
                                    <?php echo xlt("eRx"); ?>
                                </h6>
                                <div class="card-actions">
                                    <a href="<?php echo $GLOBALS['webroot']; ?>/interface/eRx.php?page=compose" 
                                       class="btn-card btn-add" onclick="top.restoreSession()">
                                        <i class="fa fa-plus"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="card-content">
                                    <?php echo $dispatchResult->getPrependedInjection(); ?>
                                    <div class="text-center">
                                        <a href="<?php echo $GLOBALS['webroot']; ?>/interface/eRx.php?page=compose" 
                                           class="btn btn-primary btn-sm" onclick="top.restoreSession()">
                                            <?php echo xlt("New eRx"); ?>
                                        </a>
                                    </div>
                                    <?php echo $dispatchResult->getAppendedInjection(); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Enhanced Photos/ID Card Section -->
                    <?php
                                        $photos = pic_array($pid, $GLOBALS['patient_photo_category_name']);
                    if ($photos or $idcard_doc_id) :
                        $dispatchResult = $ed->dispatch(new CardRenderEvent('patient_photo'), CardRenderEvent::EVENT_HANDLE);
                        ?>
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h6 class="card-title">
                                    <i class="fa fa-id-card"></i>
                                    <?php echo xlt("ID Card / Photos"); ?>
                                </h6>
                                <div class="card-actions">
                                    <a href="#" class="btn-card btn-edit" onclick="top.restoreSession()">
                                        <i class="fa fa-edit"></i>
                                    </a>
                            </div>
                        </div>
                            <div class="card-body">
                                <div class="card-content">
                                    <?php echo $dispatchResult->getPrependedInjection(); ?>
                                    <?php if (!empty($photos)) : ?>
                                        <div class="row">
                                            <?php foreach ($photos as $photo) : ?>
                                                <div class="col-6 mb-2">
                                                    <img src="<?php echo text($photo['url']); ?>" 
                                                         class="img-fluid rounded" 
                                                         alt="<?php echo xlt("Patient Photo"); ?>"
                                                         style="max-height: 100px; object-fit: cover;">
                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($idcard_doc_id) : ?>
                                        <div class="text-center">
                                            <a href="<?php echo $GLOBALS['webroot']; ?>/controller.php?document&retrieve&patient_id=<?php echo attr_url($pid); ?>&document_id=<?php echo attr_url($idcard_doc_id); ?>" 
                                               class="btn btn-outline-primary btn-sm" onclick="top.restoreSession()">
                                                <i class="fa fa-download"></i> <?php echo xlt("View ID Card"); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                    <?php echo $dispatchResult->getAppendedInjection(); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Enhanced Advance Directives Section -->
                    <?php if ($GLOBALS['advance_directives_warning']) :
                        $counterFlag = false;
                        $query = "SELECT id FROM categories WHERE name='Advance Directive'";
                        $myrow2 = sqlQuery($query);
                        $advDirArr = [];
                        if ($myrow2) {
                            $parentId = $myrow2['id'];
                            $query = "SELECT id, name FROM categories WHERE parent=?";
                            $resNew1 = sqlStatement($query, array($parentId));
                            while ($myrows3 = sqlFetchArray($resNew1)) {
                                $categoryId = $myrows3['id'];
                                $nameDoc = $myrows3['name'];
                                $query = "SELECT documents.date, documents.id
                                    FROM documents
                                    INNER JOIN categories_to_documents ON categories_to_documents.document_id=documents.id
                                    WHERE categories_to_documents.category_id=?
                                    AND documents.foreign_id=?
                                    AND documents.deleted = 0
                                    ORDER BY documents.date DESC";
                                $resNew2 = sqlStatement($query, array($categoryId, $pid));
                                $limitCounter = 0;
                                while (($myrows4 = sqlFetchArray($resNew2)) && ($limitCounter == 0)) {
                                    $dateTimeDoc = $myrows4['date'];
                                    $tempParse = explode(" ", $dateTimeDoc);
                                    $dateDoc = $tempParse[0];
                                    $idDoc = $myrows4['id'];
                                    $tmp = [
                                        'pid' => $pid,
                                        'docID' => $idDoc,
                                        'docName' => $nameDoc,
                                        'docDate' => $dateDoc,
                                    ];
                                    $advDirArr[] = $tmp;
                                    $limitCounter = $limitCounter + 1;
                                    $counterFlag = true;
                                }
                            }

                            $dispatchResult = $ed->dispatch(new CardRenderEvent('advance_directive'), CardRenderEvent::EVENT_HANDLE);
                            ?>
                            <div class="dashboard-card">
                                <div class="card-header">
                                    <h6 class="card-title">
                                        <i class="fa fa-file-medical"></i>
                                        <?php echo xlt("Advance Directives"); ?>
                                        <?php if (!empty($advDirArr)) : ?>
                                            <span class="badge badge-success"><?php echo count($advDirArr); ?></span>
                                        <?php endif; ?>
                                    </h6>
                                    <div class="card-actions">
                                        <a href="#" class="btn-card btn-edit" onclick="return advdirconfigure();">
                                            <i class="fa fa-edit"></i>
                                        </a>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="card-content">
                                        <?php echo $dispatchResult->getPrependedInjection(); ?>
                                        <?php if (empty($advDirArr)) : ?>
                                            <div class="empty-state">
                                                <i class="fa fa-file-medical text-muted"></i>
                                                <h6><?php echo xlt("No Advance Directives"); ?></h6>
                                                <p><?php echo xlt("No advance directives recorded"); ?></p>
                                            </div>
                                        <?php else : ?>
                                            <div class="list-group list-group-flush">
                                                <?php foreach ($advDirArr as $advDir) : ?>
                                                    <div class="list-group-item">
                                                        <div class="d-flex justify-content-between align-items-start">
                                                            <div>
                                                                <strong><?php echo text($advDir['docName']); ?></strong>
                                                                <?php if (!empty($advDir['docDate'])) : ?>
                                                                    <br><small class="text-muted"><?php echo xlt("Date"); ?>: <?php echo text($advDir['docDate']); ?></small>
                                                                <?php endif; ?>
                                                            </div>
                                                            <a href="<?php echo $GLOBALS['webroot']; ?>/controller.php?document&retrieve&patient_id=<?php echo attr_url($pid); ?>&document_id=<?php echo attr_url($advDir['docID']); ?>" 
                                                               class="btn btn-outline-primary btn-sm" onclick="top.restoreSession()">
                                                                <i class="fa fa-download"></i>
                                                            </a>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php echo $dispatchResult->getAppendedInjection(); ?>
                                    </div>
                                </div>
                            </div>
                        <?php } ?>
                    <?php endif; ?>
                            'prependedInjection' => $dispatchResult->getPrependedInjection(),
                            'appendedInjection' => $dispatchResult->getAppendedInjection(),
                        ];
                            echo $twig->getTwig()->render('patient/card/adv_dir.html.twig', $viewArgs);
                        }
                    }  // close advanced dir block

                    // Show Clinical Reminders for any user that has rules that are permitted.
                    $clin_rem_check = resolve_rules_sql('', '0', true, '', $_SESSION['authUser']);
                    $cdr = $GLOBALS['enable_cdr'];
                    $cdr_crw = $GLOBALS['enable_cdr_crw'];
                    if (!empty($clin_rem_check) && $cdr && $cdr_crw && AclMain::aclCheckCore('patients', 'alert')) {
                        // clinical summary expand collapse widget
                        $id = "clinical_reminders_ps_expand";
                        $dispatchResult = $ed->dispatch(new CardRenderEvent('clinical_reminders'), CardRenderEvent::EVENT_HANDLE);
                        $viewArgs = [
                            'title' => xl("Clinical Reminders"),
                            'id' => $id,
                            'initiallyCollapsed' => (getUserSetting($id) == 0) ? true : false,
                            'btnLabel' => "Edit",
                            'btnLink' => "../reminder/clinical_reminders.php?patient_id=" . attr_url($pid),
                            'linkMethod' => "html",
                            'auth' => AclMain::aclCheckCore('patients', 'alert', '', 'write'),
                            'prependedInjection' => $dispatchResult->getPrependedInjection(),
                            'appendedInjection' => $dispatchResult->getAppendedInjection(),
                        ];
                        echo $twig->getTwig()->render('patient/card/loader.html.twig', $viewArgs);
                    } // end if crw

                    $displayAppts = false;
                    $displayRecurrAppts = false;
                    $displayPastAppts = false;

                    // Show current and upcoming appointments.
                    // Recurring appointment support and Appointment Display Sets
                    // added to Appointments by Ian Jardine ( epsdky ).
                    if (isset($pid) && !$GLOBALS['disable_calendar'] && AclMain::aclCheckCore('patients', 'appt')) {
                        $displayAppts = true;
                        $current_date2 = date('Y-m-d');
                        $events = array();
                        $apptNum = (int)$GLOBALS['number_of_appts_to_show'];
                        $apptNum2 = ($apptNum != 0) ? abs($apptNum) : 10;

                        $mode1 = !$GLOBALS['appt_display_sets_option'];
                        $colorSet1 = $GLOBALS['appt_display_sets_color_1'];
                        $colorSet2 = $GLOBALS['appt_display_sets_color_2'];
                        $colorSet3 = $GLOBALS['appt_display_sets_color_3'];
                        $colorSet4 = $GLOBALS['appt_display_sets_color_4'];
                        $extraAppts = ($mode1) ? 1 : 6;
                        $extraApptDate = '';

                        $past_appts = [];
                        $recallArr = [];

                        $events = fetchNextXAppts($current_date2, $pid, $apptNum2 + $extraAppts, true);

                        if ($events) {
                            $selectNum = 0;
                            $apptNumber = count($events);
                            //
                            if ($apptNumber <= $apptNum2) {
                                $extraApptDate = '';
                                //
                            } elseif ($mode1 && $apptNumber == $apptNum2 + 1) {
                                $extraApptDate = $events[$apptNumber - 1]['pc_eventDate'];
                                array_pop($events);
                                --$apptNumber;
                                $selectNum = 1;
                                //
                            } elseif ($apptNumber == $apptNum2 + 6) {
                                $extraApptDate = $events[$apptNumber - 1]['pc_eventDate'];
                                array_pop($events);
                                --$apptNumber;
                                $selectNum = 2;
                                //
                            } else { // mode 2 - $apptNum2 < $apptNumber < $apptNum2 + 6
                                $extraApptDate = '';
                                $selectNum = 2;
                                //
                            }

                            $limitApptIndx = $apptNum2 - 1;
                            $limitApptDate = $events[$limitApptIndx]['pc_eventDate'] ?? '';

                            switch ($selectNum) {
                                case 2:
                                    $lastApptIndx = $apptNumber - 1;
                                    $thisNumber = $lastApptIndx - $limitApptIndx;
                                    for ($i = 1; $i <= $thisNumber; ++$i) {
                                        if ($events[$limitApptIndx + $i]['pc_eventDate'] != $limitApptDate) {
                                            $extraApptDate = $events[$limitApptIndx + $i]['pc_eventDate'];
                                            $events = array_slice($events, 0, $limitApptIndx + $i);
                                            break;
                                        }
                                    }
                                // Break in the loop to improve performance
                                case 1:
                                    $firstApptIndx = 0;
                                    for ($i = 1; $i <= $limitApptIndx; ++$i) {
                                        if ($events[$limitApptIndx - $i]['pc_eventDate'] != $limitApptDate) {
                                            $firstApptIndx = $apptNum2 - $i;
                                            break;
                                        }
                                    }
                                // Break in the loop to improve performance
                            }

                            if ($extraApptDate) {
                                if ($extraApptDate != $limitApptDate) {
                                    $apptStyle2 = " style='background-color:" . attr($colorSet3) . ";'";
                                } else {
                                    $apptStyle2 = " style='background-color:" . attr($colorSet4) . ";'";
                                }
                            }
                        }

                        $count = 0;
                        $toggleSet = true;
                        $priorDate = "";
                        $therapyGroupCategories = array();
                        $query = sqlStatement("SELECT pc_catid FROM openemr_postcalendar_categories WHERE pc_cattype = 3 AND pc_active = 1");
                        while ($result = sqlFetchArray($query)) {
                            $therapyGroupCategories[] = $result['pc_catid'];
                        }

                        // Build the UI Loop
                        $appts = [];
                        foreach ($events as $row) {
                            $count++;
                            $dayname = date("D", strtotime($row['pc_eventDate']));
                            $displayMeridiem = ($GLOBALS['time_display_format'] == 0) ? "" : "am";
                            $disphour = substr($row['pc_startTime'], 0, 2) + 0;
                            $dispmin = substr($row['pc_startTime'], 3, 2);
                            if ($disphour >= 12 && $GLOBALS['time_display_format'] == 1) {
                                $displayMeridiem = "pm";
                                if ($disphour > 12) {
                                    $disphour -= 12;
                                }
                            }

                            // Note the translaution occurs here instead of in teh Twig file for some specific concatenation needs
                            $etitle = xl('(Click to edit)');
                            if ($row['pc_hometext'] != "") {
                                $etitle = xl('Comments') . ": " . ($row['pc_hometext']) . "\r\n" . $etitle;
                            }

                            $row['etitle'] = $etitle;

                            if ($extraApptDate && $count > $firstApptIndx) {
                                $apptStyle = $apptStyle2;
                            } else {
                                if ($row['pc_eventDate'] != $priorDate) {
                                    $priorDate = $row['pc_eventDate'];
                                    $toggleSet = !$toggleSet;
                                }

                                $bgColor = ($toggleSet) ? $colorSet2 : $colorSet1;
                            }

                            $row['pc_eventTime'] = sprintf("%02d", $disphour) . ":{$dispmin}";
                            $row['pc_status'] = generate_display_field(array('data_type' => '1', 'list_id' => 'apptstat'), $row['pc_apptstatus']);
                            if ($row['pc_status'] == 'None') {
                                $row['pc_status'] = 'Scheduled';
                            }

                            if (in_array($row['pc_catid'], $therapyGroupCategories)) {
                                $row['groupName'] = getGroup($row['pc_gid'])['group_name'];
                            }

                            $row['uname'] = text($row['ufname'] . " " . $row['ulname']);
                            $row['bgColor'] = $bgColor;
                            $row['dayName'] = $dayname;
                            $row['displayMeridiem'] = $displayMeridiem;
                            $row['jsEvent'] = attr_js(preg_replace("/-/", "", $row['pc_eventDate'])) . ', ' . attr_js($row['pc_eid']);
                            $appts[] = $row;
                        }

                        if ($resNotNull) {
                            // Show Recall if one exists
                            $query = sqlStatement("SELECT * FROM `medex_recalls` WHERE `r_pid` = ?", [(int)$pid]);
                            $recallArr = [];
                            $count2 = 0;
                            while ($result2 = sqlFetchArray($query)) {
                                //tabYourIt('recall', 'main/messages/messages.php?go=' + choice);
                                //parent.left_nav.loadFrame('1', tabNAME, url);
                                $recallArr[] = [
                                    'date' => $result2['r_eventDate'],
                                    'reason' => $result2['r_reason'],
                                ];
                                $count2++;
                            }
                            $id = "recall_ps_expand";
                            $dispatchResult = $ed->dispatch(new CardRenderEvent('recall'), CardRenderEvent::EVENT_HANDLE);
                            echo $twig->getTwig()->render('patient/card/recall.html.twig', [
                                'title' => xl('Recall'),
                                'id' => $id,
                                'initiallyCollapsed' => (getUserSetting($id) == 0) ? true : false,
                                'recalls' => $recallArr,
                                'recallsAvailable' => ($count < 1 && empty($count2)) ? false : true,
                                'prependedInjection' => $dispatchResult->getPrependedInjection(),
                                'appendedInjection' => $dispatchResult->getAppendedInjection(),
                            ]);
                        }
                    } // End of Appointments Widget.

                    /* Widget that shows recurrences for appointments. */
                    $recurr = [];
                    if (isset($pid) && !$GLOBALS['disable_calendar'] && $GLOBALS['appt_recurrences_widget'] && AclMain::aclCheckCore('patients', 'appt')) {
                        $displayRecurrAppts = true;
                        $count = 0;
                        $toggleSet = true;
                        $priorDate = "";

                        //Fetch patient's recurrences. Function returns array with recurrence appointments' category, recurrence pattern (interpreted), and end date.
                        $recurrences = fetchRecurrences($pid);
                        if (!empty($recurrences)) {
                            foreach ($recurrences as $row) {
                                if (!recurrence_is_current($row['pc_endDate'])) {
                                    continue;
                                }

                                if (ends_in_a_week($row['pc_endDate'])) {
                                    $row['close_to_end'] = true;
                                }
                                $recurr[] = $row;
                            }
                        }
                    }
                    /* End of recurrence widget */

                    // Show PAST appointments.
                    // added by Terry Hill to allow reverse sorting of the appointments
                    $direction = '1';
                    if ($GLOBALS['num_past_appointments_to_show'] < 0) {
                        $direction = '2';
                        ($showpast = -1 * $GLOBALS['num_past_appointments_to_show']);
                            } else {
                        $showpast = $GLOBALS['num_past_appointments_to_show'];
                    }

                    if (isset($pid) && !$GLOBALS['disable_calendar'] && $showpast > 0 && AclMain::aclCheckCore('patients', 'appt')) {
                        $displayPastAppts = true;

                        $pastAppts = fetchXPastAppts($pid, $showpast, $direction); // This line added by epsdky

                        $count = 0;

                        foreach ($pastAppts as $row) {
                            $count++;
                            $dayname = date("D", strtotime($row['pc_eventDate']));
                            $displayMeridiem = ($GLOBALS['time_display_format'] == 0) ? "" : "am";
                            $disphour = substr($row['pc_startTime'], 0, 2) + 0;
                            $dispmin = substr($row['pc_startTime'], 3, 2);
                            if ($disphour >= 12) {
                                $displayMeridiem = "pm";
                                if ($disphour > 12 && $GLOBALS['time_display_format'] == 1) {
                                    $disphour -= 12;
                                }
                            }

                            $petitle = xl('(Click to edit)');
                            if ($row['pc_hometext'] != "") {
                                $petitle = xl('Comments') . ": " . ($row['pc_hometext']) . "\r\n" . $petitle;
                            }
                            $row['etitle'] = $petitle;

                            $row['pc_status'] = generate_display_field(array('data_type' => '1', 'list_id' => 'apptstat'), $row['pc_apptstatus']);

                            $row['dayName'] = $dayname;
                            $row['displayMeridiem'] = $displayMeridiem;
                            $row['pc_eventTime'] = sprintf("%02d", $disphour) . ":{$dispmin}";
                            $row['uname'] = text($row['ufname'] . " " . $row['ulname']);
                            $row['jsEvent'] = attr_js(preg_replace("/-/", "", $row['pc_eventDate'])) . ', ' . attr_js($row['pc_eid']);
                            $past_appts[] = $row;
                        }
                    }
                    // END of past appointments

                    // Display the Appt card
                    $id = "appointments_ps_expand";
                    $dispatchResult = $ed->dispatch(new CardRenderEvent('appointment'), CardRenderEvent::EVENT_HANDLE);
                    echo $twig->getTwig()->render('patient/card/appointments.html.twig', [
                        'title' => xl("Appointments"),
                        'id' => $id,
                        'initiallyCollapsed' => (getUserSetting($id) == 0) ? true : false,
                        'btnLabel' => "Add",
                        'btnLink' => "return newEvt()",
                        'linkMethod' => "javascript",
                        'appts' => $appts,
                        'recurrAppts' => $recurr,
                        'pastAppts' => $past_appts,
                        'displayAppts' => $displayAppts,
                        'displayRecurrAppts' => $displayRecurrAppts,
                        'displayPastAppts' => $displayPastAppts,
                        'extraApptDate' => $extraApptDate,
                        'therapyGroupCategories' => $therapyGroupCategories,
                        'auth' => $resNotNull && (AclMain::aclCheckCore('patients', 'appt', '', 'write') || AclMain::aclCheckCore('patients', 'appt', '', 'addonly')),
                        'resNotNull' => $resNotNull,
                        'prependedInjection' => $dispatchResult->getPrependedInjection(),
                        'appendedInjection' => $dispatchResult->getAppendedInjection(),
                    ]);

                    echo "<div id=\"stats_div\"></div>";

                    // TRACK ANYTHING
                    // Determine if track_anything form is in use for this site.
                    $tmp = sqlQuery("SELECT count(*) AS count FROM registry WHERE directory = 'track_anything' AND state = 1");
                    $track_is_registered = $tmp['count'];
                    if ($track_is_registered) {
                        $spruch = "SELECT id FROM forms WHERE pid = ? AND formdir = ?";
                        $existTracks = sqlQuery($spruch, array($pid, "track_anything"));
                        $id = "track_anything_ps_expand";
                        $dispatchResult = $ed->dispatch(new CardRenderEvent('track_anything'), CardRenderEvent::EVENT_HANDLE);
                        echo $twig->getTwig()->render('patient/card/loader.html.twig', [
                            'title' => xl("Tracks"),
                            'id' => $id,
                            'initiallyCollapsed' => (getUserSetting($id) == 0) ? true : false,
                            'btnLink' => "../../forms/track_anything/create.php",
                            'linkMethod' => "html",
                            'prependedInjection' => $dispatchResult->getPrependedInjection(),
                            'appendedInjection' => $dispatchResult->getAppendedInjection(),
                        ]);
                    }  // end track_anything

                    if ($thisauth) :
                        echo $twig->getTwig()->render('patient/partials/delete.html.twig', [
                            'isAdmin' => AclMain::aclCheckCore('admin', 'super'),
                            'allowPatientDelete' => $GLOBALS['allow_pat_delete'],
                            'csrf' => CsrfUtils::collectCsrfToken(),
                            'pid' => $pid
                        ]);
                    endif;
                    ?>
                </div> <!-- end right column div -->
            </div> <!-- end div.main > row:first  -->
        </div> <!-- end main content div -->
    </div><!-- end container div -->
    <?php $oemr_ui->oeBelowContainerDiv(); ?>
    <script>
        // Array of skip conditions for the checkSkipConditions() function.
        var skipArray = [
            <?php echo($condition_str ?? ''); ?>
        ];
        checkSkipConditions();


        var isPost = <?php echo js_escape($showEligibility ?? false); ?>;
        var listId = '#' + <?php echo js_escape($list_id); ?>;
        $(function () {
            $(listId).addClass("active");
            if (isPost === true) {
                $("#eligibility").click();
                $("#eligibility").get(0).scrollIntoView();
            }
            });
        </script>
</body>
<?php $ed->dispatch(new RenderEvent($pid), RenderEvent::EVENT_RENDER_POST_PAGELOAD, 10); ?>
</html>
