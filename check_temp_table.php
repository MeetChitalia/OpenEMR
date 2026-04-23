<?php
// Set up OpenEMR environment
$GLOBALS['OE_SITE_DIR'] = 'sites/default';
require_once 'library/sqlconf.php';
require_once 'library/sql.inc';

$result = sqlQuery("SHOW TABLES LIKE 'temp_passwords'");
if ($result) {
    echo "Table temp_passwords exists\n";
} else {
    echo "Table temp_passwords does not exist\n";
}
?> 