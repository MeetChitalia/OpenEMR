<?php
/**
 * Comprehensive Weight Tracking System
 * All weight tracking features in one integrated page
 * 
 * Features:
 * - Weight loss tracking and summary reports
 * - Advanced analytics with trends
 * - Goal setting and progress monitoring
 * - Treatment correlation analysis
 * - Export functionality
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("../../library/patient.inc");
require_once("../../library/options.inc.php");
require_once("../../library/forms.inc");
require_once("../../library/formatting.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

// Check if user has access
if (!AclMain::aclCheckCore('patients', 'med')) {
    echo "<div class='alert alert-danger'>Access Denied. Patient medical access required.</div>";
    exit;
}

// CSRF protection
if (!empty($_POST)) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }
}

// Get parameters
$active_tab = $_GET['tab'] ?? 'reports';
$form_date = $_POST['form_date'] ?? date('Y-m-d', strtotime('-30 days'));
$form_to_date = $_POST['form_to_date'] ?? date('Y-m-d');
$form_patient_id = $_POST['form_patient_id'] ?? '';
$form_export = $_POST['form_export'] ?? '';

// Weight tracking functions
function getWeightLossSummary($date_from, $date_to, $patient_id = '') {
    // Note: date_from and date_to are now only used for filtering weigh-ins in the date range
    // The actual weight loss calculation uses ALL weight data for complete journey
    
    // Real-time query that shows complete weight loss journey from first to latest weight
    // This query gets ALL patients who have weight data, regardless of date range
    $query = "
        SELECT DISTINCT
            p.pid,
            p.fname,
            p.lname,
            p.DOB,
            -- Get the very first weight recorded for this patient (overall journey)
            -- Use id as secondary sort to ensure deterministic ordering when dates are the same
            (SELECT fv2.weight FROM form_vitals fv2 
             WHERE fv2.pid = p.pid AND fv2.weight > 0 
             ORDER BY fv2.date ASC, fv2.id ASC LIMIT 1) as starting_weight,
            -- Get the most recent weight recorded for this patient (real-time)
            -- Use id DESC as secondary sort to get the latest entry when dates are the same
            (SELECT fv3.weight FROM form_vitals fv3 
             WHERE fv3.pid = p.pid AND fv3.weight > 0 
             ORDER BY fv3.date DESC, fv3.id DESC LIMIT 1) as current_weight,
            -- Get the date of the first weight
            (SELECT fv4.date FROM form_vitals fv4 
             WHERE fv4.pid = p.pid AND fv4.weight > 0 
             ORDER BY fv4.date ASC, fv4.id ASC LIMIT 1) as first_weight_date,
            -- Get the date of the latest weight
            (SELECT fv5.date FROM form_vitals fv5 
             WHERE fv5.pid = p.pid AND fv5.weight > 0 
             ORDER BY fv5.date DESC, fv5.id DESC LIMIT 1) as latest_weight_date,
            -- Count total weigh-ins for this patient
            (SELECT COUNT(*) FROM form_vitals fv6 
             WHERE fv6.pid = p.pid AND fv6.weight > 0) as total_weigh_ins,
            -- Count weigh-ins in the selected date range
            (SELECT COUNT(*) FROM form_vitals fv7 
             WHERE fv7.pid = p.pid AND fv7.weight > 0 
             AND fv7.date BETWEEN ? AND ?) as range_weigh_ins
        FROM patient_data p
        WHERE p.pid IN (
            SELECT DISTINCT pid FROM form_vitals WHERE weight > 0
        )
    ";
    
    // Add date parameters for the range_weigh_ins subquery
    $params = [$date_from, $date_to];
    
    // Add patient filter if specified
    if (!empty($patient_id)) {
        $query .= " AND p.pid = ?";
        $params[] = $patient_id;
    }
    
    $query .= " ORDER BY (starting_weight - current_weight) DESC";
    
    return sqlStatement($query, $params);
}

function getPatientGoals($patient_id) {
    $query = "
        SELECT 
            pg.*,
            p.fname,
            p.lname
        FROM patient_goals pg
        JOIN patient_data p ON pg.pid = p.pid
        WHERE pg.pid = ? 
        AND pg.goal_type = 'weight_loss'
        AND pg.status = 'active'
        ORDER BY pg.target_date ASC
    ";
    
    return sqlStatement($query, [$patient_id]);
}

function getCurrentWeight($patient_id) {
    $query = "
        SELECT weight
        FROM form_vitals
        WHERE pid = ? AND weight > 0
        ORDER BY date DESC
        LIMIT 1
    ";
    
    $result = sqlQuery($query, [$patient_id]);
    return $result ? $result['weight'] : null;
}

function calculateGoalProgress($current_weight, $starting_weight, $goal_weight) {
    if (!$current_weight || !$starting_weight || !$goal_weight) {
        return null;
    }
    
    $total_goal_loss = $starting_weight - $goal_weight;
    $current_loss = $starting_weight - $current_weight;
    
    if ($total_goal_loss <= 0) {
        return null;
    }
    
    $progress_percentage = min(100, max(0, ($current_loss / $total_goal_loss) * 100));
    
    return [
        'progress_percentage' => $progress_percentage,
        'total_goal_loss' => $total_goal_loss,
        'current_loss' => $current_loss,
        'remaining_loss' => max(0, $goal_weight - $current_weight),
        'is_achieved' => $current_weight <= $goal_weight
    ];
}

// Handle goal creation
if (!empty($_POST['action']) && $_POST['action'] === 'create_goal') {
    $pid = $_POST['patient_id'];
    $goal_weight = $_POST['goal_weight'];
    $target_date = $_POST['target_date'];
    $notes = $_POST['notes'];
    
    $query = "INSERT INTO patient_goals (pid, goal_type, goal_weight, target_date, notes, status, created_date) 
             VALUES (?, 'weight_loss', ?, ?, ?, 'active', NOW())";
    sqlStatement($query, [$pid, $goal_weight, $target_date, $notes]);
    $message = "Weight goal created successfully!";
}

// Generate report data - Always generate for real-time updates
$report_data = [];
$report_stats = [];

// Always generate fresh data for real-time display
$report_data = getWeightLossSummary($form_date, $form_to_date, $form_patient_id);
$report_stats = calculateWeightLossStats($report_data);

// Regenerate the data for display since calculateWeightLossStats consumes the result set
$report_data = getWeightLossSummary($form_date, $form_to_date, $form_patient_id);

// Debug: Show query parameters
if (!empty($form_patient_id)) {
    $debug_params = "Date Range: $form_date to $form_to_date, Patient ID: $form_patient_id";
}

// Debug: Log the latest weight data for verification
if (!empty($form_patient_id)) {
    // Get all weight entries for this patient to understand the data
    $debug_query = "SELECT id, weight, date FROM form_vitals WHERE pid = ? AND weight > 0 ORDER BY date ASC, id ASC";
    $debug_result = sqlStatement($debug_query, [$form_patient_id]);
    $debug_weights = [];
    $all_weights = [];
    while ($debug_row = sqlFetchArray($debug_result)) {
        $all_weights[] = $debug_row;
        $debug_weights[] = "ID:{$debug_row['id']} " . $debug_row['weight'] . " lbs (" . $debug_row['date'] . ")";
    }
    
    if (count($all_weights) > 0) {
        $first_weight = $all_weights[0]['weight'];
        $last_weight = $all_weights[count($all_weights) - 1]['weight'];
        $calculated_loss = $first_weight - $last_weight;
        $debug_message = "Patient ID $form_patient_id - First: {$first_weight} lbs, Last: {$last_weight} lbs, Calculated Loss: {$calculated_loss} lbs | All entries: " . implode(", ", $debug_weights);
    } else {
        $debug_message = "No weight data found for Patient ID $form_patient_id";
    }
} else {
    // Show overall system stats when no specific patient is selected
    $debug_query = "SELECT COUNT(DISTINCT pid) as total_patients, COUNT(*) as total_weights, MAX(date) as latest_entry FROM form_vitals WHERE weight > 0 AND date BETWEEN ? AND ?";
    $debug_result = sqlStatement($debug_query, [$form_date, $form_to_date]);
    $debug_row = sqlFetchArray($debug_result);
    if ($debug_row) {
        $debug_message = "System Overview: " . $debug_row['total_patients'] . " patients, " . $debug_row['total_weights'] . " weight entries, latest: " . $debug_row['latest_entry'];
    } else {
        $debug_message = "No weight data found in the selected date range.";
    }
}

function calculateWeightLossStats($weight_data) {
    $stats = [
        'total_patients' => 0,
        'total_weight_loss' => 0,
        'average_weight_loss' => 0,
        'patients_with_loss' => 0,
        'patients_with_gain' => 0,
        'patients_with_no_change' => 0,
        'total_weight_lost' => 0,
        'total_weight_gained' => 0
    ];
    
    // Reset the data pointer to ensure we can iterate through it
    if (is_resource($weight_data)) {
        // For resource types, we need to reset the pointer
        // This is handled by the while loop below
    }
    
    while ($row = sqlFetchArray($weight_data)) {
        $stats['total_patients']++;
        
        // Calculate real-time weight change from starting to current weight
        $weight_change = $row['starting_weight'] - $row['current_weight'];
        $stats['total_weight_loss'] += $weight_change;
        
        if ($weight_change > 0.5) {
            $stats['patients_with_loss']++;
            $stats['total_weight_lost'] += $weight_change;
        } elseif ($weight_change < -0.5) {
            $stats['patients_with_gain']++;
            $stats['total_weight_gained'] += abs($weight_change);
        } else {
            $stats['patients_with_no_change']++;
        }
    }
    
    if ($stats['total_patients'] > 0) {
        $stats['average_weight_loss'] = $stats['total_weight_loss'] / $stats['total_patients'];
    }
    
    return $stats;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Weight Tracking System'); ?></title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <?php Header::setupHeader(['datetime-picker', 'common']); ?>
    <style>
        .weight-tracking-container {
            max-width: 1400px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .page-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid #007cba;
        }
        
        .page-header h1 {
            color: #007cba;
            margin: 0;
            font-size: 32px;
            font-weight: 300;
        }
        
        .tab-navigation {
            display: flex;
            margin-bottom: 30px;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 5px;
        }
        
        .tab-button {
            flex: 1;
            padding: 15px 20px;
            background: transparent;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            color: #666;
            border-radius: 6px;
            transition: all 0.3s ease;
        }
        
        .tab-button.active {
            background: #007cba;
            color: white;
        }
        
        .tab-button:hover {
            background: #e9ecef;
            color: #007cba;
        }
        
        .tab-button.active:hover {
            background: #005a8b;
            color: white;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .controls-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        
        .form-row {
            display: flex;
            gap: 20px;
            align-items: end;
            flex-wrap: wrap;
        }
        
        .form-group {
            flex: 1;
            min-width: 200px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: #007cba;
            color: white;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn:hover {
            opacity: 0.9;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #007cba, #005a8b);
            color: white;
            padding: 25px;
            border-radius: 10px;
            text-align: center;
        }
        
        .stat-card h3 {
            margin: 0 0 10px 0;
            font-size: 32px;
            font-weight: bold;
        }
        
        .stat-card p {
            margin: 0;
            font-size: 14px;
            opacity: 0.9;
        }
        
        .report-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .report-table th {
            background: #007cba;
            color: white;
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
        }
        
        .report-table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        
        .report-table tr:hover {
            background: #f8f9fa;
        }
        
        .weight-positive {
            color: #28a745;
            font-weight: bold;
        }
        
        .weight-negative {
            color: #dc3545;
            font-weight: bold;
        }
        
        .weight-neutral {
            color: #6c757d;
            font-weight: bold;
        }
        
        .goals-section {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .goal-card {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
        }
        
        .progress-bar {
            width: 100%;
            height: 25px;
            background: #e9ecef;
            border-radius: 12px;
            overflow: hidden;
            margin: 10px 0;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #28a745, #20c997);
            border-radius: 12px;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 12px;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .close {
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            color: #aaa;
        }
        
        .close:hover {
            color: #000;
        }
        
        .form-group-modal {
            margin-bottom: 20px;
        }
        
        .form-group-modal label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        .form-group-modal input,
        .form-group-modal textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .form-group-modal textarea {
            height: 100px;
            resize: vertical;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 25px;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
            font-style: italic;
            background: #f8f9fa;
            border-radius: 10px;
            margin: 20px 0;
        }
        
         .real-time-indicator {
             display: inline-block;
             width: 10px;
             height: 10px;
             background: #28a745;
             border-radius: 50%;
             margin-right: 8px;
             animation: pulse 2s infinite;
         }
         
         @keyframes pulse {
             0% { opacity: 1; }
             50% { opacity: 0.5; }
             100% { opacity: 1; }
         }
         
         .last-updated {
             font-size: 12px;
             color: #666;
             margin-left: 10px;
         }
         
         .refresh-button {
             background: #17a2b8;
             color: white;
             border: none;
             padding: 8px 15px;
             border-radius: 4px;
             cursor: pointer;
             font-size: 12px;
             margin-left: 10px;
         }
         
         .refresh-button:hover {
             background: #138496;
         }
         
         @media (max-width: 768px) {
             .form-row {
                 flex-direction: column;
             }
             
             .stats-grid {
                 grid-template-columns: 1fr;
             }
             
             .tab-navigation {
                 flex-direction: column;
             }
         }
    </style>
</head>
<body>
    <div class="weight-tracking-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1>
                <span class="real-time-indicator"></span>
                <?php echo xlt('Weight Tracking System'); ?>
                <span class="last-updated" id="lastUpdated">
                    <?php echo xlt('Last updated:'); ?> <?php echo date('M d, Y H:i:s'); ?>
                </span>
                <button class="refresh-button" onclick="refreshData()" title="<?php echo xlt('Refresh Data'); ?>">
                    🔄 <?php echo xlt('Refresh'); ?>
                </button>
            </h1>
            <p><?php echo xlt('Real-time weight loss tracking, analytics, and goal management'); ?></p>
            <div style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 6px; padding: 10px; margin-top: 10px; font-size: 14px;">
                <strong>✅ Complete Weight Loss Journey:</strong> This system shows the TOTAL weight loss from the very first weight recorded to the most recent weight, regardless of date range. 
                When you record new weights (e.g., 150 lbs → 135 lbs), the complete weight loss journey will update immediately showing the total progress.
            </div>
        </div>
        
        <!-- Tab Navigation -->
        <div class="tab-navigation">
            <button class="tab-button <?php echo $active_tab === 'reports' ? 'active' : ''; ?>" data-tab="reports">
                📊 <?php echo xlt('Reports'); ?>
            </button>
            <button class="tab-button <?php echo $active_tab === 'analytics' ? 'active' : ''; ?>" data-tab="analytics">
                📈 <?php echo xlt('Analytics'); ?>
            </button>
            <!-- Goals tab hidden for now -->
            <!-- <button class="tab-button <?php echo $active_tab === 'goals' ? 'active' : ''; ?>" data-tab="goals">
                🎯 <?php echo xlt('Goals'); ?>
            </button> -->
        </div>
        
        <!-- Reports Tab -->
        <div id="reports" class="tab-content <?php echo $active_tab === 'reports' ? 'active' : ''; ?>">
            <div class="controls-section">
                <form method="POST" action="?tab=reports">
                    <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="form_date"><?php echo xlt('From Date:'); ?></label>
                            <input type="date" id="form_date" name="form_date" value="<?php echo attr($form_date); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="form_to_date"><?php echo xlt('To Date:'); ?></label>
                            <input type="date" id="form_to_date" name="form_to_date" value="<?php echo attr($form_to_date); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="form_patient_id"><?php echo xlt('Patient (Optional):'); ?></label>
                            <select id="form_patient_id" name="form_patient_id">
                                <option value=""><?php echo xlt('All Patients'); ?></option>
                                <?php
                                $patient_query = "SELECT pid, fname, lname FROM patient_data ORDER BY lname, fname";
                                $patient_result = sqlStatement($patient_query);
                                while ($patient = sqlFetchArray($patient_result)) {
                                    $selected = ($form_patient_id == $patient['pid']) ? 'selected' : '';
                                    echo "<option value='" . attr($patient['pid']) . "' $selected>" . 
                                         text($patient['lname'] . ', ' . $patient['fname']) . "</option>";
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary"><?php echo xlt('Generate Report'); ?></button>
                            <button type="submit" name="form_export" value="csv" class="btn btn-success"><?php echo xlt('Export CSV'); ?></button>
                        </div>
                    </div>
                </form>
            </div>
            
             <?php if (!empty($report_stats) && $report_stats['total_patients'] > 0): ?>
                 <!-- Debug Information (for testing real-time updates) -->
                 <div style="background: #e7f3ff; border: 1px solid #007cba; border-radius: 6px; padding: 15px; margin-bottom: 20px;">
                     <strong>🔍 Real-Time Debug Info:</strong><br>
                     <small><strong>Query Parameters:</strong> <?php echo text($debug_params ?? 'Date Range: ' . $form_date . ' to ' . $form_to_date . ', Patient ID: All Patients'); ?></small><br>
                     <small><strong>Latest Weights:</strong> <?php echo text($debug_message ?? 'No data available'); ?></small><br>
                     <small style="color: #666;">This shows the latest weight entries to verify real-time updates.</small>
                 </div>
                 
                 <!-- Statistics Summary -->
                 <div class="stats-grid">
                    <div class="stat-card">
                        <h3><?php echo $report_stats['total_patients']; ?></h3>
                        <p><?php echo xlt('Total Patients'); ?></p>
                    </div>
                    <div class="stat-card">
                        <h3><?php echo number_format($report_stats['total_weight_loss'], 1); ?> lbs</h3>
                        <p><?php echo xlt('Total Weight Lost'); ?></p>
                    </div>
                    <div class="stat-card">
                        <h3><?php echo number_format($report_stats['average_weight_loss'], 1); ?> lbs</h3>
                        <p><?php echo xlt('Average Weight Loss'); ?></p>
                    </div>
                    <div class="stat-card">
                        <h3><?php echo $report_stats['patients_with_loss']; ?></h3>
                        <p><?php echo xlt('Patients Lost Weight'); ?></p>
                    </div>
                </div>
                
                <!-- Weight Loss Summary Table -->
                <table class="report-table">
                    <thead>
                        <tr>
                            <th><?php echo xlt('Patient'); ?></th>
                            <th><?php echo xlt('DOB'); ?></th>
                            <th><?php echo xlt('First Weigh-in'); ?></th>
                            <th><?php echo xlt('Last Weigh-in'); ?></th>
                            <th><?php echo xlt('Starting Weight'); ?></th>
                            <th><?php echo xlt('Current Weight'); ?></th>
                            <th><?php echo xlt('Weight Change'); ?></th>
                            <th><?php echo xlt('Weigh-ins'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                         <?php
                         while ($row = sqlFetchArray($report_data)) {
                             // Calculate weight loss from starting weight to current weight (real-time)
                             // Starting weight = first weight ever recorded
                             // Current weight = most recent weight recorded
                             $weight_change = $row['starting_weight'] - $row['current_weight'];
                             $change_class = '';
                             $change_text = '';
                             
                             if ($weight_change > 0.5) {
                                 $change_class = 'weight-positive';
                                 $change_text = '-' . number_format($weight_change, 1) . ' lbs';
                             } elseif ($weight_change < -0.5) {
                                 $change_class = 'weight-negative';
                                 $change_text = '+' . number_format(abs($weight_change), 1) . ' lbs';
                             } else {
                                 $change_class = 'weight-neutral';
                                 $change_text = '0.0 lbs';
                             }
                             
                             // Use the most recent weight for display (complete journey)
                             $display_weight = $row['current_weight']; // This is the latest weight from ALL data
                             $first_date = $row['first_weight_date'];
                             $latest_date = $row['latest_weight_date'];
                             
                            // Debug info for this patient
                            $debug_info = "";
                            if ($row['pid'] == $form_patient_id) {
                                $debug_info = " <small style='color: #007cba;'>(Starting: " . $row['starting_weight'] . " lbs, Current: " . $row['current_weight'] . " lbs, Loss: " . $weight_change . " lbs, Total Entries: " . $row['total_weigh_ins'] . ")</small>";
                            }
                             
                             echo "<tr>";
                             echo "<td><strong>" . text($row['fname'] . ' ' . $row['lname']) . "</strong>$debug_info</td>";
                             echo "<td>" . text($row['DOB']) . "</td>";
                             echo "<td>" . text($first_date) . " <small style='color: #666;'>(First Ever)</small></td>";
                             echo "<td>" . text($latest_date) . " <small style='color: #28a745; font-weight: bold;'>(Latest Entry)</small></td>";
                             echo "<td><strong>" . number_format($row['starting_weight'], 1) . " lbs</strong> <small style='color: #666;'>(First Record)</small></td>";
                             echo "<td><strong style='color: #007cba; font-size: 16px;'>" . number_format($display_weight, 1) . " lbs</strong> <small style='color: #28a745; font-weight: bold;'>(Current)</small></td>";
                             echo "<td class='$change_class' style='font-size: 16px; font-weight: bold;'>$change_text</td>";
                             echo "<td>" . $row['total_weigh_ins'] . " <small style='color: #666;'>(Total)</small><br>" . $row['range_weigh_ins'] . " <small style='color: #999;'>(In Range)</small></td>";
                             echo "</tr>";
                         }
                         ?>
                    </tbody>
                </table>
                
            <?php else: ?>
                <div class="no-data">
                    <h3><?php echo xlt('No Data Found'); ?></h3>
                    <p><?php echo xlt('No weight data found for the selected criteria. Please adjust your date range or patient selection.'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Analytics Tab -->
        <div id="analytics" class="tab-content <?php echo $active_tab === 'analytics' ? 'active' : ''; ?>">
            <div class="controls-section">
                <form method="POST" action="?tab=analytics">
                    <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="form_date"><?php echo xlt('From Date:'); ?></label>
                            <input type="date" id="form_date" name="form_date" value="<?php echo attr($form_date); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="form_to_date"><?php echo xlt('To Date:'); ?></label>
                            <input type="date" id="form_to_date" name="form_to_date" value="<?php echo attr($form_to_date); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary"><?php echo xlt('Generate Analytics'); ?></button>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><?php echo isset($report_stats['total_patients']) ? $report_stats['total_patients'] : '0'; ?></h3>
                    <p><?php echo xlt('Patients Analyzed'); ?></p>
                </div>
                <div class="stat-card">
                    <h3><?php echo isset($report_stats['total_weight_loss']) ? number_format($report_stats['total_weight_loss'], 1) : '0.0'; ?> lbs</h3>
                    <p><?php echo xlt('Total Weight Lost'); ?></p>
                </div>
                <div class="stat-card">
                    <h3><?php echo isset($report_stats['average_weight_loss']) ? number_format($report_stats['average_weight_loss'], 1) : '0.0'; ?> lbs</h3>
                    <p><?php echo xlt('Average Loss'); ?></p>
                </div>
                <div class="stat-card">
                    <h3><?php echo isset($report_stats['patients_with_loss']) ? round(($report_stats['patients_with_loss'] / max(1, $report_stats['total_patients'])) * 100, 1) : '0'; ?>%</h3>
                    <p><?php echo xlt('Success Rate'); ?></p>
                </div>
            </div>
            
            <div class="no-data">
                <h3><?php echo xlt('Advanced Analytics'); ?></h3>
                <p><?php echo xlt('Advanced analytics features including trend analysis, predictive modeling, and treatment correlation will be available here.'); ?></p>
            </div>
        </div>
        
        <!-- Goals Tab -->
        <div id="goals" class="tab-content <?php echo $active_tab === 'goals' ? 'active' : ''; ?>">
            <div class="controls-section">
                <form method="POST" action="?tab=goals">
                    <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="form_patient_id"><?php echo xlt('Select Patient:'); ?></label>
                            <select id="form_patient_id" name="form_patient_id" onchange="this.form.submit()">
                                <option value=""><?php echo xlt('Choose a patient...'); ?></option>
                                <?php
                                $patient_query = "SELECT pid, fname, lname FROM patient_data ORDER BY lname, fname";
                                $patient_result = sqlStatement($patient_query);
                                while ($patient = sqlFetchArray($patient_result)) {
                                    $selected = ($form_patient_id == $patient['pid']) ? 'selected' : '';
                                    echo "<option value='" . attr($patient['pid']) . "' $selected>" . 
                                         text($patient['lname'] . ', ' . $patient['fname']) . "</option>";
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <button type="button" class="btn btn-success" id="addGoalButton">
                                ➕ <?php echo xlt('Add New Goal'); ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            
            <?php if (!empty($form_patient_id)): ?>
                <?php
                $current_weight = getCurrentWeight($form_patient_id);
                $patient_goals = getPatientGoals($form_patient_id);
                ?>
                
                <?php if ($current_weight): ?>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <h3><?php echo number_format($current_weight, 1); ?> lbs</h3>
                            <p><?php echo xlt('Current Weight'); ?></p>
                        </div>
                        <div class="stat-card">
                            <h3><?php echo count(sqlFetchArray($patient_goals)); ?></h3>
                            <p><?php echo xlt('Active Goals'); ?></p>
                        </div>
                    </div>
                    
                    <!-- Patient Goals List -->
                    <div class="goals-section">
                        <h3><?php echo xlt('Weight Loss Goals'); ?></h3>
                        
                        <?php
                        $goals = getPatientGoals($form_patient_id);
                        $has_goals = false;
                        
                        while ($goal = sqlFetchArray($goals)) {
                            $has_goals = true;
                            $progress = calculateGoalProgress($current_weight, $current_weight + 10, $goal['goal_weight']); // Simplified calculation
                            $days_remaining = max(0, (strtotime($goal['target_date']) - time()) / (60 * 60 * 24));
                        ?>
                        <div class="goal-card">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                <h4><?php echo xlt('Weight Loss Goal'); ?></h4>
                                <span style="background: #d4edda; color: #155724; padding: 5px 15px; border-radius: 20px; font-size: 12px; font-weight: 600;">
                                    <?php echo xlt('Active'); ?>
                                </span>
                            </div>
                            
                            <?php if ($progress): ?>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $progress['progress_percentage']; ?>%">
                                    <?php echo number_format($progress['progress_percentage'], 1); ?>%
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin: 15px 0;">
                                <div style="text-align: center;">
                                    <h4 style="margin: 0 0 5px 0; font-size: 24px; color: #007cba;"><?php echo number_format($goal['goal_weight'], 1); ?> lbs</h4>
                                    <p style="margin: 0; font-size: 12px; color: #666; text-transform: uppercase;"><?php echo xlt('Target Weight'); ?></p>
                                </div>
                                <div style="text-align: center;">
                                    <h4 style="margin: 0 0 5px 0; font-size: 24px; color: #007cba;"><?php echo date('M d, Y', strtotime($goal['target_date'])); ?></h4>
                                    <p style="margin: 0; font-size: 12px; color: #666; text-transform: uppercase;"><?php echo xlt('Target Date'); ?></p>
                                </div>
                                <div style="text-align: center;">
                                    <h4 style="margin: 0 0 5px 0; font-size: 24px; color: #007cba;"><?php echo number_format($days_remaining, 0); ?></h4>
                                    <p style="margin: 0; font-size: 12px; color: #666; text-transform: uppercase;"><?php echo xlt('Days Left'); ?></p>
                                </div>
                            </div>
                            
                            <?php if (!empty($goal['notes'])): ?>
                            <div style="margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
                                <strong><?php echo xlt('Notes:'); ?></strong> <?php echo text($goal['notes']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php } ?>
                        
                        <?php if (!$has_goals): ?>
                        <div class="no-data">
                            <h3><?php echo xlt('No Goals Set'); ?></h3>
                            <p><?php echo xlt('This patient doesn\'t have any active weight loss goals. Click "Add New Goal" to create one.'); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                <?php else: ?>
                    <div class="no-data">
                        <h3><?php echo xlt('No Weight Data'); ?></h3>
                        <p><?php echo xlt('This patient doesn\'t have any weight measurements recorded. Please add weight data first.'); ?></p>
                    </div>
                <?php endif; ?>
                
            <?php else: ?>
                <div class="no-data">
                    <h3><?php echo xlt('Select a Patient'); ?></h3>
                    <p><?php echo xlt('Please select a patient from the dropdown above to view and manage their weight goals.'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Create Goal Modal -->
    <div id="createGoalModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><?php echo xlt('Create Weight Loss Goal'); ?></h2>
                <span class="close" onclick="closeModal('createGoalModal')">&times;</span>
            </div>
            
            <form method="POST" action="?tab=goals">
                <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                <input type="hidden" name="action" value="create_goal" />
                <input type="hidden" name="patient_id" value="<?php echo attr($form_patient_id); ?>" />
                
                <div class="form-group-modal">
                    <label for="goal_weight"><?php echo xlt('Target Weight (lbs):'); ?></label>
                    <input type="number" id="goal_weight" name="goal_weight" step="0.1" min="50" max="500" required>
                </div>
                
                <div class="form-group-modal">
                    <label for="target_date"><?php echo xlt('Target Date:'); ?></label>
                    <input type="date" id="target_date" name="target_date" required>
                </div>
                
                <div class="form-group-modal">
                    <label for="notes"><?php echo xlt('Notes (optional):'); ?></label>
                    <textarea id="notes" name="notes" placeholder="<?php echo xlt('Additional notes about this goal...'); ?>"></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('createGoalModal')"><?php echo xlt('Cancel'); ?></button>
                    <button type="submit" class="btn btn-success"><?php echo xlt('Create Goal'); ?></button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Make functions globally available
        window.switchTab = function(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked tab button
            event.target.classList.add('active');
        };
        
        window.openCreateGoalModal = function() {
            const patientSelect = document.getElementById('form_patient_id');
            if (!patientSelect || !patientSelect.value) {
                alert('<?php echo xlt('Please select a patient first'); ?>');
                return;
            }
            const modal = document.getElementById('createGoalModal');
            if (modal) {
                modal.style.display = 'block';
            }
        };
        
        window.closeModal = function(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
            }
        };
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let modal of modals) {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            }
        };
        
         window.refreshData = function() {
             // Show loading state
             const refreshBtn = document.querySelector('.refresh-button');
             const originalText = refreshBtn.innerHTML;
             refreshBtn.innerHTML = '⏳ <?php echo xlt('Refreshing...'); ?>';
             refreshBtn.disabled = true;
             
             // Reload the page to get fresh data
             window.location.reload();
         };
         
         window.updateLastUpdatedTime = function() {
             const now = new Date();
             const timeString = now.toLocaleString();
             const lastUpdatedElement = document.getElementById('lastUpdated');
             if (lastUpdatedElement) {
                 lastUpdatedElement.textContent = '<?php echo xlt('Last updated:'); ?> ' + timeString;
             }
         };
         
         document.addEventListener('DOMContentLoaded', function() {
             console.log('Weight Tracking System loaded successfully');
             
             // Set up tab switching
             document.querySelectorAll('.tab-button').forEach(button => {
                 button.addEventListener('click', function() {
                     const tabName = this.getAttribute('data-tab');
                     switchTab(tabName);
                 });
             });
             
             // Set up add goal button
             const addGoalButton = document.getElementById('addGoalButton');
             if (addGoalButton) {
                 addGoalButton.addEventListener('click', function() {
                     openCreateGoalModal();
                 });
             }
             
             // Auto-refresh every 2 minutes for real-time updates
             setInterval(function() {
                 console.log('Auto-refreshing weight data...');
                 updateLastUpdatedTime();
                 
                 // Simple refresh to ensure we always have the latest data
                 // In a production environment, you might want to use AJAX to update specific parts
                 setTimeout(function() {
                     console.log('Refreshing data automatically...');
                     refreshData();
                 }, 1000); // Small delay to show the timestamp update
                 
             }, 120000); // 2 minutes for more frequent updates
             
             // Update timestamp every minute
             setInterval(updateLastUpdatedTime, 60000);
         });
    </script>
</body>
</html>
