#!/bin/sh
ADMINOPLOCKFILE=/cache/adminop.lock
DBLOCKFILE=/cache/dbupdate.lock

echo "app_reindex_sql.sh : start running" >&2

exec 199> "$ADMINOPLOCKFILE"
if ! flock -n 199; then
  echo "app_reindex_sql.sh : failed to obtain admin op lock" >&2
  exit 1;
fi

exec 200> "$DBLOCKFILE"
if ! flock -w 30 200; then
  echo "app_reindex_sql.sh : failed to obtain db op lock" >&2
  exit 1
fi




echo "Создание индекса zip-файлов"
php /tools/app_update_zip_list.php  > /cache/log/update_zip_list.log


echo "app_reindex_sql.sh : finished" >&2
exec 200>&-

exec 199>&-
