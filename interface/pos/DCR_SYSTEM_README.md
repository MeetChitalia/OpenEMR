# DCR (Daily Collection Report) System Documentation

## Overview

The DCR System is an automated reporting solution that generates comprehensive Daily Collection Reports directly from your OpenEMR system data. This eliminates the need for manual Excel spreadsheet creation and provides real-time, accurate financial and treatment data.

## What It Replaces

**Before**: Manual Excel spreadsheets with data entry errors, time-consuming updates, and static information.

**After**: Automated reports generated in real-time from your OpenEMR system with:
- ✅ **Real-time data** - Always current
- ✅ **No manual entry** - Eliminates human error
- ✅ **Comprehensive tracking** - All treatments and revenue
- ✅ **Professional formatting** - Print-ready reports
- ✅ **Export capabilities** - CSV, PDF, and print options

## System Components

### 1. **Basic DCR Report** (`pos_dcr_reports.php`)
- Daily revenue summary
- Patient treatment details
- Basic patient summary
- Export functionality

### 2. **Enhanced DCR Report** (`pos_dcr_enhanced.php`)
- **Treatment categorization**: LIPO, SEMA, TRZ, Office, Supplements
- **Patient status tracking**: New vs Follow-up patients
- **Shot card usage**: Automatic counting and revenue calculation
- **Monthly breakdowns**: Business day filtering (Wed, Thu, Sat)
- **Dosage tracking**: For SEMA and TRZ treatments
- **Real-time updates**: Auto-refresh every 5 minutes

### 3. **Navigation System** (`pos_dcr_navigation.php`)
- Easy access to all report types
- Quick links for today's report and monthly breakdown
- User-friendly interface

## How to Access

### From POS System
1. Open the POS system for any patient
2. Click the **"DCR Reports"** button in the header
3. Choose your report type

### Direct Access
- **Basic Report**: `interface/pos/pos_dcr_reports.php`
- **Enhanced Report**: `interface/pos/pos_dcr_enhanced.php`
- **Navigation**: `interface/pos/pos_dcr_navigation.php`

## Report Types

### Daily Report
**Purpose**: Track daily revenue and patient activity

**Shows**:
- Total revenue for the day
- Revenue by treatment type (Office, LIPO, SEMA, TRZ, Supplements)
- Shot card usage and revenue
- Patient treatment details with status
- Patient summary with spending totals

**Data Sources**:
- `pos_receipts` table (POS transactions)
- `drug_sales` table (pharmaceutical sales)
- `drugs` table (treatment information)
- `form_encounter` table (patient status)

### Monthly Breakdown
**Purpose**: Track monthly trends and business day performance

**Shows**:
- Daily breakdown for business days (Wed, Thu, Sat)
- Card usage by treatment type
- Revenue totals by treatment type
- Monthly summaries

**Business Logic**:
- Only includes business days (Wednesday, Thursday, Saturday)
- Aggregates daily data into monthly totals
- Filters by clinic/location

## Treatment Categorization

The system automatically categorizes treatments based on drug names and descriptions:

### **LIPO Treatments**
- **Keywords**: "lipo", "b12"
- **Purpose**: Fat burning injections
- **Tracking**: Card usage, revenue, patient count

### **SEMA Treatments**
- **Keywords**: "sema", "semaglutide"
- **Purpose**: Weight loss medication injections
- **Tracking**: Dosage, card usage, revenue, patient count

### **TRZ Treatments**
- **Keywords**: "trz", "tirzepatide"
- **Purpose**: Weight loss medication injections
- **Tracking**: Dosage, card usage, revenue, patient count

### **Office Visits**
- **Keywords**: "office", "consultation"
- **Purpose**: Patient consultations and follow-ups
- **Tracking**: Revenue, patient count

### **Supplements**
- **Keywords**: "supplement", "vitamin"
- **Purpose**: Nutritional supplements
- **Tracking**: Revenue, patient count

## Patient Status Tracking

### **New Patients (N)**
- First encounter within 30 days
- Green badge in reports
- Important for growth tracking

### **Follow-up Patients (F)**
- Returning patients after 30 days
- Blue badge in reports
- Indicates patient retention

## Shot Card System

### **Automatic Tracking**
- Counts cards used per treatment type
- Calculates revenue ($4.00 per card)
- Tracks daily and monthly usage

### **Card Types**
- **LIPO Cards**: Fat burning treatment cards
- **SEMA Cards**: Semaglutide treatment cards
- **TRZ Cards**: Tirzepatide treatment cards

## Data Sources

### **Primary Tables**
1. **`pos_receipts`** - POS transaction records
2. **`drug_sales`** - Pharmaceutical sales
3. **`drugs`** - Drug information and descriptions
4. **`form_encounter`** - Patient encounter history

### **Data Flow**
```
POS Transaction → pos_receipts → DCR Report
Drug Sale → drug_sales → DCR Report
Patient Visit → form_encounter → Status Determination
```

## Configuration

### **Card Value**
- Default: $4.00 per card
- Configurable in `pos_dcr_enhanced.php`:
```php
$card_value = 4.00; // Change this value as needed
```

### **Business Days**
- Default: Wednesday, Thursday, Saturday
- Configurable in `getMonthlyBreakdownData()` function:
```php
if (in_array($day_name, ['Wed', 'Thu', 'Sat'])) {
    // Process business day
}
```

### **Treatment Keywords**
- Customizable in `categorizeTreatment()` function
- Add new treatment types as needed

## Export Options

### **CSV Export**
- Comma-separated values
- Excel-compatible format
- All report data included

### **PDF Export**
- Professional formatting
- Print-ready layout
- Complete report preservation

### **Print**
- Browser print functionality
- Optimized for paper output
- Clean, professional appearance

## Real-time Features

### **Auto-refresh**
- Updates every 5 minutes
- Ensures data currency
- No manual refresh needed

### **Live Data**
- Pulls from current database
- Reflects recent transactions
- Accurate financial reporting

## Troubleshooting

### **Common Issues**

#### **No Data Showing**
- Check if `pos_receipts` table exists
- Verify patient has transactions for the selected date
- Check database permissions

#### **Treatment Not Categorized**
- Review drug names in `drugs` table
- Add keywords to `categorizeTreatment()` function
- Check drug descriptions for categorization clues

#### **Patient Status Incorrect**
- Verify `form_encounter` table has data
- Check encounter dates are in correct format
- Review patient encounter history

### **Debug Mode**
Enable error logging by checking:
- OpenEMR error logs
- Browser console for JavaScript errors
- Database query logs

## Customization

### **Adding New Treatment Types**
1. Edit `categorizeTreatment()` function
2. Add new keywords and logic
3. Update revenue categorization
4. Test with sample data

### **Modifying Business Days**
1. Edit `getMonthlyBreakdownData()` function
2. Update day filtering logic
3. Test monthly reports

### **Changing Card Values**
1. Update `$card_value` variable
2. Modify calculation logic if needed
3. Test revenue calculations

## Security

### **Access Control**
- Requires admin privileges (`acl_check('admin', 'super')`)
- Secure database queries
- Input validation and sanitization

### **Data Protection**
- No sensitive patient data exposure
- Financial data access control
- Audit trail preservation

## Performance

### **Optimization**
- Efficient database queries
- Indexed table access
- Minimal memory usage

### **Scalability**
- Handles large patient volumes
- Efficient date range processing
- Optimized for daily use

## Future Enhancements

### **Planned Features**
- Email report delivery
- Automated scheduling
- Advanced analytics
- Multi-clinic support
- Custom report templates

### **Integration Opportunities**
- Accounting software export
- Practice management systems
- Business intelligence tools
- Financial reporting systems

## Support

### **Documentation**
- This README file
- Code comments
- Function documentation

### **Maintenance**
- Regular database backups
- Monitor error logs
- Update treatment keywords as needed
- Review business day settings

## Conclusion

The DCR System transforms your manual reporting process into an automated, accurate, and real-time solution. It provides the same detailed information as your Excel spreadsheets but with:

- **100% accuracy** - No manual entry errors
- **Real-time data** - Always current information
- **Professional output** - Print and export ready
- **Time savings** - Generate reports in seconds
- **Business insights** - Track trends and performance

This system ensures your daily collection reports are always accurate, up-to-date, and professional, giving you better insights into your practice's financial performance and patient treatment patterns.
