# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state

This is a Laravel 10 + Livewire 3 + Tailwind CSS 4 admin panel for coordinating stock replenishment between a central warehouse and multiple shops. Product data is pushed in from an external ERP (1C) via API; shops push sales receipts from POS terminals; the app shows a daily sales dashboard and suggests (and manages) replenishment orders from the warehouse to shops. Two roles: **admin** (full access) and **operator** (scoped to one shop, read-only on other shops' stock). All authenticated screens are mobile-first (bottom tab bar on mobile, sidebar on desktop — see `resources/views/components/layouts/app.blade.php`).

**Deployment target constraint — do not casually bump versions**: production runs **PHP 8.1** and **MariaDB 10.11 (LTS)**. This project was previously downgraded Laravel 13→12/PHP 8.3→8.2, then downgraded *again* to Laravel 10/PHP 8.1 once the real production PHP version was confirmed (the 8.2 belief was wrong). The database target was also corrected: an earlier belief that production ran MySQL 5.6 was wrong, and a follow-up attempt to target MariaDB 10.8 was also wrong — 10.8 is a short-term release that has been EOL since May 2023. **MariaDB 10.11 LTS** (supported through Feb 2028) is the confirmed current target; verify against 1C/hosting docs before assuming any further version changes. Consequences of the PHP 8.1/Laravel 10 target to keep in mind when writing new code:
- **No Laravel 11+ APIs** — this is the big one, since Laravel 11 restructured huge parts of the framework:
  - App bootstrapping is the **classic Kernel-based structure**, not the `bootstrap/app.php` fluent config: `app/Http/Kernel.php` (global middleware, `$middlewareGroups`, `$middlewareAliases` — this is where `role`/`pos.token`/`local.only` are registered, not `bootstrap/app.php`), `app/Console/Kernel.php` (the `orders:generate-suggestions` schedule lives in its `schedule()` method, **not** `Schedule::command(...)` in `routes/console.php` — that facade doesn't exist pre-11), `app/Exceptions/Handler.php` (`shouldReturnJson()` override drives the `api/*` JSON-error behavior), `app/Providers/RouteServiceProvider.php` (registers `routes/web.php`/`routes/api.php` — routes are **not** auto-loaded by `bootstrap/app.php`). `config/app.php` has real `providers`/`aliases` arrays (`ServiceProvider::defaultProviders()->merge([...])`), not `bootstrap/providers.php`. `artisan` and `public/index.php` both go through `$kernel->handle(...)`, not `$app->handleCommand()`/`$app->handleRequest()`.
  - Eloquent models use the **classic `protected $casts = [...]` property**, not the `protected function casts(): array` method override (added in Laravel 11) — every model already follows this.
  - Jobs use the classic 4-trait combo `use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;` (`Illuminate\Bus\Queueable`), not the consolidated `Illuminate\Foundation\Queue\Queueable` (Laravel 11+ only, doesn't exist in 10).
  - `tests/TestCase.php` needs the `CreatesApplication` trait (`tests/CreatesApplication.php`) — Laravel 10's base test `TestCase` doesn't bootstrap the app on its own like 11+'s does.
  - `config/database.php`'s `'migrations'` key is a **plain string** `'migrations'`, not the `['table' => ..., 'update_date_on_publish' => ...]` array shape (Laravel 11+ only) — the array shape silently breaks `Schema::hasTable()` with an "Array to string conversion" error, not an obvious one.
  - No `Pdo\Mysql`/`Pdo\Sqlite` classes (PHP 8.4+ only) — `config/database.php` uses the classic `PDO::MYSQL_ATTR_SSL_CA` constant.
  - `config/view.php`, `config/cors.php`, `config/hashing.php` must exist and be populated (Laravel 11+ bakes their defaults into the framework internals and stopped requiring the published files; Laravel 10 still reads them directly). `config/broadcasting.php`/`config/sanctum.php` are intentionally NOT present — this app uses neither.
  - No built-in `/up` health check route (`bootstrap/app.php`'s `health:` param is 11+ only) — there's a manual `Route::get('/up', ...)` in `routes/web.php` instead.
- **Livewire is on `3.8.2`** (`"livewire/livewire": "3.8.2"` in composer.json), alongside `livewire/flux` (free tier, `^2.15`) for UI components. An earlier note here claimed Livewire 3.8.x/4.x call `Illuminate\View\ComponentAttributeBag::all()`/`extractPropNames()` and that neither exists in Laravel 10, and pinned to `3.4.12` as a result — **that claim didn't hold up**: `extractPropNames()` exists (protected) and `all()` never existed on that class at all in Laravel 10, but grepping the actual 3.8.2/flux 2.15.0 source found no calls to either method; their only `ComponentAttributeBag` interaction is `::macro('wire', ...)`/`::macro('pluck', ...)`, backed by the `Macroable` trait Laravel 10.50.2's `ComponentAttributeBag` already uses. Composer resolves 3.8.2 + flux cleanly against `laravel/framework:10.50.2`, and a real page render (`<flux:button>`/`<flux:input>` via `artisan serve`) returned clean HTML with no errors. Gotcha: **`flux:activate`** is only for the paid Flux Pro tier — do not run it for the free `livewire/flux` package, it prompts for a license email/key with no non-interactive escape hatch and will hang indefinitely under `--no-interaction`. Re-verify full-suite + a real page render before bumping Livewire again, same discipline as before — this note just corrects which version passed that bar.
- Do **not** use Laravel 13's `#[Fillable]`/`#[Hidden]` attribute syntax on models — it doesn't exist in Laravel 10 either. Use classic `protected $fillable = [...]` / `protected $hidden = [...]` properties (every model already follows this).
- `$table->json(...)` columns are now usable — MariaDB implements `JSON` as a `LONGTEXT` alias with an automatic `CHECK (JSON_VALID(...))` constraint, not MySQL's native binary JSON type, but it works fine via Eloquent's `'array'`/`'encrypted:array'` casts. (The earlier MySQL 5.6 target genuinely had no JSON type at all and required `$table->text(...)` instead — that restriction no longer applies under MariaDB.)
- `Schema::defaultStringLength(191)` is set in `AppServiceProvider::boot()`. It's **no longer strictly required** — MariaDB has defaulted to `ROW_FORMAT=DYNAMIC` (3072-byte index-prefix limit) since 10.2/10.3, well clear of a `utf8mb4` `string(255)` unique column's 1020 bytes. This was a hard requirement under the old MySQL 5.6 target (767-byte limit) but is now just a harmless defensive default under MariaDB 10.11 — keep it rather than spend time removing it.
- The `mysql` driver in `config/database.php` is correct for MariaDB — Laravel 10 has no separate `'mariadb'` driver key (that was added in Laravel 11+). No config changes needed beyond host/credentials.
- No MariaDB 10.11 instance exists in this dev environment (only MariaDB 11.7 and MySQL 8.4 are available locally under `D:\OSPanel\modules\`) — schema compatibility here is verified by reasoning about MariaDB 10.11's documented behavior, not by running against a live 10.11 instance. Local dev/tests run against SQLite.

Database is SQLite locally (`database/database.sqlite`); tests run against an in-memory SQLite database (see `phpunit.xml`). Production uses MariaDB 10.11 (LTS) via Laravel's `mysql` driver.

## Commands

Run all commands from the project root. PHP dependencies are managed via Composer, JS/CSS via npm + Vite.

**This is an OSPanel environment on Windows — `php` and `composer` are not on PATH.** Use the full interpreter path matching the **PHP 8.1** module (matches production; do not use the 8.2/8.3/8.4 modules also present under `D:\OSPanel\modules\` — they'd resolve dependencies incompatible with production):

```bash
D:\OSPanel\modules\PHP-8.1\PHP\php.exe artisan migrate
D:\OSPanel\modules\PHP-8.1\PHP\php.exe "D:\OSPanel\data\PHP-8.1\default\composer\composer.phar" install
```

### Local development

```bash
composer run dev
```
Runs the PHP dev server, queue listener, `pail` log tailer, and Vite dev server concurrently (via `concurrently`).

Individual pieces, if needed separately:
```bash
php artisan serve            # HTTP server
php artisan queue:listen --tries=1
php artisan pail             # tail application logs
npm run dev                  # Vite dev server (HMR)
```

### Build

```bash
npm run build                # production frontend assets via Vite
```
**Rebuild after adding/changing Blade views with new Tailwind utility classes** — Tailwind v4's Vite plugin only compiles utility classes it finds by scanning source files at build time. A stale build silently omits CSS for classes introduced since the last build (they render with no effect, not an error), which is easy to mistake for a Livewire/backend bug.

### Tests

```bash
composer run test            # clears config cache, then runs php artisan test
php artisan test                                 # run full suite directly
php artisan test --filter=ExampleTest            # run a single test class
php artisan test tests/Feature/ExampleTest.php   # run a single test file
```

Test suites are defined in `phpunit.xml`: `tests/Unit` and `tests/Feature`. Testing env forces `DB_DATABASE=:memory:`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`.

### Linting / formatting

```bash
vendor/bin/pint               # Laravel Pint (PHP code style), auto-fixes
vendor/bin/pint --test        # check only, no changes
```

### Database

```bash
php artisan migrate
php artisan migrate:fresh --seed
```
The seeder creates: 1 warehouse location (id matches `WAREHOUSE_LOCATION_ID`, default `1`), 1 admin (`admin@example.com` / `password`), 3 shops each with an operator user and 2 POS terminals, and 10 sample products with stock at each shop. Seeded POS API tokens are printed to console output at seed time (each POS's plaintext token is only ever shown once — `Pos::issueApiToken()` stores only the SHA-256 hash).

`WAREHOUSE_LOCATION_ID` (`.env`, defaults to `1` locally and in `phpunit.xml`) must be set to the location id that represents the central warehouse — see the domain-model note on locations below. `Location::factory()->warehouse()` and `OrderFactory`'s default `source_location_id` both key off this same config value, reusing the existing warehouse row instead of creating a second one.

## Architecture

### Domain model

**Products, groups, and locations use 1C's own numeric id as their primary key** (`$incrementing = false`, `$table->unsignedBigInteger('id')->primary()`) — there is no separate `one_c_id` mapping column on any of them. This means creating one of these locally (e.g. via the admin UI) requires supplying the id 1C will use for that same record, not letting the DB auto-generate one. `ProductPrice`, `ProductBarcode`, `Stock`, `ReorderRule`, `ApiIngestionLog`, etc. still use normal auto-incrementing ids — only the three entities 1C itself owns/identifies work this way.

- **`locations`** is a single table for both shops and the central warehouse. **There is no `type` column** — the warehouse is simply the location whose id equals `config('inventory.warehouse_location_id')` (env `WAREHOUSE_LOCATION_ID`; local dev/tests use `1`). `Location::scopeShops()` (`Location::query()->shops()`) and `$location->isWarehouse()` are the two helpers everything else uses instead of a type filter — grep for `->shops()` before reintroducing type-like filtering. No `code` column either; the id itself (1C's shop id) is the only stable identifier, shown in the UI as `#{{ $location->id }}`.
- **`pos`** (point-of-sale terminals) belongs to a `location`. Receipts reference both `location_id` (denormalized for fast shop-level queries) and `pos_id` (the actual till that rang up the sale). Each POS has its own bearer token (`api_token_hash`) for the receipt-ingestion API — not the location, since a shop can have multiple registers.
- **`stocks`** is a fast-read current-quantity cache per `(location_id, product_id)` — the only source of truth for on-hand quantity; there is no movement ledger (`stock_movements` was removed — sales, order fulfillment, and manual adjustments all just write `stocks.quantity` directly, with no audit trail beyond `receipts`/`receipt_items` for sales and the `orders`/`order_items` rows themselves for transfers).
- **`orders`** move stock from the warehouse to a shop. Status lifecycle (`App\Enums\OrderStatus`): `pending_approval` → `approved`/`rejected` → `fulfilled`, or `cancelled`. Operators can draft an order for their own shop only (`OrderPolicy::create`); it needs admin approval. Admins can create and approve for any shop directly. The warehouse source id comes from `config('inventory.warehouse_location_id')`, not a location type lookup.
- **`reorder_rules`** hold min/max thresholds per shop+product — the fallback basis for the suggestion engine when there isn't enough sales history for velocity-based forecasting. **1C is the system of record for these and for `stocks` quantities**, pushed via `POST /api/local/items` (see below) and applied by `ProcessLocalItemsBatch`, which overwrites `stocks.quantity` and `reorder_rules` on every sync. Receipt/order-driven adjustments are only a real-time approximation *between* syncs. `reorder_rules.created_by` is nullable and unused going forward (null = synced from 1C) — the `/admin/reorder-rules` screen is **read-only**, there is no manual create/edit/delete path (`ReorderRulePolicy::create/update/delete` all return `false`).
- **`product_prices`** holds per-shop prices: `(location_id, product_id, price_id, price_name, value)`, unique on `(location_id, product_id, price_id)`. 1C's `price[]` entries aren't shop-scoped in the payload, but prices are still tracked per shop — `ProcessLocalItemsBatch::syncPrices()` writes one row per known location for every price entry it receives (so a global 1C price fans out to every shop's row). `price_id` is 1C's raw price-type id (e.g. cost vs. selling) with no local lookup table backing it — just stored as-is alongside `price_name` for display. **`products` itself has no `price` column** — there's no single global price anymore, only per-shop `product_prices` rows.
- **`product_barcodes`** holds a product's barcodes (a product can have several); 1C sends the full current set per product on every sync and `ProcessLocalItemsBatch` reconciles it (deletes barcodes no longer present, upserts the rest). `barcode` is globally unique — reassigning a barcode to a different product is accepted as-is (1C is authoritative).
- **`groups`** are product categories/groups sourced from 1C (`id` + `name`), referenced by `products.group_id` (nullable). Replaced the earlier free-text `products.category` column entirely — 1C's `group` object is the only source, upserted by `ProcessLocalItemsBatch` alongside the product itself.
- **`api_ingestion_logs`** records every inbound 1C/receipt payload (source, status, raw payload) for audit/replay, independent of whether processing succeeded. For the local-items sync, non-fatal issues (e.g. a shop id in the payload that doesn't match any known location) don't fail the batch — they're skipped and appended to `error_message` while the rest of the batch still processes and the log is marked `processed`.

### Auth & authorization

Hand-rolled Livewire login (`app/Livewire/Auth/Login.php`) against the standard `web` session guard — deliberately not Laravel Breeze, since Breeze's Livewire stack historically targets Livewire 3 and risks conflicting with the pinned `livewire/livewire ^4.3`. Role is a `role` enum column on `users` (`App\Enums\UserRole`) plus a nullable `location_id` FK (null for admins). Authorization is Policy-based (`app/Policies/*`) — admin bypasses everything; operator mutation checks `$model->location_id === $user->location_id`. The `role:admin` middleware alias (`App\Http\Middleware\EnsureUserHasRole`, registered in `bootstrap/app.php`) gates `/admin/*` routes as defense-in-depth on top of policy checks.

### Inbound integration APIs (`routes/api.php`)

- `POST /api/local/items` — the 1C product/stock/price/reorder-rule sync channel. Behind `local.only` middleware (`App\Http\Middleware\EnsureRequestIsLocal`) — no token, just an IP check restricting requests to loopback + private LAN ranges (`127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`), since 1C reaches this from the same local network. The request body is a **bare JSON array** (not `{"items": [...]}`) of up to 500 items shaped like `.claude/sample/request.json`: `{id, group:{id,name}, mark, name, barcode[], qty[{shop:{id,name}, value}], price[{price:{id,name}, value}], order[{shop:{id,name}, min, max}], class_code, package_code}`. `LocalItemsRequest::validationData()` wraps the root array under a synthetic `items` key purely so the rest of validation can use normal `items.*.foo` dot-notation. Field mapping: `id` → `products.id` directly (1C's id *is* the primary key, no separate mapping column), `mark` → `products.sku`, `group.id`/`group.name` → upserted into `groups` (`id` = `group.id` directly) and linked via `products.group_id`, `qty[].shop.id`/`order[].shop.id` → matched directly against `locations.id`, `price[]` → every entry is written to `product_prices` for every known shop (see domain-model note above), not just a single `'Sotish narxi'` value on the product. `class_code` (an IKPU-style tax classification code) and `package_code` are stored as-is for future fiscal/receipt use, not currently surfaced in the UI. **`ProcessLocalItemsBatch` bulk-upserts** (one `Model::upsert()` call per table — groups, products, barcodes, prices, stocks, reorder_rules — rather than per-row queries) since a 500-item batch would otherwise be thousands of individual queries; the only lookup query left is an existence check for shop ids against `locations.id` (products/groups need no lookup at all since their id already *is* the value from the payload).
- `POST /api/shops/receipts` — behind `pos.token` middleware, which resolves the specific `Pos` row from its hashed bearer token and binds it onto the request (`$request->attributes->get('pos')`) — the token itself identifies which shop/register is posting, no location id needed in the payload.

Both controllers are thin: validate via a `FormRequest`, log the raw payload to `api_ingestion_logs`, dispatch a queued Job (`App\Jobs\ProcessLocalItemsBatch` / `ProcessReceiptIngestion`), return `202`. The actual upsert/stock-decrement logic lives in the jobs, run on the `database` queue connection (already configured, no Redis in this stack).

### MCP server (`POST /api/mcp`)

A hand-written, **read-only** Model Context Protocol server (JSON-RPC 2.0 over HTTP — no `laravel/mcp`, which would pull Laravel 12 components into this Laravel 10 app). `App\Http\Controllers\Api\McpController` handles `initialize`, `ping`, `tools/list`, `tools/call`. Tools are classes implementing `App\Mcp\Tool` (extend `App\Mcp\BaseTool` for date/int helpers) listed in `config/mcp.php` — **to add a tool, create the class and add it to that list**; keep tools read-only. Auth is `mcp.token` middleware (`EnsureMcpTokenIsValid`): a bearer token generated in the admin panel (`/settings/mcp`, stored only as a SHA-256 hash in the `settings` table via `App\Mcp\McpSettings`) or env `MCP_API_TOKEN`; the server and each tool can also be switched off there. No token = endpoint closed.

### Mobile-first UI & design system

Single Blade layout (`components/layouts/app.blade.php`) drives both breakpoints — no separate mobile/desktop templates. Bottom tab bar + top bar are `md:hidden`; the sidebar is `hidden md:flex`. Data tables follow the same pattern per-page: a card-list `md:hidden` block and a `<table>` `hidden md:block` block in the same Blade view (see `resources/views/livewire/products/index.blade.php` for the reference pattern).

Shared Blade UI components live in `resources/views/components/ui/` (`button`, `badge`, `card`, `page-header`, `empty-state`, `stat`, `input`, `select`, `textarea`, `label`, `error`, `logo-mark`) — reuse these rather than hand-rolling utility classes. Icons are inline SVGs in `resources/views/components/icon.blade.php` (switch on a `name` prop).

**Brand palette**: accent color is `brand-*` (a custom Tailwind color scale defined in `resources/css/app.css`'s `@theme` block), sampled from the actual Ajwa logo (`.claude/logo/iajwa.webp` — an olive/forest green, `brand-700 ≈ #5a7535`). Neutral palette is `slate`. The sidebar/login logo badge is `<x-ui.logo-mark>`, a text-based "A" monogram (not the raster logo image — its intricate wreath icon doesn't hold up at UI sizes below ~40px). `APP_NAME=Ajwa`. Note: the logo's wordmark actually reads "AJVA" (Uzbek transliteration); "Ajwa" was kept for consistency with the project directory name — flag this to the user if brand spelling matters.

### Nav structure

Common nav (all authenticated users): Dashboard, Orders. Admin-only nav (`role:admin`), in this exact order: **Locations, POS, Products, Receipts, Users, Reorder Rules**. `Stock` was deliberately dropped from the nav (it's largely superseded now that 1C is authoritative for stock levels) but the route/page still exists and works (`/stock`) — it's just not linked from the sidebar/bottom-nav/menu anymore, reachable only by direct URL.

### Localization (Uzbek)

`APP_LOCALE=uz`, `APP_FALLBACK_LOCALE=en`. All UI-facing strings in Blade views/Livewire components are wrapped in `__('...')`; translations live in `lang/uz.json` (string-keyed — the key is the literal English source string, not a short code, so `__('New order')` looks up `"New order"` in that file directly). Validation messages and pagination text are translated in `lang/uz/validation.php` and `lang/uz/pagination.php` (only the rules actually used in this app are filled in — anything missing gracefully falls back to Laravel's built-in English defaults via `APP_FALLBACK_LOCALE`, it does not show raw translation keys). Enum `label()` methods (`UserRole`, `OrderStatus`) also route through `__()`.

When adding new UI text: wrap it in `__('Exact English Sentence')` and add the matching entry to `lang/uz.json`. When adding a new validation rule that isn't already in `lang/uz/validation.php`, either add it there or accept the English fallback. Custom field names for validation (`:attribute` placeholders) are centralized in `lang/uz/validation.php`'s `attributes` array — prefer adding there over per-component `validationAttributes()` overrides, since the global mapping covers every form automatically (see `App\Livewire\Admin\Users\Index::validationAttributes()` for the one exception, needed because it hardcoded a raw string instead of relying on the global map).

### Build status

Foundation, auth/layout, ingestion APIs (including 1C-authoritative stock/reorder-rule sync), admin CRUD (Locations, POS with token issuance, Users, Products, read-only Reorder Rules, read-only Receipts), Stock views, order workflow (draft/approve/reject/fulfill/cancel), the order-suggestion engine, the daily-sales dashboard, and full Uzbek localization are all built, tested (`tests/` — run `php artisan test`), and manually verified end-to-end in-browser. UI has a cohesive brand-colored design system (see above).
