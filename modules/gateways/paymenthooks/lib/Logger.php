<?php
/**
 * Module logging.
 *
 *   call()  -> Module Log (logModuleCall), only with the module's Debug Logging on.
 *   error() -> Module Log as well, AND the WHMCS Activity Log, which WHMCS
 *              always records. logModuleCall alone is dropped unless WHMCS
 *              module debugging is switched on, so errors would vanish.
 *
 * Secrets are redacted in both.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class Logger
{
    /** @var string */
    private $gateway;
    /** @var bool */
    private $enabled;

    public function __construct(string $gateway, bool $enabled)
    {
        $this->gateway = $gateway;
        $this->enabled = $enabled;
    }

    /**
     * @param string $action  short label, e.g. "api-post"
     * @param mixed  $request
     * @param mixed  $response
     * @param array  $replace strings to redact in the stored log
     */
    public function call(string $action, $request, $response, array $replace = []): void
    {
        if ($this->enabled) {
            logModuleCall($this->gateway, $action, $request, $response, '', self::secrets($replace));
        }
    }

    /**
     * Always recorded, not an error: Module Log plus an Activity Log line
     * prefixed "PaymentHooks info", e.g. why an invoice is not offered
     * Subscribe & Auto-pay.
     */
    public function info(string $action, $request, string $message): void
    {
        logModuleCall($this->gateway, $action, $request, $message, '', []);
        $context = is_string($request) ? $request : (string) json_encode($request, JSON_UNESCAPED_SLASHES);
        logActivity(substr('PaymentHooks info [' . $action . ']: ' . $message . ' | ' . $context, 0, 2000));
    }

    /** Always recorded: Activity Log plus Module Log. */
    public function error(string $action, $request, $response, array $replace = []): void
    {
        $secrets = self::secrets($replace);
        logModuleCall($this->gateway, $action, $request, $response, '', $secrets);

        $detail = is_string($response) ? $response : (string) json_encode($response, JSON_UNESCAPED_SLASHES);
        $context = is_string($request) ? $request : (string) json_encode($request, JSON_UNESCAPED_SLASHES);
        $line = 'PaymentHooks error [' . $action . ']: ' . self::redact($detail, $secrets)
            . ' | ' . self::redact($context, $secrets);
        logActivity(substr($line, 0, 2000));
    }

    /** @return string[] non-empty secrets */
    private static function secrets(array $replace): array
    {
        return array_values(array_filter($replace, static function ($v) {
            return is_string($v) && $v !== '';
        }));
    }

    public static function redact(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            $text = str_replace($secret, Config::maskKey($secret), $text);
        }
        return $text;
    }
}
