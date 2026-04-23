<?php
/**
 * Stripe Payment Interface for OpenEMR - Modal Version
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
error_log("Form submission received - stripe_payment: " . ($stripe_payment ? 'true' : 'false') . ", POST data: " . print_r($_POST, true));
error_log("Raw POST data: " . file_get_contents('php://input'));
error_log("Request method: " . $_SERVER['REQUEST_METHOD']);
error_log("Content type: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));

if ($stripe_payment) {
    error_log("Stripe payment processing started for PID: " . $pid);
    
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"] ?? '')) {
        error_log("CSRF token verification failed");
        CsrfUtils::csrfNotVerified();
    }
    
    $amount = floatval($_POST['amount']);
    $description = $_POST['description'] ?? 'Payment for ' . $patdata['fname'] . ' ' . $patdata['lname'];
    $stripeToken = $_POST['stripeToken'] ?? '';
    
    error_log("Payment details - Amount: $amount, Description: $description, Token: " . substr($stripeToken, 0, 10) . "...");
    
    if (empty($stripeToken)) {
        $error_message = xlt('Payment token is missing. Please try again.');
        error_log("Stripe token is missing");
    } else {
    
    // Process payment using OpenEMR's PaymentGateway
    $pay = new PaymentGateway("Stripe");
    $transaction['amount'] = $amount;
    $transaction['currency'] = "USD";
    $transaction['token'] = $stripeToken;
    $transaction['description'] = $description;
    $transaction['metadata'] = [
        'Patient' => $patdata['fname'] . ' ' . $patdata['lname'],
        'MRN' => $patdata['pubpid'],
        'Invoice Total' => $amount
    ];
    
    try {
        error_log("Submitting payment to Stripe...");
        $response = $pay->submitPaymentToken($transaction);
        error_log("Stripe response received: " . (is_string($response) ? $response : get_class($response)));
        
        if (is_string($response)) {
            $error_message = $response;
            error_log("Stripe payment failed: " . $response);
        } else {
            if ($response->isSuccessful()) {
                error_log("Stripe payment successful, recording in database...");
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
                error_log("Payment recorded successfully in database");
                
                // Check if this is an AJAX request
                if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    // Return JSON response for AJAX
                    error_log("Sending JSON success response for AJAX request");
                    header('Content-Type: application/json');
                    $response = json_encode(['success' => true, 'message' => 'Payment processed successfully']);
                    error_log("JSON response: " . $response);
                    echo $response;
                } else {
                    // Return HTML response for regular form submission
                    echo "<script>
                        console.log('Payment processed successfully in PHP');
                        alert('" . xlj('Payment processed successfully') . "');
                        $('#paymentModal').modal('hide');
                        // Refresh the patient finder table
                        if (typeof oTable !== 'undefined') {
                            oTable.fnDraw();
                        }
                    </script>";
                }
                exit;
            } else {
                $error_message = $response->getMessage();
                error_log("Stripe payment not successful: " . $error_message);
            }
        }
    } catch (\Exception $ex) {
        $error_message = $ex->getMessage();
        error_log("Exception during payment processing: " . $ex->getMessage());
    }
    }
    
    // Handle error responses
    if (!empty($error_message)) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            // Return JSON error response for AJAX
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error_message]);
        }
        // For regular form submission, the error will be displayed in the modal
    }
}

// Get Stripe public key
$cryptoGen = new CryptoGen();
$stripePublicKey = $cryptoGen->decryptStandard($GLOBALS['gateway_public_key'] ?? '');

// Debug: Check if Stripe is properly configured
if (empty($stripePublicKey)) {
    error_log("Stripe public key is empty or not configured");
    die(xlt('Stripe payment gateway is not properly configured. Please contact your administrator.'));
}
?>

<!-- Modal Content -->
<style>
    #card-element {
        padding: 12px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        background-color: #fff;
        min-height: 40px;
    }
    #card-element.StripeElement--focus {
        border-color: #80bdff;
        outline: 0;
        box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
    }
    #card-element.StripeElement--invalid {
        border-color: #dc3545;
    }
    #card-errors {
        font-size: 14px;
        margin-top: 8px;
    }
</style>
<div class="container-fluid">
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
                    <form id="payment-form" method="post" action="">
                        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                        <input type="hidden" name="stripe_payment" value="1" />
                        <input type="hidden" name="pid" value="<?php echo attr($pid); ?>" />
                        
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
                            <small class="form-text text-muted">
                                <?php echo xlt('Test Card'); ?>: 4242 4242 4242 4242 | <?php echo xlt('Expiry'); ?>: 12/25 | <?php echo xlt('CVC'); ?>: 123
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary" id="submit-button">
                                <i class="fa fa-credit-card"></i> <?php echo xlt('Process Payment'); ?>
                            </button>
                            <button type="button" class="btn btn-secondary ml-2" onclick="$('#paymentModal').modal('hide')">
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

<script src="https://js.stripe.com/v3/"></script>
<script>
    // Initialize Stripe Elements after modal is fully shown
    $(document).ready(function() {
        // Wait for modal to be fully shown
        $('#paymentModal').on('shown.bs.modal', function() {
            console.log('Modal shown, initializing Stripe Elements...');
            
            // Small delay to ensure DOM is ready
            setTimeout(function() {
            
            // Initialize Stripe
            console.log('Stripe public key:', '<?php echo attr($stripePublicKey); ?>');
            
            // Check if Stripe is available
            if (typeof Stripe === 'undefined') {
                throw new Error('Stripe library is not loaded');
            }
            
            const stripe = Stripe('<?php echo attr($stripePublicKey); ?>');
            const elements = stripe.elements();
            console.log('Stripe and elements initialized');
            
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
            
            // Mount the card element
            card.mount('#card-element');
            console.log('Stripe card element mounted');
            
            // Test if card element is properly mounted
            setTimeout(() => {
                const cardElement = document.getElementById('card-element');
                if (cardElement && cardElement.children.length > 0) {
                    console.log('Card element is properly mounted with', cardElement.children.length, 'children');
                } else {
                    console.error('Card element is not properly mounted');
                }
            }, 200);
            
            // Handle form submission - use event delegation to avoid cloning issues
            const form = document.getElementById('payment-form');
            const submitButton = document.getElementById('submit-button');
            
            // Use a flag to prevent multiple submissions
            let isProcessing = false;
            
            form.addEventListener('submit', async (event) => {
                // Prevent multiple submissions
                if (isProcessing) {
                    event.preventDefault();
                    return;
                }
                
                console.log('Form submission started...');
                
                submitButton.disabled = true;
                submitButton.innerHTML = '<?php echo xlj("Processing..."); ?>';
                
                try {
                    console.log('Creating Stripe token...');
                    console.log('Card object:', card);
                    
                    // Check if card is properly initialized
                    if (!card || typeof card.mount !== 'function') {
                        throw new Error('Card element is not properly initialized');
                    }
                    
                    const {token, error} = await stripe.createToken(card);
                    console.log('Stripe response:', {token: token ? 'present' : 'null', error: error ? error.message : 'none'});
                    
                    if (error) {
                        const errorElement = document.getElementById('card-errors');
                        errorElement.textContent = error.message;
                        submitButton.disabled = false;
                        submitButton.innerHTML = '<i class="fa fa-credit-card"></i> <?php echo xlj("Process Payment"); ?>';
                        isProcessing = false;
                        console.error('Stripe token error:', error);
                    } else if (token) {
                        console.log('Stripe token created successfully:', token.id);
                        
                        // Add token to form and submit
                        const hiddenInput = document.createElement('input');
                        hiddenInput.setAttribute('type', 'hidden');
                        hiddenInput.setAttribute('name', 'stripeToken');
                        hiddenInput.setAttribute('value', token.id);
                        form.appendChild(hiddenInput);
                        
                        console.log('Form data before submission:', new FormData(form));
                        console.log('Submitting form with token via AJAX...');
                        
                        // Submit form via AJAX instead of regular form submission
                        const formData = new FormData(form);
                        
                        // Add the Stripe token to form data
                        formData.append('stripeToken', token.id);
                        
                        console.log('FormData contents:');
                        for (let [key, value] of formData.entries()) {
                            console.log(key + ': ' + value);
                        }
                        
                        const ajaxUrl = '../../patient_file/front_payment_stripe_modal.php?pid=<?php echo attr($pid); ?>';
                        console.log('AJAX URL:', ajaxUrl);
                        
                        $.ajax({
                            url: ajaxUrl,
                            method: 'POST',
                            data: formData,
                            processData: false,
                            contentType: false,
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            success: function(response) {
                                console.log('Payment response received:', response);
                                console.log('Response type:', typeof response);
                                console.log('Response length:', response.length);
                                
                                try {
                                    // Try to parse as JSON first
                                    const jsonResponse = JSON.parse(response);
                                    console.log('Parsed JSON response:', jsonResponse);
                                    if (jsonResponse.success) {
                                        console.log('Payment successful, closing modal and refreshing table');
                                        alert(jsonResponse.message || '<?php echo xlj("Payment processed successfully"); ?>');
                                        $('#paymentModal').modal('hide');
                                        // Refresh the patient finder table
                                        if (typeof oTable !== 'undefined') {
                                            oTable.fnDraw();
                                        }
                                    } else {
                                        const errorElement = document.getElementById('card-errors');
                                        errorElement.textContent = jsonResponse.error || 'Payment failed';
                                        submitButton.disabled = false;
                                        submitButton.innerHTML = '<i class="fa fa-credit-card"></i> <?php echo xlj("Process Payment"); ?>';
                                        isProcessing = false;
                                    }
                                } catch (e) {
                                    console.log('JSON parsing failed:', e);
                                    console.log('Raw response:', response);
                                    // If not JSON, check for success message in HTML
                                    if (response.includes('Payment processed successfully') || response.includes('success')) {
                                        alert('Payment processed successfully');
                                        $('#paymentModal').modal('hide');
                                        // Refresh the patient finder table
                                        if (typeof oTable !== 'undefined') {
                                            oTable.fnDraw();
                                        }
                                    } else {
                                        // Show error response
                                        $('#paymentModalBody').html(response);
                                    }
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('Payment submission error:', error);
                                console.error('Response:', xhr.responseText);
                                const errorElement = document.getElementById('card-errors');
                                errorElement.textContent = 'Payment submission failed: ' + error;
                                submitButton.disabled = false;
                                submitButton.innerHTML = '<i class="fa fa-credit-card"></i> <?php echo xlj("Process Payment"); ?>';
                                isProcessing = false;
                            }
                        });
                    } else {
                        throw new Error('No token or error received from Stripe');
                    }
                } catch (err) {
                    console.error('Error during payment processing:', err);
                    console.error('Error details:', {
                        message: err.message,
                        stack: err.stack,
                        name: err.name
                    });
                    const errorElement = document.getElementById('card-errors');
                    errorElement.textContent = 'An unexpected error occurred: ' + err.message;
                    submitButton.disabled = false;
                    submitButton.innerHTML = '<i class="fa fa-credit-card"></i> <?php echo xlj("Process Payment"); ?>';
                    isProcessing = false;
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
            }, 100); // 100ms delay
        });
    });
</script> 