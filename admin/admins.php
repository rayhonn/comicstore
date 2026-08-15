<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/staff_admin_id_helper.php';

require_senior_admin();

$error = '';
$openAddModalOnError = false;

$formValues = [
    'user_first_name' => '',
    'user_last_name' => '',
    'user_name' => '',
    'user_gmail' => '',
    'user_phone' => '',
];

function normalizeAdminAccountText(
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
            $label . ' cannot exceed ' . $maxLength . ' characters.'
        );
    }

    return $value;
}

function validateAdminAccountPassword(mixed $value): string
{
    if (!is_string($value)) {
        throw new RuntimeException('Invalid password.');
    }

    $length = strlen($value);

    if ($length < 8 || $length > 72) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? null;

    try {
        if (
            !is_string($action) ||
            !in_array(
                $action,
                [
                    'add',
                    'toggle',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Invalid administrator management action.'
            );
        }

        if ($action === 'add') {
            $openAddModalOnError = true;

            $firstName = normalizeAdminAccountText(
                $_POST['user_first_name'] ?? '',
                'First name',
                50,
                true
            );
            $lastName = normalizeAdminAccountText(
                $_POST['user_last_name'] ?? '',
                'Last name',
                50
            );
            $userName = normalizeAdminAccountText(
                $_POST['user_name'] ?? '',
                'Username',
                50,
                true
            );
            $email = normalizeAdminAccountText(
                $_POST['user_gmail'] ?? '',
                'Email',
                100,
                true
            );
            $phone = normalizeAdminAccountText(
                $_POST['user_phone'] ?? '',
                'Phone number',
                20
            );
            $password = validateAdminAccountPassword(
                $_POST['password'] ?? null
            );
            $confirmPassword =
                $_POST['confirm_password'] ?? null;

            $formValues = [
                'user_first_name' => $firstName,
                'user_last_name' => $lastName,
                'user_name' => $userName,
                'user_gmail' => $email,
                'user_phone' => $phone,
            ];

            if (!is_string($confirmPassword)) {
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

            $duplicateCheck = $pdo->prepare("
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

            if ($duplicateCheck->fetchColumn()) {
                throw new RuntimeException(
                    'Username or email already exists.'
                );
            }

            $pdo->beginTransaction();

            try {
                $staffAdminId =
                    allocateStaffAdminId(
                        $pdo
                    );

                $insert = $pdo->prepare("
                    INSERT INTO users (
                        user_name,
                        user_gmail,
                        user_password_hash,
                        user_first_name,
                        user_last_name,
                        user_phone,
                        user_role,
                        user_admin_level,
                        user_staff_id
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
                        ?
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
                    $staffAdminId,
                ]);
                $newAdminId =
                    (int) $pdo->lastInsertId();

                $log = $pdo->prepare("
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
                    (int) $_SESSION['user_id'],
                    $newAdminId,
                    'Added administrator: ' .
                        $userName,
                ]);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $e;
            }

            header(
                'Location: admins.php?created=1&staff_id=' .
                urlencode(
                    $staffAdminId
                )
            );
            exit;
        }

        $userId = filter_var(
            $_POST['user_id'] ?? null,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                ],
            ]
        );

        $isActive = filter_var(
            $_POST['is_active'] ?? null,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 0,
                    'max_range' => 1,
                ],
            ]
        );

        if (
            $userId === false ||
            $userId === null
        ) {
            throw new RuntimeException(
                'Invalid administrator account.'
            );
        }

        if (
            $isActive === false ||
            $isActive === null
        ) {
            throw new RuntimeException(
                'Invalid administrator account status.'
            );
        }

        if (
            $userId ===
            (int) $_SESSION['user_id']
        ) {
            throw new RuntimeException(
                'You cannot change the status of your own administrator account.'
            );
        }

        $pdo->beginTransaction();

        try {
            $targetStatement = $pdo->prepare("
                SELECT
                    user_name,
                    user_is_active,
                    user_admin_level
                FROM users
                WHERE user_id = ?
                AND user_role = 'admin'
                FOR UPDATE
            ");
            $targetStatement->execute([
                $userId,
            ]);

            $targetAdmin =
                $targetStatement->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$targetAdmin) {
                throw new RuntimeException(
                    'Administrator account not found.'
                );
            }

            if (
                $targetAdmin['user_admin_level'] !==
                'staff_admin'
            ) {
                throw new RuntimeException(
                    'Super Admin accounts cannot be deactivated or reactivated here.'
                );
            }

            if (
                (int) $targetAdmin['user_is_active'] ===
                $isActive
            ) {
                throw new RuntimeException(
                    'Administrator account status is already unchanged.'
                );
            }

            $update = $pdo->prepare("
                UPDATE users
                SET user_is_active = ?
                WHERE user_id = ?
                AND user_role = 'admin'
                AND user_admin_level = 'staff_admin'
            ");
            $update->execute([
                $isActive,
                $userId,
            ]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException(
                    'Unable to update administrator account status.'
                );
            }

            $statusAction =
                $isActive === 1
                    ? 'activate_admin'
                    : 'deactivate_admin';

            $statusLabel =
                $isActive === 1
                    ? 'activated'
                    : 'deactivated';

            $log = $pdo->prepare("
                INSERT INTO admin_logs (
                    log_admin_id,
                    log_action,
                    log_target_type,
                    log_target_id,
                    log_details
                )
                VALUES (
                    ?,
                    ?,
                    'user',
                    ?,
                    ?
                )
            ");
            $log->execute([
                (int) $_SESSION['user_id'],
                $statusAction,
                $userId,
                'Administrator account ' .
                    $statusLabel .
                    ': ' .
                    (string) $targetAdmin['user_name'],
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        header(
            'Location: admins.php?status=' .
            (
                $isActive === 1
                    ? 'activated'
                    : 'deactivated'
            )
        );
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        app_error_log(
            'Administrator account management failed: ' .
            $e->getMessage()
        );

        $error =
            'Unable to manage administrator account.';
    }
}

$admins = $pdo->query("
    SELECT
        user_id,
        user_staff_id,
        user_name,
        user_gmail,
        user_first_name,
        user_last_name,
        user_phone,
        user_is_active,
        user_created_at,
        user_last_login,
        user_admin_level
    FROM users
    WHERE user_role = 'admin'
    ORDER BY
        CASE
            WHEN user_admin_level = 'senior_admin' THEN 0
            ELSE 1
        END,
        user_created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Accounts - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
        .modal { display: none; }
        .modal.active { display: flex; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-6">
            <div>
                <h1 class="text-2xl font-black text-gray-800">Admin Accounts</h1>
                <p class="text-sm text-gray-400 mt-0.5">
                    <?= count($admins) ?> administrator account(s)
                </p>
            </div>

            <button
                type="button"
                onclick="openAddAdminModal()"
                class="bg-red-600 hover:bg-red-700 text-white font-semibold px-4 py-2 rounded-xl text-sm transition-colors"
            >
                + Add Admin
            </button>
        </div>

        <div class="bg-blue-50 border border-blue-100 text-blue-700 text-sm px-4 py-3 rounded-xl mb-5">
            Only Super Admins can create, deactivate or reactivate standard Admin accounts. Super Admin accounts are protected.
        </div>

        <?php if (isset($_GET['created'])): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5">
            ✅ Administrator account created successfully.
        </div>
        <?php endif; ?>

        <?php if (
            ($_GET['status'] ?? '') ===
            'deactivated'
        ): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5">
            ✅ Administrator account deactivated successfully.
        </div>
        <?php endif; ?>

        <?php if (
            ($_GET['status'] ?? '') ===
            'activated'
        ): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5">
            ✅ Administrator account reactivated successfully.
        </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-5">
            ❌ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[850px]">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-100">
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Administrator</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Access Level</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Contact</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Created</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Last Login</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($admins as $admin): ?>
                        <?php
                        $isSuperAdmin =
                            $admin['user_admin_level'] === 'senior_admin';
                        ?>
                        <tr class="border-t border-gray-50 hover:bg-gray-50 transition-colors <?= !(int) $admin['user_is_active'] ? 'opacity-60' : '' ?>">
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 bg-[#1e2d4a] rounded-full flex items-center justify-center text-white text-sm font-black flex-shrink-0">
                                        <?= htmlspecialchars(
                                            strtoupper(
                                                substr(
                                                    (string) ($admin['user_first_name'] ?? 'A'),
                                                    0,
                                                    1
                                                )
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-sm text-gray-800">
                                            <?= htmlspecialchars(
                                                trim(
                                                    (string) ($admin['user_first_name'] ?? '') . ' ' .
                                                    (string) ($admin['user_last_name'] ?? '')
                                                ),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>
                                        <p class="text-xs text-gray-400">
                                            @<?= htmlspecialchars(
                                                (string) $admin['user_name'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>

                                        <?php if (
                                            !$isSuperAdmin &&
                                            !empty(
                                                $admin[
                                                    'user_staff_id'
                                                ]
                                            )
                                        ): ?>
                                            <p
                                                class="mt-1 inline-block rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs font-bold text-gray-600"
                                            >
                                                ID:
                                                <?= htmlspecialchars(
                                                    (string) $admin[
                                                        'user_staff_id'
                                                    ],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </p>
                                        <?php endif; ?>                                        

                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <span class="<?= $isSuperAdmin ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' ?> text-xs px-2.5 py-1 rounded-full font-semibold">
                                    <?= $isSuperAdmin ? 'Super Admin' : 'Admin' ?>
                                </span>
                            </td>
                            <td class="px-5 py-4">
                                <p class="text-sm text-gray-600">
                                    <?= htmlspecialchars(
                                        (string) $admin['user_gmail'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>
                                <p class="text-xs text-gray-400">
                                    <?= htmlspecialchars(
                                        (string) ($admin['user_phone'] ?: '—'),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500">
                                <?= date(
                                    'd M Y',
                                    strtotime((string) $admin['user_created_at'])
                                ) ?>
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500">
                                <?= !empty($admin['user_last_login'])
                                    ? date(
                                        'd M Y H:i',
                                        strtotime((string) $admin['user_last_login'])
                                    )
                                    : 'Never' ?>
                            </td>
                            <td class="px-5 py-4">
                                <span class="<?= (int) $admin['user_is_active'] === 1 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?> text-xs px-2 py-1 rounded-full font-semibold">
                                    <?= (int) $admin['user_is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td class="px-5 py-4">
                                <?php if ($isSuperAdmin): ?>
                                <span
                                    class="text-xs text-gray-400 font-medium"
                                    title="Super Admin accounts are protected"
                                >
                                    Protected
                                </span>
                                <?php else: ?>
                                <form
                                    method="POST"
                                    class="inline"
                                    onsubmit="return confirm(
                                        '<?= (int) $admin['user_is_active'] === 1
                                            ? 'Deactivate this administrator account?'
                                            : 'Reactivate this administrator account?' ?>'
                                    )"
                                >
                                    <?php csrf_field(); ?>
                                    <input
                                        type="hidden"
                                        name="action"
                                        value="toggle"
                                    >
                                    <input
                                        type="hidden"
                                        name="user_id"
                                        value="<?= (int) $admin['user_id'] ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="is_active"
                                        value="<?= (int) $admin['user_is_active'] === 1 ? 0 : 1 ?>"
                                    >

                                    <?php if (
                                        (int) $admin['user_is_active'] === 1
                                    ): ?>
                                    <button
                                        type="submit"
                                        class="text-xs px-3 py-1.5 border border-red-200 text-red-600 rounded-lg hover:bg-red-50 transition-colors"
                                    >
                                        Deactivate
                                    </button>
                                    <?php else: ?>
                                    <button
                                        type="submit"
                                        class="text-xs px-3 py-1.5 border border-green-200 text-green-600 rounded-lg hover:bg-green-50 transition-colors"
                                    >
                                        Reactivate
                                    </button>
                                    <?php endif; ?>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div
        id="addAdminModal"
        class="modal fixed inset-0 bg-black/50 z-50 items-center justify-center px-4"
    >
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl max-h-[90vh] overflow-y-auto">
            <div class="p-5 border-b border-gray-100 flex justify-between items-center">
                <div>
                    <h3 class="font-black text-gray-800">Create Admin Account</h3>
                    <p class="text-xs text-gray-400 mt-0.5">Creates a standard Admin account</p>
                </div>
                <button
                    type="button"
                    onclick="closeAddAdminModal()"
                    class="text-gray-400 hover:text-gray-600"
                    aria-label="Close"
                >
                    ✕
                </button>
            </div>

            <form method="POST" class="p-5 space-y-4">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="add">

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">First Name *</label>
                        <input
                            type="text"
                            name="user_first_name"
                            maxlength="50"
                            required
                            value="<?= htmlspecialchars($formValues['user_first_name'], ENT_QUOTES, 'UTF-8') ?>"
                            class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400"
                        >
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Last Name</label>
                        <input
                            type="text"
                            name="user_last_name"
                            maxlength="50"
                            value="<?= htmlspecialchars($formValues['user_last_name'], ENT_QUOTES, 'UTF-8') ?>"
                            class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400"
                        >
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Username *</label>
                    <input
                        type="text"
                        name="user_name"
                        maxlength="50"
                        required
                        value="<?= htmlspecialchars($formValues['user_name'], ENT_QUOTES, 'UTF-8') ?>"
                        class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400"
                    >
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Email *</label>
                    <input
                        type="email"
                        name="user_gmail"
                        maxlength="100"
                        required
                        value="<?= htmlspecialchars($formValues['user_gmail'], ENT_QUOTES, 'UTF-8') ?>"
                        class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400"
                    >
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Phone</label>
                    <input
                        type="text"
                        name="user_phone"
                        maxlength="11"
                        value="<?= htmlspecialchars($formValues['user_phone'], ENT_QUOTES, 'UTF-8') ?>"
                        class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400"
                    >
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Password *</label>
                    <input
                        type="password"
                        name="password"
                        minlength="8"
                        maxlength="72"
                        autocomplete="new-password"
                        required
                        class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400"
                    >
                    <p class="text-xs text-gray-400 mt-1.5">
                        Use 8–72 characters with uppercase, lowercase, number and symbol.
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Confirm Password *</label>
                    <input
                        type="password"
                        name="confirm_password"
                        minlength="8"
                        maxlength="72"
                        autocomplete="new-password"
                        required
                        class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400"
                    >
                </div>

                <div class="flex gap-3 pt-1">
                    <button
                        type="button"
                        onclick="closeAddAdminModal()"
                        class="flex-1 py-3 border-2 border-gray-100 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors"
                    >
                        Create Admin
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openAddAdminModal() {
        document
            .getElementById('addAdminModal')
            .classList
            .add('active');
    }

    function closeAddAdminModal() {
        document
            .getElementById('addAdminModal')
            .classList
            .remove('active');
    }

    document
        .getElementById('addAdminModal')
        .addEventListener('click', function (event) {
            if (event.target === this) {
                closeAddAdminModal();
            }
        });

    <?php if (
        $openAddModalOnError &&
        $error !== ''
    ): ?>
    openAddAdminModal();
    <?php endif; ?>
    </script>
</body>
</html>