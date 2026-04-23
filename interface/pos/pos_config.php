<?php
/**
 * POS Configuration Page
 * Allows administrators to configure POS settings including service prices
 */

require_once(__DIR__ . "/../globals.php");
require_once("$srcdir/globals.inc.php");

// Check if user has admin privileges
if (!isset($_SESSION['authUserID']) || !$_SESSION['authUserID']) {
    echo "<div class='alert alert-danger'>Access Denied. Please log in.</div>";
    exit;
}

// Handle form submission
if (isset($_POST['action']) && $_POST['action'] == 'save_config') {
    $consultation_price = floatval($_POST['consultation_price']);
    
    // Validate prices
    if ($consultation_price < 0) {
        $error_message = "Prices cannot be negative.";
    } else {
        // Save to globals
        sqlStatement("DELETE FROM globals WHERE gl_name = 'pos_consultation_price'");
        sqlStatement("INSERT INTO globals (gl_name, gl_index, gl_value) VALUES ('pos_consultation_price', 0, ?)", array($consultation_price));
        
        $success_message = "Configuration saved successfully!";
    }
}

// Get current values
$res = sqlStatement("SELECT gl_value FROM globals WHERE gl_name = 'pos_consultation_price'");
$row = sqlFetchArray($res);
$current_consultation_price = $row ? floatval($row['gl_value']) : 39.95;

use OpenEMR\Core\Header;

$title = xlt("POS Configuration");
?>
<title><?php echo $title; ?></title>
<?php Header::setupHeader(['common']); ?>

<style>
.pos-config-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    padding: 1.2rem 1rem 1rem 1rem;
    max-width: 900px;
    margin: 1.2rem auto;
}
.pos-config-title {
    font-size: 1.2rem;
    font-weight: 700;
    margin-bottom: 1.5rem;
    color: #2a3b4d;
    text-align: center;
}
.pos-config-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.7rem 1.2rem;
}
@media (max-width: 900px) {
    .pos-config-grid {
        grid-template-columns: 1fr;
    }
}
.pos-config-label {
    font-weight: 600;
    color: #333;
    margin-bottom: 0.1rem;
    display: block;
}
.pos-config-input {
    width: 100%;
    padding: 0.45rem 0.7rem;
    border: 1.5px solid #E1E5E9;
    border-radius: 7px;
    font-size: 0.98rem;
    background: white;
    margin-bottom: 0.1rem;
}
.pos-config-input:focus {
    border-color: #4A90E2;
    outline: none;
    box-shadow: 0 0 0 0.2rem rgba(74, 144, 226, 0.25);
}
.pos-config-actions {
    grid-column: 1 / -1;
    display: flex;
    justify-content: center;
    gap: 0.7rem;
    margin-top: 1rem;
}
.btn-save {
    background: #4A90E2;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 0.5rem 1.5rem;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}
.btn-save:hover {
    background: #357ABD;
}
.btn-cancel {
    background: #f8f9fa;
    color: #495057;
    border: 2px solid #E1E5E9;
    border-radius: 8px;
    padding: 0.5rem 1.5rem;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}
.btn-cancel:hover {
    background: #e9ecef;
}
.current-config-section {
    background: #f8f9fa;
    border: 1px solid #E1E5E9;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1.5rem;
}
.current-config-title {
    font-weight: 600;
    color: #2a3b4d;
    margin-bottom: 0.8rem;
    font-size: 1rem;
}
.current-config-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
}
.current-config-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem;
    background: white;
    border-radius: 6px;
    border: 1px solid #E1E5E9;
}
.current-config-label {
    font-weight: 600;
    color: #333;
}
.current-config-value {
    font-weight: 700;
    color: #2a3b4d;
}
.alert {
    padding: 0.75rem 1rem;
    margin-bottom: 1rem;
    border-radius: 8px;
    border: 1px solid transparent;
}
.alert-success {
    background-color: #d4edda;
    border-color: #c3e6cb;
    color: #155724;
}
.alert-danger {
    background-color: #f8d7da;
    border-color: #f5c6cb;
    color: #721c24;
}
</style>
</head>

<body class="body_top">

<div class="pos-config-card">
    <div class="pos-config-title">
        <i class="fas fa-cog me-2" style="color: #4A90E2;"></i>
        <?php echo xlt('POS Configuration'); ?>
    </div>
    
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo xlt($success_message); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle"></i> <?php echo xlt($error_message); ?>
        </div>
    <?php endif; ?>
    
    <div class="current-config-section">
        <div class="current-config-title">
            <i class="fas fa-info-circle"></i> <?php echo xlt('Current Configuration'); ?>
        </div>
        <div class="current-config-grid">
            <div class="current-config-item">
                <span class="current-config-label"><?php echo xlt('Consultation Service Price'); ?></span>
                <span class="current-config-value">$<?php echo number_format($current_consultation_price, 2); ?></span>
            </div>
        </div>
    </div>
    
    <form method="post" action="" id="pos_config_form">
        <input type="hidden" name="action" value="save_config">
        
        <div class="pos-config-grid">
            <div>
                <label class="pos-config-label">
                    <i class="fas fa-stethoscope"></i> <?php echo xlt('Consultation Service Price ($)'); ?>
                </label>
                <input type="number" 
                       id="consultation_price" 
                       name="consultation_price" 
                       value="<?php echo htmlspecialchars($current_consultation_price); ?>" 
                       step="0.01" 
                       min="0" 
                       class="pos-config-input"
                       required>
                <small style="color: #6c757d; font-size: 0.875rem;">
                    <?php echo xlt('Enter the price for consultation services (e.g., 100.00)'); ?>
                </small>
            </div>
        </div>
        
        <div class="pos-config-actions">
            <button type="button" id="cancel-btn" class="btn-cancel">
                <?php echo xlt('Cancel'); ?>
            </button>
            <button type="submit" class="btn-save">
                <?php echo xlt('Save Configuration'); ?>
            </button>
        </div>
    </form>
</div>

<script>
    // Store original values for cancel functionality
    const originalConsultationPrice = <?php echo $current_consultation_price; ?>;
    
    // Add some interactive features
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-format price inputs
        const priceInputs = document.querySelectorAll('input[type="number"]');
        priceInputs.forEach(input => {
            input.addEventListener('blur', function() {
                if (this.value) {
                    this.value = parseFloat(this.value).toFixed(2);
                }
            });
        });
        
        // Cancel button functionality
        document.getElementById('cancel-btn').addEventListener('click', function() {
            // Restore original values
            document.getElementById('consultation_price').value = originalConsultationPrice.toFixed(2);
        });
        
        // Form validation
        const form = document.getElementById('pos_config_form');
        form.addEventListener('submit', function(e) {
            const consultationPrice = parseFloat(document.getElementById('consultation_price').value);
            
            if (consultationPrice < 0) {
                e.preventDefault();
                 ?>');
                return false;
            }
            
            if (isNaN(consultationPrice)) {
                e.preventDefault();
                 ?>');
                return false;
            }
        });
    });
</script>
</body>
</html> 