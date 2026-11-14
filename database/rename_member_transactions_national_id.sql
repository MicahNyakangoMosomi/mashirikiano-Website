ALTER TABLE member_transactions
  DROP INDEX idx_member_transactions_national_id,
  CHANGE COLUMN NationalID AccountNumber VARCHAR(60) NOT NULL,
  MODIFY COLUMN TransactionType ENUM('deposit','contribution','withdrawal','loan_repayment') NOT NULL,
  ADD INDEX idx_member_transactions_account_number (AccountNumber);

UPDATE member_transactions mt
JOIN members m ON m.NationalID = mt.AccountNumber
SET mt.MemberID = m.MemberID,
    mt.FirstName = m.FirstName,
    mt.LastName = m.LastName
WHERE mt.MemberID IS NULL;

UPDATE member_transactions mt
JOIN members m ON m.PrimaryNumber = mt.MSISDN
SET mt.MemberID = m.MemberID,
    mt.FirstName = m.FirstName,
    mt.LastName = m.LastName
WHERE mt.MemberID IS NULL;

UPDATE member_transactions mt
JOIN active_loan al
  ON mt.TransactionCategory = 'loan_repayment'
 AND mt.AccountNumber = al.repayment_account
JOIN members m ON m.MemberID = al.memberID
SET mt.MemberID = m.MemberID,
    mt.FirstName = m.FirstName,
    mt.LastName = m.LastName
WHERE mt.MemberID IS NULL;
