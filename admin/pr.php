<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}
require_once '../includes/db.php';

$is_senior = (($_SESSION['admin_level'] ?? '') === 'senior_admin');
$PR_APPROVAL_THRESHOLD = 1000;

function get_estimated_pr_cost($pdo, $product_id, $quantity) {
    $last_price = $pdo->prepare("
        SELECT po_item_unit_price FROM po_items
        WHERE po_item_product_id = ?
        ORDER BY po_item_id DESC LIMIT 1
    ");
    $last_price->execute([$product_id]);
    $unit_price = $last_price->fetchColumn();
    if ($unit_price === false) return null;
    return $unit_price * $quantity;
}

date_default_timezone_set('Asia/Kuala_Lumpur');

if (isset($_GET['download_pdf'])) {
    require_once '../vendor/autoload.php';

    $pr_id = $_GET['download_pdf'];
    $pr = $pdo->prepare("
        SELECT pr.*, p.product_title, p.product_volume_number,
        CONCAT_WS(' ', u1.user_first_name, u1.user_last_name) AS requested_by_name,
        CONCAT_WS(' ', u2.user_first_name, u2.user_last_name) AS reviewed_by_name
        FROM purchase_requisitions pr
        JOIN products p ON p.product_id = pr.pr_product_id
        LEFT JOIN users u1 ON u1.user_id = pr.pr_requested_by
        LEFT JOIN users u2 ON u2.user_id = pr.pr_reviewed_by
        WHERE pr.pr_id = ?
    ");
    $pr->execute([$pr_id]);
    $pr = $pr->fetch(PDO::FETCH_ASSOC);
    if (!$pr) { header('Location: pr.php'); exit; }

    $html = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: Arial, sans-serif; margin:0; padding:30px; color:#111827;'>

        <div style='background:#1e2d4a; padding:24px; border-radius:8px; margin-bottom:30px;'>
            <h1 style='color:#ffffff; font-size:22px; margin:0; font-weight:900;'>MANGA<span style='color:#ef4444;'>VAULT</span></h1>
            <p style='color:rgba(255,255,255,0.7); font-size:12px; margin:4px 0 0;'>Purchase Requisition</p>
        </div>

        <div style='margin-bottom:24px;'>
            <h2 style='font-size:18px; color:#111827; margin:0 0 4px;'>" . htmlspecialchars($pr['pr_number']) . "</h2>
            <p style='font-size:12px; color:#6b7280; margin:0;'>Date: " . date('d F Y', strtotime($pr['pr_created_at'])) . "</p>
            <p style='font-size:12px; color:#6b7280; margin:2px 0 0;'>Status: <strong style='text-transform:uppercase;'>" . htmlspecialchars($pr['pr_status']) . "</strong></p>
        </div>

        <table style='width:100%; border-collapse:collapse; margin-bottom:24px;'>
            <tr style='background:#1e2d4a; color:white;'>
                <td style='padding:10px 12px; font-size:11px; font-weight:700;'>Item</td>
                <td style='padding:10px 12px; font-size:11px; font-weight:700; text-align:center;'>Suggested Qty</td>
            </tr>
            <tr style='border-bottom:1px solid #e5e7eb;'>
                <td style='padding:10px 12px; font-size:12px;'>" . htmlspecialchars($pr['product_title']) . ($pr['product_volume_number'] ? ' (Vol.' . $pr['product_volume_number'] . ')' : '') . "</td>
                <td style='padding:10px 12px; font-size:12px; text-align:center;'>" . $pr['pr_suggested_quantity'] . "</td>
            </tr>
        </table>

        <div style='background:#f9fafb; border-radius:8px; padding:16px; margin-bottom:24px;'>
            <p style='font-size:11px; color:#9ca3af; margin:0 0 6px; text-transform:uppercase; font-weight:700;'>Reason</p>
            <p style='font-size:13px; color:#374151; margin:0;'>" . htmlspecialchars($pr['pr_reason'] ?: '—') . "</p>
        </div>" .

        ($pr['pr_status'] === 'rejected' && $pr['pr_review_note'] ? "
        <div style='background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:16px; margin-bottom:24px;'>
            <p style='font-size:11px; color:#991b1b; margin:0 0 6px; text-transform:uppercase; font-weight:700;'>Rejection Reason</p>
            <p style='font-size:13px; color:#374151; margin:0;'>" . htmlspecialchars($pr['pr_review_note']) . "</p>
        </div>" : "") . "

        <div style='display:table; width:100%; margin-top:40px; margin-bottom:24px;'>
            <div style='display:table-cell; width:50%; padding-right:20px;'>
                <p style='font-size:11px; color:#9ca3af; margin:0 0 40px;'>Requested By</p>
                <p style='border-top:1px solid #111827; padding-top:6px; font-size:12px; font-weight:700; margin:0;'>" . htmlspecialchars($pr['requested_by_name'] ?: '—') . "</p>
                <p style='font-size:12px; color:#6b7280; margin:2px 0 0;'>Staff</p>
                <p style='font-size:12px; color:#6b7280; margin:2px 0 0;'>" . date('d M Y', strtotime($pr['pr_created_at'])) . "</p>
            </div>
            <div style='display:table-cell; width:50%; padding-left:20px;'>
                <p style='font-size:11px; color:#9ca3af; margin:0 0 40px;'>Approved By</p>
                <p style='border-top:1px solid #111827; padding-top:6px; font-size:12px; font-weight:700; margin:0; min-height:14px;'>" . htmlspecialchars($pr['pr_status'] !== 'pending' ? $pr['reviewed_by_name'] : '') . "</p>
                <p style='font-size:12px; color:#6b7280; margin:2px 0 0;'>Admin</p>
                <p style='font-size:12px; color:#6b7280; margin:2px 0 0;'>" . ($pr['pr_reviewed_at'] ? date('d M Y', strtotime($pr['pr_reviewed_at'])) : '') . "</p>
            </div>
        </div>

        <div style='border-top:2px solid #f3f4f6; padding-top:16px; margin-top:24px;'>
            <p style='font-size:11px; color:#9ca3af; margin:0;'>This is an internal purchase requisition issued by MangaVault Sdn Bhd.</p>
            <p style='font-size:11px; color:#9ca3af; margin:4px 0 0;'>Generated on " . date('d F Y, h:i A') . "</p>
        </div>

    </body>
    </html>";

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream("{$pr['pr_number']}.pdf", ['Attachment' => true]);
    exit;
}

$success = '';
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scan_low_stock'])) {
    $low_stock_products = $pdo->query("
        SELECT p.product_id, pp.physical_stock_quantity, pp.physical_low_stock_threshold
        FROM products p
        JOIN product_physical pp ON pp.physical_product_id = p.product_id
        WHERE pp.physical_stock_quantity <= pp.physical_low_stock_threshold
        AND p.product_id NOT IN (
            SELECT pr_product_id FROM purchase_requisitions WHERE pr_status IN ('draft', 'pending', 'approved')
        )
    ")->fetchAll(PDO::FETCH_ASSOC);

    $created = 0;
    foreach ($low_stock_products as $lp) {
        $last = $pdo->query("SELECT pr_id FROM purchase_requisitions ORDER BY pr_id DESC LIMIT 1")->fetchColumn();
        $pr_number = 'PR-' . str_pad(($last ?? 0) + 1, 4, '0', STR_PAD_LEFT);
        $suggested_qty = max(($lp['physical_low_stock_threshold'] * 2) - $lp['physical_stock_quantity'], $lp['physical_low_stock_threshold']);

        $pdo->prepare("
            INSERT INTO purchase_requisitions (pr_number, pr_product_id, pr_suggested_quantity, pr_reason, pr_status, pr_auto_generated)
            VALUES (?, ?, ?, ?, 'draft', 1)
        ")->execute([
            $pr_number, $lp['product_id'], $suggested_qty,
            "Auto-generated: stock at {$lp['physical_stock_quantity']}, below threshold of {$lp['physical_low_stock_threshold']}."
        ]);
        $created++;
    }

    $_SESSION['flash_success'] = $created > 0 ? "$created draft PR(s) generated for low-stock items." : "No new drafts needed — all low-stock items already have an open PR.";
    header('Location: pr.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_pr'])) {
    $pr_id = $_POST['pr_id'];

    $pr_check = $pdo->prepare("SELECT pr_product_id, pr_suggested_quantity FROM purchase_requisitions WHERE pr_id = ? AND pr_status = 'pending'");
    $pr_check->execute([$pr_id]);
    $pr_check = $pr_check->fetch(PDO::FETCH_ASSOC);

    if (!$pr_check) {
        $_SESSION['flash_success'] = 'PR not found or already reviewed.';
        header('Location: pr.php'); exit;
    }

    $estimated_cost = get_estimated_pr_cost($pdo, $pr_check['pr_product_id'], $pr_check['pr_suggested_quantity']);

    if ($estimated_cost !== null && $estimated_cost >= $PR_APPROVAL_THRESHOLD && !$is_senior) {
        $_SESSION['flash_success'] = "This PR is estimated at RM " . number_format($estimated_cost, 2) . " — only senior admin can approve requisitions above RM " . number_format($PR_APPROVAL_THRESHOLD, 2) . ".";
        header('Location: pr.php'); exit;
    }

    $pdo->prepare("UPDATE purchase_requisitions SET pr_status = 'approved', pr_reviewed_by = ?, pr_reviewed_at = NOW() WHERE pr_id = ? AND pr_status = 'pending'")
        ->execute([$_SESSION['user_id'], $pr_id]);
    $_SESSION['flash_success'] = 'PR approved. You can now convert it to an RFQ.';
    header('Location: pr.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_pr'])) {
    $pr_id = $_POST['pr_id'];
    $note = trim($_POST['review_note'] ?? '');
    if ($note === '') {
        $_SESSION['flash_success'] = 'A reason is required to reject a PR.';
        header('Location: pr.php'); exit;
    }
    $pdo->prepare("UPDATE purchase_requisitions SET pr_status = 'rejected', pr_review_note = ?, pr_reviewed_by = ?, pr_reviewed_at = NOW() WHERE pr_id = ? AND pr_status = 'pending'")
        ->execute([$note, $_SESSION['user_id'], $pr_id]);
    $_SESSION['flash_success'] = 'PR rejected.';
    header('Location: pr.php');
    exit;
}

$prs = $pdo->query("
    SELECT pr.*, p.product_title, p.product_volume_number, pp.physical_stock_quantity,
    u1.user_name AS requested_by_name, u2.user_name AS reviewed_by_name
    FROM purchase_requisitions pr
    JOIN products p ON p.product_id = pr.pr_product_id
    LEFT JOIN product_physical pp ON pp.physical_product_id = p.product_id
    LEFT JOIN users u1 ON u1.user_id = pr.pr_requested_by
    LEFT JOIN users u2 ON u2.user_id = pr.pr_reviewed_by
    WHERE pr.pr_status != 'draft'
    ORDER BY pr.pr_created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Requisitions - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-6xl mx-auto px-6 py-8">

        <div class="mb-8 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-black text-gray-800">📝 Purchase Requisitions</h1>
                <p class="text-gray-500 text-sm mt-1">Review restock requests submitted by staff</p>
            </div>
            <form method="POST">
                <input type="hidden" name="scan_low_stock" value="1">
                <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white font-bold px-5 py-2.5 rounded-xl text-sm transition-colors">
                    🔄 Scan Low Stock & Generate Drafts
                </button>
            </form>
        </div>

        <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-6">
            ✅ <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>

        <?php if (count($prs) === 0): ?>
        <div class="bg-white rounded-2xl shadow-sm p-16 text-center">
            <div class="text-5xl mb-4">📝</div>
            <p class="text-gray-400">No requisitions submitted yet.</p>
        </div>
        <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($prs as $pr):
                $status_colors = [
                    'pending'   => 'bg-yellow-100 text-yellow-700',
                    'approved'  => 'bg-blue-100 text-blue-700',
                    'rejected'  => 'bg-red-100 text-red-700',
                    'converted' => 'bg-green-100 text-green-700',
                ];
                $modal_id = 'reject_modal_' . $pr['pr_id'];
                $est_cost = get_estimated_pr_cost($pdo, $pr['pr_product_id'], $pr['pr_suggested_quantity']);
                $needs_senior = $est_cost !== null && $est_cost >= $PR_APPROVAL_THRESHOLD;
            ?>
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <p class="font-bold text-gray-800"><?= htmlspecialchars($pr['pr_number']) ?></p>
                        <p class="text-xs text-gray-400">Requested by <?= htmlspecialchars($pr['requested_by_name'] ?? '—') ?> · <?= date('d M Y', strtotime($pr['pr_created_at'])) ?></p>
                    </div>
                    <span class="<?= $status_colors[$pr['pr_status']] ?> text-xs px-3 py-1 rounded-full font-semibold capitalize">
                        <?= $pr['pr_status'] ?>
                    </span>
                </div>

                <div class="bg-gray-50 rounded-xl p-4 mb-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($pr['product_title']) ?></p>
                            <p class="text-xs text-gray-400">Current stock: <?= $pr['physical_stock_quantity'] ?? '—' ?></p>
                            <?php if ($est_cost !== null): ?>
                            <p class="text-xs <?= $needs_senior ? 'text-orange-500 font-semibold' : 'text-gray-400' ?>">
                                Est. cost: RM <?= number_format($est_cost, 2) ?><?= $needs_senior ? ' ⚠️ Requires Senior Admin' : '' ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <p class="text-lg font-black text-red-600"><?= $pr['pr_suggested_quantity'] ?> units</p>
                    </div>
                    <?php if ($pr['pr_reason']): ?>
                    <p class="text-xs text-gray-500 mt-2 pt-2 border-t border-gray-100">"<?= htmlspecialchars($pr['pr_reason']) ?>"</p>
                    <?php endif; ?>
                </div>

                <?php if ($pr['pr_status'] === 'rejected' && $pr['pr_review_note']): ?>
                <p class="text-xs text-red-500 mb-3">Rejected: <?= htmlspecialchars($pr['pr_review_note']) ?> — by <?= htmlspecialchars($pr['reviewed_by_name']) ?></p>
                <?php endif; ?>

                <div class="flex items-center justify-end gap-2">
                    <?php if ($pr['pr_status'] === 'pending'): ?>
                    <?php if ($needs_senior && !$is_senior): ?>
                    <span class="bg-gray-100 text-gray-400 text-xs font-semibold px-4 py-2 rounded-lg" title="Only senior admin can approve">🔒 Senior Admin Only</span>
                    <?php else: ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="approve_pr" value="1">
                        <input type="hidden" name="pr_id" value="<?= $pr['pr_id'] ?>">
                        <button type="submit" class="bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-bold px-4 py-2 rounded-lg transition-colors">
                            ✓ Approve
                        </button>
                    </form>
                    <?php endif; ?>
                    <button onclick="document.getElementById('<?= $modal_id ?>').classList.remove('hidden')"
                            class="bg-red-50 hover:bg-red-100 text-red-600 text-xs font-bold px-4 py-2 rounded-lg transition-colors">
                        ✕ Reject
                    </button>
                    <?php elseif ($pr['pr_status'] === 'approved'): ?>
                    <a href="rfq.php?from_pr=<?= $pr['pr_id'] ?>" class="bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold px-4 py-2 rounded-lg transition-colors">
                        📋 Convert to RFQ
                    </a>
                    <?php elseif ($pr['pr_status'] === 'converted'): ?>
                    <a href="rfq.php" class="text-xs text-green-600 hover:underline font-semibold mr-2">View RFQ →</a>
                    <a href="?download_pdf=<?= $pr['pr_id'] ?>" class="text-xs text-gray-500 hover:underline font-semibold">📄 PDF</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($pr['pr_status'] === 'pending'): ?>
            <div id="<?= $modal_id ?>" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
                <div class="bg-white rounded-2xl shadow-xl max-w-sm w-full p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-3">Reject <?= htmlspecialchars($pr['pr_number']) ?></h3>
                    <form method="POST">
                        <input type="hidden" name="reject_pr" value="1">
                        <input type="hidden" name="pr_id" value="<?= $pr['pr_id'] ?>">
                        <textarea name="review_note" rows="3" required placeholder="Reason for rejection (required)"
                                  class="w-full px-3 py-2 border-2 border-gray-100 rounded-lg text-sm focus:outline-none focus:border-red-400 resize-none mb-3"></textarea>
                        <div class="flex gap-2">
                            <button type="button" onclick="document.getElementById('<?= $modal_id ?>').classList.add('hidden')"
                                    class="flex-1 border-2 border-gray-100 hover:bg-gray-50 text-gray-600 font-semibold py-2 rounded-lg text-sm transition-colors">
                                Cancel
                            </button>
                            <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-2 rounded-lg text-sm transition-colors">
                                Confirm Reject
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>

</body>
</html>