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

- **`all_in_one_config/` — deprecated, kept for backward compatibility.** Bundles the
  `postgres` and `webserver` (`nginx:alpine`) containers together with php-fpm. It
  does **not** enforce HTTPS transport and therefore has security shortcomings; do not
  use it for new deployments. The `postgres` and `webserver` containers exist *only*
  in this configuration.

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
- **Sessions** are stored in Postgres via `application/PostgresSessionHandler.php`;
  clients on the trusted network get long-lived sessions.

- **User settings** (`user_settings` table + the `user_excluded_genres` child
  table) are read **per request** from the DB — they are deliberately never
  cached in `$_SESSION`, because sessions are shared across tabs and long-lived
  for trusted-network clients, so a cached copy would go stale after a save on
  another device. Follow this convention when adding a setting.
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
- `FLIBUSTA_APP_ROOT`, `TZ` — bootstrap / timezone
