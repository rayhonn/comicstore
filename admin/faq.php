<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

$success = '';
$error = '';

function normalizeFaqText(
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

function normalizeFaqId(mixed $value): int
{
    $id = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($id === false || $id === null) {
        throw new RuntimeException('Invalid FAQ.');
    }

    return $id;
}

function normalizeFaqOrder(mixed $value): int
{
    $order = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 0,
                'max_range' => 1000000,
            ],
        ]
    );

    if ($order === false || $order === null) {
        throw new RuntimeException(
            'Display order must be a non-negative integer.'
        );
    }

    return $order;
}

function validateFaqFields(array $input): array
{
    return [
        normalizeFaqText(
            $input['faq_category'] ?? '',
            'FAQ category',
            100
        ),
        normalizeFaqText(
            $input['faq_question'] ?? '',
            'FAQ question',
            500
        ),
        normalizeFaqText(
            $input['faq_answer'] ?? '',
            'FAQ answer',
            5000
        ),
        normalizeFaqOrder($input['faq_order'] ?? 0),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? null;

    try {
        if (!is_string($action)) {
            throw new RuntimeException('Invalid FAQ action.');
        }

        if ($action === 'add') {
            [$category, $question, $answer, $order] =
                validateFaqFields($_POST);

            $insert_faq = $pdo->prepare(
                'INSERT INTO faqs (
                    faq_category,
                    faq_question,
                    faq_answer,
                    faq_order
                ) VALUES (?, ?, ?, ?)'
            );
            $insert_faq->execute([
                $category,
                $question,
                $answer,
                $order,
            ]);
            $success = 'FAQ added successfully!';
        } elseif ($action === 'edit') {
            $faq_id = normalizeFaqId($_POST['faq_id'] ?? null);
            [$category, $question, $answer, $order] =
                validateFaqFields($_POST);
            $active = isset($_POST['faq_is_active']) ? 1 : 0;

            $faq_check = $pdo->prepare(
                'SELECT faq_id FROM faqs WHERE faq_id = ?'
            );
            $faq_check->execute([$faq_id]);
            if (!$faq_check->fetchColumn()) {
                throw new RuntimeException('FAQ not found.');
            }

            $update_faq = $pdo->prepare(
                'UPDATE faqs
                 SET faq_category = ?,
                     faq_question = ?,
                     faq_answer = ?,
                     faq_order = ?,
                     faq_is_active = ?
                 WHERE faq_id = ?'
            );
            $update_faq->execute([
                $category,
                $question,
                $answer,
                $order,
                $active,
                $faq_id,
            ]);
            $success = 'FAQ updated successfully!';
        } elseif ($action === 'delete') {
            $faq_id = normalizeFaqId($_POST['faq_id'] ?? null);

            $delete_faq = $pdo->prepare(
                'DELETE FROM faqs WHERE faq_id = ?'
            );
            $delete_faq->execute([$faq_id]);

            if ($delete_faq->rowCount() !== 1) {
                throw new RuntimeException('FAQ not found.');
            }

            $success = 'FAQ deleted.';
        } elseif ($action === 'toggle') {
            $faq_id = normalizeFaqId($_POST['faq_id'] ?? null);

            $toggle_faq = $pdo->prepare(
                'UPDATE faqs
                 SET faq_is_active = NOT faq_is_active
                 WHERE faq_id = ?'
            );
            $toggle_faq->execute([$faq_id]);

            if ($toggle_faq->rowCount() !== 1) {
                throw new RuntimeException('FAQ not found.');
            }

            header('Location: faq.php');
            exit;
        } else {
            throw new RuntimeException('Invalid FAQ action.');
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        app_error_log('FAQ management failed: ' . $e->getMessage());
        $error = 'Unable to update FAQ content.';
    }
}

$faqs = $pdo->query(
    'SELECT * FROM faqs ORDER BY faq_category, faq_order ASC'
)->fetchAll(PDO::FETCH_ASSOC);
$faqs_grouped = [];
foreach ($faqs as $faq) {
    $faqs_grouped[$faq['faq_category']][] = $faq;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage FAQ - MangaVault Admin</title>
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

    <div class="max-w-6xl mx-auto px-6 py-8">

        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-black text-gray-800">Manage FAQ</h1>
                <p class="text-sm text-gray-400 mt-0.5">Add, edit or remove FAQ questions</p>
            </div>
            <button onclick="openAddModal()"
                    class="bg-red-600 hover:bg-red-700 text-white font-semibold px-4 py-2 rounded-xl text-sm transition-colors flex items-center gap-2">
                + Add FAQ
            </button>
        </div>

        <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-5">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (empty($faqs)): ?>
        <div class="bg-white rounded-2xl p-12 text-center shadow-sm">
            <div class="text-5xl mb-4">❓</div>
            <p class="text-gray-500 font-medium mb-4">No FAQs yet</p>
            <button onclick="openAddModal()" class="bg-red-600 hover:bg-red-700 text-white font-semibold px-6 py-2.5 rounded-xl text-sm transition-colors">
                Add First FAQ
            </button>
        </div>
        <?php else: ?>

        <?php foreach ($faqs_grouped as $category => $items): ?>
        <div class="mb-6">
            <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wide mb-3"><?= htmlspecialchars($category) ?></h2>
            <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                <?php foreach ($items as $i => $faq): ?>
                <div class="flex items-start gap-4 p-5 <?= $i > 0 ? 'border-t border-gray-50' : '' ?> <?= !$faq['faq_is_active'] ? 'opacity-50' : '' ?>">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($faq['faq_question']) ?></p>
                            <?php if (!$faq['faq_is_active']): ?>
                            <span class="bg-gray-100 text-gray-500 text-xs px-2 py-0.5 rounded-full">Hidden</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-gray-400 line-clamp-2"><?= htmlspecialchars($faq['faq_answer']) ?></p>
                        <p class="text-xs text-gray-300 mt-1">Order: <?= $faq['faq_order'] ?></p>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <form method="POST" class="inline">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="faq_id" value="<?= (int) $faq['faq_id'] ?>">
                            <button type="submit" class="text-xs px-3 py-1.5 rounded-lg border border-gray-200 hover:border-gray-300 text-gray-500 hover:text-gray-700 transition-colors">
                                <?= $faq['faq_is_active'] ? '👁️ Hide' : '👁️ Show' ?>
                            </button>
                        </form>
                        <button onclick="openEditModal(<?= htmlspecialchars(json_encode($faq), ENT_QUOTES, 'UTF-8') ?>)"
                                class="text-xs px-3 py-1.5 rounded-lg border border-blue-200 text-blue-600 hover:bg-blue-50 transition-colors">
                            ✏️ Edit
                        </button>
                        <button onclick="openDeleteModal(<?= (int) $faq['faq_id'] ?>, '<?= htmlspecialchars(addslashes($faq['faq_question'])) ?>')"
                                class="text-xs px-3 py-1.5 rounded-lg border border-red-200 text-red-600 hover:bg-red-50 transition-colors">
                            🗑️ Delete
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>
    </div>

    <!-- Add/Edit Modal -->
    <div id="faqModal" class="modal fixed inset-0 bg-black/50 z-50 items-center justify-center px-4">
        <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl max-h-[90vh] overflow-y-auto">
            <div class="p-6 border-b border-gray-100 flex justify-between items-center">
                <h3 class="font-black text-gray-800" id="modalTitle">Add FAQ</h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 text-xl">✕</button>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="faq_id" id="formId">

                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Category *</label>
                    <input type="text" name="faq_category" maxlength="100" id="formCategory" required
                           placeholder="e.g. 🛒 Orders & Payment"
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Question *</label>
                    <input type="text" name="faq_question" maxlength="500" id="formQuestion" required
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Answer *</label>
                    <textarea name="faq_answer" maxlength="5000" id="formAnswer" required rows="4"
                              class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white resize-none"></textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Display Order</label>
                    <input type="number" name="faq_order" id="formOrder" value="0" min="0"
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                </div>
                <div id="activeToggle" class="hidden">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" name="faq_is_active" id="formActive" class="w-4 h-4 accent-red-600">
                        <span class="text-sm text-gray-700 font-medium">Visible to customers</span>
                    </label>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" onclick="closeModal()"
                            class="flex-1 py-3 border-2 border-gray-100 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors">
                        Save FAQ
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="modal fixed inset-0 bg-black/50 z-50 items-center justify-center px-4">
        <div class="bg-white rounded-2xl w-full max-w-sm shadow-2xl p-6 text-center">
            <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="text-2xl">🗑️</span>
            </div>
            <h3 class="font-black text-gray-800 mb-2">Delete FAQ?</h3>
            <p class="text-sm text-gray-500 mb-6" id="deleteQuestion"></p>
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="faq_id" id="deleteId">
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
        document.getElementById('modalTitle').textContent = 'Add FAQ';
        document.getElementById('formAction').value = 'add';
        document.getElementById('formId').value = '';
        document.getElementById('formCategory').value = '';
        document.getElementById('formQuestion').value = '';
        document.getElementById('formAnswer').value = '';
        document.getElementById('formOrder').value = '0';
        document.getElementById('activeToggle').classList.add('hidden');
        document.getElementById('faqModal').classList.add('active');
    }

    function openEditModal(faq) {
        document.getElementById('modalTitle').textContent = 'Edit FAQ';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('formId').value = faq.faq_id;
        document.getElementById('formCategory').value = faq.faq_category;
        document.getElementById('formQuestion').value = faq.faq_question;
        document.getElementById('formAnswer').value = faq.faq_answer;
        document.getElementById('formOrder').value = faq.faq_order;
        document.getElementById('formActive').checked = faq.faq_is_active == 1;
        document.getElementById('activeToggle').classList.remove('hidden');
        document.getElementById('faqModal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('faqModal').classList.remove('active');
    }

    function openDeleteModal(id, question) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteQuestion').textContent = question;
        document.getElementById('deleteModal').classList.add('active');
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.remove('active');
    }

    document.getElementById('faqModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
    document.getElementById('deleteModal').addEventListener('click', function(e) { if (e.target === this) closeDeleteModal(); });
    </script>
</body>
</html>