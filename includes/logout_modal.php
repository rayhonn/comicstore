<?php

require_once __DIR__ . '/csrf.php';

$logout_modal_action =
    isset($logout_modal_action) &&
    is_string($logout_modal_action) &&
    $logout_modal_action !== ''
        ? $logout_modal_action
        : app_path('logout.php');

$logout_modal_account_label =
    isset($logout_modal_account_label) &&
    is_string($logout_modal_account_label) &&
    $logout_modal_account_label !== ''
        ? $logout_modal_account_label
        : 'MangaVault account';

?>

<div
    id="sharedLogoutModal"
    class="fixed inset-0 z-[120] hidden items-center justify-center bg-black/60 px-5 backdrop-blur-sm"
    role="dialog"
    aria-modal="true"
    aria-labelledby="sharedLogoutModalTitle"
>
    <div
        class="w-full max-w-sm overflow-hidden rounded-3xl bg-white shadow-2xl"
    >
        <div
            class="bg-gradient-to-r from-red-600 to-red-700 px-6 py-5 text-white"
        >
            <div class="flex items-center gap-4">
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/20"
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
                            d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"
                        ></path>
                    </svg>
                </div>

                <div>
                    <h2
                        id="sharedLogoutModalTitle"
                        class="text-xl font-black"
                    >
                        Confirm Logout
                    </h2>

                    <p class="mt-1 text-sm text-white/80">
                        Your current session will be ended securely.
                    </p>
                </div>
            </div>
        </div>

        <div class="p-6">
            <p class="text-sm leading-6 text-gray-600">
                Are you sure you want to log out of your
                <span class="font-bold text-gray-800">
                    <?= htmlspecialchars(
                        $logout_modal_account_label,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </span>?
            </p>

            <div
                class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3"
            >
                <p class="text-xs leading-5 text-amber-800">
                    Save any unfinished work before logging out.
                </p>
            </div>

            <div class="mt-6 grid grid-cols-2 gap-3">
                <button
                    type="button"
                    onclick="closeSharedLogoutModal()"
                    class="rounded-xl border border-gray-300 bg-white px-4 py-3 text-sm font-bold text-gray-600 transition-colors hover:bg-gray-50"
                >
                    Cancel
                </button>

                <form
                    method="POST"
                    action="<?= htmlspecialchars(
                        $logout_modal_action,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >
                    <?php csrf_field(); ?>

                    <button
                        type="submit"
                        class="w-full rounded-xl bg-red-600 px-4 py-3 text-sm font-bold text-white transition-colors hover:bg-red-700"
                    >
                        Yes, Log Out
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    function openSharedLogoutModal() {
        const modal = document.getElementById(
            'sharedLogoutModal'
        );

        modal.classList.remove('hidden');
        modal.classList.add('flex');

        document.body.classList.add(
            'overflow-hidden'
        );
    }

    function closeSharedLogoutModal() {
        const modal = document.getElementById(
            'sharedLogoutModal'
        );

        modal.classList.add('hidden');
        modal.classList.remove('flex');

        document.body.classList.remove(
            'overflow-hidden'
        );
    }

    document.getElementById(
        'sharedLogoutModal'
    ).addEventListener(
        'click',
        function (event) {
            if (event.target === this) {
                closeSharedLogoutModal();
            }
        }
    );

    document.addEventListener(
        'keydown',
        function (event) {
            if (
                event.key === 'Escape' &&
                !document
                    .getElementById(
                        'sharedLogoutModal'
                    )
                    .classList
                    .contains('hidden')
            ) {
                closeSharedLogoutModal();
            }
        }
    );
</script>
