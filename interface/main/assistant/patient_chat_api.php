<?php

/**
 * Patient support assistant endpoint.
 *
 * @package OpenEMR
 */

$ignoreAuth = true;
require_once(dirname(__FILE__) . "/../../globals.php");
require_once(__DIR__ . "/assistant_common.php");

use OpenEMR\Common\Csrf\CsrfUtils;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    assistantJsonExit(['success' => false, 'error' => xl('Invalid request method')], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}
$stream = !empty($payload['stream']);

$csrf = $payload['csrf_token_form'] ?? $payload['csrf_token'] ?? '';
if (!CsrfUtils::verifyCsrfToken($csrf)) {
    $errorPayload = ['success' => false, 'error' => xl('CSRF token validation failed')];
    $stream ? assistantStreamReplyExit($errorPayload, 400) : assistantJsonExit($errorPayload, 400);
}

$message = trim((string)($payload['message'] ?? ''));
if ($message === '') {
    assistantJsonExit(['success' => false, 'error' => xl('Message is required')], 400);
}
$history = assistantPrepareConversationHistory($payload['history'] ?? []);
$context = assistantResolveContext($payload['context'] ?? []);

assistantEnsureTables();

$normalized = assistantAugmentNaturalPhrasing(assistantNormalizeMessage($message));
$knowledge = assistantFindApprovedKnowledge('patient', $normalized);
$replySource = 'workflow';
$knowledgeId = null;

if (!empty($knowledge['answer_text'])) {
    $reply = (string)$knowledge['answer_text'];
    $replySource = 'approved_knowledge';
    $knowledgeId = (int)$knowledge['id'];
} elseif (assistantKeywordMatch($normalized, ['appointment', 'schedule', 'reschedule', 'cancel'])) {
    $reply = assistantBuildReply(
        xl('Appointments'),
        [
            xl('Please contact the clinic or use the patient portal appointment tools if enabled.'),
            xl('If you are trying to reschedule, have your preferred date and time ready.'),
            xl('Urgent medical issues should not wait for chat support.')
        ]
    );
} elseif (assistantKeywordMatch($normalized, ['bill', 'payment', 'invoice', 'balance'])) {
    $reply = assistantBuildReply(
        xl('Billing Help'),
        [
            xl('Please contact the billing team or use the portal billing section if available.'),
            xl('Have your invoice or account details ready when asking for help.'),
            xl('This assistant does not expose account-specific financial data in chat.')
        ]
    );
} elseif (assistantKeywordMatch($normalized, ['hours', 'location', 'address', 'phone'])) {
    $reply = assistantBuildReply(
        xl('Clinic Information'),
        [
            xl('Please use the clinic contact information shown in the portal or site header.'),
            xl('If you need directions or office hours, the front desk can help quickly.')
        ]
    );
} else {
    $reply = assistantBuildReply(
        xl('Patient Support Assistant'),
        [
            xl('I can help with appointments, general billing questions, and portal guidance.'),
            xl('I do not provide diagnosis, emergency guidance, or private account-specific information in this version.'),
            xl('For urgent symptoms, contact your clinician or emergency services right away.')
        ]
    );
}

$logId = assistantLogInteraction(
    'patient',
    $message,
    $reply,
    $replySource,
    $knowledgeId,
    null
);

if (assistantReplyNeedsKnowledgeReview($replySource, $reply)) {
    assistantQueueKnowledgeSuggestion('patient', $message, $reply, 'unanswered', $logId);
}

$responsePayload = [
    'success' => true,
    'reply' => $reply,
    'mode' => 'patient',
    'log_id' => $logId,
    'reply_source' => $replySource
];

if ($stream) {
    assistantStreamReplyExit($responsePayload);
}

assistantJsonExit($responsePayload);
