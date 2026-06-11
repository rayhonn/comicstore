<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];
$order_id = $_GET['order_id'] ?? null;

if (!$order_id) {
    header('Location: orders.php');
    exit;
}

// Get order - must belong to this user
$order = $pdo->prepare("
    SELECT o.*, u.user_first_name, u.user_last_name, u.user_gmail, u.user_phone,
    a.address_recipient_name, a.address_taman, a.address_street, a.address_city,
    a.address_state, a.address_postal_code, a.address_country, a.address_phone
    FROM orders o
    JOIN users u ON o.order_user_id = u.user_id
    LEFT JOIN addresses a ON o.order_address_id = a.address_id
    WHERE o.order_id = ? AND o.order_user_id = ?
");
$order->execute([$order_id, $user_id]);
$order = $order->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: orders.php');
    exit;
}

// Get order items
$items = $pdo->prepare("
    SELECT oi.*, p.product_title, p.product_cover_image, p.product_author, p.product_type
    FROM order_items oi
    JOIN products p ON oi.order_item_product_id = p.product_id
    WHERE oi.order_item_order_id = ?
");
$items->execute([$order_id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

$subtotal = $order['order_total_amount'] - ($order['order_shipping_fee'] ?? 0);
$order_num = '#' . str_pad($order_id, 4, '0', STR_PAD_LEFT);

$status_colors = [
    'pending'    => ['bg' => '#fef9c3', 'text' => '#854d0e', 'dot' => '#ca8a04'],
    'processing' => ['bg' => '#dbeafe', 'text' => '#1e40af', 'dot' => '#3b82f6'],
    'shipped'    => ['bg' => '#f3e8ff', 'text' => '#6b21a8', 'dot' => '#9333ea'],
    'delivered'  => ['bg' => '#dcfce7', 'text' => '#166534', 'dot' => '#16a34a'],
    'cancelled'  => ['bg' => '#fee2e2', 'text' => '#991b1b', 'dot' => '#ef4444'],
];
$sc = $status_colors[$order['order_status']] ?? $status_colors['pending'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?= $order_num ?> - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html { scroll-behavior: smooth; }
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }

        @media print {
            body { opacity: 1 !important; background: white !important; }
            .no-print { display: none !important; }
            .invoice-card { box-shadow: none !important; border: 1px solid #e5e7eb !important; }
            @page { margin: 15mm; }
        }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-4xl mx-auto px-6 py-8">

        <!-- Breadcrumb + Actions -->
        <div class="flex justify-between items-center mb-6 no-print">
            <p class="text-sm text-gray-400">
                <a href="../index.php" class="hover:text-red-600 transition-colors">Home</a>
                <span class="mx-2">›</span>
                <a href="dashboard.php" class="hover:text-red-600 transition-colors">My Account</a>
                <span class="mx-2">›</span>
                <a href="orders.php" class="hover:text-red-600 transition-colors">Orders</a>
                <span class="mx-2">›</span>
                <span class="text-gray-600">Invoice <?= $order_num ?></span>
            </p>
            <div class="flex gap-3">
                <button onclick="window.print()"
                        class="flex items-center gap-2 bg-white hover:bg-gray-50 text-gray-700 font-semibold px-4 py-2 rounded-xl text-sm shadow-sm border border-gray-200 transition-colors">
                    🖨️ Print
                </button>
                <button onclick="downloadInvoice()"
                        class="flex items-center gap-2 bg-red-600 hover:bg-red-700 text-white font-semibold px-4 py-2 rounded-xl text-sm transition-colors">
                    ⬇️ Download PDF
                </button>
            </div>
        </div>

        <!-- Invoice Card -->
        <div class="invoice-card bg-white rounded-2xl shadow-sm overflow-hidden" id="invoiceContent">

            <!-- Header -->
            <div class="bg-gradient-to-r from-[#1e2d4a] to-[#2c3e6b] px-8 py-8 text-white relative overflow-hidden">
                <div class="absolute top-0 right-0 w-48 h-48 bg-white/5 rounded-full -translate-y-16 translate-x-16"></div>
                <div class="absolute bottom-0 left-0 w-32 h-32 bg-white/5 rounded-full translate-y-12 -translate-x-12"></div>
                <div class="relative z-10 flex justify-between items-start">
                    <div>
                        <h1 class="text-3xl font-black mb-1">Manga<span class="text-red-400">Vault</span></h1>
                        <p class="text-white/60 text-sm">Your One-Stop Manga Store</p>
                        <p class="text-white/60 text-xs mt-1">mangavault@gmail.com</p>
                    </div>
                    <div class="text-right">
                        <p class="text-white/60 text-xs uppercase tracking-widest mb-1">Invoice</p>
                        <p class="text-3xl font-black"><?= $order_num ?></p>
                        <p class="text-white/60 text-sm mt-1"><?= date('d F Y', strtotime($order['order_created_at'])) ?></p>
                    </div>
                </div>
            </div>

            <div class="px-8 py-8">

                <!-- Status + Billing/Shipping -->
                <div class="flex flex-wrap justify-between gap-6 mb-8">
                    <div>
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Bill To</p>
                        <p class="font-bold text-gray-800"><?= htmlspecialchars($order['user_first_name'] . ' ' . $order['user_last_name']) ?></p>
                        <p class="text-sm text-gray-500"><?= htmlspecialchars($order['user_gmail']) ?></p>
                        <?php if ($order['user_phone']): ?>
                        <p class="text-sm text-gray-500"><?= htmlspecialchars($order['user_phone']) ?></p>
                        <?php endif; ?>
                    </div>

                    <?php if ($order['order_has_physical'] && $order['address_recipient_name']): ?>
                    <div>
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Ship To</p>
                        <p class="font-bold text-gray-800"><?= htmlspecialchars($order['address_recipient_name']) ?></p>
                        <p class="text-sm text-gray-500"><?= htmlspecialchars($order['address_street']) ?></p>
                        <?php if (!empty($order['address_taman'])): ?>
                        <p class="text-sm text-gray-500"><?= htmlspecialchars($order['address_taman']) ?></p>
                        <?php endif; ?>
                        <p class="text-sm text-gray-500"><?= htmlspecialchars($order['address_city']) ?>, <?= htmlspecialchars($order['address_state'] ?? '') ?> <?= htmlspecialchars($order['address_postal_code']) ?></p>
                        <p class="text-sm text-gray-500"><?= htmlspecialchars($order['address_country']) ?></p>
                        <p class="text-sm text-gray-500">Tel: <?= htmlspecialchars($order['address_phone']) ?></p>
                    </div>
                    <?php endif; ?>

                    <div class="text-right">
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Order Status</p>
                        <span style="background: <?= $sc['bg'] ?>; color: <?= $sc['text'] ?>;"
                              class="px-3 py-1.5 rounded-full text-xs font-bold capitalize inline-block">
                            <?= $order['order_status'] ?>
                        </span>
                        <p class="text-xs text-gray-400 mt-2">
                            <?= $order['order_has_physical'] ? ucfirst($order['order_shipping_method'] ?? 'standard') . ' Shipping' : 'Digital Only' ?>
                        </p>
                    </div>
                </div>

                <?php if (!empty($order['order_payment_method'])): ?>
                <div>
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Payment Method</p>
                    <p class="font-semibold text-gray-700">💳 <?= htmlspecialchars($order['order_payment_method']) ?></p>
                </div>
                <?php endif; ?>

                <!-- Divider -->
                <div class="border-t-2 border-gray-50 mb-6"></div>

                <!-- Items Table -->
                <div class="mb-8">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-4">Order Items</p>
                    <div class="overflow-hidden rounded-xl border border-gray-100">
                        <table class="w-full">
                            <thead>
                                <tr class="bg-gray-50">
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Item</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wide">Type</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wide">Qty</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Unit Price</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $i => $item): ?>
                                <tr class="<?= $i % 2 === 0 ? 'bg-white' : 'bg-gray-50/50' ?> border-t border-gray-50">
                                    <td class="px-4 py-4">
                                        <div class="flex items-center gap-3">
                                            <?php if (!empty($item['product_cover_image'])): ?>
                                                <img src="../assets/images/<?= htmlspecialchars($item['product_cover_image']) ?>"
                                                     class="w-10 h-14 object-cover rounded-lg flex-shrink-0">
                                            <?php else: ?>
                                                <div class="w-10 h-14 bg-gray-100 rounded-lg flex-shrink-0 flex items-center justify-center text-gray-400 text-xs font-bold">
                                                    📖
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($item['product_title']) ?></p>
                                                <?php if ($item['product_author']): ?>
                                                    <p class="text-xs text-gray-400"><?= htmlspecialchars($item['product_author']) ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 text-center">
                                        <span class="<?= $item['product_type'] === 'ebook' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700' ?> text-xs px-2 py-1 rounded-full font-semibold">
                                            <?= $item['product_type'] === 'ebook' ? '📱 E-Book' : '📦 Physical' ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-center text-sm text-gray-600"><?= $item['order_item_quantity'] ?></td>
                                    <td class="px-4 py-4 text-right text-sm text-gray-600">RM <?= number_format($item['order_item_price'], 2) ?></td>
                                    <td class="px-4 py-4 text-right text-sm font-semibold text-gray-800">RM <?= number_format($item['order_item_price'] * $item['order_item_quantity'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Totals -->
                <div class="flex justify-end mb-8">
                    <div class="w-full max-w-xs space-y-2">
                        <div class="flex justify-between text-sm text-gray-500">
                            <span>Subtotal</span>
                            <span>RM <?= number_format($subtotal + ($order['order_discount_amount'] ?? 0), 2) ?></span>
                        </div>
                        <?php if ($order['order_has_physical']): ?>
                        <div class="flex justify-between text-sm text-gray-500">
                            <span>Shipping (<?= ucfirst($order['order_shipping_method'] ?? 'standard') ?>)</span>
                            <span>RM <?= number_format($order['order_shipping_fee'] ?? 0, 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($order['order_voucher_code']) && $order['order_discount_amount'] > 0): ?>
                        <div class="flex justify-between text-sm text-green-600">
                            <span>🎟️ Voucher (<?= htmlspecialchars($order['order_voucher_code']) ?>)</span>
                            <span>-RM <?= number_format($order['order_discount_amount'], 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="border-t-2 border-gray-100 pt-3">
                            <div class="flex justify-between">
                                <span class="font-black text-gray-800 text-lg">Total Paid</span>
                                <span class="font-black text-red-600 text-lg">RM <?= number_format($order['order_total_amount'], 2) ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Divider -->
                <div class="border-t-2 border-gray-50 mb-6"></div>

                <!-- Notes -->
                <div class="bg-gray-50 rounded-xl p-4 mb-6">
                    <p class="text-xs font-semibold text-gray-500 mb-2">Notes</p>
                    <p class="text-xs text-gray-400 leading-relaxed">
                        Thank you for shopping at MangaVault! For any questions about your order, please contact us.
                        <?php if ($order['order_has_physical']): ?>
                        Physical items will be delivered within <?= $order['order_shipping_method'] === 'express' ? '1-2' : '3-5' ?> business days.
                        <?php endif; ?>
                        E-books are available for download immediately from your collection.
                    </p>
                </div>

                <!-- Footer -->
                <div class="flex justify-between items-end">
                    <div>
                        <p class="text-xs text-gray-400">Generated on <?= date('d F Y, h:i A') ?></p>
                        <p class="text-xs text-gray-300">MangaVault — All rights reserved</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-400">Order ID: <?= $order_num ?></p>
                        <p class="text-xs text-gray-400">Customer ID: #<?= str_pad($user_id, 4, '0', STR_PAD_LEFT) ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Back Button -->
        <div class="mt-6 text-center no-print">
            <a href="orders.php" class="text-sm text-gray-400 hover:text-red-600 transition-colors">
                ← Back to Orders
            </a>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
    function downloadInvoice() {
        const element = document.getElementById('invoiceContent');
        const opt = {
            margin: 10,
            filename: 'MangaVault-Invoice-<?= $order_num ?>.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
        };
        html2pdf().set(opt).from(element).save();
    }
    </script>

</body>
</html>