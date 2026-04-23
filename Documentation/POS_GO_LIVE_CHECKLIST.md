# POS Go-Live Checklist

This checklist is for final validation before enabling live POS use across facilities.

## Database

- Confirm `pos_transactions` has `uniq_facility_receipt (facility_id, receipt_number)`.
- Confirm `pos_receipts` has `uniq_facility_receipt (facility_id, receipt_number)`.
- Confirm `pos_transactions.facility_id` and `pos_receipts.facility_id` are `NOT NULL`.
- Confirm `pos_transactions` has `finalized`, `finalized_at`, and `finalized_by`.
- Confirm `pos_remaining_dispense` has `idx_pid_drug_lot_remaining_created`.
- Confirm patient search and DCR indexes created during staging are present.

## POS Checkout

- Create a cash sale and verify receipt opens correctly.
- Create a card sale and verify receipt opens correctly.
- Create a credit-applied sale and verify patient credit decreases once.
- Repeat-submit the same invoice and confirm the second finalize returns success without deducting inventory again.
- Confirm receipt numbers include the facility token such as `F036`.

## Inventory

- New purchase with dispense only reduces inventory exactly once.
- Administer-only item reduces inventory exactly once.
- Remaining-dispense consumption updates the oldest remaining entries first.
- Marketplace dispense still follows Hoover-specific rules.
- Void/refund paths still work against the latest POS receipts.

## Patients And Finder

- Patient search is fast for exact last name, prefix last name, phone, and pubpid.
- Same-day patient number logic still works per facility.
- Patients from the wrong facility are not surfaced where facility scoping is expected.

## Reports

- DCR daily report loads with expected filters and totals.
- Receipt history opens by receipt number.
- Inventory count report email/spreadsheet export still works.

## Email

- Receipt email sends from the configured sender.
- Inventory admin email sends to a real administrator selection.
- SMTP sender/domain configuration is verified with the real production mailbox.

## Operations

- Confirm backup/restore process for the DB before live cutover.
- Confirm who will monitor PHP error log and MySQL slow query log on launch day.
- Confirm there is a rollback point before final cutover.
