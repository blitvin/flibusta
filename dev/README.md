# Local development stack

Runs the whole library on your workstation — php-fpm, nginx, Postgres and Redis —
with the source bind-mounted from the repo and books served from the NAS over
NFS. Edit a file in VSCode, reload the page, see the change. No rebuild.

The nginx config and the container entrypoint are the ones that ship, so what
you exercise here is what production runs. The differences are deliberate and
limited to three things: live source mounts, Xdebug, and `FLIBUSTA_ALLOW_ADMIN_ACCESS_BY_HTTP`.

## Prerequisites

### On the NAS

Export the books directory read-only to your workstation. In `/etc/exports`:

```
/export/Flibusta.Net  192.168.1.50(ro,sync,no_subtree_check)
```

(replace with your workstation's address and the real path), then `exportfs -ra`.
Read-only is enough — the library only ever reads `/flibusta`. Check from the
workstation with `showmount -e <nas-ip>`.

### On the workstation

```bash
./install-docker.sh      # Docker Engine, compose plugin, nfs-common
newgrp docker            # or log out and back in
```

Mint ships its own codename (`virginia`), which does not exist in Docker's apt
repository — the script reads `UBUNTU_CODENAME` (`jammy`) instead. That is the
usual reason a hand-typed Docker install fails on Mint.

Restart VSCode from a shell that has the `docker` group, or its Docker extension
will report permission denied even though the CLI works.

## Setup

```bash
./setup.sh               # writes dev/.env from the template, then exits
$EDITOR .env             # NAS_HOST and NAS_BOOKS_EXPORT
./setup.sh               # generates secrets, creates data dirs, checks NFS
docker compose up -d --build
docker compose logs -f php-fpm
```

First build takes a few minutes (it downloads the `fbc` binary and builds the
PHP extensions). Then <http://localhost:8080>, log in as `admin` — `setup.sh`
prints the generated password, and it is in `../secrets/flibusta_app_admin_pwd.txt`.

`NAS_HOST` must be an IP address, not a hostname. The NFS mount is performed by
the Docker daemon, which does not consult your `/etc/hosts`.

## Filling the database

The database starts empty, so searches and book pages will be empty until you
import a dump. From **Сервис**: «Скачать базу», wait, then «Обновить базу», then
«Сканирование ZIP». Watch progress in the page or in `docker compose logs -f php-fpm`.

This is a multi-gigabyte download and a long import. Two ways to shorten it:

**Copy a dump from the NAS.** If the NAS already downloaded one, put it straight
into `dev/data/sql/` and skip to «Обновить базу». Export the NAS `sql` directory
over NFS as well, or just `scp` the file.

**Import, then snapshot.** Once imported, take a local snapshot so you never pay
for it twice:

```bash
docker compose exec -T postgres pg_dump -U flibusta -Fc flibusta > ~/flibusta-dev.dump
# later, to reset to that state:
docker compose exec -T postgres pg_restore -U flibusta -d flibusta --clean --if-exists < ~/flibusta-dev.dump
```

«Сканирование ZIP» is the step that populates `book_zip` and writes
`/cache/zip_index.php`. Without it the archive lookup falls back to the table,
so run it after every import if you are testing the index.

## Everyday use

```bash
docker compose logs -f php-fpm     # PHP errors, warnings, dbstats lines
docker compose restart php-fpm     # after changing php.ini or xdebug.ini
docker compose exec php-fpm sh     # a shell in the container
docker compose down                # stop; add -v to also drop the database
```

PHP, JS and CSS edits need nothing — the next request picks them up. Only
`Dockerfile`, `php-fpm.conf` and `nginx.conf` changes need `up -d --build`
or a restart.

## Step debugging

1. Install the recommended extensions (VSCode offers them on opening the repo;
   `.vscode/extensions.json` lists them).
2. Run **Listen for Xdebug (dev stack)** from the Run panel.
3. Set a breakpoint.
4. Load a page with the trigger: append `?XDEBUG_TRIGGER=1`, or install the
   Xdebug helper browser extension and toggle it on.

The trigger requirement is deliberate — see the comment in `xdebug.ini`. Since
this project is largely about per-request cost, having the debugger attach to
every request would distort the very numbers you are measuring. Use
**Listen for Xdebug (break on entry)** for bootstrap code that runs before any
breakpoint you could set by hand.

If breakpoints stay hollow and never bind, it is almost always `pathMappings` —
the container sees `/application`, VSCode sees `<repo>/application`. Uncomment
`xdebug.log` in `xdebug.ini` and restart php-fpm to see what the debugger thinks.

## Checking the caching work

`FLIBUSTA_DEBUG_DB=true` is on by default here, so every request logs its cost:

```bash
docker compose logs -f php-fpm | grep dbstats
```

A book download that is already cached locally should report
`connected=0 statements=0`. Watch the cache itself alongside it:

```bash
redis-cli -p 26379 monitor
redis-cli -p 26379 --scan --pattern 'flibusta:*' | head -50
redis-cli -p 26379 info keyspace
```

**Test the no-cache path too.** Every code path has to work with Redis off, and
that is easy to break without noticing:

```bash
# in dev/.env
FLIBUSTA_REDIS_HOST=
```

then `docker compose up -d`. Sessions move back to Postgres and every `cache_*()`
call becomes a miss. Pages must still work, only slower.

Similarly, to exercise trusted-network behaviour, set `FLIBUSTA_TRUSTED_NET` to
the Docker bridge range (`172.16.0.0/12`) and recreate.

## Troubleshooting

**`docker compose up` fails on the books volume.** Docker only mounts NFS when
the volume is first used, so export problems surface as a container that will
not start. Check the export is visible (`showmount -e $NAS_HOST`), that the
workstation address is allowed, and that the path matches exactly. If the NAS
only speaks NFSv3, change `nfsvers=4` to `nfsvers=3` in `docker-compose.yml`.
After fixing, remove the cached volume so it is mounted afresh:
`docker compose down && docker volume rm flibusta-dev_books`.

**Permission denied writing to /cache.** `setup.sh` makes `data/cache` and
`data/sql` world-writable because php-fpm workers run as uid 82 while the
directories belong to you. If you recreated them by hand, `chmod 777` them.

**`<host> is unreachable, can not access DB`.** The entrypoint pings Postgres
before starting. The compose file waits on a healthcheck, so this normally means
Postgres itself failed — check `docker compose logs postgres`.

**Admin pages redirect or refuse.** `FLIBUSTA_ALLOW_ADMIN_ACCESS_BY_HTTP=true`
must be set, otherwise admin access over plain HTTP is blocked. It is set in
the compose file; never set it in a real deployment.

**Books list is there but every book 404s.** The catalogue is imported but the
archive index is not built: run «Сканирование ZIP», and confirm `/flibusta` is
actually populated with `docker compose exec php-fpm ls /flibusta | head`.
