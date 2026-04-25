# Upgrading DataFlair Toplists

This document covers breaking-change windows and migration steps between
major and minor versions. For day-to-day changelog entries see
`plugins_api_info` in `dataflair-toplists.php` or the release notes on
GitHub.

---

## v1.15.x → v2.0.0

### What changed

`DataFlair\Toplists\Plugin::boot()` is now the canonical entry point for
the plugin. The plugin file calls `Plugin::boot()` directly at load time;
that method is idempotent and internally constructs the legacy
`DataFlair_Toplists` singleton so every existing hook registration, admin
page, shortcode, block, and REST route keeps working byte-for-byte.

`DataFlair_Toplists::get_instance()` is **deprecated but functional** on
the entire v2.0.x line. It is scheduled for removal in **v2.1.0**, at
which point calling it will throw `BadMethodCallException`.

A hand-written lazy service container ships as
`DataFlair\Toplists\Container`. The canonical entry exposes it via
`Plugin::boot()->container()`. Today the container wires one service —
`logger` — resolving to the `LoggerInterface` the Phase 1 factory
returns. More services join during the v2.0.x line as one-off `new Foo()`
call sites migrate.

### What downstream consumers need to do

If you are **not** calling `DataFlair_Toplists::get_instance()` from your
theme, child plugin, or mu-plugin, **no action is required**.

If you **are** calling `DataFlair_Toplists::get_instance()`, you have the
entire v2.0.x line to migrate. The recommended pattern:

```php
// Legacy (v1.x → v2.0.x, works but deprecated)
$legacy = DataFlair_Toplists::get_instance();

// Canonical (v2.0.0+)
$plugin    = \DataFlair\Toplists\Plugin::boot();
$container = $plugin->container();
$logger    = $container->get('logger');
```

### Turning on strict deprecation notices

By default `DataFlair_Toplists::get_instance()` does **not** emit
`E_USER_DEPRECATED`. This is intentional — the god-class continues to
own WordPress hook registrations internally, and firing a deprecation
notice on every hook dispatch would drown `error_log`.

To opt in to strict notices (useful when auditing your own downstream
code for leftover references):

```php
add_filter('dataflair_strict_deprecation', '__return_true');
```

With this filter enabled, every call to
`DataFlair_Toplists::get_instance()` emits a deprecation notice pointing
at `\DataFlair\Toplists\Plugin::boot()`.

### Overriding container services

The container exposes `register()`, `set()`, `get()`, and `has()`. The
most common override is swapping the logger:

```php
add_action('plugins_loaded', static function () {
    $plugin = \DataFlair\Toplists\Plugin::instance();
    if ($plugin === null) {
        return;
    }
    $plugin->container()->set('logger', new MySentryLogger());
}, 11); // run after the plugin file has loaded
```

`Plugin::instance()` returns `null` before `Plugin::boot()` has run, so
either check for `null` or attach your override inside
`plugins_loaded` / `init` so the boot has already happened.

### Why a major version bump

Three reasons:

1. A new canonical public API (`Plugin::boot()`) becomes the supported
   contract going forward.
2. The formal deprecation window opens on `DataFlair_Toplists` — a class
   that has been the plugin's entry point since v1.0.
3. Downstream integrators should plan their migration within the v2.0.x
   line so the shim drop in v2.1.0 doesn't surprise them.

No runtime behaviour changed for end users (editors, site admins,
visitors). Every hook, option, table, shortcode, block, REST route, and
AJAX action is preserved.

---

## Planned: v2.0.x → v2.1.0 (shim drop)

The `DataFlair_Toplists` god-class is scheduled for full removal in
v2.1.0. After the drop:

- `DataFlair_Toplists::get_instance()` throws `BadMethodCallException`
  with a pointer to this guide.
- All implementation in `dataflair-toplists.php` moves to the `src/`
  namespace tree.
- The plugin file shrinks to a thin bootstrap that calls
  `\DataFlair\Toplists\Plugin::boot()` and does nothing else.

Plan your migration now. The v2.0.x line is explicitly a migration
window — nothing else about it will change.
