<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

$user_id = current_user_id();

$success = (string) (
    $_SESSION['voucher_success'] ??
    ''
);

$info = (string) (
    $_SESSION['voucher_info'] ??
    ''
);

$error = (string) (
    $_SESSION['voucher_error'] ??
    ''
);

unset(
    $_SESSION['voucher_success'],
    $_SESSION['voucher_info'],
    $_SESSION['voucher_error']
);

function redirect_customer_voucher_page(
    string $message,
    string $message_type,
    string $tab
): void {
    $allowed_message_types = [
        'success',
        'info',
        'error',
    ];

    if (
        !in_array(
            $message_type,
            $allowed_message_types,
            true
        )
    ) {
        $message_type = 'error';
    }

    $allowed_tabs = [
        'promotions',
        'points',
        'myvouchers',
    ];

    if (
        !in_array(
            $tab,
            $allowed_tabs,
            true
        )
    ) {
        $tab = 'promotions';
    }

    $_SESSION[
        'voucher_' . $message_type
    ] = $message;

    header(
        'Location: vouchers.php?tab=' .
        rawurlencode($tab)
    );

    exit;
}

function voucherOfferLabel(
    array $voucher
): string {
    if (
        ($voucher['voucher_type'] ?? '') ===
        'percentage'
    ) {
        $value = rtrim(
            rtrim(
                number_format(
                    (float) (
                        $voucher['voucher_value'] ??
                        0
                    ),
                    2,
                    '.',
                    ''
                ),
                '0'
            ),
            '.'
        );

        return $value . '% OFF';
    }

    return 'RM ' .
        number_format(
            (float) (
                $voucher['voucher_value'] ??
                0
            ),
            2
        ) .
        ' OFF';
}

function voucherEffectiveExpiryTimestamp(
    array $voucher
): ?int {
    $voucher_expiry = null;
    $user_expiry = null;

    if (
        !empty(
            $voucher['voucher_end_date']
        )
    ) {
        $parsed = strtotime(
            (string) $voucher[
                'voucher_end_date'
            ]
        );

        if ($parsed !== false) {
            $voucher_expiry = $parsed;
        }
    }

    if (
        !empty(
            $voucher['uv_expires_at']
        )
    ) {
        $parsed = strtotime(
            (string) $voucher[
                'uv_expires_at'
            ]
        );

        if ($parsed !== false) {
            $user_expiry = $parsed;
        }
    }

    if (
        $voucher_expiry !== null &&
        $user_expiry !== null
    ) {
        return min(
            $voucher_expiry,
            $user_expiry
        );
    }

    return $user_expiry ??
        $voucher_expiry;
}

// Get user points
$user = $pdo->prepare("
    SELECT
        user_points,
        user_first_name
    FROM users
    WHERE user_id = ?
");

$user->execute([
    $user_id,
]);

$user = $user->fetch(
    PDO::FETCH_ASSOC
);

$user_points = (int) (
    $user['user_points'] ??
    0
);

// Handle points voucher redemption
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['redeem_voucher'])
) {
    csrf_verify();

    $voucher_id = filter_var(
        $_POST['voucher_id'] ?? null,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if ($voucher_id === false) {
        redirect_customer_voucher_page(
            'Invalid voucher.',
            'error',
            'points'
        );
    }

    try {
        $pdo->beginTransaction();

        $voucher_stmt = $pdo->prepare("
            SELECT
                voucher_id,
                voucher_code,
                voucher_points_required
            FROM vouchers
            WHERE voucher_id = ?
            AND voucher_is_active = 1
            AND voucher_is_points_redeem = 1
            AND (
                voucher_start_date IS NULL
                OR voucher_start_date <= NOW()
            )
            AND (
                voucher_end_date IS NULL
                OR voucher_end_date >= NOW()
            )
            AND (
                voucher_usage_limit IS NULL
                OR voucher_used_count <
                    voucher_usage_limit
            )
            LIMIT 1
            FOR UPDATE
        ");

        $voucher_stmt->execute([
            $voucher_id,
        ]);

        $voucher = $voucher_stmt->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$voucher) {
            throw new RuntimeException(
                'Voucher not found or no longer available.'
            );
        }

        $points_required = max(
            0,
            (int) $voucher[
                'voucher_points_required'
            ]
        );

        if ($points_required <= 0) {
            throw new RuntimeException(
                'This voucher cannot be redeemed with points.'
            );
        }

        $user_lock = $pdo->prepare("
            SELECT user_points
            FROM users
            WHERE user_id = ?
            FOR UPDATE
        ");

        $user_lock->execute([
            $user_id,
        ]);

        $locked_user = $user_lock->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$locked_user) {
            throw new RuntimeException(
                'User account not found.'
            );
        }

        $claimed_stmt = $pdo->prepare("
            SELECT uv_id
            FROM user_vouchers
            WHERE uv_user_id = ?
            AND uv_voucher_id = ?
            LIMIT 1
            FOR UPDATE
        ");

        $claimed_stmt->execute([
            $user_id,
            $voucher_id,
        ]);

        if (
            $claimed_stmt->fetchColumn()
        ) {
            $pdo->rollBack();

            redirect_customer_voucher_page(
                'This voucher is already in My Vouchers. No points were deducted.',
                'info',
                'myvouchers'
            );
        }

        $deduct_points = $pdo->prepare("
            UPDATE users
            SET user_points =
                user_points - ?
            WHERE user_id = ?
            AND user_points >= ?
        ");

        $deduct_points->execute([
            $points_required,
            $user_id,
            $points_required,
        ]);

        if (
            $deduct_points->rowCount() !==
            1
        ) {
            throw new RuntimeException(
                'Insufficient points.'
            );
        }

        $add_voucher = $pdo->prepare("
            INSERT INTO user_vouchers (
                uv_user_id,
                uv_voucher_id
            )
            VALUES (?, ?)
        ");

        $add_voucher->execute([
            $user_id,
            $voucher_id,
        ]);

        $log_points = $pdo->prepare("
            INSERT INTO points_log (
                log_user_id,
                log_points,
                log_type,
                log_description
            )
            VALUES (
                ?,
                ?,
                'redeem',
                ?
            )
        ");

        $log_points->execute([
            $user_id,
            -$points_required,
            'Redeemed voucher: ' .
                $voucher['voucher_code'],
        ]);

        $pdo->commit();

        redirect_customer_voucher_page(
            'Voucher ' .
                $voucher['voucher_code'] .
                ' was redeemed successfully and added to My Vouchers.',
            'success',
            'myvouchers'
        );
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        app_error_log(
            'Points voucher redemption database error for user ' .
            $user_id .
            ': ' .
            $e->getMessage()
        );

        redirect_customer_voucher_page(
            'Unable to redeem the voucher. Please try again.',
            'error',
            'points'
        );
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        redirect_customer_voucher_page(
            $e->getMessage(),
            'error',
            'points'
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        app_error_log(
            'Points voucher redemption failed for user ' .
            $user_id .
            ': ' .
            $e->getMessage()
        );

        redirect_customer_voucher_page(
            'Unable to redeem the voucher. Please try again.',
            'error',
            'points'
        );
    }
}

// Get available points vouchers
$points_vouchers = $pdo->query("
    SELECT
        v.*,
        (
            SELECT uv_id
            FROM user_vouchers
            WHERE uv_user_id = $user_id
            AND uv_voucher_id =
                v.voucher_id
        ) AS already_claimed
    FROM vouchers v
    WHERE v.voucher_is_active = 1
    AND v.voucher_is_points_redeem = 1
    AND (
        v.voucher_start_date IS NULL
        OR v.voucher_start_date <= NOW()
    )
    AND (
        v.voucher_end_date IS NULL
        OR v.voucher_end_date >= NOW()
    )
    AND (
        v.voucher_usage_limit IS NULL
        OR v.voucher_used_count <
            v.voucher_usage_limit
    )
    ORDER BY
        v.voucher_points_required ASC,
        v.voucher_created_at DESC,
        v.voucher_code ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Get my vouchers — points redeemed + used promo vouchers
$my_vouchers = $pdo->prepare("
    SELECT
        v.*,
        uv.uv_claimed_at,
        uv.uv_is_used,
        uv.uv_used_at,
        uv.uv_expires_at,
        uv.uv_status
    FROM user_vouchers uv
    JOIN vouchers v
        ON uv.uv_voucher_id =
            v.voucher_id
    WHERE uv.uv_user_id = ?

    UNION

    SELECT
        v.*,
        vu.usage_created_at AS uv_claimed_at,
        1 AS uv_is_used,
        vu.usage_created_at AS uv_used_at,
        NULL AS uv_expires_at,
        'used' AS uv_status
    FROM voucher_usage vu
    JOIN vouchers v
        ON vu.usage_voucher_id =
            v.voucher_id
    WHERE vu.usage_user_id = ?
    AND NOT EXISTS (
        SELECT 1
        FROM user_vouchers uv2
        WHERE uv2.uv_user_id = ?
        AND uv2.uv_voucher_id =
            v.voucher_id
    )

    ORDER BY uv_claimed_at DESC
");

$my_vouchers->execute([
    $user_id,
    $user_id,
    $user_id,
]);

$my_vouchers = $my_vouchers->fetchAll(
    PDO::FETCH_ASSOC
);

// Get public General Promotion vouchers
$promo_vouchers = $pdo->prepare("
    SELECT v.*
    FROM vouchers v
    WHERE v.voucher_is_active = 1
    AND v.voucher_is_points_redeem = 0
    AND v.voucher_is_system_generated = 0
    AND v.voucher_is_birthday_template = 0
    AND (
        v.voucher_usage_limit IS NULL
        OR v.voucher_used_count <
            v.voucher_usage_limit
    )
    AND (
        v.voucher_start_date IS NULL
        OR v.voucher_start_date <= NOW()
    )
    AND (
        v.voucher_end_date IS NULL
        OR v.voucher_end_date >= NOW()
    )
    AND NOT EXISTS (
        SELECT 1
        FROM user_vouchers uv_any
        WHERE uv_any.uv_voucher_id =
            v.voucher_id
    )
    AND NOT EXISTS (
        SELECT 1
        FROM voucher_usage vu
        WHERE vu.usage_voucher_id =
            v.voucher_id
        AND vu.usage_user_id = ?
    )
    ORDER BY
        v.voucher_created_at DESC,
        v.voucher_code ASC
");

$promo_vouchers->execute([
    $user_id,
]);

$promo_vouchers =
    $promo_vouchers->fetchAll(
        PDO::FETCH_ASSOC
    );

// Points history
$points_history = $pdo->prepare("
    SELECT *
    FROM points_log
    WHERE log_user_id = ?
    ORDER BY log_created_at DESC
    LIMIT 20
");

$points_history->execute([
    $user_id,
]);

$points_history =
    $points_history->fetchAll(
        PDO::FETCH_ASSOC
    );

$available_count = count(
    array_filter(
        $my_vouchers,
        static function (
            array $voucher
        ): bool {
            $expiry =
                voucherEffectiveExpiryTimestamp(
                    $voucher
                );

            $is_expired =
                $expiry !== null &&
                $expiry < time();

            $is_pending =
                (
                    $voucher['uv_status'] ??
                    'available'
                ) === 'pending';

            return
                !(bool) (
                    $voucher['uv_is_used'] ??
                    false
                ) &&
                !$is_expired &&
                !$is_pending;
        }
    )
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Vouchers & Points - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body {
            opacity: 0;
            animation: fadeIn 0.4s ease forwards;
        }

        @keyframes fadeIn {
            to {
                opacity: 1;
            }
        }

        .voucher-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            position: relative;
        }

        .voucher-card::before {
            content: '';
            position: absolute;
            left: -12px;
            top: 50%;
            transform: translateY(-50%);
            width: 24px;
            height: 24px;
            background: #F5F0EB;
            border-radius: 50%;
        }

        .voucher-card::after {
            content: '';
            position: absolute;
            right: -12px;
            top: 50%;
            transform: translateY(-50%);
            width: 24px;
            height: 24px;
            background: #F5F0EB;
            border-radius: 50%;
        }

        .voucher-divider {
            border-left: 2px dashed #e5e7eb;
        }
    </style>
</head>

<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">
        <p class="text-sm text-gray-400 mb-6">
            <a
                href="../index.php"
                class="hover:text-red-600 transition-colors"
            >
                Home
            </a>

            <span class="mx-2">›</span>

            <a
                href="dashboard.php"
                class="hover:text-red-600 transition-colors"
            >
                My Account
            </a>

            <span class="mx-2">›</span>

            <span class="text-gray-600">
                Vouchers & Points
            </span>
        </p>

        <div class="flex gap-8 items-start">
            <?php include '../includes/customer_sidebar.php'; ?>

            <div class="flex-1 min-w-0 space-y-6">

                <?php if ($success): ?>
                <div
                    class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"
                >
                    ✅
                    <?= htmlspecialchars(
                        $success,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </div>
                <?php endif; ?>

                <?php if ($info): ?>
                <div
                    class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700"
                >
                    ℹ️
                    <?= htmlspecialchars(
                        $info,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div
                    class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600"
                >
                    ❌
                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </div>
                <?php endif; ?>

                <!-- Points Card -->
                <div
                    class="bg-gradient-to-r from-[#1e2d4a] to-[#2c3e6b] rounded-2xl p-6 text-white"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">
                                Your Points Balance
                            </p>

                            <p class="text-5xl font-black">
                                <?= number_format(
                                    $user_points
                                ) ?>
                            </p>

                            <p class="text-white/50 text-xs mt-1">
                                RM1 spent = 1 point
                            </p>
                        </div>

                        <div class="text-right">
                            <div class="text-6xl">
                                ⭐
                            </div>

                            <p class="text-white/50 text-xs mt-2">
                                Earn points with every purchase
                            </p>
                        </div>
                    </div>

                    <?php if (
                        !empty($points_history)
                    ): ?>
                    <div
                        class="mt-5 pt-5 border-t border-white/20"
                    >
                        <div
                            class="flex justify-between items-center mb-3"
                        >
                            <p
                                class="text-white/60 text-xs font-semibold uppercase tracking-wide"
                            >
                                Recent Points Activity
                            </p>

                            <?php if (
                                count($points_history) >
                                3
                            ): ?>
                            <button
                                type="button"
                                onclick="togglePointsHistory()"
                                id="pointsToggleBtn"
                                class="text-white/50 hover:text-white text-xs transition-colors"
                            >
                                View All ↓
                            </button>
                            <?php endif; ?>
                        </div>

                        <div
                            class="space-y-2"
                            id="pointsHistoryShort"
                        >
                            <?php foreach (
                                array_slice(
                                    $points_history,
                                    0,
                                    3
                                ) as $log
                            ): ?>
                            <div
                                class="flex justify-between items-center"
                            >
                                <p
                                    class="text-white/80 text-xs"
                                >
                                    <?= htmlspecialchars(
                                        (string) $log[
                                            'log_description'
                                        ],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>

                                <span
                                    class="text-xs font-bold <?= (int) $log['log_points'] > 0 ? 'text-green-400' : 'text-red-400' ?>"
                                >
                                    <?= (int) $log[
                                        'log_points'
                                    ] > 0
                                        ? '+'
                                        : '' ?>
                                    <?= (int) $log[
                                        'log_points'
                                    ] ?>
                                    pts
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (
                            count($points_history) >
                            3
                        ): ?>
                        <div
                            class="space-y-2 hidden"
                            id="pointsHistoryFull"
                        >
                            <?php foreach (
                                $points_history as
                                $log
                            ): ?>
                            <div
                                class="flex justify-between items-center"
                            >
                                <div
                                    class="flex-1 min-w-0 mr-3"
                                >
                                    <p
                                        class="text-white/80 text-xs truncate"
                                    >
                                        <?= htmlspecialchars(
                                            (string) $log[
                                                'log_description'
                                            ],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </p>

                                    <p
                                        class="text-white/40 text-xs"
                                    >
                                        <?= date(
                                            'd M Y',
                                            strtotime(
                                                (string) $log[
                                                    'log_created_at'
                                                ]
                                            )
                                        ) ?>
                                    </p>
                                </div>

                                <span
                                    class="text-xs font-bold flex-shrink-0 <?= (int) $log['log_points'] > 0 ? 'text-green-400' : 'text-red-400' ?>"
                                >
                                    <?= (int) $log[
                                        'log_points'
                                    ] > 0
                                        ? '+'
                                        : '' ?>
                                    <?= (int) $log[
                                        'log_points'
                                    ] ?>
                                    pts
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Tabs -->
                <div
                    class="flex w-fit flex-wrap gap-1 rounded-2xl bg-white p-1 shadow-sm"
                >
                    <button
                        type="button"
                        onclick="switchTab('promotions')"
                        id="tab-promotions"
                        class="rounded-xl bg-red-600 px-5 py-2 text-sm font-semibold text-white transition-colors"
                    >
                        🎟️ Promotions
                        <?= count(
                            $promo_vouchers
                        ) > 0
                            ? '(' .
                                count(
                                    $promo_vouchers
                                ) .
                                ')'
                            : '' ?>
                    </button>

                    <button
                        type="button"
                        onclick="switchTab('points')"
                        id="tab-points"
                        class="rounded-xl px-5 py-2 text-sm font-semibold text-gray-500 transition-colors hover:text-red-600"
                    >
                        ⭐ Redeem Points
                    </button>

                    <button
                        type="button"
                        onclick="switchTab('myvouchers')"
                        id="tab-myvouchers"
                        class="rounded-xl px-5 py-2 text-sm font-semibold text-gray-500 transition-colors hover:text-red-600"
                    >
                        💼 My Vouchers
                        <?= $available_count > 0
                            ? '(' .
                                $available_count .
                                ')'
                            : '' ?>
                    </button>
                </div>

                <!-- General Promotions Tab -->
                <div id="content-promotions">
                    <div
                        class="mb-5 flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 p-4"
                    >
                        <span class="text-2xl">
                            🎟️
                        </span>

                        <div>
                            <p
                                class="text-sm font-semibold text-red-800"
                            >
                                Available MangaVault Promotions
                            </p>

                            <p
                                class="text-xs text-red-600"
                            >
                                Add products to your cart, select them, and choose one of these vouchers on the Checkout page.
                            </p>
                        </div>
                    </div>

                    <?php if (
                        empty($promo_vouchers)
                    ): ?>
                    <div
                        class="rounded-2xl bg-white p-12 text-center shadow-sm"
                    >
                        <div class="mb-4 text-5xl">
                            🎟️
                        </div>

                        <p
                            class="font-medium text-gray-500"
                        >
                            No promotions available
                        </p>

                        <p
                            class="mt-1 text-sm text-gray-400"
                        >
                            New promotional vouchers will appear here automatically.
                        </p>
                    </div>
                    <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach (
                            $promo_vouchers as
                            $voucher
                        ): ?>
                        <?php
                        $promotion_expiry =
                            voucherEffectiveExpiryTimestamp(
                                $voucher
                            );

                        $remaining_usage =
                            $voucher[
                                'voucher_usage_limit'
                            ] === null
                                ? null
                                : max(
                                    0,
                                    (int) $voucher[
                                        'voucher_usage_limit'
                                    ] -
                                    (int) $voucher[
                                        'voucher_used_count'
                                    ]
                                );

                        $offer_label =
                            voucherOfferLabel(
                                $voucher
                            );
                        ?>

                        <div
                            class="voucher-card mx-3 shadow-sm"
                        >
                            <div class="flex">
                                <div
                                    class="w-3 flex-shrink-0 bg-red-600"
                                ></div>

                                <div
                                    class="flex flex-1 items-stretch"
                                >
                                    <div class="flex-1 p-5">
                                        <div
                                            class="mb-2 flex items-start gap-3"
                                        >
                                            <span class="text-2xl">
                                                🎟️
                                            </span>

                                            <div>
                                                <span
                                                    class="mb-1 inline-block rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700"
                                                >
                                                    ADMIN PROMOTION
                                                </span>

                                                <h3
                                                    class="text-lg font-black text-gray-800"
                                                >
                                                    <?= htmlspecialchars(
                                                        $offer_label,
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>
                                                </h3>

                                                <?php if (
                                                    !empty(
                                                        $voucher[
                                                            'voucher_max_discount'
                                                        ]
                                                    )
                                                ): ?>
                                                <p
                                                    class="text-xs text-gray-400"
                                                >
                                                    Maximum discount:
                                                    RM
                                                    <?= number_format(
                                                        (float) $voucher[
                                                            'voucher_max_discount'
                                                        ],
                                                        2
                                                    ) ?>
                                                </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div
                                            class="mt-3 flex flex-wrap items-center gap-4 text-xs text-gray-400"
                                        >
                                            <?php if (
                                                (float) $voucher[
                                                    'voucher_min_order'
                                                ] > 0
                                            ): ?>
                                            <span>
                                                Minimum spend:
                                                RM
                                                <?= number_format(
                                                    (float) $voucher[
                                                        'voucher_min_order'
                                                    ],
                                                    2
                                                ) ?>
                                            </span>
                                            <?php else: ?>
                                            <span>
                                                No minimum spend
                                            </span>
                                            <?php endif; ?>

                                            <?php if (
                                                $promotion_expiry !==
                                                null
                                            ): ?>
                                            <span>
                                                Valid until:
                                                <?= date(
                                                    'd M Y',
                                                    $promotion_expiry
                                                ) ?>
                                            </span>
                                            <?php else: ?>
                                            <span>
                                                No expiry
                                            </span>
                                            <?php endif; ?>

                                            <?php if (
                                                $remaining_usage !==
                                                null
                                            ): ?>
                                            <span>
                                                <?= number_format(
                                                    $remaining_usage
                                                ) ?>
                                                redemptions remaining
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div
                                        class="voucher-divider my-4 w-px"
                                    ></div>

                                    <div
                                        class="flex w-52 flex-col items-center justify-center gap-3 p-4"
                                    >
                                        <p
                                            class="text-xs font-semibold uppercase tracking-wide text-gray-400"
                                        >
                                            Voucher Code
                                        </p>

                                        <p
                                            class="rounded-lg border border-dashed border-red-300 bg-red-50 px-3 py-1.5 text-center font-mono text-sm font-black text-red-700"
                                        >
                                            <?= htmlspecialchars(
                                                (string) $voucher[
                                                    'voucher_code'
                                                ],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>

                                        <p
                                            class="text-center text-xs leading-5 text-gray-400"
                                        >
                                            Add items to your cart, select them, and choose this code at checkout.
                                        </p>

                                        <a
                                            href="cart.php"
                                            class="rounded-xl bg-red-600 px-4 py-2 text-center text-xs font-semibold text-white transition-colors hover:bg-red-700"
                                        >
                                            Go to Cart →
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Points Redeem Tab -->
                <div
                    id="content-points"
                    class="hidden"
                >
                    <div
                        class="mb-5 flex items-center gap-3 rounded-xl border border-blue-200 bg-blue-50 p-4"
                    >
                        <span class="text-2xl">
                            ⭐
                        </span>

                        <div>
                            <p
                                class="text-sm font-semibold text-blue-800"
                            >
                                You have
                                <span
                                    class="font-black text-blue-600"
                                >
                                    <?= number_format(
                                        $user_points
                                    ) ?> points
                                </span>
                            </p>

                            <p
                                class="text-xs text-blue-600"
                            >
                                Redeem your points for exclusive vouchers. Every reward shows its code, offer, conditions and validity before you redeem.
                            </p>
                        </div>
                    </div>

                    <?php if (
                        empty($points_vouchers)
                    ): ?>
                    <div
                        class="rounded-2xl bg-white p-12 text-center shadow-sm"
                    >
                        <div class="mb-4 text-5xl">
                            ⭐
                        </div>

                        <p class="text-gray-500">
                            No points vouchers available
                        </p>
                    </div>
                    <?php else: ?>

                    <div class="space-y-4">
                        <?php foreach (
                            $points_vouchers as
                            $voucher
                        ): ?>
                        <?php
                        $points_required = max(
                            1,
                            (int) $voucher[
                                'voucher_points_required'
                            ]
                        );

                        $already_claimed =
                            !empty(
                                $voucher[
                                    'already_claimed'
                                ]
                            );

                        $can_redeem =
                            $user_points >=
                                $points_required &&
                            !$already_claimed;

                        $voucher_expiry =
                            voucherEffectiveExpiryTimestamp(
                                $voucher
                            );

                        $remaining_usage =
                            $voucher[
                                'voucher_usage_limit'
                            ] === null
                                ? null
                                : max(
                                    0,
                                    (int) $voucher[
                                        'voucher_usage_limit'
                                    ] -
                                    (int) $voucher[
                                        'voucher_used_count'
                                    ]
                                );

                        $offer_label =
                            voucherOfferLabel(
                                $voucher
                            );

                        $progress =
                            min(
                                100,
                                (
                                    $user_points /
                                    $points_required
                                ) * 100
                            );
                        ?>

                        <div
                            class="voucher-card mx-3 shadow-sm <?= !$can_redeem && !$already_claimed ? 'opacity-60' : '' ?>"
                        >
                            <div class="flex">
                                <div
                                    class="w-3 flex-shrink-0 <?= $already_claimed ? 'bg-green-500' : ($can_redeem ? 'bg-yellow-500' : 'bg-gray-300') ?>"
                                ></div>

                                <div
                                    class="flex flex-1 items-stretch"
                                >
                                    <div class="flex-1 p-5">
                                        <div
                                            class="mb-3 flex items-start gap-3"
                                        >
                                            <span class="text-2xl">
                                                ⭐
                                            </span>

                                            <div class="min-w-0">
                                                <span
                                                    class="<?= $already_claimed ? 'bg-green-100 text-green-700' : ($can_redeem ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-500') ?> mb-1 inline-block rounded-full px-2 py-0.5 text-xs font-semibold"
                                                >
                                                    <?= $already_claimed
                                                        ? '✅ CLAIMED'
                                                        : (
                                                            $can_redeem
                                                                ? '✨ REDEEMABLE'
                                                                : '🔒 LOCKED'
                                                        ) ?>
                                                </span>

                                                <p
                                                    class="mb-1 font-mono text-xs font-black tracking-wider text-red-600"
                                                >
                                                    <?= htmlspecialchars(
                                                        (string) $voucher[
                                                            'voucher_code'
                                                        ],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>
                                                </p>

                                                <h3
                                                    class="text-lg font-black text-gray-800"
                                                >
                                                    <?= htmlspecialchars(
                                                        $offer_label,
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>
                                                </h3>

                                                <?php if (
                                                    !empty(
                                                        $voucher[
                                                            'voucher_max_discount'
                                                        ]
                                                    )
                                                ): ?>
                                                <p
                                                    class="text-xs text-gray-400"
                                                >
                                                    Max discount:
                                                    RM
                                                    <?= number_format(
                                                        (float) $voucher[
                                                            'voucher_max_discount'
                                                        ],
                                                        2
                                                    ) ?>
                                                </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div
                                            class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-gray-500"
                                        >
                                            <?php if (
                                                (float) $voucher[
                                                    'voucher_min_order'
                                                ] > 0
                                            ): ?>
                                            <span>
                                                Min spend:
                                                <strong
                                                    class="text-gray-700"
                                                >
                                                    RM
                                                    <?= number_format(
                                                        (float) $voucher[
                                                            'voucher_min_order'
                                                        ],
                                                        2
                                                    ) ?>
                                                </strong>
                                            </span>
                                            <?php else: ?>
                                            <span>
                                                <strong
                                                    class="text-gray-700"
                                                >
                                                    No minimum spend
                                                </strong>
                                            </span>
                                            <?php endif; ?>

                                            <?php if (
                                                $voucher_expiry !==
                                                null
                                            ): ?>
                                            <span>
                                                Valid until:
                                                <strong
                                                    class="text-gray-700"
                                                >
                                                    <?= date(
                                                        'd M Y',
                                                        $voucher_expiry
                                                    ) ?>
                                                </strong>
                                            </span>
                                            <?php else: ?>
                                            <span>
                                                <strong
                                                    class="text-gray-700"
                                                >
                                                    No expiry
                                                </strong>
                                            </span>
                                            <?php endif; ?>

                                            <?php if (
                                                $remaining_usage !==
                                                null
                                            ): ?>
                                            <span>
                                                <strong
                                                    class="text-gray-700"
                                                >
                                                    <?= number_format(
                                                        $remaining_usage
                                                    ) ?>
                                                </strong>
                                                redemptions remaining
                                            </span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (
                                            $already_claimed
                                        ): ?>
                                        <div
                                            class="mt-3 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-xs text-green-700"
                                        >
                                            This reward is already in My Vouchers and is ready to use at checkout while valid.
                                        </div>
                                        <?php elseif (
                                            !$can_redeem
                                        ): ?>
                                        <div class="mt-3">
                                            <div
                                                class="h-1.5 w-full rounded-full bg-gray-200"
                                            >
                                                <div
                                                    class="h-1.5 rounded-full bg-yellow-500 transition-all"
                                                    style="width: <?= $progress ?>%"
                                                ></div>
                                            </div>

                                            <p
                                                class="mt-1 text-xs text-gray-400"
                                            >
                                                <?= number_format(
                                                    $user_points
                                                ) ?>
                                                /
                                                <?= number_format(
                                                    $points_required
                                                ) ?>
                                                pts needed
                                            </p>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <div
                                        class="voucher-divider my-4 w-px"
                                    ></div>

                                    <div
                                        class="flex w-52 flex-col items-center justify-center gap-2 p-4"
                                    >
                                        <p
                                            class="text-xs font-semibold uppercase tracking-wide text-gray-400"
                                        >
                                            Required
                                        </p>

                                        <p
                                            class="text-xl font-black text-yellow-600"
                                        >
                                            <?= number_format(
                                                $points_required
                                            ) ?>
                                        </p>

                                        <p
                                            class="text-xs text-gray-400"
                                        >
                                            points
                                        </p>

                                        <?php if (
                                            $already_claimed
                                        ): ?>
                                        <span
                                            class="rounded-xl bg-green-100 px-3 py-1.5 text-xs font-semibold text-green-700"
                                        >
                                            Claimed ✓
                                        </span>

                                        <a
                                            href="cart.php"
                                            class="mt-1 rounded-xl border border-green-200 bg-white px-4 py-2 text-center text-xs font-semibold text-green-700 transition-colors hover:bg-green-50"
                                        >
                                            Use Voucher →
                                        </a>
                                        <?php elseif (
                                            $can_redeem
                                        ): ?>
                                        <form
                                            method="POST"
                                            data-points-required="<?= $points_required ?>"
                                            data-voucher-code="<?= htmlspecialchars(
                                                (string) $voucher[
                                                    'voucher_code'
                                                ],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                            data-voucher-offer="<?= htmlspecialchars(
                                                $offer_label,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                            onsubmit="return confirmVoucherRedemption(this);"
                                        >
                                            <?php csrf_field(); ?>

                                            <input
                                                type="hidden"
                                                name="redeem_voucher"
                                                value="1"
                                            >

                                            <input
                                                type="hidden"
                                                name="voucher_id"
                                                value="<?= (int) $voucher[
                                                    'voucher_id'
                                                ] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="rounded-xl bg-yellow-500 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-yellow-600 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                Redeem ⭐
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <span
                                            class="rounded-xl bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-400"
                                        >
                                            🔒 Locked
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- My Vouchers Tab -->
                <div
                    id="content-myvouchers"
                    class="hidden"
                >
                    <?php if (
                        empty($my_vouchers)
                    ): ?>
                    <div
                        class="rounded-2xl bg-white p-12 text-center shadow-sm"
                    >
                        <div class="mb-4 text-5xl">
                            💼
                        </div>

                        <p
                            class="font-medium text-gray-500"
                        >
                            No vouchers yet
                        </p>

                        <p
                            class="mt-1 text-sm text-gray-400"
                        >
                            Redeem points or use promo codes to get vouchers!
                        </p>
                    </div>
                    <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach (
                            $my_vouchers as
                            $voucher
                        ): ?>
                        <?php
                        $expiry =
                            voucherEffectiveExpiryTimestamp(
                                $voucher
                            );

                        $is_expired =
                            $expiry !== null &&
                            $expiry < time();

                        $is_used =
                            (bool) (
                                $voucher[
                                    'uv_is_used'
                                ] ??
                                false
                            );

                        $is_pending =
                            (
                                $voucher[
                                    'uv_status'
                                ] ??
                                'available'
                            ) === 'pending';

                        $offer_label =
                            voucherOfferLabel(
                                $voucher
                            );

                        if ($is_used) {
                            $status_label = 'USED';
                            $status_class =
                                'bg-gray-100 text-gray-500';
                            $stripe_class =
                                'bg-gray-400';
                        } elseif ($is_pending) {
                            $status_label =
                                '⏳ PENDING';
                            $status_class =
                                'bg-yellow-100 text-yellow-700';
                            $stripe_class =
                                'bg-yellow-400';
                        } elseif ($is_expired) {
                            $status_label =
                                'EXPIRED';
                            $status_class =
                                'bg-red-100 text-red-500';
                            $stripe_class =
                                'bg-gray-300';
                        } else {
                            $status_label =
                                'AVAILABLE';
                            $status_class =
                                'bg-green-100 text-green-700';
                            $stripe_class =
                                'bg-green-500';
                        }
                        ?>

                        <div
                            class="voucher-card mx-3 shadow-sm <?= $is_expired || $is_used || $is_pending ? 'opacity-60' : '' ?>"
                        >
                            <div class="flex">
                                <div
                                    class="w-3 flex-shrink-0 <?= $stripe_class ?>"
                                ></div>

                                <div
                                    class="flex flex-1 items-stretch"
                                >
                                    <div class="flex-1 p-5">
                                        <div
                                            class="mb-2 flex items-start gap-2"
                                        >
                                            <div>
                                                <span
                                                    class="<?= $status_class ?> mb-1 inline-block rounded-full px-2 py-0.5 text-xs font-semibold"
                                                >
                                                    <?= htmlspecialchars(
                                                        $status_label,
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>
                                                </span>

                                                <p
                                                    class="mb-1 font-mono text-xs font-black tracking-wider text-red-600"
                                                >
                                                    <?= htmlspecialchars(
                                                        (string) $voucher[
                                                            'voucher_code'
                                                        ],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>
                                                </p>

                                                <h3
                                                    class="text-lg font-black text-gray-800"
                                                >
                                                    <?= htmlspecialchars(
                                                        $offer_label,
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>
                                                </h3>

                                                <?php if (
                                                    !empty(
                                                        $voucher[
                                                            'voucher_max_discount'
                                                        ]
                                                    )
                                                ): ?>
                                                <p
                                                    class="text-xs text-gray-400"
                                                >
                                                    Max discount:
                                                    RM
                                                    <?= number_format(
                                                        (float) $voucher[
                                                            'voucher_max_discount'
                                                        ],
                                                        2
                                                    ) ?>
                                                </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div
                                            class="mt-2 flex flex-wrap items-center gap-4 text-xs text-gray-400"
                                        >
                                            <?php if (
                                                (float) $voucher[
                                                    'voucher_min_order'
                                                ] > 0
                                            ): ?>
                                            <span>
                                                Min spend:
                                                RM
                                                <?= number_format(
                                                    (float) $voucher[
                                                        'voucher_min_order'
                                                    ],
                                                    2
                                                ) ?>
                                            </span>
                                            <?php else: ?>
                                            <span>
                                                No minimum spend
                                            </span>
                                            <?php endif; ?>

                                            <span>
                                                Claimed:
                                                <?= date(
                                                    'd M Y',
                                                    strtotime(
                                                        (string) $voucher[
                                                            'uv_claimed_at'
                                                        ]
                                                    )
                                                ) ?>
                                            </span>

                                            <?php if (
                                                $expiry !== null
                                            ): ?>
                                            <span
                                                class="<?= $expiry < time() ? 'text-red-500' : '' ?>"
                                            >
                                                Valid until:
                                                <?= date(
                                                    'd M Y',
                                                    $expiry
                                                ) ?>
                                            </span>
                                            <?php else: ?>
                                            <span>
                                                No expiry
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div
                                        class="voucher-divider my-4 w-px"
                                    ></div>

                                    <div
                                        class="flex w-48 flex-col items-center justify-center gap-2 p-4"
                                    >
                                        <p
                                            class="text-xs font-semibold uppercase tracking-wide text-gray-400"
                                        >
                                            Code
                                        </p>

                                        <p
                                            class="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-3 py-1.5 text-center font-mono text-sm font-black text-gray-800"
                                        >
                                            <?= htmlspecialchars(
                                                (string) $voucher[
                                                    'voucher_code'
                                                ],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>

                                        <?php if (
                                            !$is_used &&
                                            !$is_expired &&
                                            !$is_pending
                                        ): ?>
                                        <a
                                            href="cart.php"
                                            class="rounded-xl bg-red-600 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-red-700"
                                        >
                                            Go to Cart →
                                        </a>
                                        <?php elseif (
                                            $is_pending
                                        ): ?>
                                        <span
                                            class="text-center text-xs font-semibold text-yellow-600"
                                        >
                                            ⏳ In Use
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <!-- Voucher Redemption Confirmation Modal -->
    <div
        id="voucherConfirmModal"
        class="fixed inset-0 z-[100] hidden items-center justify-center bg-black/50 px-4 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        aria-labelledby="voucherConfirmTitle"
    >
        <div
            class="w-full max-w-md overflow-hidden rounded-3xl bg-white shadow-2xl"
        >
            <div
                class="bg-gradient-to-r from-red-600 to-red-700 px-6 py-5 text-white"
            >
                <div class="flex items-center gap-4">
                    <div
                        class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-2xl bg-white/20 text-2xl"
                    >
                        ⭐
                    </div>

                    <div>
                        <h2
                            id="voucherConfirmTitle"
                            class="text-xl font-black"
                        >
                            Confirm Redemption
                        </h2>

                        <p
                            class="mt-1 text-sm text-white/80"
                        >
                            Please review before redeeming this voucher.
                        </p>
                    </div>
                </div>
            </div>

            <div class="space-y-5 p-6">
                <div
                    class="rounded-2xl border border-gray-200 bg-gray-50 p-4"
                >
                    <div
                        class="flex items-center justify-between gap-4 border-b border-gray-200 pb-3"
                    >
                        <span
                            class="text-sm text-gray-500"
                        >
                            Voucher Code
                        </span>

                        <span
                            id="voucherConfirmCode"
                            class="rounded-lg border border-dashed border-red-300 bg-red-50 px-3 py-1 font-mono text-sm font-black tracking-[0.12em] text-red-700"
                        >
                            -
                        </span>
                    </div>

                    <div
                        class="flex items-center justify-between gap-4 border-b border-gray-200 py-3"
                    >
                        <span
                            class="text-sm text-gray-500"
                        >
                            Reward
                        </span>

                        <span
                            id="voucherConfirmOffer"
                            class="text-sm font-black text-gray-800"
                        >
                            -
                        </span>
                    </div>

                    <div
                        class="flex items-center justify-between gap-4 pt-3"
                    >
                        <span
                            class="text-sm text-gray-500"
                        >
                            Points to Deduct
                        </span>

                        <span
                            id="voucherConfirmPoints"
                            class="text-lg font-black text-yellow-600"
                        >
                            0 points
                        </span>
                    </div>
                </div>

                <div
                    class="flex items-start gap-3 rounded-2xl border border-yellow-200 bg-yellow-50 p-4"
                >
                    <span class="mt-0.5 text-lg">
                        ⚠️
                    </span>

                    <p
                        class="text-sm leading-6 text-yellow-800"
                    >
                        Your points will be deducted immediately after confirmation. This action cannot be undone.
                    </p>
                </div>

                <div class="flex gap-3">
                    <button
                        type="button"
                        id="voucherCancelButton"
                        onclick="closeVoucherConfirmModal()"
                        class="flex-1 rounded-xl border border-gray-300 bg-white px-4 py-3 text-sm font-bold text-gray-600 transition-colors hover:bg-gray-50"
                    >
                        Cancel
                    </button>

                    <button
                        type="button"
                        id="voucherConfirmButton"
                        onclick="submitVoucherRedemption()"
                        class="flex-1 rounded-xl bg-red-600 px-4 py-3 text-sm font-bold text-white transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <span
                            id="voucherConfirmButtonText"
                        >
                            Confirm Redemption
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tab) {
            [
                'promotions',
                'points',
                'myvouchers'
            ].forEach((tabName) => {
                const tabButton =
                    document.getElementById(
                        'tab-' + tabName
                    );

                const tabContent =
                    document.getElementById(
                        'content-' + tabName
                    );

                tabButton.className =
                    'px-5 py-2 rounded-xl text-sm font-semibold transition-colors ' +
                    (
                        tabName === tab
                            ? 'bg-red-600 text-white'
                            : 'text-gray-500 hover:text-red-600'
                    );

                tabContent.classList.toggle(
                    'hidden',
                    tabName !== tab
                );
            });
        }

        const requestedVoucherTab =
            new URLSearchParams(
                window.location.search
            ).get('tab');

        if (
            [
                'promotions',
                'points',
                'myvouchers'
            ].includes(requestedVoucherTab)
        ) {
            switchTab(
                requestedVoucherTab
            );
        }

        let pendingVoucherForm = null;

        function confirmVoucherRedemption(
            form
        ) {
            if (
                form.dataset.submitting ===
                'true'
            ) {
                return false;
            }

            pendingVoucherForm = form;

            const pointsRequired =
                Number(
                    form.dataset
                        .pointsRequired ||
                    0
                );

            const voucherCode =
                form.dataset.voucherCode ||
                '-';

            const voucherOffer =
                form.dataset.voucherOffer ||
                '-';

            document.getElementById(
                'voucherConfirmCode'
            ).textContent =
                voucherCode;

            document.getElementById(
                'voucherConfirmOffer'
            ).textContent =
                voucherOffer;

            document.getElementById(
                'voucherConfirmPoints'
            ).textContent =
                pointsRequired.toLocaleString(
                    'en-MY'
                ) +
                ' points';

            const confirmButton =
                document.getElementById(
                    'voucherConfirmButton'
                );

            const confirmButtonText =
                document.getElementById(
                    'voucherConfirmButtonText'
                );

            confirmButton.disabled =
                false;

            confirmButtonText.textContent =
                'Confirm Redemption';

            const modal =
                document.getElementById(
                    'voucherConfirmModal'
                );

            modal.classList.remove(
                'hidden'
            );

            modal.classList.add(
                'flex'
            );

            document.body.classList.add(
                'overflow-hidden'
            );

            window.setTimeout(() => {
                confirmButton.focus();
            }, 50);

            return false;
        }

        function closeVoucherConfirmModal() {
            if (
                pendingVoucherForm &&
                pendingVoucherForm.dataset
                    .submitting ===
                    'true'
            ) {
                return;
            }

            const modal =
                document.getElementById(
                    'voucherConfirmModal'
                );

            modal.classList.add(
                'hidden'
            );

            modal.classList.remove(
                'flex'
            );

            document.body.classList.remove(
                'overflow-hidden'
            );

            pendingVoucherForm = null;
        }

        function submitVoucherRedemption() {
            if (!pendingVoucherForm) {
                return;
            }

            if (
                pendingVoucherForm.dataset
                    .submitting ===
                    'true'
            ) {
                return;
            }

            const formToSubmit =
                pendingVoucherForm;

            formToSubmit.dataset.submitting =
                'true';

            const confirmButton =
                document.getElementById(
                    'voucherConfirmButton'
                );

            const confirmButtonText =
                document.getElementById(
                    'voucherConfirmButtonText'
                );

            confirmButton.disabled = true;

            confirmButtonText.textContent =
                'Redeeming...';

            const originalSubmitButton =
                formToSubmit.querySelector(
                    'button[type="submit"]'
                );

            if (originalSubmitButton) {
                originalSubmitButton.disabled =
                    true;

                originalSubmitButton.textContent =
                    'Redeeming...';
            }

            const modal =
                document.getElementById(
                    'voucherConfirmModal'
                );

            modal.classList.add(
                'hidden'
            );

            modal.classList.remove(
                'flex'
            );

            document.body.classList.remove(
                'overflow-hidden'
            );

            pendingVoucherForm = null;

            HTMLFormElement
                .prototype
                .submit
                .call(
                    formToSubmit
                );
        }

        document.getElementById(
            'voucherConfirmModal'
        ).addEventListener(
            'click',
            function (event) {
                if (
                    event.target ===
                    this
                ) {
                    closeVoucherConfirmModal();
                }
            }
        );

        document.addEventListener(
            'keydown',
            function (event) {
                if (
                    event.key !==
                    'Escape'
                ) {
                    return;
                }

                const modal =
                    document.getElementById(
                        'voucherConfirmModal'
                    );

                if (
                    !modal.classList
                        .contains(
                            'hidden'
                        )
                ) {
                    closeVoucherConfirmModal();
                }
            }
        );

        function togglePointsHistory() {
            const shortHistory =
                document.getElementById(
                    'pointsHistoryShort'
                );

            const fullHistory =
                document.getElementById(
                    'pointsHistoryFull'
                );

            const toggleButton =
                document.getElementById(
                    'pointsToggleBtn'
                );

            if (
                !shortHistory ||
                !fullHistory ||
                !toggleButton
            ) {
                return;
            }

            const isExpanded =
                !fullHistory.classList
                    .contains(
                        'hidden'
                    );

            if (isExpanded) {
                fullHistory.classList.add(
                    'hidden'
                );

                shortHistory.classList.remove(
                    'hidden'
                );

                toggleButton.textContent =
                    'View All ↓';
            } else {
                fullHistory.classList.remove(
                    'hidden'
                );

                shortHistory.classList.add(
                    'hidden'
                );

                toggleButton.textContent =
                    'Show Less ↑';
            }
        }
    </script>
</body>
</html>