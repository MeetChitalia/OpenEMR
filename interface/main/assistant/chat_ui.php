<?php

/**
 * Shared chat UI for staff and patient support assistants.
 *
 * @package OpenEMR
 */

$ignoreAuth = false;
require_once(dirname(__FILE__) . "/../../globals.php");
require_once(__DIR__ . "/assistant_common.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$mode = ($_GET['mode'] ?? 'staff') === 'patient' ? 'patient' : 'staff';
$isEmbedded = !empty($_GET['embedded']);
$isAdminUser = !empty($_SESSION['authUserID']) && AclMain::aclCheckCore('admin', 'super');

if ($mode === 'staff' && !AclMain::aclCheckCore('patients', 'demo')) {
    die(xlt('Not authorized'));
}

$csrf = CsrfUtils::collectCsrfToken();
$apiUrl = $mode === 'patient'
    ? $GLOBALS['webroot'] . '/interface/main/assistant/patient_chat_api.php'
    : $GLOBALS['webroot'] . '/interface/main/assistant/staff_chat_api.php';
$feedbackUrl = $GLOBALS['webroot'] . '/interface/main/assistant/feedback_api.php';
$knowledgeAdminUrl = $GLOBALS['webroot'] . '/interface/main/assistant/knowledge_admin.php';
$context = assistantResolveContext([
    'area' => (string)($_GET['context_area'] ?? ''),
    'title' => (string)($_GET['context_title'] ?? ''),
    'url' => (string)($_GET['context_url'] ?? ''),
    'patient_id' => (string)($_GET['context_patient_id'] ?? ''),
    'report_name' => (string)($_GET['context_report_name'] ?? ''),
    'pos_state' => (string)($_GET['context_pos_state'] ?? ''),
]);
$pageTitle = $mode === 'patient' ? xl('jacki Patient Support') : xl('jacki');
$subtitle = $mode === 'patient'
    ? xl('A safe patient-facing support chatbot for general help.')
    : sprintf(xl('jacki is your internal workflow assistant for OpenEMR staff. Current area: %s.'), assistantContextLabel($context['area']));
$starterQuestions = assistantGetStarterQuestions($mode, $context);
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo text($pageTitle); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <style>
        :root {
            --assistant-bg: #edf2f7;
            --assistant-card-bg: #ffffff;
            --assistant-card-border: rgba(203, 213, 225, 0.85);
            --assistant-shadow: 0 28px 60px rgba(15, 23, 42, 0.14);
            --assistant-text: #17324d;
            --assistant-muted: #64748b;
            --assistant-primary: #0f6cbd;
            --assistant-primary-strong: #0b5ea7;
            --assistant-accent: #1f8f6b;
            --assistant-highlight: #dbeafe;
            --assistant-bot-bg: rgba(255, 255, 255, 0.94);
            --assistant-user-bg: linear-gradient(135deg, #0f6cbd 0%, #155fa0 100%);
            --assistant-panel-bg: rgba(255, 255, 255, 0.82);
            --assistant-chat-bg: linear-gradient(180deg, #f8fbff 0%, #f3f7fb 100%);
            --assistant-border-strong: rgba(186, 199, 215, 0.88);
            --assistant-header-bg: linear-gradient(135deg, #17324d 0%, #0f6cbd 58%, #1f8f6b 100%);
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(15, 108, 189, 0.18), transparent 34%),
                radial-gradient(circle at bottom right, rgba(31, 143, 107, 0.16), transparent 30%),
                linear-gradient(180deg, #f5f8fb 0%, var(--assistant-bg) 100%);
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
            color: var(--assistant-text);
        }
        .assistant-shell {
            height: 100vh;
            padding: 18px;
        }
        .assistant-card {
            background: var(--assistant-card-bg);
            border: 1px solid rgba(222, 229, 238, 0.95);
            border-radius: 28px;
            box-shadow: var(--assistant-shadow);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: 100vh;
            max-height: calc(100vh - 36px);
            position: relative;
            backdrop-filter: blur(14px);
        }
        .assistant-admin-link,
        .assistant-close {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            border-radius: 10px;
            border: 1px solid #d5dee8;
            background: #ffffff;
            color: var(--assistant-text);
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
            transition: border-color 0.18s ease, background 0.18s ease, color 0.18s ease;
        }
        .assistant-admin-link {
            padding: 0 14px;
        }
        .assistant-close {
            width: 42px;
            font-size: 24px;
            line-height: 1;
        }
        .assistant-admin-link:hover,
        .assistant-close:hover {
            color: var(--assistant-primary);
            background: #f2f7fc;
            border-color: #bfd1e4;
            text-decoration: none;
        }
        .assistant-floating-close {
            position: relative;
            top: auto;
            right: auto;
            z-index: 1;
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.16);
            border-color: rgba(255, 255, 255, 0.28);
            color: #ffffff;
        }
        .assistant-floating-close:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.24);
            border-color: rgba(255, 255, 255, 0.42);
        }
        .assistant-body {
            padding: 18px;
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .assistant-hero {
            position: relative;
            overflow: hidden;
            border-radius: 24px;
            padding: 22px 22px 20px;
            background: var(--assistant-header-bg);
            color: #ffffff;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08);
        }
        .assistant-hero::before,
        .assistant-hero::after {
            content: "";
            position: absolute;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            pointer-events: none;
        }
        .assistant-hero::before {
            width: 220px;
            height: 220px;
            top: -120px;
            right: -40px;
        }
        .assistant-hero::after {
            width: 140px;
            height: 140px;
            bottom: -50px;
            left: -30px;
        }
        .assistant-hero-top {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
        }
        .assistant-hero-brand {
            display: flex;
            gap: 14px;
            align-items: flex-start;
            min-width: 0;
        }
        .assistant-hero-avatar {
            width: 48px;
            height: 48px;
            flex: 0 0 48px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.18);
            font-size: 21px;
            font-weight: 800;
            letter-spacing: -0.05em;
        }
        .assistant-hero-copy {
            min-width: 0;
        }
        .assistant-hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 11px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.18);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .assistant-hero-title {
            margin: 12px 0 6px;
            font-size: 30px;
            line-height: 1.05;
            font-weight: 800;
            letter-spacing: -0.04em;
        }
        .assistant-hero-subtitle {
            max-width: 760px;
            font-size: 14px;
            line-height: 1.6;
            color: rgba(255, 255, 255, 0.84);
        }
        .assistant-hero-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .assistant-context-row {
            position: relative;
            z-index: 1;
            margin-top: 18px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .assistant-context-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 36px;
            padding: 0 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.18);
            color: #ffffff;
            font-size: 12px;
            font-weight: 700;
        }
        .assistant-context-chip strong {
            font-weight: 800;
        }
        .assistant-context-chip-inline {
            background: #f3f7fb;
            border-color: rgba(201, 214, 230, 0.95);
            color: var(--assistant-text);
        }
        .assistant-chat-panel {
            border-radius: 24px;
            background: var(--assistant-panel-bg);
            border: 1px solid rgba(214, 223, 233, 0.98);
            padding: 14px;
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
            backdrop-filter: blur(10px);
        }
        .assistant-section-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
            padding: 2px 2px 0;
        }
        .assistant-section-copy {
            min-width: 0;
        }
        .assistant-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .assistant-panel-tools {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .assistant-panel-heading {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--assistant-muted);
        }
        .assistant-panel-subcopy {
            margin-top: 4px;
            font-size: 13px;
            color: #6f8498;
            line-height: 1.4;
        }
        .assistant-reset {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid rgba(201, 214, 230, 0.95);
            background: rgba(255, 255, 255, 0.95);
            color: var(--assistant-muted);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            cursor: pointer;
            transition: transform 0.18s ease, background 0.18s ease, color 0.18s ease, border-color 0.18s ease;
        }
        .assistant-reset:hover {
            background: #edf4fb;
            color: var(--assistant-primary);
            transform: translateY(-1px);
        }
        .assistant-live-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(201, 214, 230, 0.95);
            color: var(--assistant-text);
            font-size: 12px;
            font-weight: 700;
        }
        .assistant-live-pill::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--assistant-accent);
            box-shadow: 0 0 0 4px rgba(35, 147, 108, 0.1);
        }
        .assistant-messages {
            min-height: 0;
            flex: 1;
            padding: 18px;
            overflow-y: auto;
            border-radius: 20px;
            background: var(--assistant-chat-bg);
            border: 1px solid rgba(213, 222, 234, 0.95);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.65);
        }
        .assistant-empty-hint {
            display: grid;
            gap: 8px;
            justify-items: start;
            padding: 6px 2px 20px;
            color: var(--assistant-muted);
        }
        .assistant-empty-title {
            font-size: 18px;
            font-weight: 800;
            color: var(--assistant-text);
            letter-spacing: -0.02em;
        }
        .assistant-empty-copy {
            font-size: 14px;
            line-height: 1.55;
            max-width: 760px;
        }
        .assistant-day-label {
            display: flex;
            justify-content: center;
            margin: 0 0 14px;
        }
        .assistant-day-label span {
            padding: 7px 11px;
            border-radius: 999px;
            background: #ffffff;
            border: 1px solid rgba(205, 215, 227, 0.95);
            color: var(--assistant-muted);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .assistant-row {
            display: flex;
            gap: 12px;
            align-items: flex-end;
            margin-bottom: 14px;
            animation: assistantFadeIn 0.28s ease;
        }
        .assistant-row.user {
            justify-content: flex-end;
        }
        .assistant-row.user .assistant-message-wrap {
            align-items: flex-end;
        }
        .assistant-row.user .assistant-bubble-meta {
            justify-content: flex-end;
        }
        .assistant-bubble-avatar {
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: -0.04em;
        }
        .assistant-row.bot .assistant-bubble-avatar {
            color: #fff;
            background: linear-gradient(145deg, #0f6cbd 0%, #1f8f6b 100%);
            box-shadow: 0 12px 24px rgba(15, 108, 189, 0.18);
        }
        .assistant-row.user .assistant-bubble-avatar {
            color: #31506d;
            background: #ffffff;
            border: 1px solid rgba(190, 202, 216, 0.95);
        }
        .assistant-message-wrap {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 0;
            max-width: 84%;
        }
        .assistant-bubble-meta {
            display: flex;
            gap: 8px;
            align-items: center;
            color: var(--assistant-muted);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.03em;
        }
        .assistant-message {
            padding: 14px 16px;
            border-radius: 18px;
            line-height: 1.6;
            font-size: 14px;
        }
        .assistant-message-content {
            display: grid;
            gap: 8px;
        }
        .assistant-message-content p,
        .assistant-message-content ul,
        .assistant-message-content ol {
            margin: 0;
        }
        .assistant-message-content ul,
        .assistant-message-content ol {
            padding-left: 18px;
        }
        .assistant-message-content li + li {
            margin-top: 4px;
        }
        .assistant-message-title {
            font-weight: 800;
            color: inherit;
        }
        .assistant-message.user {
            color: #fff;
            background: var(--assistant-user-bg);
            border-bottom-right-radius: 4px;
            white-space: pre-wrap;
            box-shadow: 0 14px 28px rgba(15, 108, 189, 0.18);
        }
        .assistant-message.bot {
            color: #24384c;
            background: var(--assistant-bot-bg);
            border: 1px solid rgba(203, 213, 225, 0.95);
            border-bottom-left-radius: 4px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }
        .assistant-message.pending {
            color: var(--assistant-muted);
            background: rgba(255, 255, 255, 0.72);
            border: 1px dashed rgba(148, 163, 184, 0.6);
            font-style: normal;
        }
        .assistant-typing {
            display: inline-flex;
            gap: 6px;
            align-items: center;
        }
        .assistant-typing span {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: rgba(95, 115, 135, 0.72);
            animation: assistantPulse 1s infinite ease-in-out;
        }
        .assistant-typing span:nth-child(2) {
            animation-delay: 0.15s;
        }
        .assistant-typing span:nth-child(3) {
            animation-delay: 0.3s;
        }
        .assistant-feedback {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }
        .assistant-feedback button {
            border: 1px solid #cfdae5;
            background: #ffffff;
            border-radius: 999px;
            padding: 7px 11px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            color: #42586f;
        }
        .assistant-feedback button.active {
            background: #eef7ff;
            border-color: var(--assistant-primary);
            color: var(--assistant-primary);
        }
        .assistant-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        .assistant-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 7px 12px;
            border-radius: 999px;
            background: #ffffff;
            border: 1px solid #c8d7e6;
            color: #1f5d9a;
            text-decoration: none;
            font-size: 11px;
            font-weight: 800;
            transition: transform 0.18s ease, background 0.18s ease, border-color 0.18s ease;
        }
        .assistant-action:hover {
            color: #1f5d9a;
            text-decoration: none;
            background: #eef5fb;
            border-color: #9bc1e6;
            transform: translateY(-1px);
        }
        .assistant-compose-wrap {
            margin-top: 14px;
            padding: 14px;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid rgba(206, 217, 231, 0.98);
            box-shadow: 0 14px 32px rgba(15, 23, 42, 0.06);
        }
        .assistant-starters {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 0 0 14px;
        }
        .assistant-starter {
            border: 1px solid rgba(183, 198, 214, 0.7);
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            color: #45607a;
            border-radius: 999px;
            padding: 10px 14px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.16s ease, border-color 0.16s ease, background 0.16s ease, color 0.16s ease;
        }
        .assistant-starter:hover {
            background: #edf4fb;
            border-color: #9dc2ea;
            color: var(--assistant-primary);
            transform: translateY(-1px);
        }
        .assistant-compose {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        .assistant-input-wrap {
            flex: 1;
            border-radius: 18px;
            padding: 12px 14px;
            background: #f8fafc;
            border: 1px solid rgba(201, 213, 227, 0.95);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.88);
        }
        .assistant-input-label {
            display: block;
            margin-bottom: 8px;
            color: var(--assistant-muted);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .assistant-input {
            width: 100%;
            min-height: 70px;
            max-height: 150px;
            border: none;
            padding: 0;
            font-size: 15px;
            line-height: 1.55;
            resize: vertical;
            background: transparent;
            color: var(--assistant-text);
            outline: none;
        }
        .assistant-input::placeholder {
            color: #7f91a4;
        }
        .assistant-send {
            border: none;
            border-radius: 18px;
            padding: 0 22px;
            min-width: 124px;
            min-height: 58px;
            background: linear-gradient(135deg, #0f6cbd 0%, #115d9a 100%);
            color: #fff;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 14px 28px rgba(15, 108, 189, 0.24);
            transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
        }
        .assistant-send:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 18px 34px rgba(15, 108, 189, 0.28);
        }
        .assistant-send:disabled {
            opacity: 0.65;
            cursor: not-allowed;
        }
        @keyframes assistantPulse {
            0%, 80%, 100% { transform: scale(0.85); opacity: 0.55; }
            40% { transform: scale(1); opacity: 1; }
        }
        @keyframes assistantFadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @media (max-width: 520px) {
            .assistant-shell {
                padding: 0;
            }
            .assistant-card {
                max-height: 100vh;
                border-radius: 0;
                border: none;
            }
            .assistant-hero {
                border-radius: 0 0 24px 24px;
                padding: 18px 16px;
            }
            .assistant-hero-top {
                flex-direction: column;
            }
            .assistant-hero-actions {
                width: 100%;
                justify-content: flex-start;
            }
            .assistant-hero-title {
                font-size: 26px;
            }
            .assistant-panel-head {
                align-items: flex-start;
            }
            .assistant-panel-tools {
                width: 100%;
                justify-content: flex-start;
            }
            .assistant-body {
                padding: 0 8px 8px;
            }
            .assistant-chat-panel {
                padding: 10px;
                border-radius: 18px;
            }
            .assistant-messages {
                min-height: 120px;
                padding: 12px 10px;
            }
            .assistant-message-wrap {
                max-width: 92%;
            }
            .assistant-compose-wrap {
                padding: 8px;
            }
            .assistant-starters {
                flex-wrap: nowrap;
                overflow-x: auto;
                padding-bottom: 2px;
                margin-bottom: 10px;
                scrollbar-width: thin;
            }
            .assistant-starter {
                flex: 0 0 auto;
                white-space: nowrap;
            }
            .assistant-input {
                min-height: 56px;
            }
            .assistant-send {
                min-height: 46px;
            }
        }
        @media (max-width: 430px) {
            .assistant-panel-head {
                flex-direction: column;
                align-items: stretch;
            }
            .assistant-body {
                padding: 0 6px 6px;
            }
            .assistant-chat-panel {
                padding: 10px;
                border-radius: 12px;
            }
            .assistant-compose-wrap {
                padding: 8px;
            }
            .assistant-starters {
                margin-bottom: 8px;
            }
            .assistant-panel-tools {
                width: 100%;
                justify-content: space-between;
            }
            .assistant-reset,
            .assistant-live-pill {
                flex: 1 1 auto;
                justify-content: center;
            }
            .assistant-compose {
                flex-direction: column;
            }
            .assistant-input-wrap,
            .assistant-send {
                width: 100%;
            }
            .assistant-input {
                min-height: 72px;
            }
        }
        body.assistant-embedded {
            background: #f3f6fb;
        }
        body.assistant-embedded .assistant-shell {
            padding: 0;
        }
        body.assistant-embedded .assistant-card {
            height: 100vh;
            max-height: 100vh;
            border: none;
            border-radius: 0;
            box-shadow: none;
            backdrop-filter: none;
        }
        body.assistant-embedded .assistant-body {
            padding: 0;
            gap: 0;
        }
        body.assistant-embedded .assistant-chat-panel {
            border-radius: 0;
            border: none;
            padding: 14px 14px 12px;
            background: linear-gradient(180deg, #fbfdff 0%, #f4f8fc 100%);
            backdrop-filter: none;
        }
        body.assistant-embedded .assistant-section-bar {
            align-items: flex-start;
            margin-bottom: 12px;
            padding: 0 0 10px;
            border-bottom: 1px solid rgba(214, 223, 233, 0.9);
        }
        body.assistant-embedded .assistant-panel-heading {
            font-size: 11px;
            letter-spacing: 0.1em;
        }
        body.assistant-embedded .assistant-panel-subcopy {
            margin-top: 6px;
            font-size: 12px;
            line-height: 1.5;
            max-width: 210px;
        }
        body.assistant-embedded .assistant-panel-tools {
            gap: 6px;
            justify-content: flex-end;
        }
        body.assistant-embedded .assistant-admin-link,
        body.assistant-embedded .assistant-reset,
        body.assistant-embedded .assistant-live-pill,
        body.assistant-embedded .assistant-context-chip-inline {
            min-height: 30px;
            padding: 0 10px;
            font-size: 11px;
            box-shadow: none;
        }
        body.assistant-embedded .assistant-live-pill {
            background: #ffffff;
        }
        body.assistant-embedded .assistant-reset {
            background: transparent;
        }
        body.assistant-embedded .assistant-messages {
            padding: 12px;
            border-radius: 16px;
            background: #ffffff;
            border-color: rgba(214, 223, 233, 0.92);
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.05);
        }
        body.assistant-embedded .assistant-compose-wrap {
            margin-top: 10px;
            padding: 10px 0 0;
            border-radius: 0;
            background: transparent;
            border: none;
            box-shadow: none;
        }
        body.assistant-embedded .assistant-starters {
            gap: 8px;
            margin-bottom: 10px;
            flex-wrap: nowrap;
            overflow-x: auto;
            padding-bottom: 2px;
            scrollbar-width: thin;
        }
        body.assistant-embedded .assistant-starter {
            flex: 0 0 auto;
            padding: 8px 11px;
            font-size: 11px;
            background: #ffffff;
        }
        body.assistant-embedded .assistant-input-wrap {
            border-radius: 16px;
            padding: 10px 12px;
            background: #ffffff;
        }
        body.assistant-embedded .assistant-input {
            min-height: 56px;
            font-size: 14px;
        }
        body.assistant-embedded .assistant-send {
            min-width: 96px;
            min-height: 52px;
            border-radius: 16px;
            padding: 0 16px;
            box-shadow: 0 10px 24px rgba(15, 108, 189, 0.18);
        }
        body.assistant-embedded .assistant-message-wrap {
            max-width: 88%;
        }
        body.assistant-embedded .assistant-message {
            padding: 12px 14px;
            border-radius: 16px;
            font-size: 13px;
        }
        body.assistant-embedded .assistant-bubble-avatar {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            border-radius: 12px;
        }
        body.assistant-embedded .assistant-bubble-meta {
            font-size: 10px;
        }
    </style>
</head>
<body class="<?php echo attr($isEmbedded ? 'assistant-embedded' : 'assistant-standalone'); ?>">
<div class="assistant-shell">
    <div class="assistant-card">
        <div class="assistant-body">
            <?php if (!$isEmbedded) { ?>
                <div class="assistant-hero">
                    <div class="assistant-hero-top">
                        <div class="assistant-hero-brand">
                            <div class="assistant-hero-avatar">J</div>
                            <div class="assistant-hero-copy">
                                <div class="assistant-hero-eyebrow"><?php echo text($mode === 'patient' ? xl('Patient Support') : xl('Workflow Copilot')); ?></div>
                                <div class="assistant-hero-title"><?php echo text($pageTitle); ?></div>
                                <div class="assistant-hero-subtitle"><?php echo text($subtitle); ?></div>
                            </div>
                        </div>
                        <div class="assistant-hero-actions">
                            <?php if ($isAdminUser && $mode === 'staff') { ?>
                                <a class="assistant-admin-link" href="<?php echo attr($knowledgeAdminUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo text(xl('Knowledge Admin')); ?></a>
                            <?php } ?>
                            <button type="button" class="assistant-close assistant-floating-close" id="assistant-close" aria-label="<?php echo attr(xl('Close assistant')); ?>">&times;</button>
                        </div>
                    </div>
                    <div class="assistant-context-row">
                        <div class="assistant-context-chip"><strong><?php echo text(xl('Area')); ?>:</strong> <?php echo text(assistantContextLabel($context['area'])); ?></div>
                        <?php if (!empty($context['patient_id'])) { ?>
                            <div class="assistant-context-chip"><strong><?php echo text(xl('Patient')); ?>:</strong> <?php echo text((string)$context['patient_id']); ?></div>
                        <?php } ?>
                        <?php if (!empty($context['report_name'])) { ?>
                            <div class="assistant-context-chip"><strong><?php echo text(xl('Report')); ?>:</strong> <?php echo text((string)$context['report_name']); ?></div>
                        <?php } ?>
                        <?php if (!empty($context['pos_state'])) { ?>
                            <div class="assistant-context-chip"><strong><?php echo text(xl('POS')); ?>:</strong> <?php echo text((string)$context['pos_state']); ?></div>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>
            <div class="assistant-chat-panel">
                <?php if (!$isEmbedded) { ?>
                    <div class="assistant-section-bar">
                        <div class="assistant-section-copy">
                            <div class="assistant-panel-heading"><?php echo text(xl('Live Conversation')); ?></div>
                            <div class="assistant-panel-subcopy"><?php echo text(xl('Ask questions, continue workflows, and keep the current session moving.')); ?></div>
                        </div>
                        <div class="assistant-panel-tools">
                            <div class="assistant-live-pill"><?php echo text(xl('jacki online')); ?></div>
                            <button type="button" class="assistant-reset" id="assistant-reset"><?php echo text(xl('New Chat')); ?></button>
                        </div>
                    </div>
                <?php } ?>
                <div id="assistant-messages" class="assistant-messages">
                    <div class="assistant-day-label"><span><?php echo text(xl('Current Session')); ?></span></div>
                </div>
                <div class="assistant-compose-wrap">
                    <div class="assistant-panel-heading"><?php echo text(xl('Quick Prompts')); ?></div>
                    <div class="assistant-starters">
                        <?php foreach ($starterQuestions as $question) { ?>
                            <button type="button" class="assistant-starter" data-question="<?php echo attr($question); ?>"><?php echo text($question); ?></button>
                        <?php } ?>
                    </div>
                    <div class="assistant-compose">
                        <div class="assistant-input-wrap">
                            <label class="assistant-input-label" for="assistant-input"><?php echo text(xl('Message jacki')); ?></label>
                            <textarea id="assistant-input" class="assistant-input" placeholder="<?php echo attr(xl('Ask jacki for help...')); ?>"></textarea>
                        </div>
                        <button id="assistant-send" class="assistant-send" type="button"><?php echo text(xl('Send')); ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
    window.assistantConfig = {
        apiUrl: <?php echo json_encode($apiUrl); ?>,
        feedbackUrl: <?php echo json_encode($feedbackUrl); ?>,
        csrfToken: <?php echo json_encode($csrf); ?>,
        mode: <?php echo json_encode($mode); ?>,
        storageKey: <?php echo json_encode('ava_chat_' . $mode); ?>,
        context: <?php echo json_encode($context); ?>
    };

    function escapeAssistantHtml(text) {
        return String(text || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderAssistantMessageHtml(text, role) {
        var safeText = String(text || '').replace(/\r/g, '');
        if (role === 'user') {
            return escapeAssistantHtml(safeText);
        }

        var lines = safeText.split('\n');
        var html = [];
        var listItems = [];
        var listType = '';

        function flushList() {
            if (!listItems.length) {
                return;
            }

            html.push('<' + listType + '>' + listItems.join('') + '</' + listType + '>');
            listItems = [];
            listType = '';
        }

        lines.forEach(function (line, index) {
            var trimmed = line.trim();
            if (!trimmed) {
                flushList();
                return;
            }

            var bulletMatch = trimmed.match(/^[-*]\s+(.*)$/);
            var numberMatch = trimmed.match(/^\d+\.\s+(.*)$/);
            if (bulletMatch) {
                if (listType && listType !== 'ul') {
                    flushList();
                }
                listType = 'ul';
                listItems.push('<li>' + escapeAssistantHtml(bulletMatch[1]) + '</li>');
                return;
            }

            if (numberMatch) {
                if (listType && listType !== 'ol') {
                    flushList();
                }
                listType = 'ol';
                listItems.push('<li>' + escapeAssistantHtml(numberMatch[1]) + '</li>');
                return;
            }

            flushList();
            if (index === 0 && lines.length > 1) {
                html.push('<div class="assistant-message-title">' + escapeAssistantHtml(trimmed) + '</div>');
            } else {
                html.push('<p>' + escapeAssistantHtml(trimmed) + '</p>');
            }
        });

        flushList();

        return '<div class="assistant-message-content">' + html.join('') + '</div>';
    }

    function getAssistantHistoryPayload() {
        var items = [];
        var container = document.getElementById('assistant-messages');
        Array.prototype.forEach.call(container.querySelectorAll('.assistant-row[data-role]'), function (row) {
            var role = row.getAttribute('data-role');
            var content = row.getAttribute('data-raw-text') || '';
            if (!role || !content) {
                return;
            }
            items.push({
                role: role === 'bot' ? 'assistant' : 'user',
                content: content
            });
        });

        if (items.length > 8) {
            items = items.slice(-8);
        }

        return items;
    }

    function saveAssistantConversation() {
        var snapshot = [];
        var container = document.getElementById('assistant-messages');

        Array.prototype.forEach.call(container.querySelectorAll('.assistant-row[data-role]'), function (row) {
            snapshot.push({
                role: row.getAttribute('data-role'),
                text: row.getAttribute('data-raw-text') || ''
            });
        });

        try {
            window.sessionStorage.setItem(window.assistantConfig.storageKey, JSON.stringify(snapshot));
        } catch (error) {
        }
    }

    function restoreAssistantConversation() {
        try {
            var raw = window.sessionStorage.getItem(window.assistantConfig.storageKey);
            if (!raw) {
                return false;
            }

            var items = JSON.parse(raw);
            if (!Array.isArray(items) || !items.length) {
                return false;
            }

            items.forEach(function (item) {
                if (!item || !item.role || !item.text) {
                    return;
                }
                appendAssistantMessage(item.role, item.text, null, true);
            });

            return true;
        } catch (error) {
            return false;
        }
    }

    function syncAssistantInputHeight() {
        var input = document.getElementById('assistant-input');
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 140) + 'px';
    }

    function setAssistantBusy(isBusy) {
        var sendButton = document.getElementById('assistant-send');
        var input = document.getElementById('assistant-input');
        sendButton.disabled = isBusy;
        input.disabled = isBusy;
    }

    function getAssistantTimestamp() {
        return new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    function appendAssistantMessage(role, text, meta, skipSave) {
        var container = document.getElementById('assistant-messages');
        var emptyHint = document.getElementById('assistant-empty-hint');
        if (emptyHint) {
            emptyHint.parentNode.removeChild(emptyHint);
        }

        var row = document.createElement('div');
        row.className = 'assistant-row ' + role;
        row.setAttribute('data-role', role);
        row.setAttribute('data-raw-text', text);

        var avatar = document.createElement('div');
        avatar.className = 'assistant-bubble-avatar';
        avatar.textContent = role === 'bot' ? 'J' : 'You';

        var wrap = document.createElement('div');
        wrap.className = 'assistant-message-wrap';

        var bubbleMeta = document.createElement('div');
        bubbleMeta.className = 'assistant-bubble-meta';
        bubbleMeta.textContent = role === 'bot' ? 'jacki • ' + getAssistantTimestamp() : 'You • ' + getAssistantTimestamp();

        var node = document.createElement('div');
        node.className = 'assistant-message ' + role;
        if (role === 'bot') {
            node.innerHTML = renderAssistantMessageHtml(text, role);
        } else {
            node.textContent = text;
        }
        wrap.appendChild(bubbleMeta);
        wrap.appendChild(node);

        if (role === 'bot' && meta && Array.isArray(meta.actions) && meta.actions.length) {
            var actionWrap = document.createElement('div');
            actionWrap.className = 'assistant-actions';

            meta.actions.forEach(function (action) {
                if (!action || !action.url || !action.label) {
                    return;
                }
                var link = document.createElement('a');
                link.className = 'assistant-action';
                link.href = action.url;
                link.addEventListener('click', function (event) {
                    event.preventDefault();
                    var targetUrl = action.url;

                    try {
                        if (window.top && window.top !== window && window.top.location) {
                            window.top.location.href = targetUrl;
                            return;
                        }
                    } catch (error) {
                    }

                    window.location.href = targetUrl;
                });
                link.textContent = action.label;
                actionWrap.appendChild(link);
            });

            if (actionWrap.children.length) {
                wrap.appendChild(actionWrap);
            }
        }

        if (role === 'bot' && meta && meta.logId && window.assistantConfig.mode === 'staff') {
            var feedback = document.createElement('div');
            feedback.className = 'assistant-feedback';

            var helpfulButton = document.createElement('button');
            helpfulButton.type = 'button';
            helpfulButton.textContent = 'Helpful';
            helpfulButton.addEventListener('click', function () {
                sendAssistantFeedback(meta.logId, 1, helpfulButton, notHelpfulButton);
            });

            var notHelpfulButton = document.createElement('button');
            notHelpfulButton.type = 'button';
            notHelpfulButton.textContent = 'Not helpful';
            notHelpfulButton.addEventListener('click', function () {
                sendAssistantFeedback(meta.logId, -1, notHelpfulButton, helpfulButton);
            });

            feedback.appendChild(helpfulButton);
            feedback.appendChild(notHelpfulButton);
            wrap.appendChild(feedback);
        }

        if (role === 'user') {
            row.appendChild(wrap);
            row.appendChild(avatar);
        } else {
            row.appendChild(avatar);
            row.appendChild(wrap);
        }

        container.appendChild(row);
        container.scrollTop = container.scrollHeight;

        if (!skipSave) {
            saveAssistantConversation();
        }

        return row;
    }

    function updateAssistantMessage(row, role, text, meta, saveNow) {
        if (!row) {
            return;
        }

        row.setAttribute('data-raw-text', text);
        var messageNode = row.querySelector('.assistant-message');
        if (messageNode) {
            if (role === 'bot') {
                messageNode.innerHTML = renderAssistantMessageHtml(text, role);
            } else {
                messageNode.textContent = text;
            }
        }

        if (saveNow) {
            saveAssistantConversation();
        }
    }

    function finalizeAssistantMessage(row, role, text, meta) {
        if (!row) {
            return;
        }

        if (meta && (meta.actions || meta.logId)) {
            if (row.parentNode) {
                row.parentNode.removeChild(row);
            }
            appendAssistantMessage(role, text, meta);
            return;
        }

        updateAssistantMessage(row, role, text, meta, true);
    }

    function appendPendingAssistantMessage() {
        var container = document.getElementById('assistant-messages');
        var row = document.createElement('div');
        row.className = 'assistant-row bot';
        row.id = 'assistant-pending-message';

        var avatar = document.createElement('div');
        avatar.className = 'assistant-bubble-avatar';
        avatar.textContent = 'J';

        var wrap = document.createElement('div');
        wrap.className = 'assistant-message-wrap';

        var bubbleMeta = document.createElement('div');
        bubbleMeta.className = 'assistant-bubble-meta';
        bubbleMeta.textContent = 'jacki • ' + getAssistantTimestamp();

        var node = document.createElement('div');
        node.className = 'assistant-message bot pending';
        node.innerHTML = '<span class="assistant-typing"><span></span><span></span><span></span></span>';

        wrap.appendChild(bubbleMeta);
        wrap.appendChild(node);
        row.appendChild(avatar);
        row.appendChild(wrap);
        container.appendChild(row);
        container.scrollTop = container.scrollHeight;
    }

    function removePendingAssistantMessage() {
        var node = document.getElementById('assistant-pending-message');
        if (node && node.parentNode) {
            node.parentNode.removeChild(node);
        }
    }

    function getAssistantStreamDelay(textLength) {
        if (textLength > 900) {
            return 4;
        }
        if (textLength > 400) {
            return 7;
        }
        return 11;
    }

    function getAssistantChunkSize(textLength) {
        if (textLength > 900) {
            return 18;
        }
        if (textLength > 400) {
            return 12;
        }
        return 7;
    }

    function streamAssistantReply(text, meta) {
        return new Promise(function (resolve) {
            var fullText = String(text || '');
            var row = appendAssistantMessage('bot', '', null, true);
            var index = 0;
            var chunkSize = getAssistantChunkSize(fullText.length);
            var delay = getAssistantStreamDelay(fullText.length);

            function step() {
                index = Math.min(index + chunkSize, fullText.length);
                updateAssistantMessage(row, 'bot', fullText.slice(0, index), null, false);

                var container = document.getElementById('assistant-messages');
                container.scrollTop = container.scrollHeight;

                if (index < fullText.length) {
                    window.setTimeout(step, delay);
                    return;
                }

                finalizeAssistantMessage(row, 'bot', fullText, meta);
                resolve();
            }

            if (!fullText) {
                updateAssistantMessage(row, 'bot', '', meta, true);
                resolve();
                return;
            }

            step();
        });
    }

    async function streamAssistantReplyFromResponse(response) {
        if (!response.body || typeof response.body.getReader !== 'function') {
            return false;
        }

        var reader = response.body.getReader();
        var decoder = new TextDecoder();
        var buffer = '';
        var fullText = '';
        var row = null;
        var finalized = false;

        function ensureRow() {
            if (!row) {
                row = appendAssistantMessage('bot', '', null, true);
            }
            return row;
        }

        while (true) {
            var readResult = await reader.read();
            if (readResult.done) {
                break;
            }

            buffer += decoder.decode(readResult.value, { stream: true });
            var lines = buffer.split('\n');
            buffer = lines.pop() || '';

            for (var i = 0; i < lines.length; i++) {
                var line = lines[i].trim();
                if (!line) {
                    continue;
                }

                var eventData;
                try {
                    eventData = JSON.parse(line);
                } catch (error) {
                    continue;
                }

                if (eventData.type === 'chunk') {
                    removePendingAssistantMessage();
                    fullText += String(eventData.delta || '');
                    updateAssistantMessage(ensureRow(), 'bot', fullText, null, false);
                    continue;
                }

                if (eventData.type === 'done') {
                    removePendingAssistantMessage();
                    fullText = String(eventData.reply || fullText);

                    if (!eventData.success) {
                        finalizeAssistantMessage(ensureRow(), 'bot', eventData.error || 'Unable to answer right now.');
                    } else {
                        finalizeAssistantMessage(ensureRow(), 'bot', fullText, {
                            logId: eventData.log_id || 0,
                            source: eventData.reply_source || '',
                            actions: eventData.actions || []
                        });
                    }

                    finalized = true;
                }
            }
        }

        if (!finalized) {
            removePendingAssistantMessage();
            finalizeAssistantMessage(ensureRow(), 'bot', fullText || 'Unable to answer right now.');
        }

        return true;
    }

    function renderAssistantWelcomeState() {
        var container = document.getElementById('assistant-messages');
        container.innerHTML = '<div class="assistant-day-label"><span><?php echo text(xl('Current Session')); ?></span></div>';

        var hint = document.createElement('div');
        hint.className = 'assistant-empty-hint';
        hint.id = 'assistant-empty-hint';
        hint.innerHTML =
            '<div class="assistant-empty-title">' + (window.assistantConfig.mode === 'patient' ? 'Ask anything about support' : 'Ask jacki what you need') + '</div>' +
            '<div class="assistant-empty-copy">' + (window.assistantConfig.mode === 'patient'
                ? 'I can help with appointments, billing contact, and portal guidance.'
                : 'I can help with ' + String((window.assistantConfig.context && window.assistantConfig.context.label) || 'OpenEMR') + ' workflow questions, plus patients, POS, inventory, scheduling, and reports.' +
                    ((window.assistantConfig.context && window.assistantConfig.context.patient_id)
                        ? ' Current patient: ' + window.assistantConfig.context.patient_id + '.'
                        : '') +
                    ((window.assistantConfig.context && window.assistantConfig.context.report_name)
                        ? ' Current report: ' + window.assistantConfig.context.report_name + '.'
                        : '') +
                    ((window.assistantConfig.context && window.assistantConfig.context.pos_state)
                        ? ' POS focus: ' + window.assistantConfig.context.pos_state + '.'
                        : '')) + '</div>';
        container.appendChild(hint);

        appendAssistantMessage(
            'bot',
            window.assistantConfig.mode === 'patient'
                ? 'Hello. I can help with general support topics like appointments, billing contact, and portal guidance.'
                : 'Hello. I am your internal OpenEMR workflow assistant. I can see you opened me from ' + String((window.assistantConfig.context && window.assistantConfig.context.label) || 'OpenEMR') + '.' +
                    ((window.assistantConfig.context && window.assistantConfig.context.patient_id)
                        ? ' I also see patient ' + window.assistantConfig.context.patient_id + ' in context.'
                        : '') +
                    ((window.assistantConfig.context && window.assistantConfig.context.report_name)
                        ? ' Active report: ' + window.assistantConfig.context.report_name + '.'
                        : '') +
                    ((window.assistantConfig.context && window.assistantConfig.context.pos_state)
                        ? ' Current POS focus: ' + window.assistantConfig.context.pos_state + '.'
                        : '') +
                    ' Ask me anything about this workflow or related system questions.'
        );
    }

    function resetAssistantConversation() {
        try {
            window.sessionStorage.removeItem(window.assistantConfig.storageKey);
        } catch (error) {
        }

        var input = document.getElementById('assistant-input');
        renderAssistantWelcomeState();
        if (input) {
            input.value = '';
        }
        syncAssistantInputHeight();
        if (input) {
            input.focus();
        }
    }

    async function sendAssistantFeedback(logId, feedbackValue, activeButton, inactiveButton) {
        try {
            await fetch(window.assistantConfig.feedbackUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    log_id: logId,
                    feedback: feedbackValue,
                    csrf_token_form: window.assistantConfig.csrfToken
                })
            });

            activeButton.classList.add('active');
            inactiveButton.classList.remove('active');
        } catch (error) {
        }
    }

    async function sendAssistantMessage(message) {
        if (!message) {
            return;
        }

        setAssistantBusy(true);
        appendAssistantMessage('user', message);
        appendPendingAssistantMessage();

        try {
            var response = await fetch(window.assistantConfig.apiUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    message: message,
                    history: getAssistantHistoryPayload(),
                    context: window.assistantConfig.context,
                    stream: true,
                    csrf_token_form: window.assistantConfig.csrfToken
                })
            });

            var contentType = (response.headers.get('Content-Type') || '').toLowerCase();
            if (contentType.indexOf('application/x-ndjson') !== -1) {
                await streamAssistantReplyFromResponse(response);
            } else {
                var data = await response.json();
                removePendingAssistantMessage();
                if (!response.ok || !data.success) {
                    await streamAssistantReply((data && data.error) ? data.error : 'Unable to answer right now.');
                } else {
                    await streamAssistantReply(data.reply || '', {
                        logId: data.log_id || 0,
                        source: data.reply_source || '',
                        actions: data.actions || []
                    });
                }
            }
        } catch (error) {
            removePendingAssistantMessage();
            await streamAssistantReply('Unable to answer right now.');
        } finally {
            setAssistantBusy(false);
            syncAssistantInputHeight();
            var input = document.getElementById('assistant-input');
            if (input) {
                input.focus();
            }
        }
    }

    var assistantSendButton = document.getElementById('assistant-send');
    var assistantInput = document.getElementById('assistant-input');
    var assistantResetButton = document.getElementById('assistant-reset');

    if (assistantSendButton && assistantInput) {
        assistantSendButton.addEventListener('click', function () {
            var message = assistantInput.value.trim();
            assistantInput.value = '';
            syncAssistantInputHeight();
            sendAssistantMessage(message);
        });

        assistantInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                assistantSendButton.click();
            }
        });

        assistantInput.addEventListener('input', syncAssistantInputHeight);
    }

    Array.prototype.forEach.call(document.querySelectorAll('.assistant-starter'), function (button) {
        button.addEventListener('click', function () {
            sendAssistantMessage(button.getAttribute('data-question') || '');
        });
    });

    if (assistantResetButton) {
        assistantResetButton.addEventListener('click', function () {
            resetAssistantConversation();
        });
    }

    var closeButton = document.getElementById('assistant-close');
    if (closeButton) {
        closeButton.addEventListener('click', function () {
            if (window.top && typeof window.top.closeDialog === 'function') {
                window.top.closeDialog();
                return;
            }
            if (window.parent && window.parent !== window && typeof window.parent.closeDialog === 'function') {
                window.parent.closeDialog();
                return;
            }
            window.history.back();
        });
    }

    if (!restoreAssistantConversation()) {
        renderAssistantWelcomeState();
    }

    syncAssistantInputHeight();
</script>
</body>
</html>
