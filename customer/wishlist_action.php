<?php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

require_customer();

$user_id = current_user_id();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: wishlist.php');
    exit;
}

csrf_verify();

$product_id = filter_var(
    $_POST['product_id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

$action = $_POST['action'] ?? null;
$redirect_raw = $_POST['redirect'] ?? 'wishlist.php';

if (
    $product_id === false ||
    $product_id === null ||
    !is_string($action) ||
    !in_array($action, ['add', 'remove'], true) ||
    !is_string($redirect_raw) ||
    strlen($redirect_raw) > 255
) {
    header('Location: wishlist.php');
    exit;
}

$redirect = safe_redirect_target(
    $redirect_raw,
    'wishlist.php'
);

if ($action === 'add') {
    $product = $pdo->prepare("
        SELECT product_id
        FROM products
        WHERE product_id = ?
        AND product_is_available = 1
        LIMIT 1
    ");
    $product->execute([$product_id]);

    if ($product->fetchColumn()) {
        $insert = $pdo->prepare("
            INSERT IGNORE INTO wishlist (
                wishlist_user_id,
                wishlist_product_id
            )
            VALUES (?, ?)
        ");
        $insert->execute([
            $user_id,
            $product_id,
        ]);
    }
} else {
    $delete = $pdo->prepare("
        DELETE FROM wishlist
        WHERE wishlist_user_id = ?
        AND wishlist_product_id = ?
    ");
    $delete->execute([
        $user_id,
        $product_id,
    ]);
}

header('Location: ' . $redirect);
exit;