<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

$po_id = filter_var(
    $_GET['po_id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if ($po_id === false) {
    header('Location: purchase_orders.php');
    exit;
}

$po_stmt = $pdo->prepare("
    SELECT
        po.*,
        s.supplier_name
    FROM purchase_orders po
    JOIN suppliers s
        ON s.supplier_id = po.po_supplier_id
    WHERE po.po_id = ?
    AND po.po_status = 'confirmed'
");
$po_stmt->execute([$po_id]);
$po = $po_stmt->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    header('Location: purchase_orders.php');
    exit;
}

$items_stmt = $pdo->prepare("
    SELECT
        pi.*,
        p.product_title,
        p.product_volume_number,
        p.product_cover_image
    FROM po_items pi
    JOIN products p
        ON p.product_id = pi.po_item_product_id
    WHERE pi.po_item_po_id = ?
    ORDER BY pi.po_item_id
");
$items_stmt->execute([$po_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get delivery order information when available.
$do_stmt = $pdo->prepare("
    SELECT *
    FROM delivery_orders
    WHERE do_po_id = ?
");
$do_stmt->execute([$po_id]);
$do_info = $do_stmt->fetch(PDO::FETCH_ASSOC);

$do_items = [];

if ($do_info) {
    $do_items_stmt = $pdo->prepare("
        SELECT
            doi.*,
            p.product_title
        FROM delivery_order_items doi
        JOIN products p
            ON p.product_id = doi.doi_product_id
        WHERE doi.doi_do_id = ?
    ");
    $do_items_stmt->execute([
        $do_info['do_id'],
    ]);

    foreach (
        $do_items_stmt->fetchAll(PDO::FETCH_ASSOC)
        as $do_item
    ) {
        $do_items[
            $do_item['doi_product_id']
        ] = $do_item['doi_quantity'];
    }
}

$error = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['confirm_receive'])
) {
    csrf_verify();

    $received_qtys = is_array(
        $_POST['received_qty'] ?? null
    )
        ? $_POST['received_qty']
        : [];

    $rejected_qtys = is_array(
        $_POST['rejected_qty'] ?? null
    )
        ? $_POST['rejected_qty']
        : [];

    $reject_reasons = is_array(
        $_POST['reject_reason'] ?? null
    )
        ? $_POST['reject_reason']
        : [];

    try {
        $pdo->beginTransaction();

        // Lock the PO so duplicate receipt requests cannot process it together.
        $lock_po = $pdo->prepare("
            SELECT po_id
            FROM purchase_orders
            WHERE po_id = ?
            AND po_status = 'confirmed'
            FOR UPDATE
        ");
        $lock_po->execute([$po_id]);

        if (!$lock_po->fetchColumn()) {
            throw new RuntimeException(
                'This purchase order is no longer available for receiving.'
            );
        }

        // Reload and lock the latest PO item quantities.
        $locked_items_stmt = $pdo->prepare("
            SELECT *
            FROM po_items
            WHERE po_item_po_id = ?
            ORDER BY po_item_id
            FOR UPDATE
        ");
        $locked_items_stmt->execute([$po_id]);
        $locked_items = $locked_items_stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

        if (!$locked_items) {
            throw new RuntimeException(
                'No purchase order items were found.'
            );
        }

        $processed_items = [];
        $return_items_data = [];
        $all_fully_received = true;

        foreach ($locked_items as $item) {
            $po_item_id = (int) $item['po_item_id'];
            $ordered_quantity =
                (int) $item['po_item_quantity'];
            $previously_received =
                (int) $item[
                    'po_item_received_quantity'
                ];
            $previously_rejected =
                (int) $item[
                    'po_item_rejected_quantity'
                ];

            $remaining = max(
                0,
                $ordered_quantity -
                $previously_received -
                $previously_rejected
            );

            if ($remaining === 0) {
                continue;
            }

            $received = max(
                0,
                (int) (
                    $received_qtys[$po_item_id] ?? 0
                )
            );

            $rejected = max(
                0,
                (int) (
                    $rejected_qtys[$po_item_id] ?? 0
                )
            );

            $reason = trim(
                (string) (
                    $reject_reasons[$po_item_id] ?? ''
                )
            );

            if ($received + $rejected > $remaining) {
                throw new RuntimeException(
                    'Received and rejected quantities cannot exceed the remaining quantity.'
                );
            }

            if ($received === 0 && $rejected === 0) {
                $all_fully_received = false;
                continue;
            }

            if ($rejected > 0 && $reason === '') {
                throw new RuntimeException(
                    'A rejection reason is required for damaged items.'
                );
            }

            $processed_items[] = [
                'po_item_id' => $po_item_id,
                'product_id' =>
                    (int) $item['po_item_product_id'],
                'received' => $received,
                'rejected' => $rejected,
                'reason' => $reason,
                'unit_price' =>
                    $item['po_item_unit_price'],
                'previously_received' =>
                    $previously_received,
                'previously_rejected' =>
                    $previously_rejected,
            ];

            if ($rejected > 0) {
                $return_items_data[] = [
                    'product_id' =>
                        (int) $item[
                            'po_item_product_id'
                        ],
                    'quantity' => $rejected,
                    'reason' => $reason,
                    'unit_price' =>
                        $item['po_item_unit_price'],
                ];
            }

            $new_processed_total =
                $previously_received +
                $previously_rejected +
                $received +
                $rejected;

            if ($new_processed_total < $ordered_quantity) {
                $all_fully_received = false;
            }
        }

        if (!$processed_items) {
            throw new RuntimeException(
                'Enter at least one received or rejected quantity.'
            );
        }

        // Use the auto-increment ID to generate a concurrency-safe GR number.
        $temporary_gr_number =
            'TMP-' . bin2hex(random_bytes(6));

        $insert_gr = $pdo->prepare("
            INSERT INTO goods_received (
                gr_po_id,
                gr_number,
                gr_received_by,
                gr_status
            )
            VALUES (?, ?, ?, 'pending')
        ");
        $insert_gr->execute([
            $po_id,
            $temporary_gr_number,
            (int) $_SESSION['user_id'],
        ]);

        $gr_id = (int) $pdo->lastInsertId();
        $gr_number =
            'GR-' .
            str_pad(
                (string) $gr_id,
                4,
                '0',
                STR_PAD_LEFT
            );

        $set_gr_number = $pdo->prepare("
            UPDATE goods_received
            SET gr_number = ?
            WHERE gr_id = ?
            AND gr_number = ?
        ");
        $set_gr_number->execute([
            $gr_number,
            $gr_id,
            $temporary_gr_number,
        ]);

        if ($set_gr_number->rowCount() !== 1) {
            throw new RuntimeException(
                'Unable to generate the goods received number.'
            );
        }

        $insert_gr_item = $pdo->prepare("
            INSERT INTO goods_received_items (
                gri_gr_id,
                gri_po_item_id,
                gri_received_quantity,
                gri_rejected_quantity,
                gri_reject_reason
            )
            VALUES (?, ?, ?, ?, ?)
        ");

        $update_po_item = $pdo->prepare("
            UPDATE po_items
            SET po_item_received_quantity =
                    po_item_received_quantity + ?,
                po_item_rejected_quantity =
                    po_item_rejected_quantity + ?
            WHERE po_item_id = ?
            AND po_item_po_id = ?
            AND po_item_received_quantity = ?
            AND po_item_rejected_quantity = ?
        ");

        $update_stock = $pdo->prepare("
            UPDATE product_physical
            SET physical_stock_quantity =
                physical_stock_quantity + ?
            WHERE physical_product_id = ?
        ");

        foreach ($processed_items as $processed_item) {
            $insert_gr_item->execute([
                $gr_id,
                $processed_item['po_item_id'],
                $processed_item['received'],
                $processed_item['rejected'],
                $processed_item['rejected'] > 0
                    ? $processed_item['reason']
                    : null,
            ]);

            $update_po_item->execute([
                $processed_item['received'],
                $processed_item['rejected'],
                $processed_item['po_item_id'],
                $po_id,
                $processed_item[
                    'previously_received'
                ],
                $processed_item[
                    'previously_rejected'
                ],
            ]);

            if ($update_po_item->rowCount() !== 1) {
                throw new RuntimeException(
                    'Unable to update the purchase order item.'
                );
            }

            if ($processed_item['received'] > 0) {
                $update_stock->execute([
                    $processed_item['received'],
                    $processed_item['product_id'],
                ]);

                if ($update_stock->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Unable to update product stock.'
                    );
                }
            }
        }

        $gr_status = $all_fully_received
            ? 'completed'
            : 'partial';

        $update_gr = $pdo->prepare("
            UPDATE goods_received
            SET gr_status = ?
            WHERE gr_id = ?
        ");
        $update_gr->execute([
            $gr_status,
            $gr_id,
        ]);

        if ($update_gr->rowCount() !== 1) {
            throw new RuntimeException(
                'Unable to update the goods received record.'
            );
        }

        $return_number = null;

        if ($return_items_data) {
            // Use the auto-increment ID for a concurrency-safe return number.
            $temporary_return_number =
                'TMP-' . bin2hex(random_bytes(6));

            $insert_return = $pdo->prepare("
                INSERT INTO supplier_returns (
                    return_number,
                    return_po_id,
                    return_gr_id,
                    return_status
                )
                VALUES (?, ?, ?, 'pending')
            ");
            $insert_return->execute([
                $temporary_return_number,
                $po_id,
                $gr_id,
            ]);

            $return_id =
                (int) $pdo->lastInsertId();
            $return_number =
                'RTN-' .
                str_pad(
                    (string) $return_id,
                    4,
                    '0',
                    STR_PAD_LEFT
                );

            $set_return_number = $pdo->prepare("
                UPDATE supplier_returns
                SET return_number = ?
                WHERE return_id = ?
                AND return_number = ?
            ");
            $set_return_number->execute([
                $return_number,
                $return_id,
                $temporary_return_number,
            ]);

            if ($set_return_number->rowCount() !== 1) {
                throw new RuntimeException(
                    'Unable to generate the supplier return number.'
                );
            }

            $insert_return_item = $pdo->prepare("
                INSERT INTO supplier_return_items (
                    return_item_return_id,
                    return_item_product_id,
                    return_item_quantity,
                    return_item_reason,
                    return_item_unit_price
                )
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach (
                $return_items_data
                as $return_item
            ) {
                $insert_return_item->execute([
                    $return_id,
                    $return_item['product_id'],
                    $return_item['quantity'],
                    $return_item['reason'],
                    $return_item['unit_price'],
                ]);
            }
        }

        if ($all_fully_received) {
            $payable_total_stmt = $pdo->prepare("
                SELECT SUM(
                    po_item_received_quantity *
                    po_item_unit_price
                )
                FROM po_items
                WHERE po_item_po_id = ?
            ");
            $payable_total_stmt->execute([$po_id]);

            $payable_total =
                (float) (
                    $payable_total_stmt->fetchColumn()
                    ?: 0
                );

            $complete_po = $pdo->prepare("
                UPDATE purchase_orders
                SET po_status = 'completed',
                    po_total_amount = ?
                WHERE po_id = ?
                AND po_status = 'confirmed'
            ");
            $complete_po->execute([
                $payable_total,
                $po_id,
            ]);

            if ($complete_po->rowCount() !== 1) {
                throw new RuntimeException(
                    'Unable to complete the purchase order.'
                );
            }
        }

        $pdo->commit();

        $message =
            "$gr_number recorded successfully. " .
            'Stock has been updated.';

        if ($return_number !== null) {
            $message .=
                " A return record ($return_number) " .
                'has been created for damaged or rejected items.';
        }

        $_SESSION['flash_success'] = $message;

        header('Location: purchase_orders.php');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e instanceof RuntimeException) {
            $error = $e->getMessage();
        } else {
            error_log(
                'Goods received processing failed: ' .
                $e->getMessage()
            );

            $error =
                'Unable to record the goods received. ' .
                'Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>
        Receive Goods -
        <?= htmlspecialchars($po['po_number']) ?>
    </title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-5xl mx-auto px-6 py-8">

        <p class="text-sm text-gray-400 mb-6">
            <a
                href="purchase_orders.php"
                class="hover:text-red-600 transition-colors"
            >
                Purchase Orders
            </a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">
                Receive Goods —
                <?= htmlspecialchars($po['po_number']) ?>
            </span>
        </p>

        <div class="mb-6">
            <h1 class="text-2xl font-black text-gray-800">
                📦 Receive Goods
            </h1>
            <p class="text-gray-500 text-sm mt-1">
                <?= htmlspecialchars($po['po_number']) ?>
                from
                <?= htmlspecialchars($po['supplier_name']) ?>
            </p>
        </div>

        <div
            class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-6"
        >
            <p class="text-sm text-blue-700">
                📌 Enter <strong>Good Qty</strong> for items in
                acceptable condition (added to stock) and
                <strong>Damaged/Rejected Qty</strong> for items
                with quality issues (will be returned to the
                supplier and excluded from payment).
            </p>
        </div>

        <?php if ($error !== ''): ?>
            <div
                class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-6"
            >
                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>
        <?php endif; ?>

        <?php if ($do_info): ?>
            <div
                class="bg-green-50 border border-green-200 rounded-xl p-4 mb-6"
            >
                <p
                    class="text-sm text-green-700 font-semibold mb-1"
                >
                    🚚 Delivery Order Found:
                    <?= htmlspecialchars($do_info['do_number']) ?>
                </p>
                <p class="text-xs text-green-600">
                    Delivery Date:
                    <?= date(
                        'd M Y',
                        strtotime($do_info['do_delivery_date'])
                    ) ?>
                    · Supplier declared they shipped these
                    quantities. Please verify them against the
                    actual goods received.
                </p>
            </div>
        <?php endif; ?>

        <form method="POST">
            <?php csrf_field(); ?>
            <input
                type="hidden"
                name="confirm_receive"
                value="1"
            >

            <div
                class="bg-white rounded-2xl shadow-sm overflow-hidden mb-6"
            >
                <table class="w-full">
                    <thead>
                        <tr class="bg-gray-50">
                            <th
                                class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase whitespace-nowrap"
                            >
                                Product
                            </th>
                            <th
                                class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase whitespace-nowrap"
                            >
                                Ordered
                            </th>
                            <th
                                class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase whitespace-nowrap"
                            >
                                Remaining
                            </th>
                            <?php if ($do_info): ?>
                                <th
                                    class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase whitespace-nowrap"
                                >
                                    DO Declared
                                </th>
                            <?php endif; ?>
                            <th
                                class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase whitespace-nowrap"
                            >
                                Good Qty
                            </th>
                            <th
                                class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase whitespace-nowrap"
                            >
                                Damaged Qty
                            </th>
                            <th
                                class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase whitespace-nowrap"
                            >
                                Reason
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <?php
                            $remaining =
                                (int) $item[
                                    'po_item_quantity'
                                ] -
                                (int) $item[
                                    'po_item_received_quantity'
                                ] -
                                (int) $item[
                                    'po_item_rejected_quantity'
                                ];
                            ?>
                            <tr class="border-t border-gray-50">
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <?php if ($item['product_cover_image']): ?>
                                            <img
                                                src="../assets/images/<?= htmlspecialchars(
                                                    $item['product_cover_image']
                                                ) ?>"
                                                class="w-8 h-11 object-cover rounded"
                                                alt=""
                                            >
                                        <?php endif; ?>
                                        <div>
                                            <p
                                                class="text-sm font-semibold text-gray-800"
                                            >
                                                <?= htmlspecialchars(
                                                    $item['product_title']
                                                ) ?>
                                            </p>
                                            <?php if ($item['product_volume_number']): ?>
                                                <p
                                                    class="text-xs text-gray-400"
                                                >
                                                    Vol.<?= htmlspecialchars(
                                                        $item['product_volume_number']
                                                    ) ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td
                                    class="px-5 py-4 text-center text-sm font-semibold text-gray-700"
                                >
                                    <?= (int) $item['po_item_quantity'] ?>
                                </td>
                                <td
                                    class="px-5 py-4 text-center text-sm text-gray-500"
                                >
                                    <?= max(0, $remaining) ?>
                                </td>
                                <?php if ($do_info): ?>
                                    <td
                                        class="px-5 py-4 text-center text-sm font-semibold text-blue-600"
                                    >
                                        <?= htmlspecialchars(
                                            (string) (
                                                $do_items[
                                                    $item['po_item_product_id']
                                                ] ?? '—'
                                            )
                                        ) ?>
                                    </td>
                                <?php endif; ?>
                                <td class="px-5 py-4 text-center">
                                    <?php if ($remaining > 0): ?>
                                        <input
                                            type="number"
                                            name="received_qty[<?= (int) $item['po_item_id'] ?>]"
                                            min="0"
                                            max="<?= $remaining ?>"
                                            value="<?= $remaining ?>"
                                            class="good-qty w-20 px-3 py-2 border-2 border-gray-100 rounded-xl text-sm text-center focus:outline-none focus:border-green-400"
                                            data-item="<?= (int) $item['po_item_id'] ?>"
                                            data-remaining="<?= $remaining ?>"
                                            oninput="syncQty(<?= (int) $item['po_item_id'] ?>, 'good')"
                                        >
                                    <?php else: ?>
                                        <span
                                            class="text-green-600 text-xs font-semibold"
                                        >
                                            ✓ Done
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-4 text-center">
                                    <?php if ($remaining > 0): ?>
                                        <input
                                            type="number"
                                            name="rejected_qty[<?= (int) $item['po_item_id'] ?>]"
                                            min="0"
                                            max="<?= $remaining ?>"
                                            value="0"
                                            class="rejected-qty w-20 px-3 py-2 border-2 border-red-100 rounded-xl text-sm text-center focus:outline-none focus:border-red-400"
                                            data-item="<?= (int) $item['po_item_id'] ?>"
                                            data-remaining="<?= $remaining ?>"
                                            oninput="syncQty(<?= (int) $item['po_item_id'] ?>, 'rejected')"
                                        >
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-4">
                                    <?php if ($remaining > 0): ?>
                                        <input
                                            type="text"
                                            name="reject_reason[<?= (int) $item['po_item_id'] ?>]"
                                            placeholder="e.g. Torn cover, water damage"
                                            class="w-full px-3 py-2 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400"
                                        >
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex gap-3">
                <button
                    type="submit"
                    class="bg-red-600 hover:bg-red-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm transition-colors"
                >
                    ✓ Confirm Receipt & Update Stock
                </button>
                <a
                    href="purchase_orders.php"
                    class="border-2 border-gray-200 hover:bg-gray-50 text-gray-600 font-semibold px-6 py-2.5 rounded-xl text-sm transition-colors"
                >
                    Cancel
                </a>
            </div>
        </form>

    </div>

    <script>
    function syncQty(itemId, changed) {
        const goodInput = document.querySelector(
            `.good-qty[data-item="${itemId}"]`
        );
        const rejectedInput = document.querySelector(
            `.rejected-qty[data-item="${itemId}"]`
        );

        if (!goodInput || !rejectedInput) {
            return;
        }

        const remaining = Number.parseInt(
            goodInput.dataset.remaining,
            10
        );

        let good = Number.parseInt(goodInput.value, 10) || 0;
        let rejected =
            Number.parseInt(rejectedInput.value, 10) || 0;

        good = Math.max(0, Math.min(good, remaining));
        rejected = Math.max(
            0,
            Math.min(rejected, remaining)
        );

        if (changed === 'good') {
            rejected = remaining - good;
        } else {
            good = remaining - rejected;
        }

        goodInput.value = good;
        rejectedInput.value = rejected;
    }
    </script>

</body>
</html>