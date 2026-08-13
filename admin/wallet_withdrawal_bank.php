<?php

require_once __DIR__ . '/../includes/auth.php';
require_senior_admin();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/wallet_withdrawal_helper.php';

header(
    'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0'
);
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

$withdrawal_id = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if (
    $withdrawal_id === false ||
    $withdrawal_id === null
) {
    http_response_code(404);
    exit('Withdrawal request not found.');
}

try {
    $request = walletWithdrawalLoadForAdmin(
        $pdo,
        (int) $withdrawal_id,
        false
    );
} catch (Throwable $e) {
    http_response_code(404);
    exit('Withdrawal request not found.');
}

if (
    !in_array(
        (string) $request[
            'wallet_withdrawal_status'
        ],
        [
            'pending',
            'approved',
        ],
        true
    )
) {
    http_response_code(403);

    exit(
        'Protected bank details are no longer available for this completed withdrawal workflow.'
    );
}

$error = '';
$account_number = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    try {
        $request =
            walletWithdrawalLoadForAdmin(
                $pdo,
                (int) $withdrawal_id,
                false
            );

        if (
            !in_array(
                (string) $request[
                    'wallet_withdrawal_status'
                ],
                [
                    'pending',
                    'approved',
                ],
                true
            )
        ) {
            throw new WalletWithdrawalException(
                'Protected bank details can no longer be revealed for this withdrawal.'
            );
        }

        verifyWalletActorPassword(
            $pdo,
            current_user_id(),
            'admin',
            $_POST['current_password'] ?? null
        );

        $account_number =
            decryptWalletWithdrawalAccountNumber(
                (string) $request[
                    'wallet_withdrawal_account_number_encrypted'
                ]
            );
    } catch (WalletWithdrawalException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        app_error_log(
            'Admin bank detail reveal failed: ' .
            $e->getMessage()
        );

        $error =
            'Unable to reveal bank details.';
    }
}

$customer_name = trim(
    (string) $request['user_first_name'] .
    ' ' .
    (string) $request['user_last_name']
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
    <title>Bank Details - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <main class="max-w-2xl mx-auto px-6 py-8">
        <a
            href="wallet_withdrawals.php"
            class="inline-flex items-center text-sm text-gray-500 hover:text-red-600 mb-5"
        >
            ← Back to Wallet Withdrawals
        </a>

        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100">
                <div
                    class="flex items-center justify-between gap-4 flex-wrap"
                >
                    <div>
                        <h1 class="text-xl font-black text-gray-800">
                            Protected Bank Details
                        </h1>
                        <p class="text-sm text-gray-400 mt-1">
                            Withdrawal #<?= str_pad(
                                (string) $withdrawal_id,
                                4,
                                '0',
                                STR_PAD_LEFT
                            ) ?>
                        </p>
                    </div>
                    <span
                        class="bg-red-50 text-red-700 border border-red-100 text-xs font-bold px-3 py-1.5 rounded-full"
                    >
                        Senior Admin Re-authentication
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

                <div
                    class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6"
                >
                    <div class="bg-gray-50 rounded-xl p-4">
                        <p class="text-xs text-gray-400">
                            Customer
                        </p>
                        <p class="text-sm font-bold text-gray-700 mt-1">
                            <?= htmlspecialchars(
                                $customer_name,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </p>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-4">
                        <p class="text-xs text-gray-400">
                            Bank
                        </p>
                        <p class="text-sm font-bold text-gray-700 mt-1">
                            <?= htmlspecialchars(
                                (string) $request[
                                    'wallet_withdrawal_bank_name'
                                ],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </p>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-4">
                        <p class="text-xs text-gray-400">
                            Account Holder
                        </p>
                        <p class="text-sm font-bold text-gray-700 mt-1">
                            <?= htmlspecialchars(
                                (string) $request[
                                    'wallet_withdrawal_account_holder'
                                ],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </p>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-4">
                        <p class="text-xs text-gray-400">
                            Account Number
                        </p>
                        <p class="text-sm font-bold text-gray-700 mt-1 tracking-wider">
                            <?php if ($account_number !== null): ?>
                                <?= htmlspecialchars(
                                    $account_number,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            <?php else: ?>
                                ••••••••<?= htmlspecialchars(
                                    (string) $request[
                                        'wallet_withdrawal_account_number_last4'
                                    ],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <?php if ($account_number === null): ?>
                    <div
                        class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-5"
                    >
                        <p class="text-xs text-amber-700 leading-relaxed">
                            Full bank account numbers are encrypted at rest. Enter your current administrator password to decrypt this request for transfer processing. The decrypted value is not stored in the session and disappears on refresh.
                        </p>
                    </div>

                    <form method="POST" class="space-y-4">
                        <?php csrf_field(); ?>
                        <div>
                            <label
                                for="current_password"
                                class="block text-sm font-semibold text-gray-700 mb-2"
                            >
                                Current Admin Password
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

                        <button
                            type="submit"
                            class="w-full bg-[#1e2d4a] hover:bg-[#162338] text-white font-bold py-3 rounded-xl text-sm"
                        >
                            Verify & Reveal Bank Account
                        </button>
                    </form>
                <?php else: ?>
                    <div
                        class="bg-green-50 border border-green-200 rounded-xl p-4"
                    >
                        <p class="text-sm font-bold text-green-800">
                            Bank details revealed for this page view only.
                        </p>
                        <p class="text-xs text-green-700 mt-1">
                            Return to Wallet Withdrawals after completing the external bank transfer.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

</body>
</html>
