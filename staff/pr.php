<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header('Location: ../admin/login.php');
    exit;
}
require_once '../includes/db.php';

if (isset($_GET['download_pdf'])) {
    require_once '../vendor/autoload.php';

    $pr_pdf_id = $_GET['download_pdf'];
    $pr_pdf = $pdo->prepare("
        SELECT pr.*, p.product_title, p.product_volume_number,
        CONCAT_WS(' ', u1.user_first_name, u1.user_last_name) AS requested_by_name,
        CONCAT_WS(' ', u2.user_first_name, u2.user_last_name) AS reviewed_by_name
        FROM purchase_requisitions pr
        JOIN products p ON p.product_id = pr.pr_product_id
        LEFT JOIN users u1 ON u1.user_id = pr.pr_requested_by
        LEFT JOIN users u2 ON u2.user_id = pr.pr_reviewed_by
        WHERE pr.pr_id = ? AND pr.pr_requested_by = ?
    ");
    $pr_pdf->execute([$pr_pdf_id, $_SESSION['user_id']]);
    $pr_pdf = $pr_pdf->fetch(PDO::FETCH_ASSOC);
    if (!$pr_pdf) { header('Location: pr.php'); exit; }

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
            <h2 style='font-size:18px; color:#111827; margin:0 0 4px;'>" . htmlspecialchars($pr_pdf['pr_number']) . "</h2>
            <p style='font-size:12px; color:#6b7280; margin:0;'>Date: " . date('d F Y', strtotime($pr_pdf['pr_created_at'])) . "</p>
            <p style='font-size:12px; color:#6b7280; margin:2px 0 0;'>Status: <strong style='text-transform:uppercase;'>" . htmlspecialchars($pr_pdf['pr_status']) . "</strong></p>
        </div>

        <table style='width:100%; border-collapse:collapse; margin-bottom:24px;'>
            <tr style='background:#1e2d4a; color:white;'>
                <td style='padding:10px 12px; font-size:11px; font-weight:700;'>Item</td>
                <td style='padding:10px 12px; font-size:11px; font-weight:700; text-align:center;'>Suggested Qty</td>
            </tr>
            <tr style='border-bottom:1px solid #e5e7eb;'>
                <td style='padding:10px 12px; font-size:12px;'>" . htmlspecialchars($pr_pdf['product_title']) . ($pr_pdf['product_volume_number'] ? ' (Vol.' . $pr_pdf['product_volume_number'] . ')' : '') . "</td>
                <td style='padding:10px 12px; font-size:12px; text-align:center;'>" . $pr_pdf['pr_suggested_quantity'] . "</td>
            </tr>
        </table>

        <div style='background:#f9fafb; border-radius:8px; padding:16px; margin-bottom:24px;'>
            <p style='font-size:11px; color:#9ca3af; margin:0 0 6px; text-transform:uppercase; font-weight:700;'>Reason</p>
            <p style='font-size:13px; color:#374151; margin:0;'>" . htmlspecialchars($pr_pdf['pr_reason'] ?: '—') . "</p>
        </div>" .

        ($pr_pdf['pr_status'] === 'rejected' && $pr_pdf['pr_review_note'] ? "
        <div style='background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:16px; margin-bottom:24px;'>
            <p style='font-size:11px; color:#991b1b; margin:0 0 6px; text-transform:uppercase; font-weight:700;'>Rejection Reason</p>
            <p style='font-size:13px; color:#374151; margin:0;'>" . htmlspecialchars($pr_pdf['pr_review_note']) . "</p>
        </div>" : "") . "

        <div style='display:table; width:100%; margin-top:40px; margin-bottom:24px;'>
            <div style='display:table-cell; width:50%; padding-right:20px;'>
                <p style='font-size:11px; color:#9ca3af; margin:0 0 40px;'>Requested By</p>
                <p style='border-top:1px solid #111827; padding-top:6px; font-size:12px; font-weight:700; margin:0;'>" . htmlspecialchars($pr_pdf['requested_by_name'] ?: '—') . "</p>
                <p style='font-size:12px; color:#6b7280; margin:2px 0 0;'>Staff</p>
                <p style='font-size:12px; color:#6b7280; margin:2px 0 0;'>" . date('d M Y', strtotime($pr_pdf['pr_created_at'])) . "</p>
            </div>
            <div style='display:table-cell; width:50%; padding-left:20px;'>
                <p style='font-size:11px; color:#9ca3af; margin:0 0 40px;'>Approved By</p>
                <p style='border-top:1px solid #111827; padding-top:6px; font-size:12px; font-weight:700; margin:0; min-height:14px;'>" . htmlspecialchars($pr_pdf['pr_status'] !== 'pending' ? $pr_pdf['reviewed_by_name'] : '') . "</p>
                <p style='font-size:12px; color:#6b7280; margin:2px 0 0;'>Admin</p>
                <p style='font-size:12px; color:#6b7280; margin:2px 0 0;'>" . ($pr_pdf['pr_reviewed_at'] ? date('d M Y', strtotime($pr_pdf['pr_reviewed_at'])) : '') . "</p>
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
    $dompdf->stream("{$pr_pdf['pr_number']}.pdf", ['Attachment' => true]);
    exit;
}

date_default_timezone_set('Asia/Kuala_Lumpur');

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_pr'])) {
    $product_id = $_POST['product_id'] ?? '';
    $quantity = intval($_POST['quantity'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if (!$product_id || $quantity <= 0) {
        $error = 'Please select a product and enter a valid quantity.';
    } else {
        $last = $pdo->query("SELECT pr_id FROM purchase_requisitions ORDER BY pr_id DESC LIMIT 1")->fetchColumn();
        $pr_number = 'PR-' . str_pad(($last ?? 0) + 1, 4, '0', STR_PAD_LEFT);

        $pdo->prepare("
            INSERT INTO purchase_requisitions (pr_number, pr_product_id, pr_suggested_quantity, pr_reason, pr_requested_by)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$pr_number, $product_id, $quantity, $reason, $_SESSION['user_id']]);

        $_SESSION['flash_success'] = "$pr_number submitted for admin approval.";
        header('Location: pr.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim_draft'])) {
    $pr_id = $_POST['pr_id'];
    $quantity = intval($_POST['quantity'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($quantity <= 0) {
        $_SESSION['flash_success'] = 'Please enter a valid quantity.';
        header('Location: pr.php'); exit;
    }

    $pdo->prepare("
        UPDATE purchase_requisitions
        SET pr_status = 'pending', pr_requested_by = ?, pr_suggested_quantity = ?, pr_reason = ?
        WHERE pr_id = ? AND pr_status = 'draft'
    ")->execute([$_SESSION['user_id'], $quantity, $reason, $pr_id]);

    $_SESSION['flash_success'] = 'Draft claimed and submitted for admin approval.';
    header('Location: pr.php');
    exit;
}

if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

$preselect_product_id = $_GET['product_id'] ?? null;

$products = $pdo->query("
    SELECT p.product_id, p.product_title, p.product_volume_number, p.product_cover_image, pp.physical_stock_quantity, pp.physical_low_stock_threshold
    FROM products p
    JOIN product_physical pp ON pp.physical_product_id = p.product_id
    WHERE p.product_type = 'physical' AND p.product_is_available = 1
    ORDER BY p.product_title
")->fetchAll(PDO::FETCH_ASSOC);

$my_prs = $pdo->prepare("
    SELECT pr.*, p.product_title
    FROM purchase_requisitions pr
    JOIN products p ON p.product_id = pr.pr_product_id
    WHERE pr.pr_requested_by = ?
    ORDER BY pr.pr_created_at DESC
");
$my_prs->execute([$_SESSION['user_id']]);
$my_prs = $my_prs->fetchAll(PDO::FETCH_ASSOC);

$drafts = $pdo->query("
    SELECT pr.*, p.product_title, p.product_volume_number, p.product_cover_image, pp.physical_stock_quantity
    FROM purchase_requisitions pr
    JOIN products p ON p.product_id = pr.pr_product_id
    LEFT JOIN product_physical pp ON pp.physical_product_id = p.product_id
    WHERE pr.pr_status = 'draft'
    ORDER BY pr.pr_created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Requisitions - MangaVault Staff</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/staff_navbar.php'; ?>

    <div class="max-w-4xl mx-auto px-6 py-8">

        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-black text-gray-800">📝 Purchase Requisitions</h1>
                <p class="text-gray-500 text-sm mt-1">Request restocking for low-stock or out-of-stock items</p>
            </div>
            <button onclick="document.getElementById('prModal').classList.remove('hidden')"
                    class="bg-red-600 hover:bg-red-700 text-white font-bold px-5 py-2.5 rounded-xl text-sm transition-colors">
                + New PR
            </button>
        </div>

        <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-6">
            ✅ <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-6">
            ❌ <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if (count($drafts) > 0): ?>
        <div class="bg-purple-50 border border-purple-200 rounded-2xl p-5 mb-6">
            <h3 class="font-bold text-purple-700 mb-3">🤖 System-Detected Low Stock — Needs Your Review</h3>
            <div class="space-y-2">
                <?php foreach ($drafts as $d): ?>
                <div class="bg-white rounded-xl p-4 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($d['product_title']) ?></p>
                        <p class="text-xs text-gray-400">Current stock: <?= $d['physical_stock_quantity'] ?? '—' ?> · Suggested: <?= $d['pr_suggested_quantity'] ?> units</p>
                    </div>
                    <button onclick='openClaimModal(<?= json_encode($d) ?>)'
                            class="bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold px-4 py-2 rounded-lg transition-colors">
                        Review & Submit
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <?php if (count($my_prs) === 0): ?>
            <div class="text-center py-16">
                <div class="text-5xl mb-4">📝</div>
                <p class="text-gray-400">No requisitions submitted yet.</p>
            </div>
            <?php else: ?>
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">PR Number</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Product</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Qty</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">PDF</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($my_prs as $pr):
                        $status_colors = [
                            'pending'   => 'bg-yellow-100 text-yellow-700',
                            'approved'  => 'bg-blue-100 text-blue-700',
                            'rejected'  => 'bg-red-100 text-red-700',
                            'converted' => 'bg-green-100 text-green-700',
                        ];
                    ?>
                    <tr class="border-b border-gray-50">
                        <td class="px-5 py-4 font-semibold text-sm text-gray-800"><?= htmlspecialchars($pr['pr_number']) ?></td>
                        <td class="px-5 py-4 text-sm text-gray-600"><?= htmlspecialchars($pr['product_title']) ?></td>
                        <td class="px-5 py-4 text-center text-sm text-gray-600"><?= $pr['pr_suggested_quantity'] ?></td>
                        <td class="px-5 py-4 text-center">
                            <span class="<?= $status_colors[$pr['pr_status']] ?> text-xs px-3 py-1 rounded-full font-semibold capitalize">
                                <?= $pr['pr_status'] ?>
                            </span>
                            <?php if ($pr['pr_status'] === 'rejected' && $pr['pr_review_note']): ?>
                            <p class="text-xs text-red-400 mt-1"><?= htmlspecialchars($pr['pr_review_note']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-4 text-xs text-gray-400"><?= date('d M Y', strtotime($pr['pr_created_at'])) ?></td>
                        <td class="px-5 py-4 text-center">
                            <a href="pr.php?download_pdf=<?= $pr['pr_id'] ?>" class="text-xs text-blue-600 hover:underline font-semibold">📄</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- New PR Modal -->
    <div id="prModal" class="<?= $preselect_product_id ? '' : 'hidden' ?> fixed inset-0 bg-black/50 z-50 flex items-center justify-center px-6 py-10 overflow-y-auto">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6 my-auto">
            <div class="flex items-center justify-between mb-5">
                <h3 class="font-black text-gray-800 text-lg">New Purchase Requisition</h3>
                <button onclick="document.getElementById('prModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <form method="POST">
                <input type="hidden" name="submit_pr" value="1">

                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Product *</label>
                    <div class="relative">
                        <button type="button" onclick="toggleProductDropdown()" id="prTrigger"
                                class="w-full flex items-center gap-2 px-3 py-2.5 border-2 border-gray-100 rounded-xl text-sm text-left bg-white hover:border-red-300 transition-colors">
                            <div id="prThumb" class="w-7 h-9 bg-gray-100 rounded flex-shrink-0 hidden overflow-hidden"></div>
                            <span id="prLabel" class="text-gray-400 flex-1 truncate">Select a product...</span>
                            <svg class="w-4 h-4 text-gray-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </button>
                        <input type="hidden" name="product_id" id="prProductId" required value="<?= htmlspecialchars($preselect_product_id ?? '') ?>">
                        <div id="prDropdown" class="hidden absolute z-50 mt-1 w-full bg-white border-2 border-gray-100 rounded-xl shadow-xl max-h-60 overflow-hidden flex flex-col">
                            <input type="text" placeholder="🔍 Search..." oninput="filterPrDropdown(this.value)"
                                class="w-full px-3 py-2 border-b border-gray-100 text-sm focus:outline-none">
                            <div id="prList" class="overflow-y-auto flex-1"></div>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Suggested Quantity *</label>
                    <input type="number" name="quantity" min="1" required placeholder="e.g. 20"
                           class="w-full px-3 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400">
                </div>

                <div class="mb-5">
                    <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Reason</label>
                    <textarea name="reason" rows="3" placeholder="e.g. Stock running low, frequent customer requests..."
                              class="w-full px-3 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 resize-none"></textarea>
                </div>

                <div class="flex gap-3">
                    <button type="button" onclick="document.getElementById('prModal').classList.add('hidden')"
                            class="flex-1 border-2 border-gray-100 hover:bg-gray-50 text-gray-600 font-semibold py-2.5 rounded-xl text-sm transition-colors">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 rounded-xl text-sm transition-colors">
                        Submit PR
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Claim Draft Modal -->
    <div id="claimModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center px-6 py-10 overflow-y-auto">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6 my-auto">
            <div class="flex items-center justify-between mb-5">
                <h3 class="font-black text-gray-800 text-lg">Review System-Detected PR</h3>
                <button onclick="document.getElementById('claimModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <form method="POST">
                <input type="hidden" name="claim_draft" value="1">
                <input type="hidden" name="pr_id" id="claim_pr_id">

                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Product</label>
                    <p id="claim_product_name" class="text-sm font-semibold text-gray-800 bg-gray-50 rounded-xl px-3 py-2.5"></p>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Quantity *</label>
                    <input type="number" name="quantity" id="claim_quantity" min="1" required
                           class="w-full px-3 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-purple-400">
                </div>

                <div class="mb-5">
                    <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Reason</label>
                    <textarea name="reason" id="claim_reason" rows="3"
                              class="w-full px-3 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-purple-400 resize-none"></textarea>
                </div>

                <div class="flex gap-3">
                    <button type="button" onclick="document.getElementById('claimModal').classList.add('hidden')"
                            class="flex-1 border-2 border-gray-100 hover:bg-gray-50 text-gray-600 font-semibold py-2.5 rounded-xl text-sm transition-colors">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 bg-purple-600 hover:bg-purple-700 text-white font-bold py-2.5 rounded-xl text-sm transition-colors">
                        Submit PR
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
const prAllProducts = <?= json_encode(array_map(function($p) {
    return [
        'id' => $p['product_id'],
        'title' => $p['product_title'],
        'stock' => $p['physical_stock_quantity'],
        'threshold' => $p['physical_low_stock_threshold'],
        'cover' => $p['product_cover_image'] ?? null,
    ];
}, $products)) ?>;

function toggleProductDropdown() {
    document.getElementById('prDropdown').classList.toggle('hidden');
}

function renderPrList(products) {
    const list = document.getElementById('prList');
    if (products.length === 0) {
        list.innerHTML = '<p class="text-center text-gray-400 text-sm py-4">No products found.</p>';
        return;
    }
    list.innerHTML = products.map(p => `
        <button type="button" onclick='pickPrProduct(${JSON.stringify(p)})'
                class="w-full flex items-center gap-3 px-3 py-2 hover:bg-gray-50 text-left transition-colors">
            <div class="w-8 h-11 bg-gray-100 rounded flex-shrink-0 overflow-hidden">
                ${p.cover ? `<img src="../assets/images/${p.cover}" style="width:100%; height:100%; object-fit:cover;" onerror="this.style.display='none'">` : ''}
            </div>
            <div class="min-w-0">
                <p class="text-sm text-gray-800 truncate">${p.title}</p>
                <p class="text-xs ${p.stock <= p.threshold ? 'text-red-500 font-semibold' : 'text-gray-400'}">${p.stock} in stock${p.stock <= p.threshold ? ' ⚠️ Low Stock' : ''}</p>
            </div>
        </button>
    `).join('');
}

function filterPrDropdown(query) {
    const filtered = prAllProducts.filter(p => p.title.toLowerCase().includes(query.toLowerCase()));
    renderPrList(filtered);
}

function pickPrProduct(product) {
    document.getElementById('prProductId').value = product.id;
    document.getElementById('prLabel').textContent = product.title;
    document.getElementById('prLabel').classList.remove('text-gray-400');
    document.getElementById('prLabel').classList.add('text-gray-800');

    const thumb = document.getElementById('prThumb');
    if (product.cover) {
        thumb.innerHTML = `<img src="../assets/images/${product.cover}" style="width:100%; height:100%; object-fit:cover;">`;
        thumb.classList.remove('hidden');
    } else {
        thumb.classList.add('hidden');
    }
    document.getElementById('prDropdown').classList.add('hidden');
}

function openClaimModal(draft) {
    document.getElementById('claim_pr_id').value = draft.pr_id;
    document.getElementById('claim_product_name').textContent = draft.product_title;
    document.getElementById('claim_quantity').value = draft.pr_suggested_quantity;
    document.getElementById('claim_reason').value = draft.pr_reason;
    document.getElementById('claimModal').classList.remove('hidden');
}

renderPrList(prAllProducts);

document.addEventListener('click', function(e) {
    if (!e.target.closest('#prTrigger') && !e.target.closest('#prDropdown')) {
        document.getElementById('prDropdown').classList.add('hidden');
    }
});
</script>

</body>
</html>