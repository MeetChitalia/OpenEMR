-- Enhanced Discount System for Drugs
-- This script adds comprehensive discount functionality to the drugs table

-- Add discount_percent field to drugs table (per product discount)
ALTER TABLE `drugs` ADD COLUMN IF NOT EXISTS `discount_percent` decimal(5,2) DEFAULT 0.00 COMMENT 'Discount percentage for this product (0.00 to 100.00)' AFTER `dispensable`;

-- Add discount_amount field to drugs table (fixed amount discount)
ALTER TABLE `drugs` ADD COLUMN IF NOT EXISTS `discount_amount` decimal(10,2) DEFAULT 0.00 COMMENT 'Fixed discount amount for this product' AFTER `discount_percent`;

-- Add discount_type field to specify if discount is percentage or fixed amount
ALTER TABLE `drugs` ADD COLUMN IF NOT EXISTS `discount_type` enum('percentage','fixed') DEFAULT 'percentage' COMMENT 'Type of discount: percentage or fixed amount' AFTER `discount_amount`;

-- Add discount_active field to enable/disable discount
ALTER TABLE `drugs` ADD COLUMN IF NOT EXISTS `discount_active` tinyint(1) DEFAULT 0 COMMENT 'Whether discount is active (1=active, 0=inactive)' AFTER `discount_type`;

-- Add discount_start_date field for activation date
ALTER TABLE `drugs` ADD COLUMN IF NOT EXISTS `discount_start_date` date DEFAULT NULL COMMENT 'Start date for discount activation' AFTER `discount_active`;

-- Add discount_end_date field for expiration date
ALTER TABLE `drugs` ADD COLUMN IF NOT EXISTS `discount_end_date` date DEFAULT NULL COMMENT 'End date for discount expiration' AFTER `discount_start_date`;

-- Add discount_month field for month-specific discounts
ALTER TABLE `drugs` ADD COLUMN IF NOT EXISTS `discount_month` varchar(7) DEFAULT NULL COMMENT 'Month for discount (YYYY-MM format)' AFTER `discount_end_date`;

-- Add discount_description field for notes
ALTER TABLE `drugs` ADD COLUMN IF NOT EXISTS `discount_description` varchar(255) DEFAULT NULL COMMENT 'Description or notes about the discount' AFTER `discount_month`;

-- Add cost_per_unit field to drugs table (per product pricing)
ALTER TABLE `drugs` ADD COLUMN IF NOT EXISTS `cost_per_unit` decimal(10,2) DEFAULT NULL COMMENT 'Cost per unit for this product' AFTER `discount_description`;

-- Add sell_price field to drugs table (per product pricing)
ALTER TABLE `drugs` ADD COLUMN IF NOT EXISTS `sell_price` decimal(10,2) DEFAULT NULL COMMENT 'Selling price per unit for this product' AFTER `cost_per_unit`;

-- Add sell_price field to drug_inventory table (per lot pricing) - keep for backward compatibility
ALTER TABLE `drug_inventory` ADD COLUMN IF NOT EXISTS `sell_price` decimal(10,2) DEFAULT NULL AFTER `cost_per_unit`;

-- Update existing records to have default values
UPDATE `drugs` SET `discount_percent` = 0.00 WHERE `discount_percent` IS NULL;
UPDATE `drugs` SET `discount_amount` = 0.00 WHERE `discount_amount` IS NULL;
UPDATE `drugs` SET `discount_type` = 'percentage' WHERE `discount_type` IS NULL;
UPDATE `drugs` SET `discount_active` = 0 WHERE `discount_active` IS NULL;
UPDATE `drug_inventory` SET `sell_price` = NULL WHERE `sell_price` IS NULL;

-- Migrate existing lot-level pricing data to product-level
-- For each drug, use the most recent cost and sell price from its lots
UPDATE `drugs` d 
SET d.cost_per_unit = (
    SELECT di.cost_per_unit 
    FROM drug_inventory di 
    WHERE di.drug_id = d.drug_id 
    AND di.cost_per_unit IS NOT NULL 
    AND di.cost_per_unit > 0
    ORDER BY di.inventory_id DESC 
    LIMIT 1
)
WHERE d.cost_per_unit IS NULL;

UPDATE `drugs` d 
SET d.sell_price = (
    SELECT di.sell_price 
    FROM drug_inventory di 
    WHERE di.drug_id = d.drug_id 
    AND di.sell_price IS NOT NULL 
    AND di.sell_price > 0
    ORDER BY di.inventory_id DESC 
    LIMIT 1
)
WHERE d.sell_price IS NULL;

-- Create a view for active discounts
CREATE OR REPLACE VIEW `active_discounts` AS
SELECT 
    drug_id,
    name,
    discount_type,
    discount_percent,
    discount_amount,
    discount_start_date,
    discount_end_date,
    discount_month,
    discount_description,
    CASE 
        WHEN discount_type = 'percentage' THEN CONCAT(discount_percent, '%')
        ELSE CONCAT('$', discount_amount)
    END as discount_display,
    CASE 
        WHEN discount_start_date IS NOT NULL AND discount_end_date IS NOT NULL THEN
            CURDATE() BETWEEN discount_start_date AND discount_end_date
        WHEN discount_start_date IS NOT NULL AND discount_end_date IS NULL THEN
            CURDATE() >= discount_start_date
        WHEN discount_start_date IS NULL AND discount_end_date IS NOT NULL THEN
            CURDATE() <= discount_end_date
        WHEN discount_month IS NOT NULL THEN
            DATE_FORMAT(CURDATE(), '%Y-%m') = discount_month
        ELSE 1
    END as is_currently_active
FROM drugs 
WHERE discount_active = 1 
AND (
    (discount_start_date IS NOT NULL AND discount_end_date IS NOT NULL AND CURDATE() BETWEEN discount_start_date AND discount_end_date)
    OR (discount_start_date IS NOT NULL AND discount_end_date IS NULL AND CURDATE() >= discount_start_date)
    OR (discount_start_date IS NULL AND discount_end_date IS NOT NULL AND CURDATE() <= discount_end_date)
    OR (discount_month IS NOT NULL AND DATE_FORMAT(CURDATE(), '%Y-%m') = discount_month)
    OR (discount_start_date IS NULL AND discount_end_date IS NULL AND discount_month IS NULL)
); 