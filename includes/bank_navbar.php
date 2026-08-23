<?php

require_once __DIR__ . '/auth.php';

$bank_name = trim(
    (string) (
        $_SESSION['bank_operator_bank_name'] ??
        'Institution'
    )
);
$operator_name = trim(
    (string) (
        $_SESSION['bank_operator_name'] ??
        'Authorized Operator'
    )
);
?>

<header class="bank-nav">
    <div class="bank-nav-inner">
        <a
            href="<?= htmlspecialchars(
                app_path('bank/dashboard.php'),
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
            class="bank-brand"
        >
            <span class="bank-brand-mark" aria-hidden="true">BL</span>
            <span>
                <span class="bank-brand-name">
                    BankLink Settlement Services
                </span>
                <span class="bank-brand-subtitle">
                    Institution Operations Portal
                </span>
            </span>
        </a>

        <div class="bank-nav-right">
            <div class="bank-nav-session">
                <span class="bank-secure-dot"></span>
                Secure session · 15 min
            </div>

            <div class="bank-nav-identity">
                <p class="bank-nav-bank">
                    <?= htmlspecialchars(
                        $bank_name,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>
                <p class="bank-nav-operator">
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
                class="bank-nav-logout"
            >
                Sign Out
            </button>
        </div>
    </div>
</header>

<div class="bank-demo-badge">Academic demo</div>

<?php
$logout_modal_action = app_path('bank/logout.php');
$logout_modal_account_label = 'bank operator account';
require __DIR__ . '/logout_modal.php';
?>
