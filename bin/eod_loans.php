<?php

declare(strict_types=1);

/**
 * Mashirikiano SACCO: End-Of-Day (EOD) Loan Accrual & Arrears Batch Script
 *
 * Usage via CLI / Cron:
 * php bin/eod_loans.php
 */

require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/LoanService.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting Mashirikiano SACCO EOD Loan Batch...\n";

try {
    $pdo = Database::connection();
    LoanService::ensureTables($pdo);

    $summary = LoanService::runEndOfDayBatch($pdo);

    echo "[" . date('Y-m-d H:i:s') . "] EOD Batch Completed Successfully!\n";
    echo "  - Date: " . $summary['batch_date'] . "\n";
    echo "  - Active loans processed: " . $summary['processed'] . "\n";
    echo "  - Newly flagged in arrears: " . $summary['flagged_in_arrears'] . "\n";
    echo "  - Unpaid rolling interest shifted: KES " . number_format($summary['shifted_interest'], 2) . "\n";
} catch (Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR executing EOD batch: " . $e->getMessage() . "\n";
    exit(1);
}

