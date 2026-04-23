<?php
 /**
 * Modern Patient Dashboard Header
 * Provides a clean, professional interface for patient information
 */

// Ensure we have the patient ID
$pid = $_SESSION['pid'] ?? null;
if (!$pid) {
    echo "<div class='alert alert-danger'>No patient selected</div>";
    return;
}

// Get patient data
$patientData = getPatientData($pid, "*, DATE_FORMAT(DOB,'%Y-%m-%d') as DOB_YMD");
$patientData = ensureArray($patientData);

// Format patient name
$patientName = trim($patientData['fname'] . ' ' . $patientData['mname'] . ' ' . $patientData['lname']);
$patientName = preg_replace('/\s+/', ' ', $patientName); // Remove extra spaces

// Calculate age
$age = '';
if (!empty($patientData['DOB_YMD'])) {
    $dob = new DateTime($patientData['DOB_YMD']);
    $now = new DateTime();
    $age = $now->diff($dob)->y;
}

// Get patient status
$status = '';
if (!empty($patientData['status'])) {
    $status = ucfirst($patientData['status']);
}

// Get current encounter info
$encounter = $_SESSION['encounter'] ?? null;
$encounterInfo = '';
if ($encounter) {
    $encounterData = getEncounterDateByEncounter($encounter);
    $encounterData = ensureArray($encounterData);
    if (!empty($encounterData['date'])) {
        $encounterInfo = date('M j, Y', strtotime($encounterData['date']));
    }
}
?>

<div class="modern-patient-header">
    <div class="header-content">
        <div class="patient-info">
            <div class="patient-name-section">
                <h1 class="patient-name"><?php echo htmlspecialchars($patientName); ?></h1>
                <div class="patient-details">
                    <?php if ($age): ?>
                        <span class="detail-item">
                            <i class="fa fa-birthday-cake"></i>
                            <?php echo $age; ?> years old
                        </span>
                    <?php endif; ?>
                    
                    <?php if (!empty($patientData['DOB_YMD'])): ?>
                        <span class="detail-item">
                            <i class="fa fa-calendar"></i>
                            <?php echo date('M j, Y', strtotime($patientData['DOB_YMD'])); ?>
                        </span>
                    <?php endif; ?>
                    
                    <?php if (!empty($patientData['sex'])): ?>
                        <span class="detail-item">
                            <i class="fa fa-venus-mars"></i>
                            <?php echo ucfirst($patientData['sex']); ?>
                        </span>
                    <?php endif; ?>
                    
                    <?php if (!empty($patientData['ss'])): ?>
                        <span class="detail-item">
                            <i class="fa fa-id-card"></i>
                            <?php echo htmlspecialchars($patientData['ss']); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($encounterInfo): ?>
                <div class="encounter-info">
                    <span class="encounter-label">Current Encounter:</span>
                    <span class="encounter-date"><?php echo $encounterInfo; ?></span>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="header-actions">
            <a href="demographics_full.php?pid=<?php echo attr_url($pid); ?>" class="btn-edit-demographics">
                <i class="fa fa-edit"></i>
                Edit Demographics
            </a>
        </div>
    </div>
</div>

 /**
  * Dash Board Header.
  *
  * @package   OpenEMR
  * @link      http://www.open-emr.org
  * @author    Ranganath Pathak <pathak@scrs1.org>
  * @author    Brady Miller <brady.g.miller@gmail.com>
  * @author    Robert Down <robertdown@live.com>
  * @copyright Copyright (c) 2018 Ranganath Pathak <pathak@scrs1.org>
  * @copyright Copyright (c) 2018-2020 Brady Miller <brady.g.miller@gmail.com>
  * @copyright Copyright (c) 2022 Robert Down <robertdown@live.com>
  * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
  */

require_once("$srcdir/display_help_icon_inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;

$twigContainer = new TwigContainer();
$t = $twigContainer->getTwig();

$viewArgs = [
    'pageHeading' => $oemr_ui->pageHeading(),
    'pid' => $pid,
    'csrf' => CsrfUtils::collectCsrfToken(),
];

echo $t->render('patient/dashboard_header.html.twig', $viewArgs);
