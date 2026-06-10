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
                        <!-- Header with Select All -->
                        <div class="px-6 py-4 border-b border-gray-50 flex items-center gap-3">
                            <input type="checkbox" id="selectAll" onchange="toggleAll(this)"
                                   class="w-4 h-4 rounded accent-red-600 cursor-pointer">
                            <h2 class="font-bold text-gray-800">Cart Items <span class="text-gray-400 font-normal text-sm">(<?= count($items) ?>)</span></h2>
                        </div>

                        <?php foreach ($items as $item): ?>
                        <div class="px-6 py-4 border-b border-gray-50 last:border-0 flex items-center gap-4 cart-row"
                             data-id="<?= $item['cart_item_id'] ?>"
                             data-price="<?= $item['product_price'] ?>"
                             data-qty="<?= $item['cart_item_quantity'] ?>"
                             data-type="<?= $item['product_type'] ?>">

                            <!-- Checkbox -->
                            <input type="checkbox" checked
                                   class="item-checkbox w-4 h-4 rounded accent-red-600 cursor-pointer flex-shrink-0"
                                   onchange="recalculate()">

                            <!-- Image -->
                            <a href="product_detail.php?id=<?= $item['product_id'] ?>" class="flex-shrink-0">
                                <?php if ($item['product_cover_image']): ?>
                                    <img src="../assets/images/<?= htmlspecialchars($item['product_cover_image']) ?>"
                                         class="w-16 h-20 object-cover rounded-lg hover:opacity-80 transition-opacity">
                                <?php else: ?>
                                    <div class="w-16 h-20 bg-gray-100 rounded-lg flex items-center justify-center text-gray-400 text-xs font-bold">N/A</div>
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
                                    <div class="flex items-center border border-gray-200 rounded-lg overflow-hidden">
                                        <button type="button"
                                                onclick="changeQty(<?= $item['cart_item_id'] ?>, -1, <?= $item['product_price'] ?>, <?= $item['physical_stock_quantity'] ?>)"
                                                class="w-8 h-8 flex items-center justify-center text-gray-500 hover:bg-gray-50 transition-colors text-lg">−</button>
                                        <span id="qty-<?= $item['cart_item_id'] ?>"
                                              class="w-10 h-8 flex items-center justify-center text-sm font-medium border-x border-gray-200">
                                            <?= $item['cart_item_quantity'] ?>
                                        </span>
                                        <button type="button"
                                                onclick="changeQty(<?= $item['cart_item_id'] ?>, 1, <?= $item['product_price'] ?>, <?= $item['physical_stock_quantity'] ?>)"
                                                class="w-8 h-8 flex items-center justify-center text-gray-500 hover:bg-gray-50 transition-colors text-lg">+</button>
                                    </div>
                                <?php else: ?>
                                    <span class="text-sm text-gray-500">×1</span>
                                <?php endif; ?>
                            </div>

                            <!-- Subtotal -->
                            <div class="flex-shrink-0 text-right min-w-16">
                                <p id="subtotal-<?= $item['cart_item_id'] ?>" class="font-bold text-sm text-gray-800">
                                    RM <?= number_format($item['product_price'] * $item['cart_item_quantity'], 2) ?>
                                </p>
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

                    <a href="home.php" class="inline-flex items-center gap-2 text-sm text-gray-500 hover:text-red-600 transition-colors mt-4">
                        ← Continue Shopping
                    </a>
                </div>

                <!-- Order Summary -->
                <div class="w-full lg:w-80 flex-shrink-0">
                    <div class="bg-white rounded-2xl shadow-sm p-6 sticky top-24">
                        <h3 class="font-bold text-gray-800 mb-4">Order Summary</h3>

                        <div id="summaryList" class="space-y-3 mb-4 text-sm text-gray-500">
                            <!-- filled by JS -->
                        </div>

                        <div class="border-t border-gray-100 pt-4 mb-4">
                            <div class="flex justify-between items-center">
                                <span class="font-bold text-gray-800">Total</span>
                                <span id="totalDisplay" class="font-black text-xl text-red-600">RM 0.00</span>
                            </div>
                        </div>

                        <p id="noItemMsg" class="text-xs text-gray-400 text-center mb-3 hidden">Please select at least one item.</p>

                        <!-- Hidden form for checkout with selected items -->
                        <form id="checkoutForm" method="GET" action="checkout.php">
                            <input type="hidden" name="selected_items" id="selectedItemsInput">
                            <button type="button" onclick="proceedCheckout()"
                                    class="block w-full text-center bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-xl text-sm transition-colors duration-200">
                                Proceed to Checkout
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>



    <script>
    // Item data from PHP
    const itemData = {};
    <?php foreach ($items as $item): ?>
    itemData[<?= $item['cart_item_id'] ?>] = {
        id: <?= $item['cart_item_id'] ?>,
        title: <?= json_encode(substr($item['product_title'], 0, 25) . (strlen($item['product_title']) > 25 ? '...' : '')) ?>,
        price: <?= $item['product_price'] ?>,
        qty: <?= $item['cart_item_quantity'] ?>,
        maxQty: <?= $item['physical_stock_quantity'] ?? 1 ?>,
        type: '<?= $item['product_type'] ?>'
    };
    <?php endforeach; ?>

    function changeQty(cartItemId, change, price, maxQty) {
        const data = itemData[cartItemId];
        let newQty = data.qty + change;
        if (newQty < 1) newQty = 1;
        if (newQty > maxQty) newQty = maxQty;
        data.qty = newQty;

        // Update display
        document.getElementById('qty-' + cartItemId).textContent = newQty;
        document.getElementById('subtotal-' + cartItemId).textContent = 'RM ' + (price * newQty).toFixed(2);

        // Update data-qty on row
        document.querySelector('.cart-row[data-id="' + cartItemId + '"]').dataset.qty = newQty;

        // Save to server (silent AJAX)
        fetch('cart_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=update&cart_item_id=' + cartItemId + '&quantity=' + newQty
        });

        recalculate();
    }

    function recalculate() {
        const rows = document.querySelectorAll('.cart-row');
        let total = 0;
        let summaryHtml = '';
        let selectedIds = [];

        rows.forEach(row => {
            const checkbox = row.querySelector('.item-checkbox');
            if (!checkbox.checked) return;

            const id = row.dataset.id;
            const data = itemData[id];
            const subtotal = data.price * data.qty;
            total += subtotal;
            selectedIds.push(id);
            summaryHtml += `<div class="flex justify-between">
                <span class="truncate mr-2">${data.title} ×${data.qty}</span>
                <span class="flex-shrink-0 font-medium text-gray-800">RM ${subtotal.toFixed(2)}</span>
            </div>`;
        });

        document.getElementById('totalDisplay').textContent = 'RM ' + total.toFixed(2);
        document.getElementById('summaryList').innerHTML = summaryHtml || '<p class="text-gray-400 text-xs">No items selected.</p>';
        document.getElementById('selectedItemsInput').value = selectedIds.join(',');

        // Show/hide no item message
        document.getElementById('noItemMsg').classList.toggle('hidden', selectedIds.length > 0);

        // Update select all checkbox
        const allChecked = document.querySelectorAll('.item-checkbox:not(:checked)').length === 0;
        document.getElementById('selectAll').checked = allChecked;
        document.getElementById('selectAll').indeterminate = !allChecked && selectedIds.length > 0;
    }

    function toggleAll(masterCheckbox) {
        document.querySelectorAll('.item-checkbox').forEach(cb => {
            cb.checked = masterCheckbox.checked;
        });
        recalculate();
    }

    function proceedCheckout() {
        const selected = document.getElementById('selectedItemsInput').value;
        if (!selected) {
            document.getElementById('noItemMsg').classList.remove('hidden');
            return;
        }
        document.getElementById('checkoutForm').submit();
    }

    // Init on load
    recalculate();
    </script>

</body>
</html>