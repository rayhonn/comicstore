<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$success = '';
$error = '';

// Handle tier config update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {

        // Update tier spending threshold & multiplier
        if ($_POST['action'] === 'update_config') {
            $tier_name = $_POST['tier_name'];
            $min_spending = floatval($_POST['tier_min_spending']);
            $multiplier = floatval($_POST['tier_points_multiplier']);
            $birthday_bonus = intval($_POST['tier_birthday_bonus_points']);
            $shipping_discount = floatval($_POST['tier_shipping_discount']);
            $free_shipping = isset($_POST['tier_free_shipping']) ? 1 : 0;

            $pdo->prepare("UPDATE tier_config SET tier_min_spending = ?, tier_points_multiplier = ?, tier_birthday_bonus_points = ?, tier_shipping_discount = ?, tier_free_shipping = ? WHERE tier_name = ?")
                ->execute([$min_spending, $multiplier, $birthday_bonus, $shipping_discount, $free_shipping, $tier_name]);
            $success = ucfirst($tier_name) . ' tier config updated successfully.';
        }

        // Add new benefit
        if ($_POST['action'] === 'add_benefit') {
            $tier = $_POST['benefit_tier'];
            $text = trim($_POST['benefit_text']);
            if ($text) {
                $max_order = $pdo->prepare("SELECT MAX(benefit_order) FROM tier_benefits WHERE benefit_tier = ?");
                $max_order->execute([$tier]);
                $next_order = ($max_order->fetchColumn() ?? 0) + 1;
                $pdo->prepare("INSERT INTO tier_benefits (benefit_tier, benefit_text, benefit_order) VALUES (?, ?, ?)")
                    ->execute([$tier, $text, $next_order]);
                $success = 'Benefit added successfully.';
            }
        }

        // Delete benefit
        if ($_POST['action'] === 'delete_benefit') {
            $benefit_id = intval($_POST['benefit_id']);
            $pdo->prepare("DELETE FROM tier_benefits WHERE benefit_id = ?")
                ->execute([$benefit_id]);
            $success = 'Benefit removed successfully.';
        }

        // Edit benefit text
        if ($_POST['action'] === 'edit_benefit') {
            $benefit_id = intval($_POST['benefit_id']);
            $text = trim($_POST['benefit_text']);
            if ($text) {
                $pdo->prepare("UPDATE tier_benefits SET benefit_text = ? WHERE benefit_id = ?")
                    ->execute([$text, $benefit_id]);
                $success = 'Benefit updated successfully.';
            }
        }
    }
}

// Load data
$tier_configs = $pdo->query("SELECT * FROM tier_config ORDER BY tier_min_spending ASC")->fetchAll(PDO::FETCH_ASSOC);
$benefit_rows = $pdo->query("SELECT * FROM tier_benefits ORDER BY benefit_tier, benefit_order ASC")->fetchAll(PDO::FETCH_ASSOC);
$benefits = [];
foreach ($benefit_rows as $row) {
    $benefits[$row['benefit_tier']][] = $row;
}

$tier_display = [
    'bronze'   => ['label' => 'Bronze',   'emoji' => '🥉', 'color' => 'text-orange-600', 'bg' => 'bg-orange-50',  'border' => 'border-orange-200'],
    'silver'   => ['label' => 'Silver',   'emoji' => '🥈', 'color' => 'text-gray-500',   'bg' => 'bg-gray-50',    'border' => 'border-gray-200'],
    'gold'     => ['label' => 'Gold',     'emoji' => '🥇', 'color' => 'text-yellow-600', 'bg' => 'bg-yellow-50',  'border' => 'border-yellow-200'],
    'platinum' => ['label' => 'Platinum', 'emoji' => '💎', 'color' => 'text-blue-600',   'bg' => 'bg-blue-50',    'border' => 'border-blue-200'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tier Management - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <!-- Topbar -->
    <nav class="bg-white shadow-sm px-6 py-4 flex justify-between items-center">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="text-xl font-black">MANGA<span class="text-red-600">VAULT</span></a>
            <span class="text-gray-300">|</span>
            <span class="text-gray-600 text-sm font-medium">Tier Management</span>
        </div>
        <a href="dashboard.php" class="text-sm text-gray-500 hover:text-red-600 transition">← Back to Dashboard</a>
    </nav>

    <div class="max-w-6xl mx-auto px-6 py-8">

        <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl mb-6 text-sm">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="mb-8">
            <h1 class="text-2xl font-black text-gray-800">Tier Management</h1>
            <p class="text-gray-500 text-sm mt-1">Manage spending thresholds, points multipliers, and benefits for each tier.</p>
        </div>

        <?php foreach ($tier_configs as $config):
            $key = $config['tier_name'];
            $display = $tier_display[$key];
            $tier_benefits = $benefits[$key] ?? [];
        ?>
        <div class="<?= $display['bg'] ?> <?= $display['border'] ?> border-2 rounded-2xl mb-6 overflow-hidden">

            <!-- Tier Header -->
            <div class="px-6 py-4 border-b <?= $display['border'] ?> flex items-center gap-3">
                <span class="text-3xl"><?= $display['emoji'] ?></span>
                <h2 class="text-xl font-black <?= $display['color'] ?>"><?= $display['label'] ?> Tier</h2>
            </div>

            <div class="p-6 grid grid-cols-1 lg:grid-cols-2 gap-6">

                <!-- Config Form -->
                <div class="bg-white rounded-xl p-5 shadow-sm">
                    <h3 class="font-bold text-gray-700 mb-4 text-sm uppercase tracking-wide">Tier Settings</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_config">
                        <input type="hidden" name="tier_name" value="<?= $key ?>">

                        <div class="space-y-3">
                            <div>
                                <label class="text-xs text-gray-500 font-semibold block mb-1">Min Spending (RM)</label>
                                <?php if ($key === 'bronze'): ?>
                                    <input type="number" value="0.00" disabled class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 text-gray-400 cursor-not-allowed">
                                    <input type="hidden" name="tier_min_spending" value="0">
                                    <p class="text-xs text-gray-400 mt-1">Bronze always starts at RM 0</p>
                                <?php else: ?>
                                    <input type="number" name="tier_min_spending" value="<?= $config['tier_min_spending'] ?>" step="0.01" min="1" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-300">
                                <?php endif; ?>
                            </div>
                            <div>
                                <label class="text-xs text-gray-500 font-semibold block mb-1">Points Multiplier</label>
                                <input type="number" name="tier_points_multiplier" value="<?= $config['tier_points_multiplier'] ?>" step="0.1" min="1" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-300">
                                <p class="text-xs text-gray-400 mt-1">e.g. 1.5 = 1.5x points per RM spent</p>
                            </div>
                            <div>
                                <label class="text-xs text-gray-500 font-semibold block mb-1">Birthday Bonus Points</label>
                                <input type="number" name="tier_birthday_bonus_points" value="<?= $config['tier_birthday_bonus_points'] ?>" min="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-300">
                            </div>
                            <div>
                                <label class="text-xs text-gray-500 font-semibold block mb-1">Shipping Discount (RM)</label>
                                <input type="number" name="tier_shipping_discount" value="<?= $config['tier_shipping_discount'] ?>" step="0.01" min="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-300">
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="checkbox" name="tier_free_shipping" id="free_shipping_<?= $key ?>" <?= $config['tier_free_shipping'] ? 'checked' : '' ?> class="w-4 h-4 text-red-600">
                                <label for="free_shipping_<?= $key ?>" class="text-sm text-gray-600">Free Shipping</label>
                            </div>
                        </div>

                        <button type="submit" class="mt-4 w-full bg-red-600 hover:bg-red-700 text-white font-bold py-2 rounded-lg text-sm transition">
                            Save Settings
                        </button>
                    </form>
                </div>

                <!-- Benefits Management -->
                <div class="bg-white rounded-xl p-5 shadow-sm">
                    <h3 class="font-bold text-gray-700 mb-4 text-sm uppercase tracking-wide">Benefits</h3>

                    <!-- Existing benefits -->
                    <div class="space-y-2 mb-4">
                        <?php if (empty($tier_benefits)): ?>
                            <p class="text-gray-400 text-sm italic">No benefits added yet.</p>
                        <?php endif; ?>
                        <?php foreach ($tier_benefits as $benefit): ?>
                        <div class="flex items-center gap-2 group">
                            <form method="POST" class="flex-1 flex gap-2">
                                <input type="hidden" name="action" value="edit_benefit">
                                <input type="hidden" name="benefit_id" value="<?= $benefit['benefit_id'] ?>">
                                <input type="text" name="benefit_text" value="<?= htmlspecialchars($benefit['benefit_text']) ?>" class="flex-1 border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-red-300">
                                <button type="submit" class="bg-gray-100 hover:bg-green-100 text-gray-600 hover:text-green-700 px-3 py-1.5 rounded-lg text-xs font-semibold transition">Save</button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Remove this benefit?')">
                                <input type="hidden" name="action" value="delete_benefit">
                                <input type="hidden" name="benefit_id" value="<?= $benefit['benefit_id'] ?>">
                                <button type="submit" class="text-red-400 hover:text-red-600 transition text-lg leading-none">×</button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Add new benefit -->
                    <form method="POST" class="flex gap-2">
                        <input type="hidden" name="action" value="add_benefit">
                        <input type="hidden" name="benefit_tier" value="<?= $key ?>">
                        <input type="text" name="benefit_text" placeholder="Add new benefit..." class="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-300">
                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-bold px-4 py-2 rounded-lg text-sm transition">+ Add</button>
                    </form>
                </div>

            </div>
        </div>
        <?php endforeach; ?>

    </div>
</body>
</html>