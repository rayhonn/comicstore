<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

// Get the pending order
$pending = $pdo->prepare("
    SELECT order_id, order_confirm_expires_at FROM orders 
    WHERE order_user_id = ? AND order_payment_status = 'pending_confirmation'
    AND order_confirm_expires_at > NOW()
    ORDER BY order_created_at DESC LIMIT 1
");
$pending->execute([$_SESSION['user_id']]);
$pending = $pending->fetch(PDO::FETCH_ASSOC);

if (!$pending) {
    header('Location: payment_gateway.php');
    exit;
}

$expires = new DateTime($pending['order_confirm_expires_at']);
$now = new DateTime();
$diff = max(0, $expires->getTimestamp() - $now->getTimestamp());
$mins = ceil($diff / 60);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Pending - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen flex items-center justify-center px-6">
    <div class="max-w-md w-full">
        <div class="bg-white rounded-2xl shadow-sm p-8 text-center">
            <div class="w-16 h-16 bg-yellow-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="text-3xl">⏳</span>
            </div>
            <h2 class="text-xl font-black text-gray-800 mb-2">Payment Pending</h2>
            <p class="text-gray-500 text-sm mb-4">You have a payment awaiting confirmation. Please check your email and confirm it first.</p>

            <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-6">
                <p class="text-sm text-yellow-700 font-semibold mb-1">⏰ Please wait <span id="timer"><?= $diff ?></span> seconds</p>
                <p class="text-xs text-yellow-600">Or confirm the pending payment from your email to proceed.</p>
            </div>

            <a href="payment_waiting.php?order_id=<?= $pending['order_id'] ?>"
               class="block w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-xl text-sm transition-colors mb-3">
                Go to Pending Payment
            </a>
            <a href="orders.php" class="text-xs text-gray-400 hover:text-red-600 transition-colors">
                View My Orders
            </a>
        </div>
    </div>
    <script>
    let t = <?= $diff ?>;
    const el = document.getElementById('timer');
    const iv = setInterval(() => {
        t--;
        el.textContent = t;
        if (t <= 0) { clearInterval(iv); window.location.reload(); }
    }, 1000);
    </script>
</body>
</html>