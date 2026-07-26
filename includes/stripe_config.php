<?php

require_once __DIR__ . '/config.php';

if (!defined('STRIPE_SECRET_KEY')) {
    app_log(
        'CRITICAL',
        'Stripe',
        'STRIPE_SECRET_KEY is not set in .env.'
    );

    http_response_code(500);

    die('Payment configuration error.');
}

/**
 * Return the Stripe webhook signing secret.
 */
function stripeWebhookSecret(): string
{
    if (!defined('STRIPE_WEBHOOK_SECRET')) {
        app_log(
            'CRITICAL',
            'Stripe Webhook',
            'STRIPE_WEBHOOK_SECRET is not set in .env.'
        );

        throw new RuntimeException(
            'Stripe webhook configuration is missing.'
        );
    }

    $secret = trim(
        (string) STRIPE_WEBHOOK_SECRET
    );

    if (
        strlen($secret) <= strlen('whsec_') ||
        strlen($secret) > 255 ||
        !str_starts_with($secret, 'whsec_')
    ) {
        app_log(
            'CRITICAL',
            'Stripe Webhook',
            'STRIPE_WEBHOOK_SECRET has an invalid format.'
        );

        throw new RuntimeException(
            'Stripe webhook configuration is invalid.'
        );
    }

    return $secret;
}