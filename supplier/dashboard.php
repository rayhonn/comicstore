<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_supplier();

$supplier_id = filter_var(
    $_SESSION['supplier_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($supplier_id === false || $supplier_id === null) {
    header('Location: login.php');
    exit;
}

$supplier_stmt = $pdo->prepare("
    SELECT
        supplier_id,
        supplier_name,
        supplier_status
    FROM suppliers
    WHERE supplier_id = ?
");
$supplier_stmt->execute([$supplier_id]);

$supplier = $supplier_stmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    destroy_session();

    redirect_to(
        app_path('supplier/login.php')
    );
}

$pending_rfqs_stmt = $pdo->prepare("
    SELECT
        r.rfq_id,
        r.rfq_number,
        r.rfq_status,
        r.rfq_created_at,
        COUNT(DISTINCT ri.rfq_item_id) AS item_count
    FROM rfq r
    INNER JOIN rfq_suppliers rs
        ON rs.rfq_supplier_rfq_id = r.rfq_id
    LEFT JOIN rfq_items ri
        ON ri.rfq_item_rfq_id = r.rfq_id
    WHERE rs.rfq_supplier_supplier_id = ?
    AND r.rfq_status IN ('pending', 'quoted')
    AND NOT EXISTS (
        SELECT 1
        FROM quotations q
        WHERE q.quotation_rfq_id = r.rfq_id
        AND q.quotation_supplier_id = ?
    )
    GROUP BY
        r.rfq_id,
        r.rfq_number,
        r.rfq_status,
        r.rfq_created_at
    ORDER BY r.rfq_created_at DESC
");
$pending_rfqs_stmt->execute([
    $supplier_id,
    $supplier_id,
]);

$pending_rfqs = $pending_rfqs_stmt->fetchAll(PDO::FETCH_ASSOC);

$active_pos_stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM purchase_orders
    WHERE po_supplier_id = ?
    AND po_status IN ('sent', 'confirmed')
");
$active_pos_stmt->execute([$supplier_id]);

$active_pos = (int) $active_pos_stmt->fetchColumn();

$total_quotes_stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM quotations
    WHERE quotation_supplier_id = ?
");
$total_quotes_stmt->execute([$supplier_id]);

$total_quotes = (int) $total_quotes_stmt->fetchColumn();

$unpaid_stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS unpaid_count,
        COALESCE(SUM(invoice_amount), 0) AS unpaid_amount
    FROM supplier_invoices
    WHERE invoice_supplier_id = ?
    AND invoice_status = 'unpaid'
");
$unpaid_stmt->execute([$supplier_id]);

$unpaid_data = $unpaid_stmt->fetch(PDO::FETCH_ASSOC);

$unpaid_count = (int) ($unpaid_data['unpaid_count'] ?? 0);
$unpaid_amount = (float) ($unpaid_data['unpaid_amount'] ?? 0);

$product_count_stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM supplier_products
    WHERE supplier_product_supplier_id = ?
    AND supplier_product_status = 'active'
");
$product_count_stmt->execute([$supplier_id]);

$active_product_count =
    (int) $product_count_stmt->fetchColumn();

$credits_stmt = $pdo->prepare("
    SELECT
        sr.return_credit_note_number,
        sr.return_credit_note_amount
    FROM supplier_returns sr
    INNER JOIN purchase_orders po
        ON po.po_id = sr.return_po_id
    WHERE po.po_supplier_id = ?
    AND sr.return_credit_note_number IS NOT NULL
    AND sr.return_credit_note_used_invoice_id IS NULL
    ORDER BY sr.return_id DESC
");
$credits_stmt->execute([$supplier_id]);

$credits = $credits_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_credit = array_sum(
    array_map(
        'floatval',
        array_column(
            $credits,
            'return_credit_note_amount'
        )
    )
);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Dashboard - Supplier Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-50 min-h-screen">

    <?php include '../includes/supplier_navbar.php'; ?>

    <main class="max-w-7xl mx-auto px-5 md:px-8 py-8">

        <div
            class="flex flex-col xl:flex-row xl:items-end xl:justify-between gap-5 mb-8"
        >
            <div>
                <div
                    class="inline-flex items-center gap-2 bg-blue-50 text-blue-700 text-xs font-semibold px-3 py-1.5 rounded-full mb-3"
                >
                    <span
                        class="w-2 h-2 bg-blue-500 rounded-full"
                    ></span>
                    Procurement Workspace
                </div>

                <h1
                    class="text-3xl font-black text-slate-900 tracking-tight"
                >
                    Welcome back,
                    <?= htmlspecialchars($supplier['supplier_name']) ?>
                </h1>

                <p
                    class="text-sm text-slate-500 mt-2"
                >
                    Review procurement activity, quotations, orders and account actions
                    from one workspace.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a
                    href="rfq.php"
                    class="inline-flex items-center gap-2 px-4 py-2.5 bg-white border border-slate-200 hover:border-blue-300 text-slate-700 text-sm font-semibold rounded-xl transition-colors shadow-sm"
                >
                    View RFQs
                </a>

                <a
                    href="products.php"
                    class="inline-flex items-center gap-2 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-xl transition-colors shadow-sm"
                >
                    Manage Products
                </a>
            </div>
        </div>

        <?php if ($supplier['supplier_status'] !== 'active'): ?>
        <div
            class="bg-amber-50 border border-amber-200 text-amber-800 text-sm px-4 py-3 rounded-2xl mb-6"
        >
            ⚠️ Your supplier account is currently inactive. Some procurement actions
            may be unavailable until MangaVault reactivates the account.
        </div>
        <?php endif; ?>

        <div
            class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-4 mb-8"
        >
            <a
                href="rfq.php"
                class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm hover:border-amber-300 hover:shadow-md transition-all"
            >
                <div class="flex items-center justify-between mb-4">
                    <div
                        class="w-11 h-11 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center"
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
                                d="M9 12h6M9 16h6M9 8h6M5 4h14v16H5V4z"
                            ></path>
                        </svg>
                    </div>

                    <?php if (count($pending_rfqs) > 0): ?>
                    <span
                        class="text-[10px] font-bold uppercase tracking-wide bg-amber-100 text-amber-700 px-2 py-1 rounded-full"
                    >
                        Action
                    </span>
                    <?php endif; ?>
                </div>

                <p
                    class="text-3xl font-black text-slate-900"
                >
                    <?= count($pending_rfqs) ?>
                </p>

                <p
                    class="text-xs font-bold text-slate-500 uppercase tracking-wide mt-1"
                >
                    Pending RFQs
                </p>

                <p class="text-xs text-slate-400 mt-2">
                    Awaiting quotation
                </p>
            </a>

            <a
                href="purchase_orders.php"
                class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm hover:border-blue-300 hover:shadow-md transition-all"
            >
                <div class="flex items-center justify-between mb-4">
                    <div
                        class="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center"
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
                                d="M4 7h16v13H4V7zM7 4h10v3H7V4zM8 11h8M8 15h5"
                            ></path>
                        </svg>
                    </div>
                </div>

                <p class="text-3xl font-black text-slate-900">
                    <?= $active_pos ?>
                </p>

                <p
                    class="text-xs font-bold text-slate-500 uppercase tracking-wide mt-1"
                >
                    Active Orders
                </p>

                <p class="text-xs text-slate-400 mt-2">
                    Purchase orders
                </p>
            </a>

            <a
                href="quotations.php"
                class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm hover:border-emerald-300 hover:shadow-md transition-all"
            >
                <div class="flex items-center justify-between mb-4">
                    <div
                        class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center"
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
                                d="M7 3h10l4 4v14H7V3zM17 3v5h5M10 13h8M10 17h6"
                            ></path>
                        </svg>
                    </div>
                </div>

                <p class="text-3xl font-black text-slate-900">
                    <?= $total_quotes ?>
                </p>

                <p
                    class="text-xs font-bold text-slate-500 uppercase tracking-wide mt-1"
                >
                    Quotations
                </p>

                <p class="text-xs text-slate-400 mt-2">
                    Submitted all time
                </p>
            </a>

            <a
                href="products.php"
                class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm hover:border-violet-300 hover:shadow-md transition-all"
            >
                <div class="flex items-center justify-between mb-4">
                    <div
                        class="w-11 h-11 rounded-xl bg-violet-50 text-violet-600 flex items-center justify-center"
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
                                d="M4 5.5A2.5 2.5 0 016.5 3H20v16H6.5A2.5 2.5 0 004 21.5v-16zM4 5.5v16"
                            ></path>
                        </svg>
                    </div>
                </div>

                <p class="text-3xl font-black text-slate-900">
                    <?= $active_product_count ?>
                </p>

                <p
                    class="text-xs font-bold text-slate-500 uppercase tracking-wide mt-1"
                >
                    My Products
                </p>

                <p class="text-xs text-slate-400 mt-2">
                    Active supply catalogue
                </p>
            </a>

            <a
                href="invoices.php"
                class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm hover:border-red-300 hover:shadow-md transition-all"
            >
                <div class="flex items-center justify-between mb-4">
                    <div
                        class="w-11 h-11 rounded-xl bg-red-50 text-red-600 flex items-center justify-center"
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
                                d="M6 3h12v18l-3-2-3 2-3-2-3 2V3zM9 8h6M9 12h6M9 16h4"
                            ></path>
                        </svg>
                    </div>
                </div>

                <p
                    class="text-2xl font-black text-slate-900 truncate"
                    title="RM <?= number_format($unpaid_amount, 2) ?>"
                >
                    RM <?= number_format($unpaid_amount, 2) ?>
                </p>

                <p
                    class="text-xs font-bold text-slate-500 uppercase tracking-wide mt-1"
                >
                    Unpaid Invoices
                </p>

                <p class="text-xs text-slate-400 mt-2">
                    <?= $unpaid_count ?> invoice(s)
                </p>
            </a>
        </div>

        <div
            class="grid grid-cols-1 xl:grid-cols-3 gap-6"
        >
            <section
                class="xl:col-span-2 bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden"
            >
                <div
                    class="px-5 md:px-6 py-5 border-b border-slate-100 flex items-center justify-between gap-4"
                >
                    <div>
                        <h2
                            class="font-black text-slate-800 text-lg"
                        >
                            RFQs Awaiting Your Quote
                        </h2>

                        <p
                            class="text-xs text-slate-400 mt-1"
                        >
                            Requests that still require your quotation.
                        </p>
                    </div>

                    <a
                        href="rfq.php"
                        class="text-xs text-blue-600 hover:text-blue-700 font-bold whitespace-nowrap"
                    >
                        View All →
                    </a>
                </div>

                <?php if (!$pending_rfqs): ?>
                <div class="text-center py-16 px-5">
                    <div
                        class="w-14 h-14 bg-emerald-50 rounded-2xl mx-auto flex items-center justify-center mb-4"
                    >
                        <svg
                            class="w-6 h-6 text-emerald-600"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M5 13l4 4L19 7"
                            ></path>
                        </svg>
                    </div>

                    <p class="font-bold text-slate-700">
                        You're all caught up
                    </p>

                    <p class="text-sm text-slate-400 mt-1">
                        There are no open RFQs waiting for your quotation.
                    </p>
                </div>
                <?php else: ?>

                <div class="divide-y divide-slate-100">
                    <?php foreach (
                        array_slice($pending_rfqs, 0, 5) as $rfq
                    ): ?>
                    <div
                        class="px-5 md:px-6 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 hover:bg-slate-50/70 transition-colors"
                    >
                        <div class="flex items-center gap-4 min-w-0">
                            <div
                                class="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0"
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
                                        d="M9 12h6M9 16h6M9 8h6M5 4h14v16H5V4z"
                                    ></path>
                                </svg>
                            </div>

                            <div class="min-w-0">
                                <div
                                    class="flex flex-wrap items-center gap-2"
                                >
                                    <p
                                        class="font-bold text-sm text-slate-800"
                                    >
                                        <?= htmlspecialchars(
                                            $rfq['rfq_number']
                                        ) ?>
                                    </p>

                                    <span
                                        class="text-[10px] font-bold uppercase tracking-wide bg-amber-50 text-amber-700 px-2 py-1 rounded-full"
                                    >
                                        Awaiting Quote
                                    </span>
                                </div>

                                <p class="text-xs text-slate-400 mt-1">
                                    <?= (int) $rfq['item_count'] ?>
                                    item(s)
                                    · Received
                                    <?= date(
                                        'd M Y',
                                        strtotime(
                                            $rfq['rfq_created_at']
                                        )
                                    ) ?>
                                </p>
                            </div>
                        </div>

                        <a
                            href="rfq_detail.php?id=<?= (int) $rfq['rfq_id'] ?>"
                            class="inline-flex items-center justify-center px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl transition-colors whitespace-nowrap"
                        >
                            Review & Quote
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php endif; ?>
            </section>

            <aside class="space-y-6">
                <div
                    class="bg-[#0f1b2e] rounded-3xl p-6 text-white shadow-sm"
                >
                    <p
                        class="text-[10px] uppercase tracking-[0.18em] text-blue-300 font-bold"
                    >
                        Catalogue Health
                    </p>

                    <p
                        class="text-3xl font-black mt-3"
                    >
                        <?= $active_product_count ?>
                    </p>

                    <p
                        class="text-sm text-slate-300 mt-1"
                    >
                        active products currently mapped to your supplier account.
                    </p>

                    <a
                        href="products.php"
                        class="mt-5 inline-flex items-center gap-2 bg-white/10 hover:bg-white/15 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition-colors"
                    >
                        Manage Catalogue →
                    </a>
                </div>

                <?php if ($credits): ?>
                <div
                    class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden"
                >
                    <div
                        class="px-5 py-4 border-b border-slate-100"
                    >
                        <p
                            class="text-[10px] uppercase tracking-wide text-slate-400 font-bold"
                        >
                            Available Credit
                        </p>

                        <p
                            class="text-2xl font-black text-orange-600 mt-1"
                        >
                            RM <?= number_format(
                                $total_credit,
                                2
                            ) ?>
                        </p>
                    </div>

                    <div
                        class="max-h-52 overflow-y-auto divide-y divide-slate-100"
                    >
                        <?php foreach ($credits as $credit): ?>
                        <div
                            class="px-5 py-3 flex items-center justify-between gap-3"
                        >
                            <p
                                class="text-xs font-semibold text-slate-600 truncate"
                            >
                                <?= htmlspecialchars(
                                    $credit[
                                        'return_credit_note_number'
                                    ]
                                ) ?>
                            </p>

                            <p
                                class="text-xs font-black text-orange-600 whitespace-nowrap"
                            >
                                RM <?= number_format(
                                    (float) $credit[
                                        'return_credit_note_amount'
                                    ],
                                    2
                                ) ?>
                            </p>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div
                        class="px-5 py-3 bg-orange-50"
                    >
                        <p
                            class="text-[11px] text-orange-700 leading-5"
                        >
                            Credit notes can be applied by MangaVault when
                            reviewing an unpaid supplier invoice.
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </aside>
        </div>

    </main>

</body>
</html>
