# OpenEMR Inventory Transaction System Documentation

## Overview

The OpenEMR inventory transaction system provides a comprehensive backend for managing drug inventory movements including purchases, transfers, adjustments, returns, and consumption. This document explains the complete transaction column backend logic that has been restored.

## Database Structure

### Core Tables

#### 1. `drug_sales` Table
This is the main transaction table that records all inventory movements:

```sql
CREATE TABLE `drug_sales` (
  `sale_id` int(11) NOT NULL auto_increment,
  `drug_id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `prescription_id` int(11) NOT NULL default '0',
  `pid` bigint(20) NOT NULL default '0',
  `encounter` int(11) NOT NULL default '0',
  `user` varchar(255) default NULL,
  `sale_date` date NOT NULL,
  `quantity` int(11) NOT NULL default '0',
  `fee` decimal(12,2) NOT NULL default '0.00',
  `billed` tinyint(1) NOT NULL default '0',
  `xfer_inventory_id` int(11) NOT NULL DEFAULT 0,
  `distributor_id` bigint(20) NOT NULL DEFAULT 0,
  `notes` varchar(255) NOT NULL DEFAULT '',
  `bill_date` datetime default NULL,
  `pricelevel` varchar(31) default '',
  `selector` varchar(255) default '',
  `trans_type` tinyint NOT NULL DEFAULT 1 COMMENT '1=sale, 2=purchase, 3=return, 4=transfer, 5=adjustment',
  `chargecat` varchar(31) default '',
  PRIMARY KEY (`sale_id`)
);
```

#### 2. `drug_inventory` Table
Stores individual lots and their current quantities:

```sql
CREATE TABLE `drug_inventory` (
  `inventory_id` int(11) NOT NULL auto_increment,
  `drug_id` int(11) NOT NULL,
  `lot_number` varchar(20) default NULL,
  `expiration` date default NULL,
  `manufacturer` varchar(255) default NULL,
  `on_hand` int(11) NOT NULL default '0',
  `warehouse_id` varchar(31) NOT NULL DEFAULT '',
  `vendor_id` bigint(20) NOT NULL DEFAULT 0,
  `last_notify` date NULL,
  `destroy_date` date default NULL,
  `destroy_method` varchar(255) default NULL,
  `destroy_witness` varchar(255) default NULL,
  `destroy_notes` varchar(255) default NULL,
  PRIMARY KEY (`inventory_id`)
);
```

## Transaction Types

The system supports the following transaction types (stored in `drug_sales.trans_type`):

| Type ID | Name | Description | Quantity Impact | Cost Impact |
|---------|------|-------------|----------------|-------------|
| 0 | Edit Only | Modify lot details without quantity change | None | None |
| 1 | Sale | Patient sale | Decrease | Positive |
| 2 | Purchase/Receipt | New inventory received | Increase | Negative |
| 3 | Return | Return to vendor | Decrease | Negative |
| 4 | Transfer | Move between lots/warehouses | Source: Decrease, Destination: Increase | None |
| 5 | Adjustment | Inventory correction | Variable | None |
| 6 | Distribution | Distribution to other facilities | Decrease | None |
| 7 | Consumption | Internal use/consumption | Decrease | None |

## Transaction Flow

### 1. Purchase/Receipt (Type 2)
- **Purpose**: Add new inventory to the system
- **Process**:
  - Creates new lot or adds to existing lot
  - Increases `on_hand` quantity
  - Records cost information
  - Creates `drug_sales` record with `trans_type = 2`

### 2. Transfer (Type 4)
- **Purpose**: Move inventory between lots or warehouses
- **Process**:
  - Reduces quantity from source lot
  - Increases quantity in destination lot
  - Copies lot information (expiration, manufacturer, etc.)
  - Creates `drug_sales` record with `trans_type = 4`
  - Uses `xfer_inventory_id` to link source and destination

### 3. Adjustment (Type 5)
- **Purpose**: Correct inventory discrepancies
- **Process**:
  - Modifies `on_hand` quantity directly
  - No cost impact
  - Creates `drug_sales` record with `trans_type = 5`

### 4. Consumption (Type 7)
- **Purpose**: Record internal use of inventory
- **Process**:
  - Decreases `on_hand` quantity
  - No cost impact
  - Creates `drug_sales` record with `trans_type = 7`

### 5. Return (Type 3)
- **Purpose**: Return inventory to vendor
- **Process**:
  - Decreases `on_hand` quantity
  - Records negative cost
  - Creates `drug_sales` record with `trans_type = 3`

## Key Files and Functions

### 1. `interface/drugs/add_edit_lot.php`
Main transaction interface that handles:
- Form display and validation
- Transaction processing
- Database updates
- Error handling

**Key Functions**:
- `trans_type_changed()`: JavaScript function that shows/hides form fields based on transaction type
- `validate()`: Form validation
- `genWarehouseList()`: Generates warehouse dropdown
- `checkWarehouseUsed()`: Checks if warehouse is already used for a drug

### 2. `interface/drugs/drug_inventory.php`
Main inventory listing page that:
- Displays all drugs and their lots
- Shows "Tran" button for each drug
- Handles popup opening for transaction form

### 3. `interface/reports/inventory_transactions.php`
Transaction reporting that:
- Shows all transactions by date range
- Filters by transaction type
- Displays detailed transaction information

## Security and Permissions

The system uses OpenEMR's ACL (Access Control List) system:

```php
$auth_admin = AclMain::aclCheckCore('admin', 'drugs');
$auth_lots  = $auth_admin || 
    AclMain::aclCheckCore('inventory', 'lots') ||
    AclMain::aclCheckCore('inventory', 'purchases') ||
    AclMain::aclCheckCore('inventory', 'transfers') ||
    AclMain::aclCheckCore('inventory', 'adjustments') ||
    AclMain::aclCheckCore('inventory', 'consumption') ||
    AclMain::aclCheckCore('inventory', 'destruction');
```

**Permission Levels**:
- `admin`: Full access to all functions
- `inventory.purchases`: Can perform purchases and returns
- `inventory.transfers`: Can perform transfers
- `inventory.adjustments`: Can perform adjustments
- `inventory.consumption`: Can record consumption
- `inventory.destruction`: Can destroy lots

## Business Logic

### Quantity Calculations
- **Positive quantities**: Add to inventory
- **Negative quantities**: Remove from inventory
- **Zero quantities**: No change (Edit Only mode)

### Cost Handling
- **Purchase/Receipt**: Positive cost (money spent)
- **Return**: Negative cost (money returned)
- **Transfer/Adjustment/Consumption**: No cost impact

### Lot Management
- **New lots**: Created automatically for purchases and transfers
- **Existing lots**: Updated with new quantities
- **Duplicate prevention**: System checks for existing lots with same number, warehouse, and expiration

### Warehouse Restrictions
- **Single lot per warehouse**: Some drugs restrict to one lot per warehouse
- **User restrictions**: Users may be limited to specific warehouses/facilities
- **Facility mapping**: Warehouses are linked to facilities

## Error Handling

The system includes comprehensive error handling:

1. **Insufficient quantity**: Prevents transfers when source lot doesn't have enough
2. **Negative quantities**: Prevents operations that would result in negative inventory
3. **Duplicate lots**: Prevents creation of duplicate lot numbers
4. **Authorization**: Checks user permissions before allowing operations
5. **Data validation**: Validates all form inputs

## Integration Points

### 1. Patient Billing
- Sales transactions (Type 1) are linked to patient encounters
- Billing information is stored in `drug_sales` table

### 2. Prescription System
- Sales can be linked to prescriptions via `prescription_id`
- Supports medication dispensing workflows

### 3. Accounting System
- Transaction costs are tracked for financial reporting
- Billing status indicates if transactions are posted to accounting

### 4. Reporting System
- Multiple reports available for inventory analysis
- Transaction history and audit trails
- Inventory valuation reports

## Testing

Use the provided `test_transaction_system.php` file to verify:
1. Database structure integrity
2. Sample data availability
3. Warehouse configuration
4. File accessibility
5. Permission system

## Troubleshooting

### Common Issues

1. **Blank popup**: Check file permissions and PHP errors
2. **SQL errors**: Verify database structure and parameter binding
3. **Permission denied**: Check user ACL settings
4. **No warehouses**: Ensure warehouse list options are configured
5. **Transaction not saved**: Check form validation and CSRF tokens

### Debug Steps

1. Check PHP error logs
2. Verify database connectivity
3. Test individual transaction types
4. Check user permissions
5. Validate form data

## Future Enhancements

Potential improvements to consider:
1. Batch transaction processing
2. Advanced reporting and analytics
3. Integration with external suppliers
4. Automated reorder points
5. Mobile inventory management
6. Barcode scanning integration
7. Real-time inventory tracking
8. Multi-location support enhancements

## Conclusion

The OpenEMR inventory transaction system provides a robust, secure, and comprehensive solution for managing drug inventory. The restored backend logic supports all major transaction types with proper validation, error handling, and audit trails. The system integrates seamlessly with OpenEMR's patient care, billing, and reporting modules. 