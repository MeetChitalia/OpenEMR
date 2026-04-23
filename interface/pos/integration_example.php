<?php
require_once(__DIR__ . "/../globals.php");
use OpenEMR\Core\Header;
Header::setupHeader();
?>

<!DOCTYPE html>
<html>
<head>
    <title>POS Integration Example</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            padding: 20px; 
            background: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .pos-btn { 
            background: #28a745; 
            color: white; 
            border: none; 
            padding: 10px 20px; 
            border-radius: 5px; 
            cursor: pointer; 
            margin: 5px;
            font-size: 14px;
        }
        .pos-btn:hover { background: #218838; }
        #pos-modal-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 99999;
            display: none;
        }
        .integration-code {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            margin: 15px 0;
            font-family: monospace;
            font-size: 12px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 POS Modal Integration Guide</h1>
        
        <h2>How to Integrate into Your Finder Page</h2>
        
        <h3>1. Add this HTML to your Finder page:</h3>
        <div class="integration-code">
&lt;!-- Add this button where you want the POS modal to appear --&gt;
&lt;button class="pos-btn" onclick="openPOSModal(patientId, balance)"&gt;
    &lt;i class="fa fa-cash-register"&gt;&lt;/i&gt; POS
&lt;/button&gt;

&lt;!-- Add this container at the bottom of your page --&gt;
&lt;div id="pos-modal-container"&gt;&lt;/div&gt;
        </div>
        
        <h3>2. Add this JavaScript to your Finder page:</h3>
        <div class="integration-code">
&lt;script&gt;
function openPOSModal(patientId, balance) {
    console.log('Opening POS Modal for patient:', patientId);
    
    var container = document.getElementById('pos-modal-container');
    container.style.display = 'block';
    
    // Create iframe for POS modal
    var iframe = document.createElement('iframe');
    iframe.src = 'interface/pos/pos_modal.php?pid=' + patientId + '&balance=' + balance;
    iframe.style.width = '100%';
    iframe.style.height = '100%';
    iframe.style.border = 'none';
    
    container.innerHTML = '';
    container.appendChild(iframe);
}

function closePOSModal() {
    console.log('Closing POS Modal');
    var container = document.getElementById('pos-modal-container');
    container.style.display = 'none';
    container.innerHTML = '';
}

// Make closePOSModal available globally
window.closePOSModal = closePOSModal;
&lt;/script&gt;
        </div>
        
        <h3>3. Test the Integration:</h3>
        <p>Click the buttons below to test different integration scenarios:</p>
        
        <button class="pos-btn" onclick="openPOSModal(7, 0)">Open POS (Patient 7)</button>
        <button class="pos-btn" onclick="openPOSModal(8, 25.50)">Open POS (Patient 8, Balance $25.50)</button>
        <button class="pos-btn" onclick="openPOSModal('', 0)">Open POS (No Patient)</button>
        
        <div id="pos-modal-container"></div>
        
        <h3>4. Troubleshooting Tips:</h3>
        <ul>
            <li><strong>Z-Index Issues:</strong> Make sure the modal container has high z-index (99999)</li>
            <li><strong>CSS Conflicts:</strong> Check if your page CSS is overriding modal styles</li>
            <li><strong>JavaScript Errors:</strong> Check browser console for any JavaScript errors</li>
            <li><strong>Path Issues:</strong> Ensure the path to pos_modal.php is correct</li>
            <li><strong>Search Results:</strong> Type "Semi" in search box to test functionality</li>
        </ul>
        
        <h3>5. Expected Behavior:</h3>
        <ul>
            <li>✅ Modal opens in full-screen overlay</li>
            <li>✅ Patient information displays (if provided)</li>
            <li>✅ Search box accepts input</li>
            <li>✅ Type "Semi" shows "Semiglutide" in blue dropdown</li>
            <li>✅ Click results adds items to cart</li>
            <li>✅ Cancel button closes modal</li>
        </ul>
    </div>
    
    <script>
        function openPOSModal(patientId, balance) {
            console.log('Opening POS Modal for patient:', patientId, 'balance:', balance);
            
            var container = document.getElementById('pos-modal-container');
            container.style.display = 'block';
            
            // Create iframe for POS modal
            var iframe = document.createElement('iframe');
            var url = 'pos_modal.php';
            if (patientId) {
                url += '?pid=' + patientId + '&balance=' + balance;
            }
            iframe.src = url;
            iframe.style.width = '100%';
            iframe.style.height = '100%';
            iframe.style.border = 'none';
            
            container.innerHTML = '';
            container.appendChild(iframe);
        }
        
        function closePOSModal() {
            console.log('Closing POS Modal');
            var container = document.getElementById('pos-modal-container');
            container.style.display = 'none';
            container.innerHTML = '';
        }
        
        // Make closePOSModal available globally
        window.closePOSModal = closePOSModal;
        
        console.log('Integration example loaded');
    </script>
</body>
</html> 