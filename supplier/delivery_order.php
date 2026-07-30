<?php

require_once __DIR__ . '/../includes/auth.php';
require_supplier();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ .
    '/../includes/procurement_receipt_helper.php';

use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dompdf\Dompdf;

date_default_timezone_set('Asia/Kuala_Lumpur');

$supplierId = (int) ($_SESSION['supplier_id'] ?? 0);
$poId = filter_input(
    INPUT_GET,
    'po_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($poId === false || $poId === null) {
    redirect_to(app_path('supplier/purchase_orders.php'));
}

$poId = (int) $poId;
$error = '';
$success = (string) (
    $_SESSION['supplier_do_success'] ?? ''
);
unset($_SESSION['supplier_do_success']);

$poStatement = $pdo->prepare("
    SELECT
        po.*,
        s.supplier_name,
        s.supplier_phone
    FROM purchase_orders po
    JOIN suppliers s
        ON s.supplier_id = po.po_supplier_id
    WHERE po.po_id = ?
    AND po.po_supplier_id = ?
    AND po.po_status IN ('confirmed', 'completed')
    LIMIT 1
");
$poStatement->execute([
    $poId,
    $supplierId,
]);
$po = $poStatement->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    redirect_to(app_path('supplier/purchase_orders.php'));
}

if (
    $po['po_status'] === 'confirmed' &&
    empty($po['po_acknowledged_at'])
) {
    redirect_to(
        app_path('supplier/po_detail.php') .
        '?id=' . $poId .
        '&must_acknowledge=1'
    );
}

function loadSupplierPoItems(
    PDO $pdo,
    int $poId
): array {
    $statement = $pdo->prepare("
        SELECT
            pi.po_item_id,
            pi.po_item_product_id,
            pi.po_item_quantity,
            pi.po_item_received_quantity,
            pi.po_item_rejected_quantity,
            pi.po_item_unit_price,
            p.product_title,
            p.product_volume_number,
            COALESCE((
                SELECT SUM(doi.doi_quantity)
                FROM delivery_order_items doi
                JOIN delivery_orders delivery
                    ON delivery.do_id = doi.doi_do_id
                WHERE doi.doi_po_item_id = pi.po_item_id
                AND delivery.do_status = 'issued'
            ), 0) AS pending_delivery_quantity
        FROM po_items pi
        JOIN products p
            ON p.product_id = pi.po_item_product_id
        WHERE pi.po_item_po_id = ?
        ORDER BY pi.po_item_id
    ");
    $statement->execute([$poId]);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function loadSupplierDeliveryOrders(
    PDO $pdo,
    int $poId,
    int $supplierId
): array {
    $statement = $pdo->prepare("
        SELECT
            delivery.*,
            gr.gr_number,
            gr.gr_status,
            CONCAT_WS(
                ' ',
                receiver.user_first_name,
                receiver.user_last_name
            ) AS received_by_name,
            COALESCE(SUM(doi.doi_quantity), 0)
                AS total_quantity
        FROM delivery_orders delivery
        LEFT JOIN delivery_order_items doi
            ON doi.doi_do_id = delivery.do_id
        LEFT JOIN goods_received gr
            ON gr.gr_do_id = delivery.do_id
        LEFT JOIN users receiver
            ON receiver.user_id = delivery.do_received_by
        WHERE delivery.do_po_id = ?
        AND delivery.do_supplier_id = ?
        GROUP BY delivery.do_id
        ORDER BY delivery.do_id DESC
    ");
    $statement->execute([
        $poId,
        $supplierId,
    ]);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

$downloadDeliveryOrderId = filter_input(
    INPUT_GET,
    'download_pdf',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (
    $downloadDeliveryOrderId !== false &&
    $downloadDeliveryOrderId !== null
) {
    try {
        require_once __DIR__ . '/../vendor/autoload.php';

        $deliveryStatement = $pdo->prepare("
            SELECT
                delivery.*,
                po.po_number,
                supplier.supplier_name,
                supplier.supplier_phone,
                gr.gr_number
            FROM delivery_orders delivery
            JOIN purchase_orders po
                ON po.po_id = delivery.do_po_id
            JOIN suppliers supplier
                ON supplier.supplier_id =
                    delivery.do_supplier_id
            LEFT JOIN goods_received gr
                ON gr.gr_do_id = delivery.do_id
            WHERE delivery.do_id = ?
            AND delivery.do_po_id = ?
            AND delivery.do_supplier_id = ?
            LIMIT 1
        ");
        $deliveryStatement->execute([
            (int) $downloadDeliveryOrderId,
            $poId,
            $supplierId,
        ]);
        $deliveryOrder = $deliveryStatement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$deliveryOrder) {
            throw new RuntimeException(
                'Delivery order not found.'
            );
        }

        $deliveryItemStatement = $pdo->prepare("
            SELECT
                doi.doi_quantity,
                p.product_title,
                p.product_volume_number
            FROM delivery_order_items doi
            JOIN products p
                ON p.product_id = doi.doi_product_id
            WHERE doi.doi_do_id = ?
            ORDER BY doi.doi_id
        ");
        $deliveryItemStatement->execute([
            (int) $deliveryOrder['do_id'],
        ]);
        $deliveryItems = $deliveryItemStatement->fetchAll(
            PDO::FETCH_ASSOC
        );

        $nonce = strtolower(trim(
            (string) $deliveryOrder['do_receipt_nonce']
        ));
        $qrUrl = procurementReceiptUrl(
            (int) $deliveryOrder['do_id'],
            $nonce
        );

        $renderer = new ImageRenderer(
            new RendererStyle(140),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        $qrSvg = $writer->writeString($qrUrl);
        $qrBase64 =
            'data:image/svg+xml;base64,' .
            base64_encode($qrSvg);

        $itemRows = '';
        foreach ($deliveryItems as $deliveryItem) {
            $title = htmlspecialchars(
                (string) $deliveryItem['product_title'],
                ENT_QUOTES,
                'UTF-8'
            );
            $volume = $deliveryItem[
                'product_volume_number'
            ];
            if ($volume !== null && $volume !== '') {
                $title .= ' (Vol.' . (int) $volume . ')';
            }

            $itemRows .=
                "<tr>" .
                "<td style='padding:10px 12px;font-size:12px;border-bottom:1px solid #e5e7eb;'>" .
                $title .
                "</td>" .
                "<td style='padding:10px 12px;font-size:12px;text-align:center;border-bottom:1px solid #e5e7eb;'>" .
                (int) $deliveryItem['doi_quantity'] .
                "</td>" .
                "</tr>";
        }

        $deliveryStatus =
            $deliveryOrder['do_status'] === 'received'
                ? 'RECEIVED'
                : 'AWAITING RECEIPT';
        $statusColour =
            $deliveryOrder['do_status'] === 'received'
                ? '#15803d'
                : '#b45309';
        $safeNotes = htmlspecialchars(
            (string) ($deliveryOrder['do_notes'] ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
        $safeDoNumber = htmlspecialchars(
            (string) $deliveryOrder['do_number'],
            ENT_QUOTES,
            'UTF-8'
        );
        $safePoNumber = htmlspecialchars(
            (string) $deliveryOrder['po_number'],
            ENT_QUOTES,
            'UTF-8'
        );
        $safeSupplierName = htmlspecialchars(
            (string) $deliveryOrder['supplier_name'],
            ENT_QUOTES,
            'UTF-8'
        );
        $safeSupplierPhone = htmlspecialchars(
            (string) (
                $deliveryOrder['supplier_phone'] ?? ''
            ),
            ENT_QUOTES,
            'UTF-8'
        );

        $receiptText =
            $deliveryOrder['do_status'] === 'received'
                ? 'Scan to view completed receipt'
                : 'Secure scan to confirm receipt';

        $html = "
        <!DOCTYPE html>
        <html>
        <head><meta charset='UTF-8'></head>
        <body style='font-family:Arial,sans-serif;margin:0;padding:30px;color:#111827;'>
            <div style='background:#1e2d4a;padding:24px;border-radius:8px;margin-bottom:30px;'>
                <h1 style='color:#ffffff;font-size:22px;margin:0;font-weight:900;'>MANGA<span style='color:#ef4444;'>VAULT</span></h1>
                <p style='color:rgba(255,255,255,0.7);font-size:12px;margin:4px 0 0;'>Delivery Order</p>
            </div>
            <div style='display:table;width:100%;margin-bottom:24px;'>
                <div style='display:table-cell;width:55%;vertical-align:top;'>
                    <h2 style='font-size:18px;color:#111827;margin:0 0 4px;'>$safeDoNumber</h2>
                    <p style='font-size:12px;color:#6b7280;margin:0;'>For: $safePoNumber</p>
                    <p style='font-size:12px;color:#6b7280;margin:2px 0 0;'>Delivery Date: " .
                        date(
                            'd F Y',
                            strtotime(
                                (string) $deliveryOrder[
                                    'do_delivery_date'
                                ]
                            )
                        ) .
                    "</p>
                    <p style='font-size:11px;color:$statusColour;margin:8px 0 0;font-weight:700;'>$deliveryStatus</p>
                </div>
                <div style='display:table-cell;width:45%;text-align:right;vertical-align:top;'>
                    <img src='$qrBase64' style='width:86px;height:86px;margin-bottom:6px;'>
                    <p style='font-size:9px;color:#9ca3af;margin:0;font-weight:700;'>$receiptText</p>
                </div>
            </div>
            <div style='background:#f9fafb;border-radius:8px;padding:16px;margin-bottom:24px;'>
                <p style='font-size:11px;color:#9ca3af;margin:0 0 6px;text-transform:uppercase;font-weight:700;'>From (Supplier)</p>
                <p style='font-size:14px;font-weight:700;margin:0 0 2px;'>$safeSupplierName</p>
                <p style='font-size:12px;color:#6b7280;margin:0;'>$safeSupplierPhone</p>
            </div>
            <table style='width:100%;border-collapse:collapse;margin-bottom:24px;'>
                <tr style='background:#1e2d4a;color:white;'>
                    <td style='padding:10px 12px;font-size:11px;font-weight:700;'>Product</td>
                    <td style='padding:10px 12px;font-size:11px;font-weight:700;text-align:center;'>Qty Delivered</td>
                </tr>
                $itemRows
            </table>" .
            ($safeNotes !== ''
                ? "<div style='background:#f9fafb;border-radius:8px;padding:12px;margin-bottom:24px;'><p style='font-size:11px;color:#6b7280;margin:0;'>Notes: $safeNotes</p></div>"
                : '') .
            "<div style='border-top:2px solid #f3f4f6;padding-top:16px;margin-top:40px;'>
                <p style='font-size:11px;color:#9ca3af;margin:0;'>The signed QR is bound to this delivery order. A completed delivery cannot be recorded again.</p>
                <p style='font-size:11px;color:#9ca3af;margin:4px 0 0;'>Generated on " .
                    date('d F Y, h:i A') .
                "</p>
            </div>
        </body>
        </html>";

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream(
            $deliveryOrder['do_number'] . '.pdf',
            ['Attachment' => true]
        );
        exit;
    } catch (Throwable $e) {
        app_error_log(
            'Delivery order PDF generation failed: ' .
            $e->getMessage()
        );
        $error =
            $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to generate the delivery order PDF.';
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['create_do'])
) {
    csrf_verify();

    try {
        if ($po['po_status'] !== 'confirmed') {
            throw new RuntimeException(
                'This purchase order is no longer open for delivery.'
            );
        }

        $deliveryDateRaw = $_POST['delivery_date'] ?? null;
        $notesRaw = $_POST['notes'] ?? '';
        $quantitiesRaw = $_POST['delivery_qty'] ?? null;

        if (!is_string($deliveryDateRaw)) {
            throw new RuntimeException(
                'Please enter a valid delivery date.'
            );
        }

        $deliveryDate =
            DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $deliveryDateRaw
            );
        $dateErrors = DateTimeImmutable::getLastErrors();

        if (
            !$deliveryDate ||
            (
                is_array($dateErrors) &&
                (
                    $dateErrors['warning_count'] > 0 ||
                    $dateErrors['error_count'] > 0
                )
            ) ||
            $deliveryDate->format('Y-m-d') !==
                $deliveryDateRaw
        ) {
            throw new RuntimeException(
                'Please enter a valid delivery date.'
            );
        }

        if (!is_string($notesRaw)) {
            throw new RuntimeException(
                'Invalid delivery notes.'
            );
        }

        $notes = trim($notesRaw);
        $notesLength = function_exists('mb_strlen')
            ? mb_strlen($notes, 'UTF-8')
            : strlen($notes);

        if ($notesLength > 2000) {
            throw new RuntimeException(
                'Delivery notes cannot exceed 2000 characters.'
            );
        }

        if (!is_array($quantitiesRaw)) {
            throw new RuntimeException(
                'Invalid delivery quantities.'
            );
        }

        // Fail before writing when the server-side QR secret is missing.
        procurementReceiptSecret();

        $pdo->beginTransaction();

        $lockPo = $pdo->prepare("
            SELECT
                po_id,
                po_acknowledged_at
            FROM purchase_orders
            WHERE po_id = ?
            AND po_supplier_id = ?
            AND po_status = 'confirmed'
            FOR UPDATE
        ");
        $lockPo->execute([
            $poId,
            $supplierId,
        ]);
        $lockedPo = $lockPo->fetch(PDO::FETCH_ASSOC);

        if (!$lockedPo) {
            throw new RuntimeException(
                'This purchase order is no longer open for delivery.'
            );
        }

        if (empty($lockedPo['po_acknowledged_at'])) {
            throw new RuntimeException(
                'Acknowledge the purchase order before creating a delivery order.'
            );
        }

        $lockItems = $pdo->prepare("
            SELECT
                po_item_id,
                po_item_product_id,
                po_item_quantity,
                po_item_received_quantity,
                po_item_rejected_quantity
            FROM po_items
            WHERE po_item_po_id = ?
            ORDER BY po_item_id
            FOR UPDATE
        ");
        $lockItems->execute([$poId]);
        $lockedItems = $lockItems->fetchAll(
            PDO::FETCH_ASSOC
        );

        if (!$lockedItems) {
            throw new RuntimeException(
                'No purchase order items were found.'
            );
        }

        $pendingStatement = $pdo->prepare("
            SELECT
                doi.doi_po_item_id,
                SUM(doi.doi_quantity) AS pending_quantity
            FROM delivery_order_items doi
            JOIN delivery_orders delivery
                ON delivery.do_id = doi.doi_do_id
            WHERE delivery.do_po_id = ?
            AND delivery.do_supplier_id = ?
            AND delivery.do_status = 'issued'
            GROUP BY doi.doi_po_item_id
        ");
        $pendingStatement->execute([
            $poId,
            $supplierId,
        ]);

        $pendingByPoItem = [];
        foreach (
            $pendingStatement->fetchAll(PDO::FETCH_ASSOC)
            as $pendingRow
        ) {
            $pendingByPoItem[
                (int) $pendingRow['doi_po_item_id']
            ] = (int) $pendingRow['pending_quantity'];
        }

        $validatedItems = [];
        foreach ($lockedItems as $lockedItem) {
            $poItemId = (int) $lockedItem['po_item_id'];
            $ordered = (int) $lockedItem[
                'po_item_quantity'
            ];
            $processed =
                (int) $lockedItem[
                    'po_item_received_quantity'
                ] +
                (int) $lockedItem[
                    'po_item_rejected_quantity'
                ];
            $pending = $pendingByPoItem[$poItemId] ?? 0;
            $available = max(
                0,
                $ordered - $processed - $pending
            );

            $rawQuantity = $quantitiesRaw[$poItemId] ?? 0;
            $quantity = filter_var(
                $rawQuantity,
                FILTER_VALIDATE_INT,
                [
                    'options' => [
                        'min_range' => 0,
                        'max_range' => $available,
                    ],
                ]
            );

            if ($quantity === false) {
                throw new RuntimeException(
                    'A delivery quantity exceeds the quantity still available for shipment.'
                );
            }

            if ((int) $quantity > 0) {
                $validatedItems[] = [
                    'po_item_id' => $poItemId,
                    'product_id' =>
                        (int) $lockedItem[
                            'po_item_product_id'
                        ],
                    'quantity' => (int) $quantity,
                ];
            }
        }

        if (!$validatedItems) {
            throw new RuntimeException(
                'Enter at least one quantity to deliver.'
            );
        }

        $temporaryNumber =
            'PENDING-' . bin2hex(random_bytes(8));
        $receiptNonce = procurementReceiptNonce();

        $insertDeliveryOrder = $pdo->prepare("
            INSERT INTO delivery_orders (
                do_number,
                do_po_id,
                do_supplier_id,
                do_delivery_date,
                do_notes,
                do_status,
                do_receipt_nonce
            )
            VALUES (?, ?, ?, ?, ?, 'issued', ?)
        ");
        $insertDeliveryOrder->execute([
            $temporaryNumber,
            $poId,
            $supplierId,
            $deliveryDate->format('Y-m-d'),
            $notes !== '' ? $notes : null,
            $receiptNonce,
        ]);

        $deliveryOrderId = (int) $pdo->lastInsertId();
        $deliveryOrderNumber =
            'DO-' .
            str_pad(
                (string) $deliveryOrderId,
                4,
                '0',
                STR_PAD_LEFT
            );

        $setNumber = $pdo->prepare("
            UPDATE delivery_orders
            SET do_number = ?
            WHERE do_id = ?
            AND do_number = ?
        ");
        $setNumber->execute([
            $deliveryOrderNumber,
            $deliveryOrderId,
            $temporaryNumber,
        ]);

        if ($setNumber->rowCount() !== 1) {
            throw new RuntimeException(
                'Unable to generate the delivery order number.'
            );
        }

        $insertItem = $pdo->prepare("
            INSERT INTO delivery_order_items (
                doi_do_id,
                doi_po_item_id,
                doi_product_id,
                doi_quantity
            )
            VALUES (?, ?, ?, ?)
        ");

        foreach ($validatedItems as $validatedItem) {
            $insertItem->execute([
                $deliveryOrderId,
                $validatedItem['po_item_id'],
                $validatedItem['product_id'],
                $validatedItem['quantity'],
            ]);
        }

        $pdo->commit();

        $_SESSION['supplier_do_success'] =
            $deliveryOrderNumber .
            ' created. Download the signed delivery order PDF.';

        redirect_to(
            app_path('supplier/delivery_order.php') .
            '?po_id=' . $poId
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e instanceof RuntimeException) {
            $error = $e->getMessage();
        } else {
            app_error_log(
                'Delivery order creation failed: ' .
                $e->getMessage()
            );
            $error =
                'Unable to create the delivery order.';
        }
    }
}

$items = loadSupplierPoItems($pdo, $poId);
$deliveryOrders = loadSupplierDeliveryOrders(
    $pdo,
    $poId,
    $supplierId
);
$hasAvailableQuantity = false;

foreach ($items as &$item) {
    $processed =
        (int) $item['po_item_received_quantity'] +
        (int) $item['po_item_rejected_quantity'];
    $pending = (int) $item[
        'pending_delivery_quantity'
    ];
    $available = max(
        0,
        (int) $item['po_item_quantity'] -
            $processed -
            $pending
    );

    $item['processed_quantity'] = $processed;
    $item['available_delivery_quantity'] = $available;

    if ($available > 0) {
        $hasAvailableQuantity = true;
    }
}
unset($item);
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
        Delivery Orders -
        <?= htmlspecialchars($po['po_number']) ?>
    </title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">

    <?php include '../includes/supplier_navbar.php'; ?>

    <div class="max-w-5xl mx-auto px-6 py-8">
        <p class="text-sm text-gray-400 mb-6">
            <a
                href="purchase_orders.php"
                class="hover:text-blue-600 transition-colors"
            >
                Purchase Orders
            </a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">
                Delivery Orders —
                <?= htmlspecialchars($po['po_number']) ?>
            </span>
        </p>

        <div class="mb-6">
            <h1 class="text-2xl font-black text-gray-800">
                🚚 Delivery Orders
            </h1>
            <p class="text-gray-500 text-sm mt-1">
                Declare each shipment separately. Quantities already
                delivered or reserved by another open delivery order
                cannot be shipped again.
            </p>
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

        <div
            class="bg-white rounded-2xl shadow-sm overflow-hidden mb-6"
        >
            <div class="px-6 py-4 border-b border-gray-100">
                <h2 class="font-bold text-gray-800">
                    Shipment Progress
                </h2>
            </div>
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">
                            Product
                        </th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">
                            Ordered
                        </th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">
                            Processed
                        </th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">
                            Pending DO
                        </th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">
                            Available
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr class="border-t border-gray-50">
                            <td class="px-4 py-3 text-sm text-gray-700">
                                <?= htmlspecialchars(
                                    $item['product_title']
                                ) ?>
                                <?php if (
                                    $item[
                                        'product_volume_number'
                                    ] !== null
                                ): ?>
                                    <span class="text-gray-400">
                                        (Vol.<?= (int) $item[
                                            'product_volume_number'
                                        ] ?>)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center text-sm">
                                <?= (int) $item[
                                    'po_item_quantity'
                                ] ?>
                            </td>
                            <td class="px-4 py-3 text-center text-sm text-green-700">
                                <?= (int) $item[
                                    'processed_quantity'
                                ] ?>
                            </td>
                            <td class="px-4 py-3 text-center text-sm text-amber-700">
                                <?= (int) $item[
                                    'pending_delivery_quantity'
                                ] ?>
                            </td>
                            <td class="px-4 py-3 text-center text-sm font-bold text-blue-700">
                                <?= (int) $item[
                                    'available_delivery_quantity'
                                ] ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($deliveryOrders): ?>
            <div
                class="bg-white rounded-2xl shadow-sm overflow-hidden mb-6"
            >
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="font-bold text-gray-800">
                        Delivery Order History
                    </h2>
                </div>
                <table class="w-full">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">
                                DO Number
                            </th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">
                                Quantity
                            </th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">
                                Status
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">
                                Receipt
                            </th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">
                                Action
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (
                            $deliveryOrders as $deliveryOrder
                        ): ?>
                            <tr class="border-t border-gray-50">
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-sm text-gray-800">
                                        <?= htmlspecialchars(
                                            $deliveryOrder[
                                                'do_number'
                                            ]
                                        ) ?>
                                    </p>
                                    <p class="text-xs text-gray-400">
                                        <?= date(
                                            'd M Y',
                                            strtotime(
                                                $deliveryOrder[
                                                    'do_delivery_date'
                                                ]
                                            )
                                        ) ?>
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-center text-sm">
                                    <?= (int) $deliveryOrder[
                                        'total_quantity'
                                    ] ?>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span
                                        class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $deliveryOrder['do_status'] === 'received' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' ?>"
                                    >
                                        <?= $deliveryOrder[
                                            'do_status'
                                        ] === 'received'
                                            ? 'Received'
                                            : 'Awaiting Receipt' ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500">
                                    <?php if (
                                        $deliveryOrder[
                                            'gr_number'
                                        ]
                                    ): ?>
                                        <?= htmlspecialchars(
                                            $deliveryOrder[
                                                'gr_number'
                                            ]
                                        ) ?>
                                        <p class="text-xs text-gray-400">
                                            <?= htmlspecialchars(
                                                (string) (
                                                    $deliveryOrder[
                                                        'received_by_name'
                                                    ] ?: 'Admin'
                                                )
                                            ) ?>
                                        </p>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <a
                                        href="?po_id=<?= $poId ?>&download_pdf=<?= (int) $deliveryOrder['do_id'] ?>"
                                        class="text-xs font-semibold text-blue-600 hover:underline"
                                    >
                                        Download PDF
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if (
            $po['po_status'] === 'confirmed' &&
            $hasAvailableQuantity
        ): ?>
            <form method="POST">
                <?php csrf_field(); ?>
                <input
                    type="hidden"
                    name="create_do"
                    value="1"
                >

                <div
                    class="bg-white rounded-2xl shadow-sm p-6 mb-6"
                >
                    <h2 class="font-bold text-gray-800 mb-4">
                        Create Next Shipment
                    </h2>

                    <div class="mb-5">
                        <label
                            class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide"
                        >
                            Delivery Date *
                        </label>
                        <input
                            type="date"
                            name="delivery_date"
                            required
                            value="<?= date('Y-m-d') ?>"
                            class="w-full px-4 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-blue-400"
                        >
                    </div>

                    <table class="w-full mb-5">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase">
                                    Product
                                </th>
                                <th class="px-3 py-2 text-center text-xs font-semibold text-gray-500 uppercase">
                                    Available
                                </th>
                                <th class="px-3 py-2 text-center text-xs font-semibold text-gray-500 uppercase">
                                    Delivering Now
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr class="border-t border-gray-50">
                                    <td class="px-3 py-3 text-sm text-gray-700">
                                        <?= htmlspecialchars(
                                            $item['product_title']
                                        ) ?>
                                    </td>
                                    <td class="px-3 py-3 text-center text-sm text-gray-500">
                                        <?= (int) $item[
                                            'available_delivery_quantity'
                                        ] ?>
                                    </td>
                                    <td class="px-3 py-3 text-center">
                                        <input
                                            type="number"
                                            name="delivery_qty[<?= (int) $item['po_item_id'] ?>]"
                                            value="0"
                                            min="0"
                                            max="<?= (int) $item['available_delivery_quantity'] ?>"
                                            <?= (int) $item['available_delivery_quantity'] === 0 ? 'disabled' : '' ?>
                                            class="w-24 px-2 py-1.5 border-2 border-gray-100 rounded-lg text-sm text-center focus:outline-none focus:border-blue-400 disabled:bg-gray-100"
                                        >
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <textarea
                        name="notes"
                        rows="3"
                        maxlength="2000"
                        placeholder="Delivery notes (optional)"
                        class="w-full px-4 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-blue-400 resize-none"
                    ></textarea>
                </div>

                <button
                    type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm transition-colors"
                >
                    Generate Secure Delivery Order
                </button>
            </form>
        <?php elseif ($po['po_status'] === 'completed'): ?>
            <div
                class="bg-green-50 border border-green-200 rounded-xl p-5"
            >
                <p class="font-semibold text-green-700">
                    ✅ This purchase order has been completed.
                </p>
            </div>
        <?php else: ?>
            <div
                class="bg-blue-50 border border-blue-200 rounded-xl p-5"
            >
                <p class="font-semibold text-blue-700">
                    All remaining quantities are already covered by
                    delivery orders or have been processed.
                </p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
