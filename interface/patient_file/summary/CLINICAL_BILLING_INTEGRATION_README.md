# Clinical Billing Integration System

## Overview

This system provides **two options** for adding clinical items in the Clinical Information section of the Patient Dashboard:

1. **Add [Item]** - Standard clinical item addition (no billing)
2. **Add [Item] & Charge** - Clinical item addition with integrated billing

The system supports three specific clinical item types:

1. **Prescriptions** - Drug prescriptions with dosage information
2. **Medications** - Patient medications with frequency and notes
3. **Administered Items** - Immunizations, procedures, injections, and treatments

## Features

### ✅ **Integrated Billing**
- Automatic billing charge creation when clinical items are added
- Configurable default fees for each item type
- Custom billing descriptions
- Encounter creation if none exists

### ✅ **User-Friendly Interface**
- Modal forms with billing information sections
- Auto-generated billing descriptions
- Real-time form validation
- Success/error notifications

### ✅ **Dual-Option Interface**
- Two buttons for each clinical item type
- Clear distinction between standard and billing options
- Flexible workflow for different clinical scenarios
- Automatic page refresh after billing updates

## Files Created/Modified

### New Files:
1. **`clinical_billing_integration.php`** - Core billing integration functions
2. **`add_prescription_with_billing.php`** - Prescription form with billing
3. **`add_medication_with_billing.php`** - Medication form with billing
4. **`add_administered_with_billing.php`** - Administered item form with billing

### Modified Files:
1. **`demographics.php`** - Updated Clinical Information section buttons and styling

## How It Works

### 1. **Patient Dashboard Integration**
- Clinical Information section now shows two buttons for each item type
- "Add [Item]" button for standard clinical addition
- "Add [Item] & Charge" button for integrated billing
- Clear visual distinction between the two options

### 2. **Form Flow**

**Standard Addition (No Billing):**
1. User clicks "Add [Item]" button
2. Standard OpenEMR form opens (existing functionality)
3. User adds clinical item without billing integration

**Billing Integration:**
1. User clicks "Add [Item] & Charge" button
2. Modal form opens with clinical and billing sections
3. User fills in clinical information
4. Billing section shows default fee and auto-generated description
5. User can modify fee and description as needed
6. Form submits via AJAX to `clinical_billing_integration.php`
7. Billing charge is created in the `billing` table
8. Success message shown and window closes
9. Parent window refreshes to show updated balance

### 3. **Billing Integration**
- Creates encounter if none exists
- Adds billing records with appropriate code types:
  - `PRESCRIPTION` for prescriptions
  - `MEDICATION` for medications  
  - `ADMINISTERED` for administered items
- Uses BillingUtilities::addBilling() for proper integration
- Supports all standard billing fields (fee, description, units, etc.)

## Default Fees

The system includes configurable default fees:

```php
$default_fees = array(
    'prescription' => 25.00,
    'medication' => 15.00,
    'administered' => 50.00
);
```

These can be modified in `clinical_billing_integration.php`.

## Code Types Used

- **Prescriptions**: `PRESCRIPTION` (e.g., RX_ASPIRIN)
- **Medications**: `MEDICATION` (e.g., MED_IBUPROFEN)
- **Administered**: `ADMINISTERED` (e.g., ADMIN_FLU_SHOT)

## Security Features

- CSRF token protection on all forms
- Input validation and sanitization
- Proper error handling and logging
- User authorization checks

## Benefits

### For Healthcare Providers:
- **Streamlined Workflow**: Add clinical items and billing in one step
- **Reduced Errors**: Automatic billing integration prevents missed charges
- **Better Documentation**: Integrated clinical and billing records
- **Improved Efficiency**: No need to switch between multiple interfaces

### For Patients:
- **Transparent Billing**: Clear indication when charges will be added
- **Immediate Updates**: Real-time balance updates after item addition
- **Better Communication**: Detailed billing descriptions

### For Administrators:
- **Consistent Billing**: Standardized fee structure
- **Audit Trail**: Complete record of clinical and billing activities
- **Easy Management**: Centralized billing integration

## Usage Instructions

### Adding Clinical Items (Two Options):

**Option 1: Standard Addition (No Billing)**
- Click "Add [Item]" button (blue button)
- Use standard OpenEMR forms
- No billing charges are created
- Suitable for clinical documentation only

**Option 2: Integrated Billing**
- Click "Add [Item] & Charge" button (green button)
- Use enhanced forms with billing integration
- Automatic billing charge creation
- Suitable for billable services

### Detailed Steps for Billing Integration:

**Adding a Prescription with Billing:**
1. Navigate to Patient Dashboard
2. In Clinical Information section, click "Add Prescription & Charge"
3. Fill in drug name, dosage, quantity, refills, and notes
4. Review/modify billing fee and description
5. Click "Add Prescription & Charge"
6. Charge is automatically added to patient's account

**Adding a Medication with Billing:**
1. Click "Add Medication & Charge"
2. Enter medication name, dosage, frequency, start date, and comments
3. Review billing information
4. Submit to add medication and billing charge

**Adding an Administered Item with Billing:**
1. Click "Add Administered & Charge"
2. Select item type (immunization, procedure, injection, etc.)
3. Enter item details, administration date, route, and site
4. Review billing information
5. Submit to add administered item and billing charge

## Technical Details

### Database Tables Used:
- `billing` - Main billing records
- `form_encounter` - Encounter information
- `patient_data` - Patient demographics
- `prescriptions` - Prescription records
- `lists` - Medication records
- `immunizations` - Immunization records

### Key Functions:
- `addPrescriptionCharge()` - Adds prescription billing
- `addMedicationCharge()` - Adds medication billing
- `addAdministeredCharge()` - Adds administered item billing
- `createEncounter()` - Creates new encounter if needed
- `getDefaultFee()` - Returns default fee for item type

### AJAX Endpoints:
- `clinical_billing_integration.php` - Handles form submissions
- Returns JSON response with success/error status

## Future Enhancements

Potential improvements for future versions:

1. **Fee Schedule Integration**: Connect to existing fee schedules
2. **Insurance Integration**: Automatic insurance billing
3. **Batch Processing**: Add multiple items at once
4. **Advanced Pricing**: Dynamic pricing based on patient type
5. **Reporting**: Enhanced billing reports and analytics
6. **Mobile Support**: Mobile-friendly forms
7. **Audit Logging**: Enhanced audit trail
8. **Template System**: Predefined clinical item templates

## Support

For questions or issues with the Clinical Billing Integration system:

1. Check the error logs for detailed error messages
2. Verify database permissions for billing table access
3. Ensure proper user authorization for billing functions
4. Test with a sample patient to verify functionality

## Compatibility

This system is compatible with:
- OpenEMR 6.0+
- PHP 7.4+
- MySQL 5.7+
- Modern web browsers (Chrome, Firefox, Safari, Edge)

---

**Created by**: Meet Patel  
**Date**: 2024  
**Version**: 1.0 