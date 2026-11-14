-- Reset loan records and loan repayment ledger rows while preserving members,
-- deposits, non-loan M-Pesa transactions, and unrelated member ledger rows.
--
-- SAFETY: This script ends with ROLLBACK. Review the preview results and take
-- a database backup first. To apply the reset, change the final ROLLBACK to
-- COMMIT, then run the script again.

USE `mashirikianosacc_mashirikiano`;

-- Preview all loan repayment ledger rows this script will delete.
SELECT
  `TransactionID`, `TranID`, `MemberID`, `AccountNumber`, `Amount`,
  `TransactionType`, `TransactionCategory`, `Description`, `TranTime`
FROM `member_transactions`
WHERE `TransactionType` = 'loan_repayment'
   OR `TransactionCategory` = 'loan_repayment'
   OR `Description` LIKE '%loan repayment%'
ORDER BY `TransactionID`;

-- Preview every loan table this reset clears.
SELECT 'loan_transaction' AS `table_name`, COUNT(*) AS `rows_to_delete` FROM `loan_transaction`
UNION ALL
SELECT 'active_loan', COUNT(*) FROM `active_loan`
UNION ALL
SELECT 'loan_application', COUNT(*) FROM `loan_application`
UNION ALL
SELECT 'loan products', COUNT(*) FROM `loan`;

START TRANSACTION;

-- Delete every ledger row tagged as a loan repayment. Related SaccoWallets
-- rows, if any, are removed by the transaction foreign key.
DELETE FROM `member_transactions`
WHERE `TransactionType` = 'loan_repayment'
   OR `TransactionCategory` = 'loan_repayment'
   OR `Description` LIKE '%loan repayment%';

-- Delete loan records in foreign-key order, retaining all table structures.
DELETE FROM `loan_transaction`;
DELETE FROM `active_loan`;
DELETE FROM `loan_application`;

-- Remove the loan product catalog so setup can start from a blank catalog.
DELETE FROM `loan`;

-- Safe default: nothing is permanently changed until this is replaced with
-- COMMIT after reviewing the preview and confirming a backup exists.
ROLLBACK;
