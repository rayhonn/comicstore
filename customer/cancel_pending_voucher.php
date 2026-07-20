<?php
require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.',
    ]);
    exit;
}

csrf_verify();

header('Content-Type: application/json');

$user_id = (int) $_SESSION['user_id'];
$pending_order = $_SESSION['pending_order'] ?? null;

try {
    if (
        is_array($pending_order) &&
        (int) ($pending_order['user_id'] ?? 0) === $user_id &&
        !empty($pending_order['voucher_id'])
    ) {
        $voucher_id = filter_var(
            $pending_order['voucher_id'],
            FILTER_VALIDATE_INT
        );

        if ($voucher_id) {
            $stmt = $pdo->prepare(
                "UPDATE user_vouchers
                 SET uv_status = 'available',
                     uv_is_used = 0,
                     uv_pending_at = NULL
                 WHERE uv_voucher_id = ?
                 AND uv_user_id = ?
                 AND uv_is_used = 0
                 AND uv_status = 'pending'"
            );

            $stmt->execute([
                $voucher_id,
                $user_id,
            ]);
        }
    }

    unset($_SESSION['pending_order']);
    unset($_SESSION['payment_lock']);
    unset($_SESSION['stripe_session_id']);

    echo json_encode([
        'success' => true,
    ]);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to cancel payment.',
    ]);
}