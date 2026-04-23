<?php

/**
 * Add Prescription with Billing Integration
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

// Default fee for prescriptions
$default_fee = getDefaultFee('prescription');

?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(['common', 'datetime-picker']); ?>
    <title><?php echo xlt('Add Prescription with Billing'); ?></title>
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
    </style>
</head>
<body>
    <div class="form-container">
        <h2><?php echo xlt('Add Prescription with Billing'); ?></h2>
        <p><strong><?php echo xlt('Patient'); ?>:</strong> <?php echo text(($patient['fname'] ?? '') . ' ' . ($patient['mname'] ?? '') . ' ' . ($patient['lname'] ?? '')); ?></p>
        
        <div id="alert-container"></div>
        
        <form id="prescription-form" method="post">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
            <input type="hidden" name="pid" value="<?php echo attr($pid); ?>" />
            <input type="hidden" name="encounter_id" value="<?php echo attr($encounter_id); ?>" />
            
            <div class="form-group">
                <label for="notes"><?php echo xlt('Notes'); ?></label>
                <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="<?php echo xla('Prescription notes and details'); ?>"></textarea>
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
                <button type="submit" class="btn btn-primary"><?php echo xlt('Add Prescription & Charge'); ?></button>
                <button type="button" class="btn btn-secondary" onclick="window.close()"><?php echo xlt('Cancel'); ?></button>
            </div>
        </form>
    </div>

    <script>
        $(document).ready(function() {
            // Auto-generate billing description
            $('#notes').on('input', function() {
                var notes = $('#notes').val();
                var description = '';
                
                if (notes) {
                    description = 'Prescription: ' + notes.substring(0, 50);
                    if (notes.length > 50) {
                        description += '...';
                    }
                }
                
                $('#description').val(description);
            });
            
            // Handle form submission
            $('#prescription-form').on('submit', function(e) {
                e.preventDefault();
                
                var formData = new FormData(this);
                formData.append('action', 'add_clinical_item');
                formData.append('item_type', 'prescription');
                
                // Generate item_name from notes or use default
                var notes = $('#notes').val();
                var itemName = notes ? 'Prescription: ' + notes.substring(0, 30) : 'Prescription';
                formData.append('item_name', itemName);
                
                formData.append('notes', $('#notes').val());
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