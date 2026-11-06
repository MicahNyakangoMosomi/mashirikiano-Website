USE `mashirikianosacc_mashirikiano`;

-- ============================================================================
-- Migration: Reset Existing Ushirika Shares to Zero & Set Akiba to Full Amount
-- Purpose:
--   For all existing past transactions in `SaccoWallets`:
--     - Set `UshirikaAmount` = 0.00 (all current members have 0 ushirika)
--     - Set `AkibaAmount` = `Amount` (all past contributions remain in Akiba)
--   New transactions created from now on will apply the 5% Ushirika / 95% Akiba split.
-- ============================================================================

UPDATE `SaccoWallets`
SET
  `AkibaAmount` = `Amount`,
  `UshirikaAmount` = 0.00;

