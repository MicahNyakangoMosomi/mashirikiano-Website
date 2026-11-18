CREATE TABLE IF NOT EXISTS member_penalties (
  PenaltyID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  MemberID VARCHAR(40) NOT NULL,
  Amount DECIMAL(12,2) NOT NULL,
  PaidAmount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  Reason VARCHAR(255) NULL,
  RestoredBy INT UNSIGNED NULL,
  CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_member_penalties_member (MemberID),
  KEY idx_member_penalties_status (MemberID, PaidAmount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE member_transactions
  MODIFY TransactionType ENUM('deposit','contribution','withdrawal','loan_repayment','penalty_payment') NOT NULL;
