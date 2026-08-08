<?php

require_once __DIR__ .
    '/../includes/db.php';

require_once __DIR__ .
    '/../includes/auth.php';

require_once __DIR__ .
    '/../includes/csrf.php';

require_once __DIR__ .
    '/../includes/identity_helper.php';

require_senior_admin();

$error = '';
$success = '';
$searchPhone = '';
$identity = null;

if (
    $_SERVER['REQUEST_METHOD'] ===
    'POST'
) {
    csrf_verify();

    $action =
        $_POST['action'] ?? null;

    if (!is_string($action)) {
        $error =
            'Invalid phone ownership review.';
    } elseif (
        $action === 'search_phone'
    ) {
        $phoneRaw =
            $_POST['phone'] ?? '';

        if (!is_string($phoneRaw)) {
            $error =
                'Invalid phone number.';
        } else {
            try {
                $searchPhone =
                    normalizeIdentityPhone(
                        $phoneRaw
                    );

                if ($searchPhone === '') {
                    throw new IdentityProtectionException(
                        'Please enter a phone number.'
                    );
                }

                $identity =
                    findCustomerPhoneIdentity(
                        $pdo,
                        $searchPhone
                    );

                if (!$identity) {
                    $error =
                        'No MangaVault identity history was found for this phone number.';
                }
            } catch (
                IdentityProtectionException $e
            ) {
                $error =
                    $e->getMessage();
            } catch (Throwable $e) {
                app_error_log(
                    'Phone identity search failed: ' .
                    $e->getMessage()
                );

                $error =
                    'Unable to search phone identity history.';
            }
        }
    } elseif (
        $action === 'release_phone'
    ) {
        $identityHistoryId =
            filter_var(
                $_POST[
                    'identity_history_id'
                ] ?? null,
                FILTER_VALIDATE_INT,
                [
                    'options' => [
                        'min_range' => 1,
                    ],
                ]
            );

        $phoneRaw =
            $_POST['phone'] ?? null;

        $reasonRaw =
            $_POST[
                'release_reason'
            ] ?? null;

        $ownershipVerified =
            $_POST[
                'ownership_verified'
            ] ?? null;

        if (
            $identityHistoryId === false ||
            $identityHistoryId === null ||
            !is_string($phoneRaw) ||
            !is_string($reasonRaw)
        ) {
            $error =
                'Invalid phone ownership release request.';
        } elseif (
            $ownershipVerified !== '1'
        ) {
            $error =
                'You must confirm that phone ownership was verified before releasing the historical claim.';
        } else {
            try {
                $searchPhone =
                    normalizeIdentityPhone(
                        $phoneRaw
                    );

                releaseHistoricalPhoneIdentity(
                    $pdo,
                    (int) $identityHistoryId,
                    $searchPhone,
                    (int) $_SESSION[
                        'user_id'
                    ],
                    $reasonRaw
                );

                $success =
                    'Historical phone ownership was released successfully. The verified new owner can now use this phone number.';

                $identity =
                    findCustomerPhoneIdentity(
                        $pdo,
                        $searchPhone
                    );
            } catch (
                IdentityProtectionException $e
            ) {
                $error =
                    $e->getMessage();

                try {
                    $searchPhone =
                        normalizeIdentityPhone(
                            $phoneRaw
                        );

                    $identity =
                        findCustomerPhoneIdentity(
                            $pdo,
                            $searchPhone
                        );
                } catch (Throwable $ignored) {
                    $identity = null;
                }
            } catch (Throwable $e) {
                app_error_log(
                    'Historical phone ownership release failed: ' .
                    $e->getMessage()
                );

                $error =
                    'Unable to release historical phone ownership.';
            }
        }
    } else {
        $error =
            'Invalid phone ownership review action.';
    }
}

function identityStatusClass(
    string $status
): string {
    return match ($status) {
        'active' =>
            'bg-green-100 text-green-700',

        'historical' =>
            'bg-amber-100 text-amber-700',

        'released' =>
            'bg-blue-100 text-blue-700',

        default =>
            'bg-gray-100 text-gray-700',
    };
}

function customerAccountStatus(
    array $identity
): string {
    if (
        !empty(
            $identity[
                'user_deleted_at'
            ]
        )
    ) {
        return 'Closed';
    }

    return
        (int) $identity[
            'user_is_active'
        ] === 1
            ? 'Active'
            : 'Inactive';
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
        Phone Ownership Review - MangaVault Admin
    </title>

    <script
        src="https://cdn.tailwindcss.com"
    ></script>
</head>

<body class="bg-gray-100 min-h-screen">

    <?php
    include __DIR__ .
        '/../includes/admin_navbar.php';
    ?>

    <main
        class="max-w-5xl mx-auto px-6 py-8"
    >
        <div class="mb-6">
            <p
                class="text-xs uppercase tracking-[0.18em] text-red-500 font-bold"
            >
                Super Admin Security
            </p>

            <h1
                class="text-2xl font-black text-gray-800 mt-1"
            >
                Phone Ownership Review
            </h1>

            <p
                class="text-sm text-gray-500 mt-2 max-w-3xl"
            >
                Review a phone number that may have been reassigned by a mobile provider.
                Historical ownership must only be released after the new owner's claim has been manually verified.
            </p>
        </div>

        <?php if ($error !== ''): ?>
        <div
            class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
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
            class="mb-5 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"
        >
            <?= htmlspecialchars(
                $success,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>
        <?php endif; ?>

        <section
            class="bg-white rounded-2xl shadow-sm p-6"
        >
            <h2
                class="font-bold text-gray-800"
            >
                Search Phone Identity
            </h2>

            <p
                class="text-xs text-gray-400 mt-1"
            >
                Enter the exact Malaysian mobile number provided by the customer.
            </p>

            <form
                method="POST"
                class="mt-5 flex flex-col sm:flex-row gap-3"
            >
                <?php csrf_field(); ?>

                <input
                    type="hidden"
                    name="action"
                    value="search_phone"
                >

                <input
                    type="text"
                    name="phone"
                    maxlength="11"
                    inputmode="numeric"
                    value="<?= htmlspecialchars(
                        $searchPhone,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                    placeholder="e.g. 01234567890"
                    class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400"
                    required
                >

                <button
                    type="submit"
                    class="px-5 py-2.5 rounded-xl bg-[#17243d] text-white text-sm font-semibold hover:bg-[#223454]"
                >
                    Search
                </button>
            </form>
        </section>

        <?php if ($identity): ?>
        <section
            class="bg-white rounded-2xl shadow-sm p-6 mt-6"
        >
            <div
                class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3"
            >
                <div>
                    <p
                        class="text-xs uppercase tracking-wide text-gray-400 font-semibold"
                    >
                        Identity Record
                    </p>

                    <h2
                        class="font-black text-gray-800 text-lg mt-1"
                    >
                        <?= htmlspecialchars(
                            $searchPhone,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </h2>
                </div>

                <span
                    class="<?= identityStatusClass(
                        (string) $identity[
                            'identity_status'
                        ]
                    ) ?> px-3 py-1 rounded-full text-xs font-bold uppercase"
                >
                    <?= htmlspecialchars(
                        (string) $identity[
                            'identity_status'
                        ],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </span>
            </div>

            <div
                class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6"
            >
                <div
                    class="rounded-xl bg-gray-50 p-4"
                >
                    <p
                        class="text-xs text-gray-400"
                    >
                        Previous / Current Account
                    </p>

                    <p
                        class="font-bold text-gray-800 mt-1"
                    >
                        <?= htmlspecialchars(
                            trim(
                                (
                                    $identity[
                                        'user_first_name'
                                    ] ?? ''
                                ) .
                                ' ' .
                                (
                                    $identity[
                                        'user_last_name'
                                    ] ?? ''
                                )
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>

                    <p
                        class="text-sm text-gray-500"
                    >
                        @<?= htmlspecialchars(
                            (string) $identity[
                                'user_name'
                            ],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>

                    <p
                        class="text-xs text-gray-400 mt-2"
                    >
                        <?= htmlspecialchars(
                            (string) $identity[
                                'user_gmail'
                            ],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>
                </div>

                <div
                    class="rounded-xl bg-gray-50 p-4"
                >
                    <p
                        class="text-xs text-gray-400"
                    >
                        Account Status
                    </p>

                    <p
                        class="font-bold text-gray-800 mt-1"
                    >
                        <?= htmlspecialchars(
                            customerAccountStatus(
                                $identity
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>

                    <p
                        class="text-xs text-gray-400 mt-3"
                    >
                        Identity source:
                        <?= htmlspecialchars(
                            (string) $identity[
                                'identity_source'
                            ],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>
                </div>
            </div>

            <?php if (
                $identity[
                    'identity_status'
                ] === 'historical' &&
                (int) $identity[
                    'identity_claim_key'
                ] === 1
            ): ?>

            <div
                class="mt-6 border border-amber-200 bg-amber-50 rounded-xl p-5"
            >
                <h3
                    class="font-bold text-amber-900"
                >
                    Release Historical Phone Ownership
                </h3>

                <p
                    class="text-sm text-amber-700 mt-1"
                >
                    Use this only after verifying that the mobile provider reassigned this number to a different person.
                </p>

                <form
                    method="POST"
                    class="mt-4 space-y-4"
                    onsubmit="return confirm('Release this historical phone ownership? This action will allow a verified new owner to claim the number.')"
                >
                    <?php csrf_field(); ?>

                    <input
                        type="hidden"
                        name="action"
                        value="release_phone"
                    >

                    <input
                        type="hidden"
                        name="identity_history_id"
                        value="<?= (int) $identity[
                            'identity_history_id'
                        ] ?>"
                    >

                    <input
                        type="hidden"
                        name="phone"
                        value="<?= htmlspecialchars(
                            $searchPhone,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >

                    <div>
                        <label
                            class="block text-sm font-semibold text-gray-700 mb-1"
                        >
                            Verification / Release Reason *
                        </label>

                        <textarea
                            name="release_reason"
                            maxlength="500"
                            rows="4"
                            required
                            placeholder="Example: Mobile number ownership was manually verified as reassigned by the mobile provider to a new owner."
                            class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400"
                        ></textarea>
                    </div>

                    <label
                        class="flex items-start gap-3 text-sm text-gray-700"
                    >
                        <input
                            type="checkbox"
                            name="ownership_verified"
                            value="1"
                            class="mt-1"
                            required
                        >

                        <span>
                            I confirm that the new owner's claim to this phone number has been verified before releasing the previous MangaVault ownership record.
                        </span>
                    </label>

                    <button
                        type="submit"
                        class="bg-red-600 hover:bg-red-700 text-white px-5 py-2.5 rounded-xl text-sm font-bold"
                    >
                        Release Historical Phone
                    </button>
                </form>
            </div>

            <?php elseif (
                $identity[
                    'identity_status'
                ] === 'active'
            ): ?>

            <div
                class="mt-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
            >
                This phone number currently has an active MangaVault identity claim and cannot be reassigned through historical ownership review.
            </div>

            <?php else: ?>

            <div
                class="mt-6 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700"
            >
                This historical identity has already been released. The number may be claimed by a verified new owner if it is not currently assigned to another customer.
            </div>

            <?php endif; ?>

            <?php if (
                !empty(
                    $identity[
                        'identity_released_at'
                    ]
                )
            ): ?>

            <div
                class="mt-4 text-xs text-gray-400"
            >
                Released:
                <?= htmlspecialchars(
                    (string) $identity[
                        'identity_released_at'
                    ],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

                <?php if (
                    !empty(
                        $identity[
                            'identity_release_reason'
                        ]
                    )
                ): ?>
                <br>
                Reason:
                <?= htmlspecialchars(
                    (string) $identity[
                        'identity_release_reason'
                    ],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
                <?php endif; ?>
            </div>

            <?php endif; ?>
        </section>
        <?php endif; ?>
    </main>

</body>
</html>