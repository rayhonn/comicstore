<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin_or_staff();

$error = '';
$success = '';

function normalizeGenreText(
    mixed $value,
    string $label,
    int $maxLength,
    bool $required = false
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
            $label . ' cannot exceed ' .
            $maxLength .
            ' characters.'
        );
    }

    return $value;
}

function normalizeGenreId(mixed $value): int
{
    $id = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if ($id === false || $id === null) {
        throw new RuntimeException(
            'Invalid genre.'
        );
    }

    return $id;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? null;

    try {
        if (
            !is_string($action) ||
            !in_array(
                $action,
                ['add', 'edit', 'delete'],
                true
            )
        ) {
            throw new RuntimeException(
                'Invalid genre action.'
            );
        }

        if ($action === 'add' || $action === 'edit') {
            $genre_id = null;

            if ($action === 'edit') {
                $genre_id = normalizeGenreId(
                    $_POST['genre_id'] ?? null
                );

                $check = $pdo->prepare(
                    'SELECT genre_id
                     FROM genres
                     WHERE genre_id = ?'
                );
                $check->execute([$genre_id]);

                if (!$check->fetchColumn()) {
                    throw new RuntimeException(
                        'Genre not found.'
                    );
                }
            }

            $name = normalizeGenreText(
                $_POST['genre_name'] ?? '',
                'Genre name',
                50,
                true
            );

            $description = normalizeGenreText(
                $_POST['genre_description'] ?? '',
                'Genre description',
                2000
            );

            if ($action === 'add') {
                $insert = $pdo->prepare(
                    'INSERT INTO genres (
                        genre_name,
                        genre_description
                    ) VALUES (?, ?)'
                );
                $insert->execute([
                    $name,
                    $description,
                ]);

                $success =
                    'Genre added successfully!';
            } else {
                $update = $pdo->prepare(
                    'UPDATE genres
                     SET genre_name = ?,
                         genre_description = ?
                     WHERE genre_id = ?'
                );
                $update->execute([
                    $name,
                    $description,
                    $genre_id,
                ]);

                $success = 'Genre updated!';
            }
        } else {
            $genre_id = normalizeGenreId(
                $_POST['genre_id'] ?? null
            );

            $delete = $pdo->prepare(
                'DELETE FROM genres
                 WHERE genre_id = ?'
            );
            $delete->execute([$genre_id]);

            if ($delete->rowCount() !== 1) {
                throw new RuntimeException(
                    'Genre not found.'
                );
            }

            $success = 'Genre deleted.';
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$genres = $pdo->query("
    SELECT
        g.*,
        COUNT(pg.product_genres_product_id) AS product_count
    FROM genres g
    LEFT JOIN product_genres pg
        ON g.genre_id = pg.product_genres_genre_id
    GROUP BY g.genre_id
    ORDER BY g.genre_name ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Genres - MangaVault Admin</title>
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

    <div class="max-w-4xl mx-auto px-6 py-8">

        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-black text-gray-800">Manage Genres</h1>
                <p class="text-sm text-gray-400 mt-0.5"><?= count($genres) ?> genres</p>
            </div>
            <button onclick="openAddModal()"
                    class="bg-red-600 hover:bg-red-700 text-white font-semibold px-4 py-2 rounded-xl text-sm transition-colors">
                + Add Genre
            </button>
        </div>

        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-5">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <?php if (empty($genres)): ?>
            <div class="p-12 text-center">
                <div class="text-4xl mb-3">🏷️</div>
                <p class="text-gray-400">No genres yet.</p>
            </div>
            <?php else: ?>
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Genre</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Description</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Products</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($genres as $genre): ?>
                    <tr class="border-t border-gray-50 hover:bg-gray-50 transition-colors">
                        <td class="px-5 py-4">
                            <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($genre['genre_name']) ?></p>
                        </td>
                        <td class="px-5 py-4 text-sm text-gray-500">
                            <?= htmlspecialchars($genre['genre_description'] ?? '—') ?>
                        </td>
                        <td class="px-5 py-4">
                            <span class="bg-purple-100 text-purple-700 text-xs px-2 py-1 rounded-full font-semibold">
                                <?= $genre['product_count'] ?> products
                            </span>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex gap-2">
                                <button onclick="openEditModal(<?= htmlspecialchars(json_encode($genre)) ?>)"
                                        class="text-xs px-3 py-1.5 border border-blue-200 text-blue-600 rounded-lg hover:bg-blue-50 transition-colors">
                                    ✏️ Edit
                                </button>
                                <form method="POST" class="inline">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="genre_id" value="<?= (int) $genre['genre_id'] ?>">
                                    <button type="submit" onclick="return confirm('Delete this genre?')"
                                            class="text-xs px-3 py-1.5 border border-red-200 text-red-600 rounded-lg hover:bg-red-50 transition-colors">
                                        🗑️ Delete
                                    </button>
                                </form>
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
    <div id="genreModal" class="modal fixed inset-0 bg-black/50 z-50 items-center justify-center px-4">
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl">
            <div class="p-5 border-b border-gray-100 flex justify-between items-center">
                <h3 class="font-black text-gray-800" id="modalTitle">Add Genre</h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <form method="POST" class="p-5 space-y-4">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="genre_id" id="formId">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Genre Name *</label>
                    <input type="text" name="genre_name" id="formName" maxlength="50" required
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white transition-colors">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Description</label>
                    <input type="text" name="genre_description" id="formDesc" maxlength="2000"
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white transition-colors">
                </div>
                <div class="flex gap-3">
                    <button type="button" onclick="closeModal()"
                            class="flex-1 py-3 border-2 border-gray-100 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50">Cancel</button>
                    <button type="submit"
                            class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openAddModal() {
        document.getElementById('modalTitle').textContent = 'Add Genre';
        document.getElementById('formAction').value = 'add';
        document.getElementById('formId').value = '';
        document.getElementById('formName').value = '';
        document.getElementById('formDesc').value = '';
        document.getElementById('genreModal').classList.add('active');
    }
    function openEditModal(genre) {
        document.getElementById('modalTitle').textContent = 'Edit Genre';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('formId').value = genre.genre_id;
        document.getElementById('formName').value = genre.genre_name;
        document.getElementById('formDesc').value = genre.genre_description || '';
        document.getElementById('genreModal').classList.add('active');
    }
    function closeModal() { document.getElementById('genreModal').classList.remove('active'); }
    document.getElementById('genreModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
    </script>
</body>
</html>