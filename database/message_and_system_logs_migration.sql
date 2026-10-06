-- ============================================================================
-- Mashirikiano SACCO - Message Logs and System Error Logs Migration
-- ============================================================================

-- 1. Table for recording all outgoing SMS messages and communication statuses
CREATE TABLE IF NOT EXISTS `message_logs` (
  `LogID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `MemberID` VARCHAR(40) NULL,
  `RecipientPhone` VARCHAR(25) NOT NULL,
  `SenderID` VARCHAR(30) NULL,
  `Message` TEXT NOT NULL,
  `MessageType` ENUM('single', 'bulk', 'alert', 'system') NOT NULL DEFAULT 'single',
  `Status` ENUM('Pending', 'Sent', 'Failed', 'Queued') NOT NULL DEFAULT 'Pending',
  `ResponseCode` INT NULL,
  `ResponseData` LONGTEXT NULL,
  `ErrorMessage` TEXT NULL,
  `SentBy` VARCHAR(50) NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`LogID`),
  KEY `idx_message_logs_member` (`MemberID`),
  KEY `idx_message_logs_phone` (`RecipientPhone`),
  KEY `idx_message_logs_status` (`Status`),
  KEY `idx_message_logs_created` (`CreatedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Table for recording application errors, message failures, and system diagnostics
CREATE TABLE IF NOT EXISTS `system_logs` (
  `LogID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `LogType` VARCHAR(50) NOT NULL DEFAULT 'system',
  `LogLevel` ENUM('INFO', 'WARNING', 'ERROR', 'CRITICAL') NOT NULL DEFAULT 'ERROR',
  `Message` TEXT NOT NULL,
  `ContextData` LONGTEXT NULL,
  `File` VARCHAR(255) NULL,
  `Line` INT UNSIGNED NULL,
  `IPAddress` VARCHAR(45) NULL,
  `UserAgent` VARCHAR(255) NULL,
  `UserID` VARCHAR(50) NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`LogID`),
  KEY `idx_system_logs_type` (`LogType`),
  KEY `idx_system_logs_level` (`LogLevel`),
  KEY `idx_system_logs_created` (`CreatedAt`),
  KEY `idx_system_logs_user` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
