<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/stripe_config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/voucher_helper.php';
require_once __DIR__ . '/../includes/money_helper.php';
require_once __DIR__ .
    '/../includes/payment_draft_helper.php';
require_once __DIR__ .
    '/../includes/wallet_order_payment_helper.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/config.php';

function clearCheckoutSessionState(): void
{
    unset($_SESSION['pending_order']);
    unset($_SESSION['payment_draft_id']);
    unset($_SESSION['payment_lock']);
    unset($_SESSION['stripe_session_id']);
    unset($_SESSION['stripe_checkout_url']);
    unset($_SESSION['stripe_expires_at']);
}

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

$order = null;

if ($payment_draft_id !== null) {
    $order = loadPaymentDraft(
        $pdo,
        (int) $payment_draft_id,
        $user_id
    );

    if (
        $order !== null &&
        !in_array(
            $order['status'],
            [
                'pending',
                'checkout_open',
            ],
            true
        )
    ) {
        $order = null;
    }
}

if ($order === null) {
    $payment_draft_id =
        findActivePaymentDraftId(
            $pdo,
            $user_id
        );

    if ($payment_draft_id !== null) {
        $order = loadPaymentDraft(
            $pdo,
            $payment_draft_id,
            $user_id
        );
    }
}

if ($order === null) {
    clearCheckoutSessionState();

    redirect_to(
        app_path(
            'customer/cart.php'
        )
    );
}

$payment_draft_id =
    (int) $order[
        'payment_draft_id'
    ];

$_SESSION['payment_draft_id'] =
    $payment_draft_id;

/*
 * Temporary compatibility for payment_success.php and
 * resume_payment.php until their migration batch.
 */
$_SESSION['pending_order'] = $order;

$total_sen =
    moneyDecimalToSen(
        (string) $order['total']
    );

$shipping_fee_sen =
    moneyDecimalToSen(
        (string) $order[
            'shipping_fee'
        ]
    );

$original_shipping_fee_sen =
    moneyDecimalToSen(
        (string) $order[
            'original_shipping_fee'
        ]
    );

$discount_amount_sen =
    moneyDecimalToSen(
        (string) (
            $order[
                'discount_amount'
            ] ?? '0.00'
        )
    );

$subtotal_sen =
    $total_sen -
    $shipping_fee_sen +
    $discount_amount_sen;

$stripe_session_id = trim(
    (string) (
        $order[
            'stripe_session_id'
        ] ?? ''
    )
);

$stripe_checkout_url = trim(
    (string) (
        $order[
            'stripe_checkout_url'
        ] ?? ''
    )
);

$stripe_expires_at =
    $order['stripe_expires_at'];

if ($stripe_session_id !== '') {
    $_SESSION['stripe_session_id'] =
        $stripe_session_id;

    $_SESSION['stripe_checkout_url'] =
        $stripe_checkout_url;

    $_SESSION['stripe_expires_at'] =
        $stripe_expires_at;
} else {
    unset($_SESSION['stripe_session_id']);
    unset($_SESSION['stripe_checkout_url']);
    unset($_SESSION['stripe_expires_at']);
}

$has_stripe_session_id =
    $stripe_session_id !== '';

$has_saved_stripe_session =
    $has_stripe_session_id &&
    $stripe_checkout_url !== '' &&
    is_int($stripe_expires_at);

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

$draft_created_at = max(
    1,
    (int) (
        $order['created_at']
        ?? time()
    )
);

if (
    !isset($_SESSION['payment_lock']) ||
    !is_array(
        $_SESSION['payment_lock']
    ) ||
    (int) (
        $_SESSION['payment_lock'][
            'user_id'
        ] ?? 0
    ) !== $user_id ||
    (int) (
        $_SESSION['payment_lock'][
            'payment_draft_id'
        ] ?? 0
    ) !== $payment_draft_id
) {
    $_SESSION['payment_lock'] = [
        'user_id' =>
            $user_id,

        'payment_draft_id' =>
            $payment_draft_id,

        'locked_at' =>
            $draft_created_at,
    ];
}

$lock_locked_at = (int) (
    $_SESSION['payment_lock'][
        'locked_at'
    ] ?? $draft_created_at
);

$elapsed = max(
    0,
    time() - $lock_locked_at
);

if (
    !$has_active_stripe_session &&
    $elapsed >= 300
) {
    try {
        expirePaymentDraft(
            $pdo,
            $payment_draft_id,
            $user_id
        );
    } catch (Throwable $e) {
        app_error_log(
            'Payment draft expiry failed: ' .
            $e->getMessage()
        );

        http_response_code(500);

        exit(
            'Unable to expire the payment.'
        );
    }

    clearCheckoutSessionState();

    redirect_to(
        app_path(
            'customer/cart.php?timeout=1'
        )
    );
}

$wallet_error = null;

try {
    $wallet_summary =
        getWalletSummary(
            $pdo,
            $user_id
        );
} catch (Throwable $e) {
    app_error_log(
        'Checkout wallet summary failed: ' .
        $e->getMessage()
    );

    $wallet_summary = [
        'balance_sen' => 0,
        'reserved_sen' => 0,
        'available_sen' => 0,
    ];

    $wallet_error =
        'Unable to load your wallet balance.';
}

$wallet_balance_sen =
    (int) $wallet_summary[
        'balance_sen'
    ];

$wallet_reserved_sen =
    (int) $wallet_summary[
        'reserved_sen'
    ];

$wallet_available_sen =
    (int) $wallet_summary[
        'available_sen'
    ];

$wallet_shortfall_sen = max(
    0,
    $total_sen -
        $wallet_available_sen
);

$wallet_suggested_topup_sen =
    min(
        500000,
        max(
            100,
            $wallet_shortfall_sen
        )
    );

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['pay_wallet'])
) {
    csrf_verify();

    if ($has_active_stripe_session) {
        $wallet_error =
            'Wallet payment is unavailable while a Stripe payment session is active.';
    } else {
        try {
            $wallet_result =
                finalizeWalletPaymentDraft(
                    $pdo,
                    $payment_draft_id,
                    $user_id,
                    $_POST[
                        'current_password'
                    ] ?? null
                );

            sendWalletOrderConfirmationMessages(
                $pdo,
                $wallet_result
            );

            $wallet_order_id =
                (int) $wallet_result[
                    'order_id'
                ];

            clearCheckoutSessionState();

            redirect_to(
                app_path(
                    'customer/order_success.php' .
                    '?order_id=' .
                    $wallet_order_id
                )
            );
        } catch (
            WalletOrderPaymentException |
            WalletException $e
        ) {
            $wallet_error =
                $e->getMessage();
        } catch (Throwable $e) {
            app_error_log(
                'Wallet checkout payment failed: ' .
                $e->getMessage()
            );

            $wallet_error =
                'Unable to complete the wallet payment.';
        }

        try {
            $wallet_summary =
                getWalletSummary(
                    $pdo,
                    $user_id
                );

            $wallet_balance_sen =
                (int) $wallet_summary[
                    'balance_sen'
                ];

            $wallet_reserved_sen =
                (int) $wallet_summary[
                    'reserved_sen'
                ];

            $wallet_available_sen =
                (int) $wallet_summary[
                    'available_sen'
                ];

            $wallet_shortfall_sen = max(
                0,
                $total_sen -
                    $wallet_available_sen
            );
        } catch (Throwable $e) {
            app_error_log(
                'Wallet summary refresh failed: ' .
                $e->getMessage()
            );
        }
    }
}

$stripe_error = null;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['pay_now'])
) {
    csrf_verify();

    if ($has_active_stripe_session) {
        header(
            'Location: ' .
            $stripe_checkout_url
        );

        exit;
    }

    \Stripe\Stripe::setApiKey(
        STRIPE_SECRET_KEY
    );

    $app_url = rtrim(APP_URL, '/');
    $line_items = [];

    foreach ($order['items'] as $item) {
        $unit_amount_sen =
            moneyDecimalToSen(
                (string) $item[
                    'product_price'
                ]
            );

        $line_items[] = [
            'price_data' => [
                'currency' =>
                    $order['currency'],

                'product_data' => [
                    'name' =>
                        $item['product_title'],

                    'description' =>
                        ucfirst(
                            $item['product_type']
                        ) .
                        ' - MangaVault',
                ],

                'unit_amount' =>
                    $unit_amount_sen,
            ],

            'quantity' =>
                (int) $item[
                    'cart_item_quantity'
                ],
        ];
    }

    if (
        !empty($order['has_physical']) &&
        $shipping_fee_sen > 0
    ) {
        $line_items[] = [
            'price_data' => [
                'currency' =>
                    $order['currency'],

                'product_data' => [
                    'name' =>
                        'Shipping Fee',
                ],

                'unit_amount' =>
                    $shipping_fee_sen,
            ],

            'quantity' => 1,
        ];
    }

    if (
        !empty($order['voucher_code']) &&
        $discount_amount_sen > 0
    ) {
        $line_items = [[
            'price_data' => [
                'currency' =>
                    $order['currency'],

                'product_data' => [
                    'name' =>
                        'MangaVault Order',

                    'description' =>
                        'Includes voucher discount (' .
                        $order[
                            'voucher_code'
                        ] .
                        ' -RM' .
                        moneyFormatSen(
                            $discount_amount_sen
                        ) .
                        ')',
                ],

                'unit_amount' =>
                    $total_sen,
            ],

            'quantity' => 1,
        ]];
    }

    try {
        $session_expires_at =
            time() + 1860;

        $checkout_session =
            \Stripe\Checkout\Session::create(
                [
                    'payment_method_types' => [
                        'card',
                    ],

                    'line_items' =>
                        $line_items,

                    'mode' =>
                        'payment',

                    'client_reference_id' =>
                        (string) $user_id,

                    'metadata' => [
                        'payment_draft_id' =>
                            (string) $payment_draft_id,

                        'user_id' =>
                            (string) $user_id,
                    ],

                    'payment_intent_data' => [
                        'metadata' => [
                            'payment_draft_id' =>
                                (string) $payment_draft_id,

                            'user_id' =>
                                (string) $user_id,
                        ],
                    ],

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
                ],
                [
                    'idempotency_key' =>
                        'payment-draft-' .
                        $payment_draft_id,
                ]
            );

        $payment_intent_id =
            is_string(
                $checkout_session
                    ->payment_intent
                ?? null
            )
                ? $checkout_session
                    ->payment_intent
                : null;

        attachStripeCheckoutSession(
            $pdo,
            $payment_draft_id,
            $user_id,
            (string) $checkout_session->id,
            (string) $checkout_session->url,
            (int) $checkout_session
                ->expires_at,
            $payment_intent_id
        );

        $_SESSION['stripe_session_id'] =
            (string) $checkout_session->id;

        $_SESSION['stripe_checkout_url'] =
            (string) $checkout_session->url;

        $_SESSION['stripe_expires_at'] =
            (int) $checkout_session
                ->expires_at;

        header(
            'Location: ' .
            $checkout_session->url
        );

        exit;
    } catch (
        \Stripe\Exception\ApiErrorException $e
    ) {
        app_error_log(
            'Stripe Checkout Session creation failed: ' .
            $e->getMessage()
        );

        $stripe_error =
            'Unable to open the Stripe payment page.';
    } catch (Throwable $e) {
        app_error_log(
            'Payment draft Stripe linking failed: ' .
            $e->getMessage()
        );

        $stripe_error =
            'Unable to prepare the Stripe payment.';
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

$wallet_topup_success =
    !empty(
        $_SESSION[
            'checkout_wallet_topup_success'
        ]
    );

unset(
    $_SESSION[
        'checkout_wallet_topup_success'
    ]
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

        <?php if ($wallet_error): ?>
            <div
                class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-5 max-w-2xl mx-auto"
            >
                <?= htmlspecialchars(
                    $wallet_error,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>
        <?php endif; ?>

        <?php if (
            $wallet_topup_success
        ): ?>
            <div
                class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5 max-w-2xl mx-auto"
            >
                Wallet top-up completed successfully.
                Your updated balance is ready to use.
            </div>
        <?php endif; ?>

        <?php if (
            isset($_GET['topup_cancelled'])
        ): ?>
            <div
                class="bg-gray-50 border border-gray-200 text-gray-600 text-sm px-4 py-3 rounded-xl mb-5 max-w-2xl mx-auto"
            >
                Wallet top-up was cancelled.
                No funds were added to your wallet.
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
                                <?= moneyFormatSen(
                                    moneyDecimalToSen(
                                        (string) $item[
                                            'product_price'
                                        ]
                                    ) *
                                    (int) $item[
                                        'cart_item_quantity'
                                    ]
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
                            <?= moneyFormatSen(
                                $subtotal_sen
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
                                $shipping_fee_sen === 0 &&
                                $original_shipping_fee_sen > 0
                            ):
                            ?>
                                <span>
                                    <span
                                        class="line-through text-gray-400"
                                    >
                                        RM
                                        <?= moneyFormatSen(
                                            $original_shipping_fee_sen
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
                                $original_shipping_fee_sen >
                                $shipping_fee_sen
                            ):
                            ?>
                                <span>
                                    <span
                                        class="line-through text-gray-400"
                                    >
                                        RM
                                        <?= moneyFormatSen(
                                            $original_shipping_fee_sen
                                        ) ?>
                                    </span>

                                    <span
                                        class="text-green-600 font-bold ml-1"
                                    >
                                        RM
                                        <?= moneyFormatSen(
                                            $shipping_fee_sen
                                        ) ?>
                                    </span>
                                </span>
                            <?php else: ?>
                                <span>
                                    <?= $shipping_fee_sen > 0
                                        ? 'RM ' .
                                            moneyFormatSen(
                                                $shipping_fee_sen
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
                        $discount_amount_sen > 0
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
                                <?= moneyFormatSen(
                                    $discount_amount_sen
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
                            <?= moneyFormatSen(
                                $total_sen
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
                        class="bg-emerald-50 border border-emerald-100 rounded-xl p-4 mb-5"
                    >
                        <div
                            class="flex items-center gap-3 mb-4"
                        >
                            <div
                                class="w-10 h-10 bg-emerald-100 rounded-xl flex items-center justify-center text-xl flex-shrink-0"
                            >
                                👛
                            </div>

                            <div class="min-w-0">
                                <p
                                    class="font-black text-sm text-emerald-900 leading-tight"
                                >
                                    MangaVault Wallet
                                </p>

                                <p
                                    class="text-[11px] text-emerald-600 mt-1"
                                >
                                    Secure instant wallet payment
                                </p>
                            </div>
                        </div>
                        <div
                            class="grid grid-cols-2 gap-3 mb-3"
                        >
                            <div
                                class="bg-white/80 border border-emerald-100 rounded-xl px-3 py-3 min-h-[72px] flex flex-col justify-center"
                            >
                                <p
                                    class="text-[11px] text-gray-400"
                                >
                                    Available
                                </p>

                                <p
                                    class="text-sm font-black text-emerald-700"
                                >
                                    RM
                                    <?= moneyFormatSen(
                                        $wallet_available_sen
                                    ) ?>
                                </p>
                            </div>

                            <div
                                class="bg-white/80 border border-emerald-100 rounded-xl px-3 py-3 min-h-[72px] flex flex-col justify-center"
                            >
                                <p
                                    class="text-[11px] text-gray-400"
                                >
                                    Reserved
                                </p>

                                <p
                                    class="text-sm font-bold text-gray-600"
                                >
                                    RM
                                    <?= moneyFormatSen(
                                        $wallet_reserved_sen
                                    ) ?>
                                </p>
                            </div>
                        </div>

                        <?php if (
                            $has_active_stripe_session
                        ): ?>
                            <div
                                class="bg-white/70 border border-emerald-100 rounded-lg p-3"
                            >
                                <p
                                    class="text-xs text-gray-600 leading-relaxed"
                                >
                                    Wallet payment is locked because
                                    this checkout already has an active
                                    Stripe payment session.
                                </p>
                            </div>

                        <?php elseif (
                            $wallet_available_sen >=
                                $total_sen
                        ): ?>
                            <form
                                method="POST"
                                id="walletPaymentForm"
                                class="space-y-3"
                            >
                                <?php csrf_field(); ?>

                                <input
                                    type="hidden"
                                    name="pay_wallet"
                                    value="1"
                                >

                                <div>
                                    <label
                                        for="wallet_current_password"
                                        class="block text-xs font-semibold text-emerald-800 mb-1.5"
                                    >
                                        Current Password
                                    </label>

                                    <input
                                        type="password"
                                        id="wallet_current_password"
                                        name="current_password"
                                        maxlength="72"
                                        autocomplete="current-password"
                                        required
                                        placeholder="Confirm your account password"
                                        class="w-full border border-emerald-200 rounded-lg px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-emerald-500"
                                    >
                                </div>

                                <button
                                    type="submit"
                                    class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-black py-3 rounded-lg text-sm transition-colors"
                                >
                                    Pay RM
                                    <?= moneyFormatSen(
                                        $total_sen
                                    ) ?>
                                    with Wallet
                                </button>
                            </form>

                            <p
                                class="text-[11px] text-emerald-700 mt-3 leading-relaxed"
                            >
                                Wallet payment is confirmed immediately.
                                Reserved bank-withdrawal funds cannot be
                                used for purchases.
                            </p>

                        <?php else: ?>
                            <div
                                class="bg-white border border-emerald-100 rounded-xl p-4"
                            >
                                <p
                                    class="text-xs text-gray-500"
                                >
                                    Wallet balance is short by
                                </p>

                                <p
                                    class="text-lg font-black text-gray-800 mt-1"
                                >
                                    RM
                                    <?= moneyFormatSen(
                                        $wallet_shortfall_sen
                                    ) ?>
                                </p>

                                <a
                                    href="wallet_topup.php?amount=<?= rawurlencode(
                                        moneyFormatSen(
                                            $wallet_suggested_topup_sen
                                        )
                                    ) ?>&return_to=checkout"
                                    data-wallet-topup-link="true"
                                    class="flex w-full items-center justify-center mt-3 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold py-2.5 px-3 rounded-lg transition-colors"
                                >
                                    Top Up RM
                                    <?= moneyFormatSen(
                                        $wallet_suggested_topup_sen
                                    ) ?>
                                    →
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div
                        class="flex items-center gap-3 mb-5"
                    >
                        <div
                            class="h-px bg-gray-100 flex-1"
                        ></div>

                        <span
                            class="text-[11px] font-semibold uppercase tracking-wider text-gray-400"
                        >
                            or
                        </span>

                        <div
                            class="h-px bg-gray-100 flex-1"
                        ></div>
                    </div>
                    
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
                            <?= moneyFormatSen(
                                $total_sen
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

    const walletTopupLink =
        document.querySelector(
            '[data-wallet-topup-link="true"]'
        );

    if (walletTopupLink) {
        walletTopupLink.addEventListener(
            'click',
            () => {
                allowNavigation = true;
            }
        );
    }


    const walletPaymentForm =
        document.getElementById(
            'walletPaymentForm'
        );

    if (walletPaymentForm) {
        walletPaymentForm.addEventListener(
            'submit',
            event => {
                const confirmed =
                    window.confirm(
                        <?= json_encode(
                            'Pay RM ' .
                            moneyFormatSen(
                                $total_sen
                            ) .
                            ' using your MangaVault Wallet? Wallet payment is confirmed immediately and cannot be cancelled after completion.'
                        ) ?>
                    );

                if (!confirmed) {
                    event.preventDefault();
                    return;
                }

                allowNavigation = true;
            }
        );
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

            /*
             * Allow normal navigation after checkout
             * has expired or navigation was approved.
             */
            if (allowNavigation) {
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