<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/money_helper.php';
require_once __DIR__ . '/../includes/wallet_helper.php';
require_once __DIR__ . '/../includes/wallet_withdrawal_lifecycle_helper.php';
require_once __DIR__ . '/../includes/wallet_withdrawal_mail_helper.php';

$userId = current_user_id();
walletWithdrawalUseMalaysiaDatabaseTime($pdo);
$error = '';
$amountInput = '';
$bankCodeInput = '';
$holderInput = '';

try {
    assertWalletWithdrawalLifecycleSchema($pdo);

    $profileStatement = $pdo->prepare("
        SELECT user_first_name, user_last_name
        FROM users
        WHERE user_id = ?
          AND user_role = 'customer'
          AND user_is_active = 1
          AND user_deleted_at IS NULL
        LIMIT 1
    ");
    $profileStatement->execute([$userId]);
    $profile = $profileStatement->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        throw new WalletWithdrawalException('Unable to verify your customer profile.');
    }

    $wallet = getWalletSummary($pdo, $userId);
    $withdrawalSummary = getWalletWithdrawalSummaryLifecycle($pdo, $userId);
} catch (Throwable $e) {
    app_error_log('Wallet withdrawal page failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load bank withdrawal.');
}

$registeredName = trim(
    (string) $profile['user_first_name'] . ' ' .
    (string) $profile['user_last_name']
);
$eligibleSen = min(
    (int) $withdrawalSummary['eligible_sen'],
    (int) $wallet['available_sen']
);
$retryEligibleSen = min(
    (int) ($withdrawalSummary['retry_eligible_sen'] ?? 0),
    $eligibleSen
);
$retryExpiry = trim((string) ($withdrawalSummary['retry_expires_at_myt'] ?? ''));
$activeRequest = $withdrawalSummary['active_request'];
$banks = walletWithdrawalSupportedBanks();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $amountInput = is_string($_POST['amount'] ?? null)
        ? trim($_POST['amount'])
        : '';
    $bankCodeInput = is_string($_POST['bank_code'] ?? null)
        ? strtoupper(trim($_POST['bank_code']))
        : '';
    $holderInput = is_string($_POST['account_holder'] ?? null)
        ? trim($_POST['account_holder'])
        : '';

    try {
        if ($activeRequest) {
            throw new WalletWithdrawalException(
                'You already have an active bank withdrawal request.'
            );
        }

        if ($eligibleSen < 100) {
            throw new WalletWithdrawalException(
                'No refund balance is currently eligible for bank withdrawal.'
            );
        }

        $amountSen = normalizeWalletWithdrawalAmount($amountInput);
        $withdrawalId = createWalletWithdrawalRequestLifecycle(
            $pdo,
            $userId,
            $amountSen,
            $bankCodeInput,
            $holderInput,
            $_POST['account_number'] ?? null,
            $_POST['current_password'] ?? null
        );

        dispatchWalletWithdrawalLifecycleCommunication(
            $pdo,
            $withdrawalId,
            'submitted'
        );

        $_SESSION['wallet_withdrawal_submitted'] = true;
        redirect_to(app_path('customer/wallet.php'));
    } catch (WalletWithdrawalException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        app_error_log(
            'Customer bank withdrawal request failed: ' . $e->getMessage()
        );
        $error = 'Unable to submit the bank withdrawal request. Please try again.';
    }
}

$earliestExpiryLabel = '';
if (!empty($withdrawalSummary['earliest_expiry'])) {
    $earliestExpiryLabel = walletWithdrawalMalaysiaWallClock(
        $withdrawalSummary['earliest_expiry'],
        'd M Y, h:i A',
        ''
    );
}
$retryExpiryLabel = $retryExpiry !== ''
    ? walletWithdrawalMalaysiaWallClock($retryExpiry, 'd M Y, h:i A', '')
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bank Withdrawal - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-[#F5F0EB]">

<?php include '../includes/customer_navbar.php'; ?>

<div class="mx-auto max-w-3xl px-6 py-10">
    <a href="wallet.php" class="mb-5 inline-flex items-center text-sm text-gray-500 hover:text-red-600">← Back to My Wallet</a>

    <section class="overflow-hidden rounded-2xl bg-white shadow-sm">
        <div class="border-b border-gray-100 px-6 py-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl font-black text-gray-800">Withdraw Refund to Bank</h1>
                    <p class="mt-1 text-sm text-gray-400">Only eligible refund credits can be transferred to your own Malaysian bank account.</p>
                </div>
                <span class="rounded-xl border border-green-100 bg-green-50 px-4 py-2 text-sm font-black text-green-700">RM <?= moneyFormatSen($eligibleSen) ?> eligible</span>
            </div>
        </div>

        <div class="p-6">
            <?php if ($error !== ''): ?>
                <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <div class="mb-6 overflow-hidden rounded-xl border border-slate-200">
                <div class="border-b border-slate-200 bg-slate-50 px-5 py-4">
                    <p class="text-sm font-black text-slate-800">How the bank withdrawal lifecycle works</p>
                    <p class="mt-1 text-xs leading-relaxed text-slate-500">MangaVault authorizes the refund request, but the destination bank independently verifies and settles it. Your wallet is debited only after successful final settlement.</p>
                </div>
                <div class="grid grid-cols-1 divide-y divide-slate-200 md:grid-cols-5 md:divide-x md:divide-y-0">
                    <?php foreach ([
                        ['1', 'Submit', 'Amount reserved'],
                        ['2', 'MangaVault', 'Approve / reject'],
                        ['3', 'Bank Verify', 'Accept / reject'],
                        ['4', 'Settlement', 'Process transfer'],
                        ['5', 'Final', 'Settled or released'],
                    ] as $step): ?>
                        <div class="px-4 py-3">
                            <p class="text-[9px] font-black uppercase tracking-wider text-slate-400">Step <?= $step[0] ?></p>
                            <p class="mt-1 text-xs font-black text-slate-800"><?= htmlspecialchars($step[1], ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="mt-0.5 text-[10px] text-slate-500"><?= htmlspecialchars($step[2], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($retryEligibleSen > 0): ?>
                <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-relaxed text-amber-800">
                    <strong>Correction retry window:</strong>
                    RM <?= moneyFormatSen($retryEligibleSen) ?> from a failed bank attempt is eligible again<?= $retryExpiryLabel !== '' ? ' until ' . htmlspecialchars($retryExpiryLabel, ENT_QUOTES, 'UTF-8') . ' MYT (UTC+8)' : '' ?>. This retry applies only to the failed amount; it does not reopen unrelated expired refund credits.
                </div>
            <?php endif; ?>

            <?php if ($activeRequest): ?>
                <div class="rounded-xl border border-yellow-200 bg-yellow-50 p-5">
                    <p class="font-bold text-yellow-800">Active withdrawal request</p>
                    <p class="mt-2 text-sm text-yellow-700">Request #<?= str_pad((string) $activeRequest['wallet_withdrawal_id'], 4, '0', STR_PAD_LEFT) ?> is still active. You can submit another request only after the current lifecycle reaches a final result.</p>
                </div>
            <?php elseif ($eligibleSen < 100): ?>
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-5 text-center">
                    <p class="font-bold text-gray-700">No eligible refund balance</p>
                    <p class="mt-2 text-sm text-gray-500">Refund credits are normally cash-withdrawable within their original 7-day window. A failed bank attempt can also create a limited correction-retry window.</p>
                </div>
            <?php else: ?>
                <div class="mb-6 rounded-xl border border-blue-100 bg-blue-50 p-4">
                    <p class="text-sm font-semibold text-blue-800">Refund withdrawal protection</p>
                    <p class="mt-1 text-xs leading-relaxed text-blue-700">The requested amount is reserved immediately. If MangaVault rejects the request, the bank rejects verification, or settlement fails, the reserved amount is released automatically back to your available wallet balance. A bank rejection or settlement failure also provides a 3-day correction-retry window for that failed amount.</p>
                    <?php if ($earliestExpiryLabel !== ''): ?>
                        <p class="mt-2 text-xs text-blue-700">Earliest current eligibility deadline: <strong><?= htmlspecialchars($earliestExpiryLabel, ENT_QUOTES, 'UTF-8') ?> MYT (UTC+8)</strong></p>
                    <?php endif; ?>
                </div>

                <form method="POST" class="space-y-5" id="customerWithdrawalForm" onsubmit="return openCustomerWithdrawalModal(event, this)">
                    <?php csrf_field(); ?>

                    <div>
                        <label for="amount" class="mb-2 block text-sm font-semibold text-gray-700">Withdrawal Amount (RM)</label>
                        <div class="flex gap-2">
                            <input
                                type="text"
                                inputmode="decimal"
                                id="amount"
                                name="amount"
                                value="<?= htmlspecialchars($amountInput, ENT_QUOTES, 'UTF-8') ?>"
                                placeholder="<?= moneyFormatSen($eligibleSen) ?>"
                                maxlength="12"
                                autocomplete="off"
                                required
                                pattern="[0-9]+([.][0-9]{1,2})?"
                                class="min-w-0 flex-1 rounded-xl border border-gray-200 px-4 py-3 text-lg font-bold focus:border-red-400 focus:outline-none"
                            >
                            <button type="button" onclick="document.getElementById('amount').value='<?= moneySenToDecimal($eligibleSen) ?>'" class="rounded-xl border border-gray-200 bg-gray-50 px-4 text-sm font-semibold text-gray-600 hover:bg-gray-100">Max</button>
                        </div>
                    </div>

                    <div>
                        <label for="bank_code" class="mb-2 block text-sm font-semibold text-gray-700">Bank</label>
                        <select id="bank_code" name="bank_code" required class="w-full rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm focus:border-red-400 focus:outline-none">
                            <option value="">Select bank</option>
                            <?php foreach ($banks as $code => $name): ?>
                                <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" <?= $bankCodeInput === $code ? 'selected' : '' ?>><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="account_holder" class="mb-2 block text-sm font-semibold text-gray-700">Bank Account Holder</label>
                        <input
                            type="text"
                            id="account_holder"
                            name="account_holder"
                            value="<?= htmlspecialchars($holderInput !== '' ? $holderInput : $registeredName, ENT_QUOTES, 'UTF-8') ?>"
                            maxlength="120"
                            autocomplete="name"
                            required
                            class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm focus:border-red-400 focus:outline-none"
                        >
                        <p class="mt-2 text-xs text-gray-400">The account holder must match your MangaVault customer name.</p>
                    </div>

                    <div>
                        <label for="account_number" class="mb-2 block text-sm font-semibold text-gray-700">Bank Account Number</label>
                        <input
                            type="text"
                            inputmode="numeric"
                            id="account_number"
                            name="account_number"
                            maxlength="24"
                            autocomplete="off"
                            required
                            placeholder="Enter 8 to 20 digits"
                            class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm tracking-wider focus:border-red-400 focus:outline-none"
                        >
                        <p class="mt-2 text-xs text-gray-400">The full account number is encrypted before storage. History displays only the last four digits.</p>
                    </div>

                    <div class="border-t border-gray-100 pt-5">
                        <label for="current_password" class="mb-2 block text-sm font-semibold text-gray-700">Confirm Current MangaVault Password</label>
                        <input
                            type="password"
                            id="current_password"
                            name="current_password"
                            maxlength="72"
                            autocomplete="current-password"
                            required
                            class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm focus:border-red-400 focus:outline-none"
                        >
                    </div>

                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <p class="text-xs leading-relaxed text-amber-700">By submitting, you confirm that the destination bank details are correct and belong to you. The request cannot be edited after submission. MangaVault notifications and email updates are issued for submission, MangaVault approval/rejection, bank acceptance/rejection, settlement processing, final settlement, and settlement failure. Official PDFs remain available in My Wallet.</p>
                    </div>

                    <button type="submit" class="w-full rounded-xl bg-red-600 py-3.5 text-sm font-bold text-white transition-colors hover:bg-red-700">Submit Bank Withdrawal Request</button>
                </form>
            <?php endif; ?>
        </div>
    </section>
</div>

<div id="customerWithdrawalModal" class="fixed inset-0 z-[120] hidden items-center justify-center bg-slate-950/65 px-4 py-8 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="customerWithdrawalModalTitle">
    <div class="w-full max-w-md overflow-hidden rounded-3xl bg-white shadow-2xl">
        <div class="bg-[#17243d] px-6 py-5 text-white">
            <h2 id="customerWithdrawalModalTitle" class="text-xl font-black">Confirm Bank Withdrawal</h2>
            <p class="mt-1 text-sm text-white/65">Please verify the destination details before reserving your refund amount.</p>
        </div>
        <div class="p-6">
            <div class="space-y-3 rounded-2xl border border-gray-200 bg-gray-50 p-4 text-sm">
                <div class="flex justify-between gap-4"><span class="text-gray-500">Amount</span><strong id="confirmWithdrawalAmount">RM 0.00</strong></div>
                <div class="flex justify-between gap-4"><span class="text-gray-500">Bank</span><strong id="confirmWithdrawalBank" class="text-right">-</strong></div>
                <div class="flex justify-between gap-4"><span class="text-gray-500">Account holder</span><strong id="confirmWithdrawalHolder" class="text-right">-</strong></div>
                <div class="flex justify-between gap-4"><span class="text-gray-500">Account</span><strong id="confirmWithdrawalAccount" class="font-mono">-</strong></div>
            </div>
            <p class="mt-5 text-sm leading-6 text-gray-600">Submitting reserves the requested amount immediately. The wallet is permanently debited only after successful bank settlement.</p>
            <div class="mt-6 grid grid-cols-2 gap-3">
                <button type="button" onclick="closeCustomerWithdrawalModal()" class="rounded-xl border border-gray-200 px-4 py-3 text-sm font-bold text-gray-600 hover:bg-gray-50">Cancel</button>
                <button type="button" id="confirmCustomerWithdrawalButton" onclick="confirmCustomerWithdrawal()" class="rounded-xl bg-red-600 px-4 py-3 text-sm font-bold text-white hover:bg-red-700">Submit Request</button>
            </div>
        </div>
    </div>
</div>

<script>
    let pendingCustomerWithdrawalForm = null;

    function openCustomerWithdrawalModal(event, form) {
        event.preventDefault();
        if (!form.reportValidity()) {
            return false;
        }

        pendingCustomerWithdrawalForm = form;
        const amount = form.querySelector('[name="amount"]').value.trim();
        const bank = form.querySelector('[name="bank_code"]');
        const holder = form.querySelector('[name="account_holder"]').value.trim();
        const accountRaw = form.querySelector('[name="account_number"]').value.replace(/\D/g, '');
        const masked = accountRaw.length > 4 ? '••••' + accountRaw.slice(-4) : accountRaw;

        document.getElementById('confirmWithdrawalAmount').textContent = 'RM ' + amount;
        document.getElementById('confirmWithdrawalBank').textContent = bank.options[bank.selectedIndex]?.text || '-';
        document.getElementById('confirmWithdrawalHolder').textContent = holder || '-';
        document.getElementById('confirmWithdrawalAccount').textContent = masked || '-';

        const modal = document.getElementById('customerWithdrawalModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
        return false;
    }

    function closeCustomerWithdrawalModal() {
        const modal = document.getElementById('customerWithdrawalModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
        pendingCustomerWithdrawalForm = null;
    }

    function confirmCustomerWithdrawal() {
        if (!pendingCustomerWithdrawalForm) {
            return;
        }
        const button = document.getElementById('confirmCustomerWithdrawalButton');
        button.disabled = true;
        button.textContent = 'Submitting...';
        pendingCustomerWithdrawalForm.submit();
    }

    document.getElementById('customerWithdrawalModal').addEventListener('click', function (event) {
        if (event.target === this) {
            closeCustomerWithdrawalModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeCustomerWithdrawalModal();
        }
    });
</script>

</body>
</html>
