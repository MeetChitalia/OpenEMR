# Multi-Clinic Inventory System - Deployment Checklist

## Pre-Deployment Checklist

### ✅ 1. Environment Verification
- [ ] OpenEMR version compatibility (5.0.0+)
- [ ] PHP version 7.4+ installed
- [ ] MySQL 5.7+ or MariaDB 10.2+ installed
- [ ] Sufficient disk space (minimum 1GB free)
- [ ] Backup of existing database completed
- [ ] Test environment available

### ✅ 2. File Verification
- [ ] `sql/multi_clinic_inventory.sql` - Database schema
- [ ] `library/MultiClinicInventory.class.php` - Core class
- [ ] `interface/drugs/central_inventory_dashboard.php` - Owner dashboard
- [ ] `interface/drugs/clinic_inventory_management.php` - Clinic management
- [ ] `interface/drugs/transfer_management.php` - Transfer system
- [ ] `MULTI_CLINIC_INVENTORY_SYSTEM.md` - Documentation

### ✅ 3. Database Backup
```bash
# Create backup before deployment
mysqldump -u [username] -p [database_name] > backup_before_inventory_$(date +%Y%m%d_%H%M%S).sql
```

## Deployment Steps

### Step 1: Database Setup
```bash
# Run the SQL script
mysql -u [username] -p [database_name] < sql/multi_clinic_inventory.sql
```

### Step 2: File Installation
```bash
# Copy files to OpenEMR installation
cp library/MultiClinicInventory.class.php /path/to/openemr/library/
cp interface/drugs/central_inventory_dashboard.php /path/to/openemr/interface/drugs/
cp interface/drugs/clinic_inventory_management.php /path/to/openemr/interface/drugs/
cp interface/drugs/transfer_management.php /path/to/openemr/interface/drugs/
```

### Step 3: Permission Setup
```bash
# Set proper file permissions
chmod 644 library/MultiClinicInventory.class.php
chmod 644 interface/drugs/*.php
chown www-data:www-data library/MultiClinicInventory.class.php
chown www-data:www-data interface/drugs/*.php
```

### Step 4: Menu Integration
Add to your OpenEMR menu configuration file:

```json
{
  "label": "Multi-Clinic Inventory",
  "menu_id": "multi_inventory",
  "children": [
    {
      "label": "Central Dashboard",
      "menu_id": "central_dashboard",
      "target": "adm",
      "url": "/interface/drugs/central_inventory_dashboard.php",
      "acl_req": ["admin", "super"]
    },
    {
      "label": "Clinic Management",
      "menu_id": "clinic_management",
      "target": "adm",
      "url": "/interface/drugs/clinic_inventory_management.php",
      "acl_req": ["admin", "users"]
    },
    {
      "label": "Transfer Management",
      "menu_id": "transfer_management",
      "target": "adm",
      "url": "/interface/drugs/transfer_management.php",
      "acl_req": ["admin", "users"]
    }
  ]
}
```

## Post-Deployment Verification

### ✅ 1. Database Tables Verification
```sql
-- Check if all tables were created
SHOW TABLES LIKE '%clinic%';
SHOW TABLES LIKE '%central%';

-- Expected tables:
-- central_inventory
-- clinic_inventory_settings
-- clinic_transfers
-- clinic_transfer_items
-- clinic_inventory_requests
-- clinic_request_items
-- clinic_inventory_alerts
-- central_inventory_reports
-- clinic_inventory_snapshots
```

### ✅ 2. File Access Test
- [ ] Access central dashboard: `/interface/drugs/central_inventory_dashboard.php`
- [ ] Access clinic management: `/interface/drugs/clinic_inventory_management.php`
- [ ] Access transfer management: `/interface/drugs/transfer_management.php`

### ✅ 3. Class Loading Test
```php
// Test if class loads properly
require_once('library/MultiClinicInventory.class.php');
$multi_inventory = new MultiClinicInventory();
echo "Class loaded successfully";
```

### ✅ 4. Permission Test
- [ ] Super admin can access central dashboard
- [ ] Regular users can access clinic management
- [ ] Facility restrictions work properly

## Clinic Configuration

### Step 1: Create 10 Clinics (if not exists)
```sql
-- Example: Create 10 clinics
INSERT INTO facility (name, phone, fax, street, city, state, postal_code, billing_location, service_location) VALUES
('Downtown Medical Center', '555-0101', '555-0102', '123 Main St', 'Downtown', 'CA', '90210', 1, 1),
('Northside Clinic', '555-0201', '555-0202', '456 North Ave', 'Northside', 'CA', '90211', 1, 1),
('Southside Medical', '555-0301', '555-0302', '789 South Blvd', 'Southside', 'CA', '90212', 1, 1),
('Eastside Healthcare', '555-0401', '555-0402', '321 East Rd', 'Eastside', 'CA', '90213', 1, 1),
('Westside Clinic', '555-0501', '555-0502', '654 West St', 'Westside', 'CA', '90214', 1, 1),
('Central Medical', '555-0601', '555-0602', '987 Central Ave', 'Central', 'CA', '90215', 1, 1),
('Riverside Clinic', '555-0701', '555-0702', '147 River Rd', 'Riverside', 'CA', '90216', 1, 1),
('Hillside Medical', '555-0801', '555-0802', '258 Hill St', 'Hillside', 'CA', '90217', 1, 1),
('Valley Healthcare', '555-0901', '555-0902', '369 Valley Blvd', 'Valley', 'CA', '90218', 1, 1),
('Coastal Medical Center', '555-1001', '555-1002', '741 Coast Dr', 'Coastal', 'CA', '90219', 1, 1);
```

### Step 2: Create Warehouses for Each Clinic
```sql
-- Create warehouses for each clinic
INSERT INTO list_options (list_id, option_id, title, option_value, seq, activity) VALUES
('warehouse', 'WH_DOWNTOWN', 'Main Warehouse', '1', 1, 1),
('warehouse', 'WH_NORTHSIDE', 'Main Warehouse', '2', 2, 1),
('warehouse', 'WH_SOUTHSIDE', 'Main Warehouse', '3', 3, 1),
('warehouse', 'WH_EASTSIDE', 'Main Warehouse', '4', 4, 1),
('warehouse', 'WH_WESTSIDE', 'Main Warehouse', '5', 5, 1),
('warehouse', 'WH_CENTRAL', 'Main Warehouse', '6', 6, 1),
('warehouse', 'WH_RIVERSIDE', 'Main Warehouse', '7', 7, 1),
('warehouse', 'WH_HILLSIDE', 'Main Warehouse', '8', 8, 1),
('warehouse', 'WH_VALLEY', 'Main Warehouse', '9', 9, 1),
('warehouse', 'WH_COASTAL', 'Main Warehouse', '10', 10, 1);
```

### Step 3: Configure Clinic Settings
```php
<?php
// Configure settings for each clinic
$multi_inventory = new MultiClinicInventory();

$clinics = array(1, 2, 3, 4, 5, 6, 7, 8, 9, 10);

foreach ($clinics as $facility_id) {
    $multi_inventory->setClinicSetting($facility_id, 'low_stock_threshold_days', 30);
    $multi_inventory->setClinicSetting($facility_id, 'expiration_warning_days', 90);
    $multi_inventory->setClinicSetting($facility_id, 'emergency_transfer_limit', 1000);
    $multi_inventory->setClinicSetting($facility_id, 'auto_transfer_enabled', 0);
    $multi_inventory->setClinicSetting($facility_id, 'transfer_approval_required', 1);
    $multi_inventory->setClinicSetting($facility_id, 'central_reporting_enabled', 1);
    $multi_inventory->setClinicSetting($facility_id, 'snapshot_frequency', 'daily');
    $multi_inventory->setClinicSetting($facility_id, 'alert_escalation_enabled', 1);
}
?>
```

## Testing Checklist

### ✅ 1. Basic Functionality Test
- [ ] Central dashboard loads without errors
- [ ] Clinic management interface loads
- [ ] Transfer management interface loads
- [ ] No PHP errors in error logs

### ✅ 2. Database Functionality Test
- [ ] Can create transfer request
- [ ] Can approve transfer
- [ ] Can ship transfer
- [ ] Can receive transfer
- [ ] Alerts are created properly
- [ ] Reports are generated

### ✅ 3. Permission Test
- [ ] Owner can see all clinics
- [ ] Clinic managers see only their clinic
- [ ] Regular users have appropriate access
- [ ] Transfer approvals work correctly

### ✅ 4. Integration Test
- [ ] Existing inventory data is accessible
- [ ] New inventory entries work
- [ ] Sales transactions work normally
- [ ] No conflicts with existing functionality

## Rollback Plan

### If Issues Occur:
1. **Database Rollback:**
   ```bash
   mysql -u [username] -p [database_name] < backup_before_inventory_[timestamp].sql
   ```

2. **File Rollback:**
   ```bash
   rm library/MultiClinicInventory.class.php
   rm interface/drugs/central_inventory_dashboard.php
   rm interface/drugs/clinic_inventory_management.php
   rm interface/drugs/transfer_management.php
   ```

3. **Menu Rollback:**
   - Remove added menu items from configuration

## Performance Monitoring

### Key Metrics to Monitor:
- [ ] Page load times for inventory interfaces
- [ ] Database query performance
- [ ] Memory usage during peak times
- [ ] Transfer processing times
- [ ] Alert generation performance

### Optimization if Needed:
- [ ] Add database indexes
- [ ] Implement caching
- [ ] Optimize queries
- [ ] Add pagination for large datasets

## Security Verification

### ✅ Security Checklist:
- [ ] CSRF protection working
- [ ] SQL injection prevention
- [ ] XSS protection
- [ ] Access control working
- [ ] Audit trail functioning
- [ ] Data validation working

## Documentation

### ✅ Documentation Tasks:
- [ ] Update user manuals
- [ ] Create training materials
- [ ] Document customizations
- [ ] Create troubleshooting guide
- [ ] Update system documentation

## Go-Live Checklist

### ✅ Final Verification:
- [ ] All tests passed
- [ ] Performance acceptable
- [ ] Security verified
- [ ] Backup procedures in place
- [ ] Support team trained
- [ ] Users trained
- [ ] Monitoring in place
- [ ] Rollback plan ready

## Post-Go-Live Monitoring

### Week 1:
- [ ] Monitor system performance
- [ ] Check error logs daily
- [ ] Verify user access
- [ ] Monitor transfer workflows
- [ ] Check alert generation

### Week 2-4:
- [ ] Generate weekly reports
- [ ] Review user feedback
- [ ] Optimize performance if needed
- [ ] Address any issues
- [ ] Plan future enhancements

---

**Note:** This checklist should be completed in order. Do not proceed to the next step until the current step is verified and working correctly. 