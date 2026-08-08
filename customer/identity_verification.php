<?php

require_once __DIR__ .
    '/../includes/db.php';

require_once __DIR__ .
    '/../includes/auth.php';

require_once __DIR__ .
    '/../includes/csrf.php';

require_once __DIR__ .
    '/../includes/identity_verification_helper.php';

require_customer();

$userId =
    (int) $_SESSION[
        'user_id'
    ];

$error = '';
$success = '';
$phoneValue = '';

$consentVersion =
    '2026-08-08-v1';

$currentRequestStatement =
    $pdo->prepare("
        SELECT
            verification_request_id,
            verification_request_phone_snapshot,
            verification_request_status,
            verification_request_reason,
            verification_request_requested_at,
            verification_request_reviewed_at,
            verification_request_admin_note
        FROM
            identity_verification_requests
        WHERE
            verification_request_user_id = ?
        ORDER BY
            verification_request_id DESC
        LIMIT 1
    ");

$currentRequestStatement
    ->execute([
        $userId,
    ]);

$currentRequest =
    $currentRequestStatement
        ->fetch(
            PDO::FETCH_ASSOC
        );

if (
    $_SERVER[
        'REQUEST_METHOD'
    ] === 'POST'
) {
    csrf_verify();

    $action =
        $_POST['action'] ?? null;

    if (
        !is_string($action) ||
        $action !==
            'submit_verification'
    ) {
        $error =
            'Invalid identity verification request.';
    } elseif (
        $currentRequest &&
        $currentRequest[
            'verification_request_status'
        ] === 'pending'
    ) {
        $error =
            'You already have an identity verification request under review.';
    } else {
        $identityEvidence = null;
        $phoneEvidence = null;

        try {
            $phoneRaw =
                $_POST[
                    'phone'
                ] ?? '';

            $nricRaw =
                $_POST[
                    'nric'
                ] ?? '';

            $reasonRaw =
                $_POST[
                    'reason'
                ] ?? '';

            $consentAccepted =
                $_POST[
                    'verification_consent'
                ] ?? null;

            if (
                !is_string($phoneRaw) ||
                !is_string($nricRaw) ||
                !is_string($reasonRaw)
            ) {
                throw new RuntimeException(
                    'Invalid verification request.'
                );
            }

            $phoneValue =
                normalizeIdentityPhone(
                    $phoneRaw
                );

            if ($phoneValue === '') {
                throw new RuntimeException(
                    'Phone number is required.'
                );
            }

            $reason =
                trim($reasonRaw);

            $reasonLength =
                function_exists(
                    'mb_strlen'
                )
                    ? mb_strlen(
                        $reason,
                        'UTF-8'
                    )
                    : strlen(
                        $reason
                    );

            if ($reason === '') {
                throw new RuntimeException(
                    'Please explain why you are requesting phone ownership verification.'
                );
            }

            if (
                $reasonLength > 500
            ) {
                throw new RuntimeException(
                    'Verification reason cannot exceed 500 characters.'
                );
            }

            if (
                $consentAccepted !== '1'
            ) {
                throw new RuntimeException(
                    'You must accept the identity verification consent before submitting.'
                );
            }

            $normalizedNric =
                normalizeMalaysianNric(
                    $nricRaw
                );

            $nricFingerprint =
                identityNricFingerprint(
                    $normalizedNric
                );

            $phoneFingerprint =
                customerIdentityFingerprint(
                    'phone',
                    $phoneValue
                );

            $conflict =
                findCustomerPhoneIdentity(
                    $pdo,
                    $phoneValue
                );

            if (!$conflict) {
                throw new RuntimeException(
                    'No previous MangaVault phone ownership conflict was found. Please try adding this phone number directly in My Profile.'
                );
            }

            if (
                (int) $conflict[
                    'identity_user_id'
                ] === $userId
            ) {
                throw new RuntimeException(
                    'This phone number belongs to your own MangaVault identity history. Please update it directly from My Profile.'
                );
            }

            if (
                $conflict[
                    'identity_status'
                ] === 'released' ||
                $conflict[
                    'identity_claim_key'
                ] === null
            ) {
                throw new RuntimeException(
                    'This phone number has already been released and does not require ownership verification. Please try adding it in My Profile.'
                );
            }

            if (
                $conflict[
                    'identity_status'
                ] !== 'historical' ||
                (int) $conflict[
                    'identity_claim_key'
                ] !== 1
            ) {
                throw new RuntimeException(
                    'This phone number currently has an active MangaVault ownership claim and cannot be reassigned through this verification process.'
                );
            }

            $identityEvidence =
                storeIdentityVerificationEvidence(
                    $_FILES[
                        'identity_document'
                    ] ?? [],
                    'identity',
                    'MyKad identity image'
                );

            try {
                $phoneEvidence =
                    storeIdentityVerificationEvidence(
                        $_FILES[
                            'phone_ownership_evidence'
                        ] ?? [],
                        'phone',
                        'Phone ownership evidence'
                    );
            } catch (Throwable $e) {
                deleteIdentityVerificationEvidence(
                    (string) $identityEvidence[
                        'file_name'
                    ]
                );

                $identityEvidence = null;

                throw $e;
            }

            $pdo->beginTransaction();

            try {
                /*
                 * Revalidate and lock the historical
                 * identity immediately before creating
                 * the review request.
                 */
                $conflictLock =
                    $pdo->prepare("
                        SELECT
                            identity_history_id,
                            identity_user_id,
                            identity_status,
                            identity_claim_key
                        FROM
                            customer_identity_history
                        WHERE
                            identity_type =
                                'phone'
                        AND
                            identity_fingerprint = ?
                        AND
                            identity_claim_key = 1
                        LIMIT 1
                        FOR UPDATE
                    ");

                $conflictLock
                    ->execute([
                        $phoneFingerprint,
                    ]);

                $lockedConflict =
                    $conflictLock
                        ->fetch(
                            PDO::FETCH_ASSOC
                        );

                if (
                    !$lockedConflict ||
                    $lockedConflict[
                        'identity_status'
                    ] !== 'historical' ||
                    (int) $lockedConflict[
                        'identity_user_id'
                    ] === $userId
                ) {
                    throw new RuntimeException(
                        'The phone ownership conflict changed while your request was being submitted. Please try again.'
                    );
                }

                $existingOpenRequest =
                    $pdo->prepare("
                        SELECT
                            verification_request_id
                        FROM
                            identity_verification_requests
                        WHERE
                            verification_request_open_key = 1
                        AND (
                            verification_request_user_id = ?
                            OR
                            verification_request_phone_fingerprint = ?
                        )
                        LIMIT 1
                        FOR UPDATE
                    ");

                $existingOpenRequest
                    ->execute([
                        $userId,
                        $phoneFingerprint,
                    ]);

                if (
                    $existingOpenRequest
                        ->fetchColumn() !==
                        false
                ) {
                    throw new RuntimeException(
                        'An ownership verification request is already pending for this customer or phone number.'
                    );
                }

                $insertRequest =
                    $pdo->prepare("
                        INSERT INTO
                            identity_verification_requests (
                                verification_request_user_id,
                                verification_request_phone_snapshot,
                                verification_request_phone_fingerprint,
                                verification_request_nric_fingerprint,
                                verification_request_status,
                                verification_request_reason,
                                verification_request_identity_file,
                                verification_request_identity_file_sha256,
                                verification_request_phone_evidence_file,
                                verification_request_phone_evidence_sha256,
                                verification_request_consent_version,
                                verification_request_consent_accepted_at,
                                verification_request_open_key
                            )
                        VALUES (
                            ?,
                            ?,
                            ?,
                            ?,
                            'pending',
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            NOW(),
                            1
                        )
                    ");

                $insertRequest
                    ->execute([
                        $userId,
                        $phoneValue,
                        $phoneFingerprint,
                        $nricFingerprint,
                        $reason,
                        $identityEvidence[
                            'file_name'
                        ],
                        $identityEvidence[
                            'sha256'
                        ],
                        $phoneEvidence[
                            'file_name'
                        ],
                        $phoneEvidence[
                            'sha256'
                        ],
                        $consentVersion,
                    ]);

                $pdo->commit();

                $success =
                    'Your phone ownership verification request has been submitted successfully. A Super Admin will review your identity and phone ownership evidence.';

                $currentRequestStatement
                    ->execute([
                        $userId,
                    ]);

                $currentRequest =
                    $currentRequestStatement
                        ->fetch(
                            PDO::FETCH_ASSOC
                        );
            } catch (Throwable $e) {
                if (
                    $pdo->inTransaction()
                ) {
                    $pdo->rollBack();
                }

                throw $e;
            }
        } catch (Throwable $e) {
            if (
                $identityEvidence
            ) {
                deleteIdentityVerificationEvidence(
                    (string) $identityEvidence[
                        'file_name'
                    ]
                );
            }

            if (
                $phoneEvidence
            ) {
                deleteIdentityVerificationEvidence(
                    (string) $phoneEvidence[
                        'file_name'
                    ]
                );
            }

            if (
                $e instanceof
                IdentityProtectionException ||
                $e instanceof
                RuntimeException
            ) {
                $error =
                    $e->getMessage();
            } else {
                app_error_log(
                    'Identity verification request failed: ' .
                    $e->getMessage()
                );

                $error =
                    'Unable to submit your identity verification request. Please try again later.';
            }
        }
    }
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
        Phone Ownership Verification - MangaVault
    </title>

    <script
        src="https://cdn.tailwindcss.com"
    ></script>
</head>

<body class="bg-[#F5F0EB] min-h-screen">

    <?php
    include __DIR__ .
        '/../includes/customer_navbar.php';
    ?>

    <main
        class="max-w-3xl mx-auto px-6 py-8"
    >
        <div class="mb-6">
            <a
                href="profile.php"
                class="text-sm text-gray-400 hover:text-red-600"
            >
                ← Back to My Profile
            </a>

            <h1
                class="text-2xl font-black text-gray-800 mt-4"
            >
                Phone Ownership Verification
            </h1>

            <p
                class="text-sm text-gray-500 mt-2"
            >
                Use this review only when your mobile provider reassigned a phone number that was previously linked to another MangaVault account.
            </p>
        </div>

        <?php if ($error !== ''): ?>
        <div
            class="mb-5 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl text-sm"
        >
            <?= htmlspecialchars(
                $error,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
        <div
            class="mb-5 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl text-sm"
        >
            <?= htmlspecialchars(
                $success,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>
        <?php endif; ?>

        <?php if (
            $currentRequest &&
            $currentRequest[
                'verification_request_status'
            ] === 'pending'
        ): ?>

        <section
            class="bg-white rounded-2xl shadow-sm p-6"
        >
            <div
                class="flex items-center justify-between gap-4"
            >
                <div>
                    <p
                        class="text-xs uppercase tracking-wide text-gray-400 font-bold"
                    >
                        Current Request
                    </p>

                    <h2
                        class="font-black text-gray-800 mt-1"
                    >
                        Ownership Review Pending
                    </h2>
                </div>

                <span
                    class="bg-amber-100 text-amber-700 text-xs font-bold px-3 py-1 rounded-full"
                >
                    Pending
                </span>
            </div>

            <div
                class="mt-5 bg-gray-50 rounded-xl p-4"
            >
                <p
                    class="text-xs text-gray-400"
                >
                    Phone
                </p>

                <p
                    class="font-bold text-gray-800 mt-1"
                >
                    <?= htmlspecialchars(
                        (string) $currentRequest[
                            'verification_request_phone_snapshot'
                        ],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>

                <p
                    class="text-xs text-gray-400 mt-4"
                >
                    Submitted
                </p>

                <p
                    class="text-sm text-gray-600 mt-1"
                >
                    <?= htmlspecialchars(
                        (string) $currentRequest[
                            'verification_request_requested_at'
                        ],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>
            </div>

            <div
                class="mt-5 bg-blue-50 border border-blue-100 rounded-xl px-4 py-3 text-sm text-blue-700"
            >
                Your evidence is stored privately while the request is under review. Only an authorised Super Admin will be allowed to review it.
            </div>
        </section>

        <?php else: ?>

        <form
            method="POST"
            enctype="multipart/form-data"
            class="bg-white rounded-2xl shadow-sm p-6 space-y-5"
        >
            <?php csrf_field(); ?>

            <input
                type="hidden"
                name="action"
                value="submit_verification"
            >

            <div>
                <label
                    class="block text-sm font-semibold text-gray-700 mb-1"
                >
                    Reassigned Phone Number *
                </label>

                <input
                    type="text"
                    name="phone"
                    maxlength="11"
                    inputmode="numeric"
                    required
                    value="<?= htmlspecialchars(
                        $phoneValue,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                    placeholder="e.g. 01234567890"
                    class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400"
                >
            </div>

            <div>
                <label
                    class="block text-sm font-semibold text-gray-700 mb-1"
                >
                    MyKad / NRIC Number *
                </label>

                <input
                    type="text"
                    name="nric"
                    maxlength="14"
                    required
                    autocomplete="off"
                    placeholder="e.g. 010101011234"
                    class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400"
                >

                <p
                    class="text-xs text-gray-400 mt-1"
                >
                    Your full NRIC number is used only to create a protected identity fingerprint and is not stored as plaintext.
                </p>
            </div>

            <div>
                <label
                    class="block text-sm font-semibold text-gray-700 mb-1"
                >
                    MyKad Identity Image *
                </label>

                <input
                    type="file"
                    name="identity_document"
                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    required
                    class="w-full text-sm text-gray-600"
                >

                <p
                    class="text-xs text-gray-400 mt-1"
                >
                    JPG, JPEG, PNG, or WEBP only. Maximum 5MB.
                </p>
            </div>

            <div>
                <label
                    class="block text-sm font-semibold text-gray-700 mb-1"
                >
                    Phone Ownership Evidence *
                </label>

                <input
                    type="file"
                    name="phone_ownership_evidence"
                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    required
                    class="w-full text-sm text-gray-600"
                >

                <p
                    class="text-xs text-gray-400 mt-1"
                >
                    Upload a mobile provider bill, account screenshot, or other evidence showing that this phone number is currently assigned to you.
                </p>
            </div>

            <div>
                <label
                    class="block text-sm font-semibold text-gray-700 mb-1"
                >
                    Reason *
                </label>

                <textarea
                    name="reason"
                    maxlength="500"
                    rows="4"
                    required
                    placeholder="Explain how this phone number was reassigned to you."
                    class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400"
                ></textarea>
            </div>

            <div
                class="bg-amber-50 border border-amber-100 rounded-xl p-4"
            >
                <p
                    class="text-sm font-bold text-amber-800"
                >
                    Identity Verification Consent
                </p>

                <p
                    class="text-xs text-amber-700 mt-2 leading-5"
                >
                    Your identity document and phone ownership evidence will be used only for this ownership review. The documents are stored outside the public web directory and will be removed after the Super Admin completes the review. The system retains only necessary audit information and protected fingerprints.
                </p>

                <label
                    class="flex items-start gap-3 mt-3 text-sm text-gray-700"
                >
                    <input
                        type="checkbox"
                        name="verification_consent"
                        value="1"
                        required
                        class="mt-1"
                    >

                    <span>
                        I confirm that the submitted documents belong to me and I consent to their temporary use for identity and phone ownership verification.
                    </span>
                </label>
            </div>

            <button
                type="submit"
                class="w-full bg-red-600 hover:bg-red-700 text-white py-3 rounded-xl text-sm font-bold"
                onclick="return confirm('Submit these sensitive identity documents for Super Admin review?')"
            >
                Submit Ownership Verification
            </button>
        </form>

        <?php endif; ?>
    </main>

</body>
</html>