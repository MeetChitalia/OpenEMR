-- Setup for Defective Medicines Tracking System
-- This system allows staff to mark medicines as faulty/defective with manager approval

-- Create defective medicines tracking table
CREATE TABLE IF NOT EXISTS `defective_medicines` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `drug_id` int(11) NOT NULL,
    `lot_number` varchar(100) DEFAULT NULL,
    `inventory_id` int(11) DEFAULT NULL,
    `pid` int(11) DEFAULT NULL,
    `quantity` decimal(10,2) NOT NULL,
    `reason` text NOT NULL,
    `defect_type` enum('faulty', 'defective', 'expired', 'contaminated', 'other') NOT NULL DEFAULT 'defective',
    `reported_by` varchar(100) NOT NULL,
    `approved_by` varchar(100) DEFAULT NULL,
    `approval_date` datetime DEFAULT NULL,
    `status` enum('pending', 'approved', 'rejected', 'processed') NOT NULL DEFAULT 'pending',
    `inventory_deducted` tinyint(1) NOT NULL DEFAULT 0,
    `replacement_processed` tinyint(1) NOT NULL DEFAULT 0,
    `notes` text,
    `created_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `drug_id` (`drug_id`),
    KEY `lot_number` (`lot_number`),
    KEY `inventory_id` (`inventory_id`),
    KEY `pid` (`pid`),
    KEY `status` (`status`),
    KEY `reported_by` (`reported_by`),
    KEY `approved_by` (`approved_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create defective medicine replacements table
CREATE TABLE IF NOT EXISTS `defective_medicine_replacements` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `defective_id` int(11) NOT NULL,
    `replacement_drug_id` int(11) DEFAULT NULL,
    `replacement_lot_number` varchar(100) DEFAULT NULL,
    `replacement_inventory_id` int(11) DEFAULT NULL,
    `quantity` decimal(10,2) NOT NULL,
    `fee` decimal(10,2) DEFAULT 0.00,
    `processed_by` varchar(100) NOT NULL,
    `processed_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notes` text,
    PRIMARY KEY (`id`),
    KEY `defective_id` (`defective_id`),
    KEY `replacement_drug_id` (`replacement_drug_id`),
    KEY `replacement_inventory_id` (`replacement_inventory_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add indexes for better performance
ALTER TABLE `defective_medicines` ADD INDEX `idx_drug_lot` (`drug_id`, `lot_number`);
ALTER TABLE `defective_medicines` ADD INDEX `idx_status_date` (`status`, `created_date`);
ALTER TABLE `defective_medicines` ADD INDEX `idx_reported_date` (`reported_by`, `created_date`);

-- Insert sample data for testing (optional)
-- INSERT INTO `defective_medicines` (`drug_id`, `lot_number`, `quantity`, `reason`, `defect_type`, `reported_by`, `status`) 
-- VALUES (1, 'LOT001', 5.00, 'Vial appears cloudy and discolored', 'contaminated', 'test_user', 'pending');

-- Create view for easy reporting
CREATE OR REPLACE VIEW `defective_medicines_summary` AS
SELECT 
    dm.id,
    dm.drug_id,
    d.name as drug_name,
    dm.lot_number,
    dm.quantity,
    dm.reason,
    dm.defect_type,
    dm.reported_by,
    dm.approved_by,
    dm.approval_date,
    dm.status,
    dm.inventory_deducted,
    dm.replacement_processed,
    dm.created_date,
    dm.updated_date,
    CONCAT(p.fname, ' ', p.lname) as patient_name,
    p.pid
FROM defective_medicines dm
LEFT JOIN drugs d ON dm.drug_id = d.drug_id
LEFT JOIN patient_data p ON dm.pid = p.pid
ORDER BY dm.created_date DESC;
