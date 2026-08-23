<?php

require_once __DIR__ . '/auth.php';

$bank_name = trim(
    (string) (
        $_SESSION['bank_operator_bank_name'] ??
        'Bank Gateway'
    )
);
$operator_name = trim(
    (string) (
        $_SESSION['bank_operator_name'] ??
        'Verification Operator'
    )
);
?>

<header
    class="sticky top-0 z-40 border-b border-slate-700/70 bg-[#0d1728]/95 text-white shadow-lg backdrop-blur"
>
    <div
        class="mx-auto flex h-20 max-w-7xl items-center justify-between gap-5 px-5 md:px-8"
    >
        <a
            href="<?= htmlspecialchars(
                app_path('bank/dashboard.php'),
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
            class="flex items-center gap-3"
        >
            <div
                class="flex h-11 w-11 items-center justify-center rounded-2xl bg-cyan-400 font-black text-[#0d1728] shadow-lg shadow-cyan-500/20"
            >
                BG
            </div>
            <div>
                <p class="text-lg font-black tracking-tight">
                    MangaVault Bank Gateway
                </p>
                <p
                    class="text-[10px] font-bold uppercase tracking-[0.2em] text-cyan-300"
                >
                    Simulation Environment
                </p>
            </div>
        </a>

        <div class="flex items-center gap-3">
            <div class="hidden text-right sm:block">
                <p class="text-sm font-bold">
                    <?= htmlspecialchars(
                        $bank_name,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>
                <p class="text-xs text-slate-400">
                    <?= htmlspecialchars(
                        $operator_name,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>
            </div>

            <button
                type="button"
                onclick="openSharedLogoutModal()"
                class="rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-xs font-bold text-slate-300 transition-colors hover:bg-white/10 hover:text-white"
            >
                Log Out
            </button>
        </div>
    </div>
</header>

<?php
$logout_modal_action = app_path('bank/logout.php');
$logout_modal_account_label = 'bank gateway account';
require __DIR__ . '/logout_modal.php';
?>
