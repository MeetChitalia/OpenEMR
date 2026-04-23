<?php
/**
 * POS Transfer History Page
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Team
 * @copyright Copyright (c) 2024 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

use OpenEMR\Core\Header;
use OpenEMR\Common\Csrf\CsrfUtils;

// Ensure user is logged in
if (!isset($_SESSION['authUserID'])) {
    header("Location: ../login/login.php");
    exit;
}

// Get patient ID from URL
$pid = intval($_GET['pid'] ?? 0);

// Get patient data if PID is provided
$patient_data = null;
if ($pid) {
    $patient_result = sqlQuery("SELECT fname, lname, phone_cell, phone_home, DOB, sex FROM patient_data WHERE pid = ?", array($pid));
    if ($patient_result) {
        $patient_data = $patient_result;
    }
}

// Get transfer history data
$transfers = array();
if ($pid) {
    $transfer_query = "SELECT 
                        th.transfer_id,
                        th.source_patient_name,
                        th.target_patient_name,
                        th.drug_name,
                        th.quantity_transferred,
                        th.transfer_date,
                        th.user_name,
                        th.lot_number
                      FROM pos_transfer_history th
                      WHERE th.source_pid = ? OR th.target_pid = ?
                      ORDER BY th.transfer_date DESC";
    
    $transfer_result = sqlStatement($transfer_query, array($pid, $pid));
    while ($row = sqlFetchArray($transfer_result)) {
        $transfers[] = $row;
    }
}

Header::setupHeader(['common', 'opener']);
?>

<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Transfer History'); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f8f9fa;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .patient-info {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #dee2e6;
        }
        .patient-info h3 {
            margin: 0 0 10px 0;
            color: #495057;
            font-size: 18px;
        }
        .patient-details {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
            font-size: 14px;
            color: #6c757d;
        }
        .patient-details span {
            font-weight: 500;
        }
        .content {
            padding: 20px;
        }
        .table-container {
            overflow-x: auto;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            color: #495057;
            font-weight: 600;
            padding: 15px 12px;
            text-align: left;
            border-bottom: 2px solid #dee2e6;
            font-size: 14px;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #f1f1f1;
            font-size: 14px;
            color: #333;
        }
        tr:hover {
            background-color: #f8f9fa;
        }
        .transfer-type {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        .transfer-out {
            background-color: #fff3cd;
            color: #856404;
        }
        .transfer-in {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: #6c757d;
            font-size: 16px;
        }
        .back-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.3s ease;
        }
        .back-btn:hover {
            background: #5a6268;
        }
        .quantity {
            font-weight: 600;
            color: #007bff;
        }
        .date-time {
            color: #6c757d;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-exchange-alt"></i> <?php echo xlt('Transfer History'); ?></h1>
            <a href="pos_modal.php<?php echo $pid ? "?pid=$pid" : ''; ?>" class="back-btn">
                <i class="fas fa-arrow-left"></i> <?php echo xlt('Back to POS'); ?>
            </a>
        </div>
        
        <?php if ($patient_data): ?>
        <div class="patient-info">
            <h3><?php echo xlt('Patient Information'); ?></h3>
            <div class="patient-details">
                <div><span><?php echo xlt('Name:'); ?></span> <?php echo text($patient_data['fname'] . ' ' . $patient_data['lname']); ?></div>
                <div><span><?php echo xlt('Phone:'); ?></span> <?php echo text($patient_data['phone_cell'] ?: $patient_data['phone_home'] ?: 'N/A'); ?></div>
                <div><span><?php echo xlt('DOB:'); ?></span> <?php echo text(oeFormatShortDate($patient_data['DOB'])); ?></div>
                <div><span><?php echo xlt('Gender:'); ?></span> <?php echo text(ucfirst($patient_data['sex'] ?? 'N/A')); ?></div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="content">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo xlt('Transfer ID'); ?></th>
                            <th><?php echo xlt('Patient Name'); ?></th>
                            <th><?php echo xlt('Product'); ?></th>
                            <th><?php echo xlt('Lot Number'); ?></th>
                            <th><?php echo xlt('Quantity'); ?></th>
                            <th><?php echo xlt('Date & Time'); ?></th>
                            <th><?php echo xlt('User'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transfers)): ?>
                        <tr>
                            <td colspan="7" class="no-data">
                                <i class="fas fa-info-circle" style="margin-right: 8px;"></i>
                                <?php echo xlt('No transfer history found for this patient.'); ?>
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($transfers as $transfer): ?>
                            <tr>
                                <td>
                                    <span style="font-family: monospace; font-size: 12px; color: #6c757d;">
                                        <?php echo text($transfer['transfer_id']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    $isSource = ($transfer['source_patient_name'] === ($patient_data['fname'] . ' ' . $patient_data['lname']));
                                    $patientName = $isSource ? $transfer['target_patient_name'] : $transfer['source_patient_name'];
                                    $transferType = $isSource ? 'transfer-out' : 'transfer-in';
                                    $transferLabel = $isSource ? xlt('To') : xlt('From');
                                    ?>
                                    <div>
                                        <span class="transfer-type <?php echo $transferType; ?>">
                                            <?php echo $transferLabel; ?>
                                        </span>
                                        <span style="margin-left: 8px; font-weight: 500;">
                                            <?php echo text($patientName); ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <span style="font-weight: 500; color: #495057;">
                                        <?php echo text($transfer['drug_name']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-family: monospace; font-size: 12px; color: #6c757d;">
                                        <?php echo text($transfer['lot_number']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="quantity">
                                        <?php echo text($transfer['quantity_transferred']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="date-time">
                                        <?php echo text(oeFormatShortDate($transfer['transfer_date']) . ' ' . date('H:i', strtotime($transfer['transfer_date']))); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-weight: 500; color: #495057;">
                                        <?php echo text($transfer['user_name']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>

