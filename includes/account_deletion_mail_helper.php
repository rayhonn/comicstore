<?php

require_once dirname(__DIR__) .
    '/vendor/autoload.php';

require_once __DIR__ .
    '/mail_config.php';

/**
 * Send the result of an account deletion request
 * to the customer's registered email address.
 */
function sendAccountDeletionDecisionEmail(
    string $email,
    string $firstName,
    string $decision,
    ?string $adminNote = null
): bool {
    $email = trim($email);
    $firstName = trim($firstName);
    $adminNote = trim(
        (string) $adminNote
    );

    if (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        ) ||
        !in_array(
            $decision,
            [
                'approved',
                'rejected',
            ],
            true
        )
    ) {
        app_error_log(
            'Account deletion decision email data is invalid.'
        );

        return false;
    }

    $safeFirstName = htmlspecialchars(
        $firstName !== ''
            ? $firstName
            : 'Customer',
        ENT_QUOTES,
        'UTF-8'
    );

    $safeAdminNote = nl2br(
        htmlspecialchars(
            $adminNote,
            ENT_QUOTES,
            'UTF-8'
        )
    );

    if ($decision === 'approved') {
        $subject =
            'Account Deletion Approved - MangaVault';

        $heading =
            'Account Deletion Approved';

        $statusMessage =
            'Your MangaVault account deletion request has been approved. Your account is now closed and you will no longer be able to sign in.';

        $additionalMessage =
            'Your order, payment, return and other transaction history may be retained for record integrity and audit purposes. If you need to use MangaVault again, please contact an administrator about restoring your previous account.';

        $plainText =
            'Your MangaVault account deletion request has been approved. ' .
            'Your account is now closed. ' .
            'Please contact an administrator if you need your previous account restored.';
    } else {
        $subject =
            'Account Deletion Request Rejected - MangaVault';

        $heading =
            'Account Deletion Request Rejected';

        $statusMessage =
            'Your MangaVault account deletion request was not approved. Your account remains active and you can continue using MangaVault normally.';

        $additionalMessage =
            $adminNote !== ''
                ? 'Administrator reason: ' .
                    $adminNote
                : 'Please contact an administrator if you need more information.';

        $plainText =
            'Your MangaVault account deletion request was rejected. ' .
            'Your account remains active. ' .
            $additionalMessage;
    }

    $noteSection = '';

    if (
        $decision === 'rejected' &&
        $adminNote !== ''
    ) {
        $noteSection = "
            <div style='margin:22px 0;padding:16px;border-radius:12px;background:#fef2f2;border:1px solid #fecaca;'>
                <p style='margin:0 0 6px;color:#991b1b;font-size:13px;font-weight:700;'>
                    Administrator Reason
                </p>
                <p style='margin:0;color:#b91c1c;font-size:13px;line-height:1.6;'>
                    {$safeAdminNote}
                </p>
            </div>
        ";
    }

    $emailBody = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
    </head>
    <body style='margin:0;padding:0;background:#F5F0EB;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;'>
        <div style='max-width:600px;margin:30px auto;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);'>
            <div style='background:#1e2d4a;padding:32px;text-align:center;'>
                <h1 style='color:#ffffff;font-size:24px;font-weight:900;margin:0 0 4px;'>
                    Manga<span style='color:#ef4444;'>Vault</span>
                </h1>

                <p style='color:rgba(255,255,255,0.65);font-size:13px;margin:0;'>
                    Account Management
                </p>
            </div>

            <div style='padding:32px;'>
                <p style='color:#374151;font-size:15px;'>
                    Hi <strong>{$safeFirstName}</strong>,
                </p>

                <h2 style='color:#111827;font-size:20px;margin:18px 0 12px;'>
                    {$heading}
                </h2>

                <p style='color:#4b5563;font-size:14px;line-height:1.7;'>
                    {$statusMessage}
                </p>

                {$noteSection}

                <p style='color:#4b5563;font-size:14px;line-height:1.7;'>
                    {$additionalMessage}
                </p>

                <div style='margin-top:24px;padding:15px;border-radius:12px;background:#f9fafb;border:1px solid #e5e7eb;'>
                    <p style='margin:0;color:#6b7280;font-size:12px;line-height:1.6;'>
                        For account security, do not create additional accounts to reclaim new-member or welcome promotions. Please contact MangaVault administration if you require assistance.
                    </p>
                </div>
            </div>

            <div style='background:#f9fafb;padding:18px 32px;text-align:center;border-top:1px solid #f3f4f6;'>
                <p style='color:#9ca3af;font-size:12px;margin:0;'>
                    MangaVault - Account Security Notification
                </p>
            </div>
        </div>
    </body>
    </html>
    ";

    try {
        $mail =
            new \PHPMailer\PHPMailer\PHPMailer(
                true
            );

        $mail->isSMTP();

        $mail->Host =
            MAIL_HOST;

        $mail->SMTPAuth =
            true;

        $mail->Username =
            MAIL_USERNAME;

        $mail->Password =
            MAIL_PASSWORD;

        $mail->SMTPSecure =
            'tls';

        $mail->Port =
            MAIL_PORT;

        $mail->CharSet =
            'UTF-8';

        $mail->setFrom(
            MAIL_USERNAME,
            MAIL_FROM_NAME
        );

        $mail->addAddress(
            $email,
            $firstName !== ''
                ? $firstName
                : 'Customer'
        );

        $mail->Subject =
            $subject;

        $mail->isHTML(true);

        $mail->Body =
            $emailBody;

        $mail->AltBody =
            $plainText;

        $mail->send();

        return true;
    } catch (Throwable $e) {
        app_error_log(
            'Account deletion decision email failed: ' .
            $e->getMessage()
        );

        return false;
    }
}