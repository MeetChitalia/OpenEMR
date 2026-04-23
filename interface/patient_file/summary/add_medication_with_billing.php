<?php

/**
 * Add Medication with Billing Integration
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

// Default fee for medications
$default_fee = getDefaultFee('medication');

?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(['common', 'datetime-picker']); ?>
    <title><?php echo xlt('Add Medication with Billing'); ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 500px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; color: #333; }
        select, input, textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        .info-box { background: #f8f9fa; padding: 10px; border-radius: 4px; border-left: 4px solid #007bff; margin: 10px 0; }
        .qoh-info { background: #e7f3ff; padding: 8px; border-radius: 4px; margin: 5px 0; }
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; margin: 5px; }
        .btn-primary { background: #007bff; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .alert { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-danger { background: #f8d7da; color: #721c24; }
        .product-detail { color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2><?php echo xlt('Add Medication with Billing'); ?></h2>
            <p><strong><?php echo xlt('Patient'); ?>:</strong> <?php echo text(($patient['fname'] ?? '') . ' ' . ($patient['mname'] ?? '') . ' ' . ($patient['lname'] ?? '')); ?></p>
        </div>
        
        <div id="alert-container"></div>
        
        <form id="medication-form" method="post">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
            <input type="hidden" name="pid" value="<?php echo attr($pid); ?>" />
            <input type="hidden" name="encounter_id" value="<?php echo attr($encounter_id); ?>" />
            
            <div class="form-group">
                <label for="medication_name"><?php echo xlt('Select Product'); ?> *</label>
                <select id="medication_name" name="medication_name" required>
                    <option value=""><?php echo xlt('Choose a product...'); ?></option>
                    <?php
                    // Get inventory products with size and form details
                    $inventory_sql = "SELECT DISTINCT 
                                        d.drug_id,
                                        d.name as drug_name,
                                        d.size,
                                        d.form,
                                        d.ndc_number,
                                        COALESCE(SUM(di.on_hand), 0) as total_qoh,
                                        MIN(di.cost_per_unit) as cost_per_unit,
                                        MIN(di.sell_price) as sell_price
                                     FROM drugs d
                                     LEFT JOIN drug_inventory di ON d.drug_id = di.drug_id 
                                        AND di.destroy_date IS NULL
                                     WHERE d.active = 1
                                     GROUP BY d.drug_id, d.name, d.size, d.form, d.ndc_number
                                     HAVING total_qoh > 0
                                     ORDER BY d.name";
                    
                    $inventory_result = sqlStatement($inventory_sql);
                    while ($row = sqlFetchArray($inventory_result)) {
                        $display_name = $row['drug_name'];
                        if (!empty($row['size'])) {
                            $display_name .= ' - ' . $row['size'];
                        }
                        if (!empty($row['form'])) {
                            $display_name .= ' (' . $row['form'] . ')';
                        }
                        
                        $option_value = $row['drug_id'] . '|' . $row['drug_name'] . '|' . $row['total_qoh'] . '|' . $row['cost_per_unit'] . '|' . $row['sell_price'] . '|' . $row['size'] . '|' . $row['form'];
                        echo "<option value='" . attr($option_value) . "'>" . text($display_name) . "</option>";
                    }
                    ?>
                </select>
            </div>
            
            <div id="product-info" class="info-box" style="display: none;">
                <div id="selected_medication_info"></div>
            </div>
            
            <div class="form-group">
                <label for="quantity"><?php echo xlt('Quantity'); ?> *</label>
                <input type="number" id="quantity" name="quantity" min="1" value="1" required>
                <small id="qoh-limit"></small>
            </div>
            
            <div class="form-group">
                <label for="dosage"><?php echo xlt('Dosage Instructions'); ?></label>
                <input type="text" id="dosage" name="dosage" placeholder="<?php echo xla('e.g., 1 tablet daily'); ?>">
            </div>
            
            <div class="form-group">
                <label for="comments"><?php echo xlt('Notes'); ?></label>
                <textarea id="comments" name="comments" rows="2" placeholder="<?php echo xla('Additional notes'); ?>"></textarea>
            </div>
            
            <div class="info-box">
                <h4><?php echo xlt('Billing'); ?></h4>
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="manual_fee" name="manual_fee">
                        <?php echo xlt('Manual Fee Entry'); ?>
                    </label>
                </div>
                
                <div class="form-group">
                    <label for="unit_fee"><?php echo xlt('Unit Fee ($)'); ?> *</label>
                    <input type="number" id="unit_fee" name="unit_fee" value="<?php echo $default_fee; ?>" step="0.01" min="0" required>
                    <small id="unit_fee_note"><?php echo xlt('Fee per unit'); ?></small>
                </div>
                
                <div class="form-group">
                    <label for="total_amount"><?php echo xlt('Total Amount ($)'); ?></label>
                    <input type="number" id="total_amount" name="total_amount" readonly>
                    <small><?php echo xlt('Calculated: Unit Fee × Quantity'); ?></small>
                </div>
                
                <div class="form-group">
                    <label for="description"><?php echo xlt('Description'); ?></label>
                    <input type="text" id="description" name="description" placeholder="<?php echo xla('Billing description'); ?>">
                </div>
            </div>
            
            <div class="form-group" style="text-align: center;">
                <button type="submit" class="btn btn-primary"><?php echo xlt('Add Charge'); ?></button>
                <button type="button" class="btn btn-secondary" onclick="window.close()"><?php echo xlt('Cancel'); ?></button>
            </div>
        </form>
    </div>

    <script>
        $(document).ready(function() {
            var currentQOH = 0;
            var currentDrugId = 0;
            
            // Initialize unit fee field as readonly (auto-calculated mode)
            $('#unit_fee').prop('readonly', true);
            
            // Initialize total amount
            updateTotalAmount();
            
            // Handle product selection
            $('#medication_name').on('change', function() {
                var selectedValue = $(this).val();
                if (selectedValue) {
                    var parts = selectedValue.split('|');
                    currentDrugId = parts[0];
                    var drugName = parts[1];
                    currentQOH = parseInt(parts[2]);
                    var costPerUnit = parts[3];
                    var sellPrice = parts[4];
                    var size = parts[5];
                    var form = parts[6];
                    
                    // Update product info display
                    var infoHtml = '<div class="qoh-info"><strong>QOH: ' + currentQOH + ' units</strong></div>';
                    infoHtml += '<strong>' + drugName + '</strong>';
                    if (size) infoHtml += '<div class="product-detail">Size: ' + size + '</div>';
                    if (form) infoHtml += '<div class="product-detail">Form: ' + form + '</div>';
                    if (sellPrice && sellPrice > 0) {
                        infoHtml += '<div class="product-detail">Price: $' + parseFloat(sellPrice).toFixed(2) + ' per unit</div>';
                    }
                    
                    $('#selected_medication_info').html(infoHtml);
                    $('#product-info').show();
                    
                    // Set quantity limits
                    $('#quantity').attr('max', currentQOH);
                    $('#qoh-limit').text('Maximum: ' + currentQOH + ' units');
                    
                    // Update fee based on selection (if not manual mode)
                    if (!$('#manual_fee').is(':checked')) {
                        updateFeeFromSelection();
                    }
                    
                    // Auto-generate billing description
                    updateBillingDescription();
                } else {
                    $('#product-info').hide();
                    $('#qoh-limit').text('');
                    $('#fee').val('<?php echo $default_fee; ?>');
                }
            });
            
            // Handle manual fee toggle
            $('#manual_fee').on('change', function() {
                var isManual = $(this).is(':checked');
                if (isManual) {
                    $('#unit_fee').prop('readonly', false);
                    $('#unit_fee_note').text('<?php echo xlt('Enter unit fee manually'); ?>');
                } else {
                    $('#unit_fee').prop('readonly', true);
                    $('#unit_fee_note').text('<?php echo xlt('Auto-calculated from inventory price'); ?>');
                    // Recalculate fee based on current selection
                    updateFeeFromSelection();
                }
            });
            
            // Handle quantity changes
            $('#quantity').on('input', function() {
                var quantity = parseInt($(this).val());
                if (quantity > currentQOH) {
                    $(this).val(currentQOH);
                    quantity = currentQOH;
                }
                
                // Update total amount
                updateTotalAmount();
                
                // Update unit fee if not manual mode
                if (!$('#manual_fee').is(':checked')) {
                    updateFeeFromSelection();
                }
                
                updateBillingDescription();
            });
            
            // Handle unit fee changes
            $('#unit_fee').on('input', function() {
                updateTotalAmount();
                updateBillingDescription();
            });
            
            function updateFeeFromSelection() {
                var selectedValue = $('#medication_name').val();
                if (selectedValue) {
                    var parts = selectedValue.split('|');
                    var sellPrice = parts[4];
                    
                    if (sellPrice && sellPrice > 0) {
                        $('#unit_fee').val(parseFloat(sellPrice).toFixed(2));
                        updateTotalAmount();
                    }
                }
            }
            
            function updateTotalAmount() {
                var unitFee = parseFloat($('#unit_fee').val()) || 0;
                var quantity = parseInt($('#quantity').val()) || 1;
                var totalAmount = unitFee * quantity;
                $('#total_amount').val(totalAmount.toFixed(2));
            }
            
            // Auto-generate billing description
            $('#dosage').on('input', function() {
                updateBillingDescription();
            });
            
            function updateBillingDescription() {
                var selectedValue = $('#medication_name').val();
                var quantity = $('#quantity').val();
                var dosage = $('#dosage').val();
                var description = '';
                
                if (selectedValue) {
                    var parts = selectedValue.split('|');
                    var drugName = parts[1];
                    description = drugName + ' - Qty: ' + quantity;
                    if (dosage) {
                        description += ' - ' + dosage;
                    }
                }
                
                $('#description').val(description);
            }
            
            // Handle form submission
            $('#medication-form').on('submit', function(e) {
                e.preventDefault();
                
                var formData = new FormData(this);
                var selectedValue = $('#medication_name').val();
                var drugName = '';
                var drugId = '';
                if (selectedValue) {
                    var parts = selectedValue.split('|');
                    drugId = parts[0];
                    drugName = parts[1];
                }
                
                formData.append('action', 'add_clinical_item');
                formData.append('item_type', 'medication');
                formData.append('item_name', drugName);
                formData.append('drug_id', drugId);
                formData.append('quantity', $('#quantity').val());
                formData.append('comments', $('#comments').val());
                formData.append('fee', $('#total_amount').val());
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