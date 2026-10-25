<?php
/**
 * PaymentHooks REST client. cURL-based (no Composer deps), HTTPS only, Bearer
 * secret key, optional Idempotency-Key header.
 *
 * One request per call, no automatic retries: a failure throws ApiException
 * with the server's error code and is logged. Retrying is left to the caller
 * (WHMCS re-runs the action, PaymentHooks redelivers webhooks), so a timed-out
 * create is never silently sent twice.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class Client
{
    /** @var Config */
    private $config;
    /** @var Logger */
    private $logger;
    /** @var int */
    private $timeout = 30;

    public function __construct(Config $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function get(string $path, array $query = []): array
    {
        $url = $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        return $this->request('GET', $url, null, null);
    }

    public function post(string $path, array $body, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', $path, $body, $idempotencyKey);
    }

    /**
     * @throws ApiException on transport failure or a non-2xx response
     */
    private function request(string $method, string $path, ?array $body, ?string $idempotencyKey): array
    {
        $problems = $this->config->validate();
        if ($problems) {
            throw new ApiException('PaymentHooks module is misconfigured: ' . implode(' ', $problems), 'MODULE_MISCONFIGURED');
        }

        $url = $this->config->baseUrl . $path;
        $headers = [
            'Authorization: Bearer ' . $this->config->secretKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . self::idempotencyKey($idempotencyKey);
        }

        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                throw new ApiException('Request body could not be encoded as JSON: ' . json_last_error_msg(), 'ENCODE_FAILED');
            }
        }
        $redact = [$this->config->secretKey, $this->config->webhookSecret];
        $summary = ['method' => $method, 'url' => $url, 'body' => $body];
        $action = 'api-' . strtolower($method);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            $message = 'Could not reach PaymentHooks: ' . $err;
            $this->logger->error($action, $summary, $message, $redact);
            throw new ApiException($message, 'CONNECTION_FAILED');
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            $message = 'PaymentHooks returned a non-JSON response (HTTP ' . $http . ').';
            $this->logger->error($action, $summary, substr((string) $raw, 0, 500), $redact);
            throw new ApiException($message, 'UNEXPECTED_RESPONSE', $http);
        }

        if ($http >= 200 && $http < 300) {
            $this->logger->call($action, $summary, $decoded, $redact);
            return $decoded;
        }

        $code = (string) ($decoded['error']['code'] ?? ('HTTP_' . $http));
        $msg  = (string) ($decoded['error']['message'] ?? ('HTTP ' . $http));
        $this->logger->error($action, $summary, $decoded, $redact);
        throw new ApiException($msg, $code, $http, $decoded);
    }

    /** Keeps a key within PaymentHooks' 200-character limit and a safe charset. */
    public static function idempotencyKey(string $key): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9_\-.]/', '-', $key), 0, 200);
    }
}

class ApiException extends \Exception
{
    /** @var string */
    public $errorCode;
    /** @var int */
    public $httpStatus;
    /** @var array */
    public $response;

    public function __construct(string $message, string $errorCode = '', int $httpStatus = 0, array $response = [])
    {
        parent::__construct($message);
        $this->errorCode  = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->response   = $response;
    }
}
