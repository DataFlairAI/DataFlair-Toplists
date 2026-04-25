<?php
/**
 * Phase 9.8 — Promo-code copy-to-clipboard footer script.
 *
 * Hooks `wp_footer` (priority 20) and prints the once-per-page click
 * handler that wires every `.promo-code-copy` button rendered by the
 * card template. The `data-promoBound` attribute prevents duplicate
 * listener attachment when the markup is re-rendered (e.g. by Alpine).
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Assets;

final class PromoCopyScript
{
    public function register(): void
    {
        add_action('wp_footer', [$this, 'output'], 20);
    }

    public function output(): void
    {
        ?>
        <script>
        (function() {
            function initPromoCopy() {
                document.querySelectorAll('.promo-code-copy').forEach(function(btn) {
                    if (btn.dataset.promoBound) return;
                    btn.dataset.promoBound = '1';
                    btn.addEventListener('click', function() {
                        var code = btn.getAttribute('data-code');
                        navigator.clipboard.writeText(code).then(function() {
                            btn.classList.add('copied');
                            btn.querySelector('.promo-code-value').textContent = 'Copied!';
                            setTimeout(function() {
                                btn.classList.remove('copied');
                                btn.querySelector('.promo-code-value').textContent = code;
                            }, 2000);
                        });
                    });
                });
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initPromoCopy);
            } else {
                initPromoCopy();
            }
        })();
        </script>
        <?php
    }
}
