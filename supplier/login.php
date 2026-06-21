<?php
session_start();
require_once '../includes/db.php';

if (isset($_SESSION['supplier_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE supplier_username = ? AND supplier_status = 'active'");
    $stmt->execute([$username]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($supplier && password_verify($password, $supplier['supplier_password'])) {
        $_SESSION['supplier_id'] = $supplier['supplier_id'];
        $_SESSION['supplier_name'] = $supplier['supplier_name'];
        $_SESSION['role'] = 'supplier';
        header('Location: dashboard.php');
        exit;
    } else {
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
                ❌ <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Username</label>
                    <input type="text" name="username" required
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-blue-400 transition-colors bg-gray-50 focus:bg-white">
                </div>
                <div class="mb-6">
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Password</label>
                    <input type="password" name="password" required
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