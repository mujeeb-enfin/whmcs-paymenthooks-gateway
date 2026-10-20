<?php
/**
 * Thin wrapper over WHMCS logModuleCall. Masks secrets and only emits when the
 * Debug Logging toggle is on (errors are always logged).
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
     * @param string $action  short label, e.g. "create-session"
     * @param mixed  $request
     * @param mixed  $response
     * @param array  $replace strings to redact in the stored log
     */
    public function call(string $action, $request, $response, array $replace = []): void
    {
        if (!$this->enabled) {
            return;
        }
        $this->write($action, $request, $response, $replace);
    }

    /** Always logged, regardless of the toggle. */
    public function error(string $action, $request, $response, array $replace = []): void
    {
        $this->write($action, $request, $response, $replace);
    }

    private function write(string $action, $request, $response, array $replace): void
    {
        if (!function_exists('logModuleCall')) {
            return;
        }
        // Never let secrets land in the log in cleartext.
        $replace = array_values(array_filter($replace, static function ($v) {
            return is_string($v) && $v !== '';
        }));
        logModuleCall($this->gateway, $action, $request, $response, '', $replace);
    }
}
