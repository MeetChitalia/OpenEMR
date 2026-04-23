<?php
/**
 * Marketplace Dispense Report
 * 
 * This report shows all marketplace dispense transactions from Hoover facilities.
 * It displays the quantity dispensed through the marketplace (not deducted from inventory).
 * 
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    OpenEMR
 * @copyright Copyright (c) 2025 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once "$srcdir/options.inc.php";

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

// Check if user has access to reports
// Allow any authenticated user to access
if (!isset($_SESSION['authUser']) || empty($_SESSION['authUser'])) {
    die(xlt("Access Denied - Please log in"));
}

// Get filter parameters
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // Default to first day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Default to today
$facility_filter = $_GET['facility_filter'] ?? '';
$drug_filter = $_GET['drug_filter'] ?? '';
$user_filter = $_GET['user_filter'] ?? '';

?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(['datetime-picker', 'report-helper']); ?>
    <title><?php echo xlt('Marketplace Dispense Report'); ?></title>
    <style>
        .report-container {
            padding: 20px;
        }
        .filter-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .filter-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        .filter-item {
            flex: 1;
            min-width: 200px;
        }
        .filter-item label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .filter-item input,
        .filter-item select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ced4da;
            border-radius: 4px;
        }
        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        .btn-primary {
            background: #007bff;
            color: white;
        }
        .btn-primary:hover {
            background: #0056b3;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #545b62;
        }
        .report-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
        }
        .report-table th,
        .report-table td {
            padding: 12px;
            text-align: left;
            border: 1px solid #dee2e6;
        }
        .report-table th {
            background: #007bff;
            color: white;
            font-weight: bold;
        }
        .report-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        .report-table tr:hover {
            background: #e9ecef;
        }
        .summary-section {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
        }
        .summary-label {
            font-weight: bold;
        }
        .summary-value {
            color: #007bff;
            font-weight: bold;
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: #6c757d;
            font-size: 16px;
        }
        .export-section {
            margin-top: 20px;
        }
    </style>
</head>
<body class="body_top">
    <div class="report-container">
        <h2><?php echo xlt('Marketplace Dispense Report'); ?></h2>
        <p><?php echo xlt('This report shows all medications dispensed through the marketplace (Hoover facilities). These quantities are not deducted from in-house inventory.'); ?></p>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <h3><?php echo xlt('Filters'); ?></h3>
            <form method="GET" id="filterForm">
                <div class="filter-row">
                    <div class="filter-item">
                        <label><?php echo xlt('Date From'); ?>:</label>
                        <input type="date" name="date_from" value="<?php echo attr($date_from); ?>" required>
                    </div>
                    <div class="filter-item">
                        <label><?php echo xlt('Date To'); ?>:</label>
                        <input type="date" name="date_to" value="<?php echo attr($date_to); ?>" required>
                    </div>
                </div>
                <div class="filter-row">
                    <div class="filter-item">
                        <label><?php echo xlt('Facility'); ?>:</label>
                        <select name="facility_filter">
                            <option value=""><?php echo xlt('All Facilities'); ?></option>
                            <?php
                            // Get all facilities
                            $facility_query = "SELECT id, name FROM facility ORDER BY name";
                            $facility_result = sqlStatement($facility_query);
                            while ($facility_row = sqlFetchArray($facility_result)) {
                                $selected = ($facility_filter == $facility_row['id']) ? 'selected' : '';
                                echo "<option value='" . attr($facility_row['id']) . "' $selected>" . text($facility_row['name']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label><?php echo xlt('User'); ?>:</label>
                        <select name="user_filter">
                            <option value=""><?php echo xlt('All Users'); ?></option>
                            <?php
                            // Get all users who have processed marketplace transactions
                            $user_query = "SELECT DISTINCT u.id, u.username, u.fname, u.lname 
                                          FROM users u 
                                          INNER JOIN pos_transactions pt ON u.username = pt.user_id 
                                          ORDER BY u.fname, u.lname";
                            $user_result = sqlStatement($user_query);
                            while ($user_row = sqlFetchArray($user_result)) {
                                $selected = ($user_filter == $user_row['username']) ? 'selected' : '';
                                $display_name = $user_row['fname'] . ' ' . $user_row['lname'] . ' (' . $user_row['username'] . ')';
                                echo "<option value='" . attr($user_row['username']) . "' $selected>" . text($display_name) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label><?php echo xlt('Drug Name'); ?>:</label>
                        <input type="text" name="drug_filter" value="<?php echo attr($drug_filter); ?>" placeholder="<?php echo xla('Enter drug name'); ?>">
                    </div>
                </div>
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary"><?php echo xlt('Generate Report'); ?></button>
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='marketplace_dispense_report.php'"><?php echo xlt('Reset'); ?></button>
                    <button type="button" class="btn btn-secondary" onclick="exportToCSV()"><?php echo xlt('Export to CSV'); ?></button>
                </div>
            </form>
        </div>

        <?php
        // Build query to get marketplace dispense transactions
        // Marketplace dispense is only for Hoover facility (facility_id = 36)
        $query = "SELECT 
                    pt.id,
                    pt.receipt_number,
                    pt.created_date,
                    pt.user_id,
                    pt.pid,
                    pt.items,
                    CONCAT(p.fname, ' ', p.lname) AS patient_name,
                    f.name AS facility_name
                  FROM pos_transactions pt
                  LEFT JOIN patient_data p ON pt.pid = p.pid
                  LEFT JOIN facility f ON f.id = 36
                  WHERE pt.created_date BETWEEN ? AND ?
                  AND pt.items LIKE '%marketplace_dispense\":true%'";

        $params = array($date_from . ' 00:00:00', $date_to . ' 23:59:59');

        if (!empty($facility_filter)) {
            $query .= " AND f.id = ?";
            $params[] = $facility_filter;
        }

        if (!empty($user_filter)) {
            $query .= " AND pt.user_id = ?";
            $params[] = $user_filter;
        }

        $query .= " ORDER BY pt.created_date DESC";

        $result = sqlStatement($query, $params);

        $total_marketplace_dispense = 0;
        $total_transactions = 0;
        $drug_summary = array();
        $report_data = array();

        // Process results
        while ($row = sqlFetchArray($result)) {
            $items = json_decode($row['items'], true);
            
            foreach ($items as $item) {
                // Check if this item has marketplace dispense
                if (isset($item['marketplace_dispense']) && $item['marketplace_dispense'] === true) {
                    $dispense_qty = isset($item['dispense_quantity']) ? intval($item['dispense_quantity']) : 0;
                    
                    // Apply drug filter if set
                    if (!empty($drug_filter) && stripos($item['name'], $drug_filter) === false) {
                        continue;
                    }
                    
                    if ($dispense_qty > 0) {
                        $report_data[] = array(
                            'transaction_id' => $row['id'],
                            'receipt_number' => $row['receipt_number'],
                            'date' => $row['created_date'],
                            'patient_name' => $row['patient_name'] ?? '',
                            'facility_name' => $row['facility_name'] ?? 'Hoover Location',
                            'drug_name' => $item['name'],
                            'lot_number' => $item['lot'] ?? 'N/A',
                            'dispense_quantity' => $dispense_qty,
                            'administer_quantity' => isset($item['administer_quantity']) ? intval($item['administer_quantity']) : 0,
                            'total_quantity' => isset($item['quantity']) ? intval($item['quantity']) : 0
                        );
                        
                        $total_marketplace_dispense += $dispense_qty;
                        $total_transactions++;
                        
                        // Update drug summary
                        if (!isset($drug_summary[$item['name']])) {
                            $drug_summary[$item['name']] = 0;
                        }
                        $drug_summary[$item['name']] += $dispense_qty;
                    }
                }
            }
        }
        ?>

        <!-- Report Table -->
        <?php if (count($report_data) > 0): ?>
            <table class="report-table" id="reportTable">
                <thead>
                    <tr>
                        <th><?php echo xlt('Date'); ?></th>
                        <th><?php echo xlt('Receipt #'); ?></th>
                        <th><?php echo xlt('Patient'); ?></th>
                        <th><?php echo xlt('Facility'); ?></th>
                        <th><?php echo xlt('Drug Name'); ?></th>
                        <th><?php echo xlt('Lot #'); ?></th>
                        <th><?php echo xlt('Total QTY'); ?></th>
                        <th><?php echo xlt('Marketplace Dispense QTY'); ?></th>
                        <th><?php echo xlt('Administer QTY'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report_data as $data): ?>
                        <tr>
                            <td><?php echo text(date('Y-m-d H:i', strtotime($data['date']))); ?></td>
                            <td><?php echo text($data['receipt_number']); ?></td>
                            <td><?php echo text($data['patient_name']); ?></td>
                            <td><?php echo text($data['facility_name']); ?></td>
                            <td><?php echo text($data['drug_name']); ?></td>
                            <td><?php echo text($data['lot_number']); ?></td>
                            <td><?php echo text($data['total_quantity']); ?></td>
                            <td style="font-weight: bold; color: #007bff;"><?php echo text($data['dispense_quantity']); ?></td>
                            <td><?php echo text($data['administer_quantity']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Summary Section -->
            <div class="summary-section">
                <h3><?php echo xlt('Summary'); ?></h3>
                <div class="summary-row">
                    <span class="summary-label"><?php echo xlt('Total Transactions'); ?>:</span>
                    <span class="summary-value"><?php echo text($total_transactions); ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label"><?php echo xlt('Total Marketplace Dispense Quantity'); ?>:</span>
                    <span class="summary-value"><?php echo text($total_marketplace_dispense); ?></span>
                </div>
                
                <h4 style="margin-top: 20px;"><?php echo xlt('By Drug'); ?></h4>
                <?php foreach ($drug_summary as $drug_name => $qty): ?>
                    <div class="summary-row">
                        <span class="summary-label"><?php echo text($drug_name); ?>:</span>
                        <span class="summary-value"><?php echo text($qty); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="no-data">
                <p><?php echo xlt('No marketplace dispense transactions found for the selected filters.'); ?></p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function exportToCSV() {
            const table = document.getElementById('reportTable');
            if (!table) {
                alert('<?php echo xlt('No data to export'); ?>');
                return;
            }
            
            let csv = [];
            const rows = table.querySelectorAll('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const cols = rows[i].querySelectorAll('td, th');
                let row = [];
                for (let j = 0; j < cols.length; j++) {
                    row.push('"' + cols[j].innerText.replace(/"/g, '""') + '"');
                }
                csv.push(row.join(','));
            }
            
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            link.setAttribute('download', 'marketplace_dispense_report_<?php echo date('Y-m-d'); ?>.csv');
            link.style.visibility = 'hidden';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
</body>
</html>

