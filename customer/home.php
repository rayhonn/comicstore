<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_customer();

function catalogPositiveId(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    $id = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    return $id === false ? null : (int) $id;
}

function catalogCoverUrl(?string $filename): string
{
    if ($filename === null || trim($filename) === '') {
        return '';
    }

    return
        '../assets/images/' .
        rawurlencode(basename($filename));
}

$search = isset($_GET['search']) && is_string($_GET['search'])
    ? trim($_GET['search'])
    : '';

if (function_exists('mb_substr')) {
    $search = mb_substr($search, 0, 100, 'UTF-8');
} else {
    $search = substr($search, 0, 100);
}

$categoryId = catalogPositiveId(
    $_GET['category_id'] ?? null
);
$genreId = catalogPositiveId(
    $_GET['genre_id'] ?? null
);
$type = isset($_GET['type']) && is_string($_GET['type'])
    ? trim($_GET['type'])
    : '';

if (!in_array($type, ['', 'physical', 'ebook'], true)) {
    $type = '';
}

$sql = "
    SELECT DISTINCT
        p.*,
        c.category_name,
        pp.physical_stock_quantity,
        pp.physical_low_stock_threshold,
        pe.ebook_download_limit
    FROM products p
    LEFT JOIN categories c
        ON c.category_id = p.product_category_id
    LEFT JOIN product_physical pp
        ON pp.physical_product_id = p.product_id
    LEFT JOIN product_ebook pe
        ON pe.ebook_product_id = p.product_id
    LEFT JOIN product_genres pg
        ON pg.product_genres_product_id = p.product_id
    WHERE p.product_is_available = 1
";

$params = [];

if ($search !== '') {
    $sql .= "
        AND (
            p.product_title LIKE ?
            OR p.product_series LIKE ?
            OR p.product_author LIKE ?
            OR p.product_publisher LIKE ?
        )
    ";

    $searchTerm = '%' . $search . '%';
    array_push(
        $params,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm
    );
}

if ($categoryId !== null) {
    $sql .= " AND p.product_category_id = ?";
    $params[] = $categoryId;
}

if ($genreId !== null) {
    $sql .= " AND pg.product_genres_genre_id = ?";
    $params[] = $genreId;
}

if ($type !== '') {
    $sql .= " AND p.product_type = ?";
    $params[] = $type;
}

$sql .= "
    ORDER BY
        p.product_created_at DESC,
        p.product_id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rawProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$groupedProducts = [];

foreach ($rawProducts as $product) {
    $series = trim((string) ($product['product_series'] ?? ''));
    $volume = (string) ($product['product_volume_number'] ?? '0');

    $normalizedSeries = function_exists('mb_strtolower')
        ? mb_strtolower($series, 'UTF-8')
        : strtolower($series);

    $key = $series !== ''
        ? $normalizedSeries . '||' . $volume
        : 'solo_' . (int) $product['product_id'];

    if (!isset($groupedProducts[$key])) {
        $groupedProducts[$key] = [
            'physical' => null,
            'ebook' => null,
        ];
    }

    $productType = (string) $product['product_type'];

    if (array_key_exists($productType, $groupedProducts[$key])) {
        $groupedProducts[$key][$productType] = $product;
    }
}

$products = [];

foreach ($groupedProducts as $entry) {
    $main = $entry['physical'] ?? $entry['ebook'];

    if (!$main) {
        continue;
    }

    $main['has_physical'] = $entry['physical'] !== null;
    $main['has_ebook'] = $entry['ebook'] !== null;
    $main['physical_id'] = $entry['physical']['product_id'] ?? null;
    $main['ebook_id'] = $entry['ebook']['product_id'] ?? null;
    $main['physical_price'] = $entry['physical']['product_price'] ?? null;
    $main['ebook_price'] = $entry['ebook']['product_price'] ?? null;
    $main['physical_stock_quantity'] =
        $entry['physical']['physical_stock_quantity'] ?? null;
    $main['physical_low_stock_threshold'] =
        $entry['physical']['physical_low_stock_threshold'] ?? null;

    $products[] = $main;
}

$categories = $pdo->query("
    SELECT
        category_id,
        category_name
    FROM categories
    ORDER BY category_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$genres = $pdo->query("
    SELECT
        genre_id,
        genre_name
    FROM genres
    ORDER BY genre_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$selectedCategoryName = '';
foreach ($categories as $category) {
    if ((int) $category['category_id'] === $categoryId) {
        $selectedCategoryName = (string) $category['category_name'];
        break;
    }
}

$selectedGenreName = '';
foreach ($genres as $genre) {
    if ((int) $genre['genre_id'] === $genreId) {
        $selectedGenreName = (string) $genre['genre_name'];
        break;
    }
}

$hasFilters =
    $search !== '' ||
    $categoryId !== null ||
    $genreId !== null ||
    $type !== '';

$totalPhysicalFormats = 0;
$totalEbookFormats = 0;

foreach ($products as $product) {
    if ($product['has_physical']) {
        $totalPhysicalFormats++;
    }

    if ($product['has_ebook']) {
        $totalEbookFormats++;
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
    <meta
        name="description"
        content="Browse physical manga and e-books in the MangaVault catalog."
    >

    <title>Catalog - MangaVault</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        :root {
            --catalog-ink: #111827;
            --catalog-red: #dc2626;
            --catalog-paper: #fffdf9;
            --catalog-cream: #f4eee7;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            opacity: 0;
            animation: catalogFadeIn 0.4s ease forwards;
        }

        @keyframes catalogFadeIn {
            to {
                opacity: 1;
            }
        }

        .catalog-grid-pattern {
            background-image:
                linear-gradient(
                    rgba(255, 255, 255, 0.055) 1px,
                    transparent 1px
                ),
                linear-gradient(
                    90deg,
                    rgba(255, 255, 255, 0.055) 1px,
                    transparent 1px
                );
            background-size: 42px 42px;
            animation:
                catalogGridDrift
                24s
                linear
                infinite;
        }

        .catalog-hero {
            isolation: isolate;
        }

        .catalog-hero::after {
            content: '';
            position: absolute;
            left: 42%;
            top: 18%;
            width: 260px;
            height: 260px;
            border-radius: 999px;
            pointer-events: none;
            opacity: 0.18;
            background: #ef4444;
            filter: blur(90px);
            animation:
                catalogHeroOrb
                8s
                ease-in-out
                infinite alternate;
        }

        .catalog-hero-glow {
            animation:
                catalogHeroGlow
                8s
                ease-in-out
                infinite alternate;
        }

        .catalog-hero-copy > * {
            opacity: 0;
            transform: translateY(24px);
            animation:
                catalogHeroCopyIn
                0.78s
                cubic-bezier(0.22, 1, 0.36, 1)
                forwards;
        }

        .catalog-hero-copy > *:nth-child(1) {
            animation-delay: 0.08s;
        }

        .catalog-hero-copy > *:nth-child(2) {
            animation-delay: 0.17s;
        }

        .catalog-hero-copy > *:nth-child(3) {
            animation-delay: 0.27s;
        }

        .catalog-summary-grid {
            opacity: 0;
            transform:
                translateX(32px)
                scale(0.98);
            animation:
                catalogSummaryIn
                0.9s
                0.28s
                cubic-bezier(0.22, 1, 0.36, 1)
                forwards;
        }

        .catalog-hero-ring {
            transform-origin: center;
        }

        .catalog-hero-ring-large {
            animation:
                catalogRingRotate
                22s
                linear
                infinite;
        }

        .catalog-hero-ring-small {
            animation:
                catalogRingRotateReverse
                17s
                linear
                infinite;
        }

        .catalog-filter-panel {
            opacity: 0;
            transform: translateY(26px);
            animation:
                catalogFilterIn
                0.75s
                0.42s
                cubic-bezier(0.22, 1, 0.36, 1)
                forwards;
        }

        @keyframes catalogGridDrift {
            to {
                background-position:
                    42px 42px,
                    42px 42px;
            }
        }

        @keyframes catalogHeroOrb {
            to {
                opacity: 0.3;
                transform:
                    translate3d(
                        70px,
                        24px,
                        0
                    )
                    scale(1.18);
            }
        }

        @keyframes catalogHeroGlow {
            from {
                opacity: 0.82;
                transform: scale(1);
            }

            to {
                opacity: 1;
                transform: scale(1.06);
            }
        }

        @keyframes catalogHeroCopyIn {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes catalogSummaryIn {
            to {
                opacity: 1;
                transform:
                    translateX(0)
                    scale(1);
            }
        }

        @keyframes catalogFilterIn {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes catalogRingRotate {
            to {
                transform:
                    translateY(-50%)
                    rotate(360deg);
            }
        }

        @keyframes catalogRingRotateReverse {
            to {
                transform:
                    translateY(-50%)
                    rotate(-360deg);
            }
        }

        .catalog-cover-stage {
            position: relative;
            aspect-ratio: 4 / 5;
            overflow: hidden;
            background: #ebe4dc;
            isolation: isolate;
        }

        .catalog-cover-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            transition: transform 0.5s ease;
        }

        .catalog-product-card:hover .catalog-cover-image {
            transform: scale(1.045);
        }

        .catalog-product-card {
            position: relative;
            isolation: isolate;
            box-shadow:
                0 1px 2px rgba(15, 23, 42, 0.03),
                0 14px 40px rgba(15, 23, 42, 0.07);
        }

        .catalog-product-card::after {
            content: '';
            position: absolute;
            inset: 0;
            z-index: 5;
            border-radius: inherit;
            pointer-events: none;
            opacity: 0;
            background:
                linear-gradient(
                    120deg,
                    transparent 25%,
                    rgba(255, 255, 255, 0.22) 48%,
                    transparent 70%
                );
            transform: translateX(-120%);
            transition:
                opacity 0.25s ease,
                transform 0.7s
                    cubic-bezier(0.22, 1, 0.36, 1);
        }

        .catalog-product-card:hover {
            box-shadow:
                0 2px 4px rgba(15, 23, 42, 0.04),
                0 24px 60px rgba(15, 23, 42, 0.14);
        }

        .catalog-product-card:hover::after {
            opacity: 1;
            transform: translateX(120%);
        }

        .catalog-card-body {
            min-height: 228px;
        }

        .catalog-stat-card {
            position: relative;
            overflow: hidden;
            isolation: isolate;
            transition:
                transform 0.3s
                    cubic-bezier(0.22, 1, 0.36, 1),
                border-color 0.3s ease,
                background-color 0.3s ease;
        }

        .catalog-stat-card::before {
            content: '';
            position: absolute;
            inset: -120% -55%;
            z-index: -1;
            opacity: 0;
            background:
                linear-gradient(
                    115deg,
                    transparent 38%,
                    rgba(255, 255, 255, 0.16) 50%,
                    transparent 62%
                );
            transform: translateX(-45%);
        }

        .catalog-stat-card:hover {
            border-color: rgba(255, 255, 255, 0.22);
            background-color: rgba(255, 255, 255, 0.09);
            transform: translateY(-5px);
        }

        .catalog-stat-card:hover::before {
            opacity: 1;
            animation: catalogStatShine 0.9s ease;
        }

        .catalog-stat-number {
            display: inline-flex;
            min-width: 2ch;
            font-variant-numeric: tabular-nums;
            transform-origin: center;
        }

        .catalog-stat-number.is-counting {
            text-shadow:
                0 0 24px rgba(255, 255, 255, 0.28);
            transform: translateY(-1px);
        }

        @keyframes catalogStatShine {
            to {
                transform: translateX(45%);
            }
        }

        @keyframes catalogCardReveal {
            from {
                opacity: 0;
                transform:
                    translateY(28px)
                    scale(0.975);
            }

            to {
                opacity: 1;
                transform:
                    translateY(0)
                    scale(1);
            }
        }

        .catalog-card-reveal {
            animation:
                catalogCardReveal
                0.58s
                cubic-bezier(0.22, 1, 0.36, 1)
                both;
        }

        .catalog-load-more-button {
            position: relative;
            isolation: isolate;
            overflow: hidden;
        }

        .catalog-load-more-button::before {
            content: '';
            position: absolute;
            inset: 0;
            z-index: -1;
            background:
                linear-gradient(
                    115deg,
                    transparent 30%,
                    rgba(255, 255, 255, 0.18) 50%,
                    transparent 70%
                );
            transform: translateX(-120%);
            transition:
                transform 0.75s
                cubic-bezier(0.22, 1, 0.36, 1);
        }

        .catalog-load-more-button:hover::before {
            transform: translateX(120%);
        }

        .recommendation-stage {
            position: relative;
            overflow: hidden;
            isolation: isolate;
        }

        .recommendation-stage::before {
            content: '';
            position: absolute;
            top: -180px;
            left: 50%;
            width: 560px;
            height: 560px;
            border-radius: 999px;
            pointer-events: none;
            opacity: 0.2;
            background:
                radial-gradient(
                    circle,
                    #ef4444,
                    transparent 68%
                );
            filter: blur(34px);
            transform: translateX(-50%);
            animation:
                recommendationGlow
                7s
                ease-in-out
                infinite alternate;
        }

        .recommendation-stage::after {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            opacity: 0.32;
            background-image:
                linear-gradient(
                    rgba(255, 255, 255, 0.04) 1px,
                    transparent 1px
                ),
                linear-gradient(
                    90deg,
                    rgba(255, 255, 255, 0.04) 1px,
                    transparent 1px
                );
            background-size: 52px 52px;
        }

        .recommendation-heading {
            opacity: 0;
            transform: translateY(30px);
            transition:
                opacity 0.85s
                    cubic-bezier(0.22, 1, 0.36, 1),
                transform 0.85s
                    cubic-bezier(0.22, 1, 0.36, 1);
        }

        .recommendation-heading.is-visible {
            opacity: 1;
            transform: translateY(0);
        }

        .recommendation-feature,
        .recommendation-side-card {
            opacity: 0;
            transform:
                translateY(30px)
                scale(0.98);
            animation:
                recommendationCardIn
                0.68s
                cubic-bezier(0.22, 1, 0.36, 1)
                forwards;
        }

        .recommendation-feature {
            animation-delay: 0.05s;
        }

        .recommendation-side-card:nth-child(1) {
            animation-delay: 0.14s;
        }

        .recommendation-side-card:nth-child(2) {
            animation-delay: 0.23s;
        }

        .recommendation-side-card:nth-child(3) {
            animation-delay: 0.32s;
        }

        .recommendation-feature-cover,
        .recommendation-side-cover {
            transition:
                transform 0.55s
                    cubic-bezier(0.22, 1, 0.36, 1),
                filter 0.4s ease;
        }

        .recommendation-feature:hover
        .recommendation-feature-cover {
            transform: scale(1.045);
        }

        .recommendation-side-card:hover
        .recommendation-side-cover {
            transform: scale(1.08);
        }

        @keyframes recommendationGlow {
            to {
                opacity: 0.34;
                transform:
                    translateX(-50%)
                    translateY(70px)
                    scale(1.14);
            }
        }

        @keyframes recommendationCardIn {
            to {
                opacity: 1;
                transform:
                    translateY(0)
                    scale(1);
            }
        }

        .catalog-line-clamp-2 {
            display: -webkit-box;
            overflow: hidden;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }

        .catalog-modal-scrollbar::-webkit-scrollbar {
            width: 5px;
        }

        .catalog-modal-scrollbar::-webkit-scrollbar-thumb {
            border-radius: 999px;
            background: #d1d5db;
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
</head>
<body class="min-h-screen bg-[#f5f0eb] text-gray-900 antialiased">

    <?php include __DIR__ . '/../includes/customer_navbar.php'; ?>

    <main>
        <section
            class="catalog-hero relative overflow-hidden bg-[#111827] text-white"
            aria-labelledby="catalog-title"
        >
            <div
                class="catalog-hero-glow absolute inset-0 bg-[radial-gradient(circle_at_78%_30%,rgba(220,38,38,0.28),transparent_34%),radial-gradient(circle_at_15%_85%,rgba(59,130,246,0.15),transparent_32%)]"
            ></div>
            <div class="catalog-grid-pattern absolute inset-0 opacity-40"></div>
            <div
                class="catalog-hero-ring catalog-hero-ring-large absolute -right-28 top-1/2 h-96 w-96 -translate-y-1/2 rounded-full border border-white/10"
            ></div>
            <div
                class="catalog-hero-ring catalog-hero-ring-small absolute -right-8 top-1/2 h-64 w-64 -translate-y-1/2 rounded-full border border-white/10"
            ></div>

            <div
                class="relative mx-auto grid max-w-7xl items-center gap-10 px-6 py-16 lg:grid-cols-[1fr_0.8fr] lg:py-20"
            >
                <div class="catalog-hero-copy max-w-3xl">
                    <p
                        class="text-xs font-black uppercase tracking-[0.24em] text-red-400"
                    >
                        MangaVault Catalog
                    </p>

                    <h1
                        id="catalog-title"
                        class="mt-4 text-4xl font-black leading-[0.96] tracking-[-0.05em] sm:text-5xl lg:text-6xl"
                    >
                        Find the story that
                        <span
                            class="block bg-gradient-to-r from-red-400 via-orange-300 to-amber-200 bg-clip-text text-transparent"
                        >
                            belongs on your shelf.
                        </span>
                    </h1>

                    <p
                        class="mt-6 max-w-2xl text-sm leading-7 text-white/55 sm:text-base"
                    >
                        Browse physical editions and instant e-books, compare
                        available formats and open each title for full details.
                    </p>
                </div>

                <div
                    class="catalog-summary-grid grid grid-cols-3 gap-3 sm:gap-4"
                    aria-label="Catalog summary"
                >
                    <div
                        class="catalog-stat-card rounded-2xl border border-white/10 bg-white/[0.06] p-4 backdrop-blur-xl sm:p-5"
                    >
                        <p
                            class="catalog-stat-number text-2xl font-black sm:text-3xl"
                            data-catalog-counter="<?= count($products) ?>"
                            data-catalog-counter-delay="0"
                            aria-label="<?= count($products) ?> titles"
                        >
                            0
                        </p>
                        <p
                            class="mt-1 text-[10px] font-black uppercase tracking-[0.16em] text-white/40"
                        >
                            Titles
                        </p>
                    </div>

                    <div
                        class="catalog-stat-card rounded-2xl border border-white/10 bg-white/[0.06] p-4 backdrop-blur-xl sm:p-5"
                    >
                        <p
                            class="catalog-stat-number text-2xl font-black text-red-300 sm:text-3xl"
                            data-catalog-counter="<?= $totalPhysicalFormats ?>"
                            data-catalog-counter-delay="140"
                            aria-label="<?= $totalPhysicalFormats ?> physical formats"
                        >
                            0
                        </p>
                        <p
                            class="mt-1 text-[10px] font-black uppercase tracking-[0.16em] text-white/40"
                        >
                            Physical
                        </p>
                    </div>

                    <div
                        class="catalog-stat-card rounded-2xl border border-white/10 bg-white/[0.06] p-4 backdrop-blur-xl sm:p-5"
                    >
                        <p
                            class="catalog-stat-number text-2xl font-black text-blue-300 sm:text-3xl"
                            data-catalog-counter="<?= $totalEbookFormats ?>"
                            data-catalog-counter-delay="280"
                            aria-label="<?= $totalEbookFormats ?> e-book formats"
                        >
                            0
                        </p>
                        <p
                            class="mt-1 text-[10px] font-black uppercase tracking-[0.16em] text-white/40"
                        >
                            E-Books
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <section class="relative z-10 -mt-5 pb-20">
            <div class="mx-auto max-w-7xl px-6">
                <div
                    class="catalog-filter-panel rounded-3xl border border-gray-100 bg-white p-4 shadow-[0_22px_70px_rgba(15,23,42,0.12)] sm:p-6"
                >
                    <form
                        method="GET"
                        class="grid gap-3 lg:grid-cols-[minmax(260px,1.45fr)_repeat(3,minmax(150px,0.75fr))_auto]"
                    >
                        <label class="relative block">
                            <span class="sr-only">Search catalog</span>
                            <svg
                                class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z"
                                ></path>
                            </svg>

                            <input
                                type="search"
                                name="search"
                                maxlength="100"
                                value="<?= htmlspecialchars(
                                    $search,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                placeholder="Search title, series, author or publisher"
                                class="h-12 w-full rounded-xl border-2 border-gray-100 bg-gray-50 pl-12 pr-4 text-sm outline-none transition focus:border-red-400 focus:bg-white"
                            >
                        </label>

                        <label>
                            <span class="sr-only">Category</span>
                            <select
                                name="category_id"
                                class="h-12 w-full rounded-xl border-2 border-gray-100 bg-gray-50 px-4 text-sm text-gray-600 outline-none transition focus:border-red-400 focus:bg-white"
                            >
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option
                                        value="<?= (int) $category['category_id'] ?>"
                                        <?= $categoryId === (int) $category['category_id']
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= htmlspecialchars(
                                            (string) $category['category_name'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            <span class="sr-only">Genre</span>
                            <select
                                name="genre_id"
                                class="h-12 w-full rounded-xl border-2 border-gray-100 bg-gray-50 px-4 text-sm text-gray-600 outline-none transition focus:border-red-400 focus:bg-white"
                            >
                                <option value="">All Genres</option>
                                <?php foreach ($genres as $genre): ?>
                                    <option
                                        value="<?= (int) $genre['genre_id'] ?>"
                                        <?= $genreId === (int) $genre['genre_id']
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= htmlspecialchars(
                                            (string) $genre['genre_name'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            <span class="sr-only">Format</span>
                            <select
                                name="type"
                                class="h-12 w-full rounded-xl border-2 border-gray-100 bg-gray-50 px-4 text-sm text-gray-600 outline-none transition focus:border-red-400 focus:bg-white"
                            >
                                <option value="">All Formats</option>
                                <option
                                    value="physical"
                                    <?= $type === 'physical' ? 'selected' : '' ?>
                                >
                                    Physical
                                </option>
                                <option
                                    value="ebook"
                                    <?= $type === 'ebook' ? 'selected' : '' ?>
                                >
                                    E-Book
                                </option>
                            </select>
                        </label>

                        <button
                            type="submit"
                            class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-red-600 px-6 text-sm font-black text-white shadow-lg shadow-red-100 transition hover:-translate-y-0.5 hover:bg-red-700"
                        >
                            Search
                            <span aria-hidden="true">→</span>
                        </button>
                    </form>

                    <?php if ($hasFilters): ?>
                        <div
                            class="mt-4 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4"
                        >
                            <span
                                class="mr-1 text-[10px] font-black uppercase tracking-[0.16em] text-gray-400"
                            >
                                Active filters
                            </span>

                            <?php if ($search !== ''): ?>
                                <span
                                    class="rounded-full bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-600"
                                >
                                    Search: <?= htmlspecialchars(
                                        $search,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>
                            <?php endif; ?>

                            <?php if ($selectedCategoryName !== ''): ?>
                                <span
                                    class="rounded-full bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-700"
                                >
                                    <?= htmlspecialchars(
                                        $selectedCategoryName,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>
                            <?php endif; ?>

                            <?php if ($selectedGenreName !== ''): ?>
                                <span
                                    class="rounded-full bg-purple-50 px-3 py-1.5 text-xs font-semibold text-purple-700"
                                >
                                    <?= htmlspecialchars(
                                        $selectedGenreName,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>
                            <?php endif; ?>

                            <?php if ($type !== ''): ?>
                                <span
                                    class="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700"
                                >
                                    <?= $type === 'ebook'
                                        ? 'E-Book'
                                        : 'Physical' ?>
                                </span>
                            <?php endif; ?>

                            <a
                                href="home.php"
                                class="ml-auto text-xs font-black text-red-600 transition hover:text-red-700"
                            >
                                Clear all
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <div
                    class="mb-6 mt-10 flex flex-col justify-between gap-3 sm:flex-row sm:items-end"
                >
                    <div>
                        <p
                            class="text-xs font-black uppercase tracking-[0.18em] text-red-600"
                        >
                            Browse the vault
                        </p>
                        <h2
                            class="mt-2 text-3xl font-black tracking-[-0.04em] text-gray-950"
                        >
                            <?= $hasFilters
                                ? 'Search Results'
                                : 'All Available Titles' ?>
                        </h2>
                    </div>

                    <p class="text-sm font-semibold text-gray-400">
                        <?= count($products) ?> title<?= count($products) === 1
                            ? ''
                            : 's' ?> found
                    </p>
                </div>

                <?php if (!$products): ?>
                    <div
                        class="rounded-3xl border border-dashed border-gray-300 bg-white px-6 py-20 text-center"
                    >
                        <div
                            class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-gray-100 text-3xl"
                        >
                            📚
                        </div>
                        <h3 class="mt-5 text-lg font-black text-gray-900">
                            No matching titles found
                        </h3>
                        <p class="mt-2 text-sm text-gray-400">
                            Try changing the search text or removing a filter.
                        </p>
                        <a
                            href="home.php"
                            class="mt-6 inline-flex items-center gap-2 rounded-xl bg-gray-950 px-5 py-3 text-sm font-bold text-white transition hover:bg-red-600"
                        >
                            Browse all titles
                            <span aria-hidden="true">→</span>
                        </a>
                    </div>
                <?php else: ?>
                    <div
                        id="catalogProductGrid"
                        class="grid grid-cols-2 gap-4 sm:grid-cols-3 sm:gap-6 lg:grid-cols-4"
                    >
                        <?php foreach ($products as $productIndex => $product): ?>
                            <?php
                            $detailId = (int) (
                                $product['physical_id'] ??
                                $product['ebook_id']
                            );
                            $coverUrl = catalogCoverUrl(
                                $product['product_cover_image'] ?? null
                            );
                            $isNew = strtotime(
                                (string) $product['product_created_at']
                            ) >= strtotime('-7 days');
                            $stockQuantity = (int) (
                                $product['physical_stock_quantity'] ?? 0
                            );
                            $stockThreshold = (int) (
                                $product['physical_low_stock_threshold'] ?? 5
                            );

                            if (!$product['has_physical']) {
                                $stockLabel = 'Digital access';
                                $stockClass = 'text-blue-600 bg-blue-50';
                            } elseif ($stockQuantity <= 0) {
                                $stockLabel = 'Out of stock';
                                $stockClass = 'text-red-600 bg-red-50';
                            } elseif ($stockQuantity <= $stockThreshold) {
                                $stockLabel = 'Low stock';
                                $stockClass = 'text-amber-700 bg-amber-50';
                            } else {
                                $stockLabel = 'In stock';
                                $stockClass = 'text-green-700 bg-green-50';
                            }

                            $modalData = [
                                'title' => (string) $product['product_title'],
                                'series' => (string) ($product['product_series'] ?? ''),
                                'volume' => (string) ($product['product_volume_number'] ?? ''),
                                'author' => (string) ($product['product_author'] ?? ''),
                                'publisher' => (string) ($product['product_publisher'] ?? ''),
                                'description' => (string) ($product['product_description'] ?? ''),
                                'cover' => (string) ($product['product_cover_image'] ?? ''),
                                'category' => (string) ($product['category_name'] ?? ''),
                                'hasPhysical' => (bool) $product['has_physical'],
                                'hasEbook' => (bool) $product['has_ebook'],
                                'physicalId' => $product['physical_id'] !== null
                                    ? (int) $product['physical_id']
                                    : null,
                                'ebookId' => $product['ebook_id'] !== null
                                    ? (int) $product['ebook_id']
                                    : null,
                                'physicalPrice' => $product['physical_price'] !== null
                                    ? (float) $product['physical_price']
                                    : null,
                                'ebookPrice' => $product['ebook_price'] !== null
                                    ? (float) $product['ebook_price']
                                    : null,
                                'stock' => $stockQuantity,
                            ];
                            ?>

                            <article
                                class="catalog-product-card group flex flex-col overflow-hidden rounded-2xl border border-gray-100 bg-white transition duration-300 hover:-translate-y-2"
                                data-catalog-item
                                data-catalog-index="<?= (int) $productIndex ?>"
                            >
                                <a
                                    href="product_detail.php?id=<?= $detailId ?>"
                                    class="catalog-cover-stage block"
                                    aria-label="View <?= htmlspecialchars(
                                        (string) $product['product_title'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                >
                                    <?php if ($coverUrl !== ''): ?>
                                        <img
                                            src="<?= htmlspecialchars(
                                                $coverUrl,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                            alt="<?= htmlspecialchars(
                                                (string) $product['product_title'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?> cover"
                                            class="catalog-cover-image"
                                            loading="lazy"
                                        >
                                    <?php else: ?>
                                        <div
                                            class="flex h-full items-center justify-center bg-gray-100 text-4xl font-black text-gray-300"
                                        >
                                            <?= htmlspecialchars(
                                                strtoupper(
                                                    substr(
                                                        (string) $product['product_title'],
                                                        0,
                                                        2
                                                    )
                                                ),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($isNew): ?>
                                        <span
                                            class="absolute left-3 top-3 rounded-full bg-red-600 px-3 py-1.5 text-[10px] font-black uppercase tracking-[0.14em] text-white shadow-xl"
                                        >
                                            New
                                        </span>
                                    <?php endif; ?>

                                    <div
                                        class="absolute bottom-3 left-3 right-3 flex items-end justify-between gap-2"
                                    >
                                        <span
                                            class="rounded-full px-3 py-1.5 text-[10px] font-black uppercase tracking-[0.1em] backdrop-blur <?= $stockClass ?>"
                                        >
                                            <?= $stockLabel ?>
                                        </span>

                                        <div class="flex gap-1.5">
                                            <?php if ($product['has_physical']): ?>
                                                <span
                                                    class="flex h-8 w-8 items-center justify-center rounded-full border border-white/25 bg-gray-950/80 text-xs text-white shadow-lg backdrop-blur"
                                                    title="Physical edition"
                                                >
                                                    📦
                                                </span>
                                            <?php endif; ?>

                                            <?php if ($product['has_ebook']): ?>
                                                <span
                                                    class="flex h-8 w-8 items-center justify-center rounded-full border border-white/25 bg-blue-600/90 text-xs text-white shadow-lg backdrop-blur"
                                                    title="E-book edition"
                                                >
                                                    📱
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </a>

                                <div class="catalog-card-body flex flex-1 flex-col p-4">
                                    <p
                                        class="text-[10px] font-black uppercase tracking-[0.15em] text-gray-400"
                                    >
                                        <?= htmlspecialchars(
                                            (string) (
                                                $product['category_name'] ??
                                                'MangaVault title'
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </p>

                                    <a
                                        href="product_detail.php?id=<?= $detailId ?>"
                                        class="catalog-line-clamp-2 mt-2 min-h-[2.5rem] text-sm font-black leading-5 text-gray-900 transition group-hover:text-red-600"
                                    >
                                        <?= htmlspecialchars(
                                            (string) $product['product_title'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </a>

                                    <p
                                        class="mt-1 truncate text-xs text-gray-400"
                                    >
                                        <?= htmlspecialchars(
                                            (string) (
                                                $product['product_author'] ??
                                                'MangaVault selection'
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </p>

                                    <div class="mt-4 space-y-1.5">
                                        <?php if ($product['has_physical']): ?>
                                            <div
                                                class="flex items-center justify-between gap-2"
                                            >
                                                <span
                                                    class="text-[10px] font-bold uppercase tracking-[0.12em] text-gray-400"
                                                >
                                                    Physical
                                                </span>
                                                <span
                                                    class="text-sm font-black text-red-600"
                                                >
                                                    RM <?= number_format(
                                                        (float) $product['physical_price'],
                                                        2
                                                    ) ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($product['has_ebook']): ?>
                                            <div
                                                class="flex items-center justify-between gap-2"
                                            >
                                                <span
                                                    class="text-[10px] font-bold uppercase tracking-[0.12em] text-gray-400"
                                                >
                                                    E-Book
                                                </span>
                                                <span
                                                    class="text-sm font-black text-blue-600"
                                                >
                                                    RM <?= number_format(
                                                        (float) $product['ebook_price'],
                                                        2
                                                    ) ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div
                                        class="mt-auto grid grid-cols-[1fr_auto] gap-2 pt-5"
                                    >
                                        <a
                                            href="product_detail.php?id=<?= $detailId ?>"
                                            class="inline-flex h-10 items-center justify-center rounded-xl bg-gray-950 px-3 text-xs font-black text-white transition hover:bg-red-600"
                                        >
                                            View Product
                                        </a>

                                        <button
                                            type="button"
                                            onclick='openProductModal(<?= json_encode(
                                                $modalData,
                                                JSON_HEX_TAG |
                                                JSON_HEX_AMP |
                                                JSON_HEX_APOS |
                                                JSON_HEX_QUOT
                                            ) ?>)'
                                            class="flex h-10 w-10 items-center justify-center rounded-xl border border-gray-200 text-sm text-gray-500 transition hover:border-red-300 hover:bg-red-50 hover:text-red-600"
                                            aria-label="Quick view"
                                            title="Quick view"
                                        >
                                            ⤢
                                        </button>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <div
                        id="catalogLoadMorePanel"
                        class="<?= count($products) > 12
                            ? 'flex'
                            : 'hidden' ?> mt-12 flex-col items-center justify-center text-center"
                    >
                        <p
                            id="catalogVisibleStatus"
                            class="text-xs font-bold uppercase tracking-[0.16em] text-gray-400"
                            aria-live="polite"
                        >
                            Showing
                            <span id="catalogVisibleCount">
                                <?= min(12, count($products)) ?>
                            </span>
                            of
                            <span>
                                <?= count($products) ?>
                            </span>
                            titles
                        </p>

                        <button
                            id="catalogLoadMoreButton"
                            type="button"
                            class="catalog-load-more-button mt-4 inline-flex min-w-[220px] items-center justify-center gap-3 rounded-2xl bg-gray-950 px-7 py-4 text-sm font-black uppercase tracking-[0.12em] text-white shadow-[0_18px_45px_rgba(15,23,42,0.2)] transition hover:-translate-y-1 hover:bg-red-600"
                            data-batch-size="12"
                            aria-controls="catalogProductGrid"
                        >
                            Browse More Titles
                            <span
                                id="catalogRemainingCount"
                                class="rounded-full bg-white/15 px-2.5 py-1 text-[10px]"
                            >
                                +<?= max(
                                    0,
                                    min(
                                        12,
                                        count($products) - 12
                                    )
                                ) ?>
                            </span>
                        </button>

                        <p
                            id="catalogAllLoadedMessage"
                            class="mt-4 hidden text-sm font-semibold text-green-700"
                            role="status"
                        >
                            All available titles are now displayed.
                        </p>
                    </div>

                    <noscript>
                        <style>
                            #catalogLoadMorePanel {
                                display: none !important;
                            }
                        </style>
                    </noscript>
                <?php endif; ?>
            </div>
        </section>

        <?php if (!$hasFilters): ?>
            <section
                id="recommendations-section"
                class="recommendation-stage border-y border-white/10 bg-[#0d1424] py-24 text-white"
            >
                <div class="relative z-10 mx-auto max-w-7xl px-6">
                    <div
                        class="recommendation-heading mx-auto max-w-2xl text-center"
                    >
                        <div
                            class="mx-auto inline-flex items-center gap-3 rounded-full border border-red-400/20 bg-red-400/10 px-4 py-2"
                        >
                            <span
                                class="h-2 w-2 rounded-full bg-red-400 shadow-[0_0_16px_rgba(248,113,113,0.9)]"
                            ></span>
                            <span
                                class="text-[10px] font-black uppercase tracking-[0.22em] text-red-300"
                            >
                                Selected for you
                            </span>
                        </div>

                        <h2
                            class="mt-5 text-3xl font-black tracking-[-0.045em] text-white sm:text-4xl"
                        >
                            Your next shelf favourite
                            <span
                                class="block bg-gradient-to-r from-red-400 via-orange-300 to-amber-200 bg-clip-text text-transparent"
                            >
                                might be right here.
                            </span>
                        </h2>

                        <p
                            class="mx-auto mt-4 max-w-xl text-sm leading-7 text-white/45"
                        >
                            A changing mix of titles selected from your
                            MangaVault activity, available formats and current
                            catalog.
                        </p>
                    </div>

                    <div
                        id="recommendations-grid"
                        class="mt-12 grid gap-6 lg:grid-cols-[1.12fr_0.88fr]"
                        aria-live="polite"
                    >
                        <div
                            class="grid min-h-[430px] animate-pulse overflow-hidden rounded-3xl border border-white/10 bg-white/[0.05] md:grid-cols-[0.78fr_1.22fr]"
                        >
                            <div class="bg-white/10"></div>
                            <div class="space-y-5 p-8">
                                <div class="h-3 w-24 rounded bg-white/10"></div>
                                <div class="h-8 w-4/5 rounded bg-white/10"></div>
                                <div class="h-4 w-2/3 rounded bg-white/[0.06]"></div>
                                <div class="h-20 rounded bg-white/[0.05]"></div>
                            </div>
                        </div>

                        <div class="grid gap-4">
                            <?php for ($skeleton = 0; $skeleton < 3; $skeleton++): ?>
                                <div
                                    class="grid min-h-[128px] animate-pulse grid-cols-[96px_1fr] overflow-hidden rounded-2xl border border-white/10 bg-white/[0.05]"
                                >
                                    <div class="bg-white/10"></div>
                                    <div class="space-y-3 p-5">
                                        <div class="h-3 w-4/5 rounded bg-white/10"></div>
                                        <div class="h-3 w-1/2 rounded bg-white/[0.06]"></div>
                                        <div class="h-4 w-20 rounded bg-red-400/10"></div>
                                    </div>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <footer class="bg-[#0d1424] text-white">
        <div class="mx-auto max-w-7xl px-6 py-14">
            <div
                class="grid gap-10 border-b border-white/10 pb-12 sm:grid-cols-2 lg:grid-cols-[1.2fr_0.8fr_0.8fr_1fr]"
            >
                <div>
                    <a
                        href="../index.php"
                        class="text-xl font-black tracking-[0.08em]"
                    >
                        MANGA<span class="text-red-500">VAULT</span>
                    </a>
                    <p
                        class="mt-5 max-w-sm text-sm leading-6 text-white/45"
                    >
                        Physical manga, instant e-books, secure checkout and
                        reader rewards in one MangaVault collection.
                    </p>
                </div>

                <div>
                    <h3
                        class="text-xs font-black uppercase tracking-[0.2em] text-white/35"
                    >
                        Shop
                    </h3>
                    <ul class="mt-5 space-y-3 text-sm text-white/60">
                        <li><a href="home.php" class="transition hover:text-white">All titles</a></li>
                        <li><a href="home.php?type=physical" class="transition hover:text-white">Physical books</a></li>
                        <li><a href="home.php?type=ebook" class="transition hover:text-white">E-books</a></li>
                    </ul>
                </div>

                <div>
                    <h3
                        class="text-xs font-black uppercase tracking-[0.2em] text-white/35"
                    >
                        Account
                    </h3>
                    <ul class="mt-5 space-y-3 text-sm text-white/60">
                        <li><a href="orders.php" class="transition hover:text-white">My orders</a></li>
                        <li><a href="wishlist.php" class="transition hover:text-white">Wishlist</a></li>
                        <li><a href="profile.php" class="transition hover:text-white">My account</a></li>
                    </ul>
                </div>

                <div>
                    <h3
                        class="text-xs font-black uppercase tracking-[0.2em] text-white/35"
                    >
                        Support
                    </h3>
                    <div
                        class="mt-5 rounded-2xl border border-white/10 bg-white/[0.04] p-4"
                    >
                        <p class="text-sm font-black text-white">
                            Need help choosing?
                        </p>
                        <p class="mt-1 text-xs leading-5 text-white/40">
                            Visit FAQ or ask MangaBot while signed in.
                        </p>
                        <div class="mt-4 flex gap-3 text-xs font-bold">
                            <a href="faq.php" class="text-red-300 hover:text-white">FAQ</a>
                            <a href="about.php" class="text-red-300 hover:text-white">About Us</a>
                        </div>
                    </div>
                </div>
            </div>

            <div
                class="flex flex-col gap-3 pt-7 text-xs text-white/35 sm:flex-row sm:items-center sm:justify-between"
            >
                <p>© 2026 MangaVault. All rights reserved.</p>
                <p>Physical manga · E-books · Membership rewards</p>
            </div>
        </div>
    </footer>

    <div
        id="productModal"
        class="fixed inset-0 z-[70] hidden"
        aria-hidden="true"
    >
        <div
            class="absolute inset-0 bg-black/65 backdrop-blur-sm"
            onclick="closeProductModal()"
        ></div>

        <div
            class="absolute inset-0 flex items-center justify-center p-4 sm:p-6"
        >
            <div
                id="productModalBox"
                class="pointer-events-auto grid max-h-[92vh] w-full max-w-4xl scale-95 overflow-hidden rounded-3xl bg-white opacity-0 shadow-[0_40px_120px_rgba(0,0,0,0.4)] transition duration-300 lg:grid-cols-[340px_1fr]"
                role="dialog"
                aria-modal="true"
                aria-labelledby="modalTitle"
            >
                <div class="relative bg-[#eee8e1] p-5 sm:p-7">
                    <div
                        class="relative mx-auto aspect-[2/3] w-full max-w-[300px] overflow-hidden rounded-2xl bg-white shadow-2xl"
                    >
                        <img
                            id="modalCover"
                            src=""
                            alt=""
                            class="hidden h-full w-full object-cover object-center"
                        >
                        <div
                            id="modalCoverPlaceholder"
                            class="hidden h-full w-full items-center justify-center bg-gray-100 text-5xl font-black text-gray-300"
                        ></div>
                    </div>

                    <div
                        id="modalBadges"
                        class="mt-4 flex flex-wrap justify-center gap-2"
                    ></div>
                </div>

                <div
                    class="catalog-modal-scrollbar relative overflow-y-auto p-6 sm:p-8"
                >
                    <button
                        type="button"
                        onclick="closeProductModal()"
                        class="absolute right-5 top-5 flex h-10 w-10 items-center justify-center rounded-full border border-gray-200 text-gray-500 transition hover:border-red-200 hover:bg-red-50 hover:text-red-600"
                        aria-label="Close quick view"
                    >
                        ✕
                    </button>

                    <p
                        id="modalCategory"
                        class="pr-14 text-xs font-black uppercase tracking-[0.17em] text-red-600"
                    ></p>
                    <h2
                        id="modalTitle"
                        class="mt-3 pr-14 text-3xl font-black tracking-[-0.04em] text-gray-950"
                    ></h2>
                    <p
                        id="modalSeries"
                        class="mt-2 text-sm text-gray-400"
                    ></p>

                    <div
                        class="mt-7 grid gap-4 rounded-2xl border border-gray-100 bg-gray-50 p-5 sm:grid-cols-2"
                    >
                        <div>
                            <p
                                class="text-[10px] font-black uppercase tracking-[0.15em] text-gray-400"
                            >
                                Author
                            </p>
                            <p
                                id="modalAuthor"
                                class="mt-1 text-sm font-bold text-gray-800"
                            ></p>
                        </div>
                        <div>
                            <p
                                class="text-[10px] font-black uppercase tracking-[0.15em] text-gray-400"
                            >
                                Publisher
                            </p>
                            <p
                                id="modalPublisher"
                                class="mt-1 text-sm font-bold text-gray-800"
                            ></p>
                        </div>
                    </div>

                    <div class="mt-7">
                        <p
                            class="text-[10px] font-black uppercase tracking-[0.16em] text-gray-400"
                        >
                            Synopsis
                        </p>
                        <p
                            id="modalDescription"
                            class="mt-3 text-sm leading-7 text-gray-600"
                        ></p>
                    </div>

                    <div
                        id="modalFormatActions"
                        class="mt-8 grid gap-3 sm:grid-cols-2"
                    ></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const reduceCatalogMotion = window.matchMedia(
            '(prefers-reduced-motion: reduce)'
        ).matches;

        function animateCatalogCounters() {
            const counters = Array.from(
                document.querySelectorAll(
                    '[data-catalog-counter]'
                )
            );

            if (counters.length === 0) {
                return;
            }

            let hasAnimated = false;

            function startCounters() {
                if (hasAnimated) {
                    return;
                }

                hasAnimated = true;

                counters.forEach(counter => {
                    const target = Math.max(
                        0,
                        Number(
                            counter.dataset.catalogCounter
                        ) || 0
                    );
                    const delay = Math.max(
                        0,
                        Number(
                            counter.dataset.catalogCounterDelay
                        ) || 0
                    );

                    counter.textContent = '0';

                    if (reduceCatalogMotion) {
                        counter.textContent = String(target);
                        return;
                    }

                    window.setTimeout(() => {
                        const duration = 1450;
                        const startedAt = performance.now();

                        counter.classList.add(
                            'is-counting'
                        );

                        function updateCounter(currentTime) {
                            const progress = Math.min(
                                1,
                                (
                                    currentTime -
                                    startedAt
                                ) /
                                duration
                            );

                            const eased =
                                1 -
                                Math.pow(
                                    1 -
                                    progress,
                                    3
                                );

                            const value = Math.min(
                                target,
                                Math.floor(
                                    target *
                                    eased
                                )
                            );

                            counter.textContent =
                                String(value);

                            if (progress < 1) {
                                window.requestAnimationFrame(
                                    updateCounter
                                );
                                return;
                            }

                            counter.textContent =
                                String(target);
                            counter.classList.remove(
                                'is-counting'
                            );
                        }

                        window.requestAnimationFrame(
                            updateCounter
                        );
                    }, delay);
                });
            }

            const summary = document.querySelector(
                '[aria-label="Catalog summary"]'
            );

            if (
                !summary ||
                !('IntersectionObserver' in window)
            ) {
                startCounters();
                return;
            }

            const observer = new IntersectionObserver(
                entries => {
                    if (
                        !entries.some(
                            entry =>
                                entry.isIntersecting
                        )
                    ) {
                        return;
                    }

                    startCounters();
                    observer.disconnect();
                },
                {
                    threshold: 0.45,
                }
            );

            observer.observe(summary);
        }

        function setupCatalogPagination() {
            const items = Array.from(
                document.querySelectorAll(
                    '[data-catalog-item]'
                )
            );
            const panel = document.getElementById(
                'catalogLoadMorePanel'
            );
            const button = document.getElementById(
                'catalogLoadMoreButton'
            );
            const visibleCountElement =
                document.getElementById(
                    'catalogVisibleCount'
                );
            const remainingCountElement =
                document.getElementById(
                    'catalogRemainingCount'
                );
            const allLoadedMessage =
                document.getElementById(
                    'catalogAllLoadedMessage'
                );

            if (
                items.length === 0 ||
                !panel ||
                !button
            ) {
                return;
            }

            const batchSize = Math.max(
                1,
                Number(
                    button.dataset.batchSize
                ) || 12
            );
            let visibleCount = Math.min(
                batchSize,
                items.length
            );

            function updateCatalogVisibility(
                animateFrom = null
            ) {
                items.forEach((item, index) => {
                    const visible =
                        index < visibleCount;

                    item.hidden = !visible;

                    if (
                        visible &&
                        animateFrom !== null &&
                        index >= animateFrom
                    ) {
                        item.classList.remove(
                            'catalog-card-reveal'
                        );

                        item.style.animationDelay =
                            `${
                                (
                                    index -
                                    animateFrom
                                ) *
                                65
                            }ms`;

                        void item.offsetWidth;

                        item.classList.add(
                            'catalog-card-reveal'
                        );
                    }
                });

                if (visibleCountElement) {
                    visibleCountElement.textContent =
                        String(visibleCount);
                }

                const remaining =
                    Math.max(
                        0,
                        items.length -
                        visibleCount
                    );

                if (remainingCountElement) {
                    remainingCountElement.textContent =
                        `+${
                            Math.min(
                                batchSize,
                                remaining
                            )
                        }`;
                }

                const complete =
                    remaining === 0;

                button.classList.toggle(
                    'hidden',
                    complete
                );

                if (allLoadedMessage) {
                    allLoadedMessage.classList.toggle(
                        'hidden',
                        !complete
                    );
                }

                panel.classList.toggle(
                    'hidden',
                    items.length <= batchSize
                );
                panel.classList.toggle(
                    'flex',
                    items.length > batchSize
                );
            }

            updateCatalogVisibility();

            const grid = document.getElementById(
                'catalogProductGrid'
            );

            function revealInitialBatch() {
                items
                    .slice(
                        0,
                        visibleCount
                    )
                    .forEach((item, index) => {
                        item.classList.remove(
                            'catalog-card-reveal'
                        );
                        item.style.animationDelay =
                            `${index * 55}ms`;

                        void item.offsetWidth;

                        item.classList.add(
                            'catalog-card-reveal'
                        );
                    });
            }

            if (
                reduceCatalogMotion ||
                !grid ||
                !('IntersectionObserver' in window)
            ) {
                revealInitialBatch();
            } else {
                const gridObserver =
                    new IntersectionObserver(
                        entries => {
                            if (
                                !entries.some(
                                    entry =>
                                        entry.isIntersecting
                                )
                            ) {
                                return;
                            }

                            revealInitialBatch();
                            gridObserver.disconnect();
                        },
                        {
                            threshold: 0.08,
                            rootMargin:
                                '0px 0px -5% 0px',
                        }
                    );

                gridObserver.observe(grid);
            }

            button.addEventListener(
                'click',
                () => {
                    const previousVisibleCount =
                        visibleCount;

                    visibleCount = Math.min(
                        items.length,
                        visibleCount +
                        batchSize
                    );

                    updateCatalogVisibility(
                        previousVisibleCount
                    );

                    const firstNewItem =
                        items[
                            previousVisibleCount
                        ];

                    if (firstNewItem) {
                        window.setTimeout(() => {
                            firstNewItem.scrollIntoView({
                                behavior:
                                    reduceCatalogMotion
                                        ? 'auto'
                                        : 'smooth',
                                block: 'center',
                            });
                        }, 120);
                    }
                }
            );
        }

        function setupRecommendationHeading() {
            const heading = document.querySelector(
                '.recommendation-heading'
            );

            if (!heading) {
                return;
            }

            if (
                reduceCatalogMotion ||
                !('IntersectionObserver' in window)
            ) {
                heading.classList.add(
                    'is-visible'
                );
                return;
            }

            const observer = new IntersectionObserver(
                entries => {
                    if (
                        !entries.some(
                            entry =>
                                entry.isIntersecting
                        )
                    ) {
                        return;
                    }

                    heading.classList.add(
                        'is-visible'
                    );
                    observer.disconnect();
                },
                {
                    threshold: 0.3,
                }
            );

            observer.observe(heading);
        }

        animateCatalogCounters();
        setupCatalogPagination();
        setupRecommendationHeading();

        const productModal = document.getElementById(
            'productModal'
        );
        const productModalBox = document.getElementById(
            'productModalBox'
        );

        function productImageUrl(filename) {
            return '../assets/images/' +
                encodeURIComponent(filename);
        }

        function openProductModal(data) {
            document.getElementById(
                'modalTitle'
            ).textContent = data.title;

            document.getElementById(
                'modalSeries'
            ).textContent = data.series
                ? data.series +
                    (data.volume
                        ? ' · Vol.' + data.volume
                        : '')
                : '';

            document.getElementById(
                'modalAuthor'
            ).textContent = data.author || '—';

            document.getElementById(
                'modalPublisher'
            ).textContent = data.publisher || '—';

            document.getElementById(
                'modalCategory'
            ).textContent = data.category || 'MangaVault title';

            document.getElementById(
                'modalDescription'
            ).textContent =
                data.description ||
                'No description is available for this title.';

            const cover = document.getElementById(
                'modalCover'
            );
            const placeholder = document.getElementById(
                'modalCoverPlaceholder'
            );

            if (data.cover) {
                cover.src = productImageUrl(data.cover);
                cover.alt = data.title + ' cover';
                cover.classList.remove('hidden');
                placeholder.classList.add('hidden');
                placeholder.classList.remove('flex');
            } else {
                cover.removeAttribute('src');
                cover.classList.add('hidden');
                placeholder.textContent = data.title
                    .substring(0, 2)
                    .toUpperCase();
                placeholder.classList.remove('hidden');
                placeholder.classList.add('flex');
            }

            const badges = [];

            if (data.hasPhysical) {
                badges.push(
                    '<span class="rounded-full bg-gray-950 px-3 py-1.5 text-[10px] font-black uppercase tracking-[0.12em] text-white">📦 Physical</span>'
                );
            }

            if (data.hasEbook) {
                badges.push(
                    '<span class="rounded-full bg-blue-600 px-3 py-1.5 text-[10px] font-black uppercase tracking-[0.12em] text-white">📱 E-Book</span>'
                );
            }

            document.getElementById(
                'modalBadges'
            ).innerHTML = badges.join('');

            const actions = [];

            if (data.hasPhysical && data.physicalId) {
                const physicalDisabled =
                    Number(data.stock) <= 0;

                actions.push(`
                    <a
                        href="product_detail.php?id=${Number(data.physicalId)}"
                        class="rounded-2xl ${physicalDisabled
                            ? 'pointer-events-none bg-gray-200 text-gray-400'
                            : 'bg-red-600 text-white hover:bg-red-700'} px-5 py-4 transition"
                    >
                        <span class="block text-[10px] font-black uppercase tracking-[0.14em] opacity-70">
                            Physical edition
                        </span>
                        <span class="mt-1 flex items-center justify-between gap-3 text-sm font-black">
                            RM ${Number(data.physicalPrice).toFixed(2)}
                            <span>${physicalDisabled
                                ? 'Out of stock'
                                : 'View →'}</span>
                        </span>
                    </a>
                `);
            }

            if (data.hasEbook && data.ebookId) {
                actions.push(`
                    <a
                        href="product_detail.php?id=${Number(data.ebookId)}"
                        class="rounded-2xl bg-blue-600 px-5 py-4 text-white transition hover:bg-blue-700"
                    >
                        <span class="block text-[10px] font-black uppercase tracking-[0.14em] opacity-70">
                            E-book edition
                        </span>
                        <span class="mt-1 flex items-center justify-between gap-3 text-sm font-black">
                            RM ${Number(data.ebookPrice).toFixed(2)}
                            <span>View →</span>
                        </span>
                    </a>
                `);
            }

            document.getElementById(
                'modalFormatActions'
            ).innerHTML = actions.join('');

            productModal.classList.remove('hidden');
            productModal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';

            window.setTimeout(() => {
                productModalBox.classList.remove(
                    'scale-95',
                    'opacity-0'
                );
                productModalBox.classList.add(
                    'scale-100',
                    'opacity-100'
                );
            }, 10);
        }

        function closeProductModal() {
            if (productModal.classList.contains('hidden')) {
                return;
            }

            productModalBox.classList.remove(
                'scale-100',
                'opacity-100'
            );
            productModalBox.classList.add(
                'scale-95',
                'opacity-0'
            );

            window.setTimeout(() => {
                productModal.classList.add('hidden');
                productModal.setAttribute(
                    'aria-hidden',
                    'true'
                );
                document.body.style.overflow = '';
            }, 250);
        }

        document.addEventListener(
            'keydown',
            event => {
                if (event.key === 'Escape') {
                    closeProductModal();
                }
            }
        );

        function escapeHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        <?php if (!$hasFilters): ?>
        fetch('get_recommendations.php', {
            method: 'POST',
            headers: {
                'Content-Type':
                    'application/x-www-form-urlencoded'
            },
            body: 'type=home'
        })
        .then(async response => {
            const data = await response.json();

            if (!response.ok) {
                throw new Error(
                    data.error ||
                    'Unable to load recommendations.'
                );
            }

            return data;
        })
        .then(data => {
            const section = document.getElementById(
                'recommendations-section'
            );
            const grid = document.getElementById(
                'recommendations-grid'
            );

            if (
                !Array.isArray(data.products) ||
                data.products.length === 0
            ) {
                section.hidden = true;
                return;
            }

            const products = data.products.slice(
                0,
                4
            );

            function recommendationData(product) {
                const productId = Number(
                    product.product_id
                );
                const title = escapeHtml(
                    product.product_title
                );
                const genres = escapeHtml(
                    product.genres || ''
                );
                const price = Number(
                    product.product_price
                ).toFixed(2);
                const cover = product.product_cover_image
                    ? '../assets/images/' +
                        encodeURIComponent(
                            product.product_cover_image
                        )
                    : '';
                const isEbook =
                    product.product_type === 'ebook';
                const stock = Number(
                    product.physical_stock_quantity || 0
                );
                const availabilityText = isEbook
                    ? 'Instant e-book'
                    : stock > 0
                        ? 'In stock'
                        : 'Out of stock';
                const availabilityClass = isEbook
                    ? 'bg-blue-400/15 text-blue-200'
                    : stock > 0
                        ? 'bg-emerald-400/15 text-emerald-200'
                        : 'bg-red-400/15 text-red-200';

                return {
                    productId,
                    title,
                    genres,
                    price,
                    cover,
                    availabilityText,
                    availabilityClass,
                };
            }

            const featured = recommendationData(
                products[0]
            );
            const sideProducts = products
                .slice(1)
                .map(recommendationData);

            const featuredCover = featured.cover
                ? `<img
                        src="${featured.cover}"
                        alt="${featured.title} cover"
                        class="recommendation-feature-cover h-full w-full object-cover"
                        loading="lazy"
                   >`
                : `<div
                        class="flex h-full items-center justify-center bg-white/10 text-5xl font-black text-white/25"
                   >
                        ${featured.title
                            .substring(0, 2)
                            .toUpperCase()}
                   </div>`;

            const sideCards = sideProducts
                .map((product, index) => {
                    const sideCover = product.cover
                        ? `<img
                                src="${product.cover}"
                                alt="${product.title} cover"
                                class="recommendation-side-cover h-full w-full object-cover"
                                loading="lazy"
                           >`
                        : `<div
                                class="flex h-full items-center justify-center bg-white/10 text-xl font-black text-white/25"
                           >
                                ${product.title
                                    .substring(0, 2)
                                    .toUpperCase()}
                           </div>`;

                    return `
                        <a
                            href="product_detail.php?id=${product.productId}"
                            class="recommendation-side-card group grid min-h-[132px] grid-cols-[98px_1fr] overflow-hidden rounded-2xl border border-white/10 bg-white/[0.055] transition hover:-translate-y-1 hover:border-white/20 hover:bg-white/[0.09]"
                        >
                            <div class="overflow-hidden bg-white/10">
                                ${sideCover}
                            </div>

                            <div
                                class="flex min-w-0 flex-col justify-center p-5"
                            >
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <div class="min-w-0">
                                        <p
                                            class="text-[9px] font-black uppercase tracking-[0.18em] text-white/30"
                                        >
                                            Pick 0${index + 2}
                                        </p>
                                        <h3
                                            class="mt-1 truncate text-sm font-black text-white transition group-hover:text-red-300"
                                        >
                                            ${product.title}
                                        </h3>
                                    </div>

                                    <span
                                        class="text-xs font-black text-white/25 transition group-hover:translate-x-1 group-hover:text-red-300"
                                    >
                                        →
                                    </span>
                                </div>

                                <p
                                    class="mt-2 truncate text-xs text-white/35"
                                >
                                    ${product.genres || 'MangaVault selection'}
                                </p>

                                <div
                                    class="mt-3 flex items-center justify-between gap-3"
                                >
                                    <span
                                        class="rounded-full px-2.5 py-1 text-[9px] font-black uppercase tracking-[0.1em] ${product.availabilityClass}"
                                    >
                                        ${product.availabilityText}
                                    </span>

                                    <span
                                        class="text-sm font-black text-red-300"
                                    >
                                        RM ${product.price}
                                    </span>
                                </div>
                            </div>
                        </a>
                    `;
                })
                .join('');

            grid.innerHTML = `
                <a
                    href="product_detail.php?id=${featured.productId}"
                    class="recommendation-feature group grid min-h-[430px] overflow-hidden rounded-3xl border border-white/10 bg-white/[0.055] shadow-[0_32px_90px_rgba(0,0,0,0.28)] transition hover:-translate-y-1 hover:border-white/20 hover:bg-white/[0.08] md:grid-cols-[0.78fr_1.22fr]"
                >
                    <div
                        class="relative min-h-[320px] overflow-hidden bg-white/10 md:min-h-full"
                    >
                        ${featuredCover}

                        <span
                            class="absolute left-5 top-5 rounded-full border border-white/15 bg-gray-950/70 px-3 py-1.5 text-[9px] font-black uppercase tracking-[0.16em] text-white backdrop-blur"
                        >
                            Featured match
                        </span>
                    </div>

                    <div
                        class="flex flex-col justify-center p-7 sm:p-9"
                    >
                        <p
                            class="text-[10px] font-black uppercase tracking-[0.2em] text-red-300"
                        >
                            Recommendation 01
                        </p>

                        <h3
                            class="mt-4 text-2xl font-black leading-tight tracking-[-0.035em] text-white transition group-hover:text-red-200 sm:text-3xl"
                        >
                            ${featured.title}
                        </h3>

                        <p
                            class="mt-3 text-sm leading-6 text-white/40"
                        >
                            ${featured.genres || 'A MangaVault selection chosen from the current catalog.'}
                        </p>

                        <div
                            class="mt-7 flex flex-wrap items-center gap-3"
                        >
                            <span
                                class="rounded-full px-3 py-1.5 text-[10px] font-black uppercase tracking-[0.11em] ${featured.availabilityClass}"
                            >
                                ${featured.availabilityText}
                            </span>

                            <span
                                class="text-xl font-black text-red-300"
                            >
                                RM ${featured.price}
                            </span>
                        </div>

                        <div
                            class="mt-8 inline-flex items-center gap-3 text-xs font-black uppercase tracking-[0.14em] text-white"
                        >
                            Explore this title
                            <span
                                class="transition group-hover:translate-x-2 group-hover:text-red-300"
                            >
                                →
                            </span>
                        </div>
                    </div>
                </a>

                <div class="grid gap-4">
                    ${sideCards}
                </div>
            `;
        })
        .catch(() => {
            const section = document.getElementById(
                'recommendations-section'
            );

            if (section) {
                section.hidden = true;
            }
        });
        <?php endif; ?>
    </script>

</body>
</html>