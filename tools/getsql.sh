#!/bin/sh

ADMINOPLOCKFILE=/cache/locks/adminop.lock
FLIBUSTA_URL="${FLIBUSTA_URL:-https://flibusta.is}"
SQL_BASE="${FLIBUSTA_URL}/sql"

echo "getsql.sh : start running" >&2

exec 199> "$ADMINOPLOCKFILE"
if ! flock -n 199; then
  echo "getsql.sh : failed to obtain admin op lock" >&2
  exit 1;
fi


echo Updating lib.libavtor.sql.gz
/tools/refresh_file.sh  lib.libavtor.sql.gz "${SQL_BASE}/lib.libavtor.sql.gz" /sql/
echo Updating lib.libtranslator.sql.gz
/tools/refresh_file.sh  lib.libtranslator.sql.gz "${SQL_BASE}/lib.libtranslator.sql.gz" /sql/
echo Updating lib.libavtorname.sql.gz
/tools/refresh_file.sh  lib.libavtorname.sql.gz "${SQL_BASE}/lib.libavtorname.sql.gz" /sql/
echo Updating lib.libbook.sql.gz
/tools/refresh_file.sh  lib.libbook.sql.gz "${SQL_BASE}/lib.libbook.sql.gz" /sql/
echo Updating lib.libfilename.sql.gz
/tools/refresh_file.sh  lib.libfilename.sql.gz "${SQL_BASE}/lib.libfilename.sql.gz" /sql/
echo Updating lib.libgenre.sql.gz
/tools/refresh_file.sh  lib.libgenre.sql.gz "${SQL_BASE}/lib.libgenre.sql.gz" /sql/
echo Updating lib.libgenrelist.sql.gz
/tools/refresh_file.sh  lib.libgenrelist.sql.gz "${SQL_BASE}/lib.libgenrelist.sql.gz" /sql/
echo Updating lib.libjoinedbooks.sql.gz
/tools/refresh_file.sh  lib.libjoinedbooks.sql.gz "${SQL_BASE}/lib.libjoinedbooks.sql.gz" /sql/
echo Updating lib.librate.sql.gz
/tools/refresh_file.sh  lib.librate.sql.gz "${SQL_BASE}/lib.librate.sql.gz" /sql/
echo Updating lib.librecs.sql.gz
/tools/refresh_file.sh  lib.librecs.sql.gz "${SQL_BASE}/lib.librecs.sql.gz" /sql/
echo Updating lib.libseqname.sql.gz
/tools/refresh_file.sh  lib.libseqname.sql.gz "${SQL_BASE}/lib.libseqname.sql.gz" /sql/
echo Updating lib.libseq.sql.gz
/tools/refresh_file.sh  lib.libseq.sql.gz "${SQL_BASE}/lib.libseq.sql.gz" /sql/
echo Updating lib.reviews.sql.gz
/tools/refresh_file.sh  lib.reviews.sql.gz "${SQL_BASE}/lib.reviews.sql.gz" /sql/
echo Updating lib.b.annotations.sql.gz
/tools/refresh_file.sh  lib.b.annotations.sql.gz "${SQL_BASE}/lib.b.annotations.sql.gz" /sql/
echo Updating lib.a.annotations.sql.gz
/tools/refresh_file.sh  lib.a.annotations.sql.gz "${SQL_BASE}/lib.a.annotations.sql.gz" /sql/
echo Updating lib.b.annotations_pics.sql.gz
/tools/refresh_file.sh  lib.b.annotations_pics.sql.gz "${SQL_BASE}/lib.b.annotations_pics.sql.gz" /sql/
echo Updating lib.a.annotations_pics.sql.gz
/tools/refresh_file.sh  lib.a.annotations_pics.sql.gz "${SQL_BASE}/lib.a.annotations_pics.sql.gz" /sql/
echo Done
date > /cache/timestamps/getsql
echo "getsql.sh : finished" >&2
exec 199>&-
