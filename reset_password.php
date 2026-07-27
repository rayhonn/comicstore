<?php

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';

$error = '';
$success = '';
$valid_token = false;

$token_raw =
    $_GET['token'] ??
    $_POST['token'] ??
    '';

if (
    !is_string($token_raw) ||
    strlen($token_raw) !== 64 ||
    !ctype_xdigit($token_raw)
) {
    header('Location: forgot_password.php');
    exit;
}

$token = strtolower($token_raw);
$token_hash = hash('sha256', $token);

$statement = $pdo->prepare("
    SELECT *
    FROM password_resets
    WHERE reset_token_hash = ?
    AND reset_used = 0
    AND reset_expires_at > UTC_TIMESTAMP()
    LIMIT 1
");
$statement->execute([$token_hash]);
$reset = $statement->fetch(PDO::FETCH_ASSOC);

if ($reset) {
    $valid_token = true;
} else {
    $error =
        'This reset link is invalid or has expired. ' .
        'Please request a new one.';
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $valid_token
) {
    csrf_verify();

    $password = $_POST['password'] ?? null;
    $confirm = $_POST['confirm_password'] ?? null;

    if (
        !is_string($password) ||
        !is_string($confirm)
    ) {
        $error = 'Invalid password input.';
    } elseif (
        strlen($password) > 72 ||
        strlen($confirm) > 72
    ) {
        $error =
            'Password cannot exceed 72 characters.';
    } elseif (
        !preg_match(
            '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)' .
            '(?=.*[\W_]).{8,72}$/',
            $password
        )
    ) {
        $error =
            'Password must be 8-72 characters with uppercase, ' .
            'lowercase, number and symbol.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $pdo->beginTransaction();

            $lock_reset = $pdo->prepare("
                SELECT reset_email
                FROM password_resets
                WHERE reset_token_hash = ?
                AND reset_used = 0
                AND reset_expires_at > UTC_TIMESTAMP()
                LIMIT 1
                FOR UPDATE
            ");
            $lock_reset->execute([$token_hash]);
            $locked_reset =
                $lock_reset->fetch(PDO::FETCH_ASSOC);

            if (!$locked_reset) {
                throw new RuntimeException(
                    'This reset link is no longer valid.'
                );
            }

            $update_user = $pdo->prepare("
                UPDATE users
                SET user_password_hash = ?
                WHERE user_gmail = ?
                AND user_role = 'customer'
            ");
            $update_user->execute([
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),
                $locked_reset['reset_email'],
            ]);

            if ($update_user->rowCount() !== 1) {
                throw new RuntimeException(
                    'Unable to reset the account password.'
                );
            }

            $use_token = $pdo->prepare("
                UPDATE password_resets
                SET reset_used = 1
                WHERE reset_token_hash = ?
                AND reset_used = 0
            ");
            $use_token->execute([$token_hash]);

            if ($use_token->rowCount() !== 1) {
                throw new RuntimeException(
                    'This reset link has already been used.'
                );
            }

            $pdo->commit();

            $success =
                'Password reset successfully! ' .
                'You can now login.';
            $valid_token = false;
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            app_error_log(
                'Password reset failed: ' .
                $e->getMessage()
            );

            $error =
                'Unable to reset the password. ' .
                'Please request a new reset link.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Reset Password - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="flex h-screen overflow-hidden">

    <div class="hidden md:flex md:w-1/2 lg:w-3/5 bg-[#1e2d4a] flex-col justify-center px-16 relative overflow-hidden">
        <div class="absolute -top-24 -right-24 w-80 h-80 bg-white opacity-5 rounded-full"></div>

        <div class="text-white text-xl font-bold mb-12">
            Manga<span class="text-red-600">Vault</span>
        </div>

        <h1 class="text-4xl font-bold text-white leading-tight mb-5">
            Your manga<br>
            journey
            <em class="text-red-500 not-italic">
                starts
            </em><br>
            here.
        </h1>

        <p class="text-white/60 text-sm leading-relaxed mb-10">
            Browse thousands of manga volumes and e-books.
            Track your collection. Never miss a new volume.
        </p>

        <ul class="space-y-3">
            <?php
            $features = [
                'Filter by series, genre, author',
                'Instant e-book downloads',
                'Collection tracker & wishlist',
                'Order history & return requests',
            ];

            foreach ($features as $feature):
            ?>
                <li class="flex items-center gap-3 text-white/80 text-sm">
                    <span class="w-5 h-5 bg-red-600 rounded-full flex items-center justify-center text-white text-xs flex-shrink-0">
                        ✓
                    </span>

                    <?= htmlspecialchars(
                        $feature,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="w-full md:w-1/2 lg:w-2/5 bg-white flex flex-col justify-center px-8 md:px-14">

        <?php if ($success !== ''): ?>
            <div class="text-center">
                <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg
                        class="w-8 h-8 text-green-500"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M5 13l4 4L19 7"
                        ></path>
                    </svg>
                </div>

                <h2 class="text-2xl font-bold text-gray-900 mb-2">
                    Password Reset!
                </h2>

                <p class="text-sm text-gray-400 mb-8">
                    Your password has been reset successfully.
                </p>

                <a
                    href="login.php"
                    class="w-full block bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-lg text-sm transition text-center"
                >
                    Back to Login
                </a>
            </div>

        <?php elseif (!$valid_token): ?>
            <div class="text-center">
                <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg
                        class="w-8 h-8 text-red-500"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M6 18L18 6M6 6l12 12"
                        ></path>
                    </svg>
                </div>

                <h2 class="text-2xl font-bold text-gray-900 mb-2">
                    Invalid Link
                </h2>

                <p class="text-sm text-gray-400 mb-8">
                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>

                <a
                    href="forgot_password.php"
                    class="w-full block bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-lg text-sm transition text-center"
                >
                    Request New Link
                </a>
            </div>

        <?php else: ?>
            <h2 class="text-2xl font-bold text-gray-900 mb-1">
                Create new password
            </h2>

            <p class="text-sm text-gray-400 mb-8">
                Use 8-72 characters with uppercase, lowercase,
                number and symbol.
            </p>

            <?php if ($error !== ''): ?>
                <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-lg mb-5">
                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-5">
                <?php csrf_field(); ?>
                <input
                    type="hidden"
                    name="token"
                    value="<?= htmlspecialchars(
                        $token,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">
                        New Password
                        <span class="text-red-500">*</span>
                    </label>

                    <input
                        type="password"
                        name="password"
                        minlength="8"
                        maxlength="72"
                        class="w-full px-4 py-3 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500"
                        placeholder="8-72 characters"
                        required
                    >
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">
                        Confirm Password
                        <span class="text-red-500">*</span>
                    </label>

                    <input
                        type="password"
                        name="confirm_password"
                        minlength="8"
                        maxlength="72"
                        class="w-full px-4 py-3 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500"
                        placeholder="Repeat new password"
                        required
                    >
                </div>

                <button
                    type="submit"
                    class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-lg text-sm transition"
                >
                    Reset Password
                </button>
            </form>

            <p class="text-center text-sm text-gray-400 mt-6">
                ←
                <a
                    href="login.php"
                    class="text-red-600 font-medium hover:underline"
                >
                    Back to sign in
                </a>
            </p>
        <?php endif; ?>
    </div>

</body>
</html>