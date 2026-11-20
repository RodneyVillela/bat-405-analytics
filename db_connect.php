<?php
declare(strict_types=1);

$host = getenv('BAT405_DB_HOST') ?: '127.0.0.1';
$port = getenv('BAT405_DB_PORT') ?: '3306';
$database = getenv('BAT405_DB_NAME') ?: 'bat405_analytics';
$username = getenv('BAT405_DB_USER') ?: 'root';
$password = getenv('BAT405_DB_PASSWORD') ?: '';

$dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

$pdo = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
