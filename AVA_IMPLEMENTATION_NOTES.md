# Ava Implementation Notes

This document tracks the current implementation of the internal OpenEMR assistant that is now presented in-product as `jacki`. Older code and documentation may still refer to `Ava`, but the current staff-facing assistant name is `jacki`.

## Updated On

`2026-03-25`

## Summary

The assistant was upgraded from a basic internal helper into a more production-ready internal copilot surface inside OpenEMR.

The recent work focused on these areas:

- cleaner and more modern chat UI
- OpenAI-backed basic conversation
- safer learned-answer retrieval
- more live chat behavior with progressive replies and session reset
- true streamed delivery from assistant endpoints to the chat UI
- page-aware context based on the current OpenEMR work area
- deeper context hints such as current patient, report name, and POS phase
- explicit task-oriented workflow answers for step-by-step help
- explicit Point of Sale page guidance for step-by-step checkout help
- grounded live business lookups for revenue, DCR, inventory, POS, visits, and patient/location summaries
- stronger action routing so Jacki buttons open inside the current browser/app view instead of another tab

## What Changed

### UI and chat experience

- Simplified and cleaned the Ava visual design to reduce heavy framing and visual clutter.
- Removed duplicate title treatment inside the modal.
- Improved message presentation so assistant replies render more cleanly for titles, paragraphs, and bullet lists.
- Added session-scoped conversation restore in the browser so reopening Ava in the same session keeps recent chat context.
- Improved the composer experience with auto-resizing input and cleaner empty-state copy.
- Added progressive bot reply rendering so answers appear more like a live assistant instead of a single instant block.
- Added a `New Chat` control so staff can clear the current session context and start fresh.
- Updated the browser chat flow to consume streamed reply chunks from the assistant endpoints when available.
- Updated the launcher and Ava UI so the current work area can be passed into the assistant when opened from the main shell.
- Updated starter prompts and welcome text so Ava feels more relevant when opened from POS, scheduling, reports, patient work, or inventory.
- Expanded the context layer so Ava can pick up patient ID, active report name, and basic POS workflow phase when the active tab exposes those details.
- Added more explicit workflow guidance so Ava can answer some “how do I do this” questions with direct step-by-step instructions instead of generic lookup replies.
- Added explicit POS page guidance so Ava can explain how to use the checkout screen, including weight recording, order summary review, credit balance use, and payment flow.
- Updated the branding and response tone so the assistant behaves as `jacki` in the UI and replies.
- Updated action-button navigation so assistant links open in the current browser/app view instead of launching a separate tab.

### Backend behavior

- Added server-side OpenAI support using environment variables.
- Updated env loading so assistant config can be read from `getenv()`, `$_ENV`, or `$_SERVER`, matching how this install loads `.env`.
- Preserved OpenEMR workflow and retrieval answers first, while allowing OpenAI to handle basic conversational staff prompts.
- Added recent conversation history support so OpenAI-enhanced replies can use short-term context from earlier turns.
- Kept patient mode constrained to safe, non-sensitive support behavior.
- Added streamed NDJSON response support in the assistant endpoints so replies can be delivered progressively to the browser.
- Added context resolution helpers so assistant prompts and OpenAI instructions can reflect the current OpenEMR area.
- Added context-aware fallback behavior for staff mode so generic help feels more relevant to the active workflow.
- Added deeper context extraction helpers so prompts and replies can incorporate patient, report, and POS-state hints when available.
- Added explicit patient import workflow handling so `import patients` is routed to step-by-step instructions before broad patient-search matching.
- Added explicit POS page workflow handling so broad questions like `how do I use this page` on POS return step-by-step checkout guidance instead of generic POS help.
- Added safe helper-based environment lookup and Jacki-specific config keys:
  - `JACKI_OPENAI_API_KEY`
  - `JACKI_OPENAI_MODEL`
  - `JACKI_OPENAI_TIMEOUT_SECONDS`
- Kept legacy `OPENAI_*` variable fallback support for compatibility.

### Live lookup hardening

- Revenue lookups now support:
  - `today revenue`
  - `revenue for yesterday`
  - `revenue for jan 2026`
  - month abbreviations such as `jan`, `feb`, `mar`, `sept`
  - daily/month ranges and comparisons
- Revenue SQL now uses explicit datetime boundaries instead of `DATE(created_date)` matching.
- Revenue summary handling was fixed to support `sqlQuery()` results returned as ADODB objects via `->fields`, which resolved a live “not found” bug even when transactions existed.
- DCR-style operational lookups were added for:
  - deposits
  - gross patient count
  - shot tracker
  - revenue by medicine
  - DCR by facility
- Inventory lookups were hardened to support:
  - drug-name search
  - lot-number search
  - expired medicines
  - medicines expiring soon
  - low stock
  - out of stock
- Inventory replies now include more useful lot context such as total on hand, location/facility when available, and warehouse.
- POS lookups were expanded with a dedicated direct transaction path for:
  - receipt lookup
  - recent POS by patient
  - recent transactions by patient
  - payment troubleshooting guidance
- POS revenue/payment filters were tightened so card-family methods such as `credit_card`, `affirm`, `zip`, `sezzle`, `afterpay`, and `fsa_hsa` are treated more realistically in reporting logic.
- Scheduling lookups were expanded with:
  - patient appointment lookup with location/facility details
  - clinic-wide visit lists for today, tomorrow, and yesterday
  - appointments by location/facility
  - today/tomorrow schedule summaries by location
- Patient/location lookups were expanded with:
  - patient count by location/facility
  - today patient visit list
  - broader operational prompt coverage

### Knowledge and learning behavior

- Approved-knowledge retrieval was hardened so learned answers are matched more intentionally.
- The matching flow now prefers:
  - exact normalized matches
  - phrase-boundary matches
  - token-coverage scoring
  - limited fuzzy matching only for stronger long-pattern similarity
- This reduces the risk of short or partial patterns hijacking unrelated questions.
- Grounded live lookup replies are no longer treated like generic learnable responses just because they contain common phrases.
- The assistant still supports knowledge suggestions and approval workflow, but the live operational paths remain grounded in OpenEMR data.

## Files Changed

### Latest upgrade: page-aware context

- `interface/main/tabs/main.php`
- `interface/main/assistant/chat_ui.php`
- `interface/main/assistant/assistant_common.php`
- `interface/main/assistant/staff_chat_api.php`
- `interface/main/assistant/patient_chat_api.php`
- `AVA_IMPLEMENTATION_NOTES.md`
- `AVA_TEST_CASE_REPORT.md`

### Latest upgrade: deeper context hints

- `interface/main/tabs/main.php`
- `interface/main/assistant/chat_ui.php`
- `interface/main/assistant/assistant_common.php`
- `interface/main/assistant/staff_chat_api.php`
- `interface/main/assistant/patient_chat_api.php`
- `AVA_IMPLEMENTATION_NOTES.md`
- `AVA_TEST_CASE_REPORT.md`

### Latest upgrade: workflow-specific training

- `interface/main/assistant/staff_chat_api.php`
- `AVA_IMPLEMENTATION_NOTES.md`

### Latest upgrade: POS page training

- `interface/main/assistant/staff_chat_api.php`
- `AVA_IMPLEMENTATION_NOTES.md`

### Latest upgrade: Jacki configuration and production hardening

- `interface/main/assistant/assistant_common.php`
- `interface/main/assistant/staff_chat_api.php`
- `interface/main/assistant/chat_ui.php`
- `interface/main/assistant/OPENAI_SETUP.md`
- `AVA_IMPLEMENTATION_NOTES.md`

### Ava assistant files

- `interface/main/assistant/chat_ui.php`
- `interface/main/assistant/assistant_common.php`
- `interface/main/assistant/staff_chat_api.php`
- `interface/main/assistant/patient_chat_api.php`
- `interface/main/assistant/OPENAI_SETUP.md`
- `interface/main/tabs/main.php`

### Related documentation

- `AVA_TEST_CASE_REPORT.md`
- `AVA_IMPLEMENTATION_NOTES.md`

## OpenAI Configuration

Jacki reads these server-side environment variables:

```bash
JACKI_OPENAI_API_KEY=your_api_key_here
JACKI_OPENAI_MODEL=gpt-4o
JACKI_OPENAI_TIMEOUT_SECONDS=20
```

Legacy fallback is still supported:

```bash
OPENAI_API_KEY=your_api_key_here
OPENAI_MODEL=gpt-5-mini
OPENAI_TIMEOUT_SECONDS=20
```

## Current Behavior Notes

- Staff mode prioritizes OpenEMR facts, workflow rules, and approved knowledge.
- Patient mode remains general, safe, and non-diagnostic.
- Conversation memory is currently short-term and browser-session based.
- The UI now supports a manual session reset through the `New Chat` button.
- Replies can now be streamed from the assistant endpoints to the browser for a more live chat experience.
- The UI still keeps a fallback path for non-streaming responses.
- Jacki can pick up lightweight context from the active OpenEMR tab, including areas like POS, scheduling, reports, patient work, and inventory.
- Jacki can pick up deeper hints from the active tab when available, including patient ID, report name, and basic POS state.
- OpenAI is used for basic conversational staff prompts such as greetings, acknowledgements, and capability-style questions.
- Live financial, scheduling, inventory, patient, and POS answers remain grounded in OpenEMR data paths.
- Action buttons now stay inside the current browser/app view.
- Reply quality is best when OpenAI is configured server-side.

## Recommended Next Improvements

- richer markdown/table rendering
- direct live context from active patient/report/POS data models instead of URL/title-only inference
- optional persistent conversation history for staff
- action-oriented assistant cards tied to OpenEMR workflows
- consultation/chart-side hardening and follow-up handling
- more clinic-wide scheduling analytics such as reschedules, no-shows, and status summaries
- more inventory and reporting rollups by location/facility
