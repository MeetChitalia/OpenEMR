<?php
/**
 * Enhanced Inventory Management Class
 * 
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Your Name
 * @copyright Copyright (c) 2024
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

class EnhancedInventory
{
    private $user_id;
    private $facility_id;
    private $warehouse_id;
    private $audit_enabled;

    public function __construct($user_id = null, $facility_id = null, $warehouse_id = null)
    {
        $this->user_id = $user_id ?: $_SESSION['authUserID'];
        $this->facility_id = $facility_id;
        $this->warehouse_id = $warehouse_id;
        $this->audit_enabled = $this->getSetting('audit_trail_enabled', '1') == '1';
    }

    /**
     * Get inventory setting value
     */
    public function getSetting($setting_name, $default = null)
    {
        $sql = "SELECT setting_value FROM inventory_settings WHERE setting_name = ? AND (is_global = 1 OR facility_id = ?)";
        $result = sqlQuery($sql, array($setting_name, $this->facility_id));
        return $result ? $result['setting_value'] : $default;
    }

    /**
     * Set inventory setting value
     */
    public function setSetting($setting_name, $setting_value, $description = null, $is_global = 1)
    {
        $sql = "INSERT INTO inventory_settings (setting_name, setting_value, setting_description, is_global, facility_id) 
                VALUES (?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_description = VALUES(setting_description)";
        return sqlStatement($sql, array($setting_name, $setting_value, $description, $is_global, $this->facility_id));
    }

    /**
     * Add inventory movement with audit trail
     */
    public function addMovement($drug_id, $inventory_id, $movement_type, $quantity_change, $reference_id = null, $reference_type = null, $reason = null, $notes = null)
    {
        // Get current inventory state
        $inventory = sqlQuery(
            "SELECT di.*, d.name as drug_name FROM drug_inventory di 
             JOIN drugs d ON d.drug_id = di.drug_id 
             WHERE di.inventory_id = ?",
            array($inventory_id)
        );

        if (!$inventory) {
            return false;
        }

        $quantity_before = $inventory['on_hand'];
        $quantity_after = $quantity_before + $quantity_change;

        // Calculate unit cost and total value
        $unit_cost = $inventory['cost_per_unit'] ?: 0;
        $total_value = $unit_cost * abs($quantity_change);

        // Log the movement if audit is enabled
        if ($this->audit_enabled) {
            sqlStatement(
                "INSERT INTO inventory_movement_log (
                    drug_id, inventory_id, warehouse_id, movement_type, 
                    quantity_before, quantity_change, quantity_after, 
                    reference_id, reference_type, lot_number, expiration_date,
                    unit_cost, total_value, reason, notes, user_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                array(
                    $drug_id, $inventory_id, $inventory['warehouse_id'], $movement_type,
                    $quantity_before, $quantity_change, $quantity_after,
                    $reference_id, $reference_type, $inventory['lot_number'], $inventory['expiration'],
                    $unit_cost, $total_value, $reason, $notes, $this->user_id
                )
            );
        }

        // Update inventory
        sqlStatement(
            "UPDATE drug_inventory SET on_hand = ?, last_movement_date = NOW() WHERE inventory_id = ?",
            array($quantity_after, $inventory_id)
        );

        // Check for alerts
        $this->checkAlerts($drug_id, $inventory['warehouse_id']);

        return true;
    }

    /**
     * Check and create inventory alerts
     */
    public function checkAlerts($drug_id, $warehouse_id = null)
    {
        $drug = sqlQuery("SELECT * FROM drugs WHERE drug_id = ?", array($drug_id));
        if (!$drug) {
            return;
        }

        // Get current inventory levels
        $sql = "SELECT SUM(on_hand) as total_on_hand FROM drug_inventory 
                WHERE drug_id = ? AND destroy_date IS NULL";
        $params = array($drug_id);

        if ($warehouse_id) {
            $sql .= " AND warehouse_id = ?";
            $params[] = $warehouse_id;
        }

        $inventory = sqlQuery($sql, $params);
        $total_on_hand = $inventory['total_on_hand'] ?: 0;

        // Check reorder point alert
        if ($drug['reorder_point'] > 0 && $total_on_hand <= $drug['reorder_point']) {
            $this->createAlert($drug_id, $warehouse_id, 'reorder_point', 
                "Product '{$drug['name']}' has reached reorder point. Current: $total_on_hand, Threshold: {$drug['reorder_point']}",
                $drug['reorder_point'], $total_on_hand);
        }

        // Check low stock alert
        $low_stock_threshold = $this->getSetting('low_stock_threshold_days', '30');
        $monthly_usage = $this->calculateMonthlyUsage($drug_id, $warehouse_id);
        $stock_days = $monthly_usage > 0 ? ($total_on_hand / $monthly_usage) * 30 : 999;

        if ($stock_days <= $low_stock_threshold) {
            $this->createAlert($drug_id, $warehouse_id, 'low_stock',
                "Product '{$drug['name']}' has low stock. Days remaining: " . round($stock_days, 1),
                $low_stock_threshold, $stock_days);
        }

        // Check expiration alerts
        $expiration_warning_days = $this->getSetting('expiration_warning_days', '90');
        $expiring_lots = sqlStatement(
            "SELECT * FROM drug_inventory WHERE drug_id = ? AND expiration IS NOT NULL 
             AND expiration <= DATE_ADD(NOW(), INTERVAL ? DAY) AND on_hand > 0 AND destroy_date IS NULL",
            array($drug_id, $expiration_warning_days)
        );

        while ($lot = sqlFetchArray($expiring_lots)) {
            $days_to_expiry = (strtotime($lot['expiration']) - time()) / (60 * 60 * 24);
            $this->createAlert($drug_id, $warehouse_id, 'expiration',
                "Lot '{$lot['lot_number']}' expires in " . round($days_to_expiry) . " days",
                $expiration_warning_days, $days_to_expiry);
        }
    }

    /**
     * Create inventory alert
     */
    private function createAlert($drug_id, $warehouse_id, $alert_type, $message, $threshold_value, $current_value)
    {
        // Check if alert already exists
        $existing = sqlQuery(
            "SELECT alert_id FROM inventory_alerts 
             WHERE drug_id = ? AND warehouse_id = ? AND alert_type = ? AND is_active = 1",
            array($drug_id, $warehouse_id, $alert_type)
        );

        if (!$existing) {
            sqlStatement(
                "INSERT INTO inventory_alerts (drug_id, warehouse_id, alert_type, alert_message, 
                 threshold_value, current_value) VALUES (?, ?, ?, ?, ?, ?)",
                array($drug_id, $warehouse_id, $alert_type, $message, $threshold_value, $current_value)
            );
        }
    }

    /**
     * Calculate monthly usage for a product
     */
    private function calculateMonthlyUsage($drug_id, $warehouse_id = null)
    {
        $sql = "SELECT SUM(quantity) as total_quantity FROM drug_sales 
                WHERE drug_id = ? AND sale_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
                AND trans_type = 1"; // Sales only
        $params = array($drug_id);

        if ($warehouse_id) {
            $sql .= " AND warehouse_id = ?";
            $params[] = $warehouse_id;
        }

        $result = sqlQuery($sql, $params);
        return $result['total_quantity'] ?: 0;
    }

    /**
     * Get vendor information for a product
     */
    public function getVendorInfo($drug_id)
    {
        $vendors = array();
        $result = sqlStatement(
            "SELECT dvi.*, u.fname, u.lname, u.organization 
             FROM drug_vendor_info dvi 
             JOIN users u ON u.id = dvi.vendor_id 
             WHERE dvi.drug_id = ? ORDER BY dvi.is_primary DESC",
            array($drug_id)
        );

        while ($row = sqlFetchArray($result)) {
            $vendors[] = $row;
        }

        return $vendors;
    }

    /**
     * Create vendor order
     */
    public function createVendorOrder($vendor_id, $items, $expected_delivery_date = null, $notes = null)
    {
        $order_total = 0;
        $shipping_cost = 0;
        $tax_amount = 0;

        // Calculate order total
        foreach ($items as $item) {
            $order_total += $item['quantity'] * $item['unit_cost'];
        }

        // Insert order
        $order_id = sqlInsert(
            "INSERT INTO vendor_orders (vendor_id, order_date, expected_delivery_date, 
             order_status, order_total, shipping_cost, tax_amount, notes, created_by) 
             VALUES (?, CURDATE(), ?, 'pending', ?, ?, ?, ?, ?)",
            array($vendor_id, $expected_delivery_date, $order_total, $shipping_cost, $tax_amount, $notes, $this->user_id)
        );

        // Insert order items
        foreach ($items as $item) {
            sqlStatement(
                "INSERT INTO vendor_order_items (order_id, drug_id, vendor_item_code, 
                 quantity_ordered, unit_cost, total_cost) VALUES (?, ?, ?, ?, ?, ?)",
                array($order_id, $item['drug_id'], $item['vendor_item_code'], 
                      $item['quantity'], $item['unit_cost'], $item['quantity'] * $item['unit_cost'])
            );
        }

        return $order_id;
    }

    /**
     * Receive vendor order
     */
    public function receiveVendorOrder($order_id, $received_items)
    {
        $order = sqlQuery("SELECT * FROM vendor_orders WHERE order_id = ?", array($order_id));
        if (!$order || $order['order_status'] == 'delivered') {
            return false;
        }

        foreach ($received_items as $item) {
            $order_item = sqlQuery(
                "SELECT * FROM vendor_order_items WHERE item_id = ?",
                array($item['item_id'])
            );

            if (!$order_item) {
                continue;
            }

            // Update received quantity
            sqlStatement(
                "UPDATE vendor_order_items SET quantity_received = ?, lot_number = ?, 
                 expiration_date = ?, manufacturer = ? WHERE item_id = ?",
                array($item['quantity_received'], $item['lot_number'], $item['expiration_date'], 
                      $item['manufacturer'], $item['item_id'])
            );

            // Add to inventory
            if ($item['quantity_received'] > 0) {
                $inventory_id = sqlInsert(
                    "INSERT INTO drug_inventory (drug_id, lot_number, expiration, manufacturer, 
                     on_hand, warehouse_id, vendor_id, cost_per_unit) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    array($order_item['drug_id'], $item['lot_number'], $item['expiration_date'], 
                          $item['manufacturer'], $item['quantity_received'], $this->warehouse_id, 
                          $order['vendor_id'], $order_item['unit_cost'])
                );

                // Log movement
                $this->addMovement($order_item['drug_id'], $inventory_id, 'purchase', 
                    $item['quantity_received'], $order_id, 'vendor_order', 'Vendor order received');
            }
        }

        // Update order status
        sqlStatement(
            "UPDATE vendor_orders SET order_status = 'delivered', actual_delivery_date = CURDATE() 
             WHERE order_id = ?",
            array($order_id)
        );

        return true;
    }

    /**
     * Get inventory alerts
     */
    public function getAlerts($filters = array())
    {
        $sql = "SELECT ia.*, COALESCE(d.name, CONCAT('Deleted Drug #', ia.drug_id)) as drug_name, d.ndc_number, lo.title as warehouse_name 
                FROM inventory_alerts ia 
                LEFT JOIN drugs d ON d.drug_id = ia.drug_id 
                LEFT JOIN list_options lo ON lo.list_id = 'warehouse' AND lo.option_id = ia.warehouse_id 
                WHERE ia.is_active = 1";
        $params = array();

        if (!empty($filters['alert_type'])) {
            $sql .= " AND ia.alert_type = ?";
            $params[] = $filters['alert_type'];
        }

        if (!empty($filters['drug_id'])) {
            $sql .= " AND ia.drug_id = ?";
            $params[] = $filters['drug_id'];
        }

        if (!empty($filters['warehouse_id'])) {
            $sql .= " AND ia.warehouse_id = ?";
            $params[] = $filters['warehouse_id'];
        }

        $sql .= " ORDER BY ia.created_date DESC";

        $alerts = array();
        $result = sqlStatement($sql, $params);
        while ($row = sqlFetchArray($result)) {
            $alerts[] = $row;
        }

        return $alerts;
    }

    /**
     * Acknowledge alert
     */
    public function acknowledgeAlert($alert_id)
    {
        return sqlStatement(
            "UPDATE inventory_alerts SET is_active = 0, acknowledged_date = NOW(), 
             acknowledged_by = ? WHERE alert_id = ?",
            array($this->user_id, $alert_id)
        );
    }

    /**
     * Get inventory movement history
     */
    public function getMovementHistory($filters = array())
    {
        $sql = "SELECT iml.*, COALESCE(d.name, CONCAT('Deleted Drug #', iml.drug_id)) as drug_name, d.ndc_number, lo.title as warehouse_name 
                FROM inventory_movement_log iml 
                LEFT JOIN drugs d ON d.drug_id = iml.drug_id 
                LEFT JOIN list_options lo ON lo.list_id = 'warehouse' AND lo.option_id = iml.warehouse_id 
                WHERE 1=1";
        $params = array();

        if (!empty($filters['drug_id'])) {
            $sql .= " AND iml.drug_id = ?";
            $params[] = $filters['drug_id'];
        }

        if (!empty($filters['warehouse_id'])) {
            $sql .= " AND iml.warehouse_id = ?";
            $params[] = $filters['warehouse_id'];
        }

        if (!empty($filters['movement_type'])) {
            $sql .= " AND iml.movement_type = ?";
            $params[] = $filters['movement_type'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND iml.movement_date >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND iml.movement_date <= ?";
            $params[] = $filters['date_to'];
        }

        $sql .= " ORDER BY iml.movement_date DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . intval($filters['limit']);
        }

        $movements = array();
        $result = sqlStatement($sql, $params);
        while ($row = sqlFetchArray($result)) {
            $movements[] = $row;
        }

        return $movements;
    }

    /**
     * Get vendor order history
     */
    public function getVendorOrders($filters = array())
    {
        $sql = "SELECT vo.*, u.fname, u.lname, u.organization 
                FROM vendor_orders vo 
                JOIN users u ON u.id = vo.vendor_id 
                WHERE 1=1";
        $params = array();

        if (!empty($filters['vendor_id'])) {
            $sql .= " AND vo.vendor_id = ?";
            $params[] = $filters['vendor_id'];
        }

        if (!empty($filters['order_status'])) {
            $sql .= " AND vo.order_status = ?";
            $params[] = $filters['order_status'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND vo.order_date >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND vo.order_date <= ?";
            $params[] = $filters['date_to'];
        }

        $sql .= " ORDER BY vo.order_date DESC";

        $orders = array();
        $result = sqlStatement($sql, $params);
        while ($row = sqlFetchArray($result)) {
            $orders[] = $row;
        }

        return $orders;
    }

    /**
     * Get vendor order details
     */
    public function getVendorOrderDetails($order_id)
    {
        $order = sqlQuery(
            "SELECT vo.*, u.fname, u.lname, u.organization 
             FROM vendor_orders vo 
             JOIN users u ON u.id = vo.vendor_id 
             WHERE vo.order_id = ?",
            array($order_id)
        );

        if (!$order) {
            return null;
        }

        $items = array();
        $result = sqlStatement(
            "SELECT voi.*, d.name as drug_name, d.ndc_number 
             FROM vendor_order_items voi 
             JOIN drugs d ON d.drug_id = voi.drug_id 
             WHERE voi.order_id = ?",
            array($order_id)
        );

        while ($row = sqlFetchArray($result)) {
            $items[] = $row;
        }

        $order['items'] = $items;
        return $order;
    }
} 