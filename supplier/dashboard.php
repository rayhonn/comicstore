<?php
session_start();
if (!isset($_SESSION['supplier_id']) || $_SESSION['role'] !== 'supplier') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$supplier_id = $_SESSION['supplier_id'];

// Pending RFQs (not yet quoted by this supplier)
$pending_rfqs = $pdo->prepare("
    SELECT r.* FROM rfq r
    JOIN rfq_suppliers rs ON rs.rfq_supplier_rfq_id = r.rfq_id
    WHERE rs.rfq_supplier_supplier_id = ?
    AND r.rfq_id NOT IN (
        SELECT quotation_rfq_id FROM quotations WHERE quotation_supplier_id = ?
    )
    ORDER BY r.rfq_created_at DESC
");
$pending_rfqs->execute([$supplier_id, $supplier_id]);
$pending_rfqs = $pending_rfqs->fetchAll(PDO::FETCH_ASSOC);

// Active POs
$active_pos = $pdo->prepare("
    SELECT COUNT(*) FROM purchase_orders 
    WHERE po_supplier_id = ? AND po_status IN ('sent','confirmed')
");
$active_pos->execute([$supplier_id]);
$active_pos = $active_pos->fetchColumn();

// Total quotations submitted
$total_quotes = $pdo->prepare("SELECT COUNT(*) FROM quotations WHERE quotation_supplier_id = ?");
$total_quotes->execute([$supplier_id]);
$total_quotes = $total_quotes->fetchColumn();

// Unpaid invoices
$unpaid = $pdo->prepare("
    SELECT COUNT(*), COALESCE(SUM(invoice_amount), 0) FROM supplier_invoices 
    WHERE invoice_supplier_id = ? AND invoice_status = 'unpaid'
");
$unpaid->execute([$supplier_id]);
[$unpaid_count, $unpaid_amount] = $unpaid->fetch(PDO::FETCH_NUM);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Supplier Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">

    <?php include '../includes/supplier_navbar.php'; ?>

    <div class="max-w-6xl mx-auto px-6 py-8">

        <div class="mb-8">
            <h1 class="text-2xl font-black text-gray-800">Welcome back, <?= htmlspecialchars($_SESSION['supplier_name']) ?> 👋</h1>
            <p class="text-gray-500 text-sm mt-1">Here's what's happening with your MangaVault partnership</p>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-white rounded-2xl shadow-sm p-5 border-l-4 border-yellow-400">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Pending RFQs</p>
                <p class="text-2xl font-black text-gray-800"><?= count($pending_rfqs) ?></p>
                <p class="text-xs text-gray-400 mt-1">Awaiting your quote</p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5 border-l-4 border-blue-400">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Active Orders</p>
                <p class="text-2xl font-black text-gray-800"><?= $active_pos ?></p>
                <p class="text-xs text-gray-400 mt-1">Purchase orders</p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5 border-l-4 border-green-400">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Quotes Submitted</p>
                <p class="text-2xl font-black text-gray-800"><?= $total_quotes ?></p>
                <p class="text-xs text-gray-400 mt-1">All time</p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5 border-l-4 border-red-400">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Unpaid Invoices</p>
                <p class="text-2xl font-black text-gray-800">RM <?= number_format($unpaid_amount, 2) ?></p>
                <p class="text-xs text-gray-400 mt-1"><?= $unpaid_count ?> invoice(s)</p>
            </div>
        </div>

        <!-- Pending RFQs -->
        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-50 flex justify-between items-center">
                <h3 class="font-bold text-gray-800">📋 RFQs Awaiting Your Quote</h3>
                <a href="rfq.php" class="text-xs text-blue-600 hover:underline font-semibold">View All →</a>
            </div>
            <?php if (count($pending_rfqs) === 0): ?>
            <div class="text-center py-10">
                <div class="text-4xl mb-3">✅</div>
                <p class="text-gray-400 text-sm">No pending RFQs. You're all caught up!</p>
            </div>
            <?php else: ?>
            <div class="divide-y divide-gray-50">
                <?php foreach (array_slice($pending_rfqs, 0, 5) as $rfq): ?>
                <div class="px-6 py-4 flex items-center justify-between hover:bg-gray-50 transition-colors">
                    <div>
                        <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($rfq['rfq_number']) ?></p>
                        <p class="text-xs text-gray-400">Received <?= date('d M Y', strtotime($rfq['rfq_created_at'])) ?></p>
                    </div>
                    <a href="rfq_detail.php?id=<?= $rfq['rfq_id'] ?>"
                       class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold px-4 py-2 rounded-lg transition-colors">
                        Submit Quote
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>