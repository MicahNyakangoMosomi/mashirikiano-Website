<?php

require_once __DIR__ . '/TransactionDatabase.php';

class TransactionMirror
{
    public static function insert(array $data, ?array $member, float $amount, string $type, string $category, string $description): void
    {
        $mirror = TransactionDatabase::connection();
        $stmt = $mirror->prepare("\n            INSERT IGNORE INTO transactions\n            (TranID, MemberID, NationalID, FirstName, LastName, MSISDN, Amount, TransactionType, TransactionCategory, Reference, Description, TranTime, SourceDatabase)\n            VALUES\n            (:tran_id, :member_id, :national_id, :first_name, :last_name, :msisdn, :amount, :transaction_type, :transaction_category, :reference, :description, :tran_time, :source_database)\n        ");

        $stmt->execute([
            ':tran_id' => $data['TranID'],
            ':member_id' => $member ? (string)$member['MemberID'] : null,
            ':national_id' => $data['NationalID'],
            ':first_name' => $member ? (string)$member['FirstName'] : $data['FirstName'],
            ':last_name' => $member ? (string)$member['LastName'] : $data['LastName'],
            ':msisdn' => $data['MSISDN'],
            ':amount' => $amount,
            ':transaction_type' => $type,
            ':transaction_category' => $category,
            ':reference' => $data['TranID'],
            ':description' => $description,
            ':tran_time' => $data['TranTime'],
            ':source_database' => 'mashirikianosacc_mashirikiano',
        ]);
    }
}