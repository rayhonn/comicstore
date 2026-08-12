<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/stock_helper.php';
require_once __DIR__ . '/../includes/voucher_helper.php';
require_once __DIR__ . '/../includes/notifications.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

$user_id = current_user_id();

$order_id = filter_input(
    INPUT_GET,
    'order_id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if (
    $order_id === false ||
    $order_id === null
) {
    redirect_to(
        app_path('customer/orders.php')
    );
}

$order_id = (int) $order_id;

$reason_options = [
    'Ordered by mistake',
    'Selected the wrong item',
    'Duplicate order',
    'Changed my mind',
    'Other',
];

$order_stmt = $pdo->prepare("
    SELECT
        order_id,
        order_user_id,
        order_total_amount,
        order_status,
        order_payment_status,
        order_confirm_expires_at,
        order_voucher_code
    FROM orders
    WHERE order_id = ?
    AND order_user_id = ?
    LIMIT 1
");

$order_stmt->execute([
    $order_id,
    $user_id,
]);

$order = $order_stmt->fetch(
    PDO::FETCH_ASSOC
);

if (!$order) {
    redirect_to(
        app_path('customer/orders.php')
    );
}

$existing_stmt = $pdo->prepare("
    SELECT
        cancel_request_reason,
        cancel_request_details,
        cancel_request_created_at
    FROM order_cancellation_requests
    WHERE cancel_request_order_id = ?
    AND cancel_request_user_id = ?
    LIMIT 1
");

$existing_stmt->execute([
    $order_id,
    $user_id,
]);

$existing_request = $existing_stmt->fetch(
    PDO::FETCH_ASSOC
);

$can_cancel =
    $order['order_status'] === 'pending' &&
    $order['order_payment_status'] ===
        'pending_confirmation' &&
    !empty(
        $order['order_confirm_expires_at']
    ) &&
    strtotime(
        $order['order_confirm_expires_at']
    ) > time() &&
    !$existing_request;

$error = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {
    csrf_verify();

    if (!$can_cancel) {
        $error =
            'This order can no longer be cancelled.';
    } else {
        $reason =
            $_POST['reason'] ?? '';

        $details_raw =
            $_POST['details'] ?? '';

        if (
            !is_string($reason) ||
            !in_array(
                $reason,
                $reason_options,
                true
            ) ||
            !is_string($details_raw)
        ) {
            $error =
                'Please select a valid cancellation reason.';
        } else {
            $details = trim(
                $details_raw
            );

            $details_length =
                function_exists('mb_strlen')
                    ? mb_strlen(
                        $details,
                        'UTF-8'
                    )
                    : strlen($details);

            if ($details_length > 500) {
                $error =
                    'Additional details cannot exceed 500 characters.';
            } elseif (
                $reason === 'Other' &&
                $details === ''
            ) {
                $error =
                    'Please provide details for the selected reason.';
            }
        }

        if ($error === '') {
            try {
                $pdo->beginTransaction();

                $locked_stmt = $pdo->prepare("
                    SELECT
                        order_id,
                        order_status,
                        order_payment_status,
                        order_confirm_expires_at,
                        order_voucher_code
                    FROM orders
                    WHERE order_id = ?
                    AND order_user_id = ?
                    FOR UPDATE
                ");

                $locked_stmt->execute([
                    $order_id,
                    $user_id,
                ]);

                $locked_order =
                    $locked_stmt->fetch(
                        PDO::FETCH_ASSOC
                    );

                if (
                    !$locked_order ||
                    $locked_order['order_status'] !==
                        'pending' ||
                    $locked_order[
                        'order_payment_status'
                    ] !==
                        'pending_confirmation' ||
                    empty(
                        $locked_order[
                            'order_confirm_expires_at'
                        ]
                    ) ||
                    strtotime(
                        $locked_order[
                            'order_confirm_expires_at'
                        ]
                    ) <= time()
                ) {
                    throw new RuntimeException(
                        'This order can no longer be cancelled.'
                    );
                }

                $duplicate_stmt = $pdo->prepare("
                    SELECT cancel_request_id
                    FROM order_cancellation_requests
                    WHERE cancel_request_order_id = ?
                    LIMIT 1
                    FOR UPDATE
                ");

                $duplicate_stmt->execute([
                    $order_id,
                ]);

                if (
                    $duplicate_stmt->fetchColumn() !==
                    false
                ) {
                    throw new RuntimeException(
                        'A cancellation request has already been submitted.'
                    );
                }

                $request_stmt = $pdo->prepare("
                    INSERT INTO order_cancellation_requests (
                        cancel_request_order_id,
                        cancel_request_user_id,
                        cancel_request_reason,
                        cancel_request_details
                    )
                    VALUES (?, ?, ?, ?)
                ");

                $request_stmt->execute([
                    $order_id,
                    $user_id,
                    $reason,
                    $details !== ''
                        ? $details
                        : null,
                ]);

                $cancel_stmt = $pdo->prepare("
                    UPDATE orders
                    SET order_status = 'cancelled',
                        order_payment_status =
                            'cancelled',
                        order_confirm_token = NULL,
                        order_confirm_expires_at =
                            NULL
                    WHERE order_id = ?
                    AND order_user_id = ?
                    AND order_status = 'pending'
                    AND order_payment_status =
                        'pending_confirmation'
                ");

                $cancel_stmt->execute([
                    $order_id,
                    $user_id,
                ]);

                if (
                    $cancel_stmt->rowCount() !== 1
                ) {
                    throw new RuntimeException(
                        'The order has already been processed.'
                    );
                }

                restoreOrderPhysicalStock(
                    $pdo,
                    $order_id
                );

                restoreOrderVoucherUsage(
                    $pdo,
                    $locked_order[
                        'order_voucher_code'
                    ] ?? null,
                    $order_id,
                    $user_id
                );

                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if (
                    $exception instanceof
                        RuntimeException
                ) {
                    $error =
                        $exception->getMessage();
                } else {
                    app_error_log(
                        'Customer order cancellation failed for order ' .
                        $order_id .
                        ': ' .
                        $exception->getMessage()
                    );

                    $error =
                        'Unable to cancel the order. Please try again.';
                }
            }

            if ($error === '') {
                $order_num =
                    '#' .
                    str_pad(
                        (string) $order_id,
                        4,
                        '0',
                        STR_PAD_LEFT
                    );

                try {
                    sendNotification(
                        $pdo,
                        $user_id,
                        'Order Cancelled',
                        "Your cancellation request for order $order_num has been accepted. Reason: $reason.",
                        'order'
                    );
                } catch (Throwable $exception) {
                    app_error_log(
                        'Cancellation notification failed for order ' .
                        $order_id .
                        ': ' .
                        $exception->getMessage()
                    );
                }

                unset(
                    $_SESSION['payment_lock']
                );

                header(
                    'Location: orders.php?cancellation_requested=1'
                );
                exit;
            }
        }
    }
}

$order_num =
    '#' .
    str_pad(
        (string) $order_id,
        4,
        '0',
        STR_PAD_LEFT
    );
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Request Cancellation - MangaVault</title>

    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-xl mx-auto px-6 py-10">

        <a
            href="orders.php"
            class="inline-flex items-center text-sm text-gray-500 hover:text-red-600 mb-5"
        >
            ← Back to My Orders
        </a>

        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">

            <div class="px-6 py-5 border-b border-gray-100">
                <h1 class="text-xl font-black text-gray-800">
                    Request Cancellation
                </h1>

                <p class="text-sm text-gray-500 mt-1">
                    Order <?= htmlspecialchars(
                        $order_num,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>
            </div>

            <div class="p-6">

                <?php if ($error !== ''): ?>
                    <div
                        class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-5"
                    >
                        <?= htmlspecialchars(
                            $error,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </div>
                <?php endif; ?>

                <?php if ($existing_request): ?>
                    <div
                        class="bg-gray-50 border border-gray-200 rounded-xl p-4"
                    >
                        <p class="font-semibold text-gray-700">
                            Cancellation request already submitted
                        </p>

                        <p class="text-sm text-gray-500 mt-2">
                            Reason:
                            <?= htmlspecialchars(
                                $existing_request[
                                    'cancel_request_reason'
                                ],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </p>

                        <?php if (
                            !empty(
                                $existing_request[
                                    'cancel_request_details'
                                ]
                            )
                        ): ?>
                            <p class="text-sm text-gray-500 mt-1">
                                Details:
                                <?= htmlspecialchars(
                                    $existing_request[
                                        'cancel_request_details'
                                    ],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                <?php elseif (!$can_cancel): ?>

                    <div
                        class="bg-gray-50 border border-gray-200 rounded-xl p-4"
                    >
                        <p class="font-semibold text-gray-700">
                            Cancellation is no longer available.
                        </p>

                        <p class="text-sm text-gray-500 mt-2">
                            Orders cannot be cancelled after payment confirmation.
                        </p>
                    </div>

                <?php else: ?>

                    <div
                        class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-5"
                    >
                        <p class="text-sm font-semibold text-yellow-800">
                            Cancellation is only available before payment confirmation.
                        </p>

                        <p class="text-xs text-yellow-700 mt-1">
                            Once payment is confirmed, this action will be permanently locked.
                        </p>
                    </div>

                    <form
                        method="POST"
                        onsubmit="return confirm('Confirm cancellation request for this order? This action cannot be undone.');"
                        class="space-y-5"
                    >
                        <?php csrf_field(); ?>

                        <div>
                            <label
                                for="reason"
                                class="block text-sm font-semibold text-gray-700 mb-2"
                            >
                                Cancellation Reason
                            </label>

                            <select
                                id="reason"
                                name="reason"
                                required
                                class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm bg-white focus:outline-none focus:border-red-400"
                            >
                                <option value="">
                                    Select a reason
                                </option>

                                <?php foreach ($reason_options as $reason_option): ?>
                                    <option
                                        value="<?= htmlspecialchars(
                                            $reason_option,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                    >
                                        <?= htmlspecialchars(
                                            $reason_option,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label
                                for="details"
                                class="block text-sm font-semibold text-gray-700 mb-2"
                            >
                                Additional Details
                                <span class="text-gray-400 font-normal">
                                    (optional)
                                </span>
                            </label>

                            <textarea
                                id="details"
                                name="details"
                                maxlength="500"
                                rows="4"
                                placeholder="Provide additional information if needed."
                                class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm resize-none focus:outline-none focus:border-red-400"
                            ></textarea>
                        </div>

                        <div class="flex gap-3">
                            <a
                                href="orders.php"
                                class="flex-1 text-center border border-gray-200 text-gray-600 font-semibold py-3 rounded-xl text-sm hover:bg-gray-50 transition-colors"
                            >
                                Keep Order
                            </a>

                            <button
                                type="submit"
                                class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-xl text-sm transition-colors"
                            >
                                Request Cancellation
                            </button>
                        </div>
                    </form>

                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>