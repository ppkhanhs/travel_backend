<?php

return [
    'merchant_code' => env('SEPAY_MERCHANT_CODE'),
    'api_key' => env('SEPAY_API_KEY'),
    'checksum_key' => env('SEPAY_CHECKSUM_KEY'),
    'payment_url' => env('SEPAY_PAYMENT_URL'),
    'return_url' => env('SEPAY_RETURN_URL'),
    'log_channel' => env('SEPAY_LOG_CHANNEL', 'stack'),
    'webhook_token' => env('SEPAY_WEBHOOK_TOKEN'),
    'pattern' => env('SEPAY_MATCH_PATTERN', 'BOOKING-'),
    'account' => env('SEPAY_ACCOUNT'),
    'bank' => env('SEPAY_BANK'),
    'qr_url' => env('SEPAY_QR_URL', 'https://qr.sepay.vn/img'),
];
