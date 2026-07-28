<?php

require_once __DIR__ . '/includes/session.php';

start_secure_session();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/logger.php';

$hero_backgrounds = [
    'main' => null,
    'rankings' => null,
    'ebook' => null,
];

try {
    $hero_background_stmt = $pdo->query("
        SELECT
            hero_slide_key,
            hero_slide_background
        FROM homepage_hero_slides
    ");

    foreach (
        $hero_background_stmt->fetchAll(
            PDO::FETCH_ASSOC
        ) as $hero_background
    ) {
        $slide_key =
            (string) $hero_background[
                'hero_slide_key'
            ];

        if (array_key_exists(
            $slide_key,
            $hero_backgrounds
        )) {
            $hero_backgrounds[$slide_key] =
                $hero_background[
                    'hero_slide_background'
                ];
        }
    }
} catch (Throwable $e) {
    app_error_log(
        'Homepage hero backgrounds could not be loaded: ' .
        $e->getMessage()
    );
}

$rankings = $pdo->query("
    SELECT
        p.*,
        COALESCE(
            SUM(oi.order_item_quantity),
            0
        ) AS total_sold,
        pp.physical_stock_quantity
    FROM products p
    LEFT JOIN order_items oi
        ON oi.order_item_product_id =
            p.product_id
    LEFT JOIN product_physical pp
        ON pp.physical_product_id =
            p.product_id
    WHERE p.product_is_available = 1
    GROUP BY p.product_id
    ORDER BY total_sold DESC,
             p.product_id ASC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$new_releases = $pdo->query("
    SELECT
        p.*,
        pp.physical_stock_quantity
    FROM products p
    LEFT JOIN product_physical pp
        ON pp.physical_product_id =
            p.product_id
    WHERE p.product_is_available = 1
    ORDER BY p.product_created_at DESC,
             p.product_id DESC
    LIMIT 4
")->fetchAll(PDO::FETCH_ASSOC);

$genres = $pdo->query("
    SELECT
        g.*,
        COUNT(
            pg.product_genres_product_id
        ) AS product_count
    FROM genres g
    LEFT JOIN product_genres pg
        ON pg.product_genres_genre_id =
            g.genre_id
    GROUP BY g.genre_id
    ORDER BY product_count DESC,
             g.genre_name ASC
    LIMIT 4
")->fetchAll(PDO::FETCH_ASSOC);

$featured_ebook = $pdo->query("
    SELECT
        p.*,
        pe.ebook_download_limit
    FROM products p
    JOIN product_ebook pe
        ON pe.ebook_product_id =
            p.product_id
    WHERE p.product_is_available = 1
    ORDER BY p.product_created_at DESC,
             p.product_id DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC) ?: null;

$cart_count = 0;
$notif_count = 0;

if (isset($_SESSION['user_id'])) {
    $user_id = (int) $_SESSION['user_id'];

    $cart_stmt = $pdo->prepare("
        SELECT COALESCE(
            SUM(cart_item_quantity),
            0
        )
        FROM cart_items
        WHERE cart_item_user_id = ?
    ");
    $cart_stmt->execute([$user_id]);
    $cart_count = (int) $cart_stmt->fetchColumn();

    $notif_stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM notifications
        WHERE notif_user_id = ?
        AND notif_is_read = 0
    ");
    $notif_stmt->execute([$user_id]);
    $notif_count = (int) $notif_stmt->fetchColumn();
}

function coverImageUrl(?string $filename): string
{
    if ($filename === null || $filename === '') {
        return '';
    }

    return
        'assets/images/' .
        rawurlencode(basename($filename));
}

function homepageHeroImageUrl(?string $filename): string
{
    if ($filename === null || $filename === '') {
        return '';
    }

    return
        'assets/images/homepage/' .
        rawurlencode(basename($filename));
}

$main_hero_background =
    homepageHeroImageUrl(
        $hero_backgrounds['main']
    );

if ($main_hero_background === '') {
    $main_hero_background =
        'assets/images/manga cover.avif';
}

$rankings_hero_background =
    homepageHeroImageUrl(
        $hero_backgrounds['rankings']
    );

$ebook_hero_background =
    homepageHeroImageUrl(
        $hero_backgrounds['ebook']
    );

$genre_visuals = [
    'action' => [
        'icon' => '⚡',
        'eyebrow' => 'High-energy battles',
        'background' =>
            'linear-gradient(135deg, #7f1d1d 0%, #dc2626 55%, #fb7185 100%)',
    ],
    'romance' => [
        'icon' => '♡',
        'eyebrow' => 'Stories that stay with you',
        'background' =>
            'linear-gradient(135deg, #831843 0%, #db2777 55%, #f9a8d4 100%)',
    ],
    'fantasy' => [
        'icon' => '✦',
        'eyebrow' => 'Beyond the ordinary',
        'background' =>
            'linear-gradient(135deg, #312e81 0%, #7c3aed 55%, #c4b5fd 100%)',
    ],
    'horror' => [
        'icon' => '☾',
        'eyebrow' => 'Read after dark',
        'background' =>
            'linear-gradient(135deg, #111827 0%, #374151 55%, #991b1b 100%)',
    ],
    'comedy' => [
        'icon' => '☺',
        'eyebrow' => 'Bright, funny and easy-going',
        'background' =>
            'linear-gradient(135deg, #92400e 0%, #f59e0b 55%, #fde68a 100%)',
    ],
    'shounen' => [
        'icon' => '爆',
        'eyebrow' => 'Courage, rivalry and growth',
        'background' =>
            'linear-gradient(135deg, #7c2d12 0%, #ea580c 55%, #fdba74 100%)',
    ],
    'seinen' => [
        'icon' => '影',
        'eyebrow' => 'Bold stories for mature readers',
        'background' =>
            'linear-gradient(135deg, #0f172a 0%, #334155 55%, #64748b 100%)',
    ],
    'shoujo' => [
        'icon' => '✿',
        'eyebrow' => 'Heartfelt and beautifully told',
        'background' =>
            'linear-gradient(135deg, #701a75 0%, #c026d3 55%, #f0abfc 100%)',
    ],
];
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
        content="Discover physical manga and e-books, secure checkout, membership rewards and nationwide delivery at MangaVault."
    >

    <title>
        MangaVault - Manga, Comics and E-Books
    </title>

    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        :root {
            --ink: #111827;
            --navy: #17233b;
            --cream: #f6f1eb;
            --paper: #fffdf9;
            --red: #dc2626;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            opacity: 0;
            background: var(--cream);
            animation: pageFadeIn 0.45s ease forwards;
        }

        body.menu-open {
            overflow: hidden;
        }

        @keyframes pageFadeIn {
            to {
                opacity: 1;
            }
        }

        @keyframes bellRing {
            0%, 100% {
                transform: rotate(0deg);
            }

            20% {
                transform: rotate(13deg);
            }

            40% {
                transform: rotate(-11deg);
            }

            60% {
                transform: rotate(7deg);
            }

            80% {
                transform: rotate(-4deg);
            }
        }

        @keyframes floatBook {
            0%, 100% {
                transform: translateY(0) rotate(var(--book-rotate));
            }

            50% {
                transform: translateY(-12px) rotate(var(--book-rotate));
            }
        }

        @keyframes softPulse {
            0%, 100% {
                opacity: 0.5;
            }

            50% {
                opacity: 0.9;
            }
        }

        .bell-ring {
            display: inline-block;
            transform-origin: top center;
            animation: bellRing 1.25s ease infinite;
        }

        .nav-link {
            position: relative;
        }

        .nav-link::after {
            content: '';
            position: absolute;
            right: 0;
            bottom: -8px;
            left: 0;
            height: 2px;
            background: var(--red);
            transform: scaleX(0);
            transform-origin: center;
            transition: transform 0.2s ease;
        }

        .nav-link:hover::after,
        .nav-link.active::after {
            transform: scaleX(1);
        }

        #mobileMenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        #mobileMenu.open {
            max-height: 560px;
        }

        .hero-grid {
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
            background-size: 44px 44px;
        }

        .hero-book {
            --book-rotate: 0deg;
        }

        .hero-book-motion {
            animation: floatBook 5s ease-in-out infinite;
        }

        .hero-book:nth-child(2) .hero-book-motion {
            animation-delay: -1.2s;
        }

        .hero-book:nth-child(3) .hero-book-motion {
            animation-delay: -2.4s;
        }

        .cover-stage {
            position: relative;
            overflow: hidden;
            background: #eee8e1;
            isolation: isolate;
        }

        .cover-stage::after {
            content: '';
            position: absolute;
            inset: 0;
            z-index: 1;
            background:
                linear-gradient(
                    180deg,
                    rgba(255, 255, 255, 0.08),
                    rgba(17, 24, 39, 0.08)
                );
            pointer-events: none;
        }

        .cover-background {
            position: absolute;
            inset: -12%;
            width: 124%;
            height: 124%;
            object-fit: cover;
            filter: blur(24px);
            opacity: 0.23;
            transform: scale(1.05);
        }

        .cover-foreground {
            position: relative;
            z-index: 2;
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            padding: 0;
            transition: transform 0.45s ease;
        }

        .product-card:hover .cover-foreground {
            transform: scale(1.045);
        }

        .product-card {
            box-shadow: 0 14px 42px rgba(31, 41, 55, 0.07);
        }

        .product-card:hover {
            box-shadow: 0 24px 60px rgba(31, 41, 55, 0.14);
        }

        .genre-card::before {
            content: '';
            position: absolute;
            width: 190px;
            height: 190px;
            top: -95px;
            right: -55px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 999px;
        }

        .genre-card::after {
            content: '';
            position: absolute;
            width: 120px;
            height: 120px;
            right: 20px;
            bottom: -72px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 999px;
        }

        .section-kicker {
            letter-spacing: 0.22em;
        }

        .line-clamp-2 {
            display: -webkit-box;
            overflow: hidden;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }

        .hero-progress-fill {
            width: 0;
            animation: heroProgress 6s linear forwards;
        }

        @keyframes heroProgress {
            to {
                width: 100%;
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
        }
    </style>
    <link
        rel="stylesheet"
        href="assets/css/index_motion.css"
    >
</head>

<body class="text-gray-900 antialiased">

    <!-- Navbar -->
    <nav
        class="sticky top-0 z-50 border-b border-white/70 bg-white/90 shadow-sm backdrop-blur-xl"
    >
        <div
            class="mx-auto flex max-w-7xl items-center justify-between px-5 py-4 sm:px-6"
        >
            <a
                href="index.php"
                class="group flex items-center gap-3"
                aria-label="MangaVault home"
            >

                <span
                    class="text-xl font-black tracking-[0.08em] text-gray-950"
                >
                    MANGA<span class="text-red-600">VAULT</span>
                </span>
            </a>

            <div
                class="hidden items-center gap-8 text-sm font-semibold lg:flex"
            >
                <a
                    href="index.php"
                    class="nav-link active text-red-600"
                >
                    Home
                </a>

                <a
                    href="customer/home.php"
                    class="nav-link text-gray-600 hover:text-red-600"
                >
                    Catalog
                </a>

                <a
                    href="#rankings"
                    class="nav-link text-gray-600 hover:text-red-600"
                >
                    Rankings
                </a>

                <a
                    href="#new-releases"
                    class="nav-link text-gray-600 hover:text-red-600"
                >
                    New Releases
                </a>

                <a
                    href="tier.php"
                    class="nav-link text-gray-600 hover:text-red-600"
                >
                    Membership
                </a>
            </div>

            <div class="flex items-center gap-3 text-sm">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a
                        href="customer/notifications.php"
                        class="relative flex h-10 w-10 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-600 transition hover:border-red-200 hover:bg-red-50 hover:text-red-600"
                        aria-label="Notifications"
                    >
                        <svg
                            class="h-5 w-5 <?= $notif_count > 0
                                ? 'bell-ring'
                                : '' ?>"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"
                            ></path>
                        </svg>

                        <?php if ($notif_count > 0): ?>
                            <span
                                class="absolute -right-1.5 -top-1.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-black text-white"
                            >
                                <?= $notif_count > 99
                                    ? '99+'
                                    : $notif_count ?>
                            </span>
                        <?php endif; ?>
                    </a>

                    <a
                        href="customer/cart.php"
                        class="relative flex h-10 w-10 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-600 transition hover:border-red-200 hover:bg-red-50 hover:text-red-600"
                        aria-label="Shopping cart"
                    >
                        <svg
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"
                            ></path>
                        </svg>

                        <?php if ($cart_count > 0): ?>
                            <span
                                class="absolute -right-1.5 -top-1.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-black text-white"
                            >
                                <?= $cart_count > 99
                                    ? '99+'
                                    : $cart_count ?>
                            </span>
                        <?php endif; ?>
                    </a>

                    <a
                        href="customer/profile.php"
                        class="hidden items-center gap-2 rounded-xl bg-gray-950 px-4 py-2.5 font-bold text-white transition hover:bg-red-600 lg:flex"
                    >
                        <span class="text-white/60">
                            Hi,
                        </span>

                        <?= htmlspecialchars(
                            (string) (
                                $_SESSION['user_first_name'] ??
                                $_SESSION['user_name'] ??
                                'Reader'
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </a>
                <?php else: ?>
                    <a
                        href="login.php"
                        class="hidden font-semibold text-gray-600 transition hover:text-red-600 lg:block"
                    >
                        Login
                    </a>

                    <a
                        href="register.php"
                        class="hidden rounded-xl bg-red-600 px-5 py-2.5 font-bold text-white shadow-lg shadow-red-100 transition hover:-translate-y-0.5 hover:bg-red-700 lg:block"
                    >
                        Create account
                    </a>
                <?php endif; ?>

                <button
                    id="menuBtn"
                    type="button"
                    class="flex h-10 w-10 flex-col items-center justify-center gap-1.5 rounded-xl border border-gray-200 bg-white lg:hidden"
                    aria-label="Open navigation menu"
                    aria-expanded="false"
                    aria-controls="mobileMenu"
                >
                    <span
                        class="hamburger-line h-0.5 w-5 rounded bg-gray-800 transition-all duration-300"
                    ></span>
                    <span
                        class="hamburger-line h-0.5 w-5 rounded bg-gray-800 transition-all duration-300"
                    ></span>
                    <span
                        class="hamburger-line h-0.5 w-5 rounded bg-gray-800 transition-all duration-300"
                    ></span>
                </button>
            </div>
        </div>

        <div
            id="mobileMenu"
            class="border-t border-gray-100 bg-white lg:hidden"
        >
            <div class="space-y-1 px-5 py-4 sm:px-6">
                <a
                    href="index.php"
                    class="block rounded-xl bg-red-50 px-4 py-3 text-sm font-bold text-red-600"
                >
                    Home
                </a>

                <a
                    href="customer/home.php"
                    class="block rounded-xl px-4 py-3 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-red-600"
                >
                    Catalog
                </a>

                <a
                    href="#rankings"
                    class="block rounded-xl px-4 py-3 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-red-600"
                    data-close-menu
                >
                    Rankings
                </a>

                <a
                    href="#new-releases"
                    class="block rounded-xl px-4 py-3 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-red-600"
                    data-close-menu
                >
                    New Releases
                </a>

                <a
                    href="tier.php"
                    class="block rounded-xl px-4 py-3 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-red-600"
                >
                    Membership
                </a>

                <div class="mt-3 border-t border-gray-100 pt-3">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <a
                            href="customer/profile.php"
                            class="block rounded-xl px-4 py-3 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-red-600"
                        >
                            My account
                        </a>

                        <a
                            href="customer/cart.php"
                            class="block rounded-xl px-4 py-3 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-red-600"
                        >
                            Cart<?= $cart_count > 0
                                ? ' (' . $cart_count . ')'
                                : '' ?>
                        </a>
                    <?php else: ?>
                        <a
                            href="login.php"
                            class="block rounded-xl px-4 py-3 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-red-600"
                        >
                            Login
                        </a>

                        <a
                            href="register.php"
                            class="mt-2 block rounded-xl bg-red-600 px-4 py-3 text-center text-sm font-bold text-white"
                        >
                            Create account
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <div
        id="mobileOverlay"
        class="fixed inset-0 z-40 hidden bg-black/40 backdrop-blur-sm lg:hidden"
    ></div>

    <!-- Hero -->
    <section
        class="relative min-h-[680px] overflow-hidden bg-gray-950 lg:h-[720px]"
        aria-label="Featured MangaVault highlights"
    >
        <div
            id="heroSlider"
            class="flex h-full min-h-[680px] transition-transform duration-700 ease-[cubic-bezier(0.77,0,0.175,1)] lg:h-[720px]"
            style="width: 300%;"
        >
            <!-- Slide 1 -->
            <article
                class="relative h-full min-h-[680px] flex-shrink-0 overflow-hidden lg:h-[720px]"
                style="width: 33.333333%;"
            >
                <div
                    class="absolute inset-0 bg-cover bg-center"
                    style="background-image: url('<?= htmlspecialchars(
                        $main_hero_background,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>');"
                ></div>

                <div
                    class="absolute inset-0 bg-[linear-gradient(100deg,rgba(7,10,18,0.97)_0%,rgba(7,10,18,0.86)_48%,rgba(7,10,18,0.42)_72%,rgba(7,10,18,0.28)_100%)]"
                ></div>

                <div
                    class="hero-grid absolute inset-0 opacity-30"
                ></div>

                <div
                    class="relative mx-auto grid h-full max-w-7xl items-center gap-12 px-6 py-20 lg:grid-cols-[1.05fr_0.95fr] lg:px-10"
                >
                    <div class="max-w-2xl">
                        <div
                            class="mb-6 inline-flex items-center gap-3 rounded-full border border-white/10 bg-white/5 px-4 py-2 backdrop-blur-md"
                        >
                            <span
                                class="h-2 w-2 rounded-full bg-red-500 shadow-[0_0_16px_rgba(239,68,68,0.9)]"
                            ></span>

                            <p
                                class="text-[11px] font-black uppercase tracking-[0.24em] text-white/70"
                            >
                                Malaysia's Manga Destination
                            </p>
                        </div>

                        <h1
                            class="max-w-xl text-5xl font-black leading-[0.92] tracking-[-0.055em] text-white sm:text-6xl lg:text-7xl xl:text-[5.4rem]"
                        >
                            Your next
                            <span
                                class="block bg-gradient-to-r from-red-500 via-red-400 to-orange-300 bg-clip-text text-transparent"
                            >
                                great story
                            </span>
                            starts here.
                        </h1>

                        <p
                            class="mt-7 max-w-xl text-base leading-7 text-white/60 sm:text-lg"
                        >
                            Explore physical manga and instant e-books,
                            discover new series, and build a collection
                            that feels completely yours.
                        </p>

                        <div
                            class="mt-9 flex flex-col gap-3 sm:flex-row"
                        >
                            <a
                                href="customer/home.php"
                                class="inline-flex items-center justify-center gap-3 rounded-xl bg-red-600 px-7 py-4 text-sm font-black uppercase tracking-[0.14em] text-white shadow-2xl shadow-red-950/50 transition hover:-translate-y-1 hover:bg-red-500"
                            >
                                Browse collection
                                <span aria-hidden="true">→</span>
                            </a>

                            <a
                                href="#rankings"
                                class="inline-flex items-center justify-center rounded-xl border border-white/20 bg-white/5 px-7 py-4 text-sm font-black uppercase tracking-[0.14em] text-white backdrop-blur-md transition hover:-translate-y-1 hover:border-white/40 hover:bg-white/10"
                            >
                                See what's trending
                            </a>
                        </div>

                        <div
                            class="mt-10 flex flex-wrap items-center gap-x-8 gap-y-4 text-xs font-bold uppercase tracking-[0.14em] text-white/35"
                        >
                            <span>Physical manga</span>
                            <span>E-books</span>
                            <span>Member rewards</span>
                        </div>
                    </div>

                    <div
                        class="relative hidden h-[500px] lg:block"
                        aria-hidden="true"
                    >
                        <div
                            class="absolute left-1/2 top-1/2 h-80 w-80 -translate-x-1/2 -translate-y-1/2 rounded-full bg-red-600/25 blur-[90px]"
                        ></div>

                        <?php foreach (
                            array_slice($rankings, 0, 3) as $index => $product
                        ): ?>
                            <?php
                            $book_positions = [
                                [
                                    'class' =>
                                        'left-4 top-28 w-44',
                                    'rotate' => '-10deg',
                                ],
                                [
                                    'class' =>
                                        'left-1/2 top-6 z-20 w-52 -translate-x-1/2',
                                    'rotate' => '3deg',
                                ],
                                [
                                    'class' =>
                                        'right-1 top-36 w-40',
                                    'rotate' => '11deg',
                                ],
                            ];
                            $position =
                                $book_positions[$index];
                            $cover_url = coverImageUrl(
                                $product['product_cover_image'] ?? null
                            );
                            ?>

                            <div
                                class="hero-book absolute <?= $position['class'] ?>"
                                style="--book-rotate: <?= $position['rotate'] ?>;"
                            >
                                <div
                                    class="hero-book-motion overflow-hidden rounded-xl border border-white/10 bg-white/10 p-2 shadow-[0_32px_80px_rgba(0,0,0,0.55)] backdrop-blur-xl"
                                >
                                    <div
                                        class="cover-stage aspect-[2/3] rounded-lg"
                                    >
                                        <?php if ($cover_url !== ''): ?>
                                            <img
                                                src="<?= htmlspecialchars(
                                                    $cover_url,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                                alt=""
                                                class="cover-background"
                                            >

                                            <img
                                                src="<?= htmlspecialchars(
                                                    $cover_url,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                                alt=""
                                                class="cover-foreground"
                                            >
                                        <?php else: ?>
                                            <div
                                                class="flex h-full items-center justify-center bg-gray-800 text-3xl font-black text-white/50"
                                            >
                                                <?= htmlspecialchars(
                                                    strtoupper(
                                                        substr(
                                                            (string) $product[
                                                                'product_title'
                                                            ],
                                                            0,
                                                            2
                                                        )
                                                    ),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </article>

            <!-- Slide 2 -->
            <article
                class="relative h-full min-h-[680px] flex-shrink-0 overflow-hidden bg-[#130e09] lg:h-[720px]"
                style="width: 33.333333%;"
            >
                <?php if (
                    $rankings_hero_background !== ''
                ): ?>
                    <div
                        class="absolute inset-0 bg-cover bg-center opacity-45"
                        style="background-image: url('<?= htmlspecialchars(
                            $rankings_hero_background,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>');"
                    ></div>

                    <div
                        class="absolute inset-0 bg-[#130e09]/80"
                    ></div>
                <?php endif; ?>

                <div
                    class="absolute inset-0 bg-[radial-gradient(circle_at_70%_45%,rgba(245,158,11,0.22),transparent_34%),radial-gradient(circle_at_20%_80%,rgba(220,38,38,0.13),transparent_30%)]"
                ></div>

                <div
                    class="hero-grid absolute inset-0 opacity-30"
                ></div>

                <div
                    class="relative mx-auto grid h-full max-w-7xl items-center gap-10 px-6 py-20 lg:grid-cols-[0.8fr_1.2fr] lg:px-10"
                >
                    <div class="max-w-lg">
                        <div
                            class="mb-6 inline-flex items-center gap-2 rounded-full border border-amber-400/20 bg-amber-400/10 px-4 py-2"
                        >
                            <span class="text-sm">🔥</span>
                            <span
                                class="text-[11px] font-black uppercase tracking-[0.22em] text-amber-300"
                            >
                                Reader favourites
                            </span>
                        </div>

                        <h2
                            class="text-5xl font-black leading-[0.94] tracking-[-0.05em] text-white sm:text-6xl"
                        >
                            This week's
                            <span class="block text-amber-400">
                                top reads.
                            </span>
                        </h2>

                        <p
                            class="mt-6 max-w-md text-base leading-7 text-white/50"
                        >
                            See the manga readers are picking up right now,
                            ranked using MangaVault sales activity.
                        </p>

                        <a
                            href="#rankings"
                            class="mt-9 inline-flex items-center gap-3 rounded-xl border border-amber-400/30 bg-amber-400/10 px-6 py-3.5 text-sm font-black uppercase tracking-[0.14em] text-amber-300 transition hover:-translate-y-1 hover:bg-amber-400 hover:text-gray-950"
                        >
                            View full ranking
                            <span aria-hidden="true">→</span>
                        </a>
                    </div>

                    <div
                        class="grid grid-cols-3 items-end gap-3 sm:gap-5"
                    >
                        <?php foreach (
                            array_slice($rankings, 0, 3) as $index => $product
                        ): ?>
                            <?php
                            $cover_url = coverImageUrl(
                                $product['product_cover_image'] ?? null
                            );
                            $rank_styles = [
                                'order-2 scale-100',
                                'order-1 scale-[0.88]',
                                'order-3 scale-[0.82]',
                            ];
                            ?>

                            <a
                                href="customer/product_detail.php?id=<?= (int) $product[
                                    'product_id'
                                ] ?>"
                                class="group <?= $rank_styles[$index] ?> origin-bottom transition duration-300 hover:-translate-y-3"
                            >
                                <div
                                    class="relative overflow-hidden rounded-2xl border border-white/10 bg-white/10 p-2 shadow-[0_30px_80px_rgba(0,0,0,0.55)] backdrop-blur-xl"
                                >
                                    <div
                                        class="cover-stage aspect-[2/3] rounded-xl"
                                    >
                                        <?php if ($cover_url !== ''): ?>
                                            <img
                                                src="<?= htmlspecialchars(
                                                    $cover_url,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                                alt=""
                                                class="cover-background"
                                            >

                                            <img
                                                src="<?= htmlspecialchars(
                                                    $cover_url,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                                alt="<?= htmlspecialchars(
                                                    (string) $product[
                                                        'product_title'
                                                    ],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?> cover"
                                                class="cover-foreground"
                                            >
                                        <?php else: ?>
                                            <div
                                                class="flex h-full items-center justify-center bg-gray-800 text-3xl font-black text-white/50"
                                            >
                                                <?= htmlspecialchars(
                                                    strtoupper(
                                                        substr(
                                                            (string) $product[
                                                                'product_title'
                                                            ],
                                                            0,
                                                            2
                                                        )
                                                    ),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <span
                                        class="absolute left-4 top-4 flex h-10 w-10 items-center justify-center rounded-full border border-white/20 bg-gray-950/80 text-sm font-black text-white shadow-xl backdrop-blur"
                                    >
                                        <?= $index + 1 ?>
                                    </span>
                                </div>

                                <div class="mt-4 text-center">
                                    <p
                                        class="truncate text-sm font-bold text-white/80"
                                    >
                                        <?= htmlspecialchars(
                                            (string) $product[
                                                'product_title'
                                            ],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </p>

                                    <p
                                        class="mt-1 text-sm font-black text-amber-400"
                                    >
                                        RM <?= number_format(
                                            (float) $product[
                                                'product_price'
                                            ],
                                            2
                                        ) ?>
                                    </p>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </article>

            <!-- Slide 3 -->
            <article
                class="relative h-full min-h-[680px] flex-shrink-0 overflow-hidden bg-[#101332] lg:h-[720px]"
                style="width: 33.333333%;"
            >
                <?php if (
                    $ebook_hero_background !== ''
                ): ?>
                    <div
                        class="absolute inset-0 bg-cover bg-center opacity-40"
                        style="background-image: url('<?= htmlspecialchars(
                            $ebook_hero_background,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>');"
                    ></div>

                    <div
                        class="absolute inset-0 bg-[#101332]/82"
                    ></div>
                <?php endif; ?>

                <div
                    class="absolute inset-0 bg-[radial-gradient(circle_at_72%_45%,rgba(99,102,241,0.35),transparent_32%),radial-gradient(circle_at_15%_85%,rgba(168,85,247,0.18),transparent_32%)]"
                ></div>

                <div
                    class="hero-grid absolute inset-0 opacity-40"
                ></div>

                <div
                    class="relative mx-auto grid h-full max-w-7xl items-center gap-14 px-6 py-20 lg:grid-cols-[0.95fr_1.05fr] lg:px-10"
                >
                    <div class="max-w-xl">
                        <div
                            class="mb-6 inline-flex items-center gap-2 rounded-full border border-indigo-300/20 bg-indigo-300/10 px-4 py-2"
                        >
                            <span class="text-sm">📱</span>
                            <span
                                class="text-[11px] font-black uppercase tracking-[0.22em] text-indigo-200"
                            >
                                Digital collection
                            </span>
                        </div>

                        <h2
                            class="text-5xl font-black leading-[0.94] tracking-[-0.05em] text-white sm:text-6xl lg:text-7xl"
                        >
                            Read anywhere.
                            <span
                                class="block bg-gradient-to-r from-indigo-300 to-fuchsia-300 bg-clip-text text-transparent"
                            >
                                Start instantly.
                            </span>
                        </h2>

                        <p
                            class="mt-6 max-w-lg text-base leading-7 text-white/50"
                        >
                            Purchase an e-book and access it from your
                            MangaVault library without waiting for delivery.
                        </p>

                        <div
                            class="mt-8 flex flex-wrap gap-3 text-xs font-black uppercase tracking-[0.13em] text-white/60"
                        >
                            <span
                                class="rounded-full border border-white/10 bg-white/5 px-4 py-2"
                            >
                                Instant access
                            </span>
                            <span
                                class="rounded-full border border-white/10 bg-white/5 px-4 py-2"
                            >
                                Secure library
                            </span>
                            <span
                                class="rounded-full border border-white/10 bg-white/5 px-4 py-2"
                            >
                                Download limits protected
                            </span>
                        </div>

                        <a
                            href="customer/home.php?type=ebook"
                            class="mt-9 inline-flex items-center gap-3 rounded-xl bg-indigo-500 px-7 py-4 text-sm font-black uppercase tracking-[0.14em] text-white shadow-2xl shadow-indigo-950/50 transition hover:-translate-y-1 hover:bg-indigo-400"
                        >
                            Browse e-books
                            <span aria-hidden="true">→</span>
                        </a>
                    </div>

                    <div
                        class="relative hidden h-[510px] items-center justify-center lg:flex"
                        aria-hidden="true"
                    >
                        <div
                            class="absolute h-80 w-80 rounded-full bg-indigo-500/25 blur-[90px]"
                        ></div>

                        <div
                            class="relative w-[330px] rounded-[2.25rem] border border-white/15 bg-gray-950 p-3 shadow-[0_40px_100px_rgba(0,0,0,0.6)]"
                        >
                            <div
                                class="relative aspect-[4/5] overflow-hidden rounded-[1.7rem] bg-[#1b204d]"
                            >
                                <div
                                    class="absolute inset-x-0 top-0 z-20 flex items-center justify-between px-5 py-4 text-[10px] font-black uppercase tracking-[0.18em] text-white/45"
                                >
                                    <span>MangaVault Reader</span>
                                    <span>100%</span>
                                </div>

                                <?php
                                $ebook_cover = coverImageUrl(
                                    $featured_ebook[
                                        'product_cover_image'
                                    ] ?? null
                                );
                                ?>

                                <?php if ($ebook_cover !== ''): ?>
                                    <img
                                        src="<?= htmlspecialchars(
                                            $ebook_cover,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        alt=""
                                        class="absolute inset-0 h-full w-full object-cover opacity-25 blur-xl"
                                    >

                                    <img
                                        src="<?= htmlspecialchars(
                                            $ebook_cover,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        alt=""
                                        class="absolute inset-x-10 bottom-14 top-14 rounded-lg object-contain shadow-2xl"
                                    >
                                <?php else: ?>
                                    <div
                                        class="absolute inset-x-10 bottom-14 top-14 flex items-center justify-center rounded-xl border border-white/10 bg-white/5 text-5xl font-black text-white/20"
                                    >
                                        MV
                                    </div>
                                <?php endif; ?>

                                <div
                                    class="absolute inset-x-5 bottom-5 flex items-center justify-between rounded-xl border border-white/10 bg-black/30 px-4 py-3 backdrop-blur-xl"
                                >
                                    <span
                                        class="text-xs font-bold text-white/75"
                                    >
                                        <?= htmlspecialchars(
                                            (string) (
                                                $featured_ebook[
                                                    'product_title'
                                                ] ??
                                                'Your digital manga'
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </span>

                                    <span class="text-indigo-300">•••</span>
                                </div>
                            </div>
                        </div>

                        <div
                            class="absolute right-0 top-16 rounded-2xl border border-white/10 bg-white/10 px-5 py-4 shadow-2xl backdrop-blur-xl"
                        >
                            <p
                                class="text-[10px] font-black uppercase tracking-[0.18em] text-white/35"
                            >
                                Access
                            </p>
                            <p class="mt-1 text-lg font-black text-white">
                                Instant
                            </p>
                        </div>

                        <div
                            class="absolute bottom-20 left-0 rounded-2xl border border-white/10 bg-white/10 px-5 py-4 shadow-2xl backdrop-blur-xl"
                        >
                            <p
                                class="text-[10px] font-black uppercase tracking-[0.18em] text-white/35"
                            >
                                Stored in
                            </p>
                            <p class="mt-1 text-lg font-black text-white">
                                My Library
                            </p>
                        </div>
                    </div>
                </div>
            </article>
        </div>

        <div
            class="absolute bottom-7 left-1/2 z-20 flex -translate-x-1/2 items-center gap-3"
            role="tablist"
            aria-label="Hero slides"
        >
            <?php for ($slide = 0; $slide < 3; $slide++): ?>
                <button
                    type="button"
                    class="hero-dot h-2.5 rounded-full transition-all duration-300 <?= $slide === 0
                        ? 'w-9 bg-white'
                        : 'w-2.5 bg-white/30' ?>"
                    data-slide="<?= $slide ?>"
                    aria-label="Show slide <?= $slide + 1 ?>"
                    aria-current="<?= $slide === 0
                        ? 'true'
                        : 'false' ?>"
                ></button>
            <?php endfor; ?>
        </div>

        <button
            id="previousSlide"
            type="button"
            class="absolute left-4 top-1/2 z-20 hidden h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full border border-white/10 bg-black/20 text-2xl text-white/60 backdrop-blur-md transition hover:border-white/25 hover:bg-white/10 hover:text-white sm:flex"
            aria-label="Previous hero slide"
        >
            ‹
        </button>

        <button
            id="nextSlide"
            type="button"
            class="absolute right-4 top-1/2 z-20 hidden h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full border border-white/10 bg-black/20 text-2xl text-white/60 backdrop-blur-md transition hover:border-white/25 hover:bg-white/10 hover:text-white sm:flex"
            aria-label="Next hero slide"
        >
            ›
        </button>

        <div
            class="absolute bottom-0 left-0 z-20 h-1 w-full bg-white/10"
            aria-hidden="true"
        >
            <div
                id="heroProgress"
                class="hero-progress-fill h-full bg-red-500"
            ></div>
        </div>
    </section>

    <!-- Trust strip -->
    <section
        class="relative z-20 border-b border-gray-200 bg-white"
        aria-label="MangaVault services"
    >
        <div
            class="mx-auto grid max-w-7xl grid-cols-2 divide-x divide-y divide-gray-100 px-5 sm:px-6 lg:grid-cols-4 lg:divide-y-0"
        >
            <?php
            $features = [
                [
                    'icon' => '🚚',
                    'title' => 'Nationwide delivery',
                    'text' => 'Peninsular and East Malaysia',
                ],
                [
                    'icon' => '🔒',
                    'title' => 'Secure checkout',
                    'text' => 'Stripe card payment protection',
                ],
                [
                    'icon' => '📱',
                    'title' => 'Instant e-books',
                    'text' => 'Access from your digital library',
                ],
                [
                    'icon' => '✦',
                    'title' => 'Member rewards',
                    'text' => 'Points, tiers and shipping benefits',
                ],
            ];
            ?>

            <?php foreach ($features as $feature): ?>
                <div
                    class="flex items-center gap-4 px-4 py-6 sm:px-6 lg:py-7"
                >
                    <div
                        class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-2xl bg-gray-950 text-lg shadow-lg shadow-gray-200"
                    >
                        <?= $feature['icon'] ?>
                    </div>

                    <div>
                        <p class="text-sm font-black text-gray-900">
                            <?= htmlspecialchars(
                                $feature['title'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </p>

                        <p
                            class="mt-1 text-xs leading-5 text-gray-400"
                        >
                            <?= htmlspecialchars(
                                $feature['text'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Rankings -->
    <section id="rankings" class="bg-[#fffdf9] py-20 lg:py-24">
        <div class="mx-auto max-w-7xl px-5 sm:px-6">
            <div
                class="mb-10 flex flex-col justify-between gap-5 sm:flex-row sm:items-end"
            >
                <div>
                    <p
                        class="section-kicker text-xs font-black uppercase text-red-600"
                    >
                        Reader favourites
                    </p>

                    <h2
                        class="mt-3 text-3xl font-black tracking-[-0.04em] text-gray-950 sm:text-4xl"
                    >
                        Weekly Manga Rankings
                    </h2>

                    <p
                        class="mt-3 max-w-xl text-sm leading-6 text-gray-500"
                    >
                        The most purchased available titles currently
                        trending across MangaVault.
                    </p>
                </div>

                <a
                    href="customer/home.php"
                    class="inline-flex items-center gap-2 text-sm font-black text-gray-800 transition hover:text-red-600"
                >
                    Browse all titles
                    <span aria-hidden="true">→</span>
                </a>
            </div>

            <?php if (!$rankings): ?>
                <div
                    class="rounded-3xl border border-dashed border-gray-300 bg-white px-6 py-16 text-center"
                >
                    <p class="text-sm font-semibold text-gray-400">
                        No rankings are available yet.
                    </p>
                </div>
            <?php else: ?>
                <div
                    class="grid grid-cols-2 gap-4 sm:grid-cols-3 sm:gap-6 lg:grid-cols-5"
                >
                    <?php foreach ($rankings as $index => $product): ?>
                        <?php
                        $cover_url = coverImageUrl(
                            $product['product_cover_image'] ?? null
                        );
                        $rank_label = str_pad(
                            (string) ($index + 1),
                            2,
                            '0',
                            STR_PAD_LEFT
                        );
                        ?>

                        <a
                            href="customer/product_detail.php?id=<?= (int) $product[
                                'product_id'
                            ] ?>"
                            class="product-card group flex flex-col overflow-hidden rounded-2xl border border-gray-100 bg-white transition duration-300 hover:-translate-y-2"
                        >
                            <div class="cover-stage aspect-[2/3]">
                                <?php if ($cover_url !== ''): ?>
                                    <img
                                        src="<?= htmlspecialchars(
                                            $cover_url,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        alt=""
                                        class="cover-background"
                                    >

                                    <img
                                        src="<?= htmlspecialchars(
                                            $cover_url,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        alt="<?= htmlspecialchars(
                                            (string) $product[
                                                'product_title'
                                            ],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?> cover"
                                        class="cover-foreground"
                                    >
                                <?php else: ?>
                                    <div
                                        class="flex h-full items-center justify-center bg-gray-100 text-4xl font-black text-gray-300"
                                    >
                                        <?= htmlspecialchars(
                                            strtoupper(
                                                substr(
                                                    (string) $product[
                                                        'product_title'
                                                    ],
                                                    0,
                                                    2
                                                )
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </div>
                                <?php endif; ?>

                                <span
                                    class="absolute left-3 top-3 z-10 flex h-10 w-10 items-center justify-center rounded-full border border-white/30 <?= $index === 0
                                        ? 'bg-amber-400 text-gray-950'
                                        : ($index === 1
                                            ? 'bg-slate-400 text-white'
                                            : ($index === 2
                                                ? 'bg-orange-700 text-white'
                                                : 'bg-gray-950 text-white')) ?> text-xs font-black shadow-xl backdrop-blur"
                                >
                                    <?= $rank_label ?>
                                </span>
                            </div>

                            <div class="flex flex-1 flex-col p-4">
                                <p
                                    class="text-[10px] font-black uppercase tracking-[0.16em] text-gray-400"
                                >
                                    Rank <?= $rank_label ?>
                                </p>

                                <h3
                                    class="line-clamp-2 mt-2 min-h-[2.5rem] text-sm font-black leading-5 text-gray-900"
                                >
                                    <?= htmlspecialchars(
                                        (string) $product[
                                            'product_title'
                                        ],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </h3>

                                <p
                                    class="mt-1 truncate text-xs text-gray-400"
                                >
                                    <?= htmlspecialchars(
                                        (string) (
                                            $product[
                                                'product_author'
                                            ] ??
                                            'MangaVault selection'
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>

                                <div
                                    class="mt-4 flex items-end justify-between gap-2"
                                >
                                    <p
                                        class="text-base font-black text-red-600"
                                    >
                                        RM <?= number_format(
                                            (float) $product[
                                                'product_price'
                                            ],
                                            2
                                        ) ?>
                                    </p>

                                    <span
                                        class="text-xs font-black text-gray-300 transition group-hover:translate-x-1 group-hover:text-red-600"
                                    >
                                        →
                                    </span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Genres -->
    <section id="genres" class="bg-[#f4eee7] py-20 lg:py-24">
        <div class="mx-auto max-w-7xl px-5 sm:px-6">
            <div class="mb-10 max-w-2xl">
                <p
                    class="section-kicker text-xs font-black uppercase text-red-600"
                >
                    Find your mood
                </p>

                <h2
                    class="mt-3 text-3xl font-black tracking-[-0.04em] text-gray-950 sm:text-4xl"
                >
                    Explore Manga Genres
                </h2>

                <p class="mt-3 text-sm leading-6 text-gray-500">
                    Jump straight into the stories, worlds and themes you
                    enjoy most.
                </p>
            </div>

            <?php if (!$genres): ?>
                <div
                    class="rounded-3xl border border-dashed border-gray-300 bg-white/70 px-6 py-16 text-center"
                >
                    <p class="text-sm font-semibold text-gray-400">
                        No genres are available yet.
                    </p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <?php foreach ($genres as $genre): ?>
                        <?php
                        $genre_key = strtolower(
                            trim(
                                (string) $genre['genre_name']
                            )
                        );
                        $visual =
                            $genre_visuals[$genre_key] ??
                            [
                                'icon' => '✦',
                                'eyebrow' =>
                                    'Discover something new',
                                'background' =>
                                    'linear-gradient(135deg, #1e293b 0%, #475569 55%, #94a3b8 100%)',
                            ];
                        ?>

                        <a
                            href="customer/home.php?genre_id=<?= (int) $genre[
                                'genre_id'
                            ] ?>"
                            class="genre-card group relative min-h-[260px] overflow-hidden rounded-3xl p-6 text-white shadow-[0_18px_45px_rgba(31,41,55,0.13)] transition duration-300 hover:-translate-y-2 hover:shadow-[0_26px_60px_rgba(31,41,55,0.2)]"
                            style="background: <?= htmlspecialchars(
                                $visual['background'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>;"
                        >
                            <div
                                class="absolute inset-0 bg-[linear-gradient(180deg,rgba(255,255,255,0.06),rgba(0,0,0,0.22))]"
                            ></div>

                            <span
                                class="absolute right-5 top-3 text-8xl font-black text-white/10 transition duration-500 group-hover:scale-110 group-hover:text-white/15"
                                aria-hidden="true"
                            >
                                <?= $visual['icon'] ?>
                            </span>

                            <div
                                class="relative z-10 flex h-full min-h-[212px] flex-col justify-between"
                            >
                                <div>
                                    <p
                                        class="text-[10px] font-black uppercase tracking-[0.18em] text-white/55"
                                    >
                                        <?= htmlspecialchars(
                                            $visual['eyebrow'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </p>

                                    <h3
                                        class="mt-3 text-3xl font-black tracking-[-0.04em]"
                                    >
                                        <?= htmlspecialchars(
                                            (string) $genre[
                                                'genre_name'
                                            ],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </h3>
                                </div>

                                <div
                                    class="flex items-center justify-between"
                                >
                                    <span
                                        class="rounded-full border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-bold backdrop-blur"
                                    >
                                        <?= (int) $genre[
                                            'product_count'
                                        ] ?> titles
                                    </span>

                                    <span
                                        class="flex h-10 w-10 items-center justify-center rounded-full border border-white/20 bg-white/10 text-lg transition group-hover:translate-x-1 group-hover:bg-white group-hover:text-gray-950"
                                    >
                                        →
                                    </span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- New Releases -->
    <section
        id="new-releases"
        class="bg-white py-20 lg:py-24"
    >
        <div class="mx-auto max-w-7xl px-5 sm:px-6">
            <div
                class="mb-10 flex flex-col justify-between gap-5 sm:flex-row sm:items-end"
            >
                <div>
                    <p
                        class="section-kicker text-xs font-black uppercase text-red-600"
                    >
                        Fresh on the shelf
                    </p>

                    <h2
                        class="mt-3 text-3xl font-black tracking-[-0.04em] text-gray-950 sm:text-4xl"
                    >
                        New Releases
                    </h2>

                    <p
                        class="mt-3 max-w-xl text-sm leading-6 text-gray-500"
                    >
                        Recently added physical editions and digital
                        releases ready to explore.
                    </p>
                </div>

                <a
                    href="customer/home.php"
                    class="inline-flex items-center gap-2 text-sm font-black text-gray-800 transition hover:text-red-600"
                >
                    View full catalog
                    <span aria-hidden="true">→</span>
                </a>
            </div>

            <?php if (!$new_releases): ?>
                <div
                    class="rounded-3xl border border-dashed border-gray-300 bg-gray-50 px-6 py-16 text-center"
                >
                    <p class="text-sm font-semibold text-gray-400">
                        No new products are available yet.
                    </p>
                </div>
            <?php else: ?>
                <div
                    class="grid grid-cols-2 gap-4 sm:gap-6 lg:grid-cols-4"
                >
                    <?php foreach ($new_releases as $product): ?>
                        <?php
                        $cover_url = coverImageUrl(
                            $product['product_cover_image'] ?? null
                        );
                        $product_type_label =
                            $product['product_type'] === 'ebook'
                                ? 'E-Book'
                                : 'Physical';
                        ?>

                        <a
                            href="customer/product_detail.php?id=<?= (int) $product[
                                'product_id'
                            ] ?>"
                            class="product-card group flex flex-col overflow-hidden rounded-2xl border border-gray-100 bg-[#fffdf9] transition duration-300 hover:-translate-y-2"
                        >
                            <div class="cover-stage aspect-[2/3]">
                                <?php if ($cover_url !== ''): ?>
                                    <img
                                        src="<?= htmlspecialchars(
                                            $cover_url,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        alt=""
                                        class="cover-background"
                                    >

                                    <img
                                        src="<?= htmlspecialchars(
                                            $cover_url,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        alt="<?= htmlspecialchars(
                                            (string) $product[
                                                'product_title'
                                            ],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?> cover"
                                        class="cover-foreground"
                                    >
                                <?php else: ?>
                                    <div
                                        class="flex h-full items-center justify-center bg-gray-100 text-4xl font-black text-gray-300"
                                    >
                                        <?= htmlspecialchars(
                                            strtoupper(
                                                substr(
                                                    (string) $product[
                                                        'product_title'
                                                    ],
                                                    0,
                                                    2
                                                )
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </div>
                                <?php endif; ?>

                                <span
                                    class="absolute left-3 top-3 z-10 rounded-full bg-red-600 px-3 py-1.5 text-[10px] font-black uppercase tracking-[0.14em] text-white shadow-xl"
                                >
                                    New
                                </span>

                                <span
                                    class="absolute bottom-3 right-3 z-10 rounded-full border border-white/30 bg-gray-950/75 px-3 py-1.5 text-[10px] font-black uppercase tracking-[0.12em] text-white backdrop-blur"
                                >
                                    <?= htmlspecialchars(
                                        $product_type_label,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>
                            </div>

                            <div class="flex flex-1 flex-col p-4 sm:p-5">
                                <h3
                                    class="line-clamp-2 min-h-[2.5rem] text-sm font-black leading-5 text-gray-900 sm:text-base"
                                >
                                    <?= htmlspecialchars(
                                        (string) $product[
                                            'product_title'
                                        ],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </h3>

                                <p
                                    class="mt-1 truncate text-xs text-gray-400"
                                >
                                    <?= htmlspecialchars(
                                        (string) (
                                            $product[
                                                'product_author'
                                            ] ??
                                            'MangaVault selection'
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>

                                <div
                                    class="mt-5 flex items-center justify-between"
                                >
                                    <p
                                        class="text-base font-black text-red-600 sm:text-lg"
                                    >
                                        RM <?= number_format(
                                            (float) $product[
                                                'product_price'
                                            ],
                                            2
                                        ) ?>
                                    </p>

                                    <span
                                        class="flex h-9 w-9 items-center justify-center rounded-full bg-gray-950 text-sm font-black text-white transition group-hover:translate-x-1 group-hover:bg-red-600"
                                    >
                                        →
                                    </span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Membership CTA -->
    <section class="relative overflow-hidden bg-[#17233b] py-20 lg:py-24">
        <div
            class="absolute -left-28 top-10 h-80 w-80 rounded-full bg-red-600/15 blur-[100px]"
        ></div>

        <div
            class="absolute -right-24 bottom-0 h-96 w-96 rounded-full bg-blue-400/15 blur-[110px]"
        ></div>

        <div
            class="hero-grid absolute inset-0 opacity-20"
        ></div>

        <div
            class="relative mx-auto grid max-w-7xl items-center gap-12 px-5 sm:px-6 lg:grid-cols-[1fr_0.95fr]"
        >
            <div>
                <p
                    class="section-kicker text-xs font-black uppercase text-blue-300"
                >
                    MangaVault membership
                </p>

                <h2
                    class="mt-4 max-w-2xl text-4xl font-black tracking-[-0.045em] text-white sm:text-5xl"
                >
                    The more you collect,
                    <span class="text-blue-300">
                        the more you unlock.
                    </span>
                </h2>

                <p
                    class="mt-5 max-w-xl text-base leading-7 text-white/55"
                >
                    Earn points from confirmed purchases, progress through
                    membership tiers and receive benefits designed for
                    regular readers.
                </p>

                <a
                    href="tier.php"
                    class="mt-8 inline-flex items-center gap-3 rounded-xl bg-blue-300 px-7 py-4 text-sm font-black uppercase tracking-[0.14em] text-[#17233b] shadow-2xl shadow-blue-950/30 transition hover:-translate-y-1 hover:bg-white"
                >
                    Explore membership
                    <span aria-hidden="true">→</span>
                </a>
            </div>

            <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-1">
                <?php
                $member_benefits = [
                    [
                        'number' => '01',
                        'title' => 'Earn points',
                        'text' =>
                            'Confirmed purchases build your MangaVault points balance.',
                    ],
                    [
                        'number' => '02',
                        'title' => 'Progress through tiers',
                        'text' =>
                            'Lifetime spending unlocks higher membership benefits.',
                    ],
                    [
                        'number' => '03',
                        'title' => 'Enjoy reader perks',
                        'text' =>
                            'Eligible tiers receive shipping and reward advantages.',
                    ],
                ];
                ?>

                <?php foreach ($member_benefits as $benefit): ?>
                    <div
                        class="flex gap-5 rounded-2xl border border-white/10 bg-white/[0.06] p-5 backdrop-blur-xl"
                    >
                        <span
                            class="text-xs font-black tracking-[0.16em] text-blue-300"
                        >
                            <?= $benefit['number'] ?>
                        </span>

                        <div>
                            <h3 class="font-black text-white">
                                <?= htmlspecialchars(
                                    $benefit['title'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </h3>

                            <p
                                class="mt-1 text-sm leading-6 text-white/45"
                            >
                                <?= htmlspecialchars(
                                    $benefit['text'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-[#0d1424] text-white">
        <div class="mx-auto max-w-7xl px-5 py-14 sm:px-6">
            <div
                class="grid gap-10 border-b border-white/10 pb-12 sm:grid-cols-2 lg:grid-cols-[1.2fr_0.8fr_0.8fr_1fr]"
            >
                <div>
                    <a
                        href="index.php"
                        class="inline-flex items-center gap-3"
                    >
                        <span
                            class="flex h-10 w-10 items-center justify-center rounded-xl bg-red-600 text-sm font-black text-white"
                        >
                            MV
                        </span>

                        <span
                            class="text-xl font-black tracking-[0.08em]"
                        >
                            MANGA<span class="text-red-500">VAULT</span>
                        </span>
                    </a>

                    <p
                        class="mt-5 max-w-sm text-sm leading-6 text-white/45"
                    >
                        A Malaysian online destination for physical manga,
                        e-books, secure checkout and reader rewards.
                    </p>
                </div>

                <div>
                    <h3
                        class="text-xs font-black uppercase tracking-[0.2em] text-white/35"
                    >
                        Shop
                    </h3>

                    <ul class="mt-5 space-y-3 text-sm text-white/60">
                        <li>
                            <a
                                href="customer/home.php"
                                class="transition hover:text-white"
                            >
                                All manga
                            </a>
                        </li>
                        <li>
                            <a
                                href="customer/home.php?type=physical"
                                class="transition hover:text-white"
                            >
                                Physical books
                            </a>
                        </li>
                        <li>
                            <a
                                href="customer/home.php?type=ebook"
                                class="transition hover:text-white"
                            >
                                E-books
                            </a>
                        </li>
                        <li>
                            <a
                                href="#new-releases"
                                class="transition hover:text-white"
                            >
                                New releases
                            </a>
                        </li>
                    </ul>
                </div>

                <div>
                    <h3
                        class="text-xs font-black uppercase tracking-[0.2em] text-white/35"
                    >
                        Support
                    </h3>

                    <ul class="mt-5 space-y-3 text-sm text-white/60">
                        <li>
                            <a
                                href="customer/orders.php"
                                class="transition hover:text-white"
                            >
                                My orders
                            </a>
                        </li>
                        <li>
                            <a
                                href="customer/profile.php"
                                class="transition hover:text-white"
                            >
                                My account
                            </a>
                        </li>
                        <li>
                            <a
                                href="customer/faq.php"
                                class="transition hover:text-white"
                            >
                                FAQ
                            </a>
                        </li>
                        <li>
                            <a
                                href="customer/about.php"
                                class="transition hover:text-white"
                            >
                                About MangaVault
                            </a>
                        </li>
                    </ul>
                </div>

                <div>
                    <h3
                        class="text-xs font-black uppercase tracking-[0.2em] text-white/35"
                    >
                        Shopping with confidence
                    </h3>

                    <div class="mt-5 space-y-4">
                        <div
                            class="rounded-2xl border border-white/10 bg-white/[0.04] p-4"
                        >
                            <p class="text-sm font-black text-white">
                                Secure card payment
                            </p>
                            <p
                                class="mt-1 text-xs leading-5 text-white/40"
                            >
                                Checkout is processed through Stripe.
                            </p>
                        </div>

                        <div
                            class="rounded-2xl border border-white/10 bg-white/[0.04] p-4"
                        >
                            <p class="text-sm font-black text-white">
                                Customer support
                            </p>
                            <p
                                class="mt-1 text-xs leading-5 text-white/40"
                            >
                                Use FAQ or MangaBot after signing in.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div
                class="flex flex-col gap-3 pt-7 text-xs text-white/35 sm:flex-row sm:items-center sm:justify-between"
            >
                <p>
                    © 2026 MangaVault. All rights reserved.
                </p>

                <p>
                    Physical manga · E-books · Membership rewards
                </p>
            </div>
        </div>
    </footer>

    <script>
        const heroSlider = document.getElementById(
            'heroSlider'
        );
        const heroDots = document.querySelectorAll(
            '.hero-dot'
        );
        const heroProgress = document.getElementById(
            'heroProgress'
        );
        const previousSlideButton =
            document.getElementById(
                'previousSlide'
            );
        const nextSlideButton =
            document.getElementById(
                'nextSlide'
            );
        const reduceMotion = window.matchMedia(
            '(prefers-reduced-motion: reduce)'
        ).matches;

        let currentSlide = 0;
        let heroTimer = null;
        let touchStartX = 0;

        function resetHeroProgress() {
            heroProgress.classList.remove(
                'hero-progress-fill'
            );

            void heroProgress.offsetWidth;

            if (!reduceMotion) {
                heroProgress.classList.add(
                    'hero-progress-fill'
                );
            }
        }

        function updateHeroSlide(slideIndex) {
            currentSlide =
                (slideIndex + heroDots.length) %
                heroDots.length;

            heroSlider.style.transform =
                `translateX(-${
                    currentSlide * 33.333333
                }%)`;

            heroDots.forEach((dot, index) => {
                const isActive =
                    index === currentSlide;

                dot.classList.toggle(
                    'w-9',
                    isActive
                );
                dot.classList.toggle(
                    'bg-white',
                    isActive
                );
                dot.classList.toggle(
                    'w-2.5',
                    !isActive
                );
                dot.classList.toggle(
                    'bg-white/30',
                    !isActive
                );
                dot.setAttribute(
                    'aria-current',
                    isActive ? 'true' : 'false'
                );
            });

            resetHeroProgress();
        }

        function stopHeroAutoplay() {
            if (heroTimer !== null) {
                clearInterval(heroTimer);
                heroTimer = null;
            }
        }

        function startHeroAutoplay() {
            stopHeroAutoplay();

            if (reduceMotion) {
                return;
            }

            heroTimer = setInterval(() => {
                updateHeroSlide(
                    currentSlide + 1
                );
            }, 6000);
        }

        heroDots.forEach(dot => {
            dot.addEventListener('click', () => {
                updateHeroSlide(
                    Number(dot.dataset.slide)
                );
                startHeroAutoplay();
            });
        });

        previousSlideButton.addEventListener(
            'click',
            () => {
                updateHeroSlide(
                    currentSlide - 1
                );
                startHeroAutoplay();
            }
        );

        nextSlideButton.addEventListener(
            'click',
            () => {
                updateHeroSlide(
                    currentSlide + 1
                );
                startHeroAutoplay();
            }
        );

        heroSlider.addEventListener(
            'mouseenter',
            stopHeroAutoplay
        );

        heroSlider.addEventListener(
            'mouseleave',
            startHeroAutoplay
        );

        heroSlider.addEventListener(
            'touchstart',
            event => {
                touchStartX =
                    event.changedTouches[0]
                        .clientX;
            },
            { passive: true }
        );

        heroSlider.addEventListener(
            'touchend',
            event => {
                const touchEndX =
                    event.changedTouches[0]
                        .clientX;
                const distance =
                    touchEndX - touchStartX;

                if (Math.abs(distance) < 50) {
                    return;
                }

                updateHeroSlide(
                    distance < 0
                        ? currentSlide + 1
                        : currentSlide - 1
                );
                startHeroAutoplay();
            },
            { passive: true }
        );

        updateHeroSlide(0);
        startHeroAutoplay();

        const menuButton =
            document.getElementById(
                'menuBtn'
            );
        const mobileMenu =
            document.getElementById(
                'mobileMenu'
            );
        const mobileOverlay =
            document.getElementById(
                'mobileOverlay'
            );
        const hamburgerLines =
            menuButton.querySelectorAll(
                '.hamburger-line'
            );

        function setMobileMenu(open) {
            mobileMenu.classList.toggle(
                'open',
                open
            );
            mobileOverlay.classList.toggle(
                'hidden',
                !open
            );
            document.body.classList.toggle(
                'menu-open',
                open
            );
            menuButton.setAttribute(
                'aria-expanded',
                open ? 'true' : 'false'
            );

            hamburgerLines[0].style.transform =
                open
                    ? 'translateY(8px) rotate(45deg)'
                    : '';
            hamburgerLines[1].style.opacity =
                open ? '0' : '';
            hamburgerLines[2].style.transform =
                open
                    ? 'translateY(-8px) rotate(-45deg)'
                    : '';
        }

        menuButton.addEventListener(
            'click',
            () => {
                setMobileMenu(
                    !mobileMenu.classList.contains(
                        'open'
                    )
                );
            }
        );

        mobileOverlay.addEventListener(
            'click',
            () => setMobileMenu(false)
        );

        document.querySelectorAll(
            '[data-close-menu]'
        ).forEach(link => {
            link.addEventListener(
                'click',
                () => setMobileMenu(false)
            );
        });

        document.addEventListener(
            'keydown',
            event => {
                if (event.key === 'Escape') {
                    setMobileMenu(false);
                }
            }
        );

        document.querySelectorAll(
            'a[href]'
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
                        'opacity 0.25s ease';

                    setTimeout(() => {
                        window.location.href =
                            destination.href;
                    }, 250);
                }
            );
        });
    </script>

    <script src="assets/js/index_motion.js"></script>
</body>
</html>