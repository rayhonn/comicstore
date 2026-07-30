<?php

require_once __DIR__ . '/../includes/auth.php';
require_admin();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ .
    '/../includes/procurement_receipt_helper.php';

$poId = filter_input(
    INPUT_GET,
    'po_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($poId === false || $poId === null) {
    redirect_to(app_path('admin/purchase_orders.php'));
}

$poId = (int) $poId;

$poStatement = $pdo->prepare("
    SELECT
        po.po_id,
        po.po_number,
        po.po_status,
        po.po_acknowledged_at,
        supplier.supplier_name
    FROM purchase_orders po
    JOIN suppliers supplier
        ON supplier.supplier_id = po.po_supplier_id
    WHERE po.po_id = ?
    LIMIT 1
");
$poStatement->execute([$poId]);
$purchaseOrder = $poStatement->fetch(PDO::FETCH_ASSOC);

if (!$purchaseOrder) {
    redirect_to(app_path('admin/purchase_orders.php'));
}

$deliveryStatement = $pdo->prepare("
    SELECT
        delivery.do_id,
        delivery.do_number,
        delivery.do_delivery_date,
        delivery.do_status,
        delivery.do_receipt_nonce,
        delivery.do_created_at,
        delivery.do_received_at,
        receipt.gr_number,
        receipt.gr_status,
        COALESCE(SUM(delivery_item.doi_quantity), 0)
            AS total_quantity
    FROM delivery_orders delivery
    LEFT JOIN delivery_order_items delivery_item
        ON delivery_item.doi_do_id = delivery.do_id
    LEFT JOIN goods_received receipt
        ON receipt.gr_do_id = delivery.do_id
    WHERE delivery.do_po_id = ?
    GROUP BY delivery.do_id
    ORDER BY delivery.do_id DESC
");
$deliveryStatement->execute([$poId]);
$deliveryOrders = $deliveryStatement->fetchAll(PDO::FETCH_ASSOC);

$configurationError = '';
try {
    procurementReceiptSecret();

    if (!defined('APP_URL')) {
        throw new RuntimeException(
            'APP_URL is not configured. Add the full local project URL to the project .env file.'
        );
    }

    $appUrl = rtrim(trim((string) APP_URL), '/');
    $scheme = strtolower((string) parse_url($appUrl, PHP_URL_SCHEME));

    if (
        $appUrl === '' ||
        filter_var($appUrl, FILTER_VALIDATE_URL) === false ||
        !in_array($scheme, ['http', 'https'], true)
    ) {
        throw new RuntimeException(
            'APP_URL must be a valid http or https URL, for example http://localhost/comicstore.'
        );
    }
} catch (RuntimeException $e) {
    $configurationError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>
        Delivery Receipts -
        <?= htmlspecialchars((string) $purchaseOrder['po_number']) ?>
    </title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-5xl mx-auto px-6 py-8">
        <p class="text-sm text-gray-400 mb-6">
            <a
                href="purchase_orders.php"
                class="hover:text-red-600"
            >Purchase Orders</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">
                Delivery Receipts —
                <?= htmlspecialchars((string) $purchaseOrder['po_number']) ?>
            </span>
        </p>

        <div class="mb-6">
            <h1 class="text-2xl font-black text-gray-800">
                Delivery Receipts
            </h1>
            <p class="text-gray-500 text-sm mt-1">
                <?= htmlspecialchars((string) $purchaseOrder['po_number']) ?>
                from
                <?= htmlspecialchars((string) $purchaseOrder['supplier_name']) ?>
            </p>
        </div>

        <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6">
            <p class="font-semibold text-blue-800 text-sm">
                Goods can only be received against an issued supplier
                delivery order.
            </p>
            <p class="text-xs text-blue-700 mt-1">
                Direct receipt by purchase order has been disabled so
                stock, rejected quantities and payment remain traceable
                to the exact shipment.
            </p>
        </div>

        <?php if ($configurationError !== ''): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-6">
                <?= htmlspecialchars($configurationError) ?>
            </div>
        <?php endif; ?>

        <?php if (!$deliveryOrders): ?>
            <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                <div class="text-4xl mb-3">📭</div>
                <h2 class="font-bold text-gray-800">
                    No Delivery Order Yet
                </h2>
                <p class="text-sm text-gray-500 mt-2">
                    The supplier must acknowledge the purchase order and
                    create a delivery order before receiving can begin.
                </p>
                <a
                    href="purchase_orders.php"
                    class="inline-flex mt-5 bg-gray-800 text-white font-bold px-5 py-2.5 rounded-xl text-sm"
                >Return to Purchase Orders</a>
            </div>
        <?php else: ?>
            <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-100">
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Delivery Order</th>
                                <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Quantity</th>
                                <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Goods Receipt</th>
                                <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deliveryOrders as $deliveryOrder): ?>
                                <?php
                                $receiptUrl = '';
                                if (
                                    $configurationError === '' &&
                                    $deliveryOrder['do_status'] === 'issued'
                                ) {
                                    try {
                                        $receiptUrl = procurementReceiptUrl(
                                            (int) $deliveryOrder['do_id'],
                                            strtolower(trim(
                                                (string) $deliveryOrder[
                                                    'do_receipt_nonce'
                                                ]
                                            ))
                                        );
                                    } catch (Throwable $e) {
                                        $receiptUrl = '';
                                    }
                                }
                                ?>
                                <tr class="border-b border-gray-50">
                                    <td class="px-5 py-4">
                                        <p class="font-semibold text-sm text-gray-800">
                                            <?= htmlspecialchars((string) $deliveryOrder['do_number']) ?>
                                        </p>
                                        <p class="text-xs text-gray-400">
                                            Delivery date:
                                            <?= date('d M Y', strtotime((string) $deliveryOrder['do_delivery_date'])) ?>
                                        </p>
                                    </td>
                                    <td class="px-5 py-4 text-center text-sm font-semibold text-gray-700">
                                        <?= (int) $deliveryOrder['total_quantity'] ?>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $deliveryOrder['do_status'] === 'received' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' ?>">
                                            <?= $deliveryOrder['do_status'] === 'received' ? 'Received' : 'Awaiting Receipt' ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-sm text-gray-500">
                                        <?php if ($deliveryOrder['gr_number']): ?>
                                            <?= htmlspecialchars((string) $deliveryOrder['gr_number']) ?>
                                            <p class="text-xs text-gray-400 capitalize">
                                                <?= htmlspecialchars((string) $deliveryOrder['gr_status']) ?>
                                            </p>
                                        <?php else: ?>
                                            Not recorded
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <?php if (
                                            $deliveryOrder['do_status'] === 'issued' &&
                                            $receiptUrl !== ''
                                        ): ?>
                                            <a
                                                href="<?= htmlspecialchars($receiptUrl, ENT_QUOTES, 'UTF-8') ?>"
                                                class="inline-flex bg-red-600 hover:bg-red-700 text-white text-xs font-bold px-4 py-2 rounded-lg"
                                            >Open Secure Receipt</a>
                                        <?php elseif (
                                            $deliveryOrder['do_status'] === 'received' &&
                                            $deliveryOrder['gr_number']
                                        ): ?>
                                            <span class="text-xs font-semibold text-green-600">
                                                Completed
                                            </span>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-400">
                                                Unavailable
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
