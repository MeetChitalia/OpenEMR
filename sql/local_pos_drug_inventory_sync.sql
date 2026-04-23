-- Local sync for custom POS and drug inventory schema.
-- Run this against the local OpenEMR database to align it with the code in:
--   interface/drugs/*
--   interface/pos/*

START TRANSACTION;

-- Drug inventory metadata expected by interface/drugs/drugs.inc.php
ALTER TABLE `drugs`
    ADD COLUMN IF NOT EXISTS `vial_quantity` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `route`;

-- POS receipts expected by setup_receipts.php and pos_payment_processor.php
CREATE TABLE IF NOT EXISTS `pos_receipts` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `receipt_number` varchar(50) NOT NULL,
    `pid` int(11) NOT NULL,
    `amount` decimal(10,2) NOT NULL,
    `payment_method` varchar(20) NOT NULL,
    `transaction_id` varchar(100) NOT NULL,
    `receipt_data` longtext NOT NULL,
    `created_by` varchar(50) NOT NULL,
    `created_date` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `receipt_number` (`receipt_number`),
    KEY `pid` (`pid`),
    KEY `created_date` (`created_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Superset schema used across pos_payment_processor.php, backdate_save.php,
-- pos_transaction_history.php, pos_receipt.php, and pos_refund_system.php.
CREATE TABLE IF NOT EXISTS `pos_transactions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `pid` int(11) NOT NULL,
    `receipt_number` varchar(50) NOT NULL,
    `transaction_type` varchar(50) NOT NULL DEFAULT 'dispense',
    `payment_method` varchar(20) DEFAULT NULL,
    `payment_status` varchar(20) DEFAULT NULL,
    `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
    `total_amount` decimal(10,2) DEFAULT NULL,
    `credit_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
    `items` longtext DEFAULT NULL,
    `created_date` datetime NOT NULL,
    `user_id` varchar(50) NOT NULL,
    `visit_type` varchar(10) NOT NULL DEFAULT '-',
    `price_override_notes` text DEFAULT NULL,
    `patient_number` int(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `pid` (`pid`),
    KEY `receipt_number` (`receipt_number`),
    KEY `created_date` (`created_date`),
    KEY `transaction_type` (`transaction_type`),
    KEY `payment_method` (`payment_method`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `pos_transactions`
    MODIFY COLUMN `transaction_type` varchar(50) NOT NULL DEFAULT 'dispense',
    ADD COLUMN IF NOT EXISTS `payment_method` varchar(20) DEFAULT NULL AFTER `transaction_type`,
    ADD COLUMN IF NOT EXISTS `payment_status` varchar(20) DEFAULT NULL AFTER `payment_method`,
    ADD COLUMN IF NOT EXISTS `amount` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `payment_status`,
    ADD COLUMN IF NOT EXISTS `total_amount` decimal(10,2) DEFAULT NULL AFTER `amount`,
    ADD COLUMN IF NOT EXISTS `credit_amount` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`,
    ADD COLUMN IF NOT EXISTS `items` longtext DEFAULT NULL AFTER `credit_amount`,
    ADD COLUMN IF NOT EXISTS `visit_type` varchar(10) NOT NULL DEFAULT '-' AFTER `user_id`,
    ADD COLUMN IF NOT EXISTS `price_override_notes` text DEFAULT NULL AFTER `visit_type`,
    ADD COLUMN IF NOT EXISTS `patient_number` int(11) DEFAULT NULL AFTER `price_override_notes`;

ALTER TABLE `pos_transactions`
    MODIFY COLUMN `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
    MODIFY COLUMN `credit_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
    MODIFY COLUMN `visit_type` varchar(10) NOT NULL DEFAULT '-';

CREATE INDEX IF NOT EXISTS `payment_method` ON `pos_transactions` (`payment_method`);

-- Used by pos_refund_system.php when it bootstraps a simpler POS ledger.
CREATE TABLE IF NOT EXISTS `pos_transaction_items` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `transaction_id` int(11) NOT NULL,
    `drug_id` varchar(50) NOT NULL,
    `drug_name` varchar(255) NOT NULL,
    `quantity` int(11) NOT NULL,
    `dispense_quantity` int(11) NOT NULL DEFAULT 0,
    `administer_quantity` int(11) NOT NULL DEFAULT 0,
    `unit_price` decimal(10,2) NOT NULL,
    `total_price` decimal(10,2) NOT NULL,
    `lot_number` varchar(50) DEFAULT NULL,
    `created_date` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_transaction` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Remaining-dispense tracking used throughout POS.
CREATE TABLE IF NOT EXISTS `pos_remaining_dispense` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `pid` int(11) NOT NULL,
    `drug_id` int(11) NOT NULL,
    `lot_number` varchar(50) NOT NULL DEFAULT '',
    `total_quantity` decimal(12,4) NOT NULL DEFAULT 0,
    `dispensed_quantity` decimal(12,4) NOT NULL DEFAULT 0,
    `administered_quantity` decimal(12,4) NOT NULL DEFAULT 0,
    `remaining_quantity` decimal(12,4) NOT NULL DEFAULT 0,
    `receipt_number` varchar(50) NOT NULL DEFAULT '',
    `created_date` datetime NOT NULL,
    `last_updated` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `pid` (`pid`),
    KEY `drug_id` (`drug_id`),
    KEY `receipt_number` (`receipt_number`),
    KEY `remaining_quantity` (`remaining_quantity`),
    KEY `pid_drug_lot` (`pid`, `drug_id`, `lot_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `pos_remaining_dispense`
    MODIFY COLUMN `total_quantity` decimal(12,4) NOT NULL DEFAULT 0,
    MODIFY COLUMN `dispensed_quantity` decimal(12,4) NOT NULL DEFAULT 0,
    MODIFY COLUMN `administered_quantity` decimal(12,4) NOT NULL DEFAULT 0,
    MODIFY COLUMN `remaining_quantity` decimal(12,4) NOT NULL DEFAULT 0;

-- Daily administration limit tracking.
CREATE TABLE IF NOT EXISTS `daily_administer_tracking` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `pid` int(11) NOT NULL,
    `drug_id` int(11) NOT NULL,
    `lot_number` varchar(50) NOT NULL,
    `administer_date` date NOT NULL,
    `total_administered` decimal(12,4) NOT NULL DEFAULT 0,
    `created_date` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_date` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_daily_administer` (`pid`, `drug_id`, `administer_date`),
    KEY `idx_patient_date` (`pid`, `administer_date`),
    KEY `idx_drug_date` (`drug_id`, `administer_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `daily_administer_tracking`
    MODIFY COLUMN `total_administered` decimal(12,4) NOT NULL DEFAULT 0;

-- Refund tracking.
CREATE TABLE IF NOT EXISTS `pos_refunds` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `refund_number` varchar(50) NOT NULL,
    `pid` int(11) NOT NULL,
    `item_id` int(11) NOT NULL,
    `drug_id` int(11) NOT NULL,
    `drug_name` varchar(255) NOT NULL,
    `lot_number` varchar(50) NOT NULL,
    `refund_quantity` decimal(12,4) NOT NULL,
    `refund_amount` decimal(10,2) NOT NULL,
    `refund_type` enum('payment','credit') NOT NULL,
    `original_receipt` varchar(50) NOT NULL,
    `reason` text DEFAULT NULL,
    `created_date` datetime NOT NULL,
    `user_id` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `refund_number` (`refund_number`),
    KEY `pid` (`pid`),
    KEY `item_id` (`item_id`),
    KEY `drug_id` (`drug_id`),
    KEY `refund_type` (`refund_type`),
    KEY `created_date` (`created_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `pos_refunds`
    MODIFY COLUMN `refund_quantity` decimal(12,4) NOT NULL;

-- Patient credit subsystem.
CREATE TABLE IF NOT EXISTS `patient_credit_balance` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `pid` int(11) NOT NULL,
    `balance` decimal(10,2) NOT NULL DEFAULT 0.00,
    `created_date` datetime NOT NULL,
    `updated_date` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `pid` (`pid`),
    KEY `balance` (`balance`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `patient_credit_transactions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `pid` int(11) NOT NULL,
    `transaction_type` enum('payment','refund','transfer') NOT NULL,
    `amount` decimal(10,2) NOT NULL,
    `balance_before` decimal(10,2) NOT NULL,
    `balance_after` decimal(10,2) NOT NULL,
    `receipt_number` varchar(50) DEFAULT NULL,
    `description` text DEFAULT NULL,
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `pid` (`pid`),
    KEY `transaction_type` (`transaction_type`),
    KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `patient_credit_transfers` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `from_pid` int(11) NOT NULL,
    `to_pid` int(11) NOT NULL,
    `amount` decimal(10,2) NOT NULL,
    `reason` text DEFAULT NULL,
    `created_date` datetime NOT NULL,
    `user_id` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `from_pid` (`from_pid`),
    KEY `to_pid` (`to_pid`),
    KEY `created_date` (`created_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Transfer history for moving remaining dispense between patients.
CREATE TABLE IF NOT EXISTS `pos_transfer_history` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `transfer_id` varchar(50) NOT NULL,
    `source_pid` int(11) NOT NULL,
    `source_patient_name` varchar(255) NOT NULL,
    `target_pid` int(11) NOT NULL,
    `target_patient_name` varchar(255) NOT NULL,
    `drug_id` int(11) NOT NULL,
    `drug_name` varchar(255) NOT NULL,
    `lot_number` varchar(50) NOT NULL,
    `quantity_transferred` int(11) NOT NULL,
    `transfer_date` datetime NOT NULL,
    `user_id` varchar(50) NOT NULL,
    `user_name` varchar(255) NOT NULL,
    `notes` text DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `transfer_id` (`transfer_id`),
    KEY `source_pid` (`source_pid`),
    KEY `target_pid` (`target_pid`),
    KEY `drug_id` (`drug_id`),
    KEY `transfer_date` (`transfer_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Marketplace dispense tracking.
CREATE TABLE IF NOT EXISTS `marketplace_dispense_tracking` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `pid` int(11) NOT NULL,
    `drug_id` int(11) NOT NULL,
    `lot_number` varchar(50) NOT NULL,
    `dispense_quantity` int(11) NOT NULL DEFAULT 0,
    `dispense_date` datetime DEFAULT CURRENT_TIMESTAMP,
    `created_by` varchar(50) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_patient_drug` (`pid`, `drug_id`),
    KEY `idx_drug_lot` (`drug_id`, `lot_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Defective medicines tracking.
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
    `notes` text DEFAULT NULL,
    `created_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `drug_id` (`drug_id`),
    KEY `lot_number` (`lot_number`),
    KEY `inventory_id` (`inventory_id`),
    KEY `pid` (`pid`),
    KEY `status` (`status`),
    KEY `reported_by` (`reported_by`),
    KEY `approved_by` (`approved_by`),
    KEY `idx_drug_lot` (`drug_id`, `lot_number`),
    KEY `idx_status_date` (`status`, `created_date`),
    KEY `idx_reported_date` (`reported_by`, `created_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
    `notes` text DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `defective_id` (`defective_id`),
    KEY `replacement_drug_id` (`replacement_drug_id`),
    KEY `replacement_inventory_id` (`replacement_inventory_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
