<?php

declare(strict_types=1);

$root = __DIR__;

$productPath =
    $root .
    DIRECTORY_SEPARATOR .
    'customer' .
    DIRECTORY_SEPARATOR .
    'product_detail.php';

$backupPath =
    $productPath .
    '.before-final-refinement';

if (!is_file($productPath)) {
    fwrite(
        STDERR,
        "customer/product_detail.php was not found.\n"
    );
    exit(1);
}

$content = file_get_contents(
    $productPath
);

if ($content === false) {
    fwrite(
        STDERR,
        "Unable to read customer/product_detail.php.\n"
    );
    exit(1);
}

$usesCrLf = str_contains(
    $content,
    "\r\n"
);

$content = str_replace(
    "\r\n",
    "\n",
    $content
);

if (!is_file($backupPath)) {
    if (!copy(
        $productPath,
        $backupPath
    )) {
        fwrite(
            STDERR,
            "Unable to create the local backup.\n"
        );
        exit(1);
    }
}

function replaceRequired(
    string $content,
    string $search,
    string $replacement,
    string $label
): string {
    if (!str_contains(
        $content,
        $search
    )) {
        fwrite(
            STDERR,
            "Unable to locate {$label}.\n"
        );
        exit(1);
    }

    return str_replace(
        $search,
        $replacement,
        $content
    );
}

if (
    str_contains(
        $content,
        'product_detail_refinement.css?v=1'
    )
) {
    $content = str_replace(
        'product_detail_refinement.css?v=1',
        'product_detail_refinement.css?v=2',
        $content
    );
}

if (
    !str_contains(
        $content,
        'product_review_success'
    )
) {
    $reviewInitialisation = <<<'PHP'
$review_success = '';
$review_error = '';
PHP;

    $reviewInitialisationReplacement = <<<'PHP'
$review_success = '';
$review_error = '';

if (
    isset($_SESSION['product_review_success']) &&
    is_string($_SESSION['product_review_success'])
) {
    $review_success =
        $_SESSION['product_review_success'];

    unset(
        $_SESSION['product_review_success']
    );
}
PHP;

    $content = replaceRequired(
        $content,
        $reviewInitialisation,
        $reviewInitialisationReplacement,
        'review flash initialisation'
    );
}

$oldReviewSuccess = <<<'PHP'
            $review_success =
                'Review submitted successfully!';
            $can_review = false;
            $existing_review = [
                'review_status' => 'approved',
                'review_rating' => (int) $rating,
                'review_comment' => $comment,
            ];
PHP;

$newReviewSuccess = <<<'PHP'
            $_SESSION['product_review_success'] =
                'Review submitted successfully!';

            header(
                'Location: product_detail.php?id=' .
                $id .
                '#customer-reviews'
            );
            exit;
PHP;

if (
    str_contains(
        $content,
        $oldReviewSuccess
    )
) {
    $content = str_replace(
        $oldReviewSuccess,
        $newReviewSuccess,
        $content
    );
}

if (
    !str_contains(
        $content,
        'id="customer-reviews"'
    )
) {
    $reviewSectionMarker = <<<'HTML'
        <!-- Reviews Section -->
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-8">
HTML;

    $reviewSectionReplacement = <<<'HTML'
        <!-- Reviews Section -->
        <div
            id="customer-reviews"
            class="bg-white rounded-2xl shadow-sm p-6 mb-8 scroll-mt-28"
        >
HTML;

    $content = replaceRequired(
        $content,
        $reviewSectionMarker,
        $reviewSectionReplacement,
        'customer review section'
    );
}

$oldExistingReview = <<<'HTML'
            <?php elseif ($existing_review): ?>
            <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-6">
                <p class="text-sm font-semibold text-blue-700 mb-1">
                    <?= $existing_review['review_status'] === 'pending' ? '⏳ Your review is pending approval.' : '✅ Your review has been published.' ?>
                </p>
                <div class="flex gap-0.5 mb-1">
                    <?php for ($s = 1; $s <= 5; $s++): ?>
                    <span class="<?= $s <= $existing_review['review_rating'] ? 'text-yellow-400' : 'text-gray-300' ?>">★</span>
                    <?php endfor; ?>
                </div>
                <p class="text-sm text-gray-600"><?= htmlspecialchars($existing_review['review_comment']) ?></p>
            </div>
            <?php endif; ?>
HTML;

$newExistingReview = <<<'HTML'
            <?php elseif (
                $existing_review &&
                $existing_review['review_status'] === 'pending'
            ): ?>
            <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-6">
                <p class="text-sm font-semibold text-blue-700 mb-1">
                    ⏳ Your review is pending approval.
                </p>
                <div class="flex gap-0.5 mb-1">
                    <?php for ($s = 1; $s <= 5; $s++): ?>
                    <span class="<?= $s <= $existing_review['review_rating'] ? 'text-yellow-400' : 'text-gray-300' ?>">★</span>
                    <?php endfor; ?>
                </div>
                <p class="text-sm text-gray-600"><?= htmlspecialchars($existing_review['review_comment']) ?></p>
            </div>
            <?php endif; ?>
HTML;

if (
    str_contains(
        $content,
        $oldExistingReview
    )
) {
    $content = str_replace(
        $oldExistingReview,
        $newExistingReview,
        $content
    );
}

if (
    !str_contains(
        $content,
        'related-volume-format-badge'
    )
) {
    $relatedCardOpening = <<<'HTML'
                <a href="product_detail.php?id=<?= (int) $r['product_id'] ?>"
                   class="related-volume-card flex-shrink-0 w-28 transition-all duration-200 group">
HTML;

    $relatedCardReplacement = <<<'HTML'
                <a href="product_detail.php?id=<?= (int) $r['product_id'] ?>"
                   class="related-volume-card flex-shrink-0 w-28 transition-all duration-200 group">
                    <span
                        class="related-volume-format-badge <?= $r['product_type'] === 'ebook'
                            ? 'is-ebook'
                            : 'is-physical' ?>"
                    >
                        <?= $r['product_type'] === 'ebook'
                            ? 'E-Book'
                            : 'Physical' ?>
                    </span>
HTML;

    $content = replaceRequired(
        $content,
        $relatedCardOpening,
        $relatedCardReplacement,
        'related-volume card'
    );
}

if (
    !str_contains(
        $content,
        '<!-- Product Detail Footer -->'
    )
) {
    $footer = <<<'HTML'

    <!-- Product Detail Footer -->
    <footer class="bg-[#F5F0EB] text-gray-800 py-12 border-t border-gray-200">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-8 mb-10">
                <div class="col-span-2 md:col-span-1">
                    <h3 class="text-lg font-black mb-4">
                        MANGA<span class="text-red-600">VAULT</span>
                    </h3>
                    <p class="text-gray-600 text-sm leading-relaxed">
                        Malaysia's ultimate destination for manga and
                        comic book lovers.
                    </p>
                </div>

                <div>
                    <h4 class="font-bold mb-4 text-sm uppercase tracking-wide text-gray-800">
                        Shop
                    </h4>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li>
                            <a href="home.php" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">
                                All Manga
                            </a>
                        </li>
                        <li>
                            <a href="home.php?type=physical" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">
                                Physical Books
                            </a>
                        </li>
                        <li>
                            <a href="home.php?type=ebook" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">
                                E-Books
                            </a>
                        </li>
                    </ul>
                </div>

                <div>
                    <h4 class="font-bold mb-4 text-sm uppercase tracking-wide text-gray-800">
                        Help
                    </h4>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li>
                            <a href="orders.php" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">
                                My Orders
                            </a>
                        </li>
                        <li>
                            <a href="profile.php" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">
                                My Account
                            </a>
                        </li>
                        <li>
                            <a href="faq.php" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">
                                FAQ
                            </a>
                        </li>
                        <li>
                            <a href="about.php" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">
                                About Us
                            </a>
                        </li>
                    </ul>
                </div>

                <div>
                    <h4 class="font-bold mb-4 text-sm uppercase tracking-wide text-gray-800">
                        Follow Us
                    </h4>
                    <div class="flex gap-3">
                        <a href="#" class="w-9 h-9 bg-gray-200 hover:bg-red-600 hover:text-white rounded-full flex items-center justify-center transition-all text-sm font-bold text-gray-600" aria-label="Facebook">
                            f
                        </a>
                        <a href="#" class="w-9 h-9 bg-gray-200 hover:bg-red-600 hover:text-white rounded-full flex items-center justify-center transition-all text-sm font-bold text-gray-600" aria-label="Twitter">
                            t
                        </a>
                        <a href="#" class="w-9 h-9 bg-gray-200 hover:bg-red-600 hover:text-white rounded-full flex items-center justify-center transition-all text-sm font-bold text-gray-600" aria-label="LinkedIn">
                            in
                        </a>
                    </div>
                </div>
            </div>

            <div class="border-t border-gray-300 pt-6 text-center text-xs text-gray-500">
                © 2026 MangaVault. All rights reserved.
            </div>
        </div>
    </footer>
HTML;

    $lastScriptPosition = strrpos(
        $content,
        "\n    <script>"
    );

    if ($lastScriptPosition === false) {
        fwrite(
            STDERR,
            "Unable to locate the product-detail script block.\n"
        );
        exit(1);
    }

    $content =
        substr(
            $content,
            0,
            $lastScriptPosition
        ) .
        $footer .
        substr(
            $content,
            $lastScriptPosition
        );
}

if ($usesCrLf) {
    $content = str_replace(
        "\n",
        "\r\n",
        $content
    );
}

if (
    file_put_contents(
        $productPath,
        $content
    ) === false
) {
    fwrite(
        STDERR,
        "Unable to update customer/product_detail.php.\n"
    );
    exit(1);
}

echo "Final product-detail refinements applied successfully.\n";
echo "Backup: customer/product_detail.php.before-final-refinement\n";