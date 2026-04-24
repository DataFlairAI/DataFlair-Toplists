<?php
/**
 * Minimal WP_Error polyfill for unit tests that exercise code calling
 * `is_wp_error()` / `$wp_error->get_error_code()` without a running WordPress.
 *
 * Only loaded if WP_Error is not already declared — tests that run under the
 * real WordPress bootstrap pick up the core class instead.
 */

declare(strict_types=1);

if (!class_exists('WP_Error')) {
    /**
     * @psalm-suppress UnusedClass
     */
    class WP_Error
    {
        /** @var string */
        public $code = '';

        /** @var string */
        public $message = '';

        /** @var array<string, mixed> */
        public $data = [];

        public function __construct(string $code = '', string $message = '', array $data = [])
        {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        /**
         * @return array<string, mixed>
         */
        public function get_error_data(): array
        {
            return $this->data;
        }
    }
}
