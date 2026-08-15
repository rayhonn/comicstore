<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/voucher_helper.php';
require_once __DIR__ . '/stock_helper.php';
require_once __DIR__ . '/wallet_helper.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/birthday_helper.php';

$nav_home_url = app_path('index.php');
$nav_catalog_url = app_path('customer/home.php');
$nav_membership_url = app_path('tier.php');
$nav_about_url = app_path('customer/about.php');
$nav_faq_url = app_path('customer/faq.php');
$nav_notifications_url = app_path('customer/notifications.php');
$nav_cart_url = app_path('customer/cart.php');
$nav_dashboard_url = app_path('customer/dashboard.php');
$nav_login_url = app_path('login.php');
$nav_register_url = app_path('register.php');

$nav_session_activity_url =
    app_path(
        'customer/session_activity.php'
    );

$nav_session_expired_url =
    app_path(
        'session_expired.php?idle=1'
    );

// Auto-check one expired pending confirmation order.
if (isset($_SESSION['user_id'])) {
    $user_id = (int) $_SESSION['user_id'];
    $expired_order = null;

    try {
        $pdo->beginTransaction();

        $expired_orders = $pdo->prepare("
            SELECT
                order_id,
                order_total_amount,
                order_voucher_code
            FROM orders
            WHERE order_user_id = ?
            AND order_payment_status =
                'pending_confirmation'
            AND order_confirm_expires_at < NOW()
            ORDER BY order_confirm_expires_at ASC
            LIMIT 1
            FOR UPDATE
        ");

        $expired_orders->execute([
            $user_id,
        ]);

        $expired_order = $expired_orders->fetch(
            PDO::FETCH_ASSOC
        );

        if ($expired_order) {
            $order_id =
                (int) $expired_order['order_id'];

            $cancel_order = $pdo->prepare("
                UPDATE orders
                SET order_payment_status = 'cancelled',
                    order_status = 'cancelled'
                WHERE order_id = ?
                AND order_user_id = ?
                AND order_payment_status =
                    'pending_confirmation'
                AND order_confirm_expires_at < NOW()
            ");

            $cancel_order->execute([
                $order_id,
                $user_id,
            ]);

            if ($cancel_order->rowCount() !== 1) {
                throw new RuntimeException(
                    'Expired order has already been processed.'
                );
            }

            restoreOrderPhysicalStock(
                $pdo,
                $order_id
            );

            restoreOrderVoucherUsage(
                $pdo,
                $expired_order[
                    'order_voucher_code'
                ] ?? null,
                $order_id,
                $user_id
            );

            creditWalletRefund(
                $pdo,
                $user_id,
                'payment_timeout',
                $order_id,
                moneyDecimalToSen(
                    (string) $expired_order[
                        'order_total_amount'
                    ]
                ),
                'Refund for expired payment confirmation Order #' .
                    str_pad(
                        (string) $order_id,
                        4,
                        '0',
                        STR_PAD_LEFT
                    )
            );
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        app_error_log(
            'Navbar expired order cleanup failed: ' .
            $e->getMessage()
        );

        $expired_order = null;
    }

    if ($expired_order) {
        $order_number =
            '#' . str_pad(
                (string) $expired_order['order_id'],
                4,
                '0',
                STR_PAD_LEFT
            );

        try {
            sendNotification(
                $pdo,
                $user_id,
                'Payment Confirmation Timeout',
                "Your order $order_number has been cancelled because the payment confirmation link expired. The paid amount has been refunded to your MangaVault Wallet. Stock and any applied voucher have been restored.",
                'order'
            );
        } catch (Throwable $e) {
            app_error_log(
                'Navbar cancellation notification failed: ' .
                $e->getMessage()
            );
        }
    }
}


if (isset($_SESSION['user_id'])) {
    try {
        awardAnnualBirthdayBonus(
            $pdo,
            (int) $_SESSION['user_id']
        );
    } catch (Throwable $e) {
        app_error_log(
            'Navbar birthday bonus processing failed: ' .
            $e->getMessage()
        );
    }
}

$cart_count = 0;
$notification_count = 0;

if (isset($_SESSION['user_id'])) {
    $user_id = (int) $_SESSION['user_id'];

    $cart_count_stmt = $pdo->prepare("
        SELECT COALESCE(
            SUM(cart_item_quantity),
            0
        )
        FROM cart_items
        WHERE cart_item_user_id = ?
    ");
    $cart_count_stmt->execute([
        $user_id,
    ]);
    $cart_count =
        (int) $cart_count_stmt->fetchColumn();

    $notification_count_stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM notifications
        WHERE notif_user_id = ?
        AND notif_is_read = 0
    ");
    $notification_count_stmt->execute([
        $user_id,
    ]);
    $notification_count =
        (int) $notification_count_stmt->fetchColumn();
}

$nav_mobile_account_links = [
    [
        'label' => 'Dashboard',
        'url' => app_path(
            'customer/dashboard.php'
        ),
    ],
    [
        'label' => 'Order History',
        'url' => app_path(
            'customer/orders.php'
        ),
    ],
    [
        'label' => 'Wishlist',
        'url' => app_path(
            'customer/wishlist.php'
        ),
    ],
    [
        'label' => 'My Collection',
        'url' => app_path(
            'customer/collection.php'
        ),
    ],
    [
        'label' => 'Returns',
        'url' => app_path(
            'customer/returns.php'
        ),
    ],
    [
        'label' => 'My Wallet',
        'url' => app_path(
            'customer/wallet.php'
        ),
    ],
    [
        'label' => 'My Addresses',
        'url' => app_path(
            'customer/addresses.php'
        ),
    ],
    [
        'label' => 'Vouchers & Points',
        'url' => app_path(
            'customer/vouchers.php'
        ),
    ],
    [
        'label' => 'My Reviews',
        'url' => app_path(
            'customer/my_reviews.php'
        ),
    ],
    [
        'label' => 'My Profile',
        'url' => app_path(
            'customer/profile.php'
        ),
    ],
    [
        'label' => 'Notifications',
        'url' => $nav_notifications_url,
        'badge' => $notification_count,
    ],
];
?>

<style>
    @import url("<?= htmlspecialchars(
        app_path(
            'assets/css/customer_mobile.css?v=2'
        ),
        ENT_QUOTES,
        'UTF-8'
    ) ?>");

    @keyframes bellRing {
        0%,
        100% {
            transform: rotate(0deg);
        }

        20% {
            transform: rotate(13deg);
        }

        40% {
            transform: rotate(-11deg);
        }

        60% {
            transform: rotate(7deg);
        }

        80% {
            transform: rotate(-4deg);
        }
    }

    .bell-ring {
        display: inline-block;
        transform-origin: top center;
        animation: bellRing 1.2s ease infinite;
    }
</style>

<nav class="sticky top-0 z-50 bg-white shadow-sm">
    <div
        class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 sm:py-4"
    >
        <a
            href="<?= htmlspecialchars(
                $nav_home_url,
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
            class="text-lg font-black tracking-wide text-gray-900 sm:text-xl"
        >
            MANGA<span class="text-red-600">VAULT</span>
        </a>

        <div
            class="hidden items-center gap-8 text-sm font-medium lg:flex"
        >
            <a
                href="<?= htmlspecialchars(
                    $nav_home_url,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                class="text-gray-600 transition-colors hover:text-red-600"
            >
                Home
            </a>

            <a
                href="<?= htmlspecialchars(
                    $nav_catalog_url,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                class="text-gray-600 transition-colors hover:text-red-600"
            >
                Catalog
            </a>

            <a
                href="<?= htmlspecialchars(
                    $nav_membership_url,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                class="text-gray-600 transition-colors hover:text-red-600"
            >
                Membership
            </a>

            <a
                href="<?= htmlspecialchars(
                    $nav_about_url,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                class="text-gray-600 transition-colors hover:text-red-600"
            >
                About Us
            </a>

            <a
                href="<?= htmlspecialchars(
                    $nav_faq_url,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                class="text-gray-600 transition-colors hover:text-red-600"
            >
                FAQ
            </a>
        </div>

        <div class="flex items-center gap-3 text-sm sm:gap-4">
            <?php if (isset($_SESSION['user_id'])): ?>
                <a
                    href="<?= htmlspecialchars(
                        $nav_notifications_url,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    class="relative text-gray-600 transition-colors hover:text-red-600"
                    aria-label="Notifications"
                >
                    <svg
                        class="h-6 w-6 <?= $notification_count > 0
                            ? 'bell-ring'
                            : '' ?>"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"
                        ></path>
                    </svg>

                    <?php if ($notification_count > 0): ?>
                        <span
                            class="absolute -right-2 -top-2 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-xs text-white"
                        >
                            <?= $notification_count > 99
                                ? '99+'
                                : $notification_count ?>
                        </span>
                    <?php endif; ?>
                </a>

                <a
                    href="<?= htmlspecialchars(
                        $nav_cart_url,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    class="relative text-gray-600 transition-colors hover:text-red-600"
                    aria-label="Shopping cart"
                >
                    <svg
                        class="h-6 w-6"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"
                        ></path>
                    </svg>

                    <?php if ($cart_count > 0): ?>
                        <span
                            class="absolute -right-2 -top-2 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-xs text-white"
                        >
                            <?= $cart_count > 99
                                ? '99+'
                                : $cart_count ?>
                        </span>
                    <?php endif; ?>
                </a>

                <a
                    href="<?= htmlspecialchars(
                        $nav_dashboard_url,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    class="hidden font-medium text-gray-600 transition-colors hover:text-red-600 lg:block"
                >
                    Hi,
                    <?= htmlspecialchars(
                        (string) (
                            $_SESSION['user_first_name'] ??
                            $_SESSION['user_name'] ??
                            'Customer'
                        ),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>!
                </a>
            <?php else: ?>
                <a
                    href="<?= htmlspecialchars(
                        $nav_login_url,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    class="hidden font-medium text-gray-600 transition-colors hover:text-red-600 lg:block"
                >
                    Login
                </a>

                <a
                    href="<?= htmlspecialchars(
                        $nav_register_url,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    class="hidden rounded-lg bg-red-600 px-4 py-2 font-semibold text-white transition-colors hover:bg-red-700 lg:block"
                >
                    Register
                </a>
            <?php endif; ?>

            <button
                id="navMenuBtn"
                type="button"
                class="flex h-10 w-10 flex-col items-center justify-center gap-1.5 rounded-xl p-2 focus:outline-none focus:ring-2 focus:ring-red-100 lg:hidden"
                aria-label="Open navigation menu"
                aria-expanded="false"
                aria-controls="navMobileMenu"
            >
                <span
                    class="h-0.5 w-6 rounded bg-gray-700 transition-all duration-300"
                ></span>
                <span
                    class="h-0.5 w-6 rounded bg-gray-700 transition-all duration-300"
                ></span>
                <span
                    class="h-0.5 w-6 rounded bg-gray-700 transition-all duration-300"
                ></span>
            </button>
        </div>
    </div>

    <div
        id="navMobileMenu"
        class="fixed right-0 top-0 z-[60] h-[100dvh] w-[min(88vw,380px)] translate-x-full overflow-y-auto bg-white shadow-2xl transition-transform duration-300 ease-out lg:hidden"
        aria-hidden="true"
    >
        <div
            class="sticky top-0 z-10 flex items-center justify-between border-b border-gray-100 bg-white/95 px-5 py-4 backdrop-blur"
        >
            <div>
                <p
                    class="text-[10px] font-bold uppercase tracking-[0.18em] text-gray-400"
                >
                    MangaVault
                </p>

                <p
                    class="mt-0.5 text-base font-black text-gray-800"
                >
                    Navigation
                </p>
            </div>

            <button
                type="button"
                id="navMenuCloseBtn"
                class="flex h-10 w-10 items-center justify-center rounded-xl bg-gray-50 text-xl text-gray-500 transition-colors hover:bg-red-50 hover:text-red-600"
                aria-label="Close navigation menu"
            >
                ×
            </button>
        </div>

        <div class="space-y-2 px-5 py-4 pb-8">
            <a
                href="<?= htmlspecialchars(
                    $nav_home_url,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                class="block py-2 text-sm text-gray-600 hover:text-red-600"
            >
                Home
            </a>

            <a
                href="<?= htmlspecialchars(
                    $nav_catalog_url,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                class="block py-2 text-sm text-gray-600 hover:text-red-600"
            >
                Catalog
            </a>

            <a
                href="<?= htmlspecialchars(
                    $nav_membership_url,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                class="block py-2 text-sm text-gray-600 hover:text-red-600"
            >
                Membership
            </a>

            <a
                href="<?= htmlspecialchars(
                    $nav_about_url,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                class="block py-2 text-sm text-gray-600 hover:text-red-600"
            >
                About Us
            </a>

            <a
                href="<?= htmlspecialchars(
                    $nav_faq_url,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                class="block py-2 text-sm text-gray-600 hover:text-red-600"
            >
                FAQ
            </a>

            <?php if (isset($_SESSION['user_id'])): ?>
                <div
                    class="mt-2 border-t border-gray-100 pt-4"
                >
                    <div
                        class="mb-2 flex items-center justify-between"
                    >
                        <p
                            class="text-[11px] font-bold uppercase tracking-[0.16em] text-gray-400"
                        >
                            My Account
                        </p>

                        <span
                            class="rounded-full bg-red-50 px-2 py-1 text-[10px] font-bold text-red-600"
                        >
                            Customer
                        </span>
                    </div>

                    <div
                        class="grid grid-cols-2 gap-2"
                    >
                        <?php foreach (
                            $nav_mobile_account_links
                            as $account_link
                        ): ?>
                            <a
                                href="<?= htmlspecialchars(
                                    $account_link['url'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                class="flex min-h-[44px] items-center justify-between rounded-xl bg-gray-50 px-3 py-2.5 text-xs font-semibold text-gray-600 transition-colors hover:bg-red-50 hover:text-red-600"
                            >
                                <span>
                                    <?= htmlspecialchars(
                                        $account_link['label'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>

                                <?php if (
                                    !empty(
                                        $account_link[
                                            'badge'
                                        ]
                                    )
                                ): ?>
                                    <span
                                        class="ml-2 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] text-white"
                                    >
                                        <?= (int)
                                            $account_link[
                                                'badge'
                                            ] ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <a
                    href="<?= htmlspecialchars(
                        $nav_login_url,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    class="block py-2 text-sm text-gray-600 hover:text-red-600"
                >
                    Login
                </a>

                <a
                    href="<?= htmlspecialchars(
                        $nav_register_url,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    class="block py-2 text-sm font-semibold text-red-600 hover:text-red-700"
                >
                    Register
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div
        id="navOverlay"
        class="fixed inset-0 z-[55] hidden bg-black/40 backdrop-blur-[1px] lg:hidden"
    ></div>
</nav>

<?php include __DIR__ . '/chatbot_widget.php'; ?>

<script>
    const customerPageFile =
        window.location.pathname
            .split('/')
            .pop() || 'index.php';

    document.body.dataset.customerPage =
        customerPageFile.replace(
            /\.php$/i,
            ''
        );

    const navMenuButton =
        document.getElementById('navMenuBtn');
    const navMobileMenu =
        document.getElementById('navMobileMenu');
    const navOverlay =
        document.getElementById(
            'navOverlay'
        );

    const navMenuCloseButton =
        document.getElementById(
            'navMenuCloseBtn'
        );

    function openNavMenu() {
        if (
            !navMobileMenu ||
            !navOverlay
        ) {
            return;
        }

        navMobileMenu.classList.remove(
            'translate-x-full'
        );

        navMobileMenu.setAttribute(
            'aria-hidden',
            'false'
        );

        navOverlay.classList.remove(
            'hidden'
        );

        document.body.classList.add(
            'overflow-hidden'
        );

        if (navMenuButton) {
            navMenuButton.setAttribute(
                'aria-expanded',
                'true'
            );
        }
    }

    function closeNavMenu() {
        if (
            !navMobileMenu ||
            !navOverlay
        ) {
            return;
        }

        navMobileMenu.classList.add(
            'translate-x-full'
        );

        navMobileMenu.setAttribute(
            'aria-hidden',
            'true'
        );

        navOverlay.classList.add(
            'hidden'
        );

        document.body.classList.remove(
            'overflow-hidden'
        );

        if (navMenuButton) {
            navMenuButton.setAttribute(
                'aria-expanded',
                'false'
            );
        }
    }

    if (
        navMenuButton &&
        navMobileMenu &&
        navOverlay
    ) {
        navMenuButton.addEventListener(
            'click',
            () => {
                const isOpen =
                    !navMobileMenu.classList
                        .contains(
                            'translate-x-full'
                        );

                if (isOpen) {
                    closeNavMenu();
                } else {
                    openNavMenu();
                }
            }
        );

        navOverlay.addEventListener(
            'click',
            closeNavMenu
        );

        navMenuCloseButton
            ?.addEventListener(
                'click',
                closeNavMenu
            );

        document.addEventListener(
            'keydown',
            event => {
                if (
                    event.key ===
                    'Escape'
                ) {
                    closeNavMenu();
                }
            }
        );
    }

    document.querySelectorAll(
        'a[href]'
    ).forEach(link => {
        link.addEventListener(
            'click',
            event => {
                const href =
                    link.getAttribute('href');

                if (
                    !href ||
                    href.startsWith('#') ||
                    href.startsWith('mailto:') ||
                    href.startsWith('tel:') ||
                    link.target === '_blank' ||
                    link.hasAttribute('download')
                ) {
                    return;
                }

                const destination = new URL(
                    link.href,
                    window.location.href
                );

                if (
                    destination.origin !==
                    window.location.origin
                ) {
                    return;
                }

                event.preventDefault();
                document.body.style.opacity = '0';
                document.body.style.transition =
                    'opacity 0.3s ease';

                window.setTimeout(() => {
                    window.location.href =
                        destination.href;
                }, 300);
            }
        );
    });

    <?php if (isset($_SESSION['user_id'])): ?>

        const sessionIdleTimeoutMs =
            <?= APP_SESSION_IDLE_TIMEOUT_SECONDS * 1000 ?>;

        const sessionHeartbeatIntervalMs =
            5 * 60 * 1000;

        const sessionActivityUrl =
            <?= json_encode(
                $nav_session_activity_url
            ) ?>;

        const sessionExpiredUrl =
            <?= json_encode(
                $nav_session_expired_url
            ) ?>;

        let sessionIdleTimer = null;

        let lastSessionHeartbeatAt =
            Date.now();

        let lastHandledActivityAt = 0;

        function scheduleSessionExpiry() {
            if (sessionIdleTimer) {
                window.clearTimeout(
                    sessionIdleTimer
                );
            }

            sessionIdleTimer =
                window.setTimeout(
                    () => {
                        window.location.href =
                            sessionExpiredUrl;
                    },
                    sessionIdleTimeoutMs
                );
        }

        function sendSessionHeartbeat() {
            fetch(
                sessionActivityUrl,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'X-Requested-With':
                            'XMLHttpRequest',
                        'Accept':
                            'application/json',
                    },
                }
            )
            .then(response => {
                if (
                    response.status === 401
                ) {
                    window.location.href =
                        sessionExpiredUrl;
                }
            })
            .catch(() => {
                /*
                 * A temporary network failure should
                 * not expose an application error.
                 * The server-side timeout remains
                 * authoritative.
                 */
            });
        }

        function registerSessionActivity() {
            const now = Date.now();

            if (
                now -
                    lastHandledActivityAt <
                1000
            ) {
                return;
            }

            lastHandledActivityAt = now;

            scheduleSessionExpiry();

            if (
                now -
                    lastSessionHeartbeatAt >=
                sessionHeartbeatIntervalMs
            ) {
                lastSessionHeartbeatAt =
                    now;

                sendSessionHeartbeat();
            }
        }

        [
            'mousemove',
            'mousedown',
            'keydown',
            'touchstart',
        ].forEach(eventName => {
            window.addEventListener(
                eventName,
                registerSessionActivity
            );
        });

        window.addEventListener(
            'scroll',
            registerSessionActivity,
            {
                passive: true,
            }
        );

        scheduleSessionExpiry();

    <?php endif; ?>
    
</script>