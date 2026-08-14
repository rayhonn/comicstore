<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/ebook_helper.php';

final class PayAgainException extends RuntimeException
{
}

function redirectPayAgainError(string $code): void
{
    redirect_to(
        app_path(
            'customer/orders.php?pay_again_error=' .
            rawurlencode($code)
        )
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(
        app_path('customer/orders.php')
    );
}

csrf_verify();

$userId = current_user_id();

$orderId = filter_var(
    $_POST['order_id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if (
    $orderId === false ||
    $orderId === null
) {
    redirectPayAgainError('expired');
}

$orderId = (int) $orderId;

try {
    $pdo->beginTransaction();

    $customerLock = $pdo->prepare("
        SELECT user_id
        FROM users
        WHERE user_id = ?
        FOR UPDATE
    ");

    $customerLock->execute([$userId]);

    if ($customerLock->fetchColumn() === false) {
        throw new PayAgainException('expired');
    }

    $activeDraftStatement = $pdo->prepare("
        SELECT payment_draft_id
        FROM payment_drafts
        WHERE payment_draft_user_id = ?
        AND payment_draft_status IN (
            'pending',
            'checkout_open',
            'paid'
        )
        LIMIT 1
        FOR UPDATE
    ");

    $activeDraftStatement->execute([$userId]);

    if (
        $activeDraftStatement->fetchColumn()
        !== false
    ) {
        throw new PayAgainException(
            'checkout_active'
        );
    }

    $orderStatement = $pdo->prepare("
        SELECT order_id
        FROM orders
        WHERE order_id = ?
        AND order_user_id = ?
        AND order_status = 'cancelled'
        AND order_payment_status = 'cancelled'
        AND order_updated_at >= DATE_SUB(
            NOW(),
            INTERVAL 24 HOUR
        )
        LIMIT 1
        FOR UPDATE
    ");

    $orderStatement->execute([
        $orderId,
        $userId,
    ]);

    if ($orderStatement->fetchColumn() === false) {
        throw new PayAgainException('expired');
    }

    $itemStatement = $pdo->prepare("
        SELECT
            oi.order_item_product_id AS product_id,
            oi.order_item_quantity AS quantity,
            oi.order_item_type AS original_type,
            p.product_type,
            p.product_is_available,
            COALESCE(
                pp.physical_stock_quantity,
                0
            ) AS stock_quantity
        FROM order_items oi
        LEFT JOIN products p
            ON p.product_id =
                oi.order_item_product_id
        LEFT JOIN product_physical pp
            ON pp.physical_product_id =
                p.product_id
        WHERE oi.order_item_order_id = ?
        ORDER BY oi.order_item_id ASC
        FOR UPDATE
    ");

    $itemStatement->execute([$orderId]);

    $items = $itemStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

    if ($items === []) {
        throw new PayAgainException('unavailable');
    }

    $validatedItems = [];

    foreach ($items as $item) {
        $productId = (int) $item['product_id'];
        $quantity = (int) $item['quantity'];
        $productType = (string) $item[
            'product_type'
        ];

        if (
            $productId < 1 ||
            $quantity < 1 ||
            (int) $item[
                'product_is_available'
            ] !== 1 ||
            $productType !==
                (string) $item['original_type']
        ) {
            throw new PayAgainException(
                'unavailable'
            );
        }

        if ($productType === 'ebook') {
            if ($quantity !== 1) {
                throw new PayAgainException(
                    'unavailable'
                );
            }

            try {
                assertCustomerCanPurchaseEbook(
                    $pdo,
                    $userId,
                    $productId,
                    true
                );
            } catch (PDOException $exception) {
                throw $exception;
            } catch (RuntimeException $exception) {
                throw new PayAgainException(
                    'unavailable'
                );
            }

            $maximumQuantity = 1;
        } elseif ($productType === 'physical') {
            $maximumQuantity = max(
                0,
                (int) $item['stock_quantity']
            );

            if ($maximumQuantity < $quantity) {
                throw new PayAgainException(
                    'unavailable'
                );
            }
        } else {
            throw new PayAgainException(
                'unavailable'
            );
        }

        $validatedItems[] = [
            'product_id' => $productId,
            'quantity' => $quantity,
            'maximum_quantity' =>
                $maximumQuantity,
        ];
    }

    $upsertStatement = $pdo->prepare("
        INSERT INTO cart_items (
            cart_item_user_id,
            cart_item_product_id,
            cart_item_quantity
        )
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            cart_item_quantity = LEAST(
                GREATEST(
                    cart_item_quantity,
                    VALUES(cart_item_quantity)
                ),
                ?
            )
    ");

    foreach ($validatedItems as $item) {
        $upsertStatement->execute([
            $userId,
            $item['product_id'],
            $item['quantity'],
            $item['maximum_quantity'],
        ]);
    }

    $pdo->commit();

    redirect_to(
        app_path('customer/cart.php')
    );
} catch (PayAgainException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    redirectPayAgainError(
        $exception->getMessage()
    );
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    app_error_log(
        'Pay Again failed for customer ' .
        $userId .
        ', order ' .
        $orderId .
        ': ' .
        $exception->getMessage()
    );

    redirectPayAgainError('system');
}