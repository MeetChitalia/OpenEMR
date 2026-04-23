-- SQL Script to Remove Category Column from Drugs Table
-- This script safely removes the category column if it exists

-- Check if the category column exists and remove it
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'drugs' 
     AND COLUMN_NAME = 'category') > 0,
    'ALTER TABLE drugs DROP COLUMN category',
    'SELECT "Category column does not exist in drugs table" as message'
));

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verify the column has been removed
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'drugs' 
AND COLUMN_NAME = 'category';

-- If no results are returned, the column has been successfully removed 