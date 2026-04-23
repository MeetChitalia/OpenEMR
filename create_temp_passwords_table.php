<?php
// Set up OpenEMR environment
$GLOBALS['OE_SITE_DIR'] = 'sites/default';
require_once 'library/sqlconf.php';
require_once 'library/sql.inc';

// Check if temp_passwords table exists
$result = sqlQuery("SHOW TABLES LIKE 'temp_passwords'");
if (!$result) {
    echo "Creating temp_passwords table...\n";
    
    // Create the temp_passwords table
    $sql = "CREATE TABLE `temp_passwords` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
    
    $result = sqlStatement($sql);
    if ($result) {
        echo "temp_passwords table created successfully!\n";
    } else {
        echo "Error creating temp_passwords table!\n";
    }
} else {
    echo "temp_passwords table already exists.\n";
}

// Check the structure of the table
$result = sqlStatement("DESCRIBE temp_passwords");
echo "\nTable structure:\n";
while ($row = sqlFetchArray($result)) {
    echo $row['Field'] . " - " . $row['Type'] . "\n";
}
?> 