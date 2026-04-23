<?php
/**
 * One-time script to update the application name.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Database\QueryUtils;

// The new name for the application
$new_name = 'Achieve Medical - Weight Loss';

// Find the existing setting
$result = sqlStatement("SELECT * FROM `globals` WHERE `gl_name` = 'openemr_name'");
$row = sqlFetchArray($result);

if ($row) {
    // Setting exists, so we update it
    echo "Updating existing application name setting...\n";
    $update_sql = "UPDATE `globals` SET `gl_value` = ? WHERE `gl_name` = 'openemr_name'";
    QueryUtils::sqlStatement($update_sql, [$new_name]);
    echo "Successfully updated application name to: " . $new_name . "\n";
} else {
    // Setting does not exist, so we insert it
    // This is less likely, but good to handle as a fallback
    echo "No existing setting found. Creating new application name setting...\n";
    $insert_sql = "INSERT INTO `globals` (`gl_name`, `gl_value`, `gl_index`) VALUES ('openemr_name', ?, 0)";
    QueryUtils::sqlStatement($insert_sql, [$new_name]);
    echo "Successfully set application name to: " . $new_name . "\n";
}

echo "Script finished. Please clear your browser cache and refresh the login page to see the changes.\n"; 