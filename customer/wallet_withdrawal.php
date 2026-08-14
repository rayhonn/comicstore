<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/money_helper.php';
require_once __DIR__ . '/../includes/wallet_helper.php';
require_once __DIR__ . '/../includes/wallet_withdrawal_helper.php';

$user_id = current_user_id();
$error = '';
$amount_input = '';
$bank_code_input = '';
$holder_input = '';

$profileStatement = $pdo->prepare("
    SELECT
        user_first_name,
        user_last_name
    FROM users
    WHERE user_id = ?
    AND user_role = 'customer'
    LIMIT 1
");
$profileStatement->execute([$user_id]);
$profile = $profileStatement->fetch(
    PDO::FETCH_ASSOC
);

if (!$profile) {
    http_response_code(500);
    exit('Unable to verify your customer profile.');
}

$registered_name = trim(
    (string) $profile['user_first_name'] .
    ' ' .
    (string) $profile['user_last_name']
);

try {
    $wallet = getWalletSummary(
        $pdo,
        $user_id
    );
    $withdrawal_summary =
        getWalletWithdrawalSummary(
            $pdo,
            $user_id
        );
} catch (Throwable $e) {
    app_error_log(
        'Wallet withdrawal page failed: ' .
        $e->getMessage()
    );

    http_response_code(500);
    exit('Unable to load bank withdrawal.');
}

$eligible_sen = min(
    (int) $withdrawal_summary[
        'eligible_sen'
    ],
    (int) $wallet['available_sen']
);
$active_request =
    $withdrawal_summary['active_request'];
$banks = walletWithdrawalSupportedBanks();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $amount_input =
        is_string($_POST['amount'] ?? null)
            ? trim($_POST['amount'])
            : '';
    $bank_code_input =
        is_string($_POST['bank_code'] ?? null)
            ? strtoupper(trim($_POST['bank_code']))
            : '';
    $holder_input =
        is_string($_POST['account_holder'] ?? null)
            ? trim($_POST['account_holder'])
            : '';

    try {
        if ($active_request) {
            throw new WalletWithdrawalException(
                'You already have an active bank withdrawal request.'
            );
        }

        if ($eligible_sen < 100) {
            throw new WalletWithdrawalException(
                'No refund balance is currently eligible for bank withdrawal.'
            );
        }

        $amount_sen =
            normalizeWalletWithdrawalAmount(
                $amount_input
            );

        createWalletWithdrawalRequest(
            $pdo,
            $user_id,
            $amount_sen,
            $bank_code_input,
            $holder_input,
            $_POST['account_number'] ?? null,
            $_POST['current_password'] ?? null
        );

        $_SESSION[
            'wallet_withdrawal_submitted'
        ] = true;

        redirect_to(
            app_path(
                'customer/wallet.php'
            )
        );
    } catch (WalletWithdrawalException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        app_error_log(
            'Customer bank withdrawal request failed: ' .
            $e->getMessage()
        );

        $error =
            'Unable to submit the bank withdrawal request. Please try again.';
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
    <title>Bank Withdrawal - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-2xl mx-auto px-6 py-10">
        <a
            href="wallet.php"
            class="inline-flex items-center text-sm text-gray-500 hover:text-red-600 mb-5"
        >
            ← Back to My Wallet
        </a>

        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100">
                <div
                    class="flex items-start justify-between gap-4 flex-wrap"
                >
                    <div>
                        <h1 class="text-xl font-black text-gray-800">
                            Withdraw Refund to Bank
                        </h1>
                        <p class="text-sm text-gray-400 mt-1">
                            Only eligible refund credits can be transferred to a bank account.
                        </p>
                    </div>

                    <span
                        class="bg-green-50 text-green-700 border border-green-100 text-sm font-black px-4 py-2 rounded-xl"
                    >
                        RM <?= moneyFormatSen(
                            $eligible_sen
                        ) ?> eligible
                    </span>
                </div>
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

                <?php if ($active_request): ?>
                    <div
                        class="bg-yellow-50 border border-yellow-200 rounded-xl p-5"
                    >
                        <p class="font-bold text-yellow-800">
                            Active withdrawal request
                        </p>
                        <p class="text-sm text-yellow-700 mt-2">
                            Request #<?= str_pad(
                                (string) $active_request[
                                    'wallet_withdrawal_id'
                                ],
                                4,
                                '0',
                                STR_PAD_LEFT
                            ) ?> is currently
                            <strong><?= htmlspecialchars(
                                ucfirst(
                                    (string) $active_request[
                                        'wallet_withdrawal_status'
                                    ]
                                ),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?></strong>.
                            You can submit another request after this one is completed or rejected.
                        </p>
                    </div>

                <?php elseif ($eligible_sen < 100): ?>
                    <div
                        class="bg-gray-50 border border-gray-200 rounded-xl p-5 text-center"
                    >
                        <p class="font-bold text-gray-700">
                            No eligible refund balance
                        </p>
                        <p class="text-sm text-gray-500 mt-2">
                            Refund credits can be requested for bank transfer only within 7 days after they are credited to your MangaVault Wallet.
                        </p>
                    </div>

                <?php else: ?>
                    <div
                        class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-6"
                    >
                        <p class="text-sm font-semibold text-blue-800">
                            Refund withdrawal protection
                        </p>
                        <p class="text-xs text-blue-700 leading-relaxed mt-1">
                            The requested amount will be reserved immediately and cannot be used by another withdrawal while your request is under review. Your current MangaVault password is required before submission.
                        </p>

                        <?php if (!empty(
                            $withdrawal_summary[
                                'earliest_expiry'
                            ]
                        )): ?>
                            <p class="text-xs text-blue-700 mt-2">
                                Earliest eligible refund expires:
                                <strong><?= htmlspecialchars(
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
                                ) ?></strong>
                            </p>
                        <?php endif; ?>
                    </div>

                    <form method="POST" class="space-y-5">
                        <?php csrf_field(); ?>

                        <div>
                            <label
                                for="amount"
                                class="block text-sm font-semibold text-gray-700 mb-2"
                            >
                                Withdrawal Amount (RM)
                            </label>
                            <div class="flex gap-2">
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
                                    placeholder="<?= moneyFormatSen(
                                        $eligible_sen
                                    ) ?>"
                                    maxlength="12"
                                    autocomplete="off"
                                    required
                                    pattern="[0-9]+([.][0-9]{1,2})?"
                                    class="flex-1 border border-gray-200 rounded-xl px-4 py-3 text-lg font-bold focus:outline-none focus:border-red-400"
                                >
                                <button
                                    type="button"
                                    onclick="document.getElementById('amount').value='<?= moneySenToDecimal(
                                        $eligible_sen
                                    ) ?>'"
                                    class="px-4 rounded-xl border border-gray-200 bg-gray-50 hover:bg-gray-100 text-sm font-semibold text-gray-600"
                                >
                                    Max
                                </button>
                            </div>
                        </div>

                        <div>
                            <label
                                for="bank_code"
                                class="block text-sm font-semibold text-gray-700 mb-2"
                            >
                                Bank
                            </label>
                            <select
                                id="bank_code"
                                name="bank_code"
                                required
                                class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm bg-white focus:outline-none focus:border-red-400"
                            >
                                <option value="">
                                    Select bank
                                </option>
                                <?php foreach ($banks as $code => $name): ?>
                                    <option
                                        value="<?= htmlspecialchars(
                                            $code,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        <?= $bank_code_input === $code
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= htmlspecialchars(
                                            $name,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label
                                for="account_holder"
                                class="block text-sm font-semibold text-gray-700 mb-2"
                            >
                                Bank Account Holder
                            </label>
                            <input
                                type="text"
                                id="account_holder"
                                name="account_holder"
                                value="<?= htmlspecialchars(
                                    $holder_input !== ''
                                        ? $holder_input
                                        : $registered_name,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                maxlength="120"
                                autocomplete="name"
                                required
                                class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-red-400"
                            >
                            <p class="text-xs text-gray-400 mt-2">
                                For refund protection, the bank account holder must match your MangaVault customer name.
                            </p>
                        </div>

                        <div>
                            <label
                                for="account_number"
                                class="block text-sm font-semibold text-gray-700 mb-2"
                            >
                                Bank Account Number
                            </label>
                            <input
                                type="text"
                                inputmode="numeric"
                                id="account_number"
                                name="account_number"
                                maxlength="24"
                                autocomplete="off"
                                required
                                placeholder="Enter 8 to 20 digits"
                                class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm tracking-wider focus:outline-none focus:border-red-400"
                            >
                            <p class="text-xs text-gray-400 mt-2">
                                The full account number is encrypted before storage. Transaction history displays only the last 4 digits.
                            </p>
                        </div>

                        <div
                            class="border-t border-gray-100 pt-5"
                        >
                            <label
                                for="current_password"
                                class="block text-sm font-semibold text-gray-700 mb-2"
                            >
                                Confirm Current MangaVault Password
                            </label>
                            <input
                                type="password"
                                id="current_password"
                                name="current_password"
                                maxlength="72"
                                autocomplete="current-password"
                                required
                                class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-red-400"
                            >
                        </div>

                        <div
                            class="bg-amber-50 border border-amber-200 rounded-xl p-4"
                        >
                            <p class="text-xs text-amber-700 leading-relaxed">
                                By submitting, you confirm that the bank details are correct and belong to you. Bank withdrawal requests cannot be edited after submission. If rejected, reserved funds are released back to your wallet and may be requested again only while the original refund is still within its 7-day withdrawal window.
                            </p>
                        </div>

                        <button
                            type="submit"
                            onclick="return confirm('Submit this bank withdrawal request? Bank details cannot be edited after submission.')"
                            class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3.5 rounded-xl text-sm transition-colors"
                        >
                            Submit Bank Withdrawal Request
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>
