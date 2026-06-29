<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}
require_once '../includes/db.php';

$from_pr_id = $_GET['from_pr'] ?? null;
$from_pr = null;
if ($from_pr_id) {
    $stmt = $pdo->prepare("SELECT pr.*, p.product_title, p.product_volume_number FROM purchase_requisitions pr JOIN products p ON p.product_id = pr.pr_product_id WHERE pr.pr_id = ? AND pr.pr_status = 'approved'");
    $stmt->execute([$from_pr_id]);
    $from_pr = $stmt->fetch(PDO::FETCH_ASSOC);
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_rfq'])) {
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $supplier_ids = $_POST['supplier_ids'] ?? [];
    $notes = trim($_POST['notes'] ?? '');

    if (empty($product_ids) || empty($supplier_ids)) {
        $error = 'Please select at least one product and one supplier.';
    } else {
        $last = $pdo->query("SELECT rfq_id FROM rfq ORDER BY rfq_id DESC LIMIT 1")->fetchColumn();
        $next_num = ($last ?? 0) + 1;
        $rfq_number = 'RFQ-' . str_pad($next_num, 4, '0', STR_PAD_LEFT);

        $pdo->prepare("INSERT INTO rfq (rfq_number, rfq_notes, rfq_created_by) VALUES (?, ?, ?)")
            ->execute([$rfq_number, $notes, $_SESSION['user_id']]);
        $rfq_id = $pdo->lastInsertId();

        foreach ($product_ids as $i => $pid) {
            if (empty($pid) || empty($quantities[$i])) continue;
            $pdo->prepare("INSERT INTO rfq_items (rfq_item_rfq_id, rfq_item_product_id, rfq_item_quantity) VALUES (?, ?, ?)")
                ->execute([$rfq_id, $pid, $quantities[$i]]);
        }

        foreach ($supplier_ids as $sid) {
            $pdo->prepare("INSERT INTO rfq_suppliers (rfq_supplier_rfq_id, rfq_supplier_supplier_id) VALUES (?, ?)")
                ->execute([$rfq_id, $sid]);
        }

        if (!empty($_POST['from_pr_id'])) {
            $pdo->prepare("UPDATE purchase_requisitions SET pr_status = 'converted', pr_rfq_id = ? WHERE pr_id = ?")
                ->execute([$rfq_id, $_POST['from_pr_id']]);
        }

        $_SESSION['flash_success'] = "$rfq_number created successfully and sent to " . count($supplier_ids) . " supplier(s).";
        header('Location: rfq.php');
        exit;
    }
}

if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

$rfqs = $pdo->query("
    SELECT r.*, 
    COUNT(DISTINCT ri.rfq_item_id) as item_count,
    COUNT(DISTINCT rs.rfq_supplier_id) as supplier_count
    FROM rfq r
    LEFT JOIN rfq_items ri ON ri.rfq_item_rfq_id = r.rfq_id
    LEFT JOIN rfq_suppliers rs ON rs.rfq_supplier_rfq_id = r.rfq_id
    GROUP BY r.rfq_id
    ORDER BY r.rfq_created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$products = $pdo->query("
    SELECT product_id, product_title, product_series, product_volume_number, product_cover_image
    FROM products WHERE product_type = 'physical' AND product_is_available = 1
    ORDER BY product_title
")->fetchAll(PDO::FETCH_ASSOC);

$suppliers = $pdo->query("
    SELECT s.*,
    AVG(DATEDIFF(gr.gr_received_at, po.po_created_at)) as avg_lead_time
    FROM suppliers s
    LEFT JOIN purchase_orders po ON po.po_supplier_id = s.supplier_id
    LEFT JOIN goods_received gr ON gr.gr_po_id = po.po_id AND gr.gr_status = 'completed'
    WHERE s.supplier_status = 'active'
    GROUP BY s.supplier_id
    ORDER BY s.supplier_name
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RFQ - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-6xl mx-auto px-6 py-8">

        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-black text-gray-800">📋 Request for Quotation (RFQ)</h1>
                <p class="text-gray-500 text-sm mt-1">Send quotation requests to suppliers for stock procurement</p>
            </div>
            <button onclick="openRfqModal()"
                    class="bg-red-600 hover:bg-red-700 text-white font-bold px-5 py-2.5 rounded-xl text-sm transition-colors">
                + New RFQ
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

        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <?php if (count($rfqs) === 0): ?>
            <div class="text-center py-16">
                <div class="text-5xl mb-4">📋</div>
                <p class="text-gray-400">No RFQs yet. Create your first request for quotation.</p>
            </div>
            <?php else: ?>
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">RFQ Number</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Items</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Suppliers</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rfqs as $r):
                        $status_colors = [
                            'pending' => 'bg-yellow-100 text-yellow-700',
                            'quoted'  => 'bg-blue-100 text-blue-700',
                            'closed'  => 'bg-gray-100 text-gray-500',
                        ];
                    ?>
                    <tr class="border-b border-gray-50 hover:bg-gray-50 transition-colors">
                        <td class="px-5 py-4 font-semibold text-sm text-gray-800"><?= htmlspecialchars($r['rfq_number']) ?></td>
                        <td class="px-5 py-4 text-sm text-gray-600"><?= $r['item_count'] ?> product(s)</td>
                        <td class="px-5 py-4 text-sm text-gray-600"><?= $r['supplier_count'] ?> supplier(s)</td>
                        <td class="px-5 py-4 text-center">
                            <span class="<?= $status_colors[$r['rfq_status']] ?> text-xs px-3 py-1 rounded-full font-semibold capitalize">
                                <?= $r['rfq_status'] ?>
                            </span>
                        </td>
                        <td class="px-5 py-4 text-xs text-gray-400"><?= date('d M Y', strtotime($r['rfq_created_at'])) ?></td>
                        <td class="px-5 py-4 text-center">
                            <a href="quotations.php?rfq_id=<?= $r['rfq_id'] ?>" class="text-xs text-blue-600 hover:underline font-semibold">
                                View / Enter Quotes →
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- New RFQ Modal -->
    <div id="rfqModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center px-6 py-10 overflow-y-auto">
        <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full p-6 my-auto">
            <div class="flex items-center justify-between mb-5">
                <h3 class="font-black text-gray-800 text-lg">Create New RFQ</h3>
                <button onclick="closeRfqModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <form method="POST" id="rfqForm">
                <input type="hidden" name="create_rfq" value="1">
                <input type="hidden" name="from_pr_id" value="<?= $from_pr_id ?? '' ?>">

                <div class="mb-5">
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide">Products to Request *</label>
                        <button type="button" onclick="addProductRow()" class="text-xs text-red-600 hover:underline font-semibold">+ Add Product</button>
                    </div>
                    <div id="productRows" class="space-y-2">
                        <!-- Filled by JS -->
                    </div>
                </div>

                <div class="mb-5">
                    <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Send To Suppliers *</label>
                    <?php if (count($suppliers) === 0): ?>
                    <p class="text-sm text-gray-400">No active suppliers. <a href="suppliers.php" class="text-red-600 hover:underline">Add a supplier first.</a></p>
                    <?php else: ?>
                    <div class="grid grid-cols-2 gap-2">
                        <?php foreach ($suppliers as $s): ?>
                        <label class="flex items-center gap-2 p-3 border-2 border-gray-100 rounded-xl cursor-pointer hover:border-red-300 transition-colors has-[:checked]:border-red-500 has-[:checked]:bg-red-50">
                            <input type="checkbox" name="supplier_ids[]" value="<?= $s['supplier_id'] ?>" class="accent-red-600">
                            <div class="min-w-0">
                                <span class="text-sm text-gray-700"><?= htmlspecialchars($s['supplier_name']) ?></span>
                                <?php if ($s['avg_lead_time'] !== null): ?>
                                <span class="text-xs text-gray-400 block">~<?= round($s['avg_lead_time'], 1) ?> days lead time</span>
                                <?php endif; ?>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="mb-5">
                    <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Notes (Optional)</label>
                    <textarea name="notes" rows="2" placeholder="Any special requirements..."
                            class="w-full px-4 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors resize-none"></textarea>
                </div>

                <div class="flex gap-3">
                    <button type="button" onclick="closeRfqModal()"
                            class="flex-1 border-2 border-gray-100 hover:bg-gray-50 text-gray-600 font-semibold py-2.5 rounded-xl text-sm transition-colors">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 rounded-xl text-sm transition-colors">
                        Create & Send RFQ
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    const allProducts = <?= json_encode(array_map(function($p) {
        return [
            'id' => $p['product_id'],
            'title' => $p['product_title'],
            'series' => $p['product_series'] ?? '',
            'volume' => $p['product_volume_number'] ?? '',
            'cover' => $p['product_cover_image'] ?? null,
        ];
    }, $products)) ?>;

    let rowCounter = 0;

    function openRfqModal() {
        document.getElementById('rfqModal').classList.remove('hidden');
        if (document.querySelectorAll('.product-row').length === 0) {
            addProductRow();
        }
    }
    function closeRfqModal() {
        document.getElementById('rfqModal').classList.add('hidden');
    }

    function addProductRow() {
        rowCounter++;
        const rid = rowCounter;
        const container = document.getElementById('productRows');
        const row = document.createElement('div');
        row.className = 'product-row flex gap-2 items-start';
        row.dataset.rid = rid;
        row.innerHTML = `
            <div class="flex-1 relative">
                <button type="button" onclick="toggleDropdown(${rid})" id="trigger-${rid}"
                        class="w-full flex items-center gap-2 px-3 py-2 border-2 border-gray-100 rounded-xl text-sm text-left bg-white hover:border-red-300 transition-colors">
                    <div id="thumb-${rid}" class="w-7 h-9 bg-gray-100 rounded flex-shrink-0 hidden overflow-hidden"></div>
                    <span id="label-${rid}" class="text-gray-400 flex-1 truncate">Select product...</span>
                    <svg class="w-4 h-4 text-gray-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </button>
                <input type="hidden" name="product_id[]" id="pid-${rid}" required>
                <div id="dropdown-${rid}" class="hidden absolute z-50 mt-1 w-full bg-white border-2 border-gray-100 rounded-xl shadow-xl max-h-72 overflow-hidden flex flex-col">
                    <input type="text" placeholder="🔍 Search..." oninput="filterDropdown(${rid}, this.value)"
                           class="w-full px-3 py-2 border-b border-gray-100 text-sm focus:outline-none">
                    <div id="list-${rid}" class="overflow-y-auto flex-1"></div>
                </div>
            </div>
            <input type="number" name="quantity[]" placeholder="Qty" min="1" required
                   class="w-20 px-3 py-2 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 flex-shrink-0">
            <button type="button" onclick="removeRow(${rid})" class="text-gray-400 hover:text-red-600 px-2 flex-shrink-0">✕</button>
        `;
        container.appendChild(row);
        renderDropdownList(rid, allProducts);
    }

    function renderDropdownList(rid, products) {
        const list = document.getElementById('list-' + rid);
        if (products.length === 0) {
            list.innerHTML = '<p class="text-center text-gray-400 text-sm py-4">No products found.</p>';
            return;
        }
        list.innerHTML = products.map(p => `
            <button type="button" onclick='pickProduct(${rid}, ${JSON.stringify(p)})'
                    class="w-full flex items-center gap-3 px-3 py-2 hover:bg-gray-50 text-left transition-colors">
                <div class="w-8 h-11 bg-gray-100 rounded flex-shrink-0 overflow-hidden">
                    ${p.cover
                        ? `<img src="../assets/images/${p.cover}" style="width:100%; height:100%; object-fit:cover;" onerror="this.style.display='none'">`
                        : ''
                    }
                </div>
                <div class="min-w-0">
                    <p class="text-sm text-gray-800 truncate">${p.title}</p>
                    <p class="text-xs text-gray-400 truncate">${p.series}</p>
                </div>
            </button>
        `).join('');
    }

    function filterDropdown(rid, query) {
        const filtered = allProducts.filter(p =>
            p.title.toLowerCase().includes(query.toLowerCase()) ||
            p.series.toLowerCase().includes(query.toLowerCase())
        );
        renderDropdownList(rid, filtered);
    }

    function toggleDropdown(rid) {
        document.querySelectorAll('[id^="dropdown-"]').forEach(d => {
            if (d.id !== 'dropdown-' + rid) d.classList.add('hidden');
        });
        document.getElementById('dropdown-' + rid).classList.toggle('hidden');
    }

    function pickProduct(rid, product) {
        document.getElementById('pid-' + rid).value = product.id;
        document.getElementById('label-' + rid).textContent = product.title;
        document.getElementById('label-' + rid).classList.remove('text-gray-400');
        document.getElementById('label-' + rid).classList.add('text-gray-800');

        const thumb = document.getElementById('thumb-' + rid);
        if (product.cover) {
            thumb.innerHTML = `<img src="../assets/images/${product.cover}" style="width:100%; height:100%; object-fit:cover;" onerror="this.parentElement.classList.add('hidden')">`;
            thumb.classList.remove('hidden');
        } else {
            thumb.classList.add('hidden');
        }

        document.getElementById('dropdown-' + rid).classList.add('hidden');
    }

    function removeRow(rid) {
        const rows = document.querySelectorAll('.product-row');
        if (rows.length > 1) {
            document.querySelector(`.product-row[data-rid="${rid}"]`).remove();
        }
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('[id^="trigger-"]') && !e.target.closest('[id^="dropdown-"]')) {
            document.querySelectorAll('[id^="dropdown-"]').forEach(d => d.classList.add('hidden'));
        }
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('[id^="trigger-"]') && !e.target.closest('[id^="dropdown-"]')) {
            document.querySelectorAll('[id^="dropdown-"]').forEach(d => d.classList.add('hidden'));
        }
    });

    <?php if ($from_pr): ?>
    window.addEventListener('DOMContentLoaded', function() {
        openRfqModal();
        document.getElementById('pid-1').value = <?= $from_pr['pr_product_id'] ?>;
        document.getElementById('label-1').textContent = <?= json_encode($from_pr['product_title']) ?>;
        document.getElementById('label-1').classList.remove('text-gray-400');
        document.getElementById('label-1').classList.add('text-gray-800');
        document.querySelector('input[name="quantity[]"]').value = <?= $from_pr['pr_suggested_quantity'] ?>;
    });
    <?php endif; ?>
    </script>

</body>
</html>