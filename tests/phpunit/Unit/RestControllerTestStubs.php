<?php
/**
 * Namespace-local WordPress stubs for Rest\Controllers\* tests.
 *
 * Controllers call `rest_ensure_response`, `current_user_can`,
 * `sanitize_title`, and `register_rest_route` unqualified — PHP resolves
 * unqualified calls to the current namespace first. The stubs below live in
 * DataFlair\Toplists\Rest and DataFlair\Toplists\Rest\Controllers so the
 * controller/router code under test hits these instead of WordPress.
 *
 * `RestControllerTestStubs::$registered_routes` records every registration
 * RestRouter makes, so RouterTest can assert the three routes exist.
 *
 * We also declare minimal WP_REST_Response / WP_REST_Request / WP_Error / wpdb
 * shells in global scope so controllers can return them without a real WP
 * bootstrap.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response
        {
            /** @var mixed */
            public $data;
            /** @var array<string,string> */
            public array $headers = [];

            public function __construct($data = null)
            {
                $this->data = $data;
            }

            public function header(string $key, string $value): void
            {
                $this->headers[$key] = $value;
            }

            public function get_data()
            {
                return $this->data;
            }
        }
    }

    if (!class_exists('WP_Error')) {
        class WP_Error
        {
            public string $code;
            public string $message;
            public array $data;

            public function __construct(string $code = '', string $message = '', array $data = [])
            {
                $this->code    = $code;
                $this->message = $message;
                $this->data    = $data;
            }

            public function get_error_message(): string
            {
                return $this->message;
            }

            public function get_error_code(): string
            {
                return $this->code;
            }
        }
    }

    if (!class_exists('WP_REST_Request')) {
        class WP_REST_Request implements \ArrayAccess
        {
            /** @var array<string,mixed> */
            private array $params;

            public function __construct(array $params = [])
            {
                $this->params = $params;
            }

            public function get_param(string $name)
            {
                return $this->params[$name] ?? null;
            }

            public function offsetExists($offset): bool { return isset($this->params[$offset]); }
            public function offsetGet($offset): mixed   { return $this->params[$offset] ?? null; }
            public function offsetSet($offset, $value): void { $this->params[$offset] = $value; }
            public function offsetUnset($offset): void  { unset($this->params[$offset]); }
        }
    }

    if (!class_exists('wpdb')) {
        class wpdb
        {
            public string $prefix    = 'wp_';
            public string $last_error = '';
        }
    }

    if (!class_exists('RestControllerTestStubs')) {
        final class RestControllerTestStubs
        {
            /**
             * Every `register_rest_route()` call recorded by the stubbed function.
             *
             * @var array<int,array{namespace:string,route:string,args:array<string,mixed>}>
             */
            public static array $registered_routes = [];

            public static bool $canEditPosts    = true;
            public static bool $canManageOptions = true;

            public static function reset(): void
            {
                self::$registered_routes = [];
                self::$canEditPosts      = true;
                self::$canManageOptions  = true;
            }
        }
    }
}

namespace DataFlair\Toplists\Rest {
    if (!function_exists(__NAMESPACE__ . '\\register_rest_route')) {
        function register_rest_route(string $namespace, string $route, array $args): void
        {
            \RestControllerTestStubs::$registered_routes[] = [
                'namespace' => $namespace,
                'route'     => $route,
                'args'      => $args,
            ];
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\current_user_can')) {
        function current_user_can(string $capability): bool
        {
            if ($capability === 'manage_options') {
                return \RestControllerTestStubs::$canManageOptions;
            }
            return \RestControllerTestStubs::$canEditPosts;
        }
    }
}

namespace DataFlair\Toplists\Rest\Controllers {
    if (!function_exists(__NAMESPACE__ . '\\rest_ensure_response')) {
        function rest_ensure_response($data): \WP_REST_Response
        {
            if ($data instanceof \WP_REST_Response) {
                return $data;
            }
            return new \WP_REST_Response($data);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\sanitize_title')) {
        function sanitize_title(string $title): string
        {
            $title = strtolower(trim($title));
            $title = preg_replace('/[^a-z0-9]+/', '-', $title) ?? '';
            return trim($title, '-');
        }
    }
}
