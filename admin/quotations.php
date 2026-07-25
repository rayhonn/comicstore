<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}
require_once '../includes/db.php';
require_once '../includes/csrf.php';

$rfq_id = filter_var(
    $_GET['rfq_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($rfq_id === false) {
    header('Location: rfq.php');
    exit;
}

$rfq = $pdo->prepare("SELECT * FROM rfq WHERE rfq_id = ?");
$rfq->execute([$rfq_id]);
$rfq = $rfq->fetch(PDO::FETCH_ASSOC);
if (!$rfq) {
    header('Location: rfq.php');
    exit;
}

// Get RFQ items
$rfq_items = $pdo->prepare("
    SELECT ri.*, p.product_title, p.product_volume_number, p.product_cover_image
    FROM rfq_items ri
    JOIN products p ON p.product_id = ri.rfq_item_product_id
    WHERE ri.rfq_item_rfq_id = ?
");
$rfq_items->execute([$rfq_id]);
$rfq_items = $rfq_items->fetchAll(PDO::FETCH_ASSOC);

// Get all suppliers this RFQ was sent to
$sent_suppliers = $pdo->prepare("
    SELECT s.* FROM rfq_suppliers rs
    JOIN suppliers s ON s.supplier_id = rs.rfq_supplier_supplier_id
    WHERE rs.rfq_supplier_rfq_id = ?
");
$sent_suppliers->execute([$rfq_id]);
$sent_suppliers = $sent_suppliers->fetchAll(PDO::FETCH_ASSOC);

// Get quotations submitted
$quotations = $pdo->prepare("
    SELECT q.*, s.supplier_name
    FROM quotations q
    JOIN suppliers s ON s.supplier_id = q.quotation_supplier_id
    WHERE q.quotation_rfq_id = ?
");
$quotations->execute([$rfq_id]);
$quotations = $quotations->fetchAll(PDO::FETCH_ASSOC);

// Get quotation items for each quotation
foreach ($quotations as &$q) {
    $qi = $pdo->prepare("SELECT * FROM quotation_items WHERE quotation_item_quotation_id = ?");
    $qi->execute([$q['quotation_id']]);
    $q['items'] = $qi->fetchAll(PDO::FETCH_ASSOC);
    $q['total'] = array_sum(array_map(fn($i) => $i['quotation_item_quantity'] * $i['quotation_item_unit_price'], $q['items']));
}
unset($q);

// Fetch supplier performance data and compute weighted scores
foreach ($quotations as &$q) {
    $perf = $pdo->prepare("
        SELECT
        AVG(po.po_rating) as avg_rating,
        AVG(DATEDIFF(gr.gr_received_at, po.po_created_at)) as avg_lead_time
        FROM purchase_orders po
        LEFT JOIN goods_received gr ON gr.gr_po_id = po.po_id AND gr.gr_status = 'completed'
        WHERE po.po_supplier_id = ?
    ");
    $perf->execute([$q['quotation_supplier_id']]);
    $perf = $perf->fetch(PDO::FETCH_ASSOC);
    $q['avg_rating'] = $perf['avg_rating'];
    $q['avg_lead_time'] = $perf['avg_lead_time'];
}
unset($q);

if (count($quotations) > 0) {
    $min_total = min(array_column($quotations, 'total'));
    $lead_times = array_filter(array_column($quotations, 'avg_lead_time'), fn($v) => $v !== null);
    $min_lead_time = count($lead_times) > 0 ? min($lead_times) : null;

    foreach ($quotations as &$q) {
        $price_score = $min_total > 0 ? ($min_total / $q['total']) * 100 : 100;
        $rating_score = $q['avg_rating'] !== null ? ($q['avg_rating'] / 5) * 100 : 50;
        $lead_score = ($min_lead_time !== null && $q['avg_lead_time'] !== null && $q['avg_lead_time'] > 0)
            ? ($min_lead_time / $q['avg_lead_time']) * 100 : 50;

        $q['score'] = ($price_score * 0.6) + ($rating_score * 0.2) + ($lead_score * 0.2);
    }
    unset($q);

    $best_score = max(array_column($quotations, 'score'));
} else {
    $best_score = null;
}

// Handle generate PO
$success = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_po'])) {
    csrf_verify();

    $quotation_id = filter_var(
        $_POST['quotation_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($quotation_id === false) {
        $error = 'Invalid quotation selected.';
    } else {
        try {
            $pdo->beginTransaction();

            // Lock the RFQ so only one quotation can be selected.
            $locked_rfq_stmt = $pdo->prepare("
                SELECT *
                FROM rfq
                WHERE rfq_id = ?
                FOR UPDATE
            ");
            $locked_rfq_stmt->execute([$rfq_id]);
            $locked_rfq = $locked_rfq_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$locked_rfq) {
                throw new RuntimeException('The RFQ was not found.');
            }

            if ($locked_rfq['rfq_status'] === 'closed') {
                throw new RuntimeException(
                    'This RFQ has already been closed and a purchase order has already been generated.'
                );
            }

            // Lock and verify the selected quotation belongs to this RFQ.
            $selected_quote_stmt = $pdo->prepare("
                SELECT q.*, s.supplier_name
                FROM quotations q
                JOIN suppliers s ON s.supplier_id = q.quotation_supplier_id
                WHERE q.quotation_id = ?
                AND q.quotation_rfq_id = ?
                FOR UPDATE
            ");
            $selected_quote_stmt->execute([$quotation_id, $rfq_id]);
            $selected_quote = $selected_quote_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$selected_quote) {
                throw new RuntimeException(
                    'The selected quotation does not belong to this RFQ.'
                );
            }

            if ($selected_quote['quotation_status'] !== 'submitted') {
                throw new RuntimeException(
                    'This quotation is no longer available for selection.'
                );
            }

            // Check for an existing PO created from any quotation under this RFQ.
            $existing_po_stmt = $pdo->prepare("
                SELECT po.po_id
                FROM purchase_orders po
                JOIN quotations q ON q.quotation_id = po.po_quotation_id
                WHERE q.quotation_rfq_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $existing_po_stmt->execute([$rfq_id]);

            if ($existing_po_stmt->fetchColumn()) {
                throw new RuntimeException(
                    'A purchase order has already been generated for this RFQ.'
                );
            }

            // Reload and lock quotation items before creating the PO.
            $quote_items_stmt = $pdo->prepare("
                SELECT *
                FROM quotation_items
                WHERE quotation_item_quotation_id = ?
                ORDER BY quotation_item_id
                FOR UPDATE
            ");
            $quote_items_stmt->execute([$quotation_id]);
            $quote_items = $quote_items_stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$quote_items) {
                throw new RuntimeException(
                    'No items were found for the selected quotation.'
                );
            }

            $po_items_data = [];
            $total_cents = 0;

            foreach ($quote_items as $item) {
                $product_id = (int) $item['quotation_item_product_id'];
                $quantity = (int) $item['quotation_item_quantity'];
                $unit_price = (float) $item['quotation_item_unit_price'];

                if ($product_id <= 0 || $quantity <= 0 || $unit_price <= 0) {
                    throw new RuntimeException(
                        'The selected quotation contains invalid item data.'
                    );
                }

                $unit_price_cents = (int) round($unit_price * 100);
                $total_cents += $quantity * $unit_price_cents;

                $po_items_data[] = [
                    'product_id' => $product_id,
                    'quantity' => $quantity,
                    'unit_price' => number_format(
                        $unit_price_cents / 100,
                        2,
                        '.',
                        ''
                    ),
                ];
            }

            if ($total_cents <= 0) {
                throw new RuntimeException(
                    'The selected quotation has an invalid total amount.'
                );
            }

            $po_total = number_format($total_cents / 100, 2, '.', '');

            // Use the auto-increment ID to generate a concurrency-safe PO number.
            $temporary_po_number = 'TMP-PO-' . bin2hex(random_bytes(6));

            $insert_po = $pdo->prepare("
                INSERT INTO purchase_orders (
                    po_number,
                    po_supplier_id,
                    po_quotation_id,
                    po_status,
                    po_total_amount,
                    po_created_by
                )
                VALUES (?, ?, ?, 'sent', ?, ?)
            ");
            $insert_po->execute([
                $temporary_po_number,
                $selected_quote['quotation_supplier_id'],
                $quotation_id,
                $po_total,
                $_SESSION['user_id'],
            ]);

            $po_id = (int) $pdo->lastInsertId();
            $po_number = 'PO-' . str_pad((string) $po_id, 4, '0', STR_PAD_LEFT);

            $set_po_number = $pdo->prepare("
                UPDATE purchase_orders
                SET po_number = ?
                WHERE po_id = ?
                AND po_number = ?
            ");
            $set_po_number->execute([
                $po_number,
                $po_id,
                $temporary_po_number,
            ]);

            if ($set_po_number->rowCount() !== 1) {
                throw new RuntimeException(
                    'Unable to generate the purchase order number.'
                );
            }

            $insert_po_item = $pdo->prepare("
                INSERT INTO po_items (
                    po_item_po_id,
                    po_item_product_id,
                    po_item_quantity,
                    po_item_unit_price
                )
                VALUES (?, ?, ?, ?)
            ");

            foreach ($po_items_data as $item) {
                $insert_po_item->execute([
                    $po_id,
                    $item['product_id'],
                    $item['quantity'],
                    $item['unit_price'],
                ]);
            }

            // Mark the selected quotation as accepted and the remaining submissions as rejected.
            $accept_quote = $pdo->prepare("
                UPDATE quotations
                SET quotation_status = 'accepted'
                WHERE quotation_id = ?
                AND quotation_rfq_id = ?
                AND quotation_status = 'submitted'
            ");
            $accept_quote->execute([$quotation_id, $rfq_id]);

            if ($accept_quote->rowCount() !== 1) {
                throw new RuntimeException(
                    'The selected quotation could not be accepted.'
                );
            }

            $reject_other_quotes = $pdo->prepare("
                UPDATE quotations
                SET quotation_status = 'rejected'
                WHERE quotation_rfq_id = ?
                AND quotation_id != ?
                AND quotation_status = 'submitted'
            ");
            $reject_other_quotes->execute([$rfq_id, $quotation_id]);

            $close_rfq = $pdo->prepare("
                UPDATE rfq
                SET rfq_status = 'closed'
                WHERE rfq_id = ?
                AND rfq_status != 'closed'
            ");
            $close_rfq->execute([$rfq_id]);

            if ($close_rfq->rowCount() !== 1) {
                throw new RuntimeException(
                    'The RFQ could not be closed.'
                );
            }

            $pdo->commit();

            $_SESSION['flash_success'] =
                "$po_number created successfully for {$selected_quote['supplier_name']}!";
            header('Location: purchase_orders.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException) {
                $error = $e->getMessage();
            } else {
                app_error_log('Purchase order generation failed: ' . $e->getMessage());
                $error = 'Unable to generate the purchase order. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotations - <?= htmlspecialchars($rfq['rfq_number']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-6xl mx-auto px-6 py-8">

        <p class="text-sm text-gray-400 mb-6">
            <a href="rfq.php" class="hover:text-red-600 transition-colors">RFQ</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600"><?= htmlspecialchars($rfq['rfq_number']) ?></span>
        </p>

        <div class="mb-8">
            <h1 class="text-2xl font-black text-gray-800">📋 <?= htmlspecialchars($rfq['rfq_number']) ?> — Compare Quotations</h1>
            <p class="text-gray-500 text-sm mt-1">
                <?= count($quotations) ?> of <?= count($sent_suppliers) ?> supplier(s) have submitted quotes
                · Status: <span class="font-semibold capitalize"><?= $rfq['rfq_status'] ?></span>
            </p>
        </div>

        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-6">
            ❌ <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <!-- Requested Items -->
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
            <h3 class="font-bold text-gray-800 mb-4">Requested Items</h3>
            <div class="flex gap-4 flex-wrap">
                <?php foreach ($rfq_items as $item): ?>
                <div class="flex items-center gap-2 bg-gray-50 rounded-xl px-3 py-2">
                    <?php if ($item['product_cover_image']): ?>
                    <img src="../assets/images/<?= htmlspecialchars($item['product_cover_image']) ?>" class="w-7 h-10 object-cover rounded">
                    <?php endif; ?>
                    <div>
                        <p class="text-xs font-semibold text-gray-700"><?= htmlspecialchars($item['product_title']) ?></p>
                        <p class="text-xs text-gray-400">Qty: <?= $item['rfq_item_quantity'] ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Quotations Comparison -->
        <?php if (count($quotations) === 0): ?>
        <div class="bg-white rounded-2xl shadow-sm p-16 text-center">
            <div class="text-5xl mb-4">⏳</div>
            <p class="text-gray-400">No quotations submitted yet. Waiting for suppliers to respond.</p>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-<?= min(count($quotations), 3) ?> gap-4">
            <?php foreach ($quotations as $q): ?>
            <div class="bg-white rounded-2xl shadow-sm p-6 <?= $q['quotation_status'] === 'accepted' ? 'ring-2 ring-green-400' : ($q['quotation_status'] === 'rejected' ? 'opacity-50' : '') ?>">
                <div class="flex items-center justify-between mb-2">
                    <p class="font-bold text-gray-800"><?= htmlspecialchars($q['supplier_name']) ?></p>
                    <?php if ($q['quotation_status'] === 'accepted'): ?>
                    <span class="bg-green-100 text-green-700 text-xs px-2 py-1 rounded-full font-semibold">✓ Accepted</span>
                    <?php elseif ($q['quotation_status'] === 'rejected'): ?>
                    <span class="bg-gray-100 text-gray-500 text-xs px-2 py-1 rounded-full font-semibold">Rejected</span>
                    <?php elseif ($q['score'] == $best_score): ?>
                    <span class="bg-purple-100 text-purple-700 text-xs px-2 py-1 rounded-full font-semibold">⭐ Recommended</span>
                    <?php endif; ?>
                </div>

                <div class="flex items-center gap-3 mb-4 text-xs text-gray-400">
                    <span>Score: <strong class="text-gray-700"><?= round($q['score'], 1) ?></strong>/100</span>
                    <span>·</span>
                    <span><?= $q['avg_rating'] !== null ? round($q['avg_rating'], 1) . '★' : 'No rating' ?></span>
                    <span>·</span>
                    <span><?= $q['avg_lead_time'] !== null ? round($q['avg_lead_time'], 1) . 'd lead time' : 'No lead time data' ?></span>
                </div>

                <div class="space-y-2 mb-4">
                    <?php foreach ($q['items'] as $i):
                        $prod = array_filter($rfq_items, fn($r) => $r['rfq_item_product_id'] == $i['quotation_item_product_id']);
                        $prod = reset($prod);
                    ?>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600 truncate"><?= htmlspecialchars($prod['product_title'] ?? 'Product') ?></span>
                        <span class="text-gray-800 font-semibold flex-shrink-0">RM <?= number_format($i['quotation_item_unit_price'], 2) ?>/unit</span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="border-t border-gray-100 pt-3 mb-4">
                    <div class="flex justify-between">
                        <span class="font-bold text-gray-700">Total</span>
                        <span class="font-black text-red-600">RM <?= number_format($q['total'], 2) ?></span>
                    </div>
                </div>

                <?php if (!empty($q['quotation_notes'])): ?>
                <div class="bg-gray-50 rounded-lg p-2 mb-4">
                    <p class="text-xs text-gray-500"><?= htmlspecialchars($q['quotation_notes']) ?></p>
                </div>
                <?php endif; ?>

                <?php if ($rfq['rfq_status'] !== 'closed'): ?>
                <form method="POST" onsubmit="return confirm('Generate PO for <?= htmlspecialchars($q['supplier_name']) ?>?')">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="generate_po" value="1">
                    <input type="hidden" name="quotation_id" value="<?= $q['quotation_id'] ?>">
                    <button type="submit"
                            class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 rounded-xl text-sm transition-colors">
                        ✓ Select & Generate PO
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Suppliers not yet responded -->
        <?php
        $responded_ids = array_column($quotations, 'quotation_supplier_id');
        $not_responded = array_filter($sent_suppliers, fn($s) => !in_array($s['supplier_id'], $responded_ids));
        ?>
        <?php if (count($not_responded) > 0): ?>
        <div class="mt-6 bg-yellow-50 border border-yellow-200 rounded-xl p-4">
            <p class="text-xs font-semibold text-yellow-700 mb-1">⏳ Awaiting response from:</p>
            <p class="text-sm text-yellow-600"><?= implode(', ', array_column($not_responded, 'supplier_name')) ?></p>
        </div>
        <?php endif; ?>

    </div>

</body>
</html>
