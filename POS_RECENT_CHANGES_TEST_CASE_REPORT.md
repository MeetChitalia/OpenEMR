# POS Recent Changes Test Case Report

## Document Info

- Feature Area: `POS / Receipt / Dispense Tracking / DCR`
- Prepared On: `2026-03-22`
- Files Covered:
  - `interface/pos/pos_modal.php`
  - `interface/pos/pos_receipt.php`
  - `interface/pos/pos_email_receipt.php`
  - `interface/pos/pos_payment_processor.php`
  - `interface/reports/dcr_daily_collection_report_enhanced.php`

## Scope

This report covers the recent POS-related changes for:

- Prepay workflow in POS
- Prepay notes/details capture
- Receipt display for prepaid items
- Emailed receipt display for prepaid items
- Dispense tracking updates after administer actions
- DCR reporting for dispense/administer activity
- Override mode unit/price read-only behavior

## Preconditions

- User can log in and open POS
- Patient exists in the system
- Inventory and lots exist for the tested products
- Backdate flow is working for test data setup when needed
- DCR report is accessible
- Email receipt configuration is available if email receipt testing is required

## Test Cases

| TC ID | Scenario | Preconditions | Steps | Expected Result | Status |
|---|---|---|---|---|---|
| POS-001 | Prepay toggle is visible in POS | Patient opened in POS | 1. Open POS for a patient. 2. Review visit option row. | `Prepay` toggle appears after `Patient Number`. | Pending |
| POS-002 | Prepay details section appears when prepay is enabled | POS open | 1. Turn on `Prepay`. | `Prepay Details` section appears. | Pending |
| POS-003 | Prepay details section hides when prepay is disabled | POS open | 1. Turn on `Prepay`. 2. Turn it off. | `Prepay Details` section hides again. | Pending |
| POS-004 | Product-level prepay checkbox appears when prepay mode is enabled | Cart contains products | 1. Turn on `Prepay`. 2. Review order summary rows. | Each applicable product row shows a `Prepay` checkbox under product name. | Pending |
| POS-005 | Product price becomes $0.00 when prepaid is selected | Cart contains product | 1. Turn on `Prepay`. 2. Check `Prepay` for one item. | That item’s displayed price becomes `$0.00`. | Pending |
| POS-006 | Original price still displays visually for prepaid item | Cart contains discounted/prepay-capable product | 1. Mark item prepaid. | Original amount is shown as crossed out and final price shows `$0.00`. | Pending |
| POS-007 | Cart total updates when prepaid item is selected | Cart contains billable items | 1. Mark one item as prepaid. | Subtotal/total reflect zero price for prepaid item. | Pending |
| POS-008 | Prepay details are required when prepaid item is selected | Prepay on, at least one item marked prepaid | 1. Leave `Prepay Date` and `Sale / Reference` blank. 2. Try checkout. | Checkout is blocked and user is prompted to complete required prepay details. | Pending |
| POS-009 | Checkout proceeds after valid prepay details are entered | Prepay on, item marked prepaid | 1. Enter `Prepay Date`. 2. Enter `Sale / Reference`. 3. Checkout. | Payment/checkout can proceed successfully. | Pending |
| POS-010 | Printed receipt shows prepaid note under product name | Completed prepaid transaction exists | 1. Complete prepaid sale. 2. Open receipt. | Receipt shows prepaid item note under product name with date, notes/reference, and `$0.00` explanation. | Pending |
| POS-011 | Email receipt shows prepaid note under product name | Completed prepaid transaction exists, email enabled | 1. Send email receipt. | Emailed receipt shows prepaid item note matching printed receipt behavior. | Pending |
| POS-012 | Receipt wording looks polished and readable | Prepaid receipt available | 1. Open receipt for prepaid transaction. | Prepaid note appears as a clear formatted block, not raw/plain debug-style text. | Pending |
| POS-013 | Override mode changes checkout button label | Cart contains items with quantity > 0 | 1. Enter override mode with valid admin verification. | Checkout button changes to `Override Complete`. | Pending |
| POS-014 | Unit/Price field becomes read-only in override mode | Override mode enabled | 1. Enable override mode. 2. Review `Unit/Price` column. | Unit/Price input is visible but read-only/locked. | Pending |
| POS-015 | Unit/Price field looks visually disabled in override mode | Override mode enabled | 1. Enable override mode. | Unit/Price input has muted disabled-style appearance. | Pending |
| POS-016 | Unit/Price field cannot be edited in override mode | Override mode enabled | 1. Click into `Unit/Price` input. 2. Try to type/change value. | Field cannot be edited. | Pending |
| POS-017 | Discount field remains usable in override mode | Override mode enabled | 1. Enable override mode. 2. Change discount field. | Discount field remains usable as designed. | Pending |
| POS-018 | Backdated dispense creates remaining dispense record | Backdate screen available | 1. Backdate a medicine with take-home quantity. | Remaining dispense tracking shows created remaining quantity. | Pending |
| POS-019 | Administering from remaining dispense deducts remaining count correctly | Existing remaining dispense record exists | 1. Create backdated quantity of 2. 2. Go to POS. 3. Administer 1. | Remaining quantity decreases from `2` to `1`. | Pending |
| POS-020 | Administering from remaining dispense increments administered count | Existing remaining dispense record exists | 1. Backdate a medicine. 2. Administer from POS. | `administered_quantity` increases appropriately in dispense tracking. | Pending |
| POS-021 | POS updates correct lot when administering | Multiple lots exist for same product | 1. Backdate/dispense with a specific lot. 2. Administer from same lot in POS. | Matching lot record is updated, not another lot for same drug. | Pending |
| POS-022 | Dispense tracking reflects exact lot-level change | Existing dispense tracking data exists | 1. Perform administer action against tracked lot. | Visible dispense tracking row for that lot is updated correctly. | Pending |
| POS-023 | DCR shows administered quantity correctly | Administer transaction completed | 1. Complete administer transaction. 2. Open DCR for that date. | DCR shows administered quantity instead of treating the item only as take-home quantity. | Pending |
| POS-024 | DCR does not overstate take-home quantity after administer | Backdate + administer scenario exists | 1. Backdate 2 injections. 2. Administer 1 in POS. 3. Open DCR. | DCR does not continue showing full 2 as take-home after administer action. | Pending |
| POS-025 | DCR handles alternate lot dispense transaction type | Alternate lot dispense test data exists | 1. Complete alternate lot dispense flow. 2. Open DCR. | Transaction is counted under dispense logic. | Pending |
| POS-026 | DCR handles combined dispense and administer transaction type | Combined transaction exists | 1. Complete combined dispense/administer flow. 2. Open DCR. | DCR includes both dispense and administer quantities appropriately. | Pending |
| POS-027 | Regular non-prepay receipt remains unchanged | Standard sale exists | 1. Complete normal transaction without prepay. 2. Open receipt. | Receipt shows normal pricing without prepaid note. | Pending |
| POS-028 | Non-prepaid items are not labeled as prepaid | Mixed cart or normal cart exists | 1. Complete transaction where only some items are prepaid. | Only prepaid items show prepaid note; others remain normal. | Pending |

## Recommended Test Data

- Patient with no prior POS transactions
- Patient with backdated medication quantity
- Product with normal sell price
- Product marked prepaid in POS
- Product with remaining dispense quantity
- Same drug across multiple lots for lot-specific verification

## Execution Notes

- For dispense tracking validation, use the same patient and lot across backdate and POS steps.
- For DCR validation, test on the exact transaction date used in the scenario.
- For receipt validation, verify both printed receipt and emailed receipt if email is enabled.

## Summary

This report validates that the recent POS changes behave correctly across:

- checkout behavior
- override behavior
- prepay workflow
- receipt output
- lot-based dispense tracking
- DCR reporting

The highest priority regression checks are:

1. Prepay item price becomes `$0.00`
2. Receipt shows prepaid date and notes
3. Administer action reduces remaining dispense correctly
4. DCR reflects administered quantity correctly
5. Override mode keeps `Unit/Price` read-only
