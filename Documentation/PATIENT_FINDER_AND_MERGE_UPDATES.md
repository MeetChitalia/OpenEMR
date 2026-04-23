# Patient Finder And Merge Updates

## Summary

This note documents the recent UI and workflow updates made to the patient finder, duplicate patient management, and patient merge flow.

## Files Updated

- `interface/main/finder/dynamic_finder.php`
- `interface/main/finder/existing_finder.php`
- `interface/patient_file/manage_dup_patients.php`
- `interface/patient_file/merge_patients.php`
- `interface/patient_file/deleter.php`

## Files Changed Today

- `Documentation/PATIENT_FINDER_AND_MERGE_UPDATES.md`
- `interface/main/finder/dynamic_finder.php`
- `interface/main/finder/existing_finder.php`
- `interface/main/tabs/main.php`
- `interface/patient_file/manage_dup_patients.php`
- `interface/patient_file/merge_patients.php`
- `interface/patient_file/deleter.php`
- `interface/pos/pos_modal.php`
- `interface/pos/backdate_pos_screen.php`
- `interface/pos/backdate_save.php`
- `interface/pos/backdate_void.php`
- `interface/pos/pos_payment_processor.php`
- `interface/pos/pos_patient_transaction_history.php`
- `interface/pos/pos_transaction_history.php`
- `interface/pos/pos_receipt.php`
- `interface/reports/dcr_daily_collection_report.php`
- `interface/reports/dcr_daily_collection_report_enhanced.php`

## Patient Finder UI Updates

### Unified Search Behavior

The patient finder search box was updated so search results stay inside the standard patient table instead of switching to a separate card-style results panel.

What changed:

- typing in the unified search box now filters the existing patient table
- matched patients remain visible in the same table layout with the normal columns
- the edit icon/button remains available directly from the filtered row
- the POS action remains available directly from the filtered row
- the `Clear` button resets the search and restores the full patient list

Why this change was made:

- keeps search behavior consistent with the normal finder view
- avoids a disconnected alternate result layout
- makes it easier for staff to search and act on a patient without changing context

## POS Prepay Date Restriction

The POS prepay workflow was updated so future dates cannot be selected for prepaid transactions.

What changed:

- the `Prepay Date` input in the POS modal now uses today as the maximum allowed date
- the calendar picker no longer allows users to choose a future date
- an additional JavaScript validation check now blocks manually entered future dates
- users now see a validation message if they attempt to save a prepay with a future date

Why this change was made:

- prepay represents an advance payment already received
- future payment dates should not be recorded in that workflow
- UI restriction plus validation provides stronger protection than calendar-only behavior

## **jacki** Widget Position

The global **`jacki`** chat widget was repositioned so it no longer sits on the right side and overlaps important interface content.

What changed:

- the floating **`jacki`** launcher was moved from the bottom-right to the bottom-left
- the expanded chat panel now opens from the left side as well
- mobile positioning was updated so the widget remains left-aligned on smaller screens

Why this change was made:

- reduces UI obstruction on the right side of the application
- improves visibility of underlying page actions and content
- keeps the assistant accessible without interfering with core workflows

## **jacki** Widget Visibility

The global **`jacki`** launcher was visually enhanced so staff can notice it more easily.

What changed:

- stronger blue-tinted background styling was added to the launcher
- the **`jacki`** label was made darker and bolder
- the chat icon was given stronger emphasis
- the launcher now uses a clearer highlight ring and stronger shadow

Why this change was made:

- the previous widget styling was too subtle and easy to overlook
- staff need to be able to quickly identify the assistant entry point
- stronger visual contrast improves discoverability without changing workflow behavior

## DCR `TH` Fix

The DCR `TH` (`Take Home`) columns were corrected so they reflect actual take-home quantities recorded in POS.

What changed:

- `TH` was confirmed to mean `Take Home` in the DCR patient grid
- DCR no longer nets take-home counts against administered counts during patient finalization
- finalized POS `external_payment` transactions are now treated as valid dispense/administer-bearing transactions for DCR rollup

Why this change was made:

- receipts were correctly showing dispense quantities, but DCR was leaving `TH` blank
- the report was previously reading the saved POS transaction but not counting the final payment transaction as a dispense source
- `TH` should show the real take-home quantity sold/dispensed during the reporting period

Example:

- if POS saves `Semaglutide` with `Dispense: 3` and `Administer: 0`, DCR should show `Sema TH = 3`

## POS Payment Fix

The POS payment processor was fixed after checkout payments started failing with a server `500` during transaction recording.

What changed:

- missing database helper wrappers were added in `interface/pos/pos_payment_processor.php`
- the processor now defines `sqlFetchArray()` and `sqlQuery()` locally for the lightweight payment flow

Why this change was made:

- payment requests were reaching `recordExternalPayment()` and failing while storing the transaction
- the processor was calling OpenEMR-style DB helpers that were not defined in that stripped-down file
- this caused payment recording to fail before the JSON success response could be returned

Outcome:

- POS payments now complete successfully again
- the resulting saved transaction can be used by DCR for `TH` reporting validation

## Prepaid Receipt Display Fix

The printed POS receipt was updated so prepaid items are clearly shown instead of appearing like ordinary paid items.

What changed:

- prepaid item flags are now preserved when POS transaction items are saved
- prepaid metadata such as `prepay_date` and `prepay_sale_reference` is kept with the saved receipt item data
- the receipt lookup now prefers the final `external_payment` row for a receipt number when multiple POS rows exist for the same receipt
- the receipt continues to render the prepaid item note directly under the affected line item

What now appears on the receipt for a prepaid item:

- `PREPAID ITEM`
- `Receipt price: $0.00`
- `Paid on: <date>`
- `Notes: <reference>`

Why this change was made:

- the receipt template already supported prepaid display, but the saved item payload was dropping the prepaid fields
- in some cases the receipt could also load the wrong transaction row when multiple rows shared the same receipt number
- staff need to see clearly that the item was prepaid earlier and is not being charged again on the current visit

Files involved in this fix:

- `interface/pos/pos_payment_processor.php`
- `interface/pos/pos_receipt.php`

## Backdate POS Fix And Validation

The backdate workflow was reviewed and corrected so saved backdated entries now update inventory as intended.

What was confirmed about backdate behavior:

- the backdate screen saves a dated POS transaction entry for the selected patient
- the saved backdated transaction is written into `pos_transactions`
- the related dispense/administer totals are written into `pos_remaining_dispense`
- the selected dose such as `0.25 mg`, `1.00 mg`, or `1.70 mg` is saved in the transaction item data as `dose_option_mg`
- DCR can read that saved dose from POS transaction items and include it in the dose columns

What was fixed:

- the inventory deduction block in `backdate_save.php` was calculating the new QOH but not actually updating `drug_inventory`
- the `UPDATE drug_inventory ...` statement was restored so backdated entries now reduce stock correctly

Why this change was made:

- backdate could appear to save successfully and update tracking totals while leaving inventory unchanged
- this caused partial success: transaction history and remaining-dispense tracking worked, but stock did not reflect the backdated usage

What was validated after the fix:

- a backdated semaglutide entry with `Quantity = 4`, `Dispense = 3`, and `Administer = 1` saved successfully
- the tracking summary updated from:
  - `Bought = 50`, `Dispensed = 17`, `Administered = 11`, `Remaining = 12`
- to:
  - `Bought = 54`, `Dispensed = 20`, `Administered = 12`, `Remaining = 12`
- this matches the expected backdate math:
  - bought `+4`
  - dispensed `+3`
  - administered `+1`
  - remaining change `= 4 - 3 - 1 = 0`

Where backdated data is saved:

- transaction history: `pos_transactions`
- dispense tracking: `pos_remaining_dispense`
- inventory stock/QOH: `drug_inventory`

Important note:

- the backdate tracking summary is quantity-based, not dose-based
- dose is still recorded in the saved POS item JSON and can be used later by DCR

Files involved in this fix:

- `interface/pos/backdate_pos_screen.php`
- `interface/pos/backdate_save.php`
- `interface/reports/dcr_daily_collection_report_enhanced.php`

## Backdated Entry Undo

The backdate workflow now has a dedicated reversal path instead of relying on the normal same-day POS void logic.

What changed:

- a separate undo endpoint was added for backdated receipts
- backdated receipts can still be undone immediately from the success modal after save
- backdated receipts can now also be undone later from transaction history
- undo reverses the saved backdated transaction, restores inventory, and reverses remaining-dispense totals together
- the original backdated transaction is marked voided and an audit row is recorded

Why this change was made:

- the normal POS void flow is same-day only
- true backdated receipts often need to be corrected later
- deleting only one row manually would leave inventory and dispense tracking inconsistent

Important safeguard:

- undo can be blocked when a later transaction already uses the same product after the backdated entry
- this avoids corrupting later inventory and patient tracking

Files involved in this fix:

- `interface/pos/backdate_void.php`
- `interface/pos/backdate_pos_screen.php`
- `interface/pos/pos_modal.php`
- `interface/pos/pos_patient_transaction_history.php`
- `interface/pos/pos_transaction_history.php`

## Daily Patient Number Behavior

The POS `Patient Number` field now behaves like a same-day token number instead of an unrestricted free-form value.

What changed:

- the number entered for a patient stays with that same patient for the rest of the day
- reopening POS for that same patient on the same day reuses the saved number
- the reused number is locked for that patient for the rest of that day
- the same number cannot be used by a different patient on the same day
- the rule resets naturally the next day because validation only checks today’s POS transactions

Why this change was made:

- the team uses patient number as a daily token/workflow number
- it should not be a permanent chart number
- it must stay consistent for one patient during the day
- it must not be reused by another patient on the same day

Validation behavior:

- duplicate number checking now happens on the same POS screen
- when the user leaves the `Patient Number` field, POS validates it immediately
- if the number is already used for another patient today, staff see a popup right away
- the field is highlighted red so they know exactly what to fix
- backend validation still exists so the rule cannot be bypassed

Files involved in this fix:

- `interface/pos/pos_modal.php`
- `interface/pos/pos_payment_processor.php`

### Length Selector

The DataTables page-length selector was enlarged and restyled so values like `10` are fully visible and easier to use.

What changed:

- increased selector width
- increased control height
- improved padding and border radius
- aligned the selector text better with the surrounding `Show ... entries` label

### Footer Controls

The footer area under the patient table was cleaned up.

What changed:

- `Open in New Window` and `Exact Search` now render in a cleaner control row
- checkbox spacing and hit area were improved
- the `Showing X to Y of Z entries` text was restyled
- pagination buttons were made cleaner and more consistent with the rest of the UI

## Duplicate Patient Management UI

The duplicate patient management page was already updated to use a more polished card-based layout consistent with the rest of the site.

Highlights:

- cleaner card container
- improved toolbar buttons
- improved table presentation
- better spacing and visual grouping

## Merge Patients UI

The merge patient screen was redesigned to feel more product-finished while still using the site’s existing UI language.

What changed:

- added a cleaner page header and description
- reorganized the form into card sections
- clarified `Target Patient` vs `Source Patient`
- styled warnings and helper text using the site’s Bootstrap/OpenEMR patterns

## Merge Patients Behavior

### Standard Merge

The `Merge` action:

- moves source patient-linked data into the target patient
- keeps the target chart as the main demographic/history/insurance record
- removes the source patient chart after merge cleanup

### Merge With Encounter Deduplication

The `Merge with Encounter Deduplication` action:

- performs the standard merge
- also attempts to merge duplicate encounters when both charts represent the same visit/date

Use this when duplicate patient creation also caused duplicate visit records.

## Merge Coverage Added

The merge flow was extended to better handle POS and shot-history related data.

Special handling was added for:

- `patient_credit_balance`
- `daily_administer_tracking`
- `patient_credit_transfers`
- `pos_transfer_history`

This is in addition to the existing generic merge logic that already updates many tables using `pid` or `patient_id`.

## Merge Coverage Expanded Further

The merge sweep was widened again so newer tables using patient reference columns ending in `_pid` are also updated during merge.

What changed:

- the generic merge logic no longer looks only for plain `pid` and `patient_id`
- it now also updates patient-link columns such as `dld_pid`, `ee_pid`, `ep_pid`, `imo_pid`, `msg_pid`, and similar `_pid` references
- explicit handling was also added for the `credit_transfers` table because it uses `from_pid` and `to_pid`

Why this change was made:

- some newer tables were not being touched during merge because they do not use only the older `pid` / `patient_id` naming pattern
- that could leave parts of the source chart behind even though the main merge looked successful

Outcome:

- merge coverage is now broader for newer POS, legal, external, immunization, and other patient-linked tables
- this reduces the chance of partial merges where source-linked records remain orphaned

Files involved in this fix:

- `interface/patient_file/merge_patients.php`

## Source Patient Cleanup

After a merge completes, an explicit cleanup step now runs to remove leftover source patient shell records if they still exist.

Final cleanup targets:

- `patient_data`
- `history_data`
- `insurance_data`
- empty source document directory when applicable

## Patient Deletion Configuration

Manual patient deletion is controlled by the global setting:

- `$GLOBALS['allow_pat_delete']`

Relevant enforcement points:

- `interface/patient_file/manage_dup_patients.php`
- `interface/patient_file/deleter.php`

Important note:

- standard patient deletion can be disabled by environment configuration
- merge cleanup currently uses its own merge/cleanup flow rather than the normal delete endpoint

## Staging Note

If staging shows `Patient deletion is disabled.`, that confirms the normal delete workflow is blocked by configuration. It does not automatically mean the merge script itself is blocked unless the merge flow is explicitly changed to honor that same setting.

## Suggested Validation

After deployment, validate the following with a controlled duplicate patient pair:

1. Merge basic patient data into target chart.
2. Confirm source chart no longer appears as a separate patient.
3. Confirm POS transactions moved correctly.
4. Confirm remaining dispense and administered shot history moved correctly.
5. Confirm patient credit balances and transfer history remain correct.
6. If using encounter deduplication, confirm duplicate visit data is consolidated as expected.
