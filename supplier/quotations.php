<?php
session_start();
if (!isset($_SESSION['supplier_id']) || $_SESSION['role'] !== 'supplier') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$supplier_id = $_SESSION['supplier_id'];

$quotations = $pdo->prepare("
    SELECT q.*, r.rfq_number
    FROM quotations q
    JOIN rfq r ON r.rfq_id = q.quotation_rfq_id
    WHERE q.quotation_supplier_id = ?
    ORDER BY q.quotation_submitted_at DESC
");
$quotations->execute([$supplier_id]);
$quotations = $quotations->fetchAll(PDO::FETCH_ASSOC);

foreach ($quotations as &$q) {
    $items = $pdo->prepare("SELECT * FROM quotation_items WHERE quotation_item_quotation_id = ?");
    $items->execute([$q['quotation_id']]);
    $q['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
    $q['total'] = array_sum(array_map(fn($i) => $i['quotation_item_quantity'] * $i['quotation_item_unit_price'], $q['items']));
}
unset($q);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Quotations - Supplier Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">

    <?php include '../includes/supplier_navbar.php'; ?>

    <div class="max-w-4xl mx-auto px-6 py-8">

        <div class="mb-6">
            <h1 class="text-2xl font-black text-gray-800">📝 My Quotations</h1>
            <p class="text-gray-500 text-sm mt-1">Track the status of all quotes you've submitted</p>
        </div>

        <?php if (count($quotations) === 0): ?>
        <div class="bg-white rounded-2xl shadow-sm p-16 text-center">
            <div class="text-5xl mb-4">📝</div>
            <p class="text-gray-400">You haven't submitted any quotations yet.</p>
        </div>
        <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($quotations as $q): 
                $status_config = [
                    'submitted' => ['bg' => 'bg-yellow-50', 'border' => 'border-yellow-200', 'text' => 'text-yellow-700', 'icon' => '⏳', 'label' => 'Under Review'],
                    'accepted'  => ['bg' => 'bg-green-50',  'border' => 'border-green-200',  'text' => 'text-green-700',  'icon' => '✅', 'label' => 'Accepted'],
                    'rejected'  => ['bg' => 'bg-gray-50',   'border' => 'border-gray-200',   'text' => 'text-gray-500',   'icon' => '❌', 'label' => 'Not Selected'],
                ];
                $sc = $status_config[$q['quotation_status']];
            ?>
            <div class="bg-white rounded-2xl shadow-sm p-6 <?= $sc['border'] ?> border-2">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <p class="font-bold text-gray-800"><?= htmlspecialchars($q['rfq_number']) ?></p>
                        <p class="text-xs text-gray-400">Submitted <?= date('d M Y, h:i A', strtotime($q['quotation_submitted_at'])) ?></p>
                    </div>
                    <span class="<?= $sc['bg'] ?> <?= $sc['text'] ?> text-xs px-3 py-1.5 rounded-full font-semibold">
                        <?= $sc['icon'] ?> <?= $sc['label'] ?>
                    </span>
                </div>
                <div class="flex justify-between items-center pt-3 border-t border-gray-50">
                    <span class="text-sm text-gray-500">Total Quote Amount</span>
                    <span class="font-black text-blue-600">RM <?= number_format($q['total'], 2) ?></span>
                </div>
                <?php if ($q['quotation_status'] === 'accepted'): ?>
                <div class="bg-green-50 rounded-xl p-3 mt-3">
                    <p class="text-xs text-green-700">🎉 Congratulations! Your quote was selected. A Purchase Order has been generated. Check your Purchase Orders tab.</p>
                </div>
                <?php elseif ($q['quotation_status'] === 'rejected'): ?>
                <div class="bg-gray-50 rounded-xl p-3 mt-3">
                    <p class="text-xs text-gray-500">Thank you for your quote. MangaVault has selected another supplier for this order this time.</p>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>

</body>
</html>