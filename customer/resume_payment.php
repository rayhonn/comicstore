<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/stripe_config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ .
    '/../includes/payment_draft_helper.php';
require_once __DIR__ . '/../includes/config.php';

function clearResumeSessionState(): void
{
    unset($_SESSION['pending_order']);
    unset($_SESSION['payment_draft_id']);
    unset($_SESSION['payment_lock']);
    unset($_SESSION['stripe_session_id']);
    unset($_SESSION['stripe_checkout_url']);
    unset($_SESSION['stripe_expires_at']);
}

$user_id = current_user_id();

$payment_draft_id = filter_var(
    $_SESSION['payment_draft_id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if ($payment_draft_id === false) {
    $payment_draft_id = null;
}

$payment_draft = null;

if ($payment_draft_id !== null) {
    $payment_draft = loadPaymentDraft(
        $pdo,
        (int) $payment_draft_id,
        $user_id
    );

    if (
        $payment_draft !== null &&
        !in_array(
            $payment_draft['status'],
            [
                'pending',
                'checkout_open',
            ],
            true
        )
    ) {
        $payment_draft = null;
    }
}

if ($payment_draft === null) {
    $payment_draft_id =
        findActivePaymentDraftId(
            $pdo,
            $user_id
        );

    if ($payment_draft_id !== null) {
        $payment_draft = loadPaymentDraft(
            $pdo,
            $payment_draft_id,
            $user_id
        );
    }
}

if ($payment_draft === null) {
    clearResumeSessionState();

    redirect_to(
        app_path(
            'customer/orders.php'
        )
    );
}

$payment_draft_id =
    (int) $payment_draft[
        'payment_draft_id'
    ];

$_SESSION['payment_draft_id'] =
    $payment_draft_id;

/*
 * Temporary compatibility for payment_success.php.
 * Payment validation uses the database draft.
 */
$_SESSION['pending_order'] =
    $payment_draft;

$stripe_session_id = trim(
    (string) (
        $payment_draft[
            'stripe_session_id'
        ] ?? ''
    )
);

if ($stripe_session_id === '') {
    redirect_to(
        app_path(
            'customer/payment_gateway.php'
        )
    );
}

if (
    strlen($stripe_session_id) > 255 ||
    !preg_match(
        '/\Acs_[A-Za-z0-9_]+\z/',
        $stripe_session_id
    )
) {
    try {
        expirePaymentDraft(
            $pdo,
            $payment_draft_id,
            $user_id
        );
    } catch (Throwable $e) {
        app_error_log(
            'Invalid payment draft expiry failed: ' .
            $e->getMessage()
        );
    }

    clearResumeSessionState();

    redirect_to(
        app_path(
            'customer/cart.php' .
            '?payment_invalid=1'
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

    $session_user_id = (string) (
        $checkout_session
            ->client_reference_id
        ?? ''
    );

    $metadata_draft_id = (string) (
        $checkout_session
            ->metadata
            ->payment_draft_id
        ?? ''
    );

    $metadata_user_id = (string) (
        $checkout_session
            ->metadata
            ->user_id
        ?? ''
    );

    if (
        $session_user_id !==
            (string) $user_id ||
        $metadata_user_id !==
            (string) $user_id ||
        $metadata_draft_id !==
            (string) $payment_draft_id
    ) {
        throw new RuntimeException(
            'Stripe Checkout Session ownership mismatch.'
        );
    }

    $expected_amount = (int) round(
        (float) $payment_draft[
            'total'
        ] * 100
    );

    $session_amount = (int) (
        $checkout_session->amount_total
        ?? -1
    );

    if (
        $expected_amount <= 0 ||
        $session_amount !==
            $expected_amount
    ) {
        throw new RuntimeException(
            'Stripe Checkout Session amount mismatch.'
        );
    }

    $expected_currency = strtolower(
        (string) $payment_draft[
            'currency'
        ]
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

    $payment_status = (string) (
        $checkout_session->payment_status
        ?? ''
    );

    $session_status = (string) (
        $checkout_session->status
        ?? ''
    );

    $session_expires_at = (int) (
        $checkout_session->expires_at
        ?? 0
    );

    if ($payment_status === 'paid') {
        redirect_to(
            app_path(
                'customer/payment_success.php' .
                '?session_id=' .
                urlencode(
                    $stripe_session_id
                )
            )
        );
    }

    if (
        $session_status === 'open' &&
        $payment_status === 'unpaid' &&
        $session_expires_at > time()
    ) {
        $checkout_url = trim(
            (string) (
                $checkout_session->url
                ?? ''
            )
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

        $payment_intent_id =
            is_string(
                $checkout_session
                    ->payment_intent
                ?? null
            )
                ? $checkout_session
                    ->payment_intent
                : null;

        try {
            attachStripeCheckoutSession(
                $pdo,
                $payment_draft_id,
                $user_id,
                $stripe_session_id,
                $checkout_url,
                $session_expires_at,
                $payment_intent_id
            );
        } catch (Throwable $e) {
            app_error_log(
                'Stripe resume draft update failed: ' .
                $e->getMessage()
            );

            redirect_to(
                app_path(
                    'customer/orders.php' .
                    '?payment_resume_error=1'
                )
            );
        }

        $_SESSION['stripe_session_id'] =
            $stripe_session_id;

        $_SESSION['stripe_checkout_url'] =
            $checkout_url;

        $_SESSION['stripe_expires_at'] =
            $session_expires_at;

        header(
            'Location: ' .
            $checkout_url
        );

        exit;
    }

    expirePaymentDraft(
        $pdo,
        $payment_draft_id,
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
    app_error_log(
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
    app_error_log(
        'Payment resume validation failed: ' .
        $e->getMessage()
    );

    try {
        expirePaymentDraft(
            $pdo,
            $payment_draft_id,
            $user_id
        );
    } catch (Throwable $cleanupError) {
        app_error_log(
            'Invalid payment draft cleanup failed: ' .
            $cleanupError->getMessage()
        );
    }

    clearResumeSessionState();

    redirect_to(
        app_path(
            'customer/cart.php' .
            '?payment_invalid=1'
        )
    );
}