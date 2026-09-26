# flibusta-at-home — Project Context

A self-hosted online library that mirrors the Flibusta online library. It imports
Flibusta database dumps into Postgres and serves books from Flibusta's zip archives
held on a local volume, so the library works without access to the Flibusta site.

The UI is in Russian; many commit messages are in Russian as well.

## Main features

- Viewing and downloading books
- Full-text search (FTS) over authors, book titles, and book series
- Author / book / series info pages (short descriptions, Flibusta comments)
- Synchronization with an up-to-date Flibusta DB by downloading and importing DB dumps
- Books stored on a local volume; if a book is missing, on-the-fly download from a
  Flibusta mirror is possible when the feature is enabled in the configuration
- Supported book formats: fb2, pdf, djvu, docx, epub, mobi, html, txt, rtf.
  fb2 books can additionally be downloaded/converted on the fly to fb2, fb2.zip,
  epub, kfx, azw8, txt, pdf (via the bundled `fbc` binary)
- Password-protected accounts; optionally accessible without log-in from a trusted
  ("home") network defined in the configuration
- OPDS support (for e-reader apps)
- Favorites (books/authors/series) and per-user reading position & settings

## Core stack

- php-fpm (PHP 8.5) in Docker — this is the flibusta-at-home application container
- Postgres backend database
- A web server (nginx) acting as the front controller in front of php-fpm
- External JS libraries for in-browser book rendering
- `fbc` (fb2cng) binary for fb2 → epub/kfx/azw8/… conversion

## Build & run

Build the application image:
```
docker buildx build -f phpdocker/php-fpm/Dockerfile .
```

There are two deployment configurations under `docs_and_configs/`:

- **`external_services_config/` — preferred.** flibusta-at-home ships *only* the
  php-fpm application container (`flibusta-fpm`). Postgres and the web server are
  expected to already exist as separate Docker services, joined over shared external
  Docker networks. Secrets (DB and app-admin passwords) are provided via Docker
  secrets / `*_FILE` env vars. Static public assets are shared to the external web
  server through a named volume (`/public_mountpoint`). The library volume `/flibusta`
  is mounted read-only. Reverse-proxy snippets (`flibusta.subdomain.conf`,
  `flibusta.subfolder.conf`), plus `fail2ban` and `logrotate` configs, are included.

- **`all_in_one_config/` — self-contained, second choice.** Bundles the `postgres`,
  `webserver` (`nginx:alpine`) and `redis` containers together with php-fpm, builds the
  app image from the repo, and is run with `--project-directory .` from the repo root.
  Its nginx serves plain **HTTP**, which is acceptable in two cases only: TLS is
  terminated upstream (Cloudflare Tunnel, another proxy — then
  `FLIBUSTA_ALLOW_ADMIN_ACCESS_BY_HTTP` must stay unset, because the browser is still
  on https:// and returns `Secure` cookies), or an isolated network / development box
  that cannot obtain a certificate (then the flag is required). `external_services_config`
  remains preferred for anything published to the internet. Static assets come from the
  same `flibusta_public_files` volume, mounted read-only in the web server. The
  `postgres` and `webserver` containers exist *only* in this configuration; see
  `docs_and_configs/all_in_one_config/README.md`.

Dockerfiles and the bundled nginx / php config live in `phpdocker/`
(`phpdocker/php-fpm/`, `phpdocker/pg/`, `phpdocker/nginx/`).

There is no automated test suite yet (unit tests are planned) — verify changes by
running the app. CI: `.github/workflows/dockerhub-release.yml` publishes images to
Docker Hub.

## Layout

- `application/` — code of the library running at runtime
- `application/public/` — web-exposed entry points and static assets
- `application/modules/<name>/` — feature modules (see Architecture)
- `application/opds/` — OPDS output rendering
- `tools/` — backend/ops scripts (DB import, book download, docker entrypoint, admin ops)
- `docs_and_configs/` — installation docs and deployment configuration templates
- `phpdocker/` — Dockerfiles and web/php configuration

## Entry points

All page requests are rewritten by the web server to `application/public/index.php`
(front controller). Direct entry points:

- `application/public/index.php` — routing and page rendering
- `application/public/login.php` — log-in handling
- `application/public/fb2.php` — fb2 download and on-the-fly conversion (via `fbc`)
- `application/public/usr.php` — download of non-fb2 books

## Architecture

- **Front-controller routing** (`decode_gurl()` in `application/functions.php`):
  a URL path is parsed as `/<module>/<action>/<var1>/<var2>/<var3>`. The first
  segment is the module; it must be listed in `allowed_route_modules()`. An optional
  `FLIBUSTA_WEBROOT` prefix is stripped first. Path segments are cleaned with
  `sanitize_route_token()`.
- **Modules** live in `application/modules/<name>/`:
  - `module.conf` — setup, access checks, redirects/headers, request handling
  - `index.php` — rendering
  - `module_menu.php` — optional module menu
  Current modules: `primary, book, author, authors, series, genres, fav, favlist,
  help, opds, users, service, settings, addbook, 404`.
- **Admin / service operations** (`service` module) follow a shared pattern:
  a CSRF-protected POST is validated in `service/module.conf`, then `service/index.php`
  launches a `tools/*.sh` script in the background
  (`stdbuf -o0 /tools/<x>.sh > ADMINOPSTATUSFILE &`). Long ops serialize on an
  `flock` over `ADMINOPLOCKFILE`; the UI polls `ADMINOPSTATUSFILE` for progress.
- **Sessions** have two backends, chosen in `init.php`. Without
  `FLIBUSTA_REDIS_HOST` they live in Postgres
  (`application/PostgresSessionHandler.php`); with it they live in Redis
  (`application/RedisSessionHandler.php`), which is what lets hot paths run
  without touching the DB at all. Clients on the trusted network get long-lived
  sessions either way. Code that needs to reach *other* users' sessions (the
  admin session list, "log out everywhere") must go through the `SessionStore`
  interface in `application/SessionStore.php`, never through SQL against
  `php_sessions`.

- **The PDO handle is lazy** (`application/LazyPDO.php`): no connection is opened
  until the first statement runs. Do not assume `$dbh` implies a live connection,
  and do not add a PDO method call path that the subclass does not override.

- **User settings** (`user_settings` table + the `user_excluded_genres` child
  table) are still never cached in `$_SESSION` — a session copy is per device and
  long-lived, so it goes stale after a save somewhere else. They are read through
  `user_prefs()`, which keeps **one shared copy** in Redis that every writer
  deletes, so all devices see a change on their next request. Follow that pattern
  when adding a setting: read it in `user_prefs()`, and call
  `user_prefs_invalidate()` wherever it is written. `last_book` is the exception —
  written on every book view, read only at log-in, so it stays DB-only with a
  write-dedup marker in the cache.
- **Locally added books** (`addbook` module, admin-only): stored durably in
  `local_*` tables with ids from sequences starting at 10000000 (dump ids never
  reach that); also dual-written into the `lib*` tables so they are live
  immediately. The book file is packaged as a one-book zip
  (`/cache/local/f.fb2.<id>-<id>.zip` or `f.usr-<id>-<id>.zip`, inner entry
  `<id>.<ext>`) so the normal `book_zip` range lookup and `update_zip_list.php`
  handle it unchanged. After each dump import (which TRUNCATEs the `lib*`
  tables), `tools/merge_local_books.php` replays the `local_*` rows back,
  applying `FLIBUSTA_LOCAL_DUPLICATE_POLICY`. Bookless authors are kept in the
  DB as candidate authors for this module; regular search/browse/OPDS filter
  them out at query level.

## Runtime layout (Docker volumes) & key constants

Defined in `application/init.php`:

- `/flibusta` — main library zip archives (`LIBRARY_PATH`); mounted read-only in the
  preferred config
- `/cache` — working data (`CACHE_PATH`): `local/` (`LOCAL_LIBRARY_PATH`, locally
  added books), `covers/`, `authors/`, `tmp/`, `locks/`, `etag/`, `log/`,
  `status` file, sessions
- `/sql` — DB dumps (`SQL_PATH`)
- `ADMINOPLOCKFILE` = `/cache/locks/adminop.lock`, `ADMINOPSTATUSFILE` = `/cache/status`

## Caching (Redis, optional)

Enabled by `FLIBUSTA_REDIS_HOST`. When unset, sessions stay in Postgres and every
`cache_*()` call in `application/cache.php` is a no-op, so **every code path must
still work with no cache**. When set and Redis is unreachable, requests fail with
503 rather than silently splitting session state across two stores.

Keys (under `FLIBUSTA_REDIS_PREFIX`, default `flibusta:`):

| Key | Holds | Dropped by |
|---|---|---|
| `sess:<id>`, `sess:all`, `sess:user:<uid>` | sessions + indexes | TTL, `SessionStore` |
| `auth:<hmac>`, `auth:user:<uid>` | verified HTTP Basic credentials | TTL (5 min), `user_security_changed()` |
| `book:<gen>:<id>` / `:hdr:` / `:rev:` | book metadata, badges, comments | `lib:gen` bump |
| `user:<uid>:prefs`, `user:<uid>:fav` | settings + hidden genres, favorite ids | their `*_invalidate()` helpers |
| `user:<uid>:last_book` | write-dedup marker (not a read cache) | `user_security_changed()` |
| `lib:gen`, `cache:secret` | generation counter, HMAC key — **no TTL on purpose** | never (evicting them would be wrong) |

Run the instance with `--maxmemory <n> --maxmemory-policy volatile-lru`: everything
but the last row carries a TTL and may be evicted, and sessions are touched on
every request so cold book entries go first.

Three rules to keep caches honest:

- Anything that writes the `lib*` tables or `book_zip` must bump the library
  generation (`php /tools/cache_bump.php` from a script, `lib_generation_bump()`
  in-process) **and** regenerate the archive index (below).
- Anything that changes a user's password, role or existence must call
  `user_security_changed()`.
- Anything that writes `user_settings` (except `last_book`),
  `user_excluded_genres` or a user's `fav` rows must call
  `user_prefs_invalidate()` / `user_favs_invalidate()`.

**Archive index.** The book-id to zip mapping is served from `/cache/zip_index.php`,
a generated PHP file (`application/zipindex.php`) that OPcache keeps in shared
memory, so the lookup needs neither the DB nor Redis. It is written by
`tools/update_zip_list.php` and by the addbook module; `book_zip` remains as the
fallback until the first archive rescan after an upgrade. OPcache timestamp
validation must stay enabled or workers will serve a stale layout.

`FLIBUSTA_DEBUG_DB=true` logs one `dbstats <uri> connected=<0|1> statements=<n>`
line per request — the way to check that a hot path really is DB-free.

## Database

- Postgres. Schema: `tools/postgres_init.sql`; migrations: `tools/postgres_migration.sql`.
- Dumps are downloaded and imported via `tools/app_import_sql.sh`
  (helpers: `tools/app_topg`, `tools/app_db_converter.py`).
- FTS vectors: `tools/update_vectors.sql`.

## Security conventions (do not regress — enforced by a prior security audit)

- CSRF token on every state-changing POST (`get_csrf_token()` /
  `validate_csrf_token()`); the `service` module keeps its own token.
- **Escape all DB-origin / user-origin data before output** with
  `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` (and/or `strip_tags`). Book/author
  fields from the Flibusta DB are untrusted and must not be echoed as-is.
- Use `sanitize_route_token()` for path segments and `escapeshellarg()` for any
  value passed to a shell command.

## Configuration (environment variables)

- `FLIBUSTA_URL` — Flibusta mirror base URL (default `https://flibusta.is`)
- `FLIBUSTA_WEBROOT` — sub-path the app is served under (empty = root)
- `FLIBUSTA_TRUSTED_NET` — trusted network CIDR (no log-in required; admin actions
  still require auth).
- `FLIBUSTA_ALLOW_ADMIN_ACCESS_BY_HTTP` — allow admin access over plain HTTP
- `FLIBUSTA_ENABLE_MISSING_BOOK_DOWNLOAD` — enable on-the-fly download of missing books
- `FLIBUSTA_LOCAL_DUPLICATE_POLICY` — `keep_both` (default) or `prefer_dump`: what to
  do when a locally added book also appears in a newly imported dump
- `MAX_FB2_SIZE_2_DISPLAY` — max fb2 size rendered in-browser
- `FLIBUSTA_DBHOST` / `FLIBUSTA_DBNAME` / `FLIBUSTA_DBUSER` / `FLIBUSTA_DBTYPE` and
  `FLIBUSTA_DBPASSWORD` (or `FLIBUSTA_DBPASSWORD_FILE`) — database connection
- `POSTGRES_ADMIN_DBPASSWORD_FILE` — Postgres admin password (DB provisioning)
- `FLIBUSTA_APP_ADMIN` / `FLIBUSTA_APP_ADMIN_PASSWORD_FILE` (or `ADMIN_PASSWORD`) —
  application admin account
- `FLIBUSTA_REDIS_HOST` / `FLIBUSTA_REDIS_PORT` / `FLIBUSTA_REDIS_DB` /
  `FLIBUSTA_REDIS_PREFIX` / `FLIBUSTA_REDIS_PASSWORD` (or
  `FLIBUSTA_REDIS_PASSWORD_FILE`) — optional cache and session store; unset
  disables both
- `FLIBUSTA_SESSION_TRUSTED_TTL` — idle lifetime of a trusted-network session in
  Redis (default 30 days)
- `FLIBUSTA_DEBUG_DB` — `true` logs per-request DB connection/statement counts
- `FLIBUSTA_APP_ROOT`, `TZ` — bootstrap / timezone
