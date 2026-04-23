<?php

/**
 * Shared helpers for OpenEMR chat assistants.
 *
 * @package OpenEMR
 */

function assistantNormalizeMessage(string $message): string
{
    $message = strtolower(trim($message));
    return preg_replace('/\s+/', ' ', $message);
}

function assistantAugmentNaturalPhrasing(string $message): string
{
    $message = assistantNormalizeMessage($message);
    if ($message === '') {
        return '';
    }

    $aliases = [];
    $addAlias = static function (string $alias) use (&$aliases, $message): void {
        $alias = assistantNormalizeMessage($alias);
        if ($alias !== '' && strpos($message, $alias) === false) {
            $aliases[$alias] = true;
        }
    };

    $hasToday = assistantKeywordMatch($message, ['today', "today's", 'todays']);
    $hasTomorrow = assistantKeywordMatch($message, ['tomorrow', "tomorrow's", 'tomorrows']);
    $hasYesterday = assistantKeywordMatch($message, ['yesterday', "yesterday's", 'yesterdays']);
    $hasDateAnchor = $hasToday || $hasTomorrow || $hasYesterday;

    if (
        $hasDateAnchor &&
        (
            assistantKeywordMatch($message, ['list of patient', 'list of patients', 'patient list', 'patients list'])
            || (assistantKeywordMatch($message, ['patients', 'patient']) && assistantKeywordMatch($message, ['list', 'show', 'give me']))
        )
    ) {
        if ($hasToday) {
            $addAlias('today patient visit list');
            $addAlias('today visit list');
            $addAlias('today patients list');
        }
        if ($hasTomorrow) {
            $addAlias('tomorrow patient visit list');
            $addAlias('tomorrow visit list');
            $addAlias('tomorrow patients list');
        }
        if ($hasYesterday) {
            $addAlias('yesterday patient visit list');
            $addAlias('yesterday visit list');
            $addAlias('yesterday patients list');
        }
    }

    if (
        assistantKeywordMatch($message, ['appointment', 'appointments', 'visit', 'visits', 'schedule']) &&
        (
            assistantKeywordMatch($message, ['by location', 'by facility', 'per location', 'per facility'])
            || (assistantKeywordMatch($message, ['location', 'facility']) && assistantKeywordMatch($message, ['wise', 'each']))
        )
    ) {
        $addAlias('appointments by location');
        $addAlias('appointments by facility');
        $addAlias('visits by location');
        $addAlias('visits by facility');
    }

    if (
        assistantKeywordMatch($message, ['patient', 'patients']) &&
        (
            assistantKeywordMatch($message, ['by location', 'by facility', 'per location', 'per facility'])
            || (
                assistantKeywordMatch($message, ['location', 'facility']) &&
                assistantKeywordMatch($message, ['wise', 'each'])
            )
            || assistantKeywordMatch($message, ['count location', 'count facility'])
        )
    ) {
        $addAlias('patient count by location');
        $addAlias('patient count by facility');
        $addAlias('patients by location');
        $addAlias('patients by facility');
    }

    if (assistantKeywordMatch($message, ['revenue']) && assistantKeywordMatch($message, ['last year', 'this year'])) {
        $addAlias('compare last year vs this year revenue');
    }

    if (assistantKeywordMatch($message, ['revenue']) && assistantKeywordMatch($message, ['2025', '2026', '2024', '2023']) && assistantKeywordMatch($message, ['compare', 'vs'])) {
        $addAlias('compare revenue');
    }

    return empty($aliases) ? $message : ($message . ' ' . implode(' ', array_keys($aliases)));
}

function assistantJsonExit(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function assistantStreamStart(int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/x-ndjson');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Accel-Buffering: no');

    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    ob_implicit_flush(true);
}

function assistantStreamEvent(array $payload): void
{
    echo json_encode($payload) . "\n";
    flush();
}

function assistantStreamReplyExit(array $payload, int $status = 200): void
{
    $reply = (string)($payload['reply'] ?? '');
    $chunkSize = 18;

    assistantStreamStart($status);

    if (!empty($payload['success']) && $reply !== '') {
        $length = strlen($reply);
        for ($offset = 0; $offset < $length; $offset += $chunkSize) {
            assistantStreamEvent([
                'type' => 'chunk',
                'delta' => substr($reply, $offset, $chunkSize),
            ]);
            usleep(15000);
        }
    }

    assistantStreamEvent(array_merge($payload, ['type' => 'done']));
    exit;
}

function assistantBuildReply(string $title, array $bullets, string $footer = ''): string
{
    $parts = [$title];
    foreach ($bullets as $bullet) {
        $parts[] = '- ' . $bullet;
    }
    if ($footer !== '') {
        $parts[] = $footer;
    }
    return implode("\n", $parts);
}

function assistantPrepareConversationHistory($history, int $maxMessages = 8): array
{
    if (!is_array($history)) {
        return [];
    }

    $prepared = [];
    foreach ($history as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $role = (string)($entry['role'] ?? '');
        $content = trim((string)($entry['content'] ?? ''));
        if (!in_array($role, ['user', 'assistant'], true) || $content === '') {
            continue;
        }

        $prepared[] = [
            'role' => $role,
            'content' => substr($content, 0, 2000),
        ];
    }

    if (count($prepared) > $maxMessages) {
        $prepared = array_slice($prepared, -$maxMessages);
    }

    return $prepared;
}

function assistantNormalizeContextArea(string $area): string
{
    $area = assistantNormalizeMessage($area);
    $allowed = ['pos', 'scheduling', 'reports', 'patients', 'inventory', 'dashboard'];
    return in_array($area, $allowed, true) ? $area : 'general';
}

function assistantInferContextArea(string $url = '', string $title = ''): string
{
    $haystack = assistantNormalizeMessage($url . ' ' . $title);

    $map = [
        'pos' => ['interface/pos', ' pos', 'checkout', 'dispense', 'administer', 'payment'],
        'scheduling' => ['calendar', 'appointment', 'schedule', 'visit'],
        'reports' => ['interface/reports', ' report', 'dcr', 'daily collection'],
        'patients' => ['patient_file', 'patient', 'demographics', 'encounter', 'finder'],
        'inventory' => ['inventory', 'drug', 'lot', 'medicine', 'warehouse'],
        'dashboard' => ['dashboard', 'home'],
    ];

    foreach ($map as $area => $patterns) {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && strpos($haystack, assistantNormalizeMessage($pattern)) !== false) {
                return $area;
            }
        }
    }

    return 'general';
}

function assistantResolveContext($context): array
{
    if (!is_array($context)) {
        $context = [];
    }

    $url = trim((string)($context['url'] ?? ''));
    $title = trim((string)($context['title'] ?? ''));
    $area = assistantNormalizeContextArea((string)($context['area'] ?? ''));
    $patientId = (int)($context['patient_id'] ?? 0);
    $reportName = trim((string)($context['report_name'] ?? ''));
    $posState = trim((string)($context['pos_state'] ?? ''));

    if ($area === 'general') {
        $area = assistantInferContextArea($url, $title);
    }

    if ($patientId <= 0) {
        $patientId = assistantExtractContextPatientId($url);
    }
    if ($reportName === '') {
        $reportName = assistantExtractContextReportName($area, $title, $url);
    }
    if ($posState === '') {
        $posState = assistantExtractContextPosState($area, $title, $url);
    }

    return [
        'area' => $area,
        'title' => $title,
        'url' => $url,
        'label' => assistantContextLabel($area),
        'patient_id' => $patientId > 0 ? $patientId : null,
        'report_name' => $reportName,
        'pos_state' => $posState,
    ];
}

function assistantExtractContextPatientId(string $url): int
{
    if ($url === '') {
        return 0;
    }

    if (preg_match('/(?:\?|&)(?:pid|set_pid|patient_id)=([0-9]+)/i', $url, $matches)) {
        return (int)$matches[1];
    }

    return 0;
}

function assistantExtractContextReportName(string $area, string $title, string $url): string
{
    if ($area !== 'reports') {
        return '';
    }

    $source = trim($title !== '' ? $title : basename(parse_url($url, PHP_URL_PATH) ?: ''));
    return substr($source, 0, 120);
}

function assistantExtractContextPosState(string $area, string $title, string $url): string
{
    if ($area !== 'pos') {
        return '';
    }

    $haystack = assistantNormalizeMessage($title . ' ' . $url);
    foreach (['payment', 'checkout', 'backdate', 'dispense', 'administer', 'invoice'] as $state) {
        if (strpos($haystack, $state) !== false) {
            return $state;
        }
    }

    return '';
}

function assistantContextLabel(string $area): string
{
    $labels = [
        'pos' => 'POS',
        'scheduling' => 'Scheduling',
        'reports' => 'Reports',
        'patients' => 'Patient Chart',
        'inventory' => 'Inventory',
        'dashboard' => 'Dashboard',
        'general' => 'OpenEMR',
    ];

    return $labels[$area] ?? 'OpenEMR';
}

function assistantGetStarterQuestions(string $mode, array $context = []): array
{
    if ($mode === 'patient') {
        return [xl('How do I schedule an appointment?'), xl('Who do I contact about billing?'), xl('How do I use the portal?')];
    }

    $area = assistantNormalizeContextArea((string)($context['area'] ?? 'general'));
    $patientId = (int)($context['patient_id'] ?? 0);
    switch ($area) {
        case 'pos':
            if ($patientId > 0) {
                return [
                    sprintf(xl('Patient %d balance'), $patientId),
                    sprintf(xl('Recent POS for patient %d'), $patientId),
                    sprintf(xl('Why did payment fail for patient %d?'), $patientId)
                ];
            }
            return [xl('Patient 42 balance'), xl('Receipt 1001'), xl('Why did payment fail?')];
        case 'scheduling':
            if ($patientId > 0) {
                return [
                    sprintf(xl('Show upcoming visits for patient %d'), $patientId),
                    sprintf(xl('Recent visits for patient %d'), $patientId),
                    xl('How do I reschedule this patient?')
                ];
            }
            return [xl('Appointments for John Smith'), xl('How do I reschedule a patient?'), xl('Show upcoming visits for patient 42')];
        case 'reports':
            if (!empty($context['report_name'])) {
                return [
                    xl('Today revenue'),
                    xl('Today deposits'),
                    xl('Gross patient count today')
                ];
            }
            return [xl('Today revenue'), xl('Revenue by medicine today'), xl('DCR by facility this month')];
        case 'patients':
            if ($patientId > 0) {
                return [
                    sprintf(xl('Patient %d balance'), $patientId),
                    sprintf(xl('Recent visits for patient %d'), $patientId),
                    sprintf(xl('Appointments for patient %d'), $patientId)
                ];
            }
            return [xl('John Smith'), xl('Patient 42 balance'), xl('Recent visits for John Smith')];
        case 'inventory':
            return [xl('Expired sema'), xl('Tirz stock'), xl('Lot K001')];
        default:
            return [xl('Today revenue'), xl('Find patient John Smith'), xl('Show expired medicines')];
    }
}

function assistantBuildContextInstructions(array $context): string
{
    $area = assistantNormalizeContextArea((string)($context['area'] ?? 'general'));
    $title = trim((string)($context['title'] ?? ''));
    $label = assistantContextLabel($area);

    $instructions = 'The user is currently working in the ' . $label . ' area of OpenEMR.';
    if ($title !== '') {
        $instructions .= ' Active screen title: ' . $title . '.';
    }
    if (!empty($context['patient_id'])) {
        $instructions .= ' Current patient ID in context: ' . (int)$context['patient_id'] . '.';
    }
    if (!empty($context['report_name'])) {
        $instructions .= ' Current report context: ' . trim((string)$context['report_name']) . '.';
    }
    if (!empty($context['pos_state'])) {
        $instructions .= ' Current POS workflow state appears to be: ' . trim((string)$context['pos_state']) . '.';
    }

    switch ($area) {
        case 'pos':
            $instructions .= ' Prioritize checkout, payment, dispense, administer, backdate, and remaining-dispense workflow guidance.';
            break;
        case 'scheduling':
            $instructions .= ' Prioritize calendar, appointments, rescheduling, and visit workflow guidance.';
            break;
        case 'reports':
            $instructions .= ' Prioritize report interpretation, DCR workflows, reconciliation, and reporting logic guidance.';
            break;
        case 'patients':
            $instructions .= ' Prioritize patient search, demographics, encounters, and chart workflow guidance.';
            break;
        case 'inventory':
            $instructions .= ' Prioritize stock, lots, expiration, and inventory workflow guidance.';
            break;
        default:
            break;
    }

    return $instructions;
}

function assistantBuildConversationTranscript(array $history, string $message): string
{
    $lines = [];

    foreach ($history as $entry) {
        $speaker = ($entry['role'] ?? '') === 'assistant' ? 'jacki' : 'User';
        $content = trim((string)($entry['content'] ?? ''));
        if ($content === '') {
            continue;
        }

        $lines[] = $speaker . ': ' . $content;
    }

    $lines[] = 'User: ' . trim($message);

    return implode("\n\n", $lines);
}

function assistantKeywordMatch(string $message, array $keywords): bool
{
    foreach ($keywords as $keyword) {
        if (strpos($message, $keyword) !== false) {
            return true;
        }
    }
    return false;
}

function assistantEnsureTables(): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    sqlStatementNoLog(
        "CREATE TABLE IF NOT EXISTS assistant_knowledge_base (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mode VARCHAR(20) NOT NULL DEFAULT 'staff',
            pattern_text VARCHAR(255) NOT NULL,
            answer_text TEXT NOT NULL,
            approved TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT DEFAULT NULL,
            approved_by INT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_assistant_knowledge_mode_approved (mode, approved)
        ) ENGINE=InnoDB"
    );

    sqlStatementNoLog(
        "CREATE TABLE IF NOT EXISTS assistant_chat_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mode VARCHAR(20) NOT NULL DEFAULT 'staff',
            user_id INT DEFAULT NULL,
            deidentified_message TEXT NOT NULL,
            deidentified_reply TEXT NOT NULL,
            reply_source VARCHAR(40) NOT NULL DEFAULT 'workflow',
            knowledge_id INT DEFAULT NULL,
            feedback SMALLINT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_assistant_chat_mode_created (mode, created_at),
            INDEX idx_assistant_chat_feedback (feedback)
        ) ENGINE=InnoDB"
    );

    sqlStatementNoLog(
        "CREATE TABLE IF NOT EXISTS assistant_knowledge_suggestions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mode VARCHAR(20) NOT NULL DEFAULT 'staff',
            normalized_question VARCHAR(255) NOT NULL,
            sample_message TEXT NOT NULL,
            latest_reply TEXT NOT NULL,
            suggested_pattern VARCHAR(255) NOT NULL,
            suggested_answer TEXT DEFAULT NULL,
            reason VARCHAR(40) NOT NULL DEFAULT 'unanswered',
            source_log_id INT DEFAULT NULL,
            occurrence_count INT NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_assistant_suggestions_status (status, updated_at),
            INDEX idx_assistant_suggestions_mode_status (mode, status)
        ) ENGINE=InnoDB"
    );

    $initialized = true;
}

function assistantFindApprovedKnowledge(string $mode, string $normalizedMessage): array
{
    assistantEnsureTables();

    $normalizedMessage = assistantNormalizeMessage($normalizedMessage);
    if ($normalizedMessage === '') {
        return [];
    }

    $rows = [];
    $statement = sqlStatement(
        "SELECT id, mode, pattern_text, answer_text
        FROM assistant_knowledge_base
        WHERE approved = 1
          AND (mode = ? OR mode = 'all')
        ORDER BY CHAR_LENGTH(pattern_text) DESC, id DESC",
        [$mode]
    );

    while ($row = sqlFetchArray($statement)) {
        if (!is_array($row)) {
            continue;
        }

        $pattern = assistantNormalizeMessage((string)($row['pattern_text'] ?? ''));
        if ($pattern === '') {
            continue;
        }

        $score = assistantScoreApprovedKnowledgeMatch($normalizedMessage, $pattern);
        if ($score <= 0) {
            continue;
        }

        $row['_assistant_match_score'] = $score;
        $rows[] = $row;
    }

    if (empty($rows)) {
        return [];
    }

    usort($rows, static function (array $left, array $right): int {
        $scoreCompare = (($right['_assistant_match_score'] ?? 0) <=> ($left['_assistant_match_score'] ?? 0));
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }

        return strlen((string)($right['pattern_text'] ?? '')) <=> strlen((string)($left['pattern_text'] ?? ''));
    });

    unset($rows[0]['_assistant_match_score']);
    return $rows[0];
}

function assistantScoreApprovedKnowledgeMatch(string $normalizedMessage, string $pattern): int
{
    $pattern = assistantNormalizeMessage($pattern);
    if ($pattern === '') {
        return 0;
    }

    if ($normalizedMessage === $pattern) {
        return 1000;
    }

    $messageTokens = assistantTokenizeMatchText($normalizedMessage);
    $patternTokens = assistantTokenizeMatchText($pattern);
    if (empty($messageTokens) || empty($patternTokens)) {
        return 0;
    }

    $patternTokenCount = count($patternTokens);
    $messageTokenCount = count($messageTokens);
    $sharedTokens = count(array_intersect($patternTokens, $messageTokens));
    $coverage = $patternTokenCount > 0 ? ($sharedTokens / $patternTokenCount) : 0;

    if ($patternTokenCount === 1) {
        $token = $patternTokens[0];
        if (strlen($token) < 4) {
            return 0;
        }

        return in_array($token, $messageTokens, true) ? 420 : 0;
    }

    if (assistantContainsPhraseBoundary($normalizedMessage, $pattern)) {
        $lengthBonus = min(120, max(0, strlen($pattern) - 12));
        return 820 + $lengthBonus;
    }

    if ($coverage >= 1.0) {
        return 760 - max(0, $messageTokenCount - $patternTokenCount) * 8;
    }

    if ($coverage >= 0.8 && $sharedTokens >= 3) {
        return 620 - max(0, $messageTokenCount - $patternTokenCount) * 10;
    }

    if ($coverage >= 0.67 && $sharedTokens >= 2 && $patternTokenCount >= 3) {
        return 420 - max(0, $messageTokenCount - $patternTokenCount) * 12;
    }

    if (strlen($pattern) >= 18) {
        similar_text($normalizedMessage, $pattern, $percent);
        if ($percent >= 88) {
            return 320;
        }
    }

    return 0;
}

function assistantContainsPhraseBoundary(string $haystack, string $needle): bool
{
    if ($haystack === '' || $needle === '') {
        return false;
    }

    $pattern = '/(^|[^a-z0-9])' . preg_quote($needle, '/') . '([^a-z0-9]|$)/i';
    return preg_match($pattern, $haystack) === 1;
}

function assistantTokenizeMatchText(string $text): array
{
    $text = assistantNormalizeMessage($text);
    if ($text === '') {
        return [];
    }

    $tokens = preg_split('/[^a-z0-9]+/i', $text);
    if (!is_array($tokens)) {
        return [];
    }

    $tokens = array_values(array_unique(array_filter(array_map(static function ($token) {
        $token = trim((string)$token);
        return strlen($token) >= 2 ? $token : '';
    }, $tokens))));

    return $tokens;
}

function assistantDeidentifyText(string $text): string
{
    $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[EMAIL]', $text);
    $text = preg_replace('/\b(?:\+?1[\s\-\.]?)?(?:\(?\d{3}\)?[\s\-\.]?)\d{3}[\s\-\.]?\d{4}\b/', '[PHONE]', $text);
    $text = preg_replace('/\b\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}\b/', '[DATE]', $text);
    $text = preg_replace('/\b\d{4}-\d{2}-\d{2}\b/', '[DATE]', $text);
    $text = preg_replace('/\b\d{5}(?:-\d{4})?\b/', '[ZIP]', $text);
    $text = preg_replace('/\bpid\s*[:#-]?\s*\d+\b/i', 'PID [ID]', $text);
    $text = preg_replace('/\bpatient\s+\d+\b/i', 'patient [ID]', $text);
    $text = preg_replace('/\b\d{7,}\b/', '[NUMBER]', $text);
    return trim((string)$text);
}

function assistantLogInteraction(
    string $mode,
    string $message,
    string $reply,
    string $source,
    ?int $knowledgeId = null,
    ?int $userId = null
): int {
    assistantEnsureTables();

    return (int) sqlInsert(
        "INSERT INTO assistant_chat_log
        (mode, user_id, deidentified_message, deidentified_reply, reply_source, knowledge_id)
        VALUES (?, ?, ?, ?, ?, ?)",
        [
            $mode,
            $userId,
            assistantDeidentifyText($message),
            assistantDeidentifyText($reply),
            $source,
            $knowledgeId
        ]
    );
}

function assistantSaveFeedback(int $logId, int $feedback): bool
{
    assistantEnsureTables();

    if ($logId <= 0 || !in_array($feedback, [1, -1], true)) {
        return false;
    }

    return sqlStatement(
        "UPDATE assistant_chat_log SET feedback = ? WHERE id = ?",
        [$feedback, $logId]
    ) !== false;
}

function assistantReplyNeedsKnowledgeReview(string $replySource, string $reply): bool
{
    if ($replySource === 'approved_knowledge') {
        return false;
    }

    $normalizedReply = assistantNormalizeMessage($reply);
    if ($normalizedReply === '') {
        return false;
    }

    if (assistantReplyIsGroundedLookup($normalizedReply)) {
        return false;
    }

    $genericMarkers = [
        assistantNormalizeMessage(xl('Staff Assistant')),
        assistantNormalizeMessage(xl('Patient Support Assistant')),
        assistantNormalizeMessage(xl('Patient Workflow')),
        assistantNormalizeMessage(xl('Inventory Workflow')),
        assistantNormalizeMessage(xl('Bloodwork Lookup')),
        assistantNormalizeMessage(xl('Consultation Lookup')),
        assistantNormalizeMessage(xl('Appointment Lookup')),
        assistantNormalizeMessage(xl('Scheduling Workflow')),
        assistantNormalizeMessage(xl('POS Workflow')),
        assistantNormalizeMessage(xl('Import and Export')),
    ];

    foreach ($genericMarkers as $marker) {
        if ($marker !== '' && strpos($normalizedReply, $marker) === 0) {
            return true;
        }
    }

    return (
        strpos($normalizedReply, assistantNormalizeMessage(xl('I could not find a matching patient in your system.'))) !== false ||
        strpos($normalizedReply, assistantNormalizeMessage(xl('Tell me which patient you want'))) !== false ||
        strpos($normalizedReply, assistantNormalizeMessage(xl('I found more than one possible patient.'))) !== false
    );
}

function assistantReplyIsGroundedLookup(string $normalizedReply): bool
{
    $groundedTitles = [
        assistantNormalizeMessage(xl('Revenue Lookup')),
        assistantNormalizeMessage(xl('Revenue Comparison')),
        assistantNormalizeMessage(xl('Deposit Lookup')),
        assistantNormalizeMessage(xl('Gross Patient Count')),
        assistantNormalizeMessage(xl('Shot Tracker')),
        assistantNormalizeMessage(xl('Revenue by Medicine')),
        assistantNormalizeMessage(xl('DCR by Facility')),
        assistantNormalizeMessage(xl('Today Visit List')),
        assistantNormalizeMessage(xl('Patient Count by Location')),
        assistantNormalizeMessage(xl('Appointment Lookup')),
        assistantNormalizeMessage(xl('Bloodwork Lookup')),
        assistantNormalizeMessage(xl('Consultation Lookup')),
        assistantNormalizeMessage(xl('Recent Patient Activity')),
        assistantNormalizeMessage(xl('Patient Balance')),
        assistantNormalizeMessage(xl('Inventory Lookup')),
        assistantNormalizeMessage(xl('Cash Lookup')),
        assistantNormalizeMessage(xl('Patient Spend Lookup')),
        assistantNormalizeMessage(xl('Follow-Up Lookup')),
        assistantNormalizeMessage(xl('New Patient Visit Lookup')),
        assistantNormalizeMessage(xl('Shot History Lookup')),
        assistantNormalizeMessage(xl('Patient Medication Lookup')),
        assistantNormalizeMessage(xl('Weight Lookup')),
        assistantNormalizeMessage(xl('Remaining Injection Lookup')),
        assistantNormalizeMessage(xl('Patient Address')),
        assistantNormalizeMessage(xl('System Search')),
    ];

    foreach ($groundedTitles as $title) {
        if ($title !== '' && strpos($normalizedReply, $title) === 0) {
            return true;
        }
    }

    return false;
}

function assistantQueueKnowledgeSuggestion(
    string $mode,
    string $message,
    string $reply,
    string $reason = 'unanswered',
    ?int $logId = null
): int {
    assistantEnsureTables();

    $normalizedQuestion = assistantNormalizeMessage(assistantDeidentifyText($message));
    if ($normalizedQuestion === '') {
        return 0;
    }

    $sampleMessage = assistantDeidentifyText($message);
    $latestReply = assistantDeidentifyText($reply);
    $pattern = substr($normalizedQuestion, 0, 255);

    $existing = sqlQuery(
        "SELECT id, occurrence_count
         FROM assistant_knowledge_suggestions
         WHERE mode = ?
           AND normalized_question = ?
           AND status = 'pending'
         ORDER BY id DESC
         LIMIT 1",
        [$mode, $pattern]
    );

    if (is_array($existing) && !empty($existing['id'])) {
        sqlStatement(
            "UPDATE assistant_knowledge_suggestions
             SET occurrence_count = occurrence_count + 1,
                 sample_message = ?,
                 latest_reply = ?,
                 reason = ?,
                 source_log_id = ?,
                 updated_at = NOW()
             WHERE id = ?",
            [$sampleMessage, $latestReply, $reason, $logId, (int)$existing['id']]
        );
        assistantRefreshKnowledgeSuggestionDraft((int)$existing['id'], $mode, $sampleMessage, $latestReply, false);
        return (int)$existing['id'];
    }

    $suggestedPattern = assistantBuildFallbackSuggestionPattern($normalizedQuestion);
    $suggestionId = (int) sqlInsert(
        "INSERT INTO assistant_knowledge_suggestions
        (mode, normalized_question, sample_message, latest_reply, suggested_pattern, suggested_answer, reason, source_log_id, occurrence_count, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'pending')",
        [$mode, $pattern, $sampleMessage, $latestReply, $suggestedPattern, '', $reason, $logId]
    );

    if ($suggestionId > 0) {
        assistantRefreshKnowledgeSuggestionDraft($suggestionId, $mode, $sampleMessage, $latestReply, true);
    }

    return $suggestionId;
}

function assistantQueueSuggestionFromLog(int $logId, string $reason = 'negative_feedback'): int
{
    assistantEnsureTables();

    $row = sqlQuery(
        "SELECT mode, deidentified_message, deidentified_reply
         FROM assistant_chat_log
         WHERE id = ?",
        [$logId]
    );

    if (!is_array($row) || empty($row['deidentified_message'])) {
        return 0;
    }

    return assistantQueueKnowledgeSuggestion(
        (string)($row['mode'] ?? 'staff'),
        (string)$row['deidentified_message'],
        (string)($row['deidentified_reply'] ?? ''),
        $reason,
        $logId
    );
}

function assistantRefreshKnowledgeSuggestionDraft(int $suggestionId, string $mode, string $sampleMessage, string $latestReply, bool $force = false): bool
{
    assistantEnsureTables();

    if ($suggestionId <= 0) {
        return false;
    }

    $row = sqlQuery(
        "SELECT id, normalized_question, suggested_pattern, suggested_answer, status
         FROM assistant_knowledge_suggestions
         WHERE id = ?
         LIMIT 1",
        [$suggestionId]
    );

    if (!is_array($row) || empty($row['id']) || ($row['status'] ?? 'pending') !== 'pending') {
        return false;
    }

    $needsDraft = $force
        || trim((string)($row['suggested_answer'] ?? '')) === ''
        || assistantNormalizeMessage((string)($row['suggested_pattern'] ?? '')) === assistantNormalizeMessage((string)($row['normalized_question'] ?? ''));

    if (!$needsDraft) {
        return false;
    }

    $draft = assistantBuildKnowledgeSuggestionDraft($mode, $sampleMessage, $latestReply, (string)($row['normalized_question'] ?? ''));
    $pattern = trim((string)($draft['pattern'] ?? ''));
    $answer = trim((string)($draft['answer'] ?? ''));

    if ($pattern === '') {
        $pattern = assistantBuildFallbackSuggestionPattern((string)($row['normalized_question'] ?? $sampleMessage));
    }

    if ($pattern === '' && $answer === '') {
        return false;
    }

    sqlStatement(
        "UPDATE assistant_knowledge_suggestions
         SET suggested_pattern = ?,
             suggested_answer = ?,
             updated_at = NOW()
         WHERE id = ?",
        [$pattern, $answer, $suggestionId]
    );

    return true;
}

function assistantBuildKnowledgeSuggestionDraft(string $mode, string $sampleMessage, string $latestReply, string $normalizedQuestion = ''): array
{
    $fallbackPattern = assistantBuildFallbackSuggestionPattern($normalizedQuestion !== '' ? $normalizedQuestion : $sampleMessage);
    $sampleMessage = trim(assistantDeidentifyText($sampleMessage));
    $latestReply = trim(assistantDeidentifyText($latestReply));

    if (assistantPythonBotEnabled()) {
        $pythonDraft = assistantCallPythonBot([
            'task' => 'knowledge_draft',
            'mode' => $mode,
            'sample_message' => $sampleMessage,
            'latest_reply' => $latestReply,
            'fallback_pattern' => $fallbackPattern,
        ]);

        $pattern = assistantNormalizeSuggestedPattern((string)($pythonDraft['pattern'] ?? ''));
        $answer = trim((string)($pythonDraft['answer'] ?? ''));
        if (!empty($pythonDraft['success']) && ($pattern !== '' || $answer !== '')) {
            return [
                'pattern' => $pattern !== '' ? $pattern : $fallbackPattern,
                'answer' => $answer,
            ];
        }
    }

    if (!assistantOpenAIEnabled()) {
        return [
            'pattern' => $fallbackPattern,
            'answer' => '',
        ];
    }

    $config = assistantGetOpenAIConfig();
    $instructions = 'You are helping prepare reviewed knowledge drafts for an internal OpenEMR assistant. '
        . 'Given a de-identified user question and the assistant\'s latest weak or incomplete reply, produce a safe reusable trigger pattern and a concise draft answer. '
        . 'Do not include PHI, names, phone numbers, IDs, dates of birth, addresses, or anything patient-specific. '
        . 'Do not invent live financial, patient, scheduling, or inventory data. '
        . 'The pattern should be a short reusable phrase that could match similar future questions. '
        . 'The answer should be practical, neutral, and safe for staff review. '
        . 'Return strict JSON only with keys "pattern" and "answer".';

    if ($mode === 'patient') {
        $instructions .= ' Patient-mode drafts must stay general, non-diagnostic, and non-sensitive.';
    } else {
        $instructions .= ' Staff-mode drafts may describe workflow guidance, but must not pretend to know live records.';
    }

    $payload = [
        'model' => $config['model'],
        'instructions' => $instructions,
        'input' => "Question:\n" . $sampleMessage . "\n\nCurrent weak reply:\n" . $latestReply . "\n\nFallback pattern:\n" . $fallbackPattern,
    ];

    $ch = curl_init($config['endpoint']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $config['api_key'],
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, (int)$config['timeout']);

    $rawResponse = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($rawResponse === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
        return [
            'pattern' => $fallbackPattern,
            'answer' => '',
        ];
    }

    $responseData = json_decode((string)$rawResponse, true);
    if (!is_array($responseData)) {
        return [
            'pattern' => $fallbackPattern,
            'answer' => '',
        ];
    }

    $text = assistantExtractOpenAIText($responseData);
    $parsed = assistantParseSuggestionJson($text);
    $pattern = assistantNormalizeSuggestedPattern((string)($parsed['pattern'] ?? ''));
    $answer = trim((string)($parsed['answer'] ?? ''));

    if ($pattern === '') {
        $pattern = $fallbackPattern;
    }

    return [
        'pattern' => $pattern,
        'answer' => $answer,
    ];
}

function assistantParseSuggestionJson(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    $parsed = json_decode($text, true);
    if (is_array($parsed)) {
        return $parsed;
    }

    if (preg_match('/\{.*\}/s', $text, $matches)) {
        $parsed = json_decode((string)$matches[0], true);
        if (is_array($parsed)) {
            return $parsed;
        }
    }

    return [];
}

function assistantBuildFallbackSuggestionPattern(string $text): string
{
    $normalized = assistantNormalizeMessage(assistantDeidentifyText($text));
    if ($normalized === '') {
        return '';
    }

    $normalized = preg_replace('/\b(please|help|can you|could you|tell me|show me|what is|what are|how do i|how can i|i need|i want)\b/i', ' ', $normalized);
    $normalized = assistantNormalizeMessage((string)$normalized);
    $tokens = assistantTokenizeMatchText($normalized);
    if (empty($tokens)) {
        return substr($normalized, 0, 120);
    }

    return substr(implode(' ', array_slice($tokens, 0, 8)), 0, 120);
}

function assistantNormalizeSuggestedPattern(string $pattern): string
{
    $pattern = assistantNormalizeMessage(assistantDeidentifyText($pattern));
    return substr($pattern, 0, 255);
}

function assistantGetOpenAIConfig(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $apiKey = trim((string)(assistantGetEnvValue('JACKI_OPENAI_API_KEY', 'OPENAI_API_KEY') ?: ''));
    $model = trim((string)(assistantGetEnvValue('JACKI_OPENAI_MODEL', 'OPENAI_MODEL') ?: 'gpt-5-mini'));
    $timeout = (int)(assistantGetEnvValue('JACKI_OPENAI_TIMEOUT_SECONDS', 'OPENAI_TIMEOUT_SECONDS') ?: 20);

    $config = [
        'enabled' => $apiKey !== '',
        'api_key' => $apiKey,
        'model' => $model !== '' ? $model : 'gpt-5-mini',
        'timeout' => $timeout > 0 ? $timeout : 20,
        'endpoint' => 'https://api.openai.com/v1/responses',
    ];

    return $config;
}

function assistantGetPythonBotConfig(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $url = trim((string)(assistantGetEnvValue('JACKI_PYTHON_BOT_URL') ?: ''));
    $token = trim((string)(assistantGetEnvValue('JACKI_PYTHON_BOT_TOKEN') ?: ''));
    $timeout = (int)(assistantGetEnvValue('JACKI_PYTHON_BOT_TIMEOUT_SECONDS') ?: 20);

    $config = [
        'enabled' => $url !== '',
        'url' => $url,
        'token' => $token,
        'timeout' => $timeout > 0 ? $timeout : 20,
    ];

    return $config;
}

function assistantGetEnvValue(string ...$keys): string
{
    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value !== false && trim((string)$value) !== '') {
            return trim((string)$value);
        }

        if (!empty($_ENV[$key]) && trim((string)$_ENV[$key]) !== '') {
            return trim((string)$_ENV[$key]);
        }

        if (!empty($_SERVER[$key]) && trim((string)$_SERVER[$key]) !== '') {
            return trim((string)$_SERVER[$key]);
        }
    }

    return '';
}

function assistantOpenAIEnabled(): bool
{
    return false;
}

function assistantPythonBotEnabled(): bool
{
    return false;
}

function assistantCallPythonBot(array $payload): array
{
    $config = assistantGetPythonBotConfig();
    if (empty($config['enabled']) || empty($config['url']) || !function_exists('curl_init')) {
        return ['success' => false];
    }

    $headers = ['Content-Type: application/json'];
    if (!empty($config['token'])) {
        $headers[] = 'X-Jacki-Token: ' . $config['token'];
    }

    $ch = curl_init($config['url']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, (int)$config['timeout']);

    $rawResponse = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($rawResponse === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
        return ['success' => false];
    }

    $decoded = json_decode((string)$rawResponse, true);
    return is_array($decoded) ? $decoded : ['success' => false];
}

function assistantShouldUseOpenAIReply(string $mode, string $replySource, string $draftReply): bool
{
    return assistantReplyNeedsKnowledgeReview($replySource, $draftReply);
}

function assistantBuildOpenAIInstructions(string $mode, string $draftReply = '', array $history = [], array $context = []): string
{
    if ($mode === 'patient') {
        $instructions = 'You are jacki, a patient-facing OpenEMR support assistant. '
            . 'Be warm, concise, and realistic. '
            . 'Do not give diagnosis, emergency triage, medication dosing, or private account-specific details. '
            . 'If the situation could be urgent, clearly tell the user to contact their clinician or emergency services. '
            . 'Offer practical next steps for appointments, billing questions, office contact, or portal help. '
            . 'Write like a polished chat assistant, not like a robotic FAQ.';
    } else {
        $instructions = 'You are jacki, an internal workflow assistant for OpenEMR staff. '
            . 'Be concise, practical, and confident. '
            . 'Help with operational workflow questions. '
            . 'Do not invent patient records, appointment data, or inventory facts. '
            . 'If specific data is unavailable, say so clearly and give the next best OpenEMR workflow step. '
            . 'Sound like a capable operations copilot, not like static help text. '
            . 'For greetings, acknowledgements, or casual conversation, reply naturally in one or two short sentences and then offer one concrete thing you can help with. '
            . 'For workflow explanations, be direct and useful instead of overly formal.';
    }

    $instructions .= ' Keep replies short and easy to scan. Use a brief lead sentence and then short bullets when useful. '
        . 'Carry forward relevant context from the recent conversation if it helps answer the current message.';

    if ($draftReply !== '') {
        $instructions .= ' You will also receive a draft OpenEMR answer. '
            . 'Preserve its meaning and concrete facts. Improve tone and readability, but do not add new factual claims.';
    }

    if (!empty($history)) {
        $instructions .= ' A recent conversation transcript is included. Use it only for continuity and follow-up context.';
    }

    if (!empty($context)) {
        $instructions .= ' ' . assistantBuildContextInstructions($context);
    }

    return $instructions;
}

function assistantExtractOpenAIText(array $responseData): string
{
    if (!empty($responseData['output_text']) && is_string($responseData['output_text'])) {
        return trim($responseData['output_text']);
    }

    if (empty($responseData['output']) || !is_array($responseData['output'])) {
        return '';
    }

    foreach ($responseData['output'] as $item) {
        if (!is_array($item) || ($item['type'] ?? '') !== 'message' || empty($item['content']) || !is_array($item['content'])) {
            continue;
        }

        foreach ($item['content'] as $content) {
            if (!is_array($content)) {
                continue;
            }

            if (($content['type'] ?? '') === 'output_text' && !empty($content['text']) && is_string($content['text'])) {
                return trim($content['text']);
            }

            if (($content['type'] ?? '') === 'refusal' && !empty($content['refusal']) && is_string($content['refusal'])) {
                return trim($content['refusal']);
            }
        }
    }

    return '';
}

function assistantTryOpenAIReply(string $mode, string $message, string $draftReply = '', array $history = [], array $context = []): array
{
    return ['success' => false, 'reply' => '', 'source' => ''];
}
