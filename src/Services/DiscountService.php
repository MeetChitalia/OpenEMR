<?php

namespace OpenEMR\Services;

use OpenEMR\Common\Database\QueryUtils;

/**
 * Service class for managing discounts
 */
class DiscountService
{
    /**
     * Get all active discounts for a specific date
     * @param string $date Date in Y-m-d format
     * @return array Array of active discounts
     */
    public function getActiveDiscounts($date = null)
    {
        if ($date === null) {
            $date = date('Y-m-d');
        }

        $sql = "SELECT * FROM discounts 
                WHERE is_active = 1 
                AND start_date <= ? 
                AND (end_date IS NULL OR end_date >= ?)
                ORDER BY start_date DESC, name ASC";

        $result = QueryUtils::fetchRecords($sql, [$date, $date]);
        
        // Process and format the results
        $discounts = [];
        foreach ($result as $row) {
            $discounts[] = $this->formatDiscount($row);
        }
        
        return $discounts;
    }

    /**
     * Get all discounts (active and future) for display
     * @return array Array of all discounts
     */
    public function getAllDiscounts()
    {
        $sql = "SELECT * FROM discounts 
                WHERE is_active = 1 
                ORDER BY start_date DESC, name ASC";

        $result = QueryUtils::fetchRecords($sql);
        
        $discounts = [];
        foreach ($result as $row) {
            $discounts[] = $this->formatDiscount($row);
        }
        
        return $discounts;
    }

    /**
     * Get future discounts that are enabled but not yet active
     * @return array Array of future discounts
     */
    public function getFutureDiscounts()
    {
        $currentDate = date('Y-m-d');
        
        $sql = "SELECT * FROM discounts 
                WHERE is_active = 1 
                AND start_date > ? 
                ORDER BY start_date ASC, name ASC";

        $result = QueryUtils::fetchRecords($sql, [$currentDate]);
        
        $discounts = [];
        foreach ($result as $row) {
            $discounts[] = $this->formatDiscount($row);
        }
        
        return $discounts;
    }

    /**
     * Get applicable discounts for a specific service and amount
     * @param int $serviceId Service ID (optional)
     * @param float $amount Order amount
     * @return array Array of applicable discounts
     */
    public function getApplicableDiscounts($serviceId = null, $amount = 0)
    {
        $currentDate = date('Y-m-d');
        
        $sql = "SELECT * FROM discounts 
                WHERE is_active = 1 
                AND start_date <= ? 
                AND (end_date IS NULL OR end_date >= ?)
                AND minimum_amount <= ?
                AND (usage_limit IS NULL OR used_count < usage_limit)
                ORDER BY discount_value DESC, name ASC";

        $result = QueryUtils::fetchRecords($sql, [$currentDate, $currentDate, $amount]);
        
        $applicableDiscounts = [];
        foreach ($result as $row) {
            // Check if discount applies to this service
            if ($serviceId !== null) {
                $applicableServices = json_decode($row['applicable_services'], true);
                if ($applicableServices !== null && !in_array($serviceId, $applicableServices)) {
                    continue; // Skip if service not in applicable list
                }
            }
            
            $applicableDiscounts[] = $this->formatDiscount($row);
        }
        
        return $applicableDiscounts;
    }

    /**
     * Calculate discount amount for a given discount and order amount
     * @param array $discount Discount array
     * @param float $amount Order amount
     * @return float Discount amount
     */
    public function calculateDiscountAmount($discount, $amount)
    {
        if ($discount['discount_type'] === 'percentage') {
            $discountAmount = ($amount * $discount['discount_value']) / 100;
        } else {
            $discountAmount = $discount['discount_value'];
        }
        
        // Apply maximum discount limit if set
        if ($discount['maximum_discount'] !== null && $discountAmount > $discount['maximum_discount']) {
            $discountAmount = $discount['maximum_discount'];
        }
        
        return round($discountAmount, 2);
    }

    /**
     * Apply a discount to a billing record
     * @param int $discountId Discount ID
     * @param int $patientId Patient ID
     * @param int $billingId Billing ID
     * @param float $amountSaved Amount saved
     * @param int $appliedBy User ID who applied the discount
     * @return bool Success status
     */
    public function applyDiscount($discountId, $patientId, $billingId, $amountSaved, $appliedBy = null)
    {
        try {
            // Record the usage
            $sql = "INSERT INTO discount_usage (discount_id, patient_id, billing_id, amount_saved, applied_by) 
                    VALUES (?, ?, ?, ?, ?)";
            QueryUtils::sqlStatementThrowException($sql, [$discountId, $patientId, $billingId, $amountSaved, $appliedBy]);
            
            // Update usage count
            $sql = "UPDATE discounts SET used_count = used_count + 1 WHERE id = ?";
            QueryUtils::sqlStatementThrowException($sql, [$discountId]);
            
            return true;
        } catch (\Exception $e) {
            error_log("Error applying discount: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a new discount
     * @param array $data Discount data
     * @param int $createdBy User ID who created the discount
     * @return int|false New discount ID or false on failure
     */
    public function createDiscount($data, $createdBy = null)
    {
        try {
            $sql = "INSERT INTO discounts (name, description, discount_type, discount_value, start_date, end_date, 
                    is_active, is_automatic, applicable_services, minimum_amount, maximum_discount, usage_limit, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $params = [
                $data['name'],
                $data['description'] ?? '',
                $data['discount_type'],
                $data['discount_value'],
                $data['start_date'],
                $data['end_date'] ?? null,
                $data['is_active'] ?? 1,
                $data['is_automatic'] ?? 0,
                $data['applicable_services'] ?? null,
                $data['minimum_amount'] ?? 0.00,
                $data['maximum_discount'] ?? null,
                $data['usage_limit'] ?? null,
                $createdBy
            ];
            
            $result = QueryUtils::sqlInsert($sql, $params);
            return $result;
        } catch (\Exception $e) {
            error_log("Error creating discount: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update an existing discount
     * @param int $discountId Discount ID
     * @param array $data Updated discount data
     * @param int $modifiedBy User ID who modified the discount
     * @return bool Success status
     */
    public function updateDiscount($discountId, $data, $modifiedBy = null)
    {
        try {
            $sql = "UPDATE discounts SET 
                    name = ?, description = ?, discount_type = ?, discount_value = ?, 
                    start_date = ?, end_date = ?, is_active = ?, is_automatic = ?, 
                    applicable_services = ?, minimum_amount = ?, maximum_discount = ?, 
                    usage_limit = ?, modified_by = ? 
                    WHERE id = ?";
            
            $params = [
                $data['name'],
                $data['description'] ?? '',
                $data['discount_type'],
                $data['discount_value'],
                $data['start_date'],
                $data['end_date'] ?? null,
                $data['is_active'] ?? 1,
                $data['is_automatic'] ?? 0,
                $data['applicable_services'] ?? null,
                $data['minimum_amount'] ?? 0.00,
                $data['maximum_discount'] ?? null,
                $data['usage_limit'] ?? null,
                $modifiedBy,
                $discountId
            ];
            
            QueryUtils::sqlStatementThrowException($sql, $params);
            return true;
        } catch (\Exception $e) {
            error_log("Error updating discount: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a discount
     * @param int $discountId Discount ID
     * @return bool Success status
     */
    public function deleteDiscount($discountId)
    {
        try {
            $sql = "DELETE FROM discounts WHERE id = ?";
            QueryUtils::sqlStatementThrowException($sql, [$discountId]);
            return true;
        } catch (\Exception $e) {
            error_log("Error deleting discount: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Format discount data for display
     * @param array $row Raw database row
     * @return array Formatted discount data
     */
    private function formatDiscount($row)
    {
        $currentDate = date('Y-m-d');
        $startDate = $row['start_date'];
        $endDate = $row['end_date'];
        
        // Determine status
        $status = 'active';
        if ($startDate > $currentDate) {
            $status = 'future';
        } elseif ($endDate !== null && $endDate < $currentDate) {
            $status = 'expired';
        }
        
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'discount_type' => $row['discount_type'],
            'discount_value' => $row['discount_value'],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'is_active' => $row['is_active'],
            'is_automatic' => $row['is_automatic'],
            'applicable_services' => json_decode($row['applicable_services'], true),
            'minimum_amount' => $row['minimum_amount'],
            'maximum_discount' => $row['maximum_discount'],
            'usage_limit' => $row['usage_limit'],
            'used_count' => $row['used_count'],
            'status' => $status,
            'created_date' => $row['created_date'],
            'modified_date' => $row['modified_date']
        ];
    }

    /**
     * Get discount usage statistics
     * @param int $discountId Discount ID (optional)
     * @return array Usage statistics
     */
    public function getUsageStatistics($discountId = null)
    {
        if ($discountId) {
            $sql = "SELECT COUNT(*) as total_usage, SUM(amount_saved) as total_saved 
                    FROM discount_usage WHERE discount_id = ?";
            $result = QueryUtils::fetchRecords($sql, [$discountId]);
            return $result[0] ?? ['total_usage' => 0, 'total_saved' => 0];
        } else {
            $sql = "SELECT COUNT(*) as total_usage, SUM(amount_saved) as total_saved 
                    FROM discount_usage";
            $result = QueryUtils::fetchRecords($sql);
            return $result[0] ?? ['total_usage' => 0, 'total_saved' => 0];
        }
    }
} 