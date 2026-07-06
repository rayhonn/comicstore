<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';

require_customer();

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: cart.php');
    exit;
}

csrf_verify();

$action = $_POST['action'] ?? '';

if ($action === 'add') {
    $product_id = $_POST['product_id'] ?? null;
    $quantity   = (int)($_POST['quantity'] ?? 1);

    if ($product_id && $quantity > 0) {
        $stmt = $pdo->prepare("SELECT cart_item_id, cart_item_quantity FROM cart_items WHERE cart_item_user_id = ? AND cart_item_product_id = ?");
        $stmt->execute([$user_id, $product_id]);
        $existing = $stmt->fetch();

        if ($existing) {
            $pdo->prepare("UPDATE cart_items SET cart_item_quantity = cart_item_quantity + ? WHERE cart_item_id = ?")
                ->execute([$quantity, $existing['cart_item_id']]);
        } else {
            $pdo->prepare("INSERT INTO cart_items (cart_item_user_id, cart_item_product_id, cart_item_quantity) VALUES (?, ?, ?)")
                ->execute([$user_id, $product_id, $quantity]);
        }
    }

} elseif ($action === 'remove') {
    $cart_item_id = $_POST['cart_item_id'] ?? null;
    if ($cart_item_id) {
        $pdo->prepare("DELETE FROM cart_items WHERE cart_item_id = ? AND cart_item_user_id = ?")
            ->execute([$cart_item_id, $user_id]);
    }

} elseif ($action === 'update') {
    $cart_item_id = $_POST['cart_item_id'] ?? null;
    $quantity     = (int)($_POST['quantity'] ?? 1);
    if ($cart_item_id && $quantity > 0) {
        $pdo->prepare("UPDATE cart_items SET cart_item_quantity = ? WHERE cart_item_id = ? AND cart_item_user_id = ?")
            ->execute([$quantity, $cart_item_id, $user_id]);
    } elseif ($cart_item_id && $quantity <= 0) {
        $pdo->prepare("DELETE FROM cart_items WHERE cart_item_id = ? AND cart_item_user_id = ?")
            ->execute([$cart_item_id, $user_id]);
    }
}

header('Location: cart.php');
exit;