<?php
date_default_timezone_set('Asia/Kuala_Lumpur');
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}
require_once '../includes/db.php';

$success = '';
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// Handle mark as paid (with override reason if mismatch) — senior admin only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_paid_confirm'])) {
    if (($_SESSION['admin_level'] ?? '') !== 'senior_admin') {
        $_SESSION['flash_success'] = 'Only senior admin can approve mismatched payments.';
        header('Location: supplier_invoices.php');
        exit;
    }

    $invoice_id = $_POST['invoice_id'];
    $override_reason = trim($_POST['override_reason'] ?? '');

    $pdo->prepare("UPDATE supplier_invoices SET invoice_status = 'paid', invoice_paid_at = NOW(), invoice_override_reason = ?, invoice_override_by = ? WHERE invoice_id = ?")
        ->execute([$override_reason ?: null, $_SESSION['user_id'], $invoice_id]);

    $_SESSION['flash_success'] = 'Invoice marked as paid.';
    header('Location: supplier_invoices.php');
    exit;
}

// Simple mark as paid (no mismatch — direct link still works)
if (isset($_GET['mark_paid'])) {
    $check = $pdo->prepare("SELECT invoice_is_mismatch FROM supplier_invoices WHERE invoice_id = ?");
    $check->execute([$_GET['mark_paid']]);
    $is_mismatch = $check->fetchColumn();

    if (!$is_mismatch) {
        $pdo->prepare("UPDATE supplier_invoices SET invoice_status = 'paid', invoice_paid_at = NOW() WHERE invoice_id = ?")
            ->execute([$_GET['mark_paid']]);
        $_SESSION['flash_success'] = 'Invoice marked as paid.';
        header('Location: supplier_invoices.php');
        exit;
    }
    // If mismatch, fall through — JS will intercept and show modal instead
}

// Handle reject invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_invoice'])) {
    $invoice_id = $_POST['invoice_id'];
    $reason = trim($_POST['reject_reason'] ?? '');

    $pdo->prepare("UPDATE supplier_invoices SET invoice_status = 'rejected', invoice_reject_reason = ? WHERE invoice_id = ?")
        ->execute([$reason, $invoice_id]);

    $_SESSION['flash_success'] = 'Invoice rejected. The supplier has been notified to resubmit.';
    header('Location: supplier_invoices.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_credit_note'])) {
    $invoice_id = $_POST['invoice_id'];
    $return_id = $_POST['return_id'];

    $inv = $pdo->prepare("SELECT invoice_amount, invoice_supplier_id FROM supplier_invoices WHERE invoice_id = ? AND invoice_status = 'unpaid'");
    $inv->execute([$invoice_id]);
    $inv = $inv->fetch(PDO::FETCH_ASSOC);

    if (!$inv) {
        $_SESSION['flash_success'] = 'Invoice not found or already processed.';
        header('Location: supplier_invoices.php'); exit;
    }

    $cn = $pdo->prepare("
        SELECT sr.return_credit_note_amount
        FROM supplier_returns sr
        JOIN purchase_orders po ON po.po_id = sr.return_po_id
        WHERE sr.return_id = ? AND sr.return_credit_note_used_invoice_id IS NULL AND po.po_supplier_id = ?
    ");
    $cn->execute([$return_id, $inv['invoice_supplier_id']]);
    $cn_amount = $cn->fetchColumn();

    if ($cn_amount === false) {
        $_SESSION['flash_success'] = 'This credit note is not available for this supplier.';
        header('Location: supplier_invoices.php'); exit;
    }

    $applied = min($cn_amount, $inv['invoice_amount']);

    $pdo->prepare("UPDATE supplier_invoices SET invoice_credit_note_id = ?, invoice_credit_applied_amount = ? WHERE invoice_id = ?")
        ->execute([$return_id, $applied, $invoice_id]);
    $pdo->prepare("UPDATE supplier_returns SET return_credit_note_used_invoice_id = ? WHERE return_id = ?")
        ->execute([$invoice_id, $return_id]);

    $_SESSION['flash_success'] = "Credit note applied. RM " . number_format($applied, 2) . " deducted from this invoice.";
    header('Location: supplier_invoices.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_credit_note'])) {
    $invoice_id = $_POST['invoice_id'];

    $inv = $pdo->prepare("SELECT invoice_credit_note_id FROM supplier_invoices WHERE invoice_id = ? AND invoice_status = 'unpaid'");
    $inv->execute([$invoice_id]);
    $return_id = $inv->fetchColumn();

    if (!$return_id) {
        $_SESSION['flash_success'] = 'No credit note to remove, or invoice already paid.';
        header('Location: supplier_invoices.php'); exit;
    }

    $pdo->prepare("UPDATE supplier_invoices SET invoice_credit_note_id = NULL, invoice_credit_applied_amount = 0 WHERE invoice_id = ?")
        ->execute([$invoice_id]);
    $pdo->prepare("UPDATE supplier_returns SET return_credit_note_used_invoice_id = NULL WHERE return_id = ?")
        ->execute([$return_id]);

    $_SESSION['flash_success'] = 'Credit note removed from this invoice and made available again.';
    header('Location: supplier_invoices.php');
    exit;
}

// Handle download receipt
if (isset($_GET['download_receipt'])) {
    require_once '../vendor/autoload.php';
    $invoice_id = $_GET['download_receipt'];

    $inv = $pdo->prepare("
        SELECT si.*, s.supplier_name, s.supplier_contact_person, s.supplier_address, s.supplier_email, po.po_number, sr.return_credit_note_number
        FROM supplier_invoices si
        JOIN suppliers s ON s.supplier_id = si.invoice_supplier_id
        LEFT JOIN purchase_orders po ON po.po_id = si.invoice_po_id
        LEFT JOIN supplier_returns sr ON sr.return_id = si.invoice_credit_note_id
        WHERE si.invoice_id = ? AND si.invoice_status = 'paid'
    ");
    $inv->execute([$invoice_id]);
    $inv = $inv->fetch(PDO::FETCH_ASSOC);

    if (!$inv) { header('Location: supplier_invoices.php'); exit; }

    $receipt_number = 'RCT-' . str_pad($invoice_id, 5, '0', STR_PAD_LEFT);

    $html = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: Arial, sans-serif; margin:0; padding:30px; color:#111827;'>
        
        <div style='background:#1e2d4a; padding:24px; border-radius:8px; margin-bottom:30px;'>
            <h1 style='color:#ffffff; font-size:22px; margin:0; font-weight:900;'>MANGA<span style='color:#ef4444;'>VAULT</span></h1>
            <p style='color:rgba(255,255,255,0.7); font-size:12px; margin:4px 0 0;'>Official Payment Receipt</p>
        </div>

        <h2 style='font-size:18px; color:#111827; margin:0 0 4px;'>Payment Receipt</h2>
        <p style='font-size:12px; color:#6b7280; margin:0 0 24px;'>Receipt No: <strong>$receipt_number</strong></p>

        <table style='width:100%; margin-bottom:24px; font-size:13px;'>
            <tr>
                <td style='padding:4px 0; color:#6b7280; width:40%;'>Receipt Date</td>
                <td style='padding:4px 0; font-weight:600;'>" . date('d F Y', strtotime($inv['invoice_paid_at'])) . "</td>
            </tr>
            <tr>
                <td style='padding:4px 0; color:#6b7280;'>Invoice Number</td>
                <td style='padding:4px 0; font-weight:600;'>" . htmlspecialchars($inv['invoice_number']) . "</td>
            </tr>
            <tr>
                <td style='padding:4px 0; color:#6b7280;'>Purchase Order</td>
                <td style='padding:4px 0; font-weight:600;'>" . htmlspecialchars($inv['po_number'] ?? '—') . "</td>
            </tr>
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

    </body>
    </html>";

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream("Receipt_{$receipt_number}.pdf", ['Attachment' => true]);
    exit;
}

$invoices = $pdo->query("
    SELECT si.*, s.supplier_name, po.po_number, po.po_total_amount
    FROM supplier_invoices si
    JOIN suppliers s ON s.supplier_id = si.invoice_supplier_id
    LEFT JOIN purchase_orders po ON po.po_id = si.invoice_po_id
    ORDER BY si.invoice_created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get completed POs for the dropdown (that don't have invoices yet)
$available_pos = $pdo->query("
    SELECT po.po_id, po.po_number, po.po_total_amount, s.supplier_name
    FROM purchase_orders po
    JOIN suppliers s ON s.supplier_id = po.po_supplier_id
    WHERE po.po_status IN ('confirmed', 'completed')
    AND po.po_id NOT IN (SELECT invoice_po_id FROM supplier_invoices WHERE invoice_po_id IS NOT NULL)
    ORDER BY po.po_created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$available_credits = $pdo->query("
    SELECT sr.return_id, sr.return_number, sr.return_credit_note_number, sr.return_credit_note_amount, po.po_supplier_id
    FROM supplier_returns sr
    JOIN purchase_orders po ON po.po_id = sr.return_po_id
    WHERE sr.return_credit_note_number IS NOT NULL
    AND sr.return_credit_note_used_invoice_id IS NULL
")->fetchAll(PDO::FETCH_ASSOC);

$credits_by_supplier = [];
foreach ($available_credits as $c) {
    $credits_by_supplier[$c['po_supplier_id']][] = $c;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Invoices - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-6xl mx-auto px-5 py-8">

        <div class="mb-8">
            <h1 class="text-2xl font-black text-gray-800">🧾 Supplier Invoices</h1>
            <p class="text-gray-500 text-sm mt-1">Review invoices submitted by suppliers and process payments</p>
        </div>

        <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-6">
            ✅ <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>

        <?php
        $mismatch_count = count(array_filter($invoices, fn($i) => $i['invoice_is_mismatch'] && $i['invoice_status'] === 'unpaid'));
        if ($mismatch_count > 0): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-6">
            ⚠️ <?= $mismatch_count ?> invoice(s) have amount mismatches with their PO.
            <?php if (($_SESSION['admin_level'] ?? '') === 'senior_admin'): ?>
            Please review before marking as paid.
            <?php else: ?>
            These require senior admin approval before payment can be processed.
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl shadow-sm overflow-hidden isolate">
            <?php if (count($invoices) === 0): ?>
            <div class="text-center py-16">
                <div class="text-5xl mb-4">🧾</div>
                <p class="text-gray-400">No invoices recorded yet.</p>
            </div>
            <?php else: ?>
            <table class="w-full border-separate" style="border-spacing: 0;">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100 rounded-t-2xl overflow-hidden">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase rounded-tl-2xl">Invoice #</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Supplier</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">PO</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Amount</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Match</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Due Date</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase rounded-tr-2xl">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): 
                        $is_overdue = $inv['invoice_status'] === 'unpaid' && $inv['invoice_due_date'] && strtotime($inv['invoice_due_date']) < time();
                    ?>
                    <tr class="border-b border-gray-50 hover:bg-gray-50 transition-colors" style="overflow: hidden;">
                        <td class="px-5 py-4 whitespace-nowrap">
                            <p class="font-semibold text-sm text-gray-800 whitespace-nowrap"><?= htmlspecialchars($inv['invoice_number']) ?></p>
                            <?php if ($inv['invoice_status'] === 'rejected' && $inv['invoice_reject_reason']): ?>
                            <p class="text-xs text-red-400 mt-1 break-words" style="max-width: 130px; white-space: normal;">
                                ⚠️ <?= htmlspecialchars($inv['invoice_reject_reason']) ?>
                            </p>
                            <?php endif; ?>
                            <?php if ($inv['invoice_status'] === 'paid' && $inv['invoice_override_reason']): ?>
                            <button onclick="alert('Override Reason:\n\n<?= htmlspecialchars(addslashes($inv['invoice_override_reason'])) ?>')"
                                    class="text-xs text-orange-500 hover:underline mt-1 flex items-center gap-1">
                                🔓 Paid with override — view reason
                            </button>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-4 text-sm text-gray-600 whitespace-nowrap"><?= htmlspecialchars($inv['supplier_name']) ?></td>
                        <td class="px-5 py-4 text-sm text-gray-600 whitespace-nowrap"><?= htmlspecialchars($inv['po_number'] ?? '—') ?></td>
                        <td class="px-5 py-4 text-right text-sm whitespace-nowrap">
                            <p class="font-bold text-gray-800">RM <?= number_format($inv['invoice_amount'], 2) ?></p>
                            <?php if ($inv['invoice_credit_applied_amount'] > 0): ?>
                            <p class="text-xs text-green-600">− RM <?= number_format($inv['invoice_credit_applied_amount'], 2) ?> credit</p>
                            <p class="text-xs text-gray-400">Net: RM <?= number_format($inv['invoice_amount'] - $inv['invoice_credit_applied_amount'], 2) ?></p>
                            <?php if ($inv['invoice_status'] === 'unpaid'): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="remove_credit_note" value="1">
                                <input type="hidden" name="invoice_id" value="<?= $inv['invoice_id'] ?>">
                                <button type="submit" class="text-xs text-red-400 hover:underline mt-0.5">✕ Undo</button>
                            </form>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-4 text-center whitespace-nowrap">
                            <?php if ($inv['invoice_is_mismatch']): ?>
                            <span class="bg-red-100 text-red-600 text-xs px-2 py-1 rounded-full font-semibold inline-block" title="PO Total: RM <?= number_format($inv['po_total_amount'], 2) ?>">
                                ⚠️ Mismatch
                            </span>
                            <?php else: ?>
                            <span class="text-green-600 text-xs whitespace-nowrap">✓ Matched</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-4 text-sm whitespace-nowrap <?= $is_overdue ? 'text-red-500 font-semibold' : 'text-gray-500' ?>">
                            <?= $inv['invoice_due_date'] ? date('d M Y', strtotime($inv['invoice_due_date'])) : '—' ?>
                            <?= $is_overdue ? ' ⚠️' : '' ?>
                        </td>
                        <td class="px-5 py-4 text-center whitespace-nowrap">
                            <?php
                            $status_badges = [
                                'unpaid'   => 'bg-yellow-100 text-yellow-700',
                                'paid'     => 'bg-green-100 text-green-700',
                                'rejected' => 'bg-red-100 text-red-700',
                            ];
                            ?>
                            <span class="<?= $status_badges[$inv['invoice_status']] ?> text-xs px-3 py-1 rounded-full font-semibold capitalize">
                                <?= $inv['invoice_status'] ?>
                            </span>
                        </td>
                        <td class="px-5 py-4 text-center whitespace-nowrap">
                            <?php if ($inv['invoice_status'] === 'unpaid'): ?>
                            <div class="flex flex-col items-center gap-2">
                                <?php if (!$inv['invoice_credit_note_id'] && !empty($credits_by_supplier[$inv['invoice_supplier_id']])): ?>
                                    <?php foreach ($credits_by_supplier[$inv['invoice_supplier_id']] as $credit): ?>
                                    <form method="POST" class="w-full">
                                        <input type="hidden" name="apply_credit_note" value="1">
                                        <input type="hidden" name="invoice_id" value="<?= $inv['invoice_id'] ?>">
                                        <input type="hidden" name="return_id" value="<?= $credit['return_id'] ?>">
                                        <button type="submit" class="w-full bg-yellow-50 hover:bg-yellow-100 text-yellow-700 text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors whitespace-normal leading-tight">
                                            💳 Apply Credit<br><?= htmlspecialchars($credit['return_credit_note_number']) ?> (RM <?= number_format($credit['return_credit_note_amount'], 2) ?>)
                                        </button>
                                    </form>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php if ($inv['invoice_is_mismatch']): ?>
                                    <?php if (($_SESSION['admin_level'] ?? '') === 'senior_admin'): ?>
                                    <button onclick="openOverrideModal(<?= $inv['invoice_id'] ?>, '<?= htmlspecialchars($inv['invoice_number']) ?>', <?= $inv['invoice_amount'] ?>, <?= $inv['po_total_amount'] ?>)"
                                            class="bg-green-50 hover:bg-green-100 text-green-700 text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors w-full text-center">
                                        ✓ Mark as Paid
                                    </button>
                                    <?php else: ?>
                                    <span class="bg-gray-100 text-gray-400 text-xs font-semibold px-3 py-1.5 rounded-lg w-full text-center inline-block" title="Only senior admin can approve mismatched payments">
                                        🔒 Needs Approval
                                    </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                <a href="?mark_paid=<?= $inv['invoice_id'] ?>" onclick="return confirm('Mark this invoice as paid?')"
                                class="bg-green-50 hover:bg-green-100 text-green-700 text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors w-full text-center">
                                    ✓ Mark as Paid
                                </a>
                                <?php endif; ?>
                                <button onclick="openRejectModal(<?= $inv['invoice_id'] ?>, '<?= htmlspecialchars($inv['invoice_number']) ?>')"
                                        class="bg-red-50 hover:bg-red-100 text-red-600 text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors w-full text-center">
                                    ✕ Reject
                                </button>
                            </div>
                            <?php elseif ($inv['invoice_status'] === 'paid'): ?>
                            <a href="?download_receipt=<?= $inv['invoice_id'] ?>"
                            class="text-xs text-blue-600 hover:underline font-semibold">📄 Download Receipt</a>
                            <?php else: ?>
                            <span class="text-xs text-gray-400">Rejected — Closed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Mismatch Override Modal -->
    <div id="overrideModal" class="hidden fixed inset-0 bg-black/60 z-50 flex items-center justify-center px-6">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6 border-4 border-red-500">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center text-xl flex-shrink-0">⚠️</div>
                <div>
                    <h3 class="font-black text-gray-800 text-lg">Amount Mismatch Warning</h3>
                    <p class="text-xs text-red-500">This requires your explicit confirmation</p>
                </div>
            </div>

            <div class="bg-red-50 rounded-xl p-4 mb-4 space-y-1">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Invoice <span id="overrideInvoiceLabel" class="font-semibold"></span> Amount</span>
                    <span class="font-bold text-red-600" id="overrideInvoiceAmount"></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">PO Total Amount</span>
                    <span class="font-bold text-gray-700" id="overridePoAmount"></span>
                </div>
            </div>

            <p class="text-sm text-gray-600 mb-4">You are about to pay an amount that <strong>does not match</strong> the original Purchase Order. This action will be logged with your account for audit purposes. Please confirm this is intentional and provide a reason.</p>

            <form method="POST">
                <input type="hidden" name="mark_paid_confirm" value="1">
                <input type="hidden" name="invoice_id" id="overrideInvoiceId">
                <textarea name="override_reason" rows="3" required placeholder="Required: Explain why you are proceeding despite the mismatch (e.g. 'Confirmed with supplier via phone — correct amount is RM1,000 due to partial delivery')"
                        class="w-full px-4 py-2.5 border-2 border-red-200 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors resize-none mb-4"></textarea>
                <div class="flex gap-3">
                    <button type="button" onclick="closeOverrideModal()"
                            class="flex-1 border-2 border-gray-100 hover:bg-gray-50 text-gray-600 font-semibold py-2.5 rounded-xl text-sm transition-colors">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 rounded-xl text-sm transition-colors">
                        I Confirm — Proceed with Payment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center px-6">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6">
            <div class="flex items-center justify-between mb-5">
                <h3 class="font-black text-gray-800 text-lg">Reject Invoice</h3>
                <button onclick="closeRejectModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <p class="text-sm text-gray-500 mb-4">Rejecting <strong id="rejectInvoiceLabel"></strong>. The supplier will be notified to correct and resubmit.</p>
            <form method="POST">
                <input type="hidden" name="reject_invoice" value="1">
                <input type="hidden" name="invoice_id" id="rejectInvoiceId">
                <textarea name="reject_reason" rows="3" required placeholder="e.g. Amount does not match PO total. Please verify and resubmit."
                        class="w-full px-4 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors resize-none mb-4"></textarea>
                <div class="flex gap-3">
                    <button type="button" onclick="closeRejectModal()"
                            class="flex-1 border-2 border-gray-100 hover:bg-gray-50 text-gray-600 font-semibold py-2.5 rounded-xl text-sm transition-colors">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 rounded-xl text-sm transition-colors">
                        Reject Invoice
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openRejectModal(id, number) {
        document.getElementById('rejectInvoiceId').value = id;
        document.getElementById('rejectInvoiceLabel').textContent = number;
        document.getElementById('rejectModal').classList.remove('hidden');
    }
    function closeRejectModal() {
        document.getElementById('rejectModal').classList.add('hidden');
    }
    function openOverrideModal(id, number, invoiceAmount, poAmount) {
        document.getElementById('overrideInvoiceId').value = id;
        document.getElementById('overrideInvoiceLabel').textContent = number;
        document.getElementById('overrideInvoiceAmount').textContent = 'RM ' + parseFloat(invoiceAmount).toFixed(2);
        document.getElementById('overridePoAmount').textContent = 'RM ' + parseFloat(poAmount).toFixed(2);
        document.getElementById('overrideModal').classList.remove('hidden');
    }
    function closeOverrideModal() {
        document.getElementById('overrideModal').classList.add('hidden');
    }
</script>
</body>
</html>