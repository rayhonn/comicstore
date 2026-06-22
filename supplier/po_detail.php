<?php
session_start();
if (!isset($_SESSION['supplier_id']) || $_SESSION['role'] !== 'supplier') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$supplier_id = $_SESSION['supplier_id'];
$po_id = $_GET['id'] ?? null;
if (!$po_id) { header('Location: purchase_orders.php'); exit; }

$po = $pdo->prepare("SELECT * FROM purchase_orders WHERE po_id = ? AND po_supplier_id = ?");
$po->execute([$po_id, $supplier_id]);
$po = $po->fetch(PDO::FETCH_ASSOC);
if (!$po) { header('Location: purchase_orders.php'); exit; }

date_default_timezone_set('Asia/Kuala_Lumpur');

$items = $pdo->prepare("
    SELECT pi.*, p.product_title, p.product_volume_number, p.product_cover_image
    FROM po_items pi
    JOIN products p ON p.product_id = pi.po_item_product_id
    WHERE pi.po_item_po_id = ?
");
$items->execute([$po_id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

$gri = $pdo->prepare("
    SELECT gri.*, p.product_title, pi.po_item_unit_price
    FROM goods_received_items gri
    JOIN goods_received gr ON gr.gr_id = gri.gri_gr_id
    JOIN po_items pi ON pi.po_item_id = gri.gri_po_item_id
    JOIN products p ON p.product_id = pi.po_item_product_id
    WHERE gr.gr_po_id = ? AND gri.gri_rejected_quantity > 0
");
$gri->execute([$po_id]);
$rejected_items = $gri->fetchAll(PDO::FETCH_ASSOC);
$rejected_total = array_sum(array_map(fn($r) => $r['gri_rejected_quantity'] * $r['po_item_unit_price'], $rejected_items));

if (isset($_GET['download_pdf'])) {
    require_once '../vendor/autoload.php';

    $supplier_info = $pdo->prepare("SELECT * FROM suppliers WHERE supplier_id = ?");
    $supplier_info->execute([$supplier_id]);
    $supplier_info = $supplier_info->fetch(PDO::FETCH_ASSOC);

    $html = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: Arial, sans-serif; margin:0; padding:30px; color:#111827;'>
        
        <div style='background:#1e2d4a; padding:24px; border-radius:8px; margin-bottom:30px;'>
            <h1 style='color:#ffffff; font-size:22px; margin:0; font-weight:900;'>MANGA<span style='color:#ef4444;'>VAULT</span></h1>
            <p style='color:rgba(255,255,255,0.7); font-size:12px; margin:4px 0 0;'>Purchase Order</p>
        </div>

        <div style='display:table; width:100%; margin-bottom:24px;'>
            <div style='display:table-cell; width:50%;'>
                <h2 style='font-size:18px; color:#111827; margin:0 0 4px;'>" . htmlspecialchars($po['po_number']) . "</h2>
                <p style='font-size:12px; color:#6b7280; margin:0;'>Date: " . date('d F Y', strtotime($po['po_created_at'])) . "</p>
                <p style='font-size:12px; color:#6b7280; margin:2px 0 0;'>Status: <strong style='text-transform:uppercase;'>" . htmlspecialchars($po['po_status']) . "</strong></p>
            </div>
            <div style='display:table-cell; width:50%; text-align:right; vertical-align:top;'>
                <p style='font-size:11px; color:#9ca3af; margin:0;'>MangaVault Sdn Bhd</p>
                <p style='font-size:11px; color:#9ca3af; margin:0;'>Kuala Lumpur, Malaysia</p>
            </div>
        </div>

        <div style='background:#f9fafb; border-radius:8px; padding:16px; margin-bottom:24px;'>
            <p style='font-size:11px; color:#9ca3af; margin:0 0 6px; text-transform:uppercase; font-weight:700;'>Supplier (Bill To)</p>
            <p style='font-size:14px; font-weight:700; margin:0 0 2px;'>" . htmlspecialchars($supplier_info['supplier_name']) . "</p>
            <p style='font-size:12px; color:#6b7280; margin:0;'>" . htmlspecialchars($supplier_info['supplier_contact_person'] ?? '') . "</p>
            <p style='font-size:12px; color:#6b7280; margin:0;'>" . htmlspecialchars($supplier_info['supplier_address'] ?? '') . "</p>
            <p style='font-size:12px; color:#6b7280; margin:0;'>" . htmlspecialchars($supplier_info['supplier_phone'] ?? '') . " · " . htmlspecialchars($supplier_info['supplier_email'] ?? '') . "</p>
        </div>

        <table style='width:100%; border-collapse:collapse; margin-bottom:24px;'>
            <tr style='background:#1e2d4a; color:white;'>
                <td style='padding:10px 12px; font-size:11px; font-weight:700;'>Product</td>
                <td style='padding:10px 12px; font-size:11px; font-weight:700; text-align:center;'>Qty</td>
                <td style='padding:10px 12px; font-size:11px; font-weight:700; text-align:right;'>Unit Price</td>
                <td style='padding:10px 12px; font-size:11px; font-weight:700; text-align:right;'>Subtotal</td>
            </tr>";

    foreach ($items as $item) {
        $html .= "
            <tr style='border-bottom:1px solid #e5e7eb;'>
                <td style='padding:10px 12px; font-size:12px;'>" . htmlspecialchars($item['product_title']) . ($item['product_volume_number'] ? ' (Vol.' . $item['product_volume_number'] . ')' : '') . "</td>
                <td style='padding:10px 12px; font-size:12px; text-align:center;'>" . $item['po_item_quantity'] . "</td>
                <td style='padding:10px 12px; font-size:12px; text-align:right;'>RM " . number_format($item['po_item_unit_price'], 2) . "</td>
                <td style='padding:10px 12px; font-size:12px; text-align:right; font-weight:600;'>RM " . number_format($item['po_item_unit_price'] * $item['po_item_quantity'], 2) . "</td>
            </tr>";
    }

    $html .= "
            <tr style='background:#fef2f2;'>
                <td colspan='3' style='padding:12px; font-size:14px; font-weight:900;'>Total</td>
                <td style='padding:12px; font-size:14px; font-weight:900; text-align:right; color:#C0392B;'>RM " . number_format($po['po_total_amount'], 2) . "</td>
            </tr>
        </table>";

    if (count($rejected_items) > 0) {
        $rejected_rows = '';
        foreach ($rejected_items as $ri) {
            $amt = $ri['gri_rejected_quantity'] * $ri['po_item_unit_price'];
            $rejected_rows .= "<tr><td style='padding:8px 12px; font-size:11px;'>" . htmlspecialchars($ri['product_title']) . "</td><td style='padding:8px 12px; font-size:11px; text-align:center;'>" . $ri['gri_rejected_quantity'] . "</td><td style='padding:8px 12px; font-size:11px;'>" . htmlspecialchars($ri['gri_reject_reason'] ?? '—') . "</td><td style='padding:8px 12px; font-size:11px; text-align:right;'>RM " . number_format($amt, 2) . "</td></tr>";
        }
        $html .= "
        <div style='background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:14px; margin-bottom:24px;'>
            <p style='font-size:12px; color:#991b1b; margin:0 0 8px; font-weight:700;'>[!] Items Returned (Excluded from Payment)</p>
            <table style='width:100%; border-collapse:collapse;'>
                <tr style='background:#fee2e2;'>
                    <td style='padding:6px 12px; font-size:10px; font-weight:700; color:#991b1b;'>Product</td>
                    <td style='padding:6px 12px; font-size:10px; font-weight:700; color:#991b1b; text-align:center;'>Qty</td>
                    <td style='padding:6px 12px; font-size:10px; font-weight:700; color:#991b1b;'>Reason</td>
                    <td style='padding:6px 12px; font-size:10px; font-weight:700; color:#991b1b; text-align:right;'>Amount</td>
                </tr>
                $rejected_rows
                <tr style='background:#fee2e2;'>
                    <td colspan='3' style='padding:8px 12px; font-size:11px; font-weight:700; color:#991b1b;'>Total Excluded</td>
                    <td style='padding:8px 12px; font-size:11px; font-weight:700; color:#991b1b; text-align:right;'>RM " . number_format($rejected_total, 2) . "</td>
                </tr>
            </table>
        </div>";
    }

    $html .= "
        <div style='background:#fefce8; border:1px solid #fde68a; border-radius:8px; padding:14px; margin-bottom:24px;'>
            <p style='font-size:11px; color:#92400e; margin:0; font-weight:700;'>Terms & Conditions</p>
            <p style='font-size:11px; color:#b45309; margin:4px 0 0;'>1. Goods must match the specifications stated above.</p>
            <p style='font-size:11px; color:#b45309; margin:2px 0 0;'>2. Payment will be processed within 30 days of invoice submission.</p>
            <p style='font-size:11px; color:#b45309; margin:2px 0 0;'>3. Any discrepancies must be reported within 7 days of delivery.</p>
        </div>

        <div style='border-top:2px solid #f3f4f6; padding-top:16px; margin-top:40px;'>
            <p style='font-size:11px; color:#9ca3af; margin:0;'>This is an official purchase order issued by MangaVault Sdn Bhd.</p>
            <p style='font-size:11px; color:#9ca3af; margin:4px 0 0;'>Generated on " . date('d F Y, h:i A') . "</p>
        </div>

    </body>
    </html>";

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream("{$po['po_number']}.pdf", ['Attachment' => true]);
    exit;
}

$status_colors = [
    'draft'     => 'bg-gray-100 text-gray-500',
    'sent'      => 'bg-yellow-100 text-yellow-700',
    'confirmed' => 'bg-blue-100 text-blue-700',
    'completed' => 'bg-green-100 text-green-700',
    'cancelled' => 'bg-red-100 text-red-700',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($po['po_number']) ?> - Supplier Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">

    <?php include '../includes/supplier_navbar.php'; ?>

    <div class="max-w-3xl mx-auto px-6 py-8">

        <p class="text-sm text-gray-400 mb-6">
            <a href="purchase_orders.php" class="hover:text-blue-600 transition-colors">Purchase Orders</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600"><?= htmlspecialchars($po['po_number']) ?></span>
        </p>

        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-black text-gray-800"><?= htmlspecialchars($po['po_number']) ?></h1>
                <p class="text-gray-500 text-sm mt-1">Created <?= date('d M Y, h:i A', strtotime($po['po_created_at'])) ?></p>
            </div>
            <span class="<?= $status_colors[$po['po_status']] ?> text-sm px-4 py-2 rounded-full font-semibold capitalize">
                <?= $po['po_status'] ?>
            </span>
        </div>

        <?php if ($po['po_status'] === 'confirmed'): ?>
        <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-6">
            <p class="text-sm text-blue-700">📦 This order has been confirmed. Please prepare and ship the items as scheduled.</p>
        </div>
        <?php elseif ($po['po_status'] === 'completed'): ?>
        <div class="bg-green-50 border border-green-100 rounded-xl p-4 mb-6">
            <p class="text-sm text-green-700">✅ This order has been fully received by MangaVault. Thank you!</p>
        </div>
        <?php endif; ?>

        <?php if (count($rejected_items) > 0): ?>
        <div class="bg-red-50 border border-red-200 rounded-2xl p-5 mb-6">
            <h3 class="font-bold text-red-700 mb-3">⚠️ Items Returned to You</h3>
            <p class="text-sm text-red-600 mb-3">The following items were found to be damaged or did not meet quality standards. These have been excluded from the final payment amount.</p>
            <div class="space-y-2 mb-3">
                <?php foreach ($rejected_items as $ri): ?>
                <div class="bg-white rounded-xl p-3 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($ri['product_title']) ?></p>
                        <?php if ($ri['gri_reject_reason']): ?>
                        <p class="text-xs text-red-500">Reason: <?= htmlspecialchars($ri['gri_reject_reason']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-bold text-red-600"><?= $ri['gri_rejected_quantity'] ?> units</p>
                        <p class="text-xs text-gray-400">RM <?= number_format($ri['gri_rejected_quantity'] * $ri['po_item_unit_price'], 2) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="flex justify-between items-center pt-3 border-t border-red-200">
                <span class="text-sm font-semibold text-red-700">Total Excluded from Payment</span>
                <span class="text-base font-black text-red-600">RM <?= number_format($rejected_total, 2) ?></span>
            </div>
        </div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl shadow-sm overflow-hidden mb-6">
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Product</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Quantity</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Unit Price</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
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
                        <td class="px-5 py-4 text-right text-sm text-gray-700">RM <?= number_format($item['po_item_unit_price'], 2) ?></td>
                        <td class="px-5 py-4 text-right text-sm font-bold text-gray-800">RM <?= number_format($item['po_item_unit_price'] * $item['po_item_quantity'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="bg-gray-50">
                        <td colspan="3" class="px-5 py-3 text-right text-sm font-bold text-gray-700">Total</td>
                        <td class="px-5 py-3 text-right text-base font-black text-blue-600">RM <?= number_format($po['po_total_amount'], 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="flex gap-3">
            <a href="?id=<?= $po_id ?>&download_pdf=1"
               class="bg-gray-700 hover:bg-gray-800 text-white font-bold px-6 py-2.5 rounded-xl text-sm transition-colors">
                📄 Download PO (PDF)
            </a>
        </div>

    </div>

</body>
</html>