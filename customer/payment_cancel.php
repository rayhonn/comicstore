<?php
require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/notifications.php';

// Restore voucher if pending
if (isset($_SESSION['pending_order'])) {
    $order = $_SESSION['pending_order'];
    if (!empty($order['voucher_id'])) {
        require_once '../includes/db.php';
        $pdo->prepare("UPDATE user_vouchers SET uv_status = 'available', uv_is_used = 0, uv_pending_at = NULL WHERE uv_voucher_id = ? AND uv_user_id = ?")
            ->execute([$order['voucher_id'], $_SESSION['user_id']]);
    }
}

// Clear payment session
unset($_SESSION['payment_lock']);
unset($_SESSION['stripe_session_id']);
// Keep pending_order so user can retry checkout
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Cancelled - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen flex items-center justify-center px-6">
    <div class="max-w-md w-full">
        <div class="bg-white rounded-2xl shadow-sm p-10 text-center">
            <div class="w-20 h-20 bg-yellow-100 rounded-full flex items-center justify-center mx-auto mb-5">
                <span class="text-4xl">❌</span>
            </div>
            <h2 class="text-2xl font-black text-gray-800 mb-2">Payment Cancelled</h2>
            <p class="text-gray-500 text-sm mb-2">You cancelled the payment. Your order has not been placed.</p>
            <p class="text-gray-400 text-xs mb-6">Any voucher you applied has been restored to your account.</p>

            <div class="bg-yellow-50 border border-yellow-100 rounded-xl p-4 mb-6 text-left">
                <p class="text-xs text-yellow-700 font-semibold mb-1">ℹ️ What happened?</p>
                <p class="text-xs text-yellow-600">Your cart items are still saved. You can go back to checkout and try again.</p>
            </div>

            <div class="flex gap-3">
                <a href="cart.php" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-xl text-sm transition-colors text-center">
                    Back to Cart
                </a>
                <a href="home.php" class="flex-1 border-2 border-gray-100 hover:bg-gray-50 text-gray-600 font-semibold py-3 rounded-xl text-sm transition-colors text-center">
                    Continue Shopping
                </a>
            </div>
        </div>
    </div>
</body>
</html>