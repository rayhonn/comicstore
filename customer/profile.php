<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';

require_customer();

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $first_name = trim($_POST['user_first_name']);
        $last_name = trim($_POST['user_last_name']);
        $phone = trim($_POST['user_phone']);
        $new_dob = trim($_POST['user_dob'] ?? '');

        if (empty($first_name)) {
            $error = "First name is required.";
        } elseif ($phone && !preg_match('/^01[0-9]{8,9}$/', $phone)) {
            $error = "Please enter a valid Malaysian phone number.";
        } else {
            $dob_to_save = $user['user_dob'];
            $dob_changed_flag = $user['user_dob_changed'];

            if (!empty($new_dob) && !$user['user_dob_changed']) {
                $dob_obj = new DateTime($new_dob);
                $today = new DateTime();
                $age = $today->diff($dob_obj)->y;
                if ($age < 13) {
                    $error = "You must be at least 13 years old.";
                } else {
                    $is_changing = !empty($user['user_dob']) && $new_dob !== $user['user_dob'];
                    $dob_to_save = $new_dob;
                    $dob_changed_flag = $is_changing ? 1 : $user['user_dob_changed'];
                }
            }

            if (empty($error)) {
                $pdo->prepare("UPDATE users SET user_first_name = ?, user_last_name = ?, user_phone = ?, user_dob = ?, user_dob_changed = ? WHERE user_id = ?")
                    ->execute([$first_name, $last_name, $phone, $dob_to_save, $dob_changed_flag, $user_id]);

                if (!empty($new_dob) && !$user['user_dob_changed'] && !empty($user['user_dob']) && $new_dob !== $user['user_dob']) {
                    $success = "Profile updated! Date of birth has been changed and is now locked.";
                } else {
                    $success = "Profile updated successfully!";
                }

                $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }

    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'];
        $new = $_POST['new_password'];
        $confirm = $_POST['confirm_password'];

        if (!password_verify($current, $user['user_password_hash'])) {
            $error = "Current password is incorrect.";
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/', $new)) {
            $error = "New password must be at least 8 characters with uppercase, lowercase, number and symbol.";
        } elseif ($new !== $confirm) {
            $error = "New passwords do not match.";
        } else {
            $pdo->prepare("UPDATE users SET user_password_hash = ? WHERE user_id = ?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), $user_id]);
            $success = "Password changed successfully!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - MangaVault</title>
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
            <span class="text-gray-600">My Profile</span>
        </p>

        <div class="flex gap-8 items-start">
            <?php include '../includes/customer_sidebar.php'; ?>

            <div class="flex-1 min-w-0">

                <?php if ($error): ?>
                    <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-5"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="bg-green-50 border border-green-200 text-green-600 text-sm px-4 py-3 rounded-xl mb-5"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                    <!-- Personal Info -->
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h3 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
                            <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                            Personal Information
                        </h3>
                        <form method="POST" class="space-y-4">
                            <?php csrf_field() ?>
                            <input type="hidden" name="action" value="update_profile">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">First Name *</label>
                                    <input type="text" name="user_first_name" required
                                           value="<?= htmlspecialchars($user['user_first_name'] ?? '') ?>"
                                           class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Last Name</label>
                                    <input type="text" name="user_last_name"
                                           value="<?= htmlspecialchars($user['user_last_name'] ?? '') ?>"
                                           class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Username</label>
                                <input type="text" value="<?= htmlspecialchars($user['user_name']) ?>" disabled
                                       class="w-full px-3 py-2.5 border border-gray-100 rounded-lg text-sm bg-gray-50 text-gray-400 cursor-not-allowed">
                                <p class="text-xs text-gray-400 mt-1">Username cannot be changed.</p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Email</label>
                                <input type="text" value="<?= htmlspecialchars($user['user_gmail']) ?>" disabled
                                       class="w-full px-3 py-2.5 border border-gray-100 rounded-lg text-sm bg-gray-50 text-gray-400 cursor-not-allowed">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Phone</label>
                                <input type="text" name="user_phone"
                                       value="<?= htmlspecialchars($user['user_phone'] ?? '') ?>"
                                       oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                                       maxlength="11"
                                       class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors"
                                       placeholder="e.g. 01234567890">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">
                                    Date of Birth
                                    <?php if ($user['user_dob_changed']): ?>
                                        <span class="text-red-400 font-normal">🔒 Locked</span>
                                    <?php else: ?>
                                        <span class="text-yellow-500 font-normal">(can only be changed once)</span>
                                    <?php endif; ?>
                                </label>
                                <?php if ($user['user_dob_changed']): ?>
                                    <input type="text"
                                           value="<?= !empty($user['user_dob']) ? date('d F Y', strtotime($user['user_dob'])) : 'Not set' ?>"
                                           disabled
                                           class="w-full px-3 py-2.5 border border-gray-100 rounded-lg text-sm bg-gray-50 text-gray-400 cursor-not-allowed">
                                <?php else: ?>
                                    <input type="date" name="user_dob"
                                           value="<?= htmlspecialchars($user['user_dob'] ?? '') ?>"
                                           max="<?= date('Y-m-d', strtotime('-13 years')) ?>"
                                           class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors">
                                    <?php if (!empty($user['user_dob'])): ?>
                                        <p class="text-xs text-yellow-600 mt-1">⚠️ Changing this will lock it permanently.</p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <button type="submit"
                                    <?php if (!empty($user['user_dob']) && !$user['user_dob_changed']): ?>
                                    onclick="return checkDobChange(this.form)"
                                    <?php endif; ?>
                                    class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-2.5 rounded-lg text-sm transition-colors duration-200">
                                Update Profile
                            </button>
                        </form>
                    </div>

                    <!-- Change Password -->
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h3 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
                            <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                            Change Password
                        </h3>
                        <form method="POST" class="space-y-4">
                            <?php csrf_field() ?>
                            <input type="hidden" name="action" value="change_password">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Current Password *</label>
                                <input type="password" name="current_password" required
                                       class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">New Password *</label>
                                <input type="password" name="new_password" id="newPassInput" required
                                       class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors"
                                       placeholder="Min 8 chars, upper, lower, number, symbol">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Confirm New Password *</label>
                                <input type="password" name="confirm_password" id="confirmPassInput" required
                                       class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors">
                                <p id="passMatchMsg" class="text-xs mt-1 hidden"></p>
                            </div>
                            <button type="submit"
                                    class="w-full bg-[#1e2d4a] hover:bg-[#162338] text-white font-semibold py-2.5 rounded-lg text-sm transition-colors duration-200">
                                Change Password
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Address Book shortcut -->
                <div class="bg-white rounded-2xl shadow-sm p-6 mt-6">
                    <div class="flex items-center justify-between">
                        <h3 class="font-bold text-gray-800 flex items-center gap-2">
                            <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                            Address Book
                        </h3>
                        <a href="addresses.php"
                           class="bg-red-600 hover:bg-red-700 text-white font-semibold px-4 py-2 rounded-xl text-sm transition-colors flex items-center gap-2">
                            📍 Manage Addresses
                        </a>
                    </div>
                    <p class="text-sm text-gray-400 mt-2">Add, edit or remove your delivery addresses from the Addresses page.</p>
                </div>

            </div>
        </div>
    </div>

    <script>
    const newPassInput = document.getElementById('newPassInput');
    const confirmPassInput = document.getElementById('confirmPassInput');
    const passMatchMsg = document.getElementById('passMatchMsg');

    function checkPassMatch() {
        if (confirmPassInput.value === '') { passMatchMsg.classList.add('hidden'); return; }
        if (newPassInput.value === confirmPassInput.value) {
            passMatchMsg.textContent = '✓ Passwords match';
            passMatchMsg.className = 'text-xs mt-1 text-green-600';
        } else {
            passMatchMsg.textContent = '✗ Passwords do not match';
            passMatchMsg.className = 'text-xs mt-1 text-red-500';
        }
        passMatchMsg.classList.remove('hidden');
    }

    newPassInput.addEventListener('input', checkPassMatch);
    confirmPassInput.addEventListener('input', checkPassMatch);

    function checkDobChange(form) {
        const dobInput = form.querySelector('[name="user_dob"]');
        const originalDob = '<?= $user['user_dob'] ?? '' ?>';
        if (dobInput && dobInput.value && dobInput.value !== originalDob) {
            return confirm('Changing your date of birth will lock it permanently and cannot be undone. Are you sure?');
        }
        return true;
    }
    </script>

</body>
</html>