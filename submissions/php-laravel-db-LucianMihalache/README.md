# PHP + Laravel with the DB facade, on Octane and FrankenPHP

| | |
|---|---|
| Language | PHP 8.5.11 (the PHP inside FrankenPHP; thread-safe build) |
| Framework | Laravel 13.34.0 with Laravel Octane 2.20.0; SQL through the `DB` facade |
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
| **The same Laravel app with the `DB` facade** (this one) | Octane, Laravel's kernel, middleware and router, validation, SQL through the `DB` facade | 2.72 ms | 3,500 users (4,000 fails) | 143 ms / 446 ms | 14% |
| [Laravel Octane, stripped down](../php-laravel-stripped-LucianMihalache) | Octane only: its own routes answer every request, then one controller and PDO | 1.43 ms | 5,500 users (not its limit) | 47 ms / 109 ms | 32% |
| [No framework: PHP on FrankenPHP](../php-frankenphp-LucianMihalache) | one PHP worker script and PDO | 0.63 ms | 5,500 users (not its limit) | 44 ms / 70 ms | 62% |

This entry passed the five-minute hold at 3,500 users (129,948 requests, none failed) and fails at 4,000 (95th
percentile 2.46 s, still no failed request): the processor is full.

## What it is

The same Laravel application as the [Eloquent entry](../php-laravel-eloquent-LucianMihalache): the same routes in `routes/api.php`, middleware,
validation, error handling, Octane settings and server configuration. The one difference is the data layer: the
controller runs the queries as SQL through Laravel's `DB` facade instead of through Eloquent models. The controller
is the only file that differs (the Eloquent entry also has its three models and an API resource). Comparing the two
shows what Eloquent itself costs.

## What was done, exactly

Everything the Eloquent entry does (Octane, `php artisan optimize`, OPcache with the JIT, workers recycled every
1,000 requests, Octane's refreshers trimmed to the 8 a RESTful API with a database needs, the connection kept
open), and one thing more:

- **The queries are SQL, run with `DB::select` and `DB::affectingStatement`.** The feed is one query (the reference
  query of SPEC.md) instead of two, no model objects are built, and a row comes back as an object whose fields are
  already in the key order the spec asks for. Creating a post is one `INSERT … RETURNING`; a like is one
  `INSERT … SELECT … ON CONFLICT DO NOTHING`, with a second query only when nothing was inserted.

## What was deliberately left alone

- Every request still goes through Laravel's kernel, default middleware and router, and through Laravel's database
  layer (a statement is prepared again for every query).
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
