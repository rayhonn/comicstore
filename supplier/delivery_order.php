<?php
require_once __DIR__ . '/../includes/auth.php';

if (
    empty($_SESSION['supplier_id']) ||
    ($_SESSION['role'] ?? '') !== 'supplier'
) {
    redirect_to(app_path('supplier/login.php'));
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

$supplier_id = $_SESSION['supplier_id'];
$po_id = $_GET['po_id'] ?? null;
if (!$po_id) { header('Location: purchase_orders.php'); exit; }

$po = $pdo->prepare("SELECT * FROM purchase_orders WHERE po_id = ? AND po_supplier_id = ? AND po_status = 'confirmed'");
$po->execute([$po_id, $supplier_id]);
$po = $po->fetch(PDO::FETCH_ASSOC);
if (!$po) { header('Location: purchase_orders.php'); exit; }

if (!$po['po_acknowledged_at']) {
    header('Location: po_detail.php?id=' . $po_id . '&must_acknowledge=1');
    exit;
}

$items = $pdo->prepare("
    SELECT pi.*, p.product_title, p.product_volume_number
    FROM po_items pi
    JOIN products p ON p.product_id = pi.po_item_product_id
    WHERE pi.po_item_po_id = ?
");
$items->execute([$po_id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

$existing_do = $pdo->prepare("SELECT * FROM delivery_orders WHERE do_po_id = ?");
$existing_do->execute([$po_id]);
$existing_do = $existing_do->fetch(PDO::FETCH_ASSOC);

// Handle download DO PDF
if (isset($_GET['download_pdf']) && $existing_do) {
    require_once '../vendor/autoload.php';

    $do_items = $pdo->prepare("
        SELECT doi.*, p.product_title, p.product_volume_number
        FROM delivery_order_items doi
        JOIN products p ON p.product_id = doi.doi_product_id
        WHERE doi.doi_do_id = ?
    ");
    $do_items->execute([$existing_do['do_id']]);
    $do_items = $do_items->fetchAll(PDO::FETCH_ASSOC);

    $supplier_info = $pdo->prepare("SELECT * FROM suppliers WHERE supplier_id = ?");
    $supplier_info->execute([$supplier_id]);
    $supplier_info = $supplier_info->fetch(PDO::FETCH_ASSOC);

    $redirect_target =
        app_path('admin/goods_received.php') .
        '?po_id=' . (int) $po_id;

    $qr_url =
        rtrim(APP_URL, '/') .
        '/admin/login.php?redirect=' .
        urlencode($redirect_target);
    $renderer = new \BaconQrCode\Renderer\ImageRenderer(
        new \BaconQrCode\Renderer\RendererStyle\RendererStyle(140),
        new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
    );
    $writer = new \BaconQrCode\Writer($renderer);
    $qr_svg = $writer->writeString($qr_url);
    $qr_base64 = 'data:image/svg+xml;base64,' . base64_encode($qr_svg);

    $items_rows = '';
    foreach ($do_items as $di) {
        $items_rows .= "<tr><td style='padding:10px 12px; font-size:12px; border-bottom:1px solid #e5e7eb;'>" . htmlspecialchars($di['product_title']) . ($di['product_volume_number'] ? ' (Vol.' . $di['product_volume_number'] . ')' : '') . "</td><td style='padding:10px 12px; font-size:12px; text-align:center; border-bottom:1px solid #e5e7eb;'>" . $di['doi_quantity'] . "</td></tr>";
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

        " . (!empty($existing_do['do_notes']) ? "<div style='background:#f9fafb; border-radius:8px; padding:12px; margin-bottom:24px;'><p style='font-size:11px; color:#6b7280; margin:0;'>Notes: " . htmlspecialchars($existing_do['do_notes']) . "</p></div>" : "") . "

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
    $dompdf->stream("{$existing_do['do_number']}.pdf", ['Attachment' => true]);
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_do']) && !$existing_do) {
    csrf_verify();
    $delivery_date = $_POST['delivery_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    $qtys = $_POST['delivery_qty'] ?? [];

    $last = $pdo->query("SELECT do_id FROM delivery_orders ORDER BY do_id DESC LIMIT 1")->fetchColumn();
    $next_num = ($last ?? 0) + 1;
    $do_number = 'DO-' . str_pad($next_num, 4, '0', STR_PAD_LEFT);

    $pdo->prepare("INSERT INTO delivery_orders (do_number, do_po_id, do_supplier_id, do_delivery_date, do_notes) VALUES (?, ?, ?, ?, ?)")
        ->execute([$do_number, $po_id, $supplier_id, $delivery_date, $notes]);
    $do_id = $pdo->lastInsertId();

    foreach ($items as $item) {
        $qty = intval($qtys[$item['po_item_id']] ?? $item['po_item_quantity']);
        $pdo->prepare("INSERT INTO delivery_order_items (doi_do_id, doi_product_id, doi_quantity) VALUES (?, ?, ?)")
            ->execute([$do_id, $item['po_item_product_id'], $qty]);
    }

    header('Location: delivery_order.php?po_id=' . $po_id);
    exit;
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
            <a href="?po_id=<?= $po_id ?>&download_pdf=1"
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
                                <input type="number" name="delivery_qty[<?= $item['po_item_id'] ?>]" value="<?= $item['po_item_quantity'] ?>" min="1" max="<?= $item['po_item_quantity'] ?>"
                                       class="w-20 px-2 py-1.5 border-2 border-gray-100 rounded-lg text-sm text-center focus:outline-none focus:border-blue-400">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <textarea name="notes" rows="2" placeholder="Delivery notes (optional)"
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