<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}
require_once '../includes/db.php';

$is_senior = (($_SESSION['admin_level'] ?? '') === 'senior_admin');

$success = '';
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
$error = '';
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

function generate_po_number($pdo) {
    $last = $pdo->query("SELECT po_id FROM purchase_orders ORDER BY po_id DESC LIMIT 1")->fetchColumn();
    return 'PO-' . str_pad(($last ?? 0) + 1, 4, '0', STR_PAD_LEFT);
}

function get_return_or_redirect($pdo, $return_id) {
    $stmt = $pdo->prepare("
        SELECT sr.*, po.po_supplier_id, po.po_number
        FROM supplier_returns sr
        JOIN purchase_orders po ON po.po_id = sr.return_po_id
        WHERE sr.return_id = ?
    ");
    $stmt->execute([$return_id]);
    $ret = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ret) {
        $_SESSION['flash_error'] = 'Return record not found.';
        header('Location: supplier_returns.php');
        exit;
    }
    return $ret;
}

function get_return_items($pdo, $return_id) {
    $items = $pdo->prepare("SELECT * FROM supplier_return_items WHERE return_item_return_id = ?");
    $items->execute([$return_id]);
    return $items->fetchAll(PDO::FETCH_ASSOC);
}

// ------------------------------------------------------------
// Action: Issue Credit Note (resolves an acknowledged return)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['issue_credit_note'])) {
    $return_id = $_POST['return_id'];
    $cn_seq = trim($_POST['credit_note_seq'] ?? '');
    if (!ctype_digit($cn_seq) || strlen($cn_seq) !== 4) {
        $_SESSION['flash_error'] = 'Credit note number must be 4 digits.';
        header('Location: supplier_returns.php'); exit;
    }
    $cn_number = 'CN-' . date('Y') . '-' . $cn_seq;
    $items_for_amount = get_return_items($pdo, $return_id);
    $cn_amount = array_sum(array_map(fn($i) => $i['return_item_quantity'] * $i['return_item_unit_price'], $items_for_amount));
    $ret = get_return_or_redirect($pdo, $return_id);

    if (!in_array($ret['return_status'], ['acknowledged', 'escalated'])) {
        $_SESSION['flash_error'] = 'This return is not in a resolvable state.';
        header('Location: supplier_returns.php'); exit;
    }
    if ($ret['return_status'] === 'escalated' && !$is_senior) {
        $_SESSION['flash_error'] = 'Only senior admin can resolve a disputed return.';
        header('Location: supplier_returns.php'); exit;
    }
    if ($cn_number === '') {
        $_SESSION['flash_error'] = 'Credit note number and amount are required.';
        header('Location: supplier_returns.php'); exit;
    }

    $notes = trim($_POST['resolution_notes'] ?? '');
    if ($ret['return_status'] === 'escalated' && $notes === '') {
        $_SESSION['flash_error'] = 'A justification is required to resolve a disputed return.';
        header('Location: supplier_returns.php'); exit;
    }

    $resolution_type = $ret['return_status'] === 'escalated' ? 'dispute_upheld' : 'credit_note';

    $pdo->prepare("
        UPDATE supplier_returns SET
            return_status = 'resolved',
            return_resolution_type = ?,
            return_resolution_notes = ?,
            return_credit_note_number = ?,
            return_credit_note_amount = ?,
            return_resolved_by = ?,
            return_resolved_at = NOW()
        WHERE return_id = ?
    ")->execute([$resolution_type, $notes ?: null, $cn_number, $cn_amount, $_SESSION['user_id'], $return_id]);

    $_SESSION['flash_success'] = "Credit note $cn_number recorded. Return {$ret['return_number']} marked resolved.";
    header('Location: supplier_returns.php');
    exit;
}

// ------------------------------------------------------------
// Action: Create Replacement PO (resolves an acknowledged return)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_replacement_po'])) {
    $return_id = $_POST['return_id'];
    $ret = get_return_or_redirect($pdo, $return_id);

    if (!in_array($ret['return_status'], ['acknowledged', 'escalated'])) {
        $_SESSION['flash_error'] = 'This return is not in a resolvable state.';
        header('Location: supplier_returns.php'); exit;
    }
    if ($ret['return_status'] === 'escalated' && !$is_senior) {
        $_SESSION['flash_error'] = 'Only senior admin can resolve a disputed return.';
        header('Location: supplier_returns.php'); exit;
    }

    $notes = trim($_POST['resolution_notes'] ?? '');
    if ($ret['return_status'] === 'escalated' && $notes === '') {
        $_SESSION['flash_error'] = 'A justification is required to resolve a disputed return.';
        header('Location: supplier_returns.php'); exit;
    }

    $items = get_return_items($pdo, $return_id);
    if (empty($items)) {
        $_SESSION['flash_error'] = 'No items found for this return.';
        header('Location: supplier_returns.php'); exit;
    }

    $po_number = generate_po_number($pdo);
    $total = array_sum(array_map(fn($i) => $i['return_item_quantity'] * $i['return_item_unit_price'], $items));

    $pdo->prepare("
        INSERT INTO purchase_orders (po_number, po_supplier_id, po_quotation_id, po_status, po_total_amount, po_notes, po_created_by)
        VALUES (?, ?, NULL, 'confirmed', ?, ?, ?)
    ")->execute([
        $po_number, $ret['po_supplier_id'], $total,
        "Replacement order for {$ret['return_number']} (original {$ret['po_number']})",
        $_SESSION['user_id']
    ]);
    $new_po_id = $pdo->lastInsertId();

    foreach ($items as $item) {
        $pdo->prepare("
            INSERT INTO po_items (po_item_po_id, po_item_product_id, po_item_quantity, po_item_unit_price)
            VALUES (?, ?, ?, ?)
        ")->execute([$new_po_id, $item['return_item_product_id'], $item['return_item_quantity'], $item['return_item_unit_price']]);
    }

    $resolution_type = $ret['return_status'] === 'escalated' ? 'dispute_upheld' : 'replacement';

    $pdo->prepare("
        UPDATE supplier_returns SET
            return_status = 'resolved',
            return_resolution_type = ?,
            return_resolution_notes = ?,
            return_replacement_po_id = ?,
            return_resolved_by = ?,
            return_resolved_at = NOW()
        WHERE return_id = ?
    ")->execute([$resolution_type, $notes ?: null, $new_po_id, $_SESSION['user_id'], $return_id]);

    $_SESSION['flash_success'] = "Replacement $po_number created and confirmed. Use Goods Received once it arrives.";
    header('Location: supplier_returns.php');
    exit;
}

// ------------------------------------------------------------
// Action: Reject Dispute — supplier was right, reverse the return (senior_admin only)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_dispute'])) {
    $return_id = $_POST['return_id'];
    $notes = trim($_POST['resolution_notes'] ?? '');
    $ret = get_return_or_redirect($pdo, $return_id);

    if (!$is_senior) {
        $_SESSION['flash_error'] = 'Only senior admin can reverse a disputed return.';
        header('Location: supplier_returns.php'); exit;
    }
    if ($ret['return_status'] !== 'escalated') {
        $_SESSION['flash_error'] = 'Only escalated (disputed) returns can be reversed.';
        header('Location: supplier_returns.php'); exit;
    }
    if ($notes === '') {
        $_SESSION['flash_error'] = 'A justification is required to reverse a disputed return.';
        header('Location: supplier_returns.php'); exit;
    }

    $items = get_return_items($pdo, $return_id);

    $pdo->beginTransaction();
    try {
        foreach ($items as $item) {
            $pdo->prepare("
                UPDATE po_items
                SET po_item_received_quantity = po_item_received_quantity + ?,
                    po_item_rejected_quantity = po_item_rejected_quantity - ?
                WHERE po_item_po_id = ? AND po_item_product_id = ?
            ")->execute([$item['return_item_quantity'], $item['return_item_quantity'], $ret['return_po_id'], $item['return_item_product_id']]);

            $pdo->prepare("UPDATE product_physical SET physical_stock_quantity = physical_stock_quantity + ? WHERE physical_product_id = ?")
                ->execute([$item['return_item_quantity'], $item['return_item_product_id']]);
        }

        $new_total = $pdo->prepare("SELECT SUM(po_item_received_quantity * po_item_unit_price) FROM po_items WHERE po_item_po_id = ?");
        $new_total->execute([$ret['return_po_id']]);
        $payable_total = $new_total->fetchColumn();
        $pdo->prepare("UPDATE purchase_orders SET po_total_amount = ? WHERE po_id = ?")->execute([$payable_total, $ret['return_po_id']]);

        $pdo->prepare("
            UPDATE supplier_returns SET
                return_status = 'resolved',
                return_resolution_type = 'dispute_rejected',
                return_resolution_notes = ?,
                return_resolved_by = ?,
                return_resolved_at = NOW()
            WHERE return_id = ?
        ")->execute([$notes, $_SESSION['user_id'], $return_id]);

        $pdo->commit();
        $_SESSION['flash_success'] = "Dispute upheld in supplier's favor. Stock and PO total restored for {$ret['return_number']}.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash_error'] = 'Failed to reverse return: ' . $e->getMessage();
    }
    header('Location: supplier_returns.php');
    exit;
}

// ------------------------------------------------------------
// Load list
// ------------------------------------------------------------
$returns = $pdo->query("
    SELECT sr.*, po.po_number, s.supplier_name
    FROM supplier_returns sr
    JOIN purchase_orders po ON po.po_id = sr.return_po_id
    JOIN suppliers s ON s.supplier_id = po.po_supplier_id
    ORDER BY sr.return_created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($returns as &$r) {
    $items = $pdo->prepare("
        SELECT sri.*, p.product_title
        FROM supplier_return_items sri
        JOIN products p ON p.product_id = sri.return_item_product_id
        WHERE sri.return_item_return_id = ?
    ");
    $items->execute([$r['return_id']]);
    $r['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
    $r['total_value'] = array_sum(array_map(fn($i) => $i['return_item_quantity'] * $i['return_item_unit_price'], $r['items']));

    if ($r['return_replacement_po_id']) {
        $po = $pdo->prepare("SELECT po_number FROM purchase_orders WHERE po_id = ?");
        $po->execute([$r['return_replacement_po_id']]);
        $r['replacement_po_number'] = $po->fetchColumn();
    }
    if ($r['return_resolved_by']) {
        $u = $pdo->prepare("SELECT user_name FROM users WHERE user_id = ?");
        $u->execute([$r['return_resolved_by']]);
        $r['resolved_by_name'] = $u->fetchColumn();
    }
}
unset($r);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Returns - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-6xl mx-auto px-6 py-8">

        <div class="mb-8">
            <h1 class="text-2xl font-black text-gray-800">↩️ Supplier Returns</h1>
            <p class="text-gray-500 text-sm mt-1">Damaged or rejected items returned to suppliers during goods receipt</p>
        </div>

        <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-6">
            ✅ <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-6">
            🔒 <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if (count($returns) === 0): ?>
        <div class="bg-white rounded-2xl shadow-sm p-16 text-center">
            <div class="text-5xl mb-4">↩️</div>
            <p class="text-gray-400">No returns recorded. All goods received have been in good condition!</p>
        </div>
        <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($returns as $ret):
                $status_config = [
                    'pending'      => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-700', 'label' => '⏳ Pending Supplier Response'],
                    'acknowledged' => ['bg' => 'bg-blue-100',   'text' => 'text-blue-700',   'label' => '📨 Acknowledged — Needs Resolution'],
                    'escalated'    => ['bg' => 'bg-orange-100', 'text' => 'text-orange-700', 'label' => '🚨 Disputed — Escalated to Senior Admin'],
                    'resolved'     => ['bg' => 'bg-green-100',  'text' => 'text-green-700',  'label' => '✅ Resolved'],
                ];
                $sc = $status_config[$ret['return_status']];
                $modal_id = 'modal_' . $ret['return_id'];
            ?>
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <p class="font-bold text-gray-800"><?= htmlspecialchars($ret['return_number']) ?></p>
                        <p class="text-xs text-gray-400">
                            <?= htmlspecialchars($ret['supplier_name']) ?> · <?= htmlspecialchars($ret['po_number']) ?> ·
                            <?= date('d M Y', strtotime($ret['return_created_at'])) ?>
                        </p>
                    </div>
                    <span class="<?= $sc['bg'] ?> <?= $sc['text'] ?> text-xs px-3 py-1.5 rounded-full font-semibold whitespace-nowrap">
                        <?= $sc['label'] ?>
                    </span>
                </div>

                <div class="bg-red-50 rounded-xl p-4 mb-4">
                    <?php foreach ($ret['items'] as $item): ?>
                    <div class="flex items-center justify-between py-1.5 border-b border-red-100 last:border-0">
                        <div>
                            <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($item['product_title']) ?></p>
                            <?php if ($item['return_item_reason']): ?>
                            <p class="text-xs text-red-500">Reason: <?= htmlspecialchars($item['return_item_reason']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="text-sm font-bold text-red-600"><?= $item['return_item_quantity'] ?> units</p>
                            <p class="text-xs text-gray-400">RM <?= number_format($item['return_item_quantity'] * $item['return_item_unit_price'], 2) ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($ret['return_supplier_response'] !== 'pending'): ?>
                <div class="bg-gray-50 rounded-xl p-3 mb-3">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-xs font-semibold text-gray-500">Supplier Response:</span>
                        <?php if ($ret['return_supplier_response'] === 'accepted'): ?>
                        <span class="bg-blue-100 text-blue-700 text-xs px-2 py-0.5 rounded-full font-semibold">✓ Acknowledged</span>
                        <?php else: ?>
                        <span class="bg-orange-100 text-orange-700 text-xs px-2 py-0.5 rounded-full font-semibold">⚠️ Disputed</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-sm text-gray-700"><?= htmlspecialchars($ret['return_supplier_comment'] ?: 'No comment provided.') ?></p>
                    <p class="text-xs text-gray-400 mt-1">Responded on <?= date('d M Y, h:i A', strtotime($ret['return_responded_at'])) ?></p>
                </div>
                <?php else: ?>
                <div class="bg-yellow-50 border border-yellow-100 rounded-xl p-3 mb-3">
                    <p class="text-xs text-yellow-700">⏳ Waiting for supplier to respond to this return.</p>
                </div>
                <?php endif; ?>

                <?php if ($ret['return_status'] === 'resolved'): ?>
                <div class="bg-green-50 border border-green-200 rounded-xl p-3 mb-3">
                    <p class="text-xs font-semibold text-green-700 mb-1">
                        Resolution: <?= [
                            'credit_note'      => '💳 Credit Note',
                            'replacement'      => '📦 Replacement PO',
                            'dispute_upheld'    => '⚖️ Dispute Upheld (Senior Admin)',
                            'dispute_rejected'  => '⚖️ Dispute Rejected — Stock Restored (Senior Admin)',
                        ][$ret['return_resolution_type']] ?? $ret['return_resolution_type'] ?>
                    </p>
                    <?php if ($ret['return_resolution_type'] === 'credit_note'): ?>
                    <p class="text-sm text-gray-700">Credit Note <strong><?= htmlspecialchars($ret['return_credit_note_number']) ?></strong> — RM <?= number_format($ret['return_credit_note_amount'], 2) ?></p>
                    <?php elseif ($ret['return_resolution_type'] === 'replacement' && !empty($ret['replacement_po_number'])): ?>
                    <p class="text-sm text-gray-700">Replacement order: <a href="purchase_orders.php" class="text-blue-600 font-semibold hover:underline"><?= htmlspecialchars($ret['replacement_po_number']) ?></a></p>
                    <?php endif; ?>
                    <?php if ($ret['return_resolution_notes']): ?>
                    <p class="text-xs text-gray-500 mt-1">"<?= htmlspecialchars($ret['return_resolution_notes']) ?>"</p>
                    <?php endif; ?>
                    <p class="text-xs text-gray-400 mt-1">Resolved by <?= htmlspecialchars($ret['resolved_by_name'] ?? '—') ?> on <?= date('d M Y, h:i A', strtotime($ret['return_resolved_at'])) ?></p>
                </div>
                <?php endif; ?>

                <div class="flex items-center justify-between">
                    <p class="text-sm text-gray-600">Total deducted from payment: <strong class="text-red-600">RM <?= number_format($ret['total_value'], 2) ?></strong></p>

                    <?php if ($ret['return_status'] === 'acknowledged'): ?>
                    <button onclick="document.getElementById('<?= $modal_id ?>').classList.remove('hidden')"
                            class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2 rounded-lg transition-colors">
                        Resolve Return
                    </button>
                    <?php elseif ($ret['return_status'] === 'escalated'): ?>
                        <?php if ($is_senior): ?>
                        <button onclick="document.getElementById('<?= $modal_id ?>').classList.remove('hidden')"
                                class="bg-orange-600 hover:bg-orange-700 text-white text-xs font-bold px-4 py-2 rounded-lg transition-colors">
                            ⚖️ Adjudicate Dispute
                        </button>
                        <?php else: ?>
                        <span class="text-xs text-gray-400 font-semibold" title="Only senior admin can resolve disputes">🔒 Awaiting Senior Admin</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Resolution Modal -->
            <?php if (in_array($ret['return_status'], ['acknowledged', 'escalated'])): ?>
            <div id="<?= $modal_id ?>" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
                <div class="bg-white rounded-2xl shadow-xl max-w-lg w-full p-6 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold text-gray-800 mb-1">
                        <?= $ret['return_status'] === 'escalated' ? '⚖️ Adjudicate Dispute' : 'Resolve Return' ?> — <?= htmlspecialchars($ret['return_number']) ?>
                    </h3>
                    <p class="text-xs text-gray-400 mb-4">RM <?= number_format($ret['total_value'], 2) ?> · <?= htmlspecialchars($ret['supplier_name']) ?></p>

                    <?php if ($ret['return_status'] === 'escalated'): ?>
                    <div class="bg-orange-50 border border-orange-100 rounded-xl p-3 mb-4">
                        <p class="text-xs text-orange-700">Supplier disputed this return. As senior admin, decide whether MangaVault's original assessment stands, or whether to reverse it.</p>
                    </div>
                    <?php endif; ?>

                    <div class="space-y-3">
                        <form method="POST">
                            <input type="hidden" name="issue_credit_note" value="1">
                            <input type="hidden" name="return_id" value="<?= $ret['return_id'] ?>">
                            <div class="border-2 border-gray-100 rounded-xl p-4">
                                <p class="text-sm font-bold text-gray-700 mb-2">💳 Issue Credit Note</p>
                                <div class="grid grid-cols-2 gap-2 mb-2">
                                   <div class="flex items-center border-2 border-gray-100 rounded-lg overflow-hidden focus-within:border-blue-400">
                                        <span class="px-3 py-2 bg-gray-50 text-gray-500 text-sm font-mono">CN-<?= date('Y') ?>-</span>
                                        <input type="text" name="credit_note_seq" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="0001" required
                                            class="flex-1 px-3 py-2 text-sm focus:outline-none" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,4)">
                                    </div>
                                    <input type="number" step="0.01" name="credit_note_amount" value="<?= number_format($ret['total_value'], 2, '.', '') ?>" readonly required
                                           class="px-3 py-2 border-2 border-gray-100 rounded-lg text-sm focus:outline-none focus:border-blue-400">
                                </div>
                                <?php if ($ret['return_status'] === 'escalated'): ?>
                                <textarea name="resolution_notes" rows="2" required placeholder="Justification for upholding the dispute (required)"
                                          class="w-full px-3 py-2 border-2 border-gray-100 rounded-lg text-xs focus:outline-none focus:border-blue-400 resize-none mb-2"></textarea>
                                <?php endif; ?>
                                <button type="submit" class="w-full bg-blue-50 hover:bg-blue-100 text-blue-700 font-semibold py-2 rounded-lg text-sm transition-colors">
                                    Confirm Credit Note
                                </button>
                            </div>
                        </form>

                        <form method="POST">
                            <input type="hidden" name="create_replacement_po" value="1">
                            <input type="hidden" name="return_id" value="<?= $ret['return_id'] ?>">
                            <div class="border-2 border-gray-100 rounded-xl p-4">
                                <p class="text-sm font-bold text-gray-700 mb-2">📦 Create Replacement PO</p>
                                <p class="text-xs text-gray-400 mb-2">Generates a confirmed PO for the same items/quantities — process via Goods Received once it arrives.</p>
                                <?php if ($ret['return_status'] === 'escalated'): ?>
                                <textarea name="resolution_notes" rows="2" required placeholder="Justification for upholding the dispute (required)"
                                          class="w-full px-3 py-2 border-2 border-gray-100 rounded-lg text-xs focus:outline-none focus:border-purple-400 resize-none mb-2"></textarea>
                                <?php endif; ?>
                                <button type="submit" class="w-full bg-purple-50 hover:bg-purple-100 text-purple-700 font-semibold py-2 rounded-lg text-sm transition-colors">
                                    Generate Replacement PO
                                </button>
                            </div>
                        </form>

                        <?php if ($ret['return_status'] === 'escalated'): ?>
                        <form method="POST">
                            <input type="hidden" name="reject_dispute" value="1">
                            <input type="hidden" name="return_id" value="<?= $ret['return_id'] ?>">
                            <div class="border-2 border-red-100 rounded-xl p-4">
                                <p class="text-sm font-bold text-red-700 mb-2">↩️ Reject Dispute — Supplier Was Right</p>
                                <p class="text-xs text-gray-400 mb-2">Restores stock and PO total as if these items were always good.</p>
                                <textarea name="resolution_notes" rows="2" required placeholder="Justification for reversing the return (required)"
                                          class="w-full px-3 py-2 border-2 border-gray-100 rounded-lg text-xs focus:outline-none focus:border-red-400 resize-none mb-2"></textarea>
                                <button type="submit" class="w-full bg-red-50 hover:bg-red-100 text-red-700 font-semibold py-2 rounded-lg text-sm transition-colors">
                                    Reverse Return & Restore Stock
                                </button>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>

                    <button onclick="document.getElementById('<?= $modal_id ?>').classList.add('hidden')"
                            class="w-full mt-4 text-gray-400 hover:text-gray-600 text-xs font-semibold">
                        Cancel
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>

</body>
</html>