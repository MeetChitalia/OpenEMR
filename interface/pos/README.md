# OpenEMR POS (Point of Sale) System

## Overview

The OpenEMR POS System is a modern, integrated point-of-sale solution designed specifically for healthcare practices. It provides a streamlined interface for processing payments, managing patient transactions, and integrating with existing OpenEMR billing systems.

## Features

### ✅ **Modern User Interface**
- **Responsive Design**: Works on desktop, tablet, and mobile devices
- **Clean Layout**: Intuitive two-column design with services on the left and cart/payment on the right
- **Professional Styling**: Modern gradient backgrounds and card-based layout
- **Real-time Updates**: Live cart updates and payment processing feedback

### ✅ **Patient Management**
- **Patient Information Display**: Shows patient name, ID, DOB, and current balance
- **Balance Integration**: Displays current patient balance from OpenEMR billing system
- **Walk-in Support**: Can process payments for patients without existing records

### ✅ **Service Catalog**
- **Predefined Services**: Common healthcare services with standard pricing
- **Visual Service Cards**: Easy-to-click service cards with icons and pricing
- **Quantity Management**: Add multiple instances of the same service
- **Customizable**: Services can be easily modified or extended

### ✅ **Shopping Cart**
- **Real-time Cart**: Live updates as items are added/removed
- **Item Management**: Remove individual items or clear entire cart
- **Quantity Display**: Shows quantity for each service
- **Total Calculation**: Automatic total calculation

### ✅ **Payment Processing**
- **Multiple Payment Methods**:
  - **Credit/Debit Cards**: Integrated Stripe processing
  - **Cash**: With change calculation
  - **Check**: With check number tracking
- **Secure Processing**: All payments processed through OpenEMR's secure payment gateway
- **Transaction Recording**: Complete audit trail in database

### ✅ **Stripe Integration**
- **Real-time Processing**: Instant payment processing
- **Secure Tokenization**: Card data never stored on server
- **Error Handling**: Comprehensive error messages and validation
- **Transaction Metadata**: Rich transaction data for reporting

### ✅ **Receipt Generation**
- **Professional Receipts**: Clean, printable receipt format
- **Complete Information**: Patient details, items, totals, and payment method
- **Print Support**: Direct printing from browser
- **Digital Copy**: Receipt stored in database for future reference

## Installation

### 1. Files Created
- `interface/pos/pos_system.php` - Main POS interface
- `interface/pos/pos_payment_processor.php` - Payment processing backend
- `interface/pos/README.md` - This documentation

### 2. Database Changes
The system automatically creates a `pos_transactions` table to store transaction history:

```sql
CREATE TABLE pos_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pid INT,
    amount DECIMAL(10,2),
    payment_method VARCHAR(50),
    payment_id VARCHAR(100),
    transaction_id VARCHAR(100),
    items JSON,
    metadata JSON,
    created_by VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 3. Configuration Requirements
- **Stripe Gateway**: Must be configured in OpenEMR for credit card processing
- **Payment Gateway**: Set to 'Stripe' in OpenEMR configuration
- **API Keys**: Stripe API keys must be properly configured

## Usage

### Accessing the POS System
1. **From Patient Finder**: Click on any patient in the patient finder
2. **Direct URL**: Navigate to `interface/pos/pos_system.php?pid=<patient_id>`
3. **New Window**: POS opens in a new window for better workflow

### Processing a Payment
1. **Select Patient**: Patient information is automatically loaded
2. **Add Services**: Click on service cards to add to cart
3. **Review Cart**: Check items and total in the right sidebar
4. **Choose Payment Method**: Select credit card, cash, or check
5. **Enter Payment Details**: Fill in required payment information
6. **Process Payment**: Click "Process Payment" button
7. **Print Receipt**: Print or save receipt for patient

### Payment Methods

#### Credit Card
- Uses Stripe for secure processing
- Card information is tokenized
- Real-time validation and error handling
- Transaction recorded in both payments and ar_activity tables

#### Cash
- Enter amount tendered
- Automatic change calculation
- Transaction recorded with cash payment type
- Metadata includes amount tendered and change due

#### Check
- Enter check number
- Transaction recorded with check payment type
- Metadata includes check number for tracking

## Integration Points

### OpenEMR Billing System
- **Patient Balance**: Displays current balance from billing system
- **Payment Recording**: Payments recorded in standard OpenEMR tables
- **AR Activity**: Transactions properly recorded in ar_activity table
- **Audit Trail**: Complete audit trail maintained

### Stripe Integration
- **Secure Processing**: Uses OpenEMR's PaymentGateway class
- **Token-based**: Card data never stored on server
- **Metadata**: Rich transaction metadata for reporting
- **Error Handling**: Comprehensive error handling and user feedback

### Database Integration
- **Patient Data**: Integrates with patient_data table
- **Billing Tables**: Uses payments and ar_activity tables
- **POS Transactions**: Custom table for POS-specific data
- **User Tracking**: All transactions tracked by user

## Security Features

### ✅ **Authentication**
- Requires valid OpenEMR user session
- CSRF token validation on all requests
- Session-based security

### ✅ **Data Protection**
- No sensitive data stored on server
- Card data tokenized through Stripe
- Secure API communication

### ✅ **Audit Trail**
- Complete transaction logging
- User tracking for all operations
- Timestamp recording for all transactions

## Customization

### Adding New Services
To add new services, modify the services grid in `pos_system.php`:

```html
<div class="service-card" data-service="new_service" data-price="100.00">
    <div class="service-icon">
        <i class="fa fa-icon-name"></i>
    </div>
    <div class="service-name">New Service</div>
    <div class="service-price">$100.00</div>
</div>
```

### Modifying Service Names
Update the `getServiceName()` function in the JavaScript:

```javascript
function getServiceName(service) {
    const names = {
        'new_service': 'New Service Name',
        // ... other services
    };
    return names[service] || service;
}
```

### Styling Customization
The system uses CSS custom properties for easy theming:

```css
:root {
    --primary-color: #2c3e50;
    --secondary-color: #3498db;
    --success-color: #27ae60;
    /* ... other colors */
}
```

## Troubleshooting

### Common Issues

#### Payment Processing Fails
- Check Stripe configuration in OpenEMR
- Verify API keys are correct
- Check network connectivity
- Review error logs for specific error messages

#### Patient Data Not Loading
- Verify patient ID is valid
- Check database connectivity
- Ensure user has proper permissions

#### Receipt Not Printing
- Check browser print settings
- Ensure popup blockers are disabled
- Try different browser if issues persist

### Error Logging
The system logs errors to the OpenEMR error log. Check the log file for detailed error information.

## Support

For support and questions:
1. Check this documentation
2. Review OpenEMR community forums
3. Check error logs for specific issues
4. Verify configuration settings

## Future Enhancements

### Planned Features
- **Inventory Integration**: Link services to inventory items
- **Discount System**: Support for discounts and coupons
- **Multi-location Support**: Support for multiple practice locations
- **Advanced Reporting**: Detailed POS transaction reports
- **Mobile App**: Native mobile application
- **Offline Mode**: Support for offline transactions

### Integration Opportunities
- **EHR Integration**: Deeper integration with patient records
- **Insurance Processing**: Integration with insurance claims
- **Inventory Management**: Real-time inventory updates
- **Accounting Integration**: Export to accounting systems 

## Sales Tax Tooltip (Tax Source Transparency)

### Business Rule
- Sales tax is calculated only for taxable products (currently `Meta Trim` and `BariCare` via name matching).
- Non-taxable items (for example, consultation services) do not contribute to sales tax.

### UI Behavior
- In Order Summary, the sales tax amount cell now has a hover tooltip.
- Hovering the tax amount shows which cart product(s) generated that tax.
- Example tooltip text: `Tax from: Meta Trim` or `Tax from: Meta Trim, BariCare`.

### Implementation Details
- Taxability check: `isTaxableItem(item)` in [pos_modal.php](c:/xampp/htdocs/openemr/interface/pos/pos_modal.php).
- Order summary rendering: `renderOrderSummary()` in [pos_modal.php](c:/xampp/htdocs/openemr/interface/pos/pos_modal.php).
- During render:
  - Taxable product display names are collected from cart items that have taxable totals.
  - Duplicate names are removed before display.
  - The tax amount cell `title` attribute is set to `Tax from: <comma-separated product names>`.

### Maintenance Notes
- If taxable product names change, update `isTaxableItem(item)` matching logic.
- If tax rules move from name-based matching to category/flag-based matching, update both:
  - taxable subtotal logic
  - tooltip source-product collection logic
