<?php
error_reporting(0);
ini_set('display_errors', 0);
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
require_once '../includes/db.php';
require_once '../includes/gemini_config.php';

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$type = $_POST['type'] ?? 'home';
$product_id = $_POST['product_id'] ?? null;

// Get all available products
$all_products = $pdo->query("
    SELECT p.product_id, p.product_title, p.product_price, p.product_type,
    p.product_cover_image, p.product_description,
    GROUP_CONCAT(DISTINCT g.genre_name SEPARATOR ', ') as genres,
    c.category_name,
    COALESCE(SUM(oi.order_item_quantity), 0) as total_sold
    FROM products p
    LEFT JOIN product_genres pg ON pg.product_genres_product_id = p.product_id
    LEFT JOIN genres g ON g.genre_id = pg.product_genres_genre_id
    LEFT JOIN categories c ON c.category_id = p.product_category_id
    LEFT JOIN order_items oi ON oi.order_item_product_id = p.product_id
    WHERE p.product_is_available = 1
    GROUP BY p.product_id
    ORDER BY total_sold DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get user purchase history
$purchase_history = $pdo->prepare("
    SELECT p.product_id, p.product_title,
    GROUP_CONCAT(DISTINCT g.genre_name SEPARATOR ', ') as genres,
    c.category_name
    FROM orders o
    JOIN order_items oi ON oi.order_item_order_id = o.order_id
    JOIN products p ON p.product_id = oi.order_item_product_id
    LEFT JOIN product_genres pg ON pg.product_genres_product_id = p.product_id
    LEFT JOIN genres g ON g.genre_id = pg.product_genres_genre_id
    LEFT JOIN categories c ON c.category_id = p.product_category_id
    WHERE o.order_user_id = ? AND o.order_payment_status = 'confirmed'
    GROUP BY p.product_id
");
$purchase_history->execute([$user_id]);
$purchase_history = $purchase_history->fetchAll(PDO::FETCH_ASSOC);

// Get current product info if product page
$current_product = null;
if ($type === 'product' && $product_id) {
    $cp = $pdo->prepare("
        SELECT p.*, GROUP_CONCAT(DISTINCT g.genre_name SEPARATOR ', ') as genres,
        c.category_name
        FROM products p
        LEFT JOIN product_genres pg ON pg.product_genres_product_id = p.product_id
        LEFT JOIN genres g ON g.genre_id = pg.product_genres_genre_id
        LEFT JOIN categories c ON c.category_id = p.product_category_id
        WHERE p.product_id = ?
        GROUP BY p.product_id
    ");
    $cp->execute([$product_id]);
    $current_product = $cp->fetch(PDO::FETCH_ASSOC);
}

// Build product list for Claude
$products_text = '';
foreach ($all_products as $p) {
    $products_text .= "ID:{$p['product_id']} | {$p['product_title']} | Genres: {$p['genres']} | Category: {$p['category_name']} | Type: {$p['product_type']} | Sold: {$p['total_sold']}\n";
}

// Build prompt
if ($type === 'home') {
    if (empty($purchase_history)) {
        $prompt = "You are a manga recommendation AI for MangaVault store.

The user has no purchase history yet. Recommend 6 popular manga from this list based on overall popularity (total_sold).

Available products:
$products_text

Return ONLY a JSON array of 6 product IDs like this (no explanation, no markdown, just pure JSON):
[1, 5, 3, 8, 2, 7]

Pick the 6 most popular ones based on total sold.";
    } else {
        $history_text = '';
        foreach ($purchase_history as $h) {
            $history_text .= "- {$h['product_title']} (Genres: {$h['genres']})\n";
        }

        $prompt = "You are a manga recommendation AI for MangaVault store.

User's purchase history:
$history_text

Available products to recommend from:
$products_text

Based on the user's taste from their purchase history, recommend 6 products they haven't bought yet that match their interests.

Return ONLY a JSON array of 6 product IDs like this (no explanation, no markdown, just pure JSON):
[1, 5, 3, 8, 2, 7]";
    }
} else {
    $prompt = "You are a manga recommendation AI for MangaVault store.

Current product the user is viewing:
Title: {$current_product['product_title']}
Genres: {$current_product['genres']}
Category: {$current_product['category_name']}

Available products to recommend from:
$products_text

Recommend 4 similar products based on same genres or category. Exclude the current product (ID: $product_id).

Return ONLY a JSON array of 4 product IDs like this (no explanation, no markdown, just pure JSON):
[1, 5, 3, 8]";
}

// Call Claude API
$claude_body = [
    'model' => CLAUDE_MODEL,
    'max_tokens' => 100,
    'messages' => [
        ['role' => 'user', 'content' => $prompt]
    ]
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
    echo json_encode(['error' => 'AI unavailable', 'debug' => $response]);
    exit;
}

$data = json_decode($response, true);
$ai_text = $data['content'][0]['text'] ?? '[]';

// Parse product IDs from Claude response
$ai_text = trim($ai_text);
$ai_text = preg_replace('/```json|```/', '', $ai_text);
$recommended_ids = json_decode($ai_text, true);

if (!is_array($recommended_ids)) {
    echo json_encode(['error' => 'Invalid AI response', 'raw' => $ai_text]);
    exit;
}

if (empty($recommended_ids)) {
    echo json_encode(['products' => []]);
    exit;
}

$placeholders = implode(',', array_fill(0, count($recommended_ids), '?'));
$products = $pdo->prepare("
    SELECT p.product_id, p.product_title, p.product_price, p.product_type,
    p.product_cover_image,
    GROUP_CONCAT(DISTINCT g.genre_name SEPARATOR ', ') as genres,
    pp.physical_stock_quantity,
    pe.ebook_product_id
    FROM products p
    LEFT JOIN product_genres pg ON pg.product_genres_product_id = p.product_id
    LEFT JOIN genres g ON g.genre_id = pg.product_genres_genre_id
    LEFT JOIN product_physical pp ON pp.physical_product_id = p.product_id
    LEFT JOIN product_ebook pe ON pe.ebook_product_id = p.product_id
    WHERE p.product_id IN ($placeholders) AND p.product_is_available = 1
    GROUP BY p.product_id
");
$products->execute($recommended_ids);
$products = $products->fetchAll(PDO::FETCH_ASSOC);

$sorted = [];
foreach ($recommended_ids as $id) {
    foreach ($products as $p) {
        if ($p['product_id'] == $id) {
            $sorted[] = $p;
            break;
        }
    }
}

echo json_encode(['products' => $sorted]);