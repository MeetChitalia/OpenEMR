<?php
/**
 * DCR (Daily Collection Report) System
 * Facility-wide daily collection reports with treatment categorization
 * 
 * Features:
 * - Multi-facility support
 * - Daily revenue tracking with treatment categorization
 * - Monthly breakdown with business day filtering
 * - Patient status tracking (New vs Follow-up)
 * - Treatment type identification (LIPO, SEMA, TRZ, Office, Supplements)
 * - Shot card usage tracking
 * - Real-time data from OpenEMR system
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Keep the GET path extremely small on staging: the reports menu loads this URL in an
// iframe, and we only need to redirect to the enhanced report. Doing this before the
// heavier OpenEMR bootstrap avoids a blank iframe if the wrapper itself fatals.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header("Location: dcr_daily_collection_report_enhanced.php");
    exit;
}

require_once("../globals.php");
require_once("../../library/patient.inc");
require_once("../../library/options.inc.php");

enforceSelectedFacilityScopeForRequest('form_facility');

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

// Check if user has access
if (!AclMain::aclCheckCore('acct', 'rep_a')) {
    echo "<div class='alert alert-danger'>Access Denied. Accounting reports access required.</div>";
    exit;
}

// Redirect to Enhanced DCR Report (fully dynamic POS integration)
// For POST requests, use JavaScript to forward the form data
if (!empty($_POST)) {
    // Generate new CSRF token for enhanced report
    $csrf_token = CsrfUtils::collectCsrfToken();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Redirecting to Enhanced DCR Report...</title>
        <meta http-equiv="refresh" content="0;url=dcr_daily_collection_report_enhanced.php">
    </head>
    <body>
        <p>Redirecting to Enhanced DCR Report...</p>
        <form id="redirectForm" method="POST" action="dcr_daily_collection_report_enhanced.php">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf_token); ?>">
            <?php if (isset($_POST['form_date'])): ?>
                <input type="hidden" name="form_date" value="<?php echo attr($_POST['form_date']); ?>">
            <?php endif; ?>
            <?php if (isset($_POST['form_to_date'])): ?>
                <input type="hidden" name="form_to_date" value="<?php echo attr($_POST['form_to_date']); ?>">
            <?php endif; ?>
            <?php if (isset($_POST['form_facility'])): ?>
                <input type="hidden" name="form_facility" value="<?php echo attr($_POST['form_facility']); ?>">
            <?php endif; ?>
            <?php if (isset($_POST['form_report_type'])): ?>
                <input type="hidden" name="form_report_type" value="<?php echo attr($_POST['form_report_type']); ?>">
            <?php endif; ?>
            <?php if (isset($_POST['form_export'])): ?>
                <input type="hidden" name="form_export" value="<?php echo attr($_POST['form_export']); ?>">
            <?php endif; ?>
        </form>
        <script>
            document.getElementById('redirectForm').submit();
        </script>
    </body>
    </html>
    <?php
    exit;
}

// Parse dates
$date_obj = DateTime::createFromFormat('Y-m-d', $form_date);
$to_date_obj = DateTime::createFromFormat('Y-m-d', $form_to_date);

if (!$date_obj) {
    $date_obj = new DateTime();
    $form_date = $date_obj->format('Y-m-d');
}
if (!$to_date_obj) {
    $to_date_obj = new DateTime();
    $form_to_date = $to_date_obj->format('Y-m-d');
}

$date_formatted = $date_obj->format('m/d/Y');
$to_date_formatted = $to_date_obj->format('m/d/Y');
$day_of_week = $date_obj->format('D');
$month_year = $date_obj->format('F Y');

// Function to get patient status (New vs Follow-up)
function getPatientStatus($pid, $encounter_date) {
    try {
        // Check if this is the first encounter for this patient
        $first_encounter_query = "SELECT MIN(encounter) as first_encounter FROM form_encounter WHERE pid = ?";
        $first_result = sqlStatement($first_encounter_query, array($pid));
        
        if ($first_result) {
            $first_row = sqlFetchArray($first_result);
            if ($first_row && isset($first_row['first_encounter']) && $first_row['first_encounter']) {
                $first_encounter = $first_row['first_encounter'];
                
                // Check if current encounter is within 30 days of first encounter
                $encounter_date_obj = DateTime::createFromFormat('Y-m-d', $encounter_date);
                $first_encounter_obj = DateTime::createFromFormat('Y-m-d', $first_encounter);
                $interval = $first_encounter_obj->diff($encounter_date_obj);
                
                if ($interval->days <= 30) {
                    return 'N'; // New patient
                } else {
                    return 'F'; // Follow-up patient
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error getting patient status: " . $e->getMessage());
    }
    
    return 'N'; // Default to new if no previous encounters
}

// Function to get all medicines from inventory dynamically
function getAllMedicines() {
    $medicines = array();
    
    try {
        // Get all drugs from inventory using correct column names
        $query = "SELECT drug_id, name, category_name, strength, form, size, unit FROM drugs WHERE active = 1 ORDER BY name";
        $result = sqlStatement($query);
        
        if ($result) {
            while ($row = sqlFetchArray($result)) {
                $medicines[] = array(
                    'id' => $row['drug_id'],
                    'name' => $row['name'],
                    'description' => trim($row['strength'] . ' ' . $row['form'] . ' ' . $row['size'] . ' ' . $row['unit']),
                    'category' => $row['category_name'] ?? ''
                );
            }
        }
        
        // If no medicines found, return empty array
        if (empty($medicines)) {
            error_log("No medicines found in inventory - drugs table may be empty or have different structure");
        }
        
    } catch (Exception $e) {
        error_log("Error getting medicines: " . $e->getMessage());
        // Return empty array on error
    }
    
    return $medicines;
}

// Function to categorize treatments dynamically based on actual inventory
function categorizeTreatment($drug_name, $description, $drug_id = null) {
    $drug_name = strtolower($drug_name ?? '');
    $description = strtolower($description ?? '');
    
    // If we have a drug_id, try to get category from inventory
    if ($drug_id) {
        try {
            $query = "SELECT category_name FROM drugs WHERE drug_id = ?";
            $result = sqlQuery($query, array($drug_id));
            if ($result && isset($result['category_name']) && !empty($result['category_name'])) {
                return strtoupper($result['category_name']);
            }
        } catch (Exception $e) {
            error_log("Error getting drug category: " . $e->getMessage());
        }
    }
    
    // Fallback to keyword matching for existing data
    // LIPO B12 Injections
    if (strpos($drug_name, 'lipo') !== false || strpos($drug_name, 'b12') !== false || 
        strpos($description, 'lipo') !== false || strpos($description, 'b12') !== false) {
        return 'LIPO';
    }
    
    // SEMA (Semaglutide) Injections
    if (strpos($drug_name, 'sema') !== false || strpos($drug_name, 'semaglutide') !== false || 
        strpos($description, 'sema') !== false || strpos($description, 'semaglutide') !== false) {
        return 'SEMAGLUTIDE';
    }
    
    // TRZ (Tirzepatide) Injections
    if (strpos($drug_name, 'trz') !== false || strpos($drug_name, 'tirzepatide') !== false || 
        strpos($description, 'trz') !== false || strpos($description, 'tirzepatide') !== false) {
        return 'TIRZEPATIDE';
    }
    
    // Testosterone
    if (strpos($drug_name, 'testosterone') !== false || strpos($drug_name, 'test') !== false || 
        strpos($description, 'testosterone') !== false || strpos($description, 'test') !== false) {
        return 'TESTOSTERONE';
    }
    
    // Pills/Supplements
    if (strpos($drug_name, 'supplement') !== false || strpos($drug_name, 'vitamin') !== false || 
        strpos($drug_name, 'pill') !== false || strpos($drug_name, 'tablet') !== false ||
        strpos($description, 'supplement') !== false || strpos($description, 'vitamin') !== false ||
        strpos($description, 'pill') !== false || strpos($description, 'tablet') !== false) {
        return 'PILLS';
    }
    
    return 'OTHER';
}

// Function to get treatment dosage
function getTreatmentDosage($drug_id, $treatment_type) {
    // Don't query if drug_id is 0 or empty
    if (empty($drug_id) || $drug_id == 0) {
        return 'N/A';
    }
    
    if ($treatment_type === 'SEMA' || $treatment_type === 'TRZ') {
        try {
            // Get dosage from drugs table using correct columns
            $dosage_query = "SELECT name, strength, form, size, unit FROM drugs WHERE drug_id = ?";
            $dosage_result = sqlQuery($dosage_query, array($drug_id));
            
            if ($dosage_result) {
                $strength = $dosage_result['strength'] ?? '';
                $form = $dosage_result['form'] ?? '';
                $size = $dosage_result['size'] ?? '';
                $unit = $dosage_result['unit'] ?? '';
                
                // Build dosage from available fields
                $dosage_parts = array();
                if (!empty($strength)) $dosage_parts[] = $strength;
                if (!empty($form)) $dosage_parts[] = $form;
                if (!empty($size)) $dosage_parts[] = $size;
                if (!empty($unit)) $dosage_parts[] = $unit;
                
                $dosage = implode(' ', $dosage_parts);
                
                // If we have dosage info, return it
                if (!empty($dosage)) {
                    return $dosage;
                }
            }
            
            // Fallback: check if dosage is in the name
            if ($dosage_result && $dosage_result['name']) {
                if (preg_match('/(\d+\.?\d*)\s*mg/i', $dosage_result['name'], $matches)) {
                    return $matches[1] . 'mg';
                }
            }
        } catch (Exception $e) {
            error_log("Error getting treatment dosage: " . $e->getMessage());
        }
    }
    
    return 'N/A';
}

// Function to get daily revenue data
function getDailyRevenueData($date, $facility_id = '') {
    // Get all medicines from inventory dynamically
    $all_medicines = getAllMedicines();
    
    // Initialize data structure dynamically
    $data = array(
        'total_revenue' => 0,
        'lipo_injections' => 0,
        'sema_injections' => 0,
        'trz_injections' => 0,
        'supplements' => 0,
        'testosterone' => 0,
        'other_revenue' => 0,
        'shot_cards' => 0,
        'taxes' => 0,
        'patients' => array(),
        'treatments' => array(),
        'card_usage' => array(
            'lipo_cards' => 0,
            'sema_cards' => 0,
            'trz_cards' => 0
        ),
        'medicine_breakdown' => array(),
        'all_medicines' => $all_medicines
    );
    
    // Initialize medicine breakdown for each medicine in inventory
    if (!empty($all_medicines)) {
        foreach ($all_medicines as $medicine) {
            $category = strtolower($medicine['category'] ?: 'other');
            if (!isset($data['medicine_breakdown'][$category])) {
                $data['medicine_breakdown'][$category] = array(
                    'new_patients' => 0,
                    'new_revenue' => 0,
                    'followup_patients' => 0,
                    'followup_revenue' => 0,
                    'total_patients' => 0,
                    'total_revenue' => 0,
                    'medicine_name' => $medicine['name']
                );
            }
        }
    } else {
        // Log warning if no medicines found
        error_log("Warning: No medicines found in inventory for DCR report");
    }
    
    // Add common categories if they don't exist (fallback for backward compatibility)
    $common_categories = ['lipo', 'semaglutide', 'tirzepatide', 'pills', 'testosterone', 'other'];
    foreach ($common_categories as $cat) {
        if (!isset($data['medicine_breakdown'][$cat])) {
            $data['medicine_breakdown'][$cat] = array(
                'new_patients' => 0,
                'new_revenue' => 0,
                'followup_patients' => 0,
                'followup_revenue' => 0,
                'total_patients' => 0,
                'total_revenue' => 0,
                'medicine_name' => ucfirst($cat)
            );
        }
    }
    
    try {
        // Build facility filter
        $facility_filter = '';
        $bind_array = array($date);
        
        if ($facility_id) {
            $facility_filter = ' AND facility_id = ?';
            $bind_array[] = $facility_id;
        }
        
                 // Get POS receipts for the date
         $receipts_query = "SELECT * FROM pos_receipts WHERE DATE(created_date) = ?" . $facility_filter . " ORDER BY created_date";
         $receipts_result = sqlStatement($receipts_query, $bind_array);
         
         if ($receipts_result) {
             while ($receipt = sqlFetchArray($receipts_result)) {
            $receipt_data = json_decode($receipt['receipt_data'], true);
            if (!$receipt_data) continue;
            
            $data['total_revenue'] += floatval($receipt['amount']);
            
            // Process items in receipt
            if (isset($receipt_data['items']) && is_array($receipt_data['items'])) {
                                 foreach ($receipt_data['items'] as $item) {
                     $treatment_type = categorizeTreatment($item['name'] ?? '', $item['description'] ?? '');
                     
                     // Only get dosage if we have a valid drug_id
                     $drug_id = $item['drug_id'] ?? 0;
                     $dosage = 'N/A';
                     if (!empty($drug_id) && $drug_id > 0) {
                         $dosage = getTreatmentDosage($drug_id, $treatment_type);
                     }
                     
                     $treatment_info = array(
                         'patient_id' => $receipt['pid'],
                         'patient_name' => $receipt_data['patient_name'] ?? 'Unknown',
                         'treatment_type' => $treatment_type,
                         'drug_name' => $item['name'] ?? 'Unknown',
                         'quantity' => intval($item['quantity'] ?? 1),
                         'unit_price' => floatval($item['price'] ?? 0),
                         'total_price' => floatval($item['total'] ?? 0),
                         'dosage' => $dosage,
                         'receipt_number' => $receipt['receipt_number'],
                         'created_date' => $receipt['created_date']
                     );
                    
                    $data['treatments'][] = $treatment_info;
                    
                                         // Categorize revenue and track medicine breakdown
                     $patient_status = getPatientStatus($receipt['pid'], $date);
                     
                     // Convert treatment type to lowercase for dynamic lookup
                     $treatment_category = strtolower($treatment_type);
                     
                     // Track medicine breakdown dynamically
                     if (isset($data['medicine_breakdown'][$treatment_category])) {
                         if ($patient_status === 'N') {
                             $data['medicine_breakdown'][$treatment_category]['new_patients']++;
                             $data['medicine_breakdown'][$treatment_category]['new_revenue'] += $treatment_info['total_price'];
                         } else {
                             $data['medicine_breakdown'][$treatment_category]['followup_patients']++;
                             $data['medicine_breakdown'][$treatment_category]['followup_revenue'] += $treatment_info['total_price'];
                         }
                         $data['medicine_breakdown'][$treatment_category]['total_patients']++;
                         $data['medicine_breakdown'][$treatment_category]['total_revenue'] += $treatment_info['total_price'];
                     }
                     
                     // Update specific revenue categories
                     switch ($treatment_type) {
                         case 'LIPO':
                             $data['lipo_injections'] += $treatment_info['total_price'];
                             $data['card_usage']['lipo_cards']++;
                             break;
                         case 'SEMAGLUTIDE':
                             $data['sema_injections'] += $treatment_info['total_price'];
                             $data['card_usage']['sema_cards']++;
                             break;
                         case 'TIRZEPATIDE':
                             $data['trz_injections'] += $treatment_info['total_price'];
                             $data['card_usage']['trz_cards']++;
                             break;
                         case 'PILLS':
                             $data['supplements'] += $treatment_info['total_price'];
                             break;
                         case 'TESTOSTERONE':
                             $data['testosterone'] += $treatment_info['total_price'];
                             break;
                         default:
                             $data['other_revenue'] += $treatment_info['total_price'];
                             break;
                     }
                    
                                         // Track patient status (already calculated above)
                     $patient_key = $receipt['pid'];
                    
                    if (!isset($data['patients'][$patient_key])) {
                        $data['patients'][$patient_key] = array(
                            'id' => $receipt['pid'],
                            'name' => $receipt_data['patient_name'] ?? 'Unknown',
                            'status' => $patient_status,
                            'total_spent' => 0,
                            'treatments' => array()
                        );
                    }
                    
                    $data['patients'][$patient_key]['total_spent'] += $treatment_info['total_price'];
                    $data['patients'][$patient_key]['treatments'][] = $treatment_info;
                }
            }
            
            // Check for shot cards
            if (isset($receipt_data['shot_cards']) && $receipt_data['shot_cards'] > 0) {
                $data['shot_cards'] += $receipt_data['shot_cards'];
            }
            
            // Check for taxes
            if (isset($receipt_data['tax_total']) && $receipt_data['tax_total'] > 0) {
                $data['taxes'] += $receipt_data['tax_total'];
            }
        }
        } // Close the if ($receipts_result) statement
        
                 // Also check drug_sales table for additional transactions
         $drug_sales_query = "SELECT * FROM drug_sales WHERE DATE(sale_date) = ? AND trans_type = 1";
         $drug_sales_bind = array($date);
         
         if ($facility_id) {
             $drug_sales_query .= " AND facility_id = ?";
             $drug_sales_bind[] = $facility_id;
         }
         
         $drug_sales_result = sqlStatement($drug_sales_query, $drug_sales_bind);
        
         if ($drug_sales_result) {
             while ($sale = sqlFetchArray($drug_sales_result)) {
             // Get drug information
             $drug_query = "SELECT name, strength, form, size, unit FROM drugs WHERE drug_id = ?";
             $drug_result = sqlQuery($drug_query, array($sale['drug_id']));
             
             if ($drug_result) {
                 $description = $drug_result['strength'] . ' ' . $drug_result['form'] . ' ' . $drug_result['size'] . ' ' . $drug_result['unit'];
                 $treatment_type = categorizeTreatment($drug_result['name'], $description);
                 
                 // Only get dosage if we have a valid drug_id
                 $drug_id = $sale['drug_id'] ?? 0;
                 $dosage = 'N/A';
                 if (!empty($drug_id) && $drug_id > 0) {
                     $dosage = getTreatmentDosage($drug_id, $treatment_type);
                 }
                 
                 $treatment_info = array(
                     'patient_id' => $sale['pid'],
                     'patient_name' => 'Patient ' . $sale['pid'],
                     'treatment_type' => $treatment_type,
                     'drug_name' => $drug_result['name'],
                     'quantity' => intval($sale['quantity']),
                     'unit_price' => floatval($sale['fee']),
                     'total_price' => floatval($sale['fee']) * intval($sale['quantity']),
                     'dosage' => $dosage,
                     'receipt_number' => 'DRUG-' . $sale['sale_id'],
                     'created_date' => $sale['sale_date']
                 );
                
                $data['treatments'][] = $treatment_info;
                
                                 // Update revenue totals and medicine breakdown
                 $patient_status = getPatientStatus($sale['pid'], $date);
                 
                 // Convert treatment type to lowercase for dynamic lookup
                 $treatment_category = strtolower($treatment_type);
                 
                 // Track medicine breakdown dynamically
                 if (isset($data['medicine_breakdown'][$treatment_category])) {
                     if ($patient_status === 'N') {
                         $data['medicine_breakdown'][$treatment_category]['new_patients']++;
                         $data['medicine_breakdown'][$treatment_category]['new_revenue'] += $treatment_info['total_price'];
                     } else {
                         $data['medicine_breakdown'][$treatment_category]['followup_patients']++;
                         $data['medicine_breakdown'][$treatment_category]['followup_revenue'] += $treatment_info['total_price'];
                     }
                     $data['medicine_breakdown'][$treatment_category]['total_patients']++;
                     $data['medicine_breakdown'][$treatment_category]['total_revenue'] += $treatment_info['total_price'];
                 }
                 
                 // Update specific revenue categories
                 switch ($treatment_type) {
                     case 'LIPO':
                         $data['lipo_injections'] += $treatment_info['total_price'];
                         $data['card_usage']['lipo_cards']++;
                         break;
                     case 'SEMAGLUTIDE':
                         $data['sema_injections'] += $treatment_info['total_price'];
                         $data['card_usage']['sema_cards']++;
                         break;
                     case 'TIRZEPATIDE':
                         $data['trz_injections'] += $treatment_info['total_price'];
                         $data['card_usage']['trz_cards']++;
                         break;
                     case 'PILLS':
                         $data['supplements'] += $treatment_info['total_price'];
                         break;
                     case 'TESTOSTERONE':
                         $data['testosterone'] += $treatment_info['total_price'];
                         break;
                     default:
                         $data['other_revenue'] += $treatment_info['total_price'];
                         break;
                 }
            }
        }
        } // Close the if ($drug_sales_result) statement
        
    } catch (Exception $e) {
        error_log("Error getting daily revenue data: " . $e->getMessage());
        
        // Return default structure on error
        $data = array(
            'total_revenue' => 0,
            'lipo_injections' => 0,
            'sema_injections' => 0,
            'trz_injections' => 0,
            'supplements' => 0,
            'testosterone' => 0,
            'other_revenue' => 0,
            'shot_cards' => 0,
            'taxes' => 0,
            'patients' => array(),
            'treatments' => array(),
            'card_usage' => array(
                'lipo_cards' => 0,
                'sema_cards' => 0,
                'trz_cards' => 0
            ),
            'medicine_breakdown' => array(),
            'all_medicines' => array()
        );
    }
    
    return $data;
}

// Function to get monthly breakdown data
function getMonthlyBreakdownData($month, $year, $facility_id = '') {
    $data = array(
        'dates' => array(),
        'sema_new_patients' => array(),
        'sema_followup_patients' => array(),
        'sema_revenue' => array(),
        'trz_new_patients' => array(),
        'trz_followup_patients' => array(),
        'trz_revenue' => array(),
        'pills_new_patients' => array(),
        'pills_followup_patients' => array(),
        'pills_revenue' => array(),
        'testosterone_new_patients' => array(),
        'testosterone_followup_patients' => array(),
        'testosterone_revenue' => array(),
        'other_revenue' => array(),
        'total_revenue' => array(),
        'totals' => array(
            'sema_new_patients' => 0,
            'sema_followup_patients' => 0,
            'sema_revenue' => 0,
            'trz_new_patients' => 0,
            'trz_followup_patients' => 0,
            'trz_revenue' => 0,
            'pills_new_patients' => 0,
            'pills_followup_patients' => 0,
            'pills_revenue' => 0,
            'testosterone_new_patients' => 0,
            'testosterone_followup_patients' => 0,
            'testosterone_revenue' => 0,
            'other_revenue' => 0,
            'total_revenue' => 0
        )
    );
    
    try {
        // Get all dates in the month
        $start_date = "$year-$month-01";
        $end_date = date('Y-m-t', strtotime($start_date));
        
        $current_date = new DateTime($start_date);
        $end_date_obj = new DateTime($end_date);
        
        while ($current_date <= $end_date_obj) {
            $date_str = $current_date->format('Y-m-d');
            $day_name = $current_date->format('D');
            $day_num = $current_date->format('j');
            
            // Only include business days (Wed, Thu, Sat)
            if (in_array($day_name, ['Wed', 'Thu', 'Sat'])) {
                $daily_data = getDailyRevenueData($date_str, $facility_id);
                
                                 $data['dates'][] = $day_name . ', ' . $month . ' ' . $day_num . ', ' . $year;
                 
                 // Semaglutide data
                 $data['sema_new_patients'][] = $daily_data['medicine_breakdown']['semaglutide']['new_patients'];
                 $data['sema_followup_patients'][] = $daily_data['medicine_breakdown']['semaglutide']['followup_patients'];
                 $data['sema_revenue'][] = $daily_data['medicine_breakdown']['semaglutide']['total_revenue'];
                 
                 // Tirzepatide data
                 $data['trz_new_patients'][] = $daily_data['medicine_breakdown']['tirzepatide']['new_patients'];
                 $data['trz_followup_patients'][] = $daily_data['medicine_breakdown']['tirzepatide']['followup_patients'];
                 $data['trz_revenue'][] = $daily_data['medicine_breakdown']['tirzepatide']['total_revenue'];
                 
                 // Pills data
                 $data['pills_new_patients'][] = $daily_data['medicine_breakdown']['pills']['new_patients'];
                 $data['pills_followup_patients'][] = $daily_data['medicine_breakdown']['pills']['followup_patients'];
                 $data['pills_revenue'][] = $daily_data['medicine_breakdown']['pills']['total_revenue'];
                 
                 // Testosterone data
                 $data['testosterone_new_patients'][] = $daily_data['medicine_breakdown']['testosterone']['new_patients'];
                 $data['testosterone_followup_patients'][] = $daily_data['medicine_breakdown']['testosterone']['followup_patients'];
                 $data['testosterone_revenue'][] = $daily_data['medicine_breakdown']['testosterone']['total_revenue'];
                 
                 // Other and total revenue
                 $data['other_revenue'][] = $daily_data['other_revenue'];
                 $data['total_revenue'][] = $daily_data['total_revenue'];
                 
                 // Update totals
                 $data['totals']['sema_new_patients'] += $daily_data['medicine_breakdown']['semaglutide']['new_patients'];
                 $data['totals']['sema_followup_patients'] += $daily_data['medicine_breakdown']['semaglutide']['followup_patients'];
                 $data['totals']['sema_revenue'] += $daily_data['medicine_breakdown']['semaglutide']['total_revenue'];
                 $data['totals']['trz_new_patients'] += $daily_data['medicine_breakdown']['tirzepatide']['new_patients'];
                 $data['totals']['trz_followup_patients'] += $daily_data['medicine_breakdown']['tirzepatide']['followup_revenue'];
                 $data['totals']['trz_revenue'] += $daily_data['medicine_breakdown']['tirzepatide']['total_revenue'];
                 $data['totals']['pills_new_patients'] += $daily_data['medicine_breakdown']['pills']['new_patients'];
                 $data['totals']['pills_followup_patients'] += $daily_data['medicine_breakdown']['pills']['followup_patients'];
                 $data['totals']['pills_revenue'] += $daily_data['medicine_breakdown']['pills']['total_revenue'];
                 $data['totals']['testosterone_new_patients'] += $daily_data['medicine_breakdown']['testosterone']['new_patients'];
                 $data['totals']['testosterone_followup_patients'] += $daily_data['medicine_breakdown']['testosterone']['followup_patients'];
                 $data['totals']['testosterone_revenue'] += $daily_data['medicine_breakdown']['testosterone']['total_revenue'];
                 $data['totals']['other_revenue'] += $daily_data['other_revenue'];
                 $data['totals']['total_revenue'] += $daily_data['total_revenue'];
            }
            
            $current_date->add(new DateInterval('P1D'));
        }
        
    } catch (Exception $e) {
        error_log("Error getting monthly breakdown data: " . $e->getMessage());
    }
    
    return $data;
}

// Get report data
try {
    $daily_data = getDailyRevenueData($form_date, $form_facility);
    $monthly_data = getMonthlyBreakdownData($date_obj->format('m'), $date_obj->format('Y'), $form_facility);
} catch (Exception $e) {
    error_log("Error generating DCR report: " . $e->getMessage());
         $daily_data = array(
         'total_revenue' => 0,
         'lipo_injections' => 0,
         'sema_injections' => 0,
         'trz_injections' => 0,
         'supplements' => 0,
         'testosterone' => 0,
         'other_revenue' => 0,
         'shot_cards' => 0,
         'taxes' => 0,
         'patients' => array(),
         'treatments' => array(),
         'card_usage' => array(
             'lipo_cards' => 0,
             'sema_cards' => 0,
             'trz_cards' => 0
         ),
         'medicine_breakdown' => array(
             'semaglutide' => array(
                 'new_patients' => 0,
                 'new_revenue' => 0,
                 'followup_patients' => 0,
                 'followup_revenue' => 0,
                 'total_patients' => 0,
                 'total_revenue' => 0
             ),
             'tirzepatide' => array(
                 'new_patients' => 0,
                 'new_revenue' => 0,
                 'followup_patients' => 0,
                 'followup_revenue' => 0,
                 'total_patients' => 0,
                 'total_revenue' => 0
             ),
             'pills' => array(
                 'new_patients' => 0,
                 'new_revenue' => 0,
                 'followup_patients' => 0,
                 'followup_revenue' => 0,
                 'total_patients' => 0,
                 'total_revenue' => 0
             ),
             'testosterone' => array(
                 'new_patients' => 0,
                 'new_revenue' => 0,
                 'followup_patients' => 0,
                 'followup_revenue' => 0,
                 'total_patients' => 0,
                 'total_revenue' => 0
             )
         )
     );
         $monthly_data = array(
         'dates' => array(),
         'sema_new_patients' => array(),
         'sema_followup_patients' => array(),
         'sema_revenue' => array(),
         'trz_new_patients' => array(),
         'trz_followup_patients' => array(),
         'trz_revenue' => array(),
         'pills_new_patients' => array(),
         'pills_followup_patients' => array(),
         'pills_revenue' => array(),
         'testosterone_new_patients' => array(),
         'testosterone_followup_patients' => array(),
         'testosterone_revenue' => array(),
         'other_revenue' => array(),
         'total_revenue' => array(),
         'totals' => array(
             'sema_new_patients' => 0,
             'sema_followup_patients' => 0,
             'sema_revenue' => 0,
             'trz_new_patients' => 0,
             'trz_followup_patients' => 0,
             'trz_revenue' => 0,
             'pills_new_patients' => 0,
             'pills_followup_patients' => 0,
             'pills_revenue' => 0,
             'testosterone_new_patients' => 0,
             'testosterone_followup_patients' => 0,
             'testosterone_revenue' => 0,
             'other_revenue' => 0,
             'total_revenue' => 0
         )
     );
}

// Debug information (remove this in production)
if (isset($_GET['debug']) && $_GET['debug'] == 1) {
    echo "<div style='background: #f0f0f0; padding: 10px; margin: 10px; border: 1px solid #ccc;'>";
    echo "<strong>Debug Info:</strong><br>";
    echo "Date: " . $form_date . "<br>";
    echo "Facility: " . ($form_facility ?: 'All') . "<br>";
    echo "Total Revenue: $" . number_format($daily_data['total_revenue'], 2) . "<br>";
    echo "Total Treatments: " . count($daily_data['treatments']) . "<br>";
    echo "Total Patients: " . count($daily_data['patients']) . "<br>";
    
    echo "<br><strong>Inventory Medicines Found:</strong><br>";
    if (isset($daily_data['all_medicines'])) {
        foreach ($daily_data['all_medicines'] as $medicine) {
            echo "- " . htmlspecialchars($medicine['name']) . " (Category: " . ($medicine['category'] ?: 'None') . ")<br>";
        }
    }
    
    echo "<br><strong>Medicine Breakdown Categories:</strong><br>";
    foreach ($daily_data['medicine_breakdown'] as $category => $data_item) {
        echo "- " . strtoupper($category) . ": " . $data_item['total_patients'] . " patients, $" . number_format($data_item['total_revenue'], 2) . "<br>";
    }
    
    echo "<br><strong>Database Test:</strong><br>";
    
    // Test database connection
    try {
        $test_query = "SELECT COUNT(*) as count FROM form_encounter LIMIT 1";
        $test_result = sqlStatement($test_query);
        if ($test_result) {
            $test_row = sqlFetchArray($test_result);
            echo "Database connection: OK (Found " . ($test_row['count'] ?? 'unknown') . " encounters)<br>";
        } else {
            echo "Database connection: ERROR<br>";
        }
    } catch (Exception $e) {
        echo "Database connection: EXCEPTION - " . $e->getMessage() . "<br>";
    }
    
    echo "</div>";
}

// Calculate card values (assuming $4 per card)
$card_value = 4.00;
$lipo_card_total = $daily_data['card_usage']['lipo_cards'] * $card_value;
$sema_card_total = $daily_data['card_usage']['sema_cards'] * $card_value;
$trz_card_total = $daily_data['card_usage']['trz_cards'] * $card_value;

// Handle export
if ($form_export === 'csv') {
    // CSV export logic would go here
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="dcr_report_' . $form_date . '.csv"');
    // CSV output would be generated here
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DCR Daily Collection Report - OpenEMR</title>
    <?php Header::setupHeader(['datetime-picker']); ?>
    <style>
        .dcr-container { max-width: 1400px; margin: 20px auto; padding: 20px; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .dcr-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #007bff; padding-bottom: 20px; }
        .dcr-title { font-size: 28px; font-weight: bold; color: #007bff; margin: 0; }
        .dcr-subtitle { font-size: 18px; color: #666; margin: 10px 0 0 0; }
        .dcr-section { margin-bottom: 30px; padding: 20px; border: 1px solid #ddd; border-radius: 6px; background: #f9f9f9; }
        .dcr-section h3 { margin: 0 0 15px 0; color: #333; border-bottom: 1px solid #ddd; padding-bottom: 10px; }
        .dcr-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .dcr-card { background: white; padding: 15px; border-radius: 6px; border: 1px solid #ddd; text-align: center; }
        .dcr-card-label { font-size: 14px; color: #666; margin-bottom: 8px; }
        .dcr-card-value { font-size: 20px; font-weight: bold; color: #007bff; }
                 .dcr-table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 14px; }
         .dcr-table th, .dcr-table td { padding: 8px 6px; text-align: left; border-bottom: 1px solid #ddd; }
                  .dcr-table th { background: #f5f5f5; font-weight: bold; text-align: center; }
        .dcr-table tr:hover { background: #f9f9f9; }
        .dcr-controls { margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 6px; border: 1px solid #dee2e6; }
        .dcr-controls form { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .dcr-controls label { font-weight: bold; margin-right: 5px; }
        .dcr-controls select, .dcr-controls input { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; }
        .dcr-controls button { padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .dcr-controls button:hover { background: #0056b3; }
        .export-buttons { margin-top: 20px; text-align: center; }
        .export-btn { display: inline-block; padding: 10px 20px; margin: 0 10px; background: #28a745; color: white; text-decoration: none; border-radius: 4px; border: none; cursor: pointer; }
        .export-btn:hover { background: #218838; }
        .export-btn.export-csv { background: #17a2b8; }
        .export-btn.export-csv:hover { background: #138496; }
        .export-btn.export-pdf { background: #dc3545; }
        .export-btn.export-pdf:hover { background: #c82333; }
        .treatment-type { font-weight: bold; color: #007bff; }
        .patient-status { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .patient-status.new { background: #28a745; color: white; }
        .patient-status.followup { background: #007bff; color: white; }
        .amount-positive { color: #28a745; font-weight: bold; }
        .amount-negative { color: #dc3545; font-weight: bold; }
        .summary-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin: 15px 0; }
        .summary-stat { background: white; padding: 10px; border-radius: 4px; border: 1px solid #ddd; text-align: center; }
        .summary-stat-label { font-size: 12px; color: #666; margin-bottom: 5px; }
        .summary-stat-value { font-size: 16px; font-weight: bold; color: #333; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .btn-primary { background: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; }
        .btn-primary:hover { background: #0056b3; }
    </style>
</head>
<body>
    <div class="dcr-container">
                 <!-- Header -->
         <div class="dcr-header">
             <h1 class="dcr-title"><?php 
                 if ($form_facility) {
                     try {
                         $facility_query = "SELECT name FROM facility WHERE id = ?";
                         $facility_result = sqlStatement($facility_query, array($form_facility));
                         if ($facility_result) {
                             $facility_row = sqlFetchArray($facility_result);
                             if ($facility_row && isset($facility_row['name'])) {
                                 echo htmlspecialchars($facility_row['name']);
                             } else {
                                 echo 'Selected Facility';
                             }
                         } else {
                             echo 'Selected Facility';
                         }
                     } catch (Exception $e) {
                         echo 'Selected Facility';
                     }
                 } else {
                     echo 'All Facilities';
                 }
             ?></h1>
             <p class="dcr-subtitle">Daily Collection Report (DCR) - Facility Report</p>
             <p class="dcr-subtitle"><?php echo $day_of_week . ' ' . $date_formatted; ?></p>
         </div>

                 <!-- Controls -->
         <div class="dcr-controls">
             <form method="POST" action="">
                 <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                 <div class="form-group">
                     <label for="form_date">From Date:</label>
                     <input type="date" id="form_date" name="form_date" value="<?php echo $form_date; ?>">
                 </div>
                 
                 <div class="form-group">
                     <label for="form_to_date">To Date:</label>
                     <input type="date" id="form_to_date" name="form_to_date" value="<?php echo $form_to_date; ?>">
                 </div>
                 
                 <div class="form-group">
                     <label for="form_facility">Facility:</label>
                     <?php dropdown_facility($form_facility, 'form_facility', true); ?>
                 </div>
                 
                 <div class="form-group">
                     <label for="form_report_type">Report Type:</label>
                     <select id="form_report_type" name="form_report_type">
                         <option value="daily" <?php echo $form_report_type === 'daily' ? 'selected' : ''; ?>>Daily Report</option>
                         <option value="monthly" <?php echo $form_report_type === 'monthly' ? 'selected' : ''; ?>>Monthly Breakdown</option>
                     </select>
                 </div>
                 
                 <button type="submit" class="btn-primary">Generate Report</button>
                 <button type="submit" name="form_export" value="csv" class="export-btn export-csv">Export to CSV</button>
             </form>
         </div>
         
         <!-- Facility Summary -->
         <div class="dcr-section">
             <h3>Report Summary</h3>
             <div class="dcr-grid">
                 <div class="dcr-card">
                     <div class="dcr-card-label">Facility</div>
                     <div class="dcr-card-value"><?php 
                         if ($form_facility) {
                             try {
                                 $facility_query = "SELECT name FROM facility WHERE id = ?";
                                 $facility_result = sqlStatement($facility_query, array($form_facility));
                                 if ($facility_result) {
                                     $facility_row = sqlFetchArray($facility_result);
                                     if ($facility_row && isset($facility_row['name'])) {
                                         echo htmlspecialchars($facility_row['name']);
                                     } else {
                                         echo 'Unknown';
                                     }
                                 } else {
                                     echo 'Unknown';
                                 }
                             } catch (Exception $e) {
                                 echo 'Unknown';
                             }
                         } else {
                             echo 'All Facilities';
                         }
                     ?></div>
                 </div>
                 <div class="dcr-card">
                     <div class="dcr-card-label">Report Date</div>
                     <div class="dcr-card-value"><?php echo $date_formatted; ?></div>
                 </div>
                 <div class="dcr-card">
                     <div class="dcr-card-label">Report Type</div>
                     <div class="dcr-card-value"><?php echo ucfirst($form_report_type); ?></div>
                 </div>
             </div>
         </div>

        <?php if ($form_report_type === 'daily'): ?>
                 <!-- Daily Report -->
         <div class="dcr-section">
             <h3>Daily Revenue Summary</h3>
             <div class="dcr-grid">
                 <div class="dcr-card">
                     <div class="dcr-card-label">Total Revenue</div>
                     <div class="dcr-card-value">$<?php echo number_format($daily_data['total_revenue'], 2); ?></div>
                 </div>
                 <div class="dcr-card">
                     <div class="dcr-card-label">LIPO Injections</div>
                     <div class="dcr-card-value">$<?php echo number_format($daily_data['lipo_injections'], 2); ?></div>
                 </div>
                 <div class="dcr-card">
                     <div class="dcr-card-label">SEMA Injections</div>
                     <div class="dcr-card-value">$<?php echo number_format($daily_data['sema_injections'], 2); ?></div>
                 </div>
                 <div class="dcr-card">
                     <div class="dcr-card-label">TRZ Injections</div>
                     <div class="dcr-card-value">$<?php echo number_format($daily_data['trz_injections'], 2); ?></div>
                 </div>
                 <div class="dcr-card">
                     <div class="dcr-card-label">Pills/Supplements</div>
                     <div class="dcr-card-value">$<?php echo number_format($daily_data['supplements'], 2); ?></div>
                 </div>
                 <div class="dcr-card">
                     <div class="dcr-card-label">Testosterone</div>
                     <div class="dcr-card-value">$<?php echo number_format($daily_data['testosterone'], 2); ?></div>
                 </div>
                 <div class="dcr-card">
                     <div class="dcr-card-label">Other Revenue</div>
                     <div class="dcr-card-value">$<?php echo number_format($daily_data['other_revenue'], 2); ?></div>
                 </div>
             </div>
         </div>

        <!-- Card Usage Summary -->
        <div class="dcr-section">
            <h3>Shot Card Usage</h3>
            <div class="dcr-grid">
                <div class="dcr-card">
                    <div class="dcr-card-label">LIPO Cards</div>
                    <div class="dcr-card-value"><?php echo $daily_data['card_usage']['lipo_cards']; ?></div>
                    <div class="dcr-card-label">$<?php echo number_format($lipo_card_total, 2); ?></div>
                </div>
                <div class="dcr-card">
                    <div class="dcr-card-label">SEMA Cards</div>
                    <div class="dcr-card-value"><?php echo $daily_data['card_usage']['sema_cards']; ?></div>
                    <div class="dcr-card-label">$<?php echo number_format($sema_card_total, 2); ?></div>
                </div>
                <div class="dcr-card">
                    <div class="dcr-card-label">TRZ Cards</div>
                    <div class="dcr-card-value"><?php echo $daily_data['card_usage']['trz_cards']; ?></div>
                    <div class="dcr-card-label">$<?php echo number_format($trz_card_total, 2); ?></div>
                </div>
            </div>
        </div>

        <!-- Medicine Breakdown (Dynamic from Inventory) -->
        <div class="dcr-section">
            <h3>Medicine Breakdown - <?php echo $day_of_week . ', ' . $date_formatted; ?></h3>
            <table class="dcr-table" style="font-size: 12px;">
                <thead>
                    <tr>
                        <th rowspan="2">Medicine</th>
                        <th colspan="2">NEW PATIENTS</th>
                        <th colspan="2">FOLLOW-UP PATIENTS</th>
                        <th colspan="2">TOTAL</th>
                    </tr>
                    <tr>
                        <th>Count</th>
                        <th>Revenue</th>
                        <th>Count</th>
                        <th>Revenue</th>
                        <th>Count</th>
                        <th>Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $total_new_patients = 0;
                    $total_new_revenue = 0;
                    $total_followup_patients = 0;
                    $total_followup_revenue = 0;
                    $total_all_patients = 0;
                    $total_all_revenue = 0;
                    
                    foreach ($daily_data['medicine_breakdown'] as $category => $data_item): 
                        if ($data_item['total_patients'] > 0 || $data_item['total_revenue'] > 0):
                            $total_new_patients += $data_item['new_patients'];
                            $total_new_revenue += $data_item['new_revenue'];
                            $total_followup_patients += $data_item['followup_patients'];
                            $total_followup_revenue += $data_item['followup_revenue'];
                            $total_all_patients += $data_item['total_patients'];
                            $total_all_revenue += $data_item['total_revenue'];
                    ?>
                    <tr>
                        <td><strong><?php echo strtoupper($category); ?></strong></td>
                        <td><?php echo $data_item['new_patients']; ?></td>
                        <td>$<?php echo number_format($data_item['new_revenue'], 2); ?></td>
                        <td><?php echo $data_item['followup_patients']; ?></td>
                        <td>$<?php echo number_format($data_item['followup_revenue'], 2); ?></td>
                        <td><?php echo $data_item['total_patients']; ?></td>
                        <td>$<?php echo number_format($data_item['total_revenue'], 2); ?></td>
                    </tr>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                    <tr style="font-weight: bold; background: #f0f0f0;">
                        <td><strong>ALL OTHER</strong></td>
                        <td>-</td>
                        <td>$<?php echo number_format($daily_data['other_revenue'], 2); ?></td>
                        <td>-</td>
                        <td>-</td>
                        <td>-</td>
                        <td>$<?php echo number_format($daily_data['other_revenue'], 2); ?></td>
                    </tr>
                    <tr style="font-weight: bold; background: #e6f3ff;">
                        <td><strong>TOTAL</strong></td>
                        <td><?php echo $total_new_patients; ?></td>
                        <td>$<?php echo number_format($total_new_revenue, 2); ?></td>
                        <td><?php echo $total_followup_patients; ?></td>
                        <td>$<?php echo number_format($total_followup_revenue, 2); ?></td>
                        <td><?php echo $total_all_patients; ?></td>
                        <td>$<?php echo number_format($total_all_revenue, 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Inventory Medicines Used Today -->
        <div class="dcr-section">
            <h3>Inventory Medicines Used Today</h3>
            <table class="dcr-table" style="font-size: 12px;">
                <thead>
                    <tr>
                        <th>Medicine Name</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Total Patients</th>
                        <th>Total Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $has_medicines = false;
                    foreach ($daily_data['all_medicines'] as $medicine): 
                        $category = strtolower($medicine['category'] ?: 'other');
                        if (isset($daily_data['medicine_breakdown'][$category]) && 
                            ($daily_data['medicine_breakdown'][$category]['total_patients'] > 0 || 
                             $daily_data['medicine_breakdown'][$category]['total_revenue'] > 0)):
                            $has_medicines = true;
                            $breakdown = $daily_data['medicine_breakdown'][$category];
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($medicine['name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($medicine['category'] ?: 'None'); ?></td>
                        <td><?php echo htmlspecialchars($medicine['description'] ?: 'N/A'); ?></td>
                        <td><?php echo $breakdown['total_patients']; ?></td>
                        <td>$<?php echo number_format($breakdown['total_revenue'], 2); ?></td>
                    </tr>
                    <?php 
                        endif;
                    endforeach; 
                    
                    if (!$has_medicines):
                    ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: #666;">No medicines from inventory were used today</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Patient Treatment Details -->
        <div class="dcr-section">
            <h3>Patient Treatment Details</h3>
            <table class="dcr-table">
                <thead>
                                         <tr>
                         <th>Name</th>
                         <th>Status</th>
                         <th>Drug Name</th>
                         <th>Quantity</th>
                         <th>Dosage</th>
                         <th>Unit Price</th>
                     </tr>
                </thead>
                <tbody>
                    <?php foreach ($daily_data['treatments'] as $treatment): ?>
                                         <tr>
                         <td><?php echo htmlspecialchars($treatment['patient_name']); ?></td>
                         <td>
                             <?php 
                             $patient_status = getPatientStatus($treatment['patient_id'], $form_date);
                             ?>
                             <span class="patient-status <?php echo $patient_status === 'N' ? 'new' : 'followup'; ?>">
                                 <?php echo $patient_status === 'N' ? 'N' : 'F'; ?>
                             </span>
                         </td>
                         <td><?php echo htmlspecialchars($treatment['drug_name']); ?></td>
                         <td><?php echo $treatment['quantity']; ?></td>
                         <td><?php echo $treatment['dosage']; ?></td>
                         <td>$<?php echo number_format($treatment['unit_price'], 2); ?></td>
                     </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Patient Summary -->
        <div class="dcr-section">
            <h3>Patient Summary</h3>
            <table class="dcr-table">
                <thead>
                    <tr>
                        <th>Patient ID</th>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Total Spent</th>
                        <th>Treatments</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($daily_data['patients'] as $patient): ?>
                    <tr>
                        <td><?php echo $patient['id']; ?></td>
                        <td><?php echo htmlspecialchars($patient['name']); ?></td>
                        <td>
                            <span class="patient-status <?php echo $patient['status'] === 'N' ? 'new' : 'followup'; ?>">
                                <?php echo $patient['status'] === 'N' ? 'New' : 'Follow-up'; ?>
                            </span>
                        </td>
                        <td class="amount-positive">$<?php echo number_format($patient['total_spent'], 2); ?></td>
                        <td><?php echo count($patient['treatments']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php else: ?>
        <!-- Monthly Breakdown -->
        <div class="dcr-section">
            <h3>Monthly Breakdown - <?php echo $month_year; ?></h3>
            <table class="dcr-table" style="font-size: 11px;">
                <thead>
                    <tr>
                        <th rowspan="2">Day of week & date</th>
                        <th colspan="3">SEMAGLUTIDE</th>
                        <th colspan="3">TIRZEPATIDE</th>
                        <th colspan="3">PILLS</th>
                        <th colspan="2">All Other</th>
                        <th colspan="2">TOTAL</th>
                        <th colspan="3">TESTOSTERONE</th>
                    </tr>
                    <tr>
                        <th>NEW PTS</th>
                        <th>FU PTS</th>
                        <th>SG TOTAL</th>
                        <th>NEW PTS</th>
                        <th>FU PTS</th>
                        <th>TRZ TOTAL</th>
                        <th>NEW PTS</th>
                        <th>FU PTS</th>
                        <th>PILLS TOTAL</th>
                        <th>Revenue</th>
                        <th>REVENUE</th>
                        <th>NEW PTS</th>
                        <th>FU PTS</th>
                        <th>TESTOSTERONE TOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($i = 0; $i < count($monthly_data['dates']); $i++): ?>
                    <tr>
                        <td><?php echo $monthly_data['dates'][$i]; ?></td>
                        <td><?php echo $monthly_data['sema_new_patients'][$i] ?? 0; ?></td>
                        <td><?php echo $monthly_data['sema_followup_patients'][$i] ?? 0; ?></td>
                        <td>$<?php echo number_format($monthly_data['sema_revenue'][$i], 2); ?></td>
                        <td><?php echo $monthly_data['trz_new_patients'][$i] ?? 0; ?></td>
                        <td><?php echo $monthly_data['trz_followup_patients'][$i] ?? 0; ?></td>
                        <td>$<?php echo number_format($monthly_data['trz_revenue'][$i], 2); ?></td>
                        <td><?php echo $monthly_data['pills_new_patients'][$i] ?? 0; ?></td>
                        <td><?php echo $monthly_data['pills_followup_patients'][$i] ?? 0; ?></td>
                        <td>$<?php echo number_format($monthly_data['pills_revenue'][$i], 2); ?></td>
                        <td>$<?php echo number_format($monthly_data['other_revenue'][$i] ?? 0, 2); ?></td>
                        <td>$<?php echo number_format($monthly_data['total_revenue'][$i], 2); ?></td>
                        <td><?php echo $monthly_data['testosterone_new_patients'][$i] ?? 0; ?></td>
                        <td><?php echo $monthly_data['testosterone_followup_patients'][$i] ?? 0; ?></td>
                        <td>$<?php echo number_format($monthly_data['testosterone_revenue'][$i] ?? 0, 2); ?></td>
                    </tr>
                    <?php endfor; ?>
                    <tr style="font-weight: bold; background: #f0f0f0;">
                        <td>TOTAL</td>
                        <td><?php echo $monthly_data['totals']['sema_new_patients'] ?? 0; ?></td>
                        <td><?php echo $monthly_data['totals']['sema_followup_patients'] ?? 0; ?></td>
                        <td>$<?php echo number_format($monthly_data['totals']['sema_revenue'], 2); ?></td>
                        <td><?php echo $monthly_data['totals']['trz_new_patients'] ?? 0; ?></td>
                        <td><?php echo $monthly_data['totals']['trz_followup_patients'] ?? 0; ?></td>
                        <td>$<?php echo number_format($monthly_data['totals']['trz_revenue'], 2); ?></td>
                        <td><?php echo $monthly_data['totals']['pills_new_patients'] ?? 0; ?></td>
                        <td><?php echo $monthly_data['totals']['pills_followup_patients'] ?? 0; ?></td>
                        <td>$<?php echo number_format($monthly_data['totals']['pills_revenue'], 2); ?></td>
                        <td>$<?php echo number_format($monthly_data['totals']['other_revenue'] ?? 0, 2); ?></td>
                        <td>$<?php echo number_format($monthly_data['totals']['total_revenue'], 2); ?></td>
                        <td><?php echo $monthly_data['totals']['testosterone_new_patients'] ?? 0; ?></td>
                        <td><?php echo $monthly_data['totals']['testosterone_followup_patients'] ?? 0; ?></td>
                        <td>$<?php echo number_format($monthly_data['totals']['testosterone_revenue'] ?? 0, 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Export Buttons -->
        <div class="export-buttons">
            <button class="export-btn export-csv" onclick="exportToCSV()">Export to CSV</button>
            <button class="export-btn export-pdf" onclick="exportToPDF()">Export to PDF</button>
            <button class="export-btn" onclick="printReport()">Print Report</button>
        </div>
    </div>

    <script>
        function exportToCSV() {
            // Set export flag and submit form
            document.querySelector('input[name="form_export"]').value = 'csv';
            document.querySelector('form').submit();
        }
        
        function exportToPDF() {
            alert('PDF export functionality will be implemented');
        }
        
        function printReport() {
            window.print();
        }
        
        // Auto-refresh every 5 minutes for real-time updates
        setInterval(function() {
            if (!window.matchMedia('print').matches) {
                location.reload();
            }
        }, 300000);
    </script>
</body>
</html>
