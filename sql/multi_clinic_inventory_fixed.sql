-- Multi-Clinic Inventory Management System - Fixed Version
-- This file contains the SQL to create additional tables for multi-clinic inventory management

-- Table for central inventory management (owner level)
CREATE TABLE IF NOT EXISTS `central_inventory` (
  `central_id` int(11) NOT NULL AUTO_INCREMENT,
  `drug_id` int(11) NOT NULL,
  `total_quantity` int(11) NOT NULL DEFAULT 0,
  `allocated_quantity` int(11) NOT NULL DEFAULT 0,
  `available_quantity` int(11) NOT NULL DEFAULT 0,
  `central_warehouse_id` varchar(31) DEFAULT NULL,
  `last_updated` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`central_id`),
  UNIQUE KEY `drug_id` (`drug_id`),
  KEY `central_warehouse_id` (`central_warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table for clinic-specific inventory settings
CREATE TABLE IF NOT EXISTS `clinic_inventory_settings` (
  `setting_id` int(11) NOT NULL AUTO_INCREMENT,
  `facility_id` int(11) NOT NULL,
  `setting_name` varchar(100) NOT NULL,
  `setting_value` text,
  `setting_description` text,
  `created_date` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_date` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `facility_setting` (`facility_id`, `setting_name`),
  KEY `facility_id` (`facility_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table for inter-clinic transfers
CREATE TABLE IF NOT EXISTS `clinic_transfers` (
  `transfer_id` int(11) NOT NULL AUTO_INCREMENT,
  `from_facility_id` int(11) NOT NULL,
  `to_facility_id` int(11) NOT NULL,
  `from_warehouse_id` varchar(31) NOT NULL,
  `to_warehouse_id` varchar(31) NOT NULL,
  `transfer_date` date NOT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `actual_delivery_date` date DEFAULT NULL,
  `transfer_status` enum('pending','approved','shipped','delivered','cancelled') DEFAULT 'pending',
  `transfer_type` enum('emergency','scheduled','return') DEFAULT 'scheduled',
  `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
  `shipping_cost` decimal(10,2) DEFAULT 0.00,
  `notes` text,
  `requested_by` varchar(255) DEFAULT NULL,
  `approved_by` varchar(255) DEFAULT NULL,
  `shipped_by` varchar(255) DEFAULT NULL,
  `received_by` varchar(255) DEFAULT NULL,
  `created_date` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_date` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`transfer_id`),
  KEY `from_facility_id` (`from_facility_id`),
  KEY `to_facility_id` (`to_facility_id`),
  KEY `transfer_status` (`transfer_status`),
  KEY `transfer_date` (`transfer_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table for transfer items
CREATE TABLE IF NOT EXISTS `clinic_transfer_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_id` int(11) NOT NULL,
  `drug_id` int(11) NOT NULL,
  `quantity_requested` int(11) NOT NULL,
  `quantity_shipped` int(11) DEFAULT 0,
  `quantity_received` int(11) DEFAULT 0,
  `lot_number` varchar(20) DEFAULT NULL,
  `expiration_date` date DEFAULT NULL,
  `manufacturer` varchar(255) DEFAULT NULL,
  `unit_cost` decimal(10,2) DEFAULT NULL,
  `total_cost` decimal(10,2) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `notes` text,
  PRIMARY KEY (`item_id`),
  KEY `transfer_id` (`transfer_id`),
  KEY `drug_id` (`drug_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table for clinic inventory requests
CREATE TABLE IF NOT EXISTS `clinic_inventory_requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `facility_id` int(11) NOT NULL,
  `request_type` enum('restock','emergency','new_item','discontinue') NOT NULL,
  `request_date` date NOT NULL,
  `requested_by` varchar(255) NOT NULL,
  `approved_by` varchar(255) DEFAULT NULL,
  `approval_date` date DEFAULT NULL,
  `request_status` enum('pending','approved','rejected','completed') DEFAULT 'pending',
  `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
  `expected_delivery_date` date DEFAULT NULL,
  `notes` text,
  `created_date` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_date` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_id`),
  KEY `facility_id` (`facility_id`),
  KEY `request_status` (`request_status`),
  KEY `request_date` (`request_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table for request items
CREATE TABLE IF NOT EXISTS `clinic_request_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `drug_id` int(11) NOT NULL,
  `quantity_requested` int(11) NOT NULL,
  `quantity_approved` int(11) DEFAULT NULL,
  `quantity_delivered` int(11) DEFAULT 0,
  `unit_cost` decimal(10,2) DEFAULT NULL,
  `total_cost` decimal(10,2) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `notes` text,
  PRIMARY KEY (`item_id`),
  KEY `request_id` (`request_id`),
  KEY `drug_id` (`drug_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table for clinic inventory alerts
CREATE TABLE IF NOT EXISTS `clinic_inventory_alerts` (
  `alert_id` int(11) NOT NULL AUTO_INCREMENT,
  `facility_id` int(11) NOT NULL,
  `drug_id` int(11) NOT NULL,
  `warehouse_id` varchar(31) DEFAULT NULL,
  `alert_type` enum('low_stock','reorder_point','expiration','overstock','transfer_needed') NOT NULL,
  `alert_message` text NOT NULL,
  `threshold_value` decimal(10,2) DEFAULT NULL,
  `current_value` decimal(10,2) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `escalation_level` int(11) DEFAULT 1,
  `created_date` timestamp DEFAULT CURRENT_TIMESTAMP,
  `acknowledged_date` timestamp NULL DEFAULT NULL,
  `acknowledged_by` varchar(255) DEFAULT NULL,
  `escalated_date` timestamp NULL DEFAULT NULL,
  `escalated_to` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`alert_id`),
  KEY `facility_id` (`facility_id`),
  KEY `drug_id` (`drug_id`),
  KEY `warehouse_id` (`warehouse_id`),
  KEY `alert_type` (`alert_type`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table for central inventory reports
CREATE TABLE IF NOT EXISTS `central_inventory_reports` (
  `report_id` int(11) NOT NULL AUTO_INCREMENT,
  `report_type` enum('daily','weekly','monthly','quarterly','annual') NOT NULL,
  `report_date` date NOT NULL,
  `facility_id` int(11) DEFAULT NULL,
  `total_products` int(11) DEFAULT 0,
  `total_value` decimal(12,2) DEFAULT 0.00,
  `low_stock_count` int(11) DEFAULT 0,
  `expiring_count` int(11) DEFAULT 0,
  `transfer_count` int(11) DEFAULT 0,
  `request_count` int(11) DEFAULT 0,
  `report_data` longtext,
  `generated_by` varchar(255) DEFAULT NULL,
  `created_date` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`report_id`),
  KEY `report_type` (`report_type`),
  KEY `report_date` (`report_date`),
  KEY `facility_id` (`facility_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table for clinic inventory snapshots (for historical tracking)
CREATE TABLE IF NOT EXISTS `clinic_inventory_snapshots` (
  `snapshot_id` int(11) NOT NULL AUTO_INCREMENT,
  `facility_id` int(11) NOT NULL,
  `warehouse_id` varchar(31) DEFAULT NULL,
  `drug_id` int(11) NOT NULL,
  `inventory_id` int(11) DEFAULT NULL,
  `quantity_on_hand` int(11) NOT NULL,
  `lot_number` varchar(20) DEFAULT NULL,
  `expiration_date` date DEFAULT NULL,
  `unit_cost` decimal(10,2) DEFAULT NULL,
  `total_value` decimal(10,2) DEFAULT NULL,
  `snapshot_date` timestamp DEFAULT CURRENT_TIMESTAMP,
  `snapshot_type` enum('daily','weekly','monthly','manual') DEFAULT 'daily',
  PRIMARY KEY (`snapshot_id`),
  KEY `facility_id` (`facility_id`),
  KEY `warehouse_id` (`warehouse_id`),
  KEY `drug_id` (`drug_id`),
  KEY `snapshot_date` (`snapshot_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default clinic inventory settings
INSERT IGNORE INTO `clinic_inventory_settings` (`facility_id`, `setting_name`, `setting_value`, `setting_description`) VALUES
(1, 'auto_transfer_enabled', '0', 'Enable automatic transfers between clinics'),
(1, 'transfer_approval_required', '1', 'Require approval for inter-clinic transfers'),
(1, 'emergency_transfer_limit', '1000', 'Maximum amount for emergency transfers'),
(1, 'low_stock_threshold_days', '30', 'Days to consider for low stock calculation'),
(1, 'expiration_warning_days', '90', 'Days before expiration to show warning'),
(1, 'central_reporting_enabled', '1', 'Enable reporting to central inventory'),
(1, 'snapshot_frequency', 'daily', 'Frequency of inventory snapshots'),
(1, 'alert_escalation_enabled', '1', 'Enable alert escalation to central management');

-- Add indexes to existing tables for better performance (only if columns exist)
-- Note: These will fail gracefully if columns don't exist
ALTER TABLE `drug_inventory` ADD INDEX IF NOT EXISTS `idx_warehouse_drug` (`warehouse_id`, `drug_id`);
ALTER TABLE `facility` ADD INDEX IF NOT EXISTS `idx_billing_location` (`billing_location`);

-- Add new columns to existing tables if they don't exist
ALTER TABLE `drug_inventory` ADD COLUMN IF NOT EXISTS `facility_id` int(11) DEFAULT NULL AFTER `warehouse_id`;

-- Create triggers to maintain central inventory
DELIMITER $$

CREATE TRIGGER IF NOT EXISTS `update_central_inventory_insert` 
AFTER INSERT ON `drug_inventory`
FOR EACH ROW
BEGIN
    INSERT INTO central_inventory (drug_id, total_quantity, allocated_quantity, available_quantity, updated_by)
    VALUES (NEW.drug_id, NEW.on_hand, 0, NEW.on_hand, COALESCE(NEW.vendor_id, 'system'))
    ON DUPLICATE KEY UPDATE 
        total_quantity = total_quantity + NEW.on_hand,
        available_quantity = available_quantity + NEW.on_hand,
        last_updated = NOW(),
        updated_by = COALESCE(NEW.vendor_id, 'system');
END$$

CREATE TRIGGER IF NOT EXISTS `update_central_inventory_update` 
AFTER UPDATE ON `drug_inventory`
FOR EACH ROW
BEGIN
    UPDATE central_inventory 
    SET total_quantity = total_quantity - OLD.on_hand + NEW.on_hand,
        available_quantity = available_quantity - OLD.on_hand + NEW.on_hand,
        last_updated = NOW(),
        updated_by = COALESCE(NEW.vendor_id, 'system')
    WHERE drug_id = NEW.drug_id;
END$$

CREATE TRIGGER IF NOT EXISTS `update_central_inventory_delete` 
AFTER DELETE ON `drug_inventory`
FOR EACH ROW
BEGIN
    UPDATE central_inventory 
    SET total_quantity = total_quantity - OLD.on_hand,
        available_quantity = available_quantity - OLD.on_hand,
        last_updated = NOW()
    WHERE drug_id = OLD.drug_id;
END$$

DELIMITER ; 