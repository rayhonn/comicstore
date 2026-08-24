<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/mail_config.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/wallet_withdrawal_lifecycle_helper.php';

function loadWalletWithdrawalCommunicationRecord(
    PDO $pdo,
    int $withdrawalId
): array {
    if ($withdrawalId < 1) {
        throw new WalletWithdrawalException('Invalid withdrawal communication record.');
    }

    $statement = $pdo->prepare("
        SELECT
            wr.*,
            customer.user_first_name,
            customer.user_last_name,
            customer.user_gmail
        FROM wallet_withdrawal_requests wr
        INNER JOIN users customer
            ON customer.user_id = wr.wallet_withdrawal_user_id
        WHERE wr.wallet_withdrawal_id = ?
        LIMIT 1
    ");
    $statement->execute([$withdrawalId]);
    $record = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        throw new WalletWithdrawalException('Withdrawal communication record was not found.');
    }

    return $record;
}

function walletWithdrawalCommunicationCopy(
    array $record,
    string $event
): array {
    $id = (int) ($record['wallet_withdrawal_id'] ?? 0);
    $requestNumber = '#' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    $amount = moneyFormatSen(
        moneyDecimalToSen((string) ($record['wallet_withdrawal_amount'] ?? '0'))
    );
    $bankName = trim((string) ($record['wallet_withdrawal_bank_name'] ?? 'destination bank'));
    $bankReason = trim((string) ($record['wallet_withdrawal_bank_decision_note'] ?? ''));
    $adminReason = trim((string) ($record['wallet_withdrawal_admin_note'] ?? ''));
    $failureReason = trim((string) ($record['wallet_withdrawal_failure_reason'] ?? ''));
    $settlementReference = trim((string) ($record['wallet_withdrawal_transfer_reference'] ?? ''));
    $retryDeadline = walletWithdrawalRetryDeadlineLabel($record);

    $base = [
        'title' => 'Bank Withdrawal Update',
        'message' => "Withdrawal $requestNumber for RM $amount was updated.",
        'heading' => 'Bank Withdrawal Update',
        'detail' => '',
        'tone' => 'blue',
        'attach' => null,
    ];

    return match ($event) {
        'submitted' => array_merge($base, [
            'title' => 'Bank Withdrawal Request Received',
            'message' => "Your bank withdrawal request $requestNumber for RM $amount was received. The amount is reserved while MangaVault reviews the request.",
            'heading' => 'Withdrawal Request Received',
            'detail' => 'MangaVault will review the request before it is sent to the destination bank. Reserved funds cannot be spent while the request is active.',
            'tone' => 'blue',
        ]),
        'merchant_approved' => array_merge($base, [
            'title' => 'Bank Withdrawal Approved by MangaVault',
            'message' => "Your bank withdrawal request $requestNumber for RM $amount was approved by MangaVault and submitted to $bankName for independent verification. The funds remain reserved until final settlement.",
            'heading' => 'MangaVault Approved Your Request',
            'detail' => 'The destination bank now performs its own verification. MangaVault approval is not proof that the bank transfer has settled.',
            'tone' => 'blue',
            'attach' => 'withdrawal_record',
        ]),
        'merchant_rejected' => array_merge($base, [
            'title' => 'Bank Withdrawal Rejected by MangaVault',
            'message' => "Your bank withdrawal request $requestNumber for RM $amount was rejected by MangaVault. Reserved funds were released back to your wallet." . ($adminReason !== '' ? " Reason: $adminReason" : ''),
            'heading' => 'Withdrawal Request Rejected',
            'detail' => $adminReason !== '' ? 'Reason: ' . $adminReason : 'The request did not proceed to bank verification.',
            'tone' => 'red',
            'attach' => 'withdrawal_record',
        ]),
        'bank_accepted' => array_merge($base, [
            'title' => 'Bank Verification Accepted',
            'message' => "$bankName accepted withdrawal $requestNumber for RM $amount for settlement. The amount remains reserved until the bank confirms final settlement. Your bank decision PDF is available in My Wallet.",
            'heading' => 'Bank Verification Accepted',
            'detail' => 'The instruction is now settlement-ready. Bank acceptance confirms verification only; it is not yet proof that funds were credited to your bank account.',
            'tone' => 'green',
            'attach' => 'bank_decision',
        ]),
        'bank_rejected' => array_merge($base, [
            'title' => 'Bank Verification Rejected',
            'message' => "$bankName rejected withdrawal $requestNumber for RM $amount. Reserved funds were released automatically back to your MangaVault Wallet." . ($bankReason !== '' ? " Reason: $bankReason" : '') . ($retryDeadline !== '' ? " You may retry with corrected bank details until $retryDeadline MYT." : ''),
            'heading' => 'Bank Verification Rejected',
            'detail' => ($bankReason !== '' ? 'Reason: ' . $bankReason . ' ' : '') . ($retryDeadline !== '' ? "The failed amount is eligible for a corrected bank-withdrawal retry until $retryDeadline MYT (UTC+8)." : 'The reserved amount has been released.'),
            'tone' => 'red',
            'attach' => 'bank_decision',
        ]),
        'settlement_processing' => array_merge($base, [
            'title' => 'Bank Settlement Processing',
            'message' => "$bankName started settlement processing for withdrawal $requestNumber for RM $amount. The amount remains reserved until a final settlement result is posted.",
            'heading' => 'Settlement Is Processing',
            'detail' => 'No wallet debit is final until the destination bank posts a successful settlement result.',
            'tone' => 'blue',
        ]),
        'settled' => array_merge($base, [
            'title' => 'Bank Transfer Settled Successfully',
            'message' => "Withdrawal $requestNumber for RM $amount settled successfully." . ($settlementReference !== '' ? " Settlement reference: $settlementReference." : '') . ' The reserved amount was permanently debited and the final PDF is available in My Wallet.',
            'heading' => 'Bank Transfer Settled',
            'detail' => ($settlementReference !== '' ? 'Settlement reference: ' . $settlementReference . '. ' : '') . 'This is the successful final outcome of the bank-withdrawal lifecycle.',
            'tone' => 'green',
            'attach' => 'withdrawal_record',
        ]),
        'settlement_failed' => array_merge($base, [
            'title' => 'Bank Settlement Failed',
            'message' => "Settlement for withdrawal $requestNumber for RM $amount failed. Reserved funds were released automatically back to your MangaVault Wallet." . ($failureReason !== '' ? " Reason: $failureReason" : '') . ($retryDeadline !== '' ? " You may retry until $retryDeadline MYT." : ''),
            'heading' => 'Bank Settlement Failed',
            'detail' => ($failureReason !== '' ? 'Reason: ' . $failureReason . ' ' : '') . ($retryDeadline !== '' ? "The failed amount is eligible for a corrected retry until $retryDeadline MYT (UTC+8)." : 'The reserved amount has been released.'),
            'tone' => 'red',
            'attach' => 'withdrawal_record',
        ]),
        'reconciled' => array_merge($base, [
            'title' => 'Bank Rejection Record Reconciled',
            'message' => "Historical withdrawal $requestNumber for RM $amount was synchronized with the bank rejection. Reserved funds were released back to your wallet." . ($retryDeadline !== '' ? " A retry is available until $retryDeadline MYT." : ''),
            'heading' => 'Historical Bank Rejection Reconciled',
            'detail' => 'This message confirms a legacy state mismatch was corrected. Reconciliation is not part of the normal rejection workflow.',
            'tone' => 'red',
            'attach' => 'bank_decision',
        ]),
        default => $base,
    };
}

function walletWithdrawalEventTimeLabel(array $record, string $event): string
{
    $field = match ($event) {
        'submitted' => 'wallet_withdrawal_created_at',
        'merchant_approved', 'merchant_rejected' => 'wallet_withdrawal_reviewed_at',
        'bank_accepted', 'bank_rejected', 'reconciled' => 'wallet_withdrawal_bank_decided_at',
        'settlement_processing' => 'wallet_withdrawal_bank_settlement_started_at',
        'settled' => 'wallet_withdrawal_bank_settled_at',
        'settlement_failed' => 'wallet_withdrawal_failed_at',
        default => 'wallet_withdrawal_updated_at',
    };

    return walletWithdrawalLifecycleEventMyt(
        $record,
        $field,
        'd M Y, h:i A',
        'Not available'
    );
}

function sendWalletWithdrawalLifecycleEmail(
    PDO $pdo,
    array $record,
    string $event
): bool {
    $email = trim((string) ($record['user_gmail'] ?? ''));
    $firstName = trim((string) ($record['user_first_name'] ?? 'Customer'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        app_error_log('Wallet withdrawal lifecycle email address is invalid.');
        return false;
    }

    $copy = walletWithdrawalCommunicationCopy($record, $event);
    $requestNumber = '#' . str_pad(
        (string) ($record['wallet_withdrawal_id'] ?? 0),
        4,
        '0',
        STR_PAD_LEFT
    );
    $amount = moneyFormatSen(
        moneyDecimalToSen((string) ($record['wallet_withdrawal_amount'] ?? '0'))
    );
    $eventTime = walletWithdrawalEventTimeLabel($record, $event);
    $safeName = htmlspecialchars($firstName !== '' ? $firstName : 'Customer', ENT_QUOTES, 'UTF-8');
    $safeHeading = htmlspecialchars((string) $copy['heading'], ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars((string) $copy['message'], ENT_QUOTES, 'UTF-8');
    $safeDetail = htmlspecialchars((string) $copy['detail'], ENT_QUOTES, 'UTF-8');
    $safeRequest = htmlspecialchars($requestNumber, ENT_QUOTES, 'UTF-8');
    $safeAmount = htmlspecialchars($amount, ENT_QUOTES, 'UTF-8');
    $safeEventTime = htmlspecialchars($eventTime, ENT_QUOTES, 'UTF-8');

    $tone = (string) ($copy['tone'] ?? 'blue');
    $accent = $tone === 'green' ? '#15803d' : ($tone === 'red' ? '#b91c1c' : '#1d4ed8');
    $soft = $tone === 'green' ? '#f0fdf4' : ($tone === 'red' ? '#fef2f2' : '#eff6ff');

    $body = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='margin:0;background:#f3f4f6;font-family:Segoe UI,Arial,sans-serif;color:#1f2937;'>
    <div style='max-width:620px;margin:28px auto;background:#fff;border:1px solid #e5e7eb;'>
      <div style='background:#17243d;padding:24px 28px;color:#fff;'>
        <div style='font-size:22px;font-weight:800;'>Manga<span style='color:#ef4444;'>Vault</span></div>
        <div style='margin-top:4px;color:#cbd5e1;font-size:12px;'>Wallet Bank Withdrawal Notification</div>
      </div>
      <div style='padding:28px;'>
        <p style='margin:0 0 18px;font-size:14px;'>Hi <strong>{$safeName}</strong>,</p>
        <h2 style='margin:0 0 12px;font-size:20px;color:{$accent};'>{$safeHeading}</h2>
        <p style='font-size:14px;line-height:1.7;color:#4b5563;'>{$safeMessage}</p>
        <table style='width:100%;border-collapse:collapse;margin:20px 0;font-size:13px;'>
          <tr><td style='padding:9px;border-bottom:1px solid #e5e7eb;color:#6b7280;'>Request</td><td style='padding:9px;border-bottom:1px solid #e5e7eb;font-weight:700;'>{$safeRequest}</td></tr>
          <tr><td style='padding:9px;border-bottom:1px solid #e5e7eb;color:#6b7280;'>Amount</td><td style='padding:9px;border-bottom:1px solid #e5e7eb;font-weight:700;'>RM {$safeAmount}</td></tr>
          <tr><td style='padding:9px;border-bottom:1px solid #e5e7eb;color:#6b7280;'>Event time</td><td style='padding:9px;border-bottom:1px solid #e5e7eb;font-weight:700;'>{$safeEventTime} MYT (UTC+8)</td></tr>
        </table>
        <div style='padding:14px 16px;background:{$soft};border-left:4px solid {$accent};font-size:13px;line-height:1.65;color:#374151;'>{$safeDetail}</div>
        <p style='margin:20px 0 0;font-size:12px;line-height:1.6;color:#6b7280;'>Open <strong>My Wallet</strong> in MangaVault to view the current lifecycle, official records and available PDF evidence.</p>
      </div>
      <div style='padding:16px 28px;border-top:1px solid #e5e7eb;background:#f9fafb;color:#9ca3af;font-size:11px;text-align:center;'>MangaVault · Automated Wallet Operations Notice</div>
    </div></body></html>";

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = MAIL_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_USERNAME;
        $mail->Password = MAIL_PASSWORD;
        $mail->SMTPSecure = 'tls';
        $mail->Port = MAIL_PORT;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_USERNAME, MAIL_FROM_NAME);
        $mail->addAddress($email, $firstName !== '' ? $firstName : 'Customer');
        $mail->Subject = (string) $copy['title'] . ' - MangaVault';
        $mail->isHTML(true);
        $mail->Body = $body;
        $mail->AltBody = (string) $copy['message'] . ' Event time: ' . $eventTime . ' MYT (UTC+8).';

        $attachment = $copy['attach'] ?? null;
        if ($attachment === 'bank_decision') {
            try {
                require_once __DIR__ . '/bank_confirmation_document_helper.php';
                $decisionRecord = loadBankConfirmationRecord(
                    $pdo,
                    (int) $record['wallet_withdrawal_id'],
                    null,
                    (int) $record['wallet_withdrawal_user_id']
                );
                $mail->addStringAttachment(
                    renderBankConfirmationDocumentPdf($decisionRecord),
                    bankConfirmationDocumentFilename($decisionRecord),
                    'base64',
                    'application/pdf'
                );
            } catch (Throwable $attachmentError) {
                app_error_log('Bank decision PDF email attachment failed: ' . $attachmentError->getMessage());
            }
        } elseif ($attachment === 'withdrawal_record') {
            try {
                require_once __DIR__ . '/wallet_withdrawal_document_helper.php';
                $documentRecord = loadWalletWithdrawalDocumentRecord(
                    $pdo,
                    (int) $record['wallet_withdrawal_id'],
                    (int) $record['wallet_withdrawal_user_id']
                );
                $mail->addStringAttachment(
                    renderWalletWithdrawalDocumentPdf($documentRecord),
                    walletWithdrawalDocumentFilename($documentRecord),
                    'base64',
                    'application/pdf'
                );
            } catch (Throwable $attachmentError) {
                app_error_log('Withdrawal PDF email attachment failed: ' . $attachmentError->getMessage());
            }
        }

        $mail->send();
        return true;
    } catch (Throwable $e) {
        app_error_log('Wallet withdrawal lifecycle email failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Financial state changes are committed before this function is called.
 * Notification/email failures are logged and must never roll back money.
 */
function dispatchWalletWithdrawalLifecycleCommunication(
    PDO $pdo,
    int $withdrawalId,
    string $event
): array {
    try {
        $record = loadWalletWithdrawalCommunicationRecord($pdo, $withdrawalId);
        $copy = walletWithdrawalCommunicationCopy($record, $event);

        $notificationSent = false;
        try {
            sendNotification(
                $pdo,
                (int) $record['wallet_withdrawal_user_id'],
                (string) $copy['title'],
                (string) $copy['message'],
                'system'
            );
            $notificationSent = true;
        } catch (Throwable $notificationError) {
            app_error_log('Wallet withdrawal notification failed: ' . $notificationError->getMessage());
        }

        $emailSent = sendWalletWithdrawalLifecycleEmail($pdo, $record, $event);
        return [
            'notification_sent' => $notificationSent,
            'email_sent' => $emailSent,
        ];
    } catch (Throwable $e) {
        app_error_log('Wallet withdrawal lifecycle communication failed: ' . $e->getMessage());
        return [
            'notification_sent' => false,
            'email_sent' => false,
        ];
    }
}
