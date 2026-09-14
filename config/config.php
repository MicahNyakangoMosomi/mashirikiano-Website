<?php

$rootPath = dirname(__DIR__);
$autoloadPath = $rootPath . '/vendor/autoload.php';

/**
 * 1. Load Composer autoload
 */
if (!file_exists($autoloadPath)) {
    die("Composer autoload not found. Run composer install.");
}

require_once $autoloadPath;

/**
 * 2. Load .env safely but strictly
 */
if (!class_exists(Dotenv\Dotenv::class)) {
    die("Dotenv library not installed.");
}

$dotenv = Dotenv\Dotenv::createImmutable($rootPath);
$dotenv->load();

/**
 * 3. Hard fail if .env is missing critical values
 */
if (!function_exists('env')) {
    function env(string $key, string $default = null)
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return $value;
    }
}

if (!function_exists('env_required')) {
    function env_required(string $key)
    {
        $value = env($key);

        if ($value === null || $value === '') {
            die("Missing required environment variable: {$key}");
        }

        return $value;
    }
}

/**
 * 4. CONFIG ARRAY
 */
return [
    /**
     * DATABASE CONFIG
     */
    'db' => [
        'host'    => env_required('DB_HOST'),
        'name'    => env_required('DB_NAME'),
        'user'    => env_required('DB_USER'),
        'pass'    => env_required('DB_PASS'),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
    ],

    /**
     * APPLICATION CONFIG
     */
    'app' => [
        'name' => env('APP_NAME', 'My App'),
        'env'  => env('APP_ENV', 'production'),
        'base_url' => env('APP_BASE_URL', ''),
        'admin_token' => env('ADMIN_REPORT_TOKEN', env('ADMIN_TOKEN', '')),
    ],

    /**
     * M-PESA CONFIG (FIXED - THIS WAS MISSING)
     */
    'mpesa' => [
        'environment'     => env('MPESA_ENV', 'sandbox'), // sandbox | production
        'consumer_key'    => env_required('MPESA_CONSUMER_KEY'),
        'consumer_secret' => env_required('MPESA_CONSUMER_SECRET'),
        'shortcode'       => env_required('MPESA_SHORTCODE'),

        // optional but useful later
        'passkey'         => env('MPESA_PASSKEY'),
        'callback_url'    => env('MPESA_CALLBACK_URL'),
    ],

    /**
     * MOBILESASA CONFIG
     */
    'mobilesasa' => [
        'api_key'   => env('MOBILESASA_API_KEY'),
        'sender_id' => env('MOBILESASA_SENDER_ID', 'MSHRKIANO'),
    ],
];
