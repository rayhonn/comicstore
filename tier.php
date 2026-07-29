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
        content="Explore MangaVault membership tiers, points multipliers, shipping benefits and tier progression."
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

        @keyframes tierPassFloat {
            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-7px);
            }
        }

        @keyframes tierPassSheen {
            from {
                transform: translateX(-160%) rotate(16deg);
            }

            to {
                transform: translateX(260%) rotate(16deg);
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

        .membership-hero {
            background:
                radial-gradient(
                    circle at 10% 12%,
                    rgba(220, 38, 38, 0.1),
                    transparent 30%
                ),
                radial-gradient(
                    circle at 88% 20%,
                    rgba(37, 99, 235, 0.09),
                    transparent 31%
                ),
                linear-gradient(
                    180deg,
                    #fffdf9 0%,
                    #f5f0eb 100%
                );
        }

        .membership-hero::before {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            opacity: 0.42;
            background-image:
                linear-gradient(
                    rgba(17, 24, 39, 0.035) 1px,
                    transparent 1px
                ),
                linear-gradient(
                    90deg,
                    rgba(17, 24, 39, 0.035) 1px,
                    transparent 1px
                );
            background-size: 52px 52px;
            mask-image:
                linear-gradient(
                    to bottom,
                    black,
                    transparent 88%
                );
        }

        .membership-board {
            box-shadow:
                0 30px 90px rgba(15, 23, 42, 0.12),
                0 2px 8px rgba(15, 23, 42, 0.04);
        }

        .membership-pass {
            position: relative;
            overflow: hidden;
            animation:
                tierPassFloat
                6s
                ease-in-out
                infinite;
        }

        .membership-pass::after {
            content: '';
            position: absolute;
            top: -45%;
            bottom: -45%;
            left: -35%;
            width: 22%;
            pointer-events: none;
            background:
                linear-gradient(
                    90deg,
                    transparent,
                    rgba(255, 255, 255, 0.4),
                    transparent
                );
            animation:
                tierPassSheen
                5.5s
                ease-in-out
                infinite;
        }

        .tier-journey-track {
            position: absolute;
            top: 1.45rem;
            right: 9%;
            left: 9%;
            height: 2px;
            background: #e5e7eb;
        }

        .tier-journey-progress {
            height: 100%;
            border-radius: 999px;
            background:
                linear-gradient(
                    90deg,
                    #dc2626,
                    #f59e0b,
                    #2563eb
                );
        }

        .tier-stat-value {
            white-space: nowrap;
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

        @media (max-width: 639px) {
            .tier-journey-track {
                display: none;
            }
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
        <!-- Membership overview -->
        <section
            class="membership-hero relative overflow-hidden border-b border-gray-200/70"
            aria-labelledby="membership-title"
        >
            <div
                class="relative mx-auto max-w-7xl px-6 py-16 lg:py-20"
            >
                <div
                    class="mx-auto max-w-3xl text-center"
                >
                    <div
                        class="inline-flex items-center gap-3 rounded-full border border-red-100 bg-white/80 px-4 py-2 shadow-sm backdrop-blur"
                    >
                        <span
                            class="flex h-6 w-6 items-center justify-center rounded-full bg-red-600 text-[10px] font-black text-white"
                            aria-hidden="true"
                        >
                            M
                        </span>

                        <p
                            class="text-[11px] font-black uppercase tracking-[0.22em] text-gray-500"
                        >
                            MangaVault Membership
                        </p>
                    </div>

                    <h1
                        id="membership-title"
                        class="mt-6 text-5xl font-black leading-[0.96] tracking-[-0.055em] text-gray-950 sm:text-6xl"
                    >
                        Your reading journey,
                        <span class="text-red-600">
                            mapped into rewards.
                        </span>
                    </h1>

                    <p
                        class="mx-auto mt-6 max-w-2xl text-base leading-7 text-gray-500 sm:text-lg"
                    >
                        Confirmed purchases increase your lifetime spending,
                        move you through four membership levels and unlock
                        stronger points and standard-shipping benefits.
                    </p>
                </div>

                <div
                    class="membership-board relative mx-auto mt-12 max-w-6xl overflow-hidden rounded-[2rem] border border-white bg-white/90 p-5 backdrop-blur-xl sm:p-7 lg:p-9"
                >
                    <?php if (
                        isset($_SESSION['user_id']) &&
                        $current_config !== null &&
                        $current_display !== null
                    ): ?>
                        <div
                            class="grid gap-6 lg:grid-cols-[0.82fr_1.18fr]"
                        >
                            <div
                                class="membership-pass rounded-[1.7rem] bg-gradient-to-br <?= $current_display[
                                    'gradient'
                                ] ?> p-6 text-white shadow-2xl <?= $current_display[
                                    'glow'
                                ] ?> sm:p-7"
                            >
                                <div
                                    class="relative z-10 flex items-start justify-between gap-5"
                                >
                                    <div>
                                        <p
                                            class="text-[10px] font-black uppercase tracking-[0.2em] text-white/65"
                                        >
                                            MangaVault member
                                        </p>

                                        <h2
                                            class="mt-4 text-4xl font-black tracking-[-0.045em]"
                                        >
                                            <?= htmlspecialchars(
                                                $current_display['label'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </h2>

                                        <p
                                            class="mt-2 text-sm font-semibold text-white/70"
                                        >
                                            <?= htmlspecialchars(
                                                $current_display['eyebrow'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>
                                    </div>

                                    <span
                                        class="flex h-16 w-16 items-center justify-center rounded-2xl border border-white/25 bg-white/15 text-4xl shadow-xl backdrop-blur"
                                        aria-hidden="true"
                                    >
                                        <?= $current_display['emoji'] ?>
                                    </span>
                                </div>

                                <div
                                    class="relative z-10 mt-12 flex items-end justify-between gap-4"
                                >
                                    <div>
                                        <p
                                            class="text-[10px] font-black uppercase tracking-[0.18em] text-white/60"
                                        >
                                            Lifetime spending
                                        </p>

                                        <p
                                            class="mt-2 text-2xl font-black"
                                        >
                                            <?= tierMoney(
                                                $user_spending
                                            ) ?>
                                        </p>
                                    </div>

                                    <p
                                        class="text-right text-[10px] font-black uppercase leading-5 tracking-[0.16em] text-white/60"
                                    >
                                        Automatic<br>
                                        tier upgrades
                                    </p>
                                </div>
                            </div>

                            <div
                                class="rounded-[1.7rem] border border-gray-100 bg-[#fbfaf8] p-6 sm:p-7"
                            >
                                <div
                                    class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start"
                                >
                                    <div>
                                        <p
                                            class="text-[10px] font-black uppercase tracking-[0.18em] text-gray-400"
                                        >
                                            Membership progress
                                        </p>

                                        <h2
                                            class="mt-3 text-2xl font-black tracking-[-0.035em] text-gray-950"
                                        >
                                            <?= $next_display !== null
                                                ? 'Next stop: ' .
                                                    htmlspecialchars(
                                                        $next_display['label'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    )
                                                : 'Every tier unlocked' ?>
                                        </h2>

                                        <p
                                            class="mt-2 text-sm text-gray-500"
                                        >
                                            <?php if (
                                                $next_display !== null
                                            ): ?>
                                                <?= tierMoney(
                                                    $amount_needed
                                                ) ?> remains before your
                                                next automatic upgrade.
                                            <?php else: ?>
                                                You have reached the highest
                                                MangaVault membership level.
                                            <?php endif; ?>
                                        </p>
                                    </div>

                                    <div
                                        class="rounded-2xl bg-gray-950 px-4 py-3 text-center text-white"
                                    >
                                        <p
                                            class="text-2xl font-black"
                                        >
                                            <?= number_format(
                                                $progress_percentage,
                                                0
                                            ) ?>%
                                        </p>

                                        <p
                                            class="text-[9px] font-black uppercase tracking-[0.14em] text-white/45"
                                        >
                                            Complete
                                        </p>
                                    </div>
                                </div>

                                <div
                                    class="mt-6 h-3 overflow-hidden rounded-full bg-gray-200"
                                    role="progressbar"
                                    aria-valuemin="0"
                                    aria-valuemax="100"
                                    aria-valuenow="<?= (int) round(
                                        $progress_percentage
                                    ) ?>"
                                >
                                    <div
                                        class="tier-progress-fill h-full rounded-full bg-gradient-to-r from-red-500 via-orange-400 to-blue-500"
                                        style="width: <?= number_format(
                                            $progress_percentage,
                                            2,
                                            '.',
                                            ''
                                        ) ?>%;"
                                    ></div>
                                </div>

                                <div
                                    class="mt-7 grid grid-cols-1 gap-3 sm:grid-cols-3"
                                >
                                    <div
                                        class="rounded-2xl border border-gray-100 bg-white p-4"
                                    >
                                        <p
                                            class="text-[9px] font-black uppercase tracking-[0.15em] text-gray-400"
                                        >
                                            Points rate
                                        </p>

                                        <p
                                            class="tier-stat-value mt-2 text-lg font-black text-gray-950"
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
                                        class="rounded-2xl border border-gray-100 bg-white p-4"
                                    >
                                        <p
                                            class="text-[9px] font-black uppercase tracking-[0.15em] text-gray-400"
                                        >
                                            Shipping
                                        </p>

                                        <p
                                            class="tier-stat-value mt-2 text-sm font-black text-gray-950"
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

                                    <div
                                        class="rounded-2xl border border-gray-100 bg-white p-4"
                                    >
                                        <p
                                            class="text-[9px] font-black uppercase tracking-[0.15em] text-gray-400"
                                        >
                                            Upgrade mode
                                        </p>

                                        <p
                                            class="mt-2 text-sm font-black text-gray-950"
                                        >
                                            Automatic
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div
                            class="grid items-center gap-8 lg:grid-cols-[1fr_auto]"
                        >
                            <div>
                                <p
                                    class="text-[10px] font-black uppercase tracking-[0.2em] text-red-600"
                                >
                                    Your membership starts free
                                </p>

                                <h2
                                    class="mt-3 text-3xl font-black tracking-[-0.04em] text-gray-950"
                                >
                                    Create an account and let every confirmed
                                    purchase move you forward.
                                </h2>

                                <p
                                    class="mt-3 max-w-2xl text-sm leading-6 text-gray-500"
                                >
                                    MangaVault automatically tracks your
                                    lifetime spending and assigns the correct
                                    membership tier.
                                </p>
                            </div>

                            <a
                                href="register.php"
                                class="inline-flex items-center justify-center gap-3 rounded-xl bg-red-600 px-7 py-4 text-sm font-black uppercase tracking-[0.13em] text-white shadow-xl shadow-red-100 transition hover:-translate-y-1 hover:bg-red-700"
                            >
                                Create free account
                                <span aria-hidden="true">→</span>
                            </a>
                        </div>
                    <?php endif; ?>

                    <div
                        class="relative mt-8 border-t border-gray-100 pt-8"
                        aria-label="Membership tier journey"
                    >
                        <div
                            class="tier-journey-track"
                            aria-hidden="true"
                        >
                            <div
                                class="tier-journey-progress"
                                style="width: <?= $current_tier_index === false
                                    ? '0'
                                    : number_format(
                                        max(
                                            0,
                                            min(
                                                100,
                                                (
                                                    $current_tier_index /
                                                    max(
                                                        1,
                                                        count(
                                                            $available_tiers
                                                        ) - 1
                                                    )
                                                ) * 100
                                            )
                                        ),
                                        2,
                                        '.',
                                        ''
                                    ) ?>%;"
                            ></div>
                        </div>

                        <div
                            class="relative grid grid-cols-2 gap-5 sm:grid-cols-4"
                        >
                            <?php foreach (
                                $available_tiers as $journey_index => $journey_tier
                            ): ?>
                                <?php
                                $journey_display =
                                    $tier_display[$journey_tier];
                                $journey_config =
                                    $tier_config[$journey_tier];
                                $journey_is_current =
                                    $user_tier === $journey_tier;
                                $journey_is_reached =
                                    $current_tier_index !== false &&
                                    $journey_index <=
                                        $current_tier_index;
                                ?>

                                <div
                                    class="flex flex-col items-center text-center"
                                >
                                    <span
                                        class="relative z-10 flex h-12 w-12 items-center justify-center rounded-full border-4 <?= $journey_is_current
                                            ? 'border-red-100 bg-red-600 text-white shadow-lg shadow-red-100'
                                            : (
                                                $journey_is_reached
                                                    ? 'border-white bg-gray-950 text-white shadow-md'
                                                    : 'border-white bg-gray-100 text-gray-400'
                                            ) ?> text-lg"
                                        aria-hidden="true"
                                    >
                                        <?= $journey_display['emoji'] ?>
                                    </span>

                                    <p
                                        class="mt-3 text-sm font-black <?= $journey_is_current
                                            ? 'text-red-600'
                                            : 'text-gray-800' ?>"
                                    >
                                        <?= htmlspecialchars(
                                            $journey_display['label'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </p>

                                    <p
                                        class="mt-1 text-[10px] font-bold text-gray-400"
                                    >
                                        From <?= tierMoney(
                                            (float) (
                                                $journey_config[
                                                    'tier_min_spending'
                                                ] ?? 0
                                            )
                                        ) ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
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
                                                class="tier-stat-value text-lg font-black <?= $display[
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
                                                class="tier-stat-value text-lg font-black <?= $display[
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
                                                Birthday*
                                            </p>
                                        </div>

                                        <div
                                            class="rounded-2xl <?= $display[
                                                'soft_background'
                                            ] ?> p-3 text-center"
                                        >
                                            <p
                                                class="tier-stat-value text-base font-black <?= $display[
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

                    <p
                        class="mt-7 text-center text-xs leading-5 text-gray-400"
                    >
                        * Birthday bonus values are configured in the system,
                        but automatic birthday-point crediting is not active yet.
                    </p>
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

    <!-- Footer -->
    <footer
        class="border-t border-gray-200 bg-[#F5F0EB] py-12 text-gray-800"
    >
        <div class="mx-auto max-w-7xl px-6">
            <div
                class="mb-10 grid grid-cols-2 gap-8 md:grid-cols-4"
            >
                <div class="col-span-2 md:col-span-1">
                    <h3 class="mb-4 text-lg font-black">
                        MANGA<span class="text-red-600">VAULT</span>
                    </h3>

                    <p
                        class="text-sm leading-relaxed text-gray-600"
                    >
                        Malaysia's ultimate destination for manga and
                        comic book lovers.
                    </p>
                </div>

                <div>
                    <h4
                        class="mb-4 text-sm font-bold uppercase tracking-wide text-gray-800"
                    >
                        Shop
                    </h4>

                    <ul class="space-y-2 text-sm text-gray-600">
                        <li>
                            <a
                                href="customer/home.php"
                                class="inline-block transition-all hover:translate-x-1 hover:text-red-600"
                            >
                                All Manga
                            </a>
                        </li>
                        <li>
                            <a
                                href="customer/home.php?type=physical"
                                class="inline-block transition-all hover:translate-x-1 hover:text-red-600"
                            >
                                Physical Books
                            </a>
                        </li>
                        <li>
                            <a
                                href="customer/home.php?type=ebook"
                                class="inline-block transition-all hover:translate-x-1 hover:text-red-600"
                            >
                                E-Books
                            </a>
                        </li>
                    </ul>
                </div>

                <div>
                    <h4
                        class="mb-4 text-sm font-bold uppercase tracking-wide text-gray-800"
                    >
                        Help
                    </h4>

                    <ul class="space-y-2 text-sm text-gray-600">
                        <li>
                            <a
                                href="customer/orders.php"
                                class="inline-block transition-all hover:translate-x-1 hover:text-red-600"
                            >
                                My Orders
                            </a>
                        </li>
                        <li>
                            <a
                                href="customer/profile.php"
                                class="inline-block transition-all hover:translate-x-1 hover:text-red-600"
                            >
                                My Account
                            </a>
                        </li>
                        <li>
                            <a
                                href="customer/faq.php"
                                class="inline-block transition-all hover:translate-x-1 hover:text-red-600"
                            >
                                FAQ
                            </a>
                        </li>
                        <li>
                            <a
                                href="customer/about.php"
                                class="inline-block transition-all hover:translate-x-1 hover:text-red-600"
                            >
                                About Us
                            </a>
                        </li>
                    </ul>
                </div>

                <div>
                    <h4
                        class="mb-4 text-sm font-bold uppercase tracking-wide text-gray-800"
                    >
                        Follow Us
                    </h4>

                    <div class="flex gap-3">
                        <a
                            href="#"
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-gray-200 text-sm font-bold text-gray-600 transition-all hover:bg-red-600 hover:text-white"
                            aria-label="Facebook"
                        >
                            f
                        </a>
                        <a
                            href="#"
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-gray-200 text-sm font-bold text-gray-600 transition-all hover:bg-red-600 hover:text-white"
                            aria-label="Twitter"
                        >
                            t
                        </a>
                        <a
                            href="#"
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-gray-200 text-sm font-bold text-gray-600 transition-all hover:bg-red-600 hover:text-white"
                            aria-label="LinkedIn"
                        >
                            in
                        </a>
                    </div>
                </div>
            </div>

            <div
                class="border-t border-gray-300 pt-6 text-center text-xs text-gray-500"
            >
                © 2026 MangaVault. All rights reserved.
            </div>
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