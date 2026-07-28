(() => {
    'use strict';

    const mobileQuery = window.matchMedia(
        '(max-width: 767px)'
    );

    if (!mobileQuery.matches) {
        return;
    }

    const body = document.body;
    const isIndex = body.classList.contains(
        'mobile-index-page'
    );
    const isCatalog = body.classList.contains(
        'mobile-catalog-page'
    );

    if (!isIndex && !isCatalog) {
        return;
    }

    const icons = {
        home: `
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                aria-hidden="true"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V10Z"
                ></path>
            </svg>
        `,
        catalog: `
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                aria-hidden="true"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v16H6.5A2.5 2.5 0 0 0 4 21.5v-16Zm16 0A2.5 2.5 0 0 0 17.5 3H13v16h4.5a2.5 2.5 0 0 1 2.5 2.5v-16Z"
                ></path>
            </svg>
        `,
        ranking: `
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                aria-hidden="true"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M8 21h8m-4-4v4M7 4h10v3a5 5 0 0 1-10 0V4Zm0 1H4v2a4 4 0 0 0 4 4m9-6h3v2a4 4 0 0 1-4 4"
                ></path>
            </svg>
        `,
        new: `
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                aria-hidden="true"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="m12 3 1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6L12 3Zm6 11 .9 2.1L21 17l-2.1.9L18 20l-.9-2.1L15 17l2.1-.9L18 14ZM5 14l1.1 2.9L9 18l-2.9 1.1L5 22l-1.1-2.9L1 18l2.9-1.1L5 14Z"
                ></path>
            </svg>
        `,
        cart: `
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                aria-hidden="true"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M3 3h2l1.5 10.5h10L20 6H6m2 15a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm9 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"
                ></path>
            </svg>
        `,
        account: `
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                aria-hidden="true"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M20 21a8 8 0 0 0-16 0m8-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"
                ></path>
            </svg>
        `,
        filter: `
            <svg
                viewBox="0 0 24 24"
                width="18"
                height="18"
                fill="none"
                stroke="currentColor"
                aria-hidden="true"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M4 6h16M7 12h10m-7 6h4"
                ></path>
            </svg>
        `,
    };

    function escapeText(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function createBottomNavigation() {
        if (
            document.querySelector(
                '.mobile-bottom-nav'
            )
        ) {
            return;
        }

        const nav = document.createElement(
            'nav'
        );

        nav.className =
            'mobile-bottom-nav';
        nav.setAttribute(
            'aria-label',
            'Mobile navigation'
        );

        let items = [];

        if (isIndex) {
            const accountAnchor =
                document.querySelector(
                    'a[href="customer/profile.php"]'
                ) ||
                document.querySelector(
                    'a[href="login.php"]'
                );

            items = [
                {
                    key: 'home',
                    label: 'Home',
                    href: 'index.php',
                    active: true,
                },
                {
                    key: 'catalog',
                    label: 'Catalog',
                    href: 'customer/home.php',
                },
                {
                    key: 'ranking',
                    label: 'Rankings',
                    href: '#rankings',
                },
                {
                    key: 'account',
                    label: 'Account',
                    href: accountAnchor
                        ? accountAnchor.getAttribute(
                            'href'
                        )
                        : 'login.php',
                },
            ];
        } else {
            items = [
                {
                    key: 'home',
                    label: 'Home',
                    href: '../index.php',
                },
                {
                    key: 'catalog',
                    label: 'Catalog',
                    href: 'home.php',
                    active: true,
                },
                {
                    key: 'cart',
                    label: 'Cart',
                    href: 'cart.php',
                    badge: readCartBadge(),
                },
                {
                    key: 'account',
                    label: 'Account',
                    href: 'profile.php',
                },
            ];
        }

        nav.style.setProperty(
            '--mobile-nav-count',
            String(items.length)
        );

        nav.innerHTML = items
            .map(item => {
                const badge = item.badge
                    ? `
                        <span
                            class="mobile-bottom-nav-badge"
                        >
                            ${escapeText(item.badge)}
                        </span>
                    `
                    : '';

                return `
                    <a
                        href="${escapeText(item.href)}"
                        class="${item.active
                            ? 'is-active'
                            : ''}"
                    >
                        <span
                            class="mobile-bottom-nav-icon"
                        >
                            ${icons[item.key] || ''}
                            ${badge}
                        </span>
                        <span
                            class="mobile-bottom-nav-label"
                        >
                            ${escapeText(item.label)}
                        </span>
                    </a>
                `;
            })
            .join('');

        document.body.appendChild(nav);

        nav.querySelectorAll(
            'a[href^="#"]'
        ).forEach(link => {
            link.addEventListener(
                'click',
                event => {
                    const target =
                        document.querySelector(
                            link.getAttribute(
                                'href'
                            )
                        );

                    if (!target) {
                        return;
                    }

                    event.preventDefault();
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start',
                    });
                }
            );
        });
    }

    function readCartBadge() {
        const cartLink =
            document.querySelector(
                'a[href="cart.php"]'
            );

        if (!cartLink) {
            return '';
        }

        const badge =
            cartLink.querySelector(
                'span.absolute'
            );

        return badge
            ? badge.textContent.trim()
            : '';
    }

    function setupIndexDrawer() {
        if (!isIndex) {
            return;
        }

        const menu =
            document.getElementById(
                'mobileMenu'
            );
        const button =
            document.getElementById(
                'menuBtn'
            );

        if (!menu || !button) {
            return;
        }

        const syncBody = () => {
            body.classList.toggle(
                'mobile-menu-open',
                menu.classList.contains(
                    'open'
                )
            );
        };

        const observer =
            new MutationObserver(
                syncBody
            );

        observer.observe(menu, {
            attributes: true,
            attributeFilter: [
                'class',
            ],
        });

        button.addEventListener(
            'click',
            () => {
                window.requestAnimationFrame(
                    syncBody
                );
            }
        );

        menu.querySelectorAll('a').forEach(
            link => {
                link.addEventListener(
                    'click',
                    () => {
                        body.classList.remove(
                            'mobile-menu-open'
                        );
                    }
                );
            }
        );
    }

    function setupCatalogDrawer() {
        if (!isCatalog) {
            return;
        }

        const menu =
            document.getElementById(
                'navMobileMenu'
            );

        if (!menu) {
            return;
        }

        const syncDrawer = () => {
            const open =
                menu.style.maxHeight !==
                    '0px' &&
                menu.style.maxHeight !==
                    '';

            menu.classList.toggle(
                'mobile-drawer-open',
                open
            );

            body.classList.toggle(
                'mobile-menu-open',
                open
            );
        };

        const observer =
            new MutationObserver(
                syncDrawer
            );

        observer.observe(menu, {
            attributes: true,
            attributeFilter: [
                'style',
            ],
        });

        document
            .getElementById('navMenuBtn')
            ?.addEventListener(
                'click',
                () => {
                    window.requestAnimationFrame(
                        syncDrawer
                    );
                }
            );

        document
            .getElementById('navOverlay')
            ?.addEventListener(
                'click',
                () => {
                    window.requestAnimationFrame(
                        syncDrawer
                    );
                }
            );
    }

    function addSwipeHints() {
        if (!isIndex) {
            return;
        }

        [
            document.getElementById(
                'rankings'
            ),
            document.getElementById(
                'new-releases'
            ),
        ].forEach(section => {
            if (!section) {
                return;
            }

            const headingRow =
                section.querySelector(
                    '.mb-10'
                );

            if (!headingRow) {
                return;
            }

            const link =
                headingRow.querySelector(
                    'a'
                );

            if (link) {
                link.classList.add(
                    'mobile-swipe-hint'
                );
                link.insertAdjacentText(
                    'afterbegin',
                    'Swipe · '
                );
            }
        });
    }

    function setupCatalogFilters() {
        if (!isCatalog) {
            return;
        }

        const panel =
            document.querySelector(
                '.catalog-filter-panel'
            );
        const form =
            panel?.querySelector(
                'form'
            );

        if (!panel || !form) {
            return;
        }

        const directChildren =
            Array.from(form.children);

        if (directChildren.length < 5) {
            return;
        }

        const [
            searchField,
            categoryField,
            genreField,
            typeField,
            searchButton,
        ] = directChildren;

        const primary =
            document.createElement('div');
        primary.className =
            'mobile-catalog-primary';

        form.insertBefore(
            primary,
            searchField
        );
        primary.append(
            searchField,
            searchButton
        );

        const toggle =
            document.createElement(
                'button'
            );
        toggle.type = 'button';
        toggle.className =
            'mobile-filter-toggle';
        toggle.innerHTML = `
            <span
                class="mobile-filter-toggle-left"
            >
                ${icons.filter}
                Filters
            </span>
            <span
                class="mobile-filter-count"
                aria-label="Active filters"
            >
                0
            </span>
        `;

        primary.insertAdjacentElement(
            'afterend',
            toggle
        );

        const overlay =
            document.createElement('div');
        overlay.className =
            'mobile-filter-overlay';
        overlay.setAttribute(
            'aria-hidden',
            'true'
        );

        const drawer =
            document.createElement('div');
        drawer.className =
            'mobile-filter-drawer';
        drawer.setAttribute(
            'role',
            'dialog'
        );
        drawer.setAttribute(
            'aria-modal',
            'true'
        );
        drawer.setAttribute(
            'aria-label',
            'Catalog filters'
        );

        drawer.innerHTML = `
            <div
                class="mobile-filter-handle"
                aria-hidden="true"
            ></div>
            <div class="mobile-filter-header">
                <div>
                    <p
                        style="
                            margin:0 0 3px;
                            color:#dc2626;
                            font-size:9px;
                            font-weight:900;
                            letter-spacing:.17em;
                            text-transform:uppercase;
                        "
                    >
                        Refine catalog
                    </p>
                    <h3>Filter titles</h3>
                </div>
                <button
                    type="button"
                    class="mobile-filter-close"
                    aria-label="Close filters"
                >
                    ✕
                </button>
            </div>
            <div
                class="mobile-filter-fields"
            ></div>
            <div
                class="mobile-filter-actions"
            >
                <a
                    href="home.php"
                    class="mobile-filter-clear"
                >
                    Clear
                </a>
                <button
                    type="submit"
                    class="mobile-filter-apply"
                >
                    Apply Filters
                </button>
            </div>
        `;

        drawer
            .querySelector(
                '.mobile-filter-fields'
            )
            .append(
                categoryField,
                genreField,
                typeField
            );

        form.appendChild(drawer);
        document.body.appendChild(
            overlay
        );

        const closeButton =
            drawer.querySelector(
                '.mobile-filter-close'
            );

        const openFilters = () => {
            body.classList.add(
                'mobile-filter-open'
            );
            overlay.setAttribute(
                'aria-hidden',
                'false'
            );
            window.setTimeout(
                () => {
                    closeButton?.focus();
                },
                120
            );
        };

        const closeFilters = () => {
            body.classList.remove(
                'mobile-filter-open'
            );
            overlay.setAttribute(
                'aria-hidden',
                'true'
            );
            toggle.focus();
        };

        toggle.addEventListener(
            'click',
            openFilters
        );
        closeButton?.addEventListener(
            'click',
            closeFilters
        );
        overlay.addEventListener(
            'click',
            closeFilters
        );

        document.addEventListener(
            'keydown',
            event => {
                if (
                    event.key === 'Escape' &&
                    body.classList.contains(
                        'mobile-filter-open'
                    )
                ) {
                    closeFilters();
                }
            }
        );

        const countElement =
            toggle.querySelector(
                '.mobile-filter-count'
            );

        const updateCount = () => {
            const selects = [
                categoryField,
                genreField,
                typeField,
            ].map(field =>
                field.querySelector(
                    'select'
                )
            );

            const count = selects.filter(
                select =>
                    select &&
                    select.value !== ''
            ).length;

            if (countElement) {
                countElement.textContent =
                    String(count);
                countElement.style.visibility =
                    count > 0
                        ? 'visible'
                        : 'hidden';
            }
        };

        [
            categoryField,
            genreField,
            typeField,
        ].forEach(field => {
            field
                .querySelector('select')
                ?.addEventListener(
                    'change',
                    updateCount
                );
        });

        updateCount();
    }

    function setupFooterAccordion() {
        const footer =
            document.querySelector(
                'footer'
            );
        const grid =
            footer?.querySelector(
                '.grid'
            );

        if (!footer || !grid) {
            return;
        }

        const columns =
            Array.from(grid.children);

        columns
            .slice(1)
            .forEach((column, index) => {
                const heading =
                    column.querySelector(
                        'h3, h4'
                    );

                if (!heading) {
                    return;
                }

                const title =
                    heading.textContent.trim();

                const contents =
                    Array.from(
                        column.children
                    ).filter(
                        child =>
                            child !== heading
                    );

                const section =
                    document.createElement(
                        'div'
                    );
                section.className =
                    'mobile-footer-section';

                const button =
                    document.createElement(
                        'button'
                    );
                button.type = 'button';
                button.className =
                    'mobile-footer-toggle';
                button.setAttribute(
                    'aria-expanded',
                    'false'
                );
                button.innerHTML = `
                    <span
                        class="mobile-footer-toggle-title"
                    >
                        ${escapeText(title)}
                    </span>
                    <span
                        class="mobile-footer-chevron"
                        aria-hidden="true"
                    >
                       ⌄
                    </span>
                `;

                const content =
                    document.createElement(
                        'div'
                    );
                content.className =
                    'mobile-footer-content';

                const inner =
                    document.createElement(
                        'div'
                    );
                inner.className =
                    'mobile-footer-content-inner';

                contents.forEach(child => {
                    inner.appendChild(child);
                });

                content.appendChild(inner);
                section.append(
                    button,
                    content
                );

                column.replaceWith(section);

                button.addEventListener(
                    'click',
                    () => {
                        const open =
                            section.classList
                                .toggle(
                                    'is-open'
                                );

                        button.setAttribute(
                            'aria-expanded',
                            open
                                ? 'true'
                                : 'false'
                        );
                    }
                );

                if (index === 0) {
                    section.classList.add(
                        'is-open'
                    );
                    button.setAttribute(
                        'aria-expanded',
                        'true'
                    );
                }
            });
    }

    createBottomNavigation();
    setupIndexDrawer();
    setupCatalogDrawer();
    addSwipeHints();
    setupCatalogFilters();
    setupFooterAccordion();
})();