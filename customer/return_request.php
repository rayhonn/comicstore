<?php
require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';

$user_id = $_SESSION['user_id'];
$order_id = $_GET['order_id'] ?? null;
$item_id  = $_GET['item_id'] ?? null;

if (!$order_id || !$item_id) {
    header('Location: orders.php');
    exit;
}

date_default_timezone_set('Asia/Kuala_Lumpur');

// Verify order belongs to user, delivered, physical, within 7 days
$stmt = $pdo->prepare("
    SELECT o.*, oi.order_item_id, oi.order_item_type, oi.order_item_price, oi.order_item_quantity,
    p.product_title, p.product_cover_image
    FROM orders o
    JOIN order_items oi ON oi.order_item_order_id = o.order_id
    JOIN products p ON oi.order_item_product_id = p.product_id
    WHERE o.order_id = ? AND o.order_user_id = ? AND oi.order_item_id = ?
    AND o.order_status = 'delivered' AND oi.order_item_type = 'physical'
");
$stmt->execute([$order_id, $user_id, $item_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: orders.php');
    exit;
}

// Check 7 day window
$delivered_at = new DateTime($order['order_delivered_at']);
$now = new DateTime();
$days_since = $now->diff($delivered_at)->days;
$within_window = $days_since <= 7;

// Check if return already submitted
$existing = $pdo->prepare("SELECT return_id, return_status, return_admin_note FROM return_requests WHERE return_item_id = ?");
$existing->execute([$item_id]);
$existing = $existing->fetch(PDO::FETCH_ASSOC);

$order_num = '#' . str_pad($order_id, 4, '0', STR_PAD_LEFT);
$error = '';
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existing && $within_window) {
    $reason_type = trim($_POST['reason_type'] ?? '');
    $reason_detail = trim($_POST['reason_detail'] ?? '');

    if (empty($reason_type)) {
        $error = 'Please select a reason for return.';
    } else {
        $full_reason = $reason_type;
        if ($reason_type === 'Other' && !empty($reason_detail)) {
            $full_reason = 'Other: ' . $reason_detail;
        } elseif (!empty($reason_detail)) {
            $full_reason = $reason_type . ' — ' . $reason_detail;
        }

        $pdo->prepare("INSERT INTO return_requests (return_order_id, return_user_id, return_item_id, return_reason) VALUES (?, ?, ?, ?)")
            ->execute([$order_id, $user_id, $item_id, $full_reason]);

        $submitted = true;
        $existing = ['return_status' => 'pending', 'return_admin_note' => null];
    }
}

$reason_options = [
    'Wrong item received',
    'Item damaged / defective',
    'Item not as described',
    'Missing item / incomplete order',
    'Other',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return Request - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-2xl mx-auto px-6 py-10">

        <!-- Breadcrumb -->
        <p class="text-sm text-gray-400 mb-6">
            <a href="../index.php" class="hover:text-red-600 transition-colors">Home</a>
            <span class="mx-2">›</span>
            <a href="orders.php" class="hover:text-red-600 transition-colors">My Orders</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">Return Request</span>
        </p>

        <?php if ($submitted): ?>
        <!-- SUCCESS STATE -->
        <div class="bg-white rounded-2xl shadow-sm p-10 text-center">
            <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-5">
                <span class="text-4xl">✅</span>
            </div>
            <h2 class="text-2xl font-black text-gray-800 mb-2">Request Submitted!</h2>
            <p class="text-gray-500 text-sm mb-6 max-w-sm mx-auto leading-relaxed">
                Your return request for <strong><?= htmlspecialchars($order['product_title']) ?></strong> has been submitted successfully.
            </p>
            <div class="bg-blue-50 border border-blue-100 rounded-2xl p-5 mb-6 text-left">
                <div class="flex items-start gap-3">
                    <span class="text-2xl">⏳</span>
                    <div>
                        <p class="font-bold text-blue-800 text-sm mb-1">What happens next?</p>
                        <p class="text-blue-600 text-sm leading-relaxed">Please wait up to <strong>3 working days</strong> for our team to review your return request. You will be notified via notification once a decision has been made.</p>
                    </div>
                </div>
            </div>
            <div class="flex gap-3">
                <a href="returns.php" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-xl text-sm transition-colors text-center">
                    View My Returns
                </a>
                <a href="orders.php" class="flex-1 border-2 border-gray-100 hover:bg-gray-50 text-gray-600 font-semibold py-3 rounded-xl text-sm transition-colors text-center">
                    Back to Orders
                </a>
            </div>
        </div>

        <?php elseif ($existing): ?>
        <!-- ALREADY SUBMITTED -->
        <div class="bg-white rounded-2xl shadow-sm p-8">
            <h2 class="text-xl font-black text-gray-800 mb-6">Return Request Status</h2>

            <!-- Product -->
            <div class="flex items-center gap-4 bg-gray-50 rounded-xl p-4 mb-6">
                <?php if (!empty($order['product_cover_image'])): ?>
                <img src="../assets/images/<?= htmlspecialchars($order['product_cover_image']) ?>" class="w-12 h-16 object-cover rounded-lg flex-shrink-0">
                <?php endif; ?>
                <div>
                    <p class="font-bold text-sm text-gray-800"><?= htmlspecialchars($order['product_title']) ?></p>
                    <p class="text-xs text-gray-400">Order <?= $order_num ?></p>
                </div>
            </div>

            <?php
            $status_config = [
                'pending'  => ['bg' => 'bg-yellow-50', 'border' => 'border-yellow-200', 'text' => 'text-yellow-800', 'icon' => '⏳', 'label' => 'Under Review'],
                'approved' => ['bg' => 'bg-green-50',  'border' => 'border-green-200',  'text' => 'text-green-800',  'icon' => '✅', 'label' => 'Approved'],
                'rejected' => ['bg' => 'bg-red-50',    'border' => 'border-red-200',    'text' => 'text-red-800',    'icon' => '❌', 'label' => 'Rejected'],
            ];
            $sc = $status_config[$existing['return_status']] ?? $status_config['pending'];
            ?>

            <div class="<?= $sc['bg'] ?> <?= $sc['border'] ?> border-2 rounded-2xl p-5 mb-4">
                <div class="flex items-center gap-3 mb-2">
                    <span class="text-2xl"><?= $sc['icon'] ?></span>
                    <p class="font-black text-lg <?= $sc['text'] ?>"><?= $sc['label'] ?></p>
                </div>
                <?php if ($existing['return_status'] === 'pending'): ?>
                    <p class="text-sm <?= $sc['text'] ?> opacity-80">Please wait up to <strong>3 working days</strong> for our team to review your request.</p>
                <?php elseif ($existing['return_status'] === 'approved'): ?>
                    <p class="text-sm <?= $sc['text'] ?> opacity-80">Your return has been approved. A refund of <strong>RM <?= number_format($order['order_item_price'] * $order['order_item_quantity'], 2) ?></strong> will be processed to your original payment method within <strong>5-7 working days</strong>.</p>
                <?php elseif ($existing['return_status'] === 'rejected'): ?>
                    <p class="text-sm <?= $sc['text'] ?> opacity-80">Your return request was not approved.</p>
                <?php endif; ?>
            </div>

            <?php if (!empty($existing['return_admin_note'])): ?>
            <div class="bg-gray-50 rounded-xl p-4 mb-4">
                <p class="text-xs font-semibold text-gray-500 mb-1 uppercase tracking-wide">Note from our team</p>
                <p class="text-sm text-gray-700"><?= htmlspecialchars($existing['return_admin_note']) ?></p>
            </div>
            <?php endif; ?>

            <a href="returns.php" class="block w-full text-center bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-xl text-sm transition-colors">
                View All Returns
            </a>
        </div>

        <?php elseif (!$within_window): ?>
        <!-- EXPIRED WINDOW -->
        <div class="bg-white rounded-2xl shadow-sm p-10 text-center">
            <div class="w-20 h-20 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-5">
                <span class="text-4xl">⏰</span>
            </div>
            <h2 class="text-xl font-black text-gray-800 mb-2">Return Window Expired</h2>
            <p class="text-gray-500 text-sm mb-6 max-w-sm mx-auto">Returns are only accepted within <strong>7 days</strong> of delivery. This order was delivered <?= $days_since ?> days ago.</p>
            <a href="orders.php" class="inline-block bg-red-600 hover:bg-red-700 text-white font-bold px-8 py-3 rounded-xl text-sm transition-colors">
                Back to Orders
            </a>
        </div>

        <?php else: ?>
        <!-- RETURN FORM -->
        <div class="bg-white rounded-2xl shadow-sm p-8">
            <h2 class="text-xl font-black text-gray-800 mb-1">Request a Return</h2>
            <p class="text-sm text-gray-400 mb-6">Order <?= $order_num ?> · <?= 7 - $days_since ?> day(s) remaining to request</p>

            <!-- Product Info -->
            <div class="flex items-center gap-4 bg-gray-50 rounded-xl p-4 mb-6">
                <?php if (!empty($order['product_cover_image'])): ?>
                <img src="../assets/images/<?= htmlspecialchars($order['product_cover_image']) ?>" class="w-12 h-16 object-cover rounded-lg flex-shrink-0">
                <?php endif; ?>
                <div class="flex-1">
                    <p class="font-bold text-sm text-gray-800"><?= htmlspecialchars($order['product_title']) ?></p>
                    <p class="text-xs text-gray-400">Qty: <?= $order['order_item_quantity'] ?> · RM <?= number_format($order['order_item_price'] * $order['order_item_quantity'], 2) ?></p>
                </div>
            </div>

            <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-5">
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" id="returnForm">
                <!-- Reason Options -->
                <div class="mb-5">
                    <label class="block text-xs font-semibold text-gray-500 mb-3 uppercase tracking-wide">Reason for Return *</label>
                    <div class="space-y-2">
                        <?php foreach ($reason_options as $reason): ?>
                        <label class="flex items-center gap-3 p-3 border-2 border-gray-100 rounded-xl cursor-pointer hover:border-red-300 transition-colors has-[:checked]:border-red-500 has-[:checked]:bg-red-50">
                            <input type="radio" name="reason_type" value="<?= htmlspecialchars($reason) ?>"
                                   class="accent-red-600"
                                   onchange="toggleOtherField(this.value)">
                            <span class="text-sm text-gray-700"><?= htmlspecialchars($reason) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Additional Details -->
                <div class="mb-5" id="detailsField">
                    <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Additional Details <span class="text-gray-300 normal-case font-normal">(optional)</span></label>
                    <textarea name="reason_detail" rows="3"
                              placeholder="Provide more details about your return..."
                              class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors resize-none bg-gray-50 focus:bg-white"></textarea>
                </div>

                <!-- Info Box -->
                <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-6">
                    <div class="flex items-start gap-2">
                        <span class="text-amber-500 text-lg flex-shrink-0">ℹ️</span>
                        <div class="text-xs text-amber-700 leading-relaxed">
                            <p class="font-semibold mb-1">Return Policy</p>
                            <p>Returns are only accepted for physical items within 7 days of delivery. Our team will review your request within <strong>3 working days</strong>. If approved, refund will be processed within 5-7 working days.</p>
                        </div>
                    </div>
                </div>

                <button type="submit"
                        class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3.5 rounded-xl text-sm transition-colors">
                    Submit Return Request
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <script>
    function toggleOtherField(value) {
        const field = document.getElementById('detailsField');
        const textarea = field.querySelector('textarea');
        if (value === 'Other') {
            textarea.placeholder = 'Please describe your reason...';
            textarea.required = true;
        } else {
            textarea.placeholder = 'Provide more details about your return...';
            textarea.required = false;
        }
    }
    </script>

</body>
</html>