<?php
require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';

$user_id = $_SESSION['user_id'];

// Get ebooks
$collection = $pdo->prepare("
    SELECT DISTINCT p.*, 
    pe.ebook_file_path, pe.ebook_download_limit,
    oi.order_item_id, oi.order_item_download_count,
    o.order_created_at as purchased_at,
    o.order_id
    FROM order_items oi
    JOIN orders o ON oi.order_item_order_id = o.order_id
    JOIN products p ON oi.order_item_product_id = p.product_id
    JOIN product_ebook pe ON p.product_id = pe.ebook_product_id
    WHERE o.order_user_id = ?
    AND oi.order_item_type = 'ebook'
    AND o.order_payment_status = 'confirmed'
    ORDER BY o.order_created_at DESC
");
$collection->execute([$user_id]);
$collection = $collection->fetchAll(PDO::FETCH_ASSOC);

// Get owned physical products
$owned_stmt = $pdo->prepare("
    SELECT DISTINCT p.product_id
    FROM order_items oi
    JOIN orders o ON oi.order_item_order_id = o.order_id
    JOIN products p ON oi.order_item_product_id = p.product_id
    WHERE o.order_user_id = ?
    AND oi.order_item_type = 'physical'
    AND o.order_payment_status = 'confirmed'
");
$owned_stmt->execute([$user_id]);
$owned_ids = array_column($owned_stmt->fetchAll(PDO::FETCH_ASSOC), 'product_id');

// Get all physical series
$series_stmt = $pdo->query("
    SELECT DISTINCT product_series, product_author, product_cover_image
    FROM products
    WHERE product_type = 'physical'
    AND product_is_available = 1
    AND product_series IS NOT NULL
    AND product_series != ''
    ORDER BY product_series
");
$all_series = $series_stmt->fetchAll(PDO::FETCH_ASSOC);

// For each series, get all volumes
$series_data = [];
foreach ($all_series as $s) {
    $vols = $pdo->prepare("
        SELECT product_id, product_title, product_volume_number, product_cover_image, product_price
        FROM products
        WHERE product_series = ? AND product_type = 'physical' AND product_is_available = 1
        ORDER BY product_volume_number ASC
    ");
    $vols->execute([$s['product_series']]);
    $volumes = $vols->fetchAll(PDO::FETCH_ASSOC);

    $owned_count = 0;
    foreach ($volumes as $v) {
        if (in_array($v['product_id'], $owned_ids)) $owned_count++;
    }

    // Only show series where user owns at least 1
    if ($owned_count > 0) {
        $series_data[] = [
            'series' => $s['product_series'],
            'author' => $s['product_author'],
            'cover' => $s['product_cover_image'],
            'volumes' => $volumes,
            'owned' => $owned_count,
            'total' => count($volumes),
        ];
    }
}

// Physical books without series
$no_series_stmt = $pdo->prepare("
    SELECT DISTINCT p.*
    FROM order_items oi
    JOIN orders o ON oi.order_item_order_id = o.order_id
    JOIN products p ON oi.order_item_product_id = p.product_id
    WHERE o.order_user_id = ?
    AND oi.order_item_type = 'physical'
    AND o.order_payment_status = 'confirmed'
    AND (p.product_series IS NULL OR p.product_series = '')
    ORDER BY p.product_title
");
$no_series_stmt->execute([$user_id]);
$no_series = $no_series_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_physical = count($owned_ids);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Collection - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html { scroll-behavior: smooth; }
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
        .line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">
        <p class="text-sm text-gray-400 mb-6">
            <a href="../index.php" class="hover:text-red-600 transition-colors">Home</a>
            <span class="mx-2">›</span>
            <a href="dashboard.php" class="hover:text-red-600 transition-colors">My Account</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">My Collection</span>
        </p>

        <div class="flex gap-8 items-start">
            <?php include '../includes/customer_sidebar.php'; ?>

            <div class="flex-1 min-w-0">

                <!-- Tabs -->
                <div class="flex gap-1 bg-white rounded-2xl shadow-sm p-1 mb-6 w-fit">
                    <button onclick="switchTab('ebooks')" id="tab-ebooks"
                            class="px-5 py-2 rounded-xl text-sm font-semibold transition-colors duration-200 bg-red-600 text-white">
                        📱 E-Books (<?= count($collection) ?>)
                    </button>
                    <button onclick="switchTab('physical')" id="tab-physical"
                            class="px-5 py-2 rounded-xl text-sm font-semibold transition-colors duration-200 text-gray-500 hover:text-red-600">
                        📚 Physical (<?= $total_physical ?>)
                    </button>
                </div>

                <!-- E-Books Tab -->
                <div id="content-ebooks">
                    <?php if (count($collection) === 0): ?>
                        <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                            <div class="text-5xl mb-4">📱</div>
                            <p class="text-gray-500 font-medium mb-1">No e-books yet</p>
                            <p class="text-gray-400 text-sm mb-6">Purchase e-books to build your digital library.</p>
                            <a href="home.php?type=ebook" class="bg-red-600 hover:bg-red-700 text-white font-semibold px-6 py-2.5 rounded-xl text-sm transition-colors inline-block">
                                Browse E-Books
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                            <?php foreach ($collection as $item): ?>
                            <div class="bg-white rounded-2xl shadow-sm overflow-hidden hover:-translate-y-1 hover:shadow-md transition-all duration-300 group">
                                <div class="relative overflow-hidden">
                                    <?php if (!empty($item['product_cover_image'])): ?>
                                        <img src="../assets/images/<?= htmlspecialchars($item['product_cover_image']) ?>"
                                             class="w-full h-48 object-cover group-hover:scale-105 transition-transform duration-300">
                                    <?php else: ?>
                                        <div class="w-full h-48 bg-gray-100 flex items-center justify-center text-2xl font-bold text-gray-400">
                                            <?= strtoupper(substr($item['product_title'], 0, 2)) ?>
                                        </div>
                                    <?php endif; ?>
                                    <span class="absolute top-2 left-2 bg-blue-600 text-white text-xs px-2 py-0.5 rounded-full font-semibold">E-Book</span>
                                </div>
                                <div class="p-3">
                                    <h3 class="font-semibold text-sm text-gray-800 line-clamp-2 mb-1"><?= htmlspecialchars($item['product_title']) ?></h3>
                                    <p class="text-xs text-gray-400 mb-1"><?= htmlspecialchars($item['product_author'] ?? '') ?></p>
                                    <p class="text-xs text-gray-400 mb-3">Purchased <?= date('d M Y', strtotime($item['purchased_at'])) ?></p>
                                    <?php $downloads_left = $item['ebook_download_limit'] - $item['order_item_download_count']; ?>
                                    <div class="mb-3">
                                        <div class="flex justify-between text-xs text-gray-400 mb-1">
                                            <span>Downloads</span>
                                            <span><?= $item['order_item_download_count'] ?>/<?= $item['ebook_download_limit'] ?></span>
                                        </div>
                                        <div class="w-full bg-gray-100 rounded-full h-1.5">
                                            <div class="bg-red-500 h-1.5 rounded-full"
                                                 style="width: <?= min(100, ($item['order_item_download_count'] / $item['ebook_download_limit']) * 100) ?>%"></div>
                                        </div>
                                    </div>
                                    <?php if ($downloads_left > 0): ?>
                                        <a href="download.php?item_id=<?= $item['order_item_id'] ?>"
                                           class="block text-center bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold py-2 rounded-lg transition-colors">
                                            ↓ Download (<?= $downloads_left ?> left)
                                        </a>
                                    <?php else: ?>
                                        <button disabled class="w-full bg-gray-100 text-gray-400 text-xs font-semibold py-2 rounded-lg cursor-not-allowed">
                                            Download Limit Reached
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Physical Tab -->
                <div id="content-physical" class="hidden">
                    <?php if ($total_physical === 0): ?>
                        <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                            <div class="text-5xl mb-4">📚</div>
                            <p class="text-gray-500 font-medium mb-1">No physical books yet</p>
                            <p class="text-gray-400 text-sm mb-6">Purchase physical books to track your collection.</p>
                            <a href="home.php?type=physical" class="bg-red-600 hover:bg-red-700 text-white font-semibold px-6 py-2.5 rounded-xl text-sm transition-colors inline-block">
                                Browse Physical Books
                            </a>
                        </div>
                    <?php else: ?>

                        <!-- Series Tracking -->
                        <?php if (count($series_data) > 0): ?>
                        <div class="mb-8">
                            <h3 class="font-bold text-gray-700 text-sm uppercase tracking-wide mb-4">Series Collection</h3>
                            <div class="space-y-4">
                                <?php foreach ($series_data as $s): ?>
                                <div class="bg-white rounded-2xl shadow-sm p-5">
                                    <div class="flex items-start gap-4 mb-4">
                                        <?php if (!empty($s['cover'])): ?>
                                            <img src="../assets/images/<?= htmlspecialchars($s['cover']) ?>"
                                                 class="w-14 h-20 object-cover rounded-lg flex-shrink-0">
                                        <?php else: ?>
                                            <div class="w-14 h-20 bg-gray-100 rounded-lg flex-shrink-0 flex items-center justify-center text-gray-400 text-xs font-bold text-center px-1">
                                                <?= htmlspecialchars(substr($s['series'], 0, 6)) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="flex-1 min-w-0">
                                            <h4 class="font-bold text-gray-800 mb-0.5"><?= htmlspecialchars($s['series']) ?></h4>
                                            <?php if ($s['author']): ?>
                                            <p class="text-xs text-gray-400 mb-2"><?= htmlspecialchars($s['author']) ?></p>
                                            <?php endif; ?>
                                            <!-- Progress bar -->
                                            <div class="flex items-center gap-2 mb-1">
                                                <div class="flex-1 bg-gray-100 rounded-full h-2">
                                                    <div class="bg-red-500 h-2 rounded-full transition-all"
                                                         style="width: <?= ($s['owned'] / $s['total']) * 100 ?>%"></div>
                                                </div>
                                                <span class="text-xs font-bold text-gray-600 flex-shrink-0"><?= $s['owned'] ?>/<?= $s['total'] ?></span>
                                            </div>
                                            <p class="text-xs text-gray-400">
                                                <?php if ($s['owned'] === $s['total']): ?>
                                                    🎉 Complete collection!
                                                <?php else: ?>
                                                    <?= $s['total'] - $s['owned'] ?> volume(s) missing
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Volume grid -->
                                    <div class="flex flex-wrap gap-2">
                                        <?php foreach ($s['volumes'] as $vol): ?>
                                        <?php $owned = in_array($vol['product_id'], $owned_ids); ?>
                                        <?php if ($owned): ?>
                                            <div class="flex flex-col items-center gap-1">
                                                <?php if (!empty($vol['product_cover_image'])): ?>
                                                    <img src="../assets/images/<?= htmlspecialchars($vol['product_cover_image']) ?>"
                                                         class="w-12 h-16 object-cover rounded-lg ring-2 ring-green-400">
                                                <?php else: ?>
                                                    <div class="w-12 h-16 bg-green-100 rounded-lg flex items-center justify-center text-green-600 font-bold text-xs ring-2 ring-green-400">
                                                        Vol <?= $vol['product_volume_number'] ?? '?' ?>
                                                    </div>
                                                <?php endif; ?>
                                                <span class="text-[10px] text-green-600 font-semibold">Vol.<?= $vol['product_volume_number'] ?? '?' ?></span>
                                            </div>
                                        <?php else: ?>
                                            <a href="product_detail.php?id=<?= $vol['product_id'] ?>" class="flex flex-col items-center gap-1 group">
                                                <?php if (!empty($vol['product_cover_image'])): ?>
                                                    <img src="../assets/images/<?= htmlspecialchars($vol['product_cover_image']) ?>"
                                                         class="w-12 h-16 object-cover rounded-lg opacity-30 group-hover:opacity-60 transition-opacity ring-2 ring-dashed ring-gray-300">
                                                <?php else: ?>
                                                    <div class="w-12 h-16 bg-gray-100 rounded-lg flex items-center justify-center text-gray-300 font-bold text-xs ring-2 ring-dashed ring-gray-300">
                                                        Vol <?= $vol['product_volume_number'] ?? '?' ?>
                                                    </div>
                                                <?php endif; ?>
                                                <span class="text-[10px] text-gray-300 group-hover:text-red-500 transition-colors font-semibold">Vol.<?= $vol['product_volume_number'] ?? '?' ?></span>
                                            </a>
                                        <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- No series physical books -->
                        <?php if (count($no_series) > 0): ?>
                        <div>
                            <h3 class="font-bold text-gray-700 text-sm uppercase tracking-wide mb-4">Standalone Books</h3>
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                                <?php foreach ($no_series as $item): ?>
                                <div class="bg-white rounded-2xl shadow-sm overflow-hidden hover:-translate-y-1 hover:shadow-md transition-all duration-300 group">
                                    <div class="relative overflow-hidden">
                                        <?php if (!empty($item['product_cover_image'])): ?>
                                            <img src="../assets/images/<?= htmlspecialchars($item['product_cover_image']) ?>"
                                                 class="w-full h-48 object-cover group-hover:scale-105 transition-transform duration-300">
                                        <?php else: ?>
                                            <div class="w-full h-48 bg-gray-100 flex items-center justify-center text-2xl font-bold text-gray-400">
                                                <?= strtoupper(substr($item['product_title'], 0, 2)) ?>
                                            </div>
                                        <?php endif; ?>
                                        <span class="absolute top-2 left-2 bg-green-600 text-white text-xs px-2 py-0.5 rounded-full font-semibold">Physical</span>
                                    </div>
                                    <div class="p-3">
                                        <h3 class="font-semibold text-sm text-gray-800 line-clamp-2 mb-1"><?= htmlspecialchars($item['product_title']) ?></h3>
                                        <p class="text-xs text-gray-400"><?= htmlspecialchars($item['product_author'] ?? '') ?></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <script>
    function switchTab(tab) {
        document.getElementById('tab-ebooks').className = 'px-5 py-2 rounded-xl text-sm font-semibold transition-colors duration-200 ' +
            (tab === 'ebooks' ? 'bg-red-600 text-white' : 'text-gray-500 hover:text-red-600');
        document.getElementById('tab-physical').className = 'px-5 py-2 rounded-xl text-sm font-semibold transition-colors duration-200 ' +
            (tab === 'physical' ? 'bg-red-600 text-white' : 'text-gray-500 hover:text-red-600');
        document.getElementById('content-ebooks').classList.toggle('hidden', tab !== 'ebooks');
        document.getElementById('content-physical').classList.toggle('hidden', tab !== 'physical');
    }
    </script>

</body>
</html>