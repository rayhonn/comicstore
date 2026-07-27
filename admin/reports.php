<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/money_helper.php';

require_admin();

date_default_timezone_set('Asia/Kuala_Lumpur');

$report_types = [
    'sales' => [
        'title' => 'Sales Report',
        'description' =>
            'Confirmed non-cancelled orders and revenue.',
        'icon' => '💰',
    ],
    'product_sales' => [
        'title' => 'Product Sales Report',
        'description' =>
            'Units sold and revenue by product.',
        'icon' => '📚',
    ],
    'inventory' => [
        'title' => 'Inventory Report',
        'description' =>
            'Current physical stock and low-stock status.',
        'icon' => '📦',
    ],
    'customers' => [
        'title' => 'Customer Report',
        'description' =>
            'Customer registrations, tiers and spending.',
        'icon' => '👥',
    ],
    'suppliers' => [
        'title' => 'Supplier Performance Report',
        'description' =>
            'Purchase orders, spending, ratings and lead time.',
        'icon' => '🏭',
    ],
    'returns' => [
        'title' => 'Return Report',
        'description' =>
            'Customer return requests and outcomes.',
        'icon' => '↩️',
    ],
];

function normalizeReportDate(
    mixed $value,
    string $fallback
): string {
    if (!is_string($value) || strlen($value) !== 10) {
        return $fallback;
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $value
    );
    $errors = DateTimeImmutable::getLastErrors();

    if (
        !$date ||
        (
            is_array($errors) &&
            (
                $errors['warning_count'] > 0 ||
                $errors['error_count'] > 0
            )
        ) ||
        $date->format('Y-m-d') !== $value
    ) {
        return $fallback;
    }

    return $date->format('Y-m-d');
}

function csvSafeValue(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    $value = (string) $value;

    if (
        $value !== '' &&
        in_array($value[0], ['=', '+', '-', '@'], true)
    ) {
        return "'" . $value;
    }

    return $value;
}

function reportDisplayValue(
    string $key,
    mixed $value
): string {
    if ($value === null || $value === '') {
        return '—';
    }

    $money_columns = [
        'order_total_amount',
        'revenue',
        'product_price',
        'lifetime_spending',
        'total_spent',
        'total_spend',
    ];

    if (in_array($key, $money_columns, true)) {
        try {
            return 'RM ' . moneyFormatSen(
                moneyDecimalToSen((string) $value)
            );
        } catch (MoneyValueException) {
            return 'RM 0.00';
        }
    }

    if ($key === 'is_active') {
        return (int) $value === 1
            ? 'Active'
            : 'Inactive';
    }

    if ($key === 'avg_rating') {
        return number_format((float) $value, 1) . ' / 5';
    }

    if ($key === 'avg_lead_days') {
        return number_format((float) $value, 1) . ' days';
    }

    if (
        in_array(
            $key,
            [
                'item_lines',
                'units',
                'units_sold',
                'stock_quantity',
                'low_stock_threshold',
                'points',
                'total_orders',
                'total_pos',
                'completed_pos',
            ],
            true
        )
    ) {
        return number_format((int) $value);
    }

    return (string) $value;
}

function reportCsvValue(
    string $key,
    mixed $value
): string {
    if ($value === null) {
        return '';
    }

    $money_columns = [
        'order_total_amount',
        'revenue',
        'product_price',
        'lifetime_spending',
        'total_spent',
        'total_spend',
    ];

    if (in_array($key, $money_columns, true)) {
        try {
            return moneySenToDecimal(
                moneyDecimalToSen((string) $value)
            );
        } catch (MoneyValueException) {
            return '0.00';
        }
    }

    if ($key === 'is_active') {
        return (int) $value === 1
            ? 'Active'
            : 'Inactive';
    }

    return csvSafeValue($value);
}

function getReportData(
    PDO $pdo,
    string $report,
    string $from_date,
    string $to_date
): array {
    switch ($report) {
        case 'sales':
            $columns = [
                'order_number' => 'Order Number',
                'order_date' => 'Order Date',
                'customer_name' => 'Customer',
                'customer_email' => 'Email',
                'item_lines' => 'Item Lines',
                'units' => 'Units',
                'order_total_amount' => 'Revenue (RM)',
                'order_status' => 'Order Status',
                'payment_status' => 'Payment Status',
            ];

            $statement = $pdo->prepare("
                SELECT
                    o.order_id,
                    DATE_FORMAT(
                        o.order_created_at,
                        '%Y-%m-%d %H:%i:%s'
                    ) AS order_date,
                    TRIM(
                        CONCAT_WS(
                            ' ',
                            u.user_first_name,
                            u.user_last_name
                        )
                    ) AS customer_name,
                    u.user_gmail AS customer_email,
                    COUNT(DISTINCT oi.order_item_id)
                        AS item_lines,
                    COALESCE(
                        SUM(oi.order_item_quantity),
                        0
                    ) AS units,
                    o.order_total_amount,
                    o.order_status,
                    o.order_payment_status
                        AS payment_status
                FROM orders o
                JOIN users u
                    ON u.user_id = o.order_user_id
                LEFT JOIN order_items oi
                    ON oi.order_item_order_id =
                        o.order_id
                WHERE o.order_created_at >= ?
                AND o.order_created_at <
                    DATE_ADD(?, INTERVAL 1 DAY)
                AND o.order_payment_status = 'confirmed'
                AND o.order_status != 'cancelled'
                GROUP BY
                    o.order_id,
                    o.order_created_at,
                    u.user_first_name,
                    u.user_last_name,
                    u.user_gmail,
                    o.order_total_amount,
                    o.order_status,
                    o.order_payment_status
                ORDER BY o.order_created_at DESC
            ");
            $statement->execute([
                $from_date,
                $to_date,
            ]);
            $rows = $statement->fetchAll(
                PDO::FETCH_ASSOC
            );

            foreach ($rows as &$row) {
                $row['order_number'] =
                    'ORD-' .
                    str_pad(
                        (string) ((int) $row['order_id']),
                        4,
                        '0',
                        STR_PAD_LEFT
                    );
                unset($row['order_id']);
            }
            unset($row);

            return [$columns, $rows];

        case 'product_sales':
            $columns = [
                'product_id' => 'Product ID',
                'product_title' => 'Product',
                'product_type' => 'Type',
                'units_sold' => 'Units Sold',
                'revenue' => 'Revenue (RM)',
            ];

            $statement = $pdo->prepare("
                SELECT
                    p.product_id,
                    p.product_title,
                    p.product_type,
                    COALESCE(
                        SUM(oi.order_item_quantity),
                        0
                    ) AS units_sold,
                    COALESCE(
                        SUM(
                            oi.order_item_price *
                            oi.order_item_quantity
                        ),
                        0.00
                    ) AS revenue
                FROM order_items oi
                JOIN orders o
                    ON o.order_id =
                        oi.order_item_order_id
                JOIN products p
                    ON p.product_id =
                        oi.order_item_product_id
                WHERE o.order_created_at >= ?
                AND o.order_created_at <
                    DATE_ADD(?, INTERVAL 1 DAY)
                AND o.order_payment_status = 'confirmed'
                AND o.order_status != 'cancelled'
                GROUP BY
                    p.product_id,
                    p.product_title,
                    p.product_type
                ORDER BY units_sold DESC,
                    p.product_title ASC
            ");
            $statement->execute([
                $from_date,
                $to_date,
            ]);

            return [
                $columns,
                $statement->fetchAll(PDO::FETCH_ASSOC),
            ];

        case 'inventory':
            $columns = [
                'product_id' => 'Product ID',
                'product_title' => 'Product',
                'product_series' => 'Series',
                'product_volume_number' => 'Volume',
                'product_price' => 'Price (RM)',
                'stock_quantity' => 'Current Stock',
                'low_stock_threshold' => 'Low-Stock Level',
                'stock_status' => 'Stock Status',
                'availability' => 'Availability',
            ];

            $statement = $pdo->query("
                SELECT
                    p.product_id,
                    p.product_title,
                    p.product_series,
                    p.product_volume_number,
                    p.product_price,
                    pp.physical_stock_quantity
                        AS stock_quantity,
                    pp.physical_low_stock_threshold
                        AS low_stock_threshold,
                    CASE
                        WHEN pp.physical_stock_quantity = 0
                            THEN 'Out of Stock'
                        WHEN pp.physical_stock_quantity <=
                            pp.physical_low_stock_threshold
                            THEN 'Low Stock'
                        ELSE 'In Stock'
                    END AS stock_status,
                    CASE
                        WHEN p.product_is_available = 1
                            THEN 'Available'
                        ELSE 'Unavailable'
                    END AS availability
                FROM products p
                JOIN product_physical pp
                    ON pp.physical_product_id =
                        p.product_id
                WHERE p.product_type = 'physical'
                ORDER BY
                    pp.physical_stock_quantity ASC,
                    p.product_title ASC
            ");

            return [
                $columns,
                $statement->fetchAll(PDO::FETCH_ASSOC),
            ];

        case 'customers':
            $columns = [
                'customer_id' => 'Customer ID',
                'customer_name' => 'Customer',
                'email' => 'Email',
                'tier' => 'Tier',
                'points' => 'Points',
                'lifetime_spending' =>
                    'Lifetime Spending (RM)',
                'total_orders' => 'Confirmed Orders',
                'total_spent' => 'Confirmed Spending (RM)',
                'is_active' => 'Account Status',
                'registered_at' => 'Registered At',
            ];

            $statement = $pdo->prepare("
                SELECT
                    u.user_id AS customer_id,
                    TRIM(
                        CONCAT_WS(
                            ' ',
                            u.user_first_name,
                            u.user_last_name
                        )
                    ) AS customer_name,
                    u.user_gmail AS email,
                    u.user_tier AS tier,
                    u.user_points AS points,
                    u.user_lifetime_spending
                        AS lifetime_spending,
                    COUNT(
                        DISTINCT CASE
                            WHEN
                                o.order_payment_status =
                                    'confirmed'
                                AND o.order_status !=
                                    'cancelled'
                            THEN o.order_id
                        END
                    ) AS total_orders,
                    COALESCE(
                        SUM(
                            CASE
                                WHEN
                                    o.order_payment_status =
                                        'confirmed'
                                    AND o.order_status !=
                                        'cancelled'
                                THEN o.order_total_amount
                                ELSE 0
                            END
                        ),
                        0.00
                    ) AS total_spent,
                    u.user_is_active AS is_active,
                    DATE_FORMAT(
                        u.user_created_at,
                        '%Y-%m-%d %H:%i:%s'
                    ) AS registered_at
                FROM users u
                LEFT JOIN orders o
                    ON o.order_user_id = u.user_id
                WHERE u.user_role = 'customer'
                AND u.user_created_at >= ?
                AND u.user_created_at <
                    DATE_ADD(?, INTERVAL 1 DAY)
                GROUP BY
                    u.user_id,
                    u.user_first_name,
                    u.user_last_name,
                    u.user_gmail,
                    u.user_tier,
                    u.user_points,
                    u.user_lifetime_spending,
                    u.user_is_active,
                    u.user_created_at
                ORDER BY u.user_created_at DESC
            ");
            $statement->execute([
                $from_date,
                $to_date,
            ]);

            return [
                $columns,
                $statement->fetchAll(PDO::FETCH_ASSOC),
            ];

        case 'suppliers':
            $columns = [
                'supplier_id' => 'Supplier ID',
                'supplier_name' => 'Supplier',
                'supplier_status' => 'Status',
                'total_pos' => 'Purchase Orders',
                'completed_pos' => 'Completed POs',
                'total_spend' => 'Completed Spend (RM)',
                'avg_rating' => 'Average Rating',
                'avg_lead_days' => 'Average Lead Time',
            ];

            $statement = $pdo->prepare("
                SELECT
                    s.supplier_id,
                    s.supplier_name,
                    s.supplier_status,
                    COALESCE(
                        po_stats.total_pos,
                        0
                    ) AS total_pos,
                    COALESCE(
                        po_stats.completed_pos,
                        0
                    ) AS completed_pos,
                    COALESCE(
                        po_stats.total_spend,
                        0.00
                    ) AS total_spend,
                    po_stats.avg_rating,
                    lead_stats.avg_lead_days
                FROM suppliers s
                LEFT JOIN (
                    SELECT
                        po_supplier_id,
                        COUNT(*) AS total_pos,
                        SUM(
                            CASE
                                WHEN po_status = 'completed'
                                THEN 1
                                ELSE 0
                            END
                        ) AS completed_pos,
                        COALESCE(
                            SUM(
                                CASE
                                    WHEN po_status =
                                        'completed'
                                    THEN po_total_amount
                                    ELSE 0
                                END
                            ),
                            0.00
                        ) AS total_spend,
                        AVG(po_rating) AS avg_rating
                    FROM purchase_orders
                    WHERE po_created_at >= ?
                    AND po_created_at <
                        DATE_ADD(?, INTERVAL 1 DAY)
                    GROUP BY po_supplier_id
                ) po_stats
                    ON po_stats.po_supplier_id =
                        s.supplier_id
                LEFT JOIN (
                    SELECT
                        po.po_supplier_id,
                        AVG(
                            DATEDIFF(
                                gr.gr_received_at,
                                po.po_created_at
                            )
                        ) AS avg_lead_days
                    FROM purchase_orders po
                    JOIN goods_received gr
                        ON gr.gr_po_id = po.po_id
                        AND gr.gr_status = 'completed'
                    WHERE po.po_created_at >= ?
                    AND po.po_created_at <
                        DATE_ADD(?, INTERVAL 1 DAY)
                    GROUP BY po.po_supplier_id
                ) lead_stats
                    ON lead_stats.po_supplier_id =
                        s.supplier_id
                ORDER BY
                    completed_pos DESC,
                    s.supplier_name ASC
            ");
            $statement->execute([
                $from_date,
                $to_date,
                $from_date,
                $to_date,
            ]);

            return [
                $columns,
                $statement->fetchAll(PDO::FETCH_ASSOC),
            ];

        case 'returns':
            $columns = [
                'return_number' => 'Return Number',
                'order_number' => 'Order Number',
                'customer_name' => 'Customer',
                'product_title' => 'Product',
                'return_reason' => 'Reason',
                'return_status' => 'Status',
                'return_created_at' => 'Requested At',
            ];

            $statement = $pdo->prepare("
                SELECT
                    rr.return_id,
                    rr.return_order_id,
                    TRIM(
                        CONCAT_WS(
                            ' ',
                            u.user_first_name,
                            u.user_last_name
                        )
                    ) AS customer_name,
                    p.product_title,
                    rr.return_reason,
                    rr.return_status,
                    DATE_FORMAT(
                        rr.return_created_at,
                        '%Y-%m-%d %H:%i:%s'
                    ) AS return_created_at
                FROM return_requests rr
                JOIN users u
                    ON u.user_id = rr.return_user_id
                JOIN order_items oi
                    ON oi.order_item_id =
                        rr.return_item_id
                JOIN products p
                    ON p.product_id =
                        oi.order_item_product_id
                WHERE rr.return_created_at >= ?
                AND rr.return_created_at <
                    DATE_ADD(?, INTERVAL 1 DAY)
                ORDER BY rr.return_created_at DESC
            ");
            $statement->execute([
                $from_date,
                $to_date,
            ]);
            $rows = $statement->fetchAll(
                PDO::FETCH_ASSOC
            );

            foreach ($rows as &$row) {
                $row['return_number'] =
                    'RTN-' .
                    str_pad(
                        (string) ((int) $row['return_id']),
                        4,
                        '0',
                        STR_PAD_LEFT
                    );
                $row['order_number'] =
                    'ORD-' .
                    str_pad(
                        (string) (
                            (int) $row['return_order_id']
                        ),
                        4,
                        '0',
                        STR_PAD_LEFT
                    );
                unset(
                    $row['return_id'],
                    $row['return_order_id']
                );
            }
            unset($row);

            return [$columns, $rows];
    }

    throw new RuntimeException(
        'Invalid report type.'
    );
}

$default_from = date('Y-m-01');
$default_to = date('Y-m-d');

$report_raw = $_GET['report'] ?? 'sales';
$report =
    is_string($report_raw) &&
    isset($report_types[$report_raw])
        ? $report_raw
        : 'sales';

$from_date = normalizeReportDate(
    $_GET['from'] ?? null,
    $default_from
);
$to_date = normalizeReportDate(
    $_GET['to'] ?? null,
    $default_to
);

$error = '';

if ($from_date > $to_date) {
    $error =
        'The start date cannot be later than the end date.';
    $from_date = $default_from;
    $to_date = $default_to;
}

try {
    [$columns, $rows] = getReportData(
        $pdo,
        $report,
        $from_date,
        $to_date
    );
} catch (Throwable $e) {
    app_error_log(
        'Report generation failed: ' .
        $e->getMessage()
    );
    $columns = [];
    $rows = [];
    $error =
        'Unable to generate the report. Please try again.';
}

$export_raw = $_GET['export'] ?? '';
$export =
    is_string($export_raw)
        ? $export_raw
        : '';

if ($export === 'csv' && $error === '') {
    $filename =
        'mangavault_' .
        $report .
        '_report_' .
        $from_date .
        '_to_' .
        $to_date .
        '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="' .
        $filename .
        '"'
    );
    header('X-Content-Type-Options: nosniff');

    $output = fopen('php://output', 'wb');

    if ($output === false) {
        http_response_code(500);
        exit('Unable to generate the CSV file.');
    }

    fwrite($output, "\xEF\xBB\xBF");

    fputcsv(
        $output,
        [
            'MangaVault',
            $report_types[$report]['title'],
        ],
        ',',
        '"',
        ''
    );

    if ($report === 'inventory') {
        fputcsv(
            $output,
            [
                'Generated At',
                date('Y-m-d H:i:s'),
            ],
            ',',
            '"',
            ''
        );
    } else {
        fputcsv(
            $output,
            [
                'Date Range',
                $from_date . ' to ' . $to_date,
            ],
            ',',
            '"',
            ''
        );
    }

    fputcsv($output, [], ',', '"', '');
    fputcsv(
        $output,
        array_values($columns),
        ',',
        '"',
        ''
    );

    foreach ($rows as $row) {
        $csv_row = [];

        foreach ($columns as $key => $label) {
            $csv_row[] = reportCsvValue(
                $key,
                $row[$key] ?? null
            );
        }

        fputcsv(
            $output,
            $csv_row,
            ',',
            '"',
            ''
        );
    }

    fclose($output);
    exit;
}

$summary_cards = [
    [
        'label' => 'Report Rows',
        'value' => number_format(count($rows)),
        'detail' => 'Records in this report',
    ],
];

if ($report === 'sales') {
    $revenue_sen = 0;
    $units = 0;

    foreach ($rows as $row) {
        try {
            $revenue_sen += moneyDecimalToSen(
                (string) $row['order_total_amount']
            );
        } catch (MoneyValueException) {
        }

        $units += (int) $row['units'];
    }

    $summary_cards[] = [
        'label' => 'Total Revenue',
        'value' => 'RM ' . moneyFormatSen($revenue_sen),
        'detail' => 'Confirmed non-cancelled sales',
    ];
    $summary_cards[] = [
        'label' => 'Units Sold',
        'value' => number_format($units),
        'detail' => 'Across all listed orders',
    ];
} elseif ($report === 'product_sales') {
    $revenue_sen = 0;
    $units = 0;

    foreach ($rows as $row) {
        try {
            $revenue_sen += moneyDecimalToSen(
                (string) $row['revenue']
            );
        } catch (MoneyValueException) {
        }

        $units += (int) $row['units_sold'];
    }

    $summary_cards[] = [
        'label' => 'Product Revenue',
        'value' => 'RM ' . moneyFormatSen($revenue_sen),
        'detail' => 'Revenue from listed products',
    ];
    $summary_cards[] = [
        'label' => 'Units Sold',
        'value' => number_format($units),
        'detail' => 'Total product quantity sold',
    ];
} elseif ($report === 'inventory') {
    $stock_units = 0;
    $low_stock_count = 0;

    foreach ($rows as $row) {
        $stock_units += (int) $row['stock_quantity'];

        if (
            in_array(
                $row['stock_status'],
                ['Low Stock', 'Out of Stock'],
                true
            )
        ) {
            $low_stock_count++;
        }
    }

    $summary_cards[] = [
        'label' => 'Stock Units',
        'value' => number_format($stock_units),
        'detail' => 'Current physical stock',
    ];
    $summary_cards[] = [
        'label' => 'Low / Out of Stock',
        'value' => number_format($low_stock_count),
        'detail' => 'Products requiring attention',
    ];
} elseif ($report === 'customers') {
    $active_count = 0;
    $confirmed_spending_sen = 0;

    foreach ($rows as $row) {
        if ((int) $row['is_active'] === 1) {
            $active_count++;
        }

        try {
            $confirmed_spending_sen += moneyDecimalToSen(
                (string) $row['total_spent']
            );
        } catch (MoneyValueException) {
        }
    }

    $summary_cards[] = [
        'label' => 'Active Customers',
        'value' => number_format($active_count),
        'detail' => 'Active accounts in the report',
    ];
    $summary_cards[] = [
        'label' => 'Confirmed Spending',
        'value' =>
            'RM ' .
            moneyFormatSen($confirmed_spending_sen),
        'detail' => 'Spending by listed customers',
    ];
} elseif ($report === 'suppliers') {
    $completed_pos = 0;
    $total_spend_sen = 0;

    foreach ($rows as $row) {
        $completed_pos += (int) $row['completed_pos'];

        try {
            $total_spend_sen += moneyDecimalToSen(
                (string) $row['total_spend']
            );
        } catch (MoneyValueException) {
        }
    }

    $summary_cards[] = [
        'label' => 'Completed POs',
        'value' => number_format($completed_pos),
        'detail' => 'Completed within the date range',
    ];
    $summary_cards[] = [
        'label' => 'Completed Spend',
        'value' => 'RM ' . moneyFormatSen($total_spend_sen),
        'detail' => 'Completed purchase-order value',
    ];
} elseif ($report === 'returns') {
    $pending_count = 0;
    $approved_count = 0;

    foreach ($rows as $row) {
        if ($row['return_status'] === 'pending') {
            $pending_count++;
        } elseif ($row['return_status'] === 'approved') {
            $approved_count++;
        }
    }

    $summary_cards[] = [
        'label' => 'Pending Returns',
        'value' => number_format($pending_count),
        'detail' => 'Awaiting admin review',
    ];
    $summary_cards[] = [
        'label' => 'Approved Returns',
        'value' => number_format($approved_count),
        'detail' => 'Approved within the date range',
    ];
}

$query_parameters = http_build_query([
    'report' => $report,
    'from' => $from_date,
    'to' => $to_date,
]);
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
        <?= htmlspecialchars(
            $report_types[$report]['title'],
            ENT_QUOTES,
            'UTF-8'
        ) ?> - MangaVault Admin
    </title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            opacity: 0;
            animation: fadeIn 0.25s ease forwards;
        }

        @keyframes fadeIn {
            to {
                opacity: 1;
            }
        }

        .print-only {
            display: none;
        }

        .report-tabs::-webkit-scrollbar {
            height: 5px;
        }

        .report-tabs::-webkit-scrollbar-thumb {
            background: #d1d5db;
            border-radius: 999px;
        }

        @page {
            size: A4 landscape;
            margin: 13mm 12mm 16mm;
        }

        @media print {
            aside,
            nav,
            header,
            .no-print,
            #adminSidebarOverlay,
            #adminSharedSidebar {
                display: none !important;
            }

            html,
            body {
                width: 100%;
                min-height: auto;
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
                color: #172033 !important;
                opacity: 1 !important;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }

            .print-only {
                display: block !important;
            }

            .report-wrapper {
                max-width: none !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .print-report-header {
                border-bottom: 4px solid #dc2626;
                padding-bottom: 14px;
                margin-bottom: 16px;
            }

            .print-brand {
                display: flex !important;
                align-items: flex-start;
                justify-content: space-between;
                gap: 24px;
            }

            .print-brand-name {
                font-size: 25px !important;
                font-weight: 900 !important;
                letter-spacing: 0.03em;
                color: #17243d !important;
            }

            .print-brand-name span {
                color: #dc2626 !important;
            }

            .print-report-title {
                margin-top: 10px;
                font-size: 18px !important;
                font-weight: 800 !important;
                color: #17243d !important;
            }

            .print-report-description {
                margin-top: 3px;
                font-size: 9px !important;
                color: #64748b !important;
            }

            .print-meta {
                min-width: 245px;
                border: 1px solid #dbe1ea;
                border-radius: 8px;
                overflow: hidden;
                font-size: 8.5px !important;
            }

            .print-meta-row {
                display: flex !important;
                justify-content: space-between;
                gap: 18px;
                padding: 6px 9px;
                border-bottom: 1px solid #e5e7eb;
            }

            .print-meta-row:last-child {
                border-bottom: 0;
            }

            .print-meta-label {
                color: #64748b !important;
                font-weight: 600;
            }

            .print-meta-value {
                color: #17243d !important;
                font-weight: 700;
                text-align: right;
            }

            .print-summary {
                display: grid !important;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 9px;
                margin-bottom: 16px;
            }

            .print-summary-card {
                border: 1px solid #dbe1ea;
                border-left: 4px solid #dc2626;
                border-radius: 7px;
                padding: 9px 11px;
                background: #f8fafc !important;
            }

            .print-summary-label {
                font-size: 7.5px !important;
                font-weight: 700 !important;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                color: #64748b !important;
            }

            .print-summary-value {
                margin-top: 3px;
                font-size: 15px !important;
                font-weight: 900 !important;
                color: #17243d !important;
            }

            .print-summary-detail {
                margin-top: 2px;
                font-size: 7.5px !important;
                color: #94a3b8 !important;
            }

            .report-table {
                border: 1px solid #cbd5e1 !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                overflow: visible !important;
            }

            .screen-report-heading {
                display: none !important;
            }

            .report-table-scroll {
                overflow: visible !important;
            }

            table {
                width: 100% !important;
                border-collapse: collapse !important;
                table-layout: auto;
            }

            thead {
                display: table-header-group;
            }

            tfoot {
                display: table-footer-group;
            }

            tr {
                page-break-inside: avoid;
            }

            th {
                background: #17243d !important;
                color: #ffffff !important;
                border: 1px solid #31415f !important;
                font-size: 7.4px !important;
                font-weight: 800 !important;
                letter-spacing: 0.045em;
                padding: 6px 5px !important;
                white-space: normal !important;
            }

            td {
                border: 1px solid #dbe1ea !important;
                color: #334155 !important;
                font-size: 7.6px !important;
                line-height: 1.3 !important;
                padding: 5px !important;
                white-space: normal !important;
                vertical-align: top;
            }

            tbody tr:nth-child(even) td {
                background: #f8fafc !important;
            }

            td span {
                border: 0 !important;
                border-radius: 4px !important;
                padding: 2px 4px !important;
                font-size: 7px !important;
            }

            .print-footer {
                display: flex !important;
                position: fixed;
                bottom: -10mm;
                left: 0;
                right: 0;
                justify-content: space-between;
                border-top: 1px solid #cbd5e1;
                padding-top: 5px;
                font-size: 7px !important;
                color: #64748b !important;
            }
        }
    </style>
</head>
<body class="bg-[#f5f6fa] min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div
        class="report-wrapper max-w-7xl mx-auto px-5 md:px-7 py-6"
    >
        <section class="print-only print-report-header">
            <div class="print-brand">
                <div>
                    <div class="print-brand-name">
                        MANGA<span>VAULT</span>
                    </div>
                    <div class="print-report-title">
                        <?= htmlspecialchars(
                            $report_types[$report]['title'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </div>
                    <div class="print-report-description">
                        <?= htmlspecialchars(
                            $report_types[$report]['description'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </div>
                </div>

                <div class="print-meta">
                    <div class="print-meta-row">
                        <span class="print-meta-label">
                            Report Period
                        </span>
                        <span class="print-meta-value">
                            <?= $report === 'inventory'
                                ? 'Current inventory position'
                                : htmlspecialchars(
                                    date(
                                        'd M Y',
                                        strtotime($from_date)
                                    ) .
                                    ' – ' .
                                    date(
                                        'd M Y',
                                        strtotime($to_date)
                                    ),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                        </span>
                    </div>

                    <div class="print-meta-row">
                        <span class="print-meta-label">
                            Generated
                        </span>
                        <span class="print-meta-value">
                            <?= date('d M Y, h:i A') ?>
                        </span>
                    </div>

                    <div class="print-meta-row">
                        <span class="print-meta-label">
                            Prepared By
                        </span>
                        <span class="print-meta-value">
                            <?= htmlspecialchars(
                                (string) (
                                    $_SESSION['user_name']
                                    ?? 'MangaVault Admin'
                                ),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </span>
                    </div>

                    <div class="print-meta-row">
                        <span class="print-meta-label">
                            Total Records
                        </span>
                        <span class="print-meta-value">
                            <?= number_format(count($rows)) ?>
                        </span>
                    </div>
                </div>
            </div>
        </section>

        <section class="print-only print-summary">
            <?php foreach ($summary_cards as $card): ?>
            <div class="print-summary-card">
                <div class="print-summary-label">
                    <?= htmlspecialchars(
                        $card['label'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </div>
                <div class="print-summary-value">
                    <?= htmlspecialchars(
                        $card['value'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </div>
                <div class="print-summary-detail">
                    <?= htmlspecialchars(
                        $card['detail'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </section>

        <div class="print-only print-footer">
            <span>
                MangaVault Administration · Business Report
            </span>
            <span>
                Generated <?= date('d M Y') ?>
            </span>
        </div>

        <section
            class="no-print flex flex-col lg:flex-row lg:items-center justify-between gap-4 mb-5"
        >
            <div>
                <h1
                    class="text-2xl font-black text-gray-900"
                >
                    Reports Center
                </h1>
                <p class="text-sm text-gray-400 mt-1">
                    Generate and export operational reports.
                </p>
            </div>

            <div
                class="inline-flex items-center gap-2 text-xs text-gray-400"
            >
                <span
                    class="w-2 h-2 rounded-full bg-emerald-500"
                ></span>
                <?= number_format(count($rows)) ?>
                records in the current report
            </div>
        </section>

        <nav
            class="no-print report-tabs flex gap-2 overflow-x-auto pb-2 mb-4"
            aria-label="Report types"
        >
            <?php foreach ($report_types as $key => $config): ?>
            <a
                href="reports.php?<?= http_build_query([
                    'report' => $key,
                    'from' => $from_date,
                    'to' => $to_date,
                ]) ?>"
                class="<?= $report === $key
                    ? 'bg-[#17243d] text-white border-[#17243d]'
                    : 'bg-white text-gray-600 border-gray-200 hover:border-red-300 hover:text-red-600' ?> flex-shrink-0 inline-flex items-center gap-2 border rounded-xl px-3.5 py-2.5 text-xs font-bold transition-colors"
            >
                <span><?= $config['icon'] ?></span>
                <span>
                    <?= htmlspecialchars(
                        str_replace(
                            [
                                ' Report',
                                'Supplier Performance',
                            ],
                            [
                                '',
                                'Suppliers',
                            ],
                            $config['title']
                        ),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </span>
            </a>
            <?php endforeach; ?>
        </nav>

        <section
            class="no-print bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-5"
        >
            <form
                method="GET"
                class="flex flex-col xl:flex-row xl:items-end gap-3"
            >
                <input
                    type="hidden"
                    name="report"
                    value="<?= htmlspecialchars(
                        $report,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <div class="flex-1 min-w-0">
                    <p
                        class="text-sm font-black text-gray-800"
                    >
                        <?= htmlspecialchars(
                            $report_types[$report]['title'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>
                    <p class="text-xs text-gray-400 mt-1">
                        <?= htmlspecialchars(
                            $report_types[$report]['description'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>
                </div>

                <div
                    class="grid grid-cols-1 sm:grid-cols-2 gap-3 xl:w-[410px]"
                >
                    <label>
                        <span
                            class="block text-[10px] font-bold uppercase tracking-wide text-gray-400 mb-1.5"
                        >
                            Start Date
                        </span>
                        <input
                            type="date"
                            name="from"
                            value="<?= htmlspecialchars(
                                $from_date,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            class="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400"
                        >
                    </label>

                    <label>
                        <span
                            class="block text-[10px] font-bold uppercase tracking-wide text-gray-400 mb-1.5"
                        >
                            End Date
                        </span>
                        <input
                            type="date"
                            name="to"
                            value="<?= htmlspecialchars(
                                $to_date,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            class="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400"
                        >
                    </label>
                </div>

                <div
                    class="flex flex-wrap sm:flex-nowrap gap-2"
                >
                    <button
                        type="submit"
                        class="bg-red-600 hover:bg-red-700 text-white text-sm font-bold px-4 py-2.5 rounded-xl transition-colors"
                    >
                        Generate
                    </button>

                    <a
                        href="reports.php?<?= htmlspecialchars(
                            $query_parameters .
                            '&export=csv',
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-4 py-2.5 rounded-xl transition-colors"
                    >
                        Download CSV
                    </a>

                    <button
                        type="button"
                        onclick="window.print()"
                        class="bg-[#17243d] hover:bg-[#223454] text-white text-sm font-bold px-4 py-2.5 rounded-xl transition-colors"
                    >
                        Save PDF
                    </button>
                </div>
            </form>

            <?php if ($report === 'inventory'): ?>
            <p class="text-xs text-amber-600 mt-3">
                Inventory reports display the current stock
                position and are not affected by the date range.
            </p>
            <?php endif; ?>
        </section>

        <?php if ($error !== ''): ?>
        <div
            class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-5"
        >
            <?= htmlspecialchars(
                $error,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>
        <?php endif; ?>

        <section
            class="no-print grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5"
        >
            <?php foreach ($summary_cards as $card): ?>
            <div
                class="bg-white rounded-2xl border border-gray-100 shadow-sm px-4 py-3.5 flex items-center justify-between gap-4"
            >
                <div class="min-w-0">
                    <p
                        class="text-[10px] font-bold text-gray-400 uppercase tracking-wide"
                    >
                        <?= htmlspecialchars(
                            $card['label'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>
                    <p
                        class="text-xl font-black text-gray-900 mt-1"
                    >
                        <?= htmlspecialchars(
                            $card['value'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>
                </div>

                <p
                    class="hidden lg:block text-[11px] text-gray-400 text-right max-w-36"
                >
                    <?= htmlspecialchars(
                        $card['detail'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>
            </div>
            <?php endforeach; ?>
        </section>

        <section
            class="report-table bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden"
        >
            <div
                class="screen-report-heading px-5 py-4 border-b border-gray-100 flex flex-col md:flex-row md:items-center justify-between gap-2"
            >
                <div>
                    <h2
                        class="font-black text-gray-900"
                    >
                        <?= htmlspecialchars(
                            $report_types[$report]['title'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </h2>
                    <p class="text-xs text-gray-400 mt-1">
                        <?= $report === 'inventory'
                            ? 'Current inventory position'
                            : htmlspecialchars(
                                date(
                                    'd M Y',
                                    strtotime($from_date)
                                ) .
                                ' – ' .
                                date(
                                    'd M Y',
                                    strtotime($to_date)
                                ),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                    </p>
                </div>

                <p class="text-xs text-gray-400">
                    Generated <?= date('d M Y, h:i A') ?>
                </p>
            </div>

            <?php if (!$rows): ?>
            <div class="text-center py-14">
                <div
                    class="w-12 h-12 mx-auto rounded-2xl bg-gray-100 text-gray-400 flex items-center justify-center text-xl mb-3"
                >
                    ▤
                </div>
                <p class="text-gray-600 font-semibold">
                    No records found
                </p>
                <p class="text-xs text-gray-400 mt-1">
                    Change the report period and generate it again.
                </p>
            </div>
            <?php else: ?>
            <div class="report-table-scroll overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-gray-50">
                            <?php foreach (
                                $columns as $label
                            ): ?>
                            <th
                                class="px-4 py-3 text-left text-[10px] font-bold text-gray-500 uppercase tracking-wide whitespace-nowrap"
                            >
                                <?= htmlspecialchars(
                                    $label,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($rows as $row): ?>
                        <tr
                            class="border-t border-gray-100 hover:bg-gray-50/60"
                        >
                            <?php foreach (
                                $columns as $key => $label
                            ): ?>
                            <td
                                class="px-4 py-3 text-xs text-gray-600 align-top <?= $key === 'return_reason'
                                    ? 'min-w-64 max-w-md whitespace-normal'
                                    : 'whitespace-nowrap' ?>"
                            >
                                <?php
                                $display_value =
                                    reportDisplayValue(
                                        $key,
                                        $row[$key] ?? null
                                    );
                                ?>

                                <?php if (
                                    in_array(
                                        $key,
                                        [
                                            'order_status',
                                            'payment_status',
                                            'return_status',
                                            'stock_status',
                                            'availability',
                                            'supplier_status',
                                            'is_active',
                                        ],
                                        true
                                    )
                                ): ?>
                                <span
                                    class="inline-flex px-2.5 py-1 rounded-full bg-gray-100 text-gray-700 text-[10px] font-bold capitalize"
                                >
                                    <?= htmlspecialchars(
                                        $display_value,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>
                                <?php else: ?>
                                <?= htmlspecialchars(
                                    $display_value,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
    </div>

</body>
</html>