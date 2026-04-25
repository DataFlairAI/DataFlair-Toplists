<?php
/**
 * Concrete brand-logo downloader.
 *
 * Phase 2 — extracted from `DataFlair_Toplists::download_brand_logo()` and
 * preserves all Phase 0A/0B invariants:
 *   - 3 MB size cap (Phase 0B H3)
 *   - 8 s timeout (Phase 0B H3)
 *   - HEAD request first to read Content-Length cheaply (Phase 0B H3)
 *   - `dataflair_brand_logo_stored` action fires on every successful store
 *     (Phase 0A — the hook Sigma's theme subscribes to)
 *   - Existing-file reuse within 7 days to skip redundant downloads
 *
 * Emits structured log events via the injected `LoggerInterface`.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Http;

use DataFlair\Toplists\Logging\LoggerFactory;
use DataFlair\Toplists\Logging\LoggerInterface;

final class LogoDownloader implements LogoDownloaderInterface
{
    /** 3 MB hard cap on logo bodies (Phase 0B H3). */
    private const LOGO_MAX_BYTES = 3 * 1024 * 1024;

    /** 8 s download timeout (Phase 0B H3). */
    private const LOGO_TIMEOUT = 8;

    /** 7-day reuse window for cached on-disk logos. */
    private const REUSE_WINDOW_SECONDS = 7 * 24 * 60 * 60;

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? LoggerFactory::get();
    }

    /**
     * @inheritDoc
     */
    public function download(array $brand_data, string $brand_slug)
    {
        $logo_url = $this->extractLogoUrl($brand_data);

        if ($logo_url === '' || !filter_var($logo_url, FILTER_VALIDATE_URL)) {
            $this->logger->info('logo.no_valid_url', [
                'brand' => $brand_data['name'] ?? 'unknown',
                'keys'  => array_keys($brand_data),
            ]);
            return false;
        }

        $upload_dir = DATAFLAIR_PLUGIN_DIR . 'assets/logos/';
        if (!file_exists($upload_dir)) {
            wp_mkdir_p($upload_dir);
        }

        $path_info = pathinfo((string) parse_url($logo_url, PHP_URL_PATH));
        $extension = !empty($path_info['extension']) ? (string) $path_info['extension'] : 'png';

        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        if (!in_array(strtolower($extension), $allowed_extensions, true)) {
            $extension = 'png';
        }

        $filename  = sanitize_file_name($brand_slug) . '.' . $extension;
        $file_path = $upload_dir . $filename;

        if (file_exists($file_path) && (time() - (int) filemtime($file_path)) < self::REUSE_WINDOW_SECONDS) {
            $file_url = DATAFLAIR_PLUGIN_URL . 'assets/logos/' . $filename;
            $this->logger->debug('logo.cache_hit', [
                'brand' => $brand_data['name'] ?? 'unknown',
                'url'   => $file_url,
            ]);
            $this->fireStoredHook($brand_data, $file_url, $logo_url);
            return $file_url;
        }

        $use_persistent = PersistentCurlTransport::isAvailable()
            && (bool) apply_filters('dataflair_use_persistent_curl', true);

        // HEAD first (Phase 0B H3) — bail before bandwidth burn.
        if ($use_persistent) {
            $head_res = PersistentCurlTransport::head(
                $logo_url,
                max(3, (int) ceil(self::LOGO_TIMEOUT / 2)),
                false
            );
            if ($head_res['ok'] && $head_res['content_length'] > 0
                && $head_res['content_length'] > self::LOGO_MAX_BYTES) {
                $this->logger->warning('logo.size_cap_head', [
                    'url'            => $logo_url,
                    'content_length' => $head_res['content_length'],
                    'cap'            => self::LOGO_MAX_BYTES,
                ]);
                return false;
            }
        } else {
            $head = wp_remote_head($logo_url, [
                'timeout'   => max(3, (int) ceil(self::LOGO_TIMEOUT / 2)),
                'sslverify' => false,
            ]);
            if (!is_wp_error($head)) {
                $content_length = (int) wp_remote_retrieve_header($head, 'content-length');
                if ($content_length > 0 && $content_length > self::LOGO_MAX_BYTES) {
                    $this->logger->warning('logo.size_cap_head', [
                        'url'            => $logo_url,
                        'content_length' => $content_length,
                        'cap'            => self::LOGO_MAX_BYTES,
                    ]);
                    return false;
                }
            }
        }

        if ($use_persistent) {
            $get_res = PersistentCurlTransport::get(
                $logo_url,
                [],
                self::LOGO_TIMEOUT,
                self::LOGO_MAX_BYTES,
                false
            );
            if (!$get_res['ok']) {
                if (($get_res['error'] ?? '') === 'response_too_large') {
                    $this->logger->warning('logo.size_cap_hit', [
                        'url' => $logo_url,
                        'cap' => self::LOGO_MAX_BYTES,
                    ]);
                } else {
                    $this->logger->warning('logo.download_failed', [
                        'url'   => $logo_url,
                        'error' => (string) ($get_res['error'] ?? 'unknown'),
                    ]);
                }
                return false;
            }
            $image_data    = $get_res['body'];
            $response_code = (int) $get_res['code'];
        } else {
            $response = wp_remote_get($logo_url, [
                'timeout'             => self::LOGO_TIMEOUT,
                'sslverify'           => false,
                'limit_response_size' => self::LOGO_MAX_BYTES,
            ]);

            if (is_wp_error($response)) {
                $this->logger->warning('logo.download_failed', [
                    'url'   => $logo_url,
                    'error' => $response->get_error_message(),
                ]);
                return false;
            }

            $image_data    = wp_remote_retrieve_body($response);
            $response_code = (int) wp_remote_retrieve_response_code($response);
        }

        if ($response_code !== 200 || $image_data === '') {
            $this->logger->warning('logo.download_non_200', [
                'url'    => $logo_url,
                'status' => $response_code,
            ]);
            return false;
        }

        if (strlen((string) $image_data) >= self::LOGO_MAX_BYTES) {
            $this->logger->warning('logo.size_cap_hit', [
                'url' => $logo_url,
                'cap' => self::LOGO_MAX_BYTES,
            ]);
            return false;
        }

        $saved = file_put_contents($file_path, $image_data);
        if ($saved === false) {
            $this->logger->error('logo.save_failed', ['path' => $file_path]);
            return false;
        }

        $file_url = DATAFLAIR_PLUGIN_URL . 'assets/logos/' . $filename;
        $this->logger->info('logo.stored', [
            'brand' => $brand_data['name'] ?? 'unknown',
            'url'   => $file_url,
        ]);
        $this->fireStoredHook($brand_data, $file_url, $logo_url);
        return $file_url;
    }

    /**
     * Extract a logo URL from upstream brand-data shapes.
     * Supports: logo / brandLogo / logoUrl / image / logoImage, each optionally
     * a nested {rectangular, square, url, src, path} object.
     */
    private function extractLogoUrl(array $brand_data): string
    {
        $logo_keys = ['logo', 'brandLogo', 'logoUrl', 'image', 'logoImage'];
        foreach ($logo_keys as $key) {
            if (empty($brand_data[$key])) {
                continue;
            }
            $val = $brand_data[$key];
            if (is_array($val)) {
                foreach (['rectangular', 'square', 'url', 'src', 'path'] as $sub) {
                    if (!empty($val[$sub])) {
                        return (string) $val[$sub];
                    }
                }
                continue;
            }
            return (string) $val;
        }
        return '';
    }

    private function fireStoredHook(array $brand_data, string $file_url, string $logo_url): void
    {
        if (!function_exists('do_action')) {
            return;
        }
        do_action(
            'dataflair_brand_logo_stored',
            isset($brand_data['id']) ? (int) $brand_data['id'] : 0,
            $file_url,
            $logo_url
        );
    }
}
