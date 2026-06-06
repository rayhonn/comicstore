<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $recipient = trim($_POST['address_recipient_name']);
        $taman     = trim($_POST['address_taman'] ?? '');
        $street    = trim($_POST['address_street']);
        $city      = trim($_POST['address_city']);
        $state     = trim($_POST['address_state'] ?? '');
        $postal    = trim($_POST['address_postal_code']);
        $country   = trim($_POST['address_country'] ?? 'Malaysia');
        $phone     = trim($_POST['address_phone']);
        $is_default = isset($_POST['is_default']) ? 1 : 0;

        if ($is_default) {
            $pdo->prepare("UPDATE addresses SET address_is_default = 0 WHERE address_user_id = ?")
                ->execute([$user_id]);
        }

        $pdo->prepare("INSERT INTO addresses (address_user_id, address_recipient_name, address_taman, address_street, address_city, address_state, address_postal_code, address_country, address_phone, address_is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$user_id, $recipient, $taman, $street, $city, $state, $postal, $country, $phone, $is_default]);

        $_SESSION['addr_success'] = 'Address added successfully!';
        header('Location: addresses.php');
        exit;

    } elseif ($action === 'edit') {
        $addr_id   = $_POST['address_id'];
        $recipient = trim($_POST['address_recipient_name']);
        $taman     = trim($_POST['address_taman'] ?? '');
        $street    = trim($_POST['address_street']);
        $city      = trim($_POST['address_city']);
        $state     = trim($_POST['address_state'] ?? '');
        $postal    = trim($_POST['address_postal_code']);
        $country   = trim($_POST['address_country'] ?? 'Malaysia');
        $phone     = trim($_POST['address_phone']);
        $is_default = isset($_POST['is_default']) ? 1 : 0;

        $check = $pdo->prepare("SELECT address_id FROM addresses WHERE address_id = ? AND address_user_id = ?");
        $check->execute([$addr_id, $user_id]);
        if ($check->fetch()) {
            if ($is_default) {
                $pdo->prepare("UPDATE addresses SET address_is_default = 0 WHERE address_user_id = ?")
                    ->execute([$user_id]);
            }
            $pdo->prepare("UPDATE addresses SET address_recipient_name=?, address_taman=?, address_street=?, address_city=?, address_state=?, address_postal_code=?, address_country=?, address_phone=?, address_is_default=? WHERE address_id=?")
                ->execute([$recipient, $taman, $street, $city, $state, $postal, $country, $phone, $is_default, $addr_id]);
        }
        $_SESSION['addr_success'] = 'Address updated successfully!';
        header('Location: addresses.php');
        exit;

    } elseif ($action === 'delete') {
        $addr_id = $_POST['address_id'];
        $check = $pdo->prepare("SELECT address_id FROM addresses WHERE address_id = ? AND address_user_id = ?");
        $check->execute([$addr_id, $user_id]);
        if ($check->fetch()) {
            $pdo->prepare("DELETE FROM addresses WHERE address_id = ?")->execute([$addr_id]);
        }
        $_SESSION['addr_success'] = 'Address deleted.';
        header('Location: addresses.php');
        exit;

    } elseif ($action === 'set_default') {
        $addr_id = $_POST['address_id'];
        $check = $pdo->prepare("SELECT address_id FROM addresses WHERE address_id = ? AND address_user_id = ?");
        $check->execute([$addr_id, $user_id]);
        if ($check->fetch()) {
            $pdo->prepare("UPDATE addresses SET address_is_default = 0 WHERE address_user_id = ?")
                ->execute([$user_id]);
            $pdo->prepare("UPDATE addresses SET address_is_default = 1 WHERE address_id = ?")
                ->execute([$addr_id]);
        }
        header('Location: addresses.php');
        exit;
    }
}

$addresses = $pdo->prepare("SELECT * FROM addresses WHERE address_user_id = ? ORDER BY address_is_default DESC, address_created_at DESC");
$addresses->execute([$user_id]);
$addresses = $addresses->fetchAll(PDO::FETCH_ASSOC);

$success = $_SESSION['addr_success'] ?? '';
unset($_SESSION['addr_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Addresses - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html { scroll-behavior: smooth; }
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
        .modal { display: none; }
        .modal.active { display: flex; }
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
            <span class="text-gray-600">My Addresses</span>
        </p>

        <div class="flex gap-8 items-start">
            <?php include '../includes/customer_sidebar.php'; ?>

            <div class="flex-1 min-w-0">

                <?php if ($success): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5 flex items-center gap-2">
                    ✅ <?= htmlspecialchars($success) ?>
                </div>
                <?php endif; ?>

                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-xl font-black text-gray-800">My Addresses</h1>
                        <p class="text-sm text-gray-400 mt-0.5">Manage your delivery addresses</p>
                    </div>
                    <button onclick="openAddModal()"
                            class="bg-red-600 hover:bg-red-700 text-white font-semibold px-4 py-2 rounded-xl text-sm transition-colors flex items-center gap-2">
                        + Add Address
                    </button>
                </div>

                <?php if (count($addresses) === 0): ?>
                <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                    <div class="text-5xl mb-4">📍</div>
                    <p class="font-semibold text-gray-600 mb-1">No addresses saved</p>
                    <p class="text-gray-400 text-sm mb-6">Add a delivery address to speed up checkout.</p>
                    <button onclick="openAddModal()"
                            class="bg-red-600 hover:bg-red-700 text-white font-semibold px-6 py-2.5 rounded-xl text-sm transition-colors">
                        + Add Your First Address
                    </button>
                </div>
                <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($addresses as $addr): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-5 relative <?= $addr['address_is_default'] ? 'ring-2 ring-red-500' : '' ?>">
                        <?php if ($addr['address_is_default']): ?>
                        <span class="absolute top-4 right-4 bg-red-600 text-white text-xs px-2 py-0.5 rounded-full font-semibold">Default</span>
                        <?php endif; ?>

                        <div class="flex items-start gap-3 mb-4">
                            <div class="w-10 h-10 bg-red-50 rounded-xl flex items-center justify-center flex-shrink-0">
                                <span class="text-lg">📍</span>
                            </div>
                            <div class="flex-1 min-w-0 pr-16">
                                <p class="font-bold text-sm text-gray-800"><?= htmlspecialchars($addr['address_recipient_name']) ?></p>
                                <?php if (!empty($addr['address_taman'])): ?>
                                <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($addr['address_taman']) ?></p>
                                <?php endif; ?>
                                <p class="text-xs text-gray-500"><?= htmlspecialchars($addr['address_street']) ?></p>
                                <p class="text-xs text-gray-500"><?= htmlspecialchars($addr['address_city']) ?>, <?= htmlspecialchars($addr['address_postal_code']) ?></p>
                                <p class="text-xs text-gray-500"><?= htmlspecialchars($addr['address_country']) ?></p>
                                <p class="text-xs text-gray-400 mt-1">📞 <?= htmlspecialchars($addr['address_phone']) ?></p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 flex-wrap">
                            <?php if (!$addr['address_is_default']): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="set_default">
                                <input type="hidden" name="address_id" value="<?= $addr['address_id'] ?>">
                                <button type="submit" class="text-xs text-gray-500 hover:text-red-600 border border-gray-200 hover:border-red-300 px-3 py-1.5 rounded-lg transition-colors">
                                    Set Default
                                </button>
                            </form>
                            <?php endif; ?>
                            <button onclick="openEditModal(<?= htmlspecialchars(json_encode($addr)) ?>)"
                                    class="text-xs text-gray-500 hover:text-blue-600 border border-gray-200 hover:border-blue-300 px-3 py-1.5 rounded-lg transition-colors">
                                ✏️ Edit
                            </button>
                            <button onclick="openDeleteModal(<?= $addr['address_id'] ?>, '<?= htmlspecialchars($addr['address_recipient_name']) ?>')"
                                    class="text-xs text-gray-500 hover:text-red-600 border border-gray-200 hover:border-red-300 px-3 py-1.5 rounded-lg transition-colors">
                                🗑️ Delete
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div id="addressModal" class="modal fixed inset-0 bg-black/50 z-50 items-center justify-center px-4">
        <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl max-h-[90vh] overflow-y-auto">
            <div class="p-6 border-b border-gray-100 flex justify-between items-center">
                <h3 class="font-black text-gray-800" id="modalTitle">Add New Address</h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 text-xl">✕</button>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="address_id" id="formAddressId">

                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Recipient Name *</label>
                        <input type="text" name="address_recipient_name" id="formRecipient" required
                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Phone *</label>
                        <input type="text" name="address_phone" id="formPhone" required
                               oninput="this.value = this.value.replace(/[^0-9]/g, '')" maxlength="11"
                               placeholder="01234567890"
                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Taman / Apartment *</label>
                        <input type="text" name="address_taman" id="formTaman" required
                               placeholder="e.g. Taman Desa Jaya"
                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Street Address *</label>
                        <input type="text" name="address_street" id="formStreet" required
                               placeholder="e.g. No. 12, Jalan ABC"
                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">City *</label>
                        <input type="text" name="address_city" id="formCity" required
                                class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Postal Code *</label>
                        <input type="text" name="address_postal_code" id="formPostal" required
                                oninput="this.value = this.value.replace(/[^0-9]/g, '')" maxlength="5"
                                placeholder="e.g. 80300"
                                class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">State *</label>
                        <select name="address_state" id="formState" required onchange="autoPostcode(this.value)"
                                class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                            <option value="">Select state</option>
                            <option>Johor</option>
                            <option>Kedah</option>
                            <option>Kelantan</option>
                            <option>Melaka</option>
                            <option>Negeri Sembilan</option>
                            <option>Pahang</option>
                            <option>Perak</option>
                            <option>Perlis</option>
                            <option>Pulau Pinang</option>
                            <option>Sabah</option>
                            <option>Sarawak</option>
                            <option>Selangor</option>
                            <option>Terengganu</option>
                            <option>Wilayah Persekutuan Kuala Lumpur</option>
                            <option>Wilayah Persekutuan Labuan</option>
                            <option>Wilayah Persekutuan Putrajaya</option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Country</label>
                        <input type="text" name="address_country" id="formCountry" value="Malaysia" readonly
                                class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm bg-gray-100 text-gray-400 cursor-not-allowed">
                    </div>
                    <div class="col-span-2">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" name="is_default" id="formIsDefault" class="w-4 h-4 accent-red-600">
                            <span class="text-sm text-gray-700 font-medium">Set as default address</span>
                        </label>
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" onclick="closeModal()"
                            class="flex-1 py-3 border-2 border-gray-100 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors">
                        Save Address
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirm Modal -->
    <div id="deleteModal" class="modal fixed inset-0 bg-black/50 z-50 items-center justify-center px-4">
        <div class="bg-white rounded-2xl w-full max-w-sm shadow-2xl p-6 text-center">
            <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="text-2xl">🗑️</span>
            </div>
            <h3 class="font-black text-gray-800 mb-2">Delete Address?</h3>
            <p class="text-sm text-gray-500 mb-6">Are you sure you want to delete <strong id="deleteAddrName"></strong>'s address?</p>
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="address_id" id="deleteAddrId">
                <div class="flex gap-3">
                    <button type="button" onclick="closeDeleteModal()"
                            class="flex-1 py-3 border-2 border-gray-100 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors">
                        Delete
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openAddModal() {
        document.getElementById('modalTitle').textContent = 'Add New Address';
        document.getElementById('formAction').value = 'add';
        document.getElementById('formAddressId').value = '';
        document.getElementById('formRecipient').value = '';
        document.getElementById('formPhone').value = '';
        document.getElementById('formTaman').value = '';
        document.getElementById('formStreet').value = '';
        document.getElementById('formCity').value = '';
        document.getElementById('formPostal').value = '';
        document.getElementById('formState').value = '';
        document.getElementById('formCountry').value = 'Malaysia';
        document.getElementById('formIsDefault').checked = false;
        document.getElementById('addressModal').classList.add('active');
    }

    function openEditModal(addr) {
        document.getElementById('modalTitle').textContent = 'Edit Address';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('formAddressId').value = addr.address_id;
        document.getElementById('formRecipient').value = addr.address_recipient_name || '';
        document.getElementById('formPhone').value = addr.address_phone || '';
        document.getElementById('formTaman').value = addr.address_taman || '';
        document.getElementById('formStreet').value = addr.address_street || '';
        document.getElementById('formCity').value = addr.address_city || '';
        document.getElementById('formPostal').value = addr.address_postal_code || '';
        document.getElementById('formState').value = addr.address_state || '';
        document.getElementById('formCountry').value = addr.address_country || 'Malaysia';
        document.getElementById('formIsDefault').checked = addr.address_is_default == 1;
        document.getElementById('addressModal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('addressModal').classList.remove('active');
    }

    function openDeleteModal(id, name) {
        document.getElementById('deleteAddrId').value = id;
        document.getElementById('deleteAddrName').textContent = name;
        document.getElementById('deleteModal').classList.add('active');
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.remove('active');
    }

    // Close modal on backdrop click
    document.getElementById('addressModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
    document.getElementById('deleteModal').addEventListener('click', function(e) {
        if (e.target === this) closeDeleteModal();
    });

    const statePostcodePrefix = {
        'Johor': '79',
        'Kedah': '05',
        'Kelantan': '15',
        'Melaka': '75',
        'Negeri Sembilan': '70',
        'Pahang': '25',
        'Perak': '30',
        'Perlis': '02',
        'Pulau Pinang': '10',
        'Sabah': '88',
        'Sarawak': '93',
        'Selangor': '40',
        'Terengganu': '20',
        'Wilayah Persekutuan Kuala Lumpur': '50',
        'Wilayah Persekutuan Labuan': '87',
        'Wilayah Persekutuan Putrajaya': '62',
    };

    function autoPostcode(state) {
        const postalInput = document.getElementById('formPostal');
        const prefix = statePostcodePrefix[state] || '';
        if (prefix && (!postalInput.value || postalInput.value.length <= 2)) {
            postalInput.value = prefix;
            postalInput.focus();
        }
    }
    </script>

</body>
</html>