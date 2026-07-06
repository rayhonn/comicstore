<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';

require_supplier();

$supplier_id = $_SESSION['supplier_id'];
$rfq_id = $_GET['id'] ?? null;
if (!$rfq_id) { header('Location: dashboard.php'); exit; }

$check = $pdo->prepare("SELECT * FROM rfq_suppliers WHERE rfq_supplier_rfq_id = ? AND rfq_supplier_supplier_id = ?");
$check->execute([$rfq_id, $supplier_id]);
if (!$check->fetch()) { header('Location: dashboard.php'); exit; }

$rfq = $pdo->prepare("SELECT * FROM rfq WHERE rfq_id = ?");
$rfq->execute([$rfq_id]);
$rfq = $rfq->fetch(PDO::FETCH_ASSOC);

$items = $pdo->prepare("
    SELECT ri.*, p.product_title, p.product_series, p.product_volume_number, p.product_cover_image
    FROM rfq_items ri
    JOIN products p ON p.product_id = ri.rfq_item_product_id
    WHERE ri.rfq_item_rfq_id = ?
");
$items->execute([$rfq_id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

$existing_quote = $pdo->prepare("SELECT * FROM quotations WHERE quotation_rfq_id = ? AND quotation_supplier_id = ?");
$existing_quote->execute([$rfq_id, $supplier_id]);
$existing_quote = $existing_quote->fetch(PDO::FETCH_ASSOC);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quote']) && !$existing_quote) {
    csrf_verify();

    $prices = $_POST['unit_price'] ?? [];
    $notes = trim($_POST['notes'] ?? '');

    $valid = true;
    foreach ($items as $item) {
        if (empty($prices[$item['rfq_item_id']]) || $prices[$item['rfq_item_id']] <= 0) {
            $valid = false;
        }
    }

    if (!$valid) {
        $error = 'Please enter a valid price for all items.';
    } else {
        $pdo->prepare("INSERT INTO quotations (quotation_rfq_id, quotation_supplier_id, quotation_notes) VALUES (?, ?, ?)")
            ->execute([$rfq_id, $supplier_id, $notes]);
        $quotation_id = $pdo->lastInsertId();

        foreach ($items as $item) {
            $pdo->prepare("INSERT INTO quotation_items (quotation_item_quotation_id, quotation_item_product_id, quotation_item_quantity, quotation_item_unit_price) VALUES (?, ?, ?, ?)")
                ->execute([$quotation_id, $item['rfq_item_product_id'], $item['rfq_item_quantity'], $prices[$item['rfq_item_id']]]);
        }

        $pdo->prepare("UPDATE rfq SET rfq_status = 'quoted' WHERE rfq_id = ?")->execute([$rfq_id]);

        header('Location: dashboard.php?quoted=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RFQ <?= htmlspecialchars($rfq['rfq_number']) ?> - Supplier Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">

    <?php include '../includes/supplier_navbar.php'; ?>

    <div class="max-w-3xl mx-auto px-6 py-8">

        <p class="text-sm text-gray-400 mb-6">
            <a href="dashboard.php" class="hover:text-blue-600 transition-colors">Dashboard</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600"><?= htmlspecialchars($rfq['rfq_number']) ?></span>
        </p>

        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-black text-gray-800"><?= htmlspecialchars($rfq['rfq_number']) ?></h1>
                <p class="text-gray-500 text-sm mt-1">Received <?= date('d M Y', strtotime($rfq['rfq_created_at'])) ?></p>
            </div>
        </div>

        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-6">
            ❌ <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($rfq['rfq_notes'])): ?>
        <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-6">
            <p class="text-xs font-semibold text-blue-700 mb-1">📝 Notes from MangaVault</p>
            <p class="text-sm text-blue-600"><?= nl2br(htmlspecialchars($rfq['rfq_notes'])) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($existing_quote): ?>
        <div class="bg-white rounded-2xl shadow-sm p-6">
            <div class="flex items-center gap-3 mb-5">
                <div class="w-10 h-10 bg-green-100 rounded-xl flex items-center justify-center text-xl">✅</div>
                <div>
                    <p class="font-bold text-gray-800">Quote Submitted</p>
                    <p class="text-xs text-gray-400">Submitted on <?= date('d M Y, h:i A', strtotime($existing_quote['quotation_submitted_at'])) ?></p>
                </div>
            </div>
            <p class="text-sm text-gray-500">You have already submitted a quote for this RFQ. MangaVault will review and contact you if selected.</p>
        </div>
        <?php else: ?>
        <form method="POST">
            <?php csrf_field() ?>
            <input type="hidden" name="submit_quote" value="1">

            <div class="bg-white rounded-2xl shadow-sm overflow-hidden mb-6">
                <table class="w-full">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-100">
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Product</th>
                            <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Qty Requested</th>
                            <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Your Unit Price (RM)</th>
                            <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr class="border-b border-gray-50">
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <?php if ($item['product_cover_image']): ?>
                                    <img src="../assets/images/<?= htmlspecialchars($item['product_cover_image']) ?>" class="w-8 h-11 object-cover rounded">
                                    <?php endif; ?>
                                    <div>
                                        <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($item['product_title']) ?></p>
                                        <p class="text-xs text-gray-400"><?= htmlspecialchars($item['product_series'] ?? '') ?> <?= $item['product_volume_number'] ? 'Vol.' . $item['product_volume_number'] : '' ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-4 text-center text-sm font-semibold text-gray-700"><?= $item['rfq_item_quantity'] ?></td>
                            <td class="px-5 py-4">
                                <input type="number" step="0.01" min="0.01" name="unit_price[<?= $item['rfq_item_id'] ?>]" required
                                       placeholder="0.00" data-qty="<?= $item['rfq_item_quantity'] ?>"
                                       oninput="updateSubtotal(this)"
                                       class="w-28 px-3 py-2 border-2 border-gray-100 rounded-xl text-sm text-right focus:outline-none focus:border-blue-400 transition-colors">
                            </td>
                            <td class="px-5 py-4 text-right text-sm font-bold text-gray-800 subtotal-cell">RM 0.00</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="bg-gray-50">
                            <td colspan="3" class="px-5 py-3 text-right text-sm font-bold text-gray-700">Total Quote Amount</td>
                            <td class="px-5 py-3 text-right text-base font-black text-blue-600" id="grandTotal">RM 0.00</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Additional Notes (Optional)</label>
                <textarea name="notes" rows="3" placeholder="Delivery timeline, terms, special conditions..."
                          class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-blue-400 transition-colors resize-none"></textarea>
            </div>

            <button type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3.5 rounded-xl text-sm transition-colors">
                Submit Quotation
            </button>
        </form>
        <?php endif; ?>

    </div>

    <script>
    function updateSubtotal(input) {
        const qty = parseFloat(input.dataset.qty);
        const price = parseFloat(input.value) || 0;
        const subtotal = qty * price;
        input.closest('tr').querySelector('.subtotal-cell').textContent = 'RM ' + subtotal.toFixed(2);
        updateGrandTotal();
    }

    function updateGrandTotal() {
        let total = 0;
        document.querySelectorAll('.subtotal-cell').forEach(cell => {
            total += parseFloat(cell.textContent.replace('RM ', '')) || 0;
        });
        document.getElementById('grandTotal').textContent = 'RM ' + total.toFixed(2);
    }
    </script>

</body>
</html>