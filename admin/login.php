<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

$redirect_raw =
    $_GET['redirect'] ??
    $_POST['redirect'] ??
    'dashboard.php';

if (
    !is_string($redirect_raw) ||
    strlen($redirect_raw) > 255
) {
    $redirect_raw = 'dashboard.php';
}

$redirect_to = safe_redirect_target(
    $redirect_raw,
    'dashboard.php'
);

if (
    !empty($_SESSION['user_id']) &&
    in_array(
        $_SESSION['role'] ?? '',
        ['admin', 'staff'],
        true
    )
) {
    if (($_SESSION['role'] ?? '') === 'admin') {
        redirect_to($redirect_to);
    }

    redirect_to(app_path('staff/dashboard.php'));
}

$error = '';
$identifier_value = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $identifier_raw = $_POST['user_name'] ?? null;
    $password = $_POST['password'] ?? null;

    if (!is_string($identifier_raw)) {
        $error =
            'Please enter a valid Staff/Admin ID or Super Admin identifier.';
    } else {
        $identifier_value = trim($identifier_raw);
    }

    if (
        $error === '' &&
        (
            $identifier_value === '' ||
            strlen($identifier_value) > 100
        )
    ) {
        $error =
            'Enter a valid 7-digit Staff/Admin ID or Super Admin account identifier.';
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
            FROM users
            WHERE user_is_active = 1
            AND (
                (
                    user_staff_id = ?
                    AND (
                        user_role = 'staff'
                        OR (
                            user_role = 'admin'
                            AND user_admin_level =
                                'staff_admin'
                        )
                    )
                )
                OR (
                    (
                        user_name = ?
                        OR user_gmail = ?
                    )
                    AND user_role = 'admin'
                    AND user_admin_level =
                        'senior_admin'
                )
            )
            LIMIT 1
        ");

        $stmt->execute([
            $identifier_value,
            $identifier_value,
            $identifier_value,
        ]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            $user &&
            password_verify(
                $password,
                $user['user_password_hash']
            )
        ) {
            $adminLevel = null;

            if ($user['user_role'] === 'admin') {
                $adminLevel = (string) (
                    $user['user_admin_level']
                    ?? ''
                );

                if (
                    !in_array(
                        $adminLevel,
                        [
                            'staff_admin',
                            'senior_admin',
                        ],
                        true
                    )
                ) {
                    $error =
                        'Administrator account configuration is invalid.';
                }
            }

            if ($error === '') {
                session_regenerate_id(true);

                $_SESSION['user_id'] =
                    (int) $user['user_id'];
                $_SESSION['user_name'] =
                    $user['user_name'];
                $_SESSION['user_first_name'] =
                    $user['user_first_name'];
                $_SESSION['role'] =
                    $user['user_role'];

                unset($_SESSION['admin_level']);

                if ($user['user_role'] === 'admin') {
                    $_SESSION['admin_level'] =
                        $adminLevel;
                }

                $update_login = $pdo->prepare("
                    UPDATE users
                    SET user_last_login = NOW()
                    WHERE user_id = ?
                ");
                $update_login->execute([
                    (int) $user['user_id'],
                ]);

                if ($user['user_role'] === 'admin') {
                    redirect_to($redirect_to);
                }

                redirect_to(
                    app_path('staff/dashboard.php')
                );
            }
        }

        if ($error === '') {
            $error =
                'Invalid credentials or insufficient permissions.';
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

        <!-- Logo -->
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
                ❌ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">
                <?php csrf_field(); ?>
                <input
                    type="hidden"
                    name="redirect"
                    value="<?= htmlspecialchars($redirect_to, ENT_QUOTES, 'UTF-8') ?>"
                >
                <div>
                    <label
                        class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide"
                    >
                        Staff / Admin ID
                    </label>
                    <input
                        type="text"
                        name="user_name"
                        maxlength="100"
                        required
                        autocomplete="username"
                        value="<?= htmlspecialchars(
                            $identifier_value,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white"
                        placeholder="e.g. 2608001"
                    >

                    <p
                        class="mt-1.5 text-[11px] leading-4 text-gray-400"
                    >
                        Staff and Admin accounts sign in using their
                        7-digit ID. Super Admin may use username or email.
                    </p>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Password</label>
                    <input type="password" name="password" maxlength="72" required
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

    <script src="../assets/js/password-toggle.js" defer></script>
</body>
</html>