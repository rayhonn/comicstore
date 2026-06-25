<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}
require_once '../includes/db.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

$success = '';
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_pr'])) {
    $pr_id = $_POST['pr_id'];
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
    JOIN users u1 ON u1.user_id = pr.pr_requested_by
    LEFT JOIN users u2 ON u2.user_id = pr.pr_reviewed_by
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

        <div class="mb-8">
            <h1 class="text-2xl font-black text-gray-800">📝 Purchase Requisitions</h1>
            <p class="text-gray-500 text-sm mt-1">Review restock requests submitted by staff</p>
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
            ?>
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <p class="font-bold text-gray-800"><?= htmlspecialchars($pr['pr_number']) ?></p>
                        <p class="text-xs text-gray-400">Requested by <?= htmlspecialchars($pr['requested_by_name']) ?> · <?= date('d M Y', strtotime($pr['pr_created_at'])) ?></p>
                    </div>
                    <span class="<?= $status_colors[$pr['pr_status']] ?> text-xs px-3 py-1 rounded-full font-semibold capitalize">
                        <?= $pr['pr_status'] ?>
                    </span>
                </div>

                <div class="bg-gray-50 rounded-xl p-4 mb-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($pr['product_title']) ?><?= $pr['product_volume_number'] ? ' Vol.' . $pr['product_volume_number'] : '' ?></p>
                            <p class="text-xs text-gray-400">Current stock: <?= $pr['physical_stock_quantity'] ?? '—' ?></p>
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
                    <form method="POST" class="inline">
                        <input type="hidden" name="approve_pr" value="1">
                        <input type="hidden" name="pr_id" value="<?= $pr['pr_id'] ?>">
                        <button type="submit" class="bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-bold px-4 py-2 rounded-lg transition-colors">
                            ✓ Approve
                        </button>
                    </form>
                    <button onclick="document.getElementById('<?= $modal_id ?>').classList.remove('hidden')"
                            class="bg-red-50 hover:bg-red-100 text-red-600 text-xs font-bold px-4 py-2 rounded-lg transition-colors">
                        ✕ Reject
                    </button>
                    <?php elseif ($pr['pr_status'] === 'approved'): ?>
                    <a href="rfq.php?from_pr=<?= $pr['pr_id'] ?>" class="bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold px-4 py-2 rounded-lg transition-colors">
                        📋 Convert to RFQ
                    </a>
                    <?php elseif ($pr['pr_status'] === 'converted'): ?>
                    <a href="rfq.php" class="text-xs text-green-600 hover:underline font-semibold">View RFQ →</a>
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