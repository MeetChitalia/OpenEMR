<?php
/**
 * Enhanced DCR (Daily Collection Report) System - Fully Dynamic POS Integration
 * 
 * Complete integration with POS system including:
 * - All transaction types (purchase, dispense, administer, transfers, refunds, switches)
 * - Marketplace vs Administered medicine breakdown
 * - Individual medicine-level statistics
 * - Credit transfers and refunds
 * - Date range reporting (daily, weekly, monthly, yearly)
 * - Facility-specific and comparison reports
 * - Real-time revenue and patient tracking
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

if (!headers_sent()) {
    ob_start();
}

@ini_set('memory_limit', '512M');
@set_time_limit(120);

register_shutdown_function(static function () {
    $fatal = error_get_last();
    if (!$fatal) {
        return;
    }

    if (!in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    error_log(
        'Enhanced DCR fatal: ' .
        ($fatal['message'] ?? 'unknown error') .
        ' in ' . ($fatal['file'] ?? 'unknown file') .
        ' on line ' . ($fatal['line'] ?? 0)
    );

    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(500);
    }

    echo "<!DOCTYPE html><html><head><title>DCR Error</title><style>
        body{font-family:Arial,sans-serif;background:#f8fafc;color:#1f2937;padding:24px}
        .dcr-error{max-width:900px;margin:0 auto;background:#fff;border:1px solid #dbe3f0;border-radius:10px;padding:20px}
        .dcr-error h2{margin:0 0 10px;font-size:22px}
        .dcr-error p{margin:8px 0;line-height:1.5}
        .dcr-error code{background:#f1f5f9;padding:2px 5px;border-radius:4px}
    </style></head><body><div class='dcr-error'>
        <h2>DCR report failed to load</h2>
        <p>The staging server hit a PHP fatal error while rendering this report.</p>
        <p><strong>Message:</strong> " . htmlspecialchars((string) ($fatal['message'] ?? 'unknown error'), ENT_QUOTES, 'UTF-8') . "</p>
        <p><strong>File:</strong> <code>" . htmlspecialchars((string) ($fatal['file'] ?? 'unknown file'), ENT_QUOTES, 'UTF-8') . "</code></p>
        <p><strong>Line:</strong> <code>" . (int) ($fatal['line'] ?? 0) . "</code></p>
    </div></body></html>";
});

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/../../library/patient.inc");
require_once(__DIR__ . "/../../library/options.inc.php");

if (function_exists('enforceSelectedFacilityScopeForRequest')) {
    enforceSelectedFacilityScopeForRequest('form_facility');
}

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Check if user has access
if (!AclMain::aclCheckCore('acct', 'rep_a')) {
    echo "<div class='alert alert-danger'>Access Denied. Accounting reports access required.</div>";
    exit;
}

// CSRF protection
if (!empty($_POST)) {
    $csrfToken = $_POST['csrf_token_form'] ?? '';
    if ($csrfToken === '' || !CsrfUtils::verifyCsrfToken($csrfToken)) {
        CsrfUtils::csrfNotVerified();
    }
}

// Get report parameters
$form_date = $_REQUEST['form_date'] ?? date('Y-m-d');
$form_to_date = $_REQUEST['form_to_date'] ?? date('Y-m-d');
$form_facility = $_REQUEST['form_facility'] ?? '';
$form_report_type = $_REQUEST['form_report_type'] ?? 'daily';
$form_medicine = $_REQUEST['form_medicine'] ?? '';
$form_payment_method = $_REQUEST['form_payment_method'] ?? '';
$form_export = $_REQUEST['form_export'] ?? '';
$form_month = $_REQUEST['form_month'] ?? '';
$form_email_to = $_POST['form_email_to'] ?? [];
$form_email_to = is_array($form_email_to) ? $form_email_to : [$form_email_to];
$form_email_to = array_values(array_filter(array_map(static function ($email) {
    return trim((string) $email);
}, $form_email_to), static function ($email) {
    return $email !== '';
}));
$dcr_email_recipients = [
    'chitaliameet656@gmail.com',
    'carleyn42@gmail.com',
    'khushick32@gmail.com',
    'nsmithemail@gmail.com',
    'copelandc@charter.net',
    'amwlsouth@gmail.com',
    'amontoya1988@hotmail.com',
    'darrenwilliams@me.com',
    'silasamy@gmail.com',
    'dharmeshvora@gmail.com',
];

if ($form_report_type === 'monthly') {
    if (empty($form_month)) {
        $form_month = date('Y-m');
    }
    $month_obj = DateTime::createFromFormat('Y-m', $form_month);
    if ($month_obj instanceof DateTime) {
        $first_day = (clone $month_obj)->setDate((int)$month_obj->format('Y'), (int)$month_obj->format('m'), 1);
        $last_day = (clone $first_day)->modify('last day of this month');
        $form_date = $first_day->format('Y-m-d');
        $form_to_date = $last_day->format('Y-m-d');
    }
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
if ($form_report_type === 'monthly' && !empty($form_month)) {
    $month_obj_display = DateTime::createFromFormat('Y-m', $form_month);
    if ($month_obj_display instanceof DateTime) {
        $month_year = $month_obj_display->format('F Y');
    }
}

/**
 * Get comprehensive POS transaction data for date range
 */
function getComprehensivePOSData($start_date, $end_date, $facility_id = '') {
    $data = [
        // Revenue tracking
        'total_revenue' => 0,
        'purchase_revenue' => 0,
        'dispense_revenue' => 0,
        'administer_revenue' => 0,
        'external_payment_revenue' => 0,
        'refunds_total' => 0,
        'credits_transferred' => 0,
        'net_revenue' => 0,
        
        // Transaction counts
        'transaction_counts' => [
            'purchase' => 0,
            'dispense' => 0,
            'administer' => 0,
            'medicine_switch' => 0,
            'refund' => 0,
            'credit_transfer' => 0,
            'external_payment' => 0
        ],
        
        // Payment method breakdown
        'payment_methods' => [],
        
        // Patient tracking
        'unique_patients' => [],
        'new_patients' => 0,
        'returning_patients' => 0,
        'patient_rows' => [],
        
        // Medicine breakdown (by individual medicine)
        'medicines' => [],
        
        // Category breakdown
        'categories' => [],

        // Detailed per-patient metrics and summary breakdowns
        'breakdown' => initializeBreakdownStructure(),
        
        // Transaction details
        'transactions' => [],
        
        // Marketplace vs Administered
        'marketplace_quantity' => 0,
        'administered_quantity' => 0,
        'marketplace_revenue' => 0,
        'administered_revenue' => 0
    ];
    
    try {
        // Build facility filter
        $facility_filter = '';
        $start_datetime = $start_date . ' 00:00:00';
        $end_datetime = date('Y-m-d H:i:s', strtotime($end_date . ' +1 day'));
        $bind_array = [$start_datetime, $end_datetime];
        $void_filter = '';
        if (posTransactionsHaveVoidColumns()) {
            $void_filter = " AND COALESCE(pt.voided, 0) = 0 AND pt.transaction_type != 'void'";
        } else {
            $void_filter = " AND pt.transaction_type != 'void'";
        }
        $backdate_filter = dcrBuildBackdateExclusionSql('pt');
        
        if ($facility_id) {
            // Check if pos_transactions has facility_id column.
            // If not, fall back to the linked patient's facility.
            $check_column = "SHOW COLUMNS FROM pos_transactions LIKE 'facility_id'";
            $col_result = sqlStatement($check_column);
            if ($col_result && sqlFetchArray($col_result)) {
                $facility_filter = ' AND (pt.facility_id = ? OR (pt.facility_id IS NULL AND p.facility_id = ?))';
                $bind_array[] = $facility_id;
                $bind_array[] = $facility_id;
            } else {
                $facility_filter = ' AND p.facility_id = ?';
                $bind_array[] = $facility_id;
            }
        }
        
        // Get all POS transactions for date range
        $query = "SELECT pt.*, 
                         p.fname, p.lname, p.DOB,
                         u.username as created_by_username
                  FROM pos_transactions pt
                  INNER JOIN patient_data p ON pt.pid = p.pid
                  LEFT JOIN users u ON pt.user_id = u.id
                  WHERE pt.created_date >= ? AND pt.created_date < ?" . $facility_filter . $void_filter . $backdate_filter . "
                  ORDER BY pt.created_date DESC";
        
        error_log("Enhanced DCR Query: $query");
        error_log("Enhanced DCR Query Params: " . json_encode($bind_array));
        
        $result = sqlStatement($query, $bind_array);
        error_log("Enhanced DCR: Query executed, processing results...");
        
        if ($result) {
            while ($row = sqlFetchArray($result)) {
                $transaction_type = $row['transaction_type'];
                $amount = floatval($row['amount']);
                $items = json_decode($row['items'], true) ?: [];
                
                // Patient tracking
                $patient_key = $row['pid'];
                if (!isset($data['unique_patients'][$patient_key])) {
                    $patient_name = trim(($row['fname'] ?? '') . ' ' . ($row['lname'] ?? ''));
                    $data['unique_patients'][$patient_key] = [
                        'pid' => $row['pid'],
                        'name' => $patient_name ?: 'Unknown',
                        'dob' => $row['DOB'] ?? '',
                        'total_spent' => 0,
                        'transactions' => 0,
                        'first_visit' => $row['created_date'],
                        'first_visit_timestamp' => strtotime($row['created_date']),
                        'is_new' => false,
                        'order_number' => 0,  // Will be assigned after all data is collected
                        'patient_number' => normalizeDcrPatientNumber($row['patient_number'] ?? null),
                        'details' => initializePatientDetails()
                    ];
                    
                    // Check if new patient
                    $is_new = isNewPatient(
                        $row['pid'],
                        $start_date,
                        isset($row['facility_id']) ? (int) $row['facility_id'] : ($facility_id ? (int) $facility_id : null)
                    );
                    $data['unique_patients'][$patient_key]['is_new'] = $is_new;
                    if ($is_new) {
                        $data['new_patients']++;
                    } else {
                        $data['returning_patients']++;
                    }
                } else {
                    // Update first_visit if this transaction is earlier
                    $current_timestamp = strtotime($row['created_date']);
                    if ($current_timestamp < $data['unique_patients'][$patient_key]['first_visit_timestamp']) {
                        $data['unique_patients'][$patient_key]['first_visit'] = $row['created_date'];
                        $data['unique_patients'][$patient_key]['first_visit_timestamp'] = $current_timestamp;
                    }
                }
                
               $patient_ref =& $data['unique_patients'][$patient_key];

                $rowPatientNumber = normalizeDcrPatientNumber($row['patient_number'] ?? null);
                if ($rowPatientNumber !== null) {
                    $patient_ref['patient_number'] = $rowPatientNumber;
                }

// Ensure details exists first
if (!isset($patient_ref['details']) || !is_array($patient_ref['details'])) {
    $patient_ref['details'] = initializePatientDetails();
}

// Set visit_type strictly from DB (no previous logic)
// Ensure details exists
if (!isset($patient_ref['details']) || !is_array($patient_ref['details'])) {
    $patient_ref['details'] = initializePatientDetails();
}

// Lock visit_type to the MOST RECENT transaction for that patient (query is DESC)
if (empty($patient_ref['details']['__visit_type_set'])) {
    $patient_ref['details']['visit_type'] = $row['visit_type'] ?? '-';
    $patient_ref['details']['__visit_type_set'] = 1;
}
// Capture price override notes
if (!empty($row['price_override_notes'])) {
    $note = trim((string)$row['price_override_notes']);
    if ($note !== '') {
        appendDcrPatientNote($patient_ref['details'], $note);
    }
}

foreach (buildDcrPrepayNotes($items) as $prepayNote) {
    appendDcrPatientNote($patient_ref['details'], $prepayNote);
}
                
                // Only add to total_spent if it's not a refund or transfer (to match total_revenue calculation)
                if (!shouldExcludeTransactionFromRevenue($transaction_type)) {
                    $patient_ref['total_spent'] += $amount;
                }
                $patient_ref['transactions']++;
                
                if (is_array($items)) {
                    foreach ($items as $item) {
                        updatePatientDetails($patient_ref, $item, $transaction_type, $row['created_date'] ?? '');
                    }
                }
                
                // Track payment method
                $payment_method = $row['payment_method'] ?? 'N/A';
                if (!empty($payment_method) && $payment_method != 'NULL' && $amount > 0) {
                    if (!isset($data['payment_methods'][$payment_method])) {
                        $data['payment_methods'][$payment_method] = [
                            'name' => $payment_method,
                            'total_revenue' => 0,
                            'transaction_count' => 0
                        ];
                    }
                    $data['payment_methods'][$payment_method]['total_revenue'] += $amount;
                    $data['payment_methods'][$payment_method]['transaction_count']++;
                                                                                                                    
                    // Track payment method per patient for breakdown
                    if (!isset($patient_ref['payment_methods'])) {
                        $patient_ref['payment_methods'] = [];
                    }
                    if (!isset($patient_ref['payment_methods'][$payment_method])) {
                        $patient_ref['payment_methods'][$payment_method] = 0;
                    }
                    $patient_ref['payment_methods'][$payment_method] += $amount;
                }
                
                // Process based on transaction type
                switch ($transaction_type) {
                    case 'purchase':
                    case 'purchase_and_dispens':
                    case 'purchase_and_alterna':
                        $data['purchase_revenue'] += $amount;
                        $data['transaction_counts']['purchase']++;
                        
                        // Process items
                        foreach ($items as $item) {
                            processMedicineItem($data, $item, $row, 'purchase');
                        }
                        break;
                        
                    case 'dispense':
                    case 'alternate_lot_dispense':
                        $data['dispense_revenue'] += $amount;
                        $data['transaction_counts']['dispense']++;
                        
                        foreach ($items as $item) {
                            processMedicineItem($data, $item, $row, 'dispense');
                        }
                        break;
                        
                    case 'administer':
                        $data['administer_revenue'] += $amount;
                        $data['transaction_counts']['administer']++;
                        
                        foreach ($items as $item) {
                            processMedicineItem($data, $item, $row, 'administer');
                        }
                        break;

                    case 'dispense_and_administer':
                        $data['dispense_revenue'] += $amount;
                        $data['administer_revenue'] += $amount;
                        $data['transaction_counts']['dispense']++;
                        $data['transaction_counts']['administer']++;

                        foreach ($items as $item) {
                            processMedicineItem($data, $item, $row, 'dispense_and_administer');
                        }
                        break;
                        
                    case 'external_payment':
                        $data['external_payment_revenue'] += $amount;
                        $data['transaction_counts']['external_payment']++;
                        
                        // Process items for external payments (they also have medicine data)
                        foreach ($items as $item) {
                            processMedicineItem($data, $item, $row, 'external_payment');
                        }
                        break;
                        
                    case 'medicine_switch':
                        $data['transaction_counts']['medicine_switch']++;
                        // Medicine switches tracked separately
                        break;
                        
                    case 'credit_payment':
                    case 'transfer_in':
                    case 'transfer_out':
                        $data['credits_transferred'] += abs($amount);
                        $data['transaction_counts']['credit_transfer']++;
                        // Track credit per patient (positive for transfer_in, negative for transfer_out)
                        if (isset($patient_ref)) {
                            if (!isset($patient_ref['details'])) {
                                $patient_ref['details'] = initializePatientDetails();
                            }
                            if ($transaction_type === 'transfer_in' || $transaction_type === 'credit_payment') {
                                $patient_ref['details']['credit_amount'] = ($patient_ref['details']['credit_amount'] ?? 0) + abs($amount);
                            }
                        }
                        
                        // Process items if any
                        if (is_array($items) && !empty($items)) {
                            foreach ($items as $item) {
                                processMedicineItem($data, $item, $row, 'credit_transfer');
                            }
                        }
                        break;
                        
                    case 'credit_for_remaining':
                        $data['refunds_total'] += abs($amount);
                        $data['transaction_counts']['refund']++;
                        // Track refund per patient
                        if (isset($patient_ref)) {
                            if (!isset($patient_ref['details'])) {
                                $patient_ref['details'] = initializePatientDetails();
                            }
                            $patient_ref['details']['refund_amount'] = ($patient_ref['details']['refund_amount'] ?? 0) + abs($amount);
                        }
                        break;
                }
                
                // Add to transactions list
                $data['transactions'][] = [
                    'id' => $row['id'],
                    'pid' => $row['pid'],
                    'patient_name' => $data['unique_patients'][$patient_key]['name'],
                    'receipt_number' => $row['receipt_number'],
                    'transaction_type' => $transaction_type,
                    'payment_method' => $row['payment_method'] ?? 'N/A',
                    'amount' => $amount,
                    'items' => $items,
                    'created_date' => $row['created_date'],
                    'created_by' => $row['created_by_username'] ?? 'System'
                ];
                
                // Update total revenue (excluding refunds and transfers)
                if (!shouldExcludeTransactionFromRevenue($transaction_type)) {
                    $data['total_revenue'] += $amount;
                }

                unset($patient_ref);
            }
        }
        
        // Calculate net revenue
        $data['net_revenue'] = $data['total_revenue'];
        
        applyPatientCardPunchTotals($data, $end_date);
        rebuildPatientSummaries($data);
        
    } catch (Exception $e) {
        error_log("Error getting comprehensive POS data: " . $e->getMessage());
    }
    
    return $data;
}

function dcrApplyEmailAttachmentFilters(array &$reportData, $formPaymentMethod = '', $formMedicine = ''): void
{
    if ($formPaymentMethod) {
        $filtered_transactions = [];
        $filtered_patients = [];
        $filtered_medicines = [];

        $total_revenue = 0;
        $marketplace_qty = 0;
        $administered_qty = 0;

        foreach ($reportData['transactions'] as $transaction) {
            if (($transaction['payment_method'] ?? 'N/A') == $formPaymentMethod) {
                $filtered_transactions[] = $transaction;
                $filtered_patients[$transaction['pid']] = $reportData['unique_patients'][$transaction['pid']] ?? [];

                if (!shouldExcludeTransactionFromRevenue($transaction['transaction_type'] ?? '')) {
                    $total_revenue += $transaction['amount'];
                }

                if (is_array($transaction['items'])) {
                    foreach ($transaction['items'] as $item) {
                        $drug_id = intval($item['drug_id'] ?? 0);
                        if ($drug_id == 0 && isset($item['id']) && is_string($item['id']) && preg_match('/drug_(\d+)/', $item['id'], $matches)) {
                            $drug_id = intval($matches[1]);
                        }

                        if ($drug_id > 0) {
                            if (!isset($filtered_medicines[$drug_id])) {
                                $filtered_medicines[$drug_id] = $reportData['medicines'][$drug_id] ?? [
                                    'drug_id' => $drug_id,
                                    'name' => $item['name'] ?? 'Unknown',
                                    'category' => 'Other',
                                    'total_quantity' => 0,
                                    'total_dispensed' => 0,
                                    'total_administered' => 0,
                                    'total_revenue' => 0,
                                    'transaction_count' => 0,
                                    'patients' => []
                                ];
                                $filtered_medicines[$drug_id]['total_quantity'] = 0;
                                $filtered_medicines[$drug_id]['total_dispensed'] = 0;
                                $filtered_medicines[$drug_id]['total_administered'] = 0;
                                $filtered_medicines[$drug_id]['total_revenue'] = 0;
                                $filtered_medicines[$drug_id]['transaction_count'] = 0;
                                $filtered_medicines[$drug_id]['patients'] = [];
                            }

                            $qty = getDcrReportedMedicineQuantity($item, $transaction['transaction_type'] ?? '');
                            $disp_qty = dcrNormalizeQuantity($item['dispense_quantity'] ?? 0);
                            $admin_qty = dcrNormalizeQuantity($item['administer_quantity'] ?? 0);
                            $item_total = calculateDcrItemTotal($item, $transaction['created_date'] ?? '');

                            $filtered_medicines[$drug_id]['total_quantity'] += $qty;
                            $filtered_medicines[$drug_id]['total_dispensed'] += $disp_qty;
                            $filtered_medicines[$drug_id]['total_administered'] += $admin_qty;
                            $filtered_medicines[$drug_id]['total_revenue'] += $item_total;
                            $filtered_medicines[$drug_id]['transaction_count']++;

                            if (!in_array($transaction['pid'], $filtered_medicines[$drug_id]['patients'])) {
                                $filtered_medicines[$drug_id]['patients'][] = $transaction['pid'];
                            }

                            $marketplace_qty += $disp_qty;
                            $administered_qty += $admin_qty;
                        }
                    }
                }
            }
        }

        $reportData['transactions'] = $filtered_transactions;
        $reportData['unique_patients'] = $filtered_patients;
        $reportData['medicines'] = $filtered_medicines;
        $reportData['total_revenue'] = $total_revenue;
        $reportData['marketplace_quantity'] = $marketplace_qty;
        $reportData['administered_quantity'] = $administered_qty;
        $reportData['net_revenue'] = $total_revenue;
    }

    if ($formMedicine) {
        $filtered_medicines = [];
        $filtered_transactions = [];
        $filtered_patients = [];

        foreach ($reportData['medicines'] as $drug_id => $medicine) {
            if ($drug_id == $formMedicine) {
                $filtered_medicines[$drug_id] = $medicine;
            }
        }

        foreach ($reportData['transactions'] as $transaction) {
            $has_medicine = false;
            if (is_array($transaction['items'])) {
                foreach ($transaction['items'] as $item) {
                    $item_drug_id = intval($item['drug_id'] ?? 0);
                    if ($item_drug_id == 0 && isset($item['id']) && is_string($item['id']) && preg_match('/drug_(\d+)/', $item['id'], $matches)) {
                        $item_drug_id = intval($matches[1]);
                    }
                    if ($item_drug_id == $formMedicine) {
                        $has_medicine = true;
                        break;
                    }
                }
            }
            if ($has_medicine) {
                $filtered_transactions[] = $transaction;
                $filtered_patients[$transaction['pid']] = $reportData['unique_patients'][$transaction['pid']] ?? [];
            }
        }

        $reportData['medicines'] = $filtered_medicines;
        $reportData['transactions'] = $filtered_transactions;
        $reportData['unique_patients'] = $filtered_patients;
        $reportData['total_revenue'] = 0;
        $reportData['marketplace_quantity'] = 0;
        $reportData['administered_quantity'] = 0;

        if (!empty($filtered_medicines)) {
            $medicine = reset($filtered_medicines);
            $reportData['total_revenue'] = $medicine['total_revenue'];
            $reportData['marketplace_quantity'] = $medicine['total_dispensed'];
            $reportData['administered_quantity'] = $medicine['total_administered'];
        }

        $reportData['net_revenue'] = $reportData['total_revenue'];
    }

    rebuildPatientSummaries($reportData);
}

function dcrBuildEmailAttachmentReportData(
    array $reportData,
    string $formReportType,
    string $formDate,
    string $formFacility,
    $formPaymentMethod = '',
    $formMedicine = ''
): array {
    if ($formReportType !== 'daily' || empty($formDate)) {
        return $reportData;
    }

    $monthStart = date('Y-m-01', strtotime($formDate));
    $monthToDate = $formDate;
    $monthReportData = getComprehensivePOSData($monthStart, $monthToDate, $formFacility);
    dcrApplyEmailAttachmentFilters($monthReportData, $formPaymentMethod, $formMedicine);

    $reportData['daily_breakdown'] = $monthReportData['daily_breakdown'] ?? ($reportData['daily_breakdown'] ?? ['rows' => [], 'totals' => [], 'averages' => []]);
    $reportData['monthly_revenue_grid'] = $monthReportData['monthly_revenue_grid'] ?? ($reportData['monthly_revenue_grid'] ?? ['rows' => [], 'totals' => []]);
    $reportData['shot_tracker'] = $monthReportData['shot_tracker'] ?? ($reportData['shot_tracker'] ?? ['rows' => [], 'totals' => []]);

    return $reportData;
}

/**
 * Initialize the breakdown structure for summary statistics
 */
function initializeBreakdownStructure() {
    return [
        'sema' => [
            'new_patients' => 0,
            'follow_patients' => 0,
            'total_patients' => 0,
            'new_revenue' => 0,
            'follow_revenue' => 0,
            'total_revenue' => 0,
            'cards_sold' => 0,
            'injection_amount' => 0,  // Sema injection revenue only (not total patient revenue)
        ],
        'tirz' => [
            'new_patients' => 0,
            'follow_patients' => 0,
            'total_patients' => 0,
            'new_revenue' => 0,
            'follow_revenue' => 0,
            'total_revenue' => 0,
            'cards_sold' => 0,
        ],
        'pills' => [
            'new_patients' => 0,
            'follow_patients' => 0,
            'total_patients' => 0,
            'new_revenue' => 0,
            'follow_revenue' => 0,
            'total_revenue' => 0,
        ],
        'testosterone' => [
            'new_patients' => 0,
            'follow_patients' => 0,
            'total_patients' => 0,
            'new_revenue' => 0,
            'follow_revenue' => 0,
            'total_revenue' => 0,
            'cards_sold' => 0,
        ],
        'other' => [
            'new_patients' => 0,
            'follow_patients' => 0,
            'total_patients' => 0,
            'new_revenue' => 0,
            'follow_revenue' => 0,
            'total_revenue' => 0,
        ],
        'other_revenue' => 0,
        'total_trz_patients' => 0,
        'total_trz_revenue' => 0,
        'sema_trz_cards_sold' => 0,
        'total_revenue' => 0,
        'total_patients' => 0
    ];
}

/**
 * Initialize the per-patient detail structure
 */
function initializePatientDetails() {
    return [
        'action_code' => '',
        'ov_amount' => 0,
        'bw_amount' => 0,
        'card_punch_in_office' => 0,
        'card_punch_take_home' => 0,
        'card_punch_amount' => 0,
        'card_punch_purchased' => 0,
        'lipo_admin_count' => 0,
        'lipo_card_punch_total' => 0,
        'lipo_takehome_count' => 0,
        'lipo_amount' => 0,
        'lipo_purchased_count' => 0,
        'lipo_doses' => [],
        'sema_admin_count' => 0,
        'sema_card_punch_total' => 0,
        'sema_takehome_count' => 0,
        'sema_amount' => 0,
        'sema_purchased_count' => 0,
        'sema_doses' => [],
        'tirz_admin_count' => 0,
        'tirz_card_punch_total' => 0,
        'tirz_takehome_count' => 0,
        'tirz_amount' => 0,
        'tirz_purchased_count' => 0,
        'tirz_doses' => [],
        'testosterone_admin_count' => 0,
        'testosterone_takehome_count' => 0,
        'testosterone_purchased_count' => 0,
        'testosterone_card_punch_total' => 0,
        'testosterone_revenue' => 0,
        'supplement_amount' => 0,
        'supplement_count' => 0,
        'drink_amount' => 0,
        'drink_count' => 0,
        'afterpay_fee' => 0,
        'tax_amount' => 0,
        'taxable_amount' => 0,
        'subtotal' => 0,
        'discount_amount' => 0,
        'refund_amount' => 0,
        'credit_amount' => 0,
        'pill_amount' => 0,
        'pill_count' => 0,
        'lab_amount' => 0,
        'lab_count' => 0,
        'total_amount' => 0,
        'primary_category' => 'other',
        'categories_involved' => [],
        'prepaid_categories' => [],
        'price_override_notes' => ''
    ];
}

function shouldExcludeTransactionFromRevenue($transaction_type) {
    return in_array($transaction_type, ['credit_for_remaining', 'transfer_out'], true);
}

function getCardCountFromTakeHome($quantity, $cycleLength)
{
    $quantity = (int) round(floatval($quantity));
    $cycleLength = (int) $cycleLength;

    if ($quantity <= 0 || $cycleLength <= 0) {
        return 0;
    }

    return (int) floor($quantity / $cycleLength);
}

function normalizeDcrPatientNumber($value)
{
    $value = trim((string) ($value ?? ''));
    if ($value === '' || !is_numeric($value)) {
        return null;
    }

    return (int) round((float) $value);
}

function dcrBuildBackdateExclusionSql($alias = '')
{
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return " AND COALESCE(" . $prefix . "receipt_number, '') NOT LIKE 'BD-%'";
}

function getDcrAdministerIncrement($item, $transactionType = '')
{
    $adminQty = (int) round(floatval($item['administer_quantity'] ?? 0));
    if ($adminQty > 0) {
        return $adminQty;
    }

    if (strtolower(trim((string) $transactionType)) === 'administer') {
        return (int) round(floatval($item['quantity'] ?? 0));
    }

    return 0;
}

function dcrNormalizeQuantity($value): float
{
    return round((float) ($value ?? 0), 4);
}

function getPatientHistoricalCardPunchTotals(array $patientIds, $endDate)
{
    $totals = [];
    foreach ($patientIds as $pid) {
        $totals[(int) $pid] = [
            'lipo' => 0,
            'sema' => 0,
            'tirz' => 0,
            'testosterone' => 0,
        ];
    }

    $patientIds = array_values(array_filter(array_map('intval', $patientIds)));
    if (empty($patientIds) || empty($endDate)) {
        return $totals;
    }

    $placeholders = implode(',', array_fill(0, count($patientIds), '?'));
    $binds = $patientIds;
    $binds[] = $endDate;

    $voidFilter = posTransactionsHaveVoidColumns()
        ? " AND COALESCE(voided, 0) = 0 AND transaction_type != 'void'"
        : " AND transaction_type != 'void'";
    $sql = "SELECT pid, transaction_type, items
            FROM pos_transactions
            WHERE pid IN ($placeholders)
              AND DATE(created_date) <= ?" . $voidFilter . "
              AND transaction_type IN ('administer', 'dispense', 'dispense_and_administer', 'external_payment')
            ORDER BY created_date ASC, id ASC";

    $result = sqlStatement($sql, $binds);
    if (!$result) {
        return $totals;
    }

    while ($row = sqlFetchArray($result)) {
        $pid = (int) ($row['pid'] ?? 0);
        if ($pid <= 0 || !isset($totals[$pid])) {
            continue;
        }

        $items = json_decode($row['items'] ?? '[]', true);
        if (!is_array($items)) {
            continue;
        }

        foreach ($items as $item) {
            $increment = getDcrAdministerIncrement($item, $row['transaction_type'] ?? '');
            if ($increment <= 0) {
                continue;
            }

            $type = classifyItemTypeFromItem($item);
            if (isset($totals[$pid][$type])) {
                $totals[$pid][$type] += $increment;
            }
        }
    }

    return $totals;
}

function applyPatientCardPunchTotals(&$data, $endDate)
{
    if (empty($data['unique_patients']) || empty($endDate)) {
        return;
    }

    $historicalTotals = getPatientHistoricalCardPunchTotals(array_keys($data['unique_patients']), $endDate);
    foreach ($data['unique_patients'] as $pid => &$patient) {
        if (!isset($patient['details']) || !is_array($patient['details'])) {
            $patient['details'] = initializePatientDetails();
        }

        $pid = (int) $pid;
        $patientTotals = $historicalTotals[$pid] ?? [];
        $patient['details']['lipo_card_punch_total'] = (int) ($patientTotals['lipo'] ?? 0);
        $patient['details']['sema_card_punch_total'] = (int) ($patientTotals['sema'] ?? 0);
        $patient['details']['tirz_card_punch_total'] = (int) ($patientTotals['tirz'] ?? 0);
        $patient['details']['testosterone_card_punch_total'] = (int) ($patientTotals['testosterone'] ?? 0);
    }
    unset($patient);
}

function resolvePatientCardPunchDisplay(array $details, $category, $cycleLength)
{
    $adminKey = $category . '_admin_count';
    $adminCount = (int) round(floatval($details[$adminKey] ?? 0));

    if ($adminCount <= 0) {
        return '';
    }

    return formatCardPunchCycleSpanCell($adminCount, $cycleLength, $adminCount);
}

function isGrossBreakdownNewPatient(array $patient): bool
{
    $details = $patient['details'] ?? [];
    $visitType = strtoupper(trim((string) ($details['visit_type'] ?? '')));
    $actionCode = strtoupper(trim((string) ($details['action_code'] ?? '')));

    return ($visitType === 'N' || $actionCode === 'N');
}

function hasExplicitGrossBreakdownVisitCode(array $patient): bool
{
    $details = $patient['details'] ?? [];
    $visitType = strtoupper(trim((string) ($details['visit_type'] ?? '')));
    $actionCode = strtoupper(trim((string) ($details['action_code'] ?? '')));

    return in_array($visitType, ['N', 'F', 'R'], true) || in_array($actionCode, ['N', 'F', 'R'], true);
}

function getGrossBreakdownVisitBucket(array $patient): string
{
    $details = $patient['details'] ?? [];
    $visitType = strtoupper(trim((string) ($details['visit_type'] ?? '')));
    $actionCode = strtoupper(trim((string) ($details['action_code'] ?? '')));

    if ($visitType === 'N' || $actionCode === 'N') {
        return 'new';
    }

    if ($visitType === 'R' || $actionCode === 'R') {
        return 'rtn';
    }

    if ($visitType === 'F' || $actionCode === 'F') {
        return 'fu';
    }

    $receivedInjections = (
        intval($details['sema_admin_count'] ?? 0) +
        intval($details['sema_takehome_count'] ?? 0) +
        intval($details['tirz_admin_count'] ?? 0) +
        intval($details['tirz_takehome_count'] ?? 0) +
        intval($details['lipo_admin_count'] ?? 0) +
        intval($details['lipo_takehome_count'] ?? 0)
    );

    if ($receivedInjections > 0) {
        return !empty($patient['is_new']) ? 'new' : 'rtn';
    }

    return !empty($patient['is_new']) ? 'new' : 'rtn';
}

function appendDcrPatientNote(array &$details, string $note) {
    $note = trim($note);
    if ($note === '') {
        return;
    }

    $existing = trim((string)($details['price_override_notes'] ?? ''));
    if ($existing === '') {
        $details['price_override_notes'] = $note;
        return;
    }

    $existingParts = array_filter(array_map('trim', explode(' | ', $existing)));
    if (in_array($note, $existingParts, true)) {
        return;
    }

    $details['price_override_notes'] = $existing . ' | ' . $note;
}

function formatDcrPrepayDate($date) {
    $date = trim((string)$date);
    if ($date === '') {
        return '';
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    return date('m/d/Y', $timestamp);
}

function buildDcrPrepayNotes($items) {
    if (!is_array($items)) {
        return [];
    }

    $notes = [];
    foreach ($items as $item) {
        if (empty($item['prepay_selected'])) {
            continue;
        }

        $itemName = trim((string)($item['name'] ?? $item['display_name'] ?? ''));
        $note = 'Prepay';
        if ($itemName !== '') {
            $note .= ' - ' . $itemName;
        }

        $detailParts = [];
        $prepayDate = formatDcrPrepayDate($item['prepay_date'] ?? '');
        $prepayReference = trim((string)($item['prepay_sale_reference'] ?? ''));

        if ($prepayDate !== '') {
            $detailParts[] = 'Date: ' . $prepayDate;
        }
        if ($prepayReference !== '') {
            $detailParts[] = 'Notes: ' . $prepayReference;
        }

        if (!empty($detailParts)) {
            $note .= ' (' . implode('; ', $detailParts) . ')';
        }

        $notes[] = $note;
    }

    return array_values(array_unique($notes));
}

/**
 * Determine the classification of an item based on its name
 */
function classifyItemType($item_name) {
    $normalized = strtolower($item_name ?? '');
    $normalized_padded = ' ' . preg_replace('/[^a-z0-9]+/', ' ', $normalized) . ' ';
    
    if ($normalized === '') {
        return 'other';
    }
    
    // Check OV and BW first (before pills and other items)
    // OV keywords: consult, office visit, ov, office, consultation, provider visit
    if (strpos($normalized, 'consult') !== false || strpos($normalized, 'office visit') !== false || 
        strpos($normalized, 'ov') !== false || strpos($normalized, 'office') !== false || 
        strpos($normalized, 'consultation') !== false || strpos($normalized, 'provider visit') !== false) {
        return 'ov';
    }
    // BW keywords: lab, blood, bw, blood work, laboratory
    if (strpos($normalized, 'lab') !== false || strpos($normalized, 'blood') !== false || 
        strpos($normalized, 'bw') !== false || strpos($normalized, 'blood work') !== false || 
        strpos($normalized, 'laboratory') !== false) {
        return 'bw';
    }
    
    // Then check other specific items
    if (strpos($normalized, 'afterpay') !== false) {
        return 'afterpay';
    }
    if (strpos($normalized, 'tax') !== false) {
        return 'tax';
    }
    if (
        strpos($normalized, 'tirz') !== false ||
        strpos($normalized, 'tirzep') !== false ||
        strpos($normalized, 'tirzepatide') !== false ||
        strpos($normalized_padded, ' trz ') !== false
    ) {
        return 'tirz';
    }
    if (
        strpos($normalized, 'sema') !== false ||
        strpos($normalized, 'semaglutide') !== false ||
        strpos($normalized_padded, ' sg ') !== false
    ) {
        return 'sema';
    }
    if (
        strpos($normalized, 'lipo') !== false ||
        strpos($normalized, 'lipo b') !== false ||
        strpos($normalized, 'b12') !== false
    ) {
        return 'lipo';
    }
    if ($normalized === 'phentermine #28' || $normalized === 'phentermine #14') {
        return 'pills';
    }
    if (strpos($normalized, 'supplement') !== false || strpos($normalized, 'bari') !== false || strpos($normalized, 'metatrim') !== false || strpos($normalized, 'lipobc') !== false) {
        return 'supplement';
    }
    if (strpos($normalized, 'shake') !== false || strpos($normalized, 'drink') !== false || strpos($normalized, 'protein') !== false) {
        return 'drink';
    }
    if (strpos($normalized, 'card') !== false && strpos($normalized, 'shot') !== false) {
        return 'card';
    }
    if (strpos($normalized, 'card') !== false) {
        return 'card';
    }
    if (
        strpos($normalized, 'testo') !== false ||
        strpos($normalized, 'testosterone') !== false ||
        strpos($normalized_padded, ' tes ') !== false
    ) {
        return 'testosterone';
    }
    
    return 'other';
}

function extractItemDrugId($item)
{
    $drugId = (int) ($item['drug_id'] ?? 0);
    if ($drugId > 0) {
        return $drugId;
    }

    if (isset($item['id']) && is_string($item['id']) && preg_match('/drug_(\d+)/', $item['id'], $matches)) {
        return (int) $matches[1];
    }

    return 0;
}

function classifyItemTypeFromItem($item)
{
    $candidates = [];
    $candidates[] = (string) ($item['name'] ?? '');
    $candidates[] = (string) ($item['display_name'] ?? '');

    $drugId = extractItemDrugId($item);
    if ($drugId > 0) {
        $medicineInfo = getMedicineInfo($drugId);
        $candidates[] = (string) ($medicineInfo['name'] ?? '');
        $candidates[] = (string) ($medicineInfo['category'] ?? '');
        $candidates[] = (string) ($medicineInfo['dosage'] ?? '');
    }

    foreach ($candidates as $candidate) {
        $type = classifyItemType($candidate);
        if ($type !== 'other') {
            return $type;
        }
    }

    return 'other';
}

function isDcrTaxableItem($item)
{
    $nameCandidates = [
        (string) ($item['name'] ?? ''),
        (string) ($item['display_name'] ?? ''),
    ];

    $drugId = extractItemDrugId($item);
    if ($drugId > 0) {
        $medicineInfo = getMedicineInfo($drugId);
        $nameCandidates[] = (string) ($medicineInfo['name'] ?? '');
    }

    foreach ($nameCandidates as $candidate) {
        $normalized = strtolower(trim($candidate));
        if ($normalized === '') {
            continue;
        }

        if (
            strpos($normalized, 'metatrim') !== false ||
            strpos($normalized, 'meta trim') !== false ||
            strpos($normalized, 'baricare') !== false ||
            strpos($normalized, 'bari care') !== false
        ) {
            return true;
        }
    }

    return false;
}

/**
 * Attempt to extract dosage information from an item name
 */
function extractDoseFromName($item_name) {
    if (empty($item_name)) {
        return '';
    }
    
    if (preg_match('/(\d+(?:\.\d+)?)\s*mg/i', $item_name, $matches)) {
        return trim($matches[0]);
    }
    
    return '';
}

function formatDoseValue($dose_value) {
    $dose_value = trim((string) $dose_value);
    if ($dose_value === '') {
        return '';
    }

    if (preg_match('/mg$/i', $dose_value)) {
        return $dose_value;
    }

    return $dose_value . ' mg';
}

function extractDoseFromItem($item) {
    $savedDose = formatDoseValue($item['dose_option_mg'] ?? ($item['semaglutide_dose_mg'] ?? ''));
    if ($savedDose !== '') {
        return $savedDose;
    }

    $dose = extractDoseFromName($item['name'] ?? '');
    if ($dose !== '') {
        return $dose;
    }

    $dose = extractDoseFromName($item['display_name'] ?? '');
    if ($dose !== '') {
        return $dose;
    }

    $drugId = extractItemDrugId($item);
    if ($drugId > 0) {
        $medicineInfo = getMedicineInfo($drugId);
        $dbDose = formatDoseValue($medicineInfo['dosage'] ?? '');
        if ($dbDose !== '') {
            return $dbDose;
        }
    }

    return '';
}

function calculateDcrQuantityDiscountTotal(float $unitPrice, int $quantity, int $discountQuantity): float
{
    if ($discountQuantity <= 1 || $quantity <= 0) {
        return $unitPrice * $quantity;
    }

    $groupSize = $discountQuantity + 1;
    $fullGroups = (int) floor($quantity / $groupSize);
    $remainingItems = $quantity % $groupSize;

    return ($fullGroups * $discountQuantity * $unitPrice) + ($remainingItems * $unitPrice);
}

function calculateDcrQuantityDiscountPaidCount(int $quantity, int $discountQuantity): int
{
    if ($discountQuantity <= 1 || $quantity <= 0) {
        return max(0, $quantity);
    }

    $groupSize = $discountQuantity + 1;
    $fullGroups = (int) floor($quantity / $groupSize);
    $remainingItems = $quantity % $groupSize;

    return ($fullGroups * $discountQuantity) + $remainingItems;
}

function calculateDcrLipoPaidCountFallback(int $quantity): int
{
    $quantity = max(0, $quantity);
    if ($quantity <= 0) {
        return 0;
    }

    // Lipo cards are operationally treated as 5-shot packs where every 5th
    // shot is free, even when the POS row was saved without explicit discount
    // metadata. The DCR "#" column should therefore show paid units only.
    return $quantity - (int) floor($quantity / 5);
}

function getDcrDrugDiscountRule(int $drugId): array
{
    static $cache = [];

    if ($drugId <= 0) {
        return [];
    }

    if (array_key_exists($drugId, $cache)) {
        return $cache[$drugId];
    }

    $row = sqlQuery(
        "SELECT discount_active, discount_type, discount_percent, discount_amount, discount_quantity, discount_start_date, discount_end_date, discount_month
         FROM drugs WHERE drug_id = ?",
        [$drugId]
    );

    $cache[$drugId] = is_array($row) ? $row : [];
    return $cache[$drugId];
}

function isDcrDiscountRuleActive(array $rule, $referenceDate = ''): bool
{
    if (empty($rule) || empty($rule['discount_active'])) {
        return false;
    }

    $referenceTimestamp = $referenceDate ? strtotime((string) $referenceDate) : time();
    if ($referenceTimestamp === false) {
        $referenceTimestamp = time();
    }

    $referenceDay = date('Y-m-d', $referenceTimestamp);
    $referenceMonth = date('Y-m', $referenceTimestamp);
    $startDate = trim((string) ($rule['discount_start_date'] ?? ''));
    $endDate = trim((string) ($rule['discount_end_date'] ?? ''));
    $discountMonth = trim((string) ($rule['discount_month'] ?? ''));

    if ($startDate !== '' && $startDate !== '0000-00-00' && $endDate !== '' && $endDate !== '0000-00-00') {
        return ($referenceDay >= $startDate && $referenceDay <= $endDate);
    }

    if ($startDate !== '' && $startDate !== '0000-00-00' && ($endDate === '' || $endDate === '0000-00-00')) {
        return ($referenceDay === $startDate);
    }

    if ($discountMonth !== '') {
        return ($referenceMonth === $discountMonth);
    }

    return true;
}

function calculateDcrItemTotal(array $item, $referenceDate = ''): float
{
    if (!empty($item['prepay_selected'])) {
        return 0.0;
    }

    if (isset($item['total']) && is_numeric($item['total'])) {
        return floatval($item['total']);
    }

    $quantity = intval($item['quantity'] ?? 0);
    $unitPrice = floatval($item['price'] ?? 0);
    if ($quantity <= 0 || $unitPrice <= 0) {
        return $unitPrice * $quantity;
    }

    $drugId = extractItemDrugId($item);
    if ($drugId > 0) {
        $rule = getDcrDrugDiscountRule($drugId);
        if (
            !empty($rule) &&
            isDcrDiscountRuleActive($rule, $referenceDate) &&
            (($rule['discount_type'] ?? '') === 'quantity') &&
            intval($rule['discount_quantity'] ?? 0) > 1
        ) {
            return calculateDcrQuantityDiscountTotal($unitPrice, $quantity, intval($rule['discount_quantity']));
        }
    }

    return $unitPrice * $quantity;
}

function getDcrReportedMedicineQuantity(array $item, $transactionType = ''): float
{
    $quantity = dcrNormalizeQuantity($item['quantity'] ?? 0);
    $dispenseQty = dcrNormalizeQuantity($item['dispense_quantity'] ?? 0);
    $administerQty = dcrNormalizeQuantity($item['administer_quantity'] ?? 0);
    $normalizedTransactionType = strtolower(trim((string) $transactionType));

    if ($normalizedTransactionType !== 'external_payment') {
        return $quantity;
    }

    // External-payment rows often settle prior balances and can still carry the
    // original item quantity. Only let them affect DCR medicine counts when the
    // row records real same-day operational activity.
    if ($dispenseQty <= 0 && $administerQty <= 0) {
        return 0;
    }

    return $dispenseQty + $administerQty;
}

/**
 * Get tax rate from POS configuration
 */
function getTaxRate() {
    static $tax_rate = null;
    
    if ($tax_rate === null) {
        // Get tax rates from database (same as POS system)
        $res = sqlStatement(
            "SELECT option_id, title, option_value " .
            "FROM list_options WHERE list_id = 'taxrate' AND activity = 1 ORDER BY seq, title, option_id LIMIT 1"
        );
        $row = sqlFetchArray($res);
        if ($row && !empty($row['option_value'])) {
            // option_value is stored as percentage (e.g., 8.5 for 8.5%)
            $tax_rate = floatval($row['option_value']) / 100; // Convert percentage to decimal
        } else {
            // Default tax rate: 8.5% (0.085 as decimal)
            $tax_rate = 0.085;
        }
        
        // Ensure tax rate is valid (should be between 0 and 1)
        if ($tax_rate <= 0 || $tax_rate > 1) {
            $tax_rate = 0.085; // Fallback to 8.5%
        }
    }
    
    return $tax_rate;
}

/**
 * Update per-patient metrics based on a transaction item
 */
function updatePatientDetails(&$patient, $item, $transactionType = '', $transactionDate = '') {
    if (!isset($patient['details'])) {
        $patient['details'] = initializePatientDetails();
    }
    
    $details =& $patient['details'];
    $type = classifyItemTypeFromItem($item);
    
    $quantity = dcrNormalizeQuantity($item['quantity'] ?? 0);
    $dispense_qty = dcrNormalizeQuantity($item['dispense_quantity'] ?? 0);
    $admin_qty = dcrNormalizeQuantity($item['administer_quantity'] ?? 0);
    $normalizedTransactionType = strtolower(trim((string) $transactionType));
    $isPrepaidItem = !empty($item['prepay_selected']);
    $isRemainingDispenseItem = !empty($item['is_remaining_dispense']);
    $hasExplicitDispenseActivity = ($dispense_qty > 0);
    $hasExplicitAdminActivity = ($admin_qty > 0);
    $discountRule = [];

    $isAdministerOnlyTransaction = ($normalizedTransactionType === 'administer');
    $countsDispense = in_array($normalizedTransactionType, [
        'dispense',
        'alternate_lot_dispense',
        'dispense_and_administer',
        'purchase_and_dispens',
        'purchase_and_alterna',
        'marketplace_dispense'
    ], true) || $hasExplicitDispenseActivity;
    $countsAdminister = in_array($normalizedTransactionType, [
        'administer',
        'dispense_and_administer'
    ], true) || $hasExplicitAdminActivity;

    // DCR patient rows should reflect the operational meaning of the transaction:
    // an administer-only entry should not create extra "bought" or "take home" counts,
    // even if the item payload still carries a source quantity from remaining-dispense data.
    // Prepaid items were paid on a prior date, so they should not increase today's "# bought"
    // columns even when the receipt still carries a quantity.
    // External payments are the finalized sale record in this POS flow, so their paid
    // quantities should still drive DCR sold-count metrics such as Shot Tracker.
    $purchased_qty = ($isAdministerOnlyTransaction || $isPrepaidItem || $isRemainingDispenseItem) ? 0 : $quantity;
    $takehome_qty = $countsDispense ? $dispense_qty : 0;
    $administered_qty = $countsAdminister ? $admin_qty : 0;
    
    // Fallbacks when dispense/admin quantities are absent
    if (!$isRemainingDispenseItem && $dispense_qty === 0 && $admin_qty === 0 && $quantity > 0) {
        $dispense_qty = $quantity;
        if ($countsDispense) {
            $takehome_qty = $quantity;
        }
    }
    
    $item_total = calculateDcrItemTotal($item, $transactionDate);
    $dose = extractDoseFromItem($item);
    $drugId = extractItemDrugId($item);
    if ($drugId > 0) {
        $discountRule = getDcrDrugDiscountRule($drugId);
    }

    if (
        $type === 'lipo' &&
        $purchased_qty > 0 &&
        !empty($discountRule) &&
        isDcrDiscountRuleActive($discountRule, $transactionDate) &&
        (($discountRule['discount_type'] ?? '') === 'quantity') &&
        intval($discountRule['discount_quantity'] ?? 0) > 1
    ) {
        // For Lipo buy-N-get-1-free packs, the DCR "#" column should reflect
        // the paid units sold, not the free unit included in the pack.
        $purchased_qty = calculateDcrQuantityDiscountPaidCount(
            $purchased_qty,
            intval($discountRule['discount_quantity'])
        );
    } elseif ($type === 'lipo' && $purchased_qty > 0) {
        $purchased_qty = calculateDcrLipoPaidCountFallback($purchased_qty);
    }

    if ($isPrepaidItem && in_array($type, ['lipo', 'sema', 'tirz', 'testosterone', 'pills'], true)) {
        $details['prepaid_categories'][$type] = true;
    }

    if (isDcrTaxableItem($item)) {
        $details['taxable_amount'] += $item_total;
    }
    
    switch ($type) {
        case 'ov':
            $details['ov_amount'] += $item_total;
            break;
        case 'bw':
            $details['bw_amount'] += $item_total;
            $details['lab_amount'] += $item_total;
            $details['lab_count'] += $quantity;
            break;
        case 'lipo':
            $details['lipo_admin_count'] += $administered_qty;
            $details['lipo_takehome_count'] += $takehome_qty;
            $details['lipo_amount'] += $item_total;
            $details['lipo_purchased_count'] += $purchased_qty;
            if ($dose) {
                $details['lipo_doses'][] = $dose;
            }
            $details['categories_involved']['lipo'] = true;
            break;
        case 'sema':
            $details['sema_admin_count'] += $administered_qty;
            $details['sema_takehome_count'] += $takehome_qty;
            $details['sema_amount'] += $item_total;
            $details['sema_purchased_count'] += $purchased_qty;
            if ($dose) {
                $details['sema_doses'][] = $dose;
            }
            $details['categories_involved']['sema'] = true;
            break;
        case 'tirz':
            $details['tirz_admin_count'] += $administered_qty;
            $details['tirz_takehome_count'] += $takehome_qty;
            $details['tirz_amount'] += $item_total;
            $details['tirz_purchased_count'] += $purchased_qty;
            if ($dose) {
                $details['tirz_doses'][] = $dose;
            }
            $details['categories_involved']['tirz'] = true;
            break;
        case 'supplement':
            $details['supplement_amount'] += $item_total;
            $details['supplement_count'] += $quantity;
            $details['categories_involved']['supplement'] = true;
            // Tax will be calculated in finalizePatientDetails on total supplement amount
            break;
        case 'drink':
            $details['drink_amount'] += $item_total;
            $details['drink_count'] += $quantity;
            $details['categories_involved']['drink'] = true;
            // Tax will be calculated in finalizePatientDetails on total drink amount
            break;
        case 'afterpay':
            $details['afterpay_fee'] += $item_total;
            break;
        case 'tax':
            $details['tax_amount'] += $item_total;
            break;
        case 'pills':
            $details['pill_amount'] += $item_total;
            $details['pill_count'] += $quantity;
            $details['categories_involved']['pills'] = true;
            $details['ov_amount'] += $item_total;
            break;
        case 'card':
            $details['card_punch_take_home'] += $takehome_qty;
            $details['card_punch_in_office'] += $administered_qty;
            $details['card_punch_amount'] += $item_total;
            $details['card_punch_purchased'] += $purchased_qty;
            $details['categories_involved']['card'] = true;
            break;
        case 'testosterone':
            $details['categories_involved']['testosterone'] = true;
            $details['testosterone_admin_count'] += $administered_qty;
            $details['testosterone_takehome_count'] += $takehome_qty;
            $details['testosterone_purchased_count'] += $purchased_qty;
            $details['testosterone_revenue'] += $item_total;
            break;
        default:
            // Other items are still counted towards total through transactions
            break;
    }
}

/**
 * Finalize per-patient details after all transactions have been processed
 */
function finalizePatientDetails(&$patient) {
    if (!isset($patient['details'])) {
        $patient['details'] = initializePatientDetails();
    }
    
    $details =& $patient['details'];

    // DCR TH columns represent take-home quantities dispensed/sold during the report period.
    // Keep them as recorded instead of netting them against administered counts.
    $details['lipo_takehome_count'] = max(0, intval($details['lipo_takehome_count'] ?? 0));
    $details['sema_takehome_count'] = max(0, intval($details['sema_takehome_count'] ?? 0));
    $details['tirz_takehome_count'] = max(0, intval($details['tirz_takehome_count'] ?? 0));
    $details['card_punch_take_home'] = max(0, intval($details['card_punch_take_home'] ?? 0));
    
    // Calculate subtotal from all item amounts (before tax and adjustments)
    $subtotal = 0;
    $subtotal += floatval($details['ov_amount'] ?? 0);
    $subtotal += floatval($details['bw_amount'] ?? 0);
    $subtotal += floatval($details['lipo_amount'] ?? 0);
    $subtotal += floatval($details['sema_amount'] ?? 0);
    $subtotal += floatval($details['tirz_amount'] ?? 0);
    $subtotal += floatval($details['testosterone_revenue'] ?? 0);
    $subtotal += floatval($details['afterpay_fee'] ?? 0);
    $subtotal += floatval($details['supplement_amount'] ?? 0);
    $subtotal += floatval($details['drink_amount'] ?? 0);
    $details['subtotal'] = $subtotal;
    
    // Calculate tax only on the same product set taxed in POS.
    $tax_rate = getTaxRate();
    $calculated_tax = floatval($details['taxable_amount'] ?? 0) * $tax_rate;
    $details['tax_amount'] = $calculated_tax;
    
    // Calculate adjustments (discounts, refunds, credits)
    $refunds = floatval($details['refund_amount'] ?? 0);
    $credits = floatval($details['credit_amount'] ?? 0);
    
    // Calculate discount: (Subtotal + Tax) - Refunds + Credits - Actual Total = Discount
    $expected_total = $subtotal + $calculated_tax - $refunds + $credits;
    $actual_total = floatval($patient['total_spent'] ?? 0);
    $discount = $expected_total - $actual_total;
    if ($discount > 0) {
        $details['discount_amount'] = $discount;
    } else {
        $details['discount_amount'] = 0;
    }
    
    // Total = Actual transaction total (includes all adjustments)
    // This matches what was actually collected
    $details['total_amount'] = $actual_total;
    
    // Deduplicate dose lists
    $details['sema_doses'] = array_values(array_unique(array_filter($details['sema_doses'])));
    $details['tirz_doses'] = array_values(array_unique(array_filter($details['tirz_doses'])));
    $details['lipo_doses'] = array_values(array_unique(array_filter($details['lipo_doses'])));
    
    // Determine primary category priority using categories with actual revenue first
    // so zero-dollar prepay activity does not incorrectly drive DCR buckets.
    if (floatval($details['sema_amount'] ?? 0) > 0) {
        $details['primary_category'] = 'sema';
    } elseif (floatval($details['tirz_amount'] ?? 0) > 0) {
        $details['primary_category'] = 'tirz';
    } elseif (floatval($details['testosterone_revenue'] ?? 0) > 0) {
        $details['primary_category'] = 'testosterone';
    } elseif (floatval($details['pill_amount'] ?? 0) > 0) {
        $details['primary_category'] = 'pills';
    } elseif (floatval($details['lipo_amount'] ?? 0) > 0) {
        $details['primary_category'] = 'lipo';
    } elseif (!empty($details['categories_involved']['sema'])) {
        $details['primary_category'] = 'sema';
    } elseif (!empty($details['categories_involved']['tirz'])) {
        $details['primary_category'] = 'tirz';
    } elseif (!empty($details['categories_involved']['testosterone'])) {
        $details['primary_category'] = 'testosterone';
    } elseif (!empty($details['categories_involved']['pills'])) {
        $details['primary_category'] = 'pills';
    } elseif (!empty($details['categories_involved']['lipo'])) {
        $details['primary_category'] = 'lipo';
    } else {
        $details['primary_category'] = 'other';
    }
    
    $received_injections = (
        $details['sema_admin_count'] + $details['sema_takehome_count'] +
        $details['tirz_admin_count'] + $details['tirz_takehome_count'] +
        $details['lipo_admin_count'] + $details['lipo_takehome_count']
    );
    
    $visitType = strtoupper(trim((string) ($details['visit_type'] ?? '')));
    if (in_array($visitType, ['N', 'F', 'R'], true)) {
        $details['action_code'] = $visitType;
    } elseif ($received_injections > 0) {
        $details['action_code'] = '-';
    } elseif ($details['ov_amount'] > 0 || $details['bw_amount'] > 0) {
        $details['action_code'] = $patient['is_new'] ? 'N' : 'F';
    } else {
        $details['action_code'] = $patient['is_new'] ? 'N' : 'F';
    }
}

/**
 * Update breakdown totals based on a patient's finalized details
 */
function updateBreakdownMetrics(&$breakdown, $patient) {
    $details = $patient['details'] ?? initializePatientDetails();
    if (!shouldIncludeInGrossBreakdown($details)) {
        return;
    }

    $isNewPatient = isGrossBreakdownNewPatient($patient);
    $action = $isNewPatient ? 'N' : 'F';
    $revenue = floatval($details['total_amount'] ?? 0);
    $category = getBreakdownBucketCategory($details);
    $categoryRevenue = $revenue;
    
    // Ensure totals exist
    $breakdown['total_patients']++;
    $breakdown['total_revenue'] += $revenue;
    
    switch ($category) {
        case 'sema':
            $breakdown['sema']['total_patients']++;
            $breakdown['sema']['total_revenue'] += $categoryRevenue;
            $breakdown['sema']['injection_amount'] += floatval($details['sema_amount'] ?? 0);  // Track Sema injection amount separately
            $breakdown['sema']['cards_sold'] += getCardCountFromTakeHome($details['sema_takehome_count'] ?? 0, 4);
            if ($action === 'N') {
                $breakdown['sema']['new_patients']++;
                $breakdown['sema']['new_revenue'] += $categoryRevenue;
            } else {
                $breakdown['sema']['follow_patients']++;
                $breakdown['sema']['follow_revenue'] += $categoryRevenue;
            }
            break;
        case 'tirz':
            $breakdown['tirz']['total_patients']++;
            $breakdown['tirz']['total_revenue'] += $categoryRevenue;
            $breakdown['tirz']['cards_sold'] += getCardCountFromTakeHome($details['tirz_takehome_count'] ?? 0, 4);
            if ($action === 'N') {
                $breakdown['tirz']['new_patients']++;
                $breakdown['tirz']['new_revenue'] += $categoryRevenue;
            } else {
                $breakdown['tirz']['follow_patients']++;
                $breakdown['tirz']['follow_revenue'] += $categoryRevenue;
            }
            break;
        case 'pills':
            $breakdown['pills']['total_patients']++;
            $breakdown['pills']['total_revenue'] += $categoryRevenue;
            if ($action === 'N') {
                $breakdown['pills']['new_patients']++;
                $breakdown['pills']['new_revenue'] += $categoryRevenue;
            } else {
                $breakdown['pills']['follow_patients']++;
                $breakdown['pills']['follow_revenue'] += $categoryRevenue;
            }
            break;
        case 'testosterone':
            $breakdown['testosterone']['total_patients']++;
            $breakdown['testosterone']['total_revenue'] += $categoryRevenue;
            $breakdown['testosterone']['cards_sold'] += getCardCountFromTakeHome($details['testosterone_takehome_count'] ?? 0, 4);
            if ($action === 'N') {
                $breakdown['testosterone']['new_patients']++;
                $breakdown['testosterone']['new_revenue'] += $categoryRevenue;
            } else {
                $breakdown['testosterone']['follow_patients']++;
                $breakdown['testosterone']['follow_revenue'] += $categoryRevenue;
            }
            break;
        default:
            $breakdown['other_revenue'] += $categoryRevenue;
            $breakdown['other']['total_patients']++;
            $breakdown['other']['total_revenue'] += $categoryRevenue;
            if ($action === 'N') {
                $breakdown['other']['new_patients']++;
                $breakdown['other']['new_revenue'] += $categoryRevenue;
            } else {
                $breakdown['other']['follow_patients']++;
                $breakdown['other']['follow_revenue'] += $categoryRevenue;
            }
            break;
    }
    
    $breakdown['total_trz_patients'] = $breakdown['tirz']['total_patients'];
    $breakdown['total_trz_revenue'] = $breakdown['tirz']['total_revenue'];
    $breakdown['sema_trz_cards_sold'] = $breakdown['sema']['cards_sold'] + $breakdown['tirz']['cards_sold'];
}

function patientHasCategoryActivity(array $details, string $category): bool
{
    switch ($category) {
        case 'sema':
            return (
                !empty($details['categories_involved']['sema']) ||
                floatval($details['sema_amount'] ?? 0) > 0 ||
                intval($details['sema_admin_count'] ?? 0) > 0 ||
                intval($details['sema_takehome_count'] ?? 0) > 0 ||
                intval($details['sema_purchased_count'] ?? 0) > 0
            );
        case 'tirz':
            return (
                !empty($details['categories_involved']['tirz']) ||
                floatval($details['tirz_amount'] ?? 0) > 0 ||
                intval($details['tirz_admin_count'] ?? 0) > 0 ||
                intval($details['tirz_takehome_count'] ?? 0) > 0 ||
                intval($details['tirz_purchased_count'] ?? 0) > 0
            );
        case 'pills':
            return (
                !empty($details['categories_involved']['pills']) ||
                floatval($details['pill_amount'] ?? 0) > 0 ||
                intval($details['pill_count'] ?? 0) > 0 ||
                (
                    empty($details['categories_involved']['sema']) &&
                    empty($details['categories_involved']['tirz']) &&
                    empty($details['categories_involved']['testosterone']) &&
                    empty($details['categories_involved']['lipo']) &&
                    (
                        floatval($details['ov_amount'] ?? 0) > 0 ||
                        floatval($details['bw_amount'] ?? 0) > 0
                    )
                )
            );
        case 'testosterone':
            return (
                !empty($details['categories_involved']['testosterone']) ||
                floatval($details['testosterone_revenue'] ?? 0) > 0 ||
                intval($details['testosterone_admin_count'] ?? 0) > 0 ||
                intval($details['testosterone_takehome_count'] ?? 0) > 0 ||
                intval($details['testosterone_purchased_count'] ?? 0) > 0
            );
    }

    return false;
}

function getPatientCategoryRevenue(array $details, string $category): float
{
    switch ($category) {
        case 'sema':
            return floatval($details['sema_amount'] ?? 0);
        case 'tirz':
            return floatval($details['tirz_amount'] ?? 0);
        case 'pills':
            return floatval($details['pill_amount'] ?? 0);
        case 'testosterone':
            return floatval($details['testosterone_revenue'] ?? 0);
    }

    return 0.0;
}

function getPatientRevenueGridAmounts(array $details): array
{
    $amounts = [
        'office_visit' => floatval($details['ov_amount'] ?? 0),
        'lab' => floatval($details['lab_amount'] ?? ($details['bw_amount'] ?? 0)),
        'lipo' => floatval($details['lipo_amount'] ?? 0),
        'sema' => floatval($details['sema_amount'] ?? 0),
        'tirz' => floatval($details['tirz_amount'] ?? 0),
        'testosterone' => floatval($details['testosterone_revenue'] ?? 0),
        'afterpay' => floatval($details['afterpay_fee'] ?? 0),
        'supplements' => floatval($details['supplement_amount'] ?? 0),
        'protein' => floatval($details['drink_amount'] ?? 0),
    ];

    $actualSubtotal = floatval($details['total_amount'] ?? 0)
        - floatval($details['tax_amount'] ?? 0)
        + floatval($details['refund_amount'] ?? 0)
        - floatval($details['credit_amount'] ?? 0);
    $actualSubtotal = max(0, $actualSubtotal);

    $currentSubtotal = array_sum($amounts);
    $variance = round($currentSubtotal - $actualSubtotal, 2);
    if ($variance <= 0.009) {
        return $amounts;
    }

    $targetCategory = null;
    $notes = strtolower(trim((string)($details['price_override_notes'] ?? '')));
    $categoryPatterns = [
        'lipo' => ['lipo', 'b12'],
        'sema' => ['semaglutide', 'sema', 'sg'],
        'tirz' => ['tirzepatide', 'tirz', 'trz'],
        'testosterone' => ['testosterone', 'tes'],
        'supplements' => ['supplement'],
        'protein' => ['protein', 'shake'],
        'afterpay' => ['afterpay'],
    ];

    foreach ($categoryPatterns as $category => $patterns) {
        if (($amounts[$category] ?? 0) <= 0) {
            continue;
        }
        foreach ($patterns as $pattern) {
            if ($notes !== '' && strpos($notes, $pattern) !== false) {
                $targetCategory = $category;
                break 2;
            }
        }
    }

    if ($targetCategory === null) {
        $countMap = [
            'lipo' => max(1, intval($details['lipo_purchased_count'] ?? 0)),
            'sema' => max(1, intval($details['sema_purchased_count'] ?? 0)),
            'tirz' => max(1, intval($details['tirz_purchased_count'] ?? 0)),
            'testosterone' => max(1, intval($details['testosterone_purchased_count'] ?? 0)),
            'supplements' => max(1, intval($details['supplement_count'] ?? 0)),
            'protein' => max(1, intval($details['drink_count'] ?? 0)),
        ];

        foreach ($countMap as $category => $count) {
            if (($amounts[$category] ?? 0) <= 0 || $count <= 0) {
                continue;
            }
            $estimatedUnit = round($amounts[$category] / $count, 2);
            if (abs($estimatedUnit - $variance) < 0.011) {
                $targetCategory = $category;
                break;
            }
        }
    }

    if ($targetCategory === null) {
        foreach (['lipo', 'sema', 'tirz', 'testosterone', 'supplements', 'protein', 'afterpay', 'office_visit', 'lab'] as $category) {
            if (($amounts[$category] ?? 0) >= $variance) {
                $targetCategory = $category;
                break;
            }
        }
    }

    if ($targetCategory !== null && isset($amounts[$targetCategory])) {
        $amounts[$targetCategory] = max(0, round($amounts[$targetCategory] - $variance, 2));
    }

    return $amounts;
}

function getPatientRevenueGridSubtotal(array $details, array $amounts): float
{
    return round(array_sum($amounts), 2);
}

function shouldShowZeroDcrMedicationAmount(array $details, string $category): bool
{
    if (!empty($details['prepaid_categories'][$category]) || !empty($details['categories_involved'][$category])) {
        return true;
    }

    foreach ([
        $category . '_admin_count',
        $category . '_takehome_count',
        $category . '_purchased_count',
    ] as $key) {
        if (floatval($details[$key] ?? 0) > 0) {
            return true;
        }
    }

    $doseKey = $category . '_doses';
    return !empty($details[$doseKey]) && is_array($details[$doseKey]);
}

function shouldShowZeroDcrOfficeVisitAmount(array $details): bool
{
    if (!empty($details['prepaid_categories']['pills']) || !empty($details['categories_involved']['pills'])) {
        return true;
    }

    return floatval($details['pill_amount'] ?? 0) > 0 || floatval($details['pill_count'] ?? 0) > 0;
}

function buildDcrPatientGridNotes(array $details, array $netAmounts): string
{
    $notesParts = [];
    $baseNotes = trim((string) ($details['price_override_notes'] ?? ''));
    if ($baseNotes !== '') {
        $notesParts[] = preg_replace("/\r\n|\r|\n/", ' ', $baseNotes);
    }

    $testosteroneRevenue = floatval($netAmounts['testosterone'] ?? 0);
    if ($testosteroneRevenue > 0 || shouldShowZeroDcrMedicationAmount($details, 'testosterone')) {
        $testosteroneQty = max(
            floatval($details['testosterone_purchased_count'] ?? 0),
            floatval($details['testosterone_takehome_count'] ?? 0),
            floatval($details['testosterone_admin_count'] ?? 0)
        );
        $qtyDisplay = formatNumberCell($testosteroneQty);
        $note = 'Testosterone: $' . number_format($testosteroneRevenue, 2);
        if ($qtyDisplay !== '') {
            $note .= ' (Qty ' . $qtyDisplay . ')';
        }
        $notesParts[] = $note;
    }

    return implode(' | ', array_values(array_unique(array_filter($notesParts))));
}

function getBreakdownBucketCategory(array $details): string
{
    $category = $details['primary_category'] ?? 'other';

    if (in_array($category, ['sema', 'tirz', 'pills', 'testosterone'], true)) {
        return $category;
    }

    if (
        empty($details['categories_involved']['sema']) &&
        empty($details['categories_involved']['tirz']) &&
        empty($details['categories_involved']['testosterone']) &&
        empty($details['categories_involved']['lipo']) &&
        (
            floatval($details['ov_amount'] ?? 0) > 0 ||
            floatval($details['bw_amount'] ?? 0) > 0
        )
    ) {
        return 'pills';
    }

    return 'other';
}

function shouldIncludeInGrossBreakdown(array $details): bool
{
    return (
        floatval($details['total_amount'] ?? 0) > 0 ||
        patientHasCategoryActivity($details, 'sema') ||
        patientHasCategoryActivity($details, 'tirz') ||
        patientHasCategoryActivity($details, 'pills') ||
        patientHasCategoryActivity($details, 'testosterone')
    );
}

function applyBreakdownBucket(array &$bucket, string $category, string $visitBucket, float $revenue): void
{
    $visitBucket = in_array($visitBucket, ['new', 'rtn', 'fu'], true) ? $visitBucket : 'fu';

    switch ($category) {
        case 'sema':
            $bucket['sema_' . $visitBucket . '_pts'] += 1;
            $bucket['sema_' . $visitBucket . '_rev'] += $revenue;
            break;
        case 'tirz':
            $bucket['tirz_' . $visitBucket . '_pts'] += 1;
            $bucket['tirz_' . $visitBucket . '_rev'] += $revenue;
            break;
        case 'pills':
            $bucket['pills_' . $visitBucket . '_pts'] += 1;
            $bucket['pills_' . $visitBucket . '_rev'] += $revenue;
            break;
        case 'testosterone':
            $bucket['test_' . $visitBucket . '_pts'] += 1;
            $bucket['test_' . $visitBucket . '_rev'] += $revenue;
            break;
    }
}

/**
 * Rebuild patient_rows and breakdown summaries from unique_patients array
 */
function rebuildPatientSummaries(&$data) {
    if (!isset($data['breakdown']) || !is_array($data['breakdown'])) {
        $data['breakdown'] = initializeBreakdownStructure();
    } else {
        $data['breakdown'] = initializeBreakdownStructure();
    }
    
    $data['patient_rows'] = [];
    $data['new_patients'] = 0;
    $data['returning_patients'] = 0;
    
    if (empty($data['unique_patients'])) {
        $data['daily_breakdown'] = [
            'rows' => [],
            'totals' => [],
            'averages' => [],
            'day_count' => 0
        ];
        return;
    }
    
    // Keep the Sign In # column aligned with the saved daily patient number.
    // Fall back to first-visit order only when a patient number was not saved.
    uasort($data['unique_patients'], function($a, $b) {
        $aNumber = normalizeDcrPatientNumber($a['patient_number'] ?? null);
        $bNumber = normalizeDcrPatientNumber($b['patient_number'] ?? null);

        if ($aNumber !== null && $bNumber !== null && $aNumber !== $bNumber) {
            return $aNumber <=> $bNumber;
        }

        if ($aNumber !== null && $bNumber === null) {
            return -1;
        }

        if ($aNumber === null && $bNumber !== null) {
            return 1;
        }

        return ($a['first_visit_timestamp'] ?? 0) <=> ($b['first_visit_timestamp'] ?? 0);
    });
    
    $order = 1;
    foreach ($data['unique_patients'] as $key => &$patient) {
        if (!isset($patient['details']) || !is_array($patient['details'])) {
            $patient['details'] = initializePatientDetails();
        }
        
        finalizePatientDetails($patient);
        $patient['order_number'] = normalizeDcrPatientNumber($patient['patient_number'] ?? null) ?? $order++;
        $data['patient_rows'][] = $patient;
        updateBreakdownMetrics($data['breakdown'], $patient);
        
        if (!empty($patient['is_new'])) {
            $data['new_patients']++;
        } else {
            $data['returning_patients']++;
        }
    }
    unset($patient);
    
    // Compute daily breakdown for Excel summary
    $data['daily_breakdown'] = computeDailyBreakdown($data['patient_rows']);
    $data['monthly_breakdown'] = computeMonthlyBreakdown($data['patient_rows']);
    $data['monthly_revenue_grid'] = computeMonthlyRevenueGrid($data['patient_rows']);
    $data['shot_tracker'] = computeShotTracker($data['patient_rows']);
}

/**
 * Hoover facility uses marketplace dispense, so take-home columns should render blank.
 */
function shouldBlankTakeHomeColumns($facility_id = null, $facility_name = '') {
    if ((int)$facility_id === 36) {
        return true;
    }

    return stripos((string)$facility_name, 'hoover') !== false;
}

function posTransactionsHaveVoidColumns()
{
    static $checked = null;

    if ($checked !== null) {
        return $checked;
    }

    $result = sqlStatement("SHOW COLUMNS FROM pos_transactions LIKE 'voided'");
    $checked = (bool) ($result && sqlFetchArray($result));
    return $checked;
}

function posTransactionsHaveFacilityColumn()
{
    static $checked = null;

    if ($checked !== null) {
        return $checked;
    }

    $result = sqlStatement("SHOW COLUMNS FROM pos_transactions LIKE 'facility_id'");
    $checked = (bool) ($result && sqlFetchArray($result));
    return $checked;
}

/**
 * Helper: format currency values for display
 */
function formatCurrencyCell($value, $showZero = false) {
    $amount = floatval($value);
    if ($amount != 0.0 || $showZero) {
        return '$' . number_format($amount, 2);
    }
    return '';
}

/**
 * Helper: format patient name as "LAST, FIRST" for Excel display
 */
function formatPatientNameExcel($name) {
    $parts = explode(' ', trim($name));
    if (count($parts) >= 2) {
        $last = array_pop($parts);
        $first = implode(' ', $parts);
        return strtoupper($last . ', ' . $first);
    }
    return strtoupper($name);
}

/**
 * Helper: anonymize patient names for emailed DCR content/attachments.
 * Format: first initial, first four letters of last name.
 * Supports both "First Last" and "LAST, FIRST" source formats.
 */
function formatPatientNameForEmail($name): string
{
    $normalized = trim((string) $name);
    if ($normalized === '') {
        return '';
    }

    $firstName = '';
    $lastName = '';

    if (strpos($normalized, ',') !== false) {
        $commaParts = array_map('trim', explode(',', $normalized, 2));
        $lastName = (string) ($commaParts[0] ?? '');
        $firstName = (string) ($commaParts[1] ?? '');
    } else {
        $parts = preg_split('/\s+/', $normalized) ?: [];
        $parts = array_values(array_filter($parts, static function ($part) {
            return $part !== '';
        }));

        if (empty($parts)) {
            return '';
        }

        $firstName = (string) ($parts[0] ?? '');
        $lastName = count($parts) > 1 ? (string) $parts[count($parts) - 1] : $firstName;
    }

    $firstInitial = strtoupper(substr($firstName, 0, 1));
    $lastFragmentSource = preg_replace('/[^A-Za-z]/', '', $lastName);
    $lastFragment = substr($lastFragmentSource !== '' ? $lastFragmentSource : $lastName, 0, 4);
    $lastFragment = ucfirst(strtolower($lastFragment));

    if ($lastFragment === '') {
        return $firstInitial;
    }

    return trim($firstInitial . ', ' . $lastFragment);
}

function maskReportDataForEmail(array $reportData): array
{
    if (!empty($reportData['patient_rows']) && is_array($reportData['patient_rows'])) {
        foreach ($reportData['patient_rows'] as &$patient) {
            if (is_array($patient)) {
                $patient['name'] = formatPatientNameForEmail($patient['name'] ?? '');
            }
        }
        unset($patient);
    }

    if (!empty($reportData['transactions']) && is_array($reportData['transactions'])) {
        foreach ($reportData['transactions'] as &$transaction) {
            if (is_array($transaction) && isset($transaction['patient_name'])) {
                $transaction['patient_name'] = formatPatientNameForEmail($transaction['patient_name']);
            }
        }
        unset($transaction);
    }

    if (!empty($reportData['unique_patients']) && is_array($reportData['unique_patients'])) {
        foreach ($reportData['unique_patients'] as $key => $patient) {
            if (is_array($patient)) {
                $patient['name'] = formatPatientNameForEmail($patient['name'] ?? '');
                $reportData['unique_patients'][$key] = $patient;
            }
        }
    }

    return $reportData;
}

/**
 * Helper: format numeric counts for display
 */
function formatNumberCell($value, $precision = 0, $showZero = false) {
    if ($value === null || $value === '') {
        return $showZero ? number_format(0, $precision) : '';
    }
    
    $number = is_numeric($value) ? floatval($value) : 0;
    if ($number == 0 && !$showZero) {
        return '';
    }
    
    if ($precision > 0) {
        return number_format($number, $precision);
    }
    
    if (floor($number) != $number) {
        return number_format($number, 2);
    }
    
    return number_format($number, 0);
}

/**
 * Helper: format card punch display so the visible punch shows the current punch
 * already used within the active cycle.
 * Example:
 * - Lipo cycle is 1-5
 * - Sema/Tirz/Testosterone cycle is 1-4
 * If a patient has already used shot 3 on a 1-4 card, the display should show 3.
 */
function formatCardPunchCycleCell($value, $cycleLength)
{
    $number = is_numeric($value) ? (int) round(floatval($value)) : 0;
    $cycleLength = (int) $cycleLength;

    if ($number <= 0 || $cycleLength <= 0) {
        return '';
    }

    $wrapped = $number % $cycleLength;
    if ($wrapped === 0) {
        $wrapped = $cycleLength;
    }

    return (string) $wrapped;
}

function formatCardPunchCycleSpanCell($value, $cycleLength, $spanLength = 1)
{
    $number = is_numeric($value) ? (int) round(floatval($value)) : 0;
    $cycleLength = (int) $cycleLength;
    $spanLength = (int) $spanLength;

    if ($number <= 0 || $cycleLength <= 0 || $spanLength <= 0) {
        return '';
    }

    if ($spanLength <= 1) {
        return formatCardPunchCycleCell($number, $cycleLength);
    }

    $labels = [];
    $start = $number - $spanLength + 1;
    for ($current = $start; $current <= $number; $current++) {
        $wrapped = $current % $cycleLength;
        if ($wrapped <= 0) {
            $wrapped += $cycleLength;
        }
        $labels[] = (string) $wrapped;
    }

    return implode(',', $labels);
}

/**
 * Helper: format currency for Excel-style tables (always show value)
 */
function formatCurrencyExcelValue($value, $decimals = 2) {
    return '$' . number_format(floatval($value), $decimals);
}

/**
 * Helper: format numeric values for Excel-style tables (always show value)
 */
function formatNumberExcelValue($value, $decimals = 0) {
    return number_format(floatval($value), $decimals);
}

function renderGrossBreakdownCountLink($value, string $dateKey, string $metricKey, string $label): string
{
    $count = floatval($value);
    $formattedValue = formatNumberExcelValue($count);
    if ($count <= 0) {
        return $formattedValue;
    }

    return '<button'
        . ' type="button"'
        . ' class="gross-count-link js-gross-count-link"'
        . ' data-date-key="' . attr($dateKey) . '"'
        . ' data-metric-key="' . attr($metricKey) . '"'
        . ' data-label="' . attr($label) . '"'
        . '>'
        . $formattedValue
        . '</button>';
}

function renderShotTrackerCountLink($value, string $dateKey, string $metricKey, string $label): string
{
    $count = floatval($value);
    $formattedValue = formatNumberExcelValue($count);
    if ($count <= 0) {
        return $formattedValue;
    }

    return '<button'
        . ' type="button"'
        . ' class="gross-count-link js-shot-tracker-link"'
        . ' data-date-key="' . attr($dateKey) . '"'
        . ' data-metric-key="' . attr($metricKey) . '"'
        . ' data-label="' . attr($label) . '"'
        . '>'
        . $formattedValue
        . '</button>';
}

function initializeGrossBreakdownMetrics(): array
{
    $metrics = [
        'other_rev' => 0,
        'total_rev' => 0,
    ];

    foreach (['sema', 'tirz', 'pills', 'test'] as $prefix) {
        foreach (['new', 'fu', 'rtn'] as $visitBucket) {
            $metrics[$prefix . '_' . $visitBucket . '_pts'] = 0;
            $metrics[$prefix . '_' . $visitBucket . '_rev'] = 0;
        }
    }

    foreach (['sg_total', 'trz_total', 'pills_total', 'test_total'] as $prefix) {
        $metrics[$prefix . '_pts'] = 0;
        $metrics[$prefix . '_rev'] = 0;
    }

    return $metrics;
}

function buildGrossBreakdownDrilldownMap(array $patient_rows): array
{
    $map = [];

    foreach ($patient_rows as $index => $patient) {
        $first_visit = $patient['first_visit'] ?? null;
        $timestamp = $first_visit ? strtotime($first_visit) : false;
        if (!$timestamp) {
            continue;
        }

        $details = $patient['details'] ?? initializePatientDetails();
        if (!shouldIncludeInGrossBreakdown($details) || !hasExplicitGrossBreakdownVisitCode($patient)) {
            continue;
        }

        $bucketCategory = getBreakdownBucketCategory($details);
        $categoryPrefixMap = [
            'sema' => 'sema',
            'tirz' => 'tirz',
            'pills' => 'pills',
            'testosterone' => 'test',
        ];

        if (!isset($categoryPrefixMap[$bucketCategory])) {
            continue;
        }

        $metricPrefix = $categoryPrefixMap[$bucketCategory];
        $visitBucket = getGrossBreakdownVisitBucket($patient);
        $dateKey = date('Y-m-d', $timestamp);
        $patientEntry = [
            'row_anchor' => 'patient-dcr-row-' . $index,
            'order_number' => $patient['order_number'] ?? '',
            'pid' => $patient['pid'] ?? '',
            'name' => formatPatientNameExcel($patient['name'] ?? ''),
            'action' => strtoupper(trim((string) ($details['visit_type'] ?? $details['action_code'] ?? '-'))),
            'category' => ucfirst($bucketCategory),
            'total_amount' => floatval($details['total_amount'] ?? $patient['total_spent'] ?? 0),
            'first_visit' => $first_visit ? date('m/d/Y', $timestamp) : '',
            'notes' => buildDcrPatientGridNotes($details, getPatientRevenueGridAmounts($details)),
        ];

        foreach ([$dateKey, '__total__'] as $mapDateKey) {
            if (!isset($map[$mapDateKey])) {
                $map[$mapDateKey] = [];
            }

            $metricKeys = [
                $metricPrefix . '_' . $visitBucket,
                ($metricPrefix === 'sema' ? 'sg_total' : ($metricPrefix === 'tirz' ? 'trz_total' : $metricPrefix . '_total')),
            ];

            foreach ($metricKeys as $metricKey) {
                if (!isset($map[$mapDateKey][$metricKey])) {
                    $map[$mapDateKey][$metricKey] = [];
                }
                $map[$mapDateKey][$metricKey][] = $patientEntry;
            }
        }
    }

    return $map;
}

function buildShotTrackerDrilldownMap(array $patient_rows): array
{
    $map = [];

    foreach ($patient_rows as $index => $patient) {
        $first_visit = $patient['first_visit'] ?? null;
        $timestamp = $first_visit ? strtotime($first_visit) : false;
        if (!$timestamp) {
            continue;
        }

        $details = $patient['details'] ?? initializePatientDetails();
        $dateKey = date('Y-m-d', $timestamp);
        $baseEntry = [
            'row_anchor' => 'patient-dcr-row-' . $index,
            'order_number' => $patient['order_number'] ?? '',
            'pid' => $patient['pid'] ?? '',
            'name' => formatPatientNameExcel($patient['name'] ?? ''),
            'action' => strtoupper(trim((string) ($details['visit_type'] ?? $details['action_code'] ?? '-'))),
            'total_amount' => floatval($details['total_amount'] ?? $patient['total_spent'] ?? 0),
            'first_visit' => $first_visit ? date('m/d/Y', $timestamp) : '',
        ];

        $lipoSold = (int) round(floatval($details['lipo_purchased_count'] ?? 0));
        $lipoCards = getCardCountFromTakeHome($lipoSold, 5);
        if ($lipoCards > 0) {
            $entry = $baseEntry;
            $entry['category'] = 'LIPO';
            $entry['notes'] = 'Shot Tracker: ' . $lipoCards . ' card(s) from ' . $lipoSold . ' paid shot(s).';
            foreach ([$dateKey, '__total__'] as $mapDateKey) {
                if (!isset($map[$mapDateKey]['lipo_cards'])) {
                    $map[$mapDateKey]['lipo_cards'] = [];
                }
                $map[$mapDateKey]['lipo_cards'][] = $entry;
            }
        }

        $semaSold = (int) round(floatval($details['sema_purchased_count'] ?? 0));
        $tirzSold = (int) round(floatval($details['tirz_purchased_count'] ?? 0));
        $sgTrzSold = $semaSold + $tirzSold;
        $sgTrzCards = getCardCountFromTakeHome($sgTrzSold, 4);
        if ($sgTrzCards > 0) {
            $entry = $baseEntry;
            $entry['category'] = 'SG & TRZ';
            $entry['notes'] = 'Shot Tracker: ' . $sgTrzCards . ' card(s) from ' . $sgTrzSold . ' paid shot(s) (SG ' . $semaSold . ', TRZ ' . $tirzSold . ').';
            foreach ([$dateKey, '__total__'] as $mapDateKey) {
                if (!isset($map[$mapDateKey]['sg_trz_cards'])) {
                    $map[$mapDateKey]['sg_trz_cards'] = [];
                }
                $map[$mapDateKey]['sg_trz_cards'][] = $entry;
            }
        }

        $tesSold = (int) round(floatval($details['testosterone_purchased_count'] ?? 0));
        $tesCards = getCardCountFromTakeHome($tesSold, 4);
        if ($tesCards > 0) {
            $entry = $baseEntry;
            $entry['category'] = 'TES';
            $entry['notes'] = 'Shot Tracker: ' . $tesCards . ' card(s) from ' . $tesSold . ' paid shot(s).';
            foreach ([$dateKey, '__total__'] as $mapDateKey) {
                if (!isset($map[$mapDateKey]['tes_cards'])) {
                    $map[$mapDateKey]['tes_cards'] = [];
                }
                $map[$mapDateKey]['tes_cards'][] = $entry;
            }
        }
    }

    return $map;
}

/**
 * Compute daily category breakdown for Excel-style summary
 */
function computeDailyBreakdown($patient_rows) {
    $daily = [];
    
    // First, collect all unique payment methods
    $all_payment_methods = [];
    foreach ($patient_rows as $patient) {
        if (isset($patient['payment_methods']) && is_array($patient['payment_methods'])) {
            foreach ($patient['payment_methods'] as $method => $amount) {
                if (!in_array($method, $all_payment_methods)) {
                    $all_payment_methods[] = $method;
                }
            }
        }
    }
    sort($all_payment_methods); // Sort for consistent ordering
    
    foreach ($patient_rows as $patient) {
        $first_visit = $patient['first_visit'] ?? null;
        $timestamp = $first_visit ? strtotime($first_visit) : false;
        if (!$timestamp) {
            continue;
        }
        
        $date_key = date('Y-m-d', $timestamp);
        if (!isset($daily[$date_key])) {
            $daily[$date_key] = array_merge([
                'date_key' => $date_key,
                'display_date' => date('l, F j, Y', $timestamp),
                'payment_methods' => [] // Track payment methods per day
            ], initializeGrossBreakdownMetrics());
            // Initialize all payment methods to 0
            foreach ($all_payment_methods as $method) {
                $daily[$date_key]['payment_methods'][$method] = 0;
            }
        }
        
        $details = $patient['details'] ?? initializePatientDetails();
        if (!shouldIncludeInGrossBreakdown($details)) {
            continue;
        }

        if (!hasExplicitGrossBreakdownVisitCode($patient)) {
            continue;
        }

        $visitBucket = getGrossBreakdownVisitBucket($patient);
        $bucketCategory = getBreakdownBucketCategory($details);
        $bucketRevenue = floatval($details['total_amount'] ?? 0);

        if (in_array($bucketCategory, ['sema', 'tirz', 'pills', 'testosterone'], true)) {
            applyBreakdownBucket($daily[$date_key], $bucketCategory, $visitBucket, $bucketRevenue);
        } else {
            $daily[$date_key]['other_rev'] += $bucketRevenue;
        }

        // Aggregate payment methods for this day
        if (isset($patient['payment_methods']) && is_array($patient['payment_methods'])) {
            foreach ($patient['payment_methods'] as $method => $amount) {
                if (isset($daily[$date_key]['payment_methods'][$method])) {
                    $daily[$date_key]['payment_methods'][$method] += floatval($amount);
                } else {
                    $daily[$date_key]['payment_methods'][$method] = floatval($amount);
                }
            }
        }
    }
    
    if (empty($daily)) {
        return [
            'rows' => [],
            'totals' => [],
            'averages' => [],
            'day_count' => 0
        ];
    }
    
    ksort($daily);
    
    $rows = [];
    $totals = initializeGrossBreakdownMetrics();
    
    // Initialize payment method totals
    $payment_method_totals = [];
    foreach ($all_payment_methods as $method) {
        $payment_method_totals[$method] = 0;
    }
    
    foreach ($daily as $date_key => $row) {
        $row['sg_total_pts'] = $row['sema_new_pts'] + $row['sema_fu_pts'] + $row['sema_rtn_pts'];
        $row['sg_total_rev'] = $row['sema_new_rev'] + $row['sema_fu_rev'] + $row['sema_rtn_rev'];
        $row['trz_total_pts'] = $row['tirz_new_pts'] + $row['tirz_fu_pts'] + $row['tirz_rtn_pts'];
        $row['trz_total_rev'] = $row['tirz_new_rev'] + $row['tirz_fu_rev'] + $row['tirz_rtn_rev'];
        $row['pills_total_pts'] = $row['pills_new_pts'] + $row['pills_fu_pts'] + $row['pills_rtn_pts'];
        $row['pills_total_rev'] = $row['pills_new_rev'] + $row['pills_fu_rev'] + $row['pills_rtn_rev'];
        $row['test_total_pts'] = $row['test_new_pts'] + $row['test_fu_pts'] + $row['test_rtn_pts'];
        $row['test_total_rev'] = $row['test_new_rev'] + $row['test_fu_rev'] + $row['test_rtn_rev'];
        $row['total_rev'] = $row['sg_total_rev'] + $row['trz_total_rev'] + $row['pills_total_rev'] + $row['test_total_rev'] + ($row['other_rev'] ?? 0);
        
        foreach ($totals as $key => $value) {
            $totals[$key] += $row[$key] ?? 0;
        }
        
        // Aggregate payment methods
        if (isset($row['payment_methods']) && is_array($row['payment_methods'])) {
            foreach ($row['payment_methods'] as $method => $amount) {
                if (isset($payment_method_totals[$method])) {
                    $payment_method_totals[$method] += floatval($amount);
                } else {
                    $payment_method_totals[$method] = floatval($amount);
                }
            }
        }
        
        $rows[] = $row;
    }
    
    $day_count = count($rows);
    $averages = [];
    foreach ($totals as $key => $value) {
        $averages[$key] = $day_count > 0 ? ($value / $day_count) : 0;
    }
    
    return [
        'rows' => $rows,
        'totals' => $totals,
        'averages' => $averages,
        'day_count' => $day_count,
        'payment_methods' => $all_payment_methods,
        'payment_method_totals' => $payment_method_totals
    ];
}

/**
 * Compute monthly category breakdown for Excel-style summary
 */
function computeMonthlyBreakdown($patient_rows) {
    $monthly = [];
    
    // First, collect all unique payment methods
    $all_payment_methods = [];
    foreach ($patient_rows as $patient) {
        if (isset($patient['payment_methods']) && is_array($patient['payment_methods'])) {
            foreach ($patient['payment_methods'] as $method => $amount) {
                if (!in_array($method, $all_payment_methods)) {
                    $all_payment_methods[] = $method;
                }
            }
        }
    }
    sort($all_payment_methods); // Sort for consistent ordering
    
    foreach ($patient_rows as $patient) {
        $first_visit = $patient['first_visit'] ?? null;
        $timestamp = $first_visit ? strtotime($first_visit) : false;
        if (!$timestamp) {
            continue;
        }
        
        $month_key = date('Y-m', $timestamp);
        if (!isset($monthly[$month_key])) {
            $monthly[$month_key] = array_merge([
                'display_label' => date('F Y', $timestamp),
                'payment_methods' => [] // Track payment methods per month
            ], initializeGrossBreakdownMetrics());
            // Initialize all payment methods to 0
            foreach ($all_payment_methods as $method) {
                $monthly[$month_key]['payment_methods'][$method] = 0;
            }
        }
        
        $details = $patient['details'] ?? initializePatientDetails();
        if (!shouldIncludeInGrossBreakdown($details)) {
            continue;
        }

        if (!hasExplicitGrossBreakdownVisitCode($patient)) {
            continue;
        }

        $visitBucket = getGrossBreakdownVisitBucket($patient);
        $bucketCategory = getBreakdownBucketCategory($details);
        $bucketRevenue = floatval($details['total_amount'] ?? 0);

        if (in_array($bucketCategory, ['sema', 'tirz', 'pills', 'testosterone'], true)) {
            applyBreakdownBucket($monthly[$month_key], $bucketCategory, $visitBucket, $bucketRevenue);
        } else {
            $monthly[$month_key]['other_rev'] += $bucketRevenue;
        }
        
        // Aggregate payment methods for this month
        if (isset($patient['payment_methods']) && is_array($patient['payment_methods'])) {
            foreach ($patient['payment_methods'] as $method => $amount) {
                if (isset($monthly[$month_key]['payment_methods'][$method])) {
                    $monthly[$month_key]['payment_methods'][$method] += floatval($amount);
                } else {
                    $monthly[$month_key]['payment_methods'][$method] = floatval($amount);
                }
            }
        }
    }
    
    if (empty($monthly)) {
        return [
            'rows' => [],
            'totals' => [],
            'averages' => [],
            'month_count' => 0,
            'payment_methods' => [],
            'payment_method_totals' => []
        ];
    }
    
    ksort($monthly);
    
    $rows = [];
    $totals = initializeGrossBreakdownMetrics();
    
    // Initialize payment method totals
    $payment_method_totals = [];
    foreach ($all_payment_methods as $method) {
        $payment_method_totals[$method] = 0;
    }
    
    foreach ($monthly as $month_key => $row) {
        $row['sg_total_pts'] = $row['sema_new_pts'] + $row['sema_fu_pts'] + $row['sema_rtn_pts'];
        $row['sg_total_rev'] = $row['sema_new_rev'] + $row['sema_fu_rev'] + $row['sema_rtn_rev'];
        $row['trz_total_pts'] = $row['tirz_new_pts'] + $row['tirz_fu_pts'] + $row['tirz_rtn_pts'];
        $row['trz_total_rev'] = $row['tirz_new_rev'] + $row['tirz_fu_rev'] + $row['tirz_rtn_rev'];
        $row['pills_total_pts'] = $row['pills_new_pts'] + $row['pills_fu_pts'] + $row['pills_rtn_pts'];
        $row['pills_total_rev'] = $row['pills_new_rev'] + $row['pills_fu_rev'] + $row['pills_rtn_rev'];
        $row['test_total_pts'] = $row['test_new_pts'] + $row['test_fu_pts'] + $row['test_rtn_pts'];
        $row['test_total_rev'] = $row['test_new_rev'] + $row['test_fu_rev'] + $row['test_rtn_rev'];
        $row['total_rev'] = $row['sg_total_rev'] + $row['trz_total_rev'] + $row['pills_total_rev'] + $row['test_total_rev'] + ($row['other_rev'] ?? 0);
        
        // Aggregate payment methods
        if (isset($row['payment_methods']) && is_array($row['payment_methods'])) {
            foreach ($row['payment_methods'] as $method => $amount) {
                if (isset($payment_method_totals[$method])) {
                    $payment_method_totals[$method] += floatval($amount);
                } else {
                    $payment_method_totals[$method] = floatval($amount);
                }
            }
        }
        
        $monthly[$month_key] = $row;
        $rows[] = $row;
        
        foreach ($totals as $key => $value) {
            $totals[$key] += $row[$key] ?? 0;
        }
    }
    
    $month_count = count($rows);
    $averages = [];
    foreach ($totals as $key => $value) {
        $averages[$key] = $month_count > 0 ? ($value / $month_count) : 0;
    }
    
    return [
        'rows' => $rows,
        'totals' => $totals,
        'averages' => $averages,
        'month_count' => $month_count,
        'payment_methods' => $all_payment_methods,
        'payment_method_totals' => $payment_method_totals
    ];
}

/**
 * Build monthly revenue grid (per-date totals within the month)
 */
function computeMonthlyRevenueGrid($patient_rows) {
    $per_day = [];
    
    // First, collect all unique payment methods
    $all_payment_methods = [];
    foreach ($patient_rows as $patient) {
        if (isset($patient['payment_methods']) && is_array($patient['payment_methods'])) {
            foreach ($patient['payment_methods'] as $method => $amount) {
                if (!in_array($method, $all_payment_methods)) {
                    $all_payment_methods[] = $method;
                }
            }
        }
    }
    sort($all_payment_methods);
    
    foreach ($patient_rows as $patient) {
        $first_visit = $patient['first_visit'] ?? null;
        $timestamp = $first_visit ? strtotime($first_visit) : false;
        if (!$timestamp) {
            continue;
        }
        
        $date_key = date('Y-m-d', $timestamp);
        if (!isset($per_day[$date_key])) {
            $per_day[$date_key] = [
                'date' => $timestamp,
                'office_visit' => 0,
                'lab' => 0,
                'lipo' => 0,
                'sema' => 0,
                'tirz' => 0,
                'testosterone' => 0,
                'afterpay' => 0,
                'supplements' => 0,
                'protein' => 0,
                'taxes' => 0,
                'total' => 0,
                'payment_methods' => []
            ];
            // Initialize all payment methods to 0
            foreach ($all_payment_methods as $method) {
                $per_day[$date_key]['payment_methods'][$method] = 0;
            }
        }
        
        $details = $patient['details'] ?? initializePatientDetails();
        $netAmounts = getPatientRevenueGridAmounts($details);
        $netSubtotal = getPatientRevenueGridSubtotal($details, $netAmounts);
        $bucketCategory = getBreakdownBucketCategory($details);

        if ($bucketCategory === 'testosterone' && patientHasCategoryActivity($details, 'testosterone')) {
            $per_day[$date_key]['testosterone'] += $netSubtotal;
        } else {
            $per_day[$date_key]['office_visit'] += $netAmounts['office_visit'];
            $per_day[$date_key]['lab'] += $netAmounts['lab'];
            $per_day[$date_key]['lipo'] += $netAmounts['lipo'];
            $per_day[$date_key]['sema'] += $netAmounts['sema'];
            $per_day[$date_key]['tirz'] += $netAmounts['tirz'];
            $per_day[$date_key]['testosterone'] += $netAmounts['testosterone'];
        }
        $per_day[$date_key]['afterpay'] += $netAmounts['afterpay'];
        $per_day[$date_key]['supplements'] += $netAmounts['supplements'];
        $per_day[$date_key]['protein'] += $netAmounts['protein'];
        $per_day[$date_key]['taxes'] += floatval($details['tax_amount'] ?? 0);
        $per_day[$date_key]['total'] += floatval($details['total_amount'] ?? $patient['total_spent'] ?? 0);
        
        // Aggregate payment methods
        if (isset($patient['payment_methods']) && is_array($patient['payment_methods'])) {
            foreach ($patient['payment_methods'] as $method => $amount) {
                if (isset($per_day[$date_key]['payment_methods'][$method])) {
                    $per_day[$date_key]['payment_methods'][$method] += floatval($amount);
                } else {
                    $per_day[$date_key]['payment_methods'][$method] = floatval($amount);
                }
            }
        }
    }
    
    if (empty($per_day)) {
        return [
            'rows' => [],
            'totals' => [
                'running_total' => 0,
                'office_visit' => 0,
                'lab' => 0,
                'lipo' => 0,
                'sema' => 0,
                'tirz' => 0,
                'testosterone' => 0,
                'afterpay' => 0,
                'supplements' => 0,
                'protein' => 0,
                'taxes' => 0,
                'total' => 0,
            ],
        ];
    }
    
    ksort($per_day);
    $rows = [];
    $running_total = 0;
    $totals = [
        'office_visit' => 0,
        'lab' => 0,
        'lipo' => 0,
        'sema' => 0,
        'tirz' => 0,
        'testosterone' => 0,
        'afterpay' => 0,
        'supplements' => 0,
        'protein' => 0,
        'taxes' => 0,
        'total' => 0,
    ];
    
    // Initialize payment method totals
    $payment_method_totals = [];
    foreach ($all_payment_methods as $method) {
        $payment_method_totals[$method] = 0;
    }
    
    foreach ($per_day as $date_key => $values) {
        foreach ($totals as $cat => $val) {
            $totals[$cat] += $values[$cat];
        }
        
        // Aggregate payment methods
        if (isset($values['payment_methods']) && is_array($values['payment_methods'])) {
            foreach ($values['payment_methods'] as $method => $amount) {
                if (isset($payment_method_totals[$method])) {
                    $payment_method_totals[$method] += floatval($amount);
                } else {
                    $payment_method_totals[$method] = floatval($amount);
                }
            }
        }
        
        $running_total += $values['total'];
        $row_data = [
            'date_label' => date('F j, Y', $values['date']),
            'label' => 'TOTAL',
            'running_total' => $running_total,
            'office_visit' => $values['office_visit'],
            'lab' => $values['lab'],
            'lipo' => $values['lipo'],
            'sema' => $values['sema'],
            'tirz' => $values['tirz'],
            'testosterone' => $values['testosterone'],
            'afterpay' => $values['afterpay'],
            'supplements' => $values['supplements'],
            'protein' => $values['protein'],
            'taxes' => $values['taxes'],
            'total' => $values['total'],
        ];
        
        // Add payment methods to row data
        foreach ($all_payment_methods as $method) {
            $row_data['payment_' . $method] = $values['payment_methods'][$method] ?? 0;
        }
        
        $rows[] = $row_data;
    }
    
    // Add payment method totals to totals array
    foreach ($all_payment_methods as $method) {
        $totals['payment_' . $method] = $payment_method_totals[$method] ?? 0;
    }
    
    return [
        'rows' => $rows,
        'totals' => array_merge(['running_total' => $running_total], $totals),
    ];
}

/**
 * Compute shot tracker breakdown by date (LIPO, SG & TRZ, TES cards)
 */
function computeShotTracker($patient_rows) {
    $per_day = [];
    
    foreach ($patient_rows as $patient) {
        $first_visit = $patient['first_visit'] ?? null;
        $timestamp = $first_visit ? strtotime($first_visit) : false;
        if (!$timestamp) {
            continue;
        }
        
        $date_key = date('Y-m-d', $timestamp);
        if (!isset($per_day[$date_key])) {
            $per_day[$date_key] = [
                'date' => $timestamp,
                'lipo_cards' => 0,
                'lipo_total' => 0,
                'sg_trz_cards' => 0,
                'sg_trz_total' => 0,
                'tes_cards' => 0,
                'tes_total' => 0,
            ];
        }
        
        $details = $patient['details'] ?? initializePatientDetails();
        
        // LIPO cards should follow the daily sold "#" column from Patient DCR.
        // Each card is a 5-shot cycle, with 4 paid + 1 free.
        $lipo_sold = intval($details['lipo_purchased_count'] ?? 0);
        $lipo_cards = getCardCountFromTakeHome($lipo_sold, 5);
        $per_day[$date_key]['lipo_cards'] += $lipo_cards;
        $per_day[$date_key]['lipo_total'] += $lipo_cards * 4;
        
        // SG & TRZ cards should follow the daily sold "#" columns from Patient DCR.
        // Combine same-day sold counts first, then divide by 4 to get completed cards.
        $sema_sold = intval($details['sema_purchased_count'] ?? 0);
        $tirz_sold = intval($details['tirz_purchased_count'] ?? 0);
        $sg_trz_sold_total = $sema_sold + $tirz_sold;
        $sg_trz_cards = getCardCountFromTakeHome($sg_trz_sold_total, 4);
        $per_day[$date_key]['sg_trz_cards'] += $sg_trz_cards;
        $per_day[$date_key]['sg_trz_total'] += $sg_trz_cards * 4;
        
        // TES should follow the daily sold "#" column from Patient DCR.
        $tes_sold = intval($details['testosterone_purchased_count'] ?? 0);
        $tes_cards = getCardCountFromTakeHome($tes_sold, 4);
        $per_day[$date_key]['tes_cards'] += $tes_cards;
        $per_day[$date_key]['tes_total'] += $tes_cards * 4;
    }

    foreach ($per_day as $date_key => $row) {
        if (
            intval($row['lipo_cards'] ?? 0) === 0 &&
            intval($row['sg_trz_cards'] ?? 0) === 0 &&
            intval($row['tes_cards'] ?? 0) === 0
        ) {
            unset($per_day[$date_key]);
        }
    }
    
    if (empty($per_day)) {
        return [
            'rows' => [],
            'totals' => [
                'lipo_cards' => 0,
                'lipo_total' => 0,
                'sg_trz_cards' => 0,
                'sg_trz_total' => 0,
                'tes_cards' => 0,
                'tes_total' => 0,
            ],
        ];
    }
    
    ksort($per_day);
    $rows = [];
    $totals = [
        'lipo_cards' => 0,
        'lipo_total' => 0,
        'sg_trz_cards' => 0,
        'sg_trz_total' => 0,
        'tes_cards' => 0,
        'tes_total' => 0,
    ];
    
    foreach ($per_day as $date_key => $values) {
        $totals['lipo_cards'] += $values['lipo_cards'];
        $totals['lipo_total'] += $values['lipo_total'];
        $totals['sg_trz_cards'] += $values['sg_trz_cards'];
        $totals['sg_trz_total'] += $values['sg_trz_total'];
        $totals['tes_cards'] += $values['tes_cards'];
        $totals['tes_total'] += $values['tes_total'];
        
        // Format date as "Sat 8-2"
        $day_abbr = date('D', $values['date']); // "Sat", "Wed", etc.
        $month_day = date('n-j', $values['date']); // "8-2", "8-6", etc.
        $date_label = substr($day_abbr, 0, 3) . ' ' . $month_day; // "Sat 8-2"
        
        $rows[] = [
            'date_key' => $date_key,
            'date_label' => $date_label,
            'lipo_cards' => $values['lipo_cards'],
            'lipo_total' => $values['lipo_total'],
            'sg_trz_cards' => $values['sg_trz_cards'],
            'sg_trz_total' => $values['sg_trz_total'],
            'tes_cards' => $values['tes_cards'],
            'tes_total' => $values['tes_total'],
        ];
    }
    
    return [
        'rows' => $rows,
        'totals' => $totals,
    ];
}

/**
 * Process individual medicine item from transaction
 */
function processMedicineItem(&$data, $item, $transaction_row, $transaction_type) {
    // Extract drug_id - it might be in 'drug_id' field or in 'id' field (format: drug_148_lot_100)
    $drug_id = intval($item['drug_id'] ?? 0);
    
    // If drug_id is 0, try to extract from 'id' field
    if ($drug_id === 0 && isset($item['id']) && is_string($item['id'])) {
        if (preg_match('/drug_(\d+)/', $item['id'], $matches)) {
            $drug_id = intval($matches[1]);
        }
    }
    
    $drug_name = $item['name'] ?? $item['display_name'] ?? 'Unknown';
    $quantity = getDcrReportedMedicineQuantity($item, $transaction_type);
    $dispense_qty = dcrNormalizeQuantity($item['dispense_quantity'] ?? 0);
    $administer_qty = dcrNormalizeQuantity($item['administer_quantity'] ?? 0);
    $total = calculateDcrItemTotal($item, $transaction_row['created_date'] ?? '');
    
    // Get medicine details from database
    $medicine_info = getMedicineInfo($drug_id);
    $category = $medicine_info['category'] ?? 'Other';
    
    // Initialize medicine tracking
    if (!isset($data['medicines'][$drug_id])) {
        $data['medicines'][$drug_id] = [
            'drug_id' => $drug_id,
            'name' => $drug_name,
            'category' => $category,
            'total_quantity' => 0,
            'total_dispensed' => 0,
            'total_administered' => 0,
            'total_revenue' => 0,
            'transaction_count' => 0,
            'patients' => [],
            'dosage' => $medicine_info['dosage'] ?? 'N/A'
        ];
    }
    
    // Update medicine stats
    $data['medicines'][$drug_id]['total_quantity'] += $quantity;
    $data['medicines'][$drug_id]['total_dispensed'] += $dispense_qty;
    $data['medicines'][$drug_id]['total_administered'] += $administer_qty;
    $data['medicines'][$drug_id]['total_revenue'] += $total;
    $data['medicines'][$drug_id]['transaction_count']++;
    
    // Track unique patients for this medicine
    if (!in_array($transaction_row['pid'], $data['medicines'][$drug_id]['patients'])) {
        $data['medicines'][$drug_id]['patients'][] = $transaction_row['pid'];
    }
    
    // Update category breakdown
    if (!isset($data['categories'][$category])) {
        $data['categories'][$category] = [
            'name' => $category,
            'total_quantity' => 0,
            'total_revenue' => 0,
            'patient_count' => 0,
            'transaction_count' => 0
        ];
    }
    
    $data['categories'][$category]['total_quantity'] += $quantity;
    $data['categories'][$category]['total_revenue'] += $total;
    $data['categories'][$category]['transaction_count']++;
    
    // Track marketplace vs administered
    $data['marketplace_quantity'] += $dispense_qty;
    $data['administered_quantity'] += $administer_qty;
    
    // Estimate revenue split (proportional to quantity)
    if ($quantity > 0) {
        $dispense_revenue = ($dispense_qty / $quantity) * $total;
        $administer_revenue = ($administer_qty / $quantity) * $total;
        $data['marketplace_revenue'] += $dispense_revenue;
        $data['administered_revenue'] += $administer_revenue;
    }
}

/**
 * Get medicine information from database
 */
function getMedicineInfo($drug_id) {
    if (!$drug_id || $drug_id == 0) {
        return ['category' => 'Other', 'dosage' => 'N/A'];
    }
    
    try {
        $query = "SELECT name, category_name, strength, form, size, unit FROM drugs WHERE drug_id = ?";
        $stmt = sqlStatement($query, [$drug_id]);
        
        if ($stmt) {
            $result = sqlFetchArray($stmt);
            if ($result) {
                $dosage = trim(($result['strength'] ?? '') . ' ' . ($result['form'] ?? '') . ' ' . 
                              ($result['size'] ?? '') . ' ' . ($result['unit'] ?? ''));
                
                return [
                    'name' => $result['name'] ?? 'Unknown',
                    'category' => $result['category_name'] ?? 'Other',
                    'dosage' => $dosage ?: 'N/A'
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Error getting medicine info: " . $e->getMessage());
    }
    
    return ['category' => 'Other', 'dosage' => 'N/A'];
}

/**
 * Check if patient is new for a specific date
 * A patient is "New" if they have NO transactions before the reference date
 * A patient is "Returning" if they have transactions on previous dates
 */
function isNewPatient($pid, $reference_date, $facility_id = null) {
    try {
        // Check if patient has any transactions BEFORE the reference date
        $query = "SELECT COUNT(*) as previous_visits 
                  FROM pos_transactions 
                  WHERE pid = ? AND DATE(created_date) < ?";
        $params = [$pid, $reference_date];
        if ($facility_id) {
            if (posTransactionsHaveFacilityColumn()) {
                $query .= " AND facility_id = ?";
            } else {
                $query .= " AND pid IN (SELECT pid FROM patient_data WHERE pid = ? AND facility_id = ?)";
                $params[] = $pid;
            }
            $params[] = $facility_id;
        }
        if (posTransactionsHaveVoidColumns()) {
            $query .= " AND COALESCE(voided, 0) = 0 AND transaction_type != 'void'";
        } else {
            $query .= " AND transaction_type != 'void'";
        }
        $query .= dcrBuildBackdateExclusionSql();
        $stmt = sqlStatement($query, $params);
        
        if ($stmt) {
            $result = sqlFetchArray($stmt);
            if ($result) {
                $previous_visits = intval($result['previous_visits'] ?? 0);
                
                // If there are any visits before this date, patient is returning
                return $previous_visits == 0;
            }
        }
    } catch (Exception $e) {
        error_log("Error checking new patient: " . $e->getMessage());
    }
    
    return true; // Default to new if can't determine
}

// Set cache control headers to prevent caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

/**
 * Get list of all medicines used in transactions
 */
function getAllMedicinesFromTransactions() {
    $medicines = [];

    try {
        // Use the inventory table first. Scanning every POS transaction to build a
        // dropdown becomes expensive on larger staging datasets and can prevent the
        // report page from rendering at all.
        $result = sqlStatement("SELECT drug_id, name FROM drugs WHERE active = 1 ORDER BY name");
        if ($result) {
            while ($row = sqlFetchArray($result)) {
                $medicines[] = [
                    'drug_id' => $row['drug_id'],
                    'name' => $row['name']
                ];
            }
        }

        if (!empty($medicines)) {
            return $medicines;
        }
    } catch (Exception $e) {
        error_log("DCR medicines lookup via drugs table failed, falling back to POS items: " . $e->getMessage());
    }

    $drug_ids_found = [];

    try {
        $query = "SELECT items FROM pos_transactions WHERE items IS NOT NULL AND items != ''";
        if (posTransactionsHaveVoidColumns()) {
            $query .= " AND COALESCE(voided, 0) = 0 AND transaction_type != 'void'";
        } else {
            $query .= " AND transaction_type != 'void'";
        }
        $result = sqlStatement($query);

        if ($result) {
            while ($row = sqlFetchArray($result)) {
                $items = json_decode($row['items'], true);
                if (!is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    $drug_id = intval($item['drug_id'] ?? 0);
                    if ($drug_id === 0 && isset($item['id']) && is_string($item['id']) && preg_match('/drug_(\d+)/', $item['id'], $matches)) {
                        $drug_id = intval($matches[1]);
                    }

                    if ($drug_id > 0) {
                        $drug_ids_found[$drug_id] = true;
                    }
                }
            }
        }

        if (!empty($drug_ids_found)) {
            $drugIds = array_keys($drug_ids_found);
            $placeholders = implode(',', array_fill(0, count($drugIds), '?'));
            $result = sqlStatement("SELECT drug_id, name FROM drugs WHERE drug_id IN ($placeholders) ORDER BY name", $drugIds);

            if ($result) {
                while ($row = sqlFetchArray($result)) {
                    $medicines[] = [
                        'drug_id' => $row['drug_id'],
                        'name' => $row['name']
                    ];
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error getting medicines from transactions fallback: " . $e->getMessage());
    }

    return $medicines;
}

function getDcrFacilityDisplayName($facilityId)
{
    if (empty($facilityId)) {
        return 'ALL FACILITIES';
    }

    try {
        $stmt = sqlStatement("SELECT name FROM facility WHERE id = ?", [$facilityId]);
        if ($stmt) {
            $row = sqlFetchArray($stmt);
            if ($row && isset($row['name']) && trim((string) $row['name']) !== '') {
                return strtoupper(trim((string) $row['name']));
            }
        }
    } catch (Exception $e) {
        error_log("DCR facility lookup failed: " . $e->getMessage());
    }

    return 'ALL FACILITIES';
}

function buildDcrEmailHtml(array $reportData, string $facilityDisplayName, string $dateFormatted, string $toDateFormatted): string
{
    $generatedAt = date('m/d/Y h:i A');
    $totalPatients = (int) count($reportData['unique_patients'] ?? []);
    $totalTransactions = (int) count($reportData['transactions'] ?? []);
    $totalRevenue = number_format((float) ($reportData['total_revenue'] ?? 0), 2);
    $netRevenue = number_format((float) ($reportData['net_revenue'] ?? 0), 2);
    $newPatients = (int) ($reportData['new_patients'] ?? 0);
    $returningPatients = (int) ($reportData['returning_patients'] ?? 0);
    $unitsDispensed = (int) ($reportData['marketplace_quantity'] ?? 0);
    $unitsAdministered = (int) ($reportData['administered_quantity'] ?? 0);

    return "
    <!doctype html>
    <html>
    <body style='margin:0; padding:24px; background:#eef3f8; font-family:Arial, Helvetica, sans-serif; color:#1f2937;'>
        <div style='max-width:920px; margin:0 auto; background:#ffffff; border:1px solid #d7e2ee; border-radius:16px; overflow:hidden; box-shadow:0 10px 24px rgba(15, 23, 42, 0.06);'>
            <div style='background:linear-gradient(135deg, #163a63 0%, #1f5a96 100%); color:#ffffff; padding:28px 32px 24px 32px;'>
                <div style='font-size:12px; letter-spacing:0.08em; text-transform:uppercase; opacity:0.82; margin-bottom:10px;'>JACtrac Reporting</div>
                <div style='font-size:28px; line-height:1.25; font-weight:700; margin-bottom:8px;'>Daily Collection Report</div>
                <div style='font-size:15px; line-height:1.5; opacity:0.94;'>
                    Your DCR summary for <strong>" . text($facilityDisplayName) . "</strong> is ready. The formatted Excel workbook is attached for review and distribution.
                </div>
            </div>

            <div style='padding:28px 32px;'>
                <div style='font-size:14px; line-height:1.7; color:#334155; margin-bottom:20px;'>
                    This email includes a concise operational summary for the selected reporting window. The attachment remains the source file for detailed daily reporting and recordkeeping.
                </div>

                <table cellpadding='0' cellspacing='0' border='0' style='width:100%; margin-bottom:22px;'>
                    <tr>
                        <td style='width:33.33%; padding-right:10px; vertical-align:top;'>
                            <div style='border:1px solid #d8e4f0; background:#f8fbff; border-radius:12px; padding:14px 16px;'>
                                <div style='font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:#64748b; margin-bottom:6px;'>Location</div>
                                <div style='font-size:15px; font-weight:700; color:#1e293b;'>" . text($facilityDisplayName) . "</div>
                            </div>
                        </td>
                        <td style='width:33.33%; padding:0 5px; vertical-align:top;'>
                            <div style='border:1px solid #d8e4f0; background:#f8fbff; border-radius:12px; padding:14px 16px;'>
                                <div style='font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:#64748b; margin-bottom:6px;'>Report Period</div>
                                <div style='font-size:15px; font-weight:700; color:#1e293b;'>" . text($dateFormatted) . " to " . text($toDateFormatted) . "</div>
                            </div>
                        </td>
                        <td style='width:33.33%; padding-left:10px; vertical-align:top;'>
                            <div style='border:1px solid #d8e4f0; background:#f8fbff; border-radius:12px; padding:14px 16px;'>
                                <div style='font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:#64748b; margin-bottom:6px;'>Generated</div>
                                <div style='font-size:15px; font-weight:700; color:#1e293b;'>" . text($generatedAt) . "</div>
                            </div>
                        </td>
                    </tr>
                </table>

                <div style='font-size:17px; font-weight:700; color:#183b63; margin:0 0 12px 0;'>Executive Summary</div>
                <table cellpadding='0' cellspacing='0' border='0' style='width:100%; margin-bottom:20px;'>
                    <tr>
                        <td style='width:50%; padding:0 8px 12px 0; vertical-align:top;'>
                            <div style='border:1px solid #d9e3ef; border-radius:12px; padding:16px 18px; background:#ffffff;'>
                                <div style='font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:#64748b; margin-bottom:8px;'>Total Revenue</div>
                                <div style='font-size:28px; font-weight:700; color:#163a63;'>$" . $totalRevenue . "</div>
                            </div>
                        </td>
                        <td style='width:50%; padding:0 0 12px 8px; vertical-align:top;'>
                            <div style='border:1px solid #d9e3ef; border-radius:12px; padding:16px 18px; background:#ffffff;'>
                                <div style='font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:#64748b; margin-bottom:8px;'>Net Revenue</div>
                                <div style='font-size:28px; font-weight:700; color:#163a63;'>$" . $netRevenue . "</div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style='width:50%; padding:0 8px 0 0; vertical-align:top;'>
                            <div style='border:1px solid #d9e3ef; border-radius:12px; padding:16px 18px; background:#ffffff;'>
                                <div style='font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:#64748b; margin-bottom:8px;'>Total Patients</div>
                                <div style='font-size:28px; font-weight:700; color:#163a63;'>" . $totalPatients . "</div>
                            </div>
                        </td>
                        <td style='width:50%; padding:0 0 0 8px; vertical-align:top;'>
                            <div style='border:1px solid #d9e3ef; border-radius:12px; padding:16px 18px; background:#ffffff;'>
                                <div style='font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:#64748b; margin-bottom:8px;'>Total Transactions</div>
                                <div style='font-size:28px; font-weight:700; color:#163a63;'>" . $totalTransactions . "</div>
                            </div>
                        </td>
                    </tr>
                </table>

                <div style='background:#f8fbff; border:1px solid #d8e4f0; border-radius:12px; padding:16px 18px; margin-bottom:22px;'>
                    <div style='font-size:14px; font-weight:700; color:#1e3a5f; margin-bottom:6px;'>Attachment Included</div>
                    <div style='font-size:13px; line-height:1.6; color:#475569;'>
                        The attached Excel workbook contains the complete DCR detail set for this reporting period, including daily breakdowns and supporting report tabs.
                    </div>
                </div>

                <div style='padding-top:18px; border-top:1px solid #e2e8f0;'>
                    <div style='font-size:14px; font-weight:700; color:#1e3a5f; margin-bottom:6px;'>Prepared by JACtrac</div>
                    <div style='font-size:13px; color:#475569; line-height:1.6; margin-bottom:4px;'>
                        Operational reporting for daily collections, patient activity, and revenue visibility.
                    </div>
                    <div style='font-size:12px; color:#64748b; line-height:1.6;'>
                        This report was generated automatically from the OpenEMR DCR workflow and delivered through the JACtrac reporting process.
                    </div>
                    <div style='font-size:12px; color:#64748b; line-height:1.6; margin-top:12px;'>
                        Thank you for using JacTrac.
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>";
}

function generateDcrCsvContent(array $reportData, string $facilityDisplayName, string $dateFormatted, string $toDateFormatted, string $formReportType, string $monthYear): string
{
    $stream = fopen('php://temp', 'r+');
    if (!$stream) {
        return '';
    }

    fprintf($stream, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($stream, ['PATIENT DCR GRID']);
    fputcsv($stream, ['Report Period: ' . $dateFormatted . ' to ' . $toDateFormatted]);
    fputcsv($stream, ['Facility: ' . $facilityDisplayName]);
    fputcsv($stream, []);
    $blankTakeHomeColumns = shouldBlankTakeHomeColumns($form_facility, $facilityDisplayName);

    fputcsv($stream, [
        'Sign In #', 'Patient Name', 'Action', 'OV', 'BW',
        'B12 Lipo - Card Punch #', 'B12 Lipo - TH', 'B12 Lipo - Amount', 'B12 Lipo - #',
        'Semaglutide - Card Punch #', 'Semaglutide - TH', 'Sema Injection', 'Semaglutide - #', 'Sema DOSE (mg)',
        'Tirzepatide - Card Punch #', 'Tirzepatide - TH', 'TRZ Injection', 'Tirzepatide - #', 'TRZ DOSE (mg)',
        'AfterPay Fee', 'Supplements - Amount', 'Supplements - #', 'Protein Shakes - Amount', 'Protein Shakes - #',
        'Subtotal', 'Tax', 'Credits', 'Total', 'Notes'
    ]);

    $seenPatients = [];
    foreach ($reportData['patient_rows'] ?? [] as $patient) {
        $details = $patient['details'] ?? initializePatientDetails();
        $patientKey = (string) (($patient['pid'] ?? '') . '|' . ($patient['first_visit'] ?? '') . '|' . ($patient['name'] ?? ''));
        if ($patientKey !== '||' && isset($seenPatients[$patientKey])) {
            continue;
        }
        $seenPatients[$patientKey] = true;

        $semaDose = !empty($details['sema_doses']) ? implode(', ', $details['sema_doses']) : '';
        $tirzDose = !empty($details['tirz_doses']) ? implode(', ', $details['tirz_doses']) : '';
        $lipoTakeHome = $blankTakeHomeColumns ? '' : formatNumberCell($details['lipo_takehome_count'] ?? 0, 0, true);
        $semaTakeHome = $blankTakeHomeColumns ? '' : formatNumberCell($details['sema_takehome_count'] ?? 0, 0, true);
        $tirzTakeHome = $blankTakeHomeColumns ? '' : formatNumberCell($details['tirz_takehome_count'] ?? 0, 0, true);
        $totalAmount = isset($details['total_amount']) ? (float) $details['total_amount'] : (float) ($patient['total_spent'] ?? 0);

        fputcsv($stream, [
            $patient['order_number'] ?? '',
            $patient['name'] ?? '',
            $details['visit_type'] ?? '-',
            number_format((float) ($details['ov_amount'] ?? 0), 2, '.', ''),
            number_format((float) ($details['bw_amount'] ?? 0), 2, '.', ''),
            resolvePatientCardPunchDisplay($details, 'lipo', 5),
            $lipoTakeHome,
            number_format((float) ($details['lipo_amount'] ?? 0), 2, '.', ''),
            (int) ($details['lipo_purchased_count'] ?? 0),
            resolvePatientCardPunchDisplay($details, 'sema', 4),
            $semaTakeHome,
            number_format((float) ($details['sema_amount'] ?? 0), 2, '.', ''),
            (int) ($details['sema_purchased_count'] ?? 0),
            $semaDose,
            resolvePatientCardPunchDisplay($details, 'tirz', 4),
            $tirzTakeHome,
            number_format((float) ($details['tirz_amount'] ?? 0), 2, '.', ''),
            (int) ($details['tirz_purchased_count'] ?? 0),
            $tirzDose,
            number_format((float) ($details['afterpay_fee'] ?? 0), 2, '.', ''),
            number_format((float) ($details['supplement_amount'] ?? 0), 2, '.', ''),
            (int) ($details['supplement_count'] ?? 0),
            number_format((float) ($details['drink_amount'] ?? 0), 2, '.', ''),
            (int) ($details['drink_count'] ?? 0),
            number_format((float) ($details['subtotal'] ?? 0), 2, '.', ''),
            number_format((float) ($details['tax_amount'] ?? 0), 2, '.', ''),
            number_format((float) ($details['credit_amount'] ?? 0), 2, '.', ''),
            number_format($totalAmount, 2, '.', ''),
            preg_replace("/\r\n|\r|\n/", " ", (string) ($details['price_override_notes'] ?? ''))
        ]);
    }

    fputcsv($stream, []);

    $dailyBreakdown = $reportData['daily_breakdown'] ?? ['rows' => [], 'totals' => []];
    if ($formReportType === 'daily' && !empty($dailyBreakdown['rows'])) {
        fputcsv($stream, ['DAILY BREAKDOWN']);
        fputcsv($stream, [
            'Day of week & date',
            'Semaglutide - New Pts', 'Semaglutide - New Revenue',
            'Semaglutide - FU Pts', 'Semaglutide - FU Revenue',
            'Semaglutide - Rtn Pts', 'Semaglutide - Rtn Revenue',
            'Semaglutide - Total Pts', 'Semaglutide - Total Revenue',
            'Tirzepatide - New Pts', 'Tirzepatide - New Revenue',
            'Tirzepatide - FU Pts', 'Tirzepatide - FU Revenue',
            'Tirzepatide - Rtn Pts', 'Tirzepatide - Rtn Revenue',
            'Tirzepatide - Total Pts', 'Tirzepatide - Total Revenue',
            'Pills - New Pts', 'Pills - New Revenue',
            'Pills - FU Pts', 'Pills - FU Revenue',
            'Pills - Rtn Pts', 'Pills - Rtn Revenue',
            'Pills - Total Pts', 'Pills - Total Revenue',
            'Testosterone - New Pts', 'Testosterone - New Revenue',
            'Testosterone - FU Pts', 'Testosterone - FU Revenue',
            'Testosterone - Rtn Pts', 'Testosterone - Rtn Revenue',
            'Testosterone - Total Pts', 'Testosterone - Total Revenue'
        ]);
        foreach ($dailyBreakdown['rows'] as $row) {
            fputcsv($stream, [
                $row['display_date'] ?? '',
                $row['sema_new_pts'] ?? 0, number_format((float) ($row['sema_new_rev'] ?? 0), 2, '.', ''),
                $row['sema_fu_pts'] ?? 0, number_format((float) ($row['sema_fu_rev'] ?? 0), 2, '.', ''),
                $row['sema_rtn_pts'] ?? 0, number_format((float) ($row['sema_rtn_rev'] ?? 0), 2, '.', ''),
                $row['sg_total_pts'] ?? 0, number_format((float) ($row['sg_total_rev'] ?? 0), 2, '.', ''),
                $row['tirz_new_pts'] ?? 0, number_format((float) ($row['tirz_new_rev'] ?? 0), 2, '.', ''),
                $row['tirz_fu_pts'] ?? 0, number_format((float) ($row['tirz_fu_rev'] ?? 0), 2, '.', ''),
                $row['tirz_rtn_pts'] ?? 0, number_format((float) ($row['tirz_rtn_rev'] ?? 0), 2, '.', ''),
                $row['trz_total_pts'] ?? 0, number_format((float) ($row['trz_total_rev'] ?? 0), 2, '.', ''),
                $row['pills_new_pts'] ?? 0, number_format((float) ($row['pills_new_rev'] ?? 0), 2, '.', ''),
                $row['pills_fu_pts'] ?? 0, number_format((float) ($row['pills_fu_rev'] ?? 0), 2, '.', ''),
                $row['pills_rtn_pts'] ?? 0, number_format((float) ($row['pills_rtn_rev'] ?? 0), 2, '.', ''),
                $row['pills_total_pts'] ?? 0, number_format((float) ($row['pills_total_rev'] ?? 0), 2, '.', ''),
                $row['test_new_pts'] ?? 0, number_format((float) ($row['test_new_rev'] ?? 0), 2, '.', ''),
                $row['test_fu_pts'] ?? 0, number_format((float) ($row['test_fu_rev'] ?? 0), 2, '.', ''),
                $row['test_rtn_pts'] ?? 0, number_format((float) ($row['test_rtn_rev'] ?? 0), 2, '.', ''),
                $row['test_total_pts'] ?? 0, number_format((float) ($row['test_total_rev'] ?? 0), 2, '.', '')
            ]);
        }
        fputcsv($stream, []);
    }

    $monthlyGrid = $reportData['monthly_revenue_grid'] ?? ['rows' => [], 'totals' => []];
    if (!empty($monthlyGrid['rows'])) {
        fputcsv($stream, ['REVENUE BREAKDOWN']);
        fputcsv($stream, ['Period: ' . $monthYear]);
        fputcsv($stream, ['Date', 'Running Totals', 'Office Visit', 'LAB', 'LIPO Inj', 'Semaglutide', 'Tirzepatide', 'Testosterone', 'AfterPay', 'Supplements', 'Protein Drinks', 'Taxes', 'Total']);
        foreach ($monthlyGrid['rows'] as $row) {
            fputcsv($stream, [
                $row['date_label'] ?? '',
                number_format((float) ($row['running_total'] ?? 0), 2, '.', ''),
                number_format((float) ($row['office_visit'] ?? 0), 2, '.', ''),
                number_format((float) ($row['lab'] ?? 0), 2, '.', ''),
                number_format((float) ($row['lipo'] ?? 0), 2, '.', ''),
                number_format((float) ($row['sema'] ?? 0), 2, '.', ''),
                number_format((float) ($row['tirz'] ?? 0), 2, '.', ''),
                number_format((float) ($row['testosterone'] ?? 0), 2, '.', ''),
                number_format((float) ($row['afterpay'] ?? 0), 2, '.', ''),
                number_format((float) ($row['supplements'] ?? 0), 2, '.', ''),
                number_format((float) ($row['protein'] ?? 0), 2, '.', ''),
                number_format((float) ($row['taxes'] ?? 0), 2, '.', ''),
                number_format((float) ($row['total'] ?? 0), 2, '.', '')
            ]);
        }
        fputcsv($stream, []);
    }

    $shotTracker = $reportData['shot_tracker'] ?? ['rows' => [], 'totals' => []];
    if (!empty($shotTracker['rows'])) {
        fputcsv($stream, ['SHOT TRACKER']);
        fputcsv($stream, ['Date', '# of LIPO Cards', 'Total from cards (LIPO)', '# of SG & TRZ Cards', 'Total from cards (SG & TRZ)', '# of TES Cards', 'Total from cards (TES)']);
        foreach ($shotTracker['rows'] as $row) {
            fputcsv($stream, [
                $row['date_label'] ?? '',
                $row['lipo_cards'] ?? 0,
                $row['lipo_total'] ?? 0,
                $row['sg_trz_cards'] ?? 0,
                $row['sg_trz_total'] ?? 0,
                $row['tes_cards'] ?? 0,
                $row['tes_total'] ?? 0
            ]);
        }
        fputcsv($stream, []);
    }

    fputcsv($stream, ['SUMMARY STATISTICS']);
    fputcsv($stream, ['Metric', 'Value']);
    $fallbackRevenue = 0.0;
    foreach ($reportData['transactions'] ?? [] as $transaction) {
        if (!shouldExcludeTransactionFromRevenue($transaction['transaction_type'] ?? '')) {
            $fallbackRevenue += (float) ($transaction['amount'] ?? 0);
        }
    }
    $csvTotalRevenue = (float) ($reportData['total_revenue'] ?? 0);
    if ($csvTotalRevenue <= 0 && $fallbackRevenue > 0) {
        $csvTotalRevenue = $fallbackRevenue;
    }
    $csvNetRevenue = (float) ($reportData['net_revenue'] ?? 0);
    if ($csvNetRevenue <= 0 && $csvTotalRevenue > 0) {
        $csvNetRevenue = $csvTotalRevenue;
    }
    fputcsv($stream, ['Total Revenue', number_format($csvTotalRevenue, 2, '.', '')]);
    fputcsv($stream, ['Net Revenue', number_format($csvNetRevenue, 2, '.', '')]);
    fputcsv($stream, ['Total Patients', count($seenPatients)]);
    fputcsv($stream, ['New Patients', (int) ($reportData['new_patients'] ?? 0)]);
    fputcsv($stream, ['Returning Patients', (int) ($reportData['returning_patients'] ?? 0)]);
    fputcsv($stream, ['Total Transactions', count($reportData['transactions'] ?? [])]);

    rewind($stream);
    $content = stream_get_contents($stream);
    fclose($stream);

    return $content === false ? '' : $content;
}

function dcrBuildDepositSheetData(string $formDate, string $formToDate, $formFacility): array
{
    $depositData = [];
    $depositTotals = [
        'cash' => 0.0,
        'checks' => 0.0,
        'credit' => 0.0,
        'actual_bank_deposit' => 0.0,
        'total_deposit' => 0.0,
        'subtotal_credit_checks' => 0.0,
        'total_revenue' => 0.0,
    ];

    $checkFacilityColumn = "SHOW COLUMNS FROM pos_transactions LIKE 'facility_id'";
    $facilityColumnExists = sqlStatement($checkFacilityColumn);
    $hasFacilityColumn = false;
    if ($facilityColumnExists) {
        $colRow = sqlFetchArray($facilityColumnExists);
        if ($colRow) {
            $hasFacilityColumn = true;
        }
    }

    $depositQuery = "SELECT 
        DATE(pt.created_date) as business_date,
        pt.payment_method,
        pt.amount";
    $depositVoidFilter = posTransactionsHaveVoidColumns()
        ? " AND COALESCE(pt.voided, 0) = 0 AND pt.transaction_type != 'void'"
        : " AND pt.transaction_type != 'void'";

    if ($hasFacilityColumn) {
        $depositQuery .= ",
        COALESCE(f.name, 'Unknown Facility') as facility_name,
        COALESCE(pt.facility_id, p.facility_id) as facility_id
        FROM pos_transactions pt
        INNER JOIN patient_data p ON p.pid = pt.pid
        LEFT JOIN facility f ON f.id = COALESCE(pt.facility_id, p.facility_id)";
    } else {
        $depositQuery .= ",
        ? as facility_name,
        ? as facility_id
        FROM pos_transactions pt
        INNER JOIN patient_data p ON p.pid = pt.pid";
    }

    $depositQuery .= " WHERE DATE(pt.created_date) BETWEEN ? AND ?
    AND pt.transaction_type IN ('purchase', 'purchase_and_dispens', 'external_payment')
    AND pt.amount > 0"
    . $depositVoidFilter
    . dcrBuildBackdateExclusionSql('pt');

    $depositParams = [];
    if (!$hasFacilityColumn) {
        $defaultFacilityName = 'ALL FACILITIES';
        if ($formFacility) {
            $facStmt = sqlStatement("SELECT name FROM facility WHERE id = ?", [$formFacility]);
            if ($facStmt) {
                $facRow = sqlFetchArray($facStmt);
                if ($facRow && isset($facRow['name'])) {
                    $defaultFacilityName = $facRow['name'];
                }
            }
        }
        $depositParams[] = $defaultFacilityName;
        $depositParams[] = $formFacility ?: 0;
    }

    $depositParams[] = $formDate;
    $depositParams[] = $formToDate;

    if ($formFacility) {
        if ($hasFacilityColumn) {
            $depositQuery .= " AND (pt.facility_id = ? OR (pt.facility_id IS NULL AND p.facility_id = ?))";
            $depositParams[] = $formFacility;
        } else {
            $depositQuery .= " AND p.facility_id = ?";
        }
        $depositParams[] = $formFacility;
    }

    $depositQuery .= " ORDER BY pt.created_date ASC";
    $depositResult = sqlStatement($depositQuery, $depositParams);

    while ($row = sqlFetchArray($depositResult)) {
        $date = $row['business_date'];
        $paymentMethod = strtolower($row['payment_method'] ?? '');
        $amount = (float) ($row['amount'] ?? 0);
        $facilityName = $row['facility_name'] ?? 'ALL FACILITIES';

        if (!isset($depositData[$date])) {
            $depositData[$date] = [
                'date' => $date,
                'facility_name' => $facilityName,
                'cash' => 0.0,
                'checks' => 0.0,
                'credit' => 0.0,
                'actual_bank_deposit' => 0.0,
                'total_deposit' => 0.0,
                'subtotal_credit_checks' => 0.0,
                'total_revenue' => 0.0,
            ];
        }

        if ($paymentMethod === 'cash') {
            $depositData[$date]['cash'] += $amount;
            $depositTotals['cash'] += $amount;
        } elseif ($paymentMethod === 'check') {
            $depositData[$date]['checks'] += $amount;
            $depositTotals['checks'] += $amount;
        } else {
            // Treat every remaining positive electronic/non-cash payment
            // method as part of the credit bucket so Deposit matches DCR revenue.
            $depositData[$date]['credit'] += $amount;
            $depositTotals['credit'] += $amount;
        }

        $depositData[$date]['actual_bank_deposit'] = $depositData[$date]['cash'] + $depositData[$date]['checks'];
        $depositData[$date]['total_deposit'] = $depositData[$date]['actual_bank_deposit'];
        $depositData[$date]['subtotal_credit_checks'] = $depositData[$date]['credit'] + $depositData[$date]['checks'];
        $depositData[$date]['total_revenue'] = $depositData[$date]['cash'] + $depositData[$date]['checks'] + $depositData[$date]['credit'];
    }

    $depositTotals['actual_bank_deposit'] = $depositTotals['cash'] + $depositTotals['checks'];
    $depositTotals['total_deposit'] = $depositTotals['actual_bank_deposit'];
    $depositTotals['subtotal_credit_checks'] = $depositTotals['credit'] + $depositTotals['checks'];
    $depositTotals['total_revenue'] = $depositTotals['cash'] + $depositTotals['checks'] + $depositTotals['credit'];

    ksort($depositData);

    return [
        'rows' => array_values($depositData),
        'totals' => $depositTotals,
    ];
}

function dcrComputeWorkbookPeriodLabel(string $formReportType, string $monthYear, string $formDate, string $formToDate): string
{
    if ($formReportType !== 'daily') {
        return $monthYear;
    }

    $startDateObj = DateTime::createFromFormat('Y-m-d', $formDate);
    $endDateObj = DateTime::createFromFormat('Y-m-d', $formToDate);
    if (!$startDateObj || !$endDateObj) {
        return $monthYear;
    }

    if ($startDateObj->format('Y-m') === $endDateObj->format('Y-m')) {
        return $startDateObj->format('F Y');
    }

    return $startDateObj->format('M Y') . ' - ' . $endDateObj->format('M Y');
}

function dcrExcelApplyRangeStyle($sheet, string $range, ?string $fillColor = null, bool $bold = false, string $horizontal = Alignment::HORIZONTAL_LEFT, bool $wrapText = false, int $fontSize = 11, string $borderColor = 'FFD9E2F3'): void
{
    $style = [
        'font' => [
            'bold' => $bold,
            'size' => $fontSize,
            'name' => 'Calibri',
            'color' => ['rgb' => 'FF000000'],
        ],
        'alignment' => [
            'horizontal' => $horizontal,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => $wrapText,
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => $borderColor],
            ],
        ],
    ];

    if ($fillColor !== null) {
        $style['fill'] = [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => $fillColor],
        ];
    }

    $sheet->getStyle($range)->applyFromArray($style);
}

function dcrExcelFormatCurrencyColumns($sheet, array $columns, int $startRow, int $endRow): void
{
    foreach ($columns as $column) {
        $sheet->getStyle($column . $startRow . ':' . $column . $endRow)
            ->getNumberFormat()
            ->setFormatCode('$#,##0.00');
    }
}

function dcrExcelFormatIntegerColumns($sheet, array $columns, int $startRow, int $endRow): void
{
    foreach ($columns as $column) {
        $sheet->getStyle($column . $startRow . ':' . $column . $endRow)
            ->getNumberFormat()
            ->setFormatCode('0');
    }
}

function dcrExcelFormatTextColumns($sheet, array $columns, int $startRow, int $endRow): void
{
    foreach ($columns as $column) {
        $sheet->getStyle($column . $startRow . ':' . $column . $endRow)
            ->getNumberFormat()
            ->setFormatCode('@');
    }
}

function dcrExcelValueOrBlank($value, int $decimals = 0, bool $blankWhenZero = true)
{
    if ($value === null || $value === '') {
        return '';
    }

    $number = floatval($value);
    if ($blankWhenZero && abs($number) < 0.00001) {
        return '';
    }

    if ($decimals <= 0) {
        return (abs($number - round($number)) < 0.00001) ? (int) round($number) : $number;
    }

    return round($number, $decimals);
}

function dcrExcelApplyCenteredNoWrapRange($sheet, string $range): void
{
    $alignment = $sheet->getStyle($range)->getAlignment();
    $alignment->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $alignment->setVertical(Alignment::VERTICAL_CENTER);
    $alignment->setWrapText(false);
}

function dcrExcelFinalizeSheetLayout($sheet, string $range, int $columnCount, array $widthOverrides = []): void
{
    dcrExcelApplyCenteredNoWrapRange($sheet, $range);
    dcrExcelAutosize($sheet, $columnCount);

    foreach ($widthOverrides as $column => $width) {
        $sheet->getColumnDimension($column)->setAutoSize(false);
        $sheet->getColumnDimension($column)->setWidth($width);
    }
}

function dcrPatientGroupHasActivity(array $details, array $netAmounts, string $group, $cardPunchDisplay = ''): bool
{
    switch ($group) {
        case 'lipo':
            return trim((string) $cardPunchDisplay) !== ''
                || abs((float) ($details['lipo_admin_count'] ?? 0)) > 0.00001
                || abs((float) ($details['lipo_card_punch_total'] ?? 0)) > 0.00001
                || abs((float) ($details['lipo_takehome_count'] ?? 0)) > 0.00001
                || abs((float) ($details['lipo_purchased_count'] ?? 0)) > 0.00001
                || abs((float) ($netAmounts['lipo'] ?? 0)) > 0.00001
                || !empty($details['lipo_doses']);
        case 'sema':
            return trim((string) $cardPunchDisplay) !== ''
                || abs((float) ($details['sema_admin_count'] ?? 0)) > 0.00001
                || abs((float) ($details['sema_card_punch_total'] ?? 0)) > 0.00001
                || abs((float) ($details['sema_takehome_count'] ?? 0)) > 0.00001
                || abs((float) ($details['sema_purchased_count'] ?? 0)) > 0.00001
                || abs((float) ($netAmounts['sema'] ?? 0)) > 0.00001
                || !empty($details['sema_doses']);
        case 'tirz':
            return trim((string) $cardPunchDisplay) !== ''
                || abs((float) ($details['tirz_admin_count'] ?? 0)) > 0.00001
                || abs((float) ($details['tirz_card_punch_total'] ?? 0)) > 0.00001
                || abs((float) ($details['tirz_takehome_count'] ?? 0)) > 0.00001
                || abs((float) ($details['tirz_purchased_count'] ?? 0)) > 0.00001
                || abs((float) ($netAmounts['tirz'] ?? 0)) > 0.00001
                || !empty($details['tirz_doses']);
        case 'supplements':
            return abs((float) ($details['supplement_count'] ?? 0)) > 0.00001
                || abs((float) ($netAmounts['supplements'] ?? 0)) > 0.00001;
        case 'protein':
            return abs((float) ($details['drink_count'] ?? 0)) > 0.00001
                || abs((float) ($netAmounts['protein'] ?? 0)) > 0.00001;
        case 'afterpay':
            return abs((float) ($netAmounts['afterpay'] ?? 0)) > 0.00001;
    }

    return false;
}

function dcrPatientGroupHasRowData(array $details, array $netAmounts, string $group, $cardPunchDisplay = ''): bool
{
    switch ($group) {
        case 'lipo':
        case 'sema':
        case 'tirz':
            return trim((string) $cardPunchDisplay) !== ''
                || abs((float) ($details[$group . '_admin_count'] ?? 0)) > 0.00001
                || abs((float) ($details[$group . '_takehome_count'] ?? 0)) > 0.00001
                || abs((float) ($details[$group . '_purchased_count'] ?? 0)) > 0.00001
                || abs((float) ($netAmounts[$group] ?? 0)) > 0.00001
                || !empty($details[$group . '_doses']);
        case 'supplements':
            return abs((float) ($details['supplement_count'] ?? 0)) > 0.00001
                || abs((float) ($netAmounts['supplements'] ?? 0)) > 0.00001;
        case 'protein':
            return abs((float) ($details['drink_count'] ?? 0)) > 0.00001
                || abs((float) ($netAmounts['protein'] ?? 0)) > 0.00001;
        case 'afterpay':
            return abs((float) ($netAmounts['afterpay'] ?? 0)) > 0.00001;
    }

    return false;
}

function dcrDisplayCurrencyForGroup(bool $groupActive, $value): string
{
    if (!$groupActive) {
        return '';
    }

    return formatCurrencyCell($value, true);
}

function dcrExcelValueForGroup(bool $groupActive, $value, int $decimals = 0)
{
    if (!$groupActive) {
        return '';
    }

    return dcrExcelValueOrBlank($value, $decimals, false);
}

function dcrExcelDisplayForGroup(bool $groupActive, $value, $zeroFallback = 0)
{
    if (!$groupActive) {
        return '';
    }

    $text = trim((string) ($value ?? ''));
    return $text !== '' ? $value : $zeroFallback;
}

function dcrDisplayNumberForGroup(bool $groupActive, $value, int $precision = 0): string
{
    if (!$groupActive) {
        return '';
    }

    $number = ($value === null || $value === '') ? 0 : $value;
    return formatNumberCell($number, $precision, true);
}

function dcrDisplayTextForGroup(bool $groupActive, $value, string $zeroFallback = '0'): string
{
    if (!$groupActive) {
        return '';
    }

    $text = trim((string) ($value ?? ''));
    return $text !== '' ? $text : $zeroFallback;
}

function dcrExcelAutosize($sheet, int $columnCount): void
{
    for ($i = 1; $i <= $columnCount; $i++) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
    }
}

function dcrBuildActualGrossPatientCountSheet(Spreadsheet $spreadsheet, int $sheetIndex, array $dailyBreakdown): void
{
    $existingSheet = $spreadsheet->getSheet($sheetIndex);
    $sheetName = $existingSheet->getTitle();
    $spreadsheet->removeSheetByIndex($sheetIndex);
    $sheet = $spreadsheet->createSheet($sheetIndex);
    $sheet->setTitle($sheetName);

    $headerGroups = [
        ['label' => 'Semaglutide', 'start' => 2, 'fill' => 'FFF4B084'],
        ['label' => 'Semaglutide', 'start' => 4, 'fill' => 'FFF4B084'],
        ['label' => 'Semaglutide', 'start' => 6, 'fill' => 'FFF4B084'],
        ['label' => 'SG Total', 'start' => 8, 'fill' => 'FFF4B084'],
        ['label' => 'Tirzepatide', 'start' => 10, 'fill' => 'FFD8C1DD'],
        ['label' => 'Tirzepatide', 'start' => 12, 'fill' => 'FFD8C1DD'],
        ['label' => 'Tirzepatide', 'start' => 14, 'fill' => 'FFD8C1DD'],
        ['label' => 'TRZ Total', 'start' => 16, 'fill' => 'FFD8C1DD'],
        ['label' => 'Pills', 'start' => 18, 'fill' => 'FF9BC2E6'],
        ['label' => 'Pills', 'start' => 20, 'fill' => 'FF9BC2E6'],
        ['label' => 'Pills', 'start' => 22, 'fill' => 'FF9BC2E6'],
        ['label' => 'Pills Total', 'start' => 24, 'fill' => 'FF9BC2E6'],
        ['label' => 'Testosterone', 'start' => 26, 'fill' => 'FFC5E0B4'],
        ['label' => 'Testosterone', 'start' => 28, 'fill' => 'FFC5E0B4'],
        ['label' => 'Testosterone', 'start' => 30, 'fill' => 'FFC5E0B4'],
        ['label' => 'Testosterone Total', 'start' => 32, 'fill' => 'FFC5E0B4'],
    ];
    $subHeaders = [
        ['New Pts', 'Revenue'],
        ['FU Pts', 'Revenue'],
        ['Rtn Pts', 'Revenue'],
        ['Pts', 'Revenue'],
        ['New Pts', 'Revenue'],
        ['FU Pts', 'Revenue'],
        ['Rtn Pts', 'Revenue'],
        ['Pts', 'Revenue'],
        ['New Pts', 'Revenue'],
        ['FU Pts', 'Revenue'],
        ['Rtn Pts', 'Revenue'],
        ['Pts', 'Revenue'],
        ['New Pts', 'Revenue'],
        ['FU Pts', 'Revenue'],
        ['Rtn Pts', 'Revenue'],
        ['Pts', 'Revenue'],
    ];

    $sheet->mergeCells('A1:A2');
    $sheet->setCellValue('A1', 'Day of week & date');
    dcrExcelApplyRangeStyle($sheet, 'A1:A2', 'FFFEFFFF', true, Alignment::HORIZONTAL_LEFT, true);

    foreach ($headerGroups as $index => $group) {
        $startColumn = Coordinate::stringFromColumnIndex($group['start']);
        $endColumn = Coordinate::stringFromColumnIndex($group['start'] + 1);
        $sheet->mergeCells($startColumn . '1:' . $endColumn . '1');
        $sheet->setCellValue($startColumn . '1', $group['label']);
        dcrExcelApplyRangeStyle($sheet, $startColumn . '1:' . $endColumn . '1', $group['fill'], true, Alignment::HORIZONTAL_CENTER, true);

        $sheet->setCellValue($startColumn . '2', $subHeaders[$index][0]);
        $sheet->setCellValue($endColumn . '2', $subHeaders[$index][1]);
        dcrExcelApplyRangeStyle($sheet, $startColumn . '2:' . $endColumn . '2', $group['fill'], true, Alignment::HORIZONTAL_CENTER, true);
    }

    $bodyRanges = [
        'A' => 'FFFEFFFF',
        'B:C' => 'FFF4B084',
        'D:E' => 'FFF4B084',
        'F:G' => 'FFF4B084',
        'H:I' => 'FFF4B084',
        'J:K' => 'FFD8C1DD',
        'L:M' => 'FFD8C1DD',
        'N:O' => 'FFD8C1DD',
        'P:Q' => 'FFD8C1DD',
        'R:S' => 'FF9BC2E6',
        'T:U' => 'FF9BC2E6',
        'V:W' => 'FF9BC2E6',
        'X:Y' => 'FF9BC2E6',
        'Z:AA' => 'FFC5E0B4',
        'AB:AC' => 'FFC5E0B4',
        'AD:AE' => 'FFC5E0B4',
        'AF:AG' => 'FFC5E0B4',
    ];

    $dataRows = $dailyBreakdown['rows'] ?? [];
    $rowNumber = 3;
    foreach ($dataRows as $row) {
        $sheet->setCellValue('A' . $rowNumber, $row['display_date'] ?? '');
        $sheet->fromArray([
            dcrExcelValueOrBlank($row['sema_new_pts'] ?? 0),
            dcrExcelValueOrBlank($row['sema_new_rev'] ?? 0, 2),
            dcrExcelValueOrBlank($row['sema_fu_pts'] ?? 0),
            dcrExcelValueOrBlank($row['sema_fu_rev'] ?? 0, 2),
            dcrExcelValueOrBlank($row['sema_rtn_pts'] ?? 0),
            dcrExcelValueOrBlank($row['sema_rtn_rev'] ?? 0, 2),
            dcrExcelValueOrBlank($row['sg_total_pts'] ?? 0),
            dcrExcelValueOrBlank($row['sg_total_rev'] ?? 0, 2),
            dcrExcelValueOrBlank($row['tirz_new_pts'] ?? 0),
            dcrExcelValueOrBlank($row['tirz_new_rev'] ?? 0, 2),
            dcrExcelValueOrBlank($row['tirz_fu_pts'] ?? 0),
            dcrExcelValueOrBlank($row['tirz_fu_rev'] ?? 0, 2),
            dcrExcelValueOrBlank($row['tirz_rtn_pts'] ?? 0),
            dcrExcelValueOrBlank($row['tirz_rtn_rev'] ?? 0, 2),
            dcrExcelValueOrBlank($row['trz_total_pts'] ?? 0),
            dcrExcelValueOrBlank($row['trz_total_rev'] ?? 0, 2),
            dcrExcelValueOrBlank($row['pills_new_pts'] ?? 0),
            dcrExcelValueOrBlank($row['pills_new_rev'] ?? 0, 2),
            dcrExcelValueOrBlank($row['pills_fu_pts'] ?? 0),
            dcrExcelValueOrBlank($row['pills_fu_rev'] ?? 0, 2),
            dcrExcelValueOrBlank($row['pills_rtn_pts'] ?? 0),
            dcrExcelValueOrBlank($row['pills_rtn_rev'] ?? 0, 2),
            dcrExcelValueOrBlank($row['pills_total_pts'] ?? 0),
            dcrExcelValueOrBlank($row['pills_total_rev'] ?? 0, 2),
            dcrExcelValueOrBlank($row['test_new_pts'] ?? 0),
            dcrExcelValueOrBlank($row['test_new_rev'] ?? 0, 2),
            dcrExcelValueOrBlank($row['test_fu_pts'] ?? 0),
            dcrExcelValueOrBlank($row['test_fu_rev'] ?? 0, 2),
            dcrExcelValueOrBlank($row['test_rtn_pts'] ?? 0),
            dcrExcelValueOrBlank($row['test_rtn_rev'] ?? 0, 2),
            dcrExcelValueOrBlank($row['test_total_pts'] ?? 0),
            dcrExcelValueOrBlank($row['test_total_rev'] ?? 0, 2),
        ], null, 'B' . $rowNumber);

        foreach ($bodyRanges as $rangeColumns => $fillColor) {
            [$startColumn, $endColumn] = array_pad(explode(':', $rangeColumns, 2), 2, null);
            $endColumn = $endColumn ?: $startColumn;
            dcrExcelApplyRangeStyle($sheet, $startColumn . $rowNumber . ':' . $endColumn . $rowNumber, $fillColor, false, Alignment::HORIZONTAL_CENTER, false, 11, 'FFD9E2F3');
        }
        $rowNumber++;
    }

    if (empty($dataRows)) {
        $sheet->mergeCells('A3:AG3');
        $sheet->setCellValue('A3', 'No gross patient count data available for the selected filters.');
        dcrExcelApplyRangeStyle($sheet, 'A3:AG3', 'FFFEFFFF', false, Alignment::HORIZONTAL_CENTER);
        $rowNumber = 4;
    }

    $totalsRow = $rowNumber;
    $averagesRow = $rowNumber + 1;

    $sheet->setCellValue('A' . $totalsRow, 'Total');
    $sheet->fromArray([
        dcrExcelValueOrBlank($dailyBreakdown['totals']['sema_new_pts'] ?? 0),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['sema_new_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['sema_fu_pts'] ?? 0),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['sema_fu_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['sema_rtn_pts'] ?? 0),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['sema_rtn_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['sg_total_pts'] ?? 0),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['sg_total_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['tirz_new_pts'] ?? 0),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['tirz_new_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['tirz_fu_pts'] ?? 0),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['tirz_fu_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['tirz_rtn_pts'] ?? 0),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['tirz_rtn_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['trz_total_pts'] ?? 0),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['trz_total_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['pills_new_pts'] ?? 0),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['pills_new_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['pills_fu_pts'] ?? 0),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['pills_fu_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['pills_rtn_pts'] ?? 0),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['pills_rtn_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['pills_total_pts'] ?? 0),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['pills_total_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['test_new_pts'] ?? 0),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['test_new_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['test_fu_pts'] ?? 0),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['test_fu_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['test_rtn_pts'] ?? 0),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['test_rtn_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['test_total_pts'] ?? 0),
        dcrExcelValueOrBlank($dailyBreakdown['totals']['test_total_rev'] ?? 0, 2),
    ], null, 'B' . $totalsRow);
    foreach ($bodyRanges as $rangeColumns => $fillColor) {
        [$startColumn, $endColumn] = array_pad(explode(':', $rangeColumns, 2), 2, null);
        $endColumn = $endColumn ?: $startColumn;
        dcrExcelApplyRangeStyle($sheet, $startColumn . $totalsRow . ':' . $endColumn . $totalsRow, $fillColor, true, Alignment::HORIZONTAL_CENTER, false, 11, 'FFD9E2F3');
    }
    $sheet->getStyle('A' . $totalsRow . ':AG' . $totalsRow)->getFont()->getColor()->setRGB('FFFF0000');

    $sheet->setCellValue('A' . $averagesRow, 'Average');
    $sheet->fromArray([
        dcrExcelValueOrBlank($dailyBreakdown['averages']['sema_new_pts'] ?? 0, 1),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['sema_new_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['sema_fu_pts'] ?? 0, 1),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['sema_fu_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['sema_rtn_pts'] ?? 0, 1),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['sema_rtn_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['sg_total_pts'] ?? 0, 1),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['sg_total_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['tirz_new_pts'] ?? 0, 1),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['tirz_new_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['tirz_fu_pts'] ?? 0, 1),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['tirz_fu_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['tirz_rtn_pts'] ?? 0, 1),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['tirz_rtn_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['trz_total_pts'] ?? 0, 1),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['trz_total_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['pills_new_pts'] ?? 0, 1),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['pills_new_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['pills_fu_pts'] ?? 0, 1),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['pills_fu_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['pills_rtn_pts'] ?? 0, 1),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['pills_rtn_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['pills_total_pts'] ?? 0, 1),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['pills_total_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['test_new_pts'] ?? 0, 1),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['test_new_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['test_fu_pts'] ?? 0, 1),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['test_fu_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['test_rtn_pts'] ?? 0, 1),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['test_rtn_rev'] ?? 0, 2),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['test_total_pts'] ?? 0, 1),
        dcrExcelValueOrBlank($dailyBreakdown['averages']['test_total_rev'] ?? 0, 2),
    ], null, 'B' . $averagesRow);
    foreach ($bodyRanges as $rangeColumns => $fillColor) {
        [$startColumn, $endColumn] = array_pad(explode(':', $rangeColumns, 2), 2, null);
        $endColumn = $endColumn ?: $startColumn;
        dcrExcelApplyRangeStyle($sheet, $startColumn . $averagesRow . ':' . $endColumn . $averagesRow, $fillColor, true, Alignment::HORIZONTAL_CENTER, false, 11, 'FFD9E2F3');
    }
    $sheet->getStyle('A' . $averagesRow . ':AG' . $averagesRow)->getFont()->getColor()->setRGB('FFFF0000');
    $sheet->getStyle('A' . $averagesRow . ':AG' . $averagesRow)->getFont()->setItalic(true);

    dcrExcelFinalizeSheetLayout($sheet, 'A1:AG' . $averagesRow, 33, ['A' => 24]);
    $sheet->freezePane('B3');
}

function dcrPopulateGrossTemplateSheet($sheet, string $facilityDisplayName, string $periodLabel, array $dailyBreakdown): void
{
    $sheet->setCellValue('A1', strtoupper($facilityDisplayName));
    $sheet->setCellValue('F1', $periodLabel);

    $dataStartRow = 5;
    $dataEndRow = 22;
    $totalRow = 23;
    $averageRow = 24;

    for ($row = $dataStartRow; $row <= $dataEndRow; $row++) {
        foreach (range('A', 'Z') as $column) {
            $sheet->setCellValue($column . $row, '');
        }
        $sheet->setCellValue('AA' . $row, '');
    }

    $rows = $dailyBreakdown['rows'] ?? [];
    $currentRow = $dataStartRow;
    foreach ($rows as $row) {
        if ($currentRow > $dataEndRow) {
            break;
        }

        $sheet->setCellValue('A' . $currentRow, $row['display_date'] ?? '');
        $sheet->setCellValue('B' . $currentRow, formatNumberExcelValue($row['sema_new_pts'] ?? 0));
        $sheet->setCellValue('C' . $currentRow, formatCurrencyExcelValue($row['sema_new_rev'] ?? 0));
        $sheet->setCellValue('D' . $currentRow, formatNumberExcelValue($row['sema_fu_pts'] ?? 0));
        $sheet->setCellValue('E' . $currentRow, formatCurrencyExcelValue($row['sema_fu_rev'] ?? 0));
        $sheet->setCellValue('F' . $currentRow, formatNumberExcelValue($row['sg_total_pts'] ?? 0));
        $sheet->setCellValue('G' . $currentRow, formatCurrencyExcelValue($row['sg_total_rev'] ?? 0));
        $sheet->setCellValue('H' . $currentRow, formatNumberExcelValue($row['tirz_new_pts'] ?? 0));
        $sheet->setCellValue('I' . $currentRow, formatCurrencyExcelValue($row['tirz_new_rev'] ?? 0));
        $sheet->setCellValue('J' . $currentRow, formatNumberExcelValue($row['tirz_fu_pts'] ?? 0));
        $sheet->setCellValue('K' . $currentRow, formatCurrencyExcelValue($row['tirz_fu_rev'] ?? 0));
        $sheet->setCellValue('L' . $currentRow, formatNumberExcelValue($row['trz_total_pts'] ?? 0));
        $sheet->setCellValue('M' . $currentRow, formatCurrencyExcelValue($row['trz_total_rev'] ?? 0));
        $sheet->setCellValue('N' . $currentRow, formatNumberExcelValue($row['pills_new_pts'] ?? 0));
        $sheet->setCellValue('O' . $currentRow, formatCurrencyExcelValue($row['pills_new_rev'] ?? 0));
        $sheet->setCellValue('P' . $currentRow, formatNumberExcelValue($row['pills_fu_pts'] ?? 0));
        $sheet->setCellValue('Q' . $currentRow, formatCurrencyExcelValue($row['pills_fu_rev'] ?? 0));
        $sheet->setCellValue('R' . $currentRow, formatNumberExcelValue($row['pills_total_pts'] ?? 0));
        $sheet->setCellValue('S' . $currentRow, formatCurrencyExcelValue($row['pills_total_rev'] ?? 0));
        $sheet->setCellValue('T' . $currentRow, (float) ($row['test_new_pts'] ?? 0));
        $sheet->setCellValue('U' . $currentRow, (float) ($row['test_new_rev'] ?? 0));
        $sheet->setCellValue('V' . $currentRow, (float) ($row['test_fu_pts'] ?? 0));
        $sheet->setCellValue('W' . $currentRow, (float) ($row['test_fu_rev'] ?? 0));
        $sheet->setCellValue('X' . $currentRow, (float) ($row['test_total_pts'] ?? 0));
        $sheet->setCellValue('Y' . $currentRow, (float) ($row['test_total_rev'] ?? 0));
        $sheet->setCellValue('Z' . $currentRow, 0.0);
        $sheet->setCellValue('AA' . $currentRow, (float) (
            ($row['sg_total_rev'] ?? 0) +
            ($row['trz_total_rev'] ?? 0) +
            ($row['pills_total_rev'] ?? 0) +
            ($row['test_total_rev'] ?? 0)
        ));

        $currentRow++;
    }

    $sheet->setCellValue('A' . $totalRow, 'TOTAL');
    $sheet->setCellValue('B' . $totalRow, formatNumberExcelValue($dailyBreakdown['totals']['sema_new_pts'] ?? 0));
    $sheet->setCellValue('C' . $totalRow, formatCurrencyExcelValue($dailyBreakdown['totals']['sema_new_rev'] ?? 0));
    $sheet->setCellValue('D' . $totalRow, formatNumberExcelValue($dailyBreakdown['totals']['sema_fu_pts'] ?? 0));
    $sheet->setCellValue('E' . $totalRow, formatCurrencyExcelValue($dailyBreakdown['totals']['sema_fu_rev'] ?? 0));
    $sheet->setCellValue('F' . $totalRow, formatNumberExcelValue($dailyBreakdown['totals']['sg_total_pts'] ?? 0));
    $sheet->setCellValue('G' . $totalRow, formatCurrencyExcelValue($dailyBreakdown['totals']['sg_total_rev'] ?? 0));
    $sheet->setCellValue('H' . $totalRow, formatNumberExcelValue($dailyBreakdown['totals']['tirz_new_pts'] ?? 0));
    $sheet->setCellValue('I' . $totalRow, formatCurrencyExcelValue($dailyBreakdown['totals']['tirz_new_rev'] ?? 0));
    $sheet->setCellValue('J' . $totalRow, formatNumberExcelValue($dailyBreakdown['totals']['tirz_fu_pts'] ?? 0));
    $sheet->setCellValue('K' . $totalRow, formatCurrencyExcelValue($dailyBreakdown['totals']['tirz_fu_rev'] ?? 0));
    $sheet->setCellValue('L' . $totalRow, formatNumberExcelValue($dailyBreakdown['totals']['trz_total_pts'] ?? 0));
    $sheet->setCellValue('M' . $totalRow, formatCurrencyExcelValue($dailyBreakdown['totals']['trz_total_rev'] ?? 0));
    $sheet->setCellValue('N' . $totalRow, formatNumberExcelValue($dailyBreakdown['totals']['pills_new_pts'] ?? 0));
    $sheet->setCellValue('O' . $totalRow, formatCurrencyExcelValue($dailyBreakdown['totals']['pills_new_rev'] ?? 0));
    $sheet->setCellValue('P' . $totalRow, formatNumberExcelValue($dailyBreakdown['totals']['pills_fu_pts'] ?? 0));
    $sheet->setCellValue('Q' . $totalRow, formatCurrencyExcelValue($dailyBreakdown['totals']['pills_fu_rev'] ?? 0));
    $sheet->setCellValue('R' . $totalRow, formatNumberExcelValue($dailyBreakdown['totals']['pills_total_pts'] ?? 0));
    $sheet->setCellValue('S' . $totalRow, formatCurrencyExcelValue($dailyBreakdown['totals']['pills_total_rev'] ?? 0));
    $sheet->setCellValue('T' . $totalRow, (float) ($dailyBreakdown['totals']['test_new_pts'] ?? 0));
    $sheet->setCellValue('U' . $totalRow, (float) ($dailyBreakdown['totals']['test_new_rev'] ?? 0));
    $sheet->setCellValue('V' . $totalRow, (float) ($dailyBreakdown['totals']['test_fu_pts'] ?? 0));
    $sheet->setCellValue('W' . $totalRow, (float) ($dailyBreakdown['totals']['test_fu_rev'] ?? 0));
    $sheet->setCellValue('X' . $totalRow, (float) ($dailyBreakdown['totals']['test_total_pts'] ?? 0));
    $sheet->setCellValue('Y' . $totalRow, (float) ($dailyBreakdown['totals']['test_total_rev'] ?? 0));
    $sheet->setCellValue('Z' . $totalRow, 0.0);
    $sheet->setCellValue('AA' . $totalRow, (float) (
        ($dailyBreakdown['totals']['sg_total_rev'] ?? 0) +
        ($dailyBreakdown['totals']['trz_total_rev'] ?? 0) +
        ($dailyBreakdown['totals']['pills_total_rev'] ?? 0) +
        ($dailyBreakdown['totals']['test_total_rev'] ?? 0)
    ));

    $sheet->setCellValue('A' . $averageRow, 'AVERAGE');
    $sheet->setCellValue('B' . $averageRow, formatNumberExcelValue($dailyBreakdown['averages']['sema_new_pts'] ?? 0, 1));
    $sheet->setCellValue('C' . $averageRow, formatCurrencyExcelValue($dailyBreakdown['averages']['sema_new_rev'] ?? 0));
    $sheet->setCellValue('D' . $averageRow, formatNumberExcelValue($dailyBreakdown['averages']['sema_fu_pts'] ?? 0, 1));
    $sheet->setCellValue('E' . $averageRow, formatCurrencyExcelValue($dailyBreakdown['averages']['sema_fu_rev'] ?? 0));
    $sheet->setCellValue('F' . $averageRow, formatNumberExcelValue($dailyBreakdown['averages']['sg_total_pts'] ?? 0, 1));
    $sheet->setCellValue('G' . $averageRow, formatCurrencyExcelValue($dailyBreakdown['averages']['sg_total_rev'] ?? 0));
    $sheet->setCellValue('H' . $averageRow, formatNumberExcelValue($dailyBreakdown['averages']['tirz_new_pts'] ?? 0, 1));
    $sheet->setCellValue('I' . $averageRow, formatCurrencyExcelValue($dailyBreakdown['averages']['tirz_new_rev'] ?? 0));
    $sheet->setCellValue('J' . $averageRow, formatNumberExcelValue($dailyBreakdown['averages']['tirz_fu_pts'] ?? 0, 1));
    $sheet->setCellValue('K' . $averageRow, formatCurrencyExcelValue($dailyBreakdown['averages']['tirz_fu_rev'] ?? 0));
    $sheet->setCellValue('L' . $averageRow, formatNumberExcelValue($dailyBreakdown['averages']['trz_total_pts'] ?? 0, 1));
    $sheet->setCellValue('M' . $averageRow, formatCurrencyExcelValue($dailyBreakdown['averages']['trz_total_rev'] ?? 0));
    $sheet->setCellValue('N' . $averageRow, formatNumberExcelValue($dailyBreakdown['averages']['pills_new_pts'] ?? 0, 1));
    $sheet->setCellValue('O' . $averageRow, formatCurrencyExcelValue($dailyBreakdown['averages']['pills_new_rev'] ?? 0));
    $sheet->setCellValue('P' . $averageRow, formatNumberExcelValue($dailyBreakdown['averages']['pills_fu_pts'] ?? 0, 1));
    $sheet->setCellValue('Q' . $averageRow, formatCurrencyExcelValue($dailyBreakdown['averages']['pills_fu_rev'] ?? 0));
    $sheet->setCellValue('R' . $averageRow, formatNumberExcelValue($dailyBreakdown['averages']['pills_total_pts'] ?? 0, 1));
    $sheet->setCellValue('S' . $averageRow, formatCurrencyExcelValue($dailyBreakdown['averages']['pills_total_rev'] ?? 0));
    $sheet->setCellValue('T' . $averageRow, (float) ($dailyBreakdown['averages']['test_new_pts'] ?? 0));
    $sheet->setCellValue('U' . $averageRow, (float) ($dailyBreakdown['averages']['test_new_rev'] ?? 0));
    $sheet->setCellValue('V' . $averageRow, (float) ($dailyBreakdown['averages']['test_fu_pts'] ?? 0));
    $sheet->setCellValue('W' . $averageRow, (float) ($dailyBreakdown['averages']['test_fu_rev'] ?? 0));
    $sheet->setCellValue('X' . $averageRow, (float) ($dailyBreakdown['averages']['test_total_pts'] ?? 0));
    $sheet->setCellValue('Y' . $averageRow, (float) ($dailyBreakdown['averages']['test_total_rev'] ?? 0));
    $sheet->setCellValue('Z' . $averageRow, 0.0);
    $sheet->setCellValue('AA' . $averageRow, (float) (
        ($dailyBreakdown['averages']['sg_total_rev'] ?? 0) +
        ($dailyBreakdown['averages']['trz_total_rev'] ?? 0) +
        ($dailyBreakdown['averages']['pills_total_rev'] ?? 0) +
        ($dailyBreakdown['averages']['test_total_rev'] ?? 0)
    ));
}

function dcrBuildActualShotTrackerSheet(Spreadsheet $spreadsheet, int $sheetIndex, string $facilityDisplayName, string $periodLabel, array $shotTracker): void
{
    $existingSheet = $spreadsheet->getSheet($sheetIndex);
    $sheetName = $existingSheet->getTitle();
    $spreadsheet->removeSheetByIndex($sheetIndex);
    $sheet = $spreadsheet->createSheet($sheetIndex);
    $sheet->setTitle($sheetName);

    $sheet->mergeCells('A1:A1');
    $sheet->mergeCells('B1:G1');
    $sheet->setCellValue('A1', strtoupper($facilityDisplayName));
    $sheet->setCellValue('B1', strtoupper($periodLabel));
    dcrExcelApplyRangeStyle($sheet, 'A1:A1', 'FFC6E0B4', true, Alignment::HORIZONTAL_LEFT, false, 12, 'FF000000');
    dcrExcelApplyRangeStyle($sheet, 'B1:G1', 'FFFFE699', true, Alignment::HORIZONTAL_CENTER, false, 12, 'FF000000');

    $headers = [
        ['DATE', 'FFD9D9D9'],
        ['# OF LIPO CARDS', 'FFD9D9D9'],
        ['TOTAL FROM CARDS (LIPO)', 'FFD9D9D9'],
        ['# OF SG & TRZ CARDS', 'FFF9CB9C'],
        ['TOTAL FROM CARDS (SG & TRZ)', 'FFF9CB9C'],
        ['# OF TES CARDS', 'FFC5E0B4'],
        ['TOTAL FROM CARDS (TES)', 'FFC5E0B4'],
    ];

    foreach ($headers as $index => $headerData) {
        $column = Coordinate::stringFromColumnIndex($index + 1);
        $sheet->setCellValue($column . '2', $headerData[0]);
        dcrExcelApplyRangeStyle($sheet, $column . '2', $headerData[1], true, Alignment::HORIZONTAL_CENTER, true, 11, 'FF000000');
    }

    $rowNumber = 3;
    foreach ($shotTracker['rows'] ?? [] as $row) {
        $sheet->fromArray([
            $row['date_label'] ?? '',
            dcrExcelValueOrBlank($row['lipo_cards'] ?? 0),
            dcrExcelValueOrBlank($row['lipo_total'] ?? 0, 2),
            dcrExcelValueOrBlank($row['sg_trz_cards'] ?? 0),
            dcrExcelValueOrBlank($row['sg_trz_total'] ?? 0, 2),
            dcrExcelValueOrBlank($row['tes_cards'] ?? 0),
            dcrExcelValueOrBlank($row['tes_total'] ?? 0, 2),
        ], null, 'A' . $rowNumber);
        dcrExcelApplyRangeStyle($sheet, 'A' . $rowNumber . ':G' . $rowNumber, 'FFFFF2CC', false, Alignment::HORIZONTAL_CENTER, false, 11, 'FF000000');
        $rowNumber++;
    }

    if (empty($shotTracker['rows'])) {
        $sheet->mergeCells('A3:G3');
        $sheet->setCellValue('A3', 'No shot tracker data available for the selected filters.');
        dcrExcelApplyRangeStyle($sheet, 'A3:G3', 'FFFEFFFF', false, Alignment::HORIZONTAL_CENTER, false, 11, 'FF000000');
        $rowNumber = 4;
    }

    $totalsRow = $rowNumber;
    $sheet->setCellValue('A' . $totalsRow, 'Totals');
    $sheet->fromArray([
        dcrExcelValueOrBlank($shotTracker['totals']['lipo_cards'] ?? 0),
        dcrExcelValueOrBlank($shotTracker['totals']['lipo_total'] ?? 0, 2),
        dcrExcelValueOrBlank($shotTracker['totals']['sg_trz_cards'] ?? 0),
        dcrExcelValueOrBlank($shotTracker['totals']['sg_trz_total'] ?? 0, 2),
        dcrExcelValueOrBlank($shotTracker['totals']['tes_cards'] ?? 0),
        dcrExcelValueOrBlank($shotTracker['totals']['tes_total'] ?? 0, 2),
    ], null, 'B' . $totalsRow);
    dcrExcelApplyRangeStyle($sheet, 'A' . $totalsRow . ':G' . $totalsRow, 'FFFFF2CC', true, Alignment::HORIZONTAL_CENTER, false, 11, 'FF000000');
    dcrExcelFinalizeSheetLayout($sheet, 'A1:G' . $totalsRow, 7, ['A' => 18]);
    $sheet->freezePane('A3');
}

function dcrExcelClearRowValues($sheet, int $row, int $columnCount): void
{
    for ($i = 1; $i <= $columnCount; $i++) {
        $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i) . $row, '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }
}

function dcrExcelCopyTemplateRow($sheet, int $sourceRow, int $targetRow, int $columnCount): void
{
    $start = 'A';
    $end = Coordinate::stringFromColumnIndex($columnCount);
    $sheet->duplicateStyle($sheet->getStyle($start . $sourceRow . ':' . $end . $sourceRow), $start . $targetRow . ':' . $end . $targetRow);
    $sheet->getRowDimension($targetRow)->setRowHeight($sheet->getRowDimension($sourceRow)->getRowHeight());
    dcrExcelClearRowValues($sheet, $targetRow, $columnCount);
}

function dcrExcelEnsureTemplateCapacity($sheet, int $dataStartRow, int $templateDataEndRow, ?int $summaryStartRow, int $requiredRows, int $columnCount): array
{
    $baseRows = max(0, $templateDataEndRow - $dataStartRow + 1);
    $neededRows = max($requiredRows, 0);
    $additionalRows = max(0, $neededRows - $baseRows);
    $adjustedSummaryRow = $summaryStartRow;

    if ($additionalRows > 0) {
        $insertAt = $summaryStartRow ?? ($templateDataEndRow + 1);
        $sheet->insertNewRowBefore($insertAt, $additionalRows);
        for ($row = 0; $row < $additionalRows; $row++) {
            dcrExcelCopyTemplateRow($sheet, $templateDataEndRow, $templateDataEndRow + 1 + $row, $columnCount);
        }
        if ($summaryStartRow !== null) {
            $adjustedSummaryRow += $additionalRows;
        }
    }

    $dataEndRow = $dataStartRow + max($baseRows, $neededRows) - 1;

    return [
        'data_end_row' => $dataEndRow,
        'summary_row' => $adjustedSummaryRow,
    ];
}

function dcrGenerateTemplateExcelContent(
    array $reportData,
    string $facilityDisplayName,
    string $formReportType,
    string $monthYear,
    string $formDate,
    string $formToDate,
    $formFacility
): string {
    $templatePath = __DIR__ . '/templates/dcr_email_template.xlsx';
    if (!is_file($templatePath)) {
        return '';
    }

    try {
        $spreadsheet = IOFactory::load($templatePath);
    } catch (Throwable $e) {
        error_log('DCR template load failed: ' . $e->getMessage());
        return '';
    }

    $periodLabel = dcrComputeWorkbookPeriodLabel($formReportType, $monthYear, $formDate, $formToDate);
    $dailyBreakdown = $reportData['daily_breakdown'] ?? ['rows' => [], 'totals' => [], 'averages' => []];
    $monthlyGrid = $reportData['monthly_revenue_grid'] ?? ['rows' => [], 'totals' => []];
    $shotTracker = $reportData['shot_tracker'] ?? ['rows' => [], 'totals' => []];
    $depositSheetData = dcrBuildDepositSheetData($formDate, $formToDate, $formFacility);

    $patientSheet = $spreadsheet->getSheetByName('Patient DCR');
    if (!$patientSheet) {
        $patientSheet = $spreadsheet->createSheet(0);
        $patientSheet->setTitle('Patient DCR');
    }
    $grossSheet = $spreadsheet->getSheetByName('Gross Pat. Count');
    $revenueSheet = $spreadsheet->getSheetByName('Revenue Breakdown ');
    if (!$revenueSheet) {
        $revenueSheet = $spreadsheet->getSheetByName('Revenue Breakdown');
    }
    $depositSheet = $spreadsheet->getSheetByName('Deposit');
    $shotSheet = $spreadsheet->getSheetByName('Shot tracker');

    if (!$patientSheet || !$grossSheet || !$revenueSheet || !$depositSheet || !$shotSheet) {
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        return '';
    }

    dcrPopulatePatientDcrTemplateSheet($patientSheet, $reportData, $facilityDisplayName);
    // Rebuild the gross count sheet so the emailed workbook matches the live
    // DCR layout, including the newer Rtn Pts columns for each medicine group.
    $grossSheetIndex = $spreadsheet->getIndex($grossSheet);
    dcrBuildActualGrossPatientCountSheet($spreadsheet, $grossSheetIndex, $dailyBreakdown);

    $revenueSheet->setCellValue('A1', strtoupper($facilityDisplayName));
    $revenueSheet->setCellValue('H1', $periodLabel);
    $revenueMeta = dcrExcelEnsureTemplateCapacity($revenueSheet, 4, 21, null, count($monthlyGrid['rows'] ?? []), 13);
    for ($row = 4; $row <= $revenueMeta['data_end_row']; $row++) {
        dcrExcelClearRowValues($revenueSheet, $row, 13);
    }
    $revenueSheet->setCellValue('B3', "Running\nTotals");
    $revenueSheet->setCellValue('C3', dcrExcelValueOrBlank($monthlyGrid['totals']['office_visit'] ?? 0, 2));
    $revenueSheet->setCellValue('D3', dcrExcelValueOrBlank($monthlyGrid['totals']['lab'] ?? 0, 2));
    $revenueSheet->setCellValue('E3', dcrExcelValueOrBlank($monthlyGrid['totals']['lipo'] ?? 0, 2));
    $revenueSheet->setCellValue('F3', dcrExcelValueOrBlank($monthlyGrid['totals']['sema'] ?? 0, 2));
    $revenueSheet->setCellValue('G3', dcrExcelValueOrBlank($monthlyGrid['totals']['tirz'] ?? 0, 2));
    $revenueSheet->setCellValue('H3', dcrExcelValueOrBlank($monthlyGrid['totals']['testosterone'] ?? 0, 2));
    $revenueSheet->setCellValue('I3', dcrExcelValueOrBlank($monthlyGrid['totals']['afterpay'] ?? 0, 2));
    $revenueSheet->setCellValue('J3', dcrExcelValueOrBlank($monthlyGrid['totals']['supplements'] ?? 0, 2));
    $revenueSheet->setCellValue('K3', dcrExcelValueOrBlank($monthlyGrid['totals']['protein'] ?? 0, 2));
    $revenueSheet->setCellValue('L3', dcrExcelValueOrBlank($monthlyGrid['totals']['taxes'] ?? 0, 2));
    $revenueSheet->setCellValue('M3', dcrExcelValueOrBlank($monthlyGrid['totals']['total'] ?? 0, 2));
    $revenueRow = 4;
    foreach ($monthlyGrid['rows'] ?? [] as $row) {
        $revenueSheet->setCellValue('A' . $revenueRow, $row['date_label'] ?? '');
        $revenueSheet->setCellValue('B' . $revenueRow, 'TOTAL');
        $revenueSheet->setCellValue('C' . $revenueRow, dcrExcelValueOrBlank($row['office_visit'] ?? 0, 2));
        $revenueSheet->setCellValue('D' . $revenueRow, dcrExcelValueOrBlank($row['lab'] ?? 0, 2));
        $revenueSheet->setCellValue('E' . $revenueRow, dcrExcelValueOrBlank($row['lipo'] ?? 0, 2));
        $revenueSheet->setCellValue('F' . $revenueRow, dcrExcelValueOrBlank($row['sema'] ?? 0, 2));
        $revenueSheet->setCellValue('G' . $revenueRow, dcrExcelValueOrBlank($row['tirz'] ?? 0, 2));
        $revenueSheet->setCellValue('H' . $revenueRow, dcrExcelValueOrBlank($row['testosterone'] ?? 0, 2));
        $revenueSheet->setCellValue('I' . $revenueRow, dcrExcelValueOrBlank($row['afterpay'] ?? 0, 2));
        $revenueSheet->setCellValue('J' . $revenueRow, dcrExcelValueOrBlank($row['supplements'] ?? 0, 2));
        $revenueSheet->setCellValue('K' . $revenueRow, dcrExcelValueOrBlank($row['protein'] ?? 0, 2));
        $revenueSheet->setCellValue('L' . $revenueRow, dcrExcelValueOrBlank($row['taxes'] ?? 0, 2));
        $revenueSheet->setCellValue('M' . $revenueRow, dcrExcelValueOrBlank($row['total'] ?? 0, 2));
        $revenueRow++;
    }
    dcrExcelFinalizeSheetLayout($revenueSheet, 'A1:M' . max($revenueRow - 1, 3), 13, ['A' => 16, 'B' => 14]);

    $depositSheet->setCellValue('A1', '  ');
    $depositSheet->setCellValue('E1', $periodLabel);
    $depositMeta = dcrExcelEnsureTemplateCapacity($depositSheet, 3, 20, 21, count($depositSheetData['rows'] ?? []), 9);
    for ($row = 3; $row <= $depositMeta['data_end_row']; $row++) {
        dcrExcelClearRowValues($depositSheet, $row, 9);
    }
    $depositRow = 3;
    foreach ($depositSheetData['rows'] as $row) {
        $dateObj = DateTime::createFromFormat('Y-m-d', (string) ($row['date'] ?? ''));
        $displayDate = $dateObj ? $dateObj->format('m/d/Y') : (string) ($row['date'] ?? '');
        $depositSheet->setCellValue('A' . $depositRow, $row['facility_name'] ?? '');
        $depositSheet->setCellValue('B' . $depositRow, $displayDate);
        $depositSheet->setCellValue('C' . $depositRow, dcrExcelValueOrBlank($row['cash'] ?? 0, 2));
        $depositSheet->setCellValue('D' . $depositRow, dcrExcelValueOrBlank($row['checks'] ?? 0, 2));
        $depositSheet->setCellValue('E' . $depositRow, dcrExcelValueOrBlank($row['actual_bank_deposit'] ?? 0, 2));
        $depositSheet->setCellValue('F' . $depositRow, dcrExcelValueOrBlank($row['total_deposit'] ?? 0, 2));
        $depositSheet->setCellValue('G' . $depositRow, dcrExcelValueOrBlank($row['credit'] ?? 0, 2));
        $depositSheet->setCellValue('H' . $depositRow, dcrExcelValueOrBlank($row['subtotal_credit_checks'] ?? 0, 2));
        $depositSheet->setCellValue('I' . $depositRow, dcrExcelValueOrBlank($row['total_revenue'] ?? 0, 2));
        $depositRow++;
    }
    $depositTotalRow = (int) ($depositMeta['summary_row'] ?? 21);
    dcrExcelClearRowValues($depositSheet, $depositTotalRow, 9);
    $depositSheet->setCellValue('A' . $depositTotalRow, 'Total');
    $depositSheet->setCellValue('C' . $depositTotalRow, dcrExcelValueOrBlank($depositSheetData['totals']['cash'] ?? 0, 2));
    $depositSheet->setCellValue('D' . $depositTotalRow, dcrExcelValueOrBlank($depositSheetData['totals']['checks'] ?? 0, 2));
    $depositSheet->setCellValue('E' . $depositTotalRow, dcrExcelValueOrBlank($depositSheetData['totals']['actual_bank_deposit'] ?? 0, 2));
    $depositSheet->setCellValue('F' . $depositTotalRow, dcrExcelValueOrBlank($depositSheetData['totals']['total_deposit'] ?? 0, 2));
    $depositSheet->setCellValue('G' . $depositTotalRow, dcrExcelValueOrBlank($depositSheetData['totals']['credit'] ?? 0, 2));
    $depositSheet->setCellValue('H' . $depositTotalRow, dcrExcelValueOrBlank($depositSheetData['totals']['subtotal_credit_checks'] ?? 0, 2));
    $depositSheet->setCellValue('I' . $depositTotalRow, dcrExcelValueOrBlank($depositSheetData['totals']['total_revenue'] ?? 0, 2));
    dcrExcelFinalizeSheetLayout($depositSheet, 'A1:I' . $depositTotalRow, 9, ['A' => 18, 'B' => 16]);

    $shotSheetIndex = $spreadsheet->getIndex($shotSheet);
    dcrBuildActualShotTrackerSheet($spreadsheet, $shotSheetIndex, $facilityDisplayName, $periodLabel, $shotTracker);

    $spreadsheet->setActiveSheetIndex(0);
    $writer = new Xlsx($spreadsheet);
    $writer->setPreCalculateFormulas(true);
    ob_start();
    $writer->save('php://output');
    $content = ob_get_clean();
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

    return is_string($content) ? $content : '';
}

function dcrPopulatePatientDcrTemplateSheet($sheet, array $reportData, string $facilityDisplayName): void
{
    $blankTakeHomeColumns = shouldBlankTakeHomeColumns($_REQUEST['form_facility'] ?? '', $facilityDisplayName);
    $patientRows = $reportData['patient_rows'] ?? [];
    $meta = dcrExcelEnsureTemplateCapacity($sheet, 5, 6, 7, count($patientRows), 29);
    $dataEndRow = (int) ($meta['data_end_row'] ?? 6);
    $summaryRow = (int) ($meta['summary_row'] ?? 7);

    for ($row = 5; $row <= $dataEndRow; $row++) {
        dcrExcelClearRowValues($sheet, $row, 29);
    }
    dcrExcelClearRowValues($sheet, $summaryRow, 29);

    $totals = [
        'ov' => 0.0, 'bw' => 0.0, 'lipo_amount' => 0.0, 'lipo_purchased' => 0.0, 'lipo_home' => 0.0,
        'sema_amount' => 0.0, 'sema_purchased' => 0.0, 'sema_home' => 0.0,
        'tirz_amount' => 0.0, 'tirz_purchased' => 0.0, 'tirz_home' => 0.0,
        'afterpay' => 0.0, 'supp_amount' => 0.0, 'supp_count' => 0.0,
        'drink_amount' => 0.0, 'drink_count' => 0.0, 'subtotal' => 0.0,
        'tax' => 0.0, 'credit' => 0.0, 'total' => 0.0
    ];
    $groupTotalsActivity = [
        'lipo' => false,
        'sema' => false,
        'tirz' => false,
        'afterpay' => false,
        'supplements' => false,
        'protein' => false,
    ];

    $rowNum = 5;
    foreach ($patientRows as $patient) {
        $details = $patient['details'] ?? initializePatientDetails();
        $netAmounts = getPatientRevenueGridAmounts($details);
        $netSubtotal = getPatientRevenueGridSubtotal($details, $netAmounts);
        $lipoCardPunchDisplay = resolvePatientCardPunchDisplay($details, 'lipo', 5);
        $semaCardPunchDisplay = resolvePatientCardPunchDisplay($details, 'sema', 4);
        $tirzCardPunchDisplay = resolvePatientCardPunchDisplay($details, 'tirz', 4);
        $lipoGroupActive = dcrPatientGroupHasActivity($details, $netAmounts, 'lipo', $lipoCardPunchDisplay);
        $semaGroupActive = dcrPatientGroupHasActivity($details, $netAmounts, 'sema', $semaCardPunchDisplay);
        $tirzGroupActive = dcrPatientGroupHasActivity($details, $netAmounts, 'tirz', $tirzCardPunchDisplay);
        $afterpayGroupActive = dcrPatientGroupHasActivity($details, $netAmounts, 'afterpay');
        $supplementsGroupActive = dcrPatientGroupHasActivity($details, $netAmounts, 'supplements');
        $proteinGroupActive = dcrPatientGroupHasActivity($details, $netAmounts, 'protein');
        $semaDose = !empty($details['sema_doses']) ? implode(', ', $details['sema_doses']) : '';
        $tirzDose = !empty($details['tirz_doses']) ? implode(', ', $details['tirz_doses']) : '';
        $lipoTakeHome = $blankTakeHomeColumns ? '' : dcrExcelValueForGroup($lipoGroupActive, $details['lipo_takehome_count'] ?? 0);
        $semaTakeHome = $blankTakeHomeColumns ? '' : dcrExcelValueForGroup($semaGroupActive, $details['sema_takehome_count'] ?? 0);
        $tirzTakeHome = $blankTakeHomeColumns ? '' : dcrExcelValueForGroup($tirzGroupActive, $details['tirz_takehome_count'] ?? 0);
        $totalAmount = isset($details['total_amount']) ? (float) $details['total_amount'] : (float) ($patient['total_spent'] ?? 0);

        $groupTotalsActivity['lipo'] = $groupTotalsActivity['lipo'] || $lipoGroupActive;
        $groupTotalsActivity['sema'] = $groupTotalsActivity['sema'] || $semaGroupActive;
        $groupTotalsActivity['tirz'] = $groupTotalsActivity['tirz'] || $tirzGroupActive;
        $groupTotalsActivity['afterpay'] = $groupTotalsActivity['afterpay'] || $afterpayGroupActive;
        $groupTotalsActivity['supplements'] = $groupTotalsActivity['supplements'] || $supplementsGroupActive;
        $groupTotalsActivity['protein'] = $groupTotalsActivity['protein'] || $proteinGroupActive;

        $totals['ov'] += (float) ($netAmounts['office_visit'] ?? 0);
        $totals['bw'] += (float) ($netAmounts['lab'] ?? 0);
        $totals['lipo_amount'] += (float) ($netAmounts['lipo'] ?? 0);
        $totals['lipo_purchased'] += (float) ($details['lipo_purchased_count'] ?? 0);
        $totals['lipo_home'] += $blankTakeHomeColumns ? 0 : (float) ($details['lipo_takehome_count'] ?? 0);
        $totals['sema_amount'] += (float) ($netAmounts['sema'] ?? 0);
        $totals['sema_purchased'] += (float) ($details['sema_purchased_count'] ?? 0);
        $totals['sema_home'] += $blankTakeHomeColumns ? 0 : (float) ($details['sema_takehome_count'] ?? 0);
        $totals['tirz_amount'] += (float) ($netAmounts['tirz'] ?? 0);
        $totals['tirz_purchased'] += (float) ($details['tirz_purchased_count'] ?? 0);
        $totals['tirz_home'] += $blankTakeHomeColumns ? 0 : (float) ($details['tirz_takehome_count'] ?? 0);
        $totals['afterpay'] += (float) ($netAmounts['afterpay'] ?? 0);
        $totals['supp_amount'] += (float) ($netAmounts['supplements'] ?? 0);
        $totals['supp_count'] += (float) ($details['supplement_count'] ?? 0);
        $totals['drink_amount'] += (float) ($netAmounts['protein'] ?? 0);
        $totals['drink_count'] += (float) ($details['drink_count'] ?? 0);
        $totals['subtotal'] += $netSubtotal;
        $totals['tax'] += (float) ($details['tax_amount'] ?? 0);
        $totals['credit'] += (float) ($details['credit_amount'] ?? 0);
        $totals['total'] += $totalAmount;

        $values = [
            $patient['order_number'] ?? '',
            $patient['name'] ?? '',
            $details['visit_type'] ?? '-',
            dcrExcelValueOrBlank($netAmounts['office_visit'] ?? 0, 2),
            dcrExcelValueOrBlank($netAmounts['lab'] ?? 0, 2),
            dcrExcelDisplayForGroup($lipoGroupActive, $lipoCardPunchDisplay, 0),
            $lipoTakeHome,
            dcrExcelValueForGroup($lipoGroupActive, $netAmounts['lipo'] ?? 0, 2),
            dcrExcelValueForGroup($lipoGroupActive, $details['lipo_purchased_count'] ?? 0),
            dcrExcelDisplayForGroup($semaGroupActive, $semaCardPunchDisplay, 0),
            $semaTakeHome,
            dcrExcelValueForGroup($semaGroupActive, $netAmounts['sema'] ?? 0, 2),
            dcrExcelValueForGroup($semaGroupActive, $details['sema_purchased_count'] ?? 0),
            $semaGroupActive ? $semaDose : '',
            dcrExcelDisplayForGroup($tirzGroupActive, $tirzCardPunchDisplay, 0),
            $tirzTakeHome,
            dcrExcelValueForGroup($tirzGroupActive, $netAmounts['tirz'] ?? 0, 2),
            dcrExcelValueForGroup($tirzGroupActive, $details['tirz_purchased_count'] ?? 0),
            $tirzGroupActive ? $tirzDose : '',
            dcrExcelValueForGroup($afterpayGroupActive, $netAmounts['afterpay'] ?? 0, 2),
            dcrExcelValueForGroup($supplementsGroupActive, $netAmounts['supplements'] ?? 0, 2),
            dcrExcelValueForGroup($supplementsGroupActive, $details['supplement_count'] ?? 0),
            dcrExcelValueForGroup($proteinGroupActive, $netAmounts['protein'] ?? 0, 2),
            dcrExcelValueForGroup($proteinGroupActive, $details['drink_count'] ?? 0),
            dcrExcelValueOrBlank($netSubtotal, 2),
            dcrExcelValueOrBlank($details['tax_amount'] ?? 0, 2),
            dcrExcelValueOrBlank($details['credit_amount'] ?? 0, 2),
            dcrExcelValueOrBlank($totalAmount, 2),
            buildDcrPatientGridNotes($details, $netAmounts)
        ];

        foreach ($values as $index => $value) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($column . $rowNum, $value);
        }
        $rowNum++;
    }

    if (empty($patientRows)) {
        $sheet->mergeCells('A5:AC5');
        $sheet->setCellValue('A5', 'No patient DCR data available for the selected filters.');
        dcrExcelApplyRangeStyle($sheet, 'A5:AC5', 'FFFEFFFF', false, Alignment::HORIZONTAL_CENTER);
    }

    $totalValues = [
        'Totals', '', '',
        dcrExcelValueOrBlank($totals['ov'], 2), dcrExcelValueOrBlank($totals['bw'], 2), '', dcrExcelValueForGroup($groupTotalsActivity['lipo'], $totals['lipo_home']), dcrExcelValueForGroup($groupTotalsActivity['lipo'], $totals['lipo_amount'], 2), dcrExcelValueForGroup($groupTotalsActivity['lipo'], $totals['lipo_purchased']),
        '', dcrExcelValueForGroup($groupTotalsActivity['sema'], $totals['sema_home']), dcrExcelValueForGroup($groupTotalsActivity['sema'], $totals['sema_amount'], 2), dcrExcelValueForGroup($groupTotalsActivity['sema'], $totals['sema_purchased']), '',
        '', dcrExcelValueForGroup($groupTotalsActivity['tirz'], $totals['tirz_home']), dcrExcelValueForGroup($groupTotalsActivity['tirz'], $totals['tirz_amount'], 2), dcrExcelValueForGroup($groupTotalsActivity['tirz'], $totals['tirz_purchased']), '',
        dcrExcelValueForGroup($groupTotalsActivity['afterpay'], $totals['afterpay'], 2), dcrExcelValueForGroup($groupTotalsActivity['supplements'], $totals['supp_amount'], 2), dcrExcelValueForGroup($groupTotalsActivity['supplements'], $totals['supp_count']), dcrExcelValueForGroup($groupTotalsActivity['protein'], $totals['drink_amount'], 2), dcrExcelValueForGroup($groupTotalsActivity['protein'], $totals['drink_count']),
        dcrExcelValueOrBlank($totals['subtotal'], 2), dcrExcelValueOrBlank($totals['tax'], 2), dcrExcelValueOrBlank($totals['credit'], 2), dcrExcelValueOrBlank($totals['total'], 2), ''
    ];
    foreach ($totalValues as $index => $value) {
        $column = Coordinate::stringFromColumnIndex($index + 1);
        $sheet->setCellValue($column . $summaryRow, $value);
    }

    $sheet->setCellValue('F1', strtoupper($facilityDisplayName));
    $sheet->setCellValue('A2', strtoupper($facilityDisplayName));
    $sheet->setCellValue('J2', 'PATIENT DCR');
    $sheet->setCellValue('M1', "SG Revenue\n$" . number_format((float) $totals['sema_amount'], 2));
    $sheet->setCellValue('S1', "Total REV\n$" . number_format((float) $totals['total'], 2));

    dcrExcelFormatCurrencyColumns($sheet, ['D', 'E', 'H', 'L', 'Q', 'T', 'U', 'W', 'Y', 'Z', 'AA', 'AB'], 5, max($summaryRow, 5));
    dcrExcelFormatIntegerColumns($sheet, ['A', 'F', 'G', 'I', 'J', 'K', 'M', 'O', 'P', 'R', 'V', 'X'], 5, max($summaryRow, 5));
    dcrExcelFormatTextColumns($sheet, ['B', 'C', 'N', 'S', 'AC'], 5, max($summaryRow, 5));
    dcrExcelFinalizeSheetLayout($sheet, 'A1:AC' . max($summaryRow, 5), 29, ['B' => 24, 'N' => 14, 'S' => 14, 'AC' => 30]);
}

function dcrPopulatePatientDcrWorkbookSheet($sheet, array $reportData, string $facilityDisplayName): void
{
    $blankTakeHomeColumns = shouldBlankTakeHomeColumns($_REQUEST['form_facility'] ?? '', $facilityDisplayName);
    $headers = [
        'Sign In #', 'Patient Name', 'Action', 'OV', 'BW',
        'B12 Lipo - Card Punch #', 'B12 Lipo - TH', 'B12 Lipo - Amount', 'B12 Lipo - #',
        'Semaglutide - Card Punch #', 'Semaglutide - TH', 'Sema Injection', 'Semaglutide - #', 'Sema DOSE (mg)',
        'Tirzepatide - Card Punch #', 'Tirzepatide - TH', 'TRZ Injection', 'Tirzepatide - #', 'TRZ DOSE (mg)',
        'AfterPay Fee', 'Supplements - Amount', 'Supplements - #', 'Protein Shakes - Amount', 'Protein Shakes - #',
        'Subtotal', 'Tax', 'Credits', 'Total', 'Notes'
    ];

    $sheet->setCellValue('A1', strtoupper($facilityDisplayName));
    $sheet->mergeCells('A1:E1');
    $sheet->setCellValue('F1', 'PATIENT DCR');
    $sheet->mergeCells('F1:AC1');
    dcrExcelApplyRangeStyle($sheet, 'A1:E1', 'FFFEFFFF', true, Alignment::HORIZONTAL_LEFT, false, 12);
    dcrExcelApplyRangeStyle($sheet, 'F1:AC1', 'FFFEFFFF', true, Alignment::HORIZONTAL_CENTER, false, 12);

    foreach ($headers as $index => $header) {
        $column = Coordinate::stringFromColumnIndex($index + 1);
        $sheet->setCellValue($column . '2', $header);
        dcrExcelApplyRangeStyle($sheet, $column . '2', 'FFFEFFFF', true, Alignment::HORIZONTAL_CENTER, true, 10);
    }

    $totals = [
        'ov' => 0.0, 'bw' => 0.0, 'lipo_amount' => 0.0, 'lipo_purchased' => 0.0, 'lipo_home' => 0.0,
        'sema_amount' => 0.0, 'sema_purchased' => 0.0, 'sema_home' => 0.0,
        'tirz_amount' => 0.0, 'tirz_purchased' => 0.0, 'tirz_home' => 0.0,
        'afterpay' => 0.0, 'supp_amount' => 0.0, 'supp_count' => 0.0,
        'drink_amount' => 0.0, 'drink_count' => 0.0, 'subtotal' => 0.0,
        'tax' => 0.0, 'credit' => 0.0, 'total' => 0.0
    ];
    $groupTotalsActivity = [
        'lipo' => false,
        'sema' => false,
        'tirz' => false,
        'afterpay' => false,
        'supplements' => false,
        'protein' => false,
    ];

    $rowNum = 3;
    foreach ($reportData['patient_rows'] ?? [] as $patient) {
        $details = $patient['details'] ?? initializePatientDetails();
        $netAmounts = getPatientRevenueGridAmounts($details);
        $netSubtotal = getPatientRevenueGridSubtotal($details, $netAmounts);
        $lipoCardPunchDisplay = resolvePatientCardPunchDisplay($details, 'lipo', 5);
        $semaCardPunchDisplay = resolvePatientCardPunchDisplay($details, 'sema', 4);
        $tirzCardPunchDisplay = resolvePatientCardPunchDisplay($details, 'tirz', 4);
        $lipoGroupActive = dcrPatientGroupHasActivity($details, $netAmounts, 'lipo', $lipoCardPunchDisplay);
        $semaGroupActive = dcrPatientGroupHasActivity($details, $netAmounts, 'sema', $semaCardPunchDisplay);
        $tirzGroupActive = dcrPatientGroupHasActivity($details, $netAmounts, 'tirz', $tirzCardPunchDisplay);
        $afterpayGroupActive = dcrPatientGroupHasActivity($details, $netAmounts, 'afterpay');
        $supplementsGroupActive = dcrPatientGroupHasActivity($details, $netAmounts, 'supplements');
        $proteinGroupActive = dcrPatientGroupHasActivity($details, $netAmounts, 'protein');
        $semaDose = !empty($details['sema_doses']) ? implode(', ', $details['sema_doses']) : '';
        $tirzDose = !empty($details['tirz_doses']) ? implode(', ', $details['tirz_doses']) : '';
        $lipoTakeHome = $blankTakeHomeColumns ? '' : dcrExcelValueForGroup($lipoGroupActive, $details['lipo_takehome_count'] ?? 0);
        $semaTakeHome = $blankTakeHomeColumns ? '' : dcrExcelValueForGroup($semaGroupActive, $details['sema_takehome_count'] ?? 0);
        $tirzTakeHome = $blankTakeHomeColumns ? '' : dcrExcelValueForGroup($tirzGroupActive, $details['tirz_takehome_count'] ?? 0);
        $totalAmount = isset($details['total_amount']) ? (float) $details['total_amount'] : (float) ($patient['total_spent'] ?? 0);

        $groupTotalsActivity['lipo'] = $groupTotalsActivity['lipo'] || $lipoGroupActive;
        $groupTotalsActivity['sema'] = $groupTotalsActivity['sema'] || $semaGroupActive;
        $groupTotalsActivity['tirz'] = $groupTotalsActivity['tirz'] || $tirzGroupActive;
        $groupTotalsActivity['afterpay'] = $groupTotalsActivity['afterpay'] || $afterpayGroupActive;
        $groupTotalsActivity['supplements'] = $groupTotalsActivity['supplements'] || $supplementsGroupActive;
        $groupTotalsActivity['protein'] = $groupTotalsActivity['protein'] || $proteinGroupActive;

        $totals['ov'] += (float) ($netAmounts['office_visit'] ?? 0);
        $totals['bw'] += (float) ($netAmounts['lab'] ?? 0);
        $totals['lipo_amount'] += (float) ($netAmounts['lipo'] ?? 0);
        $totals['lipo_purchased'] += (float) ($details['lipo_purchased_count'] ?? 0);
        $totals['lipo_home'] += $blankTakeHomeColumns ? 0 : (float) ($details['lipo_takehome_count'] ?? 0);
        $totals['sema_amount'] += (float) ($netAmounts['sema'] ?? 0);
        $totals['sema_purchased'] += (float) ($details['sema_purchased_count'] ?? 0);
        $totals['sema_home'] += $blankTakeHomeColumns ? 0 : (float) ($details['sema_takehome_count'] ?? 0);
        $totals['tirz_amount'] += (float) ($netAmounts['tirz'] ?? 0);
        $totals['tirz_purchased'] += (float) ($details['tirz_purchased_count'] ?? 0);
        $totals['tirz_home'] += $blankTakeHomeColumns ? 0 : (float) ($details['tirz_takehome_count'] ?? 0);
        $totals['afterpay'] += (float) ($netAmounts['afterpay'] ?? 0);
        $totals['supp_amount'] += (float) ($netAmounts['supplements'] ?? 0);
        $totals['supp_count'] += (float) ($details['supplement_count'] ?? 0);
        $totals['drink_amount'] += (float) ($netAmounts['protein'] ?? 0);
        $totals['drink_count'] += (float) ($details['drink_count'] ?? 0);
        $totals['subtotal'] += $netSubtotal;
        $totals['tax'] += (float) ($details['tax_amount'] ?? 0);
        $totals['credit'] += (float) ($details['credit_amount'] ?? 0);
        $totals['total'] += $totalAmount;

        $values = [
            $patient['order_number'] ?? '',
            $patient['name'] ?? '',
            $details['visit_type'] ?? '-',
            dcrExcelValueOrBlank($netAmounts['office_visit'] ?? 0),
            dcrExcelValueOrBlank($netAmounts['lab'] ?? 0),
            dcrExcelDisplayForGroup($lipoGroupActive, $lipoCardPunchDisplay, 0),
            $lipoTakeHome,
            dcrExcelValueForGroup($lipoGroupActive, $netAmounts['lipo'] ?? 0),
            dcrExcelValueForGroup($lipoGroupActive, $details['lipo_purchased_count'] ?? 0),
            dcrExcelDisplayForGroup($semaGroupActive, $semaCardPunchDisplay, 0),
            $semaTakeHome,
            dcrExcelValueForGroup($semaGroupActive, $netAmounts['sema'] ?? 0),
            dcrExcelValueForGroup($semaGroupActive, $details['sema_purchased_count'] ?? 0),
            $semaGroupActive ? $semaDose : '',
            dcrExcelDisplayForGroup($tirzGroupActive, $tirzCardPunchDisplay, 0),
            $tirzTakeHome,
            dcrExcelValueForGroup($tirzGroupActive, $netAmounts['tirz'] ?? 0),
            dcrExcelValueForGroup($tirzGroupActive, $details['tirz_purchased_count'] ?? 0),
            $tirzGroupActive ? $tirzDose : '',
            dcrExcelValueForGroup($afterpayGroupActive, $netAmounts['afterpay'] ?? 0),
            dcrExcelValueForGroup($supplementsGroupActive, $netAmounts['supplements'] ?? 0),
            dcrExcelValueForGroup($supplementsGroupActive, $details['supplement_count'] ?? 0),
            dcrExcelValueForGroup($proteinGroupActive, $netAmounts['protein'] ?? 0),
            dcrExcelValueForGroup($proteinGroupActive, $details['drink_count'] ?? 0),
            dcrExcelValueOrBlank($netSubtotal),
            dcrExcelValueOrBlank($details['tax_amount'] ?? 0),
            dcrExcelValueOrBlank($details['credit_amount'] ?? 0),
            dcrExcelValueOrBlank($totalAmount),
            buildDcrPatientGridNotes($details, $netAmounts)
        ];

        foreach ($values as $index => $value) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($column . $rowNum, $value);
        }
        dcrExcelApplyRangeStyle($sheet, 'A' . $rowNum . ':AC' . $rowNum, 'FFFEFFFF');
        $rowNum++;
    }

    if ($rowNum === 3) {
        $sheet->mergeCells('A3:AC3');
        $sheet->setCellValue('A3', 'No patient DCR data available for the selected filters.');
        dcrExcelApplyRangeStyle($sheet, 'A3:AC3', 'FFFEFFFF', false, Alignment::HORIZONTAL_CENTER);
        $rowNum = 4;
    } else {
        $totalValues = [
            'Totals', '', '',
            dcrExcelValueOrBlank($totals['ov']), dcrExcelValueOrBlank($totals['bw']), '', dcrExcelValueForGroup($groupTotalsActivity['lipo'], $totals['lipo_home']), dcrExcelValueForGroup($groupTotalsActivity['lipo'], $totals['lipo_amount']), dcrExcelValueForGroup($groupTotalsActivity['lipo'], $totals['lipo_purchased']),
            '', dcrExcelValueForGroup($groupTotalsActivity['sema'], $totals['sema_home']), dcrExcelValueForGroup($groupTotalsActivity['sema'], $totals['sema_amount']), dcrExcelValueForGroup($groupTotalsActivity['sema'], $totals['sema_purchased']), '',
            '', dcrExcelValueForGroup($groupTotalsActivity['tirz'], $totals['tirz_home']), dcrExcelValueForGroup($groupTotalsActivity['tirz'], $totals['tirz_amount']), dcrExcelValueForGroup($groupTotalsActivity['tirz'], $totals['tirz_purchased']), '',
            dcrExcelValueForGroup($groupTotalsActivity['afterpay'], $totals['afterpay']), dcrExcelValueForGroup($groupTotalsActivity['supplements'], $totals['supp_amount']), dcrExcelValueForGroup($groupTotalsActivity['supplements'], $totals['supp_count']), dcrExcelValueForGroup($groupTotalsActivity['protein'], $totals['drink_amount']), dcrExcelValueForGroup($groupTotalsActivity['protein'], $totals['drink_count']),
            dcrExcelValueOrBlank($totals['subtotal']), dcrExcelValueOrBlank($totals['tax']), dcrExcelValueOrBlank($totals['credit']), dcrExcelValueOrBlank($totals['total']), ''
        ];
        foreach ($totalValues as $index => $value) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($column . $rowNum, $value);
        }
        dcrExcelApplyRangeStyle($sheet, 'A' . $rowNum . ':AC' . $rowNum, 'FFFEFFFF', true);
        $rowNum++;
    }

    dcrExcelFormatCurrencyColumns($sheet, ['D', 'E', 'H', 'L', 'Q', 'T', 'U', 'W', 'Y', 'Z', 'AA', 'AB'], 3, max($rowNum, 3));
    dcrExcelFormatIntegerColumns($sheet, ['A', 'F', 'G', 'I', 'J', 'K', 'M', 'O', 'P', 'R', 'V', 'X'], 3, max($rowNum, 3));
    dcrExcelFormatTextColumns($sheet, ['B', 'C', 'N', 'S', 'AC'], 3, max($rowNum, 3));
    $sheet->freezePane('A3');
    dcrExcelFinalizeSheetLayout($sheet, 'A1:AC' . max($rowNum, 3), 29, ['B' => 24, 'N' => 14, 'S' => 14, 'AC' => 30]);
}

function generateDcrExcelContent(
    array $reportData,
    string $facilityDisplayName,
    string $dateFormatted,
    string $toDateFormatted,
    string $formReportType,
    string $monthYear,
    string $formDate,
    string $formToDate,
    $formFacility
): string {
    $templateContent = dcrGenerateTemplateExcelContent(
        $reportData,
        $facilityDisplayName,
        $formReportType,
        $monthYear,
        $formDate,
        $formToDate,
        $formFacility
    );
    if ($templateContent !== '') {
        return $templateContent;
    }

    $workbook = new Spreadsheet();
    $workbook->getProperties()
        ->setCreator('JACtrac')
        ->setTitle('DCR Report')
        ->setSubject('Daily Collection Report')
        ->setDescription('Styled DCR workbook export');

    $periodLabel = strtoupper(dcrComputeWorkbookPeriodLabel($formReportType, $monthYear, $formDate, $formToDate));
    $dailyBreakdown = $reportData['daily_breakdown'] ?? ['rows' => [], 'totals' => [], 'averages' => []];
    $monthlyGrid = $reportData['monthly_revenue_grid'] ?? ['rows' => [], 'totals' => []];
    $shotTracker = $reportData['shot_tracker'] ?? ['rows' => [], 'totals' => []];
    $depositSheetData = dcrBuildDepositSheetData($formDate, $formToDate, $formFacility);

    $sheet = $workbook->getActiveSheet();
    $sheet->setTitle('Patient DCR');
    dcrPopulatePatientDcrWorkbookSheet($sheet, $reportData, $facilityDisplayName);

    $sheet = $workbook->createSheet();
    $sheet->setTitle('Gross Pat. Count');
    $sheet->mergeCells('A1:H1');
    $sheet->mergeCells('J1:N1');
    $sheet->setCellValue('A1', strtoupper($facilityDisplayName));
    $sheet->setCellValue('J1', $periodLabel);
    dcrExcelApplyRangeStyle($sheet, 'A1:H1', 'FFFEFFFF', true, Alignment::HORIZONTAL_LEFT, false, 12);
    dcrExcelApplyRangeStyle($sheet, 'J1:N1', 'FFFEFFFF', true, Alignment::HORIZONTAL_CENTER, false, 12);
    $sheet->getStyle('J1:N1')->getNumberFormat()->setFormatCode('MMM YYYY');

    $grossMergedHeaders = [
        'D3:E3' => ['SEMAGLUTIDE', 'FFF9CB9C'],
        'G3:H3' => ['SEMAGLUTIDE', 'FFF9CB9C'],
        'J3:K3' => ['SG TOTAL', 'FFF9CB9C'],
        'M3:N3' => ['TIRZEPATIDE', 'FFF4CCCC'],
        'P3:Q3' => ['TIRZEPATIDE', 'FFF4CCCC'],
        'S3:T3' => ['TRZ TOTAL', 'FFF4CCCC'],
        'V3:W3' => ['PILLS', 'FF9FC5E8'],
        'Y3:Z3' => ['PILLS', 'FF9FC5E8'],
        'AB3:AC3' => ['PILLS TOTAL', 'FF9FC5E8'],
    ];
    foreach ($grossMergedHeaders as $range => [$label, $fill]) {
        $sheet->mergeCells($range);
        $sheet->setCellValue(explode(':', $range)[0], $label);
        dcrExcelApplyRangeStyle($sheet, $range, $fill, true, Alignment::HORIZONTAL_CENTER, false, 11);
    }
    $grossSingleHeaders = [
        'A3' => ['Day of week & date', 'FFFEFFFF'],
        'B3' => ['', 'FFAAAAAA'],
        'C3' => ['', 'FFA6A6A6'],
        'F3' => ['', 'FFAAAAAA'],
        'I3' => ['', 'FFAAAAAA'],
        'L3' => ['', 'FFAAAAAA'],
        'O3' => ['', 'FFAAAAAA'],
        'R3' => ['', 'FFAAAAAA'],
        'U3' => ['', 'FFAAAAAA'],
        'X3' => ['', 'FFAAAAAA'],
        'AA3' => ['', 'FFAAAAAA'],
        'AD3' => ['All Other', 'FFFFF2CC'],
        'AE3' => ['', 'FFAAAAAA'],
        'AF3' => ['TOTAL', 'FFFFF2CC'],
    ];
    foreach ($grossSingleHeaders as $cell => [$label, $fill]) {
        $sheet->setCellValue($cell, $label);
        dcrExcelApplyRangeStyle($sheet, $cell, $fill, true, Alignment::HORIZONTAL_CENTER, true, 11);
    }
    $grossSubHeaders = [
        'D4' => 'NEW PTS', 'E4' => 'REVENUE',
        'G4' => 'FU PTS', 'H4' => 'REVENUE',
        'J4' => 'PTS', 'K4' => ' REVENUE',
        'M4' => 'NEW PTS', 'N4' => 'REVENUE',
        'P4' => 'FU PTS', 'Q4' => 'REVENUE',
        'S4' => 'PTS', 'T4' => ' REVENUE',
        'V4' => 'NEW PTS', 'W4' => 'REVENUE',
        'Y4' => 'FU PTS', 'Z4' => 'REVENUE',
        'AB4' => 'PTS', 'AC4' => 'REVENUE',
        'AD4' => 'Revenue', 'AF4' => 'REVENUE',
    ];
    foreach ($grossSubHeaders as $cell => $label) {
        $sheet->setCellValue($cell, $label);
        dcrExcelApplyRangeStyle($sheet, $cell, 'FFFEFFFF', true, Alignment::HORIZONTAL_CENTER, true, 11);
    }
    foreach (['A4', 'B4', 'C4', 'F4', 'I4', 'L4', 'O4', 'R4', 'U4', 'X4', 'AA4', 'AE4'] as $cell) {
        $fill = in_array($cell, ['B4', 'C4', 'F4', 'I4', 'L4', 'O4', 'R4', 'U4', 'X4', 'AA4', 'AE4'], true) ? 'FFAAAAAA' : 'FFFEFFFF';
        if ($cell === 'C4') {
            $fill = 'FFA6A6A6';
        }
        $sheet->setCellValue($cell, '');
        dcrExcelApplyRangeStyle($sheet, $cell, $fill, false, Alignment::HORIZONTAL_CENTER, false, 11);
    }
    $grossRow = 5;
    foreach ($dailyBreakdown['rows'] ?? [] as $row) {
        $values = [
            $row['display_date'] ?? '',
            '',
            '',
            dcrExcelValueOrBlank($row['sema_new_pts'] ?? 0), dcrExcelValueOrBlank($row['sema_new_rev'] ?? 0), '',
            dcrExcelValueOrBlank($row['sema_fu_pts'] ?? 0), dcrExcelValueOrBlank($row['sema_fu_rev'] ?? 0), '',
            dcrExcelValueOrBlank($row['sg_total_pts'] ?? 0), dcrExcelValueOrBlank($row['sg_total_rev'] ?? 0), '',
            dcrExcelValueOrBlank($row['tirz_new_pts'] ?? 0), dcrExcelValueOrBlank($row['tirz_new_rev'] ?? 0), '',
            dcrExcelValueOrBlank($row['tirz_fu_pts'] ?? 0), dcrExcelValueOrBlank($row['tirz_fu_rev'] ?? 0), '',
            dcrExcelValueOrBlank($row['trz_total_pts'] ?? 0), dcrExcelValueOrBlank($row['trz_total_rev'] ?? 0), '',
            dcrExcelValueOrBlank($row['pills_new_pts'] ?? 0), dcrExcelValueOrBlank($row['pills_new_rev'] ?? 0), '',
            dcrExcelValueOrBlank($row['pills_fu_pts'] ?? 0), dcrExcelValueOrBlank($row['pills_fu_rev'] ?? 0), '',
            dcrExcelValueOrBlank($row['pills_total_pts'] ?? 0), dcrExcelValueOrBlank($row['pills_total_rev'] ?? 0),
            dcrExcelValueOrBlank($row['test_total_rev'] ?? 0), '',
            dcrExcelValueOrBlank(($row['sg_total_rev'] ?? 0) + ($row['trz_total_rev'] ?? 0) + ($row['pills_total_rev'] ?? 0) + ($row['test_total_rev'] ?? 0)),
        ];
        foreach ($values as $index => $value) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($column . $grossRow, $value);
        }
        dcrExcelApplyRangeStyle($sheet, 'A' . $grossRow . ':AF' . $grossRow, 'FFFEFFFF');
        dcrExcelApplyRangeStyle($sheet, 'AD' . $grossRow, 'FFFFF2CC');
        dcrExcelApplyRangeStyle($sheet, 'AF' . $grossRow, 'FFFFF2CC');
        foreach (['B', 'C', 'F', 'I', 'L', 'O', 'R', 'U', 'X', 'AA', 'AE'] as $separatorColumn) {
            $fill = $separatorColumn === 'C' ? 'FFA6A6A6' : 'FFAAAAAA';
            dcrExcelApplyRangeStyle($sheet, $separatorColumn . $grossRow, $fill, false, Alignment::HORIZONTAL_CENTER, false, 11);
        }
        $grossRow++;
    }
    if (empty($dailyBreakdown['rows'])) {
        $sheet->mergeCells('A5:AF5');
        $sheet->setCellValue('A5', 'No gross patient count data available for the selected filters.');
        dcrExcelApplyRangeStyle($sheet, 'A5:AF5', 'FFFEFFFF', false, Alignment::HORIZONTAL_CENTER);
        $grossRow = 6;
    } else {
        $totalValues = [
            'Total',
            '',
            '',
            dcrExcelValueOrBlank($dailyBreakdown['totals']['sema_new_pts'] ?? 0), dcrExcelValueOrBlank($dailyBreakdown['totals']['sema_new_rev'] ?? 0), '',
            dcrExcelValueOrBlank($dailyBreakdown['totals']['sema_fu_pts'] ?? 0), dcrExcelValueOrBlank($dailyBreakdown['totals']['sema_fu_rev'] ?? 0), '',
            dcrExcelValueOrBlank($dailyBreakdown['totals']['sg_total_pts'] ?? 0), dcrExcelValueOrBlank($dailyBreakdown['totals']['sg_total_rev'] ?? 0), '',
            dcrExcelValueOrBlank($dailyBreakdown['totals']['tirz_new_pts'] ?? 0), dcrExcelValueOrBlank($dailyBreakdown['totals']['tirz_new_rev'] ?? 0), '',
            dcrExcelValueOrBlank($dailyBreakdown['totals']['tirz_fu_pts'] ?? 0), dcrExcelValueOrBlank($dailyBreakdown['totals']['tirz_fu_rev'] ?? 0), '',
            dcrExcelValueOrBlank($dailyBreakdown['totals']['trz_total_pts'] ?? 0), dcrExcelValueOrBlank($dailyBreakdown['totals']['trz_total_rev'] ?? 0), '',
            dcrExcelValueOrBlank($dailyBreakdown['totals']['pills_new_pts'] ?? 0), dcrExcelValueOrBlank($dailyBreakdown['totals']['pills_new_rev'] ?? 0), '',
            dcrExcelValueOrBlank($dailyBreakdown['totals']['pills_fu_pts'] ?? 0), dcrExcelValueOrBlank($dailyBreakdown['totals']['pills_fu_rev'] ?? 0), '',
            dcrExcelValueOrBlank($dailyBreakdown['totals']['pills_total_pts'] ?? 0), dcrExcelValueOrBlank($dailyBreakdown['totals']['pills_total_rev'] ?? 0),
            dcrExcelValueOrBlank($dailyBreakdown['totals']['test_total_rev'] ?? 0), '',
            dcrExcelValueOrBlank(($dailyBreakdown['totals']['sg_total_rev'] ?? 0) + ($dailyBreakdown['totals']['trz_total_rev'] ?? 0) + ($dailyBreakdown['totals']['pills_total_rev'] ?? 0) + ($dailyBreakdown['totals']['test_total_rev'] ?? 0)),
        ];
        foreach ($totalValues as $index => $value) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($column . $grossRow, $value);
        }
        dcrExcelApplyRangeStyle($sheet, 'A' . $grossRow . ':AF' . $grossRow, 'FFFEFFFF', true);
        dcrExcelApplyRangeStyle($sheet, 'AD' . $grossRow, 'FFFFF2CC', true);
        dcrExcelApplyRangeStyle($sheet, 'AF' . $grossRow, 'FFFFF2CC', true);
        foreach (['B', 'F', 'I', 'L', 'O', 'R', 'U', 'X', 'AA', 'AE'] as $separatorColumn) {
            dcrExcelApplyRangeStyle($sheet, $separatorColumn . $grossRow, 'FFAAAAAA', true, Alignment::HORIZONTAL_CENTER, false, 11);
        }
        dcrExcelApplyRangeStyle($sheet, 'C' . $grossRow, 'FFA6A6A6', true, Alignment::HORIZONTAL_CENTER, false, 11);
        $grossRow++;
    }
    dcrExcelFormatCurrencyColumns($sheet, ['E', 'H', 'K', 'N', 'Q', 'T', 'W', 'Z', 'AC', 'AD', 'AF'], 5, max($grossRow, 5));
    $sheet->freezePane('A5');
    dcrExcelFinalizeSheetLayout($sheet, 'A1:AF' . max($grossRow, 5), 32, ['A' => 20]);

    $revenueSheet = $workbook->createSheet();
    $revenueSheet->setTitle('Revenue Breakdown');
    $revenueSheet->mergeCells('A1:D1');
    $revenueSheet->mergeCells('H1:J1');
    $revenueSheet->setCellValue('A1', $facilityDisplayName);
    $revenueSheet->setCellValue('H1', $periodLabel);
    dcrExcelApplyRangeStyle($revenueSheet, 'A1:D1', 'FFFEFFFF', true, Alignment::HORIZONTAL_LEFT, false, 12);
    dcrExcelApplyRangeStyle($revenueSheet, 'H1:J1', 'FFFEFFFF', true, Alignment::HORIZONTAL_CENTER, false, 12);
    $revenueHeaders = ['', '', 'Office Visit', 'LAB', 'LIPO Inj', 'Semaglutide', 'Tirzepatide', 'AfterPay', 'Supplements', 'Protein Drinks', 'Taxes', ' Total'];
    $revenueColors = ['FFFEFFFF', 'FFFEFFFF', 'FFFEFFFF', 'FFFEFFFF', 'FFFEFFFF', 'FFF9CB9C', 'FFF4CCCC', 'FFFEFFFF', 'FFFEFFFF', 'FFFEFFFF', 'FFFEFFFF', 'FFFEFFFF'];
    foreach ($revenueHeaders as $index => $header) {
        $column = Coordinate::stringFromColumnIndex($index + 1);
        $revenueSheet->setCellValue($column . '2', $header);
        dcrExcelApplyRangeStyle($revenueSheet, $column . '2', $revenueColors[$index] ?? 'FFFEFFFF', true, Alignment::HORIZONTAL_CENTER, true);
    }
    $revenueSheet->setCellValue('B3', "Running\nTotals");
    dcrExcelApplyRangeStyle($revenueSheet, 'A3:L3', 'FFFEFFFF', true, Alignment::HORIZONTAL_CENTER, true);
    $revenueSheet->getRowDimension(3)->setRowHeight(30);
    $revenueRow = 4;
    if (!empty($monthlyGrid['totals'])) {
        $revenueSheet->setCellValue('B3', "Running\nTotals");
        $revenueSheet->fromArray([
            dcrExcelValueOrBlank($monthlyGrid['totals']['office_visit'] ?? 0),
            dcrExcelValueOrBlank($monthlyGrid['totals']['lab'] ?? 0),
            dcrExcelValueOrBlank($monthlyGrid['totals']['lipo'] ?? 0),
            dcrExcelValueOrBlank($monthlyGrid['totals']['sema'] ?? 0),
            dcrExcelValueOrBlank($monthlyGrid['totals']['tirz'] ?? 0),
            dcrExcelValueOrBlank($monthlyGrid['totals']['afterpay'] ?? 0),
            dcrExcelValueOrBlank($monthlyGrid['totals']['supplements'] ?? 0),
            dcrExcelValueOrBlank($monthlyGrid['totals']['protein'] ?? 0),
            dcrExcelValueOrBlank($monthlyGrid['totals']['taxes'] ?? 0),
            dcrExcelValueOrBlank($monthlyGrid['totals']['total'] ?? 0),
        ], null, 'C3');
        $revenueSheet->setCellValue('A' . $revenueRow, $periodLabel);
        $revenueSheet->setCellValue('B' . $revenueRow, 'TOTAL');
        $revenueSheet->fromArray([
            dcrExcelValueOrBlank($monthlyGrid['totals']['office_visit'] ?? 0),
            dcrExcelValueOrBlank($monthlyGrid['totals']['lab'] ?? 0),
            dcrExcelValueOrBlank($monthlyGrid['totals']['lipo'] ?? 0),
            dcrExcelValueOrBlank($monthlyGrid['totals']['sema'] ?? 0),
            dcrExcelValueOrBlank($monthlyGrid['totals']['tirz'] ?? 0),
            dcrExcelValueOrBlank($monthlyGrid['totals']['afterpay'] ?? 0),
            dcrExcelValueOrBlank($monthlyGrid['totals']['supplements'] ?? 0),
            dcrExcelValueOrBlank($monthlyGrid['totals']['protein'] ?? 0),
            dcrExcelValueOrBlank($monthlyGrid['totals']['taxes'] ?? 0),
            dcrExcelValueOrBlank($monthlyGrid['totals']['total'] ?? 0),
        ], null, 'C' . $revenueRow);
        dcrExcelApplyRangeStyle($revenueSheet, 'A' . $revenueRow . ':L' . $revenueRow, 'FFFEFFFF', true);
        $revenueRow++;
    }
    foreach ($monthlyGrid['rows'] ?? [] as $row) {
        $revenueSheet->setCellValue('A' . $revenueRow, $row['date_label']);
        $revenueSheet->setCellValue('B' . $revenueRow, 'TOTAL');
        $revenueSheet->fromArray([
            dcrExcelValueOrBlank($row['office_visit'] ?? 0),
            dcrExcelValueOrBlank($row['lab'] ?? 0),
            dcrExcelValueOrBlank($row['lipo'] ?? 0),
            dcrExcelValueOrBlank($row['sema'] ?? 0),
            dcrExcelValueOrBlank($row['tirz'] ?? 0),
            dcrExcelValueOrBlank($row['afterpay'] ?? 0),
            dcrExcelValueOrBlank($row['supplements'] ?? 0),
            dcrExcelValueOrBlank($row['protein'] ?? 0),
            dcrExcelValueOrBlank($row['taxes'] ?? 0),
            dcrExcelValueOrBlank($row['total'] ?? 0),
        ], null, 'C' . $revenueRow);
        dcrExcelApplyRangeStyle($revenueSheet, 'A' . $revenueRow . ':L' . $revenueRow, 'FFFEFFFF');
        $revenueRow++;
    }
    if ($revenueRow === 4) {
        $revenueSheet->mergeCells('A4:L4');
        $revenueSheet->setCellValue('A4', 'No revenue breakdown data available for the selected filters.');
        dcrExcelApplyRangeStyle($revenueSheet, 'A4:L4', 'FFFEFFFF', false, Alignment::HORIZONTAL_CENTER);
        $revenueRow = 5;
    }
    dcrExcelFormatCurrencyColumns($revenueSheet, ['C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'], 3, max($revenueRow, 4));
    $revenueSheet->freezePane('A4');
    dcrExcelFinalizeSheetLayout($revenueSheet, 'A1:L' . max($revenueRow, 4), 12, ['A' => 16, 'B' => 14]);

    $depositSheet = $workbook->createSheet();
    $depositSheet->setTitle('Deposit');
    $depositSheet->mergeCells('E1:G1');
    $depositSheet->setCellValue('A1', '  ');
    $depositSheet->setCellValue('E1', $periodLabel);
    dcrExcelApplyRangeStyle($depositSheet, 'A1:C1', 'FFFEFFFF', true, Alignment::HORIZONTAL_LEFT, false, 12);
    dcrExcelApplyRangeStyle($depositSheet, 'E1:G1', 'FFFEFFFF', true, Alignment::HORIZONTAL_CENTER, false, 12);
    $depositHeaders = ['CLINIC', 'DATE OF BUSINESS', 'CASH', 'CHECKS', 'Actual Bank Deposit', 'TOTAL DEPOSIT', 'CREDIT', 'SUBTOTAL OF CREDIT/    CHECKS', 'TOTAL REVENUE'];
    foreach ($depositHeaders as $index => $header) {
        $column = Coordinate::stringFromColumnIndex($index + 1);
        $depositSheet->setCellValue($column . '2', $header);
        dcrExcelApplyRangeStyle($depositSheet, $column . '2', 'FFFEFFFF', true, Alignment::HORIZONTAL_CENTER, true);
    }
    $depositRow = 3;
    foreach ($depositSheetData['rows'] as $row) {
        $dateObj = DateTime::createFromFormat('Y-m-d', (string) ($row['date'] ?? ''));
        $displayDate = $dateObj ? $dateObj->format('n/j/Y') : (string) ($row['date'] ?? '');
        $depositSheet->fromArray([
            $row['facility_name'] ?? '',
            $displayDate,
            dcrExcelValueOrBlank($row['cash'] ?? 0),
            dcrExcelValueOrBlank($row['checks'] ?? 0),
            dcrExcelValueOrBlank($row['actual_bank_deposit'] ?? 0),
            dcrExcelValueOrBlank($row['total_deposit'] ?? 0),
            dcrExcelValueOrBlank($row['credit'] ?? 0),
            dcrExcelValueOrBlank($row['subtotal_credit_checks'] ?? 0),
            dcrExcelValueOrBlank($row['total_revenue'] ?? 0),
        ], null, 'A' . $depositRow);
        dcrExcelApplyRangeStyle($depositSheet, 'A' . $depositRow . ':I' . $depositRow, 'FFFEFFFF');
        $depositRow++;
    }
    if (empty($depositSheetData['rows'])) {
        $depositSheet->mergeCells('A3:I3');
        $depositSheet->setCellValue('A3', 'No deposit data available for the selected filters.');
        dcrExcelApplyRangeStyle($depositSheet, 'A3:I3', 'FFFEFFFF', false, Alignment::HORIZONTAL_CENTER);
        $depositRow = 4;
    } else {
        $depositSheet->fromArray([
            'Totals',
            '',
            dcrExcelValueOrBlank($depositSheetData['totals']['cash'] ?? 0),
            dcrExcelValueOrBlank($depositSheetData['totals']['checks'] ?? 0),
            dcrExcelValueOrBlank($depositSheetData['totals']['actual_bank_deposit'] ?? 0),
            dcrExcelValueOrBlank($depositSheetData['totals']['total_deposit'] ?? 0),
            dcrExcelValueOrBlank($depositSheetData['totals']['credit'] ?? 0),
            dcrExcelValueOrBlank($depositSheetData['totals']['subtotal_credit_checks'] ?? 0),
            dcrExcelValueOrBlank($depositSheetData['totals']['total_revenue'] ?? 0),
        ], null, 'A' . $depositRow);
        dcrExcelApplyRangeStyle($depositSheet, 'A' . $depositRow . ':I' . $depositRow, 'FFFEFCA9', true);
        $depositRow++;
    }
    dcrExcelFormatCurrencyColumns($depositSheet, ['C', 'D', 'E', 'F', 'G', 'H', 'I'], 3, max($depositRow, 3));
    $depositSheet->freezePane('A3');
    dcrExcelFinalizeSheetLayout($depositSheet, 'A1:I' . max($depositRow, 3), 9, ['A' => 18, 'B' => 16]);

    $shotSheet = $workbook->createSheet();
    $shotSheet->setTitle('Shot tracker');
    $shotSheet->mergeCells('A1:C1');
    $shotSheet->mergeCells('E1:H1');
    $shotSheet->setCellValue('A1', $facilityDisplayName);
    $shotSheet->setCellValue('E1', $periodLabel);
    dcrExcelApplyRangeStyle($shotSheet, 'A1:C1', 'FFFEFFFF', true, Alignment::HORIZONTAL_LEFT, false, 12, 'FF000000');
    dcrExcelApplyRangeStyle($shotSheet, 'E1:H1', 'FFFEFFFF', true, Alignment::HORIZONTAL_CENTER, false, 12, 'FF000000');
    $shotHeaders = ['Date:', '# of LIPO Cards', 'Total from cards', '', '# of SG & TRZ Cards', 'Total from cards', '# of TRZ Cards', 'Total from cards'];
    $shotHeaderColors = ['FFB7B7B7', 'FFB7B7B7', 'FFB7B7B7', 'FF000000', 'FFF6B26B', 'FFF6B26B', 'FFEAD1DC', 'FFEAD1DC'];
    foreach ($shotHeaders as $index => $header) {
        $column = Coordinate::stringFromColumnIndex($index + 1);
        $shotSheet->setCellValue($column . '2', $header);
        dcrExcelApplyRangeStyle($shotSheet, $column . '2', $shotHeaderColors[$index] ?? 'FFFEFFFF', true, Alignment::HORIZONTAL_CENTER, true, 11, 'FF000000');
    }
    $shotRow = 3;
    foreach ($shotTracker['rows'] ?? [] as $row) {
        $shotSheet->fromArray([
            $row['date_label'] ?? '',
            dcrExcelValueOrBlank($row['lipo_cards'] ?? 0),
            dcrExcelValueOrBlank($row['lipo_total'] ?? 0),
            '',
            dcrExcelValueOrBlank($row['sg_trz_cards'] ?? 0),
            dcrExcelValueOrBlank($row['sg_trz_total'] ?? 0),
            dcrExcelValueOrBlank($row['tes_cards'] ?? 0),
            dcrExcelValueOrBlank($row['tes_total'] ?? 0),
        ], null, 'A' . $shotRow);
        dcrExcelApplyRangeStyle($shotSheet, 'A' . $shotRow . ':H' . $shotRow, 'FFFEFFFF', false, Alignment::HORIZONTAL_CENTER, false, 11, 'FF000000');
        dcrExcelApplyRangeStyle($shotSheet, 'D' . $shotRow, 'FFFEFFFF', false, Alignment::HORIZONTAL_CENTER, false, 11, 'FF000000');
        $shotRow++;
    }
    if (empty($shotTracker['rows'])) {
        $shotSheet->mergeCells('A3:H3');
        $shotSheet->setCellValue('A3', 'No shot tracker data available for the selected filters.');
        dcrExcelApplyRangeStyle($shotSheet, 'A3:H3', 'FFFEFFFF', false, Alignment::HORIZONTAL_CENTER, false, 11, 'FF000000');
        $shotRow = 4;
    } else {
        $shotSheet->fromArray([
            'Totals',
            dcrExcelValueOrBlank($shotTracker['totals']['lipo_cards'] ?? 0),
            dcrExcelValueOrBlank($shotTracker['totals']['lipo_total'] ?? 0),
            '',
            dcrExcelValueOrBlank($shotTracker['totals']['sg_trz_cards'] ?? 0),
            dcrExcelValueOrBlank($shotTracker['totals']['sg_trz_total'] ?? 0),
            dcrExcelValueOrBlank($shotTracker['totals']['tes_cards'] ?? 0),
            dcrExcelValueOrBlank($shotTracker['totals']['tes_total'] ?? 0),
        ], null, 'A' . $shotRow);
        dcrExcelApplyRangeStyle($shotSheet, 'A' . $shotRow . ':H' . $shotRow, 'FFFEFFFF', true, Alignment::HORIZONTAL_CENTER, false, 11, 'FF000000');
        $shotRow++;
    }
    dcrExcelFormatCurrencyColumns($shotSheet, ['C', 'F', 'H'], 3, max($shotRow, 3));
    $shotSheet->freezePane('A3');
    dcrExcelFinalizeSheetLayout($shotSheet, 'A1:H' . max($shotRow, 3), 8, ['A' => 18]);

    $workbook->setActiveSheetIndex(0);
    $writer = new Xlsx($workbook);
    $writer->setPreCalculateFormulas(true);

    ob_start();
    $writer->save('php://output');
    $content = ob_get_clean();
    $workbook->disconnectWorksheets();
    unset($workbook);

    return is_string($content) ? $content : '';
}

function sendDcrReportEmail(array $toEmails, array $reportData, string $facilityDisplayName, string $dateFormatted, string $toDateFormatted, string $formReportType, string $monthYear, string $formDate, string $formToDate, $formFacility, $formPaymentMethod = '', $formMedicine = ''): array
{
    $attachmentReportData = dcrBuildEmailAttachmentReportData(
        $reportData,
        $formReportType,
        $formDate,
        (string) $formFacility,
        $formPaymentMethod,
        $formMedicine
    );
    $emailReportData = maskReportDataForEmail($attachmentReportData);
    $attachmentFormDate = $formDate;
    $attachmentFormToDate = $formToDate;
    $attachmentDateFormatted = $dateFormatted;
    $attachmentToDateFormatted = $toDateFormatted;

    if ($formReportType === 'daily' && !empty($formDate)) {
        $attachmentFormDate = date('Y-m-01', strtotime($formDate));
        $attachmentFormToDate = $formDate;
        $attachmentDateFormatted = date('m/d/Y', strtotime($attachmentFormDate));
        $attachmentToDateFormatted = date('m/d/Y', strtotime($attachmentFormToDate));
    }

    $validatedEmails = [];
    foreach ($toEmails as $email) {
        $email = trim((string) $email);
        if ($email === '') {
            continue;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Please select only valid email addresses.'];
        }
        $validatedEmails[strtolower($email)] = $email;
    }

    if (empty($validatedEmails)) {
        return ['success' => false, 'message' => 'Please select at least one email recipient.'];
    }

    $senderCandidates = [
        trim((string) ($GLOBALS['patient_reminder_sender_email'] ?? '')),
        trim((string) ($GLOBALS['practice_return_email_path'] ?? '')),
        trim((string) ($GLOBALS['SMTP_USER'] ?? '')),
    ];
    $senderEmail = '';
    foreach ($senderCandidates as $candidate) {
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            $senderEmail = $candidate;
            break;
        }
    }
    if ($senderEmail === '' || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'OpenEMR email sender is not configured.'];
    }

    try {
        if (!class_exists('MyMailer', false)) {
            require_once(__DIR__ . "/../../library/classes/postmaster.php");
        }

        $senderName = 'JACtrac DCR Reports';
        if ($facilityDisplayName !== '' && $facilityDisplayName !== 'ALL FACILITIES') {
            $senderName = 'JACtrac DCR Reports - ' . $facilityDisplayName;
        }
        $reportRangeLabel = $dateFormatted . ' to ' . $toDateFormatted;
        $attachmentName = 'jactrac_dcr_report_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', strtolower($facilityDisplayName)) . '_' . date('Ymd_His') . '.xlsx';

        $mail = new MyMailer();
        $mail->AddReplyTo($senderEmail, $senderName);
        $mail->SetFrom($senderEmail, $senderName);
        foreach ($validatedEmails as $recipientEmail) {
            $mail->AddAddress($recipientEmail, $recipientEmail);
        }
        $mail->Subject = 'JACtrac Daily Collection Report | ' . $facilityDisplayName . ' | ' . $reportRangeLabel;
        $mail->MsgHTML(buildDcrEmailHtml($emailReportData, $facilityDisplayName, $dateFormatted, $toDateFormatted));
        $mail->IsHTML(true);
        $mail->AltBody = 'Daily Collection Report for ' . $facilityDisplayName . ' covering ' . $reportRangeLabel . '. The Excel workbook is attached.' . "\n\nThank you for using JacTrac.";
        $excelContent = generateDcrExcelContent(
            $emailReportData,
            $facilityDisplayName,
            $attachmentDateFormatted,
            $attachmentToDateFormatted,
            $formReportType,
            $monthYear,
            $attachmentFormDate,
            $attachmentFormToDate,
            $formFacility
        );
        if ($excelContent !== '') {
            $mail->addStringAttachment(
                $excelContent,
                $attachmentName,
                'base64',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            );
        }

        if ($mail->Send()) {
            return ['success' => true, 'message' => 'DCR email with Excel attachment sent to: ' . implode(', ', array_values($validatedEmails)) . '.'];
        }

        return ['success' => false, 'message' => 'Email send failed: ' . $mail->ErrorInfo];
    } catch (Throwable $e) {
        error_log("DCR email send failed: " . $e->getMessage());
        return ['success' => false, 'message' => 'Email send failed: ' . $e->getMessage()];
    }
}

// Get report data
$report_generated_at = date('Y-m-d H:i:s');
try {
    error_log("Enhanced DCR: Generating report for dates $form_date to $form_to_date, facility: " . ($form_facility ?: 'All') . ", medicine: " . ($form_medicine ?: 'All') . ", payment method: " . ($form_payment_method ?: 'All'));
    $report_data = getComprehensivePOSData($form_date, $form_to_date, $form_facility);
    
    // Apply payment method filter if selected
    if ($form_payment_method) {
        $filtered_transactions = [];
        $filtered_patients = [];
        $filtered_medicines = [];
        
        $total_revenue = 0;
        $marketplace_qty = 0;
        $administered_qty = 0;
        
        // Filter transactions by payment method
        foreach ($report_data['transactions'] as $transaction) {
            if (($transaction['payment_method'] ?? 'N/A') == $form_payment_method) {
                $filtered_transactions[] = $transaction;
                $filtered_patients[$transaction['pid']] = $report_data['unique_patients'][$transaction['pid']] ?? [];
                
                if (!shouldExcludeTransactionFromRevenue($transaction['transaction_type'] ?? '')) {
                    $total_revenue += $transaction['amount'];
                }
                
                // Process items to get medicine data
                if (is_array($transaction['items'])) {
                    foreach ($transaction['items'] as $item) {
                        $drug_id = intval($item['drug_id'] ?? 0);
                        if ($drug_id == 0 && isset($item['id']) && is_string($item['id'])) {
                            if (preg_match('/drug_(\d+)/', $item['id'], $matches)) {
                                $drug_id = intval($matches[1]);
                            }
                        }
                        
                        if ($drug_id > 0) {
                            if (!isset($filtered_medicines[$drug_id])) {
                                $filtered_medicines[$drug_id] = $report_data['medicines'][$drug_id] ?? [
                                    'drug_id' => $drug_id,
                                    'name' => $item['name'] ?? 'Unknown',
                                    'category' => 'Other',
                                    'total_quantity' => 0,
                                    'total_dispensed' => 0,
                                    'total_administered' => 0,
                                    'total_revenue' => 0,
                                    'transaction_count' => 0,
                                    'patients' => []
                                ];
                                // Reset counters for filtered view
                                $filtered_medicines[$drug_id]['total_quantity'] = 0;
                                $filtered_medicines[$drug_id]['total_dispensed'] = 0;
                                $filtered_medicines[$drug_id]['total_administered'] = 0;
                                $filtered_medicines[$drug_id]['total_revenue'] = 0;
                                $filtered_medicines[$drug_id]['transaction_count'] = 0;
                                $filtered_medicines[$drug_id]['patients'] = [];
                            }
                            
                            $qty = getDcrReportedMedicineQuantity($item, $transaction['transaction_type'] ?? '');
                            $disp_qty = dcrNormalizeQuantity($item['dispense_quantity'] ?? 0);
                            $admin_qty = dcrNormalizeQuantity($item['administer_quantity'] ?? 0);
                            $item_total = calculateDcrItemTotal($item, $transaction['created_date'] ?? '');
                            
                            $filtered_medicines[$drug_id]['total_quantity'] += $qty;
                            $filtered_medicines[$drug_id]['total_dispensed'] += $disp_qty;
                            $filtered_medicines[$drug_id]['total_administered'] += $admin_qty;
                            $filtered_medicines[$drug_id]['total_revenue'] += $item_total;
                            $filtered_medicines[$drug_id]['transaction_count']++;
                            
                            if (!in_array($transaction['pid'], $filtered_medicines[$drug_id]['patients'])) {
                                $filtered_medicines[$drug_id]['patients'][] = $transaction['pid'];
                            }
                            
                            $marketplace_qty += $disp_qty;
                            $administered_qty += $admin_qty;
                        }
                    }
                }
            }
        }
        
        // Update report data with filtered results
        $report_data['transactions'] = $filtered_transactions;
        $report_data['unique_patients'] = $filtered_patients;
        $report_data['medicines'] = $filtered_medicines;
        $report_data['total_revenue'] = $total_revenue;
        $report_data['marketplace_quantity'] = $marketplace_qty;
        $report_data['administered_quantity'] = $administered_qty;
        $report_data['net_revenue'] = $total_revenue;
    }
    
    // Apply medicine filter if selected
    if ($form_medicine) {
        $filtered_medicines = [];
        $filtered_transactions = [];
        $filtered_patients = [];
        
        // Filter medicines
        foreach ($report_data['medicines'] as $drug_id => $medicine) {
            if ($drug_id == $form_medicine) {
                $filtered_medicines[$drug_id] = $medicine;
            }
        }
        
        // Filter transactions to only those containing the selected medicine
        foreach ($report_data['transactions'] as $transaction) {
            $has_medicine = false;
            if (is_array($transaction['items'])) {
                foreach ($transaction['items'] as $item) {
                    $item_drug_id = intval($item['drug_id'] ?? 0);
                    if ($item_drug_id == 0 && isset($item['id']) && is_string($item['id'])) {
                        if (preg_match('/drug_(\d+)/', $item['id'], $matches)) {
                            $item_drug_id = intval($matches[1]);
                        }
                    }
                    if ($item_drug_id == $form_medicine) {
                        $has_medicine = true;
                        break;
                    }
                }
            }
            if ($has_medicine) {
                $filtered_transactions[] = $transaction;
                $filtered_patients[$transaction['pid']] = $report_data['unique_patients'][$transaction['pid']] ?? [];
            }
        }
        
        // Update report data with filtered results
        $report_data['medicines'] = $filtered_medicines;
        $report_data['transactions'] = $filtered_transactions;
        $report_data['unique_patients'] = $filtered_patients;
        
        // Recalculate totals
        $report_data['total_revenue'] = 0;
        $report_data['marketplace_quantity'] = 0;
        $report_data['administered_quantity'] = 0;
        
        if (!empty($filtered_medicines)) {
            $medicine = reset($filtered_medicines);
            $report_data['total_revenue'] = $medicine['total_revenue'];
            $report_data['marketplace_quantity'] = $medicine['total_dispensed'];
            $report_data['administered_quantity'] = $medicine['total_administered'];
        }
        
        // Recalculate net revenue
        $report_data['net_revenue'] = $report_data['total_revenue'];
    }
    
    error_log("Enhanced DCR: Found " . count($report_data['transactions']) . " transactions, " . count($report_data['medicines']) . " medicines, " . count($report_data['unique_patients']) . " patients");
    error_log("Enhanced DCR: Total revenue: $" . $report_data['total_revenue']);

    // Rebuild patient rows and breakdown after filters
    rebuildPatientSummaries($report_data);

} catch (Throwable $e) {
    error_log("Error generating enhanced DCR report: " . $e->getMessage());
    error_log("Error trace: " . $e->getTraceAsString());
    $report_data = [
        'total_revenue' => 0,
        'net_revenue' => 0,
        'new_patients' => 0,
        'returning_patients' => 0,
        'transactions' => [],
        'unique_patients' => [],
        'patient_rows' => [],
        'medicines' => [],
        'categories' => [],
        'breakdown' => initializeBreakdownStructure()
    ];
}

// Get medicine list for dropdown
$available_medicines = getAllMedicinesFromTransactions();
$facility_display_name = getDcrFacilityDisplayName($form_facility);
$email_status = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form_export === 'email') {
    $email_status = sendDcrReportEmail(
        $form_email_to,
        $report_data,
        $facility_display_name,
        $date_formatted,
        $to_date_formatted,
        $form_report_type,
        $month_year,
        $form_date,
        $form_to_date,
        $form_facility,
        $form_payment_method,
        $form_medicine
    );
}

// Handle XLSX download using the same workbook sent by email
if ($form_export === 'download') {
    $exportTimestamp = date('Ymd_His');
    $downloadFacilityName = $facility_display_name !== '' ? $facility_display_name : 'all_facilities';
    $downloadFilename = 'jactrac_dcr_report_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', strtolower($downloadFacilityName)) . '_' . $exportTimestamp . '.xlsx';
    $excelContent = generateDcrExcelContent(
        $report_data,
        $facility_display_name,
        $date_formatted,
        $to_date_formatted,
        $form_report_type,
        $month_year,
        $form_date,
        $form_to_date,
        $form_facility
    );

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
    header('Content-Length: ' . strlen($excelContent));
    echo $excelContent;
    exit;
}

// Handle CSV export
if ($form_export === 'csv') {
    $exportTimestamp = date('Ymd_His');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dcr_report_' . $form_date . '_to_' . $form_to_date . '_' . $exportTimestamp . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Get facility name
    $csv_facility_name = $facility_display_name;
    $blank_take_home_columns = shouldBlankTakeHomeColumns($form_facility, $csv_facility_name);
    
    // Add BOM for Excel UTF-8 support
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // ===== SHEET 1: PATIENT DCR GRID =====
    fputcsv($output, ['PATIENT DCR GRID']);
    fputcsv($output, ['Report Period: ' . $date_formatted . ' to ' . $to_date_formatted]);
    fputcsv($output, ['Facility: ' . $csv_facility_name]);
    fputcsv($output, []);
    
    // Patient grid headers
    $gridHeaders = [
        'Sign In #', 'Patient Name', 'Action', 'OV', 'BW',
        'B12 Lipo - Card Punch #', 'B12 Lipo - TH', 'B12 Lipo - Amount', 'B12 Lipo - #',
        'Semaglutide - Card Punch #', 'Semaglutide - TH', 'Sema Injection', 'Semaglutide - #', 'Sema DOSE (mg)',
        'Tirzepatide - Card Punch #', 'Tirzepatide - TH', 'TRZ Injection', 'Tirzepatide - #', 'TRZ DOSE (mg)',
        'AfterPay Fee', 'Supplements - Amount', 'Supplements - #', 'Protein Shakes - Amount', 'Protein Shakes - #',
        'Subtotal', 'Tax', 'Credits', 'Total', 'Notes'
    ];
    fputcsv($output, $gridHeaders);
    
    // Calculate totals for patient grid
    $csvTotals = [
        'ov' => 0,
        'bw' => 0,
        'lipo_admin' => 0,
        'lipo_home' => 0,
        'lipo_amount' => 0,
        'lipo_purchased' => 0,
        'sema_admin' => 0,
        'sema_home' => 0,
        'sema_amount' => 0,
        'sema_purchased' => 0,
        'tirz_admin' => 0,
        'tirz_home' => 0,
        'tirz_amount' => 0,
        'tirz_purchased' => 0,
        'afterpay' => 0,
        'supp_amount' => 0,
        'supp_count' => 0,
        'drink_amount' => 0,
        'drink_count' => 0,
        'subtotal' => 0,
        'tax' => 0,
        'credit' => 0,
        'total' => 0
    ];
    
    // Patient rows
    foreach ($report_data['patient_rows'] as $patient) {
        $details = $patient['details'] ?? initializePatientDetails();
        $netAmounts = getPatientRevenueGridAmounts($details);
        $netSubtotal = getPatientRevenueGridSubtotal($details, $netAmounts);
        $actionCode = $details['visit_type'] ?? '-';
        $semaDose = !empty($details['sema_doses']) ? implode(', ', $details['sema_doses']) : '';
        $tirzDose = !empty($details['tirz_doses']) ? implode(', ', $details['tirz_doses']) : '';
        $lipoCardPunchCsv = resolvePatientCardPunchDisplay($details, 'lipo', 5);
        $semaCardPunchCsv = resolvePatientCardPunchDisplay($details, 'sema', 4);
        $tirzCardPunchCsv = resolvePatientCardPunchDisplay($details, 'tirz', 4);
        $lipoTakeHomeCsv = $blank_take_home_columns ? '' : formatNumberCell($details['lipo_takehome_count'] ?? 0);
        $semaTakeHomeCsv = $blank_take_home_columns ? '' : formatNumberCell($details['sema_takehome_count'] ?? 0);
        $tirzTakeHomeCsv = $blank_take_home_columns ? '' : formatNumberCell($details['tirz_takehome_count'] ?? 0);
        
        // Accumulate totals
        $csvTotals['ov'] += floatval($netAmounts['office_visit'] ?? 0);
        $csvTotals['bw'] += floatval($netAmounts['lab'] ?? 0);
        $csvTotals['lipo_admin'] += floatval($details['lipo_admin_count'] ?? 0);
        $csvTotals['lipo_home'] += $blank_take_home_columns ? 0 : floatval($details['lipo_takehome_count'] ?? 0);
        $csvTotals['lipo_amount'] += floatval($netAmounts['lipo'] ?? 0);
        $csvTotals['lipo_purchased'] += floatval($details['lipo_purchased_count'] ?? 0);
        $csvTotals['sema_admin'] += floatval($details['sema_admin_count'] ?? 0);
        $csvTotals['sema_home'] += $blank_take_home_columns ? 0 : floatval($details['sema_takehome_count'] ?? 0);
        $csvTotals['sema_amount'] += floatval($netAmounts['sema'] ?? 0);
        $csvTotals['sema_purchased'] += floatval($details['sema_purchased_count'] ?? 0);
        $csvTotals['tirz_admin'] += floatval($details['tirz_admin_count'] ?? 0);
        $csvTotals['tirz_home'] += $blank_take_home_columns ? 0 : floatval($details['tirz_takehome_count'] ?? 0);
        $csvTotals['tirz_amount'] += floatval($netAmounts['tirz'] ?? 0);
        $csvTotals['tirz_purchased'] += floatval($details['tirz_purchased_count'] ?? 0);
        $csvTotals['afterpay'] += floatval($netAmounts['afterpay'] ?? 0);
        $csvTotals['supp_amount'] += floatval($netAmounts['supplements'] ?? 0);
        $csvTotals['supp_count'] += floatval($details['supplement_count'] ?? 0);
        $csvTotals['drink_amount'] += floatval($netAmounts['protein'] ?? 0);
        $csvTotals['drink_count'] += floatval($details['drink_count'] ?? 0);
        $csvTotals['subtotal'] += $netSubtotal;
        $csvTotals['tax'] += floatval($details['tax_amount'] ?? 0);
        $csvTotals['credit'] += floatval($details['credit_amount'] ?? 0);
        $csvTotals['total'] += floatval($details['total_amount'] ?? $patient['total_spent']);
        $totalAmount = number_format(
            floatval($details['total_amount'] ?? $patient['total_spent']),
            2
        );

        // Keep override notes in a separate CSV column (do NOT append into Total)
        $overrideNotes = $details['price_override_notes'] ?? '';
        $overrideNotes = preg_replace("/\r\n|\r|\n/", " ", (string)$overrideNotes);
        
        fputcsv($output, [
            $patient['order_number'],
            $patient['name'],
            $actionCode,
            number_format(floatval($netAmounts['office_visit'] ?? 0), 2),
            number_format(floatval($netAmounts['lab'] ?? 0), 2),
            $lipoCardPunchCsv,
            $lipoTakeHomeCsv,
            number_format(floatval($netAmounts['lipo'] ?? 0), 2),
            floatval($details['lipo_purchased_count'] ?? 0),
            $semaCardPunchCsv,
            $semaTakeHomeCsv,
            number_format(floatval($netAmounts['sema'] ?? 0), 2),
            floatval($details['sema_purchased_count'] ?? 0),
            $semaDose,
            $tirzCardPunchCsv,
            $tirzTakeHomeCsv,
            number_format(floatval($netAmounts['tirz'] ?? 0), 2),
            floatval($details['tirz_purchased_count'] ?? 0),
            $tirzDose,
            number_format(floatval($netAmounts['afterpay'] ?? 0), 2),
            number_format(floatval($netAmounts['supplements'] ?? 0), 2),
            floatval($details['supplement_count'] ?? 0),
            number_format(floatval($netAmounts['protein'] ?? 0), 2),
            floatval($details['drink_count'] ?? 0),
            number_format($netSubtotal, 2),
            number_format(floatval($details['tax_amount'] ?? 0), 2),
            number_format(floatval($details['credit_amount'] ?? 0), 2),
            $totalAmount,
            $overrideNotes
        ]);
        
    }
    
    // Add totals row
    fputcsv($output, ['TOTALS', '', '', 
        number_format($csvTotals['ov'], 2),
        number_format($csvTotals['bw'], 2),
        $csvTotals['lipo_admin'],
        $blank_take_home_columns ? '' : $csvTotals['lipo_home'],
        number_format($csvTotals['lipo_amount'], 2),
        $csvTotals['lipo_purchased'],
        '',
        $csvTotals['sema_admin'],
        $blank_take_home_columns ? '' : $csvTotals['sema_home'],
        number_format($csvTotals['sema_amount'], 2),
        $csvTotals['sema_purchased'],
        '',
        $csvTotals['tirz_admin'],
        $blank_take_home_columns ? '' : $csvTotals['tirz_home'],
        number_format($csvTotals['tirz_amount'], 2),
        $csvTotals['tirz_purchased'],
        '',
        number_format($csvTotals['afterpay'], 2),
        number_format($csvTotals['supp_amount'], 2),
        $csvTotals['supp_count'],
        number_format($csvTotals['drink_amount'], 2),
        $csvTotals['drink_count'],
        number_format($csvTotals['subtotal'], 2),
        number_format($csvTotals['tax'], 2),
        number_format($csvTotals['credit'], 2),
        number_format($csvTotals['total'], 2),
        ''
    ]);
    
    fputcsv($output, []);
    fputcsv($output, []);
    
    // ===== SHEET 2: DAILY BREAKDOWN =====
    if ($form_report_type === 'daily' && !empty($report_data['daily_breakdown']['rows'])) {
        fputcsv($output, ['DAILY BREAKDOWN']);
        fputcsv($output, []);
        
        $breakdownHeaders = [
            'Day of week & date',
            'Semaglutide - New Pts', 'Semaglutide - New Revenue',
            'Semaglutide - FU Pts', 'Semaglutide - FU Revenue',
            'Semaglutide - Rtn Pts', 'Semaglutide - Rtn Revenue',
            'Semaglutide - Total Pts', 'Semaglutide - Total Revenue',
            'Tirzepatide - New Pts', 'Tirzepatide - New Revenue',
            'Tirzepatide - FU Pts', 'Tirzepatide - FU Revenue',
            'Tirzepatide - Rtn Pts', 'Tirzepatide - Rtn Revenue',
            'Tirzepatide - Total Pts', 'Tirzepatide - Total Revenue',
            'Pills - New Pts', 'Pills - New Revenue',
            'Pills - FU Pts', 'Pills - FU Revenue',
            'Pills - Rtn Pts', 'Pills - Rtn Revenue',
            'Pills - Total Pts', 'Pills - Total Revenue',
            'Testosterone - New Pts', 'Testosterone - New Revenue',
            'Testosterone - FU Pts', 'Testosterone - FU Revenue',
            'Testosterone - Rtn Pts', 'Testosterone - Rtn Revenue',
            'Testosterone - Total Pts', 'Testosterone - Total Revenue'
        ];
        fputcsv($output, $breakdownHeaders);
        
        $dailyBreakdown = $report_data['daily_breakdown'];
        foreach ($dailyBreakdown['rows'] as $row) {
            fputcsv($output, [
                $row['display_date'],
                $row['sema_new_pts'], number_format($row['sema_new_rev'], 2),
                $row['sema_fu_pts'], number_format($row['sema_fu_rev'], 2),
                $row['sema_rtn_pts'], number_format($row['sema_rtn_rev'], 2),
                $row['sg_total_pts'], number_format($row['sg_total_rev'], 2),
                $row['tirz_new_pts'], number_format($row['tirz_new_rev'], 2),
                $row['tirz_fu_pts'], number_format($row['tirz_fu_rev'], 2),
                $row['tirz_rtn_pts'], number_format($row['tirz_rtn_rev'], 2),
                $row['trz_total_pts'], number_format($row['trz_total_rev'], 2),
                $row['pills_new_pts'], number_format($row['pills_new_rev'], 2),
                $row['pills_fu_pts'], number_format($row['pills_fu_rev'], 2),
                $row['pills_rtn_pts'], number_format($row['pills_rtn_rev'], 2),
                $row['pills_total_pts'], number_format($row['pills_total_rev'], 2),
                $row['test_new_pts'], number_format($row['test_new_rev'], 2),
                $row['test_fu_pts'], number_format($row['test_fu_rev'], 2),
                $row['test_rtn_pts'], number_format($row['test_rtn_rev'], 2),
                $row['test_total_pts'], number_format($row['test_total_rev'], 2)
            ]);
        }
        
        // Totals row
        fputcsv($output, ['TOTAL']);
        $totals = $dailyBreakdown['totals'];
        fputcsv($output, [
            '',
            $totals['sema_new_pts'], number_format($totals['sema_new_rev'], 2),
            $totals['sema_fu_pts'], number_format($totals['sema_fu_rev'], 2),
            $totals['sema_rtn_pts'], number_format($totals['sema_rtn_rev'], 2),
            $totals['sg_total_pts'], number_format($totals['sg_total_rev'], 2),
            $totals['tirz_new_pts'], number_format($totals['tirz_new_rev'], 2),
            $totals['tirz_fu_pts'], number_format($totals['tirz_fu_rev'], 2),
            $totals['tirz_rtn_pts'], number_format($totals['tirz_rtn_rev'], 2),
            $totals['trz_total_pts'], number_format($totals['trz_total_rev'], 2),
            $totals['pills_new_pts'], number_format($totals['pills_new_rev'], 2),
            $totals['pills_fu_pts'], number_format($totals['pills_fu_rev'], 2),
            $totals['pills_rtn_pts'], number_format($totals['pills_rtn_rev'], 2),
            $totals['pills_total_pts'], number_format($totals['pills_total_rev'], 2),
            $totals['test_new_pts'], number_format($totals['test_new_rev'], 2),
            $totals['test_fu_pts'], number_format($totals['test_fu_rev'], 2),
            $totals['test_rtn_pts'], number_format($totals['test_rtn_rev'], 2),
            $totals['test_total_pts'], number_format($totals['test_total_rev'], 2)
        ]);
        
        fputcsv($output, []);
        fputcsv($output, []);
    }
    
    // ===== SHEET 3: MONTHLY REVENUE GRID =====
    if ($form_report_type === 'monthly' && !empty($report_data['monthly_revenue_grid']['rows'])) {
        fputcsv($output, ['MONTHLY REVENUE BREAKDOWN']);
        fputcsv($output, ['Month: ' . $month_year]);
        fputcsv($output, []);
        
        $monthlyHeaders = [
            'Date', 'Label', 'Running Totals',
            'Office Visit', 'LAB', 'LIPO Inj', 'Semaglutide', 'Tirzepatide', 'Testosterone',
            'AfterPay', 'Supplements', 'Protein Drinks', 'Taxes', 'Total'
        ];
        fputcsv($output, $monthlyHeaders);
        
        $monthlyGrid = $report_data['monthly_revenue_grid'];
        foreach ($monthlyGrid['rows'] as $row) {
            fputcsv($output, [
                $row['date_label'],
                $row['label'],
                number_format($row['running_total'], 2),
                number_format($row['office_visit'], 2),
                number_format($row['lab'], 2),
                number_format($row['lipo'], 2),
                number_format($row['sema'], 2),
                number_format($row['tirz'], 2),
                number_format($row['testosterone'], 2),
                number_format($row['afterpay'], 2),
                number_format($row['supplements'], 2),
                number_format($row['protein'], 2),
                number_format($row['taxes'], 2),
                number_format($row['total'], 2)
            ]);
        }
        
        // Running totals row
        fputcsv($output, ['Running Totals', 'TOTAL']);
        $monthlyTotals = $monthlyGrid['totals'];
        fputcsv($output, [
            '',
            '',
            number_format($monthlyTotals['running_total'], 2),
            number_format($monthlyTotals['office_visit'], 2),
            number_format($monthlyTotals['lab'], 2),
            number_format($monthlyTotals['lipo'], 2),
            number_format($monthlyTotals['sema'], 2),
            number_format($monthlyTotals['tirz'], 2),
            number_format($monthlyTotals['testosterone'], 2),
            number_format($monthlyTotals['afterpay'], 2),
            number_format($monthlyTotals['supplements'], 2),
            number_format($monthlyTotals['protein'], 2),
            number_format($monthlyTotals['taxes'], 2),
            number_format($monthlyTotals['total'], 2)
        ]);
        
        fputcsv($output, []);
        fputcsv($output, []);
    }
    
    // ===== SHEET 4: SUMMARY STATISTICS =====
    fputcsv($output, ['SUMMARY STATISTICS']);
    fputcsv($output, []);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Revenue', number_format($report_data['total_revenue'], 2)]);
    fputcsv($output, ['Net Revenue', number_format($report_data['net_revenue'], 2)]);
    fputcsv($output, ['Purchase Revenue', number_format($report_data['purchase_revenue'] ?? 0, 2)]);
    fputcsv($output, ['Dispense Revenue', number_format($report_data['dispense_revenue'] ?? 0, 2)]);
    fputcsv($output, ['Administer Revenue', number_format($report_data['administer_revenue'] ?? 0, 2)]);
    fputcsv($output, ['Refunds Total', number_format($report_data['refunds_total'] ?? 0, 2)]);
    fputcsv($output, ['Total Patients', count($report_data['patient_rows'])]);
    fputcsv($output, ['New Patients', $report_data['new_patients'] ?? 0]);
    fputcsv($output, ['Returning Patients', $report_data['returning_patients'] ?? 0]);
    fputcsv($output, ['Total Transactions', count($report_data['transactions'] ?? [])]);
    fputcsv($output, []);
    
    fclose($output);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced DCR Report - OpenEMR</title>
    <?php Header::setupHeader(['datetime-picker', 'datatables', 'datatables-dt', 'datatables-bs']); ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; 
            background: #f8f9fa; 
            padding: 0;
            margin: 0;
        }
        
        .dcr-container { 
            max-width: 100%; 
            margin: 0; 
            background: white;
        }
        
        .dcr-header { 
            background: #fff; 
            color: #333; 
            padding: 20px 30px; 
            border-bottom: 1px solid #e0e0e0;
        }
        
        .dcr-title { 
            font-size: 24px; 
            font-weight: 600; 
            margin-bottom: 5px;
            color: #1a1a1a;
        }
        
        .dcr-subtitle { 
            font-size: 14px; 
            color: #666;
        }
        
        .dcr-controls { 
            padding: 20px 30px; 
            background: #fff; 
            border-bottom: 1px solid #e0e0e0;
        }
        
        .controls-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 15px; 
            margin-bottom: 15px;
        }
        
        .control-group label { 
            display: block; 
            font-weight: 500; 
            margin-bottom: 6px; 
            color: #333;
            font-size: 13px;
        }
        
        .control-group input,
        .control-group select { 
            width: 100%; 
            padding: 8px 12px; 
            border: 1px solid #ddd; 
            border-radius: 4px; 
            font-size: 14px;
            background: #fff;
        }
        
        .control-group input:focus,
        .control-group select:focus { 
            outline: none; 
            border-color: #007bff; 
            box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.1);
        }
        
        .button-group { 
            display: flex; 
            gap: 10px; 
            flex-wrap: wrap;
        }
        
        .btn { 
            padding: 8px 16px; 
            border: 1px solid transparent; 
            border-radius: 4px; 
            font-weight: 500; 
            cursor: pointer; 
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.15s;
        }
        
        .btn-primary { 
            background: #007bff; 
            color: white;
            border-color: #007bff;
        }
        
        .btn-primary:hover { 
            background: #0056b3; 
            border-color: #0056b3;
        }
        
        .btn-success { 
            background: #28a745; 
            color: white;
            border-color: #28a745;
        }
        
        .btn-success:hover { 
            background: #218838;
            border-color: #1e7e34;
        }
        
        .btn-info { 
            background: #17a2b8; 
            color: white;
            border-color: #17a2b8;
        }
        
        .btn-info:hover { 
            background: #138496;
            border-color: #117a8b;
        }
        
        .dcr-content { 
            padding: 20px;
            background: #ffffff;
        }
        
        .stats-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); 
            gap: 15px; 
            margin-bottom: 25px;
        }
        
        .stat-card { 
            background: #fff; 
            padding: 18px; 
            border-radius: 4px; 
            border: 1px solid #e0e0e0;
        }
        
        .stat-label { 
            font-size: 12px; 
            color: #666; 
            margin-bottom: 8px; 
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-value { 
            font-size: 24px; 
            font-weight: 600;
            color: #1a1a1a;
        }
        
        .stat-card.revenue .stat-value { color: #007bff; }
        .stat-card.purchase .stat-value { color: #28a745; }
        .stat-card.dispense .stat-value { color: #17a2b8; }
        .stat-card.administer .stat-value { color: #fd7e14; }
        .stat-card.patients .stat-value { color: #6610f2; }
        .stat-card.transactions .stat-value { color: #6c757d; }
        
        .section { 
            margin-bottom: 25px; 
            background: #fff; 
            border-radius: 4px; 
            padding: 20px; 
            border: 1px solid #e0e0e0;
        }
        
        .section-title { 
            font-size: 16px; 
            font-weight: 600; 
            margin-bottom: 15px; 
            color: #333;
            padding-bottom: 8px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .data-table { 
            width: 100%; 
            border-collapse: collapse; 
            background: white; 
            font-size: 13px;
        }
        
        .data-table thead { 
            background: #f8f9fa; 
            color: #333;
        }
        
        .data-table th { 
            padding: 10px 12px; 
            text-align: left; 
            font-weight: 600; 
            font-size: 12px;
            border-bottom: 2px solid #dee2e6;
        }
        
        .data-table td { 
            padding: 10px 12px; 
            border-bottom: 1px solid #e9ecef; 
            font-size: 13px;
        }
        
        .data-table tbody tr:hover { 
            background: #f8f9fa;
        }

        .dcr-highlight-row {
            background: #fff3cd !important;
            box-shadow: inset 0 0 0 2px #f0ad4e;
        }

        .gross-count-link {
            border: none;
            background: transparent;
            padding: 0;
            margin: 0;
            color: #0056b3;
            cursor: pointer;
            text-decoration: underline;
            font: inherit;
            font-weight: 700;
        }

        .gross-count-link:hover {
            color: #003d80;
        }

        .dcr-drilldown-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            padding: 24px;
        }

        .dcr-drilldown-overlay.active {
            display: flex;
        }

        .dcr-drilldown-modal {
            background: #fff;
            width: min(960px, 100%);
            max-height: 85vh;
            border-radius: 10px;
            box-shadow: 0 18px 60px rgba(15, 23, 42, 0.22);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .dcr-drilldown-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 18px 22px;
            border-bottom: 1px solid #e5e7eb;
        }

        .dcr-drilldown-title {
            font-size: 18px;
            font-weight: 700;
            color: #1f2937;
        }

        .dcr-drilldown-subtitle {
            margin-top: 4px;
            font-size: 13px;
            color: #6b7280;
        }

        .dcr-drilldown-close {
            border: 1px solid #d1d5db;
            background: #fff;
            color: #374151;
            border-radius: 6px;
            padding: 8px 12px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
        }

        .dcr-drilldown-body {
            padding: 18px 22px 22px;
            overflow: auto;
        }

        .dcr-drilldown-empty {
            color: #6b7280;
            font-style: italic;
        }

        .dcr-drilldown-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .dcr-drilldown-table th,
        .dcr-drilldown-table td {
            border: 1px solid #dbe3ef;
            padding: 10px 12px;
            text-align: left;
            vertical-align: top;
        }

        .dcr-drilldown-table th {
            background: #f8fafc;
            font-weight: 700;
            color: #334155;
        }

        .dcr-drilldown-table tbody tr:nth-child(even) {
            background: #fafcff;
        }

        .dcr-row-link {
            color: #0056b3;
            text-decoration: underline;
            cursor: pointer;
            font-weight: 600;
        }
        
        /* Excel-like spreadsheet styling */
        .excel-container {
            background: #ffffff;
            padding: 20px;
            font-family: 'Segoe UI', Arial, sans-serif;
        }
        
        .excel-header {
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 30px;
        }
        
        .excel-facility-name {
            font-size: 18px;
            font-weight: bold;
            color: #000000;
        }
        
        .excel-summary-box {
            display: flex;
            gap: 20px;
        }
        
        .excel-summary-value {
            font-size: 14px;
            font-weight: 600;
            color: #000000;
        }
        
        .excel-top-summary-row th {
            background: #b8cce4;
            border: 1px solid #95b3d7;
            font-weight: 600;
            font-size: 10px;
            text-align: center;
            padding: 6px 4px;
        }
        
        .excel-top-summary-cell {
            background: #dfe7f3 !important;
            color: #000000;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .excel-top-summary-value {
            display: block;
            margin-top: 3px;
            font-size: 12px;
            font-weight: 700;
        }
        
        .excel-facility-title-row th {
            background: #ffffff;
            border: 1px solid #95b3d7;
            padding: 8px 6px;
        }
        
        .excel-facility-title-cell {
            font-size: 18px;
            font-weight: 700;
            text-decoration: underline;
            text-align: left;
            color: #000000;
        }
        
        .excel-title-cell {
            font-size: 18px;
            font-weight: 700;
            text-align: center;
            letter-spacing: 0.1em;
            color: #000000;
        }
        
        .excel-breakdown-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            font-size: 11px;
            font-family: 'Segoe UI', Arial, sans-serif;
        }
        
        .excel-breakdown-section {
            margin-top: 15px;
        }
        
        .excel-breakdown-table td {
            border: 1px solid #95b3d7;
            padding: 4px 6px;
            text-align: center;
            color: #000000;
            background: #ffffff;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
            min-width: 85px;
        }
        
        .excel-daily-scroll {
            overflow-x: auto;
            margin-top: 20px;
            border: 1px solid #95b3d7;
            padding: 8px;
            background: #ffffff;
        }
        
        .excel-daily-table {
            border-collapse: collapse;
            width: 1500px;
            min-width: 1500px;
            font-size: 11px;
            font-family: 'Segoe UI', Arial, sans-serif;
        }
        
        .excel-daily-table th,
        .excel-daily-table td {
            border: 1px solid #95b3d7;
            padding: 4px 6px;
            text-align: center;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }
        
        .excel-daily-table th {
            font-size: 10px;
            font-weight: 700;
        }
        
        .excel-daily-date {
            text-align: left !important;
            font-weight: 600;
            min-width: 220px;
        }
        
        .excel-daily-sema {
            background: #f4b084 !important;
            color: #000000;
        }
        
        .excel-daily-trz {
            background: #d8c1dd !important;
            color: #000000;
        }
        
        .excel-daily-pills {
            background: #9bc2e6 !important;
            color: #000000;
        }
        
        .excel-daily-test {
            background: #c5e0b4 !important;
            color: #000000;
        }

        .excel-daily-other {
            background: #fff2cc !important;
            color: #000000;
        }

        .excel-daily-other-white {
            background: #ffffff !important;
            color: #000000;
        }
        
        .excel-daily-payment {
            background: #d9e1f2 !important;
            color: #000000;
            font-weight: 600;
        }
        
        .excel-daily-total-row td {
            color: #c00000;
            font-weight: 700;
        }
        
        .excel-daily-average-row td {
            color: #c00000;
            font-style: italic;
            font-weight: 600;
        }
        
        .excel-label-cell {
            text-align: left;
            font-weight: 700;
        }
        
        .excel-value-cell {
            text-align: right;
            font-weight: 600;
        }
        
        .excel-orange-cell { background: #f4b084 !important; }
        .excel-blue-cell { background: #9bc2e6 !important; }
        .excel-pink-cell { background: #dab6d8 !important; }
        .excel-yellow-cell { background: #fff2cc !important; }
        .excel-green-cell { background: #92d050 !important; font-weight: 700; }
        
        .dcr-grid-table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
            font-size: 11px;
            font-family: 'Segoe UI', Arial, sans-serif;
            border: 1px solid #d0d7e5;
        }
        
        .dcr-grid-table th,
        .dcr-grid-table td {
            border: 1px solid #d0d7e5;
            padding: 4px 6px;
            font-size: 11px;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
            min-width: 0;
            width: auto;
            text-align: center;
        }
        
        .dcr-grid-table thead th {
            background: #d9e1f2;
            color: #000000;
            font-weight: 700;
            text-align: center;
            font-size: 10px;
            padding: 6px 4px;
            border: 1px solid #95b3d7;
        }
        
        .dcr-grid-table thead tr:first-child th {
            background: #b8cce4;
            border: 1px solid #95b3d7;
        }
        
        /* Header color accents matching Excel */
        .dcr-grid-table th.header-b12 { background: #9bc2e6 !important; }
        .dcr-grid-table th.header-sema { background: #f4b084 !important; }
        .dcr-grid-table th.header-trz { background: #d8c1dd !important; }
        .dcr-grid-table th.header-card { background: #b8cce4 !important; }
        
        /* Patient name column */
        .dcr-grid-table tbody td:nth-child(2) {
            text-align: center;
            font-weight: 600;
            color: #000000;
        }
        
        /* Sign in # column - center */
        .dcr-grid-table tbody td:first-child {
            text-align: center;
            font-weight: 600;
            color: #000000;
        }
        
        /* Action column - center */
        .dcr-grid-table tbody td:nth-child(3) {
            text-align: center;
        }
        
        /* All data columns centered */
        .dcr-grid-table tbody td:nth-child(n+4) {
            text-align: center;
        }
        
        .dcr-grid-table tbody td {
            background: #ffffff;
            color: #000000;
        }
        
        .dcr-grid-table tbody tr:nth-child(even) td {
            background: #f2f2f2;
        }
        
        .dcr-grid-table tbody tr:nth-child(even) td.col-card-punch,
        .dcr-grid-table tbody tr:nth-child(even) td.col-th,
        .dcr-grid-table tbody tr:nth-child(even) td.col-injection,
        .dcr-grid-table tbody tr:nth-child(even) td.col-count {
            background: #fff2cc;
        }
        
        .dcr-grid-table tfoot td {
            background: #d9e1f2;
            font-weight: 700;
            border: 1px solid #95b3d7;
            text-align: center;
        }
        
        .amount-cell {
            text-align: center !important;
            font-weight: 400;
            color: #000000;
        }
        
        .number-cell {
            text-align: center !important;
            font-weight: 400;
            color: #000000;
        }

        .notes-cell {
            text-align: left !important;
            white-space: normal !important;
            word-break: break-word;
            vertical-align: top;
            min-width: 220px !important;
            max-width: 320px;
        }

        .notes-header {
            min-width: 220px !important;
        }
        
        .status-indicator {
            display: inline-block;
            min-width: 20px;
            padding: 2px 4px;
            text-align: center;
            font-size: 11px;
            font-weight: 700;
            color: #000000;
            background: transparent;
            border: none;
        }
        
        .status-new {
            color: #000000;
        }
        
        .status-follow {
            color: #000000;
        }
        
        .status-shot {
            color: #000000;
        }
        
        /* Breakdown rows styling */
        .breakdown-row {
            background: #d9e1f2 !important;
            font-weight: 700;
            border: 1px solid #95b3d7;
        }
        
        .breakdown-label {
            text-align: left !important;
            font-weight: 700;
        }
        
        .dcr-summary-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            font-size: 12px;
            box-shadow: inset 0 0 0 1px #dde2ea;
        }
        
        .dcr-summary-table th,
        .dcr-summary-table td {
            border: 1px solid #d7dce3;
            padding: 6px 10px;
            text-align: center;
            font-variant-numeric: tabular-nums;
        }
        
        .dcr-summary-table thead th {
            background: #edf1f7;
            color: #2f3e4d;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-size: 11px;
            font-weight: 600;
        }
        
        .dcr-summary-table tbody td:first-child {
            text-align: left;
            font-weight: 600;
            color: #2f3e4d;
        }
        
        .dcr-summary-table tfoot td {
            background: #f4f6fb;
            font-weight: 600;
        }
        
        .badge { 
            padding: 3px 8px; 
            border-radius: 3px; 
            font-size: 11px; 
            font-weight: 500;
            display: inline-block;
        }
        
        .badge-new { 
            background: #d4edda; 
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .badge-returning { 
            background: #d1ecf1; 
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .badge-purchase { 
            background: #d4edda; 
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .badge-dispense { 
            background: #d1ecf1; 
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .badge-administer { 
            background: #fff3cd; 
            color: #856404;
            border: 1px solid #ffeeba;
        }
        
        .amount-positive { 
            color: #28a745; 
            font-weight: 600;
        }
        
        .amount-negative { 
            color: #dc3545; 
            font-weight: 600;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e0e0e0;
            width: 100%;
        }
        
        .section-title-flex {
            font-size: 16px; 
            font-weight: 600; 
            color: #333;
            margin: 0;
            flex: 0 0 auto;
        }
        
        .download-btn {
            padding: 6px 12px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            color: #333;
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
            display: inline-block;
            margin-left: auto;
            flex-shrink: 0;
        }
        
        .download-btn:hover {
            background: #f8f9fa;
            border-color: #007bff;
            color: #007bff;
        }
        
        .download-btn:before {
            content: '⬇ ';
        }
        
        @media print {
            @page {
                size: A4 landscape;
                margin: 10mm;
            }
            
            * {
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            
            body { 
                background: white !important; 
                padding: 0 !important; 
                margin: 0 !important;
                font-size: 9pt !important;
            }
            
            .dcr-controls, 
            .button-group, 
            .download-btn,
            .btn,
            button { 
                display: none !important; 
            }
            
            .dcr-container { 
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 !important;
                max-width: 100% !important;
            }
            
            .excel-container {
                padding: 0 !important;
                page-break-inside: avoid;
            }
            
            .dcr-grid-table {
                font-size: 7pt !important;
                width: 100% !important;
                border-collapse: collapse !important;
                table-layout: auto !important;
                page-break-inside: auto;
            }
            
            .dcr-grid-table th,
            .dcr-grid-table td {
                padding: 2px 3px !important;
                font-size: 7pt !important;
                border: 0.5pt solid #000 !important;
                min-width: auto !important;
                white-space: nowrap !important;
                overflow: visible !important;
            }
            
            .dcr-grid-table thead {
                display: table-header-group !important;
            }
            
            .dcr-grid-table tfoot {
                display: table-footer-group !important;
            }
            
            .dcr-grid-table tr {
                page-break-inside: avoid;
            }
            
            .excel-daily-scroll,
            .excel-month-scroll {
                overflow: visible !important;
                page-break-inside: avoid;
            }
            
            .excel-daily-table,
            .excel-month-table {
                font-size: 8pt !important;
                width: 100% !important;
                page-break-inside: avoid;
            }
            
            .excel-daily-table th,
            .excel-daily-table td,
            .excel-month-table th,
            .excel-month-table td {
                padding: 3px 4px !important;
                font-size: 8pt !important;
                border: 0.5pt solid #000 !important;
            }
            
            .excel-top-summary-row,
            .excel-facility-title-row {
                page-break-after: avoid;
            }
            
            .section {
                page-break-inside: avoid;
                margin-bottom: 5mm;
            }
            
            /* Ensure colors print */
            .header-b12,
            .header-sema,
            .header-trz,
            .header-card {
                background-color: #4472c4 !important;
                color: white !important;
                -webkit-print-color-adjust: exact !important;
            }
            
            .excel-orange-cell,
            .excel-daily-sema {
                background-color: #f4b084 !important;
                -webkit-print-color-adjust: exact !important;
            }
            
            .excel-blue-cell,
            .excel-daily-pills {
                background-color: #8faadc !important;
                -webkit-print-color-adjust: exact !important;
            }
            
            .excel-pink-cell,
            .excel-daily-trz {
                background-color: #d9d2e9 !important;
                -webkit-print-color-adjust: exact !important;
            }
            
            .excel-yellow-cell,
            .excel-daily-test {
                background-color: #fff2cc !important;
                -webkit-print-color-adjust: exact !important;
            }
            
            .excel-green-cell {
                background-color: #c6e0b4 !important;
                -webkit-print-color-adjust: exact !important;
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .controls-grid { grid-template-columns: 1fr; }
            .button-group { flex-direction: column; }
            .btn { width: 100%; }
        }
        
        .excel-daily-test {
            background: #c5e0b4 !important;
            color: #000000;
        }
        
        .excel-month-scroll {
            overflow-x: auto;
            margin-top: 20px;
            border: 1px solid #95b3d7;
            padding: 8px;
            background: #ffffff;
        }
        
        .excel-month-table {
            border-collapse: collapse;
            width: 1400px;
            min-width: 1400px;
            font-size: 11px;
            font-family: 'Segoe UI', Arial, sans-serif;
        }
        
        .excel-month-table th,
        .excel-month-table td {
            border: 1px solid #95b3d7;
            padding: 4px 6px;
            text-align: right;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }
        
        .excel-month-table th {
            background: #f2f2a0;
            color: #000000;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }
        
        .excel-month-table td:first-child,
        .excel-month-table th:first-child {
            text-align: left;
        }
        
        .excel-month-table td:nth-child(2),
        .excel-month-table th:nth-child(2) {
            text-align: center;
        }
        
        .excel-month-running-row td {
            background: #ffe599;
            font-weight: 700;
        }
        
        .excel-month-total-col {
            background: #b7dee8;
            font-weight: 700;
        }
        
        .excel-month-table tbody tr:nth-child(odd):not(.excel-month-running-row) td {
            background: #fff2cc;
        }
        
        .excel-month-table tbody tr:nth-child(even):not(.excel-month-running-row) td {
            background: #fef6d9;
        }
        
        .excel-month-table td {
            color: #000000;
        }
        
        .excel-month-table td.amount-zero {
            color: #444444;
        }
        
        .excel-month-table td.total-cell {
            font-weight: 700;
        }
        
        .excel-month-table td.running-total-cell {
            font-weight: 600;
            color: #c00000;
        }
        
        .excel-daily-total-row td {
            color: #c00000;
            font-weight: 700;
        }
        
        /* Tab Styles */
        .dcr-tabs {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 4px;
            border-bottom: 2px solid #95b3d7;
            margin-bottom: 20px;
            background: #ffffff;
            overflow-x: auto;
            padding-right: 4px;
        }
        
        .dcr-tab {
            padding: 12px 24px;
            background: #f2f2f2;
            border: 1px solid #95b3d7;
            border-bottom: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            color: #333;
            margin-right: 0;
            border-top-left-radius: 4px;
            border-top-right-radius: 4px;
            transition: all 0.2s;
            flex: 0 0 auto;
            white-space: nowrap;
        }
        
        .dcr-tab:hover {
            background: #e0e0e0;
        }
        
        .dcr-tab.active {
            background: #ffffff;
            color: #000;
            border-bottom: 2px solid #ffffff;
            margin-bottom: -2px;
            position: relative;
            z-index: 1;
        }
        
        .dcr-tab-content {
            display: none;
        }
        
        .dcr-tab-content.active {
            display: block;
        }
    </style>
</head>
<body>
    <div class="dcr-container">
        <!-- Header -->
        <div class="dcr-header">
            <h1 class="dcr-title">DCR - Daily Collection Report</h1>
            <p class="dcr-subtitle">Financial & Operations Summary</p>
        </div>
        
        <!-- Controls -->
        <div class="dcr-controls">
            <form method="POST" action="" autocomplete="off">
                <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                <?php if ($email_status): ?>
                    <div class="alert <?php echo !empty($email_status['success']) ? 'alert-success' : 'alert-danger'; ?>" style="margin-bottom: 15px;">
                        <?php echo text($email_status['message'] ?? ''); ?>
                    </div>
                <?php endif; ?>
                
                <div class="controls-grid">
                    <div class="control-group" id="form_date_group" style="<?php echo $form_report_type === 'monthly' ? 'display:none;' : ''; ?>">
                        <label for="form_date">From Date:</label>
                        <input type="date" id="form_date" name="form_date" value="<?php echo $form_date; ?>">
                    </div>
                    
                    <div class="control-group" id="form_to_date_group" style="<?php echo $form_report_type === 'monthly' ? 'display:none;' : ''; ?>">
                        <label for="form_to_date">To Date:</label>
                        <input type="date" id="form_to_date" name="form_to_date" value="<?php echo $form_to_date; ?>">
                    </div>
                    
                    <div class="control-group" id="form_month_group" style="<?php echo $form_report_type === 'monthly' ? '' : 'display:none;'; ?>">
                         <label for="form_month">Month:</label>
                         <input type="month" id="form_month" name="form_month" value="<?php echo attr($form_month ?: date('Y-m')); ?>">
                     </div>
                    
                    <div class="control-group">
                        <label for="form_facility">Facility:</label>
                        <?php dropdown_facility($form_facility, 'form_facility', true); ?>
                    </div>
                    
                    <div class="control-group">
                        <label for="form_medicine">Medicine Filter:</label>
                        <select id="form_medicine" name="form_medicine" class="form-control">
                            <option value="">-- All Medicines --</option>
                            <?php foreach ($available_medicines as $medicine): ?>
                                <option value="<?php echo attr($medicine['drug_id']); ?>" 
                                        <?php echo $form_medicine == $medicine['drug_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($medicine['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="control-group">
                        <label for="form_payment_method">Payment Method:</label>
                        <select id="form_payment_method" name="form_payment_method" class="form-control">
                            <option value="">-- All Payment Methods --</option>
                            <option value="credit_card" <?php echo $form_payment_method === 'credit_card' ? 'selected' : ''; ?>>Credit Card</option>
                            <option value="cash" <?php echo $form_payment_method === 'cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="credit" <?php echo $form_payment_method === 'credit' ? 'selected' : ''; ?>>Credit</option>
                            <option value="check" <?php echo $form_payment_method === 'check' ? 'selected' : ''; ?>>Check</option>
                        </select>
                    </div>
                    
                    <div class="control-group">
                        <label for="form_report_type">Report Type:</label>
                        <select id="form_report_type" name="form_report_type">
                            <option value="daily" <?php echo $form_report_type === 'daily' ? 'selected' : ''; ?>>Daily Breakdown</option>
                            <option value="monthly" <?php echo $form_report_type === 'monthly' ? 'selected' : ''; ?>>Monthly Breakdown</option>
                         </select>
                    </div>

                    <div class="control-group">
                        <label for="form_email_to">Email Report To:</label>
                        <select id="form_email_to" name="form_email_to[]" class="form-control" multiple size="6">
                            <?php foreach ($dcr_email_recipients as $recipient_email): ?>
                                <option value="<?php echo attr($recipient_email); ?>" <?php echo in_array($recipient_email, $form_email_to, true) ? 'selected' : ''; ?>>
                                    <?php echo text($recipient_email); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="display:block; margin-top:6px; color:#667085;">Hold Ctrl on Windows or Command on Mac to select multiple email recipients.</small>
                    </div>
                </div>
                
                <div class="button-group">
                    <button type="submit" class="btn btn-primary">Generate Report</button>
                    <button type="submit" name="form_export" value="csv" class="btn btn-success">Export to CSV</button>
                    <button type="submit" name="form_export" value="email" class="btn btn-info">Email Report</button>
                    <button type="submit" name="form_export" value="download" class="btn btn-info">Download Report</button>
                </div>
            </form>
        </div>
        
        <!-- Status Banner -->
        <div style="background: #f8f9fa; border-top: 1px solid #e0e0e0; border-bottom: 1px solid #e0e0e0; padding: 12px 30px; margin: 0;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; font-size: 13px;">
                <div style="color: #666;">
                    Generated: <?php echo date('M d, Y \a\t H:i:s', strtotime($report_generated_at)); ?>
                </div>
                <div style="display: flex; gap: 20px; color: #333;">
                    <span><strong><?php echo count($report_data['transactions']); ?></strong> transactions</span>
                    <span><strong><?php echo count($report_data['unique_patients']); ?></strong> patients</span>
                    <span><strong><?php echo count($report_data['medicines']); ?></strong> medicines</span>
                </div>
            </div>
            <div style="margin-top: 4px; font-size: 12px; color: #666;">
                <strong>Date Range:</strong> <?php echo $date_formatted; ?> to <?php echo $to_date_formatted; ?>
                <?php if ($form_facility): ?>
                    | <strong>Facility:</strong> <?php echo htmlspecialchars($facility_display_name); ?>
                <?php else: ?>
                    | <strong>Facility:</strong> All Facilities
                <?php endif; ?>
                <?php if ($form_medicine): ?>
                    | <strong>Medicine:</strong> <?php 
                        try {
                            $stmt = sqlStatement("SELECT name FROM drugs WHERE drug_id = ?", [$form_medicine]);
                            if ($stmt) {
                                $med_name = sqlFetchArray($stmt);
                                if ($med_name && isset($med_name['name'])) {
                                    echo htmlspecialchars($med_name['name']);
                                } else {
                                    echo 'Unknown';
                                }
                            } else {
                                echo 'Unknown';
                            }
                        } catch (Exception $e) {
                            echo 'Unknown';
                        }
                    ?>
                <?php else: ?>
                    | <strong>Medicine:</strong> All Medicines
                <?php endif; ?>
                <?php if ($form_payment_method): ?>
                    | <strong>Payment:</strong> <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $form_payment_method))); ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Content -->
        <div class="dcr-content">
            <!-- Revenue Stats (Hidden for Excel view) -->
            <div class="stats-grid" style="display: none;">
                <div class="stat-card revenue">
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-value">$<?php echo number_format($report_data['total_revenue'], 2); ?></div>
                </div>
                
                <div class="stat-card revenue">
                    <div class="stat-label">Net Revenue</div>
                    <div class="stat-value">$<?php echo number_format($report_data['net_revenue'], 2); ?></div>
                    <div style="font-size: 11px; margin-top: 6px; color: #666;">
                        After Refunds
                    </div>
                </div>
                
                <div class="stat-card patients">
                    <div class="stat-label">Total Patients</div>
                    <div class="stat-value"><?php echo count($report_data['unique_patients']); ?></div>
                    <div style="font-size: 11px; margin-top: 6px; color: #666;">
                        New: <?php echo $report_data['new_patients']; ?> | 
                        Returning: <?php echo $report_data['returning_patients']; ?>
                    </div>
                </div>
                
                <div class="stat-card transactions">
                    <div class="stat-label">Transactions</div>
                    <div class="stat-value"><?php echo count($report_data['transactions']); ?></div>
                </div>
                
                <div class="stat-card dispense">
                    <div class="stat-label">Units Dispensed</div>
                    <div class="stat-value"><?php echo $report_data['marketplace_quantity']; ?></div>
                </div>
                
                <div class="stat-card administer">
                    <div class="stat-label">Units Administered</div>
                    <div class="stat-value"><?php echo $report_data['administered_quantity']; ?></div>
                </div>
            </div>
            
            <!-- Payment Method Breakdown (Hidden for Excel view) -->
            <?php if (!empty($report_data['payment_methods']) && !$form_payment_method): ?>
            <div class="section" style="display: none;">
                <div class="section-header">
                    <h2 class="section-title-flex">Payment Method Breakdown</h2>
                    <button class="download-btn" onclick="downloadPaymentMethodsCSV()">Download CSV</button>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <?php foreach ($report_data['payment_methods'] as $method_key => $method_data): ?>
                    <div style="background: #fff; padding: 15px; border: 1px solid #e0e0e0; border-radius: 4px;">
                        <div style="font-size: 12px; color: #666; margin-bottom: 8px; text-transform: uppercase; font-weight: 500;">
                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $method_key))); ?>
                        </div>
                        <div style="font-size: 24px; font-weight: 600; color: #007bff;">
                            $<?php echo number_format($method_data['total_revenue'], 2); ?>
                        </div>
                        <div style="font-size: 11px; margin-top: 6px; color: #666;">
                            <?php echo $method_data['transaction_count']; ?> transactions
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Additional Activities (Hidden for Excel view) -->
            <?php if (($report_data['transaction_counts']['medicine_switch'] ?? 0) > 0 || 
                      ($report_data['transaction_counts']['refund'] ?? 0) > 0 || 
                      ($report_data['transaction_counts']['credit_transfer'] ?? 0) > 0 ||
                      $report_data['refunds_total'] > 0 ||
                      $report_data['credits_transferred'] > 0): ?>
            <div class="section" style="display: none;">
                <h2 class="section-title">Additional Activities</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <?php if (($report_data['transaction_counts']['medicine_switch'] ?? 0) > 0): ?>
                    <div style="background: #fff; padding: 15px; border: 1px solid #e0e0e0; border-radius: 4px;">
                        <div style="font-size: 12px; color: #666; margin-bottom: 8px; text-transform: uppercase; font-weight: 500;">Medicine Switches</div>
                        <div style="font-size: 24px; font-weight: 600; color: #6c757d;"><?php echo $report_data['transaction_counts']['medicine_switch']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (($report_data['transaction_counts']['refund'] ?? 0) > 0 || $report_data['refunds_total'] > 0): ?>
                    <div style="background: #fff; padding: 15px; border: 1px solid #e0e0e0; border-radius: 4px;">
                        <div style="font-size: 12px; color: #666; margin-bottom: 8px; text-transform: uppercase; font-weight: 500;">Refunds Processed</div>
                        <div style="font-size: 24px; font-weight: 600; color: #dc3545;"><?php echo $report_data['transaction_counts']['refund'] ?? 0; ?></div>
                        <div style="font-size: 11px; margin-top: 6px; color: #666;">
                            Total: $<?php echo number_format($report_data['refunds_total'], 2); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (($report_data['transaction_counts']['credit_transfer'] ?? 0) > 0 || $report_data['credits_transferred'] > 0): ?>
                    <div style="background: #fff; padding: 15px; border: 1px solid #e0e0e0; border-radius: 4px;">
                        <div style="font-size: 12px; color: #666; margin-bottom: 8px; text-transform: uppercase; font-weight: 500;">Credit Transfers</div>
                        <div style="font-size: 24px; font-weight: 600; color: #6610f2;"><?php echo $report_data['transaction_counts']['credit_transfer'] ?? 0; ?></div>
                        <div style="font-size: 11px; margin-top: 6px; color: #666;">
                            Total: $<?php echo number_format($report_data['credits_transferred'], 2); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Medicine Breakdown (Hidden for Excel view) -->
            <div class="section" style="display: none;">
                <div class="section-header">
                    <h2 class="section-title-flex">Medicine Sales Report</h2>
                    <button class="download-btn" onclick="downloadMedicinesCSV()">Download CSV</button>
                </div>
                <table class="data-table">
                    <thead>
                        <tr class="excel-top-summary-row">
                            <th colspan="5"></th>
                            <th colspan="7" class="excel-top-summary-cell">
                                Total REV
                                <span class="excel-top-summary-value">$<?php echo number_format($report_data['total_revenue'], 2); ?></span>
                            </th>
                            <th colspan="6" class="excel-top-summary-cell">
                                Shot Cards
                                <span class="excel-top-summary-value"><?php echo number_format((float)($glpCombined['cards_sold'] ?? 0), 0); ?></span>
                            </th>
                            <th colspan="6" class="excel-top-summary-cell">
                                SG Revenue
                                <span class="excel-top-summary-value">$<?php echo number_format((float)($sema['injection_amount'] ?? 0), 2); ?></span>
                            </th>
                            <th colspan="7"></th>
                        </tr>
                        <tr class="excel-facility-title-row">
                            <th colspan="9" class="excel-facility-title-cell"><?php echo htmlspecialchars($facility_display_name); ?></th>
                            <th colspan="22" class="excel-title-cell">PATIENT DCR</th>
                        </tr>
                        <tr>
                            <th>Medicine Name</th>
                            <th>Category</th>
                            <th>Total Sold</th>
                            <th>Dispensed</th>
                            <th>Administered</th>
                            <th>Revenue</th>
                            <th>Patients</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data['medicines'] as $medicine): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($medicine['name']); ?></strong></td>
                            <td><span class="badge badge-purchase"><?php echo htmlspecialchars($medicine['category']); ?></span></td>
                            <td><?php echo $medicine['total_quantity']; ?></td>
                            <td><?php echo $medicine['total_dispensed']; ?></td>
                            <td><?php echo $medicine['total_administered']; ?></td>
                            <td class="amount-positive">$<?php echo number_format($medicine['total_revenue'], 2); ?></td>
                            <td><?php echo count($medicine['patients']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Excel-Style Patient Summary -->
            <div class="excel-container">
                <?php
                    $blank_take_home_columns = shouldBlankTakeHomeColumns($form_facility, $facility_display_name);
                    $grossCountDrilldownMap = buildGrossBreakdownDrilldownMap($report_data['patient_rows'] ?? []);
                    $shotTrackerDrilldownMap = buildShotTrackerDrilldownMap($report_data['patient_rows'] ?? []);
                    
                    // Breakdown summaries for Sema, TRZ, Pills, etc.
                    $bd = $report_data['breakdown'] ?? initializeBreakdownStructure();
                    $sema = $bd['sema'];
                    $tirz = $bd['tirz'];
                    $pills = $bd['pills'];
                    $other = $bd['other'] ?? [
                        'new_patients' => 0,
                        'follow_patients' => 0,
                        'total_patients' => 0,
                        'new_revenue' => 0,
                        'follow_revenue' => 0,
                        'total_revenue' => 0,
                    ];
                    $testosterone = $bd['testosterone'] ?? [
                        'new_patients' => 0,
                        'follow_patients' => 0,
                        'total_patients' => 0,
                        'new_revenue' => 0,
                        'follow_revenue' => 0,
                        'total_revenue' => 0,
                        'cards_sold' => 0,
                    ];
                    
                    $glpCombined = [
                        'new_patients' => ($sema['new_patients'] ?? 0) + ($tirz['new_patients'] ?? 0),
                        'new_revenue' => ($sema['new_revenue'] ?? 0.0) + ($tirz['new_revenue'] ?? 0.0),
                        'follow_patients' => ($sema['follow_patients'] ?? 0) + ($tirz['follow_patients'] ?? 0),
                        'follow_revenue' => ($sema['follow_revenue'] ?? 0.0) + ($tirz['follow_revenue'] ?? 0.0),
                        'total_patients' => ($sema['total_patients'] ?? 0) + ($tirz['total_patients'] ?? 0),
                        'total_revenue' => ($sema['total_revenue'] ?? 0.0) + ($tirz['total_revenue'] ?? 0.0),
                        'cards_sold' => $bd['sema_trz_cards_sold'] ?? 0
                    ];
                    
                    $overallTotals = [
                        'new_patients' => ($sema['new_patients'] ?? 0) + ($tirz['new_patients'] ?? 0) + ($pills['new_patients'] ?? 0) + ($testosterone['new_patients'] ?? 0) + ($other['new_patients'] ?? 0),
                        'new_revenue' => ($sema['new_revenue'] ?? 0.0) + ($tirz['new_revenue'] ?? 0.0) + ($pills['new_revenue'] ?? 0.0) + ($testosterone['new_revenue'] ?? 0.0) + ($other['new_revenue'] ?? 0.0),
                        'follow_patients' => ($sema['follow_patients'] ?? 0) + ($tirz['follow_patients'] ?? 0) + ($pills['follow_patients'] ?? 0) + ($testosterone['follow_patients'] ?? 0) + ($other['follow_patients'] ?? 0),
                        'follow_revenue' => ($sema['follow_revenue'] ?? 0.0) + ($tirz['follow_revenue'] ?? 0.0) + ($pills['follow_revenue'] ?? 0.0) + ($testosterone['follow_revenue'] ?? 0.0) + ($other['follow_revenue'] ?? 0.0),
                        'total_patients' => $bd['total_patients'] ?? (($sema['total_patients'] ?? 0) + ($tirz['total_patients'] ?? 0) + ($pills['total_patients'] ?? 0) + ($testosterone['total_patients'] ?? 0) + ($other['total_patients'] ?? 0)),
                        'total_revenue' => $bd['total_revenue'] ?? (($sema['total_revenue'] ?? 0.0) + ($tirz['total_revenue'] ?? 0.0) + ($pills['total_revenue'] ?? 0.0) + ($testosterone['total_revenue'] ?? 0.0) + ($other['total_revenue'] ?? 0.0))
                    ];
                ?>
                
                <!-- Tab Navigation -->
                <div class="dcr-tabs">
                    <div class="dcr-tab active" onclick="switchDCRTab('patient-dcr', this)">Patient DCR</div>
                    <div class="dcr-tab" onclick="switchDCRTab('gross-count', this)">Gross Patient Count</div>
                    <div class="dcr-tab" onclick="switchDCRTab('deposit', this)">Deposit</div>
                    <div class="dcr-tab" onclick="switchDCRTab('revenue-breakdown', this)">Revenue Breakdown</div>
                    <div class="dcr-tab" onclick="switchDCRTab('shot-tracker', this)">Shot Tracker</div>
                </div>
                
                <!-- Tab 1: Patient DCR -->
                <div id="tab-patient-dcr" class="dcr-tab-content active">
                <?php
                    $gridTotals = [
                        'ov' => 0,
                        'ov_prepaid' => false,
                        'bw' => 0,
                        'lipo_admin' => 0,
                        'lipo_home' => 0,
                        'lipo_amount' => 0,
                        'lipo_purchased' => 0,
                        'lipo_prepaid' => false,
                        'sema_admin' => 0,
                        'sema_home' => 0,
                        'sema_amount' => 0,
                        'sema_purchased' => 0,
                        'sema_prepaid' => false,
                        'tirz_admin' => 0,
                        'tirz_home' => 0,
                        'tirz_amount' => 0,
                        'tirz_purchased' => 0,
                        'tirz_prepaid' => false,
                        'afterpay' => 0,
                        'supp_amount' => 0,
                        'supp_count' => 0,
                        'drink_amount' => 0,
                        'drink_count' => 0,
                        'subtotal' => 0,
                        'tax' => 0,
                        'refund' => 0,
                        'credit' => 0,
                        'total' => 0,
                    ];
                ?>
                <div class="excel-daily-scroll">
                <table class="dcr-grid-table">
                    <thead>
                        <tr class="excel-top-summary-row">
                            <th colspan="5"></th>
                            <th colspan="7" class="excel-top-summary-cell">
                                Total REV
                                <span class="excel-top-summary-value">$<?php echo number_format($report_data['total_revenue'], 2); ?></span>
                            </th>
                            <th colspan="6" class="excel-top-summary-cell">
                                Shot Cards
                                <span class="excel-top-summary-value"><?php echo number_format((float)($glpCombined['cards_sold'] ?? 0), 0); ?></span>
                            </th>
                            <th colspan="6" class="excel-top-summary-cell">
                                SG Revenue
                                <span class="excel-top-summary-value">$<?php echo number_format((float)($sema['injection_amount'] ?? 0), 2); ?></span>
                            </th>
                            <th colspan="7"></th>
                        </tr>
                        <tr class="excel-facility-title-row">
                            <th colspan="9" class="excel-facility-title-cell"><?php echo htmlspecialchars($facility_display_name); ?></th>
                            <th colspan="22" class="excel-title-cell">PATIENT DCR</th>
                        </tr>
                        <tr>
                            <th rowspan="2">Sign In #</th>
                            <th rowspan="2">Patient Name</th>
                            <th rowspan="2">Action</th>
                            <th rowspan="2">OV</th>
                            <th rowspan="2">BW</th>
                            <th colspan="4" class="header-b12">B12 Lipo Injection</th>
                            <th colspan="5" class="header-sema">Semaglutide</th>
                            <th colspan="5" class="header-trz">Tirzepatide</th>
                            <th rowspan="2">AfterPay Fee</th>
                            <th colspan="2">Supplements</th>
                            <th colspan="2">Protein Shakes</th>
                            <th rowspan="2">Subtotal</th>
                            <th rowspan="2">Tax</th>
                            <th rowspan="2">Credits</th>
                            <th rowspan="2">Total</th>
                            <th rowspan="2" class="notes-header">Notes</th>
                        </tr>
                        <tr>
                            <th class="header-card">Card Punch #</th>
                            <th class="header-card">TH</th>
                            <th class="header-b12">B12 Lipo Injection</th>
                            <th class="header-b12">#</th>
                            <th class="header-sema">Card Punch #</th>
                            <th class="header-sema">TH</th>
                            <th class="header-sema">Sema Injection</th>
                            <th class="header-sema">#</th>
                            <th class="header-sema">DOSE (mg)</th>
                            <th class="header-trz">Card Punch #</th>
                            <th class="header-trz">TH</th>
                            <th class="header-trz">TRZ Injection</th>
                            <th class="header-trz">#</th>
                            <th class="header-trz">DOSE (mg)</th>
                            <th>Amount</th>
                            <th>#</th>
                            <th>Amount</th>
                            <th>#</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report_data['patient_rows'])): ?>
                            <tr>
                                <td colspan="29" style="text-align:center; color:#6c757d; font-style: italic;">
                                    No patient data available for the selected filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($report_data['patient_rows'] as $patientIndex => $patient): ?>
                                <?php
                                    $details = $patient['details'] ?? initializePatientDetails();
                                    $netAmounts = getPatientRevenueGridAmounts($details);
                                    $netSubtotal = getPatientRevenueGridSubtotal($details, $netAmounts);
                                    $actionCode = $details['visit_type'] ?? '-';
                                    $statusClass = ($actionCode === 'N')
    ? 'status-new'
    : (($actionCode === 'F') ? 'status-follow' : 'status-none');
                                    $semaDose = !empty($details['sema_doses']) ? implode(', ', $details['sema_doses']) : '';
                                    $tirzDose = !empty($details['tirz_doses']) ? implode(', ', $details['tirz_doses']) : '';
                                    $lipoCardPunchDisplay = resolvePatientCardPunchDisplay($details, 'lipo', 5);
                                    $semaCardPunchDisplay = resolvePatientCardPunchDisplay($details, 'sema', 4);
                                    $tirzCardPunchDisplay = resolvePatientCardPunchDisplay($details, 'tirz', 4);
                                    $lipoGroupActive = dcrPatientGroupHasActivity($details, $netAmounts, 'lipo', $lipoCardPunchDisplay);
                                    $semaGroupActive = dcrPatientGroupHasActivity($details, $netAmounts, 'sema', $semaCardPunchDisplay);
                                    $tirzGroupActive = dcrPatientGroupHasActivity($details, $netAmounts, 'tirz', $tirzCardPunchDisplay);
                                    $lipoRowActive = dcrPatientGroupHasRowData($details, $netAmounts, 'lipo', $lipoCardPunchDisplay);
                                    $semaRowActive = dcrPatientGroupHasRowData($details, $netAmounts, 'sema', $semaCardPunchDisplay);
                                    $tirzRowActive = dcrPatientGroupHasRowData($details, $netAmounts, 'tirz', $tirzCardPunchDisplay);
                                    $afterpayGroupActive = dcrPatientGroupHasActivity($details, $netAmounts, 'afterpay');
                                    $supplementsGroupActive = dcrPatientGroupHasActivity($details, $netAmounts, 'supplements');
                                    $proteinGroupActive = dcrPatientGroupHasActivity($details, $netAmounts, 'protein');
                                    $lipoTakeHomeDisplay = $blank_take_home_columns ? '' : dcrDisplayNumberForGroup($lipoRowActive, $details['lipo_takehome_count'] ?? 0);
                                    $semaTakeHomeDisplay = $blank_take_home_columns ? '' : dcrDisplayNumberForGroup($semaRowActive, $details['sema_takehome_count'] ?? 0);
                                    $tirzTakeHomeDisplay = $blank_take_home_columns ? '' : dcrDisplayNumberForGroup($tirzRowActive, $details['tirz_takehome_count'] ?? 0);
                                    $showOvZero = shouldShowZeroDcrOfficeVisitAmount($details);
                                    $patientNotesDisplay = buildDcrPatientGridNotes($details, $netAmounts);
                                    
                                    $gridTotals['ov'] += floatval($netAmounts['office_visit'] ?? 0);
                                    $gridTotals['ov_prepaid'] = !empty($gridTotals['ov_prepaid']) || $showOvZero;
                                    $gridTotals['bw'] += floatval($netAmounts['lab'] ?? 0);
                                    $gridTotals['lipo_admin'] += floatval($details['lipo_admin_count'] ?? 0);
                                    $gridTotals['lipo_home'] += $blank_take_home_columns ? 0 : floatval($details['lipo_takehome_count'] ?? 0);
                                    $gridTotals['lipo_amount'] += floatval($netAmounts['lipo'] ?? 0);
                                    $gridTotals['lipo_purchased'] += floatval($details['lipo_purchased_count'] ?? 0);
                                    $gridTotals['lipo_prepaid'] = $gridTotals['lipo_prepaid'] || $lipoGroupActive;
                                    $gridTotals['sema_admin'] += floatval($details['sema_admin_count'] ?? 0);
                                    $gridTotals['sema_home'] += $blank_take_home_columns ? 0 : floatval($details['sema_takehome_count'] ?? 0);
                                    $gridTotals['sema_amount'] += floatval($netAmounts['sema'] ?? 0);
                                    $gridTotals['sema_purchased'] += floatval($details['sema_purchased_count'] ?? 0);
                                    $gridTotals['sema_prepaid'] = $gridTotals['sema_prepaid'] || $semaGroupActive;
                                    $gridTotals['tirz_admin'] += floatval($details['tirz_admin_count'] ?? 0);
                                    $gridTotals['tirz_home'] += $blank_take_home_columns ? 0 : floatval($details['tirz_takehome_count'] ?? 0);
                                    $gridTotals['tirz_amount'] += floatval($netAmounts['tirz'] ?? 0);
                                    $gridTotals['tirz_purchased'] += floatval($details['tirz_purchased_count'] ?? 0);
                                    $gridTotals['tirz_prepaid'] = $gridTotals['tirz_prepaid'] || $tirzGroupActive;
                                    $gridTotals['afterpay'] += floatval($netAmounts['afterpay'] ?? 0);
                                    $gridTotals['supp_amount'] += floatval($netAmounts['supplements'] ?? 0);
                                    $gridTotals['supp_count'] += floatval($details['supplement_count'] ?? 0);
                                    $gridTotals['drink_amount'] += floatval($netAmounts['protein'] ?? 0);
                                    $gridTotals['drink_count'] += floatval($details['drink_count'] ?? 0);
                                    $gridTotals['subtotal'] += $netSubtotal;
                                    $gridTotals['tax'] += floatval($details['tax_amount'] ?? 0);
                                    $gridTotals['credit'] += floatval($details['credit_amount'] ?? 0);
                                    $gridTotals['total'] += floatval($details['total_amount'] ?? 0);
                                    $gridTotals['afterpay_active'] = !empty($gridTotals['afterpay_active']) || $afterpayGroupActive;
                                    $gridTotals['supp_active'] = !empty($gridTotals['supp_active']) || $supplementsGroupActive;
                                    $gridTotals['drink_active'] = !empty($gridTotals['drink_active']) || $proteinGroupActive;
                                ?>
                                <tr id="patient-dcr-row-<?php echo attr((string) $patientIndex); ?>" data-patient-row="1">
                                    <td><?php echo $patient['order_number']; ?></td>
                                    <td><?php echo formatPatientNameExcel($patient['name']); ?></td>
                                    <td><span class="status-indicator <?php echo $statusClass; ?>"><?php echo htmlspecialchars($actionCode); ?></span></td>
                                    <td class="amount-cell"><?php echo formatCurrencyCell($netAmounts['office_visit'] ?? 0, $showOvZero); ?></td>
                                    <td class="amount-cell"><?php echo formatCurrencyCell($netAmounts['lab'] ?? 0); ?></td>
                                    <td class="col-card-punch number-cell"><?php echo text(dcrDisplayTextForGroup($lipoRowActive, $lipoCardPunchDisplay, '')); ?></td>
                                    <td class="col-th number-cell"><?php echo $lipoTakeHomeDisplay; ?></td>
                                    <td class="col-injection amount-cell"><?php echo dcrDisplayCurrencyForGroup($lipoRowActive, $netAmounts['lipo'] ?? 0); ?></td>
                                    <td class="col-count number-cell"><?php echo dcrDisplayNumberForGroup($lipoRowActive, $details['lipo_purchased_count'] ?? 0); ?></td>
                                    <td class="col-card-punch number-cell"><?php echo text(dcrDisplayTextForGroup($semaRowActive, $semaCardPunchDisplay, '')); ?></td>
                                    <td class="col-th number-cell"><?php echo $semaTakeHomeDisplay; ?></td>
                                    <td class="col-injection amount-cell"><?php echo dcrDisplayCurrencyForGroup($semaRowActive, $netAmounts['sema'] ?? 0); ?></td>
                                    <td class="col-count number-cell"><?php echo dcrDisplayNumberForGroup($semaRowActive, $details['sema_purchased_count'] ?? 0); ?></td>
                                    <td class="number-cell"><?php echo text(dcrDisplayTextForGroup($semaRowActive, $semaDose, '')); ?></td>
                                    <td class="col-card-punch number-cell"><?php echo text(dcrDisplayTextForGroup($tirzRowActive, $tirzCardPunchDisplay, '')); ?></td>
                                    <td class="col-th number-cell"><?php echo $tirzTakeHomeDisplay; ?></td>
                                    <td class="col-injection amount-cell"><?php echo dcrDisplayCurrencyForGroup($tirzRowActive, $netAmounts['tirz'] ?? 0); ?></td>
                                    <td class="col-count number-cell"><?php echo dcrDisplayNumberForGroup($tirzRowActive, $details['tirz_purchased_count'] ?? 0); ?></td>
                                    <td class="number-cell"><?php echo text(dcrDisplayTextForGroup($tirzRowActive, $tirzDose, '')); ?></td>
                                    <td class="amount-cell"><?php echo dcrDisplayCurrencyForGroup($afterpayGroupActive, $netAmounts['afterpay'] ?? 0); ?></td>
                                    <td class="col-injection amount-cell"><?php echo dcrDisplayCurrencyForGroup($supplementsGroupActive, $netAmounts['supplements'] ?? 0); ?></td>
                                    <td class="col-count number-cell"><?php echo dcrDisplayNumberForGroup($supplementsGroupActive, $details['supplement_count'] ?? 0); ?></td>
                                    <td class="col-injection amount-cell"><?php echo dcrDisplayCurrencyForGroup($proteinGroupActive, $netAmounts['protein'] ?? 0); ?></td>
                                    <td class="col-count number-cell"><?php echo dcrDisplayNumberForGroup($proteinGroupActive, $details['drink_count'] ?? 0); ?></td>
                                    <td class="amount-cell"><?php echo formatCurrencyCell($netSubtotal); ?></td>
                                    <td class="amount-cell"><?php echo formatCurrencyCell($details['tax_amount'] ?? 0, true); ?></td>
                                    <td class="amount-cell"><?php echo formatCurrencyCell($details['credit_amount'] ?? 0); ?></td>
                                    <td class="amount-cell"><?php echo formatCurrencyCell($details['total_amount'] ?? $patient['total_spent']); ?></td>
                                    <td class="notes-cell"><?php echo text($patientNotesDisplay); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($report_data['patient_rows'])): ?>
                    <tfoot>
                        <tr>
                            <td colspan="3" style="text-align:center; font-weight:700;">Totals</td>
                            <td class="amount-cell"><?php echo formatCurrencyCell($gridTotals['ov'], !empty($gridTotals['ov_prepaid'])); ?></td>
                            <td class="amount-cell"><?php echo formatCurrencyCell($gridTotals['bw']); ?></td>
                            <td class="col-card-punch number-cell"></td>
                            <td class="col-th number-cell"><?php echo dcrDisplayNumberForGroup(!empty($gridTotals['lipo_prepaid']), $gridTotals['lipo_home']); ?></td>
                            <td class="col-injection amount-cell"><?php echo dcrDisplayCurrencyForGroup(!empty($gridTotals['lipo_prepaid']), $gridTotals['lipo_amount']); ?></td>
                            <td class="col-count number-cell"><?php echo dcrDisplayNumberForGroup(!empty($gridTotals['lipo_prepaid']), $gridTotals['lipo_purchased']); ?></td>
                            <td class="col-card-punch number-cell"></td>
                            <td class="col-th number-cell"><?php echo dcrDisplayNumberForGroup(!empty($gridTotals['sema_prepaid']), $gridTotals['sema_home']); ?></td>
                            <td class="col-injection amount-cell"><?php echo dcrDisplayCurrencyForGroup(!empty($gridTotals['sema_prepaid']), $gridTotals['sema_amount']); ?></td>
                            <td class="col-count number-cell"><?php echo dcrDisplayNumberForGroup(!empty($gridTotals['sema_prepaid']), $gridTotals['sema_purchased']); ?></td>
                            <td></td>
                            <td class="col-card-punch number-cell"></td>
                            <td class="col-th number-cell"><?php echo dcrDisplayNumberForGroup(!empty($gridTotals['tirz_prepaid']), $gridTotals['tirz_home']); ?></td>
                            <td class="col-injection amount-cell"><?php echo dcrDisplayCurrencyForGroup(!empty($gridTotals['tirz_prepaid']), $gridTotals['tirz_amount']); ?></td>
                            <td class="col-count number-cell"><?php echo dcrDisplayNumberForGroup(!empty($gridTotals['tirz_prepaid']), $gridTotals['tirz_purchased']); ?></td>
                            <td></td>
                            <td class="amount-cell"><?php echo dcrDisplayCurrencyForGroup(!empty($gridTotals['afterpay_active']), $gridTotals['afterpay']); ?></td>
                            <td class="col-injection amount-cell"><?php echo dcrDisplayCurrencyForGroup(!empty($gridTotals['supp_active']), $gridTotals['supp_amount']); ?></td>
                            <td class="col-count number-cell"><?php echo dcrDisplayNumberForGroup(!empty($gridTotals['supp_active']), $gridTotals['supp_count']); ?></td>
                            <td class="col-injection amount-cell"><?php echo dcrDisplayCurrencyForGroup(!empty($gridTotals['drink_active']), $gridTotals['drink_amount']); ?></td>
                            <td class="col-count number-cell"><?php echo dcrDisplayNumberForGroup(!empty($gridTotals['drink_active']), $gridTotals['drink_count']); ?></td>
                            <td class="amount-cell"><?php echo formatCurrencyCell($gridTotals['subtotal']); ?></td>
                            <td class="amount-cell"><?php echo formatCurrencyCell($gridTotals['tax'], true); ?></td>
                            <td class="amount-cell"><?php echo formatCurrencyCell($gridTotals['credit']); ?></td>
                            <td class="amount-cell"><?php echo formatCurrencyCell($gridTotals['total']); ?></td>
                            <td class="notes-cell"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
                </div>
                <!-- End of Tab 1: Patient DCR -->
                
                <!-- Tab 2: Gross Patient Count -->
                <div id="tab-gross-count" class="dcr-tab-content">
                <?php $dailyBreakdown = $report_data['daily_breakdown'] ?? ['rows' => [], 'totals' => [], 'averages' => [], 'day_count' => 0]; ?>
                <?php if ($form_report_type === 'daily' && !empty($dailyBreakdown['rows'])): ?>
                <div class="excel-daily-scroll">
                    <table class="excel-daily-table">
                        <thead>
                            <tr>
                                <th rowspan="2" class="excel-daily-date">Day of week &amp; date</th>
                                <th colspan="2" class="excel-daily-sema">Semaglutide</th>
                                <th colspan="2" class="excel-daily-sema">Semaglutide</th>
                                <th colspan="2" class="excel-daily-sema">Semaglutide</th>
                                <th colspan="2" class="excel-daily-sema">SG Total</th>
                                <th colspan="2" class="excel-daily-trz">Tirzepatide</th>
                                <th colspan="2" class="excel-daily-trz">Tirzepatide</th>
                                <th colspan="2" class="excel-daily-trz">Tirzepatide</th>
                                <th colspan="2" class="excel-daily-trz">TRZ Total</th>
                                <th colspan="2" class="excel-daily-pills">Pills</th>
                                <th colspan="2" class="excel-daily-pills">Pills</th>
                                <th colspan="2" class="excel-daily-pills">Pills</th>
                                <th colspan="2" class="excel-daily-pills">Pills Total</th>
                                <th colspan="2" class="excel-daily-test">Testosterone</th>
                                <th colspan="2" class="excel-daily-test">Testosterone</th>
                                <th colspan="2" class="excel-daily-test">Testosterone</th>
                                <th colspan="2" class="excel-daily-test">Testosterone Total</th>
                                <th class="excel-daily-other-white">All Other</th>
                                <th class="excel-daily-other">TOTAL</th>
                            </tr>
                            <tr>
                                <th class="excel-daily-sema">New Pts</th>
                                <th class="excel-daily-sema">Revenue</th>
                                <th class="excel-daily-sema">FU Pts</th>
                                <th class="excel-daily-sema">Revenue</th>
                                <th class="excel-daily-sema">Rtn Pts</th>
                                <th class="excel-daily-sema">Revenue</th>
                                <th class="excel-daily-sema">Pts</th>
                                <th class="excel-daily-sema">Revenue</th>
                                <th class="excel-daily-trz">New Pts</th>
                                <th class="excel-daily-trz">Revenue</th>
                                <th class="excel-daily-trz">FU Pts</th>
                                <th class="excel-daily-trz">Revenue</th>
                                <th class="excel-daily-trz">Rtn Pts</th>
                                <th class="excel-daily-trz">Revenue</th>
                                <th class="excel-daily-trz">Pts</th>
                                <th class="excel-daily-trz">Revenue</th>
                                <th class="excel-daily-pills">New Pts</th>
                                <th class="excel-daily-pills">Revenue</th>
                                <th class="excel-daily-pills">FU Pts</th>
                                <th class="excel-daily-pills">Revenue</th>
                                <th class="excel-daily-pills">Rtn Pts</th>
                                <th class="excel-daily-pills">Revenue</th>
                                <th class="excel-daily-pills">Pts</th>
                                <th class="excel-daily-pills">Revenue</th>
                                <th class="excel-daily-test">New Pts</th>
                                <th class="excel-daily-test">Revenue</th>
                                <th class="excel-daily-test">FU Pts</th>
                                <th class="excel-daily-test">Revenue</th>
                                <th class="excel-daily-test">Rtn Pts</th>
                                <th class="excel-daily-test">Revenue</th>
                                <th class="excel-daily-test">Pts</th>
                                <th class="excel-daily-test">Revenue</th>
                                <th class="excel-daily-other-white">Revenue</th>
                                <th class="excel-daily-other">REVENUE</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dailyBreakdown['rows'] as $dayRow): ?>
                            <tr>
                                <td class="excel-daily-date"><?php echo htmlspecialchars($dayRow['display_date']); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dayRow['sema_new_pts'], $dayRow['date_key'] ?? '', 'sema_new', 'Semaglutide New Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dayRow['sema_new_rev']); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dayRow['sema_fu_pts'], $dayRow['date_key'] ?? '', 'sema_fu', 'Semaglutide Follow-Up Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dayRow['sema_fu_rev']); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dayRow['sema_rtn_pts'], $dayRow['date_key'] ?? '', 'sema_rtn', 'Semaglutide Returning Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dayRow['sema_rtn_rev']); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dayRow['sg_total_pts'], $dayRow['date_key'] ?? '', 'sg_total', 'Semaglutide Total Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dayRow['sg_total_rev']); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dayRow['tirz_new_pts'], $dayRow['date_key'] ?? '', 'tirz_new', 'Tirzepatide New Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dayRow['tirz_new_rev']); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dayRow['tirz_fu_pts'], $dayRow['date_key'] ?? '', 'tirz_fu', 'Tirzepatide Follow-Up Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dayRow['tirz_fu_rev']); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dayRow['tirz_rtn_pts'], $dayRow['date_key'] ?? '', 'tirz_rtn', 'Tirzepatide Returning Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dayRow['tirz_rtn_rev']); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dayRow['trz_total_pts'], $dayRow['date_key'] ?? '', 'trz_total', 'Tirzepatide Total Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dayRow['trz_total_rev']); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dayRow['pills_new_pts'], $dayRow['date_key'] ?? '', 'pills_new', 'Pills New Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dayRow['pills_new_rev']); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dayRow['pills_fu_pts'], $dayRow['date_key'] ?? '', 'pills_fu', 'Pills Follow-Up Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dayRow['pills_fu_rev']); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dayRow['pills_rtn_pts'], $dayRow['date_key'] ?? '', 'pills_rtn', 'Pills Returning Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dayRow['pills_rtn_rev']); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dayRow['pills_total_pts'], $dayRow['date_key'] ?? '', 'pills_total', 'Pills Total Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dayRow['pills_total_rev']); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dayRow['test_new_pts'], $dayRow['date_key'] ?? '', 'test_new', 'Testosterone New Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dayRow['test_new_rev']); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dayRow['test_fu_pts'], $dayRow['date_key'] ?? '', 'test_fu', 'Testosterone Follow-Up Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dayRow['test_fu_rev']); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dayRow['test_rtn_pts'], $dayRow['date_key'] ?? '', 'test_rtn', 'Testosterone Returning Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dayRow['test_rtn_rev']); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dayRow['test_total_pts'], $dayRow['date_key'] ?? '', 'test_total', 'Testosterone Total Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dayRow['test_total_rev']); ?></td>
                                <td class="excel-daily-other-white"><?php echo formatCurrencyExcelValue($dayRow['other_rev'] ?? 0); ?></td>
                                <td class="excel-daily-other"><?php echo formatCurrencyExcelValue($dayRow['total_rev'] ?? 0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="excel-daily-total-row">
                                <td>Total</td>
                                <td><?php echo renderGrossBreakdownCountLink($dailyBreakdown['totals']['sema_new_pts'] ?? 0, '__total__', 'sema_new', 'Semaglutide New Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['totals']['sema_new_rev'] ?? 0); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dailyBreakdown['totals']['sema_fu_pts'] ?? 0, '__total__', 'sema_fu', 'Semaglutide Follow-Up Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['totals']['sema_fu_rev'] ?? 0); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dailyBreakdown['totals']['sema_rtn_pts'] ?? 0, '__total__', 'sema_rtn', 'Semaglutide Returning Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['totals']['sema_rtn_rev'] ?? 0); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dailyBreakdown['totals']['sg_total_pts'] ?? 0, '__total__', 'sg_total', 'Semaglutide Total Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['totals']['sg_total_rev'] ?? 0); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dailyBreakdown['totals']['tirz_new_pts'] ?? 0, '__total__', 'tirz_new', 'Tirzepatide New Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['totals']['tirz_new_rev'] ?? 0); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dailyBreakdown['totals']['tirz_fu_pts'] ?? 0, '__total__', 'tirz_fu', 'Tirzepatide Follow-Up Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['totals']['tirz_fu_rev'] ?? 0); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dailyBreakdown['totals']['tirz_rtn_pts'] ?? 0, '__total__', 'tirz_rtn', 'Tirzepatide Returning Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['totals']['tirz_rtn_rev'] ?? 0); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dailyBreakdown['totals']['trz_total_pts'] ?? 0, '__total__', 'trz_total', 'Tirzepatide Total Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['totals']['trz_total_rev'] ?? 0); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dailyBreakdown['totals']['pills_new_pts'] ?? 0, '__total__', 'pills_new', 'Pills New Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['totals']['pills_new_rev'] ?? 0); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dailyBreakdown['totals']['pills_fu_pts'] ?? 0, '__total__', 'pills_fu', 'Pills Follow-Up Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['totals']['pills_fu_rev'] ?? 0); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dailyBreakdown['totals']['pills_rtn_pts'] ?? 0, '__total__', 'pills_rtn', 'Pills Returning Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['totals']['pills_rtn_rev'] ?? 0); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dailyBreakdown['totals']['pills_total_pts'] ?? 0, '__total__', 'pills_total', 'Pills Total Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['totals']['pills_total_rev'] ?? 0); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dailyBreakdown['totals']['test_new_pts'] ?? 0, '__total__', 'test_new', 'Testosterone New Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['totals']['test_new_rev'] ?? 0); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dailyBreakdown['totals']['test_fu_pts'] ?? 0, '__total__', 'test_fu', 'Testosterone Follow-Up Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['totals']['test_fu_rev'] ?? 0); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dailyBreakdown['totals']['test_rtn_pts'] ?? 0, '__total__', 'test_rtn', 'Testosterone Returning Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['totals']['test_rtn_rev'] ?? 0); ?></td>
                                <td><?php echo renderGrossBreakdownCountLink($dailyBreakdown['totals']['test_total_pts'] ?? 0, '__total__', 'test_total', 'Testosterone Total Patients'); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['totals']['test_total_rev'] ?? 0); ?></td>
                                <td class="excel-daily-other-white"><?php echo formatCurrencyExcelValue($dailyBreakdown['totals']['other_rev'] ?? 0); ?></td>
                                <td class="excel-daily-other"><?php echo formatCurrencyExcelValue($dailyBreakdown['totals']['total_rev'] ?? 0); ?></td>
                            </tr>
                            <tr class="excel-daily-average-row">
                                <td>Average</td>
                                <td><?php echo formatNumberExcelValue($dailyBreakdown['averages']['sema_new_pts'] ?? 0, 1); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['averages']['sema_new_rev'] ?? 0); ?></td>
                                <td><?php echo formatNumberExcelValue($dailyBreakdown['averages']['sema_fu_pts'] ?? 0, 1); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['averages']['sema_fu_rev'] ?? 0); ?></td>
                                <td><?php echo formatNumberExcelValue($dailyBreakdown['averages']['sema_rtn_pts'] ?? 0, 1); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['averages']['sema_rtn_rev'] ?? 0); ?></td>
                                <td><?php echo formatNumberExcelValue($dailyBreakdown['averages']['sg_total_pts'] ?? 0, 1); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['averages']['sg_total_rev'] ?? 0); ?></td>
                                <td><?php echo formatNumberExcelValue($dailyBreakdown['averages']['tirz_new_pts'] ?? 0, 1); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['averages']['tirz_new_rev'] ?? 0); ?></td>
                                <td><?php echo formatNumberExcelValue($dailyBreakdown['averages']['tirz_fu_pts'] ?? 0, 1); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['averages']['tirz_fu_rev'] ?? 0); ?></td>
                                <td><?php echo formatNumberExcelValue($dailyBreakdown['averages']['tirz_rtn_pts'] ?? 0, 1); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['averages']['tirz_rtn_rev'] ?? 0); ?></td>
                                <td><?php echo formatNumberExcelValue($dailyBreakdown['averages']['trz_total_pts'] ?? 0, 1); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['averages']['trz_total_rev'] ?? 0); ?></td>
                                <td><?php echo formatNumberExcelValue($dailyBreakdown['averages']['pills_new_pts'] ?? 0, 1); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['averages']['pills_new_rev'] ?? 0); ?></td>
                                <td><?php echo formatNumberExcelValue($dailyBreakdown['averages']['pills_fu_pts'] ?? 0, 1); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['averages']['pills_fu_rev'] ?? 0); ?></td>
                                <td><?php echo formatNumberExcelValue($dailyBreakdown['averages']['pills_rtn_pts'] ?? 0, 1); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['averages']['pills_rtn_rev'] ?? 0); ?></td>
                                <td><?php echo formatNumberExcelValue($dailyBreakdown['averages']['pills_total_pts'] ?? 0, 1); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['averages']['pills_total_rev'] ?? 0); ?></td>
                                <td><?php echo formatNumberExcelValue($dailyBreakdown['averages']['test_new_pts'] ?? 0, 1); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['averages']['test_new_rev'] ?? 0); ?></td>
                                <td><?php echo formatNumberExcelValue($dailyBreakdown['averages']['test_fu_pts'] ?? 0, 1); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['averages']['test_fu_rev'] ?? 0); ?></td>
                                <td><?php echo formatNumberExcelValue($dailyBreakdown['averages']['test_rtn_pts'] ?? 0, 1); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['averages']['test_rtn_rev'] ?? 0); ?></td>
                                <td><?php echo formatNumberExcelValue($dailyBreakdown['averages']['test_total_pts'] ?? 0, 1); ?></td>
                                <td><?php echo formatCurrencyExcelValue($dailyBreakdown['averages']['test_total_rev'] ?? 0); ?></td>
                                <td class="excel-daily-other-white"><?php echo formatCurrencyExcelValue($dailyBreakdown['averages']['other_rev'] ?? 0); ?></td>
                                <td class="excel-daily-other"><?php echo formatCurrencyExcelValue($dailyBreakdown['averages']['total_rev'] ?? 0); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>
                </div>
                <!-- End of Tab 2: Gross Patient Count -->
                
                <!-- Tab 3: Deposit -->
                <div id="tab-deposit" class="dcr-tab-content">
                <?php
                    // Build deposit data from transactions
                    $depositData = [];
                    $depositTotals = [
                        'cash' => 0,
                        'checks' => 0,
                        'credit' => 0,
                        'actual_bank_deposit' => 0,
                        'total_deposit' => 0,
                        'subtotal_credit_checks' => 0,
                        'total_revenue' => 0
                    ];
                    
                    // Get transactions for the date range
                    // Check if facility_id column exists in pos_transactions
                    $checkFacilityColumn = "SHOW COLUMNS FROM pos_transactions LIKE 'facility_id'";
                    $facilityColumnExists = sqlStatement($checkFacilityColumn);
                    $hasFacilityColumn = false;
                    if ($facilityColumnExists) {
                        $colRow = sqlFetchArray($facilityColumnExists);
                        if ($colRow) {
                            $hasFacilityColumn = true;
                        }
                    }
                    
                    $depositQuery = "SELECT 
                        DATE(pt.created_date) as business_date,
                        pt.payment_method,
                        pt.amount";
                    $depositVoidFilter = posTransactionsHaveVoidColumns()
                        ? " AND COALESCE(pt.voided, 0) = 0 AND pt.transaction_type != 'void'"
                        : " AND pt.transaction_type != 'void'";
                    
                    if ($hasFacilityColumn) {
                        $depositQuery .= ",
                        COALESCE(f.name, 'Unknown Facility') as facility_name,
                        COALESCE(pt.facility_id, p.facility_id) as facility_id
                        FROM pos_transactions pt
                        INNER JOIN patient_data p ON p.pid = pt.pid
                        LEFT JOIN facility f ON f.id = COALESCE(pt.facility_id, p.facility_id)";
                    } else {
                        $depositQuery .= ",
                        ? as facility_name,
                        ? as facility_id
                        FROM pos_transactions pt
                        INNER JOIN patient_data p ON p.pid = pt.pid";
                    }
                    
                    $depositQuery .= " WHERE DATE(pt.created_date) BETWEEN ? AND ?
                    AND pt.transaction_type IN ('purchase', 'purchase_and_dispens', 'external_payment')
                    AND pt.amount > 0"
                    . $depositVoidFilter
                    . dcrBuildBackdateExclusionSql('pt');
                    
                    $depositParams = [];
                    if (!$hasFacilityColumn) {
                        // If no facility_id column, use form facility or default
                        $defaultFacilityName = 'ALL FACILITIES';
                        if ($form_facility) {
                            $facStmt = sqlStatement("SELECT name FROM facility WHERE id = ?", [$form_facility]);
                            if ($facStmt) {
                                $facRow = sqlFetchArray($facStmt);
                                if ($facRow && isset($facRow['name'])) {
                                    $defaultFacilityName = $facRow['name'];
                                }
                            }
                        }
                        $depositParams[] = $defaultFacilityName;
                        $depositParams[] = $form_facility ?: 0;
                    }
                    
                    $depositParams[] = $form_date;
                    $depositParams[] = $form_to_date;
                    
                    if ($form_facility) {
                        if ($hasFacilityColumn) {
                            $depositQuery .= " AND (pt.facility_id = ? OR (pt.facility_id IS NULL AND p.facility_id = ?))";
                            $depositParams[] = $form_facility;
                        } else {
                            $depositQuery .= " AND p.facility_id = ?";
                        }
                        $depositParams[] = $form_facility;
                    }
                    
                    $depositQuery .= " ORDER BY pt.created_date ASC";
                    
                    $depositResult = sqlStatement($depositQuery, $depositParams);
                    
                    while ($row = sqlFetchArray($depositResult)) {
                        $date = $row['business_date'];
                        $paymentMethod = strtolower($row['payment_method'] ?? '');
                        $amount = floatval($row['amount']);
                        $facilityName = $row['facility_name'] ?? 'ALL FACILITIES';
                        
                        if (!isset($depositData[$date])) {
                            $depositData[$date] = [
                                'date' => $date,
                                'facility_name' => $facilityName,
                                'cash' => 0,
                                'checks' => 0,
                                'credit' => 0,
                                'actual_bank_deposit' => 0,
                                'total_deposit' => 0,
                                'subtotal_credit_checks' => 0,
                                'total_revenue' => 0
                            ];
                        }
                        
                        // Categorize payment methods
                        if ($paymentMethod === 'cash') {
                            $depositData[$date]['cash'] += $amount;
                            $depositTotals['cash'] += $amount;
                        } elseif ($paymentMethod === 'check') {
                            $depositData[$date]['checks'] += $amount;
                            $depositTotals['checks'] += $amount;
                        } else {
                            // Treat every remaining positive electronic/non-cash
                            // payment method as part of the credit bucket so Deposit
                            // matches the DCR revenue totals.
                            $depositData[$date]['credit'] += $amount;
                            $depositTotals['credit'] += $amount;
                        }
                        
                        // Calculate totals
                        $depositData[$date]['actual_bank_deposit'] = $depositData[$date]['cash'] + $depositData[$date]['checks'];
                        $depositData[$date]['total_deposit'] = $depositData[$date]['actual_bank_deposit'];
                        $depositData[$date]['subtotal_credit_checks'] = $depositData[$date]['credit'] + $depositData[$date]['checks'];
                        $depositData[$date]['total_revenue'] = $depositData[$date]['cash'] + $depositData[$date]['checks'] + $depositData[$date]['credit'];
                    }
                    
                    // Calculate grand totals
                    $depositTotals['actual_bank_deposit'] = $depositTotals['cash'] + $depositTotals['checks'];
                    $depositTotals['total_deposit'] = $depositTotals['actual_bank_deposit'];
                    $depositTotals['subtotal_credit_checks'] = $depositTotals['credit'] + $depositTotals['checks'];
                    $depositTotals['total_revenue'] = $depositTotals['cash'] + $depositTotals['checks'] + $depositTotals['credit'];
                    
                    // Sort by date
                    ksort($depositData);
                ?>
                <div class="excel-daily-scroll">
                    <table class="dcr-grid-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>CLINIC</th>
                                <th>DATE OF BUSINESS</th>
                                <th>CASH</th>
                                <th>CHECKS</th>
                                <th>Actual Bank Deposit</th>
                                <th>TOTAL DEPOSIT</th>
                                <th>CREDIT</th>
                                <th>SUBTOTAL OF CREDIT/CHECKS</th>
                                <th>TOTAL REVENUE</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($depositData)): ?>
                                <tr>
                                    <td colspan="9" style="text-align:center; color:#6c757d; font-style: italic;">
                                        No deposit data available for the selected filters.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($depositData as $dateKey => $dayData): ?>
                                    <?php
                                        $dateObj = DateTime::createFromFormat('Y-m-d', $dayData['date']);
                                        if ($dateObj) {
                                            $dayOfWeek = $dateObj->format('D');
                                            $month = $dateObj->format('n');
                                            $day = $dateObj->format('j');
                                            $displayDate = $dayOfWeek . ' ' . $month . '-' . $day;
                                        } else {
                                            $displayDate = $dayData['date'];
                                        }
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($dayData['facility_name']); ?></td>
                                        <td><?php echo htmlspecialchars($displayDate); ?></td>
                                        <td class="amount-cell"><?php echo formatCurrencyCell($dayData['cash']); ?></td>
                                        <td class="amount-cell"><?php echo formatCurrencyCell($dayData['checks']); ?></td>
                                        <td class="amount-cell"><?php echo formatCurrencyCell($dayData['actual_bank_deposit']); ?></td>
                                        <td class="amount-cell"><?php echo formatCurrencyCell($dayData['total_deposit']); ?></td>
                                        <td class="amount-cell"><?php echo formatCurrencyCell($dayData['credit']); ?></td>
                                        <td class="amount-cell"><?php echo formatCurrencyCell($dayData['subtotal_credit_checks']); ?></td>
                                        <td class="amount-cell"><?php echo formatCurrencyCell($dayData['total_revenue']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($depositData)): ?>
                        <tfoot>
                            <tr style="font-weight: 700;">
                                <td colspan="2" style="text-align:left;">Totals</td>
                                <td class="amount-cell"><?php echo formatCurrencyCell($depositTotals['cash']); ?></td>
                                <td class="amount-cell"><?php echo formatCurrencyCell($depositTotals['checks']); ?></td>
                                <td class="amount-cell"><?php echo formatCurrencyCell($depositTotals['actual_bank_deposit']); ?></td>
                                <td class="amount-cell"><?php echo formatCurrencyCell($depositTotals['total_deposit']); ?></td>
                                <td class="amount-cell"><?php echo formatCurrencyCell($depositTotals['credit']); ?></td>
                                <td class="amount-cell"><?php echo formatCurrencyCell($depositTotals['subtotal_credit_checks']); ?></td>
                                <td class="amount-cell"><?php echo formatCurrencyCell($depositTotals['total_revenue']); ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
                </div>
                <!-- End of Tab 3: Deposit -->
                
                <!-- Tab 4: Revenue Breakdown -->
                <div id="tab-revenue-breakdown" class="dcr-tab-content">
                <?php 
                    // Use monthly revenue grid for all report types (it works for any date range)
                    $revenueGrid = $report_data['monthly_revenue_grid'] ?? ['rows' => [], 'totals' => []];
                    
                    // Define categories (without payment methods)
                    $revenueCategories = [
                        'office_visit' => 'Office Visit',
                        'lab' => 'LAB',
                        'lipo' => 'LIPO Inj',
                        'sema' => 'Semaglutide',
                        'tirz' => 'Tirzepatide',
                        'testosterone' => 'Testosterone',
                        'afterpay' => 'AfterPay',
                        'supplements' => 'Supplements',
                        'protein' => 'Protein Drinks',
                        'taxes' => 'Taxes',
                        'total' => 'Total',
                    ];
                    
                    $revenueTotals = $revenueGrid['totals'] ?? [];
                    
                    // Format month/year for header
                    $revenueHeaderDate = $month_year;
                    if ($form_report_type === 'daily') {
                        $startDateObj = DateTime::createFromFormat('Y-m-d', $form_date);
                        $endDateObj = DateTime::createFromFormat('Y-m-d', $form_to_date);
                        if ($startDateObj && $endDateObj) {
                            if ($startDateObj->format('Y-m') === $endDateObj->format('Y-m')) {
                                $revenueHeaderDate = $startDateObj->format('F Y');
                            } else {
                                $revenueHeaderDate = $startDateObj->format('M Y') . ' - ' . $endDateObj->format('M Y');
                            }
                        }
                    }
                ?>
                <?php if (!empty($revenueGrid['rows'])): ?>
                <div class="excel-month-scroll">
                    <table class="excel-month-table">
                        <thead>
                            <tr>
                                <th colspan="2" style="background:#c6e0b4; text-align:left; font-weight:700; font-size:12px;"><?php echo htmlspecialchars($facility_display_name); ?></th>
                                <th colspan="<?php echo count($revenueCategories); ?>" style="background:#ffe699; text-align:center; font-weight:700; font-size:12px;"><?php echo htmlspecialchars(strtoupper($revenueHeaderDate)); ?></th>
                            </tr>
                            <tr>
                                <th></th>
                                <th></th>
                                <?php foreach ($revenueCategories as $key => $label): ?>
                                    <th><?php echo htmlspecialchars($label); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="excel-month-running-row">
                                <td></td>
                                <td style="font-weight:700;">TOTAL</td>
                                <?php foreach ($revenueCategories as $key => $label): ?>
                                    <?php $val = floatval($revenueTotals[$key] ?? 0); ?>
                                    <td class="total-cell <?php echo $val == 0.0 ? 'amount-zero' : ''; ?>"><?php echo formatCurrencyExcelValue($val); ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <?php foreach ($revenueGrid['rows'] as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['date_label'] . ' (' . $row['label'] . ')'); ?></td>
                                <td></td>
                                <?php foreach ($revenueCategories as $key => $label): ?>
                                    <?php $val = floatval($row[$key] ?? 0); ?>
                                    <td class="<?php echo $val == 0.0 ? 'amount-zero' : ''; ?>"><?php echo formatCurrencyExcelValue($val); ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div style="text-align:center; color:#6c757d; font-style: italic; padding: 40px;">
                        No revenue breakdown data available for the selected filters.
                    </div>
                <?php endif; ?>
                </div>
                <!-- End of Tab 4: Revenue Breakdown -->
                
                <!-- Tab 5: Shot Tracker -->
                <div id="tab-shot-tracker" class="dcr-tab-content">
                <?php 
                    $shotTracker = $report_data['shot_tracker'] ?? ['rows' => [], 'totals' => []];
                    $shotTotals = $shotTracker['totals'] ?? [];
                    
                    // Format month/year for header
                    $shotHeaderDate = $month_year;
                    if ($form_report_type === 'daily') {
                        $startDateObj = DateTime::createFromFormat('Y-m-d', $form_date);
                        $endDateObj = DateTime::createFromFormat('Y-m-d', $form_to_date);
                        if ($startDateObj && $endDateObj) {
                            if ($startDateObj->format('Y-m') === $endDateObj->format('Y-m')) {
                                $shotHeaderDate = $startDateObj->format('F Y');
                            } else {
                                $shotHeaderDate = $startDateObj->format('M Y') . ' - ' . $endDateObj->format('M Y');
                            }
                        }
                    }
                ?>
                <?php if (!empty($shotTracker['rows'])): ?>
                <div class="excel-month-scroll">
                    <table class="excel-month-table" style="border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th colspan="1" style="background:#c6e0b4; text-align:left; font-weight:700; font-size:12px; border: 2px solid #000; padding: 8px;"><?php echo htmlspecialchars($facility_display_name); ?></th>
                                <th colspan="6" style="background:#ffe699; text-align:center; font-weight:700; font-size:12px; border: 2px solid #000; padding: 8px;"><?php echo htmlspecialchars(strtoupper($shotHeaderDate)); ?></th>
                            </tr>
                            <tr>
                                <th style="border: 1px solid #000; padding: 6px; background: #f0f0f0;">Date</th>
                                <th style="border: 1px solid #000; padding: 6px; background: #d3d3d3;"># of LIPO Cards</th>
                                <th style="border: 1px solid #000; padding: 6px; background: #d3d3d3;">Total from cards (LIPO)</th>
                                <th style="border: 1px solid #000; padding: 6px; background: #ffcc99; border-left: 3px solid #000;"># of SG & TRZ Cards</th>
                                <th style="border: 1px solid #000; padding: 6px; background: #ffcc99;">Total from cards (SG & TRZ)</th>
                                <th style="border: 1px solid #000; padding: 6px; background: #c6e0b4; border-left: 3px solid #000;"># of TES Cards</th>
                                <th style="border: 1px solid #000; padding: 6px; background: #c6e0b4;">Total from cards (TES)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shotTracker['rows'] as $row): ?>
                            <tr>
                                <td style="border: 1px solid #000; padding: 6px;"><?php echo htmlspecialchars($row['date_label']); ?></td>
                                <td style="border: 1px solid #000; padding: 6px; text-align: center;"><?php echo renderShotTrackerCountLink($row['lipo_cards'], $row['date_key'] ?? '', 'lipo_cards', 'LIPO Cards'); ?></td>
                                <td style="border: 1px solid #000; padding: 6px; text-align: right;"><?php echo htmlspecialchars($row['lipo_total']); ?></td>
                                <td style="border: 1px solid #000; padding: 6px; text-align: center; border-left: 3px solid #000;"><?php echo renderShotTrackerCountLink($row['sg_trz_cards'], $row['date_key'] ?? '', 'sg_trz_cards', 'SG & TRZ Cards'); ?></td>
                                <td style="border: 1px solid #000; padding: 6px; text-align: right;"><?php echo htmlspecialchars($row['sg_trz_total']); ?></td>
                                <td style="border: 1px solid #000; padding: 6px; text-align: center; border-left: 3px solid #000;"><?php echo renderShotTrackerCountLink($row['tes_cards'], $row['date_key'] ?? '', 'tes_cards', 'TES Cards'); ?></td>
                                <td style="border: 1px solid #000; padding: 6px; text-align: right;"><?php echo htmlspecialchars($row['tes_total']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <!-- Totals Row -->
                            <tr style="font-weight: 700; background: #f0f0f0;">
                                <td style="border: 1px solid #000; padding: 6px;">Totals</td>
                                <td style="border: 1px solid #000; padding: 6px; text-align: center;"><?php echo renderShotTrackerCountLink($shotTotals['lipo_cards'] ?? 0, '__total__', 'lipo_cards', 'LIPO Cards'); ?></td>
                                <td style="border: 1px solid #000; padding: 6px; text-align: right;"><?php echo htmlspecialchars($shotTotals['lipo_total']); ?></td>
                                <td style="border: 1px solid #000; padding: 6px; text-align: center; border-left: 3px solid #000;"><?php echo renderShotTrackerCountLink($shotTotals['sg_trz_cards'] ?? 0, '__total__', 'sg_trz_cards', 'SG & TRZ Cards'); ?></td>
                                <td style="border: 1px solid #000; padding: 6px; text-align: right;"><?php echo htmlspecialchars($shotTotals['sg_trz_total']); ?></td>
                                <td style="border: 1px solid #000; padding: 6px; text-align: center; border-left: 3px solid #000;"><?php echo renderShotTrackerCountLink($shotTotals['tes_cards'] ?? 0, '__total__', 'tes_cards', 'TES Cards'); ?></td>
                                <td style="border: 1px solid #000; padding: 6px; text-align: right;"><?php echo htmlspecialchars($shotTotals['tes_total']); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div style="text-align:center; color:#6c757d; font-style: italic; padding: 40px;">
                        No shot tracker data available for the selected filters.
                    </div>
                <?php endif; ?>
                </div>
                <!-- End of Tab 5: Shot Tracker -->
            </div>
            <!-- End of excel-container -->
            
            <?php if ($form_report_type === 'daily'): ?>
            <!-- Detailed Transactions (Hidden for Excel-style view) -->
            <div class="section" style="display: none;">
                <div class="section-header">
                    <h2 class="section-title-flex">Detailed Transaction History</h2>
                    <button class="download-btn" onclick="downloadTransactionsCSV()">Download CSV</button>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>Receipt #</th>
                            <th>Patient</th>
                            <th>Type</th>
                            <th>Payment Method</th>
                            <th>Amount</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data['transactions'] as $trans): ?>
                        <tr>
                            <td><?php echo date('m/d/Y H:i', strtotime($trans['created_date'])); ?></td>
                            <td><code><?php echo htmlspecialchars($trans['receipt_number']); ?></code></td>
                            <td><?php echo htmlspecialchars($trans['patient_name']); ?></td>
                            <td>
                                <span class="badge badge-<?php echo in_array($trans['transaction_type'], ['purchase', 'purchase_and_dispens']) ? 'purchase' : 'dispense'; ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $trans['transaction_type'])); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($trans['payment_method']); ?></td>
                            <td class="<?php echo $trans['amount'] >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                $<?php echo number_format(abs($trans['amount']), 2); ?>
                            </td>
                            <td><?php echo htmlspecialchars($trans['created_by']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="dcr-drilldown-overlay" class="dcr-drilldown-overlay" onclick="closeGrossCountDrilldown(event)">
        <div class="dcr-drilldown-modal" role="dialog" aria-modal="true" aria-labelledby="dcr-drilldown-title" onclick="event.stopPropagation()">
            <div class="dcr-drilldown-header">
                <div>
                    <div id="dcr-drilldown-title" class="dcr-drilldown-title">Patient Details</div>
                    <div id="dcr-drilldown-subtitle" class="dcr-drilldown-subtitle"></div>
                </div>
                <button type="button" class="dcr-drilldown-close" onclick="closeGrossCountDrilldown()">Close</button>
            </div>
            <div id="dcr-drilldown-body" class="dcr-drilldown-body"></div>
        </div>
    </div>
    
    <script>
        const grossCountDrilldownMap = <?php echo json_encode($grossCountDrilldownMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const shotTrackerDrilldownMap = <?php echo json_encode($shotTrackerDrilldownMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        function escapeDrilldownHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function closeGrossCountDrilldown() {
            $('#dcr-drilldown-overlay').removeClass('active');
            return false;
        }

        function scrollToPatientDcrRow(rowAnchor) {
            if (!rowAnchor) {
                return false;
            }

            const patientTab = $('.dcr-tab').filter(function() {
                return $(this).text().trim() === 'Patient DCR';
            }).get(0);
            switchDCRTab('patient-dcr', patientTab || null);

            const $target = $('#' + rowAnchor);
            if (!$target.length) {
                closeGrossCountDrilldown();
                return false;
            }

            $('[data-patient-row="1"]').removeClass('dcr-highlight-row');
            $target.addClass('dcr-highlight-row');

            const top = Math.max($target.offset().top - 140, 0);
            $('html, body').animate({ scrollTop: top }, 250);

            window.setTimeout(function() {
                $target.removeClass('dcr-highlight-row');
            }, 3500);

            closeGrossCountDrilldown();
            return false;
        }

        function openGrossCountDrilldown(dateKey, metricKey, label) {
            const rows = (((grossCountDrilldownMap || {})[dateKey] || {})[metricKey]) || [];
            const subtitle = dateKey === '__total__' ? 'All dates in current report' : dateKey;

            $('#dcr-drilldown-title').text(label || 'Patient Details');
            $('#dcr-drilldown-subtitle').text(subtitle + ' • ' + rows.length + ' patient' + (rows.length === 1 ? '' : 's'));

            if (!rows.length) {
                $('#dcr-drilldown-body').html('<div class="dcr-drilldown-empty">No matching patient rows were found for this count.</div>');
                $('#dcr-drilldown-overlay').addClass('active');
                return false;
            }

            const tableRows = rows.map(function(row) {
                const notes = row.notes ? escapeDrilldownHtml(row.notes) : '';
                return '<tr>' +
                    '<td>' + escapeDrilldownHtml(row.order_number) + '</td>' +
                    '<td>' + escapeDrilldownHtml(row.name) + '</td>' +
                    '<td>' + escapeDrilldownHtml(row.action || '-') + '</td>' +
                    '<td>' + escapeDrilldownHtml(row.category) + '</td>' +
                    '<td>$' + Number(row.total_amount || 0).toFixed(2) + '</td>' +
                    '<td>' + escapeDrilldownHtml(row.first_visit) + '</td>' +
                    '<td>' + notes + '</td>' +
                    '<td><a href="#" class="dcr-row-link" onclick="return scrollToPatientDcrRow(\'' + escapeDrilldownHtml(row.row_anchor) + '\');">View in Patient DCR</a></td>' +
                    '</tr>';
            }).join('');

            $('#dcr-drilldown-body').html(
                '<table class="dcr-drilldown-table">' +
                '<thead><tr><th>Sign In #</th><th>Patient</th><th>Action</th><th>Category</th><th>Total</th><th>Visit Date</th><th>Notes</th><th>Jump</th></tr></thead>' +
                '<tbody>' + tableRows + '</tbody>' +
                '</table>'
            );

            $('#dcr-drilldown-overlay').addClass('active');
            return false;
        }

        function openShotTrackerDrilldown(dateKey, metricKey, label) {
            const rows = (((shotTrackerDrilldownMap || {})[dateKey] || {})[metricKey]) || [];
            const subtitle = dateKey === '__total__' ? 'All dates in current report' : dateKey;

            $('#dcr-drilldown-title').text(label || 'Shot Tracker Details');
            $('#dcr-drilldown-subtitle').text(subtitle + ' • ' + rows.length + ' patient' + (rows.length === 1 ? '' : 's'));

            if (!rows.length) {
                $('#dcr-drilldown-body').html('<div class="dcr-drilldown-empty">No matching patient rows were found for this count.</div>');
                $('#dcr-drilldown-overlay').addClass('active');
                return false;
            }

            const tableRows = rows.map(function(row) {
                const notes = row.notes ? escapeDrilldownHtml(row.notes) : '';
                return '<tr>' +
                    '<td>' + escapeDrilldownHtml(row.order_number) + '</td>' +
                    '<td>' + escapeDrilldownHtml(row.name) + '</td>' +
                    '<td>' + escapeDrilldownHtml(row.action || '-') + '</td>' +
                    '<td>' + escapeDrilldownHtml(row.category) + '</td>' +
                    '<td>$' + Number(row.total_amount || 0).toFixed(2) + '</td>' +
                    '<td>' + escapeDrilldownHtml(row.first_visit) + '</td>' +
                    '<td>' + notes + '</td>' +
                    '<td><a href="#" class="dcr-row-link" onclick="return scrollToPatientDcrRow(\'' + escapeDrilldownHtml(row.row_anchor) + '\');">View in Patient DCR</a></td>' +
                    '</tr>';
            }).join('');

            $('#dcr-drilldown-body').html(
                '<table class="dcr-drilldown-table">' +
                '<thead><tr><th>Sign In #</th><th>Patient</th><th>Action</th><th>Category</th><th>Total</th><th>Visit Date</th><th>Shot Tracker Detail</th><th>Jump</th></tr></thead>' +
                '<tbody>' + tableRows + '</tbody>' +
                '</table>'
            );

            $('#dcr-drilldown-overlay').addClass('active');
            return false;
        }

        // Tab switching function
        function switchDCRTab(tabName, element) {
            // Hide all tab contents using jQuery
            $('.dcr-tab-content').hide().removeClass('active');
            
            // Remove active class from all tab buttons
            $('.dcr-tab').removeClass('active');
            
            // Show selected tab content
            var $selectedContent = $('#tab-' + tabName);
            if ($selectedContent.length) {
                $selectedContent.addClass('active').show();
            }
            
            // Add active class to clicked tab button
            if (element) {
                $(element).addClass('active');
            }
            
            // Prevent default if element is a link
            if (element && element.preventDefault) {
                element.preventDefault();
            }
            return false;
        }
        
        // Initialize tab display on page load
        $(document).ready(function() {
            // First, hide all tabs
            $('.dcr-tab-content').hide();
            
            // Then show only the tab that has the active class (preserve HTML state)
            var $activeTab = $('.dcr-tab-content.active');
            if ($activeTab.length > 0) {
                $activeTab.show();
            } else {
                // Fallback: if no active tab, activate and show Patient DCR tab
                $('#tab-patient-dcr').addClass('active').show();
                $('.dcr-tab').first().addClass('active');
            }
            
            // Ensure inactive tabs are hidden
            $('.dcr-tab-content').not('.active').hide();

            $(document).on('click', '.js-gross-count-link', function(event) {
                event.preventDefault();
                const $button = $(this);
                openGrossCountDrilldown(
                    String($button.data('dateKey') || ''),
                    String($button.data('metricKey') || ''),
                    String($button.data('label') || 'Patient Details')
                );
            });

            $(document).on('click', '.js-shot-tracker-link', function(event) {
                event.preventDefault();
                const $button = $(this);
                openShotTrackerDrilldown(
                    String($button.data('dateKey') || ''),
                    String($button.data('metricKey') || ''),
                    String($button.data('label') || 'Shot Tracker Details')
                );
            });
        });
        
        // Initialize DataTables for better sorting/filtering
        $(document).ready(function() {
            // Only initialize DataTables on visible tables with proper structure
            $('.data-table').each(function() {
                var $table = $(this);
                // Check if table is visible and has rows
                if ($table.is(':visible') && $table.find('tbody tr').length > 0) {
                    try {
                        $table.DataTable({
                            pageLength: 25,
                            order: [[0, 'desc']],
                            responsive: true,
                            <?php require($GLOBALS['srcdir'] . '/js/xl/datatables-net.js.php'); ?>
                        });
                    } catch (e) {
                        console.warn('DataTables initialization failed for table:', e);
                    }
                }
            });
            
            function toggleReportInputs() {
                var reportType = $('#form_report_type').val();
                if (reportType === 'monthly') {
                    $('#form_month_group').show();
                    $('#form_date_group, #form_to_date_group').hide();
                } else {
                    $('#form_month_group').hide();
                    $('#form_date_group, #form_to_date_group').show();
                }
            }
            toggleReportInputs();
            $('#form_report_type').on('change', toggleReportInputs);
        });
         
         // CSV Download Functions
         function arrayToCSV(data) {
             return data.map(row => 
                 row.map(cell => {
                     // Handle quotes and commas in cell values
                     const cellStr = String(cell ?? '');
                     if (cellStr.includes(',') || cellStr.includes('"') || cellStr.includes('\n')) {
                         return '"' + cellStr.replace(/"/g, '""') + '"';
                     }
                     return cellStr;
                 }).join(',')
             ).join('\n');
         }
         
         function downloadCSV(filename, csvContent) {
             const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
             const link = document.createElement('a');
             const url = URL.createObjectURL(blob);
             link.setAttribute('href', url);
             link.setAttribute('download', filename);
             link.style.visibility = 'hidden';
             document.body.appendChild(link);
             link.click();
             document.body.removeChild(link);
         }
         
         function downloadPaymentMethodsCSV() {
             const data = [
                 ['Payment Method Breakdown - <?php echo $date_formatted . " to " . $to_date_formatted; ?>'],
                 [],
                 ['Payment Method', 'Total Revenue', 'Transaction Count']
             ];
             
             <?php foreach ($report_data['payment_methods'] as $method_key => $method_data): ?>
             data.push([
                 '<?php echo addslashes(ucwords(str_replace('_', ' ', $method_key))); ?>',
                 '$<?php echo number_format($method_data['total_revenue'], 2); ?>',
                 '<?php echo $method_data['transaction_count']; ?>'
             ]);
             <?php endforeach; ?>
             
             data.push([]);
             data.push(['Generated', '<?php echo date('M d, Y \a\t H:i:s', strtotime($report_generated_at)); ?>']);
             
             const csv = arrayToCSV(data);
             downloadCSV('payment_methods_<?php echo $form_date; ?>.csv', csv);
         }
         
         function downloadMedicinesCSV() {
             const data = [
                 ['Medicine Sales Report - <?php echo $date_formatted . " to " . $to_date_formatted; ?>'],
                 [],
                 ['Medicine Name', 'Category', 'Total Sold', 'Dispensed', 'Administered', 'Revenue', 'Patients']
             ];
             
             <?php foreach ($report_data['medicines'] as $medicine): ?>
             data.push([
                 '<?php echo addslashes($medicine['name']); ?>',
                 '<?php echo addslashes($medicine['category']); ?>',
                 '<?php echo $medicine['total_quantity']; ?>',
                 '<?php echo $medicine['total_dispensed']; ?>',
                 '<?php echo $medicine['total_administered']; ?>',
                 '$<?php echo number_format($medicine['total_revenue'], 2); ?>',
                 '<?php echo count($medicine['patients']); ?>'
             ]);
             <?php endforeach; ?>
             
             data.push([]);
             data.push(['Generated', '<?php echo date('M d, Y \a\t H:i:s', strtotime($report_generated_at)); ?>']);
             
             const csv = arrayToCSV(data);
             downloadCSV('medicine_sales_<?php echo $form_date; ?>.csv', csv);
         }
         
         function downloadPatientsCSV() {
             const data = [
                 ['Patient Financial Summary - <?php echo $date_formatted . " to " . $to_date_formatted; ?>'],
                 [],
                ['Sign In #', 'Patient Name', 'Action', 'OV', 'BW', 'Lipo In-Office #', 'Lipo Take Home #', 'Lipo Amount', 'Lipo Purchased', 'Sema In-Office #', 'Sema Take Home #', 'Sema Amount', 'Sema Purchased', 'Sema Dose (mg)', 'TRZ In-Office #', 'TRZ Take Home #', 'TRZ Amount', 'TRZ Purchased', 'TRZ Dose (mg)', 'AfterPay Fee', 'Supplement Amount', 'Supplement Count', 'Protein Amount', 'Protein Count', 'Tax', 'Total']
             ];
             
             <?php foreach ($report_data['patient_rows'] as $patient): ?>
             <?php
                 $details = $patient['details'] ?? initializePatientDetails();
                 $actionCode = $details['visit_type'] ?? '-';
                 $semaDose = !empty($details['sema_doses']) ? implode(', ', $details['sema_doses']) : '';
                 $tirzDose = !empty($details['tirz_doses']) ? implode(', ', $details['tirz_doses']) : '';
                 $lipoCardPunchJs = resolvePatientCardPunchDisplay($details, 'lipo', 5);
                 $semaCardPunchJs = resolvePatientCardPunchDisplay($details, 'sema', 4);
                 $tirzCardPunchJs = resolvePatientCardPunchDisplay($details, 'tirz', 4);
                 $lipoTakeHomeJs = $blank_take_home_columns ? '' : formatNumberCell($details['lipo_takehome_count'] ?? 0);
                 $semaTakeHomeJs = $blank_take_home_columns ? '' : formatNumberCell($details['sema_takehome_count'] ?? 0);
                 $tirzTakeHomeJs = $blank_take_home_columns ? '' : formatNumberCell($details['tirz_takehome_count'] ?? 0);
             ?>
             data.push([
                 '<?php echo $patient['order_number']; ?>',
                 '<?php echo addslashes(formatPatientNameExcel($patient['name'])); ?>',
                 '<?php echo $actionCode; ?>',
                 '<?php echo number_format(floatval($details['ov_amount'] ?? 0), 2); ?>',
                 '<?php echo number_format(floatval($details['bw_amount'] ?? 0), 2); ?>',
                 '<?php echo addslashes($lipoCardPunchJs); ?>',
                 '<?php echo addslashes($lipoTakeHomeJs); ?>',
                 '<?php echo number_format(floatval($details['lipo_amount'] ?? 0), 2); ?>',
                 '<?php echo floatval($details['lipo_purchased_count'] ?? 0); ?>',
                 '<?php echo addslashes($semaCardPunchJs); ?>',
                 '<?php echo addslashes($semaTakeHomeJs); ?>',
                 '<?php echo number_format(floatval($details['sema_amount'] ?? 0), 2); ?>',
                 '<?php echo floatval($details['sema_purchased_count'] ?? 0); ?>',
                 '<?php echo addslashes($semaDose); ?>',
                 '<?php echo addslashes($tirzCardPunchJs); ?>',
                 '<?php echo addslashes($tirzTakeHomeJs); ?>',
                 '<?php echo number_format(floatval($details['tirz_amount'] ?? 0), 2); ?>',
                 '<?php echo floatval($details['tirz_purchased_count'] ?? 0); ?>',
                 '<?php echo addslashes($tirzDose); ?>',
                 '<?php echo number_format(floatval($details['afterpay_fee'] ?? 0), 2); ?>',
                 '<?php echo number_format(floatval($details['supplement_amount'] ?? 0), 2); ?>',
                 '<?php echo floatval($details['supplement_count'] ?? 0); ?>',
                 '<?php echo number_format(floatval($details['drink_amount'] ?? 0), 2); ?>',
                 '<?php echo floatval($details['drink_count'] ?? 0); ?>',
                 '<?php echo number_format(floatval($details['tax_amount'] ?? 0), 2); ?>',
                 '<?php echo number_format(floatval($details['total_amount'] ?? $patient['total_spent']), 2); ?>'
             ]);
             <?php endforeach; ?>
             
             data.push([]);
             data.push(['Total Patients', '<?php echo count($report_data['patient_rows']); ?>']);
             data.push(['New Patients', '<?php echo $report_data['new_patients']; ?>']);
             data.push(['Returning Patients', '<?php echo $report_data['returning_patients']; ?>']);
             data.push(['Generated', '<?php echo date('M d, Y \a\t H:i:s', strtotime($report_generated_at)); ?>']);
             
             const csv = arrayToCSV(data);
             downloadCSV('patient_summary_<?php echo $form_date; ?>.csv', csv);
         }
         
         function downloadTransactionsCSV() {
             const data = [
                 ['Transaction History - <?php echo $date_formatted . " to " . $to_date_formatted; ?>'],
                 [],
                 ['Date/Time', 'Receipt #', 'Patient', 'Type', 'Payment Method', 'Amount', 'By']
             ];
             
             <?php foreach ($report_data['transactions'] as $trans): ?>
             data.push([
                 '<?php echo date('m/d/Y H:i', strtotime($trans['created_date'])); ?>',
                 '<?php echo addslashes($trans['receipt_number']); ?>',
                 '<?php echo addslashes($trans['patient_name']); ?>',
                 '<?php echo addslashes(ucwords(str_replace('_', ' ', $trans['transaction_type']))); ?>',
                 '<?php echo addslashes($trans['payment_method']); ?>',
                 '$<?php echo number_format(abs($trans['amount']), 2); ?>',
                 '<?php echo addslashes($trans['created_by']); ?>'
             ]);
             <?php endforeach; ?>
             
             data.push([]);
             data.push(['Total Transactions', '<?php echo count($report_data['transactions']); ?>']);
             data.push(['Total Revenue', '$<?php echo number_format($report_data['total_revenue'], 2); ?>']);
             data.push(['Generated', '<?php echo date('M d, Y \a\t H:i:s', strtotime($report_generated_at)); ?>']);
             
             const csv = arrayToCSV(data);
             downloadCSV('transactions_<?php echo $form_date; ?>.csv', csv);
         }
     </script>
 </body>
</html>
