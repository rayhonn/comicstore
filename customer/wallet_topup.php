<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/wallet_helper.php';
require_once __DIR__ . '/../includes/wallet_topup_helper.php';

$user_id = current_user_id();
$error = '';
$amount_input = '';

try {
    $wallet = getWalletSummary(
        $pdo,
        $user_id
    );
} catch (Throwable $e) {
    app_error_log(
        'Wallet top-up page could not load wallet: ' .
        $e->getMessage()
    );

    http_response_code(500);
    exit('Unable to load wallet top-up.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $amount_input =
        is_string($_POST['amount'] ?? null)
            ? trim($_POST['amount'])
            : '';

    $topup_id = null;

    try {
        $amount_sen = normalizeWalletTopupAmount(
            $amount_input
        );

        $topup_id = createWalletTopup(
            $pdo,
            $user_id,
            $amount_sen
        );

        \Stripe\Stripe::setApiKey(
            STRIPE_SECRET_KEY
        );

        $expires_at = time() + 1860;
        $app_url = rtrim(APP_URL, '/');

        $checkout_session =
            \Stripe\Checkout\Session::create(
                [
                    'payment_method_types' => [
                        'card',
                    ],
                    'line_items' => [[
                        'price_data' => [
                            'currency' => 'myr',
                            'product_data' => [
                                'name' =>
                                    'MangaVault Wallet Top-Up',
                                'description' =>
                                    'Add funds to your MangaVault Wallet',
                            ],
                            'unit_amount' =>
                                $amount_sen,
                        ],
                        'quantity' => 1,
                    ]],
                    'mode' => 'payment',
                    'client_reference_id' =>
                        (string) $user_id,
                    'metadata' => [
                        'checkout_type' =>
                            'wallet_topup',
                        'wallet_topup_id' =>
                            (string) $topup_id,
                        'user_id' =>
                            (string) $user_id,
                    ],
                    'payment_intent_data' => [
                        'metadata' => [
                            'checkout_type' =>
                                'wallet_topup',
                            'wallet_topup_id' =>
                                (string) $topup_id,
                            'user_id' =>
                                (string) $user_id,
                        ],
                    ],
                    'success_url' =>
                        $app_url .
                        '/customer/wallet_topup_success.php' .
                        '?session_id=' .
                        '{CHECKOUT_SESSION_ID}',
                    'cancel_url' =>
                        $app_url .
                        '/customer/wallet_topup_cancel.php' .
                        '?topup_id=' .
                        $topup_id,
                    'expires_at' =>
                        $expires_at,
                    'custom_text' => [
                        'submit' => [
                            'message' =>
                                'Your wallet balance is updated only after Stripe confirms successful payment.',
                        ],
                    ],
                ],
                [
                    'idempotency_key' =>
                        'wallet-topup-' .
                        $topup_id,
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

        attachWalletTopupStripeSession(
            $pdo,
            $topup_id,
            $user_id,
            (string) $checkout_session->id,
            (string) $checkout_session->url,
            (int) $checkout_session->expires_at,
            $payment_intent_id
        );

        header(
            'Location: ' .
            $checkout_session->url
        );
        exit;
    } catch (
        \Stripe\Exception\ApiErrorException $e
    ) {
        if ($topup_id !== null) {
            markWalletTopupFailed(
                $pdo,
                (int) $topup_id,
                $user_id
            );
        }

        app_error_log(
            'Wallet Stripe top-up creation failed: ' .
            $e->getMessage()
        );

        $error =
            'Unable to open the Stripe top-up page. Please try again.';
    } catch (WalletTopupException $e) {
        if ($topup_id !== null) {
            markWalletTopupFailed(
                $pdo,
                (int) $topup_id,
                $user_id
            );
        }

        $error = $e->getMessage();
    } catch (Throwable $e) {
        if ($topup_id !== null) {
            markWalletTopupFailed(
                $pdo,
                (int) $topup_id,
                $user_id
            );
        }

        app_error_log(
            'Wallet top-up preparation failed: ' .
            $e->getMessage()
        );

        $error =
            'Unable to prepare the wallet top-up.';
    }
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
    <title>Top Up Wallet - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-xl mx-auto px-6 py-10">
        <a
            href="wallet.php"
            class="inline-flex items-center text-sm text-gray-500 hover:text-red-600 mb-5"
        >
            ← Back to My Wallet
        </a>

        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100">
                <h1 class="text-xl font-black text-gray-800">
                    Top Up Wallet
                </h1>
                <p class="text-sm text-gray-400 mt-1">
                    Current available balance: RM
                    <?= moneyFormatSen(
                        $wallet['available_sen']
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

                <div
                    class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-5"
                >
                    <p class="text-xs text-blue-700 leading-relaxed">
                        Top-ups are processed through Stripe. Your wallet is credited only after MangaVault verifies the paid Stripe session, amount, currency, ownership, and session metadata.
                    </p>
                </div>

                <form method="POST" class="space-y-5">
                    <?php csrf_field(); ?>

                    <div>
                        <label
                            for="amount"
                            class="block text-sm font-semibold text-gray-700 mb-2"
                        >
                            Top-Up Amount (RM)
                        </label>

                        <input
                            type="text"
                            inputmode="decimal"
                            id="amount"
                            name="amount"
                            value="<?= htmlspecialchars(
                                $amount_input,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            placeholder="50.00"
                            maxlength="7"
                            autocomplete="off"
                            required
                            pattern="[0-9]+([.][0-9]{1,2})?"
                            class="w-full border border-gray-200 rounded-xl px-4 py-3 text-lg font-bold focus:outline-none focus:border-red-400"
                        >

                        <p class="text-xs text-gray-400 mt-2">
                            Minimum RM 1.00 · Maximum RM 5,000.00
                        </p>
                    </div>

                    <div class="grid grid-cols-4 gap-2">
                        <?php foreach ([
                            '20.00',
                            '50.00',
                            '100.00',
                            '200.00',
                        ] as $preset): ?>
                            <button
                                type="button"
                                onclick="document.getElementById('amount').value='<?= $preset ?>'"
                                class="border border-gray-200 hover:border-red-300 hover:bg-red-50 text-gray-600 text-sm font-semibold py-2.5 rounded-xl transition-colors"
                            >
                                RM <?= (int) $preset ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <button
                        type="submit"
                        class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3.5 rounded-xl text-sm transition-colors"
                    >
                        Continue to Stripe
                    </button>
                </form>
            </div>
        </div>
    </div>

</body>
</html>
