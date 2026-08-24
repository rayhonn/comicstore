<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/money_helper.php';
require_once __DIR__ . '/../includes/wallet_helper.php';
require_once __DIR__ . '/../includes/wallet_withdrawal_lifecycle_helper.php';

$userId = current_user_id();
walletWithdrawalUseMalaysiaDatabaseTime($pdo);

try {
    assertWalletWithdrawalLifecycleSchema($pdo);

    $wallet = getWalletSummary($pdo, $userId);
    $transactions = getWalletTransactions($pdo, $userId, 20);
    $withdrawalSummary = getWalletWithdrawalSummaryLifecycle($pdo, $userId);
    $withdrawals = getCustomerWalletWithdrawalsLifecycle($pdo, $userId, 20);
} catch (Throwable $e) {
    app_error_log('Customer wallet page failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load your wallet.');
}

$withdrawableRefundSen = min(
    (int) $withdrawalSummary['eligible_sen'],
    (int) $wallet['available_sen']
);
$retryEligibleSen = min(
    (int) ($withdrawalSummary['retry_eligible_sen'] ?? 0),
    $withdrawableRefundSen
);
$retryExpiry = trim((string) ($withdrawalSummary['retry_expires_at_myt'] ?? ''));
$activeWithdrawal = $withdrawalSummary['active_request'];

$walletTopupSuccess = !empty($_SESSION['wallet_topup_success']);
unset($_SESSION['wallet_topup_success']);

$walletWithdrawalSubmitted = !empty($_SESSION['wallet_withdrawal_submitted']);
unset($_SESSION['wallet_withdrawal_submitted']);

$typeLabels = [
    'topup' => 'Wallet Top-Up',
    'return_refund' => 'Return Refund',
    'cancellation_refund' => 'Order Cancellation Refund',
    'payment_timeout_refund' => 'Payment Timeout Refund',
    'order_payment' => 'Wallet Order Payment',
    'order_payment_refund' => 'Wallet Order Refund',
    'withdrawal_reserve' => 'Bank Withdrawal Reserved',
    'withdrawal_release' => 'Bank Withdrawal Released',
    'withdrawal_complete' => 'Bank Withdrawal Settled',
];

function customerWithdrawalTime(
    array $request,
    string $field,
    string $fallback = 'Pending'
): string {
    $value = walletWithdrawalLifecycleEventMyt(
        $request,
        $field,
        'd M Y, h:i A',
        $fallback
    );

    return $value === $fallback
        ? $fallback
        : $value . ' MYT';
}

function customerWithdrawalTimeline(array $request): array
{
    $status = (string) ($request['wallet_withdrawal_status'] ?? '');
    $bank = (string) ($request['wallet_withdrawal_bank_status'] ?? 'not_submitted');
    $settlement = (string) (
        $request['wallet_withdrawal_bank_settlement_status'] ?? 'not_required'
    );

    $timeline = [
        [
            'label' => 'Requested',
            'time' => customerWithdrawalTime(
                $request,
                'wallet_withdrawal_created_at',
                'Received'
            ),
            'state' => 'done',
            'detail' => 'Withdrawal request received.',
        ],
    ];

    if ($status === 'pending') {
        $timeline[] = [
            'label' => 'MangaVault Review',
            'time' => 'Pending',
            'state' => 'current',
            'detail' => 'Awaiting administrator authorization.',
        ];
        return $timeline;
    }

    if ($status === 'rejected') {
        $timeline[] = [
            'label' => 'MangaVault Rejected',
            'time' => customerWithdrawalTime(
                $request,
                'wallet_withdrawal_reviewed_at',
                'Rejected'
            ),
            'state' => 'failed',
            'detail' => 'Rejected before bank submission.',
        ];
        $timeline[] = [
            'label' => 'Funds Released',
            'time' => customerWithdrawalTime(
                $request,
                'wallet_withdrawal_reviewed_at',
                'Released'
            ),
            'state' => 'done',
            'detail' => 'Reserved amount returned to available wallet balance.',
        ];
        return $timeline;
    }

    $timeline[] = [
        'label' => 'MangaVault Approved',
        'time' => customerWithdrawalTime(
            $request,
            'wallet_withdrawal_reviewed_at',
            'Approved'
        ),
        'state' => 'done',
        'detail' => 'Instruction submitted to the destination bank.',
    ];

    if ($bank === 'pending') {
        $timeline[] = [
            'label' => 'Bank Verification',
            'time' => 'Pending',
            'state' => 'current',
            'detail' => 'Destination bank is reviewing the instruction.',
        ];
        return $timeline;
    }

    if ($bank === 'rejected') {
        $timeline[] = [
            'label' => 'Bank Rejected',
            'time' => customerWithdrawalTime(
                $request,
                'wallet_withdrawal_bank_decided_at',
                'Rejected'
            ),
            'state' => 'failed',
            'detail' => 'Destination bank rejected verification.',
        ];

        if ($status === 'approved') {
            $timeline[] = [
                'label' => 'Reconciliation',
                'time' => 'Legacy exception',
                'state' => 'warning',
                'detail' => 'Historical state mismatch is awaiting synchronization.',
            ];
        } else {
            $timeline[] = [
                'label' => 'Funds Released',
                'time' => customerWithdrawalTime(
                    $request,
                    'wallet_withdrawal_failed_at',
                    'Released'
                ),
                'state' => 'done',
                'detail' => 'Reserved amount returned automatically to available balance.',
            ];
        }
        return $timeline;
    }

    if ($bank === 'approved') {
        $timeline[] = [
            'label' => 'Bank Accepted',
            'time' => customerWithdrawalTime(
                $request,
                'wallet_withdrawal_bank_decided_at',
                'Accepted'
            ),
            'state' => 'done',
            'detail' => 'Verification accepted for settlement.',
        ];
    }

    if ($settlement === 'ready') {
        $timeline[] = [
            'label' => 'Settlement',
            'time' => 'Ready',
            'state' => 'current',
            'detail' => 'Waiting for settlement processing to start.',
        ];
        return $timeline;
    }

    if ($settlement === 'processing') {
        $timeline[] = [
            'label' => 'Settlement Processing',
            'time' => customerWithdrawalTime(
                $request,
                'wallet_withdrawal_bank_settlement_started_at',
                'Processing'
            ),
            'state' => 'current',
            'detail' => 'Destination bank is processing settlement.',
        ];
        return $timeline;
    }

    if ($status === 'completed' && $settlement === 'settled') {
        $timeline[] = [
            'label' => 'Settlement Processing',
            'time' => customerWithdrawalTime(
                $request,
                'wallet_withdrawal_bank_settlement_started_at',
                'Started'
            ),
            'state' => 'done',
            'detail' => 'Settlement processing started.',
        ];
        $timeline[] = [
            'label' => 'Settled',
            'time' => customerWithdrawalTime(
                $request,
                'wallet_withdrawal_bank_settled_at',
                customerWithdrawalTime(
                    $request,
                    'wallet_withdrawal_completed_at',
                    'Completed'
                )
            ),
            'state' => 'done',
            'detail' => 'Transfer completed and wallet debit posted.',
        ];
        return $timeline;
    }

    if ($status === 'failed' && $settlement === 'failed') {
        $timeline[] = [
            'label' => 'Settlement Processing',
            'time' => customerWithdrawalTime(
                $request,
                'wallet_withdrawal_bank_settlement_started_at',
                'Started'
            ),
            'state' => 'done',
            'detail' => 'Settlement processing started.',
        ];
        $timeline[] = [
            'label' => 'Settlement Failed',
            'time' => customerWithdrawalTime(
                $request,
                'wallet_withdrawal_failed_at',
                'Failed'
            ),
            'state' => 'failed',
            'detail' => 'Settlement failed and reserved funds were released.',
        ];
        return $timeline;
    }

    return $timeline;
}

function customerWithdrawalBadge(array $request): array
{
    [$key, $label] = walletWithdrawalCustomerStage($request);

    $classes = match ($key) {
        'settled' => 'bg-green-100 text-green-700 border-green-200',
        'rejected', 'merchant_rejected', 'failed' => 'bg-red-100 text-red-700 border-red-200',
        'accepted', 'processing', 'bank_review' => 'bg-blue-100 text-blue-700 border-blue-200',
        'exception' => 'bg-amber-100 text-amber-800 border-amber-200',
        default => 'bg-yellow-100 text-yellow-800 border-yellow-200',
    };

    return [$label, $classes];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Wallet - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html { scroll-behavior: smooth; }
        body { opacity: 0; animation: fadeIn .35s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="min-h-screen bg-[#F5F0EB]">

<?php include '../includes/customer_navbar.php'; ?>

<div class="mx-auto max-w-7xl px-6 py-8">
    <p class="mb-6 text-sm text-gray-400">
        <a href="../index.php" class="transition-colors hover:text-red-600">Home</a>
        <span class="mx-2">›</span>
        <a href="dashboard.php" class="transition-colors hover:text-red-600">My Account</a>
        <span class="mx-2">›</span>
        <span class="text-gray-600">My Wallet</span>
    </p>

    <div class="flex items-start gap-8">
        <?php include '../includes/customer_sidebar.php'; ?>

        <main class="min-w-0 flex-1">
            <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-black text-gray-800">My Wallet</h1>
                    <p class="mt-1 text-sm text-gray-400">Secure wallet top-ups, refund credits and refund-to-bank transfers.</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <?php if ($withdrawableRefundSen >= 100 && !$activeWithdrawal): ?>
                        <a href="wallet_withdrawal.php" class="rounded-xl bg-[#1e2d4a] px-5 py-2.5 text-sm font-bold text-white transition-colors hover:bg-[#162338]">
                            Withdraw Refund to Bank
                        </a>
                    <?php endif; ?>
                    <a href="wallet_topup.php" class="rounded-xl bg-red-600 px-5 py-2.5 text-sm font-bold text-white transition-colors hover:bg-red-700">
                        + Top Up Wallet
                    </a>
                </div>
            </div>

            <?php if ($walletTopupSuccess): ?>
                <div class="mb-5 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">Wallet top-up completed successfully.</div>
            <?php endif; ?>
            <?php if (isset($_GET['topup_cancelled'])): ?>
                <div class="mb-5 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">Wallet top-up was cancelled. No wallet balance was added.</div>
            <?php endif; ?>
            <?php if (isset($_GET['topup_error'])): ?>
                <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">The wallet top-up could not be verified. Please check your transaction history before trying again.</div>
            <?php endif; ?>
            <?php if ($walletWithdrawalSubmitted): ?>
                <div class="mb-5 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                    Bank withdrawal request submitted successfully. The requested refund amount is reserved while MangaVault reviews the request. A notification and email have been issued.
                </div>
            <?php endif; ?>

            <section class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="rounded-2xl bg-gradient-to-br from-[#17243d] to-[#293957] p-6 text-white shadow-sm md:col-span-2">
                    <p class="text-xs font-semibold uppercase tracking-[.18em] text-white/50">Available Balance</p>
                    <p class="mt-2 text-4xl font-black">RM <?= moneyFormatSen($wallet['available_sen']) ?></p>
                    <div class="mt-6 flex gap-7 border-t border-white/10 pt-5 text-sm">
                        <div>
                            <p class="text-xs text-white/45">Total Balance</p>
                            <p class="mt-1 font-bold">RM <?= moneyFormatSen($wallet['balance_sen']) ?></p>
                        </div>
                        <div>
                            <p class="text-xs text-white/45">Reserved</p>
                            <p class="mt-1 font-bold">RM <?= moneyFormatSen($wallet['reserved_sen']) ?></p>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl bg-white p-6 shadow-sm">
                    <div class="mb-4 flex h-11 w-11 items-center justify-center rounded-xl bg-red-50 text-xl text-red-600">+</div>
                    <p class="font-bold text-gray-800">Add Funds</p>
                    <p class="mb-5 mt-2 text-xs leading-relaxed text-gray-400">Top-ups are added only after Stripe confirms and MangaVault verifies a successful payment.</p>
                    <a href="wallet_topup.php" class="block w-full rounded-xl bg-red-600 py-2.5 text-center text-sm font-semibold text-white transition-colors hover:bg-red-700">Top Up Now</a>
                </div>
            </section>

            <section class="mb-6 overflow-hidden rounded-2xl bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-100 px-6 py-5">
                    <div>
                        <h2 class="font-black text-gray-800">Refund Bank Withdrawal</h2>
                        <p class="mt-1 text-xs text-gray-400">Only refund credits are cash-withdrawable. Stripe wallet top-ups cannot be withdrawn.</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-400">Eligible Now</p>
                        <p class="text-2xl font-black text-green-600">RM <?= moneyFormatSen($withdrawableRefundSen) ?></p>
                    </div>
                </div>

                <div class="p-6">
                    <?php if ($activeWithdrawal): ?>
                        <?php [$activeLabel, $activeClass] = customerWithdrawalBadge($activeWithdrawal); ?>
                        <div class="rounded-2xl border border-blue-200 bg-blue-50/60 p-5">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="font-black text-blue-950">Active Request #<?= str_pad((string) $activeWithdrawal['wallet_withdrawal_id'], 4, '0', STR_PAD_LEFT) ?></p>
                                        <span class="rounded-full border px-2.5 py-1 text-[10px] font-black uppercase tracking-wider <?= $activeClass ?>"><?= htmlspecialchars($activeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <p class="mt-2 text-xs leading-relaxed text-blue-700">The requested amount remains reserved until a final bank outcome. A bank rejection or settlement failure releases the reserve automatically.</p>
                                </div>
                                <p class="text-xl font-black text-blue-800">RM <?= moneyFormatSen(moneyDecimalToSen((string) $activeWithdrawal['wallet_withdrawal_amount'])) ?></p>
                            </div>
                        </div>
                    <?php elseif ($withdrawableRefundSen >= 100): ?>
                        <?php if ($retryEligibleSen > 0): ?>
                            <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                <strong>Correction retry available.</strong>
                                RM <?= moneyFormatSen($retryEligibleSen) ?> from a failed bank attempt is eligible again<?= $retryExpiry !== '' ? ' until ' . htmlspecialchars(walletWithdrawalMalaysiaWallClock($retryExpiry, 'd M Y, h:i A'), ENT_QUOTES, 'UTF-8') . ' MYT (UTC+8)' : '' ?>. You may submit corrected bank details without losing the returned wallet funds.
                            </div>
                        <?php endif; ?>
                        <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-green-200 bg-green-50 px-5 py-4">
                            <div>
                                <p class="font-bold text-green-800">Refund balance available for bank withdrawal</p>
                                <p class="mt-1 text-xs text-green-700">A new request reserves only the amount you choose. The wallet balance is debited only after successful bank settlement.</p>
                            </div>
                            <a href="wallet_withdrawal.php" class="rounded-xl bg-green-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-green-800">Withdraw to Bank</a>
                        </div>
                    <?php else: ?>
                        <div class="py-4 text-center">
                            <p class="font-bold text-gray-600">No refund balance is currently eligible for bank withdrawal.</p>
                            <p class="mt-2 text-xs text-gray-400">Eligible refund credits or an active correction-retry window will appear here automatically.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="mb-6 overflow-hidden rounded-2xl bg-white shadow-sm">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="font-black text-gray-800">Bank Withdrawal History</h2>
                    <p class="mt-1 text-xs text-gray-400">All lifecycle times below are Malaysia Time (MYT, UTC+8).</p>
                </div>

                <?php if ($withdrawals === []): ?>
                    <div class="px-6 py-12 text-center text-sm text-gray-400">No bank withdrawal records yet.</div>
                <?php else: ?>
                    <div class="divide-y divide-gray-100">
                        <?php foreach ($withdrawals as $withdrawal): ?>
                            <?php
                            $withdrawalId = (int) $withdrawal['wallet_withdrawal_id'];
                            $amountSen = moneyDecimalToSen((string) $withdrawal['wallet_withdrawal_amount']);
                            $bankStatus = (string) ($withdrawal['wallet_withdrawal_bank_status'] ?? 'not_submitted');
                            $status = (string) ($withdrawal['wallet_withdrawal_status'] ?? '');
                            $timeline = customerWithdrawalTimeline($withdrawal);
                            [$badgeLabel, $badgeClass] = customerWithdrawalBadge($withdrawal);
                            $retryActive = walletWithdrawalRetryIsActive($withdrawal);
                            $retryLabel = walletWithdrawalRetryDeadlineLabel($withdrawal);
                            $failureReason = trim((string) ($withdrawal['wallet_withdrawal_failure_reason'] ?? ''));
                            $bankNote = trim((string) ($withdrawal['wallet_withdrawal_bank_decision_note'] ?? ''));
                            ?>
                            <article class="px-6 py-5">
                                <div class="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="font-black text-gray-800">#<?= str_pad((string) $withdrawalId, 4, '0', STR_PAD_LEFT) ?> · <?= htmlspecialchars((string) $withdrawal['wallet_withdrawal_bank_name'], ENT_QUOTES, 'UTF-8') ?> ·••••<?= htmlspecialchars((string) $withdrawal['wallet_withdrawal_account_number_last4'], ENT_QUOTES, 'UTF-8') ?></h3>
                                            <span class="rounded-full border px-2.5 py-1 text-[10px] font-black uppercase tracking-wide <?= $badgeClass ?>"><?= htmlspecialchars($badgeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <p class="mt-1 text-xs text-gray-400">Requested <?= htmlspecialchars(customerWithdrawalTime($withdrawal, 'wallet_withdrawal_created_at', 'Not available'), ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <p class="text-lg font-black text-gray-800">RM <?= moneyFormatSen($amountSen) ?></p>
                                </div>

                                <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-5">
                                    <?php foreach ($timeline as $item): ?>
                                        <?php
                                        $timelineClass = match ($item['state']) {
                                            'failed' => 'border-red-200 bg-red-50',
                                            'current' => 'border-blue-200 bg-blue-50',
                                            'warning' => 'border-amber-200 bg-amber-50',
                                            default => 'border-gray-200 bg-gray-50',
                                        };
                                        ?>
                                        <div class="rounded-lg border px-3 py-2.5 <?= $timelineClass ?>">
                                            <p class="text-[9px] font-black uppercase tracking-wider text-gray-500"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></p>
                                            <p class="mt-1 text-[10px] font-semibold text-gray-700"><?= htmlspecialchars($item['time'], ENT_QUOTES, 'UTF-8') ?></p>
                                            <p class="mt-1 text-[9px] leading-relaxed text-gray-500"><?= htmlspecialchars($item['detail'], ENT_QUOTES, 'UTF-8') ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php if ($status === 'failed' && $bankStatus === 'rejected'): ?>
                                    <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-xs text-red-700">
                                        <strong>Bank verification rejected.</strong>
                                        Reserved funds were released automatically to your available wallet balance.
                                        <?php if ($bankNote !== ''): ?> Reason: <?= htmlspecialchars($bankNote, ENT_QUOTES, 'UTF-8') ?>.<?php endif; ?>
                                    </div>
                                <?php elseif ($status === 'failed' && $failureReason !== ''): ?>
                                    <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-xs text-red-700">
                                        <strong>Transfer failed.</strong> <?= htmlspecialchars($failureReason, ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($retryActive): ?>
                                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-800">
                                        <span><strong>Correction retry:</strong> this failed amount can be requested again until <?= htmlspecialchars($retryLabel, ENT_QUOTES, 'UTF-8') ?> MYT (UTC+8).</span>
                                        <?php if (!$activeWithdrawal && $withdrawableRefundSen >= 100): ?>
                                            <a href="wallet_withdrawal.php" class="font-bold text-amber-900 underline">Retry with corrected details</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="mt-4 flex flex-wrap gap-2">
                                    <a href="wallet_withdrawal_receipt.php?id=<?= $withdrawalId ?>&amp;download=1" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-bold text-gray-600 hover:bg-gray-50">
                                        <?= $status === 'completed' ? 'Download Final Transfer PDF' : 'Download Official Withdrawal PDF' ?>
                                    </a>

                                    <?php if (in_array($bankStatus, ['approved', 'rejected'], true)): ?>
                                        <a href="wallet_withdrawal_bank_confirmation.php?id=<?= $withdrawalId ?>&amp;download=1" class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700 hover:bg-blue-100">
                                            Download Bank Decision PDF
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="overflow-hidden rounded-2xl bg-white shadow-sm">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="font-black text-gray-800">Wallet Transaction History</h2>
                    <p class="mt-1 text-xs text-gray-400">Reserve, release and settlement entries are recorded separately for auditability.</p>
                </div>

                <?php if ($transactions === []): ?>
                    <div class="px-6 py-10 text-center text-sm text-gray-400">No wallet transactions yet.</div>
                <?php else: ?>
                    <div class="divide-y divide-gray-100">
                        <?php foreach ($transactions as $transaction): ?>
                            <?php
                            $effect = (string) ($transaction['wallet_tx_effect'] ?? '');
                            $type = (string) ($transaction['wallet_tx_type'] ?? '');
                            $amount = moneyDecimalToSen((string) ($transaction['wallet_tx_amount'] ?? '0'));
                            $amountPrefix = in_array($effect, ['credit', 'release'], true) ? '+' : ($effect === 'debit' ? '-' : '');
                            $amountClass = in_array($effect, ['credit', 'release'], true) ? 'text-green-600' : ($effect === 'debit' ? 'text-red-600' : 'text-amber-600');
                            $txTime = walletWithdrawalMalaysiaWallClock(
                                $transaction['wallet_tx_created_at'] ?? '',
                                'd M Y, h:i A',
                                'Not available'
                            );
                            ?>
                            <div class="flex flex-wrap items-center justify-between gap-4 px-6 py-4">
                                <div>
                                    <p class="text-sm font-bold text-gray-700"><?= htmlspecialchars($typeLabels[$type] ?? ucwords(str_replace('_', ' ', $type)), ENT_QUOTES, 'UTF-8') ?></p>
                                    <p class="mt-1 text-xs text-gray-400"><?= htmlspecialchars((string) ($transaction['wallet_tx_description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                    <p class="mt-1 text-[10px] text-gray-400"><?= htmlspecialchars($txTime, ENT_QUOTES, 'UTF-8') ?> MYT</p>
                                </div>
                                <div class="text-right">
                                    <p class="font-black <?= $amountClass ?>"><?= $amountPrefix ?>RM <?= moneyFormatSen($amount) ?></p>
                                    <p class="mt-1 text-[10px] text-gray-400">Effect: <?= htmlspecialchars(ucfirst($effect), ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

</body>
</html>
