<?php
require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

$order_id = filter_input(
    INPUT_GET,
    'order_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$order_id) {
    redirect_to(app_path('customer/orders.php'));
}

$order = $pdo->prepare("
    SELECT *
    FROM orders
    WHERE order_id = ?
    AND order_user_id = ?
    AND order_payment_status = 'pending_confirmation'
    AND order_confirm_expires_at IS NOT NULL
");
$order->execute([
    $order_id,
    current_user_id(),
]);

$order = $order->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    redirect_to(app_path('customer/orders.php'));
}

date_default_timezone_set('Asia/Kuala_Lumpur');
$expires = new DateTime($order['order_confirm_expires_at']);
$now = new DateTime();
$diff = max(0, $expires->getTimestamp() - $now->getTimestamp());
$order_num = '#' . str_pad($order_id, 4, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Waiting for Confirmation - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
        @keyframes pulse-ring {
            0% { transform: scale(0.8); opacity: 1; }
            100% { transform: scale(1.4); opacity: 0; }
        }
        .pulse-ring { animation: pulse-ring 1.5s ease-out infinite; }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen flex items-center justify-center px-6">
    <div class="max-w-md w-full">

        <div class="bg-white rounded-2xl shadow-sm p-8 text-center">

            <!-- Animated email icon -->
            <div class="relative w-20 h-20 mx-auto mb-6">
                <div class="pulse-ring absolute inset-0 bg-yellow-200 rounded-full"></div>
                <div class="relative w-20 h-20 bg-yellow-100 rounded-full flex items-center justify-center">
                    <span class="text-3xl">📧</span>
                </div>
            </div>

            <h2 class="text-xl font-black text-gray-800 mb-2">Check Your Email!</h2>
            <p class="text-gray-500 text-sm mb-1">We've sent a confirmation link to your email.</p>
            <p class="text-gray-400 text-xs mb-6">Click the <strong>Confirm My Payment</strong> button in the email to complete your order.</p>

            <!-- Order info -->
            <div class="bg-gray-50 rounded-xl p-4 mb-6 text-left">
                <div class="flex justify-between text-sm mb-2">
                    <span class="text-gray-500">Order</span>
                    <span class="font-bold text-gray-800"><?= $order_num ?></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Amount</span>
                    <span class="font-bold text-red-600">RM <?= number_format($order['order_total_amount'], 2) ?></span>
                </div>
            </div>

            <!-- Countdown -->
            <div class="bg-red-50 border border-red-100 rounded-xl p-4 mb-6">
                <p class="text-xs text-red-500 mb-2">⏰ Link expires in:</p>
                <p class="text-3xl font-black text-red-600" id="countdown">05:00</p>
                <p class="text-xs text-red-400 mt-1">Order will be automatically cancelled if not confirmed</p>
            </div>

            <!-- Status check -->
            <div id="statusMsg" class="hidden bg-green-50 border border-green-200 rounded-xl p-3 mb-4">
                <p class="text-sm text-green-700 font-semibold">✅ Payment confirmed! Redirecting...</p>
            </div>

            <p class="text-xs text-gray-400 mb-4">Didn't receive the email? Check your spam folder.</p>

            <a href="orders.php" class="text-xs text-gray-400 hover:text-red-600 transition-colors">
                View My Orders
            </a>
        </div>
    </div>

    <script>
    let timeLeft = <?= (int) $diff ?>;

    const orderId = <?= (int) $order_id ?>;
    const csrfToken = <?= json_encode(csrf_token()) ?>;

    let countdownInterval = null;
    let cancellationStarted = false;

    async function cancelExpiredOrder() {
        if (cancellationStarted) {
            return;
        }

        cancellationStarted = true;

        if (countdownInterval !== null) {
            clearInterval(countdownInterval);
        }

        try {
            const response = await fetch(
                'cancel_expired_order.php',
                {
                    method: 'POST',
                    headers: {
                        'Content-Type':
                            'application/x-www-form-urlencoded;charset=UTF-8',
                        'Accept': 'application/json'
                    },
                    body: new URLSearchParams({
                        csrf_token: csrfToken,
                        order_id: orderId.toString()
                  })
                }
            );

            const data = await response
                .json()
                .catch(() => null);

            if (
                response.ok &&
                data !== null &&
                data.success === true
            ) {
                window.location.href =
                    'orders.php?cancelled=1';

                return;
            }

            checkStatus();

            setTimeout(() => {
                cancellationStarted = false;
                cancelExpiredOrder();
            }, 3000);
        } catch (error) {
            setTimeout(() => {
                cancellationStarted = false;
                cancelExpiredOrder();
            }, 3000);
        }
    }

    function updateCountdown() {
        if (timeLeft <= 0) {
            document
                .getElementById('countdown')
                .textContent = '00:00';

            document
                .getElementById('countdown')
                .classList
                .add('text-gray-400');

            cancelExpiredOrder();
            return;
        }

        const mins = Math
            .floor(timeLeft / 60)
            .toString()
            .padStart(2, '0');

        const secs = (timeLeft % 60)
            .toString()
            .padStart(2, '0');

        document
            .getElementById('countdown')
            .textContent = mins + ':' + secs;

        timeLeft--;
    }

    function checkStatus() {
        fetch(
            'check_payment_status.php?order_id=' +
            orderId
        )
            .then(response => response.json())
            .then(data => {
                if (data.status === 'confirmed') {
                    document
                        .getElementById('statusMsg')
                        .classList
                        .remove('hidden');

                    setTimeout(() => {
                        window.location.href =
                            'order_success.php?order_id=' +
                            orderId;
                    }, 2000);
                } else if (data.status === 'cancelled') {
                    window.location.href =
                        'orders.php?cancelled=1';
                }
            })
            .catch(() => {});
    }

    updateCountdown();

    if (!cancellationStarted) {
        countdownInterval = setInterval(
            updateCountdown,
            1000
        );
    }

    setInterval(
        checkStatus,
        3000
    );
</script>
</body>
</html>