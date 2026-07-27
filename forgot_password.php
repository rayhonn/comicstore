<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/mail_config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error = '';
$success = '';
$email_value = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $email_raw = $_POST['email'] ?? null;

    if (!is_string($email_raw)) {
        $error =
            'Please enter a valid email address.';
    } else {
        $email_value = trim($email_raw);
    }

    if (
        $error === '' &&
        (
            $email_value === '' ||
            strlen($email_value) > 100 ||
            !filter_var(
                $email_value,
                FILTER_VALIDATE_EMAIL
            )
        )
    ) {
        $error =
            'Please enter a valid email address.';
    }

    if ($error === '') {
        $stmt = $pdo->prepare("
            SELECT
                user_id,
                user_first_name,
                user_gmail
            FROM users
            WHERE user_gmail = ?
            AND user_role = 'customer'
            AND user_is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$email_value]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $token_hash = hash('sha256', $token);
            $expires = gmdate(
                'Y-m-d H:i:s',
                time() + 3600
            );

            $pdo->beginTransaction();

            try {
                $delete_old = $pdo->prepare("
                    DELETE FROM password_resets
                    WHERE reset_email = ?
                ");
                $delete_old->execute([$email_value]);

                $insert_reset = $pdo->prepare("
                    INSERT INTO password_resets (
                        reset_email,
                        reset_token,
                        reset_expires_at
                    )
                    VALUES (?, ?, ?)
                ");
                $insert_reset->execute([
                    $email_value,
                    $token_hash,
                    $expires,
                ]);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Password reset storage failed: ' .
                    $e->getMessage()
                );

                $error =
                    'Unable to process the request. Please try again later.';
            }

            if ($error === '') {
                $reset_link =
                    rtrim(APP_URL, '/') .
                    '/reset_password.php?token=' .
                    urlencode($token);

                $mail = new PHPMailer(true);

                try {
                    $mail->isSMTP();
                    $mail->Host = MAIL_HOST;
                    $mail->SMTPAuth = true;
                    $mail->Username = MAIL_USERNAME;
                    $mail->Password = MAIL_PASSWORD;
                    $mail->SMTPSecure =
                        PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = MAIL_PORT;

                    $mail->setFrom(
                        MAIL_USERNAME,
                        MAIL_FROM_NAME
                    );
                    $mail->addAddress(
                        $email_value,
                        (string) $user['user_first_name']
                    );

                    $safe_name = htmlspecialchars(
                        (string) $user['user_first_name'],
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    $safe_link = htmlspecialchars(
                        $reset_link,
                        ENT_QUOTES,
                        'UTF-8'
                    );

                    $mail->isHTML(true);
                    $mail->Subject =
                        'Reset Your MangaVault Password';
                    $mail->Body = '
                        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
                            <div style="background: #1e2d4a; padding: 30px; text-align: center;">
                                <h1 style="color: white; margin: 0;">Manga<span style="color: #C0392B;">Vault</span></h1>
                            </div>
                            <div style="padding: 40px; background: #f9f9f9;">
                                <h2 style="color: #333;">Reset Your Password</h2>
                                <p style="color: #666;">Hi ' .
                                    $safe_name .
                                ',</p>
                                <p style="color: #666;">We received a request to reset your password. Click the button below to create a new password.</p>
                                <div style="text-align: center; margin: 30px 0;">
                                    <a href="' .
                                        $safe_link .
                                    '" style="background: #C0392B; color: white; padding: 14px 30px; text-decoration: none; border-radius: 6px; font-weight: bold;">Reset Password</a>
                                </div>
                                <p style="color: #999; font-size: 13px;">This link will expire in 1 hour.</p>
                                <p style="color: #999; font-size: 13px;">If you did not request a password reset, please ignore this email.</p>
                            </div>
                            <div style="background: #eee; padding: 20px; text-align: center;">
                                <p style="color: #999; font-size: 12px;">© 2026 MangaVault. All rights reserved.</p>
                            </div>
                        </div>
                    ';

                    $mail->AltBody =
                        'Reset your MangaVault password using this link: ' .
                        $reset_link .
                        ' This link expires in 1 hour.';

                    $mail->send();
                } catch (Exception $e) {
                    error_log(
                        'Password reset email failed: ' .
                        $mail->ErrorInfo
                    );
                }
            }
        }

        if ($error === '') {
            $success =
                'If an active customer account matches that email, a password reset link has been sent.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="flex h-screen overflow-hidden">

    <!-- Left Panel -->
    <div class="hidden md:flex md:w-1/2 lg:w-3/5 bg-[#1e2d4a] flex-col justify-center px-16 relative overflow-hidden">
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
    <div class="w-full md:w-1/2 lg:w-2/5 bg-white flex flex-col justify-center px-8 md:px-14">
        <h2 class="text-2xl font-bold text-gray-900 mb-1">Reset password</h2>
        <p class="text-sm text-gray-400 mb-8">Enter your email and we'll send a reset link.</p>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-lg mb-5">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-green-50 border border-green-200 text-green-600 text-sm px-4 py-3 rounded-lg mb-5">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-5">
            <?php csrf_field(); ?>
            <div>
                <label class="block text-sm font-medium text-gray-600 mb-1">Email Address <span class="text-red-500">*</span></label>
                <input type="email" name="email" maxlength="100"
                       class="w-full px-4 py-3 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500"
                       placeholder="you@example.com"
                       value="<?= htmlspecialchars($email_value, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <button type="submit"
                    class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-lg text-sm transition">
                Send Reset Link
            </button>
        </form>

        <p class="text-center text-sm text-gray-400 mt-6">
            ← <a href="login.php" class="text-red-600 font-medium hover:underline">Back to sign in</a>
        </p>
    </div>

</body>
</html>