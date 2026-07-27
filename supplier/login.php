<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

if (
    !empty($_SESSION['supplier_id']) &&
    ($_SESSION['role'] ?? '') === 'supplier'
) {
    redirect_to(app_path('supplier/dashboard.php'));
}

$error = '';
$username_value = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $username_raw = $_POST['username'] ?? null;
    $password = $_POST['password'] ?? null;

    if (!is_string($username_raw)) {
        $error = 'Please enter a valid username.';
    } else {
        $username_value = trim($username_raw);
    }

    if (
        $error === '' &&
        (
            $username_value === '' ||
            strlen($username_value) > 50
        )
    ) {
        $error =
            'Username must be between 1 and 50 characters.';
    } elseif (
        $error === '' &&
        (
            !is_string($password) ||
            $password === '' ||
            strlen($password) > 72
        )
    ) {
        $error =
            'Password must be between 1 and 72 characters.';
    }

    if ($error === '') {
        $stmt = $pdo->prepare("
            SELECT *
            FROM suppliers
            WHERE supplier_username = ?
            AND supplier_status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$username_value]);
        $supplier =
            $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            $supplier &&
            password_verify(
                $password,
                $supplier['supplier_password']
            )
        ) {
            session_regenerate_id(true);

            $_SESSION['supplier_id'] =
                (int) $supplier['supplier_id'];
            $_SESSION['supplier_name'] =
                $supplier['supplier_name'];
            $_SESSION['role'] = 'supplier';

            redirect_to(
                app_path('supplier/dashboard.php')
            );
        }

        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Portal Login - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#0f1b2e] min-h-screen flex items-center justify-center px-6">

    <div class="max-w-md w-full">
        <div class="text-center mb-8">
            <h1 class="text-2xl font-black text-white">MANGA<span class="text-blue-400">VAULT</span></h1>
            <p class="text-blue-300 text-sm mt-1">Supplier Portal</p>
        </div>

        <div class="bg-white rounded-2xl shadow-2xl p-8">
            <h2 class="text-xl font-black text-gray-800 mb-1">Supplier Login</h2>
            <p class="text-sm text-gray-400 mb-6">Access your RFQs, quotations, and orders</p>

            <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-5">
                ❌ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <form method="POST">
                <?php csrf_field(); ?>
                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Username</label>
                    <input type="text" name="username" maxlength="50" required
                           value="<?= htmlspecialchars($username_value, ENT_QUOTES, 'UTF-8') ?>"
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-blue-400 transition-colors bg-gray-50 focus:bg-white">
                </div>
                <div class="mb-6">
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Password</label>
                    <input type="password" name="password" maxlength="72" required
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-blue-400 transition-colors bg-gray-50 focus:bg-white">
                </div>
                <button type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl text-sm transition-colors">
                    Sign In
                </button>
            </form>
        </div>

        <p class="text-center text-blue-300 text-xs mt-6">© 2026 MangaVault. Supplier Portal v1.0</p>
    </div>

</body>
</html>