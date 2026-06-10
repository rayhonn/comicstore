<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    exit;
}
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pending = $pdo->prepare("
        SELECT order_id, order_voucher_code FROM orders 
        WHERE order_user_id = ? 
        AND order_payment_status = 'pending_confirmation'
        ORDER BY order_created_at DESC LIMIT 1
    ");
    $pending->execute([$user_id]);
    $pending = $pending->fetch(PDO::FETCH_ASSOC);

    if ($pending && !empty($pending['order_voucher_code'])) {
        $v = $pdo->prepare("SELECT voucher_id FROM vouchers WHERE voucher_code = ?");
        $v->execute([$pending['order_voucher_code']]);
        $v = $v->fetch(PDO::FETCH_ASSOC);
        if ($v) {
            $pdo->prepare("UPDATE user_vouchers SET uv_status = 'available' WHERE uv_voucher_id = ? AND uv_user_id = ? AND uv_is_used = 0")
                ->execute([$v['voucher_id'], $user_id]);
        }

        $pdo->prepare("UPDATE orders SET order_payment_status = 'cancelled', order_status = 'cancelled' WHERE order_id = ?")
            ->execute([$pending['order_id']]);

        $items = $pdo->prepare("SELECT * FROM order_items WHERE order_item_order_id = ?");
        $items->execute([$pending['order_id']]);
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            if ($item['order_item_type'] === 'physical') {
                $pdo->prepare("UPDATE product_physical SET physical_stock_quantity = physical_stock_quantity + ? WHERE physical_product_id = ?")
                    ->execute([$item['order_item_quantity'], $item['order_item_product_id']]);
            }
        }

        $pdo->prepare("DELETE FROM voucher_usage WHERE usage_order_id = ?")
            ->execute([$pending['order_id']]);
        $pdo->prepare("UPDATE vouchers SET voucher_used_count = GREATEST(0, voucher_used_count - 1) WHERE voucher_code = ?")
            ->execute([$pending['order_voucher_code']]);
    }

    unset($_SESSION['pending_order']);
}
echo json_encode(['success' => true]);