<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/wallet_withdrawal_helper.php';

if (
    current_role() === 'bank' &&
    !empty($_SESSION['bank_operator_id'])
) {
    redirect_to(app_path('bank/dashboard.php'));
}

$banks = walletWithdrawalSupportedBanks();
$error = '';
$bank_code_input = '';
$username_input = '';
$now = time();
$locked_until = (int) ($_SESSION['bank_login_locked_until'] ?? 0);

if ($locked_until > 0 && $locked_until <= $now) {
    unset(
        $_SESSION['bank_login_failures'],
        $_SESSION['bank_login_locked_until']
    );
    $locked_until = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $bank_code_input = is_string($_POST['bank_code'] ?? null)
        ? strtoupper(trim($_POST['bank_code']))
        : '';
    $username_input = is_string($_POST['username'] ?? null)
        ? strtolower(trim($_POST['username']))
        : '';
    $password = $_POST['password'] ?? null;

    if ($locked_until > $now) {
        $error = 'Too many unsuccessful attempts. Please wait before trying again.';
    } elseif (!isset($banks[$bank_code_input])) {
        $error = 'Please select the operator bank.';
    } elseif (
        preg_match('/\A[a-z0-9_]{3,60}\z/', $username_input) !== 1
    ) {
        $error = 'Please enter a valid bank operator username.';
    } elseif (
        !is_string($password) ||
        $password === '' ||
        strlen($password) > 72
    ) {
        $error = 'Please enter a valid password.';
    }

    if ($error === '') {
        $statement = $pdo->prepare("
            SELECT
                bank_gateway_operator_id,
                bank_gateway_operator_bank_code,
                bank_gateway_operator_bank_name,
                bank_gateway_operator_username,
                bank_gateway_operator_password_hash,
                bank_gateway_operator_display_name,
                bank_gateway_operator_is_active
            FROM bank_gateway_operators
            WHERE bank_gateway_operator_username = ?
            AND bank_gateway_operator_bank_code = ?
            LIMIT 1
        ");
        $statement->execute([
            $username_input,
            $bank_code_input,
        ]);
        $operator = $statement->fetch(PDO::FETCH_ASSOC);

        if (
            $operator &&
            (int) $operator['bank_gateway_operator_is_active'] === 1 &&
            password_verify(
                $password,
                (string) $operator['bank_gateway_operator_password_hash']
            )
        ) {
            regenerate_session();

            unset(
                $_SESSION['bank_login_failures'],
                $_SESSION['bank_login_locked_until']
            );

            $_SESSION['bank_operator_id'] =
                (int) $operator['bank_gateway_operator_id'];
            $_SESSION['bank_operator_code'] =
                (string) $operator['bank_gateway_operator_bank_code'];
            $_SESSION['bank_operator_name'] =
                (string) $operator['bank_gateway_operator_display_name'];
            $_SESSION['bank_operator_bank_name'] =
                (string) $operator['bank_gateway_operator_bank_name'];
            $_SESSION['role'] = 'bank';
            $_SESSION['auth_last_activity_at'] = time();

            $update = $pdo->prepare("
                UPDATE bank_gateway_operators
                SET bank_gateway_operator_last_login_at = UTC_TIMESTAMP()
                WHERE bank_gateway_operator_id = ?
            ");
            $update->execute([
                (int) $operator['bank_gateway_operator_id'],
            ]);

            redirect_to(app_path('bank/dashboard.php'));
        }

        $failures = (int) ($_SESSION['bank_login_failures'] ?? 0) + 1;
        $_SESSION['bank_login_failures'] = $failures;

        if ($failures >= 5) {
            $_SESSION['bank_login_locked_until'] = time() + 900;
            $locked_until = (int) $_SESSION['bank_login_locked_until'];
        }

        $error = 'Invalid bank, username, or password.';
    }
}

$selected_bank_name = $banks[$bank_code_input] ?? '';
$selected_bank_initials = $bank_code_input !== ''
    ? substr($bank_code_input, 0, 3)
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Institution Sign In - BankLink Settlement Services</title>
    <link
        rel="stylesheet"
        href="<?= htmlspecialchars(
            app_path('assets/css/bank_portal.css'),
            ENT_QUOTES,
            'UTF-8'
        ) ?>"
    >
</head>
<body class="bank-login-page">
    <div class="bank-login-shell">
        <header class="bank-login-topbar">
            <a
                class="bank-brand"
                href="<?= htmlspecialchars(
                    app_path('bank/login.php'),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
            >
                <span class="bank-brand-mark" aria-hidden="true">BL</span>
                <span>
                    <span class="bank-brand-name">
                        BankLink Settlement Services
                    </span>
                    <span class="bank-brand-subtitle">
                        Institution Operations Portal
                    </span>
                </span>
            </a>

            <div class="bank-topbar-status">
                <span>
                    <span class="bank-secure-dot"></span>
                    Secure connection
                </span>
                <span id="malaysiaClock">Malaysia time</span>
            </div>
        </header>

        <main class="bank-login-main">
            <div class="bank-login-card">
                <section class="bank-login-visual">
                    <div class="bank-network-label">
                        <span class="bank-secure-dot"></span>
                        Protected settlement network
                    </div>

                    <h1 class="bank-login-title">
                        Review transfer instructions with institutional controls.
                    </h1>

                    <p class="bank-login-description">
                        Authorized bank operators can inspect only instructions
                        assigned to their institution, record a decision, and
                        generate a verifiable Malaysia-time decision record.
                    </p>

                    <div class="bank-operation-panel">
                        <?php foreach ([
                            [
                                '01',
                                'Institution-only work queue',
                                'Requests remain isolated by destination bank.',
                            ],
                            [
                                '02',
                                'Password-authorized decisions',
                                'Sensitive access and every decision are audited.',
                            ],
                            [
                                '03',
                                'Independent verification record',
                                'Bank review is separated from merchant approval.',
                            ],
                        ] as $operation): ?>
                            <div class="bank-operation-row">
                                <span class="bank-operation-icon">
                                    <?= htmlspecialchars($operation[0]) ?>
                                </span>
                                <span>
                                    <span class="bank-operation-title">
                                        <?= htmlspecialchars($operation[1]) ?>
                                    </span>
                                    <span class="bank-operation-copy">
                                        <?= htmlspecialchars($operation[2]) ?>
                                    </span>
                                </span>
                                <span class="bank-operation-state">Active</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="bank-login-form-panel">
                    <div class="bank-form-eyebrow">Institutional Sign In</div>
                    <h2 class="bank-form-title">Secure operator access</h2>
                    <p class="bank-form-description">
                        Choose the institution assigned to your operator account.
                        Five failed attempts lock sign-in for 15 minutes.
                    </p>

                    <?php if ($error !== ''): ?>
                        <div class="bank-alert" role="alert">
                            <span aria-hidden="true">!</span>
                            <span>
                                <?= htmlspecialchars(
                                    $error,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>
                        </div>
                    <?php elseif (isset($_GET['logged_out'])): ?>
                        <div
                            class="bank-alert bank-alert-success"
                            role="status"
                        >
                            <span aria-hidden="true">✓</span>
                            <span>Your operator session ended securely.</span>
                        </div>
                    <?php elseif (isset($_GET['session_expired'])): ?>
                        <div
                            class="bank-alert bank-alert-warning"
                            role="alert"
                        >
                            <span aria-hidden="true">!</span>
                            <span>
                                Your session expired after 15 minutes of inactivity.
                            </span>
                        </div>
                    <?php elseif (isset($_GET['account']) && $_GET['account'] === 'inactive'): ?>
                        <div class="bank-alert" role="alert">
                            <span aria-hidden="true">!</span>
                            <span>
                                This operator account is inactive. Contact the
                                system administrator.
                            </span>
                        </div>
                    <?php endif; ?>

                    <form
                        method="POST"
                        target="_self"
                        class="bank-login-form"
                        id="bankLoginForm"
                        novalidate
                    >
                        <?php csrf_field(); ?>

                        <div class="bank-form-group">
                            <label class="bank-form-label" id="bankCodeLabel">
                                <span>
                                    Financial Institution
                                    <span class="bank-required">*</span>
                                </span>
                            </label>

                            <div class="bank-select" id="bankSelect">
                                <input
                                    type="hidden"
                                    id="bankCode"
                                    name="bank_code"
                                    value="<?= htmlspecialchars(
                                        $bank_code_input,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                >
                                <button
                                    type="button"
                                    class="bank-select-trigger"
                                    id="bankSelectTrigger"
                                    aria-labelledby="bankCodeLabel bankSelectedName"
                                    aria-haspopup="listbox"
                                    aria-expanded="false"
                                    aria-controls="bankSelectMenu"
                                >
                                    <span class="bank-select-value">
                                        <span
                                            class="bank-select-logo"
                                            id="bankSelectedLogo"
                                            <?= $selected_bank_name === ''
                                                ? 'hidden'
                                                : '' ?>
                                        >
                                            <?= htmlspecialchars(
                                                $selected_bank_initials,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </span>
                                        <span
                                            class="bank-select-name <?=
                                                $selected_bank_name === ''
                                                    ? 'bank-select-placeholder'
                                                    : ''
                                            ?>"
                                            id="bankSelectedName"
                                        >
                                            <?= htmlspecialchars(
                                                $selected_bank_name !== ''
                                                    ? $selected_bank_name
                                                    : 'Select your institution',
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </span>
                                    </span>
                                    <span
                                        class="bank-select-chevron"
                                        aria-hidden="true"
                                    >
                                        ▾
                                    </span>
                                </button>

                                <div
                                    class="bank-select-menu"
                                    id="bankSelectMenu"
                                    hidden
                                >
                                    <div class="bank-select-search-wrap">
                                        <input
                                            type="search"
                                            class="bank-select-search"
                                            id="bankSelectSearch"
                                            placeholder="Search institution"
                                            autocomplete="off"
                                            aria-label="Search institution"
                                        >
                                    </div>
                                    <div
                                        class="bank-select-options"
                                        role="listbox"
                                        aria-labelledby="bankCodeLabel"
                                    >
                                        <?php foreach ($banks as $code => $name): ?>
                                            <button
                                                type="button"
                                                class="bank-option"
                                                role="option"
                                                data-code="<?= htmlspecialchars(
                                                    $code,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                                data-name="<?= htmlspecialchars(
                                                    $name,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                                aria-selected="<?=
                                                    $bank_code_input === $code
                                                        ? 'true'
                                                        : 'false'
                                                ?>"
                                            >
                                                <span class="bank-option-logo">
                                                    <?= htmlspecialchars(
                                                        substr($code, 0, 3),
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>
                                                </span>
                                                <span class="bank-option-copy">
                                                    <span class="bank-option-name">
                                                        <?= htmlspecialchars(
                                                            $name,
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        ) ?>
                                                    </span>
                                                    <span class="bank-option-code">
                                                        INSTITUTION <?= htmlspecialchars(
                                                            $code,
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        ) ?>
                                                    </span>
                                                </span>
                                            </button>
                                        <?php endforeach; ?>
                                        <div class="bank-select-empty" hidden>
                                            No matching institution found.
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <p class="bank-field-error" id="bankCodeError"></p>
                        </div>

                        <div class="bank-form-group">
                            <label
                                for="operatorUsername"
                                class="bank-form-label"
                            >
                                <span>
                                    Operator Username
                                    <span class="bank-required">*</span>
                                </span>
                            </label>
                            <input
                                type="text"
                                id="operatorUsername"
                                name="username"
                                class="bank-input"
                                value="<?= htmlspecialchars(
                                    $username_input,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                minlength="3"
                                maxlength="60"
                                pattern="[a-z0-9_]{3,60}"
                                autocomplete="username"
                                autocapitalize="none"
                                spellcheck="false"
                                required
                            >
                            <p
                                class="bank-field-error"
                                id="operatorUsernameError"
                            ></p>
                        </div>

                        <div class="bank-form-group">
                            <label
                                for="operatorPassword"
                                class="bank-form-label"
                            >
                                <span>
                                    Password
                                    <span class="bank-required">*</span>
                                </span>
                                <span
                                    class="bank-caps-warning"
                                    id="capsLockWarning"
                                >
                                    Caps Lock is on
                                </span>
                            </label>
                            <div class="bank-input-wrap">
                                <input
                                    type="password"
                                    id="operatorPassword"
                                    name="password"
                                    class="bank-input bank-password-input"
                                    maxlength="72"
                                    autocomplete="current-password"
                                    required
                                >
                                <button
                                    type="button"
                                    class="bank-password-toggle"
                                    id="passwordToggle"
                                    aria-label="Show password"
                                    aria-pressed="false"
                                >
                                    <span aria-hidden="true">Show</span>
                                </button>
                            </div>
                            <p
                                class="bank-field-error"
                                id="operatorPasswordError"
                            ></p>
                        </div>

                        <button
                            type="submit"
                            class="bank-login-button"
                            id="bankLoginButton"
                            data-locked-until="<?= $locked_until ?>"
                            <?= $locked_until > $now ? 'disabled' : '' ?>
                        >
                            <span id="bankLoginButtonText">
                                <?= $locked_until > $now
                                    ? 'Sign-in temporarily locked'
                                    : 'Continue to Work Queue' ?>
                            </span>
                            <span aria-hidden="true">→</span>
                        </button>
                    </form>

                    <div class="bank-form-footer">
                        <span>
                            Protected by CSRF, rate-limit, session, and
                            role-isolation controls.
                        </span>
                        <span><strong>Session:</strong> 15 min inactive</span>
                    </div>
                </section>
            </div>
        </main>
    </div>

    <div class="bank-demo-badge">Academic demo</div>

    <script>
        (() => {
            const form = document.getElementById('bankLoginForm');
            const bankInput = document.getElementById('bankCode');
            const bankSelect = document.getElementById('bankSelect');
            const bankTrigger = document.getElementById('bankSelectTrigger');
            const bankMenu = document.getElementById('bankSelectMenu');
            const bankSearch = document.getElementById('bankSelectSearch');
            const bankOptions = Array.from(
                document.querySelectorAll('.bank-option')
            );
            const bankEmpty = document.querySelector('.bank-select-empty');
            const selectedLogo = document.getElementById('bankSelectedLogo');
            const selectedName = document.getElementById('bankSelectedName');
            const username = document.getElementById('operatorUsername');
            const password = document.getElementById('operatorPassword');
            const passwordToggle = document.getElementById('passwordToggle');
            const capsWarning = document.getElementById('capsLockWarning');
            const submitButton = document.getElementById('bankLoginButton');
            const submitText = document.getElementById('bankLoginButtonText');
            let activeIndex = -1;

            const setFieldError = (field, message, errorId) => {
                field.classList.toggle('is-invalid', message !== '');
                field.setAttribute('aria-invalid', message !== '' ? 'true' : 'false');
                document.getElementById(errorId).textContent = message;
            };

            const visibleOptions = () => bankOptions.filter(
                (option) => !option.hidden
            );

            const setActiveOption = (index) => {
                const options = visibleOptions();

                options.forEach((option) => option.classList.remove('is-active'));

                if (options.length === 0) {
                    activeIndex = -1;
                    return;
                }

                activeIndex = Math.max(0, Math.min(index, options.length - 1));
                options[activeIndex].classList.add('is-active');
                options[activeIndex].scrollIntoView({ block: 'nearest' });
            };

            const openBankMenu = () => {
                bankMenu.hidden = false;
                bankTrigger.setAttribute('aria-expanded', 'true');
                bankSearch.value = '';
                bankOptions.forEach((option) => {
                    option.hidden = false;
                });
                bankEmpty.hidden = true;
                const selectedIndex = visibleOptions().findIndex(
                    (option) => option.getAttribute('aria-selected') === 'true'
                );
                setActiveOption(selectedIndex >= 0 ? selectedIndex : 0);
                window.requestAnimationFrame(() => bankSearch.focus());
            };

            const closeBankMenu = (returnFocus = false) => {
                bankMenu.hidden = true;
                bankTrigger.setAttribute('aria-expanded', 'false');
                bankOptions.forEach((option) => {
                    option.classList.remove('is-active');
                });
                activeIndex = -1;

                if (returnFocus) {
                    bankTrigger.focus();
                }
            };

            const chooseBank = (option) => {
                bankInput.value = option.dataset.code;
                selectedLogo.textContent = option.dataset.code.slice(0, 3);
                selectedLogo.hidden = false;
                selectedName.textContent = option.dataset.name;
                selectedName.classList.remove('bank-select-placeholder');
                bankOptions.forEach((candidate) => {
                    candidate.setAttribute(
                        'aria-selected',
                        candidate === option ? 'true' : 'false'
                    );
                });
                setFieldError(bankTrigger, '', 'bankCodeError');
                closeBankMenu(true);
            };

            bankTrigger.addEventListener('click', () => {
                if (bankMenu.hidden) {
                    openBankMenu();
                } else {
                    closeBankMenu(true);
                }
            });

            bankOptions.forEach((option) => {
                option.addEventListener('click', () => chooseBank(option));
            });

            bankSearch.addEventListener('input', () => {
                const query = bankSearch.value.trim().toLowerCase();

                bankOptions.forEach((option) => {
                    const searchable = `${option.dataset.name} ${option.dataset.code}`
                        .toLowerCase();
                    option.hidden = !searchable.includes(query);
                });

                bankEmpty.hidden = visibleOptions().length !== 0;
                setActiveOption(0);
            });

            bankSearch.addEventListener('keydown', (event) => {
                const options = visibleOptions();

                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    setActiveOption(activeIndex + 1);
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    setActiveOption(activeIndex - 1);
                } else if (event.key === 'Enter' && options[activeIndex]) {
                    event.preventDefault();
                    chooseBank(options[activeIndex]);
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    closeBankMenu(true);
                }
            });

            document.addEventListener('click', (event) => {
                if (!bankSelect.contains(event.target) && !bankMenu.hidden) {
                    closeBankMenu();
                }
            });

            username.addEventListener('input', () => {
                username.value = username.value.toLowerCase().replace(/\s+/g, '');
                setFieldError(username, '', 'operatorUsernameError');
            });

            password.addEventListener('input', () => {
                setFieldError(password, '', 'operatorPasswordError');
            });

            password.addEventListener('keydown', (event) => {
                capsWarning.classList.toggle(
                    'is-visible',
                    event.getModifierState('CapsLock')
                );
            });

            password.addEventListener('blur', () => {
                capsWarning.classList.remove('is-visible');
            });

            passwordToggle.addEventListener('click', () => {
                const showPassword = password.type === 'password';
                password.type = showPassword ? 'text' : 'password';
                passwordToggle.setAttribute(
                    'aria-pressed',
                    showPassword ? 'true' : 'false'
                );
                passwordToggle.setAttribute(
                    'aria-label',
                    showPassword ? 'Hide password' : 'Show password'
                );
                passwordToggle.firstElementChild.textContent =
                    showPassword ? 'Hide' : 'Show';
                password.focus();
            });

            form.addEventListener('submit', (event) => {
                let valid = true;
                const normalizedUsername = username.value.trim().toLowerCase();

                if (bankInput.value === '') {
                    setFieldError(
                        bankTrigger,
                        'Select the institution assigned to this operator.',
                        'bankCodeError'
                    );
                    valid = false;
                }

                if (!/^[a-z0-9_]{3,60}$/.test(normalizedUsername)) {
                    setFieldError(
                        username,
                        'Use 3–60 lowercase letters, numbers, or underscores.',
                        'operatorUsernameError'
                    );
                    valid = false;
                }

                if (password.value.length < 1 || password.value.length > 72) {
                    setFieldError(
                        password,
                        'Enter the assigned operator password.',
                        'operatorPasswordError'
                    );
                    valid = false;
                }

                if (!valid || submitButton.disabled) {
                    event.preventDefault();
                    return;
                }

                username.value = normalizedUsername;
                submitButton.disabled = true;
                submitText.textContent = 'Verifying operator access…';
            });

            const updateMalaysiaClock = () => {
                document.getElementById('malaysiaClock').textContent =
                    new Intl.DateTimeFormat('en-MY', {
                        timeZone: 'Asia/Kuala_Lumpur',
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: true,
                    }).format(new Date()) + ' MYT';
            };

            const lockedUntil = Number(submitButton.dataset.lockedUntil || 0);

            if (lockedUntil > Math.floor(Date.now() / 1000)) {
                const updateLockCountdown = () => {
                    const seconds = Math.max(
                        0,
                        lockedUntil - Math.floor(Date.now() / 1000)
                    );

                    if (seconds === 0) {
                        window.location.reload();
                        return;
                    }

                    const minutes = String(Math.floor(seconds / 60)).padStart(2, '0');
                    const remainder = String(seconds % 60).padStart(2, '0');
                    submitText.textContent = `Try again in ${minutes}:${remainder}`;
                };

                updateLockCountdown();
                window.setInterval(updateLockCountdown, 1000);
            }

            updateMalaysiaClock();
            window.setInterval(updateMalaysiaClock, 1000);
        })();
    </script>
</body>
</html>
