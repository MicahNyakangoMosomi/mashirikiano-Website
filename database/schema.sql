CREATE DATABASE IF NOT EXISTS `mashirikianosacc_mashirikiano`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `mashirikianosacc_mashirikiano`;

CREATE TABLE IF NOT EXISTS `members` (
  `MemberID` VARCHAR(40) NOT NULL,
  `NationalID` VARCHAR(30) NOT NULL,
  `FirstName` VARCHAR(100) NOT NULL,
  `LastName` VARCHAR(100) NOT NULL,
  `PrimaryNumber` VARCHAR(20) NOT NULL,
  `Email` VARCHAR(150) NULL,
  `Password` VARCHAR(255) NOT NULL,
  `Status` ENUM('Active','Suspended','Pending') NOT NULL DEFAULT 'Pending',
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`MemberID`),
  UNIQUE KEY `uniq_members_national_id` (`NationalID`),
  KEY `idx_members_status` (`Status`),
  KEY `idx_members_name` (`FirstName`, `LastName`),
  KEY `idx_members_phone` (`PrimaryNumber`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_users` (
  `AdminUserID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `FullName` VARCHAR(150) NOT NULL,
  `Email` VARCHAR(150) NOT NULL,
  `Password` VARCHAR(255) NOT NULL,
  `Role` ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  `Status` ENUM('Active','Suspended') NOT NULL DEFAULT 'Active',
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`AdminUserID`),
  UNIQUE KEY `uniq_admin_users_email` (`Email`),
  KEY `idx_admin_users_role` (`Role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `NationalID` VARCHAR(30) NOT NULL,
  `FirstName` VARCHAR(100) NOT NULL,
  `LastName` VARCHAR(100) NOT NULL,
  `MSISDN` VARCHAR(20) NOT NULL,
  `Amount` DECIMAL(12,2) NOT NULL,
  `TransactionType` ENUM('deposit','contribution','withdrawal') NOT NULL,
  `TransactionCategory` VARCHAR(60) NOT NULL DEFAULT 'general',
  `Reference` VARCHAR(120) NULL,
  `Description` VARCHAR(255) NULL,
  `TranTime` DATETIME NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`TransactionID`),
  UNIQUE KEY `uniq_member_transactions_tran_segment` (`TranID`, `TransactionType`, `TransactionCategory`),
  KEY `idx_member_transactions_member` (`MemberID`),
  KEY `idx_member_transactions_national_id` (`NationalID`),
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

CREATE TABLE IF NOT EXISTS `withdrawals` (
  `WithdrawalID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `MemberID` VARCHAR(40) NOT NULL,
  `Amount` DECIMAL(12,2) NOT NULL,
  `WithdrawalDate` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `Description` VARCHAR(255) NULL,
  `AdminUserID` INT UNSIGNED NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`WithdrawalID`),
  KEY `idx_withdrawals_member` (`MemberID`),
  KEY `idx_withdrawals_date` (`WithdrawalDate`),
  CONSTRAINT `fk_withdrawals_member`
    FOREIGN KEY (`MemberID`) REFERENCES `members` (`MemberID`)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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
