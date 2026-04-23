<?php
require_once("../globals.php");

use OpenEMR\Core\Header;

$v_js_includes = $GLOBALS['v_js_includes'] ?? date('YmdH');
?>
<!doctype html>
<html lang="<?php echo text($language_iso_code); ?>">
<head>
    <?php Header::setupHeader("Dashboard Content"); ?>
    <link rel="stylesheet" href="<?php echo $GLOBALS['webroot'] ?>/public/assets/dashboard/css/dashboard.css?v=<?php echo $v_js_includes; ?>">
    <link rel="stylesheet" href="<?php echo $GLOBALS['webroot'] ?>/library/fontawesome/css/all.min.css?v=<?php echo $v_js_includes; ?>">
    <style>
        body {
            margin: 0;
            padding: 20px;
            background: #F7F8FC;
            font-family: 'Inter', sans-serif;
        }
        
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .main-data-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }
        
        @media (max-width: 768px) {
            .main-data-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Summary Cards -->
    <div class="summary-cards">
        <div class="card">
            <div class="card-header">
                <p><?php echo xlt("Total sales"); ?></p>
            </div>
            <div class="card-body">
                <h2>$822,000</h2>
                <div class="card-comparison text-danger">
                    <i class="fas fa-arrow-down"></i> 48%
                </div>
                <span class="comparison-text"><?php echo xlt("Compared to last week"); ?></span>
            </div>
            <div class="card-chart">
                <canvas id="salesChart"></canvas>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <p><?php echo xlt("Total Patients"); ?></p>
            </div>
            <div class="card-body">
                <h2>1,300</h2>
                <div class="card-comparison text-success">
                    <i class="fas fa-arrow-up"></i> 48%
                </div>
                <span class="comparison-text"><?php echo xlt("Compared to last week"); ?></span>
            </div>
            <div class="card-chart">
                <canvas id="patientsChart"></canvas>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <p><?php echo xlt("Unit Sold"); ?></p>
            </div>
            <div class="card-body">
                <h2>8,000</h2>
                <div class="card-comparison text-success">
                    <i class="fas fa-arrow-up"></i> 48%
                </div>
                <span class="comparison-text"><?php echo xlt("Compared to last week"); ?></span>
            </div>
            <div class="card-chart">
                <canvas id="unitsChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Main Charts and Tables -->
    <div class="main-data-grid">
        <div class="card data-card">
            <div class="card-header">
                <h3><?php echo xlt("Sales"); ?></h3>
                <div class="time-period-selector">
                    <?php echo xlt("Last 7 days"); ?> <i class="fas fa-chevron-down"></i>
                </div>
            </div>
            <div class="card-body">
                <canvas id="mainSalesChart"></canvas>
            </div>
        </div>
        <div class="card data-card">
            <div class="card-header">
                <h3><?php echo xlt("Out of stock"); ?></h3>
                <a href="#" class="view-all"><?php echo xlt("View all"); ?> ></a>
            </div>
            <div class="card-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th><input type="checkbox"></th>
                            <th><?php echo xlt("Product name"); ?></th>
                            <th><?php echo xlt("ID"); ?></th>
                            <th><?php echo xlt("Price"); ?></th>
                            <th><?php echo xlt("Action"); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><input type="checkbox"></td>
                            <td>Petazine</td>
                            <td>933030-3030-303</td>
                            <td>$400,000</td>
                            <td><a href="#" class="action-restock"><?php echo xlt("Restock"); ?></a></td>
                        </tr>
                        <tr>
                            <td><input type="checkbox"></td>
                            <td>Petazine</td>
                            <td>933030-3030-303</td>
                            <td>$400,000</td>
                            <td><a href="#" class="action-restock"><?php echo xlt("Restock"); ?></a></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<script src="<?php echo $GLOBALS['webroot'] . '/library/js/jquery.min.js?v=' . $v_js_includes; ?>"></script>
<script src="<?php echo $GLOBALS['webroot'] . '/library/js/chart.js/dist/Chart.bundle.min.js?v=' . $v_js_includes; ?>"></script>
<script src="<?php echo $GLOBALS['webroot'] ?>/public/assets/dashboard/js/dashboard.js?v=<?php echo $v_js_includes; ?>"></script>
</body>
</html> 