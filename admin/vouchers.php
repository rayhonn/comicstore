<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/money_helper.php';
require_once __DIR__ . '/../includes/logger.php';

require_admin();

date_default_timezone_set('Asia/Kuala_Lumpur');

$success = (string) ($_SESSION['admin_voucher_success'] ?? '');
unset($_SESSION['admin_voucher_success']);
$error = '';

function requireVoucherId(mixed $value): int
{
    $voucherId = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if ($voucherId === false) {
        throw new InvalidArgumentException(
            'Invalid voucher.'
        );
    }

    return (int) $voucherId;
}

function normalizeVoucherCode(mixed $value): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException(
            'Voucher code is required.'
        );
    }

    $code = strtoupper(trim($value));
    $length = function_exists('mb_strlen')
        ? mb_strlen($code, 'UTF-8')
        : strlen($code);

    if ($code === '') {
        throw new InvalidArgumentException(
            'Voucher code is required.'
        );
    }

    if ($length > 50) {
        throw new InvalidArgumentException(
            'Voucher code cannot exceed 50 characters.'
        );
    }

    if (!preg_match('/\A[A-Z0-9_-]+\z/', $code)) {
        throw new InvalidArgumentException(
            'Voucher code may only contain letters, numbers, hyphens and underscores.'
        );
    }

    return $code;
}

function normalizeVoucherType(mixed $value): string
{
    if (
        !is_string($value) ||
        !in_array(
            $value,
            [
                'percentage',
                'fixed',
            ],
            true
        )
    ) {
        throw new InvalidArgumentException(
            'Please select a valid voucher type.'
        );
    }

    return $value;
}

function normalizeVoucherAmounts(
    array $input,
    string $type
): array {
    try {
        $value = moneyNormalizeDecimal(
            (string) ($input['voucher_value'] ?? '')
        );

        $minimumRaw = trim(
            (string) (
                $input['voucher_min_order'] ?? ''
            )
        );
        $minimumOrder = moneyNormalizeDecimal(
            $minimumRaw === ''
                ? '0'
                : $minimumRaw
        );

        $maximumRaw = trim(
            (string) (
                $input['voucher_max_discount'] ?? ''
            )
        );
        $maximumDiscount =
            $maximumRaw === ''
                ? null
                : moneyNormalizeDecimal(
                    $maximumRaw
                );

        $valueSen = moneyDecimalToSen($value);
    } catch (MoneyValueException $e) {
        throw new InvalidArgumentException(
            'Please enter valid voucher amounts with up to two decimal places.',
            0,
            $e
        );
    }

    if ($valueSen < 1) {
        throw new InvalidArgumentException(
            'Voucher value must be at least 0.01.'
        );
    }

    if (
        $type === 'percentage' &&
        $valueSen > 10000
    ) {
        throw new InvalidArgumentException(
            'Percentage voucher value cannot exceed 100.00.'
        );
    }

    if ($type === 'fixed') {
        $maximumDiscount = null;
    }

    return [
        $value,
        $minimumOrder,
        $maximumDiscount,
    ];
}

function normalizeVoucherUsageLimit(
    mixed $value
): ?int {
    if ($value === null || $value === '') {
        return null;
    }

    $usageLimit = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
                'max_range' => 2147483647,
            ],
        ]
    );

    if ($usageLimit === false) {
        throw new InvalidArgumentException(
            'Usage limit must be a positive whole number.'
        );
    }

    return (int) $usageLimit;
}

function normalizeVoucherDate(
    mixed $value,
    string $label
): ?string {
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_string($value)) {
        throw new InvalidArgumentException(
            'Invalid ' . $label . '.'
        );
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i',
        trim($value)
    );
    $errors = DateTimeImmutable::getLastErrors();

    if (
        !$date ||
        (
            is_array($errors) &&
            (
                $errors['warning_count'] > 0 ||
                $errors['error_count'] > 0
            )
        )
    ) {
        throw new InvalidArgumentException(
            'Invalid ' . $label . '.'
        );
    }

    return $date->format('Y-m-d H:i:s');
}

function clearTierBirthdayVoucherReferences(
    PDO $pdo,
    int $voucherId
): void {
    $statement = $pdo->prepare("
        UPDATE tier_config
        SET tier_birthday_voucher_id = NULL
        WHERE tier_birthday_voucher_id = ?
    ");
    $statement->execute([
        $voucherId,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? null;

    try {
        if (
            !is_string($action) ||
            !in_array(
                $action,
                [
                    'add',
                    'edit',
                    'delete',
                    'toggle',
                ],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid voucher action.'
            );
        }

        if (
            $action === 'delete' ||
            $action === 'toggle'
        ) {
            $voucherId = requireVoucherId(
                $_POST['voucher_id'] ?? null
            );

            $voucherStatement = $pdo->prepare("
                SELECT
                    voucher_id,
                    voucher_is_active,
                    voucher_is_birthday_template
                FROM vouchers
                WHERE voucher_id = ?
                AND voucher_is_system_generated = 0
                LIMIT 1
            ");
            $voucherStatement->execute([
                $voucherId,
            ]);
            $voucher = $voucherStatement->fetch(
                PDO::FETCH_ASSOC
            );

            if (!$voucher) {
                throw new InvalidArgumentException(
                    'Voucher not found.'
                );
            }

            $pdo->beginTransaction();

            if ($action === 'delete') {
                clearTierBirthdayVoucherReferences(
                    $pdo,
                    $voucherId
                );

                $deleteStatement = $pdo->prepare("
                    DELETE FROM vouchers
                    WHERE voucher_id = ?
                    AND voucher_is_system_generated = 0
                ");
                $deleteStatement->execute([
                    $voucherId,
                ]);

                if ($deleteStatement->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Voucher deletion failed.'
                    );
                }

                $success = 'Voucher deleted.';
            } else {
                $newActiveStatus =
                    (int) $voucher[
                        'voucher_is_active'
                    ] === 1
                        ? 0
                        : 1;

                $toggleStatement = $pdo->prepare("
                    UPDATE vouchers
                    SET voucher_is_active = ?
                    WHERE voucher_id = ?
                    AND voucher_is_system_generated = 0
                ");
                $toggleStatement->execute([
                    $newActiveStatus,
                    $voucherId,
                ]);

                if ($toggleStatement->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Voucher status update failed.'
                    );
                }

                if ($newActiveStatus === 0) {
                    clearTierBirthdayVoucherReferences(
                        $pdo,
                        $voucherId
                    );
                }

                $success =
                    $newActiveStatus === 1
                        ? 'Voucher enabled.'
                        : 'Voucher disabled.';
            }

            $pdo->commit();
        } else {
            $voucherId = null;
            $isPointsVoucher = false;

            if ($action === 'edit') {
                $voucherId = requireVoucherId(
                    $_POST['voucher_id'] ?? null
                );

                $existingStatement = $pdo->prepare("
                    SELECT
                        voucher_id,
                        voucher_is_points_redeem
                    FROM vouchers
                    WHERE voucher_id = ?
                    AND voucher_is_system_generated = 0
                    LIMIT 1
                ");
                $existingStatement->execute([
                    $voucherId,
                ]);
                $existingVoucher =
                    $existingStatement->fetch(
                        PDO::FETCH_ASSOC
                    );

                if (!$existingVoucher) {
                    throw new InvalidArgumentException(
                        'Voucher not found.'
                    );
                }

                $isPointsVoucher =
                    (int) $existingVoucher[
                        'voucher_is_points_redeem'
                    ] === 1;
            }

            $code = normalizeVoucherCode(
                $_POST['voucher_code'] ?? null
            );
            $type = normalizeVoucherType(
                $_POST['voucher_type'] ?? null
            );
            [
                $value,
                $minimumOrder,
                $maximumDiscount,
            ] = normalizeVoucherAmounts(
                $_POST,
                $type
            );

            $usageLimit =
                normalizeVoucherUsageLimit(
                    $_POST[
                        'voucher_usage_limit'
                    ] ?? null
                );
            $startDate = normalizeVoucherDate(
                $_POST['voucher_start_date'] ?? null,
                'start date'
            );
            $endDate = normalizeVoucherDate(
                $_POST['voucher_end_date'] ?? null,
                'end date'
            );
            $isActive = isset(
                $_POST['voucher_is_active']
            ) ? 1 : 0;
            $isBirthdayTemplate =
                !$isPointsVoucher &&
                isset(
                    $_POST[
                        'voucher_is_birthday_template'
                    ]
                )
                    ? 1
                    : 0;

            if (
                $startDate !== null &&
                $endDate !== null &&
                strtotime($endDate) <
                    strtotime($startDate)
            ) {
                throw new InvalidArgumentException(
                    'End date cannot be earlier than start date.'
                );
            }

            $duplicateSql = "
                SELECT voucher_id
                FROM vouchers
                WHERE voucher_code = ?
            ";
            $duplicateParams = [$code];

            if ($voucherId !== null) {
                $duplicateSql .=
                    ' AND voucher_id != ?';
                $duplicateParams[] = $voucherId;
            }

            $duplicateStatement = $pdo->prepare(
                $duplicateSql
            );
            $duplicateStatement->execute(
                $duplicateParams
            );

            if ($duplicateStatement->fetchColumn()) {
                throw new InvalidArgumentException(
                    'Voucher code already exists.'
                );
            }

            $pdo->beginTransaction();

            if ($action === 'add') {
                $insertStatement = $pdo->prepare("
                    INSERT INTO vouchers (
                        voucher_code,
                        voucher_type,
                        voucher_value,
                        voucher_min_order,
                        voucher_max_discount,
                        voucher_usage_limit,
                        voucher_start_date,
                        voucher_end_date,
                        voucher_is_active,
                        voucher_is_birthday_template
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insertStatement->execute([
                    $code,
                    $type,
                    $value,
                    $minimumOrder,
                    $maximumDiscount,
                    $usageLimit,
                    $startDate,
                    $endDate,
                    $isActive,
                    $isBirthdayTemplate,
                ]);

                $success = 'Voucher created!';
            } else {
                $updateStatement = $pdo->prepare("
                    UPDATE vouchers
                    SET voucher_code = ?,
                        voucher_type = ?,
                        voucher_value = ?,
                        voucher_min_order = ?,
                        voucher_max_discount = ?,
                        voucher_usage_limit = ?,
                        voucher_start_date = ?,
                        voucher_end_date = ?,
                        voucher_is_active = ?,
                        voucher_is_birthday_template = ?
                    WHERE voucher_id = ?
                    AND voucher_is_system_generated = 0
                ");
                $updateStatement->execute([
                    $code,
                    $type,
                    $value,
                    $minimumOrder,
                    $maximumDiscount,
                    $usageLimit,
                    $startDate,
                    $endDate,
                    $isActive,
                    $isBirthdayTemplate,
                    $voucherId,
                ]);

                if (
                    $isBirthdayTemplate === 0 ||
                    $isActive === 0
                ) {
                    clearTierBirthdayVoucherReferences(
                        $pdo,
                        (int) $voucherId
                    );
                }

                $success = 'Voucher updated!';
            }

            $pdo->commit();
        }

        $_SESSION['admin_voucher_success'] =
            $success;

        header('Location: vouchers.php');
        exit;
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        app_error_log(
            'Admin voucher management failed: ' .
            $e->getMessage()
        );

        $error =
            'Unable to update the voucher. Please try again.';
    }
}

$vouchers = $pdo->query("
    SELECT
        v.*,
        COUNT(vu.usage_id) AS actual_usage
    FROM vouchers v
    LEFT JOIN voucher_usage vu
        ON v.voucher_id = vu.usage_voucher_id
    WHERE v.voucher_is_system_generated = 0
    GROUP BY v.voucher_id
    ORDER BY v.voucher_created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Vouchers - MangaVault Admin</title>

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

        .voucher-modal {
            display: none;
        }

        .voucher-modal.active {
            display: flex;
        }
    </style>
</head>

<body class="min-h-screen bg-gray-100">

    <?php include __DIR__ . '/../includes/admin_navbar.php'; ?>

    <main class="mx-auto max-w-7xl px-6 py-8">
        <div
            class="mb-6 flex items-center justify-between gap-4"
        >
            <div>
                <h1
                    class="text-2xl font-black text-gray-800"
                >
                    Vouchers
                </h1>

                <p class="mt-0.5 text-sm text-gray-400">
                    <?= count($vouchers) ?> vouchers total
                </p>
            </div>

            <button
                type="button"
                onclick="openAddModal()"
                class="rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-red-700"
            >
                + Create Voucher
            </button>
        </div>

        <?php if ($success !== ''): ?>
            <div
                class="mb-5 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"
            >
                ✅
                <?= htmlspecialchars(
                    $success,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div
                class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
            >
                ❌
                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>
        <?php endif; ?>

        <?php if ($vouchers === []): ?>
            <div
                class="rounded-2xl bg-white p-12 text-center shadow-sm"
            >
                <div class="mb-4 text-5xl">🎟️</div>
                <p class="mb-4 font-medium text-gray-500">
                    No vouchers yet
                </p>

                <button
                    type="button"
                    onclick="openAddModal()"
                    class="rounded-xl bg-red-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-red-700"
                >
                    Create First Voucher
                </button>
            </div>
        <?php else: ?>
            <div
                class="overflow-x-auto rounded-2xl bg-white shadow-sm"
            >
                <table class="min-w-full">
                    <thead>
                        <tr
                            class="border-b border-gray-100 bg-gray-50"
                        >
                            <th
                                class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                            >
                                Code
                            </th>
                            <th
                                class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                            >
                                Purpose
                            </th>
                            <th
                                class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                            >
                                Discount
                            </th>
                            <th
                                class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                            >
                                Min Order
                            </th>
                            <th
                                class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                            >
                                Usage
                            </th>
                            <th
                                class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                            >
                                Validity
                            </th>
                            <th
                                class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                            >
                                Status
                            </th>
                            <th
                                class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                            >
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach (
                            $vouchers as $voucher
                        ): ?>
                            <?php
                            $valueSen = moneyDecimalToSen(
                                (string) $voucher[
                                    'voucher_value'
                                ]
                            );
                            $minimumOrderSen =
                                moneyDecimalToSen(
                                    (string) $voucher[
                                        'voucher_min_order'
                                    ]
                                );
                            $maximumDiscountSen =
                                $voucher[
                                    'voucher_max_discount'
                                ] === null
                                    ? null
                                    : moneyDecimalToSen(
                                        (string) $voucher[
                                            'voucher_max_discount'
                                        ]
                                    );
                            $now = new DateTimeImmutable();
                            $isExpired =
                                !empty(
                                    $voucher[
                                        'voucher_end_date'
                                    ]
                                ) &&
                                new DateTimeImmutable(
                                    (string) $voucher[
                                        'voucher_end_date'
                                    ]
                                ) < $now;
                            $isMaxed =
                                $voucher[
                                    'voucher_usage_limit'
                                ] !== null &&
                                (int) $voucher[
                                    'actual_usage'
                                ] >=
                                (int) $voucher[
                                    'voucher_usage_limit'
                                ];
                            $isBirthdayTemplate =
                                (int) (
                                    $voucher[
                                        'voucher_is_birthday_template'
                                    ] ?? 0
                                ) === 1;
                            $isPointsVoucher =
                                (int) (
                                    $voucher[
                                        'voucher_is_points_redeem'
                                    ] ?? 0
                                ) === 1;
                            ?>

                            <tr
                                class="border-t border-gray-50 transition-colors hover:bg-gray-50 <?= (!(int) $voucher['voucher_is_active'] || $isExpired || $isMaxed) ? 'opacity-60' : '' ?>"
                            >
                                <td class="px-5 py-4">
                                    <span
                                        class="rounded-lg bg-gray-100 px-3 py-1 font-mono text-sm font-black text-gray-800"
                                    >
                                        <?= htmlspecialchars(
                                            (string) $voucher[
                                                'voucher_code'
                                            ],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </span>
                                </td>

                                <td class="px-5 py-4">
                                    <?php if (
                                        $isBirthdayTemplate
                                    ): ?>
                                        <span
                                            class="inline-flex rounded-full bg-pink-100 px-2.5 py-1 text-xs font-semibold text-pink-700"
                                        >
                                            🎂 Birthday Template
                                        </span>
                                    <?php elseif (
                                        $isPointsVoucher
                                    ): ?>
                                        <span
                                            class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-700"
                                        >
                                            ⭐ Points Redeem
                                        </span>
                                    <?php else: ?>
                                        <span
                                            class="inline-flex rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-700"
                                        >
                                            General
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="px-5 py-4">
                                    <p class="font-bold text-red-600">
                                        <?= $voucher[
                                            'voucher_type'
                                        ] === 'percentage'
                                            ? htmlspecialchars(
                                                (string) $voucher[
                                                    'voucher_value'
                                                ],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) . '%'
                                            : 'RM ' .
                                                moneyFormatSen(
                                                    $valueSen
                                                ) ?>
                                    </p>

                                    <?php if (
                                        $maximumDiscountSen !== null &&
                                        $maximumDiscountSen > 0
                                    ): ?>
                                        <p
                                            class="text-xs text-gray-400"
                                        >
                                            Max: RM
                                            <?= moneyFormatSen(
                                                $maximumDiscountSen
                                            ) ?>
                                        </p>
                                    <?php endif; ?>
                                </td>

                                <td
                                    class="px-5 py-4 text-sm text-gray-600"
                                >
                                    <?= $minimumOrderSen > 0
                                        ? 'RM ' .
                                            moneyFormatSen(
                                                $minimumOrderSen
                                            )
                                        : '—' ?>
                                </td>

                                <td class="px-5 py-4 text-sm">
                                    <span
                                        class="font-semibold text-gray-800"
                                    >
                                        <?= (int) $voucher[
                                            'actual_usage'
                                        ] ?>
                                    </span>

                                    <span class="text-gray-400">
                                        /
                                        <?= $voucher[
                                            'voucher_usage_limit'
                                        ] !== null
                                            ? (int) $voucher[
                                                'voucher_usage_limit'
                                            ]
                                            : '∞' ?>
                                    </span>
                                </td>

                                <td
                                    class="px-5 py-4 text-xs text-gray-500"
                                >
                                    <?php if (!empty(
                                        $voucher[
                                            'voucher_start_date'
                                        ]
                                    )): ?>
                                        <p>
                                            From:
                                            <?= date(
                                                'd M Y',
                                                strtotime(
                                                    (string) $voucher[
                                                        'voucher_start_date'
                                                    ]
                                                )
                                            ) ?>
                                        </p>
                                    <?php endif; ?>

                                    <?php if (!empty(
                                        $voucher[
                                            'voucher_end_date'
                                        ]
                                    )): ?>
                                        <p
                                            class="<?= $isExpired ? 'font-semibold text-red-500' : '' ?>"
                                        >
                                            Until:
                                            <?= date(
                                                'd M Y',
                                                strtotime(
                                                    (string) $voucher[
                                                        'voucher_end_date'
                                                    ]
                                                )
                                            ) ?>
                                            <?= $isExpired
                                                ? '(Expired)'
                                                : '' ?>
                                        </p>
                                    <?php else: ?>
                                        <p>No expiry</p>
                                    <?php endif; ?>
                                </td>

                                <td class="px-5 py-4">
                                    <?php if ($isExpired): ?>
                                        <span
                                            class="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-500"
                                        >
                                            Expired
                                        </span>
                                    <?php elseif ($isMaxed): ?>
                                        <span
                                            class="rounded-full bg-orange-100 px-2 py-1 text-xs font-semibold text-orange-600"
                                        >
                                            Maxed Out
                                        </span>
                                    <?php elseif ((int) $voucher[
                                        'voucher_is_active'
                                    ] === 1): ?>
                                        <span
                                            class="rounded-full bg-green-100 px-2 py-1 text-xs font-semibold text-green-700"
                                        >
                                            Active
                                        </span>
                                    <?php else: ?>
                                        <span
                                            class="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-500"
                                        >
                                            Inactive
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="px-5 py-4">
                                    <div class="flex gap-2">
                                        <button
                                            type="button"
                                            data-voucher="<?= htmlspecialchars(
                                                json_encode(
                                                    $voucher,
                                                    JSON_HEX_TAG |
                                                    JSON_HEX_AMP |
                                                    JSON_HEX_APOS |
                                                    JSON_HEX_QUOT
                                                ),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                            class="edit-voucher-button rounded-lg border border-blue-200 px-3 py-1.5 text-xs text-blue-600 transition-colors hover:bg-blue-50"
                                        >
                                            ✏️ Edit
                                        </button>

                                        <form method="POST">
                                            <?php csrf_field(); ?>
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="toggle"
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
                                                class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs text-gray-600 transition-colors hover:bg-gray-50"
                                            >
                                                <?= (int) $voucher[
                                                    'voucher_is_active'
                                                ] === 1
                                                    ? '🙈 Disable'
                                                    : '👁️ Enable' ?>
                                            </button>
                                        </form>

                                        <form
                                            method="POST"
                                            onsubmit="return confirm('Delete this voucher?')"
                                        >
                                            <?php csrf_field(); ?>
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete"
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
                                                class="rounded-lg border border-red-200 px-3 py-1.5 text-xs text-red-600 transition-colors hover:bg-red-50"
                                                aria-label="Delete voucher"
                                            >
                                                🗑️
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>

    <div
        id="voucherModal"
        class="voucher-modal fixed inset-0 z-50 items-center justify-center bg-black/50 px-4"
    >
        <div
            class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white shadow-2xl"
        >
            <div
                class="flex items-center justify-between border-b border-gray-100 p-5"
            >
                <h3
                    id="modalTitle"
                    class="font-black text-gray-800"
                >
                    Create Voucher
                </h3>

                <button
                    type="button"
                    onclick="closeModal()"
                    class="text-xl text-gray-400 hover:text-gray-600"
                    aria-label="Close voucher form"
                >
                    ✕
                </button>
            </div>

            <form
                method="POST"
                class="space-y-4 p-5"
                id="voucherForm"
            >
                <?php csrf_field(); ?>

                <input
                    type="hidden"
                    name="action"
                    id="formAction"
                    value="add"
                >

                <input
                    type="hidden"
                    name="voucher_id"
                    id="formId"
                >

                <div>
                    <label
                        class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500"
                    >
                        Voucher Code *
                    </label>

                    <input
                        type="text"
                        name="voucher_code"
                        id="formCode"
                        maxlength="50"
                        pattern="[A-Za-z0-9_-]+"
                        required
                        placeholder="e.g. BIRTHDAY10"
                        class="w-full rounded-xl border-2 border-gray-100 bg-gray-50 px-4 py-3 text-sm uppercase focus:border-red-400 focus:bg-white focus:outline-none"
                    >
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label
                            class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500"
                        >
                            Discount Type *
                        </label>

                        <select
                            name="voucher_type"
                            id="formType"
                            onchange="toggleMaxDiscount()"
                            class="w-full rounded-xl border-2 border-gray-100 bg-gray-50 px-4 py-3 text-sm focus:border-red-400 focus:bg-white focus:outline-none"
                        >
                            <option value="percentage">
                                Percentage (%)
                            </option>
                            <option value="fixed">
                                Fixed Amount (RM)
                            </option>
                        </select>
                    </div>

                    <div>
                        <label
                            class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500"
                        >
                            Value *
                        </label>

                        <input
                            type="number"
                            name="voucher_value"
                            id="formValue"
                            required
                            step="0.01"
                            min="0.01"
                            max="99999999.99"
                            placeholder="e.g. 10"
                            class="w-full rounded-xl border-2 border-gray-100 bg-gray-50 px-4 py-3 text-sm focus:border-red-400 focus:bg-white focus:outline-none"
                        >
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label
                            class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500"
                        >
                            Min Order (RM)
                        </label>

                        <input
                            type="number"
                            name="voucher_min_order"
                            id="formMinOrder"
                            step="0.01"
                            min="0"
                            max="99999999.99"
                            value="0"
                            class="w-full rounded-xl border-2 border-gray-100 bg-gray-50 px-4 py-3 text-sm focus:border-red-400 focus:bg-white focus:outline-none"
                        >
                    </div>

                    <div id="maxDiscountDiv">
                        <label
                            class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500"
                        >
                            Max Discount (RM)
                        </label>

                        <input
                            type="number"
                            name="voucher_max_discount"
                            id="formMaxDiscount"
                            step="0.01"
                            min="0"
                            max="99999999.99"
                            placeholder="No limit"
                            class="w-full rounded-xl border-2 border-gray-100 bg-gray-50 px-4 py-3 text-sm focus:border-red-400 focus:bg-white focus:outline-none"
                        >
                    </div>
                </div>

                <div>
                    <label
                        class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500"
                    >
                        Usage Limit
                    </label>

                    <input
                        type="number"
                        name="voucher_usage_limit"
                        id="formUsageLimit"
                        min="1"
                        max="2147483647"
                        placeholder="Unlimited"
                        class="w-full rounded-xl border-2 border-gray-100 bg-gray-50 px-4 py-3 text-sm focus:border-red-400 focus:bg-white focus:outline-none"
                    >
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label
                            class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500"
                        >
                            Start Date
                        </label>

                        <input
                            type="datetime-local"
                            name="voucher_start_date"
                            id="formStartDate"
                            class="w-full rounded-xl border-2 border-gray-100 bg-gray-50 px-4 py-3 text-sm focus:border-red-400 focus:bg-white focus:outline-none"
                        >
                    </div>

                    <div>
                        <label
                            class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500"
                        >
                            End Date
                        </label>

                        <input
                            type="datetime-local"
                            name="voucher_end_date"
                            id="formEndDate"
                            class="w-full rounded-xl border-2 border-gray-100 bg-gray-50 px-4 py-3 text-sm focus:border-red-400 focus:bg-white focus:outline-none"
                        >
                    </div>
                </div>

                <label
                    id="birthdayTemplateLabel"
                    class="flex cursor-pointer items-start gap-3 rounded-xl border border-pink-100 bg-pink-50 p-4"
                >
                    <input
                        type="checkbox"
                        name="voucher_is_birthday_template"
                        id="formBirthdayTemplate"
                        class="mt-0.5 h-4 w-4 accent-pink-600"
                    >

                    <span>
                        <span
                            class="block text-sm font-semibold text-gray-700"
                        >
                            Birthday Voucher Template
                        </span>

                        <span
                            class="mt-1 block text-xs leading-5 text-gray-500"
                        >
                            Only vouchers marked here appear in the
                            Tier Management birthday voucher dropdown.
                        </span>
                    </span>
                </label>

                <label
                    class="flex cursor-pointer items-center gap-3"
                >
                    <input
                        type="checkbox"
                        name="voucher_is_active"
                        id="formActive"
                        checked
                        class="h-4 w-4 accent-red-600"
                    >

                    <span
                        class="text-sm font-medium text-gray-700"
                    >
                        Active
                    </span>
                </label>

                <div class="flex gap-3 pt-2">
                    <button
                        type="button"
                        onclick="closeModal()"
                        class="flex-1 rounded-xl border-2 border-gray-100 py-3 text-sm font-semibold text-gray-600 hover:bg-gray-50"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="flex-1 rounded-xl bg-red-600 py-3 text-sm font-semibold text-white transition-colors hover:bg-red-700"
                    >
                        Save Voucher
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const voucherModal =
            document.getElementById(
                'voucherModal'
            );
        const birthdayTemplateLabel =
            document.getElementById(
                'birthdayTemplateLabel'
            );
        const birthdayTemplateInput =
            document.getElementById(
                'formBirthdayTemplate'
            );

        function resetVoucherForm() {
            document.getElementById(
                'voucherForm'
            ).reset();
            document.getElementById(
                'formAction'
            ).value = 'add';
            document.getElementById(
                'formId'
            ).value = '';
            document.getElementById(
                'formCode'
            ).readOnly = false;
            document.getElementById(
                'formMinOrder'
            ).value = '0';
            document.getElementById(
                'formActive'
            ).checked = true;
            birthdayTemplateInput.checked = false;
            birthdayTemplateInput.disabled = false;
            birthdayTemplateLabel.classList.remove(
                'hidden'
            );
            toggleMaxDiscount();
        }

        function openAddModal() {
            resetVoucherForm();
            document.getElementById(
                'modalTitle'
            ).textContent = 'Create Voucher';
            voucherModal.classList.add('active');
        }

        function openEditModal(voucher) {
            resetVoucherForm();

            document.getElementById(
                'modalTitle'
            ).textContent = 'Edit Voucher';
            document.getElementById(
                'formAction'
            ).value = 'edit';
            document.getElementById(
                'formId'
            ).value = voucher.voucher_id;
            document.getElementById(
                'formCode'
            ).value = voucher.voucher_code;
            document.getElementById(
                'formCode'
            ).readOnly = true;
            document.getElementById(
                'formType'
            ).value = voucher.voucher_type;
            document.getElementById(
                'formValue'
            ).value = voucher.voucher_value;
            document.getElementById(
                'formMinOrder'
            ).value = voucher.voucher_min_order;
            document.getElementById(
                'formMaxDiscount'
            ).value =
                voucher.voucher_max_discount || '';
            document.getElementById(
                'formUsageLimit'
            ).value =
                voucher.voucher_usage_limit || '';
            document.getElementById(
                'formStartDate'
            ).value = voucher.voucher_start_date
                ? voucher.voucher_start_date.slice(
                    0,
                    16
                )
                : '';
            document.getElementById(
                'formEndDate'
            ).value = voucher.voucher_end_date
                ? voucher.voucher_end_date.slice(
                    0,
                    16
                )
                : '';
            document.getElementById(
                'formActive'
            ).checked =
                Number(
                    voucher.voucher_is_active
                ) === 1;

            const isPointsVoucher =
                Number(
                    voucher.voucher_is_points_redeem
                ) === 1;

            birthdayTemplateInput.checked =
                !isPointsVoucher &&
                Number(
                    voucher.voucher_is_birthday_template
                ) === 1;
            birthdayTemplateInput.disabled =
                isPointsVoucher;

            birthdayTemplateLabel.classList.toggle(
                'hidden',
                isPointsVoucher
            );

            toggleMaxDiscount();
            voucherModal.classList.add('active');
        }

        function closeModal() {
            voucherModal.classList.remove('active');
        }

        function toggleMaxDiscount() {
            const voucherType =
                document.getElementById(
                    'formType'
                ).value;

            document.getElementById(
                'maxDiscountDiv'
            ).style.display =
                voucherType === 'percentage'
                    ? 'block'
                    : 'none';
        }

        document.querySelectorAll(
            '.edit-voucher-button'
        ).forEach(button => {
            button.addEventListener(
                'click',
                () => {
                    const rawVoucher =
                        button.dataset.voucher;

                    if (!rawVoucher) {
                        return;
                    }

                    openEditModal(
                        JSON.parse(rawVoucher)
                    );
                }
            );
        });

        voucherModal.addEventListener(
            'click',
            event => {
                if (event.target === voucherModal) {
                    closeModal();
                }
            }
        );
    </script>

</body>
</html>