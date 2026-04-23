<?php
/**
 * Advanced Weight Analytics System
 * Comprehensive weight tracking with advanced analytics and reporting
 * 
 * Features:
 * - Weight loss trends and patterns
 * - BMI progression tracking
 * - Goal achievement monitoring
 * - Treatment correlation analysis
 * - Predictive analytics
 * - Comprehensive reporting suite
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

enforceSelectedFacilityScopeForRequest('form_facility');

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

// Get report parameters
$form_date = $_POST['form_date'] ?? date('Y-m-d', strtotime('-90 days'));
$form_to_date = $_POST['form_to_date'] ?? date('Y-m-d');
$form_patient_id = $_POST['form_patient_id'] ?? '';
$form_report_type = $_POST['form_report_type'] ?? 'comprehensive_analysis';
$form_export = $_POST['form_export'] ?? '';
$form_facility = $_POST['form_facility'] ?? '';

// Parse dates
$date_obj = DateTime::createFromFormat('Y-m-d', $form_date);
$to_date_obj = DateTime::createFromFormat('Y-m-d', $form_to_date);

if (!$date_obj) {
    $date_obj = new DateTime('-90 days');
    $form_date = $date_obj->format('Y-m-d');
}
if (!$to_date_obj) {
    $to_date_obj = new DateTime();
    $form_to_date = $to_date_obj->format('Y-m-d');
}

$date_formatted = $date_obj->format('m/d/Y');
$to_date_formatted = $to_date_obj->format('m/d/Y');

// Advanced Weight Analytics Functions
function getComprehensiveWeightAnalysis($date_from, $date_to, $patient_id = '', $facility_id = '') {
    global $sql;
    
    $where_clause = "WHERE fv.date BETWEEN ? AND ? AND fv.weight > 0";
    $params = [$date_from, $date_to];
    
    if (!empty($patient_id)) {
        $where_clause .= " AND fv.pid = ?";
        $params[] = $patient_id;
    }
    
    if (!empty($facility_id)) {
        $where_clause .= " AND fe.facility_id = ?";
        $params[] = $facility_id;
    }
    
    $query = "
        SELECT 
            p.pid,
            p.fname,
            p.lname,
            p.DOB,
            p.sex,
            DATEDIFF(CURDATE(), p.DOB) / 365.25 as age,
            MIN(fv.date) as first_visit,
            MAX(fv.date) as last_visit,
            COUNT(DISTINCT fv.date) as total_visits,
            MIN(fv.weight) as min_weight,
            MAX(fv.weight) as max_weight,
            AVG(fv.weight) as avg_weight,
            (SELECT fv2.weight FROM form_vitals fv2 
             WHERE fv2.pid = p.pid AND fv2.weight > 0 
             ORDER BY fv2.date ASC LIMIT 1) as starting_weight,
            (SELECT fv3.weight FROM form_vitals fv3 
             WHERE fv3.pid = p.pid AND fv3.weight > 0 
             ORDER BY fv3.date DESC LIMIT 1) as current_weight,
            (SELECT fv4.height FROM form_vitals fv4 
             WHERE fv4.pid = p.pid AND fv4.height > 0 
             ORDER BY fv4.date DESC LIMIT 1) as current_height,
            DATEDIFF(MAX(fv.date), MIN(fv.date)) as days_tracked
        FROM form_vitals fv
        JOIN patient_data p ON fv.pid = p.pid
        LEFT JOIN forms f ON f.form_id = fv.id
        LEFT JOIN form_encounter fe ON f.encounter = fe.encounter
        $where_clause
        GROUP BY p.pid, p.fname, p.lname, p.DOB, p.sex
        HAVING total_visits > 1
        ORDER BY (starting_weight - current_weight) DESC
    ";
    
    return sqlStatement($query, $params);
}

function getWeightLossTrends($patient_id, $date_from, $date_to) {
    $query = "
        SELECT 
            fv.date,
            fv.weight,
            fv.height,
            fv.BMI,
            fv.BMI_status,
            LAG(fv.weight) OVER (ORDER BY fv.date) as prev_weight,
            fv.weight - LAG(fv.weight) OVER (ORDER BY fv.date) as weight_change
        FROM form_vitals fv
        WHERE fv.pid = ? 
        AND fv.date BETWEEN ? AND ?
        AND fv.weight > 0
        ORDER BY fv.date ASC
    ";
    
    return sqlStatement($query, [$patient_id, $date_from, $date_to]);
}

function getTreatmentCorrelation($patient_id, $date_from, $date_to) {
    $query = "
        SELECT 
            ds.sale_date,
            ds.drug_id,
            d.name as drug_name,
            ds.quantity,
            ds.fee,
            fv.weight,
            fv.BMI
        FROM drug_sales ds
        JOIN drugs d ON ds.drug_id = d.drug_id
        LEFT JOIN form_vitals fv ON DATE(ds.sale_date) = DATE(fv.date) AND ds.pid = fv.pid
        WHERE ds.pid = ?
        AND ds.sale_date BETWEEN ? AND ?
        AND (d.name LIKE '%sema%' OR d.name LIKE '%tirz%' OR d.name LIKE '%lipo%')
        ORDER BY ds.sale_date ASC
    ";
    
    return sqlStatement($query, [$patient_id, $date_from, $date_to]);
}

function calculateAdvancedStats($weight_data) {
    $stats = [
        'total_patients' => 0,
        'total_weight_loss' => 0,
        'average_weight_loss' => 0,
        'patients_with_loss' => 0,
        'patients_with_gain' => 0,
        'patients_maintained' => 0,
        'average_age' => 0,
        'gender_distribution' => ['M' => 0, 'F' => 0],
        'bmi_categories' => [
            'Underweight' => 0,
            'Normal' => 0,
            'Overweight' => 0,
            'Obese' => 0
        ],
        'success_rate' => 0,
        'average_days_tracked' => 0
    ];
    
    $total_age = 0;
    $total_days = 0;
    
    while ($row = sqlFetchArray($weight_data)) {
        $stats['total_patients']++;
        
        $weight_change = $row['starting_weight'] - $row['current_weight'];
        $stats['total_weight_loss'] += $weight_change;
        
        // Calculate BMI
        if ($row['current_height'] > 0) {
            $bmi = ($row['current_weight'] / pow($row['current_height'], 2)) * 703;
            
            if ($bmi < 18.5) {
                $stats['bmi_categories']['Underweight']++;
            } elseif ($bmi < 25) {
                $stats['bmi_categories']['Normal']++;
            } elseif ($bmi < 30) {
                $stats['bmi_categories']['Overweight']++;
            } else {
                $stats['bmi_categories']['Obese']++;
            }
        }
        
        if ($weight_change > 5) { // Significant weight loss
            $stats['patients_with_loss']++;
        } elseif ($weight_change < -2) {
            $stats['patients_with_gain']++;
        } else {
            $stats['patients_maintained']++;
        }
        
        $total_age += $row['age'];
        $total_days += $row['days_tracked'];
        
        if ($row['sex'] == 'M') {
            $stats['gender_distribution']['M']++;
        } else {
            $stats['gender_distribution']['F']++;
        }
    }
    
    if ($stats['total_patients'] > 0) {
        $stats['average_weight_loss'] = $stats['total_weight_loss'] / $stats['total_patients'];
        $stats['average_age'] = $total_age / $stats['total_patients'];
        $stats['average_days_tracked'] = $total_days / $stats['total_patients'];
        $stats['success_rate'] = ($stats['patients_with_loss'] / $stats['total_patients']) * 100;
    }
    
    return $stats;
}

function generateWeightPrediction($patient_id) {
    $query = "
        SELECT 
            fv.date,
            fv.weight,
            DATEDIFF(fv.date, (SELECT MIN(fv2.date) FROM form_vitals fv2 WHERE fv2.pid = fv.pid AND fv2.weight > 0)) as days_from_start
        FROM form_vitals fv
        WHERE fv.pid = ? 
        AND fv.weight > 0
        ORDER BY fv.date ASC
    ";
    
    $result = sqlStatement($query, [$patient_id]);
    $weights = [];
    $days = [];
    
    while ($row = sqlFetchArray($result)) {
        $weights[] = $row['weight'];
        $days[] = $row['days_from_start'];
    }
    
    if (count($weights) >= 3) {
        // Simple linear regression for prediction
        $n = count($weights);
        $sum_x = array_sum($days);
        $sum_y = array_sum($weights);
        $sum_xy = 0;
        $sum_x2 = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $sum_xy += $days[$i] * $weights[$i];
            $sum_x2 += $days[$i] * $days[$i];
        }
        
        $slope = ($n * $sum_xy - $sum_x * $sum_y) / ($n * $sum_x2 - $sum_x * $sum_x);
        $intercept = ($sum_y - $slope * $sum_x) / $n;
        
        $current_day = max($days);
        $predicted_30_days = $intercept + $slope * ($current_day + 30);
        $predicted_90_days = $intercept + $slope * ($current_day + 90);
        
        return [
            'current_weight' => end($weights),
            'predicted_30_days' => max(0, $predicted_30_days),
            'predicted_90_days' => max(0, $predicted_90_days),
            'weight_loss_rate' => abs($slope) * 7, // pounds per week
            'trend' => $slope < 0 ? 'losing' : ($slope > 0 ? 'gaining' : 'stable')
        ];
    }
    
    return null;
}

// Generate report data based on type
$report_data = [];
$report_stats = [];
$prediction_data = null;

switch ($form_report_type) {
    case 'comprehensive_analysis':
        $report_data = getComprehensiveWeightAnalysis($form_date, $form_to_date, $form_patient_id, $form_facility);
        $report_stats = calculateAdvancedStats($report_data);
        break;
        
    case 'individual_analysis':
        if (!empty($form_patient_id)) {
            $report_data = getWeightLossTrends($form_patient_id, $form_date, $form_to_date);
            $prediction_data = generateWeightPrediction($form_patient_id);
        }
        break;
        
    case 'treatment_correlation':
        if (!empty($form_patient_id)) {
            $report_data = getTreatmentCorrelation($form_patient_id, $form_date, $form_to_date);
        }
        break;
}

// Handle export
if (!empty($form_export) && $form_export === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="weight_analytics_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    if ($form_report_type === 'comprehensive_analysis') {
        fputcsv($output, ['Patient ID', 'Name', 'Age', 'Gender', 'Starting Weight', 'Current Weight', 'Weight Change', 'BMI Category', 'Days Tracked', 'Total Visits']);
        
        while ($row = sqlFetchArray($report_data)) {
            $weight_change = $row['starting_weight'] - $row['current_weight'];
            $bmi = ($row['current_height'] > 0) ? ($row['current_weight'] / pow($row['current_height'], 2)) * 703 : 0;
            
            $bmi_category = 'Unknown';
            if ($bmi > 0) {
                if ($bmi < 18.5) $bmi_category = 'Underweight';
                elseif ($bmi < 25) $bmi_category = 'Normal';
                elseif ($bmi < 30) $bmi_category = 'Overweight';
                else $bmi_category = 'Obese';
            }
            
            fputcsv($output, [
                $row['pid'],
                $row['fname'] . ' ' . $row['lname'],
                round($row['age'], 1),
                $row['sex'],
                $row['starting_weight'],
                $row['current_weight'],
                $weight_change,
                $bmi_category,
                $row['days_tracked'],
                $row['total_visits']
            ]);
        }
    }
    
    fclose($output);
    exit;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Weight Analytics'); ?></title>
    <?php Header::setupHeader(['datetime-picker', 'common', 'morris']); ?>
    <style>
        .analytics-container {
            max-width: 1400px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .analytics-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid #007cba;
        }
        
        .analytics-header h1 {
            color: #007cba;
            margin: 0;
            font-size: 32px;
            font-weight: 300;
        }
        
        .analytics-header .subtitle {
            color: #666;
            margin-top: 10px;
            font-size: 18px;
        }
        
        .controls-section {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            border: 1px solid #dee2e6;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            align-items: end;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group select {
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #007cba;
            box-shadow: 0 0 0 3px rgba(0, 124, 186, 0.1);
        }
        
        .btn-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #007cba, #005a8b);
            color: white;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #28a745, #1e7e34);
            color: white;
        }
        
        .btn-info {
            background: linear-gradient(135deg, #17a2b8, #117a8b);
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .stats-dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #007cba, #005a8b);
            color: white;
            padding: 25px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 124, 186, 0.2);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card h3 {
            margin: 0 0 10px 0;
            font-size: 36px;
            font-weight: bold;
        }
        
        .stat-card p {
            margin: 0;
            font-size: 16px;
            opacity: 0.9;
        }
        
        .stat-card .trend {
            margin-top: 10px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .chart-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #333;
            border-bottom: 2px solid #f8f9fa;
            padding-bottom: 10px;
        }
        
        .analytics-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .analytics-table th {
            background: linear-gradient(135deg, #007cba, #005a8b);
            color: white;
            padding: 18px 15px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
        }
        
        .analytics-table td {
            padding: 15px;
            border-bottom: 1px solid #f1f3f4;
            font-size: 14px;
        }
        
        .analytics-table tr:hover {
            background: #f8f9fa;
            transition: background-color 0.3s ease;
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
        
        .prediction-box {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border: 2px solid #2196f3;
            border-radius: 12px;
            padding: 25px;
            margin: 20px 0;
        }
        
        .prediction-title {
            font-size: 20px;
            font-weight: 600;
            color: #1976d2;
            margin-bottom: 15px;
        }
        
        .prediction-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .prediction-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #666;
            font-style: italic;
            background: #f8f9fa;
            border-radius: 10px;
            margin: 20px 0;
        }
        
        .export-section {
            margin-top: 30px;
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-dashboard {
                grid-template-columns: 1fr;
            }
            
            .btn-group {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="analytics-container">
        <!-- Analytics Header -->
        <div class="analytics-header">
            <h1><?php echo xlt('Advanced Weight Analytics'); ?></h1>
            <div class="subtitle">
                <?php echo xlt('Comprehensive weight tracking, trends analysis, and predictive insights'); ?>
            </div>
        </div>
        
        <!-- Controls Section -->
        <div class="controls-section">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="form_date"><?php echo xlt('From Date:'); ?></label>
                        <input type="date" id="form_date" name="form_date" value="<?php echo attr($form_date); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="form_to_date"><?php echo xlt('To Date:'); ?></label>
                        <input type="date" id="form_to_date" name="form_to_date" value="<?php echo attr($form_to_date); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="form_report_type"><?php echo xlt('Analysis Type:'); ?></label>
                        <select id="form_report_type" name="form_report_type">
                            <option value="comprehensive_analysis" <?php echo $form_report_type === 'comprehensive_analysis' ? 'selected' : ''; ?>>
                                <?php echo xlt('Comprehensive Analysis'); ?>
                            </option>
                            <option value="individual_analysis" <?php echo $form_report_type === 'individual_analysis' ? 'selected' : ''; ?>>
                                <?php echo xlt('Individual Patient Analysis'); ?>
                            </option>
                            <option value="treatment_correlation" <?php echo $form_report_type === 'treatment_correlation' ? 'selected' : ''; ?>>
                                <?php echo xlt('Treatment Correlation'); ?>
                            </option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="form_patient_id"><?php echo xlt('Patient (for individual analysis):'); ?></label>
                        <select id="form_patient_id" name="form_patient_id">
                            <option value=""><?php echo xlt('Select Patient'); ?></option>
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
                    
                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary">
                            📊 <?php echo xlt('Generate Analysis'); ?>
                        </button>
                        <button type="submit" name="form_export" value="csv" class="btn btn-success">
                            📁 <?php echo xlt('Export CSV'); ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <?php if (!empty($report_data) && $form_report_type === 'comprehensive_analysis'): ?>
            <!-- Advanced Statistics Dashboard -->
            <div class="stats-dashboard">
                <div class="stat-card">
                    <h3><?php echo $report_stats['total_patients']; ?></h3>
                    <p><?php echo xlt('Total Patients Analyzed'); ?></p>
                    <div class="trend"><?php echo xlt('Active Weight Tracking'); ?></div>
                </div>
                <div class="stat-card">
                    <h3><?php echo number_format($report_stats['total_weight_loss'], 1); ?> lbs</h3>
                    <p><?php echo xlt('Total Weight Lost'); ?></p>
                    <div class="trend"><?php echo xlt('Cumulative Success'); ?></div>
                </div>
                <div class="stat-card">
                    <h3><?php echo number_format($report_stats['success_rate'], 1); ?>%</h3>
                    <p><?php echo xlt('Success Rate'); ?></p>
                    <div class="trend"><?php echo xlt('Patients Lost >5 lbs'); ?></div>
                </div>
                <div class="stat-card">
                    <h3><?php echo number_format($report_stats['average_weight_loss'], 1); ?> lbs</h3>
                    <p><?php echo xlt('Average Weight Loss'); ?></p>
                    <div class="trend"><?php echo xlt('Per Patient'); ?></div>
                </div>
                <div class="stat-card">
                    <h3><?php echo number_format($report_stats['average_age'], 1); ?></h3>
                    <p><?php echo xlt('Average Age'); ?></p>
                    <div class="trend"><?php echo xlt('Years Old'); ?></div>
                </div>
                <div class="stat-card">
                    <h3><?php echo number_format($report_stats['average_days_tracked'], 0); ?></h3>
                    <p><?php echo xlt('Days Tracked'); ?></p>
                    <div class="trend"><?php echo xlt('Average Duration'); ?></div>
                </div>
            </div>
            
            <!-- Comprehensive Analysis Table -->
            <div class="chart-container">
                <div class="chart-title"><?php echo xlt('Comprehensive Weight Analysis'); ?></div>
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th><?php echo xlt('Patient'); ?></th>
                            <th><?php echo xlt('Age'); ?></th>
                            <th><?php echo xlt('Gender'); ?></th>
                            <th><?php echo xlt('Starting Weight'); ?></th>
                            <th><?php echo xlt('Current Weight'); ?></th>
                            <th><?php echo xlt('Weight Change'); ?></th>
                            <th><?php echo xlt('BMI Category'); ?></th>
                            <th><?php echo xlt('Days Tracked'); ?></th>
                            <th><?php echo xlt('Visits'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        while ($row = sqlFetchArray($report_data)) {
                            $weight_change = $row['starting_weight'] - $row['current_weight'];
                            $change_class = '';
                            $change_text = '';
                            
                            if ($weight_change > 5) {
                                $change_class = 'weight-positive';
                                $change_text = '-' . number_format($weight_change, 1) . ' lbs';
                            } elseif ($weight_change < -2) {
                                $change_class = 'weight-negative';
                                $change_text = '+' . number_format(abs($weight_change), 1) . ' lbs';
                            } else {
                                $change_class = 'weight-neutral';
                                $change_text = number_format($weight_change, 1) . ' lbs';
                            }
                            
                            // Calculate BMI category
                            $bmi = 0;
                            $bmi_category = 'Unknown';
                            if ($row['current_height'] > 0) {
                                $bmi = ($row['current_weight'] / pow($row['current_height'], 2)) * 703;
                                if ($bmi < 18.5) $bmi_category = 'Underweight';
                                elseif ($bmi < 25) $bmi_category = 'Normal';
                                elseif ($bmi < 30) $bmi_category = 'Overweight';
                                else $bmi_category = 'Obese';
                            }
                            
                            echo "<tr>";
                            echo "<td>" . text($row['fname'] . ' ' . $row['lname']) . "</td>";
                            echo "<td>" . round($row['age'], 1) . "</td>";
                            echo "<td>" . text($row['sex']) . "</td>";
                            echo "<td>" . number_format($row['starting_weight'], 1) . " lbs</td>";
                            echo "<td>" . number_format($row['current_weight'], 1) . " lbs</td>";
                            echo "<td class='$change_class'>$change_text</td>";
                            echo "<td>" . text($bmi_category) . "</td>";
                            echo "<td>" . $row['days_tracked'] . "</td>";
                            echo "<td>" . $row['total_visits'] . "</td>";
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            
        <?php elseif (!empty($report_data) && $form_report_type === 'individual_analysis'): ?>
            <!-- Individual Patient Analysis -->
            <div class="chart-container">
                <div class="chart-title"><?php echo xlt('Individual Patient Weight Trend'); ?></div>
                <div id="weight-trend-chart" style="height: 400px;"></div>
            </div>
            
            <?php if ($prediction_data): ?>
                <div class="prediction-box">
                    <div class="prediction-title">🔮 <?php echo xlt('Weight Prediction Analysis'); ?></div>
                    <div class="prediction-grid">
                        <div class="prediction-item">
                            <h4><?php echo number_format($prediction_data['current_weight'], 1); ?> lbs</h4>
                            <p><?php echo xlt('Current Weight'); ?></p>
                        </div>
                        <div class="prediction-item">
                            <h4><?php echo number_format($prediction_data['predicted_30_days'], 1); ?> lbs</h4>
                            <p><?php echo xlt('Predicted in 30 Days'); ?></p>
                        </div>
                        <div class="prediction-item">
                            <h4><?php echo number_format($prediction_data['predicted_90_days'], 1); ?> lbs</h4>
                            <p><?php echo xlt('Predicted in 90 Days'); ?></p>
                        </div>
                        <div class="prediction-item">
                            <h4><?php echo number_format($prediction_data['weight_loss_rate'], 2); ?> lbs/week</h4>
                            <p><?php echo xlt('Current Rate'); ?></p>
                        </div>
                    </div>
                    <div style="text-align: center; margin-top: 15px;">
                        <strong><?php echo xlt('Trend:'); ?></strong> 
                        <span style="color: <?php echo $prediction_data['trend'] === 'losing' ? '#28a745' : ($prediction_data['trend'] === 'gaining' ? '#dc3545' : '#6c757d'); ?>">
                            <?php echo ucfirst($prediction_data['trend']); ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
            
        <?php elseif (empty($report_data)): ?>
            <div class="no-data">
                <h3><?php echo xlt('No Data Found'); ?></h3>
                <p><?php echo xlt('No weight analytics data found for the selected criteria. Please adjust your parameters and try again.'); ?></p>
            </div>
        <?php endif; ?>
        
        <!-- Export Section -->
        <?php if (!empty($report_data)): ?>
            <div class="export-section">
                <h4><?php echo xlt('Export Options'); ?></h4>
                <form method="POST" action="" style="display: inline;">
                    <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                    <input type="hidden" name="form_date" value="<?php echo attr($form_date); ?>" />
                    <input type="hidden" name="form_to_date" value="<?php echo attr($form_to_date); ?>" />
                    <input type="hidden" name="form_patient_id" value="<?php echo attr($form_patient_id); ?>" />
                    <input type="hidden" name="form_report_type" value="<?php echo attr($form_report_type); ?>" />
                    <button type="submit" name="form_export" value="csv" class="btn btn-info">
                        📊 <?php echo xlt('Download Comprehensive Report'); ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Weight trend chart for individual analysis
            <?php if ($form_report_type === 'individual_analysis' && !empty($report_data)): ?>
            Morris.Line({
                element: 'weight-trend-chart',
                data: [
                    <?php
                    $chart_data = [];
                    while ($row = sqlFetchArray($report_data)) {
                        $chart_data[] = "{
                            date: '" . $row['date'] . "',
                            weight: " . $row['weight'] . ",
                            bmi: " . ($row['BMI'] ?: 0) . "
                        }";
                    }
                    echo implode(',', $chart_data);
                    ?>
                ],
                xkey: 'date',
                ykeys: ['weight', 'bmi'],
                labels: ['Weight (lbs)', 'BMI'],
                lineColors: ['#007cba', '#28a745'],
                pointSize: 4,
                hideHover: 'auto',
                resize: true
            });
            <?php endif; ?>
            
            console.log('Advanced Weight Analytics loaded successfully');
        });
    </script>
</body>
</html>
