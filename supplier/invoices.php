<?php
date_default_timezone_set('Asia/Kuala_Lumpur');
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';

require_supplier();

$supplier_id = $_SESSION['supplier_id'];
$error = '';
$success = '';

if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_invoice'])) {
    csrf_verify();

    $po_id  = $_POST['po_id'] ?? null;
    $suffix = trim($_POST['invoice_number_suffix'] ?? '');
    $amount = $_POST['invoice_amount'] ?? 0;

    if (empty($suffix) || !preg_match('/^[0-9]{4}$/', $suffix) || empty($po_id)) {
        $error = 'Please enter a valid 4-digit invoice number and select an order.';
    } else {
        $invoice_number = 'INV-' . date('Y') . '-' . $suffix;

        $dup_check = $pdo->prepare("SELECT invoice_id FROM supplier_invoices WHERE invoice_number = ? AND invoice_supplier_id = ?");
        $dup_check->execute([$invoice_number, $supplier_id]);
        if ($dup_check->fetch()) {
            $error = "Invoice number $invoice_number has already been used. Please use a different number.";
        } else {
            $po_check = $pdo->prepare("SELECT po_total_amount FROM purchase_orders WHERE po_id = ?");
            $po_check->execute([$po_id]);
            $po_total = $po_check->fetchColumn();

            $is_mismatch = abs($po_total - $amount) > 0.01;

            $po_verify = $pdo->prepare("SELECT po_id FROM purchase_orders WHERE po_id = ? AND po_supplier_id = ? AND po_status = 'completed'");
            $po_verify->execute([$po_id, $supplier_id]);
            if (!$po_verify->fetch()) {
                $error = 'Invalid order or order not yet completed.';
            } else {
                $pdo->prepare("INSERT INTO supplier_invoices (invoice_number, invoice_supplier_id, invoice_po_id, invoice_amount, invoice_due_date, invoice_is_mismatch) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?)")
                    ->execute([$invoice_number, $supplier_id, $po_id, $amount, $is_mismatch ? 1 : 0]);
                $_SESSION['flash_success'] = 'Invoice submitted successfully. MangaVault will review and process payment.';
                header('Location: invoices.php');
                exit;
            }
        }
    }
}

if (isset($_GET['download_receipt'])) {
    require_once '../vendor/autoload.php';
    $invoice_id = $_GET['download_receipt'];

    $inv = $pdo->prepare("
        SELECT si.*, s.supplier_name, s.supplier_contact_person, s.supplier_address, s.supplier_email, po.po_number, sr.return_credit_note_number
        FROM supplier_invoices si
        JOIN suppliers s ON s.supplier_id = si.invoice_supplier_id
        LEFT JOIN purchase_orders po ON po.po_id = si.invoice_po_id
        LEFT JOIN supplier_returns sr ON sr.return_id = si.invoice_credit_note_id
        WHERE si.invoice_id = ? AND si.invoice_supplier_id = ? AND si.invoice_status = 'paid'
    ");
    $inv->execute([$invoice_id, $supplier_id]);
    $inv = $inv->fetch(PDO::FETCH_ASSOC);

    if (!$inv) { header('Location: invoices.php'); exit; }

    $receipt_number = 'RCT-' . str_pad($invoice_id, 5, '0', STR_PAD_LEFT);

    $html = "
    <!DOCTYPE html><html><head><meta charset='UTF-8'></head>
    <body style='font-family: Arial, sans-serif; margin:0; padding:30px; color:#111827;'>
        <div style='background:#1e2d4a; padding:24px; border-radius:8px; margin-bottom:30px;'>
            <h1 style='color:#ffffff; font-size:22px; margin:0; font-weight:900;'>MANGA<span style='color:#ef4444;'>VAULT</span></h1>
            <p style='color:rgba(255,255,255,0.7); font-size:12px; margin:4px 0 0;'>Official Payment Receipt</p>
        </div>
        <h2 style='font-size:18px; color:#111827; margin:0 0 4px;'>Payment Receipt</h2>
        <p style='font-size:12px; color:#6b7280; margin:0 0 24px;'>Receipt No: <strong>$receipt_number</strong></p>
        <table style='width:100%; margin-bottom:24px; font-size:13px;'>
            <tr><td style='padding:4px 0; color:#6b7280; width:40%;'>Receipt Date</td><td style='padding:4px 0; font-weight:600;'>" . date('d F Y', strtotime($inv['invoice_paid_at'])) . "</td></tr>
            <tr><td style='padding:4px 0; color:#6b7280;'>Invoice Number</td><td style='padding:4px 0; font-weight:600;'>" . htmlspecialchars($inv['invoice_number']) . "</td></tr>
            <tr><td style='padding:4px 0; color:#6b7280;'>Purchase Order</td><td style='padding:4px 0; font-weight:600;'>" . htmlspecialchars($inv['po_number'] ?? '—') . "</td></tr>
        </table>
        <div style='background:#f9fafb; border-radius:8px; padding:16px; margin-bottom:24px;'>
            <p style='font-size:11px; color:#9ca3af; margin:0 0 6px; text-transform:uppercase; font-weight:700;'>Paid To</p>
            <p style='font-size:14px; font-weight:700; margin:0 0 2px;'>" . htmlspecialchars($inv['supplier_name']) . "</p>
            <p style='font-size:12px; color:#6b7280; margin:0;'>" . htmlspecialchars($inv['supplier_contact_person'] ?? '') . "</p>
            <p style='font-size:12px; color:#6b7280; margin:0;'>" . htmlspecialchars($inv['supplier_address'] ?? '') . "</p>
            <p style='font-size:12px; color:#6b7280; margin:0;'>" . htmlspecialchars($inv['supplier_email'] ?? '') . "</p>
        </div>
        <table style='width:100%; border-collapse:collapse; margin-bottom:24px;'>
            <tr style='background:#1e2d4a; color:white;'>
                <td style='padding:10px 14px; font-size:12px; font-weight:700;'>Description</td>
                <td style='padding:10px 14px; font-size:12px; font-weight:700; text-align:right;'>Amount</td>
            </tr>
            <tr style='border-bottom:1px solid #e5e7eb;'>
                <td style='padding:12px 14px; font-size:13px;'>Invoice " . htmlspecialchars($inv['invoice_number']) . "</td>
                <td style='padding:12px 14px; font-size:13px; text-align:right;'>RM " . number_format($inv['invoice_amount'], 2) . "</td>
            </tr>" . ($inv['invoice_credit_applied_amount'] > 0 ? "
            <tr style='border-bottom:1px solid #e5e7eb;'>
                <td style='padding:12px 14px; font-size:13px; color:#C0392B;'>Less: Credit Note " . htmlspecialchars($inv['return_credit_note_number']) . "</td>
                <td style='padding:12px 14px; font-size:13px; text-align:right; color:#C0392B;'>- RM " . number_format($inv['invoice_credit_applied_amount'], 2) . "</td>
            </tr>" : "") . "
            <tr style='background:#fef2f2;'>
                <td style='padding:12px 14px; font-size:14px; font-weight:900;'>Total Paid</td>
                <td style='padding:12px 14px; font-size:14px; font-weight:900; text-align:right; color:#C0392B;'>RM " . number_format($inv['invoice_amount'] - $inv['invoice_credit_applied_amount'], 2) . "</td>
            </tr>
        </table>
        <div style='border-top:2px solid #f3f4f6; padding-top:16px; margin-top:40px;'>
            <p style='font-size:11px; color:#9ca3af; margin:0;'>This is a computer-generated receipt and serves as official proof of payment from MangaVault to the above supplier.</p>
            <p style='font-size:11px; color:#9ca3af; margin:4px 0 0;'>MangaVault Sdn Bhd · Generated on " . date('d F Y, h:i A') . "</p>
        </div>
    </body></html>";

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream("Receipt_{$receipt_number}.pdf", ['Attachment' => true]);
    exit;
}

$invoices = $pdo->prepare("
    SELECT si.*, po.po_number, sr.return_credit_note_number
    FROM supplier_invoices si
    LEFT JOIN purchase_orders po ON po.po_id = si.invoice_po_id
    LEFT JOIN supplier_returns sr ON sr.return_id = si.invoice_credit_note_id
    WHERE si.invoice_supplier_id = ?
    ORDER BY si.invoice_created_at DESC
");
$invoices->execute([$supplier_id]);
$invoices = $invoices->fetchAll(PDO::FETCH_ASSOC);

$available_pos = $pdo->prepare("
    SELECT po_id, po_number, po_total_amount FROM purchase_orders
    WHERE po_supplier_id = ? AND po_status = 'completed'
    AND po_id NOT IN (
        SELECT invoice_po_id FROM supplier_invoices
        WHERE invoice_po_id IS NOT NULL AND invoice_status != 'rejected'
    )
");
$available_pos->execute([$supplier_id]);
$available_pos = $available_pos->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoices - Supplier Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">

    <?php include '../includes/supplier_navbar.php'; ?>

    <div class="max-w-4xl mx-auto px-6 py-8">

        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-black text-gray-800">🧾 My Invoices</h1>
                <p class="text-gray-500 text-sm mt-1">Submit invoices for completed orders and track payment status</p>
            </div>
            <?php if (count($available_pos) > 0): ?>
            <button onclick="openModal()"
                    class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-5 py-2.5 rounded-xl text-sm transition-colors">
                + Submit Invoice
            </button>
            <?php endif; ?>
        </div>

        <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-6">
            ✅ <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>

        <?php if (count($available_pos) > 0): ?>
        <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-6">
            <p class="text-sm text-blue-700">📌 You have <?= count($available_pos) ?> completed order(s) awaiting invoice submission.</p>
        </div>
        <?php endif; ?>

        <?php if (count($invoices) === 0): ?>
        <div class="bg-white rounded-2xl shadow-sm p-16 text-center">
            <div class="text-5xl mb-4">🧾</div>
            <p class="text-gray-400">No invoices submitted yet.</p>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Invoice #</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">PO</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Amount</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Due Date</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv):
                        $is_overdue = $inv['invoice_status'] === 'unpaid' && $inv['invoice_due_date'] && strtotime($inv['invoice_due_date']) < time();
                    ?>
                    <tr class="border-b border-gray-50 hover:bg-gray-50 transition-colors">
                        <td class="px-5 py-4">
                            <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($inv['invoice_number']) ?></p>
                            <?php if ($inv['invoice_status'] === 'rejected' && $inv['invoice_reject_reason']): ?>
                            <p class="text-xs text-red-500 mt-0.5">⚠️ <?= htmlspecialchars($inv['invoice_reject_reason']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-4 text-sm text-gray-600"><?= htmlspecialchars($inv['po_number'] ?? '—') ?></td>
                        <td class="px-5 py-4 text-right text-sm">
                            <p class="font-bold text-gray-800">RM <?= number_format($inv['invoice_amount'], 2) ?></p>
                            <?php if ($inv['invoice_credit_applied_amount'] > 0): ?>
                            <p class="text-xs text-orange-600">− RM <?= number_format($inv['invoice_credit_applied_amount'], 2) ?> credit (<?= htmlspecialchars($inv['return_credit_note_number']) ?>)</p>
                            <p class="text-xs text-gray-400">Net: RM <?= number_format($inv['invoice_amount'] - $inv['invoice_credit_applied_amount'], 2) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-4 text-sm <?= $is_overdue ? 'text-red-500 font-semibold' : 'text-gray-500' ?>">
                            <?= $inv['invoice_due_date'] ? date('d M Y', strtotime($inv['invoice_due_date'])) : '—' ?>
                            <?= $is_overdue ? ' ⚠️' : '' ?>
                        </td>
                        <td class="px-5 py-4 text-center">
                            <?php if ($inv['invoice_status'] === 'paid'): ?>
                            <a href="?download_receipt=<?= $inv['invoice_id'] ?>" class="text-xs text-green-600 hover:underline font-semibold">✅ Paid — Download Receipt</a>
                            <?php elseif ($inv['invoice_status'] === 'rejected'): ?>
                            <span class="bg-red-100 text-red-700 text-xs px-3 py-1 rounded-full font-semibold">❌ Rejected</span>
                            <?php else: ?>
                            <span class="bg-yellow-100 text-yellow-700 text-xs px-3 py-1 rounded-full font-semibold">⏳ Awaiting Payment</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div>

    <!-- Submit Invoice Modal -->
    <div id="invoiceModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center px-6">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6">
            <div class="flex items-center justify-between mb-5">
                <h3 class="font-black text-gray-800 text-lg">Submit Invoice</h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-4">
                ❌ <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
            <form method="POST">
                <?php csrf_field() ?>
                <input type="hidden" name="submit_invoice" value="1">

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Completed Order *</label>
                        <select name="po_id" id="po_select" required onchange="autofillAmount(this)"
                                class="w-full px-4 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-blue-400 transition-colors">
                            <option value="">Select order...</option>
                            <?php foreach ($available_pos as $po): ?>
                            <option value="<?= $po['po_id'] ?>" data-amount="<?= $po['po_total_amount'] ?>">
                                <?= htmlspecialchars($po['po_number']) ?> (RM <?= number_format($po['po_total_amount'], 2) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Your Invoice Number *</label>
                        <div class="flex items-center border-2 border-gray-100 rounded-xl overflow-hidden focus-within:border-blue-400 transition-colors">
                            <span class="px-3 py-2.5 bg-gray-50 text-sm text-gray-500 font-mono border-r border-gray-100">INV-<?= date('Y') ?>-</span>
                            <input type="text" name="invoice_number_suffix" required maxlength="4" pattern="[0-9]{4}"
                                   placeholder="0001" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 4)"
                                   class="flex-1 px-3 py-2.5 text-sm focus:outline-none">
                        </div>
                        <p class="text-xs text-gray-400 mt-1">4-digit number only, e.g. 0001</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Amount (RM) *</label>
                        <input type="number" step="0.01" name="invoice_amount" id="amount_input" required
                               class="w-full px-4 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-blue-400 transition-colors">
                    </div>
                    <p class="text-xs text-gray-400">Payment terms: 30 days from submission</p>
                </div>

                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="closeModal()"
                            class="flex-1 border-2 border-gray-100 hover:bg-gray-50 text-gray-600 font-semibold py-2.5 rounded-xl text-sm transition-colors">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl text-sm transition-colors">
                        Submit Invoice
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openModal() { document.getElementById('invoiceModal').classList.remove('hidden'); }
    function closeModal() { document.getElementById('invoiceModal').classList.add('hidden'); }
    function autofillAmount(select) {
        const amount = select.options[select.selectedIndex].dataset.amount;
        if (amount) document.getElementById('amount_input').value = amount;
    }
    <?php if ($error): ?>
    document.addEventListener('DOMContentLoaded', () => openModal());
    <?php endif; ?>
    </script>

</body>
</html>