<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/ebook_helper.php';

$userId = current_user_id();

$itemId = filter_input(
    INPUT_GET,
    'item_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (
    $itemId === false ||
    $itemId === null
) {
    redirect_to(
        app_path('customer/collection.php')
    );
}

$statement = $pdo->prepare("
    SELECT
        oi.order_item_id,
        oi.order_item_product_id,
        oi.order_item_product_title AS product_title,
        p.product_author,
        pe.ebook_file_path,
        pe.ebook_file_format,
        erp.ebook_progress_page,
        erp.ebook_progress_locator,
        erp.ebook_progress_percent
    FROM order_items oi
    JOIN orders o
        ON o.order_id = oi.order_item_order_id
    JOIN products p
        ON p.product_id =
            oi.order_item_product_id
    JOIN product_ebook pe
        ON pe.ebook_product_id =
            oi.order_item_product_id
    LEFT JOIN ebook_reading_progress erp
        ON erp.ebook_progress_order_item_id =
            oi.order_item_id
        AND erp.ebook_progress_user_id =
            o.order_user_id
    WHERE oi.order_item_id = ?
    AND o.order_user_id = ?
    AND oi.order_item_type = 'ebook'
    AND o.order_payment_status = 'confirmed'
    LIMIT 1
");

$statement->execute([
    (int) $itemId,
    $userId,
]);

$item = $statement->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    http_response_code(404);
    exit('E-book entitlement was not found.');
}

try {
    $file = resolveStoredEbookFile(
        (string) $item['ebook_file_path'],
        (string) $item['ebook_file_format']
    );
} catch (Throwable $exception) {
    app_error_log(
        'E-book reader failed for customer ' .
        $userId .
        ', order item ' .
        (int) $itemId .
        ': ' .
        $exception->getMessage()
    );

    http_response_code(404);
    exit(
        'E-book content is unavailable. ' .
        'Please contact support.'
    );
}

$format = strtoupper(
    (string) $file['format']
);

if (
    !in_array(
        $format,
        ['PDF', 'EPUB'],
        true
    )
) {
    http_response_code(415);
    exit('Unsupported e-book format.');
}

if (
    !isset($_SESSION['ebook_reader_tokens']) ||
    !is_array($_SESSION['ebook_reader_tokens'])
) {
    $_SESSION['ebook_reader_tokens'] = [];
}

$now = time();

foreach (
    $_SESSION['ebook_reader_tokens']
    as $existingToken => $tokenData
) {
    if (
        !is_array($tokenData) ||
        (int) ($tokenData['expires_at'] ?? 0) <
            $now
    ) {
        unset(
            $_SESSION['ebook_reader_tokens'][
                $existingToken
            ]
        );
    }
}

$readerToken = bin2hex(
    random_bytes(32)
);

$_SESSION['ebook_reader_tokens'][$readerToken] = [
    'user_id' => $userId,
    'item_id' => (int) $itemId,
    'expires_at' => $now + 7200,
];

$initialPage = max(
    1,
    (int) (
        $item['ebook_progress_page'] ??
        1
    )
);

$initialLocator = trim(
    (string) (
        $item['ebook_progress_locator'] ??
        ''
    )
);

$initialPercent = max(
    0,
    min(
        100,
        (float) (
            $item['ebook_progress_percent'] ??
            0
        )
    )
);

$contentUrl =
    app_path(
        'customer/ebook_content.php'
    ) .
    '?' .
    http_build_query([
        'item_id' => (int) $itemId,
        'token' => $readerToken,
    ]);

$saveUrl = app_path(
    'customer/save_ebook_progress.php'
);

$collectionUrl = app_path(
    'customer/collection.php'
);

$csrfToken = csrf_token();

header(
    'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0'
);
header('Pragma: no-cache');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
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
        <?= htmlspecialchars(
            (string) $item['product_title'],
            ENT_QUOTES,
            'UTF-8'
        ) ?> - MangaVault Reader
    </title>

    <script src="https://cdn.tailwindcss.com"></script>

    <?php if ($format === 'PDF'): ?>
        <script
            src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"
        ></script>
    <?php else: ?>
        <script
            src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"
        ></script>
        <script
            src="https://cdn.jsdelivr.net/npm/epubjs@0.3.93/dist/epub.min.js"
        ></script>
    <?php endif; ?>

    <style>
        html,
        body {
            height: 100%;
            margin: 0;
            overflow: hidden;
        }

        #reader-area {
            height: calc(100vh - 76px);
        }

        #pdf-container {
            height: 100%;
            overflow: auto;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 20px;
        }

        #pdf-canvas {
            background: white;
            box-shadow:
                0 8px 30px rgba(0, 0, 0, 0.16);
        }

        #epub-viewer {
            width: 100%;
            height: 100%;
            background: white;
        }

        @media (max-width: 640px) {
            #reader-area {
                height: calc(100vh - 112px);
            }
        }
    </style>
</head>

<body class="bg-gray-900">

<header
    class="min-h-[76px] bg-white border-b border-gray-200 px-3 sm:px-5 py-3 flex flex-wrap items-center gap-3"
>
    <a
        href="<?= htmlspecialchars(
            $collectionUrl,
            ENT_QUOTES,
            'UTF-8'
        ) ?>"
        class="rounded-lg border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50"
    >
        ← My Collection
    </a>

    <div class="min-w-0 flex-1">
        <h1
            class="font-bold text-gray-800 truncate"
        >
            <?= htmlspecialchars(
                (string) $item['product_title'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </h1>

        <p class="text-xs text-gray-400 truncate">
            <?= htmlspecialchars(
                (string) (
                    $item['product_author'] ?? ''
                ),
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </p>
    </div>

    <div
        class="flex items-center gap-2"
    >
        <button
            type="button"
            id="previous-page"
            class="rounded-lg border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 disabled:opacity-40"
        >
            ← Previous
        </button>

        <div
            id="reading-position"
            class="min-w-[110px] text-center text-xs font-semibold text-gray-500"
        >
            Loading...
        </div>

        <button
            type="button"
            id="next-page"
            class="rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-40"
        >
            Next →
        </button>
    </div>

    <div class="w-full sm:w-auto sm:min-w-[150px]">
        <div
            class="flex justify-between text-[11px] text-gray-400 mb-1"
        >
            <span>Reading progress</span>
            <span id="progress-text">
                <?= number_format(
                    $initialPercent,
                    0
                ) ?>%
            </span>
        </div>

        <div
            class="h-1.5 rounded-full bg-gray-100 overflow-hidden"
        >
            <div
                id="progress-bar"
                class="h-full bg-red-500 transition-all"
                style="width: <?= number_format(
                    $initialPercent,
                    2,
                    '.',
                    ''
                ) ?>%"
            ></div>
        </div>
    </div>
</header>

<main id="reader-area">
    <?php if ($format === 'PDF'): ?>
        <div id="pdf-container">
            <canvas id="pdf-canvas"></canvas>
        </div>
    <?php else: ?>
        <div id="epub-viewer"></div>
    <?php endif; ?>
</main>

<script>
const itemId = <?= (int) $itemId ?>;
const contentUrl = <?= json_encode(
    $contentUrl,
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
) ?>;
const saveUrl = <?= json_encode(
    $saveUrl,
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
) ?>;
const csrfToken = <?= json_encode(
    $csrfToken,
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
) ?>;

const initialPage = <?= (int) $initialPage ?>;
const initialLocator = <?= json_encode(
    $initialLocator,
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
) ?>;

const positionElement =
    document.getElementById(
        'reading-position'
    );

const progressTextElement =
    document.getElementById(
        'progress-text'
    );

const progressBarElement =
    document.getElementById(
        'progress-bar'
    );

const previousButton =
    document.getElementById(
        'previous-page'
    );

const nextButton =
    document.getElementById(
        'next-page'
    );

let saveTimer = null;

function normalizePercent(percent) {
    const numeric = Number(percent);

    if (!Number.isFinite(numeric)) {
        return 0;
    }

    return Math.max(
        0,
        Math.min(
            100,
            numeric
        )
    );
}

function showProgress(percent) {
    const normalized =
        normalizePercent(percent);

    progressTextElement.textContent =
        `${Math.round(normalized)}%`;

    progressBarElement.style.width =
        `${normalized}%`;
}

function saveProgress(payload) {
    const parameters =
        new URLSearchParams();

    parameters.set(
        'csrf_token',
        csrfToken
    );

    parameters.set(
        'item_id',
        String(itemId)
    );

    parameters.set(
        'percent',
        String(
            normalizePercent(
                payload.percent
            )
        )
    );

    if (
        Number.isInteger(
            payload.page
        ) &&
        payload.page > 0
    ) {
        parameters.set(
            'page',
            String(payload.page)
        );
    }

    if (
        typeof payload.locator ===
            'string' &&
        payload.locator !== ''
    ) {
        parameters.set(
            'locator',
            payload.locator
        );
    }

    fetch(
        saveUrl,
        {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type':
                    'application/x-www-form-urlencoded;charset=UTF-8',
            },
            body: parameters.toString(),
            keepalive: true,
        }
    ).catch(() => {
        // Reading must continue even if one
        // background progress save fails.
    });
}

function scheduleProgressSave(payload) {
    clearTimeout(saveTimer);

    saveTimer = setTimeout(
        () => {
            saveProgress(payload);
        },
        300
    );
}

<?php if ($format === 'PDF'): ?>

pdfjsLib.GlobalWorkerOptions.workerSrc =
    'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

let pdfDocument = null;
let currentPage = initialPage;
let rendering = false;
let pendingRender = null;

async function renderPdfPage(
    pageNumber,
    saveAfterRender = true
) {
    if (!pdfDocument) {
        return;
    }

    pageNumber = Math.max(
        1,
        Math.min(
            pdfDocument.numPages,
            pageNumber
        )
    );

    if (rendering) {
        pendingRender = {
            pageNumber,
            saveAfterRender,
        };

        return;
    }

    rendering = true;

    try {
        const page =
            await pdfDocument.getPage(
                pageNumber
            );

        const container =
            document.getElementById(
                'pdf-container'
            );

        const canvas =
            document.getElementById(
                'pdf-canvas'
            );

        const context =
            canvas.getContext(
                '2d'
            );

        const baseViewport =
            page.getViewport({
                scale: 1,
            });

        const availableWidth =
            Math.max(
                280,
                container.clientWidth -
                    40
            );

        const scale =
            Math.min(
                2.2,
                availableWidth /
                    baseViewport.width
            );

        const viewport =
            page.getViewport({
                scale,
            });

        const pixelRatio =
            window.devicePixelRatio || 1;

        canvas.width = Math.floor(
            viewport.width *
            pixelRatio
        );

        canvas.height = Math.floor(
            viewport.height *
            pixelRatio
        );

        canvas.style.width =
            `${Math.floor(
                viewport.width
            )}px`;

        canvas.style.height =
            `${Math.floor(
                viewport.height
            )}px`;

        const transform =
            pixelRatio === 1
                ? null
                : [
                    pixelRatio,
                    0,
                    0,
                    pixelRatio,
                    0,
                    0,
                ];

        await page.render({
            canvasContext: context,
            viewport,
            transform,
        }).promise;

        currentPage = pageNumber;

        const percent =
            (
                currentPage /
                pdfDocument.numPages
            ) * 100;

        positionElement.textContent =
            `Page ${currentPage} of ${pdfDocument.numPages}`;

        showProgress(percent);

        previousButton.disabled =
            currentPage <= 1;

        nextButton.disabled =
            currentPage >=
            pdfDocument.numPages;

        if (saveAfterRender) {
            scheduleProgressSave({
                page: currentPage,
                percent,
            });
        }
    } finally {
        rendering = false;

        if (pendingRender) {
            const pending =
                pendingRender;

            pendingRender = null;

            renderPdfPage(
                pending.pageNumber,
                pending.saveAfterRender
            );
        }
    }
}

previousButton.addEventListener(
    'click',
    () => {
        if (currentPage > 1) {
            renderPdfPage(
                currentPage - 1
            );
        }
    }
);

nextButton.addEventListener(
    'click',
    () => {
        if (
            pdfDocument &&
            currentPage <
                pdfDocument.numPages
        ) {
            renderPdfPage(
                currentPage + 1
            );
        }
    }
);

let resizeTimer = null;

window.addEventListener(
    'resize',
    () => {
        clearTimeout(resizeTimer);

        resizeTimer = setTimeout(
            () => {
                renderPdfPage(
                    currentPage,
                    false
                );
            },
            200
        );
    }
);

document.addEventListener(
    'keydown',
    (event) => {
        if (event.key === 'ArrowLeft') {
            previousButton.click();
        }

        if (event.key === 'ArrowRight') {
            nextButton.click();
        }
    }
);

(async () => {
    try {
        pdfDocument =
            await pdfjsLib
                .getDocument({
                    url: contentUrl,
                    withCredentials: true,
                })
                .promise;

        currentPage =
            Math.max(
                1,
                Math.min(
                    pdfDocument.numPages,
                    initialPage
                )
            );

        await renderPdfPage(
            currentPage,
            false
        );
    } catch (error) {
        positionElement.textContent =
            'Unable to load e-book';

        previousButton.disabled = true;
        nextButton.disabled = true;

        console.error(error);
    }
})();

<?php else: ?>

let epubBook = null;
let epubRendition = null;

previousButton.addEventListener(
    'click',
    () => {
        if (epubRendition) {
            epubRendition.prev();
        }
    }
);

nextButton.addEventListener(
    'click',
    () => {
        if (epubRendition) {
            epubRendition.next();
        }
    }
);

document.addEventListener(
    'keydown',
    (event) => {
        if (event.key === 'ArrowLeft') {
            previousButton.click();
        }

        if (event.key === 'ArrowRight') {
            nextButton.click();
        }
    }
);

(async () => {
    try {
        epubBook = ePub(
            contentUrl
        );

        epubRendition =
            epubBook.renderTo(
                'epub-viewer',
                {
                    width: '100%',
                    height: '100%',
                    spread: 'none',
                    flow: 'paginated',
                    allowScriptedContent:
                        false,
                }
            );

        await epubBook.ready;

        try {
            await epubBook.locations.generate(
                1000
            );
        } catch (error) {
            console.warn(
                'Unable to generate EPUB location map.',
                error
            );
        }

        epubRendition.on(
            'relocated',
            (location) => {
                const locator =
                    location?.start?.cfi ??
                    '';

                if (
                    typeof locator !==
                        'string' ||
                    !locator.startsWith(
                        'epubcfi('
                    )
                ) {
                    return;
                }

                let fraction =
                    Number(
                        location?.start
                            ?.percentage
                    );

                if (
                    !Number.isFinite(
                        fraction
                    ) &&
                    epubBook.locations
                ) {
                    fraction =
                        Number(
                            epubBook.locations
                                .percentageFromCfi(
                                    locator
                                )
                        );
                }

                if (
                    !Number.isFinite(
                        fraction
                    )
                ) {
                    fraction = 0;
                }

                let percent =
                    normalizePercent(
                        fraction * 100
                    );

                if (location?.atEnd) {
                    percent = 100;
                }

                positionElement.textContent =
                    `${Math.round(
                        percent
                    )}% complete`;

                showProgress(percent);

                scheduleProgressSave({
                    locator,
                    percent,
                });
            }
        );

        if (
            initialLocator.startsWith(
                'epubcfi('
            )
        ) {
            try {
                await epubRendition.display(
                    initialLocator
                );
            } catch (error) {
                await epubRendition.display();
            }
        } else {
            await epubRendition.display();
        }
    } catch (error) {
        positionElement.textContent =
            'Unable to load e-book';

        previousButton.disabled = true;
        nextButton.disabled = true;

        console.error(error);
    }
})();

<?php endif; ?>
</script>

</body>
</html>