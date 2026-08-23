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

        if ($claimed_stmt->fetchColumn()) {
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
            $deduct_points->rowCount() !== 1
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
            AND uv_voucher_id = v.voucher_id
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
    ORDER BY v.voucher_points_required ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Get my vouchers — points redeemed + used promo vouchers
$my_vouchers = $pdo->prepare("
    SELECT v.*, uv.uv_claimed_at, uv.uv_is_used, uv.uv_used_at, uv.uv_expires_at, uv.uv_status
    FROM user_vouchers uv
    JOIN vouchers v ON uv.uv_voucher_id = v.voucher_id
    WHERE uv.uv_user_id = ?

    UNION

    SELECT v.*, vu.usage_created_at as uv_claimed_at, 1 as uv_is_used, vu.usage_created_at as uv_used_at, NULL as uv_expires_at, 'used' as uv_status
    FROM voucher_usage vu
    JOIN vouchers v ON vu.usage_voucher_id = v.voucher_id
    WHERE vu.usage_user_id = ?
    AND NOT EXISTS (
        SELECT 1 FROM user_vouchers uv2 
        WHERE uv2.uv_user_id = ? AND uv2.uv_voucher_id = v.voucher_id
    )

    ORDER BY uv_claimed_at DESC
");
$my_vouchers->execute([$user_id, $user_id, $user_id]);
$my_vouchers = $my_vouchers->fetchAll(PDO::FETCH_ASSOC);

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
    SELECT * FROM points_log WHERE log_user_id = ? ORDER BY log_created_at DESC LIMIT 20
");
$points_history->execute([$user_id]);
$points_history = $points_history->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vouchers & Points - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }

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
            <a href="../index.php" class="hover:text-red-600 transition-colors">Home</a>
            <span class="mx-2">›</span>
            <a href="dashboard.php" class="hover:text-red-600 transition-colors">My Account</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">Vouchers & Points</span>
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
                <div class="bg-gradient-to-r from-[#1e2d4a] to-[#2c3e6b] rounded-2xl p-6 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Your Points Balance</p>
                            <p class="text-5xl font-black"><?= number_format($user_points) ?></p>
                            <p class="text-white/50 text-xs mt-1">RM1 spent = 1 point</p>
                        </div>
                        <div class="text-right">
                            <div class="text-6xl">⭐</div>
                            <p class="text-white/50 text-xs mt-2">Earn points with every purchase</p>
                        </div>
                    </div>

                    <?php if (!empty($points_history)): ?>
                    <div class="mt-5 pt-5 border-t border-white/20">
                        <div class="flex justify-between items-center mb-3">
                            <p class="text-white/60 text-xs font-semibold uppercase tracking-wide">Recent Points Activity</p>
                            <?php if (count($points_history) > 3): ?>
                            <button onclick="togglePointsHistory()" id="pointsToggleBtn"
                                    class="text-white/50 hover:text-white text-xs transition-colors">
                                View All ↓
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="space-y-2" id="pointsHistoryShort">
                            <?php foreach (array_slice($points_history, 0, 3) as $log): ?>
                            <div class="flex justify-between items-center">
                                <p class="text-white/80 text-xs"><?= htmlspecialchars($log['log_description']) ?></p>
                                    <span class="text-xs font-bold <?= $log['log_points'] > 0 ? 'text-green-400' : 'text-red-400' ?>">
                                        <?= $log['log_points'] > 0 ? '+' : '' ?><?= $log['log_points'] ?> pts
                                    </span>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (count($points_history) > 3): ?>
                        <div class="space-y-2 hidden" id="pointsHistoryFull">
                            <?php foreach ($points_history as $log): ?>
                            <div class="flex justify-between items-center">
                                <div class="flex-1 min-w-0 mr-3">
                                    <p class="text-white/80 text-xs truncate"><?= htmlspecialchars($log['log_description']) ?></p>
                                    <p class="text-white/40 text-xs"><?= date('d M Y', strtotime($log['log_created_at'])) ?></p>
                                </div>
                                <span class="text-xs font-bold flex-shrink-0 <?= $log['log_points'] > 0 ? 'text-green-400' : 'text-red-400' ?>">
                                    <?= $log['log_points'] > 0 ? '+' : '' ?><?= $log['log_points'] ?> pts
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php
                $available_count = count(array_filter($my_vouchers, function($v) {
                    $uv_expires = !empty($v['uv_expires_at']) ? strtotime($v['uv_expires_at']) : null;
                    $is_expired = ($v['voucher_end_date'] && strtotime($v['voucher_end_date']) < time())
                        || ($uv_expires && $uv_expires < time());
                    $is_pending = ($v['uv_status'] ?? 'available') === 'pending';
                    return !$v['uv_is_used'] && !$is_expired && !$is_pending;
                }));
                ?>
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
                        <?= count($promo_vouchers) > 0
                            ? '(' .
                                count($promo_vouchers) .
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
                            ? "($available_count)"
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

                            <p class="text-xs text-red-600">
                                Add products to your cart, select them, and then choose one of these vouchers on the Checkout page.
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
                            $promo_vouchers as $voucher
                        ): ?>
                            <?php
                            $promotion_expiry =
                                !empty(
                                    $voucher[
                                        'voucher_end_date'
                                    ]
                                )
                                    ? strtotime(
                                        $voucher[
                                            'voucher_end_date'
                                        ]
                                    )
                                    : null;

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
                                                        <?php if (
                                                            $voucher[
                                                                'voucher_type'
                                                            ] ===
                                                            'percentage'
                                                        ): ?>
                                                            <?= htmlspecialchars(
                                                                (string) $voucher[
                                                                    'voucher_value'
                                                                ],
                                                                ENT_QUOTES,
                                                                'UTF-8'
                                                            ) ?>% OFF
                                                        <?php else: ?>
                                                            RM
                                                            <?= number_format(
                                                                (float) $voucher[
                                                                    'voucher_value'
                                                                ],
                                                                2
                                                            ) ?>
                                                            OFF
                                                        <?php endif; ?>
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
                    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-5 flex items-center gap-3">
                        <span class="text-2xl">⭐</span>
                        <div>
                            <p class="font-semibold text-blue-800 text-sm">You have <span class="text-blue-600 font-black"><?= number_format($user_points) ?> points</span></p>
                            <p class="text-xs text-blue-600">Redeem your points for exclusive vouchers!</p>
                        </div>
                    </div>

                    <?php if (empty($points_vouchers)): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                        <div class="text-5xl mb-4">⭐</div>
                        <p class="text-gray-500">No points vouchers available</p>
                    </div>
                    <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($points_vouchers as $v):
                            $can_redeem = $user_points >= $v['voucher_points_required'] && !$v['already_claimed'];
                            $already_claimed = $v['already_claimed'];
                        ?>
                        <div class="voucher-card shadow-sm mx-3 <?= !$can_redeem && !$already_claimed ? 'opacity-60' : '' ?>">
                            <div class="flex">
                                <div class="w-3 <?= $already_claimed ? 'bg-green-500' : ($can_redeem ? 'bg-yellow-500' : 'bg-gray-300') ?> flex-shrink-0"></div>
                                <div class="flex-1 flex items-stretch">
                                    <div class="flex-1 p-5">
                                        <div class="flex items-start gap-3 mb-2">
                                            <span class="text-2xl">⭐</span>
                                            <div>
                                                <span class="<?= $already_claimed ? 'bg-green-100 text-green-700' : ($can_redeem ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-500') ?> text-xs px-2 py-0.5 rounded-full font-semibold mb-1 inline-block">
                                                    <?= $already_claimed ? '✅ CLAIMED' : ($can_redeem ? '✨ REDEEMABLE' : '🔒 LOCKED') ?>
                                                </span>
                                                <h3 class="font-black text-gray-800 text-lg">
                                                    <?= $v['voucher_type'] === 'percentage' ? $v['voucher_value'] . '% OFF' : 'RM ' . number_format($v['voucher_value'], 2) . ' OFF' ?>
                                                </h3>
                                                <?php if ($v['voucher_max_discount']): ?>
                                                <p class="text-xs text-gray-400">Max discount: RM <?= number_format($v['voucher_max_discount'], 2) ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-4 text-xs text-gray-400 mt-2">
                                            <?php if ($v['voucher_min_order'] > 0): ?>
                                            <span>Min spend: RM <?= number_format($v['voucher_min_order'], 2) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!$can_redeem && !$already_claimed): ?>
                                        <div class="mt-2">
                                            <div class="bg-gray-200 rounded-full h-1.5 w-full">
                                                <div class="bg-yellow-500 h-1.5 rounded-full transition-all"
                                                     style="width: <?= min(100, ($user_points / $v['voucher_points_required']) * 100) ?>%"></div>
                                            </div>
                                            <p class="text-xs text-gray-400 mt-1"><?= number_format($user_points) ?> / <?= number_format($v['voucher_points_required']) ?> pts needed</p>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="voucher-divider w-px my-4"></div>
                                    <div class="w-40 flex flex-col items-center justify-center p-4 gap-2">
                                        <p class="text-xs text-gray-400 font-semibold uppercase tracking-wide">Required</p>
                                        <p class="font-black text-yellow-600 text-xl"><?= number_format($v['voucher_points_required']) ?></p>
                                        <p class="text-xs text-gray-400">points</p>
                                        <?php if ($already_claimed): ?>
                                        <span class="bg-green-100 text-green-700 text-xs font-semibold px-3 py-1.5 rounded-xl">Claimed ✓</span>
                                        <?php elseif ($can_redeem): ?>
                                        <form
                                            method="POST"
                                            data-voucher-reward="<?= htmlspecialchars(
                                                $v['voucher_type'] === 'percentage'
                                                    ? (string)$v['voucher_value'] . '% OFF'
                                                    : 'RM ' . number_format(
                                                        (float)$v['voucher_value'],
                                                        2
                                                    ) . ' OFF',
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                            data-points-required="<?= (int)$v[
                                                'voucher_points_required'
                                            ] ?>"
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
                                                value="<?= (int)$v[
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
                                        <span class="bg-gray-100 text-gray-400 text-xs font-semibold px-3 py-1.5 rounded-xl">🔒 Locked</span>
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
                <div id="content-myvouchers" class="hidden">
                    <?php if (empty($my_vouchers)): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                        <div class="text-5xl mb-4">💼</div>
                        <p class="text-gray-500 font-medium">No vouchers yet</p>
                        <p class="text-gray-400 text-sm mt-1">Redeem points or use promo codes to get vouchers!</p>
                    </div>
                    <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($my_vouchers as $v):
                            $uv_expires = !empty($v['uv_expires_at']) ? strtotime($v['uv_expires_at']) : null;
                            $is_expired = ($v['voucher_end_date'] && strtotime($v['voucher_end_date']) < time()) 
                                        || ($uv_expires && $uv_expires < time());
                            $is_used = $v['uv_is_used'];
                            $is_pending = ($v['uv_status'] ?? 'available') === 'pending';
                        ?>
                        <div class="voucher-card shadow-sm mx-3 <?= ($is_expired || $is_used || $is_pending) ? 'opacity-60' : '' ?>">
                            <div class="flex">
                                <div class="w-3 <?= $is_used ? 'bg-gray-400' : ($is_pending ? 'bg-yellow-400' : ($is_expired ? 'bg-gray-300' : 'bg-green-500')) ?> flex-shrink-0"></div>
                                <div class="flex-1 flex items-stretch">
                                    <div class="flex-1 p-5">
                                        <div class="flex items-start gap-2 mb-2">
                                            <div>
                                                <span class="<?= $is_used ? 'bg-gray-100 text-gray-500' : ($is_pending ? 'bg-yellow-100 text-yellow-700' : ($is_expired ? 'bg-red-100 text-red-500' : 'bg-green-100 text-green-700')) ?> text-xs px-2 py-0.5 rounded-full font-semibold mb-1 inline-block">
                                                    <?= $is_used ? 'USED' : ($is_pending ? '⏳ PENDING' : ($is_expired ? 'EXPIRED' : 'AVAILABLE')) ?>
                                                </span>
                                                <h3 class="font-black text-gray-800 text-lg">
                                                    <?= $v['voucher_type'] === 'percentage' ? $v['voucher_value'] . '% OFF' : 'RM ' . number_format($v['voucher_value'], 2) . ' OFF' ?>
                                                </h3>
                                                <?php if ($v['voucher_max_discount']): ?>
                                                <p class="text-xs text-gray-400">Max discount: RM <?= number_format($v['voucher_max_discount'], 2) ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-4 text-xs text-gray-400 mt-2 flex-wrap">
                                            <?php if ($v['voucher_min_order'] > 0): ?>
                                            <span>Min spend: RM <?= number_format($v['voucher_min_order'], 2) ?></span>
                                            <?php endif; ?>
                                            <span>Claimed: <?= date('d M Y', strtotime($v['uv_claimed_at'])) ?></span>
                                            <?php
                                            $expiry = null;
                                            if ($v['uv_expires_at'] && $v['voucher_end_date']) {
                                                $expiry = min(strtotime($v['uv_expires_at']), strtotime($v['voucher_end_date']));
                                            } elseif ($v['uv_expires_at']) {
                                                $expiry = strtotime($v['uv_expires_at']);
                                            } elseif ($v['voucher_end_date']) {
                                                $expiry = strtotime($v['voucher_end_date']);
                                            }
                                            ?>
                                            <?php if ($expiry): ?>
                                            <span class="<?= $expiry < time() ? 'text-red-500' : '' ?>">
                                                Valid until: <?= date('d M Y', $expiry) ?>
                                            </span>
                                            <?php else: ?>
                                            <span class="text-gray-400 text-xs">No expiry</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="voucher-divider w-px my-4"></div>
                                    <div class="w-40 flex flex-col items-center justify-center p-4 gap-2">
                                        <p class="text-xs text-gray-400 font-semibold uppercase tracking-wide">Code</p>
                                        <p class="font-mono font-black text-gray-800 text-sm bg-gray-50 px-3 py-1.5 rounded-lg border border-dashed border-gray-300">
                                            <?= htmlspecialchars($v['voucher_code']) ?>
                                        </p>
                                        <?php if (!$is_used && !$is_expired && !$is_pending): ?>
                                        <a
                                            href="cart.php"
                                            class="rounded-xl bg-red-600 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-red-700"
                                        >
                                            Go to Cart →
                                        </a>
                                        <?php elseif ($is_pending): ?>
                                        <span class="text-xs text-yellow-600 font-semibold text-center">⏳ In Use</span>
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

                        <p class="mt-1 text-sm text-white/80">
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
                        <span class="text-sm text-gray-500">
                            Reward
                        </span>

                        <span
                            id="voucherConfirmReward"
                            class="text-right text-base font-black text-red-600"
                        >
                            -
                        </span>
                    </div>

                    <div
                        class="flex items-center justify-between gap-4 pt-3"
                    >
                        <span class="text-sm text-gray-500">
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

                    <p class="text-sm leading-6 text-yellow-800">
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
                        <span id="voucherConfirmButtonText">
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
                const tabButton = document.getElementById(
                    'tab-' + tabName
                );

                const tabContent = document.getElementById(
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
            switchTab(requestedVoucherTab);
        }

        let pendingVoucherForm = null;

        function confirmVoucherRedemption(form) {
            if (
                form.dataset.submitting ===
                'true'
            ) {
                return false;
            }

            pendingVoucherForm = form;

            const voucherReward =
                form.dataset.voucherReward ||
                'Points Voucher';

            const pointsRequired = Number(
                form.dataset.pointsRequired ||
                0
            );

            document.getElementById(
                'voucherConfirmReward'
            ).textContent = voucherReward;

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

            confirmButton.disabled = false;

            confirmButtonText.textContent =
                'Confirm Redemption';

            const modal = document.getElementById(
                'voucherConfirmModal'
            );

            modal.classList.remove('hidden');
            modal.classList.add('flex');

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
                pendingVoucherForm.dataset.submitting ===
                    'true'
            ) {
                return;
            }

            const modal = document.getElementById(
                'voucherConfirmModal'
            );

            modal.classList.add('hidden');
            modal.classList.remove('flex');

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
                pendingVoucherForm.dataset.submitting ===
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

            const modal = document.getElementById(
                'voucherConfirmModal'
            );

            modal.classList.add('hidden');
            modal.classList.remove('flex');

            document.body.classList.remove(
                'overflow-hidden'
            );

            pendingVoucherForm = null;

            HTMLFormElement.prototype.submit.call(
                formToSubmit
            );
        }

        document.getElementById(
            'voucherConfirmModal'
        ).addEventListener(
            'click',
            function (event) {
                if (event.target === this) {
                    closeVoucherConfirmModal();
                }
            }
        );

        document.addEventListener(
            'keydown',
            function (event) {
                if (event.key !== 'Escape') {
                    return;
                }

                const modal =
                    document.getElementById(
                        'voucherConfirmModal'
                    );

                if (
                    !modal.classList.contains(
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

            const isExpanded =
                !fullHistory.classList.contains(
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