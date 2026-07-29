<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/money_helper.php';
require_once __DIR__ . '/../includes/logger.php';

require_admin();

$success = '';
$error = '';

function requireTierName(mixed $value): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException(
            'Please select a valid tier.'
        );
    }

    $value = trim($value);

    if (
        !in_array(
            $value,
            [
                'bronze',
                'silver',
                'gold',
                'platinum',
            ],
            true
        )
    ) {
        throw new InvalidArgumentException(
            'Please select a valid tier.'
        );
    }

    return $value;
}

function normalizeTierMoney(
    mixed $value,
    string $label
): string {
    try {
        return moneyNormalizeDecimal(
            (string) $value
        );
    } catch (MoneyValueException $e) {
        throw new InvalidArgumentException(
            $label .
                ' must be a non-negative amount with up to two decimal places.',
            0,
            $e
        );
    }
}

function normalizeTierMultiplier(mixed $value): string
{
    if (
        !is_string($value) &&
        !is_int($value) &&
        !is_float($value)
    ) {
        throw new InvalidArgumentException(
            'Points multiplier must be between 1.0 and 99.9.'
        );
    }

    $value = trim((string) $value);

    if (
        !preg_match(
            '/\A(\d{1,2})(?:\.(\d))?\z/',
            $value,
            $matches
        )
    ) {
        throw new InvalidArgumentException(
            'Points multiplier must be between 1.0 and 99.9 with one decimal place.'
        );
    }

    $whole = (int) $matches[1];

    if ($whole < 1 || $whole > 99) {
        throw new InvalidArgumentException(
            'Points multiplier must be between 1.0 and 99.9.'
        );
    }

    return $whole . '.' . ($matches[2] ?? '0');
}

function requireNonNegativeInt(
    mixed $value,
    string $label
): int {
    $validated = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 0,
                'max_range' => 2147483647,
            ],
        ]
    );

    if ($validated === false) {
        throw new InvalidArgumentException(
            $label .
                ' must be a valid non-negative whole number.'
        );
    }

    return (int) $validated;
}

function requireBoundedPositiveInt(
    mixed $value,
    string $label,
    int $maximum
): int {
    $validated = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
                'max_range' => $maximum,
            ],
        ]
    );

    if ($validated === false) {
        throw new InvalidArgumentException(
            $label .
                ' must be between 1 and ' .
                $maximum .
                '.'
        );
    }

    return (int) $validated;
}

function requirePositiveId(
    mixed $value,
    string $label
): int {
    $validated = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if ($validated === false) {
        throw new InvalidArgumentException(
            $label . ' is invalid.'
        );
    }

    return (int) $validated;
}

function normalizeBenefitText(mixed $value): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException(
            'Benefit text is required.'
        );
    }

    $value = trim($value);

    if ($value === '') {
        throw new InvalidArgumentException(
            'Benefit text is required.'
        );
    }

    $length = function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);

    if ($length > 255) {
        throw new InvalidArgumentException(
            'Benefit text cannot exceed 255 characters.'
        );
    }

    return $value;
}

function normalizeOptionalVoucherId(
    PDO $pdo,
    mixed $value
): ?int {
    if ($value === null || $value === '') {
        return null;
    }

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
            'Please select a valid birthday voucher.'
        );
    }

    $voucherStatement = $pdo->prepare("
        SELECT voucher_id
        FROM vouchers
        WHERE voucher_id = ?
        AND voucher_is_active = 1
        AND voucher_is_points_redeem = 0
        AND voucher_is_system_generated = 0
        AND voucher_is_birthday_template = 1
        AND (
            voucher_end_date IS NULL
            OR voucher_end_date >= NOW()
        )
        LIMIT 1
    ");
    $voucherStatement->execute([
        (int) $voucherId,
    ]);

    if ($voucherStatement->fetchColumn() === false) {
        throw new InvalidArgumentException(
            'The selected birthday voucher is unavailable.'
        );
    }

    return (int) $voucherId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_config') {
            $tierName = requireTierName(
                $_POST['tier_name'] ?? null
            );

            $minimumSpending =
                $tierName === 'bronze'
                    ? '0.00'
                    : normalizeTierMoney(
                        $_POST[
                            'tier_min_spending'
                        ] ?? null,
                        'Minimum spending'
                    );

            if (
                $tierName !== 'bronze' &&
                moneyDecimalToSen(
                    $minimumSpending
                ) < 100
            ) {
                throw new InvalidArgumentException(
                    'Minimum spending must be at least RM 1.00.'
                );
            }

            $pointsMultiplier =
                normalizeTierMultiplier(
                    $_POST[
                        'tier_points_multiplier'
                    ] ?? null
                );

            $birthdayBonusPoints =
                requireNonNegativeInt(
                    $_POST[
                        'tier_birthday_bonus_points'
                    ] ?? null,
                    'Birthday bonus points'
                );

            $birthdayVoucherId =
                normalizeOptionalVoucherId(
                    $pdo,
                    $_POST[
                        'tier_birthday_voucher_id'
                    ] ?? null
                );

            $birthdayVoucherValidDays =
                requireBoundedPositiveInt(
                    $_POST[
                        'tier_birthday_voucher_valid_days'
                    ] ?? null,
                    'Birthday voucher validity',
                    365
                );

            $shippingDiscount =
                normalizeTierMoney(
                    $_POST[
                        'tier_shipping_discount'
                    ] ?? null,
                    'Shipping discount'
                );

            $freeShipping = isset(
                $_POST['tier_free_shipping']
            ) ? 1 : 0;

            if ($freeShipping === 1) {
                $shippingDiscount = '0.00';
            }

            $updateConfig = $pdo->prepare("
                UPDATE tier_config
                SET tier_min_spending = ?,
                    tier_points_multiplier = ?,
                    tier_birthday_bonus_points = ?,
                    tier_birthday_voucher_id = ?,
                    tier_birthday_voucher_valid_days = ?,
                    tier_shipping_discount = ?,
                    tier_free_shipping = ?
                WHERE tier_name = ?
            ");

            $updateConfig->execute([
                $minimumSpending,
                $pointsMultiplier,
                $birthdayBonusPoints,
                $birthdayVoucherId,
                $birthdayVoucherValidDays,
                $shippingDiscount,
                $freeShipping,
                $tierName,
            ]);

            $success =
                ucfirst($tierName) .
                ' tier settings updated successfully.';
        } elseif ($action === 'add_benefit') {
            $tierName = requireTierName(
                $_POST['benefit_tier'] ?? null
            );

            $benefitText =
                normalizeBenefitText(
                    $_POST[
                        'benefit_text'
                    ] ?? null
                );

            $maximumOrder = $pdo->prepare("
                SELECT MAX(benefit_order)
                FROM tier_benefits
                WHERE benefit_tier = ?
            ");
            $maximumOrder->execute([
                $tierName,
            ]);

            $nextOrder =
                (int) (
                    $maximumOrder->fetchColumn() ??
                    0
                ) + 1;

            $insertBenefit = $pdo->prepare("
                INSERT INTO tier_benefits (
                    benefit_tier,
                    benefit_text,
                    benefit_order
                )
                VALUES (?, ?, ?)
            ");
            $insertBenefit->execute([
                $tierName,
                $benefitText,
                $nextOrder,
            ]);

            $success =
                'Benefit added successfully.';
        } elseif ($action === 'delete_benefit') {
            $benefitId = requirePositiveId(
                $_POST['benefit_id'] ?? null,
                'Benefit ID'
            );

            $deleteBenefit = $pdo->prepare("
                DELETE FROM tier_benefits
                WHERE benefit_id = ?
            ");
            $deleteBenefit->execute([
                $benefitId,
            ]);

            if (
                $deleteBenefit->rowCount() !== 1
            ) {
                throw new InvalidArgumentException(
                    'Benefit not found.'
                );
            }

            $success =
                'Benefit removed successfully.';
        } elseif ($action === 'edit_benefit') {
            $benefitId = requirePositiveId(
                $_POST['benefit_id'] ?? null,
                'Benefit ID'
            );

            $benefitText =
                normalizeBenefitText(
                    $_POST[
                        'benefit_text'
                    ] ?? null
                );

            $updateBenefit = $pdo->prepare("
                UPDATE tier_benefits
                SET benefit_text = ?
                WHERE benefit_id = ?
            ");
            $updateBenefit->execute([
                $benefitText,
                $benefitId,
            ]);

            $success =
                'Benefit updated successfully.';
        } else {
            throw new InvalidArgumentException(
                'Invalid tier management action.'
            );
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        app_error_log(
            'Tier management database error: ' .
            $e->getMessage()
        );

        $error =
            'Unable to update tier settings. Please try again.';
    }
}

$tierConfigs = $pdo->query("
    SELECT *
    FROM tier_config
    ORDER BY tier_min_spending ASC
")->fetchAll(PDO::FETCH_ASSOC);

$birthdayVoucherOptions = $pdo->query("
    SELECT
        voucher_id,
        voucher_code,
        voucher_type,
        voucher_value
    FROM vouchers
    WHERE voucher_is_active = 1
    AND voucher_is_points_redeem = 0
    AND voucher_is_system_generated = 0
    AND voucher_is_birthday_template = 1
    AND (
        voucher_end_date IS NULL
        OR voucher_end_date >= NOW()
    )
    ORDER BY voucher_code ASC
")->fetchAll(PDO::FETCH_ASSOC);

$benefitRows = $pdo->query("
    SELECT *
    FROM tier_benefits
    ORDER BY
        benefit_tier,
        benefit_order ASC,
        benefit_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$benefits = [];

foreach ($benefitRows as $row) {
    $benefits[
        $row['benefit_tier']
    ][] = $row;
}

$tierDisplay = [
    'bronze' => [
        'label' => 'Bronze',
        'emoji' => '🥉',
        'color' => 'text-orange-600',
        'background' => 'bg-orange-50',
        'border' => 'border-orange-200',
    ],
    'silver' => [
        'label' => 'Silver',
        'emoji' => '🥈',
        'color' => 'text-gray-500',
        'background' => 'bg-gray-50',
        'border' => 'border-gray-200',
    ],
    'gold' => [
        'label' => 'Gold',
        'emoji' => '🥇',
        'color' => 'text-yellow-600',
        'background' => 'bg-yellow-50',
        'border' => 'border-yellow-200',
    ],
    'platinum' => [
        'label' => 'Platinum',
        'emoji' => '💎',
        'color' => 'text-blue-600',
        'background' => 'bg-blue-50',
        'border' => 'border-blue-200',
    ],
];

function birthdayVoucherLabel(array $voucher): string
{
    $value = (string) $voucher['voucher_value'];

    if (
        $voucher['voucher_type'] ===
        'percentage'
    ) {
        return
            $voucher['voucher_code'] .
            ' — ' .
            $value .
            '% off';
    }

    return
        $voucher['voucher_code'] .
        ' — RM ' .
        $value .
        ' off';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Tier Management - MangaVault Admin</title>

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
    </style>
</head>

<body class="min-h-screen bg-gray-100">

    <nav class="flex items-center justify-between bg-white px-6 py-4 shadow-sm">
        <div class="flex items-center gap-4">
            <a
                href="dashboard.php"
                class="text-xl font-black"
            >
                MANGA<span class="text-red-600">VAULT</span>
            </a>

            <span class="text-gray-300">|</span>

            <span class="text-sm font-medium text-gray-600">
                Tier Management
            </span>
        </div>

        <a
            href="dashboard.php"
            class="text-sm text-gray-500 transition hover:text-red-600"
        >
            ← Back to Dashboard
        </a>
    </nav>

    <main class="mx-auto max-w-6xl px-6 py-8">
        <?php if ($success !== ''): ?>
            <div
                class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"
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
                class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
            >
                ⚠️
                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>
        <?php endif; ?>

        <header class="mb-8">
            <h1
                class="text-2xl font-black text-gray-800"
            >
                Tier Management
            </h1>

            <p
                class="mt-1 text-sm text-gray-500"
            >
                Manage spending thresholds, points, birthday rewards,
                shipping benefits and customer-facing tier benefits.
            </p>
        </header>

        <?php foreach (
            $tierConfigs as $config
        ): ?>
            <?php
            $tierName =
                (string) $config['tier_name'];
            $display =
                $tierDisplay[$tierName];
            $tierBenefits =
                $benefits[$tierName] ?? [];
            $freeShipping =
                (int) (
                    $config[
                        'tier_free_shipping'
                    ] ?? 0
                ) === 1;
            ?>

            <section
                class="<?= $display[
                    'background'
                ] ?> <?= $display[
                    'border'
                ] ?> mb-6 overflow-hidden rounded-2xl border-2"
            >
                <div
                    class="<?= $display[
                        'border'
                    ] ?> flex items-center gap-3 border-b px-6 py-4"
                >
                    <span class="text-3xl">
                        <?= $display['emoji'] ?>
                    </span>

                    <h2
                        class="text-xl font-black <?= $display[
                            'color'
                        ] ?>"
                    >
                        <?= htmlspecialchars(
                            $display['label'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                        Tier
                    </h2>
                </div>

                <div
                    class="grid grid-cols-1 gap-6 p-6 lg:grid-cols-2"
                >
                    <div
                        class="rounded-xl bg-white p-5 shadow-sm"
                    >
                        <h3
                            class="mb-4 text-sm font-bold uppercase tracking-wide text-gray-700"
                        >
                            Tier Settings
                        </h3>

                        <form
                            method="POST"
                            class="space-y-4"
                        >
                            <?php csrf_field(); ?>

                            <input
                                type="hidden"
                                name="action"
                                value="update_config"
                            >

                            <input
                                type="hidden"
                                name="tier_name"
                                value="<?= htmlspecialchars(
                                    $tierName,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                            >

                            <div>
                                <label
                                    class="mb-1 block text-xs font-semibold text-gray-500"
                                >
                                    Min Spending (RM)
                                </label>

                                <?php if (
                                    $tierName === 'bronze'
                                ): ?>
                                    <input
                                        type="number"
                                        value="0.00"
                                        disabled
                                        class="w-full cursor-not-allowed rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-400"
                                    >

                                    <input
                                        type="hidden"
                                        name="tier_min_spending"
                                        value="0.00"
                                    >

                                    <p
                                        class="mt-1 text-xs text-gray-400"
                                    >
                                        Bronze always starts at RM 0.
                                    </p>
                                <?php else: ?>
                                    <input
                                        type="number"
                                        name="tier_min_spending"
                                        value="<?= htmlspecialchars(
                                            (string) $config[
                                                'tier_min_spending'
                                            ],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        step="0.01"
                                        min="1"
                                        max="99999999.99"
                                        required
                                        class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-300"
                                    >
                                <?php endif; ?>
                            </div>

                            <div>
                                <label
                                    class="mb-1 block text-xs font-semibold text-gray-500"
                                >
                                    Points Multiplier
                                </label>

                                <input
                                    type="number"
                                    name="tier_points_multiplier"
                                    value="<?= htmlspecialchars(
                                        (string) $config[
                                            'tier_points_multiplier'
                                        ],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    step="0.1"
                                    min="1"
                                    max="99.9"
                                    required
                                    class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-300"
                                >

                                <p
                                    class="mt-1 text-xs text-gray-400"
                                >
                                    1.5 means eligible spending × 1.5;
                                    2 means eligible spending × 2.0.
                                </p>
                            </div>

                            <div>
                                <label
                                    class="mb-1 block text-xs font-semibold text-gray-500"
                                >
                                    Birthday Bonus Points
                                </label>

                                <input
                                    type="number"
                                    name="tier_birthday_bonus_points"
                                    value="<?= (int) $config[
                                        'tier_birthday_bonus_points'
                                    ] ?>"
                                    min="0"
                                    max="2147483647"
                                    required
                                    class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-300"
                                >

                                <p
                                    class="mt-1 text-xs text-gray-400"
                                >
                                    Awarded automatically once per
                                    calendar year.
                                </p>
                            </div>

                            <div>
                                <label
                                    class="mb-1 block text-xs font-semibold text-gray-500"
                                >
                                    Birthday Voucher
                                </label>

                                <select
                                    name="tier_birthday_voucher_id"
                                    class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-300"
                                >
                                    <option value="">
                                        No birthday voucher
                                    </option>

                                    <?php foreach (
                                        $birthdayVoucherOptions
                                        as $voucher
                                    ): ?>
                                        <option
                                            value="<?= (int) $voucher[
                                                'voucher_id'
                                            ] ?>"
                                            <?= (int) (
                                                $config[
                                                    'tier_birthday_voucher_id'
                                                ] ?? 0
                                            ) ===
                                            (int) $voucher[
                                                'voucher_id'
                                            ]
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            <?= htmlspecialchars(
                                                birthdayVoucherLabel(
                                                    $voucher
                                                ),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <p
                                    class="mt-1 text-xs text-gray-400"
                                >
                                    Create a voucher in Voucher Management and mark it as a Birthday Voucher Template.
                                </p>
                            </div>

                            <div>
                                <label
                                    class="mb-1 block text-xs font-semibold text-gray-500"
                                >
                                    Birthday Voucher Validity (Days)
                                </label>

                                <input
                                    type="number"
                                    name="tier_birthday_voucher_valid_days"
                                    value="<?= (int) (
                                        $config[
                                            'tier_birthday_voucher_valid_days'
                                        ] ?? 30
                                    ) ?>"
                                    min="1"
                                    max="365"
                                    required
                                    class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-300"
                                >
                            </div>

                            <div>
                                <label
                                    class="mb-1 block text-xs font-semibold text-gray-500"
                                >
                                    Shipping Discount (RM)
                                </label>

                                <input
                                    type="number"
                                    name="tier_shipping_discount"
                                    value="<?= htmlspecialchars(
                                        $freeShipping
                                            ? '0.00'
                                            : (string) $config[
                                                'tier_shipping_discount'
                                            ],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    step="0.01"
                                    min="0"
                                    max="99999999.99"
                                    required
                                    class="tier-shipping-discount w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-300"
                                >

                                <p
                                    class="mt-1 text-xs text-gray-400"
                                >
                                    Deducted from each physical order's
                                    selected delivery fee.
                                </p>
                            </div>

                            <label
                                class="flex items-start gap-3 rounded-lg border border-gray-100 bg-gray-50 p-3"
                            >
                                <input
                                    type="checkbox"
                                    name="tier_free_shipping"
                                    value="1"
                                    <?= $freeShipping
                                        ? 'checked'
                                        : '' ?>
                                    class="tier-free-shipping mt-0.5 h-4 w-4 accent-red-600"
                                >

                                <span>
                                    <span
                                        class="block text-sm font-semibold text-gray-700"
                                    >
                                        Free Shipping
                                    </span>

                                    <span
                                        class="mt-0.5 block text-xs leading-5 text-gray-400"
                                    >
                                        Applies to every physical delivery
                                        method. It overrides the shipping
                                        discount.
                                    </span>
                                </span>
                            </label>

                            <button
                                type="submit"
                                class="w-full rounded-lg bg-red-600 py-2.5 text-sm font-bold text-white transition hover:bg-red-700"
                            >
                                Save Settings
                            </button>
                        </form>
                    </div>

                    <div
                        class="rounded-xl bg-white p-5 shadow-sm"
                    >
                        <h3
                            class="mb-4 text-sm font-bold uppercase tracking-wide text-gray-700"
                        >
                            Benefits
                        </h3>

                        <div class="mb-4 space-y-2">
                            <?php if (
                                $tierBenefits === []
                            ): ?>
                                <p
                                    class="text-sm italic text-gray-400"
                                >
                                    No benefits added yet.
                                </p>
                            <?php endif; ?>

                            <?php foreach (
                                $tierBenefits as $benefit
                            ): ?>
                                <div
                                    class="group flex items-center gap-2"
                                >
                                    <form
                                        method="POST"
                                        class="flex flex-1 gap-2"
                                    >
                                        <?php csrf_field(); ?>

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="edit_benefit"
                                        >

                                        <input
                                            type="hidden"
                                            name="benefit_id"
                                            value="<?= (int) $benefit[
                                                'benefit_id'
                                            ] ?>"
                                        >

                                        <input
                                            type="text"
                                            name="benefit_text"
                                            value="<?= htmlspecialchars(
                                                (string) $benefit[
                                                    'benefit_text'
                                                ],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                            maxlength="255"
                                            required
                                            class="min-w-0 flex-1 rounded-lg border border-gray-200 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-red-300"
                                        >

                                        <button
                                            type="submit"
                                            class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-green-100 hover:text-green-700"
                                        >
                                            Save
                                        </button>
                                    </form>

                                    <form
                                        method="POST"
                                        onsubmit="return confirm('Remove this benefit?')"
                                    >
                                        <?php csrf_field(); ?>

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete_benefit"
                                        >

                                        <input
                                            type="hidden"
                                            name="benefit_id"
                                            value="<?= (int) $benefit[
                                                'benefit_id'
                                            ] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="text-lg leading-none text-red-400 transition hover:text-red-600"
                                            aria-label="Remove benefit"
                                        >
                                            ×
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <form
                            method="POST"
                            class="flex gap-2"
                        >
                            <?php csrf_field(); ?>

                            <input
                                type="hidden"
                                name="action"
                                value="add_benefit"
                            >

                            <input
                                type="hidden"
                                name="benefit_tier"
                                value="<?= htmlspecialchars(
                                    $tierName,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                            >

                            <input
                                type="text"
                                name="benefit_text"
                                placeholder="Add new benefit..."
                                maxlength="255"
                                required
                                class="min-w-0 flex-1 rounded-lg border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-300"
                            >

                            <button
                                type="submit"
                                class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-red-700"
                            >
                                + Add
                            </button>
                        </form>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>
    </main>

    <script>
        document.querySelectorAll(
            'form'
        ).forEach(form => {
            const freeShipping =
                form.querySelector(
                    '.tier-free-shipping'
                );
            const shippingDiscount =
                form.querySelector(
                    '.tier-shipping-discount'
                );

            if (
                !freeShipping ||
                !shippingDiscount
            ) {
                return;
            }

            freeShipping.addEventListener(
                'change',
                () => {
                    if (freeShipping.checked) {
                        shippingDiscount.value =
                            '0.00';
                    }
                }
            );
        });
    </script>

</body>
</html>