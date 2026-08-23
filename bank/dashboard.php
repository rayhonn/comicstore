<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/money_helper.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ .
    '/../includes/bank_gateway_helper.php';

requireBankOperator();

$bank_code = currentBankOperatorCode();
$bank_name = (string) (
    $_SESSION['bank_operator_bank_name'] ??
    $bank_code
);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $withdrawal_id = filter_input(
        INPUT_POST,
        'withdrawal_id',
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );
    $action = $_POST['action'] ?? null;

    if (
        $withdrawal_id === false ||
        $withdrawal_id === null ||
        !is_string($action) ||
        !in_array(
            $action,
            [
                'approve',
                'reject',
            ],
            true
        )
    ) {
        $error =
            'Invalid bank verification action.';
    } elseif (
        $action === 'approve' &&
        ($_POST['verification_ack'] ?? '') !== '1'
    ) {
        $error =
            'Confirm that the destination account and transfer instruction were verified.';
    } else {
        try {
            $result = decideBankGatewayWithdrawal(
                $pdo,
                (int) $withdrawal_id,
                currentBankOperatorId(),
                $bank_code,
                $action,
                $_POST[
                    'authorization_reference'
                ] ?? '',
                $_POST['decision_note'] ?? '',
                $_POST['current_password'] ?? null
            );

            try {
                $request_number = '#' . str_pad(
                    (string) $withdrawal_id,
                    4,
                    '0',
                    STR_PAD_LEFT
                );
                $amount = moneyFormatSen(
                    moneyDecimalToSen(
                        (string) $result[
                            'wallet_withdrawal_amount'
                        ]
                    )
                );

                if ($action === 'approve') {
                    sendNotification(
                        $pdo,
                        (int) $result[
                            'wallet_withdrawal_user_id'
                        ],
                        'Bank Verification Approved',
                        "The destination bank verified withdrawal $request_number for RM $amount. MangaVault administration can now perform and record the final transfer.",
                        'system'
                    );
                } else {
                    sendNotification(
                        $pdo,
                        (int) $result[
                            'wallet_withdrawal_user_id'
                        ],
                        'Bank Verification Rejected',
                        "The destination bank could not verify withdrawal $request_number for RM $amount. MangaVault administration will review the rejection and release the reserved funds if the transfer cannot proceed.",
                        'system'
                    );
                }
            } catch (Throwable $notificationError) {
                app_error_log(
                    'Bank gateway notification failed: ' .
                    $notificationError->getMessage()
                );
            }

            redirect_to(
                app_path('bank/dashboard.php') .
                '?' .
                http_build_query([
                    'status' => $action === 'approve'
                        ? 'approved'
                        : 'rejected',
                    'result' => $action,
                    'id' => (int) $withdrawal_id,
                ])
            );
        } catch (
            BankGatewayException |
            WalletWithdrawalException $e
        ) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            app_error_log(
                'Bank gateway decision failed: ' .
                $e->getMessage()
            );

            $error =
                'Unable to process the bank verification decision.';
        }
    }
}

$allowed_filters = [
    'pending',
    'approved',
    'rejected',
    'all',
];
$status_filter = $_GET['status'] ?? 'pending';

if (
    !is_string($status_filter) ||
    !in_array(
        $status_filter,
        $allowed_filters,
        true
    )
) {
    $status_filter = 'pending';
}

try {
    $counts = getBankGatewayQueueCounts(
        $pdo,
        $bank_code
    );
    $requests = getBankGatewayQueue(
        $pdo,
        $bank_code,
        $status_filter
    );
} catch (Throwable $e) {
    app_error_log(
        'Bank gateway queue failed: ' .
        $e->getMessage()
    );

    http_response_code(500);
    exit('Unable to load the bank verification queue.');
}

$result = $_GET['result'] ?? '';
$result_id = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
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
    <title><?= htmlspecialchars(
        $bank_name,
        ENT_QUOTES,
        'UTF-8'
    ) ?> Transfer Instruction Review</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link
        rel="stylesheet"
        href="<?= htmlspecialchars(
            app_path('assets/css/bank_portal.css'),
            ENT_QUOTES,
            'UTF-8'
        ) ?>"
    >
</head>
<body class="bank-portal-body min-h-screen">

    <?php require '../includes/bank_navbar.php'; ?>

    <main class="mx-auto max-w-7xl px-5 py-8 md:px-8">
        <div
            class="mb-6 flex items-start justify-between gap-5 flex-wrap"
        >
            <div>
                <div class="flex items-center gap-3 flex-wrap">
                    <h1 class="text-2xl font-black text-slate-900">
                        Transfer Instruction Review
                    </h1>
                    <span
                        class="rounded-full border border-cyan-200 bg-cyan-50 px-3 py-1 text-[10px] font-black uppercase tracking-wider text-cyan-700"
                    >
                        <?= htmlspecialchars(
                            $bank_name,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?> only
                    </span>
                </div>
                <p
                    class="mt-2 max-w-3xl text-sm leading-relaxed text-slate-500"
                >
                    Review merchant-authorized refund instructions assigned to
                    this institution. Accepting an instruction releases it to
                    merchant operations; it does not confirm that funds were sent.
                </p>
            </div>

            <div
                class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-right shadow-sm"
            >
                <p
                    class="text-[10px] font-black uppercase tracking-wider text-slate-400"
                >
                    Malaysia Time
                </p>
                <p
                    id="malaysiaClock"
                    class="mt-1 font-mono text-sm font-black text-slate-800"
                >
                    Loading MYT...
                </p>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div
                class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
            >
                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>
        <?php elseif (
            in_array(
                $result,
                [
                    'approve',
                    'reject',
                ],
                true
            ) &&
            $result_id !== false &&
            $result_id !== null
        ): ?>
            <div
                class="mb-5 rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"
            >
                Withdrawal #<?= str_pad(
                    (string) $result_id,
                    4,
                    '0',
                    STR_PAD_LEFT
                ) ?> was <?= $result === 'approve'
                    ? 'verified and approved'
                    : 'rejected' ?> successfully.
            </div>
        <?php endif; ?>

        <section
            class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3"
        >
            <?php foreach ([
                'pending' => [
                    'Pending Review',
                    $counts['pending'],
                    'border-amber-200 bg-amber-50 text-amber-800',
                    'Awaiting institution action',
                ],
                'approved' => [
                    'Accepted',
                    $counts['approved'],
                    'border-green-200 bg-green-50 text-green-800',
                    'Released to merchant operations',
                ],
                'rejected' => [
                    'Declined',
                    $counts['rejected'],
                    'border-red-200 bg-red-50 text-red-800',
                    'Requires merchant resolution',
                ],
            ] as $key => $card): ?>
                <a
                    href="?status=<?= urlencode($key) ?>"
                    class="rounded-2xl border p-5 transition-all hover:-translate-y-0.5 hover:shadow-md <?= $card[2] ?> <?= $status_filter === $key
                        ? 'ring-2 ring-slate-300 ring-offset-2'
                        : '' ?>"
                >
                    <p
                        class="text-xs font-black uppercase tracking-wider opacity-70"
                    >
                        <?= $card[0] ?>
                    </p>
                    <div
                        class="mt-2 flex items-end justify-between gap-4"
                    >
                        <p class="text-3xl font-black">
                            <?= (int) $card[1] ?>
                        </p>
                        <p class="text-xs opacity-70">
                            <?= $card[3] ?>
                        </p>
                    </div>
                </a>
            <?php endforeach; ?>
        </section>

        <div
            class="mb-6 overflow-x-auto rounded-2xl bg-white p-3 shadow-sm"
        >
            <div class="flex min-w-max items-center gap-2">
                <span
                    class="px-3 text-[10px] font-black uppercase tracking-[0.18em] text-slate-400"
                >
                    Work Queue
                </span>
                <?php foreach ([
                    'pending' => 'Pending Review',
                    'approved' => 'Accepted',
                    'rejected' => 'Declined',
                    'all' => 'All Records',
                ] as $value => $label): ?>
                    <a
                        href="?status=<?= urlencode($value) ?>"
                        class="rounded-xl px-4 py-2.5 text-sm font-bold transition-colors <?= $status_filter === $value
                            ? 'bg-[#12233f] text-white'
                            : 'text-slate-500 hover:bg-slate-100 hover:text-slate-800' ?>"
                    >
                        <?= $label ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($requests === []): ?>
            <section
                class="rounded-3xl bg-white px-6 py-16 text-center shadow-sm"
            >
                <div class="text-5xl" aria-hidden="true">🏦</div>
                <p class="mt-4 font-black text-slate-700">
                    No transfer instructions in this queue
                </p>
                <p class="mt-1 text-sm text-slate-400">
                    New instructions appear after merchant administrator approval.
                </p>
            </section>
        <?php else: ?>
            <div class="space-y-5">
                <?php foreach ($requests as $request): ?>
                    <?php
                    $withdrawal_id = (int) $request[
                        'wallet_withdrawal_id'
                    ];
                    $bank_status = (string) $request[
                        'wallet_withdrawal_bank_status'
                    ];
                    $customer_name = trim(
                        (string) $request[
                            'user_first_name'
                        ] .
                        ' ' .
                        (string) $request[
                            'user_last_name'
                        ]
                    );
                    ?>
                    <section
                        class="overflow-hidden rounded-3xl bg-white shadow-sm ring-1 ring-slate-200"
                    >
                        <div
                            class="flex items-start justify-between gap-5 border-b border-slate-100 px-6 py-5 flex-wrap"
                        >
                            <div>
                                <div
                                    class="flex items-center gap-3 flex-wrap"
                                >
                                    <h2
                                        class="text-lg font-black text-slate-900"
                                    >
                                        Instruction #<?= str_pad(
                                            (string) $withdrawal_id,
                                            4,
                                            '0',
                                            STR_PAD_LEFT
                                        ) ?>
                                    </h2>
                                    <span
                                        class="rounded-full px-3 py-1 text-[10px] font-black uppercase tracking-wider <?= $bank_status === 'pending'
                                            ? 'bg-amber-100 text-amber-700'
                                            : (
                                                $bank_status === 'approved'
                                                    ? 'bg-green-100 text-green-700'
                                                    : 'bg-red-100 text-red-700'
                                            ) ?>"
                                    >
                                        <?= match ($bank_status) {
                                            'pending' => 'Pending Review',
                                            'approved' => 'Accepted',
                                            default => 'Declined',
                                        } ?>
                                    </span>
                                </div>
                                <p
                                    class="mt-1 text-xs text-slate-400"
                                >
                                    Instruction reference
                                    <span
                                        class="font-mono font-semibold text-slate-500"
                                    >
                                        <?= htmlspecialchars(
                                            (string) $request[
                                                'wallet_withdrawal_bank_submission_id'
                                            ],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </span>
                                </p>
                            </div>

                            <p class="text-2xl font-black text-cyan-700">
                                RM <?= moneyFormatSen(
                                    moneyDecimalToSen(
                                        (string) $request[
                                            'wallet_withdrawal_amount'
                                        ]
                                    )
                                ) ?>
                            </p>
                        </div>

                        <div
                            class="grid grid-cols-1 gap-6 p-6 lg:grid-cols-[1fr_0.86fr]"
                        >
                            <div class="space-y-4">
                                <div
                                    class="grid grid-cols-1 gap-4 md:grid-cols-2"
                                >
                                    <div
                                        class="rounded-2xl bg-slate-50 p-4"
                                    >
                                        <p
                                            class="text-[10px] font-black uppercase tracking-wider text-slate-400"
                                        >
                                            Customer
                                        </p>
                                        <p
                                            class="mt-1 font-black text-slate-800"
                                        >
                                            <?= htmlspecialchars(
                                                $customer_name,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>
                                        <p
                                            class="mt-1 text-xs text-slate-400"
                                        >
                                            <?= htmlspecialchars(
                                                (string) $request[
                                                    'user_gmail'
                                                ],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>
                                    </div>

                                    <div
                                        class="rounded-2xl bg-slate-50 p-4"
                                    >
                                        <p
                                            class="text-[10px] font-black uppercase tracking-wider text-slate-400"
                                        >
                                            Account Holder
                                        </p>
                                        <p
                                            class="mt-1 font-black text-slate-800"
                                        >
                                            <?= htmlspecialchars(
                                                (string) $request[
                                                    'wallet_withdrawal_account_holder'
                                                ],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>
                                        <p
                                            class="mt-1 text-xs text-slate-400"
                                        >
                                            Name must match bank records
                                        </p>
                                    </div>
                                </div>

                                <div
                                    class="rounded-2xl border border-cyan-100 bg-cyan-50 p-5"
                                >
                                    <div
                                        class="flex items-center justify-between gap-4 flex-wrap"
                                    >
                                        <div>
                                            <p
                                                class="text-[10px] font-black uppercase tracking-wider text-cyan-600"
                                            >
                                                Destination Account
                                            </p>
                                            <p
                                                class="mt-1 text-sm font-black text-cyan-950"
                                            >
                                                <?= htmlspecialchars(
                                                    $bank_name,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </p>
                                        </div>

                                        <button
                                            type="button"
                                            onclick="openAccountAccessModal(
                                                <?= $withdrawal_id ?>,
                                                '<?= htmlspecialchars(
                                                    (string) $request[
                                                        'wallet_withdrawal_account_number_last4'
                                                    ],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>'
                                            )"
                                            class="rounded-xl border border-cyan-200 bg-white px-4 py-2 text-xs font-bold text-cyan-700 transition-colors hover:bg-cyan-100"
                                        >
                                            Securely View Account
                                        </button>
                                    </div>

                                    <p
                                        class="mt-4 font-mono text-2xl font-black tracking-[0.18em] text-cyan-900"
                                    >
                                        •••• •••• <?= htmlspecialchars(
                                            (string) $request[
                                                'wallet_withdrawal_account_number_last4'
                                            ],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </p>

                                    <p class="mt-2 text-[10px] text-cyan-700">
                                        Full account details require operator
                                        password re-authorization and are audit logged.
                                    </p>
                                </div>

                                <div
                                    class="grid grid-cols-1 gap-3 text-xs sm:grid-cols-3"
                                >
                                    <div
                                        class="rounded-xl border border-slate-200 px-3 py-3"
                                    >
                                        <p class="text-slate-400">
                                            Customer request
                                        </p>
                                        <p
                                            class="mt-1 font-bold text-slate-700"
                                        >
                                            <?= htmlspecialchars(
                                                walletWithdrawalMalaysiaDateTime(
                                                    (string) $request[
                                                        'wallet_withdrawal_created_at'
                                                    ],
                                                    'd M Y, h:i A'
                                                ) . ' MYT',
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>
                                    </div>
                                    <div
                                        class="rounded-xl border border-slate-200 px-3 py-3"
                                    >
                                        <p class="text-slate-400">
                                            Admin approved
                                        </p>
                                        <p
                                            class="mt-1 font-bold text-slate-700"
                                        >
                                            <?= htmlspecialchars(
                                                walletWithdrawalMalaysiaDateTime(
                                                    (string) $request[
                                                        'wallet_withdrawal_reviewed_at'
                                                    ],
                                                    'd M Y, h:i A'
                                                ) . ' MYT',
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>
                                    </div>
                                    <div
                                        class="rounded-xl border border-slate-200 px-3 py-3"
                                    >
                                        <p class="text-slate-400">
                                            Sent to bank
                                        </p>
                                        <p
                                            class="mt-1 font-bold text-slate-700"
                                        >
                                            <?= htmlspecialchars(
                                                walletWithdrawalMalaysiaDateTime(
                                                    (string) $request[
                                                        'wallet_withdrawal_bank_submitted_at'
                                                    ],
                                                    'd M Y, h:i A'
                                                ) . ' MYT',
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <?php if ($bank_status === 'pending'): ?>
                                    <div
                                        class="rounded-2xl border border-green-200 bg-green-50 p-5"
                                    >
                                        <p
                                            class="text-sm font-black text-green-900"
                                        >
                                            Accept Transfer Instruction
                                        </p>
                                        <p
                                            class="mt-1 text-xs leading-relaxed text-green-700"
                                        >
                                            Record that this institution accepts
                                            the instruction for subsequent merchant processing.
                                        </p>

                                        <form
                                            method="POST"
                                            class="mt-4 space-y-3"
                                            data-confirm-title="Accept Transfer Instruction"
                                            data-confirm-message="Confirm that the destination institution accepts this instruction? Merchant operations will receive the signed institution decision record."
                                            data-confirm-button="Accept Instruction"
                                            onsubmit="return openBankDecisionModal(event, this)"
                                        >
                                            <?php csrf_field(); ?>
                                            <input
                                                type="hidden"
                                                name="withdrawal_id"
                                                value="<?= $withdrawal_id ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="approve"
                                            >
                                            <input
                                                type="text"
                                                name="authorization_reference"
                                                minlength="8"
                                                maxlength="80"
                                                pattern="[A-Za-z0-9][A-Za-z0-9._/-]{7,79}"
                                                autocomplete="off"
                                                required
                                                placeholder="Institution authorization reference"
                                                class="w-full rounded-xl border border-green-200 bg-white px-4 py-3 text-sm outline-none focus:border-green-500"
                                            >
                                            <textarea
                                                name="decision_note"
                                                maxlength="1000"
                                                rows="3"
                                                placeholder="Optional verification note"
                                                class="w-full resize-none rounded-xl border border-green-200 bg-white px-4 py-3 text-sm outline-none focus:border-green-500"
                                            ></textarea>
                                            <label
                                                class="flex items-start gap-3 rounded-xl border border-green-200 bg-white px-4 py-3 text-xs leading-relaxed text-green-800"
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="verification_ack"
                                                    value="1"
                                                    required
                                                    class="mt-0.5 h-4 w-4 rounded border-green-300 text-green-600"
                                                >
                                                <span>
                                                    I verified the account holder, destination account, amount, and transfer instruction against the institution record.
                                                </span>
                                            </label>
                                            <input
                                                type="password"
                                                name="current_password"
                                                maxlength="72"
                                                autocomplete="current-password"
                                                required
                                                placeholder="Current operator password"
                                                class="w-full rounded-xl border border-green-200 bg-white px-4 py-3 text-sm outline-none focus:border-green-500"
                                            >
                                            <button
                                                type="submit"
                                                class="w-full rounded-xl bg-green-600 px-4 py-3 text-sm font-black text-white transition-colors hover:bg-green-700"
                                            >
                                                Accept Transfer Instruction
                                            </button>
                                        </form>
                                    </div>

                                    <div
                                        class="mt-4 rounded-2xl border border-red-200 bg-red-50 p-5"
                                    >
                                        <p
                                            class="text-sm font-black text-red-900"
                                        >
                                            Decline Transfer Instruction
                                        </p>
                                        <p
                                            class="mt-1 text-xs leading-relaxed text-red-700"
                                        >
                                            Use when the destination account or transfer instruction cannot be accepted.
                                        </p>

                                        <form
                                            method="POST"
                                            class="mt-4 space-y-3"
                                            data-confirm-title="Decline Transfer Instruction"
                                            data-confirm-message="Decline this transfer instruction? Merchant operations will receive the reason for resolution."
                                            data-confirm-button="Decline Instruction"
                                            onsubmit="return openBankDecisionModal(event, this)"
                                        >
                                            <?php csrf_field(); ?>
                                            <input
                                                type="hidden"
                                                name="withdrawal_id"
                                                value="<?= $withdrawal_id ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="reject"
                                            >
                                            <textarea
                                                name="decision_note"
                                                maxlength="1000"
                                                rows="3"
                                                required
                                                placeholder="Required decline reason"
                                                class="w-full resize-none rounded-xl border border-red-200 bg-white px-4 py-3 text-sm outline-none focus:border-red-500"
                                            ></textarea>
                                            <input
                                                type="password"
                                                name="current_password"
                                                maxlength="72"
                                                autocomplete="current-password"
                                                required
                                                placeholder="Current operator password"
                                                class="w-full rounded-xl border border-red-200 bg-white px-4 py-3 text-sm outline-none focus:border-red-500"
                                            >
                                            <button
                                                type="submit"
                                                class="w-full rounded-xl bg-red-600 px-4 py-3 text-sm font-black text-white transition-colors hover:bg-red-700"
                                            >
                                                Decline Transfer Instruction
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <div
                                        class="rounded-2xl border p-5 <?= $bank_status === 'approved'
                                            ? 'border-green-200 bg-green-50'
                                            : 'border-red-200 bg-red-50' ?>"
                                    >
                                        <p
                                            class="text-sm font-black <?= $bank_status === 'approved'
                                                ? 'text-green-900'
                                                : 'text-red-900' ?>"
                                        >
                                            Instruction <?= $bank_status === 'approved'
                                                ? 'Accepted'
                                                : 'Declined' ?>
                                        </p>
                                        <p
                                            class="mt-2 text-xs leading-relaxed <?= $bank_status === 'approved'
                                                ? 'text-green-700'
                                                : 'text-red-700' ?>"
                                        >
                                            Decision recorded
                                            <?= htmlspecialchars(
                                                walletWithdrawalMalaysiaDateTime(
                                                    (string) $request[
                                                        'wallet_withdrawal_bank_decided_at'
                                                    ],
                                                    'd M Y, h:i A'
                                                ) . ' MYT',
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                            by
                                            <?= htmlspecialchars(
                                                (string) $request[
                                                    'decision_operator_name'
                                                ],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>.
                                        </p>

                                        <?php if (!empty(
                                            $request[
                                                'wallet_withdrawal_bank_decision_reference'
                                            ]
                                        )): ?>
                                            <p
                                                class="mt-3 font-mono text-xs font-black text-green-800"
                                            >
                                                <?= htmlspecialchars(
                                                    (string) $request[
                                                        'wallet_withdrawal_bank_decision_reference'
                                                    ],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </p>
                                        <?php endif; ?>

                                        <?php if (!empty(
                                            $request[
                                                'wallet_withdrawal_bank_decision_note'
                                            ]
                                        )): ?>
                                            <p
                                                class="mt-3 text-xs <?= $bank_status === 'approved'
                                                    ? 'text-green-700'
                                                    : 'text-red-700' ?>"
                                            >
                                                <?= htmlspecialchars(
                                                    (string) $request[
                                                        'wallet_withdrawal_bank_decision_note'
                                                    ],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </p>
                                        <?php endif; ?>

                                        <?php if (
                                            $bank_status === 'approved'
                                        ): ?>
                                            <a
                                                href="confirmation.php?id=<?= $withdrawal_id ?>&amp;download=1"
                                                class="mt-4 inline-flex items-center gap-2 rounded-xl bg-green-600 px-4 py-2.5 text-xs font-black text-white transition-colors hover:bg-green-700"
                                            >
                                                📄 Download Institution Decision PDF
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <input
        type="hidden"
        id="bankAccountCsrf"
        value="<?= htmlspecialchars(
            csrf_token(),
            ENT_QUOTES,
            'UTF-8'
        ) ?>"
    >

    <div
        id="bankAccountAccessModal"
        class="fixed inset-0 z-[125] hidden items-center justify-center bg-slate-950/70 px-4 py-8 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        aria-labelledby="bankAccountAccessTitle"
    >
        <div class="w-full max-w-md overflow-hidden rounded-3xl bg-white shadow-2xl">
            <div class="bg-gradient-to-r from-[#0d3158] to-[#071b33] px-6 py-5 text-white">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-cyan-300">
                    Protected customer information
                </p>
                <h2
                    id="bankAccountAccessTitle"
                    class="mt-1 text-xl font-black"
                >
                    Re-authorize account access
                </h2>
                <p class="mt-2 text-xs leading-5 text-slate-300">
                    This access is restricted to the assigned institution and
                    will be recorded in the audit log.
                </p>
            </div>

            <form id="bankAccountAccessForm" class="p-6" novalidate>
                <input type="hidden" id="accountAccessWithdrawalId">

                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-sm text-slate-500">Instruction</span>
                        <strong id="accountAccessNumber" class="text-slate-900">
                            #0000
                        </strong>
                    </div>
                    <div class="mt-3 flex items-center justify-between gap-4">
                        <span class="text-sm text-slate-500">Masked account</span>
                        <strong
                            id="accountAccessMasked"
                            class="font-mono text-slate-900"
                        >
                            •••• •••• 0000
                        </strong>
                    </div>
                </div>

                <label
                    for="accountAccessPassword"
                    class="mt-5 block text-xs font-black uppercase tracking-wider text-slate-500"
                >
                    Current Operator Password
                </label>
                <input
                    type="password"
                    id="accountAccessPassword"
                    maxlength="72"
                    autocomplete="current-password"
                    required
                    class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
                >

                <p
                    id="accountAccessError"
                    class="mt-2 min-h-5 text-xs text-red-600"
                    role="alert"
                    aria-live="polite"
                ></p>

                <div
                    id="accountAccessResult"
                    class="mt-4 hidden rounded-2xl border border-blue-200 bg-blue-50 p-4"
                    aria-live="polite"
                >
                    <p class="text-[10px] font-black uppercase tracking-wider text-blue-600">
                        Authorized Account Number
                    </p>
                    <p
                        id="accountAccessFullNumber"
                        class="mt-2 break-all font-mono text-xl font-black tracking-[0.12em] text-blue-950"
                    ></p>
                    <p id="accountAccessHolder" class="mt-2 text-xs text-blue-700"></p>
                </div>

                <div class="mt-6 grid grid-cols-2 gap-3">
                    <button
                        type="button"
                        onclick="closeAccountAccessModal()"
                        class="rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold text-slate-600 hover:bg-slate-50"
                    >
                        Close
                    </button>
                    <button
                        type="submit"
                        id="accountAccessSubmit"
                        class="rounded-xl bg-blue-700 px-4 py-3 text-sm font-black text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        Authorize Access
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div
        id="bankDecisionModal"
        class="fixed inset-0 z-[120] hidden items-center justify-center bg-slate-950/70 px-4 py-8 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        aria-labelledby="bankDecisionModalTitle"
    >
        <div
            class="w-full max-w-md overflow-hidden rounded-3xl bg-white shadow-2xl"
        >
            <div
                class="bg-gradient-to-r from-[#12233f] to-[#08111f] px-6 py-5 text-white"
            >
                <div class="flex items-center gap-4">
                    <div
                        class="flex h-12 w-12 items-center justify-center rounded-2xl bg-cyan-300/15 text-2xl"
                        aria-hidden="true"
                    >
                        🏦
                    </div>
                    <div>
                        <p
                            class="text-[10px] font-black uppercase tracking-[0.18em] text-cyan-300"
                        >
                            Dual authorization control
                        </p>
                        <h2
                            id="bankDecisionModalTitle"
                            class="mt-1 text-xl font-black"
                        >
                            Confirm Decision
                        </h2>
                    </div>
                </div>
            </div>

            <div class="p-6">
                <div
                    class="rounded-2xl border border-slate-200 bg-slate-50 p-4"
                >
                    <div
                        class="flex items-center justify-between gap-4"
                    >
                        <span class="text-sm text-slate-500">
                            Instruction
                        </span>
                        <strong
                            id="bankDecisionModalNumber"
                            class="text-slate-900"
                        >
                            #0000
                        </strong>
                    </div>
                </div>

                <p
                    id="bankDecisionModalMessage"
                    class="mt-5 text-sm leading-6 text-slate-600"
                ></p>

                <div class="mt-6 grid grid-cols-2 gap-3">
                    <button
                        type="button"
                        onclick="closeBankDecisionModal()"
                        class="rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold text-slate-600 hover:bg-slate-50"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        id="bankDecisionModalConfirm"
                        onclick="confirmBankDecision()"
                        class="rounded-xl bg-[#12233f] px-4 py-3 text-sm font-black text-white hover:bg-[#08111f]"
                    >
                        Confirm
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let pendingBankDecisionForm = null;

        function updateMalaysiaClock() {
            const formatter = new Intl.DateTimeFormat(
                'en-MY',
                {
                    timeZone: 'Asia/Kuala_Lumpur',
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: true,
                }
            );

            document.getElementById(
                'malaysiaClock'
            ).textContent =
                formatter.format(new Date()) +
                ' MYT';
        }

        function openAccountAccessModal(
            withdrawalId,
            last4
        ) {
            const modal = document.getElementById(
                'bankAccountAccessModal'
            );

            document.getElementById(
                'accountAccessWithdrawalId'
            ).value = String(withdrawalId);
            document.getElementById(
                'accountAccessNumber'
            ).textContent =
                '#' + String(withdrawalId).padStart(4, '0');
            document.getElementById(
                'accountAccessMasked'
            ).textContent = '•••• •••• ' + last4;
            document.getElementById(
                'accountAccessPassword'
            ).value = '';
            document.getElementById(
                'accountAccessError'
            ).textContent = '';
            document.getElementById(
                'accountAccessResult'
            ).classList.add('hidden');
            document.getElementById(
                'accountAccessSubmit'
            ).disabled = false;
            document.getElementById(
                'accountAccessSubmit'
            ).textContent = 'Authorize Access';

            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.classList.add('overflow-hidden');

            window.requestAnimationFrame(
                () => document.getElementById(
                    'accountAccessPassword'
                ).focus()
            );
        }

        function closeAccountAccessModal() {
            const modal = document.getElementById(
                'bankAccountAccessModal'
            );

            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.classList.remove('overflow-hidden');
            document.getElementById(
                'accountAccessPassword'
            ).value = '';
            document.getElementById(
                'accountAccessFullNumber'
            ).textContent = '';
            document.getElementById(
                'accountAccessHolder'
            ).textContent = '';
            document.getElementById(
                'accountAccessResult'
            ).classList.add('hidden');
        }

        function openBankDecisionModal(
            event,
            form
        ) {
            event.preventDefault();

            if (!form.reportValidity()) {
                return false;
            }

            pendingBankDecisionForm = form;

            const withdrawal = form.querySelector(
                '[name="withdrawal_id"]'
            );

            document.getElementById(
                'bankDecisionModalTitle'
            ).textContent =
                form.dataset.confirmTitle ||
                'Confirm Decision';
            document.getElementById(
                'bankDecisionModalMessage'
            ).textContent =
                form.dataset.confirmMessage ||
                'Confirm this bank verification decision.';
            document.getElementById(
                'bankDecisionModalNumber'
            ).textContent =
                '#' + String(
                    withdrawal
                        ? withdrawal.value
                        : '0'
                ).padStart(4, '0');
            document.getElementById(
                'bankDecisionModalConfirm'
            ).textContent =
                form.dataset.confirmButton ||
                'Confirm';

            const modal = document.getElementById(
                'bankDecisionModal'
            );
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.classList.add(
                'overflow-hidden'
            );

            return false;
        }

        function closeBankDecisionModal() {
            const modal = document.getElementById(
                'bankDecisionModal'
            );
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.classList.remove(
                'overflow-hidden'
            );
            pendingBankDecisionForm = null;
        }

        function confirmBankDecision() {
            if (!pendingBankDecisionForm) {
                return;
            }

            const form = pendingBankDecisionForm;
            const button = document.getElementById(
                'bankDecisionModalConfirm'
            );
            button.disabled = true;
            button.textContent = 'Processing...';
            form.submit();
        }

        document.getElementById(
            'bankDecisionModal'
        ).addEventListener(
            'click',
            function (event) {
                if (event.target === this) {
                    closeBankDecisionModal();
                }
            }
        );

        document.getElementById(
            'bankAccountAccessModal'
        ).addEventListener(
            'click',
            function (event) {
                if (event.target === this) {
                    closeAccountAccessModal();
                }
            }
        );

        document.getElementById(
            'bankAccountAccessForm'
        ).addEventListener(
            'submit',
            async function (event) {
                event.preventDefault();

                const password = document.getElementById(
                    'accountAccessPassword'
                );
                const error = document.getElementById(
                    'accountAccessError'
                );
                const button = document.getElementById(
                    'accountAccessSubmit'
                );

                error.textContent = '';

                if (
                    password.value.length < 1 ||
                    password.value.length > 72
                ) {
                    error.textContent =
                        'Enter your current operator password.';
                    password.focus();
                    return;
                }

                const body = new URLSearchParams({
                    csrf_token: document.getElementById(
                        'bankAccountCsrf'
                    ).value,
                    withdrawal_id: document.getElementById(
                        'accountAccessWithdrawalId'
                    ).value,
                    current_password: password.value,
                });

                button.disabled = true;
                button.textContent = 'Authorizing…';

                try {
                    const response = await fetch(
                        'account_details.php',
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type':
                                    'application/x-www-form-urlencoded;charset=UTF-8',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: body.toString(),
                            credentials: 'same-origin',
                        }
                    );
                    const result = await response.json();

                    if (!response.ok || !result.ok) {
                        throw new Error(
                            result.message ||
                            'Account access could not be authorized.'
                        );
                    }

                    document.getElementById(
                        'accountAccessFullNumber'
                    ).textContent = result.account_number;
                    document.getElementById(
                        'accountAccessHolder'
                    ).textContent =
                        result.account_holder + ' · ' + result.bank_name;
                    document.getElementById(
                        'accountAccessResult'
                    ).classList.remove('hidden');
                    password.value = '';
                    button.textContent = 'Access Authorized';
                } catch (requestError) {
                    error.textContent = requestError.message;
                    button.disabled = false;
                    button.textContent = 'Authorize Access';
                }
            }
        );

        document.addEventListener(
            'keydown',
            function (event) {
                if (event.key === 'Escape') {
                    closeAccountAccessModal();
                    closeBankDecisionModal();
                }
            }
        );

        updateMalaysiaClock();
        window.setInterval(
            updateMalaysiaClock,
            1000
        );
    </script>
</body>
</html>
