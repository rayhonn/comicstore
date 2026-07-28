<?php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

require_customer();

function wishlist_ajax_response(
    array $payload,
    int $status_code = 200
): void {
    http_response_code($status_code);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wishlist_ajax_response(
        [
            'success' => false,
            'message' => 'Invalid request method.',
        ],
        405
    );
}

$csrf_token =
    $_POST['csrf_token'] ?? '';

if (
    !is_string($csrf_token) ||
    !hash_equals(
        csrf_token(),
        $csrf_token
    )
) {
    wishlist_ajax_response(
        [
            'success' => false,
            'message' =>
                'Your session has expired. Please refresh the page.',
        ],
        403
    );
}

$product_id = filter_var(
    $_POST['product_id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

$action =
    $_POST['action'] ?? null;

if (
    $product_id === false ||
    $product_id === null ||
    !is_string($action) ||
    !in_array(
        $action,
        [
            'add',
            'remove',
        ],
        true
    )
) {
    wishlist_ajax_response(
        [
            'success' => false,
            'message' =>
                'Invalid wishlist request.',
        ],
        422
    );
}

$user_id = current_user_id();

try {
    if ($action === 'add') {
        $product = $pdo->prepare("
            SELECT product_id
            FROM products
            WHERE product_id = ?
            AND product_is_available = 1
            LIMIT 1
        ");
        $product->execute([
            $product_id,
        ]);

        if (!$product->fetchColumn()) {
            wishlist_ajax_response(
                [
                    'success' => false,
                    'message' =>
                        'This product is no longer available.',
                ],
                404
            );
        }

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

    $state = $pdo->prepare("
        SELECT EXISTS (
            SELECT 1
            FROM wishlist
            WHERE wishlist_user_id = ?
            AND wishlist_product_id = ?
        )
    ");
    $state->execute([
        $user_id,
        $product_id,
    ]);

    $in_wishlist =
        (bool) $state->fetchColumn();

    wishlist_ajax_response([
        'success' => true,
        'in_wishlist' => $in_wishlist,
        'next_action' =>
            $in_wishlist
                ? 'remove'
                : 'add',
        'message' =>
            $in_wishlist
                ? 'Added to your wishlist.'
                : 'Removed from your wishlist.',
    ]);
} catch (Throwable $exception) {
    error_log(
        'Wishlist AJAX error: ' .
        $exception->getMessage()
    );

    wishlist_ajax_response(
        [
            'success' => false,
            'message' =>
                'Unable to update your wishlist.',
        ],
        500
    );
}