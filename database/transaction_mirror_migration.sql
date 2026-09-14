/*
  Transaction mirror database
  ---------------------------
  Run this script once with a MySQL user that can create databases.
  It creates the transaction-only database and copies the current primary
  ledger. Re-running it is safe because the mirror key is unique.
*/

CREATE DATABASE IF NOT EXISTS `mashirikiano_sacco_transctions`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `mashirikiano_sacco_transctions`;

CREATE TABLE IF NOT EXISTS `transactions` (
  `MirrorTransactionID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `TranID` VARCHAR(60) NOT NULL,
  `MemberID` VARCHAR(40) NULL,
  `NationalID` VARCHAR(30) NOT NULL,
  `FirstName` VARCHAR(100) NOT NULL,
  `LastName` VARCHAR(100) NOT NULL,
  `MSISDN` VARCHAR(20) NOT NULL,
  `Amount` DECIMAL(12,2) NOT NULL,
  `TransactionType` VARCHAR(30) NOT NULL,
  `TransactionCategory` VARCHAR(60) NOT NULL,
  `Reference` VARCHAR(120) NULL,
  `Description` VARCHAR(255) NULL,
  `TranTime` DATETIME NULL,
  `SourceDatabase` VARCHAR(100) NOT NULL,
  `MirroredAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`MirrorTransactionID`),
  UNIQUE KEY `uniq_transactions_segment` (`TranID`, `TransactionType`, `TransactionCategory`),
  KEY `idx_transactions_member` (`MemberID`),
  KEY `idx_transactions_time` (`TranTime`),
  KEY `idx_transactions_type` (`TransactionType`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `transactions`
  (`TranID`, `MemberID`, `NationalID`, `FirstName`, `LastName`, `MSISDN`, `Amount`, `TransactionType`, `TransactionCategory`, `Reference`, `Description`, `TranTime`, `SourceDatabase`)
SELECT
  `TranID`, `MemberID`, `NationalID`, `FirstName`, `LastName`, `MSISDN`, `Amount`, `TransactionType`, `TransactionCategory`, `Reference`, `Description`, `TranTime`, 'mashirikianosacc_mashirikiano'
FROM `mashirikianosacc_mashirikiano`.`member_transactions`;