<?php

/**
 * Versapay POS Interface
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Meet Patel <meetpatel1798@gmail.com>
 * @copyright Copyright (c) 2024 Meet Patel
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/../../src/Billing/VersapayGateway.php");

use OpenEMR\Billing\VersapayGateway;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

// Check if user has billing permissions
if (!AclMain::aclCheckCore('acct', 'bill', '', 'write')) {
    echo xlt('Access Denied');
    exit;
}

$versapay = new VersapayGateway();
$isConfigured = $versapay->isConfigured();
$supportedMethods = $versapay->getSupportedPaymentMethods();
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Versapay POS System'); ?></title>
    <?php Header::setupHeader(['bootstrap', 'fontawesome', 'jquery']); ?>
    <style>
        .pos-container {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .pos-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .pos-header {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 20px;
            border-radius: 15px 15px 0 0;
            text-align: center;
        }
        .pos-body {
            padding: 30px;
        }
        .patient-search {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .service-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .service-item {
            background: #e3f2fd;
            border: 2px solid #2196f3;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .service-item:hover {
            background: #2196f3;
            color: white;
            transform: translateY(-2px);
        }
        .cart-container {
            background: #f5f5f5;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #ddd;
        }
        .payment-methods {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }
        .payment-method {
            background: #fff;
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .payment-method.selected {
            border-color: #2196f3;
            background: #e3f2fd;
        }
        .payment-method:hover {
            border-color: #2196f3;
        }
        .btn-pos {
            background: linear-gradient(135deg, #2196f3 0%, #1976d2 100%);
            border: none;
            border-radius: 8px;
            color: white;
            padding: 12px 24px;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        .btn-pos:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(33, 150, 243, 0.3);
        }
        .btn-success {
            background: linear-gradient(135deg, #4caf50 0%, #388e3c 100%);
        }
        .btn-danger {
            background: linear-gradient(135deg, #f44336 0%, #d32f2f 100%);
        }
        .total-display {
            background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            font-size: 24px;
            font-weight: bold;
        }
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        .status-connected { background: #4caf50; }
        .status-disconnected { background: #f44336; }
        .status-unknown { background: #ff9800; }
    </style>
</head>
<body>
    <div class="pos-container">
        <div class="pos-card">
            <div class="pos-header">
                <h2><i class="fas fa-cash-register"></i> <?php echo xlt('Versapay POS System'); ?></h2>
                <div class="mt-2">
                    <span class="status-indicator <?php echo $isConfigured ? 'status-connected' : 'status-disconnected'; ?>"></span>
                    <?php echo $isConfigured ? xlt('Connected') : xlt('Not Configured'); ?>
                </div>
            </div>
            
            <div class="pos-body">
                <?php if (!$isConfigured): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo xlt('Versapay is not configured. Please configure the payment gateway in the administration settings.'); ?>
                    </div>
                <?php else: ?>
                    
                    <!-- Patient Search -->
                    <div class="patient-search">
                        <h4><i class="fas fa-user-search"></i> <?php echo xlt('Patient Search'); ?></h4>
                        <div class="row">
                            <div class="col-md-8">
                                <input type="text" id="patient-search" class="form-control" placeholder="<?php echo xla('Search by name, ID, or phone'); ?>">
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-pos" onclick="searchPatient()">
                                    <i class="fas fa-search"></i> <?php echo xlt('Search'); ?>
                                </button>
                            </div>
                        </div>
                        <div id="patient-results" class="mt-3"></div>
                    </div>

                    <!-- Selected Patient -->
                    <div id="selected-patient" style="display: none;">
                        <div class="alert alert-info">
                            <h5><i class="fas fa-user"></i> <?php echo xlt('Selected Patient'); ?></h5>
                            <div id="patient-info"></div>
                        </div>
                    </div>

                    <!-- Services -->
                    <div class="service-grid">
                        <div class="service-item" onclick="addService('consultation', 75.00, 'Consultation')">
                            <i class="fas fa-stethoscope fa-2x"></i>
                            <h5><?php echo xlt('Consultation'); ?></h5>
                            <p>$75.00</p>
                        </div>
                        <div class="service-item" onclick="addService('examination', 120.00, 'Physical Examination')">
                            <i class="fas fa-heartbeat fa-2x"></i>
                            <h5><?php echo xlt('Physical Exam'); ?></h5>
                            <p>$120.00</p>
                        </div>
                        <div class="service-item" onclick="addService('lab_work', 85.00, 'Lab Work')">
                            <i class="fas fa-flask fa-2x"></i>
                            <h5><?php echo xlt('Lab Work'); ?></h5>
                            <p>$85.00</p>
                        </div>
                        <div class="service-item" onclick="addService('xray', 150.00, 'X-Ray')">
                            <i class="fas fa-x-ray fa-2x"></i>
                            <h5><?php echo xlt('X-Ray'); ?></h5>
                            <p>$150.00</p>
                        </div>
                        <div class="service-item" onclick="addService('medication', 45.00, 'Medication')">
                            <i class="fas fa-pills fa-2x"></i>
                            <h5><?php echo xlt('Medication'); ?></h5>
                            <p>$45.00</p>
                        </div>
                        <div class="service-item" onclick="addService('procedure', 200.00, 'Procedure')">
                            <i class="fas fa-procedures fa-2x"></i>
                            <h5><?php echo xlt('Procedure'); ?></h5>
                            <p>$200.00</p>
                        </div>
                    </div>

                    <!-- Cart -->
                    <div class="cart-container">
                        <h4><i class="fas fa-shopping-cart"></i> <?php echo xlt('Cart'); ?></h4>
                        <div id="cart-items"></div>
                        <div class="total-display">
                            <div><?php echo xlt('Total'); ?>: $<span id="cart-total">0.00</span></div>
                        </div>
                        <div class="mt-3">
                            <button class="btn btn-danger" onclick="clearCart()">
                                <i class="fas fa-trash"></i> <?php echo xlt('Clear Cart'); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Payment Methods -->
                    <div class="payment-methods">
                        <?php foreach ($supportedMethods as $method => $label): ?>
                            <div class="payment-method" data-method="<?php echo attr($method); ?>" onclick="selectPaymentMethod('<?php echo attr($method); ?>')">
                                <i class="fas fa-credit-card fa-2x"></i>
                                <div><?php echo xlt($label); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Payment Form -->
                    <div id="payment-form" style="display: none;">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-credit-card"></i> <?php echo xlt('Payment Details'); ?></h5>
                            </div>
                            <div class="card-body">
                                <div id="payment-fields"></div>
                                <button class="btn btn-success btn-pos mt-3" onclick="processPayment()">
                                    <i class="fas fa-check"></i> <?php echo xlt('Process Payment'); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        let cartItems = [];
        let selectedPatient = null;
        let selectedPaymentMethod = null;
        let cartTotal = 0;

        // Patient search
        function searchPatient() {
            const searchTerm = $('#patient-search').val();
            if (!searchTerm) return;

            $.post('pos_interface.php', {
                action: 'search_patient',
                search: searchTerm
            }, function(data) {
                const results = JSON.parse(data);
                let html = '';
                
                if (results.length > 0) {
                    results.forEach(patient => {
                        html += `
                            <div class="card mb-2" onclick="selectPatient(${patient.pid}, '${patient.name}')">
                                <div class="card-body">
                                    <h6>${patient.name}</h6>
                                    <small>ID: ${patient.pubpid} | DOB: ${patient.dob}</small>
                                </div>
                            </div>
                        `;
                    });
                } else {
                    html = '<div class="alert alert-info"><?php echo xla("No patients found"); ?></div>';
                }
                
                $('#patient-results').html(html);
            });
        }

        // Select patient
        function selectPatient(pid, name) {
            selectedPatient = { pid: pid, name: name };
            $('#patient-info').html(`
                <strong>${name}</strong> (ID: ${pid})
                <button class="btn btn-sm btn-outline-secondary ml-2" onclick="clearPatient()">
                    <i class="fas fa-times"></i>
                </button>
            `);
            $('#selected-patient').show();
            $('#patient-results').empty();
        }

        // Clear patient
        function clearPatient() {
            selectedPatient = null;
            $('#selected-patient').hide();
        }

        // Add service to cart
        function addService(code, price, name) {
            if (!selectedPatient) {
                alert('<?php echo xla("Please select a patient first"); ?>');
                return;
            }

            cartItems.push({
                code: code,
                name: name,
                price: price,
                quantity: 1
            });
            
            updateCart();
        }

        // Update cart display
        function updateCart() {
            let html = '';
            cartTotal = 0;
            
            cartItems.forEach((item, index) => {
                html += `
                    <div class="cart-item">
                        <div>
                            <strong>${item.name}</strong><br>
                            <small>$${item.price.toFixed(2)} x ${item.quantity}</small>
                        </div>
                        <div>
                            <span class="badge badge-primary">$${(item.price * item.quantity).toFixed(2)}</span>
                            <button class="btn btn-sm btn-danger ml-2" onclick="removeItem(${index})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                `;
                cartTotal += item.price * item.quantity;
            });
            
            $('#cart-items').html(html);
            $('#cart-total').text(cartTotal.toFixed(2));
        }

        // Remove item from cart
        function removeItem(index) {
            cartItems.splice(index, 1);
            updateCart();
        }

        // Clear cart
        function clearCart() {
            cartItems = [];
            updateCart();
        }

        // Select payment method
        function selectPaymentMethod(method) {
            selectedPaymentMethod = method;
            $('.payment-method').removeClass('selected');
            $(`.payment-method[data-method="${method}"]`).addClass('selected');
            
            showPaymentForm(method);
        }

        // Show payment form
        function showPaymentForm(method) {
            let html = '';
            
            switch(method) {
                case 'credit_card':
                case 'debit_card':
                    html = `
                        <div class="form-group">
                            <label><?php echo xla("Card Number"); ?></label>
                            <input type="text" id="card-number" class="form-control" placeholder="1234 5678 9012 3456">
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><?php echo xla("Expiry Month"); ?></label>
                                    <select id="expiry-month" class="form-control">
                                        ${generateMonthOptions()}
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><?php echo xla("Expiry Year"); ?></label>
                                    <select id="expiry-year" class="form-control">
                                        ${generateYearOptions()}
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><?php echo xla("CVV"); ?></label>
                            <input type="text" id="cvv" class="form-control" placeholder="123" maxlength="4">
                        </div>
                    `;
                    break;
                case 'gift_card':
                    html = `
                        <div class="form-group">
                            <label><?php echo xla("Gift Card Number"); ?></label>
                            <input type="text" id="gift-card-number" class="form-control" placeholder="Gift card number">
                        </div>
                    `;
                    break;
                case 'cash':
                    html = `
                        <div class="form-group">
                            <label><?php echo xla("Amount Tendered"); ?></label>
                            <input type="number" id="amount-tendered" class="form-control" value="${cartTotal.toFixed(2)}" step="0.01">
                        </div>
                    `;
                    break;
            }
            
            $('#payment-fields').html(html);
            $('#payment-form').show();
        }

        // Generate month options
        function generateMonthOptions() {
            let html = '';
            for (let i = 1; i <= 12; i++) {
                html += `<option value="${i.toString().padStart(2, '0')}">${i.toString().padStart(2, '0')}</option>`;
            }
            return html;
        }

        // Generate year options
        function generateYearOptions() {
            let html = '';
            const currentYear = new Date().getFullYear();
            for (let i = currentYear; i <= currentYear + 10; i++) {
                html += `<option value="${i}">${i}</option>`;
            }
            return html;
        }

        // Process payment
        function processPayment() {
            if (!selectedPatient || cartItems.length === 0 || !selectedPaymentMethod) {
                alert('<?php echo xla("Please select patient, add items to cart, and choose payment method"); ?>');
                return;
            }

            const paymentData = {
                form_pid: selectedPatient.pid,
                payment: cartTotal.toFixed(2),
                payment_method: selectedPaymentMethod,
                mode: 'Versapay'
            };

            // Add payment method specific data
            switch(selectedPaymentMethod) {
                case 'credit_card':
                case 'debit_card':
                    paymentData.card_number = $('#card-number').val();
                    paymentData.expiry_month = $('#expiry-month').val();
                    paymentData.expiry_year = $('#expiry-year').val();
                    paymentData.cvv = $('#cvv').val();
                    break;
                case 'gift_card':
                    paymentData.gift_card_number = $('#gift-card-number').val();
                    break;
                case 'cash':
                    paymentData.amount_tendered = $('#amount-tendered').val();
                    break;
            }

            // Process payment
            $.post('front_payment_versapay.php', paymentData, function(response) {
                const result = JSON.parse(response);
                
                if (result.success) {
                    alert('<?php echo xla("Payment processed successfully!"); ?>');
                    clearCart();
                    clearPatient();
                    $('#payment-form').hide();
                    $('.payment-method').removeClass('selected');
                } else {
                    alert('Error: ' + result.error);
                }
            });
        }

        // Initialize
        $(document).ready(function() {
            updateCart();
        });
    </script>
</body>
</html> 