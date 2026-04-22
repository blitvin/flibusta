#!/bin/sh
. /tools/dbinit.sh

if [ -z "$DB_HOST_PINGS" ]; then
   DB_HOST_PINGS=3
fi

for i in `seq 1 $DB_HOST_PINGS`
do
 ping -q -c 1 $FLIBUSTA_DBHOST
 EXITCODE=$?
 if [ $EXITCODE -eq 0 ]; then
  break
 fi
 if [ $i -lt $DB_HOST_PINGS ]; then
  sleep 1
 fi
done

if [ $EXITCODE -ne 0 ]; then
   echo $FLIBUSTA_DBHOST is unreachable, can not access DB, exiting
   exit 1
fi


if [ `$SQL_CMD -c "select 1 from pg_roles where rolname='flibusta'"  | wc -l` -eq 0 ]; then
    echo attempt to connect to postgres db has failed. Trying to initialize the DB

    FLIBUSTA_DBPASSWORD=$PGPASSWORD

    if [ -z "$POSTGRES_ADMIN_USER" ]; then
        POSTGRES_ADMIN_USER=postgres
    fi
    
    if [ ! -z "$POSTGRES_ADMIN_DBPASSWORD_FILE" ] && [ -e $POSTGRES_ADMIN_DBPASSWORD_FILE ]; then
        TPOSTGRES_ADMIN_PASSWD=`cat $POSTGRES_ADMIN_DBPASSWORD_FILE`
    fi
    if [ ! -z "$TPOSTGRES_ADMIN_PASSWD" ]; then
        PGPASSWORD=$TPOSTGRES_ADMIN_PASSWD
    fi
    
    if [ ! -z "$POSTGRES_ADMIN_PASSWD" ]; then
        PGPASSWORD=$POSTGRES_ADMIN_PASSWD
    fi
    
    if [ -z "$PGPASSWORD" ]; then
        echo Attempt to initialize postgres DB has failed. Cant obtain PG admin password
        exit 1
    fi

    psql -h $FLIBUSTA_DBHOST -d $POSTGRES_ADMIN_USER -U $POSTGRES_ADMIN_USER -v FLIBUSTA_DBUSER="$FLIBUSTA_DBUSER"  -v FLIBUSTA_DBPASSWORD="$FLIBUSTA_DBPASSWORD" -v FLIBUSTA_DBNAME="$FLIBUSTA_DBNAME" -f /tools/postgres_init.sql
    #restore flibusta password
    export PGPASSWORD=$FLIBUSTA_DBPASSWORD

    if [ `$SQL_CMD -c "select 1 from pg_roles where rolname='$FLIBUSTA_DBUSER'"  | wc -l` -eq 0 ]; then
        echo "Can't connect to the DB after initialization attempt, exiting"
        exit 1
    fi
fi

mkdir -p /sql/psql
mkdir -p /cache/authors
mkdir -p /cache/covers
mkdir -p /cache/tmp
mkdir -p /cache/etag
mkdir -p /cache/local
mkdir -p /cache/log
mkdir -p /cache/login_attempts
mkdir -p /cache/locks
mkdir -p /cache/timestamps
mkdir -p /cache/clearlists

touch /cache/locks/dbupdate.lock
touch /cache/locks/adminop.lock
touch /cache/timestamps/getcovers
touch /cache/timestamps/getsql
touch /cache/timestamps/app_reindex
touch /cache/timestamps/update_daily
touch /cache/login_attempts/flibusta_login_attempts.log

rsync -av --delete --checksum /public_files/ /public_mountpoint/
echo Checking whether migration is required
$SQL_CMD -f /tools/postgres_migration.sql > /cache/log/postgres_migration.log 2>&1
echo Migration proceeded

# Ensure admin user exists if env vars are set
if [ ! -z "$FLIBUSTA_APP_ADMIN" ]; then
    echo Admin user is set to $FLIBUSTA_APP_ADMIN, ensuring it exists
    ADMIN_PASSWORD="$FLIBUSTA_APP_ADMIN_PASSWORD"
    if [ ! -z "$FLIBUSTA_APP_ADMIN_PASSWORD_FILE" ] && [ -e "$FLIBUSTA_APP_ADMIN_PASSWORD_FILE" ]; then
        ADMIN_PASSWORD=`cat "$FLIBUSTA_APP_ADMIN_PASSWORD_FILE"`
        echo Admin password read from file $FLIBUSTA_APP_ADMIN_PASSWORD_FILE
    fi
    if [ ! -z "$ADMIN_PASSWORD" ]; then
        echo "Ensuring admin user $FLIBUSTA_APP_ADMIN exists"
        export ADMIN_PASSWORD
        ADMIN_PASSWORD_HASH=`php -r 'echo password_hash(getenv("ADMIN_PASSWORD"), PASSWORD_DEFAULT);'`
        unset ADMIN_PASSWORD
        ADMIN_USER_ESCAPED=`printf '%s' "$FLIBUSTA_APP_ADMIN" | sed "s/'/''/g"`
        ADMIN_PASSWORD_HASH_ESCAPED=`printf '%s' "$ADMIN_PASSWORD_HASH" | sed "s/'/''/g"`
        $SQL_CMD -c "INSERT INTO users (username, password_hash, is_admin) VALUES ('$ADMIN_USER_ESCAPED', '$ADMIN_PASSWORD_HASH_ESCAPED', true) ON CONFLICT (username) DO NOTHING;"
    fi
fi



chown -R www-data:www-data /sql/*
chown -R www-data:www-data /cache/*


if [ ! -d /flibusta ]; then
echo FATAL: directory /flibusta with books is not found, exiting
exit 1
fi

exec php-fpm