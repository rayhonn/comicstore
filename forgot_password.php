<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/mail_config.php';
require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);

    if (empty($email)) {
        $error = "Please enter your email address.";
    } else {
        // Check if email exists
        $stmt = $pdo->prepare("SELECT * FROM users WHERE user_gmail = ? AND user_role = 'customer' AND user_is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Generate token
            $token = bin2hex(random_bytes(32));
            $expires = gmdate('Y-m-d H:i:s', strtotime('+6 hours'));

            // Delete old tokens for this email
            $pdo->prepare("DELETE FROM password_resets WHERE reset_email = ?")->execute([$email]);

            // Insert new token
            $pdo->prepare("INSERT INTO password_resets (reset_email, reset_token, reset_expires_at) VALUES (?, ?, ?)")
                ->execute([$email, $token, $expires]);

            // Send email
            $reset_link = "http://localhost/comicstore/reset_password.php?token=" . $token;

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = MAIL_HOST;
                $mail->SMTPAuth = true;
                $mail->Username = MAIL_USERNAME;
                $mail->Password = MAIL_PASSWORD;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = MAIL_PORT;

                $mail->setFrom(MAIL_USERNAME, MAIL_FROM_NAME);
                $mail->addAddress($email, $user['user_first_name']);

                $mail->isHTML(true);
                $mail->Subject = 'Reset Your MangaVault Password';
                $mail->Body = '
                    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
                        <div style="background: #1e2d4a; padding: 30px; text-align: center;">
                            <h1 style="color: white; margin: 0;">Manga<span style="color: #C0392B;">Vault</span></h1>
                        </div>
                        <div style="padding: 40px; background: #f9f9f9;">
                            <h2 style="color: #333;">Reset Your Password</h2>
                            <p style="color: #666;">Hi ' . $user['user_first_name'] . ',</p>
                            <p style="color: #666;">We received a request to reset your password. Click the button below to create a new password.</p>
                            <div style="text-align: center; margin: 30px 0;">
                                <a href="' . $reset_link . '" style="background: #C0392B; color: white; padding: 14px 30px; text-decoration: none; border-radius: 6px; font-weight: bold;">Reset Password</a>
                            </div>
                            <p style="color: #999; font-size: 13px;">This link will expire in 1 hour.</p>
                            <p style="color: #999; font-size: 13px;">If you did not request a password reset, please ignore this email.</p>
                        </div>
                        <div style="background: #eee; padding: 20px; text-align: center;">
                            <p style="color: #999; font-size: 12px;">© 2026 MangaVault. All rights reserved.</p>
                        </div>
                    </div>
                ';

                $mail->send();
                $success = "Password reset link has been sent to your email.";
            } catch (Exception $e) {
                $error = "Failed to send email. Please try again later.";
            }
        } else {
            // Security: don't reveal if email exists or not
            $success = "If this email is registered, you will receive a reset link shortly.";
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
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-green-50 border border-green-200 text-green-600 text-sm px-4 py-3 rounded-lg mb-5">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-5">
            <div>
                <label class="block text-sm font-medium text-gray-600 mb-1">Email Address <span class="text-red-500">*</span></label>
                <input type="email" name="email"
                       class="w-full px-4 py-3 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500"
                       placeholder="you@example.com"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
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