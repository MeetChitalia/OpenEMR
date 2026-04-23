<?php
/**
 * Custom Multi-Clinic Inventory Management Class
 *
 * This class is 100% separate from OpenEMR's built-in inventory system.
 *
 * @author    Your Name
 * @copyright Copyright (c) 2024
 */

class CustomInventory
{
    private $user_id;
    private $is_admin;
    private $clinic_id;

    public function __construct($user_id)
    {
        $this->user_id = $user_id;
        $this->is_admin = $this->checkIsAdmin();
        $this->clinic_id = $this->getUserClinic();
    }

    // Check if user is admin in custom system
    private function checkIsAdmin()
    {
        $row = sqlQuery("SELECT role FROM custom_user_clinics WHERE user_id = ? AND is_active = 1 LIMIT 1", array($this->user_id));
        return $row && $row['role'] === 'admin';
    }

    // Get the clinic_id for this user (if not admin)
    private function getUserClinic()
    {
        if ($this->is_admin) return null;
        $row = sqlQuery("SELECT clinic_id FROM custom_user_clinics WHERE user_id = ? AND is_active = 1 LIMIT 1", array($this->user_id));
        return $row ? $row['clinic_id'] : null;
    }

    // Get all clinics (admin only)
    public function getAllClinics()
    {
        if (!$this->is_admin) return [];
        $res = sqlStatement("SELECT * FROM custom_clinics WHERE is_active = 1 ORDER BY clinic_name");
        $clinics = [];
        while ($row = sqlFetchArray($res)) {
            $clinics[] = $row;
        }
        return $clinics;
    }

    // Get clinic info
    public function getClinic($clinic_id)
    {
        return sqlQuery("SELECT * FROM custom_clinics WHERE clinic_id = ?", array($clinic_id));
    }

    // Get all drugs
    public function getAllDrugs()
    {
        $res = sqlStatement("SELECT * FROM custom_drugs WHERE is_active = 1 ORDER BY drug_name");
        $drugs = [];
        while ($row = sqlFetchArray($res)) {
            $drugs[] = $row;
        }
        return $drugs;
    }

    // Get inventory for a clinic
    public function getClinicInventory($clinic_id)
    {
        $sql = "SELECT ci.*, cd.drug_name, cd.ndc_number, cd.manufacturer, cd.dosage_form, cd.strength, cd.unit
                FROM custom_inventory ci
                JOIN custom_drugs cd ON ci.drug_id = cd.drug_id
                WHERE ci.clinic_id = ?
                ORDER BY cd.drug_name, ci.expiration_date";
        $res = sqlStatement($sql, array($clinic_id));
        $inventory = [];
        while ($row = sqlFetchArray($res)) {
            $inventory[] = $row;
        }
        return $inventory;
    }

    // Get central inventory (admin only)
    public function getCentralInventory()
    {
        if (!$this->is_admin) return [];
        $sql = "SELECT cci.*, cd.drug_name, cd.ndc_number, cd.manufacturer, cd.dosage_form, cd.strength, cd.unit
                FROM custom_central_inventory cci
                JOIN custom_drugs cd ON cci.drug_id = cd.drug_id
                ORDER BY cd.drug_name";
        $res = sqlStatement($sql);
        $inventory = [];
        while ($row = sqlFetchArray($res)) {
            $inventory[] = $row;
        }
        return $inventory;
    }

    // Get all transfers (admin) or for a clinic (user)
    public function getTransfers($clinic_id = null)
    {
        if ($this->is_admin && !$clinic_id) {
            $sql = "SELECT * FROM custom_transfers ORDER BY transfer_date DESC";
            $res = sqlStatement($sql);
        } else {
            $sql = "SELECT * FROM custom_transfers WHERE from_clinic_id = ? OR to_clinic_id = ? ORDER BY transfer_date DESC";
            $res = sqlStatement($sql, array($clinic_id, $clinic_id));
        }
        $transfers = [];
        while ($row = sqlFetchArray($res)) {
            $transfers[] = $row;
        }
        return $transfers;
    }

    // Get alerts for a clinic (or all clinics for admin)
    public function getAlerts($clinic_id = null)
    {
        if ($this->is_admin && !$clinic_id) {
            $sql = "SELECT a.*, c.clinic_name, d.drug_name FROM custom_inventory_alerts a
                    JOIN custom_clinics c ON a.clinic_id = c.clinic_id
                    JOIN custom_drugs d ON a.drug_id = d.drug_id
                    WHERE a.is_active = 1 ORDER BY a.created_date DESC";
            $res = sqlStatement($sql);
        } else {
            $sql = "SELECT a.*, d.drug_name FROM custom_inventory_alerts a
                    JOIN custom_drugs d ON a.drug_id = d.drug_id
                    WHERE a.clinic_id = ? AND a.is_active = 1 ORDER BY a.created_date DESC";
            $res = sqlStatement($sql, array($clinic_id));
        }
        $alerts = [];
        while ($row = sqlFetchArray($res)) {
            $alerts[] = $row;
        }
        return $alerts;
    }

    // Add more methods for transfers, inventory updates, alert creation, etc. as needed
} 