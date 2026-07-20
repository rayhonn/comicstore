<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

$success = '';
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// Handle PO status update
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['po_action'])
) {
    csrf_verify();

    $poId = filter_input(
        INPUT_POST,
        'po_id',
        FILTER_VALIDATE_INT
    );

    if (!$poId) {
        $_SESSION['flash_error'] = 'Invalid purchase order.';
        header('Location: purchase_orders.php');
        exit;
    }

    $action = $_POST['po_action'];

    if ($action === 'confirm') {
        $stmt = $pdo->prepare(
            "UPDATE purchase_orders
             SET po_status = 'confirmed',
                 po_confirmed_by = ?
             WHERE po_id = ?
             AND po_status = 'sent'"
        );

        $stmt->execute([
            current_user_id(),
            $poId
        ]);

        $_SESSION['flash_success'] =
            $stmt->rowCount() > 0
                ? 'Purchase order confirmed.'
                : 'Purchase order could not be confirmed.';
    } elseif ($action === 'cancel') {
        if (($_SESSION['admin_level'] ?? '') !== 'senior_admin') {
            $_SESSION['flash_error'] =
                'Only senior admin can cancel purchase orders.';

            header('Location: purchase_orders.php');
            exit;
        }

        $stmt = $pdo->prepare(
            "UPDATE purchase_orders
             SET po_status = 'cancelled'
             WHERE po_id = ?
             AND po_status IN ('sent', 'confirmed')"
        );

        $stmt->execute([$poId]);

        $_SESSION['flash_success'] =
            $stmt->rowCount() > 0
                ? 'Purchase order cancelled.'
                : 'Purchase order could not be cancelled.';
    }

    header('Location: purchase_orders.php');
    exit;
}

$pos = $pdo->query("
    SELECT po.*, s.supplier_name,
    COUNT(pi.po_item_id) as item_count
    FROM purchase_orders po
    JOIN suppliers s ON s.supplier_id = po.po_supplier_id
    LEFT JOIN po_items pi ON pi.po_item_po_id = po.po_id
    GROUP BY po.po_id
    ORDER BY po.po_created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-6xl mx-auto px-6 py-8">

        <div class="mb-8">
            <h1 class="text-2xl font-black text-gray-800">📦 Purchase Orders</h1>
            <p class="text-gray-500 text-sm mt-1">Track and manage all purchase orders sent to suppliers</p>
        </div>

        <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-6">
            ✅ <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['flash_error'])): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-6">
            🔒 <?= htmlspecialchars($_SESSION['flash_error']) ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>
        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <?php if (count($pos) === 0): ?>
            <div class="text-center py-16">
                <div class="text-5xl mb-4">📦</div>
                <p class="text-gray-400">No purchase orders yet.</p>
            </div>
            <?php else: ?>
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">PO Number</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Supplier</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Items</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Total</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pos as $po):
                        $status_colors = [
                            'draft'     => 'bg-gray-100 text-gray-500',
                            'sent'      => 'bg-yellow-100 text-yellow-700',
                            'confirmed' => 'bg-blue-100 text-blue-700',
                            'completed' => 'bg-green-100 text-green-700',
                            'cancelled' => 'bg-red-100 text-red-700',
                        ];
                    ?>
                    <tr class="border-b border-gray-50 hover:bg-gray-50 transition-colors">
                        <td class="px-5 py-4">
                            <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($po['po_number']) ?></p>
                            <?php if ($po['po_notes']): ?>
                            <p class="text-xs text-purple-500 mt-0.5">📌 <?= htmlspecialchars($po['po_notes']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-4 text-sm text-gray-600"><?= htmlspecialchars($po['supplier_name']) ?></td>
                        <td class="px-5 py-4 text-center text-sm text-gray-600"><?= $po['item_count'] ?></td>
                        <td class="px-5 py-4 text-right text-sm font-bold text-red-600">RM <?= number_format($po['po_total_amount'], 2) ?></td>
                        <td class="px-5 py-4 text-center">
                            <span class="<?= $status_colors[$po['po_status']] ?> text-xs px-3 py-1 rounded-full font-semibold capitalize">
                                <?= $po['po_status'] ?>
                            </span>
                        </td>
                        <td class="px-5 py-4 text-xs text-gray-400"><?= date('d M Y', strtotime($po['po_created_at'])) ?></td>
                        <td class="px-5 py-4 text-center">
                            <div class="flex items-center justify-center gap-2">
                                <a href="po_detail.php?id=<?= $po['po_id'] ?>" class="text-xs text-blue-600 hover:underline font-semibold">View</a>
                                <?php if ($po['po_status'] === 'sent'): ?>
                                <span class="text-gray-300">|</span>
                                <form method="POST" class="inline">
                                    <?php csrf_field(); ?>
                                    <input
                                        type="hidden" name="po_action" value="confirm">

                                    <input
                                        type="hidden" name="po_id" value="<?= (int) $po['po_id'] ?>">

                                    <button
                                        type="submit" onclick="return confirm('Confirm this PO?')" class="text-xs text-green-600 hover:underline font-semibold">
                                        Confirm
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php if (in_array($po['po_status'], ['sent', 'confirmed'])): ?>
                                <span class="text-gray-300">|</span>
                                <?php if (($_SESSION['admin_level'] ?? '') === 'senior_admin'): ?>
                                <form method="POST" class="inline">
                                    <?php csrf_field(); ?>

                                    <input
                                        type="hidden"
                                        name="po_action"
                                        value="cancel"
                                    >

                                    <input
                                        type="hidden"
                                        name="po_id"
                                        value="<?= (int) $po['po_id'] ?>"
                                    >

                                    <button
                                        type="submit"
                                        onclick="return confirm('Cancel this PO?')"
                                        class="text-xs text-red-500 hover:underline font-semibold"
                                    >
                                        Cancel
                                    </button>
                                </form>
                                <?php else: ?>
                                <span class="text-xs text-gray-300" title="Only senior admin can cancel orders">🔒 Cancel</span>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($po['po_status'] === 'confirmed'): ?>
                                <span class="text-gray-300">|</span>
                                <a href="goods_received.php?po_id=<?= $po['po_id'] ?>" class="text-xs text-purple-600 hover:underline font-semibold">Receive Goods</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>