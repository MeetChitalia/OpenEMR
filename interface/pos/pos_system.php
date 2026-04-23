<?php
/**
 * OpenEMR POS (Point of Sale) System
 * 
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Team
 * @copyright Copyright (c) 2024 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/payment.inc.php");

use OpenEMR\Billing\PaymentGateway;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\OeUI\OemrUI;

// Ensure user is logged in
if (!isset($_SESSION['authUserID'])) {
    die(xlt('Not authorized'));
}

// Get patient ID from URL
$pid = $_GET['pid'] ?? 0;
$patient_data = null;

if ($pid) {
    $patient_data = getPatientData($pid, 'fname,mname,lname,pubpid,DOB,phone_home,email');
    if (!$patient_data) {
        die(xlt('Patient not found'));
    }
}

// Get patient balance
$patient_balance = 0;
if ($pid) {
    $patient_balance = get_patient_balance($pid, true);
    if ($patient_balance < 0) {
        $patient_balance = 0;
    }
}

// Get available services for POS
$services = sqlStatement("SELECT * FROM list_options WHERE list_id = 'pos_services' AND activity = 1 ORDER BY seq, title");

// UI Setup
$arrOeUiSettings = array(
    'heading_title' => xl('POS System'),
    'include_patient_name' => false,
    'expandable' => false,
    'expandable_files' => array(),
    'action' => "",
    'action_title' => "",
    'action_href' => "",
    'show_help_icon' => false,
    'help_file_name' => ""
);
$oemr_ui = new OemrUI($arrOeUiSettings);

Header::setupHeader(['opener']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo xlt('POS System'); ?></title>
    <script src="https://js.stripe.com/v3/"></script>
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-gray: #ecf0f1;
            --dark-gray: #7f8c8d;
            --white: #ffffff;
            --shadow: 0 2px 10px rgba(0,0,0,0.1);
            --border-radius: 8px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .pos-container {
            max-width: 1400px;
            margin: 0 auto;
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .pos-header {
            background: var(--primary-color);
            color: var(--white);
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .pos-header h1 {
            font-size: 2rem;
            font-weight: 300;
        }

        .pos-header .header-actions {
            display: flex;
            gap: 10px;
        }

        .pos-content {
            display: grid;
            grid-template-columns: 1fr 400px;
            min-height: 600px;
        }

        .pos-main {
            padding: 20px;
            border-right: 1px solid var(--light-gray);
        }

        .pos-sidebar {
            background: var(--light-gray);
            padding: 20px;
        }

        .patient-info {
            background: var(--white);
            padding: 20px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }

        .patient-info h3 {
            color: var(--primary-color);
            margin-bottom: 15px;
            font-size: 1.2rem;
        }

        .patient-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }

        .patient-detail {
            display: flex;
            flex-direction: column;
        }

        .patient-detail label {
            font-size: 0.8rem;
            color: var(--dark-gray);
            margin-bottom: 2px;
        }

        .patient-detail span {
            font-weight: 600;
            color: var(--primary-color);
        }

        .patient-balance {
            background: linear-gradient(135deg, var(--success-color), #2ecc71);
            color: var(--white);
            padding: 15px;
            border-radius: var(--border-radius);
            text-align: center;
        }

        .patient-balance h4 {
            font-size: 0.9rem;
            margin-bottom: 5px;
            opacity: 0.9;
        }

        .patient-balance .balance-amount {
            font-size: 2rem;
            font-weight: 700;
        }

        .services-section {
            margin-top: 30px;
        }

        .services-section h3 {
            color: var(--primary-color);
            margin-bottom: 20px;
            font-size: 1.3rem;
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }

        .service-card {
            background: var(--white);
            border: 2px solid var(--light-gray);
            border-radius: var(--border-radius);
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }

        .service-card:hover {
            border-color: var(--secondary-color);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .service-card.selected {
            border-color: var(--success-color);
            background: #f8fff9;
        }

        .service-icon {
            font-size: 2rem;
            color: var(--secondary-color);
            margin-bottom: 10px;
        }

        .service-name {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .service-price {
            font-size: 1.2rem;
            color: var(--success-color);
            font-weight: 700;
        }

        .cart-section {
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .cart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .cart-header h3 {
            color: var(--primary-color);
            font-size: 1.3rem;
        }

        .cart-items {
            flex: 1;
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 15px;
            margin-bottom: 20px;
            max-height: 300px;
            overflow-y: auto;
        }

        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid var(--light-gray);
        }

        .cart-item:last-child {
            border-bottom: none;
        }

        .cart-item-name {
            font-weight: 600;
            color: var(--primary-color);
        }

        .cart-item-price {
            color: var(--success-color);
            font-weight: 600;
        }

        .cart-item-remove {
            background: var(--danger-color);
            color: var(--white);
            border: none;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            cursor: pointer;
            font-size: 0.8rem;
        }

        .cart-total {
            background: var(--primary-color);
            color: var(--white);
            padding: 20px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
        }

        .cart-total h4 {
            font-size: 1.1rem;
            margin-bottom: 10px;
        }

        .cart-total .total-amount {
            font-size: 2.5rem;
            font-weight: 700;
        }

        .payment-section {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 20px;
        }

        .payment-section h4 {
            color: var(--primary-color);
            margin-bottom: 15px;
        }

        .payment-methods {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 20px;
        }

        .payment-method {
            padding: 15px;
            border: 2px solid var(--light-gray);
            border-radius: var(--border-radius);
            cursor: pointer;
            text-align: center;
            transition: all 0.3s ease;
        }

        .payment-method:hover {
            border-color: var(--secondary-color);
        }

        .payment-method.selected {
            border-color: var(--success-color);
            background: #f8fff9;
        }

        .payment-method i {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .payment-method.credit-card i { color: #2c3e50; }
        .payment-method.cash i { color: #27ae60; }
        .payment-method.check i { color: #3498db; }

        .stripe-form {
            display: none;
            margin-top: 15px;
        }

        .stripe-form.active {
            display: block;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: var(--primary-color);
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--light-gray);
            border-radius: var(--border-radius);
            font-size: 1rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-primary {
            background: var(--secondary-color);
            color: var(--white);
        }

        .btn-primary:hover {
            background: #2980b9;
        }

        .btn-success {
            background: var(--success-color);
            color: var(--white);
        }

        .btn-success:hover {
            background: #229954;
        }

        .btn-danger {
            background: var(--danger-color);
            color: var(--white);
        }

        .btn-danger:hover {
            background: #c0392b;
        }

        .btn-warning {
            background: var(--warning-color);
            color: var(--white);
        }

        .btn-warning:hover {
            background: #e67e22;
        }

        .btn-block {
            width: 100%;
        }

        .btn-lg {
            padding: 15px 30px;
            font-size: 1.1rem;
        }

        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }

        .loading.active {
            display: block;
        }

        .spinner {
            border: 4px solid var(--light-gray);
            border-top: 4px solid var(--secondary-color);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .receipt-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
        }

        .receipt-modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .receipt-content {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }

        .receipt-header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light-gray);
        }

        .receipt-header h2 {
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .receipt-details {
            margin-bottom: 20px;
        }

        .receipt-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }

        .receipt-total {
            font-weight: 700;
            font-size: 1.2rem;
            border-top: 2px solid var(--light-gray);
            padding-top: 10px;
            margin-top: 10px;
        }

        @media (max-width: 768px) {
            .pos-content {
                grid-template-columns: 1fr;
            }
            
            .pos-sidebar {
                order: -1;
            }
            
            .services-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }
            
            .payment-methods {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="pos-container">
        <!-- Header -->
        <div class="pos-header">
            <h1><i class="fa fa-cash-register"></i> <?php echo xlt('Point of Sale System'); ?></h1>
            <div class="header-actions">
                <?php if (acl_check('admin', 'super')): ?>
                <a href="pos_defective_medicines_manager.php" class="btn btn-info" target="_blank">
                    <i class="fa fa-shield-alt"></i> <?php echo xlt('Defective Medicines Manager'); ?>
                </a>
                <a href="inventory_import.php" class="btn btn-success" target="_blank">
                    <i class="fa fa-upload"></i> <?php echo xlt('Import Inventory'); ?>
                </a>
                <?php endif; ?>
                <button class="btn btn-warning" onclick="clearCart()">
                    <i class="fa fa-trash"></i> <?php echo xlt('Clear Cart'); ?>
                </button>
                <button class="btn btn-danger" onclick="window.close()">
                    <i class="fa fa-times"></i> <?php echo xlt('Close'); ?>
                </button>
            </div>
        </div>

        <!-- Main Content -->
        <div class="pos-content">
            <!-- Left Side - Services -->
            <div class="pos-main">
                <?php if ($patient_data): ?>
                <!-- Patient Information -->
                <div class="patient-info">
                    <h3><i class="fa fa-user"></i> <?php echo xlt('Patient Information'); ?></h3>
                    <div class="patient-details">
                        <div class="patient-detail">
                            <label><?php echo xlt('Name'); ?></label>
                            <span><?php echo text($patient_data['fname'] . ' ' . $patient_data['lname']); ?></span>
                        </div>
                        <div class="patient-detail">
                            <label><?php echo xlt('Patient ID'); ?></label>
                            <span><?php echo text($patient_data['pubpid']); ?></span>
                        </div>
                        <div class="patient-detail">
                            <label><?php echo xlt('Date of Birth'); ?></label>
                            <span><?php echo text(oeFormatShortDate($patient_data['DOB'])); ?></span>
                        </div>
                        <div class="patient-detail">
                            <label><?php echo xlt('Phone'); ?></label>
                            <span><?php echo text($patient_data['phone_home']); ?></span>
                        </div>
                    </div>
                    <div class="patient-balance">
                        <h4><?php echo xlt('Current Balance'); ?></h4>
                        <div class="balance-amount">$<?php echo number_format($patient_balance, 2); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Services Section -->
                <div class="services-section">
                    <h3><i class="fa fa-list"></i> <?php echo xlt('Available Services'); ?></h3>
                    <div class="services-grid">
                        <!-- Consultation -->
                        <div class="service-card" data-service="consultation" data-price="75.00">
                            <div class="service-icon">
                                <i class="fa fa-stethoscope"></i>
                            </div>
                            <div class="service-name"><?php echo xlt('Consultation'); ?></div>
                            <div class="service-price">$75.00</div>
                        </div>

                        <!-- Physical Exam -->
                        <div class="service-card" data-service="physical_exam" data-price="120.00">
                            <div class="service-icon">
                                <i class="fa fa-heartbeat"></i>
                            </div>
                            <div class="service-name"><?php echo xlt('Physical Exam'); ?></div>
                            <div class="service-price">$120.00</div>
                        </div>

                        <!-- Lab Work -->
                        <div class="service-card" data-service="lab_work" data-price="85.00">
                            <div class="service-icon">
                                <i class="fa fa-flask"></i>
                            </div>
                            <div class="service-name"><?php echo xlt('Lab Work'); ?></div>
                            <div class="service-price">$85.00</div>
                        </div>

                        <!-- X-Ray -->
                        <div class="service-card" data-service="xray" data-price="150.00">
                            <div class="service-icon">
                                <i class="fa fa-x-ray"></i>
                            </div>
                            <div class="service-name"><?php echo xlt('X-Ray'); ?></div>
                            <div class="service-price">$150.00</div>
                        </div>

                        <!-- Vaccination -->
                        <div class="service-card" data-service="vaccination" data-price="45.00">
                            <div class="service-icon">
                                <i class="fa fa-syringe"></i>
                            </div>
                            <div class="service-name"><?php echo xlt('Vaccination'); ?></div>
                            <div class="service-price">$45.00</div>
                        </div>

                        <!-- Prescription -->
                        <div class="service-card" data-service="prescription" data-price="25.00">
                            <div class="service-icon">
                                <i class="fa fa-prescription"></i>
                            </div>
                            <div class="service-name"><?php echo xlt('Prescription'); ?></div>
                            <div class="service-price">$25.00</div>
                        </div>

                        <!-- Follow-up -->
                        <div class="service-card" data-service="follow_up" data-price="60.00">
                            <div class="service-icon">
                                <i class="fa fa-calendar-check"></i>
                            </div>
                            <div class="service-name"><?php echo xlt('Follow-up'); ?></div>
                            <div class="service-price">$60.00</div>
                        </div>

                        <!-- Emergency -->
                        <div class="service-card" data-service="emergency" data-price="200.00">
                            <div class="service-icon">
                                <i class="fa fa-ambulance"></i>
                            </div>
                            <div class="service-name"><?php echo xlt('Emergency'); ?></div>
                            <div class="service-price">$200.00</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Side - Cart & Payment -->
            <div class="pos-sidebar">
                <div class="cart-section">
                    <!-- Cart Header -->
                    <div class="cart-header">
                        <h3><i class="fa fa-shopping-cart"></i> <?php echo xlt('Cart'); ?></h3>
                        <span id="cart-count">0</span>
                    </div>

                    <!-- Cart Items -->
                    <div class="cart-items" id="cart-items">
                        <div class="text-center text-muted">
                            <?php echo xlt('No items in cart'); ?>
                        </div>
                    </div>

                    <!-- Cart Total -->
                    <div class="cart-total">
                        <h4><?php echo xlt('Total'); ?></h4>
                        <div class="total-amount" id="cart-total">$0.00</div>
                    </div>

                    <!-- Payment Section -->
                    <div class="payment-section">
                        <h4><i class="fa fa-credit-card"></i> <?php echo xlt('Payment Method'); ?></h4>
                        
                        <div class="payment-methods">
                            <div class="payment-method credit-card" data-method="credit_card">
                                <i class="fa fa-credit-card"></i>
                                <div><?php echo xlt('Credit Card'); ?></div>
                            </div>
                            <div class="payment-method cash" data-method="cash">
                                <i class="fa fa-money-bill"></i>
                                <div><?php echo xlt('Cash'); ?></div>
                            </div>
                            <div class="payment-method check" data-method="check">
                                <i class="fa fa-university"></i>
                                <div><?php echo xlt('Check'); ?></div>
                            </div>
                        </div>

                        <!-- Stripe Form -->
                        <div class="stripe-form" id="stripe-form">
                            <div class="form-group">
                                <label for="card-element"><?php echo xlt('Credit or debit card'); ?></label>
                                <div id="card-element" class="form-control"></div>
                                <div id="card-errors" class="alert alert-danger" style="display: none;"></div>
                            </div>
                        </div>

                        <!-- Cash Form -->
                        <div class="cash-form" id="cash-form" style="display: none;">
                            <div class="form-group">
                                <label for="amount-tendered"><?php echo xlt('Amount Tendered'); ?></label>
                                <input type="number" id="amount-tendered" class="form-control" step="0.01" min="0">
                            </div>
                            <div class="form-group">
                                <label><?php echo xlt('Change Due'); ?></label>
                                <div id="change-due" class="form-control" style="background: #f8f9fa;">$0.00</div>
                            </div>
                        </div>

                        <!-- Check Form -->
                        <div class="check-form" id="check-form" style="display: none;">
                            <div class="form-group">
                                <label for="check-number"><?php echo xlt('Check Number'); ?></label>
                                <input type="text" id="check-number" class="form-control">
                            </div>
                        </div>

                        <!-- Process Payment Button -->
                        <button class="btn btn-success btn-block btn-lg" id="process-payment" onclick="processPayment()">
                            <i class="fa fa-check"></i> <?php echo xlt('Process Payment'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Receipt Modal -->
    <div class="receipt-modal" id="receipt-modal">
        <div class="receipt-content">
            <div class="receipt-header">
                <h2><?php echo xlt('Payment Receipt'); ?></h2>
                <p><?php echo date('F j, Y g:i A'); ?></p>
            </div>
            <div class="receipt-details" id="receipt-details">
                <!-- Receipt content will be populated here -->
            </div>
            <div class="text-center">
                <button class="btn btn-primary" onclick="printReceipt()">
                    <i class="fa fa-print"></i> <?php echo xlt('Print Receipt'); ?>
                </button>
                <button class="btn btn-success" onclick="closeReceipt()">
                    <i class="fa fa-check"></i> <?php echo xlt('Done'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Loading Modal -->
    <div class="loading" id="loading">
        <div class="spinner"></div>
        <p><?php echo xlt('Processing payment...'); ?></p>
    </div>

    <script>
        // Global variables
        let cart = [];
        let selectedPaymentMethod = null;
        let stripe = null;
        let card = null;
        let isProcessing = false;

        // Initialize Stripe
        <?php if ($GLOBALS['payment_gateway'] === 'Stripe'): ?>
        stripe = Stripe('<?php echo $GLOBALS['gateway_api_key']; ?>');
        const elements = stripe.elements();
        card = elements.create('card', {
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
        
        card.addEventListener('change', function(event) {
            const displayError = document.getElementById('card-errors');
            if (event.error) {
                displayError.textContent = event.error.message;
                displayError.style.display = 'block';
            } else {
                displayError.textContent = '';
                displayError.style.display = 'none';
            }
        });
        <?php endif; ?>

        // Service card click handler
        document.querySelectorAll('.service-card').forEach(card => {
            card.addEventListener('click', function() {
                const service = this.dataset.service;
                const price = parseFloat(this.dataset.price);
                addToCart(service, price);
            });
        });

        // Payment method selection
        document.querySelectorAll('.payment-method').forEach(method => {
            method.addEventListener('click', function() {
                // Remove previous selection
                document.querySelectorAll('.payment-method').forEach(m => m.classList.remove('selected'));
                document.querySelectorAll('.payment-form').forEach(f => f.style.display = 'none');
                
                // Select current method
                this.classList.add('selected');
                selectedPaymentMethod = this.dataset.method;
                
                // Show appropriate form
                const formId = this.dataset.method + '-form';
                const form = document.getElementById(formId);
                if (form) {
                    form.style.display = 'block';
                }
            });
        });

        // Add to cart function
        function addToCart(service, price) {
            const existingItem = cart.find(item => item.service === service);
            
            if (existingItem) {
                existingItem.quantity += 1;
            } else {
                cart.push({
                    service: service,
                    price: price,
                    quantity: 1
                });
            }
            
            updateCart();
        }

        // Remove from cart function
        function removeFromCart(index) {
            cart.splice(index, 1);
            updateCart();
        }

        // Update cart display
        function updateCart() {
            const cartItems = document.getElementById('cart-items');
            const cartCount = document.getElementById('cart-count');
            const cartTotal = document.getElementById('cart-total');
            
            let total = 0;
            let html = '';
            
            if (cart.length === 0) {
                html = '<div class="text-center text-muted"><?php echo xlt("No items in cart"); ?></div>';
            } else {
                cart.forEach((item, index) => {
                    const itemTotal = item.price * item.quantity;
                    total += itemTotal;
                    
                    html += `
                        <div class="cart-item">
                            <div>
                                <div class="cart-item-name">${getServiceName(item.service)}</div>
                                <small class="text-muted">Qty: ${item.quantity}</small>
                            </div>
                            <div class="cart-item-price">$${itemTotal.toFixed(2)}</div>
                            <button class="cart-item-remove" onclick="removeFromCart(${index})">
                                <i class="fa fa-times"></i>
                            </button>
                        </div>
                    `;
                });
            }
            
            cartItems.innerHTML = html;
            cartCount.textContent = cart.length;
            cartTotal.textContent = '$' + total.toFixed(2);
        }

        // Get service name
        function getServiceName(service) {
            const names = {
                'consultation': '<?php echo xlt("Consultation"); ?>',
                'physical_exam': '<?php echo xlt("Physical Exam"); ?>',
                'lab_work': '<?php echo xlt("Lab Work"); ?>',
                'xray': '<?php echo xlt("X-Ray"); ?>',
                'vaccination': '<?php echo xlt("Vaccination"); ?>',
                'prescription': '<?php echo xlt("Prescription"); ?>',
                'follow_up': '<?php echo xlt("Follow-up"); ?>',
                'emergency': '<?php echo xlt("Emergency"); ?>'
            };
            return names[service] || service;
        }

        // Clear cart
        function clearCart() {
            cart = [];
            updateCart();
        }

        // Process payment
        async function processPayment() {
            if (isProcessing) return;
            
            if (cart.length === 0) {
                 ?>');
                return;
            }
            
            if (!selectedPaymentMethod) {
                 ?>');
                return;
            }
            
            const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            
            isProcessing = true;
            document.getElementById('loading').classList.add('active');
            
            try {
                let paymentResult = false;
                
                switch (selectedPaymentMethod) {
                    case 'credit_card':
                        paymentResult = await processCreditCardPayment(total);
                        break;
                    case 'cash':
                        paymentResult = await processCashPayment(total);
                        break;
                    case 'check':
                        paymentResult = await processCheckPayment(total);
                        break;
                }
                
                if (paymentResult) {
                    showReceipt();
                    clearCart();
                }
            } catch (error) {
                console.error('Payment error:', error);
                 ?>');
            } finally {
                isProcessing = false;
                document.getElementById('loading').classList.remove('active');
            }
        }

        // Process credit card payment
        async function processCreditCardPayment(total) {
            <?php if ($GLOBALS['payment_gateway'] === 'Stripe'): ?>
            const {token, error} = await stripe.createToken(card);
            
            if (error) {
                 ?>: ' + error.message);
                return false;
            }
            
            // Send to server for processing
            const response = await fetch('pos_payment_processor.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'process_stripe_payment',
                    pid: <?php echo $pid ?: 'null'; ?>,
                    amount: total,
                    token: token.id,
                    items: cart,
                    csrf_token_form: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                return true;
            } else {
                 ?>: ' + result.error);
                return false;
            }
            <?php else: ?>
             ?>');
            return false;
            <?php endif; ?>
        }

        // Process cash payment
        async function processCashPayment(total) {
            const amountTendered = parseFloat(document.getElementById('amount-tendered').value);
            
            if (isNaN(amountTendered) || amountTendered < total) {
                 ?>');
                return false;
            }
            
            const response = await fetch('pos_payment_processor.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'process_cash_payment',
                    pid: <?php echo $pid ?: 'null'; ?>,
                    amount: total,
                    amount_tendered: amountTendered,
                    items: cart,
                    csrf_token_form: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                return true;
            } else {
                 ?>: ' + result.error);
                return false;
            }
        }

        // Process check payment
        async function processCheckPayment(total) {
            const checkNumber = document.getElementById('check-number').value;
            
            if (!checkNumber) {
                 ?>');
                return false;
            }
            
            const response = await fetch('pos_payment_processor.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'process_check_payment',
                    pid: <?php echo $pid ?: 'null'; ?>,
                    amount: total,
                    check_number: checkNumber,
                    items: cart,
                    csrf_token_form: '<?php echo CsrfUtils::collectCsrfToken(); ?>'
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                return true;
            } else {
                 ?>: ' + result.error);
                return false;
            }
        }

        // Show receipt
        function showReceipt() {
            const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            const receiptDetails = document.getElementById('receipt-details');
            
            let html = `
                <div class="receipt-row">
                    <span><?php echo xlt("Patient"); ?>:</span>
                    <span><?php echo $patient_data ? text($patient_data['fname'] . ' ' . $patient_data['lname']) : xlt('Walk-in Patient'); ?></span>
                </div>
                <div class="receipt-row">
                    <span><?php echo xlt("Patient ID"); ?>:</span>
                    <span><?php echo $patient_data ? text($patient_data['pubpid']) : xlt('N/A'); ?></span>
                </div>
                <hr>
            `;
            
            cart.forEach(item => {
                const itemTotal = item.price * item.quantity;
                html += `
                    <div class="receipt-row">
                        <span>${getServiceName(item.service)} (${item.quantity})</span>
                        <span>$${itemTotal.toFixed(2)}</span>
                    </div>
                `;
            });
            
            html += `
                <hr>
                <div class="receipt-row receipt-total">
                    <span><?php echo xlt("Total"); ?>:</span>
                    <span>$${total.toFixed(2)}</span>
                </div>
                <div class="receipt-row">
                    <span><?php echo xlt("Payment Method"); ?>:</span>
                    <span>${getPaymentMethodName(selectedPaymentMethod)}</span>
                </div>
            `;
            
            receiptDetails.innerHTML = html;
            document.getElementById('receipt-modal').classList.add('active');
        }

        // Get payment method name
        function getPaymentMethodName(method) {
            const names = {
                'credit_card': '<?php echo xlt("Credit Card"); ?>',
                'cash': '<?php echo xlt("Cash"); ?>',
                'check': '<?php echo xlt("Check"); ?>'
            };
            return names[method] || method;
        }

        // Print receipt
        function printReceipt() {
            const receiptContent = document.querySelector('.receipt-content').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                    <head>
                        <title><?php echo xlt("Receipt"); ?></title>
                        <style>
                            body { font-family: Arial, sans-serif; margin: 20px; }
                            .receipt-header { text-align: center; margin-bottom: 20px; }
                            .receipt-row { display: flex; justify-content: space-between; margin-bottom: 5px; }
                            .receipt-total { font-weight: bold; font-size: 1.2em; }
                            hr { border: none; border-top: 1px solid #ccc; margin: 10px 0; }
                        </style>
                    </head>
                    <body>
                        ${receiptContent}
                    </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }

        // Close receipt
        function closeReceipt() {
            document.getElementById('receipt-modal').classList.remove('active');
        }

        // Cash amount tendered change handler
        document.getElementById('amount-tendered')?.addEventListener('input', function() {
            const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            const amountTendered = parseFloat(this.value) || 0;
            const changeDue = amountTendered - total;
            
            document.getElementById('change-due').textContent = '$' + Math.max(0, changeDue).toFixed(2);
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            updateCart();
        });
    </script>
</body>
</html> 