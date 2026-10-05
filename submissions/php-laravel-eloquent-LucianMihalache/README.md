# PHP + Laravel with Eloquent, on Octane and FrankenPHP

| | |
|---|---|
| Language | PHP 8.5.11 (the PHP inside FrankenPHP; thread-safe build) |
| Framework | Laravel 13.34.0 with Laravel Octane 2.20.0; Eloquent models and an API resource |
| Server | FrankenPHP 1.13.0 (Caddy 2.11.7) |
| SQLite driver | PDO SQLite, linked to SQLite 3.45.2 (bundled in FrankenPHP) |
| **Nginx or direct** | **Direct**: FrankenPHP serves `0.0.0.0:80` itself |

This is one of four PHP entries from the same author. All four run on the same server (FrankenPHP 1.13.0, serving
port 80 directly), the same PHP (8.5.11), the same `php.ini` (OPcache with the JIT), four PHP workers, and the same
code for the token check. They differ in one thing only: how much framework a request passes through. Sent together,
they show what that costs on the challenge's 1 vCPU.

| Entry | A request passes through | Processor time per request | Highest load passed | 95th / 99th percentile there | Processor idle there |
|---|---|---|---|---|---|
| **Laravel the usual way: Eloquent** (this one) | Octane, Laravel's kernel, middleware and router, validation, Eloquent models and an API resource | 4.33 ms | 2,000 users (2,500 fails) | 213 ms / 464 ms | 13% |
| [The same Laravel app with the `DB` facade](../php-laravel-db-LucianMihalache) | Octane, Laravel's kernel, middleware and router, validation, SQL through the `DB` facade | 2.72 ms | 3,500 users (4,000 fails) | 143 ms / 446 ms | 14% |
| [Laravel Octane, stripped down](../php-laravel-stripped-LucianMihalache) | Octane only: its own routes answer every request, then one controller and PDO | 1.43 ms | 5,500 users (not its limit) | 47 ms / 109 ms | 32% |
| [No framework: PHP on FrankenPHP](../php-frankenphp-LucianMihalache) | one PHP worker script and PDO | 0.63 ms | 5,500 users (not its limit) | 44 ms / 70 ms | 62% |

This entry passed the five-minute hold at 2,000 users (74,206 requests, none failed) and fails at 2,500 (95th percentile
1.65 s, still no failed request): the processor is full. 2,000 is its edge, with 13% of the processor idle: a
shorter run at the same load with a 30-second ramp-up instead of the script's 60 went over the latency limit (95th
percentile 3.6 s). Read at 1,500 users, a request costs 4.1 ms.

## What it is

A Laravel application written the way most are: routes in `routes/api.php` (in the `api` middleware group), a
controller, a middleware for the token, request validation, Eloquent models with their relations
(`Post::with('user')->withCount('likes')`), and an API resource that shapes a post. Laravel's default global
middleware is left in place. It runs on Laravel Octane, which keeps the application booted in long-lived workers.

## What was done, exactly

The usual steps of a Laravel deployment guide, and one trim of Octane's own list:

- **Octane instead of starting Laravel per request**, started with `php artisan octane:frankenphp` and Octane's own
  server configuration, untouched (its compression, static-file look-up and access-log filter included).
- **`php artisan optimize`** at start (configuration, routes and events cached) and Composer's optimized class map.
- **OPcache with the JIT** (`php.ini`), files never re-checked on disk.
- **Workers are recycled every 1,000 requests** (`--max-requests=1000`; Octane's default is 500).
- **Octane's per-request refreshers trimmed** (`config/octane.php`). Since this is just a RESTful API, we don't need
  all the application refreshers Octane runs before each request (sessions, cookies, logged-in users, views, mail,
  queues, broadcasts, caches, files, translations). We leave just the ones this API touches: the HTTP kernel and
  the router, the request, the database, and the validator: 8 of the stock 39.
- **The database connection stays open** in each worker for the worker's life (Octane's normal behaviour); SQLite is
  in WAL mode with `synchronous=NORMAL` and a 5-second busy timeout (`config/database.php`).

## What was deliberately left alone

- Eloquent builds a model object for every row: a feed is two queries (posts with their like counts, then the
  authors) and up to 40 model objects. Creating a post reads the row back to learn the `created_at` the database
  gave it.
- Every request goes through Laravel's kernel, default middleware and router.
- Octane's server configuration compresses an answer when the client asks for it. The challenge's load script never
  asks, so no answer is compressed in any of the four entries' runs.

## The token check (the same in all four entries)

`Jwt.php` verifies the bearer token by hand on every request: the header must say HS256, the signature is recomputed
with `hash_hmac` and compared in constant time, `exp` is checked, and `sub` and `username` are validated. Nothing
about a token is remembered between requests (rule 5).

## Running it

```bash
sudo bash install.sh   # FrankenPHP (official release binary) and Composer (official phar), pinned and checksummed
bash build.sh          # composer install from the lock file; Laravel's caches are written by `start.sh`, when the environment is known
SQLITE_PATH=... JWT_SECRET=... HOST=0.0.0.0 PORT=80 bash start.sh
```

`WORKERS` (default 4) sets the number of PHP workers. FrankenPHP is the only PHP on the box; `bin/php` is a two-line
wrapper so that Composer and `artisan` can be run with it (FrankenPHP's command-line mode hands a script its
arguments one place off from where the `php` command puts them).

## How these were measured, and what that is worth

- **The box**: a DigitalOcean Basic droplet, 1 vCPU and 2 GB, Ubuntu 24.04, in Frankfurt: the challenge's size.
- **The load**: the challenge's `bench/load.js`, unchanged. **Not the challenge's set-up, though**: k6 ran on a PC
  about 40 ms away over an office line, so every latency here carries about 40 ms of network, and a result here
  is not the score the challenge would give. The five-minute holds use the script's own shape (60-second ramp-up).
- **Processor time per request** is the droplet's busy processor share (user and kernel) divided by the requests a
  second it was answering, read during a hold at 2,000 users (about 200 requests a second). It is the fairest number
  in the table: it does not depend on the network.
- **Highest load passed** is the highest number of users that passed a five-minute hold with the challenge's limits
  (95th percentile under 500 ms, 99th under 1 s, under 1% errors), found with two-minute holds in steps of 500.
  No run of any entry had a failed request; the runs that fail, fail on latency with the processor fully busy.
- **Processor idle there** is the droplet's average idle share while that five-minute hold was at full load.
- **The load generator runs out before the two fast entries do.** Above roughly 5,500 to 6,000 users its own
  network starts losing idle connections (requests time out while the droplet still has processor to spare), so
  those two were tried at 5,000 and 5,500 users only and not pushed to their limit. Their line says so, and what
  the idle processor share suggests instead: that is arithmetic, not a measurement.

## License

MIT, under the repo's [license](../../LICENSE).
