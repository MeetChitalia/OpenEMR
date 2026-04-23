-- Custom Multi-Clinic Inventory System Database Setup
-- This creates a completely separate inventory system from OpenEMR's built-in inventory

-- Create clinics table
CREATE TABLE IF NOT EXISTS `custom_clinics` (
  `clinic_id` int(11) NOT NULL AUTO_INCREMENT,
  `clinic_name` varchar(100) NOT NULL,
  `clinic_code` varchar(20) NOT NULL,
  `address` text,
  `phone` varchar(20),
  `email` varchar(100),
  `contact_person` varchar(100),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_date` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`clinic_id`),
  UNIQUE KEY `clinic_code` (`clinic_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Create drugs table
CREATE TABLE IF NOT EXISTS `custom_drugs` (
  `drug_id` int(11) NOT NULL AUTO_INCREMENT,
  `drug_name` varchar(200) NOT NULL,
  `ndc_number` varchar(20),
  `manufacturer` varchar(100),
  `dosage_form` varchar(50),
  `strength` varchar(50),
  `unit` varchar(20),
  `description` text,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_date` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`drug_id`),
  UNIQUE KEY `ndc_number` (`ndc_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Create clinic inventory table
CREATE TABLE IF NOT EXISTS `custom_inventory` (
  `inventory_id` int(11) NOT NULL AUTO_INCREMENT,
  `clinic_id` int(11) NOT NULL,
  `drug_id` int(11) NOT NULL,
  `lot_number` varchar(50),
  `expiration_date` date,
  `quantity_on_hand` int(11) NOT NULL DEFAULT 0,
  `quantity_allocated` int(11) NOT NULL DEFAULT 0,
  `quantity_available` int(11) NOT NULL DEFAULT 0,
  `unit_cost` decimal(10,2) DEFAULT 0.00,
  `reorder_level` int(11) DEFAULT 10,
  `max_level` int(11) DEFAULT 100,
  `location` varchar(100),
  `notes` text,
  `created_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_date` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`inventory_id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `drug_id` (`drug_id`),
  KEY `expiration_date` (`expiration_date`),
  FOREIGN KEY (`clinic_id`) REFERENCES `custom_clinics` (`clinic_id`) ON DELETE CASCADE,
  FOREIGN KEY (`drug_id`) REFERENCES `custom_drugs` (`drug_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Create central inventory table
CREATE TABLE IF NOT EXISTS `custom_central_inventory` (
  `central_id` int(11) NOT NULL AUTO_INCREMENT,
  `drug_id` int(11) NOT NULL,
  `total_quantity` int(11) NOT NULL DEFAULT 0,
  `total_allocated` int(11) NOT NULL DEFAULT 0,
  `total_available` int(11) NOT NULL DEFAULT 0,
  `total_value` decimal(12,2) DEFAULT 0.00,
  `last_updated` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`central_id`),
  UNIQUE KEY `drug_id` (`drug_id`),
  FOREIGN KEY (`drug_id`) REFERENCES `custom_drugs` (`drug_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Create transfers table
CREATE TABLE IF NOT EXISTS `custom_transfers` (
  `transfer_id` int(11) NOT NULL AUTO_INCREMENT,
  `from_clinic_id` int(11) NOT NULL,
  `to_clinic_id` int(11) NOT NULL,
  `drug_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `transfer_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `transfer_status` enum('pending','approved','shipped','received','cancelled') DEFAULT 'pending',
  `transfer_type` enum('routine','emergency','return') DEFAULT 'routine',
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `requested_by` int(11),
  `approved_by` int(11),
  `notes` text,
  `created_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_date` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`transfer_id`),
  KEY `from_clinic_id` (`from_clinic_id`),
  KEY `to_clinic_id` (`to_clinic_id`),
  KEY `drug_id` (`drug_id`),
  KEY `transfer_status` (`transfer_status`),
  FOREIGN KEY (`from_clinic_id`) REFERENCES `custom_clinics` (`clinic_id`),
  FOREIGN KEY (`to_clinic_id`) REFERENCES `custom_clinics` (`clinic_id`),
  FOREIGN KEY (`drug_id`) REFERENCES `custom_drugs` (`drug_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Create user-clinic assignments table
CREATE TABLE IF NOT EXISTS `custom_user_clinics` (
  `assignment_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `clinic_id` int(11) NOT NULL,
  `role` enum('admin','manager','user') DEFAULT 'user',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_date` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`assignment_id`),
  UNIQUE KEY `user_clinic` (`user_id`, `clinic_id`),
  KEY `clinic_id` (`clinic_id`),
  FOREIGN KEY (`clinic_id`) REFERENCES `custom_clinics` (`clinic_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Create alerts table
CREATE TABLE IF NOT EXISTS `custom_inventory_alerts` (
  `alert_id` int(11) NOT NULL AUTO_INCREMENT,
  `clinic_id` int(11) NOT NULL,
  `drug_id` int(11) NOT NULL,
  `alert_type` enum('low_stock','expiring_soon','expired','overstock') NOT NULL,
  `alert_message` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_date` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`alert_id`),
  KEY `clinic_id` (`clinic_id`),
  KEY `drug_id` (`drug_id`),
  KEY `alert_type` (`alert_type`),
  KEY `is_active` (`is_active`),
  FOREIGN KEY (`clinic_id`) REFERENCES `custom_clinics` (`clinic_id`) ON DELETE CASCADE,
  FOREIGN KEY (`drug_id`) REFERENCES `custom_drugs` (`drug_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Insert the 20 clinics
INSERT INTO `custom_clinics` (`clinic_name`, `clinic_code`) VALUES
('Aspen Medical Center', 'AMC'),
('Birch Medical', 'BM'),
('Cedar Clinic', 'CC'),
('Central Medical', 'CM'),
('Coastal Medical Center', 'CMC'),
('Downtown Medical Center', 'DMC'),
('Eastside Healthcare', 'EH'),
('Elm Clinic', 'EC'),
('Hillside Medical', 'HM'),
('Maple Healthcare', 'MH'),
('Metro Health Clinic', 'MHC'),
('Northside Clinic', 'NC'),
('Oakland Healthcare', 'OH'),
('Pine Medical Center', 'PMC'),
('Riverside Clinic', 'RC'),
('Southside Medical', 'SM'),
('Sunset Medical', 'SM'),
('Valley Healthcare', 'VH'),
('Westside Clinic', 'WC'),
('Willow Healthcare', 'WH');

-- Insert some sample drugs
INSERT INTO `custom_drugs` (`drug_name`, `ndc_number`, `manufacturer`, `dosage_form`, `strength`, `unit`) VALUES
('Acetaminophen', '12345678901', 'Generic Pharma', 'Tablet', '500', 'mg'),
('Ibuprofen', '12345678902', 'Generic Pharma', 'Tablet', '200', 'mg'),
('Amoxicillin', '12345678903', 'Generic Pharma', 'Capsule', '250', 'mg'),
('Lisinopril', '12345678904', 'Generic Pharma', 'Tablet', '10', 'mg'),
('Metformin', '12345678905', 'Generic Pharma', 'Tablet', '500', 'mg'),
('Omeprazole', '12345678906', 'Generic Pharma', 'Capsule', '20', 'mg'),
('Atorvastatin', '12345678907', 'Generic Pharma', 'Tablet', '10', 'mg'),
('Losartan', '12345678908', 'Generic Pharma', 'Tablet', '50', 'mg'),
('Amlodipine', '12345678909', 'Generic Pharma', 'Tablet', '5', 'mg'),
('Metoprolol', '12345678910', 'Generic Pharma', 'Tablet', '25', 'mg');

-- Insert sample inventory for each clinic
INSERT INTO `custom_inventory` (`clinic_id`, `drug_id`, `lot_number`, `expiration_date`, `quantity_on_hand`, `quantity_allocated`, `quantity_available`, `unit_cost`, `reorder_level`, `max_level`) VALUES
-- Aspen Medical Center
(1, 1, 'LOT001', '2025-12-31', 100, 10, 90, 0.50, 20, 200),
(1, 2, 'LOT002', '2025-12-31', 150, 15, 135, 0.75, 25, 300),
(1, 3, 'LOT003', '2024-06-30', 50, 5, 45, 2.00, 10, 100),

-- Birch Medical
(2, 1, 'LOT004', '2025-12-31', 80, 8, 72, 0.50, 20, 200),
(2, 2, 'LOT005', '2025-12-31', 120, 12, 108, 0.75, 25, 300),
(2, 4, 'LOT006', '2025-12-31', 60, 6, 54, 1.50, 15, 150),

-- Cedar Clinic
(3, 1, 'LOT007', '2025-12-31', 90, 9, 81, 0.50, 20, 200),
(3, 3, 'LOT008', '2024-08-31', 40, 4, 36, 2.00, 10, 100),
(3, 5, 'LOT009', '2025-12-31', 70, 7, 63, 0.25, 20, 150);

-- Insert sample central inventory
INSERT INTO `custom_central_inventory` (`drug_id`, `total_quantity`, `total_allocated`, `total_available`, `total_value`) VALUES
(1, 500, 50, 450, 250.00),
(2, 400, 40, 360, 300.00),
(3, 200, 20, 180, 400.00),
(4, 150, 15, 135, 225.00),
(5, 300, 30, 270, 75.00);

-- Insert sample transfers
INSERT INTO `custom_transfers` (`from_clinic_id`, `to_clinic_id`, `drug_id`, `quantity`, `transfer_status`, `transfer_type`, `priority`) VALUES
(1, 2, 1, 20, 'approved', 'routine', 'medium'),
(2, 3, 2, 15, 'pending', 'routine', 'low'),
(3, 1, 3, 10, 'shipped', 'emergency', 'high');

-- Insert sample alerts
INSERT INTO `custom_inventory_alerts` (`clinic_id`, `drug_id`, `alert_type`, `alert_message`) VALUES
(1, 3, 'expiring_soon', 'Amoxicillin lot LOT003 expires in 3 months'),
(2, 1, 'low_stock', 'Acetaminophen stock is below reorder level'),
(3, 2, 'expired', 'Ibuprofen lot LOT005 has expired');

-- Create admin user assignment (replace 1 with actual admin user ID)
INSERT INTO `custom_user_clinics` (`user_id`, `clinic_id`, `role`) VALUES
(1, 1, 'admin'); -- Assuming user ID 1 is admin

-- Create triggers to update central inventory
DELIMITER $$

CREATE TRIGGER IF NOT EXISTS `update_central_inventory_insert` 
AFTER INSERT ON `custom_inventory`
FOR EACH ROW
BEGIN
    INSERT INTO `custom_central_inventory` (`drug_id`, `total_quantity`, `total_allocated`, `total_available`, `total_value`)
    VALUES (NEW.drug_id, NEW.quantity_on_hand, NEW.quantity_allocated, NEW.quantity_available, NEW.quantity_on_hand * NEW.unit_cost)
    ON DUPLICATE KEY UPDATE
    `total_quantity` = `total_quantity` + NEW.quantity_on_hand,
    `total_allocated` = `total_allocated` + NEW.quantity_allocated,
    `total_available` = `total_available` + NEW.quantity_available,
    `total_value` = `total_value` + (NEW.quantity_on_hand * NEW.unit_cost);
END$$

CREATE TRIGGER IF NOT EXISTS `update_central_inventory_update` 
AFTER UPDATE ON `custom_inventory`
FOR EACH ROW
BEGIN
    UPDATE `custom_central_inventory` 
    SET `total_quantity` = `total_quantity` - OLD.quantity_on_hand + NEW.quantity_on_hand,
        `total_allocated` = `total_allocated` - OLD.quantity_allocated + NEW.quantity_allocated,
        `total_available` = `total_available` - OLD.quantity_available + NEW.quantity_available,
        `total_value` = `total_value` - (OLD.quantity_on_hand * OLD.unit_cost) + (NEW.quantity_on_hand * NEW.unit_cost)
    WHERE `drug_id` = NEW.drug_id;
END$$

CREATE TRIGGER IF NOT EXISTS `update_central_inventory_delete` 
AFTER DELETE ON `custom_inventory`
FOR EACH ROW
BEGIN
    UPDATE `custom_central_inventory` 
    SET `total_quantity` = `total_quantity` - OLD.quantity_on_hand,
        `total_allocated` = `total_allocated` - OLD.quantity_allocated,
        `total_available` = `total_available` - OLD.quantity_available,
        `total_value` = `total_value` - (OLD.quantity_on_hand * OLD.unit_cost)
    WHERE `drug_id` = OLD.drug_id;
END$$

DELIMITER ;

-- Create indexes for better performance
CREATE INDEX `idx_inventory_clinic_drug` ON `custom_inventory` (`clinic_id`, `drug_id`);
CREATE INDEX `idx_transfers_status_date` ON `custom_transfers` (`transfer_status`, `transfer_date`);
CREATE INDEX `idx_alerts_clinic_active` ON `custom_inventory_alerts` (`clinic_id`, `is_active`);

-- Update available quantities
UPDATE `custom_inventory` SET `quantity_available` = `quantity_on_hand` - `quantity_allocated`;

-- Update central inventory totals
UPDATE `custom_central_inventory` cci 
JOIN (
    SELECT drug_id, 
           SUM(quantity_on_hand) as total_qty,
           SUM(quantity_allocated) as total_alloc,
           SUM(quantity_available) as total_avail,
           SUM(quantity_on_hand * unit_cost) as total_val
    FROM custom_inventory 
    GROUP BY drug_id
) ci ON cci.drug_id = ci.drug_id
SET cci.total_quantity = ci.total_qty,
    cci.total_allocated = ci.total_alloc,
    cci.total_available = ci.total_avail,
    cci.total_value = ci.total_val; 