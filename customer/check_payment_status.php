<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'customer'
) {
    http_response_code(401);

    echo json_encode([
        'status' => 'error',
    ]);

    exit;
}

$order_id = filter_input(
    INPUT_GET,
    'order_id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if (!$order_id) {
    http_response_code(400);

    echo json_encode([
        'status' => 'error',
    ]);

    exit;
}

$statement = $pdo->prepare("
    SELECT order_payment_status
    FROM orders
    WHERE order_id = ?
    AND order_user_id = ?
    LIMIT 1
");

$statement->execute([
    $order_id,
    current_user_id(),
]);

$order = $statement->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);

    echo json_encode([
        'status' => 'error',
    ]);

    exit;
}

echo json_encode([
    'status' => $order['order_payment_status'],
]);