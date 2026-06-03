<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$id = $_GET['id'] ?? null;
if ($id) {
    // Get product title for log
    $stmt = $pdo->prepare("SELECT product_title FROM products WHERE product_id = ?");
    $stmt->execute([$id]);
    $product = $stmt->fetch();

    // Delete genres
    $pdo->prepare("DELETE FROM product_genres WHERE product_genres_product_id = ?")->execute([$id]);
    
    // Delete physical or ebook
    $pdo->prepare("DELETE FROM product_physical WHERE physical_product_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM product_ebook WHERE ebook_product_id = ?")->execute([$id]);
    
    // Delete product
    $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$id]);

    // Log admin action
    $pdo->prepare("INSERT INTO admin_logs (log_admin_id, log_action, log_target_type, log_target_id, log_details) VALUES (?, 'delete_product', 'product', ?, ?)")
        ->execute([$_SESSION['user_id'], $id, "Deleted product: " . ($product['product_title'] ?? '')]);
}

header('Location: products.php?success=1');
exit;
?>