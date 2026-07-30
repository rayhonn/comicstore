<?php

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/notifications.php';

$error = '';
$success = '';

function normalizeRegistrationText(
    mixed $value,
    string $label,
    int $maxLength,
    bool $required = false
): string {
    if (!is_string($value)) {
        throw new RuntimeException('Invalid ' . $label . '.');
    }

    $value = trim($value);
    $length = function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);

    if ($required && $value === '') {
        throw new RuntimeException($label . ' is required.');
    }

    if ($length > $maxLength) {
        throw new RuntimeException(
            $label . ' cannot exceed ' .
            $maxLength .
            ' characters.'
        );
    }

    return $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    try {
        $userName = normalizeRegistrationText(
            $_POST['user_name'] ?? '',
            'Username',
            50,
            true
        );
        $email = normalizeRegistrationText(
            $_POST['user_gmail'] ?? '',
            'Email',
            100,
            true
        );
        $firstName = normalizeRegistrationText(
            $_POST['user_first_name'] ?? '',
            'First name',
            50,
            true
        );
        $lastName = normalizeRegistrationText(
            $_POST['user_last_name'] ?? '',
            'Last name',
            50
        );
        $phone = normalizeRegistrationText(
            $_POST['user_phone'] ?? '',
            'Phone number',
            20
        );
        $dobInput = normalizeRegistrationText(
            $_POST['user_dob'] ?? '',
            'Date of birth',
            10,
            true
        );

        $password = $_POST['password'] ?? null;
        $confirmPassword =
            $_POST['confirm_password'] ?? null;

        if (
            !is_string($password) ||
            !is_string($confirmPassword)
        ) {
            throw new RuntimeException(
                'Invalid password input.'
            );
        }

        if (
            strlen($password) > 72 ||
            strlen($confirmPassword) > 72
        ) {
            throw new RuntimeException(
                'Password cannot exceed 72 characters.'
            );
        }

        if ($password !== $confirmPassword) {
            throw new RuntimeException(
                'Passwords do not match.'
            );
        }

        if (
            !preg_match(
                '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)' .
                '(?=.*[\W_]).{8,72}$/',
                $password
            )
        ) {
            throw new RuntimeException(
                'Password must be 8-72 characters ' .
                'with uppercase, lowercase, number ' .
                'and symbol.'
            );
        }

        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new RuntimeException(
                'Please enter a valid email address.'
            );
        }

        if (
            $phone !== '' &&
            !preg_match('/^01[0-9]{8,9}$/', $phone)
        ) {
            throw new RuntimeException(
                'Please enter a valid Malaysian phone number.'
            );
        }

        $dob = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $dobInput
        );
        $dobErrors = DateTimeImmutable::getLastErrors();

        if (
            !$dob ||
            (
                is_array($dobErrors) &&
                (
                    $dobErrors['warning_count'] > 0 ||
                    $dobErrors['error_count'] > 0
                )
            ) ||
            $dob->format('Y-m-d') !== $dobInput
        ) {
            throw new RuntimeException(
                'Please enter a valid date of birth.'
            );
        }

        $today = new DateTimeImmutable('today');

        if ($dob > $today) {
            throw new RuntimeException(
                'Date of birth cannot be in the future.'
            );
        }

        if ($today->diff($dob)->y < 13) {
            throw new RuntimeException(
                'You must be at least 13 years old to register.'
            );
        }

        $check = $pdo->prepare(
            'SELECT user_id
             FROM users
             WHERE user_name = ?
             OR user_gmail = ?'
        );
        $check->execute([$userName, $email]);

        if ($check->fetchColumn()) {
            throw new RuntimeException(
                'Username or email already exists.'
            );
        }

        $insert = $pdo->prepare(
            "INSERT INTO users (
                user_name,
                user_gmail,
                user_password_hash,
                user_first_name,
                user_last_name,
                user_phone,
                user_dob,
                user_role
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'customer')"
        );
        $insert->execute([
            $userName,
            $email,
            password_hash(
                $password,
                PASSWORD_DEFAULT
            ),
            $firstName,
            $lastName,
            $phone,
            $dobInput,
        ]);

        $newUserId = (int) $pdo->lastInsertId();

        sendNotification(
            $pdo,
            $newUserId,
            'Welcome to MangaVault! 🎉',
            'Hi ' . $firstName .
            '! Your account has been created successfully. ' .
            'Start exploring thousands of manga titles now!',
            'system'
        );
        sendNotification(
            $pdo,
            $newUserId,
            '🎟️ Welcome Gift — 10% OFF!',
            'Use code WELCOME10 for 10% off your first order ' .
            '(min RM20, max RM15 discount). Happy shopping!',
            'promo'
        );
        sendNotification(
            $pdo,
            $newUserId,
            '🎁 New Member Special — 20% OFF!',
            'Exclusive for new members! Use code NEWUSER20 ' .
            'for 20% off (min RM50, max RM20 discount). ' .
            'One-time use only!',
            'promo'
        );
        sendNotification(
            $pdo,
            $newUserId,
            '🎊 New Member Gift — 50% OFF!',
            'Welcome gift! Use code NEWMEMBER50 for 50% off ' .
            'your first order (min RM30, max RM25 discount). ' .
            'Valid for 1 month only!',
            'promo'
        );

        $welcomeVouchers = $pdo->prepare(
            "SELECT voucher_id
             FROM vouchers
             WHERE voucher_code IN (
                'WELCOME10',
                'NEWUSER20',
                'NEWMEMBER50'
             )
             AND voucher_is_active = 1"
        );
        $welcomeVouchers->execute();

        $expiresAt = date(
            'Y-m-d H:i:s',
            strtotime('+1 month')
        );

        $claimVoucher = $pdo->prepare(
            'INSERT IGNORE INTO user_vouchers (
                uv_user_id,
                uv_voucher_id,
                uv_expires_at
             ) VALUES (?, ?, ?)'
        );

        foreach (
            $welcomeVouchers->fetchAll(PDO::FETCH_ASSOC)
            as $voucher
        ) {
            $claimVoucher->execute([
                $newUserId,
                (int) $voucher['voucher_id'],
                $expiresAt,
            ]);
        }

        $success =
            'Registration successful! You can now login.';
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
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
    <title>Create Account - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        :root {
            color-scheme: light;
            --paper: #f2eee6;
            --paper-deep: #e7dfd2;
            --ink: #172033;
            --muted: #6f7480;
            --red: #cf2f38;
            --red-dark: #8f1f28;
            --gold: #b78a3a;
            --line: rgba(23, 32, 51, 0.13);
        }

        * {
            box-sizing: border-box;
        }

        html {
            min-height: 100%;
        }

        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100svh;
            color: var(--ink);
            background:
                linear-gradient(
                    rgba(23, 32, 51, 0.035) 1px,
                    transparent 1px
                ),
                linear-gradient(
                    90deg,
                    rgba(23, 32, 51, 0.035) 1px,
                    transparent 1px
                ),
                radial-gradient(
                    circle at 8% 5%,
                    rgba(207, 47, 56, 0.12),
                    transparent 23rem
                ),
                radial-gradient(
                    circle at 95% 92%,
                    rgba(183, 138, 58, 0.12),
                    transparent 25rem
                ),
                var(--paper);
            background-size:
                28px 28px,
                28px 28px,
                auto,
                auto,
                auto;
        }

        button,
        input {
            font: inherit;
        }

        .registration-shell {
            position: relative;
            width: min(1380px, calc(100% - 2rem));
            min-height: 100vh;
            min-height: 100svh;
            margin: 0 auto;
            /* padding: 1rem 0; */
            display: grid;
            place-items: center;
        }

        .registration-card {
            position: relative;
            width: 100%;
            min-height: min(780px, calc(100svh - 2rem));
            display: grid;
            grid-template-columns:
                minmax(310px, 0.72fr)
                minmax(620px, 1.28fr);
            overflow: hidden;
            border: 1px solid rgba(23, 32, 51, 0.15);
            border-radius: 30px;
            background: rgba(255, 253, 248, 0.9);
            box-shadow:
                0 36px 90px rgba(23, 32, 51, 0.16),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(14px);
        }

        .identity-panel {
            position: relative;
            overflow: hidden;
            padding: clamp(2rem, 4vw, 3.6rem);
            color: white;
            background:
                linear-gradient(
                    152deg,
                    #30151b 0%,
                    #7a2028 48%,
                    #161d2c 100%
                );
        }

        .identity-panel::before {
            content: "";
            position: absolute;
            inset: 0;
            opacity: 0.13;
            background:
                repeating-linear-gradient(
                    -24deg,
                    rgba(255, 255, 255, 0.22) 0 1px,
                    transparent 1px 13px
                );
        }

        .identity-panel::after {
            content: "MEMBER";
            position: absolute;
            right: -1.6rem;
            bottom: -1.8rem;
            color: rgba(255, 255, 255, 0.06);
            font-size: clamp(5rem, 11vw, 10rem);
            font-weight: 1000;
            letter-spacing: -0.08em;
            transform: rotate(-90deg);
            transform-origin: right bottom;
            pointer-events: none;
        }

        .identity-content {
            position: relative;
            z-index: 2;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .wordmark {
            width: fit-content;
            color: white;
            font-size: 1.05rem;
            font-weight: 1000;
            letter-spacing: 0.12em;
        }

        .wordmark span {
            color: #ff646c;
        }

        .dossier-label {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            width: fit-content;
            margin-top: clamp(2.2rem, 6vh, 4.6rem);
            padding: 0.45rem 0.7rem;
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            color: rgba(255, 255, 255, 0.72);
            font-size: 0.68rem;
            font-weight: 900;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }

        .dossier-label::before {
            content: "";
            width: 0.45rem;
            height: 0.45rem;
            border-radius: 50%;
            background: #ff646c;
            box-shadow:
                0 0 0 6px rgba(255, 100, 108, 0.1);
        }

        .identity-title {
            margin-top: 1.1rem;
            max-width: 28rem;
            font-size: clamp(2.5rem, 5vw, 4.7rem);
            font-weight: 1000;
            line-height: 0.92;
            letter-spacing: -0.055em;
        }

        .identity-title em {
            color: #ff777e;
            font-style: normal;
        }

        .identity-copy {
            max-width: 27rem;
            margin-top: 1.3rem;
            color: rgba(255, 255, 255, 0.62);
            font-size: 0.88rem;
            line-height: 1.75;
        }

        .member-pass {
            position: relative;
            width: min(100%, 410px);
            margin-top: auto;
            padding: 1.25rem;
            border: 1px solid rgba(255, 255, 255, 0.17);
            border-radius: 22px;
            background:
                linear-gradient(
                    145deg,
                    rgba(255, 255, 255, 0.15),
                    rgba(255, 255, 255, 0.05)
                );
            box-shadow:
                0 24px 50px rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(14px);
            transform-style: preserve-3d;
            transition: transform 0.18s ease;
        }

        .pass-topline {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            color: rgba(255, 255, 255, 0.58);
            font-size: 0.62rem;
            font-weight: 900;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }

        .pass-avatar {
            width: 3.8rem;
            height: 3.8rem;
            margin-top: 1.2rem;
            display: grid;
            place-items: center;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.09);
            color: rgba(255, 255, 255, 0.78);
            font-size: 1.2rem;
            font-weight: 1000;
        }

        .pass-name {
            margin-top: 0.85rem;
            color: white;
            font-size: 1.1rem;
            font-weight: 900;
            letter-spacing: 0.02em;
        }

        .pass-subline {
            margin-top: 0.15rem;
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.7rem;
        }

        .pass-code {
            position: absolute;
            right: 1.2rem;
            bottom: 1.15rem;
            display: grid;
            grid-template-columns: repeat(5, 4px);
            gap: 3px;
            opacity: 0.68;
        }

        .pass-code span {
            height: 28px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.8);
        }

        .pass-code span:nth-child(2),
        .pass-code span:nth-child(5) {
            height: 18px;
            align-self: end;
        }

        .application-panel {
            position: relative;
            padding:
                clamp(1.4rem, 3vw, 2.5rem)
                clamp(1.4rem, 4vw, 3.75rem);
            background:
                linear-gradient(
                    180deg,
                    rgba(255, 253, 248, 0.96),
                    rgba(250, 247, 240, 0.93)
                );
        }

        .application-panel::before {
            content: "";
            position: absolute;
            top: 1.1rem;
            right: 1.1rem;
            bottom: 1.1rem;
            left: 1.1rem;
            border: 1px dashed rgba(23, 32, 51, 0.1);
            border-radius: 22px;
            pointer-events: none;
        }

        .application-content {
            position: relative;
            z-index: 2;
            width: min(100%, 860px);
            margin: 0 auto;
        }

        .application-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1.5rem;
        }

        .eyebrow {
            color: var(--red);
            font-size: 0.68rem;
            font-weight: 1000;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }

        .application-title {
            margin-top: 0.35rem;
            color: var(--ink);
            font-size: clamp(1.8rem, 3vw, 2.65rem);
            font-weight: 1000;
            line-height: 1;
            letter-spacing: -0.04em;
        }

        .application-description {
            margin-top: 0.65rem;
            color: var(--muted);
            font-size: 0.8rem;
            line-height: 1.55;
        }

        .approval-stamp {
            flex: 0 0 auto;
            width: 5.2rem;
            height: 5.2rem;
            display: grid;
            place-items: center;
            border: 2px solid rgba(207, 47, 56, 0.6);
            border-radius: 50%;
            color: rgba(207, 47, 56, 0.78);
            font-size: 0.61rem;
            font-weight: 1000;
            letter-spacing: 0.1em;
            line-height: 1.2;
            text-align: center;
            text-transform: uppercase;
            transform: rotate(8deg);
            animation: stamp-breathe 3.2s ease-in-out infinite;
        }

        @keyframes stamp-breathe {
            0%,
            100% {
                transform: rotate(8deg) scale(1);
                opacity: 0.7;
            }
            50% {
                transform: rotate(5deg) scale(1.04);
                opacity: 0.92;
            }
        }

        .notice {
            display: flex;
            align-items: flex-start;
            gap: 0.7rem;
            margin-top: 1rem;
            padding: 0.8rem 0.9rem;
            border-radius: 14px;
            font-size: 0.78rem;
            line-height: 1.45;
        }

        .notice-error {
            border: 1px solid rgba(207, 47, 56, 0.18);
            background: rgba(254, 242, 242, 0.92);
            color: #a6222b;
        }

        .notice-success {
            border: 1px solid rgba(21, 128, 61, 0.18);
            background: rgba(240, 253, 244, 0.92);
            color: #15703a;
        }

        .registration-form {
            margin-top: 1.2rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.85rem 1rem;
        }

        .field-group {
            min-width: 0;
        }

        .field-span-2 {
            grid-column: span 2;
        }

        .form-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.4rem;
            color: #3c4352;
            font-size: 0.72rem;
            font-weight: 850;
            letter-spacing: 0.015em;
        }

        .field-note {
            color: #9b6e22;
            font-size: 0.62rem;
            font-weight: 800;
        }

        .field-wrap {
            position: relative;
        }

        .field-prefix {
            position: absolute;
            z-index: 3;
            top: 50%;
            left: 0.85rem;
            transform: translateY(-50%);
            color: #9ca0aa;
            font-size: 0.78rem;
            font-weight: 1000;
            pointer-events: none;
        }

        .form-input {
            width: 100%;
            min-height: 2.85rem;
            border: 1px solid rgba(23, 32, 51, 0.13);
            border-radius: 13px;
            background: rgba(255, 255, 255, 0.82);
            padding: 0.72rem 0.85rem 0.72rem 2.6rem;
            color: var(--ink);
            font-size: 0.8rem;
            outline: none;
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.95);
            transition:
                border-color 0.2s ease,
                box-shadow 0.2s ease,
                transform 0.2s ease,
                background 0.2s ease;
        }

        .form-input::placeholder {
            color: #a5a8af;
        }

        .form-input:focus {
            border-color: rgba(207, 47, 56, 0.58);
            background: white;
            box-shadow:
                0 0 0 4px rgba(207, 47, 56, 0.08),
                0 10px 24px rgba(23, 32, 51, 0.06);
            transform: translateY(-1px);
        }

        input[type="date"].form-input {
            color-scheme: light;
        }

        .helper-text {
            margin-top: 0.3rem;
            color: #9296a0;
            font-size: 0.62rem;
            line-height: 1.35;
        }

        .password-checks {
            grid-column: span 2;
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 0.45rem;
            margin-top: -0.15rem;
            padding: 0.65rem;
            border: 1px solid rgba(23, 32, 51, 0.08);
            border-radius: 13px;
            background: rgba(255, 255, 255, 0.58);
        }

        .password-check-item {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            min-width: 0;
            color: #9ca0aa;
            font-size: 0.61rem;
            font-weight: 750;
            line-height: 1.25;
        }

        .check-icon {
            flex: 0 0 auto;
            width: 1rem;
            height: 1rem;
            display: grid;
            place-items: center;
            border: 1px solid rgba(23, 32, 51, 0.12);
            border-radius: 50%;
            font-size: 0.55rem;
            font-weight: 1000;
        }

        .password-check-item.is-valid {
            color: #15803d;
        }

        .password-check-item.is-valid .check-icon {
            border-color: rgba(21, 128, 61, 0.24);
            background: rgba(220, 252, 231, 0.9);
        }

        .confirm-message {
            margin-top: 0.28rem;
            font-size: 0.63rem;
            font-weight: 800;
        }

        .form-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-top: 1.05rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(23, 32, 51, 0.09);
        }

        .signin-copy {
            color: #7f8490;
            font-size: 0.74rem;
        }

        .signin-link {
            color: var(--red);
            font-weight: 900;
        }

        .submit-button {
            position: relative;
            isolation: isolate;
            min-width: 13rem;
            min-height: 3rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            overflow: hidden;
            border: 0;
            border-radius: 14px;
            background:
                linear-gradient(
                    135deg,
                    #e1434b 0%,
                    #c92d36 50%,
                    #92202a 100%
                );
            color: white;
            font-size: 0.78rem;
            font-weight: 950;
            letter-spacing: 0.02em;
            cursor: pointer;
            box-shadow:
                0 14px 28px rgba(207, 47, 56, 0.22);
            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease;
        }

        .submit-button::before {
            content: "";
            position: absolute;
            inset: -30% auto -30% -35%;
            z-index: -1;
            width: 24%;
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
                0 18px 34px rgba(207, 47, 56, 0.29);
        }

        .submit-button:hover::before {
            left: 125%;
        }

        .submit-button:disabled {
            opacity: 0.72;
            cursor: wait;
            transform: none;
        }

        .copyright {
            margin-top: 0.85rem;
            color: #a0a3aa;
            font-size: 0.61rem;
            font-weight: 650;
            text-align: center;
            letter-spacing: 0.035em;
        }

        @media (max-width: 1080px) {
            .registration-card {
                grid-template-columns:
                    minmax(260px, 0.58fr)
                    minmax(570px, 1.42fr);
            }

            .identity-panel {
                padding: 2rem;
            }

            .identity-title {
                font-size: 3rem;
            }

            .identity-copy {
                font-size: 0.78rem;
            }
        }

        @media (max-width: 880px) {
            body {
                overflow-y: auto;
            }

            .registration-shell {
                width: min(720px, calc(100% - 1rem));
                padding: 0.5rem 0;
            }

            .registration-card {
                min-height: auto;
                grid-template-columns: 1fr;
                border-radius: 24px;
            }

            .identity-panel {
                min-height: 250px;
                padding: 1.6rem;
            }

            .dossier-label {
                margin-top: 1.5rem;
            }

            .identity-title {
                max-width: 22rem;
                font-size: 2.5rem;
            }

            .identity-copy {
                max-width: 31rem;
                margin-top: 0.75rem;
            }

            .member-pass {
                position: absolute;
                right: 1.5rem;
                bottom: 1.5rem;
                width: 250px;
                margin-top: 0;
                transform: rotate(3deg);
            }

            .pass-avatar,
            .pass-subline {
                display: none;
            }

            .pass-name {
                margin-top: 0.7rem;
            }

            .application-panel {
                padding: 1.5rem;
            }
        }

        @media (max-width: 640px) {
            .registration-shell {
                display: block;
            }

            .identity-panel {
                min-height: 210px;
            }

            .identity-copy {
                max-width: 19rem;
            }

            .member-pass {
                display: none;
            }

            .application-header {
                display: block;
            }

            .approval-stamp {
                display: none;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .field-span-2,
            .password-checks {
                grid-column: span 1;
            }

            .password-checks {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .form-actions {
                align-items: stretch;
                flex-direction: column-reverse;
            }

            .submit-button {
                width: 100%;
                min-width: 0;
            }

            .signin-copy {
                text-align: center;
            }
        }

        @media (max-height: 760px) and (min-width: 881px) {
            .registration-card {
                min-height: calc(100svh - 1rem);
            }

            .identity-panel {
                padding-top: 1.7rem;
                padding-bottom: 1.7rem;
            }

            .dossier-label {
                margin-top: 2rem;
            }

            .identity-title {
                font-size: 3.2rem;
            }

            .identity-copy {
                margin-top: 0.8rem;
                line-height: 1.5;
            }

            .application-panel {
                padding-top: 1.2rem;
                padding-bottom: 1.2rem;
            }

            .application-description {
                margin-top: 0.4rem;
            }

            .registration-form {
                margin-top: 0.8rem;
            }

            .form-grid {
                gap: 0.62rem 0.9rem;
            }

            .form-input {
                min-height: 2.58rem;
                padding-top: 0.58rem;
                padding-bottom: 0.58rem;
            }

            .password-checks {
                padding: 0.48rem 0.55rem;
            }

            .form-actions {
                margin-top: 0.75rem;
                padding-top: 0.75rem;
            }

            .copyright {
                margin-top: 0.55rem;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.001ms !important;
                animation-iteration-count: 1 !important;
                scroll-behavior: auto !important;
                transition-duration: 0.001ms !important;
            }
        }
    </style>
</head>

<body>
    <main class="registration-shell">
        <section class="registration-card">
            <aside class="identity-panel" id="identityPanel">
                <div class="identity-content">
                    <a href="index.php" class="wordmark">
                        MANGA<span>VAULT</span>
                    </a>

                    <div class="dossier-label">
                        New Reader Dossier
                    </div>

                    <h1 class="identity-title">
                        Build your
                        <br>
                        <em>reader identity.</em>
                    </h1>

                    <p class="identity-copy">
                        Create one account for your physical manga,
                        e-books, rewards, orders, wishlist and
                        collection history.
                    </p>

                    <div class="member-pass" id="memberPass">
                        <div class="pass-topline">
                            <span>MangaVault Access Pass</span>
                            <span>MV-2026</span>
                        </div>

                        <div class="pass-avatar">
                            MV
                        </div>

                        <p class="pass-name" id="passDisplayName">
                            Future Member
                        </p>

                        <p class="pass-subline" id="passDisplayHandle">
                            @your-reader-name
                        </p>

                        <div class="pass-code" aria-hidden="true">
                            <span></span>
                            <span></span>
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                    </div>
                </div>
            </aside>

            <section class="application-panel">
                <div class="application-content">
                    <header class="application-header">
                        <div>
                            <p class="eyebrow">
                                Membership Application
                            </p>

                            <h2 class="application-title">
                                Create your account
                            </h2>

                            <p class="application-description">
                                Complete the details below to open your
                                MangaVault reader profile.
                            </p>
                        </div>

                        <div
                            class="approval-stamp"
                            aria-hidden="true"
                        >
                            Reader
                            <br>
                            Entry
                            <br>
                            2026
                        </div>
                    </header>

                    <?php if ($error): ?>
                    <div
                        class="notice notice-error"
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

                    <?php if ($success): ?>
                    <div
                        class="notice notice-success"
                        role="status"
                        aria-live="polite"
                    >
                        <span aria-hidden="true">✓</span>
                        <span>
                            <?= htmlspecialchars(
                                $success,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                            <a
                                href="login.php"
                                class="font-black underline"
                            >
                                Login here
                            </a>
                        </span>
                    </div>
                    <?php endif; ?>

                    <form
                        method="POST"
                        class="registration-form"
                        id="registerForm"
                    >
                        <?php csrf_field(); ?>

                        <div class="form-grid">
                            <div class="field-group">
                                <label
                                    for="firstNameInput"
                                    class="form-label"
                                >
                                    <span>
                                        First Name
                                        <span class="text-red-600">*</span>
                                    </span>
                                </label>

                                <div class="field-wrap">
                                    <span
                                        class="field-prefix"
                                        aria-hidden="true"
                                    >
                                        FN
                                    </span>

                                    <input
                                        id="firstNameInput"
                                        type="text"
                                        name="user_first_name"
                                        maxlength="50"
                                        autocomplete="given-name"
                                        class="form-input"
                                        placeholder="John"
                                        required
                                    >
                                </div>
                            </div>

                            <div class="field-group">
                                <label
                                    for="lastNameInput"
                                    class="form-label"
                                >
                                    Last Name
                                </label>

                                <div class="field-wrap">
                                    <span
                                        class="field-prefix"
                                        aria-hidden="true"
                                    >
                                        LN
                                    </span>

                                    <input
                                        id="lastNameInput"
                                        type="text"
                                        name="user_last_name"
                                        maxlength="50"
                                        autocomplete="family-name"
                                        class="form-input"
                                        placeholder="Doe"
                                    >
                                </div>
                            </div>

                            <div class="field-group">
                                <label
                                    for="usernameInput"
                                    class="form-label"
                                >
                                    <span>
                                        Username
                                        <span class="text-red-600">*</span>
                                    </span>
                                </label>

                                <div class="field-wrap">
                                    <span
                                        class="field-prefix"
                                        aria-hidden="true"
                                    >
                                        @
                                    </span>

                                    <input
                                        id="usernameInput"
                                        type="text"
                                        name="user_name"
                                        maxlength="50"
                                        autocomplete="username"
                                        class="form-input"
                                        placeholder="mangalover91"
                                        required
                                    >
                                </div>

                                <p class="helper-text">
                                    Used to identify your account publicly.
                                </p>
                            </div>

                            <div class="field-group">
                                <label
                                    for="emailInput"
                                    class="form-label"
                                >
                                    <span>
                                        Email Address
                                        <span class="text-red-600">*</span>
                                    </span>
                                </label>

                                <div class="field-wrap">
                                    <span
                                        class="field-prefix"
                                        aria-hidden="true"
                                    >
                                        EM
                                    </span>

                                    <input
                                        id="emailInput"
                                        type="email"
                                        name="user_gmail"
                                        maxlength="100"
                                        autocomplete="email"
                                        class="form-input"
                                        placeholder="you@example.com"
                                        required
                                    >
                                </div>
                            </div>

                            <div class="field-group">
                                <label
                                    for="dobInput"
                                    class="form-label"
                                >
                                    <span>
                                        Date of Birth
                                        <span class="text-red-600">*</span>
                                    </span>

                                    <span class="field-note">
                                        Can only be changed once
                                    </span>
                                </label>

                                <div class="field-wrap">
                                    <span
                                        class="field-prefix"
                                        aria-hidden="true"
                                    >
                                        DOB
                                    </span>

                                    <input
                                        id="dobInput"
                                        type="date"
                                        name="user_dob"
                                        max="<?= date(
                                            'Y-m-d',
                                            strtotime('-13 years')
                                        ) ?>"
                                        autocomplete="bday"
                                        class="form-input"
                                        required
                                    >
                                </div>
                            </div>

                            <div class="field-group">
                                <label
                                    for="phoneInput"
                                    class="form-label"
                                >
                                    Phone
                                    <span class="text-gray-400">
                                        Optional
                                    </span>
                                </label>

                                <div class="field-wrap">
                                    <span
                                        class="field-prefix"
                                        aria-hidden="true"
                                    >
                                        +60
                                    </span>

                                    <input
                                        id="phoneInput"
                                        type="text"
                                        name="user_phone"
                                        maxlength="11"
                                        inputmode="numeric"
                                        autocomplete="tel"
                                        class="form-input"
                                        placeholder="01234567890"
                                    >
                                </div>

                                <p class="helper-text">
                                    Malaysian format, for example
                                    01234567890.
                                </p>
                            </div>

                            <div class="field-group">
                                <label
                                    for="passwordInput"
                                    class="form-label"
                                >
                                    <span>
                                        Password
                                        <span class="text-red-600">*</span>
                                    </span>
                                </label>

                                <div class="field-wrap">
                                    <span
                                        class="field-prefix"
                                        aria-hidden="true"
                                    >
                                        PW
                                    </span>

                                    <input
                                        id="passwordInput"
                                        type="password"
                                        name="password"
                                        minlength="8"
                                        maxlength="72"
                                        autocomplete="new-password"
                                        class="form-input"
                                        placeholder="Create a strong password"
                                        required
                                    >
                                </div>
                            </div>

                            <div class="field-group">
                                <label
                                    for="confirmInput"
                                    class="form-label"
                                >
                                    <span>
                                        Confirm Password
                                        <span class="text-red-600">*</span>
                                    </span>
                                </label>

                                <div class="field-wrap">
                                    <span
                                        class="field-prefix"
                                        aria-hidden="true"
                                    >
                                        OK
                                    </span>

                                    <input
                                        id="confirmInput"
                                        type="password"
                                        name="confirm_password"
                                        minlength="8"
                                        maxlength="72"
                                        autocomplete="new-password"
                                        class="form-input"
                                        placeholder="Repeat your password"
                                        required
                                    >
                                </div>

                                <p
                                    id="confirmMsg"
                                    class="confirm-message hidden"
                                ></p>
                            </div>

                            <div
                                id="passwordStrength"
                                class="password-checks hidden"
                                aria-live="polite"
                            >
                                <div
                                    id="check_length"
                                    class="password-check-item"
                                >
                                    <span class="check-icon">○</span>
                                    <span>8+ characters</span>
                                </div>

                                <div
                                    id="check_upper"
                                    class="password-check-item"
                                >
                                    <span class="check-icon">○</span>
                                    <span>Uppercase</span>
                                </div>

                                <div
                                    id="check_lower"
                                    class="password-check-item"
                                >
                                    <span class="check-icon">○</span>
                                    <span>Lowercase</span>
                                </div>

                                <div
                                    id="check_number"
                                    class="password-check-item"
                                >
                                    <span class="check-icon">○</span>
                                    <span>Number</span>
                                </div>

                                <div
                                    id="check_symbol"
                                    class="password-check-item"
                                >
                                    <span class="check-icon">○</span>
                                    <span>Symbol</span>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <p class="signin-copy">
                                Already have an account?
                                <a
                                    href="login.php"
                                    class="signin-link"
                                >
                                    Sign in
                                </a>
                            </p>

                            <button
                                type="submit"
                                class="submit-button"
                                id="createAccountButton"
                            >
                                <span id="createAccountButtonText">
                                    Create Account
                                </span>
                                <span aria-hidden="true">→</span>
                            </button>
                        </div>
                    </form>

                    <p class="copyright">
                        © 2026 MangaVault. Your reader identity starts here.
                    </p>
                </div>
            </section>
        </section>
    </main>

    <script
        src="assets/js/password-toggle.js"
        defer
    ></script>

    <script>
        (() => {
            'use strict';

            const phoneInput =
                document.getElementById('phoneInput');
            const passwordInput =
                document.getElementById('passwordInput');
            const strengthDiv =
                document.getElementById('passwordStrength');
            const confirmInput =
                document.getElementById('confirmInput');
            const confirmMsg =
                document.getElementById('confirmMsg');
            const registerForm =
                document.getElementById('registerForm');
            const dobInput =
                document.getElementById('dobInput');
            const usernameInput =
                document.getElementById('usernameInput');
            const firstNameInput =
                document.getElementById('firstNameInput');
            const passDisplayName =
                document.getElementById('passDisplayName');
            const passDisplayHandle =
                document.getElementById('passDisplayHandle');
            const memberPass =
                document.getElementById('memberPass');
            const identityPanel =
                document.getElementById('identityPanel');
            const submitButton =
                document.getElementById('createAccountButton');
            const submitButtonText =
                document.getElementById(
                    'createAccountButtonText'
                );

            function updateCheck(id, passed) {
                const element = document.getElementById(id);
                const icon =
                    element.querySelector('.check-icon');

                element.classList.toggle(
                    'is-valid',
                    passed
                );
                icon.textContent = passed ? '✓' : '○';
            }

            function updateConfirmation() {
                if (!confirmInput.value) {
                    confirmMsg.classList.add('hidden');
                    return;
                }

                const matches =
                    confirmInput.value === passwordInput.value;

                confirmMsg.textContent = matches
                    ? '✓ Passwords match'
                    : '✗ Passwords do not match';

                confirmMsg.className = matches
                    ? 'confirm-message text-green-700'
                    : 'confirm-message text-red-600';
            }

            phoneInput.addEventListener('input', function () {
                this.value =
                    this.value.replace(/[^0-9]/g, '');
            });

            passwordInput.addEventListener(
                'input',
                function () {
                    const value = this.value;

                    strengthDiv.classList.toggle(
                        'hidden',
                        value.length === 0
                    );

                    updateCheck(
                        'check_length',
                        value.length >= 8
                    );
                    updateCheck(
                        'check_upper',
                        /[A-Z]/.test(value)
                    );
                    updateCheck(
                        'check_lower',
                        /[a-z]/.test(value)
                    );
                    updateCheck(
                        'check_number',
                        /[0-9]/.test(value)
                    );
                    updateCheck(
                        'check_symbol',
                        /[!@#$%^&*(),.?":{}|<>]/.test(value)
                    );

                    updateConfirmation();
                }
            );

            confirmInput.addEventListener(
                'input',
                updateConfirmation
            );

            firstNameInput.addEventListener(
                'input',
                () => {
                    const name =
                        firstNameInput.value.trim();

                    passDisplayName.textContent =
                        name || 'Future Member';
                }
            );

            usernameInput.addEventListener(
                'input',
                () => {
                    const handle =
                        usernameInput.value.trim();

                    passDisplayHandle.textContent =
                        handle
                            ? `@${handle}`
                            : '@your-reader-name';
                }
            );

            registerForm.addEventListener(
                'submit',
                function (event) {
                    const phone = phoneInput.value;
                    const password = passwordInput.value;
                    const dob = dobInput.value;

                    if (!dob) {
                        event.preventDefault();
                        alert(
                            'Please enter your date of birth.'
                        );
                        return;
                    }

                    if (
                        phone &&
                        !/^01[0-9]{8,9}$/.test(phone)
                    ) {
                        event.preventDefault();
                        alert(
                            'Please enter a valid Malaysian ' +
                            'phone number (e.g. 01234567890)'
                        );
                        return;
                    }

                    const allGood =
                        password.length >= 8 &&
                        /[A-Z]/.test(password) &&
                        /[a-z]/.test(password) &&
                        /[0-9]/.test(password) &&
                        /[!@#$%^&*(),.?":{}|<>]/.test(
                            password
                        );

                    if (!allGood) {
                        event.preventDefault();
                        alert(
                            'Password must be at least 8 ' +
                            'characters with uppercase, ' +
                            'lowercase, number and symbol!'
                        );
                        return;
                    }

                    submitButton.disabled = true;
                    submitButtonText.textContent =
                        'Creating your account...';
                }
            );

            if (
                memberPass &&
                identityPanel &&
                !window.matchMedia(
                    '(prefers-reduced-motion: reduce)'
                ).matches
            ) {
                identityPanel.addEventListener(
                    'pointermove',
                    (event) => {
                        const rect =
                            identityPanel.getBoundingClientRect();
                        const x =
                            (
                                event.clientX -
                                rect.left
                            ) / rect.width - 0.5;
                        const y =
                            (
                                event.clientY -
                                rect.top
                            ) / rect.height - 0.5;

                        memberPass.style.transform =
                            `rotateY(${x * 7}deg) ` +
                            `rotateX(${-y * 7}deg) ` +
                            `translate3d(${x * 6}px, ` +
                            `${y * 6}px, 0)`;
                    }
                );

                identityPanel.addEventListener(
                    'pointerleave',
                    () => {
                        memberPass.style.transform =
                            'rotateY(0deg) rotateX(0deg) ' +
                            'translate3d(0, 0, 0)';
                    }
                );
            }
        })();
    </script>
</body>
</html>