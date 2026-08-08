<?php

$admin_current = basename($_SERVER['PHP_SELF']);

$pending_orders_count = (int) $pdo->query("
    SELECT COUNT(*)
    FROM orders
    WHERE order_status = 'pending'
    AND order_payment_status = 'confirmed'
")->fetchColumn();

$pending_returns_count = (int) $pdo->query("
    SELECT COUNT(*)
    FROM return_requests
    WHERE return_status = 'pending'
")->fetchColumn();

$pending_supplier_returns_count = (int) $pdo->query("
    SELECT COUNT(*)
    FROM supplier_returns
    WHERE return_status IN ('pending', 'escalated')
")->fetchColumn();

$pending_reviews_count = (int) $pdo->query("
    SELECT COUNT(*)
    FROM product_reviews
    WHERE review_status = 'pending'
")->fetchColumn();

$pending_account_deletions_count = 0;

if (is_senior_admin()) {
    $pending_account_deletions_count =
        (int) $pdo->query("
            SELECT COUNT(*)
            FROM
                account_deletion_requests
            WHERE
                deletion_request_status =
                    'pending'
        ")->fetchColumn();
}

$low_stock_count = (int) $pdo->query("
    SELECT COUNT(*)
    FROM product_physical
    WHERE physical_stock_quantity <=
        physical_low_stock_threshold
")->fetchColumn();

$pending_pr_count = (int) $pdo->query("
    SELECT COUNT(*)
    FROM purchase_requisitions
    WHERE pr_status IN ('pending', 'approved')
")->fetchColumn();

function adminSidebarLinkClass(
    bool $isActive
): string {
    return $isActive
        ? 'bg-white/12 text-white font-semibold'
        : 'text-white/65 hover:text-white hover:bg-white/10';
}

function adminSidebarSubLinkClass(
    bool $isActive
): string {
    return $isActive
        ? 'bg-white/10 text-white font-semibold'
        : 'text-white/50 hover:text-white hover:bg-white/10';
}

$product_pages = [
    'products.php',
    'add_product.php',
    'edit_product.php',
];

$order_pages = [
    'orders.php',
];

$return_pages = [
    'returns.php',
];

$review_pages = [
    'reviews.php',
];

$user_pages = [
    'users.php',
];

$account_deletion_pages = [
    'account_deletion_requests.php',
];

$identity_conflict_pages = [
    'identity_conflicts.php',
];

$report_pages = [
    'reports.php',
];

$pr_pages = [
    'pr.php',
];

$rfq_pages = [
    'rfq.php',
    'quotations.php',
];

$po_pages = [
    'purchase_orders.php',
    'po_detail.php',
];

$goods_received_pages = [
    'goods_received.php',
];

$supplier_pages = [
    'suppliers.php',
    'supplier_performance.php',
];

$supplier_invoice_pages = [
    'supplier_invoices.php',
];

$supplier_return_pages = [
    'supplier_returns.php',
];

$management_pages = [
    'categories.php',
    'genres.php',
    'vouchers.php',
    'tiers.php',
    'staff.php',
    'admins.php',
    'homepage.php',
    'faq.php',
    'about.php',
];

$management_open = in_array(
    $admin_current,
    $management_pages,
    true
);

$admin_display_name =
    $_SESSION['user_first_name'] ??
    $_SESSION['user_name'] ??
    'Admin';

$admin_access_label =
    is_senior_admin()
        ? 'Super Admin'
        : 'Admin';
?>

<style>
    body {
        background: #f5f6fa !important;
        color: #1f2937;
    }

    @media (min-width: 1024px) {
        body {
            padding-left: 16rem;
        }
    }

    .admin-sidebar-scrollbar::-webkit-scrollbar {
        width: 5px;
    }

    .admin-sidebar-scrollbar::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.16);
        border-radius: 999px;
    }

    body .bg-white.rounded-2xl {
        border: 1px solid #edf0f5;
        box-shadow:
            0 1px 2px rgba(15, 23, 42, 0.03),
            0 10px 28px rgba(15, 23, 42, 0.045);
    }

    body table thead tr {
        background: #f8fafc;
    }

    body table tbody tr {
        transition:
            background-color 0.15s ease,
            transform 0.15s ease;
    }

    body input:not([type="checkbox"]):not([type="radio"]),
    body select,
    body textarea {
        background-color: #ffffff;
    }

    body input:focus,
    body select:focus,
    body textarea:focus {
        box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.08);
    }
</style>

<div
    id="adminSidebarOverlay"
    class="fixed inset-0 bg-black/50 z-30 hidden lg:hidden"
    onclick="closeAdminSidebar()"
></div>

<aside
    id="adminSharedSidebar"
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
            onclick="closeAdminSidebar()"
            class="lg:hidden ml-auto text-white/60 hover:text-white"
            aria-label="Close navigation"
        >
            ✕
        </button>
    </div>

    <nav
        class="admin-sidebar-scrollbar flex-1 overflow-y-auto px-4 py-5"
    >
        <p
            class="px-3 mb-2 text-[10px] uppercase tracking-[0.18em] font-bold text-white/30"
        >
            Overview
        </p>

        <a
            href="dashboard.php"
            class="<?= adminSidebarLinkClass(
                $admin_current === 'dashboard.php'
            ) ?> flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors text-sm"
        >
            <span class="w-6 text-center">⌂</span>
            Dashboard
        </a>

        <a
            href="reports.php"
            class="<?= adminSidebarLinkClass(
                in_array(
                    $admin_current,
                    $report_pages,
                    true
                )
            ) ?> mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors text-sm"
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
            class="<?= adminSidebarLinkClass(
                in_array(
                    $admin_current,
                    $product_pages,
                    true
                )
            ) ?> flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors text-sm"
        >
            <span class="w-6 text-center">▣</span>
            Products

            <?php if ($low_stock_count > 0): ?>
            <span
                class="ml-auto min-w-5 h-5 px-1.5 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center"
            >
                <?= $low_stock_count ?>
            </span>
            <?php endif; ?>
        </a>

        <a
            href="orders.php"
            class="<?= adminSidebarLinkClass(
                in_array(
                    $admin_current,
                    $order_pages,
                    true
                )
            ) ?> mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors text-sm"
        >
            <span class="w-6 text-center">▤</span>
            Orders

            <?php if ($pending_orders_count > 0): ?>
            <span
                class="ml-auto min-w-5 h-5 px-1.5 rounded-full bg-amber-500 text-white text-[10px] font-bold flex items-center justify-center"
            >
                <?= $pending_orders_count ?>
            </span>
            <?php endif; ?>
        </a>

        <a
            href="returns.php"
            class="<?= adminSidebarLinkClass(
                in_array(
                    $admin_current,
                    $return_pages,
                    true
                )
            ) ?> mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors text-sm"
        >
            <span class="w-6 text-center">↩</span>
            Returns

            <?php if ($pending_returns_count > 0): ?>
            <span
                class="ml-auto min-w-5 h-5 px-1.5 rounded-full bg-orange-500 text-white text-[10px] font-bold flex items-center justify-center"
            >
                <?= $pending_returns_count ?>
            </span>
            <?php endif; ?>
        </a>

        <a
            href="reviews.php"
            class="<?= adminSidebarLinkClass(
                in_array(
                    $admin_current,
                    $review_pages,
                    true
                )
            ) ?> mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors text-sm"
        >
            <span class="w-6 text-center">★</span>
            Reviews

            <?php if ($pending_reviews_count > 0): ?>
            <span
                class="ml-auto min-w-5 h-5 px-1.5 rounded-full bg-blue-500 text-white text-[10px] font-bold flex items-center justify-center"
            >
                <?= $pending_reviews_count ?>
            </span>
            <?php endif; ?>
        </a>

        <a
            href="users.php"
            class="<?= adminSidebarLinkClass(
                in_array(
                    $admin_current,
                    $user_pages,
                    true
                )
            ) ?> mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors text-sm"
        >
            <span class="w-6 text-center">♙</span>
            Customers
        </a>

        <?php if (
            is_senior_admin()
        ): ?>
        <a
            href="account_deletion_requests.php"
            class="<?= adminSidebarLinkClass(
                in_array(
                    $admin_current,
                    $account_deletion_pages,
                    true
                )
            ) ?> mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors text-sm"
        >
            <span
                class="w-6 text-center"
            >
                ⊘
            </span>

            Deletion Requests

            <?php if (
                $pending_account_deletions_count >
                0
            ): ?>
            <span
                class="ml-auto min-w-5 h-5 px-1.5 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center"
            >
                <?= $pending_account_deletions_count ?>
            </span>
            <?php endif; ?>
        </a>

        <a
            href="identity_conflicts.php"
            class="<?= adminSidebarLinkClass(
                in_array(
                    $admin_current,
                    $identity_conflict_pages,
                    true
                )
            ) ?> mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors text-sm"
        >
            <span
                class="w-6 text-center"
            >
                ⟳
            </span>

            Phone Ownership
        </a>

        <?php endif; ?>

        <p
            class="px-3 mt-6 mb-2 text-[10px] uppercase tracking-[0.18em] font-bold text-white/30"
        >
            Procurement
        </p>

        <a
            href="pr.php"
            class="<?= adminSidebarLinkClass(
                in_array(
                    $admin_current,
                    $pr_pages,
                    true
                )
            ) ?> flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors text-sm"
        >
            <span class="w-6 text-center">▧</span>
            Requisitions

            <?php if ($pending_pr_count > 0): ?>
            <span
                class="ml-auto min-w-5 h-5 px-1.5 rounded-full bg-blue-500 text-white text-[10px] font-bold flex items-center justify-center"
            >
                <?= $pending_pr_count ?>
            </span>
            <?php endif; ?>
        </a>

        <a
            href="rfq.php"
            class="<?= adminSidebarLinkClass(
                in_array(
                    $admin_current,
                    $rfq_pages,
                    true
                )
            ) ?> mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors text-sm"
        >
            <span class="w-6 text-center">▨</span>
            RFQ
        </a>

        <a
            href="purchase_orders.php"
            class="<?= adminSidebarLinkClass(
                in_array(
                    $admin_current,
                    $po_pages,
                    true
                )
            ) ?> mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors text-sm"
        >
            <span class="w-6 text-center">▦</span>
            Purchase Orders
        </a>

        <a
            href="goods_received.php"
            class="<?= adminSidebarLinkClass(
                in_array(
                    $admin_current,
                    $goods_received_pages,
                    true
                )
            ) ?> mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors text-sm"
        >
            <span class="w-6 text-center">✓</span>
            Goods Received
        </a>

        <a
            href="suppliers.php"
            class="<?= adminSidebarLinkClass(
                in_array(
                    $admin_current,
                    $supplier_pages,
                    true
                )
            ) ?> mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors text-sm"
        >
            <span class="w-6 text-center">▰</span>
            Suppliers
        </a>

        <a
            href="supplier_invoices.php"
            class="<?= adminSidebarLinkClass(
                in_array(
                    $admin_current,
                    $supplier_invoice_pages,
                    true
                )
            ) ?> mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors text-sm"
        >
            <span class="w-6 text-center">▩</span>
            Supplier Invoices
        </a>

        <a
            href="supplier_returns.php"
            class="<?= adminSidebarLinkClass(
                in_array(
                    $admin_current,
                    $supplier_return_pages,
                    true
                )
            ) ?> mt-1 flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors text-sm"
        >
            <span class="w-6 text-center">↪</span>
            Supplier Returns

            <?php if (
                $pending_supplier_returns_count > 0
            ): ?>
            <span
                class="ml-auto min-w-5 h-5 px-1.5 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center"
            >
                <?= $pending_supplier_returns_count ?>
            </span>
            <?php endif; ?>
        </a>

        <details
            class="mt-5 group"
            <?= $management_open ? 'open' : '' ?>
        >
            <summary
                class="<?= adminSidebarLinkClass(
                    $management_open
                ) ?> cursor-pointer list-none flex items-center justify-between px-3 py-2.5 rounded-xl transition-colors text-sm"
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
                    class="<?= adminSidebarSubLinkClass(
                        $admin_current ===
                            'categories.php'
                    ) ?> block px-3 py-2 rounded-lg text-xs transition-colors"
                >
                    Categories
                </a>

                <a
                    href="genres.php"
                    class="<?= adminSidebarSubLinkClass(
                        $admin_current === 'genres.php'
                    ) ?> block px-3 py-2 rounded-lg text-xs transition-colors"
                >
                    Genres
                </a>

                <a
                    href="vouchers.php"
                    class="<?= adminSidebarSubLinkClass(
                        $admin_current === 'vouchers.php'
                    ) ?> block px-3 py-2 rounded-lg text-xs transition-colors"
                >
                    Vouchers
                </a>

                <a
                    href="tiers.php"
                    class="<?= adminSidebarSubLinkClass(
                        $admin_current === 'tiers.php'
                    ) ?> block px-3 py-2 rounded-lg text-xs transition-colors"
                >
                    Tier Management
                </a>

                <a
                    href="staff.php"
                    class="<?= adminSidebarSubLinkClass(
                        $admin_current === 'staff.php'
                    ) ?> block px-3 py-2 rounded-lg text-xs transition-colors"
                >
                    Staff Accounts
                </a>

                <?php if (is_senior_admin()): ?>
                <a
                    href="admins.php"
                    class="<?= adminSidebarSubLinkClass(
                        $admin_current === 'admins.php'
                    ) ?> block px-3 py-2 rounded-lg text-xs transition-colors"
                >
                    Admin Accounts
                </a>
                <?php endif; ?>

                <a
                    href="homepage.php"
                    class="<?= adminSidebarSubLinkClass(
                        $admin_current === 'homepage.php'
                    ) ?> block px-3 py-2 rounded-lg text-xs transition-colors"
                >
                    Homepage Content
                </a>

                <a
                    href="faq.php"
                    class="<?= adminSidebarSubLinkClass(
                        $admin_current === 'faq.php'
                    ) ?> block px-3 py-2 rounded-lg text-xs transition-colors"
                >
                    FAQ Content
                </a>

                <a
                    href="about.php"
                    class="<?= adminSidebarSubLinkClass(
                        $admin_current === 'about.php'
                    ) ?> block px-3 py-2 rounded-lg text-xs transition-colors"
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
                    (string) $admin_display_name,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </p>

            <p class="text-[10px] text-white/35 mt-0.5">
                <?= htmlspecialchars(
                    $admin_access_label,
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

<header
    class="lg:hidden h-16 px-4 bg-white border-b border-gray-100 flex items-center justify-between sticky top-0 z-20"
>
    <div class="flex items-center gap-3">
        <button
            type="button"
            onclick="openAdminSidebar()"
            class="w-10 h-10 rounded-xl border border-gray-200 text-gray-600 hover:bg-gray-50"
            aria-label="Open navigation"
        >
            ☰
        </button>

        <div>
            <p
                class="text-sm font-black text-gray-900"
            >
                MangaVault Admin
            </p>
            <p class="text-[10px] text-gray-400">
                <?= htmlspecialchars(
                    ucwords(
                        str_replace(
                            ['_', '.php'],
                            [' ', ''],
                            $admin_current
                        )
                    ),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </p>
        </div>
    </div>

    <?php if ($low_stock_count > 0): ?>
    <a
        href="products.php?filter=low_stock"
        class="min-w-8 h-8 px-2 rounded-full bg-red-50 text-red-600 text-xs font-black flex items-center justify-center"
        title="Low-stock products"
    >
        <?= $low_stock_count ?>
    </a>
    <?php endif; ?>
</header>

<script>
function openAdminSidebar() {
    document
        .getElementById('adminSharedSidebar')
        .classList
        .remove('-translate-x-full');

    document
        .getElementById('adminSidebarOverlay')
        .classList
        .remove('hidden');
}

function closeAdminSidebar() {
    document
        .getElementById('adminSharedSidebar')
        .classList
        .add('-translate-x-full');

    document
        .getElementById('adminSidebarOverlay')
        .classList
        .add('hidden');
}

window.addEventListener('resize', function () {
    if (window.innerWidth >= 1024) {
        document
            .getElementById('adminSidebarOverlay')
            .classList
            .add('hidden');
    }
});
</script>