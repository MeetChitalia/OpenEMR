# Codex Change Note - 2026-04-05

- BackDate Transactions patient search was fixed while preserving the original finder UI.
- BackDate Transactions now opens the existing patient finder in BackDate mode so the visible layout stays unchanged.
- BackDate patient search now works reliably against the full patient list instead of only the initially loaded rows.
- BackDate patient search now supports DOB search in displayed `MM/DD/YYYY` format in addition to stored database DOB values.
- The BackDate save flow fallback/return path now routes back to the BackDate Transactions finder entry point.
- POS medicine search was corrected for facility-filtered sessions so global/unassigned inventory rows are still searchable when no explicit facility mapping exists on the lot.
- This resolved missing inventory search results for medicines such as `Semaglutide` when inventory existed but the lot rows had no `facility_id` or `warehouse_id`.
- POS receipt rendering was enhanced to better reflect finalized transaction details for newer checkout flows.
- Receipt loading now prefers the most relevant transaction row for the invoice/receipt and disables browser caching to reduce stale receipt output.
- Receipt totals now calculate line totals more accurately for quantity-discount scenarios.
- Receipt output now includes prepaid item detail blocks more clearly, including prepaid receipt/reference information, paid date, and notes when available.
- Receipt output also supports displaying price override notes more clearly on the printed receipt when present.
- POS transaction storage logic was aligned with the shared transaction insert path so payment method, patient number, facility-aware fields, and related metadata are handled more consistently.
- Checkout summary calculation logic was adjusted so displayed totals better align with discounted/prepaid item handling in the receipt and payment flow.
- Enhanced DCR patient-row counting now excludes prepaid items from the purchased `#` columns so prior-paid semaglutide/tirzepatide items do not appear as bought again on the dispense date.
- Shot Tracker SG/TRZ card math now uses the day-wise sold `#` totals from Patient DCR, combining semaglutide and tirzepatide sold counts first and dividing by 4 so card totals match the daily DCR sheet.
- Shot Tracker LIPO card math now uses the day-wise sold `#` totals from Patient DCR, dividing by 5 for each card cycle and using `cards * 4` for the paid total because the 5th LIPO shot is free.
- Shot Tracker TES card math now also uses the day-wise sold `#` totals from Patient DCR, dividing by 4 so testosterone card totals follow the same sold-count logic as semaglutide and tirzepatide.
- Patient DCR `Card Punch #` now shows the current punch already used in the active cycle instead of the next punch, so values like SG `3` remain `3` instead of displaying `4`.
- Gross Patient Count new/follow-up classification now follows the visible Patient DCR action/visit type, so rows without `N` in Action are no longer counted as new patients in the gross breakdown tab.

Changed file:
- `interface/main/finder/existing_finder.php`
- `interface/main/finder/existing_ajax.php`
- `interface/main/tabs/menu/menus/standard.json`
- `interface/pos/backdate_pos_screen.php`
- `interface/pos/simple_search.php`
- `interface/pos/pos_receipt.php`
- `interface/pos/pos_payment_processor.php`
- `interface/pos/pos_modal.php`
- `interface/reports/dcr_daily_collection_report_enhanced.php`
