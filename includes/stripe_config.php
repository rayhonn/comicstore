<?php
require_once __DIR__ . '/config.php';

if (!defined('STRIPE_SECRET_KEY')) {
    error_log('[Stripe] STRIPE_SECRET_KEY not set in .env');
    http_response_code(500);
    die("Payment configuration error.");
}