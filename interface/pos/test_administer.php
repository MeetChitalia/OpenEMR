<?php
require_once(__DIR__ . "/../globals.php");
use OpenEMR\Common\Csrf\CsrfUtils;

if (!isset($_SESSION['authUserID'])) {
    die(xlt('Not authorized'));
}

$pid = $_GET['pid'] ?? 15;
$test_drug_id = 1;
$test_lot = 'TEST_LOT_001';

// Get current daily count
$today = date('Y-m-d');
$current_count = 0;

$table_exists = sqlQuery("SHOW TABLES LIKE 'daily_administer_tracking'");
if ($table_exists) {
    $count_result = sqlFetchArray(sqlStatement(
        "SELECT total_administered FROM daily_administer_tracking 
         WHERE pid = ? AND drug_id = ? AND lot_number = ? AND administer_date = ?",
        array($pid, $test_drug_id, $test_lot, $today)
    ));
    $current_count = $count_result ? intval($count_result['total_administered']) : 0;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Test Daily Administration Limit</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .test-section { background: #f8f9fa; padding: 20px; margin: 20px 0; border-radius: 8px; }
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; margin: 5px; }
        .btn-primary { background: #007bff; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .console { background: #000; color: #0f0; padding: 15px; font-family: monospace; height: 200px; overflow-y: scroll; }
    </style>
    <meta name="csrf-token" content="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
</head>
<body>
    <h1>Test Daily Administration Limit</h1>
    
    <div class="test-section">
        <h3>Current Status</h3>
        <p><strong>Patient ID:</strong> <?php echo $pid; ?></p>
        <p><strong>Current Daily Count:</strong> <span id="current-count"><?php echo $current_count; ?></span>/2</p>
        <p><strong>Table Exists:</strong> <?php echo $table_exists ? 'Yes' : 'No'; ?></p>
    </div>
    
    <div class="test-section">
        <h3>Test Actions</h3>
        <button class="btn btn-primary" onclick="testLimitCheck(1)">Test 1 Dose</button>
        <button class="btn btn-primary" onclick="testLimitCheck(2)">Test 2 Doses</button>
        <button class="btn btn-danger" onclick="testLimitCheck(3)">Test 3 Doses (Should Fail)</button>
        <button class="btn btn-danger" onclick="clearCount()">Clear Count</button>
    </div>
    
    <div class="test-section">
        <h3>Console Output</h3>
        <div id="console-output" class="console"></div>
    </div>
    
    <div style="margin-top: 20px;">
        <a href="pos_modal.php?pid=<?php echo $pid; ?>" class="btn btn-primary">Back to POS</a>
    </div>
    
    <script>
        function log(message) {
            const console = document.getElementById('console-output');
            const timestamp = new Date().toLocaleTimeString();
            console.innerHTML += `[${timestamp}] ${message}\n`;
            console.scrollTop = console.scrollHeight;
        }
        
        function testLimitCheck(quantity) {
            log(`Testing limit check for ${quantity} dose(s)...`);
            
            fetch('pos_payment_processor.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'check_administer_limit',
                    pid: <?php echo $pid; ?>,
                    drug_id: <?php echo $test_drug_id; ?>,
                    lot_number: '<?php echo $test_lot; ?>',
                    requested_quantity: quantity,
                    csrf_token_form: document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                })
            })
            .then(response => response.json())
            .then(data => {
                log(`Response: ${JSON.stringify(data)}`);
                if (data.success && data.can_administer) {
                    log(`✅ PASSED: Can administer ${quantity} dose(s)`);
                } else {
                    log(`❌ FAILED: ${data.error || 'Unknown error'}`);
                }
            })
            .catch(error => {
                log(`Error: ${error.message}`);
            });
        }
        
        function clearCount() {
            log('Clearing daily count...');
            location.reload();
        }
        
        log('Test page loaded');
        log(`Current count: <?php echo $current_count; ?>/2`);
    </script>
</body>
</html>
