<?php
require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';

$user_id = $_SESSION['user_id'];

$returns = $pdo->prepare("
    SELECT rr.*, 
    p.product_title, p.product_cover_image,
    o.order_id, oi.order_item_quantity, oi.order_item_price
    FROM return_requests rr
    JOIN order_items oi ON rr.return_item_id = oi.order_item_id
    JOIN products p ON oi.order_item_product_id = p.product_id
    JOIN orders o ON oi.order_item_order_id = o.order_id
    WHERE o.order_user_id = ?
    ORDER BY rr.return_created_at DESC
");
$returns->execute([$user_id]);
$returns = $returns->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Returns - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html { scroll-behavior: smooth; }
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">
        <p class="text-sm text-gray-400 mb-6">
            <a href="../index.php" class="hover:text-red-600 transition-colors">Home</a>
            <span class="mx-2">›</span>
            <a href="dashboard.php" class="hover:text-red-600 transition-colors">My Account</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">My Returns</span>
        </p>

        <div class="flex gap-8 items-start">
            <?php include '../includes/customer_sidebar.php'; ?>

            <div class="flex-1 min-w-0">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-xl font-black text-gray-800">My Returns</h1>
                    <a href="orders.php"
                       class="text-xs bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-xl font-semibold transition-colors">
                        + New Return Request
                    </a>
                </div>

                <?php if (count($returns) === 0): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                        <div class="text-5xl mb-4">↩️</div>
                        <p class="text-gray-500 font-medium mb-1">No return requests</p>
                        <p class="text-gray-400 text-sm mb-6">You haven't submitted any return requests yet.</p>
                        <a href="orders.php" class="bg-red-600 hover:bg-red-700 text-white font-semibold px-6 py-2.5 rounded-xl text-sm transition-colors inline-block">
                            View Orders
                        </a>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($returns as $ret): ?>
                        <div class="bg-white rounded-2xl shadow-sm overflow-hidden hover:shadow-md transition-shadow duration-200">
                            <!-- Header -->
                            <div class="px-6 py-4 border-b border-gray-50 flex justify-between items-center flex-wrap gap-3">
                                <div>
                                    <p class="font-bold text-sm text-gray-800">Return #<?= str_pad($ret['return_id'], 4, '0', STR_PAD_LEFT) ?></p>
                                    <p class="text-xs text-gray-400">Order #<?= str_pad($ret['order_id'], 4, '0', STR_PAD_LEFT) ?> · <?= date('d M Y', strtotime($ret['return_created_at'])) ?></p>
                                </div>
                                <?php
                                $status_colors = [
                                    'pending' => 'bg-yellow-100 text-yellow-700',
                                    'approved' => 'bg-green-100 text-green-700',
                                    'rejected' => 'bg-red-100 text-red-700',
                                ];
                                $color = $status_colors[$ret['return_status']] ?? 'bg-gray-100 text-gray-700';
                                $status_icons = [
                                    'pending' => '⏳',
                                    'approved' => '✅',
                                    'rejected' => '❌',
                                ];
                                $icon = $status_icons[$ret['return_status']] ?? '🔄';
                                ?>
                                <span class="<?= $color ?> text-xs px-3 py-1 rounded-full font-semibold capitalize">
                                    <?= $icon ?> <?= $ret['return_status'] ?>
                                </span>
                            </div>

                            <!-- Item -->
                            <div class="px-6 py-4 flex items-center gap-4">
                                <?php if (!empty($ret['product_cover_image'])): ?>
                                    <img src="../assets/images/<?= htmlspecialchars($ret['product_cover_image']) ?>"
                                         class="w-14 h-20 object-cover rounded-xl flex-shrink-0">
                                <?php else: ?>
                                    <div class="w-14 h-20 bg-gray-100 rounded-xl flex-shrink-0 flex items-center justify-center text-gray-400 font-bold text-xs">N/A</div>
                                <?php endif; ?>
                                <div class="flex-1">
                                    <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($ret['product_title']) ?></p>
                                    <p class="text-xs text-gray-400 mt-0.5">Qty: <?= $ret['order_item_quantity'] ?> · RM <?= number_format($ret['order_item_price'], 2) ?> each</p>
                                    <div class="mt-2">
                                        <p class="text-xs font-medium text-gray-500">Reason:</p>
                                        <p class="text-xs text-gray-600"><?= htmlspecialchars($ret['return_reason']) ?></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Admin Note (if any) -->
                            <?php if (!empty($ret['return_admin_note'])): ?>
                            <div class="px-6 py-3 border-t border-gray-50 <?= $ret['return_status'] === 'approved' ? 'bg-green-50' : 'bg-red-50' ?>">
                                <p class="text-xs font-semibold <?= $ret['return_status'] === 'approved' ? 'text-green-700' : 'text-red-700' ?> mb-0.5">
                                    Admin Response:
                                </p>
                                <p class="text-xs <?= $ret['return_status'] === 'approved' ? 'text-green-600' : 'text-red-600' ?>">
                                    <?= htmlspecialchars($ret['return_admin_note']) ?>
                                </p>
                            </div>
                            <?php endif; ?>

                            <!-- Status Timeline -->
                            <div class="px-6 py-4 bg-gray-50 border-t border-gray-100">
                                <div class="flex items-center gap-2">
                                    <div class="flex items-center gap-1.5">
                                        <div class="w-6 h-6 bg-green-500 rounded-full flex items-center justify-center text-white text-xs">✓</div>
                                        <span class="text-xs text-gray-600">Submitted</span>
                                    </div>
                                    <div class="flex-1 h-0.5 <?= in_array($ret['return_status'], ['approved','rejected']) ? 'bg-green-400' : 'bg-gray-200' ?>"></div>
                                    <div class="flex items-center gap-1.5">
                                        <div class="w-6 h-6 <?= $ret['return_status'] === 'pending' ? 'bg-yellow-400' : ($ret['return_status'] === 'approved' ? 'bg-green-500' : 'bg-red-500') ?> rounded-full flex items-center justify-center text-white text-xs">
                                            <?= $ret['return_status'] === 'pending' ? '⏳' : ($ret['return_status'] === 'approved' ? '✓' : '✗') ?>
                                        </div>
                                        <span class="text-xs text-gray-600 capitalize"><?= $ret['return_status'] ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>