<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/money_helper.php';
require_once __DIR__ . '/../includes/wallet_helper.php';

$user_id = current_user_id();

try {
    $wallet = getWalletSummary(
        $pdo,
        $user_id
    );

    $transactions = getWalletTransactions(
        $pdo,
        $user_id,
        20
    );
} catch (Throwable $e) {
    app_error_log(
        'Customer wallet page failed: ' .
        $e->getMessage()
    );

    http_response_code(500);
    exit('Unable to load your wallet.');
}

$type_labels = [
    'topup' => 'Wallet Top-Up',
    'return_refund' => 'Return Refund',
    'cancellation_refund' => 'Order Cancellation Refund',
    'payment_timeout_refund' => 'Payment Timeout Refund',
    'withdrawal_reserve' => 'Bank Transfer Reserved',
    'withdrawal_release' => 'Bank Transfer Released',
    'withdrawal_complete' => 'Bank Transfer Completed',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>My Wallet - MangaVault</title>
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
            <a
                href="../index.php"
                class="hover:text-red-600 transition-colors"
            >
                Home
            </a>
            <span class="mx-2">›</span>
            <a
                href="dashboard.php"
                class="hover:text-red-600 transition-colors"
            >
                My Account
            </a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">My Wallet</span>
        </p>

        <div class="flex gap-8 items-start">
            <?php include '../includes/customer_sidebar.php'; ?>

            <div class="flex-1 min-w-0">
                <div
                    class="flex items-center justify-between gap-4 flex-wrap mb-6"
                >
                    <div>
                        <h1 class="text-2xl font-black text-gray-800">
                            My Wallet
                        </h1>
                        <p class="text-sm text-gray-400 mt-1">
                            Top up securely and receive eligible refunds here.
                        </p>
                    </div>

                    <a
                        href="wallet_topup.php"
                        class="bg-red-600 hover:bg-red-700 text-white font-bold px-5 py-2.5 rounded-xl text-sm transition-colors"
                    >
                        + Top Up Wallet
                    </a>
                </div>

                <?php if (isset($_GET['topup_success'])): ?>
                    <div
                        class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5"
                    >
                        Wallet top-up completed successfully.
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['topup_cancelled'])): ?>
                    <div
                        class="bg-gray-50 border border-gray-200 text-gray-600 text-sm px-4 py-3 rounded-xl mb-5"
                    >
                        Wallet top-up was cancelled. No wallet balance was added.
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['topup_error'])): ?>
                    <div
                        class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-5"
                    >
                        The wallet top-up could not be verified. Please check your transaction history before trying again.
                    </div>
                <?php endif; ?>

                <div
                    class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6"
                >
                    <div
                        class="md:col-span-2 bg-gradient-to-br from-[#17243d] to-[#293957] rounded-2xl p-6 text-white shadow-sm"
                    >
                        <p
                            class="text-xs uppercase tracking-[0.18em] text-white/50 font-semibold"
                        >
                            Available Balance
                        </p>

                        <p class="text-4xl font-black mt-2">
                            RM <?= moneyFormatSen(
                                $wallet['available_sen']
                            ) ?>
                        </p>

                        <div
                            class="flex gap-6 mt-6 pt-5 border-t border-white/10 text-sm"
                        >
                            <div>
                                <p class="text-white/45 text-xs">
                                    Total Balance
                                </p>
                                <p class="font-bold mt-1">
                                    RM <?= moneyFormatSen(
                                        $wallet['balance_sen']
                                    ) ?>
                                </p>
                            </div>

                            <div>
                                <p class="text-white/45 text-xs">
                                    Reserved
                                </p>
                                <p class="font-bold mt-1">
                                    RM <?= moneyFormatSen(
                                        $wallet['reserved_sen']
                                    ) ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div
                        class="bg-white rounded-2xl shadow-sm p-6"
                    >
                        <div
                            class="w-11 h-11 bg-red-50 text-red-600 rounded-xl flex items-center justify-center text-xl mb-4"
                        >
                            +
                        </div>

                        <p class="font-bold text-gray-800">
                            Add Funds
                        </p>

                        <p
                            class="text-xs text-gray-400 leading-relaxed mt-2 mb-5"
                        >
                            Top-ups are added only after Stripe confirms a successful payment.
                        </p>

                        <a
                            href="wallet_topup.php"
                            class="block w-full text-center bg-red-600 hover:bg-red-700 text-white font-semibold py-2.5 rounded-xl text-sm transition-colors"
                        >
                            Top Up Now
                        </a>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                    <div
                        class="px-6 py-5 border-b border-gray-100 flex items-center justify-between"
                    >
                        <div>
                            <h2 class="font-black text-gray-800">
                                Transaction History
                            </h2>
                            <p class="text-xs text-gray-400 mt-1">
                                Latest 20 wallet events
                            </p>
                        </div>
                    </div>

                    <?php if ($transactions === []): ?>
                        <div class="p-10 text-center">
                            <div class="text-4xl mb-3">💰</div>
                            <p class="text-sm font-semibold text-gray-600">
                                No wallet transactions yet
                            </p>
                            <p class="text-xs text-gray-400 mt-1">
                                Top-ups and refunds will appear here.
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="divide-y divide-gray-100">
                            <?php foreach ($transactions as $transaction): ?>
                                <?php
                                $amount_sen = moneyDecimalToSen(
                                    (string) $transaction[
                                        'wallet_tx_amount'
                                    ]
                                );

                                $effect = (string) $transaction[
                                    'wallet_tx_effect'
                                ];

                                if ($effect === 'credit') {
                                    $amount_prefix = '+';
                                    $amount_class =
                                        'text-green-600';
                                } elseif ($effect === 'debit') {
                                    $amount_prefix = '-';
                                    $amount_class =
                                        'text-red-600';
                                } elseif ($effect === 'reserve') {
                                    $amount_prefix = 'Reserved ';
                                    $amount_class =
                                        'text-amber-600';
                                } else {
                                    $amount_prefix = 'Released ';
                                    $amount_class =
                                        'text-blue-600';
                                }

                                $type = (string) $transaction[
                                    'wallet_tx_type'
                                ];

                                $label =
                                    $type_labels[$type] ??
                                    ucfirst(
                                        str_replace(
                                            '_',
                                            ' ',
                                            $type
                                        )
                                    );
                                ?>
                                <div
                                    class="px-6 py-4 flex items-center justify-between gap-4"
                                >
                                    <div class="min-w-0">
                                        <p
                                            class="text-sm font-semibold text-gray-800"
                                        >
                                            <?= htmlspecialchars(
                                                $label,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>

                                        <p
                                            class="text-xs text-gray-400 mt-1 truncate"
                                        >
                                            <?= htmlspecialchars(
                                                (string) $transaction[
                                                    'wallet_tx_description'
                                                ],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>

                                        <p class="text-[11px] text-gray-300 mt-1">
                                            <?= date(
                                                'd M Y, h:i A',
                                                strtotime(
                                                    (string) $transaction[
                                                        'wallet_tx_created_at'
                                                    ]
                                                )
                                            ) ?>
                                        </p>
                                    </div>

                                    <div class="text-right flex-shrink-0">
                                        <p
                                            class="font-black <?= $amount_class ?>"
                                        >
                                            <?= $amount_prefix ?>RM
                                            <?= moneyFormatSen(
                                                $amount_sen
                                            ) ?>
                                        </p>

                                        <p class="text-[11px] text-gray-400 mt-1">
                                            Balance RM
                                            <?= moneyFormatSen(
                                                moneyDecimalToSen(
                                                    (string) $transaction[
                                                        'wallet_tx_balance_after'
                                                    ]
                                                )
                                            ) ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
