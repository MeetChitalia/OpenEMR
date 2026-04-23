<?php

/**
 * main.php
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Kevin Yeh <kevin.y@integralemr.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Ranganath Pathak <pathak@scrs1.org>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2016 Kevin Yeh <kevin.y@integralemr.com>
 * @copyright Copyright (c) 2016-2019 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019 Ranganath Pathak <pathak@scrs1.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$sessionAllowWrite = true;
require_once(__DIR__ . '/../../globals.php');
require_once $GLOBALS['srcdir'] . '/ESign/Api.php';

use Esign\Api;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\Events\Main\Tabs\RenderEvent;

// Ensure token_main matches so this script can not be run by itself
if (
    (empty($_SESSION['token_main_php'])) ||
    (empty($_GET['token_main'])) ||
    ($_GET['token_main'] != $_SESSION['token_main_php'])
) {
    authCloseSession();
    authLoginScreen(false);
}

// prevent refresh / copy paste behavior (configurable)
if ($GLOBALS['prevent_browser_refresh'] > 1) {
    unset($_SESSION['token_main_php']);
}

$esignApi = new Api();
?>
<!DOCTYPE html>
<html>

<head>
    <title><?php echo text($openemr_name); ?></title>

    <script>
        <?php if ($GLOBALS['prevent_browser_refresh'] > 0) { ?>
            window.addEventListener('beforeunload', (event) => {
                if (!timed_out) {
                    event.returnValue = <?php echo xlj('Recommend not leaving or refreshing or you may lose data.'); ?>;
                }
            });
        <?php } ?>

        <?php require($GLOBALS['srcdir'] . "/restoreSession.php"); ?>

        window.opener = null;
        window.name = "main";

        var timed_out = false;
        var isPortalEnabled = "<?php echo $GLOBALS['portal_onsite_two_enable'] ?>";
        var csrf_token_js = <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>;
        var userDebug = <?php echo js_escape($GLOBALS['user_debug']); ?>;
        var webroot_url = <?php echo js_escape($web_root); ?>;
        var jsLanguageDirection = <?php echo js_escape($_SESSION['language_direction']); ?>;
        var jsGlobals = {};
        jsGlobals.enable_group_therapy = <?php echo js_escape($GLOBALS['enable_group_therapy']); ?>;

        var WindowTitleAddPatient = <?php echo ($GLOBALS['window_title_add_patient_name'] ? 'true' : 'false'); ?>;
        var WindowTitleBase = <?php echo js_escape($openemr_name); ?>;

        function goRepeaterServices() {
            restoreSession();
            let request = new FormData;
            request.append("skip_timeout_reset", "1");
            request.append("isPortal", isPortalEnabled);
            request.append("csrf_token_form", csrf_token_js);

            fetch(webroot_url + "/library/ajax/dated_reminders_counter.php", {
                method: 'POST',
                credentials: 'same-origin',
                body: request
            }).then((response) => {
                if (response.status !== 200) {
                    console.log('Reminders start failed. Status Code: ' + response.status);
                    return;
                }
                return response.json();
            }).then((data) => {
                if (data && data.timeoutMessage && (data.timeoutMessage == 'timeout')) {
                    timeoutLogout();
                }
                if (isPortalEnabled && data) {
                    let mail = data.mailCnt;
                    let chats = data.chatCnt;
                    let audits = data.auditCnt;
                    let payments = data.paymentCnt;
                    let total = data.total;
                    let enable = ((1 * mail) + (1 * audits)); // payments are among audits.
                    app_view_model.application_data.user().portal(enable);
                    if (enable > 0) {
                        app_view_model.application_data.user().portalAlerts(total);
                        app_view_model.application_data.user().portalAudits(audits);
                        app_view_model.application_data.user().portalMail(mail);
                        app_view_model.application_data.user().portalChats(chats);
                        app_view_model.application_data.user().portalPayments(payments);
                    }
                }
                if (data) {
                    app_view_model.application_data.user().messages(data.reminderText);
                }
            }).catch(function(error) {
                console.log('Request failed', error);
            });

            setTimeout(function() {
                restoreSession();
                request = new FormData;
                request.append("skip_timeout_reset", "1");
                request.append("ajax", "1");
                request.append("csrf_token_form", csrf_token_js);

                fetch(webroot_url + "/library/ajax/execute_background_services.php", {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: request
                }).then((response) => {
                    if (response.status !== 200) {
                        console.log('Background Service start failed. Status Code: ' + response.status);
                    }
                }).catch(function(error) {
                    console.log('HTML Background Service start Request failed: ', error);
                });
            }, 10000);

            setTimeout("goRepeaterServices()", 60000);
        }

        function isEncounterLocked(encounterId) {
            <?php if ($esignApi->lockEncounters()) { ?>
                restoreSession();
                let url = webroot_url + "/interface/esign/index.php?module=encounter&method=esign_is_encounter_locked";
                $.ajax({
                    type: 'POST',
                    url: url,
                    data: {
                        encounterId: encounterId
                    },
                    success: function(data) {
                        encounter_locked = data;
                    },
                    dataType: 'json',
                    async: false
                });
                return encounter_locked;
            <?php } else { ?>
                return false;
            <?php } ?>
        }
    </script>

    <?php Header::setupHeader(['knockout', 'tabs-theme', 'i18next', 'hotkeys', 'fontawesome', 'dialog']); ?>
    <link rel="stylesheet" href="../modern_ui.css?v=<?php echo time(); ?>">

    <!-- Keep your essential styles (sidebar + main-menu) -->
    <style>
        .app-flex {
            display: flex;
            min-height: 100vh;
            width: 100%;
            position: relative;
            overflow: hidden;
        }
        .sidebar {
            display: flex;
            flex-direction: column;
            width: 220px;
            min-width: 220px;
            background: #fff;
            border-right: 1px solid #e0e0e0;
            min-height: 100vh;
            max-height: 100vh;
            overflow-y: auto;
            font-family: 'Segoe UI', Arial, sans-serif;
            transition: width 0.24s ease, min-width 0.24s ease, transform 0.24s ease, opacity 0.2s ease, border-color 0.24s ease;
        }
        .sidebar-logo {
            padding: 20px 0 10px 0;
            text-align: center;
            border-bottom: 1px solid #e0e0e0;
        }
        .sidebar-logo img {
            max-width: 170px;
            width: 100%;
            height: auto;
        }

        .main-menu-list, .main-menu-dropdown {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .main-menu-item {
            position: relative;
            width: 100%;
        }
        .main-menu-link, .main-menu-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            padding: 10px 18px;
            font-size: 1rem;
            color: #222;
            background: none;
            border: none;
            cursor: pointer;
            transition: background 0.15s;
            outline: none;
            font-weight: 500;
        }
        .main-menu-link:hover, .main-menu-header:hover,
        .main-menu-link:focus, .main-menu-header:focus {
            background: #f0f5fa;
            color: #1976d2;
        }
        .main-menu-arrow {
            margin-left: 8px;
            font-size: 1em;
            transition: transform 0.2s;
        }
        .main-menu-arrow.open {
            transform: rotate(90deg);
        }

        .main-menu-dropdown {
            display: none;
            position: fixed;
            min-width: 180px;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            z-index: 1000;
            flex-direction: column;
            padding: 4px 0;
            animation: fadeIn 0.18s;
            max-height: 80vh;
            overflow-y: auto;
            white-space: nowrap;
        }
        .main-menu-dropdown.open {
            display: flex;
        }

        .main-menu-dropdown .main-menu-link,
        .main-menu-dropdown .main-menu-header {
            font-size: 0.95rem;
            padding: 8px 16px;
        }
        .main-menu-dropdown .main-menu-link:hover,
        .main-menu-dropdown .main-menu-header:hover {
            background: #eaf2fb;
            color: #1976d2;
        }

        .sidebar, .main-menu-dropdown {
            scrollbar-width: thin;
            scrollbar-color: #b0b0b0 #f5f5f5;
        }
        .sidebar::-webkit-scrollbar, .main-menu-dropdown::-webkit-scrollbar { width: 7px; }
        .sidebar::-webkit-scrollbar-thumb, .main-menu-dropdown::-webkit-scrollbar-thumb {
            background: #b0b0b0; border-radius: 4px;
        }
        .sidebar::-webkit-scrollbar-track, .main-menu-dropdown::-webkit-scrollbar-track { background: #f5f5f5; }

        .app-flex.sidebar-hidden > .sidebar {
            width: 0;
            min-width: 0;
            transform: translateX(-100%);
            opacity: 0;
            border-right-color: transparent;
            overflow: hidden;
        }

        .app-shell {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            min-width: 0;
            width: 100%;
            max-width: 100%;
            transition: width 0.24s ease;
        }

        .header,
        .main-content,
        #mainFrames_div,
        #framesDisplay,
        #framesDisplay > div,
        #framesDisplay > div > iframe {
            width: 100%;
            max-width: 100%;
            min-width: 0;
        }

        .header {
            left: 220px !important;
            right: 0 !important;
            width: auto !important;
            transition: left 0.24s ease, padding 0.24s ease;
            justify-content: flex-end !important;
            padding: 0 20px !important;
        }

        .main-content {
            left: 220px !important;
            right: 0 !important;
            margin-left: 0 !important;
            width: auto !important;
            transition: left 0.24s ease;
        }

        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .app-flex.sidebar-hidden .header,
        .app-flex.sidebar-hidden .main-content {
            left: 38px !important;
        }

        .app-flex.sidebar-hidden .header {
            padding-left: 50px !important;
            padding-right: 20px !important;
        }

        .header-left {
            display: none !important;
            align-items: center;
            gap: 12px;
            min-width: 150px;
        }

        .sidebar-edge-toggle {
            position: fixed;
            top: 44px;
            left: 220px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 64px;
            border: 1px solid #d8e2ec;
            border-left: none;
            border-radius: 0 18px 18px 0;
            background: #fff;
            color: #35506e;
            box-shadow: 6px 8px 20px rgba(22, 44, 74, 0.10);
            cursor: pointer;
            transition: left 0.24s ease, transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease, color 0.18s ease;
            z-index: 30;
        }

        .sidebar-edge-toggle:hover,
        .sidebar-edge-toggle:focus {
            transform: translateX(1px);
            border-color: #8db8e8;
            color: #1259c3;
            box-shadow: 0 12px 24px rgba(18, 89, 195, 0.14);
            outline: none;
        }

        .sidebar-edge-toggle i {
            font-size: 18px;
            transition: transform 0.24s ease;
        }

        .app-flex.sidebar-hidden .sidebar-edge-toggle {
            left: 0;
            border-left: 1px solid #d8e2ec;
            border-radius: 0 18px 18px 0;
        }

        .app-flex.sidebar-hidden .sidebar-edge-toggle i {
            transform: rotate(180deg);
        }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        html, body {
            width: 100%;
            min-height: 100% !important;
            height: 100% !important;
            overflow-x: hidden;
        }

        @media (max-width: 900px) {
            .sidebar-edge-toggle {
                top: 44px;
                height: 56px;
            }
        }
    </style>

    <script>
        function setupI18n(lang_id) {
            restoreSession();
            return fetch(<?php echo js_escape($GLOBALS['webroot']) ?> + "/library/ajax/i18n_generator.php?lang_id=" + encodeURIComponent(lang_id) + "&csrf_token_form=" + encodeURIComponent(csrf_token_js), {
                credentials: 'same-origin',
                method: 'GET'
            }).then((response) => {
                if (response.status !== 200) {
                    console.log('I18n setup failed. Status Code: ' + response.status);
                    return [];
                }
                return response.json();
            })
        }
        setupI18n(<?php echo js_escape($_SESSION['language_choice']); ?>).then(translationsJson => {
            i18next.init({
                lng: 'selected',
                debug: false,
                nsSeparator: false,
                keySeparator: false,
                resources: { selected: { translation: translationsJson } }
            });
        }).catch(error => console.log(error.message));

        function assignPatientDocuments(patientId) {
            let url = top.webroot_url + '/portal/import_template_ui.php?from_demo_pid=' + encodeURIComponent(patientId);
            dlgopen(url, 'pop-assignments', 'modal-lg', 850, '', '', {
                allowDrag: true,
                allowResize: true,
                sizeHeight: 'full',
            });
        }
    </script>

    <script src="js/custom_bindings.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/user_data_view_model.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/patient_data_view_model.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/therapy_group_data_view_model.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/tabs_view_model.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/application_view_model.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/frame_proxies.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/dialog_utils.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/shortcuts.js?v=<?php echo $v_js_includes; ?>"></script>

    <?php
    if ($GLOBALS['erx_enable']) {
        $newcrop_user_role_stmt = sqlStatement("SELECT `newcrop_user_role` FROM `users` WHERE `username` = ?", array($_SESSION['authUser']));
        $newcrop_user_role_sql = sqlFetchArray($newcrop_user_role_stmt);
        $GLOBALS['newcrop_user_role'] = $newcrop_user_role_sql['newcrop_user_role'];
        if ($GLOBALS['newcrop_user_role'] === 'erxadmin') {
            $GLOBALS['newcrop_user_role_erxadmin'] = 1;
        }
    }

    $track_anything_stmt = sqlStatement("SELECT `state` FROM `registry` WHERE `directory` = 'track_anything'");
    $track_anything_sql = sqlFetchArray($track_anything_stmt);
    $GLOBALS['track_anything_state'] = ($track_anything_sql['state'] ?? 0);

    $GLOBALS['allow_issue_menu_link'] = ((AclMain::aclCheckCore('encounters', 'notes', '', 'write') || AclMain::aclCheckCore('encounters', 'notes_a', '', 'write')) &&
        AclMain::aclCheckCore('patients', 'med', '', 'write'));
    ?>

    <?php require_once("templates/tabs_template.php"); ?>
    <?php require_once("templates/menu_template.php"); ?>
    <?php require_once("templates/patient_data_template.php"); ?>
    <?php require_once("templates/therapy_group_template.php"); ?>
    <?php require_once("templates/user_data_template.php"); ?>
    <?php require_once("menu/menu_json.php"); ?>

    <?php
    $userStmt = sqlStatement("select * from users where username = ?", array($_SESSION['authUser']));
    $userQuery = sqlFetchArray($userStmt);
    ?>

    <script>
        <?php if (!empty($_SESSION['frame1url']) && !empty($_SESSION['frame1target'])) { ?>
            app_view_model.application_data.tabs.tabsList.push(
                new tabStatus(
                    <?php echo xlj("Loading"); ?> + "...",
                    <?php echo json_encode("../" . $_SESSION['frame1url']); ?>,
                    <?php echo json_encode($_SESSION['frame1target']); ?>,
                    <?php echo xlj("Loading"); ?> + " " + <?php echo json_encode($_SESSION['frame1label']); ?>,
                    true, true, false
                )
            );
        <?php } ?>

        <?php if (!empty($_SESSION['frame2url']) && !empty($_SESSION['frame2target'])) { ?>
            app_view_model.application_data.tabs.tabsList.push(
                new tabStatus(
                    <?php echo xlj("Loading"); ?> + "...",
                    <?php echo json_encode("../" . $_SESSION['frame2url']); ?>,
                    <?php echo json_encode($_SESSION['frame2target']); ?>,
                    <?php echo xlj("Loading"); ?> + " " + <?php echo json_encode($_SESSION['frame2label']); ?>,
                    true, false, false
                )
            );
        <?php } ?>

        app_view_model.application_data.user(
            new user_data_view_model(
                <?php echo json_encode($_SESSION["authUser"])
                    . ',' . json_encode($userQuery['fname'])
                    . ',' . json_encode($userQuery['lname'])
                    . ',' . json_encode($_SESSION['authProvider']); ?>
            )
        );
    </script>
</head>

<body class="<?php echo attr($bodyClass ?? ''); ?> dashboard-body">
    <iframe name="logoutinnerframe" id="logoutinnerframe"
        style="visibility:hidden; position:absolute; left:0; top:0; height:0; width:0; border:none;"
        src="about:blank"></iframe>

    <?php
    $disp_mainBox = '';
    if (isset($_SESSION['app1'])) {
        $rs = sqlquery(
            "SELECT title app_url FROM list_options WHERE activity=1 AND list_id=? AND option_id=?",
            array('apps', $_SESSION['app1'])
        );
        if ($rs['app_url'] != "main/main_screen.php") {
            echo '<iframe name="app1" src="../../' . attr($rs['app_url']) . '"
                style="position: absolute; left: 0; top: 0; height: 100%; width: 100%; border: none;" />';
            $disp_mainBox = 'style="display: none;"';
        }
    }
    ?>

    <div class="app-flex">
        <nav id="app-sidebar" class="sidebar">
            <div class="sidebar-logo">
                <img src="<?php echo $GLOBALS['webroot']; ?>/public/images/achieve-medical-logo.png" alt="Logo"
                    onerror="this.style.display='none';this.parentNode.insertAdjacentHTML('beforeend','<span style=\'font-size:1.5rem;font-weight:bold;color:#4A90E2\'>Achieve Medical</span>');">
            </div>
            <div data-bind="template: {name: 'menu-template', data: application_data}"></div>
            <div class="sidebar-bottom"></div>
        </nav>
        <button id="sidebar-toggle" class="sidebar-edge-toggle" type="button" aria-expanded="true" aria-controls="app-sidebar" title="<?php echo attr(xla('Hide or show menu')); ?>">
            <i class="fa fa-chevron-left"></i>
        </button>

        <div class="app-shell">
            <header class="header">
                <div class="header-left"></div>
                <div class="header-actions">
                    <div id="userData" data-bind="template: {name: 'user-data-template', data: application_data}"></div>
                </div>
            </header>

            <main class="main-content">
                <div id="attendantData" class="body_title acck" data-bind="template: {name: app_view_model.attendant_template_type, data: application_data}"></div>
                <div class="body_title" id="tabs_div" data-bind="template: {name: 'tabs-controls', data: application_data}"></div>
                <div class="mainFrames d-flex flex-row" id="mainFrames_div" style="height:100%;width:100%;">
                    <div id="framesDisplay" data-bind="template: {name: 'tabs-frames', data: application_data}"></div>
                </div>
            </main>
        </div>
    </div>

    <div id="global-assistant-widget" class="global-assistant-widget">
        <button id="global-assistant-toggle" class="global-assistant-toggle" type="button" aria-expanded="false" aria-controls="global-assistant-panel">
            <i class="fa fa-comments"></i>
            <span><?php echo xlt('jacki'); ?></span>
        </button>
        <div id="global-assistant-panel" class="global-assistant-panel" hidden>
            <div class="global-assistant-header">
                <div class="global-assistant-title-wrap">
                    <div class="global-assistant-title"><?php echo xlt('jacki'); ?></div>
                    <div class="global-assistant-subtitle"><?php echo xlt('Quick workflow help from anywhere in OpenEMR'); ?></div>
                </div>
                <button id="global-assistant-close" class="global-assistant-close" type="button" aria-label="<?php echo attr(xla('Close jacki')); ?>">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <iframe
                id="global-assistant-frame"
                class="global-assistant-frame"
                title="<?php echo attr(xla('jacki')); ?>"
                loading="lazy"
                src="about:blank"></iframe>
        </div>
    </div>

    <style>
        .global-assistant-widget {
            position: fixed;
            left: 20px;
            bottom: 20px;
            z-index: 5000;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }

        .global-assistant-toggle {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            border: 1px solid #8fb8df;
            border-radius: 999px;
            padding: 11px 18px;
            background: linear-gradient(135deg, #ffffff 0%, #eef7ff 52%, #dceeff 100%);
            color: #0f4f8a;
            font-weight: 700;
            box-shadow: 0 12px 30px rgba(31, 95, 153, 0.18);
            cursor: pointer;
            transition: background 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
            position: relative;
        }

        .global-assistant-toggle:hover {
            transform: translateY(-1px);
            background: linear-gradient(135deg, #ffffff 0%, #f3f9ff 45%, #e1f0ff 100%);
            border-color: #6ea7da;
            box-shadow: 0 16px 34px rgba(31, 95, 153, 0.24);
        }

        .global-assistant-toggle i {
            color: #0d6efd;
            font-size: 18px;
        }

        .global-assistant-toggle span {
            color: #1b4f84;
            font-size: 15px;
            font-weight: 800;
            letter-spacing: 0.01em;
            text-shadow: 0 1px 0 rgba(255, 255, 255, 0.85);
        }

        .global-assistant-toggle::after {
            content: '';
            position: absolute;
            inset: -4px;
            border-radius: 999px;
            border: 1px solid rgba(13, 110, 253, 0.16);
            pointer-events: none;
        }

        .global-assistant-panel {
            width: min(430px, calc(100vw - 24px));
            height: min(720px, calc(100vh - 88px));
            background: #fff;
            border: 1px solid #cfd9e4;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 24px 56px rgba(15, 23, 42, 0.16);
        }

        .global-assistant-header {
            height: 62px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 12px 16px;
            background: linear-gradient(180deg, #ffffff 0%, #f5f9fd 100%);
            color: #203446;
            border-bottom: 1px solid #d7e0ea;
        }

        .global-assistant-title {
            font-size: 16px;
            font-weight: 800;
            line-height: 1.1;
        }

        .global-assistant-subtitle {
            margin-top: 4px;
            font-size: 12px;
            color: #63778b;
        }

        .global-assistant-close {
            border: 1px solid #d5dee8;
            background: #ffffff;
            color: #4d657c;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            cursor: pointer;
        }

        .global-assistant-close:hover {
            background: #f2f7fc;
            color: #1f6fb2;
            border-color: #bfd1e4;
        }

        .global-assistant-frame {
            display: block;
            width: 100%;
            height: calc(100% - 62px);
            border: none;
            background: #eef3f8;
        }

        @media (max-width: 768px) {
            .global-assistant-widget {
                left: 12px;
                bottom: 12px;
            }

            .global-assistant-toggle span {
                display: none;
            }

            .global-assistant-toggle {
                width: 56px;
                height: 56px;
                justify-content: center;
                padding: 0;
                border-radius: 50%;
            }

            .global-assistant-panel {
                width: calc(100vw - 16px);
                height: calc(100vh - 88px);
            }
        }
    </style>

    <script>
        ko.applyBindings(app_view_model);

        $(function() {

            function positionFlyout($dropdown, $header) {
                var headerRect = $header[0].getBoundingClientRect();
                var dropdownWidth = $dropdown.outerWidth();
                var left = headerRect.right;
                var top = headerRect.top;

                if (left + dropdownWidth > window.innerWidth - 8) {
                    left = headerRect.left - dropdownWidth;
                }

                $dropdown.css({ left: left + 'px', top: top + 'px' });

                var dropdownRect = $dropdown[0].getBoundingClientRect();
                var margin = 8;
                if (dropdownRect.bottom > window.innerHeight - margin) {
                    var shift = dropdownRect.bottom - window.innerHeight + margin;
                    $dropdown.css('top', (top - shift) + 'px');
                }
                if ($dropdown[0].getBoundingClientRect().top < margin) {
                    $dropdown.css('top', margin + 'px');
                }
            }

            function positionNestedDropdown($dropdown, $header) {
                var headerRect = $header[0].getBoundingClientRect();
                var dropdownWidth = $dropdown.outerWidth();
                var left = headerRect.right;
                var top = headerRect.top;

                if (left + dropdownWidth > window.innerWidth - 8) {
                    left = headerRect.left - dropdownWidth;
                }

                $dropdown.css({ left: left + 'px', top: top + 'px' });

                var dropdownRect = $dropdown[0].getBoundingClientRect();
                var margin = 8;
                if (dropdownRect.bottom > window.innerHeight - margin) {
                    var shift = dropdownRect.bottom - window.innerHeight + margin;
                    $dropdown.css('top', (top - shift) + 'px');
                }
                if ($dropdown[0].getBoundingClientRect().top < margin) {
                    $dropdown.css('top', margin + 'px');
                }
            }

            function closeAllDropdowns() {
                $('.main-menu-dropdown').removeClass('open');
                $('.main-menu-arrow').removeClass('open');
            }

            var assistantToggle = document.getElementById('global-assistant-toggle');
            var assistantClose = document.getElementById('global-assistant-close');
            var assistantPanel = document.getElementById('global-assistant-panel');
            var assistantFrame = document.getElementById('global-assistant-frame');
            var assistantLoaded = false;

            function getAssistantContext() {
                var context = {
                    area: 'general',
                    title: '',
                    url: '',
                    patient_id: '',
                    report_name: '',
                    pos_state: ''
                };

                if (window.app_view_model && app_view_model.application_data && app_view_model.application_data.tabs && typeof app_view_model.application_data.tabs.tabsList === 'function') {
                    var tabs = app_view_model.application_data.tabs.tabsList();
                    for (var i = 0; i < tabs.length; i++) {
                        var tab = tabs[i];
                        if (tab && typeof tab.visible === 'function' && tab.visible()) {
                            context.title = typeof tab.title === 'function' ? String(tab.title() || '') : '';
                            context.url = typeof tab.url === 'function' ? String(tab.url() || '') : '';
                            break;
                        }
                    }
                }

                var haystack = (context.url + ' ' + context.title).toLowerCase();
                if (haystack.indexOf('interface/pos') !== -1 || haystack.indexOf('checkout') !== -1 || haystack.indexOf('dispense') !== -1) {
                    context.area = 'pos';
                } else if (haystack.indexOf('calendar') !== -1 || haystack.indexOf('appointment') !== -1 || haystack.indexOf('schedule') !== -1) {
                    context.area = 'scheduling';
                } else if (haystack.indexOf('interface/reports') !== -1 || haystack.indexOf('report') !== -1 || haystack.indexOf('dcr') !== -1) {
                    context.area = 'reports';
                } else if (haystack.indexOf('patient') !== -1 || haystack.indexOf('encounter') !== -1 || haystack.indexOf('demographics') !== -1) {
                    context.area = 'patients';
                } else if (haystack.indexOf('inventory') !== -1 || haystack.indexOf('drug') !== -1 || haystack.indexOf('lot') !== -1) {
                    context.area = 'inventory';
                } else if (haystack.indexOf('dashboard') !== -1) {
                    context.area = 'dashboard';
                }

                var patientMatch = context.url.match(/(?:\?|&)(?:pid|set_pid|patient_id)=([0-9]+)/i);
                if (patientMatch && patientMatch[1]) {
                    context.patient_id = patientMatch[1];
                }

                if (context.area === 'reports') {
                    context.report_name = context.title || '';
                }

                if (context.area === 'pos') {
                    if (haystack.indexOf('payment') !== -1) {
                        context.pos_state = 'payment';
                    } else if (haystack.indexOf('backdate') !== -1) {
                        context.pos_state = 'backdate';
                    } else if (haystack.indexOf('administer') !== -1) {
                        context.pos_state = 'administer';
                    } else if (haystack.indexOf('dispense') !== -1) {
                        context.pos_state = 'dispense';
                    } else if (haystack.indexOf('checkout') !== -1) {
                        context.pos_state = 'checkout';
                    }
                }

                return context;
            }

            function buildAssistantUrl() {
                var context = getAssistantContext();
                var url = new URL(webroot_url + '/interface/main/assistant/chat_ui.php', window.location.origin);
                url.searchParams.set('mode', 'staff');
                url.searchParams.set('context_area', context.area);
                url.searchParams.set('context_title', context.title);
                url.searchParams.set('context_url', context.url);
                url.searchParams.set('context_patient_id', context.patient_id);
                url.searchParams.set('context_report_name', context.report_name);
                url.searchParams.set('context_pos_state', context.pos_state);
                url.searchParams.set('embedded', '1');
                return url.toString();
            }

            function setAssistantOpen(isOpen) {
                assistantPanel.hidden = !isOpen;
                assistantToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                if (isOpen) {
                    var nextAssistantUrl = buildAssistantUrl();
                    if (!assistantLoaded || assistantFrame.src !== nextAssistantUrl) {
                        assistantFrame.src = nextAssistantUrl;
                    }
                    assistantLoaded = true;
                }
            }

            assistantToggle.addEventListener('click', function () {
                setAssistantOpen(assistantPanel.hidden);
            });

            assistantClose.addEventListener('click', function () {
                setAssistantOpen(false);
            });

            function overrideMenuToggleOpen(menuItems) {
                if (!menuItems || !Array.isArray(menuItems)) return;

                menuItems.forEach(function(menuItem) {
                    if (menuItem.header && menuItem.toggleOpen) {

                        // ✅ KO is the single source of truth for opening dropdowns
                        menuItem.toggleOpen = function (data, event) {
                            event && event.preventDefault && event.preventDefault();
                            event && event.stopPropagation && event.stopPropagation();

                            if (!this.enabled || !this.enabled()) return;

                            var $header = $(event.target).closest('.main-menu-header');
                            var $item = $header.closest('.main-menu-item');
                            var $dropdown = $item.children('.main-menu-dropdown');
                            var $arrow = $header.find('.main-menu-arrow');

                            if (!$dropdown.length) return;

                            var wasOpen = $dropdown.hasClass('open');

                            // Keep chain = ancestor dropdowns + this dropdown
                            var $keepDropdowns = $item.parents('.main-menu-item').children('.main-menu-dropdown').add($dropdown);

                            // Close open dropdowns not in keep chain (preserve parents)
                            $('.main-menu-dropdown.open').not($keepDropdowns).removeClass('open');
                            $('.main-menu-arrow.open').removeClass('open');

                            // Close siblings at same level (one open per level)
                            var $levelContainer = $item.parent();

                            $levelContainer.children('.main-menu-item')
                                .not($item)
                                .find('.main-menu-dropdown.open')
                                .removeClass('open');

                            $levelContainer.children('.main-menu-item')
                                .not($item)
                                .find('.main-menu-arrow.open')
                                .removeClass('open');

                            if (!wasOpen) {
                                $dropdown.addClass('open');
                                $arrow.addClass('open');

                                if (this.isOpen && typeof this.isOpen === 'function') {
                                    this.isOpen(true);
                                }

                                var isNested = $item.closest('.main-menu-dropdown').length > 0;
                                if (isNested) positionNestedDropdown($dropdown, $header);
                                else positionFlyout($dropdown, $header);

                            } else {
                                $dropdown.removeClass('open');
                                $arrow.removeClass('open');

                                if (this.isOpen && typeof this.isOpen === 'function') {
                                    this.isOpen(false);
                                }

                                // close children too
                                $dropdown.find('.main-menu-dropdown.open').removeClass('open');
                                $dropdown.find('.main-menu-arrow.open').removeClass('open');
                            }
                        };
                    }

                    if (menuItem.children && menuItem.children()) {
                        overrideMenuToggleOpen(menuItem.children());
                    }
                });
            }

            if (typeof app_view_model !== 'undefined' && app_view_model.application_data && app_view_model.application_data.menu) {
                overrideMenuToggleOpen(app_view_model.application_data.menu());
                app_view_model.application_data.menu.subscribe(function(newMenuItems) {
                    overrideMenuToggleOpen(newMenuItems);
                });
            }

            // ✅ Disable jQuery fallback click handler (prevents double-handling bugs)
            $(document).on('click', '.main-menu-header', function(e) {
                return;
            });

            $(document).on('click', '.main-menu-link', function(e) {
                closeAllDropdowns();
            });

            $(document).on('click', function(e) {
                if ($(e.target).closest('.sidebar').length ||
                    $(e.target).closest('.main-menu-header').length ||
                    $(e.target).closest('.main-menu-dropdown').length ||
                    $(e.target).closest('.main-menu-link').length) {
                    return;
                }
                closeAllDropdowns();
            });

            $(document).on('keydown', function(e) {
                if (e.keyCode === 27) closeAllDropdowns();
            });

            $(window).on('blur', function() {
                closeAllDropdowns();
            });

            // ✅ Resize: reposition both top-level and nested dropdowns
            $(window).on('resize', function() {
                $('.main-menu-dropdown.open').each(function() {
                    var $dropdown = $(this);
                    var $header = $dropdown.siblings('.main-menu-header');
                    if (!$header.length) return;

                    var isNested = $dropdown.closest('.main-menu-dropdown').length > 0;
                    if (isNested) positionNestedDropdown($dropdown, $header);
                    else positionFlyout($dropdown, $header);
                });
            });

        });

        $(function() {
            $('#logo_menu').focus();

            var appFlex = document.querySelector('.app-flex');
            var sidebarToggle = document.getElementById('sidebar-toggle');
            var sidebarStorageKey = 'openemr.sidebar.hidden';

            function setSidebarHidden(hidden) {
                if (!appFlex || !sidebarToggle) {
                    return;
                }

                appFlex.classList.toggle('sidebar-hidden', hidden);
                sidebarToggle.setAttribute('aria-expanded', hidden ? 'false' : 'true');
                sidebarToggle.setAttribute('aria-label', hidden ? <?php echo xlj('Show menu'); ?> : <?php echo xlj('Hide menu'); ?>);
                sidebarToggle.title = hidden ? <?php echo xlj('Show menu'); ?> : <?php echo xlj('Hide menu'); ?>;

                window.setTimeout(function () {
                    try {
                        window.dispatchEvent(new Event('resize'));
                    } catch (error) {
                    }

                    document.querySelectorAll('#framesDisplay iframe').forEach(function (frame) {
                        try {
                            frame.contentWindow.dispatchEvent(new Event('resize'));
                        } catch (error) {
                        }
                    });
                }, 260);
            }

            try {
                setSidebarHidden(window.localStorage.getItem(sidebarStorageKey) === '1');
            } catch (error) {
                setSidebarHidden(false);
            }

            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function () {
                    var willHide = !appFlex.classList.contains('sidebar-hidden');
                    setSidebarHidden(willHide);
                    try {
                        window.localStorage.setItem(sidebarStorageKey, willHide ? '1' : '0');
                    } catch (error) {
                    }
                });
            }
        });

        function openGlobalSearchTarget(url, tabName, loadingLabel) {
            top.restoreSession();
            navigateTab(url, tabName, function () {
                activateTabByName(tabName, true);
            }, loadingLabel || '');
        }

        function normalizeGlobalSearchTerm(term) {
            return $.trim(String(term || '').replace(/\s+/g, ' '));
        }

        function resolveGlobalSearchTarget(rawTerm) {
            var term = normalizeGlobalSearchTerm(rawTerm);
            var normalized = term.toLowerCase();
            var webroot = webroot_url || '';
            var keywordSearchTerm = term;
            var patterns = [
                {
                    match: /^(main|home|dashboard)$/i,
                    tab: 'main',
                    label: <?php echo xlj('Main'); ?>,
                    url: webroot + '/interface/main/main_screen.php'
                },
                {
                    match: /^(finder|find|patient|patients)$/i,
                    tab: 'pat',
                    label: <?php echo xlj('Patients'); ?>,
                    url: webroot + '/interface/main/finder/dynamic_finder.php'
                },
                {
                    match: /^(pos|checkout|sale|sales)$/i,
                    tab: 'pos',
                    label: <?php echo xlj('POS'); ?>,
                    url: webroot + '/interface/pos/pos_modal.php'
                },
                {
                    match: /^(inventory|drug|drugs|medicine|medicines|stock)$/i,
                    tab: 'inventory',
                    label: <?php echo xlj('Inventory'); ?>,
                    url: webroot + '/interface/drugs/drug_inventory.php'
                },
                {
                    match: /^(report|reports|dcr|collections|collection)$/i,
                    tab: 'reports',
                    label: <?php echo xlj('Reports'); ?>,
                    url: webroot + '/interface/reports/report_engine.php'
                },
                {
                    match: /^(calendar|schedule|scheduling|appointment|appointments)$/i,
                    tab: 'calendar',
                    label: <?php echo xlj('Calendar'); ?>,
                    url: webroot + '/interface/main/calendar/index.php'
                },
                {
                    match: /^(admin|administration|settings|setup)$/i,
                    tab: 'admin',
                    label: <?php echo xlj('Administration'); ?>,
                    url: webroot + '/interface/main/administration/admin.php'
                }
            ];

            for (var i = 0; i < patterns.length; i++) {
                if (patterns[i].match.test(normalized)) {
                    return patterns[i];
                }
            }

            normalized = normalized.replace(/^(find|finder|patient|patients|search)\s+/i, '');
            keywordSearchTerm = normalizeGlobalSearchTerm(normalized || term);

            return {
                tab: 'pat',
                label: <?php echo xlj('Patients'); ?>,
                url: webroot + '/interface/main/finder/dynamic_finder.php?global_search=' + encodeURIComponent(keywordSearchTerm)
            };
        }

        function runGlobalHeaderSearch() {
            var term = normalizeGlobalSearchTerm($('#anySearchBox').val());
            if (!term) {
                alert(<?php echo xlj('Please enter a search term.'); ?>);
                $('#anySearchBox').focus();
                return false;
            }

            var target = resolveGlobalSearchTarget(term);
            openGlobalSearchTarget(target.url, target.tab, target.label);
            return true;
        }

        $('#anySearchBox').on('keypress', function(event) {
            if (event.which === 13 || event.keyCode === 13) {
                event.preventDefault();
                runGlobalHeaderSearch();
            }
        });

        $('#search_globals').on('click', function(event) {
            event.preventDefault();
            runGlobalHeaderSearch();
        });

        document.addEventListener('touchstart', {});

        $(function() { goRepeaterServices(); });
    </script>

    <?php
    if (!empty($GLOBALS['kernel']->getEventDispatcher())) {
        $dispatcher = $GLOBALS['kernel']->getEventDispatcher();
        $dispatcher->dispatch(new RenderEvent(), RenderEvent::EVENT_BODY_RENDER_POST);
    }
    ?>
</body>

</html>
