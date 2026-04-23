<?php
/**
 * Weight Reports Navigation
 * Central navigation hub for all weight tracking and analytics reports
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("../../library/patient.inc");
require_once("../../library/options.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Core\Header;

// Check if user has access
if (!AclMain::aclCheckCore('patients', 'med')) {
    echo "<div class='alert alert-danger'>Access Denied. Patient medical access required.</div>";
    exit;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Weight Reports Navigation'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <style>
        .navigation-container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .nav-header {
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 3px solid #007cba;
        }
        
        .nav-header h1 {
            color: #007cba;
            margin: 0;
            font-size: 36px;
            font-weight: 300;
        }
        
        .nav-header .subtitle {
            color: #666;
            margin-top: 15px;
            font-size: 20px;
        }
        
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }
        
        .report-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border: 2px solid #dee2e6;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        
        .report-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            border-color: #007cba;
        }
        
        .report-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #007cba, #28a745);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }
        
        .report-card:hover::before {
            transform: scaleX(1);
        }
        
        .report-icon {
            font-size: 48px;
            margin-bottom: 20px;
            color: #007cba;
        }
        
        .report-title {
            font-size: 24px;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
        }
        
        .report-description {
            color: #666;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 20px;
        }
        
        .report-features {
            text-align: left;
            margin: 20px 0;
        }
        
        .report-features h4 {
            color: #007cba;
            font-size: 16px;
            margin-bottom: 10px;
        }
        
        .feature-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .feature-list li {
            padding: 5px 0;
            color: #666;
            font-size: 14px;
        }
        
        .feature-list li::before {
            content: '✓';
            color: #28a745;
            font-weight: bold;
            margin-right: 8px;
        }
        
        .report-button {
            background: linear-gradient(135deg, #007cba, #005a8b);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 25px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            margin-top: 15px;
        }
        
        .report-button:hover {
            background: linear-gradient(135deg, #005a8b, #003d63);
            color: white;
            text-decoration: none;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .quick-stats {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 40px;
            text-align: center;
        }
        
        .quick-stats h3 {
            color: #1976d2;
            margin-bottom: 20px;
            font-size: 24px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .stat-item {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #1976d2;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        
        .help-section {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 30px;
            margin-top: 40px;
        }
        
        .help-section h3 {
            color: #333;
            margin-bottom: 20px;
            font-size: 24px;
        }
        
        .help-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
        }
        
        .help-item {
            background: white;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid #007cba;
        }
        
        .help-item h4 {
            color: #007cba;
            margin-bottom: 10px;
            font-size: 18px;
        }
        
        .help-item p {
            color: #666;
            line-height: 1.6;
            margin: 0;
        }
        
        @media (max-width: 768px) {
            .reports-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .help-content {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="navigation-container">
        <!-- Navigation Header -->
        <div class="nav-header">
            <h1><?php echo xlt('Weight Tracking & Analytics'); ?></h1>
            <div class="subtitle">
                <?php echo xlt('Comprehensive weight loss monitoring and reporting suite'); ?>
            </div>
        </div>
        
        <!-- Quick Statistics -->
        <div class="quick-stats">
            <h3>📊 <?php echo xlt('Quick Overview'); ?></h3>
            <div class="stats-grid">
                <?php
                // Get quick statistics
                $total_patients = sqlQuery("SELECT COUNT(DISTINCT pid) as count FROM form_vitals WHERE weight > 0")['count'];
                $total_goals = sqlQuery("SELECT COUNT(*) as count FROM patient_goals WHERE status = 'active' AND goal_type = 'weight_loss'")['count'];
                $achieved_goals = sqlQuery("SELECT COUNT(*) as count FROM patient_goals WHERE status = 'achieved' AND goal_type = 'weight_loss'")['count'];
                $recent_weigh_ins = sqlQuery("SELECT COUNT(*) as count FROM form_vitals WHERE weight > 0 AND date >= DATE_SUB(NOW(), INTERVAL 7 DAYS)")['count'];
                ?>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $total_patients; ?></div>
                    <div class="stat-label"><?php echo xlt('Patients with Weight Data'); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $total_goals; ?></div>
                    <div class="stat-label"><?php echo xlt('Active Weight Goals'); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $achieved_goals; ?></div>
                    <div class="stat-label"><?php echo xlt('Goals Achieved'); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $recent_weigh_ins; ?></div>
                    <div class="stat-label"><?php echo xlt('Weigh-ins This Week'); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Reports Grid -->
        <div class="reports-grid">
            <!-- Weight Reports -->
            <div class="report-card" onclick="window.location.href='weight_reports.php'">
                <div class="report-icon">⚖️</div>
                <div class="report-title"><?php echo xlt('Weight Reports'); ?></div>
                <div class="report-description">
                    <?php echo xlt('Basic weight tracking and loss summary reports with date range filtering and patient selection.'); ?>
                </div>
                <div class="report-features">
                    <h4><?php echo xlt('Features:'); ?></h4>
                    <ul class="feature-list">
                        <li><?php echo xlt('Weight loss summary by date range'); ?></li>
                        <li><?php echo xlt('Individual patient weight history'); ?></li>
                        <li><?php echo xlt('BMI tracking and analysis'); ?></li>
                        <li><?php echo xlt('CSV export functionality'); ?></li>
                        <li><?php echo xlt('Patient filtering options'); ?></li>
                    </ul>
                </div>
                <a href="weight_reports.php" class="report-button">
                    📊 <?php echo xlt('Open Weight Reports'); ?>
                </a>
            </div>
            
            <!-- Weight Analytics -->
            <div class="report-card" onclick="window.location.href='weight_analytics.php'">
                <div class="report-icon">📈</div>
                <div class="report-title"><?php echo xlt('Weight Analytics'); ?></div>
                <div class="report-description">
                    <?php echo xlt('Advanced weight analytics with trends analysis, predictive insights, and comprehensive reporting.'); ?>
                </div>
                <div class="report-features">
                    <h4><?php echo xlt('Features:'); ?></h4>
                    <ul class="feature-list">
                        <li><?php echo xlt('Comprehensive weight analysis'); ?></li>
                        <li><?php echo xlt('Weight trend predictions'); ?></li>
                        <li><?php echo xlt('Treatment correlation analysis'); ?></li>
                        <li><?php echo xlt('Advanced statistical reporting'); ?></li>
                        <li><?php echo xlt('Interactive charts and graphs'); ?></li>
                    </ul>
                </div>
                <a href="weight_analytics.php" class="report-button">
                    📈 <?php echo xlt('Open Analytics'); ?>
                </a>
            </div>
            
            <!-- Weight Goals -->
            <div class="report-card" onclick="window.location.href='weight_goals.php'">
                <div class="report-icon">🎯</div>
                <div class="report-title"><?php echo xlt('Weight Goals'); ?></div>
                <div class="report-description">
                    <?php echo xlt('Set, track, and monitor patient weight loss goals with progress visualization and achievement tracking.'); ?>
                </div>
                <div class="report-features">
                    <h4><?php echo xlt('Features:'); ?></h4>
                    <ul class="feature-list">
                        <li><?php echo xlt('Goal setting and management'); ?></li>
                        <li><?php echo xlt('Progress tracking and visualization'); ?></li>
                        <li><?php echo xlt('Achievement monitoring'); ?></li>
                        <li><?php echo xlt('Timeline and deadline tracking'); ?></li>
                        <li><?php echo xlt('Motivational feedback'); ?></li>
                    </ul>
                </div>
                <a href="weight_goals.php" class="report-button">
                    🎯 <?php echo xlt('Manage Goals'); ?>
                </a>
            </div>
            
            <!-- DCR Reports -->
            <div class="report-card" onclick="window.location.href='dcr_daily_collection_report.php'">
                <div class="report-icon">💰</div>
                <div class="report-title"><?php echo xlt('DCR Reports'); ?></div>
                <div class="report-description">
                    <?php echo xlt('Daily Collection Reports with treatment categorization including weight loss treatments (LIPO, SEMA, TRZ).'); ?>
                </div>
                <div class="report-features">
                    <h4><?php echo xlt('Features:'); ?></h4>
                    <ul class="feature-list">
                        <li><?php echo xlt('Daily revenue tracking'); ?></li>
                        <li><?php echo xlt('Treatment categorization'); ?></li>
                        <li><?php echo xlt('Weight loss treatment tracking'); ?></li>
                        <li><?php echo xlt('Shot card usage monitoring'); ?></li>
                        <li><?php echo xlt('Monthly breakdown reports'); ?></li>
                    </ul>
                </div>
                <a href="dcr_daily_collection_report.php" class="report-button">
                    💰 <?php echo xlt('Open DCR Reports'); ?>
                </a>
            </div>
        </div>
        
        <!-- Help Section -->
        <div class="help-section">
            <h3>❓ <?php echo xlt('Getting Started Guide'); ?></h3>
            <div class="help-content">
                <div class="help-item">
                    <h4><?php echo xlt('For New Users'); ?></h4>
                    <p><?php echo xlt('Start with Weight Reports for basic weight tracking. This will show you patient weight loss summaries and individual histories.'); ?></p>
                </div>
                <div class="help-item">
                    <h4><?php echo xlt('Advanced Analytics'); ?></h4>
                    <p><?php echo xlt('Use Weight Analytics for deeper insights, trend analysis, and predictive weight loss modeling for your patients.'); ?></p>
                </div>
                <div class="help-item">
                    <h4><?php echo xlt('Goal Management'); ?></h4>
                    <p><?php echo xlt('Set up Weight Goals to help patients track their progress and stay motivated with visual progress indicators.'); ?></p>
                </div>
                <div class="help-item">
                    <h4><?php echo xlt('Treatment Integration'); ?></h4>
                    <p><?php echo xlt('DCR Reports integrate weight loss treatments (LIPO, SEMA, TRZ) with financial tracking and patient outcomes.'); ?></p>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div style="text-align: center; margin-top: 40px; padding-top: 20px; border-top: 2px solid #e9ecef; color: #666;">
            <p><?php echo xlt('Weight Tracking & Analytics System'); ?> | <?php echo xlt('Version 1.0'); ?></p>
            <p><?php echo xlt('For support and documentation, please refer to the system administrator.'); ?></p>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Add smooth scrolling for better UX
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    e.preventDefault();
                    document.querySelector(this.getAttribute('href')).scrollIntoView({
                        behavior: 'smooth'
                    });
                });
            });
            
            // Add loading animation for report cards
            document.querySelectorAll('.report-card').forEach(card => {
                card.addEventListener('click', function(e) {
                    if (e.target.tagName !== 'A') {
                        const button = this.querySelector('.report-button');
                        if (button) {
                            button.innerHTML = '⏳ Loading...';
                        }
                    }
                });
            });
            
            console.log('Weight Reports Navigation loaded successfully');
        });
    </script>
</body>
</html>
