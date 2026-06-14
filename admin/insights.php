<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}
require_once '../includes/db.php';
require_once '../includes/gemini_config.php';

$period = $_GET['period'] ?? '30';
$period_label = match($period) {
    '1'   => 'Today',
    '7'   => 'Last 7 Days',
    '30'  => 'Last 30 Days',
    'all' => 'All Time',
    default => 'Last 30 Days'
};

$date_condition = match($period) {
    '1'   => "AND DATE(order_created_at) = CURDATE()",
    '7'   => "AND order_created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
    '30'  => "AND order_created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
    'all' => "",
    default => "AND order_created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
};

// Total revenue
$revenue = $pdo->query("
    SELECT 
        COUNT(*) as total_orders,
        COALESCE(SUM(order_total_amount), 0) as total_revenue,
        COALESCE(AVG(order_total_amount), 0) as avg_order_value
    FROM orders 
    WHERE order_payment_status = 'confirmed' 
    AND order_status != 'cancelled'
    $date_condition
")->fetch(PDO::FETCH_ASSOC);

// Top products
$top_products = $pdo->query("
    SELECT p.product_title, p.product_type,
    SUM(oi.order_item_quantity) as total_qty,
    SUM(oi.order_item_price * oi.order_item_quantity) as total_revenue
    FROM order_items oi
    JOIN products p ON oi.order_item_product_id = p.product_id
    JOIN orders o ON oi.order_item_order_id = o.order_id
    WHERE o.order_payment_status = 'confirmed'
    AND o.order_status != 'cancelled'
    $date_condition
    GROUP BY p.product_id
    ORDER BY total_qty DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// New customers
$new_customers = $pdo->query("
    SELECT COUNT(*) as count FROM users 
    WHERE user_role = 'customer'
    " . ($period !== 'all' ? "AND user_created_at >= DATE_SUB(NOW(), INTERVAL $period DAY)" : "") . "
")->fetchColumn();

// Tier distribution
$tier_dist = $pdo->query("
    SELECT user_tier, COUNT(*) as count 
    FROM users WHERE user_role = 'customer' 
    GROUP BY user_tier
")->fetchAll(PDO::FETCH_ASSOC);

// Return rate
$returns = $pdo->query("
    SELECT COUNT(*) as count FROM return_requests rr
    JOIN orders o ON rr.return_order_id = o.order_id
    WHERE 1=1 $date_condition
")->fetchColumn();

// Daily revenue trend (last 7 or 30 days)
$trend_days = $period === '7' ? 7 : 30;
$trend = $pdo->query("
    SELECT DATE(order_created_at) as date,
    COUNT(*) as orders,
    SUM(order_total_amount) as revenue
    FROM orders
    WHERE order_payment_status = 'confirmed'
    AND order_status != 'cancelled'
    AND order_created_at >= DATE_SUB(NOW(), INTERVAL $trend_days DAY)
    GROUP BY DATE(order_created_at)
    ORDER BY date ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Build data summary for Claude
$top_products_text = '';
foreach ($top_products as $p) {
    $top_products_text .= "- {$p['product_title']} ({$p['product_type']}): {$p['total_qty']} units sold, RM " . number_format($p['total_revenue'], 2) . " revenue\n";
}

$tier_text = '';
foreach ($tier_dist as $t) {
    $tier_text .= "- " . ucfirst($t['user_tier']) . ": {$t['count']} customers\n";
}

$generate_insights = isset($_GET['generate']);
$ai_insights = null;

if ($generate_insights) {
    $data_summary = "
SALES DATA SUMMARY FOR $period_label:

Revenue Metrics:
- Total Orders: {$revenue['total_orders']}
- Total Revenue: RM " . number_format($revenue['total_revenue'], 2) . "
- Average Order Value: RM " . number_format($revenue['avg_order_value'], 2) . "
- Return Requests: $returns

Top Selling Products:
$top_products_text

Customer Data:
- New Customers: $new_customers
- Tier Distribution:
$tier_text
";

    $prompt = "You are a business analyst AI for MangaVault, a Malaysian online manga store. Analyze the following sales data and provide actionable insights.

$data_summary

Please provide:
1. **Sales Performance Summary** — How is the business doing? Key highlights.
2. **Top Product Analysis** — What's selling well and why?
3. **Customer Insights** — What does the customer data tell us?
4. **Areas of Concern** — Any red flags or areas to watch?
5. **Actionable Recommendations** — 3-5 specific actions the admin should take to improve sales.

Keep it concise, professional, and actionable. Use Malaysian Ringgit (RM) for currency references.";

    $claude_body = [
        'model' => CLAUDE_MODEL,
        'max_tokens' => 1000,
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

    if ($http_code === 200) {
        $data = json_decode($response, true);
        $ai_insights = $data['content'][0]['text'] ?? null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Sales Insights - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-6xl mx-auto px-6 py-8">

        <!-- Header -->
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-black text-gray-800">🤖 AI Sales Insights</h1>
                <p class="text-gray-500 text-sm mt-1">Powered by Claude AI — Analyze your sales data and get actionable recommendations</p>
            </div>
        </div>

        <!-- Period Selector + Generate Button -->
        <div class="bg-white rounded-2xl shadow-sm p-5 mb-6 flex flex-wrap gap-4 items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="text-sm font-semibold text-gray-600">Time Period:</span>
                <div class="flex gap-2">
                    <?php foreach (['1' => 'Today', '7' => '7 Days', '30' => '30 Days', 'all' => 'All Time'] as $val => $label): ?>
                    <a href="?period=<?= $val ?>"
                       class="px-4 py-2 rounded-xl text-sm font-semibold transition-colors <?= $period == $val ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
                        <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <a href="?period=<?= $period ?>&generate=1"
               class="bg-red-600 hover:bg-red-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm transition-colors flex items-center gap-2">
                🤖 Generate AI Insights
            </a>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Total Revenue</p>
                <p class="text-2xl font-black text-gray-800">RM <?= number_format($revenue['total_revenue'], 2) ?></p>
                <p class="text-xs text-gray-400 mt-1"><?= $period_label ?></p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Total Orders</p>
                <p class="text-2xl font-black text-gray-800"><?= $revenue['total_orders'] ?></p>
                <p class="text-xs text-gray-400 mt-1"><?= $period_label ?></p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Avg Order Value</p>
                <p class="text-2xl font-black text-gray-800">RM <?= number_format($revenue['avg_order_value'], 2) ?></p>
                <p class="text-xs text-gray-400 mt-1"><?= $period_label ?></p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Return Requests</p>
                <p class="text-2xl font-black text-gray-800"><?= $returns ?></p>
                <p class="text-xs text-gray-400 mt-1"><?= $period_label ?></p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Top Products -->
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">🏆 Top Selling Products</h3>
                <?php if (empty($top_products)): ?>
                <p class="text-gray-400 text-sm text-center py-4">No sales data for this period.</p>
                <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($top_products as $i => $p): ?>
                    <div class="flex items-center gap-3">
                        <span class="w-6 h-6 bg-red-600 text-white text-xs font-black rounded-full flex items-center justify-center flex-shrink-0"><?= $i + 1 ?></span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-gray-800 truncate"><?= htmlspecialchars($p['product_title']) ?></p>
                            <p class="text-xs text-gray-400"><?= $p['total_qty'] ?> units · RM <?= number_format($p['total_revenue'], 2) ?></p>
                        </div>
                        <span class="text-xs <?= $p['product_type'] === 'ebook' ? 'bg-blue-100 text-blue-600' : 'bg-green-100 text-green-600' ?> px-2 py-0.5 rounded-full font-semibold flex-shrink-0">
                            <?= $p['product_type'] === 'ebook' ? 'E-Book' : 'Physical' ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Tier Distribution -->
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">🏅 Customer Tier Distribution</h3>
                <?php
                $tier_colors = [
                    'bronze'   => ['bg' => 'bg-orange-100', 'text' => 'text-orange-700', 'bar' => 'bg-orange-400', 'emoji' => '🥉'],
                    'silver'   => ['bg' => 'bg-gray-100',   'text' => 'text-gray-600',   'bar' => 'bg-gray-400',   'emoji' => '🥈'],
                    'gold'     => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-700', 'bar' => 'bg-yellow-400', 'emoji' => '🥇'],
                    'platinum' => ['bg' => 'bg-blue-100',   'text' => 'text-blue-700',   'bar' => 'bg-blue-400',   'emoji' => '💎'],
                ];
                $total_customers = array_sum(array_column($tier_dist, 'count'));
                ?>
                <div class="space-y-3">
                    <?php foreach ($tier_dist as $t):
                        $colors = $tier_colors[$t['user_tier']] ?? $tier_colors['bronze'];
                        $pct = $total_customers > 0 ? round($t['count'] / $total_customers * 100) : 0;
                    ?>
                    <div>
                        <div class="flex justify-between text-xs font-semibold mb-1">
                            <span><?= $colors['emoji'] ?> <?= ucfirst($t['user_tier']) ?></span>
                            <span class="<?= $colors['text'] ?>"><?= $t['count'] ?> (<?= $pct ?>%)</span>
                        </div>
                        <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full <?= $colors['bar'] ?> rounded-full" style="width:<?= $pct ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="text-xs text-gray-400 mt-4">Total: <?= $total_customers ?> customers</p>
            </div>
        </div>

        <!-- AI Insights -->
        <?php if ($ai_insights): ?>
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
            <div class="flex items-center gap-3 mb-5">
                <div class="w-10 h-10 bg-red-600 rounded-xl flex items-center justify-center">
                    <span class="text-white text-xl">🤖</span>
                </div>
                <div>
                    <h3 class="font-black text-gray-800">AI Sales Analysis</h3>
                    <p class="text-xs text-gray-400">Generated for <?= $period_label ?></p>
                </div>
            </div>
            <div class="prose prose-sm max-w-none text-gray-700 leading-relaxed">
            <?php
            $formatted = htmlspecialchars($ai_insights);
            // Headers
            $formatted = preg_replace('/^#### (.*?)$/m', '<h5 class="font-bold text-gray-700 mt-3 mb-1 text-sm uppercase tracking-wide">$1</h5>', $formatted);
            $formatted = preg_replace('/^### (.*?)$/m', '<h4 class="font-bold text-gray-800 mt-5 mb-2 text-base border-b border-gray-100 pb-1">$1</h4>', $formatted);
            $formatted = preg_replace('/^## (.*?)$/m', '<h3 class="font-black text-gray-800 mt-6 mb-3 text-lg">$1</h3>', $formatted);
            $formatted = preg_replace('/^\d+\. \*\*(.*?)\*\*(.*?)$/m', '<div class="flex gap-3 mb-3 p-3 bg-gray-50 rounded-xl"><span class="font-black text-red-600 flex-shrink-0">●</span><div><strong class="text-gray-800">$1</strong><span class="text-gray-600">$2</span></div></div>', $formatted);
            $formatted = preg_replace('/\*\*(.*?)\*\*/', '<strong class="text-gray-800">$1</strong>', $formatted);
            $formatted = preg_replace('/^- (.*?)$/m', '<div class="flex gap-2 mb-2"><span class="text-red-400 flex-shrink-0 mt-0.5">▸</span><span class="text-gray-600 text-sm">$1</span></div>', $formatted);
            $formatted = preg_replace('/^\d+\. (.*?)$/m', '<div class="flex gap-2 mb-2"><span class="text-red-600 font-bold flex-shrink-0">$1.</span><span class="text-gray-600 text-sm"></span></div>', $formatted);
            $formatted = preg_replace('/^---+$/m', '<hr class="border-gray-100 my-4">', $formatted);
            $formatted = preg_replace('/\n\n+/', '</p><p class="mb-3 text-gray-600 text-sm leading-relaxed">', $formatted);
            $formatted = preg_replace('/\n/', '<br>', $formatted);
            echo '<div class="space-y-1"><p class="mb-3 text-gray-600 text-sm leading-relaxed">' . $formatted . '</p></div>';
            ?>
            </div>
        </div>
        <?php elseif ($generate_insights): ?>
        <div class="bg-red-50 border border-red-200 rounded-2xl p-6 mb-6 text-center">
            <p class="text-red-600 font-semibold">❌ Failed to generate insights. Please try again.</p>
        </div>
        <?php else: ?>
        <div class="bg-gradient-to-br from-[#1e2d4a] to-[#2c3e6b] rounded-2xl p-8 text-center">
            <div class="text-5xl mb-4">🤖</div>
            <h3 class="text-xl font-black text-white mb-2">Ready to Analyze</h3>
            <p class="text-blue-200 text-sm mb-6 max-w-md mx-auto">Click "Generate AI Insights" to get Claude AI's analysis of your sales data with actionable recommendations.</p>
            <a href="?period=<?= $period ?>&generate=1"
               class="bg-red-600 hover:bg-red-700 text-white font-bold px-8 py-3 rounded-xl text-sm transition-colors inline-block">
                🤖 Generate AI Insights
            </a>
        </div>
        <?php endif; ?>

    </div>
</body>
</html>