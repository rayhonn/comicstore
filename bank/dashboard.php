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

<div class="ops-app">
    <aside class="ops-sidebar" aria-label="Institution operations navigation">
        <p class="ops-sidebar-kicker">Settlement Operations</p>
        <nav class="ops-nav">
            <?php
            $navItems = [
                'pending' => ['RV', 'Review Queue', $counts['pending']],
                'accepted' => ['AC', 'Accepted', $counts['accepted']],
                'processing' => ['ST', 'Settlement Queue', $counts['processing']],
                'settled' => ['OK', 'Settled Records', $counts['settled']],
                'rejected' => ['RJ', 'Rejected', $counts['rejected']],
                'failed' => ['FL', 'Settlement Failed', $counts['failed']],
                'exceptions' => ['EX', 'Reconciliation', $counts['exceptions']],
                'all' => ['AR', 'All Instructions', array_sum($counts)],
            ];
            ?>
            <?php foreach ($navItems as $key => $item): ?>
                <a
                    class="ops-nav-link <?= $statusFilter === $key ? 'is-active' : '' ?>"
                    href="?<?= htmlspecialchars(http_build_query(['status' => $key]), ENT_QUOTES, 'UTF-8') ?>"
                >
                    <span class="ops-nav-icon"><?= htmlspecialchars($item[0], ENT_QUOTES, 'UTF-8') ?></span>
                    <span><?= htmlspecialchars($item[1], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="ops-nav-count"><?= (int) $item[2] ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="ops-sidebar-card">
            <div class="ops-sidebar-card-label">Institution Scope</div>
            <div class="ops-sidebar-card-value">
                <?= htmlspecialchars($bankName, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($bankCode, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="ops-sidebar-card-copy">
                This operator can only access instructions routed to this destination institution.
            </div>
        </div>

        <div class="ops-sidebar-card">
            <div class="ops-sidebar-card-label">Controls</div>
            <div class="ops-sidebar-card-value">Re-authentication enforced</div>
            <div class="ops-sidebar-card-copy">
                Protected account access and material settlement actions require the current operator password and are audit logged.
            </div>
        </div>
    </aside>

    <main class="ops-main">
        <header class="ops-workspace-header">
            <div>
                <div class="ops-eyebrow">BankLink · Institution Settlement Console</div>
                <h1 class="ops-title">Transfer Operations Workspace</h1>
                <p class="ops-subtitle">
                    Independent beneficiary verification, settlement processing, wallet-ledger synchronization and exception reconciliation for merchant-authorized refund instructions. Merchant approval does not complete a transfer; final wallet debit occurs only after institution settlement is confirmed.
                </p>
            </div>
            <div class="ops-clock">
                <div class="ops-clock-label">Malaysia Time · MYT</div>
                <div id="malaysiaClock" class="ops-clock-value">Loading...</div>
            </div>
        </header>

        <?php if ($error !== ''): ?>
            <div class="ops-alert is-error">
                <strong>Action blocked.</strong>
                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php elseif ($result !== ''): ?>
            <div class="ops-alert">
                <strong>Operation recorded.</strong>
                <span>The institution lifecycle and MangaVault wallet state were synchronized successfully.</span>
            </div>
        <?php endif; ?>

        <?php if ($metrics['reconciliation_exceptions'] > 0): ?>
            <div class="ops-alert is-warning">
                <strong>Reconciliation attention required.</strong>
                <span>
                    <?= (int) $metrics['reconciliation_exceptions'] ?> historical rejected instruction(s) still have merchant status Approved. Open Reconciliation and resolve them so reserved funds are released and both systems match.
                </span>
            </div>
        <?php endif; ?>

        <section class="ops-metrics" aria-label="Institution operations metrics">
            <div class="ops-metric is-amber">
                <div class="ops-metric-label">Pending Review</div>
                <div class="ops-metric-value"><?= (int) $metrics['pending_review'] ?></div>
                <div class="ops-metric-foot">Awaiting institution verification</div>
            </div>
            <div class="ops-metric is-blue">
                <div class="ops-metric-label">Settlement Ready</div>
                <div class="ops-metric-value"><?= (int) $metrics['ready_settlement'] ?></div>
                <div class="ops-metric-foot">Accepted, not yet processing</div>
            </div>
            <div class="ops-metric is-cyan">
                <div class="ops-metric-label">Processing</div>
                <div class="ops-metric-value"><?= (int) $metrics['processing'] ?></div>
                <div class="ops-metric-foot">Settlement in progress</div>
            </div>
            <div class="ops-metric is-green">
                <div class="ops-metric-label">Settled Today</div>
                <div class="ops-metric-value"><?= (int) $metrics['settled_today'] ?></div>
                <div class="ops-metric-foot">RM <?= moneyFormatSen($metrics['settled_value_today_sen']) ?> posted today</div>
            </div>
            <div class="ops-metric is-red">
                <div class="ops-metric-label">Rejected Today</div>
                <div class="ops-metric-value"><?= (int) $metrics['rejected_today'] ?></div>
                <div class="ops-metric-foot">Funds auto-released</div>
            </div>
            <div class="ops-metric is-violet">
                <div class="ops-metric-label">SLA Exceptions</div>
                <div class="ops-metric-value"><?= (int) $metrics['sla_exceptions'] ?></div>
                <div class="ops-metric-foot">Pending longer than 30 minutes</div>
            </div>
        </section>

        <div class="ops-grid">
            <section class="ops-card">
                <div class="ops-card-header">
                    <div>
                        <h2 class="ops-card-title">Institution Work Queue</h2>
                        <div class="ops-card-caption">
                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $statusFilter)), ENT_QUOTES, 'UTF-8') ?> · <?= count($requests) ?> record(s)
                        </div>
                    </div>
                    <form method="GET" class="ops-toolbar">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
                        <input
                            class="ops-search"
                            type="search"
                            name="q"
                            maxlength="100"
                            value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Search instruction, customer, ref, last 4..."
                            aria-label="Search institution work queue"
                        >
                        <button class="ops-btn ops-btn-dark" type="submit">Search</button>
                        <?php if ($search !== ''): ?>
                            <a class="ops-btn ops-btn-ghost" href="?status=<?= urlencode($statusFilter) ?>">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if ($requests === []): ?>
                    <div class="ops-empty">
                        <div class="ops-empty-mark">BL</div>
                        <div class="ops-empty-title">No instructions in this queue</div>
                        <div class="ops-empty-copy">New merchant-authorized instructions will appear here automatically.</div>
                    </div>
                <?php else: ?>
                    <div class="ops-table-wrap">
                        <table class="ops-table">
                            <thead>
                                <tr>
                                    <th>Instruction</th>
                                    <th>Beneficiary</th>
                                    <th>Destination</th>
                                    <th>Amount</th>
                                    <th>Queue Age</th>
                                    <th>Stage</th>
                                    <th>Action</th>
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
                                $isSlaBreach =
                                    $stageKey === 'pending' && $ageMinutes > 30;
                                ?>
                                <tr class="<?= $selected !== null && (int) $selected['wallet_withdrawal_id'] === $rowId ? 'is-selected' : '' ?>">
                                    <td>
                                        <span class="ops-instruction">#<?= str_pad((string) $rowId, 4, '0', STR_PAD_LEFT) ?></span>
                                        <span class="ops-subtext">
                                            <?= htmlspecialchars(substr((string) $request['wallet_withdrawal_bank_submission_id'], 0, 12), ENT_QUOTES, 'UTF-8') ?>…
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span class="ops-subtext"><?= htmlspecialchars((string) $request['wallet_withdrawal_account_holder'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars((string) $request['wallet_withdrawal_bank_name'], ENT_QUOTES, 'UTF-8') ?>
                                        <span class="ops-subtext">••••<?= htmlspecialchars((string) $request['wallet_withdrawal_account_number_last4'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <td class="ops-money">
                                        RM <?= moneyFormatSen(moneyDecimalToSen((string) $request['wallet_withdrawal_amount'])) ?>
                                    </td>
                                    <td>
                                        <span class="ops-sla <?= $isSlaBreach ? 'is-breach' : '' ?>">
                                            <?= htmlspecialchars(bankOpsQueueAgeLabel($ageMinutes), ENT_QUOTES, 'UTF-8') ?>
                                            <?= $isSlaBreach ? ' · SLA' : '' ?>
                                        </span>
                                    </td>
                                    <td><span class="ops-badge <?= htmlspecialchars($stageKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($stageLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td>
                                        <a
                                            class="ops-btn ops-btn-ghost"
                                            href="?<?= htmlspecialchars(http_build_query([
                                                'status' => $statusFilter,
                                                'q' => $search,
                                                'view' => $rowId,
                                            ]), ENT_QUOTES, 'UTF-8') ?>"
                                        >Open</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <aside class="ops-card ops-detail" aria-label="Selected instruction detail">
                <?php if ($selected === null): ?>
                    <div class="ops-empty">
                        <div class="ops-empty-mark">ID</div>
                        <div class="ops-empty-title">Select an instruction</div>
                        <div class="ops-empty-copy">Open a queue record to view verification, settlement and audit details.</div>
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
                    ?>
                    <div class="ops-detail-head">
                        <div class="ops-detail-head-top">
                            <div>
                                <div class="ops-detail-kicker">Instruction Detail · <?= htmlspecialchars($selectedPhase, ENT_QUOTES, 'UTF-8') ?></div>
                                <h2 class="ops-detail-title">#<?= str_pad((string) $selectedWithdrawalId, 4, '0', STR_PAD_LEFT) ?></h2>
                            </div>
                            <div class="ops-detail-amount">
                                RM <?= moneyFormatSen(moneyDecimalToSen((string) $selected['wallet_withdrawal_amount'])) ?>
                            </div>
                        </div>
                        <div class="ops-detail-reference">
                            Submission <?= htmlspecialchars((string) $selected['wallet_withdrawal_bank_submission_id'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>

                    <div class="ops-detail-body">
                        <div class="ops-section">
                            <div class="ops-section-title">
                                <span>Lifecycle</span>
                                <span class="ops-badge <?= htmlspecialchars($selectedStageKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($selectedStageLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="ops-stepper">
                                <div class="ops-step is-done">
                                    <div class="ops-step-label">1 · Merchant</div>
                                    <div class="ops-step-value">Authorized</div>
                                </div>
                                <div class="ops-step <?= $stepReviewDone ? 'is-done' : 'is-current' ?>">
                                    <div class="ops-step-label">2 · Bank</div>
                                    <div class="ops-step-value"><?= $stepReviewDone ? 'Decision Posted' : 'Verification' ?></div>
                                </div>
                                <div class="ops-step <?= $stepSettlementStarted ? ($stepFinalDone ? 'is-done' : 'is-current') : ($selectedStageKey === 'accepted' ? 'is-current' : '') ?>">
                                    <div class="ops-step-label">3 · Settlement</div>
                                    <div class="ops-step-value"><?= htmlspecialchars(ucfirst((string) ($selected['wallet_withdrawal_bank_settlement_status'] ?? 'not_required')), ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <div class="ops-step <?= $stepFinalDone ? 'is-done' : '' ?>">
                                    <div class="ops-step-label">4 · Ledger</div>
                                    <div class="ops-step-value"><?= $stepFinalDone ? ucfirst((string) $selected['wallet_withdrawal_status']) : 'Reserved' ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="ops-section">
                            <div class="ops-section-title">Beneficiary & Instruction</div>
                            <div class="ops-info-grid">
                                <div class="ops-info">
                                    <div class="ops-info-label">Customer</div>
                                    <div class="ops-info-value"><?= htmlspecialchars($selectedCustomerName, ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="ops-info-note"><?= htmlspecialchars((string) $selected['user_gmail'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <div class="ops-info">
                                    <div class="ops-info-label">Account Holder</div>
                                    <div class="ops-info-value"><?= htmlspecialchars((string) $selected['wallet_withdrawal_account_holder'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="ops-info-note">Captured by merchant identity control</div>
                                </div>
                                <div class="ops-info">
                                    <div class="ops-info-label">Destination Institution</div>
                                    <div class="ops-info-value"><?= htmlspecialchars((string) $selected['wallet_withdrawal_bank_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="ops-info-note"><?= htmlspecialchars((string) $selected['wallet_withdrawal_bank_code'], ENT_QUOTES, 'UTF-8') ?> routing scope</div>
                                </div>
                                <div class="ops-info">
                                    <div class="ops-info-label">Protected Account</div>
                                    <div class="ops-info-value">•••• <?= htmlspecialchars((string) $selected['wallet_withdrawal_account_number_last4'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <button
                                        type="button"
                                        class="ops-btn ops-btn-ghost"
                                        style="margin-top:7px"
                                        onclick="openAccountModal(<?= $selectedWithdrawalId ?>, '<?= htmlspecialchars((string) $selected['wallet_withdrawal_account_holder'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars((string) $selected['wallet_withdrawal_bank_name'], ENT_QUOTES, 'UTF-8') ?>')"
                                    >Securely View</button>
                                </div>
                            </div>
                        </div>

                        <div class="ops-section">
                            <div class="ops-section-title">Control Validation Matrix</div>
                            <div class="ops-validation">
                                <?php
                                $validations = [
                                    ['Merchant authorization recorded', $isMerchantAuthorized],
                                    ['Unique bank submission identifier present', $hasSubmission],
                                    ['Account data stored as encrypted payload', $encryptedPayloadValid],
                                    ['Institution routing matches operator scope', (string) $selected['wallet_withdrawal_bank_code'] === $bankCode],
                                    ['Bank verification integrity hash', $stepReviewDone ? $hasVerificationHash : null],
                                    ['Settlement integrity hash', $selectedStageKey === 'settled' ? $hasSettlementHash : null],
                                ];
                                ?>
                                <?php foreach ($validations as [$label, $ok]): ?>
                                    <div class="ops-validation-row">
                                        <div class="ops-validation-icon <?= $ok === false ? 'is-warn' : '' ?>"><?= $ok === false ? '!' : '✓' ?></div>
                                        <div class="ops-validation-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="ops-validation-status <?= $ok === false ? 'is-warn' : '' ?>">
                                            <?= $ok === null ? 'Pending' : ($ok ? 'Pass' : 'Review') ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php if (!empty($selected['wallet_withdrawal_bank_decision_reference'])): ?>
                            <div class="ops-section">
                                <div class="ops-section-title">Bank Decision</div>
                                <div class="ops-info-grid">
                                    <div class="ops-info">
                                        <div class="ops-info-label">Authorization Reference</div>
                                        <div class="ops-info-value"><?= htmlspecialchars((string) $selected['wallet_withdrawal_bank_decision_reference'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="ops-info">
                                        <div class="ops-info-label">Decision Time</div>
                                        <div class="ops-info-value"><?= htmlspecialchars(bankOpsMyt((string) $selected['wallet_withdrawal_bank_decided_at']), ENT_QUOTES, 'UTF-8') ?> MYT</div>
                                    </div>
                                </div>
                                <?php if (!empty($selected['wallet_withdrawal_bank_decision_note'])): ?>
                                    <div class="ops-info" style="margin-top:8px">
                                        <div class="ops-info-label">Decision Note</div>
                                        <div class="ops-info-value"><?= nl2br(htmlspecialchars((string) $selected['wallet_withdrawal_bank_decision_note'], ENT_QUOTES, 'UTF-8')) ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($selected['wallet_withdrawal_transfer_reference'])): ?>
                            <div class="ops-section">
                                <div class="ops-section-title">Final Settlement</div>
                                <div class="ops-info-grid">
                                    <div class="ops-info">
                                        <div class="ops-info-label">Settlement Reference</div>
                                        <div class="ops-info-value"><?= htmlspecialchars((string) $selected['wallet_withdrawal_transfer_reference'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="ops-info">
                                        <div class="ops-info-label">Settled At</div>
                                        <div class="ops-info-value"><?= htmlspecialchars(bankOpsMyt((string) ($selected['wallet_withdrawal_bank_settled_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?> MYT</div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="ops-section">
                            <div class="ops-section-title">Operational Action</div>

                            <?php if ($selectedStageKey === 'pending'): ?>
                                <div class="ops-action-panel is-blue">
                                    <div class="ops-action-title">Independent Verification Decision</div>
                                    <p class="ops-action-copy">
                                        Accepting releases this instruction to the bank settlement queue. Rejecting immediately synchronizes the merchant withdrawal to Failed and releases the reserved wallet amount in the same transaction.
                                    </p>
                                    <div class="ops-action-buttons">
                                        <button
                                            class="ops-btn ops-btn-success"
                                            type="button"
                                            onclick="openActionModal('approve', <?= $selectedWithdrawalId ?>)"
                                        >Accept for Settlement</button>
                                        <button
                                            class="ops-btn ops-btn-danger"
                                            type="button"
                                            onclick="openActionModal('reject', <?= $selectedWithdrawalId ?>)"
                                        >Reject Instruction</button>
                                    </div>
                                </div>
                            <?php elseif ($selectedStageKey === 'accepted'): ?>
                                <div class="ops-action-panel is-blue">
                                    <div class="ops-action-title">Settlement Ready</div>
                                    <p class="ops-action-copy">
                                        Verification has passed. Starting settlement moves the instruction into processing while the MangaVault amount remains reserved.
                                    </p>
                                    <div class="ops-action-buttons">
                                        <button
                                            class="ops-btn ops-btn-primary"
                                            type="button"
                                            onclick="openActionModal('start_settlement', <?= $selectedWithdrawalId ?>)"
                                        >Start Settlement</button>
                                    </div>
                                </div>
                            <?php elseif ($selectedStageKey === 'processing'): ?>
                                <div class="ops-action-panel is-green">
                                    <div class="ops-action-title">Settlement Processing</div>
                                    <p class="ops-action-copy">
                                        Confirm settlement only after the simulated payment rail has completed. The system will generate the final settlement reference and atomically post the wallet debit. If settlement fails, reserved funds are automatically released.
                                    </p>
                                    <div class="ops-action-buttons">
                                        <button
                                            class="ops-btn ops-btn-success"
                                            type="button"
                                            onclick="openActionModal('settle', <?= $selectedWithdrawalId ?>)"
                                        >Confirm Settled</button>
                                        <button
                                            class="ops-btn ops-btn-warning"
                                            type="button"
                                            onclick="openActionModal('fail_settlement', <?= $selectedWithdrawalId ?>)"
                                        >Settlement Failed</button>
                                    </div>
                                </div>
                            <?php elseif ($selectedStageKey === 'exception'): ?>
                                <div class="ops-action-panel is-violet">
                                    <div class="ops-action-title">Historical Reconciliation Exception</div>
                                    <p class="ops-action-copy">
                                        This rejected instruction was created before automatic bank-to-wallet synchronization. Reconcile it once to release the reserved amount and change merchant withdrawal status from Approved to Failed.
                                    </p>
                                    <div class="ops-action-buttons">
                                        <button
                                            class="ops-btn ops-btn-dark"
                                            type="button"
                                            onclick="openActionModal('reconcile_rejection', <?= $selectedWithdrawalId ?>)"
                                        >Reconcile & Release</button>
                                    </div>
                                </div>
                            <?php elseif ($selectedStageKey === 'settled'): ?>
                                <div class="ops-action-panel is-green">
                                    <div class="ops-action-title">Settlement Finalized</div>
                                    <p class="ops-action-copy">
                                        This instruction is read-only. The bank settlement and MangaVault wallet debit are both final and linked by the settlement reference and ledger transaction.
                                    </p>
                                </div>
                            <?php elseif ($selectedStageKey === 'rejected'): ?>
                                <div class="ops-action-panel is-red">
                                    <div class="ops-action-title">Rejected · Funds Released</div>
                                    <p class="ops-action-copy">
                                        Verification was declined and the wallet reserve was automatically released. No merchant administrator follow-up is required.
                                    </p>
                                </div>
                            <?php elseif ($selectedStageKey === 'failed'): ?>
                                <div class="ops-action-panel is-orange">
                                    <div class="ops-action-title">Settlement Failed · Funds Released</div>
                                    <p class="ops-action-copy">
                                        Verification passed, but settlement did not complete. The wallet reserve was released automatically and the record is final.
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="ops-section">
                            <div class="ops-section-title">Operator Audit Trail</div>
                            <?php if ($auditTrail === []): ?>
                                <div class="ops-info-note">No operator audit events have been recorded yet.</div>
                            <?php else: ?>
                                <div class="ops-audit">
                                    <?php foreach ($auditTrail as $audit): ?>
                                        <div class="ops-audit-item">
                                            <div class="ops-audit-dot"></div>
                                            <div>
                                                <div class="ops-audit-title"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $audit['bank_gateway_log_action'])), ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="ops-audit-copy"><?= htmlspecialchars((string) $audit['bank_gateway_log_details'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="ops-audit-meta">
                                                    <?= htmlspecialchars((string) $audit['bank_gateway_operator_display_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(bankOpsMyt((string) $audit['bank_gateway_log_created_at']), ENT_QUOTES, 'UTF-8') ?> MYT
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
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
