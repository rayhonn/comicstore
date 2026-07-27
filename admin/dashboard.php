<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/money_helper.php';

require_admin();

date_default_timezone_set('Asia/Kuala_Lumpur');

$total_orders = (int) $pdo->query("
    SELECT COUNT(*)
    FROM orders
    WHERE order_payment_status = 'confirmed'
    AND order_status != 'cancelled'
")->fetchColumn();

$total_revenue_decimal = (string) (
    $pdo->query("
        SELECT COALESCE(SUM(order_total_amount), 0.00)
        FROM orders
        WHERE order_payment_status = 'confirmed'
        AND order_status != 'cancelled'
    ")->fetchColumn() ?: '0.00'
);
$total_revenue_sen = moneyDecimalToSen(
    $total_revenue_decimal
);

$revenue_month_decimal = (string) (
    $pdo->query("
        SELECT COALESCE(SUM(order_total_amount), 0.00)
        FROM orders
        WHERE order_payment_status = 'confirmed'
        AND order_status != 'cancelled'
        AND MONTH(order_created_at) = MONTH(NOW())
        AND YEAR(order_created_at) = YEAR(NOW())
    ")->fetchColumn() ?: '0.00'
);
$revenue_month_sen = moneyDecimalToSen(
    $revenue_month_decimal
);

$total_products = (int) $pdo->query("
    SELECT COUNT(*)
    FROM products
    WHERE product_is_available = 1
")->fetchColumn();

$total_customers = (int) $pdo->query("
    SELECT COUNT(*)
    FROM users
    WHERE user_role = 'customer'
")->fetchColumn();

$low_stock = (int) $pdo->query("
    SELECT COUNT(*)
    FROM product_physical
    WHERE physical_stock_quantity <=
        physical_low_stock_threshold
")->fetchColumn();

$pending_orders = (int) $pdo->query("
    SELECT COUNT(*)
    FROM orders
    WHERE order_status = 'pending'
    AND order_payment_status = 'confirmed'
")->fetchColumn();

$pending_returns = (int) $pdo->query("
    SELECT COUNT(*)
    FROM return_requests
    WHERE return_status = 'pending'
")->fetchColumn();

$pending_reviews = (int) $pdo->query("
    SELECT COUNT(*)
    FROM product_reviews
    WHERE review_status = 'pending'
")->fetchColumn();

$pending_supplier_returns = (int) $pdo->query("
    SELECT COUNT(*)
    FROM supplier_returns
    WHERE return_status IN ('pending', 'escalated')
")->fetchColumn();

$pending_pr = (int) $pdo->query("
    SELECT COUNT(*)
    FROM purchase_requisitions
    WHERE pr_status IN ('pending', 'approved')
")->fetchColumn();

$recent_orders = $pdo->query("
    SELECT
        o.order_id,
        o.order_total_amount,
        o.order_status,
        o.order_created_at,
        u.user_first_name,
        u.user_last_name
    FROM orders o
    JOIN users u
        ON o.order_user_id = u.user_id
    WHERE o.order_payment_status = 'confirmed'
    ORDER BY o.order_created_at DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$low_stock_products = $pdo->query("
    SELECT
        p.product_id,
        p.product_title,
        pp.physical_stock_quantity,
        pp.physical_low_stock_threshold
    FROM product_physical pp
    JOIN products p
        ON pp.physical_product_id = p.product_id
    WHERE pp.physical_stock_quantity <=
        pp.physical_low_stock_threshold
    ORDER BY
        pp.physical_stock_quantity ASC,
        p.product_title ASC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$top_products = $pdo->query("
    SELECT
        p.product_id,
        p.product_title,
        p.product_cover_image,
        SUM(oi.order_item_quantity) AS total_sold,
        SUM(
            oi.order_item_price *
            oi.order_item_quantity
        ) AS revenue
    FROM order_items oi
    JOIN products p
        ON oi.order_item_product_id = p.product_id
    JOIN orders o
        ON oi.order_item_order_id = o.order_id
    WHERE o.order_payment_status = 'confirmed'
    AND o.order_status != 'cancelled'
    GROUP BY
        p.product_id,
        p.product_title,
        p.product_cover_image
    ORDER BY total_sold DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$revenue_7days = [];
$labels_7days = [];

for ($i = 6; $i >= 0; $i--) {
    $date = date(
        'Y-m-d',
        strtotime("-$i days")
    );

    $labels_7days[] = date(
        'd M',
        strtotime($date)
    );

    $revenue_statement = $pdo->prepare("
        SELECT COALESCE(SUM(order_total_amount), 0.00)
        FROM orders
        WHERE DATE(order_created_at) = ?
        AND order_payment_status = 'confirmed'
        AND order_status != 'cancelled'
    ");
    $revenue_statement->execute([$date]);

    $revenue_decimal = (string) (
        $revenue_statement->fetchColumn() ?: '0.00'
    );

    $revenue_7days[] = $revenue_decimal;
}

$order_statuses = $pdo->query("
    SELECT
        order_status,
        COUNT(*) AS status_count
    FROM orders
    WHERE order_payment_status = 'confirmed'
    GROUP BY order_status
")->fetchAll(PDO::FETCH_ASSOC);

$status_labels = [];
$status_counts = [];
$status_chart_colors = [];

$status_color_map = [
    'pending' => '#f59e0b',
    'processing' => '#3b82f6',
    'shipped' => '#8b5cf6',
    'delivered' => '#10b981',
    'cancelled' => '#ef4444',
];

foreach ($order_statuses as $status_row) {
    $status = (string) $status_row['order_status'];

    $status_labels[] = ucfirst($status);
    $status_counts[] =
        (int) $status_row['status_count'];
    $status_chart_colors[] =
        $status_color_map[$status] ?? '#94a3b8';
}

$status_badge_classes = [
    'pending' =>
        'bg-amber-50 text-amber-700 border-amber-200',
    'processing' =>
        'bg-blue-50 text-blue-700 border-blue-200',
    'shipped' =>
        'bg-violet-50 text-violet-700 border-violet-200',
    'delivered' =>
        'bg-emerald-50 text-emerald-700 border-emerald-200',
    'cancelled' =>
        'bg-red-50 text-red-700 border-red-200',
];

$task_count =
    $pending_orders +
    $pending_returns +
    $pending_reviews +
    $pending_supplier_returns +
    $pending_pr;

$admin_name =
    $_SESSION['user_first_name'] ??
    $_SESSION['user_name'] ??
    'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Admin Dashboard - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            opacity: 0;
            animation: fadeIn 0.3s ease forwards;
        }

        @keyframes fadeIn {
            to {
                opacity: 1;
            }
        }

        .sidebar-scrollbar::-webkit-scrollbar {
            width: 5px;
        }

        .sidebar-scrollbar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.16);
            border-radius: 999px;
        }
    </style>
</head>
<body class="bg-[#f5f6fa] text-gray-800">

    <div
        id="sidebarOverlay"
        class="fixed inset-0 bg-black/50 z-30 hidden lg:hidden"
        onclick="closeSidebar()"
    ></div>

    <aside
        id="adminSidebar"
        class="fixed inset-y-0 left-0 z-40 w-64 bg-[#17243d] text-white -translate-x-full lg:translate-x-0 transition-transform duration-300 flex flex-col"
    >
        <div
            class="h-20 px-6 flex items-center border-b border-white/10"
        >
            <a
                href="dashboard.php"
                class="text-xl font-black tracking-wide"
            >
                MANGA<span class="text-red-400">VAULT</span>
                <span
                    class="block text-[10px] tracking-[0.24em] text-white/40 font-semibold mt-0.5"
                >
                    ADMIN PORTAL
                </span>
            </a>

            <button
                type="button"
                onclick="closeSidebar()"
                class="lg:hidden ml-auto text-white/60 hover:text-white"
                aria-label="Close navigation"
            >
                ✕
            </button>
        </div>

        <nav
            class="sidebar-scrollbar flex-1 overflow-y-auto px-4 py-5"
        >
            <p
                class="px-3 mb-2 text-[10px] uppercase tracking-[0.18em] font-bold text-white/30"
            >
                Overview
            </p>

            <a
                href="dashboard.php"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-white/12 text-white font-semibold text-sm"
            >
                <span class="w-6 text-center">⌂</span>
                Dashboard
            </a>

            <a
                href="reports.php"
                class="mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl text-white/65 hover:text-white hover:bg-white/10 transition-colors text-sm"
            >
                <span class="w-6 text-center">▥</span>
                Reports
            </a>

            <p
                class="px-3 mt-6 mb-2 text-[10px] uppercase tracking-[0.18em] font-bold text-white/30"
            >
                Commerce
            </p>

            <a
                href="products.php"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-white/65 hover:text-white hover:bg-white/10 transition-colors text-sm"
            >
                <span class="w-6 text-center">▣</span>
                Products
                <?php if ($low_stock > 0): ?>
                <span
                    class="ml-auto min-w-5 h-5 px-1.5 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center"
                >
                    <?= $low_stock ?>
                </span>
                <?php endif; ?>
            </a>

            <a
                href="orders.php"
                class="mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl text-white/65 hover:text-white hover:bg-white/10 transition-colors text-sm"
            >
                <span class="w-6 text-center">▤</span>
                Orders
                <?php if ($pending_orders > 0): ?>
                <span
                    class="ml-auto min-w-5 h-5 px-1.5 rounded-full bg-amber-500 text-white text-[10px] font-bold flex items-center justify-center"
                >
                    <?= $pending_orders ?>
                </span>
                <?php endif; ?>
            </a>

            <a
                href="returns.php"
                class="mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl text-white/65 hover:text-white hover:bg-white/10 transition-colors text-sm"
            >
                <span class="w-6 text-center">↩</span>
                Returns
                <?php if ($pending_returns > 0): ?>
                <span
                    class="ml-auto min-w-5 h-5 px-1.5 rounded-full bg-orange-500 text-white text-[10px] font-bold flex items-center justify-center"
                >
                    <?= $pending_returns ?>
                </span>
                <?php endif; ?>
            </a>

            <a
                href="reviews.php"
                class="mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl text-white/65 hover:text-white hover:bg-white/10 transition-colors text-sm"
            >
                <span class="w-6 text-center">★</span>
                Reviews
                <?php if ($pending_reviews > 0): ?>
                <span
                    class="ml-auto min-w-5 h-5 px-1.5 rounded-full bg-blue-500 text-white text-[10px] font-bold flex items-center justify-center"
                >
                    <?= $pending_reviews ?>
                </span>
                <?php endif; ?>
            </a>

            <a
                href="users.php"
                class="mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl text-white/65 hover:text-white hover:bg-white/10 transition-colors text-sm"
            >
                <span class="w-6 text-center">♙</span>
                Customers
            </a>

            <p
                class="px-3 mt-6 mb-2 text-[10px] uppercase tracking-[0.18em] font-bold text-white/30"
            >
                Procurement
            </p>

            <a
                href="pr.php"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-white/65 hover:text-white hover:bg-white/10 transition-colors text-sm"
            >
                <span class="w-6 text-center">▧</span>
                Requisitions
                <?php if ($pending_pr > 0): ?>
                <span
                    class="ml-auto min-w-5 h-5 px-1.5 rounded-full bg-blue-500 text-white text-[10px] font-bold flex items-center justify-center"
                >
                    <?= $pending_pr ?>
                </span>
                <?php endif; ?>
            </a>

            <a
                href="rfq.php"
                class="mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl text-white/65 hover:text-white hover:bg-white/10 transition-colors text-sm"
            >
                <span class="w-6 text-center">▨</span>
                RFQ
            </a>

            <a
                href="purchase_orders.php"
                class="mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl text-white/65 hover:text-white hover:bg-white/10 transition-colors text-sm"
            >
                <span class="w-6 text-center">▦</span>
                Purchase Orders
            </a>

            <a
                href="suppliers.php"
                class="mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl text-white/65 hover:text-white hover:bg-white/10 transition-colors text-sm"
            >
                <span class="w-6 text-center">▰</span>
                Suppliers
            </a>

            <a
                href="supplier_invoices.php"
                class="mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl text-white/65 hover:text-white hover:bg-white/10 transition-colors text-sm"
            >
                <span class="w-6 text-center">▩</span>
                Supplier Invoices
            </a>

            <a
                href="supplier_returns.php"
                class="mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl text-white/65 hover:text-white hover:bg-white/10 transition-colors text-sm"
            >
                <span class="w-6 text-center">↪</span>
                Supplier Returns
                <?php if ($pending_supplier_returns > 0): ?>
                <span
                    class="ml-auto min-w-5 h-5 px-1.5 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center"
                >
                    <?= $pending_supplier_returns ?>
                </span>
                <?php endif; ?>
            </a>

            <details class="mt-5 group">
                <summary
                    class="cursor-pointer list-none flex items-center justify-between px-3 py-2.5 rounded-xl text-white/55 hover:text-white hover:bg-white/10 transition-colors text-sm"
                >
                    <span class="flex items-center gap-3">
                        <span class="w-6 text-center">⚙</span>
                        Management
                    </span>
                    <span
                        class="text-xs group-open:rotate-180 transition-transform"
                    >
                        ▾
                    </span>
                </summary>

                <div class="mt-1 ml-9 space-y-1">
                    <a
                        href="categories.php"
                        class="block px-3 py-2 rounded-lg text-xs text-white/50 hover:text-white hover:bg-white/10"
                    >
                        Categories
                    </a>
                    <a
                        href="genres.php"
                        class="block px-3 py-2 rounded-lg text-xs text-white/50 hover:text-white hover:bg-white/10"
                    >
                        Genres
                    </a>
                    <a
                        href="vouchers.php"
                        class="block px-3 py-2 rounded-lg text-xs text-white/50 hover:text-white hover:bg-white/10"
                    >
                        Vouchers
                    </a>
                    <a
                        href="tiers.php"
                        class="block px-3 py-2 rounded-lg text-xs text-white/50 hover:text-white hover:bg-white/10"
                    >
                        Tier Management
                    </a>
                    <a
                        href="staff.php"
                        class="block px-3 py-2 rounded-lg text-xs text-white/50 hover:text-white hover:bg-white/10"
                    >
                        Staff Accounts
                    </a>
                    <a
                        href="faq.php"
                        class="block px-3 py-2 rounded-lg text-xs text-white/50 hover:text-white hover:bg-white/10"
                    >
                        FAQ Content
                    </a>
                    <a
                        href="about.php"
                        class="block px-3 py-2 rounded-lg text-xs text-white/50 hover:text-white hover:bg-white/10"
                    >
                        About Content
                    </a>
                </div>
            </details>
        </nav>

        <div class="p-4 border-t border-white/10">
            <div
                class="rounded-xl bg-white/7 px-3 py-3 mb-3"
            >
                <p
                    class="text-xs font-semibold text-white truncate"
                >
                    <?= htmlspecialchars(
                        (string) $admin_name,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>
                <p class="text-[10px] text-white/35 mt-0.5">
                    <?= htmlspecialchars(
                        ucfirst(
                            (string) (
                                $_SESSION['role'] ?? 'admin'
                            )
                        ),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>
            </div>

            <a
                href="../logout.php"
                class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl border border-white/10 text-sm font-semibold text-white/60 hover:text-white hover:bg-white/10 transition-colors"
            >
                Log Out
            </a>
        </div>
    </aside>

    <main class="min-h-screen lg:ml-64">
        <header
            class="h-20 px-5 md:px-8 bg-white border-b border-gray-100 flex items-center justify-between sticky top-0 z-20"
        >
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    onclick="openSidebar()"
                    class="lg:hidden w-10 h-10 rounded-xl border border-gray-200 text-gray-600 hover:bg-gray-50"
                    aria-label="Open navigation"
                >
                    ☰
                </button>

                <div>
                    <h1
                        class="text-xl md:text-2xl font-black text-gray-900"
                    >
                        Dashboard
                    </h1>
                    <p class="text-xs text-gray-400 mt-0.5">
                        Business overview and pending work
                    </p>
                </div>
            </div>

            <div class="text-right">
                <p
                    class="text-xs font-semibold text-gray-600"
                >
                    <?= date('l') ?>
                </p>
                <p class="text-xs text-gray-400 mt-0.5">
                    <?= date('d F Y') ?>
                </p>
            </div>
        </header>

        <div class="p-5 md:p-8 max-w-[1600px] mx-auto">
            <section
                class="bg-gradient-to-r from-[#17243d] to-[#263b61] rounded-3xl px-6 md:px-8 py-7 text-white mb-6 relative overflow-hidden"
            >
                <div
                    class="absolute -right-16 -top-20 w-64 h-64 rounded-full border-[42px] border-white/5"
                ></div>

                <div
                    class="relative flex flex-col md:flex-row md:items-center justify-between gap-5"
                >
                    <div>
                        <p
                            class="text-sm text-white/55 font-medium"
                        >
                            Welcome back,
                        </p>
                        <h2
                            class="text-2xl md:text-3xl font-black mt-1"
                        >
                            <?= htmlspecialchars(
                                (string) $admin_name,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </h2>
                        <p
                            class="text-sm text-white/55 mt-2 max-w-xl"
                        >
                            <?= $task_count > 0
                                ? 'There are ' .
                                    number_format($task_count) .
                                    ' pending operational items requiring attention.'
                                : 'All tracked operational items are currently up to date.' ?>
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <a
                            href="reports.php"
                            class="bg-white text-[#17243d] hover:bg-gray-100 font-bold text-sm px-4 py-2.5 rounded-xl transition-colors"
                        >
                            View Reports
                        </a>
                        <a
                            href="add_product.php"
                            class="bg-red-500 hover:bg-red-600 text-white font-bold text-sm px-4 py-2.5 rounded-xl transition-colors"
                        >
                            Add Product
                        </a>
                    </div>
                </div>
            </section>

            <section
                class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6"
            >
                <div
                    class="bg-white rounded-2xl border border-gray-100 p-5"
                >
                    <div
                        class="flex items-start justify-between gap-3"
                    >
                        <div>
                            <p
                                class="text-xs font-bold uppercase tracking-wide text-gray-400"
                            >
                                Total Revenue
                            </p>
                            <p
                                class="text-2xl font-black text-gray-900 mt-2"
                            >
                                RM <?= moneyFormatSen(
                                    $total_revenue_sen
                                ) ?>
                            </p>
                            <p class="text-xs text-gray-400 mt-1">
                                This month:
                                RM <?= moneyFormatSen(
                                    $revenue_month_sen
                                ) ?>
                            </p>
                        </div>
                        <div
                            class="w-11 h-11 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl"
                        >
                            RM
                        </div>
                    </div>
                </div>

                <div
                    class="bg-white rounded-2xl border border-gray-100 p-5"
                >
                    <div
                        class="flex items-start justify-between gap-3"
                    >
                        <div>
                            <p
                                class="text-xs font-bold uppercase tracking-wide text-gray-400"
                            >
                                Confirmed Orders
                            </p>
                            <p
                                class="text-2xl font-black text-gray-900 mt-2"
                            >
                                <?= number_format(
                                    $total_orders
                                ) ?>
                            </p>
                            <p
                                class="text-xs <?= $pending_orders > 0
                                    ? 'text-amber-600'
                                    : 'text-gray-400' ?> mt-1"
                            >
                                <?= number_format(
                                    $pending_orders
                                ) ?>
                                pending processing
                            </p>
                        </div>
                        <div
                            class="w-11 h-11 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center text-xl"
                        >
                            ▤
                        </div>
                    </div>
                </div>

                <div
                    class="bg-white rounded-2xl border border-gray-100 p-5"
                >
                    <div
                        class="flex items-start justify-between gap-3"
                    >
                        <div>
                            <p
                                class="text-xs font-bold uppercase tracking-wide text-gray-400"
                            >
                                Active Products
                            </p>
                            <p
                                class="text-2xl font-black text-gray-900 mt-2"
                            >
                                <?= number_format(
                                    $total_products
                                ) ?>
                            </p>
                            <p
                                class="text-xs <?= $low_stock > 0
                                    ? 'text-red-600'
                                    : 'text-gray-400' ?> mt-1"
                            >
                                <?= number_format(
                                    $low_stock
                                ) ?>
                                low-stock products
                            </p>
                        </div>
                        <div
                            class="w-11 h-11 rounded-2xl bg-violet-50 text-violet-600 flex items-center justify-center text-xl"
                        >
                            ▣
                        </div>
                    </div>
                </div>

                <div
                    class="bg-white rounded-2xl border border-gray-100 p-5"
                >
                    <div
                        class="flex items-start justify-between gap-3"
                    >
                        <div>
                            <p
                                class="text-xs font-bold uppercase tracking-wide text-gray-400"
                            >
                                Customers
                            </p>
                            <p
                                class="text-2xl font-black text-gray-900 mt-2"
                            >
                                <?= number_format(
                                    $total_customers
                                ) ?>
                            </p>
                            <p class="text-xs text-gray-400 mt-1">
                                Registered customer accounts
                            </p>
                        </div>
                        <div
                            class="w-11 h-11 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl"
                        >
                            ♙
                        </div>
                    </div>
                </div>
            </section>

            <section
                class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6"
            >
                <div
                    class="xl:col-span-2 bg-white rounded-2xl border border-gray-100 p-5 md:p-6"
                >
                    <div
                        class="flex items-center justify-between gap-4 mb-5"
                    >
                        <div>
                            <h3
                                class="font-black text-gray-900"
                            >
                                Revenue Trend
                            </h3>
                            <p
                                class="text-xs text-gray-400 mt-1"
                            >
                                Confirmed non-cancelled sales,
                                last 7 days
                            </p>
                        </div>
                        <a
                            href="reports.php?report=sales"
                            class="text-xs font-bold text-red-600 hover:text-red-700"
                        >
                            Full report →
                        </a>
                    </div>

                    <div class="h-72">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>

                <div
                    class="bg-white rounded-2xl border border-gray-100 p-5 md:p-6"
                >
                    <div class="mb-5">
                        <h3
                            class="font-black text-gray-900"
                        >
                            Order Status
                        </h3>
                        <p class="text-xs text-gray-400 mt-1">
                            Confirmed orders by current status
                        </p>
                    </div>

                    <div class="h-72">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </section>

            <section
                class="grid grid-cols-1 xl:grid-cols-3 gap-6"
            >
                <div
                    class="xl:col-span-2 bg-white rounded-2xl border border-gray-100 overflow-hidden"
                >
                    <div
                        class="px-5 md:px-6 py-5 border-b border-gray-100 flex items-center justify-between gap-4"
                    >
                        <div>
                            <h3
                                class="font-black text-gray-900"
                            >
                                Recent Orders
                            </h3>
                            <p
                                class="text-xs text-gray-400 mt-1"
                            >
                                Latest confirmed customer orders
                            </p>
                        </div>
                        <a
                            href="orders.php"
                            class="text-xs font-bold text-red-600 hover:text-red-700"
                        >
                            View all →
                        </a>
                    </div>

                    <?php if (!$recent_orders): ?>
                    <div class="py-14 text-center">
                        <p class="text-sm text-gray-400">
                            No confirmed orders yet.
                        </p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="bg-gray-50/70">
                                    <th
                                        class="px-5 md:px-6 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-gray-400"
                                    >
                                        Order
                                    </th>
                                    <th
                                        class="px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-gray-400"
                                    >
                                        Customer
                                    </th>
                                    <th
                                        class="px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-gray-400"
                                    >
                                        Amount
                                    </th>
                                    <th
                                        class="px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wide text-gray-400"
                                    >
                                        Status
                                    </th>
                                    <th
                                        class="px-5 md:px-6 py-3 text-right text-[10px] font-bold uppercase tracking-wide text-gray-400"
                                    >
                                        Date
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (
                                    $recent_orders as $order
                                ):
                                    $order_status =
                                        (string) $order[
                                            'order_status'
                                        ];
                                    $badge_class =
                                        $status_badge_classes[
                                            $order_status
                                        ] ??
                                        'bg-gray-50 text-gray-600 border-gray-200';
                                    $order_amount_sen =
                                        moneyDecimalToSen(
                                            (string) $order[
                                                'order_total_amount'
                                            ]
                                        );
                                ?>
                                <tr
                                    class="border-t border-gray-100 hover:bg-gray-50/60 transition-colors"
                                >
                                    <td
                                        class="px-5 md:px-6 py-4"
                                    >
                                        <a
                                            href="orders.php"
                                            class="text-sm font-black text-gray-900 hover:text-red-600"
                                        >
                                            #<?= str_pad(
                                                (string) (
                                                    (int) $order[
                                                        'order_id'
                                                    ]
                                                ),
                                                4,
                                                '0',
                                                STR_PAD_LEFT
                                            ) ?>
                                        </a>
                                    </td>
                                    <td
                                        class="px-4 py-4 text-sm text-gray-600 whitespace-nowrap"
                                    >
                                        <?= htmlspecialchars(
                                            trim(
                                                (string) $order[
                                                    'user_first_name'
                                                ] .
                                                ' ' .
                                                (string) $order[
                                                    'user_last_name'
                                                ]
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </td>
                                    <td
                                        class="px-4 py-4 text-sm font-bold text-gray-800 whitespace-nowrap"
                                    >
                                        RM <?= moneyFormatSen(
                                            $order_amount_sen
                                        ) ?>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span
                                            class="<?= $badge_class ?> inline-flex border px-2.5 py-1 rounded-full text-[10px] font-bold capitalize"
                                        >
                                            <?= htmlspecialchars(
                                                $order_status,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </span>
                                    </td>
                                    <td
                                        class="px-5 md:px-6 py-4 text-right text-xs text-gray-400 whitespace-nowrap"
                                    >
                                        <?= date(
                                            'd M Y',
                                            strtotime(
                                                $order[
                                                    'order_created_at'
                                                ]
                                            )
                                        ) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="space-y-6">
                    <div
                        class="bg-white rounded-2xl border border-gray-100 overflow-hidden"
                    >
                        <div
                            class="px-5 py-5 border-b border-gray-100 flex items-center justify-between"
                        >
                            <div>
                                <h3
                                    class="font-black text-gray-900"
                                >
                                    Pending Work
                                </h3>
                                <p
                                    class="text-xs text-gray-400 mt-1"
                                >
                                    Items requiring attention
                                </p>
                            </div>
                            <span
                                class="min-w-8 h-8 px-2 rounded-full bg-red-50 text-red-600 text-xs font-black flex items-center justify-center"
                            >
                                <?= number_format(
                                    $task_count
                                ) ?>
                            </span>
                        </div>

                        <div class="p-3">
                            <?php
                            $pending_items = [
                                [
                                    'label' =>
                                        'Orders to process',
                                    'count' =>
                                        $pending_orders,
                                    'href' =>
                                        'orders.php?filter=pending',
                                    'icon' => '▤',
                                ],
                                [
                                    'label' =>
                                        'Customer returns',
                                    'count' =>
                                        $pending_returns,
                                    'href' => 'returns.php',
                                    'icon' => '↩',
                                ],
                                [
                                    'label' =>
                                        'Reviews to moderate',
                                    'count' =>
                                        $pending_reviews,
                                    'href' => 'reviews.php',
                                    'icon' => '★',
                                ],
                                [
                                    'label' =>
                                        'Purchase requisitions',
                                    'count' => $pending_pr,
                                    'href' => 'pr.php',
                                    'icon' => '▧',
                                ],
                                [
                                    'label' =>
                                        'Supplier returns',
                                    'count' =>
                                        $pending_supplier_returns,
                                    'href' =>
                                        'supplier_returns.php',
                                    'icon' => '↪',
                                ],
                            ];
                            ?>

                            <?php foreach (
                                $pending_items as $item
                            ): ?>
                            <a
                                href="<?= htmlspecialchars(
                                    $item['href'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                class="flex items-center gap-3 px-3 py-3 rounded-xl hover:bg-gray-50 transition-colors"
                            >
                                <span
                                    class="w-9 h-9 rounded-xl bg-gray-100 text-gray-600 flex items-center justify-center"
                                >
                                    <?= $item['icon'] ?>
                                </span>
                                <span
                                    class="flex-1 text-sm font-semibold text-gray-700"
                                >
                                    <?= htmlspecialchars(
                                        $item['label'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>
                                <span
                                    class="<?= $item['count'] > 0
                                        ? 'bg-red-50 text-red-600'
                                        : 'bg-emerald-50 text-emerald-600' ?> min-w-7 h-7 px-2 rounded-full text-xs font-black flex items-center justify-center"
                                >
                                    <?= (int) $item['count'] ?>
                                </span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div
                        class="bg-white rounded-2xl border border-gray-100 overflow-hidden"
                    >
                        <div
                            class="px-5 py-5 border-b border-gray-100 flex items-center justify-between"
                        >
                            <div>
                                <h3
                                    class="font-black text-gray-900"
                                >
                                    Top Products
                                </h3>
                                <p
                                    class="text-xs text-gray-400 mt-1"
                                >
                                    Highest units sold
                                </p>
                            </div>
                            <a
                                href="reports.php?report=product_sales"
                                class="text-xs font-bold text-red-600"
                            >
                                Report →
                            </a>
                        </div>

                        <div class="p-4 space-y-3">
                            <?php if (!$top_products): ?>
                            <p
                                class="text-sm text-gray-400 text-center py-5"
                            >
                                No sales data yet.
                            </p>
                            <?php endif; ?>

                            <?php foreach (
                                $top_products as $index => $product
                            ):
                                $product_revenue_sen =
                                    moneyDecimalToSen(
                                        (string) (
                                            $product['revenue']
                                            ?? '0.00'
                                        )
                                    );
                            ?>
                            <div
                                class="flex items-center gap-3"
                            >
                                <span
                                    class="w-7 h-7 rounded-full bg-[#17243d] text-white text-xs font-black flex items-center justify-center flex-shrink-0"
                                >
                                    <?= $index + 1 ?>
                                </span>

                                <?php if (
                                    !empty(
                                        $product[
                                            'product_cover_image'
                                        ]
                                    )
                                ): ?>
                                <img
                                    src="../assets/images/<?= htmlspecialchars(
                                        basename(
                                            (string) $product[
                                                'product_cover_image'
                                            ]
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    alt=""
                                    class="w-8 h-11 object-cover rounded-lg bg-gray-100 flex-shrink-0"
                                >
                                <?php else: ?>
                                <div
                                    class="w-8 h-11 rounded-lg bg-gray-100 flex items-center justify-center text-gray-300 text-xs flex-shrink-0"
                                >
                                    ▣
                                </div>
                                <?php endif; ?>

                                <div class="flex-1 min-w-0">
                                    <p
                                        class="text-xs font-bold text-gray-800 truncate"
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
                                        class="text-[11px] text-gray-400 mt-0.5"
                                    >
                                        <?= number_format(
                                            (int) $product[
                                                'total_sold'
                                            ]
                                        ) ?>
                                        sold
                                    </p>
                                </div>

                                <p
                                    class="text-xs font-black text-emerald-600 whitespace-nowrap"
                                >
                                    RM <?= moneyFormatSen(
                                        $product_revenue_sen
                                    ) ?>
                                </p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if ($low_stock_products): ?>
                    <div
                        class="bg-white rounded-2xl border border-red-100 overflow-hidden"
                    >
                        <div
                            class="px-5 py-5 border-b border-red-100 bg-red-50/50 flex items-center justify-between"
                        >
                            <div>
                                <h3
                                    class="font-black text-red-800"
                                >
                                    Low Stock
                                </h3>
                                <p
                                    class="text-xs text-red-500 mt-1"
                                >
                                    Products to restock
                                </p>
                            </div>
                            <a
                                href="products.php?filter=low_stock"
                                class="text-xs font-bold text-red-600"
                            >
                                View →
                            </a>
                        </div>

                        <div class="p-4 space-y-3">
                            <?php foreach (
                                $low_stock_products as $product
                            ): ?>
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <p
                                    class="text-xs font-semibold text-gray-700 truncate"
                                >
                                    <?= htmlspecialchars(
                                        (string) $product[
                                            'product_title'
                                        ],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>
                                <span
                                    class="text-xs font-black text-red-600 whitespace-nowrap"
                                >
                                    <?= (int) $product[
                                        'physical_stock_quantity'
                                    ] ?>
                                    left
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </main>

    <script
        src="https://cdn.jsdelivr.net/npm/chart.js"
    ></script>
    <script>
    function openSidebar() {
        document
            .getElementById('adminSidebar')
            .classList
            .remove('-translate-x-full');

        document
            .getElementById('sidebarOverlay')
            .classList
            .remove('hidden');
    }

    function closeSidebar() {
        document
            .getElementById('adminSidebar')
            .classList
            .add('-translate-x-full');

        document
            .getElementById('sidebarOverlay')
            .classList
            .add('hidden');
    }

    const chartFont = {
        family:
            'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
        size: 11,
    };

    new Chart(
        document.getElementById('revenueChart'),
        {
            type: 'line',
            data: {
                labels: <?= json_encode(
                    $labels_7days,
                    JSON_HEX_TAG |
                    JSON_HEX_AMP |
                    JSON_HEX_APOS |
                    JSON_HEX_QUOT
                ) ?>,
                datasets: [{
                    data: <?= json_encode(
                        $revenue_7days,
                        JSON_HEX_TAG |
                        JSON_HEX_AMP |
                        JSON_HEX_APOS |
                        JSON_HEX_QUOT
                    ) ?>,
                    borderColor: '#ef4444',
                    backgroundColor:
                        'rgba(239, 68, 68, 0.08)',
                    fill: true,
                    tension: 0.38,
                    borderWidth: 2.5,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    pointBackgroundColor: '#ef4444',
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index',
                },
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        callbacks: {
                            label: context =>
                                'RM ' +
                                Number(
                                    context.raw
                                ).toLocaleString(
                                    'en-MY',
                                    {
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2,
                                    }
                                ),
                        },
                    },
                },
                scales: {
                    x: {
                        grid: {
                            display: false,
                        },
                        ticks: {
                            font: chartFont,
                            color: '#94a3b8',
                        },
                        border: {
                            display: false,
                        },
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#f1f5f9',
                        },
                        ticks: {
                            font: chartFont,
                            color: '#94a3b8',
                            callback: value =>
                                'RM ' +
                                Number(value).toLocaleString(),
                        },
                        border: {
                            display: false,
                        },
                    },
                },
            },
        }
    );

    new Chart(
        document.getElementById('statusChart'),
        {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(
                    $status_labels,
                    JSON_HEX_TAG |
                    JSON_HEX_AMP |
                    JSON_HEX_APOS |
                    JSON_HEX_QUOT
                ) ?>,
                datasets: [{
                    data: <?= json_encode(
                        $status_counts
                    ) ?>,
                    backgroundColor: <?= json_encode(
                        $status_chart_colors
                    ) ?>,
                    borderWidth: 0,
                    hoverOffset: 4,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '67%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            boxWidth: 7,
                            boxHeight: 7,
                            padding: 14,
                            font: chartFont,
                            color: '#64748b',
                        },
                    },
                },
            },
        }
    );
    </script>
</body>
</html>