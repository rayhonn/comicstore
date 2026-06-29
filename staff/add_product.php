<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header('Location: ../admin/login.php');
    exit;
}
require_once '../includes/db.php';
require_once '../includes/upload_helper.php';
require_once '../includes/notifications.php';

$error = '';
$categories = $pdo->query("SELECT * FROM categories ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);
$genres = $pdo->query("SELECT * FROM genres ORDER BY genre_name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
    $title = trim($_POST['product_title']);
    $series = trim($_POST['product_series']);
    $volume = $_POST['product_volume_number'] ?: null;
    $author = trim($_POST['product_author']);
    $publisher = trim($_POST['product_publisher']);
    $isbn = trim($_POST['product_isbn']);
    $description = trim($_POST['product_description']);
    $price = $_POST['product_price'];
    $category_id = $_POST['product_category_id'] ?: null;
    $type = $_POST['product_type'];
    $is_available = isset($_POST['product_is_available']) ? 1 : 0;
    $selected_genres = $_POST['genres'] ?? [];

    if (empty($title) || empty($price)) {
        $error = "Title and price are required.";
    } else {
        $cover_image = '';
        if (!empty($_FILES['product_cover_image']['name'])) {
            $upload_dir = '../assets/images/';
            $new_cover_image = uploadProductImage($_FILES['product_cover_image'], $upload_dir);

            if ($new_cover_image !== '') {
                $cover_image = $new_cover_image;
            }
        }

        $stmt = $pdo->prepare("INSERT INTO products (product_title, product_series, product_volume_number, product_author, product_publisher, product_isbn, product_description, product_price, product_cover_image, product_category_id, product_type, product_is_available) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $series, $volume, $author, $publisher, $isbn, $description, $price, $cover_image, $category_id, $type, $is_available]);
        $product_id = $pdo->lastInsertId();

        if ($type === 'physical') {
            $stock = (int)$_POST['physical_stock_quantity'];
            $threshold = (int)$_POST['physical_low_stock_threshold'];
            $weight = $_POST['physical_weight'] ?: null;
            $dimensions = trim($_POST['physical_dimensions']);
            $pdo->prepare("INSERT INTO product_physical (physical_product_id, physical_stock_quantity, physical_low_stock_threshold, physical_weight, physical_dimensions) VALUES (?, ?, ?, ?, ?)")
                ->execute([$product_id, $stock, $threshold, $weight, $dimensions]);
        } else {
            $download_limit = (int)$_POST['ebook_download_limit'];
            $file_format = $_POST['ebook_file_format'];
            $ebook_file = '';
            if (!empty($_FILES['ebook_file']['name'])) {
                $ebook_dir = '../assets/ebooks/';
                $new_ebook_file = uploadEbookFile($_FILES['ebook_file'], $ebook_dir);

                if ($new_ebook_file !== '') {
                    $ebook_file = $new_ebook_file;
                }
            }
            $file_size = 0;
            if ($ebook_file && file_exists('../assets/ebooks/' . $ebook_file)) {
                $file_size = round(filesize('../assets/ebooks/' . $ebook_file) / 1048576, 2);
            }
            $pdo->prepare("INSERT INTO product_ebook (ebook_product_id, ebook_file_path, ebook_file_format, ebook_file_size_mb, ebook_download_limit) VALUES (?, ?, ?, ?, ?)")
                ->execute([$product_id, $ebook_file, $file_format, $file_size, $download_limit]);
        }

        foreach ($selected_genres as $genre_id) {
            $pdo->prepare("INSERT INTO product_genres (product_genres_product_id, product_genres_genre_id) VALUES (?, ?)")
                ->execute([$product_id, $genre_id]);
        }

        $pdo->prepare("INSERT INTO admin_logs (log_admin_id, log_action, log_target_type, log_target_id, log_details) VALUES (?, 'add_product', 'product', ?, ?)")
            ->execute([$_SESSION['user_id'], $product_id, "Added product: $title"]);

        // Notify all customers about new product
        if ($is_available) {
            $type_label = $type === 'ebook' ? 'E-Book' : 'Physical';
            $notif_msg = "New $type_label added: \"$title\"" . ($series ? " ($series" . ($volume ? " Vol.$volume" : "") . ")" : "") . " — RM " . number_format($price, 2) . ". Check it out now!";
            sendNotificationAll($pdo, '🆕 New Release!', $notif_msg, 'promo');
        }

        header('Location: products.php?success=1');
        exit;
    }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Product - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .tab-btn { transition: all 0.2s ease; }
        .tab-btn.active { background: #C0392B; color: white; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">

    <?php include '../includes/staff_navbar.php'; ?>

    <div class="max-w-5xl mx-auto px-6 py-8">
        <div class="flex items-center gap-4 mb-6">
            <a href="products.php" class="w-9 h-9 bg-white rounded-xl shadow-sm flex items-center justify-center text-gray-500 hover:text-red-600 transition-colors">←</a>
            <div>
                <h1 class="text-2xl font-black text-gray-800">Add New Product</h1>
                <p class="text-sm text-gray-400">Fill in the details below to add a new manga title</p>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-6">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="productForm">

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- LEFT: Main Info -->
                <div class="lg:col-span-2 space-y-5">

                    <!-- Basic Info -->
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h3 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
                            <span class="w-6 h-6 bg-red-100 rounded-lg flex items-center justify-center text-red-600 text-xs font-black">1</span>
                            Basic Information
                        </h3>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Title *</label>
                                <input type="text" name="product_title" required
                                       placeholder="e.g. Naruto"
                                       class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Series</label>
                                    <input type="text" name="product_series"
                                           placeholder="e.g. Naruto"
                                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Volume No.</label>
                                    <input type="number" name="product_volume_number" min="1"
                                           placeholder="e.g. 1"
                                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Author</label>
                                    <input type="text" name="product_author"
                                           placeholder="Author name"
                                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Publisher</label>
                                    <input type="text" name="product_publisher"
                                           placeholder="Publisher name"
                                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">ISBN</label>
                                    <input type="text" name="product_isbn"
                                           placeholder="ISBN number"
                                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Price (RM) *</label>
                                    <input type="number" name="product_price" step="0.01" min="0" required
                                           placeholder="0.00"
                                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Description</label>
                                <textarea name="product_description" rows="3"
                                          placeholder="Brief description of the manga..."
                                          class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white resize-none"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Category & Genres -->
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h3 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
                            <span class="w-6 h-6 bg-red-100 rounded-lg flex items-center justify-center text-red-600 text-xs font-black">2</span>
                            Category & Genres
                        </h3>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Category</label>
                                <select name="product_category_id"
                                        class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                    <option value="">-- Select Category --</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Genres</label>
                                <div class="flex flex-wrap gap-2">
                                    <?php foreach ($genres as $genre): ?>
                                    <label class="flex items-center gap-1.5 px-3 py-1.5 border-2 border-gray-100 rounded-xl text-xs font-medium text-gray-600 cursor-pointer hover:border-red-300 hover:bg-red-50 transition-colors has-[:checked]:border-red-500 has-[:checked]:bg-red-50 has-[:checked]:text-red-600">
                                        <input type="checkbox" name="genres[]" value="<?= $genre['genre_id'] ?>" class="accent-red-600 w-3 h-3">
                                        <?= htmlspecialchars($genre['genre_name']) ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Product Type -->
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h3 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
                            <span class="w-6 h-6 bg-red-100 rounded-lg flex items-center justify-center text-red-600 text-xs font-black">3</span>
                            Product Type & Details
                        </h3>

                        <!-- Type Toggle -->
                        <div class="flex gap-2 mb-6 bg-gray-50 p-1 rounded-xl w-fit">
                            <button type="button" id="tab-physical" onclick="switchType('physical')"
                                    class="tab-btn active px-5 py-2 rounded-lg text-sm font-semibold transition-all">
                                📦 Physical
                            </button>
                            <button type="button" id="tab-ebook" onclick="switchType('ebook')"
                                    class="tab-btn px-5 py-2 rounded-lg text-sm font-semibold text-gray-500 transition-all">
                                📱 E-Book
                            </button>
                        </div>
                        <input type="hidden" name="product_type" id="product_type" value="physical">

                        <!-- Physical Fields -->
                        <div id="fields-physical" class="space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Stock Quantity</label>
                                    <input type="number" name="physical_stock_quantity" min="0" value="0"
                                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Low Stock Alert</label>
                                    <input type="number" name="physical_low_stock_threshold" min="0" value="5"
                                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Weight (kg)</label>
                                    <input type="number" name="physical_weight" step="0.01" min="0"
                                           placeholder="e.g. 0.25"
                                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Dimensions</label>
                                    <input type="text" name="physical_dimensions"
                                           placeholder="e.g. 18x12x2cm"
                                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                </div>
                            </div>
                        </div>

                        <!-- E-Book Fields -->
                        <div id="fields-ebook" class="space-y-4 hidden">
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">E-Book File (PDF/EPUB)</label>
                                <input type="file" name="ebook_file" accept=".pdf,.epub"
                                       class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm bg-gray-50 file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-red-50 file:text-red-600 hover:file:bg-red-100">
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">File Format</label>
                                    <select name="ebook_file_format"
                                            class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                        <option value="PDF">PDF</option>
                                        <option value="EPUB">EPUB</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Download Limit</label>
                                    <input type="number" name="ebook_download_limit" min="1" value="3"
                                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: Cover + Settings -->
                <div class="space-y-5">

                    <!-- Cover Image -->
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                            <span class="w-6 h-6 bg-red-100 rounded-lg flex items-center justify-center text-red-600 text-xs font-black">4</span>
                            Cover Image
                        </h3>
                        <div id="coverPreviewDiv" class="w-full h-48 bg-gray-50 border-2 border-dashed border-gray-200 rounded-xl flex items-center justify-center mb-3 overflow-hidden">
                            <div id="coverPlaceholder" class="text-center">
                                <div class="text-3xl mb-2">🖼️</div>
                                <p class="text-xs text-gray-400">No image selected</p>
                            </div>
                            <img id="coverPreviewImg" src="" class="w-full h-full object-cover hidden">
                        </div>
                        <input type="file" name="product_cover_image" accept="image/*"
                               onchange="previewCover(this)"
                               class="w-full text-xs text-gray-500 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-red-50 file:text-red-600 hover:file:bg-red-100">
                    </div>

                    <!-- Visibility -->
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                            <span class="w-6 h-6 bg-red-100 rounded-lg flex items-center justify-center text-red-600 text-xs font-black">5</span>
                            Visibility
                        </h3>
                        <label class="flex items-center gap-3 cursor-pointer">
                            <div class="relative">
                                <input type="checkbox" name="product_is_available" id="availableToggle" checked class="sr-only">
                                <div class="toggle-bg w-11 h-6 bg-gray-200 rounded-full transition-colors duration-200"></div>
                                <div class="toggle-dot absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform duration-200"></div>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-gray-700">Show to customers</p>
                                <p class="text-xs text-gray-400">Product will be visible in the store</p>
                            </div>
                        </label>
                        <div class="mt-3 bg-yellow-50 border border-yellow-200 rounded-xl p-3">
                            <p class="text-xs text-yellow-700">💡 If available, all customers will be notified about this new release.</p>
                        </div>
                    </div>

                    <!-- Submit -->
                    <button type="submit"
                            class="w-full bg-red-600 hover:bg-red-700 text-white font-black py-4 rounded-2xl text-sm transition-all duration-200 hover:scale-[1.01] shadow-lg shadow-red-100">
                        ✅ Add Product
                    </button>
                    <a href="products.php"
                       class="block text-center text-sm text-gray-400 hover:text-red-600 transition-colors">
                        Cancel
                    </a>
                </div>
            </div>
        </form>
    </div>

    <style>
    #availableToggle:checked ~ .toggle-bg { background: #C0392B; }
    #availableToggle:checked ~ .toggle-dot { transform: translateX(20px); }
    </style>

    <script>
    function switchType(type) {
        document.getElementById('product_type').value = type;
        document.getElementById('fields-physical').classList.toggle('hidden', type !== 'physical');
        document.getElementById('fields-ebook').classList.toggle('hidden', type !== 'ebook');
        document.getElementById('tab-physical').classList.toggle('active', type === 'physical');
        document.getElementById('tab-ebook').classList.toggle('active', type === 'ebook');
        document.getElementById('tab-physical').classList.toggle('text-gray-500', type !== 'physical');
        document.getElementById('tab-ebook').classList.toggle('text-gray-500', type !== 'ebook');
    }

    function previewCover(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('coverPreviewImg').src = e.target.result;
                document.getElementById('coverPreviewImg').classList.remove('hidden');
                document.getElementById('coverPlaceholder').classList.add('hidden');
            };
            reader.readAsDataURL(input.files[0]);
        }
    }
    </script>

</body>
</html>