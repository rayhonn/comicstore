<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ .
    '/../includes/identity_helper.php';

require_customer();

$user_id = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$error = '';
$success = '';

$deletionAgreementVersion =
    '2026-08-08-v1';

function profileInput(mixed $value, string $label, int $maxLength): string
{
    if (!is_string($value)) {
        throw new RuntimeException('Invalid ' . $label . '.');
    }
    $value = trim($value);
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($length > $maxLength) {
        throw new RuntimeException($label . ' cannot exceed ' . $maxLength . ' characters.');
    }
    return $value;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        try {
            $first_name = profileInput($_POST['user_first_name'] ?? '', 'First name', 100);
            $last_name = profileInput($_POST['user_last_name'] ?? '', 'Last name', 100);
            $phone = profileInput($_POST['user_phone'] ?? '', 'Phone number', 11);
            $new_dob = profileInput($_POST['user_dob'] ?? '', 'Date of birth', 10);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
            $first_name = $last_name = $phone = $new_dob = '';
        }

        if ($error !== '') {
            // Validation error already set.
        } elseif (empty($first_name)) {
            $error = "First name is required.";
        } elseif ($phone && !preg_match('/^01[0-9]{8,9}$/', $phone)) {
            $error = "Please enter a valid Malaysian phone number.";
        } else {
            $dob_to_save = $user['user_dob'];
            $dob_changed_flag = $user['user_dob_changed'];

            if (!empty($new_dob) && !$user['user_dob_changed']) {
                $dob_obj = new DateTime($new_dob);
                $today = new DateTime();
                $age = $today->diff($dob_obj)->y;
                if ($age < 13) {
                    $error = "You must be at least 13 years old.";
                } else {
                    $is_changing = !empty($user['user_dob']) && $new_dob !== $user['user_dob'];
                    $dob_to_save = $new_dob;
                    $dob_changed_flag = $is_changing ? 1 : $user['user_dob_changed'];
                }
            }

            if (empty($error)) {
                try {
                    if ($phone !== '') {
                        $phone =
                            normalizeIdentityPhone(
                                $phone
                            );
                    }

                    $oldPhone =
                        normalizeIdentityPhone(
                            (string) (
                                $user[
                                    'user_phone'
                                ] ?? ''
                            )
                        );

                    $phoneChanged =
                        $phone !==
                        $oldPhone;

                    if ($phoneChanged) {
                        assertCustomerIdentityAvailable(
                            $pdo,
                            'phone',
                            $phone,
                            $user_id
                        );
                    }

                    $pdo->beginTransaction();

                    claimCustomerIdentity(
                        $pdo,
                        $user_id,
                        'email',
                        (string) $user[
                            'user_gmail'
                        ],
                        'legacy_sync'
                    );

                    if ($phoneChanged) {
                        if (
                            $oldPhone !== ''
                        ) {
                            markCustomerIdentityHistorical(
                                $pdo,
                                $user_id,
                                'phone',
                                $oldPhone
                            );
                        }

                        if ($phone !== '') {
                            claimCustomerIdentity(
                                $pdo,
                                $user_id,
                                'phone',
                                $phone,
                                'profile_update'
                            );
                        }
                    } elseif (
                        $phone !== ''
                    ) {
                        claimCustomerIdentity(
                            $pdo,
                            $user_id,
                            'phone',
                            $phone,
                            'legacy_sync'
                        );
                    }

                    $updateProfile =
                        $pdo->prepare("
                            UPDATE users
                            SET
                                user_first_name = ?,
                                user_last_name = ?,
                                user_phone = ?,
                                user_dob = ?,
                                user_dob_changed = ?
                            WHERE user_id = ?
                            AND user_role = 'customer'
                        ");

                    $updateProfile->execute([
                        $first_name,
                        $last_name,
                        $phone,
                        $dob_to_save,
                        $dob_changed_flag,
                        $user_id,
                    ]);

                    $pdo->commit();

                    if (
                        !empty($new_dob) &&
                        !$user[
                            'user_dob_changed'
                        ] &&
                        !empty(
                            $user['user_dob']
                        ) &&
                        $new_dob !==
                            $user['user_dob']
                    ) {
                        $success =
                            'Profile updated! Date of birth has been changed and is now locked.';
                    } else {
                        $success =
                            'Profile updated successfully!';
                    }

                    $stmt =
                        $pdo->prepare("
                            SELECT *
                            FROM users
                            WHERE user_id = ?
                        ");

                    $stmt->execute([
                        $user_id,
                    ]);

                    $user =
                        $stmt->fetch(
                            PDO::FETCH_ASSOC
                        );
                } catch (
                    IdentityProtectionException $e
                ) {
                    if (
                        $pdo->inTransaction()
                    ) {
                        $pdo->rollBack();
                    }

                    $error =
                        $e->getMessage();
                } catch (Throwable $e) {
                    if (
                        $pdo->inTransaction()
                    ) {
                        $pdo->rollBack();
                    }

                    app_error_log(
                        'Customer profile identity update failed: ' .
                        $e->getMessage()
                    );

                    $error =
                        'Unable to update your profile. Please try again later.';
                }
            }
        }

    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? null;
        $new = $_POST['new_password'] ?? null;
        $confirm = $_POST['confirm_password'] ?? null;

        if (!is_string($current) || !is_string($new) || !is_string($confirm) || strlen($current) > 72 || strlen($new) > 72 || strlen($confirm) > 72) {
            $error = 'Password input is invalid or exceeds 72 characters.';
        } elseif (!password_verify($current, $user['user_password_hash'])) {
            $error = "Current password is incorrect.";
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/', $new)) {
            $error = "New password must be at least 8 characters with uppercase, lowercase, number and symbol.";
        } elseif ($new !== $confirm) {
            $error = "New passwords do not match.";
        } else {
            $pdo->prepare("UPDATE users SET user_password_hash = ? WHERE user_id = ?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), $user_id]);
            $success = "Password changed successfully!";
        }
    } elseif (
        $action ===
        'request_account_deletion'
    ) {
        $deletePassword =
            $_POST['delete_password']
            ?? null;

        $deleteConfirmation =
            $_POST['delete_confirmation']
            ?? null;

        $agreementAccepted =
            $_POST['deletion_agreement']
            ?? null;

        try {
            $deletionReason =
                profileInput(
                    $_POST[
                        'deletion_reason'
                    ] ?? '',
                    'Deletion reason',
                    500
                );
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
            $deletionReason = '';
        }

        if (
            $error === '' &&
            (
                !is_string(
                    $deletePassword
                ) ||
                $deletePassword === '' ||
                strlen(
                    $deletePassword
                ) > 72
            )
        ) {
            $error =
                'Please enter your current password.';
        } elseif (
            $error === '' &&
            (
                !is_string(
                    $deleteConfirmation
                ) ||
                $deleteConfirmation !==
                    'DELETE'
            )
        ) {
            $error =
                'Type DELETE exactly to confirm the deletion request.';
        } elseif (
            $error === '' &&
            $agreementAccepted !== '1'
        ) {
            $error =
                'You must read and accept the Account Deletion Agreement before submitting your request.';
        }

        if ($error === '') {
            try {
                $pdo->beginTransaction();

                $accountLock =
                    $pdo->prepare("
                        SELECT
                            user_password_hash,
                            user_gmail,
                            user_phone,
                            user_is_active,
                            user_deleted_at
                        FROM users
                        WHERE user_id = ?
                        AND user_role =
                            'customer'
                        FOR UPDATE
                    ");

                $accountLock->execute([
                    $user_id,
                ]);

                $lockedAccount =
                    $accountLock->fetch(
                        PDO::FETCH_ASSOC
                    );

                if (
                    !$lockedAccount ||
                    (int) $lockedAccount[
                        'user_is_active'
                    ] !== 1 ||
                    $lockedAccount[
                        'user_deleted_at'
                    ] !== null
                ) {
                    throw new RuntimeException(
                        'Your account is not available for an account deletion request.'
                    );
                }

                if (
                    !password_verify(
                        $deletePassword,
                        $lockedAccount[
                            'user_password_hash'
                        ]
                    )
                ) {
                    throw new RuntimeException(
                        'Current password is incorrect.'
                    );
                }

                $existingRequest =
                    $pdo->prepare("
                        SELECT
                            deletion_request_id
                        FROM
                            account_deletion_requests
                        WHERE
                            deletion_request_user_id = ?
                        AND
                            deletion_request_status =
                            'pending'
                        LIMIT 1
                        FOR UPDATE
                    ");

                $existingRequest->execute([
                    $user_id,
                ]);

                if (
                    $existingRequest
                        ->fetchColumn() !== false
                ) {
                    throw new RuntimeException(
                        'You already have an account deletion request under review.'
                    );
                }

                $paymentDraftCheck =
                    $pdo->prepare("
                        SELECT
                            payment_draft_id
                        FROM payment_drafts
                        WHERE
                            payment_draft_user_id = ?
                        AND
                            payment_draft_status
                            IN (
                                'pending',
                                'checkout_open',
                                'paid'
                            )
                        LIMIT 1
                        FOR UPDATE
                    ");

                $paymentDraftCheck
                    ->execute([
                        $user_id,
                    ]);

                if (
                    $paymentDraftCheck
                        ->fetchColumn() !==
                    false
                ) {
                    throw new RuntimeException(
                        'You cannot submit an account deletion request while you have a pending or processing checkout.'
                    );
                }

                $activeOrderCheck =
                    $pdo->prepare("
                        SELECT order_id
                        FROM orders
                        WHERE
                            order_user_id = ?
                        AND order_status IN (
                            'pending',
                            'processing',
                            'shipped'
                        )
                        LIMIT 1
                        FOR UPDATE
                    ");

                $activeOrderCheck
                    ->execute([
                        $user_id,
                    ]);

                if (
                    $activeOrderCheck
                        ->fetchColumn() !==
                    false
                ) {
                    throw new RuntimeException(
                        'You cannot submit an account deletion request while you have an active order. Please wait until it is delivered or cancelled.'
                    );
                }

                $pendingReturnCheck =
                    $pdo->prepare("
                        SELECT return_id
                        FROM return_requests
                        WHERE
                            return_user_id = ?
                        AND
                            return_status =
                            'pending'
                        LIMIT 1
                        FOR UPDATE
                    ");

                $pendingReturnCheck
                    ->execute([
                        $user_id,
                    ]);

                if (
                    $pendingReturnCheck
                        ->fetchColumn() !==
                    false
                ) {
                    throw new RuntimeException(
                        'You cannot submit an account deletion request while you have a pending return request.'
                    );
                }

                $insertRequest =
                    $pdo->prepare("
                        INSERT INTO
                            account_deletion_requests (
                                deletion_request_user_id,
                                deletion_request_status,
                                deletion_request_email_snapshot,
                                deletion_request_phone_snapshot,
                                deletion_request_reason,
                                deletion_request_agreement_version,
                                deletion_request_agreement_accepted_at,
                                deletion_request_open_key
                            )
                        VALUES (
                            ?,
                            'pending',
                            ?,
                            ?,
                            ?,
                            ?,
                            NOW(),
                            1
                        )
                    ");

                $phoneSnapshot = trim(
                    (string) (
                        $lockedAccount[
                            'user_phone'
                        ] ?? ''
                    )
                );

                $insertRequest->execute([
                    $user_id,
                    (string) $lockedAccount[
                        'user_gmail'
                    ],
                    $phoneSnapshot !== ''
                        ? $phoneSnapshot
                        : null,
                    $deletionReason !== ''
                        ? $deletionReason
                        : null,
                    $deletionAgreementVersion,
                ]);

                $pdo->commit();

                redirect_to(
                    app_path(
                        'customer/profile.php'
                    ) .
                    '?deletion=requested'
                );
            } catch (
                RuntimeException $e
            ) {
                if (
                    $pdo->inTransaction()
                ) {
                    $pdo->rollBack();
                }

                $error =
                    $e->getMessage();
            } catch (Throwable $e) {
                if (
                    $pdo->inTransaction()
                ) {
                    $pdo->rollBack();
                }

                app_error_log(
                    'Account deletion request submission failed: ' .
                    $e->getMessage()
                );

                $error =
                    'Unable to submit your account deletion request. Please try again later.';
            }
        }
    } elseif (
        $action ===
        'cancel_account_deletion'
    ) {
        $requestId = filter_var(
            $_POST[
                'deletion_request_id'
            ] ?? null,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                ],
            ]
        );

        if (
            $requestId === false ||
            $requestId === null
        ) {
            $error =
                'Invalid account deletion request.';
        } else {
            try {
                $pdo->beginTransaction();

                $requestLock =
                    $pdo->prepare("
                        SELECT
                            deletion_request_status
                        FROM
                            account_deletion_requests
                        WHERE
                            deletion_request_id = ?
                        AND
                            deletion_request_user_id = ?
                        FOR UPDATE
                    ");

                $requestLock->execute([
                    (int) $requestId,
                    $user_id,
                ]);

                $request =
                    $requestLock->fetch(
                        PDO::FETCH_ASSOC
                    );

                if (
                    !$request ||
                    $request[
                        'deletion_request_status'
                    ] !== 'pending'
                ) {
                    throw new RuntimeException(
                        'This deletion request can no longer be cancelled.'
                    );
                }

                $cancelRequest =
                    $pdo->prepare("
                        UPDATE
                            account_deletion_requests
                        SET
                            deletion_request_status =
                                'cancelled',
                            deletion_request_cancelled_at =
                                NOW(),
                            deletion_request_open_key =
                                NULL
                        WHERE
                            deletion_request_id = ?
                        AND
                            deletion_request_user_id = ?
                        AND
                            deletion_request_status =
                                'pending'
                    ");

                $cancelRequest->execute([
                    (int) $requestId,
                    $user_id,
                ]);

                if (
                    $cancelRequest
                        ->rowCount() !== 1
                ) {
                    throw new RuntimeException(
                        'Unable to cancel the deletion request.'
                    );
                }

                $pdo->commit();

                redirect_to(
                    app_path(
                        'customer/profile.php'
                    ) .
                    '?deletion=cancelled'
                );
            } catch (
                RuntimeException $e
            ) {
                if (
                    $pdo->inTransaction()
                ) {
                    $pdo->rollBack();
                }

                $error =
                    $e->getMessage();
            } catch (Throwable $e) {
                if (
                    $pdo->inTransaction()
                ) {
                    $pdo->rollBack();
                }

                app_error_log(
                    'Account deletion request cancellation failed: ' .
                    $e->getMessage()
                );

                $error =
                    'Unable to cancel the account deletion request. Please try again later.';
            }
        }
    }
}

$deletionRequestStatement =
    $pdo->prepare("
        SELECT
            deletion_request_id,
            deletion_request_status,
            deletion_request_reason,
            deletion_request_requested_at,
            deletion_request_cancelled_at,
            deletion_request_processed_at,
            deletion_request_admin_note
        FROM
            account_deletion_requests
        WHERE
            deletion_request_user_id = ?
        ORDER BY
            deletion_request_id DESC
        LIMIT 1
    ");

$deletionRequestStatement
    ->execute([
        $user_id,
    ]);

$deletionRequest =
    $deletionRequestStatement
        ->fetch(
            PDO::FETCH_ASSOC
        );

$pendingDeletionRequest =
    $deletionRequest &&
    $deletionRequest[
        'deletion_request_status'
    ] === 'pending';

$deletionResult =
    $_GET['deletion'] ?? null;

if (
    is_string($deletionResult) &&
    $deletionResult === 'requested'
) {
    $success =
        'Your account deletion request has been submitted successfully. Please allow 3–5 working days for review.';
} elseif (
    is_string($deletionResult) &&
    $deletionResult === 'cancelled'
) {
    $success =
        'Your account deletion request has been cancelled.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html { scroll-behavior: smooth; }
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">
        <p class="text-sm text-gray-400 mb-6">
            <a href="../index.php" class="hover:text-red-600 transition-colors">Home</a>
            <span class="mx-2">›</span>
            <a href="dashboard.php" class="hover:text-red-600 transition-colors">My Account</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">My Profile</span>
        </p>

        <div class="flex gap-8 items-start">
            <?php include '../includes/customer_sidebar.php'; ?>

            <div class="flex-1 min-w-0">

                <?php if ($error): ?>
                    <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-5"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="bg-green-50 border border-green-200 text-green-600 text-sm px-4 py-3 rounded-xl mb-5"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                    <!-- Personal Info -->
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h3 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
                            <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                            Personal Information
                        </h3>
                        <form method="POST" class="space-y-4">
                            <?php csrf_field(); ?>
<input type="hidden" name="action" value="update_profile">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">First Name *</label>
                                    <input type="text" name="user_first_name" maxlength="100" required
                                           value="<?= htmlspecialchars($user['user_first_name'] ?? '') ?>"
                                           class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Last Name</label>
                                    <input type="text" name="user_last_name" maxlength="100"
                                           value="<?= htmlspecialchars($user['user_last_name'] ?? '') ?>"
                                           class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Username</label>
                                <input type="text" value="<?= htmlspecialchars($user['user_name']) ?>" disabled
                                       class="w-full px-3 py-2.5 border border-gray-100 rounded-lg text-sm bg-gray-50 text-gray-400 cursor-not-allowed">
                                <p class="text-xs text-gray-400 mt-1">Username cannot be changed.</p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Email</label>
                                <input type="text" value="<?= htmlspecialchars($user['user_gmail']) ?>" disabled
                                       class="w-full px-3 py-2.5 border border-gray-100 rounded-lg text-sm bg-gray-50 text-gray-400 cursor-not-allowed">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Phone</label>
                                <input type="text" name="user_phone"
                                       value="<?= htmlspecialchars($user['user_phone'] ?? '') ?>"
                                       oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                                       maxlength="11"
                                       class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors"
                                       placeholder="e.g. 01234567890">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">
                                    Date of Birth
                                    <?php if ($user['user_dob_changed']): ?>
                                        <span class="text-red-400 font-normal">🔒 Locked</span>
                                    <?php else: ?>
                                        <span class="text-yellow-500 font-normal">(can only be changed once)</span>
                                    <?php endif; ?>
                                </label>
                                <?php if ($user['user_dob_changed']): ?>
                                    <input type="text"
                                           value="<?= !empty($user['user_dob']) ? date('d F Y', strtotime($user['user_dob'])) : 'Not set' ?>"
                                           disabled
                                           class="w-full px-3 py-2.5 border border-gray-100 rounded-lg text-sm bg-gray-50 text-gray-400 cursor-not-allowed">
                                <?php else: ?>
                                    <input type="date" name="user_dob"
                                           value="<?= htmlspecialchars($user['user_dob'] ?? '') ?>"
                                           max="<?= date('Y-m-d', strtotime('-13 years')) ?>"
                                           class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors">
                                    <?php if (!empty($user['user_dob'])): ?>
                                        <p class="text-xs text-yellow-600 mt-1">⚠️ Changing this will lock it permanently.</p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <button type="submit"
                                    <?php if (!empty($user['user_dob']) && !$user['user_dob_changed']): ?>
                                    onclick="return checkDobChange(this.form)"
                                    <?php endif; ?>
                                    class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-2.5 rounded-lg text-sm transition-colors duration-200">
                                Update Profile
                            </button>
                        </form>
                    </div>

                    <!-- Change Password -->
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h3 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
                            <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                            Change Password
                        </h3>
                        <form method="POST" class="space-y-4">
                            <?php csrf_field(); ?>
<input type="hidden" name="action" value="change_password">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Current Password *</label>
                                <input type="password" name="current_password" maxlength="72" required
                                       class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">New Password *</label>
                                <input type="password" name="new_password" id="newPassInput" maxlength="72" required
                                       class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors"
                                       placeholder="Min 8 chars, upper, lower, number, symbol">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Confirm New Password *</label>
                                <input type="password" name="confirm_password" id="confirmPassInput" maxlength="72" required
                                       class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors">
                                <p id="passMatchMsg" class="text-xs mt-1 hidden"></p>
                            </div>
                            <button type="submit"
                                    class="w-full bg-[#1e2d4a] hover:bg-[#162338] text-white font-semibold py-2.5 rounded-lg text-sm transition-colors duration-200">
                                Change Password
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Address Book shortcut -->
                <div class="bg-white rounded-2xl shadow-sm p-6 mt-6">
                    <div class="flex items-center justify-between">
                        <h3 class="font-bold text-gray-800 flex items-center gap-2">
                            <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                            Address Book
                        </h3>
                        <a href="addresses.php"
                           class="bg-red-600 hover:bg-red-700 text-white font-semibold px-4 py-2 rounded-xl text-sm transition-colors flex items-center gap-2">
                            📍 Manage Addresses
                        </a>
                    </div>
                    <p class="text-sm text-gray-400 mt-2">Add, edit or remove your delivery addresses from the Addresses page.</p>
                </div>

                <!-- Account Deletion Request -->
                <div
                    class="bg-white rounded-2xl shadow-sm p-6 mt-6 border border-red-200"
                >
                    <div
                        class="flex items-start justify-between gap-6 flex-wrap"
                    >
                        <div>
                            <h3
                                class="font-bold text-red-700 text-lg"
                            >
                                Account Deletion Request
                            </h3>

                            <p
                                class="text-sm text-gray-500 mt-1 max-w-3xl leading-relaxed"
                            >
                                Account deletion is subject to administrator review.
                                Your account will remain active while the request is
                                being reviewed. Please allow 3–5 working days for
                                processing.
                            </p>
                        </div>

                        <?php if (
                            $pendingDeletionRequest
                        ): ?>
                        <span
                            class="bg-yellow-100 text-yellow-700 text-xs font-semibold px-3 py-1 rounded-full"
                        >
                            Pending Review
                        </span>
                        <?php else: ?>
                        <span
                            class="bg-red-50 text-red-600 text-xs font-semibold px-3 py-1 rounded-full"
                        >
                            Account Deletion
                        </span>
                        <?php endif; ?>
                    </div>

                    <?php if (
                        $pendingDeletionRequest
                    ): ?>

                    <div
                        class="border-t border-red-100 mt-5 pt-5"
                    >
                        <div
                            class="bg-yellow-50 border border-yellow-200 rounded-xl p-5"
                        >
                            <div
                                class="flex items-start gap-3"
                            >
                                <div
                                    class="w-10 h-10 rounded-full bg-yellow-100 flex items-center justify-center flex-shrink-0"
                                >
                                    ⏳
                                </div>

                                <div>
                                    <p
                                        class="font-bold text-yellow-800"
                                    >
                                        Your request is under review
                                    </p>

                                    <p
                                        class="text-sm text-yellow-700 mt-1 leading-relaxed"
                                    >
                                        MangaVault administrators will review your
                                        account deletion request within 3–5 working
                                        days. Your account remains active until the
                                        request is approved or rejected. The final
                                        decision will be sent to your registered
                                        email address.
                                    </p>

                                    <p
                                        class="text-xs text-yellow-600 mt-3"
                                    >
                                        Submitted:
                                        <?= htmlspecialchars(
                                            date(
                                                'd M Y, h:i A',
                                                strtotime(
                                                    $deletionRequest[
                                                        'deletion_request_requested_at'
                                                    ]
                                                )
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div
                            class="mt-4 flex items-center justify-between gap-4 flex-wrap"
                        >
                            <p
                                class="text-xs text-gray-400"
                            >
                                You may cancel this request before an administrator processes it.
                            </p>

                            <form
                                method="POST"
                                onsubmit="return confirm('Cancel your account deletion request?')"
                            >
                                <?php csrf_field(); ?>

                                <input
                                    type="hidden"
                                    name="action"
                                    value="cancel_account_deletion"
                                >

                                <input
                                    type="hidden"
                                    name="deletion_request_id"
                                    value="<?= (int) $deletionRequest['deletion_request_id'] ?>"
                                >

                                <button
                                    type="submit"
                                    class="border border-gray-300 hover:bg-gray-50 text-gray-600 font-semibold px-4 py-2 rounded-lg text-sm transition-colors"
                                >
                                    Cancel Request
                                </button>
                            </form>
                        </div>
                    </div>

                    <?php else: ?>

                    <?php if (
                        $deletionRequest &&
                        $deletionRequest[
                            'deletion_request_status'
                        ] === 'rejected'
                    ): ?>
                    <div
                        class="mt-5 bg-red-50 border border-red-200 rounded-xl p-4"
                    >
                        <p
                            class="text-sm font-semibold text-red-700"
                        >
                            Previous request rejected
                        </p>

                        <?php if (
                            !empty(
                                $deletionRequest[
                                    'deletion_request_admin_note'
                                ]
                            )
                        ): ?>
                        <p
                            class="text-sm text-red-600 mt-1"
                        >
                            <?= htmlspecialchars(
                                $deletionRequest[
                                    'deletion_request_admin_note'
                                ],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div
                        class="border-t border-red-100 mt-5 pt-5"
                    >
                        <div
                            class="bg-gray-50 border border-gray-200 rounded-xl p-5 mb-5"
                        >
                            <p
                                class="font-bold text-gray-800 mb-3"
                            >
                                Account Deletion Agreement
                            </p>

                            <ul
                                class="text-sm text-gray-600 space-y-2 leading-relaxed"
                            >
                                <li>
                                    • My account will remain active while the request is under review.
                                </li>

                                <li>
                                    • I understand that the review normally takes 3–5 working days.
                                </li>

                                <li>
                                    • I must not have a pending checkout, active order or pending return request.
                                </li>

                                <li>
                                    • Order, payment, return and other transaction history may be retained for record integrity and audit purposes.
                                </li>

                                <li>
                                    • Deleting my account does not reset my eligibility for new-member or welcome promotions.
                                </li>

                                <li>
                                    • Account identifiers, including email address and phone number, may be retained in account history to prevent duplicate accounts and repeated new-member reward claims.
                                </li>

                                <li>
                                    • If I want to use MangaVault again after deletion, I may be required to contact an administrator to restore my previous account instead of creating another new-member account.
                                </li>
                            </ul>
                        </div>

                        <form
                            method="POST"
                            id="accountDeletionRequestForm"
                            class="space-y-5"
                        >
                            <?php csrf_field(); ?>

                            <input
                                type="hidden"
                                name="action"
                                value="request_account_deletion"
                            >

                            <div>
                                <label
                                    class="block text-xs font-medium text-gray-500 mb-1"
                                >
                                    Reason for Leaving
                                    <span
                                        class="text-gray-400 font-normal"
                                    >
                                        (Optional)
                                    </span>
                                </label>

                                <textarea
                                    name="deletion_reason"
                                    maxlength="500"
                                    rows="3"
                                    placeholder="Tell us why you would like to delete your account..."
                                    class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 resize-none"
                                ></textarea>

                                <p
                                    class="text-xs text-gray-400 mt-1"
                                >
                                    Maximum 500 characters.
                                </p>
                            </div>

                            <div
                                class="grid grid-cols-1 md:grid-cols-2 gap-4"
                            >
                                <div>
                                    <label
                                        class="block text-xs font-medium text-gray-500 mb-1"
                                    >
                                        Current Password *
                                    </label>

                                    <input
                                        type="password"
                                        name="delete_password"
                                        id="deleteAccountPassword"
                                        maxlength="72"
                                        autocomplete="current-password"
                                        required
                                        class="w-full px-3 py-2.5 border border-red-200 rounded-lg text-sm focus:outline-none focus:border-red-500"
                                    >
                                </div>

                                <div>
                                    <label
                                        class="block text-xs font-medium text-gray-500 mb-1"
                                    >
                                        Type DELETE to Confirm *
                                    </label>

                                    <input
                                        type="text"
                                        name="delete_confirmation"
                                        id="deleteAccountConfirmation"
                                        maxlength="6"
                                        autocomplete="off"
                                        placeholder="DELETE"
                                        required
                                        class="w-full px-3 py-2.5 border border-red-200 rounded-lg text-sm focus:outline-none focus:border-red-500"
                                    >
                                </div>
                            </div>

                            <label
                                class="flex items-start gap-3 bg-red-50 border border-red-100 rounded-xl p-4 cursor-pointer"
                            >
                                <input
                                    type="checkbox"
                                    name="deletion_agreement"
                                    id="deletionAgreementCheckbox"
                                    value="1"
                                    required
                                    class="mt-1 w-4 h-4 accent-red-600"
                                >

                                <span
                                    class="text-sm text-gray-600 leading-relaxed"
                                >
                                    I have read and agree to the Account Deletion
                                    Agreement above, including the retention of
                                    account history and identifiers for transaction
                                    integrity and prevention of repeated new-member
                                    reward claims.
                                </span>
                            </label>

                            <div>
                                <button
                                    type="submit"
                                    id="submitDeletionRequestButton"
                                    disabled
                                    class="bg-red-600 hover:bg-red-700 disabled:bg-gray-300 disabled:cursor-not-allowed text-white font-semibold px-5 py-2.5 rounded-lg text-sm transition-colors"
                                >
                                    Submit Account Deletion Request
                                </button>
                            </div>
                        </form>
                    </div>

                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/password-toggle.js" defer></script>

    <script>
    const newPassInput = document.getElementById('newPassInput');
    const confirmPassInput = document.getElementById('confirmPassInput');
    const passMatchMsg = document.getElementById('passMatchMsg');

    function checkPassMatch() {
        if (confirmPassInput.value === '') { passMatchMsg.classList.add('hidden'); return; }
        if (newPassInput.value === confirmPassInput.value) {
            passMatchMsg.textContent = '✓ Passwords match';
            passMatchMsg.className = 'text-xs mt-1 text-green-600';
        } else {
            passMatchMsg.textContent = '✗ Passwords do not match';
            passMatchMsg.className = 'text-xs mt-1 text-red-500';
        }
        passMatchMsg.classList.remove('hidden');
    }

    newPassInput.addEventListener('input', checkPassMatch);
    confirmPassInput.addEventListener('input', checkPassMatch);

    function checkDobChange(form) {
        const dobInput = form.querySelector('[name="user_dob"]');
        const originalDob = '<?= $user['user_dob'] ?? '' ?>';
        if (dobInput && dobInput.value && dobInput.value !== originalDob) {
            return confirm('Changing your date of birth will lock it permanently and cannot be undone. Are you sure?');
        }
        return true;
    }

    const deletionPassword =
        document.getElementById(
            'deleteAccountPassword'
        );

    const deletionConfirmation =
        document.getElementById(
            'deleteAccountConfirmation'
        );

    const deletionAgreement =
        document.getElementById(
            'deletionAgreementCheckbox'
        );

    const deletionSubmitButton =
        document.getElementById(
            'submitDeletionRequestButton'
        );

    function updateDeletionRequestButton() {
        if (
            !deletionPassword ||
            !deletionConfirmation ||
            !deletionAgreement ||
            !deletionSubmitButton
        ) {
            return;
        }

        deletionSubmitButton.disabled = !(
            deletionPassword.value.length > 0 &&
            deletionConfirmation.value ===
                'DELETE' &&
            deletionAgreement.checked
        );
    }

    [
        deletionPassword,
        deletionConfirmation,
        deletionAgreement,
    ].forEach((element) => {
        if (!element) {
            return;
        }

        element.addEventListener(
            'input',
            updateDeletionRequestButton
        );

        element.addEventListener(
            'change',
            updateDeletionRequestButton
        );
    });

    updateDeletionRequestButton();
    </script>

</body>
</html>