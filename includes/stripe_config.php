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