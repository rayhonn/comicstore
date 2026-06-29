<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}
require_once '../includes/db.php';

$supplier_id = $_GET['id'] ?? null;
if (!$supplier_id) { header('Location: suppliers.php'); exit; }

$supplier = $pdo->prepare("SELECT * FROM suppliers WHERE supplier_id = ?");
$supplier->execute([$supplier_id]);
$supplier = $supplier->fetch(PDO::FETCH_ASSOC);
if (!$supplier) { header('Location: suppliers.php'); exit; }

$stats = $pdo->prepare("
    SELECT
    COUNT(*) as total_pos,
    SUM(CASE WHEN po_status = 'completed' THEN po_total_amount ELSE 0 END) as total_spend,
    SUM(CASE WHEN po_status = 'completed' THEN 1 ELSE 0 END) as completed_pos,
    AVG(po_rating) as avg_rating,
    COUNT(po_rating) as rating_count
    FROM purchase_orders
    WHERE po_supplier_id = ?
");
$stats->execute([$supplier_id]);
$stats = $stats->fetch(PDO::FETCH_ASSOC);

$lead_time = $pdo->prepare("
    SELECT AVG(DATEDIFF(gr.gr_received_at, po.po_created_at)) as avg_lead_time
    FROM purchase_orders po
    JOIN goods_received gr ON gr.gr_po_id = po.po_id AND gr.gr_status = 'completed'
    WHERE po.po_supplier_id = ?
");
$lead_time->execute([$supplier_id]);
$avg_lead_time = $lead_time->fetchColumn();

$disputes = $pdo->prepare("
    SELECT COUNT(*) FROM supplier_returns sr
    JOIN purchase_orders po ON po.po_id = sr.return_po_id
    WHERE po.po_supplier_id = ? AND sr.return_supplier_response = 'disputed'
");
$disputes->execute([$supplier_id]);
$dispute_count = $disputes->fetchColumn();

$po_history = $pdo->prepare("
    SELECT po.*, COUNT(pi.po_item_id) as item_count
    FROM purchase_orders po
    LEFT JOIN po_items pi ON pi.po_item_po_id = po.po_id
    WHERE po.po_supplier_id = ?
    GROUP BY po.po_id
    ORDER BY po.po_created_at DESC
");
$po_history->execute([$supplier_id]);
$po_history = $po_history->fetchAll(PDO::FETCH_ASSOC);

$completion_rate = $stats['total_pos'] > 0 ? ($stats['completed_pos'] / $stats['total_pos']) * 100 : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($supplier['supplier_name']) ?> - Performance History</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-5xl mx-auto px-6 py-8">

        <p class="text-sm text-gray-400 mb-6">
            <a href="suppliers.php" class="hover:text-red-600 transition-colors">Suppliers</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600"><?= htmlspecialchars($supplier['supplier_name']) ?></span>
        </p>

        <div class="mb-8">
            <h1 class="text-2xl font-black text-gray-800">🏭 <?= htmlspecialchars($supplier['supplier_name']) ?></h1>
            <p class="text-gray-500 text-sm mt-1">Supplier performance history</p>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <p class="text-xs text-gray-400 uppercase font-semibold mb-1">Total POs</p>
                <p class="text-2xl font-black text-gray-800"><?= $stats['total_pos'] ?></p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <p class="text-xs text-gray-400 uppercase font-semibold mb-1">Total Spend</p>
                <p class="text-2xl font-black text-red-600">RM <?= number_format($stats['total_spend'] ?? 0, 0) ?></p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <p class="text-xs text-gray-400 uppercase font-semibold mb-1">Avg Rating</p>
                <p class="text-2xl font-black text-yellow-500"><?= $stats['avg_rating'] ? round($stats['avg_rating'], 1) . '★' : '—' ?></p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <p class="text-xs text-gray-400 uppercase font-semibold mb-1">Avg Lead Time</p>
                <p class="text-2xl font-black text-blue-600"><?= $avg_lead_time !== null ? round($avg_lead_time, 1) . 'd' : '—' ?></p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <p class="text-xs text-gray-400 uppercase font-semibold mb-1">Disputed Returns</p>
                <p class="text-2xl font-black <?= $dispute_count > 0 ? 'text-orange-500' : 'text-gray-800' ?>"><?= $dispute_count ?></p>
            </div>
        </div>

        <?php if ($completion_rate !== null): ?>
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-8">
            <div class="flex items-center justify-between mb-2">
                <p class="text-sm font-semibold text-gray-700">Completion Rate</p>
                <p class="text-sm font-bold text-gray-800"><?= round($completion_rate, 1) ?>%</p>
            </div>
            <div class="w-full bg-gray-100 rounded-full h-2">
                <div class="bg-green-500 h-2 rounded-full" style="width: <?= $completion_rate ?>%"></div>
            </div>
            <p class="text-xs text-gray-400 mt-2"><?= $stats['completed_pos'] ?> of <?= $stats['total_pos'] ?> purchase orders fully completed</p>
        </div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-50">
                <h3 class="font-bold text-gray-800">Purchase Order History</h3>
            </div>
            <?php if (count($po_history) === 0): ?>
            <div class="text-center py-12">
                <p class="text-gray-400 text-sm">No purchase orders yet with this supplier.</p>
            </div>
            <?php else: ?>
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">PO Number</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Items</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Total</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Rating</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $status_colors = [
                        'draft'     => 'bg-gray-100 text-gray-500',
                        'sent'      => 'bg-yellow-100 text-yellow-700',
                        'confirmed' => 'bg-blue-100 text-blue-700',
                        'completed' => 'bg-green-100 text-green-700',
                        'cancelled' => 'bg-red-100 text-red-700',
                    ];
                    foreach ($po_history as $po):
                    ?>
                    <tr class="border-t border-gray-50">
                        <td class="px-5 py-4">
                            <a href="po_detail.php?id=<?= $po['po_id'] ?>" class="text-sm font-semibold text-blue-600 hover:underline"><?= htmlspecialchars($po['po_number']) ?></a>
                        </td>
                        <td class="px-5 py-4 text-center text-sm text-gray-600"><?= $po['item_count'] ?></td>
                        <td class="px-5 py-4 text-right text-sm font-bold text-gray-800">RM <?= number_format($po['po_total_amount'], 2) ?></td>
                        <td class="px-5 py-4 text-center">
                            <span class="<?= $status_colors[$po['po_status']] ?> text-xs px-3 py-1 rounded-full font-semibold capitalize"><?= $po['po_status'] ?></span>
                        </td>
                        <td class="px-5 py-4 text-center text-sm">
                            <?= $po['po_rating'] ? str_repeat('★', $po['po_rating']) : '—' ?>
                        </td>
                        <td class="px-5 py-4 text-xs text-gray-400"><?= date('d M Y', strtotime($po['po_created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>