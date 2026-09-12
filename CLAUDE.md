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
- **Sessions** are files under `/cache/sessions` via
  `application/FileSessionHandler.php` (a `sess_<id>` payload plus a `.meta`
  JSON sidecar holding user/ip/user-agent/last-accessed for the admin "active
  sessions" tab). Clients on the trusted network get long-lived sessions and are
  exempt from GC. They are **not** in Postgres: a DB session read+write on every
  request would make DB-free cache hits impossible. The handler holds an
  exclusive `flock` for the request, so concurrent tabs no longer clobber
  `$_SESSION`.
- **Caching** (see "Caching" below) — page cache, fragment caches, and lazy PDO.
  Cached pages are shared across *all* visitors; anything per-visitor is either a
  placeholder filled on output or fetched by the browser from
  `public/user_state.php`. Keep it that way when adding to a cacheable page.
- **User settings** (`user_settings` table + the `user_excluded_genres` child
  table) are read **per request** from the DB and are deliberately never cached
  in `$_SESSION` (sessions are shared across tabs and long-lived for
  trusted-network clients, so a session copy would go stale after a save on
  another device). They *are* served from the shared cache via
  `get_user_prefs()` / `get_excluded_genres()`, which is keyed by the user's
  cache epoch — every settings save calls `bump_user_cache_epoch()`, so a change
  on one device is visible on all of them immediately. Follow this convention
  when adding a setting: read from the DB, cache by epoch, bump on save.
- **Reading positions** (scroll offset / EPUB CFI / DJVU page) and the "last
  opened book" pointer live in `/cache/positions/<user_id>.json`
  (`application/positions.php`), not in Postgres — they were the heaviest write
  path in the app (one UPSERT per ~66 ms of scrolling). Readers never embed the
  position in the page; they fetch it from `public/user_state.php`, which is what
  keeps a book page identical for every reader and therefore cacheable.
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
  `status` file, `sessions/`, `positions/`, `pagecache/`, `appcache/`,
  `book_zip.php`, `timestamps/cache_epoch`
- `/sql` — DB dumps (`SQL_PATH`)
- `ADMINOPLOCKFILE` = `/cache/locks/adminop.lock`, `ADMINOPSTATUSFILE` = `/cache/status`

## Caching

Goal: serve repeat requests without touching Postgres at all. Disable entirely
with `FLIBUSTA_CACHE_BACKEND=none` (the escape hatch — restores pre-cache
behaviour).

- **Lazy DB connection** — web requests get `application/LazyPDO.php` instead of
  a real PDO (`dbinit.php`); the connection opens on the first query. CLI tools
  still get a real PDO. Do not "fix" this by connecting eagerly.
- **Backends** — `application/cache/`: `FileCache` (default, `/cache/appcache`
  + `/cache/pagecache`, atomic tmp+rename writes) and `RedisCache` (phpredis,
  **fails open** — an unreachable redis logs once and degrades to no caching,
  never an error page). Get them via `flib_cache()` / `flib_page_cache()`.
- **Page cache** (`application/pagecache.php`, hooked into
  `public/index.php` *after* the access check so auth is never bypassed):
  read-only modules only (`primary, book, author, authors, series, genres, help,
  opds`); GET/HEAD only; OPDS `fav`/`favs` excluded. A hit still honours the
  `DBUPDATE_LOCK` maintenance gate.
  - **Session-held browsing filters** — `primary`, `authors` and `series` all
    read a query param into `$_SESSION` and then render later plain URLs from it.
    Both halves are declared in `page_cache_session_filters()`: the request
    carrying the param is not cached, and the resulting session state is hashed
    into the key. **When adding a filter of this kind, add it there** — otherwise
    a filtered listing gets stored under the unfiltered URL and served to
    everybody.
  - **Pages are keyed by what changes their bytes, not by who asks**
    (`page_cache_facets()`): only display preferences — `book_view_mode` for the
    book module, the hidden-genre list for `primary`. Two users whose
    preferences agree, and anonymous visitors (who get the defaults), all share
    one entry. **If you add a preference that changes rendering, add it to
    `page_cache_facets()`** or users will see each other's layout.
  - Everything genuinely per-visitor is kept out of the stored body:
    - **Nav chrome** (username badge, admin/settings menu entries) is emitted by
      `renderer.php` as placeholders and substituted per request by
      `flib_output_filter()` (`application/user_chrome.php`), installed as the
      output-buffer callback in `public/index.php` so it runs on cache hits,
      normal renders and `die()` paths alike. Server-side, so there is no flash
      or layout shift. Note it is emitted *as* a placeholder rather than
      string-replaced afterwards: a username is not unique, and an author of the
      same name in a listing would otherwise be corrupted.
    - **CSRF tokens** never reach the cache: `page_cache_store()` swaps the live
      token for `CSRF_CACHE_PLACEHOLDER` and the output filter substitutes the
      current session's token back in. Token rotation therefore cannot
      invalidate a stored page.
    - **Favourite marks** are not rendered at all. `fav_slot()` emits an empty
      `<span class="flib-fav" data-fav-type data-fav-id>` and `public/js/fav.js`
      draws the button from `public/user_state.php`. The slot starts empty, so
      anonymous visitors see nothing appear and then vanish.
  - `public/user_state.php` is the single per-visitor endpoint (`no-store`):
    login state, CSRF token, favourite id sets and — with `bookid`/`kind` — the
    reading position, so a reader page makes one request rather than two.
- **Fragment caches** (`functions.php`): `user_fav_bookids()` (replaces a
  per-card `SELECT COUNT(*) FROM fav`), `get_excluded_genres()`,
  `get_user_prefs()`, `get_book_download_meta()` (lets downloads and cover
  extraction run DB-free).
- **`book_zip` map** (`application/book_zip_store.php`) — the id-range → archive
  table is mirrored into `/cache/book_zip.php`, regenerated by
  `tools/update_zip_list.php` and by `addbook`. Use `book_zip_lookup()`; it falls
  back to SQL and self-heals if the file is missing.
- **Invalidation** — no active purging is needed, because the invalidation token
  is part of the key. Two independent kinds:
  - *Global epoch* (`cache_global_epoch()` — the `filemtime` of
    `/cache/timestamps/cache_epoch`, restamped by `app_import_sql.sh` /
    `app_reindex.sh` and the admin "clear cache" op) keys everything derived from
    the dump: rendered pages and `bookmeta`. Being *in the key* is what lets the
    shell scripts invalidate a redis-backed cache without a redis client.
  - *Per-user epochs* (`user_cache_epoch($id, $scope)` /
    `bump_user_cache_epoch()`) key user-owned fragments. They are **scoped** —
    `'fav'` for favourite id sets, `'prefs'` for preferences and hidden genres —
    because one shared counter would make every heart click re-read
    `user_settings`. User-scoped keys deliberately **omit** the global epoch: an
    import TRUNCATEs only the `lib*` tables, so it has nothing to invalidate
    there. Keep both properties when adding a user-owned fragment.

  TTLs are only a safety net.

## Database

- Postgres. Schema: `tools/postgres_init.sql`; migrations: `tools/postgres_migration.sql`.
- Dumps are downloaded and imported via `tools/app_import_sql.sh`
  (helpers: `tools/app_topg`, `tools/app_db_converter.py`).
- FTS vectors: `tools/update_vectors.sql`.
- Deliberately **not** in Postgres: sessions, reading positions, and the runtime
  `book_zip` lookup (see above). Auth data (`users`, `user_tokens`,
  `login_attempts`) stays in Postgres and is never cached. The `progress`,
  `epub_progress`, `djvu_progress` tables and `user_settings.last_book` are
  dormant — migrated to files by `tools/migrate_positions_to_files.php` (run once
  from the entrypoint, guarded by `/cache/positions/.migrated`) and kept only so
  this release can be rolled back.

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
- `FLIBUSTA_CACHE_BACKEND` — `files` (default), `redis`, or `none` (disable caching)
- `FLIBUSTA_REDIS_HOST` / `_PORT` / `_DB` / `_PASSWORD` (or `_PASSWORD_FILE`) /
  `_PREFIX` — redis backend connection (defaults `redis`, `6379`, `0`, none, `flib:`)
- `FLIBUSTA_PAGE_CACHE_TTL` (rendered pages, default 21600),
  `FLIBUSTA_DATA_CACHE_TTL` (fragments, 86400) — safety-net TTLs
- `FLIBUSTA_DBHOST` / `FLIBUSTA_DBNAME` / `FLIBUSTA_DBUSER` / `FLIBUSTA_DBTYPE` and
  `FLIBUSTA_DBPASSWORD` (or `FLIBUSTA_DBPASSWORD_FILE`) — database connection
- `POSTGRES_ADMIN_DBPASSWORD_FILE` — Postgres admin password (DB provisioning)
- `FLIBUSTA_APP_ADMIN` / `FLIBUSTA_APP_ADMIN_PASSWORD_FILE` (or `ADMIN_PASSWORD`) —
  application admin account
- `FLIBUSTA_APP_ROOT`, `TZ` — bootstrap / timezone
