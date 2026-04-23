# DCR (Daily Collection Report) System

## 📍 **Location**
The DCR system is now located in the **main Reports menu** and is **facility-wide**.

## 🚀 **How to Access**

### **From Main Menu:**
1. Go to **Reports** in the main menu bar
2. Look for **"DCR Daily Collection Report"**
3. Click to open the facility-wide DCR system

### **Direct URL:**
```
interface/reports/dcr_daily_collection_report.php
```

## ✅ **Key Features**

### **Multi-Facility Support**
- **Facility Selection**: Choose specific facility or all facilities
- **Facility Filtering**: Data automatically filtered by selected facility
- **Cross-Facility Reports**: Generate reports for any facility in your system

### **Report Types**
- **Daily Report**: Detailed daily collection data
- **Monthly Breakdown**: Business day trends (Wed, Thu, Sat)

### **Treatment Categorization**
- **LIPO**: Fat burning injections
- **SEMA**: Semaglutide weight loss injections
- **TRZ**: Tirzepatide weight loss injections
- **Office**: Patient consultations
- **Supplements**: Nutritional products

### **Patient Tracking**
- **New vs Follow-up**: Automatic patient status determination
- **Treatment History**: Complete patient treatment details
- **Revenue Tracking**: Patient spending totals

### **Shot Card System**
- **Automatic Counting**: Tracks cards used per treatment type
- **Revenue Calculation**: $4.00 per card value
- **Daily/Monthly Totals**: Comprehensive card usage reporting

## 🔧 **Configuration**

### **Facility Selection**
- Use the **Facility** dropdown to select specific locations
- Leave blank for all facilities
- Data automatically filters based on selection

### **Date Range**
- **From Date**: Start date for report
- **To Date**: End date for report
- Supports single day or date range reporting

### **Business Days**
- Default: Wednesday, Thursday, Saturday
- Configurable in the monthly breakdown function
- Only business days included in monthly reports

## 📊 **Data Sources**

### **Primary Tables**
1. **`pos_receipts`** - POS transaction records
2. **`drug_sales`** - Pharmaceutical sales
3. **`drugs`** - Drug information and descriptions
4. **`form_encounter`** - Patient encounter history

### **Facility Integration**
- **`facility_id`** field in `pos_receipts` table
- Automatic facility filtering in all queries
- Support for multi-facility OpenEMR installations

## 🎯 **Benefits of Main Reports Location**

### **Before (POS-based):**
- ❌ Limited to POS context
- ❌ Single facility only
- ❌ Not accessible to all users
- ❌ Poor integration with main system

### **After (Main Reports):**
- ✅ **Facility-wide access**
- ✅ **Multi-facility support**
- ✅ **Proper user permissions**
- ✅ **Integrated with main OpenEMR**
- ✅ **Standard report interface**
- ✅ **Better user experience**

## 📋 **User Permissions**

### **Required Access:**
- **Accounting Reports** (`acct` → `rep_a`)
- Standard OpenEMR report permissions
- No special POS access required

### **User Groups:**
- Administrators
- Accounting staff
- Practice managers
- Any user with report access

## 🔄 **Report Generation Process**

1. **User selects** facility, dates, and report type
2. **System queries** database with facility filters
3. **Data processing** categorizes treatments automatically
4. **Patient status** determined from encounter history
5. **Revenue calculation** by treatment type
6. **Card counting** for shot card tracking
7. **Report generation** with professional formatting

## 📈 **Example Output**

```
HOOVER
Daily Collection Report (DCR) - Facility Report
Mon 09/01/2025

Daily Revenue Summary:
├── Total Revenue: $2,450.00
├── Office Visits: $150.00
├── LIPO Injections: $800.00
├── SEMA Injections: $1,200.00
├── TRZ Injections: $300.00
└── Supplements: $0.00

Shot Card Usage:
├── LIPO Cards: 20 ($80.00)
├── SEMA Cards: 30 ($120.00)
└── TRZ Cards: 7 ($28.00)

Patient Treatment Details:
├── John Doe (N) - LIPO - $40.00
├── Jane Smith (F) - SEMA 2.5mg - $40.00
└── Bob Johnson (N) - TRZ 5.0mg - $40.00
```

## 🎉 **Summary**

The DCR system is now **properly integrated** into OpenEMR's main Reports menu with:

- **Multi-facility support** for enterprise installations
- **Standard OpenEMR permissions** and security
- **Professional report interface** matching other reports
- **Complete facility filtering** and data isolation
- **Better user experience** and accessibility

**Access it from: Reports → DCR Daily Collection Report**
