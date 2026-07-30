<?php

require_once __DIR__ . '/config.php';

function procurementReceiptSecret(): string
{
    if (!defined('PROCUREMENT_QR_SECRET')) {
        throw new RuntimeException(
            'Procurement receipt QR security is not configured.'
        );
    }

    $secret = trim((string) PROCUREMENT_QR_SECRET);

    if (strlen($secret) < 32) {
        throw new RuntimeException(
            'PROCUREMENT_QR_SECRET must contain at least 32 characters.'
        );
    }

    return $secret;
}

function procurementReceiptNonce(): string
{
    return bin2hex(random_bytes(16));
}

function procurementReceiptSignature(
    int $deliveryOrderId,
    string $nonce
): string {
    if (
        $deliveryOrderId < 1 ||
        !preg_match('/\A[a-f0-9]{32}\z/', $nonce)
    ) {
        throw new InvalidArgumentException(
            'Invalid delivery receipt token data.'
        );
    }

    return hash_hmac(
        'sha256',
        'delivery-order-receipt|' .
            $deliveryOrderId .
            '|' .
            $nonce,
        procurementReceiptSecret()
    );
}

function procurementReceiptUrl(
    int $deliveryOrderId,
    string $nonce
): string {
    $signature = procurementReceiptSignature(
        $deliveryOrderId,
        $nonce
    );

    return rtrim(APP_URL, '/') .
        '/admin/delivery_receipt.php?' .
        http_build_query([
            'do' => $deliveryOrderId,
            'nonce' => $nonce,
            'sig' => $signature,
        ]);
}

function procurementVerifyReceiptSignature(
    int $deliveryOrderId,
    string $nonce,
    string $signature
): bool {
    if (
        $deliveryOrderId < 1 ||
        !preg_match('/\A[a-f0-9]{32}\z/', $nonce) ||
        !preg_match('/\A[a-f0-9]{64}\z/', $signature)
    ) {
        return false;
    }

    try {
        $expected = procurementReceiptSignature(
            $deliveryOrderId,
            $nonce
        );
    } catch (Throwable $e) {
        return false;
    }

    return hash_equals($expected, $signature);
}
