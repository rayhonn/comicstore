<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/money_helper.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/bank_gateway_helper.php';

requireBankOperator();

$bankCode = currentBankOperatorCode();
$bankName = trim((string) (
    $_SESSION['bank_operator_bank_name'] ?? $bankCode
));
$operatorName = currentBankOperatorName();
$error = '';

try {
    assertBankGatewayEnterpriseSchema($pdo);
} catch (BankGatewayException $e) {
    http_response_code(500);
    exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $withdrawalId = filter_input(
        INPUT_POST,
        'withdrawal_id',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $action = $_POST['action'] ?? null;
    $allowedActions = [
        'approve',
        'reject',
        'start_settlement',
        'settle',
        'fail_settlement',
        'reconcile_rejection',
    ];

    if (
        $withdrawalId === false ||
        $withdrawalId === null ||
        !is_string($action) ||
        !in_array($action, $allowedActions, true)
    ) {
        $error = 'Invalid institution operations action.';
    } else {
        try {
            $requestResult = null;
            $nextQueue = 'all';
            $notificationTitle = '';
            $notificationMessage = '';

            if ($action === 'approve') {
                if (($_POST['verification_ack'] ?? '') !== '1') {
                    throw new BankGatewayException(
                        'Confirm the beneficiary and instruction verification before accepting this instruction.'
                    );
                }

                $requestResult = decideBankGatewayWithdrawal(
                    $pdo,
                    (int) $withdrawalId,
                    currentBankOperatorId(),
                    $bankCode,
                    'approve',
                    '',
                    $_POST['decision_note'] ?? '',
                    $_POST['current_password'] ?? null
                );
                $nextQueue = 'accepted';
                $notificationTitle = 'Bank Verification Accepted';
                $notificationMessage =
                    'Your destination bank accepted withdrawal #%s for settlement. The funds remain reserved while settlement is processed.';
            } elseif ($action === 'reject') {
                $requestResult = decideBankGatewayWithdrawal(
                    $pdo,
                    (int) $withdrawalId,
                    currentBankOperatorId(),
                    $bankCode,
                    'reject',
                    '',
                    $_POST['decision_note'] ?? '',
                    $_POST['current_password'] ?? null
                );
                $nextQueue = 'rejected';
                $notificationTitle = 'Bank Verification Rejected';
                $notificationMessage =
                    'Your destination bank rejected withdrawal #%s. The reserved amount was automatically released back to your MangaVault Wallet.';
            } elseif ($action === 'start_settlement') {
                $requestResult = startBankGatewaySettlement(
                    $pdo,
                    (int) $withdrawalId,
                    currentBankOperatorId(),
                    $bankCode,
                    $_POST['current_password'] ?? null,
                    $_POST['settlement_note'] ?? ''
                );
                $nextQueue = 'processing';
                $notificationTitle = 'Bank Settlement Processing';
                $notificationMessage =
                    'Settlement processing has started for withdrawal #%s. The reserved amount remains locked until settlement finishes.';
            } elseif ($action === 'settle') {
                if (($_POST['settlement_ack'] ?? '') !== '1') {
                    throw new BankGatewayException(
                        'Confirm that settlement is complete before posting the final debit.'
                    );
                }

                $requestResult = settleBankGatewayWithdrawal(
                    $pdo,
                    (int) $withdrawalId,
                    currentBankOperatorId(),
                    $bankCode,
                    $_POST['current_password'] ?? null,
                    $_POST['settlement_note'] ?? ''
                );
                $nextQueue = 'settled';
                $notificationTitle = 'Bank Transfer Settled';
                $notificationMessage =
                    'Withdrawal #%s has been settled successfully. Settlement reference: ' .
                    (string) ($requestResult['wallet_withdrawal_transfer_reference'] ?? '') .
                    '. The reserved amount has been permanently debited.';
            } elseif ($action === 'fail_settlement') {
                $requestResult = failBankGatewaySettlement(
                    $pdo,
                    (int) $withdrawalId,
                    currentBankOperatorId(),
                    $bankCode,
                    $_POST['current_password'] ?? null,
                    $_POST['failure_reason'] ?? ''
                );
                $nextQueue = 'failed';
                $notificationTitle = 'Bank Settlement Failed';
                $notificationMessage =
                    'Settlement failed for withdrawal #%s. The reserved amount was automatically released back to your MangaVault Wallet.';
            } else {
                $requestResult = reconcileRejectedBankGatewayWithdrawal(
                    $pdo,
                    (int) $withdrawalId,
                    currentBankOperatorId(),
                    $bankCode,
                    $_POST['current_password'] ?? null
                );
                $nextQueue = 'rejected';
                $notificationTitle = 'Bank Rejection Reconciled';
                $notificationMessage =
                    'A historical rejected bank instruction for withdrawal #%s was reconciled. The reserved amount has been released back to your MangaVault Wallet.';
            }

            if (!is_array($requestResult)) {
                throw new BankGatewayException('Institution operation result is missing.');
            }

            try {
                $requestNumber = str_pad(
                    (string) $withdrawalId,
                    4,
                    '0',
                    STR_PAD_LEFT
                );
                $amount = moneyFormatSen(
                    moneyDecimalToSen(
                        (string) $requestResult['wallet_withdrawal_amount']
                    )
                );
                $message = sprintf($notificationMessage, $requestNumber) .
                    ' Amount: RM ' . $amount . '.';

                sendNotification(
                    $pdo,
                    (int) $requestResult['wallet_withdrawal_user_id'],
                    $notificationTitle,
                    $message,
                    'system'
                );
            } catch (Throwable $notificationError) {
                app_error_log(
                    'Bank settlement notification failed: ' .
                    $notificationError->getMessage()
                );
            }

            redirect_to(
                app_path('bank/dashboard.php') . '?' . http_build_query([
                    'status' => $nextQueue,
                    'view' => (int) $withdrawalId,
                    'result' => $action,
                ])
            );
        } catch (BankGatewayException | WalletWithdrawalException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            app_error_log(
                'Enterprise bank operation failed: ' . $e->getMessage()
            );
            $error = 'Unable to complete the institution operation.';
        }
    }
}

$allowedFilters = [
    'pending',
    'accepted',
    'processing',
    'settled',
    'rejected',
    'failed',
    'exceptions',
    'all',
];
$statusFilter = $_GET['status'] ?? 'pending';
if (!is_string($statusFilter) || !in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = 'pending';
}

$search = isset($_GET['q']) && is_string($_GET['q'])
    ? trim($_GET['q'])
    : '';
if (strlen($search) > 100) {
    $search = substr($search, 0, 100);
}

try {
    $metrics = getBankGatewayMetrics($pdo, $bankCode);
    $counts = getBankGatewayQueueCounts($pdo, $bankCode);
    $requests = getBankGatewayQueue(
        $pdo,
        $bankCode,
        $statusFilter,
        $search,
        120
    );
} catch (Throwable $e) {
    app_error_log('Bank operations queue failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load the institution operations workspace.');
}

$selectedId = filter_input(
    INPUT_GET,
    'view',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$selected = null;
$auditTrail = [];

if ($selectedId !== false && $selectedId !== null) {
    try {
        $selected = loadBankGatewayWithdrawal(
            $pdo,
            (int) $selectedId,
            $bankCode,
            false
        );
        $auditTrail = getBankGatewayAuditTrail(
            $pdo,
            (int) $selectedId,
            $bankCode,
            30
        );
    } catch (BankGatewayException $e) {
        $error = $error !== '' ? $error : $e->getMessage();
    }
}

if ($selected === null && $requests !== []) {
    $selected = $requests[0];
    try {
        $auditTrail = getBankGatewayAuditTrail(
            $pdo,
            (int) $selected['wallet_withdrawal_id'],
            $bankCode,
            30
        );
    } catch (Throwable $e) {
        $auditTrail = [];
    }
}

$result = isset($_GET['result']) && is_string($_GET['result'])
    ? $_GET['result']
    : '';

function bankOpsMyt(string $utc, string $format = 'd M Y, h:i A'): string
{
    return walletWithdrawalMalaysiaDateTime(
        $utc,
        $format,
        'Not available'
    );
}

function bankOpsQueueAgeLabel(mixed $minutes): string
{
    $minutes = max(0, (int) $minutes);
    if ($minutes < 60) {
        return $minutes . 'm';
    }
    $hours = intdiv($minutes, 60);
    $remainder = $minutes % 60;
    if ($hours < 24) {
        return $hours . 'h ' . $remainder . 'm';
    }
    return intdiv($hours, 24) . 'd ' . ($hours % 24) . 'h';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($bankName, ENT_QUOTES, 'UTF-8') ?> Institution Operations</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link
        rel="stylesheet"
        href="<?= htmlspecialchars(app_path('assets/css/bank_portal.css'), ENT_QUOTES, 'UTF-8') ?>"
    >
    <link
        rel="stylesheet"
        href="<?= htmlspecialchars(app_path('assets/css/bank_operations.css'), ENT_QUOTES, 'UTF-8') ?>"
    >
</head>
<body class="bank-portal-body">

<?php require '../includes/bank_navbar.php'; ?>

<div class="bo-shell">
    <aside class="bo-sidebar" aria-label="Payment operations navigation">
        <div class="bo-sidebar-section">
            <div class="bo-sidebar-heading">PAYMENTS OPERATIONS</div>
            <nav class="bo-menu">
                <?php
                $navItems = [
                    'pending' => ['Review Queue', $counts['pending']],
                    'accepted' => ['Settlement Ready', $counts['accepted']],
                    'processing' => ['In Processing', $counts['processing']],
                    'exceptions' => ['Reconciliation', $counts['exceptions']],
                    'settled' => ['Settled', $counts['settled']],
                    'rejected' => ['Rejected', $counts['rejected']],
                    'failed' => ['Failed', $counts['failed']],
                    'all' => ['All Instructions', array_sum($counts)],
                ];
                ?>
                <?php foreach ($navItems as $key => $item): ?>
                    <a
                        class="bo-menu-link <?= $statusFilter === $key ? 'is-active' : '' ?>"
                        href="?<?= htmlspecialchars(http_build_query(['status' => $key]), ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <span><?= htmlspecialchars($item[0], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="bo-menu-count"><?= (int) $item[1] ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>

        <div class="bo-sidebar-footer">
            <div class="bo-scope-label">Institution</div>
            <div class="bo-scope-value"><?= htmlspecialchars($bankName, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="bo-scope-meta"><?= htmlspecialchars($bankCode, ENT_QUOTES, 'UTF-8') ?> · Operator scope enforced</div>
        </div>
    </aside>

    <main class="bo-main">
        <header class="bo-page-header">
            <div>
                <div class="bo-breadcrumb">Operations / Payments / Refund Settlement</div>
                <h1>Refund Settlement Instructions</h1>
                <p>Review and settle merchant-authorized refund instructions routed to <?= htmlspecialchars($bankName, ENT_QUOTES, 'UTF-8') ?>.</p>
            </div>
            <div class="bo-header-meta">
                <div class="bo-header-meta-label">BUSINESS DATE / MYT</div>
                <div id="malaysiaClock" class="bo-header-clock">Loading...</div>
                <div class="bo-header-operator"><?= htmlspecialchars($operatorName, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </header>

        <?php if ($error !== ''): ?>
            <div class="bo-message is-error">
                <strong>Action not completed.</strong>
                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php elseif ($result !== ''): ?>
            <div class="bo-message is-success">
                <strong>Operation completed.</strong>
                <span>The instruction and wallet ledger states were synchronized.</span>
            </div>
        <?php endif; ?>

        <?php if ($metrics['reconciliation_exceptions'] > 0): ?>
            <div class="bo-message is-warning">
                <strong>Reconciliation required.</strong>
                <span><?= (int) $metrics['reconciliation_exceptions'] ?> historical rejected instruction(s) require reserve release synchronization.</span>
                <a href="?status=exceptions">Open reconciliation queue</a>
            </div>
        <?php endif; ?>

        <section class="bo-summary" aria-label="Operations summary">
            <div class="bo-summary-item">
                <span>Pending review</span>
                <strong><?= (int) $metrics['pending_review'] ?></strong>
            </div>
            <div class="bo-summary-item">
                <span>Settlement ready</span>
                <strong><?= (int) $metrics['ready_settlement'] ?></strong>
            </div>
            <div class="bo-summary-item">
                <span>Processing</span>
                <strong><?= (int) $metrics['processing'] ?></strong>
            </div>
            <div class="bo-summary-item">
                <span>Settled today</span>
                <strong><?= (int) $metrics['settled_today'] ?></strong>
                <small>RM <?= moneyFormatSen($metrics['settled_value_today_sen']) ?></small>
            </div>
            <div class="bo-summary-item">
                <span>Rejected today</span>
                <strong><?= (int) $metrics['rejected_today'] ?></strong>
            </div>
            <div class="bo-summary-item <?= (int) $metrics['sla_exceptions'] > 0 ? 'is-attention' : '' ?>">
                <span>SLA exceptions</span>
                <strong><?= (int) $metrics['sla_exceptions'] ?></strong>
                <small>&gt; 30 min pending</small>
            </div>
        </section>

        <div class="bo-work-area">
            <section class="bo-panel bo-queue-panel">
                <div class="bo-panel-header">
                    <div>
                        <h2><?= htmlspecialchars(ucwords(str_replace('_', ' ', $statusFilter)), ENT_QUOTES, 'UTF-8') ?></h2>
                        <span><?= count($requests) ?> instruction(s)</span>
                    </div>
                    <form method="GET" class="bo-search-form">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
                        <input
                            type="search"
                            name="q"
                            maxlength="100"
                            value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Instruction, beneficiary, reference, last 4"
                            aria-label="Search instructions"
                        >
                        <button type="submit">Search</button>
                        <?php if ($search !== ''): ?>
                            <a href="?status=<?= urlencode($statusFilter) ?>">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if ($requests === []): ?>
                    <div class="bo-empty-state">
                        <strong>No instructions found</strong>
                        <span>There are no records matching this queue and search criteria.</span>
                    </div>
                <?php else: ?>
                    <div class="bo-table-scroll">
                        <table class="bo-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Received</th>
                                    <th>Beneficiary</th>
                                    <th>Account</th>
                                    <th class="is-right">Amount</th>
                                    <th>Status</th>
                                    <th>Age</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($requests as $request): ?>
                                <?php
                                [$stageKey, $stageLabel] = bankGatewayInstructionStage($request);
                                $rowId = (int) $request['wallet_withdrawal_id'];
                                $customerName = trim(
                                    (string) $request['user_first_name'] . ' ' .
                                    (string) $request['user_last_name']
                                );
                                $ageMinutes = (int) ($request['queue_age_minutes'] ?? 0);
                                $isSlaBreach = $stageKey === 'pending' && $ageMinutes > 30;
                                ?>
                                <tr class="<?= $selected !== null && (int) $selected['wallet_withdrawal_id'] === $rowId ? 'is-selected' : '' ?>">
                                    <td class="bo-id-cell">
                                        <strong>#<?= str_pad((string) $rowId, 4, '0', STR_PAD_LEFT) ?></strong>
                                        <span><?= htmlspecialchars(substr((string) $request['wallet_withdrawal_bank_submission_id'], 0, 8), ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <td><?= htmlspecialchars(bankOpsMyt((string) ($request['wallet_withdrawal_bank_submitted_at'] ?? ''), 'd M H:i'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span class="bo-cell-subtext"><?= htmlspecialchars((string) $request['wallet_withdrawal_account_holder'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars((string) $request['wallet_withdrawal_bank_code'], ENT_QUOTES, 'UTF-8') ?> · ••••<?= htmlspecialchars((string) $request['wallet_withdrawal_account_number_last4'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="is-right bo-amount-cell">RM <?= moneyFormatSen(moneyDecimalToSen((string) $request['wallet_withdrawal_amount'])) ?></td>
                                    <td><span class="bo-status bo-status-<?= htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($stageLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td class="<?= $isSlaBreach ? 'bo-sla-breach' : '' ?>">
                                        <?= htmlspecialchars(bankOpsQueueAgeLabel($ageMinutes), ENT_QUOTES, 'UTF-8') ?>
                                        <?= $isSlaBreach ? ' SLA' : '' ?>
                                    </td>
                                    <td class="bo-open-cell">
                                        <a href="?<?= htmlspecialchars(http_build_query([
                                            'status' => $statusFilter,
                                            'q' => $search,
                                            'view' => $rowId,
                                        ]), ENT_QUOTES, 'UTF-8') ?>">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <aside class="bo-panel bo-detail-panel" aria-label="Instruction details">
                <?php if ($selected === null): ?>
                    <div class="bo-empty-state bo-empty-detail">
                        <strong>No instruction selected</strong>
                        <span>Select a record from the queue to review details and available actions.</span>
                    </div>
                <?php else: ?>
                    <?php
                    [$selectedStageKey, $selectedStageLabel, $selectedPhase] =
                        bankGatewayInstructionStage($selected);
                    $selectedWithdrawalId = (int) $selected['wallet_withdrawal_id'];
                    $selectedCustomerName = trim(
                        (string) $selected['user_first_name'] . ' ' .
                        (string) $selected['user_last_name']
                    );
                    $isMerchantAuthorized = in_array(
                        (string) $selected['wallet_withdrawal_status'],
                        ['approved', 'completed', 'failed'],
                        true
                    ) && !empty($selected['wallet_withdrawal_reviewed_at']);
                    $hasSubmission = preg_match(
                        '/\A[a-f0-9]{32}\z/i',
                        (string) ($selected['wallet_withdrawal_bank_submission_id'] ?? '')
                    ) === 1;
                    $encryptedPayloadValid = str_starts_with(
                        (string) ($selected['wallet_withdrawal_account_number_encrypted'] ?? ''),
                        'v1:'
                    );
                    $hasVerificationHash = preg_match(
                        '/\A[a-f0-9]{64}\z/i',
                        (string) ($selected['wallet_withdrawal_bank_verification_hash'] ?? '')
                    ) === 1;
                    $hasSettlementHash = preg_match(
                        '/\A[a-f0-9]{64}\z/i',
                        (string) ($selected['wallet_withdrawal_bank_settlement_hash'] ?? '')
                    ) === 1;
                    $stepReviewDone = $selected['wallet_withdrawal_bank_status'] !== 'pending';
                    $stepSettlementStarted = in_array(
                        (string) ($selected['wallet_withdrawal_bank_settlement_status'] ?? ''),
                        ['processing', 'settled', 'failed'],
                        true
                    );
                    $stepFinalDone = in_array(
                        (string) $selected['wallet_withdrawal_status'],
                        ['completed', 'failed'],
                        true
                    );
                    $validations = [
                        ['Merchant authorization', $isMerchantAuthorized],
                        ['Submission identifier', $hasSubmission],
                        ['Encrypted account payload', $encryptedPayloadValid],
                        ['Institution routing scope', (string) $selected['wallet_withdrawal_bank_code'] === $bankCode],
                        ['Verification integrity hash', $stepReviewDone ? $hasVerificationHash : null],
                        ['Settlement integrity hash', $selectedStageKey === 'settled' ? $hasSettlementHash : null],
                    ];
                    ?>

                    <div class="bo-detail-header">
                        <div>
                            <div class="bo-detail-label">INSTRUCTION</div>
                            <div class="bo-detail-id">#<?= str_pad((string) $selectedWithdrawalId, 4, '0', STR_PAD_LEFT) ?></div>
                            <div class="bo-detail-ref"><?= htmlspecialchars((string) $selected['wallet_withdrawal_bank_submission_id'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="bo-detail-header-right">
                            <span class="bo-status bo-status-<?= htmlspecialchars($selectedStageKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($selectedStageLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            <strong>RM <?= moneyFormatSen(moneyDecimalToSen((string) $selected['wallet_withdrawal_amount'])) ?></strong>
                        </div>
                    </div>

                    <div class="bo-detail-scroll">
                        <section class="bo-detail-section">
                            <h3>Processing status</h3>
                            <div class="bo-lifecycle">
                                <div class="is-complete"><span>1</span><strong>Merchant approval</strong><small>Authorized</small></div>
                                <div class="<?= $stepReviewDone ? 'is-complete' : 'is-current' ?>"><span>2</span><strong>Bank review</strong><small><?= $stepReviewDone ? 'Decision posted' : 'In review' ?></small></div>
                                <div class="<?= $stepSettlementStarted ? ($stepFinalDone ? 'is-complete' : 'is-current') : ($selectedStageKey === 'accepted' ? 'is-current' : '') ?>"><span>3</span><strong>Settlement</strong><small><?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($selected['wallet_withdrawal_bank_settlement_status'] ?? 'not_required'))), ENT_QUOTES, 'UTF-8') ?></small></div>
                                <div class="<?= $stepFinalDone ? 'is-complete' : '' ?>"><span>4</span><strong>Wallet ledger</strong><small><?= $stepFinalDone ? ucfirst((string) $selected['wallet_withdrawal_status']) : 'Reserved' ?></small></div>
                            </div>
                        </section>

                        <section class="bo-detail-section">
                            <h3>Instruction details</h3>
                            <dl class="bo-detail-grid">
                                <div><dt>Customer</dt><dd><?= htmlspecialchars($selectedCustomerName, ENT_QUOTES, 'UTF-8') ?></dd><small><?= htmlspecialchars((string) $selected['user_gmail'], ENT_QUOTES, 'UTF-8') ?></small></div>
                                <div><dt>Account holder</dt><dd><?= htmlspecialchars((string) $selected['wallet_withdrawal_account_holder'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                                <div><dt>Destination bank</dt><dd><?= htmlspecialchars((string) $selected['wallet_withdrawal_bank_name'], ENT_QUOTES, 'UTF-8') ?></dd><small><?= htmlspecialchars((string) $selected['wallet_withdrawal_bank_code'], ENT_QUOTES, 'UTF-8') ?></small></div>
                                <div><dt>Destination account</dt><dd>•••• <?= htmlspecialchars((string) $selected['wallet_withdrawal_account_number_last4'], ENT_QUOTES, 'UTF-8') ?></dd><button type="button" class="bo-link-button" onclick="openAccountModal(<?= $selectedWithdrawalId ?>, '<?= htmlspecialchars((string) $selected['wallet_withdrawal_account_holder'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars((string) $selected['wallet_withdrawal_bank_name'], ENT_QUOTES, 'UTF-8') ?>')">View protected data</button></div>
                                <div><dt>Submitted</dt><dd><?= htmlspecialchars(bankOpsMyt((string) ($selected['wallet_withdrawal_bank_submitted_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?> MYT</dd></div>
                                <div><dt>Current phase</dt><dd><?= htmlspecialchars($selectedPhase, ENT_QUOTES, 'UTF-8') ?></dd></div>
                            </dl>
                        </section>

                        <section class="bo-detail-section">
                            <h3>Control checks</h3>
                            <table class="bo-control-table">
                                <tbody>
                                <?php foreach ($validations as [$label, $ok]): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="is-right">
                                            <?php if ($ok === null): ?>
                                                <span class="bo-control-state is-pending">Pending</span>
                                            <?php elseif ($ok): ?>
                                                <span class="bo-control-state is-pass">Pass</span>
                                            <?php else: ?>
                                                <span class="bo-control-state is-review">Review</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </section>

                        <?php if (!empty($selected['wallet_withdrawal_bank_decision_reference'])): ?>
                            <section class="bo-detail-section">
                                <h3>Bank decision</h3>
                                <dl class="bo-detail-grid">
                                    <div><dt>Authorization reference</dt><dd class="bo-mono"><?= htmlspecialchars((string) $selected['wallet_withdrawal_bank_decision_reference'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                                    <div><dt>Decision time</dt><dd><?= htmlspecialchars(bankOpsMyt((string) $selected['wallet_withdrawal_bank_decided_at']), ENT_QUOTES, 'UTF-8') ?> MYT</dd></div>
                                </dl>
                                <?php if (!empty($selected['wallet_withdrawal_bank_decision_note'])): ?>
                                    <div class="bo-note"><strong>Decision note</strong><p><?= nl2br(htmlspecialchars((string) $selected['wallet_withdrawal_bank_decision_note'], ENT_QUOTES, 'UTF-8')) ?></p></div>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>

                        <?php if (!empty($selected['wallet_withdrawal_transfer_reference'])): ?>
                            <section class="bo-detail-section">
                                <h3>Settlement result</h3>
                                <dl class="bo-detail-grid">
                                    <div><dt>Settlement reference</dt><dd class="bo-mono"><?= htmlspecialchars((string) $selected['wallet_withdrawal_transfer_reference'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                                    <div><dt>Settled at</dt><dd><?= htmlspecialchars(bankOpsMyt((string) ($selected['wallet_withdrawal_bank_settled_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?> MYT</dd></div>
                                </dl>
                            </section>
                        <?php endif; ?>

                        <section class="bo-detail-section bo-action-section">
                            <h3>Available action</h3>
                            <?php if ($selectedStageKey === 'pending'): ?>
                                <p>Complete the independent beneficiary review before accepting or rejecting this instruction.</p>
                                <div class="bo-action-row">
                                    <button class="ops-btn ops-btn-success" type="button" onclick="openActionModal('approve', <?= $selectedWithdrawalId ?>)">Accept for settlement</button>
                                    <button class="ops-btn ops-btn-danger" type="button" onclick="openActionModal('reject', <?= $selectedWithdrawalId ?>)">Reject instruction</button>
                                </div>
                            <?php elseif ($selectedStageKey === 'accepted'): ?>
                                <p>Verification is complete. Move the instruction to settlement processing when ready.</p>
                                <div class="bo-action-row"><button class="ops-btn ops-btn-primary" type="button" onclick="openActionModal('start_settlement', <?= $selectedWithdrawalId ?>)">Start settlement</button></div>
                            <?php elseif ($selectedStageKey === 'processing'): ?>
                                <p>Post the final result only after the simulated settlement rail has completed.</p>
                                <div class="bo-action-row">
                                    <button class="ops-btn ops-btn-success" type="button" onclick="openActionModal('settle', <?= $selectedWithdrawalId ?>)">Confirm settled</button>
                                    <button class="ops-btn ops-btn-warning" type="button" onclick="openActionModal('fail_settlement', <?= $selectedWithdrawalId ?>)">Mark failed</button>
                                </div>
                            <?php elseif ($selectedStageKey === 'exception'): ?>
                                <p>This historical bank rejection has not yet released the merchant-side reserve.</p>
                                <div class="bo-action-row"><button class="ops-btn ops-btn-dark" type="button" onclick="openActionModal('reconcile_rejection', <?= $selectedWithdrawalId ?>)">Reconcile and release</button></div>
                            <?php elseif ($selectedStageKey === 'settled'): ?>
                                <p class="bo-final-state">Final. Settlement and wallet debit are complete.</p>
                            <?php elseif ($selectedStageKey === 'rejected'): ?>
                                <p class="bo-final-state">Final. Bank review rejected the instruction and reserved funds were released.</p>
                            <?php elseif ($selectedStageKey === 'failed'): ?>
                                <p class="bo-final-state">Final. Settlement failed and reserved funds were released.</p>
                            <?php endif; ?>
                        </section>

                        <section class="bo-detail-section">
                            <h3>Operator audit</h3>
                            <?php if ($auditTrail === []): ?>
                                <div class="bo-muted">No operator events recorded.</div>
                            <?php else: ?>
                                <table class="bo-audit-table">
                                    <thead><tr><th>Time</th><th>Operator</th><th>Event</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($auditTrail as $audit): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(bankOpsMyt((string) $audit['bank_gateway_log_created_at'], 'd M H:i:s'), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) $audit['bank_gateway_operator_display_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $audit['bank_gateway_log_action'])), ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars((string) $audit['bank_gateway_log_details'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </section>
                    </div>
                <?php endif; ?>
            </aside>
        </div>
    </main>
</div>


<div id="actionModal" class="ops-modal" role="dialog" aria-modal="true" aria-labelledby="actionModalTitle">
    <div class="ops-modal-card">
        <div class="ops-modal-head">
            <div class="ops-modal-kicker">Controlled Institution Action</div>
            <h2 id="actionModalTitle" class="ops-modal-title">Confirm Operation</h2>
        </div>
        <form method="POST" class="ops-modal-body" id="actionForm">
            <?php csrf_field(); ?>
            <input type="hidden" name="withdrawal_id" id="actionWithdrawalId" value="">
            <input type="hidden" name="action" id="actionName" value="">

            <p id="actionModalCopy" class="ops-modal-copy"></p>

            <div id="decisionNoteGroup" class="ops-form-group" style="display:none">
                <label class="ops-form-label" for="decisionNote">Decision Note</label>
                <textarea class="ops-textarea" id="decisionNote" name="decision_note" maxlength="1000" placeholder="Record the verification rationale or rejection reason..."></textarea>
            </div>

            <div id="settlementNoteGroup" class="ops-form-group" style="display:none">
                <label class="ops-form-label" for="settlementNote">Settlement Note</label>
                <textarea class="ops-textarea" id="settlementNote" name="settlement_note" maxlength="1000" placeholder="Optional settlement operations note..."></textarea>
            </div>

            <div id="failureReasonGroup" class="ops-form-group" style="display:none">
                <label class="ops-form-label" for="failureReason">Failure Reason</label>
                <textarea class="ops-textarea" id="failureReason" name="failure_reason" maxlength="1000" placeholder="Describe why settlement could not complete..."></textarea>
            </div>

            <div class="ops-form-group">
                <label class="ops-form-label" for="actionPassword">Current Operator Password</label>
                <input
                    class="ops-input"
                    type="password"
                    id="actionPassword"
                    name="current_password"
                    maxlength="72"
                    autocomplete="current-password"
                    required
                    placeholder="Re-authenticate this operation"
                >
            </div>

            <label id="verificationAckWrap" class="ops-check" style="display:none">
                <input type="checkbox" name="verification_ack" value="1" id="verificationAck">
                <span>I confirm that the destination institution, beneficiary details and merchant-authorized instruction were independently reviewed before acceptance.</span>
            </label>

            <label id="settlementAckWrap" class="ops-check" style="display:none">
                <input type="checkbox" name="settlement_ack" value="1" id="settlementAck">
                <span>I confirm that the simulated settlement rail completed successfully and the final wallet debit may now be posted.</span>
            </label>

            <div class="ops-modal-actions">
                <button class="ops-btn ops-btn-ghost" type="button" onclick="closeActionModal()">Cancel</button>
                <button class="ops-btn ops-btn-dark" type="submit" id="actionSubmitButton">Confirm</button>
            </div>
        </form>
    </div>
</div>

<div id="accountModal" class="ops-modal" role="dialog" aria-modal="true" aria-labelledby="accountModalTitle">
    <div class="ops-modal-card">
        <div class="ops-modal-head">
            <div class="ops-modal-kicker">Protected Data Access</div>
            <h2 id="accountModalTitle" class="ops-modal-title">Secure Account Reveal</h2>
        </div>
        <div class="ops-modal-body">
            <p class="ops-modal-copy">
                Protected destination account data is encrypted at rest. Re-authentication is required and every reveal is written to the bank audit trail.
            </p>
            <div class="ops-info-grid">
                <div class="ops-info">
                    <div class="ops-info-label">Account Holder</div>
                    <div class="ops-info-value" id="accountHolderLabel">—</div>
                </div>
                <div class="ops-info">
                    <div class="ops-info-label">Institution</div>
                    <div class="ops-info-value" id="accountBankLabel">—</div>
                </div>
            </div>
            <div class="ops-form-group">
                <label class="ops-form-label" for="accountPassword">Current Operator Password</label>
                <input class="ops-input" type="password" id="accountPassword" maxlength="72" autocomplete="current-password" placeholder="Re-authenticate protected data access">
            </div>
            <div id="accountResult" style="display:none">
                <div class="ops-account-number" id="accountNumberValue"></div>
                <div class="ops-info-note">Do not copy protected account data outside the authorized workflow.</div>
            </div>
            <div id="accountError" class="ops-alert is-error" style="display:none;margin:12px 0 0"></div>
            <div class="ops-modal-actions">
                <button class="ops-btn ops-btn-ghost" type="button" onclick="closeAccountModal()">Close</button>
                <button class="ops-btn ops-btn-primary" type="button" id="accountRevealButton" onclick="revealAccount()">Reveal Account</button>
            </div>
        </div>
    </div>
</div>

<script>
    const accountDetailsUrl = <?= json_encode(app_path('bank/account_details.php'), JSON_UNESCAPED_SLASHES) ?>;
    const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
    let accountWithdrawalId = null;

    const actionConfig = {
        approve: {
            title: 'Accept for Settlement',
            copy: 'Accept this independently verified instruction and release it to the settlement queue. This does not yet debit the wallet.',
            button: 'Accept Instruction',
            buttonClass: 'ops-btn ops-btn-success',
            decisionNote: true,
            decisionRequired: false,
            verificationAck: true,
        },
        reject: {
            title: 'Reject & Auto-Release Funds',
            copy: 'Reject this instruction. The merchant withdrawal will become Failed and reserved wallet funds will be released automatically in the same database transaction.',
            button: 'Reject & Release',
            buttonClass: 'ops-btn ops-btn-danger',
            decisionNote: true,
            decisionRequired: true,
        },
        start_settlement: {
            title: 'Start Settlement Processing',
            copy: 'Move this accepted instruction into settlement processing. Funds stay reserved until a final settlement result is posted.',
            button: 'Start Settlement',
            buttonClass: 'ops-btn ops-btn-primary',
            settlementNote: true,
        },
        settle: {
            title: 'Confirm Final Settlement',
            copy: 'Post the final settlement. A settlement reference will be generated automatically and the reserved wallet amount will be permanently debited atomically.',
            button: 'Confirm Settled',
            buttonClass: 'ops-btn ops-btn-success',
            settlementNote: true,
            settlementAck: true,
        },
        fail_settlement: {
            title: 'Mark Settlement Failed',
            copy: 'Close this settlement as failed. The wallet reserve will be released automatically and the merchant withdrawal will become Failed.',
            button: 'Fail & Release Funds',
            buttonClass: 'ops-btn ops-btn-warning',
            failureReason: true,
        },
        reconcile_rejection: {
            title: 'Reconcile Historical Rejection',
            copy: 'Resolve this historical mismatch once. The rejected bank instruction will synchronize to merchant Failed status and release its reserved wallet amount.',
            button: 'Reconcile & Release',
            buttonClass: 'ops-btn ops-btn-dark',
        },
    };

    function setMalaysiaClock() {
        const formatter = new Intl.DateTimeFormat('en-GB', {
            timeZone: 'Asia/Kuala_Lumpur',
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: true,
        });
        document.getElementById('malaysiaClock').textContent =
            formatter.format(new Date()) + ' MYT';
    }

    setMalaysiaClock();
    window.setInterval(setMalaysiaClock, 1000);

    function openActionModal(action, withdrawalId) {
        const config = actionConfig[action];
        if (!config) return;

        document.getElementById('actionName').value = action;
        document.getElementById('actionWithdrawalId').value = withdrawalId;
        document.getElementById('actionModalTitle').textContent = config.title;
        document.getElementById('actionModalCopy').textContent = config.copy;

        const submit = document.getElementById('actionSubmitButton');
        submit.textContent = config.button;
        submit.className = config.buttonClass;
        submit.disabled = false;

        const decisionGroup = document.getElementById('decisionNoteGroup');
        const decision = document.getElementById('decisionNote');
        decisionGroup.style.display = config.decisionNote ? 'block' : 'none';
        decision.required = Boolean(config.decisionRequired);
        decision.value = '';

        const settlementGroup = document.getElementById('settlementNoteGroup');
        const settlement = document.getElementById('settlementNote');
        settlementGroup.style.display = config.settlementNote ? 'block' : 'none';
        settlement.required = false;
        settlement.value = '';

        const failureGroup = document.getElementById('failureReasonGroup');
        const failure = document.getElementById('failureReason');
        failureGroup.style.display = config.failureReason ? 'block' : 'none';
        failure.required = Boolean(config.failureReason);
        failure.value = '';

        const verificationWrap = document.getElementById('verificationAckWrap');
        const verificationAck = document.getElementById('verificationAck');
        verificationWrap.style.display = config.verificationAck ? 'flex' : 'none';
        verificationAck.required = Boolean(config.verificationAck);
        verificationAck.checked = false;

        const settlementWrap = document.getElementById('settlementAckWrap');
        const settlementAck = document.getElementById('settlementAck');
        settlementWrap.style.display = config.settlementAck ? 'flex' : 'none';
        settlementAck.required = Boolean(config.settlementAck);
        settlementAck.checked = false;

        document.getElementById('actionPassword').value = '';
        document.getElementById('actionModal').classList.add('is-open');
        document.body.style.overflow = 'hidden';
        window.setTimeout(() => document.getElementById('actionPassword').focus(), 50);
    }

    function closeActionModal() {
        document.getElementById('actionModal').classList.remove('is-open');
        document.body.style.overflow = '';
    }

    document.getElementById('actionForm').addEventListener('submit', function () {
        const submit = document.getElementById('actionSubmitButton');
        submit.disabled = true;
        submit.textContent = 'Processing...';
    });

    function openAccountModal(withdrawalId, holder, bank) {
        accountWithdrawalId = withdrawalId;
        document.getElementById('accountHolderLabel').textContent = holder;
        document.getElementById('accountBankLabel').textContent = bank;
        document.getElementById('accountPassword').value = '';
        document.getElementById('accountNumberValue').textContent = '';
        document.getElementById('accountResult').style.display = 'none';
        document.getElementById('accountError').style.display = 'none';
        document.getElementById('accountRevealButton').disabled = false;
        document.getElementById('accountRevealButton').textContent = 'Reveal Account';
        document.getElementById('accountModal').classList.add('is-open');
        document.body.style.overflow = 'hidden';
        window.setTimeout(() => document.getElementById('accountPassword').focus(), 50);
    }

    function closeAccountModal() {
        accountWithdrawalId = null;
        document.getElementById('accountModal').classList.remove('is-open');
        document.body.style.overflow = '';
    }

    async function revealAccount() {
        if (!accountWithdrawalId) return;

        const password = document.getElementById('accountPassword').value;
        const button = document.getElementById('accountRevealButton');
        const error = document.getElementById('accountError');
        const result = document.getElementById('accountResult');

        error.style.display = 'none';
        result.style.display = 'none';
        button.disabled = true;
        button.textContent = 'Authorizing...';

        const body = new URLSearchParams({
            csrf_token: csrfToken,
            withdrawal_id: String(accountWithdrawalId),
            current_password: password,
        });

        try {
            const response = await fetch(accountDetailsUrl, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'Accept': 'application/json',
                },
                body: body.toString(),
            });
            const payload = await response.json();

            if (!response.ok || !payload.ok) {
                throw new Error(payload.message || 'Protected account access failed.');
            }

            document.getElementById('accountNumberValue').textContent = payload.account_number;
            result.style.display = 'block';
            button.textContent = 'Access Logged';
        } catch (e) {
            error.textContent = e.message || 'Protected account access failed.';
            error.style.display = 'flex';
            button.disabled = false;
            button.textContent = 'Reveal Account';
        }
    }

    document.querySelectorAll('.ops-modal').forEach((modal) => {
        modal.addEventListener('click', (event) => {
            if (event.target !== modal) return;
            if (modal.id === 'actionModal') closeActionModal();
            if (modal.id === 'accountModal') closeAccountModal();
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeActionModal();
            closeAccountModal();
        }
    });
</script>

</body>
</html>