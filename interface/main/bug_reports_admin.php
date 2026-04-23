<?php

/**
 * Internal bug reporting / ticket page.
 *
 * @package OpenEMR
 */

$ignoreAuth = false;
require_once(__DIR__ . "/../globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

if (empty($_SESSION['authUserID']) || !AclMain::aclCheckCore('admin', 'super')) {
    die(xlt('Not authorized'));
}

bugReportsEnsureTables();

$bugReportUploadDir = $GLOBALS['OE_SITE_DIR'] . '/documents/bug_reports';
$priorityOptions = ['Low', 'Medium', 'High', 'Critical'];
$statusOptions = ['Open', 'In Progress', 'Resolved', 'Closed'];
$issueTypeOptions = ['Bug', 'Task', 'Improvement'];
$formValues = [
    'title' => '',
    'priority' => 'Medium',
    'issue_type' => 'Bug',
    'module_page' => '',
    'environment' => '',
    'description' => '',
];

if (isset($_GET['download'])) {
    $ticketId = (int)($_GET['download'] ?? 0);
    if ($ticketId > 0) {
        $ticketRow = sqlQuery(
            "SELECT screenshot_path, title
             FROM bug_reports
             WHERE id = ?",
            [$ticketId]
        );
        if (!is_array($ticketRow)) {
            $ticketRow = [];
        }

        $relativePath = (string)($ticketRow['screenshot_path'] ?? '');
        $fullPath = $relativePath !== '' ? $bugReportUploadDir . '/' . basename($relativePath) : '';
        if ($relativePath !== '' && is_file($fullPath)) {
            $downloadName = basename($relativePath);
            $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';
            header('Pragma: public');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . (string)filesize($fullPath));
            header('Content-Disposition: attachment; filename="' . addslashes($downloadName) . '"');
            readfile($fullPath);
            exit;
        }
    }

    die(xlt('File not found'));
}

$errors = [];
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        die(xlt('CSRF token validation failed'));
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_ticket') {
        $title = trim((string)($_POST['title'] ?? ''));
        $priority = in_array((string)($_POST['priority'] ?? ''), $priorityOptions, true) ? (string)$_POST['priority'] : 'Medium';
        $issueType = in_array((string)($_POST['issue_type'] ?? ''), $issueTypeOptions, true) ? (string)$_POST['issue_type'] : 'Bug';
        $modulePage = trim((string)($_POST['module_page'] ?? ''));
        $environment = trim((string)($_POST['environment'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $screenshotPath = '';
        $formValues = [
            'title' => $title,
            'priority' => $priority,
            'issue_type' => $issueType,
            'module_page' => $modulePage,
            'environment' => $environment,
            'description' => $description,
        ];

        if ($title === '') {
            $errors[] = xlt('Title is required.');
        }
        if ($modulePage === '') {
            $errors[] = xlt('Module/Page is required.');
        }
        if ($description === '') {
            $errors[] = xlt('Description is required.');
        }

        if (empty($_FILES['screenshot']['tmp_name']) || !is_uploaded_file($_FILES['screenshot']['tmp_name'])) {
            $errors[] = xlt('Screenshot is required.');
        } else {
            $originalName = (string)($_FILES['screenshot']['name'] ?? '');
            $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
            $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf'];
            if (!in_array($extension, $allowedExtensions, true)) {
                $errors[] = xlt('Accepted screenshot formats are PNG, JPG, JPEG, GIF, WEBP, and PDF.');
            } else {
                if (!is_dir($bugReportUploadDir) && !mkdir($concurrentDirectory = $bugReportUploadDir, 0755, true) && !is_dir($concurrentDirectory)) {
                    $errors[] = xlt('Unable to create the screenshot directory.');
                } else {
                    $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
                    $safeBase = $safeBase !== '' ? $safeBase : 'bug_report';
                    $storedFilename = 'bug_report_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeBase . '.' . $extension;
                    $destination = $bugReportUploadDir . '/' . $storedFilename;
                    if (!move_uploaded_file($_FILES['screenshot']['tmp_name'], $destination)) {
                        $errors[] = xlt('Unable to save the screenshot.');
                    } else {
                        $screenshotPath = $storedFilename;
                    }
                }
            }
        }

        if (empty($errors)) {
            $newId = (int)sqlInsert(
                "INSERT INTO bug_reports
                (title, priority, issue_type, module_page, environment, description, screenshot_path, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'Open', ?)",
                [$title, $priority, $issueType, $modulePage, $environment, $description, $screenshotPath, (int)$_SESSION['authUserID']]
            );

            sqlInsert(
                "INSERT INTO bug_report_logs
                (report_id, message, created_by)
                VALUES (?, ?, ?)",
                [$newId, sprintf(xl('%s created with priority %s'), $issueType, $priority), (int)$_SESSION['authUserID']]
            );

            header('Location: bug_reports_admin.php?ticket_id=' . urlencode((string)$newId) . '&created=1');
            exit;
        }
    } elseif ($action === 'add_log') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $message = trim((string)($_POST['message'] ?? ''));
        if ($ticketId <= 0 || $message === '') {
            $errors[] = xlt('A log message is required.');
        } else {
            sqlInsert(
                "INSERT INTO bug_report_logs
                (report_id, message, created_by)
                VALUES (?, ?, ?)",
                [$ticketId, $message, (int)$_SESSION['authUserID']]
            );
            header('Location: bug_reports_admin.php?ticket_id=' . urlencode((string)$ticketId) . '&logged=1');
            exit;
        }
    } elseif ($action === 'update_status') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $status = in_array((string)($_POST['status'] ?? ''), $statusOptions, true) ? (string)$_POST['status'] : 'Open';
        if ($ticketId > 0) {
            sqlStatement(
                "UPDATE bug_reports
                 SET status = ?
                 WHERE id = ?",
                [$status, $ticketId]
            );
            sqlInsert(
                "INSERT INTO bug_report_logs
                (report_id, message, created_by)
                VALUES (?, ?, ?)",
                [$ticketId, sprintf(xl('Status changed to %s'), $status), (int)$_SESSION['authUserID']]
            );
            header('Location: bug_reports_admin.php?ticket_id=' . urlencode((string)$ticketId) . '&updated=1');
            exit;
        }
    }
}

$filterStatus = in_array((string)($_GET['status'] ?? ''), array_merge(['All'], $statusOptions), true) ? (string)($_GET['status'] ?? 'All') : 'All';
$searchTerm = trim((string)($_GET['q'] ?? ''));

if (isset($_GET['created'])) {
    $flash = xlt('Bug report created.');
} elseif (isset($_GET['logged'])) {
    $flash = xlt('Log added.');
} elseif (isset($_GET['updated'])) {
    $flash = xlt('Status updated.');
}

$tickets = [];
$ticketQuery = "SELECT br.id, br.title, br.priority, br.issue_type, br.module_page, br.environment, br.status, br.created_at, br.updated_at,
            COALESCE(NULLIF(CONCAT(TRIM(u.fname), ' ', TRIM(u.lname)), ' '), u.username, '') AS created_by_name
     FROM bug_reports br
     LEFT JOIN users u ON u.id = br.created_by
     WHERE 1 = 1";
$ticketBind = [];
if ($filterStatus !== 'All') {
    $ticketQuery .= " AND br.status = ?";
    $ticketBind[] = $filterStatus;
}
if ($searchTerm !== '') {
    $ticketQuery .= " AND (
        br.title LIKE ?
        OR br.module_page LIKE ?
        OR br.description LIKE ?
        OR br.environment LIKE ?
        OR CAST(br.id AS CHAR) LIKE ?
    )";
    $searchLike = '%' . $searchTerm . '%';
    $ticketBind[] = $searchLike;
    $ticketBind[] = $searchLike;
    $ticketBind[] = $searchLike;
    $ticketBind[] = $searchLike;
    $ticketBind[] = $searchLike;
}
$ticketQuery .= " ORDER BY
        CASE br.status
            WHEN 'Open' THEN 0
            WHEN 'In Progress' THEN 1
            WHEN 'Resolved' THEN 2
            ELSE 3
        END,
        br.updated_at DESC,
        br.id DESC";
$ticketStmt = sqlStatement($ticketQuery, $ticketBind);
while ($row = sqlFetchArray($ticketStmt)) {
    if (is_array($row)) {
        $tickets[] = $row;
    }
}

$ticketSummary = bugReportsGetSummaryCounts();

$selectedTicketId = (int)($_GET['ticket_id'] ?? ($tickets[0]['id'] ?? 0));
$selectedTicket = null;
if ($selectedTicketId > 0) {
    $selectedTicket = sqlQuery(
        "SELECT br.*,
                COALESCE(NULLIF(CONCAT(TRIM(u.fname), ' ', TRIM(u.lname)), ' '), u.username, '') AS created_by_name
         FROM bug_reports br
         LEFT JOIN users u ON u.id = br.created_by
         WHERE br.id = ?",
        [$selectedTicketId]
    );
    if (!is_array($selectedTicket)) {
        $selectedTicket = null;
    }
}

$logs = [];
if (!empty($selectedTicket['id'])) {
    $logStmt = sqlStatement(
        "SELECT l.id, l.message, l.created_at,
                COALESCE(NULLIF(CONCAT(TRIM(u.fname), ' ', TRIM(u.lname)), ' '), u.username, '') AS created_by_name
         FROM bug_report_logs l
         LEFT JOIN users u ON u.id = l.created_by
         WHERE l.report_id = ?
         ORDER BY l.id ASC",
        [(int)$selectedTicket['id']]
    );
    while ($row = sqlFetchArray($logStmt)) {
        if (is_array($row)) {
            $logs[] = $row;
        }
    }
}

$nextTicketNumber = bugReportsGetNextTicketNumber();
$selectedTicketKey = !empty($selectedTicket['id']) ? bugReportsGetTicketKey((int)$selectedTicket['id']) : '';
?>
<!doctype html>
<html>
<head>
    <title><?php echo xlt('Bug Reports'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <style>
        :root {
            --bg: linear-gradient(180deg, #f3f6fb 0%, #eef4ff 100%);
            --card: #ffffff;
            --line: #d8e1ee;
            --text: #152033;
            --muted: #5f6f86;
            --accent: #0b63ce;
            --accent-soft: #eaf3ff;
            --success-soft: #dcfce7;
            --danger-soft: #fee2e2;
        }
        body { font-family: Arial, sans-serif; background: var(--bg); color: var(--text); margin: 0; }
        .wrap { max-width: 1360px; margin: 24px auto; padding: 0 20px 32px; }
        .page-title { margin: 0 0 8px; font-size: 30px; }
        .muted { color: var(--muted); font-size: 13px; }
        .notice, .error-box { border-radius: 14px; padding: 14px 16px; margin-bottom: 16px; }
        .notice { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .error-box { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .hero { display: flex; justify-content: space-between; gap: 18px; align-items: flex-end; margin-bottom: 18px; }
        .hero-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
        .summary-card { background: rgba(255, 255, 255, 0.88); border: 1px solid var(--line); border-radius: 18px; padding: 16px 18px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05); }
        .summary-card strong { display: block; font-size: 26px; margin-top: 4px; }
        .layout { display: grid; grid-template-columns: 420px 1fr; gap: 20px; align-items: start; }
        .stack { display: grid; gap: 20px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 20px; padding: 20px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04); }
        .card-title-row { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 14px; }
        .field { margin-bottom: 14px; }
        label { display: block; font-weight: 700; margin-bottom: 6px; }
        input[type="text"], select, textarea, input[type="file"] {
            width: 100%;
            border: 1px solid #cdd7e3;
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 14px;
            background: #fff;
            box-sizing: border-box;
        }
        input[readonly] { background: #f3f7fc; color: #42536a; }
        textarea { min-height: 140px; resize: vertical; }
        .field-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .btn {
            border: none;
            border-radius: 12px;
            padding: 10px 16px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-secondary { background: #e5e7eb; color: #111827; }
        .btn-link { background: transparent; color: var(--accent); border: 1px solid var(--line); }
        .queue-toolbar { display: grid; gap: 12px; margin-bottom: 14px; }
        .filter-row { display: grid; grid-template-columns: 1fr 160px; gap: 10px; }
        .ticket-list { display: grid; gap: 12px; }
        .ticket-item {
            display: block;
            text-decoration: none;
            color: inherit;
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 14px;
            background: #f8fbff;
        }
        .ticket-item.active { border-color: var(--accent); box-shadow: inset 0 0 0 1px var(--accent); background: var(--accent-soft); }
        .ticket-topline { display: flex; justify-content: space-between; gap: 10px; align-items: center; margin-bottom: 6px; }
        .ticket-key { font-size: 12px; font-weight: 700; letter-spacing: 0.06em; color: var(--accent); text-transform: uppercase; }
        .ticket-title { font-weight: 700; margin-bottom: 6px; font-size: 15px; }
        .ticket-meta { font-size: 13px; color: var(--muted); display: grid; gap: 4px; }
        .pill {
            display: inline-block;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 700;
        }
        .type-pill { background: #ecfeff; color: #155e75; }
        .priority-Low { background: #e0f2fe; color: #075985; }
        .priority-Medium { background: #fef3c7; color: #92400e; }
        .priority-High { background: #fde68a; color: #92400e; }
        .priority-Critical { background: #fee2e2; color: #991b1b; }
        .status-Open { background: #dbeafe; color: #1d4ed8; }
        .status-In-Progress { background: #fef3c7; color: #92400e; }
        .status-Resolved { background: #dcfce7; color: #166534; }
        .status-Closed { background: #e5e7eb; color: #374151; }
        .detail-hero { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; margin-bottom: 16px; }
        .detail-key { display: inline-flex; align-items: center; gap: 8px; color: var(--accent); font-weight: 700; margin-bottom: 6px; }
        .detail-grid { display: grid; grid-template-columns: 1fr auto; gap: 16px; align-items: start; }
        .detail-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin: 16px 0 20px; }
        .detail-box { background: #f8fbff; border: 1px solid #dbe3ec; border-radius: 14px; padding: 12px 14px; }
        .detail-box.full { grid-column: 1 / -1; }
        .detail-label { font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 6px; }
        .section-title { margin: 0 0 12px; font-size: 18px; }
        .log-list { display: grid; gap: 12px; margin-bottom: 18px; }
        .log-item { border: 1px solid #e5e7eb; border-radius: 14px; padding: 14px 16px; background: #fff; }
        .log-head { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 8px; font-size: 13px; color: var(--muted); }
        .empty { color: var(--muted); font-style: italic; }
        .status-form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .detail-layout { display: grid; gap: 20px; }
        .subtle-divider { height: 1px; background: var(--line); margin: 18px 0; }
        @media (max-width: 1100px) {
            .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .layout { grid-template-columns: 1fr; }
            .detail-fields { grid-template-columns: 1fr; }
            .field-grid, .filter-row { grid-template-columns: 1fr; }
            .detail-hero, .hero { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <div>
            <h1 class="page-title"><?php echo xlt('Bug Tracker'); ?></h1>
            <div class="muted"><?php echo xlt('A streamlined Jira-style queue for capturing issues, tracking status, and keeping the team aligned.'); ?></div>
        </div>
        <div class="hero-actions">
            <a class="btn btn-link" href="bug_reports_admin.php"><?php echo xlt('Reset Filters'); ?></a>
        </div>
    </div>

    <?php if ($flash !== '') { ?>
        <div class="notice"><?php echo text($flash); ?></div>
    <?php } ?>

    <?php if (!empty($errors)) { ?>
        <div class="error-box">
            <?php foreach ($errors as $error) { ?>
                <div><?php echo text($error); ?></div>
            <?php } ?>
        </div>
    <?php } ?>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="muted"><?php echo xlt('Total Tickets'); ?></div>
            <strong><?php echo text((string)$ticketSummary['total']); ?></strong>
        </div>
        <div class="summary-card">
            <div class="muted"><?php echo xlt('Open'); ?></div>
            <strong><?php echo text((string)$ticketSummary['Open']); ?></strong>
        </div>
        <div class="summary-card">
            <div class="muted"><?php echo xlt('In Progress'); ?></div>
            <strong><?php echo text((string)$ticketSummary['In Progress']); ?></strong>
        </div>
        <div class="summary-card">
            <div class="muted"><?php echo xlt('Resolved / Closed'); ?></div>
            <strong><?php echo text((string)($ticketSummary['Resolved'] + $ticketSummary['Closed'])); ?></strong>
        </div>
    </div>

    <div class="layout">
        <div class="stack">
            <div class="card">
                <div class="card-title-row">
                    <h2 style="margin: 0;"><?php echo xlt('Create Ticket'); ?></h2>
                    <span class="pill type-pill"><?php echo xlt('Production Ready'); ?></span>
                </div>
                <form method="post" action="bug_reports_admin.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                    <input type="hidden" name="action" value="create_ticket" />

                    <div class="field">
                        <label for="ticket_number"><?php echo xlt('Ticket Number'); ?></label>
                        <input
                            id="ticket_number"
                            type="text"
                            value="<?php echo attr('#' . (string)$nextTicketNumber); ?>"
                            readonly="readonly"
                            aria-readonly="true"
                        />
                    </div>

                    <div class="field">
                        <label for="title"><?php echo xlt('Summary'); ?></label>
                        <input id="title" type="text" name="title" maxlength="255" value="<?php echo attr($formValues['title']); ?>" placeholder="<?php echo attr(xl('Short, searchable description of the issue')); ?>" />
                    </div>

                    <div class="field-grid">
                        <div class="field">
                            <label for="issue_type"><?php echo xlt('Issue Type'); ?></label>
                            <select id="issue_type" name="issue_type">
                                <?php foreach ($issueTypeOptions as $issueTypeOption) { ?>
                                    <option value="<?php echo attr($issueTypeOption); ?>"<?php echo $formValues['issue_type'] === $issueTypeOption ? ' selected' : ''; ?>><?php echo text($issueTypeOption); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="priority"><?php echo xlt('Priority'); ?></label>
                            <select id="priority" name="priority">
                                <?php foreach ($priorityOptions as $priorityOption) { ?>
                                    <option value="<?php echo attr($priorityOption); ?>"<?php echo $formValues['priority'] === $priorityOption ? ' selected' : ''; ?>><?php echo text($priorityOption); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>

                    <div class="field-grid">
                        <div class="field">
                            <label for="module_page"><?php echo xlt('Module/Page'); ?></label>
                            <input id="module_page" type="text" name="module_page" maxlength="255" value="<?php echo attr($formValues['module_page']); ?>" placeholder="<?php echo attr(xl('Example: DCR Report / Revenue Breakdown')); ?>" />
                        </div>
                        <div class="field">
                            <label for="environment"><?php echo xlt('Environment'); ?></label>
                            <input id="environment" type="text" name="environment" maxlength="255" value="<?php echo attr($formValues['environment']); ?>" placeholder="<?php echo attr(xl('Example: Staging / Chrome / Billing Team')); ?>" />
                        </div>
                    </div>

                    <div class="field">
                        <label for="description"><?php echo xlt('Description'); ?></label>
                        <textarea id="description" name="description" placeholder="<?php echo attr(xl('Describe the problem, impact, steps to reproduce, expected result, and actual result.')); ?>"><?php echo text($formValues['description']); ?></textarea>
                    </div>

                    <div class="field">
                        <label for="screenshot"><?php echo xlt('Attachment'); ?></label>
                        <input id="screenshot" type="file" name="screenshot" accept=".png,.jpg,.jpeg,.gif,.webp,.pdf" />
                    </div>

                    <button class="btn btn-primary" type="submit"><?php echo xlt('Create Ticket'); ?></button>
                </form>
            </div>

            <div class="card">
                <div class="card-title-row">
                    <h2 style="margin: 0;"><?php echo xlt('Ticket Queue'); ?></h2>
                    <span class="muted"><?php echo text((string)count($tickets)); ?> <?php echo xlt('shown'); ?></span>
                </div>
                <form method="get" action="bug_reports_admin.php" class="queue-toolbar">
                    <div class="filter-row">
                        <input type="text" name="q" value="<?php echo attr($searchTerm); ?>" placeholder="<?php echo attr(xl('Search by summary, module, environment, description, or ticket #')); ?>" />
                        <select name="status">
                            <option value="All"><?php echo xlt('All Statuses'); ?></option>
                            <?php foreach ($statusOptions as $statusOption) { ?>
                                <option value="<?php echo attr($statusOption); ?>"<?php echo $filterStatus === $statusOption ? ' selected' : ''; ?>><?php echo text($statusOption); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <button class="btn btn-secondary" type="submit"><?php echo xlt('Apply Filters'); ?></button>
                </form>
                <div class="ticket-list">
                    <?php if (empty($tickets)) { ?>
                        <div class="empty"><?php echo xlt('No bug reports yet.'); ?></div>
                    <?php } ?>
                    <?php foreach ($tickets as $ticket) { ?>
                        <?php
                        $statusClass = 'status-' . str_replace(' ', '-', (string)$ticket['status']);
                        $priorityClass = 'priority-' . (string)$ticket['priority'];
                        ?>
                        <a class="ticket-item<?php echo (int)$ticket['id'] === $selectedTicketId ? ' active' : ''; ?>" href="bug_reports_admin.php?ticket_id=<?php echo attr_url((string)$ticket['id']); ?>">
                            <div class="ticket-topline">
                                <span class="ticket-key"><?php echo text(bugReportsGetTicketKey((int)$ticket['id'])); ?></span>
                                <span class="pill type-pill"><?php echo text((string)($ticket['issue_type'] ?: 'Bug')); ?></span>
                            </div>
                            <div class="ticket-title"><?php echo text($ticket['title']); ?></div>
                            <div style="display: flex; gap: 8px; margin-bottom: 8px; flex-wrap: wrap;">
                                <span class="pill <?php echo attr($priorityClass); ?>"><?php echo text($ticket['priority']); ?></span>
                                <span class="pill <?php echo attr($statusClass); ?>"><?php echo text($ticket['status']); ?></span>
                            </div>
                            <div class="ticket-meta">
                                <div><?php echo xlt('Module/Page'); ?>: <?php echo text($ticket['module_page']); ?></div>
                                <div><?php echo xlt('Environment'); ?>: <?php echo text((string)($ticket['environment'] ?: xlt('Not specified'))); ?></div>
                                <div><?php echo xlt('Created By'); ?>: <?php echo text($ticket['created_by_name'] ?: xlt('Unknown User')); ?></div>
                                <div><?php echo xlt('Updated'); ?>: <?php echo text(oeFormatShortDate(substr((string)$ticket['updated_at'], 0, 10)) . ' ' . substr((string)$ticket['updated_at'], 11, 5)); ?></div>
                            </div>
                        </a>
                    <?php } ?>
                </div>
            </div>
        </div>

        <div class="card">
            <?php if (!empty($selectedTicket['id'])) { ?>
                <?php
                $statusClass = 'status-' . str_replace(' ', '-', (string)$selectedTicket['status']);
                $priorityClass = 'priority-' . (string)$selectedTicket['priority'];
                ?>
                <div class="detail-layout">
                <div class="detail-hero">
                    <div>
                        <div class="detail-key"><?php echo text($selectedTicketKey); ?></div>
                        <h2 style="margin: 0 0 6px;"><?php echo text($selectedTicket['title']); ?></h2>
                        <div class="muted"><?php echo xlt('Created by'); ?> <?php echo text((string)($selectedTicket['created_by_name'] ?: xlt('Unknown User'))); ?></div>
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end;">
                        <span class="pill type-pill"><?php echo text((string)($selectedTicket['issue_type'] ?: 'Bug')); ?></span>
                        <span class="pill <?php echo attr($priorityClass); ?>"><?php echo text($selectedTicket['priority']); ?></span>
                        <span class="pill <?php echo attr($statusClass); ?>"><?php echo text($selectedTicket['status']); ?></span>
                    </div>
                </div>

                <div class="detail-fields">
                    <div class="detail-box">
                        <div class="detail-label"><?php echo xlt('Ticket Number'); ?></div>
                        <div><?php echo text($selectedTicketKey); ?></div>
                    </div>
                    <div class="detail-box">
                        <div class="detail-label"><?php echo xlt('Created By'); ?></div>
                        <div><?php echo text((string)($selectedTicket['created_by_name'] ?: xlt('Unknown User'))); ?></div>
                    </div>
                    <div class="detail-box">
                        <div class="detail-label"><?php echo xlt('Issue Type'); ?></div>
                        <div><?php echo text((string)($selectedTicket['issue_type'] ?: 'Bug')); ?></div>
                    </div>
                    <div class="detail-box">
                        <div class="detail-label"><?php echo xlt('Module/Page'); ?></div>
                        <div><?php echo text((string)$selectedTicket['module_page']); ?></div>
                    </div>
                    <div class="detail-box">
                        <div class="detail-label"><?php echo xlt('Environment'); ?></div>
                        <div><?php echo text((string)($selectedTicket['environment'] ?: xlt('Not specified'))); ?></div>
                    </div>
                    <div class="detail-box">
                        <div class="detail-label"><?php echo xlt('Created At'); ?></div>
                        <div><?php echo text((string)$selectedTicket['created_at']); ?></div>
                    </div>
                    <div class="detail-box">
                        <div class="detail-label"><?php echo xlt('Updated At'); ?></div>
                        <div><?php echo text((string)$selectedTicket['updated_at']); ?></div>
                    </div>
                    <div class="detail-box full">
                        <div class="detail-label"><?php echo xlt('Description'); ?></div>
                        <div><?php echo nl2br(text((string)$selectedTicket['description'])); ?></div>
                    </div>
                    <div class="detail-box full">
                        <div class="detail-label"><?php echo xlt('Screenshot'); ?></div>
                        <?php if (!empty($selectedTicket['screenshot_path'])) { ?>
                            <a href="bug_reports_admin.php?download=<?php echo attr_url((string)$selectedTicket['id']); ?>"><?php echo xlt('Download attachment'); ?></a>
                        <?php } else { ?>
                            <span class="empty"><?php echo xlt('No attachment'); ?></span>
                        <?php } ?>
                    </div>
                </div>

                <div class="subtle-divider"></div>

                <div style="margin-bottom: 20px;">
                    <h3 class="section-title"><?php echo xlt('Workflow'); ?></h3>
                    <form method="post" action="bug_reports_admin.php?ticket_id=<?php echo attr_url((string)$selectedTicket['id']); ?>" class="status-form">
                        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                        <input type="hidden" name="action" value="update_status" />
                        <input type="hidden" name="ticket_id" value="<?php echo attr((string)$selectedTicket['id']); ?>" />
                        <label for="status" style="margin: 0;"><?php echo xlt('Status'); ?></label>
                        <select id="status" name="status" style="max-width: 220px;">
                            <?php foreach ($statusOptions as $statusOption) { ?>
                                <option value="<?php echo attr($statusOption); ?>"<?php echo $statusOption === $selectedTicket['status'] ? ' selected' : ''; ?>><?php echo text($statusOption); ?></option>
                            <?php } ?>
                        </select>
                        <button class="btn btn-secondary" type="submit"><?php echo xlt('Update Status'); ?></button>
                    </form>
                </div>

                <h3 class="section-title"><?php echo xlt('Activity Timeline'); ?></h3>
                <div class="log-list">
                    <?php if (empty($logs)) { ?>
                        <div class="empty"><?php echo xlt('No logs added yet.'); ?></div>
                    <?php } ?>
                    <?php foreach ($logs as $log) { ?>
                        <div class="log-item">
                            <div class="log-head">
                                <span><?php echo text((string)($log['created_by_name'] ?: xlt('Unknown User'))); ?></span>
                                <span><?php echo text((string)$log['created_at']); ?></span>
                            </div>
                            <div><?php echo nl2br(text((string)$log['message'])); ?></div>
                        </div>
                    <?php } ?>
                </div>

                <form method="post" action="bug_reports_admin.php?ticket_id=<?php echo attr_url((string)$selectedTicket['id']); ?>">
                    <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                    <input type="hidden" name="action" value="add_log" />
                    <input type="hidden" name="ticket_id" value="<?php echo attr((string)$selectedTicket['id']); ?>" />
                    <div class="field">
                        <label for="message"><?php echo xlt('Add Update'); ?></label>
                        <textarea id="message" name="message" placeholder="<?php echo attr(xl('Add a progress note, workaround, root-cause note, or resolution update.')); ?>"></textarea>
                    </div>
                    <button class="btn btn-primary" type="submit"><?php echo xlt('Post Update'); ?></button>
                </form>
                </div>
            <?php } else { ?>
                <h2 style="margin-top: 0;"><?php echo xlt('Ticket Details'); ?></h2>
                <div class="empty"><?php echo xlt('Create a ticket or select one from the queue to review details, update workflow status, and add timeline notes.'); ?></div>
            <?php } ?>
        </div>
    </div>
</div>
</body>
</html>
<?php

function bugReportsEnsureTables(): void
{
    sqlStatement(
        "CREATE TABLE IF NOT EXISTS bug_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            priority VARCHAR(20) NOT NULL DEFAULT 'Medium',
            issue_type VARCHAR(20) NOT NULL DEFAULT 'Bug',
            module_page VARCHAR(255) NOT NULL DEFAULT '',
            environment VARCHAR(255) NOT NULL DEFAULT '',
            description TEXT NOT NULL,
            screenshot_path VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Open',
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_bug_reports_status (status),
            INDEX idx_bug_reports_priority (priority),
            INDEX idx_bug_reports_updated (updated_at)
        )"
    );

    sqlStatement(
        "CREATE TABLE IF NOT EXISTS bug_report_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT NOT NULL,
            message TEXT NOT NULL,
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_bug_report_logs_report (report_id),
            INDEX idx_bug_report_logs_created (created_at)
        )"
    );

    bugReportsEnsureColumn('bug_reports', 'issue_type', "ALTER TABLE bug_reports ADD COLUMN issue_type VARCHAR(20) NOT NULL DEFAULT 'Bug' AFTER priority");
    bugReportsEnsureColumn('bug_reports', 'environment', "ALTER TABLE bug_reports ADD COLUMN environment VARCHAR(255) NOT NULL DEFAULT '' AFTER module_page");
}

function bugReportsGetNextTicketNumber(): int
{
    $row = sqlQuery(
        "SELECT AUTO_INCREMENT AS next_id
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'bug_reports'"
    );

    $nextId = is_array($row) ? (int)($row['next_id'] ?? 0) : 0;
    if ($nextId > 0) {
        return $nextId;
    }

    $fallbackRow = sqlQuery("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM bug_reports");
    $fallbackId = is_array($fallbackRow) ? (int)($fallbackRow['next_id'] ?? 1) : 1;

    return max(1, $fallbackId);
}

function bugReportsGetTicketKey(int $ticketId): string
{
    return 'BUG-' . $ticketId;
}

function bugReportsEnsureColumn(string $tableName, string $columnName, string $alterSql): void
{
    $tableName = escape_table_name($tableName);
    $columnRow = sqlFetchArray(sqlStatement("SHOW COLUMNS FROM `" . $tableName . "` LIKE ?", [$columnName]));

    if (!is_array($columnRow)) {
        sqlStatement($alterSql);
    }
}

function bugReportsGetSummaryCounts(): array
{
    $summary = [
        'total' => 0,
        'Open' => 0,
        'In Progress' => 0,
        'Resolved' => 0,
        'Closed' => 0,
    ];

    $stmt = sqlStatement(
        "SELECT status, COUNT(*) AS ticket_count
         FROM bug_reports
         GROUP BY status"
    );

    while ($row = sqlFetchArray($stmt)) {
        if (!is_array($row)) {
            continue;
        }
        $status = (string)($row['status'] ?? '');
        $count = (int)($row['ticket_count'] ?? 0);
        $summary['total'] += $count;
        if (array_key_exists($status, $summary)) {
            $summary[$status] = $count;
        }
    }

    return $summary;
}
