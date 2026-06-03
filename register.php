<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/notifications.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_name = trim($_POST['user_name']);
    $user_gmail = trim($_POST['user_gmail']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $user_first_name = trim($_POST['user_first_name']);
    $user_last_name = trim($_POST['user_last_name']);
    $user_phone = trim($_POST['user_phone']);
    $user_dob = trim($_POST['user_dob']);

    if (empty($user_name) || empty($user_gmail) || empty($password) || empty($user_first_name) || empty($user_dob)) {
        $error = "All required fields must be filled.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/', $password)) {
        $error = "Password must be at least 8 characters with uppercase, lowercase, number and symbol.";
    } elseif ($user_phone && !preg_match('/^01[0-9]{8,9}$/', $user_phone)) {
        $error = "Please enter a valid Malaysian phone number (e.g. 01234567890).";
    } elseif (empty($user_dob)) {
        $error = "Date of birth is required.";
    } else {
        // Validate age (must be at least 13)
        $dob = new DateTime($user_dob);
        $today = new DateTime();
        $age = $today->diff($dob)->y;
        if ($age < 13) {
            $error = "You must be at least 13 years old to register.";
        } else {
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE user_name = ? OR user_gmail = ?");
            $stmt->execute([$user_name, $user_gmail]);
            if ($stmt->rowCount() > 0) {
                $error = "Username or email already exists.";
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (user_name, user_gmail, user_password_hash, user_first_name, user_last_name, user_phone, user_dob, user_role) VALUES (?, ?, ?, ?, ?, ?, ?, 'customer')")
                    ->execute([$user_name, $user_gmail, $hashed, $user_first_name, $user_last_name, $user_phone, $user_dob]);

                $new_user_id = $pdo->lastInsertId();

                // Welcome notifications with voucher info
                sendNotification($pdo, $new_user_id,
                    'Welcome to MangaVault! 🎉',
                    "Hi $user_first_name! Your account has been created successfully. Start exploring thousands of manga titles now!",
                    'system'
                );
                sendNotification($pdo, $new_user_id,
                    '🎟️ Welcome Gift — 10% OFF!',
                    "Use code WELCOME10 for 10% off your first order (min RM20, max RM15 discount). Happy shopping!",
                    'promo'
                );
                sendNotification($pdo, $new_user_id,
                    '🎁 New Member Special — 20% OFF!',
                    "Exclusive for new members! Use code NEWUSER20 for 20% off (min RM50, max RM20 discount). One-time use only!",
                    'promo'
                );
                sendNotification($pdo, $new_user_id,
                    '🎊 New Member Gift — 50% OFF!',
                    "Welcome gift! Use code NEWMEMBER50 for 50% off your first order (min RM30, max RM25 discount). Valid for 1 month only!",
                    'promo'
                );

                // Auto-claim welcome vouchers with 1 month expiry
                $welcome_vouchers = $pdo->prepare("SELECT voucher_id FROM vouchers WHERE voucher_code IN ('WELCOME10', 'NEWUSER20', 'NEWMEMBER50') AND              voucher_is_active = 1");
                $welcome_vouchers->execute();
                $expires_at = date('Y-m-d H:i:s', strtotime('+1 month'));
                foreach ($welcome_vouchers->fetchAll(PDO::FETCH_ASSOC) as $wv) {
                    $pdo->prepare("INSERT IGNORE INTO user_vouchers (uv_user_id, uv_voucher_id, uv_expires_at) VALUES (?, ?, ?)")
                        ->execute([$new_user_id, $wv['voucher_id'], $expires_at]);
                }

                $success = "Registration successful! You can now login.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="flex h-screen overflow-hidden">

    <!-- Left Panel -->
    <div class="hidden md:flex lg:w-3/5 md:w-1/2 bg-[#1e2d4a] flex-col justify-center px-16 relative overflow-hidden">
        <div class="absolute -top-24 -right-24 w-80 h-80 bg-white opacity-5 rounded-full"></div>
        <div class="text-white text-xl font-bold mb-12">
            Manga<span class="text-red-600">Vault</span>
        </div>
        <h1 class="text-4xl font-bold text-white leading-tight mb-5">
            Your manga<br>journey <em class="text-red-500 not-italic">starts</em><br>here.
        </h1>
        <p class="text-white/60 text-sm leading-relaxed mb-10">
            Browse thousands of manga volumes and e-books. Track your collection. Never miss a new volume.
        </p>
        <ul class="space-y-3">
            <?php foreach(['Filter by series, genre, author', 'Instant e-book downloads', 'Collection tracker & wishlist', 'Order history & return requests'] as $feature): ?>
            <li class="flex items-center gap-3 text-white/80 text-sm">
                <span class="w-5 h-5 bg-red-600 rounded-full flex items-center justify-center text-white text-xs flex-shrink-0">✓</span>
                <?= $feature ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- Right Panel -->
    <div class="w-full md:w-1/2 lg:w-2/5 bg-white flex flex-col justify-center px-8 md:px-12 overflow-hidden py-4">
        <h2 class="text-2xl font-bold text-gray-900 mb-1">Create account</h2>
        <p class="text-sm text-gray-400 mb-6">
            Already have one? <a href="login.php" class="text-red-600 font-medium hover:underline">Sign in</a>
        </p>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-lg mb-4">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="bg-green-50 border border-green-200 text-green-600 text-sm px-4 py-3 rounded-lg mb-4">
                <?= htmlspecialchars($success) ?> <a href="login.php" class="font-medium underline">Login here</a>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-3" id="registerForm">
            <div class="flex gap-3">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-600 mb-1">First Name <span class="text-red-500">*</span></label>
                    <input type="text" name="user_first_name"
                           class="w-full px-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors"
                           placeholder="John" required>
                </div>
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-600 mb-1">Last Name</label>
                    <input type="text" name="user_last_name"
                           class="w-full px-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors"
                           placeholder="Doe">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-600 mb-1">Username <span class="text-red-500">*</span></label>
                <input type="text" name="user_name"
                       class="w-full px-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors"
                       placeholder="mangalover91" required>
                <p class="text-xs text-gray-400 mt-1">Used to identify your account publicly</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-600 mb-1">Email Address <span class="text-red-500">*</span></label>
                <input type="email" name="user_gmail"
                       class="w-full px-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors"
                       placeholder="you@example.com" required>
            </div>

            <!-- Date of Birth -->
            <div>
                <label class="block text-sm font-medium text-gray-600 mb-1">Date of Birth <span class="text-red-500">*</span>
                    <span class="text-yellow-600 text-xs font-medium ml-1">
                        ⚠️ Can only be changed once！
                    </span>
                </label>
                <input type="date" name="user_dob" id="dobInput"
                       max="<?= date('Y-m-d', strtotime('-13 years')) ?>"
                       class="w-full px-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors"
                       required>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-600 mb-1">Phone (optional)</label>
                <input type="text" name="user_phone" id="phoneInput"
                       class="w-full px-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors"
                       placeholder="01234567890" maxlength="11">
                <p class="text-xs text-gray-400 mt-1">Malaysian format e.g. 01234567890</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-600 mb-1">Password <span class="text-red-500">*</span></label>
                <input type="password" name="password" id="passwordInput"
                       class="w-full px-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors"
                       placeholder="Min 8 chars, uppercase, number & symbol" required>
                <div id="passwordStrength" class="mt-2 space-y-1 hidden">
                    <div id="check_length" class="flex items-center gap-2 text-xs text-gray-400">
                        <span class="check-icon">○</span><span>At least 8 characters</span>
                    </div>
                    <div id="check_upper" class="flex items-center gap-2 text-xs text-gray-400">
                        <span class="check-icon">○</span><span>One uppercase letter (A-Z)</span>
                    </div>
                    <div id="check_lower" class="flex items-center gap-2 text-xs text-gray-400">
                        <span class="check-icon">○</span><span>One lowercase letter (a-z)</span>
                    </div>
                    <div id="check_number" class="flex items-center gap-2 text-xs text-gray-400">
                        <span class="check-icon">○</span><span>One number (0-9)</span>
                    </div>
                    <div id="check_symbol" class="flex items-center gap-2 text-xs text-gray-400">
                        <span class="check-icon">○</span><span>One symbol (!@#$...)</span>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-600 mb-1">Confirm Password <span class="text-red-500">*</span></label>
                <input type="password" name="confirm_password" id="confirmInput"
                       class="w-full px-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors"
                       placeholder="Repeat password" required>
                <p id="confirmMsg" class="text-xs mt-1 hidden"></p>
            </div>

            <button type="submit"
                    class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-2.5 rounded-lg text-sm transition-colors duration-200 mt-2">
                Create Account
            </button>
        </form>

        <p class="text-center text-sm text-gray-400 mt-4">
            ← <a href="login.php" class="text-red-600 font-medium hover:underline">Back to sign in</a>
        </p>
    </div>

<script>
const phoneInput = document.getElementById('phoneInput');
phoneInput.addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9]/g, '');
});

const passwordInput = document.getElementById('passwordInput');
const strengthDiv = document.getElementById('passwordStrength');

function updateCheck(id, passed) {
    const el = document.getElementById(id);
    const icon = el.querySelector('.check-icon');
    if (passed) {
        el.className = 'flex items-center gap-2 text-xs text-green-600';
        icon.textContent = '✓';
    } else {
        el.className = 'flex items-center gap-2 text-xs text-gray-400';
        icon.textContent = '○';
    }
}

passwordInput.addEventListener('input', function() {
    const val = this.value;
    if (val.length > 0) {
        strengthDiv.classList.remove('hidden');
    } else {
        strengthDiv.classList.add('hidden');
    }
    updateCheck('check_length', val.length >= 8);
    updateCheck('check_upper', /[A-Z]/.test(val));
    updateCheck('check_lower', /[a-z]/.test(val));
    updateCheck('check_number', /[0-9]/.test(val));
    updateCheck('check_symbol', /[!@#$%^&*(),.?":{}|<>]/.test(val));
});

const confirmInput = document.getElementById('confirmInput');
const confirmMsg = document.getElementById('confirmMsg');

confirmInput.addEventListener('input', function() {
    if (this.value === passwordInput.value) {
        confirmMsg.textContent = '✓ Passwords match';
        confirmMsg.className = 'text-xs mt-1 text-green-600';
    } else {
        confirmMsg.textContent = '✗ Passwords do not match';
        confirmMsg.className = 'text-xs mt-1 text-red-500';
    }
    confirmMsg.classList.remove('hidden');
});

document.getElementById('registerForm').addEventListener('submit', function(e) {
    const phone = phoneInput.value;
    const password = passwordInput.value;
    const dob = document.getElementById('dobInput').value;

    if (!dob) {
        e.preventDefault();
        alert('Please enter your date of birth.');
        return;
    }

    if (phone && !/^01[0-9]{8,9}$/.test(phone)) {
        e.preventDefault();
        alert('Please enter a valid Malaysian phone number (e.g. 01234567890)');
        return;
    }

    const allGood = password.length >= 8 &&
        /[A-Z]/.test(password) &&
        /[a-z]/.test(password) &&
        /[0-9]/.test(password) &&
        /[!@#$%^&*(),.?":{}|<>]/.test(password);

    if (!allGood) {
        e.preventDefault();
        alert('Password must be at least 8 characters with uppercase, lowercase, number and symbol!');
        return;
    }
});
</script>
</body>
</html>