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

        $user = $stmt->fetch(
            PDO::FETCH_ASSOC
        );

        if ($user) {
            try {
                $token = bin2hex(
                    random_bytes(32)
                );

                $token_hash = hash(
                    'sha256',
                    $token
                );

                $expires = gmdate(
                    'Y-m-d H:i:s',
                    time() + 3600
                );

                $pdo->beginTransaction();

                $delete_old = $pdo->prepare("
                    DELETE FROM password_resets
                    WHERE reset_email = ?
                ");
                $delete_old->execute([
                    $email_value,
                ]);

                $insert_reset = $pdo->prepare("
                    INSERT INTO password_resets (
                        reset_email,
                        reset_token_hash,
                        reset_expires_at,
                        reset_used
                    )
                    VALUES (?, ?, ?, 0)
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
                    $mail->Username =
                        MAIL_USERNAME;
                    $mail->Password =
                        MAIL_PASSWORD;
                    $mail->SMTPSecure =
                        PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port =
                        MAIL_PORT;

                    $mail->setFrom(
                        MAIL_USERNAME,
                        MAIL_FROM_NAME
                    );

                    $mail->addAddress(
                        $email_value,
                        (string) $user[
                            'user_first_name'
                        ]
                    );

                    $safe_name = htmlspecialchars(
                        (string) $user[
                            'user_first_name'
                        ],
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
                        <div style="
                            font-family: Arial, sans-serif;
                            max-width: 600px;
                            margin: 0 auto;
                            background: #f5f5f5;
                            padding: 32px;
                        ">
                            <div style="
                                background: #1e2d4a;
                                padding: 28px;
                                border-radius: 14px 14px 0 0;
                                text-align: center;
                            ">
                                <h1 style="
                                    color: white;
                                    margin: 0;
                                    font-size: 26px;
                                ">
                                    Manga<span style="
                                        color: #ef4444;
                                    ">Vault</span>
                                </h1>
                            </div>

                            <div style="
                                background: white;
                                padding: 36px;
                                border-radius: 0 0 14px 14px;
                            ">
                                <h2 style="
                                    color: #172033;
                                    margin-top: 0;
                                ">
                                    Reset your password
                                </h2>

                                <p style="
                                    color: #64748b;
                                ">
                                    Hi ' .
                                    $safe_name .
                                    ',
                                </p>

                                <p style="
                                    color: #64748b;
                                    line-height: 1.7;
                                ">
                                    We received a request to reset
                                    your MangaVault password. Use the
                                    secure button below to create a
                                    new password.
                                </p>

                                <div style="
                                    text-align: center;
                                    margin: 30px 0;
                                ">
                                    <a
                                        href="' .
                                        $safe_link .
                                        '"
                                        style="
                                            display: inline-block;
                                            background: #dc2626;
                                            color: white;
                                            padding: 14px 28px;
                                            text-decoration: none;
                                            border-radius: 10px;
                                            font-weight: bold;
                                        "
                                    >
                                        Reset Password
                                    </a>
                                </div>

                                <div style="
                                    background: #f8fafc;
                                    border-radius: 10px;
                                    padding: 14px 16px;
                                ">
                                    <p style="
                                        color: #64748b;
                                        font-size: 13px;
                                        margin: 0;
                                    ">
                                        This secure link expires in
                                        1 hour and can only be used
                                        once.
                                    </p>
                                </div>

                                <p style="
                                    color: #94a3b8;
                                    font-size: 12px;
                                    margin-top: 24px;
                                ">
                                    If you did not request this
                                    password reset, you can safely
                                    ignore this email.
                                </p>
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
                'If an active customer account matches that email, ' .
                'a password reset link has been sent.';
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

    <title>
        Forgot Password - MangaVault
    </title>

    <script
        src="https://cdn.tailwindcss.com"
    ></script>

    <style>
        body {
            opacity: 0;
            animation:
                pageFade 0.35s ease forwards;
        }

        @keyframes pageFade {
            to {
                opacity: 1;
            }
        }
    </style>
</head>

<body
    class="
        min-h-screen
        bg-[#f5f1ec]
        text-gray-800
    "
>

    <main
        class="
            min-h-screen
            flex
            items-center
            justify-center
            px-4
            py-8
            sm:px-6
            lg:px-8
        "
    >
        <div
            class="
                w-full
                max-w-6xl
                overflow-hidden
                rounded-[28px]
                bg-white
                shadow-[0_24px_70px_rgba(15,23,42,0.12)]
                lg:grid
                lg:grid-cols-[0.9fr_1.1fr]
            "
        >

            <section
                class="
                    relative
                    hidden
                    min-h-[660px]
                    overflow-hidden
                    bg-[#172642]
                    p-12
                    text-white
                    lg:flex
                    lg:flex-col
                    lg:justify-between
                "
            >
                <div
                    class="
                        absolute
                        -right-32
                        -top-32
                        h-80
                        w-80
                        rounded-full
                        bg-white/5
                    "
                ></div>

                <div
                    class="
                        absolute
                        -bottom-36
                        -left-20
                        h-72
                        w-72
                        rounded-full
                        bg-red-500/10
                    "
                ></div>

                <div class="relative z-10">
                    <a
                        href="index.php"
                        class="
                            inline-flex
                            items-center
                            text-xl
                            font-black
                            tracking-tight
                        "
                    >
                        MANGA
                        <span class="text-red-500">
                            VAULT
                        </span>
                    </a>
                </div>

                <div
                    class="
                        relative
                        z-10
                        max-w-md
                    "
                >
                    <div
                        class="
                            mb-7
                            inline-flex
                            h-14
                            w-14
                            items-center
                            justify-center
                            rounded-2xl
                            bg-white/10
                            ring-1
                            ring-white/10
                        "
                    >
                        <svg
                            class="
                                h-7
                                w-7
                                text-red-400
                            "
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="
                                    M12 15v2m-6
                                    4h12a2 2
                                    0 0 0
                                    2-2v-6a2 2
                                    0 0 0-2-2H6a2 2
                                    0 0 0-2
                                    2v6a2 2
                                    0 0 0
                                    2 2zm10-10V7a4 4
                                    0 0 0-8
                                    0v4h8z
                                "
                            ></path>
                        </svg>
                    </div>

                    <p
                        class="
                            mb-3
                            text-xs
                            font-bold
                            uppercase
                            tracking-[0.22em]
                            text-red-400
                        "
                    >
                        Account Recovery
                    </p>

                    <h1
                        class="
                            text-4xl
                            font-black
                            leading-tight
                            tracking-tight
                        "
                    >
                        Recover your
                        account securely.
                    </h1>

                    <p
                        class="
                            mt-5
                            text-sm
                            leading-7
                            text-white/60
                        "
                    >
                        MangaVault uses a secure,
                        time-limited reset link to
                        protect your account and
                        personal information.
                    </p>

                    <div
                        class="
                            mt-10
                            space-y-5
                        "
                    >
                        <div
                            class="
                                flex
                                items-start
                                gap-4
                            "
                        >
                            <span
                                class="
                                    mt-0.5
                                    flex
                                    h-8
                                    w-8
                                    flex-shrink-0
                                    items-center
                                    justify-center
                                    rounded-xl
                                    bg-green-400/10
                                    text-green-300
                                "
                            >
                                ✓
                            </span>

                            <div>
                                <p
                                    class="
                                        text-sm
                                        font-bold
                                    "
                                >
                                    Secure token
                                </p>

                                <p
                                    class="
                                        mt-1
                                        text-xs
                                        leading-5
                                        text-white/50
                                    "
                                >
                                    The reset token is
                                    stored securely as
                                    a hash.
                                </p>
                            </div>
                        </div>

                        <div
                            class="
                                flex
                                items-start
                                gap-4
                            "
                        >
                            <span
                                class="
                                    mt-0.5
                                    flex
                                    h-8
                                    w-8
                                    flex-shrink-0
                                    items-center
                                    justify-center
                                    rounded-xl
                                    bg-blue-400/10
                                    text-blue-300
                                "
                            >
                                ⏱
                            </span>

                            <div>
                                <p
                                    class="
                                        text-sm
                                        font-bold
                                    "
                                >
                                    1-hour expiry
                                </p>

                                <p
                                    class="
                                        mt-1
                                        text-xs
                                        leading-5
                                        text-white/50
                                    "
                                >
                                    Every password
                                    reset link expires
                                    automatically.
                                </p>
                            </div>
                        </div>

                        <div
                            class="
                                flex
                                items-start
                                gap-4
                            "
                        >
                            <span
                                class="
                                    mt-0.5
                                    flex
                                    h-8
                                    w-8
                                    flex-shrink-0
                                    items-center
                                    justify-center
                                    rounded-xl
                                    bg-red-400/10
                                    text-red-300
                                "
                            >
                                ✉
                            </span>

                            <div>
                                <p
                                    class="
                                        text-sm
                                        font-bold
                                    "
                                >
                                    Email verification
                                </p>

                                <p
                                    class="
                                        mt-1
                                        text-xs
                                        leading-5
                                        text-white/50
                                    "
                                >
                                    The recovery link is
                                    sent only to the
                                    registered customer
                                    email.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <p
                    class="
                        relative
                        z-10
                        text-xs
                        text-white/30
                    "
                >
                    © 2026 MangaVault.
                    Secure account access.
                </p>
            </section>

            <section
                class="
                    flex
                    min-h-[620px]
                    items-center
                    px-6
                    py-10
                    sm:px-10
                    lg:min-h-[660px]
                    lg:px-16
                "
            >
                <div
                    class="
                        mx-auto
                        w-full
                        max-w-md
                    "
                >
                    <div
                        class="
                            mb-8
                            lg:hidden
                        "
                    >
                        <a
                            href="index.php"
                            class="
                                text-xl
                                font-black
                                tracking-tight
                                text-[#172642]
                            "
                        >
                            MANGA
                            <span
                                class="
                                    text-red-600
                                "
                            >
                                VAULT
                            </span>
                        </a>
                    </div>

                    <div
                        class="
                            mb-7
                            flex
                            h-12
                            w-12
                            items-center
                            justify-center
                            rounded-2xl
                            bg-red-50
                            text-red-600
                        "
                    >
                        <svg
                            class="h-6 w-6"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="
                                    M3 8l7.89
                                    5.26a2 2
                                    0 0 0
                                    2.22 0L21
                                    8m-16 10h14a2 2
                                    0 0 0
                                    2-2V6a2 2
                                    0 0 0-2-2H5a2 2
                                    0 0 0-2
                                    2v10a2 2
                                    0 0 0
                                    2 2z
                                "
                            ></path>
                        </svg>
                    </div>

                    <p
                        class="
                            mb-2
                            text-xs
                            font-bold
                            uppercase
                            tracking-[0.18em]
                            text-red-600
                        "
                    >
                        Password Recovery
                    </p>

                    <h2
                        class="
                            text-3xl
                            font-black
                            tracking-tight
                            text-[#172033]
                        "
                    >
                        Forgot your password?
                    </h2>

                    <p
                        class="
                            mt-3
                            text-sm
                            leading-6
                            text-gray-500
                        "
                    >
                        Enter the email address linked
                        to your MangaVault customer
                        account. We will send you a
                        secure reset link.
                    </p>

                    <?php if ($error !== ''): ?>
                        <div
                            class="
                                mt-7
                                flex
                                items-start
                                gap-3
                                rounded-2xl
                                border
                                border-red-200
                                bg-red-50
                                px-4
                                py-3.5
                                text-sm
                                text-red-700
                            "
                        >
                            <span
                                class="
                                    mt-0.5
                                    font-black
                                "
                            >
                                !
                            </span>

                            <p>
                                <?= htmlspecialchars(
                                    $error,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if ($success !== ''): ?>
                        <div
                            class="
                                mt-7
                                flex
                                items-start
                                gap-3
                                rounded-2xl
                                border
                                border-green-200
                                bg-green-50
                                px-4
                                py-3.5
                                text-sm
                                text-green-700
                            "
                        >
                            <span
                                class="
                                    mt-0.5
                                    font-black
                                "
                            >
                                ✓
                            </span>

                            <p>
                                <?= htmlspecialchars(
                                    $success,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <form
                        method="POST"
                        class="mt-8"
                    >
                        <?php csrf_field(); ?>

                        <label
                            for="email"
                            class="
                                mb-2
                                block
                                text-sm
                                font-bold
                                text-gray-700
                            "
                        >
                            Account Email
                        </label>

                        <div class="relative">
                            <div
                                class="
                                    pointer-events-none
                                    absolute
                                    inset-y-0
                                    left-0
                                    flex
                                    items-center
                                    pl-4
                                    text-gray-400
                                "
                            >
                                <svg
                                    class="h-5 w-5"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="1.8"
                                        d="
                                            M16 12a4 4
                                            0 1 1-8
                                            0 4 4
                                            0 0 1
                                            8 0zm0
                                            0v1.5a2.5
                                            2.5 0 0
                                            0 5 0V12a9
                                            9 0 1
                                            0-3.48
                                            7.11
                                        "
                                    ></path>
                                </svg>
                            </div>

                            <input
                                type="email"
                                id="email"
                                name="email"
                                maxlength="100"
                                autocomplete="email"
                                placeholder="you@example.com"
                                value="<?= htmlspecialchars(
                                    $email_value,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                required
                                class="
                                    w-full
                                    rounded-2xl
                                    border-2
                                    border-gray-100
                                    bg-gray-50
                                    py-3.5
                                    pl-12
                                    pr-4
                                    text-sm
                                    text-gray-800
                                    outline-none
                                    transition
                                    placeholder:text-gray-400
                                    focus:border-red-400
                                    focus:bg-white
                                    focus:ring-4
                                    focus:ring-red-50
                                "
                            >
                        </div>

                        <button
                            type="submit"
                            class="
                                mt-5
                                inline-flex
                                w-full
                                items-center
                                justify-center
                                gap-2
                                rounded-2xl
                                bg-red-600
                                px-5
                                py-3.5
                                text-sm
                                font-bold
                                text-white
                                shadow-sm
                                transition
                                hover:bg-red-700
                                focus:outline-none
                                focus:ring-4
                                focus:ring-red-100
                            "
                        >
                            Send Reset Link

                            <svg
                                class="h-4 w-4"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="
                                        M14 5l7
                                        7m0 0l-7
                                        7m7-7H3
                                    "
                                ></path>
                            </svg>
                        </button>
                    </form>

                    <div
                        class="
                            mt-6
                            rounded-2xl
                            bg-gray-50
                            px-4
                            py-3.5
                        "
                    >
                        <div
                            class="
                                flex
                                items-start
                                gap-3
                            "
                        >
                            <svg
                                class="
                                    mt-0.5
                                    h-4
                                    w-4
                                    flex-shrink-0
                                    text-gray-400
                                "
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.8"
                                    d="
                                        M13 16h-1v-4h-1m1-4h.01
                                        M21 12a9 9
                                        0 1 1-18 0
                                        9 9 0 0 1
                                        18 0z
                                    "
                                ></path>
                            </svg>

                            <p
                                class="
                                    text-xs
                                    leading-5
                                    text-gray-500
                                "
                            >
                                For your security,
                                MangaVault does not
                                reveal whether an email
                                address is registered.
                            </p>
                        </div>
                    </div>

                    <div
                        class="
                            mt-8
                            border-t
                            border-gray-100
                            pt-6
                            text-center
                        "
                    >
                        <a
                            href="login.php"
                            class="
                                inline-flex
                                items-center
                                gap-2
                                text-sm
                                font-bold
                                text-[#172642]
                                transition
                                hover:text-red-600
                            "
                        >
                            <span>←</span>
                            Back to sign in
                        </a>
                    </div>
                </div>
            </section>
        </div>
    </main>

</body>
</html>