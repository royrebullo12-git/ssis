<?php
declare(strict_types=1);

if (!defined('SSIS_BOOT')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

require_once __DIR__ . '/helpers.php';

if (!defined('TEXTBEE_API_KEY')) {
    define('TEXTBEE_API_KEY', 'txb_qrAC95UEKubzqX633KbA4K2qlz77949F');
}
if (!defined('TEXTBEE_DEVICE_ID')) {
    define('TEXTBEE_DEVICE_ID', '6ac798d72597187c9cbb0d86');
}
if (!defined('TEXTBEE_ENDPOINT')) {
    define(
        'TEXTBEE_ENDPOINT',
        trim((string)(getenv('https://api.textbee.dev/api/v1/gateway/send-sms') ?: 'https://api.textbee.dev/api/v1/gateway/send-sms'))
    );
}

/**
 * Deliver a message through SMS via TextBee.
 *
 * @return array{sms_sent: bool, errors: list<string>}
 */
function sendSmsNotification(string $recipientPhone, string $smsMessage): array
{
    $result = ['sms_sent' => false, 'errors' => []];

    try {
        wls_send_textbee_sms($recipientPhone, $smsMessage);
        $result['sms_sent'] = true;
    } catch (Throwable $error) {
        error_log('[WLS] SMS delivery failed: ' . $error->getMessage());
        $result['errors'][] = 'SMS delivery failed.';
    }

    return $result;
}

function wls_send_textbee_sms(string $recipientPhone, string $message): void
{
    if (TEXTBEE_API_KEY === '' || TEXTBEE_DEVICE_ID === '') {
        throw new RuntimeException('TextBee API key and device ID must be configured.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required.');
    }

    $endpoint = TEXTBEE_ENDPOINT;
    if (str_contains($endpoint, '{deviceId}')) {
        $endpoint = str_replace('{deviceId}', rawurlencode(TEXTBEE_DEVICE_ID), $endpoint);
    }
    if (!filter_var($endpoint, FILTER_VALIDATE_URL) || parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException('TextBee endpoint must be an HTTPS URL.');
    }

    $phone = trim($recipientPhone);
    if (!preg_match('/^\+?[0-9\s().-]+$/', $phone)) {
        throw new RuntimeException('The registered mobile number contains invalid characters.');
    }
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($phone, '+')) {
        $phone = '+' . $digits;
    } elseif (str_starts_with($digits, '00')) {
        $phone = '+' . substr($digits, 2);
    } elseif (str_starts_with($digits, '63')) {
        $phone = '+' . $digits;
    } elseif (str_starts_with($digits, '0') && in_array(strlen($digits), [10, 11], true)) {
        $phone = '+63' . substr($digits, 1);
    } else {
        throw new RuntimeException('Enter the mobile number in international format, such as +639171234567.');
    }
    if (!preg_match('/^\+[1-9][0-9]{7,14}$/', $phone)) {
        throw new RuntimeException('The registered mobile number is not a valid international number.');
    }

    $payload = json_encode([
        'recipients' => [$phone],
        'message' => $message,
    ], JSON_THROW_ON_ERROR);

    $request = curl_init($endpoint);
    if ($request === false) {
        throw new RuntimeException('Could not initialize the TextBee request.');
    }

    try {
        curl_setopt_array($request, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . TEXTBEE_API_KEY,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($request);
        $status = (int)curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        if ($response === false || $status < 200 || $status >= 300) {
            $curlError = curl_error($request);
            throw new RuntimeException(
                'TextBee did not accept the SMS (HTTP ' . $status
                . ($curlError !== '' ? ', cURL transport error: ' . $curlError : '') . ').'
            );
        }
    } finally {
        curl_close($request);
    }
}