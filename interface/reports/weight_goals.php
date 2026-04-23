<?php
/**
 * Weight Goals Tracking System
 * Patient weight goal setting and progress monitoring
 * 
 * Features:
 * - Set and track patient weight loss goals
 * - Progress monitoring with visual indicators
 * - Goal achievement analytics
 * - Timeline tracking
 * - Motivational feedback
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

// Handle goal creation/update
if (!empty($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'create_goal' || $action === 'update_goal') {
        $pid = $_POST['patient_id'];
        $goal_weight = $_POST['goal_weight'];
        $target_date = $_POST['target_date'];
        $notes = $_POST['notes'];
        $goal_id = $_POST['goal_id'] ?? null;
        
        if ($action === 'create_goal') {
            $query = "INSERT INTO patient_goals (pid, goal_type, goal_weight, target_date, notes, status, created_date) 
                     VALUES (?, 'weight_loss', ?, ?, ?, 'active', NOW())";
            sqlStatement($query, [$pid, $goal_weight, $target_date, $notes]);
            $message = "Weight goal created successfully!";
        } else {
            $query = "UPDATE patient_goals SET goal_weight = ?, target_date = ?, notes = ? WHERE id = ?";
            sqlStatement($query, [$goal_weight, $target_date, $notes, $goal_id]);
            $message = "Weight goal updated successfully!";
        }
    } elseif ($action === 'delete_goal') {
        $goal_id = $_POST['goal_id'];
        $query = "UPDATE patient_goals SET status = 'inactive' WHERE id = ?";
        sqlStatement($query, [$goal_id]);
        $message = "Weight goal deleted successfully!";
    }
}

// Get report parameters
$form_patient_id = $_POST['form_patient_id'] ?? $_GET['patient_id'] ?? '';
$form_date = $_POST['form_date'] ?? date('Y-m-d', strtotime('-90 days'));
$form_to_date = $_POST['form_to_date'] ?? date('Y-m-d');

// Weight Goals Functions
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

function getStartingWeight($patient_id) {
    $query = "
        SELECT weight
        FROM form_vitals
        WHERE pid = ? AND weight > 0
        ORDER BY date ASC
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

function getGoalAnalytics($date_from, $date_to) {
    $query = "
        SELECT 
            COUNT(*) as total_goals,
            SUM(CASE WHEN pg.status = 'active' THEN 1 ELSE 0 END) as active_goals,
            SUM(CASE WHEN pg.status = 'achieved' THEN 1 ELSE 0 END) as achieved_goals,
            AVG(pg.goal_weight) as avg_goal_weight,
            p.fname,
            p.lname,
            pg.pid
        FROM patient_goals pg
        JOIN patient_data p ON pg.pid = p.pid
        WHERE pg.goal_type = 'weight_loss'
        AND pg.created_date BETWEEN ? AND ?
        GROUP BY pg.pid, p.fname, p.lname
    ";
    
    return sqlStatement($query, [$date_from, $date_to]);
}

// Get patient goals data
$patient_goals = [];
$current_weight = null;
$starting_weight = null;
$goal_progress = null;

if (!empty($form_patient_id)) {
    $patient_goals = getPatientGoals($form_patient_id);
    $current_weight = getCurrentWeight($form_patient_id);
    $starting_weight = getStartingWeight($form_patient_id);
}

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Weight Goals Tracking'); ?></title>
    <?php Header::setupHeader(['datetime-picker', 'common']); ?>
    <style>
        .goals-container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .goals-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid #28a745;
        }
        
        .goals-header h1 {
            color: #28a745;
            margin: 0;
            font-size: 32px;
            font-weight: 300;
        }
        
        .goals-header .subtitle {
            color: #666;
            margin-top: 10px;
            font-size: 18px;
        }
        
        .patient-selector {
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
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        
        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        
        .current-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        
        .stat-card h3 {
            margin: 0 0 10px 0;
            font-size: 28px;
            font-weight: bold;
        }
        
        .stat-card p {
            margin: 0;
            font-size: 14px;
            opacity: 0.9;
        }
        
        .progress-container {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .progress-bar {
            width: 100%;
            height: 30px;
            background: #e9ecef;
            border-radius: 15px;
            overflow: hidden;
            margin: 15px 0;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #28a745, #20c997);
            border-radius: 15px;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
        }
        
        .goal-card {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        
        .goal-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        
        .goal-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .goal-title {
            font-size: 20px;
            font-weight: 600;
            color: #333;
        }
        
        .goal-status {
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-achieved {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .goal-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 15px 0;
        }
        
        .goal-detail {
            text-align: center;
        }
        
        .goal-detail h4 {
            margin: 0 0 5px 0;
            font-size: 24px;
            color: #007cba;
        }
        
        .goal-detail p {
            margin: 0;
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
        }
        
        .goal-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
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
        
        .achievement-badge {
            background: linear-gradient(135deg, #ffd700, #ffed4e);
            color: #8b6914;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 12px;
            text-transform: uppercase;
        }
        
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
            }
            
            .current-stats {
                grid-template-columns: 1fr;
            }
            
            .goal-details {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="goals-container">
        <!-- Goals Header -->
        <div class="goals-header">
            <h1><?php echo xlt('Weight Goals Tracking'); ?></h1>
            <div class="subtitle">
                <?php echo xlt('Set, track, and monitor patient weight loss goals'); ?>
            </div>
        </div>
        
        <!-- Patient Selector -->
        <div class="patient-selector">
            <form method="POST" action="">
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
                    
                    <div class="btn-group">
                        <button type="button" class="btn btn-success" onclick="openCreateGoalModal()">
                            ➕ <?php echo xlt('Add New Goal'); ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <?php if (!empty($form_patient_id) && !empty($current_weight)): ?>
            <!-- Current Weight Statistics -->
            <div class="current-stats">
                <div class="stat-card">
                    <h3><?php echo number_format($current_weight, 1); ?> lbs</h3>
                    <p><?php echo xlt('Current Weight'); ?></p>
                </div>
                <div class="stat-card">
                    <h3><?php echo $starting_weight ? number_format($starting_weight - $current_weight, 1) : '0.0'; ?> lbs</h3>
                    <p><?php echo xlt('Total Lost'); ?></p>
                </div>
                <div class="stat-card">
                    <h3><?php echo count(sqlFetchArray(getPatientGoals($form_patient_id))); ?></h3>
                    <p><?php echo xlt('Active Goals'); ?></p>
                </div>
            </div>
            
            <!-- Goal Progress Overview -->
            <?php
            $goals = getPatientGoals($form_patient_id);
            $goal_array = sqlFetchArray($goals);
            if ($goal_array):
                $progress = calculateGoalProgress($current_weight, $starting_weight, $goal_array['goal_weight']);
                if ($progress):
            ?>
            <div class="progress-container">
                <h3><?php echo xlt('Goal Progress Overview'); ?></h3>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $progress['progress_percentage']; ?>%">
                        <?php echo number_format($progress['progress_percentage'], 1); ?>%
                    </div>
                </div>
                <div class="goal-details">
                    <div class="goal-detail">
                        <h4><?php echo number_format($progress['current_loss'], 1); ?> lbs</h4>
                        <p><?php echo xlt('Lost So Far'); ?></p>
                    </div>
                    <div class="goal-detail">
                        <h4><?php echo number_format($progress['remaining_loss'], 1); ?> lbs</h4>
                        <p><?php echo xlt('Remaining'); ?></p>
                    </div>
                    <div class="goal-detail">
                        <h4><?php echo number_format($progress['total_goal_loss'], 1); ?> lbs</h4>
                        <p><?php echo xlt('Total Goal'); ?></p>
                    </div>
                </div>
                
                <?php if ($progress['is_achieved']): ?>
                    <div style="text-align: center; margin-top: 20px;">
                        <span class="achievement-badge">🎉 <?php echo xlt('Goal Achieved!'); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; endif; ?>
            
            <!-- Patient Goals List -->
            <div class="goals-list">
                <h3><?php echo xlt('Weight Loss Goals'); ?></h3>
                
                <?php
                $goals = getPatientGoals($form_patient_id);
                $has_goals = false;
                
                while ($goal = sqlFetchArray($goals)) {
                    $has_goals = true;
                    $progress = calculateGoalProgress($current_weight, $starting_weight, $goal['goal_weight']);
                    $days_remaining = max(0, (strtotime($goal['target_date']) - time()) / (60 * 60 * 24));
                ?>
                <div class="goal-card">
                    <div class="goal-header">
                        <div class="goal-title"><?php echo xlt('Weight Loss Goal'); ?></div>
                        <div class="goal-status status-<?php echo $goal['status']; ?>">
                            <?php echo xlt(ucfirst($goal['status'])); ?>
                        </div>
                    </div>
                    
                    <?php if ($progress): ?>
                    <div class="progress-bar" style="height: 20px; margin: 15px 0;">
                        <div class="progress-fill" style="width: <?php echo $progress['progress_percentage']; ?>%">
                            <?php echo number_format($progress['progress_percentage'], 1); ?>%
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="goal-details">
                        <div class="goal-detail">
                            <h4><?php echo number_format($goal['goal_weight'], 1); ?> lbs</h4>
                            <p><?php echo xlt('Target Weight'); ?></p>
                        </div>
                        <div class="goal-detail">
                            <h4><?php echo date('M d, Y', strtotime($goal['target_date'])); ?></h4>
                            <p><?php echo xlt('Target Date'); ?></p>
                        </div>
                        <div class="goal-detail">
                            <h4><?php echo number_format($days_remaining, 0); ?></h4>
                            <p><?php echo xlt('Days Left'); ?></p>
                        </div>
                        <div class="goal-detail">
                            <h4><?php echo $progress ? number_format($progress['remaining_loss'], 1) : 'N/A'; ?> lbs</h4>
                            <p><?php echo xlt('Remaining'); ?></p>
                        </div>
                    </div>
                    
                    <?php if (!empty($goal['notes'])): ?>
                    <div style="margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
                        <strong><?php echo xlt('Notes:'); ?></strong> <?php echo text($goal['notes']); ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="goal-actions">
                        <button type="button" class="btn btn-warning" onclick="openEditGoalModal(<?php echo $goal['id']; ?>, <?php echo $goal['goal_weight']; ?>, '<?php echo $goal['target_date']; ?>', '<?php echo htmlspecialchars($goal['notes']); ?>')">
                            ✏️ <?php echo xlt('Edit'); ?>
                        </button>
                        <button type="button" class="btn btn-danger" onclick="deleteGoal(<?php echo $goal['id']; ?>)">
                            🗑️ <?php echo xlt('Delete'); ?>
                        </button>
                    </div>
                </div>
                <?php } ?>
                
                <?php if (!$has_goals): ?>
                <div class="no-data">
                    <h3><?php echo xlt('No Goals Set'); ?></h3>
                    <p><?php echo xlt('This patient doesn\'t have any active weight loss goals. Click "Add New Goal" to create one.'); ?></p>
                </div>
                <?php endif; ?>
            </div>
            
        <?php elseif (!empty($form_patient_id)): ?>
            <div class="no-data">
                <h3><?php echo xlt('No Weight Data'); ?></h3>
                <p><?php echo xlt('This patient doesn\'t have any weight measurements recorded. Please add weight data first.'); ?></p>
            </div>
        <?php else: ?>
            <div class="no-data">
                <h3><?php echo xlt('Select a Patient'); ?></h3>
                <p><?php echo xlt('Please select a patient from the dropdown above to view and manage their weight goals.'); ?></p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Create Goal Modal -->
    <div id="createGoalModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><?php echo xlt('Create Weight Loss Goal'); ?></h2>
                <span class="close" onclick="closeModal('createGoalModal')">&times;</span>
            </div>
            
            <form method="POST" action="">
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
    
    <!-- Edit Goal Modal -->
    <div id="editGoalModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><?php echo xlt('Edit Weight Loss Goal'); ?></h2>
                <span class="close" onclick="closeModal('editGoalModal')">&times;</span>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                <input type="hidden" name="action" value="update_goal" />
                <input type="hidden" name="goal_id" id="edit_goal_id" />
                
                <div class="form-group-modal">
                    <label for="edit_goal_weight"><?php echo xlt('Target Weight (lbs):'); ?></label>
                    <input type="number" id="edit_goal_weight" name="goal_weight" step="0.1" min="50" max="500" required>
                </div>
                
                <div class="form-group-modal">
                    <label for="edit_target_date"><?php echo xlt('Target Date:'); ?></label>
                    <input type="date" id="edit_target_date" name="target_date" required>
                </div>
                
                <div class="form-group-modal">
                    <label for="edit_notes"><?php echo xlt('Notes (optional):'); ?></label>
                    <textarea id="edit_notes" name="notes" placeholder="<?php echo xlt('Additional notes about this goal...'); ?>"></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editGoalModal')"><?php echo xlt('Cancel'); ?></button>
                    <button type="submit" class="btn btn-success"><?php echo xlt('Update Goal'); ?></button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openCreateGoalModal() {
            if (!document.getElementById('form_patient_id').value) {
                alert('<?php echo xlt('Please select a patient first'); ?>');
                return;
            }
            document.getElementById('createGoalModal').style.display = 'block';
        }
        
        function openEditGoalModal(goalId, goalWeight, targetDate, notes) {
            document.getElementById('edit_goal_id').value = goalId;
            document.getElementById('edit_goal_weight').value = goalWeight;
            document.getElementById('edit_target_date').value = targetDate;
            document.getElementById('edit_notes').value = notes;
            document.getElementById('editGoalModal').style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function deleteGoal(goalId) {
            if (confirm('<?php echo xlt('Are you sure you want to delete this goal?'); ?>')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                    <input type="hidden" name="action" value="delete_goal" />
                    <input type="hidden" name="goal_id" value="${goalId}" />
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let modal of modals) {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Weight Goals Tracking loaded successfully');
        });
    </script>
</body>
</html>
