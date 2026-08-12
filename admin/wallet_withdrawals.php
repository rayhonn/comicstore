<?php

require_once __DIR__ . '/../includes/auth.php';
require_senior_admin();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/money_helper.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/wallet_withdrawal_helper.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $withdrawal_id = filter_input(
        INPUT_POST,
        'withdrawal_id',
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );
    $action = $_POST['action'] ?? null;

    if (
        $withdrawal_id === false ||
        $withdrawal_id === null ||
        !is_string($action) ||
        !in_array(
            $action,
            [
                'approve',
                'reject',
                'complete',
            ],
            true
        )
    ) {
        $error =
            'Invalid bank withdrawal action.';
    } else {
        $request_result = null;
        $stored_receipt = null;

        try {
            if ($action === 'approve') {
                $request_result =
                    approveWalletWithdrawalRequest(
                        $pdo,
                        (int) $withdrawal_id,
                        current_user_id(),
                        normalizeWalletWithdrawalAdminNote(
                            $_POST['admin_note'] ?? '',
                            false
                        )
                    );
            } elseif ($action === 'reject') {
                $request_result =
                    rejectWalletWithdrawalRequest(
                        $pdo,
                        (int) $withdrawal_id,
                        current_user_id(),
                        normalizeWalletWithdrawalAdminNote(
                            $_POST['admin_note'] ?? '',
                            true
                        )
                    );
            } else {
                verifyWalletActorPassword(
                    $pdo,
                    current_user_id(),
                    'admin',
                    $_POST['current_password'] ?? null
                );

                $transfer_reference =
                    normalizeWalletWithdrawalTransferReference(
                        $_POST['transfer_reference'] ?? null
                    );

                $stored_receipt =
                    storeWalletWithdrawalReceipt(
                        $_FILES['transfer_receipt'] ?? []
                    );

                try {
                    $request_result =
                        completeWalletWithdrawalRequest(
                            $pdo,
                            (int) $withdrawal_id,
                            current_user_id(),
                            $transfer_reference,
                            $stored_receipt
                        );
                } catch (Throwable $e) {
                    deleteWalletWithdrawalReceipt(
                        $stored_receipt['file_name'] ?? null
                    );

                    throw $e;
                }
            }

            if (!is_array($request_result)) {
                throw new WalletWithdrawalException(
                    'Withdrawal result is missing.'
                );
            }

            $customer_id = (int) $request_result[
                'wallet_withdrawal_user_id'
            ];
            $request_number =
                '#' . str_pad(
                    (string) $withdrawal_id,
                    4,
                    '0',
                    STR_PAD_LEFT
                );
            $amount = moneyFormatSen(
                moneyDecimalToSen(
                    (string) $request_result[
                        'wallet_withdrawal_amount'
                    ]
                )
            );

            try {
                if ($action === 'approve') {
                    sendNotification(
                        $pdo,
                        $customer_id,
                        'Bank Withdrawal Approved',
                        "Your bank withdrawal request $request_number for RM $amount has been approved. The funds remain reserved while the bank transfer is being processed.",
                        'system'
                    );
                } elseif ($action === 'reject') {
                    $reason = trim(
                        (string) (
                            $request_result[
                                'wallet_withdrawal_admin_note'
                            ] ?? ''
                        )
                    );

                    sendNotification(
                        $pdo,
                        $customer_id,
                        'Bank Withdrawal Rejected',
                        "Your bank withdrawal request $request_number for RM $amount was rejected. Reserved funds were released back to your wallet. Reason: $reason",
                        'system'
                    );
                } else {
                    sendNotification(
                        $pdo,
                        $customer_id,
                        'Bank Transfer Completed',
                        "Your bank withdrawal request $request_number for RM $amount has been completed. Transfer reference: " .
                            $request_result[
                                'wallet_withdrawal_transfer_reference'
                            ] .
                            '. The transfer receipt is available in My Wallet.',
                        'system'
                    );
                }
            } catch (Throwable $e) {
                app_error_log(
                    'Wallet withdrawal notification failed: ' .
                    $e->getMessage()
                );
            }

            header(
                'Location: wallet_withdrawals.php?' .
                http_build_query([
                    'result' => $action,
                    'id' => (int) $withdrawal_id,
                ])
            );
            exit;
        } catch (WalletWithdrawalException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            if (is_array($stored_receipt)) {
                deleteWalletWithdrawalReceipt(
                    $stored_receipt['file_name'] ?? null
                );
            }

            app_error_log(
                'Admin bank withdrawal processing failed: ' .
                $e->getMessage()
            );

            $error =
                'Unable to process the bank withdrawal request.';
        }
    }
}

$allowed_filters = [
    'all',
    'pending',
    'approved',
    'rejected',
    'completed',
];
$status_filter = $_GET['status'] ?? 'pending';

if (
    !is_string($status_filter) ||
    !in_array(
        $status_filter,
        $allowed_filters,
        true
    )
) {
    $status_filter = 'pending';
}

$count_rows = $pdo->query("
    SELECT
        wallet_withdrawal_status,
        COUNT(*) AS total
    FROM wallet_withdrawal_requests
    GROUP BY wallet_withdrawal_status
")->fetchAll(PDO::FETCH_ASSOC);

$counts = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'completed' => 0,
];

foreach ($count_rows as $row) {
    $status = (string) $row[
        'wallet_withdrawal_status'
    ];

    if (isset($counts[$status])) {
        $counts[$status] =
            (int) $row['total'];
    }
}

$sql = "
    SELECT
        wr.*,
        u.user_name,
        u.user_first_name,
        u.user_last_name,
        u.user_gmail,
        reviewer.user_first_name
            AS reviewer_first_name,
        reviewer.user_last_name
            AS reviewer_last_name,
        uploader.user_first_name
            AS receipt_uploader_first_name,
        uploader.user_last_name
            AS receipt_uploader_last_name
    FROM wallet_withdrawal_requests wr
    INNER JOIN users u
        ON u.user_id =
            wr.wallet_withdrawal_user_id
    LEFT JOIN users reviewer
        ON reviewer.user_id =
            wr.wallet_withdrawal_reviewed_by
    LEFT JOIN users uploader
        ON uploader.user_id =
            wr.wallet_withdrawal_receipt_uploaded_by
";
$params = [];

if ($status_filter !== 'all') {
    $sql .= "
        WHERE wr.wallet_withdrawal_status = ?
    ";
    $params[] = $status_filter;
}

$sql .= "
    ORDER BY
        CASE wr.wallet_withdrawal_status
            WHEN 'pending' THEN 0
            WHEN 'approved' THEN 1
            WHEN 'completed' THEN 2
            ELSE 3
        END,
        wr.wallet_withdrawal_id DESC
";

$statement = $pdo->prepare($sql);
$statement->execute($params);
$requests = $statement->fetchAll(
    PDO::FETCH_ASSOC
);

$result = $_GET['result'] ?? null;
$result_id = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Wallet Withdrawals - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <main class="max-w-7xl mx-auto px-6 py-8">
        <div
            class="flex items-start justify-between gap-4 flex-wrap mb-6"
        >
            <div>
                <div class="flex items-center gap-3 flex-wrap">
                    <h1 class="text-2xl font-black text-gray-900">
                        Wallet Bank Withdrawals
                    </h1>
                    <span
                        class="bg-red-50 text-red-600 border border-red-100 text-xs font-bold px-3 py-1 rounded-full"
                    >
                        Senior Admin Only
                    </span>
                </div>
                <p class="text-sm text-gray-400 mt-2 max-w-3xl">
                    Review refund-to-bank requests. Approval keeps funds reserved; completion requires an external transfer reference, current admin password, and integrity-checked transfer receipt.
                </p>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div
                class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-5 text-sm"
            >
                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>
        <?php endif; ?>

        <?php if (
            is_string($result) &&
            in_array(
                $result,
                ['approve', 'reject', 'complete'],
                true
            ) &&
            $result_id !== false &&
            $result_id !== null
        ): ?>
            <div
                class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl mb-5 text-sm"
            >
                Withdrawal #<?= str_pad(
                    (string) $result_id,
                    4,
                    '0',
                    STR_PAD_LEFT
                ) ?> was <?= htmlspecialchars(
                    $result === 'approve'
                        ? 'approved'
                        : (
                            $result === 'reject'
                                ? 'rejected'
                                : 'completed'
                        ),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?> successfully.
            </div>
        <?php endif; ?>

        <div
            class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6"
        >
            <?php
            $summary = [
                'pending' => [
                    'Pending Review',
                    'bg-yellow-50 text-yellow-700 border-yellow-100',
                ],
                'approved' => [
                    'Approved / Transfer',
                    'bg-blue-50 text-blue-700 border-blue-100',
                ],
                'rejected' => [
                    'Rejected',
                    'bg-red-50 text-red-700 border-red-100',
                ],
                'completed' => [
                    'Completed',
                    'bg-green-50 text-green-700 border-green-100',
                ],
            ];
            ?>
            <?php foreach ($summary as $key => $card): ?>
                <a
                    href="?status=<?= urlencode($key) ?>"
                    class="<?= $card[1] ?> border rounded-2xl p-4"
                >
                    <p
                        class="text-xs font-semibold uppercase tracking-wide opacity-70"
                    >
                        <?= htmlspecialchars(
                            $card[0],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>
                    <p class="text-2xl font-black mt-1">
                        <?= $counts[$key] ?>
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
                <label class="text-sm font-semibold text-gray-600">
                    View
                </label>
                <select
                    name="status"
                    onchange="this.form.submit()"
                    class="px-4 py-2 border border-gray-200 rounded-xl text-sm"
                >
                    <?php foreach ([
                        'pending' => 'Pending Review',
                        'approved' => 'Approved / Awaiting Transfer',
                        'rejected' => 'Rejected',
                        'completed' => 'Completed',
                        'all' => 'All Requests',
                    ] as $value => $label): ?>
                        <option
                            value="<?= htmlspecialchars(
                                $value,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            <?= $status_filter === $value
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

        <?php if ($requests === []): ?>
            <div
                class="bg-white rounded-2xl shadow-sm p-12 text-center"
            >
                <div class="text-5xl mb-4">🏦</div>
                <p class="font-bold text-gray-700">
                    No bank withdrawal requests found.
                </p>
            </div>
        <?php else: ?>
            <div class="space-y-5">
                <?php foreach ($requests as $request): ?>
                    <?php
                    $status = (string) $request[
                        'wallet_withdrawal_status'
                    ];
                    $status_classes = [
                        'pending' =>
                            'bg-yellow-100 text-yellow-700',
                        'approved' =>
                            'bg-blue-100 text-blue-700',
                        'rejected' =>
                            'bg-red-100 text-red-700',
                        'completed' =>
                            'bg-green-100 text-green-700',
                    ];
                    $customer_name = trim(
                        (string) $request[
                            'user_first_name'
                        ] .
                        ' ' .
                        (string) $request[
                            'user_last_name'
                        ]
                    );
                    $amount_sen = moneyDecimalToSen(
                        (string) $request[
                            'wallet_withdrawal_amount'
                        ]
                    );
                    ?>
                    <section
                        class="bg-white rounded-2xl shadow-sm overflow-hidden"
                    >
                        <div
                            class="px-6 py-5 border-b border-gray-100 flex items-start justify-between gap-4 flex-wrap"
                        >
                            <div>
                                <div class="flex items-center gap-3 flex-wrap">
                                    <h2 class="font-black text-gray-900">
                                        Withdrawal #<?= str_pad(
                                            (string) $request[
                                                'wallet_withdrawal_id'
                                            ],
                                            4,
                                            '0',
                                            STR_PAD_LEFT
                                        ) ?>
                                    </h2>
                                    <span
                                        class="<?= $status_classes[$status] ?? 'bg-gray-100 text-gray-600' ?> text-xs font-bold px-3 py-1 rounded-full capitalize"
                                    >
                                        <?= htmlspecialchars(
                                            $status,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </span>
                                </div>
                                <p class="text-xs text-gray-400 mt-1">
                                    Requested <?= htmlspecialchars(
                                        date(
                                            'd M Y, h:i A',
                                            strtotime(
                                                (string) $request[
                                                    'wallet_withdrawal_created_at'
                                                ]
                                            )
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>
                            </div>

                            <p class="text-2xl font-black text-red-600">
                                RM <?= moneyFormatSen(
                                    $amount_sen
                                ) ?>
                            </p>
                        </div>

                        <div
                            class="p-6 grid grid-cols-1 lg:grid-cols-3 gap-6"
                        >
                            <div class="lg:col-span-2 space-y-4">
                                <div
                                    class="grid grid-cols-1 md:grid-cols-2 gap-4"
                                >
                                    <div class="bg-gray-50 rounded-xl p-4">
                                        <p class="text-xs text-gray-400">
                                            Customer
                                        </p>
                                        <p class="font-bold text-gray-700 mt-1">
                                            <?= htmlspecialchars(
                                                $customer_name,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>
                                        <p class="text-xs text-gray-400 mt-1">
                                            <?= htmlspecialchars(
                                                (string) $request[
                                                    'user_gmail'
                                                ],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>
                                    </div>

                                    <div class="bg-gray-50 rounded-xl p-4">
                                        <p class="text-xs text-gray-400">
                                            Bank Destination
                                        </p>
                                        <p class="font-bold text-gray-700 mt-1">
                                            <?= htmlspecialchars(
                                                (string) $request[
                                                    'wallet_withdrawal_bank_name'
                                                ],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>
                                        <p class="text-xs text-gray-500 mt-1">
                                            <?= htmlspecialchars(
                                                (string) $request[
                                                    'wallet_withdrawal_account_holder'
                                                ],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?> · ••••<?= htmlspecialchars(
                                                (string) $request[
                                                    'wallet_withdrawal_account_number_last4'
                                                ],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>
                                    </div>
                                </div>

                                <div
                                    class="flex items-center gap-3 flex-wrap"
                                >
                                    <a
                                        href="wallet_withdrawal_bank.php?id=<?= (int) $request['wallet_withdrawal_id'] ?>"
                                        class="inline-flex items-center bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold px-4 py-2 rounded-xl text-sm"
                                    >
                                        🔐 Reveal Protected Bank Details
                                    </a>

                                    <?php if (
                                        $status === 'completed' &&
                                        !empty(
                                            $request[
                                                'wallet_withdrawal_receipt_file'
                                            ]
                                        )
                                    ): ?>
                                        <a
                                            href="wallet_withdrawal_receipt.php?id=<?= (int) $request['wallet_withdrawal_id'] ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="inline-flex items-center bg-green-50 hover:bg-green-100 text-green-700 border border-green-200 font-semibold px-4 py-2 rounded-xl text-sm"
                                        >
                                            View Transfer Receipt
                                        </a>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty(
                                    $request[
                                        'wallet_withdrawal_transfer_reference'
                                    ]
                                )): ?>
                                    <div
                                        class="border border-gray-200 rounded-xl p-4"
                                    >
                                        <p class="text-xs text-gray-400">
                                            Bank Transfer Reference
                                        </p>
                                        <p class="font-mono font-bold text-gray-700 mt-1">
                                            <?= htmlspecialchars(
                                                (string) $request[
                                                    'wallet_withdrawal_transfer_reference'
                                                ],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </p>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty(
                                    $request[
                                        'wallet_withdrawal_admin_note'
                                    ]
                                )): ?>
                                    <div
                                        class="border border-gray-200 rounded-xl p-4"
                                    >
                                        <p class="text-xs text-gray-400">
                                            Admin Note
                                        </p>
                                        <p class="text-sm text-gray-700 mt-1 whitespace-pre-wrap"><?= htmlspecialchars(
                                            (string) $request[
                                                'wallet_withdrawal_admin_note'
                                            ],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div>
                                <?php if ($status === 'pending'): ?>
                                    <div
                                        class="bg-green-50 border border-green-100 rounded-xl p-4 mb-4"
                                    >
                                        <p class="font-bold text-green-800 text-sm">
                                            Approve Request
                                        </p>
                                        <p class="text-xs text-green-700 mt-1 leading-relaxed">
                                            Approval does not deduct wallet funds. The amount remains reserved until the bank transfer is completed.
                                        </p>
                                        <form
                                            method="POST"
                                            class="mt-4 space-y-3"
                                            onsubmit="return confirm('Approve this bank withdrawal request? Funds will remain reserved until transfer completion.')"
                                        >
                                            <?php csrf_field(); ?>
                                            <input
                                                type="hidden"
                                                name="withdrawal_id"
                                                value="<?= (int) $request['wallet_withdrawal_id'] ?>"
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
                                                Approve Withdrawal
                                            </button>
                                        </form>
                                    </div>

                                    <div
                                        class="bg-red-50 border border-red-100 rounded-xl p-4"
                                    >
                                        <p class="font-bold text-red-800 text-sm">
                                            Reject Request
                                        </p>
                                        <p class="text-xs text-red-700 mt-1 leading-relaxed">
                                            Rejection immediately releases the reserved wallet amount. A reason is required.
                                        </p>
                                        <form
                                            method="POST"
                                            class="mt-4 space-y-3"
                                            onsubmit="return confirm('Reject this bank withdrawal request and release the reserved funds?')"
                                        >
                                            <?php csrf_field(); ?>
                                            <input
                                                type="hidden"
                                                name="withdrawal_id"
                                                value="<?= (int) $request['wallet_withdrawal_id'] ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="reject"
                                            >
                                            <textarea
                                                name="admin_note"
                                                maxlength="1000"
                                                rows="3"
                                                required
                                                placeholder="Rejection reason..."
                                                class="w-full px-3 py-2 border border-red-200 rounded-lg text-sm resize-none"
                                            ></textarea>
                                            <button
                                                type="submit"
                                                class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 rounded-lg text-sm"
                                            >
                                                Reject & Release Funds
                                            </button>
                                        </form>
                                    </div>

                                <?php elseif ($status === 'approved'): ?>
                                    <div
                                        class="bg-blue-50 border border-blue-100 rounded-xl p-4"
                                    >
                                        <p class="font-bold text-blue-800 text-sm">
                                            Complete Bank Transfer
                                        </p>
                                        <p class="text-xs text-blue-700 mt-1 leading-relaxed">
                                            Perform the transfer through the bank first. Then enter the bank reference and upload the transfer receipt. Completion permanently debits the reserved wallet amount.
                                        </p>

                                        <form
                                            method="POST"
                                            enctype="multipart/form-data"
                                            class="mt-4 space-y-3"
                                            onsubmit="return confirm('Confirm that the external bank transfer has been completed and debit the reserved wallet funds?')"
                                        >
                                            <?php csrf_field(); ?>
                                            <input
                                                type="hidden"
                                                name="withdrawal_id"
                                                value="<?= (int) $request['wallet_withdrawal_id'] ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="complete"
                                            >

                                            <input
                                                type="text"
                                                name="transfer_reference"
                                                maxlength="100"
                                                required
                                                autocomplete="off"
                                                placeholder="Bank transfer reference"
                                                class="w-full px-3 py-2.5 border border-blue-200 rounded-lg text-sm"
                                            >

                                            <div>
                                                <label
                                                    class="block text-xs font-semibold text-blue-800 mb-1.5"
                                                >
                                                    Transfer Receipt
                                                </label>
                                                <input
                                                    type="file"
                                                    name="transfer_receipt"
                                                    accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"
                                                    required
                                                    class="block w-full text-xs text-gray-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-blue-100 file:text-blue-700 file:font-semibold"
                                                >
                                                <p class="text-[11px] text-blue-600 mt-1">
                                                    JPG, PNG, WEBP or PDF · Maximum 5MB
                                                </p>
                                            </div>

                                            <input
                                                type="password"
                                                name="current_password"
                                                maxlength="72"
                                                autocomplete="current-password"
                                                required
                                                placeholder="Current admin password"
                                                class="w-full px-3 py-2.5 border border-blue-200 rounded-lg text-sm"
                                            >

                                            <button
                                                type="submit"
                                                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-lg text-sm"
                                            >
                                                Complete Transfer
                                            </button>
                                        </form>
                                    </div>

                                <?php elseif ($status === 'completed'): ?>
                                    <div
                                        class="bg-green-50 border border-green-100 rounded-xl p-5"
                                    >
                                        <p class="font-bold text-green-800 text-sm">
                                            Transfer Completed
                                        </p>
                                        <p class="text-xs text-green-700 mt-1 leading-relaxed">
                                            The reserved amount has been permanently debited and the transfer receipt is locked to this withdrawal record.
                                        </p>
                                    </div>

                                <?php else: ?>
                                    <div
                                        class="bg-gray-50 border border-gray-200 rounded-xl p-5"
                                    >
                                        <p class="font-bold text-gray-700 text-sm">
                                            Request Rejected
                                        </p>
                                        <p class="text-xs text-gray-500 mt-1 leading-relaxed">
                                            Reserved funds were released back to the wallet. The original refund may be requested again only if its 7-day withdrawal deadline has not expired.
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
