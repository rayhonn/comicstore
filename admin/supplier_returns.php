<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}
require_once '../includes/db.php';

$success = '';
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// Handle status update
if (isset($_GET['mark_resolved'])) {
    $pdo->prepare("UPDATE supplier_returns SET return_status = 'resolved' WHERE return_id = ?")->execute([$_GET['mark_resolved']]);
    header('Location: supplier_returns.php');
    exit;
}
if (isset($_GET['mark_acknowledged'])) {
    $pdo->prepare("UPDATE supplier_returns SET return_status = 'acknowledged' WHERE return_id = ?")->execute([$_GET['mark_acknowledged']]);
    header('Location: supplier_returns.php');
    exit;
}

$returns = $pdo->query("
    SELECT sr.*, po.po_number, s.supplier_name
    FROM supplier_returns sr
    JOIN purchase_orders po ON po.po_id = sr.return_po_id
    JOIN suppliers s ON s.supplier_id = po.po_supplier_id
    ORDER BY sr.return_created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($returns as &$r) {
    $items = $pdo->prepare("
        SELECT sri.*, p.product_title
        FROM supplier_return_items sri
        JOIN products p ON p.product_id = sri.return_item_product_id
        WHERE sri.return_item_return_id = ?
    ");
    $items->execute([$r['return_id']]);
    $r['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
    $r['total_value'] = array_sum(array_map(fn($i) => $i['return_item_quantity'] * $i['return_item_unit_price'], $r['items']));
}
unset($r);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Returns - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-6xl mx-auto px-6 py-8">

        <div class="mb-8">
            <h1 class="text-2xl font-black text-gray-800">↩️ Supplier Returns</h1>
            <p class="text-gray-500 text-sm mt-1">Damaged or rejected items returned to suppliers during goods receipt</p>
        </div>

        <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-6">
            ✅ <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>

        <?php if (count($returns) === 0): ?>
        <div class="bg-white rounded-2xl shadow-sm p-16 text-center">
            <div class="text-5xl mb-4">↩️</div>
            <p class="text-gray-400">No returns recorded. All goods received have been in good condition!</p>
        </div>
        <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($returns as $ret): 
                $status_config = [
                    'pending'      => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-700', 'label' => '⏳ Pending'],
                    'acknowledged' => ['bg' => 'bg-blue-100',   'text' => 'text-blue-700',   'label' => '📨 Acknowledged'],
                    'resolved'     => ['bg' => 'bg-green-100',  'text' => 'text-green-700',  'label' => '✅ Resolved'],
                ];
                $sc = $status_config[$ret['return_status']];
            ?>
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <p class="font-bold text-gray-800"><?= htmlspecialchars($ret['return_number']) ?></p>
                        <p class="text-xs text-gray-400">
                            <?= htmlspecialchars($ret['supplier_name']) ?> · <?= htmlspecialchars($ret['po_number']) ?> · 
                            <?= date('d M Y', strtotime($ret['return_created_at'])) ?>
                        </p>
                    </div>
                    <span class="<?= $sc['bg'] ?> <?= $sc['text'] ?> text-xs px-3 py-1.5 rounded-full font-semibold">
                        <?= $sc['label'] ?>
                    </span>
                </div>

                <div class="bg-red-50 rounded-xl p-4 mb-4">
                    <?php foreach ($ret['items'] as $item): ?>
                    <div class="flex items-center justify-between py-1.5 border-b border-red-100 last:border-0">
                        <div>
                            <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($item['product_title']) ?></p>
                            <?php if ($item['return_item_reason']): ?>
                            <p class="text-xs text-red-500">Reason: <?= htmlspecialchars($item['return_item_reason']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="text-sm font-bold text-red-600"><?= $item['return_item_quantity'] ?> units</p>
                            <p class="text-xs text-gray-400">RM <?= number_format($item['return_item_quantity'] * $item['return_item_unit_price'], 2) ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($ret['return_supplier_response'] !== 'pending'): ?>
                <div class="bg-gray-50 rounded-xl p-3 mb-3">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-xs font-semibold text-gray-500">Supplier Response:</span>
                        <?php if ($ret['return_supplier_response'] === 'accepted'): ?>
                        <span class="bg-blue-100 text-blue-700 text-xs px-2 py-0.5 rounded-full font-semibold">✓ Acknowledged</span>
                        <?php else: ?>
                        <span class="bg-orange-100 text-orange-700 text-xs px-2 py-0.5 rounded-full font-semibold">⚠️ Disputed</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-sm text-gray-700"><?= htmlspecialchars($ret['return_supplier_comment'] ?: 'No comment provided.') ?></p>
                    <p class="text-xs text-gray-400 mt-1">Responded on <?= date('d M Y, h:i A', strtotime($ret['return_responded_at'])) ?></p>
                </div>
                <?php else: ?>
                <div class="bg-yellow-50 border border-yellow-100 rounded-xl p-3 mb-3">
                    <p class="text-xs text-yellow-700">⏳ Waiting for supplier to respond to this return.</p>
                </div>
                <?php endif; ?>

                <div class="flex items-center justify-between">
                    <p class="text-sm text-gray-600">Total deducted from payment: <strong class="text-red-600">RM <?= number_format($ret['total_value'], 2) ?></strong></p>
                    <div class="flex gap-2">
                        <?php if ($ret['return_status'] === 'pending' && $ret['return_supplier_response'] !== 'pending'): ?>
                        <a href="?mark_acknowledged=<?= $ret['return_id'] ?>" 
                        class="text-xs text-blue-600 hover:underline font-semibold">Mark as Acknowledged</a>
                        <?php elseif ($ret['return_status'] === 'acknowledged'): ?>
                        <a href="?mark_resolved=<?= $ret['return_id'] ?>" 
                        class="text-xs text-green-600 hover:underline font-semibold">Mark as Resolved</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>

</body>
</html>