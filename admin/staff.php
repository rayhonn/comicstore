<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $user_name = trim($_POST['user_name']);
        $user_gmail = trim($_POST['user_gmail']);
        $password = $_POST['password'];
        $first = trim($_POST['user_first_name']);
        $last = trim($_POST['user_last_name']);
        $phone = trim($_POST['user_phone']);

        if (empty($user_name) || empty($user_gmail) || empty($password) || empty($first)) {
            $error = "All required fields must be filled.";
        } else {
            $check = $pdo->prepare("SELECT user_id FROM users WHERE user_name = ? OR user_gmail = ?");
            $check->execute([$user_name, $user_gmail]);
            if ($check->rowCount() > 0) {
                $error = "Username or email already exists.";
            } else {
                // Generate staff ID: YYMM + 3 random digits
                $yymm = date('ym');
                do {
                    $random = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
                    $staff_id = $yymm . $random;
                    $check_id = $pdo->prepare("SELECT user_id FROM users WHERE user_staff_id = ?");
                    $check_id->execute([$staff_id]);
                } while ($check_id->rowCount() > 0);

                $pdo->prepare("INSERT INTO users (user_name, user_gmail, user_password_hash, user_first_name, user_last_name, user_phone, user_role, user_staff_id) VALUES (?, ?, ?, ?, ?, ?, 'staff', ?)")
                    ->execute([$user_name, $user_gmail, password_hash($password, PASSWORD_DEFAULT), $first, $last, $phone, $staff_id]);
                $pdo->prepare("INSERT INTO admin_logs (log_admin_id, log_action, log_target_type, log_target_id, log_details) VALUES (?, 'add_staff', 'user', ?, ?)")
                    ->execute([$_SESSION['user_id'], $pdo->lastInsertId(), "Added staff: $user_name"]);
                $success = "Staff account created!";
            }
        }
    } elseif ($_POST['action'] === 'toggle') {
        $pdo->prepare("UPDATE users SET user_is_active = ? WHERE user_id = ? AND user_role = 'staff'")
            ->execute([$_POST['is_active'], $_POST['user_id']]);
        $success = "Staff account updated.";
    } elseif ($_POST['action'] === 'reset_password') {
        $new_password = $_POST['new_password'];
        if (strlen($new_password) < 6) {
            $error = "Password must be at least 6 characters.";
        } else {
            $pdo->prepare("UPDATE users SET user_password_hash = ? WHERE user_id = ? AND user_role = 'staff'")
                ->execute([password_hash($new_password, PASSWORD_DEFAULT), $_POST['user_id']]);
            $success = "Password reset successfully.";
        }
    }
}

$staff = $pdo->query("SELECT * FROM users WHERE user_role = 'staff' ORDER BY user_created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Staff - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
        .modal { display: none; }
        .modal.active { display: flex; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">

        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-black text-gray-800">Manage Staff</h1>
                <p class="text-sm text-gray-400 mt-0.5"><?= count($staff) ?> staff accounts</p>
            </div>
            <button onclick="openAddModal()"
                    class="bg-red-600 hover:bg-red-700 text-white font-semibold px-4 py-2 rounded-xl text-sm transition-colors">
                + Add Staff
            </button>
        </div>

        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-5">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <?php if (empty($staff)): ?>
            <div class="p-12 text-center">
                <div class="text-4xl mb-3">👥</div>
                <p class="text-gray-400 mb-4">No staff accounts yet.</p>
                <button onclick="openAddModal()" class="bg-red-600 hover:bg-red-700 text-white font-semibold px-5 py-2 rounded-xl text-sm transition-colors">
                    Add First Staff
                </button>
            </div>
            <?php else: ?>
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Staff</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Contact</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Joined</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staff as $s): ?>
                    <tr class="border-t border-gray-50 hover:bg-gray-50 transition-colors <?= !$s['user_is_active'] ? 'opacity-60' : '' ?>">
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 bg-[#1e2d4a] rounded-full flex items-center justify-center text-white text-sm font-black flex-shrink-0">
                                    <?= strtoupper(substr($s['user_first_name'] ?? 'S', 0, 1)) ?>
                                </div>
                                <div>
                                    <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($s['user_first_name'] . ' ' . $s['user_last_name']) ?></p>
                                    <p class="text-xs text-gray-400">@<?= htmlspecialchars($s['user_name']) ?></p>
                                    <?php if (!empty($s['user_staff_id'])): ?>
                                    <p class="text-xs font-mono bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded mt-0.5 inline-block">ID: <?= htmlspecialchars($s['user_staff_id']) ?></p>
                                    <?php endif; ?>
                                    
                                </div>
                            </div>
                        </td>
                        <td class="px-5 py-4">
                            <p class="text-sm text-gray-600"><?= htmlspecialchars($s['user_gmail']) ?></p>
                            <p class="text-xs text-gray-400"><?= htmlspecialchars($s['user_phone'] ?? '—') ?></p>
                        </td>
                        <td class="px-5 py-4 text-sm text-gray-500">
                            <?= date('d M Y', strtotime($s['user_created_at'])) ?>
                        </td>
                        <td class="px-5 py-4">
                            <span class="<?= $s['user_is_active'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?> text-xs px-2 py-1 rounded-full font-semibold">
                                <?= $s['user_is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex gap-2">
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="user_id" value="<?= $s['user_id'] ?>">
                                    <input type="hidden" name="is_active" value="<?= $s['user_is_active'] ? 0 : 1 ?>">
                                    <button type="submit"
                                            class="text-xs px-3 py-1.5 border rounded-lg transition-colors <?= $s['user_is_active'] ? 'border-red-200 text-red-600 hover:bg-red-50' : 'border-green-200 text-green-600 hover:bg-green-50' ?>">
                                        <?= $s['user_is_active'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                                <button onclick="openResetModal(<?= $s['user_id'] ?>, '<?= htmlspecialchars($s['user_name']) ?>')"
                                        class="text-xs px-3 py-1.5 border border-blue-200 text-blue-600 rounded-lg hover:bg-blue-50 transition-colors">
                                    🔑 Reset PW
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Staff Modal -->
    <div id="addModal" class="modal fixed inset-0 bg-black/50 z-50 items-center justify-center px-4">
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl max-h-[90vh] overflow-y-auto">
            <div class="p-5 border-b border-gray-100 flex justify-between items-center">
                <h3 class="font-black text-gray-800">Add Staff Account</h3>
                <button onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <form method="POST" class="p-5 space-y-4">
                <input type="hidden" name="action" value="add">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">First Name *</label>
                        <input type="text" name="user_first_name" required
                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Last Name</label>
                        <input type="text" name="user_last_name"
                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Username *</label>
                    <input type="text" name="user_name" required
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Email *</label>
                    <input type="email" name="user_gmail" required
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Phone</label>
                    <input type="text" name="user_phone"
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Password *</label>
                    <input type="password" name="password" required
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                </div>
                <div class="flex gap-3">
                    <button type="button" onclick="closeAddModal()"
                            class="flex-1 py-3 border-2 border-gray-100 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50">Cancel</button>
                    <button type="submit"
                            class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors">Add Staff</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div id="resetModal" class="modal fixed inset-0 bg-black/50 z-50 items-center justify-center px-4">
        <div class="bg-white rounded-2xl w-full max-w-sm shadow-2xl p-6">
            <h3 class="font-black text-gray-800 mb-1">Reset Password</h3>
            <p class="text-sm text-gray-400 mb-4">For: <span id="resetUsername" class="font-semibold text-gray-700"></span></p>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetUserId">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">New Password *</label>
                    <input type="password" name="new_password" required placeholder="Min 6 characters"
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                </div>
                <div class="flex gap-3">
                    <button type="button" onclick="closeResetModal()"
                            class="flex-1 py-3 border-2 border-gray-100 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50">Cancel</button>
                    <button type="submit"
                            class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors">Reset</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openAddModal() { document.getElementById('addModal').classList.add('active'); }
    function closeAddModal() { document.getElementById('addModal').classList.remove('active'); }
    function openResetModal(id, name) {
        document.getElementById('resetUserId').value = id;
        document.getElementById('resetUsername').textContent = name;
        document.getElementById('resetModal').classList.add('active');
    }
    function closeResetModal() { document.getElementById('resetModal').classList.remove('active'); }
    document.getElementById('addModal').addEventListener('click', function(e) { if (e.target === this) closeAddModal(); });
    document.getElementById('resetModal').addEventListener('click', function(e) { if (e.target === this) closeResetModal(); });
    </script>
</body>
</html>