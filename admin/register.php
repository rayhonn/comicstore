<?php

require_once __DIR__ .
    '/../includes/auth.php';

require_once __DIR__ .
    '/../includes/db.php';

require_once __DIR__ .
    '/../includes/csrf.php';

require_once __DIR__ .
    '/../includes/logger.php';

require_senior_admin();

$error = '';

$formValues = [
    'user_first_name' => '',
    'user_last_name' => '',
    'user_name' => '',
    'user_gmail' => '',
    'user_phone' => '',
];

function normalizeAdminRegistrationText(
    mixed $value,
    string $label,
    int $maxLength,
    bool $required = false
): string {
    if (!is_string($value)) {
        throw new RuntimeException(
            'Invalid ' . $label . '.'
        );
    }

    $value = trim($value);

    $length =
        function_exists('mb_strlen')
            ? mb_strlen(
                $value,
                'UTF-8'
            )
            : strlen($value);

    if (
        $required &&
        $value === ''
    ) {
        throw new RuntimeException(
            $label . ' is required.'
        );
    }

    if ($length > $maxLength) {
        throw new RuntimeException(
            $label .
            ' cannot exceed ' .
            $maxLength .
            ' characters.'
        );
    }

    return $value;
}

function validateAdminRegistrationPassword(
    mixed $value
): string {
    if (!is_string($value)) {
        throw new RuntimeException(
            'Invalid password.'
        );
    }

    $length = strlen($value);

    if (
        $length < 8 ||
        $length > 72
    ) {
        throw new RuntimeException(
            'Password must be between 8 and 72 characters.'
        );
    }

    if (
        !preg_match(
            '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,72}$/',
            $value
        )
    ) {
        throw new RuntimeException(
            'Password must include uppercase, lowercase, number and symbol.'
        );
    }

    return $value;
}

if (
    $_SERVER['REQUEST_METHOD'] ===
    'POST'
) {
    csrf_verify();

    try {
        $firstName =
            normalizeAdminRegistrationText(
                $_POST[
                    'user_first_name'
                ] ?? '',
                'First name',
                50,
                true
            );

        $lastName =
            normalizeAdminRegistrationText(
                $_POST[
                    'user_last_name'
                ] ?? '',
                'Last name',
                50
            );

        $userName =
            normalizeAdminRegistrationText(
                $_POST[
                    'user_name'
                ] ?? '',
                'Username',
                50,
                true
            );

        $email =
            normalizeAdminRegistrationText(
                $_POST[
                    'user_gmail'
                ] ?? '',
                'Email',
                100,
                true
            );

        $phone =
            normalizeAdminRegistrationText(
                $_POST[
                    'user_phone'
                ] ?? '',
                'Phone number',
                20
            );

        $password =
            validateAdminRegistrationPassword(
                $_POST[
                    'password'
                ] ?? null
            );

        $confirmPassword =
            $_POST[
                'confirm_password'
            ] ?? null;

        $formValues = [
            'user_first_name' =>
                $firstName,
            'user_last_name' =>
                $lastName,
            'user_name' =>
                $userName,
            'user_gmail' =>
                $email,
            'user_phone' =>
                $phone,
        ];

        if (
            !is_string(
                $confirmPassword
            )
        ) {
            throw new RuntimeException(
                'Invalid password confirmation.'
            );
        }

        if (
            !hash_equals(
                $password,
                $confirmPassword
            )
        ) {
            throw new RuntimeException(
                'Password confirmation does not match.'
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
            !preg_match(
                '/^01[0-9]{8,9}$/',
                $phone
            )
        ) {
            throw new RuntimeException(
                'Please enter a valid Malaysian phone number.'
            );
        }

        $duplicateCheck =
            $pdo->prepare("
                SELECT user_id
                FROM users
                WHERE user_name = ?
                OR user_gmail = ?
                LIMIT 1
            ");

        $duplicateCheck->execute([
            $userName,
            $email,
        ]);

        if (
            $duplicateCheck
                ->fetchColumn()
        ) {
            throw new RuntimeException(
                'Username or email already exists.'
            );
        }

        $pdo->beginTransaction();

        try {
            $insert =
                $pdo->prepare("
                    INSERT INTO users (
                        user_name,
                        user_gmail,
                        user_password_hash,
                        user_first_name,
                        user_last_name,
                        user_phone,
                        user_role,
                        user_admin_level,
                        user_is_active
                    )
                    VALUES (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        'admin',
                        'staff_admin',
                        1
                    )
                ");

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
            ]);

            $newAdminId =
                (int)
                $pdo->lastInsertId();

            $log =
                $pdo->prepare("
                    INSERT INTO admin_logs (
                        log_admin_id,
                        log_action,
                        log_target_type,
                        log_target_id,
                        log_details
                    )
                    VALUES (
                        ?,
                        'add_admin',
                        'user',
                        ?,
                        ?
                    )
                ");

            $log->execute([
                (int)
                    $_SESSION[
                        'user_id'
                    ],
                $newAdminId,
                'Registered administrator account: ' .
                    $userName,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if (
                $pdo->inTransaction()
            ) {
                $pdo->rollBack();
            }

            throw $e;
        }

        redirect_to(
            app_path(
                'admin/admins.php'
            ) .
            '?created=1'
        );
    } catch (
        RuntimeException $e
    ) {
        $error =
            $e->getMessage();
    } catch (Throwable $e) {
        app_error_log(
            'Administrator registration failed: ' .
            $e->getMessage()
        );

        $error =
            'Unable to register the administrator account.';
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
        Register Admin - MangaVault Admin
    </title>

    <script
        src="https://cdn.tailwindcss.com"
    ></script>

    <style>
        body {
            opacity: 0;
            animation:
                fadeIn
                0.4s
                ease
                forwards;
        }

        @keyframes fadeIn {
            to {
                opacity: 1;
            }
        }
    </style>
</head>

<body
    class="min-h-screen bg-gray-100"
>
    <?php
    include __DIR__ .
        '/../includes/admin_navbar.php';
    ?>

    <main
        class="mx-auto max-w-5xl px-4 py-6 sm:px-6 sm:py-8"
    >
        <div
            class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div>
                <p
                    class="text-[11px] font-black uppercase tracking-[0.18em] text-red-600"
                >
                    Account Management
                </p>

                <h1
                    class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl"
                >
                    Register Administrator
                </h1>

                <p
                    class="mt-2 max-w-2xl text-sm leading-6 text-gray-500"
                >
                    Create a standard MangaVault administrator account.
                    Registration is restricted to Super Admins.
                </p>
            </div>

            <a
                href="admins.php"
                class="inline-flex min-h-[42px] items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-bold text-gray-600 transition-colors hover:border-red-200 hover:text-red-600"
            >
                ← Admin Accounts
            </a>
        </div>

        <div
            class="mb-6 rounded-2xl border border-blue-100 bg-blue-50 px-5 py-4"
        >
            <div
                class="flex items-start gap-3"
            >
                <div
                    class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-blue-100 text-blue-700"
                >
                    🔐
                </div>

                <div>
                    <p
                        class="text-sm font-black text-blue-800"
                    >
                        Standard Admin Access
                    </p>

                    <p
                        class="mt-1 text-xs leading-5 text-blue-600"
                    >
                        New accounts are created with the Admin access
                        level. Super Admin access cannot be self-assigned
                        through this registration page.
                    </p>
                </div>
            </div>
        </div>

        <?php if (
            $error !== ''
        ): ?>
            <div
                class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700"
            >
                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>
        <?php endif; ?>

        <div
            class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm"
        >
            <div
                class="border-b border-gray-100 px-5 py-5 sm:px-7"
            >
                <h2
                    class="text-lg font-black text-gray-900"
                >
                    Administrator Details
                </h2>

                <p
                    class="mt-1 text-sm text-gray-400"
                >
                    Enter the account and contact information below.
                </p>
            </div>

            <form
                method="POST"
                class="space-y-6 p-5 sm:p-7"
            >
                <?php csrf_field(); ?>

                <div
                    class="grid grid-cols-1 gap-5 sm:grid-cols-2"
                >
                    <div>
                        <label
                            for="user_first_name"
                            class="mb-1.5 block text-xs font-bold uppercase tracking-wide text-gray-500"
                        >
                            First Name *
                        </label>

                        <input
                            type="text"
                            id="user_first_name"
                            name="user_first_name"
                            maxlength="50"
                            required
                            autocomplete="given-name"
                            value="<?= htmlspecialchars(
                                $formValues[
                                    'user_first_name'
                                ],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            class="w-full rounded-xl border-2 border-gray-100 bg-gray-50 px-4 py-3 text-sm transition-colors focus:border-red-400 focus:bg-white focus:outline-none"
                        >
                    </div>

                    <div>
                        <label
                            for="user_last_name"
                            class="mb-1.5 block text-xs font-bold uppercase tracking-wide text-gray-500"
                        >
                            Last Name
                        </label>

                        <input
                            type="text"
                            id="user_last_name"
                            name="user_last_name"
                            maxlength="50"
                            autocomplete="family-name"
                            value="<?= htmlspecialchars(
                                $formValues[
                                    'user_last_name'
                                ],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            class="w-full rounded-xl border-2 border-gray-100 bg-gray-50 px-4 py-3 text-sm transition-colors focus:border-red-400 focus:bg-white focus:outline-none"
                        >
                    </div>
                </div>

                <div
                    class="grid grid-cols-1 gap-5 sm:grid-cols-2"
                >
                    <div>
                        <label
                            for="user_name"
                            class="mb-1.5 block text-xs font-bold uppercase tracking-wide text-gray-500"
                        >
                            Username *
                        </label>

                        <input
                            type="text"
                            id="user_name"
                            name="user_name"
                            maxlength="50"
                            required
                            autocomplete="username"
                            value="<?= htmlspecialchars(
                                $formValues[
                                    'user_name'
                                ],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            class="w-full rounded-xl border-2 border-gray-100 bg-gray-50 px-4 py-3 text-sm transition-colors focus:border-red-400 focus:bg-white focus:outline-none"
                        >
                    </div>

                    <div>
                        <label
                            for="user_gmail"
                            class="mb-1.5 block text-xs font-bold uppercase tracking-wide text-gray-500"
                        >
                            Email *
                        </label>

                        <input
                            type="email"
                            id="user_gmail"
                            name="user_gmail"
                            maxlength="100"
                            required
                            autocomplete="email"
                            value="<?= htmlspecialchars(
                                $formValues[
                                    'user_gmail'
                                ],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            class="w-full rounded-xl border-2 border-gray-100 bg-gray-50 px-4 py-3 text-sm transition-colors focus:border-red-400 focus:bg-white focus:outline-none"
                        >
                    </div>
                </div>

                <div>
                    <label
                        for="user_phone"
                        class="mb-1.5 block text-xs font-bold uppercase tracking-wide text-gray-500"
                    >
                        Phone Number
                    </label>

                    <input
                        type="tel"
                        id="user_phone"
                        name="user_phone"
                        maxlength="20"
                        autocomplete="tel"
                        placeholder="01XXXXXXXXX"
                        value="<?= htmlspecialchars(
                            $formValues[
                                'user_phone'
                            ],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        class="w-full rounded-xl border-2 border-gray-100 bg-gray-50 px-4 py-3 text-sm transition-colors focus:border-red-400 focus:bg-white focus:outline-none"
                    >

                    <p
                        class="mt-1.5 text-xs text-gray-400"
                    >
                        Malaysian mobile format, for example 0123456789.
                    </p>
                </div>

                <div
                    class="border-t border-gray-100 pt-6"
                >
                    <div
                        class="mb-4"
                    >
                        <h3
                            class="text-sm font-black text-gray-800"
                        >
                            Account Security
                        </h3>

                        <p
                            class="mt-1 text-xs leading-5 text-gray-400"
                        >
                            Password must contain uppercase, lowercase,
                            number and symbol.
                        </p>
                    </div>

                    <div
                        class="grid grid-cols-1 gap-5 sm:grid-cols-2"
                    >
                        <div>
                            <label
                                for="password"
                                class="mb-1.5 block text-xs font-bold uppercase tracking-wide text-gray-500"
                            >
                                Password *
                            </label>

                            <input
                                type="password"
                                id="password"
                                name="password"
                                minlength="8"
                                maxlength="72"
                                required
                                autocomplete="new-password"
                                class="w-full rounded-xl border-2 border-gray-100 bg-gray-50 px-4 py-3 text-sm transition-colors focus:border-red-400 focus:bg-white focus:outline-none"
                            >
                        </div>

                        <div>
                            <label
                                for="confirm_password"
                                class="mb-1.5 block text-xs font-bold uppercase tracking-wide text-gray-500"
                            >
                                Confirm Password *
                            </label>

                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                minlength="8"
                                maxlength="72"
                                required
                                autocomplete="new-password"
                                class="w-full rounded-xl border-2 border-gray-100 bg-gray-50 px-4 py-3 text-sm transition-colors focus:border-red-400 focus:bg-white focus:outline-none"
                            >
                        </div>
                    </div>
                </div>

                <div
                    class="flex flex-col-reverse gap-3 border-t border-gray-100 pt-6 sm:flex-row sm:justify-end"
                >
                    <a
                        href="admins.php"
                        class="inline-flex min-h-[46px] items-center justify-center rounded-xl border border-gray-200 px-5 py-3 text-sm font-bold text-gray-500 transition-colors hover:bg-gray-50"
                    >
                        Cancel
                    </a>

                    <button
                        type="submit"
                        class="inline-flex min-h-[46px] items-center justify-center rounded-xl bg-red-600 px-6 py-3 text-sm font-bold text-white shadow-sm transition-colors hover:bg-red-700"
                    >
                        Register Administrator
                    </button>
                </div>
            </form>
        </div>
    </main>

    <script
        src="../assets/js/password-toggle.js"
        defer
    ></script>
</body>
</html>