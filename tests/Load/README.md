# Load / crash tests

Not part of the phpunit suite. `run.php` is plain PHP + ext-curl and **starts nothing**: it hits a base URL.
Never point it at production or at your dev database - it creates thousands of receipts and items.

## 1. Make a throw-away copy of the app

Copy the project (without `.git`/`node_modules`) and give it its own database, e.g. a copy of the demo SQLite file:

```powershell
robocopy D:\OSPanel\home\Ajwa $env:TEMP\ajwa-load /E /XD node_modules .git .claude /XF database.sqlite
copy D:\OSPanel\home\Ajwa\database\database.sqlite $env:TEMP\load.sqlite
```

Environment for every process of the copy (server, worker, cookie helper):

```
DB_CONNECTION=sqlite  DB_DATABASE=<temp>\load.sqlite       # or mysql + DB_HOST/DB_PORT/DB_DATABASE (see CLAUDE.md for MariaDB 10.11)
QUEUE_CONNECTION=database  SESSION_DRIVER=file  CACHE_DRIVER=file
APP_DEBUG=false  DEV_AUTO_LOGIN=false  LOG_LEVEL=warning
```

Notes:
- The repo has no `jobs` table migration; in the copy run `php artisan queue:table` then `php artisan migrate --force`.
- `/api/*` has a 60 requests/minute/IP limiter (`RouteServiceProvider`). Raise it in the **copy** (`Limit::perMinute(10000000)`)
  or every scenario will just measure 429s.
- On Windows `php -S` is single-process (`PHP_CLI_SERVER_WORKERS` is not supported). Start several servers on different
  ports and pass them all in `--base` (round-robin) to get some parallelism. Results are still distorted: they measure
  PHP-CLI-server + Windows, not nginx + php-fpm.

## 2. Run

```
php tests\Load\make-cookie.php <copy-root>            # prints "laravel_session=..." for the first admin (same env as the copy!)
php tests\Load\run.php --base=http://127.0.0.1:8081,http://127.0.0.1:8082 ^
    --pos-token=<POS_API_TOKEN of the copy> --cookie="<printed cookie>" ^
    --scenarios=receipts,items,pages,mixed --levels=5,10,25,50,100 --duration=15 --out=result.json
```

Scenarios: `receipts` (POS payloads, several pos/shop ids, ~4 % refunds, ~2 % cancelled without positions),
`items` (200-item batches, i.e. the API limit, overlapping ids), `pages` (`--pages=/,/ordering,/receipts` with the cookie),
`mixed` (65 % receipts, 27 % pages, 8 % items). Every step keeps N requests in flight for `--duration` seconds and prints
requests, RPS, p50/p95/p99 and the status-code histogram (`timeout` = client timeout, `connerr` = refused/reset).
Ramping stops at the first step with p95 > `--p95-limit` (3000 ms) or errors > `--error-limit` (1 %) and prints that
breaking point (`--no-stop` keeps going).

## 3. Queue drain

Receipts and item batches are only queued by the HTTP request. After the run, measure the drain time:

```
php artisan queue:work --stop-when-empty --timeout=120      # time it; check failed_jobs afterwards
```

## 4. Tear down

Stop every `php -S` / `mariadbd` process you started and delete the temp copy and database files.
