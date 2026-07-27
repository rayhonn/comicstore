<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/stripe_config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/money_helper.php';
require_once __DIR__ .
    '/../includes/payment_draft_helper.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function respondWithJson(
    int $statusCode,
    array $payload
): never {
    http_response_code($statusCode);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function clearPendingCheckoutSession(): void
{
    unset($_SESSION['pending_order']);
    unset($_SESSION['payment_draft_id']);
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
            'message' =>
                'Method not allowed.',
        ]
    );
}

csrf_verify();

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
    clearPendingCheckoutSession();

    respondWithJson(
        200,
        [
            'success' => true,
        ]
    );
}

$payment_draft_id =
    (int) $payment_draft[
        'payment_draft_id'
    ];

$status = (string) $payment_draft[
    'status'
];

if (
    in_array(
        $status,
        [
            'paid',
            'completed',
        ],
        true
    ) ||
    $payment_draft['order_id'] !== null
) {
    respondWithJson(
        409,
        [
            'success' => false,
            'message' =>
                'Payment has already been completed.',
        ]
    );
}

if (
    !in_array(
        $status,
        [
            'pending',
            'checkout_open',
        ],
        true
    )
) {
    clearPendingCheckoutSession();

    respondWithJson(
        200,
        [
            'success' => true,
        ]
    );
}

$stripe_session_id = trim(
    (string) (
        $payment_draft[
            'stripe_session_id'
        ] ?? ''
    )
);

if (
    $stripe_session_id !== '' &&
    (
        strlen($stripe_session_id) > 255 ||
        !preg_match(
            '/\Acs_[A-Za-z0-9_]+\z/',
            $stripe_session_id
        )
    )
) {
    app_error_log(
        'Invalid Stripe Session ID stored for payment draft: ' .
        $payment_draft_id
    );

    try {
        cancelPaymentDraft(
            $pdo,
            $payment_draft_id,
            $user_id
        );
    } catch (Throwable $e) {
        app_error_log(
            'Invalid payment draft cancellation failed: ' .
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
        200,
        [
            'success' => true,
        ]
    );
}

if ($stripe_session_id !== '') {
    try {
        $stripe = new \Stripe\StripeClient(
            STRIPE_SECRET_KEY
        );

        $checkout_session =
            $stripe
                ->checkout
                ->sessions
                ->retrieve(
                    $stripe_session_id,
                    []
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
            app_error_log(
                'Stripe cancellation ownership mismatch for payment draft: ' .
                $payment_draft_id
            );

            respondWithJson(
                403,
                [
                    'success' => false,
                    'message' =>
                        'The Stripe payment session does not belong to this checkout.',
                ]
            );
        }

        $expected_amount = moneyDecimalToSen(
            (string) $payment_draft[
                'total'
            ]
        );

        $session_amount = (int) (
            $checkout_session->amount_total
            ?? -1
        );

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
            $expected_amount <= 0 ||
            $session_amount !==
                $expected_amount ||
            $session_currency !==
                $expected_currency
        ) {
            throw new RuntimeException(
                'Stripe Checkout Session payment details mismatch.'
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
                        'Payment has already been completed.',
                ]
            );
        }

        if ($session_status === 'open') {
            $expired_session =
                $stripe
                    ->checkout
                    ->sessions
                    ->expire(
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
        app_error_log(
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
        app_error_log(
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
    cancelPaymentDraft(
        $pdo,
        $payment_draft_id,
        $user_id
    );
} catch (PaymentDraftException $e) {
    respondWithJson(
        409,
        [
            'success' => false,
            'message' =>
                $e->getMessage(),
        ]
    );
} catch (Throwable $e) {
    app_error_log(
        'Payment draft cancellation failed: ' .
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
    200,
    [
        'success' => true,
    ]
);