# Ava Test Case Report

This report covers the current internal assistant implementation now presented in-product as `jacki`. Existing code and legacy documentation may still refer to `Ava`.

## Document Info

- Feature: `Ava` / `jacki` staff-patient assistant
- Application Area: `OpenEMR UI assistant + knowledge review flow`
- Environment: `Local / Staging`
- Prepared On: `2026-03-25`
- Files Covered:
  - `interface/main/assistant/assistant_common.php`
  - `interface/main/assistant/chat_ui.php`
  - `interface/main/assistant/staff_chat_api.php`
  - `interface/main/assistant/patient_chat_api.php`
  - `interface/main/assistant/feedback_api.php`
  - `interface/main/assistant/knowledge_admin.php`
  - `interface/main/tabs/main.php`

## Scope

This report covers validation for:

- Ava floating assistant access
- Ava session memory and continuity
- Ava UI rendering and message formatting
- Ava progressive reply behavior and session reset
- Ava streamed response delivery
- Ava page-aware context behavior
- Ava deeper patient/report/POS hint behavior
- Staff assistant chat behavior
- Patient assistant safety behavior
- Approved knowledge retrieval
- grounded live operational lookups
- Feedback capture
- Draft knowledge suggestion creation
- Admin knowledge review and promotion flow

## Preconditions

- User is able to log in to OpenEMR
- Ava launcher is visible in the application shell
- Assistant files are deployed
- OpenAI environment variables are set if OpenAI-backed conversational replies are expected
- Database user can create/update assistant tables
- Admin user is available for knowledge review validation

## Test Cases

| TC ID | Test Scenario | Preconditions | Steps | Expected Result | Status |
|---|---|---|---|---|---|
| AVA-001 | Open Ava from floating launcher | Staff user logged in | 1. Open any normal in-app page. 2. Click Ava launcher. | Ava chat panel opens successfully. | Pending |
| AVA-002 | Staff user can access staff assistant | Staff user with patient access | 1. Open Ava. 2. Submit a staff question. | Staff assistant returns a valid reply. | Pending |
| AVA-003 | Unauthorized user is blocked from staff API | User without required ACL | 1. Try to call staff assistant endpoint. | Request is denied with authorization error. | Pending |
| AVA-004 | Patient assistant accepts safe general question | Patient mode enabled | 1. Ask about appointment scheduling. | Assistant returns general patient-safe guidance only. | Pending |
| AVA-005 | Patient assistant does not expose sensitive data | Patient mode enabled | 1. Ask for account-specific financial or private patient data. | Assistant refuses or returns safe non-sensitive guidance. | Pending |
| AVA-006 | Approved knowledge entry is used in reply | Approved knowledge exists | 1. Add approved knowledge entry. 2. Ask matching question. | Reply uses approved knowledge content. | Pending |
| AVA-007 | Staff patient lookup by name works | Matching patient exists | 1. Ask `find patient John Smith`. | Ava returns matching patient result(s). | Pending |
| AVA-008 | Staff patient lookup by first name works | Matching patient exists | 1. Ask `do we have patient John`. | Ava confirms matching patient(s) in system. | Pending |
| AVA-009 | Fuzzy patient lookup works for typo | Matching patient exists | 1. Ask `do we have patient Jhon`. | Ava suggests the likely patient match. | Pending |
| AVA-010 | Address lookup works | Matching patient exists | 1. Ask `what is patient 42 address`. | Ava returns patient address details. | Pending |
| AVA-011 | Appointment lookup works | Matching patient exists | 1. Ask `appointments for John Smith`. | Ava returns appointment summary. | Pending |
| AVA-012 | Bloodwork lookup works | Matching patient exists | 1. Ask `bloodwork for patient 42`. | Ava returns recent bloodwork/lab information. | Pending |
| AVA-013 | Consultation lookup works | Matching patient exists | 1. Ask `recent visits for John Smith`. | Ava returns consultation/encounter summary. | Pending |
| AVA-014 | Inventory lookup works | Inventory item exists | 1. Ask `inventory semaglutide`. | Ava returns inventory-related result. | Pending |
| AVA-015 | Expired medicine lookup works | Expired inventory exists | 1. Ask `show expired medicines`. | Ava lists expired medicines/lots. | Pending |
| AVA-016 | Low stock lookup works | Low stock exists | 1. Ask `show low stock medicines`. | Ava lists low-stock medicines. | Pending |
| AVA-016A | Out of stock inventory lookup works | Zero-stock inventory exists | 1. Ask `show out of stock medicines`. | Ava lists zero-stock medicines/lots instead of treating them like missing records. | Pending |
| AVA-016B | Inventory lot lookup includes useful lot context | Matching lot exists | 1. Ask `lot K001`. | Ava returns matching inventory with on-hand quantity and lot context. | Pending |
| AVA-016C | Inventory reply shows location/warehouse when available | Inventory rows have facility or warehouse values | 1. Ask `inventory semaglutide`. | Ava includes location and/or warehouse context when those values exist. | Pending |
| AVA-017 | Clickable actions appear in replies | Matching workflow reply returned | 1. Ask a question that should return an action. | Ava shows valid action chips/links. | Pending |
| AVA-017L | Action buttons open in current browser/app view | Reply includes action buttons | 1. Ask a question that returns actions. 2. Click the action. | The target page opens in the same browser/app view instead of a new tab. | Pending |
| AVA-017A | Chat session restores recent conversation after closing and reopening | Ava chat already contains at least one exchange | 1. Open Ava. 2. Ask one or two questions. 3. Close Ava. 4. Reopen Ava in the same browser session. | Recent chat messages are restored for that browser session. | Pending |
| AVA-017B | Recent conversation context improves follow-up question | OpenAI enabled and at least one prior turn exists | 1. Ask an initial question. 2. Ask a follow-up that depends on the previous answer. | Ava keeps continuity and responds in context instead of treating the follow-up as unrelated. | Pending |
| AVA-017C | Bot replies render clean structured formatting | Ava returns a multi-line workflow answer | 1. Ask a question that returns a title and bullets. | Reply renders with readable paragraph/list formatting and no broken HTML. | Pending |
| AVA-017D | Bot reply renders progressively | Any normal assistant reply | 1. Ask Ava a medium-length question. | The reply appears progressively in the chat instead of arriving only as one final block. | Pending |
| AVA-017E | New Chat clears current session conversation | Existing session conversation exists | 1. Ask Ava one or more questions. 2. Click `New Chat`. | Current browser-session conversation is cleared and Ava returns to the default welcome state. | Pending |
| AVA-017F | Streamed endpoint response is consumed successfully by UI | Browser supports streamed fetch responses | 1. Ask Ava a question. 2. Observe response delivery in the chat. | Reply arrives progressively from endpoint chunks without breaking formatting or final metadata handling. | Pending |
| AVA-017G | Ava receives active work area context from launcher | OpenEMR tab is active in POS, reports, scheduling, patient work, or inventory | 1. Open one of the supported work areas. 2. Open Ava. | Ava welcome copy and starter prompts reflect the active area instead of only generic prompts. | Pending |
| AVA-017H | Context-aware fallback reply matches current area | Open Ava from a supported work area and ask a broad help question | 1. Open Ava from POS or reports. 2. Ask a broad question like `what can you help with here`. | Ava gives a context-aware fallback reply that matches the active workflow area. | Pending |
| AVA-017I | Patient ID is picked up from active tab when available | Active tab URL contains patient identifier | 1. Open a patient-specific screen. 2. Open Ava. | Ava welcome text or starter prompts reflect the current patient context when available. | Pending |
| AVA-017J | Report name is picked up from report screen | Active tab is a report screen with a title | 1. Open a report. 2. Open Ava. | Ava reflects the active report name in context-aware help. | Pending |
| AVA-017K | POS phase hint is picked up from current POS workflow | Active POS tab title or URL indicates payment, backdate, dispense, or administer flow | 1. Open a POS flow. 2. Open Ava. | Ava reflects the POS workflow phase in welcome or fallback guidance when detectable. | Pending |
| AVA-017M | Basic conversational prompt uses OpenAI path when configured | OpenAI configured | 1. Ask `good morning`. | Jacki returns a natural conversational reply instead of a system-search fallback. | Pending |
| AVA-017N | Basic capability question uses conversational assistant path | OpenAI configured | 1. Ask `what do you know`. | Jacki gives a natural capabilities-style answer and does not route to a factual lookup. | Pending |
| AVA-018 | Helpful feedback saves | Reply displayed in chat | 1. Click `Helpful`. | Feedback is saved successfully. | Pending |
| AVA-019 | Not helpful feedback saves | Reply displayed in chat | 1. Click `Not helpful`. | Feedback is saved successfully. | Pending |
| AVA-020 | Negative feedback creates draft suggestion | Reply displayed in chat | 1. Ask a weak/unhelpful question. 2. Click `Not helpful`. 3. Open knowledge review page. | A new draft suggestion appears in admin review. | Pending |
| AVA-021 | Weak generic answer creates draft suggestion automatically | Assistant returns generic/workflow fallback | 1. Ask a question Ava cannot answer well. 2. Open knowledge review page. | Draft suggestion appears with pending status. | Pending |
| AVA-022 | Suggestion occurrence count increases for repeated question | Existing pending suggestion exists | 1. Repeat the same weak/unanswered question. 2. Reopen knowledge review page. | Existing suggestion count increases instead of duplicating unnecessarily. | Pending |
| AVA-023 | Admin can promote suggestion into knowledge base | Super admin logged in | 1. Open knowledge review page. 2. Promote draft suggestion with pattern and answer. | New knowledge entry is created and suggestion marked promoted. | Pending |
| AVA-024 | Promoted knowledge can be approved | Super admin logged in | 1. Approve the promoted knowledge entry. | Knowledge entry status changes to approved. | Pending |
| AVA-025 | Approved promoted knowledge is used by Ava | Approved knowledge exists | 1. Ask the same question again. | Ava returns the approved answer. | Pending |
| AVA-026 | Admin can dismiss suggestion | Super admin logged in | 1. Open pending suggestion. 2. Click dismiss. | Suggestion status changes to dismissed. | Pending |
| AVA-027 | Chat logs are de-identified | Assistant interaction exists | 1. Open knowledge review page. 2. Review recent logs. | Logs do not expose raw phone/email/date-like sensitive strings when de-identification rules apply. | Pending |
| AVA-028 | Admin review page loads successfully | Super admin logged in | 1. Open knowledge review page. | Knowledge entries, draft suggestions, and logs render successfully. | Pending |
| AVA-029 | Today revenue lookup returns grounded live POS data | POS transactions exist for current day | 1. Ask `today revenue`. | Jacki returns total revenue and transaction count from `pos_transactions`. | Pending |
| AVA-030 | Revenue lookup supports abbreviated month prompt | Matching month data exists or intentionally does not exist | 1. Ask `revenue for jan 2026`. | Jacki understands abbreviated month syntax and returns a grounded result for that month. | Pending |
| AVA-031 | Revenue comparison works | POS transactions exist in both compared periods | 1. Ask `compare today vs yesterday revenue`. | Jacki returns both periods and the difference. | Pending |
| AVA-032 | Deposits lookup works | Deposit-related POS transactions exist | 1. Ask `today deposits`. | Jacki returns actual bank deposit, cash, checks, and card/credit totals. | Pending |
| AVA-033 | Gross patient count works | POS activity exists in selected period | 1. Ask `gross patient count today`. | Jacki returns unique, new, and returning patient counts. | Pending |
| AVA-034 | Revenue by medicine works | POS transactions contain item JSON | 1. Ask `revenue by medicine this month`. | Jacki returns top medicine/item revenue lines. | Pending |
| AVA-035 | DCR by facility works when schema supports it | `pos_transactions.facility_id` and `facility` exist | 1. Ask `dcr by facility this month`. | Jacki returns facility-level totals. | Pending |
| AVA-036 | Patient count by location works | Facility-linked patient records exist | 1. Ask `patients by location`. | Jacki returns patient totals grouped by location/facility. | Pending |
| AVA-037 | Today visit list works | Calendar rows exist for current date | 1. Ask `today patient visit list`. | Jacki returns the clinic-wide scheduled visit list for today. | Pending |
| AVA-038 | Tomorrow visit list works | Calendar rows exist for next day | 1. Ask `tomorrow visit list`. | Jacki returns the clinic-wide scheduled visit list for tomorrow. | Pending |
| AVA-039 | Appointments by location works | Calendar rows exist and use `pc_facility` | 1. Ask `appointments by location`. | Jacki returns grouped appointment totals by location/facility. | Pending |
| AVA-040 | Patient appointment lookup includes location when available | Patient appointment rows exist with `pc_facility` | 1. Ask `appointments for John Smith`. | Jacki includes appointment location in the reply when available. | Pending |
| AVA-041 | POS receipt lookup works | Matching receipt exists in `pos_transactions` | 1. Ask `receipt INV-...`. | Jacki returns the matching POS transaction details. | Pending |
| AVA-042 | Recent POS lookup by patient works | Patient has POS transactions | 1. Ask `recent POS for patient 41`. | Jacki returns recent POS entries for that patient. | Pending |
| AVA-043 | Payment troubleshooting response is honest about failure-log limits | OpenAI/POS available | 1. Ask `why did payment fail`. | Jacki gives troubleshooting guidance and does not invent decline reasons. | Pending |
| AVA-044 | Chart-context follow-up works for labs | Assistant opened from patient chart with patient context | 1. Open patient chart. 2. Open Ava. 3. Ask `labs`. | Jacki uses current patient context instead of requiring the patient to be repeated. | Pending |
| AVA-045 | Chart-context follow-up works for recent visits | Assistant opened from patient chart with patient context | 1. Open patient chart. 2. Open Ava. 3. Ask `recent visits`. | Jacki uses current patient context instead of requiring the patient to be repeated. | Pending |
| AVA-046 | Consultation lookup includes encounter location when available | Encounter rows exist with `facility_id` | 1. Ask `recent visits for John Smith`. | Jacki includes encounter location/facility when available. | Pending |
| AVA-047 | Lab lookup includes flags/status when available | Procedure/lab rows exist | 1. Ask `bloodwork for patient 42`. | Jacki includes result flags and/or report status when available. | Pending |
| AVA-048 | Grounded live lookup reply does not create bad generic learning suggestion | Grounded reply returned | 1. Ask a grounded question like `today revenue`. 2. Check suggestion queue. | No inappropriate generic knowledge suggestion is created for the grounded reply. | Pending |
| AVA-049 | Approved knowledge matching prefers strong exact/phrase match | Multiple knowledge entries exist | 1. Add a specific approved pattern and a broad overlapping one. 2. Ask the specific question. | Jacki uses the stronger intended approved match instead of an accidental partial match. | Pending |

## Recommended Execution Order

1. Validate access, launcher behavior, and core conversational UX
2. Validate grounded live operational lookups
3. Validate patient/search/chart-side behaviors
4. Validate patient-safe behavior
5. Validate feedback and draft suggestion flow
6. Validate admin review, promotion, and approval flow
7. Re-test final approved-answer and knowledge-matching behavior

## Notes

- Ava/Jacki is designed to improve through reviewed knowledge and draft suggestions, not uncontrolled self-training.
- All draft suggestions should be reviewed by an admin before being approved for live assistant responses.
- Patient mode should remain limited to safe, non-sensitive help content.
- When OpenAI is enabled, the assistant should preserve OpenEMR facts and only improve readability, continuity, and response quality for the supported conversational path.
- Session memory in the current implementation is browser-session scoped and intended for short conversational continuity rather than long-term storage.
- Ava now supports streamed endpoint delivery with a non-streaming fallback path in the UI.
- Current page-aware context is lightweight and based on active tab URL/title, not deep patient-specific state.
- Deeper patient/report/POS hints are still inferred from active tab metadata and should be validated on real screens.
- Many lookup tests depend on live local data. If the environment currently has no rows for a given table, the expected result should be “no data found” rather than a fabricated answer.

## Summary

The Ava test scope should confirm that:

- Ava is accessible and usable inside the application
- staff workflows and grounded business lookups work as expected
- patient mode remains safe
- weak or unanswered questions are captured for review
- admin review can turn those drafts into approved knowledge
- approved knowledge is then reflected in future assistant responses
- grounded operational replies stay grounded and do not get replaced by weak generic learning behavior
