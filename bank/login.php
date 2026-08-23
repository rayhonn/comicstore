<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ .
    '/../includes/wallet_withdrawal_helper.php';

if (
    current_role() === 'bank' &&
    !empty($_SESSION['bank_operator_id'])
) {
    redirect_to(
        app_path('bank/dashboard.php')
    );
}

$banks = walletWithdrawalSupportedBanks();
$error = '';
$bank_code_input = '';
$username_input = '';
$now = time();
$locked_until = (int) (
    $_SESSION['bank_login_locked_until'] ?? 0
);

if (
    $locked_until > 0 &&
    $locked_until <= $now
) {
    unset(
        $_SESSION['bank_login_failures'],
        $_SESSION['bank_login_locked_until']
    );
    $locked_until = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $bank_code_input = is_string(
        $_POST['bank_code'] ?? null
    )
        ? strtoupper(trim($_POST['bank_code']))
        : '';
    $username_input = is_string(
        $_POST['username'] ?? null
    )
        ? strtolower(trim($_POST['username']))
        : '';
    $password = $_POST['password'] ?? null;

    if ($locked_until > $now) {
        $error =
            'Too many unsuccessful attempts. Please wait before trying again.';
    } elseif (!isset($banks[$bank_code_input])) {
        $error =
            'Please select the operator bank.';
    } elseif (
        preg_match(
            '/\A[a-z0-9_]{3,60}\z/',
            $username_input
        ) !== 1
    ) {
        $error =
            'Please enter a valid bank operator username.';
    } elseif (
        !is_string($password) ||
        $password === '' ||
        strlen($password) > 72
    ) {
        $error =
            'Please enter a valid password.';
    }

    if ($error === '') {
        $statement = $pdo->prepare("
            SELECT
                bank_gateway_operator_id,
                bank_gateway_operator_bank_code,
                bank_gateway_operator_bank_name,
                bank_gateway_operator_username,
                bank_gateway_operator_password_hash,
                bank_gateway_operator_display_name,
                bank_gateway_operator_is_active
            FROM bank_gateway_operators
            WHERE bank_gateway_operator_username = ?
            AND bank_gateway_operator_bank_code = ?
            LIMIT 1
        ");
        $statement->execute([
            $username_input,
            $bank_code_input,
        ]);
        $operator = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        if (
            $operator &&
            (int) $operator[
                'bank_gateway_operator_is_active'
            ] === 1 &&
            password_verify(
                $password,
                (string) $operator[
                    'bank_gateway_operator_password_hash'
                ]
            )
        ) {
            regenerate_session();

            $_SESSION['bank_operator_id'] =
                (int) $operator[
                    'bank_gateway_operator_id'
                ];
            $_SESSION['bank_operator_code'] =
                (string) $operator[
                    'bank_gateway_operator_bank_code'
                ];
            $_SESSION['bank_operator_name'] =
                (string) $operator[
                    'bank_gateway_operator_display_name'
                ];
            $_SESSION['bank_operator_bank_name'] =
                (string) $operator[
                    'bank_gateway_operator_bank_name'
                ];
            $_SESSION['role'] = 'bank';
            $_SESSION['auth_last_activity_at'] =
                time();

            $update = $pdo->prepare("
                UPDATE bank_gateway_operators
                SET bank_gateway_operator_last_login_at =
                    UTC_TIMESTAMP()
                WHERE bank_gateway_operator_id = ?
            ");
            $update->execute([
                (int) $operator[
                    'bank_gateway_operator_id'
                ],
            ]);

            redirect_to(
                app_path('bank/dashboard.php')
            );
        }

        $failures = (int) (
            $_SESSION['bank_login_failures'] ?? 0
        ) + 1;
        $_SESSION['bank_login_failures'] =
            $failures;

        if ($failures >= 5) {
            $_SESSION['bank_login_locked_until'] =
                time() + 900;
        }

        $error =
            'Invalid bank, username, or password.';
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
    <title>Bank Gateway Login - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body
    class="min-h-screen bg-[#08111f] px-5 py-10 text-slate-800"
>
    <div
        class="fixed inset-0 overflow-hidden pointer-events-none"
        aria-hidden="true"
    >
        <div
            class="absolute -left-24 top-10 h-80 w-80 rounded-full bg-cyan-500/10 blur-3xl"
        ></div>
        <div
            class="absolute -right-20 bottom-0 h-96 w-96 rounded-full bg-blue-600/10 blur-3xl"
        ></div>
    </div>

    <main
        class="relative mx-auto flex min-h-[calc(100vh-5rem)] max-w-5xl items-center justify-center"
    >
        <div
            class="grid w-full overflow-hidden rounded-[32px] bg-white shadow-2xl lg:grid-cols-[1.05fr_0.95fr]"
        >
            <section
                class="relative hidden overflow-hidden bg-gradient-to-br from-[#12233f] to-[#08111f] p-10 text-white lg:block"
            >
                <div
                    class="absolute right-[-70px] top-[-70px] h-64 w-64 rounded-full border-[40px] border-cyan-400/10"
                ></div>

                <div class="relative">
                    <div
                        class="inline-flex items-center gap-2 rounded-full border border-cyan-300/20 bg-cyan-300/10 px-3 py-1.5 text-[10px] font-black uppercase tracking-[0.18em] text-cyan-200"
                    >
                        Controlled Simulation
                    </div>

                    <h1
                        class="mt-7 text-4xl font-black leading-tight"
                    >
                        Bank verification before wallet transfer.
                    </h1>

                    <p
                        class="mt-4 max-w-md text-sm leading-7 text-slate-300"
                    >
                        An isolated academic gateway for validating MangaVault refund withdrawal instructions before an administrator performs the external bank transfer.
                    </p>

                    <div class="mt-9 space-y-4">
                        <?php foreach ([
                            ['01', 'Institution isolation', 'Operators can access requests addressed only to their registered bank.'],
                            ['02', 'Dual authorization', 'Admin approval and bank verification are recorded separately.'],
                            ['03', 'Auditable proof', 'Every decision produces a protected reference, Malaysia timestamp, and downloadable PDF.'],
                        ] as $feature): ?>
                            <div class="flex items-start gap-4">
                                <span
                                    class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-white/10 text-xs font-black text-cyan-300"
                                >
                                    <?= $feature[0] ?>
                                </span>
                                <div>
                                    <p class="text-sm font-bold">
                                        <?= $feature[1] ?>
                                    </p>
                                    <p
                                        class="mt-1 text-xs leading-5 text-slate-400"
                                    >
                                        <?= $feature[2] ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="p-7 sm:p-10">
                <div class="mb-7">
                    <div
                        class="flex h-12 w-12 items-center justify-center rounded-2xl bg-[#12233f] font-black text-cyan-300"
                    >
                        BG
                    </div>
                    <h2 class="mt-5 text-2xl font-black text-slate-900">
                        Bank Operator Login
                    </h2>
                    <p class="mt-1 text-sm text-slate-400">
                        Select your institution and enter the assigned operator credentials.
                    </p>
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
                <?php elseif (isset($_GET['logged_out'])): ?>
                    <div
                        class="mb-5 rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"
                    >
                        Bank gateway session ended securely.
                    </div>
                <?php elseif (isset($_GET['session_expired'])): ?>
                    <div
                        class="mb-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700"
                    >
                        Your session expired after 15 minutes of inactivity.
                    </div>
                <?php endif; ?>

                <form method="POST" target="_self" class="space-y-5">
                    <?php csrf_field(); ?>

                    <div>
                        <label
                            for="bankCode"
                            class="mb-2 block text-xs font-black uppercase tracking-wider text-slate-500"
                        >
                            Financial Institution
                        </label>
                        <select
                            id="bankCode"
                            name="bank_code"
                            required
                            class="w-full rounded-xl border-2 border-slate-100 bg-slate-50 px-4 py-3 text-sm font-semibold outline-none transition-colors focus:border-cyan-400 focus:bg-white"
                        >
                            <option value="">Select bank</option>
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
                            for="operatorUsername"
                            class="mb-2 block text-xs font-black uppercase tracking-wider text-slate-500"
                        >
                            Operator Username
                        </label>
                        <input
                            type="text"
                            id="operatorUsername"
                            name="username"
                            value="<?= htmlspecialchars(
                                $username_input,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            minlength="3"
                            maxlength="60"
                            pattern="[a-z0-9_]+"
                            autocomplete="username"
                            required
                            class="w-full rounded-xl border-2 border-slate-100 bg-slate-50 px-4 py-3 text-sm outline-none transition-colors focus:border-cyan-400 focus:bg-white"
                        >
                    </div>

                    <div>
                        <label
                            for="operatorPassword"
                            class="mb-2 block text-xs font-black uppercase tracking-wider text-slate-500"
                        >
                            Password
                        </label>
                        <input
                            type="password"
                            id="operatorPassword"
                            name="password"
                            maxlength="72"
                            autocomplete="current-password"
                            required
                            class="w-full rounded-xl border-2 border-slate-100 bg-slate-50 px-4 py-3 text-sm outline-none transition-colors focus:border-cyan-400 focus:bg-white"
                        >
                    </div>

                    <button
                        type="submit"
                        class="w-full rounded-xl bg-[#12233f] px-4 py-3.5 text-sm font-black text-white shadow-lg shadow-slate-900/10 transition-colors hover:bg-[#0b192d]"
                    >
                        Access Verification Queue
                    </button>
                </form>

                <div
                    class="mt-6 rounded-2xl border border-cyan-100 bg-cyan-50 px-4 py-3"
                >
                    <p class="text-[11px] leading-5 text-cyan-800">
                        This portal simulates a partner-bank verification workflow for academic demonstration. It is not connected to any Malaysian bank production system.
                    </p>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
