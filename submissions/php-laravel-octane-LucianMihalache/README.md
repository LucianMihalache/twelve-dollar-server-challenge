# PHP + Laravel Octane (FrankenPHP)

| | |
|---|---|
| Language | PHP 8.5.11 (the PHP inside FrankenPHP; thread-safe build) |
| Framework | Laravel 13.34.0 with Laravel Octane 2.20.0 |
| Server | FrankenPHP 1.13.0 (Caddy 2.11.7), started by `php artisan octane:frankenphp` |
| SQLite driver | PDO SQLite, linked to SQLite 3.45.2 (bundled in FrankenPHP) |
| **Nginx or direct** | **Direct**: FrankenPHP serves `0.0.0.0:80` itself |

Every PHP package, transitive ones included, is pinned in `composer.lock`. FrankenPHP and Composer are pinned by
version and checked against their published SHA-256 in `install.sh`.

## Running it

```bash
sudo bash install.sh   # FrankenPHP (official release binary) and Composer (official phar); apt: ca-certificates curl unzip
bash build.sh          # composer install from the lock file, then Laravel's config, route and event caches
SQLITE_PATH=... JWT_SECRET=... HOST=0.0.0.0 PORT=80 bash start.sh
```

`WORKERS` (default 4) sets the number of PHP workers. On one processor the number hardly matters: 1, 2 and 4 workers
answered the same number of requests, because nothing in a request waits for anything but the processor.

FrankenPHP is the only PHP on the box. `bin/php` is a two-line wrapper so that Composer and `artisan` can be run with
it: FrankenPHP's command-line mode hands a script its arguments one place off from where the `php` command puts them.

## What it is

A Laravel application: one controller with the five endpoints (`app/Http/Controllers/FeedController.php`), a class
for the database (`app/Support/Db.php`) and one for the token (`app/Support/Jwt.php`). It is served by Laravel
Octane, which keeps the application booted in long-lived workers instead of starting it again for every request.

**Under Octane the requests do not go through Laravel's HTTP kernel and router.** Octane has routes of its own
(`Octane::route`) that it answers directly; `app/Support/DirectRoutes.php` is that route table extended with the two
endpoints that carry a post id, and all five endpoints are answered there, by the same controller. The ordinary
Laravel routes are still in `routes/api.php` and serve the application when it runs without Octane.

## Where the time goes, and what was done about it

Measured on the challenge's droplet size (1 vCPU, 2 GB). The database is 5 to 10% of a request (the feed query takes
about 60 microseconds, one post about 7); nearly all of the rest is the framework around the five queries.

| Setup | Processor time per request under load |
|---|---|
| Laravel booted for every request (what PHP-FPM does, OPcache warm) | about 5 ms |
| Octane as installed | 2.05 ms |
| + server config without compression, static-file look-up and access log | 1.85 ms |
| + the three fixed paths on Octane's own routes | 1.67 ms |
| + all five endpoints on Octane's own routes | 1.32 ms |

- **Octane instead of starting Laravel per request.**
- **Workers are never recycled** (`--max-requests` is set far out of reach). Octane's default restarts a worker
  every 500 requests, and every restart boots Laravel again. Nothing in this app grows between requests.
- **Octane's per-request resets cut from 39 to 1** (`config/octane.php`). Before each request Octane resets whatever
  a request could have left behind: sessions, auth, mail, queues, views, cache, translations. This API has none of
  that state, and those resets were a quarter of a request's time.
- **Octane's own routes for all five endpoints** (see above). For an endpoint with no middleware, getting through
  Laravel's kernel and router took several times longer than producing the answer.
- **No middleware** (`bootstrap/app.php`): no sessions, cookies or CSRF tokens exist here.
- **A lean server config** (`Caddyfile`, Octane's own with three things removed): no compression (optional in the
  spec; compressing every feed answer cost more processor time than building it), no look-up of a static file for
  every request, no access log.
- **Laravel's caches are built in `build.sh`**: configuration, routes, events, and Composer's authoritative class map.
- **OPcache with the JIT** (`php.ini`): files are compiled once and never checked on disk again
  (`validate_timestamps=0`), and the tracing JIT compiles the hot paths.
- **Go's memory housekeeping** (`start.sh`): `GOGC=400` with `GOMEMLIMIT=700MiB`, so the web server collects less
  often but stays under a fixed ceiling.
- **One SQLite connection per worker, opened once, with its statements prepared once.** A query is one call to run
  it and one to read its rows. A prepared statement keeps SQLite's query plan, not rows: every request still reads
  its data from the database (rule 5). Laravel's database layer is not used; it would only wrap five queries.
- **Pragmas**: `journal_mode=WAL`, `synchronous=NORMAL` (rule 6), `busy_timeout=5000`, a 1 GiB `mmap_size` so reads
  come from the kernel's page cache without a copy. Each write is its own committed statement before the response
  is sent.
- **SQL**: the reference queries. A like is one statement, `INSERT … SELECT … FROM posts WHERE id = ? ON CONFLICT DO
  NOTHING`; a second query runs only when nothing was inserted, to tell "already liked" from "no such post".
- **JWT verified by hand** on every request with `hash_hmac` and a constant-time compare: HS256 only, `exp` checked.
  Nothing about a token is remembered between requests.
- **JSON written directly** in the key order of the spec; only the two free texts (a post's body, its author) go
  through `json_encode`.

## Measured with `bench/load.js`

Not the challenge's own set-up: k6 ran on a PC 40 ms away, over an office line, so every figure carries about 40 ms
of network.

| Setup | Users | Hold | 95th | 99th | Failed | Result |
|---|---|---|---|---|---|---|
| Octane as installed | 5,000 | 2 min | 1.05 s | 1.8 s | 0% | fails: the processor is full |
| This submission | 5,000 | 5 min | 51 ms | 95 ms | 0.00% (16 of 185,629) | passes, processor 35 to 45% idle |
| This submission, one step earlier (three resets instead of one) | 6,000 | 2 min | 51 ms | 79 ms | 0% | passes, processor 20 to 35% idle |

Above about 6,000 users the load generator's own network started dropping idle connections, so the limit of this
submission was not reached in these runs: at 6,000 users the droplet's processor was still 20 to 35% idle.

## License

MIT, under the repo's [license](../../LICENSE).
