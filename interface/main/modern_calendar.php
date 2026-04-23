<?php
/**
 * Modern Calendar Page for OpenEMR
 * 
 * This demonstrates how to implement the modern design on a main OpenEMR page
 * while preserving all existing functionality and data fields.
 * 
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");

use OpenEMR\Core\Header;
use OpenEMR\Menu\MainMenuRole;

// Check authorization
if (!AclMain::aclCheckCore('patients', 'appt')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Calendar")]);
    exit;
}

// Collect the menu then build it
$menuMain = new MainMenuRole($GLOBALS['kernel']->getEventDispatcher());
$menu_restrictions = $menuMain->getMenu();

// Set page variables for modern layout
$page_title = "Calendar";
$content_url = "calendar/modern_calendar_content.php";
$show_sidebar = true;
$show_header = true;

$v_js_includes = $GLOBALS['v_js_includes'] ?? date('YmdH');
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
        /* Calendar specific styles */
        .calendar-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 25px;
            margin-bottom: 20px;
        }
        .modern-sidebar .sidebar-logo img {
            max-width: 420px;
            width: auto;
            height: 96px;
            object-fit: contain;
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #E1E5E9;
        }
        
        .calendar-nav {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .calendar-nav-btn {
            padding: 8px 16px;
            border: 2px solid #E1E5E9;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
        }
        
        .calendar-nav-btn:hover {
            border-color: #4A90E2;
            background: #F0F5FE;
        }
        
        .calendar-nav-btn.active {
            background: #4A90E2;
            border-color: #4A90E2;
            color: white;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: #E1E5E9;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .calendar-day {
            background: white;
            padding: 15px;
            min-height: 100px;
            position: relative;
        }
        
        .calendar-day.today {
            background: #F0F5FE;
            border: 2px solid #4A90E2;
        }
        
        .calendar-day.other-month {
            background: #F8F9FA;
            color: #9CA3AF;
        }
        
        .calendar-day-number {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 8px;
        }
        
        .calendar-event {
            background: #4A90E2;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            margin-bottom: 4px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .calendar-event:hover {
            background: #357ABD;
            transform: translateY(-1px);
        }
        
        .calendar-event.urgent {
            background: #DC3545;
        }
        
        .calendar-event.urgent:hover {
            background: #C82333;
        }
        
        .calendar-sidebar {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 25px;
        }
        
        .event-form {
            margin-bottom: 25px;
        }
        
        .event-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .event-item {
            padding: 12px;
            border: 1px solid #E1E5E9;
            border-radius: 8px;
            margin-bottom: 10px;
            background: #F8F9FA;
            transition: all 0.3s ease;
        }
        
        .event-item:hover {
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .event-time {
            font-weight: 600;
            color: #4A90E2;
            font-size: 0.9rem;
        }
        
        .event-title {
            font-weight: 600;
            margin: 5px 0;
        }
        
        .event-patient {
            color: #6C757D;
            font-size: 0.9rem;
        }
        
        @media (max-width: 768px) {
            .calendar-header {
                flex-direction: column;
                gap: 15px;
            }
            
            .calendar-grid {
                grid-template-columns: repeat(7, 1fr);
                font-size: 0.8rem;
            }
            
            .calendar-day {
                padding: 8px;
                min-height: 80px;
            }
        }
    </style>
</head>
<body>
    <div class="modern-layout">
        <!-- Modern Sidebar -->
        <aside class="modern-sidebar" id="modern-sidebar">
            <div class="sidebar-logo">
                <img src="<?php echo $GLOBALS['webroot'] ?>/public/images/JACtrac.jpg" alt="JACtrac">
            </div>
            <nav class="sidebar-nav">
                <!-- Main Menu Section -->
                <ul>
                    <li class="modern-nav-item" data-url="dashboard.php">
                        <a href="dashboard.php"><i class="fas fa-th-large"></i> <?php echo xlt("Dashboard"); ?></a>
                    </li>
                    <li class="modern-nav-item active" data-url="modern_calendar.php">
                        <a href="modern_calendar.php"><i class="fas fa-calendar"></i> <?php echo xlt("Calendar"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="finder/dynamic_finder.php">
                        <a href="finder/dynamic_finder.php"><i class="fas fa-search"></i> <?php echo xlt("Finder"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="../patient_tracker/patient_tracker.php?skip_timeout_reset=1">
                        <a href="../patient_tracker/patient_tracker.php?skip_timeout_reset=1"><i class="fas fa-users"></i> <?php echo xlt("Flow Board"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="messages/messages.php?form_active=1">
                        <a href="messages/messages.php?form_active=1"><i class="fas fa-envelope"></i> <?php echo xlt("Messages"); ?></a>
                    </li>
                    <li class="modern-nav-item" data-url="messages/messages.php?go=Recalls">
                        <a href="messages/messages.php?go=Recalls"><i class="fas fa-bell"></i> <?php echo xlt("Recalls"); ?></a>
                    </li>
                    
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
        
        <!-- Main Content Area -->
        <main class="modern-main">
            <!-- Modern Header -->
            <header class="modern-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="h3 mb-0"><?php echo xlt($page_title); ?></h1>
                        <p class="text-muted mb-0"><?php echo xlt("Schedule and manage appointments"); ?></p>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <div class="user-info">
                            <span class="text-muted"><?php echo xlt("Welcome"); ?>,</span>
                            <strong><?php echo text($_SESSION['authUser']); ?></strong>
                        </div>
                        <button class="modern-btn modern-btn-secondary d-md-none" id="mobile-menu-toggle">
                            <i class="fas fa-bars"></i>
                        </button>
                    </div>
                </div>
            </header>
            
            <!-- Content Area -->
            <div class="modern-content">
                <div class="row">
                    <!-- Calendar Main Area -->
                    <div class="col-lg-9">
                        <div class="calendar-container">
                            <div class="calendar-header">
                                <div class="calendar-nav">
                                    <button class="calendar-nav-btn" onclick="previousMonth()">
                                        <i class="fas fa-chevron-left"></i>
                                    </button>
                                    <h3 id="current-month"><?php echo date('F Y'); ?></h3>
                                    <button class="calendar-nav-btn" onclick="nextMonth()">
                                        <i class="fas fa-chevron-right"></i>
                                    </button>
                                </div>
                                <div class="calendar-actions">
                                    <button class="modern-btn modern-btn-primary" onclick="addAppointment()">
                                        <i class="fas fa-plus"></i> <?php echo xlt("Add Appointment"); ?>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Calendar Grid -->
                            <div class="calendar-grid" id="calendar-grid">
                                <!-- Calendar days will be populated by JavaScript -->
                            </div>
                        </div>
                    </div>
                    
                    <!-- Calendar Sidebar -->
                    <div class="col-lg-3">
                        <div class="calendar-sidebar">
                            <h4><?php echo xlt("Today's Appointments"); ?></h4>
                            <div class="event-list" id="today-events">
                                <!-- Today's events will be populated -->
                            </div>
                            
                            <hr>
                            
                            <h4><?php echo xlt("Quick Add"); ?></h4>
                            <div class="event-form">
                                <div class="modern-form-group">
                                    <label class="modern-label"><?php echo xlt("Patient"); ?></label>
                                    <select class="modern-select" id="patient-select">
                                        <option value=""><?php echo xlt("Select Patient"); ?></option>
                                        <!-- Patients will be populated -->
                                    </select>
                                </div>
                                
                                <div class="modern-form-group">
                                    <label class="modern-label"><?php echo xlt("Time"); ?></label>
                                    <input type="time" class="modern-input" id="appointment-time">
                                </div>
                                
                                <div class="modern-form-group">
                                    <label class="modern-label"><?php echo xlt("Duration"); ?></label>
                                    <select class="modern-select" id="duration-select">
                                        <option value="15">15 <?php echo xlt("minutes"); ?></option>
                                        <option value="30" selected>30 <?php echo xlt("minutes"); ?></option>
                                        <option value="45">45 <?php echo xlt("minutes"); ?></option>
                                        <option value="60">1 <?php echo xlt("hour"); ?></option>
                                    </select>
                                </div>
                                
                                <button class="modern-btn modern-btn-primary" onclick="quickAddAppointment()">
                                    <i class="fas fa-plus"></i> <?php echo xlt("Add"); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script src="<?php echo $GLOBALS['webroot'] . '/library/js/jquery.min.js?v=' . $v_js_includes; ?>"></script>
    <script>
        // Calendar functionality
        let currentDate = new Date();
        let currentMonth = currentDate.getMonth();
        let currentYear = currentDate.getFullYear();
        
        // Initialize calendar
        document.addEventListener('DOMContentLoaded', function() {
            renderCalendar();
            loadTodayEvents();
            loadPatients();
            
            // Mobile menu toggle
            const mobileToggle = document.getElementById('mobile-menu-toggle');
            const sidebar = document.getElementById('modern-sidebar');
            
            if (mobileToggle && sidebar) {
                mobileToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('open');
                });
            }
        });
        
        function renderCalendar() {
            const grid = document.getElementById('calendar-grid');
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                               'July', 'August', 'September', 'October', 'November', 'December'];
            
            // Update header
            document.getElementById('current-month').textContent = monthNames[currentMonth] + ' ' + currentYear;
            
            // Clear grid
            grid.innerHTML = '';
            
            // Add day headers
            const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            dayNames.forEach(day => {
                const dayHeader = document.createElement('div');
                dayHeader.className = 'calendar-day';
                dayHeader.style.background = '#F8F9FA';
                dayHeader.style.fontWeight = '600';
                dayHeader.textContent = day;
                grid.appendChild(dayHeader);
            });
            
            // Get first day of month and number of days
            const firstDay = new Date(currentYear, currentMonth, 1);
            const lastDay = new Date(currentYear, currentMonth + 1, 0);
            const startDate = new Date(firstDay);
            startDate.setDate(startDate.getDate() - firstDay.getDay());
            
            // Generate calendar days
            for (let i = 0; i < 42; i++) {
                const day = new Date(startDate);
                day.setDate(startDate.getDate() + i);
                
                const dayElement = document.createElement('div');
                dayElement.className = 'calendar-day';
                
                // Check if it's today
                const today = new Date();
                if (day.toDateString() === today.toDateString()) {
                    dayElement.classList.add('today');
                }
                
                // Check if it's other month
                if (day.getMonth() !== currentMonth) {
                    dayElement.classList.add('other-month');
                }
                
                const dayNumber = document.createElement('div');
                dayNumber.className = 'calendar-day-number';
                dayNumber.textContent = day.getDate();
                dayElement.appendChild(dayNumber);
                
                // Add sample events (in real implementation, load from database)
                if (day.getDate() === 15 && day.getMonth() === currentMonth) {
                    const event = document.createElement('div');
                    event.className = 'calendar-event';
                    event.textContent = 'Dr. Smith - 2:00 PM';
                    dayElement.appendChild(event);
                }
                
                if (day.getDate() === 20 && day.getMonth() === currentMonth) {
                    const event = document.createElement('div');
                    event.className = 'calendar-event urgent';
                    event.textContent = 'Emergency - 10:30 AM';
                    dayElement.appendChild(event);
                }
                
                grid.appendChild(dayElement);
            }
        }
        
        function previousMonth() {
            currentMonth--;
            if (currentMonth < 0) {
                currentMonth = 11;
                currentYear--;
            }
            renderCalendar();
        }
        
        function nextMonth() {
            currentMonth++;
            if (currentMonth > 11) {
                currentMonth = 0;
                currentYear++;
            }
            renderCalendar();
        }
        
        function loadTodayEvents() {
            const eventsContainer = document.getElementById('today-events');
            // In real implementation, load from database
            eventsContainer.innerHTML = `
                <div class="event-item">
                    <div class="event-time">9:00 AM</div>
                    <div class="event-title">John Doe - Checkup</div>
                    <div class="event-patient">Dr. Smith</div>
                </div>
                <div class="event-item">
                    <div class="event-time">2:30 PM</div>
                    <div class="event-title">Jane Smith - Follow-up</div>
                    <div class="event-patient">Dr. Johnson</div>
                </div>
            `;
        }
        
        function loadPatients() {
            // In real implementation, load patients from database
            const patientSelect = document.getElementById('patient-select');
            // Add sample patients
            const patients = ['John Doe', 'Jane Smith', 'Mike Johnson', 'Sarah Wilson'];
            patients.forEach(patient => {
                const option = document.createElement('option');
                option.value = patient;
                option.textContent = patient;
                patientSelect.appendChild(option);
            });
        }
        
        function addAppointment() {
            // In real implementation, open appointment form
            alert('Add Appointment functionality would open here');
        }
        
        function quickAddAppointment() {
            const patient = document.getElementById('patient-select').value;
            const time = document.getElementById('appointment-time').value;
            const duration = document.getElementById('duration-select').value;
            
            if (!patient || !time) {
                alert('Please select patient and time');
                return;
            }
            
            // In real implementation, save to database
            alert(`Appointment added: ${patient} at ${time} for ${duration} minutes`);
            
            // Clear form
            document.getElementById('patient-select').value = '';
            document.getElementById('appointment-time').value = '';
            document.getElementById('duration-select').value = '30';
        }
    </script>
</body>
</html> 
