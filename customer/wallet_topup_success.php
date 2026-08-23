<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();
app_clear_external_auth_flow();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/wallet_topup_helper.php';

$session_id = trim(
    (string) ($_GET['session_id'] ?? '')
);

$return_to =
    is_string(
        $_GET['return_to'] ?? null
    ) &&
    $_GET['return_to'] === 'checkout'
        ? 'checkout'
        : 'wallet';

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

    if ($return_to === 'checkout') {
        $_SESSION[
            'checkout_wallet_topup_success'
        ] = true;

        redirect_to(
            app_path(
                'customer/payment_gateway.php'
            )
        );
    }

    $_SESSION[
        'wallet_topup_success'
    ] = true;

    redirect_to(
        app_path(
            'customer/wallet.php'
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
