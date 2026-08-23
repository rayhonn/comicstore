<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/money_helper.php';
require_once __DIR__ . '/../includes/wallet_helper.php';
require_once __DIR__ . '/../includes/wallet_withdrawal_helper.php';

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

    $withdrawal_summary =
        getWalletWithdrawalSummary(
            $pdo,
            $user_id
        );

    $withdrawals =
        getCustomerWalletWithdrawals(
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

$withdrawable_refund_sen = min(
    (int) $withdrawal_summary[
        'eligible_sen'
    ],
    (int) $wallet['available_sen']
);
$active_withdrawal =
    $withdrawal_summary['active_request'];

$wallet_topup_success =
    !empty(
        $_SESSION[
            'wallet_topup_success'
        ]
    );

unset(
    $_SESSION[
        'wallet_topup_success'
    ]
);

$wallet_withdrawal_submitted =
    !empty(
        $_SESSION[
            'wallet_withdrawal_submitted'
        ]
    );

unset(
    $_SESSION[
        'wallet_withdrawal_submitted'
    ]
);

$type_labels = [
    'topup' => 'Wallet Top-Up',
    'return_refund' => 'Return Refund',
    'cancellation_refund' => 'Order Cancellation Refund',
    'payment_timeout_refund' => 'Payment Timeout Refund',
    'order_payment' => 'Wallet Order Payment',
    'order_payment_refund' => 'Wallet Order Refund',
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
                            Secure wallet top-ups, refund credits and refund-to-bank transfers.
                        </p>
                    </div>

                    <div class="flex gap-2 flex-wrap">
                        <?php if (
                            $withdrawable_refund_sen >= 100 &&
                            !$active_withdrawal
                        ): ?>
                            <a
                                href="wallet_withdrawal.php"
                                class="bg-[#1e2d4a] hover:bg-[#162338] text-white font-bold px-5 py-2.5 rounded-xl text-sm transition-colors"
                            >
                                Withdraw Refund to Bank
                            </a>
                        <?php endif; ?>

                        <a
                            href="wallet_topup.php"
                            class="bg-red-600 hover:bg-red-700 text-white font-bold px-5 py-2.5 rounded-xl text-sm transition-colors"
                        >
                            + Top Up Wallet
                        </a>
                    </div>
                </div>

                <?php if ($wallet_topup_success): ?>
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

                <?php if ($wallet_withdrawal_submitted): ?>
                    <div
                        class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5"
                    >
                        Bank withdrawal request submitted successfully. The requested refund amount is now reserved while awaiting administrator review.
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
                            Top-ups are added only after Stripe confirms and MangaVault verifies a successful payment.
                        </p>

                        <a
                            href="wallet_topup.php"
                            class="block w-full text-center bg-red-600 hover:bg-red-700 text-white font-semibold py-2.5 rounded-xl text-sm transition-colors"
                        >
                            Top Up Now
                        </a>
                    </div>
                </div>

                <div
                    class="bg-white rounded-2xl shadow-sm overflow-hidden mb-6"
                >
                    <div
                        class="px-6 py-5 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap"
                    >
                        <div>
                            <h2 class="font-black text-gray-800">
                                Refund Bank Withdrawal
                            </h2>
                            <p class="text-xs text-gray-400 mt-1">
                                Only refund credits are cash-withdrawable. Stripe wallet top-ups cannot be withdrawn.
                            </p>
                        </div>

                        <div class="text-right">
                            <p class="text-xs text-gray-400">
                                Eligible Now
                            </p>
                            <p class="text-2xl font-black text-green-600">
                                RM <?= moneyFormatSen(
                                    $withdrawable_refund_sen
                                ) ?>
                            </p>
                        </div>
                    </div>

                    <div class="p-6">
                        <?php if ($active_withdrawal): ?>
                            <?php
                            $active_status = (string) $active_withdrawal[
                                'wallet_withdrawal_status'
                            ];
                            $active_step =
                                $active_status === 'approved'
                                    ? 3
                                    : 1;
                            $active_deadline =
                                walletWithdrawalBusinessDayDeadlineLabel(
                                    (string) (
                                        $active_withdrawal[
                                            'wallet_withdrawal_reviewed_at'
                                        ] ?? ''
                                    )
                                );
                            ?>
                            <div
                                class="overflow-hidden rounded-2xl border border-blue-200 bg-gradient-to-br from-blue-50 via-white to-indigo-50"
                            >
                                <div
                                    class="flex items-start justify-between gap-5 border-b border-blue-100 px-5 py-4 flex-wrap"
                                >
                                    <div>
                                        <div
                                            class="flex items-center gap-2 flex-wrap"
                                        >
                                            <p class="font-black text-blue-900">
                                            Active Request #<?= str_pad(
                                                (string) $active_withdrawal[
                                                    'wallet_withdrawal_id'
                                                ],
                                                4,
                                                '0',
                                                STR_PAD_LEFT
                                            ) ?>
                                            </p>

                                            <span
                                                class="rounded-full bg-blue-600 px-2.5 py-1 text-[10px] font-black uppercase tracking-wider text-white"
                                            >
                                                <?= $active_status === 'approved'
                                                    ? 'Bank Processing'
                                                    : 'Pending Review' ?>
                                            </span>
                                        </div>
                                        <p
                                            class="mt-1.5 max-w-2xl text-xs leading-relaxed text-blue-700"
                                        >
                                            The requested amount remains reserved and protected until completion, rejection, or a failed transfer.
                                        </p>
                                    </div>

                                    <p class="text-xl font-black text-blue-700">
                                        RM <?= moneyFormatSen(
                                            moneyDecimalToSen(
                                                (string) $active_withdrawal[
                                                    'wallet_withdrawal_amount'
                                                ]
                                            )
                                        ) ?>
                                    </p>
                                </div>

                                <div class="px-5 py-5">
                                    <div
                                        class="grid grid-cols-2 gap-4 md:grid-cols-4"
                                    >
                                        <?php foreach ([
                                            1 => ['Request Submitted', 'Received securely'],
                                            2 => ['Admin Review', $active_status === 'approved'
                                                ? 'Approved'
                                                : 'In review'],
                                            3 => ['Bank Processing', $active_status === 'approved'
                                                ? 'In progress'
                                                : 'Starts after approval'],
                                            4 => ['Completed', 'Funds sent'],
                                        ] as $step => $step_copy): ?>
                                            <div class="relative">
                                                <div
                                                    class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-black <?= $step <= $active_step
                                                        ? 'bg-blue-600 text-white shadow-sm shadow-blue-200'
                                                        : 'bg-gray-100 text-gray-400' ?>"
                                                >
                                                    <?= $step < $active_step
                                                        ? '✓'
                                                        : $step ?>
                                                </div>
                                                <p
                                                    class="mt-2 text-xs font-black <?= $step <= $active_step
                                                        ? 'text-blue-900'
                                                        : 'text-gray-400' ?>"
                                                >
                                                    <?= htmlspecialchars(
                                                        $step_copy[0],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>
                                                </p>
                                                <p
                                                    class="mt-0.5 text-[10px] <?= $step <= $active_step
                                                        ? 'text-blue-600'
                                                        : 'text-gray-400' ?>"
                                                >
                                                    <?= htmlspecialchars(
                                                        $step_copy[1],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>
                                                </p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <div
                                        class="mt-5 flex items-center justify-between gap-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 flex-wrap"
                                    >
                                        <div>
                                            <p
                                                class="text-xs font-black text-amber-800"
                                            >
                                                Processing may take up to 14 business days
                                            </p>
                                            <p
                                                class="mt-1 text-[11px] leading-relaxed text-amber-700"
                                            >
                                                The service window begins after approval. Weekends are excluded; bank and public-holiday schedules may affect the exact arrival date.
                                            </p>
                                        </div>

                                        <?php if (
                                            $active_status === 'approved'
                                        ): ?>
                                            <div
                                                class="flex items-center gap-3 flex-wrap"
                                            >
                                                <div class="text-right">
                                                    <p
                                                        class="text-[10px] font-black uppercase tracking-wider text-amber-500"
                                                    >
                                                        Estimated by
                                                    </p>
                                                    <p
                                                        class="text-sm font-black text-amber-900"
                                                    >
                                                        <?= htmlspecialchars(
                                                            $active_deadline !== ''
                                                                ? $active_deadline
                                                                : 'Calculating',
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        ) ?>
                                                    </p>
                                                </div>

                                                <a
                                                    href="wallet_withdrawal_receipt.php?id=<?= (int) $active_withdrawal['wallet_withdrawal_id'] ?>&amp;download=1"
                                                    class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-xs font-bold text-white transition-colors hover:bg-blue-700"
                                                >
                                                    📄 Download Approval PDF
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php elseif ($withdrawable_refund_sen >= 100): ?>
                            <div
                                class="flex items-center justify-between gap-5 flex-wrap"
                            >
                                <div class="max-w-2xl">
                                    <p class="font-bold text-gray-800">
                                        Eligible refund balance is available for bank transfer.
                                    </p>
                                    <p class="text-sm text-gray-500 mt-1 leading-relaxed">
                                        Bank withdrawal must be requested within 7 days of each refund credit. The system reserves the requested amount immediately and requires password re-authentication before submission.
                                    </p>
                                    <?php if (!empty(
                                        $withdrawal_summary[
                                            'earliest_expiry'
                                        ]
                                    )): ?>
                                        <p class="text-xs text-red-600 mt-2 font-semibold">
                                            Earliest expiry: <?= htmlspecialchars(
                                                date(
                                                    'd M Y, h:i A',
                                                    strtotime(
                                                        (string) $withdrawal_summary[
                                                            'earliest_expiry'
                                                        ]
                                                    )
                                                ),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <a
                                    href="wallet_withdrawal.php"
                                    class="bg-[#1e2d4a] hover:bg-[#162338] text-white font-bold px-5 py-3 rounded-xl text-sm"
                                >
                                    Request Bank Withdrawal
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <p class="font-semibold text-gray-600">
                                    No refund balance is currently eligible for bank withdrawal.
                                </p>
                                <p class="text-xs text-gray-400 mt-1">
                                    Approved returns and eligible cancelled-order refunds will appear here automatically.
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div
                    class="bg-white rounded-2xl shadow-sm overflow-hidden mb-6"
                >
                    <div
                        class="px-6 py-5 border-b border-gray-100"
                    >
                        <h2 class="font-black text-gray-800">
                            Bank Withdrawal History
                        </h2>
                        <p class="text-xs text-gray-400 mt-1">
                            Review status, bank destination and transfer receipts.
                        </p>
                    </div>

                    <?php if ($withdrawals === []): ?>
                        <div class="p-8 text-center text-sm text-gray-400">
                            No bank withdrawal requests yet.
                        </div>
                    <?php else: ?>
                        <div class="divide-y divide-gray-100">
                            <?php foreach ($withdrawals as $withdrawal): ?>
                                <?php
                                $withdrawal_status = (string) $withdrawal[
                                    'wallet_withdrawal_status'
                                ];
                                $withdrawal_status_classes = [
                                    'pending' =>
                                        'bg-yellow-100 text-yellow-700',
                                    'approved' =>
                                        'bg-blue-100 text-blue-700',
                                    'rejected' =>
                                        'bg-red-100 text-red-700',
                                    'failed' =>
                                        'bg-orange-100 text-orange-700',
                                    'completed' =>
                                        'bg-green-100 text-green-700',
                                ];
                                $withdrawal_deadline =
                                    walletWithdrawalBusinessDayDeadlineLabel(
                                        (string) (
                                            $withdrawal[
                                                'wallet_withdrawal_reviewed_at'
                                            ] ?? ''
                                        )
                                    );
                                ?>
                                <div
                                    class="px-6 py-4 flex items-start justify-between gap-5 flex-wrap"
                                >
                                    <div>
                                        <div
                                            class="flex items-center gap-2 flex-wrap"
                                        >
                                            <p class="font-bold text-gray-800">
                                                #<?= str_pad(
                                                    (string) $withdrawal[
                                                        'wallet_withdrawal_id'
                                                    ],
                                                    4,
                                                    '0',
                                                    STR_PAD_LEFT
                                                ) ?> · <?= htmlspecialchars(
                                                    (string) $withdrawal[
                                                        'wallet_withdrawal_bank_name'
                                                    ],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?> ••••<?= htmlspecialchars(
                                                    (string) $withdrawal[
                                                        'wallet_withdrawal_account_number_last4'
                                                    ],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </p>
                                            <span
                                                class="<?= $withdrawal_status_classes[$withdrawal_status] ?? 'bg-gray-100 text-gray-600' ?> text-[11px] font-bold px-2.5 py-1 rounded-full capitalize"
                                            >
                                                <?= htmlspecialchars(
                                                    $withdrawal_status,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </span>
                                        </div>

                                        <p class="text-xs text-gray-400 mt-1">
                                            Requested <?= htmlspecialchars(
                                                date(
                                                    'd M Y, h:i A',
                                                    strtotime(
                                                        (string) $withdrawal[
                                                            'wallet_withdrawal_created_at'
                                                        ]
                                                    )
                                                ),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>

                                        <?php if (!empty(
                                            $withdrawal[
                                                'wallet_withdrawal_admin_note'
                                            ]
                                        )): ?>
                                            <p class="text-xs text-gray-500 mt-2">
                                                Admin note: <?= htmlspecialchars(
                                                    (string) $withdrawal[
                                                        'wallet_withdrawal_admin_note'
                                                    ],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </p>
                                        <?php endif; ?>

                                        <?php if (
                                            $withdrawal_status ===
                                                'failed' &&
                                            !empty(
                                                $withdrawal[
                                                    'wallet_withdrawal_failure_reason'
                                                ]
                                            )
                                        ): ?>
                                            <p
                                                class="text-xs text-orange-600 mt-2"
                                            >
                                                Transfer failure:
                                                <?= htmlspecialchars(
                                                    (string) $withdrawal[
                                                        'wallet_withdrawal_failure_reason'
                                                    ],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </p>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty(
                                            $withdrawal[
                                                'wallet_withdrawal_transfer_reference'
                                            ]
                                        )): ?>
                                            <p class="text-xs text-gray-500 mt-1">
                                                Transfer reference:
                                                <span class="font-mono font-semibold">
                                                    <?= htmlspecialchars(
                                                        (string) $withdrawal[
                                                            'wallet_withdrawal_transfer_reference'
                                                        ],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>
                                                </span>
                                            </p>
                                        <?php endif; ?>

                                        <?php if (
                                            $withdrawal_status === 'approved' &&
                                            $withdrawal_deadline !== ''
                                        ): ?>
                                            <p
                                                class="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-blue-50 px-2.5 py-1.5 text-[11px] font-semibold text-blue-700"
                                            >
                                                ⏱ Estimated processing by <?= htmlspecialchars(
                                                    $withdrawal_deadline,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>

                                    <div class="text-right">
                                        <p class="font-black text-gray-800">
                                            RM <?= moneyFormatSen(
                                                moneyDecimalToSen(
                                                    (string) $withdrawal[
                                                        'wallet_withdrawal_amount'
                                                    ]
                                                )
                                            ) ?>
                                        </p>

                                        <?php if (in_array(
                                            $withdrawal_status,
                                            [
                                                'approved',
                                                'completed',
                                            ],
                                            true
                                        )): ?>
                                            <a
                                                href="wallet_withdrawal_receipt.php?id=<?= (int) $withdrawal['wallet_withdrawal_id'] ?>&amp;download=1"
                                                class="mt-2 inline-flex items-center gap-1.5 rounded-lg border <?= $withdrawal_status === 'completed'
                                                    ? 'border-green-200 bg-green-50 text-green-700 hover:bg-green-100'
                                                    : 'border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100' ?> px-3 py-2 text-xs font-bold transition-colors"
                                            >
                                                📄 <?= $withdrawal_status === 'completed'
                                                    ? 'Transfer Confirmation PDF'
                                                    : 'Approval Advice PDF' ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
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
                                Latest 20 wallet ledger events
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
                                Top-ups, refunds and bank withdrawal events will appear here.
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
