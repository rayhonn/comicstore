<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}
require_once '../includes/db.php';

$po_id = $_GET['po_id'] ?? null;
if (!$po_id) { header('Location: purchase_orders.php'); exit; }

$po = $pdo->prepare("
    SELECT po.*, s.supplier_name 
    FROM purchase_orders po
    JOIN suppliers s ON s.supplier_id = po.po_supplier_id
    WHERE po.po_id = ? AND po.po_status = 'confirmed'
");
$po->execute([$po_id]);
$po = $po->fetch(PDO::FETCH_ASSOC);
if (!$po) { header('Location: purchase_orders.php'); exit; }

$items = $pdo->prepare("
    SELECT pi.*, p.product_title, p.product_volume_number, p.product_cover_image
    FROM po_items pi
    JOIN products p ON p.product_id = pi.po_item_product_id
    WHERE pi.po_item_po_id = ?
");
$items->execute([$po_id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

// Get delivery order info if exists
$do_info = $pdo->prepare("SELECT * FROM delivery_orders WHERE do_po_id = ?");
$do_info->execute([$po_id]);
$do_info = $do_info->fetch(PDO::FETCH_ASSOC);

$do_items = [];
if ($do_info) {
    $doi = $pdo->prepare("
        SELECT doi.*, p.product_title
        FROM delivery_order_items doi
        JOIN products p ON p.product_id = doi.doi_product_id
        WHERE doi.doi_do_id = ?
    ");
    $doi->execute([$do_info['do_id']]);
    $do_items_raw = $doi->fetchAll(PDO::FETCH_ASSOC);
    foreach ($do_items_raw as $d) {
        $do_items[$d['doi_product_id']] = $d['doi_quantity'];
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_receive'])) {
    $received_qtys = $_POST['received_qty'] ?? [];
    $rejected_qtys = $_POST['rejected_qty'] ?? [];
    $reject_reasons = $_POST['reject_reason'] ?? [];

    $last = $pdo->query("SELECT gr_id FROM goods_received ORDER BY gr_id DESC LIMIT 1")->fetchColumn();
    $next_num = ($last ?? 0) + 1;
    $gr_number = 'GR-' . str_pad($next_num, 4, '0', STR_PAD_LEFT);

    $pdo->prepare("INSERT INTO goods_received (gr_po_id, gr_number, gr_received_by, gr_status) VALUES (?, ?, ?, 'pending')")
        ->execute([$po_id, $gr_number, $_SESSION['user_id']]);
    $gr_id = $pdo->lastInsertId();

    $all_fully_received = true;
    $has_rejected_items = false;
    $return_items_data = [];

    foreach ($items as $item) {
        $po_item_id = $item['po_item_id'];
        $received = intval($received_qtys[$po_item_id] ?? 0);
        $rejected = intval($rejected_qtys[$po_item_id] ?? 0);
        $reason = trim($reject_reasons[$po_item_id] ?? '');

        if ($received <= 0 && $rejected <= 0) continue;

        // Cap at remaining quantity
        $remaining = $item['po_item_quantity'] - $item['po_item_received_quantity'] - $item['po_item_rejected_quantity'];
        $total_processed = min($received + $rejected, $remaining);
        if ($received + $rejected > $remaining) {
            // Scale down proportionally if over
            $received = min($received, $remaining);
            $rejected = $remaining - $received;
        }

        // Record in goods_received_items
        $pdo->prepare("INSERT INTO goods_received_items (gri_gr_id, gri_po_item_id, gri_received_quantity, gri_rejected_quantity, gri_reject_reason) VALUES (?, ?, ?, ?, ?)")
            ->execute([$gr_id, $po_item_id, $received, $rejected, $rejected > 0 ? $reason : null]);

        // Update po_items received & rejected quantity
        $pdo->prepare("UPDATE po_items SET po_item_received_quantity = po_item_received_quantity + ?, po_item_rejected_quantity = po_item_rejected_quantity + ? WHERE po_item_id = ?")
            ->execute([$received, $rejected, $po_item_id]);

        // Update product stock — only good items count
        if ($received > 0) {
            $pdo->prepare("UPDATE product_physical SET physical_stock_quantity = physical_stock_quantity + ? WHERE physical_product_id = ?")
                ->execute([$received, $item['po_item_product_id']]);
        }

        if ($rejected > 0) {
            $has_rejected_items = true;
            $return_items_data[] = [
                'product_id' => $item['po_item_product_id'],
                'quantity' => $rejected,
                'reason' => $reason,
                'unit_price' => $item['po_item_unit_price'],
            ];
        }

        // Check if fully processed (received + rejected covers ordered qty)
        $new_total = $item['po_item_received_quantity'] + $item['po_item_rejected_quantity'] + $received + $rejected;
        if ($new_total < $item['po_item_quantity']) {
            $all_fully_received = false;
        }
    }

    // Update GR status
    $gr_status = $all_fully_received ? 'completed' : 'partial';
    $pdo->prepare("UPDATE goods_received SET gr_status = ? WHERE gr_id = ?")->execute([$gr_status, $gr_id]);

    // Create supplier_returns record if there are rejected items
    if ($has_rejected_items) {
        $last_ret = $pdo->query("SELECT return_id FROM supplier_returns ORDER BY return_id DESC LIMIT 1")->fetchColumn();
        $next_ret_num = ($last_ret ?? 0) + 1;
        $return_number = 'RTN-' . str_pad($next_ret_num, 4, '0', STR_PAD_LEFT);

        $pdo->prepare("INSERT INTO supplier_returns (return_number, return_po_id, return_gr_id, return_status) VALUES (?, ?, ?, 'pending')")
            ->execute([$return_number, $po_id, $gr_id]);
        $return_id = $pdo->lastInsertId();

        foreach ($return_items_data as $ri) {
            $pdo->prepare("INSERT INTO supplier_return_items (return_item_return_id, return_item_product_id, return_item_quantity, return_item_reason, return_item_unit_price) VALUES (?, ?, ?, ?, ?)")
                ->execute([$return_id, $ri['product_id'], $ri['quantity'], $ri['reason'], $ri['unit_price']]);
        }
    }

    // Update PO status — recalculate total based on accepted (received) quantities only
    if ($all_fully_received) {
        // Recalculate actual payable total (received qty only, excluding rejected)
        $new_total = $pdo->prepare("
            SELECT SUM(po_item_received_quantity * po_item_unit_price) FROM po_items WHERE po_item_po_id = ?
        ");
        $new_total->execute([$po_id]);
        $payable_total = $new_total->fetchColumn();

        $pdo->prepare("UPDATE purchase_orders SET po_status = 'completed', po_total_amount = ? WHERE po_id = ?")
            ->execute([$payable_total, $po_id]);
    }

    $msg = "$gr_number recorded successfully. Stock has been updated.";
    if ($has_rejected_items) {
        $msg .= " A return record ($return_number) has been created for damaged/rejected items.";
    }
    $_SESSION['flash_success'] = $msg;
    header('Location: purchase_orders.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receive Goods - <?= htmlspecialchars($po['po_number']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-5xl mx-auto px-6 py-8">

        <p class="text-sm text-gray-400 mb-6">
            <a href="purchase_orders.php" class="hover:text-red-600 transition-colors">Purchase Orders</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">Receive Goods — <?= htmlspecialchars($po['po_number']) ?></span>
        </p>

        <div class="mb-6">
            <h1 class="text-2xl font-black text-gray-800">📦 Receive Goods</h1>
            <p class="text-gray-500 text-sm mt-1"><?= htmlspecialchars($po['po_number']) ?> from <?= htmlspecialchars($po['supplier_name']) ?></p>
        </div>

        <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-6">
            <p class="text-sm text-blue-700">📌 Enter <strong>Good Qty</strong> for items in acceptable condition (added to stock) and <strong>Damaged/Rejected Qty</strong> for items with quality issues (will be returned to supplier and excluded from payment).</p>
        </div>

        <?php if ($do_info): ?>
        <div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-6">
            <p class="text-sm text-green-700 font-semibold mb-1">🚚 Delivery Order Found: <?= htmlspecialchars($do_info['do_number']) ?></p>
            <p class="text-xs text-green-600">Delivery Date: <?= date('d M Y', strtotime($do_info['do_delivery_date'])) ?> · Supplier declared they shipped these quantities. Please verify against actual goods received.</p>
        </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="confirm_receive" value="1">

            <div class="bg-white rounded-2xl shadow-sm overflow-hidden mb-6">
                <table class="w-full">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Product</th>
                            <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Ordered</th>
                            <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Remaining</th>
                            <?php if ($do_info): ?>
                            <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">DO Declared</th>
                            <?php endif; ?>
                            <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">✓ Good Qty</th>
                            <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">⚠️ Damaged/Rejected Qty</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Reason (if any)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): 
                            $remaining = $item['po_item_quantity'] - $item['po_item_received_quantity'] - $item['po_item_rejected_quantity'];
                        ?>
                        <tr class="border-t border-gray-50">
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <?php if ($item['product_cover_image']): ?>
                                    <img src="../assets/images/<?= htmlspecialchars($item['product_cover_image']) ?>" class="w-8 h-11 object-cover rounded">
                                    <?php endif; ?>
                                    <div>
                                        <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($item['product_title']) ?></p>
                                        <?php if ($item['product_volume_number']): ?>
                                        <p class="text-xs text-gray-400">Vol.<?= $item['product_volume_number'] ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-4 text-center text-sm font-semibold text-gray-700"><?= $item['po_item_quantity'] ?></td>
                            <td class="px-5 py-4 text-center text-sm text-gray-500"><?= $remaining ?></td>
                            <td class="px-5 py-4 text-center">
                                <?php if ($remaining > 0): ?>
                                <input type="number" name="received_qty[<?= $item['po_item_id'] ?>]" min="0" max="<?= $remaining ?>" value="<?= $remaining ?>"
                                       class="good-qty w-20 px-3 py-2 border-2 border-gray-100 rounded-xl text-sm text-center focus:outline-none focus:border-green-400"
                                       data-item="<?= $item['po_item_id'] ?>" data-remaining="<?= $remaining ?>"
                                       oninput="syncQty(<?= $item['po_item_id'] ?>, 'good')">
                                <?php else: ?>
                                <span class="text-green-600 text-xs font-semibold">✓ Done</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-4 text-center">
                                <?php if ($remaining > 0): ?>
                                <input type="number" name="rejected_qty[<?= $item['po_item_id'] ?>]" min="0" max="<?= $remaining ?>" value="0"
                                       class="rejected-qty w-20 px-3 py-2 border-2 border-red-100 rounded-xl text-sm text-center focus:outline-none focus:border-red-400"
                                       data-item="<?= $item['po_item_id'] ?>" data-remaining="<?= $remaining ?>"
                                       oninput="syncQty(<?= $item['po_item_id'] ?>, 'rejected')">
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-4">
                                <?php if ($remaining > 0): ?>
                                <input type="text" name="reject_reason[<?= $item['po_item_id'] ?>]" placeholder="e.g. Torn cover, water damage"
                                       class="w-full px-3 py-2 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400">
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex gap-3">
                <button type="submit"
                        class="bg-red-600 hover:bg-red-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm transition-colors">
                    ✓ Confirm Receipt & Update Stock
                </button>
                <a href="purchase_orders.php" class="border-2 border-gray-200 hover:bg-gray-50 text-gray-600 font-semibold px-6 py-2.5 rounded-xl text-sm transition-colors">
                    Cancel
                </a>
            </div>
        </form>

    </div>

    <script>
    function syncQty(itemId, changed) {
        const goodInput = document.querySelector(`.good-qty[data-item="${itemId}"]`);
        const rejectedInput = document.querySelector(`.rejected-qty[data-item="${itemId}"]`);
        const remaining = parseInt(goodInput.dataset.remaining);

        let good = parseInt(goodInput.value) || 0;
        let rejected = parseInt(rejectedInput.value) || 0;

        if (changed === 'good') {
            if (good > remaining) good = remaining;
            rejected = remaining - good;
            rejectedInput.value = rejected;
            goodInput.value = good;
        } else {
            if (rejected > remaining) rejected = remaining;
            good = remaining - rejected;
            goodInput.value = good;
            rejectedInput.value = rejected;
        }
    }
    </script>

</body>
</html>