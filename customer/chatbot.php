<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
require_once '../includes/db.php';
require_once '../includes/gemini_config.php';

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
// Handle clear chat
if (isset($_POST['clear'])) {
    unset($_SESSION['chat_history']);
    echo json_encode(['success' => true]);
    exit;
}

// Return chat history
if (isset($_POST['get_history'])) {
    echo json_encode(['history' => $_SESSION['chat_history'] ?? []]);
    exit;
}
$user_message = trim($_POST['message'] ?? '');

if (empty($user_message)) {
    echo json_encode(['error' => 'Empty message']);
    exit;
}

// Get user info
$user = $pdo->prepare("SELECT user_first_name, user_last_name, user_tier, user_points, user_lifetime_spending FROM users WHERE user_id = ?");
$user->execute([$user_id]);
$user = $user->fetch(PDO::FETCH_ASSOC);

// Get user's recent orders
$orders = $pdo->prepare("
    SELECT o.order_id, o.order_status, o.order_payment_status, o.order_total_amount, 
    o.order_created_at, o.order_shipping_fee,
    GROUP_CONCAT(p.product_title SEPARATOR ', ') as items
    FROM orders o
    JOIN order_items oi ON oi.order_item_order_id = o.order_id
    JOIN products p ON oi.order_item_product_id = p.product_id
    WHERE o.order_user_id = ?
    GROUP BY o.order_id
    ORDER BY o.order_created_at DESC
    LIMIT 5
");
$orders->execute([$user_id]);
$orders = $orders->fetchAll(PDO::FETCH_ASSOC);

$orders_text = '';
if (empty($orders)) {
    $orders_text = 'No orders yet.';
} else {
    foreach ($orders as $o) {
        $order_num = '#' . str_pad($o['order_id'], 4, '0', STR_PAD_LEFT);
        $orders_text .= "$order_num — {$o['items']} — Status: {$o['order_status']} — Payment: {$o['order_payment_status']} — RM {$o['order_total_amount']} — Date: " . date('d M Y', strtotime($o['order_created_at'])) . "\n";
    }
}

// Get tier benefits
$tier_info = $pdo->prepare("SELECT tier_points_multiplier, tier_birthday_bonus_points, tier_shipping_discount, tier_free_shipping FROM tier_config WHERE tier_name = ?");
$tier_info->execute([$user['user_tier']]);
$tier_info = $tier_info->fetch(PDO::FETCH_ASSOC);

// Build system prompt
$system_prompt = "You are MangaBot, a friendly and helpful AI assistant for MangaVault — a Malaysian online manga store. You help customers with their queries about orders, products, membership tiers, vouchers, returns, and anything related to MangaVault.

IMPORTANT LANGUAGE RULE: Detect the language of the user's message and ALWAYS reply in the SAME language. If they write in English, reply in English. If they write in Chinese (中文), reply in Chinese. If they write in Malay (Bahasa Melayu), reply in Malay. Never mix languages unless the user does.

CURRENT USER INFO:
- Name: {$user['user_first_name']} {$user['user_last_name']}
- Membership Tier: " . ucfirst($user['user_tier']) . "
- Points: {$user['user_points']} pts
- Lifetime Spending: RM {$user['user_lifetime_spending']}
- Points Multiplier: {$tier_info['tier_points_multiplier']}x
- Shipping Discount: RM {$tier_info['tier_shipping_discount']}
- Free Shipping: " . ($tier_info['tier_free_shipping'] ? 'Yes (Standard only)' : 'No') . "

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

// Get or initialize chat history
if (!isset($_SESSION['chat_history'])) {
    $_SESSION['chat_history'] = [];
}

// Add user message to history
$_SESSION['chat_history'][] = [
    'role' => 'user',
    'parts' => [['text' => $user_message]]
];

// Keep only last 10 messages to avoid token limit
if (count($_SESSION['chat_history']) > 10) {
    $_SESSION['chat_history'] = array_slice($_SESSION['chat_history'], -10);
}

// Build Claude API request
$claude_messages = [];
foreach ($_SESSION['chat_history'] as $msg) {
    $claude_messages[] = [
        'role' => $msg['role'] === 'model' ? 'assistant' : 'user',
        'content' => $msg['parts'][0]['text']
    ];
}

$claude_body = [
    'model' => CLAUDE_MODEL,
    'max_tokens' => 500,
    'system' => $system_prompt,
    'messages' => $claude_messages
];

$ch = curl_init(CLAUDE_API_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($claude_body));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'x-api-key: ' . CLAUDE_API_KEY,
    'anthropic-version: 2023-06-01'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    echo json_encode(['error' => 'AI service unavailable. Please try again.', 'debug' => $response, 'code' => $http_code]);
    exit;
}

$data = json_decode($response, true);
$ai_reply = $data['content'][0]['text'] ?? 'Sorry, I could not process your request.';

// Add AI reply to history
$_SESSION['chat_history'][] = [
    'role' => 'model',
    'parts' => [['text' => $ai_reply]]
];

echo json_encode(['reply' => $ai_reply]);