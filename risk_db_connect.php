<?php
declare(strict_types=1);

// Academic risk prototype data lives in the user's spartan_insight database.
// Authentication and the legacy analytics API continue to use db_connect.php.
$host = getenv('SPARTAN_DB_HOST') ?: '127.0.0.1';
$port = getenv('SPARTAN_DB_PORT') ?: '3306';
$database = getenv('SPARTAN_DB_NAME') ?: 'spartan_insight';
$username = getenv('SPARTAN_DB_USER') ?: 'root';
$password = getenv('SPARTAN_DB_PASSWORD') ?: '';

$dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
$riskPdo = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
