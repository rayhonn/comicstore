<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/stripe_config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/voucher_helper.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/config.php';

function clearCheckoutSessionState(): void
{
    unset($_SESSION['pending_order']);
    unset($_SESSION['payment_lock']);
    unset($_SESSION['stripe_session_id']);
    unset($_SESSION['stripe_checkout_url']);
    unset($_SESSION['stripe_expires_at']);
}

if (!isset($_SESSION['pending_order'])) {
    redirect_to(app_path('customer/cart.php'));
}

$order = $_SESSION['pending_order'];
$user_id = current_user_id();

if (
    !is_array($order) ||
    (int) ($order['user_id'] ?? 0) !== $user_id
) {
    clearCheckoutSessionState();
    redirect_to(app_path('customer/cart.php'));
}

$total = (float) ($order['total'] ?? 0);

$stripe_session_id =
    $_SESSION['stripe_session_id'] ?? null;

$stripe_checkout_url =
    $_SESSION['stripe_checkout_url'] ?? null;

$stripe_expires_at = filter_var(
    $_SESSION['stripe_expires_at'] ?? null,
    FILTER_VALIDATE_INT
);

$has_stripe_session_id =
    is_string($stripe_session_id) &&
    $stripe_session_id !== '';

$has_saved_stripe_session =
    $has_stripe_session_id &&
    is_string($stripe_checkout_url) &&
    $stripe_checkout_url !== '' &&
    $stripe_expires_at !== false &&
    $stripe_expires_at !== null;

if (
    $has_stripe_session_id &&
    (
        !$has_saved_stripe_session ||
        $stripe_expires_at <= time()
    )
) {
    redirect_to(
        app_path(
            'customer/resume_payment.php'
        )
    );
}

$has_active_stripe_session =
    $has_saved_stripe_session &&
    $stripe_expires_at > time();

if (
    !isset($_SESSION['payment_lock']) ||
    !is_array($_SESSION['payment_lock']) ||
    (int) (
        $_SESSION['payment_lock']['user_id'] ?? 0
    ) !== $user_id
) {
    $_SESSION['payment_lock'] = [
        'user_id' => $user_id,
        'locked_at' => time(),
    ];

    $voucher_id = filter_var(
        $order['voucher_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($voucher_id) {
        $set_pending = $pdo->prepare("
            UPDATE user_vouchers
            SET uv_status = 'pending',
                uv_pending_at = NOW()
            WHERE uv_voucher_id = ?
            AND uv_user_id = ?
            AND uv_is_used = 0
        ");

        $set_pending->execute([
            $voucher_id,
            $user_id,
        ]);
    }
}

$lock_locked_at = (int) (
    $_SESSION['payment_lock']['locked_at']
    ?? time()
);

$elapsed = time() - $lock_locked_at;

if (
    !$has_active_stripe_session &&
    $elapsed >= 300
) {
    restorePendingUserVoucher(
        $pdo,
        $order['voucher_id'] ?? null,
        $user_id
    );
    clearCheckoutSessionState();

    redirect_to(
        app_path('customer/cart.php?timeout=1')
    );
}

$stripe_error = null;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['pay_now'])
) {
    csrf_verify();

    if ($has_active_stripe_session) {
        header(
            'Location: ' . $stripe_checkout_url
        );
        exit;
    }

    \Stripe\Stripe::setApiKey(
        STRIPE_SECRET_KEY
    );

    $app_url = rtrim(APP_URL, '/');
    $line_items = [];

    foreach ($order['items'] as $item) {
        $line_items[] = [
            'price_data' => [
                'currency' => STRIPE_CURRENCY,
                'product_data' => [
                    'name' =>
                        $item['product_title'],
                    'description' =>
                        ucfirst(
                            $item['product_type']
                        ) .
                        ' - MangaVault',
                ],
                'unit_amount' => (int) round(
                    (float) $item[
                        'product_price'
                    ] * 100
                ),
            ],
            'quantity' => (int) $item[
                'cart_item_quantity'
            ],
        ];
    }

    if (
        !empty($order['has_physical']) &&
        (float) $order['shipping_fee'] > 0
    ) {
        $line_items[] = [
            'price_data' => [
                'currency' => STRIPE_CURRENCY,
                'product_data' => [
                    'name' => 'Shipping Fee',
                ],
                'unit_amount' => (int) round(
                    (float) $order[
                        'shipping_fee'
                    ] * 100
                ),
            ],
            'quantity' => 1,
        ];
    }

    if (
        !empty($order['voucher_code']) &&
        (float) (
            $order['discount_amount'] ?? 0
        ) > 0
    ) {
        $line_items = [[
            'price_data' => [
                'currency' => STRIPE_CURRENCY,
                'product_data' => [
                    'name' => 'MangaVault Order',
                    'description' =>
                        'Includes voucher discount (' .
                        $order['voucher_code'] .
                        ' -RM' .
                        number_format(
                            (float) $order[
                                'discount_amount'
                            ],
                            2
                        ) .
                        ')',
                ],
                'unit_amount' => (int) round(
                    $total * 100
                ),
            ],
            'quantity' => 1,
        ]];
    }

    try {
        $session_expires_at =
            time() + 1860;

        $checkout_session =
            \Stripe\Checkout\Session::create([
                'payment_method_types' => [
                    'card',
                ],
                'line_items' => $line_items,
                'mode' => 'payment',
                'client_reference_id' =>
                    (string) $user_id,
                'success_url' =>
                    $app_url .
                    '/customer/payment_success.php' .
                    '?session_id=' .
                    '{CHECKOUT_SESSION_ID}',
                'cancel_url' =>
                    $app_url .
                    '/customer/payment_cancel.php',
                'expires_at' =>
                    $session_expires_at,
                'custom_text' => [
                    'submit' => [
                        'message' =>
                            'This payment session expires in about 30 minutes. You can resume it from My Orders before it expires.',
                    ],
                ],
            ]);

        $_SESSION['stripe_session_id'] =
            $checkout_session->id;

        $_SESSION['stripe_checkout_url'] =
            $checkout_session->url;

        $_SESSION['stripe_expires_at'] =
            (int) $checkout_session->expires_at;

        header(
            'Location: ' .
            $checkout_session->url
        );
        exit;
    } catch (
        \Stripe\Exception\ApiErrorException $e
    ) {
        error_log(
            'Stripe Checkout Session creation failed: ' .
            $e->getMessage()
        );

        $stripe_error =
            'Unable to open the Stripe payment page.';
    }
}

if ($has_active_stripe_session) {
    $timer_deadline =
        (int) $stripe_expires_at;

    $timer_total = 1860;

    $timer_title =
        'Complete Stripe payment within';

    $timer_description =
        'Your Stripe payment session is valid for 30 minutes';

    $button_label =
        'Continue with Stripe';
} else {
    $timer_deadline =
        $lock_locked_at + 300;

    $timer_total = 300;

    $timer_title =
        'Complete checkout within';

    $timer_description =
        'Your checkout details are held for 5 minutes';

    $button_label =
        'Pay with Stripe';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Complete Payment - MangaVault
    </title>

    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body {
            opacity: 0;
            animation: fadeIn 0.4s ease forwards;
        }

        @keyframes fadeIn {
            to {
                opacity: 1;
            }
        }
    </style>
</head>

<body class="bg-[#F5F0EB] min-h-screen">

    <?php
    include __DIR__ .
        '/../includes/customer_navbar.php';
    ?>

    <div
        class="bg-yellow-50 border-b border-yellow-200 px-6 py-3"
    >
        <div
            class="max-w-4xl mx-auto flex items-center justify-between"
        >
            <div class="flex items-center gap-3">
                <span class="text-xl">
                    ⏳
                </span>

                <div>
                    <p
                        class="text-sm font-semibold text-yellow-800"
                    >
                        <?= htmlspecialchars(
                            $timer_title,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>

                    <p
                        class="text-xs text-yellow-600"
                    >
                        <?= htmlspecialchars(
                            $timer_description,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>
                </div>
            </div>

            <div
                class="text-2xl font-black text-yellow-700"
                id="timerDisplay"
            >
                00:00
            </div>
        </div>

        <div class="max-w-4xl mx-auto mt-2">
            <div
                class="h-1.5 bg-yellow-200 rounded-full overflow-hidden"
            >
                <div
                    id="timerBar"
                    class="h-full bg-yellow-500 rounded-full transition-all duration-1000"
                    style="width:100%"
                ></div>
            </div>
        </div>
    </div>

    <div
        class="max-w-4xl mx-auto px-6 py-8"
    >
        <div class="text-center mb-6">
            <h1
                class="text-2xl font-black text-gray-800"
            >
                Complete Your Payment
            </h1>

            <p
                class="text-gray-400 text-sm mt-1"
            >
                Review your order before proceeding to payment
            </p>
        </div>

        <?php if ($stripe_error): ?>
            <div
                class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-5 max-w-2xl mx-auto"
            >
                <?= htmlspecialchars(
                    $stripe_error,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>
        <?php endif; ?>

        <div
            class="flex flex-col lg:flex-row gap-6"
        >
            <div
                class="flex-1 bg-white rounded-2xl shadow-sm p-6"
            >
                <h2
                    class="font-black text-gray-800 mb-5 flex items-center gap-2"
                >
                    <span>🛒</span>
                    Order Summary
                </h2>

                <div class="space-y-4 mb-6">
                    <?php
                    foreach (
                        $order['items'] as $item
                    ):
                    ?>
                        <div
                            class="flex items-center gap-4"
                        >
                            <?php
                            if (
                                !empty(
                                    $item[
                                        'product_cover_image'
                                    ]
                                )
                            ):
                            ?>
                                <img
                                    src="../assets/images/<?= htmlspecialchars(
                                        $item[
                                            'product_cover_image'
                                        ],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    class="w-12 h-16 object-cover rounded-lg flex-shrink-0"
                                    alt=""
                                >
                            <?php else: ?>
                                <div
                                    class="w-12 h-16 bg-gray-100 rounded-lg flex-shrink-0 flex items-center justify-center text-gray-400 text-xs"
                                >
                                    N/A
                                </div>
                            <?php endif; ?>

                            <div class="flex-1">
                                <p
                                    class="font-semibold text-sm text-gray-800"
                                >
                                    <?= htmlspecialchars(
                                        $item[
                                            'product_title'
                                        ],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>

                                <p
                                    class="text-xs text-gray-400"
                                >
                                    <?= $item[
                                        'product_type'
                                    ] === 'ebook'
                                        ? '📱 E-Book'
                                        : '📦 Physical' ?>

                                    · Qty:
                                    <?= (int) $item[
                                        'cart_item_quantity'
                                    ] ?>
                                </p>
                            </div>

                            <p
                                class="font-bold text-gray-800 text-sm"
                            >
                                RM
                                <?= number_format(
                                    (float) $item[
                                        'product_price'
                                    ] *
                                    (int) $item[
                                        'cart_item_quantity'
                                    ],
                                    2
                                ) ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div
                    class="border-t border-gray-100 pt-4 space-y-2"
                >
                    <div
                        class="flex justify-between text-sm text-gray-500"
                    >
                        <span>Subtotal</span>

                        <span>
                            RM
                            <?= number_format(
                                $total -
                                (float) $order[
                                    'shipping_fee'
                                ] +
                                (float) (
                                    $order[
                                        'discount_amount'
                                    ] ?? 0
                                ),
                                2
                            ) ?>
                        </span>
                    </div>

                    <?php
                    if (
                        !empty(
                            $order['has_physical']
                        )
                    ):
                    ?>
                        <div
                            class="flex justify-between text-sm text-gray-500"
                        >
                            <span>Shipping</span>

                            <?php
                            if (
                                (float) $order[
                                    'shipping_fee'
                                ] === 0.0 &&
                                isset(
                                    $order[
                                        'original_shipping_fee'
                                    ]
                                )
                            ):
                            ?>
                                <span>
                                    <span
                                        class="line-through text-gray-400"
                                    >
                                        RM
                                        <?= number_format(
                                            (float) $order[
                                                'original_shipping_fee'
                                            ],
                                            2
                                        ) ?>
                                    </span>

                                    <span
                                        class="text-green-600 font-bold ml-1"
                                    >
                                        RM 0.00
                                    </span>
                                </span>
                            <?php
                            elseif (
                                isset(
                                    $order[
                                        'original_shipping_fee'
                                    ]
                                ) &&
                                (float) $order[
                                    'original_shipping_fee'
                                ] >
                                (float) $order[
                                    'shipping_fee'
                                ]
                            ):
                            ?>
                                <span>
                                    <span
                                        class="line-through text-gray-400"
                                    >
                                        RM
                                        <?= number_format(
                                            (float) $order[
                                                'original_shipping_fee'
                                            ],
                                            2
                                        ) ?>
                                    </span>

                                    <span
                                        class="text-green-600 font-bold ml-1"
                                    >
                                        RM
                                        <?= number_format(
                                            (float) $order[
                                                'shipping_fee'
                                            ],
                                            2
                                        ) ?>
                                    </span>
                                </span>
                            <?php else: ?>
                                <span>
                                    <?= (float) $order[
                                        'shipping_fee'
                                    ] > 0
                                        ? 'RM ' .
                                            number_format(
                                                (float) $order[
                                                    'shipping_fee'
                                                ],
                                                2
                                            )
                                        : 'Free' ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php
                    if (
                        !empty(
                            $order['voucher_code']
                        ) &&
                        (float) (
                            $order[
                                'discount_amount'
                            ] ?? 0
                        ) > 0
                    ):
                    ?>
                        <div
                            class="flex justify-between text-sm text-green-600"
                        >
                            <span>
                                🎟️
                                <?= htmlspecialchars(
                                    $order[
                                        'voucher_code'
                                    ],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>

                            <span>
                                -RM
                                <?= number_format(
                                    (float) $order[
                                        'discount_amount'
                                    ],
                                    2
                                ) ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <div
                        class="flex justify-between font-black text-gray-800 text-lg pt-3 border-t border-gray-100"
                    >
                        <span>Total</span>

                        <span class="text-red-600">
                            RM
                            <?= number_format(
                                $total,
                                2
                            ) ?>
                        </span>
                    </div>
                </div>

                <?php
                if (
                    !empty(
                        $order['has_physical']
                    ) &&
                    !empty(
                        $order[
                            'shipping_courier'
                        ]
                    )
                ):
                ?>
                    <div
                        class="mt-4 bg-gray-50 rounded-xl p-3 text-xs text-gray-500"
                    >
                        🚚
                        <?= htmlspecialchars(
                            ucfirst(
                                str_replace(
                                    '_',
                                    ' ',
                                    $order[
                                        'shipping_courier'
                                    ]
                                )
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>

                        ·

                        <?= htmlspecialchars(
                            ucfirst(
                                str_replace(
                                    '_',
                                    ' ',
                                    $order[
                                        'shipping_zone'
                                    ] ?? 'peninsular'
                                )
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>

                        ·

                        <?= str_contains(
                            $order[
                                'shipping_method'
                            ] ?? '',
                            'express'
                        )
                            ? 'Express (1-2 days)'
                            : 'Standard (3-5 days)' ?>
                    </div>
                <?php endif; ?>
            </div>

            <div
                class="w-full lg:w-80 flex-shrink-0"
            >
                <div
                    class="bg-white rounded-2xl shadow-sm p-6 sticky top-24"
                >
                    <h2
                        class="font-black text-gray-800 mb-4"
                    >
                        Payment
                    </h2>

                    <div
                        class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-5"
                    >
                        <div
                            class="flex items-center gap-3 mb-2"
                        >
                            <span class="text-2xl">
                                💳
                            </span>

                            <div>
                                <p
                                    class="font-bold text-sm text-blue-800"
                                >
                                    Stripe Secure Payment
                                </p>

                                <p
                                    class="text-xs text-blue-600"
                                >
                                    Visa, Mastercard, Amex
                                </p>
                            </div>
                        </div>

                        <p
                            class="text-xs text-blue-500"
                        >
                            Stripe payment sessions expire after
                            about 30 minutes. You can continue from My
                            Orders before the session expires.
                        </p>
                    </div>

                    <div
                        class="bg-gray-50 rounded-xl p-4 mb-5"
                    >
                        <div
                            class="flex justify-between text-sm text-gray-500 mb-1"
                        >
                            <span>Amount to Pay</span>
                        </div>

                        <p
                            class="text-2xl font-black text-red-600"
                        >
                            RM
                            <?= number_format(
                                $total,
                                2
                            ) ?>
                        </p>
                    </div>

                    <form
                        method="POST"
                        id="stripePaymentForm"
                    >
                        <?php csrf_field(); ?>

                        <input
                            type="hidden"
                            name="pay_now"
                            value="1"
                        >

                        <button
                            type="submit"
                            class="w-full bg-red-600 hover:bg-red-700 text-white font-black py-4 rounded-xl text-sm transition-colors flex items-center justify-center gap-2"
                        >
                            🔒
                            <?= htmlspecialchars(
                                $button_label,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </button>
                    </form>

                    <a
                        href="cart.php"
                        data-checkout-cancel="true"
                        onclick="cancelPayment(event)"
                        class="block text-center text-sm text-gray-400 hover:text-red-600 transition-colors mt-4"
                    >
                        ← Cancel & Back to Cart
                    </a>

                    <div
                        class="flex justify-center gap-4 mt-4"
                    >
                        <span
                            class="text-xs text-gray-400"
                        >
                            🔒 SSL
                        </span>

                        <span
                            class="text-xs text-gray-400"
                        >
                            🛡️ Secure
                        </span>

                        <span
                            class="text-xs text-gray-400"
                        >
                            ✅ Safe
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div
        id="leaveModal"
        class="hidden fixed inset-0 bg-black/60 z-50 flex items-center justify-center px-6"
    >
        <div
            class="bg-white rounded-3xl p-8 max-w-sm w-full shadow-2xl text-center"
        >
            <div
                class="w-16 h-16 bg-yellow-100 rounded-full flex items-center justify-center mx-auto mb-4"
            >
                <span class="text-3xl">
                    ⚠️
                </span>
            </div>

            <h3
                class="text-xl font-black text-gray-800 mb-2"
            >
                Leave Checkout?
            </h3>

            <p
                class="text-sm text-gray-500 mb-2"
            >
                Your checkout has not been completed.
            </p>

            <p
                class="text-xs text-gray-400 mb-6"
            >
                You can continue it later from My Orders before
                it expires.
            </p>

            <div class="flex gap-3">
                <button
                    type="button"
                    onclick="stayOnCheckout()"
                    class="flex-1 border-2 border-gray-100 hover:bg-gray-50 text-gray-600 font-semibold py-3 rounded-xl text-sm"
                >
                    Stay
                </button>

                <button
                    type="button"
                    onclick="leaveCheckout()"
                    class="flex-1 bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-xl text-sm"
                >
                    Leave
                </button>
            </div>
        </div>
    </div>

    <div
        id="timeoutModal"
        class="hidden fixed inset-0 bg-black/60 z-50 flex items-center justify-center px-6"
    >
        <div
            class="bg-white rounded-3xl p-8 max-w-sm w-full shadow-2xl text-center"
        >
            <div
                class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4"
            >
                <span class="text-3xl">
                    ⏰
                </span>
            </div>

            <h3
                class="text-xl font-black text-gray-800 mb-2"
            >
                Checkout Expired
            </h3>

            <p
                class="text-sm text-gray-500 mb-2"
            >
                Your payment window has ended.
            </p>

            <p
                class="text-xs text-gray-400 mb-6"
            >
                The checkout was cancelled and any pending
                voucher was restored.
            </p>

            <a
                href="cart.php"
                class="block w-full py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold"
            >
                Back to Cart
            </a>
        </div>
    </div>

    <script>
    const timerDeadline =
        <?= (int) $timer_deadline ?> * 1000;

    const timerTotal =
        <?= (int) $timer_total ?> * 1000;

    const csrfToken =
        <?= json_encode(csrf_token()) ?>;

    const leaveModal =
        document.getElementById('leaveModal');

    const timeoutModal =
        document.getElementById('timeoutModal');

    let timerInterval = null;
    let allowNavigation = false;
    let pendingNavigationUrl = null;
    let pendingBackNavigation = false;

    function cancelPendingPayment() {
        return fetch(
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
                    csrf_token: csrfToken
                })
            }
        );
    }

    function updateTimer() {
        const remainingMs = Math.max(
            0,
            timerDeadline - Date.now()
        );

        const remainingSeconds = Math.floor(
            remainingMs / 1000
        );

        const minutes = Math.floor(
            remainingSeconds / 60
        )
            .toString()
            .padStart(2, '0');

        const seconds = (
            remainingSeconds % 60
        )
            .toString()
            .padStart(2, '0');

        document
            .getElementById('timerDisplay')
            .textContent =
                minutes + ':' + seconds;

        const percentage = Math.max(
            0,
            Math.min(
                100,
                (
                    remainingMs /
                    timerTotal
                ) * 100
            )
        );

        document
            .getElementById('timerBar')
            .style
            .width =
                percentage + '%';

        if (remainingSeconds <= 60) {
            document
                .getElementById('timerBar')
                .classList
                .replace(
                    'bg-yellow-500',
                    'bg-red-500'
                );

            document
                .getElementById('timerDisplay')
                .classList
                .replace(
                    'text-yellow-700',
                    'text-red-600'
                );
        }

        if (remainingSeconds <= 0) {
            clearInterval(timerInterval);
            allowNavigation = true;

            cancelPendingPayment()
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

                    timeoutModal
                        .classList
                        .remove('hidden');
                })
                .catch(error => {
                    allowNavigation = true;

                    window.alert(
                        error.message
                    );

                    window.location.href =
                        'orders.php?payment_resume_error=1';
                });
        }
    }

    function showLeaveModal(
        destination = null,
        fromBackButton = false
    ) {
        pendingNavigationUrl = destination;
        pendingBackNavigation =
            fromBackButton;

        leaveModal
            .classList
            .remove('hidden');
    }

    function stayOnCheckout() {
        pendingNavigationUrl = null;
        pendingBackNavigation = false;

        leaveModal
            .classList
            .add('hidden');
    }

    function leaveCheckout() {
        allowNavigation = true;

        leaveModal
            .classList
            .add('hidden');

        if (pendingBackNavigation) {
            history.go(-2);
            return;
        }

        window.location.href =
            pendingNavigationUrl ||
            'orders.php';
    }

    document
        .getElementById('stripePaymentForm')
        .addEventListener(
            'submit',
            () => {
                allowNavigation = true;
            }
        );

    document.addEventListener(
        'click',
        event => {
            const link =
                event.target.closest('a[href]');

            if (!link) {
                return;
            }

            if (
                link.dataset.checkoutCancel
                === 'true'
            ) {
                return;
            }

            if (
                link.target === '_blank' ||
                link.hasAttribute('download')
            ) {
                return;
            }

            const href =
                link.getAttribute('href');

            if (
                !href ||
                href.startsWith('#') ||
                href.startsWith(
                    'javascript:'
                )
            ) {
                return;
            }

            event.preventDefault();

            showLeaveModal(
                link.href,
                false
            );
        }
    );

    history.replaceState(
        {
            checkoutBase: true
        },
        '',
        window.location.href
    );

    history.pushState(
        {
            checkoutGuard: true
        },
        '',
        window.location.href
    );

    window.addEventListener(
        'popstate',
        () => {
            if (allowNavigation) {
                return;
            }

            history.pushState(
                {
                    checkoutGuard: true
                },
                '',
                window.location.href
            );

            showLeaveModal(
                null,
                true
            );
        }
    );

    window.addEventListener(
        'beforeunload',
        event => {
            if (allowNavigation) {
                return;
            }

            event.preventDefault();
            event.returnValue = '';
        }
    );

    function cancelPayment(event) {
        event.preventDefault();
        allowNavigation = true;

        cancelPendingPayment()
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
                    'payment_cancel.php';
            })
            .catch(error => {
                allowNavigation = false;

                window.alert(
                    error.message
                );
            });
    }

    updateTimer();

    timerInterval = setInterval(
        updateTimer,
        1000
    );
    </script>
</body>
</html>