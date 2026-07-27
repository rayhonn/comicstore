<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/gemini_config.php';

header('Content-Type: application/json; charset=UTF-8');

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'customer'
) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

if (isset($_POST['clear'])) {
    unset($_SESSION['chat_history']);
    echo json_encode(['success' => true]);
    exit;
}

if (isset($_POST['get_history'])) {
    $history = $_SESSION['chat_history'] ?? [];

    if (!is_array($history)) {
        $history = [];
    }

    echo json_encode(['history' => $history]);
    exit;
}

$message_raw = $_POST['message'] ?? null;

if (!is_string($message_raw)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid message']);
    exit;
}

$user_message = trim($message_raw);
$message_length = function_exists('mb_strlen')
    ? mb_strlen($user_message, 'UTF-8')
    : strlen($user_message);

if ($user_message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Empty message']);
    exit;
}

if ($message_length > 1000) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Message cannot exceed 1000 characters.',
    ]);
    exit;
}

$user_stmt = $pdo->prepare("
    SELECT
        user_first_name,
        user_last_name,
        user_tier,
        user_points,
        user_lifetime_spending
    FROM users
    WHERE user_id = ?
    AND user_role = 'customer'
    AND user_is_active = 1
");
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$orders_stmt = $pdo->prepare("
    SELECT
        o.order_id,
        o.order_status,
        o.order_payment_status,
        o.order_total_amount,
        o.order_created_at,
        o.order_shipping_fee,
        GROUP_CONCAT(
            oi.order_item_product_title
            SEPARATOR ', '
        ) AS items
    FROM orders o
    JOIN order_items oi
        ON oi.order_item_order_id = o.order_id
    WHERE o.order_user_id = ?
    GROUP BY o.order_id
    ORDER BY o.order_created_at DESC
    LIMIT 5
");
$orders_stmt->execute([$user_id]);
$orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);

$orders_text = 'No orders yet.';

if ($orders) {
    $lines = [];

    foreach ($orders as $order) {
        $order_number =
            '#' .
            str_pad(
                (string) ((int) $order['order_id']),
                4,
                '0',
                STR_PAD_LEFT
            );

        $lines[] =
            $order_number .
            ' — ' .
            (string) $order['items'] .
            ' — Status: ' .
            (string) $order['order_status'] .
            ' — Payment: ' .
            (string) $order['order_payment_status'] .
            ' — RM ' .
            (string) $order['order_total_amount'] .
            ' — Date: ' .
            date(
                'd M Y',
                strtotime($order['order_created_at'])
            );
    }

    $orders_text = implode("\n", $lines);
}

$tier_stmt = $pdo->prepare("
    SELECT
        tier_points_multiplier,
        tier_birthday_bonus_points,
        tier_shipping_discount,
        tier_free_shipping
    FROM tier_config
    WHERE tier_name = ?
");
$tier_stmt->execute([$user['user_tier']]);
$tier_info = $tier_stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'tier_points_multiplier' => '1.0',
    'tier_birthday_bonus_points' => 0,
    'tier_shipping_discount' => '0.00',
    'tier_free_shipping' => 0,
];

$system_prompt = "You are MangaBot, a friendly and helpful AI assistant for MangaVault — a Malaysian online manga store. You help customers with their queries about orders, products, membership tiers, vouchers, returns, and anything related to MangaVault.

IMPORTANT LANGUAGE RULE: Detect the language of the user's message and ALWAYS reply in the SAME language. If they write in English, reply in English. If they write in Chinese (中文), reply in Chinese. If they write in Malay (Bahasa Melayu), reply in Malay. Never mix languages unless the user does.

CURRENT USER INFO:
- Name: {$user['user_first_name']} {$user['user_last_name']}
- Membership Tier: " . ucfirst((string) $user['user_tier']) . "
- Points: {$user['user_points']} pts
- Lifetime Spending: RM {$user['user_lifetime_spending']}
- Points Multiplier: {$tier_info['tier_points_multiplier']}x
- Shipping Discount: RM {$tier_info['tier_shipping_discount']}
- Free Shipping: " . (
    (int) $tier_info['tier_free_shipping'] === 1
        ? 'Yes (Standard only)'
        : 'No'
) . "

USER'S RECENT ORDERS:
$orders_text

MANGAVAULT SYSTEM INFO:
- Products: Physical manga books and E-Books
- Payment: Stripe (credit/debit card)
- Shipping: J&T Express, Ninja Van, Pos Laju, GDex, DHL — Peninsular Malaysia and East Malaysia
- Standard delivery: 3-5 days, Express: 1-2 days
- Free shipping on standard delivery for Platinum tier members

TIER SYSTEM:
- Bronze: RM 0+ lifetime spending — 1x points
- Silver: RM 300+ — 1.5x points, birthday bonus +50 pts, exclusive voucher
- Gold: RM 700+ — 2x points, birthday bonus +150 pts, RM5 shipping discount
- Platinum: RM 1500+ — 3x points, birthday bonus +300 pts, free standard shipping

POINTS SYSTEM:
- Earn points for every RM1 spent (multiplied by tier)
- Redeem points for vouchers in the Points Rewards section
- Points are awarded after payment is confirmed

RETURN POLICY:
- Physical items only, within 7 days of delivery
- Submit return request in My Orders
- Takes up to 3 working days to process
- Approved returns: refund to original payment method within 5-7 working days

VOUCHER SYSTEM:
- Vouchers can be applied at checkout
- Some vouchers have minimum order requirements
- Vouchers expire based on their validity period
- Tier upgrade vouchers are given when you level up

IMPORTANT RULES:
- Be friendly, helpful and concise
- If asked about specific order details you don't have, tell the user to check My Orders page
- Never make up information not provided above
- For payment issues, direct them to contact support
- Keep responses short and easy to read
- Use emojis occasionally to be friendly but not too many";

$history = $_SESSION['chat_history'] ?? [];

if (!is_array($history)) {
    $history = [];
}

$history[] = [
    'role' => 'user',
    'parts' => [['text' => $user_message]],
];

$history = array_slice($history, -10);

$claude_messages = [];

foreach ($history as $message) {
    if (
        !is_array($message) ||
        !isset(
            $message['role'],
            $message['parts'][0]['text']
        ) ||
        !is_string($message['role']) ||
        !is_string($message['parts'][0]['text'])
    ) {
        continue;
    }

    $claude_messages[] = [
        'role' =>
            $message['role'] === 'model'
                ? 'assistant'
                : 'user',
        'content' => $message['parts'][0]['text'],
    ];
}

$claude_body = [
    'model' => CLAUDE_MODEL,
    'max_tokens' => 500,
    'system' => $system_prompt,
    'messages' => $claude_messages,
];

$ch = curl_init(CLAUDE_API_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt(
    $ch,
    CURLOPT_POSTFIELDS,
    json_encode($claude_body)
);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'x-api-key: ' . CLAUDE_API_KEY,
    'anthropic-version: 2023-06-01',
]);

$response = curl_exec($ch);
$http_code = (int) curl_getinfo(
    $ch,
    CURLINFO_HTTP_CODE
);
$curl_error = curl_error($ch);
curl_close($ch);

if (
    $response === false ||
    $curl_error !== '' ||
    $http_code !== 200
) {
    http_response_code(503);
    echo json_encode([
        'error' =>
            'AI service unavailable. Please try again.',
    ]);
    exit;
}

$data = json_decode($response, true);
$ai_reply =
    is_array($data) &&
    isset($data['content'][0]['text']) &&
    is_string($data['content'][0]['text'])
        ? trim($data['content'][0]['text'])
        : '';

if ($ai_reply === '') {
    $ai_reply =
        'Sorry, I could not process your request.';
}

$history[] = [
    'role' => 'model',
    'parts' => [['text' => $ai_reply]],
];

$_SESSION['chat_history'] =
    array_slice($history, -10);

echo json_encode(['reply' => $ai_reply]);