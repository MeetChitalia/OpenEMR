-- Discounts table for inventory management
CREATE TABLE IF NOT EXISTS `discounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text,
  `discount_type` enum('percentage','fixed_amount') NOT NULL DEFAULT 'percentage',
  `discount_value` decimal(10,2) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_automatic` tinyint(1) NOT NULL DEFAULT 1,
  `created_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_start_date` (`start_date`),
  KEY `idx_end_date` (`end_date`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Product-Discount relationship table
CREATE TABLE IF NOT EXISTS `product_discounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `discount_id` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_product_discount` (`product_id`, `discount_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_discount_id` (`discount_id`),
  FOREIGN KEY (`product_id`) REFERENCES `drugs` (`drug_id`) ON DELETE CASCADE,
  FOREIGN KEY (`discount_id`) REFERENCES `discounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Category-Discount relationship table
CREATE TABLE IF NOT EXISTS `category_discounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `discount_id` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_category_discount` (`category_id`, `discount_id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_discount_id` (`discount_id`),
  FOREIGN KEY (`category_id`) REFERENCES `drugs` (`drug_id`) ON DELETE CASCADE,
  FOREIGN KEY (`discount_id`) REFERENCES `discounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4; 