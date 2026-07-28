(() => {
    'use strict';

    const input = document.getElementById(
        'productQuantityInput'
    );
    const decreaseButton = document.getElementById(
        'productQuantityDecrease'
    );
    const increaseButton = document.getElementById(
        'productQuantityIncrease'
    );

    if (
        !input ||
        !decreaseButton ||
        !increaseButton
    ) {
        return;
    }

    function readMinimum() {
        return Math.max(
            1,
            Number(input.min) || 1
        );
    }

    function readMaximum() {
        return Math.max(
            readMinimum(),
            Number(input.max) || readMinimum()
        );
    }

    function clampQuantity(value) {
        const minimum = readMinimum();
        const maximum = readMaximum();

        return Math.min(
            maximum,
            Math.max(
                minimum,
                Number(value) || minimum
            )
        );
    }

    function updateQuantity(value) {
        const quantity = clampQuantity(
            value
        );

        input.value = String(quantity);

        decreaseButton.disabled =
            quantity <= readMinimum();

        increaseButton.disabled =
            quantity >= readMaximum();
    }

    decreaseButton.addEventListener(
        'click',
        () => {
            updateQuantity(
                Number(input.value) - 1
            );
        }
    );

    increaseButton.addEventListener(
        'click',
        () => {
            updateQuantity(
                Number(input.value) + 1
            );
        }
    );

    input.addEventListener(
        'change',
        () => {
            updateQuantity(input.value);
        }
    );

    input.addEventListener(
        'blur',
        () => {
            updateQuantity(input.value);
        }
    );

    updateQuantity(input.value);
})();