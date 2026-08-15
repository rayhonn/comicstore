<?php
require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/money_helper.php';
require_once __DIR__ .
    '/../includes/payment_draft_helper.php';

$user_id = current_user_id();

$payment_draft_id = filter_var(
    $_SESSION['payment_draft_id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if ($payment_draft_id === false) {
    $payment_draft_id = null;
}

$pending_checkout = null;

if ($payment_draft_id !== null) {
    $pending_checkout =
        loadPaymentDraft(
            $pdo,
            (int) $payment_draft_id,
            $user_id
        );

    if (
        $pending_checkout !== null &&
        !in_array(
            $pending_checkout['status'],
            [
                'pending',
                'checkout_open',
            ],
            true
        )
    ) {
        $pending_checkout = null;
    }
}

if ($pending_checkout === null) {
    $payment_draft_id =
        findActivePaymentDraftId(
            $pdo,
            $user_id
        );

    if ($payment_draft_id !== null) {
        $pending_checkout =
            loadPaymentDraft(
                $pdo,
                $payment_draft_id,
                $user_id
            );
    }
}

$has_pending_checkout =
    $pending_checkout !== null;

$pending_checkout_total_sen = 0;
$pending_checkout_item_count = 0;
$pending_checkout_deadline = 0;
$pending_checkout_expired = false;
$continue_payment_url = '';
$continue_payment_label = '';
$pending_checkout_status = '';

$stripe_session_id = '';
$stripe_expires_at = null;
$has_saved_stripe_session = false;

if ($has_pending_checkout) {
    $payment_draft_id =
        (int) $pending_checkout[
            'payment_draft_id'
        ];

    $_SESSION['payment_draft_id'] =
        $payment_draft_id;

    $pending_checkout_total_sen =
        moneyDecimalToSen(
            (string) $pending_checkout[
                'total'
            ]
        );

    $pending_checkout_item_count =
        count(
            $pending_checkout['items']
        );

    $stripe_session_id = trim(
        (string) (
            $pending_checkout[
                'stripe_session_id'
            ] ?? ''
        )
    );

    $stripe_expires_at =
        $pending_checkout[
            'stripe_expires_at'
        ];

    $has_saved_stripe_session =
        $stripe_session_id !== '';

    if ($has_saved_stripe_session) {
        $pending_checkout_deadline =
            is_int($stripe_expires_at)
                ? $stripe_expires_at
                : 0;

        $continue_payment_url =
            'resume_payment.php';

        $pending_checkout_status =
            'Stripe payment has not been completed.';
    } else {
        $pending_checkout_deadline =
            (int) $pending_checkout[
                'created_at'
            ] + 300;

        $continue_payment_url =
            'payment_gateway.php';

        $pending_checkout_status =
            'Checkout has not been completed.';
    }

    $pending_checkout_expired =
        $pending_checkout_deadline > 0 &&
        $pending_checkout_deadline <= time();

    if ($pending_checkout_expired) {
        $continue_payment_label =
            'Review Checkout';
    } elseif ($has_saved_stripe_session) {
        $continue_payment_label =
            'Continue Stripe Payment';
    } else {
        $continue_payment_label =
            'Continue Payment';
    }
} else {
    unset($_SESSION['pending_order']);
    unset($_SESSION['payment_draft_id']);
    unset($_SESSION['payment_lock']);
    unset($_SESSION['stripe_session_id']);
    unset($_SESSION['stripe_checkout_url']);
    unset($_SESSION['stripe_expires_at']);
}

$stmt = $pdo->prepare("
    SELECT
        o.*,
        o.order_address_recipient_name
            AS address_recipient_name,
        o.order_address_taman
            AS address_taman,
        o.order_address_street
            AS address_street,
        o.order_address_city
            AS address_city,
        CASE
            WHEN
                o.order_status = 'cancelled'
                AND o.order_payment_status =
                    'cancelled'
                AND o.order_updated_at >=
                    DATE_SUB(
                        NOW(),
                        INTERVAL 24 HOUR
                    )
            THEN 1
            ELSE 0
        END AS order_pay_again_available,
        cr.cancel_request_reason,
        cr.cancel_request_details,
        cr.cancel_request_created_at,
        CASE
            WHEN
                o.order_delivered_at IS NOT NULL
                AND NOW() >=
                    o.order_delivered_at
                AND NOW() <= DATE_ADD(
                    o.order_delivered_at,
                    INTERVAL 7 DAY
                )
            THEN 1
            ELSE 0
        END AS order_return_window_open
    FROM orders o
    LEFT JOIN order_cancellation_requests cr
        ON cr.cancel_request_order_id =
            o.order_id
        AND cr.cancel_request_user_id =
            o.order_user_id
    WHERE o.order_user_id = ?
    ORDER BY o.order_created_at DESC
");

$stmt->execute([$user_id]);

$orders = $stmt->fetchAll(
    PDO::FETCH_ASSOC
);

$allowed_filters = [
    'all',
    'pending',
    'processing',
    'shipped',
    'delivered',
    'cancelled',
];

$filter = $_GET['filter'] ?? 'all';

if (
    !is_string($filter) ||
    !in_array(
        $filter,
        $allowed_filters,
        true
    )
) {
    $filter = 'all';
}

if ($filter !== 'all') {
    $orders = array_filter(
        $orders,
        static fn(array $order): bool =>
            $order['order_status'] ===
            $filter
    );
}

$pay_again_error_code =
    is_string(
        $_GET['pay_again_error'] ?? null
    )
        ? $_GET['pay_again_error']
        : '';

$pay_again_error_messages = [
    'expired' =>
        'Pay Again is only available for 24 hours after payment cancellation.',
    'checkout_active' =>
        'Please finish or cancel your current checkout before using Pay Again.',
    'unavailable' =>
        'One or more items are unavailable, out of stock, or already owned.',
    'system' =>
        'Unable to prepare Pay Again. Please try again.',
];

$pay_again_error_message =
    $pay_again_error_messages[
        $pay_again_error_code
    ] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html { scroll-behavior: smooth; }
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">
        <p class="text-sm text-gray-400 mb-6">
            <a href="../index.php" class="hover:text-red-600 transition-colors">Home</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">My Orders</span>
        </p>

        <div class="flex gap-8 items-start">
            <?php include '../includes/customer_sidebar.php'; ?>

            <div class="flex-1 min-w-0">

                <div
                    class="customer-orders-heading mb-5"
                >
                    <p
                        class="text-[11px] font-black uppercase tracking-[0.18em] text-red-600"
                    >
                        Order Centre
                    </p>

                    <h1
                        class="mt-1 text-2xl font-black tracking-tight text-gray-900"
                    >
                        My Orders
                    </h1>

                    <p
                        class="mt-1 max-w-2xl text-sm leading-6 text-gray-400"
                    >
                        Review payment status, delivery progress,
                        invoices, returns and cancellation options.
                    </p>
                </div>

                <?php if (isset($_GET['success'])): ?>
                    <div class="bg-green-50 border border-green-200 text-green-600 text-sm px-4 py-3 rounded-xl mb-5">
                        Order placed successfully!
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['cancellation_requested'])): ?>
                    <div
                        class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5"
                    >
                        Your cancellation request was accepted. The order was cancelled and the paid amount was refunded to your MangaVault Wallet.
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['checkout_cancelled'])): ?>
                    <div
                        class="bg-green-50 border border-green-200 text-green-600 text-sm px-4 py-3 rounded-xl mb-5"
                    >
                        Pending checkout cancelled successfully.
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['payment_resume_error'])): ?>
                    <div
                        class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-5"
                    >
                        The Stripe payment session could not be checked.
                        Please try again.
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['payment_processing'])): ?>
                    <div
                        class="bg-yellow-50 border border-yellow-200 text-yellow-700 text-sm px-4 py-3 rounded-xl mb-5"
                    >
                        Your Stripe payment is being processed. The order will
                        appear here after server confirmation.
                    </div>
                <?php endif; ?>

                <?php if (
                    $pay_again_error_message !== null
                ): ?>
                    <div
                        class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-5"
                    >
                        <?= htmlspecialchars(
                            $pay_again_error_message,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </div>
                <?php endif; ?>

                <?php if ($has_pending_checkout): ?>
                    <div
                        class="bg-white border border-yellow-200 rounded-2xl shadow-sm overflow-hidden mb-6"
                        id="pendingCheckoutCard"
                        data-deadline="<?= (int) $pending_checkout_deadline ?>"
                    >
                        <div
                            class="bg-yellow-50 border-b border-yellow-100 px-6 py-4 flex items-center justify-between gap-4 flex-wrap"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="w-11 h-11 bg-yellow-100 rounded-full flex items-center justify-center text-xl"
                                >
                                    ⏳
                                </div>

                                <div>
                                    <p
                                        class="font-black text-gray-800"
                                    >
                                        Pending Checkout
                                    </p>

                                    <p
                                        class="text-xs text-gray-500"
                                    >
                                        <?= htmlspecialchars(
                                            $pending_checkout_status,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </p>
                                </div>
                            </div>

                            <span
                                class="bg-yellow-100 text-yellow-700 text-xs px-3 py-1 rounded-full font-semibold"
                            >
                                Not Completed
                            </span>
                        </div>

                        <div class="px-6 py-5">
                            <div
                                class="flex items-center justify-between gap-4 flex-wrap"
                            >
                                <div>
                                    <p
                                        class="text-xs text-gray-400 mb-1"
                                    >
                                        Amount
                                    </p>

                                    <p
                                        class="text-2xl font-black text-red-600"
                                    >
                                        RM
                                        <?= moneyFormatSen(
                                            $pending_checkout_total_sen
                                        ) ?>
                                    </p>

                                    <p
                                        class="text-xs text-gray-400 mt-1"
                                    >
                                        <?= $pending_checkout_item_count ?>
                                        item(s)
                                    </p>
                                </div>

                                <div class="text-right">
                                    <p
                                        class="text-xs text-gray-400 mb-1"
                                    >
                                        Time Remaining
                                    </p>

                                    <p
                                        class="text-xl font-black text-yellow-700"
                                        id="pendingCheckoutTimer"
                                    >
                                        <?= $pending_checkout_expired
                                            ? 'Expired'
                                            : '--:--' ?>
                                    </p>
                                </div>
                            </div>

                            <div
                                class="bg-gray-50 rounded-xl p-4 mt-5"
                            >
                                <p
                                    class="text-xs text-gray-500 leading-relaxed"
                                >
                                    Continue this checkout before it expires.
                                    Stripe payments can be resumed using the
                                    same payment session.
                                </p>
                            </div>

                            <div
                                class="flex flex-col sm:flex-row gap-3 mt-5"
                            >
                                <a
                                    href="<?= htmlspecialchars(
                                        $continue_payment_url,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-xl text-sm text-center transition-colors"
                                >
                                    <?= htmlspecialchars(
                                        $continue_payment_label,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </a>

                                <button
                                    type="button"
                                    id="cancelPendingCheckoutButton"
                                    onclick="cancelPendingCheckout()"
                                    class="flex-1 border-2 border-gray-100 hover:bg-gray-50 text-gray-600 font-semibold py-3 rounded-xl text-sm transition-colors"
                                >
                                    Cancel Checkout
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php
                $filters = [
                    'all' => 'All Orders',
                    'pending' => 'Pending',
                    'processing' => 'Processing',
                    'shipped' => 'Shipped',
                    'delivered' => 'Delivered',
                    'cancelled' => 'Cancelled'
                ];
                ?>

                <!-- Filter Tabs - Desktop -->
                <div class="hidden lg:flex bg-white rounded-2xl shadow-sm p-1 mb-6 gap-1">
                    <?php foreach ($filters as $key => $label): ?>
                    <a href="orders.php?filter=<?= $key ?>"
                        class="px-4 py-2 rounded-xl text-sm font-medium transition-colors duration-200 <?= $filter === $key ? 'bg-red-600 text-white' : 'text-gray-500 hover:text-red-600 hover:bg-gray-50' ?>">
                        <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                </div>

                <!-- Filter Pills - Mobile -->
                <div
                    class="order-filter-strip -mx-1 mb-5 overflow-x-auto px-1 pb-1 lg:hidden"
                >
                    <div class="flex w-max gap-2">
                        <?php foreach ($filters as $key => $label): ?>
                            <a
                                href="orders.php?filter=<?= $key ?>"
                                class="inline-flex min-h-[40px] items-center justify-center whitespace-nowrap rounded-xl px-4 text-xs font-bold transition-colors <?= $filter === $key
                                    ? 'bg-red-600 text-white shadow-sm'
                                    : 'border border-gray-200 bg-white text-gray-500' ?>"
                            >
                                <?= $label ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (count($orders) === 0): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                        <div class="text-6xl mb-4">📦</div>
                        <p class="text-gray-500 font-medium mb-2">
                            No placed orders found
                        </p>

                        <p class="text-gray-400 text-sm mb-6">
                            <?= $has_pending_checkout
                                ? 'Your unfinished checkout is shown above.'
                                : 'You have not placed any orders yet.' ?>
                        </p>
                        <a href="home.php" class="bg-red-600 hover:bg-red-700 text-white font-semibold px-6 py-2.5 rounded-xl text-sm transition-colors duration-200 inline-block">
                            Start Shopping
                        </a>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($orders as $order): ?>
                        <div
                            class="order-card bg-white rounded-2xl shadow-sm overflow-hidden hover:shadow-md transition-shadow duration-200"
                        >
                            <!-- Order Header -->
                            <div class="order-card-header px-6 py-4 border-b border-gray-50 flex justify-between items-center flex-wrap gap-3">
                                <div class="order-status-group flex items-center gap-4">
                                    <div>
                                        <p class="font-bold text-gray-800 text-sm">Order #<?= str_pad($order['order_id'], 4, '0', STR_PAD_LEFT) ?></p>
                                        <p class="text-xs text-gray-400"><?= date('d M Y, h:i A', strtotime($order['order_created_at'])) ?></p>
                                    </div>
                                    <?php
                                    $status_colors = [
                                        'pending'    => 'bg-yellow-100 text-yellow-700',
                                        'processing' => 'bg-blue-100 text-blue-700',
                                        'shipped'    => 'bg-purple-100 text-purple-700',
                                        'delivered'  => 'bg-green-100 text-green-700',
                                        'cancelled'  => 'bg-red-100 text-red-700'
                                    ];
                                    $color = $status_colors[$order['order_status']] ?? 'bg-gray-100 text-gray-700';
                                    $payment_status_colors = [
                                        'pending_confirmation' => 'bg-yellow-100 text-yellow-700',
                                        'confirmed'            => 'bg-green-100 text-green-700',
                                        'cancelled'            => 'bg-red-100 text-red-700',
                                    ];
                                    $payment_color = $payment_status_colors[$order['order_payment_status']] ?? 'bg-gray-100 text-gray-700';
                                    $payment_labels = [
                                        'pending_confirmation' => '⏳ Awaiting Payment Confirmation',
                                        'confirmed'            => '✅ Payment Confirmed',
                                        'cancelled'            => '❌ Payment Cancelled',
                                    ];
                                    $payment_label = $payment_labels[$order['order_payment_status']] ?? $order['order_payment_status'];

                                    $pay_again_available =
                                        (int) (
                                            $order[
                                                'order_pay_again_available'
                                            ] ?? 0
                                        ) === 1;
                                    ?>
                                    <span class="<?= $color ?> text-xs px-3 py-1 rounded-full font-semibold capitalize">
                                        <?= $order['order_status'] ?>
                                    </span>
                                    <span class="<?= $payment_color ?> text-xs px-3 py-1 rounded-full font-semibold">
                                        <?= $payment_label ?>
                                    </span>

                                    <?php if (
                                        $pay_again_available
                                    ): ?>
                                        <form
                                            method="POST"
                                            action="pay_again.php"
                                            class="inline-flex"
                                        >
                                            <?php csrf_field(); ?>

                                            <input
                                                type="hidden"
                                                name="order_id"
                                                value="<?= (int) $order['order_id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="bg-red-600 hover:bg-red-700 text-white text-xs px-3 py-1 rounded-full font-semibold transition-colors"
                                            >
                                                Pay Again
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($order['order_payment_status'] === 'pending_confirmation'): ?>
                                        <a
                                            href="payment_waiting.php?order_id=<?= (int) $order['order_id'] ?>"
                                            class="text-xs text-red-600 hover:underline font-semibold"
                                        >
                                            Confirm Now →
                                        </a>

                                        <?php if (
                                            !empty(
                                                $order[
                                                    'order_confirm_expires_at'
                                                ]
                                            ) &&
                                            strtotime(
                                                $order[
                                                    'order_confirm_expires_at'
                                                ]
                                            ) > time()
                                        ): ?>
                                            <a
                                                href="cancel_order.php?order_id=<?= (int) $order['order_id'] ?>"
                                                class="text-xs text-gray-500 hover:text-red-600 hover:underline font-semibold"
                                            >
                                                Request Cancellation
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <p class="order-card-total font-bold text-red-600">
                                    RM
                                    <?= moneyFormatSen(
                                        moneyDecimalToSen(
                                            (string) $order[
                                                'order_total_amount'
                                            ]
                                        )
                                    ) ?>
                                </p>
                            </div>

                            <!-- Order Items -->
                            <?php
                            $stmt2 = $pdo->prepare("
                                SELECT
                                    oi.*,
                                    oi.order_item_product_title
                                        AS product_title,
                                    oi.order_item_product_cover_image
                                        AS product_cover_image
                                FROM order_items oi
                                WHERE oi.order_item_order_id = ?
                            ");
                            $stmt2->execute([$order['order_id']]);
                            $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            <div class="order-items px-6 py-4">
                                <?php foreach ($items as $item): ?>
                                <div class="order-item-row flex items-center gap-4 py-3 border-b border-gray-50 last:border-0">
                                    <?php if ($item['product_cover_image']): ?>
                                        <img src="../assets/images/<?= htmlspecialchars($item['product_cover_image']) ?>"
                                             class="w-12 h-16 object-cover rounded-lg flex-shrink-0">
                                    <?php else: ?>
                                        <div class="w-12 h-16 bg-gray-100 rounded-lg flex items-center justify-center text-gray-400 text-xs font-bold flex-shrink-0">N/A</div>
                                    <?php endif; ?>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-semibold text-sm text-gray-800 truncate"><?= htmlspecialchars($item['product_title']) ?></p>
                                        <p class="text-xs text-gray-400"><?= $item['order_item_type'] === 'ebook' ? '📱 E-Book' : '📦 Physical' ?> × <?= $item['order_item_quantity'] ?></p>
                                        <p class="text-sm font-bold text-red-600 mt-1">
                                            RM
                                            <?= moneyFormatSen(
                                                moneyDecimalToSen(
                                                    (string) $item[
                                                        'order_item_price'
                                                    ]
                                                )
                                            ) ?>
                                        </p>
                                    </div>
                                    <div class="order-item-action flex-shrink-0">
                                        <?php if (
                                            $item['order_item_type'] === 'ebook' &&
                                            $order['order_payment_status'] === 'confirmed'
                                        ): ?>
                                            <a href="ebook_reader.php?item_id=<?= (int) $item['order_item_id'] ?>"
                                               class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors duration-200 inline-block">
                                                Read Online
                                            </a>
                                        <?php elseif ($item['order_item_type'] === 'physical' && $order['order_status'] === 'delivered'): ?>
                                            <?php
                                            $return_check = $pdo->prepare("SELECT return_id, return_status FROM return_requests WHERE return_item_id = ?");
                                            $return_check->execute([$item['order_item_id']]);
                                            $return_req = $return_check->fetch();
                                            ?>
                                            <?php
                                            $return_window_open =
                                                (int) $order[
                                                    'order_return_window_open'
                                                ] === 1;
                                            ?>
                                            <?php if (
                                                !$return_req &&
                                                $return_window_open
                                            ): ?>
                                                <a href="return_request.php?order_id=<?= $order['order_id'] ?>&item_id=<?= $item['order_item_id'] ?>"
                                                   class="bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors duration-200 inline-block">
                                                    ↩ Return
                                                </a>
                                            <?php elseif (!$return_req): ?>
                                                <span class="text-xs text-gray-400">Return expired</span>
                                            <?php else: ?>
                                                <span class="text-xs <?= $return_req['return_status'] === 'approved' ? 'text-green-600' : ($return_req['return_status'] === 'rejected' ? 'text-red-500' : 'text-orange-500') ?> font-medium capitalize">
                                                    Return <?= $return_req['return_status'] ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-300">—</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if (
                                $order['order_status'] === 'cancelled' &&
                                !empty(
                                    $order[
                                        'cancel_request_reason'
                                    ]
                                )
                            ): ?>
                                <div
                                    class="mx-6 mb-4 bg-red-50 border border-red-100 rounded-xl px-4 py-3"
                                >
                                    <p
                                        class="text-xs font-semibold text-red-700"
                                    >
                                        Cancellation Reason:
                                        <?= htmlspecialchars(
                                            $order[
                                                'cancel_request_reason'
                                            ],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </p>

                                    <?php if (
                                        !empty(
                                            $order[
                                                'cancel_request_details'
                                            ]
                                        )
                                    ): ?>
                                        <p
                                            class="text-xs text-red-600 mt-1"
                                        >
                                            <?= htmlspecialchars(
                                                $order[
                                                    'cancel_request_details'
                                                ],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Invoice + Actions Footer -->
                            <div class="order-card-footer px-6 py-3 border-t border-gray-50 flex items-center justify-between">
                                <div class="flex items-center gap-4">
                                    <a href="invoice.php?order_id=<?= $order['order_id'] ?>"
                                        class="flex items-center gap-1.5 text-xs text-gray-500 hover:text-red-600 transition-colors font-medium">
                                        🧾 View Invoice
                                    </a>
                                    <?php if ($order['order_has_physical'] && $order['order_status'] !== 'pending'): ?>
                                    <a href="order_tracking.php?order_id=<?= $order['order_id'] ?>"
                                        class="flex items-center gap-1.5 text-xs text-gray-500 hover:text-red-600 transition-colors font-medium">
                                        🚚 Track Order
                                    </a>
                                    <?php endif; ?>
                                </div>
                                <p class="text-xs text-gray-400">
                                    <?= count($items) ?> item(s) · 
                                    <?= $order['order_has_physical'] ? ucfirst($order['order_shipping_method'] ?? 'standard') . ' shipping' : 'Digital only' ?>
                                </p>
                            </div>

                            <!-- Mini Timeline + Tracking -->
                            <?php if ($order['order_has_physical'] && $order['order_payment_status'] === 'confirmed'): ?>
                            <div class="order-timeline-panel px-6 py-4 bg-gray-50 border-t border-gray-100">

                                <?php if ($order['address_recipient_name']): ?>
                                <p class="text-xs text-gray-500 mb-4">
                                    📦 Ship to: <span class="font-medium text-gray-700"><?= htmlspecialchars($order['address_recipient_name']) ?><?php if (!empty($order['address_taman'])): ?>, <?= htmlspecialchars($order['address_taman']) ?><?php endif; ?>, <?= htmlspecialchars($order['address_street']) ?>, <?= htmlspecialchars($order['address_city']) ?></span>
                                </p>
                                <?php endif; ?>

                                <?php if ($order['order_status'] !== 'cancelled'):
                                    $mini_steps = [
                                        ['icon' => '🛒', 'label' => 'Placed',     'done' => true],
                                        ['icon' => '✅', 'label' => 'Paid',        'done' => true],
                                        ['icon' => '📦', 'label' => 'Processing',  'done' => in_array($order['order_status'], ['processing','shipped','delivered'])],
                                        ['icon' => '🚚', 'label' => 'Shipped',     'done' => in_array($order['order_status'], ['shipped','delivered'])],
                                        ['icon' => '🎉', 'label' => 'Delivered',   'done' => $order['order_status'] === 'delivered'],
                                    ];
                                    $done_count = count(array_filter($mini_steps, fn($s) => $s['done']));
                                    $progress = $done_count > 1 ? (($done_count - 1) / (count($mini_steps) - 1)) * 100 : 0;
                                ?>
                                <!-- Horizontal Timeline -->
                                <div class="order-timeline-scroll mb-4 overflow-x-auto pb-1">
                                    <div
                                        class="order-mini-timeline relative flex min-w-[430px] items-start justify-between"
                                    >
                                    <!-- Background line -->
                                    <div class="absolute top-4 left-4 right-4 h-0.5 bg-gray-200 z-0"></div>
                                    <!-- Progress line -->
                                    <div class="absolute top-4 left-4 h-0.5 bg-red-500 z-0 transition-all" style="width: calc(<?= $progress ?>% - 8px)"></div>

                                    <?php foreach ($mini_steps as $step): ?>
                                    <div class="flex flex-col items-center z-10 flex-1">
                                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm
                                            <?= $step['done'] ? 'bg-red-600 text-white' : 'bg-gray-200' ?>">
                                            <?= $step['done'] ? $step['icon'] : '' ?>
                                        </div>
                                        <p class="text-xs mt-1 font-semibold <?= $step['done'] ? 'text-gray-700' : 'text-gray-300' ?> text-center leading-tight"><?= $step['label'] ?></p>
                                    </div>
                                    <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Tracking Number -->
                                <?php if ($order['order_tracking_number']):
                                    $courier_names = [
                                        'jnt' => 'J&T Express', 'ninja_van' => 'Ninja Van',
                                        'pos_laju' => 'Pos Laju', 'gdex' => 'GDex', 'dhl' => 'DHL Express'
                                    ];
                                    $courier_key = $order['order_courier'] ?? null;
                                    $courier_name = $courier_names[$courier_key] ?? 'Courier';
                                ?>
                                <div class="order-tracking-card flex items-center gap-3 bg-white rounded-xl p-3 border border-gray-100">
                                    <span class="text-lg">🚚</span>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs text-gray-400"><?= $courier_name ?></p>
                                        <p class="font-mono font-bold text-sm text-gray-800"><?= htmlspecialchars($order['order_tracking_number']) ?></p>
                                    </div>
                                    <a href="order_tracking.php?order_id=<?= $order['order_id'] ?>"
                                       class="bg-red-600 hover:bg-red-700 text-white text-xs font-semibold px-3 py-2 rounded-lg transition-colors flex-shrink-0">
                                        Track →
                                    </a>
                                </div>
                                <?php endif; ?>

                            </div>
                            <?php endif; ?>

                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<script>
const pendingCheckoutDeadline =
    <?= (int) $pending_checkout_deadline ?> *
    1000;

const pendingCheckoutCsrfToken =
    <?= json_encode(csrf_token()) ?>;

let pendingCheckoutTimerInterval = null;

function updatePendingCheckoutTimer() {
    const timerElement =
        document.getElementById(
            'pendingCheckoutTimer'
        );

    if (
        !timerElement ||
        pendingCheckoutDeadline <= 0
    ) {
        return;
    }

    const remainingMilliseconds =
        Math.max(
            0,
            pendingCheckoutDeadline -
                Date.now()
        );

    const remainingSeconds =
        Math.floor(
            remainingMilliseconds / 1000
        );

    if (remainingSeconds <= 0) {
        timerElement.textContent =
            'Expired';

        timerElement.classList.remove(
            'text-yellow-700'
        );

        timerElement.classList.add(
            'text-red-600'
        );

        clearInterval(
            pendingCheckoutTimerInterval
        );

        return;
    }

    const minutes = Math.floor(
        remainingSeconds / 60
    )
        .toString()
        .padStart(2, '0');

    const seconds =
        (remainingSeconds % 60)
            .toString()
            .padStart(2, '0');

    timerElement.textContent =
        minutes + ':' + seconds;

    if (remainingSeconds <= 60) {
        timerElement.classList.remove(
            'text-yellow-700'
        );

        timerElement.classList.add(
            'text-red-600'
        );
    }
}

function cancelPendingCheckout() {
    const confirmed = window.confirm(
        'Cancel this pending checkout?'
    );

    if (!confirmed) {
        return;
    }

    const button =
        document.getElementById(
            'cancelPendingCheckoutButton'
        );

    if (button) {
        button.disabled = true;
        button.textContent =
            'Cancelling...';
    }

    fetch(
        'cancel_pending_voucher.php',
        {
            method: 'POST',
            headers: {
                'Content-Type':
                    'application/x-www-form-urlencoded;charset=UTF-8',
                'Accept':
                    'application/json'
            },
            body: new URLSearchParams({
                csrf_token:
                    pendingCheckoutCsrfToken
            })
        }
    )
        .then(async response => {
            const result =
                await response.json();

            if (
                !response.ok ||
                !result.success
            ) {
                throw new Error(
                    result.message ||
                    'Unable to cancel checkout.'
                );
            }

            window.location.href =
                'orders.php?checkout_cancelled=1';
        })
        .catch(error => {
            window.alert(error.message);

            if (button) {
                button.disabled = false;
                button.textContent =
                    'Cancel Checkout';
            }
        });
}

updatePendingCheckoutTimer();

pendingCheckoutTimerInterval =
    setInterval(
        updatePendingCheckoutTimer,
        1000
    );
</script>
</body>
</html>