<?php

/**
 * Versapay Webhook Handler
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Meet Patel <meetpatel1798@gmail.com>
 * @copyright Copyright (c) 2024 Meet Patel
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../interface/globals.php");
require_once(__DIR__ . "/../../src/Billing/VersapayGateway.php");

use OpenEMR\Billing\VersapayGateway;
use OpenEMR\Common\Csrf\CsrfUtils;

// Disable CSRF for webhook
$ignoreAuth = true;

// Set content type
header('Content-Type: application/json');

try {
    // Get the raw POST data
    $payload = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_VERSAPAY_SIGNATURE'] ?? '';
    
    // Initialize Versapay gateway
    $versapay = new VersapayGateway();
    
    // Validate webhook signature
    if (!$versapay->validateWebhook($payload, $signature)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
    
    // Parse the webhook data
    $webhookData = json_decode($payload, true);
    
    if (!$webhookData) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        exit;
    }
    
    // Process webhook based on event type
    $eventType = $webhookData['event_type'] ?? '';
    $eventData = $webhookData['data'] ?? [];
    
    switch ($eventType) {
        case 'payment.succeeded':
            handlePaymentSucceeded($eventData);
            break;
            
        case 'payment.failed':
            handlePaymentFailed($eventData);
            break;
            
        case 'payment.refunded':
            handlePaymentRefunded($eventData);
            break;
            
        case 'payment.voided':
            handlePaymentVoided($eventData);
            break;
            
        case 'order.created':
            handleOrderCreated($eventData);
            break;
            
        case 'order.updated':
            handleOrderUpdated($eventData);
            break;
            
        default:
            // Log unknown event type
            error_log("Versapay webhook: Unknown event type: " . $eventType);
            break;
    }
    
    // Return success response
    http_response_code(200);
    echo json_encode(['status' => 'success']);
    
} catch (Exception $e) {
    error_log("Versapay webhook error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

/**
 * Handle successful payment
 */
function handlePaymentSucceeded($data)
{
    $paymentId = $data['id'] ?? '';
    $orderId = $data['order_id'] ?? '';
    $amount = $data['amount'] ?? 0;
    $status = $data['status'] ?? '';
    $metadata = $data['metadata'] ?? [];
    
    $patientId = $metadata['patient_id'] ?? 0;
    $encounterId = $metadata['encounter_id'] ?? 0;
    
    // Log the successful payment
    $versapay = new VersapayGateway();
    $versapay->logAudit('webhook_payment_succeeded', $patientId, 1, $data, null, $paymentId, 'Payment Succeeded', $amount);
    
    // Update payment status in OpenEMR if needed
    if ($patientId && $encounterId) {
        // You can add logic here to update OpenEMR payment records
        // For example, mark a pending payment as completed
    }
}

/**
 * Handle failed payment
 */
function handlePaymentFailed($data)
{
    $paymentId = $data['id'] ?? '';
    $orderId = $data['order_id'] ?? '';
    $amount = $data['amount'] ?? 0;
    $status = $data['status'] ?? '';
    $metadata = $data['metadata'] ?? [];
    
    $patientId = $metadata['patient_id'] ?? 0;
    $encounterId = $metadata['encounter_id'] ?? 0;
    
    // Log the failed payment
    $versapay = new VersapayGateway();
    $versapay->logAudit('webhook_payment_failed', $patientId, 0, $data, null, $paymentId, 'Payment Failed', $amount);
    
    // Update payment status in OpenEMR if needed
    if ($patientId && $encounterId) {
        // You can add logic here to update OpenEMR payment records
        // For example, mark a payment as failed
    }
}

/**
 * Handle payment refund
 */
function handlePaymentRefunded($data)
{
    $refundId = $data['id'] ?? '';
    $paymentId = $data['payment_id'] ?? '';
    $amount = $data['amount'] ?? 0;
    $status = $data['status'] ?? '';
    $metadata = $data['metadata'] ?? [];
    
    $patientId = $metadata['patient_id'] ?? 0;
    
    // Log the refund
    $versapay = new VersapayGateway();
    $versapay->logAudit('webhook_payment_refunded', $patientId, 1, $data, null, $refundId, 'Payment Refunded', $amount);
    
    // Update refund status in OpenEMR if needed
    if ($patientId) {
        // You can add logic here to update OpenEMR refund records
    }
}

/**
 * Handle payment void
 */
function handlePaymentVoided($data)
{
    $paymentId = $data['id'] ?? '';
    $status = $data['status'] ?? '';
    $metadata = $data['metadata'] ?? [];
    
    $patientId = $metadata['patient_id'] ?? 0;
    
    // Log the void
    $versapay = new VersapayGateway();
    $versapay->logAudit('webhook_payment_voided', $patientId, 1, $data, null, $paymentId, 'Payment Voided', '0.00');
    
    // Update void status in OpenEMR if needed
    if ($patientId) {
        // You can add logic here to update OpenEMR payment records
    }
}

/**
 * Handle order creation
 */
function handleOrderCreated($data)
{
    $orderId = $data['id'] ?? '';
    $amount = $data['amount'] ?? 0;
    $status = $data['status'] ?? '';
    $metadata = $data['metadata'] ?? [];
    
    $patientId = $metadata['patient_id'] ?? 0;
    
    // Log the order creation
    $versapay = new VersapayGateway();
    $versapay->logAudit('webhook_order_created', $patientId, 1, $data, null, $orderId, 'Order Created', $amount);
}

/**
 * Handle order update
 */
function handleOrderUpdated($data)
{
    $orderId = $data['id'] ?? '';
    $amount = $data['amount'] ?? 0;
    $status = $data['status'] ?? '';
    $metadata = $data['metadata'] ?? [];
    
    $patientId = $metadata['patient_id'] ?? 0;
    
    // Log the order update
    $versapay = new VersapayGateway();
    $versapay->logAudit('webhook_order_updated', $patientId, 1, $data, null, $orderId, 'Order Updated', $amount);
} 