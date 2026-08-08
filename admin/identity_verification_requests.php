<?php

require_once __DIR__ .
    '/../includes/db.php';

require_once __DIR__ .
    '/../includes/auth.php';

require_senior_admin();

$status =
    $_GET['status'] ?? 'pending';

if (
    !is_string($status) ||
    !in_array(
        $status,
        [
            'pending',
            'approved',
            'rejected',
            'cancelled',
            'all',
        ],
        true
    )
) {
    $status =
        'pending';
}

$requestId =
    filter_var(
        $_GET['request_id'] ?? null,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

$sql = "
    SELECT
        ivr.verification_request_id,
        ivr.verification_request_user_id,
        ivr.verification_request_phone_snapshot,
        ivr.verification_request_status,
        ivr.verification_request_reason,
        ivr.verification_request_requested_at,
        ivr.verification_request_reviewed_at,
        ivr.verification_request_admin_note,

        u.user_name,
        u.user_first_name,
        u.user_last_name,
        u.user_gmail,
        u.user_phone,
        u.user_dob,
        u.user_is_active,
        u.user_deleted_at

    FROM
        identity_verification_requests ivr

    INNER JOIN users u
        ON u.user_id =
            ivr.verification_request_user_id
";

$params = [];

if ($status !== 'all') {
    $sql .= "
        WHERE
            ivr.verification_request_status = ?
    ";

    $params[] =
        $status;
}

$sql .= "
    ORDER BY
        CASE
            WHEN
                ivr.verification_request_status =
                    'pending'
            THEN 0
            ELSE 1
        END,
        ivr.verification_request_requested_at ASC
";

$listStatement =
    $pdo->prepare(
        $sql
    );

$listStatement->execute(
    $params
);

$requests =
    $listStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

$selectedRequest = null;

if (
    $requestId !== false &&
    $requestId !== null
) {
    $detailStatement =
        $pdo->prepare("
            SELECT
                ivr.*,

                u.user_name,
                u.user_first_name,
                u.user_last_name,
                u.user_gmail,
                u.user_phone,
                u.user_dob,
                u.user_is_active,
                u.user_deleted_at

            FROM
                identity_verification_requests ivr

            INNER JOIN users u
                ON u.user_id =
                    ivr.verification_request_user_id

            WHERE
                ivr.verification_request_id = ?

            LIMIT 1
        ");

    $detailStatement->execute([
        (int) $requestId,
    ]);

    $selectedRequest =
        $detailStatement->fetch(
            PDO::FETCH_ASSOC
        ) ?: null;
}

function verificationStatusClass(
    string $status
): string {
    return match ($status) {
        'pending' =>
            'bg-amber-100 text-amber-700',

        'approved' =>
            'bg-green-100 text-green-700',

        'rejected' =>
            'bg-red-100 text-red-700',

        'cancelled' =>
            'bg-gray-100 text-gray-600',

        default =>
            'bg-gray-100 text-gray-600',
    };
}

function verificationCustomerStatus(
    array $request
): string {
    if (
        !empty(
            $request[
                'user_deleted_at'
            ]
        )
    ) {
        return 'Closed';
    }

    return
        (int) $request[
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
        Identity Verification Requests - MangaVault Admin
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
        class="max-w-7xl mx-auto px-6 py-8"
    >
        <div
            class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-6"
        >
            <div>
                <p
                    class="text-xs uppercase tracking-[0.18em] text-red-500 font-bold"
                >
                    Super Admin Security
                </p>

                <h1
                    class="text-2xl font-black text-gray-800 mt-1"
                >
                    Identity Verification Requests
                </h1>

                <p
                    class="text-sm text-gray-500 mt-2"
                >
                    Review customer identity and phone ownership evidence for reassigned mobile numbers.
                </p>
            </div>

            <div
                class="flex flex-wrap gap-2"
            >
                <?php
                foreach (
                    [
                        'pending' =>
                            'Pending',

                        'approved' =>
                            'Approved',

                        'rejected' =>
                            'Rejected',

                        'cancelled' =>
                            'Cancelled',

                        'all' =>
                            'All',
                    ] as
                    $filterValue =>
                    $filterLabel
                ):
                ?>
                <a
                    href="?status=<?= urlencode(
                        $filterValue
                    ) ?>"
                    class="<?= $status ===
                        $filterValue
                            ? 'bg-[#17243d] text-white'
                            : 'bg-white text-gray-600 border border-gray-200' ?> px-3 py-2 rounded-lg text-xs font-semibold"
                >
                    <?= htmlspecialchars(
                        $filterLabel,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div
            class="grid grid-cols-1 xl:grid-cols-[0.9fr_1.3fr] gap-6"
        >
            <section
                class="bg-white rounded-2xl shadow-sm overflow-hidden"
            >
                <div
                    class="px-5 py-4 border-b border-gray-100"
                >
                    <h2
                        class="font-bold text-gray-800"
                    >
                        Requests
                    </h2>
                </div>

                <?php if (
                    $requests === []
                ): ?>
                <div
                    class="p-10 text-center text-sm text-gray-400"
                >
                    No verification requests found.
                </div>
                <?php else: ?>

                <div
                    class="divide-y divide-gray-100"
                >
                    <?php foreach (
                        $requests as $request
                    ): ?>

                    <a
                        href="?status=<?= urlencode(
                            $status
                        ) ?>&request_id=<?= (int) $request[
                            'verification_request_id'
                        ] ?>"
                        class="block px-5 py-4 hover:bg-gray-50 transition-colors"
                    >
                        <div
                            class="flex items-start justify-between gap-3"
                        >
                            <div>
                                <p
                                    class="font-bold text-sm text-gray-800"
                                >
                                    <?= htmlspecialchars(
                                        trim(
                                            (
                                                $request[
                                                    'user_first_name'
                                                ] ?? ''
                                            ) .
                                            ' ' .
                                            (
                                                $request[
                                                    'user_last_name'
                                                ] ?? ''
                                            )
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>

                                <p
                                    class="text-xs text-gray-400 mt-0.5"
                                >
                                    @<?= htmlspecialchars(
                                        (string) $request[
                                            'user_name'
                                        ],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>
                            </div>

                            <span
                                class="<?= verificationStatusClass(
                                    (string) $request[
                                        'verification_request_status'
                                    ]
                                ) ?> px-2 py-1 rounded-full text-[10px] uppercase font-bold"
                            >
                                <?= htmlspecialchars(
                                    (string) $request[
                                        'verification_request_status'
                                    ],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>
                        </div>

                        <p
                            class="mt-3 text-sm font-semibold text-gray-700"
                        >
                            <?= htmlspecialchars(
                                (string) $request[
                                    'verification_request_phone_snapshot'
                                ],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </p>

                        <p
                            class="text-xs text-gray-400 mt-1"
                        >
                            Submitted
                            <?= htmlspecialchars(
                                (string) $request[
                                    'verification_request_requested_at'
                                ],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </p>
                    </a>

                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>

            <section
                class="bg-white rounded-2xl shadow-sm p-6"
            >
                <?php if (
                    !$selectedRequest
                ): ?>

                <div
                    class="min-h-80 flex items-center justify-center text-center"
                >
                    <div>
                        <div
                            class="text-4xl mb-3"
                        >
                            ◫
                        </div>

                        <p
                            class="font-bold text-gray-700"
                        >
                            Select a verification request
                        </p>

                        <p
                            class="text-sm text-gray-400 mt-1"
                        >
                            Customer and evidence details will appear here.
                        </p>
                    </div>
                </div>

                <?php else: ?>

                <div
                    class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3"
                >
                    <div>
                        <p
                            class="text-xs text-gray-400 uppercase font-semibold tracking-wide"
                        >
                            Request
                            #<?= (int) $selectedRequest[
                                'verification_request_id'
                            ] ?>
                        </p>

                        <h2
                            class="text-xl font-black text-gray-800 mt-1"
                        >
                            <?= htmlspecialchars(
                                (string) $selectedRequest[
                                    'verification_request_phone_snapshot'
                                ],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </h2>
                    </div>

                    <span
                        class="<?= verificationStatusClass(
                            (string) $selectedRequest[
                                'verification_request_status'
                            ]
                        ) ?> px-3 py-1 rounded-full text-xs font-bold uppercase"
                    >
                        <?= htmlspecialchars(
                            (string) $selectedRequest[
                                'verification_request_status'
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
                        class="bg-gray-50 rounded-xl p-4"
                    >
                        <p
                            class="text-xs text-gray-400"
                        >
                            Requesting Customer
                        </p>

                        <p
                            class="font-bold text-gray-800 mt-1"
                        >
                            <?= htmlspecialchars(
                                trim(
                                    (
                                        $selectedRequest[
                                            'user_first_name'
                                        ] ?? ''
                                    ) .
                                    ' ' .
                                    (
                                        $selectedRequest[
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
                                (string) $selectedRequest[
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
                                (string) $selectedRequest[
                                    'user_gmail'
                                ],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </p>
                    </div>

                    <div
                        class="bg-gray-50 rounded-xl p-4"
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
                                verificationCustomerStatus(
                                    $selectedRequest
                                ),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </p>

                        <p
                            class="text-xs text-gray-400 mt-3"
                        >
                            Date of birth:
                            <?= !empty(
                                $selectedRequest[
                                    'user_dob'
                                ]
                            )
                                ? htmlspecialchars(
                                    (string) $selectedRequest[
                                        'user_dob'
                                    ],
                                    ENT_QUOTES,
                                    'UTF-8'
                                )
                                : 'Not set' ?>
                        </p>
                    </div>
                </div>

                <div
                    class="mt-5"
                >
                    <p
                        class="text-xs text-gray-400"
                    >
                        Customer Reason
                    </p>

                    <div
                        class="mt-1 bg-gray-50 rounded-xl px-4 py-3 text-sm text-gray-700 whitespace-pre-wrap"
                    ><?= htmlspecialchars(
                        (string) $selectedRequest[
                            'verification_request_reason'
                        ],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?></div>
                </div>

                <?php if (
                    $selectedRequest[
                        'verification_request_status'
                    ] === 'pending'
                ): ?>

                <div
                    class="mt-6 border border-red-100 bg-red-50 rounded-xl p-5"
                >
                    <h3
                        class="font-bold text-red-800"
                    >
                        Sensitive Evidence
                    </h3>

                    <p
                        class="text-xs text-red-600 mt-1"
                    >
                        Open evidence only when necessary for this review. Evidence access is restricted to Super Admin accounts.
                    </p>

                    <div
                        class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4"
                    >
                        <a
                            href="identity_verification_evidence.php?request_id=<?= (int) $selectedRequest[
                                'verification_request_id'
                            ] ?>&type=identity"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="px-4 py-3 rounded-xl bg-white border border-red-200 text-sm font-bold text-red-700 text-center hover:bg-red-100"
                        >
                            View MyKad Evidence
                        </a>

                        <a
                            href="identity_verification_evidence.php?request_id=<?= (int) $selectedRequest[
                                'verification_request_id'
                            ] ?>&type=phone"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="px-4 py-3 rounded-xl bg-white border border-red-200 text-sm font-bold text-red-700 text-center hover:bg-red-100"
                        >
                            View Phone Ownership Evidence
                        </a>
                    </div>

                    <p
                        class="text-[11px] text-red-500 mt-3"
                    >
                        The evidence viewer validates the stored SHA-256 fingerprint before displaying each file.
                    </p>
                </div>

                <?php else: ?>

                <div
                    class="mt-6 bg-gray-50 rounded-xl px-4 py-3 text-sm text-gray-500"
                >
                    This request has already been processed. Sensitive evidence is not available through this review page.
                </div>

                <?php endif; ?>

                <?php endif; ?>
            </section>
        </div>
    </main>

</body>
</html>