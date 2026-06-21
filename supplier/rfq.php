<?php
session_start();
if (!isset($_SESSION['supplier_id']) || $_SESSION['role'] !== 'supplier') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$supplier_id = $_SESSION['supplier_id'];

$rfqs = $pdo->prepare("
    SELECT r.*,
    (SELECT COUNT(*) FROM quotations WHERE quotation_rfq_id = r.rfq_id AND quotation_supplier_id = ?) as has_quoted
    FROM rfq r
    JOIN rfq_suppliers rs ON rs.rfq_supplier_rfq_id = r.rfq_id
    WHERE rs.rfq_supplier_supplier_id = ?
    ORDER BY r.rfq_created_at DESC
");
$rfqs->execute([$supplier_id, $supplier_id]);
$rfqs = $rfqs->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RFQs - Supplier Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">

    <?php include '../includes/supplier_navbar.php'; ?>

    <div class="max-w-4xl mx-auto px-6 py-8">

        <div class="mb-6">
            <h1 class="text-2xl font-black text-gray-800">📋 Request for Quotations</h1>
            <p class="text-gray-500 text-sm mt-1">All RFQs sent to you by MangaVault</p>
        </div>

        <?php if (count($rfqs) === 0): ?>
        <div class="bg-white rounded-2xl shadow-sm p-16 text-center">
            <div class="text-5xl mb-4">📋</div>
            <p class="text-gray-400">No RFQs received yet.</p>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">RFQ Number</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rfqs as $r): ?>
                    <tr class="border-b border-gray-50 hover:bg-gray-50 transition-colors">
                        <td class="px-5 py-4 font-semibold text-sm text-gray-800"><?= htmlspecialchars($r['rfq_number']) ?></td>
                        <td class="px-5 py-4 text-center">
                            <?php if ($r['has_quoted']): ?>
                            <span class="bg-green-100 text-green-700 text-xs px-3 py-1 rounded-full font-semibold">Quoted</span>
                            <?php else: ?>
                            <span class="bg-yellow-100 text-yellow-700 text-xs px-3 py-1 rounded-full font-semibold">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-4 text-xs text-gray-400"><?= date('d M Y', strtotime($r['rfq_created_at'])) ?></td>
                        <td class="px-5 py-4 text-center">
                            <a href="rfq_detail.php?id=<?= $r['rfq_id'] ?>" class="text-xs text-blue-600 hover:underline font-semibold">
                                <?= $r['has_quoted'] ? 'View Quote' : 'Submit Quote' ?> →
                            </a>
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