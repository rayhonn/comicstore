<?php
date_default_timezone_set('Asia/Kuala_Lumpur');

require_once __DIR__ . '/../includes/auth.php';
require_admin();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/money_helper.php';

$success = '';
$error = '';

if (isset($_SESSION['flash_success'])) {
    $success = (string) $_SESSION[
        'flash_success'
    ];

    unset($_SESSION['flash_success']);
}

if (isset($_SESSION['flash_error'])) {
    $error = (string) $_SESSION[
        'flash_error'
    ];

    unset($_SESSION['flash_error']);
}

function redirectInvoicePage(
    string $message,
    bool $isError = false
): void {
    $_SESSION[
        $isError
            ? 'flash_error'
            : 'flash_success'
    ] = $message;

    header('Location: supplier_invoices.php');
    exit;
}

function requirePositiveRequestId(
    int $inputType,
    string $name,
    string $errorMessage
): int {
    $value = filter_input(
        $inputType,
        $name,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if (
        $value === false ||
        $value === null
    ) {
        redirectInvoicePage(
            $errorMessage,
            true
        );
    }

    return (int) $value;
}

function requireInvoiceText(
    mixed $value,
    string $label,
    int $maxLength
): string {
    if (!is_string($value)) {
        redirectInvoicePage(
            "$label is invalid.",
            true
        );
    }

    $normalized = trim($value);

    if ($normalized === '') {
        redirectInvoicePage(
            "$label is required.",
            true
        );
    }

    $length = function_exists('mb_strlen')
        ? mb_strlen(
            $normalized,
            'UTF-8'
        )
        : strlen($normalized);

    if ($length > $maxLength) {
        redirectInvoicePage(
            "$label cannot exceed $maxLength characters.",
            true
        );
    }

    return $normalized;
}

// ------------------------------------------------------------
// Mark a matched invoice as paid.
// Credit note, when applied, reduces only the net cash payable.
// It does NOT alter whether the supplier invoice matches its PO.
// ------------------------------------------------------------
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['mark_paid'])
) {
    csrf_verify();

    $invoiceId = requirePositiveRequestId(
        INPUT_POST,
        'invoice_id',
        'Invalid invoice.'
    );

    $statement = $pdo->prepare("
        UPDATE supplier_invoices
        SET
            invoice_status = 'paid',
            invoice_paid_at = NOW()
        WHERE invoice_id = ?
        AND invoice_status = 'unpaid'
        AND invoice_is_mismatch = 0
    ");

    $statement->execute([
        $invoiceId,
    ]);

    $wasUpdated =
        $statement->rowCount() === 1;

    redirectInvoicePage(
        $wasUpdated
            ? 'Invoice marked as paid.'
            : 'Invoice could not be processed.',
        !$wasUpdated
    );
}

// ------------------------------------------------------------
// Senior-admin override for a true invoice/PO mismatch.
// A credit note must never be used to turn a mismatch into a match.
// ------------------------------------------------------------
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['mark_paid_confirm'])
) {
    csrf_verify();

    if (
        ($_SESSION['admin_level'] ?? '') !==
        'senior_admin'
    ) {
        redirectInvoicePage(
            'Only senior admin can approve mismatched payments.',
            true
        );
    }

    $invoiceId = requirePositiveRequestId(
        INPUT_POST,
        'invoice_id',
        'Invalid invoice.'
    );

    $overrideReason = requireInvoiceText(
        $_POST['override_reason'] ?? null,
        'Override reason',
        2000
    );

    try {
        $pdo->beginTransaction();

        $invoiceStatement = $pdo->prepare("
            SELECT
                invoice_credit_note_id
            FROM supplier_invoices
            WHERE invoice_id = ?
            AND invoice_status = 'unpaid'
            AND invoice_is_mismatch = 1
            FOR UPDATE
        ");

        $invoiceStatement->execute([
            $invoiceId,
        ]);

        $invoice =
            $invoiceStatement->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$invoice) {
            throw new RuntimeException(
                'Invoice not found, no longer unpaid, or no longer mismatched.'
            );
        }

        if (
            !empty(
                $invoice[
                    'invoice_credit_note_id'
                ]
            )
        ) {
            throw new RuntimeException(
                'Remove the applied credit note before overriding a mismatched invoice.'
            );
        }

        $statement = $pdo->prepare("
            UPDATE supplier_invoices
            SET
                invoice_status = 'paid',
                invoice_paid_at = NOW(),
                invoice_override_reason = ?,
                invoice_override_by = ?
            WHERE invoice_id = ?
            AND invoice_status = 'unpaid'
            AND invoice_is_mismatch = 1
        ");

        $statement->execute([
            $overrideReason,
            $_SESSION['user_id'],
            $invoiceId,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException(
                'Invoice could not be processed.'
            );
        }

        $pdo->commit();

        redirectInvoicePage(
            'Invoice marked as paid with senior-admin override.'
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        redirectInvoicePage(
            $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to process the invoice override.',
            true
        );
    }
}

// ------------------------------------------------------------
// Reject invoice.
// If a later-period credit note had been applied, release it again.
// ------------------------------------------------------------
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['reject_invoice'])
) {
    csrf_verify();

    $invoiceId = requirePositiveRequestId(
        INPUT_POST,
        'invoice_id',
        'Invalid invoice.'
    );

    $reason = requireInvoiceText(
        $_POST['reject_reason'] ?? null,
        'Rejection reason',
        2000
    );

    try {
        $pdo->beginTransaction();

        $invoiceStatement = $pdo->prepare("
            SELECT
                invoice_credit_note_id
            FROM supplier_invoices
            WHERE invoice_id = ?
            AND invoice_status = 'unpaid'
            FOR UPDATE
        ");

        $invoiceStatement->execute([
            $invoiceId,
        ]);

        $invoice =
            $invoiceStatement->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$invoice) {
            throw new RuntimeException(
                'Invoice not found or no longer unpaid.'
            );
        }

        $returnId =
            $invoice[
                'invoice_credit_note_id'
            ] !== null
                ? (int) $invoice[
                    'invoice_credit_note_id'
                ]
                : null;

        $invoiceUpdate = $pdo->prepare("
            UPDATE supplier_invoices
            SET
                invoice_status = 'rejected',
                invoice_reject_reason = ?,
                invoice_credit_note_id = NULL,
                invoice_credit_applied_amount = 0
            WHERE invoice_id = ?
            AND invoice_status = 'unpaid'
        ");

        $invoiceUpdate->execute([
            $reason,
            $invoiceId,
        ]);

        if (
            $invoiceUpdate->rowCount() !== 1
        ) {
            throw new RuntimeException(
                'Invoice could not be rejected.'
            );
        }

        if ($returnId !== null) {
            $creditUpdate = $pdo->prepare("
                UPDATE supplier_returns
                SET
                    return_credit_note_used_invoice_id = NULL
                WHERE return_id = ?
                AND return_credit_note_used_invoice_id = ?
            ");

            $creditUpdate->execute([
                $returnId,
                $invoiceId,
            ]);

            if (
                $creditUpdate->rowCount() !== 1
            ) {
                throw new RuntimeException(
                    'Unable to release the applied credit note.'
                );
            }
        }

        $pdo->commit();

        redirectInvoicePage(
            $returnId !== null
                ? 'Invoice rejected. The applied credit note was released and is available again.'
                : 'Invoice rejected. The supplier can resubmit a corrected invoice.'
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        redirectInvoicePage(
            $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to reject the invoice.',
            true
        );
    }
}

// ------------------------------------------------------------
// Apply a prior credit note to a later matched invoice.
//
// Correct business rule:
//   Invoice amount = PO total
//   Invoice amount - credit note = Net payable
//
// A credit note cannot:
//   1) fix an invoice/PO mismatch;
//   2) be applied back to the same PO that generated it.
// ------------------------------------------------------------
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['apply_credit_note'])
) {
    csrf_verify();

    $invoiceId = requirePositiveRequestId(
        INPUT_POST,
        'invoice_id',
        'Invalid invoice or credit note.'
    );

    $returnId = requirePositiveRequestId(
        INPUT_POST,
        'return_id',
        'Invalid invoice or credit note.'
    );

    try {
        $pdo->beginTransaction();

        $invoiceStatement = $pdo->prepare("
            SELECT
                si.invoice_amount,
                si.invoice_supplier_id,
                si.invoice_po_id,
                si.invoice_credit_note_id,
                si.invoice_is_mismatch,
                po.po_total_amount
            FROM supplier_invoices si
            JOIN purchase_orders po
                ON po.po_id =
                    si.invoice_po_id
            WHERE si.invoice_id = ?
            AND si.invoice_status = 'unpaid'
            FOR UPDATE
        ");

        $invoiceStatement->execute([
            $invoiceId,
        ]);

        $invoice =
            $invoiceStatement->fetch(
                PDO::FETCH_ASSOC
            );

        if (
            !$invoice ||
            !empty(
                $invoice[
                    'invoice_credit_note_id'
                ]
            )
        ) {
            throw new RuntimeException(
                'Invoice not found, already processed, or already has a credit note.'
            );
        }

        if (
            (int) $invoice[
                'invoice_is_mismatch'
            ] === 1
        ) {
            throw new RuntimeException(
                'Credit notes cannot be used to correct an invoice amount mismatch. ' .
                'The supplier invoice must first match the purchase order total.'
            );
        }

        $invoiceAmountSen =
            moneyDecimalToSen(
                (string) $invoice[
                    'invoice_amount'
                ]
            );

        $poTotalSen =
            moneyDecimalToSen(
                (string) $invoice[
                    'po_total_amount'
                ]
            );

        if (
            $invoiceAmountSen !==
            $poTotalSen
        ) {
            throw new RuntimeException(
                'This invoice no longer matches its purchase order and cannot use a credit note.'
            );
        }

        $creditStatement = $pdo->prepare("
            SELECT
                sr.return_po_id,
                sr.return_credit_note_number,
                sr.return_credit_note_amount
            FROM supplier_returns sr
            JOIN purchase_orders source_po
                ON source_po.po_id =
                    sr.return_po_id
            WHERE sr.return_id = ?
            AND sr.return_status = 'resolved'
            AND sr.return_resolution_type IN (
                'credit_note',
                'dispute_upheld'
            )
            AND sr.return_credit_note_number
                IS NOT NULL
            AND sr.return_credit_note_amount > 0
            AND sr.return_credit_note_used_invoice_id
                IS NULL
            AND source_po.po_supplier_id = ?
            FOR UPDATE
        ");

        $creditStatement->execute([
            $returnId,
            $invoice[
                'invoice_supplier_id'
            ],
        ]);

        $creditNote =
            $creditStatement->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$creditNote) {
            throw new RuntimeException(
                'This credit note is not available for this supplier.'
            );
        }

        if (
            (int) $creditNote[
                'return_po_id'
            ] ===
            (int) $invoice[
                'invoice_po_id'
            ]
        ) {
            throw new RuntimeException(
                'A credit note cannot be applied to the same purchase order that generated it. ' .
                'It must be carried forward to another invoice.'
            );
        }

        $creditAmountSen =
            moneyDecimalToSen(
                (string) $creditNote[
                    'return_credit_note_amount'
                ]
            );

        if (
            $creditAmountSen < 1 ||
            $creditAmountSen >
                $invoiceAmountSen
        ) {
            throw new RuntimeException(
                'Credit Note ' .
                $creditNote[
                    'return_credit_note_number'
                ] .
                ' cannot be applied because its amount exceeds this invoice.'
            );
        }

        $netPayableSen =
            $invoiceAmountSen -
            $creditAmountSen;

        $applied =
            moneySenToDecimal(
                $creditAmountSen
            );

        $invoiceUpdate = $pdo->prepare("
            UPDATE supplier_invoices
            SET
                invoice_credit_note_id = ?,
                invoice_credit_applied_amount = ?
            WHERE invoice_id = ?
            AND invoice_status = 'unpaid'
            AND invoice_is_mismatch = 0
            AND invoice_credit_note_id IS NULL
        ");

        $invoiceUpdate->execute([
            $returnId,
            $applied,
            $invoiceId,
        ]);

        if (
            $invoiceUpdate->rowCount() !== 1
        ) {
            throw new RuntimeException(
                'Unable to apply the credit note.'
            );
        }

        $creditUpdate = $pdo->prepare("
            UPDATE supplier_returns
            SET
                return_credit_note_used_invoice_id = ?
            WHERE return_id = ?
            AND return_credit_note_used_invoice_id
                IS NULL
        ");

        $creditUpdate->execute([
            $invoiceId,
            $returnId,
        ]);

        if (
            $creditUpdate->rowCount() !== 1
        ) {
            throw new RuntimeException(
                'The credit note has already been used.'
            );
        }

        $pdo->commit();

        redirectInvoicePage(
            'Credit Note ' .
                $creditNote[
                    'return_credit_note_number'
                ] .
                ' applied. Invoice total remains RM ' .
                moneyFormatSen(
                    $invoiceAmountSen
                ) .
                '; net payable is RM ' .
                moneyFormatSen(
                    $netPayableSen
                ) .
                '.'
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        redirectInvoicePage(
            $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to apply the credit note.',
            true
        );
    }
}

// ------------------------------------------------------------
// Remove applied credit note.
// Match/mismatch is intentionally untouched because applying credit
// never changes the supplier invoice amount.
// ------------------------------------------------------------
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['remove_credit_note'])
) {
    csrf_verify();

    $invoiceId = requirePositiveRequestId(
        INPUT_POST,
        'invoice_id',
        'Invalid invoice.'
    );

    try {
        $pdo->beginTransaction();

        $invoiceStatement = $pdo->prepare("
            SELECT
                invoice_credit_note_id
            FROM supplier_invoices
            WHERE invoice_id = ?
            AND invoice_status = 'unpaid'
            FOR UPDATE
        ");

        $invoiceStatement->execute([
            $invoiceId,
        ]);

        $invoice =
            $invoiceStatement->fetch(
                PDO::FETCH_ASSOC
            );

        if (
            !$invoice ||
            empty(
                $invoice[
                    'invoice_credit_note_id'
                ]
            )
        ) {
            throw new RuntimeException(
                'No credit note to remove, or invoice already paid.'
            );
        }

        $returnId = (int) $invoice[
            'invoice_credit_note_id'
        ];

        $invoiceUpdate = $pdo->prepare("
            UPDATE supplier_invoices
            SET
                invoice_credit_note_id = NULL,
                invoice_credit_applied_amount = 0
            WHERE invoice_id = ?
            AND invoice_status = 'unpaid'
            AND invoice_credit_note_id = ?
        ");

        $invoiceUpdate->execute([
            $invoiceId,
            $returnId,
        ]);

        if (
            $invoiceUpdate->rowCount() !== 1
        ) {
            throw new RuntimeException(
                'Unable to remove the credit note.'
            );
        }

        $creditUpdate = $pdo->prepare("
            UPDATE supplier_returns
            SET
                return_credit_note_used_invoice_id = NULL
            WHERE return_id = ?
            AND return_credit_note_used_invoice_id = ?
        ");

        $creditUpdate->execute([
            $returnId,
            $invoiceId,
        ]);

        if (
            $creditUpdate->rowCount() !== 1
        ) {
            throw new RuntimeException(
                'Unable to release the credit note.'
            );
        }

        $pdo->commit();

        redirectInvoicePage(
            'Credit note removed. The original invoice total is payable again.'
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        redirectInvoicePage(
            $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to remove the credit note.',
            true
        );
    }
}

// ------------------------------------------------------------
// Download payment receipt.
// ------------------------------------------------------------
if (
    isset(
        $_GET['download_receipt']
    )
) {
    require_once __DIR__ .
        '/../vendor/autoload.php';

    $invoiceId = filter_input(
        INPUT_GET,
        'download_receipt',
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if (
        $invoiceId === false ||
        $invoiceId === null
    ) {
        header(
            'Location: supplier_invoices.php'
        );
        exit;
    }

    $invoiceStatement = $pdo->prepare("
        SELECT
            si.*,
            s.supplier_name,
            s.supplier_contact_person,
            s.supplier_address,
            s.supplier_email,
            po.po_number,
            sr.return_credit_note_number,
            sr.return_number AS credit_return_number,
            credit_source_po.po_number AS credit_source_po_number
        FROM supplier_invoices si
        JOIN suppliers s
            ON s.supplier_id =
                si.invoice_supplier_id
        LEFT JOIN purchase_orders po
            ON po.po_id =
                si.invoice_po_id
        LEFT JOIN supplier_returns sr
            ON sr.return_id =
                si.invoice_credit_note_id
        LEFT JOIN purchase_orders credit_source_po
            ON credit_source_po.po_id =
                sr.return_po_id
        WHERE si.invoice_id = ?
        AND si.invoice_status = 'paid'
    ");

    $invoiceStatement->execute([
        $invoiceId,
    ]);

    $invoice =
        $invoiceStatement->fetch(
            PDO::FETCH_ASSOC
        );

    if (!$invoice) {
        header(
            'Location: supplier_invoices.php'
        );
        exit;
    }

    $invoiceAmountSen =
        moneyDecimalToSen(
            (string) $invoice[
                'invoice_amount'
            ]
        );

    $creditAmountSen =
        moneyDecimalToSen(
            (string) (
                $invoice[
                    'invoice_credit_applied_amount'
                ] ?? '0.00'
            )
        );

    $totalPaidSen = max(
        0,
        $invoiceAmountSen -
            $creditAmountSen
    );

    $receiptNumber =
        'RCT-' .
        str_pad(
            (string) $invoiceId,
            5,
            '0',
            STR_PAD_LEFT
        );

    $creditRow = '';

    if ($creditAmountSen > 0) {
        $creditRow = "
            <tr style='border-bottom:1px solid #e5e7eb;'>
                <td style='padding:12px 14px; font-size:13px; color:#047857;'>
                    <strong>Less: Credit Note " .
                    htmlspecialchars(
                        (string) (
                            $invoice[
                                'return_credit_note_number'
                            ] ?? '—'
                        ),
                        ENT_QUOTES,
                        'UTF-8'
                    ) .
                    "</strong><br>
                    <span style='font-size:11px;color:#6b7280;'>
                        Origin: " .
                        htmlspecialchars(
                            (string) (
                                $invoice[
                                    'credit_source_po_number'
                                ] ?? '—'
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        ) .
                        " / " .
                        htmlspecialchars(
                            (string) (
                                $invoice[
                                    'credit_return_number'
                                ] ?? '—'
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        ) .
                    "</span>
                </td>
                <td style='padding:12px 14px; font-size:13px; text-align:right; color:#047857;'>
                    - RM " .
                    moneyFormatSen(
                        $creditAmountSen
                    ) .
                "</td>
            </tr>
        ";
    }

    $html = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
    </head>
    <body style='font-family:Arial,sans-serif;margin:0;padding:30px;color:#111827;'>
        <div style='background:#1e2d4a;padding:24px;border-radius:8px;margin-bottom:30px;'>
            <h1 style='color:#ffffff;font-size:22px;margin:0;font-weight:900;'>
                MANGA<span style='color:#ef4444;'>VAULT</span>
            </h1>
            <p style='color:rgba(255,255,255,0.7);font-size:12px;margin:4px 0 0;'>
                Official Payment Receipt
            </p>
        </div>

        <h2 style='font-size:18px;color:#111827;margin:0 0 4px;'>
            Payment Receipt
        </h2>

        <p style='font-size:12px;color:#6b7280;margin:0 0 24px;'>
            Receipt No:
            <strong>" .
                htmlspecialchars(
                    $receiptNumber,
                    ENT_QUOTES,
                    'UTF-8'
                ) .
            "</strong>
        </p>

        <table style='width:100%;margin-bottom:24px;font-size:13px;'>
            <tr>
                <td style='padding:4px 0;color:#6b7280;width:40%;'>
                    Receipt Date
                </td>
                <td style='padding:4px 0;font-weight:600;'>" .
                    date(
                        'd F Y',
                        strtotime(
                            (string) $invoice[
                                'invoice_paid_at'
                            ]
                        )
                    ) .
                "</td>
            </tr>
            <tr>
                <td style='padding:4px 0;color:#6b7280;'>
                    Invoice Number
                </td>
                <td style='padding:4px 0;font-weight:600;'>" .
                    htmlspecialchars(
                        (string) $invoice[
                            'invoice_number'
                        ],
                        ENT_QUOTES,
                        'UTF-8'
                    ) .
                "</td>
            </tr>
            <tr>
                <td style='padding:4px 0;color:#6b7280;'>
                    Purchase Order
                </td>
                <td style='padding:4px 0;font-weight:600;'>" .
                    htmlspecialchars(
                        (string) (
                            $invoice[
                                'po_number'
                            ] ?? '—'
                        ),
                        ENT_QUOTES,
                        'UTF-8'
                    ) .
                "</td>
            </tr>
        </table>

        <div style='background:#f9fafb;border-radius:8px;padding:16px;margin-bottom:24px;'>
            <p style='font-size:11px;color:#9ca3af;margin:0 0 6px;text-transform:uppercase;font-weight:700;'>
                Paid To
            </p>
            <p style='font-size:14px;font-weight:700;margin:0 0 2px;'>" .
                htmlspecialchars(
                    (string) $invoice[
                        'supplier_name'
                    ],
                    ENT_QUOTES,
                    'UTF-8'
                ) .
            "</p>
            <p style='font-size:12px;color:#6b7280;margin:0;'>" .
                htmlspecialchars(
                    (string) (
                        $invoice[
                            'supplier_contact_person'
                        ] ?? ''
                    ),
                    ENT_QUOTES,
                    'UTF-8'
                ) .
            "</p>
            <p style='font-size:12px;color:#6b7280;margin:0;'>" .
                htmlspecialchars(
                    (string) (
                        $invoice[
                            'supplier_address'
                        ] ?? ''
                    ),
                    ENT_QUOTES,
                    'UTF-8'
                ) .
            "</p>
            <p style='font-size:12px;color:#6b7280;margin:0;'>" .
                htmlspecialchars(
                    (string) (
                        $invoice[
                            'supplier_email'
                        ] ?? ''
                    ),
                    ENT_QUOTES,
                    'UTF-8'
                ) .
            "</p>
        </div>

        <table style='width:100%;border-collapse:collapse;margin-bottom:24px;'>
            <tr style='background:#1e2d4a;color:white;'>
                <td style='padding:10px 14px;font-size:12px;font-weight:700;'>
                    Description
                </td>
                <td style='padding:10px 14px;font-size:12px;font-weight:700;text-align:right;'>
                    Amount
                </td>
            </tr>

            <tr style='border-bottom:1px solid #e5e7eb;'>
                <td style='padding:12px 14px;font-size:13px;'>
                    Invoice " .
                    htmlspecialchars(
                        (string) $invoice[
                            'invoice_number'
                        ],
                        ENT_QUOTES,
                        'UTF-8'
                    ) .
                "</td>
                <td style='padding:12px 14px;font-size:13px;text-align:right;'>
                    RM " .
                    moneyFormatSen(
                        $invoiceAmountSen
                    ) .
                "</td>
            </tr>

            $creditRow

            <tr style='background:#f0fdf4;'>
                <td style='padding:12px 14px;font-size:14px;font-weight:900;'>
                    Total Paid
                </td>
                <td style='padding:12px 14px;font-size:14px;font-weight:900;text-align:right;color:#047857;'>
                    RM " .
                    moneyFormatSen(
                        $totalPaidSen
                    ) .
                "</td>
            </tr>
        </table>

        <div style='border-top:2px solid #f3f4f6;padding-top:16px;margin-top:40px;'>
            <p style='font-size:11px;color:#9ca3af;margin:0;'>
                This is a computer-generated receipt and serves as official proof of payment from MangaVault to the above supplier.
            </p>
            <p style='font-size:11px;color:#9ca3af;margin:4px 0 0;'>
                MangaVault Sdn Bhd · Generated on " .
                date(
                    'd F Y, h:i A'
                ) .
            "</p>
        </div>
    </body>
    </html>
    ";

    $dompdf =
        new \Dompdf\Dompdf();

    $dompdf->loadHtml($html);
    $dompdf->setPaper(
        'A4',
        'portrait'
    );
    $dompdf->render();
    $dompdf->stream(
        "Receipt_{$receiptNumber}.pdf",
        [
            'Attachment' => true,
        ]
    );

    exit;
}

// ------------------------------------------------------------
// Data for page.
// ------------------------------------------------------------
$invoices = $pdo->query("
    SELECT
        si.*,
        s.supplier_name,
        po.po_number,
        po.po_total_amount,
        sr.return_credit_note_number,
        sr.return_number AS credit_return_number,
        credit_source_po.po_number AS credit_source_po_number
    FROM supplier_invoices si
    JOIN suppliers s
        ON s.supplier_id =
            si.invoice_supplier_id
    LEFT JOIN purchase_orders po
        ON po.po_id =
            si.invoice_po_id
    LEFT JOIN supplier_returns sr
        ON sr.return_id =
            si.invoice_credit_note_id
    LEFT JOIN purchase_orders credit_source_po
        ON credit_source_po.po_id =
            sr.return_po_id
    ORDER BY
        CASE si.invoice_status
            WHEN 'unpaid' THEN 0
            WHEN 'rejected' THEN 1
            ELSE 2
        END,
        si.invoice_created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$availableCredits = $pdo->query("
    SELECT
        sr.return_id,
        sr.return_number,
        sr.return_po_id,
        sr.return_credit_note_number,
        sr.return_credit_note_amount,
        source_po.po_supplier_id,
        source_po.po_number AS source_po_number,
        supplier.supplier_name,
        (
            SELECT source_invoice.invoice_number
            FROM supplier_invoices source_invoice
            WHERE source_invoice.invoice_po_id =
                sr.return_po_id
            AND source_invoice.invoice_status != 'rejected'
            ORDER BY source_invoice.invoice_id DESC
            LIMIT 1
        ) AS source_invoice_number
    FROM supplier_returns sr
    JOIN purchase_orders source_po
        ON source_po.po_id =
            sr.return_po_id
    JOIN suppliers supplier
        ON supplier.supplier_id =
            source_po.po_supplier_id
    WHERE sr.return_status = 'resolved'
    AND sr.return_resolution_type IN (
        'credit_note',
        'dispute_upheld'
    )
    AND sr.return_credit_note_number IS NOT NULL
    AND sr.return_credit_note_amount > 0
    AND sr.return_credit_note_used_invoice_id IS NULL
    ORDER BY
        sr.return_resolved_at ASC,
        sr.return_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$creditsBySupplier = [];

foreach (
    $availableCredits as $credit
) {
    $supplierId =
        (int) $credit[
            'po_supplier_id'
        ];

    $creditsBySupplier[
        $supplierId
    ][] = $credit;
}

$mismatchCount = count(
    array_filter(
        $invoices,
        static fn (
            array $invoice
        ): bool =>
            (int) $invoice[
                'invoice_is_mismatch'
            ] === 1 &&
            $invoice[
                'invoice_status'
            ] === 'unpaid'
    )
);

$unpaidCount = count(
    array_filter(
        $invoices,
        static fn (
            array $invoice
        ): bool =>
            $invoice[
                'invoice_status'
            ] === 'unpaid'
    )
);

$availableCreditTotalSen = 0;

foreach (
    $availableCredits as $credit
) {
    $availableCreditTotalSen +=
        moneyDecimalToSen(
            (string) $credit[
                'return_credit_note_amount'
            ]
        );
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
        Supplier Invoices - MangaVault Admin
    </title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html,
        body {
            max-width: 100%;
            overflow-x: hidden;
        }

        .invoice-table {
            width: 100%;
            table-layout: fixed;
        }

        .invoice-table th,
        .invoice-table td {
            min-width: 0;
            overflow-wrap: anywhere;
            word-break: normal;
        }

        .invoice-table button,
        .invoice-table a {
            max-width: 100%;
        }

        .invoice-pill {
            white-space: nowrap !important;
            overflow-wrap: normal !important;
            word-break: keep-all !important;
            line-height: 1;
        }

        @media (max-width: 1280px) {
            .invoice-table th {
                font-size: 9px;
            }

            .invoice-table td {
                font-size: 12px;
            }
        }
    </style>
</head>
<body class="bg-[#f5f6fa] min-h-screen overflow-x-hidden">

    <?php include '../includes/admin_navbar.php'; ?>

    <main
        class="mx-auto w-full max-w-[1600px] px-3 py-7 md:px-5"
    >
        <div
            class="mb-6 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between"
        >
            <div>
                <p
                    class="text-[11px] font-black uppercase tracking-[0.18em] text-gray-400"
                >
                    Procurement / Accounts Payable
                </p>

                <h1
                    class="mt-1 text-2xl font-black text-gray-900"
                >
                    Supplier Invoices
                </h1>

                <p
                    class="mt-1 text-sm text-gray-500"
                >
                    Validate supplier invoices, manage carried-forward credit notes and process payments.
                </p>
            </div>

            <div
                class="grid grid-cols-3 gap-3"
            >
                <div
                    class="rounded-xl border border-gray-200 bg-white px-4 py-3"
                >
                    <p
                        class="text-[10px] font-bold uppercase tracking-wide text-gray-400"
                    >
                        Unpaid
                    </p>
                    <p
                        class="mt-1 text-xl font-black text-gray-900"
                    >
                        <?= number_format(
                            $unpaidCount
                        ) ?>
                    </p>
                </div>

                <div
                    class="rounded-xl border border-red-200 bg-red-50 px-4 py-3"
                >
                    <p
                        class="text-[10px] font-bold uppercase tracking-wide text-red-400"
                    >
                        Mismatch
                    </p>
                    <p
                        class="mt-1 text-xl font-black text-red-700"
                    >
                        <?= number_format(
                            $mismatchCount
                        ) ?>
                    </p>
                </div>

                <div
                    class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3"
                >
                    <p
                        class="text-[10px] font-bold uppercase tracking-wide text-emerald-500"
                    >
                        Unused Credit
                    </p>
                    <p
                        class="mt-1 text-sm font-black text-emerald-700"
                    >
                        RM
                        <?= moneyFormatSen(
                            $availableCreditTotalSen
                        ) ?>
                    </p>
                </div>
            </div>
        </div>

        <?php if ($success): ?>
        <div
            class="mb-5 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-700"
        >
            ✅
            <?= htmlspecialchars(
                $success,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div
            class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700"
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
            $mismatchCount > 0
        ): ?>
        <div
            class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
        >
            <strong>
                <?= number_format(
                    $mismatchCount
                ) ?>
                invoice(s) have a true PO amount mismatch.
            </strong>
            Credit notes cannot be used to correct these invoices.
            <?php if (
                ($_SESSION[
                    'admin_level'
                ] ?? '') ===
                'senior_admin'
            ): ?>
            You may reject them or use the documented senior-admin override.
            <?php else: ?>
            Senior-admin approval is required for any mismatch override.
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($availableCredits): ?>
        <section
            class="mb-5 overflow-hidden rounded-2xl border border-emerald-200 bg-white shadow-sm"
        >
            <div
                class="flex flex-col gap-3 border-b border-emerald-100 bg-emerald-50 px-5 py-4 md:flex-row md:items-center md:justify-between"
            >
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-lg">💳</span>
                        <h2
                            class="text-sm font-black text-emerald-900"
                        >
                            Available Supplier Credit
                        </h2>
                    </div>

                    <p
                        class="mt-1 max-w-4xl text-xs leading-5 text-emerald-700"
                    >
                        These credit notes came from resolved supplier returns.
                        The original source PO/invoice remains payable at its full
                        amount. Apply a credit only to a later matched invoice;
                        the supplier will see the same credit note reference and
                        adjusted net payable in their portal and payment receipt.
                    </p>
                </div>

                <div
                    class="shrink-0 rounded-xl border border-emerald-200 bg-white px-4 py-3 text-right"
                >
                    <p
                        class="text-[10px] font-black uppercase tracking-wide text-emerald-500"
                    >
                        Credit Available
                    </p>
                    <p
                        class="mt-1 text-lg font-black text-emerald-700"
                    >
                        RM
                        <?= moneyFormatSen(
                            $availableCreditTotalSen
                        ) ?>
                    </p>
                </div>
            </div>

            <div
                class="grid grid-cols-1 gap-3 p-4 lg:grid-cols-2"
            >
                <?php foreach (
                    $availableCredits as $credit
                ):
                    $noticeCreditSen =
                        moneyDecimalToSen(
                            (string) $credit[
                                'return_credit_note_amount'
                            ]
                        );
                ?>
                <div
                    class="rounded-xl border border-gray-200 bg-gray-50/60 p-4"
                >
                    <div
                        class="flex items-start justify-between gap-4"
                    >
                        <div class="min-w-0">
                            <p
                                class="text-xs font-bold text-gray-500"
                            >
                                <?= htmlspecialchars(
                                    (string) $credit[
                                        'supplier_name'
                                    ],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </p>

                            <p
                                class="mt-1 font-mono text-sm font-black text-emerald-700"
                            >
                                <?= htmlspecialchars(
                                    (string) $credit[
                                        'return_credit_note_number'
                                    ],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </p>
                        </div>

                        <div class="text-right">
                            <p
                                class="text-sm font-black text-emerald-700"
                            >
                                RM
                                <?= moneyFormatSen(
                                    $noticeCreditSen
                                ) ?>
                            </p>

                            <span
                                class="mt-1 inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-black text-emerald-700"
                            >
                                UNUSED
                            </span>
                        </div>
                    </div>

                    <div
                        class="mt-3 grid grid-cols-1 gap-1 text-[11px] leading-5 text-gray-500 sm:grid-cols-2"
                    >
                        <p>
                            Source PO:
                            <strong class="text-gray-700">
                                <?= htmlspecialchars(
                                    (string) $credit[
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
                                    (string) $credit[
                                        'return_number'
                                    ],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </strong>
                        </p>

                        <p class="sm:col-span-2">
                            Source Invoice:
                            <strong class="text-gray-700">
                                <?= htmlspecialchars(
                                    (string) (
                                        $credit[
                                            'source_invoice_number'
                                        ] ?? 'Not submitted yet'
                                    ),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </strong>
                        </p>
                    </div>

                    <p
                        class="mt-3 rounded-lg bg-white px-3 py-2 text-[11px] leading-5 text-gray-500"
                    >
                        This is a carried-forward credit. It does not reduce
                        the source invoice; it can reduce the cash settlement
                        of another eligible invoice from the same supplier.
                    </p>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <section
            class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm"
        >
            <?php if (
                count($invoices) === 0
            ): ?>
            <div class="py-16 text-center">
                <div
                    class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-gray-100 text-xl text-gray-400"
                >
                    ▩
                </div>

                <p
                    class="mt-4 text-sm font-semibold text-gray-500"
                >
                    No invoices recorded yet.
                </p>
            </div>
            <?php else: ?>
            <div class="w-full overflow-hidden">
                <table
                    class="invoice-table w-full table-fixed"
                >
                    <colgroup>
                        <col style="width:11%">
                        <col style="width:15%">
                        <col style="width:10%">
                        <col style="width:16%">
                        <col style="width:10%">
                        <col style="width:9%">
                        <col style="width:8%">
                        <col style="width:8%">
                        <col style="width:13%">
                    </colgroup>
                    <thead>
                        <tr
                            class="border-b border-gray-200 bg-gray-50"
                        >
                            <th
                                class="px-3 py-3 text-left text-[10px] font-black uppercase tracking-wide text-gray-400"
                            >
                                Invoice
                            </th>

                            <th
                                class="px-3 py-3 text-left text-[10px] font-black uppercase tracking-wide text-gray-400"
                            >
                                Supplier / PO
                            </th>

                            <th
                                class="px-3 py-3 text-right text-[10px] font-black uppercase tracking-wide text-gray-400"
                            >
                                Invoice Amount
                            </th>

                            <th
                                class="px-3 py-3 text-left text-[10px] font-black uppercase tracking-wide text-gray-400"
                            >
                                Credit Note
                            </th>

                            <th
                                class="px-3 py-3 text-right text-[10px] font-black uppercase tracking-wide text-gray-400"
                            >
                                Net Payable
                            </th>

                            <th
                                class="px-3 py-3 text-center text-[10px] font-black uppercase tracking-wide text-gray-400"
                            >
                                Match
                            </th>

                            <th
                                class="px-3 py-3 text-left text-[10px] font-black uppercase tracking-wide text-gray-400"
                            >
                                Due
                            </th>

                            <th
                                class="px-3 py-3 text-center text-[10px] font-black uppercase tracking-wide text-gray-400"
                            >
                                Status
                            </th>

                            <th
                                class="px-3 py-3 text-left text-[10px] font-black uppercase tracking-wide text-gray-400"
                            >
                                Action
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach (
                            $invoices as $invoice
                        ):
                            $invoiceAmountSen =
                                moneyDecimalToSen(
                                    (string) $invoice[
                                        'invoice_amount'
                                    ]
                                );

                            $creditAppliedSen =
                                moneyDecimalToSen(
                                    (string) (
                                        $invoice[
                                            'invoice_credit_applied_amount'
                                        ] ?? '0.00'
                                    )
                                );

                            $netPayableSen = max(
                                0,
                                $invoiceAmountSen -
                                    $creditAppliedSen
                            );

                            $poTotalSen =
                                moneyDecimalToSen(
                                    (string) (
                                        $invoice[
                                            'po_total_amount'
                                        ] ?? '0.00'
                                    )
                                );

                            $isOverdue =
                                $invoice[
                                    'invoice_status'
                                ] === 'unpaid' &&
                                !empty(
                                    $invoice[
                                        'invoice_due_date'
                                    ]
                                ) &&
                                strtotime(
                                    (string) $invoice[
                                        'invoice_due_date'
                                    ]
                                ) < time();

                            $eligibleCredits = [];

                            if (
                                $invoice[
                                    'invoice_status'
                                ] === 'unpaid' &&
                                (int) $invoice[
                                    'invoice_is_mismatch'
                                ] === 0 &&
                                empty(
                                    $invoice[
                                        'invoice_credit_note_id'
                                    ]
                                ) &&
                                !empty(
                                    $creditsBySupplier[
                                        (int) $invoice[
                                            'invoice_supplier_id'
                                        ]
                                    ]
                                )
                            ) {
                                foreach (
                                    $creditsBySupplier[
                                        (int) $invoice[
                                            'invoice_supplier_id'
                                        ]
                                    ] as $credit
                                ) {
                                    $creditAmountSen =
                                        moneyDecimalToSen(
                                            (string) $credit[
                                                'return_credit_note_amount'
                                            ]
                                        );

                                    if (
                                        $creditAmountSen > 0 &&
                                        $creditAmountSen <=
                                            $invoiceAmountSen &&
                                        (int) $credit[
                                            'return_po_id'
                                        ] !==
                                            (int) $invoice[
                                                'invoice_po_id'
                                            ]
                                    ) {
                                        $eligibleCredits[] = [
                                            'credit' =>
                                                $credit,
                                            'amount_sen' =>
                                                $creditAmountSen,
                                        ];
                                    }
                                }
                            }

                            $statusClass =
                                match (
                                    $invoice[
                                        'invoice_status'
                                    ]
                                ) {
                                    'paid' =>
                                        'border-emerald-200 bg-emerald-50 text-emerald-700',
                                    'rejected' =>
                                        'border-red-200 bg-red-50 text-red-700',
                                    default =>
                                        'border-amber-200 bg-amber-50 text-amber-700',
                                };
                        ?>
                        <tr
                            class="border-b border-gray-100 align-top hover:bg-gray-50/60"
                        >
                            <td class="px-3 py-4">
                                <p
                                    class="whitespace-nowrap text-sm font-black text-gray-900"
                                >
                                    <?= htmlspecialchars(
                                        (string) $invoice[
                                            'invoice_number'
                                        ],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>

                                <?php if (
                                    $invoice[
                                        'invoice_status'
                                    ] === 'rejected' &&
                                    !empty(
                                        $invoice[
                                            'invoice_reject_reason'
                                        ]
                                    )
                                ): ?>
                                <p
                                    class="mt-1 text-xs leading-4 text-red-500 break-words"
                                >
                                    <?= htmlspecialchars(
                                        (string) $invoice[
                                            'invoice_reject_reason'
                                        ],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>
                                <?php endif; ?>

                                <?php if (
                                    $invoice[
                                        'invoice_status'
                                    ] === 'paid' &&
                                    !empty(
                                        $invoice[
                                            'invoice_override_reason'
                                        ]
                                    )
                                ): ?>
                                <button
                                    type="button"
                                    onclick='showOverrideReason(
                                        <?= json_encode(
                                            (string) $invoice[
                                                'invoice_override_reason'
                                            ],
                                            JSON_HEX_TAG |
                                            JSON_HEX_AMP |
                                            JSON_HEX_APOS |
                                            JSON_HEX_QUOT
                                        ) ?>
                                    )'
                                    class="mt-1 text-left text-xs font-semibold text-orange-600 hover:underline"
                                >
                                    Paid with override — view reason
                                </button>
                                <?php endif; ?>
                            </td>

                            <td class="px-3 py-4">
                                <p
                                    class="text-sm font-semibold leading-5 text-gray-700 break-words"
                                >
                                    <?= htmlspecialchars(
                                        (string) $invoice[
                                            'supplier_name'
                                        ],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>

                                <p
                                    class="mt-1 text-xs leading-4 text-gray-400 break-words"
                                >
                                    <?= htmlspecialchars(
                                        (string) (
                                            $invoice[
                                                'po_number'
                                            ] ?? '—'
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                    · PO RM
                                    <?= moneyFormatSen(
                                        $poTotalSen
                                    ) ?>
                                </p>
                            </td>

                            <td
                                class="px-3 py-4 text-right"
                            >
                                <p
                                    class="whitespace-nowrap text-sm font-black text-gray-900"
                                >
                                    RM
                                    <?= moneyFormatSen(
                                        $invoiceAmountSen
                                    ) ?>
                                </p>
                            </td>

                            <td class="px-3 py-4">
                                <?php if (
                                    $creditAppliedSen > 0
                                ): ?>
                                <div
                                    class="w-full max-w-full rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-2"
                                >
                                    <p
                                        class="font-mono text-xs font-black text-emerald-700"
                                    >
                                        <?= htmlspecialchars(
                                            (string) (
                                                $invoice[
                                                    'return_credit_note_number'
                                                ] ?? 'Credit Note'
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </p>

                                    <p
                                        class="mt-1 text-xs font-semibold text-emerald-700"
                                    >
                                        - RM
                                        <?= moneyFormatSen(
                                            $creditAppliedSen
                                        ) ?>
                                    </p>

                                    <p
                                        class="mt-1 text-[10px] leading-4 text-emerald-700/80"
                                    >
                                        Origin:
                                        <?= htmlspecialchars(
                                            (string) (
                                                $invoice[
                                                    'credit_source_po_number'
                                                ] ?? '—'
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                        /
                                        <?= htmlspecialchars(
                                            (string) (
                                                $invoice[
                                                    'credit_return_number'
                                                ] ?? '—'
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </p>

                                    <?php if (
                                        $invoice[
                                            'invoice_status'
                                        ] === 'unpaid'
                                    ): ?>
                                    <form
                                        method="POST"
                                        class="mt-1"
                                        onsubmit="return confirm('Remove this credit note and restore the full invoice payable amount?');"
                                    >
                                        <?php csrf_field(); ?>

                                        <input
                                            type="hidden"
                                            name="remove_credit_note"
                                            value="1"
                                        >

                                        <input
                                            type="hidden"
                                            name="invoice_id"
                                            value="<?= (int) $invoice[
                                                'invoice_id'
                                            ] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="text-[11px] font-bold text-red-500 hover:underline"
                                        >
                                            Remove credit
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <?php elseif (
                                    $eligibleCredits
                                ): ?>
                                <div
                                    class="w-full min-w-0 space-y-2"
                                >
                                    <?php foreach (
                                        $eligibleCredits as $option
                                    ):
                                        $credit =
                                            $option[
                                                'credit'
                                            ];

                                        $afterCreditSen =
                                            $invoiceAmountSen -
                                            $option[
                                                'amount_sen'
                                            ];
                                    ?>
                                    <form
                                        method="POST"
                                        onsubmit="return confirm(
                                            'Apply <?= htmlspecialchars(
                                                (string) $credit[
                                                    'return_credit_note_number'
                                                ],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?> from <?= htmlspecialchars(
                                                (string) $credit[
                                                    'source_po_number'
                                                ],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?> / <?= htmlspecialchars(
                                                (string) $credit[
                                                    'return_number'
                                                ],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>? Invoice total stays RM <?= moneyFormatSen(
                                                $invoiceAmountSen
                                            ) ?> and net payable becomes RM <?= moneyFormatSen(
                                                $afterCreditSen
                                            ) ?>. The supplier will see this credit adjustment on the invoice record and payment receipt.'
                                        );"
                                    >
                                        <?php csrf_field(); ?>

                                        <input
                                            type="hidden"
                                            name="apply_credit_note"
                                            value="1"
                                        >

                                        <input
                                            type="hidden"
                                            name="invoice_id"
                                            value="<?= (int) $invoice[
                                                'invoice_id'
                                            ] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="return_id"
                                            value="<?= (int) $credit[
                                                'return_id'
                                            ] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="w-full min-w-0 rounded-lg border border-emerald-200 bg-white px-2.5 py-2 text-left transition hover:bg-emerald-50"
                                        >
                                            <span
                                                class="block font-mono text-[11px] font-black text-emerald-700"
                                            >
                                                <?= htmlspecialchars(
                                                    (string) $credit[
                                                        'return_credit_note_number'
                                                    ],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </span>

                                            <span
                                                class="mt-1 block text-[11px] text-gray-500"
                                            >
                                                Credit RM
                                                <?= moneyFormatSen(
                                                    $option[
                                                        'amount_sen'
                                                    ]
                                                ) ?>
                                                · from
                                                <?= htmlspecialchars(
                                                    (string) $credit[
                                                        'source_po_number'
                                                    ],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                                /
                                                <?= htmlspecialchars(
                                                    (string) $credit[
                                                        'return_number'
                                                    ],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </span>

                                            <span
                                                class="mt-1 block text-xs font-black text-emerald-700"
                                            >
                                                Apply → Pay RM
                                                <?= moneyFormatSen(
                                                    $afterCreditSen
                                                ) ?>
                                            </span>
                                        </button>
                                    </form>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <span
                                    class="text-xs text-gray-400"
                                >
                                    —
                                </span>
                                <?php endif; ?>
                            </td>

                            <td
                                class="px-3 py-4 text-right"
                            >
                                <p
                                    class="whitespace-nowrap text-sm font-black <?= $creditAppliedSen > 0 ? 'text-emerald-700' : 'text-gray-900' ?>"
                                >
                                    RM
                                    <?= moneyFormatSen(
                                        $netPayableSen
                                    ) ?>
                                </p>

                                <?php if (
                                    $creditAppliedSen > 0
                                ): ?>
                                <p
                                    class="mt-1 text-[11px] text-gray-400"
                                >
                                    Invoice RM
                                    <?= moneyFormatSen(
                                        $invoiceAmountSen
                                    ) ?>
                                    less credit
                                </p>
                                <?php endif; ?>
                            </td>

                            <td
                                class="px-3 py-4 text-center"
                            >
                                <?php if (
                                    (int) $invoice[
                                        'invoice_is_mismatch'
                                    ] === 1
                                ): ?>
                                <span
                                    class="invoice-pill inline-flex max-w-full items-center justify-center rounded-full border border-red-200 bg-red-50 px-2.5 py-1 text-[11px] font-black text-red-600"
                                    title="PO Total: RM <?= moneyFormatSen(
                                        $poTotalSen
                                    ) ?>"
                                >
                                    Mismatch
                                </span>
                                <?php else: ?>
                                <span
                                    class="invoice-pill inline-flex max-w-full items-center justify-center rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[11px] font-black text-emerald-700"
                                >
                                    Matched
                                </span>
                                <?php endif; ?>
                            </td>

                            <td
                                class="px-3 py-4 text-sm leading-5 <?= $isOverdue ? 'font-bold text-red-500' : 'text-gray-500' ?>"
                            >
                                <?= !empty(
                                    $invoice[
                                        'invoice_due_date'
                                    ]
                                )
                                    ? date(
                                        'd M Y',
                                        strtotime(
                                            (string) $invoice[
                                                'invoice_due_date'
                                            ]
                                        )
                                    )
                                    : '—' ?>

                                <?php if (
                                    $isOverdue
                                ): ?>
                                <span
                                    class="ml-1 text-xs"
                                >
                                    Overdue
                                </span>
                                <?php endif; ?>
                            </td>

                            <td
                                class="px-3 py-4 text-center"
                            >
                                <span
                                    class="<?= $statusClass ?> invoice-pill inline-flex items-center justify-center rounded-full border px-2.5 py-1 text-[11px] font-black capitalize"
                                >
                                    <?= htmlspecialchars(
                                        (string) $invoice[
                                            'invoice_status'
                                        ],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>
                            </td>

                            <td class="px-3 py-4">
                                <?php if (
                                    $invoice[
                                        'invoice_status'
                                    ] === 'unpaid'
                                ): ?>
                                <div
                                    class="w-full min-w-0 space-y-2"
                                >
                                    <?php if (
                                        (int) $invoice[
                                            'invoice_is_mismatch'
                                        ] === 0
                                    ): ?>
                                    <form
                                        method="POST"
                                        onsubmit="return confirm('Confirm supplier payment of RM <?= moneyFormatSen(
                                            $netPayableSen
                                        ) ?>?');"
                                    >
                                        <?php csrf_field(); ?>

                                        <input
                                            type="hidden"
                                            name="mark_paid"
                                            value="1"
                                        >

                                        <input
                                            type="hidden"
                                            name="invoice_id"
                                            value="<?= (int) $invoice[
                                                'invoice_id'
                                            ] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="w-full min-w-0 rounded-lg bg-emerald-600 px-2 py-2 text-[11px] font-black leading-tight text-white transition hover:bg-emerald-700"
                                        >
                                            <?= $creditAppliedSen > 0
                                                ? 'Pay Net Amount'
                                                : 'Pay Invoice Total' ?>
                                            · RM
                                            <?= moneyFormatSen(
                                                $netPayableSen
                                            ) ?>
                                        </button>
                                    </form>
                                    <?php elseif (
                                        (
                                            $_SESSION[
                                                'admin_level'
                                            ] ?? ''
                                        ) === 'senior_admin'
                                    ): ?>
                                    <button
                                        type="button"
                                        onclick='openOverrideModal(
                                            <?= (int) $invoice[
                                                'invoice_id'
                                            ] ?>,
                                            <?= json_encode(
                                                (string) $invoice[
                                                    'invoice_number'
                                                ],
                                                JSON_HEX_TAG |
                                                JSON_HEX_AMP |
                                                JSON_HEX_APOS |
                                                JSON_HEX_QUOT
                                            ) ?>,
                                            <?= $invoiceAmountSen ?>,
                                            <?= $poTotalSen ?>
                                        )'
                                        class="w-full min-w-0 rounded-lg border border-orange-200 bg-orange-50 px-2 py-2 text-[11px] font-black leading-tight text-orange-700 transition hover:bg-orange-100"
                                    >
                                        Pay Invoice Total
                                        · RM
                                        <?= moneyFormatSen(
                                            $invoiceAmountSen
                                        ) ?>
                                        · Override
                                    </button>
                                    <?php else: ?>
                                    <div
                                        class="rounded-lg bg-gray-100 px-3 py-2 text-center text-xs font-semibold text-gray-400"
                                    >
                                        Senior-admin override required
                                    </div>
                                    <?php endif; ?>

                                    <button
                                        type="button"
                                        onclick='openRejectModal(
                                            <?= (int) $invoice[
                                                'invoice_id'
                                            ] ?>,
                                            <?= json_encode(
                                                (string) $invoice[
                                                    'invoice_number'
                                                ],
                                                JSON_HEX_TAG |
                                                JSON_HEX_AMP |
                                                JSON_HEX_APOS |
                                                JSON_HEX_QUOT
                                            ) ?>
                                        )'
                                        class="w-full min-w-0 rounded-lg border border-red-200 bg-white px-2 py-2 text-[11px] font-black leading-tight text-red-600 transition hover:bg-red-50"
                                    >
                                        Reject Invoice
                                    </button>
                                </div>
                                <?php elseif (
                                    $invoice[
                                        'invoice_status'
                                    ] === 'paid'
                                ): ?>
                                <a
                                    href="?download_receipt=<?= (int) $invoice[
                                        'invoice_id'
                                    ] ?>"
                                    class="inline-flex w-full min-w-0 items-center justify-center rounded-lg border border-blue-200 bg-blue-50 px-2 py-2 text-center text-[11px] font-black leading-tight text-blue-700 hover:bg-blue-100"
                                    title="Download Payment Receipt PDF"
                                >
                                    Receipt PDF
                                </a>
                                <?php else: ?>
                                <span
                                    class="text-xs text-gray-400"
                                >
                                    Closed
                                </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <section
            class="mt-6 rounded-2xl border border-gray-200 bg-white p-5"
        >
            <div
                class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between"
            >
                <div>
                    <h2
                        class="text-sm font-black text-gray-900"
                    >
                        Credit Note Policy
                    </h2>

                    <p
                        class="mt-1 max-w-4xl text-xs leading-5 text-gray-500"
                    >
                        A supplier invoice is validated against its own purchase order at the original invoice amount. A credit note is a separate supplier credit created from an earlier resolved return and may be carried forward to a later matched invoice from the same supplier. Applying credit changes the net cash payable only; it never changes invoice/PO match status.
                    </p>
                </div>

                <div
                    class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs text-emerald-800"
                >
                    Example:
                    Invoice RM50.00
                    − Credit RM30.00
                    =
                    <strong>Pay RM20.00</strong>
                </div>
            </div>
        </section>
    </main>

    <!-- Reject Invoice Modal -->
    <div
        id="rejectModal"
        class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 px-5"
    >
        <div
            class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"
        >
            <div
                class="flex items-start justify-between gap-4"
            >
                <div>
                    <h3
                        class="text-lg font-black text-gray-900"
                    >
                        Reject Invoice
                    </h3>

                    <p
                        class="mt-1 text-xs text-gray-500"
                    >
                        Invoice
                        <span
                            id="rejectInvoiceLabel"
                            class="font-black text-gray-700"
                        ></span>
                    </p>
                </div>

                <button
                    type="button"
                    onclick="closeRejectModal()"
                    class="text-gray-400 hover:text-gray-700"
                >
                    ✕
                </button>
            </div>

            <form
                method="POST"
                class="mt-5"
                onsubmit="return confirm('Reject this supplier invoice?');"
            >
                <?php csrf_field(); ?>

                <input
                    type="hidden"
                    name="reject_invoice"
                    value="1"
                >

                <input
                    type="hidden"
                    id="rejectInvoiceId"
                    name="invoice_id"
                    value=""
                >

                <label
                    class="mb-2 block text-xs font-black uppercase tracking-wide text-gray-500"
                >
                    Rejection Reason
                </label>

                <textarea
                    name="reject_reason"
                    rows="4"
                    maxlength="2000"
                    required
                    class="w-full rounded-xl border border-gray-300 px-4 py-3 text-sm outline-none focus:border-red-400 focus:ring-4 focus:ring-red-50"
                    placeholder="Explain the issue clearly for the supplier..."
                ></textarea>

                <div
                    class="mt-5 flex gap-3"
                >
                    <button
                        type="button"
                        onclick="closeRejectModal()"
                        class="flex-1 rounded-xl border border-gray-300 px-4 py-2.5 text-sm font-bold text-gray-600 hover:bg-gray-50"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="flex-1 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-black text-white hover:bg-red-700"
                    >
                        Reject Invoice
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Mismatch Override Modal -->
    <div
        id="overrideModal"
        class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 px-5"
    >
        <div
            class="w-full max-w-md rounded-2xl border-4 border-orange-400 bg-white p-6 shadow-2xl"
        >
            <div
                class="flex items-start justify-between gap-4"
            >
                <div>
                    <h3
                        class="text-lg font-black text-gray-900"
                    >
                        Amount Mismatch Override
                    </h3>

                    <p
                        class="mt-1 text-xs text-orange-600"
                    >
                        Senior admin confirmation required
                    </p>
                </div>

                <button
                    type="button"
                    onclick="closeOverrideModal()"
                    class="text-gray-400 hover:text-gray-700"
                >
                    ✕
                </button>
            </div>

            <div
                class="mt-5 rounded-xl border border-orange-200 bg-orange-50 p-4"
            >
                <div
                    class="flex items-center justify-between gap-4 text-sm"
                >
                    <span class="text-gray-500">
                        Invoice
                        <span
                            id="overrideInvoiceLabel"
                            class="font-bold"
                        ></span>
                    </span>

                    <span
                        id="overrideInvoiceAmount"
                        class="font-black text-red-600"
                    ></span>
                </div>

                <div
                    class="mt-2 flex items-center justify-between gap-4 text-sm"
                >
                    <span class="text-gray-500">
                        PO Total
                    </span>

                    <span
                        id="overridePoAmount"
                        class="font-black text-gray-800"
                    ></span>
                </div>
            </div>

            <form
                method="POST"
                class="mt-5"
                onsubmit="return confirm('Proceed with this mismatched supplier payment override?');"
            >
                <?php csrf_field(); ?>

                <input
                    type="hidden"
                    name="mark_paid_confirm"
                    value="1"
                >

                <input
                    type="hidden"
                    id="overrideInvoiceId"
                    name="invoice_id"
                    value=""
                >

                <label
                    class="mb-2 block text-xs font-black uppercase tracking-wide text-gray-500"
                >
                    Override Justification
                </label>

                <textarea
                    name="override_reason"
                    rows="4"
                    maxlength="2000"
                    required
                    class="w-full rounded-xl border border-gray-300 px-4 py-3 text-sm outline-none focus:border-orange-400 focus:ring-4 focus:ring-orange-50"
                    placeholder="Document why the mismatched amount is being approved..."
                ></textarea>

                <div
                    class="mt-5 flex gap-3"
                >
                    <button
                        type="button"
                        onclick="closeOverrideModal()"
                        class="flex-1 rounded-xl border border-gray-300 px-4 py-2.5 text-sm font-bold text-gray-600 hover:bg-gray-50"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="flex-1 rounded-xl bg-orange-600 px-4 py-2.5 text-sm font-black text-white hover:bg-orange-700"
                    >
                        Confirm Override
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function moneyFromSen(sen) {
        return 'RM ' +
            (
                Number(sen) / 100
            ).toLocaleString(
                'en-MY',
                {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                }
            );
    }

    function openRejectModal(
        invoiceId,
        invoiceNumber
    ) {
        document.getElementById(
            'rejectInvoiceId'
        ).value = invoiceId;

        document.getElementById(
            'rejectInvoiceLabel'
        ).textContent = invoiceNumber;

        const modal =
            document.getElementById(
                'rejectModal'
            );

        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeRejectModal() {
        const modal =
            document.getElementById(
                'rejectModal'
            );

        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function openOverrideModal(
        invoiceId,
        invoiceNumber,
        invoiceAmountSen,
        poAmountSen
    ) {
        document.getElementById(
            'overrideInvoiceId'
        ).value = invoiceId;

        document.getElementById(
            'overrideInvoiceLabel'
        ).textContent = invoiceNumber;

        document.getElementById(
            'overrideInvoiceAmount'
        ).textContent =
            moneyFromSen(
                invoiceAmountSen
            );

        document.getElementById(
            'overridePoAmount'
        ).textContent =
            moneyFromSen(
                poAmountSen
            );

        const modal =
            document.getElementById(
                'overrideModal'
            );

        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeOverrideModal() {
        const modal =
            document.getElementById(
                'overrideModal'
            );

        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function showOverrideReason(reason) {
        alert(
            'Override Reason:\n\n' +
            reason
        );
    }

    document.addEventListener(
        'keydown',
        function (event) {
            if (event.key === 'Escape') {
                closeRejectModal();
                closeOverrideModal();
            }
        }
    );

    document.getElementById(
        'rejectModal'
    ).addEventListener(
        'click',
        function (event) {
            if (
                event.target ===
                this
            ) {
                closeRejectModal();
            }
        }
    );

    document.getElementById(
        'overrideModal'
    ).addEventListener(
        'click',
        function (event) {
            if (
                event.target ===
                this
            ) {
                closeOverrideModal();
            }
        }
    );
    </script>
</body>
</html>