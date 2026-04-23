# Versapay Integration for OpenEMR

## Overview

This integration adds Versapay payment processing capabilities to OpenEMR, leveraging the existing payment infrastructure while adding modern POS functionality. The integration is designed to be seamless, secure, and compliant with healthcare payment standards.

## Features

### ✅ Core Payment Processing
- **Credit Card Processing**: Secure credit card transactions
- **Debit Card Support**: Debit card payment processing
- **Gift Card Support**: Gift card redemption
- **Cash Transactions**: Cash payment handling
- **Digital Wallets**: Support for digital wallet payments
- **Check Processing**: Check payment support

### ✅ POS System Features
- **Patient Search**: Quick patient lookup and selection
- **Service Catalog**: Predefined healthcare services with pricing
- **Shopping Cart**: Add/remove services with real-time totals
- **Payment Methods**: Multiple payment method selection
- **Receipt Generation**: Professional receipt printing
- **Transaction History**: Complete audit trail

### ✅ Security & Compliance
- **PCI Compliance**: Secure payment processing
- **Audit Logging**: Comprehensive transaction logging
- **Webhook Security**: Signed webhook validation
- **Encrypted Storage**: Secure credential storage
- **Session Management**: Secure user sessions

## Installation

### 1. Files Added/Modified

#### New Files:
- `src/Billing/VersapayGateway.php` - Core Versapay API integration
- `interface/versapay/webhook_handler.php` - Webhook processing
- `interface/patient_file/front_payment_versapay.php` - Payment processing interface
- `interface/versapay/pos_interface.php` - Modern POS interface
- `test_versapay_integration.php` - Integration test script

#### Modified Files:
- `library/globals.inc.php` - Added Versapay configuration options
- `src/Billing/PaymentGateway.php` - Extended to support Versapay

### 2. Database Tables Used

The integration uses existing OpenEMR database tables:
- `payments` - Payment records
- `payment_processing_audit` - Audit logging
- `payment_gateway_details` - Gateway configuration

## Configuration

### 1. Admin Configuration

Navigate to **Administration > Globals > Payment Gateway** and configure:

```
Payment Gateway: Versapay
Versapay API Key: [Your API Key]
Versapay Merchant ID: [Your Merchant ID]
Versapay Webhook Secret: [Your Webhook Secret]
Versapay API URL: https://api.versapay.com
```

### 2. Webhook Setup

In your Versapay dashboard, set the webhook URL to:
```
https://your-openemr-domain.com/interface/versapay/webhook_handler.php
```

## Usage

### 1. POS Interface

Access the POS system at:
```
https://your-openemr-domain.com/interface/versapay/pos_interface.php
```

**Workflow:**
1. Search and select a patient
2. Add services to cart
3. Select payment method
4. Process payment
5. Generate receipt

### 2. Payment Processing

The system supports multiple payment scenarios:

#### Direct Payment Processing
```php
$gateway = new PaymentGateway('Versapay');
$result = $gateway->processVersapayPayment($orderData, $paymentData);
```

#### Webhook Processing
Webhooks automatically update payment status and create audit logs.

### 3. API Methods

#### VersapayGateway Class Methods:

```php
// Create order
$order = $versapay->createOrder($orderData);

// Process payment
$payment = $versapay->processPayment($orderId, $paymentData);

// Process refund
$refund = $versapay->processRefund($paymentId, $refundData);

// Void payment
$void = $versapay->voidPayment($paymentId, $voidData);

// Create wallet
$wallet = $versapay->createWallet($walletData);

// Get order details
$order = $versapay->getOrder($orderId);

// Get payment details
$payment = $versapay->getPayment($paymentId);
```

## Testing

### 1. Run Integration Test

Access the test script to verify installation:
```
https://your-openemr-domain.com/test_versapay_integration.php
```

### 2. Test Scenarios

1. **Configuration Test**: Verify credentials are set
2. **API Connectivity**: Test connection to Versapay
3. **Payment Processing**: Test a small transaction
4. **Webhook Processing**: Test webhook notifications
5. **Audit Logging**: Verify audit records are created

## Security Features

### 1. Credential Security
- API keys are encrypted using OpenEMR's CryptoGen
- Credentials are stored securely in the database
- No sensitive data is logged

### 2. Webhook Security
- HMAC-SHA256 signature validation
- IP whitelisting support (configured in webhook handler)
- Request validation and sanitization

### 3. Audit Logging
- All transactions are logged with full details
- Failed transactions are tracked
- User actions are recorded
- Compliance-ready audit trail

## Error Handling

### 1. Common Errors

| Error | Cause | Solution |
|-------|-------|----------|
| "Versapay is not configured" | Missing API credentials | Configure in Admin > Globals |
| "Invalid signature" | Webhook signature mismatch | Check webhook secret |
| "Payment processing failed" | API communication error | Check API credentials and connectivity |
| "Order not found" | Invalid order ID | Verify order exists in Versapay |

### 2. Debugging

Enable debug logging by adding to globals:
```php
$GLOBALS['versapay_debug'] = true;
```

## Integration with Existing OpenEMR Features

### 1. Patient Management
- Integrates with existing patient database
- Uses patient demographics for payment processing
- Links payments to patient encounters

### 2. Billing System
- Posts payments to existing `payments` table
- Integrates with billing workflow
- Supports encounter-based billing

### 3. User Management
- Respects OpenEMR user permissions
- Logs user actions in audit trail
- Integrates with session management

## Customization

### 1. Adding New Payment Methods

Extend the `getSupportedPaymentMethods()` method in `VersapayGateway.php`:

```php
public function getSupportedPaymentMethods()
{
    return [
        'credit_card' => 'Credit Card',
        'debit_card' => 'Debit Card',
        'gift_card' => 'Gift Card',
        'cash' => 'Cash',
        'check' => 'Check',
        'wallet' => 'Digital Wallet',
        'your_method' => 'Your Payment Method' // Add new method
    ];
}
```

### 2. Custom Service Pricing

Modify the service grid in `pos_interface.php`:

```javascript
<div class="service-item" onclick="addService('your_service', 150.00, 'Your Service')">
    <i class="fas fa-your-icon fa-2x"></i>
    <h5>Your Service</h5>
    <p>$150.00</p>
</div>
```

### 3. Custom Receipt Format

Modify the receipt generation in the payment processing code.

## Troubleshooting

### 1. Payment Gateway Not Working

1. Check configuration in Admin > Globals
2. Verify API credentials are correct
3. Test API connectivity
4. Check error logs

### 2. Webhooks Not Receiving

1. Verify webhook URL is correct
2. Check webhook secret matches
3. Ensure server can receive POST requests
4. Check firewall settings

### 3. Audit Logs Missing

1. Verify `payment_processing_audit` table exists
2. Check user permissions
3. Review error logs for audit failures

## Support

### 1. Documentation
- This integration guide
- OpenEMR documentation
- Versapay API documentation

### 2. Testing
- Use the provided test script
- Test in sandbox environment first
- Verify all payment methods work

### 3. Maintenance
- Regular security updates
- Monitor audit logs
- Backup payment data
- Update API credentials as needed

## Compliance

This integration is designed to meet healthcare payment compliance requirements:

- **HIPAA**: Patient data protection
- **PCI DSS**: Payment card security
- **SOX**: Financial reporting
- **State Regulations**: Local healthcare laws

## Future Enhancements

Potential future improvements:

1. **Mobile POS**: Mobile-optimized interface
2. **Inventory Integration**: Link payments to inventory
3. **Multi-location Support**: Support multiple facilities
4. **Advanced Reporting**: Enhanced payment analytics
5. **Recurring Payments**: Subscription payment support
6. **Insurance Integration**: Direct insurance billing

## Conclusion

The Versapay integration provides a robust, secure, and user-friendly payment processing solution for OpenEMR. It leverages existing infrastructure while adding modern POS capabilities, making it an ideal solution for healthcare practices looking to modernize their payment processing.

For questions or support, refer to the OpenEMR community forums or contact the development team. 