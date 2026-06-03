<?php
$sidebar_user = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$sidebar_user->execute([$_SESSION['user_id']]);
$sidebar_user = $sidebar_user->fetch(PDO::FETCH_ASSOC);

$wishlist_count = $pdo->prepare("SELECT COUNT(*) FROM wishlist WHERE wishlist_user_id = ?");
$wishlist_count->execute([$_SESSION['user_id']]);
$wishlist_count = $wishlist_count->fetchColumn();

$notif_count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE notif_user_id = ? AND notif_is_read = 0");
$notif_count->execute([$_SESSION['user_id']]);
$notif_count = $notif_count->fetchColumn();

$current_page = basename($_SERVER['PHP_SELF']);
$initials = strtoupper(substr($sidebar_user['user_first_name'] ?? 'U', 0, 1) . substr($sidebar_user['user_last_name'] ?? '', 0, 1));
?>
<div class="w-56 flex-shrink-0 hidden md:block">
    <div class="bg-white rounded-2xl shadow-sm overflow-hidden sticky top-24">
        <div class="bg-[#F5F0EB] px-6 py-6 text-center border-b border-gray-100">
            <div class="w-14 h-14 bg-red-600 text-white rounded-full flex items-center justify-center text-xl font-black mx-auto mb-3">
                <?= $initials ?>
            </div>
            <p class="font-bold text-sm text-gray-800"><?= htmlspecialchars($sidebar_user['user_first_name'] . ' ' . $sidebar_user['user_last_name']) ?></p>
            <p class="text-xs text-gray-400 mt-1 truncate"><?= htmlspecialchars($sidebar_user['user_gmail']) ?></p>
        </div>
        <ul class="py-2">
            <?php
            $menu = [
                ['file' => 'dashboard.php', 'label' => 'Dashboard', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>'],
                ['file' => 'orders.php', 'label' => 'Order History', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>'],
                ['file' => 'wishlist.php', 'label' => 'Wishlist', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>', 'badge' => $wishlist_count],
                ['file' => 'collection.php', 'label' => 'My Collection', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>'],
                ['file' => 'returns.php', 'label' => 'Returns', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path>'],
                ['file' => 'addresses.php', 'label' => 'My Addresses', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>'],
                ['file' => 'payment_methods.php', 'label' => 'Payment Methods', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>'],
                ['file' => 'notifications.php', 'label' => 'Notifications', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>', 'badge' => $notif_count],
                ['file' => 'vouchers.php', 'label' => 'Vouchers & Points', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"></path>'],
                ['file' => 'my_reviews.php', 'label' => 'My Reviews', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path>'],
                ['file' => 'profile.php', 'label' => 'My Profile', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>'],
            ];
            foreach ($menu as $item):
                $active = $current_page === $item['file'];
            ?>
            <li>
                <a href="<?= $item['file'] ?>"
                   class="flex items-center gap-3 px-5 py-3 text-sm <?= $active ? 'text-red-600 bg-red-50 border-l-4 border-red-600 font-semibold' : 'text-gray-600 hover:text-red-600 hover:bg-gray-50 border-l-4 border-transparent' ?> transition-all duration-200">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $item['icon'] ?></svg>
                    <span class="flex-1"><?= $item['label'] ?></span>
                    <?php if (!empty($item['badge']) && $item['badge'] > 0): ?>
                        <span class="bg-red-100 text-red-600 text-xs px-1.5 py-0.5 rounded-full font-semibold"><?= $item['badge'] ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
            <li class="border-t border-gray-100 mt-1">
                <button onclick="confirmLogout()" class="w-full flex items-center gap-3 px-5 py-3 text-sm text-gray-400 hover:text-red-600 hover:bg-gray-50 border-l-4 border-transparent transition-all duration-200">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                    Sign Out
                </button>
            </li>
        </ul>
    </div>
</div>

<!-- Logout Modal -->
<div id="logoutModal" class="hidden fixed inset-0 bg-black/50 z-[100] flex items-center justify-center px-6">
    <div class="bg-white rounded-2xl p-8 max-w-sm w-full shadow-2xl text-center">
        <div class="w-14 h-14 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-7 h-7 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
            </svg>
        </div>
        <h3 class="text-xl font-black text-gray-800 mb-2">Logging Out?</h3>
        <p class="text-sm text-gray-500 mb-6">Are you sure you want to log out of MangaVault?</p>
        <div class="flex gap-3">
            <button onclick="closeLogoutModal()"
                    class="flex-1 py-3 border-2 border-gray-100 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors">
                Cancel
            </button>
            <a href="../logout.php"
               class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors text-center block">
                Yes, Sign Out
            </a>
        </div>
    </div>
</div>

<script>
function confirmLogout() {
    document.getElementById('logoutModal').classList.remove('hidden');
}
function closeLogoutModal() {
    document.getElementById('logoutModal').classList.add('hidden');
}
document.getElementById('logoutModal').addEventListener('click', function(e) {
    if (e.target === this) closeLogoutModal();
});
</script>