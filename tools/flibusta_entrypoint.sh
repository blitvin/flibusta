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

    if [ `$SQL_CMD -c "select 1 from pg_roles where rolname='flibusta'"  | wc -l` -eq 0 ]; then
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
mkdir -p /cache/timestamps
mkdir -p /cache/clearlists

touch /cache/dbupdate.lock
touch /cache/adminop.lock
touch /cache/timestamps/getcovers
touch /cache/timestamps/getsql
touch /cache/timestamps/app_reindex
touch /cache/timestamps/update_daily

chown -R www-data:www-data /sql/*
chown -R www-data:www-data /cache/*


if [ ! -d /flibusta ]; then
echo FATAL: directory /flibusta with books is not found, exiting
exit 1
fi

exec php-fpm