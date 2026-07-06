<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';

require_customer();

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: wishlist.php');
    exit;
}

csrf_verify();

$product_id = $_POST['product_id'] ?? null;
$action     = $_POST['action'] ?? '';
$redirect   = $_POST['redirect'] ?? 'wishlist.php';

// 只允许站内相对路径
$redirect = safe_redirect_target($redirect, 'wishlist.php');

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