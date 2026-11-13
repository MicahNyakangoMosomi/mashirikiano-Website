-- ============================================================================
-- Mashirikiano SACCO: Loan Management Engine Database Schema & DDL
-- Compatible with MySQL (InnoDB, utf8mb4) and ANSI SQL / PostgreSQL semantics
-- ============================================================================

USE `mashirikianosacc_mashirikiano`;

-- ----------------------------------------------------------------------------
-- Table 1: loan (Admin Loan Product Catalog)
-- Defines the loan options configured exclusively by system administrators.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `loan` (
  `loanID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loanType` VARCHAR(100) NOT NULL,
  `loanDescription` TEXT NULL,
  `maxAmount` DECIMAL(15,2) NOT NULL,
  `loanQualification_AkibaAmount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `loanQualification_UshirikaAmount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `loanInterest` DECIMAL(6,4) NOT NULL, -- Annual simple interest rate (e.g. 0.1200 for 12%)
  `paymentPeriod` VARCHAR(50) NOT NULL, -- Predefined duration (e.g. '30 Days', '90 Days', '365 Days') or 'CUSTOM'
  `interestType` VARCHAR(20) NOT NULL, -- 'REDUCING_BALANCE' or 'FLAT_RATE'
  `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`loanID`),
  KEY `idx_loan_type` (`loanType`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 2: loan_application (Member Submissions & Workflow)
-- Manages member loan requests, approval workflows, and unique M-Pesa routing generation.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `loan_application` (
  `loanApplicationID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loanID` BIGINT UNSIGNED NOT NULL,
  `memberID` VARCHAR(40) NOT NULL, -- Aligned with members.MemberID
  `loanType` VARCHAR(100) NOT NULL, -- Snapshot of product name at application time
  `amount` DECIMAL(15,2) NOT NULL,
  `returndate` DATE NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'PENDING', -- 'PENDING', 'APPROVED', 'REJECTED'
  `rejection` TEXT NULL, -- Reason for denial populated only if status is REJECTED
  `repayment_account` VARCHAR(5) NULL, -- Unique 5-char code (^[0-9][A-Z][0-9]{3}$) generated only upon approval
  `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`loanApplicationID`),
  UNIQUE KEY `uniq_loan_app_repayment_account` (`repayment_account`),
  KEY `idx_loan_app_member` (`memberID`),
  KEY `idx_loan_app_loan` (`loanID`),
  KEY `idx_loan_app_status` (`status`),
  CONSTRAINT `fk_loan_app_member`
    FOREIGN KEY (`memberID`) REFERENCES `members` (`MemberID`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_loan_app_loan`
    FOREIGN KEY (`loanID`) REFERENCES `loan` (`loanID`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 3: active_loan (Live Operational Ledger)
-- Spawned automatically upon admin approval to track real-time financial states and daily accruals.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `active_loan` (
  `activeLoanID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loanApplicationID` BIGINT UNSIGNED NOT NULL,
  `memberID` VARCHAR(40) NOT NULL, -- Aligned with members.MemberID
  `repayment_account` VARCHAR(5) NOT NULL, -- Mirrored 5-character M-Pesa code
  `interestType` VARCHAR(20) NOT NULL, -- 'REDUCING_BALANCE' or 'FLAT_RATE'
  `annualInterestRate` DECIMAL(6,4) NOT NULL,
  `originalPrincipal` DECIMAL(15,2) NOT NULL, -- Initial disbursed principal
  `principalBalance` DECIMAL(15,2) NOT NULL, -- Live remaining principal
  `accruedInterest` DECIMAL(15,2) NOT NULL DEFAULT 0.00, -- Rolling daily interest accumulated since last calculation
  `billedUnpaidInterest` DECIMAL(15,2) NOT NULL DEFAULT 0.00, -- Overdue interest moved here from missed milestones
  `penaltyBalance` DECIMAL(15,2) NOT NULL DEFAULT 0.00, -- Outstanding late fees or default penalties
  `disbursementDate` DATE NOT NULL,
  `returnDate` DATE NOT NULL,
  `lastInterestCalcDate` DATE NOT NULL,
  `loanStatus` VARCHAR(20) NOT NULL DEFAULT 'ACTIVE', -- 'ACTIVE', 'IN_ARREARS', 'CLOSED'
  `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`activeLoanID`),
  UNIQUE KEY `uniq_active_loan_application` (`loanApplicationID`),
  UNIQUE KEY `uniq_active_loan_repayment_acct` (`repayment_account`),
  KEY `idx_active_loan_member` (`memberID`),
  KEY `idx_active_loan_status` (`loanStatus`),
  KEY `idx_active_loan_return_date` (`returnDate`),
  CONSTRAINT `fk_active_loan_app`
    FOREIGN KEY (`loanApplicationID`) REFERENCES `loan_application` (`loanApplicationID`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_active_loan_member`
    FOREIGN KEY (`memberID`) REFERENCES `members` (`MemberID`)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 4: loan_transaction (Immutable Audit Log & Snapshots)
-- Append-only historical log recording every financial event, waterfall split, and post-transaction balance.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `loan_transaction` (
  `transactionID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activeLoanID` BIGINT UNSIGNED NOT NULL,
  `repayment_account` VARCHAR(5) NOT NULL,
  `mpesaReceiptNumber` VARCHAR(50) NULL,
  `transactionType` VARCHAR(30) NOT NULL, -- 'DISBURSEMENT', 'INTEREST_ACCRUAL', 'REPAYMENT', 'PENALTY'
  `transactionDate` DATE NOT NULL,
  `totalAmount` DECIMAL(15,2) NOT NULL,
  `allocatedPenalty` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `allocatedBilledInterest` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `allocatedAccruedInterest` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `allocatedPrincipal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `principalAfter` DECIMAL(15,2) NOT NULL,
  `accruedInterestAfter` DECIMAL(15,2) NOT NULL,
  `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`transactionID`),
  UNIQUE KEY `uniq_loan_trans_mpesa` (`mpesaReceiptNumber`),
  KEY `idx_loan_trans_active_loan` (`activeLoanID`),
  KEY `idx_loan_trans_repayment_acct` (`repayment_account`),
  KEY `idx_loan_trans_type` (`transactionType`),
  KEY `idx_loan_trans_date` (`transactionDate`),
  CONSTRAINT `fk_loan_trans_active_loan`
    FOREIGN KEY (`activeLoanID`) REFERENCES `active_loan` (`activeLoanID`)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Seed Initial Product Catalog
-- ----------------------------------------------------------------------------
INSERT INTO `loan` (`loanType`, `loanDescription`, `maxAmount`, `loanQualification_AkibaAmount`, `loanQualification_UshirikaAmount`, `loanInterest`, `paymentPeriod`, `interestType`)
VALUES 
('Emergency Loan', 'Members may access emergency facilities for urgent requirements, repayable within flexible durations.', 300000.00, 5000.00, 1000.00, 0.1200, '90 Days', 'REDUCING_BALANCE'),
('Development Loan', 'Long-term financing for development projects with flexible clearance schedules.', 10000000.00, 50000.00, 10000.00, 0.1400, 'CUSTOM', 'REDUCING_BALANCE'),
('Elimu Loan', 'Education and school fee financing priced at fair interest on reducing balance.', 1000000.00, 10000.00, 3000.00, 0.1200, '365 Days', 'REDUCING_BALANCE'),
('Biashara Loan', 'Enterprise capital designed to support business expansion and inventory.', 500000.00, 15000.00, 5000.00, 0.1300, 'CUSTOM', 'FLAT_RATE'),
('Nunua Simu Loan', 'Convenient mobile device credit facility with rapid approvals.', 20000.00, 1000.00, 500.00, 0.1000, '30 Days', 'FLAT_RATE')
ON DUPLICATE KEY UPDATE `loanType` = `loanType`;

