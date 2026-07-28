<?php

require_once __DIR__ . '/includes/session.php';

start_secure_session();

require_once __DIR__ . '/includes/db.php';

// Get current user tier if logged in.
$user_tier = null;
$user_spending = 0;

if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("
        SELECT
            user_tier,
            user_lifetime_spending
        FROM users
        WHERE user_id = ?
    ");
    $stmt->execute([
        (int) $_SESSION['user_id'],
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $user_tier =
            $user['user_tier'] ?? 'bronze';
        $user_spending =
            (float) (
                $user['user_lifetime_spending'] ?? 0
            );
    }
}

// Load tier config from database.
$tier_config_rows = $pdo->query("
    SELECT *
    FROM tier_config
    ORDER BY tier_min_spending ASC
")->fetchAll(PDO::FETCH_ASSOC);

$tier_config = [];

foreach ($tier_config_rows as $row) {
    $tier_config[$row['tier_name']] = $row;
}

// Load tier benefits from database.
$benefit_rows = $pdo->query("
    SELECT *
    FROM tier_benefits
    ORDER BY benefit_tier,
             benefit_order ASC
")->fetchAll(PDO::FETCH_ASSOC);

$benefits = [];

foreach ($benefit_rows as $row) {
    $benefits[$row['benefit_tier']][] =
        $row['benefit_text'];
}

// Static display config for colours and icons.
$tier_display = [
    'bronze' => [
        'label' => 'Bronze',
        'emoji' => '🥉',
        'color' =>
            'from-orange-700 to-orange-500',
        'bg' => 'bg-orange-50',
        'border' => 'border-orange-200',
        'text' => 'text-orange-700',
    ],
    'silver' => [
        'label' => 'Silver',
        'emoji' => '🥈',
        'color' =>
            'from-gray-500 to-gray-300',
        'bg' => 'bg-gray-50',
        'border' => 'border-gray-300',
        'text' => 'text-gray-600',
    ],
    'gold' => [
        'label' => 'Gold',
        'emoji' => '🥇',
        'color' =>
            'from-yellow-600 to-yellow-400',
        'bg' => 'bg-yellow-50',
        'border' => 'border-yellow-300',
        'text' => 'text-yellow-700',
    ],
    'platinum' => [
        'label' => 'Platinum',
        'emoji' => '💎',
        'color' =>
            'from-blue-700 to-cyan-400',
        'bg' => 'bg-blue-50',
        'border' => 'border-blue-200',
        'text' => 'text-blue-700',
    ],
];

// Build next tier information for the progress display.
$tier_keys = array_keys($tier_config);
$next_tier = null;
$needed = 0;

if (
    $user_tier &&
    $user_tier !== 'platinum'
) {
    $current_index = array_search(
        $user_tier,
        $tier_keys,
        true
    );

    if (
        $current_index !== false &&
        isset($tier_keys[$current_index + 1])
    ) {
        $next_key =
            $tier_keys[$current_index + 1];
        $next_tier =
            $tier_display[$next_key];
        $needed =
            (float) $tier_config[$next_key][
                'tier_min_spending'
            ] -
            $user_spending;
    }
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
    <title>Membership Tiers - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#F5F0EB]">

    <?php include __DIR__ . '/includes/customer_navbar.php'; ?>

    <!-- Hero -->
    <section class="bg-[#1a3a5c] py-16 text-white">
        <div class="mx-auto max-w-4xl px-6 text-center">
            <p
                class="mb-3 text-sm font-semibold uppercase tracking-widest text-blue-300"
            >
                Loyalty Program
            </p>

            <h1 class="mb-4 text-5xl font-black">
                Membership
                <span class="text-blue-300">Tiers</span>
            </h1>

            <p
                class="mx-auto max-w-xl text-lg text-blue-100/70"
            >
                Spend more, earn more. Every purchase brings you
                closer to the next tier and better rewards.
            </p>

            <?php if ($user_tier): ?>
                <div
                    class="mt-8 inline-block rounded-2xl border border-white/20 bg-white/10 px-8 py-4"
                >
                    <p class="mb-1 text-sm text-blue-200">
                        Your Current Tier
                    </p>

                    <p class="text-2xl font-black">
                        <?= $tier_display[$user_tier][
                            'emoji'
                        ] ?>
                        <?= htmlspecialchars(
                            $tier_display[$user_tier][
                                'label'
                            ],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>

                    <p class="mt-1 text-sm text-blue-200">
                        Lifetime Spending:
                        <span class="font-bold text-white">
                            RM <?= number_format(
                                $user_spending,
                                2
                            ) ?>
                        </span>
                    </p>

                    <?php if (
                        $next_tier &&
                        $needed > 0
                    ): ?>
                        <p class="mt-2 text-xs text-blue-300">
                            Spend
                            <span class="font-bold text-white">
                                RM <?= number_format(
                                    $needed,
                                    2
                                ) ?>
                            </span>
                            more to reach
                            <?= $next_tier['emoji'] ?>
                            <?= htmlspecialchars(
                                $next_tier['label'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </p>
                    <?php elseif (
                        $user_tier === 'platinum'
                    ): ?>
                        <p class="mt-2 text-xs text-yellow-300">
                            🎉 You've reached the highest tier!
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Tier Cards -->
    <section class="py-16">
        <div class="mx-auto max-w-6xl px-6">
            <div
                class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-4"
            >
                <?php
                $tier_config_list =
                    array_values($tier_config);
                $tier_config_keys =
                    array_keys($tier_config);
                ?>

                <?php foreach (
                    $tier_config as $key => $config
                ): ?>
                    <?php
                    $display =
                        $tier_display[$key];
                    $is_current =
                        $user_tier === $key;
                    $tier_benefits =
                        $benefits[$key] ?? [];
                    $current_idx =
                        array_search(
                            $key,
                            $tier_config_keys,
                            true
                        );
                    $next_config =
                        $current_idx !== false
                            ? (
                                $tier_config_list[
                                    $current_idx + 1
                                ] ?? null
                            )
                            : null;
                    $max_label =
                        $next_config
                            ? 'RM ' .
                                number_format(
                                    (float) $next_config[
                                        'tier_min_spending'
                                    ] - 0.01,
                                    2
                                )
                            : null;
                    ?>

                    <div
                        class="<?= $display['bg'] ?>
                            <?= $display['border'] ?>
                            <?= $is_current
                                ? 'ring-4 ring-red-400 ring-offset-2 lg:scale-105'
                                : '' ?>
                            overflow-hidden rounded-2xl border-2 shadow-sm transition-transform"
                    >
                        <div
                            class="bg-gradient-to-br <?= $display[
                                'color'
                            ] ?> p-6 text-center text-white"
                        >
                            <div class="mb-2 text-5xl">
                                <?= $display['emoji'] ?>
                            </div>

                            <h3
                                class="text-xl font-black tracking-wide"
                            >
                                <?= htmlspecialchars(
                                    $display['label'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </h3>

                            <p class="mt-1 text-xs text-white/70">
                                RM <?= number_format(
                                    (float) $config[
                                        'tier_min_spending'
                                    ]
                                ) ?>
                                <?= $max_label
                                    ? '– ' . $max_label
                                    : '+' ?>
                            </p>

                            <?php if ($is_current): ?>
                                <span
                                    class="mt-2 inline-block rounded-full bg-white/20 px-3 py-1 text-xs font-bold text-white"
                                >
                                    ✓ Your Tier
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="p-5">
                            <ul class="space-y-2">
                                <?php foreach (
                                    $tier_benefits as $benefit
                                ): ?>
                                    <li
                                        class="flex items-start gap-2 text-sm <?= $display[
                                            'text'
                                        ] ?>"
                                    >
                                        <span
                                            class="mt-0.5 flex-shrink-0"
                                        >
                                            ✓
                                        </span>

                                        <span>
                                            <?= htmlspecialchars(
                                                (string) $benefit,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- How it works -->
    <section class="bg-white py-16">
        <div class="mx-auto max-w-4xl px-6">
            <h2
                class="mb-10 text-center text-2xl font-black text-gray-800"
            >
                How It Works
            </h2>

            <div
                class="grid grid-cols-1 gap-8 text-center md:grid-cols-3"
            >
                <div>
                    <div class="mb-3 text-4xl">🛒</div>
                    <h3
                        class="mb-2 font-bold text-gray-800"
                    >
                        1. Shop
                    </h3>
                    <p class="text-sm text-gray-500">
                        Every confirmed purchase adds to your
                        lifetime spending total.
                    </p>
                </div>

                <div>
                    <div class="mb-3 text-4xl">📈</div>
                    <h3
                        class="mb-2 font-bold text-gray-800"
                    >
                        2. Level Up
                    </h3>
                    <p class="text-sm text-gray-500">
                        Hit the spending threshold and your tier
                        upgrades automatically.
                    </p>
                </div>

                <div>
                    <div class="mb-3 text-4xl">🎁</div>
                    <h3
                        class="mb-2 font-bold text-gray-800"
                    >
                        3. Enjoy Perks
                    </h3>
                    <p class="text-sm text-gray-500">
                        Get better points multipliers, vouchers,
                        and exclusive benefits.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <?php if (!isset($_SESSION['user_id'])): ?>
        <section class="bg-[#1e2d4a] py-12">
            <div
                class="mx-auto max-w-xl px-6 text-center"
            >
                <h2
                    class="mb-3 text-2xl font-black text-white"
                >
                    Start Earning Today
                </h2>

                <p class="mb-6 text-sm text-gray-300">
                    Create a free account and every purchase
                    counts towards your tier.
                </p>

                <a
                    href="register.php"
                    class="rounded-full bg-red-600 px-10 py-4 text-sm font-black uppercase tracking-widest text-white transition hover:bg-red-700"
                >
                    Create Free Account
                </a>
            </div>
        </section>
    <?php endif; ?>

</body>
</html>