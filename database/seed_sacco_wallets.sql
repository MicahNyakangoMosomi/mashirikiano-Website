USE `mashirikianosacc_mashirikiano`;

-- ============================================================================
-- Migration: Seed SaccoWallets Table from existing member_transactions
-- Purpose:
--   Populates `SaccoWallets` with existing contribution records from
--   `member_transactions`.
--   Allocates:
--     - 100% of past contributions to Akiba Wallet (AkibaAmount)
--     - 0.00 to Ushirika Shares (UshirikaAmount)
--   New transactions going forward will automatically apply the 5% Ushirika split.
-- ============================================================================

INSERT INTO `SaccoWallets` (
  `TransactionID`,
  `TranID`,
  `MemberID`,
  `NationalID`,
  `Amount`,
  `AkibaAmount`,
  `UshirikaAmount`,
  `CreatedAt`
)
SELECT
  mt.`TransactionID`,
  mt.`TranID`,
  mt.`MemberID`,
  mt.`NationalID`,
  mt.`Amount`,
  mt.`Amount` AS `AkibaAmount`,
  0.00 AS `UshirikaAmount`,
  COALESCE(mt.`TranTime`, mt.`CreatedAt`) AS `CreatedAt`
FROM `member_transactions` mt
WHERE mt.`TransactionType` = 'contribution'
ON DUPLICATE KEY UPDATE
  `Amount` = VALUES(`Amount`),
  `AkibaAmount` = VALUES(`Amount`),
  `UshirikaAmount` = 0.00;

