-- Add category_id and product_id columns to drugs table
-- This script adds the necessary columns for the new dynamic category/subcategory system

-- Add category_id column if it doesn't exist
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'drugs' 
     AND COLUMN_NAME = 'category_id') = 0,
    'ALTER TABLE drugs ADD COLUMN category_id INT NULL',
    'SELECT "category_id column already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add product_id column if it doesn't exist
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'drugs' 
     AND COLUMN_NAME = 'product_id') = 0,
    'ALTER TABLE drugs ADD COLUMN product_id INT NULL',
    'SELECT "product_id column already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key constraints if the referenced tables exist
-- Note: These are optional and can be added later if needed
-- ALTER TABLE drugs ADD CONSTRAINT fk_drugs_category FOREIGN KEY (category_id) REFERENCES categories(category_id);
-- ALTER TABLE drugs ADD CONSTRAINT fk_drugs_product FOREIGN KEY (product_id) REFERENCES products(product_id); 