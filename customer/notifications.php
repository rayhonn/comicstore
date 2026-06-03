<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];

// Mark all as read
if (isset($_GET['mark_all_read'])) {
    $pdo->prepare("UPDATE notifications SET notif_is_read = 1 WHERE notif_user_id = ?")
        ->execute([$user_id]);
    header('Location: notifications.php');
    exit;
}

// Mark single as read
if (isset($_GET['read'])) {
    $pdo->prepare("UPDATE notifications SET notif_is_read = 1 WHERE notif_id = ? AND notif_user_id = ?")
        ->execute([$_GET['read'], $user_id]);
}

// Get all notifications
$notifications = $pdo->prepare("
    SELECT * FROM notifications
    WHERE notif_user_id = ?
    ORDER BY notif_created_at DESC
");
$notifications->execute([$user_id]);
$notifications = $notifications->fetchAll(PDO::FETCH_ASSOC);

$unread_count = count(array_filter($notifications, fn($n) => !$n['notif_is_read']));
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
                        <a href="notifications.php?mark_all_read=1"
                           class="text-xs text-red-600 hover:underline font-medium">
                            Mark all as read
                        </a>
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
                        <?php foreach ($notifications as $notif): ?>
                        <a href="notifications.php?read=<?= $notif['notif_id'] ?>"
                           class="block bg-white rounded-2xl shadow-sm p-4 hover:shadow-md transition-all duration-200 <?= !$notif['notif_is_read'] ? 'border-l-4 border-red-500' : '' ?>">
                            <div class="flex items-start gap-4">
                                <!-- Icon -->
                                <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 text-lg
                                    <?= $notif['notif_type'] === 'order' ? 'bg-blue-50' :
                                       ($notif['notif_type'] === 'return' ? 'bg-orange-50' :
                                       ($notif['notif_type'] === 'promo' ? 'bg-yellow-50' : 'bg-gray-50')) ?>">
                                    <?= $notif['notif_type'] === 'order' ? '📦' :
                                       ($notif['notif_type'] === 'return' ? '↩️' :
                                       ($notif['notif_type'] === 'promo' ? '🎉' : '🔔')) ?>
                                </div>

                                <!-- Content -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($notif['notif_title']) ?></p>
                                        <?php if (!$notif['notif_is_read']): ?>
                                            <span class="w-2 h-2 bg-red-600 rounded-full flex-shrink-0 mt-1.5"></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-sm text-gray-500 mt-0.5"><?= htmlspecialchars($notif['notif_message']) ?></p>
                                    <p class="text-xs text-gray-400 mt-1"><?= date('d M Y, h:i A', strtotime($notif['notif_created_at'])) ?></p>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>