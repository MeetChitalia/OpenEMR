<?php
/**
 * Multi-Clinic Inventory Management Class
 * 
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Your Name
 * @copyright Copyright (c) 2024
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

class MultiClinicInventory
{
    private $user_id;
    private $facility_id;
    private $warehouse_id;
    private $is_owner;
    private $user_facilities;

    public function __construct($user_id = null, $facility_id = null, $warehouse_id = null)
    {
        $this->user_id = $user_id ?: $_SESSION['authUserID'];
        $this->facility_id = $facility_id;
        $this->warehouse_id = $warehouse_id;
        $this->is_owner = $this->checkOwnerStatus();
        $this->user_facilities = $this->getUserFacilities();
    }

    /**
     * Check if user is owner (has access to all facilities)
     */
    private function checkOwnerStatus()
    {
        // Check if user has super admin privileges or access to all facilities
        $facilities = $this->getUserFacilities();
        $total_facilities = sqlQuery("SELECT COUNT(*) as count FROM facility WHERE billing_location = 1")['count'];
        
        return count($facilities) >= $total_facilities || AclMain::aclCheckCore('admin', 'super');
    }

    /**
     * Get facilities user has access to
     */
    private function getUserFacilities()
    {
        $facilities = array();
        
        if ($this->is_owner) {
            // Owner can see all facilities - but limit to real data (10 clinics + central)
            $result = sqlStatement("SELECT id, name FROM facility WHERE billing_location = 1 ORDER BY name");
            $count = 0;
            $max_clinics = 10; // Limit to real 10 clinics
            
            while ($row = sqlFetchArray($result)) {
                // Always include central facility (ID 1 or contains 'central' in name)
                if ($row['id'] == 1 || strpos(strtolower($row['name']), 'central') !== false) {
                    $facilities[$row['id']] = $row['name'];
                } 
                // Include only first 10 clinic facilities
                elseif ($count < $max_clinics) {
                    $facilities[$row['id']] = $row['name'];
                    $count++;
                }
            }
        } else {
            // Regular user - get their assigned facilities (limited to real data)
            $result = sqlStatement(
                "SELECT DISTINCT f.id, f.name FROM facility f 
                 JOIN users_facility uf ON f.id = uf.facility_id 
                 WHERE uf.tablename = 'users' AND uf.table_id = ? AND f.billing_location = 1
                 ORDER BY f.name",
                array($this->user_id)
            );
            $count = 0;
            $max_clinics = 10;
            
            while ($row = sqlFetchArray($result)) {
                // Always include central facility
                if ($row['id'] == 1 || strpos(strtolower($row['name']), 'central') !== false) {
                    $facilities[$row['id']] = $row['name'];
                } 
                // Include only first 10 clinic facilities
                elseif ($count < $max_clinics) {
                    $facilities[$row['id']] = $row['name'];
                    $count++;
                }
            }
        }
        
        return $facilities;
    }

    /**
     * Get clinic inventory summary
     */
    public function getClinicInventorySummary($facility_id = null)
    {
        $facility_id = $facility_id ?: $this->facility_id;
        
        if (!$this->canAccessFacility($facility_id)) {
            return false;
        }

        $sql = "SELECT 
                    d.drug_id,
                    d.name as drug_name,
                    d.ndc_number,
                    SUM(di.on_hand) as total_on_hand,
                    COUNT(DISTINCT di.inventory_id) as lot_count,
                    AVG(di.cost_per_unit) as avg_cost,
                    SUM(di.on_hand * COALESCE(di.cost_per_unit, 0)) as total_value,
                    MIN(di.expiration) as earliest_expiration,
                    COUNT(CASE WHEN di.expiration <= DATE_ADD(NOW(), INTERVAL 90 DAY) THEN 1 END) as expiring_lots
                FROM drugs d
                LEFT JOIN drug_inventory di ON d.drug_id = di.drug_id 
                    AND di.destroy_date IS NULL 
                    AND di.on_hand > 0
                LEFT JOIN list_options lo ON lo.list_id = 'warehouse' 
                    AND lo.option_id = di.warehouse_id 
                    AND lo.activity = 1
                WHERE d.active = 1";

        $params = array();
        if ($facility_id) {
            $sql .= " AND lo.option_value = ?";
            $params[] = $facility_id;
        }

        $sql .= " GROUP BY d.drug_id, d.name, d.ndc_number ORDER BY d.name";

        $summary = array();
        $result = sqlStatement($sql, $params);
        while ($row = sqlFetchArray($result)) {
            $summary[] = $row;
        }

        return $summary;
    }

    /**
     * Get central inventory overview (owner only)
     */
    public function getCentralInventoryOverview()
    {
        if (!$this->is_owner) {
            return false;
        }

        $overview = array();
        
        // Get total inventory across all clinics
        $sql = "SELECT 
                    ci.drug_id,
                    d.name as drug_name,
                    d.ndc_number,
                    ci.total_quantity,
                    ci.allocated_quantity,
                    ci.available_quantity,
                    ci.last_updated
                FROM central_inventory ci
                JOIN drugs d ON d.drug_id = ci.drug_id
                WHERE d.active = 1
                ORDER BY d.name";

        $result = sqlStatement($sql);
        while ($row = sqlFetchArray($result)) {
            $overview[] = $row;
        }

        return $overview;
    }

    /**
     * Create inter-clinic transfer request
     */
    public function createTransferRequest($from_facility_id, $to_facility_id, $items, $transfer_type = 'scheduled', $priority = 'normal', $notes = null)
    {
        // Validate access
        if (!$this->canAccessFacility($from_facility_id) || !$this->canAccessFacility($to_facility_id)) {
            return false;
        }

        // Get warehouse IDs for facilities
        $from_warehouse = $this->getDefaultWarehouse($from_facility_id);
        $to_warehouse = $this->getDefaultWarehouse($to_facility_id);

        if (!$from_warehouse || !$to_warehouse) {
            return false;
        }

        // Create transfer record
        $transfer_id = sqlInsert(
            "INSERT INTO clinic_transfers (
                from_facility_id, to_facility_id, from_warehouse_id, to_warehouse_id,
                transfer_date, transfer_type, priority, notes, requested_by
            ) VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?)",
            array($from_facility_id, $to_facility_id, $from_warehouse, $to_warehouse,
                  $transfer_type, $priority, $notes, $this->user_id)
        );

        // Add transfer items
        foreach ($items as $item) {
            sqlStatement(
                "INSERT INTO clinic_transfer_items (
                    transfer_id, drug_id, quantity_requested, reason, notes
                ) VALUES (?, ?, ?, ?, ?)",
                array($transfer_id, $item['drug_id'], $item['quantity'], 
                      $item['reason'] ?? '', $item['notes'] ?? '')
            );
        }

        return $transfer_id;
    }

    /**
     * Approve transfer request
     */
    public function approveTransfer($transfer_id)
    {
        $transfer = sqlQuery(
            "SELECT * FROM clinic_transfers WHERE transfer_id = ?",
            array($transfer_id)
        );

        if (!$transfer || !$this->canApproveTransfer($transfer)) {
            return false;
        }

        return sqlStatement(
            "UPDATE clinic_transfers SET 
                transfer_status = 'approved', 
                approved_by = ?, 
                updated_date = NOW() 
             WHERE transfer_id = ?",
            array($this->user_id, $transfer_id)
        );
    }

    /**
     * Process transfer shipment
     */
    public function shipTransfer($transfer_id, $shipped_items)
    {
        $transfer = sqlQuery(
            "SELECT * FROM clinic_transfers WHERE transfer_id = ? AND transfer_status = 'approved'",
            array($transfer_id)
        );

        if (!$transfer) {
            return false;
        }

        foreach ($shipped_items as $item) {
            // Update transfer item
            sqlStatement(
                "UPDATE clinic_transfer_items SET 
                    quantity_shipped = ?, 
                    lot_number = ?, 
                    expiration_date = ?, 
                    manufacturer = ?,
                    unit_cost = ?,
                    total_cost = ?
                 WHERE item_id = ?",
                array($item['quantity_shipped'], $item['lot_number'], $item['expiration_date'],
                      $item['manufacturer'], $item['unit_cost'], 
                      $item['quantity_shipped'] * $item['unit_cost'], $item['item_id'])
            );

            // Reduce inventory at source facility
            $this->reduceInventory($transfer['from_facility_id'], $item['drug_id'], 
                                 $item['quantity_shipped'], $item['lot_number']);
        }

        // Update transfer status
        sqlStatement(
            "UPDATE clinic_transfers SET 
                transfer_status = 'shipped', 
                shipped_by = ?, 
                updated_date = NOW() 
             WHERE transfer_id = ?",
            array($this->user_id, $transfer_id)
        );

        return true;
    }

    /**
     * Receive transfer
     */
    public function receiveTransfer($transfer_id, $received_items)
    {
        $transfer = sqlQuery(
            "SELECT * FROM clinic_transfers WHERE transfer_id = ? AND transfer_status = 'shipped'",
            array($transfer_id)
        );

        if (!$transfer) {
            return false;
        }

        foreach ($received_items as $item) {
            // Update transfer item
            sqlStatement(
                "UPDATE clinic_transfer_items SET quantity_received = ? WHERE item_id = ?",
                array($item['quantity_received'], $item['item_id'])
            );

            // Add inventory to destination facility
            $this->addInventory($transfer['to_facility_id'], $item['drug_id'], 
                              $item['quantity_received'], $item['lot_number'], 
                              $item['expiration_date'], $item['manufacturer'], 
                              $item['unit_cost']);
        }

        // Update transfer status
        sqlStatement(
            "UPDATE clinic_transfers SET 
                transfer_status = 'delivered', 
                actual_delivery_date = CURDATE(),
                received_by = ?, 
                updated_date = NOW() 
             WHERE transfer_id = ?",
            array($this->user_id, $transfer_id)
        );

        return true;
    }

    /**
     * Create inventory request
     */
    public function createInventoryRequest($facility_id, $request_type, $items, $priority = 'normal', $notes = null)
    {
        if (!$this->canAccessFacility($facility_id)) {
            return false;
        }

        // Create request record
        $request_id = sqlInsert(
            "INSERT INTO clinic_inventory_requests (
                facility_id, request_type, request_date, requested_by, priority, notes
            ) VALUES (?, ?, CURDATE(), ?, ?, ?)",
            array($facility_id, $request_type, $this->user_id, $priority, $notes)
        );

        // Add request items
        foreach ($items as $item) {
            sqlStatement(
                "INSERT INTO clinic_request_items (
                    request_id, drug_id, quantity_requested, reason, notes
                ) VALUES (?, ?, ?, ?, ?)",
                array($request_id, $item['drug_id'], $item['quantity'], 
                      $item['reason'] ?? '', $item['notes'] ?? '')
            );
        }

        return $request_id;
    }

    /**
     * Get clinic alerts
     */
    public function getClinicAlerts($facility_id = null, $alert_type = null)
    {
        $facility_id = $facility_id ?: $this->facility_id;
        
        if (!$this->canAccessFacility($facility_id)) {
            return array();
        }

        $sql = "SELECT cia.*, d.name as drug_name, d.ndc_number, f.name as facility_name
                FROM clinic_inventory_alerts cia
                JOIN drugs d ON d.drug_id = cia.drug_id
                JOIN facility f ON f.id = cia.facility_id
                WHERE cia.is_active = 1";

        $params = array();
        if ($facility_id) {
            $sql .= " AND cia.facility_id = ?";
            $params[] = $facility_id;
        }

        if ($alert_type) {
            $sql .= " AND cia.alert_type = ?";
            $params[] = $alert_type;
        }

        $sql .= " ORDER BY cia.created_date DESC";

        $alerts = array();
        $result = sqlStatement($sql, $params);
        while ($row = sqlFetchArray($result)) {
            $alerts[] = $row;
        }

        return $alerts;
    }

    /**
     * Create clinic alert
     */
    public function createClinicAlert($facility_id, $drug_id, $alert_type, $message, $threshold_value = null, $current_value = null)
    {
        if (!$this->canAccessFacility($facility_id)) {
            return false;
        }

        // Check if alert already exists
        $existing = sqlQuery(
            "SELECT alert_id FROM clinic_inventory_alerts 
             WHERE facility_id = ? AND drug_id = ? AND alert_type = ? AND is_active = 1",
            array($facility_id, $drug_id, $alert_type)
        );

        if (!$existing) {
            return sqlInsert(
                "INSERT INTO clinic_inventory_alerts (
                    facility_id, drug_id, alert_type, alert_message, 
                    threshold_value, current_value
                ) VALUES (?, ?, ?, ?, ?, ?)",
                array($facility_id, $drug_id, $alert_type, $message, $threshold_value, $current_value)
            );
        }

        return $existing['alert_id'];
    }

    /**
     * Acknowledge clinic alert
     */
    public function acknowledgeClinicAlert($alert_id)
    {
        return sqlStatement(
            "UPDATE clinic_inventory_alerts SET 
                is_active = 0, 
                acknowledged_date = NOW(), 
                acknowledged_by = ? 
             WHERE alert_id = ?",
            array($this->user_id, $alert_id)
        );
    }

    /**
     * Generate central inventory report
     */
    public function generateCentralReport($report_type = 'daily', $facility_id = null, $report_date = null)
    {
        if (!$this->is_owner) {
            return false;
        }

        $report_date = $report_date ?: date('Y-m-d');
        
        // Get report data
        $report_data = array();
        
        if ($facility_id) {
            // Single facility report
            $report_data = $this->getClinicInventorySummary($facility_id);
        } else {
            // Multi-facility report
            foreach ($this->user_facilities as $fac_id => $fac_name) {
                $report_data[$fac_id] = array(
                    'facility_name' => $fac_name,
                    'inventory' => $this->getClinicInventorySummary($fac_id)
                );
            }
        }

        // Calculate summary statistics
        $total_products = 0;
        $total_value = 0;
        $low_stock_count = 0;
        $expiring_count = 0;
        $transfer_count = 0;
        $request_count = 0;

        // Count transfers and requests
        $transfer_count = sqlQuery(
            "SELECT COUNT(*) as count FROM clinic_transfers WHERE transfer_date = ?",
            array($report_date)
        )['count'];

        $request_count = sqlQuery(
            "SELECT COUNT(*) as count FROM clinic_inventory_requests WHERE request_date = ?",
            array($report_date)
        )['count'];

        // Insert report record
        $report_id = sqlInsert(
            "INSERT INTO central_inventory_reports (
                report_type, report_date, facility_id, total_products, total_value,
                low_stock_count, expiring_count, transfer_count, request_count,
                report_data, generated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            array($report_type, $report_date, $facility_id, $total_products, $total_value,
                  $low_stock_count, $expiring_count, $transfer_count, $request_count,
                  json_encode($report_data), $this->user_id)
        );

        return $report_id;
    }

    /**
     * Get transfer history
     */
    public function getTransferHistory($filters = array())
    {
        $sql = "SELECT ct.*, 
                       f1.name as from_facility_name, 
                       f2.name as to_facility_name,
                       lo1.title as from_warehouse_name,
                       lo2.title as to_warehouse_name
                FROM clinic_transfers ct
                JOIN facility f1 ON f1.id = ct.from_facility_id
                JOIN facility f2 ON f2.id = ct.to_facility_id
                LEFT JOIN list_options lo1 ON lo1.list_id = 'warehouse' AND lo1.option_id = ct.from_warehouse_id
                LEFT JOIN list_options lo2 ON lo2.list_id = 'warehouse' AND lo2.option_id = ct.to_warehouse_id
                WHERE 1=1";

        $params = array();

        if (!empty($filters['facility_id'])) {
            $sql .= " AND (ct.from_facility_id = ? OR ct.to_facility_id = ?)";
            $params[] = $filters['facility_id'];
            $params[] = $filters['facility_id'];
        }

        if (!empty($filters['transfer_status'])) {
            $sql .= " AND ct.transfer_status = ?";
            $params[] = $filters['transfer_status'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND ct.transfer_date >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND ct.transfer_date <= ?";
            $params[] = $filters['date_to'];
        }

        $sql .= " ORDER BY ct.transfer_date DESC";

        $transfers = array();
        $result = sqlStatement($sql, $params);
        while ($row = sqlFetchArray($result)) {
            $transfers[] = $row;
        }

        return $transfers;
    }

    /**
     * Get transfer details
     */
    public function getTransferDetails($transfer_id)
    {
        $transfer = sqlQuery(
            "SELECT ct.*, 
                    f1.name as from_facility_name, 
                    f2.name as to_facility_name
             FROM clinic_transfers ct
             JOIN facility f1 ON f1.id = ct.from_facility_id
             JOIN facility f2 ON f2.id = ct.to_facility_id
             WHERE ct.transfer_id = ?",
            array($transfer_id)
        );

        if (!$transfer) {
            return null;
        }

        // Get transfer items
        $items = array();
        $result = sqlStatement(
            "SELECT cti.*, d.name as drug_name, d.ndc_number
             FROM clinic_transfer_items cti
             JOIN drugs d ON d.drug_id = cti.drug_id
             WHERE cti.transfer_id = ?",
            array($transfer_id)
        );

        while ($row = sqlFetchArray($result)) {
            $items[] = $row;
        }

        $transfer['items'] = $items;
        return $transfer;
    }

    /**
     * Check if user can access facility
     */
    private function canAccessFacility($facility_id)
    {
        return $this->is_owner || isset($this->user_facilities[$facility_id]);
    }

    /**
     * Check if user can approve transfer
     */
    private function canApproveTransfer($transfer)
    {
        // Owner can approve any transfer
        if ($this->is_owner) {
            return true;
        }

        // Facility managers can approve transfers from their facility
        return $this->canAccessFacility($transfer['from_facility_id']);
    }

    /**
     * Get default warehouse for facility
     */
    private function getDefaultWarehouse($facility_id)
    {
        $result = sqlQuery(
            "SELECT option_id FROM list_options 
             WHERE list_id = 'warehouse' AND option_value = ? AND activity = 1 
             ORDER BY seq, title LIMIT 1",
            array($facility_id)
        );

        return $result ? $result['option_id'] : null;
    }

    /**
     * Reduce inventory at source facility
     */
    private function reduceInventory($facility_id, $drug_id, $quantity, $lot_number)
    {
        $sql = "UPDATE drug_inventory di
                JOIN list_options lo ON lo.list_id = 'warehouse' 
                    AND lo.option_id = di.warehouse_id 
                    AND lo.activity = 1
                SET di.on_hand = di.on_hand - ?
                WHERE di.drug_id = ? 
                    AND lo.option_value = ?
                    AND di.lot_number = ?
                    AND di.on_hand >= ?
                    AND di.destroy_date IS NULL";

        return sqlStatement($sql, array($quantity, $drug_id, $facility_id, $lot_number, $quantity));
    }

    /**
     * Add inventory to destination facility
     */
    private function addInventory($facility_id, $drug_id, $quantity, $lot_number, $expiration_date, $manufacturer, $unit_cost)
    {
        $warehouse_id = $this->getDefaultWarehouse($facility_id);
        
        if (!$warehouse_id) {
            return false;
        }

        return sqlInsert(
            "INSERT INTO drug_inventory (
                drug_id, lot_number, expiration, manufacturer, on_hand, 
                warehouse_id, facility_id, cost_per_unit
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            array($drug_id, $lot_number, $expiration_date, $manufacturer, $quantity, 
                  $warehouse_id, $facility_id, $unit_cost)
        );
    }

    /**
     * Get clinic settings
     */
    public function getClinicSetting($facility_id, $setting_name, $default = null)
    {
        $result = sqlQuery(
            "SELECT setting_value FROM clinic_inventory_settings 
             WHERE facility_id = ? AND setting_name = ?",
            array($facility_id, $setting_name)
        );

        return $result ? $result['setting_value'] : $default;
    }

    /**
     * Set clinic setting
     */
    public function setClinicSetting($facility_id, $setting_name, $setting_value, $description = null)
    {
        return sqlStatement(
            "INSERT INTO clinic_inventory_settings (facility_id, setting_name, setting_value, setting_description) 
             VALUES (?, ?, ?, ?) 
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_description = VALUES(setting_description)",
            array($facility_id, $setting_name, $setting_value, $description)
        );
    }

    /**
     * Get full inventory details for a specific facility, including price
     */
    public function getInventory($facility_id = null, $category_id = null, $search_term = null)
    {
        $facility_id = $facility_id ?: $this->facility_id;

        if (!$this->canAccessFacility($facility_id)) {
            return [];
        }

        $sql = "SELECT 
                    d.id as id,
                    d.name as product_name,
                    d.unit,
                    d.price,
                    inv.quantity,
                    inv.expiry_date,
                    inv.warehouse_location,
                    inv.manufacturer,
                    inv.lot_number,
                    inv.expiry_date,
                    cat.name as category_name
                FROM drugs d
                JOIN inventory inv ON d.id = inv.product_id
                JOIN drug_categories cat ON d.category_id = cat.id
                WHERE inv.facility_id = ? AND d.active = 1";
        
        $params = [$facility_id];

        if (!empty($category_id)) {
            $sql .= " AND d.category_id = ?";
            $params[] = $category_id;
        }

        if (!empty($search_term)) {
            $sql .= " AND d.name LIKE ?";
            $params[] = '%' . $search_term . '%';
        }

        $sql .= " ORDER BY d.name";

        $inventory = [];
        $result = sqlStatement($sql, $params);
        while ($row = sqlFetchArray($result)) {
            $inventory[] = $row;
        }

        return $inventory;
    }

    /**
     * Get summary stats for a given warehouse/facility
     */
    public function getWarehouseSummary($facility_id)
    {
        if (!$this->canAccessFacility($facility_id)) {
            return null;
        }

        // Define thresholds for low and critical stock
        $low_stock_threshold = 10;
        $critical_stock_threshold = 5;

        $sql = "SELECT
                    COUNT(id) as total_items,
                    SUM(CASE WHEN quantity <= ? THEN 1 ELSE 0 END) as low_stock_count,
                    SUM(CASE WHEN quantity <= ? THEN 1 ELSE 0 END) as critical_stock_count
                FROM inventory
                WHERE facility_id = ?";

        $params = [$low_stock_threshold, $critical_stock_threshold, $facility_id];

        $result = sqlStatement($sql, $params);
        $summary = sqlFetchArray($result);

        return $summary;
    }

    /**
     * Get inventory items for the main table
     */
    public function getInventoryItems($filters = array())
    {
        $search = $filters['search'] ?? '';
        $product_type = $filters['product_type'] ?? '';
        $facility = $filters['facility'] ?? '';
        $warehouse = $filters['warehouse'] ?? '';
        $page = $filters['page'] ?? 1;
        $per_page = $filters['per_page'] ?? 10;
        
        $offset = ($page - 1) * $per_page;
        
        $sql = "SELECT 
                    d.drug_id as id,
                    d.name,
                    d.active as act,
                    d.consumable as cons,
                    d.ndc_number as ndc,
                    d.form,
                    d.size,
                    d.unit,
                    '' as tran,
                    '' as lot,
                    '' as facility,
                    '' as warehouse,
                    COALESCE(SUM(di.on_hand), 0) as ooh,
                    '' as expiry
                FROM drugs d
                LEFT JOIN drug_inventory di ON d.drug_id = di.drug_id 
                    AND di.destroy_date IS NULL
                WHERE 1=1";
        
        $params = array();
        
        if ($search) {
            $sql .= " AND (d.name LIKE ? OR d.ndc_number LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($product_type) {
            // Add product type filtering if needed
        }
        
        $sql .= " GROUP BY d.drug_id, d.name, d.active, d.consumable, d.ndc_number, d.form, d.size, d.unit";
        $sql .= " ORDER BY d.name";
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $per_page;
        $params[] = $offset;
        
        $items = array();
        $result = sqlStatement($sql, $params);
        while ($row = sqlFetchArray($result)) {
            $items[] = $row;
        }
        
        return $items;
    }
    
    /**
     * Get total count of inventory items
     */
    public function getInventoryCount($filters = array())
    {
        $search = $filters['search'] ?? '';
        
        $sql = "SELECT COUNT(DISTINCT d.drug_id) as count FROM drugs d WHERE 1=1";
        $params = array();
        
        if ($search) {
            $sql .= " AND (d.name LIKE ? OR d.ndc_number LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        $result = sqlQuery($sql, $params);
        return $result['count'];
    }
    
    /**
     * Get product types for filter
     */
    public function getProductTypes()
    {
        return array(
            array('id' => 'drugs', 'name' => 'Drugs'),
            array('id' => 'supplies', 'name' => 'Supplies'),
            array('id' => 'equipment', 'name' => 'Equipment')
        );
    }
    
    /**
     * Get warehouses for filter
     */
    public function getWarehouses()
    {
        $warehouses = array();
        $result = sqlStatement("SELECT option_id as id, title as name FROM list_options WHERE list_id = 'warehouse' AND activity = 1 ORDER BY title");
        while ($row = sqlFetchArray($result)) {
            $warehouses[] = $row;
        }
        return $warehouses;
    }
    
    /**
     * Get inventory item by ID
     */
    public function getInventoryItem($id)
    {
        $sql = "SELECT 
                    d.drug_id as id,
                    d.name,
                    d.active as act,
                    d.consumable as cons,
                    d.ndc_number as ndc,
                    d.form,
                    d.size,
                    d.unit,
                    '' as tran,
                    '' as lot,
                    '' as facility,
                    '' as warehouse,
                    COALESCE(SUM(di.on_hand), 0) as ooh,
                    '' as expiry
                FROM drugs d
                LEFT JOIN drug_inventory di ON d.drug_id = di.drug_id 
                    AND di.destroy_date IS NULL
                WHERE d.drug_id = ?
                GROUP BY d.drug_id, d.name, d.active, d.consumable, d.ndc_number, d.form, d.size, d.unit";
        
        return sqlQuery($sql, array($id));
    }
    
    /**
     * Update inventory item
     */
    public function updateInventoryItem($id, $fields)
    {
        // This would update the drug record
        // For now, return true as placeholder
        return true;
    }
    
    /**
     * Delete inventory item
     */
    public function deleteInventoryItem($id)
    {
        // This would delete the drug record
        // For now, return true as placeholder
        return true;
    }
    
    /**
     * Add inventory item
     */
    public function addInventoryItem($fields)
    {
        // This would add a new drug record
        // For now, return true as placeholder
        return true;
    }
    
    /**
     * Add inventory lot
     */
    public function addInventoryLot($fields)
    {
        // This would add a new lot record
        // For now, return true as placeholder
        return true;
    }
}