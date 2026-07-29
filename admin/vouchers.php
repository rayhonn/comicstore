<?php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/money_helper.php';

require_admin();

date_default_timezone_set('Asia/Kuala_Lumpur');

$success = '';
$error = '';

function requireVoucherId(mixed $value): int
{
    $id = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($id === false || $id === null) {
        throw new InvalidArgumentException(
            'Invalid voucher.'
        );
    }

    return (int) $id;
}

function normalizeVoucherCode(mixed $value): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException(
            'Voucher code is required.'
        );
    }

    $code = strtoupper(trim($value));
    $length = function_exists('mb_strlen')
        ? mb_strlen($code, 'UTF-8')
        : strlen($code);

    if ($code === '') {
        throw new InvalidArgumentException(
            'Voucher code is required.'
        );
    }

    if ($length > 50) {
        throw new InvalidArgumentException(
            'Voucher code cannot exceed 50 characters.'
        );
    }

    if (!preg_match('/\A[A-Z0-9_-]+\z/', $code)) {
        throw new InvalidArgumentException(
            'Voucher code may only contain letters, numbers, hyphens and underscores.'
        );
    }

    return $code;
}

function normalizeVoucherType(mixed $value): string
{
    if (
        !is_string($value) ||
        !in_array(
            $value,
            ['percentage', 'fixed'],
            true
        )
    ) {
        throw new InvalidArgumentException(
            'Please select a valid voucher type.'
        );
    }

    return $value;
}

function normalizeVoucherAmounts(
    array $input,
    string $type
): array {
    try {
        $value = moneyNormalizeDecimal(
            (string) ($input['voucher_value'] ?? '')
        );

        $minimum_raw = trim(
            (string) ($input['voucher_min_order'] ?? '')
        );
        $minimum_order = moneyNormalizeDecimal(
            $minimum_raw === '' ? '0' : $minimum_raw
        );

        $maximum_raw = trim(
            (string) (
                $input['voucher_max_discount'] ?? ''
            )
        );
        $maximum_discount =
            $maximum_raw === ''
                ? null
                : moneyNormalizeDecimal($maximum_raw);

        $value_sen = moneyDecimalToSen($value);
    } catch (MoneyValueException $e) {
        throw new InvalidArgumentException(
            'Please enter valid voucher amounts with up to two decimal places.',
            0,
            $e
        );
    }

    if ($value_sen < 1) {
        throw new InvalidArgumentException(
            'Voucher value must be at least 0.01.'
        );
    }

    if (
        $type === 'percentage' &&
        $value_sen > 10000
    ) {
        throw new InvalidArgumentException(
            'Percentage voucher value cannot exceed 100.00.'
        );
    }

    return [
        $value,
        $minimum_order,
        $maximum_discount,
    ];
}

function normalizeVoucherUsageLimit(
    mixed $value
): ?int {
    if ($value === null || $value === '') {
        return null;
    }

    $limit = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
                'max_range' => 2147483647,
            ],
        ]
    );

    if ($limit === false || $limit === null) {
        throw new InvalidArgumentException(
            'Usage limit must be a positive whole number.'
        );
    }

    return (int) $limit;
}

function normalizeVoucherDate(
    mixed $value,
    string $label
): ?string {
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_string($value)) {
        throw new InvalidArgumentException(
            'Invalid ' . $label . '.'
        );
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i',
        trim($value)
    );
    $errors = DateTimeImmutable::getLastErrors();

    if (
        !$date ||
        (
            is_array($errors) &&
            (
                $errors['warning_count'] > 0 ||
                $errors['error_count'] > 0
            )
        )
    ) {
        throw new InvalidArgumentException(
            'Invalid ' . $label . '.'
        );
    }

    return $date->format('Y-m-d H:i:s');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? null;

    try {
        if (
            !is_string($action) ||
            !in_array(
                $action,
                ['add', 'edit', 'delete', 'toggle'],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid voucher action.'
            );
        }

        if ($action === 'delete' || $action === 'toggle') {
            $voucher_id = requireVoucherId(
                $_POST['voucher_id'] ?? null
            );

            if ($action === 'delete') {
                $delete = $pdo->prepare("
                    DELETE FROM vouchers
                    WHERE voucher_id = ?
                ");
                $delete->execute([$voucher_id]);

                if ($delete->rowCount() !== 1) {
                    throw new InvalidArgumentException(
                        'Voucher not found.'
                    );
                }

                $success = 'Voucher deleted.';
            } else {
                $toggle = $pdo->prepare("
                    UPDATE vouchers
                    SET voucher_is_active =
                        NOT voucher_is_active
                    WHERE voucher_id = ?
                ");
                $toggle->execute([$voucher_id]);

                if ($toggle->rowCount() !== 1) {
                    throw new InvalidArgumentException(
                        'Voucher not found.'
                    );
                }

                header('Location: vouchers.php');
                exit;
            }
        } else {
            $voucher_id = null;

            if ($action === 'edit') {
                $voucher_id = requireVoucherId(
                    $_POST['voucher_id'] ?? null
                );

                $exists = $pdo->prepare("
                    SELECT voucher_id
                    FROM vouchers
                    WHERE voucher_id = ?
                ");
                $exists->execute([$voucher_id]);

                if (!$exists->fetchColumn()) {
                    throw new InvalidArgumentException(
                        'Voucher not found.'
                    );
                }
            }

            $code = normalizeVoucherCode(
                $_POST['voucher_code'] ?? null
            );
            $type = normalizeVoucherType(
                $_POST['voucher_type'] ?? null
            );
            [
                $value,
                $min_order,
                $max_discount,
            ] = normalizeVoucherAmounts($_POST, $type);

            $usage_limit = normalizeVoucherUsageLimit(
                $_POST['voucher_usage_limit'] ?? null
            );
            $start_date = normalizeVoucherDate(
                $_POST['voucher_start_date'] ?? null,
                'start date'
            );
            $end_date = normalizeVoucherDate(
                $_POST['voucher_end_date'] ?? null,
                'end date'
            );
            $is_active =
                isset($_POST['voucher_is_active'])
                    ? 1
                    : 0;

            if (
                $start_date !== null &&
                $end_date !== null &&
                strtotime($end_date) < strtotime($start_date)
            ) {
                throw new InvalidArgumentException(
                    'End date cannot be earlier than start date.'
                );
            }

            $duplicate_sql = "
                SELECT voucher_id
                FROM vouchers
                WHERE voucher_code = ?
            ";
            $duplicate_params = [$code];

            if ($voucher_id !== null) {
                $duplicate_sql .= " AND voucher_id != ?";
                $duplicate_params[] = $voucher_id;
            }

            $duplicate = $pdo->prepare($duplicate_sql);
            $duplicate->execute($duplicate_params);

            if ($duplicate->fetchColumn()) {
                throw new InvalidArgumentException(
                    'Voucher code already exists.'
                );
            }

            if ($action === 'add') {
                $insert = $pdo->prepare("
                    INSERT INTO vouchers (
                        voucher_code,
                        voucher_type,
                        voucher_value,
                        voucher_min_order,
                        voucher_max_discount,
                        voucher_usage_limit,
                        voucher_start_date,
                        voucher_end_date,
                        voucher_is_active
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insert->execute([
                    $code,
                    $type,
                    $value,
                    $min_order,
                    $max_discount,
                    $usage_limit,
                    $start_date,
                    $end_date,
                    $is_active,
                ]);

                $success = 'Voucher created!';
            } else {
                $update = $pdo->prepare("
                    UPDATE vouchers
                    SET voucher_code = ?,
                        voucher_type = ?,
                        voucher_value = ?,
                        voucher_min_order = ?,
                        voucher_max_discount = ?,
                        voucher_usage_limit = ?,
                        voucher_start_date = ?,
                        voucher_end_date = ?,
                        voucher_is_active = ?
                    WHERE voucher_id = ?
                ");
                $update->execute([
                    $code,
                    $type,
                    $value,
                    $min_order,
                    $max_discount,
                    $usage_limit,
                    $start_date,
                    $end_date,
                    $is_active,
                    $voucher_id,
                ]);

                $success = 'Voucher updated!';
            }
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}

$vouchers = $pdo->query("
    SELECT
        v.*,
        COUNT(vu.usage_id) AS actual_usage
    FROM vouchers v
    LEFT JOIN voucher_usage vu
        ON v.voucher_id = vu.usage_voucher_id
    WHERE v.voucher_is_system_generated = 0
    GROUP BY v.voucher_id
    ORDER BY v.voucher_created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vouchers - MangaVault Admin</title>
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
                <h1 class="text-2xl font-black text-gray-800">Vouchers</h1>
                <p class="text-sm text-gray-400 mt-0.5"><?= count($vouchers) ?> vouchers total</p>
            </div>
            <button onclick="openAddModal()"
                    class="bg-red-600 hover:bg-red-700 text-white font-semibold px-4 py-2 rounded-xl text-sm transition-colors">
                + Create Voucher
            </button>
        </div>

        <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-5">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (empty($vouchers)): ?>
        <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
            <div class="text-5xl mb-4">🎟️</div>
            <p class="text-gray-500 font-medium mb-4">No vouchers yet</p>
            <button onclick="openAddModal()" class="bg-red-600 hover:bg-red-700 text-white font-semibold px-5 py-2 rounded-xl text-sm transition-colors">
                Create First Voucher
            </button>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Code</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Discount</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Min Order</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Usage</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Validity</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vouchers as $v):
                        $voucher_value_sen = moneyDecimalToSen(
                            (string) $v['voucher_value']
                        );
                        $voucher_min_order_sen = moneyDecimalToSen(
                            (string) $v['voucher_min_order']
                        );
                        $voucher_max_discount_sen =
                            $v['voucher_max_discount'] === null
                                ? null
                                : moneyDecimalToSen(
                                    (string) $v['voucher_max_discount']
                                );
                        $now = new DateTime();
                        $is_expired = $v['voucher_end_date'] && new DateTime($v['voucher_end_date']) < $now;
                        $is_maxed = $v['voucher_usage_limit'] && $v['actual_usage'] >= $v['voucher_usage_limit'];
                    ?>
                    <tr class="border-t border-gray-50 hover:bg-gray-50 transition-colors <?= (!$v['voucher_is_active'] || $is_expired || $is_maxed) ? 'opacity-60' : '' ?>">
                        <td class="px-5 py-4">
                            <span class="font-mono font-black text-gray-800 bg-gray-100 px-3 py-1 rounded-lg text-sm">
                                <?= htmlspecialchars($v['voucher_code']) ?>
                            </span>
                        </td>
                        <td class="px-5 py-4">
                            <p class="font-bold text-red-600">
                                <?= $v['voucher_type'] === 'percentage'
                                    ? htmlspecialchars((string) $v['voucher_value'], ENT_QUOTES, 'UTF-8') . '%'
                                    : 'RM ' . moneyFormatSen($voucher_value_sen) ?>
                            </p>
                            <?php if (
                                $voucher_max_discount_sen !== null &&
                                $voucher_max_discount_sen > 0
                            ): ?>
                            <p class="text-xs text-gray-400">Max: RM <?= moneyFormatSen($voucher_max_discount_sen) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-4 text-sm text-gray-600">
                            <?= $voucher_min_order_sen > 0
                                ? 'RM ' . moneyFormatSen($voucher_min_order_sen)
                                : '—' ?>
                        </td>
                        <td class="px-5 py-4 text-sm">
                            <span class="font-semibold text-gray-800"><?= $v['actual_usage'] ?></span>
                            <?php if ($v['voucher_usage_limit']): ?>
                            <span class="text-gray-400"> / <?= $v['voucher_usage_limit'] ?></span>
                            <?php else: ?>
                            <span class="text-gray-400"> / ∞</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-4 text-xs text-gray-500">
                            <?php if ($v['voucher_start_date']): ?>
                            <p>From: <?= date('d M Y', strtotime($v['voucher_start_date'])) ?></p>
                            <?php endif; ?>
                            <?php if ($v['voucher_end_date']): ?>
                            <p class="<?= $is_expired ? 'text-red-500 font-semibold' : '' ?>">
                                Until: <?= date('d M Y', strtotime($v['voucher_end_date'])) ?>
                                <?= $is_expired ? '(Expired)' : '' ?>
                            </p>
                            <?php else: ?>
                            <p>No expiry</p>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-4">
                            <?php if ($is_expired): ?>
                            <span class="bg-gray-100 text-gray-500 text-xs px-2 py-1 rounded-full font-semibold">Expired</span>
                            <?php elseif ($is_maxed): ?>
                            <span class="bg-orange-100 text-orange-600 text-xs px-2 py-1 rounded-full font-semibold">Maxed Out</span>
                            <?php elseif ($v['voucher_is_active']): ?>
                            <span class="bg-green-100 text-green-700 text-xs px-2 py-1 rounded-full font-semibold">Active</span>
                            <?php else: ?>
                            <span class="bg-gray-100 text-gray-500 text-xs px-2 py-1 rounded-full font-semibold">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex gap-2">
                                <button onclick="openEditModal(<?= htmlspecialchars(
                                    json_encode($v),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>)"
                                        class="text-xs px-3 py-1.5 border border-blue-200 text-blue-600 rounded-lg hover:bg-blue-50 transition-colors">
                                    ✏️ Edit
                                </button>
                                <form method="POST" class="inline">
                                    <?php csrf_field(); ?>
<input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="voucher_id" value="<?= (int) $v['voucher_id'] ?>">
                                    <button type="submit"
                                            class="text-xs px-3 py-1.5 border border-gray-200 text-gray-600 rounded-lg hover:bg-gray-50 transition-colors">
                                        <?= $v['voucher_is_active'] ? '🙈 Disable' : '👁️ Enable' ?>
                                    </button>
                                </form>
                                <form method="POST" class="inline">
                                    <?php csrf_field(); ?>
<input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="voucher_id" value="<?= (int) $v['voucher_id'] ?>">
                                    <button type="submit" onclick="return confirm('Delete this voucher?')"
                                            class="text-xs px-3 py-1.5 border border-red-200 text-red-600 rounded-lg hover:bg-red-50 transition-colors">
                                        🗑️
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Add/Edit Modal -->
    <div id="voucherModal" class="modal fixed inset-0 bg-black/50 z-50 items-center justify-center px-4">
        <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl max-h-[90vh] overflow-y-auto">
            <div class="p-5 border-b border-gray-100 flex justify-between items-center">
                <h3 class="font-black text-gray-800" id="modalTitle">Create Voucher</h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 text-xl">✕</button>
            </div>
            <form method="POST" class="p-5 space-y-4">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="voucher_id" id="formId">

                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Voucher Code *</label>
                    <input type="text" name="voucher_code" id="formCode" maxlength="50" pattern="[A-Za-z0-9_-]+" required
                           placeholder="e.g. MANGA10"
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white uppercase">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Discount Type *</label>
                        <select name="voucher_type" id="formType" onchange="toggleMaxDiscount()"
                                class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                            <option value="percentage">Percentage (%)</option>
                            <option value="fixed">Fixed Amount (RM)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Value *</label>
                        <input type="number" name="voucher_value" id="formValue" required step="0.01" min="0.01" max="99999999.99"
                               placeholder="e.g. 10"
                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Min Order (RM)</label>
                        <input type="number" name="voucher_min_order" id="formMinOrder" step="0.01" min="0" max="99999999.99" value="0"
                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                    </div>
                    <div id="maxDiscountDiv">
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Max Discount (RM)</label>
                        <input type="number" name="voucher_max_discount" id="formMaxDiscount" step="0.01" min="0" max="99999999.99"
                               placeholder="Leave empty = no limit"
                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Usage Limit</label>
                    <input type="number" name="voucher_usage_limit" id="formUsageLimit" min="1" max="2147483647"
                           placeholder="Leave empty = unlimited"
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Start Date</label>
                        <input type="datetime-local" name="voucher_start_date" id="formStartDate"
                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">End Date</label>
                        <input type="datetime-local" name="voucher_end_date" id="formEndDate"
                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                    </div>
                </div>

                <div id="activeToggleDiv">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" name="voucher_is_active" id="formActive" checked class="w-4 h-4 accent-red-600">
                        <span class="text-sm text-gray-700 font-medium">Active (visible to customers)</span>
                    </label>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" onclick="closeModal()"
                            class="flex-1 py-3 border-2 border-gray-100 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors">
                        Save Voucher
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openAddModal() {
        document.getElementById('modalTitle').textContent = 'Create Voucher';
        document.getElementById('formAction').value = 'add';
        document.getElementById('formId').value = '';
        document.getElementById('formCode').value = '';
        document.getElementById('formType').value = 'percentage';
        document.getElementById('formValue').value = '';
        document.getElementById('formMinOrder').value = '0';
        document.getElementById('formMaxDiscount').value = '';
        document.getElementById('formUsageLimit').value = '';
        document.getElementById('formStartDate').value = '';
        document.getElementById('formEndDate').value = '';
        document.getElementById('formActive').checked = true;
        document.getElementById('formCode').removeAttribute('readonly');
        toggleMaxDiscount();
        document.getElementById('voucherModal').classList.add('active');
    }

    function openEditModal(v) {
        document.getElementById('modalTitle').textContent = 'Edit Voucher';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('formId').value = v.voucher_id;
        document.getElementById('formCode').value = v.voucher_code;
        document.getElementById('formCode').setAttribute('readonly', true);
        document.getElementById('formType').value = v.voucher_type;
        document.getElementById('formValue').value = v.voucher_value;
        document.getElementById('formMinOrder').value = v.voucher_min_order;
        document.getElementById('formMaxDiscount').value = v.voucher_max_discount || '';
        document.getElementById('formUsageLimit').value = v.voucher_usage_limit || '';
        document.getElementById('formStartDate').value = v.voucher_start_date ? v.voucher_start_date.slice(0,16) : '';
        document.getElementById('formEndDate').value = v.voucher_end_date ? v.voucher_end_date.slice(0,16) : '';
        document.getElementById('formActive').checked = v.voucher_is_active == 1;
        toggleMaxDiscount();
        document.getElementById('voucherModal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('voucherModal').classList.remove('active');
    }

    function toggleMaxDiscount() {
        const type = document.getElementById('formType').value;
        document.getElementById('maxDiscountDiv').style.display = type === 'percentage' ? 'block' : 'none';
    }

    document.getElementById('voucherModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
    </script>
</body>
</html>