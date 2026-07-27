<?php

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/money_helper.php';

$SUPPLIER_RETURN_COMMENT_MAX_LENGTH = 2000;

function normalizeSupplierReturnComment(mixed $value): string
{
    if (!is_string($value)) {
        throw new RuntimeException('Invalid supplier comment.');
    }

    $comment = trim($value);
    $length = function_exists('mb_strlen')
        ? mb_strlen($comment, 'UTF-8')
        : strlen($comment);

    if ($length > $GLOBALS['SUPPLIER_RETURN_COMMENT_MAX_LENGTH']) {
        throw new RuntimeException(
            'The supplier comment cannot exceed 2000 characters.'
        );
    }

    return $comment;
}

require_supplier();

date_default_timezone_set('Asia/Kuala_Lumpur');

$supplier_id = (int) $_SESSION['supplier_id'];

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['submit_response'])
) {
    csrf_verify();

    $return_id = filter_input(
        INPUT_POST,
        'return_id',
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    $response = $_POST['response'] ?? null;

    try {
        if (
            $return_id === false ||
            $return_id === null ||
            !is_string($response) ||
            !in_array(
                $response,
                [
                    'accepted',
                    'disputed',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Invalid return response.'
            );
        }

        $comment = normalizeSupplierReturnComment(
            $_POST['comment'] ?? ''
        );

        $next_status =
            $response === 'accepted'
                ? 'acknowledged'
                : 'escalated';

        $update_return = $pdo->prepare("
            UPDATE supplier_returns sr
            JOIN purchase_orders po
                ON po.po_id = sr.return_po_id
            SET sr.return_supplier_response = ?,
                sr.return_supplier_comment = ?,
                sr.return_responded_at = NOW(),
                sr.return_status = ?
            WHERE sr.return_id = ?
            AND po.po_supplier_id = ?
            AND sr.return_supplier_response = 'pending'
            AND sr.return_status = 'pending'
        ");
        $update_return->execute([
            $response,
            $comment,
            $next_status,
            $return_id,
            $supplier_id,
        ]);

        $_SESSION['flash_success'] =
            $update_return->rowCount() === 1
                ? 'Your response has been submitted.'
                : 'This return has already been processed or is unavailable.';
    } catch (RuntimeException $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }

    header('Location: returns.php');
    exit;
}

$returns = $pdo->prepare("
    SELECT sr.*, po.po_number
    FROM supplier_returns sr
    JOIN purchase_orders po ON po.po_id = sr.return_po_id
    WHERE po.po_supplier_id = ?
    ORDER BY sr.return_created_at DESC
");
$returns->execute([$supplier_id]);
$returns = $returns->fetchAll(PDO::FETCH_ASSOC);

foreach ($returns as &$return) {
    $items = $pdo->prepare("
        SELECT sri.*, p.product_title
        FROM supplier_return_items sri
        JOIN products p
            ON p.product_id =
                sri.return_item_product_id
        WHERE sri.return_item_return_id = ?
    ");
    $items->execute([
        (int) $return['return_id'],
    ]);
    $return['items'] =
        $items->fetchAll(PDO::FETCH_ASSOC);
    $return['total_value_sen'] = 0;

    foreach ($return['items'] as &$item) {
        $quantity =
            (int) $item['return_item_quantity'];
        $unit_price_sen = moneyDecimalToSen(
            (string) $item['return_item_unit_price']
        );

        if (
            $quantity <= 0 ||
            $unit_price_sen >
                intdiv(
                    9999999999 -
                        $return['total_value_sen'],
                    $quantity
                )
        ) {
            throw new RuntimeException(
                'A supplier return item has an invalid amount.'
            );
        }

        $item['line_total_sen'] =
            $unit_price_sen * $quantity;

        $return['total_value_sen'] +=
            $item['line_total_sen'];
    }
    unset($item);

    $return['credit_note_amount_sen'] =
        moneyDecimalToSen(
            (string) (
                $return['return_credit_note_amount']
                ?? '0.00'
            )
        );
}
unset($return);

$success = '';
$error = '';

if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Returns - Supplier Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">

    <?php include '../includes/supplier_navbar.php'; ?>

    <div class="max-w-4xl mx-auto px-6 py-8">

        <div class="mb-6">
            <h1 class="text-2xl font-black text-gray-800">↩️ Returns & Quality Issues</h1>
            <p class="text-gray-500 text-sm mt-1">Items returned due to damage or quality issues — please respond</p>
        </div>

        <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-6">
            ✅ <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-6">
            ❌ <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if (count($returns) === 0): ?>
        <div class="bg-white rounded-2xl shadow-sm p-16 text-center">
            <div class="text-5xl mb-4">✅</div>
            <p class="text-gray-400">No quality issues reported. Great job!</p>
        </div>
        <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($returns as $ret):
                $response_config = [
                    'pending'  => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-700', 'label' => '⏳ Awaiting Your Response'],
                    'accepted' => ['bg' => 'bg-blue-100',   'text' => 'text-blue-700',   'label' => '✓ Acknowledged'],
                    'disputed' => ['bg' => 'bg-orange-100', 'text' => 'text-orange-700', 'label' => '⚠️ Disputed'],
                ];
                $rc = $response_config[$ret['return_supplier_response']] ?? $response_config['pending'];
            ?>
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <p class="font-bold text-gray-800"><?= htmlspecialchars($ret['return_number']) ?></p>
                        <p class="text-xs text-gray-400"><?= htmlspecialchars($ret['po_number']) ?> · <?= date('d M Y', strtotime($ret['return_created_at'])) ?></p>
                    </div>
                    <span class="<?= $rc['bg'] ?> <?= $rc['text'] ?> text-xs px-3 py-1.5 rounded-full font-semibold">
                        <?= htmlspecialchars($rc['label'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>

                <div class="bg-red-50 rounded-xl p-4 mb-4">
                    <?php foreach ($ret['items'] as $item): ?>
                    <div class="flex items-center justify-between py-1.5 border-b border-red-100 last:border-0">
                        <div>
                            <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($item['product_title']) ?></p>
                            <?php if ($item['return_item_reason']): ?>
                            <p class="text-xs text-red-500">MangaVault reported: <?= htmlspecialchars($item['return_item_reason']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="text-sm font-bold text-red-600"><?= (int) $item['return_item_quantity'] ?> units</p>
                            <p class="text-xs text-gray-400">RM <?= moneyFormatSen($item['line_total_sen']) ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($ret['return_status'] === 'resolved' && $ret['return_resolution_type'] === 'dispute_rejected'): ?>
                <p class="text-sm text-gray-600 mb-4">No deduction has been made — this was reversed after review.</p>
                <?php else: ?>
                <p class="text-sm text-gray-600 mb-4">This amount (<strong class="text-red-600">RM <?= moneyFormatSen($ret['total_value_sen']) ?></strong>) has been deducted from your payment for this order.</p>
                <?php endif; ?>

                <?php if ($ret['return_supplier_response'] === 'pending'): ?>
                <form method="POST">
                    <?php csrf_field() ?>
                    <input type="hidden" name="submit_response" value="1">
                    <input type="hidden" name="return_id" value="<?= (int) $ret['return_id'] ?>">
                    <textarea name="comment" rows="2" maxlength="2000" placeholder="Optional comment..."
                              class="w-full px-4 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-blue-400 transition-colors resize-none mb-3"></textarea>
                    <div class="flex gap-3">
                        <button type="submit" name="response" value="accepted"
                                class="flex-1 bg-blue-50 hover:bg-blue-100 text-blue-700 font-semibold py-2.5 rounded-xl text-sm transition-colors">
                            ✓ Acknowledge Issue
                        </button>
                        <button type="submit" name="response" value="disputed"
                                class="flex-1 bg-orange-50 hover:bg-orange-100 text-orange-700 font-semibold py-2.5 rounded-xl text-sm transition-colors">
                            ⚠️ Dispute This
                        </button>
                    </div>
                </form>
                <?php else: ?>
                <div class="bg-gray-50 rounded-xl p-3">
                    <p class="text-xs font-semibold text-gray-500 mb-1">Your Response:</p>
                    <p class="text-sm text-gray-700"><?= htmlspecialchars($ret['return_supplier_comment'] ?: 'No comment provided.') ?></p>
                    <p class="text-xs text-gray-400 mt-1">Responded on <?= date('d M Y, h:i A', strtotime($ret['return_responded_at'])) ?></p>
                </div>

                <?php if ($ret['return_status'] === 'resolved'): ?>
                <div class="bg-green-50 border border-green-200 rounded-xl p-3 mt-3">
                    <p class="text-xs font-semibold text-green-700 mb-1">✅ Resolved by MangaVault</p>
                    <?php if ($ret['return_resolution_type'] === 'credit_note'): ?>
                    <p class="text-sm text-gray-700">A credit note <strong><?= htmlspecialchars($ret['return_credit_note_number']) ?></strong> for RM <?= moneyFormatSen($ret['credit_note_amount_sen']) ?> has been issued.</p>
                    <?php elseif ($ret['return_resolution_type'] === 'replacement'): ?>
                    <p class="text-sm text-gray-700">A replacement purchase order has been created. Please check your Purchase Orders.</p>
                    <?php elseif ($ret['return_resolution_type'] === 'dispute_rejected'): ?>
                    <p class="text-sm text-gray-700">Your dispute was accepted — no deduction will be made.</p>
                    <?php elseif ($ret['return_resolution_type'] === 'dispute_upheld'): ?>
                    <p class="text-sm text-gray-700">
                        After review, MangaVault's original assessment stands.
                        <?php if ($ret['return_credit_note_number']): ?>
                        Credit note <strong><?= htmlspecialchars($ret['return_credit_note_number']) ?></strong> for RM <?= moneyFormatSen($ret['credit_note_amount_sen']) ?> issued.
                        <?php else: ?>
                        A replacement order has been created.
                        <?php endif; ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>

</body>
</html>