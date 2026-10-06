<?php

declare(strict_types=1);

/**
 * Class SmsService
 * Handles sending SMS messages via the OramMobile API.
 */
class SmsService
{
    private static ?array $config = null;

    private static function config(): array
    {
        if (self::$config === null) {
            self::$config = require __DIR__ . '/../config/config.php';
        }
        return self::$config;
    }

    /**
     * Send an SMS using OramMobile API
     *
     * Endpoint: POST https://vas-api.oramobile.co.ke/api/v1/messages
     * Auth:     Authorization: Bearer {API_TOKEN}
     * Body:     { sender_id, phone, message }
     *
     * @param string $phone The recipient phone number
     * @param string $message The SMS text
     * @return bool True if successful, false otherwise
     */
    public static function sendSms(string $phone, string $message): bool
    {
        $oramobile = self::config()['oramobile'] ?? [];
        $apiKey    = $oramobile['api_key']   ?? '';
        $senderId  = $oramobile['sender_id'] ?? '';

        if (empty($apiKey)) {
            self::logError("SMS skipped: OramMobile API key is not configured. Phone: $phone, Message: $message");
            return false;
        }

        $url = 'https://vas-api.oramobile.co.ke/api/v1/messages';

        $data = [
            'sender_id' => $senderId,
            'phone'     => self::normalizePhone($phone),
            'message'   => $message,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            self::logError("OramMobile API Error (HTTP $httpCode): " . ($error ?: $response) . " Payload: " . json_encode($data));
            return false;
        }

        // Decode and verify success flag from response
        $decoded = json_decode($response, true);
        if (!isset($decoded['success']) || $decoded['success'] !== true) {
            self::logError("OramMobile API rejected message (HTTP $httpCode): $response Payload: " . json_encode($data));
            return false;
        }

        return true;
    }

    /**
     * Send the same SMS to multiple recipients in one API call.
     *
     * Endpoint: POST https://vas-api.oramobile.co.ke/api/v1/messages/bulk
     * Auth:     Authorization: Bearer {API_TOKEN}
     * Body:     { sender_id, phones: ['+254...', ...], message }
     *
     * @param  string[] $phones  List of phone numbers (any format — normalised internally)
     * @param  string   $message The SMS text to broadcast
     * @return array   ['success' => bool, 'data' => [...], 'error' => string]
     */
    public static function sendBulkSms(array $phones, string $message): array
    {
        $oramobile = self::config()['oramobile'] ?? [];
        $apiKey    = $oramobile['api_key']   ?? '';
        $senderId  = $oramobile['sender_id'] ?? '';

        if (empty($apiKey)) {
            $err = 'OramMobile API key is not configured.';
            self::logError('Bulk SMS skipped: ' . $err);
            return ['success' => false, 'error' => $err, 'data' => []];
        }

        // Normalise every number to E.164 and deduplicate
        $normalised = array_values(array_unique(array_map(
            [self::class, 'normalizePhone'],
            $phones
        )));

        if (empty($normalised)) {
            return ['success' => false, 'error' => 'No valid phone numbers.', 'data' => []];
        }

        $url  = 'https://vas-api.oramobile.co.ke/api/v1/messages/bulk';
        $data = [
            'sender_id' => $senderId,
            'phones'    => $normalised,
            'message'   => $message,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,   // bulk can take longer
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            $errMsg = "Bulk SMS API Error (HTTP $httpCode): " . ($error ?: $response);
            self::logError($errMsg . ' Payload phones count: ' . count($normalised));
            return ['success' => false, 'error' => $errMsg, 'data' => []];
        }

        $decoded = json_decode($response, true);
        if (!isset($decoded['success']) || $decoded['success'] !== true) {
            $errMsg = 'OramMobile rejected bulk message: ' . $response;
            self::logError($errMsg);
            return ['success' => false, 'error' => $errMsg, 'data' => $decoded ?? []];
        }

        return [
            'success' => true,
            'data'    => $decoded['data'] ?? [],
            'error'   => '',
        ];
    }

    /**
     * Normalize phone number to E.164 (+254...) format required by OramMobile.
     * Public so it can be called statically from external code.
     */
    public static function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\s+/', '', $phone);
        
        if (str_starts_with($phone, '0')) {
            return '+254' . substr($phone, 1);
        }
        
        if (str_starts_with($phone, '254')) {
            return '+' . $phone;
        }
        
        if (!str_starts_with($phone, '+')) {
            if (strlen($phone) === 9) {
                return '+254' . $phone;
            }
        }
        
        return $phone;
    }

    /**
     * Log SMS errors gracefully without breaking the app
     */
    private static function logError(string $message): void
    {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        @file_put_contents(
            $logDir . '/sms-errors.log',
            '[' . date('c') . '] ' . $message . PHP_EOL,
            FILE_APPEND
        );
    }
}
