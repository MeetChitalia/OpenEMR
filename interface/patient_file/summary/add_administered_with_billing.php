<?php

/**
 * Add Administered Item with Billing Integration
 * 
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Meet Patel <meetpatel1798@gmail.com>
 * @copyright Copyright (c) 2024 Meet Patel
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../../globals.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/options.inc.php");
require_once("clinical_billing_integration.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$pid = $_GET['pid'] ?? 0;

if (!$pid) {
    echo "Error: Patient ID required";
    exit;
}

// Get patient info
$patient_result = sqlQuery("SELECT fname, mname, lname FROM patient_data WHERE pid = ?", array($pid));
$patient = ensureArrayResult($patient_result);

// Get current encounter
$encounter_id = getCurrentEncounter($pid);

// Default fee for administered items
$default_fee = getDefaultFee('administered');

// Get immunization options
$immunization_options = array();
$result = sqlStatement("SELECT option_id, title FROM list_options WHERE list_id = 'immunizations' AND activity = 1 ORDER BY title");
while ($row = sqlFetchArray($result)) {
    $immunization_options[$row['option_id']] = $row['title'];
}

?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(['common', 'datetime-picker']); ?>
    <title><?php echo xlt('Add Administered Item with Billing'); ?></title>
    <style>
        .form-container {
            max-width: 600px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-control {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin-right: 10px;
        }
        .btn-primary {
            background: #007bff;
            color: white;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .billing-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-top: 20px;
            border-left: 4px solid #007bff;
        }
        .alert {
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .item-type-section {
            margin-bottom: 20px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .hidden-field {
            display: none;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <h2><?php echo xlt('Add Administered Item with Billing'); ?></h2>
        <p><strong><?php echo xlt('Patient'); ?>:</strong> <?php echo text(($patient['fname'] ?? '') . ' ' . ($patient['mname'] ?? '') . ' ' . ($patient['lname'] ?? '')); ?></p>
        
        <div id="alert-container"></div>
        
        <form id="administered-form" method="post">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
            <input type="hidden" name="pid" value="<?php echo attr($pid); ?>" />
            <input type="hidden" name="encounter_id" value="<?php echo attr($encounter_id); ?>" />
            
            <div class="form-group">
                <label for="item_type"><?php echo xlt('Item Type'); ?> *</label>
                <select class="form-control" id="item_type" name="item_type" required>
                    <option value=""><?php echo xlt('Select item type'); ?></option>
                    <option value="immunization"><?php echo xlt('Immunization'); ?></option>
                    <option value="procedure"><?php echo xlt('Procedure'); ?></option>
                    <option value="injection"><?php echo xlt('Injection'); ?></option>
                    <option value="treatment"><?php echo xlt('Treatment'); ?></option>
                    <option value="other"><?php echo xlt('Other'); ?></option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="item_name"><?php echo xlt('Item Name'); ?> *</label>
                <input type="text" class="form-control" id="item_name" name="item_name" required>
            </div>
            
            <div class="form-group">
                <label for="administered_date"><?php echo xlt('Administered Date'); ?></label>
                <input type="date" class="form-control" id="administered_date" name="administered_date" value="<?php echo date('Y-m-d'); ?>">
            </div>
            
            <div class="form-group hidden-field">
                <label for="manufacturer"><?php echo xlt('Manufacturer/Lot Number'); ?></label>
                <input type="text" class="form-control" id="manufacturer" name="manufacturer" placeholder="<?php echo xla('e.g., Pfizer, Lot #12345'); ?>">
            </div>
            
            <div class="form-group">
                <label for="route"><?php echo xlt('Route of Administration'); ?></label>
                <select class="form-control" id="route" name="route">
                    <option value=""><?php echo xlt('Select route'); ?></option>
                    <option value="intramuscular"><?php echo xlt('Intramuscular'); ?></option>
                    <option value="subcutaneous"><?php echo xlt('Subcutaneous'); ?></option>
                    <option value="intravenous"><?php echo xlt('Intravenous'); ?></option>
                    <option value="oral"><?php echo xlt('Oral'); ?></option>
                    <option value="topical"><?php echo xlt('Topical'); ?></option>
                    <option value="other"><?php echo xlt('Other'); ?></option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="site"><?php echo xlt('Administration Site'); ?></label>
                <input type="text" class="form-control" id="site" name="site" placeholder="<?php echo xla('e.g., Left arm, Right thigh'); ?>">
            </div>
            
            <div class="form-group">
                <label for="notes"><?php echo xlt('Notes'); ?></label>
                <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="<?php echo xla('Additional notes about the administered item'); ?>"></textarea>
            </div>
            
            <div class="billing-section">
                <h4><?php echo xlt('Billing Information'); ?></h4>
                <div class="form-group">
                    <label for="fee"><?php echo xlt('Fee Amount ($)'); ?> *</label>
                    <input type="number" class="form-control" id="fee" name="fee" value="<?php echo $default_fee; ?>" step="0.01" min="0" required>
                </div>
                
                <div class="form-group">
                    <label for="description"><?php echo xlt('Billing Description'); ?></label>
                    <input type="text" class="form-control" id="description" name="description" placeholder="<?php echo xla('Optional billing description'); ?>">
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="add_billing_charge" name="add_billing_charge" checked>
                        <?php echo xlt('Add billing charge to patient account'); ?>
                    </label>
                </div>
            </div>
            
            <div class="form-group">
                <button type="submit" class="btn btn-primary"><?php echo xlt('Add Administered Item & Charge'); ?></button>
                <button type="button" class="btn btn-secondary" onclick="window.close()"><?php echo xlt('Cancel'); ?></button>
            </div>
        </form>
    </div>

    <script>
        $(document).ready(function() {
            // Auto-generate billing description
            $('#item_type, #item_name, #route, #site').on('input change', function() {
                var itemType = $('#item_type').val();
                var itemName = $('#item_name').val();
                var route = $('#route').val();
                var site = $('#site').val();
                var description = '';
                
                if (itemName) {
                    description = 'Administered: ' + itemName;
                    if (itemType && itemType !== 'other') {
                        description += ' (' + itemType + ')';
                    }
                    if (route) {
                        description += ' - ' + route;
                    }
                    if (site) {
                        description += ' - ' + site;
                    }
                }
                
                $('#description').val(description);
            });
            
            // Handle form submission
            $('#administered-form').on('submit', function(e) {
                e.preventDefault();
                
                var formData = new FormData(this);
                formData.append('action', 'add_clinical_item');
                formData.append('item_type', 'administered');
                formData.append('item_name', $('#item_name').val());
                formData.append('manufacturer', $('#manufacturer').val());
                formData.append('fee', $('#fee').val());
                formData.append('description', $('#description').val());
                
                $.ajax({
                    url: 'clinical_billing_integration.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        try {
                            var result = JSON.parse(response);
                            if (result.success) {
                                showAlert('success', result.message);
                                // Close window after 2 seconds
                                setTimeout(function() {
                                    window.close();
                                    // Notify parent window
                                    if (window.opener) {
                                        window.opener.postMessage('billing_updated', '*');
                                    }
                                }, 2000);
                            } else {
                                showAlert('danger', result.message);
                            }
                        } catch (e) {
                            showAlert('danger', 'Error processing response');
                        }
                    },
                    error: function() {
                        showAlert('danger', 'Error submitting form');
                    }
                });
            });
        });
        
        function showAlert(type, message) {
            var alertHtml = '<div class="alert alert-' + type + '">' + message + '</div>';
            $('#alert-container').html(alertHtml);
        }
    </script>
</body>
</html> 