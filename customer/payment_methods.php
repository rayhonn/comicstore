<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_card') {
        $label = trim($_POST['pm_label']);
        $holder = trim($_POST['pm_holder_name']);
        $last_four = trim($_POST['pm_last_four']);
        $expiry = trim($_POST['pm_expiry']);
        $is_default = isset($_POST['pm_is_default']) ? 1 : 0;
        $pin = trim($_POST['confirm_pin']);

        if (empty($label) || empty($holder) || empty($last_four) || empty($expiry) || empty($pin)) {
            $error = "Please fill in all required fields.";
        } elseif (!preg_match('/^\d{4}$/', $last_four)) {
            $error = "Last 4 digits must be exactly 4 numbers.";
        } elseif (!preg_match('/^\d{2}\/\d{2}$/', $expiry)) {
            $error = "Expiry must be in MM/YY format.";
        } elseif (strlen($pin) !== 6 || !ctype_digit($pin)) {
            $error = "Security PIN must be 6 digits.";
        } else {
            if ($is_default) {
                $pdo->prepare("UPDATE payment_methods SET pm_is_default = 0 WHERE pm_user_id = ?")
                    ->execute([$user_id]);
            }
            $pdo->prepare("INSERT INTO payment_methods (pm_user_id, pm_type, pm_label, pm_last_four, pm_expiry, pm_holder_name, pm_is_default) VALUES (?, 'card', ?, ?, ?, ?, ?)")
                ->execute([$user_id, $label, $last_four, $expiry, $holder, $is_default]);
            $success = "Card saved successfully!";
        }

    } elseif ($action === 'add_ewallet') {
        $label = trim($_POST['pm_label']);
        $ewallet_name = trim($_POST['pm_ewallet_name']);
        $phone = trim($_POST['pm_phone']);
        $is_default = isset($_POST['pm_is_default']) ? 1 : 0;
        $pin = trim($_POST['confirm_pin']);

        if (empty($label) || empty($ewallet_name) || empty($phone) || empty($pin)) {
            $error = "Please fill in all required fields.";
        } elseif (!preg_match('/^[0-9]{9,10}$/', $phone)) {
            $error = "Please enter a valid phone number.";
        } elseif (strlen($pin) !== 6 || !ctype_digit($pin)) {
            $error = "Security PIN must be 6 digits.";
        } else {
            if ($is_default) {
                $pdo->prepare("UPDATE payment_methods SET pm_is_default = 0 WHERE pm_user_id = ?")
                    ->execute([$user_id]);
            }
            $pdo->prepare("INSERT INTO payment_methods (pm_user_id, pm_type, pm_label, pm_ewallet_name, pm_phone, pm_is_default) VALUES (?, 'ewallet', ?, ?, ?, ?)")
                ->execute([$user_id, $label, $ewallet_name, $phone, $is_default]);
            $success = "E-Wallet saved successfully!";
        }

    } elseif ($action === 'delete') {
        $pm_id = $_POST['pm_id'];
        $pdo->prepare("DELETE FROM payment_methods WHERE pm_id = ? AND pm_user_id = ?")
            ->execute([$pm_id, $user_id]);
        $success = "Payment method removed.";

    } elseif ($action === 'set_default') {
        $pm_id = $_POST['pm_id'];
        $pdo->prepare("UPDATE payment_methods SET pm_is_default = 0 WHERE pm_user_id = ?")
            ->execute([$user_id]);
        $pdo->prepare("UPDATE payment_methods SET pm_is_default = 1 WHERE pm_id = ? AND pm_user_id = ?")
            ->execute([$pm_id, $user_id]);
        $success = "Default payment method updated.";
    }
}

$payment_methods = $pdo->prepare("SELECT * FROM payment_methods WHERE pm_user_id = ? ORDER BY pm_is_default DESC, pm_created_at DESC");
$payment_methods->execute([$user_id]);
$payment_methods = $payment_methods->fetchAll(PDO::FETCH_ASSOC);

$ewallets = ['Touch n Go', 'GrabPay', 'ShopeePay', 'Boost'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Methods - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html { scroll-behavior: smooth; }
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
        .pin-input { transition: all 0.15s ease; }
        .pin-input:focus { transform: scale(1.08); border-color: #C0392B; }
        .pin-input.filled { border-color: #16a34a; background: #f0fdf4; }
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
            <span class="text-gray-600">Payment Methods</span>
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

                <!-- Saved Methods -->
                <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                    <h3 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
                        Saved Payment Methods
                    </h3>

                    <?php if (count($payment_methods) === 0): ?>
                        <div class="text-center py-8">
                            <div class="text-4xl mb-3">💳</div>
                            <p class="text-gray-500 text-sm mb-1">No saved payment methods</p>
                            <p class="text-gray-400 text-xs">Add a card or e-wallet below for faster checkout</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($payment_methods as $pm): ?>
                            <div class="border-2 <?= $pm['pm_is_default'] ? 'border-red-200 bg-red-50' : 'border-gray-100 bg-gray-50' ?> rounded-xl p-4">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 <?= $pm['pm_type'] === 'card' ? 'bg-gradient-to-br from-blue-500 to-purple-600' : 'bg-gradient-to-br from-orange-400 to-pink-500' ?> rounded-xl flex items-center justify-center text-xl">
                                            <?= $pm['pm_type'] === 'card' ? '💳' : '📱' ?>
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($pm['pm_label']) ?></p>
                                                <?php if ($pm['pm_is_default']): ?>
                                                    <span class="bg-red-600 text-white text-xs px-2 py-0.5 rounded-full">Default</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($pm['pm_type'] === 'card'): ?>
                                                <p class="text-xs text-gray-400">•••• •••• •••• <?= htmlspecialchars($pm['pm_last_four']) ?> · Expires <?= htmlspecialchars($pm['pm_expiry']) ?></p>
                                            <?php else: ?>
                                                <p class="text-xs text-gray-400"><?= htmlspecialchars($pm['pm_ewallet_name']) ?> · +60<?= htmlspecialchars($pm['pm_phone']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="flex gap-2">
                                        <?php if (!$pm['pm_is_default']): ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="set_default">
                                            <input type="hidden" name="pm_id" value="<?= $pm['pm_id'] ?>">
                                            <button type="submit" class="text-xs px-3 py-1.5 bg-green-100 text-green-700 hover:bg-green-200 rounded-lg transition-colors">Set Default</button>
                                        </form>
                                        <?php endif; ?>
                                        <button onclick="confirmDelete(<?= $pm['pm_id'] ?>)"
                                                class="text-xs px-3 py-1.5 bg-red-100 text-red-600 hover:bg-red-200 rounded-lg transition-colors">Remove</button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Add New -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                    <!-- Add Card -->
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h3 class="font-bold text-gray-800 mb-1 flex items-center gap-2">
                            💳 Add Credit/Debit Card
                        </h3>
                        <p class="text-xs text-gray-400 mb-5">Your card info is encrypted and stored securely.</p>

                        <form method="POST" id="addCardForm" class="space-y-4">
                            <input type="hidden" name="action" value="add_card">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1.5">Card Label *</label>
                                <input type="text" name="pm_label" placeholder="e.g. My Visa Card" required
                                       class="w-full px-3 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1.5">Card Holder Name *</label>
                                <input type="text" name="pm_holder_name" placeholder="JOHN DOE" required
                                       class="w-full px-3 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white uppercase">
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1.5">Last 4 Digits *</label>
                                    <input type="text" name="pm_last_four" placeholder="1234" maxlength="4"
                                           oninput="this.value = this.value.replace(/[^0-9]/g, '')" required
                                           class="w-full px-3 py-2.5 border-2 border-gray-100 rounded-xl text-sm font-mono focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1.5">Expiry (MM/YY) *</label>
                                    <input type="text" name="pm_expiry" placeholder="MM/YY" maxlength="5"
                                           oninput="formatExpiryInput(this)" required
                                           class="w-full px-3 py-2.5 border-2 border-gray-100 rounded-xl text-sm font-mono focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                </div>
                            </div>

                            <!-- Security PIN -->
                            <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4">
                                <p class="text-xs font-semibold text-yellow-700 mb-1">🔒 Security Verification</p>
                                <p class="text-xs text-yellow-600 mb-3">Set a 6-digit PIN to protect this payment method.</p>
                                <div class="flex gap-2 justify-center">
                                    <?php for ($p = 0; $p < 6; $p++): ?>
                                    <input type="password" maxlength="1" name="pin_digit_<?= $p ?>"
                                           oninput="movePIN(this, <?= $p ?>, 'card')"
                                           onkeydown="backspacePIN(event, <?= $p ?>, 'card')"
                                           class="pin-input card-pin w-10 h-10 text-center text-lg font-black border-2 border-gray-200 rounded-xl focus:outline-none transition-all bg-white">
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" name="confirm_pin" id="cardPinValue">
                            </div>

                            <label class="flex items-center gap-2 text-sm text-gray-600">
                                <input type="checkbox" name="pm_is_default" class="accent-red-600">
                                Set as default payment method
                            </label>
                            <button type="submit" onclick="collectPIN('card')"
                                    class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-2.5 rounded-xl text-sm transition-colors duration-200">
                                Save Card
                            </button>
                        </form>
                    </div>

                    <!-- Add E-Wallet -->
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h3 class="font-bold text-gray-800 mb-1 flex items-center gap-2">
                            📱 Add E-Wallet
                        </h3>
                        <p class="text-xs text-gray-400 mb-5">Link your e-wallet for faster checkout.</p>

                        <form method="POST" id="addWalletForm" class="space-y-4">
                            <input type="hidden" name="action" value="add_ewallet">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1.5">Wallet Label *</label>
                                <input type="text" name="pm_label" placeholder="e.g. My TNG Wallet" required
                                       class="w-full px-3 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1.5">E-Wallet Type *</label>
                                <select name="pm_ewallet_name" required
                                        class="w-full px-3 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                    <option value="">Select e-wallet...</option>
                                    <?php foreach ($ewallets as $ew): ?>
                                        <option value="<?= $ew ?>"><?= $ew ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1.5">Registered Phone *</label>
                                <div class="flex gap-2">
                                    <div class="bg-gray-100 px-3 py-2.5 rounded-xl text-sm text-gray-600 font-medium flex-shrink-0">+60</div>
                                    <input type="text" name="pm_phone" placeholder="12 3456789" maxlength="10"
                                           oninput="this.value = this.value.replace(/[^0-9]/g, '')" required
                                           class="flex-1 px-3 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                </div>
                            </div>

                            <!-- Security PIN -->
                            <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4">
                                <p class="text-xs font-semibold text-yellow-700 mb-1">🔒 Security Verification</p>
                                <p class="text-xs text-yellow-600 mb-3">Set a 6-digit PIN to protect this payment method.</p>
                                <div class="flex gap-2 justify-center">
                                    <?php for ($p = 0; $p < 6; $p++): ?>
                                    <input type="password" maxlength="1" name="wallet_pin_digit_<?= $p ?>"
                                           oninput="movePIN(this, <?= $p ?>, 'wallet')"
                                           onkeydown="backspacePIN(event, <?= $p ?>, 'wallet')"
                                           class="pin-input wallet-pin w-10 h-10 text-center text-lg font-black border-2 border-gray-200 rounded-xl focus:outline-none transition-all bg-white">
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" name="confirm_pin" id="walletPinValue">
                            </div>

                            <label class="flex items-center gap-2 text-sm text-gray-600">
                                <input type="checkbox" name="pm_is_default" class="accent-red-600">
                                Set as default payment method
                            </label>
                            <button type="submit" onclick="collectPIN('wallet')"
                                    class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-2.5 rounded-xl text-sm transition-colors duration-200">
                                Save E-Wallet
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center px-6">
        <div class="bg-white rounded-2xl p-6 max-w-sm w-full shadow-xl text-center">
            <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                </svg>
            </div>
            <h3 class="font-bold text-gray-800 mb-2">Remove Payment Method?</h3>
            <p class="text-sm text-gray-500 mb-5">This action cannot be undone.</p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="pm_id" id="deletePmId">
                <div class="flex gap-3">
                    <button type="button" onclick="document.getElementById('deleteModal').classList.add('hidden')"
                            class="flex-1 py-2.5 border-2 border-gray-100 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors">Cancel</button>
                    <button type="submit"
                            class="flex-1 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors">Remove</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function confirmDelete(pmId) {
        document.getElementById('deletePmId').value = pmId;
        document.getElementById('deleteModal').classList.remove('hidden');
    }

    function formatExpiryInput(input) {
        let val = input.value.replace(/[^0-9]/g, '');
        if (val.length >= 2) val = val.substring(0,2) + '/' + val.substring(2);
        input.value = val;
    }

    function movePIN(input, index, type) {
        input.value = input.value.replace(/[^0-9]/g, '');
        if (input.value) {
            input.classList.add('filled');
            const selector = type === 'card' ? '.card-pin' : '.wallet-pin';
            const inputs = document.querySelectorAll(selector);
            if (index < 5) inputs[index + 1].focus();
        } else {
            input.classList.remove('filled');
        }
    }

    function backspacePIN(event, index, type) {
        if (event.key === 'Backspace') {
            const selector = type === 'card' ? '.card-pin' : '.wallet-pin';
            const inputs = document.querySelectorAll(selector);
            inputs[index].classList.remove('filled');
            if (inputs[index].value === '' && index > 0) {
                inputs[index - 1].focus();
                inputs[index - 1].value = '';
                inputs[index - 1].classList.remove('filled');
            }
        }
    }

    function collectPIN(type) {
        if (type === 'card') {
            const inputs = document.querySelectorAll('.card-pin');
            let pin = '';
            inputs.forEach(p => pin += p.value);
            document.getElementById('cardPinValue').value = pin;
        } else {
            const inputs = document.querySelectorAll('.wallet-pin');
            let pin = '';
            inputs.forEach(p => pin += p.value);
            document.getElementById('walletPinValue').value = pin;
        }
    }
    </script>

</body>
</html>