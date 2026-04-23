<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$payloadJson = $argv[1] ?? '';
if ($payloadJson === '') {
    fwrite(STDERR, "Usage: php scripts/pos_cli_action.php '{...json...}'\n");
    exit(1);
}

$payload = json_decode($payloadJson, true);
if (!is_array($payload)) {
    fwrite(STDERR, "Invalid JSON payload\n");
    exit(1);
}

if (session_status() === PHP_SESSION_NONE) {
    session_save_path('/tmp');
    $sessionId = (string) ($payload['_session']['session_id'] ?? ('pos-cli-' . substr(md5(json_encode($payload) . microtime(true)), 0, 12)));
    session_id($sessionId);
    session_start();
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/openemr/interface/pos/pos_payment_processor.php';
$_SESSION['site_id'] = 'default';
$_SESSION['authUserID'] = (int) ($payload['_session']['authUserID'] ?? 1);
$_SESSION['authUser'] = (string) ($payload['_session']['authUser'] ?? 'IRS-admin-03');
$_SESSION['facilityId'] = (int) ($payload['_session']['facilityId'] ?? 36);
$_SESSION['facility_id'] = (int) ($payload['_session']['facility_id'] ?? 36);

unset($payload['_session']);
$_POST = $payload;

require __DIR__ . '/../interface/pos/pos_payment_processor.php';
