INSERT IGNORE INTO member_transactions
  (TranID, MemberID, AccountNumber, FirstName, LastName, MSISDN, Amount,
   TransactionType, TransactionCategory, Reference, Description, TranTime)
SELECT
  lt.mpesaReceiptNumber,
  m.MemberID,
  al.repayment_account,
  m.FirstName,
  m.LastName,
  m.PrimaryNumber,
  lt.totalAmount,
  'loan_repayment',
  'loan_repayment',
  lt.mpesaReceiptNumber,
  CONCAT('Loan repayment for account ', al.repayment_account),
  CAST(CONCAT(lt.transactionDate, ' 00:00:00') AS DATETIME)
FROM loan_transaction lt
JOIN active_loan al ON al.activeLoanID = lt.activeLoanID
JOIN members m ON m.MemberID = al.memberID
WHERE lt.transactionType = 'REPAYMENT'
  AND lt.mpesaReceiptNumber IS NOT NULL
  AND lt.mpesaReceiptNumber <> '';
