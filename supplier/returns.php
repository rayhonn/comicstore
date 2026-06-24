<?php
session_start();
if (!isset($_SESSION['supplier_id']) || $_SESSION['role'] !== 'supplier') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

$supplier_id = $_SESSION['supplier_id'];

// Handle response submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_response'])) {
    $return_id = $_POST['return_id'];
    $response = $_POST['response'];
    $comment = trim($_POST['comment'] ?? '');

    // Verify this return belongs to this supplier
    $check = $pdo->prepare("
        SELECT sr.return_id FROM supplier_returns sr
        JOIN purchase_orders po ON po.po_id = sr.return_po_id
        WHERE sr.return_id = ? AND po.po_supplier_id = ?
    ");
    $check->execute([$return_id, $supplier_id]);
    if ($check->fetch()) {
        $pdo->prepare("UPDATE supplier_returns SET return_supplier_response = ?, return_supplier_comment = ?, return_responded_at = NOW() WHERE return_id = ?")
            ->execute([$response, $comment, $return_id]);
        $_SESSION['flash_success'] = 'Your response has been submitted.';
    }
    header('Location: returns.php');
    exit;
}

$returns = $pdo->prepare("
    SELECT sr.*, po.po_number
    FROM supplier_returns sr
    JOIN purchase_orders po ON po.po_id = sr.return_po_id
    WHERE po.po_supplier_id = ?
    ORDER BY sr.return_created_at DESC
");
$returns->execute([$supplier_id]);
$returns = $returns->fetchAll(PDO::FETCH_ASSOC);

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

$success = '';
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Returns - Supplier Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">

    <?php include '../includes/supplier_navbar.php'; ?>

    <div class="max-w-4xl mx-auto px-6 py-8">

        <div class="mb-6">
            <h1 class="text-2xl font-black text-gray-800">↩️ Returns & Quality Issues</h1>
            <p class="text-gray-500 text-sm mt-1">Items returned due to damage or quality issues — please respond</p>
        </div>

        <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-6">
            ✅ <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>

        <?php if (count($returns) === 0): ?>
        <div class="bg-white rounded-2xl shadow-sm p-16 text-center">
            <div class="text-5xl mb-4">✅</div>
            <p class="text-gray-400">No quality issues reported. Great job!</p>
        </div>
        <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($returns as $ret): 
                $response_config = [
                    'pending'   => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-700', 'label' => '⏳ Awaiting Your Response'],
                    'accepted'  => ['bg' => 'bg-blue-100',   'text' => 'text-blue-700',   'label' => '✓ Acknowledged'],
                    'disputed'  => ['bg' => 'bg-orange-100', 'text' => 'text-orange-700', 'label' => '⚠️ Disputed'],
                ];
                $rc = $response_config[$ret['return_supplier_response']];
            ?>
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <p class="font-bold text-gray-800"><?= htmlspecialchars($ret['return_number']) ?></p>
                        <p class="text-xs text-gray-400"><?= htmlspecialchars($ret['po_number']) ?> · <?= date('d M Y', strtotime($ret['return_created_at'])) ?></p>
                    </div>
                    <span class="<?= $rc['bg'] ?> <?= $rc['text'] ?> text-xs px-3 py-1.5 rounded-full font-semibold">
                        <?= $rc['label'] ?>
                    </span>
                </div>

                <div class="bg-red-50 rounded-xl p-4 mb-4">
                    <?php foreach ($ret['items'] as $item): ?>
                    <div class="flex items-center justify-between py-1.5 border-b border-red-100 last:border-0">
                        <div>
                            <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($item['product_title']) ?></p>
                            <?php if ($item['return_item_reason']): ?>
                            <p class="text-xs text-red-500">MangaVault reported: <?= htmlspecialchars($item['return_item_reason']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="text-sm font-bold text-red-600"><?= $item['return_item_quantity'] ?> units</p>
                            <p class="text-xs text-gray-400">RM <?= number_format($item['return_item_quantity'] * $item['return_item_unit_price'], 2) ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <p class="text-sm text-gray-600 mb-4">This amount (<strong class="text-red-600">RM <?= number_format($ret['total_value'], 2) ?></strong>) has been deducted from your payment for this order.</p>

                <?php if ($ret['return_supplier_response'] === 'pending'): ?>
                <form method="POST">
                    <input type="hidden" name="submit_response" value="1">
                    <input type="hidden" name="return_id" value="<?= $ret['return_id'] ?>">
                    <textarea name="comment" rows="2" placeholder="Optional comment (e.g. 'We will investigate our packaging process' or 'We dispute this — items were quality-checked before shipping')"
                              class="w-full px-4 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-blue-400 transition-colors resize-none mb-3"></textarea>
                    <div class="flex gap-3">
                        <button type="submit" name="response" value="accepted"
                                class="flex-1 bg-blue-50 hover:bg-blue-100 text-blue-700 font-semibold py-2.5 rounded-xl text-sm transition-colors">
                            ✓ Acknowledge Issue
                        </button>
                        <button type="submit" name="response" value="disputed"
                                class="flex-1 bg-orange-50 hover:bg-orange-100 text-orange-700 font-semibold py-2.5 rounded-xl text-sm transition-colors">
                            ⚠️ Dispute This
                        </button>
                    </div>
                </form>
                <?php else: ?>
                <div class="bg-gray-50 rounded-xl p-3">
                    <p class="text-xs font-semibold text-gray-500 mb-1">Your Response:</p>
                    <p class="text-sm text-gray-700"><?= htmlspecialchars($ret['return_supplier_comment'] ?: 'No comment provided.') ?></p>
                    <p class="text-xs text-gray-400 mt-1">Responded on <?= date('d M Y, h:i A', strtotime($ret['return_responded_at'])) ?></p>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>

</body>
</html>