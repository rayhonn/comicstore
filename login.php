<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_name = trim($_POST['user_name']);
    $password = $_POST['password'];

    if (empty($user_name) || empty($password)) {
        $error = "Please enter username and password.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE (user_name = ? OR user_gmail = ?) AND user_is_active = 1");
        $stmt->execute([$user_name, $user_name]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['user_password_hash'])) {
            $pdo->prepare("UPDATE users SET user_last_login = NOW() WHERE user_id = ?")
                ->execute([$user['user_id']]);

            regenerate_session(); // ← session fixation 防护

            $_SESSION['user_id']         = $user['user_id'];
            $_SESSION['user_name']       = $user['user_name'];
            $_SESSION['user_first_name'] = $user['user_first_name'];
            $_SESSION['role']            = $user['user_role'];

            if ($user['user_role'] === 'customer') {
                header('Location: index.php');
            } else {
                destroy_session();
                $error = "Please use the admin portal to login.";
            }
            exit;
        } else {
            $error = "Invalid username or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="flex min-h-screen">

    <!-- Left Panel -->
    <div class="hidden md:flex w-3/5 bg-[#1e2d4a] flex-col justify-center px-16 relative overflow-hidden">
        <div class="absolute -top-24 -right-24 w-80 h-80 bg-white opacity-5 rounded-full"></div>
        <div class="text-white text-xl font-bold mb-12">
            Manga<span class="text-red-600">Vault</span>
        </div>
        <h1 class="text-4xl font-bold text-white leading-tight mb-5">
            Your manga<br>journey <em class="text-red-500 not-italic">starts</em><br>here.
        </h1>
        <p class="text-white/60 text-sm leading-relaxed mb-10">
            Browse thousands of manga volumes and e-books. Track your collection. Never miss a new volume.
        </p>
        <ul class="space-y-3">
            <?php foreach(['Filter by series, genre, author', 'Instant e-book downloads', 'Collection tracker & wishlist', 'Order history & return requests'] as $feature): ?>
            <li class="flex items-center gap-3 text-white/80 text-sm">
                <span class="w-5 h-5 bg-red-600 rounded-full flex items-center justify-center text-white text-xs flex-shrink-0">✓</span>
                <?= $feature ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- Right Panel -->
    <div class="w-full md:w-2/5 bg-white flex flex-col justify-center px-8 md:px-14 overflow-y-auto py-10">
        <h2 class="text-2xl font-bold text-gray-900 mb-1">Welcome back</h2>
        <p class="text-sm text-gray-400 mb-8">
            No account? <a href="register.php" class="text-red-600 font-medium hover:underline">Create one free</a>
        </p>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-lg mb-5">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-5">
            <div>
                <label class="block text-sm font-medium text-gray-600 mb-1">Username or Email <span class="text-red-500">*</span></label>
                <input type="text" name="user_name"
                       class="w-full px-4 py-3 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500"
                       placeholder="you@example.com"
                       value="<?= htmlspecialchars($_POST['user_name'] ?? '') ?>">
            </div>
            <div>
                <div class="flex justify-between items-center mb-1">
                    <label class="text-sm font-medium text-gray-600">Password <span class="text-red-500">*</span></label>
                    <a href="forgot_password.php" class="text-xs text-red-600 hover:underline">Forgot password?</a>
                </div>
                <input type="password" name="password"
                       class="w-full px-4 py-3 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500"
                       placeholder="Your password">
            </div>
            <button type="submit"
                    class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-lg text-sm transition">
                Sign In
            </button>
        </form>

        <p class="text-center text-sm text-gray-400 mt-6">
            ← <a href="register.php" class="text-red-600 font-medium hover:underline">Back to sign up</a>
        </p>
    </div>

</body>
</html>