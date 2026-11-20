<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc !== 3 || !filter_var($argv[1], FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php create_user.php <email> <display-name>\n");
    exit(2);
}

if (PHP_OS_FAMILY !== 'Windows') {
    fwrite(STDERR, "This account setup helper currently supports the Windows/XAMPP environment only.\n");
    exit(2);
}

$passwordCommand = "powershell.exe -NoProfile -Command \"\$secure = Read-Host -Prompt 'Password' -AsSecureString; \$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR(\$secure); try { [Runtime.InteropServices.Marshal]::PtrToStringBSTR(\$ptr) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR(\$ptr) }\"";
$password = shell_exec($passwordCommand);
if (!is_string($password)) {
    fwrite(STDERR, "Could not read the password securely in PowerShell.\n");
    exit(1);
}
$password = rtrim($password, "\r\n");
if (strlen($password) < 12) {
    fwrite(STDERR, "Use a password with at least 12 characters.\n");
    exit(2);
}

try {
    require __DIR__ . '/db_connect.php';
    $statement = $pdo->prepare(
        'INSERT INTO app_users (name, email, password_hash, role)
         VALUES (:name, :email, :password_hash, :role)'
    );
    $statement->execute([
        ':name' => $argv[2],
        ':email' => strtolower($argv[1]),
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':role' => 'program_chair',
    ]);
    fwrite(STDOUT, "Program Chair account created for {$argv[1]}.\n");
} catch (Throwable $exception) {
    error_log('BAT405 account provisioning error: ' . $exception->getMessage());
    fwrite(STDERR, "Could not create the account. Check the database connection and whether that email is already in use.\n");
    exit(1);
} finally {
    $password = '';
}
