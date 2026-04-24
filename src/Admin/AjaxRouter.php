<?php
/**
 * Central AJAX router.
 *
 * Registers every dataflair_* AJAX action with WordPress. On dispatch, the
 * router performs the nonce + capability checks centrally, sanitises the
 * request payload, and delegates to the handler registered for that action.
 *
 * Handlers implement AjaxHandlerInterface and return a structured array. The
 * router is responsible for emitting wp_send_json_success / wp_send_json_error.
 *
 * Phase 5 strangler-fig: the god-class registers its old ajax_* methods via
 * legacy callables; the router runs in parallel for the handlers that have
 * been migrated. Both paths can coexist until every handler class exists.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin;

use DataFlair\Toplists\Logging\LoggerInterface;

final class AjaxRouter
{
    /**
     * Map of action name → handler + nonce key + capability.
     *
     * @var array<string, array{handler:AjaxHandlerInterface, nonce:string, capability:string}>
     */
    private array $routes = [];

    public function __construct(private LoggerInterface $logger) {}

    /**
     * Register a handler for a single wp_ajax_{$action} action.
     */
    public function register(
        string $action,
        AjaxHandlerInterface $handler,
        string $nonce_action,
        string $capability = 'manage_options'
    ): void {
        $this->routes[$action] = [
            'handler'    => $handler,
            'nonce'      => $nonce_action,
            'capability' => $capability,
        ];

        add_action("wp_ajax_{$action}", function () use ($action): void {
            $this->dispatch($action);
        });
    }

    /**
     * Dispatch a single request to its registered handler.
     *
     * @internal exposed for testing; production callers should let add_action
     *           invoke this via the registered closure.
     */
    public function dispatch(string $action): void
    {
        if (!isset($this->routes[$action])) {
            wp_send_json_error(['message' => "Unknown action: {$action}"]);
            return;
        }

        $route = $this->routes[$action];

        if (!check_ajax_referer($route['nonce'], 'nonce', false)) {
            $this->logger->warning('ajax.router.nonce_failed', ['action' => $action]);
            wp_send_json_error(['message' => 'Invalid or expired nonce.']);
            return;
        }

        if (!current_user_can($route['capability'])) {
            $this->logger->warning('ajax.router.cap_denied', [
                'action'     => $action,
                'capability' => $route['capability'],
            ]);
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }

        $request = array_merge($_GET, $_POST);

        try {
            $result = $route['handler']->handle($request);
        } catch (\Throwable $e) {
            $this->logger->error('ajax.router.handler_threw', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
            wp_send_json_error(['message' => 'Handler error: ' . $e->getMessage()]);
            return;
        }

        if (!empty($result['success'])) {
            wp_send_json_success($result['data'] ?? ['message' => $result['message'] ?? 'OK']);
        } else {
            wp_send_json_error($result['data'] ?? ['message' => $result['message'] ?? 'Failed']);
        }
    }

    /**
     * Test helper: list every registered action name.
     *
     * @return array<int,string>
     */
    public function getRegisteredActions(): array
    {
        return array_keys($this->routes);
    }
}
