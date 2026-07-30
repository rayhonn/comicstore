(() => {
    'use strict';

    const eyeIcon = `
        <svg
            viewBox="0 0 24 24"
            width="20"
            height="20"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12"></path>
            <circle cx="12" cy="12" r="3"></circle>
        </svg>
    `;

    const eyeOffIcon = `
        <svg
            viewBox="0 0 24 24"
            width="20"
            height="20"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <path d="M3 3l18 18"></path>
            <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"></path>
            <path d="M9.9 4.2A10.4 10.4 0 0 1 12 4c6.5 0 10 8 10 8a18.7 18.7 0 0 1-2.1 3.3"></path>
            <path d="M6.6 6.6C3.7 8.5 2 12 2 12s3.5 8 10 8a9.8 9.8 0 0 0 4.1-.9"></path>
        </svg>
    `;

    function addPasswordToggle(input) {
        if (input.dataset.passwordToggleReady === 'true') {
            return;
        }

        input.dataset.passwordToggleReady = 'true';

        const wrapper = document.createElement('div');
        wrapper.style.position = 'relative';
        wrapper.style.width = '100%';

        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);

        input.style.width = '100%';
        input.style.paddingRight = '3rem';

        const button = document.createElement('button');

        button.type = 'button';
        button.innerHTML = eyeIcon;
        button.setAttribute('aria-label', 'Show password');
        button.setAttribute('aria-pressed', 'false');

        Object.assign(button.style, {
            position: 'absolute',
            top: '50%',
            right: '0.75rem',
            transform: 'translateY(-50%)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            width: '2rem',
            height: '2rem',
            padding: '0',
            border: '0',
            background: 'transparent',
            color: '#6b7280',
            cursor: 'pointer',
            zIndex: '2',
        });

        button.addEventListener('click', () => {
            const shouldShow = input.type === 'password';

            input.type = shouldShow ? 'text' : 'password';
            button.innerHTML = shouldShow
                ? eyeOffIcon
                : eyeIcon;

            button.setAttribute(
                'aria-label',
                shouldShow
                    ? 'Hide password'
                    : 'Show password'
            );

            button.setAttribute(
                'aria-pressed',
                shouldShow ? 'true' : 'false'
            );

            input.focus({
                preventScroll: true,
            });

            const cursorPosition = input.value.length;

            input.setSelectionRange(
                cursorPosition,
                cursorPosition
            );
        });

        wrapper.appendChild(button);
    }

    document.addEventListener('DOMContentLoaded', () => {
        document
            .querySelectorAll('input[type="password"]')
            .forEach(addPasswordToggle);
    });
})();