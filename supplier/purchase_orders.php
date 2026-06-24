<?php
session_start();
if (!isset($_SESSION['supplier_id']) || $_SESSION['role'] !== 'supplier') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$supplier_id = $_SESSION['supplier_id'];

$pos = $pdo->prepare("
    SELECT po.*, 
    COUNT(pi.po_item_id) as item_count
    FROM purchase_orders po
    LEFT JOIN po_items pi ON pi.po_item_po_id = po.po_id
    WHERE po.po_supplier_id = ?
    GROUP BY po.po_id
    ORDER BY po.po_created_at DESC
");
$pos->execute([$supplier_id]);
$pos = $pos->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders - Supplier Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">

    <?php include '../includes/supplier_navbar.php'; ?>

    <div class="max-w-4xl mx-auto px-6 py-8">

        <div class="mb-6">
            <h1 class="text-2xl font-black text-gray-800">📦 Purchase Orders</h1>
            <p class="text-gray-500 text-sm mt-1">Orders confirmed by MangaVault</p>
        </div>

        <?php if (count($pos) === 0): ?>
        <div class="bg-white rounded-2xl shadow-sm p-16 text-center">
            <div class="text-5xl mb-4">📦</div>
            <p class="text-gray-400">No purchase orders yet.</p>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">PO Number</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Items</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Total</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pos as $po):
                        $status_colors = [
                            'draft'     => 'bg-gray-100 text-gray-500',
                            'sent'      => 'bg-yellow-100 text-yellow-700',
                            'confirmed' => 'bg-blue-100 text-blue-700',
                            'completed' => 'bg-green-100 text-green-700',
                            'cancelled' => 'bg-red-100 text-red-700',
                        ];
                    ?>
                    <tr class="border-b border-gray-50 hover:bg-gray-50 transition-colors">
                        <td class="px-5 py-4">
                        <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($po['po_number']) ?></p>
                        <?php if ($po['po_notes']): ?>
                        <p class="text-xs text-purple-500 mt-0.5">📌 <?= htmlspecialchars($po['po_notes']) ?></p>
                        <?php endif; ?>
                    </td>
                        <td class="px-5 py-4 text-center text-sm text-gray-600"><?= $po['item_count'] ?></td>
                        <td class="px-5 py-4 text-right text-sm font-bold text-blue-600">RM <?= number_format($po['po_total_amount'], 2) ?></td>
                        <td class="px-5 py-4 text-center">
                            <span class="<?= $status_colors[$po['po_status']] ?> text-xs px-3 py-1 rounded-full font-semibold capitalize">
                                <?= $po['po_status'] ?>
                            </span>
                        </td>
                        <td class="px-5 py-4 text-xs text-gray-400"><?= date('d M Y', strtotime($po['po_created_at'])) ?></td>
                        <td class="px-5 py-4 text-center">
                            <a href="po_detail.php?id=<?= $po['po_id'] ?>" class="text-xs text-blue-600 hover:underline font-semibold">View →</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div>

</body>
</html>