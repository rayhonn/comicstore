<?php

function restoreOrderPhysicalStock(
    PDO $pdo,
    int $order_id
): void {
    if ($order_id <= 0) {
        throw new RuntimeException(
            'Invalid order for stock restoration.'
        );
    }

    $items = $pdo->prepare("
        SELECT
            order_item_product_id,
            order_item_quantity
        FROM order_items
        WHERE order_item_order_id = ?
        AND order_item_type = 'physical'
    ");

    $items->execute([
        $order_id,
    ]);

    $restore_stock = $pdo->prepare("
        UPDATE product_physical
        SET physical_stock_quantity =
            physical_stock_quantity + ?
        WHERE physical_product_id = ?
    ");

    foreach (
        $items->fetchAll(PDO::FETCH_ASSOC)
        as $item
    ) {
        $restore_stock->execute([
            (int) $item['order_item_quantity'],
            (int) $item['order_item_product_id'],
        ]);

        if ($restore_stock->rowCount() !== 1) {
            throw new RuntimeException(
                'Unable to restore order stock.'
            );
        }
    }
}