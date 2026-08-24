<?php

require_once __DIR__ . '/money_helper.php';
require_once __DIR__ . '/wallet_withdrawal_lifecycle_helper.php';

function loadBankConfirmationRecord(
    PDO $pdo,
    int $withdrawalId,
    ?string $bankCode = null,
    ?int $customerId = null
): array {
    assertWalletWithdrawalLifecycleSchema($pdo);

    if ($withdrawalId < 1) {
        throw new RuntimeException('Invalid bank decision document.');
    }

    $sql = "
        SELECT
            wr.*,
            customer.user_first_name,
            customer.user_last_name,
            customer.user_gmail,
            reviewer.user_first_name AS reviewer_first_name,
            reviewer.user_last_name AS reviewer_last_name,
            decision_operator.bank_gateway_operator_display_name AS bank_decision_operator,
            decision_operator.bank_gateway_operator_bank_name AS decision_bank_name
        FROM wallet_withdrawal_requests wr
        INNER JOIN users customer
            ON customer.user_id = wr.wallet_withdrawal_user_id
        LEFT JOIN users reviewer
            ON reviewer.user_id = wr.wallet_withdrawal_reviewed_by
        LEFT JOIN bank_gateway_operators decision_operator
            ON decision_operator.bank_gateway_operator_id = wr.wallet_withdrawal_bank_decided_by
        WHERE wr.wallet_withdrawal_id = ?
          AND wr.wallet_withdrawal_bank_status IN ('approved', 'rejected')
          AND wr.wallet_withdrawal_bank_decided_at IS NOT NULL
    ";
    $params = [$withdrawalId];

    if ($bankCode !== null) {
        $bank = normalizeWalletWithdrawalBankCode($bankCode);
        $sql .= ' AND wr.wallet_withdrawal_bank_code = ?';
        $params[] = $bank['code'];
    }
    if ($customerId !== null) {
        if ($customerId < 1) {
            throw new RuntimeException('Invalid customer document scope.');
        }
        $sql .= ' AND wr.wallet_withdrawal_user_id = ?';
        $params[] = $customerId;
    }

    $sql .= ' LIMIT 1';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $record = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        throw new RuntimeException('Bank decision PDF is not available.');
    }

    return $record;
}

function bankConfirmationDocumentNumber(array $record): string
{
    $suffix = (string) ($record['wallet_withdrawal_bank_status'] ?? '') === 'rejected'
        ? 'R'
        : 'A';
    return 'MV-BANK-' . str_pad(
        (string) ($record['wallet_withdrawal_id'] ?? 0),
        8,
        '0',
        STR_PAD_LEFT
    ) . '-' . $suffix;
}

function bankConfirmationDocumentFilename(array $record): string
{
    $decision = (string) ($record['wallet_withdrawal_bank_status'] ?? '') === 'rejected'
        ? 'rejected'
        : 'accepted';
    return 'bank-decision-' . $decision . '-withdrawal-' .
        str_pad((string) ($record['wallet_withdrawal_id'] ?? 0), 4, '0', STR_PAD_LEFT) . '.pdf';
}

function buildBankConfirmationDocumentHtml(array $record): string
{
    $isRejected = (string) $record['wallet_withdrawal_bank_status'] === 'rejected';
    $decisionLabel = $isRejected ? 'REJECTED' : 'ACCEPTED FOR SETTLEMENT';
    $decisionTitle = $isRejected
        ? 'Institution Verification Rejected'
        : 'Institution Verification Accepted';
    $accent = $isRejected ? '#9f2f36' : '#236341';
    $soft = $isRejected ? '#fff4f4' : '#f2f8f4';
    $customerName = trim(
        (string) $record['user_first_name'] . ' ' .
        (string) $record['user_last_name']
    );
    $reviewerName = trim(
        (string) ($record['reviewer_first_name'] ?? '') . ' ' .
        (string) ($record['reviewer_last_name'] ?? '')
    );
    $operatorName = trim((string) ($record['bank_decision_operator'] ?? 'Institution Operations Desk'));
    $amount = moneyFormatSen(moneyDecimalToSen((string) $record['wallet_withdrawal_amount']));
    $submitted = walletWithdrawalLifecycleEventMyt(
        $record,
        'wallet_withdrawal_bank_submitted_at'
    );
    $decided = walletWithdrawalLifecycleEventMyt(
        $record,
        'wallet_withdrawal_bank_decided_at'
    );
    $retry = walletWithdrawalRetryDeadlineLabel($record);
    $reason = trim((string) ($record['wallet_withdrawal_bank_decision_note'] ?? ''));
    $settlement = (string) ($record['wallet_withdrawal_bank_settlement_status'] ?? 'not_required');
    $walletStatus = (string) ($record['wallet_withdrawal_status'] ?? '');
    $reference = trim((string) ($record['wallet_withdrawal_bank_decision_reference'] ?? ''));
    $referenceDisplay = $reference !== ''
        ? $reference
        : 'Legacy decision record - reference not issued';
    $hash = (string) ($record['wallet_withdrawal_bank_verification_hash'] ?? '');
    $submissionId = (string) ($record['wallet_withdrawal_bank_submission_id'] ?? '');
    $documentNumber = bankConfirmationDocumentNumber($record);

    if ($isRejected) {
        $outcomeCopy = $walletStatus === 'failed'
            ? 'The destination institution rejected the beneficiary verification. The transfer instruction was not released to settlement, and MangaVault automatically released the reserved wallet amount back to the customer wallet.'
            : 'The destination institution rejected the beneficiary verification. This historical record is awaiting reconciliation so the MangaVault withdrawal state and reserved wallet amount can be synchronized.';
    } else {
        $outcomeCopy = 'The destination institution accepted the beneficiary verification and released the instruction to settlement processing. This document proves the bank verification decision only; it is not proof that the final transfer settled.';
    }

    $retryRow = $isRejected && $retry !== ''
        ? '<tr><th>Correction retry deadline</th><td>' . htmlspecialchars($retry, ENT_QUOTES, 'UTF-8') . ' MYT (UTC+8)</td></tr>'
        : '';
    $reasonBlock = $reason !== ''
        ? '<div class="note"><strong>' . ($isRejected ? 'Decision reason' : 'Decision note') . '</strong><div>' . nl2br(htmlspecialchars($reason, ENT_QUOTES, 'UTF-8')) . '</div></div>'
        : '';

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        @page{margin:28px 34px} body{font-family:DejaVu Sans,Arial,sans-serif;color:#1e293b;font-size:10px;line-height:1.45;margin:0}
        .top{border-bottom:3px solid #142d47;padding-bottom:16px;margin-bottom:18px}.brand{font-size:21px;font-weight:bold;color:#142d47}.sub{font-size:9px;color:#64748b;margin-top:3px;letter-spacing:.7px}
        .docmeta{float:right;text-align:right;margin-top:-34px;font-size:9px;color:#64748b}.decision{border:1px solid ' . $accent . ';background:' . $soft . ';padding:14px 16px;margin:14px 0 18px}.decision h1{font-size:17px;margin:0;color:' . $accent . '}.decision .status{margin-top:5px;font-weight:bold;color:' . $accent . ';letter-spacing:.5px}
        h2{font-size:11px;color:#142d47;margin:17px 0 7px;border-bottom:1px solid #d8dee6;padding-bottom:5px;text-transform:uppercase;letter-spacing:.5px}
        table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee6;padding:7px 8px;vertical-align:top}th{width:31%;background:#f4f6f8;text-align:left;color:#536273;font-weight:bold}.mono{font-family:DejaVu Sans Mono,monospace;font-size:8.5px;word-break:break-all}.note{margin-top:12px;border-left:3px solid ' . $accent . ';background:' . $soft . ';padding:10px 12px}.note strong{display:block;margin-bottom:4px;color:' . $accent . '}.notice{margin-top:16px;padding:11px 12px;background:#f8fafc;border:1px solid #d8dee6;color:#475569}.footer{margin-top:20px;padding-top:9px;border-top:1px solid #d8dee6;color:#7b8794;font-size:8px}.clear{clear:both}
    </style></head><body>
      <div class="top"><div class="brand">BankLink Settlement Services</div><div class="sub">INSTITUTION OPERATIONS PORTAL · ACADEMIC SIMULATION</div><div class="docmeta"><strong>Document</strong> ' . htmlspecialchars($documentNumber, ENT_QUOTES, 'UTF-8') . '<br>Generated ' . htmlspecialchars((new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur')))->format('d M Y, h:i A'), ENT_QUOTES, 'UTF-8') . ' MYT</div><div class="clear"></div></div>
      <div class="decision"><h1>' . htmlspecialchars($decisionTitle, ENT_QUOTES, 'UTF-8') . '</h1><div class="status">INSTITUTION DECISION: ' . htmlspecialchars($decisionLabel, ENT_QUOTES, 'UTF-8') . '</div></div>
      <p>' . htmlspecialchars($outcomeCopy, ENT_QUOTES, 'UTF-8') . '</p>
      <h2>Instruction</h2><table>
        <tr><th>Withdrawal</th><td>#' . str_pad((string) $record['wallet_withdrawal_id'], 4, '0', STR_PAD_LEFT) . '</td></tr>
        <tr><th>Amount</th><td>RM ' . htmlspecialchars($amount, ENT_QUOTES, 'UTF-8') . '</td></tr>
        <tr><th>Customer</th><td>' . htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars((string) $record['user_gmail'], ENT_QUOTES, 'UTF-8') . '</td></tr>
        <tr><th>Destination institution</th><td>' . htmlspecialchars((string) $record['wallet_withdrawal_bank_name'], ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars((string) $record['wallet_withdrawal_bank_code'], ENT_QUOTES, 'UTF-8') . ')</td></tr>
        <tr><th>Beneficiary</th><td>' . htmlspecialchars((string) $record['wallet_withdrawal_account_holder'], ENT_QUOTES, 'UTF-8') . ' · account ending ' . htmlspecialchars((string) $record['wallet_withdrawal_account_number_last4'], ENT_QUOTES, 'UTF-8') . '</td></tr>
        <tr><th>Merchant reviewer</th><td>' . htmlspecialchars($reviewerName !== '' ? $reviewerName : 'MangaVault Administration', ENT_QUOTES, 'UTF-8') . '</td></tr>
      </table>
      <h2>Institution Decision Evidence</h2><table>
        <tr><th>Submission ID</th><td class="mono">' . htmlspecialchars($submissionId, ENT_QUOTES, 'UTF-8') . '</td></tr>
        <tr><th>Submitted to bank</th><td>' . htmlspecialchars($submitted, ENT_QUOTES, 'UTF-8') . ' MYT (UTC+8)</td></tr>
        <tr><th>Decision reference</th><td class="mono">' . htmlspecialchars($referenceDisplay, ENT_QUOTES, 'UTF-8') . '</td></tr>
        <tr><th>Decision time</th><td>' . htmlspecialchars($decided, ENT_QUOTES, 'UTF-8') . ' MYT (UTC+8)</td></tr>
        <tr><th>Decision operator</th><td>' . htmlspecialchars($operatorName, ENT_QUOTES, 'UTF-8') . '</td></tr>
        <tr><th>Settlement stage</th><td>' . htmlspecialchars(ucwords(str_replace('_', ' ', $settlement)), ENT_QUOTES, 'UTF-8') . '</td></tr>
        <tr><th>MangaVault withdrawal status</th><td>' . htmlspecialchars(ucfirst($walletStatus), ENT_QUOTES, 'UTF-8') . '</td></tr>
        ' . $retryRow . '
        <tr><th>Verification integrity hash</th><td class="mono">' . htmlspecialchars($hash !== '' ? $hash : 'Not available', ENT_QUOTES, 'UTF-8') . '</td></tr>
      </table>' . $reasonBlock . '
      <div class="notice"><strong>Evidence notice.</strong> This academic simulation document records the independent institution decision. For an accepted instruction, final transfer success requires a separate settlement result. For a rejected instruction, no settlement is performed and MangaVault releases the reserved wallet amount.</div>
      <div class="footer">BankLink Settlement Services · MangaVault Final Year Project · Academic Demo · Times shown in Malaysia Time (MYT / UTC+8)</div>
    </body></html>';
}

function renderBankConfirmationDocumentPdf(array $record): string
{
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml(buildBankConfirmationDocumentHtml($record), 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

function streamBankConfirmationDocument(array $record, bool $download = false): never
{
    $pdf = renderBankConfirmationDocumentPdf($record);
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdf));
    header(
        'Content-Disposition: ' . ($download ? 'attachment' : 'inline') .
        '; filename="' . bankConfirmationDocumentFilename($record) . '"'
    );
    echo $pdf;
    exit;
}
