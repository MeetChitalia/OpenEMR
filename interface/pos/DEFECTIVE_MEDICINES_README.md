# 🚨 Defective Medicines Management System

## Overview
The Defective Medicines Management System allows POS staff to report medicines as faulty, defective, expired, or contaminated with manager approval. This system automatically deducts defective items from inventory and tracks replacements.

## 🎯 Key Features

### ✅ **Automatic Inventory Management**
- **Real-time Deduction**: Automatically removes defective medicines from inventory upon manager approval
- **Lot Tracking**: Tracks specific lot numbers for precise inventory management
- **Audit Trail**: Complete history of all defective medicine reports and actions

### ✅ **Manager Verification System**
- **Secure Approval**: Requires manager credentials for all defective medicine approvals
- **Role-based Access**: Only authorized managers can approve/reject reports
- **Audit Logging**: Tracks who approved/rejected each report with timestamps

### ✅ **Flexible Defect Types**
- **Defective**: General manufacturing defects
- **Faulty**: Functional issues
- **Expired**: Past expiration date
- **Contaminated**: Contamination issues
- **Other**: Custom defect categories

### ✅ **Replacement Processing**
- **Zero-fee Replacements**: Process replacement medicines with 0 cost if needed
- **Inventory Integration**: Seamlessly integrates with existing POS inventory system
- **Patient Tracking**: Links defective reports to specific patients

## 🗄️ Database Structure

### **Main Tables**

#### `defective_medicines`
```sql
- id: Primary key
- drug_id: Reference to drugs table
- lot_number: Specific lot number
- inventory_id: Reference to drug_inventory table
- pid: Patient ID (optional)
- quantity: Defective quantity
- reason: Detailed reason for defect
- defect_type: Category of defect
- reported_by: Staff member who reported
- approved_by: Manager who approved
- status: pending/approved/rejected/processed
- inventory_deducted: Boolean flag
- replacement_processed: Boolean flag
- notes: Additional information
- created_date: Report timestamp
- updated_date: Last update timestamp
```

#### `defective_medicine_replacements`
```sql
- id: Primary key
- defective_id: Reference to defective_medicines
- replacement_drug_id: New medicine ID
- replacement_lot_number: New lot number
- quantity: Replacement quantity
- fee: Replacement cost (can be 0)
- processed_by: Staff who processed replacement
- processed_date: Processing timestamp
- notes: Replacement notes
```

## 🚀 How to Use

### **1. Report Defective Medicine**

#### **Step 1: Access POS System**
- Navigate to POS interface
- Ensure patient is selected
- Add medicines to cart

#### **Step 2: Report Defect**
- Click **"Report Defective"** button (red button with warning icon)
- Select medicine from cart dropdown
- Choose defect type from dropdown
- Enter detailed reason
- Add optional notes
- Click **"Report Defective"**

#### **Step 3: System Response**
- Item is removed from cart
- Report is created with "pending" status
- Notification shows "Awaiting manager approval"

### **2. Manager Approval Process**

#### **Step 1: Manager Login**
- Manager accesses system with credentials
- Views pending defective medicine reports

#### **Step 2: Review Report**
- Check defect details
- Verify reason and quantity
- Review patient information

#### **Step 3: Approve/Reject**
- **Approve**: 
  - Status changes to "approved"
  - Inventory is automatically deducted
  - System logs approval with timestamp
- **Reject**: 
  - Status changes to "rejected"
  - Requires rejection reason
  - No inventory deduction

### **3. Process Replacement**

#### **Step 1: Select Replacement**
- Choose replacement medicine
- Select lot number
- Set quantity
- Set fee (can be 0 for defective replacements)

#### **Step 2: Process**
- System creates replacement record
- Updates defective medicine status to "processed"
- Links replacement to original defect

## 🔧 Technical Implementation

### **Backend Files**
- `defective_medicines_handler.php` - Main API handler
- `setup_defective_medicines.sql` - Database setup

### **Frontend Integration**
- Integrated into existing POS modal
- Uses same manager verification pattern as price override
- Responsive design with error handling

### **Security Features**
- CSRF token protection
- Manager credential verification
- Session-based authentication
- Comprehensive audit logging

## 📊 Reporting & Analytics

### **Available Reports**
- **Defective Medicines Summary**: Complete view of all reports
- **Status Tracking**: Pending, approved, rejected, processed
- **Inventory Impact**: Quantities deducted from inventory
- **Replacement Tracking**: All replacement medicines processed

### **SQL Views**
```sql
-- Use this view for reporting
SELECT * FROM defective_medicines_summary;
```

## 🚨 Error Handling

### **Common Scenarios**
1. **Invalid Credentials**: Manager verification fails
2. **Insufficient Inventory**: Cannot deduct requested quantity
3. **Database Errors**: Connection or query issues
4. **Invalid Data**: Missing required fields

### **User Feedback**
- Clear error messages for all scenarios
- Success notifications for completed actions
- Progress indicators for long operations

## 🔒 Security Considerations

### **Access Control**
- Only authorized staff can report defects
- Only managers can approve/reject reports
- All actions are logged with user information

### **Data Validation**
- Input sanitization for all user inputs
- Quantity validation against available inventory
- Defect type enumeration to prevent invalid values

## 📱 User Interface

### **Button States**
- **Hidden**: When cart is empty
- **Visible**: When items are in cart
- **Disabled**: During processing operations

### **Modal Design**
- Consistent with existing POS modals
- Responsive design for different screen sizes
- Clear visual hierarchy and user guidance

## 🧪 Testing

### **Test Scenarios**
1. **Report Defect**: Create defective medicine report
2. **Manager Approval**: Verify manager credentials and approve
3. **Inventory Deduction**: Confirm automatic inventory reduction
4. **Replacement Processing**: Process replacement medicine
5. **Error Handling**: Test invalid inputs and edge cases

### **Test Data**
```sql
-- Sample defective medicine report
INSERT INTO defective_medicines (drug_id, lot_number, quantity, reason, defect_type, reported_by, status) 
VALUES (1, 'LOT001', 5.00, 'Vial appears cloudy and discolored', 'contaminated', 'test_user', 'pending');
```

## 🔄 Integration Points

### **Existing Systems**
- **POS Inventory**: Automatic deduction from `drug_inventory`
- **Patient Management**: Links to patient records
- **User Authentication**: Integrates with OpenEMR user system
- **CSRF Protection**: Uses existing OpenEMR CSRF system

### **Future Enhancements**
- **Email Notifications**: Alert managers of pending reports
- **Dashboard Integration**: Manager dashboard for approvals
- **Barcode Integration**: Scan defective items for reporting
- **Photo Attachments**: Add images to defect reports

## 📋 Maintenance

### **Regular Tasks**
- Monitor defective medicine reports
- Review approval/rejection patterns
- Clean up old rejected reports
- Verify inventory deductions

### **Troubleshooting**
- Check error logs for failed operations
- Verify database table structure
- Test manager credential verification
- Monitor inventory deduction accuracy

## 🎉 Benefits

### **For Staff**
- **Easy Reporting**: Simple interface for reporting defects
- **Clear Process**: Step-by-step workflow
- **Immediate Feedback**: Real-time status updates

### **For Management**
- **Complete Visibility**: Track all defective items
- **Inventory Control**: Automatic deduction prevents overstock
- **Audit Trail**: Full history for compliance

### **For Patients**
- **Quality Assurance**: Defective medicines are removed
- **Replacement Process**: Seamless replacement handling
- **Safety**: Contaminated/expired items are tracked

## 🚀 Getting Started

### **1. Setup Database**
```bash
# Run the setup script
mysql -u username -p database_name < setup_defective_medicines.sql
```

### **2. Verify Tables**
```sql
-- Check if tables were created
SHOW TABLES LIKE 'defective_medicines';
SHOW TABLES LIKE 'defective_medicine_replacements';
```

### **3. Test System**
- Add items to POS cart
- Click "Report Defective" button
- Test manager verification
- Verify inventory deduction

### **4. Train Staff**
- Explain defect types and reporting process
- Demonstrate manager approval workflow
- Show replacement processing
- Review error handling

---

**⚠️ Important Notes:**
- Always backup database before running setup scripts
- Test in development environment first
- Ensure manager credentials are properly configured
- Monitor system logs for any errors

**🆘 Support:**
For technical support or questions about the Defective Medicines Management System, please refer to the OpenEMR documentation or contact your system administrator.
