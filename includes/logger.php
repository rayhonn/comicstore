<?php

function sanitize_log_text(string $value): string
{
    return str_replace(
        ["\r", "\n"],
        ' ',
        trim($value)
    );
}

function app_log(
    string $level,
    string $component,
    string $message,
    array $context = []
): void {
    $allowed_levels = [
        'DEBUG',
        'INFO',
        'WARNING',
        'ERROR',
        'CRITICAL',
    ];

    $level = strtoupper(trim($level));

    if (!in_array($level, $allowed_levels, true)) {
        $level = 'ERROR';
    }

    $component = preg_replace(
        '/[^A-Za-z0-9_.-]/',
        '_',
        trim($component)
    );

    if (
        !is_string($component) ||
        $component === ''
    ) {
        $component = 'Application';
    }

    $entry =
        '[MangaVault][' .
        $level .
        '][' .
        $component .
        '] ' .
        sanitize_log_text($message);

    $safe_context = [];

    foreach ($context as $key => $value) {
        if (
            !is_scalar($value) &&
            $value !== null
        ) {
            continue;
        }

        $safe_context[(string) $key] =
            is_string($value)
                ? sanitize_log_text($value)
                : $value;
    }

    if ($safe_context !== []) {
        $encoded_context = json_encode(
            $safe_context,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        if (is_string($encoded_context)) {
            $entry .= ' ' . $encoded_context;
        }
    }

    error_log($entry);
}

function app_error_log(string $message): void
{
    $trace = debug_backtrace(
        DEBUG_BACKTRACE_IGNORE_ARGS,
        1
    );

    $caller_file = $trace[0]['file'] ?? '';

    $component =
        $caller_file !== ''
            ? pathinfo(
                $caller_file,
                PATHINFO_FILENAME
            )
            : 'Application';

    app_log(
        'ERROR',
        $component,
        $message
    );
}

function app_log_exception(
    string $component,
    Throwable $exception,
    array $context = []
): void {
    $context['exception_type'] =
        get_class($exception);

    app_log(
        'ERROR',
        $component,
        $exception->getMessage(),
        $context
    );
}