<?php

/**
 *  Front Payment Versapay Integration
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Meet Patel <meetpatel1798@gmail.com>
 * @copyright Copyright (c) 2024 Meet Patel
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$ignoreAuth = false;
require_once(__DIR__ . "/../globals.php");

use OpenEMR\Billing\PaymentGateway;
use OpenEMR\Billing\VersapayGateway;
use OpenEMR\Common\Crypto\CryptoGen;

// Handle AJAX requests
if ($_POST['mode'] == 'Versapay') {
    $form_pid = $_POST['form_pid'];
    $encounter = $_POST['encounter'] ?? '';
    $amount = $_POST['payment'];
    $paymentMethod = $_POST['payment_method'] ?? 'credit_card';
    
    try {
        // Initialize Versapay gateway
        $versapay = new VersapayGateway();
        
        if (!$versapay->isConfigured()) {
            echo json_encode(['error' => 'Versapay is not configured']);
            exit;
        }
        
        // Get patient information
        $patient = sqlQuery("SELECT fname, mname, lname, pubpid FROM patient_data WHERE pid = ?", array($form_pid));
        $patientName = trim($patient['fname'] . ' ' . $patient['mname'] . ' ' . $patient['lname']);
        
        // Create order data
        $orderData = [
            'amount' => $amount,
            'description' => "Payment for patient: " . $patientName,
            'patient_id' => $form_pid,
            'encounter_id' => $encounter,
            'order_type' => 'payment'
        ];
        
        // Create payment data
        $paymentData = [
            'method' => $paymentMethod,
            'amount' => $amount,
            'patient_id' => $form_pid
        ];
        
        // Add payment method specific data
        switch ($paymentMethod) {
            case 'credit_card':
            case 'debit_card':
                $paymentData['card_number'] = $_POST['card_number'] ?? '';
                $paymentData['expiry_month'] = $_POST['expiry_month'] ?? '';
                $paymentData['expiry_year'] = $_POST['expiry_year'] ?? '';
                $paymentData['cvv'] = $_POST['cvv'] ?? '';
                break;
            case 'gift_card':
                $paymentData['gift_card_number'] = $_POST['gift_card_number'] ?? '';
                break;
            case 'cash':
                $paymentData['amount_tendered'] = $_POST['amount_tendered'] ?? $amount;
                break;
        }
        
        // Process payment using PaymentGateway
        $gateway = new PaymentGateway('Versapay');
        $result = $gateway->processVersapayPayment($orderData, $paymentData);
        
        if ($result['success']) {
            // Payment successful - post to OpenEMR payments table
            $timestamp = date('Y-m-d H:i:s');
            $paymentId = frontPayment($form_pid, $encounter, 'Versapay', 'Credit Card', $amount, 0, $timestamp);
            
            // Create audit data
            $auditData = [
                'payment_id' => $paymentId,
                'versapay_order_id' => $result['order_id'],
                'versapay_payment_id' => $result['payment_id'],
                'transaction_id' => $result['transaction_id'],
                'amount' => $result['amount'],
                'status' => $result['status'],
                'patient_name' => $patientName,
                'payment_method' => $paymentMethod
            ];
            
            echo json_encode([
                'success' => true,
                'payment_id' => $paymentId,
                'transaction_id' => $result['transaction_id'],
                'audit_data' => $auditData
            ]);
        } else {
            echo json_encode(['error' => $result['error']]);
        }
        
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Handle refund requests
if ($_POST['mode'] == 'VersapayRefund') {
    $paymentId = $_POST['payment_id'];
    $refundAmount = $_POST['refund_amount'];
    $reason = $_POST['reason'] ?? 'Refund requested';
    $form_pid = $_POST['form_pid'];
    
    try {
        $versapay = new VersapayGateway();
        
        // Get payment details from OpenEMR
        $payment = sqlQuery("SELECT * FROM payments WHERE id = ?", array($paymentId));
        
        if (!$payment) {
            echo json_encode(['error' => 'Payment not found']);
            exit;
        }
        
        // Process refund
        $refundData = [
            'amount' => $refundAmount,
            'reason' => $reason,
            'patient_id' => $form_pid
        ];
        
        // Note: You'll need to store Versapay payment IDs in your database
        // For now, we'll use a placeholder
        $versapayPaymentId = $_POST['versapay_payment_id'] ?? '';
        
        if ($versapayPaymentId) {
            $result = $versapay->processRefund($versapayPaymentId, $refundData);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'refund_id' => $result['id'],
                    'amount' => $result['amount']
                ]);
            } else {
                echo json_encode(['error' => 'Refund processing failed']);
            }
        } else {
            echo json_encode(['error' => 'Versapay payment ID not found']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Handle void requests
if ($_POST['mode'] == 'VersapayVoid') {
    $paymentId = $_POST['payment_id'];
    $reason = $_POST['reason'] ?? 'Payment voided';
    $form_pid = $_POST['form_pid'];
    
    try {
        $versapay = new VersapayGateway();
        
        $voidData = [
            'reason' => $reason,
            'patient_id' => $form_pid
        ];
        
        $versapayPaymentId = $_POST['versapay_payment_id'] ?? '';
        
        if ($versapayPaymentId) {
            $result = $versapay->voidPayment($versapayPaymentId, $voidData);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'void_id' => $result['id']
                ]);
            } else {
                echo json_encode(['error' => 'Payment void failed']);
            }
        } else {
            echo json_encode(['error' => 'Versapay payment ID not found']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Handle wallet creation
if ($_POST['mode'] == 'VersapayWallet') {
    $form_pid = $_POST['form_pid'];
    $email = $_POST['email'];
    $name = $_POST['name'];
    
    try {
        $versapay = new VersapayGateway();
        
        $walletData = [
            'email' => $email,
            'name' => $name,
            'patient_id' => $form_pid
        ];
        
        $result = $versapay->createWallet($walletData);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'wallet_id' => $result['id'],
                'wallet_url' => $result['wallet_url'] ?? ''
            ]);
        } else {
            echo json_encode(['error' => 'Wallet creation failed']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Get gateway status
if ($_POST['mode'] == 'VersapayStatus') {
    try {
        $versapay = new VersapayGateway();
        
        $status = [
            'configured' => $versapay->isConfigured(),
            'connected' => $versapay->getStatus(),
            'supported_methods' => $versapay->getSupportedPaymentMethods()
        ];
        
        echo json_encode($status);
        
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
} 