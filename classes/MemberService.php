<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/SmsService.php';

class MemberService
{
    public const STATUSES = ['Active', 'Suspended', 'Pending'];

    public static function create(array $data): array
    {
        $pdo = Database::connection();
        $firstName = self::cleanName($data['first_name'] ?? $data['FirstName'] ?? '');
        $lastName = self::cleanName($data['last_name'] ?? $data['LastName'] ?? '');
        $nationalId = self::cleanNationalId($data['national_id'] ?? $data['NationalID'] ?? '');
        $phone = self::cleanPhone($data['phone'] ?? $data['PrimaryNumber'] ?? '');
        $email = self::cleanEmail($data['email'] ?? $data['Email'] ?? '');
        $profile = self::profileValues($data);
        $password = self::generatePassword();
        $depositPaid = self::boolValue($data['deposit_paid'] ?? false);

        self::validateRequired($firstName, $lastName, $nationalId, $phone);

        if (self::nationalIdExists($nationalId)) {
            throw new InvalidArgumentException('A member with this National ID already exists.');
        }

        $memberId = self::generateMemberId($nationalId, $firstName, $lastName);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $depositRequired = self::currentDepositAmount();
        $depositPaidAmount = $depositPaid ? $depositRequired : 0.00;
        $depositBalance = max(0.00, $depositRequired - $depositPaidAmount);
        $status = $depositBalance <= 0 ? 'Active' : 'Pending';

        $pdo->beginTransaction();
        $savedDocuments = [];
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO members (MemberID, NationalID, FirstName, LastName, PrimaryNumber, Email, Password, Status,
                 DateOfBirth, Gender, Nationality, MaritalStatus, PostalAddress, PhysicalAddress, Profession, Industry,
                 EmployerName, StaffPayrollNumber, EmploymentPosition, GrossMonthlyIncome, BusinessDetails,
                 JoiningDate)
                 VALUES (:member_id, :national_id, :first_name, :last_name, :phone, :email, :password, :status,
                 :DateOfBirth, :Gender, :Nationality, :MaritalStatus, :PostalAddress, :PhysicalAddress, :Profession, :Industry,
                 :EmployerName, :StaffPayrollNumber, :EmploymentPosition, :GrossMonthlyIncome, :BusinessDetails,
                 :JoiningDate)'
            );
            $stmt->execute([
                ':member_id' => $memberId,
                ':national_id' => $nationalId,
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':phone' => $phone,
                ':email' => $email ?: null,
                ':password' => $passwordHash,
                ':status' => $status,
            ] + $profile);
            self::saveNextOfKin($pdo, $memberId, $data['next_of_kin'] ?? []);

            $depositStmt = $pdo->prepare(
                'INSERT INTO deposits (MemberID, RequiredAmount, PaidAmount, Balance, Status)
                 VALUES (:member_id, :required_amount, :paid_amount, :balance, :status)'
            );
            $depositStmt->execute([
                ':member_id' => $memberId,
                ':required_amount' => $depositRequired,
                ':paid_amount' => $depositPaidAmount,
                ':balance' => $depositBalance,
                ':status' => $depositBalance <= 0 ? 'cleared' : 'pending',
            ]);

            if ($depositPaidAmount > 0) {
                $manualTranId = 'ONBOARD-' . $memberId;
                self::insertLedgerTransaction($pdo, [
                    'TranID' => $manualTranId,
                    'MemberID' => $memberId,
                    'NationalID' => $nationalId,
                    'FirstName' => $firstName,
                    'LastName' => $lastName,
                    'MSISDN' => $phone,
                    'Amount' => $depositPaidAmount,
                    'TransactionType' => 'deposit',
                    'TransactionCategory' => 'onboarding',
                    'Reference' => $manualTranId,
                    'Description' => 'Paid manually',
                    'TranTime' => date('Y-m-d H:i:s'),
                ]);
            }

            self::linkUnmatchedContributions($memberId, $nationalId, $phone);
            self::saveDocuments($memberId, $_FILES ?? [], $pdo, $savedDocuments);
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            foreach ($savedDocuments as $path) if (is_file($path)) unlink($path);
            throw $error;
        }

        if ($status === 'Active') {
            $fullName = trim($firstName . ' ' . $lastName);
            $smsMessage = "Dear {$fullName}, Thank you for joining Mashirikiano Sacco. You have been successfully registered. Your Membership ID is {$memberId} and your password is {$password}. Use your membershipid and the password as your login. Login url:https://mashirikianosacco.co.ke/auth/login.php Keep saving to qualify for loans of up to 3 times your savings. For support contact: itsupport@mashirikianosacco.co.ke or call 0758500557";
            SmsService::sendSms($phone, $smsMessage);
        }

        return [
            'MemberID' => $memberId,
            'NationalID' => $nationalId,
            'FirstName' => $firstName,
            'LastName' => $lastName,
            'PrimaryNumber' => $phone,
            'Email' => $email,
            'Status' => $status,
        ];
    }

    public static function update(string $memberId, array $data, array $options = []): void
    {
        $canChangeStatus = (bool)($options['can_change_status'] ?? true);
        $canChangePassword = (bool)($options['can_change_password'] ?? true);
        $memberId = trim($memberId);
        $firstName = self::cleanName($data['first_name'] ?? '');
        $lastName = self::cleanName($data['last_name'] ?? '');
        $nationalId = self::cleanNationalId($data['national_id'] ?? '');
        $phone = self::cleanPhone($data['phone'] ?? '');
        $email = self::cleanEmail($data['email'] ?? '');
        $profile = self::profileValues($data);
        $nextOfKin = $data['next_of_kin'] ?? [];
        $status = trim((string)($data['status'] ?? ''));
        $password = trim((string)($data['password'] ?? ''));

        if ($memberId === '' || $firstName === '' || $lastName === '' || $nationalId === '' || $phone === '') {
            throw new InvalidArgumentException('Member ID, National ID, first name, last name, and phone are required.');
        }

        if ($canChangeStatus && !in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Invalid member status.');
        }

        if (self::nationalIdExists($nationalId, $memberId)) {
            throw new InvalidArgumentException('Another member already uses this National ID.');
        }

        $fields = [
            'NationalID = :national_id',
            'FirstName = :first_name',
            'LastName = :last_name',
            'PrimaryNumber = :phone',
            'Email = :email',
        ];
        $params = [
            ':member_id' => $memberId,
            ':national_id' => $nationalId,
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':phone' => $phone,
            ':email' => $email ?: null,
        ];
        foreach ($profile as $column => $value) {
            $fields[] = "{$column} = :{$column}";
            $params[":" . $column] = $value;
        }

        if ($canChangeStatus) {
            $fields[] = 'Status = :status';
            $params[':status'] = $status;
        }

        if ($canChangePassword && $password !== '') {
            $fields[] = 'Password = :password';
            $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
        }

        $stmt = Database::connection()->prepare(
            'UPDATE members SET ' . implode(', ', $fields) . ' WHERE MemberID = :member_id'
        );
        $stmt->execute($params);

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            self::saveNextOfKin($pdo, $memberId, $nextOfKin, false);
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }

        self::saveDocuments($memberId, $_FILES ?? [], Database::connection());

        self::linkUnmatchedContributions($memberId, $nationalId, $phone);

        if ($canChangePassword && $password !== '') {
            self::sendPasswordChangedSms($phone, $firstName, $lastName, $memberId, $password);
        }
    }

    private static function profileValues(array $data): array
    {
        $columns = ['DateOfBirth','Gender','Nationality','MaritalStatus','PostalAddress','PhysicalAddress','Profession','Industry','EmployerName','StaffPayrollNumber','EmploymentPosition','GrossMonthlyIncome','BusinessDetails','JoiningDate'];
        $values = [];
        foreach ($columns as $column) $values[$column] = trim((string)($data[$column] ?? '')) ?: null;

        if ($values['GrossMonthlyIncome'] !== null && (!is_numeric($values['GrossMonthlyIncome']) || (float)$values['GrossMonthlyIncome'] <= 0)) {
            throw new InvalidArgumentException('Gross monthly income must be greater than zero.');
        }
        return $values;
    }

    private static function saveNextOfKin(PDO $pdo, string $memberId, array $items, bool $replace = true): void
    {
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM next_of_kin WHERE MemberID = ?');
        $countStmt->execute([$memberId]);
        $existingCount = (int)$countStmt->fetchColumn();
        if ($existingCount + count($items) > 3) throw new InvalidArgumentException('A member can have no more than 3 next of kin.');
        if ($replace) $pdo->prepare('DELETE FROM next_of_kin WHERE MemberID = ?')->execute([$memberId]);
        $stmt = $pdo->prepare('INSERT INTO next_of_kin (MemberID, FullName, NationalID, DateOfBirth, Relationship, Phone, Allocation) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $values = array_map(static fn($key) => trim((string)($item[$key] ?? '')), ['name','national_id','date_of_birth','relationship','phone','allocation']);
            if (!array_filter($values, static fn($value) => $value !== '')) continue;
            if ($values[0] === '' || $values[3] === '' || $values[4] === '') throw new InvalidArgumentException('Each next of kin needs a name, relationship, and phone number.');
            if ($values[5] !== '' && (!is_numeric($values[5]) || (float)$values[5] <= 0 || (float)$values[5] > 100)) throw new InvalidArgumentException('Next of kin allocation must be greater than zero and no more than 100%.');
            $stmt->execute([$memberId, $values[0], $values[1] ?: null, $values[2] ?: null, $values[3], $values[4], $values[5] === '' ? null : $values[5]]);
        }
    }

    public static function deleteMember(string $memberId, int $adminId): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM members WHERE MemberID = ? FOR UPDATE');
            $stmt->execute([$memberId]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$member) throw new RuntimeException('Member not found.');
            if (!in_array($member['Status'], ['Pending', 'Suspended'], true)) throw new RuntimeException('Only pending or suspended members can be deleted.');
            $archive = $pdo->prepare('INSERT INTO deleted_members (MemberID, NationalID, FirstName, LastName, PrimaryNumber, Email, Status, CreatedAt, MemberData, DeletedBy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $archive->execute([$member['MemberID'], $member['NationalID'], $member['FirstName'], $member['LastName'], $member['PrimaryNumber'], $member['Email'], $member['Status'], $member['CreatedAt'], json_encode($member, JSON_THROW_ON_ERROR), $adminId]);
            $pdo->prepare('DELETE FROM members WHERE MemberID = ?')->execute([$memberId]);
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }

    private static function saveDocuments(string $memberId, array $files, PDO $pdo, array &$savedDocuments = []): void
    {
        $map = [
            'national_id_file' => ['nationalID', 'id', 'NationalIDDocument', ['pdf']],
            'kra_file' => ['KRA', 'kra', 'KRADocument', ['pdf']],
            'passport_photo_file' => ['passportPhoto', 'photo', 'PassportPhoto', ['jpg','jpeg','png','webp']],
            'membership_form_file' => ['membership_form', 'form', 'MembershipForm', ['pdf']],
        ];
        $root = dirname(__DIR__) . '/member_documents';
        $updates = [];
        foreach ($map as $input => [$folder, $suffix, $column, $extensions]) {
            if (empty($files[$input]) || $files[$input]['error'] === UPLOAD_ERR_NO_FILE) continue;
            $file = $files[$input];
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 10 * 1024 * 1024) throw new RuntimeException('Document upload failed or exceeds 10 MB.');
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, $extensions, true) || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Invalid document type for ' . $folder . '.');
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $allowedMimes = $folder === 'passportPhoto' ? ['image/jpeg','image/png','image/webp'] : ['application/pdf'];
            if (!in_array($mime, $allowedMimes, true)) throw new RuntimeException('The uploaded file content does not match the required type for ' . $folder . '.');
            $directory = $root . '/' . $folder;
            if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new RuntimeException('Unable to create member document folder.');
            $name = preg_replace('/[^A-Za-z0-9_-]/', '_', $memberId) . '_' . $suffix . '.' . $extension;
            if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $name)) throw new RuntimeException('Unable to save uploaded document.');
            $absolutePath = $directory . '/' . $name;
            $savedDocuments[] = $absolutePath;
            $updates[] = [$column, 'member_documents/' . $folder . '/' . $name];
        }
        foreach ($updates as [$column, $path]) {
            $stmt = $pdo->prepare("UPDATE members SET {$column} = :path WHERE MemberID = :member_id");
            $stmt->execute([':path' => $path, ':member_id' => $memberId]);
        }
    }

    public static function deleteDocument(string $memberId, string $type): void
    {
        $documents = [
            'id' => ['NationalIDDocument', 'nationalID'],
            'kra' => ['KRADocument', 'KRA'],
            'photo' => ['PassportPhoto', 'passportPhoto'],
            'form' => ['MembershipForm', 'membership_form'],
        ];
        if (!isset($documents[$type])) {
            throw new InvalidArgumentException('Invalid document type.');
        }

        [$column, $folder] = $documents[$type];
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT {$column} FROM members WHERE MemberID = :member_id LIMIT 1");
        $stmt->execute([':member_id' => $memberId]);
        $relativePath = (string)($stmt->fetchColumn() ?: '');
        $expectedPrefix = 'member_documents/' . $folder . '/';

        if ($relativePath !== '' && !str_starts_with($relativePath, $expectedPrefix)) {
            throw new RuntimeException('Stored document path is invalid.');
        }

        if ($relativePath !== '') {
            $storageRoot = realpath(dirname(__DIR__) . '/member_documents');
            $filePath = realpath(dirname(__DIR__) . '/' . $relativePath);
            if ($filePath !== false && $storageRoot !== false && str_starts_with($filePath, $storageRoot . DIRECTORY_SEPARATOR) && is_file($filePath) && !unlink($filePath)) {
                throw new RuntimeException('Unable to remove the document file from the server.');
            }
        }

        $clear = $pdo->prepare("UPDATE members SET {$column} = NULL WHERE MemberID = :member_id");
        $clear->execute([':member_id' => $memberId]);
    }

    public static function generateMemberId(string $nationalId, string $firstName, string $lastName): string
    {
        $nationalPrefix = substr(preg_replace('/\D+/', '', $nationalId), 0, 4);
        if (strlen($nationalPrefix) < 4) {
            throw new InvalidArgumentException('National ID must contain at least 4 digits.');
        }

        $base = strtoupper($nationalPrefix . substr($firstName, 0, 1) . substr($lastName, 0, 1));
        $stmt = Database::connection()->prepare(
            'SELECT MemberID FROM members WHERE MemberID LIKE :prefix ORDER BY LENGTH(MemberID) DESC, MemberID DESC'
        );
        $stmt->execute([':prefix' => $base . '%']);
        $max = 0;

        foreach ($stmt->fetchAll() as $row) {
            if (preg_match('/^' . preg_quote($base, '/') . '(\d+)$/', $row['MemberID'], $matches)) {
                $max = max($max, (int)$matches[1]);
            }
        }

        return $base . ($max + 1);
    }

    public static function linkUnmatchedContributions(string $memberId, string $nationalId, string $phone = ''): void
    {
        $pdo = Database::connection();
        $phoneDigits = preg_replace('/\D+/', '', $phone);
        $phoneLocal = str_starts_with($phoneDigits, '254') ? '0' . substr($phoneDigits, 3) : $phoneDigits;
        $phoneInternational = str_starts_with($phoneDigits, '0') ? '254' . substr($phoneDigits, 1) : $phoneDigits;
        $stmt = $pdo->prepare(
            'UPDATE member_transactions SET MemberID = :member_id
             WHERE MemberID IS NULL AND (AccountNumber = :account_number OR MSISDN IN (:phone, :phone_local, :phone_international))'
        );
        $stmt->execute([
            ':member_id' => $memberId,
            ':account_number' => $nationalId,
            ':phone' => $phone,
            ':phone_local' => $phoneLocal,
            ':phone_international' => $phoneInternational,
        ]);

        try {
            $walletStmt = $pdo->prepare(
                'UPDATE SaccoWallets SET MemberID = :member_id WHERE NationalID = :national_id AND MemberID IS NULL'
            );
            $walletStmt->execute([
                ':member_id' => $memberId,
                ':national_id' => $nationalId,
            ]);
        } catch (Throwable $e) {
            // SaccoWallets table may not exist yet
        }
    }

    public static function currentDepositAmount(): float
    {
        $stmt = Database::connection()->query('SELECT DepositAmount FROM system_settings WHERE SettingID = 1 LIMIT 1');

        return (float)($stmt->fetchColumn() ?: 0);
    }

    public static function generatePassword(int $length = 8): string
    {
        $digits = '0123456789';
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $characters = [];

        for ($index = 0; $index < 5; $index++) {
            $characters[] = $digits[random_int(0, strlen($digits) - 1)];
        }

        for ($index = 0; $index < 3; $index++) {
            $characters[] = $letters[random_int(0, strlen($letters) - 1)];
        }

        for ($index = count($characters) - 1; $index > 0; $index--) {
            $swapIndex = random_int(0, $index);
            [$characters[$index], $characters[$swapIndex]] = [$characters[$swapIndex], $characters[$index]];
        }

        return implode('', $characters);
    }

    private static function sendPasswordChangedSms(
        string $phone,
        string $firstName,
        string $lastName,
        string $memberId,
        string $password
    ): void {
        $fullName = trim($firstName . ' ' . $lastName);
        $smsMessage = "Dear {$fullName}, your Mashirikiano Sacco member password has been changed. Your Membership ID is {$memberId} and your new password is {$password}. Login url:https://mashirikianosacco.co.ke/auth/login.php For support contact itsupport@mashirikianosacco.co.ke or call 0758500557";

        SmsService::sendSms($phone, $smsMessage);
    }

    private static function insertLedgerTransaction(PDO $pdo, array $data): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO member_transactions
             (TranID, MemberID, AccountNumber, FirstName, LastName, MSISDN, Amount, TransactionType, TransactionCategory, Reference, Description, TranTime)
             VALUES
             (:tran_id, :member_id, :account_number, :first_name, :last_name, :msisdn, :amount, :transaction_type, :transaction_category, :reference, :description, :tran_time)'
        );
        $stmt->execute([
            ':tran_id' => $data['TranID'],
            ':member_id' => $data['MemberID'],
            ':account_number' => $data['NationalID'],
            ':first_name' => $data['FirstName'],
            ':last_name' => $data['LastName'],
            ':msisdn' => $data['MSISDN'],
            ':amount' => $data['Amount'],
            ':transaction_type' => $data['TransactionType'],
            ':transaction_category' => $data['TransactionCategory'],
            ':reference' => $data['Reference'] ?? null,
            ':description' => $data['Description'] ?? null,
            ':tran_time' => $data['TranTime'] ?? null,
        ]);

        if (($data['TransactionType'] ?? '') === 'contribution') {
            try {
                $transactionId = (int)$pdo->lastInsertId();
                $amount = (float)$data['Amount'];
                $ushirikaAmount = round($amount * 0.05, 2);
                $akibaAmount = round($amount - $ushirikaAmount, 2);

                $walletStmt = $pdo->prepare("
                    INSERT INTO SaccoWallets
                    (TransactionID, TranID, MemberID, NationalID, Amount, AkibaAmount, UshirikaAmount, CreatedAt)
                    VALUES
                    (:tx_id, :tran_id, :member_id, :national_id, :amount, :akiba, :ushirika, :created_at)
                    ON DUPLICATE KEY UPDATE
                    Amount = VALUES(Amount),
                    AkibaAmount = VALUES(AkibaAmount),
                    UshirikaAmount = VALUES(UshirikaAmount)
                ");
                $walletStmt->execute([
                    ':tx_id' => $transactionId,
                    ':tran_id' => $data['TranID'],
                    ':member_id' => $data['MemberID'] ?: null,
                    ':national_id' => $data['NationalID'],
                    ':amount' => $amount,
                    ':akiba' => $akibaAmount,
                    ':ushirika' => $ushirikaAmount,
                    ':created_at' => $data['TranTime'] ?? date('Y-m-d H:i:s'),
                ]);
            } catch (Throwable $e) {
                // Ignore if table not created yet
            }
        }
    }

    private static function boolValue($value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'yes', 'true', 'on'], true);
    }

    private static function nationalIdExists(string $nationalId, ?string $exceptMemberId = null): bool
    {
        $sql = 'SELECT MemberID FROM members WHERE NationalID = :national_id';
        $params = [':national_id' => $nationalId];

        if ($exceptMemberId !== null) {
            $sql .= ' AND MemberID <> :member_id';
            $params[':member_id'] = $exceptMemberId;
        }

        $stmt = Database::connection()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return (bool)$stmt->fetch();
    }

    private static function validateRequired(string $firstName, string $lastName, string $nationalId, string $phone): void
    {
        if ($firstName === '' || $lastName === '' || $nationalId === '' || $phone === '') {
            throw new InvalidArgumentException('First name, last name, phone, and National ID are required.');
        }
    }

    private static function cleanName(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private static function cleanNationalId(string $value): string
    {
        return trim($value);
    }

    private static function cleanPhone(string $value): string
    {
        return trim(preg_replace('/\s+/', '', $value));
    }

    private static function cleanEmail(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter a valid email address or leave it blank.');
        }

        return $value;
    }
}
