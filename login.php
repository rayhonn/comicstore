<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';

$redirect_raw =
    $_GET['redirect'] ??
    $_POST['redirect'] ??
    'index.php';

if (
    !is_string($redirect_raw) ||
    strlen($redirect_raw) > 500
) {
    $redirect_raw = 'index.php';
}

$redirect_to = safe_redirect_target(
    $redirect_raw,
    app_path('index.php')
);

if (
    !empty($_SESSION['user_id']) &&
    ($_SESSION['role'] ?? '') === 'customer'
) {
    redirect_to($redirect_to);
}

$error = '';
$identifier_value = '';

$support_email =
    defined('MAIL_USERNAME') &&
    filter_var(
        MAIL_USERNAME,
        FILTER_VALIDATE_EMAIL
    ) !== false
        ? (string) MAIL_USERNAME
        : '';

$support_mailto =
    $support_email !== ''
        ? 'mailto:' .
            $support_email .
            '?' .
            http_build_query(
                [
                    'subject' =>
                        'MangaVault Customer Login Assistance',

                    'body' =>
                        "Hello MangaVault Admin,\n\n" .
                        "I need assistance with my customer account.\n\n" .
                        "Username or email: \n" .
                        "Issue description: ",
                ],
                '',
                '&',
                PHP_QUERY_RFC3986
            )
        : '';

$account_status =
    $_GET['account'] ?? null;

$account_deleted_notice =
    $_SERVER['REQUEST_METHOD'] === 'GET' &&
    is_string($account_status) &&
    $account_status === 'deleted';

$account_closed_notice =
    $_SERVER['REQUEST_METHOD'] === 'GET' &&
    is_string($account_status) &&
    $account_status === 'closed';

$account_deactivated_notice =
    $_SERVER['REQUEST_METHOD'] === 'GET' &&
    is_string($account_status) &&
    $account_status === 'deactivated';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $identifier_raw = $_POST['user_name'] ?? null;
    $password = $_POST['password'] ?? null;

    if (!is_string($identifier_raw)) {
        $error =
            'Please enter a valid username or email.';
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
            'Username or email must be between 1 and 100 characters.';
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
            WHERE (
                user_name = ?
                OR user_gmail = ?
            )
            AND user_role = 'customer'
            LIMIT 1
        ");
        $stmt->execute([
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
            if (
                (int) $user['user_is_active'] !== 1
            ) {
                if (
                    !empty(
                        $user['user_deleted_at']
                    )
                ) {
                    $error =
                        'This account has been deleted. Please contact an administrator if you need assistance.';
                } else {
                    $error =
                        'Your account has been deactivated. Please contact an administrator for assistance.';
                }
            } else {
                regenerate_session();

                $_SESSION['user_id'] =
                    (int) $user['user_id'];
                $_SESSION['user_name'] =
                    $user['user_name'];
                $_SESSION['user_first_name'] =
                    $user['user_first_name'];
                $_SESSION['role'] = 'customer';
                $_SESSION['auth_last_activity_at'] =
                    time();

                $update_login = $pdo->prepare("
                    UPDATE users
                    SET user_last_login = NOW()
                    WHERE user_id = ?
                ");
                $update_login->execute([
                    (int) $user['user_id'],
                ]);

                redirect_to($redirect_to);
            }
        } else {
            $error =
                'Invalid username or password.';
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
    <title>Sign In - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            color-scheme: light;
            --ink: #111827;
            --paper: #f7f4ee;
            --red: #dc2626;
            --red-dark: #991b1b;
            --line: rgba(17, 24, 39, 0.1);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            overflow-x: hidden;
            background:
                radial-gradient(
                    circle at 12% 18%,
                    rgba(220, 38, 38, 0.08),
                    transparent 28rem
                ),
                radial-gradient(
                    circle at 88% 82%,
                    rgba(17, 24, 39, 0.08),
                    transparent 30rem
                ),
                var(--paper);
            color: var(--ink);
        }

        .grain {
            position: fixed;
            inset: 0;
            pointer-events: none;
            opacity: 0.14;
            z-index: 0;
            background-image:
                radial-gradient(
                    rgba(17, 24, 39, 0.22) 0.65px,
                    transparent 0.65px
                );
            background-size: 5px 5px;
            mask-image:
                linear-gradient(
                    to bottom,
                    black,
                    transparent 92%
                );
        }

        .page-shell {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: grid;
            grid-template-rows: auto 1fr auto;
        }

        .brand-mark {
            letter-spacing: 0.08em;
        }

        .login-stage {
            width: min(1080px, calc(100% - 2rem));
            margin: auto;
            padding-top: 7px;
        }

        .login-card {
            position: relative;
            display: grid;
            grid-template-columns: minmax(0, 0.9fr) minmax(320px, 1.1fr);
            min-height: 590px;
            overflow: hidden;
            border: 1px solid rgba(17, 24, 39, 0.12);
            border-radius: 32px;
            background: rgba(255, 255, 255, 0.84);
            box-shadow:
                0 32px 80px rgba(17, 24, 39, 0.14),
                0 2px 0 rgba(255, 255, 255, 0.95) inset;
            backdrop-filter: blur(18px);
        }

        .login-form-panel {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: clamp(2rem, 5vw, 4.5rem);
            background:
                linear-gradient(
                    180deg,
                    rgba(255, 255, 255, 0.95),
                    rgba(255, 255, 255, 0.82)
                );
        }

        .panel-kicker {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            width: fit-content;
            margin-bottom: 1.2rem;
            padding: 0.45rem 0.75rem;
            border: 1px solid rgba(220, 38, 38, 0.16);
            border-radius: 999px;
            background: rgba(254, 242, 242, 0.9);
            color: #b91c1c;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .pulse-dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 999px;
            background: var(--red);
            box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.36);
            animation: pulse-ring 2s infinite;
        }

        @keyframes pulse-ring {
            70% {
                box-shadow: 0 0 0 9px rgba(220, 38, 38, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(220, 38, 38, 0);
            }
        }

        .form-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 0.55rem;
            color: #374151;
            font-size: 0.82rem;
            font-weight: 700;
        }

        .field-shell {
            position: relative;
        }

        .field-icon {
            position: absolute;
            top: 50%;
            left: 1rem;
            z-index: 2;
            display: grid;
            width: 1.25rem;
            height: 1.25rem;
            place-items: center;
            transform: translateY(-50%);
            color: #9ca3af;
            pointer-events: none;
        }

        .auth-input {
            width: 100%;
            min-height: 3.35rem;
            border: 1px solid rgba(17, 24, 39, 0.12);
            border-radius: 16px;
            background: rgba(249, 250, 251, 0.84);
            padding: 0.9rem 1rem 0.9rem 3rem;
            color: #111827;
            font-size: 0.93rem;
            outline: none;
            transition:
                border-color 0.2s ease,
                background 0.2s ease,
                box-shadow 0.2s ease,
                transform 0.2s ease;
        }

        .auth-input::placeholder {
            color: #9ca3af;
        }

        .auth-input:focus {
            border-color: rgba(220, 38, 38, 0.55);
            background: #ffffff;
            box-shadow:
                0 0 0 4px rgba(220, 38, 38, 0.08),
                0 12px 28px rgba(17, 24, 39, 0.06);
            transform: translateY(-1px);
        }

        .submit-button {
            position: relative;
            isolation: isolate;
            display: flex;
            width: 100%;
            min-height: 3.35rem;
            align-items: center;
            justify-content: center;
            gap: 0.7rem;
            overflow: hidden;
            border: 0;
            border-radius: 16px;
            background:
                linear-gradient(
                    135deg,
                    #ef4444 0%,
                    #dc2626 48%,
                    #991b1b 100%
                );
            color: white;
            font-size: 0.92rem;
            font-weight: 800;
            cursor: pointer;
            box-shadow:
                0 16px 32px rgba(220, 38, 38, 0.24);
            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease;
        }

        .submit-button::before {
            content: "";
            position: absolute;
            inset: -40% auto -40% -30%;
            z-index: -1;
            width: 28%;
            transform: skewX(-18deg);
            background:
                linear-gradient(
                    to right,
                    transparent,
                    rgba(255, 255, 255, 0.45),
                    transparent
                );
            transition: left 0.55s ease;
        }

        .submit-button:hover {
            transform: translateY(-2px);
            box-shadow:
                0 20px 38px rgba(220, 38, 38, 0.3);
        }

        .submit-button:hover::before {
            left: 120%;
        }

        .submit-button:active {
            transform: translateY(0);
        }

        .showcase-panel {
            position: relative;
            overflow: hidden;
            border-left: 1px solid rgba(17, 24, 39, 0.1);
            background:
                linear-gradient(
                    145deg,
                    #111827 0%,
                    #1f2937 58%,
                    #0f172a 100%
                );
        }

        .showcase-grid {
            position: absolute;
            inset: 0;
            opacity: 0.12;
            background-image:
                linear-gradient(
                    rgba(255, 255, 255, 0.18) 1px,
                    transparent 1px
                ),
                linear-gradient(
                    90deg,
                    rgba(255, 255, 255, 0.18) 1px,
                    transparent 1px
                );
            background-size: 42px 42px;
        }

        .showcase-copy {
            position: absolute;
            z-index: 3;
            top: clamp(2rem, 5vw, 4rem);
            right: clamp(2rem, 5vw, 4rem);
            left: clamp(2rem, 5vw, 4rem);
            color: white;
        }

        .showcase-copy p {
            max-width: 29rem;
        }

        .shelf {
            position: absolute;
            inset: 10rem 0 0;
            perspective: 1200px;
        }

        .book {
            position: absolute;
            width: 180px;
            height: 250px;
            transform-style: preserve-3d;
            border-radius: 10px 18px 18px 10px;
            box-shadow:
                0 28px 60px rgba(0, 0, 0, 0.32);
            transition:
                transform 0.25s ease,
                filter 0.25s ease;
            will-change: transform;
        }

        .book::before {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: inherit;
            background:
                linear-gradient(
                    90deg,
                    rgba(0, 0, 0, 0.22),
                    transparent 14%,
                    rgba(255, 255, 255, 0.12) 15%,
                    transparent 17%
                );
            pointer-events: none;
        }

        .book::after {
            content: "";
            position: absolute;
            top: 6px;
            right: -8px;
            bottom: 6px;
            width: 13px;
            transform: rotateY(42deg);
            transform-origin: left center;
            border-radius: 0 6px 6px 0;
            background:
                repeating-linear-gradient(
                    to bottom,
                    #f9fafb 0 2px,
                    #d1d5db 2px 3px
                );
        }

        .book-one {
            right: 20%;
            bottom: 6%;
            z-index: 3;
            transform:
                rotate(-4deg)
                rotateY(-7deg);
            background:
                linear-gradient(
                    145deg,
                    #dc2626,
                    #7f1d1d
                );
            animation: book-float-one 5.5s ease-in-out infinite;
        }

        .book-two {
            right: 48%;
            bottom: 2%;
            z-index: 2;
            transform:
                rotate(7deg)
                rotateY(10deg)
                scale(0.9);
            background:
                linear-gradient(
                    145deg,
                    #f3f4f6,
                    #9ca3af
                );
            animation: book-float-two 6.2s ease-in-out infinite;
        }

        .book-three {
            right: 2%;
            bottom: -3%;
            z-index: 1;
            transform:
                rotate(13deg)
                rotateY(-12deg)
                scale(0.82);
            background:
                linear-gradient(
                    145deg,
                    #f59e0b,
                    #92400e
                );
            animation: book-float-three 6.8s ease-in-out infinite;
        }

        @keyframes book-float-one {
            0%, 100% {
                translate: 0 0;
            }
            50% {
                translate: 0 -14px;
            }
        }

        @keyframes book-float-two {
            0%, 100% {
                translate: 0 0;
            }
            50% {
                translate: 0 -10px;
            }
        }

        @keyframes book-float-three {
            0%, 100% {
                translate: 0 0;
            }
            50% {
                translate: 0 -18px;
            }
        }

        .book-content {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 1.25rem;
            color: white;
        }

        .book-two .book-content {
            color: #111827;
        }

        .book-label {
            font-size: 0.66rem;
            font-weight: 900;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            opacity: 0.72;
        }

        .book-title {
            font-size: 1.4rem;
            font-weight: 900;
            line-height: 0.95;
            text-transform: uppercase;
        }

        .bookmark {
            position: absolute;
            z-index: 4;
            top: -4px;
            right: 30px;
            width: 30px;
            height: 78px;
            background: #ef4444;
            clip-path:
                polygon(
                    0 0,
                    100% 0,
                    100% 100%,
                    50% 78%,
                    0 100%
                );
            filter:
                drop-shadow(
                    0 10px 10px rgba(0, 0, 0, 0.2)
                );
            animation: bookmark-sway 4s ease-in-out infinite;
            transform-origin: top center;
        }

        @keyframes bookmark-sway {
            0%, 100% {
                transform: rotate(-2deg);
            }
            50% {
                transform: rotate(3deg);
            }
        }

        .stat-strip {
            position: absolute;
            z-index: 4;
            right: 2.5rem;
            bottom: 2.1rem;
            left: 2.5rem;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 18px;
            background: rgba(15, 23, 42, 0.58);
            backdrop-filter: blur(12px);
        }

        .stat {
            padding: 0.85rem 0.65rem;
            color: white;
            text-align: center;
        }

        .stat + .stat {
            border-left: 1px solid rgba(255, 255, 255, 0.1);
        }

        .stat strong {
            display: block;
            font-size: 0.95rem;
        }

        .stat span {
            color: rgba(255, 255, 255, 0.54);
            font-size: 0.64rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .error-box {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            border: 1px solid rgba(220, 38, 38, 0.18);
            border-radius: 16px;
            background: rgba(254, 242, 242, 0.94);
            padding: 0.9rem 1rem;
            color: #b91c1c;
            font-size: 0.84rem;
            line-height: 1.45;
        }

        .mini-link {
            color: #b91c1c;
            font-weight: 700;
            transition: color 0.2s ease;
        }

        .mini-link:hover {
            color: #7f1d1d;
        }

        .partner-bank-entry {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: fit-content;
            margin: 0.85rem auto 0;
            border: 1px solid rgba(17, 24, 39, 0.09);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.58);
            padding: 0.42rem 0.75rem;
            color: #64748b;
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-decoration: none;
            text-transform: uppercase;
            transition:
                border-color 0.2s ease,
                color 0.2s ease,
                transform 0.2s ease;
        }

        .partner-bank-entry:hover {
            border-color: rgba(15, 76, 129, 0.25);
            color: #0f4c81;
            transform: translateY(-1px);
        }

        @media (max-width: 900px) {
            .login-card {
                grid-template-columns: 1fr;
                min-height: auto;
            }

            .showcase-panel {
                min-height: 280px;
                border-top: 1px solid rgba(17, 24, 39, 0.1);
                border-left: 0;
            }

            .showcase-copy {
                top: 1.5rem;
            }

            .shelf {
                inset: 4rem 0 0;
                transform: scale(0.72);
                transform-origin: right bottom;
            }

            .stat-strip {
                display: none;
            }
        }

        @media (max-width: 640px) {
            .login-stage {
                width: min(100% - 1rem, 36rem);
                padding: 0.5rem 0 1.25rem;
            }

            .login-card {
                border-radius: 24px;
            }

            .login-form-panel {
                padding: 2rem 1.25rem;
            }

            .showcase-panel {
                min-height: 240px;
            }

            .showcase-copy h2 {
                font-size: 1.5rem;
            }

            .shelf {
                right: -4rem;
                transform: scale(0.62);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                animation-duration: 0.001ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.001ms !important;
            }
        }

        @keyframes authPageEnter {
            from {
                opacity: 0;
                transform: translateY(10px) scale(0.995);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        body {
            animation: authPageEnter 0.28s ease-out both;
        }

        body.auth-page-leaving {
            opacity: 0;
            transform: translateY(-8px) scale(0.995);
            transition:
                opacity 0.2s ease,
                transform 0.2s ease;
            pointer-events: none;
        }

        @media (prefers-reduced-motion: reduce) {
            body,
            body.auth-page-leaving {
                opacity: 1;
                transform: none;
                animation: none;
                transition: none;
            }
        }
    </style>
</head>
<body>
    <div class="grain"></div>

    <div class="page-shell">
        <!-- <header
            class="flex items-center justify-between gap-4 px-5 py-5 sm:px-8"
        >
            <a
                href="index.php"
                class="brand-mark text-lg font-black text-gray-900"
            >
                MANGA<span class="text-red-600">VAULT</span>
            </a>

            <a
                href="index.php"
                class="inline-flex items-center gap-2 rounded-full border border-gray-900/10 bg-white/70 px-4 py-2 text-xs font-bold text-gray-700 shadow-sm backdrop-blur transition hover:-translate-y-0.5 hover:bg-white"
            >
                <span aria-hidden="true">←</span>
                Back to Store
            </a>
        </header> -->

        <main class="login-stage">
            <section class="login-card" id="loginCard">
                <div class="login-form-panel">
                    <div class="panel-kicker">
                        <span class="pulse-dot"></span>
                        Reader Access
                    </div>

                    <h1
                        class="text-3xl font-black tracking-tight text-gray-950 sm:text-4xl"
                    >
                        Pick up where
                        <span class="text-red-600">you left off.</span>
                    </h1>

                    <p
                        class="mt-3 max-w-md text-sm leading-6 text-gray-500"
                    >
                        Sign in to continue your collection, wishlist,
                        orders, rewards and digital library.
                    </p>

                    <div class="my-7 h-px bg-gray-900/10"></div>

                    <?php if ($account_deleted_notice): ?>
                    <div
                        class="mb-5 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"
                        role="status"
                    >
                        Your account has been deleted successfully. Please contact an administrator if you need the account restored.
                    </div>
                    <?php endif; ?>

                    <?php if (
                        $account_closed_notice
                    ): ?>
                    <div
                        class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
                        role="alert"
                    >
                        Your account has been closed following approval of your account deletion request. Please contact a Super Admin if you need the account restored.
                    </div>
                    <?php endif; ?>

                    <?php if (
                        $account_deactivated_notice
                    ): ?>
                    <div
                        class="mb-5 rounded-xl border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-700"
                        role="alert"
                    >
                        Your account has been deactivated. Please contact an administrator for assistance.
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                    <div
                        class="error-box"
                        role="alert"
                        aria-live="polite"
                    >
                        <span aria-hidden="true">!</span>
                        <span>
                            <?= htmlspecialchars(
                                $error,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </span>
                    </div>
                    <?php endif; ?>

                    <form
                        method="POST"
                        target="_self"
                        class="space-y-5"
                        id="customerLoginForm"
                    >
                        <?php csrf_field(); ?>

                        <input
                            type="hidden"
                            name="redirect"
                            value="<?= htmlspecialchars(
                                $redirect_to,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                        >

                        <div>
                            <label
                                for="customerIdentifier"
                                class="form-label"
                            >
                                <span>
                                    Username or Email
                                    <span class="text-red-600">*</span>
                                </span>
                            </label>

                            <div class="field-shell">
                                <span
                                    class="field-icon"
                                    aria-hidden="true"
                                >
                                    @
                                </span>

                                <input
                                    id="customerIdentifier"
                                    type="text"
                                    name="user_name"
                                    maxlength="100"
                                    required
                                    autocomplete="username"
                                    class="auth-input"
                                    placeholder="you@example.com"
                                    value="<?= htmlspecialchars(
                                        $identifier_value,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                >
                            </div>
                        </div>

                        <div>
                            <label
                                for="customerPassword"
                                class="form-label"
                            >
                                <span>
                                    Password
                                    <span class="text-red-600">*</span>
                                </span>

                                <span
                                    class="flex items-center gap-3 text-xs"
                                >
                                    <a
                                        href="forgot_password.php"
                                        class="mini-link"
                                    >
                                        Forgot password?
                                    </a>

                                    <?php if (
                                        $support_email !== ''
                                    ): ?>
                                    <span
                                        class="text-gray-300"
                                        aria-hidden="true"
                                    >
                                        ·
                                    </span>

                                    <a
                                        href="<?= htmlspecialchars(
                                            $support_mailto,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        class="mini-link"
                                    >
                                        Contact Admin
                                    </a>
                                    <?php endif; ?>
                                </span>
                            </label>

                            <div class="field-shell">
                                <span
                                    class="field-icon"
                                    aria-hidden="true"
                                >
                                    ◇
                                </span>

                                <input
                                    id="customerPassword"
                                    type="password"
                                    name="password"
                                    maxlength="72"
                                    required
                                    autocomplete="current-password"
                                    class="auth-input"
                                    placeholder="Enter your password"
                                >
                            </div>
                        </div>

                        <button
                            type="submit"
                            class="submit-button"
                            id="signInButton"
                        >
                            <span id="signInButtonText">
                                Sign In
                            </span>
                            <span aria-hidden="true">→</span>
                        </button>
                    </form>

                    <p
                        class="mt-6 text-center text-sm text-gray-500"
                    >
                        New to MangaVault?
                        <a
                            href="register.php"
                            class="mini-link"
                        >
                            Create your account
                        </a>
                    </p>

                    <a
                        href="bank/login.php"
                        class="partner-bank-entry"
                    >
                        <span aria-hidden="true">▣</span>
                        Authorized Partner Bank Access
                        <span aria-hidden="true">→</span>
                    </a>

                </div>

                <aside
                    class="showcase-panel"
                    id="showcasePanel"
                    aria-hidden="true"
                >
                    <div class="showcase-grid"></div>
                    <div class="bookmark"></div>

                    <div class="showcase-copy">
                        <p
                            class="text-xs font-black uppercase tracking-[0.22em] text-red-400"
                        >
                            Your private shelf
                        </p>

                        <h2
                            class="mt-3 text-3xl font-black leading-tight"
                        >
                            Every volume.
                            <br>
                            One vault.
                        </h2>

                        <p
                            class="mt-3 text-sm leading-6 text-white/58"
                        >
                            A personal space for physical manga,
                            e-books, rewards and collection history.
                        </p>
                    </div>

                    <div class="shelf" id="shelf">
                        <article
                            class="book book-one"
                            data-depth="18"
                        >
                            <div class="book-content">
                                <span class="book-label">
                                    Collection
                                </span>
                                <strong class="book-title">
                                    Read.
                                    <br>
                                    Keep.
                                    <br>
                                    Return.
                                </strong>
                                <span class="text-xs font-bold opacity-70">
                                    MangaVault No. 01
                                </span>
                            </div>
                        </article>

                        <article
                            class="book book-two"
                            data-depth="10"
                        >
                            <div class="book-content">
                                <span class="book-label">
                                    Digital
                                </span>
                                <strong class="book-title">
                                    Your
                                    <br>
                                    E-Book
                                    <br>
                                    Shelf
                                </strong>
                                <span class="text-xs font-bold opacity-60">
                                    Instant access
                                </span>
                            </div>
                        </article>

                        <article
                            class="book book-three"
                            data-depth="7"
                        >
                            <div class="book-content">
                                <span class="book-label">
                                    Rewards
                                </span>
                                <strong class="book-title">
                                    Earn.
                                    <br>
                                    Unlock.
                                    <br>
                                    Repeat.
                                </strong>
                                <span class="text-xs font-bold opacity-70">
                                    Member benefits
                                </span>
                            </div>
                        </article>
                    </div>

                    <div class="stat-strip">
                        <div class="stat">
                            <strong>One Account</strong>
                            <span>All purchases</span>
                        </div>

                        <div class="stat">
                            <strong>Secure</strong>
                            <span>Private access</span>
                        </div>

                        <div class="stat">
                            <strong>Always Ready</strong>
                            <span>Your library</span>
                        </div>
                    </div>
                </aside>
            </section>
        </main>

        <!-- <footer
            class="px-5 pb-5 text-center text-[11px] font-medium tracking-wide text-gray-400"
        >
            © 2026 MangaVault. Your collection, secured.
        </footer> -->
    </div>

    <script
        src="assets/js/password-toggle.js"
        defer
    ></script>

    <script>
        (() => {
            'use strict';

            const form =
                document.getElementById('customerLoginForm');
            const button =
                document.getElementById('signInButton');
            const buttonText =
                document.getElementById('signInButtonText');
            const showcase =
                document.getElementById('showcasePanel');
            const books =
                Array.from(document.querySelectorAll('.book'));

            if (form && button && buttonText) {
                form.addEventListener('submit', () => {
                    form.setAttribute(
                        'target',
                        '_self'
                    );

                    button.disabled = true;
                    button.style.opacity = '0.78';
                    button.style.cursor = 'wait';
                    buttonText.textContent = 'Signing you in...';
                });
            }

            if (
                showcase &&
                books.length > 0 &&
                !window.matchMedia(
                    '(prefers-reduced-motion: reduce)'
                ).matches
            ) {
                showcase.addEventListener('pointermove', (event) => {
                    const rect =
                        showcase.getBoundingClientRect();
                    const x =
                        (event.clientX - rect.left) /
                            rect.width -
                        0.5;
                    const y =
                        (event.clientY - rect.top) /
                            rect.height -
                        0.5;

                    books.forEach((book) => {
                        const depth =
                            Number(book.dataset.depth || 8);

                        book.style.marginLeft =
                            `${x * depth}px`;
                        book.style.marginTop =
                            `${y * depth}px`;
                    });
                });

                showcase.addEventListener('pointerleave', () => {
                    books.forEach((book) => {
                        book.style.marginLeft = '0';
                        book.style.marginTop = '0';
                    });
                });
            }
        })();
    </script>
</body>
</html>
