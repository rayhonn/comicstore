<?php
require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

$user_id = (int) $_SESSION['user_id'];
$error = '';
$success = '';

$ewallets = [
    'Touch n Go',
    'GrabPay',
    'ShopeePay',
    'Boost',
];

function normalizePaymentText(
    mixed $value,
    string $label,
    int $maxLength,
    bool $required = true
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

function normalizePaymentMethodId(mixed $value): int
{
    $id = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($id === false || $id === null) {
        throw new RuntimeException('Invalid payment method.');
    }

    return $id;
}

function validatePaymentPin(mixed $value): void
{
    if (!is_string($value)) {
        throw new RuntimeException('Invalid security PIN.');
    }

    $pin = trim($value);
    if (!preg_match('/^\d{6}$/', $pin)) {
        throw new RuntimeException('Security PIN must be 6 digits.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? null;

    try {
        if (!is_string($action)) {
            throw new RuntimeException('Invalid payment method action.');
        }

        if ($action === 'add_card') {
            $label = normalizePaymentText(
                $_POST['pm_label'] ?? '',
                'Card label',
                100
            );
            $holder = normalizePaymentText(
                $_POST['pm_holder_name'] ?? '',
                'Card holder name',
                100
            );
            $last_four = normalizePaymentText(
                $_POST['pm_last_four'] ?? '',
                'Last four digits',
                4
            );
            $expiry = normalizePaymentText(
                $_POST['pm_expiry'] ?? '',
                'Card expiry',
                5
            );
            validatePaymentPin($_POST['confirm_pin'] ?? null);

            if (!preg_match('/^\d{4}$/', $last_four)) {
                throw new RuntimeException(
                    'Last 4 digits must be exactly 4 numbers.'
                );
            }

            if (!preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $expiry)) {
                throw new RuntimeException(
                    'Expiry must be a valid MM/YY value.'
                );
            }

            $is_default = isset($_POST['pm_is_default']) ? 1 : 0;

            $pdo->beginTransaction();
            try {
                if ($is_default) {
                    $clear_default = $pdo->prepare(
                        'UPDATE payment_methods
                         SET pm_is_default = 0
                         WHERE pm_user_id = ?'
                    );
                    $clear_default->execute([$user_id]);
                }

                $insert_card = $pdo->prepare(
                    "INSERT INTO payment_methods (
                        pm_user_id,
                        pm_type,
                        pm_label,
                        pm_last_four,
                        pm_expiry,
                        pm_holder_name,
                        pm_is_default
                    ) VALUES (?, 'card', ?, ?, ?, ?, ?)"
                );
                $insert_card->execute([
                    $user_id,
                    $label,
                    $last_four,
                    $expiry,
                    $holder,
                    $is_default,
                ]);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            $success = 'Card saved successfully!';
        } elseif ($action === 'add_ewallet') {
            $label = normalizePaymentText(
                $_POST['pm_label'] ?? '',
                'Wallet label',
                100
            );
            $ewallet_name = normalizePaymentText(
                $_POST['pm_ewallet_name'] ?? '',
                'E-wallet type',
                50
            );
            $phone = normalizePaymentText(
                $_POST['pm_phone'] ?? '',
                'Registered phone number',
                20
            );
            validatePaymentPin($_POST['confirm_pin'] ?? null);

            if (!in_array($ewallet_name, $ewallets, true)) {
                throw new RuntimeException('Please select a valid e-wallet.');
            }

            if (!preg_match('/^[0-9]{9,10}$/', $phone)) {
                throw new RuntimeException(
                    'Please enter a valid phone number.'
                );
            }

            $is_default = isset($_POST['pm_is_default']) ? 1 : 0;

            $pdo->beginTransaction();
            try {
                if ($is_default) {
                    $clear_default = $pdo->prepare(
                        'UPDATE payment_methods
                         SET pm_is_default = 0
                         WHERE pm_user_id = ?'
                    );
                    $clear_default->execute([$user_id]);
                }

                $insert_wallet = $pdo->prepare(
                    "INSERT INTO payment_methods (
                        pm_user_id,
                        pm_type,
                        pm_label,
                        pm_ewallet_name,
                        pm_phone,
                        pm_is_default
                    ) VALUES (?, 'ewallet', ?, ?, ?, ?)"
                );
                $insert_wallet->execute([
                    $user_id,
                    $label,
                    $ewallet_name,
                    $phone,
                    $is_default,
                ]);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            $success = 'E-Wallet saved successfully!';
        } elseif ($action === 'delete') {
            $payment_method_id = normalizePaymentMethodId(
                $_POST['pm_id'] ?? null
            );

            $delete_method = $pdo->prepare(
                'DELETE FROM payment_methods
                 WHERE pm_id = ?
                 AND pm_user_id = ?'
            );
            $delete_method->execute([
                $payment_method_id,
                $user_id,
            ]);

            if ($delete_method->rowCount() !== 1) {
                throw new RuntimeException('Payment method not found.');
            }

            $success = 'Payment method removed.';
        } elseif ($action === 'set_default') {
            $payment_method_id = normalizePaymentMethodId(
                $_POST['pm_id'] ?? null
            );

            $method_check = $pdo->prepare(
                'SELECT pm_id
                 FROM payment_methods
                 WHERE pm_id = ?
                 AND pm_user_id = ?'
            );
            $method_check->execute([
                $payment_method_id,
                $user_id,
            ]);
            if (!$method_check->fetchColumn()) {
                throw new RuntimeException('Payment method not found.');
            }

            $pdo->beginTransaction();
            try {
                $clear_default = $pdo->prepare(
                    'UPDATE payment_methods
                     SET pm_is_default = 0
                     WHERE pm_user_id = ?'
                );
                $clear_default->execute([$user_id]);

                $set_default = $pdo->prepare(
                    'UPDATE payment_methods
                     SET pm_is_default = 1
                     WHERE pm_id = ?
                     AND pm_user_id = ?'
                );
                $set_default->execute([
                    $payment_method_id,
                    $user_id,
                ]);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            $success = 'Default payment method updated.';
        } else {
            throw new RuntimeException('Invalid payment method action.');
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        app_error_log('Payment method management failed: ' . $e->getMessage());
        $error = 'Unable to update payment methods.';
    }
}

$payment_methods_stmt = $pdo->prepare(
    'SELECT *
     FROM payment_methods
     WHERE pm_user_id = ?
     ORDER BY pm_is_default DESC, pm_created_at DESC'
);
$payment_methods_stmt->execute([$user_id]);
$payment_methods = $payment_methods_stmt->fetchAll(PDO::FETCH_ASSOC);
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
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="action" value="set_default">
                                            <input type="hidden" name="pm_id" value="<?= (int) $pm['pm_id'] ?>">
                                            <button type="submit" class="text-xs px-3 py-1.5 bg-green-100 text-green-700 hover:bg-green-200 rounded-lg transition-colors">Set Default</button>
                                        </form>
                                        <?php endif; ?>
                                        <button onclick="confirmDelete(<?= (int) $pm['pm_id'] ?>)"
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
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="add_card">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1.5">Card Label *</label>
                                <input type="text" name="pm_label" maxlength="100" placeholder="e.g. My Visa Card" required
                                       class="w-full px-3 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1.5">Card Holder Name *</label>
                                <input type="text" name="pm_holder_name" maxlength="100" placeholder="JOHN DOE" required
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
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="add_ewallet">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1.5">Wallet Label *</label>
                                <input type="text" name="pm_label" maxlength="100" placeholder="e.g. My TNG Wallet" required
                                       class="w-full px-3 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1.5">E-Wallet Type *</label>
                                <select name="pm_ewallet_name" required
                                        class="w-full px-3 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                    <option value="">Select e-wallet...</option>
                                    <?php foreach ($ewallets as $ew): ?>
                                        <option value="<?= htmlspecialchars($ew, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($ew, ENT_QUOTES, 'UTF-8') ?></option>
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
                <?php csrf_field(); ?>
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

    <script src="../assets/js/password-toggle.js" defer></script>

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