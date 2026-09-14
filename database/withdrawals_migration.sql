USE `mashirikianosacc_mashirikiano`;

/*
  Withdrawals System Migration
  --------------------------------
  Creates the `withdrawals` table to track member cash withdrawals from SACCO savings.
  Records both specific withdrawal table entries and logs transactions in `member_transactions`.
*/

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
