<?php

/**
 * The sqlconf.php file is the central place to load the SITE_ID SQL credentials. It allows allows modules to manage the
 * credential variables
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Robert Down <robertdown@live.com>
 * @copyright Copyright (c) 2022 Robert Down <robertdown@live.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Core\Kernel;
use OpenEMR\Events\Core\SqlConfigEvent;

// Check if OE_SITE_DIR is set, if not use default site
if (isset($GLOBALS['OE_SITE_DIR']) && !empty($GLOBALS['OE_SITE_DIR'])) {
    $siteConfigPath = $GLOBALS['OE_SITE_DIR'] . "/sqlconf.php";
} else {
    // Fallback to default site configuration
    $siteConfigPath = dirname(__FILE__) . "/../sites/default/sqlconf.php";
}

// Check if the site-specific config file exists
if (file_exists($siteConfigPath)) {
    require_once $siteConfigPath;
} else {
    // If site config doesn't exist, use default values
    $host = 'localhost';
    $port = '3306';
    $login = 'root';
    $pass = '';
    $dbase = 'openemr';
    $db_encoding = 'utf8mb4';
    $disable_utf8_flag = false;
    
    $sqlconf = array();
    global $sqlconf;
    $sqlconf["host"] = $host;
    $sqlconf["port"] = $port;
    $sqlconf["login"] = $login;
    $sqlconf["pass"] = $pass;
    $sqlconf["dbase"] = $dbase;
    $sqlconf["db_encoding"] = $db_encoding;
}

if (array_key_exists('kernel', $GLOBALS) && $GLOBALS['kernel'] instanceof Kernel) {
    $eventDispatcher = $GLOBALS['kernel']->getEventDispatcher();
    $sqlConfigEvent = new SqlConfigEvent();

    if ($eventDispatcher->hasListeners(SqlConfigEvent::EVENT_NAME)) {
        /**
         * @var SqlConfigEvent
         */
        $configEvent = $eventDispatcher->dispatch(new SqlConfigEvent(), SqlConfigEvent::EVENT_NAME);
        $configEntity = $configEvent->getConfig();

        // Override the variables set in sites/<site_id>/sqlconf.php file that was required above.
        $host = $configEntity->getHost();
        $port = $configEntity->getPort();
        $login = $configEntity->getUser();
        $pass = $configEntity->getPass();
        $dbase = $configEntity->getDatabaseName();
        $db_encoding = $configEntity->getEncoding();
        $disable_utf8_flag = $configEntity->getDisableUTF8();
        $config = $configEntity->getConfig();
    }
}
