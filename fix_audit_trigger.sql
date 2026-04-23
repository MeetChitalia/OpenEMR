-- Fix audit trigger to prevent duplicate entries
DROP TRIGGER IF EXISTS trg_drug_sales_audit;

DELIMITER $$

CREATE TRIGGER trg_drug_sales_audit 
AFTER INSERT ON drug_sales 
FOR EACH ROW 
BEGIN
    DECLARE v_movement_type VARCHAR(20);
    DECLARE v_reference_type VARCHAR(20);
    DECLARE v_reason VARCHAR(255);
    
    -- Only execute if audit trigger is not disabled
    IF @DISABLE_AUDIT_TRIGGER IS NULL THEN
        CASE NEW.trans_type
            WHEN 1 THEN SET v_movement_type = 'sale';
            WHEN 2 THEN SET v_movement_type = 'purchase';
            WHEN 3 THEN SET v_movement_type = 'return';
            WHEN 4 THEN SET v_movement_type = 'transfer';
            WHEN 5 THEN SET v_movement_type = 'adjustment';
            WHEN 6 THEN SET v_movement_type = 'distribution';
            WHEN 7 THEN SET v_movement_type = 'consumption';
            ELSE SET v_movement_type = 'adjustment';
        END CASE;

        SET v_reference_type = v_movement_type;

        CASE v_movement_type
            WHEN 'sale' THEN SET v_reason = 'Patient sale';
            WHEN 'purchase' THEN SET v_reason = 'Vendor purchase';
            WHEN 'return' THEN SET v_reason = 'Return to vendor';
            WHEN 'transfer' THEN SET v_reason = 'Inventory transfer';
            WHEN 'adjustment' THEN SET v_reason = 'Inventory adjustment';
            WHEN 'distribution' THEN SET v_reason = 'Distribution to facility';
            WHEN 'consumption' THEN SET v_reason = 'Internal consumption';
            ELSE SET v_reason = 'Inventory movement';
        END CASE;

        INSERT INTO inventory_movement_log (
            drug_id, inventory_id, warehouse_id, movement_type,
            quantity_before, quantity_change, quantity_after,
            reference_id, reference_type, reason, notes, user_id
        ) VALUES (
            NEW.drug_id, NEW.inventory_id,
            (SELECT warehouse_id FROM drug_inventory WHERE inventory_id = NEW.inventory_id),
            v_movement_type,
            (SELECT on_hand FROM drug_inventory WHERE inventory_id = NEW.inventory_id) + NEW.quantity,       
            NEW.quantity,
            (SELECT on_hand FROM drug_inventory WHERE inventory_id = NEW.inventory_id),
            NEW.sale_id, v_reference_type, v_reason, NEW.notes, NEW.user
        );
    END IF;
END$$

DELIMITER ; 