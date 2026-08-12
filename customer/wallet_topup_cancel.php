<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';

$topup_id = filter_input(
    INPUT_GET,
    'topup_id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if (
    $topup_id === false ||
    $topup_id === null
) {
    redirect_to(
        app_path('customer/wallet.php')
    );
}

$statement = $pdo->prepare("
    SELECT wallet_topup_id
    FROM wallet_topups
    WHERE wallet_topup_id = ?
    AND wallet_topup_user_id = ?
    LIMIT 1
");
$statement->execute([
    (int) $topup_id,
    current_user_id(),
]);

if ($statement->fetchColumn() === false) {
    redirect_to(
        app_path('customer/wallet.php')
    );
}

/*
 * Stripe cancel_url is a GET callback, so it must not mutate wallet state.
 * The Checkout Session will either complete through the verified success /
 * webhook path or become expired through Stripe's signed webhook event.
 */
redirect_to(
    app_path(
        'customer/wallet.php?topup_cancelled=1'
    )
);
