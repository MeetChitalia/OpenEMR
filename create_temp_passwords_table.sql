-- Create temp_passwords table for temporary password functionality
CREATE TABLE IF NOT EXISTS `temp_passwords` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `username` varchar(255) NOT NULL,
    `temp_password` varchar(255) NOT NULL,
    `email` varchar(255) NOT NULL,
    `expires_at` datetime NOT NULL,
    `used` tinyint(1) DEFAULT 0,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `username` (`username`),
    KEY `expires_at` (`expires_at`),
    KEY `used` (`used`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8; 