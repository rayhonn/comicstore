<?php
require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

$user_id = current_user_id();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: cart.php');
    exit;
}

csrf_verify();

$action = $_POST['action'] ?? '';

if ($action === 'add') {
    $product_id = filter_var(
        $_POST['product_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    $quantity = filter_var(
        $_POST['quantity'] ?? 1,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($product_id !== false && $quantity !== false) {
        $product_stmt = $pdo->prepare("
            SELECT
                p.product_type,
                p.product_is_available,
                COALESCE(
                    pp.physical_stock_quantity,
                    0
                ) AS stock_quantity
            FROM products p
            LEFT JOIN product_physical pp
                ON p.product_id = pp.physical_product_id
            WHERE p.product_id = ?
            LIMIT 1
        ");

        $product_stmt->execute([$product_id]);
        $product = $product_stmt->fetch(PDO::FETCH_ASSOC);

        if ($product && (int)$product['product_is_available'] === 1) {
            if ($product['product_type'] === 'ebook') {
                $max_quantity = 1;
            } elseif ($product['product_type'] === 'physical') {
                $max_quantity = max(
                    0,
                    (int)$product['stock_quantity']
                );
            } else {
                $max_quantity = 0;
            }

            if ($max_quantity > 0) {
                $stmt = $pdo->prepare("
                    SELECT
                        cart_item_id,
                        cart_item_quantity
                    FROM cart_items
                    WHERE cart_item_user_id = ?
                      AND cart_item_product_id = ?
                    LIMIT 1
                ");

                $stmt->execute([
                    $user_id,
                    $product_id,
                ]);

                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                $existing_quantity = $existing
                    ? (int)$existing['cart_item_quantity']
                    : 0;

                $new_quantity = min(
                    $existing_quantity + $quantity,
                    $max_quantity
                );

                if ($existing) {
                    $update_stmt = $pdo->prepare("
                        UPDATE cart_items
                        SET cart_item_quantity = ?
                        WHERE cart_item_id = ?
                          AND cart_item_user_id = ?
                    ");

                    $update_stmt->execute([
                        $new_quantity,
                        $existing['cart_item_id'],
                        $user_id,
                    ]);
                } else {
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO cart_items (
                            cart_item_user_id,
                            cart_item_product_id,
                            cart_item_quantity
                        )
                        VALUES (?, ?, ?)
                    ");

                    $insert_stmt->execute([
                        $user_id,
                        $product_id,
                        $new_quantity,
                    ]);
                }
            }
        }
    }

} elseif ($action === 'remove') {
    $cart_item_id = $_POST['cart_item_id'] ?? null;
    if ($cart_item_id) {
        $pdo->prepare("DELETE FROM cart_items WHERE cart_item_id = ? AND cart_item_user_id = ?")
            ->execute([$cart_item_id, $user_id]);
    }

} elseif ($action === 'update') {
    $cart_item_id = filter_var(
        $_POST['cart_item_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    $quantity = filter_var(
        $_POST['quantity'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($cart_item_id !== false && $quantity !== false) {
        $item_stmt = $pdo->prepare("
            SELECT
                ci.cart_item_id,
                p.product_type,
                p.product_is_available,
                COALESCE(
                    pp.physical_stock_quantity,
                    0
                ) AS stock_quantity
            FROM cart_items ci
            JOIN products p
                ON ci.cart_item_product_id = p.product_id
            LEFT JOIN product_physical pp
                ON p.product_id = pp.physical_product_id
            WHERE ci.cart_item_id = ?
              AND ci.cart_item_user_id = ?
            LIMIT 1
        ");

        $item_stmt->execute([
            $cart_item_id,
            $user_id,
        ]);

        $item = $item_stmt->fetch(PDO::FETCH_ASSOC);

        if ($item) {
            if ($quantity <= 0) {
                $delete_stmt = $pdo->prepare("
                    DELETE FROM cart_items
                    WHERE cart_item_id = ?
                      AND cart_item_user_id = ?
                ");

                $delete_stmt->execute([
                    $cart_item_id,
                    $user_id,
                ]);
            } else {
                if ((int)$item['product_is_available'] !== 1) {
                    $max_quantity = 0;
                } elseif ($item['product_type'] === 'ebook') {
                    $max_quantity = 1;
                } elseif ($item['product_type'] === 'physical') {
                    $max_quantity = max(
                        0,
                        (int)$item['stock_quantity']
                    );
                } else {
                    $max_quantity = 0;
                }

                if ($max_quantity <= 0) {
                    $delete_stmt = $pdo->prepare("
                        DELETE FROM cart_items
                        WHERE cart_item_id = ?
                          AND cart_item_user_id = ?
                    ");

                    $delete_stmt->execute([
                        $cart_item_id,
                        $user_id,
                    ]);
                } else {
                    $final_quantity = min(
                        $quantity,
                        $max_quantity
                    );

                    $update_stmt = $pdo->prepare("
                        UPDATE cart_items
                        SET cart_item_quantity = ?
                        WHERE cart_item_id = ?
                          AND cart_item_user_id = ?
                    ");

                    $update_stmt->execute([
                        $final_quantity,
                        $cart_item_id,
                        $user_id,
                    ]);
                }
            }
        }
    }
}

header('Location: cart.php');
exit;