<?php

require_once __DIR__ . '/money_helper.php';
require_once __DIR__ . '/wallet_withdrawal_lifecycle_helper.php';

function loadWalletWithdrawalDocumentRecord(
    PDO $pdo,
    int $withdrawalId,
    ?int $customerId = null
): array {
    assertWalletWithdrawalLifecycleSchema($pdo);
    if ($withdrawalId < 1) {
        throw new RuntimeException('Invalid withdrawal document.');
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
            settler.bank_gateway_operator_display_name AS bank_settlement_operator
        FROM wallet_withdrawal_requests wr
        INNER JOIN users customer
            ON customer.user_id = wr.wallet_withdrawal_user_id
        LEFT JOIN users reviewer
            ON reviewer.user_id = wr.wallet_withdrawal_reviewed_by
        LEFT JOIN bank_gateway_operators decision_operator
            ON decision_operator.bank_gateway_operator_id = wr.wallet_withdrawal_bank_decided_by
        LEFT JOIN bank_gateway_operators settler
            ON settler.bank_gateway_operator_id = wr.wallet_withdrawal_bank_settled_by
        WHERE wr.wallet_withdrawal_id = ?
    ";
    $params = [$withdrawalId];
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
        throw new RuntimeException('Official withdrawal document was not found.');
    }
    return $record;
}

function walletWithdrawalDocumentFilename(array $record): string
{
    $status = preg_replace(
        '/[^a-z0-9_-]/',
        '',
        strtolower((string) ($record['wallet_withdrawal_status'] ?? 'record'))
    ) ?: 'record';
    return 'mangavault-withdrawal-' .
        str_pad((string) ($record['wallet_withdrawal_id'] ?? 0), 4, '0', STR_PAD_LEFT) .
        '-' . $status . '.pdf';
}

function walletWithdrawalDocumentDescriptor(array $record): array
{
    [$stageKey, $stageLabel, $stageCopy] = walletWithdrawalCustomerStage($record);
    $status = (string) ($record['wallet_withdrawal_status'] ?? '');
    $bank = (string) ($record['wallet_withdrawal_bank_status'] ?? 'not_submitted');
    $settlement = (string) ($record['wallet_withdrawal_bank_settlement_status'] ?? 'not_required');

    if ($status === 'completed' && $settlement === 'settled') {
        return ['Bank Transfer Confirmation', 'SETTLED', '#1f6a46', '#f1f8f4', $stageCopy];
    }
    if ($status === 'failed' && $bank === 'rejected') {
        return ['Bank Withdrawal Outcome Record', 'BANK REJECTED · FUNDS RELEASED', '#9f2f36', '#fff4f4', $stageCopy];
    }
    if ($status === 'failed') {
        return ['Bank Withdrawal Outcome Record', 'SETTLEMENT FAILED · FUNDS RELEASED', '#9f5a22', '#fff8f1', $stageCopy];
    }
    if ($status === 'rejected') {
        return ['Bank Withdrawal Outcome Record', 'REJECTED BY MANGAVAULT · FUNDS RELEASED', '#9f2f36', '#fff4f4', $stageCopy];
    }
    if ($status === 'approved' && $bank === 'approved') {
        return ['Bank Withdrawal Authorization Record', 'BANK ACCEPTED · AWAITING FINAL SETTLEMENT', '#245c83', '#f2f7fb', $stageCopy];
    }
    if ($status === 'approved') {
        return ['Bank Withdrawal Authorization Record', 'MANGAVAULT APPROVED · BANK PROCESSING', '#245c83', '#f2f7fb', $stageCopy];
    }
    return ['Bank Withdrawal Request Record', 'REQUEST RECEIVED', '#475569', '#f8fafc', $stageCopy];
}

function buildWalletWithdrawalDocumentHtml(array $record): string
{
    [$documentTitle, $outcomeLabel, $accent, $soft, $stageCopy] =
        walletWithdrawalDocumentDescriptor($record);

    $id = (int) $record['wallet_withdrawal_id'];
    $customerName = trim(
        (string) $record['user_first_name'] . ' ' .
        (string) $record['user_last_name']
    );
    $reviewerName = trim(
        (string) ($record['reviewer_first_name'] ?? '') . ' ' .
        (string) ($record['reviewer_last_name'] ?? '')
    );
    $amount = moneyFormatSen(moneyDecimalToSen((string) $record['wallet_withdrawal_amount']));
    $requestTime = walletWithdrawalLifecycleEventMyt($record, 'wallet_withdrawal_created_at');
    $reviewTime = walletWithdrawalLifecycleEventMyt($record, 'wallet_withdrawal_reviewed_at');
    $bankSubmitted = walletWithdrawalLifecycleEventMyt($record, 'wallet_withdrawal_bank_submitted_at');
    $bankDecision = walletWithdrawalLifecycleEventMyt($record, 'wallet_withdrawal_bank_decided_at');
    $settlementStarted = walletWithdrawalLifecycleEventMyt($record, 'wallet_withdrawal_bank_settlement_started_at');
    $settledAt = walletWithdrawalLifecycleEventMyt($record, 'wallet_withdrawal_bank_settled_at');
    $failedAt = walletWithdrawalLifecycleEventMyt($record, 'wallet_withdrawal_failed_at');
    $retry = walletWithdrawalRetryDeadlineLabel($record);
    $adminNote = trim((string) ($record['wallet_withdrawal_admin_note'] ?? ''));
    $bankNote = trim((string) ($record['wallet_withdrawal_bank_decision_note'] ?? ''));
    $failureReason = trim((string) ($record['wallet_withdrawal_failure_reason'] ?? ''));
    $bankStatus = (string) ($record['wallet_withdrawal_bank_status'] ?? 'not_submitted');
    $settlementStatus = (string) ($record['wallet_withdrawal_bank_settlement_status'] ?? 'not_required');
    $status = (string) $record['wallet_withdrawal_status'];
    if ($status === 'completed') {
        $finalTimeLabel = $settledAt;
        $finalTimeName = 'Final settlement';
    } elseif ($status === 'failed') {
        $finalTimeLabel = $failedAt;
        $finalTimeName = 'Final outcome';
    } elseif ($status === 'rejected') {
        $finalTimeLabel = $reviewTime;
        $finalTimeName = 'Final outcome';
    } else {
        $finalTimeLabel = 'Not final';
        $finalTimeName = 'Final outcome';
    }

    $notes = [];
    if ($adminNote !== '') {
        $notes[] = '<strong>MangaVault note:</strong> ' . nl2br(htmlspecialchars($adminNote, ENT_QUOTES, 'UTF-8'));
    }
    if ($bankNote !== '') {
        $notes[] = '<strong>Bank decision note:</strong> ' . nl2br(htmlspecialchars($bankNote, ENT_QUOTES, 'UTF-8'));
    }
    if ($failureReason !== '') {
        $notes[] = '<strong>Failure reason:</strong> ' . nl2br(htmlspecialchars($failureReason, ENT_QUOTES, 'UTF-8'));
    }
    $notesHtml = $notes !== []
        ? '<div class="note">' . implode('<br><br>', $notes) . '</div>'
        : '';
    $retryRow = $retry !== ''
        ? '<tr><th>Correction retry deadline</th><td>' . htmlspecialchars($retry, ENT_QUOTES, 'UTF-8') . ' MYT (UTC+8)</td></tr>'
        : '';

    $documentNo = 'MV-WD-' . str_pad((string) $id, 8, '0', STR_PAD_LEFT) . '-' . strtoupper(substr($status ?: 'R', 0, 1));

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
      @page{margin:28px 34px}body{font-family:DejaVu Sans,Arial,sans-serif;color:#1f2937;font-size:10px;line-height:1.45;margin:0}.head{border-bottom:3px solid #17243d;padding-bottom:15px;margin-bottom:18px}.brand{font-size:21px;font-weight:bold;color:#17243d}.brand span{color:#dc2626}.meta{float:right;text-align:right;margin-top:-35px;color:#64748b;font-size:8.5px}.clear{clear:both}.outcome{border:1px solid ' . $accent . ';background:' . $soft . ';padding:14px 16px;margin-bottom:17px}.outcome h1{margin:0;font-size:17px;color:' . $accent . '}.outcome strong{display:block;margin-top:5px;color:' . $accent . ';letter-spacing:.4px}h2{font-size:11px;color:#17243d;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #d8dee6;padding-bottom:5px;margin:16px 0 7px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee6;padding:7px 8px;vertical-align:top}th{width:31%;background:#f4f6f8;text-align:left;color:#536273}.mono{font-family:DejaVu Sans Mono,monospace;font-size:8.5px;word-break:break-all}.note{margin-top:13px;border-left:3px solid ' . $accent . ';background:' . $soft . ';padding:10px 12px;color:#374151}.notice{margin-top:15px;border:1px solid #d8dee6;background:#f8fafc;padding:10px 12px;color:#475569}.footer{margin-top:19px;border-top:1px solid #d8dee6;padding-top:8px;font-size:8px;color:#7b8794}
    </style></head><body>
      <div class="head"><div class="brand">Manga<span>Vault</span></div><div style="color:#64748b;font-size:9px;letter-spacing:.6px;margin-top:3px;">WALLET OPERATIONS · OFFICIAL SYSTEM RECORD</div><div class="meta"><strong>Document</strong> ' . htmlspecialchars($documentNo, ENT_QUOTES, 'UTF-8') . '<br>Generated ' . htmlspecialchars((new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur')))->format('d M Y, h:i A'), ENT_QUOTES, 'UTF-8') . ' MYT</div><div class="clear"></div></div>
      <div class="outcome"><h1>' . htmlspecialchars($documentTitle, ENT_QUOTES, 'UTF-8') . '</h1><strong>' . htmlspecialchars($outcomeLabel, ENT_QUOTES, 'UTF-8') . '</strong><div style="margin-top:7px;color:#475569;">' . htmlspecialchars($stageCopy, ENT_QUOTES, 'UTF-8') . '</div></div>
      <h2>Withdrawal Details</h2><table>
        <tr><th>Withdrawal</th><td>#' . str_pad((string) $id, 4, '0', STR_PAD_LEFT) . '</td></tr>
        <tr><th>Customer</th><td>' . htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars((string) $record['user_gmail'], ENT_QUOTES, 'UTF-8') . '</td></tr>
        <tr><th>Amount</th><td>RM ' . htmlspecialchars($amount, ENT_QUOTES, 'UTF-8') . '</td></tr>
        <tr><th>Destination</th><td>' . htmlspecialchars((string) $record['wallet_withdrawal_bank_name'], ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars((string) $record['wallet_withdrawal_account_holder'], ENT_QUOTES, 'UTF-8') . ' · ••••' . htmlspecialchars((string) $record['wallet_withdrawal_account_number_last4'], ENT_QUOTES, 'UTF-8') . '</td></tr>
        <tr><th>Current status</th><td>' . htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') . '</td></tr>
        ' . $retryRow . '
      </table>
      <h2>Lifecycle · Malaysia Time</h2><table>
        <tr><th>Request submitted</th><td>' . htmlspecialchars($requestTime, ENT_QUOTES, 'UTF-8') . ' MYT (UTC+8)</td></tr>
        <tr><th>Admin review</th><td>' . htmlspecialchars($reviewTime, ENT_QUOTES, 'UTF-8') . ($reviewTime !== 'Not available' ? ' MYT (UTC+8)' : '') . ($reviewerName !== '' ? ' · ' . htmlspecialchars($reviewerName, ENT_QUOTES, 'UTF-8') : '') . '</td></tr>
        <tr><th>Submitted to bank</th><td>' . htmlspecialchars($bankSubmitted, ENT_QUOTES, 'UTF-8') . ($bankSubmitted !== 'Not available' ? ' MYT (UTC+8)' : '') . '</td></tr>
        <tr><th>Bank decision</th><td>' . htmlspecialchars($bankDecision, ENT_QUOTES, 'UTF-8') . ($bankDecision !== 'Not available' ? ' MYT (UTC+8)' : '') . ' · ' . htmlspecialchars(ucwords(str_replace('_', ' ', $bankStatus)), ENT_QUOTES, 'UTF-8') . '</td></tr>
        <tr><th>Settlement started</th><td>' . htmlspecialchars($settlementStarted, ENT_QUOTES, 'UTF-8') . ($settlementStarted !== 'Not available' ? ' MYT (UTC+8)' : '') . ' · ' . htmlspecialchars(ucwords(str_replace('_', ' ', $settlementStatus)), ENT_QUOTES, 'UTF-8') . '</td></tr>
        <tr><th>' . htmlspecialchars($finalTimeName, ENT_QUOTES, 'UTF-8') . '</th><td>' . htmlspecialchars($finalTimeLabel, ENT_QUOTES, 'UTF-8') . ($finalTimeLabel !== 'Not final' ? ' MYT (UTC+8)' : '') . '</td></tr>
      </table>
      <h2>References</h2><table>
        <tr><th>Bank submission ID</th><td class="mono">' . htmlspecialchars((string) ($record['wallet_withdrawal_bank_submission_id'] ?? 'Not available'), ENT_QUOTES, 'UTF-8') . '</td></tr>
        <tr><th>Bank decision reference</th><td class="mono">' . htmlspecialchars((string) ($record['wallet_withdrawal_bank_decision_reference'] ?? 'Not available'), ENT_QUOTES, 'UTF-8') . '</td></tr>
        <tr><th>Settlement reference</th><td class="mono">' . htmlspecialchars((string) ($record['wallet_withdrawal_transfer_reference'] ?? 'Not available'), ENT_QUOTES, 'UTF-8') . '</td></tr>
      </table>' . $notesHtml . '
      <div class="notice"><strong>System record.</strong> Wallet funds remain reserved during an active bank workflow. A successful settlement permanently debits the reserved amount. A bank rejection or settlement failure releases the reserve automatically; when applicable, the failed amount receives a limited correction retry window.</div>
      <div class="footer">MangaVault · Final Year Project · Times shown in Malaysia Time (MYT / UTC+8)</div>
    </body></html>';
}

function renderWalletWithdrawalDocumentPdf(array $record): string
{
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml(buildWalletWithdrawalDocumentHtml($record), 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

function streamWalletWithdrawalDocument(array $record, bool $download = false): never
{
    $pdf = renderWalletWithdrawalDocumentPdf($record);
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdf));
    header(
        'Content-Disposition: ' . ($download ? 'attachment' : 'inline') .
        '; filename="' . walletWithdrawalDocumentFilename($record) . '"'
    );
    echo $pdf;
    exit;
}
