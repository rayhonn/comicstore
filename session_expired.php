<?php

require_once __DIR__ .
    '/includes/auth.php';

$forcedIdle =
    ($_GET['idle'] ?? '') === '1';

$serverExpired =
    !empty(
        $_SESSION[
            'auth_session_expired'
        ]
    );

$expiredRole = (string) (
    $_SESSION[
        'auth_expired_role'
    ] ??
    $_SESSION['role'] ??
    'customer'
);

if (
    !$forcedIdle &&
    !$serverExpired
) {
    redirect_to(
        app_path('login.php')
    );
}

$loginUrl = match ($expiredRole) {
    'admin',
    'staff' =>
        app_path('admin/login.php'),

    'supplier' =>
        app_path('supplier/login.php'),

    default =>
        app_path('login.php'),
};

destroy_session();

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
        Session Expired - MangaVault
    </title>

    <script
        src="https://cdn.tailwindcss.com"
    ></script>
</head>

<body
    class="
        min-h-screen
        bg-[#f5f1ec]
        text-gray-800
    "
>
    <main
        class="
            min-h-screen
            flex
            items-center
            justify-center
            px-5
            py-10
        "
    >
        <section
            class="
                w-full
                max-w-lg
                overflow-hidden
                rounded-[28px]
                bg-white
                shadow-[0_24px_70px_rgba(15,23,42,0.14)]
            "
        >
            <div
                class="
                    bg-[#172642]
                    px-8
                    py-6
                "
            >
                <a
                    href="<?= htmlspecialchars(
                        app_path('index.php'),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    class="
                        text-xl
                        font-black
                        tracking-tight
                        text-white
                    "
                >
                    MANGA
                    <span
                        class="text-red-500"
                    >
                        VAULT
                    </span>
                </a>
            </div>

            <div
                class="
                    px-7
                    py-10
                    text-center
                    sm:px-10
                "
            >
                <div
                    class="
                        mx-auto
                        flex
                        h-20
                        w-20
                        items-center
                        justify-center
                        rounded-full
                        bg-orange-50
                        text-orange-500
                    "
                >
                    <svg
                        class="h-10 w-10"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="1.8"
                            d="
                                M12 8v4l3 2
                                m6-2a9 9
                                0 1 1-18 0
                                9 9 0 0 1
                                18 0z
                            "
                        ></path>
                    </svg>
                </div>

                <p
                    class="
                        mt-6
                        text-xs
                        font-bold
                        uppercase
                        tracking-[0.18em]
                        text-orange-500
                    "
                >
                    Security Timeout
                </p>

                <h1
                    class="
                        mt-2
                        text-3xl
                        font-black
                        tracking-tight
                        text-[#172033]
                    "
                >
                    Your session has expired
                </h1>

                <p
                    class="
                        mx-auto
                        mt-4
                        max-w-sm
                        text-sm
                        leading-6
                        text-gray-500
                    "
                >
                    For your security, you were
                    automatically signed out after
                    30 minutes of inactivity.
                </p>

                <div
                    class="
                        mt-7
                        rounded-2xl
                        border
                        border-gray-100
                        bg-gray-50
                        px-5
                        py-4
                        text-left
                    "
                >
                    <p
                        class="
                            text-sm
                            font-bold
                            text-gray-700
                        "
                    >
                        Please sign in again
                    </p>

                    <p
                        class="
                            mt-1
                            text-xs
                            leading-5
                            text-gray-500
                        "
                    >
                        Any unsaved information from
                        the previous session may need
                        to be entered again.
                    </p>
                </div>

                <a
                    href="<?= htmlspecialchars(
                        $loginUrl,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    class="
                        mt-7
                        inline-flex
                        w-full
                        items-center
                        justify-center
                        gap-2
                        rounded-2xl
                        bg-red-600
                        px-5
                        py-3.5
                        text-sm
                        font-bold
                        text-white
                        transition
                        hover:bg-red-700
                    "
                >
                    ← Back to Sign In
                </a>

                <p
                    class="
                        mt-5
                        text-xs
                        text-gray-400
                    "
                >
                    MangaVault automatically protects
                    inactive authenticated sessions.
                </p>
            </div>
        </section>
    </main>
</body>
</html>