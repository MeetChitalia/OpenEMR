<?php
require_once(__DIR__ . "/../globals.php");
sqlStatement("UPDATE globals SET gl_value = 'Achieve Medical - Weight Loss' WHERE gl_name = 'openemr_name'");
echo "Application name updated successfully."; 