<?php

/**
 * OpenEMR About Page
 *
 * This Displays an About page for OpenEMR Displaying Version Number, Support Phone Number
 * If it have been entered in Globals along with the Manual and On Line Support Links
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Robert Down <robertdown@live.com>
 * @copyright Copyright (c) 2017 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2021-2022 Robert Down <robertdown@live.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// @TODO: jQuery UI Removal


require_once("../globals.php");

use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Common\Uuid\UniqueInstallationUuid;
use OpenEMR\Services\VersionService;

$twig = new TwigContainer();
$t = $twig->getTwig();

$versionService = new VersionService();
$brandedSystemVersion = '1.0.0';

$viewArgs = [
    'applicationTitle' => $openemr_name,
    'versionNumber' => $brandedSystemVersion,
    'supportPhoneNumber' => $GLOBALS['support_phone_number'] ?? false,
    'theUUID' => UniqueInstallationUuid::getUniqueInstallationUuid(),
    'companyWebsiteHref' => 'https://www.amwl.net/',
    'companyWebsiteLabel' => xlt('Visit Company Website'),
    'systemHeadline' => xlt('Achieve Medical Weight Loss Clinic Management System'),
    'systemSummary' => xlt('This system supports daily operations across Achieve Medical Weight Loss locations, including patient intake, scheduling, inventory tracking, POS checkout, and DCR reporting.'),
    'companySummary' => xlt('Achieve Medical Weight Loss offers physician supervised weight loss programs, customized treatment plans, and a growing network of clinic locations across the United States.'),
    'featurePoints' => [
        xlt('Patient registration, search, and location-based clinic workflows'),
        xlt('Appointment scheduling, POS checkout, and inventory tracking by clinic location'),
        xlt('DCR reporting, facility-specific reporting, and operational visibility across locations'),
    ],
];

echo $t->render('core/about.html.twig', $viewArgs);
