<?php

date_default_timezone_set('Asia/Kuala_Lumpur');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/money_helper.php';

require_supplier();

$supplier_id = (int) $_SESSION['supplier_id'];
$error = '';
$success = '';

if (isset($_SESSION['flash_success'])) {
    $success = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

function requireSupplierInvoiceId(
    mixed $value,
    string $label
): int {
    $id = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($id === false || $id === null) {
        throw new RuntimeException(
            'Invalid ' . $label . '.'
        );
    }

    return (int) $id;
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['submit_invoice'])
) {
    csrf_verify();

    try {
        $po_id = requireSupplierInvoiceId(
            $_POST['po_id'] ?? null,
            'purchase order'
        );

        $suffix_raw =
            $_POST['invoice_number_suffix'] ?? null;

        if (
            !is_string($suffix_raw) ||
            !preg_match('/\A\d{4}\z/', $suffix_raw)
        ) {
            throw new RuntimeException(
                'Please enter a valid 4-digit invoice number.'
            );
        }

        $amount_raw = $_POST['invoice_amount'] ?? null;

        if (!is_string($amount_raw)) {
            throw new RuntimeException(
                'Please enter a valid invoice amount.'
            );
        }

        try {
            $amount = moneyNormalizeDecimal(
                trim($amount_raw)
            );
            $amount_sen = moneyDecimalToSen($amount);
        } catch (MoneyValueException $e) {
            throw new RuntimeException(
                'Please enter a valid invoice amount with up to two decimal places.',
                0,
                $e
            );
        }

        if ($amount_sen < 1) {
            throw new RuntimeException(
                'Invoice amount must be at least RM 0.01.'
            );
        }

        $po_check = $pdo->prepare("
            SELECT
                po_id,
                po_total_amount
            FROM purchase_orders
            WHERE po_id = ?
            AND po_supplier_id = ?
            AND po_status = 'completed'
            LIMIT 1
        ");
        $po_check->execute([
            $po_id,
            $supplier_id,
        ]);
        $purchase_order =
            $po_check->fetch(PDO::FETCH_ASSOC);

        if (!$purchase_order) {
            throw new RuntimeException(
                'Invalid order or order not yet completed.'
            );
        }

        $invoice_number =
            'INV-' . date('Y') . '-' . $suffix_raw;

        $duplicate = $pdo->prepare("
            SELECT invoice_id
            FROM supplier_invoices
            WHERE invoice_number = ?
            AND invoice_supplier_id = ?
            LIMIT 1
        ");
        $duplicate->execute([
            $invoice_number,
            $supplier_id,
        ]);

        if ($duplicate->fetchColumn()) {
            throw new RuntimeException(
                'Invoice number ' .
                $invoice_number .
                ' has already been used. ' .
                'Please use a different number.'
            );
        }

        $po_total_sen = moneyDecimalToSen(
            (string) $purchase_order['po_total_amount']
        );

        $is_mismatch =
            $po_total_sen !== $amount_sen;

        $insert = $pdo->prepare("
            INSERT INTO supplier_invoices (
                invoice_number,
                invoice_supplier_id,
                invoice_po_id,
                invoice_amount,
                invoice_due_date,
                invoice_is_mismatch
            )
            VALUES (
                ?,
                ?,
                ?,
                ?,
                DATE_ADD(NOW(), INTERVAL 30 DAY),
                ?
            )
        ");
        $insert->execute([
            $invoice_number,
            $supplier_id,
            $po_id,
            $amount,
            $is_mismatch ? 1 : 0,
        ]);

        $_SESSION['flash_success'] =
            'Invoice submitted successfully. ' .
            'MangaVault will review and process payment.';

        header('Location: invoices.php');
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['download_receipt'])) {
    try {
        $invoice_id = requireSupplierInvoiceId(
            $_GET['download_receipt'],
            'invoice'
        );
    } catch (RuntimeException $e) {
        header('Location: invoices.php');
        exit;
    }

    require_once __DIR__ . '/../vendor/autoload.php';

    $inv = $pdo->prepare("
        SELECT
            si.*,
            s.supplier_name,
            s.supplier_contact_person,
            s.supplier_address,
            s.supplier_email,
            po.po_number,
            sr.return_credit_note_number,
            sr.return_number AS credit_return_number,
            credit_source_po.po_number
                AS credit_source_po_number
        FROM supplier_invoices si
        JOIN suppliers s
            ON s.supplier_id =
                si.invoice_supplier_id
        LEFT JOIN purchase_orders po
            ON po.po_id = si.invoice_po_id
        LEFT JOIN supplier_returns sr
            ON sr.return_id =
                si.invoice_credit_note_id
        LEFT JOIN purchase_orders credit_source_po
            ON credit_source_po.po_id =
                sr.return_po_id
        WHERE si.invoice_id = ?
        AND si.invoice_supplier_id = ?
        AND si.invoice_status = 'paid'
    ");
    $inv->execute([
        $invoice_id,
        $supplier_id,
    ]);
    $inv = $inv->fetch(PDO::FETCH_ASSOC);

    if (!$inv) {
        header('Location: invoices.php');
        exit;
    }

    $receipt_number =
        'RCT-' .
        str_pad(
            (string) $invoice_id,
            5,
            '0',
            STR_PAD_LEFT
        );

    $credit_note_row = '';

    if (
        (float) $inv[
            'invoice_credit_applied_amount'
        ] > 0
    ) {
        $credit_note_row = "
            <tr style='border-bottom:1px solid #e5e7eb;'>
                <td style='padding:12px 14px; font-size:13px; color:#047857;'>
                    <strong>Less: Credit Note " .
                    htmlspecialchars(
                        (string) (
                            $inv[
                                'return_credit_note_number'
                            ] ?? '—'
                        )
                    ) .
                    "</strong><br>
                    <span style='font-size:11px;color:#6b7280;'>
                        Origin: " .
                        htmlspecialchars(
                            (string) (
                                $inv[
                                    'credit_source_po_number'
                                ] ?? '—'
                            )
                        ) .
                        " / " .
                        htmlspecialchars(
                            (string) (
                                $inv[
                                    'credit_return_number'
                                ] ?? '—'
                            )
                        ) .
                    "</span>
                </td>
                <td style='padding:12px 14px; font-size:13px; text-align:right; color:#047857;'>
                    - RM " .
                    number_format(
                        (float) $inv[
                            'invoice_credit_applied_amount'
                        ],
                        2
                    ) .
                "</td>
            </tr>
        ";
    }

    $html = "
    <!DOCTYPE html><html><head><meta charset='UTF-8'></head>
    <body style='font-family: Arial, sans-serif; margin:0; padding:30px; color:#111827;'>
        <div style='background:#1e2d4a; padding:24px; border-radius:8px; margin-bottom:30px;'>
            <h1 style='color:#ffffff; font-size:22px; margin:0; font-weight:900;'>MANGA<span style='color:#ef4444;'>VAULT</span></h1>
            <p style='color:rgba(255,255,255,0.7); font-size:12px; margin:4px 0 0;'>Official Payment Receipt</p>
        </div>

        <h2 style='font-size:18px; color:#111827; margin:0 0 4px;'>Payment Receipt</h2>

        <p style='font-size:12px; color:#6b7280; margin:0 0 24px;'>
            Receipt No:
            <strong>" .
                htmlspecialchars($receipt_number) .
            "</strong>
        </p>

        <table style='width:100%; margin-bottom:24px; font-size:13px;'>
            <tr>
                <td style='padding:4px 0; color:#6b7280; width:40%;'>Receipt Date</td>
                <td style='padding:4px 0; font-weight:600;'>" .
                    date(
                        'd F Y',
                        strtotime(
                            (string) $inv[
                                'invoice_paid_at'
                            ]
                        )
                    ) .
                "</td>
            </tr>
            <tr>
                <td style='padding:4px 0; color:#6b7280;'>Invoice Number</td>
                <td style='padding:4px 0; font-weight:600;'>" .
                    htmlspecialchars(
                        (string) $inv[
                            'invoice_number'
                        ]
                    ) .
                "</td>
            </tr>
            <tr>
                <td style='padding:4px 0; color:#6b7280;'>Purchase Order</td>
                <td style='padding:4px 0; font-weight:600;'>" .
                    htmlspecialchars(
                        (string) (
                            $inv[
                                'po_number'
                            ] ?? '—'
                        )
                    ) .
                "</td>
            </tr>
        </table>

        <div style='background:#f9fafb; border-radius:8px; padding:16px; margin-bottom:24px;'>
            <p style='font-size:11px; color:#9ca3af; margin:0 0 6px; text-transform:uppercase; font-weight:700;'>Paid To</p>
            <p style='font-size:14px; font-weight:700; margin:0 0 2px;'>" .
                htmlspecialchars(
                    (string) $inv[
                        'supplier_name'
                    ]
                ) .
            "</p>
            <p style='font-size:12px; color:#6b7280; margin:0;'>" .
                htmlspecialchars(
                    (string) (
                        $inv[
                            'supplier_contact_person'
                        ] ?? ''
                    )
                ) .
            "</p>
            <p style='font-size:12px; color:#6b7280; margin:0;'>" .
                htmlspecialchars(
                    (string) (
                        $inv[
                            'supplier_address'
                        ] ?? ''
                    )
                ) .
            "</p>
            <p style='font-size:12px; color:#6b7280; margin:0;'>" .
                htmlspecialchars(
                    (string) (
                        $inv[
                            'supplier_email'
                        ] ?? ''
                    )
                ) .
            "</p>
        </div>

        <table style='width:100%; border-collapse:collapse; margin-bottom:24px;'>
            <tr style='background:#1e2d4a; color:white;'>
                <td style='padding:10px 14px; font-size:12px; font-weight:700;'>Description</td>
                <td style='padding:10px 14px; font-size:12px; font-weight:700; text-align:right;'>Amount</td>
            </tr>

            <tr style='border-bottom:1px solid #e5e7eb;'>
                <td style='padding:12px 14px; font-size:13px;'>
                    Invoice " .
                    htmlspecialchars(
                        (string) $inv[
                            'invoice_number'
                        ]
                    ) .
                "</td>
                <td style='padding:12px 14px; font-size:13px; text-align:right;'>
                    RM " .
                    number_format(
                        (float) $inv[
                            'invoice_amount'
                        ],
                        2
                    ) .
                "</td>
            </tr>

            $credit_note_row

            <tr style='background:#f0fdf4;'>
                <td style='padding:12px 14px; font-size:14px; font-weight:900;'>Total Paid</td>
                <td style='padding:12px 14px; font-size:14px; font-weight:900; text-align:right; color:#047857;'>
                    RM " .
                    number_format(
                        (float) $inv[
                            'invoice_amount'
                        ] -
                        (float) $inv[
                            'invoice_credit_applied_amount'
                        ],
                        2
                    ) .
                "</td>
            </tr>
        </table>

        <div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 14px;margin-bottom:24px;'>
            <p style='font-size:11px;color:#047857;margin:0;line-height:1.6;'>
                Any credit note shown above is a carried-forward supplier credit
                from a previous resolved return. It does not reduce the original
                source invoice. It is applied separately to reduce the cash
                settlement of this invoice.
            </p>
        </div>

        <div style='border-top:2px solid #f3f4f6; padding-top:16px; margin-top:40px;'>
            <p style='font-size:11px; color:#9ca3af; margin:0;'>
                This is a computer-generated receipt and serves as official proof of payment from MangaVault to the above supplier.
            </p>
            <p style='font-size:11px; color:#9ca3af; margin:4px 0 0;'>
                MangaVault Sdn Bhd · Generated on " .
                date('d F Y, h:i A') .
            "</p>
        </div>
    </body></html>";

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream(
        "Receipt_{$receipt_number}.pdf",
        ['Attachment' => true]
    );
    exit;
}

$invoices = $pdo->prepare("
    SELECT
        si.*,
        po.po_number,
        sr.return_credit_note_number,
        sr.return_number AS credit_return_number,
        credit_source_po.po_number
            AS credit_source_po_number
    FROM supplier_invoices si
    LEFT JOIN purchase_orders po
        ON po.po_id = si.invoice_po_id
    LEFT JOIN supplier_returns sr
        ON sr.return_id =
            si.invoice_credit_note_id
    LEFT JOIN purchase_orders credit_source_po
        ON credit_source_po.po_id =
            sr.return_po_id
    WHERE si.invoice_supplier_id = ?
    ORDER BY si.invoice_created_at DESC
");
$invoices->execute([$supplier_id]);
$invoices = $invoices->fetchAll(PDO::FETCH_ASSOC);

$credit_notes = $pdo->prepare("
    SELECT
        sr.return_id,
        sr.return_number,
        sr.return_credit_note_number,
        sr.return_credit_note_amount,
        sr.return_credit_note_used_invoice_id,
        sr.return_resolved_at,
        source_po.po_number AS source_po_number,
        (
            SELECT source_invoice.invoice_number
            FROM supplier_invoices source_invoice
            WHERE source_invoice.invoice_po_id =
                sr.return_po_id
            AND source_invoice.invoice_status != 'rejected'
            ORDER BY source_invoice.invoice_id DESC
            LIMIT 1
        ) AS source_invoice_number,
        used_invoice.invoice_number
            AS used_invoice_number,
        used_invoice.invoice_status
            AS used_invoice_status
    FROM supplier_returns sr
    JOIN purchase_orders source_po
        ON source_po.po_id =
            sr.return_po_id
    LEFT JOIN supplier_invoices used_invoice
        ON used_invoice.invoice_id =
            sr.return_credit_note_used_invoice_id
    WHERE source_po.po_supplier_id = ?
    AND sr.return_status = 'resolved'
    AND sr.return_resolution_type IN (
        'credit_note',
        'dispute_upheld'
    )
    AND sr.return_credit_note_number IS NOT NULL
    AND sr.return_credit_note_amount > 0
    ORDER BY
        CASE
            WHEN sr.return_credit_note_used_invoice_id
                IS NULL
            THEN 0
            ELSE 1
        END,
        sr.return_resolved_at DESC,
        sr.return_id DESC
");
$credit_notes->execute([$supplier_id]);
$credit_notes =
    $credit_notes->fetchAll(PDO::FETCH_ASSOC);

$available_credit_total_sen = 0;
$available_credit_count = 0;

foreach ($credit_notes as $credit_note) {
    if (
        $credit_note[
            'return_credit_note_used_invoice_id'
        ] === null
    ) {
        $available_credit_total_sen +=
            moneyDecimalToSen(
                (string) $credit_note[
                    'return_credit_note_amount'
                ]
            );
        $available_credit_count++;
    }
}

$available_pos = $pdo->prepare("
    SELECT
        po_id,
        po_number,
        po_total_amount
    FROM purchase_orders
    WHERE po_supplier_id = ?
    AND po_status = 'completed'
    AND po_id NOT IN (
        SELECT invoice_po_id
        FROM supplier_invoices
        WHERE invoice_po_id IS NOT NULL
        AND invoice_status != 'rejected'
    )
");
$available_pos->execute([$supplier_id]);
$available_pos =
    $available_pos->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Invoices - Supplier Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">

    <?php include '../includes/supplier_navbar.php'; ?>

    <div class="max-w-4xl mx-auto px-6 py-8">

        <div
            class="flex items-center justify-between mb-6"
        >
            <div>
                <h1
                    class="text-2xl font-black text-gray-800"
                >
                    🧾 My Invoices
                </h1>

                <p
                    class="text-gray-500 text-sm mt-1"
                >
                    Submit invoices for completed orders and track payment status
                </p>
            </div>

            <?php if (
                count($available_pos) > 0
            ): ?>
            <button
                onclick="openModal()"
                class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-5 py-2.5 rounded-xl text-sm transition-colors"
            >
                + Submit Invoice
            </button>
            <?php endif; ?>
        </div>

        <?php if ($success): ?>
        <div
            class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-6"
        >
            ✅
            <?= htmlspecialchars(
                $success,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>
        <?php endif; ?>

        <?php if (
            count($available_pos) > 0
        ): ?>
        <div
            class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-6"
        >
            <p class="text-sm text-blue-700">
                📌 You have
                <?= count($available_pos) ?>
                completed order(s) awaiting invoice submission.
            </p>
        </div>
        <?php endif; ?>

        <?php if (
            $available_credit_count > 0
        ): ?>
        <div
            class="mb-6 overflow-hidden rounded-2xl border border-emerald-200 bg-white shadow-sm"
        >
            <div
                class="flex items-center justify-between gap-4 border-b border-emerald-100 bg-emerald-50 px-5 py-4"
            >
                <div>
                    <p
                        class="text-[11px] font-black uppercase tracking-wide text-emerald-600"
                    >
                        Available Supplier Credit
                    </p>

                    <p
                        class="mt-1 text-xl font-black text-emerald-800"
                    >
                        RM
                        <?= moneyFormatSen(
                            $available_credit_total_sen
                        ) ?>
                    </p>
                </div>

                <span
                    class="rounded-full border border-emerald-200 bg-white px-3 py-1 text-xs font-black text-emerald-700"
                >
                    <?= $available_credit_count ?>
                    unused
                </span>
            </div>

            <div class="px-5 py-4">
                <p
                    class="text-sm font-semibold text-gray-700"
                >
                    MangaVault has credit from previous resolved returns that may be applied to a future invoice.
                </p>

                <p
                    class="mt-1 text-xs leading-5 text-gray-500"
                >
                    Important: submit every new invoice at the full purchase order amount.
                    Do not manually subtract this credit. If MangaVault applies a credit note,
                    the invoice record and payment receipt will show the credit note number,
                    its source and the final net amount paid.
                </p>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($credit_notes): ?>
        <div
            class="mb-6 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm"
        >
            <div
                class="flex items-start justify-between gap-4"
            >
                <div>
                    <h2
                        class="text-sm font-black text-gray-800"
                    >
                        Credit Note Ledger
                    </h2>

                    <p
                        class="mt-1 text-xs leading-5 text-gray-500"
                    >
                        This ledger shows where each credit came from and whether MangaVault has already applied it to an invoice.
                    </p>
                </div>
            </div>

            <div class="mt-4 space-y-3">
                <?php foreach (
                    $credit_notes as $credit_index => $credit_note
                ):
                    $credit_amount_sen =
                        moneyDecimalToSen(
                            (string) $credit_note[
                                'return_credit_note_amount'
                            ]
                        );

                    $credit_is_used =
                        $credit_note[
                            'return_credit_note_used_invoice_id'
                        ] !== null;
                ?>
                <div
                    class="credit-ledger-item <?= $credit_index >= 3 ? 'hidden credit-ledger-extra' : '' ?> rounded-xl border <?= $credit_is_used ? 'border-gray-200 bg-gray-50' : 'border-emerald-200 bg-emerald-50/60' ?> p-4"
                >
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div>
                            <div
                                class="flex flex-wrap items-center gap-2"
                            >
                                <p
                                    class="font-mono text-sm font-black <?= $credit_is_used ? 'text-gray-700' : 'text-emerald-700' ?>"
                                >
                                    <?= htmlspecialchars(
                                        (string) $credit_note[
                                            'return_credit_note_number'
                                        ],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>

                                <span
                                    class="<?= $credit_is_used ? 'border-gray-200 bg-white text-gray-500' : 'border-emerald-200 bg-white text-emerald-700' ?> rounded-full border px-2 py-0.5 text-[10px] font-black"
                                >
                                    <?= $credit_is_used
                                        ? 'APPLIED'
                                        : 'AVAILABLE' ?>
                                </span>
                            </div>

                            <div
                                class="mt-2 space-y-1 text-xs text-gray-500"
                            >
                                <p>
                                    Source PO:
                                    <strong class="text-gray-700">
                                        <?= htmlspecialchars(
                                            (string) $credit_note[
                                                'source_po_number'
                                            ],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </strong>
                                </p>

                                <p>
                                    Return:
                                    <strong class="text-gray-700">
                                        <?= htmlspecialchars(
                                            (string) $credit_note[
                                                'return_number'
                                            ],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </strong>
                                </p>

                                <p>
                                    Source Invoice:
                                    <strong class="text-gray-700">
                                        <?= htmlspecialchars(
                                            (string) (
                                                $credit_note[
                                                    'source_invoice_number'
                                                ] ??
                                                'Not submitted yet'
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </strong>
                                </p>

                                <?php if (
                                    $credit_is_used
                                ): ?>
                                <p>
                                    Applied To:
                                    <strong class="text-emerald-700">
                                        <?= htmlspecialchars(
                                            (string) (
                                                $credit_note[
                                                    'used_invoice_number'
                                                ] ??
                                                'Invoice unavailable'
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </strong>
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div
                            class="sm:text-right"
                        >
                            <p
                                class="text-lg font-black <?= $credit_is_used ? 'text-gray-700' : 'text-emerald-700' ?>"
                            >
                                RM
                                <?= moneyFormatSen(
                                    $credit_amount_sen
                                ) ?>
                            </p>

                            <p
                                class="mt-1 text-[11px] leading-4 text-gray-400"
                            >
                                <?= $credit_is_used
                                    ? 'Already used as a payment deduction.'
                                    : 'May be applied to a future eligible invoice.' ?>
                            </p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (count($credit_notes) > 3): ?>
            <div
                class="mt-4 border-t border-gray-100 pt-4"
            >
                <button
                    type="button"
                    id="creditLedgerToggle"
                    onclick="toggleCreditLedger()"
                    class="flex w-full items-center justify-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm font-bold text-gray-600 transition hover:bg-gray-100 hover:text-gray-800"
                    aria-expanded="false"
                >
                    <span id="creditLedgerToggleText">
                        Show <?= count($credit_notes) - 3 ?> more credit note<?= count($credit_notes) - 3 === 1 ? '' : 's' ?>
                    </span>

                    <svg
                        id="creditLedgerToggleIcon"
                        class="h-4 w-4 transition-transform duration-200"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M19 9l-7 7-7-7"
                        ></path>
                    </svg>
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (
            count($invoices) === 0
        ): ?>
        <div
            class="bg-white rounded-2xl shadow-sm p-16 text-center"
        >
            <div class="text-5xl mb-4">
                🧾
            </div>

            <p class="text-gray-400">
                No invoices submitted yet.
            </p>
        </div>
        <?php else: ?>
        <div
            class="bg-white rounded-2xl shadow-sm overflow-hidden"
        >
            <table class="w-full">
                <thead>
                    <tr
                        class="bg-gray-50 border-b border-gray-100"
                    >
                        <th
                            class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase"
                        >
                            Invoice #
                        </th>

                        <th
                            class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase"
                        >
                            PO
                        </th>

                        <th
                            class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase"
                        >
                            Amount
                        </th>

                        <th
                            class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase"
                        >
                            Due Date
                        </th>

                        <th
                            class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase"
                        >
                            Status
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach (
                        $invoices as $inv
                    ):
                        $is_overdue =
                            $inv[
                                'invoice_status'
                            ] === 'unpaid' &&
                            $inv[
                                'invoice_due_date'
                            ] &&
                            strtotime(
                                $inv[
                                    'invoice_due_date'
                                ]
                            ) < time();
                    ?>
                    <tr
                        class="border-b border-gray-50 hover:bg-gray-50 transition-colors"
                    >
                        <td class="px-5 py-4">
                            <p
                                class="font-semibold text-sm text-gray-800"
                            >
                                <?= htmlspecialchars(
                                    (string) $inv[
                                        'invoice_number'
                                    ],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </p>

                            <?php if (
                                $inv[
                                    'invoice_status'
                                ] === 'rejected' &&
                                $inv[
                                    'invoice_reject_reason'
                                ]
                            ): ?>
                            <p
                                class="text-xs text-red-500 mt-0.5"
                            >
                                ⚠️
                                <?= htmlspecialchars(
                                    (string) $inv[
                                        'invoice_reject_reason'
                                    ],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </p>
                            <?php endif; ?>
                        </td>

                        <td
                            class="px-5 py-4 text-sm text-gray-600"
                        >
                            <?= htmlspecialchars(
                                (string) (
                                    $inv[
                                        'po_number'
                                    ] ?? '—'
                                ),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </td>

                        <td
                            class="px-5 py-4 text-right text-sm"
                        >
                            <p
                                class="font-bold text-gray-800"
                            >
                                RM
                                <?= number_format(
                                    (float) $inv[
                                        'invoice_amount'
                                    ],
                                    2
                                ) ?>
                            </p>

                            <?php if (
                                (float) $inv[
                                    'invoice_credit_applied_amount'
                                ] > 0
                            ): ?>
                            <div
                                class="mt-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-left"
                            >
                                <p
                                    class="text-xs font-black text-emerald-700"
                                >
                                    Credit Applied:
                                    <?= htmlspecialchars(
                                        (string) $inv[
                                            'return_credit_note_number'
                                        ],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>

                                <p
                                    class="mt-1 text-xs text-emerald-700"
                                >
                                    − RM
                                    <?= number_format(
                                        (float) $inv[
                                            'invoice_credit_applied_amount'
                                        ],
                                        2
                                    ) ?>
                                </p>

                                <p
                                    class="mt-1 text-[11px] leading-4 text-gray-500"
                                >
                                    Origin:
                                    <?= htmlspecialchars(
                                        (string) (
                                            $inv[
                                                'credit_source_po_number'
                                            ] ?? '—'
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                    /
                                    <?= htmlspecialchars(
                                        (string) (
                                            $inv[
                                                'credit_return_number'
                                            ] ?? '—'
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>

                                <p
                                    class="mt-1 text-xs font-black text-gray-700"
                                >
                                    Net Payable:
                                    RM
                                    <?= number_format(
                                        (float) $inv[
                                            'invoice_amount'
                                        ] -
                                        (float) $inv[
                                            'invoice_credit_applied_amount'
                                        ],
                                        2
                                    ) ?>
                                </p>
                            </div>
                            <?php endif; ?>
                        </td>

                        <td
                            class="px-5 py-4 text-sm <?= $is_overdue ? 'text-red-500 font-semibold' : 'text-gray-500' ?>"
                        >
                            <?= $inv[
                                'invoice_due_date'
                            ]
                                ? date(
                                    'd M Y',
                                    strtotime(
                                        $inv[
                                            'invoice_due_date'
                                        ]
                                    )
                                )
                                : '—' ?>

                            <?= $is_overdue
                                ? ' ⚠️'
                                : '' ?>
                        </td>

                        <td
                            class="px-5 py-4 text-center"
                        >
                            <?php if (
                                $inv[
                                    'invoice_status'
                                ] === 'paid'
                            ): ?>
                            <a
                                href="?download_receipt=<?= (int) $inv['invoice_id'] ?>"
                                class="text-xs text-green-600 hover:underline font-semibold"
                            >
                                ✅ Paid — Download Receipt
                            </a>
                            <?php elseif (
                                $inv[
                                    'invoice_status'
                                ] === 'rejected'
                            ): ?>
                            <span
                                class="bg-red-100 text-red-700 text-xs px-3 py-1 rounded-full font-semibold"
                            >
                                ❌ Rejected — Please Resubmit
                            </span>
                            <?php else: ?>
                            <span
                                class="bg-yellow-100 text-yellow-700 text-xs px-3 py-1 rounded-full font-semibold"
                            >
                                ⏳ Awaiting Payment
                            </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div>

    <!-- Submit Invoice Modal -->
    <div
        id="invoiceModal"
        class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center px-6"
    >
        <div
            class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6"
        >
            <div
                class="flex items-center justify-between mb-5"
            >
                <h3
                    class="font-black text-gray-800 text-lg"
                >
                    Submit Invoice
                </h3>

                <button
                    type="button"
                    onclick="closeModal()"
                    class="text-gray-400 hover:text-gray-600"
                >
                    <svg
                        class="w-5 h-5"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M6 18L18 6M6 6l12 12"
                        ></path>
                    </svg>
                </button>
            </div>

            <?php if ($error): ?>
            <div
                class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-4"
            >
                ❌
                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>
            <?php endif; ?>

            <?php if (
                $available_credit_count > 0
            ): ?>
            <div
                class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4"
            >
                <p
                    class="text-sm font-black text-emerald-800"
                >
                    RM
                    <?= moneyFormatSen(
                        $available_credit_total_sen
                    ) ?>
                    credit is currently available.
                </p>

                <p
                    class="mt-1 text-xs leading-5 text-emerald-700"
                >
                    Submit this invoice at the full PO amount.
                    MangaVault will decide whether to apply an eligible
                    credit note separately during payment.
                </p>
            </div>
            <?php endif; ?>

            <form method="POST">
                <?php csrf_field(); ?>

                <input
                    type="hidden"
                    name="submit_invoice"
                    value="1"
                >

                <div class="space-y-4">
                    <div>
                        <label
                            class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide"
                        >
                            Completed Order *
                        </label>

                        <select
                            name="po_id"
                            id="po_select"
                            required
                            onchange="autofillAmount(this)"
                            class="w-full px-4 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-blue-400 transition-colors"
                        >
                            <option value="">
                                Select order...
                            </option>

                            <?php foreach (
                                $available_pos as $po
                            ): ?>
                            <option
                                value="<?= (int) $po['po_id'] ?>"
                                data-amount="<?= htmlspecialchars(
                                    (string) $po[
                                        'po_total_amount'
                                    ],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                            >
                                <?= htmlspecialchars(
                                    (string) $po[
                                        'po_number'
                                    ],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                                (RM
                                <?= number_format(
                                    (float) $po[
                                        'po_total_amount'
                                    ],
                                    2
                                ) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label
                            class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide"
                        >
                            Your Invoice Number *
                        </label>

                        <div
                            class="flex items-center border-2 border-gray-100 rounded-xl overflow-hidden focus-within:border-blue-400 transition-colors"
                        >
                            <span
                                class="px-3 py-2.5 bg-gray-50 text-sm text-gray-500 font-mono border-r border-gray-100"
                            >
                                INV-<?= date('Y') ?>-
                            </span>

                            <input
                                type="text"
                                name="invoice_number_suffix"
                                required
                                maxlength="4"
                                pattern="[0-9]{4}"
                                placeholder="0001"
                                oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 4)"
                                class="flex-1 px-3 py-2.5 text-sm focus:outline-none"
                            >
                        </div>

                        <p
                            class="text-xs text-gray-400 mt-1"
                        >
                            4-digit number only, e.g. 0001
                        </p>
                    </div>

                    <div>
                        <label
                            class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide"
                        >
                            Amount (RM) *
                        </label>

                        <input
                            type="number"
                            step="0.01"
                            min="0.01"
                            max="99999999.99"
                            name="invoice_amount"
                            id="amount_input"
                            required
                            class="w-full px-4 py-2.5 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-blue-400 transition-colors"
                        >

                        <p
                            class="mt-1 text-xs text-gray-400"
                        >
                            Use the full purchase order amount. Do not subtract any credit note here.
                        </p>
                    </div>

                    <p class="text-xs text-gray-400">
                        Payment terms: 30 days from submission
                    </p>
                </div>

                <div class="flex gap-3 mt-6">
                    <button
                        type="button"
                        onclick="closeModal()"
                        class="flex-1 border-2 border-gray-100 hover:bg-gray-50 text-gray-600 font-semibold py-2.5 rounded-xl text-sm transition-colors"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl text-sm transition-colors"
                    >
                        Submit Invoice
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function toggleCreditLedger() {
        const extras =
            document.querySelectorAll(
                '.credit-ledger-extra'
            );

        const button =
            document.getElementById(
                'creditLedgerToggle'
            );

        const label =
            document.getElementById(
                'creditLedgerToggleText'
            );

        const icon =
            document.getElementById(
                'creditLedgerToggleIcon'
            );

        if (
            extras.length === 0 ||
            !button ||
            !label ||
            !icon
        ) {
            return;
        }

        const isExpanded =
            button.getAttribute(
                'aria-expanded'
            ) === 'true';

        extras.forEach(
            function (item) {
                item.classList.toggle(
                    'hidden',
                    isExpanded
                );
            }
        );

        button.setAttribute(
            'aria-expanded',
            isExpanded
                ? 'false'
                : 'true'
        );

        label.textContent =
            isExpanded
                ? 'Show ' +
                    extras.length +
                    ' more credit note' +
                    (
                        extras.length === 1
                            ? ''
                            : 's'
                    )
                : 'Show less';

        icon.classList.toggle(
            'rotate-180',
            !isExpanded
        );
    }

    function openModal() {
        document
            .getElementById(
                'invoiceModal'
            )
            .classList
            .remove(
                'hidden'
            );
    }

    function closeModal() {
        document
            .getElementById(
                'invoiceModal'
            )
            .classList
            .add(
                'hidden'
            );
    }

    function autofillAmount(select) {
        const amount =
            select.options[
                select.selectedIndex
            ].dataset.amount;

        if (amount) {
            document
                .getElementById(
                    'amount_input'
                )
                .value = amount;
        }
    }

    <?php if ($error): ?>
    openModal();
    <?php endif; ?>
    </script>

</body>
</html>