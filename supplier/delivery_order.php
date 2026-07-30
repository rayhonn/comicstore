<?php

require_once __DIR__ . '/../includes/auth.php';
require_supplier();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ .
    '/../includes/procurement_receipt_helper.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

$supplierId = filter_var(
    $_SESSION['supplier_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$poId = filter_input(
    INPUT_GET,
    'po_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (
    $supplierId === false ||
    $supplierId === null ||
    $poId === false ||
    $poId === null
) {
    redirect_to(app_path('supplier/purchase_orders.php'));
}

$supplierId = (int) $supplierId;
$poId = (int) $poId;
$error = '';
$success = (string) ($_SESSION['supplier_do_success'] ?? '');
unset($_SESSION['supplier_do_success']);

function loadSupplierDeliveryPo(
    PDO $pdo,
    int $poId,
    int $supplierId
): ?array {
    $statement = $pdo->prepare("
        SELECT
            po.*,
            supplier.supplier_name,
            supplier.supplier_phone,
            supplier.supplier_email,
            supplier.supplier_address
        FROM purchase_orders po
        JOIN suppliers supplier
            ON supplier.supplier_id = po.po_supplier_id
        WHERE po.po_id = ?
        AND po.po_supplier_id = ?
        AND po.po_status IN ('confirmed', 'completed')
        LIMIT 1
    ");
    $statement->execute([$poId, $supplierId]);

    $result = $statement->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

function loadSupplierDeliveryItems(
    PDO $pdo,
    int $poId
): array {
    $statement = $pdo->prepare("
        SELECT
            item.po_item_id,
            item.po_item_product_id,
            item.po_item_quantity,
            item.po_item_received_quantity,
            item.po_item_rejected_quantity,
            item.po_item_unit_price,
            product.product_title,
            product.product_volume_number,
            COALESCE((
                SELECT SUM(delivery_item.doi_quantity)
                FROM delivery_order_items delivery_item
                JOIN delivery_orders delivery
                    ON delivery.do_id = delivery_item.doi_do_id
                WHERE delivery_item.doi_po_item_id =
                    item.po_item_id
                AND delivery.do_status = 'issued'
            ), 0) AS pending_delivery_quantity
        FROM po_items item
        JOIN products product
            ON product.product_id = item.po_item_product_id
        WHERE item.po_item_po_id = ?
        ORDER BY item.po_item_id
    ");
    $statement->execute([$poId]);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function loadSupplierDeliveryHistory(
    PDO $pdo,
    int $poId,
    int $supplierId
): array {
    $statement = $pdo->prepare("
        SELECT
            delivery.*,
            receipt.gr_number,
            receipt.gr_status,
            CONCAT_WS(
                ' ',
                receiver.user_first_name,
                receiver.user_last_name
            ) AS received_by_name,
            COALESCE(SUM(delivery_item.doi_quantity), 0)
                AS total_quantity
        FROM delivery_orders delivery
        LEFT JOIN delivery_order_items delivery_item
            ON delivery_item.doi_do_id = delivery.do_id
        LEFT JOIN goods_received receipt
            ON receipt.gr_do_id = delivery.do_id
        LEFT JOIN users receiver
            ON receiver.user_id = delivery.do_received_by
        WHERE delivery.do_po_id = ?
        AND delivery.do_supplier_id = ?
        GROUP BY delivery.do_id
        ORDER BY delivery.do_id DESC
    ");
    $statement->execute([$poId, $supplierId]);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function assertDeliveryPdfReady(): void
{
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

    $autoloadPath = __DIR__ . '/../vendor/autoload.php';

    if (!is_file($autoloadPath)) {
        throw new RuntimeException(
            'PDF dependencies are not installed. Run Composer install before creating a delivery order.'
        );
    }

    require_once $autoloadPath;

    $requiredClasses = [
        Dompdf\Dompdf::class,
        BaconQrCode\Renderer\ImageRenderer::class,
        BaconQrCode\Renderer\Image\SvgImageBackEnd::class,
        BaconQrCode\Renderer\RendererStyle\RendererStyle::class,
        BaconQrCode\Writer::class,
    ];

    foreach ($requiredClasses as $requiredClass) {
        if (!class_exists($requiredClass)) {
            throw new RuntimeException(
                'A required PDF or QR library is unavailable. Run Composer install and try again.'
            );
        }
    }
}

function outputDeliveryOrderPdf(
    PDO $pdo,
    int $deliveryOrderId,
    int $poId,
    int $supplierId
): void {
    assertDeliveryPdfReady();

    $deliveryStatement = $pdo->prepare("
        SELECT
            delivery.*,
            po.po_number,
            supplier.supplier_name,
            supplier.supplier_phone,
            supplier.supplier_email,
            supplier.supplier_address,
            receipt.gr_number
        FROM delivery_orders delivery
        JOIN purchase_orders po
            ON po.po_id = delivery.do_po_id
        JOIN suppliers supplier
            ON supplier.supplier_id = delivery.do_supplier_id
        LEFT JOIN goods_received receipt
            ON receipt.gr_do_id = delivery.do_id
        WHERE delivery.do_id = ?
        AND delivery.do_po_id = ?
        AND delivery.do_supplier_id = ?
        LIMIT 1
    ");
    $deliveryStatement->execute([
        $deliveryOrderId,
        $poId,
        $supplierId,
    ]);
    $deliveryOrder = $deliveryStatement->fetch(PDO::FETCH_ASSOC);

    if (!$deliveryOrder) {
        throw new RuntimeException('Delivery order not found.');
    }

    $itemStatement = $pdo->prepare("
        SELECT
            delivery_item.doi_quantity,
            product.product_title,
            product.product_volume_number
        FROM delivery_order_items delivery_item
        JOIN products product
            ON product.product_id = delivery_item.doi_product_id
        WHERE delivery_item.doi_do_id = ?
        ORDER BY delivery_item.doi_id
    ");
    $itemStatement->execute([$deliveryOrderId]);
    $deliveryItems = $itemStatement->fetchAll(PDO::FETCH_ASSOC);

    if (!$deliveryItems) {
        throw new RuntimeException(
            'No items were found for this delivery order.'
        );
    }

    $nonce = strtolower(trim(
        (string) $deliveryOrder['do_receipt_nonce']
    ));
    $qrUrl = procurementReceiptUrl($deliveryOrderId, $nonce);

    $renderer = new BaconQrCode\Renderer\ImageRenderer(
        new BaconQrCode\Renderer\RendererStyle\RendererStyle(180),
        new BaconQrCode\Renderer\Image\SvgImageBackEnd()
    );
    $writer = new BaconQrCode\Writer($renderer);
    $qrDataUri =
        'data:image/svg+xml;base64,' .
        base64_encode($writer->writeString($qrUrl));

    $safe = static fn(mixed $value): string => htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );

    $itemRows = '';
    foreach ($deliveryItems as $deliveryItem) {
        $title = $safe($deliveryItem['product_title']);
        if (
            $deliveryItem['product_volume_number'] !== null &&
            $deliveryItem['product_volume_number'] !== ''
        ) {
            $title .= ' (Vol.' .
                (int) $deliveryItem['product_volume_number'] .
                ')';
        }

        $itemRows .=
            '<tr>' .
            '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;font-size:12px;">' .
            $title .
            '</td>' .
            '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;font-size:12px;text-align:center;">' .
            (int) $deliveryItem['doi_quantity'] .
            '</td>' .
            '</tr>';
    }

    $statusLabel =
        $deliveryOrder['do_status'] === 'received'
            ? 'RECEIVED'
            : 'AWAITING RECEIPT';
    $statusColour =
        $deliveryOrder['do_status'] === 'received'
            ? '#15803d'
            : '#b45309';
    $notes = trim((string) ($deliveryOrder['do_notes'] ?? ''));
    $notesBlock = $notes === ''
        ? ''
        : '<div style="background:#f9fafb;border-radius:8px;padding:12px;margin-bottom:24px;">' .
            '<p style="font-size:11px;color:#6b7280;margin:0;">Notes: ' .
            $safe($notes) .
            '</p></div>';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>' .
        '<body style="font-family:Arial,sans-serif;margin:0;padding:30px;color:#111827;">' .
        '<div style="background:#1e2d4a;padding:24px;border-radius:8px;margin-bottom:28px;">' .
        '<h1 style="color:#fff;font-size:22px;margin:0;font-weight:900;">MANGA<span style="color:#ef4444;">VAULT</span></h1>' .
        '<p style="color:rgba(255,255,255,.7);font-size:12px;margin:4px 0 0;">Delivery Order</p></div>' .
        '<div style="display:table;width:100%;margin-bottom:22px;">' .
        '<div style="display:table-cell;width:60%;vertical-align:top;">' .
        '<h2 style="font-size:18px;margin:0 0 4px;">' .
        $safe($deliveryOrder['do_number']) .
        '</h2><p style="font-size:12px;color:#6b7280;margin:0;">For: ' .
        $safe($deliveryOrder['po_number']) .
        '</p><p style="font-size:12px;color:#6b7280;margin:3px 0 0;">Delivery Date: ' .
        date(
            'd F Y',
            strtotime((string) $deliveryOrder['do_delivery_date'])
        ) .
        '</p><p style="font-size:11px;color:' .
        $statusColour .
        ';font-weight:700;margin:8px 0 0;">' .
        $statusLabel .
        '</p></div>' .
        '<div style="display:table-cell;width:40%;text-align:right;vertical-align:top;">' .
        '<img src="' . $qrDataUri . '" style="width:92px;height:92px;">' .
        '<p style="font-size:9px;color:#9ca3af;margin:4px 0 0;">Secure receipt verification</p>' .
        '</div></div>' .
        '<div style="background:#f9fafb;border-radius:8px;padding:16px;margin-bottom:22px;">' .
        '<p style="font-size:10px;color:#9ca3af;margin:0 0 5px;text-transform:uppercase;font-weight:700;">Supplier</p>' .
        '<p style="font-size:14px;font-weight:700;margin:0 0 2px;">' .
        $safe($deliveryOrder['supplier_name']) .
        '</p><p style="font-size:11px;color:#6b7280;margin:0;">' .
        $safe($deliveryOrder['supplier_phone']) .
        ($deliveryOrder['supplier_email']
            ? ' · ' . $safe($deliveryOrder['supplier_email'])
            : '') .
        '</p></div>' .
        '<table style="width:100%;border-collapse:collapse;margin-bottom:22px;">' .
        '<tr style="background:#1e2d4a;color:#fff;">' .
        '<td style="padding:10px 12px;font-size:11px;font-weight:700;">Product</td>' .
        '<td style="padding:10px 12px;font-size:11px;font-weight:700;text-align:center;">Quantity Delivered</td></tr>' .
        $itemRows .
        '</table>' .
        $notesBlock .
        '<div style="border-top:2px solid #f3f4f6;padding-top:14px;margin-top:34px;">' .
        '<p style="font-size:10px;color:#9ca3af;margin:0;">The signed QR is bound to this delivery order. Each delivery order can be received only once.</p>' .
        '<p style="font-size:10px;color:#9ca3af;margin:4px 0 0;">Generated on ' .
        date('d F Y, h:i A') .
        '</p></div></body></html>';

    $dompdf = new Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream(
        (string) $deliveryOrder['do_number'] . '.pdf',
        ['Attachment' => true]
    );
    exit;
}

$po = loadSupplierDeliveryPo($pdo, $poId, $supplierId);

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
        outputDeliveryOrderPdf(
            $pdo,
            (int) $downloadDeliveryOrderId,
            $poId,
            $supplierId
        );
    } catch (Throwable $e) {
        app_error_log(
            'Delivery order PDF generation failed: ' .
            $e->getMessage()
        );
        $error = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'The delivery order exists, but its PDF could not be generated. Use the history section to try the download again.';
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

        // A secure receipt secret is required before a DO can be created.
        // PDF dependencies are checked only when the supplier downloads the PDF.
        procurementReceiptSecret();

        $deliveryDateRaw = $_POST['delivery_date'] ?? null;
        $notesRaw = $_POST['notes'] ?? '';
        $quantitiesRaw = $_POST['delivery_qty'] ?? null;

        if (!is_string($deliveryDateRaw)) {
            throw new RuntimeException(
                'Please enter a valid delivery date.'
            );
        }

        $deliveryDate = DateTimeImmutable::createFromFormat(
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
            $deliveryDate->format('Y-m-d') !== $deliveryDateRaw
        ) {
            throw new RuntimeException(
                'Please enter a valid delivery date.'
            );
        }

        $today = new DateTimeImmutable('today');
        if ($deliveryDate < $today) {
            throw new RuntimeException(
                'The delivery date cannot be earlier than today.'
            );
        }

        if (!is_string($notesRaw)) {
            throw new RuntimeException('Invalid delivery notes.');
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
        $lockPo->execute([$poId, $supplierId]);
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
        $lockedItems = $lockItems->fetchAll(PDO::FETCH_ASSOC);

        if (!$lockedItems) {
            throw new RuntimeException(
                'No purchase order items were found.'
            );
        }

        $pendingStatement = $pdo->prepare("
            SELECT
                delivery_item.doi_po_item_id,
                SUM(delivery_item.doi_quantity) AS pending_quantity
            FROM delivery_order_items delivery_item
            JOIN delivery_orders delivery
                ON delivery.do_id = delivery_item.doi_do_id
            WHERE delivery.do_po_id = ?
            AND delivery.do_supplier_id = ?
            AND delivery.do_status = 'issued'
            GROUP BY delivery_item.doi_po_item_id
        ");
        $pendingStatement->execute([$poId, $supplierId]);

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
            $ordered = (int) $lockedItem['po_item_quantity'];
            $processed =
                (int) $lockedItem['po_item_received_quantity'] +
                (int) $lockedItem['po_item_rejected_quantity'];
            $pending = $pendingByPoItem[$poItemId] ?? 0;
            $available = max(
                0,
                $ordered - $processed - $pending
            );

            $quantity = filter_var(
                $quantitiesRaw[$poItemId] ?? 0,
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
                        (int) $lockedItem['po_item_product_id'],
                    'quantity' => (int) $quantity,
                ];
            }
        }

        if (!$validatedItems) {
            throw new RuntimeException(
                'No quantity is available for a new delivery order. Download the existing open delivery order from the history section.'
            );
        }

        // Keep the temporary value within delivery_orders.do_number VARCHAR(20).
        $temporaryNumber =
            'TMP-' . bin2hex(random_bytes(6));
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
            ' was created successfully. Use Download PDF in the Delivery Order History below.';

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
                'Unable to create the delivery order. Please try again.';
        }
    }
}

$items = loadSupplierDeliveryItems($pdo, $poId);
$deliveryOrders = loadSupplierDeliveryHistory(
    $pdo,
    $poId,
    $supplierId
);
$hasAvailableQuantity = false;
$hasOpenDeliveryOrder = false;

foreach ($deliveryOrders as $deliveryOrder) {
    if ($deliveryOrder['do_status'] === 'issued') {
        $hasOpenDeliveryOrder = true;
        break;
    }
}

foreach ($items as &$item) {
    $processed =
        (int) $item['po_item_received_quantity'] +
        (int) $item['po_item_rejected_quantity'];
    $pending = (int) $item['pending_delivery_quantity'];
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
        <?= htmlspecialchars((string) $po['po_number']) ?>
    </title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">

    <?php include '../includes/supplier_navbar.php'; ?>

    <div class="max-w-5xl mx-auto px-6 py-8">
        <p class="text-sm text-gray-400 mb-6">
            <a
                href="purchase_orders.php"
                class="hover:text-blue-600"
            >Purchase Orders</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">
                Delivery Orders —
                <?= htmlspecialchars((string) $po['po_number']) ?>
            </span>
        </p>

        <div class="mb-6">
            <h1 class="text-2xl font-black text-gray-800">
                Delivery Orders
            </h1>
            <p class="text-gray-500 text-sm mt-1">
                Create one delivery order for each physical shipment.
                An issued delivery order reserves its declared quantities
                until the receipt is completed.
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

        <?php if ($hasOpenDeliveryOrder): ?>
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-6">
                <p class="font-semibold text-amber-800 text-sm">
                    An issued delivery order is still awaiting receipt.
                </p>
                <p class="text-xs text-amber-700 mt-1">
                    Its quantities cannot be placed on another delivery
                    order. Download the existing document below instead
                    of creating a duplicate.
                </p>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl shadow-sm overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-gray-100">
                <h2 class="font-bold text-gray-800">Shipment Progress</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Product</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Ordered</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Processed</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Open DO</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Available</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr class="border-t border-gray-50">
                                <td class="px-4 py-3 text-sm text-gray-700">
                                    <?= htmlspecialchars((string) $item['product_title']) ?>
                                    <?php if ($item['product_volume_number'] !== null): ?>
                                        <span class="text-gray-400">
                                            (Vol.<?= (int) $item['product_volume_number'] ?>)
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-center text-sm"><?= (int) $item['po_item_quantity'] ?></td>
                                <td class="px-4 py-3 text-center text-sm text-green-700"><?= (int) $item['processed_quantity'] ?></td>
                                <td class="px-4 py-3 text-center text-sm text-amber-700"><?= (int) $item['pending_delivery_quantity'] ?></td>
                                <td class="px-4 py-3 text-center text-sm font-bold text-blue-700"><?= (int) $item['available_delivery_quantity'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($deliveryOrders): ?>
            <div class="bg-white rounded-2xl shadow-sm overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="font-bold text-gray-800">
                        Delivery Order History
                    </h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">DO Number</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Quantity</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Receipt</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Document</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deliveryOrders as $deliveryOrder): ?>
                                <tr class="border-t border-gray-50">
                                    <td class="px-4 py-3">
                                        <p class="font-semibold text-sm text-gray-800">
                                            <?= htmlspecialchars((string) $deliveryOrder['do_number']) ?>
                                        </p>
                                        <p class="text-xs text-gray-400">
                                            <?= date('d M Y', strtotime((string) $deliveryOrder['do_delivery_date'])) ?>
                                        </p>
                                    </td>
                                    <td class="px-4 py-3 text-center text-sm"><?= (int) $deliveryOrder['total_quantity'] ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $deliveryOrder['do_status'] === 'received' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' ?>">
                                            <?= $deliveryOrder['do_status'] === 'received' ? 'Received' : 'Awaiting Receipt' ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-500">
                                        <?php if ($deliveryOrder['gr_number']): ?>
                                            <?= htmlspecialchars((string) $deliveryOrder['gr_number']) ?>
                                            <p class="text-xs text-gray-400">
                                                <?= htmlspecialchars((string) ($deliveryOrder['received_by_name'] ?: 'Admin')) ?>
                                            </p>
                                        <?php else: ?>
                                            Not received
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <a
                                            href="?po_id=<?= $poId ?>&download_pdf=<?= (int) $deliveryOrder['do_id'] ?>"
                                            class="text-xs font-semibold text-blue-600 hover:underline"
                                        >Download PDF</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if (
            $po['po_status'] === 'confirmed' &&
            $hasAvailableQuantity
        ): ?>
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="create_do" value="1">

                <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                    <h2 class="font-bold text-gray-800 mb-4">
                        Create Next Shipment
                    </h2>

                    <div class="mb-5">
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">
                            Delivery Date *
                        </label>
                        <input
                            type="date"
                            name="delivery_date"
                            required
                            min="<?= date('Y-m-d') ?>"
                            value="<?= date('Y-m-d') ?>"
                            class="w-full px-4 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-blue-400"
                        >
                    </div>

                    <div class="overflow-x-auto mb-5">
                        <table class="w-full">
                            <thead>
                                <tr class="bg-gray-50">
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Product</th>
                                    <th class="px-3 py-2 text-center text-xs font-semibold text-gray-500 uppercase">Available</th>
                                    <th class="px-3 py-2 text-center text-xs font-semibold text-gray-500 uppercase">Delivering Now</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr class="border-t border-gray-50">
                                        <td class="px-3 py-3 text-sm text-gray-700">
                                            <?= htmlspecialchars((string) $item['product_title']) ?>
                                        </td>
                                        <td class="px-3 py-3 text-center text-sm text-gray-500">
                                            <?= (int) $item['available_delivery_quantity'] ?>
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
                    </div>

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
                    class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm"
                >
                    Create Delivery Order
                </button>
            </form>
        <?php elseif ($po['po_status'] === 'completed'): ?>
            <div class="bg-green-50 border border-green-200 rounded-xl p-5">
                <p class="font-semibold text-green-700">
                    This purchase order has been completed.
                </p>
            </div>
        <?php else: ?>
            <div class="bg-blue-50 border border-blue-200 rounded-xl p-5">
                <p class="font-semibold text-blue-700">
                    No quantity is available for a new delivery order.
                </p>
                <p class="text-xs text-blue-600 mt-1">
                    Existing issued delivery orders already reserve the
                    remaining quantity, or all quantities have been
                    processed.
                </p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
