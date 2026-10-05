# PHP + Laravel Octane, stripped down

| | |
|---|---|
| Language | PHP 8.5.11 (the PHP inside FrankenPHP; thread-safe build) |
| Framework | Laravel 13.34.0 with Laravel Octane 2.20.0, with most of Laravel taken off the request path |
| Server | FrankenPHP 1.13.0 (Caddy 2.11.7) |
| SQLite driver | PDO SQLite, linked to SQLite 3.45.2 (bundled in FrankenPHP) |
| **Nginx or direct** | **Direct**: FrankenPHP serves `0.0.0.0:80` itself |

This is one of four PHP entries from the same author. All four run on the same server (FrankenPHP 1.13.0, serving
port 80 directly), the same PHP (8.5.11), the same `php.ini` (OPcache with the JIT), four PHP workers, and the same
code for the token check. They differ in one thing only: how much framework a request passes through. Sent together,
they show what that costs on the challenge's 1 vCPU.

| Entry | A request passes through | Processor time per request | Highest load passed | 95th / 99th percentile there | Processor idle there |
|---|---|---|---|---|---|
| [Laravel the usual way: Eloquent](../php-laravel-eloquent-LucianMihalache) | Octane, Laravel's kernel, middleware and router, validation, Eloquent models and an API resource | 4.33 ms | 2,000 users (2,500 fails) | 213 ms / 464 ms | 13% |
| [The same Laravel app with the `DB` facade](../php-laravel-db-LucianMihalache) | Octane, Laravel's kernel, middleware and router, validation, SQL through the `DB` facade | 2.72 ms | 3,500 users (4,000 fails) | 143 ms / 446 ms | 14% |
| **Laravel Octane, stripped down** (this one) | Octane only: its own routes answer every request, then one controller and PDO | 1.43 ms | 5,500 users (not its limit) | 47 ms / 109 ms | 32% |
| [No framework: PHP on FrankenPHP](../php-frankenphp-LucianMihalache) | one PHP worker script and PDO | 0.63 ms | 5,500 users (not its limit) | 44 ms / 70 ms | 62% |

This entry passed the five-minute hold at 5,500 users (204,514 requests, none failed) with about a third of the
processor idle. **It was not pushed to its limit**: the load generator's network cannot drive more (see the end).
By idle processor share alone the limit would be roughly 7,000 users; that is arithmetic, not a measurement.

## What it is

**Read this as "how far Octane goes when Laravel gets out of the way", not as a normal Laravel application.**
Laravel is installed and booted, and Octane runs every request, but a request does not pass through Laravel's HTTP
kernel, its middleware, its router or its database layer. Octane has routes of its own that it answers directly
(`Octane::route`); `app/Support/DirectRoutes.php` is that route table extended with the two endpoints that carry a
post id, and all five endpoints are answered there by one controller. The ordinary Laravel routes are still in
`routes/api.php` and serve the application when it runs without Octane.

The [Eloquent](../php-laravel-eloquent-LucianMihalache) and [`DB` facade](../php-laravel-db-LucianMihalache) entries are the same API as a normal Laravel application.

## What was done, exactly

On top of what the two normal Laravel entries do (Octane, Laravel's caches, OPcache with the JIT):

- **All five endpoints on Octane's own routes.** For an endpoint with no middleware, getting through Laravel's
  kernel and router took several times longer than producing the answer.
- **Octane's per-request refreshers cut from 39 to 1** (`config/octane.php`): with no kernel, router, database
  layer or validator on the path, only the one that makes the current request the application's request is left.
- **No middleware at all** (`bootstrap/app.php`).
- **Workers are never recycled** (`--max-requests` far out of reach): nothing in this app grows between requests.
- **A lean server configuration** (`Caddyfile`, Octane's own with three things removed): the compression step
  (optional in the spec, and the load script never asks for a compressed answer), the look-up of a static file
  for every request, and the access log.
- **Laravel's caches are built in `build.sh`**: configuration, routes, events, and Composer's authoritative class
  map. The four values the benchmark sets are read from the environment when a worker starts, not from the cache.
- **Go's memory housekeeping** (`start.sh`): `GOGC=400` with `GOMEMLIMIT=700MiB`, so the web server collects less
  often but stays under a fixed ceiling.
- **JSON written directly** in the key order of the spec; only a post's body and author go through `json_encode`.

**The database part, as an extra**: Laravel's database layer is not used. Each worker opens SQLite once through PDO
and prepares its five statements once, so a query is one call to run it and one to read its rows. A prepared
statement keeps the query plan, not rows: every request still reads its data from the database (rule 5). Pragmas:
`journal_mode=WAL`, `synchronous=NORMAL` (rule 6), `busy_timeout=5000`, a 1 GiB `mmap_size`. A like is one
statement, `INSERT … SELECT … FROM posts WHERE id = ? ON CONFLICT DO NOTHING`; a second query runs only when
nothing was inserted, to tell "already liked" from "no such post". This is the smallest of the gains: run in a
tight loop on the droplet, the feed query costs about 53 microseconds this way and about 101 through `DB::select`;
one post, 7 and 39.

## The steps, one at a time

Measured in an earlier series at 3,000 users, so these figures are not on the same footing as the table at the top
(read at 2,000 users). Each line includes the ones above it; the last line is this entry.

| Setup | Processor time per request |
|---|---|
| Laravel booted for every request (what PHP-FPM does, OPcache warm) | about 5 ms |
| Octane with Laravel's router, PDO, three refreshers, Octane's own server configuration | 2.05 ms |
| + the lean server configuration | 1.85 ms |
| + the three fixed paths (`/health`, `/feed`, `POST /posts`) on Octane's own routes | 1.67 ms |
| + all five endpoints on Octane's own routes, one refresher | 1.32 ms |

## The token check (the same in all four entries)

`Jwt.php` verifies the bearer token by hand on every request: the header must say HS256, the signature is recomputed
with `hash_hmac` and compared in constant time, `exp` is checked, and `sub` and `username` are validated. Nothing
about a token is remembered between requests (rule 5).

## Running it

```bash
sudo bash install.sh   # FrankenPHP (official release binary) and Composer (official phar), pinned and checksummed
bash build.sh          # composer install from the lock file; then Laravel's caches
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
