<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];
$order_id = $_GET['order_id'] ?? null;
$item_id = $_GET['item_id'] ?? null;

if (!$order_id || !$item_id) {
    header('Location: orders.php');
    exit;
}

// Verify order belongs to user, is delivered, and item is physical
$stmt = $pdo->prepare("
    SELECT o.*, oi.order_item_type, p.product_title
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

// Check if return already submitted
$existing = $pdo->prepare("SELECT return_id, return_status FROM return_requests WHERE return_item_id = ?");
$existing->execute([$item_id]);
$existing = $existing->fetch();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existing) {
    $reason = trim($_POST['return_reason']);
    if (empty($reason)) {
        $error = "Please provide a reason for return.";
    } else {
        $pdo->prepare("INSERT INTO return_requests (return_order_id, return_user_id, return_item_id, return_reason) VALUES (?, ?, ?, ?)")
            ->execute([$order_id, $user_id, $item_id, $reason]);
        $success = "Return request submitted successfully!";
        $existing = ['return_status' => 'pending'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Return Request - MangaVault</title>
</head>
<body>
    <p><a href="orders.php">← Back to My Orders</a></p>
    <h1>Request Return</h1>
    <hr>

    <p><b>Order #<?= $order_id ?></b> — <?= htmlspecialchars($order['product_title']) ?></p>

    <?php if ($error): ?>
        <p style="color:red;"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if ($success): ?>
        <p style="color:green;"><?= htmlspecialchars($success) ?></p>
        <a href="orders.php">Back to My Orders</a>
    <?php elseif ($existing): ?>
        <p style="color:orange;">Return request already submitted. Status: <b><?= ucfirst($existing['return_status']) ?></b></p>
        <?php if ($existing['return_status'] === 'approved'): ?>
            <p style="color:green;">✓ Your return has been approved!</p>
        <?php elseif ($existing['return_status'] === 'rejected'): ?>
            <p style="color:red;">✗ Your return has been rejected.</p>
        <?php endif; ?>
        <a href="orders.php">Back to My Orders</a>
    <?php else: ?>
        <form method="POST" style="max-width:500px;">
            <label><b>Reason for Return *</b><br>
                <textarea name="return_reason" rows="5" style="width:100%; padding:8px; margin-top:6px;" 
                          placeholder="Please describe the reason for your return..." required></textarea>
            </label>
            <br><br>
            <p style="font-size:13px; color:#888;">
                ⚠️ Note: Returns are only accepted for physical items within the eligible period. 
                Our team will review your request within 3-5 business days.
            </p>
            <button type="submit" style="padding:10px 25px; background:#e74c3c; color:white; border:none; border-radius:4px; cursor:pointer;">
                Submit Return Request
            </button>
        </form>
    <?php endif; ?>
</body>
</html>