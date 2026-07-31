<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/ebook_helper.php';

$userId = current_user_id();

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

try {
    if ($action === 'add') {
        $productId = filter_var(
            $_POST['product_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $quantity = filter_var(
            $_POST['quantity'] ?? 1,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => 1000000,
                ],
            ]
        );

        if (
            $productId === false ||
            $productId === null ||
            $quantity === false ||
            $quantity === null
        ) {
            header('Location: cart.php');
            exit;
        }

        $productId = (int) $productId;
        $quantity = (int) $quantity;

        $pdo->beginTransaction();

        $productStatement = $pdo->prepare("
            SELECT
                p.product_type,
                p.product_is_available,
                COALESCE(
                    pp.physical_stock_quantity,
                    0
                ) AS stock_quantity
            FROM products p
            LEFT JOIN product_physical pp
                ON pp.physical_product_id = p.product_id
            WHERE p.product_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $productStatement->execute([$productId]);
        $product = $productStatement->fetch(PDO::FETCH_ASSOC);

        if (
            !$product ||
            (int) $product['product_is_available'] !== 1
        ) {
            throw new RuntimeException(
                'This product is not available.'
            );
        }

        if ($product['product_type'] === 'ebook') {
            assertCustomerCanPurchaseEbook(
                $pdo,
                $userId,
                $productId,
                true
            );
            $maximumQuantity = 1;
        } elseif ($product['product_type'] === 'physical') {
            $maximumQuantity = max(
                0,
                (int) $product['stock_quantity']
            );
        } else {
            throw new RuntimeException(
                'This product type is invalid.'
            );
        }

        if ($maximumQuantity < 1) {
            throw new RuntimeException(
                'This product is currently unavailable.'
            );
        }

        $insertQuantity = min(
            $quantity,
            $maximumQuantity
        );

        $upsertStatement = $pdo->prepare("
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
        $upsertStatement->execute([
            $userId,
            $productId,
            $insertQuantity,
            $maximumQuantity,
        ]);

        $pdo->commit();
    } elseif ($action === 'remove') {
        $cartItemId = filter_var(
            $_POST['cart_item_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (
            $cartItemId !== false &&
            $cartItemId !== null
        ) {
            $delete = $pdo->prepare("
                DELETE FROM cart_items
                WHERE cart_item_id = ?
                AND cart_item_user_id = ?
            ");
            $delete->execute([
                $cartItemId,
                $userId,
            ]);
        }
    } else {
        $cartItemId = filter_var(
            $_POST['cart_item_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $quantity = filter_var(
            $_POST['quantity'] ?? null,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 0,
                    'max_range' => 1000000,
                ],
            ]
        );

        if (
            $cartItemId === false ||
            $cartItemId === null ||
            $quantity === false ||
            $quantity === null
        ) {
            header('Location: cart.php');
            exit;
        }

        $cartItemId = (int) $cartItemId;
        $quantity = (int) $quantity;

        $pdo->beginTransaction();

        $itemStatement = $pdo->prepare("
            SELECT
                ci.cart_item_id,
                ci.cart_item_product_id,
                p.product_type,
                p.product_is_available,
                COALESCE(
                    pp.physical_stock_quantity,
                    0
                ) AS stock_quantity
            FROM cart_items ci
            JOIN products p
                ON p.product_id =
                    ci.cart_item_product_id
            LEFT JOIN product_physical pp
                ON pp.physical_product_id =
                    p.product_id
            WHERE ci.cart_item_id = ?
            AND ci.cart_item_user_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $itemStatement->execute([
            $cartItemId,
            $userId,
        ]);
        $item = $itemStatement->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            $pdo->commit();
            header('Location: cart.php');
            exit;
        }

        if ($quantity === 0) {
            $maximumQuantity = 0;
        } elseif (
            (int) $item['product_is_available'] !== 1
        ) {
            $maximumQuantity = 0;
        } elseif ($item['product_type'] === 'ebook') {
            try {
                assertCustomerCanPurchaseEbook(
                    $pdo,
                    $userId,
                    (int) $item['cart_item_product_id'],
                    true
                );
                $maximumQuantity = 1;
            } catch (RuntimeException $exception) {
                $maximumQuantity = 0;
            }
        } elseif ($item['product_type'] === 'physical') {
            $maximumQuantity = max(
                0,
                (int) $item['stock_quantity']
            );
        } else {
            $maximumQuantity = 0;
        }

        if ($maximumQuantity < 1) {
            $delete = $pdo->prepare("
                DELETE FROM cart_items
                WHERE cart_item_id = ?
                AND cart_item_user_id = ?
            ");
            $delete->execute([
                $cartItemId,
                $userId,
            ]);
        } else {
            $finalQuantity = min(
                $quantity,
                $maximumQuantity
            );

            $update = $pdo->prepare("
                UPDATE cart_items
                SET cart_item_quantity = ?
                WHERE cart_item_id = ?
                AND cart_item_user_id = ?
            ");
            $update->execute([
                $finalQuantity,
                $cartItemId,
                $userId,
            ]);
        }

        $pdo->commit();
    }
} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if (
        $action === 'add' &&
        isset($productId) &&
        is_int($productId)
    ) {
        $message = $exception->getMessage() ===
            'You already own this e-book.'
                ? 'owned'
                : 'unavailable';

        header(
            'Location: product_detail.php?id=' .
            $productId .
            '&ebook_status=' .
            $message
        );
        exit;
    }
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    app_error_log(
        'Cart action failed for customer ' .
        $userId . ': ' .
        $exception->getMessage()
    );
}

header('Location: cart.php');
exit;
