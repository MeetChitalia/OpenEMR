-- Add IP Address column to existing inventory_movement_log table
-- This script should be run if the table already exists without the IP address column

-- Add IP address column to inventory_movement_log table
ALTER TABLE `inventory_movement_log` 
ADD COLUMN `ip_address` varchar(45) DEFAULT NULL AFTER `user_id`,
ADD KEY `idx_ip_address` (`ip_address`);

-- Update existing records to have a default value (optional)
-- UPDATE `inventory_movement_log` SET `ip_address` = 'Unknown' WHERE `ip_address` IS NULL;

-- Add comments
ALTER TABLE `inventory_movement_log` COMMENT = 'Comprehensive audit trail for all inventory movements including IP address tracking'; 