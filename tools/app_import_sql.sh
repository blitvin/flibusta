#!/bin/sh

ADMINOPLOCKFILE=/cache/adminop.lock
DBLOCKFILE=/cache/dbupdate.lock

echo "app_import_sql.sh : start running" >&2

exec 199> "$ADMINOPLOCKFILE"
if ! flock -n 199; then
  echo "app_import_sql.sh : failed to obtain admin op lock" >&2
  exit 1;
fi

exec 200> "$DBLOCKFILE"
if ! flock -w 30 200; then
  echo "app_import_sql.sh : failed to obtain db op lock" >&2
  exit 1
fi

source /tools/dbinit.sh

mkdir -p /sql/psql
mkdir -p /cache/authors
mkdir -p /cache/covers
mkdir -p /cache/tmp

echo "Распаковка sql.gz"
gzip -f -d /sql/*.gz

echo "Starting DB import" > /cache/log/dbupdate.log
/tools/app_topg lib.a.annotations_pics.sql  /cache/log/dbupdate.log
/tools/app_topg lib.b.annotations_pics.sql  /cache/log/dbupdate.log
/tools/app_topg lib.a.annotations.sql  /cache/log/dbupdate.log
/tools/app_topg lib.b.annotations.sql  /cache/log/dbupdate.log
/tools/app_topg lib.libavtorname.sql /cache/log/dbupdate.log
/tools/app_topg lib.libavtor.sql  /cache/log/dbupdate.log
/tools/app_topg lib.libbook.sql  /cache/log/dbupdate.log
/tools/app_topg lib.libfilename.sql  /cache/log/dbupdate.log
/tools/app_topg lib.libgenrelist.sql  /cache/log/dbupdate.log
/tools/app_topg lib.libgenre.sql  /cache/log/dbupdate.log
/tools/app_topg lib.libjoinedbooks.sql  /cache/log/dbupdate.log
/tools/app_topg lib.librate.sql  /cache/log/dbupdate.log
/tools/app_topg lib.librecs.sql  /cache/log/dbupdate.log
/tools/app_topg lib.libseqname.sql  /cache/log/dbupdate.log
/tools/app_topg lib.libseq.sql  /cache/log/dbupdate.log
/tools/app_topg lib.libtranslator.sql  /cache/log/dbupdate.log
/tools/app_topg lib.reviews.sql  /cache/log/dbupdate.log

#echo "Подчистка БД. Стираем авторов, серии и жанры у которых нет ни одной книги"
#$SQL_CMD -f /tools/cleanup_db.sql

echo "Обновление полнотекстовых индексов"
$SQL_CMD -f /tools/update_vectors.sql >> /cache/log/dbupdate.log

echo "Создание индекса zip-файлов"
php /tools/update_zip_list.php  >> /cache/log/dbupdate.log
echo "Процесс обновления БД завершен"
echo "app_import_sql.sh : finished" >&2 
exec 200>&-
exec 199>&~
