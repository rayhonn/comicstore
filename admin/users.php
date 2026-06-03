<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['action'])) {
    $is_active = $_POST['action'] === 'activate' ? 1 : 0;
    $pdo->prepare("UPDATE users SET user_is_active = ? WHERE user_id = ?")
        ->execute([$is_active, $_POST['user_id']]);
    $pdo->prepare("INSERT INTO admin_logs (log_admin_id, log_action, log_target_type, log_target_id, log_details) VALUES (?, ?, 'user', ?, ?)")
        ->execute([$_SESSION['user_id'], $_POST['action'] . '_user', $_POST['user_id'], "User " . $_POST['action'] . "d"]);
    header('Location: users.php?success=1');
    exit;
}

$search = trim($_GET['search'] ?? '');
$sql = "
    SELECT u.*,
    COUNT(DISTINCT o.order_id) as total_orders,
    COALESCE(SUM(CASE WHEN o.order_payment_status = 'confirmed' THEN o.order_total_amount ELSE 0 END), 0) as total_spent
    FROM users u
    LEFT JOIN orders o ON u.user_id = o.order_user_id
    WHERE u.user_role = 'customer'
";
$params = [];
if ($search) {
    $sql .= " AND (u.user_name LIKE ? OR u.user_first_name LIKE ? OR u.user_last_name LIKE ? OR u.user_gmail LIKE ?)";
    $params = array_fill(0, 4, "%$search%");
}
$sql .= " GROUP BY u.user_id ORDER BY u.user_created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_users = $pdo->query("SELECT COUNT(*) FROM users WHERE user_role = 'customer'")->fetchColumn();
$active_users = $pdo->query("SELECT COUNT(*) FROM users WHERE user_role = 'customer' AND user_is_active = 1")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">

        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-black text-gray-800">Manage Customers</h1>
                <p class="text-sm text-gray-400 mt-0.5"><?= $total_users ?> total · <?= $active_users ?> active</p>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5">✅ User updated.</div>
        <?php endif; ?>

        <!-- Search -->
        <div class="bg-white rounded-2xl shadow-sm p-4 mb-6">
            <form method="GET" class="flex gap-3">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Search name, username or email..."
                       class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors">
                <button type="submit" class="bg-[#1e2d4a] hover:bg-[#162338] text-white px-5 py-2.5 rounded-xl text-sm font-semibold transition-colors">
                    Search
                </button>
                <?php if ($search): ?>
                <a href="users.php" class="text-sm text-gray-400 hover:text-red-600 transition-colors flex items-center">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Users Table -->
        <?php if (count($users) === 0): ?>
        <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
            <div class="text-5xl mb-4">👥</div>
            <p class="text-gray-500 font-medium">No customers found.</p>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Customer</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Contact</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Orders</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Total Spent</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Joined</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr class="border-t border-gray-50 hover:bg-gray-50 transition-colors <?= !$user['user_is_active'] ? 'opacity-60' : '' ?>">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 bg-red-600 rounded-full flex items-center justify-center text-white text-sm font-black flex-shrink-0">
                                    <?= strtoupper(substr($user['user_first_name'] ?? 'U', 0, 1)) ?>
                                </div>
                                <div>
                                    <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($user['user_first_name'] . ' ' . $user['user_last_name']) ?></p>
                                    <p class="text-xs text-gray-400">@<?= htmlspecialchars($user['user_name']) ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <p class="text-sm text-gray-600"><?= htmlspecialchars($user['user_gmail']) ?></p>
                            <p class="text-xs text-gray-400"><?= htmlspecialchars($user['user_phone'] ?? '—') ?></p>
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-semibold text-sm text-gray-800"><?= $user['total_orders'] ?></span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-semibold text-sm text-green-600">RM <?= number_format($user['total_spent'], 2) ?></span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-500">
                            <?= date('d M Y', strtotime($user['user_created_at'])) ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="<?= $user['user_is_active'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?> text-xs px-2 py-1 rounded-full font-semibold">
                                <?= $user['user_is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <form method="POST" class="inline">
                                <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                                <?php if ($user['user_is_active']): ?>
                                <input type="hidden" name="action" value="deactivate">
                                <button type="submit" onclick="return confirm('Deactivate this user?')"
                                        class="text-xs px-3 py-1.5 border border-red-200 text-red-600 rounded-lg hover:bg-red-50 transition-colors">
                                    Deactivate
                                </button>
                                <?php else: ?>
                                <input type="hidden" name="action" value="activate">
                                <button type="submit"
                                        class="text-xs px-3 py-1.5 border border-green-200 text-green-600 rounded-lg hover:bg-green-50 transition-colors">
                                    Activate
                                </button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</body>
</html>