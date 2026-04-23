<?php
/**
 * Modern Layout Template for OpenEMR
 * 
 * This template provides a consistent modern design across the entire OpenEMR platform
 * while preserving all existing functionality and data fields.
 * 
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");

use OpenEMR\Core\Header;
use OpenEMR\Menu\MainMenuRole;

// Collect the menu then build it
$menuMain = new MainMenuRole($GLOBALS['kernel']->getEventDispatcher());
$menu_restrictions = $menuMain->getMenu();

// Get current page info
$current_page = basename($_SERVER['PHP_SELF']);
$page_title = $page_title ?? "OpenEMR";
$content_url = $content_url ?? "";
$show_sidebar = $show_sidebar ?? true;
$show_header = $show_header ?? true;

?>
<!doctype html>
<html lang="<?php echo text($language_iso_code); ?>">
<head>
    <title><?php echo xlt($page_title); ?></title>
    <?php Header::setupHeader(['common', 'opener']); ?>
    <link rel="stylesheet" href="<?php echo $GLOBALS['webroot'] ?>/public/assets/dashboard/css/dashboard.css?v=<?php echo $v_js_includes; ?>">
    <link rel="stylesheet" href="<?php echo $GLOBALS['webroot'] ?>/library/fontawesome/css/all.min.css?v=<?php echo $v_js_includes; ?>">
    <link rel="stylesheet" href="<?php echo $GLOBALS['webroot'] ?>/interface/main/modern_ui.css?v=<?php echo $v_js_includes; ?>">
    
    <style>
        /* Modern Layout Specific Styles */
        .modern-layout {
            display: flex;
            min-height: 100vh;
            background: #F7F8FC;
        }
        
        .modern-sidebar {
            width: 260px;
            background: #fff;
            box-shadow: 2px 0 10px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
            align-items: stretch;
            padding: 0;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 100;
            overflow-y: auto;
        }
        .modern-sidebar .sidebar-logo img {
            max-width: 420px;
            width: auto;
            height: 96px;
            object-fit: contain;
        }
        
        .modern-main {
            flex: 1;
            margin-left: 260px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        
        .modern-header {
            background: white;
            padding: 20px 30px;
            border-bottom: 1px solid #EAEAEA;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .modern-content {
            flex: 1;
            padding: 30px;
            background: #F7F8FC;
        }
        
        .modern-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 25px;
            margin-bottom: 20px;
        }
        
        .modern-form-group {
            margin-bottom: 20px;
        }
        
        .modern-label {
            display: block;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }
        
        .modern-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #E1E5E9;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }
        
        .modern-input:focus {
            outline: none;
            border-color: #4A90E2;
            box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
        }
        
        .modern-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .modern-btn-primary {
            background: linear-gradient(135deg, #4A90E2 0%, #357ABD 100%);
            color: white;
        }
        
        .modern-btn-primary:hover {
            background: linear-gradient(135deg, #357ABD 0%, #2E6DA4 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(74, 144, 226, 0.3);
        }
        
        .modern-btn-secondary {
            background: #F8F9FA;
            color: #495057;
            border: 2px solid #E1E5E9;
        }
        
        .modern-btn-secondary:hover {
            background: #E9ECEF;
            border-color: #CED4DA;
        }
        
        .modern-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .modern-table th {
            background: #F8F9FA;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #E1E5E9;
        }
        
        .modern-table td {
            padding: 15px;
            border-bottom: 1px solid #E1E5E9;
            color: #333;
        }
        
        .modern-table tr:hover {
            background: #F8F9FA;
        }
        
        .modern-nav-item {
            display: flex;
            align-items: center;
            padding: 16px 32px;
            color: #333;
            font-size: 1.08rem;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s, color 0.2s;
            border-left: 4px solid transparent;
            text-decoration: none;
        }
        
        .modern-nav-item:hover {
            background: #F0F5FE;
            color: #4A90E2;
            border-left: 4px solid #4A90E2;
            text-decoration: none;
        }
        
        .modern-nav-item.active {
            background: #F0F5FE;
            color: #4A90E2;
            border-left: 4px solid #4A90E2;
        }
        
        .modern-nav-item i {
            margin-right: 16px;
            font-size: 1.2em;
            width: 22px;
            text-align: center;
        }
        
        @media (max-width: 768px) {
            .modern-sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .modern-sidebar.open {
                transform: translateX(0);
            }
            
            .modern-main {
                margin-left: 0;
            }
            
            .modern-content {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="modern-layout">
        <?php if ($show_sidebar): ?>
        <!-- Modern Sidebar -->
        <aside class="modern-sidebar" id="modern-sidebar">
            <div class="sidebar-logo">
                <img src="<?php echo $GLOBALS['webroot'] ?>/public/images/JACtrac.jpg" alt="JACtrac">
            </div>
            <nav class="sidebar-nav">
                <!-- Main Menu Section -->
                <ul>
                    <!-- Remove Dashboard menu item -->
                    <!-- <li class="modern-nav-item <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>" data-url="dashboard.php">
                        <a href="dashboard.php"><i class="fas fa-th-large"></i> <?php echo xlt("Dashboard"); ?></a>
                    </li> -->
                    <li class="modern-nav-item <?php echo ($current_page == 'main_info.php') ? 'active' : ''; ?>" data-url="main_info.php">
                        <a href="main_info.php"><i class="fas fa-calendar"></i> <?php echo xlt("Calendar"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="finder/dynamic_finder.php">
                        <a href="finder/dynamic_finder.php"><i class="fas fa-search"></i> <?php echo xlt("Finder"); ?></a>
                    </li>
                    <!-- Hide Flow Board menu item -->
                    <!-- <li class="modern-nav-item" data-url="../patient_tracker/patient_tracker.php?skip_timeout_reset=1">
                        <a href="../patient_tracker/patient_tracker.php?skip_timeout_reset=1"><i class="fas fa-users"></i> <?php echo xlt("Flow Board"); ?></a>
                    </li> -->
                    <!-- Hide Messages menu item -->
                    <!-- <li class="modern-nav-item" data-url="messages/messages.php?form_active=1">
                        <a href="messages/messages.php?form_active=1"><i class="fas fa-envelope"></i> <?php echo xlt("Messages"); ?></a>
                    </li> -->
                    <!-- Hide Recalls menu item -->
                    <!-- <li class="modern-nav-item" data-url="messages/messages.php?go=Recalls">
                        <a href="messages/messages.php?go=Recalls"><i class="fas fa-bell"></i> <?php echo xlt("Recalls"); ?></a>
                    </li> -->
                    <!-- Hide Miscellaneous menu item -->
                    <!-- <li class="modern-nav-item <?php echo ($current_page == 'misc.php') ? 'active' : ''; ?>" data-url="misc.php">
                        <a href="misc.php"><i class="fas fa-ellipsis-h"></i> <?php echo xlt("Miscellaneous"); ?></a>
                    </li> -->
                    <!-- Hide Procedures menu item -->
                    <!-- <li class="modern-nav-item <?php echo ($current_page == 'procedures.php') ? 'active' : ''; ?>" data-url="procedures.php">
                        <a href="procedures.php"><i class="fas fa-vials"></i> <?php echo xlt("Procedures"); ?></a>
                    </li> -->
                    
                    <!-- Patients Section -->
                    <li class="modern-nav-item" data-url="../patient_file/summary/demographics.php">
                        <a href="../patient_file/summary/demographics.php"><i class="fas fa-user"></i> <?php echo xlt("Patient Summary"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="../new/new_comprehensive.php">
                        <a href="../new/new_comprehensive.php"><i class="fas fa-user-plus"></i> <?php echo xlt("New Patient"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="../patient_file/history/encounters.php">
                        <a href="../patient_file/history/encounters.php"><i class="fas fa-stethoscope"></i> <?php echo xlt("Visit History"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="../patient_file/encounter/encounter_top.php">
                        <a href="../patient_file/encounter/encounter_top.php"><i class="fas fa-clipboard-list"></i> <?php echo xlt("Current Visit"); ?></a>
                    </li>
                    
                    <!-- Reports Section -->
                    <li class="modern-nav-item" data-url="../reports/clinical_reports.php">
                        <a href="../reports/clinical_reports.php"><i class="fas fa-chart-bar"></i> <?php echo xlt("Clinical Reports"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="../reports/financial_reports.php">
                        <a href="../reports/financial_reports.php"><i class="fas fa-dollar-sign"></i> <?php echo xlt("Financial Reports"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="../reports/appointments_report.php">
                        <a href="../reports/appointments_report.php"><i class="fas fa-calendar-check"></i> <?php echo xlt("Appointments"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="../reports/encounters_report.php">
                        <a href="../reports/encounters_report.php"><i class="fas fa-file-medical"></i> <?php echo xlt("Encounters"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="../reports/patient_flow_board_report.php">
                        <a href="../reports/patient_flow_board_report.php"><i class="fas fa-project-diagram"></i> <?php echo xlt("Patient Flow"); ?></a>
                    </li>
                    
                    <!-- Administration Section -->
                    <li class="modern-nav-item" data-url="../super/edit_globals.php">
                        <a href="../super/edit_globals.php"><i class="fas fa-cogs"></i> <?php echo xlt("Globals"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="../usergroup/adminacl.php">
                        <a href="../usergroup/adminacl.php"><i class="fas fa-users-cog"></i> <?php echo xlt("Users"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="../super/edit_layout.php">
                        <a href="../super/edit_layout.php"><i class="fas fa-edit"></i> <?php echo xlt("Layouts"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="../super/edit_list.php">
                        <a href="../super/edit_list.php"><i class="fas fa-list"></i> <?php echo xlt("Lists"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="../usergroup/addrbook_list.php">
                        <a href="../usergroup/addrbook_list.php"><i class="fas fa-address-book"></i> <?php echo xlt("Address Book"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="../super/manage_site_files.php">
                        <a href="../super/manage_site_files.php"><i class="fas fa-folder"></i> <?php echo xlt("Files"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="backup.php">
                        <a href="backup.php"><i class="fas fa-database"></i> <?php echo xlt("Backup"); ?></a>
                    </li>
                    
                    <!-- Tools Section -->
                    <li class="modern-nav-item" data-url="../fax/faxq.php">
                        <a href="../fax/faxq.php"><i class="fas fa-fax"></i> <?php echo xlt("Fax/Scan"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="../main/authorizations/authorizations.php">
                        <a href="../main/authorizations/authorizations.php"><i class="fas fa-key"></i> <?php echo xlt("Authorizations"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="../reports/patient_edu_web_lookup.php">
                        <a href="../reports/patient_edu_web_lookup.php"><i class="fas fa-graduation-cap"></i> <?php echo xlt("Patient Education"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="../custom/chart_tracker.php">
                        <a href="../custom/chart_tracker.php"><i class="fas fa-chart-line"></i> <?php echo xlt("Chart Tracker"); ?></a>
                    </li>
                    
                    <!-- Inventory Section -->
                    <li class="modern-nav-item" data-url="../drugs/drug_inventory.php">
                        <a href="../drugs/drug_inventory.php"><i class="fas fa-boxes"></i> <?php echo xlt("Inventory"); ?></a>
                    </li>
                    
                    <!-- POS Section -->
                    <li class="modern-nav-item" data-url="../pos/pos_modal.php">
                        <a href="../pos/pos_modal.php"><i class="fas fa-cash-register"></i> <?php echo xlt("POS"); ?></a>
                    </li>
                </ul>
                
                <!-- Settings & Logout -->
                <ul class="sidebar-bottom">
                    <li class="modern-nav-item" data-url="../super/edit_globals.php?mode=user">
                        <a href="../super/edit_globals.php?mode=user"><i class="fas fa-cogs"></i> <?php echo xlt("Application Settings"); ?></a>
                    </li>
                    <li class="modern-nav-item">
                        <a href="../main/logout.php"><i class="fas fa-sign-out-alt"></i> <?php echo xlt("Log Out"); ?></a>
                    </li>
                </ul>
            </nav>
        </aside>
        <?php endif; ?>
        
        <!-- Main Content Area -->
        <main class="modern-main">
            <?php if ($show_header): ?>
            <!-- Modern Header -->
            <header class="modern-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="h3 mb-0"><?php echo xlt($page_title); ?></h1>
                        <p class="text-muted mb-0"><?php echo xlt("OpenEMR Healthcare Management System"); ?></p>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <div class="user-info" style="display:none;">
                            <span class="text-muted"><?php echo xlt("Welcome"); ?>,</span>
                            <strong><?php echo text($_SESSION['authUser']); ?></strong>
                        </div>
                        <button class="modern-btn modern-btn-secondary d-md-none" id="mobile-menu-toggle">
                            <i class="fas fa-bars"></i>
                        </button>
                    </div>
                </div>
            </header>
            <?php endif; ?>
            
            <!-- Content Area -->
            <div class="modern-content">
                <?php if ($content_url): ?>
                <!-- Iframe Content -->
                <div class="content-container">
                    <div class="loading-indicator" id="loading">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p><?php echo xlt("Loading..."); ?></p>
                    </div>
                    <iframe id="content-frame" class="content-area" src="<?php echo $content_url; ?>"></iframe>
                </div>
                <?php else: ?>
                <!-- Direct Content -->
                <div class="modern-card">
                    <?php echo $content ?? ""; ?>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <script src="<?php echo $GLOBALS['webroot'] . '/library/js/jquery.min.js?v=' . $v_js_includes; ?>"></script>
    <script>
        // Modern Layout JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu toggle
            const mobileToggle = document.getElementById('mobile-menu-toggle');
            const sidebar = document.getElementById('modern-sidebar');
            
            if (mobileToggle && sidebar) {
                mobileToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('open');
                });
            }
            
            // Navigation item highlighting
            const navItems = document.querySelectorAll('.modern-nav-item[data-url]');
            navItems.forEach(item => {
                item.addEventListener('click', function() {
                    navItems.forEach(i => i.classList.remove('active'));
                    this.classList.add('active');
                });
            });
            
            // Iframe loading
            const contentFrame = document.getElementById('content-frame');
            const loadingIndicator = document.getElementById('loading');
            
            if (contentFrame && loadingIndicator) {
                contentFrame.onload = function() {
                    loadingIndicator.style.display = 'none';
                    contentFrame.style.display = 'block';
                };
            }
        });
    </script>
</body>
</html> 
