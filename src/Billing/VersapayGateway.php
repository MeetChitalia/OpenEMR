<?php

/**
 * Versapay Payment Gateway Integration
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Meet Patel <meetpatel1798@gmail.com>
 * @copyright Copyright (c) 2024 Meet Patel
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Billing;

use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Common\Logging\EventAuditLogger;

class VersapayGateway
{
    private $apiKey;
    private $merchantId;
    private $webhookSecret;
    private $apiUrl;
    private $production;
    private $cryptoGen;

    public function __construct()
    {
        $this->cryptoGen = new CryptoGen();
        $this->production = !$GLOBALS['gateway_mode_production'];
        $this->apiKey = $this->cryptoGen->decryptStandard($GLOBALS['versapay_api_key'] ?? '');
        $this->merchantId = $this->cryptoGen->decryptStandard($GLOBALS['versapay_merchant_id'] ?? '');
        $this->webhookSecret = $this->cryptoGen->decryptStandard($GLOBALS['versapay_webhook_secret'] ?? '');
        $this->apiUrl = $GLOBALS['versapay_api_url'] ?? 'https://api.versapay.com';
    }

    /**
     * Create a new order transaction
     */
    public function createOrder($orderData)
    {
        try {
            $endpoint = '/orders';
            $data = [
                'merchant_id' => $this->merchantId,
                'amount' => $orderData['amount'],
                'currency' => 'USD',
                'description' => $orderData['description'] ?? 'OpenEMR Payment',
                'metadata' => [
                    'patient_id' => $orderData['patient_id'] ?? '',
                    'encounter_id' => $orderData['encounter_id'] ?? '',
                    'user_id' => $_SESSION['authUserID'] ?? '',
                    'order_type' => $orderData['order_type'] ?? 'payment'
                ]
            ];

            $response = $this->makeApiRequest('POST', $endpoint, $data);
            
            if ($response['success']) {
                $this->logAudit('create_order', $orderData['patient_id'] ?? 0, 1, $data, null, $response['data']['id'] ?? null, 'Create Order', $orderData['amount']);
                return $response['data'];
            } else {
                $this->logAudit('create_order', $orderData['patient_id'] ?? 0, 0, $data, null, null, 'Create Order Failed', $orderData['amount']);
                throw new \Exception($response['error'] ?? 'Failed to create order');
            }
        } catch (\Exception $e) {
            $this->logAudit('create_order', $orderData['patient_id'] ?? 0, 0, $orderData, null, null, 'Create Order Exception', $orderData['amount']);
            throw $e;
        }
    }

    /**
     * Process a payment
     */
    public function processPayment($orderId, $paymentData)
    {
        try {
            $endpoint = "/orders/{$orderId}/payments";
            $data = [
                'payment_method' => $paymentData['method'],
                'amount' => $paymentData['amount'],
                'currency' => 'USD'
            ];

            // Add payment method specific data
            switch ($paymentData['method']) {
                case 'credit_card':
                    $data['card'] = [
                        'number' => $paymentData['card_number'],
                        'expiry_month' => $paymentData['expiry_month'],
                        'expiry_year' => $paymentData['expiry_year'],
                        'cvv' => $paymentData['cvv']
                    ];
                    break;
                case 'debit_card':
                    $data['card'] = [
                        'number' => $paymentData['card_number'],
                        'expiry_month' => $paymentData['expiry_month'],
                        'expiry_year' => $paymentData['expiry_year'],
                        'cvv' => $paymentData['cvv']
                    ];
                    break;
                case 'gift_card':
                    $data['gift_card'] = [
                        'number' => $paymentData['gift_card_number']
                    ];
                    break;
                case 'cash':
                    $data['cash'] = [
                        'amount_tendered' => $paymentData['amount_tendered'] ?? $paymentData['amount']
                    ];
                    break;
            }

            $response = $this->makeApiRequest('POST', $endpoint, $data);
            
            if ($response['success']) {
                $this->logAudit('process_payment', $paymentData['patient_id'] ?? 0, 1, $data, null, $response['data']['id'] ?? null, 'Process Payment', $paymentData['amount']);
                return $response['data'];
            } else {
                $this->logAudit('process_payment', $paymentData['patient_id'] ?? 0, 0, $data, null, null, 'Process Payment Failed', $paymentData['amount']);
                throw new \Exception($response['error'] ?? 'Payment processing failed');
            }
        } catch (\Exception $e) {
            $this->logAudit('process_payment', $paymentData['patient_id'] ?? 0, 0, $paymentData, null, null, 'Process Payment Exception', $paymentData['amount']);
            throw $e;
        }
    }

    /**
     * Process a refund
     */
    public function processRefund($paymentId, $refundData)
    {
        try {
            $endpoint = "/payments/{$paymentId}/refunds";
            $data = [
                'amount' => $refundData['amount'],
                'reason' => $refundData['reason'] ?? 'Refund requested',
                'currency' => 'USD'
            ];

            $response = $this->makeApiRequest('POST', $endpoint, $data);
            
            if ($response['success']) {
                $this->logAudit('process_refund', $refundData['patient_id'] ?? 0, 1, $data, null, $response['data']['id'] ?? null, 'Process Refund', $refundData['amount']);
                return $response['data'];
            } else {
                $this->logAudit('process_refund', $refundData['patient_id'] ?? 0, 0, $data, null, null, 'Process Refund Failed', $refundData['amount']);
                throw new \Exception($response['error'] ?? 'Refund processing failed');
            }
        } catch (\Exception $e) {
            $this->logAudit('process_refund', $refundData['patient_id'] ?? 0, 0, $refundData, null, null, 'Process Refund Exception', $refundData['amount']);
            throw $e;
        }
    }

    /**
     * Get order details
     */
    public function getOrder($orderId)
    {
        try {
            $endpoint = "/orders/{$orderId}";
            $response = $this->makeApiRequest('GET', $endpoint);
            
            if ($response['success']) {
                return $response['data'];
            } else {
                throw new \Exception($response['error'] ?? 'Failed to get order details');
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Get payment details
     */
    public function getPayment($paymentId)
    {
        try {
            $endpoint = "/payments/{$paymentId}";
            $response = $this->makeApiRequest('GET', $endpoint);
            
            if ($response['success']) {
                return $response['data'];
            } else {
                throw new \Exception($response['error'] ?? 'Failed to get payment details');
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Void a payment
     */
    public function voidPayment($paymentId, $voidData)
    {
        try {
            $endpoint = "/payments/{$paymentId}/void";
            $data = [
                'reason' => $voidData['reason'] ?? 'Payment voided'
            ];

            $response = $this->makeApiRequest('POST', $endpoint, $data);
            
            if ($response['success']) {
                $this->logAudit('void_payment', $voidData['patient_id'] ?? 0, 1, $data, null, $response['data']['id'] ?? null, 'Void Payment', '0.00');
                return $response['data'];
            } else {
                $this->logAudit('void_payment', $voidData['patient_id'] ?? 0, 0, $data, null, null, 'Void Payment Failed', '0.00');
                throw new \Exception($response['error'] ?? 'Payment void failed');
            }
        } catch (\Exception $e) {
            $this->logAudit('void_payment', $voidData['patient_id'] ?? 0, 0, $voidData, null, null, 'Void Payment Exception', '0.00');
            throw $e;
        }
    }

    /**
     * Create a wallet for recurring payments
     */
    public function createWallet($walletData)
    {
        try {
            $endpoint = '/wallets';
            $data = [
                'merchant_id' => $this->merchantId,
                'customer_email' => $walletData['email'],
                'customer_name' => $walletData['name'],
                'metadata' => [
                    'patient_id' => $walletData['patient_id'] ?? '',
                    'user_id' => $_SESSION['authUserID'] ?? ''
                ]
            ];

            $response = $this->makeApiRequest('POST', $endpoint, $data);
            
            if ($response['success']) {
                $this->logAudit('create_wallet', $walletData['patient_id'] ?? 0, 1, $data, null, $response['data']['id'] ?? null, 'Create Wallet', '0.00');
                return $response['data'];
            } else {
                $this->logAudit('create_wallet', $walletData['patient_id'] ?? 0, 0, $data, null, null, 'Create Wallet Failed', '0.00');
                throw new \Exception($response['error'] ?? 'Failed to create wallet');
            }
        } catch (\Exception $e) {
            $this->logAudit('create_wallet', $walletData['patient_id'] ?? 0, 0, $walletData, null, null, 'Create Wallet Exception', '0.00');
            throw $e;
        }
    }

    /**
     * Make API request to Versapay
     */
    private function makeApiRequest($method, $endpoint, $data = null)
    {
        $url = $this->apiUrl . $endpoint;
        
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => 'cURL Error: ' . $error];
        }

        $responseData = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'data' => $responseData];
        } else {
            $errorMessage = isset($responseData['error']) ? $responseData['error'] : 'HTTP Error: ' . $httpCode;
            return ['success' => false, 'error' => $errorMessage, 'http_code' => $httpCode];
        }
    }

    /**
     * Validate webhook signature
     */
    public function validateWebhook($payload, $signature)
    {
        $expectedSignature = hash_hmac('sha256', $payload, $this->webhookSecret);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Log audit entry
     */
    private function logAudit($service, $pid, $success, $auditData, $ticket = null, $transactionId = null, $actionName = null, $amount = null)
    {
        try {
            \OpenEMR\PaymentProcessing\PaymentProcessing::saveAudit(
                $service,
                $pid,
                $success,
                $auditData,
                $ticket,
                $transactionId,
                $actionName,
                $amount
            );
        } catch (\Exception $e) {
            // Log to error log if audit fails
            error_log("Versapay audit logging failed: " . $e->getMessage());
        }
    }

    /**
     * Get supported payment methods
     */
    public function getSupportedPaymentMethods()
    {
        return [
            'credit_card' => 'Credit Card',
            'debit_card' => 'Debit Card',
            'gift_card' => 'Gift Card',
            'cash' => 'Cash',
            'check' => 'Check',
            'wallet' => 'Digital Wallet'
        ];
    }

    /**
     * Check if gateway is configured
     */
    public function isConfigured()
    {
        return !empty($this->apiKey) && !empty($this->merchantId);
    }

    /**
     * Get gateway status
     */
    public function getStatus()
    {
        try {
            $endpoint = '/status';
            $response = $this->makeApiRequest('GET', $endpoint);
            return $response['success'];
        } catch (\Exception $e) {
            return false;
        }
    }
} 