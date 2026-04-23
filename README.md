# OpenEMR Customized Edition

![Platform](https://img.shields.io/badge/Platform-OpenEMR-blue)
![Backend](https://img.shields.io/badge/Backend-PHP-777BB4)
![Database](https://img.shields.io/badge/Database-MySQL%20%2F%20MariaDB-orange)
![Frontend](https://img.shields.io/badge/Frontend-JavaScript-yellow)
![Server](https://img.shields.io/badge/Server-Apache-red)
![Maintained By](https://img.shields.io/badge/Maintained%20By-Meet%20Chitalia-success)

A customized and extended implementation of **OpenEMR**, built and maintained by **Meet Chitalia**.

This project enhances the standard OpenEMR platform with clinic-focused improvements in **Point of Sale**, **inventory management**, **reporting**, **billing workflows**, and **operational controls**. The goal is to make day-to-day clinical and front-desk operations more accurate, more efficient, and easier to manage in production.

---

## Overview

OpenEMR is a powerful open source Electronic Health Records (EHR) and medical practice management system. In this customized edition, I expanded the platform with tailored workflows and business logic to support real operational requirements beyond the default product behavior.

This version includes custom work in:

- Point of Sale and checkout flow
- Backdated transaction support
- Inventory deduction and QOH tracking
- Visit type logic and workflow routing
- Price override approval flow
- DCR and reporting enhancements
- Receipt rendering and transaction visibility
- Production bug fixes and staging-to-production support

---

## About Me

Hi, I’m **Meet Chitalia**.

I specialize in customizing healthcare applications and building workflow-driven solutions that improve clinical operations, financial visibility, and system reliability. My work focuses on translating real operational pain points into practical product improvements.

Areas I work in most often:

- POS and billing systems
- Inventory and quantity tracking
- Reporting and reconciliation
- Clinical workflow customization
- Admin approval and validation flows
- Debugging production issues
- Enhancing legacy PHP/OpenEMR systems

---

## Star Features

## 1. Customized POS System
A fully customized POS experience built into OpenEMR to support clinic-specific workflows.

Key capabilities:
- Consultation billing
- Product and medicine sales
- Dispense and administer flows
- Mixed transaction support
- Receipt generation
- Payment processing
- Credit balance handling
- Refund and transaction history workflows

## 2. Backdated POS Transactions
Support for recording transactions against prior dates for operational accuracy.

Benefits:
- Better reconciliation
- Accurate historical reporting
- Reduced manual correction effort

## 3. Inventory Management with QOH Tracking
Enhanced inventory controls tied directly to operational POS activity.

Capabilities:
- Quantity-on-hand visibility
- Lot-level tracking
- Dispense/administer linked deduction
- Product-specific deduction rules
- Better stock control and accountability

## 4. Visit Type Workflow Logic
Custom visit classification to support front-desk and clinical flow.

Visit types include:
- New
- Follow-Up
- Injection
- Returning

This improves workflow consistency and reporting quality.

## 5. Price Override with Admin Validation
Added secure price override handling with approval flow and control logic.

Benefits:
- Better pricing governance
- Reduced unauthorized overrides
- Safer checkout flexibility

## 6. Enhanced DCR Reporting
Extended the Daily Collection Report with operational and financial improvements.

Enhancements include:
- Visit-type-aware reporting
- Override notes
- Card punch behavior improvements
- Export improvements
- Better medicine tracking
- Better patient-level operational visibility

## 7. Receipt and Transaction Improvements
Customized receipt behavior to reflect actual sale activity more clearly.

Examples:
- Dispense shown on receipts
- Administer shown on receipts
- Better payment summaries
- More accurate sale representation

## 8. Stability and Bug Fixes
Resolved multiple issues across POS, receipts, inventory deduction, DCR reporting, and staging/production behavior.

---

## Key Contributions

This customized version includes work such as:

- Built and customized the POS system
- Added consultation and billing workflows
- Implemented backdated POS functionality
- Developed inventory management improvements
- Added QOH tracking and deduction rules
- Implemented custom visit type logic
- Built admin-based price override validation
- Enhanced DCR reports and export output
- Fixed POS and payment flow issues
- Improved receipt rendering logic
- Fixed inventory deduction during dispense/administer actions
- Added clinic-specific workflow improvements across reporting and operations

---

## Project Architecture Focus

This implementation is centered around four operational goals:

1. Improve clinic workflow speed  
2. Increase billing and inventory accuracy  
3. Strengthen reporting and operational visibility  
4. Deliver production-ready customizations tailored to real clinic needs

---

## Tech Stack

- **PHP**
- **JavaScript**
- **MySQL / MariaDB**
- **Apache / XAMPP**
- **OpenEMR Framework**

---

## Setup Instructions

```bash
composer install --no-dev
npm install
npm run build
composer dump-autoload -o
