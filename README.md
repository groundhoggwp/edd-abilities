# Abilities for Easy Digital Downloads

Exposes [Easy Digital Downloads](https://easydigitaldownloads.com/) to AI agents through the
[WordPress Abilities API](https://developer.wordpress.org/news/2025/11/introducing-the-wordpress-abilities-api/),
so an assistant connected over [MCP](https://modelcontextprotocol.io) can look up orders, customers,
products and discounts, pull sales stats, refund an order, and more, with the same permissions the
connected WordPress user has in the admin.

It also covers the **Software Licensing** and **Recurring Payments** add-ons. Their abilities register
only when the add-on is active.

The plugin is standalone. It does not depend on Groundhogg or on any MCP plugin; it registers abilities
and any Abilities API consumer can use them.

## Requirements

- WordPress 6.9 or later (the Abilities API is in core from 6.9)
- PHP 7.4 or later
- Easy Digital Downloads 3.x (developed and tested against 3.5.3)
- Optional: EDD Software Licensing (tested on 3.9.1), EDD Recurring Payments (tested on 2.13.9)

## Installation

1. Copy this folder to `wp-content/plugins/edd-abilities`, or install a zip of it.
2. Activate **Abilities for Easy Digital Downloads**.
3. To let an AI client use the abilities, expose them over MCP (next section).

## Exposing the abilities over MCP

Abilities on their own are just PHP. To let Claude, Cursor, VS Code or any other MCP client call them,
use the official WordPress [MCP Adapter](https://github.com/WordPress/mcp-adapter), which turns registered
abilities into an MCP server.

### 1. Install the MCP Adapter

```bash
wp plugin install https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip --activate
```

(or download the zip from the [releases page](https://github.com/WordPress/mcp-adapter/releases/latest) and
install it like any plugin). It needs WordPress 6.9+ and PHP 7.4+.

### 2. Nothing to configure here

Every ability in this plugin is registered with `meta.public = true`, which is what the adapter's default
server looks for. There is no setting to switch on. Once both plugins are active the abilities are
available on the adapter's default server, `mcp-adapter-default-server`.

The default server does not list each ability as its own MCP tool. Clients reach them through three
tools the adapter provides:

| Tool | Use |
|---|---|
| `mcp-adapter-discover-abilities` | List the abilities the connected user can see, e.g. everything under `edd/` |
| `mcp-adapter-get-ability-info` | Get one ability's description and its input/output schema |
| `mcp-adapter-execute-ability` | Run an ability: `{ "ability_name": "edd/list-orders", "parameters": { ... } }` |

### 3. Connect a client

Pick a transport. Both authenticate as a WordPress user, and the ability's own capability check
(see the table below) is applied to that user.

**Local site, over STDIO with WP-CLI**

```json
{
  "mcpServers": {
    "my-store": {
      "command": "wp",
      "args": [
        "--path=/path/to/your/wordpress/site",
        "mcp-adapter",
        "serve",
        "--server=mcp-adapter-default-server",
        "--user=admin"
      ]
    }
  }
}
```

**Remote or local site, over HTTP with an Application Password**

Create an [Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/)
for the user (Users > Profile), then use the
[`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote) proxy:

```json
{
  "mcpServers": {
    "my-store": {
      "command": "npx",
      "args": [ "-y", "@automattic/mcp-wordpress-remote@latest" ],
      "env": {
        "WP_API_URL": "https://your-site.example/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "your-username",
        "WP_API_PASSWORD": "your-application-password"
      }
    }
  }
}
```

### 4. Try it

From a terminal, list the EDD abilities, then run one (STDIO, on a local site):

```bash
echo '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"mcp-adapter-discover-abilities","arguments":{}}}' \
  | wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server

echo '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"edd/get-store-stats","parameters":{"range":"last_30_days"}}}}' \
  | wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server
```

Or, once a client is connected, just ask: "How much did we earn last month, and what were the top three
products?" or "Refund $10 of order 1234, the second item only."

### Keeping it safe

- **The token can do what its user can do.** Connect a dedicated user with just the roles you're happy
  for an assistant to hold (for example Shop Manager) rather than an administrator.
- **Two abilities are marked destructive** so a well-behaved client asks before running them:
  `edd/refund-order` and `edd/cancel-subscription`. Neither can be undone from here.
- **Hide an ability entirely** by removing it from the registry:

  ```php
  add_filter( 'edd_abilities/abilities', function ( $classes ) {
      return array_diff( $classes, [ \EDD_Abilities\Abilities\Orders\Refund_Order::class ] );
  } );
  ```

- **Or keep it registered but out of MCP**, so other Abilities API consumers still see it:

  ```php
  add_filter( 'wp_register_ability_args', function ( $args, $name ) {
      if ( 'edd/refund-order' === $name ) {
          $args['meta']['mcp']['public'] = false;
      }
      return $args;
  }, 10, 2 );
  ```

## Available abilities

22 abilities: 16 that need only EDD, plus 3 each for Software Licensing and Recurring Payments.
"Requires" is the capability the connected user must hold. *Read* abilities change nothing.

### Orders

| Ability | Type | Requires | What it does |
|---|---|---|---|
| `edd/list-orders` | Read | `edit_shop_payments` | List or search orders. Filter by status, customer, email, product, gateway, date range; sort; page. Sales only by default; refund orders on request. |
| `edd/get-order` | Read | `edit_shop_payments` | One order in full: line items, billing address, applied discounts, notes, and whether it is still refundable. |
| `edd/update-order-status` | Write | `edit_shop_payments` | Change an order's status through EDD's own status handling, so anything hooked to a status change runs. Cannot be used to refund. |
| `edd/refund-order` | **Destructive** | `edit_shop_payments` | Full refund, or a **partial refund** of chosen items by quantity and amount (plus tax). A partial refund never refunds fees or credits. EDD's own validator rejects over-refunds. |
| `edd/add-order-note` | Write | `edit_shop_payments` | Add a private admin note to an order. |

### Customers

Customer abilities follow EDD's `edd_view_customers_role` / `edd_edit_customers_role` filters, so a store
that remaps who can see or edit customers is respected. By default that is `view_shop_reports` to read and
`edit_shop_payments` to change.

| Ability | Type | What it does |
|---|---|---|
| `edd/search-customers` | Read | Search customers by name or email; filter by status, user, date; sort; page. |
| `edd/get-customer` | Read | One customer by ID or any of their email addresses, with all emails and recent orders. |
| `edd/create-customer` | Write | Create a customer. Refuses a duplicate email. |
| `edd/update-customer` | Write | Change name, status or linked user, or attach an extra email. Refuses an email that belongs to someone else. |

### Products

| Ability | Type | Requires | What it does |
|---|---|---|---|
| `edd/list-products` | Read | `edit_products` | List or search products; published only by default. |
| `edd/get-product` | Read | `edit_products` | One product with its variable price options. Sales and earnings are only included for users who also hold `view_shop_reports`. |

### Discounts

| Ability | Type | Requires | What it does |
|---|---|---|---|
| `edd/list-discounts` | Read | `manage_shop_discounts` | List or search discount codes; archived hidden unless asked for. |
| `edd/get-discount` | Read | `manage_shop_discounts` | One discount by ID or code. |
| `edd/create-discount` | Write | `manage_shop_discounts` | Create a percent or flat code with limits, minimum spend, product requirements and exclusions, and start/end dates (site timezone, stored as UTC). |
| `edd/update-discount-status` | Write | `manage_shop_discounts` | Activate, deactivate or archive a code. |

### Reports

| Ability | Type | Requires | What it does |
|---|---|---|---|
| `edd/get-store-stats` | Read | `view_shop_reports` | Earnings, order count, average order value, refunds, tax, discount savings, new customers and top products for a named or custom date range. Computed by EDD's own `Stats` class so it matches Downloads > Reports. |

### Software Licensing *(only when EDD Software Licensing is active)*

| Ability | Type | Requires | What it does |
|---|---|---|---|
| `edd/list-licenses` | Read | `manage_licenses` | List licenses, filtered by key, status, product, customer or order, with free-text search. |
| `edd/get-license` | Read | `manage_licenses` | One license by ID or key, with expiration (or lifetime) and the sites it is activated on. |
| `edd/update-license-status` | Write | `manage_licenses` | Disable or re-enable a license. |

### Recurring Payments *(only when EDD Recurring Payments is active)*

| Ability | Type | Requires | What it does |
|---|---|---|---|
| `edd/list-subscriptions` | Read | `edit_shop_payments` | List or search subscriptions by status, product, customer or originating order. |
| `edd/get-subscription` | Read | `edit_shop_payments` | One subscription: billing period, amounts, times billed, gateway profile, expiry, and whether it can be cancelled. |
| `edd/cancel-subscription` | **Destructive** | `edit_shop_payments` | Cancel a subscription so it stops billing (and at the gateway where supported). Only offered where EDD Recurring says the gateway allows it. |

### Conventions

- **Lists** take `limit` (default 20, max 100) and `offset` and return `total_items` for the whole match, so
  a client can page. Where an ability has costly extras they are opt-in through `expand` / `include`.
- **Money** is a plain number in the order's currency. **Dates** come back as `{ utc, local, timezone }`.
- **Errors** are returned as WordPress errors with a stable code, e.g. `edd_abilities_not_found`,
  `refund_validation_error`, `ability_invalid_permissions`.

Not included yet: creating or editing products, licensing activations and renewals, and subscription
changes other than cancelling.

## Adding your own abilities

Other plugins can register abilities and categories next to these. WordPress only accepts registrations
while its `wp_abilities_api_init` / `wp_abilities_api_categories_init` actions run, so call these on
`plugins_loaded` or earlier (calling later is reported with `_doing_it_wrong()`):

```php
add_action( 'plugins_loaded', function () {
    \EDD_Abilities\Registry::add_category( 'my-addon', [
        'label'       => 'My Add-on',
        'description' => 'Abilities from my add-on.',
    ] );

    \EDD_Abilities\Registry::add_ability( \My_Addon\Do_The_Thing::class );
} );
```

A class extends `\EDD_Abilities\Abilities\Ability`: set the `NAME`, `CATEGORY`, `CAPABILITY`, `READONLY`,
`DESTRUCTIVE` and `IDEMPOTENT` constants, return the JSON schemas from `get_args()`, and do the work in
`__invoke( $input )`. Reuse the `Abilities\Schemas\*` classes (`get_schema()` / `transform()`) for EDD
objects. An ability can declare `public static function is_available(): bool` to register only when the
plugin it wraps is active.

## Development

- Classes autoload from `includes/` by name: `EDD_Abilities\Abilities\Orders\List_Orders` lives in
  `includes/abilities/orders/list-orders.php`.
- Tests are WordPress-integration PHPUnit tests that run every ability through the real Abilities API
  against a real EDD. See [`tests/phpunit/README.md`](tests/phpunit/README.md).

## License

GPL-3.0-or-later.
