<?php

require_once __DIR__ . '/../includes/auth.php';
require_admin();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/money_helper.php';
require_once __DIR__ .
    '/../includes/procurement_receipt_helper.php';
require_once __DIR__ . '/../includes/logger.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

$deliveryOrderId = filter_input(
    INPUT_GET,
    'do',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$nonce = strtolower(trim(
    (string) ($_GET['nonce'] ?? '')
));
$signature = strtolower(trim(
    (string) ($_GET['sig'] ?? '')
));

$tokenIsValid =
    $deliveryOrderId !== false &&
    $deliveryOrderId !== null &&
    procurementVerifyReceiptSignature(
        (int) $deliveryOrderId,
        $nonce,
        $signature
    );

if (!$tokenIsValid) {
    http_response_code(403);
}

$deliveryOrderId = $tokenIsValid
    ? (int) $deliveryOrderId
    : 0;
$error = '';
$success = (string) (
    $_SESSION['delivery_receipt_success'] ?? ''
);
unset($_SESSION['delivery_receipt_success']);

function deliveryReceiptLocation(
    int $deliveryOrderId,
    string $nonce,
    string $signature
): string {
    return app_path('admin/delivery_receipt.php') .
        '?' .
        http_build_query([
            'do' => $deliveryOrderId,
            'nonce' => $nonce,
            'sig' => $signature,
        ]);
}

function loadDeliveryReceipt(
    PDO $pdo,
    int $deliveryOrderId,
    string $nonce
): ?array {
    $statement = $pdo->prepare("
        SELECT
            delivery.*,
            po.po_number,
            po.po_status,
            supplier.supplier_name,
            gr.gr_id,
            gr.gr_number,
            gr.gr_status,
            gr.gr_received_at,
            CONCAT_WS(
                ' ',
                receiver.user_first_name,
                receiver.user_last_name
            ) AS received_by_name
        FROM delivery_orders delivery
        JOIN purchase_orders po
            ON po.po_id = delivery.do_po_id
        JOIN suppliers supplier
            ON supplier.supplier_id =
                delivery.do_supplier_id
        LEFT JOIN goods_received gr
            ON gr.gr_do_id = delivery.do_id
        LEFT JOIN users receiver
            ON receiver.user_id = delivery.do_received_by
        WHERE delivery.do_id = ?
        AND delivery.do_receipt_nonce = ?
        LIMIT 1
    ");
    $statement->execute([
        $deliveryOrderId,
        $nonce,
    ]);

    $result = $statement->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

function loadDeliveryReceiptItems(
    PDO $pdo,
    int $deliveryOrderId
): array {
    $statement = $pdo->prepare("
        SELECT
            doi.doi_id,
            doi.doi_po_item_id,
            doi.doi_product_id,
            doi.doi_quantity,
            p.product_title,
            p.product_volume_number,
            COALESCE(gri.gri_received_quantity, 0)
                AS received_quantity,
            COALESCE(gri.gri_rejected_quantity, 0)
                AS rejected_quantity,
            gri.gri_reject_reason
        FROM delivery_order_items doi
        JOIN products p
            ON p.product_id = doi.doi_product_id
        LEFT JOIN goods_received gr
            ON gr.gr_do_id = doi.doi_do_id
        LEFT JOIN goods_received_items gri
            ON gri.gri_gr_id = gr.gr_id
            AND gri.gri_po_item_id =
                doi.doi_po_item_id
        WHERE doi.doi_do_id = ?
        ORDER BY doi.doi_id
    ");
    $statement->execute([$deliveryOrderId]);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

$deliveryOrder = null;
$deliveryItems = [];

if ($tokenIsValid) {
    $deliveryOrder = loadDeliveryReceipt(
        $pdo,
        $deliveryOrderId,
        $nonce
    );

    if (!$deliveryOrder) {
        $tokenIsValid = false;
        http_response_code(404);
    } else {
        $deliveryItems = loadDeliveryReceiptItems(
            $pdo,
            $deliveryOrderId
        );
    }
}

if (
    $tokenIsValid &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['confirm_receipt'])
) {
    csrf_verify();

    $receivedQuantities = is_array(
        $_POST['received_qty'] ?? null
    )
        ? $_POST['received_qty']
        : [];
    $rejectedQuantities = is_array(
        $_POST['rejected_qty'] ?? null
    )
        ? $_POST['rejected_qty']
        : [];
    $rejectionReasons = is_array(
        $_POST['reject_reason'] ?? null
    )
        ? $_POST['reject_reason']
        : [];

    try {
        $pdo->beginTransaction();

        $lockDeliveryOrder = $pdo->prepare("
            SELECT
                do_id,
                do_po_id,
                do_supplier_id,
                do_status,
                do_receipt_nonce
            FROM delivery_orders
            WHERE do_id = ?
            FOR UPDATE
        ");
        $lockDeliveryOrder->execute([
            $deliveryOrderId,
        ]);
        $lockedDeliveryOrder =
            $lockDeliveryOrder->fetch(PDO::FETCH_ASSOC);

        if (
            !$lockedDeliveryOrder ||
            !hash_equals(
                (string) $lockedDeliveryOrder[
                    'do_receipt_nonce'
                ],
                $nonce
            )
        ) {
            throw new RuntimeException(
                'This delivery receipt link is invalid.'
            );
        }

        if (
            $lockedDeliveryOrder['do_status'] ===
            'received'
        ) {
            $pdo->commit();
            $_SESSION['delivery_receipt_success'] =
                'This delivery order was already received. No stock was added again.';
            redirect_to(
                deliveryReceiptLocation(
                    $deliveryOrderId,
                    $nonce,
                    $signature
                )
            );
        }

        $poId = (int) $lockedDeliveryOrder['do_po_id'];

        $lockPo = $pdo->prepare("
            SELECT po_id
            FROM purchase_orders
            WHERE po_id = ?
            AND po_status = 'confirmed'
            FOR UPDATE
        ");
        $lockPo->execute([$poId]);

        if (!$lockPo->fetchColumn()) {
            throw new RuntimeException(
                'The linked purchase order is no longer open for receiving.'
            );
        }

        $lockItems = $pdo->prepare("
            SELECT
                doi.doi_id,
                doi.doi_po_item_id,
                doi.doi_product_id,
                doi.doi_quantity,
                pi.po_item_quantity,
                pi.po_item_received_quantity,
                pi.po_item_rejected_quantity,
                pi.po_item_unit_price
            FROM delivery_order_items doi
            JOIN po_items pi
                ON pi.po_item_id = doi.doi_po_item_id
                AND pi.po_item_po_id = ?
                AND pi.po_item_product_id =
                    doi.doi_product_id
            WHERE doi.doi_do_id = ?
            ORDER BY doi.doi_id
            FOR UPDATE
        ");
        $lockItems->execute([
            $poId,
            $deliveryOrderId,
        ]);
        $lockedItems = $lockItems->fetchAll(
            PDO::FETCH_ASSOC
        );

        if (!$lockedItems) {
            throw new RuntimeException(
                'No delivery order items were found.'
            );
        }

        $processedItems = [];
        $returnItems = [];

        foreach ($lockedItems as $lockedItem) {
            $deliveryItemId = (int) $lockedItem['doi_id'];
            $declaredQuantity =
                (int) $lockedItem['doi_quantity'];

            $received = filter_var(
                $receivedQuantities[$deliveryItemId] ?? null,
                FILTER_VALIDATE_INT,
                [
                    'options' => [
                        'min_range' => 0,
                        'max_range' => $declaredQuantity,
                    ],
                ]
            );
            $rejected = filter_var(
                $rejectedQuantities[$deliveryItemId] ?? null,
                FILTER_VALIDATE_INT,
                [
                    'options' => [
                        'min_range' => 0,
                        'max_range' => $declaredQuantity,
                    ],
                ]
            );

            if ($received === false || $rejected === false) {
                throw new RuntimeException(
                    'Enter valid received and rejected quantities.'
                );
            }

            $received = (int) $received;
            $rejected = (int) $rejected;

            if (
                $received + $rejected !==
                $declaredQuantity
            ) {
                throw new RuntimeException(
                    'Every delivered unit must be classified as good or rejected before confirming receipt.'
                );
            }

            $reason = trim((string) (
                $rejectionReasons[$deliveryItemId] ?? ''
            ));
            $reasonLength = function_exists('mb_strlen')
                ? mb_strlen($reason, 'UTF-8')
                : strlen($reason);

            if ($rejected > 0 && $reason === '') {
                throw new RuntimeException(
                    'A rejection reason is required for damaged or rejected items.'
                );
            }

            if ($reasonLength > 255) {
                throw new RuntimeException(
                    'A rejection reason cannot exceed 255 characters.'
                );
            }

            $previouslyReceived =
                (int) $lockedItem[
                    'po_item_received_quantity'
                ];
            $previouslyRejected =
                (int) $lockedItem[
                    'po_item_rejected_quantity'
                ];
            $remainingPoQuantity = max(
                0,
                (int) $lockedItem['po_item_quantity'] -
                    $previouslyReceived -
                    $previouslyRejected
            );

            if ($declaredQuantity > $remainingPoQuantity) {
                throw new RuntimeException(
                    'This delivery order exceeds the purchase order quantity still remaining.'
                );
            }

            $processedItems[] = [
                'po_item_id' =>
                    (int) $lockedItem['doi_po_item_id'],
                'product_id' =>
                    (int) $lockedItem['doi_product_id'],
                'received' => $received,
                'rejected' => $rejected,
                'reason' => $reason,
                'unit_price' =>
                    $lockedItem['po_item_unit_price'],
                'previously_received' =>
                    $previouslyReceived,
                'previously_rejected' =>
                    $previouslyRejected,
            ];

            if ($rejected > 0) {
                $returnItems[] = [
                    'product_id' =>
                        (int) $lockedItem[
                            'doi_product_id'
                        ],
                    'quantity' => $rejected,
                    'reason' => $reason,
                    'unit_price' =>
                        $lockedItem['po_item_unit_price'],
                ];
            }
        }

        $existingReceipt = $pdo->prepare("
            SELECT gr_id
            FROM goods_received
            WHERE gr_do_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $existingReceipt->execute([$deliveryOrderId]);

        if ($existingReceipt->fetchColumn()) {
            throw new RuntimeException(
                'This delivery order has already been received.'
            );
        }

        $temporaryGrNumber =
            'TMP-' . bin2hex(random_bytes(6));
        $insertGr = $pdo->prepare("
            INSERT INTO goods_received (
                gr_po_id,
                gr_do_id,
                gr_number,
                gr_received_by,
                gr_status
            )
            VALUES (?, ?, ?, ?, 'pending')
        ");
        $insertGr->execute([
            $poId,
            $deliveryOrderId,
            $temporaryGrNumber,
            current_user_id(),
        ]);

        $grId = (int) $pdo->lastInsertId();
        $grNumber =
            'GR-' .
            str_pad(
                (string) $grId,
                4,
                '0',
                STR_PAD_LEFT
            );

        $setGrNumber = $pdo->prepare("
            UPDATE goods_received
            SET gr_number = ?
            WHERE gr_id = ?
            AND gr_number = ?
        ");
        $setGrNumber->execute([
            $grNumber,
            $grId,
            $temporaryGrNumber,
        ]);

        if ($setGrNumber->rowCount() !== 1) {
            throw new RuntimeException(
                'Unable to generate the goods received number.'
            );
        }

        $insertGrItem = $pdo->prepare("
            INSERT INTO goods_received_items (
                gri_gr_id,
                gri_po_item_id,
                gri_received_quantity,
                gri_rejected_quantity,
                gri_reject_reason
            )
            VALUES (?, ?, ?, ?, ?)
        ");
        $updatePoItem = $pdo->prepare("
            UPDATE po_items
            SET po_item_received_quantity =
                    po_item_received_quantity + ?,
                po_item_rejected_quantity =
                    po_item_rejected_quantity + ?
            WHERE po_item_id = ?
            AND po_item_po_id = ?
            AND po_item_received_quantity = ?
            AND po_item_rejected_quantity = ?
        ");
        $updateStock = $pdo->prepare("
            UPDATE product_physical
            SET physical_stock_quantity =
                physical_stock_quantity + ?
            WHERE physical_product_id = ?
        ");

        foreach ($processedItems as $processedItem) {
            $insertGrItem->execute([
                $grId,
                $processedItem['po_item_id'],
                $processedItem['received'],
                $processedItem['rejected'],
                $processedItem['rejected'] > 0
                    ? $processedItem['reason']
                    : null,
            ]);

            $updatePoItem->execute([
                $processedItem['received'],
                $processedItem['rejected'],
                $processedItem['po_item_id'],
                $poId,
                $processedItem['previously_received'],
                $processedItem['previously_rejected'],
            ]);

            if ($updatePoItem->rowCount() !== 1) {
                throw new RuntimeException(
                    'Unable to update a purchase order item.'
                );
            }

            if ($processedItem['received'] > 0) {
                $updateStock->execute([
                    $processedItem['received'],
                    $processedItem['product_id'],
                ]);

                if ($updateStock->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Unable to update product stock.'
                    );
                }
            }
        }

        $returnNumber = null;

        if ($returnItems) {
            $temporaryReturnNumber =
                'TMP-' . bin2hex(random_bytes(6));
            $insertReturn = $pdo->prepare("
                INSERT INTO supplier_returns (
                    return_number,
                    return_po_id,
                    return_gr_id,
                    return_status
                )
                VALUES (?, ?, ?, 'pending')
            ");
            $insertReturn->execute([
                $temporaryReturnNumber,
                $poId,
                $grId,
            ]);

            $returnId = (int) $pdo->lastInsertId();
            $returnNumber =
                'RTN-' .
                str_pad(
                    (string) $returnId,
                    4,
                    '0',
                    STR_PAD_LEFT
                );

            $setReturnNumber = $pdo->prepare("
                UPDATE supplier_returns
                SET return_number = ?
                WHERE return_id = ?
                AND return_number = ?
            ");
            $setReturnNumber->execute([
                $returnNumber,
                $returnId,
                $temporaryReturnNumber,
            ]);

            if ($setReturnNumber->rowCount() !== 1) {
                throw new RuntimeException(
                    'Unable to generate the supplier return number.'
                );
            }

            $insertReturnItem = $pdo->prepare("
                INSERT INTO supplier_return_items (
                    return_item_return_id,
                    return_item_product_id,
                    return_item_quantity,
                    return_item_reason,
                    return_item_unit_price
                )
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($returnItems as $returnItem) {
                $insertReturnItem->execute([
                    $returnId,
                    $returnItem['product_id'],
                    $returnItem['quantity'],
                    $returnItem['reason'],
                    $returnItem['unit_price'],
                ]);
            }
        }

        $remainingStatement = $pdo->prepare("
            SELECT COUNT(*)
            FROM po_items
            WHERE po_item_po_id = ?
            AND (
                po_item_received_quantity +
                po_item_rejected_quantity
            ) < po_item_quantity
        ");
        $remainingStatement->execute([$poId]);
        $poIsComplete =
            (int) $remainingStatement->fetchColumn() === 0;
        $grStatus = $poIsComplete
            ? 'completed'
            : 'partial';

        $updateGr = $pdo->prepare("
            UPDATE goods_received
            SET gr_status = ?
            WHERE gr_id = ?
        ");
        $updateGr->execute([
            $grStatus,
            $grId,
        ]);

        if ($updateGr->rowCount() !== 1) {
            throw new RuntimeException(
                'Unable to update the goods received record.'
            );
        }

        $markDeliveryReceived = $pdo->prepare("
            UPDATE delivery_orders
            SET do_status = 'received',
                do_received_by = ?,
                do_received_at = NOW()
            WHERE do_id = ?
            AND do_status = 'issued'
            AND do_receipt_nonce = ?
        ");
        $markDeliveryReceived->execute([
            current_user_id(),
            $deliveryOrderId,
            $nonce,
        ]);

        if ($markDeliveryReceived->rowCount() !== 1) {
            throw new RuntimeException(
                'Unable to complete the delivery order receipt.'
            );
        }

        if ($poIsComplete) {
            $payableTotalStatement = $pdo->prepare("
                SELECT SUM(
                    po_item_received_quantity *
                    po_item_unit_price
                )
                FROM po_items
                WHERE po_item_po_id = ?
            ");
            $payableTotalStatement->execute([$poId]);
            $payableTotalSen = moneyDecimalToSen(
                (string) (
                    $payableTotalStatement->fetchColumn()
                    ?: '0.00'
                )
            );

            $completePo = $pdo->prepare("
                UPDATE purchase_orders
                SET po_status = 'completed',
                    po_total_amount = ?
                WHERE po_id = ?
                AND po_status = 'confirmed'
            ");
            $completePo->execute([
                moneySenToDecimal($payableTotalSen),
                $poId,
            ]);

            if ($completePo->rowCount() !== 1) {
                throw new RuntimeException(
                    'Unable to complete the purchase order.'
                );
            }
        }

        $pdo->commit();

        $message =
            $grNumber .
            ' recorded. Good quantities were added to stock.';

        if ($returnNumber !== null) {
            $message .=
                ' ' .
                $returnNumber .
                ' was created for rejected items.';
        }

        if (!$poIsComplete) {
            $message .=
                ' The purchase order remains open for another delivery.';
        }

        $_SESSION['delivery_receipt_success'] = $message;
        redirect_to(
            deliveryReceiptLocation(
                $deliveryOrderId,
                $nonce,
                $signature
            )
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e instanceof RuntimeException) {
            $error = $e->getMessage();
        } else {
            app_error_log(
                'Secure delivery receipt failed: ' .
                $e->getMessage()
            );
            $error =
                'Unable to record this delivery receipt. Please try again.';
        }
    }

    $deliveryOrder = loadDeliveryReceipt(
        $pdo,
        $deliveryOrderId,
        $nonce
    );
    $deliveryItems = loadDeliveryReceiptItems(
        $pdo,
        $deliveryOrderId
    );
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
    <title>Secure Delivery Receipt - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-5xl mx-auto px-6 py-8">
        <?php if (!$tokenIsValid || !$deliveryOrder): ?>
            <div
                class="max-w-xl mx-auto bg-white rounded-2xl shadow-sm p-8 text-center"
            >
                <div class="text-5xl mb-4">🔒</div>
                <h1 class="text-2xl font-black text-gray-800">
                    Invalid Delivery Receipt QR
                </h1>
                <p class="text-gray-500 text-sm mt-3">
                    This QR code is invalid, incomplete or not issued
                    by MangaVault. No stock has been updated.
                </p>
                <a
                    href="purchase_orders.php"
                    class="inline-flex mt-6 bg-[#1e2d4a] text-white font-bold px-5 py-2.5 rounded-xl text-sm"
                >
                    Return to Purchase Orders
                </a>
            </div>
        <?php else: ?>
            <p class="text-sm text-gray-400 mb-6">
                <a
                    href="purchase_orders.php"
                    class="hover:text-red-600"
                >
                    Purchase Orders
                </a>
                <span class="mx-2">›</span>
                <span class="text-gray-600">
                    Secure Receipt —
                    <?= htmlspecialchars(
                        $deliveryOrder['do_number']
                    ) ?>
                </span>
            </p>

            <div class="flex items-start justify-between gap-6 mb-6">
                <div>
                    <h1 class="text-2xl font-black text-gray-800">
                        📦 Secure Delivery Receipt
                    </h1>
                    <p class="text-gray-500 text-sm mt-1">
                        <?= htmlspecialchars(
                            $deliveryOrder['do_number']
                        ) ?>
                        for
                        <?= htmlspecialchars(
                            $deliveryOrder['po_number']
                        ) ?>
                        ·
                        <?= htmlspecialchars(
                            $deliveryOrder['supplier_name']
                        ) ?>
                    </p>
                </div>
                <span
                    class="rounded-full px-4 py-2 text-xs font-bold <?= $deliveryOrder['do_status'] === 'received' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' ?>"
                >
                    <?= $deliveryOrder['do_status'] === 'received'
                        ? 'RECEIVED'
                        : 'AWAITING RECEIPT' ?>
                </span>
            </div>

            <?php if ($success !== ''): ?>
                <div
                    class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-6"
                >
                    ✅ <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div
                    class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-6"
                >
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if (
                $deliveryOrder['do_status'] === 'received'
            ): ?>
                <div
                    class="bg-green-50 border border-green-200 rounded-2xl p-6 mb-6"
                >
                    <h2 class="font-black text-green-800 text-lg">
                        ✅ Delivery Already Completed
                    </h2>
                    <p class="text-sm text-green-700 mt-2">
                        This QR has already been processed. Scanning it
                        again only displays this receipt and will not
                        add stock again.
                    </p>
                    <div
                        class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-5"
                    >
                        <div class="bg-white/70 rounded-xl p-4">
                            <p class="text-xs text-gray-400 uppercase font-semibold">
                                Goods Receipt
                            </p>
                            <p class="font-bold text-gray-800 mt-1">
                                <?= htmlspecialchars(
                                    (string) (
                                        $deliveryOrder[
                                            'gr_number'
                                        ] ?? '—'
                                    )
                                ) ?>
                            </p>
                        </div>
                        <div class="bg-white/70 rounded-xl p-4">
                            <p class="text-xs text-gray-400 uppercase font-semibold">
                                Received By
                            </p>
                            <p class="font-bold text-gray-800 mt-1">
                                <?= htmlspecialchars(
                                    (string) (
                                        $deliveryOrder[
                                            'received_by_name'
                                        ] ?: 'Administrator'
                                    )
                                ) ?>
                            </p>
                        </div>
                        <div class="bg-white/70 rounded-xl p-4">
                            <p class="text-xs text-gray-400 uppercase font-semibold">
                                Received At
                            </p>
                            <p class="font-bold text-gray-800 mt-1">
                                <?= !empty(
                                    $deliveryOrder[
                                        'gr_received_at'
                                    ]
                                )
                                    ? date(
                                        'd M Y, h:i A',
                                        strtotime(
                                            $deliveryOrder[
                                                'gr_received_at'
                                            ]
                                        )
                                    )
                                    : '—' ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div
                    class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-6"
                >
                    <p class="text-sm text-blue-700">
                        The quantities below come from this signed
                        delivery order. Every delivered unit must be
                        classified before confirmation. Only good
                        quantities are added to stock.
                    </p>
                </div>
            <?php endif; ?>

            <form method="POST">
                <?php if (
                    $deliveryOrder['do_status'] !== 'received'
                ): ?>
                    <?php csrf_field(); ?>
                    <input
                        type="hidden"
                        name="confirm_receipt"
                        value="1"
                    >
                <?php endif; ?>

                <div
                    class="bg-white rounded-2xl shadow-sm overflow-hidden mb-6"
                >
                    <table class="w-full">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">
                                    Product
                                </th>
                                <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">
                                    Delivered
                                </th>
                                <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">
                                    Good Qty
                                </th>
                                <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">
                                    Rejected Qty
                                </th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">
                                    Reason
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (
                                $deliveryItems as $item
                            ): ?>
                                <tr class="border-t border-gray-50">
                                    <td class="px-5 py-4 text-sm font-semibold text-gray-800">
                                        <?= htmlspecialchars(
                                            $item['product_title']
                                        ) ?>
                                        <?php if (
                                            $item[
                                                'product_volume_number'
                                            ] !== null
                                        ): ?>
                                            <span class="text-gray-400 font-normal">
                                                (Vol.<?= (int) $item[
                                                    'product_volume_number'
                                                ] ?>)
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-center text-sm font-bold text-blue-700">
                                        <?= (int) $item[
                                            'doi_quantity'
                                        ] ?>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <?php if (
                                            $deliveryOrder[
                                                'do_status'
                                            ] === 'received'
                                        ): ?>
                                            <span class="font-bold text-green-700">
                                                <?= (int) $item[
                                                    'received_quantity'
                                                ] ?>
                                            </span>
                                        <?php else: ?>
                                            <input
                                                type="number"
                                                name="received_qty[<?= (int) $item['doi_id'] ?>]"
                                                min="0"
                                                max="<?= (int) $item['doi_quantity'] ?>"
                                                value="<?= (int) $item['doi_quantity'] ?>"
                                                required
                                                class="good-qty w-20 px-3 py-2 border-2 border-green-100 rounded-xl text-sm text-center focus:outline-none focus:border-green-400"
                                                data-item="<?= (int) $item['doi_id'] ?>"
                                                data-declared="<?= (int) $item['doi_quantity'] ?>"
                                                oninput="syncReceiptQuantity(<?= (int) $item['doi_id'] ?>, 'good')"
                                            >
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <?php if (
                                            $deliveryOrder[
                                                'do_status'
                                            ] === 'received'
                                        ): ?>
                                            <span class="font-bold text-red-600">
                                                <?= (int) $item[
                                                    'rejected_quantity'
                                                ] ?>
                                            </span>
                                        <?php else: ?>
                                            <input
                                                type="number"
                                                name="rejected_qty[<?= (int) $item['doi_id'] ?>]"
                                                min="0"
                                                max="<?= (int) $item['doi_quantity'] ?>"
                                                value="0"
                                                required
                                                class="rejected-qty w-20 px-3 py-2 border-2 border-red-100 rounded-xl text-sm text-center focus:outline-none focus:border-red-400"
                                                data-item="<?= (int) $item['doi_id'] ?>"
                                                data-declared="<?= (int) $item['doi_quantity'] ?>"
                                                oninput="syncReceiptQuantity(<?= (int) $item['doi_id'] ?>, 'rejected')"
                                            >
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <?php if (
                                            $deliveryOrder[
                                                'do_status'
                                            ] === 'received'
                                        ): ?>
                                            <span class="text-sm text-gray-500">
                                                <?= htmlspecialchars(
                                                    (string) (
                                                        $item[
                                                            'gri_reject_reason'
                                                        ] ?: '—'
                                                    )
                                                ) ?>
                                            </span>
                                        <?php else: ?>
                                            <input
                                                type="text"
                                                name="reject_reason[<?= (int) $item['doi_id'] ?>]"
                                                maxlength="255"
                                                placeholder="Required when rejected"
                                                class="w-full px-3 py-2 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400"
                                            >
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (
                    $deliveryOrder['do_status'] !== 'received'
                ): ?>
                    <button
                        type="submit"
                        onclick="return confirm('Confirm this delivery receipt? This action updates stock and cannot be submitted twice.')"
                        class="bg-red-600 hover:bg-red-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm"
                    >
                        Confirm Receipt & Update Stock
                    </button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>

    <script>
    function syncReceiptQuantity(itemId, changedField) {
        const goodInput = document.querySelector(
            `.good-qty[data-item="${itemId}"]`
        );
        const rejectedInput = document.querySelector(
            `.rejected-qty[data-item="${itemId}"]`
        );

        if (!goodInput || !rejectedInput) {
            return;
        }

        const declared = Number.parseInt(
            goodInput.dataset.declared,
            10
        );
        let good = Number.parseInt(goodInput.value, 10) || 0;
        let rejected =
            Number.parseInt(rejectedInput.value, 10) || 0;

        good = Math.max(0, Math.min(declared, good));
        rejected = Math.max(0, Math.min(declared, rejected));

        if (changedField === 'good') {
            rejected = declared - good;
        } else {
            good = declared - rejected;
        }

        goodInput.value = good;
        rejectedInput.value = rejected;
    }
    </script>
</body>
</html>
