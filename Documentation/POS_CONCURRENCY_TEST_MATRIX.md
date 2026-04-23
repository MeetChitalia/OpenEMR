# POS Concurrency Test Matrix

Use this matrix in staging before live rollout.

## Test 1: Double Finalize Same Invoice

- User A starts checkout for one patient and leaves the invoice ready.
- User A and User B submit `finalize_invoice` for the same invoice at nearly the same time.
- Expected:
  - only one request performs inventory mutation
  - only one request applies credit
  - second request returns success with `already_finalized = true`
  - final receipt data is identical

## Test 2: Same Lot, Two Different Patients

- Prepare one lot with a known small quantity.
- User A checks out patient 1 from that lot.
- User B checks out patient 2 from the same lot at the same time.
- Expected:
  - total deducted quantity matches both successful sales
  - no negative lot quantity
  - any insufficient inventory case fails cleanly

## Test 3: Credit Plus Finalize

- Give a patient a known credit balance.
- Submit checkout using credit and intentionally retry finalize quickly.
- Expected:
  - patient credit transaction is inserted once
  - balance decreases once
  - second finalize short-circuits

## Test 4: Finalize Then Void

- Complete a POS sale.
- Void the sale from transaction history.
- Expected:
  - original transaction marked voided
  - reversal transaction created
  - inventory and remaining-dispense state restored correctly

## Test 5: Facility Isolation

- Create a sale in facility A and another in facility B.
- Confirm both can produce similarly timed receipts without collision.
- Expected:
  - receipt numbers remain unique because of facility token and DB uniqueness
  - no cross-facility transaction history bleed

## Evidence To Capture

- Screenshot of receipt number
- Screenshot of transaction history
- Inventory lot quantity before and after
- Credit balance before and after
- PHP error log lines around the test window
