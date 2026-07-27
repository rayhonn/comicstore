<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

$user_id = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? null;

    if ($action === 'mark_all_read') {
        $mark_all = $pdo->prepare("
            UPDATE notifications
            SET notif_is_read = 1
            WHERE notif_user_id = ?
            AND notif_is_read = 0
        ");
        $mark_all->execute([$user_id]);
    } elseif ($action === 'mark_read') {
        $notification_id = filter_var(
            $_POST['notification_id'] ?? null,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                ],
            ]
        );

        if (
            $notification_id !== false &&
            $notification_id !== null
        ) {
            $mark_read = $pdo->prepare("
                UPDATE notifications
                SET notif_is_read = 1
                WHERE notif_id = ?
                AND notif_user_id = ?
            ");
            $mark_read->execute([
                $notification_id,
                $user_id,
            ]);
        }
    }

    header('Location: notifications.php');
    exit;
}

$notification_query = $pdo->prepare("
    SELECT *
    FROM notifications
    WHERE notif_user_id = ?
    ORDER BY notif_created_at DESC
");
$notification_query->execute([$user_id]);
$notifications =
    $notification_query->fetchAll(PDO::FETCH_ASSOC);

$unread_count = count(
    array_filter(
        $notifications,
        static fn(array $notification): bool =>
            !(bool) $notification['notif_is_read']
    )
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html { scroll-behavior: smooth; }
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">
        <p class="text-sm text-gray-400 mb-6">
            <a href="../index.php" class="hover:text-red-600 transition-colors">Home</a>
            <span class="mx-2">›</span>
            <a href="dashboard.php" class="hover:text-red-600 transition-colors">My Account</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">Notifications</span>
        </p>

        <div class="flex gap-8 items-start">
            <?php include '../includes/customer_sidebar.php'; ?>

            <div class="flex-1 min-w-0">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-xl font-black text-gray-800">Notifications</h1>
                        <?php if ($unread_count > 0): ?>
                            <p class="text-sm text-gray-400"><?= $unread_count ?> unread</p>
                        <?php endif; ?>
                    </div>

                    <?php if ($unread_count > 0): ?>
                        <form method="POST">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="mark_all_read">
                            <button
                                type="submit"
                                class="text-xs text-red-600 hover:underline font-medium"
                            >
                                Mark all as read
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if (count($notifications) === 0): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                        <div class="text-5xl mb-4">🔔</div>
                        <p class="text-gray-500 font-medium mb-1">No notifications yet</p>
                        <p class="text-gray-400 text-sm">We'll notify you about orders, new releases and more.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($notifications as $notification): ?>
                        <form method="POST">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="mark_read">
                            <input
                                type="hidden"
                                name="notification_id"
                                value="<?= (int) $notification['notif_id'] ?>"
                            >
                            <button
                                type="submit"
                                class="text-left w-full block bg-white rounded-2xl shadow-sm p-4 hover:shadow-md transition-all duration-200 <?= !(int) $notification['notif_is_read'] ? 'border-l-4 border-red-500' : '' ?>"
                            >
                                <div class="flex items-start gap-4">
                                    <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 text-lg
                                        <?= $notification['notif_type'] === 'order' ? 'bg-blue-50' :
                                           ($notification['notif_type'] === 'return' ? 'bg-orange-50' :
                                           ($notification['notif_type'] === 'promo' ? 'bg-yellow-50' : 'bg-gray-50')) ?>">
                                        <?= $notification['notif_type'] === 'order' ? '📦' :
                                           ($notification['notif_type'] === 'return' ? '↩️' :
                                           ($notification['notif_type'] === 'promo' ? '🎉' : '🔔')) ?>
                                    </div>

                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-start justify-between gap-2">
                                            <p class="font-semibold text-sm text-gray-800">
                                                <?= htmlspecialchars(
                                                    $notification['notif_title'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </p>

                                            <?php if (!(int) $notification['notif_is_read']): ?>
                                                <span class="w-2 h-2 bg-red-600 rounded-full flex-shrink-0 mt-1.5"></span>
                                            <?php endif; ?>
                                        </div>

                                        <p class="text-sm text-gray-500 mt-0.5">
                                            <?= htmlspecialchars(
                                                $notification['notif_message'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>

                                        <p class="text-xs text-gray-400 mt-1">
                                            <?= date(
                                                'd M Y, h:i A',
                                                strtotime(
                                                    $notification['notif_created_at']
                                                )
                                            ) ?>
                                        </p>
                                    </div>
                                </div>
                            </button>
                        </form>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>