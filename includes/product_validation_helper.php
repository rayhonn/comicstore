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

function validateProductIsbn(array $input): string
{
    $value = productInputScalar(
        $input,
        'product_isbn',
        'ISBN'
    );

    if ($value === '') {
        return '';
    }

    if (productInputLength($value) > 20) {
        throw new ProductInputValidationException(
            'ISBN must not exceed 20 characters.'
        );
    }

    if (
        preg_match(
            '/\A[0-9Xx -]+\z/',
            $value
        ) !== 1
    ) {
        throw new ProductInputValidationException(
            'ISBN may contain only digits, spaces and hyphens. ' .
            'X is allowed only as the final ISBN-10 character.'
        );
    }

    $normalized = strtoupper(
        str_replace(
            [
                ' ',
                '-',
            ],
            '',
            $value
        )
    );

    $length = strlen($normalized);

    if ($length === 10) {
        if (
            preg_match(
                '/\A[0-9]{9}[0-9X]\z/',
                $normalized
            ) !== 1
        ) {
            throw new ProductInputValidationException(
                'ISBN-10 must contain nine digits followed by a digit or X.'
            );
        }

        $checksum = 0;

        for ($index = 0; $index < 10; $index++) {
            $character = $normalized[$index];

            $digit = $character === 'X'
                ? 10
                : (int) $character;

            $checksum +=
                $digit * (10 - $index);
        }

        if ($checksum % 11 !== 0) {
            throw new ProductInputValidationException(
                'ISBN checksum is invalid.'
            );
        }

        return $normalized;
    }

    if ($length === 13) {
        if (
            preg_match(
                '/\A97[89][0-9]{10}\z/',
                $normalized
            ) !== 1
        ) {
            throw new ProductInputValidationException(
                'ISBN-13 must contain 13 digits and start with 978 or 979.'
            );
        }

        $checksum = 0;

        for ($index = 0; $index < 12; $index++) {
            $digit = (int) $normalized[$index];

            $checksum += $digit * (
                $index % 2 === 0
                    ? 1
                    : 3
            );
        }

        $expectedCheckDigit = (
            10 - ($checksum % 10)
        ) % 10;

        if (
            $expectedCheckDigit !==
            (int) $normalized[12]
        ) {
            throw new ProductInputValidationException(
                'ISBN checksum is invalid.'
            );
        }

        return $normalized;
    }

    throw new ProductInputValidationException(
        'ISBN must be a valid ISBN-10 or ISBN-13.'
    );
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

function findExistingProductForDuplicateAdd(
    PDO $pdo,
    array $validated,
    ?int $excludeProductId = null
): ?array {
    $isbn = trim(
        (string) ($validated['isbn'] ?? '')
    );

    if ($isbn !== '') {
        $sql = "
            SELECT
                p.product_id,
                p.product_title,
                p.product_price,
                p.product_type,
                p.product_is_available,
                pp.physical_stock_quantity
            FROM products p
            LEFT JOIN product_physical pp
                ON pp.physical_product_id = p.product_id
            WHERE REPLACE(
                REPLACE(
                    UPPER(
                        COALESCE(
                            p.product_isbn,
                            ''
                        )
                    ),
                    '-',
                    ''
                ),
                ' ',
                ''
            ) = ?
            AND p.product_type = ?
        ";

        $params = [
            strtoupper($isbn),
            $validated['type'],
        ];

        if ($excludeProductId !== null) {
            $sql .= "
                AND p.product_id != ?
            ";
            $params[] = $excludeProductId;
        }

        $sql .= "
            ORDER BY
                p.product_is_available DESC,
                p.product_id DESC
            LIMIT 1
        ";

        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        $matchedProduct =
            $statement->fetch(PDO::FETCH_ASSOC);

        if ($matchedProduct) {
            return $matchedProduct;
        }
    }

    $sql = "
        SELECT
            p.product_id,
            p.product_title,
            p.product_price,
            p.product_type,
            p.product_is_available,
            pp.physical_stock_quantity
        FROM products p
        LEFT JOIN product_physical pp
            ON pp.physical_product_id = p.product_id
        WHERE LOWER(
            TRIM(
                p.product_title
            )
        ) = LOWER(TRIM(?))
        AND p.product_type = ?
    ";

    $params = [
        $validated['title'],
        $validated['type'],
    ];

    if ($excludeProductId !== null) {
        $sql .= "
            AND p.product_id != ?
        ";
        $params[] = $excludeProductId;
    }

    $sql .= "
        ORDER BY
            p.product_is_available DESC,
            p.product_id DESC
        LIMIT 1
    ";

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    $matchedProduct =
        $statement->fetch(PDO::FETCH_ASSOC);

    return $matchedProduct ?: null;
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

    $isbn = validateProductIsbn(
        $input
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