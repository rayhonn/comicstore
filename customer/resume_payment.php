<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/stripe_config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/voucher_helper.php';
require_once __DIR__ . '/../includes/config.php';

function clearResumeSessionState(): void
{
    unset($_SESSION['pending_order']);
    unset($_SESSION['payment_lock']);
    unset($_SESSION['stripe_session_id']);
    unset($_SESSION['stripe_checkout_url']);
    unset($_SESSION['stripe_expires_at']);
}

$user_id = current_user_id();

$pending_order =
    $_SESSION['pending_order'] ?? null;

if (
    !is_array($pending_order) ||
    (int) ($pending_order['user_id'] ?? 0)
        !== $user_id
) {
    clearResumeSessionState();

    redirect_to(
        app_path('customer/orders.php')
    );
}

$stripe_session_id =
    $_SESSION['stripe_session_id'] ?? '';

if (
    !is_string($stripe_session_id) ||
    $stripe_session_id === ''
) {
    redirect_to(
        app_path(
            'customer/payment_gateway.php'
        )
    );
}

if (
    strlen($stripe_session_id) > 255 ||
    !str_starts_with(
        $stripe_session_id,
        'cs_'
    )
) {
    restorePendingUserVoucher(
        $pdo,
        $pending_order['voucher_id'] ?? null,
        $user_id
    );

    clearResumeSessionState();

    redirect_to(
        app_path(
            'customer/cart.php?payment_invalid=1'
        )
    );
}

\Stripe\Stripe::setApiKey(
    STRIPE_SECRET_KEY
);

try {
    $checkout_session =
        \Stripe\Checkout\Session::retrieve(
            $stripe_session_id
        );

    $session_user_id =
        (string) (
            $checkout_session
                ->client_reference_id
            ?? ''
        );

    if (
        $session_user_id !==
        (string) $user_id
    ) {
        throw new RuntimeException(
            'Stripe Checkout Session user mismatch.'
        );
    }

    $expected_amount = (int) round(
        (float) (
            $pending_order['total'] ?? 0
        ) * 100
    );

    $session_amount =
        (int) (
            $checkout_session->amount_total
            ?? -1
        );

    if (
        $expected_amount <= 0 ||
        $session_amount !== $expected_amount
    ) {
        throw new RuntimeException(
            'Stripe Checkout Session amount mismatch.'
        );
    }

    $expected_currency = strtolower(
        (string) STRIPE_CURRENCY
    );

    $session_currency = strtolower(
        (string) (
            $checkout_session->currency
            ?? ''
        )
    );

    if (
        $session_currency !==
        $expected_currency
    ) {
        throw new RuntimeException(
            'Stripe Checkout Session currency mismatch.'
        );
    }

    $payment_status =
        (string) (
            $checkout_session->payment_status
            ?? ''
        );

    $session_status =
        (string) (
            $checkout_session->status
            ?? ''
        );

    $session_expires_at =
        (int) (
            $checkout_session->expires_at
            ?? 0
        );

    if ($payment_status === 'paid') {
        redirect_to(
            app_path(
                'customer/payment_success.php' .
                '?session_id=' .
                urlencode($stripe_session_id)
            )
        );
    }

    if (
        $session_status === 'open' &&
        $payment_status === 'unpaid' &&
        $session_expires_at > time()
    ) {
        $checkout_url =
            (string) (
                $checkout_session->url
                ?? ''
            );

        if (
            $checkout_url === '' ||
            !str_starts_with(
                $checkout_url,
                'https://checkout.stripe.com/'
            )
        ) {
            throw new RuntimeException(
                'Stripe Checkout URL is unavailable.'
            );
        }

        $_SESSION['stripe_checkout_url'] =
            $checkout_url;

        $_SESSION['stripe_expires_at'] =
            $session_expires_at;

        header(
            'Location: ' . $checkout_url
        );
        exit;
    }

    restorePendingUserVoucher(
        $pdo,
        $pending_order['voucher_id'] ?? null,
        $user_id
    );

    clearResumeSessionState();

    if (
        $session_status === 'expired' ||
        $session_expires_at <= time()
    ) {
        redirect_to(
            app_path(
                'customer/cart.php' .
                '?payment_expired=1'
            )
        );
    }

    redirect_to(
        app_path(
            'customer/cart.php' .
                '?payment_unavailable=1'
        )
    );
} catch (
    \Stripe\Exception\ApiErrorException $e
) {
    error_log(
        'Stripe Checkout Session resume failed: ' .
        $e->getMessage()
    );

    redirect_to(
        app_path(
            'customer/orders.php' .
                '?payment_resume_error=1'
        )
    );
} catch (Throwable $e) {
    error_log(
        'Payment resume validation failed: ' .
        $e->getMessage()
    );

    restorePendingUserVoucher(
        $pdo,
        $pending_order['voucher_id'] ?? null,
        $user_id
    );

    clearResumeSessionState();

    redirect_to(
        app_path(
            'customer/cart.php' .
                '?payment_invalid=1'
        )
    );
}