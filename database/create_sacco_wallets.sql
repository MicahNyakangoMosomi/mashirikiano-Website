USE `mashirikianosacc_mashirikiano`;

-- ============================================================================
-- Migration: Create SaccoWallets Table
-- Purpose:
--   Tracks wallet allocations for member contributions:
--     - Akiba Wallet: 95% of contribution (everyday savings)
--     - Ushirika Shares: 5% of contribution (member shares)
--   Connected to `member_transactions` via TransactionID and TranID.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `SaccoWallets` (
  `WalletRecordID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `TransactionID` BIGINT UNSIGNED NOT NULL,
  `TranID` VARCHAR(60) NOT NULL,
  `MemberID` VARCHAR(40) NULL,
  `NationalID` VARCHAR(30) NOT NULL,
  `Amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `AkibaAmount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `UshirikaAmount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`WalletRecordID`),
  UNIQUE KEY `uniq_sacco_wallets_transaction` (`TransactionID`),
  KEY `idx_sacco_wallets_tran_id` (`TranID`),
  KEY `idx_sacco_wallets_member` (`MemberID`),
  KEY `idx_sacco_wallets_national_id` (`NationalID`),
  CONSTRAINT `fk_sacco_wallets_transaction`
    FOREIGN KEY (`TransactionID`) REFERENCES `member_transactions` (`TransactionID`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_sacco_wallets_member`
    FOREIGN KEY (`MemberID`) REFERENCES `members` (`MemberID`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

