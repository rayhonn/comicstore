<?php

$supplier_current = basename($_SERVER['PHP_SELF']);

$supplier_section_map = [
    'dashboard.php' => 'dashboard',

    'rfq.php' => 'rfq',
    'rfq_detail.php' => 'rfq',

    'quotations.php' => 'quotations',

    'purchase_orders.php' => 'purchase_orders',
    'po_detail.php' => 'purchase_orders',
    'delivery_order.php' => 'purchase_orders',

    'invoices.php' => 'invoices',

    'returns.php' => 'returns',

    'products.php' => 'products',
];

$supplier_section =
    $supplier_section_map[$supplier_current] ?? '';

$supplier_name = trim(
    (string) ($_SESSION['supplier_name'] ?? 'Supplier')
);

$supplier_initial = strtoupper(
    substr($supplier_name, 0, 1)
);

if ($supplier_initial === '') {
    $supplier_initial = 'S';
}

function supplier_nav_item_class(
    string $section,
    string $current_section
): string {
    if ($section === $current_section) {
        return
            'relative flex items-center gap-3 px-4 py-3 rounded-xl ' .
            'bg-blue-500/15 text-white font-semibold';
    }

    return
        'relative flex items-center gap-3 px-4 py-3 rounded-xl ' .
        'text-slate-400 hover:text-white hover:bg-white/5 transition-colors';
}

?>
<style>
    @media (min-width: 1024px) {
        body {
            margin-left: 18rem !important;
            width: calc(100% - 18rem) !important;
        }
    }

    @media (max-width: 1023px) {
        body {
            padding-top: 4rem;
        }
    }
</style>

<div
    id="supplierSidebarOverlay"
    class="fixed inset-0 bg-slate-950/50 z-40 hidden lg:hidden"
    onclick="closeSupplierSidebar()"
></div>

<header
    class="fixed top-0 left-0 right-0 h-16 bg-[#0f1b2e] border-b border-white/10 z-30 flex items-center justify-between px-4 lg:hidden"
>
    <button
        type="button"
        onclick="openSupplierSidebar()"
        class="w-10 h-10 rounded-xl bg-white/5 hover:bg-white/10 text-white flex items-center justify-center transition-colors"
        aria-label="Open navigation"
    >
        <svg
            class="w-5 h-5"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
        >
            <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M4 6h16M4 12h16M4 18h16"
            ></path>
        </svg>
    </button>

    <div class="text-base font-black text-white">
        MANGA<span class="text-blue-400">VAULT</span>
    </div>

    <div
        class="w-9 h-9 rounded-xl bg-blue-500 text-white flex items-center justify-center text-sm font-bold"
    >
        <?= htmlspecialchars($supplier_initial) ?>
    </div>
</header>

<aside
    id="supplierSidebar"
    class="fixed inset-y-0 left-0 z-50 w-72 bg-[#0f1b2e] text-white
           -translate-x-full lg:translate-x-0 transition-transform duration-300
           flex flex-col shadow-2xl"
>
    <div class="h-20 px-6 flex items-center border-b border-white/10">
        <div>
            <div class="text-xl font-black tracking-tight">
                MANGA<span class="text-blue-400">VAULT</span>
            </div>

            <div
                class="text-[10px] uppercase tracking-[0.22em] text-slate-500 font-semibold mt-0.5"
            >
                Supplier Portal
            </div>
        </div>

        <button
            type="button"
            onclick="closeSupplierSidebar()"
            class="ml-auto w-9 h-9 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 flex items-center justify-center lg:hidden"
            aria-label="Close navigation"
        >
            <svg
                class="w-5 h-5"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M6 18L18 6M6 6l12 12"
                ></path>
            </svg>
        </button>
    </div>

    <div class="px-4 py-5 flex-1 overflow-y-auto">
        <p
            class="px-4 mb-2 text-[10px] uppercase tracking-[0.18em] text-slate-600 font-bold"
        >
            Workspace
        </p>

        <nav class="space-y-1">
            <a
                href="dashboard.php"
                class="<?= supplier_nav_item_class(
                    'dashboard',
                    $supplier_section
                ) ?>"
            >
                <?php if ($supplier_section === 'dashboard'): ?>
                <span
                    class="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-8 bg-blue-400 rounded-r-full"
                ></span>
                <?php endif; ?>

                <svg
                    class="w-5 h-5 flex-shrink-0"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M3 13h8V3H3v10zM13 21h8V11h-8v10zM13 3v6h8V3h-8zM3 21h8v-6H3v6z"
                    ></path>
                </svg>

                <span class="text-sm">Dashboard</span>
            </a>

            <a
                href="rfq.php"
                class="<?= supplier_nav_item_class(
                    'rfq',
                    $supplier_section
                ) ?>"
            >
                <?php if ($supplier_section === 'rfq'): ?>
                <span
                    class="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-8 bg-blue-400 rounded-r-full"
                ></span>
                <?php endif; ?>

                <svg
                    class="w-5 h-5 flex-shrink-0"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M9 12h6M9 16h6M9 8h6M5 4h14v16H5V4z"
                    ></path>
                </svg>

                <span class="text-sm">RFQs</span>
            </a>

            <a
                href="quotations.php"
                class="<?= supplier_nav_item_class(
                    'quotations',
                    $supplier_section
                ) ?>"
            >
                <?php if ($supplier_section === 'quotations'): ?>
                <span
                    class="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-8 bg-blue-400 rounded-r-full"
                ></span>
                <?php endif; ?>

                <svg
                    class="w-5 h-5 flex-shrink-0"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M7 3h10l4 4v14H7V3zM17 3v5h5M10 13h8M10 17h6"
                    ></path>
                </svg>

                <span class="text-sm">My Quotations</span>
            </a>

            <a
                href="purchase_orders.php"
                class="<?= supplier_nav_item_class(
                    'purchase_orders',
                    $supplier_section
                ) ?>"
            >
                <?php if ($supplier_section === 'purchase_orders'): ?>
                <span
                    class="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-8 bg-blue-400 rounded-r-full"
                ></span>
                <?php endif; ?>

                <svg
                    class="w-5 h-5 flex-shrink-0"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M4 7h16v13H4V7zM7 4h10v3H7V4zM8 11h8M8 15h5"
                    ></path>
                </svg>

                <span class="text-sm">Purchase Orders</span>
            </a>

            <a
                href="invoices.php"
                class="<?= supplier_nav_item_class(
                    'invoices',
                    $supplier_section
                ) ?>"
            >
                <?php if ($supplier_section === 'invoices'): ?>
                <span
                    class="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-8 bg-blue-400 rounded-r-full"
                ></span>
                <?php endif; ?>

                <svg
                    class="w-5 h-5 flex-shrink-0"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M6 3h12v18l-3-2-3 2-3-2-3 2V3zM9 8h6M9 12h6M9 16h4"
                    ></path>
                </svg>

                <span class="text-sm">Invoices</span>
            </a>

            <a
                href="returns.php"
                class="<?= supplier_nav_item_class(
                    'returns',
                    $supplier_section
                ) ?>"
            >
                <?php if ($supplier_section === 'returns'): ?>
                <span
                    class="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-8 bg-blue-400 rounded-r-full"
                ></span>
                <?php endif; ?>

                <svg
                    class="w-5 h-5 flex-shrink-0"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M9 14l-4-4 4-4M5 10h9a5 5 0 010 10h-3"
                    ></path>
                </svg>

                <span class="text-sm">Returns</span>
            </a>
        </nav>

        <div class="my-5 border-t border-white/10"></div>

        <p
            class="px-4 mb-2 text-[10px] uppercase tracking-[0.18em] text-slate-600 font-bold"
        >
            Catalogue
        </p>

        <nav>
            <a
                href="products.php"
                class="<?= supplier_nav_item_class(
                    'products',
                    $supplier_section
                ) ?>"
            >
                <?php if ($supplier_section === 'products'): ?>
                <span
                    class="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-8 bg-blue-400 rounded-r-full"
                ></span>
                <?php endif; ?>

                <svg
                    class="w-5 h-5 flex-shrink-0"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M4 5.5A2.5 2.5 0 016.5 3H20v16H6.5A2.5 2.5 0 004 21.5v-16zM4 5.5v16"
                    ></path>
                </svg>

                <span class="text-sm">My Products</span>
            </a>
        </nav>
    </div>

    <div class="p-4 border-t border-white/10">
        <div class="bg-white/5 rounded-2xl p-3">
            <div class="flex items-center gap-3">
                <div
                    class="w-10 h-10 rounded-xl bg-blue-500 flex items-center justify-center font-black text-white flex-shrink-0"
                >
                    <?= htmlspecialchars($supplier_initial) ?>
                </div>

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-white truncate">
                        <?= htmlspecialchars($supplier_name) ?>
                    </p>

                    <p class="text-[10px] text-slate-500 uppercase tracking-wide mt-0.5">
                        Supplier Account
                    </p>
                </div>
            </div>

            <button
                type="button"
                onclick="openSharedLogoutModal()"
                class="mt-3 w-full flex items-center justify-center gap-2 bg-white/5 hover:bg-red-500/15 text-slate-400 hover:text-red-300 text-xs font-semibold px-3 py-2.5 rounded-xl transition-colors"
            >
                <svg
                    class="w-4 h-4"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M10 17l5-5-5-5M15 12H3M15 4h4a2 2 0 012 2v12a2 2 0 01-2 2h-4"
                    ></path>
                </svg>

                Logout
            </button>
        </div>
    </div>
</aside>

<?php
$logout_modal_action = app_path('supplier/logout.php');
$logout_modal_account_label = 'Supplier account';
require_once __DIR__ . '/logout_modal.php';
?>

<script>
function openSupplierSidebar() {
    const sidebar =
        document.getElementById('supplierSidebar');

    const overlay =
        document.getElementById('supplierSidebarOverlay');

    sidebar.classList.remove('-translate-x-full');
    overlay.classList.remove('hidden');
}

function closeSupplierSidebar() {
    const sidebar =
        document.getElementById('supplierSidebar');

    const overlay =
        document.getElementById('supplierSidebarOverlay');

    if (window.innerWidth < 1024) {
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
    }
}

window.addEventListener('resize', function() {
    const overlay =
        document.getElementById('supplierSidebarOverlay');

    if (window.innerWidth >= 1024) {
        overlay.classList.add('hidden');
    }
});
</script>
