USE `mashirikianosacc_mashirikiano`;

-- ============================================================================
-- Migration: Seed SaccoWallets Table from existing member_transactions
-- Purpose:
--   Populates `SaccoWallets` with existing contribution records from
--   `member_transactions`.
--   Allocates:
--     - 95% to Akiba Wallet (AkibaAmount)
--     - 5% to Ushirika Shares (UshirikaAmount)
--   Guarantees exact balancing: AkibaAmount + UshirikaAmount = Amount.
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
  ROUND(mt.`Amount` * 0.95, 2) AS `AkibaAmount`,
  ROUND(mt.`Amount` - ROUND(mt.`Amount` * 0.95, 2), 2) AS `UshirikaAmount`,
  COALESCE(mt.`TranTime`, mt.`CreatedAt`) AS `CreatedAt`
FROM `member_transactions` mt
WHERE mt.`TransactionType` = 'contribution'
ON DUPLICATE KEY UPDATE
  `Amount` = VALUES(`Amount`),
  `AkibaAmount` = VALUES(`AkibaAmount`),
  `UshirikaAmount` = VALUES(`UshirikaAmount`);

