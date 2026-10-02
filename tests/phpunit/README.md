# Tests

WordPress-integration PHPUnit tests. They run against a **real** Easy Digital Downloads, and every
ability is executed through `wp_get_ability()->execute()`, so schema validation, the permission
callback and output validation are all exercised the way an MCP client would hit them.

| File | Covers |
|---|---|
| `registry-test.php` | Registration, categories, annotations, permissions for every ability, third-party registration rules |
| `orders-test.php` | List/get/status/notes, full and partial refunds, permissions |
| `customers-test.php` | Search/get/create/update, EDD's customer capability filters |
| `discounts-test.php` | Create/list/status, code normalisation, timezone handling of dates |
| `products-test.php` | List/get, variable prices, stats gated by capability |
| `store-stats-test.php` | Totals, tax, refunds, custom date ranges |
| `licenses-test.php` | Software Licensing abilities (skipped if the add-on is not loaded) |
| `subscriptions-test.php` | Recurring Payments abilities (skipped if the add-on is not loaded) |
| `releases-test.php` | `edd/release-product-version`: fetch, changelog prepend, guard clauses, permissions (skipped unless Software Licensing and the Git Download Updater are both loaded) |
| `helpers-test.php` | Autoloader file naming, `Schema` helpers |

## Requirements

- A WordPress test library (`WP_TESTS_DIR`, containing `includes/functions.php`) and a scratch MySQL
  database it can drop tables in. **Never point it at a site's real database.**
- `yoast/phpunit-polyfills` (`WP_TESTS_PHPUNIT_POLYFILLS_PATH`) and PHPUnit 9.6.
- An EDD 3.x checkout. `EDD_DIR` points at it; it defaults to a sibling `../easy-digital-downloads`.
  The tests currently run against **EDD 3.7.1**; Git Download Updater 1.3.5 needs EDD 3.6.2+, so the older 3.0.x and 3.5.x checkouts are not supported.
- Optional: `EDD_SL_DIR` (Software Licensing, tested on 3.9.1), `EDD_RECURRING_DIR` (Recurring Payments,
  tested on 2.13.9) and `EDD_GIT_DIR` (Git Download Updater, tested on 1.3.5). Each defaults to a sibling
  folder. If one is missing its test class skips itself, and `registry-test.php` instead asserts that its
  abilities are **not** registered. The add-ons' tables are installed through EDD's component registry,
  so no extra setup is needed.
- `releases-test.php` never makes a real request to GitHub or Bitbucket - it fakes the Git Download
  Updater's own HTTP call with a `pre_http_request` filter, returning a small real zip built on the fly
  (see `mock_zipball()` in `framework/class-edd-abilities-addon-test-cases.php`).

## Running

```bash
export WP_TESTS_DIR=/path/to/wordpress-tests-lib
export EDD_DIR=/path/to/easy-digital-downloads
export WP_TESTS_PHPUNIT_POLYFILLS_PATH=/path/to/PHPUnit-Polyfills
phpunit
```

`phpunit.xml.dist` is at the repo root. To run one class: `phpunit --filter Orders_Test`.

## How it is put together

- `bootstrap.php` loads EDD, then this plugin, at `muplugins_loaded`, creates EDD's tables once, and
  initialises the Abilities API registries before the test lib snapshots hook state (otherwise
  `did_action()` reads 0 in later tests).
- `framework/class-edd-abilities-test-case.php` provides fixtures (`create_customer`, `create_product`,
  `create_order`) that write through EDD 3's own functions without going through checkout, plus
  `run_ability()` / `run_ok()`.
- Every test runs as a user holding all shop capabilities; permission tests swap to a narrower user.
