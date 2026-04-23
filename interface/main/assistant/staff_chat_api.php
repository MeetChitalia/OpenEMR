<?php

/**
 * Internal staff assistant endpoint.
 *
 * @package OpenEMR
 */

$ignoreAuth = false;
require_once(dirname(__FILE__) . "/../../globals.php");
require_once(__DIR__ . "/assistant_common.php");
require_once(__DIR__ . "/../../drugs/drugs.inc.php");

ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', __DIR__ . '/jacki_staging_debug.log');
error_log('Jacki staff API loaded');

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

assistantRegisterStaffApiFailureHandlers();

if (empty($_SESSION['authUserID'])) {
    assistantJsonExit(['success' => false, 'error' => xl('Not authorized')], 401);
}

if (!AclMain::aclCheckCore('patients', 'demo')) {
    assistantJsonExit(['success' => false, 'error' => xl('Not authorized')], 403);
}

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
assistantTraceStaffApi('request_start', ['message' => $message, 'stream' => $stream ? 1 : 0]);

$normalized = assistantAugmentNaturalPhrasing(assistantNormalizeMessage($message));
assistantTraceStaffApi('normalized_ready', ['normalized' => $normalized]);
$knowledge = assistantFindApprovedKnowledge('staff', $normalized);
$replySource = 'retrieval';
$knowledgeId = null;

if (!empty($knowledge['answer_text'])) {
    $reply = (string)$knowledge['answer_text'];
    $replySource = 'approved_knowledge';
    $knowledgeId = (int)$knowledge['id'];
    assistantTraceStaffApi('knowledge_hit', ['knowledge_id' => $knowledgeId]);
} else {
    $reply = assistantHandleLiveStaffRequest($message, $normalized, $context);
    assistantTraceStaffApi('live_reply_ready', ['reply_length' => strlen($reply)]);
    if ($reply === '') {
        $reply = assistantTryDirectClinicMetricFallback($message, $normalized);
        assistantTraceStaffApi('direct_fallback_ready', ['reply_length' => strlen($reply)]);
    }
    if ($reply === '') {
        $reply = assistantBuildReply(
            xl('Staff Assistant'),
            [xl('I could not match that request to a supported live lookup yet.')],
            xl('Try including a patient name, a specific date like 4/4/2026, or a keyword such as revenue, cash, shot cards, inventory, follow-up, or weight.')
        );
        assistantTraceStaffApi('generic_fallback_used');
    }
}

$actions = assistantSuggestActions($message, $normalized);
assistantTraceStaffApi('actions_ready', ['count' => count($actions)]);

$logId = assistantLogInteraction(
    'staff',
    $message,
    $reply,
    $replySource,
    $knowledgeId,
    (int)($_SESSION['authUserID'] ?? 0)
);
assistantTraceStaffApi('interaction_logged', ['log_id' => $logId, 'reply_source' => $replySource]);

if (assistantReplyNeedsKnowledgeReview($replySource, $reply) && !assistantShouldAttemptRevenueLookup($message, $normalized)) {
    assistantQueueKnowledgeSuggestion('staff', $message, $reply, 'unanswered', $logId);
    assistantTraceStaffApi('knowledge_suggestion_queued', ['log_id' => $logId]);
}

$responsePayload = [
    'success' => true,
    'reply' => $reply,
    'mode' => 'staff',
    'log_id' => $logId,
    'reply_source' => $replySource,
    'actions' => $actions
];

if ($stream) {
    assistantStreamReplyExit($responsePayload);
}

assistantJsonExit($responsePayload);

function assistantRegisterStaffApiFailureHandlers(): void
{
    set_exception_handler(static function (Throwable $exception): void {
        assistantLogStaffApiFailure(
            'uncaught_exception',
            $exception->getMessage(),
            $exception->getFile(),
            (int)$exception->getLine()
        );

        assistantEmitStaffApiFailureResponse(
            xl('Jacki hit an internal error while answering that request.')
        );
    });

    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if (!is_array($error)) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array((int)($error['type'] ?? 0), $fatalTypes, true)) {
            return;
        }

        assistantLogStaffApiFailure(
            'fatal_shutdown',
            (string)($error['message'] ?? ''),
            (string)($error['file'] ?? ''),
            (int)($error['line'] ?? 0)
        );

        assistantEmitStaffApiFailureResponse(
            xl('Jacki hit an internal error while answering that request.')
        );
    });
}

function assistantEmitStaffApiFailureResponse(string $message): void
{
    if (headers_sent()) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $payload = [
        'success' => false,
        'error' => $message,
    ];

    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw ?: '', true);
    $stream = is_array($decoded) && !empty($decoded['stream']);

    $stream ? assistantStreamReplyExit($payload, 500) : assistantJsonExit($payload, 500);
}

function assistantLogStaffApiFailure(string $type, string $message, string $file = '', int $line = 0): void
{
    error_log(sprintf(
        'Jacki staff API %s: %s in %s on line %d',
        $type,
        $message !== '' ? $message : 'Unknown error',
        $file !== '' ? $file : 'unknown file',
        $line
    ));
}

function assistantTraceStaffApi(string $stage, array $context = []): void
{
    $parts = [];
    foreach ($context as $key => $value) {
        $parts[] = $key . '=' . str_replace(["\n", "\r"], ' ', (string)$value);
    }

    error_log('Jacki staff API trace: ' . $stage . (empty($parts) ? '' : ' | ' . implode(' | ', $parts)));
}

function assistantHandleLiveStaffRequest(string $message, string $normalized, array $context = []): string
{
    $conversationReply = assistantTryConversationSupport($message, $normalized, $context);
    if ($conversationReply !== '') {
        return $conversationReply;
    }

    $customReply = assistantTryClinicQuestionLookup($message, $normalized, $context);
    if ($customReply !== '') {
        return $customReply;
    }

    $liveReply = assistantTryRevenueLookup($message, $normalized);
    if ($liveReply !== '') {
        return $liveReply;
    }

    $liveReply = assistantTryDcrMetricLookup($message, $normalized);
    if ($liveReply !== '') {
        return $liveReply;
    }

    $workflowReply = assistantTryWorkflowExplanation($normalized);
    if ($workflowReply !== '') {
        return $workflowReply;
    }

    $liveReply = assistantTryPatientPresenceLookup($message, $normalized);
    if ($liveReply !== '') {
        return $liveReply;
    }

    $liveReply = assistantTryTodayVisitListLookup($message, $normalized);
    if ($liveReply !== '') {
        return $liveReply;
    }

    $liveReply = assistantTryScheduleByLocationLookup($message, $normalized);
    if ($liveReply !== '') {
        return $liveReply;
    }

    $liveReply = assistantTryPatientCountByLocationLookup($message, $normalized);
    if ($liveReply !== '') {
        return $liveReply;
    }

    $liveReply = assistantTryAppointmentLookup($message, $normalized);
    if ($liveReply !== '') {
        return $liveReply;
    }

    $liveReply = assistantTryBloodworkLookup($message, $normalized, $context);
    if ($liveReply !== '') {
        return $liveReply;
    }

    $liveReply = assistantTryConsultationLookup($message, $normalized, $context);
    if ($liveReply !== '') {
        return $liveReply;
    }

    $liveReply = assistantTryRecentPatientsLookup($message, $normalized);
    if ($liveReply !== '') {
        return $liveReply;
    }

    $liveReply = assistantTryPatientAddressLookup($message, $normalized);
    if ($liveReply !== '') {
        return $liveReply;
    }

    $liveReply = assistantTryInventoryLookup($message, $normalized);
    if ($liveReply !== '') {
        return $liveReply;
    }

    $liveReply = assistantTryPatientBalanceLookup($message, $normalized, $context);
    if ($liveReply !== '') {
        return $liveReply;
    }

    $liveReply = assistantTryPosTransactionLookup($message, $normalized, $context);
    if ($liveReply !== '') {
        return $liveReply;
    }

    $liveReply = assistantTryUnifiedSystemLookup($message, $normalized);
    if ($liveReply !== '') {
        return $liveReply;
    }

    $liveReply = assistantTryPatientSearchLookup($message, $normalized);
    if ($liveReply !== '') {
        return $liveReply;
    }

    if (assistantKeywordMatch($normalized, ['import patient', 'import patients', 'patient import', 'upload patients', 'upload patient spreadsheet'])) {
        return assistantBuildReply(
            xl('Patient Import Steps'),
            [
                xl('Open Patient Finder and click the import icon, or go directly to the Patient Import screen.'),
                xl('Download the sample template first so your spreadsheet columns match the required format.'),
                xl('Prepare your XLSX, XLS, or CSV file using the fixed template fields such as Patient ID, First Name, Last Name, DOB, Sex, phone, email, and address columns.'),
                xl('Upload the file and click Preview Import to let the system validate the rows before creating patients.'),
                xl('Review the preview counts and fix any blocked rows such as missing required fields, invalid DOB, invalid sex, or duplicate patients.'),
                xl('When the preview looks correct, click Import Ready Rows to create only the validated patients.')
            ],
            xl('If you want, ask me next: "what columns are required for patient import" or "where do I get the sample patient import template".')
        );
    }

    if (assistantKeywordMatch($normalized, ['patient import template', 'import template', 'sample import file', 'sample patient import file'])) {
        return assistantBuildReply(
            xl('Patient Import Template'),
            [
                xl('Use the sample file from the Patient Import screen so your columns stay in the required order.'),
                xl('The template includes fields like Patient ID, First Name, Middle Name, Last Name, DOB, Sex, Phone Home, Phone Cell, Email, Street, City, State, Zip, and Country.'),
                xl('The importer validates required fields and blocks rows with missing or invalid data before final import.')
            ]
        );
    }

    if (assistantKeywordMatch($normalized, ['required columns', 'required fields', 'import columns']) && assistantKeywordMatch($normalized, ['import', 'patient'])) {
        return assistantBuildReply(
            xl('Required Patient Import Fields'),
            [
                xl('The core required values are First Name, Last Name, DOB, and Sex.'),
                xl('The import template also supports Patient ID, Middle Name, Phone Home, Phone Cell, Email, Street, City, State, Zip, and Country.'),
                xl('Rows are blocked if required values are missing, DOB is invalid, Sex is invalid, or the patient looks like a duplicate.')
            ]
        );
    }

    if (assistantKeywordMatch($normalized, ['patient', 'finder', 'search patient', 'new patient'])) {
        return assistantBuildReply(
            xl('Patient Workflow'),
            [
                xl('Use Finder to search existing patients and open their chart.'),
                xl('Use New/Search under the Patient menu to register a new patient.'),
                xl('You can also ask me live questions such as "find patient John Smith", "show patient 42", or "what is Jane Doe address".')
            ],
            xl('I can search by patient name, phone, patient ID, or public ID.')
        );
    }

    if (assistantKeywordMatch($normalized, ['inventory', 'lot', 'drug', 'expiration'])) {
        return assistantBuildReply(
            xl('Inventory Workflow'),
            [
                xl('I can check inventory by drug name or lot number.'),
                xl('I can also show expired medicines or lots that are expiring soon.'),
                xl('Try questions like "inventory semaglutide" or "lot K001".'),
                xl('Use the Drug Inventory screen when you need full receiving, transfer, return, or adjustment workflows.')
            ]
        );
    }

    if (assistantKeywordMatch($normalized, ['bloodwork', 'lab', 'labs', 'result'])) {
        return assistantBuildReply(
            xl('Bloodwork Lookup'),
            [
                xl('I can show recent lab and bloodwork results for a patient.'),
                xl('Try "bloodwork for patient 42" or "labs for John Smith".'),
                xl('I can retrieve recent results, but I do not judge whether values are medically good or bad unless clinician rules are added.')
            ]
        );
    }

    if (assistantKeywordMatch($normalized, ['consultation', 'consult', 'encounter', 'visit history'])) {
        return assistantBuildReply(
            xl('Consultation Lookup'),
            [
                xl('I can show recent consultation or encounter history for a patient.'),
                xl('Try "consultation for patient 42" or "recent visits for John Smith".')
            ]
        );
    }

    if (
        assistantKeywordMatch($normalized, ['point of sale page', 'pos page', 'checkout page', 'how do i use this page', 'how do i use pos', 'how do i checkout here'])
        || (
            ($context['area'] ?? '') === 'pos'
            && assistantKeywordMatch($normalized, ['this page', 'this screen', 'what do i do here', 'how do i use this', 'what are the steps'])
        )
    ) {
        return assistantBuildReply(
            xl('POS Page Steps'),
            [
                xl('Start by confirming the patient banner at the top, including the patient name, DOB, gender, and available credit balance.'),
                xl('If the page says weight recording is required, enter the patient weight and click Record Weight before trying to check out.'),
                xl('Use the main search bar to add medicines, products, office items, or services to the cart.'),
                xl('Use the quick selections like Consultation, Follow-Up, New, Injection, Blood Work, Patient Number, and Prepay when that workflow applies to the visit.'),
                xl('Review the Order Summary and set the correct quantity, dispense amount, administer amount, and lot information for each item.'),
                xl('Use Apply Credit Balance if the patient should use available credit, and use Transfer, Refunds, or Dispense Tracking when those special workflows are needed.'),
                xl('When the cart is correct and all required items are entered, click Proceed to Payment to finish checkout.'),
                xl('After payment, confirm the transaction details so DCR, receipts, dispense tracking, and patient balances reflect the correct scenario.')
            ],
            xl('If you want, ask me next: "what is dispense tracking", "why is weight required", or "how should administered versus shipped items be entered".')
        );
    }

    if (($context['area'] ?? '') === 'pos' && assistantKeywordMatch($normalized, ['weight required', 'record weight', 'why weight', 'weight recording'])) {
        return assistantBuildReply(
            xl('Weight Recording'),
            [
                xl('If the POS screen shows Weight Recording Required, staff must enter the patient weight before checkout can continue.'),
                xl('Type the weight in pounds into the weight field and click Record Weight.'),
                xl('This is especially important for medication workflows where weight tracking is required before payment or administration.')
            ]
        );
    }

    if (assistantKeywordMatch($normalized, ['pos', 'checkout', 'dispense', 'administer', 'payment'])) {
        return assistantBuildReply(
            xl('POS Workflow'),
            [
                xl('Open POS from the POS menu or from patient finder actions.'),
                xl('Use dispense tracking for medication fulfillment and administering workflows.'),
                xl('Use transaction history to review receipts, same-day activity, and void eligibility.')
            ],
            xl('You can ask "how do I open POS for a patient" or "how does backdate work".')
        );
    }

    if (assistantKeywordMatch($normalized, ['appointment', 'calendar', 'schedule', 'visit'])) {
        return assistantBuildReply(
            xl('Scheduling Workflow'),
            [
                xl('I can show upcoming or recent appointments for a patient.'),
                xl('Try "appointments for John Smith", "next appointment for patient 42", or "recent appointments for John Smith".'),
                xl('Use Calendar from the Visits menu when you need to create or manage appointments.')
            ]
        );
    }

    if (assistantKeywordMatch($normalized, ['export', 'import', 'spreadsheet', 'csv'])) {
        return assistantBuildReply(
            xl('Import and Export'),
            [
                xl('Patient export is available from the finder toolbar.'),
                xl('Patient import uses a fixed spreadsheet template and sample file.'),
                xl('Export columns should mirror the import template for round-trip use.')
            ]
        );
    }

    return assistantBuildContextualStaffReply($context);
}

function assistantTryConversationSupport(string $message, string $normalized, array $context = []): string
{
    if (assistantKeywordMatch($normalized, ['what can you do', 'how can you help', 'help me', 'help', 'capabilities'])) {
        return assistantBuildReply(
            xl('How jacki Can Help'),
            [
                xl('I can answer live questions about revenue, deposits, gross patient count, shot tracker, revenue by medicine, and DCR by facility.'),
                xl('I can look up patients, appointments, bloodwork, consultations, inventory, balances, visit lists, and patient counts by location when that data is available.'),
                xl('I can also explain POS, DCR, patient import, inventory, and scheduling workflows in plain language.'),
                assistantBuildContextPromptLine($context),
            ],
            xl('Try asking one concrete question at a time, like "today revenue", "gross patient count yesterday", "today patient visit list", or "find patient John Smith".')
        );
    }

    if (assistantKeywordMatch($normalized, ['who are you', 'what are you', 'are you ai', 'are you a bot', 'what is jacki'])) {
        return assistantBuildReply(
            xl('About jacki'),
            [
                xl('I am your OpenEMR workflow assistant for staff.'),
                xl('I help with clinic workflows, live report questions, patient lookups, and system guidance inside OpenEMR.'),
                xl('For live facts like revenue, patients, appointments, and inventory, I use OpenEMR data first.')
            ]
        );
    }

    if (assistantKeywordMatch($normalized, ['thank you', 'thanks', 'thx'])) {
        return assistantBuildReply(
            xl('jacki'),
            [
                xl('Happy to help.'),
                assistantBuildContextPromptLine($context)
            ]
        );
    }

    if (assistantIsGreetingMessage($normalized) || assistantIsCasualConversationMessage($normalized)) {
        return assistantBuildReply(
            xl('jacki'),
            [
                sprintf(xl('I am here and ready to help with %s.'), strtolower(assistantContextLabel((string)($context['area'] ?? 'general')))),
                xl('I can answer quick workflow questions, pull live operational numbers, and help you get to the right OpenEMR screen.'),
                assistantBuildContextPromptLine($context),
            ]
        );
    }

    return '';
}

function assistantBuildContextualStaffReply(array $context = []): string
{
    $patientId = (int)($context['patient_id'] ?? 0);
    $reportName = trim((string)($context['report_name'] ?? ''));
    $posState = trim((string)($context['pos_state'] ?? ''));

    switch ($context['area'] ?? 'general') {
        case 'pos':
            return assistantBuildReply(
                xl('POS Workflow'),
                [
                    $patientId > 0
                        ? sprintf(xl('You opened jacki from POS for patient %d, so I can help with checkout, payments, backdating, remaining dispense, and administering workflow questions.'), $patientId)
                        : xl('You opened jacki from POS, so I can help with checkout, payments, backdating, remaining dispense, and administering workflow questions.'),
                    $posState !== ''
                        ? sprintf(xl('The current POS state looks closest to %s, so I can focus on that part of the workflow.'), $posState)
                        : xl('I can focus on payment, checkout, shipped versus administered logic, and DCR-related POS behavior.'),
                    xl('Try asking why a payment failed, how backdating should affect DCR, or how shipped versus administered items should be documented.'),
                    xl('I can also help explain what the system should count on receipts, DCR, and related POS flows.')
                ]
            );
        case 'scheduling':
            return assistantBuildReply(
                xl('Scheduling Workflow'),
                [
                    xl('You opened jacki from scheduling, so I can help with appointments, rescheduling, calendar workflows, and visit lookups.'),
                    xl('Try asking for appointments for a patient, next visits, or calendar workflow steps.')
                ]
            );
        case 'reports':
            return assistantBuildReply(
                xl('Reports Workflow'),
                [
                    $reportName !== ''
                        ? sprintf(xl('You opened jacki from the %s report, so I can help explain report behavior, count mismatches, and workflow logic for that report.'), $reportName)
                        : xl('You opened jacki from reports, so I can help explain DCR behavior, daily collections, count mismatches, and report workflow logic.'),
                    xl('Try asking why a count looks wrong, what today revenue is, what the deposit tab means, or how a POS scenario should appear on DCR.')
                ]
            );
        case 'patients':
            return assistantBuildReply(
                xl('Patient Workflow'),
                [
                    $patientId > 0
                        ? sprintf(xl('You opened jacki from patient work for patient %d, so I can help with chart-related questions, visits, appointments, and demographics.'), $patientId)
                        : xl('You opened jacki from patient work, so I can help with finding patients, addresses, encounter history, and chart-related questions.'),
                    xl('Try asking for recent visits, appointments, or patient search help.')
                ]
            );
        case 'inventory':
            return assistantBuildReply(
                xl('Inventory Workflow'),
                [
                    xl('You opened jacki from inventory, so I can help with stock, lots, expired medicines, and lot-based lookup questions.'),
                    xl('Try asking about low stock, expired medicines, or a specific drug or lot.')
                ]
            );
        default:
            return assistantBuildReply(
                xl('Staff Assistant'),
                [
                    xl('I can answer live revenue and DCR questions, search patients, and explain OpenEMR workflows.'),
                    xl('I can show patient addresses, appointments, bloodwork, consultations, balances, inventory by drug or lot, and patient counts by location.'),
                    xl('Try: "today revenue", "gross patient count today", "today patient visit list", "appointments for John Smith", or "show expired medicines".')
                ]
            );
    }
}

function assistantBuildContextPromptLine(array $context = []): string
{
    switch ($context['area'] ?? 'general') {
        case 'reports':
            return xl('From here, try "today revenue", "today deposits", "gross patient count today", or "revenue for yesterday".');
        case 'pos':
            return xl('From here, try "patient balance", "why did payment fail", or "how should this appear on DCR".');
        case 'patients':
            return xl('From here, try "recent visits", "appointments", "patient address", or "today patient visit list".');
        case 'inventory':
            return xl('From here, try "expired medicines", "tirz stock", or "lot K001".');
        case 'scheduling':
            return xl('From here, try "appointments for John Smith", "today patient visit list", or "how do I reschedule this patient".');
        default:
            return xl('Try asking "what can you do", "today revenue", "patients by location", or "find patient John Smith".');
    }
}

function assistantTryWorkflowExplanation(string $normalized): string
{
    if (assistantKeywordMatch($normalized, ['tirzepatide price', 'tirzepatide pricing', 'tirzepatide dose', 'tirzepatide injection price', 'tirzepatide cost'])) {
        return assistantBuildReply(
            xl('Tirzepatide Pricing'),
            [
                xl('Tirzepatide is priced per injection based on the selected dose in POS.'),
                '2.5 mg = $74.75, 5 mg = $87.25, 7.5 mg = $99.75, 10 mg = $112.25, 12.5 mg = $124.75, 15 mg = $137.25.',
                xl('When staff change the tirzepatide dose dropdown in the cart, the line-item price and order total update automatically.')
            ],
            xl('If the amount on screen does not match the selected dose, refresh the cart item and recheck the dose selection before checkout.')
        );
    }

    if (
        assistantKeywordMatch($normalized, ['daily revenue', 'daily totals', 'daily sales', 'total revenue today'])
        || (
            assistantKeywordMatch($normalized, ['revenue'])
            && assistantKeywordMatch($normalized, ['daily', 'today', 'dcr'])
        )
    ) {
        return assistantBuildReply(
            xl('Daily Revenue in DCR'),
            [
                xl('Daily revenue in the enhanced DCR comes from finalized POS transaction activity for the selected date range and facility filters.'),
                xl('The Deposit tab separates cash, checks, and credit, then shows total revenue as cash plus checks plus credit for each day.'),
                xl('Actual bank deposit is cash plus checks, while credit still counts toward revenue but not toward the physical bank deposit amount.'),
                xl('If revenue looks wrong, check the selected dates, facility, payment method filters, and whether a transaction was voided or entered under a different POS workflow.')
            ],
            xl('Ask me next: "what does the deposit tab mean", "why is DCR count off", or "how should a POS transaction appear on DCR".')
        );
    }

    if (assistantKeywordMatch($normalized, ['deposit tab', 'deposit report', 'bank deposit', 'actual bank deposit', 'cash checks credit'])) {
        return assistantBuildReply(
            xl('DCR Deposit Tab'),
            [
                xl('The Deposit tab summarizes daily collections by payment method from POS transactions.'),
                xl('Cash and checks roll into actual bank deposit, while credit is tracked separately and still contributes to total revenue.'),
                xl('Subtotal credit plus checks and total revenue are calculated from the filtered transaction set for the selected date range.'),
                xl('If the bank deposit does not match expectations, compare the POS payment methods used on the original receipts and look for same-day voids or refunds.')
            ]
        );
    }

    if (assistantKeywordMatch($normalized, ['revenue breakdown', 'medicine revenue', 'revenue by medicine', 'revenue tab'])) {
        return assistantBuildReply(
            xl('DCR Revenue Breakdown'),
            [
                xl('The Revenue Breakdown tab groups finalized POS activity into medicine and workflow categories so staff can see where revenue came from.'),
                xl('It is meant to explain totals by treatment category, not just show one grand total.'),
                xl('Differences usually come from item categorization, transaction type, facility filter, or whether a transaction was voided, refunded, or backdated.'),
                xl('When troubleshooting, compare the receipt items, quantities, lot usage, and POS transaction type against the day and facility shown in DCR.')
            ]
        );
    }

    if (assistantKeywordMatch($normalized, ['shot tracker', 'shots tab', 'injection count', 'administered shots'])) {
        return assistantBuildReply(
            xl('DCR Shot Tracker'),
            [
                xl('Shot Tracker summarizes administered treatment activity by day so staff can reconcile operational injection counts with POS activity.'),
                xl('It depends on how items were entered in POS, especially dispense versus administer quantities and the final transaction type.'),
                xl('If a shot count looks wrong, review whether the item was sold as take-home, administered in clinic, or later voided or backdated.'),
                xl('Consistent POS entry is what makes Shot Tracker line up with what the clinic actually gave the patient.')
            ]
        );
    }

    if (assistantKeywordMatch($normalized, ['gross patient count', 'patient count report', 'new vs returning', 'unique patients in dcr'])) {
        return assistantBuildReply(
            xl('DCR Patient Counts'),
            [
                xl('The patient count views are driven by the filtered POS activity in the report range, not by a general chart census.'),
                xl('Counts can vary based on date range, facility, report filters, and whether the same patient had multiple transactions.'),
                xl('New versus returning depends on the report logic for first POS activity and timing, so the same patient may appear differently across different ranges.'),
                xl('When a count feels off, first confirm the report filters and then compare the patient transactions included for that day.')
            ]
        );
    }

    if (assistantKeywordMatch($normalized, ['dcr', 'daily collection report', 'daily collections'])) {
        return assistantBuildReply(
            xl('DCR Workflow'),
            [
                xl('DCR stands for Daily Collection Report.'),
                xl('Completed POS transactions feed DCR totals, including patient DCR, gross patient count, deposit, revenue breakdown, and shot tracker views.'),
                xl('DCR reflects finalized activity, so staff should complete checkout correctly and use same-day voids when a transaction must be reversed.'),
                xl('When numbers do not match expectations, the first things to verify are date range, facility, payment method, transaction type, and whether the transaction was voided, refunded, or backdated.')
            ],
            xl('Use the DCR report to review what was collected for the day and reconcile POS activity with operational reporting.')
        );
    }

    if (assistantKeywordMatch($normalized, ['remaining dispense', 'what is remaining dispense', 'how does remaining dispense work'])) {
        return assistantBuildReply(
            xl('Remaining Dispense Workflow'),
            [
                xl('Remaining dispense tracks product that a patient has already paid for but has not fully used yet.'),
                xl('When the same medicine is added again in POS, the system can pull those remaining quantities into the cart and apply them before or alongside new inventory.'),
                xl('This helps staff continue a patient plan without losing track of prior dispensed amounts.')
            ],
            xl('Dispense Tracking is the best place to review what a patient still has remaining.')
        );
    }

    if (
        assistantKeywordMatch($normalized, ['how does inventory work', 'inventory workflow', 'how inventory works']) ||
        (assistantKeywordMatch($normalized, ['inventory']) && assistantKeywordMatch($normalized, ['how', 'work']))
    ) {
        return assistantBuildReply(
            xl('Inventory Workflow'),
            [
                xl('Inventory search in POS shows available products, lots, and quantity on hand so staff can choose what is in stock.'),
                xl('After checkout, the system updates inventory movement, patient transaction history, receipts, and remaining-dispense records as needed.'),
                xl('Drug Inventory should be used for receiving stock, reviewing lots, checking expirations, and making adjustments outside the POS sale flow.'),
                xl('Defective medicines should be reported through inventory by lot number so stock and audit history remain accurate.')
            ],
            xl('You can ask me specific inventory questions too, like "inventory semaglutide", "lot T002", or "show low stock medicines".')
        );
    }

    if (
        assistantKeywordMatch($normalized, ['how does pos work', 'pos workflow', 'checkout workflow']) ||
        (assistantKeywordMatch($normalized, ['pos', 'checkout', 'dispense', 'administer']) && assistantKeywordMatch($normalized, ['how', 'work']))
    ) {
        return assistantBuildReply(
            xl('POS Workflow'),
            [
                xl('Search for the product, add it to the cart, and confirm the lot, quantity, dispense amount, and administer amount.'),
                xl('Quantity is the amount being purchased now, Dispense is what is sent home or dispensed, and Administer is what is given in clinic.'),
                xl('For injection workflows, staff should record the patient weight when required before checkout.'),
                xl('Completing checkout updates the receipt, transaction history, inventory, and remaining-dispense tracking.')
            ],
            xl('If you tell me what part of POS you want help with, I can explain that step more specifically.')
        );
    }

    return '';
}

function assistantTryRevenueLookup(string $message, string $normalized): string
{
    if (!assistantShouldAttemptRevenueLookup($message, $normalized)) {
        return '';
    }

    $request = assistantBuildRevenueRequest($message, $normalized);
    if (empty($request['range']['start']) || empty($request['range']['end'])) {
        return assistantBuildReply(
            xl('Revenue Lookup'),
            [
                xl('Tell me a date or period, for example: "today revenue", "yesterday revenue", "revenue for 2026-03-24", "March 2026 revenue", or "compare today vs yesterday revenue".')
            ],
            xl('I can pull finalized POS revenue for days, months, payment methods, facilities, comparisons, and daily breakdowns.')
        );
    }

    if (!assistantTableExists('pos_transactions')) {
        return assistantBuildReply(
            xl('Revenue Lookup'),
            [xl('The POS transactions table is not available, so I cannot calculate revenue right now.')],
            xl('This lookup depends on finalized POS transaction data.')
        );
    }

    if (!empty($request['compare'])) {
        return assistantBuildRevenueComparisonReply($request);
    }

    $summary = assistantFetchRevenueSummary($request['range'], $request['filters']);
    if (((int)($summary['transaction_count'] ?? 0)) === 0) {
        return assistantBuildReply(
            xl('Revenue Lookup'),
            [
                sprintf(
                    xl('I did not find finalized POS revenue between %s and %s.'),
                    assistantFormatAssistantDate($request['range']['start']),
                    assistantFormatAssistantDate($request['range']['end'])
                )
            ],
            xl('Try another date, or check whether the transactions were voided, backdated, or posted outside that period.')
        );
    }

    $footerParts = [xl('Showing finalized POS revenue for the selected period.')];
    if (!empty($request['filters']['payment_method_label'])) {
        $footerParts[] = xl('Payment method filter') . ': ' . $request['filters']['payment_method_label'] . '.';
    }
    if (!empty($request['filters']['facility_name'])) {
        $footerParts[] = xl('Facility filter') . ': ' . $request['filters']['facility_name'] . '.';
    }
    $footerParts[] = xl('Voided transactions are excluded when the schema supports void tracking.');

    $bullets = [
        xl('Period') . ': ' . assistantFormatRevenuePeriodLabel($request['range']['start'], $request['range']['end'], (string)($request['range']['kind'] ?? 'range')),
        xl('Total Revenue') . ': $' . number_format((float)$summary['total_revenue'], 2),
        xl('Transactions') . ': ' . (int)$summary['transaction_count'],
    ];

    if ((float)$summary['cash_revenue'] > 0) {
        $bullets[] = xl('Cash') . ': $' . number_format((float)$summary['cash_revenue'], 2);
    }
    if ((float)$summary['check_revenue'] > 0) {
        $bullets[] = xl('Checks') . ': $' . number_format((float)$summary['check_revenue'], 2);
    }
    if ((float)$summary['card_revenue'] > 0) {
        $bullets[] = xl('Card / Credit') . ': $' . number_format((float)$summary['card_revenue'], 2);
    }
    if ((float)$summary['other_revenue'] > 0) {
        $bullets[] = xl('Other / Unmapped') . ': $' . number_format((float)$summary['other_revenue'], 2);
    }

    if (!empty($request['want_breakdown']) && ($request['range']['kind'] ?? '') === 'month') {
        $dailyRows = assistantFetchRevenueDailyBreakdown($request['range'], $request['filters']);
        $dailyLines = [];
        foreach ($dailyRows as $row) {
            $dailyLines[] = assistantFormatAssistantDate((string)$row['revenue_date']) . ' | $' . number_format((float)($row['total_revenue'] ?? 0), 2) . ' | ' . xl('Transactions') . ' ' . (int)($row['transaction_count'] ?? 0);
        }
        $bullets = array_merge($bullets, array_slice($dailyLines, 0, 31));
    }

    return assistantBuildReply(
        xl('Revenue Lookup'),
        $bullets,
        implode(' ', $footerParts)
    );
}

function assistantTryDcrMetricLookup(string $message, string $normalized): string
{
    if (!assistantShouldAttemptDcrMetricLookup($normalized)) {
        return '';
    }

    $request = assistantBuildRevenueRequest($message, $normalized);
    if (empty($request['range']['start']) || empty($request['range']['end'])) {
        return assistantBuildReply(
            xl('DCR Lookup'),
            [
                xl('Tell me a day or period, for example: "today deposits", "gross patient count yesterday", "shot tracker March 2026", "revenue by medicine this month", or "DCR by facility for 2026-03-24".')
            ],
            xl('I can pull DCR-style POS metrics for deposits, patient counts, shot tracker, medicine revenue, and facility rollups.')
        );
    }

    if (!assistantTableExists('pos_transactions')) {
        return assistantBuildReply(
            xl('DCR Lookup'),
            [xl('The POS transactions table is not available, so I cannot calculate DCR metrics right now.')],
            xl('This lookup depends on finalized POS transaction data.')
        );
    }

    if (assistantKeywordMatch($normalized, ['deposit', 'deposits', 'bank deposit'])) {
        return assistantBuildDepositLookupReply($request);
    }

    if (
        (
            assistantKeywordMatch($normalized, ['gross patient count', 'gross count', 'patient count', 'unique patients', 'new patients', 'returning patients'])
            || assistantKeywordMatch($normalized, ['patients count', 'patient total', 'patients total'])
        )
        && !assistantKeywordMatch($normalized, ['revenue by medicine', 'medicine revenue'])
    ) {
        return assistantBuildGrossPatientCountReply($request);
    }

    if (assistantKeywordMatch($normalized, ['shot tracker', 'shot count', 'lipo cards', 'sg trz', 'sg/trz'])) {
        return assistantBuildShotTrackerReply($request);
    }

    if (assistantKeywordMatch($normalized, ['revenue by medicine', 'medicine revenue', 'medicine breakdown', 'drug revenue', 'revenue by drug'])) {
        return assistantBuildMedicineRevenueReply($request);
    }

    if (assistantKeywordMatch($normalized, ['dcr by facility', 'revenue by facility', 'facility breakdown', 'facility revenue'])) {
        return assistantBuildFacilityDcrReply($request);
    }

    return '';
}

function assistantTryClinicQuestionLookup(string $message, string $normalized, array $context = []): string
{
    $request = assistantBuildRevenueRequest($message, $normalized);

    $reply = assistantTryCashMetricLookup($normalized, $request);
    if ($reply !== '') {
        return $reply;
    }

    $reply = assistantTryShotCardMetricLookup($message, $normalized, $request);
    if ($reply !== '') {
        return $reply;
    }

    $reply = assistantTryMedicineRevenueMetricLookup($message, $normalized, $request);
    if ($reply !== '') {
        return $reply;
    }

    $reply = assistantTryPatientSpendLookup($message, $normalized, $context, $request);
    if ($reply !== '') {
        return $reply;
    }

    $reply = assistantTryPatientVisitTypeLookup($message, $normalized, $context);
    if ($reply !== '') {
        return $reply;
    }

    $reply = assistantTryPatientLastShotLookup($message, $normalized, $context);
    if ($reply !== '') {
        return $reply;
    }

    $reply = assistantTryPatientMedicationLookup($message, $normalized, $context);
    if ($reply !== '') {
        return $reply;
    }

    $reply = assistantTryPatientWeightLookup($message, $normalized, $context);
    if ($reply !== '') {
        return $reply;
    }

    $reply = assistantTryPatientRemainingInjectionLookup($message, $normalized, $context);
    if ($reply !== '') {
        return $reply;
    }

    $reply = assistantTryInventoryQuantityLookup($message, $normalized);
    if ($reply !== '') {
        return $reply;
    }

    return '';
}

function assistantTryDirectClinicMetricFallback(string $message, string $normalized): string
{
    $request = assistantBuildRevenueRequest($message, $normalized);
    if (empty($request['range']['start']) || empty($request['range']['end']) || !assistantTableExists('pos_transactions')) {
        return '';
    }

    if (assistantKeywordMatch($normalized, ['cash']) && assistantKeywordMatch($normalized, ['how much', 'cash was', 'cash on'])) {
        $summary = assistantFetchRevenueSummary($request['range'], $request['filters']);
        return assistantBuildReply(
            xl('Cash Lookup'),
            [
                xl('Period') . ': ' . assistantFormatRevenuePeriodLabel($request['range']['start'], $request['range']['end'], (string)($request['range']['kind'] ?? 'range')),
                xl('Cash') . ': $' . number_format((float)($summary['cash_revenue'] ?? 0), 2),
            ],
            xl('Cash is calculated from finalized non-void POS transactions whose payment method contains cash.')
        );
    }

    if (assistantKeywordMatch($normalized, ['shot cards', 'shot card']) && assistantKeywordMatch($normalized, ['how many', 'sold'])) {
        $rows = assistantFetchRevenueTransactions($request['range'], $request['filters']);
        $metrics = assistantCalculateShotTrackerMetrics($rows);
        return assistantBuildReply(
            xl('Shot Tracker'),
            [
                xl('Period') . ': ' . assistantFormatRevenuePeriodLabel($request['range']['start'], $request['range']['end'], (string)($request['range']['kind'] ?? 'range')),
                xl('SG/TRZ Total Cards') . ': ' . (int)$metrics['sg_trz_cards'],
            ],
            xl('Shot cards here mean the combined semaglutide and tirzepatide DCR card count.')
        );
    }

    return '';
}

function assistantTryCashMetricLookup(string $normalized, array $request): string
{
    if (!assistantKeywordMatch($normalized, ['cash'])) {
        return '';
    }

    if (empty($request['range']['start']) || empty($request['range']['end']) || !assistantTableExists('pos_transactions')) {
        return '';
    }

    $summary = assistantFetchRevenueSummary($request['range'], $request['filters']);
    return assistantBuildReply(
        xl('Cash Lookup'),
        [
            xl('Period') . ': ' . assistantFormatRevenuePeriodLabel($request['range']['start'], $request['range']['end'], (string)($request['range']['kind'] ?? 'range')),
            xl('Cash') . ': $' . number_format((float)($summary['cash_revenue'] ?? 0), 2),
            xl('Total Revenue') . ': $' . number_format((float)($summary['total_revenue'] ?? 0), 2),
        ],
        xl('Cash is calculated from finalized non-void POS transactions whose payment method contains cash.')
    );
}

function assistantTryShotCardMetricLookup(string $message, string $normalized, array $request): string
{
    if (
        !assistantKeywordMatch($normalized, ['shot cards', 'shot card', 'lipo cards', 'lipo card'])
        || empty($request['range']['start'])
        || empty($request['range']['end'])
    ) {
        return '';
    }

    $rows = assistantFetchRevenueTransactions($request['range'], $request['filters']);
    $metrics = assistantCalculateShotTrackerMetrics($rows);
    $period = assistantFormatRevenuePeriodLabel($request['range']['start'], $request['range']['end'], (string)($request['range']['kind'] ?? 'range'));

    if (assistantKeywordMatch($normalized, ['lipo cards', 'lipo card'])) {
        return assistantBuildReply(
            xl('Shot Tracker'),
            [
                xl('Period') . ': ' . $period,
                xl('LIPO Total Cards') . ': ' . (int)$metrics['lipo_cards'],
                xl('LIPO Total Units') . ': ' . (int)$metrics['lipo_units'],
            ],
            xl('LIPO cards use the DCR 4-plus-1-free cycle, so cards are sold quantities divided by 5.')
        );
    }

    return assistantBuildReply(
        xl('Shot Tracker'),
        [
            xl('Period') . ': ' . $period,
            xl('SG/TRZ Total Cards') . ': ' . (int)$metrics['sg_trz_cards'],
            xl('SG/TRZ Total Units') . ': ' . (int)$metrics['sg_trz_units'],
        ],
        xl('Shot cards here mean the combined semaglutide and tirzepatide DCR card count.')
    );
}

function assistantTryMedicineRevenueMetricLookup(string $message, string $normalized, array $request): string
{
    if (
        !assistantKeywordMatch($normalized, ['revenue'])
        || empty($request['range']['start'])
        || empty($request['range']['end'])
    ) {
        return '';
    }

    $medicineKeys = assistantExtractTrackedMedicineKeys($normalized);
    if (count($medicineKeys) < 1) {
        return '';
    }

    $rows = assistantFetchRevenueTransactions($request['range'], $request['filters']);
    $stats = assistantAggregateMedicineStats($rows);
    $combinedRevenue = 0.0;
    $combinedQuantity = 0;
    $combinedItems = 0;
    $labels = [];

    foreach ($medicineKeys as $medicineKey) {
        $labels[] = strtoupper($medicineKey === 'lipo' ? 'LIPO' : $medicineKey);
        foreach ($stats as $label => $row) {
            if (assistantItemMatchesMedicineKey($label, $medicineKey)) {
                $combinedRevenue += (float)($row['revenue'] ?? 0);
                $combinedQuantity += (int)($row['quantity'] ?? 0);
                $combinedItems += (int)($row['count'] ?? 0);
            }
        }
    }

    if ($combinedRevenue <= 0 && $combinedQuantity <= 0 && $combinedItems <= 0) {
        return '';
    }

    return assistantBuildReply(
        xl('Revenue by Medicine'),
        [
            xl('Period') . ': ' . assistantFormatRevenuePeriodLabel($request['range']['start'], $request['range']['end'], (string)($request['range']['kind'] ?? 'range')),
            xl('Medicine') . ': ' . implode(' + ', $labels),
            xl('Combined Revenue') . ': $' . number_format($combinedRevenue, 2),
            xl('Quantity') . ': ' . $combinedQuantity,
            xl('Line Items') . ': ' . $combinedItems,
        ],
        xl('This sums POS item revenue for the requested medicine names in the selected period.')
    );
}

function assistantTryPatientSpendLookup(string $message, string $normalized, array $context, array $request): string
{
    if (!assistantKeywordMatch($normalized, ['spend on', 'spent on', 'spend', 'spent', 'pay on', 'paid on'])) {
        return '';
    }

    if (empty($request['range']['start']) || empty($request['range']['end'])) {
        return '';
    }

    $resolved = assistantResolveSinglePatientForLookup($message, $normalized, $context, xl('Patient Spend Lookup'));
    if ($resolved['reply'] !== '') {
        return $resolved['reply'];
    }

    $patient = $resolved['patient'];
    $row = sqlQuery(
        "SELECT COUNT(*) AS transaction_count, COALESCE(SUM(amount), 0) AS total_spent
         FROM pos_transactions
         WHERE pid = ?
           AND created_date >= ?
           AND created_date < ?
           AND transaction_type != 'void'" . (assistantPosTransactionsHaveVoidColumns() ? " AND COALESCE(voided, 0) = 0" : ''),
        [
            (int)$patient['pid'],
            $request['range']['start'] . ' 00:00:00',
            (new DateTime($request['range']['end']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
        ]
    );

    return assistantBuildReply(
        xl('Patient Spend Lookup'),
        [
            assistantFormatPatientLabel($patient),
            xl('Period') . ': ' . assistantFormatRevenuePeriodLabel($request['range']['start'], $request['range']['end'], (string)($request['range']['kind'] ?? 'range')),
            xl('Total Spent') . ': $' . number_format((float)($row['total_spent'] ?? 0), 2),
            xl('Transactions') . ': ' . (int)($row['transaction_count'] ?? 0),
        ],
        xl('This uses finalized non-void POS transactions for the selected patient and date range.')
    );
}

function assistantTryPatientVisitTypeLookup(string $message, string $normalized, array $context): string
{
    $isFollowUp = assistantKeywordMatch($normalized, ['last follow up', 'last follow-up', 'follow up visit', 'follow-up visit']);
    $isNewVisit = assistantKeywordMatch($normalized, ['new patient visit', 'last new patient', 'new visit']);
    if (!$isFollowUp && !$isNewVisit) {
        return '';
    }

    $resolved = assistantResolveSinglePatientForLookup($message, $normalized, $context, $isFollowUp ? xl('Follow-Up Lookup') : xl('New Patient Visit Lookup'));
    if ($resolved['reply'] !== '') {
        return $resolved['reply'];
    }

    $visitType = $isFollowUp ? 'F' : 'N';
    $title = $isFollowUp ? xl('Follow-Up Lookup') : xl('New Patient Visit Lookup');
    $label = $isFollowUp ? xl('Last Follow-Up') : xl('Last New Patient Visit');
    $row = sqlQuery(
        "SELECT created_date, receipt_number
         FROM pos_transactions
         WHERE pid = ?
           AND visit_type = ?
           AND transaction_type != 'void'" . (assistantPosTransactionsHaveVoidColumns() ? " AND COALESCE(voided, 0) = 0" : '') . "
         ORDER BY created_date DESC, id DESC
         LIMIT 1",
        [(int)$resolved['patient']['pid'], $visitType]
    );

    if (!is_array($row) || empty($row['created_date'])) {
        return assistantBuildReply(
            $title,
            [assistantFormatPatientLabel($resolved['patient'])],
            xl('I did not find a matching POS visit of that type for this patient.')
        );
    }

    return assistantBuildReply(
        $title,
        [
            assistantFormatPatientLabel($resolved['patient']),
            $label . ': ' . assistantFormatAssistantDateTime((string)$row['created_date']),
            xl('Receipt') . ': ' . (string)($row['receipt_number'] ?? '-'),
        ],
        xl('Visit type comes from the POS New / Follow-Up selection saved with the transaction.')
    );
}

function assistantTryPatientLastShotLookup(string $message, string $normalized, array $context): string
{
    if (!assistantKeywordMatch($normalized, ['last shot', 'last injection', 'recent shot', 'recent injection'])) {
        return '';
    }

    $resolved = assistantResolveSinglePatientForLookup($message, $normalized, $context, xl('Shot History Lookup'));
    if ($resolved['reply'] !== '') {
        return $resolved['reply'];
    }

    $rows = assistantFetchRows(
        "SELECT id, created_date, receipt_number, transaction_type, items
         FROM pos_transactions
         WHERE pid = ?
           AND transaction_type != 'void'" . (assistantPosTransactionsHaveVoidColumns() ? " AND COALESCE(voided, 0) = 0" : '') . "
         ORDER BY created_date DESC, id DESC
         LIMIT 50",
        [(int)$resolved['patient']['pid']]
    );

    foreach ($rows as $row) {
        $administered = assistantExtractAdministeredItemsFromTransaction((string)($row['items'] ?? ''));
        if (empty($administered)) {
            continue;
        }

        return assistantBuildReply(
            xl('Shot History Lookup'),
            [
                assistantFormatPatientLabel($resolved['patient']),
                xl('Last Shot') . ': ' . assistantFormatAssistantDateTime((string)$row['created_date']),
                xl('Medicines') . ': ' . implode(', ', $administered),
                xl('Receipt') . ': ' . (string)($row['receipt_number'] ?? '-'),
            ],
            xl('This comes from the most recent non-void POS transaction with administered quantity greater than zero.')
        );
    }

    return assistantBuildReply(
        xl('Shot History Lookup'),
        [assistantFormatPatientLabel($resolved['patient'])],
        xl('I did not find any administered shot history for this patient.')
    );
}

function assistantTryPatientMedicationLookup(string $message, string $normalized, array $context): string
{
    if (
        !assistantKeywordMatch($normalized, ['medication', 'medications', 'mediciation', 'what medication', 'what medications', 'what med', 'what meds', 'medicine', 'medicines'])
        || !assistantKeywordMatch($normalized, [' on', ' is ', ' are ', 'patient', 'what'])
    ) {
        return '';
    }

    $resolved = assistantResolveSinglePatientForLookup($message, $normalized, $context, xl('Patient Medication Lookup'));
    if ($resolved['reply'] !== '') {
        return $resolved['reply'];
    }

    $bullets = [assistantFormatPatientLabel($resolved['patient'])];

    $rxRows = assistantFetchRows(
        "SELECT drug, dosage, quantity, route, date_added, date_modified
         FROM prescriptions
         WHERE patient_id = ?
           AND active = 1
         ORDER BY COALESCE(date_modified, date_added) DESC, id DESC
         LIMIT 10",
        [(int)$resolved['patient']['pid']]
    );

    foreach ($rxRows as $row) {
        $parts = [trim((string)($row['drug'] ?? xl('Unknown medication')))];
        if (!empty($row['dosage'])) {
            $parts[] = xl('Dosage') . ' ' . $row['dosage'];
        }
        if (!empty($row['quantity'])) {
            $parts[] = xl('Qty') . ' ' . $row['quantity'];
        }
        if (!empty($row['route'])) {
            $parts[] = xl('Route') . ' ' . $row['route'];
        }
        $bullets[] = implode(' | ', $parts);
    }

    if (count($bullets) === 1) {
        $remainingRows = assistantFetchRows(
            "SELECT d.name, SUM(prd.remaining_quantity) AS remaining_quantity
             FROM pos_remaining_dispense AS prd
             JOIN drugs AS d ON d.drug_id = prd.drug_id
             WHERE prd.pid = ?
               AND prd.remaining_quantity > 0
             GROUP BY prd.drug_id, d.name
             ORDER BY d.name ASC",
            [(int)$resolved['patient']['pid']]
        );
        foreach ($remainingRows as $row) {
            $bullets[] = trim((string)$row['name']) . ' | ' . xl('Remaining') . ' ' . assistantFormatInventoryQuantity($row['remaining_quantity'] ?? '0');
        }
    }

    if (count($bullets) === 1) {
        return assistantBuildReply(
            xl('Patient Medication Lookup'),
            $bullets,
            xl('I did not find active prescriptions or remaining medication inventory for this patient.')
        );
    }

    return assistantBuildReply(
        xl('Patient Medication Lookup'),
        $bullets,
        xl('Showing active prescriptions first, with remaining POS medication as a fallback when prescriptions are not present.')
    );
}

function assistantTryPatientWeightLookup(string $message, string $normalized, array $context): string
{
    if (!assistantKeywordMatch($normalized, ['last weight', 'latest weight', 'recent weight', 'current weight'])) {
        return '';
    }

    $resolved = assistantResolveSinglePatientForLookup($message, $normalized, $context, xl('Weight Lookup'));
    if ($resolved['reply'] !== '') {
        return $resolved['reply'];
    }

    $row = sqlQuery(
        "SELECT date, weight
         FROM form_vitals
         WHERE pid = ?
           AND weight > 0
           AND COALESCE(activity, 1) = 1
         ORDER BY date DESC, id DESC
         LIMIT 1",
        [(int)$resolved['patient']['pid']]
    );

    if (!is_array($row) || empty($row['date'])) {
        return assistantBuildReply(
            xl('Weight Lookup'),
            [assistantFormatPatientLabel($resolved['patient'])],
            xl('I did not find a weight entry for this patient.')
        );
    }

    return assistantBuildReply(
        xl('Weight Lookup'),
        [
            assistantFormatPatientLabel($resolved['patient']),
            xl('Last Weight') . ': ' . assistantFormatInventoryQuantity($row['weight'] ?? '0') . ' lb',
            xl('Recorded') . ': ' . assistantFormatAssistantDateTime((string)$row['date']),
        ],
        xl('This uses the most recent saved vitals entry with a non-zero weight.')
    );
}

function assistantTryPatientRemainingInjectionLookup(string $message, string $normalized, array $context): string
{
    if (!assistantKeywordMatch($normalized, ['injections left', 'shots left', 'remaining injections', 'remaining shots', 'left on file'])) {
        return '';
    }

    $resolved = assistantResolveSinglePatientForLookup($message, $normalized, $context, xl('Remaining Injection Lookup'));
    if ($resolved['reply'] !== '') {
        return $resolved['reply'];
    }

    $medicineKeys = assistantExtractTrackedMedicineKeys($normalized);
    $sql = "SELECT d.name, SUM(prd.remaining_quantity) AS remaining_quantity
        FROM pos_remaining_dispense AS prd
        JOIN drugs AS d ON d.drug_id = prd.drug_id
        WHERE prd.pid = ?
          AND prd.remaining_quantity > 0";
    $binds = [(int)$resolved['patient']['pid']];
    $rows = assistantFetchRows($sql . " GROUP BY prd.drug_id, d.name ORDER BY d.name ASC", $binds);

    if (!empty($medicineKeys)) {
        $rows = array_values(array_filter($rows, static function (array $row) use ($medicineKeys): bool {
            foreach ($medicineKeys as $medicineKey) {
                if (assistantItemMatchesMedicineKey((string)($row['name'] ?? ''), $medicineKey)) {
                    return true;
                }
            }
            return false;
        }));
    }

    if (empty($rows)) {
        return assistantBuildReply(
            xl('Remaining Injection Lookup'),
            [assistantFormatPatientLabel($resolved['patient'])],
            xl('I did not find any remaining injections on file for this patient.')
        );
    }

    $total = 0.0;
    $bullets = [assistantFormatPatientLabel($resolved['patient'])];
    foreach ($rows as $row) {
        $remaining = (float)($row['remaining_quantity'] ?? 0);
        $total += $remaining;
        $bullets[] = trim((string)($row['name'] ?? xl('Unknown medication'))) . ' | ' . xl('Remaining') . ' ' . assistantFormatInventoryQuantity($remaining);
    }
    $bullets[] = xl('Total Remaining') . ': ' . assistantFormatInventoryQuantity($total);

    return assistantBuildReply(
        xl('Remaining Injection Lookup'),
        $bullets,
        xl('This comes from POS remaining-dispense tracking with quantities still left on file.')
    );
}

function assistantTryInventoryQuantityLookup(string $message, string $normalized): string
{
    if (!assistantHasInventoryIntent($message, $normalized)) {
        return '';
    }

    $term = assistantExtractInventoryTerm($message, $normalized);
    if ($term === '') {
        return '';
    }

    $rows = assistantFetchInventoryDetailRows($message, $term, 200);
    if (empty($rows)) {
        return '';
    }

    if (assistantKeywordMatch($normalized, ['mg inventory', 'inventory mg', 'how much']) && assistantKeywordMatch($normalized, ['mg'])) {
        $totalMg = 0.0;
        foreach ($rows as $row) {
            $totalMg += (float)convertLiquidVialsToMg($row, $row['on_hand'] ?? 0);
        }

        return assistantBuildReply(
            xl('Inventory Lookup'),
            [
                xl('Medicine') . ': ' . trim((string)($rows[0]['name'] ?? $term)),
                xl('Total Inventory') . ': ' . formatDrugInventoryNumber($totalMg) . ' mg',
                xl('Matching Lots') . ': ' . count($rows),
            ],
            xl('For liquid medications, inventory is converted from vial quantity into total mg using the saved size and mg per mL definition.')
        );
    }

    $totalOnHand = 0.0;
    foreach ($rows as $row) {
        $totalOnHand += (float)($row['on_hand'] ?? 0);
    }

    return assistantBuildReply(
        xl('Inventory Lookup'),
        [
            xl('Item') . ': ' . trim((string)($rows[0]['name'] ?? $term)),
            xl('Total on hand') . ': ' . assistantFormatInventoryQuantity($totalOnHand),
            xl('Matching Lots') . ': ' . count($rows),
        ],
        xl('This totals the current on-hand inventory across matching active lots.')
    );
}

function assistantResolveSinglePatientForLookup(string $message, string $normalized, array $context, string $title): array
{
    $criteria = assistantExtractPatientCriteria($message, $normalized);
    if (($criteria['value'] ?? '') === '' && !empty($context['patient_id'])) {
        $criteria = ['type' => 'pid', 'value' => (string)((int)$context['patient_id'])];
    }

    if (($criteria['value'] ?? '') === '') {
        return [
            'patient' => null,
            'reply' => assistantBuildReply(
                $title,
                [xl('Tell me which patient you want, for example "Amanda Adams", "patient 42", or a phone number.')]
            ),
        ];
    }

    $rows = assistantFindPatients($criteria, 3);
    if (empty($rows)) {
        return [
            'patient' => null,
            'reply' => assistantBuildReply(
                $title,
                [xl('I could not find a matching patient in your system.')],
                xl('Try the full name, patient ID, phone number, or public ID.')
            ),
        ];
    }

    if (count($rows) > 1) {
        return [
            'patient' => null,
            'reply' => assistantBuildAmbiguousPatientReply(
                $title,
                $rows,
                xl('I found more than one possible patient. Please narrow it down before I pull the live details.')
            ),
        ];
    }

    return ['patient' => $rows[0], 'reply' => ''];
}

function assistantExtractAdministeredItemsFromTransaction(string $itemsJson): array
{
    $items = json_decode($itemsJson, true);
    if (!is_array($items)) {
        return [];
    }

    $lines = [];
    foreach ($items as $item) {
        if (!is_array($item) || (float)($item['administer_quantity'] ?? 0) <= 0) {
            continue;
        }
        $label = trim((string)($item['name'] ?? ''));
        if ($label === '') {
            $label = trim((string)($item['display_name'] ?? ''));
        }
        if ($label === '') {
            $label = xl('Unknown medication');
        }
        $dose = trim((string)($item['dose_option_mg'] ?? ($item['semaglutide_dose_mg'] ?? '')));
        $line = $label . ' x ' . assistantFormatInventoryQuantity($item['administer_quantity'] ?? '0');
        if ($dose !== '') {
            $line .= ' @ ' . $dose . ' mg';
        }
        $lines[] = $line;
    }

    return $lines;
}

function assistantFetchInventoryDetailRows(string $message, string $term = '', int $limit = 100): array
{
    $lotNumber = '';
    if (preg_match('/\blot\s+([a-z0-9\-_]+)/i', $message, $matches)) {
        $lotNumber = trim($matches[1]);
    }

    $sql = "SELECT d.name, d.form, d.size, d.strength, d.unit, di.on_hand, di.lot_number, di.expiration
        FROM drug_inventory AS di
        JOIN drugs AS d ON d.drug_id = di.drug_id
        WHERE di.destroy_date IS NULL
          AND COALESCE(d.active, 1) = 1";
    $binds = [];

    $selectedFacilityId = assistantGetSelectedFacilityId();
    if ($selectedFacilityId > 0) {
        $sql .= " AND di.facility_id = ?";
        $binds[] = $selectedFacilityId;
    }

    if ($lotNumber !== '') {
        $sql .= " AND di.lot_number LIKE ?";
        $binds[] = '%' . $lotNumber . '%';
    } else {
        $sql .= " AND (d.name LIKE ? OR di.lot_number LIKE ?)";
        $binds[] = '%' . $term . '%';
        $binds[] = '%' . $term . '%';
    }

    $sql .= " ORDER BY di.on_hand DESC, di.expiration IS NULL ASC, di.expiration ASC, d.name ASC LIMIT " . (int)$limit;
    return assistantFetchRows($sql, $binds);
}

function assistantCalculateShotTrackerMetrics(array $rows): array
{
    $metrics = [
        'lipo_cards' => 0,
        'lipo_units' => 0,
        'sg_trz_cards' => 0,
        'sg_trz_units' => 0,
    ];
    $perDay = [];

    foreach ($rows as $row) {
        $dateKey = substr((string)($row['created_date'] ?? ''), 0, 10);
        if ($dateKey === '') {
            continue;
        }
        if (!isset($perDay[$dateKey])) {
            $perDay[$dateKey] = ['lipo_purchased' => 0, 'sema_purchased' => 0, 'tirz_purchased' => 0];
        }

        $items = json_decode((string)($row['items'] ?? ''), true);
        if (!is_array($items)) {
            continue;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = assistantClassifyDcrItemType($item);
            $quantity = (int)($item['quantity'] ?? 0);
            $isPrepaidItem = !empty($item['prepay_selected']);
            $purchasedQty = $isPrepaidItem ? 0 : $quantity;
            if ($type === 'lipo' && $purchasedQty > 0) {
                $perDay[$dateKey]['lipo_purchased'] += $purchasedQty;
            } elseif ($type === 'sema' && $purchasedQty > 0) {
                $perDay[$dateKey]['sema_purchased'] += $purchasedQty;
            } elseif ($type === 'tirz' && $purchasedQty > 0) {
                $perDay[$dateKey]['tirz_purchased'] += $purchasedQty;
            }
        }
    }

    foreach ($perDay as $values) {
        $lipoCards = (int)floor(((int)$values['lipo_purchased']) / 5);
        $lipoUnits = $lipoCards * 4;
        $sgTrzSold = (int)$values['sema_purchased'] + (int)$values['tirz_purchased'];
        $sgTrzCards = (int)floor($sgTrzSold / 4);
        $sgTrzUnits = $sgTrzCards * 4;

        $metrics['lipo_cards'] += $lipoCards;
        $metrics['lipo_units'] += $lipoUnits;
        $metrics['sg_trz_cards'] += $sgTrzCards;
        $metrics['sg_trz_units'] += $sgTrzUnits;
    }

    return $metrics;
}

function assistantAggregateMedicineStats(array $rows): array
{
    $medicineTotals = [];
    foreach ($rows as $row) {
        $items = json_decode((string)($row['items'] ?? ''), true);
        if (!is_array($items)) {
            continue;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = trim((string)($item['name'] ?? ''));
            if ($label === '') {
                $label = trim((string)($item['display_name'] ?? ''));
            }
            if ($label === '') {
                $label = xl('Unknown Item');
            }

            $quantity = (int)($item['quantity'] ?? 0);
            $itemTotal = assistantExtractTransactionItemTotal($item);
            if (!isset($medicineTotals[$label])) {
                $medicineTotals[$label] = ['revenue' => 0.0, 'quantity' => 0, 'count' => 0];
            }

            $medicineTotals[$label]['revenue'] += $itemTotal;
            $medicineTotals[$label]['quantity'] += $quantity;
            $medicineTotals[$label]['count']++;
        }
    }

    return $medicineTotals;
}

function assistantExtractTransactionItemTotal(array $item): float
{
    $candidates = [
        $item['total'] ?? null,
        $item['line_total'] ?? null,
        $item['original_line_total'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if ($candidate !== null && $candidate !== '') {
            return round((float)$candidate, 2);
        }
    }

    $price = (float)($item['price'] ?? ($item['unit_price'] ?? 0));
    $quantity = (float)($item['quantity'] ?? 0);
    return round($price * $quantity, 2);
}

function assistantExtractTrackedMedicineKeys(string $normalized): array
{
    $keys = [];
    foreach (['semaglutide' => ['semaglutide', 'sema'], 'tirzepatide' => ['tirzepatide', 'tirz'], 'lipo' => ['lipo', 'b12']] as $key => $tokens) {
        if (assistantKeywordMatch($normalized, $tokens)) {
            $keys[] = $key;
        }
    }
    return $keys;
}

function assistantItemMatchesMedicineKey(string $label, string $medicineKey): bool
{
    $normalized = assistantNormalizeMessage($label);
    if ($medicineKey === 'semaglutide') {
        return strpos($normalized, 'semaglutide') !== false || strpos($normalized, 'sema') !== false;
    }
    if ($medicineKey === 'tirzepatide') {
        return strpos($normalized, 'tirzepatide') !== false || strpos($normalized, 'tirz') !== false;
    }
    if ($medicineKey === 'lipo') {
        return strpos($normalized, 'lipo') !== false || strpos($normalized, 'b12') !== false;
    }

    return false;
}

function assistantTryUnifiedSystemLookup(string $message, string $normalized): string
{
    $isUnifiedIntent = assistantKeywordMatch($normalized, [
        'search',
        'find',
        'lookup',
        'look up',
        'show me',
        'search system',
        'search everywhere',
        'search all',
        'find everything'
    ]);
    if (!$isUnifiedIntent) {
        $isUnifiedIntent = assistantShouldAttemptUnifiedSearch($message, $normalized);
    }

    if (!$isUnifiedIntent) {
        return '';
    }

    $term = assistantExtractGlobalSearchTerm($message);
    $criteria = assistantExtractPatientCriteria($message, $normalized);

    if (($criteria['value'] ?? '') === '' && $term === '') {
        return assistantBuildReply(
            xl('System Search'),
            [xl('Tell me what to search for, for example: "search John Smith", "find semaglutide", or "search lot K001".')]
        );
    }

    $sections = [];
    $patientRows = [];

    if (($criteria['value'] ?? '') !== '') {
        $patientRows = assistantFindPatients($criteria, 3);
    } elseif ($term !== '') {
        $patientRows = assistantFindPatients(['type' => 'name', 'value' => $term], 3);
    }

    if (!empty($patientRows)) {
        $patientLines = [];
        foreach ($patientRows as $row) {
            $parts = [assistantFormatPatientLabel($row)];
            if (!empty($row['DOB'])) {
                $parts[] = xl('DOB') . ' ' . $row['DOB'];
            }
            $phone = assistantPreferredPhone($row);
            if ($phone !== '') {
                $parts[] = xl('Phone') . ' ' . $phone;
            }
            $patientLines[] = implode(' | ', $parts);
        }
        $sections[xl('Patients')] = $patientLines;
    }

    $inventoryRows = assistantUnifiedInventorySearch($message, $normalized, $term);
    if (!empty($inventoryRows)) {
        $inventoryLines = [];
        foreach ($inventoryRows as $row) {
            $parts = [
                ($row['name'] ?? xl('Unknown product')),
                xl('Lot') . ' ' . ($row['lot_number'] ?? ''),
                xl('On hand') . ' ' . ((string) ($row['on_hand'] ?? '0'))
            ];
            if (!empty($row['expiration'])) {
                $parts[] = xl('Expires') . ' ' . $row['expiration'];
            }
            $inventoryLines[] = implode(' | ', $parts);
        }
        $sections[xl('Inventory')] = $inventoryLines;
    }

    $appointmentRows = assistantUnifiedAppointmentSearch($patientRows);
    if (!empty($appointmentRows)) {
        $appointmentLines = [];
        foreach ($appointmentRows as $row) {
            $parts = [
                assistantFormatPatientName($row),
                $row['pc_eventDate'] ?? '',
            ];
            if (!empty($row['pc_startTime'])) {
                $parts[] = $row['pc_startTime'];
            }
            if (!empty($row['pc_title'])) {
                $parts[] = $row['pc_title'];
            }
            if (!empty($row['pc_apptstatus'])) {
                $parts[] = xl('Status') . ' ' . $row['pc_apptstatus'];
            }
            $appointmentLines[] = implode(' | ', array_filter($parts, static function ($value) {
                return $value !== '';
            }));
        }
        $sections[xl('Appointments')] = $appointmentLines;
    }

    $transactionRows = assistantUnifiedTransactionSearch($patientRows, $term);
    if (!empty($transactionRows)) {
        $transactionLines = [];
        foreach ($transactionRows as $row) {
            $parts = [
                assistantFormatPatientName($row),
                $row['created_date'] ?? '',
                $row['transaction_type'] ?? '',
                xl('Receipt') . ' ' . ($row['receipt_number'] ?? '')
            ];
            if (isset($row['amount']) && $row['amount'] !== '') {
                $parts[] = '$' . number_format((float) $row['amount'], 2);
            }
            $transactionLines[] = implode(' | ', array_filter($parts, static function ($value) {
                return $value !== '';
            }));
        }
        $sections[xl('POS Transactions')] = $transactionLines;
    }

    if (empty($sections)) {
        return assistantBuildReply(
            xl('System Search'),
            [xl('I did not find matching patient, inventory, appointment, or POS records for that search.')],
            xl('Try a fuller patient name, PID, phone number, drug name, lot number, or receipt reference.')
        );
    }

    return assistantBuildSectionedReply(
        xl('System Search'),
        $sections,
        xl('Showing combined results across the main OpenEMR areas jacki can search right now.')
    );
}

function assistantTryPatientBalanceLookup(string $message, string $normalized, array $context = []): string
{
    if (!assistantHasBalanceIntent($normalized)) {
        return '';
    }

    $criteria = assistantExtractPatientCriteria($message, $normalized);
    if (($criteria['value'] ?? '') === '' && !empty($context['patient_id'])) {
        $criteria = ['type' => 'pid', 'value' => (string) ((int) $context['patient_id'])];
    }

    if (($criteria['value'] ?? '') === '') {
        return assistantBuildReply(
            xl('Patient Balance'),
            [xl('Tell me which patient you want, for example: "balance for patient 42", "credit John Smith", or "what does test10 owe".')]
        );
    }

    $rows = assistantFindPatients($criteria, 3);
    if (empty($rows)) {
        return assistantBuildReply(
            xl('Patient Balance'),
            [xl('I could not find a matching patient in your system.')],
            xl('Try the full name, patient ID, phone number, or public ID.')
        );
    }
    if (count($rows) > 1) {
        return assistantBuildAmbiguousPatientReply(
            xl('Patient Balance'),
            $rows,
            xl('I found more than one possible patient. Please narrow it down before I pull balance details.')
        );
    }

    $patient = $rows[0];
    $creditBalance = assistantGetPatientCreditBalance((int) ($patient['pid'] ?? 0));
    $outstandingBalance = assistantGetPatientOutstandingBalance((int) ($patient['pid'] ?? 0));

    $bullets = [
        assistantFormatPatientLabel($patient),
        xl('Credit Balance') . ': $' . number_format($creditBalance, 2),
        xl('Outstanding Balance') . ': $' . number_format($outstandingBalance, 2),
    ];

    return assistantBuildReply(
        xl('Patient Balance'),
        $bullets,
        trim(xl('Showing the current saved credit balance and the calculated outstanding balance.') . (assistantIsFuzzyPatientMatch($patient) ? "\n" . assistantBuildPatientSuggestionFooter($patient) : ''))
    );
}

function assistantTryAppointmentLookup(string $message, string $normalized): string
{
    if (!assistantKeywordMatch($normalized, ['appointment', 'appointments', 'calendar', 'schedule'])) {
        return '';
    }

    $criteria = assistantExtractPatientCriteria($message, $normalized);
    if ($criteria['value'] === '') {
        return assistantBuildReply(
            xl('Appointment Lookup'),
            [xl('Tell me which patient you want, for example: "appointments for John Smith" or "next appointment for patient 42".')]
        );
    }

    $patientRows = assistantFindPatients($criteria, 5);
    if (empty($patientRows)) {
        return assistantBuildReply(
            xl('Appointment Lookup'),
            [xl('I could not find a matching patient in your system.')],
            xl('Try the full name, patient ID, phone number, or public ID.')
        );
    }
    if (count($patientRows) > 1) {
        return assistantBuildAmbiguousPatientReply(
            xl('Appointment Lookup'),
            $patientRows,
            xl('I found more than one possible patient. Please pick one of these matches or use a more specific search.')
        );
    }

    $patient = $patientRows[0];
    $futureMode = assistantDetectScheduleDirection($normalized) === 'future';
    $suggestionFooter = assistantIsFuzzyPatientMatch($patient) ? assistantBuildPatientSuggestionFooter($patient) : '';

    $selectedFacilityId = assistantGetSelectedFacilityId();
    $sql = "SELECT e.pc_eventDate, e.pc_startTime, e.pc_endTime, e.pc_title, e.pc_apptstatus, e.pc_hometext,
            COALESCE(f.name, '') AS facility_name
        FROM openemr_postcalendar_events AS e
        LEFT JOIN facility AS f ON f.id = e.pc_facility
        WHERE pc_pid = ?
          AND pc_pid IS NOT NULL
          AND pc_pid != '' ";
    $binds = [(string) $patient['pid']];

    if ($selectedFacilityId > 0) {
        $sql .= "AND e.pc_facility = ? ";
        $binds[] = $selectedFacilityId;
    }

    if ($futureMode) {
        $sql .= "AND pc_eventDate >= CURRENT_DATE ";
        $title = xl('Upcoming Appointments for ') . assistantFormatPatientLabel($patient);
        $footer = xl('Showing the next scheduled appointments on file.');
        $sql .= "ORDER BY pc_eventDate ASC, pc_startTime ASC LIMIT 5";
    } else {
        $sql .= "AND pc_eventDate <= CURRENT_DATE ";
        $title = xl('Recent Appointments for ') . assistantFormatPatientLabel($patient);
        $footer = xl('Showing recent appointments on file.');
        $sql .= "ORDER BY pc_eventDate DESC, pc_startTime DESC LIMIT 5";
    }

    $rows = assistantFetchRows($sql, $binds);

    if (empty($rows)) {
        return assistantBuildReply(
            xl('Appointment Lookup'),
            [assistantFormatPatientLabel($patient) . ': ' . ($futureMode ? xl('I found the patient, but there are no upcoming appointments on file.') : xl('I found the patient, but there are no recent appointments on file.'))],
            $suggestionFooter
        );
    }

    $bullets = [];
    foreach ($rows as $row) {
        $parts = [];
        if (!empty($row['pc_eventDate'])) {
            $parts[] = $row['pc_eventDate'];
        }
        if (!empty($row['pc_startTime'])) {
            $time = $row['pc_startTime'];
            if (!empty($row['pc_endTime'])) {
                $time .= ' - ' . $row['pc_endTime'];
            }
            $parts[] = $time;
        }
        if (!empty($row['pc_title'])) {
            $parts[] = $row['pc_title'];
        }
        if (!empty($row['pc_apptstatus'])) {
            $parts[] = xl('Status') . ' ' . $row['pc_apptstatus'];
        }
        if (!empty($row['facility_name'])) {
            $parts[] = xl('Location') . ' ' . $row['facility_name'];
        }
        if (!empty($row['pc_hometext'])) {
            $parts[] = xl('Notes') . ' ' . $row['pc_hometext'];
        }
        $bullets[] = implode(' | ', $parts);
    }

    $fullFooter = trim(xl('I found') . ' ' . count($rows) . ' ' . ($futureMode ? xl('upcoming appointment entries.') : xl('recent appointment entries.')) . ($suggestionFooter !== '' ? "\n" . $suggestionFooter : ''));
    return assistantBuildReply($title, $bullets, $fullFooter);
}

function assistantTryPosTransactionLookup(string $message, string $normalized, array $context = []): string
{
    if (!assistantTableExists('pos_transactions')) {
        return '';
    }

    $hasReceiptReference = assistantExtractReceiptReference($message) !== '';
    $hasPosLookupIntent = assistantKeywordMatch($normalized, [
        'recent pos',
        'pos history',
        'transaction history',
        'recent transaction',
        'recent transactions',
        'recent payment',
        'recent payments',
        'recent receipt',
        'recent receipts',
        'last receipt',
        'last transaction',
        'payment history',
        'receipt'
    ]);

    if (!$hasReceiptReference && !$hasPosLookupIntent && !assistantKeywordMatch($normalized, ['payment fail', 'payment failed', 'failed payment'])) {
        return '';
    }

    if (assistantKeywordMatch($normalized, ['payment fail', 'payment failed', 'failed payment'])) {
        return assistantBuildReply(
            xl('POS Payment Troubleshooting'),
            [
                xl('I can review recent POS transactions and receipts, but I do not currently have a dedicated live failure-log table for card processor decline reasons.'),
                xl('First check whether a receipt was created, whether the amount is zero, and whether the payment method or external processor response needs to be retried in POS.'),
                xl('If you tell me the patient or receipt number, I can pull the recent POS entries next.')
            ],
            xl('Try: "recent POS for patient 42" or "receipt INV-1774462760172-SH83O".')
        );
    }

    $criteria = assistantExtractPatientCriteria($message, $normalized);
    if (($criteria['value'] ?? '') === '' && !empty($context['patient_id'])) {
        $criteria = ['type' => 'pid', 'value' => (string)((int)$context['patient_id'])];
    }

    $receiptReference = assistantExtractReceiptReference($message);
    $rows = [];
    $title = xl('POS Transaction Lookup');
    $footer = '';

    if ($receiptReference !== '') {
        $rows = assistantFetchPosTransactions([
            'receipt' => $receiptReference,
            'limit' => 10,
        ]);
        $title = xl('Receipt Lookup');
        $footer = xl('Showing POS entries that match the requested receipt reference.');
    } elseif (($criteria['value'] ?? '') !== '') {
        $patientRows = assistantFindPatients($criteria, 3);
        if (empty($patientRows)) {
            return assistantBuildReply(
                xl('POS Transaction Lookup'),
                [xl('I could not find a matching patient in your system.')],
                xl('Try the full name, patient ID, phone number, or public ID.')
            );
        }
        if (count($patientRows) > 1) {
            return assistantBuildAmbiguousPatientReply(
                xl('POS Transaction Lookup'),
                $patientRows,
                xl('I found more than one possible patient. Please narrow it down before I pull POS history.')
            );
        }

        $patient = $patientRows[0];
        $rows = assistantFetchPosTransactions([
            'pid' => (int)($patient['pid'] ?? 0),
            'limit' => 8,
        ]);
        $title = xl('Recent POS for ') . assistantFormatPatientLabel($patient);
        $footer = trim(xl('Showing the most recent POS entries for this patient.') . (assistantIsFuzzyPatientMatch($patient) ? "\n" . assistantBuildPatientSuggestionFooter($patient) : ''));
    } elseif ($hasPosLookupIntent) {
        return assistantBuildReply(
            xl('POS Transaction Lookup'),
            [
                xl('Tell me which patient or receipt you want, for example: "recent POS for patient 42", "recent transactions for John Smith", or "receipt INV-1774462760172-SH83O".')
            ]
        );
    }

    if (empty($rows)) {
        return assistantBuildReply(
            $title,
            [xl('I did not find matching POS transactions for that request.')],
            $footer !== '' ? $footer : xl('Try a patient name, PID, or a fuller receipt reference.')
        );
    }

    $bullets = [];
    foreach ($rows as $row) {
        $parts = [];
        if (!empty($row['created_date'])) {
            $parts[] = (string)$row['created_date'];
        }
        if (!empty($row['receipt_number'])) {
            $parts[] = xl('Receipt') . ' ' . $row['receipt_number'];
        }
        if (!empty($row['transaction_type'])) {
            $parts[] = xl('Type') . ' ' . $row['transaction_type'];
        }
        if (!empty($row['payment_method'])) {
            $parts[] = xl('Payment') . ' ' . $row['payment_method'];
        }
        if (isset($row['amount']) && $row['amount'] !== '') {
            $parts[] = '$' . number_format((float)$row['amount'], 2);
        }
        $bullets[] = implode(' | ', $parts);
    }

    return assistantBuildReply($title, $bullets, $footer);
}

function assistantTryTodayVisitListLookup(string $message, string $normalized): string
{
    $dateRange = assistantExtractScheduleDateRange($normalized);
    if ($dateRange['date'] === '' || !assistantKeywordMatch($normalized, [
        'today patient visit list',
        'today visit list',
        'today list of patient',
        'today list of patients',
        'today patient list',
        'today patients list',
        'todays visit list',
        "today's visit list",
        'tomorrow patient visit list',
        'tomorrow visit list',
        'tomorrow list of patient',
        'tomorrow list of patients',
        'tomorrow patient list',
        'tomorrow patients list',
        "tomorrow's visit list",
        'yesterday patient visit list',
        'yesterday visit list',
        'yesterday list of patient',
        'yesterday list of patients',
        'yesterday patient list',
        'yesterday patients list',
        "yesterday's visit list",
        'today appointment list',
        'todays appointment list',
        "today's appointment list",
        'tomorrow appointment list',
        "tomorrow's appointment list",
        'yesterday appointment list',
        "yesterday's appointment list",
        'today visits',
        'todays visits',
        "today's visits",
        'tomorrow visits',
        "tomorrow's visits",
        'yesterday visits',
        "yesterday's visits"
    ])) {
        return '';
    }

    $selectedFacilityId = assistantGetSelectedFacilityId();
    $sql = "SELECT p.pid, p.pubpid, p.fname, p.mname, p.lname, e.pc_eventDate, e.pc_startTime, e.pc_endTime, e.pc_title, e.pc_apptstatus,
                COALESCE(f.name, '') AS facility_name
         FROM openemr_postcalendar_events AS e
         JOIN patient_data AS p ON p.pid = e.pc_pid
         LEFT JOIN facility AS f ON f.id = e.pc_facility
         WHERE e.pc_pid IS NOT NULL
           AND e.pc_pid != ''
           AND e.pc_eventDate = ? ";
    $binds = [$dateRange['date']];
    if ($selectedFacilityId > 0) {
        $sql .= "AND e.pc_facility = ? ";
        $binds[] = $selectedFacilityId;
    }
    $sql .= "ORDER BY e.pc_startTime ASC, p.lname ASC, p.fname ASC
         LIMIT 50";
    $rows = assistantFetchRows($sql, $binds);

    if (empty($rows)) {
        return assistantBuildReply(
            $dateRange['title'] . ' ' . xl('Visit List'),
            [sprintf(xl('I did not find any scheduled patient visits for %s.'), $dateRange['label'])],
            xl('This list is based on appointment records in the calendar.')
        );
    }

    $bullets = [];
    foreach ($rows as $row) {
        $parts = [assistantFormatPatientLabel($row)];
        if (!empty($row['pc_startTime'])) {
            $time = $row['pc_startTime'];
            if (!empty($row['pc_endTime'])) {
                $time .= ' - ' . $row['pc_endTime'];
            }
            $parts[] = $time;
        }
        if (!empty($row['pc_title'])) {
            $parts[] = $row['pc_title'];
        }
        if (!empty($row['pc_apptstatus'])) {
            $parts[] = xl('Status') . ' ' . $row['pc_apptstatus'];
        }
        if (!empty($row['facility_name'])) {
            $parts[] = xl('Location') . ' ' . $row['facility_name'];
        }
        $bullets[] = implode(' | ', $parts);
    }

    return assistantBuildReply(
        $dateRange['title'] . ' ' . xl('Visit List'),
        $bullets,
        sprintf(xl('Showing scheduled patient visits for %s from the OpenEMR calendar.'), $dateRange['label'])
    );
}

function assistantTryScheduleByLocationLookup(string $message, string $normalized): string
{
    if (!assistantKeywordMatch($normalized, [
        'appointments by location',
        'appointments by facility',
        'visits by location',
        'visits by facility',
        'today appointments by location',
        'today appointments by facility',
        'today visits by location',
        'today visits by facility',
        'tomorrow appointments by location',
        'tomorrow visits by location',
        'how many appointments at each location',
        'how many visits at each location',
        'schedule by location',
        'schedule by facility'
    ])) {
        return '';
    }

    $dateRange = assistantExtractScheduleDateRange($normalized);
    if ($dateRange['date'] === '') {
        $dateRange = [
            'date' => (new DateTime('today'))->format('Y-m-d'),
            'label' => xl('today'),
            'title' => xl('Today')
        ];
    }

    if (!assistantTableExists('openemr_postcalendar_events')) {
        return assistantBuildReply(
            xl('Appointments by Location'),
            [xl('The calendar table is not available, so I cannot summarize appointments by location right now.')]
        );
    }

    $selectedFacilityId = assistantGetSelectedFacilityId();
    $sql = "SELECT COALESCE(f.name, '" . addslashes(xl('Unknown Location')) . "') AS location_name,
                COUNT(*) AS visit_count
         FROM openemr_postcalendar_events AS e
         LEFT JOIN facility AS f ON f.id = e.pc_facility
         WHERE e.pc_pid IS NOT NULL
           AND e.pc_pid != ''
           AND e.pc_eventDate = ? ";
    $binds = [$dateRange['date']];
    if ($selectedFacilityId > 0) {
        $sql .= "AND e.pc_facility = ? ";
        $binds[] = $selectedFacilityId;
    }
    $sql .= "GROUP BY e.pc_facility, f.name
         ORDER BY visit_count DESC, location_name ASC";
    $rows = assistantFetchRows($sql, $binds);

    if (empty($rows)) {
        return assistantBuildReply(
            xl('Appointments by Location'),
            [sprintf(xl('I did not find any scheduled appointments by location for %s.'), $dateRange['label'])],
            xl('This summary is based on appointment records in the OpenEMR calendar.')
        );
    }

    $total = 0;
    $bullets = [];
    foreach ($rows as $row) {
        $count = (int)($row['visit_count'] ?? 0);
        $total += $count;
        $bullets[] = trim((string)($row['location_name'] ?? xl('Unknown Location'))) . ': ' . $count;
    }

    return assistantBuildReply(
        xl('Appointments by Location'),
        $bullets,
        sprintf(xl('Total scheduled appointments for %s') . ': %d', $dateRange['label'], $total)
    );
}

function assistantTryPatientCountByLocationLookup(string $message, string $normalized): string
{
    if (!assistantKeywordMatch($normalized, [
        'how many patients are there in each location',
        'how many patients in each location',
        'patients in each location',
        'patient count by location',
        'patient count by facility',
        'patients by location',
        'patients by facility',
        'how many patients in each facility',
        'how many patients are there in each facility'
    ])) {
        return '';
    }

    if (!assistantTableExists('patient_data') || !assistantTableExists('facility')) {
        return assistantBuildReply(
            xl('Patient Count by Location'),
            [xl('The patient or facility tables are not available, so I cannot group patients by location right now.')]
        );
    }

    $selectedFacilityId = assistantGetSelectedFacilityId();
    $sql = "SELECT
            COALESCE(f.name, '" . addslashes(xl('Unknown Location')) . "') AS location_name,
            COUNT(*) AS patient_count
         FROM patient_data AS p
         LEFT JOIN facility AS f ON f.id = p.facility_id";
    $binds = [];
    if ($selectedFacilityId > 0) {
        $sql .= " WHERE p.facility_id = ?";
        $binds[] = $selectedFacilityId;
    }
    $sql .= " GROUP BY p.facility_id, f.name
         ORDER BY patient_count DESC, location_name ASC";
    $rows = assistantFetchRows($sql, $binds);

    if (empty($rows)) {
        return assistantBuildReply(
            xl('Patient Count by Location'),
            [xl('I did not find any patient location assignments to summarize.')]
        );
    }

    $bullets = [];
    $totalPatients = 0;
    foreach ($rows as $row) {
        $count = (int)($row['patient_count'] ?? 0);
        $totalPatients += $count;
        $bullets[] = trim((string)($row['location_name'] ?? xl('Unknown Location'))) . ': ' . $count;
    }

    return assistantBuildReply(
        xl('Patient Count by Location'),
        $bullets,
        xl('Total Patients Counted') . ': ' . $totalPatients
    );
}

function assistantTryBloodworkLookup(string $message, string $normalized, array $context = []): string
{
    if (!assistantKeywordMatch($normalized, ['bloodwork', 'lab', 'labs', 'result'])) {
        return '';
    }

    $criteria = assistantExtractPatientCriteria($message, $normalized);
    if (($criteria['value'] ?? '') === '' && !empty($context['patient_id'])) {
        $criteria = ['type' => 'pid', 'value' => (string)((int)$context['patient_id'])];
    }
    if ($criteria['value'] === '') {
        return assistantBuildReply(
            xl('Bloodwork Lookup'),
            [xl('Tell me which patient you want, for example: "bloodwork for patient 42" or "labs for John Smith".')],
            xl('I can retrieve recent lab results, but medical interpretation should still come from the clinician.')
        );
    }

    $patientRows = assistantFindPatients($criteria, 5);
    if (empty($patientRows)) {
        return assistantBuildReply(
            xl('Bloodwork Lookup'),
            [xl('I could not find a matching patient in your system.')],
            xl('Try the full name, patient ID, phone number, or public ID.')
        );
    }
    if (count($patientRows) > 1) {
        return assistantBuildAmbiguousPatientReply(
            xl('Bloodwork Lookup'),
            $patientRows,
            xl('I found more than one possible patient. Please narrow it down before I pull lab results.')
        );
    }

    $patient = $patientRows[0];
    $suggestionFooter = assistantIsFuzzyPatientMatch($patient) ? assistantBuildPatientSuggestionFooter($patient) : '';
    $rows = assistantFetchRows(
        "SELECT pr.date_collected, pr.date_report, pr.report_status, res.result_text, res.result, res.units, res.abnormal
        FROM procedure_order AS po
        JOIN procedure_report AS pr ON pr.procedure_order_id = po.procedure_order_id
        JOIN procedure_result AS res ON res.procedure_report_id = pr.procedure_report_id
        WHERE po.patient_id = ?
        ORDER BY pr.date_collected DESC, pr.procedure_report_id DESC
        LIMIT 12",
        [$patient['pid']]
    );

    if (empty($rows)) {
        return assistantBuildReply(
            xl('Bloodwork Lookup'),
            [assistantFormatPatientLabel($patient) . ': ' . xl('I found the patient, but I did not find recent lab or bloodwork results.')],
            $suggestionFooter
        );
    }

    $bullets = [];
    foreach ($rows as $row) {
        $parts = [];
        $date = $row['date_collected'] ?: ($row['date_report'] ?? '');
        if ($date !== '') {
            $parts[] = $date;
        }
        if (!empty($row['result_text'])) {
            $parts[] = $row['result_text'];
        }
        if (($row['result'] ?? '') !== '') {
            $parts[] = xl('Result') . ' ' . $row['result'];
        }
        if (($row['units'] ?? '') !== '') {
            $parts[] = $row['units'];
        }
        if (($row['abnormal'] ?? '') !== '') {
            $parts[] = xl('Flag') . ' ' . $row['abnormal'];
        }
        if (($row['report_status'] ?? '') !== '') {
            $parts[] = xl('Status') . ' ' . $row['report_status'];
        }
        $bullets[] = implode(' | ', $parts);
    }

    return assistantBuildReply(
        xl('Bloodwork for ') . assistantFormatPatientLabel($patient),
        $bullets,
        trim(xl('I found') . ' ' . count($rows) . ' ' . xl('recent lab result entries.') . "\n" . xl('These are recent lab results only. Clinical interpretation should come from the provider.') . ($suggestionFooter !== '' ? "\n" . $suggestionFooter : ''))
    );
}

function assistantTryConsultationLookup(string $message, string $normalized, array $context = []): string
{
    if (!assistantKeywordMatch($normalized, ['consultation', 'consult', 'encounter', 'visit history', 'recent visit'])) {
        return '';
    }

    $criteria = assistantExtractPatientCriteria($message, $normalized);
    if (($criteria['value'] ?? '') === '' && !empty($context['patient_id'])) {
        $criteria = ['type' => 'pid', 'value' => (string)((int)$context['patient_id'])];
    }
    if ($criteria['value'] === '') {
        return assistantBuildReply(
            xl('Consultation Lookup'),
            [xl('Tell me which patient you want, for example: "consultation for patient 42" or "recent visits for John Smith".')]
        );
    }

    $patientRows = assistantFindPatients($criteria, 5);
    if (empty($patientRows)) {
        return assistantBuildReply(
            xl('Consultation Lookup'),
            [xl('I could not find a matching patient in your system.')],
            xl('Try the full name, patient ID, phone number, or public ID.')
        );
    }
    if (count($patientRows) > 1) {
        return assistantBuildAmbiguousPatientReply(
            xl('Consultation Lookup'),
            $patientRows,
            xl('I found more than one possible patient. Please narrow it down before I pull visit history.')
        );
    }

    $patient = $patientRows[0];
    $suggestionFooter = assistantIsFuzzyPatientMatch($patient) ? assistantBuildPatientSuggestionFooter($patient) : '';
    $selectedFacilityId = assistantGetSelectedFacilityId();
    $sql = "SELECT e.date, e.encounter, e.reason, e.facility_id, e.encounter_type_description,
                COALESCE(f.name, '') AS facility_name
        FROM form_encounter AS e
        LEFT JOIN facility AS f ON f.id = e.facility_id
        WHERE e.pid = ? ";
    $binds = [$patient['pid']];
    if ($selectedFacilityId > 0) {
        $sql .= "AND e.facility_id = ? ";
        $binds[] = $selectedFacilityId;
    }
    $sql .= "ORDER BY date DESC, encounter DESC
        LIMIT 5";
    $rows = assistantFetchRows($sql, $binds);

    if (empty($rows)) {
        return assistantBuildReply(
            xl('Consultation Lookup'),
            [assistantFormatPatientLabel($patient) . ': ' . xl('I found the patient, but there are no recent consultations or encounters on file.')],
            $suggestionFooter
        );
    }

    $bullets = [];
    foreach ($rows as $row) {
        $parts = [];
        if (!empty($row['date'])) {
            $parts[] = $row['date'];
        }
        if (!empty($row['encounter'])) {
            $parts[] = xl('Encounter') . ' ' . $row['encounter'];
        }
        if (!empty($row['encounter_type_description'])) {
            $parts[] = $row['encounter_type_description'];
        }
        if (!empty($row['facility_name'])) {
            $parts[] = xl('Location') . ' ' . $row['facility_name'];
        }
        if (!empty($row['reason'])) {
            $parts[] = xl('Reason') . ' ' . $row['reason'];
        }
        $bullets[] = implode(' | ', $parts);
    }

    return assistantBuildReply(
        xl('Recent Consultations for ') . assistantFormatPatientLabel($patient),
        $bullets,
        trim(xl('I found') . ' ' . count($rows) . ' ' . xl('recent consultation or encounter entries.') . ($suggestionFooter !== '' ? "\n" . $suggestionFooter : ''))
    );
}

function assistantTryRecentPatientsLookup(string $message, string $normalized): string
{
    if (!assistantKeywordMatch($normalized, ['recent patient', 'latest patient', 'last patient', 'new patient', 'patients added'])) {
        return '';
    }

    $limit = 5;
    if (preg_match('/\b(\d{1,2})\b/', $message, $matches)) {
        $limit = max(1, min(10, (int)$matches[1]));
    }

    $selectedFacilityId = assistantGetSelectedFacilityId();
    $sql = "SELECT pid, pubpid, fname, mname, lname, DOB, phone_cell, phone_home, city, state
        FROM patient_data";
    $binds = [];
    if ($selectedFacilityId > 0) {
        $sql .= " WHERE facility_id = ?";
        $binds[] = $selectedFacilityId;
    }
    $sql .= " ORDER BY pid DESC
        LIMIT " . (int)$limit;
    $rows = assistantFetchRows($sql, $binds);

    if (empty($rows)) {
        return assistantBuildReply(
            xl('Recent Patients'),
            [xl('No patient records were found.')]
        );
    }

    $bullets = [];
    foreach ($rows as $row) {
        $parts = [
            assistantFormatPatientName($row) . ' (' . xl('PID') . ' ' . ($row['pid'] ?? '') . ')'
        ];

        if (!empty($row['pubpid'])) {
            $parts[] = xl('ID') . ' ' . $row['pubpid'];
        }

        if (!empty($row['DOB'])) {
            $parts[] = xl('DOB') . ' ' . $row['DOB'];
        }

        $phone = assistantPreferredPhone($row);
        if ($phone !== '') {
            $parts[] = xl('Phone') . ' ' . $phone;
        }

        $location = trim(($row['city'] ?? '') . ', ' . ($row['state'] ?? ''), ' ,');
        if ($location !== '') {
            $parts[] = $location;
        }

        $bullets[] = implode(' | ', $parts);
    }

    return assistantBuildReply(
        xl('Recent Patient Records'),
        $bullets,
        xl('These are the most recent patient records by PID.')
    );
}

function assistantTryPatientAddressLookup(string $message, string $normalized): string
{
    if (!assistantKeywordMatch($normalized, ['address', 'live at', 'where does'])) {
        return '';
    }

    $criteria = assistantExtractPatientCriteria($message, $normalized);
    if ($criteria['value'] === '') {
        return assistantBuildReply(
            xl('Address Lookup'),
            [
                xl('Tell me which patient you want, for example: "what is patient 42 address" or "address for John Smith".')
            ]
        );
    }

    $rows = assistantFindPatients($criteria, 3);
    if (empty($rows)) {
        return assistantBuildReply(
            xl('Address Lookup'),
            [xl('I could not find a matching patient in your system.')],
            xl('Try the full name, patient ID, phone number, or public ID.')
        );
    }
    if (count($rows) > 1) {
        return assistantBuildAmbiguousPatientReply(
            xl('Address Lookup'),
            $rows,
            xl('I found multiple patient matches. Please pick one of these or search with a more specific value.')
        );
    }

    $bullets = [];
    foreach ($rows as $row) {
        $address = assistantFormatAddress($row);
        $contact = [];
        if ($address !== '') {
            $contact[] = $address;
        }

        if (!empty($row['email'])) {
            $contact[] = xl('Email') . ' ' . $row['email'];
        }

        $phone = assistantPreferredPhone($row);
        if ($phone !== '') {
            $contact[] = xl('Phone') . ' ' . $phone;
        }

        $bullets[] = assistantFormatPatientLabel($row) . ': ' . ($contact ? implode(' | ', $contact) : xl('No address on file'));
    }

    $footer = trim(xl('I found the patient and the saved contact details below.') . (assistantIsFuzzyPatientMatch($rows[0]) ? "\n" . assistantBuildPatientSuggestionFooter($rows[0]) : ''));
    return assistantBuildReply(xl('Patient Address'), $bullets, $footer);
}

function assistantTryPatientSearchLookup(string $message, string $normalized): string
{
    if (assistantIsGreetingMessage($normalized)) {
        return '';
    }

    if (!assistantKeywordMatch($normalized, ['patient', 'find', 'search', 'lookup', 'phone'])) {
        return '';
    }

    $criteria = assistantExtractPatientCriteria($message, $normalized);
    if ($criteria['value'] === '') {
        return '';
    }

    $rows = assistantFindPatients($criteria, 5);
    if (empty($rows)) {
        return assistantBuildReply(
            xl('Patient Search'),
            [xl('I could not find a matching patient in your system.')],
            xl('Try the full name, patient ID, phone number, or public ID.')
        );
    }

    $bullets = [];
    foreach ($rows as $row) {
        $parts = [assistantFormatPatientLabel($row)];

        $phone = assistantPreferredPhone($row);
        if ($phone !== '') {
            $parts[] = xl('Phone') . ' ' . $phone;
        }

        if (!empty($row['DOB'])) {
            $parts[] = xl('DOB') . ' ' . $row['DOB'];
        }

        $address = assistantFormatAddress($row);
        if ($address !== '') {
            $parts[] = $address;
        }

        $bullets[] = implode(' | ', $parts);
    }

    $footer = trim(xl('I found') . ' ' . count($rows) . ' ' . xl('matching patient records.') . (count($rows) > 1 ? "\n" . xl('If you want one specific patient, use a more specific name or ID.') : '') . (assistantIsFuzzyPatientMatch($rows[0]) ? "\n" . assistantBuildPatientSuggestionFooter($rows[0]) : ''));
    return assistantBuildReply(xl('Patient Search'), $bullets, $footer);
}

function assistantTryPatientPresenceLookup(string $message, string $normalized): string
{
    if (!assistantKeywordMatch($normalized, ['do we have', 'is there a patient', 'do i have a patient', 'check patient', 'patient named'])) {
        return '';
    }

    $criteria = assistantExtractPatientCriteria($message, $normalized);
    if ($criteria['value'] === '') {
        return assistantBuildReply(
            xl('Patient Check'),
            [xl('Tell me the patient name, phone, PID, or public ID you want me to look for.')]
        );
    }

    $rows = assistantFindPatients($criteria, 5);
    if (empty($rows)) {
        return assistantBuildReply(
            xl('Patient Check'),
            [xl('I could not find any matching patient in your system.')],
            xl('Try the full name, patient ID, phone number, or public ID.')
        );
    }

    $bullets = [];
    foreach ($rows as $row) {
        $parts = [assistantFormatPatientLabel($row)];

        if (!empty($row['DOB'])) {
            $parts[] = xl('DOB') . ' ' . $row['DOB'];
        }

        $phone = assistantPreferredPhone($row);
        if ($phone !== '') {
            $parts[] = xl('Phone') . ' ' . $phone;
        }

        $bullets[] = implode(' | ', $parts);
    }

    return assistantBuildReply(
        xl('Patient Check'),
        $bullets,
        trim(xl('I found') . ' ' . count($rows) . ' ' . xl('matching patients in your system.') . (count($rows) > 1 ? "\n" . xl('If you want one specific patient, use a more specific name or ID.') : '') . (assistantIsFuzzyPatientMatch($rows[0]) ? "\n" . assistantBuildPatientSuggestionFooter($rows[0]) : ''))
    );
}

function assistantTryInventoryLookup(string $message, string $normalized): string
{
    if (!assistantHasInventoryIntent($message, $normalized)) {
        return '';
    }

    if (assistantKeywordMatch($normalized, ['expired medicine', 'expired medicines', 'expired drug', 'expired drugs', 'expired lot', 'expiring', 'expires soon', 'low stock', 'out of stock', 'expired inventory'])) {
        return assistantLookupExpiredInventory($normalized);
    }

    $lotNumber = '';
    if (preg_match('/\blot\s+([a-z0-9\-_]+)/i', $message, $matches)) {
        $lotNumber = trim($matches[1]);
    }

    $term = '';
    if ($lotNumber === '') {
        $term = assistantExtractInventoryTerm($message, $normalized);
    }

    if ($lotNumber === '' && $term === '') {
        return assistantBuildReply(
            xl('Inventory Lookup'),
            [
                xl('Ask for a drug name or lot number, for example "inventory semaglutide" or "lot K001".')
            ]
        );
    }

    $rows = assistantFetchInventoryRows($message, $term, 8);

    if (empty($rows)) {
        return assistantBuildReply(
            xl('Inventory Lookup'),
            [xl('I could not find a matching inventory record.')]
        );
    }

    $totalOnHand = 0.0;
    $bullets = [];
    foreach ($rows as $row) {
        $totalOnHand += (float)($row['on_hand'] ?? 0);
        $parts = [
            ($row['name'] ?? xl('Unknown product')) . ' | ' . xl('Lot') . ' ' . ($row['lot_number'] ?? '')
        ];

        $parts[] = xl('On hand') . ' ' . assistantFormatInventoryQuantity($row['on_hand'] ?? '0');

        if (!empty($row['expiration'])) {
            $parts[] = xl('Expires') . ' ' . $row['expiration'];
        }

        if (!empty($row['facility_name'])) {
            $parts[] = xl('Location') . ' ' . $row['facility_name'];
        }

        if (!empty($row['warehouse_id'])) {
            $parts[] = xl('Warehouse') . ' ' . $row['warehouse_id'];
        }

        $bullets[] = implode(' | ', $parts);
    }

    $footerParts = [
        xl('Matching lots') . ': ' . count($rows),
        xl('Total on hand') . ': ' . assistantFormatInventoryQuantity($totalOnHand)
    ];

    return assistantBuildReply(xl('Inventory Lookup'), $bullets, implode(' • ', $footerParts));
}

function assistantExtractPatientCriteria(string $message, string $normalized): array
{
    if (assistantIsGreetingMessage($normalized)) {
        return ['type' => '', 'value' => ''];
    }

    if (preg_match('/\b(?:patient\s*id|pid)\s*[:#-]?\s*(\d+)\b/i', $message, $matches)) {
        return ['type' => 'pid', 'value' => $matches[1]];
    }

    if (preg_match('/\b(?:pubpid|mrn|external id)\s*[:#-]?\s*([a-z0-9\-_]+)\b/i', $message, $matches)) {
        return ['type' => 'pubpid', 'value' => $matches[1]];
    }

    if (preg_match('/\bpatient\s+(\d+)\b/i', $message, $matches)) {
        return ['type' => 'pid', 'value' => $matches[1]];
    }

    $digits = preg_replace('/\D+/', '', $message);
    if (strlen($digits) >= 7) {
        return ['type' => 'phone', 'value' => $digits];
    }

    $clean = strtolower($message);
    $clean = preg_replace('/\b(what|whats|show|find|search|lookup|look up|get|give|me|the|patient|address|for|of|is|was|are|on|in|at|recent|latest|last|new|follow|up|phone|please|do|we|have|named|check|bloodwork|labs|lab|consultation|consult|visit|visits|balance|credit|owe|owed|outstanding|billing|payment|history|appointments|appointment|receipt|transaction|transactions|pos|across|system|everywhere|today|spent|spend|shot|shots|injection|injections|medication|medications|mediciation|medicine|medicines|weight|cash|revenue|left|file|how|much|many|does|did|when)\b/', ' ', $clean);
    $clean = preg_replace('/[^a-z0-9\s\-]/', ' ', $clean);
    $clean = trim(preg_replace('/\s+/', ' ', $clean));

    if ($clean !== '' && preg_match('/[a-z]/', $clean)) {
        $tokenCount = count(preg_split('/\s+/', $clean) ?: []);
        if ($tokenCount < 2 && strlen($clean) < 4) {
            return ['type' => '', 'value' => ''];
        }
        return ['type' => 'name', 'value' => $clean];
    }

    return ['type' => '', 'value' => ''];
}

function assistantFindPatients(array $criteria, int $limit = 5): array
{
    $selectedFacilityId = assistantGetSelectedFacilityId();
    $base = "SELECT pid, pubpid, fname, mname, lname, DOB, phone_home, phone_cell, email, street, city, state, postal_code, country_code
        FROM patient_data";
    $whereBinds = [];
    $orderBinds = [];
    $where = '';
    $orderBy = 'pid DESC';

    if ($criteria['type'] === 'pid') {
        $where = "pid = ?";
        $whereBinds[] = $criteria['value'];
    } elseif ($criteria['type'] === 'pubpid') {
        $where = "pubpid = ?";
        $whereBinds[] = $criteria['value'];
    } elseif ($criteria['type'] === 'phone') {
        $digits = $criteria['value'];
        $where = "(REPLACE(REPLACE(REPLACE(REPLACE(phone_home, '-', ''), '(', ''), ')', ''), ' ', '') LIKE ? OR
            REPLACE(REPLACE(REPLACE(REPLACE(phone_cell, '-', ''), '(', ''), ')', ''), ' ', '') LIKE ?)";
        $whereBinds[] = '%' . $digits . '%';
        $whereBinds[] = '%' . $digits . '%';
    } else {
        $searchValue = trim((string)$criteria['value']);
        $tokens = preg_split('/\s+/', $searchValue) ?: [];
        $firstToken = $tokens[0] ?? $searchValue;
        $lastToken = count($tokens) > 1 ? $tokens[count($tokens) - 1] : $firstToken;
        $name = '%' . $searchValue . '%';
        $firstLike = '%' . $firstToken . '%';
        $lastLike = '%' . $lastToken . '%';

        $where = "CONCAT_WS(' ', fname, mname, lname) LIKE ?
               OR CONCAT_WS(' ', lname, fname, mname) LIKE ?
               OR fname LIKE ?
               OR lname LIKE ?
               OR CONCAT_WS(' ', fname, lname) LIKE ?";
        $orderBy = "CASE
                    WHEN LOWER(fname) = LOWER(?) THEN 0
                    WHEN LOWER(lname) = LOWER(?) THEN 1
                    WHEN LOWER(CONCAT_WS(' ', fname, lname)) = LOWER(?) THEN 2
                    WHEN LOWER(CONCAT_WS(' ', lname, fname)) = LOWER(?) THEN 3
                    WHEN LOWER(fname) LIKE LOWER(?) THEN 4
                    WHEN LOWER(lname) LIKE LOWER(?) THEN 5
                    ELSE 6
                END, pid DESC";
        $whereBinds[] = $name;
        $whereBinds[] = $name;
        $whereBinds[] = $firstLike;
        $whereBinds[] = $lastLike;
        $whereBinds[] = $name;
        $orderBinds[] = $firstToken;
        $orderBinds[] = $lastToken;
        $orderBinds[] = $searchValue;
        $orderBinds[] = $searchValue;
        $orderBinds[] = $firstLike;
        $orderBinds[] = $lastLike;
    }

    if ($selectedFacilityId > 0) {
        $where .= ($where !== '' ? " AND " : "") . "facility_id = ?";
        $whereBinds[] = $selectedFacilityId;
    }

    $sql = $base . " WHERE " . $where . " ORDER BY " . $orderBy . " LIMIT " . (int)$limit;
    $binds = array_merge($whereBinds, $orderBinds);

    $rows = assistantFetchRows($sql, $binds);
    foreach ($rows as &$row) {
        $row['_assistant_match_type'] = 'direct';
    }
    if (!empty($rows) || $criteria['type'] !== 'name') {
        return $rows;
    }

    return assistantFindPatientsFuzzyByName((string)$criteria['value'], $limit);
}

function assistantFindPatientsFuzzyByName(string $searchValue, int $limit = 5): array
{
    $searchValue = trim(strtolower($searchValue));
    if ($searchValue === '') {
        return [];
    }

    if (strlen($searchValue) < 4 && strpos($searchValue, ' ') === false) {
        return [];
    }

    $tokens = preg_split('/\s+/', $searchValue) ?: [];
    $firstToken = $tokens[0] ?? $searchValue;
    $lastToken = count($tokens) > 1 ? $tokens[count($tokens) - 1] : $firstToken;

    $selectedFacilityId = assistantGetSelectedFacilityId();
    $sql = "SELECT pid, pubpid, fname, mname, lname, DOB, phone_home, phone_cell, email, street, city, state, postal_code, country_code
        FROM patient_data
        WHERE (SOUNDEX(fname) = SOUNDEX(?)
           OR SOUNDEX(lname) = SOUNDEX(?)
           OR SOUNDEX(CONCAT_WS(' ', fname, lname)) = SOUNDEX(?)
           OR fname LIKE ?
           OR lname LIKE ?)";
    $binds = [
        $firstToken,
        $lastToken,
        $searchValue,
        '%' . substr($firstToken, 0, 2) . '%',
        '%' . substr($lastToken, 0, 2) . '%'
    ];
    if ($selectedFacilityId > 0) {
        $sql .= " AND facility_id = ?";
        $binds[] = $selectedFacilityId;
    }
    $sql .= " ORDER BY pid DESC
        LIMIT 30";
    $candidateRows = assistantFetchRows($sql, $binds);

    if (empty($candidateRows)) {
        return [];
    }

    $scored = [];
    foreach ($candidateRows as $row) {
        $score = assistantScorePatientNameMatch($row, $searchValue, $firstToken, $lastToken);
        if ($score <= 0) {
            continue;
        }

        $row['_assistant_score'] = $score;
        $row['_assistant_match_type'] = 'fuzzy';
        $scored[] = $row;
    }

    if (empty($scored)) {
        return [];
    }

    usort($scored, function ($a, $b) {
        $scoreCompare = ($b['_assistant_score'] ?? 0) <=> ($a['_assistant_score'] ?? 0);
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }
        return ($b['pid'] ?? 0) <=> ($a['pid'] ?? 0);
    });

    $scored = array_slice($scored, 0, $limit);
    foreach ($scored as &$row) {
        unset($row['_assistant_score']);
    }

    return $scored;
}

function assistantIsGreetingMessage(string $normalized): bool
{
    $normalized = trim(strtolower($normalized));
    return in_array($normalized, [
        'hi',
        'hey',
        'hello',
        'yo',
        'sup',
        'good morning',
        'good afternoon',
        'good evening',
        'how are you',
        'how are you?',
        'how r you',
        'how r u',
        'hows it going',
        'how is it going',
        'whats up',
        'what is up'
    ], true);
}

function assistantIsCasualConversationMessage(string $normalized): bool
{
    $normalized = trim(strtolower($normalized));

    return in_array($normalized, [
        'good morning',
        'good night',
        'hows your day',
        "how's your day",
        'how is your day',
        'okay',
        'ok',
        'nice',
        'can you help me',
        'can you help',
        'what do you know',
        'tell me what you know',
        'sounds good',
        'got it',
        'cool',
        'alright',
        'all right',
        'perfect',
        'great',
        'awesome',
        'that helps',
        'understood'
    ], true);
}

function assistantIsFuzzyPatientMatch(array $row): bool
{
    return ($row['_assistant_match_type'] ?? '') === 'fuzzy';
}

function assistantBuildPatientSuggestionFooter(array $row): string
{
    return xl('Did you mean') . ' ' . assistantFormatPatientName($row) . '? ' . xl('This match came from typo-tolerant search.');
}

function assistantBuildAmbiguousPatientReply(string $title, array $rows, string $footer = ''): string
{
    $bullets = [];
    foreach (array_slice($rows, 0, 5) as $row) {
        $parts = [assistantFormatPatientLabel($row)];

        if (!empty($row['DOB'])) {
            $parts[] = xl('DOB') . ' ' . $row['DOB'];
        }

        $phone = assistantPreferredPhone($row);
        if ($phone !== '') {
            $parts[] = xl('Phone') . ' ' . $phone;
        }

        $bullets[] = implode(' | ', $parts);
    }

    return assistantBuildReply($title, $bullets, $footer);
}

function assistantScorePatientNameMatch(array $row, string $searchValue, string $firstToken, string $lastToken): int
{
    $fname = strtolower(trim((string)($row['fname'] ?? '')));
    $lname = strtolower(trim((string)($row['lname'] ?? '')));
    $full = strtolower(trim(assistantFormatPatientName($row)));
    $reverse = trim($lname . ' ' . $fname);

    $score = 0;

    if ($fname === $firstToken || $lname === $lastToken) {
        $score += 120;
    }
    if ($full === $searchValue || $reverse === $searchValue) {
        $score += 140;
    }
    if ($fname !== '' && levenshtein($firstToken, $fname) <= 2) {
        $score += 90;
    }
    if ($lname !== '' && levenshtein($lastToken, $lname) <= 2) {
        $score += 80;
    }
    if ($full !== '' && levenshtein($searchValue, $full) <= 3) {
        $score += 100;
    }
    if ($fname !== '' && metaphone($fname) === metaphone($firstToken)) {
        $score += 75;
    }
    if ($lname !== '' && metaphone($lname) === metaphone($lastToken)) {
        $score += 65;
    }
    if ($full !== '' && strpos($full, $searchValue) !== false) {
        $score += 40;
    }
    if ($fname !== '' && strpos($fname, $firstToken) !== false) {
        $score += 35;
    }
    if ($lname !== '' && strpos($lname, $lastToken) !== false) {
        $score += 30;
    }

    return $score >= 70 ? $score : 0;
}

function assistantExtractInventoryTerm(string $message, string $normalized): string
{
    $term = preg_replace('/\b(show|find|search|lookup|look up|get|check|see|open|inventory|drug|drugs|product|products|medication|medicine|medicines|stock|lot|lots|on hand|available|expired|expiring|expires|soon|for|of|the|please|ava|what|which|is|are|do|we|have|how much|how many|many)\b/i', ' ', $message);
    $term = trim(preg_replace('/\s+/', ' ', $term));

    if (assistantKeywordMatch($normalized, ['mg inventory', 'inventory mg']) || (assistantKeywordMatch($normalized, ['how much']) && assistantKeywordMatch($normalized, ['mg']))) {
        $term = preg_replace('/\bmg\b/i', ' ', $term);
        $term = trim(preg_replace('/\s+/', ' ', $term));
    }

    if ($term !== '' && !assistantKeywordMatch($normalized, ['recent patient', 'latest patient'])) {
        return $term;
    }

    foreach (assistantInventoryKeywords() as $keyword) {
        if (strpos($normalized, $keyword) !== false) {
            return $keyword;
        }
    }

    return '';
}

function assistantExtractGlobalSearchTerm(string $message): string
{
    $term = preg_replace('/\b(show|find|search|lookup|look up|get|check|see|open|for|of|the|please|in|system|everywhere|all|records|record|inventory|stock|balance|credit|appointments|appointment|transactions|transaction|receipt|pos|patient|payment|failed|fail|history|status|what|which|is|are|do|we|have|how much|owed|owe|outstanding)\b/i', ' ', $message);
    $term = preg_replace('/[^a-z0-9\s\-_#]/i', ' ', $term);
    $term = trim(preg_replace('/\s+/', ' ', $term));

    if ($term === '' || strlen($term) < 2) {
        return '';
    }

    return $term;
}

function assistantShouldAttemptUnifiedSearch(string $message, string $normalized): bool
{
    if ($normalized === '') {
        return false;
    }

    $wordCount = count(array_filter(preg_split('/\s+/', $normalized) ?: []));
    if ($wordCount <= 6 && preg_match('/[a-z]/i', $message)) {
        $criteria = assistantExtractPatientCriteria($message, $normalized);
        if (($criteria['type'] ?? '') === 'name' && ($criteria['value'] ?? '') !== '') {
            return true;
        }

        if (
            assistantHasInventoryIntent($message, $normalized)
            || assistantHasBalanceIntent($normalized)
            || assistantHasPosIntent($normalized)
            || assistantKeywordMatch($normalized, ['appointments', 'appointment', 'schedule', 'calendar', 'patient'])
        ) {
            return true;
        }
    }

    return false;
}

function assistantHasInventoryIntent(string $message, string $normalized): bool
{
    if (assistantKeywordMatch($normalized, [
        'inventory',
        'lot',
        'lots',
        'drug',
        'drugs',
        'medication',
        'medicine',
        'product',
        'stock',
        'on hand',
        'available',
        'expired medicine',
        'expired medicines',
        'expired drug',
        'expired drugs',
        'expired lot',
        'expired inventory',
        'expiring',
        'expires soon',
        'low stock',
        'out of stock'
    ])) {
        return true;
    }

    $trimmed = trim($normalized);
    if ($trimmed !== '' && count(array_filter(preg_split('/\s+/', $trimmed) ?: [])) <= 3) {
        foreach (assistantInventoryKeywords() as $keyword) {
            if (strpos($normalized, $keyword) !== false) {
                return true;
            }
        }
    }

    return preg_match('/\blot\s+[a-z0-9\-_]+\b/i', $message) === 1;
}

function assistantHasBalanceIntent(string $normalized): bool
{
    return assistantKeywordMatch($normalized, [
        'balance',
        'credit',
        'owe',
        'owed',
        'outstanding',
        'billing balance',
        'payment balance',
        'credit history',
        'available credit'
    ]);
}

function assistantHasPosIntent(string $normalized): bool
{
    return assistantKeywordMatch($normalized, [
        'pos',
        'receipt',
        'transaction',
        'transactions',
        'payment',
        'payment fail',
        'payment failed',
        'failed payment',
        'refund',
        'checkout',
        'dispense',
        'administer',
        'backdate'
    ]);
}

function assistantExtractReceiptReference(string $message): string
{
    if (preg_match('/\b((?:inv|bd)-[a-z0-9\-]+)\b/i', $message, $matches)) {
        return trim((string)$matches[1]);
    }

    if (preg_match('/\breceipt\s+([a-z0-9\-#]+)\b/i', $message, $matches)) {
        return trim((string)$matches[1]);
    }

    return '';
}

function assistantFetchPosTransactions(array $filters = []): array
{
    $selectedFacilityId = assistantGetSelectedFacilityId();
    $where = [];
    $binds = [];

    if (!empty($filters['pid'])) {
        $where[] = 'pid = ?';
        $binds[] = (int)$filters['pid'];
    }

    if (!empty($filters['receipt'])) {
        $where[] = 'receipt_number LIKE ?';
        $binds[] = '%' . (string)$filters['receipt'] . '%';
    }

    if (empty($where)) {
        return [];
    }

    if ($selectedFacilityId > 0 && assistantPosTransactionsHaveFacilityColumn()) {
        $where[] = 'facility_id = ?';
        $binds[] = $selectedFacilityId;
    }

    if (assistantPosTransactionsHaveVoidColumns()) {
        $where[] = 'COALESCE(voided, 0) = 0';
    }
    $where[] = "transaction_type != 'void'";

    $sql = "SELECT id, pid, receipt_number, transaction_type, payment_method, amount, created_date
        FROM pos_transactions
        WHERE " . implode(' AND ', $where) . "
        ORDER BY created_date DESC, id DESC
        LIMIT " . max(1, (int)($filters['limit'] ?? 8));

    return assistantFetchRows($sql, $binds);
}

function assistantDetectScheduleDirection(string $normalized): string
{
    if (assistantKeywordMatch($normalized, ['recent appointment', 'recent appointments', 'past appointment', 'past appointments', 'last appointment', 'last appointments', 'previous appointment'])) {
        return 'past';
    }

    return 'future';
}

function assistantExtractScheduleDateRange(string $normalized): array
{
    $today = new DateTime('today');

    if (assistantKeywordMatch($normalized, ['tomorrow', "tomorrow's", 'tomorrows'])) {
        return [
            'date' => (clone $today)->modify('+1 day')->format('Y-m-d'),
            'label' => xl('tomorrow'),
            'title' => xl('Tomorrow')
        ];
    }

    if (assistantKeywordMatch($normalized, ['yesterday', "yesterday's", 'yesterdays'])) {
        return [
            'date' => (clone $today)->modify('-1 day')->format('Y-m-d'),
            'label' => xl('yesterday'),
            'title' => xl('Yesterday')
        ];
    }

    if (assistantKeywordMatch($normalized, ['today', "today's", 'todays'])) {
        return [
            'date' => $today->format('Y-m-d'),
            'label' => xl('today'),
            'title' => xl('Today')
        ];
    }

    return ['date' => '', 'label' => '', 'title' => ''];
}

function assistantInventoryKeywords(): array
{
    return [
        'semaglutide',
        'sema',
        'tirzepatide',
        'tirz',
        'testosterone',
        'trt',
        'lipo',
        'b12',
        'glp'
    ];
}

function assistantUnifiedInventorySearch(string $message, string $normalized, string $term): array
{
    return assistantFetchInventoryRows($message, $term, 4);
}

function assistantUnifiedAppointmentSearch(array $patientRows): array
{
    if (empty($patientRows)) {
        return [];
    }

    $selectedFacilityId = assistantGetSelectedFacilityId();
    $allRows = [];
    foreach (array_slice($patientRows, 0, 2) as $patient) {
        $sql = "SELECT p.fname, p.mname, p.lname, e.pc_eventDate, e.pc_startTime, e.pc_title, e.pc_apptstatus
            FROM openemr_postcalendar_events AS e
            JOIN patient_data AS p ON p.pid = e.pc_pid
            WHERE e.pc_pid = ?
              AND e.pc_pid IS NOT NULL
              AND e.pc_pid != ''";
        $binds = [$patient['pid']];
        if ($selectedFacilityId > 0) {
            $sql .= " AND e.pc_facility = ?";
            $binds[] = $selectedFacilityId;
        }
        $sql .= " ORDER BY e.pc_eventDate DESC, e.pc_startTime DESC
            LIMIT 2";
        $rows = assistantFetchRows($sql, $binds);
        foreach ($rows as $row) {
            $allRows[] = $row;
        }
    }

    return array_slice($allRows, 0, 4);
}

function assistantUnifiedTransactionSearch(array $patientRows, string $term): array
{
    $selectedFacilityId = assistantGetSelectedFacilityId();
    if (!empty($patientRows)) {
        $allRows = [];
        foreach (array_slice($patientRows, 0, 2) as $patient) {
            $sql = "SELECT p.fname, p.mname, p.lname, pt.created_date, pt.transaction_type, pt.receipt_number, pt.amount
                FROM pos_transactions AS pt
                JOIN patient_data AS p ON p.pid = pt.pid
                WHERE pt.pid = ?";
            $binds = [$patient['pid']];
            if ($selectedFacilityId > 0 && assistantPosTransactionsHaveFacilityColumn()) {
                $sql .= " AND (pt.facility_id = ? OR (pt.facility_id IS NULL AND p.facility_id = ?))";
                $binds[] = $selectedFacilityId;
                $binds[] = $selectedFacilityId;
            }
            $sql .= " ORDER BY pt.created_date DESC, pt.id DESC
                LIMIT 2";
            $rows = assistantFetchRows($sql, $binds);
            foreach ($rows as $row) {
                $allRows[] = $row;
            }
        }

        return array_slice($allRows, 0, 4);
    }

    if ($term === '') {
        return [];
    }

    $sql = "SELECT p.fname, p.mname, p.lname, pt.created_date, pt.transaction_type, pt.receipt_number, pt.amount
        FROM pos_transactions AS pt
        LEFT JOIN patient_data AS p ON p.pid = pt.pid
        WHERE (pt.receipt_number LIKE ?
           OR pt.items LIKE ?)";
    $binds = ['%' . $term . '%', '%' . $term . '%'];
    if ($selectedFacilityId > 0 && assistantPosTransactionsHaveFacilityColumn()) {
        $sql .= " AND (pt.facility_id = ? OR (pt.facility_id IS NULL AND p.facility_id = ?))";
        $binds[] = $selectedFacilityId;
        $binds[] = $selectedFacilityId;
    }
    $sql .= " ORDER BY pt.created_date DESC, pt.id DESC
        LIMIT 4";
    return assistantFetchRows($sql, $binds);
}

function assistantBuildSectionedReply(string $title, array $sections, string $footer = ''): string
{
    $parts = [$title];

    foreach ($sections as $sectionTitle => $lines) {
        if (empty($lines)) {
            continue;
        }

        $parts[] = '';
        $parts[] = $sectionTitle;
        foreach ($lines as $line) {
            $parts[] = '- ' . $line;
        }
    }

    if ($footer !== '') {
        $parts[] = '';
        $parts[] = $footer;
    }

    return implode("\n", $parts);
}

function assistantLookupExpiredInventory(string $normalized): string
{
    $soonMode = assistantKeywordMatch($normalized, ['expiring soon', 'expiring', 'expires soon']);
    $outOfStockMode = assistantKeywordMatch($normalized, ['out of stock']);
    $lowStockMode = !$outOfStockMode && assistantKeywordMatch($normalized, ['low stock']);
    $binds = [];
    $sql = assistantBuildInventoryBaseSql();

    if ($outOfStockMode) {
        $sql .= " AND di.on_hand <= 0";
        $title = xl('Out of Stock Medicines');
        $footer = xl('Showing active lots with zero quantity on hand.');
        $sql .= " ORDER BY d.name ASC, di.expiration ASC LIMIT 10";
    } elseif ($lowStockMode) {
        $sql .= " AND di.on_hand > 0 AND di.on_hand <= GREATEST(COALESCE(NULLIF(d.reorder_point, 0), 5), 1)";
        $title = xl('Low Stock Medicines');
        $footer = xl('Showing active lots below the drug reorder point, or 5 units when no reorder point is set.');
        $sql .= " ORDER BY di.on_hand ASC, d.name ASC, di.expiration ASC LIMIT 10";
    } elseif ($soonMode) {
        $sql .= " AND di.on_hand > 0 AND di.expiration IS NOT NULL AND di.expiration >= CURRENT_DATE AND di.expiration <= DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY)";
        $title = xl('Medicines Expiring Soon');
        $footer = xl('Showing active lots expiring in the next 30 days.');
        $sql .= " ORDER BY di.expiration ASC, d.name ASC LIMIT 10";
    } else {
        $sql .= " AND di.on_hand > 0 AND di.expiration IS NOT NULL AND di.expiration < CURRENT_DATE";
        $title = xl('Expired Medicines');
        $footer = xl('These lots are expired based on the stored expiration date.');
        $sql .= " ORDER BY di.expiration ASC, d.name ASC LIMIT 10";
    }

    $rows = assistantFetchRows($sql, $binds);

    if (empty($rows)) {
        if ($outOfStockMode) {
            return assistantBuildReply($title, [xl('No out of stock medicines were found.')]);
        }
        if ($lowStockMode) {
            return assistantBuildReply($title, [xl('No low stock medicines were found.')]);
        }
        return assistantBuildReply($title, [$soonMode ? xl('No medicines are expiring in the next 30 days.') : xl('No expired medicines were found.')]);
    }

    $bullets = [];
    foreach ($rows as $row) {
        $bullets[] = implode(' | ', [
            ($row['name'] ?? xl('Unknown product')),
            xl('Lot') . ' ' . ($row['lot_number'] ?? ''),
            xl('On hand') . ' ' . assistantFormatInventoryQuantity($row['on_hand'] ?? '0'),
            xl('Expires') . ' ' . ($row['expiration'] ?? '')
        ]);
    }

    return assistantBuildReply($title, $bullets, $footer);
}

function assistantBuildInventoryBaseSql(): string
{
    $selectedFacilityId = assistantGetSelectedFacilityId();
    $facilityJoin = assistantTableExists('facility')
        ? " LEFT JOIN facility AS f ON f.id = di.facility_id"
        : '';
    $facilitySelect = assistantTableExists('facility')
        ? ", COALESCE(f.name, '') AS facility_name"
        : ", '' AS facility_name";

    return "SELECT d.name, di.lot_number, di.on_hand, di.expiration, di.warehouse_id" . $facilitySelect . "
        FROM drug_inventory AS di
        JOIN drugs AS d ON d.drug_id = di.drug_id" . $facilityJoin . "
        WHERE di.destroy_date IS NULL
          AND COALESCE(d.active, 1) = 1" . ($selectedFacilityId > 0 ? " AND di.facility_id = " . $selectedFacilityId : '');
}

function assistantFetchInventoryRows(string $message, string $term = '', int $limit = 5): array
{
    $lotNumber = '';
    if (preg_match('/\blot\s+([a-z0-9\-_]+)/i', $message, $matches)) {
        $lotNumber = trim($matches[1]);
    }

    if ($lotNumber === '' && $term === '') {
        return [];
    }

    $sql = assistantBuildInventoryBaseSql();
    $binds = [];

    if ($lotNumber !== '') {
        $sql .= " AND di.lot_number LIKE ?";
        $binds[] = '%' . $lotNumber . '%';
    } else {
        $sql .= " AND (d.name LIKE ? OR di.lot_number LIKE ?)";
        $binds[] = '%' . $term . '%';
        $binds[] = '%' . $term . '%';
    }

    $sql .= " ORDER BY di.on_hand DESC, di.expiration IS NULL ASC, di.expiration ASC, d.name ASC LIMIT " . (int)$limit;
    return assistantFetchRows($sql, $binds);
}

function assistantFormatInventoryQuantity($value): string
{
    $number = (float)$value;
    if (abs($number - round($number)) < 0.0001) {
        return (string)(int)round($number);
    }

    return rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
}

function assistantSuggestActions(string $message, string $normalized): array
{
    $actions = [];
    $webroot = $GLOBALS['webroot'] ?? '';
    $todayVisitListIntent = assistantKeywordMatch($normalized, [
        'today patient visit list',
        'today visit list',
        'todays visit list',
        "today's visit list",
        'today appointment list',
        'todays appointment list',
        "today's appointment list",
        'today patient list',
        'today visits',
        'todays visits',
        "today's visits"
    ]);
    $patientIntent = assistantKeywordMatch($normalized, ['patient', 'find', 'search', 'bloodwork', 'consultation', 'consult', 'visit', 'appointment', 'appointments', 'address', 'balance', 'credit', 'owe', 'outstanding']);
    $inventoryIntent = assistantKeywordMatch($normalized, ['inventory', 'lot', 'drug', 'medication', 'expired', 'expiring', 'low stock', 'stock', 'on hand', 'available']);
    $calendarIntent = assistantKeywordMatch($normalized, ['appointment', 'appointments', 'calendar', 'schedule']);
    $posIntent = assistantKeywordMatch($normalized, ['pos', 'payment', 'checkout', 'dispense', 'administer', 'backdate', 'transaction', 'receipt']);
    $dcrIntent = assistantKeywordMatch($normalized, ['dcr', 'daily collection report', 'daily collections']);

    if ($todayVisitListIntent) {
        $actions[] = [
            'label' => xl('Open Today Calendar'),
            'url' => $webroot . '/interface/main/calendar/index.php?module=PostCalendar&func=view&viewtype=day',
            'target' => '_self'
        ];
    }

    if ($inventoryIntent) {
        $actions[] = [
            'label' => xl('Open Inventory'),
            'url' => $webroot . '/interface/drugs/drug_inventory.php',
            'target' => '_self'
        ];
    }

    if ($calendarIntent) {
        $actions[] = [
            'label' => xl('Open Calendar'),
            'url' => $webroot . '/interface/main/calendar/index.php?module=PostCalendar&func=view&viewtype=day',
            'target' => '_self'
        ];
    }

    if ($patientIntent) {
        $actions[] = [
            'label' => xl('Open Finder'),
            'url' => $webroot . '/interface/main/finder/dynamic_finder.php',
            'target' => '_self'
        ];
    }

    if ($dcrIntent) {
        $actions[] = [
            'label' => xl('Open DCR'),
            'url' => $webroot . '/interface/reports/dcr_daily_collection_report_enhanced.php',
            'target' => '_self'
        ];
    }

    $criteria = assistantExtractPatientCriteria($message, $normalized);
    if (($criteria['type'] ?? '') !== '' && !empty($criteria['value'])) {
        $rows = assistantFindPatients($criteria, 1);
        if (!empty($rows[0]['pid'])) {
            $pid = urlencode((string)$rows[0]['pid']);
            $actions[] = [
                'label' => xl('Open Patient'),
                'url' => $webroot . '/interface/patient_file/summary/demographics.php?set_pid=' . $pid,
                'target' => '_self'
            ];
            if ($posIntent) {
                $actions[] = [
                    'label' => xl('Open POS'),
                    'url' => $webroot . '/interface/pos/pos_modal.php?pid=' . $pid,
                    'target' => '_self'
                ];
            }
            if (assistantKeywordMatch($normalized, ['backdate'])) {
                $actions[] = [
                    'label' => xl('Open Backdate'),
                    'url' => $webroot . '/interface/pos/backdate_pos_screen.php?pid=' . $pid,
                    'target' => '_self'
                ];
            }
        }
    }

    $deduped = [];
    $seen = [];
    foreach ($actions as $action) {
        $key = ($action['label'] ?? '') . '|' . ($action['url'] ?? '');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $deduped[] = $action;
    }

    return array_slice($deduped, 0, 4);
}

function assistantFetchRows(string $sql, array $binds = []): array
{
    $statement = sqlStatement($sql, $binds);
    if (!$statement) {
        return [];
    }

    $rows = [];
    while ($row = sqlFetchArray($statement)) {
        if (is_array($row)) {
            $rows[] = $row;
        } elseif (is_object($row) && property_exists($row, 'fields')) {
            $rows[] = (array)$row->fields;
        }
    }

    return $rows;
}

function assistantFetchSingleRow(string $sql, array $binds = []): array
{
    $row = sqlQuery($sql, $binds);
    if (is_array($row)) {
        return $row;
    }

    if (is_object($row) && property_exists($row, 'fields') && is_array($row->fields)) {
        return $row->fields;
    }

    return [];
}

function assistantGetSelectedFacilityId(): int
{
    return isset($_SESSION['facilityId']) ? (int) $_SESSION['facilityId'] : 0;
}

function assistantTableExists(string $table): bool
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    if ($table === '') {
        return false;
    }

    $row = sqlFetchArray(sqlStatement("SHOW TABLES LIKE ?", [$table]));
    return !empty($row);
}

function assistantPosTransactionsHaveVoidColumns(): bool
{
    static $checked = null;

    if ($checked !== null) {
        return $checked;
    }

    if (!assistantTableExists('pos_transactions')) {
        $checked = false;
        return $checked;
    }

    $row = sqlFetchArray(sqlStatement("SHOW COLUMNS FROM pos_transactions LIKE 'voided'"));
    $checked = !empty($row);
    return $checked;
}

function assistantShouldAttemptRevenueLookup(string $message, string $normalized): bool
{
    if (!assistantKeywordMatch($normalized, ['revenue', 'sales', 'collected', 'collections'])) {
        return false;
    }

    if (
        assistantKeywordMatch($normalized, ['today', "today's", 'todays', 'yesterday', "yesterday's", 'yesterdays', 'this month', 'last month', 'this year', 'last year', 'month', 'year', 'compare', 'vs', 'breakdown', 'per day', 'daily breakdown', 'cash', 'check', 'credit card', 'credit', 'debit', 'facility']) ||
        preg_match('/\b\d{4}-\d{2}-\d{2}\b/', $message) ||
        preg_match('/\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/', $message) ||
        preg_match('/\b20\d{2}\b/', $message) ||
        preg_match('/\b(january|february|march|april|may|june|july|august|september|october|november|december)\b/i', $message)
    ) {
        return true;
    }

    return false;
}

function assistantBuildRevenueRequest(string $message, string $normalized): array
{
    return [
        'range' => assistantExtractRevenueDateRange($message, $normalized),
        'compare' => assistantExtractRevenueComparison($message, $normalized),
        'filters' => assistantExtractRevenueFilters($message, $normalized),
        'want_breakdown' => assistantKeywordMatch($normalized, ['breakdown', 'per day', 'daily breakdown', 'each day']),
    ];
}

function assistantExtractRevenueDateRange(string $message, string $normalized): array
{
    $today = new DateTime('today');
    $monthPattern = 'january|jan|february|feb|march|mar|april|apr|may|june|jun|july|jul|august|aug|september|sep|sept|october|oct|november|nov|december|dec';

    if (assistantKeywordMatch($normalized, ['today', "today's", 'todays'])) {
        $date = $today->format('Y-m-d');
        return ['start' => $date, 'end' => $date, 'kind' => 'day'];
    }

    if (assistantKeywordMatch($normalized, ['yesterday', "yesterday's", 'yesterdays'])) {
        $date = (clone $today)->modify('-1 day')->format('Y-m-d');
        return ['start' => $date, 'end' => $date, 'kind' => 'day'];
    }

    if (assistantKeywordMatch($normalized, ['this month'])) {
        $start = (clone $today)->modify('first day of this month')->format('Y-m-d');
        $end = (clone $today)->modify('last day of this month')->format('Y-m-d');
        return ['start' => $start, 'end' => $end, 'kind' => 'month'];
    }

    if (assistantKeywordMatch($normalized, ['last month'])) {
        $base = (clone $today)->modify('first day of last month');
        $start = $base->format('Y-m-d');
        $end = (clone $base)->modify('last day of this month')->format('Y-m-d');
        return ['start' => $start, 'end' => $end, 'kind' => 'month'];
    }

    if (assistantKeywordMatch($normalized, ['this year'])) {
        $start = (clone $today)->modify('first day of january ' . $today->format('Y'))->format('Y-m-d');
        $end = (clone $today)->modify('last day of december ' . $today->format('Y'))->format('Y-m-d');
        return ['start' => $start, 'end' => $end, 'kind' => 'year'];
    }

    if (assistantKeywordMatch($normalized, ['last year'])) {
        $lastYear = (int)$today->format('Y') - 1;
        $start = DateTime::createFromFormat('Y-m-d', $lastYear . '-01-01');
        $end = DateTime::createFromFormat('Y-m-d', $lastYear . '-12-31');
        if ($start instanceof DateTime && $end instanceof DateTime) {
            return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'kind' => 'year'];
        }
    }

    if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $message, $matches)) {
        return ['start' => $matches[1], 'end' => $matches[1], 'kind' => 'day'];
    }

    if (preg_match('/\b(\d{1,2})\/(\d{1,2})\/(\d{2,4})\b/', $message, $matches)) {
        $year = (int)$matches[3];
        if ($year < 100) {
            $year += 2000;
        }
        $date = DateTime::createFromFormat('Y-n-j', $year . '-' . (int)$matches[1] . '-' . (int)$matches[2]);
        if ($date instanceof DateTime) {
            $formatted = $date->format('Y-m-d');
            return ['start' => $formatted, 'end' => $formatted, 'kind' => 'day'];
        }
    }

    if (preg_match('/\b(' . $monthPattern . ')\s+(\d{1,2})(?:,)?\s+(\d{4})\b/i', $message, $matches)) {
        $date = assistantCreateDateFromMonthToken($matches[1], (int)$matches[2], (int)$matches[3]);
        if ($date instanceof DateTime) {
            $formatted = $date->format('Y-m-d');
            return ['start' => $formatted, 'end' => $formatted, 'kind' => 'day'];
        }
    }

    if (preg_match('/\b(' . $monthPattern . ')(?:\s+(\d{4}))?\b/i', $message, $matches)) {
        $year = !empty($matches[2]) ? (int)$matches[2] : (int)$today->format('Y');
        $monthDate = assistantCreateMonthDateFromToken($matches[1], $year);
        if ($monthDate instanceof DateTime) {
            $start = (clone $monthDate)->modify('first day of this month')->format('Y-m-d');
            $end = (clone $monthDate)->modify('last day of this month')->format('Y-m-d');
            return ['start' => $start, 'end' => $end, 'kind' => 'month'];
        }
    }

    if (preg_match('/\b(20\d{2})\b/', $message, $matches)) {
        $year = (int)$matches[1];
        $start = DateTime::createFromFormat('Y-m-d', $year . '-01-01');
        $end = DateTime::createFromFormat('Y-m-d', $year . '-12-31');
        if ($start instanceof DateTime && $end instanceof DateTime) {
            return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'kind' => 'year'];
        }
    }

    return ['start' => '', 'end' => '', 'kind' => ''];
}

function assistantExtractRevenueComparison(string $message, string $normalized): array
{
    if (!assistantKeywordMatch($normalized, ['compare', 'vs'])) {
        return [];
    }

    $today = new DateTime('today');
    if (assistantKeywordMatch($normalized, ['today', "today's", 'todays']) && assistantKeywordMatch($normalized, ['yesterday', "yesterday's", 'yesterdays'])) {
        return [
            'left' => ['start' => $today->format('Y-m-d'), 'end' => $today->format('Y-m-d'), 'kind' => 'day'],
            'right' => ['start' => (clone $today)->modify('-1 day')->format('Y-m-d'), 'end' => (clone $today)->modify('-1 day')->format('Y-m-d'), 'kind' => 'day'],
        ];
    }

    if (assistantKeywordMatch($normalized, ['this month']) && assistantKeywordMatch($normalized, ['last month'])) {
        $thisMonthStart = (clone $today)->modify('first day of this month')->format('Y-m-d');
        $thisMonthEnd = (clone $today)->modify('last day of this month')->format('Y-m-d');
        $lastMonthBase = (clone $today)->modify('first day of last month');
        return [
            'left' => ['start' => $thisMonthStart, 'end' => $thisMonthEnd, 'kind' => 'month'],
            'right' => ['start' => $lastMonthBase->format('Y-m-d'), 'end' => (clone $lastMonthBase)->modify('last day of this month')->format('Y-m-d'), 'kind' => 'month'],
        ];
    }

    preg_match_all('/\b\d{4}-\d{2}-\d{2}\b/', $message, $isoMatches);
    if (count($isoMatches[0] ?? []) >= 2) {
        return [
            'left' => ['start' => $isoMatches[0][0], 'end' => $isoMatches[0][0], 'kind' => 'day'],
            'right' => ['start' => $isoMatches[0][1], 'end' => $isoMatches[0][1], 'kind' => 'day'],
        ];
    }

    preg_match_all('/\b(20\d{2})\b/', $message, $yearMatches);
    if (count($yearMatches[1] ?? []) >= 2) {
        $leftYear = (int)$yearMatches[1][0];
        $rightYear = (int)$yearMatches[1][1];
        return [
            'left' => ['start' => $leftYear . '-01-01', 'end' => $leftYear . '-12-31', 'kind' => 'year'],
            'right' => ['start' => $rightYear . '-01-01', 'end' => $rightYear . '-12-31', 'kind' => 'year'],
        ];
    }

    if (assistantKeywordMatch($normalized, ['this year']) && assistantKeywordMatch($normalized, ['last year'])) {
        $thisYear = (int)$today->format('Y');
        $lastYear = $thisYear - 1;
        return [
            'left' => ['start' => $thisYear . '-01-01', 'end' => $thisYear . '-12-31', 'kind' => 'year'],
            'right' => ['start' => $lastYear . '-01-01', 'end' => $lastYear . '-12-31', 'kind' => 'year'],
        ];
    }

    return [];
}

function assistantCreateDateFromMonthToken(string $monthToken, int $day, int $year): ?DateTime
{
    $monthDate = assistantCreateMonthDateFromToken($monthToken, $year);
    if (!$monthDate instanceof DateTime) {
        return null;
    }

    return DateTime::createFromFormat('Y-n-j', $monthDate->format('Y') . '-' . (int)$monthDate->format('n') . '-' . $day) ?: null;
}

function assistantCreateMonthDateFromToken(string $monthToken, int $year): ?DateTime
{
    $monthMap = [
        'jan' => 'January',
        'january' => 'January',
        'feb' => 'February',
        'february' => 'February',
        'mar' => 'March',
        'march' => 'March',
        'apr' => 'April',
        'april' => 'April',
        'may' => 'May',
        'jun' => 'June',
        'june' => 'June',
        'jul' => 'July',
        'july' => 'July',
        'aug' => 'August',
        'august' => 'August',
        'sep' => 'September',
        'sept' => 'September',
        'september' => 'September',
        'oct' => 'October',
        'october' => 'October',
        'nov' => 'November',
        'november' => 'November',
        'dec' => 'December',
        'december' => 'December',
    ];

    $normalizedToken = strtolower(trim($monthToken));
    if (empty($monthMap[$normalizedToken])) {
        return null;
    }

    return DateTime::createFromFormat('F Y', $monthMap[$normalizedToken] . ' ' . $year) ?: null;
}

function assistantExtractRevenueFilters(string $message, string $normalized): array
{
    $selectedFacilityId = assistantGetSelectedFacilityId();
    $filters = [
        'payment_method_value' => '',
        'payment_method_label' => '',
        'facility_id' => $selectedFacilityId > 0 ? (string) $selectedFacilityId : '',
        'facility_name' => '',
    ];

    $paymentMap = [
        'credit card' => ['credit_card', xl('Credit Card')],
        'card' => ['credit_card', xl('Credit Card')],
        'debit' => ['credit_card', xl('Credit Card')],
        'cash' => ['cash', xl('Cash')],
        'check' => ['check', xl('Check')],
        'credit' => ['credit', xl('Credit')],
    ];

    foreach ($paymentMap as $keyword => $meta) {
        if (strpos($normalized, $keyword) !== false) {
            $filters['payment_method_value'] = $meta[0];
            $filters['payment_method_label'] = $meta[1];
            break;
        }
    }

    if (assistantKeywordMatch($normalized, ['all facilities']) && $selectedFacilityId <= 0) {
        return $filters;
    }

    if ($selectedFacilityId > 0 && assistantTableExists('facility')) {
        $facility = assistantFetchSingleRow("SELECT name FROM facility WHERE id = ?", [$selectedFacilityId]);
        if (!empty($facility['name'])) {
            $filters['facility_name'] = (string) $facility['name'];
        }
    } elseif (assistantTableExists('facility')) {
        $facilities = assistantFetchRows("SELECT id, name FROM facility WHERE name IS NOT NULL AND name != '' ORDER BY name ASC");
        foreach ($facilities as $facility) {
            $name = trim((string)($facility['name'] ?? ''));
            if ($name !== '' && strpos($normalized, assistantNormalizeMessage($name)) !== false) {
                $filters['facility_id'] = (string)($facility['id'] ?? '');
                $filters['facility_name'] = $name;
                break;
            }
        }
    }

    return $filters;
}

function assistantFetchRevenueSummary(array $range, array $filters = []): array
{
    [$whereSql, $binds] = assistantBuildRevenueWhere($range, $filters);

    $summary = sqlQuery(
        "SELECT
            COUNT(*) AS transaction_count,
            COALESCE(SUM(amount), 0) AS total_revenue,
            COALESCE(SUM(CASE WHEN LOWER(COALESCE(payment_method, '')) LIKE '%cash%' THEN amount ELSE 0 END), 0) AS cash_revenue,
            COALESCE(SUM(CASE WHEN LOWER(COALESCE(payment_method, '')) LIKE '%check%' THEN amount ELSE 0 END), 0) AS check_revenue,
            COALESCE(SUM(CASE
                WHEN LOWER(COALESCE(payment_method, '')) LIKE '%card%'
                  OR LOWER(COALESCE(payment_method, '')) LIKE '%credit%'
                  OR LOWER(COALESCE(payment_method, '')) LIKE '%debit%'
                  OR LOWER(COALESCE(payment_method, '')) IN ('affirm', 'zip', 'sezzle', 'afterpay', 'fsa_hsa')
                THEN amount ELSE 0 END), 0) AS card_revenue,
            COALESCE(SUM(CASE
                WHEN LOWER(COALESCE(payment_method, '')) LIKE '%cash%' THEN 0
                WHEN LOWER(COALESCE(payment_method, '')) LIKE '%check%' THEN 0
                WHEN LOWER(COALESCE(payment_method, '')) LIKE '%card%' THEN 0
                WHEN LOWER(COALESCE(payment_method, '')) LIKE '%credit%' THEN 0
                WHEN LOWER(COALESCE(payment_method, '')) LIKE '%debit%' THEN 0
                WHEN LOWER(COALESCE(payment_method, '')) IN ('affirm', 'zip', 'sezzle', 'afterpay', 'fsa_hsa') THEN 0
                ELSE amount
            END), 0) AS other_revenue
         FROM pos_transactions
         WHERE " . $whereSql,
        $binds
    );

    if (is_array($summary)) {
        return $summary;
    }

    if (is_object($summary) && property_exists($summary, 'fields') && is_array($summary->fields)) {
        return $summary->fields;
    }

    return [];
}

function assistantFetchRevenueDailyBreakdown(array $range, array $filters = []): array
{
    [$whereSql, $binds] = assistantBuildRevenueWhere($range, $filters);

    return assistantFetchRows(
        "SELECT
            DATE(created_date) AS revenue_date,
            COUNT(*) AS transaction_count,
            COALESCE(SUM(amount), 0) AS total_revenue
         FROM pos_transactions
         WHERE " . $whereSql . "
         GROUP BY DATE(created_date)
         ORDER BY DATE(created_date) ASC",
        $binds
    );
}

function assistantBuildRevenueComparisonReply(array $request): string
{
    $left = $request['compare']['left'] ?? [];
    $right = $request['compare']['right'] ?? [];
    $filters = $request['filters'] ?? [];

    if (empty($left['start']) || empty($right['start'])) {
        return '';
    }

    $leftSummary = assistantFetchRevenueSummary($left, $filters);
    $rightSummary = assistantFetchRevenueSummary($right, $filters);

    $leftRevenue = round((float)($leftSummary['total_revenue'] ?? 0), 2);
    $rightRevenue = round((float)($rightSummary['total_revenue'] ?? 0), 2);
    $difference = round($leftRevenue - $rightRevenue, 2);

    $bullets = [
        assistantFormatRevenuePeriodLabel($left['start'], $left['end'], (string)($left['kind'] ?? 'range')) . ': $' . number_format($leftRevenue, 2) . ' | ' . xl('Transactions') . ' ' . (int)($leftSummary['transaction_count'] ?? 0),
        assistantFormatRevenuePeriodLabel($right['start'], $right['end'], (string)($right['kind'] ?? 'range')) . ': $' . number_format($rightRevenue, 2) . ' | ' . xl('Transactions') . ' ' . (int)($rightSummary['transaction_count'] ?? 0),
        xl('Difference') . ': ' . ($difference >= 0 ? '+' : '-') . '$' . number_format(abs($difference), 2),
    ];

    $footer = xl('Showing finalized POS revenue comparison.');
    if (!empty($filters['payment_method_label'])) {
        $footer .= ' ' . xl('Payment method filter') . ': ' . $filters['payment_method_label'] . '.';
    }
    if (!empty($filters['facility_name'])) {
        $footer .= ' ' . xl('Facility filter') . ': ' . $filters['facility_name'] . '.';
    }

    return assistantBuildReply(xl('Revenue Comparison'), $bullets, $footer);
}

function assistantBuildRevenueWhere(array $range, array $filters = []): array
{
    $startDate = DateTime::createFromFormat('Y-m-d', (string)($range['start'] ?? ''));
    $endDate = DateTime::createFromFormat('Y-m-d', (string)($range['end'] ?? ''));

    if (!$startDate instanceof DateTime || !$endDate instanceof DateTime) {
        return ['1 = 0', []];
    }

    $startDateTime = $startDate->format('Y-m-d 00:00:00');
    $endExclusive = (clone $endDate)->modify('+1 day')->format('Y-m-d 00:00:00');

    $where = ["created_date >= ?", "created_date < ?"];
    $binds = [$startDateTime, $endExclusive];

    if (!empty($filters['payment_method_value'])) {
        [$paymentSql, $paymentBinds] = assistantBuildPaymentMethodWhere((string)$filters['payment_method_value']);
        if ($paymentSql !== '') {
            $where[] = $paymentSql;
            $binds = array_merge($binds, $paymentBinds);
        }
    }

    if (!empty($filters['facility_id']) && assistantPosTransactionsHaveFacilityColumn()) {
        $where[] = "facility_id = ?";
        $binds[] = $filters['facility_id'];
    }

    if (assistantPosTransactionsHaveVoidColumns()) {
        $where[] = "COALESCE(voided, 0) = 0";
    }
    $where[] = "transaction_type != 'void'";

    return [implode(' AND ', $where), $binds];
}

function assistantBuildPaymentMethodWhere(string $paymentMethodValue): array
{
    $normalized = strtolower(trim($paymentMethodValue));
    if ($normalized === '') {
        return ['', []];
    }

    if ($normalized === 'credit_card') {
        return [
            "(LOWER(COALESCE(payment_method, '')) LIKE '%card%' OR LOWER(COALESCE(payment_method, '')) LIKE '%credit%' OR LOWER(COALESCE(payment_method, '')) LIKE '%debit%' OR LOWER(COALESCE(payment_method, '')) IN ('affirm', 'zip', 'sezzle', 'afterpay', 'fsa_hsa'))",
            []
        ];
    }

    return ["LOWER(COALESCE(payment_method, '')) = ?", [$normalized]];
}

function assistantPosTransactionsHaveFacilityColumn(): bool
{
    static $checked = null;

    if ($checked !== null) {
        return $checked;
    }

    if (!assistantTableExists('pos_transactions')) {
        $checked = false;
        return $checked;
    }

    $row = sqlFetchArray(sqlStatement("SHOW COLUMNS FROM pos_transactions LIKE 'facility_id'"));
    $checked = !empty($row);
    return $checked;
}

function assistantFormatAssistantDate(string $date): string
{
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    return $dateObj instanceof DateTime ? $dateObj->format('F j, Y') : $date;
}

function assistantFormatAssistantDateTime(string $dateTime): string
{
    $dateObj = DateTime::createFromFormat('Y-m-d H:i:s', $dateTime);
    if (!$dateObj instanceof DateTime) {
        $dateObj = new DateTime($dateTime ?: 'now');
    }

    return $dateObj->format('F j, Y g:i A');
}

function assistantFormatRevenuePeriodLabel(string $start, string $end, string $kind): string
{
    if ($kind === 'day' || $start === $end) {
        return assistantFormatAssistantDate($start);
    }

    if ($kind === 'month') {
        $dateObj = DateTime::createFromFormat('Y-m-d', $start);
        if ($dateObj instanceof DateTime) {
            return $dateObj->format('F Y');
        }
    }

    if ($kind === 'year') {
        $dateObj = DateTime::createFromFormat('Y-m-d', $start);
        if ($dateObj instanceof DateTime) {
            return $dateObj->format('Y');
        }
    }

    return assistantFormatAssistantDate($start) . ' - ' . assistantFormatAssistantDate($end);
}

function assistantShouldAttemptDcrMetricLookup(string $normalized): bool
{
    return assistantKeywordMatch($normalized, [
        'deposit',
        'deposits',
        'bank deposit',
        'gross patient count',
        'gross count',
        'patient count',
        'unique patients',
        'new patients',
        'returning patients',
        'shot tracker',
        'shot count',
        'lipo cards',
        'sg trz',
        'sg/trz',
        'revenue by medicine',
        'medicine revenue',
        'medicine breakdown',
        'drug revenue',
        'revenue by drug',
        'dcr by facility',
        'revenue by facility',
        'facility breakdown',
        'facility revenue'
    ]);
}

function assistantFetchRevenueTransactions(array $range, array $filters = [], array $transactionTypes = []): array
{
    [$whereSql, $binds] = assistantBuildRevenueWhere($range, $filters);

    $selectFacility = assistantPosTransactionsHaveFacilityColumn() ? 'facility_id' : "NULL AS facility_id";
    $sql = "SELECT
            id,
            pid,
            receipt_number,
            transaction_type,
            payment_method,
            amount,
            items,
            created_date,
            " . $selectFacility . "
        FROM pos_transactions
        WHERE " . $whereSql;

    if (!empty($transactionTypes)) {
        $placeholders = implode(', ', array_fill(0, count($transactionTypes), '?'));
        $sql .= " AND transaction_type IN (" . $placeholders . ")";
        foreach ($transactionTypes as $transactionType) {
            $binds[] = $transactionType;
        }
    }

    $sql .= " ORDER BY created_date ASC, id ASC";

    return assistantFetchRows($sql, $binds);
}

function assistantBuildDepositLookupReply(array $request): string
{
    $rows = assistantFetchRevenueTransactions(
        $request['range'],
        $request['filters'],
        ['purchase', 'purchase_and_dispens', 'external_payment']
    );

    if (empty($rows)) {
        return assistantBuildReply(
            xl('Deposit Lookup'),
            [
                sprintf(
                    xl('I did not find deposit-related POS transactions between %s and %s.'),
                    assistantFormatAssistantDate($request['range']['start']),
                    assistantFormatAssistantDate($request['range']['end'])
                )
            ],
            xl('Deposits are based on purchase, purchase-and-dispense, and external payment transactions.')
        );
    }

    $cash = 0.0;
    $checks = 0.0;
    $credit = 0.0;

    foreach ($rows as $row) {
        $amount = round((float)($row['amount'] ?? 0), 2);
        $paymentMethod = strtolower(trim((string)($row['payment_method'] ?? '')));

        if (strpos($paymentMethod, 'cash') !== false) {
            $cash += $amount;
        } elseif (strpos($paymentMethod, 'check') !== false) {
            $checks += $amount;
        } else {
            $credit += $amount;
        }
    }

    $actualBankDeposit = round($cash + $checks, 2);
    $totalRevenue = round($cash + $checks + $credit, 2);

    return assistantBuildReply(
        xl('Deposit Lookup'),
        [
            xl('Period') . ': ' . assistantFormatRevenuePeriodLabel($request['range']['start'], $request['range']['end'], (string)($request['range']['kind'] ?? 'range')),
            xl('Actual Bank Deposit') . ': $' . number_format($actualBankDeposit, 2),
            xl('Cash') . ': $' . number_format($cash, 2),
            xl('Checks') . ': $' . number_format($checks, 2),
            xl('Card / Credit / Financing') . ': $' . number_format($credit, 2),
            xl('Deposit Transactions') . ': ' . count($rows),
            xl('Total Revenue in Deposit View') . ': $' . number_format($totalRevenue, 2),
        ],
        xl('This follows the DCR deposit logic where actual bank deposit is cash plus checks, while cards stay revenue but not bank deposit.')
    );
}

function assistantBuildGrossPatientCountReply(array $request): string
{
    $rows = assistantFetchRevenueTransactions($request['range'], $request['filters']);
    if (empty($rows)) {
        return assistantBuildReply(
            xl('Gross Patient Count'),
            [
                sprintf(
                    xl('I did not find finalized POS transactions between %s and %s.'),
                    assistantFormatAssistantDate($request['range']['start']),
                    assistantFormatAssistantDate($request['range']['end'])
                )
            ]
        );
    }

    $uniquePatients = [];
    foreach ($rows as $row) {
        $pid = (int)($row['pid'] ?? 0);
        if ($pid > 0) {
            $uniquePatients[$pid] = true;
        }
    }

    $newCount = 0;
    $returningCount = 0;
    foreach (array_keys($uniquePatients) as $pid) {
        if (assistantIsNewPatientForDcr($pid, (string)$request['range']['start'])) {
            $newCount++;
        } else {
            $returningCount++;
        }
    }

    return assistantBuildReply(
        xl('Gross Patient Count'),
        [
            xl('Period') . ': ' . assistantFormatRevenuePeriodLabel($request['range']['start'], $request['range']['end'], (string)($request['range']['kind'] ?? 'range')),
            xl('Unique Patients') . ': ' . count($uniquePatients),
            xl('New Patients') . ': ' . $newCount,
            xl('Returning Patients') . ': ' . $returningCount,
            xl('Transactions') . ': ' . count($rows),
        ],
        xl('New vs returning is based on whether the patient had any earlier non-void POS transactions before the start of the selected period.')
    );
}

function assistantBuildShotTrackerReply(array $request): string
{
    $rows = assistantFetchRevenueTransactions($request['range'], $request['filters']);
    if (empty($rows)) {
        return assistantBuildReply(
            xl('Shot Tracker'),
            [
                sprintf(
                    xl('I did not find finalized POS transactions between %s and %s.'),
                    assistantFormatAssistantDate($request['range']['start']),
                    assistantFormatAssistantDate($request['range']['end'])
                )
            ]
        );
    }

    $perDay = [];
    foreach ($rows as $row) {
        $dateKey = substr((string)($row['created_date'] ?? ''), 0, 10);
        if ($dateKey === '') {
            continue;
        }

        if (!isset($perDay[$dateKey])) {
            $perDay[$dateKey] = [
                'lipo_purchased' => 0,
                'sema_purchased' => 0,
                'tirz_purchased' => 0,
            ];
        }

        $items = json_decode((string)($row['items'] ?? ''), true);
        if (!is_array($items)) {
            continue;
        }

        $transactionType = strtolower(trim((string)($row['transaction_type'] ?? '')));
        $countsDispense = in_array($transactionType, [
            'dispense',
            'alternate_lot_dispense',
            'dispense_and_administer',
            'purchase_and_dispens',
            'purchase_and_alterna',
            'marketplace_dispense'
        ], true);

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = assistantClassifyDcrItemType($item);
            $quantity = (int)($item['quantity'] ?? 0);
            $dispenseQty = (int)($item['dispense_quantity'] ?? 0);
            $administerQty = (int)($item['administer_quantity'] ?? 0);
            $takeHomeQty = $countsDispense ? $dispenseQty : 0;
            $isPrepaidItem = !empty($item['prepay_selected']);
            $purchasedQty = $isPrepaidItem ? 0 : $quantity;

            if ($dispenseQty === 0 && $administerQty === 0 && $quantity > 0 && $countsDispense) {
                $takeHomeQty = $quantity;
            }

            if ($type === 'lipo' && $purchasedQty > 0) {
                $perDay[$dateKey]['lipo_purchased'] += $purchasedQty;
            } elseif ($type === 'sema' && $purchasedQty > 0) {
                $perDay[$dateKey]['sema_purchased'] += $purchasedQty;
            } elseif ($type === 'tirz' && $purchasedQty > 0) {
                $perDay[$dateKey]['tirz_purchased'] += $purchasedQty;
            }
        }
    }

    if (empty($perDay)) {
        return assistantBuildReply(
            xl('Shot Tracker'),
            [xl('I found transactions in that period, but none with sold shot quantities that contribute to the DCR shot tracker.')],
            xl('The shot tracker depends on the DCR card-count math for sold quantities.')
        );
    }

    ksort($perDay);
    $lines = [
        xl('Period') . ': ' . assistantFormatRevenuePeriodLabel($request['range']['start'], $request['range']['end'], (string)($request['range']['kind'] ?? 'range'))
    ];
    $totals = [
        'lipo_cards' => 0,
        'lipo_total' => 0,
        'sg_trz_cards' => 0,
        'sg_trz_total' => 0,
        'tes_cards' => 0,
        'tes_total' => 0,
    ];

    foreach ($perDay as $dateKey => $values) {
        $lipoCards = (int)floor(((int)$values['lipo_purchased']) / 5);
        $lipoTotal = $lipoCards * 4;
        $sgTrzSold = (int)$values['sema_purchased'] + (int)$values['tirz_purchased'];
        $sgTrzCards = (int)floor($sgTrzSold / 4);
        $sgTrzTotal = $sgTrzCards * 4;

        $totals['lipo_cards'] += $lipoCards;
        $totals['lipo_total'] += $lipoTotal;
        $totals['sg_trz_cards'] += $sgTrzCards;
        $totals['sg_trz_total'] += $sgTrzTotal;

        $lines[] = assistantFormatAssistantDate($dateKey) . ' | ' .
            xl('LIPO') . ' ' . $lipoCards . ' ' . xl('cards') . ' / ' . $lipoTotal . ' | ' .
            xl('SG+TRZ') . ' ' . $sgTrzCards . ' ' . xl('cards') . ' / ' . $sgTrzTotal;
    }

    $lines[] = xl('LIPO Total Cards') . ': ' . $totals['lipo_cards'];
    $lines[] = xl('LIPO Total Units') . ': ' . $totals['lipo_total'];
    $lines[] = xl('SG/TRZ Total Cards') . ': ' . $totals['sg_trz_cards'];
    $lines[] = xl('SG/TRZ Total Units') . ': ' . $totals['sg_trz_total'];
    $lines[] = xl('TES Total Cards') . ': 0';

    return assistantBuildReply(
        xl('Shot Tracker'),
        $lines,
        xl('This mirrors the DCR shot tracker card math: LIPO cards are sold quantities divided by 5 with a 4-plus-1-free cycle, and SG/TRZ cards are combined sold quantities divided by 4.')
    );
}

function assistantBuildMedicineRevenueReply(array $request): string
{
    $rows = assistantFetchRevenueTransactions($request['range'], $request['filters']);
    if (empty($rows)) {
        return assistantBuildReply(
            xl('Revenue by Medicine'),
            [
                sprintf(
                    xl('I did not find finalized POS transactions between %s and %s.'),
                    assistantFormatAssistantDate($request['range']['start']),
                    assistantFormatAssistantDate($request['range']['end'])
                )
            ]
        );
    }

    $medicineTotals = [];
    foreach ($rows as $row) {
        $items = json_decode((string)($row['items'] ?? ''), true);
        if (!is_array($items)) {
            continue;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = trim((string)($item['name'] ?? ''));
            if ($label === '') {
                $label = trim((string)($item['display_name'] ?? ''));
            }
            if ($label === '') {
                $label = xl('Unknown Item');
            }

            $quantity = (int)($item['quantity'] ?? 0);
            $price = (float)($item['price'] ?? 0);
            $itemTotal = round((float)($item['total'] ?? ($price * $quantity)), 2);

            if (!isset($medicineTotals[$label])) {
                $medicineTotals[$label] = [
                    'revenue' => 0.0,
                    'quantity' => 0,
                    'count' => 0,
                ];
            }

            $medicineTotals[$label]['revenue'] += $itemTotal;
            $medicineTotals[$label]['quantity'] += $quantity;
            $medicineTotals[$label]['count']++;
        }
    }

    if (empty($medicineTotals)) {
        return assistantBuildReply(
            xl('Revenue by Medicine'),
            [xl('I found transactions in that period, but no readable item payloads to aggregate by medicine.')],
            xl('This view depends on item JSON stored on the POS transactions.')
        );
    }

    uasort($medicineTotals, static function (array $left, array $right): int {
        return ($right['revenue'] <=> $left['revenue']);
    });

    $lines = [
        xl('Period') . ': ' . assistantFormatRevenuePeriodLabel($request['range']['start'], $request['range']['end'], (string)($request['range']['kind'] ?? 'range'))
    ];
    foreach (array_slice($medicineTotals, 0, 10, true) as $name => $stats) {
        $lines[] = $name . ' | $' . number_format((float)$stats['revenue'], 2) . ' | ' . xl('Qty') . ' ' . (int)$stats['quantity'] . ' | ' . xl('Line Items') . ' ' . (int)$stats['count'];
    }

    return assistantBuildReply(
        xl('Revenue by Medicine'),
        $lines,
        xl('Showing the top medicine and item names by POS item revenue for the selected period.')
    );
}

function assistantBuildFacilityDcrReply(array $request): string
{
    if (!assistantPosTransactionsHaveFacilityColumn() || !assistantTableExists('facility')) {
        return assistantBuildReply(
            xl('DCR by Facility'),
            [xl('This system does not currently expose facility-linked POS transactions, so I cannot group DCR metrics by facility.')],
            xl('A facility_id column on pos_transactions and the facility table are both required.')
        );
    }

    [$whereSql, $binds] = assistantBuildRevenueWhere($request['range'], []);
    $rows = assistantFetchRows(
        "SELECT
            COALESCE(f.name, '" . addslashes(xl('Unknown Facility')) . "') AS facility_name,
            COUNT(*) AS transaction_count,
            COUNT(DISTINCT pt.pid) AS patient_count,
            COALESCE(SUM(pt.amount), 0) AS total_revenue
         FROM pos_transactions AS pt
         LEFT JOIN patient_data AS p ON p.pid = pt.pid
         LEFT JOIN facility AS f ON f.id = COALESCE(pt.facility_id, p.facility_id)
         WHERE " . $whereSql . "
         GROUP BY COALESCE(pt.facility_id, p.facility_id), f.name
         ORDER BY total_revenue DESC, facility_name ASC",
        $binds
    );

    if (empty($rows)) {
        return assistantBuildReply(
            xl('DCR by Facility'),
            [
                sprintf(
                    xl('I did not find facility-based POS activity between %s and %s.'),
                    assistantFormatAssistantDate($request['range']['start']),
                    assistantFormatAssistantDate($request['range']['end'])
                )
            ]
        );
    }

    $lines = [
        xl('Period') . ': ' . assistantFormatRevenuePeriodLabel($request['range']['start'], $request['range']['end'], (string)($request['range']['kind'] ?? 'range'))
    ];
    foreach ($rows as $row) {
        $lines[] = trim((string)($row['facility_name'] ?? xl('Unknown Facility'))) .
            ' | $' . number_format((float)($row['total_revenue'] ?? 0), 2) .
            ' | ' . xl('Patients') . ' ' . (int)($row['patient_count'] ?? 0) .
            ' | ' . xl('Transactions') . ' ' . (int)($row['transaction_count'] ?? 0);
    }

    return assistantBuildReply(
        xl('DCR by Facility'),
        $lines,
        xl('This is a facility rollup from finalized POS transactions in the selected period.')
    );
}

function assistantIsNewPatientForDcr(int $pid, string $referenceDate): bool
{
    if ($pid <= 0 || $referenceDate === '') {
        return true;
    }

    $sql = "SELECT COUNT(*) AS previous_visits
        FROM pos_transactions
        WHERE pid = ?
          AND DATE(created_date) < ?";
    $binds = [$pid, $referenceDate];

    if (assistantPosTransactionsHaveVoidColumns()) {
        $sql .= " AND COALESCE(voided, 0) = 0";
    }
    $sql .= " AND transaction_type != 'void'";

    $row = sqlQuery($sql, $binds);
    return ((int)($row['previous_visits'] ?? 0)) === 0;
}

function assistantClassifyDcrItemType(array $item): string
{
    $candidates = [
        strtolower(trim((string)($item['name'] ?? ''))),
        strtolower(trim((string)($item['display_name'] ?? '')))
    ];

    foreach ($candidates as $normalized) {
        if ($normalized === '') {
            continue;
        }
        if (strpos($normalized, 'afterpay') !== false) {
            return 'afterpay';
        }
        if (strpos($normalized, 'tax') !== false) {
            return 'tax';
        }
        if (strpos($normalized, 'tirz') !== false || strpos($normalized, 'tirzep') !== false) {
            return 'tirz';
        }
        if (strpos($normalized, 'sema') !== false || strpos($normalized, 'semaglutide') !== false) {
            return 'sema';
        }
        if (strpos($normalized, 'lipo') !== false || strpos($normalized, 'b12') !== false) {
            return 'lipo';
        }
        if ($normalized === 'phentermine #28' || $normalized === 'phentermine #14') {
            return 'pills';
        }
        if (strpos($normalized, 'supplement') !== false || strpos($normalized, 'bari') !== false || strpos($normalized, 'metatrim') !== false || strpos($normalized, 'lipobc') !== false) {
            return 'supplement';
        }
        if (strpos($normalized, 'shake') !== false || strpos($normalized, 'drink') !== false || strpos($normalized, 'protein') !== false) {
            return 'drink';
        }
        if (strpos($normalized, 'testo') !== false || strpos($normalized, 'testosterone') !== false) {
            return 'testosterone';
        }
    }

    return 'other';
}

function assistantPreferredPhone(array $row): string
{
    return trim((string)($row['phone_cell'] ?? '')) ?: trim((string)($row['phone_home'] ?? ''));
}

function assistantFormatPatientName(array $row): string
{
    return trim(implode(' ', array_filter([
        $row['fname'] ?? '',
        $row['mname'] ?? '',
        $row['lname'] ?? ''
    ])));
}

function assistantFormatPatientLabel(array $row): string
{
    $label = assistantFormatPatientName($row);
    $label .= ' (' . xl('PID') . ' ' . ($row['pid'] ?? '') . ')';

    if (!empty($row['pubpid'])) {
        $label .= ' | ' . xl('ID') . ' ' . $row['pubpid'];
    }

    return $label;
}

function assistantFormatAddress(array $row): string
{
    $parts = array_filter([
        trim((string)($row['street'] ?? '')),
        trim(implode(', ', array_filter([
            $row['city'] ?? '',
            $row['state'] ?? ''
        ]))),
        trim((string)($row['postal_code'] ?? '')),
        trim((string)($row['country_code'] ?? ''))
    ]);

    return implode(' | ', $parts);
}

function assistantGetPatientCreditBalance(int $pid): float
{
    if ($pid <= 0) {
        return 0.0;
    }

    $row = sqlQuery("SELECT balance FROM patient_credit_balance WHERE pid = ?", [$pid]);
    if (!is_array($row) || !isset($row['balance']) || $row['balance'] === null || $row['balance'] === '') {
        return 0.0;
    }

    return round((float) $row['balance'], 2);
}

function assistantGetPatientOutstandingBalance(int $pid): float
{
    if ($pid <= 0) {
        return 0.0;
    }

    $billingCharges = sqlQuery(
        "SELECT SUM(fee) AS total_charges FROM billing WHERE pid = ? AND activity = 1 AND fee > 0",
        [$pid]
    );
    $drugCharges = sqlQuery(
        "SELECT SUM(fee) AS total_charges FROM drug_sales WHERE pid = ? AND fee > 0",
        [$pid]
    );
    $payments = sqlQuery(
        "SELECT SUM(pay_amount) AS total_payments FROM ar_activity WHERE pid = ? AND deleted IS NULL AND pay_amount > 0",
        [$pid]
    );

    $billingTotal = (is_array($billingCharges) && isset($billingCharges['total_charges']) && $billingCharges['total_charges'] !== null && $billingCharges['total_charges'] !== '')
        ? (float) $billingCharges['total_charges']
        : 0.0;
    $drugTotal = (is_array($drugCharges) && isset($drugCharges['total_charges']) && $drugCharges['total_charges'] !== null && $drugCharges['total_charges'] !== '')
        ? (float) $drugCharges['total_charges']
        : 0.0;
    $paymentTotal = (is_array($payments) && isset($payments['total_payments']) && $payments['total_payments'] !== null && $payments['total_payments'] !== '')
        ? (float) $payments['total_payments']
        : 0.0;

    return round(max(0, ($billingTotal + $drugTotal) - $paymentTotal), 2);
}
