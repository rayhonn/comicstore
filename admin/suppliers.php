<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}
require_once '../includes/db.php';

$error = '';
$success = '';

// Handle Add/Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_supplier'])) {
    $name = trim($_POST['supplier_name'] ?? '');
    $contact = trim($_POST['supplier_contact_person'] ?? '');
    $phone = trim($_POST['supplier_phone'] ?? '');
    $email = trim($_POST['supplier_email'] ?? '');
    $address = trim($_POST['supplier_address'] ?? '');
    $supplier_id = $_POST['supplier_id'] ?? null;

    if (empty($name)) {
        $error = 'Supplier name is required.';
    } else {
        // Check duplicate supplier name (excluding current one if editing)
        $dup_check = $pdo->prepare("SELECT supplier_id FROM suppliers WHERE supplier_name = ? AND supplier_id != ?");
        $dup_check->execute([$name, $supplier_id ?? 0]);
        if ($dup_check->fetch()) {
            $error = "A supplier named \"$name\" already exists.";
            goto skip_save;
        }

        // Check duplicate email
        if (!empty($email)) {
            $dup_email = $pdo->prepare("SELECT supplier_id FROM suppliers WHERE supplier_email = ? AND supplier_id != ?");
            $dup_email->execute([$email, $supplier_id ?? 0]);
            if ($dup_email->fetch()) {
                $error = "This email is already used by another supplier.";
                goto skip_save;
            }
        }

        // Check duplicate phone
        if (!empty($phone)) {
            $dup_phone = $pdo->prepare("SELECT supplier_id FROM suppliers WHERE supplier_phone = ? AND supplier_id != ?");
            $dup_phone->execute([$phone, $supplier_id ?? 0]);
            if ($dup_phone->fetch()) {
                $error = "This phone number is already used by another supplier.";
                goto skip_save;
            }
        }

        $username = trim($_POST['supplier_username'] ?? '');
        $username = !empty($username) ? $username : null;
        $plain_password = trim($_POST['supplier_password'] ?? '');

        if ($supplier_id) {
            if (!empty($plain_password)) {
                $hashed = password_hash($plain_password, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE suppliers SET supplier_name=?, supplier_contact_person=?, supplier_phone=?, supplier_email=?, supplier_address=?,         supplier_username=?, supplier_password=? WHERE supplier_id=?")
                    ->execute([$name, $contact, $phone, $email, $address, $username, $hashed, $supplier_id]);
            } else {
                $pdo->prepare("UPDATE suppliers SET supplier_name=?, supplier_contact_person=?, supplier_phone=?, supplier_email=?, supplier_address=?,         supplier_username=? WHERE supplier_id=?")
                    ->execute([$name, $contact, $phone, $email, $address, $username, $supplier_id]);
            }
            $_SESSION['flash_success'] = 'Supplier updated successfully.';
            header('Location: suppliers.php');
            exit;
        } else {
            $hashed = !empty($plain_password) ? password_hash($plain_password, PASSWORD_DEFAULT) : null;
            $pdo->prepare("INSERT INTO suppliers (supplier_name, supplier_contact_person, supplier_phone, supplier_email, supplier_address,         supplier_username, supplier_password) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$name, $contact, $phone, $email, $address, $username, $hashed]);
            $_SESSION['flash_success'] = 'Supplier added successfully. Login credentials have been set.';
        }
        header('Location: suppliers.php');
        exit;
    }
    skip_save:
}

if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// Handle status toggle — senior admin only
if (isset($_GET['toggle'])) {
    if (($_SESSION['admin_level'] ?? '') !== 'senior_admin') {
        $_SESSION['flash_error'] = 'Only senior admin can deactivate suppliers.';
        header('Location: suppliers.php');
        exit;
    }
    $sid = $_GET['toggle'];
    $current = $pdo->prepare("SELECT supplier_status FROM suppliers WHERE supplier_id = ?");
    $current->execute([$sid]);
    $current = $current->fetchColumn();
    $new_status = $current === 'active' ? 'inactive' : 'active';
    $pdo->prepare("UPDATE suppliers SET supplier_status = ? WHERE supplier_id = ?")->execute([$new_status, $sid]);
    header('Location: suppliers.php');
    exit;
}

$suppliers = $pdo->query("
    SELECT s.*, 
    AVG(po.po_rating) as avg_rating,
    COUNT(po.po_rating) as rating_count
    FROM suppliers s
    LEFT JOIN purchase_orders po ON po.po_supplier_id = s.supplier_id AND po.po_rating IS NOT NULL
    GROUP BY s.supplier_id
    ORDER BY s.supplier_created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suppliers - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-6xl mx-auto px-6 py-8">

        <!-- Header -->
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-black text-gray-800">🏭 Supplier Management</h1>
                <p class="text-gray-500 text-sm mt-1">Manage your manga and comic suppliers</p>
            </div>
            <button onclick="openAddModal()"
                    class="bg-red-600 hover:bg-red-700 text-white font-bold px-5 py-2.5 rounded-xl text-sm transition-colors flex items-center gap-2">
                + Add Supplier
            </button>
        </div>

        <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-6">
            ✅ <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['flash_error'])): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-6">
            🔒 <?= htmlspecialchars($_SESSION['flash_error']) ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-6">
            ❌ <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <!-- Suppliers Table -->
        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <?php if (count($suppliers) === 0): ?>
            <div class="text-center py-16">
                <div class="text-5xl mb-4">🏭</div>
                <p class="text-gray-400">No suppliers yet. Add your first supplier to get started.</p>
            </div>
            <?php else: ?>
            <table class="w-full table-fixed">
            <colgroup>
                <col style="width: 22%;">
                <col style="width: 14%;">
                <col style="width: 12%;">
                <col style="width: 18%;">
                <col style="width: 12%;">
                <col style="width: 10%;">
                <col style="width: 12%;">
            </colgroup>
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Supplier</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Contact Person</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Phone</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Email</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Rating</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($suppliers as $s): ?>
                    <tr class="border-b border-gray-50 hover:bg-gray-50 transition-colors">
                        <td class="px-5 py-4 overflow-hidden">
                            <p class="font-semibold text-sm text-gray-800 truncate"><?= htmlspecialchars($s['supplier_name']) ?></p>
                            <p class="text-xs text-gray-400 truncate"><?= htmlspecialchars($s['supplier_address'] ?? '') ?></p>
                        </td>
                        <td class="px-5 py-4 text-sm text-gray-600 whitespace-nowrap"><?= htmlspecialchars($s['supplier_contact_person'] ?? '—') ?></td>
                        <td class="px-5 py-4 text-sm text-gray-600 whitespace-nowrap"><?= htmlspecialchars($s['supplier_phone'] ?? '—') ?></td>
                        <td class="px-5 py-4 text-sm text-gray-600"><?= htmlspecialchars($s['supplier_email'] ?? '—') ?></td>
                        <td class="px-5 py-4 text-center">
                            <?php if ($s['rating_count'] > 0): ?>
                            <div class="flex items-center justify-center gap-1">
                                <span class="text-yellow-400">★</span>
                                <span class="text-sm font-semibold text-gray-700"><?= number_format($s['avg_rating'], 1) ?></span>
                                <span class="text-xs text-gray-400">(<?= $s['rating_count'] ?>)</span>
                            </div>
                            <?php else: ?>
                            <span class="text-xs text-gray-300">No ratings</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-4 text-center">
                            <span class="<?= $s['supplier_status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?> text-xs px-3 py-1 rounded-full font-semibold capitalize">
                                <?= $s['supplier_status'] ?>
                            </span>
                        </td>
                        <td class="px-5 py-4 text-center">
                            <div class="flex items-center justify-center gap-2">
                                <button onclick='openEditModal(<?= json_encode($s) ?>)'
                                        class="text-xs text-blue-600 hover:underline font-semibold">Edit</button>
                                <span class="text-gray-300">|</span>
                                <?php if (($_SESSION['admin_level'] ?? '') === 'senior_admin'): ?>
                                <a href="?toggle=<?= $s['supplier_id'] ?>"
                                class="text-xs <?= $s['supplier_status'] === 'active' ? 'text-red-500' : 'text-green-600' ?> hover:underline font-semibold">
                                    <?= $s['supplier_status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                </a>
                                <?php else: ?>
                                <span class="text-xs text-gray-300" title="Only senior admin can change supplier status">🔒</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div id="supplierModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center px-6">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6">
            <div class="flex items-center justify-between mb-5">
                <h3 id="modalTitle" class="font-black text-gray-800 text-lg">Add Supplier</h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="save_supplier" value="1">
                <input type="hidden" name="supplier_id" id="form_supplier_id">

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Supplier Name *</label>
                        <input type="text" name="supplier_name" id="form_name" required
                               placeholder="e.g. Popular Book Distribution Sdn Bhd"
                               class="w-full px-4 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Contact Person</label>
                        <input type="text" name="supplier_contact_person" id="form_contact"
                               placeholder="e.g. Mr. Tan"
                               class="w-full px-4 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Phone</label>
                            <input type="text" name="supplier_phone" id="form_phone"
                                   placeholder="03-12345678"
                                   class="w-full px-4 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Email</label>
                            <input type="email" name="supplier_email" id="form_email"
                                   placeholder="supplier@email.com"
                                   class="w-full px-4 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Address</label>
                        <textarea name="supplier_address" id="form_address" rows="2"
                                  placeholder="Full address"
                                  class="w-full px-4 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors resize-none"></textarea>
                    </div>
                    <div class="border-t border-gray-100 pt-4 mt-2">
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">Portal Access</p>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Username</label>
                                <input type="text" name="supplier_username" id="form_username"
                                    placeholder="e.g. popularbook"
                                    class="w-full px-4 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">
                                    Password <span id="passwordHint" class="text-gray-300 normal-case"></span>
                                </label>
                                <input type="text" name="supplier_password" id="form_password"
                                    placeholder="Leave blank to keep current"
                                    class="w-full px-4 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors">
                            </div>
                        </div>
                        <p class="text-xs text-gray-400 mt-2">Supplier can log in at: <code class="bg-gray-100 px-1.5 py-0.5 rounded text-gray-600">/comicstore/supplier/login.php</code></p>
                    </div>
                </div>

                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="closeModal()"
                            class="flex-1 border-2 border-gray-100 hover:bg-gray-50 text-gray-600 font-semibold py-2.5 rounded-xl text-sm transition-colors">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 rounded-xl text-sm transition-colors">
                        Save Supplier
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openAddModal() {
        document.getElementById('modalTitle').textContent = 'Add Supplier';
        document.getElementById('form_supplier_id').value = '';
        document.getElementById('form_name').value = '';
        document.getElementById('form_contact').value = '';
        document.getElementById('form_phone').value = '';
        document.getElementById('form_email').value = '';
        document.getElementById('form_address').value = '';
        document.getElementById('form_username').value = '';
        document.getElementById('form_password').value = '';
        document.getElementById('form_password').placeholder = 'Set initial password';
        document.getElementById('passwordHint').textContent = '';
        document.getElementById('supplierModal').classList.remove('hidden');
    }

    function openEditModal(data) {
        document.getElementById('modalTitle').textContent = 'Edit Supplier';
        document.getElementById('form_supplier_id').value = data.supplier_id;
        document.getElementById('form_name').value = data.supplier_name;
        document.getElementById('form_contact').value = data.supplier_contact_person || '';
        document.getElementById('form_phone').value = data.supplier_phone || '';
        document.getElementById('form_email').value = data.supplier_email || '';
        document.getElementById('form_address').value = data.supplier_address || '';
        document.getElementById('form_username').value = data.supplier_username || '';
        document.getElementById('form_password').value = '';
        document.getElementById('form_password').placeholder = 'Leave blank to keep current';
        document.getElementById('passwordHint').textContent = '(leave blank to keep)';
        document.getElementById('supplierModal').classList.remove('hidden');
    }

    function closeModal() {
        document.getElementById('supplierModal').classList.add('hidden');
    }
    </script>

</body>
</html>