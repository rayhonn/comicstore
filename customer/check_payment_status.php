<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

$order_id = $_GET['order_id'] ?? null;
if (!$order_id || !isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error']);
    exit;
}

$order = $pdo->prepare("SELECT order_payment_status FROM orders WHERE order_id = ? AND order_user_id = ?");
$order->execute([$order_id, $_SESSION['user_id']]);
$order = $order->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo json_encode(['status' => 'error']);
    exit;
}

echo json_encode(['status' => $order['order_payment_status']]);