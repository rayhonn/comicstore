<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/stripe_config.php';
require_once __DIR__ .
    '/includes/payment_finalization_helper.php';
require_once __DIR__ .
    '/includes/wallet_topup_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

/**
 * Return a JSON response to Stripe.
 */
function stripeWebhookRespond(
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    stripeWebhookRespond(
        405,
        [
            'received' => false,
            'message' => 'Method not allowed.',
        ]
    );
}

$payload = file_get_contents('php://input');

if (
    !is_string($payload) ||
    $payload === '' ||
    strlen($payload) > 1048576
) {
    app_log(
        'WARNING',
        'Stripe Webhook',
        'Stripe webhook payload was empty or too large.'
    );

    stripeWebhookRespond(
        400,
        [
            'received' => false,
            'message' => 'Invalid payload.',
        ]
    );
}

$signature = trim(
    (string) (
        $_SERVER['HTTP_STRIPE_SIGNATURE']
        ?? ''
    )
);

if ($signature === '') {
    app_log(
        'WARNING',
        'Stripe Webhook',
        'Stripe-Signature header was missing.'
    );

    stripeWebhookRespond(
        400,
        [
            'received' => false,
            'message' => 'Invalid signature.',
        ]
    );
}

try {
    $webhookSecret = stripeWebhookSecret();
} catch (Throwable $e) {
    stripeWebhookRespond(
        500,
        [
            'received' => false,
            'message' => 'Webhook configuration error.',
        ]
    );
}

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload,
        $signature,
        $webhookSecret
    );
} catch (UnexpectedValueException $e) {
    app_log(
        'WARNING',
        'Stripe Webhook',
        'Stripe webhook payload could not be parsed.'
    );

    stripeWebhookRespond(
        400,
        [
            'received' => false,
            'message' => 'Invalid payload.',
        ]
    );
} catch (
    \Stripe\Exception\SignatureVerificationException $e
) {
    app_log(
        'WARNING',
        'Stripe Webhook',
        'Stripe webhook signature verification failed.'
    );

    stripeWebhookRespond(
        400,
        [
            'received' => false,
            'message' => 'Invalid signature.',
        ]
    );
}

$eventId = trim(
    (string) ($event->id ?? '')
);

$eventType = trim(
    (string) ($event->type ?? '')
);

if (
    in_array(
        $eventType,
        [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
        ],
        true
    )
) {
    $checkoutSession = $event->data->object;

    $sessionId = trim(
        (string) (
            $checkoutSession->id
            ?? ''
        )
    );

    $paymentStatus = trim(
        (string) (
            $checkoutSession->payment_status
            ?? ''
        )
    );

    if (
        $eventType ===
            'checkout.session.completed' &&
        $paymentStatus !== 'paid'
    ) {
        app_log(
            'INFO',
            'Stripe Webhook',
            'Stripe Checkout Session completed before payment succeeded.',
            [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'stripe_session_id' => $sessionId,
                'payment_status' => $paymentStatus,
            ]
        );

        stripeWebhookRespond(
            200,
            [
                'received' => true,
            ]
        );
    }

    $checkoutType = trim(
        (string) (
            $checkoutSession
                ->metadata
                ->checkout_type
            ?? ''
        )
    );

    try {
        if ($checkoutType === 'wallet_topup') {
            $result =
                fulfillWalletTopupStripeSession(
                    $pdo,
                    $sessionId,
                    null
                );

            app_log(
                'INFO',
                'Stripe Webhook',
                'Wallet top-up checkout event was processed.',
                [
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'stripe_session_id' => $sessionId,
                    'wallet_topup_id' =>
                        (int) $result['topup_id'],
                    'wallet_credited' =>
                        (bool) $result['created'],
                ]
            );
        } else {
            $result =
                fulfillStripeCheckoutSession(
                    $pdo,
                    $sessionId,
                    null
                );

            app_log(
                'INFO',
                'Stripe Webhook',
                'Stripe checkout event was processed.',
                [
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'stripe_session_id' => $sessionId,
                    'order_id' =>
                        (int) $result['order_id'],
                    'order_created' =>
                        (bool) $result['created'],
                ]
            );
        }
    } catch (
        \Stripe\Exception\ApiErrorException $e
    ) {
        app_log_exception(
            'Stripe Webhook',
            $e,
            [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'stripe_session_id' => $sessionId,
            ]
        );

        stripeWebhookRespond(
            500,
            [
                'received' => false,
                'message' => 'Webhook processing failed.',
            ]
        );
    } catch (Throwable $e) {
        app_log_exception(
            'Stripe Webhook',
            $e,
            [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'stripe_session_id' => $sessionId,
            ]
        );

        stripeWebhookRespond(
            500,
            [
                'received' => false,
                'message' => 'Webhook processing failed.',
            ]
        );
    }
}

if ($eventType === 'checkout.session.expired') {
    $checkoutSession =
        $event->data->object;

    $sessionId = trim(
        (string) (
            $checkoutSession->id
            ?? ''
        )
    );

    $checkoutType = trim(
        (string) (
            $checkoutSession
                ->metadata
                ->checkout_type
            ?? ''
        )
    );

    if ($checkoutType === 'wallet_topup') {
        try {
            expireWalletTopupStripeSession(
                $pdo,
                $sessionId
            );

            app_log(
                'INFO',
                'Stripe Webhook',
                'Expired wallet top-up session was recorded.',
                [
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'stripe_session_id' => $sessionId,
                ]
            );
        } catch (Throwable $e) {
            app_log_exception(
                'Stripe Webhook',
                $e,
                [
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'stripe_session_id' => $sessionId,
                ]
            );

            stripeWebhookRespond(
                500,
                [
                    'received' => false,
                    'message' =>
                        'Webhook processing failed.',
                ]
            );
        }
    }
}

stripeWebhookRespond(
    200,
    [
        'received' => true,
    ]
);