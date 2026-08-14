<?php
require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/money_helper.php';

$user_id = (int) $_SESSION['user_id'];
$order_id = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$item_id = filter_input(INPUT_GET, 'item_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($order_id === false || $order_id === null || $item_id === false || $item_id === null) {
    header('Location: orders.php');
    exit;
}

date_default_timezone_set('Asia/Kuala_Lumpur');

// Verify order belongs to user, delivered, physical, within 7 days
$stmt = $pdo->prepare("
    SELECT
        o.*,
        oi.order_item_id,
        oi.order_item_type,
        oi.order_item_price,
        oi.order_item_quantity,
        oi.order_item_product_title
            AS product_title,
        p.product_cover_image,
        CASE
            WHEN
                o.order_delivered_at IS NOT NULL
                AND NOW() >=
                    o.order_delivered_at
                AND NOW() <= DATE_ADD(
                    o.order_delivered_at,
                    INTERVAL 7 DAY
                )
            THEN 1
            ELSE 0
        END AS return_window_open,
        GREATEST(
            0,
            CEIL(
                COALESCE(
                    TIMESTAMPDIFF(
                        SECOND,
                        NOW(),
                        DATE_ADD(
                            o.order_delivered_at,
                            INTERVAL 7 DAY
                        )
                    ),
                    0
                ) / 86400
            )
        ) AS return_days_remaining
    FROM orders o
    JOIN order_items oi
        ON oi.order_item_order_id =
            o.order_id
    JOIN products p
        ON oi.order_item_product_id =
            p.product_id
    WHERE
        o.order_id = ?
        AND o.order_user_id = ?
        AND oi.order_item_id = ?
        AND o.order_status = 'delivered'
        AND oi.order_item_type = 'physical'
");
$stmt->execute([$order_id, $user_id, $item_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: orders.php');
    exit;
}

// Enforce the exact 7-day return window using the database time.
$within_window =
    (int) $order['return_window_open'] === 1;

$days_remaining = max(
    0,
    (int) $order['return_days_remaining']
);

// Check if return already submitted
$existing = $pdo->prepare("SELECT return_id, return_status, return_admin_note FROM return_requests WHERE return_item_id = ?");
$existing->execute([$item_id]);
$existing = $existing->fetch(PDO::FETCH_ASSOC);

$order_num = '#' . str_pad((string) $order_id, 4, '0', STR_PAD_LEFT);
$return_amount_sen = moneyDecimalToSen((string) $order['order_item_price']) * (int) $order['order_item_quantity'];
$error = '';
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existing && $within_window) {
    csrf_verify();
    $reason_type = $_POST['reason_type'] ?? null;
    $reason_detail_raw = $_POST['reason_detail'] ?? '';
    $reason_options = [
        'Wrong item received',
        'Item damaged / defective',
        'Item not as described',
        'Missing item / incomplete order',
        'Other',
    ];

    if (!is_string($reason_type) || !in_array($reason_type, $reason_options, true) || !is_string($reason_detail_raw)) {
        $error = 'Please select a valid reason for return.';
        $reason_detail = '';
    } else {
        $reason_detail = trim($reason_detail_raw);
        $reason_detail_length = function_exists('mb_strlen') ? mb_strlen($reason_detail, 'UTF-8') : strlen($reason_detail);
        if ($reason_detail_length > 2000) {
            $error = 'Additional details cannot exceed 2000 characters.';
        } elseif ($reason_type === 'Other' && $reason_detail === '') {
            $error = 'Please provide details for the selected reason.';
        }
    }

    if ($error !== '') {
        // Validation error already set.
    } elseif (empty($reason_type)) {
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
                    Your return has been approved. The final refund amount
                    has been calculated after any voucher discount and
                    credited to your <strong>MangaVault Wallet</strong>.
                    If you prefer a bank transfer, you must submit the
                    withdrawal request within <strong>7 days</strong> of
                    the refund credit. Please check your notification for
                    the confirmed refund amount.
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
            <p class="text-gray-500 text-sm mb-6 max-w-sm mx-auto">Returns are only accepted within <strong>7 days</strong> of delivery. The exact 7-day return deadline for this order has passed.</p>
            <a href="orders.php" class="inline-block bg-red-600 hover:bg-red-700 text-white font-bold px-8 py-3 rounded-xl text-sm transition-colors">
                Back to Orders
            </a>
        </div>

        <?php else: ?>
        <!-- RETURN FORM -->
        <div class="bg-white rounded-2xl shadow-sm p-8">
            <h2 class="text-xl font-black text-gray-800 mb-1">Request a Return</h2>
            <p class="text-sm text-gray-400 mb-6">Order <?= $order_num ?> · <?= $days_remaining ?> day(s) remaining to request</p>

            <!-- Product Info -->
            <div class="flex items-center gap-4 bg-gray-50 rounded-xl p-4 mb-6">
                <?php if (!empty($order['product_cover_image'])): ?>
                <img src="../assets/images/<?= htmlspecialchars($order['product_cover_image']) ?>" class="w-12 h-16 object-cover rounded-lg flex-shrink-0">
                <?php endif; ?>
                <div class="flex-1">
                    <p class="font-bold text-sm text-gray-800"><?= htmlspecialchars($order['product_title']) ?></p>
                    <p class="text-xs text-gray-400">Qty: <?= $order['order_item_quantity'] ?> · RM <?= moneyFormatSen($return_amount_sen) ?></p>
                </div>
            </div>

            <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-5">
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" id="returnForm">
                <?php csrf_field(); ?>
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
                    <textarea name="reason_detail" rows="3" maxlength="2000"
                              placeholder="Provide more details about your return..."
                              class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors resize-none bg-gray-50 focus:bg-white"></textarea>
                </div>

                <!-- Info Box -->
                <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-6">
                    <div class="flex items-start gap-2">
                        <span class="text-amber-500 text-lg flex-shrink-0">ℹ️</span>
                        <div class="text-xs text-amber-700 leading-relaxed">
                            <p class="font-semibold mb-1">Return Policy</p>
                            <p>Returns are only accepted for physical items within 7 days of delivery. Our team will review your request within <strong>3 working days</strong>. If approved, the eligible refund will be credited to your MangaVault Wallet.</p>
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