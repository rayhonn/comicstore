<?php

require_once __DIR__ .
    '/../includes/db.php';

require_once __DIR__ .
    '/../includes/auth.php';

require_once __DIR__ .
    '/../includes/csrf.php';

require_once __DIR__ .
    '/../includes/money_helper.php';

require_once __DIR__ .
    '/../includes/account_deletion_mail_helper.php';

require_once __DIR__ .
    '/../includes/identity_helper.php';

require_senior_admin();

$error = '';

if (
    $_SERVER['REQUEST_METHOD'] ===
    'POST'
) {
    csrf_verify();

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

    $action =
        $_POST['action'] ?? null;

    $adminNoteRaw =
        $_POST['admin_note'] ?? '';

    if (
        $requestId === false ||
        $requestId === null ||
        !is_string($action) ||
        !in_array(
            $action,
            [
                'approve',
                'reject',
            ],
            true
        ) ||
        !is_string($adminNoteRaw)
    ) {
        $error =
            'Invalid account deletion request.';
    } else {
        $adminNote =
            trim($adminNoteRaw);

        $adminNoteLength =
            function_exists('mb_strlen')
                ? mb_strlen(
                    $adminNote,
                    'UTF-8'
                )
                : strlen(
                    $adminNote
                );

        if (
            $adminNoteLength > 1000
        ) {
            $error =
                'Administrator note cannot exceed 1000 characters.';
        } elseif (
            $action === 'reject' &&
            $adminNote === ''
        ) {
            $error =
                'A rejection reason is required.';
        }
    }

    if ($error === '') {
        $emailRecipient = null;
        $decision = null;

        try {
            $pdo->beginTransaction();

            $requestLock =
                $pdo->prepare("
                    SELECT
                        adr.deletion_request_id,
                        adr.deletion_request_user_id,
                        adr.deletion_request_status,
                        adr.deletion_request_email_snapshot,
                        adr.deletion_request_phone_snapshot,
                        adr.deletion_request_reason,
                        adr.deletion_request_agreement_version,
                        adr.deletion_request_requested_at,
                        adr.deletion_request_open_key,

                        u.user_name,
                        u.user_first_name,
                        u.user_last_name,
                        u.user_gmail,
                        u.user_phone,
                        u.user_is_active,
                        u.user_deleted_at

                    FROM
                        account_deletion_requests adr

                    INNER JOIN users u
                        ON u.user_id =
                            adr.deletion_request_user_id

                    WHERE
                        adr.deletion_request_id = ?

                    FOR UPDATE
                ");

            $requestLock->execute([
                (int) $requestId,
            ]);

            $request =
                $requestLock->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$request) {
                throw new RuntimeException(
                    'Account deletion request was not found.'
                );
            }

            if (
                $request[
                    'deletion_request_status'
                ] !== 'pending' ||
                $request[
                    'deletion_request_open_key'
                ] === null
            ) {
                throw new RuntimeException(
                    'This account deletion request has already been processed or cancelled.'
                );
            }

            $customerId =
                (int) $request[
                    'deletion_request_user_id'
                ];

            if ($action === 'approve') {
                if (
                    !empty(
                        $request[
                            'user_deleted_at'
                        ]
                    )
                ) {
                    throw new RuntimeException(
                        'This customer account has already been closed.'
                    );
                }

                if (
                    (int) $request[
                        'user_is_active'
                    ] !== 1
                ) {
                    throw new RuntimeException(
                        'Approval blocked because the customer account is currently inactive. Resolve the account status before processing this deletion request.'
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
                        $customerId,
                    ]);

                if (
                    $paymentDraftCheck
                        ->fetchColumn() !==
                    false
                ) {
                    throw new RuntimeException(
                        'Approval blocked because the customer currently has a pending or processing checkout.'
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
                        $customerId,
                    ]);

                if (
                    $activeOrderCheck
                        ->fetchColumn() !==
                    false
                ) {
                    throw new RuntimeException(
                        'Approval blocked because the customer currently has an active order.'
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
                        $customerId,
                    ]);

                if (
                    $pendingReturnCheck
                        ->fetchColumn() !==
                    false
                ) {
                    throw new RuntimeException(
                        'Approval blocked because the customer currently has a pending return request.'
                    );
                }

                $activeWalletTopupCheck =
                    $pdo->prepare("
                        SELECT
                            wallet_topup_id
                        FROM wallet_topups
                        WHERE
                            wallet_topup_user_id = ?
                        AND
                            wallet_topup_status
                            IN (
                                'pending',
                                'checkout_open'
                            )
                        LIMIT 1
                        FOR UPDATE
                    ");

                $activeWalletTopupCheck
                    ->execute([
                        $customerId,
                    ]);

                if (
                    $activeWalletTopupCheck
                        ->fetchColumn() !==
                    false
                ) {
                    throw new RuntimeException(
                        'Approval blocked because the customer currently has a pending wallet top-up.'
                    );
                }

                $walletStateCheck =
                    $pdo->prepare("
                        SELECT
                            wallet_balance,
                            wallet_reserved_amount
                        FROM wallet_accounts
                        WHERE
                            wallet_user_id = ?
                        LIMIT 1
                        FOR UPDATE
                    ");

                $walletStateCheck
                    ->execute([
                        $customerId,
                    ]);

                $walletState =
                    $walletStateCheck->fetch(
                        PDO::FETCH_ASSOC
                    );

                if ($walletState) {
                    $walletBalanceSen =
                        moneyDecimalToSen(
                            (string) $walletState[
                                'wallet_balance'
                            ]
                        );

                    $walletReservedSen =
                        moneyDecimalToSen(
                            (string) $walletState[
                                'wallet_reserved_amount'
                            ]
                        );

                    if (
                        $walletBalanceSen > 0 ||
                        $walletReservedSen > 0
                    ) {
                        throw new RuntimeException(
                            'Approval blocked because the customer still has wallet funds or reserved wallet funds.'
                        );
                    }
                }

                $activeWithdrawalCheck =
                    $pdo->prepare("
                        SELECT
                            wallet_withdrawal_id
                        FROM
                            wallet_withdrawal_requests
                        WHERE
                            wallet_withdrawal_user_id = ?
                        AND
                            wallet_withdrawal_status
                            IN (
                                'pending',
                                'approved'
                            )
                        LIMIT 1
                        FOR UPDATE
                    ");

                $activeWithdrawalCheck
                    ->execute([
                        $customerId,
                    ]);

                if (
                    $activeWithdrawalCheck
                        ->fetchColumn() !==
                    false
                ) {
                    throw new RuntimeException(
                        'Approval blocked because the customer currently has an active bank withdrawal request.'
                    );
                }

                claimCustomerIdentity(
                    $pdo,
                    $customerId,
                    'email',
                    (string) $request[
                        'user_gmail'
                    ],
                    'account_deletion'
                );

                if (
                    !empty(
                        $request[
                            'user_phone'
                        ]
                    )
                ) {
                    claimCustomerIdentity(
                        $pdo,
                        $customerId,
                        'phone',
                        (string) $request[
                            'user_phone'
                        ],
                        'account_deletion'
                    );
                }

                markCustomerIdentitiesHistorical(
                    $pdo,
                    $customerId
                );

                $deleteResetTokens =
                    $pdo->prepare("
                        DELETE FROM
                            password_resets
                        WHERE
                            reset_email = ?
                    ");

                $deleteResetTokens
                    ->execute([
                        $request[
                            'user_gmail'
                        ],
                    ]);

                $closeAccount =
                    $pdo->prepare("
                        UPDATE users
                        SET
                            user_is_active = 0,
                            user_deleted_at =
                                NOW()
                        WHERE
                            user_id = ?
                        AND
                            user_role =
                                'customer'
                        AND
                            user_is_active = 1
                        AND
                            user_deleted_at
                                IS NULL
                    ");

                $closeAccount
                    ->execute([
                        $customerId,
                    ]);

                if (
                    $closeAccount
                        ->rowCount() !== 1
                ) {
                    throw new RuntimeException(
                        'Unable to close the customer account.'
                    );
                }

                $decision =
                    'approved';

                $logAction =
                    'approve_account_deletion';

                $logDetails =
                    'Super Admin approved customer account deletion request';
            } else {
                $decision =
                    'rejected';

                $logAction =
                    'reject_account_deletion';

                $logDetails =
                    'Super Admin rejected customer account deletion request';
            }

            $processRequest =
                $pdo->prepare("
                    UPDATE
                        account_deletion_requests
                    SET
                        deletion_request_status = ?,
                        deletion_request_processed_by = ?,
                        deletion_request_processed_at =
                            NOW(),
                        deletion_request_admin_note = ?,
                        deletion_request_open_key =
                            NULL
                    WHERE
                        deletion_request_id = ?
                    AND
                        deletion_request_status =
                            'pending'
                    AND
                        deletion_request_open_key =
                            1
                ");

            $processRequest->execute([
                $decision,
                (int) $_SESSION[
                    'user_id'
                ],
                $adminNote !== ''
                    ? $adminNote
                    : null,
                (int) $requestId,
            ]);

            if (
                $processRequest
                    ->rowCount() !== 1
            ) {
                throw new RuntimeException(
                    'Unable to process the account deletion request.'
                );
            }

            $log =
                $pdo->prepare("
                    INSERT INTO admin_logs (
                        log_admin_id,
                        log_action,
                        log_target_type,
                        log_target_id,
                        log_details
                    )
                    VALUES (
                        ?,
                        ?,
                        'user',
                        ?,
                        ?
                    )
                ");

            $log->execute([
                (int) $_SESSION[
                    'user_id'
                ],
                $logAction,
                $customerId,
                $logDetails .
                    ' (Request #' .
                    (int) $requestId .
                    ')',
            ]);

            $emailRecipient = [
                'email' =>
                    (string) $request[
                        'deletion_request_email_snapshot'
                    ],

                'first_name' =>
                    (string) $request[
                        'user_first_name'
                    ],

                'decision' =>
                    $decision,

                'admin_note' =>
                    $adminNote,
            ];

            $pdo->commit();
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
                'Account deletion request processing failed: ' .
                $e->getMessage()
            );

            $error =
                'Unable to process the account deletion request. Please try again later.';
        }

        if (
            $error === '' &&
            is_array($emailRecipient)
        ) {
            $mailSent =
                sendAccountDeletionDecisionEmail(
                    $emailRecipient[
                        'email'
                    ],
                    $emailRecipient[
                        'first_name'
                    ],
                    $emailRecipient[
                        'decision'
                    ],
                    $emailRecipient[
                        'admin_note'
                    ]
                );

            header(
                'Location: account_deletion_requests.php?' .
                http_build_query([
                    'result' =>
                        $decision,
                    'mail' =>
                        $mailSent
                            ? 'sent'
                            : 'failed',
                ])
            );
            exit;
        }
    }
}

$statusRaw =
    $_GET['status'] ?? 'pending';

$statusFilter =
    is_string($statusRaw) &&
    in_array(
        $statusRaw,
        [
            'pending',
            'approved',
            'rejected',
            'cancelled',
            'all',
        ],
        true
    )
        ? $statusRaw
        : 'pending';

$countRows =
    $pdo->query("
        SELECT
            deletion_request_status,
            COUNT(*) AS total
        FROM
            account_deletion_requests
        GROUP BY
            deletion_request_status
    ")->fetchAll(
        PDO::FETCH_ASSOC
    );

$statusCounts = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'cancelled' => 0,
];

foreach ($countRows as $row) {
    $status =
        (string) $row[
            'deletion_request_status'
        ];

    if (
        array_key_exists(
            $status,
            $statusCounts
        )
    ) {
        $statusCounts[$status] =
            (int) $row['total'];
    }
}

$sql = "
    SELECT
        adr.*,

        u.user_name,
        u.user_first_name,
        u.user_last_name,
        u.user_gmail,
        u.user_phone,
        u.user_is_active,
        u.user_deleted_at,

        processor.user_first_name
            AS processor_first_name,
        processor.user_last_name
            AS processor_last_name

    FROM
        account_deletion_requests adr

    INNER JOIN users u
        ON u.user_id =
            adr.deletion_request_user_id

    LEFT JOIN users processor
        ON processor.user_id =
            adr.deletion_request_processed_by
";

$params = [];

if ($statusFilter !== 'all') {
    $sql .= "
        WHERE
            adr.deletion_request_status = ?
    ";

    $params[] =
        $statusFilter;
}

$sql .= "
    ORDER BY
        CASE
            WHEN
                adr.deletion_request_status =
                    'pending'
            THEN 0
            ELSE 1
        END,
        adr.deletion_request_requested_at
            DESC,
        adr.deletion_request_id
            DESC
";

$requestStatement =
    $pdo->prepare($sql);

$requestStatement
    ->execute($params);

$requests =
    $requestStatement
        ->fetchAll(
            PDO::FETCH_ASSOC
        );

$resultStatus =
    $_GET['result'] ?? null;

$mailStatus =
    $_GET['mail'] ?? null;
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
        Account Deletion Requests - MangaVault Admin
    </title>

    <script
        src="https://cdn.tailwindcss.com"
    ></script>
</head>

<body class="bg-gray-100 min-h-screen">

    <?php
    include
        '../includes/admin_navbar.php';
    ?>

    <main
        class="max-w-7xl mx-auto px-5 md:px-7 py-8"
    >
        <div
            class="flex items-start justify-between gap-5 flex-wrap mb-6"
        >
            <div>
                <div
                    class="flex items-center gap-3"
                >
                    <h1
                        class="text-2xl font-black text-gray-900"
                    >
                        Account Deletion Requests
                    </h1>

                    <span
                        class="bg-red-50 text-red-600 border border-red-100 text-xs font-bold px-3 py-1 rounded-full"
                    >
                        Super Admin Only
                    </span>
                </div>

                <p
                    class="text-sm text-gray-400 mt-2 max-w-3xl"
                >
                    Review customer account deletion requests.
                    Approval permanently closes customer access
                    while retaining transaction history.
                </p>
            </div>
        </div>

        <?php if (
            $resultStatus ===
                'approved'
        ): ?>
        <div
            class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl mb-5 text-sm"
        >
            ✅ Account deletion approved successfully.
        </div>
        <?php elseif (
            $resultStatus ===
                'rejected'
        ): ?>
        <div
            class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl mb-5 text-sm"
        >
            ✅ Account deletion request rejected successfully.
        </div>
        <?php endif; ?>

        <?php if (
            $mailStatus === 'sent'
        ): ?>
        <div
            class="bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded-xl mb-5 text-sm"
        >
            ✉️ The customer was notified by email.
        </div>
        <?php elseif (
            $mailStatus === 'failed'
        ): ?>
        <div
            class="bg-yellow-50 border border-yellow-200 text-yellow-700 px-4 py-3 rounded-xl mb-5 text-sm"
        >
            ⚠️ The request was processed successfully, but the email notification could not be sent. Check the mail configuration or application log.
        </div>
        <?php endif; ?>

        <?php if (
            $error !== ''
        ): ?>
        <div
            class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-5 text-sm"
        >
            ❌
            <?= htmlspecialchars(
                $error,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>
        <?php endif; ?>

        <div
            class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6"
        >
            <?php
            $summaryCards = [
                'pending' => [
                    'Pending',
                    'bg-yellow-50 text-yellow-700 border-yellow-100',
                ],
                'approved' => [
                    'Approved',
                    'bg-green-50 text-green-700 border-green-100',
                ],
                'rejected' => [
                    'Rejected',
                    'bg-red-50 text-red-700 border-red-100',
                ],
                'cancelled' => [
                    'Cancelled',
                    'bg-gray-50 text-gray-600 border-gray-200',
                ],
            ];
            ?>

            <?php foreach (
                $summaryCards as
                $status => $summary
            ): ?>
            <a
                href="?status=<?= urlencode($status) ?>"
                class="<?= $summary[1] ?> border rounded-2xl p-4"
            >
                <p
                    class="text-xs font-semibold uppercase tracking-wide opacity-70"
                >
                    <?= htmlspecialchars(
                        $summary[0],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>

                <p
                    class="text-2xl font-black mt-1"
                >
                    <?= $statusCounts[
                        $status
                    ] ?>
                </p>
            </a>
            <?php endforeach; ?>
        </div>

        <div
            class="bg-white rounded-2xl shadow-sm p-4 mb-6"
        >
            <form
                method="GET"
                class="flex items-center gap-3 flex-wrap"
            >
                <label
                    class="text-sm font-semibold text-gray-600"
                >
                    View
                </label>

                <select
                    name="status"
                    onchange="this.form.submit()"
                    class="px-4 py-2 border border-gray-200 rounded-xl text-sm"
                >
                    <?php
                    $filters = [
                        'pending' =>
                            'Pending Review',
                        'approved' =>
                            'Approved',
                        'rejected' =>
                            'Rejected',
                        'cancelled' =>
                            'Cancelled',
                        'all' =>
                            'All Requests',
                    ];
                    ?>

                    <?php foreach (
                        $filters as
                        $value => $label
                    ): ?>
                    <option
                        value="<?= htmlspecialchars(
                            $value,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        <?= $statusFilter ===
                            $value
                                ? 'selected'
                                : '' ?>
                    >
                        <?= htmlspecialchars(
                            $label,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if (
            $requests === []
        ): ?>
        <div
            class="bg-white rounded-2xl shadow-sm p-12 text-center"
        >
            <div
                class="text-5xl mb-4"
            >
                ✓
            </div>

            <p
                class="font-bold text-gray-700"
            >
                No account deletion requests found.
            </p>
        </div>

        <?php else: ?>

        <div class="space-y-5">

            <?php foreach (
                $requests as $request
            ): ?>

            <?php
            $requestStatus =
                (string) $request[
                    'deletion_request_status'
                ];

            $statusClasses = [
                'pending' =>
                    'bg-yellow-100 text-yellow-700',
                'approved' =>
                    'bg-green-100 text-green-700',
                'rejected' =>
                    'bg-red-100 text-red-700',
                'cancelled' =>
                    'bg-gray-100 text-gray-600',
            ];

            $statusClass =
                $statusClasses[
                    $requestStatus
                ] ??
                'bg-gray-100 text-gray-600';

            $customerName =
                trim(
                    (string) $request[
                        'user_first_name'
                    ] .
                    ' ' .
                    (string) $request[
                        'user_last_name'
                    ]
                );
            ?>

            <section
                class="bg-white rounded-2xl shadow-sm overflow-hidden"
            >
                <div
                    class="px-6 py-5 border-b border-gray-100 flex items-start justify-between gap-5 flex-wrap"
                >
                    <div>
                        <div
                            class="flex items-center gap-3 flex-wrap"
                        >
                            <h2
                                class="font-black text-gray-900"
                            >
                                Request
                                #<?= str_pad(
                                    (string) $request[
                                        'deletion_request_id'
                                    ],
                                    4,
                                    '0',
                                    STR_PAD_LEFT
                                ) ?>
                            </h2>

                            <span
                                class="<?= $statusClass ?> text-xs font-bold px-3 py-1 rounded-full"
                            >
                                <?= htmlspecialchars(
                                    ucfirst(
                                        $requestStatus
                                    ),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>
                        </div>

                        <p
                            class="text-xs text-gray-400 mt-1"
                        >
                            Submitted
                            <?= htmlspecialchars(
                                date(
                                    'd M Y, h:i A',
                                    strtotime(
                                        $request[
                                            'deletion_request_requested_at'
                                        ]
                                    )
                                ),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </p>
                    </div>

                    <div
                        class="text-right"
                    >
                        <p
                            class="font-bold text-gray-800"
                        >
                            <?= htmlspecialchars(
                                $customerName !== ''
                                    ? $customerName
                                    : 'Customer',
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </p>

                        <p
                            class="text-xs text-gray-400"
                        >
                            @<?= htmlspecialchars(
                                $request[
                                    'user_name'
                                ],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </p>
                    </div>
                </div>

                <div
                    class="p-6 grid grid-cols-1 lg:grid-cols-3 gap-6"
                >
                    <div
                        class="lg:col-span-2 space-y-5"
                    >
                        <div
                            class="grid grid-cols-1 md:grid-cols-2 gap-4"
                        >
                            <div
                                class="bg-gray-50 rounded-xl p-4"
                            >
                                <p
                                    class="text-xs text-gray-400"
                                >
                                    Email at Request
                                </p>

                                <p
                                    class="text-sm font-semibold text-gray-700 mt-1 break-all"
                                >
                                    <?= htmlspecialchars(
                                        $request[
                                            'deletion_request_email_snapshot'
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
                                    Phone at Request
                                </p>

                                <p
                                    class="text-sm font-semibold text-gray-700 mt-1"
                                >
                                    <?= htmlspecialchars(
                                        $request[
                                            'deletion_request_phone_snapshot'
                                        ] ??
                                        'Not provided',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>
                            </div>
                        </div>

                        <div
                            class="bg-gray-50 rounded-xl p-4"
                        >
                            <p
                                class="text-xs text-gray-400 mb-1"
                            >
                                Customer Reason
                            </p>

                            <p
                                class="text-sm text-gray-700 leading-relaxed whitespace-pre-wrap"
                            ><?= htmlspecialchars(
                                $request[
                                    'deletion_request_reason'
                                ] ??
                                'No reason provided.',
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?></p>
                        </div>

                        <div
                            class="grid grid-cols-1 md:grid-cols-2 gap-4"
                        >
                            <div
                                class="border border-gray-200 rounded-xl p-4"
                            >
                                <p
                                    class="text-xs text-gray-400"
                                >
                                    Agreement Version
                                </p>

                                <p
                                    class="text-sm font-semibold text-gray-700 mt-1"
                                >
                                    <?= htmlspecialchars(
                                        $request[
                                            'deletion_request_agreement_version'
                                        ],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>
                            </div>

                            <div
                                class="border border-gray-200 rounded-xl p-4"
                            >
                                <p
                                    class="text-xs text-gray-400"
                                >
                                    Current Account Status
                                </p>

                                <p
                                    class="text-sm font-semibold mt-1 <?= (int) $request['user_is_active'] === 1 ? 'text-green-600' : 'text-red-600' ?>"
                                >
                                    <?= (int) $request[
                                        'user_is_active'
                                    ] === 1
                                        ? 'Active'
                                        : 'Inactive' ?>
                                </p>
                            </div>
                        </div>

                        <?php if (
                            $requestStatus !==
                                'pending'
                        ): ?>
                        <div
                            class="border border-gray-200 rounded-xl p-4"
                        >
                            <p
                                class="text-xs text-gray-400"
                            >
                                Processed By
                            </p>

                            <p
                                class="text-sm font-semibold text-gray-700 mt-1"
                            >
                                <?= htmlspecialchars(
                                    trim(
                                        (string) (
                                            $request[
                                                'processor_first_name'
                                            ] ?? ''
                                        ) .
                                        ' ' .
                                        (string) (
                                            $request[
                                                'processor_last_name'
                                            ] ?? ''
                                        )
                                    ) ?: 'Unknown',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </p>

                            <?php if (
                                !empty(
                                    $request[
                                        'deletion_request_processed_at'
                                    ]
                                )
                            ): ?>
                            <p
                                class="text-xs text-gray-400 mt-1"
                            >
                                <?= htmlspecialchars(
                                    date(
                                        'd M Y, h:i A',
                                        strtotime(
                                            $request[
                                                'deletion_request_processed_at'
                                            ]
                                        )
                                    ),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </p>
                            <?php endif; ?>

                            <?php if (
                                !empty(
                                    $request[
                                        'deletion_request_admin_note'
                                    ]
                                )
                            ): ?>
                            <p
                                class="text-sm text-gray-600 mt-3 whitespace-pre-wrap"
                            ><?= htmlspecialchars(
                                $request[
                                    'deletion_request_admin_note'
                                ],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <?php if (
                            $requestStatus ===
                                'pending'
                        ): ?>

                        <div
                            class="bg-green-50 border border-green-100 rounded-xl p-4 mb-4"
                        >
                            <p
                                class="font-bold text-green-800 text-sm"
                            >
                                Approve Request
                            </p>

                            <p
                                class="text-xs text-green-700 mt-1 leading-relaxed"
                            >
                                The system will re-check checkout,
                                order and return activity before
                                closing the account.
                            </p>

                            <form
                                method="POST"
                                class="mt-4 space-y-3"
                                onsubmit="return confirm('Approve this account deletion request and close the customer account?')"
                            >
                                <?php csrf_field(); ?>

                                <input
                                    type="hidden"
                                    name="deletion_request_id"
                                    value="<?= (int) $request['deletion_request_id'] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="approve"
                                >

                                <textarea
                                    name="admin_note"
                                    maxlength="1000"
                                    rows="3"
                                    placeholder="Optional approval note..."
                                    class="w-full px-3 py-2 border border-green-200 rounded-lg text-sm resize-none"
                                ></textarea>

                                <button
                                    type="submit"
                                    class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2.5 rounded-lg text-sm"
                                >
                                    Approve & Close Account
                                </button>
                            </form>
                        </div>

                        <div
                            class="bg-red-50 border border-red-100 rounded-xl p-4"
                        >
                            <p
                                class="font-bold text-red-800 text-sm"
                            >
                                Reject Request
                            </p>

                            <p
                                class="text-xs text-red-700 mt-1 leading-relaxed"
                            >
                                A rejection reason is required and
                                will be included in the email sent
                                to the customer.
                            </p>

                            <form
                                method="POST"
                                class="mt-4 space-y-3"
                                onsubmit="return confirm('Reject this account deletion request?')"
                            >
                                <?php csrf_field(); ?>

                                <input
                                    type="hidden"
                                    name="deletion_request_id"
                                    value="<?= (int) $request['deletion_request_id'] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="reject"
                                >

                                <textarea
                                    name="admin_note"
                                    maxlength="1000"
                                    rows="4"
                                    required
                                    placeholder="Enter rejection reason..."
                                    class="w-full px-3 py-2 border border-red-200 rounded-lg text-sm resize-none"
                                ></textarea>

                                <button
                                    type="submit"
                                    class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 rounded-lg text-sm"
                                >
                                    Reject Request
                                </button>
                            </form>
                        </div>

                        <?php else: ?>

                        <div
                            class="bg-gray-50 border border-gray-200 rounded-xl p-5"
                        >
                            <p
                                class="text-sm font-semibold text-gray-600"
                            >
                                This request has already been processed.
                            </p>
                        </div>

                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <?php endforeach; ?>

        </div>

        <?php endif; ?>
    </main>

</body>
</html>