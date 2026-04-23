# Codex Change Note - 2026-04-03

- Backdated POS flow wording updated from `Dispense` to `Dispensed` and `Administer` to `Administered`.
- Scope limited to UI text in the backdated flow only. No logic changes made.
- Hoover facility inventory add flow now creates a Hoover-linked inventory mapping so new items appear under Hoover immediately.
- Inventory facility filtering/display updated so Hoover-linked items stay scoped correctly and still show in the inventory list.
- POS search updated to use the correct search endpoint and logged-in facility context so facility inventory results show more reliably.
- POS search fallback now includes legacy unmapped inventory rows while preserving facility-linked inventory behavior.
- DCR facility filtering updated to use the enforced request facility consistently so Hoover-selected reports do not fall back to `All Facilities`.
- Verified Hoover DCR output against saved POS receipts so Deposit, Revenue Breakdown, Gross Patient Count, and Shot Tracker align with current Hoover transactions.
- Fixed Revenue Breakdown email/export template column mapping so emailed Excel reports no longer shift `Taxes` and `Total` into the wrong columns.
- Added the missing `Patient DCR` sheet to emailed/exported DCR workbooks so the attachment now includes all report tabs.
- Adjusted emailed `Patient DCR` workbook output to follow the actual Excel template layout instead of the generic fallback grid.
- Corrected emailed DCR category bucketing for zero-dollar prepay mixes and cleaned Gross Patient Count template rows to prevent stale formula values.
- Aligned Gross Patient Count new/follow-up classification with rebuilt patient summary logic so the last DCR workbook sheet uses the current report dataset.
- Fixed DCR Deposit aggregation so emailed/on-screen Deposit totals include non-cash electronic payment methods consistently with the other DCR tabs.
- Tightened Gross Patient Count bucketing so mixed visits count in one primary DCR category instead of appearing across multiple categories with zero revenue.
- POS search now enforces true facility-linked inventory only when a facility is selected, so Hoover no longer shows unmapped legacy lots.
- Replaced the DCR `Print Report` button with `Download Report` so it downloads the same `.xlsx` workbook used for the email attachment.

Changed file:
- `interface/pos/backdate_pos_screen.php`
- `interface/drugs/add_edit_drug.php`
- `interface/drugs/add_edit_lot.php`
- `interface/drugs/drug_inventory.php`
- `interface/reports/inventory_list.php`
- `interface/pos/pos_modal.php`
- `interface/pos/simple_search.php`
- `interface/reports/dcr_daily_collection_report_enhanced.php`
