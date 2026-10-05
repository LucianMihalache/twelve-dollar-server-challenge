# PHP on FrankenPHP, no framework

| | |
|---|---|
| Language | PHP 8.5.11 (the PHP inside FrankenPHP; thread-safe build) |
| Framework | none: four PHP files, no Composer packages |
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
| [Laravel Octane, stripped down](../php-laravel-stripped-LucianMihalache) | Octane only: its own routes answer every request, then one controller and PDO | 1.43 ms | 5,500 users (not its limit) | 47 ms / 109 ms | 32% |
| **No framework: PHP on FrankenPHP** (this one) | one PHP worker script and PDO | 0.63 ms | 5,500 users (not its limit) | 44 ms / 70 ms | 62% |

This entry passed the five-minute hold at 5,500 users (204,392 requests, none failed) with 62% of the processor
idle. **It was not pushed to its limit**: the load generator's network cannot drive more (see the end). By idle
processor share alone the limit would be roughly 12,000 to 13,000 users; that is arithmetic, not a measurement, and
at that many open connections something other than the processor may give first.

## What it is

The API as plain PHP: `public/worker.php` is started once in each FrankenPHP worker and then answers requests in a
loop; `src/Feed.php` has the five endpoints, `src/Db.php` the database and `src/Jwt.php` the token check. There is
nothing to install besides FrankenPHP and nothing to build.

This is the entry to compare with the other languages' entries that use no framework. The three Laravel entries
([Eloquent](../php-laravel-eloquent-LucianMihalache), [`DB` facade](../php-laravel-db-LucianMihalache), [stripped down](../php-laravel-stripped-LucianMihalache)) show what the framework adds.

## What was done, exactly

- **FrankenPHP in worker mode**: the script and its classes are loaded once per worker and stay in memory; a request
  is one pass through a loop. Started directly with `frankenphp run`, serving the port itself.
- **OPcache with the JIT** (`php.ini`), files never re-checked on disk.
- **A minimal server configuration** (`Caddyfile`): every request goes straight to the worker; no static files, no
  compression (optional in the spec, and the load script never asks for it), no access log.
- **Go's memory housekeeping** (`start.sh`): `GOGC=400` with `GOMEMLIMIT=700MiB`.
- **Routing is a few string comparisons**, and the answer is written as a JSON string in the key order of the spec;
  only a post's body and author go through `json_encode`.

**The database part, as an extra**: each worker opens SQLite once through PDO and prepares its five statements
once, so a query is one call to run it and one to read its rows. A prepared statement keeps the query plan, not
rows: every request still reads its data from the database (rule 5). Pragmas: `journal_mode=WAL`,
`synchronous=NORMAL` (rule 6), `busy_timeout=5000`, a 1 GiB `mmap_size`. A like is one statement,
`INSERT … SELECT … FROM posts WHERE id = ? ON CONFLICT DO NOTHING`; a second query runs only when nothing was
inserted, to tell "already liked" from "no such post".

## The token check (the same in all four entries)

`Jwt.php` verifies the bearer token by hand on every request: the header must say HS256, the signature is recomputed
with `hash_hmac` and compared in constant time, `exp` is checked, and `sub` and `username` are validated. Nothing
about a token is remembered between requests (rule 5).

## Running it

```bash
sudo bash install.sh   # FrankenPHP (official release binary, pinned and checksummed); apt: ca-certificates curl
bash build.sh          # nothing to build
SQLITE_PATH=... JWT_SECRET=... HOST=0.0.0.0 PORT=80 bash start.sh
```

`WORKERS` (default 4) sets the number of PHP workers.

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
