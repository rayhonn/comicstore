<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];
$product_id = $_POST['product_id'] ?? null;
$action = $_POST['action'] ?? '';
$redirect = $_POST['redirect'] ?? 'wishlist.php';

if ($product_id) {
    if ($action === 'add') {
        $check = $pdo->prepare("SELECT wishlist_id FROM wishlist WHERE wishlist_user_id = ? AND wishlist_product_id = ?");
        $check->execute([$user_id, $product_id]);
        if ($check->rowCount() === 0) {
            $pdo->prepare("INSERT INTO wishlist (wishlist_user_id, wishlist_product_id) VALUES (?, ?)")
                ->execute([$user_id, $product_id]);
        }
    } elseif ($action === 'remove') {
        $pdo->prepare("DELETE FROM wishlist WHERE wishlist_user_id = ? AND wishlist_product_id = ?")
            ->execute([$user_id, $product_id]);
    }
}

header('Location: ' . $redirect);
exit;
?>