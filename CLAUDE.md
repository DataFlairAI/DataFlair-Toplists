# DataFlair Toplists — Claude Instructions

## Release Checklist (MANDATORY before every release)

Before tagging a new version and pushing, ALWAYS:

1. **Bump the version** in TWO places in `dataflair-toplists.php`:
   - `* Version: X.Y.Z` in the plugin header comment
   - `define('DATAFLAIR_VERSION', 'X.Y.Z');` constant

2. **Update the plugin description** in the `plugins_api` filter block inside `dataflair-toplists.php` (search for `dataflair_plugins_api_info`). Keep the full description accurate and up to date with all current features.

3. **Update the changelog** in the same `plugins_api` filter block. Add a new version block at the TOP of the changelog with all changes from this release. Format:
   ```
   <h4>X.Y.Z</h4>
   <ul>
     <li>Added: ...</li>
     <li>Fixed: ...</li>
   </ul>
   ```

4. **Update README.md** — keep the Features section and Changelog section in sync with the plugin description and `plugins_api` block.

5. **Run tests** — `./vendor/bin/phpunit` must be green before tagging.

6. **Commit, tag, push, release**:
   ```bash
   git add -A
   git commit -m "chore: bump version to X.Y.Z"
   git tag vX.Y.Z
   git push origin main
   git push origin vX.Y.Z
   gh release create vX.Y.Z --title "vX.Y.Z — <summary>" --notes "..."
   ```

7. **Rsync to strike-odds.test**:
   ```bash
   rsync -av --exclude='.git' --exclude='.claude' --exclude='node_modules' \
     /Users/mexpower/Sites/DataFlair-Toplists/ \
     /Users/mexpower/Sites/strike-odds/wp-content/plugins/DataFlair-Toplists/
   rsync -av --exclude='.git' --exclude='.claude' --exclude='node_modules' \
     /Users/mexpower/Sites/DataFlair-Toplists/ \
     /Users/mexpower/Sites/strike-odds/wp-content/plugins/DataFlair-Toplists-1.8.1/
   ```

---

## Plugin Overview

**Plugin slug:** `dataflair-toplists`
**Main file:** `dataflair-toplists.php`
**Active plugin folder on strike-odds:** `DataFlair-Toplists-1.8.1/` (confirmed via ReflectionMethod)

**Key files:**
- `dataflair-toplists.php` — main plugin class, all hooks, admin pages, REST API, sync logic
- `includes/render-casino-card.php` — casino card template, included via `ob_start()` in `render_casino_card()`
- `includes/ProductTypeLabels.php` — label map for casino/sportsbook/poker product types
- `build/index.js` — compiled Gutenberg block JS
- `tests/phpunit/` — PHPUnit test suite (82 tests)
- `docs/plans/` — parked feature plans for future branches

**Custom DB tables:**
- `wp_dataflair_toplists`
- `wp_dataflair_brands`
- `wp_dataflair_alternative_toplists`

**Important behaviours:**
- `render_casino_card()` requires `global $wpdb` at the top — do not remove it
- Review URL resolution priority: `review_url_override` (brands table) > published post permalink > `/reviews/{slug}/` > affiliate CTA
- `bonus_code` is extracted from `$offer['bonus_code']` in `render-casino-card.php` — skip if empty or 'N/A'
- Promo copy JS is enqueued once per page via `wp_footer` using `data-promoBound` to avoid duplicate listeners
- Auto-updates use `enableReleaseAssets()` — do NOT switch to `setBranch()`
- vendor/ is committed to the repo (client sites don't run composer install)

---

## Parked Plans

- `docs/plans/TOPLIST_PAGE_MAP.md` — toplist-to-page mapping feature, to be built on a separate branch
