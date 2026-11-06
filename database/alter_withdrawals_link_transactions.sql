USE `mashirikianosacc_mashirikiano`;

-- ============================================================================
-- Migration: Link Withdrawals Table to Member Transactions
-- Purpose:
--   1. Add 'Reference' and 'TranID' to the `withdrawals` table.
--   2. Backfill existing withdrawal records with matching 'Reference' (WD-<WithdrawalID>)
--      and 'TranID' from `member_transactions`.
--   3. Establish a Foreign Key constraint from `withdrawals.TranID` to
--      `member_transactions.TranID`.
-- ============================================================================

-- Step 1: Ensure `member_transactions.TranID` has a dedicated index for foreign key referencing
SET @exist_tranid_idx := (
    SELECT COUNT(*) 
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'member_transactions' 
      AND INDEX_NAME = 'idx_member_transactions_tran_id'
);
SET @sql := IF(@exist_tranid_idx = 0, 
    'ALTER TABLE `member_transactions` ADD INDEX `idx_member_transactions_tran_id` (`TranID`)', 
    'SELECT "Index idx_member_transactions_tran_id already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 2: Add `TranID` column to `withdrawals` if not present
SET @exist_col_tranid := (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'withdrawals' 
      AND COLUMN_NAME = 'TranID'
);
SET @sql := IF(@exist_col_tranid = 0, 
    'ALTER TABLE `withdrawals` ADD COLUMN `TranID` VARCHAR(60) NULL AFTER `AdminUserID`', 
    'SELECT "Column TranID already exists in withdrawals"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 3: Add `Reference` column to `withdrawals` if not present
SET @exist_col_ref := (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'withdrawals' 
      AND COLUMN_NAME = 'Reference'
);
SET @sql := IF(@exist_col_ref = 0, 
    'ALTER TABLE `withdrawals` ADD COLUMN `Reference` VARCHAR(120) NULL AFTER `TranID`', 
    'SELECT "Column Reference already exists in withdrawals"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 4: Backfill `Reference` on existing `withdrawals` (e.g. WD-1, WD-2, ..., WD-6)
UPDATE `withdrawals`
SET `Reference` = CONCAT('WD-', `WithdrawalID`)
WHERE `Reference` IS NULL OR `Reference` = '';

-- Step 5: Backfill `TranID` on existing `withdrawals` by matching `member_transactions.Reference`
UPDATE `withdrawals` w
JOIN `member_transactions` mt 
  ON mt.`Reference` = CONCAT('WD-', w.`WithdrawalID`)
  AND mt.`TransactionType` = 'withdrawal'
SET w.`TranID` = mt.`TranID`
WHERE w.`TranID` IS NULL OR w.`TranID` = '';

-- Fallback match by member, amount, and date if reference was missing
UPDATE `withdrawals` w
JOIN `member_transactions` mt 
  ON mt.`MemberID` = w.`MemberID`
  AND mt.`Amount` = w.`Amount`
  AND mt.`TransactionType` = 'withdrawal'
  AND ABS(TIMESTAMPDIFF(SECOND, COALESCE(mt.`TranTime`, mt.`CreatedAt`), w.`WithdrawalDate`)) <= 60
SET w.`TranID` = mt.`TranID`
WHERE w.`TranID` IS NULL OR w.`TranID` = '';

-- Step 6: Add Index on `TranID` and `Reference` in `withdrawals`
SET @exist_w_tranid_idx := (
    SELECT COUNT(*) 
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'withdrawals' 
      AND INDEX_NAME = 'idx_withdrawals_tran_id'
);
SET @sql := IF(@exist_w_tranid_idx = 0, 
    'ALTER TABLE `withdrawals` ADD INDEX `idx_withdrawals_tran_id` (`TranID`)', 
    'SELECT "Index idx_withdrawals_tran_id already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist_w_ref_idx := (
    SELECT COUNT(*) 
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'withdrawals' 
      AND INDEX_NAME = 'idx_withdrawals_reference'
);
SET @sql := IF(@exist_w_ref_idx = 0, 
    'ALTER TABLE `withdrawals` ADD INDEX `idx_withdrawals_reference` (`Reference`)', 
    'SELECT "Index idx_withdrawals_reference already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 7: Add Foreign Key Constraint from `withdrawals.TranID` to `member_transactions.TranID`
SET @exist_fk := (
    SELECT COUNT(*) 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'withdrawals' 
      AND CONSTRAINT_NAME = 'fk_withdrawals_transaction'
);
SET @sql := IF(@exist_fk = 0, 
    'ALTER TABLE `withdrawals` ADD CONSTRAINT `fk_withdrawals_transaction` FOREIGN KEY (`TranID`) REFERENCES `member_transactions` (`TranID`) ON UPDATE CASCADE ON DELETE SET NULL', 
    'SELECT "Foreign key fk_withdrawals_transaction already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

