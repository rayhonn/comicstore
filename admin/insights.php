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

$date_condition_with_o = match($period) {
    '1'   => "AND DATE(o.order_created_at) = CURDATE()",
    '7'   => "AND o.order_created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
    '30'  => "AND o.order_created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
    'all' => "",
    default => "AND o.order_created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
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
    $date_condition_with_o
    GROUP BY p.product_id
    ORDER BY total_qty DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// New customers
$user_date = $period !== 'all' ? "AND user_created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)" : "";
$new_customers = $pdo->query("
    SELECT COUNT(*) FROM users 
    WHERE user_role = 'customer' $user_date
")->fetchColumn();

// Tier distribution
$tier_dist = $pdo->query("
    SELECT user_tier, COUNT(*) as count 
    FROM users WHERE user_role = 'customer' 
    GROUP BY user_tier
")->fetchAll(PDO::FETCH_ASSOC);

// Return requests
$returns = $pdo->query("
    SELECT COUNT(*) FROM return_requests rr
    JOIN orders o ON rr.return_order_id = o.order_id
    WHERE 1=1 $date_condition_with_o
")->fetchColumn();

// Daily revenue trend
$trend_days = in_array($period, ['1', '7']) ? 7 : 30;
$trend = $pdo->query("
    SELECT DATE(order_created_at) as date,
    COUNT(*) as orders,
    COALESCE(SUM(order_total_amount), 0) as revenue
    FROM orders
    WHERE order_payment_status = 'confirmed'
    AND order_status != 'cancelled'
    AND order_created_at >= DATE_SUB(NOW(), INTERVAL $trend_days DAY)
    GROUP BY DATE(order_created_at)
    ORDER BY date ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Generate AI insights
$generate_insights = isset($_GET['generate']);
$ai_insights = null;

if ($generate_insights) {
    $top_products_text = '';
    foreach ($top_products as $p) {
        $top_products_text .= "- {$p['product_title']} ({$p['product_type']}): {$p['total_qty']} units, RM " . number_format($p['total_revenue'], 2) . "\n";
    }
    $tier_text = '';
    foreach ($tier_dist as $t) {
        $tier_text .= "- " . ucfirst($t['user_tier']) . ": {$t['count']} customers\n";
    }

    $prompt = "You are a business analyst for MangaVault, a Malaysian online manga store. Analyze this sales data and provide a concise professional report.

SALES DATA ($period_label):
- Total Revenue: RM " . number_format($revenue['total_revenue'], 2) . "
- Total Orders: {$revenue['total_orders']}
- Average Order Value: RM " . number_format($revenue['avg_order_value'], 2) . "
- Return Requests: $returns
- New Customers: $new_customers

Top Products:
$top_products_text

Customer Tiers:
$tier_text

Provide a SHORT, professional analysis with these sections:
1. **Sales Overview** — 2-3 sentences on overall performance
2. **Top Products** — What's selling and why
3. **Customer Insights** — Tier distribution analysis
4. **Recommendations** — 3 specific actions to improve sales

Keep it concise and professional. Use RM for currency.";

    $claude_body = [
        'model' => CLAUDE_MODEL,
        'max_tokens' => 600,
        'messages' => [['role' => 'user', 'content' => $prompt]]
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
    curl_close($ch);
    $data = json_decode($response, true);
    $ai_insights = $data['content'][0]['text'] ?? null;
}

// Handle PDF download
if (isset($_POST['download_pdf'])) {
    require_once '../vendor/autoload.php';
    $chart_image = $_POST['chart_image'] ?? '';
    $tier_chart_image = $_POST['tier_chart_image'] ?? '';
    $ai_text = $_POST['ai_text'] ?? '';

    $top_products_rows = '';
    foreach ($top_products as $i => $p) {
        $top_products_rows .= "<tr style='background:" . ($i % 2 === 0 ? '#f9fafb' : '#ffffff') . "'>
            <td style='padding:8px 12px; font-size:12px;'>" . htmlspecialchars($p['product_title']) . "</td>
            <td style='padding:8px 12px; font-size:12px; text-align:center;'>" . ucfirst($p['product_type']) . "</td>
            <td style='padding:8px 12px; font-size:12px; text-align:center;'>{$p['total_qty']}</td>
            <td style='padding:8px 12px; font-size:12px; text-align:right;'>RM " . number_format($p['total_revenue'], 2) . "</td>
        </tr>";
    }

    // Format AI text for PDF
    $ai_formatted = htmlspecialchars($ai_text);
    $ai_formatted = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $ai_formatted);
    $ai_formatted = preg_replace('/^### (.*?)$/m', '<h4 style="color:#1e2d4a; font-size:13px; margin:12px 0 6px;">$1</h4>', $ai_formatted);
    $ai_formatted = preg_replace('/^## (.*?)$/m', '<h3 style="color:#1e2d4a; font-size:14px; margin:14px 0 6px;">$1</h3>', $ai_formatted);
    $ai_formatted = preg_replace('/^- (.*?)$/m', '<div style="margin:3px 0; padding-left:12px; font-size:11px; color:#374151;">▸ $1</div>', $ai_formatted);
    $ai_formatted = preg_replace('/^\d+\. (.*?)$/m', '<div style="margin:4px 0; padding-left:12px; font-size:11px; color:#374151;">• $1</div>', $ai_formatted);
    $ai_formatted = str_replace("\n\n", '<br><br>', $ai_formatted);
    $ai_formatted = str_replace("\n", '<br>', $ai_formatted);

    $html = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: Arial, sans-serif; margin:0; padding:20px; color:#111827;'>
        
        <!-- Header -->
        <div style='background:linear-gradient(135deg, #1e2d4a, #2c3e6b); padding:20px; border-radius:8px; margin-bottom:20px;'>
            <h1 style='color:white; font-size:20px; margin:0; font-weight:900;'>MANGA<span style='color:#ef4444;'>VAULT</span></h1>
            <p style='color:rgba(255,255,255,0.7); font-size:11px; margin:4px 0 0;'>AI Sales Insights Report — $period_label</p>
            <p style='color:rgba(255,255,255,0.5); font-size:10px; margin:2px 0 0;'>Generated on " . date('d F Y, h:i A') . "</p>
        </div>

        <!-- Stats -->
        <div style='display:table; width:100%; margin-bottom:20px;'>
            <div style='display:table-cell; width:25%; padding:12px; background:#fef2f2; border-radius:8px; text-align:center; border:1px solid #fecaca;'>
                <p style='font-size:10px; color:#6b7280; margin:0 0 4px; text-transform:uppercase;'>Total Revenue</p>
                <p style='font-size:18px; font-weight:900; color:#C0392B; margin:0;'>RM " . number_format($revenue['total_revenue'], 2) . "</p>
            </div>
            <div style='display:table-cell; width:25%; padding:12px; background:#f0fdf4; border-radius:8px; text-align:center; border:1px solid #bbf7d0; padding-left:8px;'>
                <p style='font-size:10px; color:#6b7280; margin:0 0 4px; text-transform:uppercase;'>Total Orders</p>
                <p style='font-size:18px; font-weight:900; color:#16a34a; margin:0;'>{$revenue['total_orders']}</p>
            </div>
            <div style='display:table-cell; width:25%; padding:12px; background:#eff6ff; border-radius:8px; text-align:center; border:1px solid #bfdbfe; padding-left:8px;'>
                <p style='font-size:10px; color:#6b7280; margin:0 0 4px; text-transform:uppercase;'>Avg Order Value</p>
                <p style='font-size:18px; font-weight:900; color:#1d4ed8; margin:0;'>RM " . number_format($revenue['avg_order_value'], 2) . "</p>
            </div>
            <div style='display:table-cell; width:25%; padding:12px; background:#fefce8; border-radius:8px; text-align:center; border:1px solid #fde68a; padding-left:8px;'>
                <p style='font-size:10px; color:#6b7280; margin:0 0 4px; text-transform:uppercase;'>Returns</p>
                <p style='font-size:18px; font-weight:900; color:#d97706; margin:0;'>$returns</p>
            </div>
        </div>

        <!-- Charts -->
        " . ($chart_image ? "<div style='margin-bottom:20px;'>
            <h3 style='font-size:13px; font-weight:700; color:#1e2d4a; margin:0 0 8px;'>📈 Revenue Trend</h3>
            <img src='$chart_image' style='width:100%; border-radius:8px; border:1px solid #f3f4f6;'>
        </div>" : "") . "

        " . ($tier_chart_image ? "<div style='margin-bottom:20px;'>
            <h3 style='font-size:13px; font-weight:700; color:#1e2d4a; margin:0 0 8px;'>🏅 Customer Tier Distribution</h3>
            <img src='$tier_chart_image' style='width:60%; border-radius:8px; border:1px solid #f3f4f6;'>
        </div>" : "") . "

        <!-- Top Products Table -->
        <div style='margin-bottom:20px;'>
            <h3 style='font-size:13px; font-weight:700; color:#1e2d4a; margin:0 0 8px;'>🏆 Top Selling Products</h3>
            <table style='width:100%; border-collapse:collapse; font-size:12px;'>
                <thead>
                    <tr style='background:#1e2d4a; color:white;'>
                        <th style='padding:8px 12px; text-align:left;'>Product</th>
                        <th style='padding:8px 12px; text-align:center;'>Type</th>
                        <th style='padding:8px 12px; text-align:center;'>Units Sold</th>
                        <th style='padding:8px 12px; text-align:right;'>Revenue</th>
                    </tr>
                </thead>
                <tbody>$top_products_rows</tbody>
            </table>
        </div>

        <!-- AI Analysis -->
        " . ($ai_text ? "<div style='background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:20px;'>
            <h3 style='font-size:13px; font-weight:700; color:#1e2d4a; margin:0 0 10px; display:flex; align-items:center; gap:6px;'>📊 AI Sales Analysis</h3>
            <div style='font-size:11px; line-height:1.6; color:#374151;'>$ai_formatted</div>
        </div>" : "") . "

        <!-- Footer -->
        <div style='border-top:1px solid #e5e7eb; padding-top:12px; text-align:center;'>
            <p style='font-size:10px; color:#9ca3af; margin:0;'>MangaVault Sales Report — Confidential</p>
        </div>
    </body>
    </html>";

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->getOptions()->setChroot(realpath('../'));
    $dompdf->getOptions()->setIsRemoteEnabled(true);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream('MangaVault_Sales_Report_' . $period_label . '_' . date('Y-m-d') . '.pdf', ['Attachment' => true]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Sales Insights - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-6xl mx-auto px-6 py-8">

        <!-- Header -->
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-black text-gray-800">📊 AI Sales Insights</h1>
                <p class="text-gray-500 text-sm mt-1">Analyze your sales data and generate downloadable reports</p>
            </div>
        </div>

        <!-- Period Selector + Buttons -->
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
            <div class="flex gap-3">
                <a href="?period=<?= $period ?>&generate=1"
                   class="bg-red-600 hover:bg-red-700 text-white font-bold px-5 py-2.5 rounded-xl text-sm transition-colors flex items-center gap-2">
                    📊 Generate Insights
                </a>
                <?php if ($ai_insights): ?>
                <button onclick="downloadPDF()"
                        class="bg-[#1e2d4a] hover:bg-[#162338] text-white font-bold px-5 py-2.5 rounded-xl text-sm transition-colors flex items-center gap-2">
                    ⬇️ Download PDF
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-2xl shadow-sm p-5 border-l-4 border-red-500">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Total Revenue</p>
                <p class="text-2xl font-black text-gray-800">RM <?= number_format($revenue['total_revenue'], 2) ?></p>
                <p class="text-xs text-gray-400 mt-1"><?= $period_label ?></p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5 border-l-4 border-green-500">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Total Orders</p>
                <p class="text-2xl font-black text-gray-800"><?= $revenue['total_orders'] ?></p>
                <p class="text-xs text-gray-400 mt-1"><?= $period_label ?></p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5 border-l-4 border-blue-500">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Avg Order Value</p>
                <p class="text-2xl font-black text-gray-800">RM <?= number_format($revenue['avg_order_value'], 2) ?></p>
                <p class="text-xs text-gray-400 mt-1"><?= $period_label ?></p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5 border-l-4 border-yellow-500">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Return Requests</p>
                <p class="text-2xl font-black text-gray-800"><?= $returns ?></p>
                <p class="text-xs text-gray-400 mt-1"><?= $period_label ?></p>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <!-- Revenue Trend Chart -->
            <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">📈 Revenue Trend</h3>
                <canvas id="revenueChart" height="120"></canvas>
            </div>
            <!-- Tier Doughnut Chart -->
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">🏅 Customer Tiers</h3>
                <canvas id="tierChart" height="180"></canvas>
            </div>
        </div>

        <!-- Top Products + Tier Table -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
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
                        <span class="text-xs <?= $p['product_type'] === 'ebook' ? 'bg-blue-100 text-blue-600' : 'bg-green-100 text-green-600' ?> px-2 py-0.5 rounded-full font-semibold">
                            <?= $p['product_type'] === 'ebook' ? 'E-Book' : 'Physical' ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-2xl shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">🏅 Tier Distribution</h3>
                <?php
                $tier_colors = [
                    'bronze'   => ['bar' => 'bg-orange-400', 'text' => 'text-orange-700', 'emoji' => '🥉'],
                    'silver'   => ['bar' => 'bg-gray-400',   'text' => 'text-gray-600',   'emoji' => '🥈'],
                    'gold'     => ['bar' => 'bg-yellow-400', 'text' => 'text-yellow-700', 'emoji' => '🥇'],
                    'platinum' => ['bar' => 'bg-blue-400',   'text' => 'text-blue-700',   'emoji' => '💎'],
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
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-6" id="ai-insights-section">
            <div class="flex items-center gap-3 mb-5">
                <div class="w-10 h-10 bg-[#1e2d4a] rounded-xl flex items-center justify-center">
                    <span class="text-white text-xl">📊</span>
                </div>
                <div>
                    <h3 class="font-black text-gray-800">AI Sales Analysis</h3>
                    <p class="text-xs text-gray-400">Generated for <?= $period_label ?></p>
                </div>
                <div class="ml-auto">
                    <button onclick="downloadPDF()"
                            class="bg-[#1e2d4a] hover:bg-[#162338] text-white font-bold px-5 py-2 rounded-xl text-sm transition-colors flex items-center gap-2">
                        ⬇️ Download PDF Report
                    </button>
                </div>
            </div>
            <div class="bg-gray-50 rounded-xl p-5">
                <?php
                $formatted = htmlspecialchars($ai_insights);
                $formatted = preg_replace('/\*\*(.*?)\*\*/', '<strong class="text-gray-800">$1</strong>', $formatted);
                $formatted = preg_replace('/^### (.*?)$/m', '<h4 class="font-bold text-[#1e2d4a] mt-4 mb-2 text-sm uppercase tracking-wide">$1</h4>', $formatted);
                $formatted = preg_replace('/^## (.*?)$/m', '<h3 class="font-bold text-[#1e2d4a] mt-5 mb-2">$1</h3>', $formatted);
                $formatted = preg_replace('/^- (.*?)$/m', '<div class="flex gap-2 mb-1.5"><span class="text-red-500 flex-shrink-0">▸</span><span class="text-gray-600 text-sm">$1</span></div>', $formatted);
                $formatted = preg_replace('/^\d+\. (.*?)$/m', '<div class="flex gap-2 mb-2"><span class="text-red-600 font-bold flex-shrink-0">•</span><span class="text-gray-600 text-sm">$1</span></div>', $formatted);
                $formatted = preg_replace('/^---+$/m', '<hr class="border-gray-200 my-3">', $formatted);
                $formatted = str_replace("\n\n", '</p><p class="mb-3 text-gray-600 text-sm leading-relaxed">', $formatted);
                $formatted = str_replace("\n", '<br>', $formatted);
                echo '<p class="mb-3 text-gray-600 text-sm leading-relaxed">' . $formatted . '</p>';
                ?>
            </div>
        </div>
        <?php elseif ($generate_insights): ?>
        <div class="bg-red-50 border border-red-200 rounded-2xl p-6 mb-6 text-center">
            <p class="text-red-600 font-semibold">❌ Failed to generate insights. Please try again.</p>
        </div>
        <?php else: ?>
        <div class="bg-gradient-to-br from-[#1e2d4a] to-[#2c3e6b] rounded-2xl p-8 text-center">
            <div class="text-5xl mb-4">📊</div>
            <h3 class="text-xl font-black text-white mb-2">Ready to Analyze</h3>
            <p class="text-blue-200 text-sm mb-6 max-w-md mx-auto">Click "Generate Insights" to get an AI analysis of your sales data with actionable recommendations.</p>
            <a href="?period=<?= $period ?>&generate=1"
               class="bg-red-600 hover:bg-red-700 text-white font-bold px-8 py-3 rounded-xl text-sm transition-colors inline-block">
                📊 Generate AI Insights
            </a>
        </div>
        <?php endif; ?>

    </div>

    <!-- Hidden form for PDF download -->
    <form id="pdfForm" method="POST" style="display:none;">
        <input type="hidden" name="download_pdf" value="1">
        <input type="hidden" name="period" value="<?= $period ?>">
        <input type="hidden" name="chart_image" id="chartImageInput">
        <input type="hidden" name="tier_chart_image" id="tierChartImageInput">
        <input type="hidden" name="ai_text" value="<?= htmlspecialchars($ai_insights ?? '') ?>">
    </form>

    <script>
    // Revenue Trend Chart
    const trendData = <?= json_encode($trend) ?>;
    const labels = trendData.map(d => d.date);
    const revenues = trendData.map(d => parseFloat(d.revenue));

    const revenueCtx = document.getElementById('revenueChart').getContext('2d');
    const revenueChart = new Chart(revenueCtx, {
        type: 'line',
        data: {
            labels: labels.length > 0 ? labels : ['No Data'],
            datasets: [{
                label: 'Revenue (RM)',
                data: revenues.length > 0 ? revenues : [0],
                borderColor: '#C0392B',
                backgroundColor: 'rgba(192, 57, 43, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#C0392B',
                pointRadius: 4,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { callback: v => 'RM ' + v } },
                x: { ticks: { maxTicksLimit: 7 } }
            }
        }
    });

    // Tier Doughnut Chart
    const tierData = <?= json_encode($tier_dist) ?>;
    const tierLabels = tierData.map(t => t.user_tier.charAt(0).toUpperCase() + t.user_tier.slice(1));
    const tierCounts = tierData.map(t => parseInt(t.count));
    const tierColors = { bronze: '#f97316', silver: '#9ca3af', gold: '#eab308', platinum: '#3b82f6' };
    const tierBgColors = tierData.map(t => tierColors[t.user_tier] || '#gray');

    const tierCtx = document.getElementById('tierChart').getContext('2d');
    const tierChart = new Chart(tierCtx, {
        type: 'doughnut',
        data: {
            labels: tierLabels,
            datasets: [{
                data: tierCounts,
                backgroundColor: tierBgColors,
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 11 } } }
            }
        }
    });

    function downloadPDF() {
        // Convert charts to images
        document.getElementById('chartImageInput').value = revenueChart.toBase64Image();
        document.getElementById('tierChartImageInput').value = tierChart.toBase64Image();
        document.getElementById('pdfForm').submit();
    }
    </script>

</body>
</html>