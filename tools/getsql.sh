#!/bin/sh

ADMINOPLOCKFILE=/cache/adminop.lock

echo "getsql.sh : start running" >&2

exec 199> "$ADMINOPLOCKFILE"
if ! flock -n 199; then
  echo "getsql.sh : failed to obtain admin op lock" >&2
  exit 1;
fi


echo Updating lib.libavtor.sql.gz
/tools/refresh_file.sh  lib.libavtor.sql.gz https://flibusta.is/sql/ /sql/
echo Updating lib.libtranslator.sql.gz
/tools/refresh_file.sh  lib.libtranslator.sql.gz https://flibusta.is/sql/ /sql/
echo Updating lib.libavtorname.sql.gz
/tools/refresh_file.sh  lib.libavtorname.sql.gz https://flibusta.is/sql/ /sql/
echo Updating lib.libbook.sql.gz
/tools/refresh_file.sh  lib.libbook.sql.gz https://flibusta.is/sql/ /sql/
echo Updating lib.libfilename.sql.gz
/tools/refresh_file.sh  lib.libfilename.sql.gz https://flibusta.is/sql/ /sql/
echo Updating lib.libgenre.sql.gz
/tools/refresh_file.sh  lib.libgenre.sql.gz https://flibusta.is/sql/ /sql/
echo Updating lib.libgenrelist.sql.gz
/tools/refresh_file.sh  lib.libgenrelist.sql.gz https://flibusta.is/sql/ /sql/
echo Updating lib.libjoinedbooks.sql.gz
/tools/refresh_file.sh  lib.libjoinedbooks.sql.gz https://flibusta.is/sql/ /sql/
echo Updating lib.librate.sql.gz
/tools/refresh_file.sh  lib.librate.sql.gz https://flibusta.is/sql/ /sql/
echo Updating lib.librecs.sql.gz
/tools/refresh_file.sh  lib.librecs.sql.gz https://flibusta.is/sql/ /sql/
echo Updating lib.libseqname.sql.gz
/tools/refresh_file.sh  lib.libseqname.sql.gz https://flibusta.is/sql/ /sql/
echo Updating lib.libseq.sql.gz
/tools/refresh_file.sh  lib.libseq.sql.gz https://flibusta.is/sql/ /sql/
echo Updating lib.reviews.sql.gz
/tools/refresh_file.sh  lib.reviews.sql.gz https://flibusta.is/sql/ /sql/
echo Updating lib.b.annotations.sql.gz
/tools/refresh_file.sh  lib.b.annotations.sql.gz https://flibusta.is/sql/ /sql/
echo Updating lib.a.annotations.sql.gz
/tools/refresh_file.sh  lib.a.annotations.sql.gz https://flibusta.is/sql/ /sql/
echo Updating lib.b.annotations_pics.sql.gz
/tools/refresh_file.sh  lib.b.annotations_pics.sql.gz https://flibusta.is/sql/ /sql/
echo Updating lib.a.annotations_pics.sql.gz
/tools/refresh_file.sh  lib.a.annotations_pics.sql.gz https://flibusta.is/sql/ /sql/
echo Done
date > /cache/timestamps/getsql
echo "getsql.sh : finished" >&2
exec 199>&-

