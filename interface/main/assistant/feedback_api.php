<?php

/**
 * Assistant feedback endpoint.
 *
 * @package OpenEMR
 */

$ignoreAuth = false;
require_once(dirname(__FILE__) . "/../../globals.php");
require_once(__DIR__ . "/assistant_common.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    assistantJsonExit(['success' => false, 'error' => xl('Invalid request method')], 405);
}

if (empty($_SESSION['authUserID']) || !AclMain::aclCheckCore('patients', 'demo')) {
    assistantJsonExit(['success' => false, 'error' => xl('Not authorized')], 403);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$csrf = $payload['csrf_token_form'] ?? $payload['csrf_token'] ?? '';
if (!CsrfUtils::verifyCsrfToken($csrf)) {
    assistantJsonExit(['success' => false, 'error' => xl('CSRF token validation failed')], 400);
}

$logId = (int)($payload['log_id'] ?? 0);
$feedback = (int)($payload['feedback'] ?? 0);

if (!assistantSaveFeedback($logId, $feedback)) {
    assistantJsonExit(['success' => false, 'error' => xl('Unable to save feedback')], 400);
}

if ($feedback < 0) {
    assistantQueueSuggestionFromLog($logId, 'negative_feedback');
}

assistantJsonExit([
    'success' => true,
    'message' => xl('Feedback saved')
]);
