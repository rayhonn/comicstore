<?php

require_once __DIR__ . '/../includes/auth.php';
require_supplier();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/config.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

$supplier_id = (int) $_SESSION['supplier_id'];

$po_id = filter_input(
    INPUT_GET,
    'po_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($po_id === false || $po_id === null) {
    header('Location: purchase_orders.php');
    exit;
}

$po_id = (int) $po_id;

$po_statement = $pdo->prepare("
    SELECT *
    FROM purchase_orders
    WHERE po_id = ?
    AND po_supplier_id = ?
    AND po_status = 'confirmed'
    LIMIT 1
");
$po_statement->execute([
    $po_id,
    $supplier_id,
]);
$po = $po_statement->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    header('Location: purchase_orders.php');
    exit;
}

if (empty($po['po_acknowledged_at'])) {
    header(
        'Location: po_detail.php?id=' .
        $po_id .
        '&must_acknowledge=1'
    );
    exit;
}

$item_statement = $pdo->prepare("
    SELECT
        pi.*,
        p.product_title,
        p.product_volume_number
    FROM po_items pi
    JOIN products p
        ON p.product_id = pi.po_item_product_id
    WHERE pi.po_item_po_id = ?
");
$item_statement->execute([$po_id]);
$items = $item_statement->fetchAll(PDO::FETCH_ASSOC);

$existing_statement = $pdo->prepare("
    SELECT *
    FROM delivery_orders
    WHERE do_po_id = ?
    AND do_supplier_id = ?
    LIMIT 1
");
$existing_statement->execute([
    $po_id,
    $supplier_id,
]);
$existing_do =
    $existing_statement->fetch(PDO::FETCH_ASSOC);

if (isset($_GET['download_pdf']) && $existing_do) {
    require_once __DIR__ . '/../vendor/autoload.php';

    $do_items = $pdo->prepare("
        SELECT
            doi.*,
            p.product_title,
            p.product_volume_number
        FROM delivery_order_items doi
        JOIN products p
            ON p.product_id = doi.doi_product_id
        WHERE doi.doi_do_id = ?
    ");
    $do_items->execute([
        (int) $existing_do['do_id'],
    ]);
    $do_items =
        $do_items->fetchAll(PDO::FETCH_ASSOC);

    $supplier_info = $pdo->prepare("
        SELECT *
        FROM suppliers
        WHERE supplier_id = ?
    ");
    $supplier_info->execute([$supplier_id]);
    $supplier_info =
        $supplier_info->fetch(PDO::FETCH_ASSOC);

    $redirect_target =
        app_path('admin/goods_received.php') .
        '?po_id=' . $po_id;

    $qr_url =
        rtrim(APP_URL, '/') .
        '/admin/login.php?redirect=' .
        urlencode($redirect_target);

    $renderer =
        new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle(
                140
            ),
            new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
        );
    $writer = new \BaconQrCode\Writer($renderer);
    $qr_svg = $writer->writeString($qr_url);
    $qr_base64 =
        'data:image/svg+xml;base64,' .
        base64_encode($qr_svg);

    $items_rows = '';

    foreach ($do_items as $delivery_item) {
        $items_rows .=
            "<tr><td style='padding:10px 12px; font-size:12px; border-bottom:1px solid #e5e7eb;'>" .
            htmlspecialchars(
                $delivery_item['product_title'],
                ENT_QUOTES,
                'UTF-8'
            ) .
            (
                $delivery_item['product_volume_number']
                    ? ' (Vol.' .
                        (int) $delivery_item[
                            'product_volume_number'
                        ] .
                        ')'
                    : ''
            ) .
            "</td><td style='padding:10px 12px; font-size:12px; text-align:center; border-bottom:1px solid #e5e7eb;'>" .
            (int) $delivery_item['doi_quantity'] .
            '</td></tr>';
    }

    $html = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: Arial, sans-serif; margin:0; padding:30px; color:#111827;'>
        <div style='background:#1e2d4a; padding:24px; border-radius:8px; margin-bottom:30px;'>
            <h1 style='color:#ffffff; font-size:22px; margin:0; font-weight:900;'>MANGA<span style='color:#ef4444;'>VAULT</span></h1>
            <p style='color:rgba(255,255,255,0.7); font-size:12px; margin:4px 0 0;'>Delivery Order</p>
        </div>
        <div style='display:table; width:100%; margin-bottom:24px;'>
            <div style='display:table-cell; width:50%;'>
                <h2 style='font-size:18px; color:#111827; margin:0 0 4px;'>" . htmlspecialchars($existing_do['do_number']) . "</h2>
                <p style='font-size:12px; color:#6b7280; margin:0;'>For: " . htmlspecialchars($po['po_number']) . "</p>
                <p style='font-size:12px; color:#6b7280; margin:2px 0 0;'>Delivery Date: " . date('d F Y', strtotime($existing_do['do_delivery_date'])) . "</p>
            </div>
            <div style='display:table-cell; width:50%; text-align:right; vertical-align:top;'>
                <img src='$qr_base64' style='width:80px; height:80px; margin-bottom:6px;'>
                <p style='font-size:9px; color:#9ca3af; margin:0; font-weight:700;'>Scan to confirm receipt</p>
            </div>
        </div>
        <div style='background:#f9fafb; border-radius:8px; padding:16px; margin-bottom:24px;'>
            <p style='font-size:11px; color:#9ca3af; margin:0 0 6px; text-transform:uppercase; font-weight:700;'>From (Supplier)</p>
            <p style='font-size:14px; font-weight:700; margin:0 0 2px;'>" . htmlspecialchars($supplier_info['supplier_name']) . "</p>
            <p style='font-size:12px; color:#6b7280; margin:0;'>" . htmlspecialchars($supplier_info['supplier_phone'] ?? '') . "</p>
        </div>
        <table style='width:100%; border-collapse:collapse; margin-bottom:24px;'>
            <tr style='background:#1e2d4a; color:white;'>
                <td style='padding:10px 12px; font-size:11px; font-weight:700;'>Product</td>
                <td style='padding:10px 12px; font-size:11px; font-weight:700; text-align:center;'>Qty Delivered</td>
            </tr>
            $items_rows
        </table>
        " . (
            !empty($existing_do['do_notes'])
                ? "<div style='background:#f9fafb; border-radius:8px; padding:12px; margin-bottom:24px;'><p style='font-size:11px; color:#6b7280; margin:0;'>Notes: " .
                    htmlspecialchars($existing_do['do_notes']) .
                    '</p></div>'
                : ''
        ) . "
        <div style='border-top:2px solid #f3f4f6; padding-top:16px; margin-top:40px;'>
            <p style='font-size:11px; color:#9ca3af; margin:0;'>Recipient must scan QR code above to confirm receipt of goods.</p>
            <p style='font-size:11px; color:#9ca3af; margin:4px 0 0;'>Generated on " . date('d F Y, h:i A') . "</p>
        </div>
    </body>
    </html>";

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream(
        $existing_do['do_number'] . '.pdf',
        ['Attachment' => true]
    );
    exit;
}

$error = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['create_do']) &&
    !$existing_do
) {
    csrf_verify();

    try {
        $delivery_date_raw =
            $_POST['delivery_date'] ?? null;
        $notes_raw = $_POST['notes'] ?? '';
        $quantities_raw =
            $_POST['delivery_qty'] ?? null;

        if (!is_string($delivery_date_raw)) {
            throw new RuntimeException(
                'Please enter a valid delivery date.'
            );
        }

        $delivery_date =
            DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $delivery_date_raw
            );
        $date_errors =
            DateTimeImmutable::getLastErrors();

        if (
            !$delivery_date ||
            (
                is_array($date_errors) &&
                (
                    $date_errors['warning_count'] > 0 ||
                    $date_errors['error_count'] > 0
                )
            ) ||
            $delivery_date->format('Y-m-d') !==
                $delivery_date_raw
        ) {
            throw new RuntimeException(
                'Please enter a valid delivery date.'
            );
        }

        if (!is_string($notes_raw)) {
            throw new RuntimeException(
                'Invalid delivery notes.'
            );
        }

        $notes = trim($notes_raw);
        $notes_length =
            function_exists('mb_strlen')
                ? mb_strlen($notes, 'UTF-8')
                : strlen($notes);

        if ($notes_length > 2000) {
            throw new RuntimeException(
                'Delivery notes cannot exceed 2000 characters.'
            );
        }

        if (!is_array($quantities_raw)) {
            throw new RuntimeException(
                'Invalid delivery quantities.'
            );
        }

        $validated_quantities = [];

        foreach ($items as $item) {
            $item_id = (int) $item['po_item_id'];
            $ordered_quantity =
                (int) $item['po_item_quantity'];

            $quantity = filter_var(
                $quantities_raw[$item_id] ?? null,
                FILTER_VALIDATE_INT,
                [
                    'options' => [
                        'min_range' => 1,
                        'max_range' =>
                            $ordered_quantity,
                    ],
                ]
            );

            if (
                $quantity === false ||
                $quantity === null
            ) {
                throw new RuntimeException(
                    'Each delivery quantity must be between 1 and the ordered quantity.'
                );
            }

            $validated_quantities[$item_id] =
                (int) $quantity;
        }

        $pdo->beginTransaction();

        $duplicate_check = $pdo->prepare("
            SELECT do_id
            FROM delivery_orders
            WHERE do_po_id = ?
            FOR UPDATE
        ");
        $duplicate_check->execute([$po_id]);

        if ($duplicate_check->fetchColumn()) {
            throw new RuntimeException(
                'A delivery order already exists for this purchase order.'
            );
        }

        $temporary_number =
            'PENDING-' . bin2hex(random_bytes(8));

        $insert_do = $pdo->prepare("
            INSERT INTO delivery_orders (
                do_number,
                do_po_id,
                do_supplier_id,
                do_delivery_date,
                do_notes
            )
            VALUES (?, ?, ?, ?, ?)
        ");
        $insert_do->execute([
            $temporary_number,
            $po_id,
            $supplier_id,
            $delivery_date->format('Y-m-d'),
            $notes,
        ]);

        $do_id = (int) $pdo->lastInsertId();
        $do_number =
            'DO-' .
            str_pad(
                (string) $do_id,
                4,
                '0',
                STR_PAD_LEFT
            );

        $update_number = $pdo->prepare("
            UPDATE delivery_orders
            SET do_number = ?
            WHERE do_id = ?
        ");
        $update_number->execute([
            $do_number,
            $do_id,
        ]);

        $insert_item = $pdo->prepare("
            INSERT INTO delivery_order_items (
                doi_do_id,
                doi_product_id,
                doi_quantity
            )
            VALUES (?, ?, ?)
        ");

        foreach ($items as $item) {
            $item_id = (int) $item['po_item_id'];

            $insert_item->execute([
                $do_id,
                (int) $item['po_item_product_id'],
                $validated_quantities[$item_id],
            ]);
        }

        $pdo->commit();

        header(
            'Location: delivery_order.php?po_id=' .
            $po_id
        );
        exit;
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        app_error_log(
            'Delivery order creation failed: ' .
            $e->getMessage()
        );

        $error =
            'Unable to create the delivery order.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Order - <?= htmlspecialchars($po['po_number']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">

    <?php include '../includes/supplier_navbar.php'; ?>

    <div class="max-w-3xl mx-auto px-6 py-8">

        <p class="text-sm text-gray-400 mb-6">
            <a href="purchase_orders.php" class="hover:text-blue-600 transition-colors">Purchase Orders</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">Delivery Order — <?= htmlspecialchars($po['po_number']) ?></span>
        </p>

        <div class="mb-6">
            <h1 class="text-2xl font-black text-gray-800">🚚 Delivery Order</h1>
            <p class="text-gray-500 text-sm mt-1">Generate a delivery order for <?= htmlspecialchars($po['po_number']) ?></p>
        </div>

        <?php if ($existing_do): ?>
        <div class="bg-green-50 border border-green-200 rounded-2xl p-6 mb-6">
            <p class="text-green-700 font-semibold mb-2">✅ Delivery Order Already Generated</p>
            <p class="text-sm text-green-600 mb-4"><?= htmlspecialchars($existing_do['do_number']) ?> — Delivery Date: <?= date('d M Y', strtotime($existing_do['do_delivery_date'])) ?></p>
            <a href="?po_id=<?= (int) $po_id ?>&download_pdf=1"
               class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm transition-colors inline-block">
                📄 Download Delivery Order (PDF)
            </a>
        </div>
        <?php else: ?>
        <form method="POST">
            <?php csrf_field(); ?>
            <input type="hidden" name="create_do" value="1">

            <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Delivery Date *</label>
                    <input type="date" name="delivery_date" required value="<?= date('Y-m-d') ?>"
                           class="w-full px-4 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-blue-400 transition-colors">
                </div>

                <table class="w-full mb-4">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Product</th>
                            <th class="px-3 py-2 text-center text-xs font-semibold text-gray-500 uppercase">Ordered</th>
                            <th class="px-3 py-2 text-center text-xs font-semibold text-gray-500 uppercase">Delivering Now</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr class="border-t border-gray-50">
                            <td class="px-3 py-3 text-sm text-gray-700"><?= htmlspecialchars($item['product_title']) ?></td>
                            <td class="px-3 py-3 text-center text-sm text-gray-500"><?= $item['po_item_quantity'] ?></td>
                            <td class="px-3 py-3 text-center">
                                <input type="number" name="delivery_qty[<?= (int) $item['po_item_id'] ?>]" value="<?= (int) $item['po_item_quantity'] ?>" min="1" max="<?= (int) $item['po_item_quantity'] ?>"
                                       class="w-20 px-2 py-1.5 border-2 border-gray-100 rounded-lg text-sm text-center focus:outline-none focus:border-blue-400">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <textarea name="notes" rows="2" maxlength="2000" placeholder="Delivery notes (optional)"
                          class="w-full px-4 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-blue-400 transition-colors resize-none"></textarea>
            </div>

            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm transition-colors">
                Generate Delivery Order
            </button>
        </form>
        <?php endif; ?>

    </div>

</body>
</html>