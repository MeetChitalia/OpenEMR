# OpenEMR Custom Workflow Portfolio

Public-safe showcase repository for selected OpenEMR customizations by **Meet Chitalia**.

This folder is designed to become a standalone public repository you can share with recruiters. It focuses on real workflow engineering work while intentionally excluding patient data, credentials, deployment details, and private business context.

## Recruiter Snapshot

This portfolio demonstrates experience in:

- PHP application customization inside a large healthcare codebase
- POS and checkout workflow design for clinic operations
- Inventory deduction and quantity-on-hand tracking
- Reporting enhancements for finance and operations teams
- Front-desk finder and patient workflow improvements
- Debugging and stabilizing production-heavy business logic

## What This Shows

### POS and Checkout Engineering

The `custom-modules/pos/` examples show work on:

- patient checkout and modal-based POS flows
- consult, medicine, dispense, and administer billing paths
- transaction history and receipt rendering
- payment processing logic
- backdated transaction support
- approval and override workflows

### Reporting and Operations

The `custom-modules/reports/` example highlights:

- enhanced daily collection reporting
- patient and medicine-level breakdowns
- facility-aware reporting logic
- export and operational reporting support

### Finder and Front-Desk Workflow

The `custom-modules/finder/` examples show:

- patient finder customization
- facility-aware filtering
- balance and payment visibility
- workflow shortcuts for staff-facing operations

## Included Portfolio Files

- `custom-modules/pos/backdate_pos_screen.php`
- `custom-modules/pos/pos_modal.php`
- `custom-modules/pos/pos_patient_transaction_history.php`
- `custom-modules/pos/pos_payment_processor.php`
- `custom-modules/pos/pos_receipt.php`
- `custom-modules/reports/dcr_daily_collection_report_enhanced.php`
- `custom-modules/finder/dynamic_finder.php`
- `custom-modules/finder/existing_finder.php`

## Notes About the Code

- These are selected excerpts and working module copies from a larger OpenEMR-based project.
- Sensitive recipient data and environment-specific details have been sanitized for public sharing.
- This is a portfolio repository, not a full deployable product.
- The underlying platform is OpenEMR, so some framework and upstream references remain intentionally intact.

## Suggested Screenshots

Add screenshots to `screenshots/` and reference them here:

```md
![POS Checkout](screenshots/pos-checkout.png)
![Transaction History](screenshots/transaction-history.png)
![DCR Report](screenshots/dcr-report.png)
![Patient Finder](screenshots/patient-finder.png)
```

## Repo Structure

```text
openemr-custom-portfolio/
├── README.md
├── .gitignore
├── docs/
│   ├── FEATURES.md
│   ├── FILE_GUIDE.md
│   ├── PUBLISHING_CHECKLIST.md
│   └── RECRUITER_SUMMARY.md
├── screenshots/
│   └── .gitkeep
└── custom-modules/
    ├── finder/
    ├── pos/
    └── reports/
```

## Tech Stack

- PHP
- JavaScript
- MySQL / MariaDB
- Apache / XAMPP
- OpenEMR

## Next Step Before Publishing

Review [docs/PUBLISHING_CHECKLIST.md](docs/PUBLISHING_CHECKLIST.md), then copy this folder into its own GitHub repository and add 3-5 screenshots for the strongest recruiter presentation.

## Maintainer

**Meet Chitalia**
