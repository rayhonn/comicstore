<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/wallet_topup_helper.php';

$session_id = trim(
    (string) ($_GET['session_id'] ?? '')
);

if (
    strlen($session_id) > 255 ||
    !preg_match(
        '/\Acs_[A-Za-z0-9_]+\z/',
        $session_id
    )
) {
    redirect_to(
        app_path(
            'customer/wallet.php?topup_error=1'
        )
    );
}

try {
    fulfillWalletTopupStripeSession(
        $pdo,
        $session_id,
        current_user_id()
    );

    redirect_to(
        app_path(
            'customer/wallet.php?topup_success=1'
        )
    );
} catch (
    \Stripe\Exception\ApiErrorException $e
) {
    app_error_log(
        'Wallet top-up Stripe verification failed: ' .
        $e->getMessage()
    );
} catch (Throwable $e) {
    app_error_log(
        'Wallet top-up completion failed: ' .
        $e->getMessage()
    );
}

redirect_to(
    app_path(
        'customer/wallet.php?topup_error=1'
    )
);
