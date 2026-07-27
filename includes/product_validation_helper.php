<?php

final class ProductInputValidationException extends RuntimeException
{
}

function productInputLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

function productInputScalar(
    array $input,
    string $key,
    string $label
): string {
    $value = $input[$key] ?? '';

    if (is_array($value)) {
        throw new ProductInputValidationException(
            $label . ' is invalid.'
        );
    }

    return trim((string) $value);
}

function validateProductText(
    array $input,
    string $key,
    string $label,
    int $maximumLength,
    bool $required = false
): string {
    $value = productInputScalar(
        $input,
        $key,
        $label
    );

    if ($required && $value === '') {
        throw new ProductInputValidationException(
            $label . ' is required.'
        );
    }

    if (
        function_exists('mb_check_encoding') &&
        !mb_check_encoding($value, 'UTF-8')
    ) {
        throw new ProductInputValidationException(
            $label . ' contains invalid characters.'
        );
    }

    if (productInputLength($value) > $maximumLength) {
        throw new ProductInputValidationException(
            $label . ' must not exceed ' .
            $maximumLength .
            ' characters.'
        );
    }

    return $value;
}

function validateProductIntegerValue(
    mixed $value,
    string $label,
    int $minimum,
    int $maximum
): int {
    if (is_array($value)) {
        throw new ProductInputValidationException(
            $label . ' is invalid.'
        );
    }

    $value = trim((string) $value);

    if (!preg_match('/\A\d+\z/', $value)) {
        throw new ProductInputValidationException(
            $label . ' must be a whole number.'
        );
    }

    $normalized = ltrim($value, '0');

    if ($normalized === '') {
        $normalized = '0';
    }

    if (strlen($normalized) > 10) {
        throw new ProductInputValidationException(
            $label . ' is outside the allowed range.'
        );
    }

    $integer = (int) $normalized;

    if (
        $integer < $minimum ||
        $integer > $maximum
    ) {
        throw new ProductInputValidationException(
            $label . ' must be between ' .
            $minimum .
            ' and ' .
            $maximum .
            '.'
        );
    }

    return $integer;
}

function validateProductInteger(
    array $input,
    string $key,
    string $label,
    int $minimum,
    int $maximum,
    bool $required = false
): ?int {
    $value = productInputScalar(
        $input,
        $key,
        $label
    );

    if ($value === '') {
        if ($required) {
            throw new ProductInputValidationException(
                $label . ' is required.'
            );
        }

        return null;
    }

    return validateProductIntegerValue(
        $value,
        $label,
        $minimum,
        $maximum
    );
}

function validateProductDecimal(
    array $input,
    string $key,
    string $label,
    int $maximumIntegerDigits,
    bool $required = false
): ?string {
    $value = productInputScalar(
        $input,
        $key,
        $label
    );

    if ($value === '') {
        if ($required) {
            throw new ProductInputValidationException(
                $label . ' is required.'
            );
        }

        return null;
    }

    if (
        !preg_match(
            '/\A(\d+)(?:\.(\d{1,2}))?\z/',
            $value,
            $matches
        )
    ) {
        throw new ProductInputValidationException(
            $label .
            ' must be a non-negative number with at most two decimal places.'
        );
    }

    $whole = ltrim($matches[1], '0');

    if ($whole === '') {
        $whole = '0';
    }

    if (strlen($whole) > $maximumIntegerDigits) {
        throw new ProductInputValidationException(
            $label . ' is outside the allowed range.'
        );
    }

    $fraction = str_pad(
        $matches[2] ?? '',
        2,
        '0'
    );

    return $whole . '.' . $fraction;
}

function productAllowedIds(
    array $rows,
    string $idColumn
): array {
    $allowedIds = [];

    foreach ($rows as $row) {
        $id = (int) ($row[$idColumn] ?? 0);

        if ($id > 0) {
            $allowedIds[$id] = true;
        }
    }

    return $allowedIds;
}

function validateProductFormInput(
    array $input,
    array $categories,
    array $genres
): array {
    $title = validateProductText(
        $input,
        'product_title',
        'Title',
        255,
        true
    );

    $series = validateProductText(
        $input,
        'product_series',
        'Series',
        255
    );

    $volume = validateProductInteger(
        $input,
        'product_volume_number',
        'Volume number',
        1,
        1000000
    );

    $author = validateProductText(
        $input,
        'product_author',
        'Author',
        255
    );

    $publisher = validateProductText(
        $input,
        'product_publisher',
        'Publisher',
        255
    );

    $isbn = validateProductText(
        $input,
        'product_isbn',
        'ISBN',
        20
    );

    $description = validateProductText(
        $input,
        'product_description',
        'Description',
        5000
    );

    $price = validateProductDecimal(
        $input,
        'product_price',
        'Price',
        8,
        true
    );

    $type = productInputScalar(
        $input,
        'product_type',
        'Product type'
    );

    if (
        !in_array(
            $type,
            [
                'physical',
                'ebook',
            ],
            true
        )
    ) {
        throw new ProductInputValidationException(
            'Product type is invalid.'
        );
    }

    $categoryId = validateProductInteger(
        $input,
        'product_category_id',
        'Category',
        1,
        2147483647
    );

    $allowedCategoryIds = productAllowedIds(
        $categories,
        'category_id'
    );

    if (
        $categoryId !== null &&
        !isset($allowedCategoryIds[$categoryId])
    ) {
        throw new ProductInputValidationException(
            'Category is invalid.'
        );
    }

    $submittedGenres = $input['genres'] ?? [];

    if (!is_array($submittedGenres)) {
        throw new ProductInputValidationException(
            'Genres are invalid.'
        );
    }

    if (count($submittedGenres) > 100) {
        throw new ProductInputValidationException(
            'Too many genres were selected.'
        );
    }

    $allowedGenreIds = productAllowedIds(
        $genres,
        'genre_id'
    );

    $selectedGenres = [];

    foreach ($submittedGenres as $submittedGenre) {
        $genreId = validateProductIntegerValue(
            $submittedGenre,
            'Genre',
            1,
            2147483647
        );

        if (!isset($allowedGenreIds[$genreId])) {
            throw new ProductInputValidationException(
                'One or more selected genres are invalid.'
            );
        }

        $selectedGenres[$genreId] = $genreId;
    }

    $validated = [
        'title' => $title,
        'series' => $series,
        'volume' => $volume,
        'author' => $author,
        'publisher' => $publisher,
        'isbn' => $isbn,
        'description' => $description,
        'price' => $price,
        'category_id' => $categoryId,
        'type' => $type,
        'selected_genres' => array_values(
            $selectedGenres
        ),
        'stock' => null,
        'threshold' => null,
        'weight' => null,
        'dimensions' => '',
        'download_limit' => null,
        'file_format' => null,
    ];

    if ($type === 'physical') {
        $validated['stock'] = validateProductInteger(
            $input,
            'physical_stock_quantity',
            'Stock quantity',
            0,
            1000000,
            true
        );

        $validated['threshold'] = validateProductInteger(
            $input,
            'physical_low_stock_threshold',
            'Low stock threshold',
            0,
            1000000,
            true
        );

        $validated['weight'] = validateProductDecimal(
            $input,
            'physical_weight',
            'Weight',
            6
        );

        $validated['dimensions'] = validateProductText(
            $input,
            'physical_dimensions',
            'Dimensions',
            50
        );
    } else {
        $validated['download_limit'] = validateProductInteger(
            $input,
            'ebook_download_limit',
            'Download limit',
            1,
            1000,
            true
        );

        $fileFormat = strtoupper(
            productInputScalar(
                $input,
                'ebook_file_format',
                'E-book file format'
            )
        );

        if (
            !in_array(
                $fileFormat,
                [
                    'PDF',
                    'EPUB',
                ],
                true
            )
        ) {
            throw new ProductInputValidationException(
                'E-book file format is invalid.'
            );
        }

        $validated['file_format'] = $fileFormat;
    }

    return $validated;
}