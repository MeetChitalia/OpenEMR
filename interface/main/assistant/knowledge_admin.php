<?php

/**
 * Assistant knowledge review screen.
 *
 * @package OpenEMR
 */

$ignoreAuth = false;
require_once(dirname(__FILE__) . "/../../globals.php");
require_once(__DIR__ . "/assistant_common.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

if (empty($_SESSION['authUserID']) || !AclMain::aclCheckCore('admin', 'super')) {
    die(xlt('Not authorized'));
}

assistantEnsureTables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        die(xlt('CSRF token validation failed'));
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $mode = ($_POST['mode'] ?? 'staff') === 'patient' ? 'patient' : 'staff';
        $pattern = trim((string)($_POST['pattern_text'] ?? ''));
        $answer = trim((string)($_POST['answer_text'] ?? ''));

        if ($pattern !== '' && $answer !== '') {
            sqlInsert(
                "INSERT INTO assistant_knowledge_base
                (mode, pattern_text, answer_text, approved, created_by)
                VALUES (?, ?, ?, 0, ?)",
                [$mode, $pattern, $answer, (int)$_SESSION['authUserID']]
            );
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['knowledge_id'] ?? 0);
        $approved = (int)($_POST['approved'] ?? 0) ? 1 : 0;

            sqlStatement(
                "UPDATE assistant_knowledge_base
            SET approved = ?, approved_by = ?
            WHERE id = ?",
                [$approved, (int)$_SESSION['authUserID'], $id]
            );
    } elseif ($action === 'promote_suggestion') {
        $suggestionId = (int)($_POST['suggestion_id'] ?? 0);
        $mode = ($_POST['mode'] ?? 'staff') === 'patient' ? 'patient' : 'staff';
        $pattern = trim((string)($_POST['pattern_text'] ?? ''));
        $answer = trim((string)($_POST['answer_text'] ?? ''));

        if ($suggestionId > 0 && $pattern !== '' && $answer !== '') {
            sqlInsert(
                "INSERT INTO assistant_knowledge_base
                (mode, pattern_text, answer_text, approved, created_by)
                VALUES (?, ?, ?, 0, ?)",
                [$mode, $pattern, $answer, (int)$_SESSION['authUserID']]
            );

            sqlStatement(
                "UPDATE assistant_knowledge_suggestions
                 SET status = 'promoted', updated_at = NOW()
                 WHERE id = ?",
                [$suggestionId]
            );
        }
    } elseif ($action === 'dismiss_suggestion') {
        $suggestionId = (int)($_POST['suggestion_id'] ?? 0);
        if ($suggestionId > 0) {
            sqlStatement(
                "UPDATE assistant_knowledge_suggestions
                 SET status = 'dismissed', updated_at = NOW()
                 WHERE id = ?",
                [$suggestionId]
            );
        }
    } elseif ($action === 'regenerate_suggestion') {
        $suggestionId = (int)($_POST['suggestion_id'] ?? 0);
        if ($suggestionId > 0) {
            $row = sqlQuery(
                "SELECT mode, sample_message, latest_reply
                 FROM assistant_knowledge_suggestions
                 WHERE id = ?",
                [$suggestionId]
            );

            if (is_array($row) && !empty($row['mode'])) {
                assistantRefreshKnowledgeSuggestionDraft(
                    $suggestionId,
                    (string)$row['mode'],
                    (string)($row['sample_message'] ?? ''),
                    (string)($row['latest_reply'] ?? ''),
                    true
                );
            }
        }
    }
}

$csrf = CsrfUtils::collectCsrfToken();
$knowledgeRows = [];
$knowledgeStmt = sqlStatement(
    "SELECT id, mode, pattern_text, answer_text, approved, created_at, updated_at
    FROM assistant_knowledge_base
    ORDER BY approved DESC, updated_at DESC, id DESC"
);
while ($row = sqlFetchArray($knowledgeStmt)) {
    if (is_array($row)) {
        $knowledgeRows[] = $row;
    }
}

$suggestionRows = [];
$suggestionStmt = sqlStatement(
    "SELECT id, mode, sample_message, latest_reply, suggested_pattern, suggested_answer, reason, occurrence_count, status, updated_at
     FROM assistant_knowledge_suggestions
     ORDER BY
        CASE status
            WHEN 'pending' THEN 0
            WHEN 'promoted' THEN 1
            ELSE 2
        END,
        occurrence_count DESC,
        updated_at DESC,
        id DESC
     LIMIT 50"
);
while ($row = sqlFetchArray($suggestionStmt)) {
    if (is_array($row)) {
        $suggestionRows[] = $row;
    }
}

$logRows = [];
$logStmt = sqlStatement(
    "SELECT id, mode, deidentified_message, deidentified_reply, reply_source, feedback, created_at
    FROM assistant_chat_log
    ORDER BY id DESC
    LIMIT 30"
);
while ($row = sqlFetchArray($logStmt)) {
    if (is_array($row)) {
        $logRows[] = $row;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Assistant Knowledge Review'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f7fb; color: #1f2937; margin: 0; }
        .wrap { max-width: 1200px; margin: 24px auto; padding: 0 20px 32px; }
        .card { background: #fff; border: 1px solid #dbe3ec; border-radius: 16px; padding: 20px; margin-bottom: 20px; }
        h1, h2 { margin-top: 0; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        label { display: block; font-weight: 700; margin-bottom: 6px; }
        input[type="text"], select, textarea { width: 100%; border: 1px solid #cfd8e3; border-radius: 10px; padding: 10px 12px; font-size: 14px; }
        textarea { min-height: 120px; resize: vertical; }
        .btn { border: none; border-radius: 10px; padding: 10px 14px; font-weight: 700; cursor: pointer; }
        .btn-primary { background: #0f6cbd; color: #fff; }
        .btn-success { background: #159957; color: #fff; }
        .btn-muted { background: #e5e7eb; color: #111827; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-top: 1px solid #e5e7eb; padding: 12px 10px; text-align: left; vertical-align: top; }
        .pill { display: inline-block; padding: 4px 8px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .pill.ok { background: #dcfce7; color: #166534; }
        .pill.pending { background: #fef3c7; color: #92400e; }
        .pill.ai { background: #dbeafe; color: #1d4ed8; }
        .muted { color: #66758a; font-size: 13px; }
        .mono { font-family: Menlo, monospace; white-space: pre-wrap; }
        .draft-box { background: #f8fbff; border: 1px solid #d6e6f5; border-radius: 12px; padding: 10px 12px; margin-top: 8px; }
        .stack { display: grid; gap: 8px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1><?php echo xlt('Assistant Knowledge Review'); ?></h1>
        <div class="muted"><?php echo xlt('Only approved entries are used by the assistant. Chat logs below are de-identified for review and tuning preparation.'); ?></div>
    </div>

    <div class="card">
        <h2><?php echo xlt('Add Knowledge Entry'); ?></h2>
        <form method="post" action="knowledge_admin.php">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>" />
            <input type="hidden" name="action" value="add" />
            <div class="grid">
                <div>
                    <label for="mode"><?php echo xlt('Mode'); ?></label>
                    <select id="mode" name="mode">
                        <option value="staff"><?php echo xlt('Staff'); ?></option>
                        <option value="patient"><?php echo xlt('Patient'); ?></option>
                    </select>
                </div>
                <div>
                    <label for="pattern_text"><?php echo xlt('Trigger Pattern'); ?></label>
                    <input id="pattern_text" type="text" name="pattern_text" placeholder="<?php echo attr(xl('Example: reset patient portal password')); ?>" />
                </div>
            </div>
            <div style="margin-top: 16px;">
                <label for="answer_text"><?php echo xlt('Approved Answer'); ?></label>
                <textarea id="answer_text" name="answer_text" placeholder="<?php echo attr(xl('Write the reviewed answer that should be returned when the pattern matches.')); ?>"></textarea>
            </div>
            <div style="margin-top: 16px;">
                <button class="btn btn-primary" type="submit"><?php echo xlt('Save as Pending'); ?></button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2><?php echo xlt('Draft Suggestions'); ?></h2>
        <div class="muted"><?php echo xlt('Repeated unanswered questions and negative feedback are collected here as drafts. Promote a draft into the knowledge base when you are ready to review and approve it.'); ?></div>
        <table>
            <thead>
            <tr>
                <th><?php echo xlt('Status'); ?></th>
                <th><?php echo xlt('Mode'); ?></th>
                <th><?php echo xlt('Occurrences'); ?></th>
                <th><?php echo xlt('Reason'); ?></th>
                <th><?php echo xlt('Question'); ?></th>
                <th><?php echo xlt('Current Reply'); ?></th>
                <th><?php echo xlt('Suggested Draft'); ?></th>
                <th><?php echo xlt('Promote'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($suggestionRows as $row) { ?>
                <tr>
                    <td>
                        <span class="pill <?php echo ($row['status'] ?? '') === 'pending' ? 'pending' : 'ok'; ?>">
                            <?php echo text(ucfirst((string)($row['status'] ?? 'pending'))); ?>
                        </span>
                    </td>
                    <td><?php echo text($row['mode']); ?></td>
                    <td><?php echo text((string)($row['occurrence_count'] ?? 1)); ?></td>
                    <td><?php echo text((string)($row['reason'] ?? '')); ?></td>
                    <td class="mono"><?php echo text($row['sample_message']); ?></td>
                    <td class="mono"><?php echo text($row['latest_reply']); ?></td>
                    <td>
                        <div class="stack">
                            <span class="pill ai"><?php echo xlt('Suggested Draft'); ?></span>
                            <div class="draft-box">
                                <strong><?php echo xlt('Pattern'); ?>:</strong>
                                <div class="mono"><?php echo text((string)($row['suggested_pattern'] ?? '')); ?></div>
                            </div>
                            <div class="draft-box">
                                <strong><?php echo xlt('Answer'); ?>:</strong>
                                <div class="mono"><?php echo text((string)($row['suggested_answer'] ?? '')); ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if (($row['status'] ?? 'pending') === 'pending') { ?>
                            <form method="post" action="knowledge_admin.php" style="margin-bottom:12px;">
                                <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>" />
                                <input type="hidden" name="action" value="promote_suggestion" />
                                <input type="hidden" name="suggestion_id" value="<?php echo attr($row['id']); ?>" />
                                <input type="hidden" name="mode" value="<?php echo attr($row['mode']); ?>" />
                                <label for="pattern_<?php echo attr($row['id']); ?>"><?php echo xlt('Pattern'); ?></label>
                                <input id="pattern_<?php echo attr($row['id']); ?>" type="text" name="pattern_text" value="<?php echo attr($row['suggested_pattern']); ?>" />
                                <label for="answer_<?php echo attr($row['id']); ?>" style="margin-top:8px;"><?php echo xlt('Draft Answer'); ?></label>
                                <textarea id="answer_<?php echo attr($row['id']); ?>" name="answer_text" placeholder="<?php echo attr(xl('Write the reviewed answer jacki should use.')); ?>"><?php echo text((string)($row['suggested_answer'] ?? '')); ?></textarea>
                                <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
                                    <button class="btn btn-primary" type="submit"><?php echo xlt('Promote to Knowledge'); ?></button>
                                </div>
                            </form>
                            <form method="post" action="knowledge_admin.php" style="margin-bottom:12px;">
                                <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>" />
                                <input type="hidden" name="action" value="regenerate_suggestion" />
                                <input type="hidden" name="suggestion_id" value="<?php echo attr($row['id']); ?>" />
                                <button class="btn btn-success" type="submit"><?php echo xlt('Refresh Draft'); ?></button>
                            </form>
                            <form method="post" action="knowledge_admin.php">
                                <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>" />
                                <input type="hidden" name="action" value="dismiss_suggestion" />
                                <input type="hidden" name="suggestion_id" value="<?php echo attr($row['id']); ?>" />
                                <button class="btn btn-muted" type="submit"><?php echo xlt('Dismiss'); ?></button>
                            </form>
                        <?php } else { ?>
                            <span class="muted"><?php echo text((string)($row['status'] ?? '')); ?></span>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2><?php echo xlt('Knowledge Entries'); ?></h2>
        <table>
            <thead>
            <tr>
                <th><?php echo xlt('Status'); ?></th>
                <th><?php echo xlt('Mode'); ?></th>
                <th><?php echo xlt('Pattern'); ?></th>
                <th><?php echo xlt('Answer'); ?></th>
                <th><?php echo xlt('Action'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($knowledgeRows as $row) { ?>
                <tr>
                    <td>
                        <span class="pill <?php echo !empty($row['approved']) ? 'ok' : 'pending'; ?>">
                            <?php echo !empty($row['approved']) ? xlt('Approved') : xlt('Pending'); ?>
                        </span>
                    </td>
                    <td><?php echo text($row['mode']); ?></td>
                    <td class="mono"><?php echo text($row['pattern_text']); ?></td>
                    <td class="mono"><?php echo text($row['answer_text']); ?></td>
                    <td>
                        <form method="post" action="knowledge_admin.php">
                            <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>" />
                            <input type="hidden" name="action" value="toggle" />
                            <input type="hidden" name="knowledge_id" value="<?php echo attr($row['id']); ?>" />
                            <input type="hidden" name="approved" value="<?php echo !empty($row['approved']) ? '0' : '1'; ?>" />
                            <button class="btn <?php echo !empty($row['approved']) ? 'btn-muted' : 'btn-success'; ?>" type="submit">
                                <?php echo !empty($row['approved']) ? xlt('Unapprove') : xlt('Approve'); ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2><?php echo xlt('Recent De-identified Chat Logs'); ?></h2>
        <table>
            <thead>
            <tr>
                <th><?php echo xlt('When'); ?></th>
                <th><?php echo xlt('Mode'); ?></th>
                <th><?php echo xlt('Source'); ?></th>
                <th><?php echo xlt('Feedback'); ?></th>
                <th><?php echo xlt('Question'); ?></th>
                <th><?php echo xlt('Reply'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($logRows as $row) { ?>
                <tr>
                    <td><?php echo text($row['created_at']); ?></td>
                    <td><?php echo text($row['mode']); ?></td>
                    <td><?php echo text($row['reply_source']); ?></td>
                    <td><?php echo text((string)($row['feedback'] ?? '')); ?></td>
                    <td class="mono"><?php echo text($row['deidentified_message']); ?></td>
                    <td class="mono"><?php echo text($row['deidentified_reply']); ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
