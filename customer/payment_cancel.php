<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/csrf.php';

$user_id = current_user_id();

$pending_order =
    $_SESSION['pending_order'] ?? null;

$has_pending_checkout =
    is_array($pending_order) &&
    (int) ($pending_order['user_id'] ?? 0)
        === $user_id;

$stripe_session_id =
    $_SESSION['stripe_session_id'] ?? '';

$has_stripe_session =
    is_string($stripe_session_id) &&
    $stripe_session_id !== '';

$continue_payment_url =
    $has_stripe_session
        ? app_path(
            'customer/resume_payment.php'
        )
        : app_path(
            'customer/payment_gateway.php'
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
        Payment Status - MangaVault
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

<body
    class="bg-[#F5F0EB] min-h-screen flex items-center justify-center px-6"
>
    <div class="max-w-md w-full">
        <?php if ($has_pending_checkout): ?>
            <div
                class="bg-white rounded-2xl shadow-sm p-10 text-center"
            >
                <div
                    class="w-20 h-20 bg-yellow-100 rounded-full flex items-center justify-center mx-auto mb-5"
                >
                    <span class="text-4xl">
                        ↩️
                    </span>
                </div>

                <h2
                    class="text-2xl font-black text-gray-800 mb-2"
                >
                    Payment Not Completed
                </h2>

                <p
                    class="text-gray-500 text-sm mb-2"
                >
                    You returned before completing the Stripe
                    payment.
                </p>

                <p
                    class="text-gray-400 text-xs mb-6"
                >
                    Your checkout is still available and can be
                    continued before it expires.
                </p>

                <div
                    class="bg-yellow-50 border border-yellow-100 rounded-xl p-4 mb-6 text-left"
                >
                    <p
                        class="text-xs text-yellow-700 font-semibold mb-1"
                    >
                        Checkout saved
                    </p>

                    <p
                        class="text-xs text-yellow-600"
                    >
                        Continue now or open My Orders later to
                        resume the same payment session.
                    </p>
                </div>

                <div class="space-y-3">
                    <a
                        href="<?= htmlspecialchars(
                            $continue_payment_url,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        class="block w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-xl text-sm transition-colors"
                    >
                        Continue Payment
                    </a>

                    <a
                        href="<?= htmlspecialchars(
                            app_path(
                                'customer/orders.php'
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        class="block w-full border-2 border-gray-100 hover:bg-gray-50 text-gray-600 font-semibold py-3 rounded-xl text-sm transition-colors"
                    >
                        Go to My Orders
                    </a>

                    <button
                        type="button"
                        id="cancelCheckoutButton"
                        onclick="cancelCheckout()"
                        class="w-full text-sm text-gray-400 hover:text-red-600 transition-colors"
                    >
                        Cancel Checkout
                    </button>
                </div>
            </div>
        <?php else: ?>
            <div
                class="bg-white rounded-2xl shadow-sm p-10 text-center"
            >
                <div
                    class="w-20 h-20 bg-yellow-100 rounded-full flex items-center justify-center mx-auto mb-5"
                >
                    <span class="text-4xl">
                        ❌
                    </span>
                </div>

                <h2
                    class="text-2xl font-black text-gray-800 mb-2"
                >
                    Payment Cancelled
                </h2>

                <p
                    class="text-gray-500 text-sm mb-2"
                >
                    The checkout was cancelled and no order was
                    placed.
                </p>

                <p
                    class="text-gray-400 text-xs mb-6"
                >
                    Any pending voucher has been restored.
                </p>

                <div
                    class="bg-yellow-50 border border-yellow-100 rounded-xl p-4 mb-6 text-left"
                >
                    <p
                        class="text-xs text-yellow-700 font-semibold mb-1"
                    >
                        What happened?
                    </p>

                    <p
                        class="text-xs text-yellow-600"
                    >
                        Your cart items are still saved. You can
                        return to the cart and begin checkout
                        again.
                    </p>
                </div>

                <div class="flex gap-3">
                    <a
                        href="<?= htmlspecialchars(
                            app_path(
                                'customer/cart.php'
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-xl text-sm transition-colors text-center"
                    >
                        Back to Cart
                    </a>

                    <a
                        href="<?= htmlspecialchars(
                            app_path(
                                'customer/home.php'
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        class="flex-1 border-2 border-gray-100 hover:bg-gray-50 text-gray-600 font-semibold py-3 rounded-xl text-sm transition-colors text-center"
                    >
                        Continue Shopping
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($has_pending_checkout): ?>
        <script>
        const csrfToken =
            <?= json_encode(csrf_token()) ?>;

        function cancelCheckout() {
            const confirmed = window.confirm(
                'Cancel this pending checkout?'
            );

            if (!confirmed) {
                return;
            }

            const button =
                document.getElementById(
                    'cancelCheckoutButton'
                );

            button.disabled = true;
            button.textContent =
                'Cancelling...';

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
                        csrf_token: csrfToken
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

                    window.location.reload();
                })
                .catch(error => {
                    window.alert(
                        error.message
                    );

                    button.disabled = false;
                    button.textContent =
                        'Cancel Checkout';
                });
        }
        </script>
    <?php endif; ?>
</body>
</html>