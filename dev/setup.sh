#!/usr/bin/env bash
# Prepares the dev stack: .env, secrets, working directories, NFS sanity check.
# Safe to re-run - it never overwrites an existing .env or secret.
set -euo pipefail

cd "$(dirname "$0")"
REPO_ROOT="$(cd .. && pwd)"

fail() { echo "ERROR: $*" >&2; exit 1; }

# --- .env --------------------------------------------------------------------
if [ ! -f .env ]; then
    cp .env.example .env
    echo "==> Created dev/.env from the template."
    echo "    Fill in NAS_HOST and NAS_BOOKS_EXPORT, then run this script again."
    exit 0
fi
# shellcheck disable=SC1091
set -a; . ./.env; set +a

[ -n "${NAS_HOST:-}" ]         || fail "NAS_HOST is not set in dev/.env"
[ -n "${NAS_BOOKS_EXPORT:-}" ] || fail "NAS_BOOKS_EXPORT is not set in dev/.env"
case "$NAS_HOST" in
    *[a-zA-Z]*) echo "WARNING: NAS_HOST=$NAS_HOST looks like a hostname."
                echo "         The NFS mount is made by the Docker daemon, which does not"
                echo "         read your /etc/hosts. Prefer a literal IP address." ;;
esac

# --- secrets -----------------------------------------------------------------
gen_secret() {
    local file="$1" desc="$2"
    if [ -s "$file" ]; then
        echo "==> $desc already present ($file)"
    else
        openssl rand -base64 24 | tr -d '\n' > "$file"
        chmod 600 "$file"
        echo "==> Generated $desc ($file)"
    fi
}
mkdir -p "$REPO_ROOT/secrets"
gen_secret "$REPO_ROOT/secrets/flibusta_pwd.txt"           "database password"
gen_secret "$REPO_ROOT/secrets/flibusta_app_admin_pwd.txt" "app admin password"

# --- working directories -----------------------------------------------------
# php-fpm workers run as uid 82 (www-data) and create files directly in /cache
# and /sql. These directories are owned by you on the host, so they have to be
# group/other writable or the app cannot write its status file or zip index.
mkdir -p data/cache data/sql
chmod 777 data/cache data/sql
echo "==> Working directories ready (data/cache, data/sql)"

# --- NFS sanity check --------------------------------------------------------
if command -v showmount > /dev/null 2>&1; then
    echo "==> Exports visible on $NAS_HOST:"
    if showmount -e "$NAS_HOST" 2>/dev/null | sed 's/^/    /'; then
        showmount -e "$NAS_HOST" 2>/dev/null | awk 'NR>1{print $1}' \
            | grep -qx "$NAS_BOOKS_EXPORT" \
            || echo "    WARNING: $NAS_BOOKS_EXPORT is not in the list above."
    else
        echo "    Could not query the NAS. Check that the NFS server is running"
        echo "    and that this machine is allowed in the export rules."
    fi
else
    echo "==> showmount not installed, skipping the NFS check."
    echo "    (it comes with nfs-common; install-docker.sh installs it)"
fi

cat <<EOF

Ready. Next:

    cd $(pwd)
    docker compose up -d --build        # first build takes a few minutes

    # follow the startup: migrations, admin user, zip index
    docker compose logs -f php-fpm

Then open http://localhost:${DEV_HTTP_PORT:-8080} and log in as
"${DEV_ADMIN_USER:-admin}" with:

    $(cat "$REPO_ROOT/secrets/flibusta_app_admin_pwd.txt"; echo)

The database starts empty. Use Сервис -> "Скачать базу", then "Обновить базу"
to populate it; see dev/README.md for the faster alternative if your NAS
already has a dump.
EOF
