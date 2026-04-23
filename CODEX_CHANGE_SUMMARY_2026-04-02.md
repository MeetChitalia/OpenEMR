# Codex Change Summary

Date: 2026-04-02

This document summarizes the follow-up changes made after the duplicate-patient SQL fix in `library/patient.inc`.

## Scope

These changes focused on:

- patient creation from the finder modal
- duplicate-patient review messaging
- facility name display for legacy patient records
- valid DOB handling during patient creation
- staging-safe handling for missing Select2 assets on the Message Center page

## Changed Files

- `/Applications/XAMPP/xamppfiles/htdocs/openemr/library/patient.inc`
  - Added a shared `getPatientFacilityDisplayName()` helper so patient facility labels display more cleanly.
  - Improved fallback display for legacy patient rows that point to missing facility records.
  - Duplicate-patient matching was narrowed so warnings are driven by strong identifiers instead of weak name-only matches.
  - Duplicate-patient queries now return cleaner facility context for display in the add-patient workflow.

- `/Applications/XAMPP/xamppfiles/htdocs/openemr/interface/new/new_patient_save.php`
  - Improved duplicate warning text to show clearer facility information.
  - Hardened AJAX create handling so successful inserts return clean JSON responses.
  - Improved patient-create error formatting so validation failures show readable messages instead of raw validator keys.
  - Added safer request/bootstrap handling so missing tokens or malformed requests do not crash as hard 500 errors.
  - Prevented finder-modal creates from selecting the new patient globally in session.

- `/Applications/XAMPP/xamppfiles/htdocs/openemr/interface/new/new.php`
  - Updated the add-patient form duplicate review display to use cleaner facility labels.
  - Improved client-side validation behavior for finder-modal patient creation.
  - Reduced false required-field warnings for multi-select care team facility handling.

- `/Applications/XAMPP/xamppfiles/htdocs/openemr/interface/main/finder/dynamic_finder.php`
  - Fixed the add-patient modal AJAX flow to handle successful JSON responses correctly.
  - Stopped successful patient creation from falling into the generic error banner.
  - Updated modal post-create behavior so it closes and refreshes instead of opening the new patient summary flow unexpectedly.
  - Cleared patient context more safely after finder-modal patient creation.

- `/Applications/XAMPP/xamppfiles/htdocs/openemr/interface/main/finder/existing_finder.php`
  - Applied the same modal AJAX success-handling improvements as `dynamic_finder.php`.
  - Prevented successful patient creates from being misread as front-end failures.
  - Updated modal close/refresh behavior after successful patient creation.

- `/Applications/XAMPP/xamppfiles/htdocs/openemr/vendor/particle/validator/src/Rule/Datetime.php`
  - Fixed the datetime validator so valid DOB values are not rejected when `DateTime::getLastErrors()` returns `false` on newer PHP behavior.
  - Removed a hidden patient-create failure caused by valid DOB values being treated as invalid.

- `/Applications/XAMPP/xamppfiles/htdocs/openemr/interface/main/messages/messages.php`
  - Added a defensive Select2 availability check on the Message Center page.
  - Added direct Select2 CSS/JS includes on the page as a fallback for staging asset-load issues.
  - If Select2 is still unavailable, the SMS patient search now disables gracefully instead of breaking page JavaScript.

## Outcome

Main result of these follow-up changes:

- finder-modal patient creation is more stable and returns cleaner success/error states
- duplicate warnings now show more useful facility information
- weak duplicate matches based only on names no longer drive the warning flow
- valid DOB values no longer fail because of the third-party datetime validator behavior
- Message Center no longer hard-crashes when Select2 assets are missing on staging

## Notes

- Some staging issues may still depend on whether the latest code and static assets have been deployed there.
- Legacy patient rows that originally pointed to removed facility IDs required separate data cleanup in addition to the UI/helper changes above.
