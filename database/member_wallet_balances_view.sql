USE `mashirikianosacc_mashirikiano`;

-- ============================================================================
-- View: member_wallet_balances
-- Purpose:
--   Aggregates for each member:
--     - TotalContributions (sum of Amount from SaccoWallets)
--     - TotalAkiba (sum of 95% contributions from SaccoWallets)
--     - TotalUshirikaShares (sum of 5% contributions from SaccoWallets)
--     - TotalWithdrawals (sum of cash withdrawals from withdrawals table)
--     - AkibaWalletBalance (TotalAkiba - TotalWithdrawals)
-- ============================================================================

CREATE OR REPLACE VIEW `member_wallet_balances` AS
SELECT
  m.`MemberID`,
  m.`NationalID`,
  m.`FirstName`,
  m.`LastName`,
  COALESCE(w.`TotalContributions`, 0.00) AS `TotalContributions`,
  COALESCE(w.`TotalAkiba`, 0.00) AS `TotalAkiba`,
  COALESCE(w.`TotalUshirika`, 0.00) AS `TotalUshirikaShares`,
  COALESCE(wd.`TotalWithdrawals`, 0.00) AS `TotalWithdrawals`,
  GREATEST(0.00, COALESCE(w.`TotalAkiba`, 0.00) - COALESCE(wd.`TotalWithdrawals`, 0.00)) AS `AkibaWalletBalance`
FROM `members` m
LEFT JOIN (
  SELECT
    `MemberID`,
    SUM(`Amount`) AS `TotalContributions`,
    SUM(`AkibaAmount`) AS `TotalAkiba`,
    SUM(`UshirikaAmount`) AS `TotalUshirika`
  FROM `SaccoWallets`
  WHERE `MemberID` IS NOT NULL
  GROUP BY `MemberID`
) w ON w.`MemberID` = m.`MemberID`
LEFT JOIN (
  SELECT
    `MemberID`,
    SUM(`Amount`) AS `TotalWithdrawals`
  FROM `withdrawals`
  WHERE `MemberID` IS NOT NULL
  GROUP BY `MemberID`
) wd ON wd.`MemberID` = m.`MemberID`;

