<?php
/**
 * Stripe Payment Interface for OpenEMR
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Team
 * @copyright Copyright (c) 2024 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/../../library/payment.inc.php");

// Helper function to ensure SQL results are arrays
if (!function_exists('ensureArray')) {
    function ensureArray($result) {
        if (is_array($result)) {
            return $result;
        }
        
        // If it's an ADORecordSet object, convert to array
        if (is_object($result) && method_exists($result, 'FetchRow')) {
            $array = array();
            while ($row = $result->FetchRow()) {
                $array[] = $row;
            }
            return $array;
        }
        
        // If it's a single row ADORecordSet, get the first row
        if (is_object($result) && method_exists($result, 'FetchRow')) {
            $row = $result->FetchRow();
            return $row ? $row : array();
        }
        
        return array();
    }
}

use OpenEMR\Billing\PaymentGateway;
use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\OeUI\OemrUI;

// Ensure user is logged in
if (!isset($_SESSION['authUserID'])) {
    die(xlt('Not authorized'));
}

// Get patient ID
$pid = $_GET['pid'] ?? 0;
if (!$pid) {
    die(xlt('Patient ID required'));
}

// Get patient data
$patdata = getPatientData($pid, 'fname,mname,lname,pubpid');
if (!$patdata) {
    die(xlt('Patient not found'));
}

// Check if Stripe is configured
if ($GLOBALS['payment_gateway'] !== 'Stripe') {
    die(xlt('Stripe payment gateway not configured'));
}

// Get patient balance - use a more comprehensive calculation
$patient_balance = get_patient_balance($pid, true);

// Always calculate comprehensive balance including clinical billing charges
// Check for any charges in billing table (including clinical billing) - include unbilled charges
$billing_charges = sqlQuery(
    "SELECT SUM(fee) AS total_charges FROM billing WHERE pid = ? AND activity = 1 AND fee > 0",
    array($pid)
);

// Check for any drug sales
$drug_charges = sqlQuery(
    "SELECT SUM(fee) AS total_charges FROM drug_sales WHERE pid = ? AND fee > 0",
    array($pid)
);

// Check for any payments made
$payments = sqlQuery(
    "SELECT SUM(pay_amount) AS total_payments FROM ar_activity WHERE pid = ? AND deleted IS NULL AND pay_amount > 0",
    array($pid)
);

// Calculate total outstanding balance with explicit type casting and null handling
$billing_total = 0.0;
if (is_object($billing_charges) && method_exists($billing_charges, 'FetchRow')) {
    $billing_row = $billing_charges->FetchRow();
    if ($billing_row && isset($billing_row['total_charges']) && $billing_row['total_charges'] !== null && $billing_row['total_charges'] !== '') {
        $billing_total = floatval($billing_row['total_charges']);
    }
}

$drug_total = 0.0;
if (is_object($drug_charges) && method_exists($drug_charges, 'FetchRow')) {
    $drug_row = $drug_charges->FetchRow();
    if ($drug_row && isset($drug_row['total_charges']) && $drug_row['total_charges'] !== null && $drug_row['total_charges'] !== '') {
        $drug_total = floatval($drug_row['total_charges']);
    }
}

$payment_total = 0.0;
if (is_object($payments) && method_exists($payments, 'FetchRow')) {
    $payment_row = $payments->FetchRow();
    if ($payment_row && isset($payment_row['total_payments']) && $payment_row['total_payments'] !== null && $payment_row['total_payments'] !== '') {
        $payment_total = floatval($payment_row['total_payments']);
    }
}

$total_charges = $billing_total + $drug_total;
$calculated_balance = $total_charges - $payment_total;

// Use the calculated balance as the primary source since it includes all charges
$patient_balance = $calculated_balance;

// Ensure balance is not negative
if ($patient_balance < 0) {
    $patient_balance = 0;
}

// Initialize variables
$error_message = '';
$stripe_payment = $_POST['stripe_payment'] ?? false;

// Handle form submission
if ($stripe_payment) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }
    
    $amount = floatval($_POST['amount']);
    $description = $_POST['description'] ?? 'Payment for ' . $patdata['fname'] . ' ' . $patdata['lname'];
    
    // Process payment using OpenEMR's PaymentGateway
    $pay = new PaymentGateway("Stripe");
    $transaction['amount'] = $amount;
    $transaction['currency'] = "USD";
    $transaction['token'] = $_POST['stripeToken'];
    $transaction['description'] = $description;
    $transaction['metadata'] = [
        'Patient' => $patdata['fname'] . ' ' . $patdata['lname'],
        'MRN' => $patdata['pubpid'],
        'Invoice Total' => $amount
    ];
    
    try {
        $response = $pay->submitPaymentToken($transaction);
        if (is_string($response)) {
            $error_message = $response;
        } else {
            if ($response->isSuccessful()) {
                // Record the payment in both payments table and ar_activity table
                $timestamp = date('Y-m-d H:i:s');
                $payment_id = 'stripe_' . time();
                
                // Record in payments table
                frontPayment($pid, 0, 'stripe', $payment_id, $amount, 0, $timestamp);
                
                // Also record in ar_activity table for proper balance calculation
                sqlBeginTrans();
                $sequence_no = sqlQuery("SELECT IFNULL(MAX(sequence_no),0) + 1 AS increment FROM ar_activity WHERE pid = ? AND encounter = 0", array($pid));
                $sequence_increment = 1;
                if (is_object($sequence_no) && method_exists($sequence_no, 'FetchRow')) {
                    $sequence_row = $sequence_no->FetchRow();
                    if ($sequence_row && isset($sequence_row['increment'])) {
                        $sequence_increment = $sequence_row['increment'];
                    }
                } elseif (is_array($sequence_no) && isset($sequence_no['increment'])) {
                    $sequence_increment = $sequence_no['increment'];
                }
                
                sqlStatement("INSERT INTO ar_activity SET " .
                    "pid = ?, " .
                    "encounter = 0, " .
                    "sequence_no = ?, " .
                    "code_type = 'stripe', " .
                    "code = ?, " .
                    "modifier = '', " .
                    "payer_type = 0, " .
                    "post_time = ?, " .
                    "post_user = ?, " .
                    "session_id = ?, " .
                    "modified_time = ?, " .
                    "pay_amount = ?, " .
                    "adj_amount = 0, " .
                    "account_code = 'PP'",
                    array($pid, $sequence_increment, $payment_id, $timestamp, $_SESSION['authUser'], $payment_id, $timestamp, $amount)
                );
                sqlCommitTrans();
                
                echo "<script>alert('" . xlj('Payment processed successfully') . "'); window.close();</script>";
            } else {
                $error_message = $response->getMessage();
            }
        }
    } catch (\Exception $ex) {
        $error_message = $ex->getMessage();
    }
}

// UI Setup
$arrOeUiSettings = array(
    'heading_title' => xl('Pay Now'),
    'include_patient_name' => true,
    'expandable' => false,
    'expandable_files' => array(),
    'action' => "",
    'action_title' => "",
    'action_href' => "",
    'show_help_icon' => false,
    'help_file_name' => ""
);
$oemr_ui = new OemrUI($arrOeUiSettings);

Header::setupHeader();
?>

<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Pay Now'); ?></title>
    <?php echo $oemr_ui->oeBelowContainerDiv(); ?>
    <script src="https://js.stripe.com/v3/"></script>
</head>
<body>
    <div class="container mt-3">
        <div class="row">
            <div class="col-sm-12">
                <?php echo $oemr_ui->pageHeading() . "\r\n"; ?>
            </div>
        </div>
        
        <div class="row">
            <div class="col-sm-12">
                <div class="card">
                    <div class="card-header">
                        <h4><?php echo xlt('Patient Information'); ?></h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <strong><?php echo xlt('Patient Name'); ?>:</strong> 
                                <?php echo text($patdata['fname'] . ' ' . $patdata['mname'] . ' ' . $patdata['lname']); ?>
                            </div>
                            <div class="col-md-6">
                                <strong><?php echo xlt('Patient ID'); ?>:</strong> 
                                <?php echo text($patdata['pubpid']); ?>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <strong><?php echo xlt('Outstanding Balance'); ?>:</strong> 
                                <span class="text-danger">$<?php echo text(number_format($patient_balance, 2)); ?></span>
                            </div>
                        </div>
                        

                    </div>
                </div>
                
                <div class="card mt-3">
                    <div class="card-header">
                        <h4><?php echo xlt('Payment Details'); ?></h4>
                    </div>
                    <div class="card-body">
                        <form id="payment-form" method="post">
                            <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                            <input type="hidden" name="stripe_payment" value="1" />
                            
                            <div class="form-group">
                                <label for="amount"><?php echo xlt('Payment Amount'); ?> ($)</label>
                                <input type="number" class="form-control" id="amount" name="amount" 
                                       step="0.01" min="0.01" max="<?php echo $patient_balance; ?>" 
                                       value="<?php echo $patient_balance; ?>" required>
                                <small class="form-text text-muted">
                                    <?php echo xlt('Outstanding balance'); ?>: $<?php echo text(number_format($patient_balance, 2)); ?>
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <label for="description"><?php echo xlt('Payment Description'); ?></label>
                                <input type="text" class="form-control" id="description" name="description" 
                                       value="<?php echo attr('Payment for ' . $patdata['fname'] . ' ' . $patdata['lname']); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="card-element"><?php echo xlt('Credit or Debit Card'); ?></label>
                                <div id="card-element" class="form-control">
                                    <!-- Stripe Elements will be inserted here -->
                                </div>
                                <div id="card-errors" class="text-danger mt-2" role="alert"></div>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary" id="submit-button">
                                    <i class="fa fa-credit-card"></i> <?php echo xlt('Process Payment'); ?>
                                </button>
                                <button type="button" class="btn btn-secondary ml-2" onclick="window.close()">
                                    <?php echo xlt('Cancel'); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger mt-3">
                    <?php echo text($error_message); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Initialize Stripe
        const stripe = Stripe('<?php 
            $cryptoGen = new OpenEMR\Common\Crypto\CryptoGen();
            echo attr($cryptoGen->decryptStandard($GLOBALS['gateway_public_key'] ?? '')); 
        ?>');
        const elements = stripe.elements();
        
        // Create card element
        const card = elements.create('card', {
            style: {
                base: {
                    fontSize: '16px',
                    color: '#424770',
                    '::placeholder': {
                        color: '#aab7c4',
                    },
                },
                invalid: {
                    color: '#9e2146',
                },
            },
        });
        
        card.mount('#card-element');
        
        // Handle form submission
        const form = document.getElementById('payment-form');
        const submitButton = document.getElementById('submit-button');
        
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            submitButton.disabled = true;
            submitButton.innerHTML = '<?php echo xlj("Processing..."); ?>';
            
            const {token, error} = await stripe.createToken(card);
            
            if (error) {
                const errorElement = document.getElementById('card-errors');
                errorElement.textContent = error.message;
                submitButton.disabled = false;
                submitButton.innerHTML = '<i class="fa fa-credit-card"></i> <?php echo xlj("Process Payment"); ?>';
            } else {
                // Add token to form and submit
                const hiddenInput = document.createElement('input');
                hiddenInput.setAttribute('type', 'hidden');
                hiddenInput.setAttribute('name', 'stripeToken');
                hiddenInput.setAttribute('value', token.id);
                form.appendChild(hiddenInput);
                
                form.submit();
            }
        });
        
        // Handle card errors
        card.addEventListener('change', ({error}) => {
            const displayError = document.getElementById('card-errors');
            if (error) {
                displayError.textContent = error.message;
            } else {
                displayError.textContent = '';
            }
        });
    </script>
</body>
</html> 
 
 