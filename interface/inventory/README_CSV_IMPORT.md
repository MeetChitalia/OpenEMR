# CSV Inventory Import

## Overview
This tool imports inventory data from a CSV file into the OpenEMR inventory system.

## CSV File Location
`C:\xampp\htdocs\openemr\Files\inventory.csv`

## Access the Import Tool
**Direct URL:** http://localhost/openemr/interface/inventory/import_inventory_csv.php

## CSV Format
The CSV file should have the following columns:
1. **Product Name** - The name of the product/item
2. **Category** - Main category (e.g., "Office", "Medical")
3. **Subcategory** - Product subcategory (e.g., "PaperWork", "Supplies")
4. **Form** - Product form (e.g., "Tablet", "Injection", or "N/A")
5. **Millagram** - Product size/strength (e.g., "500mg", "10ml", or "N/A")

## How It Works
1. **Dynamic Category Creation**: If a category doesn't exist, it will be created automatically
2. **Dynamic Subcategory Creation**: If a subcategory doesn't exist, it will be created automatically
3. **Duplicate Prevention**: Products with the same name will be skipped
4. **N/A Handling**: "N/A" values are converted to empty strings

## Database Mapping
- **Product Name** → `drugs.name`
- **Category** → `categories.category_name` (creates if doesn't exist)
- **Subcategory** → `products.subcategory_name` (creates if doesn't exist)
- **Form** → `drugs.form`
- **Millagram** → `drugs.size`

## Features
- ✅ Automatic category and subcategory creation
- ✅ Duplicate detection and skipping
- ✅ Error reporting with line numbers
- ✅ Import summary with counts
- ✅ Preserves existing inventory workflow
- ✅ Dynamic and flexible structure

## Important Notes
1. **Backup First**: Always backup your database before importing
2. **Duplicates**: Products with existing names will be skipped
3. **Validation**: Empty product names are skipped
4. **N/A Values**: "N/A" is converted to empty string

## After Import
- View imported inventory at: **Reports → Inventory → List**
- Or: http://localhost/openemr/interface/reports/inventory_list.php


