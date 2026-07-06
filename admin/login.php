<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';

$redirect_to = safe_redirect_target($_GET['redirect'] ?? '', 'dashboard.php');

if (isset($_SESSION['user_id']) && in_array($_SESSION['role'], ['admin', 'staff'])) {
    header('Location: ' . ($_SESSION['role'] === 'admin' ? $redirect_to : '../staff/dashboard.php'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_name = trim($_POST['user_name']);
    $password = $_POST['password'];

    if (empty($user_name) || empty($password)) {
        $error = "Please enter username and password.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE (user_name = ? OR user_gmail = ? OR user_staff_id = ?) AND user_is_active = 1 AND user_role IN ('admin', 'staff')");
        $stmt->execute([$user_name, $user_name, $user_name]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['user_password_hash'])) {
            $pdo->prepare("UPDATE users SET user_last_login = NOW() WHERE user_id = ?")
                ->execute([$user['user_id']]);

            regenerate_session(); // ← session fixation 防护

            $_SESSION['user_id']         = $user['user_id'];
            $_SESSION['user_name']       = $user['user_name'];
            $_SESSION['user_first_name'] = $user['user_first_name'];
            $_SESSION['role']            = $user['user_role'];
            $_SESSION['admin_level']     = $user['user_admin_level'] ?? 'senior_admin';

            if ($user['user_role'] === 'admin') {
                header('Location: ' . $redirect_to);
            } else {
                header('Location: ../staff/dashboard.php');
            }
            exit;
        } else {
            $error = "Invalid credentials or insufficient permissions.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-[#1e2d4a] min-h-screen flex items-center justify-center px-6">
    <div class="w-full max-w-md">

        <div class="text-center mb-8">
            <h1 class="text-3xl font-black text-white tracking-wide">
                MANGA<span class="text-red-400">VAULT</span>
            </h1>
            <p class="text-white/40 text-sm mt-1">Staff & Admin Portal</p>
        </div>

        <div class="bg-white rounded-2xl shadow-2xl p-8">
            <h2 class="text-xl font-black text-gray-800 mb-1">Sign In</h2>
            <p class="text-sm text-gray-400 mb-6">Access the management portal</p>

            <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-5">
                ❌ <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="?redirect=<?= urlencode($redirect_to) ?>" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Username or Email</label>
                    <input type="text" name="user_name" required
                           value="<?= htmlspecialchars($_POST['user_name'] ?? '') ?>"
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white"
                           placeholder="Staff ID, username or email">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Password</label>
                    <input type="password" name="password" required
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                </div>
                <button type="submit"
                        class="w-full bg-[#1e2d4a] hover:bg-[#162338] text-white font-bold py-3 rounded-xl text-sm transition-colors">
                    Sign In to Portal
                </button>
            </form>

            <div class="mt-6 pt-5 border-t border-gray-100 text-center">
                <a href="../index.php" class="text-xs text-gray-400 hover:text-red-600 transition-colors">
                    ← Back to MangaVault Store
                </a>
            </div>
        </div>

        <p class="text-center text-white/20 text-xs mt-6">MangaVault Management System © 2026</p>
    </div>
</body>
</html>