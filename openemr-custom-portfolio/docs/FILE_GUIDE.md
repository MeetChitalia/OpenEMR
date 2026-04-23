# File Guide

This guide helps recruiters and reviewers quickly navigate the most relevant files in this portfolio.

## Best Starting Points

- `custom-modules/pos/pos_modal.php`
  Large POS workflow entry point with checkout, pricing, approval, and UI logic.
- `custom-modules/pos/pos_payment_processor.php`
  Backend transaction and payment-processing logic, including inventory-aware behavior.
- `custom-modules/reports/dcr_daily_collection_report_enhanced.php`
  Example of business reporting logic with finance and operations use cases.
- `custom-modules/finder/dynamic_finder.php`
  Patient finder customization with facility and balance-aware workflow support.

## POS Folder

- `backdate_pos_screen.php`
  Handles backdated transaction workflows and related UI actions.
- `pos_modal.php`
  Main POS modal and interaction-heavy workflow layer.
- `pos_patient_transaction_history.php`
  History, review, and transaction visibility tooling.
- `pos_payment_processor.php`
  Payment, billing, and downstream inventory handling logic.
- `pos_receipt.php`
  Receipt generation and output formatting.

## Reports Folder

- `dcr_daily_collection_report_enhanced.php`
  Daily collection reporting with richer breakdowns, export paths, and finance-oriented views.

## Finder Folder

- `dynamic_finder.php`
  Dynamic patient lookup flow with action shortcuts and operational context.
- `existing_finder.php`
  Alternate finder variant showing integration work within existing workflows.
