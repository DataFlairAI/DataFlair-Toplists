<?php
/**
 * Namespace-local WordPress function stubs for AjaxRouterTest.
 *
 * AjaxRouter lives in DataFlair\Toplists\Admin and calls
 * check_ajax_referer / current_user_can / wp_send_json_* unqualified.
 * PHP resolves those to the current namespace first, so we declare the
 * stubs there — isolating them from Brain Monkey / global stubs used by
 * other tests.
 *
 * AjaxRouterTestHalt is the global exception the stubs throw instead of
 * exit()ing; AjaxRouterTest catches it to inspect the payload.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('AjaxRouterTestHalt')) {
        final class AjaxRouterTestHalt extends \RuntimeException
        {
            /** @var string */
            public $kind;
            /** @var array */
            public $data;
            public function __construct(string $kind, array $data)
            {
                parent::__construct('AjaxRouterTestHalt');
                $this->kind = $kind;
                $this->data = $data;
            }
        }
    }

    if (!class_exists('AjaxRouterTestStubs')) {
        final class AjaxRouterTestStubs
        {
            public static bool $nonceValid = true;
            public static bool $canUser    = true;

            public static function reset(): void
            {
                self::$nonceValid = true;
                self::$canUser    = true;
            }
        }
    }
}

namespace DataFlair\Toplists\Admin {
    if (!function_exists(__NAMESPACE__ . '\\check_ajax_referer')) {
        function check_ajax_referer($action, $query_arg = false, $die = true)
        {
            return \AjaxRouterTestStubs::$nonceValid;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\current_user_can')) {
        function current_user_can($capability)
        {
            return \AjaxRouterTestStubs::$canUser;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\wp_send_json_success')) {
        function wp_send_json_success($data = null)
        {
            throw new \AjaxRouterTestHalt('success', is_array($data) ? $data : ['message' => (string) $data]);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\wp_send_json_error')) {
        function wp_send_json_error($data = null)
        {
            throw new \AjaxRouterTestHalt('error', is_array($data) ? $data : ['message' => (string) $data]);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\add_action')) {
        function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
        {
            // Router registers closures via add_action but dispatch() is
            // what AjaxRouterTest exercises directly. Phase 9.6 added admin
            // registrar tests that DO want to inspect hook wiring, so we
            // mirror into AdminStubs::$actions when that capture container
            // is loaded — keeps both test suites pointed at one source of
            // truth without forcing every Admin\* test to re-declare the
            // namespace stub.
            if (class_exists(\DataFlair\Toplists\Tests\Admin\AdminStubs::class, false)) {
                \DataFlair\Toplists\Tests\Admin\AdminStubs::$actions[] = [
                    'hook'     => $hook,
                    'callback' => $callback,
                    'priority' => $priority,
                ];
            }
            return true;
        }
    }
}
