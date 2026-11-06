USE `mashirikianosacc_mashirikiano`;

-- ============================================================================
-- Migration: Credit Transfers Table
-- Purpose:
--   Captures internal credit transfers between member wallets:
--     - Akiba Wallet -> Ushirika Shares
--     - Ushirika Shares -> Akiba Wallet
--   Tracks authorized admin, previous & new balances, and timestamps.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `credit_transfers` (
  `TransferID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `TransferCode` VARCHAR(60) NOT NULL,
  `MemberID` VARCHAR(40) NOT NULL,
  `NationalID` VARCHAR(30) NOT NULL,
  `TransferFrom` ENUM('akiba', 'ushirika') NOT NULL,
  `TransferTo` ENUM('akiba', 'ushirika') NOT NULL,
  `Amount` DECIMAL(12,2) NOT NULL,
  `PreviousAkiba` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `PreviousUshirika` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `NewAkiba` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `NewUshirika` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `AdminUserID` INT UNSIGNED NULL,
  `AdminName` VARCHAR(100) NULL,
  `Notes` VARCHAR(255) NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`TransferID`),
  UNIQUE KEY `uniq_credit_transfers_code` (`TransferCode`),
  KEY `idx_credit_transfers_member` (`MemberID`),
  KEY `idx_credit_transfers_national` (`NationalID`),
  KEY `idx_credit_transfers_admin` (`AdminUserID`),
  KEY `idx_credit_transfers_created` (`CreatedAt`),
  CONSTRAINT `fk_credit_transfers_member`
    FOREIGN KEY (`MemberID`) REFERENCES `members` (`MemberID`)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

