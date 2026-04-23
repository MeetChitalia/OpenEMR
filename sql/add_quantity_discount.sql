-- Add Quantity Discount Support
-- This script adds quantity-based discount functionality (Buy X Get 1 Free)

-- Add discount_quantity field to drugs table
ALTER TABLE `drugs` 
ADD COLUMN IF NOT EXISTS `discount_quantity` int(11) DEFAULT NULL 
COMMENT 'Quantity for buy X get 1 free discount (must be > 1)' 
AFTER `discount_amount`;

-- Update discount_type enum to include 'quantity'
ALTER TABLE `drugs` 
MODIFY COLUMN `discount_type` enum('percentage','fixed','quantity') 
DEFAULT 'percentage' 
COMMENT 'Type of discount: percentage, fixed amount, or quantity (buy X get 1 free)';



