<?php

error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/gemini_config.php';

header('Content-Type: application/json; charset=UTF-8');

function sendRecommendationResponse(
    array $payload,
    int $status_code = 200
): void {
    http_response_code($status_code);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'customer'
) {
    sendRecommendationResponse(
        ['error' => 'Unauthorized'],
        401
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendRecommendationResponse(
        ['error' => 'Method not allowed'],
        405
    );
}

$user_id = (int) $_SESSION['user_id'];
$type_raw = $_POST['type'] ?? 'home';

if (
    !is_string($type_raw) ||
    !in_array($type_raw, ['home', 'product'], true)
) {
    sendRecommendationResponse(
        ['error' => 'Invalid recommendation type'],
        400
    );
}

$type = $type_raw;
$product_id = null;

if ($type === 'product') {
    $product_id = filter_var(
        $_POST['product_id'] ?? null,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if ($product_id === false || $product_id === null) {
        sendRecommendationResponse(
            ['error' => 'Invalid product'],
            400
        );
    }

    $product_id = (int) $product_id;
}

$all_products = $pdo->query("
    SELECT
        p.product_id,
        p.product_title,
        p.product_price,
        p.product_type,
        p.product_cover_image,
        p.product_description,
        genre_summary.genres,
        c.category_name,
        COALESCE(
            sales_summary.total_sold,
            0
        ) AS total_sold
    FROM products p
    LEFT JOIN categories c
        ON c.category_id = p.product_category_id
    LEFT JOIN (
        SELECT
            pg.product_genres_product_id AS product_id,
            GROUP_CONCAT(
                DISTINCT g.genre_name
                ORDER BY g.genre_name
                SEPARATOR ', '
            ) AS genres
        FROM product_genres pg
        JOIN genres g
            ON g.genre_id =
                pg.product_genres_genre_id
        GROUP BY pg.product_genres_product_id
    ) genre_summary
        ON genre_summary.product_id = p.product_id
    LEFT JOIN (
        SELECT
            oi.order_item_product_id AS product_id,
            SUM(
                oi.order_item_quantity
            ) AS total_sold
        FROM order_items oi
        JOIN orders o
            ON oi.order_item_order_id = o.order_id
        WHERE o.order_payment_status = 'confirmed'
        AND o.order_status != 'cancelled'
        GROUP BY oi.order_item_product_id
    ) sales_summary
        ON sales_summary.product_id = p.product_id
    WHERE p.product_is_available = 1
    ORDER BY
        total_sold DESC,
        p.product_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$purchase_stmt = $pdo->prepare("
    SELECT
        p.product_id,
        p.product_title,
        genre_summary.genres,
        c.category_name,
        MAX(o.order_created_at) AS latest_purchase_at
    FROM orders o
    JOIN order_items oi
        ON oi.order_item_order_id = o.order_id
    JOIN products p
        ON p.product_id = oi.order_item_product_id
    LEFT JOIN categories c
        ON c.category_id = p.product_category_id
    LEFT JOIN (
        SELECT
            pg.product_genres_product_id AS product_id,
            GROUP_CONCAT(
                DISTINCT g.genre_name
                ORDER BY g.genre_name
                SEPARATOR ', '
            ) AS genres
        FROM product_genres pg
        JOIN genres g
            ON g.genre_id =
                pg.product_genres_genre_id
        GROUP BY pg.product_genres_product_id
    ) genre_summary
        ON genre_summary.product_id = p.product_id
    WHERE o.order_user_id = ?
    AND o.order_payment_status = 'confirmed'
    AND o.order_status != 'cancelled'
    GROUP BY
        p.product_id,
        p.product_title,
        genre_summary.genres,
        c.category_name
    ORDER BY latest_purchase_at DESC
");
$purchase_stmt->execute([
    $user_id,
]);
$purchase_history =
    $purchase_stmt->fetchAll(PDO::FETCH_ASSOC);

$current_product = null;

if ($type === 'product') {
    $current_stmt = $pdo->prepare("
        SELECT
            p.product_id,
            p.product_title,
            genre_summary.genres,
            c.category_name
        FROM products p
        LEFT JOIN categories c
            ON c.category_id = p.product_category_id
        LEFT JOIN (
            SELECT
                pg.product_genres_product_id AS product_id,
                GROUP_CONCAT(
                    DISTINCT g.genre_name
                    ORDER BY g.genre_name
                    SEPARATOR ', '
                ) AS genres
            FROM product_genres pg
            JOIN genres g
                ON g.genre_id =
                    pg.product_genres_genre_id
            GROUP BY pg.product_genres_product_id
        ) genre_summary
            ON genre_summary.product_id = p.product_id
        WHERE p.product_id = ?
        AND p.product_is_available = 1
        LIMIT 1
    ");
    $current_stmt->execute([
        $product_id,
    ]);
    $current_product =
        $current_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current_product) {
        sendRecommendationResponse(
            ['error' => 'Product not found'],
            404
        );
    }
}

$products_text = '';

foreach ($all_products as $product) {
    $products_text .=
        'ID:' .
        (int) $product['product_id'] .
        ' | ' .
        (string) $product['product_title'] .
        ' | Genres: ' .
        (string) ($product['genres'] ?? '') .
        ' | Category: ' .
        (string) ($product['category_name'] ?? '') .
        ' | Type: ' .
        (string) $product['product_type'] .
        ' | Sold: ' .
        (int) $product['total_sold'] .
        "\n";
}

if ($type === 'home') {
    if ($purchase_history === []) {
        $prompt = "You are a manga recommendation AI for MangaVault store.

The user has no purchase history yet. Recommend 5 popular manga from this list based on overall popularity.

Available products:
$products_text

Return ONLY a JSON array of 5 product IDs:
[1, 5, 3, 8, 2]";
    } else {
        $history_text = '';

        foreach ($purchase_history as $history) {
            $history_text .=
                '- ' .
                (string) $history['product_title'] .
                ' (Genres: ' .
                (string) ($history['genres'] ?? '') .
                ")\n";
        }

        $prompt = "You are a manga recommendation AI for MangaVault store.

User's purchase history:
$history_text

Available products:
$products_text

Recommend 5 available products the user has not bought yet.

Return ONLY a JSON array of 5 product IDs:
[1, 5, 3, 8, 2]";
    }

    $recommendation_limit = 5;
} else {
    $current_title =
        (string) $current_product['product_title'];
    $current_genres =
        (string) ($current_product['genres'] ?? '');
    $current_category =
        (string) ($current_product['category_name'] ?? '');

    $prompt = "You are a manga recommendation AI for MangaVault store.

Current product:
Title: $current_title
Genres: $current_genres
Category: $current_category

Available products:
$products_text

Recommend 3 similar available products. Exclude product ID $product_id.

Return ONLY a JSON array of 3 product IDs:
[1, 5, 3]";

    $recommendation_limit = 3;
}

$claude_body = [
    'model' => CLAUDE_MODEL,
    'max_tokens' => 100,
    'messages' => [
        [
            'role' => 'user',
            'content' => $prompt,
        ],
    ],
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
    sendRecommendationResponse(
        ['error' => 'AI unavailable'],
        503
    );
}

$data = json_decode($response, true);
$ai_text =
    is_array($data) &&
    isset($data['content'][0]['text']) &&
    is_string($data['content'][0]['text'])
        ? trim($data['content'][0]['text'])
        : '[]';

$ai_text = preg_replace(
    '/```(?:json)?|```/i',
    '',
    $ai_text
);
$recommended_raw = json_decode(
    trim((string) $ai_text),
    true
);

if (!is_array($recommended_raw)) {
    sendRecommendationResponse(
        ['error' => 'Invalid AI response'],
        502
    );
}

$recommended_ids = [];

foreach ($recommended_raw as $candidate) {
    $validated = filter_var(
        $candidate,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if ($validated === false || $validated === null) {
        continue;
    }

    $validated = (int) $validated;

    if (
        $product_id !== null &&
        $validated === $product_id
    ) {
        continue;
    }

    $recommended_ids[$validated] = $validated;

    if (count($recommended_ids) >= $recommendation_limit) {
        break;
    }
}

$recommended_ids = array_values($recommended_ids);

if ($recommended_ids === []) {
    sendRecommendationResponse([
        'products' => [],
    ]);
}

$placeholders = implode(
    ',',
    array_fill(0, count($recommended_ids), '?')
);

$product_stmt = $pdo->prepare("
    SELECT
        p.product_id,
        p.product_title,
        p.product_price,
        p.product_type,
        p.product_cover_image,
        genre_summary.genres,
        pp.physical_stock_quantity,
        pe.ebook_product_id
    FROM products p
    LEFT JOIN product_physical pp
        ON pp.physical_product_id = p.product_id
    LEFT JOIN product_ebook pe
        ON pe.ebook_product_id = p.product_id
    LEFT JOIN (
        SELECT
            pg.product_genres_product_id AS product_id,
            GROUP_CONCAT(
                DISTINCT g.genre_name
                ORDER BY g.genre_name
                SEPARATOR ', '
            ) AS genres
        FROM product_genres pg
        JOIN genres g
            ON g.genre_id =
                pg.product_genres_genre_id
        GROUP BY pg.product_genres_product_id
    ) genre_summary
        ON genre_summary.product_id = p.product_id
    WHERE p.product_id IN ($placeholders)
    AND p.product_is_available = 1
");
$product_stmt->execute($recommended_ids);
$products =
    $product_stmt->fetchAll(PDO::FETCH_ASSOC);

$products_by_id = [];

foreach ($products as $product) {
    $products_by_id[(int) $product['product_id']] =
        $product;
}

$sorted = [];

foreach ($recommended_ids as $recommended_id) {
    if (isset($products_by_id[$recommended_id])) {
        $sorted[] =
            $products_by_id[$recommended_id];
    }
}

sendRecommendationResponse([
    'products' => $sorted,
]);