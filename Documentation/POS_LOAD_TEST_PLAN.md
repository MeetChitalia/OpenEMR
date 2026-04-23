# POS Load Test Plan

This plan targets the highest-value pre-live flows for a deployment expected to hold 50k+ patients and 50k+ POS transactions.

## Goals

- Validate patient search responsiveness under larger patient volume.
- Validate POS checkout/finalize behavior under concurrent use.
- Validate receipt rendering for recent transactions.
- Validate DCR and payment summary report responsiveness for real date ranges and facilities.

## Test Data Assumptions

- Patient table sized to at least 50k records in staging.
- POS transaction table sized to at least 50k records in staging.
- Multiple facilities present, including Hoover / facility `36`.
- A representative spread of:
  - recent POS sales
  - dispense-only transactions
  - administer transactions
  - refunds / voids
  - credit-applied purchases

## Priority Endpoints And Pages

### 1. Patient Search

#### POS patient search modal

- Page:
  - `/interface/pos/pos_modal.php`
- AJAX search endpoint:
  - `/interface/pos/pos_modal.php?action=search_patients&search=<term>`
- AJAX patient detail endpoint:
  - `/interface/pos/pos_modal.php?action=get_patient_data&pid=<pid>`

#### POS dedicated patient search

- Endpoint:
  - `/interface/pos/pos_patient_search.php`

#### Main finder

- AJAX endpoints:
  - `/interface/main/finder/dynamic_finder_ajax.php`
  - `/interface/main/finder/existing_ajax.php`

### 2. POS inventory search

- Endpoint:
  - `/interface/pos/simple_search.php?search=<term>&limit=20`

### 3. POS checkout and finalize

- Backend endpoint:
  - `/interface/pos/pos_payment_processor.php`

#### Actions to hit

- `record_external_payment`
- `finalize_invoice`
- `get_patient_credit`
- `get_refundable_items`

### 4. Receipt rendering

- Page:
  - `/interface/pos/pos_receipt.php?receipt_number=<receipt>&pid=<pid>`

### 5. Reporting

- DCR:
  - `/interface/reports/dcr_daily_collection_report_enhanced.php`
- Payment receipt summary:
  - `/interface/reports/receipts_by_method_report.php`

## Search Test Matrix

Run each of these search types with realistic parallel users.

### POS modal / patient finder searches

- Exact last name search
- Prefix last name search
- Phone number search
- Public patient ID search
- Empty/short search rejection behavior

### Pass criteria

- Median response should feel instant for users.
- No timeouts or PHP fatals.
- No obvious cross-facility leakage where facility scoping is expected.

## POS Checkout Test Matrix

### Scenario A: standard cash/card checkout

1. Load patient in POS.
2. Search and add 1 to 3 items.
3. Call `record_external_payment`.
4. Call `finalize_invoice`.
5. Open generated receipt.

Expected:

- transaction stored once
- invoice finalizes once
- inventory deducts once
- receipt opens without error

### Scenario B: credit-applied checkout

1. Seed patient credit balance.
2. Complete checkout with partial or full credit.
3. Retry finalize immediately.

Expected:

- credit applied once
- finalize short-circuits on duplicate submit
- receipt still shows correct total and credit amount

### Scenario C: remaining-dispense usage

1. Use a patient with active remaining dispense.
2. Finalize a dispense/administer action against remaining quantity.

Expected:

- oldest remaining rows consumed first
- remaining quantity never goes negative
- no duplicate tracking rows from double finalize

### Scenario D: same lot, multiple users

1. Use one lot with known quantity.
2. Two users check out from that same lot.

Expected:

- inventory reflects successful deductions only
- no negative inventory
- any oversell fails cleanly

## Receipt Test Matrix

Use recent receipts from multiple facilities.

- Open 25 to 50 receipts in succession.
- Verify correct header branding and location line.
- Verify recent receipts with credit, dispense, administer, and consultation combinations.

Expected:

- no receipt rendering errors
- no wrong-facility header display
- no slow lookup from receipt number / pid pairing

## DCR Report Test Matrix

Run these report combinations:

- single day, one facility
- 7-day range, one facility
- monthly range, one facility
- all facilities
- medicine filter applied
- payment method filter applied

Expected:

- page renders without timeout
- totals stay internally consistent
- CSV/email/export actions complete without fatal errors

## Payment Summary Report Matrix

Run these combinations:

- one day, one facility
- 30-day range, one facility
- all facilities

Expected:

- report loads successfully
- totals match sampled receipts
- no major lag when sorting or rendering rows

## Suggested Practical Load Levels

Start small and step up:

- 5 concurrent users:
  - baseline sanity
- 10 concurrent users:
  - likely real front-desk burst
- 20 concurrent users:
  - stress level for staging confidence

For checkout/finalize:

- at least 25 repeated checkout cycles per test round
- include 5 duplicate-finalize attempts
- include 5 same-lot contention attempts

For search:

- at least 200 total patient search requests per round
- mixed exact and prefix terms

## What To Measure

- median and p95 response time
- PHP error log entries
- MySQL slow query log entries
- checkout success/failure counts
- duplicate finalize short-circuit counts
- inventory mismatches after test round

## Minimal Tooling Recommendation

Use a staged browser-driven walkthrough plus a simple HTTP runner for the AJAX endpoints.

Good candidates:

- browser + DevTools network timings for manual validation
- ApacheBench / `ab` for simple GET endpoints
- a scripted curl loop for authenticated AJAX endpoints if session cookies are available

## Green / Yellow / Red Launch Readiness

### Green

- patient search remains consistently fast at 10 to 20 concurrent users
- checkout/finalize succeeds without duplicate mutations
- receipts open reliably
- DCR and payment summary reports complete without timeout for real daily/monthly use
- PHP error log stays quiet except for expected debug lines

### Yellow

- normal flows work, but p95 response time is noticeably slow
- some reports are heavy for larger ranges
- no data corruption, but user experience may degrade under bursts
- launch possible with close monitoring and limited initial rollout

### Red

- duplicate inventory or credit mutations still occur
- repeated fatals/timeouts in reports or checkout
- patient search becomes unreliable under moderate concurrency
- slow query log shows major table scans on hot paths
- do not launch until fixed

## Current Summary

Based on the code and staging work completed so far:

- Patient search / indexing: `Green leaning Yellow`
- POS receipt uniqueness and finalize safety: `Green`
- Inventory concurrency safety: `Yellow leaning Green`
- DCR / reporting at larger ranges: `Yellow`
- Email delivery confidence: `Yellow` because real production SMTP still needs final human verification

Overall current status:

- **Yellow leaning Green**

That means the biggest correctness risks have been reduced, but final launch confidence still depends on running this plan in staging and checking logs and response times.
