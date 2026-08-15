<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/staff_admin_id_helper.php';

require_admin();

$error = '';
$success = '';

$isAjaxAddRequest =
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'add' &&
    strtolower(
        (string) (
            $_SERVER['HTTP_X_REQUESTED_WITH']
            ?? ''
        )
    ) === 'xmlhttprequest';

function normalizeStaffText(
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

function normalizeStaffUserId(mixed $value): int
{
    $userId = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if ($userId === false || $userId === null) {
        throw new RuntimeException(
            'Invalid staff account.'
        );
    }

    return $userId;
}

function validateStaffPassword(
    mixed $value,
    string $label
): string {
    if (!is_string($value)) {
        throw new RuntimeException(
            'Invalid ' . $label . '.'
        );
    }

    $length = strlen($value);

    if ($length < 8 || $length > 72) {
        throw new RuntimeException(
            $label .
            ' must be between 8 and 72 characters.'
        );
    }

    if (
        !preg_match(
            '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)' .
            '(?=.*[\W_]).{8,72}$/',
            $value
        )
    ) {
        throw new RuntimeException(
            $label .
            ' must include uppercase, lowercase, ' .
            'number and symbol.'
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
                ['add', 'toggle', 'reset_password'],
                true
            )
        ) {
            throw new RuntimeException(
                'Invalid staff management action.'
            );
        }

        if ($action === 'add') {
            $userName = normalizeStaffText(
                $_POST['user_name'] ?? '',
                'Username',
                50,
                true
            );
            $email = normalizeStaffText(
                $_POST['user_gmail'] ?? '',
                'Email',
                100,
                true
            );
            $firstName = normalizeStaffText(
                $_POST['user_first_name'] ?? '',
                'First name',
                50,
                true
            );
            $lastName = normalizeStaffText(
                $_POST['user_last_name'] ?? '',
                'Last name',
                50
            );
            $phone = normalizeStaffText(
                $_POST['user_phone'] ?? '',
                'Phone number',
                20
            );
            $password = validateStaffPassword(
                $_POST['password'] ?? null,
                'Password'
            );

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

            $pdo->beginTransaction();

            try {
                $staffId =
                    allocateStaffAdminId(
                        $pdo
                    );
                $insert = $pdo->prepare(
                    "INSERT INTO users (
                        user_name,
                        user_gmail,
                        user_password_hash,
                        user_first_name,
                        user_last_name,
                        user_phone,
                        user_role,
                        user_staff_id
                    ) VALUES (?, ?, ?, ?, ?, ?, 'staff', ?)"
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
                    $staffId,
                ]);

                $newUserId = (int) $pdo->lastInsertId();

                $log = $pdo->prepare(
                    "INSERT INTO admin_logs (
                        log_admin_id,
                        log_action,
                        log_target_type,
                        log_target_id,
                        log_details
                    ) VALUES (
                        ?,
                        'add_staff',
                        'user',
                        ?,
                        ?
                    )"
                );
                $log->execute([
                    (int) $_SESSION['user_id'],
                    $newUserId,
                    'Added staff: ' . $userName,
                ]);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $e;
            }

            $success =
                'Staff account created successfully. Staff/Admin ID: ' .
                $staffId;
        } elseif ($action === 'toggle') {
            $userId = normalizeStaffUserId(
                $_POST['user_id'] ?? null
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

            if ($isActive === false || $isActive === null) {
                throw new RuntimeException(
                    'Invalid staff account status.'
                );
            }

            $toggle = $pdo->prepare(
                "UPDATE users
                 SET user_is_active = ?
                 WHERE user_id = ?
                 AND user_role = 'staff'"
            );
            $toggle->execute([
                $isActive,
                $userId,
            ]);

            if ($toggle->rowCount() !== 1) {
                throw new RuntimeException(
                    'Staff account not found or unchanged.'
                );
            }

            $success = 'Staff account updated.';
        } else {
            $userId = normalizeStaffUserId(
                $_POST['user_id'] ?? null
            );
            $newPassword = validateStaffPassword(
                $_POST['new_password'] ?? null,
                'New password'
            );

            $reset = $pdo->prepare(
                "UPDATE users
                 SET user_password_hash = ?
                 WHERE user_id = ?
                 AND user_role = 'staff'"
            );
            $reset->execute([
                password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                ),
                $userId,
            ]);

            if ($reset->rowCount() !== 1) {
                throw new RuntimeException(
                    'Staff account not found.'
                );
            }

            $success =
                'Password reset successfully.';
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

if ($isAjaxAddRequest) {
    header(
        'Content-Type: application/json; charset=UTF-8'
    );

    if ($error !== '') {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => $error,
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' =>
                $success !== ''
                    ? $success
                    : 'Staff account created.',
        ]);
    }

    exit;
}

$staff = $pdo->query(
    "SELECT *
     FROM users
     WHERE user_role = 'staff'
     ORDER BY user_created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Staff - MangaVault Admin</title>
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

        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-black text-gray-800">Manage Staff</h1>
                <p class="text-sm text-gray-400 mt-0.5"><?= count($staff) ?> staff accounts</p>
            </div>
            <button onclick="openAddModal()"
                    class="bg-red-600 hover:bg-red-700 text-white font-semibold px-4 py-2 rounded-xl text-sm transition-colors">
                + Add Staff
            </button>
        </div>

        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-5">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <?php if (empty($staff)): ?>
            <div class="p-12 text-center">
                <div class="text-4xl mb-3">👥</div>
                <p class="text-gray-400 mb-4">No staff accounts yet.</p>
                <button onclick="openAddModal()" class="bg-red-600 hover:bg-red-700 text-white font-semibold px-5 py-2 rounded-xl text-sm transition-colors">
                    Add First Staff
                </button>
            </div>
            <?php else: ?>
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Staff</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Contact</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Joined</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staff as $s): ?>
                    <tr class="border-t border-gray-50 hover:bg-gray-50 transition-colors <?= !$s['user_is_active'] ? 'opacity-60' : '' ?>">
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 bg-[#1e2d4a] rounded-full flex items-center justify-center text-white text-sm font-black flex-shrink-0">
                                    <?= strtoupper(substr($s['user_first_name'] ?? 'S', 0, 1)) ?>
                                </div>
                                <div>
                                    <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($s['user_first_name'] . ' ' . $s['user_last_name']) ?></p>
                                    <p class="text-xs text-gray-400">@<?= htmlspecialchars($s['user_name']) ?></p>
                                    <?php if (!empty($s['user_staff_id'])): ?>
                                    <p class="text-xs font-mono bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded mt-0.5 inline-block">ID: <?= htmlspecialchars($s['user_staff_id']) ?></p>
                                    <?php endif; ?>
                                    
                                </div>
                            </div>
                        </td>
                        <td class="px-5 py-4">
                            <p class="text-sm text-gray-600"><?= htmlspecialchars($s['user_gmail']) ?></p>
                            <p class="text-xs text-gray-400"><?= htmlspecialchars($s['user_phone'] ?? '—') ?></p>
                        </td>
                        <td class="px-5 py-4 text-sm text-gray-500">
                            <?= date('d M Y', strtotime($s['user_created_at'])) ?>
                        </td>
                        <td class="px-5 py-4">
                            <span class="<?= $s['user_is_active'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?> text-xs px-2 py-1 rounded-full font-semibold">
                                <?= $s['user_is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex gap-2">
                                <form method="POST" class="inline">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="user_id" value="<?= (int) $s['user_id'] ?>">
                                    <input type="hidden" name="is_active" value="<?= $s['user_is_active'] ? 0 : 1 ?>">
                                    <button type="submit"
                                            class="text-xs px-3 py-1.5 border rounded-lg transition-colors <?= $s['user_is_active'] ? 'border-red-200 text-red-600 hover:bg-red-50' : 'border-green-200 text-green-600 hover:bg-green-50' ?>">
                                        <?= $s['user_is_active'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                                <button onclick="openResetModal(<?= (int) $s['user_id'] ?>, '<?= htmlspecialchars($s['user_name']) ?>')"
                                        class="text-xs px-3 py-1.5 border border-blue-200 text-blue-600 rounded-lg hover:bg-blue-50 transition-colors">
                                    🔑 Reset PW
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Staff Modal -->
    <div id="addModal" class="modal fixed inset-0 bg-black/50 z-50 items-center justify-center px-4">
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl max-h-[90vh] overflow-y-auto">
            <div class="p-5 border-b border-gray-100 flex justify-between items-center">
                <h3 class="font-black text-gray-800">Add Staff Account</h3>
                <button onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <form
                method="POST"
                id="addStaffForm"
                class="p-5 space-y-4"
            >
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="add">
                <div
                    id="addStaffError"
                    class="hidden bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl"
                    role="alert"
                ></div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">First Name *</label>
                        <input type="text" name="user_first_name" maxlength="50" required
                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Last Name</label>
                        <input type="text" name="user_last_name" maxlength="50"
                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Username *</label>
                    <input type="text" name="user_name" maxlength="50" required
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Email *</label>
                    <input type="email" name="user_gmail" maxlength="100" required
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Phone</label>
                    <input type="text" name="user_phone" maxlength="11"
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                </div>
                <div>
                    <label
                        for="addStaffPassword"
                        class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide"
                    >
                        Password *
                    </label>

                    <div class="relative">
                        <input
                            type="password"
                            id="addStaffPassword"
                            name="password"
                            minlength="8"
                            maxlength="72"
                            required
                            autocomplete="new-password"
                            class="w-full px-4 py-3 pr-12 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white"
                        >

                        <button
                            type="button"
                            onclick="togglePasswordVisibility(
                                'addStaffPassword',
                                this
                            )"
                            aria-label="Show password"
                            class="absolute inset-y-0 right-0 flex items-center justify-center w-12 text-gray-400 hover:text-gray-700"
                        >
                            <svg
                                data-eye-open
                                class="w-5 h-5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M2.25 12s3.5-6 9.75-6 9.75 6 9.75 6-3.5 6-9.75 6S2.25 12 2.25 12Z"
                                ></path>
                                <circle
                                    cx="12"
                                    cy="12"
                                    r="3"
                                    stroke-width="2"
                                ></circle>
                            </svg>

                            <svg
                                data-eye-closed
                                class="hidden w-5 h-5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                                ria-hidden="true"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8M9.9 4.3A10.9 10.9 0 0112 4c6.25 0 9.75 8 9.75 8a17.3 17.3 0 01-2.1 3.3M6.2 6.2C3.7 8.1 2.25 12 2.25 12S5.75 20 12 20a9.7 9.7 0 004.1-.9"
                                ></path>
                            </svg>
                        </button>
                    </div>

                    <p class="text-xs text-gray-400 mt-1.5">
                        Use 8–72 characters with uppercase, lowercase,
                        number and symbol.
                    </p>
                </div>
                <div class="flex gap-3">
                    <button type="button" onclick="closeAddModal()"
                            class="flex-1 py-3 border-2 border-gray-100 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50">Cancel</button>
                    <button
                        type="submit"
                        id="addStaffSubmitButton"
                        class="flex-1 py-3 bg-red-600 hover:bg-red-700 disabled:bg-red-300 disabled:cursor-not-allowed text-white rounded-xl text-sm font-semibold transition-colors"
                    >
                        Add Staff
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div id="resetModal" class="modal fixed inset-0 bg-black/50 z-50 items-center justify-center px-4">
        <div class="bg-white rounded-2xl w-full max-w-sm shadow-2xl p-6">
            <h3 class="font-black text-gray-800 mb-1">Reset Password</h3>
            <p class="text-sm text-gray-400 mb-4">For: <span id="resetUsername" class="font-semibold text-gray-700"></span></p>
            <form method="POST" class="space-y-4">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetUserId">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">New Password *</label>
                    <input type="password" name="new_password" minlength="8" maxlength="72" required placeholder="8-72 characters"
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                </div>
                <div class="flex gap-3">
                    <button type="button" onclick="closeResetModal()"
                            class="flex-1 py-3 border-2 border-gray-100 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50">Cancel</button>
                    <button type="submit"
                            class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors">Reset</button>
                </div>
            </form>
        </div>
    </div>

    <script src="../assets/js/password-toggle.js" defer></script>

    <script>
    function openAddModal() { document.getElementById('addModal').classList.add('active'); }
    function closeAddModal() { document.getElementById('addModal').classList.remove('active'); }
    function openResetModal(id, name) {
        document.getElementById('resetUserId').value = id;
        document.getElementById('resetUsername').textContent = name;
        document.getElementById('resetModal').classList.add('active');
    }
    function closeResetModal() { document.getElementById('resetModal').classList.remove('active'); }
        document.getElementById('addModal').addEventListener('click', function(e) { if (e.target === this) closeAddModal(); });
        document.getElementById('resetModal').addEventListener('click', function(e) { if (e.target === this) closeResetModal(); });

    function togglePasswordVisibility(
        inputId,
        button
    ) {
        const input =
            document.getElementById(inputId);

        const showPassword =
            input.type === 'password';

        input.type =
            showPassword
                ? 'text'
                : 'password';

        button.setAttribute(
            'aria-label',
            showPassword
                ? 'Hide password'
                : 'Show password'
        );

        button
            .querySelector('[data-eye-open]')
            .classList
            .toggle(
                'hidden',
                showPassword
            );

        button
            .querySelector('[data-eye-closed]')
            .classList
            .toggle(
                'hidden',
                !showPassword
            );
    }

    const addStaffForm =
        document.getElementById(
            'addStaffForm'
        );

    const addStaffError =
        document.getElementById(
            'addStaffError'
        );

    const addStaffSubmitButton =
        document.getElementById(
            'addStaffSubmitButton'
        );

    addStaffForm.addEventListener(
        'submit',
        async event => {
            event.preventDefault();

            if (!addStaffForm.reportValidity()) {
                return;
            }

            addStaffError
                .classList
                .add('hidden');

            addStaffSubmitButton.disabled = true;
            addStaffSubmitButton.textContent =
                'Adding...';

            try {
                const response = await fetch(
                    'staff.php',
                    {
                        method: 'POST',
                        headers: {
                            'X-Requested-With':
                                'XMLHttpRequest',
                            'Accept':
                                'application/json'
                        },
                        body:
                            new FormData(
                                addStaffForm
                            )
                    }
                );

                const result =
                    await response.json();

                if (
                    !response.ok ||
                    !result.success
                ) {
                    throw new Error(
                        result.message ||
                        'Unable to create staff account.'
                    );
                }

                window.location.reload();
            } catch (error) {
                addStaffError.textContent =
                    '❌ ' +
                    (
                        error.message ||
                        'Unable to create staff account.'
                    );

                addStaffError
                    .classList
                    .remove('hidden');

                addStaffError.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest'
                });
            } finally {
                addStaffSubmitButton.disabled =
                    false;

                addStaffSubmitButton.textContent =
                    'Add Staff';
            }
        }
    );
    </script>
</body>
</html>