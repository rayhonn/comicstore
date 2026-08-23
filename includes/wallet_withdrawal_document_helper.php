<?php

require_once __DIR__ . '/money_helper.php';
require_once __DIR__ . '/wallet_withdrawal_helper.php';

function loadWalletWithdrawalDocumentRecord(
    PDO $pdo,
    int $withdrawalId,
    ?int $customerId = null
): array {
    if ($withdrawalId < 1) {
        throw new WalletWithdrawalException(
            'Invalid withdrawal document request.'
        );
    }

    $sql = "
        SELECT
            wr.*,
            customer.user_first_name
                AS customer_first_name,
            customer.user_last_name
                AS customer_last_name,
            customer.user_gmail
                AS customer_email,
            reviewer.user_first_name
                AS reviewer_first_name,
            reviewer.user_last_name
                AS reviewer_last_name,
            completer.user_first_name
                AS completer_first_name,
            completer.user_last_name
                AS completer_last_name
        FROM wallet_withdrawal_requests wr
        INNER JOIN users customer
            ON customer.user_id =
                wr.wallet_withdrawal_user_id
        LEFT JOIN users reviewer
            ON reviewer.user_id =
                wr.wallet_withdrawal_reviewed_by
        LEFT JOIN users completer
            ON completer.user_id =
                wr.wallet_withdrawal_receipt_uploaded_by
        WHERE wr.wallet_withdrawal_id = ?
        AND wr.wallet_withdrawal_status IN (
            'approved',
            'completed'
        )
    ";
    $params = [$withdrawalId];

    if ($customerId !== null) {
        if ($customerId < 1) {
            throw new WalletWithdrawalException(
                'Invalid customer withdrawal document request.'
            );
        }

        $sql .= '
            AND wr.wallet_withdrawal_user_id = ?
        ';
        $params[] = $customerId;
    }

    $sql .= ' LIMIT 1';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    $record = $statement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$record) {
        throw new WalletWithdrawalException(
            'The official withdrawal document is not available.'
        );
    }

    return $record;
}

function walletWithdrawalDocumentEscape(
    mixed $value
): string {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function walletWithdrawalDocumentDate(
    mixed $value,
    string $fallback = 'Not available'
): string {
    $value = trim((string) $value);

    if ($value === '') {
        return $fallback;
    }

    try {
        $date = new DateTimeImmutable(
            $value,
            new DateTimeZone(
                'Asia/Kuala_Lumpur'
            )
        );
    } catch (Throwable) {
        return $fallback;
    }

    return $date->format(
        'd M Y, h:i A'
    ) . ' MYT';
}

function walletWithdrawalDocumentName(
    array $record,
    string $prefix
): string {
    return trim(
        (string) ($record[$prefix . '_first_name'] ?? '') .
        ' ' .
        (string) ($record[$prefix . '_last_name'] ?? '')
    );
}

function walletWithdrawalDocumentNumber(
    array $record
): string {
    $withdrawalId = (int) (
        $record['wallet_withdrawal_id'] ?? 0
    );
    try {
        $createdAt = new DateTimeImmutable(
            (string) (
                $record[
                    'wallet_withdrawal_created_at'
                ] ?? 'now'
            ),
            new DateTimeZone(
                'Asia/Kuala_Lumpur'
            )
        );
    } catch (Throwable) {
        $createdAt = new DateTimeImmutable(
            'now',
            new DateTimeZone(
                'Asia/Kuala_Lumpur'
            )
        );
    }
    $datePart = $createdAt->format('Ymd');
    $suffix = (
        ($record['wallet_withdrawal_status'] ?? '') ===
            'completed'
    )
        ? 'C'
        : 'A';

    return 'MV-WD-' .
        $datePart .
        '-' .
        str_pad(
            (string) $withdrawalId,
            4,
            '0',
            STR_PAD_LEFT
        ) .
        '-' .
        $suffix;
}

function buildWalletWithdrawalDocumentHtml(
    array $record
): string {
    $status = (string) (
        $record['wallet_withdrawal_status'] ?? ''
    );
    $isCompleted = $status === 'completed';
    $documentTitle = $isCompleted
        ? 'Bank Transfer Confirmation'
        : 'Withdrawal Approval Advice';
    $statusLabel = $isCompleted
        ? 'COMPLETED'
        : 'APPROVED — AWAITING BANK TRANSFER';
    $documentNumber =
        walletWithdrawalDocumentNumber($record);
    $customerName =
        walletWithdrawalDocumentName(
            $record,
            'customer'
        );
    $reviewerName =
        walletWithdrawalDocumentName(
            $record,
            'reviewer'
        );
    $completerName =
        walletWithdrawalDocumentName(
            $record,
            'completer'
        );
    $authorizedName = $isCompleted &&
        $completerName !== ''
            ? $completerName
            : $reviewerName;
    $authorizedName = $authorizedName !== ''
        ? $authorizedName
        : 'MangaVault Administrator';
    $authorizationDate = $isCompleted
        ? ($record['wallet_withdrawal_completed_at'] ?? '')
        : ($record['wallet_withdrawal_reviewed_at'] ?? '');
    $deadline = walletWithdrawalBusinessDayDeadline(
        (string) (
            $record['wallet_withdrawal_reviewed_at'] ?? ''
        )
    );
    $deadlineLabel = $deadline instanceof DateTimeImmutable
        ? $deadline->format('d M Y')
        : 'Within 14 business days after approval';
    $amountSen = moneyDecimalToSen(
        (string) (
            $record['wallet_withdrawal_amount'] ?? '0'
        )
    );
    $transferReference = trim(
        (string) (
            $record[
                'wallet_withdrawal_transfer_reference'
            ] ?? ''
        )
    );
    $recordHash = strtoupper(
        substr(
            hash(
                'sha256',
                $documentNumber . '|' .
                $status . '|' .
                (string) (
                    $record[
                        'wallet_withdrawal_amount'
                    ] ?? ''
                ) . '|' .
                (string) $authorizationDate
            ),
            0,
            16
        )
    );

    $transferRows = '';

    if ($isCompleted) {
        $transferRows = '
            <tr>
                <td class="label">Bank transfer reference</td>
                <td class="value mono">' .
                    walletWithdrawalDocumentEscape(
                        $transferReference !== ''
                            ? $transferReference
                            : 'Not recorded'
                    ) .
                '</td>
            </tr>
            <tr>
                <td class="label">Transfer completed</td>
                <td class="value">' .
                    walletWithdrawalDocumentEscape(
                        walletWithdrawalDocumentDate(
                            $record[
                                'wallet_withdrawal_completed_at'
                            ] ?? ''
                        )
                    ) .
                '</td>
            </tr>
        ';
    }

    $noticeTitle = $isCompleted
        ? 'Transfer record confirmed'
        : 'Important processing notice';
    $noticeText = $isCompleted
        ? 'This document confirms that MangaVault recorded the bank transfer as completed. It should be retained together with the customer bank statement and the original bank transfer evidence.'
        : 'This document confirms administrative approval only. It is not proof that the receiving bank has credited the funds. Processing may take up to 14 business days after approval.';

    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 34px 38px 42px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #1f2937;
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            line-height: 1.55;
        }

        .header {
            border-bottom: 3px solid #dc2626;
            padding-bottom: 18px;
        }

        .brand {
            color: #dc2626;
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.7px;
        }

        .brand-subtitle {
            color: #6b7280;
            font-size: 9px;
            letter-spacing: 1.8px;
            text-transform: uppercase;
        }

        .document-meta {
            float: right;
            text-align: right;
        }

        .document-title {
            color: #111827;
            font-size: 17px;
            font-weight: 800;
        }

        .document-number {
            color: #6b7280;
            font-size: 9px;
            margin-top: 3px;
        }

        .clear {
            clear: both;
        }

        .status-row {
            margin: 22px 0 16px;
        }

        .status {
            background: ' . ($isCompleted
                ? '#dcfce7'
                : '#dbeafe') . ';
            border: 1px solid ' . ($isCompleted
                ? '#86efac'
                : '#93c5fd') . ';
            border-radius: 999px;
            color: ' . ($isCompleted
                ? '#166534'
                : '#1d4ed8') . ';
            display: inline-block;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: 0.7px;
            padding: 6px 11px;
        }

        .amount-panel {
            background: #111827;
            border-radius: 12px;
            color: #ffffff;
            margin-bottom: 18px;
            padding: 18px 20px;
        }

        .amount-label {
            color: #cbd5e1;
            font-size: 9px;
            letter-spacing: 1.2px;
            text-transform: uppercase;
        }

        .amount {
            font-size: 28px;
            font-weight: 800;
            margin-top: 2px;
        }

        .purpose {
            color: #e5e7eb;
            float: right;
            margin-top: 10px;
            text-align: right;
        }

        .section-title {
            color: #111827;
            font-size: 12px;
            font-weight: 800;
            margin: 20px 0 8px;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        td {
            border-bottom: 1px solid #e5e7eb;
            padding: 9px 10px;
            vertical-align: top;
        }

        .label {
            background: #f9fafb;
            color: #6b7280;
            width: 36%;
        }

        .value {
            color: #111827;
            font-weight: 700;
        }

        .mono {
            font-family: DejaVu Sans Mono, monospace;
        }

        .notice {
            background: ' . ($isCompleted
                ? '#f0fdf4'
                : '#fffbeb') . ';
            border: 1px solid ' . ($isCompleted
                ? '#bbf7d0'
                : '#fde68a') . ';
            border-radius: 10px;
            margin-top: 18px;
            padding: 12px 14px;
        }

        .notice-title {
            color: ' . ($isCompleted
                ? '#166534'
                : '#92400e') . ';
            font-weight: 800;
            margin-bottom: 3px;
        }

        .notice-text {
            color: ' . ($isCompleted
                ? '#166534'
                : '#92400e') . ';
        }

        .authorization {
            margin-top: 20px;
            width: 100%;
        }

        .authorization-copy {
            padding-right: 18px;
            width: 68%;
        }

        .authorization-copy p {
            margin: 3px 0;
        }

        .stamp-cell {
            text-align: center;
            width: 32%;
        }

        .stamp {
            border: 4px double #dc2626;
            border-radius: 50%;
            color: #dc2626;
            display: inline-block;
            font-size: 8px;
            font-weight: 800;
            height: 100px;
            line-height: 1.35;
            padding-top: 23px;
            text-align: center;
            transform: rotate(-7deg);
            width: 100px;
        }

        .stamp-main {
            font-size: 13px;
            letter-spacing: 0.8px;
        }

        .footer {
            bottom: -23px;
            color: #9ca3af;
            font-size: 8px;
            left: 0;
            position: fixed;
            right: 0;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="document-meta">
            <div class="document-title">' .
                walletWithdrawalDocumentEscape(
                    $documentTitle
                ) .
            '</div>
            <div class="document-number">Document ' .
                walletWithdrawalDocumentEscape(
                    $documentNumber
                ) .
            '</div>
        </div>
        <div class="brand">MangaVault</div>
        <div class="brand-subtitle">Official Wallet Record</div>
        <div class="clear"></div>
    </div>

    <div class="status-row">
        <span class="status">' .
            walletWithdrawalDocumentEscape(
                $statusLabel
            ) .
        '</span>
    </div>

    <div class="amount-panel">
        <div class="purpose">
            Purpose<br>
            <strong>Refund Credit Bank Withdrawal</strong>
        </div>
        <div class="amount-label">Withdrawal Amount</div>
        <div class="amount">RM ' .
            walletWithdrawalDocumentEscape(
                moneyFormatSen($amountSen)
            ) .
        '</div>
        <div class="clear"></div>
    </div>

    <div class="section-title">Withdrawal details</div>
    <table>
        <tr>
            <td class="label">Withdrawal request</td>
            <td class="value">#' .
                str_pad(
                    (string) (
                        $record[
                            'wallet_withdrawal_id'
                        ] ?? 0
                    ),
                    4,
                    '0',
                    STR_PAD_LEFT
                ) .
            '</td>
        </tr>
        <tr>
            <td class="label">Customer</td>
            <td class="value">' .
                walletWithdrawalDocumentEscape(
                    $customerName
                ) .
            '<br><span style="color:#6b7280;font-size:9px;font-weight:400;">' .
                walletWithdrawalDocumentEscape(
                    $record['customer_email'] ?? ''
                ) .
            '</span></td>
        </tr>
        <tr>
            <td class="label">Bank destination</td>
            <td class="value">' .
                walletWithdrawalDocumentEscape(
                    $record[
                        'wallet_withdrawal_bank_name'
                    ] ?? ''
                ) .
            ' · account ending ' .
                walletWithdrawalDocumentEscape(
                    $record[
                        'wallet_withdrawal_account_number_last4'
                    ] ?? ''
                ) .
            '</td>
        </tr>
        <tr>
            <td class="label">Account holder</td>
            <td class="value">' .
                walletWithdrawalDocumentEscape(
                    $record[
                        'wallet_withdrawal_account_holder'
                    ] ?? ''
                ) .
            '</td>
        </tr>
        <tr>
            <td class="label">Requested</td>
            <td class="value">' .
                walletWithdrawalDocumentEscape(
                    walletWithdrawalDocumentDate(
                        $record[
                            'wallet_withdrawal_created_at'
                        ] ?? ''
                    )
                ) .
            '</td>
        </tr>
        <tr>
            <td class="label">Approved</td>
            <td class="value">' .
                walletWithdrawalDocumentEscape(
                    walletWithdrawalDocumentDate(
                        $record[
                            'wallet_withdrawal_reviewed_at'
                        ] ?? ''
                    )
                ) .
            '</td>
        </tr>' .
        $transferRows .
        '<tr>
            <td class="label">Processing target</td>
            <td class="value">Up to 14 business days · estimated by ' .
                walletWithdrawalDocumentEscape(
                    $deadlineLabel
                ) .
            '</td>
        </tr>
    </table>

    <div class="notice">
        <div class="notice-title">' .
            walletWithdrawalDocumentEscape(
                $noticeTitle
            ) .
        '</div>
        <div class="notice-text">' .
            walletWithdrawalDocumentEscape(
                $noticeText
            ) .
        '</div>
    </div>

    <table class="authorization">
        <tr>
            <td class="authorization-copy" style="border:0;">
                <div class="section-title" style="margin-top:0;">
                    Administrative authorization
                </div>
                <p><strong>Authorized by:</strong> ' .
                    walletWithdrawalDocumentEscape(
                        $authorizedName
                    ) .
                '</p>
                <p><strong>Authorization date:</strong> ' .
                    walletWithdrawalDocumentEscape(
                        walletWithdrawalDocumentDate(
                            $authorizationDate
                        )
                    ) .
                '</p>
                <p><strong>Record verification ID:</strong> <span class="mono">' .
                    walletWithdrawalDocumentEscape(
                        $recordHash
                    ) .
                '</span></p>
                <p style="color:#6b7280;margin-top:8px;">
                    This PDF was generated automatically from the protected MangaVault withdrawal record.
                </p>
            </td>
            <td class="stamp-cell" style="border:0;">
                <div class="stamp">
                    MANGAVAULT<br>
                    <span class="stamp-main">AUTHORIZED</span><br>
                    OFFICIAL RECORD
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">
        MangaVault · Official wallet withdrawal document · ' .
        walletWithdrawalDocumentEscape(
            $documentNumber
        ) .
        ' · Generated ' .
        walletWithdrawalDocumentEscape(
            (new DateTimeImmutable(
                'now',
                new DateTimeZone(
                    'Asia/Kuala_Lumpur'
                )
            ))->format('d M Y, h:i A') .
            ' MYT'
        ) .
    '</div>
</body>
</html>';
}

function streamWalletWithdrawalDocument(
    array $record,
    bool $download
): never {
    if (!class_exists(\Dompdf\Dompdf::class)) {
        throw new WalletWithdrawalException(
            'PDF generation is unavailable.'
        );
    }

    $dompdf = new \Dompdf\Dompdf([
        'isRemoteEnabled' => false,
        'isHtml5ParserEnabled' => true,
    ]);
    $dompdf->loadHtml(
        buildWalletWithdrawalDocumentHtml(
            $record
        ),
        'UTF-8'
    );
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $fileName =
        walletWithdrawalDocumentNumber(
            $record
        ) .
        '.pdf';

    header(
        'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0'
    );
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');

    $dompdf->stream(
        $fileName,
        [
            'Attachment' => $download,
        ]
    );

    exit;
}
