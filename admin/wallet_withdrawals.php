<?php

require_once __DIR__ . '/../includes/auth.php';
require_senior_admin();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/money_helper.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/wallet_withdrawal_helper.php';
require_once __DIR__ . '/../includes/wallet_withdrawal_lifecycle_helper.php';
require_once __DIR__ . '/../includes/wallet_withdrawal_mail_helper.php';

walletWithdrawalUseMalaysiaDatabaseTime($pdo);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $withdrawalId = filter_input(
        INPUT_POST,
        'withdrawal_id',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $action = $_POST['action'] ?? null;

    if (
        $withdrawalId === false ||
        $withdrawalId === null ||
        !is_string($action) ||
        !in_array($action, ['approve', 'reject'], true)
    ) {
        $error = 'Invalid bank withdrawal action.';
    } else {
        try {
            if ($action === 'approve') {
                $requestResult = approveWalletWithdrawalRequest(
                    $pdo,
                    (int) $withdrawalId,
                    current_user_id(),
                    normalizeWalletWithdrawalAdminNote(
                        $_POST['admin_note'] ?? '',
                        false
                    )
                );
            } else {
                $requestResult = rejectWalletWithdrawalRequest(
                    $pdo,
                    (int) $withdrawalId,
                    current_user_id(),
                    normalizeWalletWithdrawalAdminNote(
                        $_POST['admin_note'] ?? '',
                        true
                    )
                );
            }

            dispatchWalletWithdrawalLifecycleCommunication(
                $pdo,
                (int) $withdrawalId,
                $action === 'approve'
                    ? 'merchant_approved'
                    : 'merchant_rejected'
            );

            header(
                'Location: wallet_withdrawals.php?' . http_build_query([
                    'status' => $action === 'approve' ? 'approved' : 'rejected',
                    'result' => $action,
                    'id' => (int) $withdrawalId,
                ])
            );
            exit;
        } catch (WalletWithdrawalException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            app_error_log(
                'Admin bank withdrawal processing failed: ' . $e->getMessage()
            );
            $error = 'Unable to process the bank withdrawal request.';
        }
    }
}

$allowedFilters = [
    'all',
    'pending',
    'approved',
    'rejected',
    'failed',
    'completed',
];
$statusFilter = $_GET['status'] ?? 'pending';
if (!is_string($statusFilter) || !in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = 'pending';
}

$countRows = $pdo->query("
    SELECT wallet_withdrawal_status, COUNT(*) AS total
    FROM wallet_withdrawal_requests
    GROUP BY wallet_withdrawal_status
")->fetchAll(PDO::FETCH_ASSOC);

$counts = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'failed' => 0,
    'completed' => 0,
];
foreach ($countRows as $row) {
    $key = (string) $row['wallet_withdrawal_status'];
    if (array_key_exists($key, $counts)) {
        $counts[$key] = (int) $row['total'];
    }
}

$sql = "
    SELECT
        wr.*,
        customer.user_name,
        customer.user_first_name,
        customer.user_last_name,
        customer.user_gmail,
        reviewer.user_first_name AS reviewer_first_name,
        reviewer.user_last_name AS reviewer_last_name,
        decision_operator.bank_gateway_operator_display_name AS bank_decision_operator,
        starter.bank_gateway_operator_display_name AS bank_settlement_starter,
        settler.bank_gateway_operator_display_name AS bank_settlement_operator
    FROM wallet_withdrawal_requests wr
    INNER JOIN users customer
        ON customer.user_id = wr.wallet_withdrawal_user_id
    LEFT JOIN users reviewer
        ON reviewer.user_id = wr.wallet_withdrawal_reviewed_by
    LEFT JOIN bank_gateway_operators decision_operator
        ON decision_operator.bank_gateway_operator_id = wr.wallet_withdrawal_bank_decided_by
    LEFT JOIN bank_gateway_operators starter
        ON starter.bank_gateway_operator_id = wr.wallet_withdrawal_bank_settlement_started_by
    LEFT JOIN bank_gateway_operators settler
        ON settler.bank_gateway_operator_id = wr.wallet_withdrawal_bank_settled_by
";
$params = [];
if ($statusFilter !== 'all') {
    $sql .= ' WHERE wr.wallet_withdrawal_status = ? ';
    $params[] = $statusFilter;
}
$sql .= "
    ORDER BY
        CASE wr.wallet_withdrawal_status
            WHEN 'pending' THEN 0
            WHEN 'approved' THEN 1
            WHEN 'failed' THEN 2
            WHEN 'completed' THEN 3
            ELSE 4
        END,
        wr.wallet_withdrawal_id DESC
";
$statement = $pdo->prepare($sql);
$statement->execute($params);
$requests = $statement->fetchAll(PDO::FETCH_ASSOC);

$result = isset($_GET['result']) && is_string($_GET['result'])
    ? $_GET['result']
    : '';
$resultId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

function adminWithdrawalBankStage(array $request): array
{
    $status = (string) ($request['wallet_withdrawal_status'] ?? '');
    $bank = (string) ($request['wallet_withdrawal_bank_status'] ?? 'not_submitted');
    $settlement = (string) (
        $request['wallet_withdrawal_bank_settlement_status'] ?? 'not_required'
    );

    if ($status === 'pending') {
        return ['Merchant Review', 'bg-amber-100 text-amber-800', 'Awaiting MangaVault decision'];
    }
    if ($status === 'approved' && $bank === 'pending') {
        return ['Bank Verification', 'bg-yellow-100 text-yellow-800', 'Destination bank review pending'];
    }
    if ($status === 'approved' && $bank === 'approved' && $settlement === 'ready') {
        return ['Settlement Ready', 'bg-blue-100 text-blue-800', 'Bank accepted; waiting to start settlement'];
    }
    if ($status === 'approved' && $bank === 'approved' && $settlement === 'processing') {
        return ['Settlement Processing', 'bg-cyan-100 text-cyan-800', 'Bank settlement is in progress'];
    }
    if ($status === 'approved' && $bank === 'rejected') {
        return ['Reconciliation Exception', 'bg-purple-100 text-purple-800', 'Historical rejected record requires bank reconciliation'];
    }
    if ($status === 'completed') {
        return ['Settled', 'bg-green-100 text-green-800', 'Bank settled and wallet debit posted'];
    }
    if ($status === 'failed' && $bank === 'rejected') {
        return ['Bank Rejected', 'bg-red-100 text-red-800', 'Bank rejected and reserved funds released'];
    }
    if ($status === 'failed') {
        return ['Settlement Failed', 'bg-orange-100 text-orange-800', 'Settlement failed and funds released'];
    }
    if ($status === 'rejected') {
        return ['Merchant Rejected', 'bg-red-100 text-red-800', 'Admin rejected before bank submission'];
    }
    return ['Record', 'bg-gray-100 text-gray-700', 'Withdrawal lifecycle record'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wallet Withdrawals - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800">

<?php include '../includes/admin_navbar.php'; ?>

<main class="mx-auto max-w-7xl px-5 py-8 md:px-7">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-black tracking-tight text-slate-950">Wallet Bank Withdrawals</h1>
                <span class="rounded-full border border-red-100 bg-red-50 px-3 py-1 text-[10px] font-black uppercase tracking-wider text-red-600">Senior Admin Only</span>
            </div>
            <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-500">
                MangaVault Admin authorizes or rejects the customer request. After approval, the destination institution independently verifies and settles the instruction. Admin can monitor the lifecycle but cannot manually mark a bank transfer completed or failed, preserving segregation of duties.
            </p>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php elseif ($result !== '' && $resultId !== false && $resultId !== null): ?>
        <div class="mb-5 rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-700">
            Withdrawal #<?= str_pad((string) $resultId, 4, '0', STR_PAD_LEFT) ?> was <?= $result === 'approve' ? 'approved and submitted to the destination bank' : 'rejected and its wallet reserve released' ?> successfully.
        </div>
    <?php endif; ?>

    <section class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-5">
        <?php
        $summary = [
            'pending' => ['Pending', 'Merchant decision', 'border-amber-200 bg-amber-50 text-amber-800'],
            'approved' => ['In Bank Flow', 'Verification / settlement', 'border-blue-200 bg-blue-50 text-blue-800'],
            'failed' => ['Failed', 'Funds released', 'border-orange-200 bg-orange-50 text-orange-800'],
            'completed' => ['Settled', 'Wallet debit posted', 'border-green-200 bg-green-50 text-green-800'],
            'rejected' => ['Rejected', 'Before bank submission', 'border-red-200 bg-red-50 text-red-800'],
        ];
        ?>
        <?php foreach ($summary as $key => $card): ?>
            <a
                href="?status=<?= urlencode($key) ?>"
                class="rounded-2xl border p-4 transition hover:-translate-y-0.5 hover:shadow-md <?= $card[2] ?> <?= $statusFilter === $key ? 'ring-2 ring-slate-300 ring-offset-2' : '' ?>"
            >
                <div class="text-[10px] font-black uppercase tracking-wider opacity-70"><?= htmlspecialchars($card[0], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="mt-1 text-2xl font-black"><?= (int) $counts[$key] ?></div>
                <div class="mt-1 text-[10px] opacity-70"><?= htmlspecialchars($card[1], ENT_QUOTES, 'UTF-8') ?></div>
            </a>
        <?php endforeach; ?>
    </section>

    <div class="mb-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
        <div class="flex min-w-max items-center gap-2">
            <span class="px-3 text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Queue</span>
            <?php foreach ([
                'all' => 'All Requests',
                'pending' => 'Pending',
                'approved' => 'In Bank Flow',
                'failed' => 'Failed',
                'completed' => 'Settled',
                'rejected' => 'Rejected',
            ] as $key => $label): ?>
                <a
                    href="?status=<?= urlencode($key) ?>"
                    class="rounded-xl px-4 py-2.5 text-sm font-bold <?= $statusFilter === $key ? 'bg-slate-900 text-white' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-800' ?>"
                ><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($requests === []): ?>
        <section class="rounded-3xl border border-slate-200 bg-white px-6 py-16 text-center shadow-sm">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-xl font-black text-slate-400">WD</div>
            <p class="mt-4 font-black text-slate-700">No withdrawal requests in this queue</p>
        </section>
    <?php else: ?>
        <div class="space-y-5">
            <?php foreach ($requests as $request): ?>
                <?php
                $withdrawalId = (int) $request['wallet_withdrawal_id'];
                $status = (string) $request['wallet_withdrawal_status'];
                $bankStatus = (string) (
                    $request['wallet_withdrawal_bank_status'] ?? 'not_submitted'
                );
                $settlementStatus = (string) (
                    $request['wallet_withdrawal_bank_settlement_status'] ?? 'not_required'
                );
                [$stageLabel, $stageClass, $stageCopy] = adminWithdrawalBankStage($request);
                $customerName = trim(
                    (string) $request['user_first_name'] . ' ' .
                    (string) $request['user_last_name']
                );
                $amountSen = moneyDecimalToSen(
                    (string) $request['wallet_withdrawal_amount']
                );
                $shouldPoll = $status === 'approved';
                ?>
                <section
                    class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm"
                    <?= $shouldPoll ? 'data-bank-poll-id="' . $withdrawalId . '" data-bank-poll-signature="' . htmlspecialchars($status . '|' . $bankStatus . '|' . $settlementStatus, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
                >
                    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 bg-slate-50/70 px-6 py-5">
                        <div>
                            <div class="flex flex-wrap items-center gap-3">
                                <h2 class="text-lg font-black text-slate-900">Withdrawal #<?= str_pad((string) $withdrawalId, 4, '0', STR_PAD_LEFT) ?></h2>
                                <span class="rounded-full px-3 py-1 text-[10px] font-black uppercase tracking-wider <?= $stageClass ?>"><?= htmlspecialchars($stageLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <p class="mt-1 text-xs text-slate-400">
                                Requested <?= htmlspecialchars(walletWithdrawalLifecycleEventMyt($request, 'wallet_withdrawal_created_at', 'd M Y, h:i A', 'Not available') . ' MYT (UTC+8)', ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>
                        <div class="text-right">
                            <div class="text-2xl font-black text-red-600">RM <?= moneyFormatSen($amountSen) ?></div>
                            <div class="mt-1 text-[10px] font-semibold text-slate-400"><?= htmlspecialchars($stageCopy, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-6 p-6 lg:grid-cols-[1fr_360px]">
                        <div class="space-y-4">
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                <div class="rounded-2xl bg-slate-50 p-4">
                                    <div class="text-[10px] font-black uppercase tracking-wider text-slate-400">Customer</div>
                                    <div class="mt-1 font-black text-slate-800"><?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="mt-1 text-xs text-slate-400"><?= htmlspecialchars((string) $request['user_gmail'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <div class="rounded-2xl bg-slate-50 p-4">
                                    <div class="text-[10px] font-black uppercase tracking-wider text-slate-400">Destination</div>
                                    <div class="mt-1 font-black text-slate-800"><?= htmlspecialchars((string) $request['wallet_withdrawal_bank_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars((string) $request['wallet_withdrawal_account_holder'], ENT_QUOTES, 'UTF-8') ?> · ••••<?= htmlspecialchars((string) $request['wallet_withdrawal_account_number_last4'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <div class="rounded-2xl bg-slate-50 p-4">
                                    <div class="text-[10px] font-black uppercase tracking-wider text-slate-400">Bank Lifecycle</div>
                                    <div class="mt-1 font-black text-slate-800"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $bankStatus)), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="mt-1 text-xs text-slate-500">Settlement: <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $settlementStatus)), ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            </div>

                            <?php if (!empty($request['wallet_withdrawal_bank_submission_id'])): ?>
                                <div class="rounded-2xl border border-slate-200 p-4">
                                    <div class="flex flex-wrap items-start justify-between gap-4">
                                        <div>
                                            <div class="text-[10px] font-black uppercase tracking-wider text-slate-400">Bank Submission ID</div>
                                            <div class="mt-1 break-all font-mono text-sm font-bold text-slate-700"><?= htmlspecialchars((string) $request['wallet_withdrawal_bank_submission_id'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-[10px] font-black uppercase tracking-wider text-slate-400">Submitted</div>
                                            <div class="mt-1 text-sm font-bold text-slate-700"><?= htmlspecialchars(walletWithdrawalLifecycleEventMyt($request, 'wallet_withdrawal_bank_submitted_at', 'd M Y, h:i A', 'Not available') . ' MYT (UTC+8)', ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($bankStatus === 'approved'): ?>
                                <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4">
                                    <div class="text-sm font-black text-blue-900">Bank verification accepted</div>
                                    <div class="mt-1 text-xs leading-5 text-blue-700">
                                        Authorization reference: <span class="font-mono font-bold"><?= htmlspecialchars((string) ($request['wallet_withdrawal_bank_decision_reference'] ?? 'Not available'), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div class="mt-1 text-xs text-blue-700">
                                        Decision: <?= htmlspecialchars(walletWithdrawalLifecycleEventMyt($request, 'wallet_withdrawal_bank_decided_at', 'd M Y, h:i A', 'Not available') . ' MYT (UTC+8)', ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </div>
                            <?php elseif ($bankStatus === 'rejected'): ?>
                                <div class="rounded-2xl border border-red-200 bg-red-50 p-4">
                                    <div class="text-sm font-black text-red-900">Bank verification rejected</div>
                                    <div class="mt-1 text-xs leading-5 text-red-700">
                                        <?= htmlspecialchars((string) ($request['wallet_withdrawal_bank_decision_note'] ?? 'No bank reason recorded.'), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <div class="mt-1 text-xs text-red-700">
                                        Decision: <?= htmlspecialchars(walletWithdrawalLifecycleEventMyt($request, 'wallet_withdrawal_bank_decided_at', 'd M Y, h:i A', 'Not available') . ' MYT (UTC+8)', ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($settlementStatus === 'processing'): ?>
                                <div class="rounded-2xl border border-cyan-200 bg-cyan-50 p-4">
                                    <div class="text-sm font-black text-cyan-900">Settlement is processing</div>
                                    <div class="mt-1 text-xs text-cyan-700">
                                        Started <?= htmlspecialchars(walletWithdrawalLifecycleEventMyt($request, 'wallet_withdrawal_bank_settlement_started_at', 'd M Y, h:i A', 'Not available') . ' MYT (UTC+8)', ENT_QUOTES, 'UTF-8') ?>. MangaVault cannot complete this transfer manually.
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($status === 'completed'): ?>
                                <div class="rounded-2xl border border-green-200 bg-green-50 p-4">
                                    <div class="text-sm font-black text-green-900">Settlement completed and wallet debit posted</div>
                                    <div class="mt-2 grid grid-cols-1 gap-2 text-xs text-green-800 md:grid-cols-2">
                                        <div><strong>Settlement reference:</strong><br><span class="font-mono"><?= htmlspecialchars((string) ($request['wallet_withdrawal_transfer_reference'] ?? 'Not available'), ENT_QUOTES, 'UTF-8') ?></span></div>
                                        <div><strong>Settled:</strong><br><?= htmlspecialchars(walletWithdrawalLifecycleEventMyt($request, !empty($request['wallet_withdrawal_bank_settled_at']) ? 'wallet_withdrawal_bank_settled_at' : 'wallet_withdrawal_completed_at', 'd M Y, h:i A', 'Not available') . ' MYT (UTC+8)', ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($status === 'failed'): ?>
                                <div class="rounded-2xl border border-orange-200 bg-orange-50 p-4">
                                    <div class="text-sm font-black text-orange-900">Bank flow closed without settlement</div>
                                    <div class="mt-1 text-xs leading-5 text-orange-700">
                                        Reserved funds were released automatically. <?= htmlspecialchars((string) ($request['wallet_withdrawal_failure_reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <?php if (walletWithdrawalRetryIsActive($request)): ?>
                                        <div class="mt-2 text-xs font-bold text-orange-800">
                                            Customer correction retry available until <?= htmlspecialchars(walletWithdrawalRetryDeadlineLabel($request), ENT_QUOTES, 'UTF-8') ?> MYT (UTC+8).
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="flex flex-wrap gap-2">
                                <?php if (in_array($status, ['pending', 'approved'], true)): ?>
                                    <a href="wallet_withdrawal_bank.php?id=<?= $withdrawalId ?>" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-100">🔐 Reveal Protected Bank Details</a>
                                <?php endif; ?>

                                <?php if (in_array($bankStatus, ['approved', 'rejected'], true)): ?>
                                    <a href="wallet_withdrawal_bank_confirmation.php?id=<?= $withdrawalId ?>&amp;download=1" class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-bold text-blue-700 hover:bg-blue-100">Download Bank Decision PDF</a>
                                <?php endif; ?>

                                <a href="wallet_withdrawal_receipt.php?id=<?= $withdrawalId ?>&amp;download=1" class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-bold text-indigo-700 hover:bg-indigo-100">
                                    <?= $status === 'completed' ? 'Download Transfer Confirmation PDF' : 'Download Official Withdrawal PDF' ?>
                                </a>

                                <?php if ($status === 'completed' && !empty($request['wallet_withdrawal_receipt_file'])): ?>
                                    <a href="wallet_withdrawal_evidence.php?id=<?= $withdrawalId ?>" target="_blank" rel="noopener noreferrer" class="rounded-xl border border-green-200 bg-green-50 px-4 py-2 text-sm font-bold text-green-700 hover:bg-green-100">View Legacy Transfer Evidence</a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <aside>
                            <?php if ($status === 'pending'): ?>
                                <div class="rounded-2xl border border-green-200 bg-green-50 p-4">
                                    <div class="text-sm font-black text-green-900">Approve & Submit to Bank</div>
                                    <p class="mt-1 text-xs leading-5 text-green-700">Approval authorizes the withdrawal but does not send money. The destination bank must verify and settle independently.</p>
                                    <form method="POST" class="mt-4 space-y-3" onsubmit="return confirm('Approve this withdrawal and submit it to the destination bank?')">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="withdrawal_id" value="<?= $withdrawalId ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <textarea name="admin_note" maxlength="1000" rows="3" placeholder="Optional approval note..." class="w-full resize-none rounded-xl border border-green-200 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-green-200"></textarea>
                                        <button type="submit" class="w-full rounded-xl bg-green-600 py-2.5 text-sm font-black text-white hover:bg-green-700">Approve & Submit</button>
                                    </form>
                                </div>

                                <div class="mt-4 rounded-2xl border border-red-200 bg-red-50 p-4">
                                    <div class="text-sm font-black text-red-900">Reject Before Bank Submission</div>
                                    <p class="mt-1 text-xs leading-5 text-red-700">This releases the wallet reserve immediately. A reason is required.</p>
                                    <form method="POST" class="mt-4 space-y-3" onsubmit="return confirm('Reject this withdrawal and release the reserved funds?')">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="withdrawal_id" value="<?= $withdrawalId ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <textarea name="admin_note" maxlength="1000" rows="3" required placeholder="Rejection reason..." class="w-full resize-none rounded-xl border border-red-200 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-red-200"></textarea>
                                        <button type="submit" class="w-full rounded-xl bg-red-600 py-2.5 text-sm font-black text-white hover:bg-red-700">Reject & Release</button>
                                    </form>
                                </div>
                            <?php elseif ($status === 'approved'): ?>
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-900 text-xs font-black text-white">BL</div>
                                    <div class="mt-3 text-sm font-black text-slate-900">Bank-controlled stage</div>
                                    <p class="mt-1 text-xs leading-5 text-slate-600">
                                        Admin actions are locked after submission. Verification, settlement and failure handling must be posted by the destination bank operator. This prevents the same role from authorizing and settling its own payout.
                                    </p>
                                    <?php if ($bankStatus === 'rejected' && $status === 'approved'): ?>
                                        <div class="mt-4 rounded-xl border border-purple-200 bg-purple-50 p-3 text-xs font-semibold leading-5 text-purple-700">
                                            Historical mismatch detected. Open the bank Reconciliation queue and use “Reconcile & Release”. Do not manually change database values.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($status === 'completed'): ?>
                                <div class="rounded-2xl border border-green-200 bg-green-50 p-5">
                                    <div class="text-sm font-black text-green-900">Read-only settled record</div>
                                    <p class="mt-1 text-xs leading-5 text-green-700">The destination bank confirmed settlement and the wallet debit was posted automatically. No Admin completion action is available.</p>
                                </div>
                            <?php elseif ($status === 'failed'): ?>
                                <div class="rounded-2xl border border-orange-200 bg-orange-50 p-5">
                                    <div class="text-sm font-black text-orange-900">Read-only failed record</div>
                                    <p class="mt-1 text-xs leading-5 text-orange-700">The bank workflow ended without settlement and reserved wallet funds were released automatically.</p>
                                </div>
                            <?php else: ?>
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                                    <div class="text-sm font-black text-slate-900">Read-only record</div>
                                    <p class="mt-1 text-xs leading-5 text-slate-600">This withdrawal is final and no further action is required.</p>
                                </div>
                            <?php endif; ?>
                        </aside>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<script>
    async function pollBankLifecycle() {
        const cards = document.querySelectorAll('[data-bank-poll-id]');

        await Promise.all(Array.from(cards).map(async (card) => {
            const id = card.dataset.bankPollId;
            const original = card.dataset.bankPollSignature;

            try {
                const response = await fetch(
                    'wallet_withdrawal_bank_status.php?' + new URLSearchParams({ id }),
                    {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { Accept: 'application/json' },
                    }
                );
                if (!response.ok) return;
                const payload = await response.json();
                if (!payload.ok) return;

                const current = [
                    payload.withdrawal_status,
                    payload.bank_status,
                    payload.settlement_status,
                ].join('|');

                if (current !== original || payload.is_final) {
                    window.location.reload();
                }
            } catch (error) {
                // Temporary polling failures do not interrupt Admin work.
            }
        }));
    }

    if (document.querySelector('[data-bank-poll-id]')) {
        window.setInterval(pollBankLifecycle, 8000);
    }
</script>

</body>
</html>
