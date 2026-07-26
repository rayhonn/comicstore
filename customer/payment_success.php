<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ .
    '/../includes/payment_finalization_helper.php';
require_once __DIR__ . '/../includes/config.php';

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
            'customer/orders.php' .
            '?payment_processing=1'
        )
    );
}

$user_id = current_user_id();

try {
    $result = fulfillStripeCheckoutSession(
        $pdo,
        $session_id,
        $user_id
    );
} catch (
    \Stripe\Exception\ApiErrorException $e
) {
    app_error_log(
        'Stripe paid session retrieval failed: ' .
        $e->getMessage()
    );

    redirect_to(
        app_path(
            'customer/orders.php' .
            '?payment_processing=1'
        )
    );
} catch (Throwable $e) {
    app_error_log(
        'Paid payment finalization failed: ' .
        $e->getMessage()
    );

    redirect_to(
        app_path(
            'customer/orders.php' .
            '?payment_processing=1'
        )
    );
}

$order_id = (int) $result['order_id'];

$_SESSION['stripe_processed_session_id'] =
    $session_id;

$_SESSION['stripe_processed_order_id'] =
    $order_id;

unset($_SESSION['pending_order']);
unset($_SESSION['payment_draft_id']);
unset($_SESSION['payment_lock']);
unset($_SESSION['stripe_session_id']);
unset($_SESSION['stripe_checkout_url']);
unset($_SESSION['stripe_expires_at']);

if (
    $result['payment_status'] ===
    'pending_confirmation'
) {
    redirect_to(
        app_path(
            'customer/payment_waiting.php' .
            '?order_id=' .
            $order_id
        )
    );
}

if (
    $result['payment_status'] ===
    'confirmed'
) {
    redirect_to(
        app_path(
            'customer/order_success.php' .
            '?order_id=' .
            $order_id
        )
    );
}

redirect_to(
    app_path(
        'customer/orders.php'
    )
);
