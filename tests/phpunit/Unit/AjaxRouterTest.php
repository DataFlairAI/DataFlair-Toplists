<?php
/**
 * Phase 5 — pins the AjaxRouter contract.
 *
 * The router is the single nonce + capability gate for every admin
 * AJAX action. These tests lock in:
 *   - unknown action short-circuits to wp_send_json_error
 *   - nonce failure short-circuits
 *   - capability check runs after nonce and short-circuits on denial
 *   - handler exceptions are caught + translated to error responses
 *   - a successful handler return is wrapped in wp_send_json_success
 *   - getRegisteredActions() reports every registered action
 *
 * wp_send_json_success / wp_send_json_error are stubbed to throw a
 * namespaced exception instead of exit()ing, so we can inspect the
 * payload without dying.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Admin\AjaxRouter;
use DataFlair\Toplists\Logging\LoggerInterface;
use DataFlair\Toplists\Logging\NullLogger;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/NullLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxRouter.php';
require_once __DIR__ . '/AjaxRouterTestStubs.php';

final class AjaxRouterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \AjaxRouterTestStubs::reset();
        $_GET  = [];
        $_POST = [];
    }

    public function test_unknown_action_sends_json_error(): void
    {
        $router = new AjaxRouter(new NullLogger());

        $payload = $this->capture(fn() => $router->dispatch('does_not_exist'));

        $this->assertSame('error', $payload['kind']);
        $this->assertStringContainsString('Unknown action', $payload['data']['message']);
    }

    public function test_nonce_failure_sends_json_error(): void
    {
        \AjaxRouterTestStubs::$nonceValid = false;

        $router = new AjaxRouter(new NullLogger());
        $router->register('action_x', $this->recordingHandler(), 'nonce_x');

        $payload = $this->capture(fn() => $router->dispatch('action_x'));

        $this->assertSame('error', $payload['kind']);
        $this->assertSame('Invalid or expired nonce.', $payload['data']['message']);
    }

    public function test_capability_denied_sends_json_error_after_nonce_passes(): void
    {
        \AjaxRouterTestStubs::$nonceValid = true;
        \AjaxRouterTestStubs::$canUser    = false;

        $router = new AjaxRouter(new NullLogger());
        $router->register('action_x', $this->recordingHandler(), 'nonce_x');

        $payload = $this->capture(fn() => $router->dispatch('action_x'));

        $this->assertSame('error', $payload['kind']);
        $this->assertSame('Unauthorized', $payload['data']['message']);
    }

    public function test_successful_handler_result_wraps_in_json_success(): void
    {
        \AjaxRouterTestStubs::$nonceValid = true;
        \AjaxRouterTestStubs::$canUser    = true;

        $handler = new class implements AjaxHandlerInterface {
            public function handle(array $request): array
            {
                return ['success' => true, 'data' => ['greeting' => 'hello']];
            }
        };

        $router = new AjaxRouter(new NullLogger());
        $router->register('greet', $handler, 'nonce_greet');

        $payload = $this->capture(fn() => $router->dispatch('greet'));

        $this->assertSame('success', $payload['kind']);
        $this->assertSame(['greeting' => 'hello'], $payload['data']);
    }

    public function test_handler_exception_is_caught_and_translated(): void
    {
        \AjaxRouterTestStubs::$nonceValid = true;
        \AjaxRouterTestStubs::$canUser    = true;

        $handler = new class implements AjaxHandlerInterface {
            public function handle(array $request): array
            {
                throw new \RuntimeException('boom');
            }
        };

        $router = new AjaxRouter(new NullLogger());
        $router->register('explode', $handler, 'nonce_explode');

        $payload = $this->capture(fn() => $router->dispatch('explode'));

        $this->assertSame('error', $payload['kind']);
        $this->assertStringContainsString('boom', $payload['data']['message']);
    }

    public function test_handler_receives_merged_get_post_request(): void
    {
        \AjaxRouterTestStubs::$nonceValid = true;
        \AjaxRouterTestStubs::$canUser    = true;
        $_GET  = ['a' => '1'];
        $_POST = ['b' => '2'];

        $handler = new class implements AjaxHandlerInterface {
            public array $seen = [];
            public function handle(array $request): array
            {
                $this->seen = $request;
                return ['success' => true, 'data' => []];
            }
        };

        $router = new AjaxRouter(new NullLogger());
        $router->register('peek', $handler, 'nonce_peek');

        $this->capture(fn() => $router->dispatch('peek'));

        $this->assertSame('1', $handler->seen['a']);
        $this->assertSame('2', $handler->seen['b']);
    }

    public function test_registered_actions_reports_every_registration(): void
    {
        $router = new AjaxRouter(new NullLogger());
        $router->register('a', $this->recordingHandler(), 'n_a');
        $router->register('b', $this->recordingHandler(), 'n_b');
        $router->register('c', $this->recordingHandler(), 'n_c');

        $this->assertSame(['a', 'b', 'c'], $router->getRegisteredActions());
    }

    private function recordingHandler(): AjaxHandlerInterface
    {
        return new class implements AjaxHandlerInterface {
            public function handle(array $request): array
            {
                return ['success' => true, 'data' => ['ok' => true]];
            }
        };
    }

    /**
     * @return array{kind:string, data:array}
     */
    private function capture(callable $fn): array
    {
        try {
            $fn();
        } catch (\AjaxRouterTestHalt $halt) {
            return ['kind' => $halt->kind, 'data' => $halt->data];
        }

        $this->fail('Router did not emit a terminal wp_send_json_* call.');
    }
}
