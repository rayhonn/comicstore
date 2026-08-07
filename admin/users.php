<?php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $user_id = filter_var(
        $_POST['user_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    $action = $_POST['action'] ?? null;

    if (
        $user_id === false ||
        $user_id === null ||
        !is_string($action) ||
        !in_array(
            $action,
            [
                'activate',
                'deactivate',
            ],
            true
        )
    ) {
        header('Location: users.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        $customerLock = $pdo->prepare("
            SELECT
                user_is_active,
                user_deleted_at
            FROM users
            WHERE user_id = ?
            AND user_role = 'customer'
            FOR UPDATE
        ");
        $customerLock->execute([
            $user_id,
        ]);

        $customer =
            $customerLock->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$customer) {
            throw new RuntimeException(
                'Customer account not found.'
            );
        }

        $wasDeletedByCustomer =
            $customer['user_deleted_at'] !== null;

        if ($action === 'deactivate') {
            if (
                (int) $customer[
                    'user_is_active'
                ] !== 1
            ) {
                throw new RuntimeException(
                    'Customer account is already inactive.'
                );
            }

            $update = $pdo->prepare("
                UPDATE users
                SET user_is_active = 0
                WHERE user_id = ?
                AND user_role = 'customer'
                AND user_is_active = 1
            ");
            $update->execute([
                $user_id,
            ]);

            $log_action =
                'deactivate_user';

            $log_details =
                'Customer account deactivated';
        } else {
            if (
                $wasDeletedByCustomer &&
                !is_senior_admin()
            ) {
                throw new RuntimeException(
                    'Only a Super Admin can restore a customer account that was closed through the account deletion process.'
                );
            }

            if (
                (int) $customer[
                    'user_is_active'
                ] === 1 &&
                !$wasDeletedByCustomer
            ) {
                throw new RuntimeException(
                    'Customer account is already active.'
                );
            }

            $update = $pdo->prepare("
                UPDATE users
                SET
                    user_is_active = 1,
                    user_deleted_at = NULL
                WHERE user_id = ?
                AND user_role = 'customer'
            ");
            $update->execute([
                $user_id,
            ]);

            $log_action =
                $wasDeletedByCustomer
                    ? 'restore_deleted_user'
                    : 'activate_user';

            $log_details =
                $wasDeletedByCustomer
                    ? 'Customer self-deleted account restored'
                    : 'Customer account activated';
        }

        if ($update->rowCount() !== 1) {
            throw new RuntimeException(
                'Customer account status was not updated.'
            );
        }

        $log = $pdo->prepare("
            INSERT INTO admin_logs (
                log_admin_id,
                log_action,
                log_target_type,
                log_target_id,
                log_details
            )
            VALUES (?, ?, 'user', ?, ?)
        ");
        $log->execute([
            (int) $_SESSION['user_id'],
            $log_action,
            $user_id,
            $log_details,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        app_error_log(
            'Customer status update failed: ' .
            $e->getMessage()
        );

        header(
            'Location: users.php?error=1'
        );
        exit;
    }

    header(
        'Location: users.php?success=1'
    );
    exit;
}

$search_raw = $_GET['search'] ?? '';

if (!is_string($search_raw)) {
    $search_raw = '';
}

$search = trim($search_raw);
$search_length = function_exists('mb_strlen')
    ? mb_strlen($search, 'UTF-8')
    : strlen($search);

if ($search_length > 100) {
    $error = 'Search cannot exceed 100 characters.';
    $search = '';
}

$sql = "
    SELECT
        u.*,
        COUNT(DISTINCT o.order_id) AS total_orders,
        COALESCE(
            SUM(
                CASE
                    WHEN o.order_payment_status = 'confirmed'
                    THEN o.order_total_amount
                    ELSE 0
                END
            ),
            0
        ) AS total_spent
    FROM users u
    LEFT JOIN orders o
        ON u.user_id = o.order_user_id
    WHERE u.user_role = 'customer'
";

$params = [];

if ($search !== '') {
    $sql .= "
        AND (
            u.user_name LIKE ?
            OR u.user_first_name LIKE ?
            OR u.user_last_name LIKE ?
            OR u.user_gmail LIKE ?
        )
    ";
    $params = array_fill(0, 4, '%' . $search . '%');
}

$sql .= "
    GROUP BY u.user_id
    ORDER BY u.user_created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_users = (int) $pdo->query("
    SELECT COUNT(*)
    FROM users
    WHERE user_role = 'customer'
")->fetchColumn();

$active_users = (int) $pdo->query("
    SELECT COUNT(*)
    FROM users
    WHERE user_role = 'customer'
    AND user_is_active = 1
")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">

        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-black text-gray-800">Manage Customers</h1>
                <p class="text-sm text-gray-400 mt-0.5"><?= $total_users ?> total · <?= $active_users ?> active</p>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5">✅ User updated.</div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-5">❌ Unable to update the customer account.</div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-5">
            ❌ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl shadow-sm p-4 mb-6">
            <form method="GET" class="flex gap-3">
                <input type="text"
                       name="search"
                       maxlength="100"
                       value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Search name, username or email..."
                       class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors">
                <button type="submit" class="bg-[#1e2d4a] hover:bg-[#162338] text-white px-5 py-2.5 rounded-xl text-sm font-semibold transition-colors">
                    Search
                </button>
                <?php if ($search !== ''): ?>
                <a href="users.php" class="text-sm text-gray-400 hover:text-red-600 transition-colors flex items-center">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (count($users) === 0): ?>
        <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
            <div class="text-5xl mb-4">👥</div>
            <p class="text-gray-500 font-medium">No customers found.</p>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Customer</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Contact</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Orders</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Total Spent</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Joined</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr class="border-t border-gray-50 hover:bg-gray-50 transition-colors <?= !(int) $user['user_is_active'] ? 'opacity-60' : '' ?>">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 bg-red-600 rounded-full flex items-center justify-center text-white text-sm font-black flex-shrink-0">
                                    <?= strtoupper(substr((string) ($user['user_first_name'] ?? 'U'), 0, 1)) ?>
                                </div>
                                <div>
                                    <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($user['user_first_name'] . ' ' . $user['user_last_name'], ENT_QUOTES, 'UTF-8') ?></p>
                                    <p class="text-xs text-gray-400">@<?= htmlspecialchars($user['user_name'], ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <p class="text-sm text-gray-600"><?= htmlspecialchars($user['user_gmail'], ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="text-xs text-gray-400"><?= htmlspecialchars($user['user_phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?></p>
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-semibold text-sm text-gray-800"><?= (int) $user['total_orders'] ?></span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-semibold text-sm text-green-600">RM <?= number_format((float) $user['total_spent'], 2) ?></span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-500">
                            <?= date('d M Y', strtotime($user['user_created_at'])) ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php if (
                                !empty(
                                    $user[
                                        'user_deleted_at'
                                    ]
                                )
                            ): ?>
                            <span class="bg-gray-100 text-gray-600 text-xs px-2 py-1 rounded-full font-semibold">
                                Deleted by Customer
                            </span>
                            <?php elseif (
                                (int) $user[
                                    'user_is_active'
                                ] === 1
                            ): ?>
                            <span class="bg-green-100 text-green-700 text-xs px-2 py-1 rounded-full font-semibold">
                                Active
                            </span>
                            <?php else: ?>
                            <span class="bg-red-100 text-red-700 text-xs px-2 py-1 rounded-full font-semibold">
                                Inactive
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php if (
                                !empty(
                                    $user[
                                        'user_deleted_at'
                                    ]
                                ) &&
                                !is_senior_admin()
                            ): ?>

                            <span
                                class="text-xs px-3 py-1.5 bg-gray-100 text-gray-500 rounded-lg font-semibold"
                            >
                                Super Admin Required
                            </span>

                            <?php else: ?>

                            <form
                                method="POST"
                                class="inline"
                            >
                                <?php csrf_field(); ?>

                                <input
                                    type="hidden"
                                    name="user_id"
                                    value="<?= (int) $user['user_id'] ?>"
                                >

                                <?php if (
                                    (int) $user[
                                        'user_is_active'
                                    ] === 1
                                ): ?>

                                <input
                                    type="hidden"
                                    name="action"
                                    value="deactivate"
                                >

                                <button
                                    type="submit"
                                    onclick="return confirm('Deactivate this customer account?')"
                                    class="text-xs px-3 py-1.5 border border-red-200 text-red-600 rounded-lg hover:bg-red-50 transition-colors"
                                >
                                    Deactivate
                                </button>

                                <?php else: ?>

                                <input
                                    type="hidden"
                                    name="action"
                                    value="activate"
                                >

                                <button
                                    type="submit"
                                    onclick="return confirm('<?= !empty($user['user_deleted_at'])
                                        ? 'Restore this deleted customer account?'
                                        : 'Activate this customer account?' ?>')"
                                    class="text-xs px-3 py-1.5 border border-green-200 text-green-600 rounded-lg hover:bg-green-50 transition-colors"
                                >
                                    <?= !empty(
                                        $user[
                                            'user_deleted_at'
                                        ]
                                    )
                                        ? 'Restore Account'
                                        : 'Activate' ?>
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
        <?php endif; ?>
    </div>

</body>
</html>