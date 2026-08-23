<?php

require_once __DIR__ . '/money_helper.php';
require_once __DIR__ . '/bank_gateway_helper.php';

function loadBankConfirmationRecord(
    PDO $pdo,
    int $withdrawalId,
    ?string $bankCode = null
): array {
    if ($withdrawalId < 1) {
        throw new BankGatewayException(
            'Invalid bank confirmation request.'
        );
    }

    $sql = "
        SELECT
            wr.*,
            customer.user_first_name
                AS customer_first_name,
            customer.user_last_name
                AS customer_last_name,
            reviewer.user_first_name
                AS reviewer_first_name,
            reviewer.user_last_name
                AS reviewer_last_name,
            operator.bank_gateway_operator_display_name,
            operator.bank_gateway_operator_bank_code,
            operator.bank_gateway_operator_bank_name
        FROM wallet_withdrawal_requests wr
        INNER JOIN users customer
            ON customer.user_id =
                wr.wallet_withdrawal_user_id
        LEFT JOIN users reviewer
            ON reviewer.user_id =
                wr.wallet_withdrawal_reviewed_by
        LEFT JOIN bank_gateway_operators operator
            ON operator.bank_gateway_operator_id =
                wr.wallet_withdrawal_bank_decided_by
        WHERE wr.wallet_withdrawal_id = ?
        AND wr.wallet_withdrawal_bank_status = 'approved'
    ";
    $params = [$withdrawalId];

    if ($bankCode !== null) {
        $bank = normalizeWalletWithdrawalBankCode(
            $bankCode
        );
        $sql .= '
            AND wr.wallet_withdrawal_bank_code = ?
        ';
        $params[] = $bank['code'];
    }

    $sql .= ' LIMIT 1';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $record = $statement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$record) {
        throw new BankGatewayException(
            'Bank confirmation proof is not available.'
        );
    }

    return $record;
}

function bankConfirmationEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function bankConfirmationDocumentNumber(
    array $record
): string {
    return 'MV-BG-' .
        str_pad(
            (string) (
                $record[
                    'wallet_withdrawal_id'
                ] ?? 0
            ),
            6,
            '0',
            STR_PAD_LEFT
        ) .
        '-A';
}

function buildBankConfirmationHtml(array $record): string
{
    $documentNumber =
        bankConfirmationDocumentNumber($record);
    $customerName = trim(
        (string) $record['customer_first_name'] .
        ' ' .
        (string) $record['customer_last_name']
    );
    $adminName = trim(
        (string) $record['reviewer_first_name'] .
        ' ' .
        (string) $record['reviewer_last_name']
    );
    $operatorName = trim(
        (string) (
            $record[
                'bank_gateway_operator_display_name'
            ] ?? ''
        )
    );
    $bankName = (string) $record[
        'wallet_withdrawal_bank_name'
    ];
    $amountSen = moneyDecimalToSen(
        (string) $record[
            'wallet_withdrawal_amount'
        ]
    );
    $verificationHash = strtoupper(
        substr(
            (string) $record[
                'wallet_withdrawal_bank_verification_hash'
            ],
            0,
            20
        )
    );

    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 36px 40px 44px; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #1e293b;
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            line-height: 1.55;
        }
        .top {
            background: #0f2742;
            border-radius: 14px;
            color: #ffffff;
            padding: 22px 24px;
        }
        .brand {
            color: #67e8f9;
            font-size: 22px;
            font-weight: 800;
        }
        .subtitle {
            color: #cbd5e1;
            font-size: 9px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }
        .meta {
            float: right;
            text-align: right;
        }
        .meta-title {
            font-size: 15px;
            font-weight: 800;
        }
        .meta-number {
            color: #cbd5e1;
            font-size: 9px;
            margin-top: 3px;
        }
        .clear { clear: both; }
        .approved {
            background: #dcfce7;
            border: 1px solid #86efac;
            border-radius: 999px;
            color: #166534;
            display: inline-block;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: 0.8px;
            margin: 20px 0 14px;
            padding: 6px 11px;
        }
        .amount {
            background: #ecfeff;
            border: 1px solid #a5f3fc;
            border-radius: 12px;
            margin-bottom: 18px;
            padding: 16px 18px;
        }
        .amount-label {
            color: #0e7490;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .amount-value {
            color: #164e63;
            font-size: 27px;
            font-weight: 800;
        }
        .section-title {
            color: #0f172a;
            font-size: 12px;
            font-weight: 800;
            margin: 18px 0 8px;
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        td {
            border-bottom: 1px solid #e2e8f0;
            padding: 9px 10px;
            vertical-align: top;
        }
        .label {
            background: #f8fafc;
            color: #64748b;
            width: 36%;
        }
        .value {
            color: #0f172a;
            font-weight: 700;
        }
        .mono { font-family: DejaVu Sans Mono, monospace; }
        .notice {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-radius: 10px;
            color: #9a3412;
            margin-top: 18px;
            padding: 12px 14px;
        }
        .signoff {
            margin-top: 18px;
        }
        .copy {
            padding-right: 20px;
            width: 68%;
        }
        .stamp-cell {
            text-align: center;
            width: 32%;
        }
        .stamp {
            border: 4px double #0891b2;
            border-radius: 50%;
            color: #0e7490;
            display: inline-block;
            font-size: 8px;
            font-weight: 800;
            height: 105px;
            padding-top: 25px;
            text-align: center;
            transform: rotate(-6deg);
            width: 105px;
        }
        .stamp-main {
            font-size: 13px;
            letter-spacing: 0.7px;
        }
        .footer {
            bottom: -24px;
            color: #94a3b8;
            font-size: 8px;
            left: 0;
            position: fixed;
            right: 0;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="top">
        <div class="meta">
            <div class="meta-title">Bank Verification Approval</div>
            <div class="meta-number">Document ' .
                bankConfirmationEscape($documentNumber) .
            '</div>
        </div>
        <div class="brand">MangaVault Bank Gateway</div>
        <div class="subtitle">Controlled Simulation Record</div>
        <div class="clear"></div>
    </div>

    <span class="approved">BANK VERIFICATION APPROVED</span>

    <div class="amount">
        <div style="float:right;text-align:right;color:#0e7490;">
            Purpose<br><strong>Refund Withdrawal Instruction</strong>
        </div>
        <div class="amount-label">Verified Amount</div>
        <div class="amount-value">RM ' .
            bankConfirmationEscape(
                moneyFormatSen($amountSen)
            ) .
        '</div>
        <div class="clear"></div>
    </div>

    <div class="section-title">Verification record</div>
    <table>
        <tr>
            <td class="label">Withdrawal request</td>
            <td class="value">#' .
                str_pad(
                    (string) $record[
                        'wallet_withdrawal_id'
                    ],
                    4,
                    '0',
                    STR_PAD_LEFT
                ) .
            '</td>
        </tr>
        <tr>
            <td class="label">Gateway submission</td>
            <td class="value mono">' .
                bankConfirmationEscape(
                    $record[
                        'wallet_withdrawal_bank_submission_id'
                    ]
                ) .
            '</td>
        </tr>
        <tr>
            <td class="label">Customer</td>
            <td class="value">' .
                bankConfirmationEscape($customerName) .
            '</td>
        </tr>
        <tr>
            <td class="label">Destination</td>
            <td class="value">' .
                bankConfirmationEscape($bankName) .
                ' · account ending ' .
                bankConfirmationEscape(
                    $record[
                        'wallet_withdrawal_account_number_last4'
                    ]
                ) .
            '</td>
        </tr>
        <tr>
            <td class="label">MangaVault admin approval</td>
            <td class="value">' .
                bankConfirmationEscape(
                    $adminName !== ''
                        ? $adminName
                        : 'MangaVault Administrator'
                ) .
                ' · ' .
                bankConfirmationEscape(
                    walletWithdrawalMalaysiaDateTime(
                        (string) $record[
                            'wallet_withdrawal_reviewed_at'
                        ],
                        'd M Y, h:i A'
                    ) . ' MYT'
                ) .
            '</td>
        </tr>
        <tr>
            <td class="label">Bank decision reference</td>
            <td class="value mono">' .
                bankConfirmationEscape(
                    $record[
                        'wallet_withdrawal_bank_decision_reference'
                    ]
                ) .
            '</td>
        </tr>
        <tr>
            <td class="label">Bank verified at</td>
            <td class="value">' .
                bankConfirmationEscape(
                    walletWithdrawalMalaysiaDateTime(
                        (string) $record[
                            'wallet_withdrawal_bank_decided_at'
                        ],
                        'd M Y, h:i A'
                    ) . ' MYT'
                ) .
            '</td>
        </tr>
        <tr>
            <td class="label">Verification ID</td>
            <td class="value mono">' .
                bankConfirmationEscape(
                    $verificationHash
                ) .
            '</td>
        </tr>
    </table>

    <div class="notice">
        This document confirms that the simulated destination-bank verification layer accepted the transfer instruction. It does not confirm that funds have been transferred or credited. MangaVault Admin must still perform the bank transfer and upload the external receipt.
    </div>

    <table class="signoff">
        <tr>
            <td class="copy" style="border:0;">
                <div class="section-title" style="margin-top:0;">
                    Bank operator authorization
                </div>
                <p><strong>Institution:</strong> ' .
                    bankConfirmationEscape($bankName) .
                '</p>
                <p><strong>Verified by:</strong> ' .
                    bankConfirmationEscape(
                        $operatorName !== ''
                            ? $operatorName
                            : $bankName . ' Operator'
                    ) .
                '</p>
                <p><strong>Decision note:</strong> ' .
                    bankConfirmationEscape(
                        trim((string) $record[
                            'wallet_withdrawal_bank_decision_note'
                        ]) !== ''
                            ? $record[
                                'wallet_withdrawal_bank_decision_note'
                            ]
                            : 'Account and instruction verified.'
                    ) .
                '</p>
            </td>
            <td class="stamp-cell" style="border:0;">
                <div class="stamp">
                    ' . bankConfirmationEscape(
                        strtoupper($record[
                            'wallet_withdrawal_bank_code'
                        ])
                    ) . '<br>
                    <span class="stamp-main">VERIFIED</span><br>
                    MYT RECORD
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">
        MangaVault Bank Gateway Simulator · ' .
        bankConfirmationEscape($documentNumber) .
        ' · Generated ' .
        bankConfirmationEscape(
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

function streamBankConfirmationDocument(
    array $record,
    bool $download
): never {
    if (!class_exists(\Dompdf\Dompdf::class)) {
        throw new BankGatewayException(
            'Bank confirmation PDF generation is unavailable.'
        );
    }

    $dompdf = new \Dompdf\Dompdf([
        'isRemoteEnabled' => false,
        'isHtml5ParserEnabled' => true,
    ]);
    $dompdf->loadHtml(
        buildBankConfirmationHtml($record),
        'UTF-8'
    );
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    header(
        'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0'
    );
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');

    $dompdf->stream(
        bankConfirmationDocumentNumber(
            $record
        ) . '.pdf',
        [
            'Attachment' => $download,
        ]
    );

    exit;
}
