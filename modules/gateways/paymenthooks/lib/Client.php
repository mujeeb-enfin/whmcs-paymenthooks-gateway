<?php
/**
 * PaymentHooks REST client. cURL-based (no Composer deps), enforces HTTPS,
 * sends the Bearer secret key, retries on 429/5xx with backoff, and attaches
 * an idempotency key on create calls.
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
    /** @var int */
    private $maxRetries = 3;

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
        if ($this->config->secretKey === '') {
            throw new ApiException('PaymentHooks secret key is not configured for ' . $this->config->mode . ' mode.');
        }

        $url = $this->config->baseUrl . $path;
        $headers = [
            'Authorization: Bearer ' . $this->config->secretKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            // Keep within PH's 255-char limit and allowed charset.
            $headers[] = 'Idempotency-Key: ' . substr(preg_replace('/[^A-Za-z0-9_\-.]/', '-', $idempotencyKey), 0, 255);
        }

        $payload = $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES) : null;
        $redact = [$this->config->secretKey, $this->config->webhookSecret];

        $attempt = 0;
        $lastError = '';
        while ($attempt < $this->maxRetries) {
            $attempt++;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
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
                $lastError = 'Transport error: ' . $err;
                if ($this->shouldRetry(0, $attempt)) {
                    $this->backoff($attempt);
                    continue;
                }
                $this->logger->error('api-' . strtolower($method), $this->summarise($method, $url, $body), $lastError, $redact);
                throw new ApiException($lastError);
            }

            $decoded = json_decode((string) $raw, true);
            if (!is_array($decoded)) {
                $decoded = ['data' => null, 'error' => ['code' => 'INVALID_PAYLOAD', 'message' => 'Non-JSON response', 'details' => substr((string) $raw, 0, 500)]];
            }

            if ($http >= 200 && $http < 300) {
                $this->logger->call('api-' . strtolower($method), $this->summarise($method, $url, $body), $decoded, $redact);
                return $decoded;
            }

            // Retry on rate limit / transient server errors.
            if ($this->shouldRetry($http, $attempt)) {
                $this->backoff($attempt);
                continue;
            }

            $code = $decoded['error']['code'] ?? ('HTTP_' . $http);
            $msg  = $decoded['error']['message'] ?? ('HTTP ' . $http);
            $this->logger->error('api-' . strtolower($method), $this->summarise($method, $url, $body), $decoded, $redact);
            throw new ApiException($msg, $code, $http, $decoded);
        }

        throw new ApiException($lastError !== '' ? $lastError : 'Request failed after retries');
    }

    private function shouldRetry(int $http, int $attempt): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }
        return $http === 0 || $http === 429 || $http === 500 || $http === 502 || $http === 503;
    }

    private function backoff(int $attempt): void
    {
        // 0.5s, 1s, 2s ... capped.
        $ms = (int) min(2000, 250 * (2 ** $attempt));
        usleep($ms * 1000);
    }

    private function summarise(string $method, string $url, ?array $body): array
    {
        return [
            'method' => $method,
            'url'    => $url,
            'body'   => $body,
        ];
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
