# OpenEMR Access Control Guide
## Customized for Your Medical Practice

### Overview
This guide explains the Access Control List (ACL) system in your customized OpenEMR installation. The ACL system controls which users can access specific features and functions within the application.

---

## Table of Contents
1. [Understanding Access Control](#understanding-access-control)
2. [Your Current Menu Structure](#your-current-menu-structure)
3. [Access Control Categories](#access-control-categories)
4. [User Roles and Permissions](#user-roles-and-permissions)
5. [How to Configure Access Control](#how-to-configure-access-control)
6. [Common Scenarios](#common-scenarios)
7. [Troubleshooting](#troubleshooting)

---

## Understanding Access Control

### What is Access Control?
Access Control determines what each user can see and do in OpenEMR. It's like having different keys for different rooms - some users can access everything, while others have limited access based on their role.

### Key Concepts
- **ACL (Access Control List)**: The system that manages permissions
- **ACO (Access Control Object)**: A specific feature or function (like "View Patients")
- **ARO (Access Request Object)**: A user or group of users
- **ACL Check**: The system verifying if a user has permission to access something

---

## Your Current Menu Structure

Based on your customization, your OpenEMR has these main sections:

```
Finder
├── Patient Finder (Search and select patients)

POS (Point of Sale)
├── POS System (Main point of sale interface)
├── Weight Tracking (Patient weight monitoring)
├── Marketplace Dispense (Hoover location specific)

Patient
├── Patient Information
├── Medical Records
├── Appointments

Inventory
├── Product Management
├── Stock Tracking
├── CSV Import Tool
├── Audit System

Reports
├── Marketplace Dispense Report
├── Inventory Reports
├── Financial Reports
├── Patient Reports

Admin
├── User Management
├── System Configuration
├── Facility Management
├── Access Control Settings
```

---

## Access Control Categories

### 1. Patient Management (`patients`)
Controls access to patient-related functions.

**Available Permissions:**
- `add` - Add new patients
- `read` - View patient information
- `write` - Edit patient information
- `delete` - Remove patients
- `search` - Search for patients

**Example Use Cases:**
- Reception staff: `read`, `search` (can view and find patients)
- Nurses: `read`, `write` (can view and update patient info)
- Doctors: `add`, `read`, `write` (full patient management)

### 2. Point of Sale (`pos`)
Controls access to the POS system and related functions.

**Available Permissions:**
- `add` - Process new transactions
- `read` - View POS interface
- `write` - Modify transactions
- `delete` - Cancel transactions
- `rep` - Generate POS reports

**Example Use Cases:**
- Cashiers: `add`, `read` (can process sales)
- Managers: `add`, `read`, `write`, `rep` (full POS access)
- Hoover Staff: `add`, `read` (marketplace dispense only)

### 3. Inventory Management (`inventory`)
Controls access to inventory and product management.

**Available Permissions:**
- `add` - Add new products
- `read` - View inventory
- `write` - Update stock levels
- `delete` - Remove products
- `rep` - Generate inventory reports

**Example Use Cases:**
- Stock Clerks: `read`, `write` (can update stock)
- Inventory Managers: `add`, `read`, `write`, `rep` (full inventory control)
- Staff: `read` (can view available products)

### 4. Reports (`reports`)
Controls access to various reporting functions.

**Available Permissions:**
- `rep` - Generate reports
- `acct` - Access accounting reports
- `admin` - Administrative reports

**Example Use Cases:**
- Managers: `rep`, `acct` (business reports)
- Accountants: `acct` (financial reports only)
- Administrators: `rep`, `acct`, `admin` (all reports)

### 5. Administration (`admin`)
Controls access to system administration functions.

**Available Permissions:**
- `add` - Add system configurations
- `read` - View system settings
- `write` - Modify system settings
- `delete` - Remove configurations
- `super` - Super administrator access

**Example Use Cases:**
- IT Staff: `read`, `write` (system maintenance)
- Super Admins: `add`, `read`, `write`, `delete`, `super` (full control)

---

## User Roles and Permissions

### Recommended Role Configurations

#### 1. **Super Administrator**
- **Purpose**: Full system control
- **Permissions**: All permissions for all modules
- **Typical Users**: IT Manager, Practice Owner

#### 2. **Practice Manager**
- **Purpose**: Day-to-day operations management
- **Permissions**:
  - Patients: `add`, `read`, `write`, `search`
  - POS: `add`, `read`, `write`, `rep`
  - Inventory: `read`, `write`, `rep`
  - Reports: `rep`, `acct`
  - Admin: `read`

#### 3. **Medical Staff (Doctors/Nurses)**
- **Purpose**: Patient care and medical records
- **Permissions**:
  - Patients: `add`, `read`, `write`, `search`
  - POS: `read` (for weight tracking)
  - Inventory: `read`
  - Reports: `rep` (patient reports only)

#### 4. **Reception Staff**
- **Purpose**: Patient check-in and basic operations
- **Permissions**:
  - Patients: `read`, `search`
  - POS: `read`
  - Inventory: `read`

#### 5. **Cashier/Point of Sale Staff**
- **Purpose**: Handle transactions and sales
- **Permissions**:
  - Patients: `read`, `search`
  - POS: `add`, `read`
  - Inventory: `read`

#### 6. **Hoover Location Staff**
- **Purpose**: Marketplace dispense operations
- **Permissions**:
  - Patients: `read`, `search`
  - POS: `add`, `read` (marketplace dispense only)
  - Inventory: `read`
  - Reports: `rep` (marketplace dispense reports)

#### 7. **Inventory Manager**
- **Purpose**: Stock management and procurement
- **Permissions**:
  - Patients: `read`
  - POS: `read`
  - Inventory: `add`, `read`, `write`, `rep`
  - Reports: `rep` (inventory reports)

#### 8. **Accountant/Financial Staff**
- **Purpose**: Financial reporting and analysis
- **Permissions**:
  - Patients: `read`
  - POS: `read`
  - Inventory: `read`
  - Reports: `rep`, `acct`
  - Admin: `read`

---

## How to Configure Access Control

### Step 1: Access the Admin Panel
1. Log in as a Super Administrator
2. Navigate to **Admin** → **Users** → **Access Control**
3. Or go to **Admin** → **ACL Administration**

### Step 2: Create User Groups (Recommended)
1. Click **"Add Group"**
2. Enter group name (e.g., "Medical Staff", "Cashiers")
3. Add description
4. Save the group

### Step 3: Assign Permissions to Groups
1. Select the group you created
2. Click **"Edit Permissions"**
3. Check the boxes for desired permissions:
   - **Patients**: Check `read`, `write`, `search` as needed
   - **POS**: Check `add`, `read` as needed
   - **Inventory**: Check `read`, `write` as needed
   - **Reports**: Check `rep` as needed
   - **Admin**: Check `read` as needed
4. Save permissions

### Step 4: Assign Users to Groups
1. Go to **Admin** → **Users** → **User Management**
2. Edit the user you want to assign
3. In the **"Groups"** section, select the appropriate group
4. Save the user

### Step 5: Test Access Control
1. Log out and log back in as the test user
2. Verify they can only see/access what they should
3. Test each permission to ensure it works correctly

---

## Common Scenarios

### Scenario 1: New Employee Setup
**Situation**: Hiring a new cashier for the POS system

**Steps**:
1. Create user account in **Admin** → **Users**
2. Assign to "Cashier" group (with POS `add`, `read` permissions)
3. Set facility assignment (important for Hoover location staff)
4. Test login and POS access

### Scenario 2: Restricting Sensitive Reports
**Situation**: Only managers should see financial reports

**Steps**:
1. Create "Manager" group with `acct` permission
2. Create "Staff" group without `acct` permission
3. Assign users to appropriate groups
4. Verify staff cannot access financial reports

### Scenario 3: Hoover Location Specific Access
**Situation**: Hoover staff should only see marketplace dispense features

**Steps**:
1. Create "Hoover Staff" group
2. Assign POS `add`, `read` permissions
3. Set facility assignment to "Hoover Location" (ID: 36)
4. System will automatically show marketplace dispense interface

### Scenario 4: Inventory Management
**Situation**: Only inventory managers should modify stock levels

**Steps**:
1. Create "Inventory Manager" group
2. Assign inventory `add`, `read`, `write`, `rep` permissions
3. Regular staff get only `read` permission
4. Test stock modification access

---

## Troubleshooting

### Common Issues and Solutions

#### Issue 1: User Cannot Access POS System
**Symptoms**: User sees "Access Denied" when trying to open POS
**Solution**: 
1. Check if user has POS `read` permission
2. Verify user is assigned to correct group
3. Ensure user has proper facility assignment

#### Issue 2: Hoover Staff Seeing Regular Dispense
**Symptoms**: Hoover location staff see regular dispense instead of marketplace dispense
**Solution**:
1. Verify user's facility is set to "Hoover Location" (ID: 36)
2. Check if user has POS `add` permission
3. Clear browser cache and log back in

#### Issue 3: Cannot Generate Reports
**Symptoms**: "Access Denied" when trying to view reports
**Solution**:
1. Check if user has `rep` permission for the specific report type
2. For financial reports, ensure user has `acct` permission
3. Verify user is in correct group

#### Issue 4: Cannot Access Inventory
**Symptoms**: Inventory menu not visible or accessible
**Solution**:
1. Check if `inhouse_pharmacy` global setting is enabled
2. Verify user has inventory `read` permission
3. Ensure user is assigned to appropriate group

### Debugging Access Control

#### Check User Permissions
1. Go to **Admin** → **Users** → **User Management**
2. Click on the user
3. Check **"Groups"** section to see assigned groups
4. Check **"ACL"** section to see individual permissions

#### Check Group Permissions
1. Go to **Admin** → **ACL Administration**
2. Select the group
3. Review all assigned permissions
4. Make sure permissions match the intended role

#### Check System Settings
1. Go to **Admin** → **Globals**
2. Verify `inhouse_pharmacy` is set to `1` (for inventory access)
3. Check other relevant global settings

---

## Best Practices

### 1. Use Groups Instead of Individual Permissions
- Create role-based groups (e.g., "Doctors", "Nurses", "Cashiers")
- Assign users to groups rather than individual permissions
- Makes management easier and more consistent

### 2. Follow Principle of Least Privilege
- Give users only the minimum permissions they need
- Regularly review and audit permissions
- Remove unnecessary access when roles change

### 3. Document Your Access Control Structure
- Keep a record of what each group can do
- Document any custom permissions
- Update documentation when changes are made

### 4. Test After Changes
- Always test access control changes
- Verify users can access what they need
- Ensure users cannot access what they shouldn't

### 5. Regular Audits
- Review user permissions quarterly
- Check for inactive users
- Ensure permissions match current job roles

---

## Contact and Support

### For Technical Issues
- Check OpenEMR documentation
- Review system error logs
- Contact your system administrator

### For Customization Questions
- Refer to this guide
- Check your system's specific configuration
- Document any custom changes made

---

## Quick Reference

### Permission Codes
- `add` - Create new items
- `read` - View items
- `write` - Edit items
- `delete` - Remove items
- `search` - Search for items
- `rep` - Generate reports
- `acct` - Access accounting features
- `admin` - Administrative functions
- `super` - Super administrator access

### Your System's Key Modules
- **Patients**: Patient management
- **POS**: Point of sale system
- **Inventory**: Stock management
- **Reports**: Various reporting functions
- **Admin**: System administration

### Important Facility IDs
- **Hoover Location**: ID 36 (enables marketplace dispense)

---

*This guide is customized for your specific OpenEMR installation. For general OpenEMR documentation, refer to the official OpenEMR documentation.*

