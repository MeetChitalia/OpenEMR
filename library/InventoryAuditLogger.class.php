<?php

/**
 * Inventory Audit Logger - Comprehensive audit trail for inventory movements
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    OpenEMR Team
 * @copyright Copyright (c) 2024 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

class InventoryAuditLogger
{
    private $user_id;
    private $facility_id;
    private $audit_enabled;
    private $log_level;

    public function __construct($user_id = null, $facility_id = null)
    {
        $this->user_id = $user_id ?: ($_SESSION['authUserID'] ?? 0);
        $this->facility_id = $facility_id ?: ($_SESSION['facility_id'] ?? 0);
        $this->audit_enabled = $this->getSetting('audit_trail_enabled', '1') == '1';
        $this->log_level = $this->getSetting('audit_log_level', 'standard');
    }

    /**
     * Get audit setting value
     */
    public function getSetting($setting_name, $default = null)
    {
        // Check if inventory_settings table exists
        $table_exists = sqlQuery("SHOW TABLES LIKE 'inventory_settings'");
        if (!$table_exists) {
            return $default;
        }

        $sql = "SELECT setting_value FROM inventory_settings WHERE setting_name = ? AND (is_global = 1 OR facility_id = ?)";
        $result = sqlQuery($sql, array($setting_name, $this->facility_id));
        
        // Handle both array and ADORecordSet results
        if ($result && is_array($result) && isset($result['setting_value'])) {
            return $result['setting_value'];
        } elseif ($result && is_object($result) && method_exists($result, 'fields')) {
            // Handle ADORecordSet object
            $fields = $result->fields;
            return isset($fields['setting_value']) ? $fields['setting_value'] : $default;
        }
        
        return $default;
    }

    /**
     * Log inventory movement with comprehensive audit trail
     */
    public function logMovement($drug_id, $inventory_id, $movement_type, $quantity_change, $reference_id = null, $reference_type = null, $reason = null, $notes = null, $additional_data = array())
    {
        if (!$this->audit_enabled) {
            return true;
        }

        // Get current inventory state
        $inventory = sqlQuery(
            "SELECT di.*, d.name as drug_name, d.ndc_number 
             FROM drug_inventory di 
             JOIN drugs d ON d.drug_id = di.drug_id 
             WHERE di.inventory_id = ?",
            array($inventory_id)
        );

        // Convert ADORecordSet to array if needed
        $inventory = $this->getArrayResult($inventory);

        if (!$inventory) {
            return false;
        }

        $quantity_before = $inventory['on_hand'];
        $quantity_after = $quantity_before + $quantity_change;

        // Calculate unit cost and total value
        $unit_cost = $inventory['cost_per_unit'] ?: 0;
        $total_value = $unit_cost * abs($quantity_change);

        // Determine if this is a cost-impacting movement
        $cost_impact = $this->hasCostImpact($movement_type);

        // Get IP address
        $ip_address = $this->getClientIP();

        // Prepare log data
        $log_data = array(
            'drug_id' => $drug_id,
            'inventory_id' => $inventory_id,
            'warehouse_id' => $inventory['warehouse_id'],
            'movement_type' => $movement_type,
            'quantity_before' => $quantity_before,
            'quantity_change' => $quantity_change,
            'quantity_after' => $quantity_after,
            'reference_id' => $reference_id,
            'reference_type' => $reference_type,
            'lot_number' => $inventory['lot_number'],
            'expiration_date' => $inventory['expiration'],
            'unit_cost' => $cost_impact ? $unit_cost : null,
            'total_value' => $cost_impact ? $total_value : null,
            'reason' => $reason,
            'notes' => $notes,
            'user_id' => $this->user_id,
            'ip_address' => $ip_address,
            'movement_date' => date('Y-m-d H:i:s')
        );

        // Add additional data based on log level
        if ($this->log_level == 'detailed') {
            $log_data = array_merge($log_data, $additional_data);
        }

        // Insert audit log record
        $sql = "INSERT INTO inventory_movement_log (
                    drug_id, inventory_id, warehouse_id, movement_type,
                    quantity_before, quantity_change, quantity_after,
                    reference_id, reference_type, lot_number, expiration_date,
                    unit_cost, total_value, reason, notes, user_id, ip_address, movement_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $params = array(
            $log_data['drug_id'], $log_data['inventory_id'], $log_data['warehouse_id'], $log_data['movement_type'],
            $log_data['quantity_before'], $log_data['quantity_change'], $log_data['quantity_after'],
            $log_data['reference_id'], $log_data['reference_type'], $log_data['lot_number'], $log_data['expiration_date'],
            $log_data['unit_cost'], $log_data['total_value'], $log_data['reason'], $log_data['notes'],
            $log_data['user_id'], $log_data['ip_address'], $log_data['movement_date']
        );

        $result = sqlStatement($sql, $params);

        if ($result) {
            // Check for alerts based on movement
            $this->checkMovementAlerts($drug_id, $inventory['warehouse_id'], $movement_type, $quantity_change);
            
            // Send notifications if enabled
            if ($this->getSetting('audit_email_notifications', '0') == '1') {
                $this->sendMovementNotification($log_data);
            }
        }

        return $result;
    }

    /**
     * Helper to always get an array from a SQL result (handles ADORecordSet)
     */
    private function getArrayResult($result) {
        if (is_array($result)) {
            return $result;
        } elseif (is_object($result) && property_exists($result, 'fields')) {
            return $result->fields;
        }
        return null;
    }

    /**
     * Get client IP address
     */
    private function getClientIP() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }

    /**
     * Log inventory activity from existing OpenEMR transaction system
     * This method integrates with the existing drug_sales table transactions
     */
    public function logTransactionFromDrugSales($sale_id, $additional_notes = null)
    {
        if (!$this->audit_enabled) {
            return true;
        }

        // Get the drug_sales transaction
        $sale = sqlQuery(
            "SELECT ds.*, di.lot_number, di.expiration, di.manufacturer, di.warehouse_id,
                    d.name as drug_name, d.ndc_number
             FROM drug_sales ds
             JOIN drug_inventory di ON di.inventory_id = ds.inventory_id
             JOIN drugs d ON d.drug_id = ds.drug_id
             WHERE ds.sale_id = ?",
            array($sale_id)
        );
        $sale = $this->getArrayResult($sale);

        if (!$sale) {
            return false;
        }

        // Map transaction types to movement types
        $movement_type_map = array(
            0 => 'edit_only',
            1 => 'sale',
            2 => 'purchase',
            3 => 'return',
            4 => 'transfer',
            5 => 'adjustment',
            6 => 'distribution',
            7 => 'consumption'
        );

        $movement_type = $movement_type_map[$sale['trans_type']] ?? 'unknown';

        // Calculate quantity change (drug_sales stores negative values for reductions)
        $quantity_change = $sale['quantity'];

        // Get current inventory state for accurate before/after values
        $inventory = sqlQuery(
            "SELECT on_hand FROM drug_inventory WHERE inventory_id = ?",
            array($sale['inventory_id'])
        );
        
        // Convert ADORecordSet to array if needed
        $inventory = $this->getArrayResult($inventory);

        $quantity_before = $inventory['on_hand'] - $quantity_change; // Reverse the change to get before
        $quantity_after = $inventory['on_hand'];

        // Calculate unit cost and total value
        $unit_cost = ($sale['quantity'] != 0) ? abs($sale['fee'] / $sale['quantity']) : 0;
        $total_value = abs($sale['fee']);

        // Prepare log data
        $log_data = array(
            'drug_id' => $sale['drug_id'],
            'inventory_id' => $sale['inventory_id'],
            'warehouse_id' => $sale['warehouse_id'],
            'movement_type' => $movement_type,
            'quantity_before' => $quantity_before,
            'quantity_change' => $quantity_change,
            'quantity_after' => $quantity_after,
            'reference_id' => $sale_id,
            'reference_type' => 'drug_sales',
            'lot_number' => $sale['lot_number'],
            'expiration_date' => $sale['expiration'],
            'unit_cost' => $unit_cost,
            'total_value' => $total_value,
            'reason' => $this->getTransactionReason($sale['trans_type']),
            'notes' => $sale['notes'] . ($additional_notes ? ' | ' . $additional_notes : ''),
            'user_id' => $this->user_id,
            'movement_date' => $sale['sale_date'] . ' ' . date('H:i:s')
        );

        // Insert audit log record
        $sql = "INSERT INTO inventory_movement_log (
                    drug_id, inventory_id, warehouse_id, movement_type,
                    quantity_before, quantity_change, quantity_after,
                    reference_id, reference_type, lot_number, expiration_date,
                    unit_cost, total_value, reason, notes, user_id, movement_date, ip_address
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $params = array(
            $log_data['drug_id'], $log_data['inventory_id'], $log_data['warehouse_id'], $log_data['movement_type'],
            $log_data['quantity_before'], $log_data['quantity_change'], $log_data['quantity_after'],
            $log_data['reference_id'], $log_data['reference_type'], $log_data['lot_number'], $log_data['expiration_date'],
            $log_data['unit_cost'], $log_data['total_value'], $log_data['reason'], $log_data['notes'],
            $log_data['user_id'], $log_data['movement_date'], $this->getClientIP()
        );

        $result = sqlStatement($sql, $params);

        if ($result) {
            // Check for alerts based on movement
            $this->checkMovementAlerts($sale['drug_id'], $sale['warehouse_id'], $movement_type, $quantity_change);
        }

        return $result;
    }

    /**
     * Get human-readable reason for transaction type
     */
    private function getTransactionReason($trans_type)
    {
        $reasons = array(
            0 => 'Edit lot details only',
            1 => 'Patient sale',
            2 => 'Purchase/Receipt from vendor',
            3 => 'Return to vendor',
            4 => 'Transfer between lots/warehouses',
            5 => 'Inventory adjustment',
            6 => 'Distribution to facility',
            7 => 'Internal consumption'
        );

        return $reasons[$trans_type] ?? 'Unknown transaction';
    }

    /**
     * Log lot creation or modification
     */
    public function logLotActivity($drug_id, $inventory_id, $action, $old_data = null, $new_data = null, $notes = null)
    {
        if (!$this->audit_enabled) {
            return true;
        }

        // Get current inventory state
        $inventory = sqlQuery(
            "SELECT di.*, d.name as drug_name, d.ndc_number 
             FROM drug_inventory di 
             JOIN drugs d ON d.drug_id = di.drug_id 
             WHERE di.inventory_id = ?",
            array($inventory_id)
        );
        $inventory = $this->getArrayResult($inventory);

        if (!$inventory) {
            return false;
        }

        // Prepare log data for lot activity
        $log_data = array(
            'drug_id' => $drug_id,
            'inventory_id' => $inventory_id,
            'warehouse_id' => $inventory['warehouse_id'],
            'movement_type' => $action,
            'quantity_before' => $inventory['on_hand'],
            'quantity_change' => 0, // No quantity change for lot modifications
            'quantity_after' => $inventory['on_hand'],
            'reference_id' => null,
            'reference_type' => 'lot_activity',
            'lot_number' => $inventory['lot_number'],
            'expiration_date' => $inventory['expiration'],
            'unit_cost' => null,
            'total_value' => null,
            'reason' => $this->getLotActivityReason($action, $old_data, $new_data),
            'notes' => $notes,
            'user_id' => $this->user_id,
            'movement_date' => date('Y-m-d H:i:s')
        );

        // Insert audit log record
        $sql = "INSERT INTO inventory_movement_log (
                    drug_id, inventory_id, warehouse_id, movement_type,
                    quantity_before, quantity_change, quantity_after,
                    reference_id, reference_type, lot_number, expiration_date,
                    unit_cost, total_value, reason, notes, user_id, movement_date, ip_address
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $params = array(
            $log_data['drug_id'], $log_data['inventory_id'], $log_data['warehouse_id'], $log_data['movement_type'],
            $log_data['quantity_before'], $log_data['quantity_change'], $log_data['quantity_after'],
            $log_data['reference_id'], $log_data['reference_type'], $log_data['lot_number'], $log_data['expiration_date'],
            $log_data['unit_cost'], $log_data['total_value'], $log_data['reason'], $log_data['notes'],
            $log_data['user_id'], $log_data['movement_date'], $this->getClientIP()
        );

        return sqlStatement($sql, $params);
    }

    /**
     * Get human-readable reason for lot activity
     */
    private function getLotActivityReason($action, $old_data, $new_data)
    {
        switch ($action) {
            case 'lot_created':
                return 'New lot created';
            case 'lot_modified':
                return 'Lot details modified';
            case 'lot_destroyed':
                return 'Lot destroyed/expired';
            case 'lot_transferred':
                return 'Lot transferred';
            default:
                return 'Lot activity: ' . $action;
        }
    }

    /**
     * Determine if movement type has cost impact
     */
    private function hasCostImpact($movement_type)
    {
        $cost_impact_types = array('purchase', 'sale', 'return');
        return in_array($movement_type, $cost_impact_types);
    }

    /**
     * Check for movement-based alerts
     */
    private function checkMovementAlerts($drug_id, $warehouse_id, $movement_type, $quantity_change)
    {
        // Check for unusual movement patterns
        $alert_threshold = intval($this->getSetting('audit_alert_threshold', '100'));
        
        // Get recent movements for this drug
        $sql = "SELECT COUNT(*) as recent_movements 
                FROM inventory_movement_log 
                WHERE drug_id = ? AND movement_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
        $result = sqlQuery($sql, array($drug_id));
        
        // Convert ADORecordSet to array if needed
        $result = $this->getArrayResult($result);
        
        if ($result && $result['recent_movements'] > $alert_threshold) {
            $this->createAlert($drug_id, $warehouse_id, 'high_activity', 
                "High activity detected for drug ID $drug_id: {$result['recent_movements']} movements in 24 hours");
        }

        // Check for large quantity changes
        $large_quantity_threshold = 1000; // Configurable
        if (abs($quantity_change) > $large_quantity_threshold) {
            $this->createAlert($drug_id, $warehouse_id, 'large_movement', 
                "Large quantity movement detected: $quantity_change units for drug ID $drug_id");
        }
    }

    /**
     * Create alert record
     */
    private function createAlert($drug_id, $warehouse_id, $alert_type, $message, $threshold_value = null, $current_value = null)
    {
        $sql = "INSERT INTO inventory_alerts (
                    drug_id, warehouse_id, alert_type, message, threshold_value, current_value, 
                    created_date, is_active
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), 1)";
        
        return sqlStatement($sql, array($drug_id, $warehouse_id, $alert_type, $message, $threshold_value, $current_value));
    }

    /**
     * Send movement notification
     */
    private function sendMovementNotification($log_data)
    {
        // This would integrate with OpenEMR's notification system
        // For now, we'll just log the notification
        error_log("Inventory Movement Notification: " . json_encode($log_data));
    }

    /**
     * Get audit log entries with filters
     */
    public function getAuditLog($filters = array())
    {
        $sql = "SELECT iml.*, COALESCE(d.name, CONCAT('Deleted Drug #', iml.drug_id)) as drug_name, d.ndc_number, lo.title as warehouse_name,
                       u.fname as user_fname, u.lname as user_lname
                FROM inventory_movement_log iml
                LEFT JOIN drugs d ON d.drug_id = iml.drug_id
                LEFT JOIN list_options lo ON lo.list_id = 'warehouse' AND lo.option_id = iml.warehouse_id
                LEFT JOIN users u ON u.id = iml.user_id
                WHERE 1=1";
        
        $params = array();

        if (!empty($filters['start_date'])) {
            $sql .= " AND iml.movement_date >= ?";
            $params[] = $filters['start_date'] . ' 00:00:00';
        }

        if (!empty($filters['end_date'])) {
            $sql .= " AND iml.movement_date <= ?";
            $params[] = $filters['end_date'] . ' 23:59:59';
        }

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

        if (!empty($filters['user_id'])) {
            $sql .= " AND iml.user_id = ?";
            $params[] = $filters['user_id'];
        }

        $sql .= " ORDER BY iml.movement_date DESC, iml.log_id DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . intval($filters['limit']);
        }

        $result = sqlStatement($sql, $params);
        
        $logs = array();
        while ($row = sqlFetchArray($result)) {
            $logs[] = $row;
        }

        return $logs;
    }

    /**
     * Get audit statistics
     */
    public function getAuditStatistics($start_date = null, $end_date = null)
    {
        $sql = "SELECT 
                    COUNT(*) as total_movements,
                    COUNT(DISTINCT drug_id) as unique_products,
                    COUNT(DISTINCT user_id) as unique_users,
                    COUNT(DISTINCT warehouse_id) as unique_warehouses,
                    SUM(ABS(quantity_change)) as total_quantity_moved,
                    SUM(total_value) as total_value_impact,
                    AVG(total_value) as avg_value_per_movement
                FROM inventory_movement_log
                WHERE 1=1";
        
        $params = array();

        if ($start_date) {
            $sql .= " AND movement_date >= ?";
            $params[] = $start_date . ' 00:00:00';
        }

        if ($end_date) {
            $sql .= " AND movement_date <= ?";
            $params[] = $end_date . ' 23:59:59';
        }

        return sqlQuery($sql, $params);
    }

    /**
     * Get movement type statistics
     */
    public function getMovementTypeStats($start_date = null, $end_date = null)
    {
        $sql = "SELECT 
                    movement_type,
                    COUNT(*) as movement_count,
                    SUM(ABS(quantity_change)) as total_quantity,
                    SUM(total_value) as total_value,
                    AVG(total_value) as avg_value
                FROM inventory_movement_log
                WHERE 1=1";
        
        $params = array();

        if ($start_date) {
            $sql .= " AND movement_date >= ?";
            $params[] = $start_date . ' 00:00:00';
        }

        if ($end_date) {
            $sql .= " AND movement_date <= ?";
            $params[] = $end_date . ' 23:59:59';
        }

        $sql .= " GROUP BY movement_type ORDER BY movement_count DESC";

        $result = sqlStatement($sql, $params);
        
        $stats = array();
        while ($row = sqlFetchArray($result)) {
            $stats[] = $row;
        }

        return $stats;
    }

    /**
     * Get user activity statistics
     */
    public function getUserActivityStats($start_date = null, $end_date = null)
    {
        $sql = "SELECT 
                    u.id as user_id,
                    u.fname,
                    u.lname,
                    u.username,
                    COUNT(*) as movement_count,
                    SUM(ABS(iml.quantity_change)) as total_quantity_moved,
                    SUM(iml.total_value) as total_value_impact,
                    COUNT(DISTINCT iml.drug_id) as unique_products,
                    MIN(iml.movement_date) as first_activity,
                    MAX(iml.movement_date) as last_activity
                FROM inventory_movement_log iml
                JOIN users u ON u.id = iml.user_id
                WHERE 1=1";
        
        $params = array();

        if ($start_date) {
            $sql .= " AND iml.movement_date >= ?";
            $params[] = $start_date . ' 00:00:00';
        }

        if ($end_date) {
            $sql .= " AND iml.movement_date <= ?";
            $params[] = $end_date . ' 23:59:59';
        }

        $sql .= " GROUP BY u.id, u.fname, u.lname, u.username ORDER BY movement_count DESC";

        $result = sqlStatement($sql, $params);
        
        $stats = array();
        while ($row = sqlFetchArray($result)) {
            $stats[] = $row;
        }

        return $stats;
    }

    /**
     * Clean up old audit records
     */
    public function cleanupOldRecords($retention_days = null)
    {
        if (!$retention_days) {
            $retention_days = intval($this->getSetting('audit_retention_days', '365'));
        }

        // Create backup if enabled
        if ($this->getSetting('audit_backup_enabled', '0') == '1') {
            $this->createAuditBackup();
        }

        // Delete old records
        $sql = "DELETE FROM inventory_movement_log 
                WHERE movement_date < DATE_SUB(NOW(), INTERVAL ? DAY)";
        
        return sqlStatement($sql, array($retention_days));
    }

    /**
     * Create audit backup
     */
    private function createAuditBackup()
    {
        $backup_date = date('Y-m-d_H-i-s');
        $backup_table = "inventory_movement_log_backup_" . $backup_date;
        
        $sql = "CREATE TABLE $backup_table AS SELECT * FROM inventory_movement_log";
        return sqlStatement($sql);
    }

    /**
     * Export audit data
     */
    public function exportAuditData($filters = array(), $format = 'csv')
    {
        $logs = $this->getAuditLog($filters);
        
        if ($format == 'csv') {
            return $this->exportToCSV($logs);
        } elseif ($format == 'json') {
            return json_encode($logs);
        }
        
        return $logs;
    }

    /**
     * Export to CSV format
     */
    private function exportToCSV($logs)
    {
        $csv_data = array();
        
        // Add headers
        $csv_data[] = array(
            'Date/Time', 'Product', 'Lot Number', 'Warehouse', 'Movement Type',
            'Quantity Before', 'Quantity Change', 'Quantity After',
            'Unit Cost', 'Total Value', 'User', 'Reference', 'Reason', 'Notes'
        );
        
        // Add data rows
        foreach ($logs as $log) {
            $csv_data[] = array(
                $log['movement_date'],
                $log['drug_name'],
                $log['lot_number'],
                $log['warehouse_name'],
                $log['movement_type'],
                $log['quantity_before'],
                $log['quantity_change'],
                $log['quantity_after'],
                $log['unit_cost'],
                $log['total_value'],
                $log['user_lname'] . ', ' . $log['user_fname'],
                $log['reference_type'] . ' #' . $log['reference_id'],
                $log['reason'],
                $log['notes']
            );
        }
        
        return $csv_data;
    }

    /**
     * Get audit alerts
     */
    public function getAuditAlerts($filters = array())
    {
        $sql = "SELECT ia.*, d.name as drug_name, lo.title as warehouse_name
                FROM inventory_alerts ia
                JOIN drugs d ON d.drug_id = ia.drug_id
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

        $sql .= " ORDER BY ia.created_date DESC";

        $result = sqlStatement($sql, $params);
        
        $alerts = array();
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
        $sql = "UPDATE inventory_alerts 
                SET is_active = 0, acknowledged_date = NOW(), acknowledged_by = ? 
                WHERE alert_id = ?";
        
        return sqlStatement($sql, array($this->user_id, $alert_id));
    }

    /**
     * Log comprehensive activity with exact form data (prevents duplicate entries)
     */
    public function logComprehensiveActivity($audit_data)
    {
        if (!$this->audit_enabled) {
            return true;
        }

        // Get current inventory state for before/after calculations
        $inventory = null;
        if ($audit_data['inventory_id'] > 0) {
            $inventory = sqlQuery(
                "SELECT di.*, d.name as drug_name, d.ndc_number 
                 FROM drug_inventory di 
                 JOIN drugs d ON d.drug_id = di.drug_id 
                 WHERE di.inventory_id = ?",
                array($audit_data['inventory_id'])
            );
            $inventory = $this->getArrayResult($inventory);
        }

        // Calculate quantities
        $quantity_before = $inventory ? $inventory['on_hand'] : 0;
        $quantity_change = $audit_data['quantity_change'] ?? 0;
        $quantity_after = $quantity_before + $quantity_change;

        // Calculate unit cost and total value
        $unit_cost = $audit_data['cost'] ?? 0;
        $total_value = $unit_cost * abs($quantity_change);

        // Determine if this is a cost-impacting movement
        $cost_impact = $this->hasCostImpact($audit_data['movement_type']);

        // Prepare comprehensive log data
        $log_data = array(
            'drug_id' => $audit_data['drug_id'],
            'inventory_id' => $audit_data['inventory_id'],
            'warehouse_id' => $audit_data['warehouse_id'] ?? ($inventory ? $inventory['warehouse_id'] : ''),
            'movement_type' => $audit_data['movement_type'],
            'quantity_before' => $quantity_before,
            'quantity_change' => $quantity_change,
            'quantity_after' => $quantity_after,
            'reference_id' => $audit_data['reference_id'] ?? null,
            'reference_type' => $audit_data['reference_type'] ?? 'form_submission',
            'lot_number' => $audit_data['lot_number'] ?? ($inventory ? $inventory['lot_number'] : ''),
            'expiration_date' => $audit_data['expiration_date'] ?? ($inventory ? $inventory['expiration'] : null),
            'unit_cost' => $cost_impact ? $unit_cost : null,
            'total_value' => $cost_impact ? $total_value : null,
            'reason' => $this->getComprehensiveReason($audit_data),
            'notes' => $audit_data['notes'] ?? '',
            'user_id' => $this->user_id,
            'movement_date' => date('Y-m-d H:i:s'),
            'additional_data' => json_encode(array(
                'manufacturer' => $audit_data['manufacturer'] ?? '',
                'vendor_id' => $audit_data['vendor_id'] ?? '',
                'supplier_id' => $audit_data['supplier_id'] ?? '',
                'source_lot' => $audit_data['source_lot'] ?? null,
                'transaction_type' => $audit_data['transaction_type'] ?? null,
                'action' => $audit_data['action'] ?? 'unknown'
            ))
        );

        // Insert comprehensive audit log record
        $sql = "INSERT INTO inventory_movement_log (
                    drug_id, inventory_id, warehouse_id, movement_type,
                    quantity_before, quantity_change, quantity_after,
                    reference_id, reference_type, lot_number, expiration_date,
                    unit_cost, total_value, reason, notes, user_id, movement_date, ip_address
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $params = array(
            $log_data['drug_id'], $log_data['inventory_id'], $log_data['warehouse_id'], $log_data['movement_type'],
            $log_data['quantity_before'], $log_data['quantity_change'], $log_data['quantity_after'],
            $log_data['reference_id'], $log_data['reference_type'], $log_data['lot_number'], $log_data['expiration_date'],
            $log_data['unit_cost'], $log_data['total_value'], $log_data['reason'], $log_data['notes'],
            $log_data['user_id'], $log_data['movement_date'], $this->getClientIP()
        );

        $result = sqlStatement($sql, $params);

        if ($result) {
            // Check for alerts based on movement
            $this->checkMovementAlerts($audit_data['drug_id'], $log_data['warehouse_id'], $audit_data['movement_type'], $quantity_change);
        }

        return $result;
    }

    /**
     * Get comprehensive reason for audit activity
     */
    private function getComprehensiveReason($audit_data)
    {
        $action = $audit_data['action'] ?? '';
        $movement_type = $audit_data['movement_type'] ?? '';
        $transaction_type = $audit_data['transaction_type'] ?? '';

        $reasons = array(
            'lot_created' => 'New lot created',
            'lot_updated' => 'Lot details updated',
            'drug_created' => 'New drug created',
            'drug_modified' => 'Drug details modified',
            'drug_deleted' => 'Drug deleted'
        );

        if (isset($reasons[$action])) {
            return $reasons[$action];
        }

        // Fall back to transaction type reason
        $transaction_reasons = array(
            0 => 'Edit lot details only',
            1 => 'Patient sale',
            2 => 'Purchase/Receipt from vendor',
            3 => 'Return to vendor',
            4 => 'Transfer between lots/warehouses',
            5 => 'Inventory adjustment',
            6 => 'Distribution to facility',
            7 => 'Internal consumption'
        );

        return $transaction_reasons[$transaction_type] ?? 'Inventory activity';
    }
} 