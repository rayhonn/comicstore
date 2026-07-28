<?php

require_once __DIR__ . '/includes/session.php';

start_secure_session();

require_once __DIR__ . '/includes/db.php';

$tier_order = [
    'bronze',
    'silver',
    'gold',
    'platinum',
];

$tier_display = [
    'bronze' => [
        'label' => 'Bronze',
        'emoji' => '🥉',
        'eyebrow' => 'Start collecting',
        'gradient' =>
            'from-[#7c2d12] via-[#c2410c] to-[#fb923c]',
        'soft_background' => 'bg-orange-50',
        'soft_border' => 'border-orange-200',
        'soft_text' => 'text-orange-700',
        'glow' => 'shadow-orange-200/70',
    ],
    'silver' => [
        'label' => 'Silver',
        'emoji' => '🥈',
        'eyebrow' => 'Build your library',
        'gradient' =>
            'from-[#334155] via-[#64748b] to-[#cbd5e1]',
        'soft_background' => 'bg-slate-50',
        'soft_border' => 'border-slate-200',
        'soft_text' => 'text-slate-700',
        'glow' => 'shadow-slate-200/80',
    ],
    'gold' => [
        'label' => 'Gold',
        'emoji' => '🥇',
        'eyebrow' => 'Unlock more rewards',
        'gradient' =>
            'from-[#92400e] via-[#d97706] to-[#fcd34d]',
        'soft_background' => 'bg-amber-50',
        'soft_border' => 'border-amber-200',
        'soft_text' => 'text-amber-700',
        'glow' => 'shadow-amber-200/80',
    ],
    'platinum' => [
        'label' => 'Platinum',
        'emoji' => '💎',
        'eyebrow' => 'The ultimate reader tier',
        'gradient' =>
            'from-[#1e3a8a] via-[#2563eb] to-[#67e8f9]',
        'soft_background' => 'bg-blue-50',
        'soft_border' => 'border-blue-200',
        'soft_text' => 'text-blue-700',
        'glow' => 'shadow-blue-200/80',
    ],
];

$user_tier = null;
$user_spending = 0.0;

if (isset($_SESSION['user_id'])) {
    $user_stmt = $pdo->prepare("
        SELECT
            user_tier,
            user_lifetime_spending
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");
    $user_stmt->execute([
        (int) $_SESSION['user_id'],
    ]);

    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $candidate_tier =
            (string) ($user['user_tier'] ?? '');

        if (array_key_exists(
            $candidate_tier,
            $tier_display
        )) {
            $user_tier = $candidate_tier;
        }

        $user_spending =
            max(
                0.0,
                (float) (
                    $user['user_lifetime_spending'] ??
                    0
                )
            );
    }
}

$tier_config_rows = $pdo->query("
    SELECT
        tier_name,
        tier_min_spending,
        tier_points_multiplier,
        tier_birthday_bonus_points,
        tier_shipping_discount,
        tier_free_shipping
    FROM tier_config
    ORDER BY tier_min_spending ASC
")->fetchAll(PDO::FETCH_ASSOC);

$tier_config = [];

foreach ($tier_config_rows as $row) {
    $tier_name =
        (string) ($row['tier_name'] ?? '');

    if (!array_key_exists(
        $tier_name,
        $tier_display
    )) {
        continue;
    }

    $tier_config[$tier_name] = $row;
}

$benefit_rows = $pdo->query("
    SELECT
        benefit_tier,
        benefit_text
    FROM tier_benefits
    ORDER BY benefit_tier,
             benefit_order ASC,
             benefit_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$benefits = [];

foreach ($benefit_rows as $row) {
    $benefit_tier =
        (string) ($row['benefit_tier'] ?? '');

    if (!array_key_exists(
        $benefit_tier,
        $tier_display
    )) {
        continue;
    }

    $benefits[$benefit_tier][] =
        (string) ($row['benefit_text'] ?? '');
}

$available_tiers = array_values(
    array_filter(
        $tier_order,
        static fn (string $tier_name): bool =>
            isset($tier_config[$tier_name])
    )
);

if (
    isset($_SESSION['user_id']) &&
    (
        $user_tier === null ||
        !isset($tier_config[$user_tier])
    )
) {
    $user_tier =
        isset($tier_config['bronze'])
            ? 'bronze'
            : ($available_tiers[0] ?? null);
}

$current_tier_index = false;
$current_config = null;
$current_display = null;
$next_tier_key = null;
$next_config = null;
$next_display = null;
$amount_needed = 0.0;
$progress_percentage = 0.0;

if (
    $user_tier !== null &&
    isset($tier_config[$user_tier])
) {
    $current_tier_index = array_search(
        $user_tier,
        $available_tiers,
        true
    );
    $current_config =
        $tier_config[$user_tier];
    $current_display =
        $tier_display[$user_tier];

    if (
        $current_tier_index !== false &&
        isset(
            $available_tiers[
                $current_tier_index + 1
            ]
        )
    ) {
        $next_tier_key =
            $available_tiers[
                $current_tier_index + 1
            ];
        $next_config =
            $tier_config[$next_tier_key];
        $next_display =
            $tier_display[$next_tier_key];

        $current_minimum =
            (float) (
                $current_config[
                    'tier_min_spending'
                ] ?? 0
            );
        $next_minimum =
            (float) (
                $next_config[
                    'tier_min_spending'
                ] ?? 0
            );
        $tier_range =
            max(
                0.01,
                $next_minimum - $current_minimum
            );
        $progress_in_range =
            $user_spending - $current_minimum;

        $progress_percentage =
            max(
                0.0,
                min(
                    100.0,
                    (
                        $progress_in_range /
                        $tier_range
                    ) * 100
                )
            );

        $amount_needed =
            max(
                0.0,
                $next_minimum - $user_spending
            );
    } else {
        $progress_percentage = 100.0;
    }
}

function tierMoney(float $amount): string
{
    return 'RM ' . number_format($amount, 2);
}

function tierRangeLabel(
    array $config,
    ?array $next_config
): string {
    $minimum =
        (float) (
            $config['tier_min_spending'] ?? 0
        );

    if ($next_config === null) {
        return tierMoney($minimum) . '+';
    }

    $maximum =
        max(
            $minimum,
            (float) (
                $next_config[
                    'tier_min_spending'
                ] ?? $minimum
            ) - 0.01
        );

    return
        tierMoney($minimum) .
        ' – ' .
        tierMoney($maximum);
}

function tierShippingLabel(array $config): string
{
    if (
        (int) (
            $config['tier_free_shipping'] ?? 0
        ) === 1
    ) {
        return 'Free shipping';
    }

    $discount =
        (float) (
            $config['tier_shipping_discount'] ??
            0
        );

    if ($discount > 0) {
        return tierMoney($discount) .
            ' shipping discount';
    }

    return 'Standard shipping';
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
    <meta
        name="description"
        content="Explore MangaVault membership tiers, points multipliers, birthday rewards and shipping benefits."
    >

    <title>Membership Tiers - MangaVault</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        :root {
            --tier-ink: #111827;
            --tier-navy: #17233b;
            --tier-cream: #f5f0eb;
            --tier-paper: #fffdf9;
            --tier-red: #dc2626;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            opacity: 0;
            background: var(--tier-cream);
            animation:
                tierPageFadeIn
                0.4s
                ease
                forwards;
        }

        @keyframes tierPageFadeIn {
            to {
                opacity: 1;
            }
        }

        @keyframes tierHeroFloat {
            0%,
            100% {
                transform:
                    translate3d(0, 0, 0)
                    scale(1);
            }

            50% {
                transform:
                    translate3d(0, -16px, 0)
                    scale(1.04);
            }
        }

        @keyframes tierProgressShine {
            from {
                transform: translateX(-120%);
            }

            to {
                transform: translateX(220%);
            }
        }

        .tier-grid-pattern {
            background-image:
                linear-gradient(
                    rgba(255, 255, 255, 0.045) 1px,
                    transparent 1px
                ),
                linear-gradient(
                    90deg,
                    rgba(255, 255, 255, 0.045) 1px,
                    transparent 1px
                );
            background-size: 46px 46px;
        }

        .tier-hero-orb {
            animation:
                tierHeroFloat
                8s
                ease-in-out
                infinite;
        }

        .tier-progress-fill {
            position: relative;
            overflow: hidden;
        }

        .tier-progress-fill::after {
            content: '';
            position: absolute;
            inset: 0;
            width: 38%;
            background:
                linear-gradient(
                    90deg,
                    transparent,
                    rgba(255, 255, 255, 0.55),
                    transparent
                );
            animation:
                tierProgressShine
                2.8s
                ease-in-out
                infinite;
        }

        .tier-card {
            isolation: isolate;
            transition:
                transform 0.35s
                    cubic-bezier(0.22, 1, 0.36, 1),
                box-shadow 0.35s ease,
                border-color 0.35s ease;
        }

        .tier-card:hover {
            transform: translateY(-8px);
        }

        .tier-card-header::after {
            content: '';
            position: absolute;
            right: -54px;
            bottom: -72px;
            width: 170px;
            height: 170px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 999px;
        }

        .tier-card-header::before {
            content: '';
            position: absolute;
            top: -86px;
            left: -62px;
            width: 150px;
            height: 150px;
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 999px;
        }

        .tier-reveal {
            opacity: 0;
            transform: translateY(26px);
            transition:
                opacity 0.7s
                    cubic-bezier(0.22, 1, 0.36, 1),
                transform 0.7s
                    cubic-bezier(0.22, 1, 0.36, 1);
        }

        .tier-reveal.is-visible {
            opacity: 1;
            transform: translateY(0);
        }

        .tier-step-number {
            -webkit-text-stroke:
                1px rgba(220, 38, 38, 0.2);
            color: transparent;
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }

            body {
                opacity: 1;
                animation: none;
            }

            .tier-reveal {
                opacity: 1;
                transform: none;
            }
        }
    </style>
</head>

<body class="min-h-screen text-gray-900 antialiased">

    <?php include __DIR__ . '/includes/customer_navbar.php'; ?>

    <main>
        <!-- Hero -->
        <section
            class="relative overflow-hidden bg-[#111827] text-white"
            aria-labelledby="membership-title"
        >
            <div
                class="tier-grid-pattern absolute inset-0 opacity-40"
            ></div>

            <div
                class="tier-hero-orb absolute -left-24 top-20 h-80 w-80 rounded-full bg-red-600/20 blur-[100px]"
            ></div>

            <div
                class="tier-hero-orb absolute -right-24 bottom-0 h-96 w-96 rounded-full bg-blue-500/20 blur-[110px]"
                style="animation-delay: -3s;"
            ></div>

            <div
                class="relative mx-auto grid max-w-7xl items-center gap-12 px-6 py-16 lg:grid-cols-[1fr_0.9fr] lg:py-24"
            >
                <div class="max-w-3xl">
                    <div
                        class="mb-6 inline-flex items-center gap-3 rounded-full border border-white/10 bg-white/[0.06] px-4 py-2 backdrop-blur-xl"
                    >
                        <span
                            class="h-2 w-2 rounded-full bg-red-500 shadow-[0_0_16px_rgba(239,68,68,0.9)]"
                        ></span>

                        <p
                            class="text-[11px] font-black uppercase tracking-[0.24em] text-white/60"
                        >
                            MangaVault Loyalty Program
                        </p>
                    </div>

                    <h1
                        id="membership-title"
                        class="max-w-3xl text-5xl font-black leading-[0.94] tracking-[-0.055em] sm:text-6xl lg:text-7xl"
                    >
                        Collect more.
                        <span
                            class="block bg-gradient-to-r from-red-400 via-orange-300 to-amber-200 bg-clip-text text-transparent"
                        >
                            Unlock more.
                        </span>
                    </h1>

                    <p
                        class="mt-7 max-w-2xl text-base leading-7 text-white/55 sm:text-lg"
                    >
                        Every confirmed purchase builds your lifetime
                        spending, upgrades your membership tier and unlocks
                        stronger points, birthday rewards and shipping perks.
                    </p>

                    <div
                        class="mt-9 flex flex-wrap gap-3 text-xs font-black uppercase tracking-[0.13em] text-white/55"
                    >
                        <span
                            class="rounded-full border border-white/10 bg-white/[0.05] px-4 py-2"
                        >
                            Automatic upgrades
                        </span>
                        <span
                            class="rounded-full border border-white/10 bg-white/[0.05] px-4 py-2"
                        >
                            No membership fee
                        </span>
                        <span
                            class="rounded-full border border-white/10 bg-white/[0.05] px-4 py-2"
                        >
                            Rewards from confirmed orders
                        </span>
                    </div>
                </div>

                <?php if (
                    isset($_SESSION['user_id']) &&
                    $current_config !== null &&
                    $current_display !== null
                ): ?>
                    <aside
                        class="rounded-3xl border border-white/10 bg-white/[0.07] p-6 shadow-[0_30px_90px_rgba(0,0,0,0.35)] backdrop-blur-2xl sm:p-8"
                        aria-label="Your membership progress"
                    >
                        <div
                            class="flex items-start justify-between gap-5"
                        >
                            <div>
                                <p
                                    class="text-[11px] font-black uppercase tracking-[0.2em] text-white/35"
                                >
                                    Your current tier
                                </p>

                                <div
                                    class="mt-3 flex items-center gap-3"
                                >
                                    <span class="text-4xl">
                                        <?= $current_display[
                                            'emoji'
                                        ] ?>
                                    </span>

                                    <div>
                                        <h2
                                            class="text-2xl font-black text-white"
                                        >
                                            <?= htmlspecialchars(
                                                $current_display[
                                                    'label'
                                                ],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </h2>

                                        <p
                                            class="mt-0.5 text-sm text-white/45"
                                        >
                                            <?= htmlspecialchars(
                                                $current_display[
                                                    'eyebrow'
                                                ],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div
                                class="rounded-2xl border border-white/10 bg-black/20 px-4 py-3 text-right"
                            >
                                <p
                                    class="text-[10px] font-black uppercase tracking-[0.16em] text-white/35"
                                >
                                    Lifetime spend
                                </p>

                                <p
                                    class="mt-1 text-lg font-black text-white"
                                >
                                    <?= tierMoney(
                                        $user_spending
                                    ) ?>
                                </p>
                            </div>
                        </div>

                        <div class="mt-8">
                            <div
                                class="flex items-end justify-between gap-4"
                            >
                                <div>
                                    <p
                                        class="text-xs font-bold text-white/45"
                                    >
                                        <?= $next_display !== null
                                            ? 'Progress to ' .
                                                htmlspecialchars(
                                                    $next_display[
                                                        'label'
                                                    ],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                )
                                            : 'Highest tier reached' ?>
                                    </p>

                                    <p
                                        class="mt-1 text-sm font-black text-white"
                                    >
                                        <?php if (
                                            $next_display !== null
                                        ): ?>
                                            <?= tierMoney(
                                                $amount_needed
                                            ) ?>
                                            remaining
                                        <?php else: ?>
                                            All membership tiers unlocked
                                        <?php endif; ?>
                                    </p>
                                </div>

                                <span
                                    class="text-sm font-black text-blue-300"
                                >
                                    <?= number_format(
                                        $progress_percentage,
                                        0
                                    ) ?>%
                                </span>
                            </div>

                            <div
                                class="mt-4 h-3 overflow-hidden rounded-full bg-white/10"
                                role="progressbar"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-valuenow="<?= (int) round(
                                    $progress_percentage
                                ) ?>"
                            >
                                <div
                                    class="tier-progress-fill h-full rounded-full bg-gradient-to-r from-red-500 via-orange-400 to-blue-300"
                                    style="width: <?= number_format(
                                        $progress_percentage,
                                        2,
                                        '.',
                                        ''
                                    ) ?>%;"
                                ></div>
                            </div>
                        </div>

                        <div
                            class="mt-7 grid grid-cols-3 gap-3"
                        >
                            <div
                                class="rounded-2xl border border-white/10 bg-black/15 p-3"
                            >
                                <p
                                    class="text-[10px] font-black uppercase tracking-[0.14em] text-white/30"
                                >
                                    Points
                                </p>

                                <p
                                    class="mt-2 text-base font-black text-white"
                                >
                                    <?= number_format(
                                        (float) (
                                            $current_config[
                                                'tier_points_multiplier'
                                            ] ?? 1
                                        ),
                                        1
                                    ) ?>×
                                </p>
                            </div>

                            <div
                                class="rounded-2xl border border-white/10 bg-black/15 p-3"
                            >
                                <p
                                    class="text-[10px] font-black uppercase tracking-[0.14em] text-white/30"
                                >
                                    Birthday
                                </p>

                                <p
                                    class="mt-2 text-base font-black text-white"
                                >
                                    +<?= number_format(
                                        (int) (
                                            $current_config[
                                                'tier_birthday_bonus_points'
                                            ] ?? 0
                                        )
                                    ) ?>
                                </p>
                            </div>

                            <div
                                class="rounded-2xl border border-white/10 bg-black/15 p-3"
                            >
                                <p
                                    class="text-[10px] font-black uppercase tracking-[0.14em] text-white/30"
                                >
                                    Shipping
                                </p>

                                <p
                                    class="mt-2 line-clamp-2 text-xs font-black leading-4 text-white"
                                >
                                    <?= htmlspecialchars(
                                        tierShippingLabel(
                                            $current_config
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>
                            </div>
                        </div>
                    </aside>
                <?php else: ?>
                    <aside
                        class="rounded-3xl border border-white/10 bg-white/[0.07] p-8 shadow-[0_30px_90px_rgba(0,0,0,0.35)] backdrop-blur-2xl"
                    >
                        <p
                            class="text-[11px] font-black uppercase tracking-[0.2em] text-blue-300"
                        >
                            Your rewards begin here
                        </p>

                        <h2
                            class="mt-4 text-3xl font-black tracking-[-0.04em] text-white"
                        >
                            Create an account and start building your tier.
                        </h2>

                        <p
                            class="mt-4 text-sm leading-6 text-white/50"
                        >
                            Your confirmed purchases will automatically count
                            towards lifetime spending and membership progress.
                        </p>

                        <a
                            href="register.php"
                            class="mt-7 inline-flex w-full items-center justify-center gap-3 rounded-xl bg-red-600 px-6 py-4 text-sm font-black uppercase tracking-[0.13em] text-white transition hover:-translate-y-1 hover:bg-red-500"
                        >
                            Create free account
                            <span aria-hidden="true">→</span>
                        </a>
                    </aside>
                <?php endif; ?>
            </div>
        </section>

        <!-- Membership tiers -->
        <section
            class="bg-[#f5f0eb] py-20 lg:py-24"
            aria-labelledby="tier-comparison-title"
        >
            <div class="mx-auto max-w-7xl px-6">
                <div
                    class="tier-reveal mx-auto mb-12 max-w-3xl text-center"
                >
                    <p
                        class="text-xs font-black uppercase tracking-[0.22em] text-red-600"
                    >
                        Compare every level
                    </p>

                    <h2
                        id="tier-comparison-title"
                        class="mt-4 text-4xl font-black tracking-[-0.045em] text-gray-950 sm:text-5xl"
                    >
                        Four tiers. Better rewards at every step.
                    </h2>

                    <p
                        class="mt-4 text-sm leading-7 text-gray-500 sm:text-base"
                    >
                        Tier thresholds and rewards are loaded directly from
                        MangaVault's membership configuration.
                    </p>
                </div>

                <?php if ($available_tiers === []): ?>
                    <div
                        class="rounded-3xl border border-dashed border-gray-300 bg-white px-6 py-16 text-center"
                    >
                        <p class="font-semibold text-gray-400">
                            Membership tier information is currently
                            unavailable.
                        </p>
                    </div>
                <?php else: ?>
                    <div
                        class="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-4"
                    >
                        <?php foreach (
                            $available_tiers as $tier_index => $tier_name
                        ): ?>
                            <?php
                            $config =
                                $tier_config[$tier_name];
                            $display =
                                $tier_display[$tier_name];
                            $next_name =
                                $available_tiers[
                                    $tier_index + 1
                                ] ?? null;
                            $next_card_config =
                                $next_name !== null
                                    ? $tier_config[$next_name]
                                    : null;
                            $tier_benefits =
                                array_values(
                                    array_filter(
                                        $benefits[$tier_name] ?? [],
                                        static fn (
                                            string $benefit
                                        ): bool =>
                                            trim($benefit) !== ''
                                    )
                                );
                            $is_current =
                                $user_tier === $tier_name;
                            ?>

                            <article
                                class="tier-card tier-reveal flex h-full flex-col overflow-hidden rounded-3xl border-2 bg-white shadow-xl <?= $display[
                                    'soft_border'
                                ] ?> <?= $display['glow'] ?> <?= $is_current
                                    ? 'ring-4 ring-red-500/25 ring-offset-4 ring-offset-[#f5f0eb]'
                                    : '' ?>"
                                style="transition-delay: <?= min(
                                    $tier_index * 80,
                                    240
                                ) ?>ms;"
                                <?= $is_current
                                    ? 'aria-current="true"'
                                    : '' ?>
                            >
                                <div
                                    class="tier-card-header relative overflow-hidden bg-gradient-to-br <?= $display[
                                        'gradient'
                                    ] ?> p-6 text-white"
                                >
                                    <div
                                        class="relative z-10 flex items-start justify-between gap-4"
                                    >
                                        <div>
                                            <p
                                                class="text-[10px] font-black uppercase tracking-[0.18em] text-white/65"
                                            >
                                                <?= htmlspecialchars(
                                                    $display[
                                                        'eyebrow'
                                                    ],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </p>

                                            <h3
                                                class="mt-3 text-3xl font-black tracking-[-0.04em]"
                                            >
                                                <?= htmlspecialchars(
                                                    $display[
                                                        'label'
                                                    ],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </h3>
                                        </div>

                                        <span
                                            class="flex h-14 w-14 items-center justify-center rounded-2xl border border-white/20 bg-white/10 text-3xl shadow-xl backdrop-blur"
                                            aria-hidden="true"
                                        >
                                            <?= $display['emoji'] ?>
                                        </span>
                                    </div>

                                    <div
                                        class="relative z-10 mt-7 flex items-end justify-between gap-3"
                                    >
                                        <div>
                                            <p
                                                class="text-[10px] font-black uppercase tracking-[0.16em] text-white/55"
                                            >
                                                Lifetime spending
                                            </p>

                                            <p
                                                class="mt-1 text-sm font-black text-white"
                                            >
                                                <?= htmlspecialchars(
                                                    tierRangeLabel(
                                                        $config,
                                                        $next_card_config
                                                    ),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </p>
                                        </div>

                                        <?php if ($is_current): ?>
                                            <span
                                                class="rounded-full border border-white/20 bg-white/15 px-3 py-1.5 text-[10px] font-black uppercase tracking-[0.13em] text-white backdrop-blur"
                                            >
                                                Your tier
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div
                                    class="flex flex-1 flex-col p-6"
                                >
                                    <div
                                        class="grid grid-cols-3 gap-2"
                                    >
                                        <div
                                            class="rounded-2xl <?= $display[
                                                'soft_background'
                                            ] ?> p-3 text-center"
                                        >
                                            <p
                                                class="text-lg font-black <?= $display[
                                                    'soft_text'
                                                ] ?>"
                                            >
                                                <?= number_format(
                                                    (float) (
                                                        $config[
                                                            'tier_points_multiplier'
                                                        ] ?? 1
                                                    ),
                                                    1
                                                ) ?>×
                                            </p>

                                            <p
                                                class="mt-1 text-[9px] font-black uppercase tracking-[0.12em] text-gray-400"
                                            >
                                                Points
                                            </p>
                                        </div>

                                        <div
                                            class="rounded-2xl <?= $display[
                                                'soft_background'
                                            ] ?> p-3 text-center"
                                        >
                                            <p
                                                class="text-lg font-black <?= $display[
                                                    'soft_text'
                                                ] ?>"
                                            >
                                                +<?= number_format(
                                                    (int) (
                                                        $config[
                                                            'tier_birthday_bonus_points'
                                                        ] ?? 0
                                                    )
                                                ) ?>
                                            </p>

                                            <p
                                                class="mt-1 text-[9px] font-black uppercase tracking-[0.12em] text-gray-400"
                                            >
                                                Birthday
                                            </p>
                                        </div>

                                        <div
                                            class="rounded-2xl <?= $display[
                                                'soft_background'
                                            ] ?> p-3 text-center"
                                        >
                                            <p
                                                class="text-lg font-black <?= $display[
                                                    'soft_text'
                                                ] ?>"
                                            >
                                                <?= (int) (
                                                    $config[
                                                        'tier_free_shipping'
                                                    ] ?? 0
                                                ) === 1
                                                    ? 'Free'
                                                    : tierMoney(
                                                        (float) (
                                                            $config[
                                                                'tier_shipping_discount'
                                                            ] ?? 0
                                                        )
                                                    ) ?>
                                            </p>

                                            <p
                                                class="mt-1 text-[9px] font-black uppercase tracking-[0.12em] text-gray-400"
                                            >
                                                Shipping
                                            </p>
                                        </div>
                                    </div>

                                    <div
                                        class="my-6 h-px bg-gray-100"
                                    ></div>

                                    <p
                                        class="text-[10px] font-black uppercase tracking-[0.18em] text-gray-400"
                                    >
                                        Tier benefits
                                    </p>

                                    <?php if (
                                        $tier_benefits === []
                                    ): ?>
                                        <p
                                            class="mt-4 text-sm leading-6 text-gray-400"
                                        >
                                            No additional benefits have been
                                            configured for this tier.
                                        </p>
                                    <?php else: ?>
                                        <ul
                                            class="mt-4 space-y-3"
                                        >
                                            <?php foreach (
                                                $tier_benefits as $benefit
                                            ): ?>
                                                <li
                                                    class="flex items-start gap-3 text-sm leading-6 text-gray-600"
                                                >
                                                    <span
                                                        class="mt-1 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full <?= $display[
                                                            'soft_background'
                                                        ] ?> text-[10px] font-black <?= $display[
                                                            'soft_text'
                                                        ] ?>"
                                                        aria-hidden="true"
                                                    >
                                                        ✓
                                                    </span>

                                                    <span>
                                                        <?= htmlspecialchars(
                                                            $benefit,
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        ) ?>
                                                    </span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>

                                    <div class="mt-auto pt-7">
                                        <div
                                            class="rounded-2xl border <?= $display[
                                                'soft_border'
                                            ] ?> <?= $display[
                                                'soft_background'
                                            ] ?> px-4 py-3"
                                        >
                                            <p
                                                class="text-xs font-bold <?= $display[
                                                    'soft_text'
                                                ] ?>"
                                            >
                                                <?= htmlspecialchars(
                                                    tierShippingLabel(
                                                        $config
                                                    ),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- How it works -->
        <section
            class="border-y border-gray-100 bg-white py-20 lg:py-24"
            aria-labelledby="membership-process-title"
        >
            <div class="mx-auto max-w-7xl px-6">
                <div
                    class="tier-reveal mx-auto max-w-3xl text-center"
                >
                    <p
                        class="text-xs font-black uppercase tracking-[0.22em] text-red-600"
                    >
                        Simple and automatic
                    </p>

                    <h2
                        id="membership-process-title"
                        class="mt-4 text-4xl font-black tracking-[-0.045em] text-gray-950"
                    >
                        How membership progression works
                    </h2>
                </div>

                <?php
                $membership_steps = [
                    [
                        'number' => '01',
                        'icon' => '🛒',
                        'title' => 'Shop',
                        'text' =>
                            'Complete a purchase and wait for the payment to be confirmed.',
                    ],
                    [
                        'number' => '02',
                        'icon' => '📈',
                        'title' => 'Build lifetime spending',
                        'text' =>
                            'Eligible confirmed purchases increase your lifetime spending total.',
                    ],
                    [
                        'number' => '03',
                        'icon' => '🎁',
                        'title' => 'Unlock better perks',
                        'text' =>
                            'Your tier upgrades automatically when the next spending threshold is reached.',
                    ],
                ];
                ?>

                <div
                    class="mt-12 grid gap-6 md:grid-cols-3"
                >
                    <?php foreach (
                        $membership_steps as $step_index => $step
                    ): ?>
                        <article
                            class="tier-reveal relative overflow-hidden rounded-3xl border border-gray-100 bg-[#fffdf9] p-7 shadow-[0_18px_55px_rgba(15,23,42,0.07)]"
                            style="transition-delay: <?= $step_index * 90 ?>ms;"
                        >
                            <span
                                class="tier-step-number absolute -right-2 -top-8 text-8xl font-black"
                                aria-hidden="true"
                            >
                                <?= $step['number'] ?>
                            </span>

                            <div
                                class="relative flex h-12 w-12 items-center justify-center rounded-2xl bg-gray-950 text-xl shadow-lg shadow-gray-200"
                                aria-hidden="true"
                            >
                                <?= $step['icon'] ?>
                            </div>

                            <h3
                                class="relative mt-6 text-xl font-black text-gray-950"
                            >
                                <?= htmlspecialchars(
                                    $step['title'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </h3>

                            <p
                                class="relative mt-3 text-sm leading-6 text-gray-500"
                            >
                                <?= htmlspecialchars(
                                    $step['text'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section
            class="relative overflow-hidden bg-[#17233b] py-16 text-white lg:py-20"
        >
            <div
                class="tier-grid-pattern absolute inset-0 opacity-20"
            ></div>

            <div
                class="absolute -left-20 top-0 h-72 w-72 rounded-full bg-red-600/15 blur-[100px]"
            ></div>

            <div
                class="relative mx-auto flex max-w-5xl flex-col items-center justify-between gap-8 px-6 text-center lg:flex-row lg:text-left"
            >
                <div>
                    <p
                        class="text-xs font-black uppercase tracking-[0.22em] text-blue-300"
                    >
                        Keep building your collection
                    </p>

                    <h2
                        class="mt-3 text-3xl font-black tracking-[-0.04em] sm:text-4xl"
                    >
                        Your next tier starts with your next great read.
                    </h2>
                </div>

                <a
                    href="<?= isset($_SESSION['user_id'])
                        ? 'customer/home.php'
                        : 'register.php' ?>"
                    class="inline-flex flex-shrink-0 items-center justify-center gap-3 rounded-xl bg-red-600 px-7 py-4 text-sm font-black uppercase tracking-[0.13em] text-white shadow-2xl shadow-red-950/35 transition hover:-translate-y-1 hover:bg-red-500"
                >
                    <?= isset($_SESSION['user_id'])
                        ? 'Browse the catalog'
                        : 'Create free account' ?>
                    <span aria-hidden="true">→</span>
                </a>
            </div>
        </section>
    </main>

    <footer class="bg-[#0d1424] text-white">
        <div
            class="mx-auto flex max-w-7xl flex-col gap-3 px-6 py-8 text-xs text-white/35 sm:flex-row sm:items-center sm:justify-between"
        >
            <p>
                © 2026 MangaVault. All rights reserved.
            </p>

            <p>
                Physical manga · E-books · Membership rewards
            </p>
        </div>
    </footer>

    <script>
        const tierRevealElements =
            document.querySelectorAll(
                '.tier-reveal'
            );

        if (
            'IntersectionObserver' in window &&
            !window.matchMedia(
                '(prefers-reduced-motion: reduce)'
            ).matches
        ) {
            const tierRevealObserver =
                new IntersectionObserver(
                    entries => {
                        entries.forEach(entry => {
                            if (!entry.isIntersecting) {
                                return;
                            }

                            entry.target.classList.add(
                                'is-visible'
                            );
                            tierRevealObserver.unobserve(
                                entry.target
                            );
                        });
                    },
                    {
                        threshold: 0.12,
                        rootMargin:
                            '0px 0px -40px 0px',
                    }
                );

            tierRevealElements.forEach(element => {
                tierRevealObserver.observe(element);
            });
        } else {
            tierRevealElements.forEach(element => {
                element.classList.add(
                    'is-visible'
                );
            });
        }

        window.addEventListener(
            'pageshow',
            event => {
                if (event.persisted) {
                    document.body.style.opacity = '1';
                }
            }
        );

        document.querySelectorAll(
            'main a[href], footer a[href]'
        ).forEach(link => {
            link.addEventListener(
                'click',
                event => {
                    const href =
                        link.getAttribute('href');

                    if (
                        !href ||
                        href.startsWith('#') ||
                        href.startsWith('mailto:') ||
                        href.startsWith('tel:') ||
                        link.target === '_blank' ||
                        link.hasAttribute('download')
                    ) {
                        return;
                    }

                    const destination = new URL(
                        link.href,
                        window.location.href
                    );

                    if (
                        destination.origin !==
                        window.location.origin
                    ) {
                        return;
                    }

                    event.preventDefault();
                    document.body.style.opacity = '0';
                    document.body.style.transition =
                        'opacity 0.3s ease';

                    window.setTimeout(() => {
                        window.location.href =
                            destination.href;
                    }, 300);
                }
            );
        });
    </script>

</body>
</html>