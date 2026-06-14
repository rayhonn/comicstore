<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$search = trim($_GET['search'] ?? '');
$category_id = $_GET['category_id'] ?? '';
$genre_id = $_GET['genre_id'] ?? '';
$type = $_GET['type'] ?? '';

// Fetch all matching products
$sql = "
    SELECT DISTINCT p.*, c.category_name,
    pp.physical_stock_quantity,
    pe.ebook_download_limit
    FROM products p
    LEFT JOIN categories c ON p.product_category_id = c.category_id
    LEFT JOIN product_physical pp ON p.product_id = pp.physical_product_id
    LEFT JOIN product_ebook pe ON p.product_id = pe.ebook_product_id
    LEFT JOIN product_genres pg ON p.product_id = pg.product_genres_product_id
    WHERE p.product_is_available = 1
";
$params = [];

if ($search) {
    $sql .= " AND (p.product_title LIKE ? OR p.product_series LIKE ? OR p.product_author LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($category_id) {
    $sql .= " AND p.product_category_id = ?";
    $params[] = $category_id;
}
if ($genre_id) {
    $sql .= " AND pg.product_genres_genre_id = ?";
    $params[] = $genre_id;
}
if ($type) {
    $sql .= " AND p.product_type = ?";
    $params[] = $type;
}
$sql .= " ORDER BY p.product_created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$raw_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by series + volume — merge physical & ebook into one card
$grouped = [];
foreach ($raw_products as $p) {
    // Use series+volume as key, or product_id if no series
    $key = $p['product_series']
        ? $p['product_series'] . '||' . ($p['product_volume_number'] ?? '0')
        : 'solo_' . $p['product_id'];

    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'physical' => null,
            'ebook'    => null,
        ];
    }
    $grouped[$key][$p['product_type']] = $p;
}

// Build final product list — one entry per card
$products = [];
foreach ($grouped as $entry) {
    // Prefer physical as the "main" card, fallback to ebook
    $main = $entry['physical'] ?? $entry['ebook'];
    $main['has_physical'] = $entry['physical'] !== null;
    $main['has_ebook']    = $entry['ebook'] !== null;
    $main['physical_id']  = $entry['physical']['product_id'] ?? null;
    $main['ebook_id']     = $entry['ebook']['product_id'] ?? null;
    $main['ebook_price']  = $entry['ebook']['product_price'] ?? null;
    $products[] = $main;
}

$categories = $pdo->query("SELECT * FROM categories ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);
$genres = $pdo->query("SELECT * FROM genres ORDER BY genre_name")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catalog - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <!-- Navbar -->
    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">

        <!-- Search & Filter -->
        <div class="bg-white rounded-xl shadow-sm p-4 mb-6">
            <form method="GET" class="flex flex-wrap gap-3 items-center">
                <input type="text" name="search" placeholder="Search title, series, author..."
                       value="<?= htmlspecialchars($search) ?>"
                       class="flex-1 min-w-48 px-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500">
                <select name="category_id" class="px-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['category_id'] ?>" <?= $category_id == $cat['category_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="genre_id" class="px-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500">
                    <option value="">All Genres</option>
                    <?php foreach ($genres as $genre): ?>
                        <option value="<?= $genre['genre_id'] ?>" <?= $genre_id == $genre['genre_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($genre['genre_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="type" class="px-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500">
                    <option value="">All Types</option>
                    <option value="physical" <?= $type === 'physical' ? 'selected' : '' ?>>Physical</option>
                    <option value="ebook" <?= $type === 'ebook' ? 'selected' : '' ?>>E-Book</option>
                </select>
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-5 py-2 rounded-lg text-sm font-medium transition">Search</button>
                <?php if ($search || $category_id || $genre_id || $type): ?>
                    <a href="home.php" class="text-sm text-gray-500 hover:text-red-600">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Results count -->
        <p class="text-sm text-gray-500 mb-4">Showing <?= count($products) ?> product<?= count($products) !== 1 ? 's' : '' ?></p>

        <!-- Product Grid -->
        <?php if (count($products) === 0): ?>
            <div class="text-center py-16">
                <div class="text-gray-300 text-6xl mb-4">📚</div>
                <p class="text-gray-500">No products found.</p>
                <a href="home.php" class="text-red-600 text-sm hover:underline mt-2 inline-block">Browse all products</a>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-5">
                <?php foreach ($products as $p):
                    $detail_id = $p['physical_id'] ?? $p['ebook_id'];
                ?>
                <div class="bg-white rounded-xl shadow-sm overflow-hidden hover:-translate-y-1 hover:shadow-md transition-all duration-200 flex flex-col">
                    <!-- Cover -->
                    <?php
                    $is_new = strtotime($p['product_created_at']) >= strtotime('-7 days');
                    ?>
                    <a href="#" onclick="event.preventDefault();" class="relative block">
                        <?php if ($p['product_cover_image']): ?>
                            <img src="../assets/images/<?= htmlspecialchars($p['product_cover_image']) ?>"
                                class="w-full h-48 object-cover">
                        <?php else: ?>
                            <div class="w-full h-48 bg-gray-100 flex items-center justify-center text-gray-400 text-3xl font-bold">
                                <?= strtoupper(substr($p['product_title'], 0, 2)) ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($is_new): ?>
                        <div class="absolute top-2 left-2">
                            <span class="bg-red-600 text-white text-xs px-2 py-0.5 font-black tracking-wider uppercase rounded-sm">NEW</span>
                        </div>
                        <?php endif; ?>
                    </a>

                    <!-- Info -->
                    <div class="p-3 flex flex-col flex-1">
                        <h3 class="text-sm font-semibold text-gray-800 leading-tight mb-1 line-clamp-2">
                            <?= htmlspecialchars($p['product_title']) ?>
                        </h3>
                        <p class="text-xs text-gray-400 mb-2">
                            <?= htmlspecialchars($p['product_series'] ?? '') ?>
                            <?= $p['product_volume_number'] ? ' · Vol.' . $p['product_volume_number'] : '' ?>
                        </p>

                        <!-- Stock (physical only) -->
                        <?php if ($p['has_physical']): ?>
                        <p class="text-xs <?= ($p['physical_stock_quantity'] ?? 0) <= 0 ? 'text-red-500' : (($p['physical_stock_quantity'] ?? 0) <= 5 ? 'text-orange-500' : 'text-green-600') ?> mb-2">
                            <?= ($p['physical_stock_quantity'] ?? 0) <= 0 ? 'Out of Stock' : (($p['physical_stock_quantity'] ?? 0) <= 5 ? 'Low Stock' : 'In Stock') ?>
                        </p>
                        <?php endif; ?>

                        <!-- Price -->
                        <div class="mt-auto">
                            <div class="mb-3">
                                <?php if ($p['has_physical']): ?>
                                <p class="text-red-600 font-bold text-sm">RM <?= number_format($p['product_price'], 2) ?>
                                    <span class="text-gray-400 font-normal text-xs">physical</span>
                                </p>
                                <?php endif; ?>
                                <?php if ($p['has_ebook'] && $p['ebook_price']): ?>
                                <p class="text-blue-600 font-bold text-sm">RM <?= number_format($p['ebook_price'], 2) ?>
                                    <span class="text-gray-400 font-normal">e-book</span>
                                </p>
                                <?php endif; ?>
                            </div>
                            <!-- Buttons -->
                            <button onclick='openModal(<?= json_encode([
                                "title"       => $p["product_title"],
                                "series"      => $p["product_series"] ?? "",
                                "volume"      => $p["product_volume_number"] ?? "",
                                "author"      => $p["product_author"] ?? "",
                                "publisher"   => $p["product_publisher"] ?? "",
                                "description" => $p["product_description"] ?? "",
                                "cover"       => $p["product_cover_image"] ?? "",
                                "category"    => $p["category_name"] ?? "",
                                "has_physical"=> $p["has_physical"],
                                "has_ebook"   => $p["has_ebook"],
                                "physical_id" => $p["physical_id"],
                                "ebook_id"    => $p["ebook_id"],
                                "price"       => $p["product_price"],
                                "ebook_price" => $p["ebook_price"],
                                "stock"       => $p["physical_stock_quantity"] ?? 0,
                            ]) ?>)'
                                class="block w-full text-center border border-gray-200 hover:border-red-400 hover:text-red-600 text-gray-600 text-xs font-medium py-2 rounded-lg transition mb-2">
                                View Details
                            </button>
                            <div class="flex gap-2">
                                <?php if ($p['has_physical']): ?>
                                <a href="product_detail.php?id=<?= $p['physical_id'] ?>"
                                    class="flex-1 text-center bg-gray-800 hover:bg-gray-900 text-white text-xs font-semibold py-2 rounded-lg transition">
                                    📦 Physical
                                </a>
                                <?php endif; ?>
                                <?php if ($p['has_ebook']): ?>
                                <a href="product_detail.php?id=<?= $p['ebook_id'] ?>"
                                    class="flex-1 text-center bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold py-2 rounded-lg transition">
                                    📱 E-Book
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- AI Recommendations Section -->
        <?php if (!$search && !$category_id && !$genre_id && !$type): ?>
        <div class="mt-12 mb-6" id="recommendations-section">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-xl font-black text-gray-800">✨ Recommended For You</h2>
                    <p class="text-xs text-gray-400 mt-0.5">Powered by Claude AI</p>
                </div>
            </div>
            <div id="recommendations-grid" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                <?php for ($i = 0; $i < 6; $i++): ?>
                <div class="animate-pulse">
                    <div class="bg-gray-200 rounded-xl h-48 mb-2"></div>
                    <div class="bg-gray-200 rounded h-3 mb-1"></div>
                    <div class="bg-gray-200 rounded h-3 w-2/3"></div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <footer class="bg-[#F5F0EB] text-gray-800 py-12 border-t border-gray-200">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-8 mb-10">
                <div class="col-span-2 md:col-span-1">
                    <h3 class="text-lg font-black mb-4">MANGA<span class="text-red-600">VAULT</span></h3>
                    <p class="text-gray-600 text-sm leading-relaxed">Malaysia's ultimate destination for manga and comic book lovers.</p>
                </div>
                <div>
                    <h4 class="font-bold mb-4 text-sm uppercase tracking-wide text-gray-800">Shop</h4>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li><a href="home.php" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">All Manga</a></li>
                        <li><a href="home.php?type=physical" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">Physical Books</a></li>
                        <li><a href="home.php?type=ebook" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">E-Books</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold mb-4 text-sm uppercase tracking-wide text-gray-800">Help</h4>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li><a href="orders.php" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">My Orders</a></li>
                        <li><a href="profile.php" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">My Account</a></li>
                        <li><a href="faq.php" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">FAQ</a></li>
                        <li><a href="about.php" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">About Us</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold mb-4 text-sm uppercase tracking-wide text-gray-800">Follow Us</h4>
                    <div class="flex gap-3">
                        <a href="#" class="w-9 h-9 bg-gray-200 hover:bg-red-600 hover:text-white rounded-full flex items-center justify-center transition-all text-sm font-bold text-gray-600">f</a>
                        <a href="#" class="w-9 h-9 bg-gray-200 hover:bg-red-600 hover:text-white rounded-full flex items-center justify-center transition-all text-sm font-bold text-gray-600">t</a>
                        <a href="#" class="w-9 h-9 bg-gray-200 hover:bg-red-600 hover:text-white rounded-full flex items-center justify-center transition-all text-sm font-bold text-gray-600">in</a>
                    </div>
                </div>
            </div>
            <div class="border-t border-gray-300 pt-6 text-center text-xs text-gray-500">
                © 2026 MangaVault. All rights reserved.
            </div>
        </div>
    </footer>

    <!-- Product Modal -->
    <div id="productModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" onclick="closeModal()"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4 pointer-events-none">
            <div id="modalBox" class="bg-white rounded-2xl shadow-2xl w-full max-w-sm pointer-events-auto transform scale-95 opacity-0 transition-all duration-300 overflow-hidden">

                <!-- Cover Image (top) -->
                <div class="relative bg-gray-100 flex items-center justify-center" style="height:220px;">
                    <img id="modalCover" src="" alt="" class="h-full w-full object-cover hidden">
                    <div id="modalCoverPlaceholder" class="text-gray-400 font-black text-4xl hidden"></div>
                    <!-- Close button -->
                    <button onclick="closeModal()" class="absolute top-3 right-3 w-8 h-8 rounded-full bg-black/40 hover:bg-black/60 flex items-center justify-center text-white transition-colors">
                        ✕
                    </button>
                    <!-- Badges -->
                    <div id="modalBadges" class="absolute bottom-3 left-3 flex gap-1.5"></div>
                </div>

                <!-- Content -->
                <div class="p-5">
                    <h2 id="modalTitle" class="font-black text-gray-900 text-lg leading-tight mb-0.5"></h2>
                    <p id="modalSeries" class="text-sm text-gray-400 mb-4"></p>

                    <div class="space-y-2.5 text-sm mb-4">
                        <div class="flex gap-3">
                            <span class="text-gray-400 w-20 flex-shrink-0">Author</span>
                            <span id="modalAuthor" class="font-medium text-gray-700"></span>
                        </div>
                        <div class="flex gap-3">
                            <span class="text-gray-400 w-20 flex-shrink-0">Publisher</span>
                            <span id="modalPublisher" class="font-medium text-gray-700"></span>
                        </div>
                        <div class="flex gap-3">
                            <span class="text-gray-400 w-20 flex-shrink-0">Category</span>
                            <span id="modalCategory" class="font-medium text-gray-700"></span>
                        </div>
                    </div>

                    <div class="border-t border-gray-100 pt-4">
                        <p class="text-xs text-gray-400 uppercase tracking-wider font-semibold mb-2">Synopsis</p>
                        <p id="modalDesc" class="text-sm text-gray-600 leading-relaxed"></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function openModal(data) {
        document.getElementById('modalTitle').textContent = data.title;
        document.getElementById('modalSeries').textContent = data.series
            ? data.series + (data.volume ? ' · Vol.' + data.volume : '')
            : '';
        document.getElementById('modalAuthor').textContent    = data.author    || '—';
        document.getElementById('modalPublisher').textContent = data.publisher  || '—';
        document.getElementById('modalCategory').textContent  = data.category   || '—';
        document.getElementById('modalDesc').textContent      = data.description || 'No description available.';

        const cover = document.getElementById('modalCover');
        const placeholder = document.getElementById('modalCoverPlaceholder');
        if (data.cover) {
            cover.src = '../assets/images/' + data.cover;
            cover.classList.remove('hidden');
            placeholder.classList.add('hidden');
        } else {
            cover.classList.add('hidden');
            placeholder.textContent = data.title.substring(0, 2).toUpperCase();
            placeholder.classList.remove('hidden');
        }

        let badgesHtml = '';
        if (data.has_physical) badgesHtml += `<span class="bg-gray-900/80 text-white text-xs px-2 py-0.5 rounded-full font-semibold backdrop-blur-sm">📦 Physical</span>`;
        if (data.has_ebook)    badgesHtml += `<span class="bg-blue-600/80 text-white text-xs px-2 py-0.5 rounded-full font-semibold backdrop-blur-sm">📱 E-Book</span>`;
        document.getElementById('modalBadges').innerHTML = badgesHtml;

        const modal = document.getElementById('productModal');
        const box = document.getElementById('modalBox');
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        setTimeout(() => {
            box.classList.remove('scale-95', 'opacity-0');
            box.classList.add('scale-100', 'opacity-100');
        }, 10);
    }

    function closeModal() {
        const box = document.getElementById('modalBox');
        box.classList.remove('scale-100', 'opacity-100');
        box.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            document.getElementById('productModal').classList.add('hidden');
            document.body.style.overflow = '';
        }, 300);
    }

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeModal();
    });

    // Load AI Recommendations
    <?php if (!$search && !$category_id && !$genre_id && !$type): ?>
    fetch('/comicstore/customer/get_recommendations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'type=home'
    })
    .then(r => r.json())
    .then(data => {
        const grid = document.getElementById('recommendations-grid');
        if (!data.products || data.products.length === 0) {
            document.getElementById('recommendations-section').style.display = 'none';
            return;
        }
        grid.innerHTML = data.products.map(p => {
            const inStock = p.physical_stock_quantity > 0 || p.ebook_product_id;
            const stockBadge = p.product_type === 'physical' 
                ? (p.physical_stock_quantity > 0 
                    ? '<span class="text-xs text-green-600 font-semibold">In Stock</span>'
                    : '<span class="text-xs text-red-500 font-semibold">Out of Stock</span>')
                : '<span class="text-xs text-blue-600 font-semibold">E-Book</span>';
        
            return `
            <a href="/comicstore/customer/product_detail.php?id=${p.product_id}" 
                class="group bg-white rounded-xl shadow-sm overflow-hidden hover:shadow-md transition-all duration-200 hover:-translate-y-1">
                <div class="relative">
                    ${p.product_cover_image 
                        ? `<img src="/comicstore/assets/images/${p.product_cover_image}" 
                                class="w-full h-48 object-cover group-hover:scale-105 transition-transform duration-300">`
                        : `<div class="w-full h-48 bg-gray-100 flex items-center justify-center text-gray-400 text-xs">No Image</div>`
                    }
                </div>
                <div class="p-3">
                    <p class="font-bold text-xs text-gray-800 truncate mb-1">${p.product_title}</p>
                    <p class="text-xs text-gray-400 truncate mb-1">${p.genres || ''}</p>
                    ${stockBadge}
                    <p class="font-black text-red-600 text-sm mt-1">RM ${parseFloat(p.product_price).toFixed(2)}</p>
                </div>
            </a>`;
        }).join('');
    })
    .catch(() => {
        document.getElementById('recommendations-section').style.display = 'none';
    });
    <?php endif; ?>
</script>

</body>
</html>
</body>
</html>