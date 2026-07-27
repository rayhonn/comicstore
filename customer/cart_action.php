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

$action = $_POST['action'] ?? null;

if (
    !is_string($action) ||
    !in_array($action, ['add', 'remove', 'update'], true)
) {
    header('Location: cart.php');
    exit;
}

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

    if (
        $product_id === false ||
        $product_id === null ||
        $quantity === false ||
        $quantity === null
    ) {
        header('Location: cart.php');
        exit;
    }

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

    if (
        !$product ||
        (int) $product['product_is_available'] !== 1
    ) {
        header('Location: cart.php');
        exit;
    }

    if ($product['product_type'] === 'ebook') {
        $max_quantity = 1;
    } elseif ($product['product_type'] === 'physical') {
        $max_quantity = max(
            0,
            (int) $product['stock_quantity']
        );
    } else {
        $max_quantity = 0;
    }

    if ($max_quantity < 1) {
        header('Location: cart.php');
        exit;
    }

    $insert_quantity = min(
        $quantity,
        $max_quantity
    );

    $upsert_stmt = $pdo->prepare("
        INSERT INTO cart_items (
            cart_item_user_id,
            cart_item_product_id,
            cart_item_quantity
        )
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            cart_item_quantity = LEAST(
                cart_item_quantity +
                    VALUES(cart_item_quantity),
                ?
            )
    ");
    $upsert_stmt->execute([
        $user_id,
        $product_id,
        $insert_quantity,
        $max_quantity,
    ]);
} elseif ($action === 'remove') {
    $cart_item_id = filter_var(
        $_POST['cart_item_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (
        $cart_item_id !== false &&
        $cart_item_id !== null
    ) {
        $delete = $pdo->prepare("
            DELETE FROM cart_items
            WHERE cart_item_id = ?
            AND cart_item_user_id = ?
        ");
        $delete->execute([
            $cart_item_id,
            $user_id,
        ]);
    }
} else {
    $cart_item_id = filter_var(
        $_POST['cart_item_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $quantity = filter_var(
        $_POST['quantity'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    );

    if (
        $cart_item_id === false ||
        $cart_item_id === null ||
        $quantity === false ||
        $quantity === null
    ) {
        header('Location: cart.php');
        exit;
    }

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

    if (!$item) {
        header('Location: cart.php');
        exit;
    }

    if ($quantity === 0) {
        $delete = $pdo->prepare("
            DELETE FROM cart_items
            WHERE cart_item_id = ?
            AND cart_item_user_id = ?
        ");
        $delete->execute([
            $cart_item_id,
            $user_id,
        ]);
    } else {
        if ((int) $item['product_is_available'] !== 1) {
            $max_quantity = 0;
        } elseif ($item['product_type'] === 'ebook') {
            $max_quantity = 1;
        } elseif ($item['product_type'] === 'physical') {
            $max_quantity = max(
                0,
                (int) $item['stock_quantity']
            );
        } else {
            $max_quantity = 0;
        }

        if ($max_quantity < 1) {
            $delete = $pdo->prepare("
                DELETE FROM cart_items
                WHERE cart_item_id = ?
                AND cart_item_user_id = ?
            ");
            $delete->execute([
                $cart_item_id,
                $user_id,
            ]);
        } else {
            $final_quantity = min(
                $quantity,
                $max_quantity
            );

            $update = $pdo->prepare("
                UPDATE cart_items
                SET cart_item_quantity = ?
                WHERE cart_item_id = ?
                AND cart_item_user_id = ?
            ");
            $update->execute([
                $final_quantity,
                $cart_item_id,
                $user_id,
            ]);
        }
    }
}

header('Location: cart.php');
exit;