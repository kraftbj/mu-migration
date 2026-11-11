# TODO — follow-up items

Deferred work discovered while making the tool run on PHP 8.4/8.5 without
deprecation warnings. None of these block that goal; they are cleanups and
gaps worth addressing separately.

## Code cleanups (not PHP-version issues)

- [ ] **Undefined method call.** `includes/commands/class-mu-migration-base.php:49`
  calls `self::error(...)`, but `MUMigrationBase` only defines
  `line/log/success/warning` (and `WP_CLI_Command` has no `error()`). If the
  invalid-callback branch in `all_posts()` is ever reached this is a fatal
  `Error`. Change it to `\WP_CLI::error(...)`.

- [ ] **Undefined-array-key warnings under PHP 8** (these are warnings, not
  deprecations, so out of scope for this PR, but worth fixing):
  - `includes/helpers.php` `parse_url_for_search_replace()` reads
    `$parsed_url['host']` without checking it exists.
  - `includes/commands/class-mu-migration-export.php` `tables()` uses `$url`
    which is only assigned inside `if ( isset( $this->assoc_args['blog_id'] ) )`.

## Version floor / platform requirements

- [ ] **Raise the PHP floor.** `mu-migration.php` still guards on
  `version_compare( PHP_VERSION, '7.1.0' ) < 0`. Decide the real minimum
  (>= 8.1 recommended so `wp_insert_site()` / modern WP is assumed) and add a
  matching `"php"` constraint to `composer.json` `require`. Left out here so the
  bump can ship with a conscious testing decision.

## Testing (no coverage today)

- [ ] **There are no unit tests.** The repo ships only a **behat 2.5**
  functional suite (`features/*.feature` + `features/bootstrap`). behat `~2.5`
  depends on Symfony ~2 (EOL) and does **not** run on modern PHP, so the
  existing suite cannot verify this migration. The changes in this PR were
  verified by `php -l` and `composer validate` only — **not** exercised against
  a live WordPress multisite.

- [ ] **Add PHPUnit unit tests**, prioritising the code changed here:
  - `Helpers\zip()` / `Helpers\extract()` (the new `ZipArchive`
    implementation): round-trip a directory tree and loose files through
    `zip()` → `extract()` and assert the extracted layout matches what
    `ImportCommand::all()` expects from `glob()` — i.e. `*.json`/`*.csv`/`*.sql`
    at the archive root and `wp-content/{plugins,themes,uploads}` preserved.
  - CSV export → import round-trip, confirming the explicit `\` escape argument
    keeps serialized user-meta values byte-identical across the trip.
  - `ImportCommand::create_new_site()` on multisite (now `wp_insert_site()`):
    new-site creation, the `domain_exists()` short-circuit, and the
    `WP_Error` path.

- [ ] **Replace or upgrade the behat harness.** Either move to a
  modern behat release or port the feature coverage to the WP-CLI package
  testing framework so functional tests can run on supported PHP.

- [ ] **Manual end-to-end verification** of `export all` and `import all`
  (zip round-trip + site creation) on a real multisite before relying on the
  tool in production.
