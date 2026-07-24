<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/stripe_config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/voucher_helper.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function respondWithJson(
    int $status_code,
    array $payload
): never {
    http_response_code($status_code);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function clearPendingCheckoutSession(): void
{
    unset($_SESSION['pending_order']);
    unset($_SESSION['payment_lock']);
    unset($_SESSION['stripe_session_id']);
    unset($_SESSION['stripe_checkout_url']);
    unset($_SESSION['stripe_expires_at']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondWithJson(
        405,
        [
            'success' => false,
            'message' => 'Method not allowed.',
        ]
    );
}

csrf_verify();

$user_id = current_user_id();

$pending_order =
    $_SESSION['pending_order'] ?? null;

if (!is_array($pending_order)) {
    clearPendingCheckoutSession();

    respondWithJson(
        200,
        [
            'success' => true,
        ]
    );
}

if (
    (int) ($pending_order['user_id'] ?? 0)
        !== $user_id
) {
    clearPendingCheckoutSession();

    respondWithJson(
        403,
        [
            'success' => false,
            'message' =>
                'Pending checkout ownership could not be verified.',
        ]
    );
}

$stripe_session_id =
    $_SESSION['stripe_session_id'] ?? '';

if (
    $stripe_session_id !== '' &&
    (
        !is_string($stripe_session_id) ||
        strlen($stripe_session_id) > 255 ||
        !preg_match(
            '/\Acs_[A-Za-z0-9_]+\z/',
            $stripe_session_id
        )
    )
) {
    try {
        $pdo->beginTransaction();

        restorePendingUserVoucher(
            $pdo,
            $pending_order['voucher_id'] ?? null,
            $user_id
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'Invalid checkout cleanup failed: ' .
            $e->getMessage()
        );

        respondWithJson(
            500,
            [
                'success' => false,
                'message' =>
                    'Unable to cancel checkout.',
            ]
        );
    }

    clearPendingCheckoutSession();

    respondWithJson(
        400,
        [
            'success' => false,
            'message' =>
                'The Stripe payment session was invalid and has been cleared.',
        ]
    );
}

if ($stripe_session_id !== '') {
    try {
        $stripe = new \Stripe\StripeClient(
            STRIPE_SECRET_KEY
        );

        $checkout_session =
            $stripe->checkout->sessions->retrieve(
                $stripe_session_id,
                []
            );

        $session_user_id = (string) (
            $checkout_session
                ->client_reference_id
            ?? ''
        );

        if (
            $session_user_id !==
            (string) $user_id
        ) {
            error_log(
                'Stripe cancellation user mismatch for session: ' .
                $stripe_session_id
            );

            respondWithJson(
                403,
                [
                    'success' => false,
                    'message' =>
                        'The Stripe payment session does not belong to this account.',
                ]
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

        if ($payment_status === 'paid') {
            respondWithJson(
                409,
                [
                    'success' => false,
                    'message' =>
                        'Payment has already been completed. Continue the payment process from My Orders.',
                ]
            );
        }

        if ($session_status === 'open') {
            $expired_session =
                $stripe->checkout->sessions->expire(
                    $stripe_session_id,
                    []
                );

            if (
                (string) (
                    $expired_session->status
                    ?? ''
                ) !== 'expired'
            ) {
                throw new RuntimeException(
                    'Stripe Checkout Session was not expired.'
                );
            }
        } elseif (
            $session_status !== 'expired'
        ) {
            respondWithJson(
                409,
                [
                    'success' => false,
                    'message' =>
                        'The Stripe payment session cannot be cancelled while it is being processed.',
                ]
            );
        }
    } catch (
        \Stripe\Exception\ApiErrorException $e
    ) {
        error_log(
            'Stripe Checkout Session cancellation failed: ' .
            $e->getMessage()
        );

        respondWithJson(
            502,
            [
                'success' => false,
                'message' =>
                    'Stripe could not cancel the payment session. Please try again.',
            ]
        );
    } catch (Throwable $e) {
        error_log(
            'Checkout session cancellation failed: ' .
            $e->getMessage()
        );

        respondWithJson(
            500,
            [
                'success' => false,
                'message' =>
                    'Unable to cancel checkout.',
            ]
        );
    }
}

try {
    $pdo->beginTransaction();

    restorePendingUserVoucher(
        $pdo,
        $pending_order['voucher_id'] ?? null,
        $user_id
    );

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Pending checkout voucher restoration failed: ' .
        $e->getMessage()
    );

    respondWithJson(
        500,
        [
            'success' => false,
            'message' =>
                'The payment session was cancelled, but the checkout could not be cleared.',
        ]
    );
}

clearPendingCheckoutSession();

respondWithJson(
    200,
    [
        'success' => true,
    ]
);