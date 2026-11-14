USE `mashirikianosacc_mashirikiano`;

/*
  Financial workflow migration
  --------------------------------
  This script restructures an existing installation. It preserves old
  members and contribution rows, then introduces:
  - system_settings for configurable deposit requirements
  - deposits for each member's fixed deposit obligation
  - member_transactions as the financial ledger

  Existing rows in contributions are copied into member_transactions as
  TransactionType = 'contribution'. They are not deleted.
*/

ALTER TABLE `members`
  MODIFY `MemberID` VARCHAR(40) NOT NULL,
  MODIFY `Status` ENUM('Active','Suspended','Pending') NOT NULL DEFAULT 'Pending';

SET @members_national_idx_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'members'
    AND INDEX_NAME = 'uniq_members_national_id'
);

SET @add_members_national_idx_sql := IF(
  @members_national_idx_exists > 0,
  'SELECT "members.NationalID index already exists" AS migration_notice',
  'ALTER TABLE `members` ADD UNIQUE KEY `uniq_members_national_id` (`NationalID`)'
);

PREPARE add_members_national_idx_stmt FROM @add_members_national_idx_sql;
EXECUTE add_members_national_idx_stmt;
DEALLOCATE PREPARE add_members_national_idx_stmt;

SET @members_status_idx_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'members'
    AND INDEX_NAME = 'idx_members_status'
);

SET @add_members_status_idx_sql := IF(
  @members_status_idx_exists > 0,
  'SELECT "members.Status index already exists" AS migration_notice',
  'ALTER TABLE `members` ADD KEY `idx_members_status` (`Status`)'
);

PREPARE add_members_status_idx_stmt FROM @add_members_status_idx_sql;
EXECUTE add_members_status_idx_stmt;
DEALLOCATE PREPARE add_members_status_idx_stmt;

SET @members_phone_idx_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'members'
    AND INDEX_NAME = 'idx_members_phone'
);

SET @add_members_phone_idx_sql := IF(
  @members_phone_idx_exists > 0,
  'SELECT "members.PrimaryNumber index already exists" AS migration_notice',
  'ALTER TABLE `members` ADD KEY `idx_members_phone` (`PrimaryNumber`)'
);

PREPARE add_members_phone_idx_stmt FROM @add_members_phone_idx_sql;
EXECUTE add_members_phone_idx_stmt;
DEALLOCATE PREPARE add_members_phone_idx_stmt;

CREATE TABLE IF NOT EXISTS `system_settings` (
  `SettingID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `DepositAmount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`SettingID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `system_settings` (`SettingID`, `DepositAmount`)
VALUES (1, 0.00)
ON DUPLICATE KEY UPDATE `DepositAmount` = `DepositAmount`;

CREATE TABLE IF NOT EXISTS `member_transactions` (
  `TransactionID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `TranID` VARCHAR(60) NOT NULL,
  `MemberID` VARCHAR(40) NULL,
  `AccountNumber` VARCHAR(60) NOT NULL,
  `FirstName` VARCHAR(100) NOT NULL,
  `LastName` VARCHAR(100) NOT NULL,
  `MSISDN` VARCHAR(20) NOT NULL,
  `Amount` DECIMAL(12,2) NOT NULL,
  `TransactionType` ENUM('deposit','contribution','withdrawal','loan_repayment') NOT NULL,
  `TransactionCategory` VARCHAR(60) NOT NULL DEFAULT 'general',
  `Reference` VARCHAR(120) NULL,
  `Description` VARCHAR(255) NULL,
  `TranTime` DATETIME NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`TransactionID`),
  UNIQUE KEY `uniq_member_transactions_tran_segment` (`TranID`, `TransactionType`, `TransactionCategory`),
  KEY `idx_member_transactions_member` (`MemberID`),
  KEY `idx_member_transactions_account_number` (`AccountNumber`),
  KEY `idx_member_transactions_type` (`TransactionType`),
  KEY `idx_member_transactions_category` (`TransactionCategory`),
  KEY `idx_member_transactions_time` (`TranTime`),
  CONSTRAINT `fk_member_transactions_member`
    FOREIGN KEY (`MemberID`) REFERENCES `members` (`MemberID`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `deposits` (
  `DepositID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `MemberID` VARCHAR(40) NOT NULL,
  `RequiredAmount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `PaidAmount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `Balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `Status` ENUM('pending','cleared') NOT NULL DEFAULT 'pending',
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`DepositID`),
  UNIQUE KEY `uniq_deposits_member` (`MemberID`),
  KEY `idx_deposits_status` (`Status`),
  CONSTRAINT `fk_deposits_member`
    FOREIGN KEY (`MemberID`) REFERENCES `members` (`MemberID`)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @contributions_table_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'contributions'
);

SET @copy_contributions_sql := IF(
  @contributions_table_exists > 0,
  'INSERT IGNORE INTO `member_transactions` (`TranID`, `MemberID`, `AccountNumber`, `FirstName`, `LastName`, `MSISDN`, `Amount`, `TransactionType`, `TransactionCategory`, `Reference`, `Description`, `TranTime`, `CreatedAt`) SELECT c.`TranID`, m.`MemberID`, c.`NationalID`, c.`FirstName`, c.`LastName`, c.`MSISDN`, c.`Amount`, "contribution", "monthly_contribution", c.`TranID`, "Migrated from legacy contributions table", c.`TranTime`, c.`CreatedAt` FROM `contributions` c LEFT JOIN `members` m ON m.`MemberID` = c.`MemberID`',
  'SELECT "No contributions table found; skipping contribution copy" AS migration_notice'
);

PREPARE copy_contributions_stmt FROM @copy_contributions_sql;
EXECUTE copy_contributions_stmt;
DEALLOCATE PREPARE copy_contributions_stmt;

SET @transactions_table_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'transactions'
);

SET @copy_transactions_sql := IF(
  @transactions_table_exists > 0,
  'INSERT IGNORE INTO `member_transactions` (`TranID`, `MemberID`, `AccountNumber`, `FirstName`, `LastName`, `MSISDN`, `Amount`, `TransactionType`, `TransactionCategory`, `Reference`, `Description`, `TranTime`, `CreatedAt`) SELECT t.`TranID`, m.`MemberID`, t.`NationalID`, t.`FirstName`, t.`LastName`, t.`MSISDN`, t.`Amount`, "contribution", "monthly_contribution", t.`TranID`, "Migrated from legacy transactions table", t.`TranTime`, t.`CreatedAt` FROM `transactions` t LEFT JOIN `members` m ON m.`MemberID` = CAST(t.`MemberID` AS CHAR)',
  'SELECT "No transactions table found; skipping transaction copy" AS migration_notice'
);

PREPARE copy_transactions_stmt FROM @copy_transactions_sql;
EXECUTE copy_transactions_stmt;
DEALLOCATE PREPARE copy_transactions_stmt;

INSERT IGNORE INTO `deposits` (`MemberID`, `RequiredAmount`, `PaidAmount`, `Balance`, `Status`)
SELECT
  m.`MemberID`,
  0.00,
  0.00,
  0.00,
  'cleared'
FROM `members` m;

CREATE OR REPLACE VIEW `member_contribution_totals` AS
SELECT
  m.MemberID,
  m.NationalID,
  m.FirstName,
  m.LastName,
  COALESCE(SUM(CASE WHEN mt.TransactionType = 'contribution' THEN mt.Amount ELSE 0 END), 0) AS TotalContributions,
  COUNT(CASE WHEN mt.TransactionType = 'contribution' THEN mt.TransactionID END) AS ContributionCount
FROM members m
LEFT JOIN member_transactions mt ON mt.MemberID = m.MemberID
GROUP BY m.MemberID, m.NationalID, m.FirstName, m.LastName;

CREATE OR REPLACE VIEW `member_deposit_status` AS
SELECT
  m.MemberID,
  m.NationalID,
  m.FirstName,
  m.LastName,
  m.Status AS MemberStatus,
  d.RequiredAmount,
  d.PaidAmount,
  d.Balance,
  d.Status AS DepositStatus
FROM members m
LEFT JOIN deposits d ON d.MemberID = m.MemberID;
