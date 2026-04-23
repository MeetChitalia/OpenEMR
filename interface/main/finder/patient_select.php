<?php

/**
 * Patient selector screen.
 *
 * @package OpenEMR
 * @author Brady Miller <brady.g.miller@gmail.com>
 * @copyright (C) 2017 Brady Miller <brady.g.miller@gmail.com>
 * @link http://www.open-emr.org
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../../globals.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/report_database.inc");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\Events\PatientSelect\PatientSelectFilterEvent;
use OpenEMR\Events\BoundFilter;

if (!empty($_REQUEST)) {
    if (!CsrfUtils::verifyCsrfToken($_REQUEST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }
}

$fstart = isset($_REQUEST['fstart']) ? $_REQUEST['fstart'] : 0;
$popup  = empty($_REQUEST['popup']) ? 0 : 1;
$message = isset($_GET['message']) ? $_GET['message'] : "";
$from_page = isset($_REQUEST['from_page']) ? $_REQUEST['from_page'] : "";

?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader('opener'); ?>
<style>
form {
    padding: 0px;
    margin: 0px;
}

#searchCriteria {
    text-align: center;
    width: 100%;
    font-size: 0.8rem;
    background-color: var(--gray300);
    font-weight: bold;
    padding: 3px;
}

#searchResultsHeader {
    width: 100%;
    background-color: var(--gray);
}

#searchResultsHeader table {
    width: 96%;  /* not 100% because the 'searchResults' table has a scrollbar */
    border-collapse: collapse;
}

#searchResultsHeader th {
    font-size: 0.7rem;
}

#searchResults {
    width: 100%;
    height: 80%;
    overflow: auto;
}

.srName {
    width: 12%;
}

.srGender {
    width: 5%;
}

.srDOB {
    width: 8%;
}

.srID,
.srPID {
    width: 7%;
}

.srNumEnc,
.srNumDays,
.srDateLast,
.srDateNext,
.srPhone,
.srSS {
    width: 11%;
}

.srMisc {
    width: 10%;
}

#searchResults table {
    width: 100%;
    border-collapse: collapse;
    background-color: var(--white);
}

#searchResults tr {
    cursor: pointer;
}

#searchResults td {
    font-size: 0.7rem;
    border-bottom: 1px solid var(--gray200);
}

.billing {
    color: var(--danger);
    font-weight: bold;
}
.highlight {
    background-color: var(--primary);
    color: var(--white);
}
</style>

<?php if ($popup) { ?>
    <?php Header::setupAssets('topdialog'); ?>
<?php } ?>

<script>
<?php if ($popup) {
    require($GLOBALS['srcdir'] . "/restoreSession.php");
} ?>
// This is called when forward or backward paging is done.
//
function submitList(offset) {
 var f = document.forms[0];
 var i = parseInt(f.fstart.value) + offset;
 if (i < 0) i = 0;
 f.fstart.value = i;
 top.restoreSession();
 f.submit();
}

</script>

</head>
<body class="body_top">

<form method='post' action='patient_select.php' name='theform' onsubmit='return top.restoreSession()'>
<input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />

<input type='hidden' name='fstart'  value='<?php echo attr($fstart); ?>' />

<?php
$MAXSHOW = 100; // maximum number of results to display at once

//the maximum number of patient records to display:
$sqllimit = $MAXSHOW;
$given = "*";
$orderby = "lname ASC, fname ASC";

$search_service_code = trim($_POST['search_service_code'] ?? '');
echo "<input type='hidden' name='search_service_code' value='" .
  attr($search_service_code) . "' />\n";

if ($popup) {
    echo "<input type='hidden' name='popup' value='1' />\n";

  // Construct WHERE clause and save search parameters as form fields.
    $sqlBindArray = array();
    $where = "1 = 1";
    $fres = sqlStatement("SELECT * FROM layout_options " .
    "WHERE form_id = 'DEM' AND uor > 0 AND field_id != '' " .
    "ORDER BY group_id, seq");
    while ($frow = sqlFetchArray($fres)) {
        $field_id  = $frow['field_id'];
        if (strpos($field_id, 'em_') === 0) {
            continue;
        }

        $data_type = $frow['data_type'];
        if (!empty($_REQUEST[$field_id])) {
            $value = trim($_REQUEST[$field_id]);
            if ($field_id == 'pid') {
                $where .= " AND " . escape_sql_column_name($field_id, array('patient_data')) . " = ?";
                array_push($sqlBindArray, $value);
            } elseif ($field_id == 'pubpid') {
                $where .= " AND " . escape_sql_column_name($field_id, array('patient_data')) . " LIKE ?";
                array_push($sqlBindArray, $value);
                //for 'date' field
            } elseif ($data_type == 4) {
                $where .= " AND " . escape_sql_column_name($field_id, array('patient_data')) . " LIKE ?";
                array_push($sqlBindArray, DateToYYYYMMDD($value));
            } else {
                $where .= " AND " . escape_sql_column_name($field_id, array('patient_data')) . " LIKE ?";
                array_push($sqlBindArray, $value . "%");
            }

            echo "<input type='hidden' name='" . attr($field_id) .
            "' value='" . attr($value) . "' />\n";
        }
    }

  // If a non-empty service code was given, then restrict to patients who
  // have been provided that service.  Since the code is used in a LIKE
  // clause, % and _ wildcards are supported.
    if ($search_service_code) {
        $where .=
        " AND ( SELECT COUNT(*) FROM billing AS b WHERE " .
        "b.pid = patient_data.pid AND " .
        "b.activity = 1 AND " .
        "b.code_type != 'COPAY' AND " .
        "b.code LIKE ? " .
        ") > 0";
        array_push($sqlBindArray, $search_service_code);
    }

    // Add facility-based filtering
    $facilityFilter = "";
    $selectedFacilityId = isset($_SESSION['facilityId']) ? (int) $_SESSION['facilityId'] : 0;
    if ($selectedFacilityId > 0) {
        $facilityFilter = "(facility_id = ? OR FIND_IN_SET(?, REPLACE(COALESCE(care_team_facility, ''), '|', ',')) > 0)";
        $sqlBindArray = array_merge([$selectedFacilityId, $selectedFacilityId], $sqlBindArray);
    } elseif (!empty($GLOBALS['restrict_user_facility'])) {
        // Get user's accessible facilities
        $userFacilities = array();
        $facilityQuery = "SELECT DISTINCT facility_id FROM users_facility WHERE tablename = 'users' AND table_id = ?";
        $facilityResult = sqlStatement($facilityQuery, array($_SESSION['authUserID']));
        
        while ($facilityRow = sqlFetchArray($facilityResult)) {
            $userFacilities[] = $facilityRow['facility_id'];
        }
        
        // If no facilities found, use user's default facility
        if (empty($userFacilities)) {
            $userQuery = "SELECT facility_id FROM users WHERE id = ?";
            $userResult = sqlQuery($userQuery, array($_SESSION['authUserID']));
            
            if ($userResult) {
                $userData = null;
                if (is_object($userResult) && method_exists($userResult, 'FetchRow')) {
                    $userData = $userResult->FetchRow();
                } elseif (is_array($userResult)) {
                    $userData = $userResult;
                }
                
                if ($userData && !empty($userData['facility_id'])) {
                    $userFacilities[] = $userData['facility_id'];
                }
            }
        }
        
        // Build facility filter
        if (!empty($userFacilities)) {
            $facilityPlaceholders = implode(',', array_fill(0, count($userFacilities), '?'));
            $facilityFilter = "facility_id IN ($facilityPlaceholders)";
            $sqlBindArray = array_merge($userFacilities, $sqlBindArray);
        }
    }
    
    // Custom filtering which enables module developer to filter patients out of search
    $patientSelectFilterEvent = new PatientSelectFilterEvent(new BoundFilter());
    $patientSelectFilterEvent = $GLOBALS["kernel"]->getEventDispatcher()->dispatch(PatientSelectFilterEvent::EVENT_HANDLE, $patientSelectFilterEvent, 10);
    $boundFilter = $patientSelectFilterEvent->getBoundFilter();
    $sqlBindArray = array_merge($boundFilter->getBoundValues(), $sqlBindArray);
    $customWhere = $boundFilter->getFilterClause();

    // Combine all filters
    $allFilters = array_filter(array($facilityFilter, $customWhere, $where));
    if (empty($allFilters)) {
        $where = "1=1"; // No filters, return all
    } else {
        $where = implode(' AND ', $allFilters);
    }

    $sql = "SELECT $given FROM patient_data " .
    "WHERE $where ORDER BY $orderby LIMIT " . escape_limit($fstart) . ", " . escape_limit($sqllimit);

    $rez = sqlStatement($sql, $sqlBindArray);
    $result = array();
    while ($row = sqlFetchArray($rez)) {
        $result[] = $row;
    }

    _set_patient_inc_count($sqllimit, count($result), "$customWhere AND $where", $sqlBindArray);
} elseif ($from_page == "cdr_report") {
  // Collect setting from cdr report
    echo "<input type='hidden' name='from_page' value='" . attr($from_page) . "' />\n";
    $report_id = isset($_REQUEST['report_id']) ? $_REQUEST['report_id'] : 0;
    echo "<input type='hidden' name='report_id' value='" . attr($report_id) . "' />\n";
    $itemized_test_id = isset($_REQUEST['itemized_test_id']) ? $_REQUEST['itemized_test_id'] : 0;
    echo "<input type='hidden' name='itemized_test_id' value='" . attr($itemized_test_id) . "' />\n";
    $numerator_label = isset($_REQUEST['numerator_label']) ? $_REQUEST['numerator_label'] : '';
    echo "<input type='hidden' name='numerator_label' value='" . attr($numerator_label) . "' />\n";
    $pass_id = isset($_REQUEST['pass_id']) ? $_REQUEST['pass_id'] : "all";
    echo "<input type='hidden' name='pass_id' value='" . attr($pass_id) . "' />\n";
    $print_patients = isset($_REQUEST['print_patients']) ? $_REQUEST['print_patients'] : 0;
    echo "<input type='hidden' name='print_patients' value='" . attr($print_patients) . "' />\n";

  // Collect patient listing from cdr report
    if ($print_patients) {
        // collect entire listing for printing
        $result = collectItemizedPatientsCdrReport($report_id, $itemized_test_id, $pass_id, $numerator_label);
        $GLOBALS['PATIENT_INC_COUNT'] = count($result);
        $MAXSHOW = $GLOBALS['PATIENT_INC_COUNT'];
    } else {
        // collect the total listing count
        $GLOBALS['PATIENT_INC_COUNT'] = collectItemizedPatientsCdrReport($report_id, $itemized_test_id, $pass_id, $numerator_label, true);
        // then just collect applicable list for pagination
        $result = collectItemizedPatientsCdrReport($report_id, $itemized_test_id, $pass_id, $numerator_label, false, $sqllimit, $fstart);
    }
} else {
    $patient = $_REQUEST['patient'];
    $findBy  = $_REQUEST['findBy'];
    $searchFields = $_REQUEST['searchFields'];

    echo "<input type='hidden' name='patient' value='" . attr($patient) . "' />\n";
    echo "<input type='hidden' name='findBy'  value='" . attr($findBy) . "' />\n";

    if ($findBy == "Last") {
        $result = getPatientLnames($patient, $given, $orderby, $sqllimit, $fstart);
    } elseif ($findBy == "ID") {
        $result = getPatientId($patient, $given, "id ASC, " . $orderby, $sqllimit, $fstart);
    } elseif ($findBy == "DOB") {
        $result = getPatientDOB(DateToYYYYMMDD($patient), $given, "DOB ASC, " . $orderby, $sqllimit, $fstart);
    } elseif ($findBy == "SSN") {
        $result = getPatientSSN($patient, $given, "ss ASC, " . $orderby, $sqllimit, $fstart);
    } elseif ($findBy == "Phone") {                  //(CHEMED) Search by phone number
        $result = getPatientPhone($patient, $given, $orderby, $sqllimit, $fstart);
    } elseif ($findBy == "Any") {
        $result = getByPatientDemographics($patient, $given, $orderby, $sqllimit, $fstart);
    } elseif ($findBy == "Filter") {
        $result = getByPatientDemographicsFilter(
            $searchFields,
            $patient,
            $given,
            $orderby,
            $sqllimit,
            $fstart,
            $search_service_code
        );
    }
}
?>

</form>

<table class="w-100 border-0" cellpadding='5' cellspacing='0'>
 <tr>
  <td class='text'>
    <?php if ($from_page == "cdr_report") { ?>
   <a href='../../reports/cqm.php?report_id=<?php echo attr_url($report_id); ?>' class='btn btn-secondary' onclick='top.restoreSession()'><span><?php echo xlt("Return To Report Results"); ?></span></a>
    <?php } else { ?>
   <a href="./patient_select_help.php" target=_new onclick='top.restoreSession()'>[<?php echo xlt('Help'); ?>]&nbsp;</a>
    <?php } ?>
  </td>
  <td class='text' align='center'>
<?php if ($message) {
    echo "<span class='text-danger'>" . text($message) . "</span>\n";
} ?>
  </td>
  <td>
    <?php if ($from_page == "cdr_report") { ?>
        <?php echo "<a href='patient_select.php?from_page=cdr_report&pass_id=" . attr_url($pass_id) . "&report_id=" . attr_url($report_id) . "&itemized_test_id=" . attr_url($itemized_test_id) . "&numerator_label=" . attr_url($row['numerator_label'] ?? '') . "&print_patients=1&csrf_token_form=" . attr_url(CsrfUtils::collectCsrfToken()) . "' class='btn btn-primary' onclick='top.restoreSession()'><span>" . xlt("Print Entire Listing") . "</span></a>"; ?>
    <?php } ?> &nbsp;
  </td>
  <td class='text' align='right'>
<?php
// Show start and end row number, and number of rows, with paging links.
//
// $count = $fstart + $GLOBALS['PATIENT_INC_COUNT']; // Why did I do that???
$count = $GLOBALS['PATIENT_INC_COUNT'];
$fend = $fstart + $MAXSHOW;
if ($fend > $count) {
    $fend = $count;
}
?>
<?php if ($fstart) { ?>
   <a href="javascript:submitList(-<?php echo attr(addslashes($MAXSHOW)); ?>)">
    &lt;&lt;
   </a>
   &nbsp;&nbsp;
<?php } ?>
    <?php
    $countStatement =  " - " . $fend . " " . xl('of') . " " . $count;
    echo ($fstart + 1) . text($countStatement);
    ?>
<?php if ($count > $fend) { ?>
   &nbsp;&nbsp;
   <a href="javascript:submitList(<?php echo attr(addslashes($MAXSHOW)); ?>)">
    &gt;&gt;
   </a>
<?php } ?>
  </td>
 </tr>
 <tr>
    <?php if ($from_page == "cdr_report") {
        echo "<td colspan='6' class='text'>";
        echo "<strong>";
        if ($pass_id == "fail") {
             echo xlt("Failed Patients");
        } elseif ($pass_id == "pass") {
             echo xlt("Passed Patients");
        } elseif ($pass_id == "exclude") {
             echo xlt("Excluded Patients");
        } else { // $pass_id == "all"
             echo xlt("All Patients");
        }

        echo "</strong>";
        echo " - ";
        echo collectItemizedRuleDisplayTitle($report_id, $itemized_test_id, $numerator_label);
        echo "</td>";
    } ?>
 </tr>
</table>

<div id="searchResultsHeader" class="head">
<table>
<tr>
<th class="srName"><?php echo xlt('Name'); ?></th>
<th class="srGender"><?php echo xlt('Sex'); ?></th>
<th class="srPhone"><?php echo xlt('Phone'); ?></th>
<th class="srSS"><?php echo xlt('SS'); ?></th>
<th class="srDOB"><?php echo xlt('DOB'); ?></th>
<th class="srID"><?php echo xlt('ID'); ?></th>

<?php if (empty($GLOBALS['patient_search_results_style'])) { ?>
<th class="srPID"><?php echo xlt('PID'); ?></th>
<th class="srNumEnc"><?php echo xlt('[Number Of Encounters]'); ?></th>
<th class="srNumDays"><?php echo xlt('[Days Since Last Encounter]'); ?></th>
<th class="srDateLast"><?php echo xlt('[Date of Last Encounter]'); ?></th>
<th class="srDateNext">
    <?php
    $add_days = 90;
    if (!$popup && preg_match('/^(\d+)\s*(.*)/', ($patient ?? ''), $matches) > 0) {
        $add_days = $matches[1];
        $patient = $matches[2];
    }
    ?>
[<?php echo attr($add_days);?> <?php echo xlt('Days From Last Encounter'); ?>]
</th>

    <?php
} else {
  // Alternate patient search results style; this gets address plus other
  // fields that are mandatory, up to a limit of 5.
    $extracols = array();
    $tres = sqlStatement("SELECT * FROM layout_options " .
    "WHERE form_id = 'DEM' AND ( uor > 1 AND field_id != '' " .
    "OR uor > 0 AND field_id = 'street' ) AND " .
    "field_id NOT LIKE '_name' AND " .
    "field_id NOT LIKE 'phone%' AND " .
    "field_id NOT LIKE 'title' AND " .
    "field_id NOT LIKE 'ss' AND " .
    "field_id NOT LIKE 'DOB' AND " .
    "field_id NOT LIKE 'pubpid' " .
    "ORDER BY group_id, seq LIMIT 5");
    while ($trow = sqlFetchArray($tres)) {
        $extracols[$trow['field_id']] = $trow;
        echo "<th class='srMisc'>" . xlt($trow['title']) . "</th>\n";
    }
}
?>

</tr>
</table>
</div>

<div id="searchResults">

<table>
<tr>
<?php
if ($result) {
    foreach ($result as $iter) {
        echo "<tr class='oneresult' id='" . attr($iter['pid']) . "'>";
        echo  "<td class='srName'>" . text($iter['lname'] . ", " . $iter['fname']) . "</td>\n";
        echo  "<td class='srGender'>" . text(getListItemTitle("sex", $iter['sex'])) . "</td>\n";
        //other phone number display setup for tooltip
        $phone_biz = '';
        if ($iter["phone_biz"] != "") {
            $phone_biz = " [business phone " . $iter["phone_biz"] . "] ";
        }

        $phone_contact = '';
        if ($iter["phone_contact"] != "") {
            $phone_contact = " [contact phone " . $iter["phone_contact"] . "] ";
        }

        $phone_cell = '';
        if ($iter["phone_cell"] != "") {
            $phone_cell = " [cell phone " . $iter["phone_cell"] . "] ";
        }

        $all_other_phones = $phone_biz . $phone_contact . $phone_cell;
        if ($all_other_phones == '') {
            $all_other_phones = xl('No other phone numbers listed');
        }

        //end of phone number display setup, now display the phone number(s)
        echo "<td class='srPhone' title='" . attr($all_other_phones) . "'>" .
            text($iter['phone_home']) . "</td>\n";

        echo "<td class='srSS'>" . text($iter['ss']) . "</td>";
        if ($iter["DOB"] != "0000-00-00 00:00:00") {
            echo "<td class='srDOB'>" . text(oeFormatShortDate($iter['DOB'])) . "</td>";
        } else {
            echo "<td class='srDOB'>&nbsp;</td>";
        }

        echo "<td class='srID'>" . text($iter['pubpid']) . "</td>";

        if (empty($GLOBALS['patient_search_results_style'])) {
            echo "<td class='srPID'>" . text($iter['pid']) . "</td>";

          //setup for display of encounter date info
            $encounter_count = 0;
            $day_diff = '';
            $last_date_seen = '';
            $next_appt_date = '';
            $pid = '';

          // calculate date differences based on date of last encounter with billing entries
            $query = "select max(form_encounter.date) as mydate," .
                  " (to_days(current_date())-to_days(max(form_encounter.date))) as day_diff," .
                  " (max(form_encounter.date) + interval " .
                  escape_limit($add_days) .
                  " day) as next_appt, dayname(max(form_encounter.date) + interval " .
                  escape_limit($add_days) .
                  " day) as next_appt_day from form_encounter " .
                  "join billing on billing.encounter = form_encounter.encounter and " .
                  "billing.pid = form_encounter.pid and billing.activity = 1 and " .
                  "billing.code_type not like 'COPAY' where " .
                  "form_encounter.pid = ?";
            $statement = sqlStatement($query, array($iter["pid"]));
            if ($results = sqlFetchArray($statement)) {
                $last_date_seen = $results['mydate'];
                $day_diff = $results['day_diff'];
                $next_appt_date = xl($results['next_appt_day']) . ', ' . oeFormatShortDate($results['next_appt']);
            }

          // calculate date differences based on date of last encounter regardless of billing
            $query = "select max(form_encounter.date) as mydate," .
                  " (to_days(current_date())-to_days(max(form_encounter.date))) as day_diff," .
                  " (max(form_encounter.date) + interval " .
                  escape_limit($add_days) .
                  " day) as next_appt, dayname(max(form_encounter.date) + interval " .
                  escape_limit($add_days) .
                  " day) as next_appt_day from form_encounter " .
                  " where form_encounter.pid = ?";
            $statement = sqlStatement($query, array($iter["pid"]));
            if ($results = sqlFetchArray($statement)) {
                $last_date_seen = $results['mydate'];
                $day_diff = $results['day_diff'];
                $next_appt_date = xl($results['next_appt_day']) . ', ' . oeFormatShortDate($results['next_appt']);
            }

          //calculate count of encounters by distinct billing dates with cpt4
          //entries
            $query = "select count(distinct date) as encounter_count " .
                   " from billing " .
                   " where code_type not like 'COPAY' and activity = 1 " .
                   " and pid = ?";
            $statement = sqlStatement($query, array($iter["pid"]));
            if ($results = sqlFetchArray($statement)) {
                $encounter_count_billed = $results['encounter_count'];
            }

          // calculate count of encounters, regardless of billing
            $query = "select count(date) as encounter_count " .
                      " from form_encounter where " .
                      " pid = ?";
            $statement = sqlStatement($query, array($iter["pid"]));
            if ($results = sqlFetchArray($statement)) {
                $encounter_count = $results['encounter_count'];
            }

            echo "<td class='srNumEnc'>" . text($encounter_count) . "</td>\n";
            echo "<td class='srNumDay'>" . text($day_diff) . "</td>\n";
            echo "<td class='srDateLast'>" . text(oeFormatShortDate($last_date_seen)) . "</td>\n";
            echo "<td class='srDateNext'>" . text($next_appt_date) . "</td>\n";
        } else { // alternate search results style
            foreach ($extracols as $field_id => $frow) {
                echo "<td class='srMisc'>";
                echo generate_display_field($frow, $iter[$field_id]);

                echo"</td>\n";
            }
        }
    }
}
?>
</table>
</div>  <!-- end searchResults DIV -->

<script>

// jQuery stuff to make the page a little easier to use

$(function () {
    // $("#searchparm").focus();
    $(".oneresult").mouseover(function() { $(this).addClass("highlight"); });
    $(".oneresult").mouseout(function() { $(this).removeClass("highlight"); });
    $(".oneresult").click(function(e) { 
        e.preventDefault();
        e.stopPropagation();
        SelectPatient(this);
        return false;
    });
    // $(".event").dblclick(function() { EditEvent(this); });
    <?php if (isset($print_patients)) { ?>
      var win = top.printLogPrint ? top : opener.top;
      win.printLogPrint(window);
    <?php } ?>
});

var SelectPatient = function (eObj) {

// The layout loads just the demographics frame here, which in turn
// will set the pid and load all the other frames.
    objID = eObj.id;
    var parts = objID.split("~");
    <?php if (!$popup) { ?>
        top.restoreSession();
        // Show patient information instead of redirecting to POS
        console.log('SelectPatient called - PID:', parts[0]);
        showPatientInfo(parts[0]);
    <?php } elseif ($popup) { ?>
        dlgclose("srchDone", parts[0]);
    <?php } ?>

    return false; // Prevent default behavior
}

        // Function to show patient information
        function showPatientInfo(pid) {
            console.log('Showing patient info for PID:', pid);
            
            try {
                // Create modal overlay for patient information
                var modalHtml = `
                    <div id="patientInfoModal" style="display: block; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
                        <div class="modal-content" style="background-color: white; margin: 5% auto; padding: 0; border-radius: 8px; width: 80%; max-width: 800px; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
                            <div class="modal-header" style="background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0;">
                                <h3 style="margin: 0; font-size: 18px;">
                                    <i class="fas fa-user"></i> Patient Information
                                </h3>
                                <span class="close" onclick="closePatientInfoModal()" style="color: white; float: right; font-size: 28px; font-weight: bold; cursor: pointer; line-height: 1;">&times;</span>
                            </div>
                            <div class="modal-body" style="padding: 20px;">
                                <div id="patientInfoContent" style="text-align: center; padding: 40px;">
                                    <i class="fas fa-spinner fa-spin" style="font-size: 24px; color: #007bff;"></i>
                                    <p style="margin-top: 10px; color: #6c757d;">Loading patient information...</p>
                                </div>
                            </div>
                            <div class="modal-footer" style="padding: 20px; border-top: 1px solid #dee2e6; text-align: right;">
                                <button onclick="openPOSForPatient(${pid})" style="background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; margin-right: 10px; transition: all 0.3s ease;">
                                    <i class="fas fa-cash-register"></i> Open POS
                                </button>
                                <button onclick="closePatientInfoModal()" style="background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; transition: all 0.3s ease;">
                                    <i class="fas fa-times"></i> Close
                                </button>
                            </div>
                        </div>
                    </div>
                `;
                
                // Add modal to page
                document.body.insertAdjacentHTML('beforeend', modalHtml);
                
                // Load patient information via AJAX
                loadPatientInfo(pid);
                
            } catch (error) {
                console.error('Error showing patient info:', error);
                alert('Error loading patient information. Please try again.');
            }
        }

        // Function to load patient information via AJAX
        function loadPatientInfo(pid) {
            // Get CSRF token from the page
            var csrfToken = '<?php echo CsrfUtils::collectCsrfToken(); ?>';
            
            fetch('patient_info_ajax.php?pid=' + encodeURIComponent(pid) + '&csrf_token=' + encodeURIComponent(csrfToken))
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('patientInfoContent').innerHTML = data.html;
                    } else {
                        document.getElementById('patientInfoContent').innerHTML = `
                            <div style="color: #dc3545; text-align: center;">
                                <i class="fas fa-exclamation-triangle" style="font-size: 24px; margin-bottom: 10px;"></i>
                                <p>Error loading patient information: ${data.error || 'Unknown error'}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading patient info:', error);
                    document.getElementById('patientInfoContent').innerHTML = `
                        <div style="color: #dc3545; text-align: center;">
                            <i class="fas fa-exclamation-triangle" style="font-size: 24px; margin-bottom: 10px;"></i>
                            <p>Error loading patient information. Please try again.</p>
                        </div>
                    `;
                });
        }

        // Function to close patient info modal
        function closePatientInfoModal() {
            const modal = document.getElementById('patientInfoModal');
            if (modal) {
                modal.remove();
            }
        }

        // Function to open POS for specific patient
        function openPOSForPatient(pid) {
            console.log('Opening POS for patient PID:', pid);
            
            try {
                // Use absolute webroot so staging/subdirectory paths do not break
                var posUrl = <?php echo js_url($GLOBALS['webroot']); ?> + '/interface/pos/pos_modal.php?pid=' + encodeURIComponent(pid);
                
                // Navigate to the POS page
                window.location.href = posUrl;
                
                console.log('Navigating to POS page:', posUrl);
                
            } catch (error) {
                console.error('Error navigating to POS:', error);
                alert('Error navigating to POS system. Please try again.');
            }
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('patientInfoModal');
            if (event.target === modal) {
                closePatientInfoModal();
            }
        });

        

</script>

</body>
</html>
