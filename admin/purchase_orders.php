<?php

require_once __DIR__ . '/../includes/auth.php';
require_admin();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/logger.php';

$success = (string) ($_SESSION['flash_success'] ?? '');
$error = (string) ($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['po_action'])
) {
    csrf_verify();

    $poId = filter_input(
        INPUT_POST,
        'po_id',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $action = $_POST['po_action'] ?? null;

    if (
        $poId === false ||
        $poId === null ||
        !is_string($action) ||
        !in_array($action, ['confirm', 'cancel'], true)
    ) {
        $_SESSION['flash_error'] =
            'Invalid purchase order action.';
        redirect_to(app_path('admin/purchase_orders.php'));
    }

    $poId = (int) $poId;

    if ($action === 'confirm') {
        try {
            $pdo->beginTransaction();

            $lockPo = $pdo->prepare("
                SELECT po_status
                FROM purchase_orders
                WHERE po_id = ?
                FOR UPDATE
            ");
            $lockPo->execute([$poId]);
            $status = $lockPo->fetchColumn();

            if ($status === false) {
                throw new RuntimeException(
                    'Purchase order not found.'
                );
            }

            if ($status !== 'sent') {
                throw new RuntimeException(
                    'Only a sent purchase order can be confirmed.'
                );
            }

            $confirmPo = $pdo->prepare("
                UPDATE purchase_orders
                SET po_status = 'confirmed',
                    po_confirmed_by = ?
                WHERE po_id = ?
                AND po_status = 'sent'
            ");
            $confirmPo->execute([
                current_user_id(),
                $poId,
            ]);

            if ($confirmPo->rowCount() !== 1) {
                throw new RuntimeException(
                    'Purchase order confirmation failed.'
                );
            }

            $pdo->commit();
            $_SESSION['flash_success'] =
                'Purchase order confirmed. Waiting for supplier acknowledgement.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException) {
                $_SESSION['flash_error'] = $e->getMessage();
            } else {
                app_error_log(
                    'Purchase order confirmation failed: ' .
                    $e->getMessage()
                );
                $_SESSION['flash_error'] =
                    'Unable to confirm the purchase order.';
            }
        }

        redirect_to(app_path('admin/purchase_orders.php'));
    }

    if (!is_senior_admin()) {
        $_SESSION['flash_error'] =
            'Only senior admin can cancel purchase orders.';
        redirect_to(app_path('admin/purchase_orders.php'));
    }

    try {
        $pdo->beginTransaction();

        $lockPo = $pdo->prepare("
            SELECT po_status
            FROM purchase_orders
            WHERE po_id = ?
            AND po_status IN ('sent', 'confirmed')
            FOR UPDATE
        ");
        $lockPo->execute([$poId]);

        if (!$lockPo->fetchColumn()) {
            throw new RuntimeException(
                'This purchase order is no longer cancellable.'
            );
        }

        $lockItems = $pdo->prepare("
            SELECT
                po_item_received_quantity,
                po_item_rejected_quantity
            FROM po_items
            WHERE po_item_po_id = ?
            FOR UPDATE
        ");
        $lockItems->execute([$poId]);

        foreach (
            $lockItems->fetchAll(PDO::FETCH_ASSOC)
            as $poItem
        ) {
            if (
                (int) $poItem['po_item_received_quantity'] > 0 ||
                (int) $poItem['po_item_rejected_quantity'] > 0
            ) {
                throw new RuntimeException(
                    'A purchase order with recorded receipts or rejected goods cannot be cancelled.'
                );
            }
        }

        $lockDeliveryOrders = $pdo->prepare("
            SELECT do_id
            FROM delivery_orders
            WHERE do_po_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $lockDeliveryOrders->execute([$poId]);

        if ($lockDeliveryOrders->fetchColumn()) {
            throw new RuntimeException(
                'A purchase order with a delivery order cannot be cancelled.'
            );
        }

        $cancelPo = $pdo->prepare("
            UPDATE purchase_orders
            SET po_status = 'cancelled'
            WHERE po_id = ?
            AND po_status IN ('sent', 'confirmed')
        ");
        $cancelPo->execute([$poId]);

        if ($cancelPo->rowCount() !== 1) {
            throw new RuntimeException(
                'Purchase order cancellation failed.'
            );
        }

        $pdo->commit();
        $_SESSION['flash_success'] =
            'Purchase order cancelled.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e instanceof RuntimeException) {
            $_SESSION['flash_error'] = $e->getMessage();
        } else {
            app_error_log(
                'Purchase order cancellation failed: ' .
                $e->getMessage()
            );
            $_SESSION['flash_error'] =
                'Unable to cancel the purchase order.';
        }
    }

    redirect_to(app_path('admin/purchase_orders.php'));
}

$purchaseOrders = $pdo->query("
    SELECT
        po.*,
        supplier.supplier_name,
        (
            SELECT COUNT(*)
            FROM po_items item_count
            WHERE item_count.po_item_po_id = po.po_id
        ) AS item_count,
        (
            SELECT COUNT(*)
            FROM delivery_orders delivery_count
            WHERE delivery_count.do_po_id = po.po_id
        ) AS delivery_order_count,
        (
            SELECT COUNT(*)
            FROM delivery_orders issued_delivery
            WHERE issued_delivery.do_po_id = po.po_id
            AND issued_delivery.do_status = 'issued'
        ) AS issued_delivery_order_count,
        (
            SELECT COUNT(*)
            FROM delivery_orders received_delivery
            WHERE received_delivery.do_po_id = po.po_id
            AND received_delivery.do_status = 'received'
        ) AS received_delivery_order_count,
        COALESCE((
            SELECT SUM(
                processed_item.po_item_received_quantity +
                processed_item.po_item_rejected_quantity
            )
            FROM po_items processed_item
            WHERE processed_item.po_item_po_id = po.po_id
        ), 0) AS processed_quantity,
        COALESCE((
            SELECT SUM(ordered_item.po_item_quantity)
            FROM po_items ordered_item
            WHERE ordered_item.po_item_po_id = po.po_id
        ), 0) AS ordered_quantity
    FROM purchase_orders po
    JOIN suppliers supplier
        ON supplier.supplier_id = po.po_supplier_id
    ORDER BY po.po_created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Purchase Orders - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">
        <div class="mb-8">
            <h1 class="text-2xl font-black text-gray-800">
                Purchase Orders
            </h1>
            <p class="text-gray-500 text-sm mt-1">
                Track supplier acknowledgement, delivery orders and
                receipts without bypassing shipment controls.
            </p>
        </div>

        <?php if ($success !== ''): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-6">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-6">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <?php if (!$purchaseOrders): ?>
                <div class="text-center py-16">
                    <div class="text-5xl mb-4">📦</div>
                    <p class="text-gray-400">
                        No purchase orders yet.
                    </p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-100">
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">PO Number</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Supplier</th>
                                <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Progress</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Total</th>
                                <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Workflow</th>
                                <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($purchaseOrders as $purchaseOrder): ?>
                                <?php
                                $statusColours = [
                                    'draft' =>
                                        'bg-gray-100 text-gray-500',
                                    'sent' =>
                                        'bg-yellow-100 text-yellow-700',
                                    'confirmed' =>
                                        'bg-blue-100 text-blue-700',
                                    'completed' =>
                                        'bg-green-100 text-green-700',
                                    'cancelled' =>
                                        'bg-red-100 text-red-700',
                                ];
                                $deliveryOrderCount =
                                    (int) $purchaseOrder[
                                        'delivery_order_count'
                                    ];
                                $issuedDeliveryOrderCount =
                                    (int) $purchaseOrder[
                                        'issued_delivery_order_count'
                                    ];
                                $receivedDeliveryOrderCount =
                                    (int) $purchaseOrder[
                                        'received_delivery_order_count'
                                    ];
                                $processedQuantity =
                                    (int) $purchaseOrder[
                                        'processed_quantity'
                                    ];
                                $orderedQuantity =
                                    (int) $purchaseOrder[
                                        'ordered_quantity'
                                    ];
                                $canCancel =
                                    in_array(
                                        $purchaseOrder['po_status'],
                                        ['sent', 'confirmed'],
                                        true
                                    ) &&
                                    $deliveryOrderCount === 0 &&
                                    $processedQuantity === 0;

                                $workflowLabel = '—';
                                $workflowClass = 'text-gray-400';

                                if ($purchaseOrder['po_status'] === 'sent') {
                                    $workflowLabel = 'Awaiting Admin Confirmation';
                                    $workflowClass = 'text-yellow-700';
                                } elseif (
                                    $purchaseOrder['po_status'] ===
                                        'confirmed' &&
                                    empty($purchaseOrder[
                                        'po_acknowledged_at'
                                    ])
                                ) {
                                    $workflowLabel = 'Awaiting Supplier Acknowledgement';
                                    $workflowClass = 'text-orange-700';
                                } elseif (
                                    $purchaseOrder['po_status'] ===
                                        'confirmed' &&
                                    $issuedDeliveryOrderCount > 0
                                ) {
                                    $workflowLabel = 'Delivery Awaiting Receipt';
                                    $workflowClass = 'text-amber-700';
                                } elseif (
                                    $purchaseOrder['po_status'] ===
                                        'confirmed' &&
                                    $deliveryOrderCount === 0
                                ) {
                                    $workflowLabel = 'Awaiting Supplier Delivery Order';
                                    $workflowClass = 'text-purple-700';
                                } elseif (
                                    $purchaseOrder['po_status'] ===
                                        'confirmed' &&
                                    $processedQuantity < $orderedQuantity
                                ) {
                                    $workflowLabel = 'Awaiting Next Delivery Order';
                                    $workflowClass = 'text-purple-700';
                                } elseif (
                                    $purchaseOrder['po_status'] ===
                                        'completed'
                                ) {
                                    $workflowLabel = 'Procurement Completed';
                                    $workflowClass = 'text-green-700';
                                } elseif (
                                    $purchaseOrder['po_status'] ===
                                        'cancelled'
                                ) {
                                    $workflowLabel = 'Cancelled';
                                    $workflowClass = 'text-red-600';
                                }
                                ?>
                                <tr class="border-b border-gray-50 hover:bg-gray-50">
                                    <td class="px-5 py-4">
                                        <p class="font-semibold text-sm text-gray-800">
                                            <?= htmlspecialchars((string) $purchaseOrder['po_number']) ?>
                                        </p>
                                        <p class="text-xs text-gray-400 mt-0.5">
                                            <?= date('d M Y', strtotime((string) $purchaseOrder['po_created_at'])) ?>
                                        </p>
                                        <?php if ($purchaseOrder['po_notes']): ?>
                                            <p class="text-xs text-purple-500 mt-1">
                                                <?= htmlspecialchars((string) $purchaseOrder['po_notes']) ?>
                                            </p>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-sm text-gray-600">
                                        <?= htmlspecialchars((string) $purchaseOrder['supplier_name']) ?>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <p class="text-sm font-semibold text-gray-700">
                                            <?= $processedQuantity ?>/<?= $orderedQuantity ?>
                                        </p>
                                        <p class="text-xs text-gray-400">
                                            <?= $deliveryOrderCount ?> DO ·
                                            <?= $receivedDeliveryOrderCount ?> received
                                        </p>
                                    </td>
                                    <td class="px-5 py-4 text-right text-sm font-bold text-red-600">
                                        RM <?= number_format((float) $purchaseOrder['po_total_amount'], 2) ?>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <span class="<?= $statusColours[$purchaseOrder['po_status']] ?? 'bg-gray-100 text-gray-500' ?> text-xs px-3 py-1 rounded-full font-semibold capitalize">
                                            <?= htmlspecialchars((string) $purchaseOrder['po_status']) ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="text-xs font-semibold <?= $workflowClass ?>">
                                            <?= htmlspecialchars($workflowLabel) ?>
                                        </p>
                                        <?php if ($purchaseOrder['po_acknowledged_at']): ?>
                                            <p class="text-xs text-gray-400 mt-1">
                                                Acknowledged
                                                <?= date('d M Y, h:i A', strtotime((string) $purchaseOrder['po_acknowledged_at'])) ?>
                                            </p>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <div class="flex items-center justify-center gap-2 flex-wrap">
                                            <a
                                                href="po_detail.php?id=<?= (int) $purchaseOrder['po_id'] ?>"
                                                class="text-xs text-blue-600 hover:underline font-semibold"
                                            >View</a>

                                            <?php if ($purchaseOrder['po_status'] === 'sent'): ?>
                                                <span class="text-gray-300">|</span>
                                                <form method="POST" class="inline">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="po_action" value="confirm">
                                                    <input type="hidden" name="po_id" value="<?= (int) $purchaseOrder['po_id'] ?>">
                                                    <button
                                                        type="submit"
                                                        onclick="return confirm('Confirm this purchase order?')"
                                                        class="text-xs text-green-600 hover:underline font-semibold"
                                                    >Confirm</button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if (
                                                $purchaseOrder['po_status'] ===
                                                'confirmed' &&
                                                $issuedDeliveryOrderCount > 0
                                            ): ?>
                                                <span class="text-gray-300">|</span>
                                                <a
                                                    href="goods_received.php?po_id=<?= (int) $purchaseOrder['po_id'] ?>"
                                                    class="text-xs text-purple-600 hover:underline font-semibold"
                                                >Receive Goods</a>
                                            <?php elseif (
                                                $purchaseOrder['po_status'] ===
                                                'confirmed' &&
                                                $deliveryOrderCount === 0
                                            ): ?>
                                                <span class="text-gray-300">|</span>
                                                <span
                                                    class="text-xs text-gray-400 font-semibold"
                                                    title="The supplier must create a delivery order before goods can be received"
                                                >Await DO</span>
                                            <?php elseif (
                                                $deliveryOrderCount > 0
                                            ): ?>
                                                <span class="text-gray-300">|</span>
                                                <a
                                                    href="goods_received.php?po_id=<?= (int) $purchaseOrder['po_id'] ?>"
                                                    class="text-xs text-gray-500 hover:underline font-semibold"
                                                >Delivery History</a>
                                            <?php endif; ?>

                                            <?php if (
                                                in_array(
                                                    $purchaseOrder['po_status'],
                                                    ['sent', 'confirmed'],
                                                    true
                                                )
                                            ): ?>
                                                <span class="text-gray-300">|</span>
                                                <?php if (
                                                    is_senior_admin() &&
                                                    $canCancel
                                                ): ?>
                                                    <form method="POST" class="inline">
                                                        <?php csrf_field(); ?>
                                                        <input type="hidden" name="po_action" value="cancel">
                                                        <input type="hidden" name="po_id" value="<?= (int) $purchaseOrder['po_id'] ?>">
                                                        <button
                                                            type="submit"
                                                            onclick="return confirm('Cancel this purchase order?')"
                                                            class="text-xs text-red-500 hover:underline font-semibold"
                                                        >Cancel</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span
                                                        class="text-xs text-gray-300"
                                                        title="A purchase order cannot be cancelled after a delivery order or receipt exists"
                                                    >Locked</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
