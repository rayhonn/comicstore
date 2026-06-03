<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';
require_once '../includes/notifications.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_id'], $_POST['action'])) {
    $return_id = $_POST['return_id'];
    $action = $_POST['action'];
    $admin_note = trim($_POST['admin_note'] ?? '');

    if ($action === 'approved') {
        $stmt = $pdo->prepare("
            SELECT oi.order_item_product_id, oi.order_item_quantity
            FROM return_requests rr
            JOIN order_items oi ON rr.return_item_id = oi.order_item_id
            WHERE rr.return_id = ?
        ");
        $stmt->execute([$return_id]);
        $item = $stmt->fetch();
        if ($item) {
            $pdo->prepare("UPDATE product_physical SET physical_stock_quantity = physical_stock_quantity + ? WHERE physical_product_id = ?")
                ->execute([$item['order_item_quantity'], $item['order_item_product_id']]);
        }
    }

    $pdo->prepare("UPDATE return_requests SET return_status = ?, return_admin_note = ? WHERE return_id = ?")
        ->execute([$action, $admin_note, $return_id]);

    $pdo->prepare("INSERT INTO admin_logs (log_admin_id, log_action, log_target_type, log_target_id, log_details) VALUES (?, ?, 'return', ?, ?)")
        ->execute([$_SESSION['user_id'], $action . '_return', $return_id, "Return request " . $action]);

    $ret_info = $pdo->prepare("
        SELECT o.order_user_id, p.product_title
        FROM return_requests rr
        JOIN order_items oi ON rr.return_item_id = oi.order_item_id
        JOIN orders o ON oi.order_item_order_id = o.order_id
        JOIN products p ON oi.order_item_product_id = p.product_id
        WHERE rr.return_id = ?
    ");
    $ret_info->execute([$return_id]);
    $ret_data = $ret_info->fetch(PDO::FETCH_ASSOC);

    if ($ret_data) {
        $ret_num = '#' . str_pad($return_id, 4, '0', STR_PAD_LEFT);
        if ($action === 'approved') {
            sendNotification($pdo, $ret_data['order_user_id'], 'Return Approved ✅',
                "Your return request $ret_num for \"{$ret_data['product_title']}\" has been approved.", 'return');
        } else {
            sendNotification($pdo, $ret_data['order_user_id'], 'Return Rejected ❌',
                "Your return request $ret_num for \"{$ret_data['product_title']}\" has been rejected." . ($admin_note ? " Reason: $admin_note" : ''), 'return');
        }
    }

    header('Location: returns.php?success=1');
    exit;
}

$filter = $_GET['filter'] ?? 'all';
$sql = "
    SELECT rr.*, u.user_name, u.user_first_name, u.user_last_name,
    p.product_title, p.product_cover_image,
    oi.order_item_quantity, oi.order_item_price
    FROM return_requests rr
    JOIN users u ON rr.return_user_id = u.user_id
    JOIN order_items oi ON rr.return_item_id = oi.order_item_id
    JOIN products p ON oi.order_item_product_id = p.product_id
";
if ($filter !== 'all') $sql .= " WHERE rr.return_status = " . $pdo->quote($filter);
$sql .= " ORDER BY rr.return_created_at DESC";
$returns = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$counts = [
    'pending' => $pdo->query("SELECT COUNT(*) FROM return_requests WHERE return_status = 'pending'")->fetchColumn(),
    'approved' => $pdo->query("SELECT COUNT(*) FROM return_requests WHERE return_status = 'approved'")->fetchColumn(),
    'rejected' => $pdo->query("SELECT COUNT(*) FROM return_requests WHERE return_status = 'rejected'")->fetchColumn(),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return Requests - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-6xl mx-auto px-6 py-8">

        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-black text-gray-800">Return Requests</h1>
                <p class="text-sm text-gray-400 mt-0.5"><?= array_sum($counts) ?> total requests</p>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-6">
            ✅ Return request updated and customer notified.
        </div>
        <?php endif; ?>

        <!-- Filter Tabs -->
        <div class="flex gap-1 bg-white rounded-2xl shadow-sm p-1 mb-6 w-fit">
            <?php foreach (['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $key => $label): ?>
            <a href="returns.php?filter=<?= $key ?>"
               class="px-4 py-2 rounded-xl text-sm font-semibold transition-colors flex items-center gap-1.5
               <?= $filter === $key ? 'bg-[#1e2d4a] text-white' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50' ?>">
                <?= $label ?>
                <?php if ($key !== 'all' && $counts[$key] > 0): ?>
                <span class="<?= $filter === $key ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-600' ?> text-xs px-1.5 py-0.5 rounded-full">
                    <?= $counts[$key] ?>
                </span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if (count($returns) === 0): ?>
        <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
            <div class="text-5xl mb-4">↩️</div>
            <p class="text-gray-500 font-medium">No <?= $filter !== 'all' ? $filter : '' ?> return requests.</p>
        </div>
        <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($returns as $r):
                $status_colors = [
                    'pending'  => 'bg-yellow-100 text-yellow-700',
                    'approved' => 'bg-green-100 text-green-700',
                    'rejected' => 'bg-red-100 text-red-700',
                ];
                $color = $status_colors[$r['return_status']] ?? 'bg-gray-100 text-gray-600';
            ?>
            <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-50 flex justify-between items-center flex-wrap gap-3">
                    <div>
                        <p class="font-bold text-gray-800">
                            Return #<?= str_pad($r['return_id'], 4, '0', STR_PAD_LEFT) ?>
                            <span class="text-gray-400 font-normal text-sm">· Order #<?= str_pad($r['return_order_id'], 4, '0', STR_PAD_LEFT) ?></span>
                        </p>
                        <p class="text-xs text-gray-400"><?= date('d M Y, h:i A', strtotime($r['return_created_at'])) ?></p>
                    </div>
                    <span class="<?= $color ?> text-xs px-3 py-1 rounded-full font-semibold capitalize"><?= $r['return_status'] ?></span>
                </div>

                <div class="px-6 py-4 flex gap-4 items-start">
                    <?php if (!empty($r['product_cover_image'])): ?>
                    <img src="../assets/images/<?= htmlspecialchars($r['product_cover_image']) ?>"
                         class="w-14 h-20 object-cover rounded-xl flex-shrink-0">
                    <?php else: ?>
                    <div class="w-14 h-20 bg-gray-100 rounded-xl flex-shrink-0 flex items-center justify-center text-gray-400 text-xs">📖</div>
                    <?php endif; ?>
                    <div class="flex-1">
                        <p class="font-semibold text-gray-800 mb-1"><?= htmlspecialchars($r['product_title']) ?></p>
                        <div class="flex items-center gap-4 text-xs text-gray-400 mb-3">
                            <span>Customer: <span class="font-semibold text-gray-600"><?= htmlspecialchars($r['user_first_name'] . ' ' . $r['user_last_name']) ?></span></span>
                            <span>Qty: <?= $r['order_item_quantity'] ?></span>
                            <span>RM <?= number_format($r['order_item_price'], 2) ?></span>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3 mb-2">
                            <p class="text-xs font-semibold text-gray-500 mb-1">Return Reason:</p>
                            <p class="text-sm text-gray-700"><?= nl2br(htmlspecialchars($r['return_reason'])) ?></p>
                        </div>
                        <?php if ($r['return_admin_note']): ?>
                        <div class="bg-blue-50 rounded-xl p-3">
                            <p class="text-xs font-semibold text-blue-600 mb-1">Admin Note:</p>
                            <p class="text-sm text-blue-700"><?= htmlspecialchars($r['return_admin_note']) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($r['return_status'] === 'pending'): ?>
                <div class="px-6 py-4 border-t border-gray-50 bg-gray-50">
                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="return_id" value="<?= $r['return_id'] ?>">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Admin Note (optional)</label>
                            <textarea name="admin_note" rows="2" placeholder="Add a note for the customer..."
                                      class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400 resize-none transition-colors"></textarea>
                        </div>
                        <div class="flex gap-3">
                            <button name="action" value="approved"
                                    class="flex-1 bg-green-600 hover:bg-green-700 text-white font-semibold py-2.5 rounded-xl text-sm transition-colors">
                                ✓ Approve & Restore Stock
                            </button>
                            <button name="action" value="rejected"
                                    class="flex-1 bg-red-600 hover:bg-red-700 text-white font-semibold py-2.5 rounded-xl text-sm transition-colors">
                                ✗ Reject
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</body>
</html>