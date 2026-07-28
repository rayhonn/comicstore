(() => {
    'use strict';

    const reduceMotion = window.matchMedia(
        '(prefers-reduced-motion: reduce)'
    ).matches;

    const body = document.body;
    const heroSection = document.querySelector(
        'section[aria-label="Featured MangaVault highlights"]'
    );
    const heroSlider = document.getElementById(
        'heroSlider'
    );
    const heroArticles = heroSlider
        ? Array.from(
            heroSlider.querySelectorAll(
                ':scope > article'
            )
        )
        : [];
    const heroDots = Array.from(
        document.querySelectorAll(
            '.hero-dot'
        )
    );

    body.classList.add(
        'index-motion-page'
    );

    function createScrollProgress() {
        const progress = document.createElement(
            'div'
        );

        progress.id =
            'indexMotionProgress';
        progress.setAttribute(
            'aria-hidden',
            'true'
        );

        body.appendChild(progress);

        const updateProgress = () => {
            const scrollable =
                document.documentElement
                    .scrollHeight -
                window.innerHeight;

            const value = scrollable > 0
                ? Math.min(
                    100,
                    Math.max(
                        0,
                        (
                            window.scrollY /
                            scrollable
                        ) *
                        100
                    )
                )
                : 0;

            document.documentElement
                .style.setProperty(
                    '--index-motion-progress',
                    `${value}%`
                );
        };

        updateProgress();

        window.addEventListener(
            'scroll',
            updateProgress,
            { passive: true }
        );

        window.addEventListener(
            'resize',
            updateProgress
        );
    }

    function createAmbientOrbs() {
        const first = document.createElement(
            'div'
        );
        const second = document.createElement(
            'div'
        );

        first.className =
            'index-motion-orb index-motion-orb-one';
        second.className =
            'index-motion-orb index-motion-orb-two';

        first.setAttribute(
            'aria-hidden',
            'true'
        );
        second.setAttribute(
            'aria-hidden',
            'true'
        );

        body.append(
            first,
            second
        );

        let frameRequested = false;

        const updateOrbs = () => {
            if (frameRequested) {
                return;
            }

            frameRequested = true;

            window.requestAnimationFrame(
                () => {
                    const position =
                        window.scrollY;

                    first.style.setProperty(
                        '--orb-y',
                        `${position * 0.08}px`
                    );
                    second.style.setProperty(
                        '--orb-y',
                        `${position * -0.045}px`
                    );

                    frameRequested = false;
                }
            );
        };

        updateOrbs();

        window.addEventListener(
            'scroll',
            updateOrbs,
            { passive: true }
        );
    }

    function setupHeroLayers() {
        if (
            !heroSection ||
            !heroSlider
        ) {
            return;
        }

        const spotlight =
            document.createElement(
                'div'
            );

        spotlight.className =
            'index-hero-spotlight';
        spotlight.setAttribute(
            'aria-hidden',
            'true'
        );

        const particles =
            document.createElement(
                'div'
            );

        particles.className =
            'index-hero-particles';
        particles.setAttribute(
            'aria-hidden',
            'true'
        );

        for (
            let index = 0;
            index < 16;
            index++
        ) {
            const particle =
                document.createElement(
                    'span'
                );

            const size =
                8 +
                Math.random() *
                    24;
            const duration =
                9 +
                Math.random() *
                    9;
            const delay =
                Math.random() *
                    -18;
            const drift =
                -90 +
                Math.random() *
                    180;

            particle.className =
                'index-hero-particle';

            particle.style.left =
                `${Math.random() * 100}%`;
            particle.style.setProperty(
                '--particle-size',
                `${size}px`
            );
            particle.style.setProperty(
                '--particle-duration',
                `${duration}s`
            );
            particle.style.setProperty(
                '--particle-delay',
                `${delay}s`
            );
            particle.style.setProperty(
                '--particle-drift',
                `${drift}px`
            );

            particles.appendChild(
                particle
            );
        }

        heroSection.append(
            spotlight,
            particles
        );

        heroArticles.forEach(
            (article, articleIndex) => {
                const background =
                    article.querySelector(
                        ':scope > div.bg-cover'
                    );

                const grid =
                    article.querySelector(
                        '.hero-grid'
                    );

                const copy =
                    article.querySelector(
                        '.max-w-2xl, .max-w-lg'
                    );

                if (background) {
                    background.classList.add(
                        'index-hero-background'
                    );
                }

                if (grid) {
                    grid.classList.add(
                        'index-hero-grid'
                    );
                }

                if (copy) {
                    copy.classList.add(
                        'index-hero-copy'
                    );
                }

                article
                    .querySelectorAll(
                        '.hero-book'
                    )
                    .forEach(
                        (book, bookIndex) => {
                            book.classList.add(
                                'index-hero-book'
                            );

                            book.style.setProperty(
                                '--book-depth',
                                String(
                                    0.18 +
                                    articleIndex *
                                        0.04 +
                                    bookIndex *
                                        0.08
                                )
                            );
                        }
                    );
            }
        );

        const updateActiveArticle = () => {
            let activeIndex = 0;

            heroDots.forEach(
                (dot, index) => {
                    if (
                        dot.getAttribute(
                            'aria-current'
                        ) === 'true'
                    ) {
                        activeIndex =
                            index;
                    }
                }
            );

            heroArticles.forEach(
                (article, index) => {
                    const active =
                        index ===
                        activeIndex;

                    article.classList.toggle(
                        'is-motion-active',
                        active
                    );

                    article.setAttribute(
                        'aria-hidden',
                        active
                            ? 'false'
                            : 'true'
                    );
                }
            );
        };

        updateActiveArticle();

        heroDots.forEach(dot => {
            const observer =
                new MutationObserver(
                    updateActiveArticle
                );

            observer.observe(
                dot,
                {
                    attributes: true,
                    attributeFilter: [
                        'aria-current',
                    ],
                }
            );
        });

        heroSection.addEventListener(
            'pointermove',
            event => {
                const bounds =
                    heroSection
                        .getBoundingClientRect();

                const relativeX =
                    (
                        event.clientX -
                        bounds.left
                    ) /
                    bounds.width;

                const relativeY =
                    (
                        event.clientY -
                        bounds.top
                    ) /
                    bounds.height;

                const offsetX =
                    (
                        relativeX -
                        0.5
                    ) *
                    32;
                const offsetY =
                    (
                        relativeY -
                        0.5
                    ) *
                    24;

                heroSection.style
                    .setProperty(
                        '--index-pointer-x',
                        `${relativeX * 100}%`
                    );
                heroSection.style
                    .setProperty(
                        '--index-pointer-y',
                        `${relativeY * 100}%`
                    );
                heroSection.style
                    .setProperty(
                        '--hero-parallax-x',
                        `${offsetX}px`
                    );
                heroSection.style
                    .setProperty(
                        '--hero-parallax-y',
                        `${offsetY}px`
                    );
            }
        );

        heroSection.addEventListener(
            'pointerleave',
            () => {
                heroSection.style
                    .setProperty(
                        '--hero-parallax-x',
                        '0px'
                    );
                heroSection.style
                    .setProperty(
                        '--hero-parallax-y',
                        '0px'
                    );
                heroSection.style
                    .setProperty(
                        '--index-pointer-x',
                        '50%'
                    );
                heroSection.style
                    .setProperty(
                        '--index-pointer-y',
                        '45%'
                    );
            }
        );
    }

    function setupRevealAnimations() {
        const sections = Array.from(
            document.querySelectorAll(
                'body > section'
            )
        ).filter(section => {
            return (
                section !== heroSection &&
                !section.hasAttribute(
                    'aria-label'
                )
            );
        });

        const revealTargets = [];

        sections.forEach(section => {
            const heading =
                section.querySelector(
                    'h2'
                );

            const headingBlock =
                heading
                    ? heading.parentElement
                    : null;

            if (headingBlock) {
                headingBlock.classList.add(
                    'index-reveal',
                    'index-section-heading'
                );
                revealTargets.push(
                    headingBlock
                );
            }

            const cards =
                section.querySelectorAll(
                    '.product-card, .genre-card'
                );

            cards.forEach(
                (card, index) => {
                    card.classList.add(
                        'index-reveal'
                    );
                    card.style.setProperty(
                        '--index-reveal-delay',
                        `${
                            Math.min(
                                index,
                                7
                            ) * 70
                        }ms`
                    );
                    revealTargets.push(
                        card
                    );
                }
            );

            const directBlocks =
                section.querySelectorAll(
                    ':scope > div > div:not(.grid)'
                );

            directBlocks.forEach(
                (block, index) => {
                    if (
                        block ===
                        headingBlock ||
                        block.closest(
                            '.product-card, .genre-card'
                        )
                    ) {
                        return;
                    }

                    block.classList.add(
                        'index-reveal'
                    );
                    block.style.setProperty(
                        '--index-reveal-delay',
                        `${index * 55}ms`
                    );
                    revealTargets.push(
                        block
                    );
                }
            );
        });

        const observer =
            new IntersectionObserver(
                entries => {
                    entries.forEach(entry => {
                        if (
                            !entry.isIntersecting
                        ) {
                            return;
                        }

                        entry.target
                            .classList.add(
                                'is-visible'
                            );

                        observer.unobserve(
                            entry.target
                        );
                    });
                },
                {
                    threshold: 0.12,
                    rootMargin:
                        '0px 0px -8% 0px',
                }
            );

        revealTargets.forEach(target => {
            observer.observe(target);
        });
    }

    function setupTrustStrip() {
        if (!heroSection) {
            return;
        }

        const trustSection =
            heroSection.nextElementSibling;

        if (!trustSection) {
            return;
        }

        const items = Array.from(
            trustSection.querySelectorAll(
                ':scope > div > div'
            )
        );

        items.forEach(
            (item, index) => {
                item.classList.add(
                    'index-trust-item',
                    'index-reveal'
                );
                item.style.setProperty(
                    '--index-reveal-delay',
                    `${index * 90}ms`
                );

                const icon =
                    item.firstElementChild;

                if (icon) {
                    icon.classList.add(
                        'index-trust-icon'
                    );
                }
            }
        );

        const observer =
            new IntersectionObserver(
                entries => {
                    entries.forEach(entry => {
                        if (
                            !entry.isIntersecting
                        ) {
                            return;
                        }

                        entry.target
                            .classList.add(
                                'is-visible'
                            );

                        observer.unobserve(
                            entry.target
                        );
                    });
                },
                {
                    threshold: 0.25,
                }
            );

        items.forEach(item => {
            observer.observe(item);
        });
    }

    function setupTiltCards() {
        const cards = Array.from(
            document.querySelectorAll(
                '.product-card, .genre-card'
            )
        );

        cards.forEach(card => {
            card.classList.add(
                'index-motion-card'
            );

            card.addEventListener(
                'pointermove',
                event => {
                    const bounds =
                        card.getBoundingClientRect();

                    const x =
                        (
                            event.clientX -
                            bounds.left
                        ) /
                        bounds.width;
                    const y =
                        (
                            event.clientY -
                            bounds.top
                        ) /
                        bounds.height;

                    const rotateY =
                        (
                            x -
                            0.5
                        ) *
                        7;
                    const rotateX =
                        (
                            0.5 -
                            y
                        ) *
                        6;

                    card.style.setProperty(
                        '--index-tilt-x',
                        `${rotateX}deg`
                    );
                    card.style.setProperty(
                        '--index-tilt-y',
                        `${rotateY}deg`
                    );
                    card.style.setProperty(
                        '--index-shine-x',
                        `${x * 100}%`
                    );
                    card.style.setProperty(
                        '--index-shine-y',
                        `${y * 100}%`
                    );
                    card.style.setProperty(
                        '--index-shine-opacity',
                        '1'
                    );
                }
            );

            card.addEventListener(
                'pointerleave',
                () => {
                    card.style.setProperty(
                        '--index-tilt-x',
                        '0deg'
                    );
                    card.style.setProperty(
                        '--index-tilt-y',
                        '0deg'
                    );
                    card.style.setProperty(
                        '--index-shine-opacity',
                        '0'
                    );
                }
            );
        });
    }

    function setupRankingCounters() {
        const rankingSection =
            document.getElementById(
                'rankings'
            );

        if (!rankingSection) {
            return;
        }

        const rankBadges = Array.from(
            rankingSection.querySelectorAll(
                '.cover-stage > span'
            )
        );

        rankBadges.forEach(badge => {
            const raw =
                badge.textContent.trim();
            const target =
                Number(raw);

            if (
                !Number.isFinite(target)
            ) {
                return;
            }

            badge.dataset.rankTarget =
                String(target);
            badge.dataset.rankWidth =
                String(raw.length);
            badge.textContent =
                raw.padStart(
                    raw.length,
                    '0'
                );
            badge.classList.add(
                'index-rank-number'
            );
        });

        let started = false;

        const observer =
            new IntersectionObserver(
                entries => {
                    if (
                        started ||
                        !entries.some(
                            entry =>
                                entry.isIntersecting
                        )
                    ) {
                        return;
                    }

                    started = true;

                    rankBadges.forEach(
                        (badge, index) => {
                            const target =
                                Number(
                                    badge.dataset
                                        .rankTarget
                                );
                            const width =
                                Number(
                                    badge.dataset
                                        .rankWidth
                                );

                            const startTime =
                                performance.now() +
                                index *
                                    80;
                            const duration =
                                520;

                            const tick =
                                currentTime => {
                                    const progress =
                                        Math.min(
                                            1,
                                            Math.max(
                                                0,
                                                (
                                                    currentTime -
                                                    startTime
                                                ) /
                                                duration
                                            )
                                        );

                                    const eased =
                                        1 -
                                        Math.pow(
                                            1 -
                                            progress,
                                            3
                                        );

                                    const value =
                                        Math.max(
                                            0,
                                            Math.round(
                                                target *
                                                eased
                                            )
                                        );

                                    badge.textContent =
                                        String(
                                            value
                                        ).padStart(
                                            width,
                                            '0'
                                        );

                                    if (
                                        progress <
                                        1
                                    ) {
                                        window
                                            .requestAnimationFrame(
                                                tick
                                            );
                                    }
                                };

                            window
                                .requestAnimationFrame(
                                    tick
                                );
                        }
                    );

                    observer.disconnect();
                },
                {
                    threshold: 0.25,
                }
            );

        observer.observe(
            rankingSection
        );
    }

    function setupMagneticButtons() {
        const buttons = Array.from(
            document.querySelectorAll(
                '#heroSlider a.rounded-xl'
            )
        );

        buttons.forEach(button => {
            button.classList.add(
                'index-magnetic'
            );

            button.addEventListener(
                'pointermove',
                event => {
                    const bounds =
                        button
                            .getBoundingClientRect();

                    const x =
                        event.clientX -
                        bounds.left -
                        bounds.width /
                            2;
                    const y =
                        event.clientY -
                        bounds.top -
                        bounds.height /
                            2;

                    button.style.setProperty(
                        '--magnetic-x',
                        `${x * 0.08}px`
                    );
                    button.style.setProperty(
                        '--magnetic-y',
                        `${y * 0.08}px`
                    );
                }
            );

            button.addEventListener(
                'pointerleave',
                () => {
                    button.style.setProperty(
                        '--magnetic-x',
                        '0px'
                    );
                    button.style.setProperty(
                        '--magnetic-y',
                        '0px'
                    );
                }
            );
        });
    }

    if (reduceMotion) {
        body.classList.add(
            'index-reduced-motion'
        );
        return;
    }

    createScrollProgress();
    createAmbientOrbs();
    setupHeroLayers();
    setupTrustStrip();
    setupRevealAnimations();
    setupTiltCards();
    setupRankingCounters();
    setupMagneticButtons();
})();