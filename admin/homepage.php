<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/upload_helper.php';
require_once __DIR__ . '/../includes/logger.php';

require_admin();

$success = '';
$error = '';
$newFileName = '';

$slideDefinitions = [
    'main' => [
        'title' => 'Main Hero',
        'description' =>
            'Background behind the main MangaVault welcome message.',
        'fallback' =>
            '../assets/images/manga cover.avif',
        'fallback_label' =>
            'Default manga collage',
        'placeholder' =>
            'linear-gradient(135deg, #111827, #7f1d1d)',
    ],
    'rankings' => [
        'title' => 'Rankings Hero',
        'description' =>
            'Optional background behind the weekly top reads slide.',
        'fallback' => '',
        'fallback_label' =>
            'Built-in dark amber gradient',
        'placeholder' =>
            'linear-gradient(135deg, #130e09, #92400e)',
    ],
    'ebook' => [
        'title' => 'E-Book Hero',
        'description' =>
            'Optional background behind the digital collection slide.',
        'fallback' => '',
        'fallback_label' =>
            'Built-in indigo gradient',
        'placeholder' =>
            'linear-gradient(135deg, #101332, #4338ca)',
    ],
];

$uploadDir =
    __DIR__ .
    '/../assets/images/homepage';

function normalizeHomepageSlideKey(
    mixed $value,
    array $slideDefinitions
): string {
    if (!is_string($value)) {
        throw new RuntimeException(
            'Invalid homepage slide.'
        );
    }

    $value = trim($value);

    if (!array_key_exists(
        $value,
        $slideDefinitions
    )) {
        throw new RuntimeException(
            'Invalid homepage slide.'
        );
    }

    return $value;
}

function homepageStoredImagePath(
    string $uploadDir,
    ?string $fileName
): string {
    if ($fileName === null || $fileName === '') {
        return '';
    }

    return
        rtrim($uploadDir, '/\\') .
        DIRECTORY_SEPARATOR .
        basename($fileName);
}

function removeHomepageStoredImage(
    string $uploadDir,
    ?string $fileName
): void {
    $path = homepageStoredImagePath(
        $uploadDir,
        $fileName
    );

    if ($path === '' || !is_file($path)) {
        return;
    }

    if (!unlink($path)) {
        app_log(
            'WARNING',
            'Homepage',
            'Old homepage hero image could not be deleted.',
            [
                'file' => basename(
                    (string) $fileName
                ),
            ]
        );
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? null;

    try {
        if (!is_string($action)) {
            throw new RuntimeException(
                'Invalid homepage action.'
            );
        }

        $slideKey = normalizeHomepageSlideKey(
            $_POST['slide_key'] ?? null,
            $slideDefinitions
        );

        if ($action === 'replace_background') {
            if (!isset(
                $_FILES['hero_background']
            ) || !is_array(
                $_FILES['hero_background']
            )) {
                throw new RuntimeException(
                    'Please choose a homepage hero image.'
                );
            }

            try {
                $newFileName =
                    uploadHomepageHeroImage(
                        $_FILES['hero_background'],
                        $uploadDir
                    );
            } catch (Exception $e) {
                throw new RuntimeException(
                    $e->getMessage(),
                    0,
                    $e
                );
            }

            if ($newFileName === '') {
                throw new RuntimeException(
                    'Please choose a homepage hero image.'
                );
            }

            $pdo->beginTransaction();

            try {
                $currentStmt = $pdo->prepare("
                    SELECT hero_slide_background
                    FROM homepage_hero_slides
                    WHERE hero_slide_key = ?
                    FOR UPDATE
                ");

                $currentStmt->execute([
                    $slideKey,
                ]);

                $oldFileName =
                    $currentStmt->fetchColumn();

                $saveStmt = $pdo->prepare("
                    INSERT INTO homepage_hero_slides (
                        hero_slide_key,
                        hero_slide_background,
                        hero_slide_updated_by
                    ) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        hero_slide_background =
                            VALUES(
                                hero_slide_background
                            ),
                        hero_slide_updated_by =
                            VALUES(
                                hero_slide_updated_by
                            )
                ");

                $saveStmt->execute([
                    $slideKey,
                    $newFileName,
                    (int) $_SESSION['user_id'],
                ]);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $e;
            }

            if (
                is_string($oldFileName) &&
                $oldFileName !== '' &&
                $oldFileName !== $newFileName
            ) {
                removeHomepageStoredImage(
                    $uploadDir,
                    $oldFileName
                );
            }

            $newFileName = '';
            $success =
                $slideDefinitions[$slideKey][
                    'title'
                ] .
                ' background updated successfully.';
        } elseif ($action === 'reset_background') {
            $pdo->beginTransaction();

            try {
                $currentStmt = $pdo->prepare("
                    SELECT hero_slide_background
                    FROM homepage_hero_slides
                    WHERE hero_slide_key = ?
                    FOR UPDATE
                ");

                $currentStmt->execute([
                    $slideKey,
                ]);

                $oldFileName =
                    $currentStmt->fetchColumn();

                $resetStmt = $pdo->prepare("
                    INSERT INTO homepage_hero_slides (
                        hero_slide_key,
                        hero_slide_background,
                        hero_slide_updated_by
                    ) VALUES (?, NULL, ?)
                    ON DUPLICATE KEY UPDATE
                        hero_slide_background = NULL,
                        hero_slide_updated_by =
                            VALUES(
                                hero_slide_updated_by
                            )
                ");

                $resetStmt->execute([
                    $slideKey,
                    (int) $_SESSION['user_id'],
                ]);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $e;
            }

            if (
                is_string($oldFileName) &&
                $oldFileName !== ''
            ) {
                removeHomepageStoredImage(
                    $uploadDir,
                    $oldFileName
                );
            }

            $success =
                $slideDefinitions[$slideKey][
                    'title'
                ] .
                ' background reset successfully.';
        } else {
            throw new RuntimeException(
                'Invalid homepage action.'
            );
        }
    } catch (RuntimeException $e) {
        if ($newFileName !== '') {
            removeHomepageStoredImage(
                $uploadDir,
                $newFileName
            );
            $newFileName = '';
        }

        $error = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($newFileName !== '') {
            removeHomepageStoredImage(
                $uploadDir,
                $newFileName
            );
            $newFileName = '';
        }

        app_error_log(
            'Homepage hero update failed: ' .
            $e->getMessage()
        );

        $error =
            'Unable to update the homepage hero background.';
    }
}

$storedSlides = [];

try {
    $slideRows = $pdo->query("
        SELECT
            hero_slide_key,
            hero_slide_background,
            hero_slide_updated_at
        FROM homepage_hero_slides
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($slideRows as $slideRow) {
        $storedSlides[
            (string) $slideRow[
                'hero_slide_key'
            ]
        ] = $slideRow;
    }
} catch (Throwable $e) {
    app_error_log(
        'Homepage hero settings could not be loaded: ' .
        $e->getMessage()
    );

    $error =
        'Homepage settings table is unavailable. Run the provided SQL migration first.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Homepage Management - MangaVault Admin
    </title>

    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-100">

    <?php
    include __DIR__ .
        '/../includes/admin_navbar.php';
    ?>

    <main class="mx-auto max-w-7xl px-6 py-8">
        <div
            class="mb-8 flex flex-col justify-between gap-4 sm:flex-row sm:items-end"
        >
            <div>
                <p
                    class="text-xs font-black uppercase tracking-[0.18em] text-red-600"
                >
                    Storefront Content
                </p>

                <h1
                    class="mt-2 text-3xl font-black tracking-[-0.04em] text-gray-900"
                >
                    Homepage Management
                </h1>

                <p
                    class="mt-2 max-w-2xl text-sm leading-6 text-gray-500"
                >
                    Replace the three homepage hero backgrounds.
                    Product cards continue to use their own product cover images.
                </p>
            </div>

            <a
                href="../index.php"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-5 py-3 text-sm font-bold text-gray-700 transition hover:border-red-200 hover:text-red-600"
            >
                Preview Homepage
                <span aria-hidden="true">↗</span>
            </a>
        </div>

        <?php if ($success !== ''): ?>
            <div
                class="mb-6 rounded-2xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-semibold text-green-700"
                role="status"
            >
                <?= htmlspecialchars(
                    $success,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div
                class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-semibold text-red-700"
                role="alert"
            >
                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>
        <?php endif; ?>

        <div
            class="mb-8 grid gap-4 rounded-2xl border border-blue-100 bg-blue-50 p-5 text-sm text-blue-900 md:grid-cols-3"
        >
            <div>
                <p class="font-black">Recommended size</p>
                <p class="mt-1 text-blue-700">
                    1920 × 900 pixels
                </p>
            </div>

            <div>
                <p class="font-black">Accepted files</p>
                <p class="mt-1 text-blue-700">
                    JPG, PNG, WebP or AVIF
                </p>
            </div>

            <div>
                <p class="font-black">Maximum size</p>
                <p class="mt-1 text-blue-700">
                    5 MB per background
                </p>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <?php foreach (
                $slideDefinitions as
                $slideKey => $definition
            ): ?>
                <?php
                $storedBackground =
                    (string) (
                        $storedSlides[$slideKey][
                            'hero_slide_background'
                        ] ?? ''
                    );

                $customImageUrl =
                    $storedBackground !== ''
                        ? '../assets/images/homepage/' .
                            rawurlencode(
                                basename(
                                    $storedBackground
                                )
                            )
                        : '';

                $displayImageUrl =
                    $customImageUrl !== ''
                        ? $customImageUrl
                        : $definition['fallback'];

                $updatedAt =
                    (string) (
                        $storedSlides[$slideKey][
                            'hero_slide_updated_at'
                        ] ?? ''
                    );
                ?>

                <section
                    class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm"
                >
                    <div
                        class="relative aspect-[16/9] overflow-hidden"
                        style="background: <?= htmlspecialchars(
                            $definition['placeholder'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>;"
                    >
                        <?php if (
                            $displayImageUrl !== ''
                        ): ?>
                            <img
                                id="preview-<?= htmlspecialchars(
                                    $slideKey,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                src="<?= htmlspecialchars(
                                    $displayImageUrl,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                alt="<?= htmlspecialchars(
                                    $definition['title'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?> background preview"
                                class="h-full w-full object-cover"
                            >
                        <?php else: ?>
                            <img
                                id="preview-<?= htmlspecialchars(
                                    $slideKey,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                src=""
                                alt=""
                                class="hidden h-full w-full object-cover"
                            >
                        <?php endif; ?>

                        <div
                            class="absolute inset-0 bg-gradient-to-t from-black/65 via-black/5 to-transparent"
                        ></div>

                        <div
                            class="absolute inset-x-0 bottom-0 p-5 text-white"
                        >
                            <p
                                class="text-xs font-black uppercase tracking-[0.16em] text-white/65"
                            >
                                <?= $customImageUrl !== ''
                                    ? 'Custom background'
                                    : 'Default background' ?>
                            </p>

                            <h2 class="mt-1 text-xl font-black">
                                <?= htmlspecialchars(
                                    $definition['title'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </h2>
                        </div>
                    </div>

                    <div class="p-6">
                        <p class="text-sm leading-6 text-gray-500">
                            <?= htmlspecialchars(
                                $definition[
                                    'description'
                                ],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </p>

                        <div
                            class="mt-4 rounded-xl bg-gray-50 px-4 py-3 text-xs text-gray-500"
                        >
                            <p>
                                Current:
                                <span
                                    class="font-bold text-gray-700"
                                >
                                    <?= htmlspecialchars(
                                        $storedBackground !== ''
                                            ? $storedBackground
                                            : $definition[
                                                'fallback_label'
                                            ],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>
                            </p>

                            <?php if ($updatedAt !== ''): ?>
                                <p class="mt-1">
                                    Updated:
                                    <?= htmlspecialchars(
                                        $updatedAt,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <form
                            method="POST"
                            enctype="multipart/form-data"
                            class="mt-5"
                        >
                            <?php csrf_field(); ?>

                            <input
                                type="hidden"
                                name="action"
                                value="replace_background"
                            >

                            <input
                                type="hidden"
                                name="slide_key"
                                value="<?= htmlspecialchars(
                                    $slideKey,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                            >

                            <label
                                class="block text-xs font-black uppercase tracking-[0.14em] text-gray-500"
                                for="file-<?= htmlspecialchars(
                                    $slideKey,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                            >
                                Choose new background
                            </label>

                            <input
                                id="file-<?= htmlspecialchars(
                                    $slideKey,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                type="file"
                                name="hero_background"
                                accept=".jpg,.jpeg,.png,.webp,.avif,image/jpeg,image/png,image/webp,image/avif"
                                required
                                data-preview-target="preview-<?= htmlspecialchars(
                                    $slideKey,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                class="mt-2 block w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-3 text-xs text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-red-50 file:px-3 file:py-2 file:text-xs file:font-bold file:text-red-600"
                            >

                            <button
                                type="submit"
                                class="mt-4 w-full rounded-xl bg-red-600 px-4 py-3 text-sm font-black text-white transition hover:bg-red-700"
                            >
                                Save Background
                            </button>
                        </form>

                        <?php if (
                            $storedBackground !== ''
                        ): ?>
                            <form
                                method="POST"
                                class="mt-3"
                                onsubmit="return confirm('Reset this slide to its default background?');"
                            >
                                <?php csrf_field(); ?>

                                <input
                                    type="hidden"
                                    name="action"
                                    value="reset_background"
                                >

                                <input
                                    type="hidden"
                                    name="slide_key"
                                    value="<?= htmlspecialchars(
                                        $slideKey,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                >

                                <button
                                    type="submit"
                                    class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm font-bold text-gray-500 transition hover:border-red-200 hover:bg-red-50 hover:text-red-600"
                                >
                                    Reset to Default
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </main>

    <script>
        document.querySelectorAll(
            '[data-preview-target]'
        ).forEach(input => {
            input.addEventListener(
                'change',
                () => {
                    const file =
                        input.files &&
                        input.files[0];

                    if (!file) {
                        return;
                    }

                    const preview =
                        document.getElementById(
                            input.dataset
                                .previewTarget
                        );

                    if (!preview) {
                        return;
                    }

                    preview.src =
                        URL.createObjectURL(file);
                    preview.alt =
                        'Selected homepage background preview';
                    preview.classList.remove(
                        'hidden'
                    );
                }
            );
        });
    </script>

</body>
</html>