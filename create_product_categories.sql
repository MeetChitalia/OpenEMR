-- Product Categories System
-- This script creates tables for managing product categories and their relationships

-- Drop tables if they exist (for clean installation)
DROP TABLE IF EXISTS `product_categories`;
DROP TABLE IF EXISTS `product_category_mapping`;

-- Create the categories table
CREATE TABLE `product_categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(255) NOT NULL,
  `description` text,
  `is_active` tinyint(1) DEFAULT 1,
  `created_date` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_date` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `category_name` (`category_name`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create the product-category mapping table
CREATE TABLE `product_category_mapping` (
  `mapping_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `category_id` int(11) NOT NULL,
  `category_name` varchar(255) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_date` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_date` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`mapping_id`),
  UNIQUE KEY `product_id` (`product_id`),
  KEY `category_id` (`category_id`),
  KEY `product_name` (`product_name`),
  KEY `category_name` (`category_name`),
  KEY `is_active` (`is_active`),
  FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`category_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert some default categories
INSERT INTO `product_categories` (`category_name`, `description`) VALUES
('Medications', 'Prescription and over-the-counter medications'),
('Medical Supplies', 'Medical equipment and supplies'),
('Surgical Instruments', 'Surgical tools and instruments'),
('Laboratory Supplies', 'Lab testing materials and equipment'),
('Personal Protective Equipment', 'PPE and safety equipment'),
('Diagnostic Equipment', 'Medical diagnostic devices'),
('Emergency Supplies', 'Emergency medical supplies'),
('Pharmaceuticals', 'Drug products and medications'),
('Consumables', 'Disposable medical items'),
('Equipment', 'Medical equipment and devices');

-- Create a view for easy querying of products with their categories
CREATE OR REPLACE VIEW `product_category_view` AS
SELECT 
    pcm.product_id,
    pcm.product_name,
    pcm.category_id,
    pcm.category_name,
    pcm.is_active,
    pcm.created_date,
    pcm.updated_date
FROM `product_category_mapping` pcm
WHERE pcm.is_active = 1;

-- Create indexes for better performance
CREATE INDEX `idx_product_category_active` ON `product_category_mapping` (`is_active`, `category_id`);
CREATE INDEX `idx_product_category_name` ON `product_category_mapping` (`product_name`, `category_name`);

-- Insert sample data (optional - you can remove this section)
INSERT INTO `product_category_mapping` (`product_id`, `product_name`, `category_id`, `category_name`) VALUES
(1, 'Aspirin 325mg', 1, 'Medications'),
(2, 'Bandages', 2, 'Medical Supplies'),
(3, 'Surgical Scissors', 3, 'Surgical Instruments'),
(4, 'Blood Test Tubes', 4, 'Laboratory Supplies'),
(5, 'Face Masks', 5, 'Personal Protective Equipment'),
(6, 'Stethoscope', 6, 'Diagnostic Equipment'),
(7, 'First Aid Kit', 7, 'Emergency Supplies'),
(8, 'Antibiotics', 8, 'Pharmaceuticals'),
(9, 'Disposable Gloves', 9, 'Consumables'),
(10, 'X-Ray Machine', 10, 'Equipment');

-- Show the created tables
SELECT 'Tables created successfully!' as message;
SELECT TABLE_NAME, TABLE_ROWS, ENGINE, TABLE_COLLATION 
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME IN ('product_categories', 'product_category_mapping', 'product_category_view'); 