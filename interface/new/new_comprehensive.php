<?php
// Enable error reporting at the very top
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * New patient or search patient.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Tyler Wrenn <tyler@tylerwrenn.com>
 * @copyright Copyright (c) 2009-2021 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2017-2019 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2020 Tyler Wrenn <tyler@tylerwrenn.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/validation/LBF_Validation.php");
require_once("$srcdir/patientvalidation.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;

// Debug ACL permissions


// Check authorization.
if (!AclMain::aclCheckCore('patients', 'demo', '', array('write','addonly'))) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Search or Add Patient")]);
    exit;
}

$CPR = 4; // cells per row

$searchcolor = empty($GLOBALS['layout_search_color']) ?
  'var(--yellow)' : $GLOBALS['layout_search_color'];

$WITH_SEARCH = ($GLOBALS['full_new_patient_form'] == '1' || $GLOBALS['full_new_patient_form'] == '2' );
$SHORT_FORM  = ($GLOBALS['full_new_patient_form'] == '2' || $GLOBALS['full_new_patient_form'] == '3' || $GLOBALS['full_new_patient_form'] == '4');

$grparr = array();
getLayoutProperties('DEM', $grparr, '*');

$TOPCPR = empty($grparr['']['grp_columns']) ? 4 : $grparr['']['grp_columns'];

// Dynamically fetch all groups for DEM
$groups_result = sqlStatement("SELECT * FROM layout_group_properties WHERE grp_form_id = 'DEM' AND grp_group_id != '' ORDER BY grp_seq, grp_group_id");
$groups = array();
while ($row = sqlFetchArray($groups_result)) {
    $groups[] = $row;
}

function getLayoutRes()
{
    global $SHORT_FORM;
    return sqlStatement("SELECT * FROM layout_options " .
    "WHERE form_id = 'DEM' AND uor > 0 AND field_id != '' " .
    ($SHORT_FORM ? "AND ( uor > 1 OR edit_options LIKE '%N%' ) " : "") .
    "ORDER BY group_id, seq");
}

// Determine layout field search treatment from its data type:
// 1 = text field
// 2 = select list
// 0 = not searchable
//
function getSearchClass($data_type)
{
    switch ($data_type) {
        case 1: // single-selection list
        case 10: // local provider list
        case 11: // provider list
        case 12: // pharmacy list
        case 13: // squads
        case 14: // address book list
        case 26: // single-selection list with add
        case 35: // facilities
            return 2;
        case 2: // text field
        case 3: // textarea
        case 4: // date
            return 1;
    }

    return 0;
}

// Helper functions for form field generation
function end_cell()
{
    global $item_count;
    if ($item_count > 0) {
        echo "</div>"; // end BS column
        $item_count = 0;
    }
}

function end_row()
{
    global $cell_count, $CPR, $BS_COL_CLASS;
    end_cell();
    if ($cell_count > 0 && $cell_count < $CPR) {
        // Create a cell occupying the remaining bootstrap columns.
        // BS columns will be less than 12 if $CPR is not 2, 3, 4, 6 or 12.
        $bs_cols_remaining = ($CPR - $cell_count) * intval(12 / $CPR);
        echo "<div class='$BS_COL_CLASS-$bs_cols_remaining'></div>";
    }
    if ($cell_count > 0) {
        echo "</div><!-- End BS row -->\n";
    }
    $cell_count = 0;
}

function end_group()
{
    global $last_group, $SHORT_FORM;
    if (strlen($last_group) > 0) {
        end_row();
        echo "</div>\n"; // end BS container
        if (!$SHORT_FORM) {
            echo "</div>\n";
        }
    }
    echo "</div>";
}

// Custom function to display layout group with multi-field layout
function display_layout_group_custom($formtype, $group_id, $result1 = [], $result2 = '') {
    global $grparr;
    
    $grparr = [];
    getLayoutProperties($formtype, $grparr, '*');
    $subtitle = empty($grparr[$group_id]['grp_subtitle']) ? '' : xl_layout_label($grparr[$group_id]['grp_subtitle']);

    $group_fields_query = sqlStatement("SELECT * FROM layout_options WHERE form_id = ? AND uor > 0 AND group_id = ? ORDER BY seq", array($formtype, $group_id));

    $fields = [];
    while ($group_fields_query && $row = sqlFetchArray($group_fields_query)) {
        $fields[] = $row;
    }

    // Group fields by type for better organization
    $field_groups = [
        'demographics' => [],
        'contact' => [],
        'address' => [],
        'emergency' => [],
        'insurance' => [],
        'other' => []
    ];

    foreach ($fields as $field) {
        $field_name = $field['field_id'];
        
        if (strpos($field_name, 'fname') !== false || strpos($field_name, 'lname') !== false || 
            strpos($field_name, 'mname') !== false || strpos($field_name, 'DOB') !== false ||
            strpos($field_name, 'sex') !== false || strpos($field_name, 'marital') !== false) {
            $field_groups['demographics'][] = $field;
        } elseif (strpos($field_name, 'phone') !== false || strpos($field_name, 'email') !== false ||
                   strpos($field_name, 'contact') !== false) {
            $field_groups['contact'][] = $field;
        } elseif (strpos($field_name, 'address') !== false || strpos($field_name, 'city') !== false ||
                   strpos($field_name, 'state') !== false || strpos($field_name, 'zip') !== false ||
                   strpos($field_name, 'country') !== false) {
            $field_groups['address'][] = $field;
        } elseif (strpos($field_name, 'emergency') !== false || strpos($field_name, 'guardian') !== false) {
            $field_groups['emergency'][] = $field;
        } elseif (strpos($field_name, 'insurance') !== false || strpos($field_name, 'policy') !== false ||
                   strpos($field_name, 'group') !== false) {
            $field_groups['insurance'][] = $field;
        } else {
            $field_groups['other'][] = $field;
        }
    }

    // Display each field group
    foreach ($field_groups as $group_type => $group_fields) {
        if (empty($group_fields)) continue;
        
        $group_titles = [
            'demographics' => 'Demographics',
            'contact' => 'Contact Information',
            'address' => 'Address',
            'emergency' => 'Emergency Contact',
            'insurance' => 'Insurance',
            'other' => 'Additional Information'
        ];
        
        echo "<div class='field-group'>";
        echo "<div class='field-group-title'>";
        echo "<i class='fas fa-" . getGroupIcon($group_type) . "'></i>";
        echo xlt($group_titles[$group_type]);
        echo "</div>";
        
        // Arrange fields in rows based on group type
        $fields_per_row = getFieldsPerRow($group_type);
        $field_chunks = array_chunk($group_fields, $fields_per_row);
        
        foreach ($field_chunks as $row_fields) {
            echo "<div class='field-row field-group-" . count($row_fields) . "'>";
            
            foreach ($row_fields as $field) {
                $currvalue = get_layout_form_value($field, '', $result1, $result2);
                $label = xl_layout_label($field['title']);
                $field_id = 'form_' . $field['field_id'];
                $required = ($field['uor'] == 2) ? 'required' : '';
                
                // Field information for data_type 26
                if ($field['data_type'] == 26) {
                    // List field processing
                }
                
                echo "<div class='field-item'>";
                echo "<label for='" . attr($field_id) . "' class='form-label " . $required . "'>" . text($label) . "</label>";
                
                if ($field['edit_options'] == 'H') {
                    echo "<div class='form-control' id='" . attr($field_id) . "'>";
                    echo generate_display_field($field, $currvalue);
                    echo "</div>";
                } else {
                    generate_form_field($field, $currvalue);
                }
                
                echo "</div>";
            }
            
            echo "</div>";
        }
        
        echo "</div>";
    }
}

function getGroupIcon($group_type) {
    $icons = [
        'demographics' => 'user',
        'contact' => 'phone',
        'address' => 'map-marker-alt',
        'emergency' => 'exclamation-triangle',
        'insurance' => 'shield-alt',
        'other' => 'info-circle'
    ];
    
    return $icons[$group_type] ?? 'info-circle';
}

function getFieldsPerRow($group_type) {
    $fields_per_row = [
        'demographics' => 3,
        'contact' => 2,
        'address' => 2,
        'emergency' => 2,
        'insurance' => 3,
        'other' => 2
    ];
    
    return $fields_per_row[$group_type] ?? 2;
}

$fres = getLayoutRes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo xlt('New Patient Registration'); ?> - OpenEMR</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?php echo $GLOBALS['web_root']; ?>/public/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo $GLOBALS['web_root']; ?>/public/assets/css/fontawesome/css/all.min.css">
<style>
:root {
            --primary-color: #1e40af;
            --primary-light: #3b82f6;
            --primary-dark: #1e3a8a;
            --secondary-color: #64748b;
            --success-color: #059669;
            --warning-color: #d97706;
            --danger-color: #dc2626;
            --info-color: #0891b2;
            --light-color: #f8fafc;
            --dark-color: #1e293b;
            --border-color: #e2e8f0;
            --text-color: #334155;
            --text-muted: #64748b;
            --bg-light: #f1f5f9;
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --border-radius: 12px;
            --border-radius-sm: 8px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            box-sizing: border-box;
}

body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            color: var(--text-color);
            line-height: 1.6;
  margin: 0;
  padding: 0;
            min-height: 100vh;
        }

        /* Main Container - Full Width */
        .main-container {
            max-width: 100%;
            margin: 0;
            padding: 1rem;
            min-height: 100vh;
        }

        /* Form Layout - Full Width */
        .form-layout {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 1.5rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            border: 1px solid var(--border-color);
            height: calc(100vh - 2rem);
        }

        /* Sidebar Navigation */
        .sidebar-nav {
            background: var(--bg-light);
            padding: 1.5rem 1rem;
            border-right: 1px solid var(--border-color);
            overflow-y: auto;
        }

        .sidebar-header {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .sidebar-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--text-color);
            margin: 0 0 0.25rem 0;
        }

        .sidebar-subtitle {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin: 0;
        }

        .nav-items {
  display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .nav-item {
            background: white;
            border: 2px solid transparent;
            border-radius: var(--border-radius-sm);
            padding: 0.875rem 1rem;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            color: var(--text-color);
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .nav-item:hover {
            border-color: var(--primary-light);
            transform: translateX(4px);
            box-shadow: var(--shadow);
        }

        .nav-item.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            box-shadow: var(--shadow);
        }

        .nav-item.completed {
            background: var(--success-color);
  color: white;
            border-color: var(--success-color);
        }

        .nav-item.completed:hover {
            background: #047857;
        }

        .nav-icon {
            font-size: 1rem;
            width: 18px;
            text-align: center;
        }

        .nav-text {
            flex: 1;
            font-size: 0.875rem;
        }

        .nav-status {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--border-color);
            transition: var(--transition);
        }

        .nav-item.active .nav-status,
        .nav-item.completed .nav-status {
            background: white;
        }

        /* Main Content */
        .main-content {
            padding: 1.5rem;
            background: white;
            overflow-y: auto;
        }

        .content-section {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .content-section.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .section-header {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--bg-light);
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-color);
            margin: 0 0 0.25rem 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-subtitle {
            font-size: 0.9rem;
            color: var(--text-muted);
  margin: 0;
        }

        /* Field Grouping */
        .field-group {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-sm);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }

        .field-group-title {
            font-size: 1rem;
  font-weight: 600;
            color: var(--text-color);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border-color);
  display: flex;
  align-items: center;
            gap: 0.5rem;
        }

        .field-group-title i {
            color: var(--primary-color);
        }

        .field-row {
            display: grid;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .field-row:last-child {
            margin-bottom: 0;
        }

        .field-group-1 {
            grid-template-columns: 1fr;
        }

        .field-group-2 {
            grid-template-columns: 1fr 1fr;
        }

        .field-group-3 {
            grid-template-columns: 1fr 1fr 1fr;
        }

        .field-group-4 {
            grid-template-columns: 1fr 1fr 1fr 1fr;
        }

        .field-item {
            display: flex;
            flex-direction: column;
        }

        /* Form Controls */
        .form-label {
  font-weight: 600;
            color: var(--text-color);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .form-label.required::after {
            content: " *";
            color: var(--danger-color);
            font-weight: bold;
        }

        input[type="text"],
        input[type="email"],
        input[type="tel"],
        input[type="date"],
        input[type="number"],
        input[type="password"],
        select,
        textarea {
  width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius-sm);
            font-size: 0.95rem;
            transition: var(--transition);
            background: white;
            color: var(--text-color);
        }

        input:focus,
        select:focus,
        textarea:focus {
  outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
        }

        select {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.75rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            appearance: none;
        }

        textarea {
            min-height: 80px;
            resize: vertical;
        }

        .field-help {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }

        /* Form Actions */
        .form-actions {
            background: var(--bg-light);
            padding: 1.5rem;
            border-top: 1px solid var(--border-color);
  display: flex;
  justify-content: space-between;
  align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            position: sticky;
            bottom: 0;
            z-index: 10;
        }

        .action-group {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
}

        .btn {
            padding: 0.75rem 1.5rem;
  border: none;
            border-radius: var(--border-radius-sm);
            font-size: 0.95rem;
            font-weight: 600;
  cursor: pointer;
            transition: var(--transition);
  text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
}

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
  color: white;
}

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(30, 64, 175, 0.3);
        }

        .btn-secondary {
            background: var(--secondary-color);
            color: white;
        }

        .btn-secondary:hover {
            background: #475569;
            transform: translateY(-1px);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color) 0%, #10b981 100%);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(5, 150, 105, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color) 0%, #f59e0b 100%);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(217, 119, 6, 0.3);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info-color) 0%, #06b6d4 100%);
            color: white;
        }

        .btn-info:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(8, 145, 178, 0.3);
        }

        /* Alert Styles */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius-sm);
            margin-bottom: 1.5rem;
            border: 1px solid transparent;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .alert-info {
            background-color: #eff6ff;
            border-color: #bfdbfe;
            color: #1e40af;
        }

        .alert-warning {
            background-color: #fffbeb;
            border-color: #fed7aa;
            color: #92400e;
        }

        .alert-success {
            background-color: #f0fdf4;
            border-color: #bbf7d0;
            color: #166534;
        }

        .alert-icon {
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .form-layout {
                grid-template-columns: 200px 1fr;
            }
            
            .field-group-4 {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 1024px) {
            .form-layout {
                grid-template-columns: 1fr;
            }
            
            .sidebar-nav {
                border-right: none;
                border-bottom: 1px solid var(--border-color);
                max-height: 200px;
            }
            
            .nav-items {
                flex-direction: row;
                overflow-x: auto;
                padding-bottom: 0.5rem;
            }
            
            .nav-item {
                min-width: 180px;
                flex-shrink: 0;
            }
            
            .field-group-3 {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 0.5rem;
            }
            
            .form-layout {
                height: calc(100vh - 1rem);
            }
            
            .field-row {
                grid-template-columns: 1fr;
            }
            
            .field-group-2,
            .field-group-3,
            .field-group-4 {
                grid-template-columns: 1fr;
        }
        
            .form-actions {
            flex-direction: column;
                align-items: stretch;
            }
            
            .action-group {
                justify-content: center;
            }
            
            .btn {
                justify-content: center;
            width: 100%;
            }
        }

        /* Loading States */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 2px solid var(--border-color);
            border-top: 2px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Accessibility */
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        /* Focus indicators for keyboard navigation */
        .nav-item:focus,
        .btn:focus {
            outline: 2px solid var(--primary-color);
            outline-offset: 2px;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- Main Form -->
        <form action='new_comprehensive_save.php' name='demographics_form' id='DEM' method='post' onsubmit='return submitme(<?php echo $GLOBALS['new_validate'] ? 1 : 0;?>,event,"DEM",constraints)'>
            <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />

            <!-- Information Alert -->
            <div class="alert alert-info">
                <i class="fas fa-info-circle alert-icon"></i>
                <div>
                    <strong><?php echo xlt('Important'); ?>:</strong>
                    <?php echo xlt('Please complete all required fields marked with an asterisk (*). This information is essential for patient care and billing.'); ?>
                </div>
            </div>

            <div class="form-layout">
                <!-- Sidebar Navigation -->
                <nav class="sidebar-nav">
                    <div class="sidebar-header">
                        <h3 class="sidebar-title"><?php echo xlt('Patient Information'); ?></h3>
                        <p class="sidebar-subtitle"><?php echo xlt('Complete each section'); ?></p>
                    </div>
                    
                    <div class="nav-items">
<?php
                        // Define group icons and descriptions for better organization
                        $group_info = [
                            '1' => ['icon' => 'fas fa-user', 'title' => 'Demographics', 'desc' => 'Basic patient information'],
                            '2' => ['icon' => 'fas fa-map-marker-alt', 'title' => 'Contact', 'desc' => 'Address and contact details'],
                            '3' => ['icon' => 'fas fa-cog', 'title' => 'Choices', 'desc' => 'Preferences and settings'],
                            '6' => ['icon' => 'fas fa-info-circle', 'title' => 'Miscellaneous', 'desc' => 'Additional information'],
                            '8' => ['icon' => 'fas fa-shield-alt', 'title' => 'Guardian', 'desc' => 'Guardian information'],
                            '11' => ['icon' => 'fas fa-handshake', 'title' => 'Meet', 'desc' => 'Meeting preferences']
                        ];
                        
                        foreach ($groups as $i => $group): 
                            $group_id = $group['grp_group_id'];
                            $info = $group_info[$group_id] ?? ['icon' => 'fas fa-folder', 'title' => $group['grp_title'], 'desc' => ''];
                            $isActive = ($i === 0) ? 'active' : '';
                        ?>
                            <button type="button" 
                                    class="nav-item <?php echo $isActive; ?>" 
                                    data-group="<?php echo $group_id; ?>"
                                    onclick="showSection('<?php echo $group_id; ?>')"
                                    title="<?php echo xlt($info['desc']); ?>">
                                <i class="<?php echo $info['icon']; ?> nav-icon"></i>
                                <span class="nav-text"><?php echo text(xl_layout_label($info['title'])); ?></span>
                                <div class="nav-status"></div>
                            </button>
                        <?php endforeach; ?>
            </div>
                </nav>

                <!-- Main Content Area -->
                <main class="main-content">
                    <?php foreach ($groups as $i => $group): 
                        $group_id = $group['grp_group_id'];
                        $info = $group_info[$group_id] ?? ['icon' => 'fas fa-folder', 'title' => $group['grp_title'], 'desc' => ''];
                        $displayStyle = ($i === 0) ? 'block' : 'none';
                    ?>
                        <section class="content-section <?php echo ($i === 0) ? 'active' : ''; ?>" 
                                 id="section-<?php echo $group_id; ?>" 
                                 style="display: <?php echo $displayStyle; ?>;">
                            
                            <div class="section-header">
                                <h2 class="section-title">
                                    <i class="<?php echo $info['icon']; ?>"></i>
                                    <?php echo text(xl_layout_label($info['title'])); ?>
                                </h2>
                                <p class="section-subtitle"><?php echo xlt($info['desc']); ?></p>
                        </div>

                            <div class="form-content">
                                <?php display_layout_group_custom('DEM', $group_id); ?>
                        </div>
                        </section>
                    <?php endforeach; ?>
                </main>
    </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <div class="action-group">
                    <button type="button" class="btn btn-info" onclick="navigateSections('prev')" id="prevBtn">
                        <i class="fas fa-chevron-left"></i>
                        <?php echo xlt('Previous'); ?>
                    </button>
                    <button type="button" class="btn btn-info" onclick="navigateSections('next')" id="nextBtn">
                        <i class="fas fa-chevron-right"></i>
                        <?php echo xlt('Next'); ?>
                    </button>
                </div>
                
                <div class="action-group">
                    <button type="button" class="btn btn-warning" onclick="validateForm()">
                        <i class="fas fa-check-circle"></i>
                        <?php echo xlt('Validate'); ?>
                    </button>
                    <button type="button" class="btn btn-success" onclick="validateAndSubmit()">
                        <i class="fas fa-save"></i>
                        <?php echo xlt('Save Patient'); ?>
                    </button>
        </div>
                            </div>
                </form>
                </div>

    <script src="<?php echo $GLOBALS['web_root']; ?>/public/assets/js/jquery.min.js"></script>
    <script src="<?php echo $GLOBALS['web_root']; ?>/public/assets/js/bootstrap.bundle.min.js"></script>
    
    <!-- Fallback jQuery if local file fails to load -->
<script>
        if (typeof jQuery === 'undefined') {
            console.log('Local jQuery failed to load, loading from CDN...');
            document.write('<script src="https://code.jquery.com/jquery-3.6.0.min.js"><\/script>');
        }
    </script>
    
    <!-- include support for the list-add selectbox feature -->
    <?php include $GLOBALS['fileroot'] . "/library/options_listadd.inc"; ?>
    
    <!-- Ensure jQuery is loaded before initializing addtolist functionality -->
    <script>
        function initializeAddToList() {
            if (typeof jQuery === 'undefined') {
                console.log('jQuery still not loaded, retrying in 500ms...');
                setTimeout(initializeAddToList, 500);
                return;
            }
            
            console.log('jQuery loaded successfully, initializing addtolist functionality...');
            
            // Re-initialize the addtolist event handlers
            if (typeof window.oeUI !== 'undefined' && window.oeUI.optionWidgets) {
                console.log('oeUI.optionWidgets available, re-binding events...');
                
                // Remove existing event handlers
                $('.addtolist').off('click');
                
                // Re-bind event handlers
                $('.addtolist').on('click', function(evt) {
                    console.log('AddToList clicked:', this.id);
                    window.oeUI.optionWidgets.AddToList(this, evt);
                });
                
                console.log('AddToList event handlers re-bound successfully');
            } else {
                console.log('oeUI.optionWidgets not available');
            }
        }
        
        // Initialize when document is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeAddToList);
        } else {
            initializeAddToList();
        }
    </script>
    
    <script>
        let currentSection = '<?php echo $groups[0]['grp_group_id'] ?? '1'; ?>';
        const totalSections = <?php echo count($groups); ?>;
        let completedSections = new Set();
        const sectionIds = <?php echo json_encode(array_column($groups, 'grp_group_id')); ?>;

        // Debug function to check if addtolist buttons exist
        function debugAddToListButtons() {
            console.log('Checking for addtolist buttons...');
            const addButtons = document.querySelectorAll('.addtolist');
            console.log('Found', addButtons.length, 'addtolist buttons');
            
            let debugInfo = 'Found ' + addButtons.length + ' addtolist buttons<br>';
            
            addButtons.forEach((button, index) => {
                const buttonInfo = {
                    id: button.id,
                    fieldid: button.getAttribute('fieldid'),
                    value: button.value,
                    className: button.className
                };
                console.log('Button', index + 1, ':', buttonInfo);
                debugInfo += 'Button ' + (index + 1) + ': ' + buttonInfo.id + '<br>';
            });
            
            // Check if jQuery is loaded
            if (typeof $ !== 'undefined') {
                console.log('jQuery is loaded');
                console.log('jQuery version:', $.fn.jquery);
                debugInfo += 'jQuery is loaded (version: ' + $.fn.jquery + ')<br>';
                
                // Check if event handlers are attached
                const events = $._data(document, 'events') || {};
                console.log('Document events:', events);
                debugInfo += 'Document events: ' + Object.keys(events).length + ' types<br>';
                
                // Check if addtolist event handlers are attached
                const addtolistEvents = $._data(addButtons[0], 'events');
                if (addtolistEvents && addtolistEvents.click) {
                    debugInfo += 'AddToList event handlers: ' + addtolistEvents.click.length + ' handlers attached<br>';
                } else {
                    debugInfo += 'AddToList event handlers: NOT attached<br>';
                }
                
                // Check if oeUI is available
                if (typeof window.oeUI !== 'undefined' && window.oeUI.optionWidgets) {
                    debugInfo += 'oeUI.optionWidgets: Available<br>';
                } else {
                    debugInfo += 'oeUI.optionWidgets: NOT available<br>';
                }
            } else {
                console.log('jQuery is NOT loaded');
                debugInfo += 'jQuery is NOT loaded<br>';
            }
            
            // Update debug output
            const debugContent = document.getElementById('debug-content');
            if (debugContent) {
                debugContent.innerHTML = debugInfo;
            }
        }

        // Debug ACL permissions
        function debugACLPermissions() {
            console.log('Debugging ACL permissions...');
            let debugInfo = 'ACL Debug Info:<br>';
            
            // This will be populated by PHP
            const userId = <?php echo json_encode($_SESSION['authUserID'] ?? 'not set'); ?>;
            const userType = <?php echo json_encode($_SESSION['userauthorized'] ?? 'not set'); ?>;
            
            console.log('User ID:', userId);
            console.log('User type:', userType);
            
            debugInfo += 'User ID: ' + userId + '<br>';
            debugInfo += 'User type: ' + userType + '<br>';
            
            // Update debug output
            const debugContent = document.getElementById('debug-content');
            if (debugContent) {
                debugContent.innerHTML += debugInfo;
            }
        }

        // Debug form fields
        function debugFormFields() {
            console.log('Debugging form fields...');
            let debugInfo = 'Form Fields Debug:<br>';
            
            // Check for select elements that might have add buttons
            const selectElements = document.querySelectorAll('select');
            console.log('Found', selectElements.length, 'select elements');
            debugInfo += 'Found ' + selectElements.length + ' select elements<br>';
            
            // Check for input-group elements (where add buttons are typically placed)
            const inputGroups = document.querySelectorAll('.input-group');
            console.log('Found', inputGroups.length, 'input-group elements');
            debugInfo += 'Found ' + inputGroups.length + ' input-group elements<br>';
            
            // Check for any elements with 'addtolist' in their class
            const addtolistElements = document.querySelectorAll('[class*="addtolist"]');
            console.log('Found', addtolistElements.length, 'elements with addtolist in class');
            debugInfo += 'Found ' + addtolistElements.length + ' elements with addtolist in class<br>';
            
            // Update debug output
            const debugContent = document.getElementById('debug-content');
            if (debugContent) {
                debugContent.innerHTML += debugInfo;
            }
        }

        function showSection(sectionId) {
            // Hide all sections
            document.querySelectorAll('.content-section').forEach(section => {
                section.classList.remove('active');
                section.style.display = 'none';
            });
            
            // Show selected section
            const selectedSection = document.getElementById('section-' + sectionId);
            if (selectedSection) {
                selectedSection.classList.add('active');
                selectedSection.style.display = 'block';
            }
            
            // Update navigation styling
            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
            });
            
            // Highlight active navigation item
            const activeNavItem = document.querySelector(`[data-group="${sectionId}"]`);
            if (activeNavItem) {
                activeNavItem.classList.add('active');
            }
            
            // Update current section
            currentSection = sectionId;
            
            // Update navigation buttons
            updateNavigationButtons();
            
            // Smooth scroll to top
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function markSectionAsCompleted(sectionId) {
            completedSections.add(sectionId);
            
            // Update navigation item to show completion
            const navItem = document.querySelector(`[data-group="${sectionId}"]`);
            if (navItem && sectionId !== currentSection) {
                navItem.classList.add('completed');
            }
        }

        function updateNavigationButtons() {
            const currentIndex = sectionIds.indexOf(currentSection);
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            
            if (prevBtn) {
                prevBtn.disabled = currentIndex === 0;
                prevBtn.style.opacity = currentIndex === 0 ? '0.5' : '1';
            }
            
            if (nextBtn) {
                nextBtn.disabled = currentIndex === sectionIds.length - 1;
                nextBtn.style.opacity = currentIndex === sectionIds.length - 1 ? '0.5' : '1';
            }
        }

        function navigateSections(direction) {
            const currentIndex = sectionIds.indexOf(currentSection);
            let nextIndex;
            
            if (direction === 'next') {
                nextIndex = Math.min(currentIndex + 1, sectionIds.length - 1);
            } else {
                nextIndex = Math.max(currentIndex - 1, 0);
            }
            
            showSection(sectionIds[nextIndex]);
        }

        function validateAndSubmit() {
            if (confirm('<?php echo xlt('Are you sure you want to save this patient information? Please verify all data is correct.'); ?>')) {
                // Add loading state
                const submitBtn = document.querySelector('button[onclick="validateAndSubmit()"]');
                if (submitBtn) {
                    submitBtn.classList.add('loading');
                    submitBtn.disabled = true;
                }
                
                // Submit form
                document.getElementById('DEM').submit();
            }
        }

        function validateForm() {
            let isValid = true;
            let missingFields = [];
            
            // Check for required fields
            const requiredFields = ['form_fname', 'form_lname', 'form_DOB'];
            
            requiredFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field && (!field.value || field.value.trim() === '')) {
                    isValid = false;
                    missingFields.push(fieldId.replace('form_', ''));
                }
            });
            
            if (!isValid) {
                alert('<?php echo xlt('Please fill in all required fields: '); ?>' + missingFields.join(', '));
            } else {
                alert('<?php echo xlt('Form validation passed! All required fields are completed.'); ?>');
            }
            
            return isValid;
        }

        // Initialize form
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Patient registration form initialized');
            
            // Debug addtolist buttons
            setTimeout(debugAddToListButtons, 1000); // Wait a bit for all scripts to load
            
            // Debug ACL permissions
            setTimeout(debugACLPermissions, 1000);
            
            // Debug form fields
            setTimeout(debugFormFields, 1000);
            
            // Manual backup event handler for addtolist buttons
            setTimeout(function() {
                const addButtons = document.querySelectorAll('.addtolist');
                console.log('Setting up manual event handlers for', addButtons.length, 'buttons');
                
                addButtons.forEach(function(button) {
                    button.addEventListener('click', function(e) {
                        console.log('Manual addtolist click detected:', this.id);
                        
                        // Call the AddToList function if it exists
                        if (typeof window.oeUI !== 'undefined' && window.oeUI.optionWidgets && window.oeUI.optionWidgets.AddToList) {
                            console.log('Calling AddToList function');
                            window.oeUI.optionWidgets.AddToList(this, e);
                        } else {
                            console.log('AddToList function not available');
                        }
                    });
                });
            }, 1500);
            
            // Initialize navigation buttons
            updateNavigationButtons();
            
            // Add field validation and completion tracking
            const formFields = document.querySelectorAll('input, select, textarea');
            formFields.forEach(field => {
                field.addEventListener('focus', function() {
                    this.style.borderColor = 'var(--primary-color)';
                    this.style.boxShadow = '0 0 0 3px rgba(30, 64, 175, 0.1)';
                });
                
                field.addEventListener('blur', function() {
                    this.style.borderColor = 'var(--border-color)';
                    this.style.boxShadow = 'none';
                    
                    // Mark current section as completed if fields are filled
                    if (this.value && this.value.trim() !== '') {
                        markSectionAsCompleted(currentSection);
                    }
            });
            
                // Auto-save functionality
            let autoSaveTimer;
                field.addEventListener('input', function() {
                    clearTimeout(autoSaveTimer);
                    autoSaveTimer = setTimeout(() => {
                        console.log('Auto-save triggered for field:', this.name);
                        // Implement actual auto-save logic here if needed
                    }, 3000);
                });
            });
            
            // Keyboard navigation
            document.addEventListener('keydown', function(e) {
      if (e.ctrlKey || e.metaKey) {
        switch(e.key) {
          case 'ArrowLeft':
            e.preventDefault();
                            navigateSections('prev');
            break;
          case 'ArrowRight':
            e.preventDefault();
                            navigateSections('next');
            break;
        }
      }
    });
            
            // Add smooth transitions
            document.querySelectorAll('.nav-item').forEach(item => {
                item.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateX(4px)';
                });
                
                item.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateX(0)';
                });
            });
        });
</script>
</body>
</html>
