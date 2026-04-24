<?php
/**
 * Central REST router for the `/wp-json/dataflair/v1` namespace.
 *
 * Phase 6 — extracted from the god-class `register_rest_routes()` method.
 * Owns every `register_rest_route()` call and delegates the callbacks to
 * per-endpoint controllers. The god-class no longer touches `WP_REST_Request`
 * or `rest_ensure_response` directly.
 *
 * REST namespace `dataflair/v1` is a public contract and is preserved verbatim.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Rest;

use DataFlair\Toplists\Rest\Controllers\CasinosController;
use DataFlair\Toplists\Rest\Controllers\HealthController;
use DataFlair\Toplists\Rest\Controllers\ToplistsController;

final class RestRouter
{
    public const NAMESPACE = 'dataflair/v1';

    public function __construct(
        private ToplistsController $toplists,
        private CasinosController $casinos,
        private HealthController $health
    ) {}

    /**
     * Register every route with WordPress. Safe to call once on `rest_api_init`.
     */
    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/toplists', [
            'methods'             => 'GET',
            'callback'            => [$this->toplists, 'list'],
            'permission_callback' => [$this, 'canEditPosts'],
        ]);

        register_rest_route(self::NAMESPACE, '/toplists/(?P<id>\d+)/casinos', [
            'methods'             => 'GET',
            'callback'            => [$this->casinos, 'listForToplist'],
            'permission_callback' => [$this, 'canEditPosts'],
            'args'                => [
                'id' => [
                    'required' => true,
                    'type'     => 'integer',
                ],
                'page' => [
                    'required' => false,
                    'type'     => 'integer',
                    'default'  => 1,
                    'minimum'  => 1,
                ],
                'per_page' => [
                    'required' => false,
                    'type'     => 'integer',
                    'default'  => 20,
                    'minimum'  => 1,
                    'maximum'  => 100,
                ],
                'full' => [
                    'required' => false,
                    'type'     => 'integer',
                    'default'  => 0,
                    'enum'     => [0, 1],
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/health', [
            'methods'             => 'GET',
            'callback'            => [$this->health, 'status'],
            'permission_callback' => [$this, 'canManageOptions'],
        ]);
    }

    public function canEditPosts(): bool
    {
        return current_user_can('edit_posts');
    }

    public function canManageOptions(): bool
    {
        return current_user_can('manage_options');
    }
}
