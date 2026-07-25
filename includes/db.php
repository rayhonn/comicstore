<?php

require_once __DIR__ . '/logger.php';

$host = 'localhost';
$dbname = 'comicstore_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    $pdo->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );
} catch (PDOException $e) {
    app_log_exception(
        'Database',
        $e,
        ['operation' => 'connect']
    );

    http_response_code(500);

    die(
        'A database error occurred. ' .
        'Please try again later.'
    );
}