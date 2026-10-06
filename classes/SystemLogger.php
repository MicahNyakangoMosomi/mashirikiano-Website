<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * Class SystemLogger
 * Handles structured logging for system errors and SMS/message communications.
 */
class SystemLogger
{
    /**
     * Log a message delivery record into the database message_logs table.
     *
     * @param string      $recipientPhone Phone number of receiver
     * @param string      $message        Message body content
     * @param string      $status         'Sent', 'Failed', 'Queued', 'Pending'
     * @param string|null $messageType    'single', 'bulk', 'alert', 'system'
     * @param int|null    $responseCode   HTTP response code from provider
     * @param string|null $responseData   Raw response from provider
     * @param string|null $errorMessage   Failure details if failed
     * @param string|null $memberId       Associated MemberID (optional, auto-detected if empty)
     * @param string|null $senderId       Sender ID (e.g. ORAMOBILE)
     * @param string|null $sentBy         User or system context that sent the message
     * @return int|null Inserted log ID, or null on failure
     */
    public static function logMessage(
        string $recipientPhone,
        string $message,
        string $status = 'Sent',
        ?string $messageType = 'single',
        ?int $responseCode = null,
        ?string $responseData = null,
        ?string $errorMessage = null,
        ?string $memberId = null,
        ?string $senderId = null,
        ?string $sentBy = null
    ): ?int {
        try {
            $pdo = Database::connection();

            // Auto-detect MemberID from phone if not provided
            if (empty($memberId) && !empty($recipientPhone)) {
                try {
                    $cleanPhone = preg_replace('/[^0-9]/', '', $recipientPhone);
                    $mStmt = $pdo->prepare(
                        "SELECT MemberID FROM members 
                         WHERE PrimaryNumber LIKE :phone1 
                            OR PrimaryNumber LIKE :phone2 
                         LIMIT 1"
                    );
                    $suffix = substr($cleanPhone, -9);
                    $mStmt->execute([
                        ':phone1' => '%' . $suffix,
                        ':phone2' => $recipientPhone,
                    ]);
                    $row = $mStmt->fetch(PDO::FETCH_ASSOC);
                    if ($row && !empty($row['MemberID'])) {
                        $memberId = (string)$row['MemberID'];
                    }
                } catch (Throwable $e) {
                    // Ignore lookup error and proceed
                }
            }

            // Resolve sentBy if not passed
            if ($sentBy === null) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $sentBy = $_SESSION['admin_user_id'] ?? ($_SESSION['member_id'] ?? 'system');
                } else {
                    $sentBy = 'system';
                }
            }

            $stmt = $pdo->prepare(
                "INSERT INTO message_logs (
                    MemberID,
                    RecipientPhone,
                    SenderID,
                    Message,
                    MessageType,
                    Status,
                    ResponseCode,
                    ResponseData,
                    ErrorMessage,
                    SentBy
                ) VALUES (
                    :member_id,
                    :recipient_phone,
                    :sender_id,
                    :message,
                    :message_type,
                    :status,
                    :response_code,
                    :response_data,
                    :error_message,
                    :sent_by
                )"
            );

            $stmt->execute([
                ':member_id'        => $memberId ?: null,
                ':recipient_phone'  => $recipientPhone,
                ':sender_id'        => $senderId ?: null,
                ':message'          => $message,
                ':message_type'     => $messageType ?: 'single',
                ':status'           => $status,
                ':response_code'    => $responseCode,
                ':response_data'    => $responseData,
                ':error_message'    => $errorMessage,
                ':sent_by'          => (string)$sentBy,
            ]);

            $logId = (int)$pdo->lastInsertId();

            // If message delivery failed, also record an error in system_logs
            if (strcasecmp($status, 'Failed') === 0) {
                self::log(
                    'message',
                    "SMS delivery failed to {$recipientPhone}: " . ($errorMessage ?: 'Unknown error'),
                    'ERROR',
                    [
                        'phone'         => $recipientPhone,
                        'message_id'    => $logId,
                        'response_code' => $responseCode,
                        'response_data' => $responseData,
                        'error_message' => $errorMessage,
                    ]
                );
            }

            return $logId;
        } catch (Throwable $e) {
            error_log('SystemLogger::logMessage failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Log a general system event or failure into the system_logs table.
     *
     * @param string      $type    Log category e.g. 'message', 'mpesa', 'auth', 'database', 'system'
     * @param string      $message Descriptive message
     * @param string      $level   'INFO', 'WARNING', 'ERROR', 'CRITICAL'
     * @param array       $context Context metadata, payload, or stack trace
     * @param string|null $file    Originating file path
     * @param int|null    $line    Originating line number
     * @param string|null $userId  User or admin identifier
     * @return int|null Inserted log ID, or null on failure
     */
    public static function log(
        string $type,
        string $message,
        string $level = 'ERROR',
        array $context = [],
        ?string $file = null,
        ?int $line = null,
        ?string $userId = null
    ): ?int {
        try {
            $pdo = Database::connection();

            $ipAddress = $_SERVER['REMOTE_ADDR']     ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

            if ($userId === null && session_status() === PHP_SESSION_ACTIVE) {
                $userId = (string)($_SESSION['admin_user_id'] ?? ($_SESSION['member_id'] ?? ''));
            }

            $stmt = $pdo->prepare(
                "INSERT INTO system_logs (
                    LogType,
                    LogLevel,
                    Message,
                    ContextData,
                    File,
                    Line,
                    IPAddress,
                    UserAgent,
                    UserID
                ) VALUES (
                    :log_type,
                    :log_level,
                    :message,
                    :context_data,
                    :file,
                    :line,
                    :ip_address,
                    :user_agent,
                    :user_id
                )"
            );

            $stmt->execute([
                ':log_type'     => strtolower(trim($type)),
                ':log_level'    => strtoupper(trim($level)),
                ':message'      => $message,
                ':context_data' => !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                ':file'         => $file,
                ':line'         => $line,
                ':ip_address'   => $ipAddress,
                ':user_agent'   => $userAgent ? substr($userAgent, 0, 255) : null,
                ':user_id'      => $userId ?: null,
            ]);

            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log("SystemLogger::log failed [{$type} - {$level}]: " . $e->getMessage() . " | Original message: {$message}");
            return null;
        }
    }

    /**
     * Convenience method to log an error.
     */
    public static function error(string $type, string $message, array $context = []): ?int
    {
        return self::log($type, $message, 'ERROR', $context);
    }

    /**
     * Convenience method to log a warning.
     */
    public static function warning(string $type, string $message, array $context = []): ?int
    {
        return self::log($type, $message, 'WARNING', $context);
    }

    /**
     * Convenience method to log info.
     */
    public static function info(string $type, string $message, array $context = []): ?int
    {
        return self::log($type, $message, 'INFO', $context);
    }
}
