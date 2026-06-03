<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT ci.*, p.product_title, p.product_price, p.product_cover_image,
    p.product_type, p.product_id, pp.physical_stock_quantity
    FROM cart_items ci
    JOIN products p ON ci.cart_item_product_id = p.product_id
    LEFT JOIN product_physical pp ON p.product_id = pp.physical_product_id
    WHERE ci.cart_item_user_id = ?
");
$stmt->execute([$user_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = 0;
foreach ($items as $item) {
    $total += $item['product_price'] * $item['cart_item_quantity'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Cart - MangaVault</title>
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
            <span class="text-gray-600">My Cart</span>
        </p>

        <?php if (count($items) === 0): ?>
            <div class="bg-white rounded-2xl shadow-sm p-12 text-center max-w-md mx-auto">
                <div class="text-6xl mb-4">🛒</div>
                <p class="text-gray-500 font-medium mb-2">Your cart is empty</p>
                <p class="text-gray-400 text-sm mb-6">Add some manga to get started!</p>
                <a href="home.php" class="bg-red-600 hover:bg-red-700 text-white font-semibold px-6 py-2.5 rounded-xl text-sm transition-colors duration-200 inline-block">
                    Browse Catalog
                </a>
            </div>
        <?php else: ?>
            <div class="flex gap-6 items-start flex-col lg:flex-row">

                <!-- Cart Items -->
                <div class="flex-1 w-full">
                    <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-50">
                            <h2 class="font-bold text-gray-800">Cart Items <span class="text-gray-400 font-normal text-sm">(<?= count($items) ?>)</span></h2>
                        </div>

                        <?php foreach ($items as $item): ?>
                        <div class="px-6 py-4 border-b border-gray-50 last:border-0 flex items-center gap-4">
                            <!-- Image -->
                            <a href="product_detail.php?id=<?= $item['product_id'] ?>" class="flex-shrink-0">
                                <?php if ($item['product_cover_image']): ?>
                                    <img src="../assets/images/<?= htmlspecialchars($item['product_cover_image']) ?>"
                                         class="w-16 h-20 object-cover rounded-lg hover:opacity-80 transition-opacity">
                                <?php else: ?>
                                    <div class="w-16 h-20 bg-gray-100 rounded-lg flex items-center justify-center text-gray-400 text-xs font-bold">
                                        N/A
                                    </div>
                                <?php endif; ?>
                            </a>

                            <!-- Info -->
                            <div class="flex-1 min-w-0">
                                <a href="product_detail.php?id=<?= $item['product_id'] ?>">
                                    <p class="font-semibold text-sm text-gray-800 hover:text-red-600 transition-colors truncate"><?= htmlspecialchars($item['product_title']) ?></p>
                                </a>
                                <p class="text-xs text-gray-400 mt-0.5"><?= $item['product_type'] === 'ebook' ? '📱 E-Book' : '📦 Physical' ?></p>
                                <p class="text-red-600 font-bold text-sm mt-1">RM <?= number_format($item['product_price'], 2) ?></p>
                            </div>

                            <!-- Quantity -->
                            <div class="flex-shrink-0">
                                <?php if ($item['product_type'] === 'physical'): ?>
                                    <form method="POST" action="cart_action.php" class="flex items-center gap-2">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="cart_item_id" value="<?= $item['cart_item_id'] ?>">
                                        <div class="flex items-center border border-gray-200 rounded-lg overflow-hidden">
                                            <button type="button" onclick="updateQty(this, -1)"
                                                    class="w-8 h-8 flex items-center justify-center text-gray-500 hover:bg-gray-50 transition-colors text-lg">−</button>
                                            <input type="number" name="quantity" value="<?= $item['cart_item_quantity'] ?>"
                                                   min="1" max="<?= $item['physical_stock_quantity'] ?>"
                                                   class="w-10 h-8 text-center text-sm font-medium border-x border-gray-200 focus:outline-none"
                                                   onchange="this.form.submit()">
                                            <button type="button" onclick="updateQty(this, 1)"
                                                    class="w-8 h-8 flex items-center justify-center text-gray-500 hover:bg-gray-50 transition-colors text-lg">+</button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <span class="text-sm text-gray-500">×1</span>
                                <?php endif; ?>
                            </div>

                            <!-- Subtotal -->
                            <div class="flex-shrink-0 text-right min-w-16">
                                <p class="font-bold text-sm text-gray-800">RM <?= number_format($item['product_price'] * $item['cart_item_quantity'], 2) ?></p>
                            </div>

                            <!-- Remove -->
                            <a href="cart_action.php?action=remove&cart_item_id=<?= $item['cart_item_id'] ?>"
                               onclick="return confirm('Remove this item?')"
                               class="flex-shrink-0 w-8 h-8 flex items-center justify-center text-gray-300 hover:text-red-500 hover:bg-red-50 rounded-lg transition-all duration-200">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Continue Shopping -->
                    <a href="home.php" class="inline-flex items-center gap-2 text-sm text-gray-500 hover:text-red-600 transition-colors mt-4">
                        ← Continue Shopping
                    </a>
                </div>

                <!-- Order Summary -->
                <div class="w-full lg:w-80 flex-shrink-0">
                    <div class="bg-white rounded-2xl shadow-sm p-6 sticky top-24">
                        <h3 class="font-bold text-gray-800 mb-4">Order Summary</h3>

                        <div class="space-y-3 mb-4">
                            <?php foreach ($items as $item): ?>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500 truncate mr-2"><?= htmlspecialchars(substr($item['product_title'], 0, 25)) . (strlen($item['product_title']) > 25 ? '...' : '') ?> ×<?= $item['cart_item_quantity'] ?></span>
                                <span class="font-medium text-gray-800 flex-shrink-0">RM <?= number_format($item['product_price'] * $item['cart_item_quantity'], 2) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="border-t border-gray-100 pt-4 mb-6">
                            <div class="flex justify-between items-center">
                                <span class="font-bold text-gray-800">Total</span>
                                <span class="font-black text-xl text-red-600">RM <?= number_format($total, 2) ?></span>
                            </div>
                        </div>

                        <a href="checkout.php"
                           class="block w-full text-center bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-xl text-sm transition-colors duration-200">
                            Proceed to Checkout
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if (isset($_SESSION['payment_lock_msg'])): ?>
    <div id="paymentLockModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center px-6">
        <div class="bg-white rounded-3xl p-8 max-w-sm w-full shadow-2xl text-center">
            <div class="w-16 h-16 bg-yellow-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="text-3xl">⏳</span>
            </div>
            <h3 class="text-xl font-black text-gray-800 mb-2">Payment Pending</h3>
            <p class="text-sm text-gray-500 leading-relaxed mb-6">
                <?= htmlspecialchars($_SESSION['payment_lock_msg']) ?>
            </p>
            <button onclick="document.getElementById('paymentLockModal').classList.add('hidden')"
                    class="w-full py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors">
                OK
            </button>
        </div>
    </div>
    <?php unset($_SESSION['payment_lock_msg']); endif; ?>
    <script>
    function updateQty(btn, change) {
        const input = btn.parentNode.querySelector('input[name="quantity"]');
        const min = parseInt(input.min) || 1;
        const max = parseInt(input.max) || 999;
        let val = parseInt(input.value) + change;
        if (val < min) val = min;
        if (val > max) val = max;
        input.value = val;
        input.form.submit();
    }
    </script>

</body>
</html>